"""Trava da autonomia: confere um plano de mudanças antes de gravar no PriceLabs.

Uso:
  python3 validar_plano.py plano.json [--precos SAIDA_WORKFLOW.json] [--payload]

Formato do plano (JSON):
  {"hoje": "AAAA-MM-DD",
   "itens": [
     {"tipo": "data", "quarto": "Q7", "data": "2026-10-06", "preco": -35, "price_type": "percent",
      "min_price": 640, "max_price": null, "min_stay": 1, "preco_antes": 800, "motivo": "Regra I",
      "excecao": null, "ocupacao_sabado": null},
     {"tipo": "apagar", "quarto": "Q7", "data": "2026-10-13", "motivo": "Regra F: 4 de 7 vendidas"},
     {"tipo": "anuncio", "quarto": "Balcony", "campo": "min", "valor": 850, "antes": 900, "motivo": "..."},
     {"tipo": "personalizacao", "quarto": "Balcony", "descricao": "...", "motivo": "...",
      "como_desfazer": "..."}
   ]}

Campos de "data": preco/price_type são opcionais (pode ser só min_stay). Decisão do dono (07/10):
preço por data SEMPRE em "percent" (percentual sobre o recomendado, ex.: -20), para o PriceLabs
continuar flutuando. O piso da data vai em "min_price" e, se preciso, o teto em "max_price".
Com percentual, o preço mais baixo possível é o min_price (ou o mínimo do anúncio): é ele que passa
pelas travas (limite de segurança, Regra I, piso de feriado, Regra G). "fixed" só com ordem
expressa do dono (excecao "dono"). "excecao" aceita:
  "regra_h"      sábado sozinho liberado (exige ocupacao_sabado < 50, quarta a sexta antes, fora de feriado)
  "piso_feriado" corte na Queen (2) só para acompanhar o piso de feriado
  "dono"         ordem explícita do dono nesta conversa; cite no motivo. Nunca passa por cima
                 dos limites de segurança. É a única forma de gravar preço fixo.

Padrão de qualidade (dono, 08/10): sexta, sábado e noites de feriado das suítes com banheira (todas menos
a Balcony) ficam a partir de R$ 1.000 (limites_seguranca.padrao_qualidade_diaria), no preço ou no piso da data. Para
ir abaixo, o item leva o campo "mercado" com o dado que justifica (mediana da Booking, ocupação do mercado,
ritmo de vendas; texto com número, mínimo de 20 caracteres). Sem ele, ERRO. Com ele, sai um aviso para o
relatório. Vale também para o teto (max_price) do item. O valor fica em limites_seguranca (só o dono muda).

Sai com código 0 se tudo passou e 1 se houve ERRO. Com --payload, imprime os pedidos prontos
para update_listing_date_overrides, delete_listing_date_overrides e update_listing_data.
"""

from __future__ import annotations

import argparse
import math
import json
import re
import sys
from collections import defaultdict
from datetime import date, datetime, timezone

from comum import carregar_quartos, data, hoje_brt, ler_instante, ler_json, periodo_feriado, reais, resultado_workflow
from ordens import carregar_ordens, congelada, ordem_valida

TIPOS = {"data", "apagar", "anuncio", "personalizacao"}
PALAVRAS_PROIBIDAS = ("ota", "oferta", "genius", "promo", "deal", "visibility")


def estadia_exigida(cfg: dict, d: date, fer: dict | None) -> int | None:
    """Estadia mínima que a data exige: 2 em sábado; em feriado, a do período em quartos.json, senão a do
    calendário de sazonalidade, senão 2. None quando a data não exige nada."""
    if fer:
        if fer.get("estadia_minima"):
            return int(fer["estadia_minima"])
        try:
            from sazonalidade import carregar, periodo
            p = periodo(carregar(), d)
            if p and p.get("estadia_minima"):
                return int(p["estadia_minima"])
        except Exception:
            pass
        return 2
    if d.weekday() == 5:
        return 2
    return None


class Validador:
    def __init__(self, cfg: dict, hoje: date, precos: dict | None = None, horas_max: float = 6, aceitar_velho: bool = False,
                 ordens: dict | None = None):
        self.cfg, self.hoje = cfg, hoje
        self.precos = precos or {}          # (curto, "AAAA-MM-DD") -> {"preco", "livres", "vendidas", "total"}
        self.meta_precos = self.precos.pop("_meta", {}) if isinstance(self.precos, dict) else {}
        self.horas_max, self.aceitar_velho = horas_max, aceitar_velho
        self.tetos: dict = {}               # (curto, data) -> max_price
        self.erros: list[str] = []
        self.avisos: list[str] = []
        lim = cfg["limites_seguranca"]
        self.fracao = lim["fracao_min_referencia"]
        self.var_semanal = lim["variacao_semanal_base_min"]
        self.piso_seg = lim["piso_feriado_real_seguranca"]
        self.padrao = lim.get("padrao_qualidade_diaria")  # só o dono muda; sem ele, nada passa
        self.par = cfg["parametros"]
        self.pisos: set = set()             # (curto, data) cujo valor em "finais" é piso de percentual
        # 30/10: excecao "dono" só com ordem_id de dados/ordens_dono.json vigente para o quarto e a noite
        self.ordens = ordens if isinstance(ordens, dict) else carregar_ordens(ordens)

    # ---------- utilidades
    def erro(self, i, item, msg):
        self.erros.append(f"ERRO item {i} ({item.get('quarto')} {item.get('data', item.get('campo', ''))}): {msg}")

    def aviso(self, i, item, msg):
        self.avisos.append(f"aviso item {i} ({item.get('quarto')} {item.get('data', '')}): {msg}")

    def piso_absoluto(self, q, d: date | None = None) -> float:
        # Só o dono abre exceção ao limite, por quarto e período, em limites_seguranca.excecoes_dono (quartos.json).
        if d is not None:
            for ex in self.cfg["limites_seguranca"].get("excecoes_dono", []) or []:
                if ex.get("quarto") == q["curto"] and data(ex["de"]) <= d <= data(ex["ate"]):
                    return float(ex["limite"])
        return round(self.fracao * q["min_referencia"], 2)

    def fator_piso(self, q) -> float:
        return q["fator_vitrine"] if q.get("piso_feriado_base") == "vitrine" else q["fator_real"]

    # ---------- validações
    def validar(self, itens: list[dict]) -> None:
        finais = {}  # (curto, data) -> preço fixo planejado ou piso (min_price) do percentual
        if not self.padrao or float(self.padrao) <= 0:
            self.erros.append("ERRO padrão de qualidade ausente ou zerado em dados/quartos.json "
                              "(limites_seguranca.padrao_qualidade_diaria): só o dono muda; não grave")
        vistos: dict = {}
        for i, it in enumerate(itens, 1):
            tipo = it.get("tipo")
            texto = json.dumps(it, ensure_ascii=False).lower()
            if tipo not in TIPOS:
                self.erro(i, it, f"tipo desconhecido '{tipo}'")
                continue
            if tipo in ("data", "apagar") and it.get("quarto") and it.get("data"):
                chave = (it["quarto"], str(it["data"])[:10])
                if chave in vistos:
                    self.erro(i, it, f"item duplicado para {chave[0]} {chave[1]} (já há item {vistos[chave]}): "
                                     "a API mescla campos e o último sobrescreve o primeiro sem registro")
                vistos[chave] = i
            if any(p in str(it.get("campo", "")).lower() for p in PALAVRAS_PROIBIDAS) or \
               (tipo == "personalizacao" and any(p in texto for p in PALAVRAS_PROIBIDAS)):
                self.erro(i, it, "mexe em desconto de OTA, oferta da Booking ou Genius: proibido")
                continue
            q = self.cfg["por_curto"].get(it.get("quarto"))
            if not q:
                self.erro(i, it, "quarto desconhecido")
                continue
            if not it.get("motivo"):
                self.erro(i, it, "falta o motivo (vai para o registro)")
            if tipo == "data":
                self.validar_data(i, it, q, finais)
            elif tipo == "apagar":
                self.validar_apagar(i, it, q)
            elif tipo == "anuncio":
                self.validar_anuncio(i, it, q)
            elif tipo == "personalizacao" and not it.get("como_desfazer"):
                self.erro(i, it, "personalização sem 'como_desfazer'")
        self.validar_balcony(finais)
        self.validar_excecoes()

    def validar_apagar(self, i, it, q):
        try:
            d = data(it["data"])
        except (KeyError, ValueError):
            self.erro(i, it, "data inválida")
            return
        if d < self.hoje:
            self.erro(i, it, "data no passado")
        fer = periodo_feriado(self.cfg, d)
        if d.weekday() == 5 or fer:
            exig = estadia_exigida(self.cfg, d, fer)
            self.aviso(i, it, f"apagar a substituição de {'feriado' if fer else 'sábado'} remove a estadia mínima "
                              f"({exig} noites); regrave min_stay {exig} no mesmo plano")
        if fer:
            self.aviso(i, it, f"apagar em feriado ({fer['nome']}) remove também o piso de proteção; só com piso regravado")

    def validar_data(self, i, it, q, finais):
        try:
            d = data(it["data"])
        except (KeyError, ValueError):
            self.erro(i, it, "data inválida")
            return
        if d < self.hoje:
            self.erro(i, it, "data no passado")
            return
        excecao = it.get("excecao")
        if excecao == "dono":
            # Dono (07/10, 14/10) + auditoria de 30/10: a exceção do dono precisa apontar a ordem literal dele, vigente
            # para o quarto e a noite; sem isso, os scripts não podem carimbar "dono" sozinhos.
            if not ordem_valida(self.ordens, it.get("ordem_id"), q["curto"], d):
                self.erro(i, it, f"excecao 'dono' sem ordem_id vigente para {q['curto']} em {d} (dados/ordens_dono.json; "
                                 f"ordem_id={it.get('ordem_id')!r}): registre a ordem do dono com validade ou tire a exceção")
                excecao = None
        cong = congelada(self.ordens, q["curto"], d)
        if cong and (cong["id"] != it.get("ordem_id")):
            self.erro(i, it, f"noite com mudança manual do dono pela tela ({cong['id']}, até {cong['ate']}): respeite e registre; "
                             "nenhum script mexe nela")
        fer = periodo_feriado(self.cfg, d)
        antecedencia = (d - self.hoje).days
        self.validar_chegada_saida(i, it, q, d, excecao)
        piso_abs = self.piso_absoluto(q, d)
        tipo_preco = it.get("price_type")
        preco = it.get("preco")
        if preco is not None and tipo_preco not in ("fixed", "percent"):
            self.erro(i, it, "price_type precisa ser 'fixed' ou 'percent'")
            return
        if it.get("min_price") is not None and float(it["min_price"]) < piso_abs:
            self.erro(i, it, f"min_price {it['min_price']} abaixo do limite de segurança {piso_abs:.0f} "
                             f"({self.fracao:.0%} do mínimo de referência)")
        antes = it.get("preco_antes")
        if antes is None:
            antes = (self.precos.get((q["curto"], d.isoformat())) or {}).get("preco")

        if tipo_preco == "fixed" and excecao != "dono":
            self.erro(i, it, "preço fixo: o dono decidiu em 07/10 usar sempre percentual, com o piso em "
                             "min_price; fixo só com ordem expressa dele (excecao 'dono')")
        if tipo_preco == "fixed":
            minimo = float(preco)            # preço fixo: é o próprio valor
        elif tipo_preco == "percent" or it.get("min_price") is not None:
            # percentual (ou só piso): o mais baixo possível é o min_price da data, senão o mínimo do anúncio
            minimo = float(it["min_price"]) if it.get("min_price") is not None else float(q["min"])
        else:
            minimo = None
        if it.get("max_price") is not None and minimo is not None and float(it["max_price"]) < minimo:
            self.erro(i, it, f"max_price {it['max_price']} abaixo do piso {minimo:.0f}")
        if it.get("max_price") is not None and q.get("sem_desconto") and excecao not in ("piso_feriado", "dono"):
            # 19/10: um teto abaixo do preço atual também é desconto (Queen 2 em 30/10 passou assim)
            if antes is None:
                self.erro(i, it, f"{q['nome']}: teto sem preco_antes nem --precos (não dá para provar que não é desconto)")
            elif float(it["max_price"]) < float(antes) - 1:
                self.erro(i, it, f"{q['nome']} não recebe desconto (teto {it['max_price']} abaixo do preço atual {antes})")
        piso_vigente = (self.precos.get((q["curto"], d.isoformat())) or {}).get("piso")
        if it.get("max_price") is not None and it.get("min_price") is None and piso_vigente \
           and float(piso_vigente) > float(it["max_price"]) + 1:
            # 19/10: o piso que já vale na data (regra por data ou personalização de fim de semana) vence o teto
            self.erro(i, it, f"teto {it['max_price']} abaixo do piso vigente {float(piso_vigente):.0f}: o PriceLabs fica "
                             "no piso; ponha min_price no item ou tire o teto")
        if tipo_preco != "fixed" and it.get("min_price") is not None:
            # Dono (14/10): o piso vai ao PriceLabs como variação % sobre a base, arredondada para cima. O que
            # vale nas travas é o valor efetivo desse percentual (nunca abaixo do pedido, até 1% da base acima).
            minimo = em_reais(q, piso_pct(q, float(it["min_price"])))

        if tipo_preco == "percent":
            pct = float(preco)
            if pct < 0:
                if q.get("sem_desconto") and excecao not in ("piso_feriado", "dono"):
                    self.erro(i, it, f"{q['nome']} não recebe desconto percentual")
                if it.get("min_price") is None:
                    self.erro(i, it, "desconto percentual sem min_price (piso da data)")
                if pct < -60:
                    self.erro(i, it, f"desconto de {pct}% passa do razoável; segure o preço com o min_price")
            self.aviso(i, it, f"percentual {pct:+g}%: o valor final só aparece no recálculo "
                              f"(piso {minimo:.0f})")

        if minimo is not None:
            finais[(q["curto"], d.isoformat())] = minimo
            if tipo_preco != "fixed":
                self.pisos.add((q["curto"], d.isoformat()))
            if minimo < piso_abs:
                self.erro(i, it, f"preço/piso {minimo:.0f} abaixo do limite de segurança {piso_abs:.0f}")
            ocup = self.precos.get((q["curto"], d.isoformat())) or {}
            depende_ocupacao = minimo < q["min"] or re.search(r"\+10|4c|flutua|regra g|saída|saida|esgot", str(it.get("motivo", "")).lower())
            if depende_ocupacao:
                self.calendario_fresco(i, it, q)
            if minimo < q["min"] and excecao != "dono":
                limite_i = q["min"] * (1 - self.par["regra_i_desconto_max"])
                if fer:
                    self.erro(i, it, f"abaixo do mínimo ({q['min']}) em feriado ({fer['nome']})")
                elif not q.get("regra_i"):
                    self.erro(i, it, f"abaixo do mínimo ({q['min']}) e o quarto não entra na Regra I")
                elif antecedencia > self.par["regra_i_dias"]:
                    self.erro(i, it, f"abaixo do mínimo a {antecedencia} dias; a Regra I só vale até "
                                     f"{self.par['regra_i_dias']} dias")
                elif minimo < limite_i:
                    self.erro(i, it, f"preço/piso {minimo:.0f} abaixo do piso da Regra I ({limite_i:.0f})")
                elif tipo_preco == "fixed" and (it.get("min_price") is None or float(it["min_price"]) > minimo):
                    self.erro(i, it, "abaixo do mínimo do anúncio sem min_price igual ao preço: "
                                     "o PriceLabs trava no mínimo")
                elif ocup.get("total") is None:
                    self.erro(i, it, "Regra I exige --precos com o calendário mesclado (unidades livres e vendidas da noite)")
                else:
                    livres, vend, tot = ocup["livres"], ocup["vendidas"], ocup["total"]
                    frac = vend / tot if tot else 0
                    if livres <= 0:
                        self.erro(i, it, "Regra I em noite esgotada: não há unidade livre para cortar")
                    elif frac > 0.70 and antecedencia >= 1:
                        self.erro(i, it, f"Regra I não começa em data com mais de 70% vendida ({vend}/{tot}); "
                                         "fica no mínimo (regras.md, Regra I)")
                    elif frac > 0.70:
                        self.aviso(i, it, f"Regra I em D0 com {vend}/{tot} vendidas: corte já aplicado segue só hoje")
                    if q.get("demanda") == "alta" and livres > 1:
                        self.erro(i, it, f"{q['nome']} em demanda alta: Regra I só para a última unidade "
                                         f"({livres} livres)")
            if fer:
                valor = minimo * self.fator_piso(q)
                base = "vitrine" if q.get("piso_feriado_base") == "vitrine" else "real"
                if valor < self.piso_seg:
                    self.erro(i, it, f"feriado: valor {base} estimado {valor:.0f} abaixo do limite de "
                                     f"segurança {self.piso_seg}")
                piso = (fer.get("piso_real_por_quarto") or {}).get(q["curto"], fer.get("piso_real"))
                if piso and valor < piso - 1 and excecao != "dono":
                    self.erro(i, it, f"feriado: valor {base} estimado {valor:.0f} abaixo do piso do "
                                     f"período ({piso}); para mudar o piso, atualize dados/quartos.json")
            if q.get("sem_desconto") and antes is None and excecao not in ("piso_feriado", "dono"):
                self.erro(i, it, f"{q['nome']}: informe preco_antes ou rode com --precos (sem o preço atual não dá "
                                 "para provar que não é desconto)")
            if q.get("sem_desconto") and antes and minimo < float(antes) - 1 and \
               excecao not in ("piso_feriado", "dono"):
                self.erro(i, it, f"{q['nome']} não recebe desconto (de {antes} para {minimo:.0f})")
            if antes and not fer and excecao != "dono" and minimo > float(antes) * 1.15 + 1 \
               and "prote" not in str(it.get("motivo", "")).lower():
                self.aviso(i, it, f"piso {minimo:.0f} é mais de 15% acima do preço atual ({float(antes):.0f}): "
                                  "a flutuação dá um degrau por dia (regras.md 4c)")
            if it.get("max_price") is not None:
                self.tetos[(q["curto"], d.isoformat())] = self.teto_efetivo(q, it)
            self.padrao_qualidade(i, it, q, d, fer, minimo, excecao)
        elif it.get("max_price") is not None:
            # item só com teto: o teto é o preço mais alto possível, então também precisa respeitar os pisos
            teto = self.teto_efetivo(q, it)
            if teto < piso_abs:
                self.erro(i, it, f"max_price {teto:.0f} abaixo do limite de segurança {piso_abs:.0f}")
            self.tetos[(q["curto"], d.isoformat())] = teto
            self.padrao_qualidade(i, it, q, d, fer, teto, excecao)

        ms = it.get("min_stay")
        exig = estadia_exigida(self.cfg, d, fer)
        if ms is None and exig and minimo is not None:
            self.aviso(i, it, f"{'feriado' if fer else 'sábado'} sem min_stay no item: confira a estadia ({exig} noites) depois de gravar")
        if ms is not None:
            ms = int(ms)
            if fer and exig and ms < exig and excecao != "dono":
                self.erro(i, it, f"feriado ({fer['nome']}) exige estadia mínima {exig}; item com {ms}")
            if d.weekday() == 5 and ms < 2:
                # A política de não vender sábado sozinho é do dono: ordem expressa dele (excecao "dono") também libera.
                ok = (excecao == "dono" and not fer) or (
                    excecao == "regra_h" and not fer and it.get("ocupacao_sabado") is not None
                    and float(it["ocupacao_sabado"]) < 50 and self.hoje.weekday() in (2, 3, 4)
                    and antecedencia <= 3)
                if not ok:
                    self.erro(i, it, "sábado com 1 noite: a Regra H só libera na quarta a sexta antes, "
                                     "fora de feriado e com o sábado abaixo de 50% (excecao 'regra_h' "
                                     "e ocupacao_sabado)")
            if ms < 1 or ms > 7:
                self.erro(i, it, f"estadia mínima {ms} fora do razoável")

    def validar_chegada_saida(self, i, it, q, d, excecao):
        """Chegada e saída por data (dono, 21/10): strings de 7 caracteres 0/1, de segunda a domingo. Fechar a noite só
        vale no sábado, espelhando o bloqueio da Regra H que o dono usa no Beds24 (parametros.regra_h_restricao);
        fora disso só com excecao "dono"."""
        if it.get("check_in") is None and it.get("check_out") is None:
            return
        regra = ((self.cfg.get("parametros") or {}).get("regra_h_restricao") or {}).get(q["curto"])
        for campo in ("check_in", "check_out"):
            s = str(it.get(campo) or "1111111")
            if len(s) != 7 or set(s) - {"0", "1"}:
                self.erro(i, it, f"{campo} '{s}' precisa ter 7 caracteres 0/1 (segunda a domingo)")
                return
            if s == "0000000":
                self.erro(i, it, f"{campo} com todos os dias fechados: o PriceLabs recusa")
                return
            if s[d.weekday()] == "0" and excecao != "dono":
                if d.weekday() != 5:
                    self.erro(i, it, f"{campo} fecha uma noite que não é sábado: só o dono decide (excecao 'dono')")
                elif campo == "check_out" and regra != "chegada_saida":
                    self.erro(i, it, f"{q['nome']}: o Beds24 do dono fecha só a chegada no sábado; saída fechada só com "
                                     "excecao 'dono'")
                elif not regra:
                    self.erro(i, it, f"{q['nome']} sem parametros.regra_h_restricao: restrição de sábado não configurada")

    def calendario_fresco(self, i, it, q):
        """Decisão que depende de ocupação precisa de calendário recalculado (aprendizado de 13/10)."""
        idades = (self.meta_precos or {}).get("idade_h") or {}
        h = idades.get(q["curto"])
        if h is None or h <= self.horas_max:
            return
        msg = (f"calendário de {q['curto']} com {h:.0f} h (limite {self.horas_max:g} h): recalcule e use o arquivo "
               "mesclado antes de decidir por ocupação")
        if self.aceitar_velho:
            self.aviso(i, it, msg + " (aceito sem saldo de recálculo)")
        else:
            self.erro(i, it, msg)

    def validar_excecoes(self):
        for ex in self.cfg.get("excecoes_mercado", []) or []:
            rev = ex.get("revisar_em")
            if rev and data(rev) < self.hoje:
                self.avisos.append(f"aviso exceção de mercado vencida ({ex.get('quarto')} {ex.get('datas')}, revisar_em {rev}): "
                                   "revalide com o dado do dia ou apague")

    def padrao_qualidade(self, i, it, q, d, fer, minimo, excecao):
        """Padrão de qualidade do dono (08/10): sexta, sábado e noites de feriado das suítes com banheira
        a partir de limites_seguranca.padrao_qualidade_diaria (preço enviado). Para ir abaixo, o item
        precisa do campo 'mercado' com o dado (texto com número). A exceção do dono também libera, com aviso."""
        if q.get("banheira") and d.weekday() not in (4, 5) and not fer:
            self.piso_vitrine_semana(i, it, q, minimo, excecao)
        if q.get("banheira") and d.weekday() in (4, 5) and not fer:
            self.piso_vitrine_semana(i, it, q, minimo, excecao, fds=True)
        padrao = float(self.padrao or 0)
        if padrao <= 0 or not q.get("banheira") or not (d.weekday() in (4, 5) or fer):
            return
        if minimo >= padrao - 1:
            return
        if excecao == "dono":
            self.aviso(i, it, f"abaixo do padrão de qualidade ({reais(padrao)}) por ordem do dono")
            return
        just = it.get("mercado")
        if isinstance(just, str) and len(just.strip()) >= 20 and re.search(r"\d", just):
            self.aviso(i, it, f"abaixo do padrão de qualidade ({reais(padrao)}) por exceção de mercado: "
                              f"{just.strip()}. Registre em excecoes_mercado (quartos.json)")
        else:
            self.erro(i, it, f"padrão de qualidade do dono (08/10): sexta, sábado e feriado das suítes com "
                             f"banheira ficam a partir de {reais(padrao)} (preço/piso/teto {reais(minimo)}). "
                             f"Ponha min_price >= {padrao:.0f} ou, para ir abaixo, cite o dado de mercado com "
                             f"número (mediana da Booking, ocupação do mercado, ritmo de vendas) no campo 'mercado'")

    def piso_semana_enviado(self, q: dict, fds: bool = False) -> float | None:
        """Piso enviado das suítes com banheira (dono, 14/10): domingo a quinta R$ 500 e sexta e sábado R$ 850 no
        preço final (vitrine da Booking) ÷ fator de vitrine da pousada."""
        alvo = (self.cfg.get("limites_seguranca") or {}).get("piso_vitrine_fds_suites" if fds else "piso_vitrine_semana_suites")
        fat = ((self.cfg.get("parametros") or {}).get("fator_vitrine_fds" if fds else "fator_vitrine_semana") or {}).get(q.get("pousada"))
        if not alvo or not fat:
            return None
        return float(alvo) / float(fat)

    def piso_vitrine_semana(self, i, it, q, minimo, excecao, fds: bool = False):
        """Dono (14/10): domingo a quinta, fora de feriado, preço final nas plataformas a partir de R$ 500 nas suítes
        com banheira (inclusive na Regra I). Sem exceção de mercado; só a ordem do dono libera."""
        req = self.piso_semana_enviado(q, fds)
        nome, valor = ("fim de semana", "R$ 850") if fds else ("dia de semana", "R$ 500")
        if req is None:
            self.erro(i, it, f"piso de vitrine de {nome} sem configuração (limites_seguranca/parametros)")
            return
        if minimo >= req - 1:
            return
        if excecao == "dono":
            # revisão de 31/10: a ordem citada precisa liberar 'abaixo_piso' (a ordem do Recanto, por exemplo, não libera)
            ordem = (self.ordens or {}).get(it.get("ordem_id") or "") or {}
            if "abaixo_piso" not in (ordem.get("libera") or []):
                self.erro(i, it, f"abaixo do piso de vitrine de {nome} ({reais(req)} enviados) e a ordem citada "
                                 f"({it.get('ordem_id')}) não libera 'abaixo_piso'")
                return
            self.aviso(i, it, f"abaixo do piso de vitrine de {nome} por ordem do dono")
            return
        self.erro(i, it, f"piso de {nome} do dono (14/10): suíte com banheira a partir de {valor} no preço final "
                         f"= {reais(req)} enviados ({q.get('pousada')}); preço/piso/teto {reais(minimo)}")

    def validar_anuncio(self, i, it, q):
        campo, valor = it.get("campo"), it.get("valor")
        if campo not in ("base", "min", "max"):
            self.erro(i, it, f"campo de anúncio '{campo}' não permitido")
            return
        if it.get("excecao") != "dono":
            self.erro(i, it, "dono (14/10): \"Lembre-se tem ser correções percentuais\": base, mínimo e máximo do anúncio "
                             "não se usam para corrigir preço; use piso/teto/preço em percentual por data "
                             "(mudar o anúncio só com excecao 'dono' e a ordem citada no motivo)")
        valor = float(valor)
        ref = self.cfg["referencia_semanal"]["valores"].get(q["curto"], {})
        if campo in ("base", "min") and ref.get(campo):
            var = abs(valor - ref[campo]) / ref[campo]
            if var > self.var_semanal + 1e-9:
                self.erro(i, it, f"{campo} {valor:.0f} muda {var:.0%} contra a referência da semana "
                                 f"({ref[campo]}); limite {self.var_semanal:.0%}")
        if campo == "min" and valor < self.piso_absoluto(q):
            self.erro(i, it, f"mínimo {valor:.0f} abaixo do limite de segurança {self.piso_absoluto(q):.0f}")
        if campo == "base" and valor < q["min"]:
            self.erro(i, it, "base abaixo do mínimo")

    @staticmethod
    def teto_efetivo(q: dict, it: dict) -> float:
        """Teto como o PriceLabs vai calcular (dono 14/10): variação % sobre a base, arredondada para baixo e
        nunca abaixo do piso em percentual (montar_payload faz o mesmo)."""
        p = teto_pct(q, float(it["max_price"]))
        if it.get("min_price") is not None and it.get("price_type") != "fixed":
            p = max(p, piso_pct(q, float(it["min_price"])))
        return em_reais(q, p)

    def validar_balcony(self, finais):
        """Regra G (dono, 07/10): a Balcony (sem banheira) fica cerca de 10% (`regra_g_desconto`) abaixo da
        suíte com banheira mais barata e livre da Villa. ERRO se ficar acima dessa suíte ou mais de 5 pontos
        abaixo do alvo (fora do feriado); aviso acima dos 90% (também no feriado, revisão de 31/10). Com percentual,
        compara o piso; contra suíte fora do plano, vira aviso."""
        desc = self.par.get("regra_g_desconto", 0.10)
        datas = {d for (k, d) in finais if k in ("Balcony", "VK7", "VK2")}
        for d in sorted(datas):
            def preco(k):
                return finais.get((k, d), (self.precos.get((k, d)) or {}).get("preco"))

            def livre(k):
                return (self.precos.get((k, d)) or {}).get("livres", 1) > 0
            balc = preco("Balcony")
            refs = [(preco(k), (k, d) in finais) for k in ("VK7", "VK2") if preco(k) is not None and livre(k)]
            if balc is None or not refs or not livre("Balcony"):
                continue  # Balcony esgotada: Regra J (topo do mercado), fora da Regra G
            fer = bool(periodo_feriado(self.cfg, data(d)))
            menor, do_plano = min(refs)
            alvo = menor * (1 - desc)
            teto = self.tetos.get(("Balcony", d))
            if teto is not None and teto > menor + 1:
                self.erros.append(f"ERRO Regra G {d}: teto da Balcony {teto:.0f} acima da suíte com banheira mais "
                                  f"barata livre ({menor:.0f})")
            if balc > menor + 1:
                # piso acima da suíte: o preço final só pode ser maior ainda, então é erro sempre
                self.erros.append(f"ERRO Regra G {d}: Balcony {balc:.0f} acima da suíte com banheira mais "
                                  f"barata livre ({menor:.0f})")
                continue
            folga = 0.01 * float(self.cfg["por_curto"]["Balcony"]["base"]) + 1  # percentual inteiro da base
            if balc > alvo + folga:
                # revisão de 31/10: acima dos 90% a Balcony perde para a suíte com banheira (vale também no feriado)
                self.avisos.append(f"aviso Regra G {d}: Balcony {balc:.0f} acima de {1 - desc:.0%} da suíte com banheira "
                                   f"mais barata livre ({menor:.0f}; alvo {alvo:.0f})")
            if fer:
                continue  # no feriado a Regra G só baixa a Balcony: o piso de feriado pode deixá-la abaixo do alvo
            msg = None
            if balc < alvo * 0.95:
                msg = (f"Regra G {d}: Balcony {balc:.0f} mais de 5% abaixo do alvo {alvo:.0f} "
                       f"({desc:.0%} abaixo de {menor:.0f})")
            if msg:
                if ("Balcony", d) in self.pisos and not do_plano:
                    self.avisos.append(f"aviso {msg}; é piso de percentual, confira no recálculo")
                else:
                    self.erros.append(f"ERRO {msg}")


def precos_da_coleta(res: dict, agora: datetime | None = None) -> dict:
    """(curto, data) -> preço, livres, vendidas, total; e _meta com a idade do calendário de cada quarto em horas."""
    out: dict = {}
    agora = agora or datetime.now(timezone.utc)
    idade = {}
    for curto, sala in (res.get("rooms") or {}).items():
        ts = ler_instante(sala.get("last_refreshed_at"))
        idade[curto] = round((agora - ts).total_seconds() / 3600, 1) if ts else None
        for dia in sala.get("days") or []:
            mu = str(dia.get("multi_unit_occupancy") or "")
            if "/" in mu:
                v, t = (int(x) for x in mu.split("/"))
            else:  # unidade única: vendida quando há reserva
                v, t = (1 if str(dia.get("booking_status") or "").strip() else 0), 1
            out[(curto, str(dia["date"])[:10])] = {"preco": dia.get("price"), "livres": t - v, "vendidas": v, "total": t,
                                                   "piso": dia.get("min_price")}
    out["_meta"] = {"idade_h": idade, "hoje": res.get("hoje")}
    return out


# Convenção do PriceLabs, medida em 14/10 (VK2 18/10): "percent_base" é VARIAÇÃO sobre a base do anúncio,
# como o price_type "percent": -39 = base x 0,61 (R$ 775 na VK2); 61 daria base x 1,61 (R$ 2.045). Nunca mandar a fração.
def piso_pct(q: dict, reais: float) -> int:
    """Piso como variação % sobre a base do anúncio, arredondado para cima (o piso efetivo nunca fica abaixo do alvo)."""
    return int(math.ceil(reais / float(q["base"]) * 100 - 100 - 1e-9))


def teto_pct(q: dict, reais: float) -> int:
    """Teto como variação % sobre a base, arredondado para baixo (o teto efetivo nunca passa do alvo)."""
    return int(math.floor(reais / float(q["base"]) * 100 - 100 + 1e-9))


def em_reais(q: dict, pct: float) -> float:
    """Valor em reais que o PriceLabs calcula para uma variação % sobre a base (arredonda ao real)."""
    return round(float(q["base"]) * (1 + float(pct) / 100))


def montar_payload(cfg: dict, itens: list[dict]) -> dict:
    gravar, apagar, anuncio = defaultdict(list), defaultdict(list), defaultdict(dict)
    for it in itens:
        q = cfg["por_curto"][it["quarto"]]
        if it["tipo"] == "data":
            o = {"date": it["data"], "reason": str(it.get("motivo", ""))[:250]}
            if it.get("preco") is not None:
                o["price"] = str(int(round(float(it["preco"])))) if it["price_type"] == "fixed" \
                    else f"{float(it['preco']):g}"
                o["price_type"] = it["price_type"]
                o["currency"] = "BRL"
            # Dono (14/10): nada em valor absoluto por data. Piso e teto vão como variação % sobre a base do anúncio
            # (percent_base, ex.: -31 = base x 0,69): piso arredondado para cima (nunca abaixo do alvo), teto para
            # baixo, nunca abaixo do piso. O plano continua em reais; a conversão é só na hora de montar o payload.
            pmin = pmax = None
            if it.get("min_price") is not None:
                pmin = piso_pct(q, float(it["min_price"]))
                o.update(min_price=str(pmin), min_price_type="percent_base", currency="BRL")
            if it.get("max_price") is not None:
                pmax = teto_pct(q, float(it["max_price"]))
                if pmin is not None and pmax < pmin:
                    pmax = pmin
                o.update(max_price=str(pmax), max_price_type="percent_base", currency="BRL")
            if it.get("min_stay") is not None:
                o["min_stay"] = str(int(it["min_stay"]))
            if it.get("check_in") is not None or it.get("check_out") is not None:
                o.update(check_in_check_out_enabled="1", check_in=str(it.get("check_in") or "1111111"),
                         check_out=str(it.get("check_out") or "1111111"))
            gravar[q["id"]].append(o)
        elif it["tipo"] == "apagar":
            apagar[q["id"]].append({"date": it["data"]})
        elif it["tipo"] == "anuncio":
            anuncio[q["id"]][it["campo"]] = it["valor"]
    return {"update_listing_date_overrides": [{"listing_id": k, "pms": cfg["pms"], "overrides": v}
                                              for k, v in gravar.items()],
            "delete_listing_date_overrides": [{"listing_id": k, "pms": cfg["pms"], "overrides": v}
                                              for k, v in apagar.items()],
            "update_listing_data": [{"listing_id": k, "pms": cfg["pms"], **v} for k, v in anuncio.items()]}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("plano")
    ap.add_argument("--precos", help="saída do workflow coleta-pricelabs (preços e unidades livres atuais)")
    ap.add_argument("--payload", action="store_true")
    ap.add_argument("--horas", type=float, default=6, help="idade máxima do calendário (h) para decisões por ocupação")
    ap.add_argument("--aceitar-velho", action="store_true", help="rebaixa calendário velho de ERRO para aviso")
    ap.add_argument("--hoje", help="força a data de hoje (replay/testes); sem ela, o plano precisa ser de hoje em America/Sao_Paulo")
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    plano = ler_json(a.plano)
    precos = precos_da_coleta(resultado_workflow(ler_json(a.precos))) if a.precos else {}
    hoje = data(a.hoje) if a.hoje else hoje_brt(cfg)
    if data(plano["hoje"]) != hoje:
        print(f"ERRO plano de outro dia ({plano['hoje']}; hoje é {hoje}): refaça o plano com hoje = {hoje} ou passe --hoje")
        return 1
    v = Validador(cfg, hoje, precos, horas_max=a.horas, aceitar_velho=a.aceitar_velho)
    v.validar(plano.get("itens", []))
    for m in v.avisos:
        print(m)
    for m in v.erros:
        print(m)
    print(f"\n{len(plano.get('itens', []))} itens: {len(v.erros)} erro(s), {len(v.avisos)} aviso(s).")
    if v.erros:
        print("NÃO GRAVE: corrija o plano e valide de novo.")
        return 1
    print("OK para gravar.")
    if a.payload:
        print(json.dumps(montar_payload(cfg, plano["itens"]), ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())

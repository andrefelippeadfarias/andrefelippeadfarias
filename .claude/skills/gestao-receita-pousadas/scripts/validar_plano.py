"""Trava da autonomia: confere um plano de mudanças antes de gravar no PriceLabs.

Uso:
  python3 validar_plano.py plano.json [--precos SAIDA_WORKFLOW.json] [--payload] [--fatias PASTA [--max-datas 70]]
  --fatias grava o payload em chamadas de até 70 datas por quarto, com D0 e D1 em chamadas separadas
  (scripts/fatiar_payload.py; revisão de 05/11: lote de 12 meses numa chamada só é arriscado)

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
  "regra_h"      sábado sozinho liberado (exige ocupacao_sabado < 50, quarta a sexta antes, fora de feriado); só em
                 noites antes de 05/11: a ordem do dono de 04/11 tirou a liberação de última hora
  "piso_feriado" corte na Queen (2) só para acompanhar o piso de feriado
  "dono"         ordem explícita do dono nesta conversa; cite no motivo. Nunca passa por cima
                 dos limites de segurança. É a única forma de gravar preço fixo. Para a estadia mínima, a ordem
                 citada precisa liberar 'estadia' (e, desde 05/11, ser mais nova que a regra do dia de chegada).
                 A ordem da própria regra (dono-2026-11-04-estadia-dia-chegada) não serve de exceção "dono".

Estadia mínima (dono 04/11, desde as noites de 05/11; `parametros.estadia_por_dia_chegada`): domingo, terça e quinta 1;
segunda, quarta e sexta 2; sábado 2 (Regra H); feriado, véspera e pacote com a estadia própria; temporada acima do dia
vale (o período da sazonalidade que é o próprio feriado não conta). Noite órfã (livre no quarto com a noite seguinte
esgotada nele, fora de feriado, pacote e sábado) com 1: o item leva "orfa": "seguinte" e "orfa_prova", do inventário do
Beds24 quando a rotina o leu ({fonte: "beds24", unidades, noite_numAvail, seguinte_numAvail, lido_em}: vale por si com
a data da leitura, de hoje ou de ontem) ou do recálculo ({noite_vendidas, noite_total, seguinte_vendidas,
seguinte_total}); com --precos, o calendário confere o que souber. Sábado órfão (sexta esgotada, sábado livre; regra
de 02/11, `regra_h_beds24.orfas.ativa`): "orfa": "sábado" com "orfa_prova" (anterior_* = a sexta), qualquer que seja a
ordem do item, ou excecao "dono" com a ordem de 02/11. Noite aguardando o dono (`estadia_por_dia_chegada.aguardando_dono`:
quartas da Afrodite) fica fora da regra do dia. "manter_reason": true (item só de estadia numa data cuja regra por data
tem preço, piso, teto ou chegada/saída, ou reason da Regra J) faz o payload sair sem reason, para não apagar o da regra.

Padrão de qualidade (dono, 08/10): sexta, sábado e noites de feriado das suítes com banheira (todas menos
a Balcony) ficam a partir de R$ 1.000 (limites_seguranca.padrao_qualidade_diaria), no preço ou no piso da data. Para
ir abaixo, o item leva o campo "mercado" com o dado que justifica (mediana da Booking, ocupação do mercado,
ritmo de vendas; texto com número, mínimo de 20 caracteres). Sem ele, ERRO. Com ele, sai um aviso para o
relatório. Vale também para o teto (max_price) do item. O valor fica em limites_seguranca (só o dono muda).

Sai com código 0 se tudo passou e 1 se houve ERRO. Com --payload, imprime os pedidos prontos
para update_listing_date_overrides, delete_listing_date_overrides e update_listing_data; com --fatias, grava cada
chamada num arquivo (até --max-datas datas por quarto; D0 e D1 em chamadas próprias) e imprime a lista na ordem de
gravação (apagar antes; depois D0, D1 e o resto, quarto a quarto).
"""

from __future__ import annotations

import argparse
import math
import json
import re
import sys
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
from functools import lru_cache

from comum import (DIAS_SEMANA, aguardando_dono, carregar_quartos, data, data_de_pacote, estadia_do_dia, estadia_pacote,
                   hoje_brt, ler_instante, ler_json, periodo_feriado, reais, regra_dia_chegada, resultado_workflow)
from ordens import carregar_ordens, cobre, congelada, ordem_valida

TIPOS = {"data", "apagar", "anuncio", "personalizacao"}
PALAVRAS_PROIBIDAS = ("ota", "oferta", "genius", "promo", "deal", "visibility")
CAMPOS_PRECO = ("preco", "min_price", "max_price", "check_in", "check_out")


@lru_cache(maxsize=1)
def _sazonalidade() -> dict | None:
    try:
        from sazonalidade import carregar
        return carregar()
    except Exception:
        return None


def estadia_temporada(d: date) -> int | None:
    """Estadia mínima do período de sazonalidade da noite (ex.: pico de julho 2, CBQ 16/09/2027 2), ou None. É a do
    feriado sem estadia própria em quartos.json (Carnaval 3); fora de feriado vale `estadia_temporada_dia`."""
    cal = _sazonalidade()
    if not cal:
        return None
    from sazonalidade import periodo
    p = periodo(cal, d)
    return int(p["estadia_minima"]) if p and p.get("estadia_minima") else None


def periodo_temporada(cfg: dict, d: date) -> dict | None:
    """Período de sazonalidade da noite que é TEMPORADA, não o próprio feriado: o período que se sobrepõe a um feriado de
    quartos.json não conta (revisão de 05/11, ordem dono-2026-11-02-aniv-sp-gatilho: com o gatilho de 01/01 tirando o
    domingo 24/01 do feriado do Aniversário de SP, o período "noites sex 22 a dom 24/1" da sazonalidade ainda dava 2 ao
    domingo, que tem de poder ir a 1). Pico de julho (11-25/07) e CBQ (15-18/09) não tocam feriado e contam."""
    cal = _sazonalidade()
    if not cal:
        return None
    from sazonalidade import periodo
    p = periodo(cal, d)
    if not p or not p.get("estadia_minima"):
        return None
    ini, fim = data(p["inicio"]), data(p["fim"])
    if any(data(f["inicio"]) <= fim and ini <= data(f["fim"]) for f in cfg.get("feriados", []) or []):
        return None
    return p


def estadia_temporada_dia(cfg: dict, d: date) -> int | None:
    """Estadia da temporada fora de feriado (piso da regra do dia de chegada), sem o período do próprio feriado."""
    p = periodo_temporada(cfg, d)
    return int(p["estadia_minima"]) if p else None


def estadia_exigida(cfg: dict, d: date, fer: dict | None) -> int | None:
    """Estadia mínima que a data exige. Pacote fechado do dono (03/11): a estadia por data. Feriado: a do período em
    quartos.json, senão a do calendário de sazonalidade, senão 2. Fora de feriado, com a ordem do dono de 04/11
    (`parametros.estadia_por_dia_chegada`, desde as noites de 05/11): a do dia de chegada (domingo, terça e quinta 1;
    segunda, quarta, sexta e sábado 2), nunca abaixo da temporada (sazonalidade: pico de julho 2; o período que é o
    próprio feriado não conta, `periodo_temporada`). Antes dela: 2 no sábado. None quando a data não exige nada. A noite
    órfã é exceção à exigida (estadia_alvo), não muda a exigida."""
    pac = estadia_pacote(cfg, d)
    if pac is not None:
        return pac
    if fer:
        if fer.get("estadia_minima"):
            return int(fer["estadia_minima"])
        return estadia_temporada(d) or 2
    base = estadia_do_dia(cfg, d)
    if base is None:
        return 2 if d.weekday() == 5 else None
    if d.weekday() == 5:
        base = max(base, 2)
    return max(base, estadia_temporada_dia(cfg, d) or 1)


def regra_do_dia_decide(cfg: dict, d: date, fer: dict | None = None, quarto: str | None = None) -> bool:
    """A estadia da noite é a do dia de chegada (ordem de 04/11): regra vigente, fora de feriado, de pacote e do sábado
    (sábado é a Regra H e o sábado órfão de 02/11; feriado, véspera e pacote ficam com a estadia própria). Com `quarto`,
    a noite aguardando o dono (`estadia_por_dia_chegada.aguardando_dono`: quartas da Afrodite) também fica de fora."""
    fer = fer if fer is not None else periodo_feriado(cfg, d)
    if quarto and aguardando_dono(cfg, quarto, d):
        return False
    return bool(regra_dia_chegada(cfg, d)) and not fer and not data_de_pacote(cfg, d) and d.weekday() != 5


def orfa_seguinte(cfg: dict, d: date, fer: dict | None, livre: bool, seguinte_esgotada: bool,
                  quarto: str | None = None) -> bool:
    """Noite órfã da ordem de 04/11 ("libera órfã"): a noite tem unidade livre no quarto e a noite seguinte está esgotada
    nele, então a chegada nela só cabe com 1 noite. Só onde a regra do dia decide (fora de feriado, de pacote e do
    sábado: o sábado órfão é a regra de 02/11, regra_h_beds24.orfas) e com `orfa_noite_seguinte` ligada."""
    r = regra_dia_chegada(cfg, d)
    if not r or not r.get("orfa_noite_seguinte") or not regra_do_dia_decide(cfg, d, fer, quarto):
        return False
    return bool(livre) and bool(seguinte_esgotada)


def estadia_alvo(cfg: dict, d: date, fer: dict | None, livre: bool = False,
                 seguinte_esgotada: bool = False, quarto: str | None = None) -> tuple[int | None, str | None]:
    """(estadia alvo, 'seguinte' se for noite órfã): a exigida, ou 1 na noite órfã da ordem de 04/11."""
    exig = estadia_exigida(cfg, d, fer)
    if exig and exig > 1 and orfa_seguinte(cfg, d, fer, livre, seguinte_esgotada, quarto):
        return 1, "seguinte"
    return exig, None


def estadia_planejada(cfg: dict, d: date, atual: int | None, livre: bool = False,
                      seguinte_esgotada: bool = False, quarto: str | None = None) -> tuple[int | None, str | None]:
    """(estadia que a noite deve ter, 'seguinte' na noite órfã) pela ordem de 04/11, partindo da `atual` (None = sem
    estadia por data); (None, None) onde a regra do dia não decide (antes de 05/11, feriado, pacote, sábado e, com
    `quarto`, a noite aguardando o dono: nem sobe nem desce). Sobe ao alvo o que está abaixo dele; desce só a 1
    (domingo, terça e quinta fora de temporada, e a noite órfã); acima de um alvo de 2 (temporada, segunda/quarta/sexta
    com 3) fica como está. O dia órfão do PriceLabs (1 entre noites esgotadas) é decisão de quem chama: ele sabe a noite
    anterior."""
    fer = periodo_feriado(cfg, d)
    if not regra_do_dia_decide(cfg, d, fer, quarto):
        return None, None
    alvo, orfa = estadia_alvo(cfg, d, fer, livre, seguinte_esgotada, quarto)
    if not alvo:
        return None, None
    if atual is None or int(atual) < alvo or (alvo == 1 and int(atual) > 1):
        return alvo, orfa
    return int(atual), orfa


def checar_prova_orfa(prova, vizinha: str, hoje: date, fuso: str = "America/Sao_Paulo") -> str | None:
    """None quando `orfa_prova` prova a órfã; senão o porquê. `vizinha` é a noite que esgotou: "seguinte" (noite órfã da
    ordem de 04/11) ou "anterior" (sábado órfão da ordem de 02/11: a sexta). Duas fontes (revisão de 05/11, fonte única):
      - Beds24 (a do inventário, quando a rotina o leu): {"fonte": "beds24", "unidades", "noite_numAvail",
        "<vizinha>_numAvail", "lido_em"}: noite com numAvail > 0 e a vizinha com numAvail <= 0; vale por si com a data da
        leitura (lido_em de hoje ou de ontem, no fuso das pousadas);
      - recálculo do PriceLabs (sem inventário): {"noite_vendidas", "noite_total", "<vizinha>_vendidas",
        "<vizinha>_total"}: noite com unidade livre e a vizinha toda vendida."""
    if not isinstance(prova, dict) or not prova:
        return "sem orfa_prova"
    if prova.get("fonte") == "beds24":
        try:
            u, n, s = int(prova["unidades"]), int(prova["noite_numAvail"]), int(prova[f"{vizinha}_numAvail"])
        except (KeyError, TypeError, ValueError):
            return f"prova do Beds24 incompleta (unidades, noite_numAvail, {vizinha}_numAvail)"
        if not (u > 0 and n > 0 and s <= 0):
            return f"prova do Beds24 não mostra a noite livre ({n}) com a {vizinha} esgotada ({s})"
        lido = ler_instante(prova.get("lido_em"))
        if lido is None:
            return "prova do Beds24 sem lido_em (data e hora da leitura do inventário)"
        from zoneinfo import ZoneInfo
        dl = lido.astimezone(ZoneInfo(fuso)).date()
        if not (hoje - timedelta(days=1) <= dl <= hoje):
            return f"prova do Beds24 lida em {dl.isoformat()}: leia o inventário de novo"
        return None
    try:
        nv, nt = int(prova.get("noite_vendidas")), int(prova.get("noite_total"))
        sv, st = int(prova.get(f"{vizinha}_vendidas")), int(prova.get(f"{vizinha}_total"))
    except (TypeError, ValueError):
        return f"prova incompleta (noite_vendidas, noite_total, {vizinha}_vendidas, {vizinha}_total)"
    if not (st > 0 and sv >= st and nt > 0 and nv < nt):
        return f"prova não mostra a noite livre ({nv}/{nt}) com a {vizinha} esgotada ({sv}/{st})"
    return None


def ordem_mais_nova(o: dict, ref: dict) -> bool:
    """A ordem `o` foi dada depois da `ref` (data maior, ou mesma data e criada_em posterior; o mesmo critério das faixas
    do dono)."""
    dx, dr = o.get("data") or "", ref.get("data") or ""
    cx, cr = ler_instante(o.get("criada_em")), ler_instante(ref.get("criada_em"))
    return dx > dr or (dx == dr and cx is not None and cr is not None and cx > cr)


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
        exig = estadia_exigida(self.cfg, d, fer)
        espera = not fer and d.weekday() != 5 and aguardando_dono(self.cfg, q["curto"], d)
        if (d.weekday() == 5 or fer or (exig and exig > 1)) and not espera:
            # dono 04/11: segunda, quarta e sexta também exigem 2; apagar a data cai na estadia padrão do PriceLabs (1)
            self.aviso(i, it, f"apagar a substituição de {'feriado' if fer else DIAS_SEMANA[d.weekday()]} remove a "
                              f"estadia mínima ({exig} noites); regrave min_stay {exig} no mesmo plano")
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
        dono_valida = False  # excecao "dono" com ordem vigente (a estadia confere à parte o que a ordem libera)
        if excecao == "dono":
            # Dono (07/10, 14/10) + auditoria de 30/10: a exceção do dono precisa apontar a ordem literal dele, vigente
            # para o quarto e a noite; sem isso, os scripts não podem carimbar "dono" sozinhos.
            dono_valida = ordem_valida(self.ordens, it.get("ordem_id"), q["curto"], d)
            if not dono_valida:
                self.erro(i, it, f"excecao 'dono' sem ordem_id vigente para {q['curto']} em {d} (dados/ordens_dono.json; "
                                 f"ordem_id={it.get('ordem_id')!r}): registre a ordem do dono com validade ou tire a exceção")
                excecao = None
            elif it.get("ordem_id") in self.ordens_regra_estadia():
                # revisão de 05/11: a ordem da estadia pelo dia de chegada (tipo "regra") vale para todo quarto e toda
                # noite e não libera nada além da noite órfã; carimbada como exceção "dono" ela abriria preço fixo, Regra
                # I, chegada/saída e piso de feriado em qualquer noite. Fora da estadia ela não é exceção
                if any(it.get(k) is not None for k in CAMPOS_PRECO) or it.get("price_type") == "fixed":
                    self.erro(i, it, f"a ordem {it.get('ordem_id')} é a regra da estadia pelo dia de chegada (dono 04/11): "
                                     "não serve de exceção 'dono' para preço fixo, Regra I, chegada/saída nem piso de "
                                     "feriado; cite a ordem do dono que libera isso")
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
            if minimo > float(q["max"]) + 0.5:
                # revisão de 04/11: piso acima do máximo do anúncio deixa piso > teto no PriceLabs (Natal a R$ 5.024)
                self.erro(i, it, f"piso efetivo R$ {minimo:.0f} acima do máximo do anúncio R$ {float(q['max']):.0f}: "
                                 "limite ao máximo (pace.teto_anuncio) ou peça ao dono para subir o máximo")

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
        regra_dia = regra_dia_chegada(self.cfg, d)
        decide_dia = regra_do_dia_decide(self.cfg, d, fer, q["curto"])
        # revisão de 05/11: noite fora da regra do dia até o dono decidir (quartas da Afrodite): o validador não acusa
        espera = aguardando_dono(self.cfg, q["curto"], d) if regra_do_dia_decide(self.cfg, d, fer) else None
        pacote = data_de_pacote(self.cfg, d)
        if ms is None and exig and minimo is not None and (fer or d.weekday() == 5):
            # segunda, quarta e sexta (dono 04/11) não avisam: o item sem min_stay mescla com a regra da data e a estadia
            # dela fica; quem apaga a data regrava a estadia (pace.ajustar_tetos)
            self.aviso(i, it, f"{'feriado' if fer else 'sábado'} sem min_stay no item: confira a estadia ({exig} noites) depois de gravar")
        if it.get("manter_reason") and any(it.get(k) is not None for k in CAMPOS_PRECO):
            # o payload sem reason mantém o da regra da data; com preço novo ele ficaria mentindo
            self.erro(i, it, "manter_reason só em item só de estadia (sem preço, piso, teto, chegada/saída)")
        if ms is not None:
            ms = int(ms)
            orfa_ok = False
            lib_est, por_que = self.libera_estadia(it, "dono" if dono_valida else None, d)
            nao_libera = f" ({por_que})" if por_que else ""
            orfas = (((self.cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("orfas") or {})
            ordem_orfa = orfas.get("ordem_id")
            fuso = self.cfg.get("fuso") or "America/Sao_Paulo"
            if ordem_orfa and it.get("ordem_id") == ordem_orfa and ms < 2:
                # dono 02/11: a estadia 1 da ordem do sábado órfão vale só na noite órfã (sexta ou sábado), fora de feriado
                lado = it.get("orfa")
                if fer or lado not in ("sexta", "sábado") or d.weekday() != (4 if lado == "sexta" else 5):
                    self.erro(i, it, f"{q['nome']}: estadia {ms} pela ordem {ordem_orfa} só na noite órfã (campo 'orfa' "
                                     "'sexta' numa sexta ou 'sábado' num sábado) e fora de feriado")
                elif lado == "sexta" and regra_dia:
                    # dono 04/11 ("libera órfã"): a sexta órfã passou à noite órfã geral, com prova da noite seguinte
                    self.erro(i, it, f"{q['nome']}: desde 05/11 a sexta órfã é a noite órfã geral (dono 04/11): item com "
                                     "orfa 'seguinte' e orfa_prova, não pela ordem de 02/11")
                elif not orfas.get("ativa"):
                    self.erro(i, it, f"{q['nome']}: sábado órfão com a regra de 02/11 desligada "
                                     "(regra_h_beds24.orfas.ativa)")
                elif excecao == "dono":
                    orfa_ok = True  # como antes de 05/11: a ordem de 02/11 citada como exceção do dono, já conferida
                elif it.get("orfa_prova") is None:
                    # revisão de 05/11: sem excecao 'dono' (que confere a ordem) nem prova, o sábado órfão não passa
                    self.erro(i, it, f"{q['nome']}: sábado com 1 noite pela ordem {ordem_orfa} sem excecao 'dono' nem "
                                     "orfa_prova (sexta esgotada e sábado livre)")
            if it.get("orfa") == "sábado" and ms < 2 and not orfa_ok and it.get("orfa_prova") is not None:
                # revisão de 05/11 (pace.ajustar_tetos): o sábado órfão provado (sexta esgotada no quarto, sábado livre)
                # mantém 1 qualquer que seja a ordem do item (a da escada ou da faixa), com a regra de 02/11 ligada
                falha = checar_prova_orfa(it.get("orfa_prova"), "anterior", self.hoje, fuso)
                ant = self.precos.get((q["curto"], (d - timedelta(days=1)).isoformat())) or {}
                noite = self.precos.get((q["curto"], d.isoformat())) or {}
                if d.weekday() != 5 or fer:
                    self.erro(i, it, f"{q['nome']}: orfa 'sábado' só num sábado fora de feriado")
                elif not orfas.get("ativa"):
                    self.erro(i, it, f"{q['nome']}: sábado órfão com a regra de 02/11 desligada "
                                     "(regra_h_beds24.orfas.ativa)")
                elif falha:
                    self.erro(i, it, f"{q['nome']}: sábado órfão sem prova ({falha})")
                elif (ant.get("livres") is not None and int(ant["livres"]) > 0) or \
                        (noite.get("livres") is not None and int(noite["livres"]) <= 0):
                    self.erro(i, it, f"{q['nome']}: o calendário de --precos desmente o sábado órfão (sábado com "
                                     f"{noite.get('livres', '?')} livre(s), sexta com {ant.get('livres', '?')}): refaça "
                                     "com a leitura nova")
                else:
                    orfa_ok = True
            if it.get("orfa") == "seguinte" and ms < (exig or 1):
                # dono 04/11 ("libera órfã"): 1 diária na noite livre com a noite seguinte esgotada no quarto. É regra
                # permanente (não exceção): o item prova a ocupação em `orfa_prova` (do inventário do Beds24 quando a
                # rotina o leu, senão do recálculo); com --precos, o calendário confere o que souber.
                falha = checar_prova_orfa(it.get("orfa_prova"), "seguinte", self.hoje, fuso)
                seg = self.precos.get((q["curto"], (d + timedelta(days=1)).isoformat())) or {}
                noite = self.precos.get((q["curto"], d.isoformat())) or {}
                if not regra_dia or not regra_dia.get("orfa_noite_seguinte") or not decide_dia or ms != 1:
                    self.erro(i, it, f"{q['nome']}: noite órfã (dono 04/11) só com estadia 1, fora de feriado, pacote e "
                                     "sábado, desde 05/11" + (f" (noite aguardando o dono: {espera[:80]})" if espera else ""))
                elif falha:
                    self.erro(i, it, f"{q['nome']}: noite órfã sem prova ({falha}; orfa_prova: noite livre e a seguinte "
                                     "esgotada no quarto, do inventário do Beds24 ou do recálculo)")
                elif (seg.get("livres") is not None and int(seg["livres"]) > 0) or \
                        (noite.get("livres") is not None and int(noite["livres"]) <= 0):
                    self.erro(i, it, f"{q['nome']}: o calendário de --precos desmente a noite órfã (noite com "
                                     f"{noite.get('livres', '?')} livre(s), seguinte com {seg.get('livres', '?')}): refaça "
                                     "com o recálculo novo")
                else:
                    orfa_ok = True
            if (fer or pacote) and exig and ms < exig and not lib_est:
                self.erro(i, it, f"{'feriado (' + fer['nome'] + ')' if fer else 'pacote do dono (03/11)'} exige estadia "
                                 f"mínima {exig}; item com {ms}" + nao_libera)
            if decide_dia and exig:
                # dono 04/11: "Abriremos apenas para uma diária. A diária de domingo, a diária de terça e a diária de
                # quinta. Todas as outras deverão ser com no mínimo 2 dias" (última hora não libera)
                if ms < exig and not orfa_ok and not lib_est:
                    self.erro(i, it, f"{q['nome']}: chegada de {DIAS_SEMANA[d.weekday()]} exige estadia mínima {exig} "
                                     f"(dono 04/11: 1 diária só domingo, terça e quinta; noite órfã com 'orfa' e "
                                     f"'orfa_prova'); item com {ms}" + nao_libera)
                elif ms > exig and not lib_est:
                    if exig == 1:
                        self.erro(i, it, f"{q['nome']}: {DIAS_SEMANA[d.weekday()]} abre 1 diária (dono 04/11); item com "
                                         f"{ms} (acima só com ordem do dono mais nova que libere 'estadia')" + nao_libera)
                    else:
                        self.aviso(i, it, f"{q['nome']}: estadia {ms} acima da do dia ({exig}, dono 04/11: no mínimo 2)")
            if d.weekday() == 5 and ms < 2:
                if regra_dia:
                    # dono 04/11: última hora não libera (sai a exceção 'regra_h'); só o sábado órfão (ordem de 02/11 com
                    # excecao 'dono', ou provado com orfa 'sábado' e orfa_prova) ou ordem do dono mais nova que libere
                    # 'estadia'
                    ok = not fer and (orfa_ok or lib_est)
                    msg = ("sábado com 1 noite: desde 05/11 (dono 04/11, última hora não libera) só o sábado órfão de 02/11 "
                           f"(orfa 'sábado' com orfa_prova, ou excecao 'dono' com a ordem {ordem_orfa}) ou ordem do dono "
                           "mais nova que libere 'estadia', fora de feriado" + nao_libera)
                else:
                    # A política de não vender sábado sozinho é do dono: ordem expressa dele que libere 'estadia' também.
                    ok = (not fer and (lib_est or orfa_ok)) or (
                        excecao == "regra_h" and not fer and it.get("ocupacao_sabado") is not None
                        and float(it["ocupacao_sabado"]) < 50 and self.hoje.weekday() in (2, 3, 4)
                        and antecedencia <= 3)
                    msg = ("sábado com 1 noite: a Regra H só libera na quarta a sexta antes, fora de feriado e com o sábado "
                           "abaixo de 50% (excecao 'regra_h' e ocupacao_sabado), ou ordem do dono que libere 'estadia'")
                if not ok:
                    self.erro(i, it, msg)
            if ms < 1 or ms > 7:
                self.erro(i, it, f"estadia mínima {ms} fora do razoável")

    def ordens_regra_estadia(self) -> set:
        """Ids das ordens que SÃO a regra da estadia pelo dia de chegada (tipo "regra", dono 04/11): não servem de
        exceção "dono" fora da estadia nem abrem exceção contra si mesmas."""
        oid = ((self.cfg.get("parametros") or {}).get("estadia_por_dia_chegada") or {}).get("ordem_id")
        return {oid} if oid else set()

    def libera_estadia(self, it: dict, excecao: str | None, d: date) -> tuple[bool, str | None]:
        """(libera, por que não) da exceção do dono à estadia mínima (revisão de 05/11: antes qualquer ordem válida
        liberava a estadia do feriado e do sábado, até uma ordem de preço). A ordem citada (já conferida por
        ordem_valida) precisa liberar 'estadia' e, onde vale a regra do dia de chegada (desde 05/11), ser mais nova que a
        ordem dela: as liberações de estadia de 01/11 (Beds24) são anteriores e não passam por cima de "Todas as outras
        deverão ser com no mínimo 2 dias". O motivo distingue "não libera estadia", "a própria regra" e "anterior à regra
        de 04/11"; None quando não há exceção do dono no item."""
        if excecao != "dono":
            return False, None
        oid = it.get("ordem_id")
        o = (self.ordens or {}).get(oid or "") or {}
        regra = regra_dia_chegada(self.cfg, d)
        if regra and oid == regra.get("ordem_id"):
            return False, (f"a ordem {oid} é a própria regra do dia de chegada: não abre exceção contra si mesma "
                           "(libera só a noite órfã)")
        if "estadia" not in (o.get("libera") or []):
            return False, f"a ordem {oid} não libera 'estadia'"
        if not regra:
            return True, None
        ref = (self.ordens or {}).get(regra.get("ordem_id") or "")
        if not ref:
            desde = regra.get("desde")
            ref = {"data": (data(desde) - timedelta(days=1)).isoformat() if desde else ""}
        if ordem_mais_nova(o, ref):
            return True, None
        return False, (f"a ordem {oid} ({o.get('data') or '?'}) é anterior à regra de 04/11 da estadia pelo dia de "
                       "chegada: só ordem do dono mais nova que ela libera")

    def validar_chegada_saida(self, i, it, q, d, excecao):
        """Chegada e saída por data (dono, 21/10): strings de 7 caracteres 0/1, de segunda a domingo. Desde 02/11 (dono:
        "todos os sábados fechados sempre, tanto para a entrada quanto para a saída", `regra_h_beds24.sabado_override`):
        abrir a entrada ou a saída num sábado é erro sem excecao "dono". Antes (Regra H de 01/11): a chegada no sábado ficava LIBERADA (o sábado sozinho é barrado pela estadia mínima 2); fechar a
        saída no sábado é permitido (barra a sexta sozinha, como o noCheckOut do Beds24). Fechar outra noite, ou a
        chegada no sábado, só com excecao "dono"."""
        if it.get("check_in") is None and it.get("check_out") is None:
            return
        for campo in ("check_in", "check_out"):
            s = str(it.get(campo) or "1111111")
            if len(s) != 7 or set(s) - {"0", "1"}:
                self.erro(i, it, f"{campo} '{s}' precisa ter 7 caracteres 0/1 (segunda a domingo)")
                return
            if s == "0000000":
                self.erro(i, it, f"{campo} com todos os dias fechados: o PriceLabs recusa")
                return
            sab_fechado = ((self.cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("sabado_override") == \
                "noCheckInOrCheckOut"
            if d.weekday() == 5 and sab_fechado:
                # dono 02/11: "mantenha todos os sábados fechados sempre, tanto para a entrada quanto para a saída"
                if s[5] == "1" and excecao != "dono":
                    self.erro(i, it, f"{q['nome']}: {campo} abre o sábado contra a ordem do dono de 02/11 (sábado fechado "
                                     "para entrada e saída, sempre); só com nova ordem do dono (excecao 'dono')")
                elif s[5] == "1":
                    # exceção do dono de 02/11 ("Pode fazer"): só ordem que libera abrir o sábado (revisão 02/11: antes
                    # qualquer ordem vigente abria); o lado órfão abre só com o motivo (que lado esgotou)
                    rh = (self.cfg.get("parametros") or {}).get("regra_h_beds24") or {}
                    ordem_orfa = (rh.get("orfas") or {}).get("ordem_id")
                    lib = ((self.ordens or {}).get(it.get("ordem_id") or "") or {}).get("libera") or []
                    if "sabado_aberto" not in lib and "sabado_aberto_orfao" not in lib:
                        self.erro(i, it, f"{q['nome']}: {campo} abre o sábado e a ordem citada ({it.get('ordem_id')}) não "
                                         "libera 'sabado_aberto' nem 'sabado_aberto_orfao'")
                    elif ordem_orfa and it.get("ordem_id") == ordem_orfa:
                        lado = it.get("orfa")
                        if lado not in ("sexta", "sábado"):
                            self.erro(i, it, f"{q['nome']}: abrir o sábado pela ordem {ordem_orfa} exige o campo 'orfa' "
                                             "('sexta' = sábado esgotado e sexta livre; 'sábado' = sexta esgotada)")
                        elif (lado == "sexta" and campo == "check_in") or (lado == "sábado" and campo == "check_out"):
                            self.erro(i, it, f"{q['nome']}: a ordem {ordem_orfa} abre só o lado órfão ({'saída' if lado == 'sexta' else 'entrada'} "
                                             f"do sábado); {campo} aberto vai além dela")
                continue
            if s[d.weekday()] == "0" and excecao != "dono":
                if d.weekday() != 5:
                    self.erro(i, it, f"{campo} fecha uma noite que não é sábado: só o dono decide (excecao 'dono')")
                elif campo == "check_in":
                    self.erro(i, it, f"{q['nome']}: chegada no sábado fechada contra a Regra H (dono 01/11: chegada "
                                     "liberada com 2 noites); só com excecao 'dono'")

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
        if it.get("excecao") == "dono" and it.get("ordem_id") in self.ordens_regra_estadia():
            self.erro(i, it, f"a ordem {it.get('ordem_id')} é a regra de estadia por dia de chegada (04/11): não vale "
                             "como exceção 'dono' em item de anúncio")
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
            libera_g = [o.get("id") for o in (self.ordens or {}).values()
                        if cobre(o, "Balcony", data(d)) and "regra_g" in (o.get("libera") or [])
                        and (not o.get("noites") or d in o["noites"])]
            if libera_g and balc < alvo * 0.95:
                # dono 01/11 ("Pode baixar a Balcony de 08 e 09/11"): ordem por data libera a Balcony abaixo da Regra G
                self.avisos.append(f"aviso Regra G {d}: Balcony {balc:.0f} abaixo do alvo {alvo:.0f} pela ordem "
                                   f"{libera_g[0]} (libera regra_g)")
                continue
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
            o = {"date": it["data"]}
            so_estadia = it.get("min_stay") is not None and all(it.get(k) is None for k in CAMPOS_PRECO)
            if not (so_estadia and it.get("manter_reason")):
                # item só de estadia numa data que já tem regra por data (`manter_reason`, calendario_12m --regras): a API
                # mescla os campos e o reason da regra fica (topo_mercado lê "Regra J.*antes R$" nele)
                o["reason"] = str(it.get("motivo", ""))[:250]
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
    ap.add_argument("--fatias", metavar="PASTA", help="grava o payload fatiado (fatiar_payload.py): uma chamada por arquivo, "
                                                      "até --max-datas datas por quarto, D0 e D1 em chamadas próprias")
    ap.add_argument("--max-datas", type=int, default=70, help="datas por chamada no payload fatiado (padrão 70)")
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
    if a.fatias:
        from fatiar_payload import fatiar, gravar_fatias
        fatias = fatiar(cfg, montar_payload(cfg, plano["itens"]), hoje, a.max_datas)
        for linha in gravar_fatias(fatias, a.fatias):
            print(linha)
    return 0


if __name__ == "__main__":
    sys.exit(main())

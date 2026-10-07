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

Sai com código 0 se tudo passou e 1 se houve ERRO. Com --payload, imprime os pedidos prontos
para update_listing_date_overrides, delete_listing_date_overrides e update_listing_data.
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict
from datetime import date

from comum import carregar_quartos, data, ler_json, periodo_feriado, resultado_workflow

TIPOS = {"data", "apagar", "anuncio", "personalizacao"}
PALAVRAS_PROIBIDAS = ("ota", "oferta", "genius", "promo", "deal", "visibility")


class Validador:
    def __init__(self, cfg: dict, hoje: date, precos: dict | None = None):
        self.cfg, self.hoje = cfg, hoje
        self.precos = precos or {}          # (curto, "AAAA-MM-DD") -> {"preco", "livres"}
        self.erros: list[str] = []
        self.avisos: list[str] = []
        lim = cfg["limites_seguranca"]
        self.fracao = lim["fracao_min_referencia"]
        self.var_semanal = lim["variacao_semanal_base_min"]
        self.piso_seg = lim["piso_feriado_real_seguranca"]
        self.par = cfg["parametros"]
        self.pisos: set = set()             # (curto, data) cujo valor em "finais" é piso de percentual

    # ---------- utilidades
    def erro(self, i, item, msg):
        self.erros.append(f"ERRO item {i} ({item.get('quarto')} {item.get('data', item.get('campo', ''))}): {msg}")

    def aviso(self, i, item, msg):
        self.avisos.append(f"aviso item {i} ({item.get('quarto')} {item.get('data', '')}): {msg}")

    def piso_absoluto(self, q) -> float:
        return round(self.fracao * q["min_referencia"], 2)

    def fator_piso(self, q) -> float:
        return q["fator_vitrine"] if q.get("piso_feriado_base") == "vitrine" else q["fator_real"]

    # ---------- validações
    def validar(self, itens: list[dict]) -> None:
        finais = {}  # (curto, data) -> preço fixo planejado ou piso (min_price) do percentual
        for i, it in enumerate(itens, 1):
            tipo = it.get("tipo")
            texto = json.dumps(it, ensure_ascii=False).lower()
            if tipo not in TIPOS:
                self.erro(i, it, f"tipo desconhecido '{tipo}'")
                continue
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

    def validar_apagar(self, i, it, q):
        try:
            d = data(it["data"])
        except (KeyError, ValueError):
            self.erro(i, it, "data inválida")
            return
        if d < self.hoje:
            self.erro(i, it, "data no passado")
        if d.weekday() == 5 and not periodo_feriado(self.cfg, d):
            self.aviso(i, it, "apagar a substituição de um sábado remove a estadia mínima de 2 noites "
                              "(Regra H); regrave min_stay 2 no mesmo plano")

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
        fer = periodo_feriado(self.cfg, d)
        antecedencia = (d - self.hoje).days
        piso_abs = self.piso_absoluto(q)
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
            if q.get("sem_desconto") and antes and minimo < float(antes) - 1 and \
               excecao not in ("piso_feriado", "dono"):
                self.erro(i, it, f"{q['nome']} não recebe desconto (de {antes} para {minimo:.0f})")

        ms = it.get("min_stay")
        if ms is not None:
            ms = int(ms)
            if d.weekday() == 5 and ms < 2:
                ok = (excecao == "regra_h" and not fer and it.get("ocupacao_sabado") is not None
                      and float(it["ocupacao_sabado"]) < 50 and self.hoje.weekday() in (2, 3, 4)
                      and antecedencia <= 3)
                if not ok:
                    self.erro(i, it, "sábado com 1 noite: a Regra H só libera na quarta a sexta antes, "
                                     "fora de feriado e com o sábado abaixo de 50% (excecao 'regra_h' "
                                     "e ocupacao_sabado)")
            if ms < 1 or ms > 7:
                self.erro(i, it, f"estadia mínima {ms} fora do razoável")

    def validar_anuncio(self, i, it, q):
        campo, valor = it.get("campo"), it.get("valor")
        if campo not in ("base", "min", "max"):
            self.erro(i, it, f"campo de anúncio '{campo}' não permitido")
            return
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

    def validar_balcony(self, finais):
        """Regra G (dono, 07/10): a Balcony (sem banheira) fica cerca de 10% (`regra_g_desconto`) abaixo da
        suíte com banheira mais barata e livre da Villa. ERRO se ficar acima dessa suíte ou mais de 5 pontos
        abaixo do alvo. Com percentual, compara o piso; contra suíte fora do plano, vira aviso."""
        desc = self.par.get("regra_g_desconto", 0.10)
        datas = {d for (k, d) in finais if k in ("Balcony", "VK7", "VK2")}
        for d in sorted(datas):
            def preco(k):
                return finais.get((k, d), (self.precos.get((k, d)) or {}).get("preco"))

            def livre(k):
                return (self.precos.get((k, d)) or {}).get("livres", 1) > 0
            balc = preco("Balcony")
            refs = [(preco(k), (k, d) in finais) for k in ("VK7", "VK2") if preco(k) is not None and livre(k)]
            if balc is None or not refs:
                continue
            if periodo_feriado(self.cfg, data(d)):
                continue  # no feriado a Balcony segue o piso de vitrine definido pelo dono
            menor, do_plano = min(refs)
            alvo = menor * (1 - desc)
            if balc > menor + 1:
                # piso acima da suíte: o preço final só pode ser maior ainda, então é erro sempre
                self.erros.append(f"ERRO Regra G {d}: Balcony {balc:.0f} acima da suíte com banheira mais "
                                  f"barata livre ({menor:.0f})")
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


def precos_da_coleta(res: dict) -> dict:
    out = {}
    for curto, sala in (res.get("rooms") or {}).items():
        for dia in sala.get("days") or []:
            mu = str(dia.get("multi_unit_occupancy") or "")
            if "/" in mu:
                v, t = (int(x) for x in mu.split("/"))
                livres = t - v
            else:
                livres = 0 if str(dia.get("booking_status") or "").strip() else 1
            out[(curto, str(dia["date"])[:10])] = {"preco": dia.get("price"), "livres": livres}
    return out


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
            if it.get("min_price") is not None:
                o.update(min_price=str(int(round(float(it["min_price"])))), min_price_type="fixed",
                         currency="BRL")
            if it.get("max_price") is not None:
                o.update(max_price=str(int(round(float(it["max_price"])))), max_price_type="fixed",
                         currency="BRL")
            if it.get("min_stay") is not None:
                o["min_stay"] = str(int(it["min_stay"]))
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
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    plano = ler_json(a.plano)
    precos = precos_da_coleta(resultado_workflow(ler_json(a.precos))) if a.precos else {}
    v = Validador(cfg, data(plano["hoje"]), precos)
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

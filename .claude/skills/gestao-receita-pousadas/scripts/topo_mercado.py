"""Regra J (dono, 27/10/2026): suíte lotada ou com overbooking, ou pousada lotada → topo do mercado.

"Quando estivermos com overbooking assim, ou a pousada lotada, essa suíte, suba o valor para o top de mercado para que
possamos evitar que entrem reservas, e se entrar a reserva, que entre com o valor mais alto do mercado. Coloque isso
dentro da nossa skill para sempre executarmos diariamente essas regras."

Gatilhos, noite a noite, de amanhã em diante (a noite de hoje fica):
  - suíte esgotada no calendário do PriceLabs (vendidas ≥ unidades);
  - overbooking na lista de reservas (reservas confirmadas na noite > unidades), mesmo que o calendário não acuse;
  - pousada lotada: vendidas da pousada na noite ≥ parametros.regra_j.limiar_pousada das unidades; aí as suítes que
    ainda têm unidade livre também vão ao topo.
Topo do mercado (preço público por noite na Booking), nesta ordem:
  1. `maximo_a` do período em dados/concorrentes_hidro_vista.json (maior quarto com hidro da classe A);
  2. maior preço por noite das buscas de concorrentes da coleta (`price_total_max` ÷ noites) que cobrem a noite;
  3. sem dado: preço atual × parametros.regra_j.sem_dado_mult.
Enviado = topo ÷ parametros.regra_j.fator_publico[pousada], nunca abaixo do preço atual nem acima do máximo do anúncio.
Piso = teto (preço fixo pela regra, em percent_base pelo payload do validador). O motivo guarda o preço de antes.

`restaurar`: noite com regra "Regra J" que deixou de estar lotada (cancelamento) volta ao preço de antes como piso,
com o teto no máximo do anúncio.

Uso:
  python3 topo_mercado.py plano --hoje AAAA-MM-DD Q7=<recálculo> Double=<recálculo> ... [--reservas reservas.json]
      [--coleta coleta.json] [--dias 90] [--saida plano_topo.json] [--regras Q7=<overrides.json> ...]
      (com --regras, a noite que já tem a Regra J usa o preço de antes como base: o × 1,5 não se acumula)
  python3 topo_mercado.py restaurar --hoje AAAA-MM-DD Q7=<recálculo> ... --regras Q7=<overrides.json> ... [--saida ...]
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import math
import os
import re
import sys
from collections import defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from calendario_12m import ler_recalculo  # noqa: E402
from comum import carregar_quartos, esgotado, ler_json, resultado_workflow, vendidas_total  # noqa: E402

ARQ_HIDRO = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "dados", "concorrentes_hidro_vista.json")


def periodos_mercado(hidro: dict, coleta: dict | None) -> list[tuple[str, set, float, str]]:
    """(rótulo, noites, topo público por noite, fonte), do mais confiável para o menos."""
    out = []
    for p in hidro.get("periodos", []):
        if p.get("maximo_a"):
            out.append((p["rotulo"], set(p["datas"]), float(p["maximo_a"]), "classe A (quarto com hidro)"))
    for s in ((coleta or {}).get("concorrentes") or {}).get("searches", []) or []:
        ci, co, mx = s.get("checkin"), s.get("checkout"), s.get("price_total_max")
        if not (ci and co and mx):
            continue
        a, b = dt.date.fromisoformat(ci), dt.date.fromisoformat(co)
        noites = {(a + dt.timedelta(days=k)).isoformat() for k in range((b - a).days)}
        if noites:
            out.append((s.get("label", ci), noites, float(mx) / len(noites), "busca da coleta (maior preço)"))
    return out


def topo_publico(periodos, noite: str):
    for rot, noites, valor, fonte in periodos:
        if noite in noites:
            return valor, f"{fonte}, {rot}"
    return None, None


def overbooking(reservas: list[dict], cfg: dict) -> dict:
    """(curto, noite) -> reservas confirmadas, só onde passa das unidades."""
    cont = defaultdict(int)
    for r in reservas:
        if r.get("booking_status") != "booked":
            continue
        q = cfg["por_id"].get(r.get("listing_id"))
        if not q:
            continue
        ci = dt.date.fromisoformat(r["check_in"])
        for k in range(int(r.get("no_of_days") or 1)):
            cont[(q["curto"], (ci + dt.timedelta(days=k)).isoformat())] += 1
    return {k: v for k, v in cont.items() if v > cfg["por_curto"][k[0]]["unidades"]}


def antes_regra_j(regras: dict | None) -> dict:
    """(curto, noite) -> preço de antes, das noites que já têm a Regra J gravada."""
    out = {}
    for curto, lista in (regras or {}).items():
        for o in lista:
            m = re.search(r"Regra J.*antes R\$ (\d+)", str(o.get("reason", "")))
            if m and o.get("date"):
                out[(curto, o["date"])] = float(m.group(1))
    return out


def plano(cfg, cal: dict, hoje: dt.date, dias: int, periodos, over: dict, regras: dict | None = None) -> list[dict]:
    """regras (overrides atuais por quarto): noite que já tem a Regra J usa o preço de antes como base do
    'sem dado de mercado', para o multiplicador não se acumular a cada rodada."""
    rj = cfg["parametros"]["regra_j"]
    ja = antes_regra_j(regras)
    itens = []
    for k in range(1, dias + 1):
        noite = (hoje + dt.timedelta(days=k)).isoformat()
        por_pousada = defaultdict(lambda: [0, 0])
        for curto, c in cal.items():
            r = c.get(noite)
            if r:
                q = cfg["por_curto"][curto]
                v, t = vendidas_total(r, q["unidades"])
                por_pousada[q["pousada"]][0] += min(v, t)
                por_pousada[q["pousada"]][1] += t
        for curto, c in cal.items():
            r = c.get(noite)
            if not r:
                continue
            q = cfg["por_curto"][curto]
            v, t = por_pousada[q["pousada"]]
            lotada_pousada = t > 0 and v / t >= rj["limiar_pousada"]
            ob = over.get((curto, noite))
            if not (esgotado(r, q["unidades"]) or ob or lotada_pousada):
                continue
            atual = float(r["price"])
            base = ja.get((curto, noite), atual)
            pub, fonte = topo_publico(periodos, noite)
            if pub:
                alvo = pub / rj["fator_publico"][q["pousada"]]
            else:
                alvo, fonte = base * rj["sem_dado_mult"], "sem dado de mercado: preço atual × " + str(rj["sem_dado_mult"])
            alvo = min(max(alvo, atual), float(q["max"]))
            alvo = math.floor(alvo)
            if alvo <= atual + 1:
                continue
            gat = (f"overbooking ({ob} reservas para {q['unidades']})" if ob else
                   "suíte esgotada" if esgotado(r, q["unidades"]) else f"pousada lotada ({v}/{t})")
            itens.append({"tipo": "data", "quarto": curto, "data": noite, "min_price": alvo, "max_price": alvo,
                          "preco_antes": atual, "excecao": "dono", "ordem_id": "dono-2026-10-27-regra-j",
                          "motivo": f"Regra J (dono 27/10): {gat}; topo do mercado ({fonte}); antes R$ {base:.0f}"})
    return itens


def restaurar(cfg, cal: dict, regras: dict, hoje: dt.date) -> list[dict]:
    itens = []
    for curto, lista in regras.items():
        q = cfg["por_curto"][curto]
        for o in lista:
            m = re.search(r"Regra J.*antes R\$ (\d+)", str(o.get("reason", "")))
            noite = o.get("date")
            if not m or not noite or noite <= hoje.isoformat():
                continue
            r = cal.get(curto, {}).get(noite)
            if r is None or esgotado(r, q["unidades"]):
                continue
            itens.append({"tipo": "data", "quarto": curto, "data": noite, "min_price": float(m.group(1)),
                          "max_price": float(q["max"]), "preco_antes": float(r["price"]),
                          "motivo": f"Regra J desfeita: a noite reabriu; volta ao preço de antes (R$ {m.group(1)}) como piso"})
    return itens


def ler_regras(pares: list[str]) -> dict:
    regras = {}
    for x in pares:
        k, v = x.split("=", 1)
        d = ler_json(v)
        regras[k] = ((d.get("data") or {}).get("overrides")) or d.get("overrides") or []
    return regras


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("modo", choices=["plano", "restaurar"])
    ap.add_argument("--hoje", required=True)
    ap.add_argument("--dias", type=int, default=90)
    ap.add_argument("--reservas")
    ap.add_argument("--coleta")
    ap.add_argument("--regras", nargs="*", default=[], help="Q7=overrides.json ... (saída de get_listing_date_overrides)")
    ap.add_argument("--saida", default="plano_topo.json")
    ap.add_argument("recalculos", nargs="+", help="Q7=arquivo Double=arquivo ...")
    a = ap.parse_intermixed_args(argv)
    cfg = carregar_quartos()
    hoje = dt.date.fromisoformat(a.hoje)
    cal = {k: ler_recalculo(v) for k, v in (x.split("=", 1) for x in a.recalculos)}
    if a.modo == "plano":
        hidro = json.load(open(ARQ_HIDRO, encoding="utf-8"))
        coleta = resultado_workflow(ler_json(a.coleta)) if a.coleta else None
        res = []
        if a.reservas:
            d = ler_json(a.reservas)
            res = ((d.get("data") or {}).get("data")) or d.get("data") or []
        over = overbooking(res, cfg)
        itens = plano(cfg, cal, hoje, a.dias, periodos_mercado(hidro, coleta), over, ler_regras(a.regras))
        for (curto, noite), n in sorted(over.items()):
            print(f"OVERBOOKING {curto} {noite}: {n} reservas para {cfg['por_curto'][curto]['unidades']} unidades")
    else:
        itens = restaurar(cfg, cal, ler_regras(a.regras), hoje)
    json.dump({"hoje": hoje.isoformat(), "itens": itens}, open(a.saida, "w"), ensure_ascii=False, indent=1)
    print(f"{len(itens)} itens em {a.saida}")
    for it in itens[:15]:
        print(f"  {it['quarto']} {it['data']}: R$ {it['preco_antes']:.0f} -> R$ {it['min_price']:.0f} | {it['motivo'][:90]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

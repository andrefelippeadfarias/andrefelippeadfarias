"""Busca diária de preços dos concorrentes do perfil (dono, 20/10): hidro no quarto, vista, café, até 3 km do Capivari.

Uso:
  python3 mercado_diario.py planejar --hoje AAAA-MM-DD [--saida pedidos.json]
  python3 mercado_diario.py consolidar --hoje AAAA-MM-DD --respostas respostas.json [--captado ISO8601]

planejar: escolhe as estadias do dia (próximas 3 sextas a domingo, a próxima quarta de 1 noite e cada feriado a até
60 dias) e divide o cadastro `pricelabs/pesquisa-booking/concorrentes.json` em lotes de até 10 nomes para o conector
oficial da Booking (accommodations_search com hotel_names, meal_plan "breakfast_included", 2 adultos, 1 quarto, BRL).
Os pedidos vão para um agente (texto em references/mercado-diario.md); nada de navegador.

consolidar: lê as respostas do agente ({"estadias": [{"checkin", "checkout", "hoteis": [{"slug", "id", "preco_total"}]}]}),
grava as capturas no formato da skill booking-hotel-research (rodada nova em pricelabs/pesquisa-booking/), calcula por
estadia a mediana e os quartis do preço por noite dos concorrentes (menor quarto com café; vezes `r_hidro` do hotel
quando a calibração semanal pela escada ou pelo PDF do dono já mediu quanto o quarto com hidro custa a mais) e o
percentil do Recanto e da Villa na mesma vitrine. Acrescenta o dia em dados/mercado_diario.json (histórico) e
atualiza `periodos` de dados/concorrentes_hidro_vista.json, que o concorrencia_hidro_vista.py usa para o plano.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import json
import os
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from comum import carregar_quartos, periodo_feriado  # noqa: E402

SKILL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = os.path.dirname(os.path.dirname(os.path.dirname(SKILL)))
CADASTRO = os.path.join(REPO, "pricelabs", "pesquisa-booking", "concorrentes.json")
HIST = os.path.join(SKILL, "dados", "mercado_diario.json")
REF = os.path.join(SKILL, "dados", "concorrentes_hidro_vista.json")
NOSSOS = {"pousada-vista-panoramica-campos-do-jordao": "Recanto", "villa-dolce-amore-boutique-panoramica": "Villa"}


def estadias(hoje: dt.date, cfg) -> list:
    out, d = [], hoje + dt.timedelta(days=1)
    sextas = 0
    while sextas < 3:
        if d.weekday() == 4 and not any(periodo_feriado(cfg, d + dt.timedelta(days=i)) for i in range(2)):
            out.append((d, d + dt.timedelta(days=2), "fim de semana"))
            sextas += 1
        d += dt.timedelta(days=1)
    d = hoje + dt.timedelta(days=1)
    while d.weekday() != 2:
        d += dt.timedelta(days=1)
    out.append((d, d + dt.timedelta(days=1), "quarta"))
    vistos = set()
    for f in cfg.get("feriados", []):
        ini = dt.date.fromisoformat(f.get("inicio") or f.get("de"))
        fim = dt.date.fromisoformat(f.get("fim") or f.get("ate"))
        if hoje < ini <= hoje + dt.timedelta(days=60) and f["nome"] not in vistos:
            out.append((ini, fim + dt.timedelta(days=1), f["nome"]))
            vistos.add(f["nome"])
    unicos = {(a, b): t for a, b, t in out}
    return sorted(((a, b, t) for (a, b), t in unicos.items()), key=lambda x: x[0])


def planejar(hoje: dt.date, saida: str) -> dict:
    cad = json.load(open(CADASTRO))
    nomes = [h["nome"] for h in cad["hoteis"]] + ["Pousada Recanto dos Moinhos", "Villa Dolce Amore"]
    lotes = [nomes[i:i + 10] for i in range(0, len(nomes), 10)]
    est = [{"checkin": a.isoformat(), "checkout": b.isoformat(), "tipo": t} for a, b, t in estadias(hoje, carregar_quartos())]
    pedido = {"hoje": hoje.isoformat(), "estadias": est, "lotes": lotes,
              "parametros": {"destination": "Campos do Jordão, Brazil", "number_of_adults": 2, "number_of_rooms": 1,
                             "currency": "BRL", "meal_plan": "breakfast_included", "user_country_code": "br",
                             "user_locale": "pt-br"},
              "slugs": {h["slug"]: h["nome"] for h in cad["hoteis"]}}
    json.dump(pedido, open(saida, "w"), ensure_ascii=False, indent=1)
    return pedido


def _pct(v, x):
    return round(100 * sum(1 for y in v if y < x) / len(v)) if v else None


def consolidar(hoje: dt.date, respostas: str, captado: str) -> dict:
    cad = {h["slug"]: h for h in json.load(open(CADASTRO))["hoteis"]}
    resp = json.load(open(respostas))
    pasta = os.path.join(REPO, "pricelabs", "pesquisa-booking", f"diaria-{hoje.isoformat()}")
    os.makedirs(pasta, exist_ok=True)
    caps = os.path.join(pasta, "captures.csv")
    colunas = ["run_id", "hotel_key", "hotel_name", "booking_url", "checkin", "checkout", "nights", "adults", "rooms",
               "currency", "status", "captured_at", "room_name", "total_displayed", "price_basis", "breakfast",
               "source_type", "criteria_status", "notes"]
    resumo = []
    with open(caps, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=colunas)
        w.writeheader()
        for e in resp["estadias"]:
            a, b = dt.date.fromisoformat(e["checkin"]), dt.date.fromisoformat(e["checkout"])
            n = (b - a).days
            comp, nossos = [], {}
            for h in e["hoteis"]:
                slug, total = h.get("slug"), h.get("preco_total")
                ref = cad.get(slug)
                st_ = "available" if total else "unverified"
                w.writerow({"run_id": os.path.basename(pasta), "hotel_key": slug, "hotel_name": (ref or {}).get("nome", slug),
                            "booking_url": f"https://www.booking.com/hotel/br/{slug}.html", "checkin": e["checkin"],
                            "checkout": e["checkout"], "nights": n, "adults": 2, "rooms": 1, "currency": "BRL",
                            "status": st_, "captured_at": captado, "room_name": "menor quarto com café (conector)",
                            "total_displayed": total or "", "price_basis": "estadia", "breakfast": "incluído (meal_plan)",
                            "source_type": "conector_booking_oficial",
                            "criteria_status": "hidro no quarto: unverified (menor quarto); café: confirmed",
                            "notes": "" if total else "sem preço na resposta"})
                if not total:
                    continue
                noite = total / n
                if slug in NOSSOS:
                    nossos[NOSSOS[slug]] = round(noite, 2)
                elif ref:
                    comp.append(noite * float(ref.get("r_hidro") or 1.0))
            comp.sort()
            q = st.quantiles(comp, n=4) if len(comp) >= 4 else [None, None, None]
            r = {"checkin": e["checkin"], "checkout": e["checkout"], "noites": n, "n": len(comp),
                 "mediana": round(st.median(comp), 2) if comp else None, "p25": q[0] and round(q[0], 2),
                 "p75": q[2] and round(q[2], 2)}
            for k, x in nossos.items():
                r[k.lower()] = {"noite": x, "percentil": _pct(comp, x),
                                "vs_mediana": round(x / r["mediana"] - 1, 3) if r["mediana"] else None}
            resumo.append(r)
    hist = json.load(open(HIST)) if os.path.exists(HIST) else {"_doc": "Histórico da busca diária (mercado_diario.py)", "dias": []}
    hist["dias"] = [d for d in hist["dias"] if d["hoje"] != hoje.isoformat()] + [{"hoje": hoje.isoformat(), "captado": captado, "estadias": resumo}]
    json.dump(hist, open(HIST, "w"), ensure_ascii=False, indent=1)
    # referência do plano: mediana do dia e o preço do Recanto na mesma vitrine
    ref = json.load(open(REF))
    ref["periodos"] = [{"rotulo": f"{r['checkin']}→{r['checkout']}", "datas": [(dt.date.fromisoformat(r["checkin"]) + dt.timedelta(days=i)).isoformat() for i in range(r["noites"])],
                        "mediana_a": r["mediana"], "recanto": (r.get("recanto") or {}).get("noite"), "n": r["n"],
                        "fonte": f"busca diária {hoje.isoformat()} (conector, menor quarto com café × r_hidro)"}
                       for r in resumo if r["mediana"]]
    ref["atualizado_em"] = hoje.isoformat()
    json.dump(ref, open(REF, "w"), ensure_ascii=False, indent=1)
    md = ["| Estadia | Concorrentes | Mediana/noite | 25%–75% | Recanto (percentil) | Villa (percentil) |", "|---|---|---|---|---|---|"]
    for r in resumo:
        rc, vl = r.get("recanto") or {}, r.get("villa") or {}
        md.append(f"| {r['checkin']} ({r['noites']}n) | {r['n']} | R$ {r['mediana'] or 0:,.0f} | R$ {r['p25'] or 0:,.0f}–{r['p75'] or 0:,.0f} | "
                  f"R$ {rc.get('noite', 0):,.0f} ({rc.get('percentil')}) | R$ {vl.get('noite', 0):,.0f} ({vl.get('percentil')}) |")
    open(os.path.join(pasta, "resumo.md"), "w").write(f"# Busca diária {hoje.isoformat()}\n\n" + "\n".join(md).replace(",", ".") + "\n")
    return {"pasta": pasta, "estadias": resumo}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("planejar"); p.add_argument("--hoje", required=True); p.add_argument("--saida", default="pedidos_mercado.json")
    c = sub.add_parser("consolidar"); c.add_argument("--hoje", required=True); c.add_argument("--respostas", required=True)
    c.add_argument("--captado", default=dt.datetime.now(dt.timezone.utc).isoformat(timespec="minutes"))
    a = ap.parse_args(argv)
    hoje = dt.date.fromisoformat(a.hoje)
    if a.cmd == "planejar":
        r = planejar(hoje, a.saida)
        print(f"{len(r['estadias'])} estadias × {len(r['lotes'])} lotes = {len(r['estadias']) * len(r['lotes'])} buscas → {a.saida}")
    else:
        r = consolidar(hoje, a.respostas, a.captado)
        print(json.dumps(r, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

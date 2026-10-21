"""Desempenho do mês pelas reservas do Beds24 (dono, 21/10): "Avalie todas as reservas que tivemos esse mês, do início
do mês até agora. Valores etc. Para fazermos o acompanhamento da performance dos hotéis".

Uso:
  python3 desempenho_mes.py --mes 2026-10 --ate 2026-10-21T14:00:00Z [--arquivo reservas.json] [--md saida.md]

Duas leituras, sem dados de hóspedes:
  1. Reservas FEITAS no mês (bookingTime do dia 1, 00:00 BRT, até --ate): quantas, quantas já canceladas, noites,
     receita de hospedagem (itens de fatura de diária; sem itens, o campo price), diária média, antecedência e
     estadia média; por pousada, canal, quarto, semana em que entraram e mês da estadia.
  2. Estadias NO mês (noites do dia 1 ao último dia): realizado até ontem + já vendido até o fim do mês, contra o
     mínimo do dono (dados/metas_faturamento.json → por_pousada), com ocupação, diária média e RevPAR.
Mais os cancelamentos feitos no mês (inclusive de reservas antigas) e a receita que saiu com eles; sem cancelTime
(35 de 80 em 21/10), a data do cancelamento é a da última alteração.
Valores brutos: o Beds24 não traz a comissão das OTAs (campo commission vem 0).
"""

from __future__ import annotations

import argparse
import calendar
import datetime as dt
import json
import os
import sys
from collections import defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import beds24  # noqa: E402

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
POUS = {"350362": "Recanto", "350364": "Villa"}
OCUPA = {"confirmed", "new"}


def canal(b):
    c = (b.get("channel") or "direct").lower()
    if c == "direct":
        ref = b.get("referer") or ""
        return "direto (API/site)" if ref == "API" else "direto (lançado à mão)"
    return {"booking": "Booking", "airbnb": "Airbnb", "expedia": "Expedia"}.get(c, c)


def linha(b):
    chegada, n, total = beds24.receita_por_noite(b)
    q = int(b.get("roomQty") or 1)
    bt = dt.datetime.fromisoformat((b.get("bookingTime") or "1970-01-01T00:00:00Z").replace("Z", "+00:00"))
    return {"pousada": POUS.get(str(b.get("propertyId")), "?"), "quarto": beds24.QUARTOS.get(str(b.get("roomId")), ("?",))[0],
            "status": (b.get("status") or "").lower(), "canal": canal(b), "chegada": chegada, "noites": n, "un": q,
            "receita": total, "feita": bt, "antecedencia": (chegada - (bt - dt.timedelta(hours=3)).date()).days,
            "cancelada_em": b.get("cancelTime") or (b.get("modifiedTime") if (b.get("status") or "").lower() == "cancelled" else "") or ""}


def agrega(rs):
    ativas = [r for r in rs if r["status"] in OCUPA]
    un = sum(r["noites"] * r["un"] for r in ativas)
    rec = sum(r["receita"] for r in ativas)
    return {"reservas": len(rs), "canceladas": sum(1 for r in rs if r["status"] == "cancelled"), "ativas": len(ativas),
            "diarias": un, "receita": round(rec, 2), "adr": round(rec / un, 2) if un else None,
            "antecedencia": round(sum(r["antecedencia"] for r in ativas) / len(ativas), 1) if ativas else None,
            "estadia": round(sum(r["noites"] for r in ativas) / len(ativas), 2) if ativas else None,
            "receita_cancelada": round(sum(r["receita"] for r in rs if r["status"] == "cancelled"), 2),
            "canceladas_sem_valor": sum(1 for r in rs if r["status"] == "cancelled" and not r["receita"])}


def calcular(lista, mes, ate):
    a, m = (int(x) for x in mes.split("-"))
    ini = dt.date(a, m, 1)
    fim = dt.date(a, m, calendar.monthrange(a, m)[1]) + dt.timedelta(days=1)
    ini_utc = dt.datetime(a, m, 1, 3, tzinfo=dt.timezone.utc)   # 00:00 BRT
    hoje = (ate - dt.timedelta(hours=3)).date()
    L = [linha(b) for b in lista if (b.get("status") or "").lower() in OCUPA | {"cancelled"}]
    feitas = [r for r in L if ini_utc <= r["feita"] <= ate]
    out = {"mes": mes, "ate": ate.isoformat(), "feitas": {}, "estadias": {}, "cancelamentos_no_mes": {}}
    for p in ("Recanto", "Villa", None):
        rs = [r for r in feitas if p is None or r["pousada"] == p]
        chave = p or "Total"
        g = {"geral": agrega(rs), "canal": {}, "quarto": {}, "semana": {}, "mes_estadia": {}}
        for campo, fn in (("canal", lambda r: r["canal"]), ("quarto", lambda r: r["quarto"]),
                          ("semana", lambda r: f"{((r['feita'] - dt.timedelta(hours=3)).day - 1) // 7 + 1}ª semana"),
                          ("mes_estadia", lambda r: r["chegada"].strftime("%Y-%m"))):
            grupos = defaultdict(list)
            for r in rs:
                grupos[fn(r)].append(r)
            g[campo] = {k: agrega(v) for k, v in sorted(grupos.items())}
        out["feitas"][chave] = g
        canc = [r for r in L if r["status"] == "cancelled" and r["cancelada_em"] >= ini_utc.isoformat()[:19]
                and r["cancelada_em"] <= ate.isoformat()[:19] and (p is None or r["pousada"] == p)]
        out["cancelamentos_no_mes"][chave] = {"quantidade": len(canc), "receita": round(sum(r["receita"] for r in canc), 2),
                                              "de_reservas_feitas_antes_do_mes": sum(1 for r in canc if r["feita"] < ini_utc)}
    metas = json.load(open(os.path.join(RAIZ, "dados", "metas_faturamento.json"))).get("por_pousada", {}).get(mes, {})
    dias = (fim - ini).days
    for p, prop in (("Recanto", "350362"), ("Villa", "350364")):
        cap = beds24.CAPACIDADE[prop]
        real = {"un": 0, "rec": 0.0}
        otb = {"un": 0, "rec": 0.0}
        for r in L:
            if r["pousada"] != p or r["status"] not in OCUPA:
                continue
            for k in range(r["noites"]):
                d = r["chegada"] + dt.timedelta(days=k)
                if ini <= d < fim:
                    x = real if d < hoje else otb
                    x["un"] += r["un"]
                    x["rec"] += r["receita"] / r["noites"]
        dias_passados = max((min(hoje, fim) - ini).days, 0)
        tot_un, tot_rec = real["un"] + otb["un"], real["rec"] + otb["rec"]
        meta = (metas.get(p.lower()) or {}).get("minimo_dono")
        out["estadias"][p] = {
            "realizado": {"dias": dias_passados, "diarias": real["un"], "receita": round(real["rec"], 2),
                          "ocupacao": round(real["un"] / (cap * dias_passados), 3) if dias_passados else None,
                          "adr": round(real["rec"] / real["un"], 2) if real["un"] else None,
                          "revpar": round(real["rec"] / (cap * dias_passados), 2) if dias_passados else None},
            "vendido_ate_o_fim": {"dias": dias - dias_passados, "diarias": otb["un"], "receita": round(otb["rec"], 2),
                                  "ocupacao": round(otb["un"] / (cap * (dias - dias_passados)), 3) if dias > dias_passados else None},
            "mes": {"diarias": tot_un, "receita": round(tot_rec, 2), "ocupacao": round(tot_un / (cap * dias), 3),
                    "adr": round(tot_rec / tot_un, 2) if tot_un else None, "minimo_dono": meta,
                    "falta_para_o_minimo": round(meta - tot_rec, 2) if meta else None,
                    "diarias_livres": cap * (dias - dias_passados) - otb["un"],
                    "diaria_necessaria_nas_livres": round((meta - tot_rec) / (cap * (dias - dias_passados) - otb["un"]), 2)
                    if meta and cap * (dias - dias_passados) - otb["un"] > 0 and meta > tot_rec else None}}
    return out


def brl(v):
    return "—" if v is None else "R$ " + f"{v:,.0f}".replace(",", ".")


def pct(v):
    return "—" if v is None else f"{100 * v:.0f}%".replace(".", ",")


def markdown(r):
    t = [f"# Desempenho de {r['mes']} (reservas do Beds24 até {r['ate'][:16]} UTC)", "",
         "## Reservas feitas no mês", "",
         "| | Reservas | Canceladas | Ativas | Diárias | Receita | Diária média | Antecedência | Estadia |",
         "|---|---|---|---|---|---|---|---|---|"]
    for k, g in r["feitas"].items():
        x = g["geral"]
        t.append(f"| {k} | {x['reservas']} | {x['canceladas']} ({pct(x['canceladas'] / x['reservas'] if x['reservas'] else None)}) | "
                 f"{x['ativas']} | {x['diarias']} | {brl(x['receita'])} | {brl(x['adr'])} | {x['antecedencia']} dias | {x['estadia']} noites |")
    for campo, titulo in (("canal", "Por canal"), ("quarto", "Por quarto"), ("semana", "Por semana em que entraram"),
                          ("mes_estadia", "Por mês da estadia")):
        t += ["", f"### {titulo}", "", "| Pousada | " + titulo.split()[-1].capitalize() + " | Ativas | Canceladas | Diárias | Receita | Diária média |",
              "|---|---|---|---|---|---|---|"]
        for p in ("Recanto", "Villa"):
            for k, x in r["feitas"][p][campo].items():
                t.append(f"| {p} | {k} | {x['ativas']} | {x['canceladas']} | {x['diarias']} | {brl(x['receita'])} | {brl(x['adr'])} |")
    t += ["", "## Cancelamentos feitos no mês", "", "| | Quantidade | Receita que saiu | De reservas feitas antes do mês |", "|---|---|---|---|"]
    for k, x in r["cancelamentos_no_mes"].items():
        t.append(f"| {k} | {x['quantidade']} | {brl(x['receita'])} | {x['de_reservas_feitas_antes_do_mes']} |")
    t += ["", "## Estadias no mês contra o mínimo do dono", "",
          "| Pousada | Realizado (até ontem) | Ocupação | Diária média | RevPAR | Já vendido até o fim | Mês (realizado + vendido) | Mínimo do dono | Falta | Diária necessária nas livres |",
          "|---|---|---|---|---|---|---|---|---|---|"]
    for p, x in r["estadias"].items():
        a, b, m = x["realizado"], x["vendido_ate_o_fim"], x["mes"]
        t.append(f"| {p} | {brl(a['receita'])} ({a['diarias']} diárias) | {pct(a['ocupacao'])} | {brl(a['adr'])} | {brl(a['revpar'])} | "
                 f"{brl(b['receita'])} ({pct(b['ocupacao'])}) | {brl(m['receita'])} | {brl(m['minimo_dono'])} | {brl(m['falta_para_o_minimo'])} | "
                 f"{brl(m['diaria_necessaria_nas_livres'])} em {m['diarias_livres']} diárias |")
    t += ["", "Valores brutos de hospedagem (itens de fatura do Beds24), antes da comissão das OTAs; ativas = confirmadas + novas."]
    return "\n".join(t) + "\n"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--mes", required=True)
    ap.add_argument("--ate", default=dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"))
    ap.add_argument("--arquivo", help="lista de reservas já baixada do /bookings (com invoiceItems)")
    ap.add_argument("--md")
    ap.add_argument("--json")
    a = ap.parse_args(argv)
    ate = dt.datetime.fromisoformat(a.ate.replace("Z", "+00:00"))
    if a.arquivo:
        lista = json.load(open(a.arquivo))
    else:
        ano, m = (int(x) for x in a.mes.split("-"))
        lista = list(beds24.paginas("/bookings", {"propertyId": list(beds24.PROPRIEDADES), "departureFrom": f"{ano}-{m:02d}-01",
                                                  "arrivalTo": f"{ano + 1}-12-31", "includeInvoiceItems": "true",
                                                  "status": ["confirmed", "new", "cancelled"]}, beds24.token_de_acesso()))
    r = calcular(lista, a.mes, ate)
    md = markdown(r)
    if a.md:
        open(a.md, "w").write(md)
    if a.json:
        json.dump(r, open(a.json, "w"), ensure_ascii=False, indent=1, default=str)
    print(md)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

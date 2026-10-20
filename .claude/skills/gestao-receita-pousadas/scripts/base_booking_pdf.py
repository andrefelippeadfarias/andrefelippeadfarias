"""Lê o PDF de uma busca da Booking salvo pelo dono (filtros em dados/base_booking_dono.json) e extrai cada hotel.

Uso:
  python3 -I base_booking_pdf.py ARQUIVO.pdf --noites N [--json saida.json]

Precisa de pymupdf (pip install pymupdf). Para cada card: nome, nota, quarto (o mais barato que atende aos filtros,
com hidro no quarto), preço final da estadia (+ impostos quando vêm à parte), preço riscado e preço por noite.
Separa Recanto e Villa dos concorrentes e imprime mediana, quartis, mediana por faixa de nota e o percentil dos
nossos. O preço é a vitrine de quem salvou o PDF (logado como Genius 3 no caso do dono): compare só dentro do PDF.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import re
import statistics as st
import unicodedata
from datetime import date

NOME, FINAL, RISCADO, NOTA = (10.0, 27876), (10.0, 1710618), (6.0, 13898014), (8.0, 11250603)
NOSSOS = ("Pousada Recanto dos Moinhos", "Villa Dolce Amore")


def valor(t: str):
    t = re.sub(r"[^\d,]", "", t).replace(",", ".")
    return float(t) if t else None


def spans(pdf: str) -> list:
    import pymupdf
    out = []
    for pi, p in enumerate(pymupdf.open(pdf)):
        for b in p.get_text("dict")["blocks"]:
            for ln in b.get("lines", []):
                for s in ln["spans"]:
                    t = s["text"].strip()
                    if t:
                        out.append((pi + 1, round(s["bbox"][1]), round(s["bbox"][0]), round(s["size"], 1),
                                    s["color"], s["flags"], t))
    return out


def cards(rows: list, noites: int) -> list:
    out = []
    for pg in sorted({r[0] for r in rows}):
        R = [r for r in rows if r[0] == pg and r[2] > 250]
        tops = []
        for n in sorted((r for r in R if (r[3], r[4]) == NOME), key=lambda r: r[1]):
            if tops and n[1] - tops[-1][0] < 20:
                tops[-1][1] += " " + n[6]
            else:
                tops.append([n[1], n[6]])
        for i, (y, nome) in enumerate(tops):
            y2 = tops[i + 1][0] if i + 1 < len(tops) else 10 ** 6
            C = [r for r in R if y - 5 <= r[1] < y2 - 5]
            fin = [r for r in C if (r[3], r[4]) == FINAL and r[6].startswith("R$")]
            stk = [r for r in C if (r[3], r[4]) == RISCADO and r[6].startswith("R$")]
            tax = [r for r in C if r[6].startswith("+") and "impostos" in r[6]]
            nota = [r for r in C if (r[3], r[4]) == NOTA and re.match(r"^\d+,\d$", r[6])]
            quarto = " ".join(r[6] for r in sorted(C, key=lambda r: r[1])
                              if r[3] == 6.0 and r[4] == 1710618 and r[5] == 16 and r[2] < 330
                              and "Parcelamento" not in r[6])
            out.append({"pag": pg, "nome": nome, "quarto": quarto,
                        "final": valor(fin[0][6]) if fin else None, "riscado": valor(stk[0][6]) if stk else None,
                        "taxas": valor(tax[0][6]) if tax else 0.0, "nota": valor(nota[0][6]) if nota else None})
    # card partido entre páginas ou nome em duas linhas: um "card" sem preço seguido de outro sem nota
    limpo = []
    for c in out:
        if limpo and limpo[-1]["final"] is None and c["final"] is not None and c["nota"] is None:
            limpo[-1].update({k: c[k] for k in ("quarto", "final", "riscado", "taxas")})
            limpo[-1]["nome"] += " " + c["nome"]
        else:
            limpo.append(c)
    for c in limpo:
        if c["final"] is not None:
            c["noite"] = round((c["final"] + c["taxas"]) / noites, 2)
    return [c for c in limpo if c.get("noite")]


def resumo(lista: list) -> dict:
    nossos = [c for c in lista if c["nome"].startswith(NOSSOS)]
    comp = [c for c in lista if c not in nossos]
    v = sorted(c["noite"] for c in comp)
    q = st.quantiles(v, n=4) if len(v) >= 4 else [None, None, None]
    faixa = lambda a, b: [c["noite"] for c in comp if c["nota"] is not None and a <= c["nota"] < b]
    r = {"n": len(v), "mediana": st.median(v) if v else None, "p25": q[0], "p75": q[2],
         "mediana_nota_9": st.median(faixa(9, 11)) if faixa(9, 11) else None,
         "mediana_nota_8": st.median(faixa(8, 9)) if faixa(8, 9) else None,
         "desconto_mediano_concorrentes": st.median([1 - (c["final"] + c["taxas"]) / (c["riscado"] + c["taxas"])
                                                     for c in comp if c["riscado"]]) if comp else None, "nossos": {}}
    for c in nossos:
        r["nossos"][c["nome"].split(" -")[0]] = {
            "noite": c["noite"], "quarto": c["quarto"], "nota": c["nota"],
            "percentil": round(100 * sum(1 for x in v if x < c["noite"]) / len(v)) if v else None,
            "desconto": round(1 - c["final"] / c["riscado"], 3) if c["riscado"] else None}
    return r


def _norm(t: str) -> str:
    t = unicodedata.normalize("NFKD", t).encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9]+", " ", t).strip()


def para_rodada(lista: list, plano: str, checkin: str, checkout: str, captado: str, evidencia: str) -> int:
    """Grava as capturas do PDF no formato da skill booking-hotel-research (captures.csv ao lado do plan.json).
    Casa cada hotel do plano pelo nome; os filtros da busca do dono (hidro no quarto, café incluído, <3 km, nota 8+)
    confirmam os critérios. Hotel do plano ausente no PDF fica sem linha (segue pending)."""
    p = json.load(open(plano))
    noites = (date.fromisoformat(checkout) - date.fromisoformat(checkin)).days
    pdf = {_norm(c["nome"]): c for c in lista}
    destino = os.path.join(os.path.dirname(plano), "captures.csv")
    colunas = open(os.path.join(os.path.dirname(plano), "capture-template.csv"), encoding="utf-8-sig").readline().strip().split(",")
    novo = not os.path.exists(destino)
    n = 0
    with open(destino, "a", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=colunas)
        if novo:
            w.writeheader()
        for j in p["jobs"]:
            if j["checkin"] != checkin or j["checkout"] != checkout:
                continue
            alvo = _norm(re.sub(r"\(.*?\)", "", j["hotel_name"]))
            c = next((v for k, v in pdf.items() if k.startswith(alvo) or alvo.startswith(k)), None)
            if not c:
                continue
            total = round(c["noite"] * noites, 2)
            w.writerow({"job_id": j["job_id"], "hotel_key": j["hotel_key"], "hotel_name": j["hotel_name"],
                        "booking_url": j["booking_url"], "checkin": checkin, "checkout": checkout, "nights": noites,
                        "adults": 2, "rooms": 1, "children_ages": "", "currency": "BRL", "status": "available",
                        "captured_at": captado, "room_name": c["quarto"], "total_displayed": total,
                        "total_all_in": total, "tax_status": "partial" if c["taxas"] else "unknown",
                        "breakfast": "incluído (filtro da busca)", "price_raw": f"R$ {c['final']:.0f}" +
                        (f" + R$ {c['taxas']:.0f} impostos" if c["taxas"] else ""), "price_basis": "estadia",
                        "discount_eligibility": "vitrine Genius 3 (dono logado)", "source_type": "pdf_dono",
                        "criteria_status": "hidro no quarto e café: confirmed (filtros roomfacility=20, mealplan=1)",
                        "evidence_ref": evidencia, "notes": f"riscado R$ {c['riscado']}; nota {c['nota']}",
                        "run_id": os.path.basename(os.path.dirname(plano))})
            n += 1
    return n


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("pdf")
    ap.add_argument("--noites", type=int, required=True)
    ap.add_argument("--json")
    ap.add_argument("--rodada", help="plan.json da skill booking-hotel-research para gravar as capturas")
    ap.add_argument("--checkin")
    ap.add_argument("--checkout")
    ap.add_argument("--captado", help="horário da captura, ISO 8601 com fuso")
    a = ap.parse_args(argv)
    lista = cards(spans(a.pdf), a.noites)
    r = resumo(lista)
    print(json.dumps(r, ensure_ascii=False, indent=1))
    if a.json:
        json.dump({"resumo": r, "hoteis": lista}, open(a.json, "w"), ensure_ascii=False, indent=1)
    if a.rodada:
        n = para_rodada(lista, a.rodada, a.checkin, a.checkout, a.captado, os.path.basename(a.pdf))
        print(f"{n} capturas gravadas em {os.path.join(os.path.dirname(a.rodada), 'captures.csv')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

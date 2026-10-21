"""Dados do painel semanal de desempenho (dono, 21/10: "relatório semanal para gerar todas as segundas-feiras").

Uso:
  python3 painel_semanal.py --ate 2026-10-26T10:00:00Z --saida <pasta> [--arquivo reservas.json]

Gera, sem dados de hóspedes, os 5 arquivos JSON (linhas) que o painel "Desempenho Semanal das Pousadas" lê:
  semanas.json        reservas FEITAS por semana (segunda a domingo, BRT) e pousada, desde 07/09/2026 (a semana de
                      31/08 teve cancelamentos em massa no Beds24 e fica de fora); `completa` marca a semana fechada
  estadias.json       ocupação, receita, diária média e RevPAR por semana de ESTADIA (8 semanas para trás e 8 para frente)
  metas.json          mês corrente e os 2 seguintes por pousada: realizado, vendido, total e o mínimo do dono
  canais.json         reservas feitas nos últimos 28 dias por pousada e canal
  quartos.json        reservas feitas nos últimos 28 dias por pousada e quarto
Depois, a rotina de segunda sobe cada arquivo para o painel e atualiza o `url` e o `updated` de cada fonte
(references/ferramentas.md, "Painel semanal").
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys
from collections import defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import beds24  # noqa: E402
import desempenho_mes as dm  # noqa: E402

INICIO = dt.date(2026, 9, 7)
CAP = {"Recanto": beds24.CAPACIDADE["350362"], "Villa": beds24.CAPACIDADE["350364"]}


def _brt(t: dt.datetime) -> dt.date:
    return (t - dt.timedelta(hours=3)).date()


def _seg(d: dt.date) -> dt.date:
    return d - dt.timedelta(days=d.weekday())


def _linhas_agregadas(rs, **chave):
    g = dm.agrega(rs)
    return {**chave, "reservas": g["reservas"], "canceladas": g["canceladas"], "ativas": g["ativas"],
            "diarias": g["diarias"], "receita": g["receita"], "diaria_media": g["adr"],
            "antecedencia_dias": g["antecedencia"], "estadia_noites": g["estadia"]}


def montar(lista, ate: dt.datetime) -> dict:
    hoje = _brt(ate)
    L = [dm.linha(b) for b in lista if (b.get("status") or "").lower() in dm.OCUPA | {"cancelled"}]
    seg_atual = _seg(hoje)
    # 1. reservas feitas por semana
    semanas = []
    w = INICIO
    while w <= seg_atual:
        for p in ("Recanto", "Villa"):
            rs = [r for r in L if r["pousada"] == p and w <= _brt(r["feita"]) < w + dt.timedelta(days=7)]
            canc = [r for r in L if r["pousada"] == p and r["status"] == "cancelled" and r["cancelada_em"]
                    and w <= _brt(dt.datetime.fromisoformat(r["cancelada_em"].replace("Z", "+00:00"))) < w + dt.timedelta(days=7)]
            linha = _linhas_agregadas(rs, semana=w.isoformat(), pousada=p, completa=w + dt.timedelta(days=7) <= hoje)
            linha.update({"cancelamentos_na_semana": len(canc),
                          "receita_cancelada_na_semana": round(sum(r["receita"] for r in canc), 2)})
            semanas.append(linha)
        w += dt.timedelta(days=7)
    # 2. estadias por semana de estadia
    estadias = []
    ocup = defaultdict(lambda: [0, 0.0])
    for r in L:
        if r["status"] not in dm.OCUPA:
            continue
        for k in range(r["noites"]):
            d = r["chegada"] + dt.timedelta(days=k)
            x = ocup[(_seg(d), r["pousada"])]
            x[0] += r["un"]
            x[1] += r["receita"] / r["noites"]
    for i in range(-8, 9):
        w = seg_atual + dt.timedelta(days=7 * i)
        if w < INICIO:
            continue
        for p in ("Recanto", "Villa"):
            un, rec = ocup.get((w, p), (0, 0.0))
            cap = CAP[p] * 7
            estadias.append({"semana": w.isoformat(), "pousada": p, "momento": "passada" if w + dt.timedelta(days=7) <= hoje
                             else ("atual" if w == seg_atual else "futura"), "diarias": un, "capacidade": cap,
                             "ocupacao": round(un / cap, 4), "receita": round(rec, 2),
                             "diaria_media": round(rec / un, 2) if un else None, "revpar": round(rec / cap, 2)})
    # 3. metas do mês corrente e dos 2 seguintes
    metas = []
    m = dt.date(hoje.year, hoje.month, 1)
    for _ in range(3):
        r = dm.calcular(lista, m.strftime("%Y-%m"), ate)
        for p, x in r["estadias"].items():
            metas.append({"mes": m.strftime("%Y-%m"), "pousada": p, "realizado": x["realizado"]["receita"],
                          "vendido_a_frente": x["vendido_ate_o_fim"]["receita"], "total": x["mes"]["receita"],
                          "minimo_dono": x["mes"]["minimo_dono"], "falta": x["mes"]["falta_para_o_minimo"],
                          "ocupacao_mes": x["mes"]["ocupacao"], "diaria_media": x["mes"]["adr"],
                          "diarias_livres": x["mes"]["diarias_livres"],
                          "diaria_necessaria": x["mes"]["diaria_necessaria_nas_livres"],
                          "atingido": round(x["mes"]["receita"] / x["mes"]["minimo_dono"], 4) if x["mes"]["minimo_dono"] else None})
        m = (m + dt.timedelta(days=32)).replace(day=1)
    # 4 e 5. últimos 28 dias por canal e por quarto
    ini = hoje - dt.timedelta(days=28)
    rec = [r for r in L if ini <= _brt(r["feita"]) <= hoje]
    canais, quartos = [], []
    for p in ("Recanto", "Villa"):
        rp = [r for r in rec if r["pousada"] == p]
        total = sum(r["receita"] for r in rp if r["status"] in dm.OCUPA) or 1
        for nome, campo, saida in (("canal", "canal", canais), ("quarto", "quarto", quartos)):
            grupos = defaultdict(list)
            for r in rp:
                grupos[r[campo]].append(r)
            for k, v in sorted(grupos.items()):
                linha = _linhas_agregadas(v, pousada=p, **{nome: k})
                linha["participacao"] = round(linha["receita"] / total, 4)
                saida.append(linha)
    return {"semanas": semanas, "estadias": estadias, "metas": metas, "canais": canais, "quartos": quartos}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--ate", default=dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"))
    ap.add_argument("--saida", required=True)
    ap.add_argument("--arquivo")
    a = ap.parse_args(argv)
    ate = dt.datetime.fromisoformat(a.ate.replace("Z", "+00:00"))
    if a.arquivo:
        lista = json.load(open(a.arquivo))
    else:
        lista = list(beds24.paginas("/bookings", {"propertyId": list(beds24.PROPRIEDADES), "departureFrom": "2026-09-01",
                                                  "arrivalTo": f"{ate.year + 1}-12-31", "includeInvoiceItems": "true",
                                                  "status": ["confirmed", "new", "cancelled"]}, beds24.token_de_acesso()))
    os.makedirs(a.saida, exist_ok=True)
    for nome, linhas in montar(lista, ate).items():
        json.dump(linhas, open(os.path.join(a.saida, f"{nome}.json"), "w"), ensure_ascii=False, indent=1)
        print(f"{nome}.json: {len(linhas)} linhas")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

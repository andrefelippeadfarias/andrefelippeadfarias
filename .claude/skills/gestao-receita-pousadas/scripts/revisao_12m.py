"""Revisão quinzenal dos 12 meses futuros (dono, 21/10: "A cada 15 dias sempre faça a análise dos 12 meses futuros.").

Uso:
  python3 revisao_12m.py devida --hoje AAAA-MM-DD
  python3 revisao_12m.py rodar --hoje AAAA-MM-DD [--ate ISO8601] [--vizinhanca neighbourhood.json] \\
      [--arquivo reservas.json] Q7=<recalculo> Double=<recalculo> ... [--md saida.md]

`devida` responde se a revisão está vencida (15 dias ou mais desde a última em dados/revisao_12m.json) e sai com
código 0 se estiver, 1 se não. `rodar` monta, para cada um dos 12 meses seguintes ao mês corrente (mais o corrente)
e cada pousada:
  - vendido (Beds24, confirmadas + novas), ocupação já vendida, diária média, contra o mínimo do dono e o realista
    (dados/metas_faturamento.json), e o quanto entrou desde a última revisão (pickup de 15 dias);
  - ocupação já vendida do mercado no mesmo mês (PriceLabs, get_neighbourhood_data → occupancy.monthly), para comparar
    o ritmo: nós contra o mercado, com o mesmo horizonte;
  - preço médio enviado pelas suítes no mês (recálculo do PriceLabs) e a variação desde a última revisão;
  - leitura: "à frente", "no ritmo" ou "atrás", pela ocupação vendida contra o mercado.
Grava a fotografia em dados/revisao_12m.json (histórico) e o relatório em Markdown. Sem dados de hóspedes.
"""

from __future__ import annotations

import argparse
import calendar
import datetime as dt
import json
import os
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import beds24  # noqa: E402
import desempenho_mes as dm  # noqa: E402
from comum import carregar_quartos  # noqa: E402

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HIST = os.path.join(RAIZ, "dados", "revisao_12m.json")
METAS = os.path.join(RAIZ, "dados", "metas_faturamento.json")
INTERVALO = 15


def carregar_hist() -> dict:
    if os.path.exists(HIST):
        return json.load(open(HIST, encoding="utf-8"))
    return {"_doc": "Fotografias da revisão quinzenal dos 12 meses (revisao_12m.py). Só totais.", "revisoes": []}


def devida(hoje: dt.date, hist: dict) -> tuple[bool, str]:
    if not hist["revisoes"]:
        return True, "nunca rodou"
    ult = dt.date.fromisoformat(hist["revisoes"][-1]["hoje"])
    dias = (hoje - ult).days
    return dias >= INTERVALO, f"última em {ult} ({dias} dias)"


def meses(hoje: dt.date, n: int = 13) -> list[str]:
    m, out = dt.date(hoje.year, hoje.month, 1), []
    for _ in range(n):
        out.append(m.strftime("%Y-%m"))
        m = (m + dt.timedelta(days=32)).replace(day=1)
    return out


def mercado_mensal(caminho: str | None) -> dict:
    if not caminho:
        return {}
    t = open(caminho, encoding="utf-8").read()
    d = json.loads(t[t.find("{"):])
    d = d.get("data", d)
    d = d.get("data", d)
    m = (d.get("occupancy") or {}).get("monthly") or {}
    out = {}
    for i, per in enumerate(m.get("period") or []):
        out[str(per)[:7]] = {"mercado_vendido": m["market_occupancy"][i], "mercado_ano_passado": m["market_occupancy_ly"][i]}
    return out


def precos_mensais(recalculos: dict) -> dict:
    cfg = carregar_quartos()
    soma: dict = {}
    for quarto, caminho in recalculos.items():
        q = cfg["por_curto"][quarto]
        if not q.get("banheira"):
            continue
        t = open(caminho, encoding="utf-8").read()
        d = json.loads(t[t.find("{"):])
        for r in (d.get("data") or d).get("pricing_array") or []:
            soma.setdefault((q["pousada"], r["date"][:7]), []).append(float(r.get("price") or 0))
    return {k: round(st.mean(v)) for k, v in soma.items() if v}


def rodar(lista, hoje, ate, merc, precos, hist) -> dict:
    metas = json.load(open(METAS, encoding="utf-8")).get("por_pousada", {})
    anteriores = [r for r in hist["revisoes"] if r["hoje"] < hoje.isoformat()]
    ant = {(x["mes"], x["pousada"]): x for x in (anteriores[-1]["linhas"] if anteriores else [])}
    linhas = []
    for mes in meses(hoje):
        r = dm.calcular(lista, mes, ate)
        a, mm = (int(x) for x in mes.split("-"))
        for p, x in r["estadias"].items():
            m = x["mes"]
            alvo = metas.get(mes, {}).get(p.lower(), {})
            mc = merc.get(mes, {})
            ocup = m["ocupacao"]
            leitura = None
            if mc.get("mercado_vendido") is not None and mes != hoje.strftime("%Y-%m"):
                mv = float(mc["mercado_vendido"])
                if mv < 5:
                    leitura = "cedo"            # mercado com menos de 5% vendido: pouco para comparar
                else:
                    razao = 100 * ocup / mv
                    leitura = "à frente" if razao >= 1.2 else ("atrás" if razao <= 0.8 else "no ritmo")
            a0 = ant.get((mes, p), {})
            linhas.append({
                "mes": mes, "pousada": p, "vendido": m["receita"], "ocupacao_vendida": ocup, "diaria_media": m["adr"],
                "minimo_dono": alvo.get("minimo_dono"), "realista": alvo.get("realista"),
                "atingido_minimo": round(m["receita"] / alvo["minimo_dono"], 3) if alvo.get("minimo_dono") else None,
                "pickup_desde_ultima": round(m["receita"] - a0["vendido"], 2) if a0 else None,
                "mercado_vendido": mc.get("mercado_vendido"), "mercado_ano_passado": mc.get("mercado_ano_passado"),
                "leitura": leitura, "preco_medio_suites": precos.get((p, mes)),
                "preco_var_desde_ultima": (round(precos[(p, mes)] / a0["preco_medio_suites"] - 1, 3)
                                           if a0.get("preco_medio_suites") and precos.get((p, mes)) else None)})
    return {"hoje": hoje.isoformat(), "ate": ate.isoformat(), "anterior": anteriores[-1]["hoje"] if anteriores else None,
            "linhas": linhas}


def brl(v):
    return "—" if v is None else "R$ " + f"{v / 1000:,.0f} mil".replace(",", ".")


def pct(v):
    return "—" if v is None else f"{100 * v:.0f}%"


def markdown(rev: dict) -> str:
    t = [f"# Revisão dos 12 meses — {rev['hoje']}", "",
         f"Comparação com a revisão anterior: {rev['anterior'] or 'esta é a primeira (base)'}.", "",
         "| Mês | Pousada | Vendido | Mínimo | Atingido | Entrou em 15 dias | Ocupação vendida | Mercado vendido | Ritmo | Diária média | Preço médio das suítes (variação) |",
         "|---|---|---|---|---|---|---|---|---|---|---|"]
    for x in rev["linhas"]:
        var = f" ({x['preco_var_desde_ultima']:+.0%})" if x.get("preco_var_desde_ultima") is not None else ""
        t.append(f"| {x['mes']} | {x['pousada']} | {brl(x['vendido'])} | {brl(x['minimo_dono'])} | {pct(x['atingido_minimo'])} | "
                 f"{brl(x['pickup_desde_ultima']) if x['pickup_desde_ultima'] is not None else '—'} | {pct(x['ocupacao_vendida'])} | "
                 f"{'—' if x['mercado_vendido'] is None else str(round(x['mercado_vendido'])) + '%'} | {x['leitura'] or '—'} | "
                 f"{'—' if not x['diaria_media'] else 'R$ ' + format(round(x['diaria_media']), ',').replace(',', '.')} | "
                 f"{'—' if not x['preco_medio_suites'] else 'R$ ' + format(x['preco_medio_suites'], ',').replace(',', '.')}{var} |")
    atras = [f"{x['mes']} {x['pousada']}" for x in rev["linhas"] if x["leitura"] == "atrás"]
    t += ["", f"Meses atrás do mercado no ritmo de venda: {', '.join(atras) if atras else 'nenhum'}.",
          "Ritmo = nossa ocupação já vendida ÷ a do mercado (PriceLabs, mesmo horizonte): 1,2 ou mais à frente, 0,8 ou menos atrás; \"cedo\" com o mercado abaixo de 5% vendido.",
          "Valores brutos de hospedagem (Beds24, confirmadas + novas)."]
    return "\n".join(t) + "\n"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("modo", choices=["devida", "rodar"])
    ap.add_argument("--hoje", required=True)
    ap.add_argument("--ate", default=dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"))
    ap.add_argument("--vizinhanca")
    ap.add_argument("--arquivo")
    ap.add_argument("--md")
    ap.add_argument("--nao-gravar", action="store_true", help="não acrescenta a fotografia ao histórico")
    ap.add_argument("recalculos", nargs="*", help="QUARTO=arquivo do refresh_listing_pricing")
    a = ap.parse_intermixed_args(argv)
    hoje = dt.date.fromisoformat(a.hoje)
    hist = carregar_hist()
    if a.modo == "devida":
        ok, txt = devida(hoje, hist)
        print(("VENCIDA: " if ok else "em dia: ") + txt)
        return 0 if ok else 1
    ate = dt.datetime.fromisoformat(a.ate.replace("Z", "+00:00"))
    if a.arquivo:
        lista = json.load(open(a.arquivo))
    else:
        lista = list(beds24.paginas("/bookings", {"propertyId": list(beds24.PROPRIEDADES), "departureFrom": hoje.replace(day=1).isoformat(),
                                                  "arrivalTo": f"{hoje.year + 2}-12-31", "includeInvoiceItems": "true",
                                                  "status": ["confirmed", "new", "cancelled"]}, beds24.token_de_acesso()))
    rec = dict(x.split("=", 1) for x in a.recalculos)
    rev = rodar(lista, hoje, ate, mercado_mensal(a.vizinhanca), precos_mensais(rec), hist)
    md = markdown(rev)
    if a.md:
        os.makedirs(os.path.dirname(os.path.abspath(a.md)), exist_ok=True)
        open(a.md, "w", encoding="utf-8").write(md)
    if not a.nao_gravar:
        hist["revisoes"] = [r for r in hist["revisoes"] if r["hoje"] != rev["hoje"]] + [rev]
        json.dump(hist, open(HIST, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(md)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

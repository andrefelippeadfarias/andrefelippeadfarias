"""Ranking com suítes comparáveis: banheira/hidro no quarto + café incluído (dono, 18/11/2026).

Pedido do dono (literal): "Então, vamos ajustar nossos preços sempre para valores de suítes companheira. Então, você vai
entrar nas pesquisas da Booking, da Expedia e do Airbnb, fazer buscas dos nossos concorrentes para que você possa nos
qualificar e fazer um ranking sempre com suítes companheiras. Ignore todos os outros valores de suítes sem banheira e
café da manhã, pois nós oferecemos banheira e café da manhã em nossos hotéis."

Regra: a referência de mercado é SÓ suíte com banheira/hidro no quarto e café incluído. Um preço sem prova de que é dessa
suíte não entra na régua principal:
  - concorrente: menor quarto com café da busca oficial (conector da Booking) × `r_hidro` MEDIDO do hotel (escada de
    preço ou PDF filtrado do dono; r = 1 quando o menor quarto já é o da banheira). Hotel sem `r_hidro` medido fica na
    régua ampla (× r_hidro_padrao), marcado "estimado", nunca na principal;
  - nós: só as 6 suítes com banheira (`quartos.json` → banheira). A Balcony (sem banheira) fica fora. O preço da Villa
    na busca é o do menor quarto livre (quase sempre a Balcony), por isso a King Spa entra pelo enviado × fator público
    medido no mesmo dia.

Uso:
  python3 ranking_comparaveis.py --hoje AAAA-MM-DD --respostas <busca.json> --inventario <beds24 inv --precos>
      [--fator Recanto=0.62 --fator Villa=0.56] [--publico Recanto:2026-11-22:541.73 ...]
      [--saida-md arquivo.md] [--json arquivo.json] [--ancora-buscas arquivo.json]

--respostas: {"estadias": [{"checkin", "checkout", "hoteis": [{"slug", "id", "nome", "preco_total", "nota", "avaliacoes"}]}]}
--publico: preço público TOTAL medido na Booking para a pousada na estadia (busca por coordenadas do Recanto). Com ele, o
  fator da pousada na estadia = público ÷ enviado do quarto com banheira livre mais barato (o que a Booking mostra).
--ancora-buscas: grava as buscas no formato que `ancora_mercado.py construir --buscas` lê (`percentis_noite`), só com a
  régua principal (comparáveis comprovados).
"""

from __future__ import annotations

import argparse
import json
import os
import re
import statistics as st
import sys
from datetime import date, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from comum import carregar_quartos  # noqa: E402

SKILL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = os.path.dirname(os.path.dirname(os.path.dirname(SKILL)))
CADASTRO = os.path.join(REPO, "pricelabs", "pesquisa-booking", "concorrentes.json")
NOSSOS = {"pousada-recanto-dos-moinhos": "Recanto", "pousada-vista-panoramica-campos-do-jordao": "Recanto",
          "villa-dolce-amore-boutique-panoramica": "Villa"}
NOMES = {"Q7": "Moinhos", "Double": "Panorâmica I", "Afrodite": "Afrodite", "Q2": "Panorâmica II",
         "VK7": "King Spa 7", "VK2": "King Spa 2", "Balcony": "Balcony"}
FATOR_PADRAO = {"Recanto": 0.62, "Villa": 0.56}   # medidos em 19/11 (Booking × enviado); --fator troca
MIN_N_PRINCIPAL = 8


def quantil(v: list[float], p: float) -> float:
    s = sorted(v)
    if not s:
        raise ValueError("lista vazia")
    if len(s) == 1:
        return s[0]
    k = (len(s) - 1) * p
    i = int(k)
    return s[i] + (s[min(i + 1, len(s) - 1)] - s[i]) * (k - i)


def percentil_de(x: float, v: list[float]) -> int | None:
    """Quantos % dos comparáveis estão mais baratos que x (empate conta metade)."""
    if not v:
        return None
    abaixo = sum(1 for y in v if y < x) + 0.5 * sum(1 for y in v if y == x)
    return round(100 * abaixo / len(v))


def noites(ci: date, co: date) -> list[str]:
    return [(ci + timedelta(days=i)).isoformat() for i in range((co - ci).days)]


def medido(ref: dict) -> bool:
    return bool(ref.get("r_hidro")) and "não medido" not in str(ref.get("r_hidro_fonte", ""))


def comparaveis(e: dict, cad: dict, padrao: float) -> tuple[list[dict], list[dict], dict]:
    ci, co = date.fromisoformat(e["checkin"]), date.fromisoformat(e["checkout"])
    n = (co - ci).days
    principal, ampla, nossos = [], [], {}
    vistos = set()
    for h in e.get("hoteis") or []:
        slug, total = h.get("slug"), h.get("preco_total")
        if not total or not slug or slug in vistos:
            continue
        vistos.add(slug)
        noite = float(total) / n
        if slug in NOSSOS:
            nossos.setdefault(NOSSOS[slug], round(noite, 2))
            continue
        ref = cad.get(slug)
        if not ref:   # fora do cadastro (ex.: homônimo de outra cidade): não é concorrente
            continue
        item = {"slug": slug, "nome": ref.get("nome") or h.get("nome") or slug, "nota": h.get("nota") or ref.get("nota"),
                "avaliacoes": h.get("avaliacoes"), "menor_quarto_noite": round(noite, 2), "quarto_hidro": ref.get("quarto_hidro"),
                "classe": ref.get("classe")}
        if medido(ref):
            r = float(ref["r_hidro"])
            m = re.search(r"certeza (\w+)", str(ref.get("r_hidro_fonte", "")))
            principal.append(item | {"r_hidro": r, "suite_noite": round(noite * r, 2), "prova": "medido",
                                     "certeza": m.group(1) if m else "incerto"})
            ampla.append(principal[-1])
        else:
            ampla.append(item | {"r_hidro": padrao, "suite_noite": round(noite * padrao, 2), "prova": "estimado"})
    return principal, ampla, nossos


def faixa(v: list[float]) -> dict | None:
    if not v:
        return None
    return {"n": len(v), "p25": round(quantil(v, .25)), "p50": round(quantil(v, .5)), "p75": round(quantil(v, .75)),
            "p90": round(quantil(v, .9)), "max": round(max(v)), "min": round(min(v))}


def nossas_suites(cfg: dict, inv: dict, ns: list[str], fatores: dict) -> list[dict]:
    out = []
    for q in cfg["quartos"]:
        c = q["curto"]
        dias = inv.get(c) or {}
        linhas = [dias.get(d) for d in ns]
        if any(x is None for x in linhas):
            continue
        livres = min(int(x.get("numAvail") or 0) for x in linhas)
        fechada = any(str(x.get("override", "none")) not in ("none", "noCheckInOrCheckOut", "noCheckIn", "noCheckOut")
                      for x in linhas)
        enviado = st.mean(float(x.get("price1") or 0) for x in linhas)
        f = fatores[q["pousada"]]
        out.append({"quarto": c, "nome": NOMES.get(c, q.get("nome")), "pousada": q["pousada"], "banheira": bool(q.get("banheira")),
                    "livres": max(livres, 0), "fechada": fechada, "enviado_noite": round(enviado),
                    "publico_noite": round(enviado * f), "fator": f})
    return out


def fator_da_estadia(pousada: str, publico_total: float | None, suites: list[dict], n: int, padrao: float) -> tuple[float, str]:
    """Fator público ÷ enviado na estadia: a Booking mostra o quarto livre mais barato; no Recanto todos têm banheira."""
    if not publico_total:
        return padrao, "padrão"
    livres = [s for s in suites if s["pousada"] == pousada and s["livres"] > 0 and not s["fechada"]
              and (s["banheira"] or pousada == "Villa")]
    if not livres:
        return padrao, "padrão (nada livre)"
    mais_barato = min(livres, key=lambda s: s["enviado_noite"])
    if not mais_barato["banheira"]:   # Villa: o menor quarto livre é a Balcony, que tem fator próprio (promoção)
        return padrao, "padrão (menor quarto livre da Villa é a Balcony)"
    f = publico_total / n / mais_barato["enviado_noite"]
    if not 0.35 <= f <= 0.9:
        return padrao, f"padrão (medido {f:.2f} fora da faixa)"
    return round(f, 3), f"medido ({mais_barato['nome']})"


def ranking(hoje: date, resp: dict, inv: dict, fatores_cli: dict, publicos: dict) -> dict:
    cfg = carregar_quartos()
    with open(CADASTRO, encoding="utf-8") as f:
        cadj = json.load(f)
    cad = {h["slug"]: h for h in cadj["hoteis"]}
    padrao = float(cadj.get("r_hidro_padrao") or 1.395)
    estadias = []
    for e in resp["estadias"]:
        ci, co = date.fromisoformat(e["checkin"]), date.fromisoformat(e["checkout"])
        n = (co - ci).days
        ns = noites(ci, co)
        principal, ampla, nossos_busca = comparaveis(e, cad, padrao)
        base = {p: fatores_cli.get(p, FATOR_PADRAO[p]) for p in ("Recanto", "Villa")}
        suites0 = nossas_suites(cfg, inv, ns, base)
        fatores, fonte_f = {}, {}
        for p in ("Recanto", "Villa"):
            pub = publicos.get((p, e["checkin"]))
            if pub is None and p == "Villa" and nossos_busca.get("Villa"):
                pub = nossos_busca["Villa"] * n    # menor quarto livre da Villa (Balcony): mede o fator do dia
            if pub is None and p == "Recanto" and nossos_busca.get("Recanto"):
                pub = nossos_busca["Recanto"] * n
            fatores[p], fonte_f[p] = fator_da_estadia(p, pub, suites0, n, base[p])
        suites = nossas_suites(cfg, inv, ns, fatores)
        vp = [c["suite_noite"] for c in principal]
        va = [c["suite_noite"] for c in ampla]
        fp, fa = faixa(vp), faixa(va)
        ff = faixa([c["suite_noite"] for c in principal if c.get("certeza") in ("alta", "media")])
        nossas = []
        for s in suites:
            if not s["banheira"]:
                continue
            nossas.append(s | {"percentil": percentil_de(s["publico_noite"], vp), "percentil_ampla": percentil_de(s["publico_noite"], va),
                               "posicao": 1 + sum(1 for x in vp if x < s["publico_noite"]), "de": len(vp) + 1,
                               "vs_mediana": round(s["publico_noite"] / fp["p50"] - 1, 3) if fp else None,
                               "enviado_na_mediana": round(fp["p50"] / s["fator"]) if fp else None})
        notas = [c for c in principal if c.get("nota")]
        estadias.append({"checkin": e["checkin"], "checkout": e["checkout"], "noites": n,
                         "principal": fp, "ampla": fa, "certeza_alta_media": ff, "principal_ok": bool(fp and fp["n"] >= MIN_N_PRINCIPAL),
                         "fatores": fatores, "fonte_fator": fonte_f, "nossas": nossas,
                         "balcony_fora": "Balcony sem banheira: fora da régua de suítes comparáveis",
                         "ranking": sorted(principal, key=lambda c: c["suite_noite"]),
                         "estimados": sorted([c for c in ampla if c["prova"] == "estimado"], key=lambda c: c["suite_noite"]),
                         "nota_mediana_principal": round(st.median(c["nota"] for c in notas), 1) if notas else None})
    return {"hoje": hoje.isoformat(), "regra": "suíte com banheira/hidro no quarto + café incluído (dono, 18/11/2026)",
            "r_hidro_padrao": padrao, "estadias": estadias}


def buscas_ancora(res: dict) -> dict:
    out = []
    for e in res["estadias"]:
        fp = e["principal"]
        if not e["principal_ok"]:
            continue
        out.append({"checkin": e["checkin"], "checkout": e["checkout"], "label": f"comparáveis {e['checkin']}→{e['checkout']}",
                    "busca_em": res["hoje"], "percentis_noite": {k: fp[k] for k in ("p25", "p50", "p75", "p90", "max", "n")}})
    return {"_doc": "Buscas para ancora_mercado.py: só suítes com banheira + café, preço medido (ranking_comparaveis.py)",
            "buscas": out}


def brl(x) -> str:
    return "—" if x is None else f"R$ {x:,.0f}".replace(",", ".")


def markdown(res: dict, top: int = 0) -> str:
    L = [f"# Ranking de suítes comparáveis ({res['hoje']})", "",
         "Régua: só suíte com banheira/hidro no quarto e café incluído (dono, 18/11). Preço público por noite na Booking, "
         "2 adultos. Concorrente = menor quarto com café × fator medido do quarto com banheira (`r_hidro`); hotel sem "
         "fator medido fica fora da régua principal. Nossas suítes: enviado × fator público medido no dia. A Balcony "
         "não entra (sem banheira).", "",
         "| Estadia | Comparáveis | p25 | Mediana | p75 | p90 | Mediana só certeza alta/média (n) | Mediana ampla, com estimados (n) | Fator Recanto / Villa |",
         "|---|---|---|---|---|---|---|---|---|"]
    for e in res["estadias"]:
        fp = e["principal"] or {}
        L.append(f"| {e['checkin'][8:]}/{e['checkin'][5:7]}→{e['checkout'][8:]}/{e['checkout'][5:7]} ({e['noites']}n) | {fp.get('n', 0)} | "
                 f"{brl(fp.get('p25'))} | {brl(fp.get('p50'))} | {brl(fp.get('p75'))} | {brl(fp.get('p90'))} | "
                 f"{brl((e['certeza_alta_media'] or {}).get('p50'))} ({(e['certeza_alta_media'] or {}).get('n', 0)}) | "
                 f"{brl((e['ampla'] or {}).get('p50'))} ({(e['ampla'] or {}).get('n', 0)}) | "
                 f"{e['fatores']['Recanto']:.2f} / {e['fatores']['Villa']:.2f} |".replace("0.", "0,"))
    for e in res["estadias"]:
        L += ["", f"## {e['checkin']} → {e['checkout']} ({e['noites']} noite{'s' if e['noites'] > 1 else ''})", ""]
        if not e["principal_ok"]:
            L.append(f"Amostra pequena ({(e['principal'] or {}).get('n', 0)} comparáveis medidos): só leitura, não vira âncora.")
            L.append("")
        L += ["| Nossa suíte | Livres | Enviado/noite | Público/noite | Posição | Percentil | vs mediana | Enviado na mediana |",
              "|---|---|---|---|---|---|---|---|"]
        for s in e["nossas"]:
            vs = "—" if s["vs_mediana"] is None else f"{s['vs_mediana']:+.0%}"
            liv = "esgotada" if s["livres"] == 0 else ("fechada" if s["fechada"] else str(s["livres"]))
            L.append(f"| {s['nome']} | {liv} | {brl(s['enviado_noite'])} | {brl(s['publico_noite'])} | {s['posicao']}º de {s['de']} | "
                     f"p{s['percentil']} | {vs} | {brl(s['enviado_na_mediana'])} |")
        rk = e["ranking"] if not top else e["ranking"][:top]
        L += ["", "| # | Concorrente (suíte com banheira) | Nota | Suíte/noite | Menor quarto/noite | Fator (certeza) |", "|---|---|---|---|---|---|"]
        for i, c in enumerate(rk, 1):
            nota = "—" if not c.get("nota") else f"{c['nota']:.1f}".replace(".", ",")
            fator = f"{c['r_hidro']:.2f}".replace(".", ",")
            L.append(f"| {i} | {c['nome']} | {nota} | {brl(c['suite_noite'])} | {brl(c['menor_quarto_noite'])} | "
                     f"{fator} ({c.get('certeza', '—')}) |")
        if e["estimados"]:
            L += ["", f"Fora da régua (sem fator medido do quarto com banheira, {len(e['estimados'])} hotéis): "
                  + ", ".join(f"{c['nome']} ({brl(c['menor_quarto_noite'])} menor quarto)" for c in e["estimados"][:40]) + "."]
    return "\n".join(L) + "\n"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--hoje", required=True)
    ap.add_argument("--respostas", required=True)
    ap.add_argument("--inventario", required=True)
    ap.add_argument("--fator", action="append", default=[], help="Pousada=fator (ex.: Recanto=0.62)")
    ap.add_argument("--publico", action="append", default=[], help="Pousada:checkin:total público medido")
    ap.add_argument("--saida-md")
    ap.add_argument("--json")
    ap.add_argument("--ancora-buscas")
    ap.add_argument("--top", type=int, default=0)
    a = ap.parse_args(argv)
    fat = {k: float(v) for k, v in (x.split("=", 1) for x in a.fator)}
    pubs = {}
    for x in a.publico:
        p, ci, v = x.split(":")
        pubs[(p, ci)] = float(v)
    res = ranking(date.fromisoformat(a.hoje), json.load(open(a.respostas)), json.load(open(a.inventario)), fat, pubs)
    if a.json:
        json.dump(res, open(a.json, "w"), ensure_ascii=False, indent=1)
    if a.ancora_buscas:
        json.dump(buscas_ancora(res), open(a.ancora_buscas, "w"), ensure_ascii=False, indent=1)
    md = markdown(res, a.top)
    if a.saida_md:
        open(a.saida_md, "w").write(md)
    for e in res["estadias"]:
        fp = e["principal"] or {}
        nos = "; ".join(f"{s['nome']} {brl(s['publico_noite'])} p{s['percentil']}" for s in e["nossas"] if s["livres"] > 0)
        print(f"{e['checkin']}→{e['checkout']}: n={fp.get('n', 0)} mediana {brl(fp.get('p50'))} | {nos or 'nada livre'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

"""Confere o recálculo do PriceLabs (refresh_listing_pricing) contra o plano gravado.

A saída do refresh_listing_pricing passa de 270 mil caracteres por quarto; o Claude Code
salva em arquivo. Passe cada arquivo com o nome curto do quarto.

Uso:
  python3 ler_recalculo.py --plano plano.json Q7=arquivo_q7.txt VK7=arquivo_vk7.txt ...
  python3 ler_recalculo.py --datas 2026-10-09,2026-10-10 Q7=arquivo_q7.txt ...

Com --plano: compara preço (só itens de preço fixo) e estadia mínima de cada item do plano
com o recalculado; sai com 1 se alguma data ficou diferente. Sem --plano: só mostra a tabela.
"""

from __future__ import annotations

import argparse
import sys

from comum import carregar_quartos, ler_json, reais


def ler_refresh(caminho: str) -> dict:
    j = ler_json(caminho)
    arr = (j.get("data") or {}).get("pricing_array") or []
    return {str(x.get("date"))[:10]: x for x in arr}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("arquivos", nargs="+", help="CURTO=arquivo do refresh_listing_pricing")
    ap.add_argument("--plano")
    ap.add_argument("--datas", help="datas separadas por vírgula (sem --plano)")
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    dados = {}
    for par in a.arquivos:
        curto, caminho = par.split("=", 1)
        if curto not in cfg["por_curto"]:
            print(f"quarto desconhecido: {curto}", file=sys.stderr)
            return 2
        dados[curto] = ler_refresh(caminho)

    if a.plano:
        plano = ler_json(a.plano)
        problemas = 0
        print("| Quarto | Data | Planejado | Recalculado | Estadia (plano/recalc.) | Situação |")
        print("|---|---|---|---|---|---|")
        for it in plano.get("itens", []):
            if it.get("tipo") != "data" or it["quarto"] not in dados:
                continue
            x = dados[it["quarto"]].get(it["data"])
            if x is None:
                print(f"| {it['quarto']} | {it['data']} | — | sem dado | — | ⚠️ conferir |")
                problemas += 1
                continue
            ok = True
            plan_txt = "—"
            if it.get("price_type") == "fixed":
                plan_txt = reais(it["preco"])
                ok &= abs(float(x.get("price") or 0) - float(it["preco"])) <= 1
            elif it.get("price_type") == "percent":
                plan_txt = f"{it['preco']}%"
            ms_plan = it.get("min_stay")
            if ms_plan is not None:
                ok &= int(x.get("min_stay") or 0) == int(ms_plan)
            problemas += 0 if ok else 1
            print(f"| {it['quarto']} | {it['data']} | {plan_txt} | {reais(x.get('price'))} | "
                  f"{ms_plan if ms_plan is not None else '—'}/{x.get('min_stay')} | "
                  f"{'✅' if ok else '❌ diferente'} |")
        print(f"\n{problemas} diferença(s).")
        return 1 if problemas else 0

    datas = [d.strip() for d in (a.datas or "").split(",") if d.strip()]
    if not datas:
        datas = sorted({d for v in dados.values() for d in v})[:14]
    print("| Data | " + " | ".join(dados) + " |")
    print("|---|" + "---|" * len(dados))
    for d in datas:
        cel = []
        for k in dados:
            x = dados[k].get(d) or {}
            cel.append(f"{reais(x.get('price'))} · {x.get('min_stay', '?')}n")
        print(f"| {d} | " + " | ".join(cel) + " |")
    return 0


if __name__ == "__main__":
    sys.exit(main())

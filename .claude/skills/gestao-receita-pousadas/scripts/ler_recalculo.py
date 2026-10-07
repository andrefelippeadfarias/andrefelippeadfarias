"""Confere o recálculo do PriceLabs (refresh_listing_pricing) contra o plano gravado.

A saída do refresh_listing_pricing passa de 270 mil caracteres por quarto; o Claude Code
salva em arquivo. Passe cada arquivo com o nome curto do quarto.

Uso:
  python3 ler_recalculo.py --plano plano.json Q7=arquivo_q7.txt VK7=arquivo_vk7.txt ...
  python3 ler_recalculo.py --datas 2026-10-09,2026-10-10 Q7=arquivo_q7.txt ...

Com --plano: compara cada item do plano com o recalculado e sai com 1 se alguma data ficou fora:
- preço fixo: igual ao planejado (±1);
- percentual: dentro da faixa min_price–max_price da data (o valor flutua; a coluna mostra quanto deu);
- estadia mínima: igual.
Também confere a Regra G (Balcony não abaixo da suíte com banheira mais barata) nas datas da Villa
que estiverem no plano, quando os três arquivos da Villa forem passados. Sem --plano: só mostra a tabela.
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
                faixa = f"piso {reais(it['min_price'])}" if it.get("min_price") is not None else "sem piso"
                if it.get("max_price") is not None:
                    faixa += f", teto {reais(it['max_price'])}"
                plan_txt = f"{float(it['preco']):+g}% ({faixa})"
                p = float(x.get("price") or 0)
                if it.get("min_price") is not None:
                    ok &= p >= float(it["min_price"]) - 1
                if it.get("max_price") is not None:
                    ok &= p <= float(it["max_price"]) + 1
            ms_plan = it.get("min_stay")
            if ms_plan is not None:
                ok &= int(x.get("min_stay") or 0) == int(ms_plan)
            problemas += 0 if ok else 1
            print(f"| {it['quarto']} | {it['data']} | {plan_txt} | {reais(x.get('price'))} | "
                  f"{ms_plan if ms_plan is not None else '—'}/{x.get('min_stay')} | "
                  f"{'✅' if ok else '❌ diferente'} |")
        if all(k in dados for k in ("VK7", "VK2", "Balcony")):
            datas_villa = sorted({it["data"] for it in plano.get("itens", [])
                                  if it.get("tipo") == "data" and it["quarto"] in ("VK7", "VK2", "Balcony")})
            for d in datas_villa:
                b, s7, s2 = (float((dados[k].get(d) or {}).get("price") or 0) for k in ("Balcony", "VK7", "VK2"))
                if b and min(s7, s2) and b < min(s7, s2) - 1:
                    print(f"⚠️ Regra G {d}: Balcony {reais(b)} abaixo da suíte mais barata ({reais(min(s7, s2))}); "
                          f"confira se essa suíte está livre")
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

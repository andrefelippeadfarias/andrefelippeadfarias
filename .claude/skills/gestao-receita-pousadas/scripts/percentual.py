"""Converte alvos de preço em percentual por data, respeitando a suavização em blocos do PriceLabs.

Decisão do dono (07/10/2026): preço por data sempre em percentual, com o piso em min_price.

Como o PriceLabs calcula (medido em 07/10 no detalhamento do recálculo):
- o percentual da data entra DEPOIS dos ajustes de ocupação e ANTES da suavização;
- a suavização tira a média do bloco: domingo a quinta e sexta e sábado nos quartos do grupo, e a
  semana inteira na Balcony (personalização própria dela). Data esgotada não entra na média;
- por fim, aplica o piso e o teto da data (min_price/max_price). O min_price da data substitui o
  piso de fim de semana (150% da base).
Consequência: o percentual de uma data "vaza" para as outras datas do mesmo bloco. Para acertar o
preço, o percentual é calculado por bloco.

Uso:
  python3 percentual.py DETALHE.json Q7=arquivo_refresh_com_reasons.txt ...   (gera o detalhe)
  python3 percentual.py --alvos alvos.json --detalhe DETALHE.json               (calcula e simula)

alvos.json: {"VK7": {"2026-10-12": 850, ...}, ...}. Datas do bloco sem alvo ficam com 0% (o preço
delas não muda). Para levar o bloco inteiro ao mesmo nível, dê alvo a todas as datas do bloco.
Saída: para cada data com alvo, percentual, piso sugerido (o próprio alvo, quando ele fica acima
do nível do bloco) e o preço simulado.
"""

from __future__ import annotations

import argparse
import json
import sys

from comum import ler_json


def detalhe_do_refresh(caminho: str) -> dict:
    """Lê um refresh_listing_pricing com parse_reasons_json=true e guarda o essencial por data."""
    j = ler_json(caminho)
    out = {}
    for a in (j.get("data") or {}).get("pricing_array") or []:
        rj = a.get("reasons_json") or {}
        passos = [(v.get("key"), v.get("value"), v.get("price"))
                  for v in (rj.get("pricing_customizations") or {}).values()]
        out[str(a["date"])[:10]] = {
            "price": float(a["price"]),
            "cust": float((rj.get("listing_info") or {}).get("customized_price") or a["price"]),
            "passos": passos,
            "mu": a.get("mu_occupancy"),
        }
    return out


def valor_antes_suavizar(dia: dict) -> float:
    pre = [p for p in dia["passos"] if p[0] != "price_smoothing"]
    return float(pre[-1][2]) if pre else dia["cust"]


def suavizada(dia: dict) -> bool:
    return any(p[0] == "price_smoothing" for p in dia["passos"])


def blocos(det: dict) -> list[list[str]]:
    """Agrupa as datas suavizadas com o mesmo valor final de bloco; datas sem suavização (esgotadas,
    por exemplo) ficam sozinhas e não quebram o bloco ao redor."""
    datas = sorted(det)
    grupos: list[list[str]] = []
    aberto: list[str] = []
    for d in datas:
        if not suavizada(det[d]):
            grupos.append([d])
            continue
        if aberto and abs(det[d]["cust"] - det[aberto[-1]]["cust"]) <= 1:
            aberto.append(d)
        else:
            if aberto:
                grupos.append(aberto)
            aberto = [d]
    if aberto:
        grupos.append(aberto)
    return grupos


def calcular(det: dict, alvos: dict, minimo: float) -> dict:
    """Devolve {data: {"pct", "piso", "simulado"}} para as datas com alvo."""
    res = {}
    for b in blocos(det):
        com_alvo = [d for d in b if d in alvos]
        if not com_alvo:
            continue
        sem_alvo = [d for d in b if d not in alvos]
        nivel = min(alvos[d] for d in com_alvo)
        soma_x = sum(valor_antes_suavizar(det[d]) for d in com_alvo)
        soma_n = sum(valor_antes_suavizar(det[d]) for d in sem_alvo)
        pct = round(((nivel * len(b) - soma_n) / soma_x - 1) * 100)
        v = (sum(valor_antes_suavizar(det[d]) * (1 + pct / 100) for d in com_alvo) + soma_n) / len(b)
        for d in com_alvo:
            piso = alvos[d] if (alvos[d] > nivel + 1 or alvos[d] < minimo) else minimo
            res[d] = {"pct": pct, "piso": round(piso), "simulado": round(max(v, piso)),
                      "bloco": f"{b[0]}..{b[-1]}", "vaza_para": sem_alvo}
    return res


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("saida", nargs="?", help="arquivo de detalhe a gravar (modo de geração)")
    ap.add_argument("arquivos", nargs="*", help="CURTO=arquivo do refresh com parse_reasons_json")
    ap.add_argument("--alvos")
    ap.add_argument("--detalhe")
    ap.add_argument("--quarto", help="nome curto, para usar o mínimo do quarto")
    a = ap.parse_args(argv)
    if a.alvos:
        from comum import carregar_quartos
        cfg = carregar_quartos()
        det_all = ler_json(a.detalhe)
        alvos_all = ler_json(a.alvos)
        for curto, alvos in alvos_all.items():
            minimo = cfg["por_curto"][curto]["min"]
            r = calcular(det_all[curto], {k: float(v) for k, v in alvos.items()}, minimo)
            for d in sorted(r):
                x = r[d]
                vaza = f" (vaza para {', '.join(x['vaza_para'])})" if x["vaza_para"] else ""
                if x["pct"] < -60:
                    vaza += " ATENÇÃO: impossível só com essa data; dê alvo ao bloco inteiro (se a regra permitir)"
                print(f"{curto:8} {d} alvo {alvos[d]:>6} -> {x['pct']:+d}% piso {x['piso']} "
                      f"simulado {x['simulado']}{vaza}")
        return 0
    if not a.saida or not a.arquivos:
        ap.error("informe o arquivo de saída e CURTO=arquivo, ou --alvos e --detalhe")
    det = {}
    for par in a.arquivos:
        curto, caminho = par.split("=", 1)
        det[curto] = detalhe_do_refresh(caminho)
    with open(a.saida, "w", encoding="utf-8") as f:
        json.dump(det, f, ensure_ascii=False)
    print(f"detalhe de {len(det)} quarto(s) gravado em {a.saida}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

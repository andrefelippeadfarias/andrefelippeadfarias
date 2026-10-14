"""Junta recálculos recentes (refresh_listing_pricing) no arquivo da coleta.

Por quê (aprendizado de 13/10/2026): Double, Queen 2, VK7 e Balcony só recalculam no envio da tarde;
na rotina das 07:53 o calendário deles tem ~20 h. Na VK7 isso escondeu 21 pontos de ocupação em 7 dias
(53% contra 74%) e a Balcony aparecia com 3 de 6 vendidas em 16 e 17/10 quando já estava esgotada. Decida
sempre com o calendário recalculado: rode o refresh nos quartos com `last_refreshed_at` velho, junte aqui e
use o arquivo mesclado no ocupacao.py e no validar_plano.py.

Uso:
  python3 mesclar_recalculo.py coleta.json saida.json Q7=refresh_q7.txt VK7=refresh_vk7.txt ...
  python3 mesclar_recalculo.py coleta.json --velhos [--horas 6] [--agora 2026-10-13T12:00:00+00:00]

Com --velhos só lista os quartos cujo `last_refreshed_at` tem mais de N horas (padrão 6).
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone

from comum import ler_json, resultado_workflow


def mesclar_quarto(quarto: dict, refresh: dict) -> dict:
    """Troca preço, enviado, estadia e ocupação dos dias que existem nos dois lados. Mantém o resto."""
    arr = (refresh.get("data") or {}).get("pricing_array") or []
    novo = {str(x.get("date"))[:10]: x for x in arr}
    dias = []
    for d in quarto.get("days", []):
        x = novo.get(d["date"])
        if not x:
            dias.append(d)
            continue
        d = dict(d)
        d["price"] = float(x["price"])
        d["user_price"] = float(x["user_price"])
        d["min_stay"] = int(x["min_stay"])
        mu = str(x.get("mu_occupancy") or "")
        if "/" in mu:
            d["multi_unit_occupancy"] = mu
            d["booking_status"] = "Booked" if int(mu.split("/")[0]) > 0 else ""
        else:
            # unidade única (Afrodite): mu_occupancy vem "-1"; vendida = tem reserva (num_bookings)
            d["booking_status"] = "Booked" if int(x.get("num_bookings") or 0) > 0 else ""
        if x.get("uncustomized_price") is not None:
            d["uncustomized_price"] = float(x["uncustomized_price"])
        dias.append(d)
    return {**quarto, "days": dias}


def quartos_velhos(coleta: dict, horas: float, agora: datetime) -> list[tuple[str, float]]:
    """(curto, horas desde o último recálculo) dos quartos com recálculo mais velho que `horas`."""
    saida = []
    for curto, q in coleta.get("rooms", {}).items():
        ts = q.get("last_refreshed_at")
        if not ts:
            saida.append((curto, float("inf")))
            continue
        idade = (agora - datetime.fromisoformat(ts)).total_seconds() / 3600
        if idade > horas:
            saida.append((curto, idade))
    return saida


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("coleta")
    ap.add_argument("saida", nargs="?")
    ap.add_argument("refreshes", nargs="*", help="CURTO=arquivo do refresh_listing_pricing")
    ap.add_argument("--velhos", action="store_true")
    ap.add_argument("--horas", type=float, default=6)
    ap.add_argument("--agora")
    a = ap.parse_args(argv)
    coleta = resultado_workflow(ler_json(a.coleta))
    if a.velhos:
        agora = datetime.fromisoformat(a.agora) if a.agora else datetime.now(timezone.utc)
        for curto, h in quartos_velhos(coleta, a.horas, agora):
            print(f"{curto}: recálculo de {h:.0f} h atrás" if h != float("inf") else f"{curto}: sem last_refreshed_at")
        return 0
    if not a.saida:
        print("faltou o arquivo de saída", file=sys.stderr)
        return 2
    for par in a.refreshes:
        curto, caminho = par.split("=", 1)
        if curto not in coleta.get("rooms", {}):
            print(f"quarto desconhecido na coleta: {curto}", file=sys.stderr)
            return 2
        coleta["rooms"][curto] = mesclar_quarto(coleta["rooms"][curto], ler_json(caminho))
    with open(a.saida, "w", encoding="utf-8") as f:
        json.dump(coleta, f, ensure_ascii=False)
    print(f"{len(a.refreshes)} quarto(s) mesclado(s) em {a.saida}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

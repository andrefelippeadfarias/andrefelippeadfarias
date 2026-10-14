"""Calendário de sazonalidade (dados/sazonalidade.json): em que período cai cada noite e o que ele pede.

Uso:
  python3 sazonalidade.py hoje [--dias 60]      períodos que cobrem os próximos N dias, com nível e faixa alvo
  python3 sazonalidade.py data 2027-07-09        o período de uma noite
  python3 sazonalidade.py validar                cada noite da janela cai em exatamente um período
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date, timedelta
from pathlib import Path

ARQ = Path(__file__).resolve().parent.parent / "dados" / "sazonalidade.json"
NIVEIS = ["muito baixa", "baixa", "média", "alta", "muito alta"]
FAIXAS = ["muito baixo", "baixo", "médio", "alto", "muito alto"]


def carregar(caminho: Path | str | None = None) -> dict:
    with open(caminho or ARQ, encoding="utf-8") as f:
        return json.load(f)


def _cobre(p: dict, d: date) -> bool:
    if not (date.fromisoformat(p["inicio"]) <= d <= date.fromisoformat(p["fim"])):
        return False
    dias = p.get("dias", "todos")
    fds = d.weekday() in (4, 5)  # sexta e sábado
    return dias == "todos" or (dias == "sex-sab" and fds) or (dias == "dom-qui" and not fds)


def periodo(cal: dict, d: date) -> dict | None:
    """O período que cobre a noite `d`, ou None fora da janela do calendário."""
    for p in cal["periodos"]:
        if _cobre(p, d):
            return p
    return None


def datas_especiais(cal: dict, d: date, tipos=("feriado", "evento")) -> list[dict]:
    """Feriados e eventos da lista `datas` que tocam a noite `d`."""
    return [x for x in cal.get("datas", []) if x["tipo"] in tipos
            and date.fromisoformat(x["inicio"]) <= d <= date.fromisoformat(x["fim"])]


def indice_faixa(nome: str) -> int:
    return FAIXAS.index(nome)


def validar(cal: dict) -> list[str]:
    """Problemas de cobertura: noites sem período ou com mais de um."""
    problemas = []
    d = date.fromisoformat(cal["periodos"][0]["inicio"]) if cal["periodos"] else None
    fim = max(date.fromisoformat(p["fim"]) for p in cal["periodos"]) if cal["periodos"] else None
    ini = min(date.fromisoformat(p["inicio"]) for p in cal["periodos"]) if cal["periodos"] else None
    d = ini
    while d is not None and d <= fim:
        n = sum(1 for p in cal["periodos"] if _cobre(p, d))
        if n != 1:
            problemas.append(f"{d}: {n} período(s)")
        d += timedelta(days=1)
    for p in cal["periodos"]:
        if p["nivel"] not in NIVEIS or p["faixa_alvo"] not in FAIXAS:
            problemas.append(f"{p['inicio']} {p['nome'][:40]}: nível ou faixa inválidos")
    return problemas


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    h = sub.add_parser("hoje"); h.add_argument("--dias", type=int, default=60); h.add_argument("--inicio")
    dd = sub.add_parser("data"); dd.add_argument("data")
    sub.add_parser("validar")
    a = ap.parse_args(argv)
    cal = carregar()
    if a.cmd == "validar":
        probs = validar(cal)
        print("ok: cada noite cai em um só período" if not probs else "\n".join(probs))
        return 1 if probs else 0
    if a.cmd == "data":
        d = date.fromisoformat(a.data)
        p = periodo(cal, d)
        print(json.dumps({"noite": a.data, "periodo": p, "datas": datas_especiais(cal, d)}, ensure_ascii=False, indent=1))
        return 0
    ini = date.fromisoformat(a.inicio) if a.inicio else date.today()
    vistos = []
    for i in range(a.dias):
        p = periodo(cal, ini + timedelta(days=i))
        if p and p not in vistos:
            vistos.append(p)
    print("| Período | Dias | Nível | Faixa alvo | Estadia | Antecedência |")
    print("|---|---|---|---|---|---|")
    for p in vistos:
        print(f"| {p['inicio']} a {p['fim']}: {p['nome'][:55]} | {p['dias']} | {p['nivel']} | {p['faixa_alvo']} | {p.get('estadia_minima') or '—'} | {p.get('antecedencia_dias') or '—'} d |")
    return 0


if __name__ == "__main__":
    sys.exit(main())

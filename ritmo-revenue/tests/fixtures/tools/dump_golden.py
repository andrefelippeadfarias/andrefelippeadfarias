#!/usr/bin/env python3
"""Dumps the level-1 golden fixtures of the rule engine.

Runs the pure functions of the reference Python engine (read from the repository, never changed)
with frozen inputs and writes, for each function, a JSON file

    {"function": ..., "php_target": ..., "notes": ..., "cases": [{"input": {...}, "expected": ...}]}

into tests/fixtures/golden/level1/. PHPUnit loads these files and requires the PHP port to return
the same results (amounts within 0.01, percentages and integers exactly).

Inputs are frozen: the engine configuration comes from tests/fixtures/frozen/quartos.json and the
market anchor from tests/fixtures/frozen/ancora_mercado.json (see freeze_inputs.py). No function
dumped here reads the clock; FROZEN_TODAY is recorded in the manifest for the fixtures that will.
Case inputs use the engine's own argument names and vocabulary; MANIFEST.json maps the vocabulary
to the plugin's (semana -> weekday, frente -> ahead, ...). NaN is written as the string "NaN".

Usage: python3 tests/fixtures/tools/dump_golden.py [--generated-at YYYY-MM-DD]
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import math
import platform
import random
import subprocess
import sys
from pathlib import Path

sys.dont_write_bytecode = True  # the engine folder must stay untouched, bytecode cache included

TOOLS = Path(__file__).resolve().parent
FIXTURES = TOOLS.parent
FROZEN = FIXTURES / "frozen"
GOLDEN = FIXTURES / "golden"
LEVEL1 = GOLDEN / "level1"
REPO = TOOLS.parents[3]
ENGINE = REPO / ".claude" / "skills" / "gestao-receita-pousadas" / "scripts"

sys.path.insert(0, str(ENGINE))
import ancora_mercado  # noqa: E402
import pace  # noqa: E402
import validar_plano  # noqa: E402
from comum import carregar_quartos  # noqa: E402
from vocabulary import PROPERTY_ID, VOCABULARY  # noqa: E402

FROZEN_TODAY = "2026-11-11"
NIGHT_TYPES = ("semana", "fds", "feriado")
STATUSES = ("frente", "ritmo", "atras", "muito_atras")
ANCHOR_KEYS = ("p25", "p50", "p75", "p90", "max")


def number(value):
    """JSON-safe number: NaN becomes the string "NaN" (JSON has no NaN)."""
    if isinstance(value, float) and math.isnan(value):
        return "NaN"
    return value


def anchors(anchor_file: dict) -> list[dict]:
    """Anchors used by the percentile cases: frozen nights, monthly fallbacks and synthetic edges."""
    found = [{k: v[k] for k in ANCHOR_KEYS} for v in anchor_file["noites"].values()]
    for months in anchor_file["por_tipo"].values():
        found += [{k: v[k] for k in ANCHOR_KEYS} for v in months.values()]
    found += [
        {"p25": 500, "p50": 500, "p75": 500, "p90": 500, "max": 500},  # every point equal
        {"p25": 0, "p50": 400, "p75": 600, "p90": 800, "max": 1000},  # p25 at zero
        {"p25": 300, "p50": 450, "p75": 450, "p90": 450, "max": 900},  # a flat stretch in the middle
        {"p25": 310.5, "p50": 402.25, "p75": 655.75, "p90": 901.1, "max": 1333.33},  # not whole numbers
    ]
    unique = []
    for anchor in found:
        if anchor not in unique:
            unique.append(anchor)
    return unique


# ---------- pace.py
def cases_curva(cfg: dict) -> list[dict]:
    days = [-1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 10, 13, 14, 15, 20, 29, 30, 31, 45, 59, 60, 61, 90, 365]
    return [{"input": {"tipo": t, "dias": d}, "expected": pace.curva(cfg, t, d)} for t in NIGHT_TYPES for d in days]


def cases_status(cfg: dict) -> list[dict]:
    grid = []
    for tipo in NIGHT_TYPES:
        # every sale count of small and medium rooms, at the days around each rule of the status
        for dias in (0, 1, 3, 7, 8, 14, 15, 30, 45, 60, 61):
            for unidades in (1, 2, 6, 7):
                grid += [(tipo, dias, v, unidades) for v in range(unidades + 1)]
        # with 100 units the sold share is a whole percentage: hits the band limits exactly
        for dias in (7, 14, 30, 60):
            esperado = int(round(pace.curva(cfg, tipo, dias)))
            for delta in (-31, -30, -29, -21, -20, -19, -1, 0, 1, 19, 20, 21, 30):
                if 0 <= esperado + delta <= 100:
                    grid.append((tipo, dias, esperado + delta, 100))
        # nothing sold on both sides of the "zero sold close to the night" windows, overbooking, no units
        grid += [(tipo, d, 0, 100) for d in (6, 7, 8, 13, 14, 15, 16)]
        grid += [(tipo, 5, 8, 7), (tipo, 20, 3, 2), (tipo, 10, 0, 0), (tipo, 10, 2, 0)]
    out = []
    for tipo, dias, vendidas, unidades in grid:
        status, vendido, curva = pace.status_da_noite(cfg, tipo, dias, vendidas, unidades)
        out.append({"input": {"tipo": tipo, "dias": dias, "vendidas": vendidas, "unidades": unidades},
                    "expected": {"status": status, "vendido": vendido, "curva": curva}})
    return out


def cases_percentil_alvo(cfg: dict) -> list[dict]:
    return [{"input": {"pousada": p, "status": s, "dias": d}, "expected": pace.percentil_alvo(cfg, p, s, d)}
            for p in PROPERTY_ID for s in STATUSES for d in (-1, 0, 6, 7, 8, 30, 60)]


def cases_pmax(cfg: dict) -> list[dict]:
    days = [-1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 15, 29, 30, 31, 32, 60, 365]
    return [{"input": {"dias": d}, "expected": pace.pmax(cfg, d)} for d in days]


def cases_passo(cfg: dict) -> list[dict]:
    out = []
    for atual in (0, 500, 739.5, 1173, 2000):
        for alvo in (300, 739.5, 800, 1180, 5000):
            for dias in (0, 3, 4, 7, 8, 30, 31, 60):
                for fraco in (False, True):
                    out.append({"input": {"atual": atual, "alvo": alvo, "dias": dias, "fraco": fraco},
                                "expected": pace.passo(cfg, atual, alvo, dias, fraco)})
    rng = random.Random(20261111)
    for _ in range(60):
        atual, alvo = round(rng.uniform(300, 3000), 2), round(rng.uniform(300, 3000), 2)
        dias, fraco = rng.randint(0, 75), rng.random() < 0.3
        out.append({"input": {"atual": atual, "alvo": alvo, "dias": dias, "fraco": fraco},
                    "expected": pace.passo(cfg, atual, alvo, dias, fraco)})
    return out


def cases_efetivo(cfg: dict) -> list[dict]:
    out = []
    rng = random.Random(20261112)
    for q in cfg["quartos"]:
        base = float(q["base"])
        values = [base * f for f in (0.25, 0.5, 0.69, 0.7, 0.855, 0.9, 1.0, 1.005, 1.07, 1.5, 2.0)]
        values += [round(rng.uniform(0.3, 2.5) * base, 2) for _ in range(6)]
        for reais in values:
            for para_baixo in (False, True):
                out.append({"input": {"curto": q["curto"], "base": q["base"], "reais": reais, "para_baixo": para_baixo},
                            "expected": pace.efetivo(cfg, q["curto"], reais, para_baixo)})
    return out


# ---------- ancora_mercado.py
def cases_quantil() -> list[dict]:
    lists = [
        [], [420.0], [300, 500], [500, 300], [100, 200, 300], [7, 7, 7, 7],
        [812, 640, 955, 1210, 770], [350.5, 410.25, 388, 520.75, 610, 299.9],
        [1000, 250, 250, 400, 980, 615, 730, 505], [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        [0, 0, 100], [1e-3, 1e6],
    ]
    return [{"input": {"valores": v, "p": p}, "expected": number(ancora_mercado.quantil(list(v), p))}
            for v in lists for p in (0, 0.1, 0.25, 0.5, 0.75, 0.8, 0.9, 1)]


def cases_valor_percentil(anchor_file: dict) -> list[dict]:
    pcts = [0, 10, 12.5, 25, 25.0001, 37.5, 40, 50, 65, 75, 82, 90, 95, 100, 100.5, 110, 150]
    return [{"input": {"anc": a, "pct": p}, "expected": ancora_mercado.valor_percentil(a, p)}
            for a in anchors(anchor_file) for p in pcts]


def cases_percentil_de(anchor_file: dict) -> list[dict]:
    out = []
    for anc in anchors(anchor_file):
        points = [anc[k] for k in ANCHOR_KEYS]
        prices = {0, points[0] / 2, points[-1] * 1.1, points[-1] * 2, 333.33, 1234.5}
        prices.update(points)
        prices.update((a + b) / 2 for a, b in zip(points, points[1:]))
        prices.update(p + delta for p in points for delta in (-1, 1) if p + delta >= 0)
        for preco in sorted(prices):
            out.append({"input": {"preco_publico": preco, "anc": anc}, "expected": ancora_mercado.percentil_de(preco, anc)})
    return out


# ---------- validar_plano.py
def bases(cfg: dict) -> list[float]:
    return sorted({q["base"] for q in cfg["quartos"]})


def cases_percent(cfg: dict, function) -> list[dict]:
    """Shared by piso_pct and teto_pct: amounts on, just above and just below whole percentages."""
    out = []
    rng = random.Random(20261113)
    for base in bases(cfg):
        q = {"base": base}
        values = []
        for pct in list(range(-75, 101, 5)) + [-74, -31, -1, 1, 7, 61, 99]:
            exact = base * (1 + pct / 100)
            values += [exact, round(exact, 2), exact + 0.01, exact - 0.01]
        values += [float(base), base * 0.7, base / 3, base * 1.0049, base * 1.005, base * 1.0051]
        values += [round(rng.uniform(0.25, 2.2) * base, 2) for _ in range(8)]
        for reais in values:
            out.append({"input": {"base": base, "reais": reais}, "expected": function(q, reais)})
    return out


def cases_em_reais(cfg: dict) -> list[dict]:
    out = []
    for base in bases(cfg):
        q = {"base": base}
        pcts = list(range(-75, 101)) if base in (1173, 1270) else list(range(-75, 101, 5))
        pcts += [-31.5, -0.5, 0.5, 2.5, 12.25, 33.33, 150, 1000]
        for pct in pcts:
            out.append({"input": {"base": base, "pct": pct}, "expected": validar_plano.em_reais(q, pct)})
    return out


# ---------- Python's own round(), the reference of Num::roundHalfEven
def cases_round() -> list[dict]:
    values = [0.5, 1.5, 2.5, 3.5, -0.5, -1.5, -2.5, 0.49999999999999994, 2.675, 1.005, 1.115, 0.285, 0.125, 0.375,
              0.045, 0.25, 0.35, 0.15, 1.45, 1759.5, 1333.5, 1460.5, 586.5, 1669.5, 1234567.895, 123456789.125,
              -0.4, -0.04, 0.0, 1e-7, 814.8275, 904.0600000000001, 723.9, 0.30000000000000004, 99.995, 1e15 + 0.5]
    rng = random.Random(20261114)
    values += [round(rng.uniform(-5000, 5000), 4) for _ in range(40)]
    values += [rng.randint(0, 400) + rng.choice((0.5, 0.05, 0.005, 0.25, 0.125)) for _ in range(40)]
    out = []
    for value in values:
        for decimals in (0, 1, 2, 3):
            # float() because round(x) without digits returns an int in Python
            out.append({"input": {"value": value, "decimals": decimals}, "expected": float(round(value, decimals))})
    return out


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=1, allow_nan=False)
        handle.write("\n")


def write_golden(path: Path, golden: dict) -> None:
    """One case per line: small files and one-line diffs when a case changes."""
    head = {k: v for k, v in golden.items() if k != "cases"}
    lines = [json.dumps(case, ensure_ascii=False, allow_nan=False) for case in golden["cases"]]
    text = json.dumps(head, ensure_ascii=False, indent=1)[:-2] + ',\n "cases": [\n  ' + ",\n  ".join(lines) + "\n ]\n}\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")


def golden_files(cfg: dict, anchor_file: dict) -> list[dict]:
    num = "Ritmo\\Domain\\Support\\Num"
    rules = "Ritmo\\Domain\\Config\\RuleSet"
    cfg_note = "cfg is tests/fixtures/frozen/quartos.json; in PHP, the rule set of tests/fixtures/presets/parity.json."
    return [
        {"function": "pace.curva", "php_target": "Ritmo\\Domain\\Pricing (pace curve)", "notes": cfg_note,
         "cases": cases_curva(cfg)},
        {"function": "pace.status_da_noite", "php_target": "Ritmo\\Domain\\Pricing (pace status)",
         "notes": cfg_note + " expected = {status, vendido (sold %), curva (expected %)}.", "cases": cases_status(cfg)},
        {"function": "pace.percentil_alvo", "php_target": rules + "::targetPercentile(tier, status, daysOut)",
         "notes": cfg_note + " pousada maps to the tier of the property (manifest vocabulary.tier).",
         "cases": cases_percentil_alvo(cfg)},
        {"function": "pace.pmax", "php_target": rules + "::maxStepPercent(daysOut)", "notes": cfg_note,
         "cases": cases_pmax(cfg)},
        {"function": "pace.passo", "php_target": "Ritmo\\Domain\\Pricing (pace step)", "notes": cfg_note,
         "cases": cases_passo(cfg)},
        {"function": "pace.efetivo", "php_target": num + "::percentToAmount(floorToPercent|ceilingToPercent, base, 2)",
         "notes": "para_baixo false = floor (rounded up), true = ceiling (rounded down).", "cases": cases_efetivo(cfg)},
        {"function": "ancora_mercado.quantil", "php_target": "Ritmo\\Domain\\Market (quantile)",
         "notes": 'An empty list returns NaN, written as "NaN".', "cases": cases_quantil()},
        {"function": "ancora_mercado.valor_percentil", "php_target": "Ritmo\\Domain\\Market (value at percentile)",
         "notes": "anc holds p25, p50, p75, p90 and max.", "cases": cases_valor_percentil(anchor_file)},
        {"function": "ancora_mercado.percentil_de", "php_target": "Ritmo\\Domain\\Market (percentile of a price)",
         "notes": "anc holds p25, p50, p75, p90 and max. The result is rounded to 1 decimal by the engine.",
         "cases": cases_percentil_de(anchor_file)},
        {"function": "validar_plano.piso_pct", "php_target": num + "::floorToPercent(amount, base)",
         "notes": "reais = amount.", "cases": cases_percent(cfg, validar_plano.piso_pct)},
        {"function": "validar_plano.teto_pct", "php_target": num + "::ceilingToPercent(amount, base)",
         "notes": "reais = amount.", "cases": cases_percent(cfg, validar_plano.teto_pct)},
        {"function": "validar_plano.em_reais", "php_target": num + "::percentToAmount(percent, base)",
         "notes": "pct = percent.", "cases": cases_em_reais(cfg)},
        {"function": "builtins.round", "php_target": num + "::roundHalfEven(value, decimals)",
         "notes": "Python 3 round(value, decimals) as a float.", "cases": cases_round()},
    ]


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--generated-at", default=dt.datetime.now(dt.timezone.utc).date().isoformat())
    args = parser.parse_args(argv)

    cfg = carregar_quartos(FROZEN / "quartos.json")
    with open(FROZEN / "ancora_mercado.json", encoding="utf-8") as handle:
        anchor_file = json.load(handle)

    listing = []
    for golden in golden_files(cfg, anchor_file):
        name = golden["function"] + ".json"
        write_golden(LEVEL1 / name, golden)
        listing.append({"file": "level1/" + name, "function": golden["function"], "cases": len(golden["cases"])})

    commit = subprocess.run(["git", "-C", str(REPO), "rev-parse", "HEAD"], check=True, capture_output=True, text=True)
    write_json(GOLDEN / "MANIFEST.json", {
        "generated_at": args.generated_at,
        "repo_commit": commit.stdout.strip(),
        "engine": ".claude/skills/gestao-receita-pousadas/scripts",
        "python": platform.python_version(),
        "frozen_today": FROZEN_TODAY,
        "frozen_inputs": {p.name: sha256(p) for p in sorted(FROZEN.glob("*.json"))},
        "tolerance": {"amounts": 0.01, "percentages_and_integers": "exact"},
        "nan": 'written as the string "NaN"',
        "vocabulary": VOCABULARY,
        "files": listing,
    })
    for row in listing:
        print(f"{row['cases']:5d} cases  {row['file']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

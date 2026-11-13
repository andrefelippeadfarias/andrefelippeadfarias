#!/usr/bin/env python3
"""Golden cases of the market area (M4: competitors, stay samples, the market series and where a price sits).

Runs pure functions of the reference Python engine (faixas_dono: quantil, r_hidro, estadia_por_noite, construir,
mediana_por_mes, regua_noite, percentil_de, posicao) with frozen invented inputs and writes one JSON file per
function into tests/fixtures/golden/m4/market/, in the format of the other goldens, plus its MANIFEST.json and the
two frozen sweep files the stays come from (frozen/sweep_1.json, frozen/sweep_2.json):

    {"function": ..., "php_target": ..., "notes": ..., "cases": [{"input": {...}, "expected": ...}]}

Moved from the draft of spec 06 (ritmo-specs/golden/m4/dump.py, market part) without changing a single input, so the
cases are the ones the spec counts (45, 7, 14, 2, 30, 89, 7, 20).

Safety (all on while the engine runs):
  - no bytecode is written (sys.dont_write_bytecode; run it with -B);
  - no network: urllib's urlopen, http.client and socket connections raise;
  - open() of anything under the engine's dados/ folder is refused and open() for writing anywhere under the skill
    folder is refused (these functions read nothing from dados/: the configuration loader is replaced by {});
  - the clock is frozen where an engine function reads it (construir stamps a version date that is dropped).
Inputs are invented: competitors are "comp-NN" with invented factors and ratings, the own listing is "own-hotel";
no guest data anywhere.

Usage: PYTHONDONTWRITEBYTECODE=1 python3 -I -B tests/fixtures/tools/golden_market.py
"""
from __future__ import annotations

import sys

sys.dont_write_bytecode = True

import builtins  # noqa: E402
import copy  # noqa: E402
import datetime as dt  # noqa: E402
import http.client  # noqa: E402
import json  # noqa: E402
import platform  # noqa: E402
import random  # noqa: E402
import socket  # noqa: E402
import subprocess  # noqa: E402
import urllib.request  # noqa: E402
from pathlib import Path  # noqa: E402
from unittest import mock  # noqa: E402

PLUGIN = Path(__file__).resolve().parents[3]
REPO = PLUGIN.parent
ENGINE_PATH = ".claude/skills/gestao-receita-pousadas/scripts"
SKILL = REPO / ".claude" / "skills" / "gestao-receita-pousadas"
ENGINE = REPO / ENGINE_PATH
OUT = PLUGIN / "tests" / "fixtures" / "golden" / "m4" / "market"
FROZEN = OUT / "frozen"

FROZEN_TODAY = dt.date(2026, 11, 13)


# ---------------------------------------------------------------------------------------------- guards
def _no_network(*_a, **_k):
    raise RuntimeError("network access is blocked while dumping goldens")


_real_open = builtins.open


def _guarded_open(file, mode="r", *args, **kwargs):
    if isinstance(file, (str, Path)):
        path = Path(file).resolve()
        if SKILL in path.parents and any(flag in mode for flag in "wax+"):
            raise RuntimeError(f"refused to write into the skill folder: {path}")
        if (SKILL / "dados") in path.parents:
            raise RuntimeError(f"refused to read live engine data: {path}")
    return _real_open(file, mode, *args, **kwargs)


class FrozenDate(dt.date):
    @classmethod
    def today(cls):
        return cls(FROZEN_TODAY.year, FROZEN_TODAY.month, FROZEN_TODAY.day)


GUARDS = [
    mock.patch.object(urllib.request, "urlopen", _no_network),
    mock.patch.object(http.client.HTTPConnection, "connect", _no_network),
    mock.patch.object(socket.socket, "connect", _no_network),
    mock.patch.object(builtins, "open", _guarded_open),
]


# ---------------------------------------------------------------------------------------------- helpers
def write(name: str, function: str, php_target: str, notes: str, cases: list) -> tuple[str, int]:
    path = OUT / f"{name}.json"
    body = {"function": function, "php_target": php_target, "notes": notes, "cases": jsonable(cases)}
    with _real_open(path, "w", encoding="utf-8") as f:
        f.write(json.dumps(body, ensure_ascii=False, indent=1, default=str) + "\n")
    return path.name, len(cases)


def jsonable(value):
    """Tuples become lists, dates ISO text, NaN the string "NaN" (as dump_golden.py)."""
    if isinstance(value, dict):
        return {str(k): jsonable(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [jsonable(v) for v in value]
    if isinstance(value, (dt.date, dt.datetime)):
        return value.isoformat()
    if isinstance(value, float) and value != value:
        return "NaN"
    return value


def git(*args: str) -> str:
    return subprocess.run(["git", "-C", str(REPO), *args], capture_output=True, text=True, check=False).stdout.strip()


# ---------------------------------------------------------------------------------------------- inputs
TYPES = {"weekday": "Weekday", "weekend": "Weekend", "holiday": "Holiday"}
OWN = {"own-hotel"}
HOLIDAYS = {dt.date(2026, 12, 24), dt.date(2026, 12, 25), dt.date(2026, 12, 31), dt.date(2027, 1, 1),
            dt.date(2027, 2, 13), dt.date(2027, 2, 14), dt.date(2027, 2, 15)}


def night_type(d: dt.date) -> str:
    if d in HOLIDAYS:
        return "holiday"
    return "weekend" if d.weekday() in (4, 5) else "weekday"


def catalog(rng: random.Random) -> dict:
    """60 invented competitors: measured, uncertain and missing comparable-room factors, ratings 7.0-9.6."""
    hotels = []
    for i in range(1, 61):
        h = {"slug": f"comp-{i:02d}", "nome": f"Competitor {i:02d}", "nota": round(rng.uniform(7.0, 9.6), 1)}
        roll = rng.random()
        if roll < 0.6:
            h["r_hidro"] = round(rng.uniform(1.1, 1.9), 3)
            h["r_hidro_fonte"] = "medido"
        elif roll < 0.75:
            h["r_hidro"] = round(rng.uniform(1.7, 2.8), 3)
            h["r_hidro_fonte"] = "estimado (incerto)"
        hotels.append(h)
    return {"r_hidro_padrao": 1.395, "hoteis": hotels}


def stay_of(rng: random.Random, cad: dict, checkin: dt.date, nights: int, count: int, level: float) -> dict:
    slugs = [h["slug"] for h in cad["hoteis"]]
    rng.shuffle(slugs)
    hotels = []
    for slug in slugs[:count]:
        per_night = level * rng.lognormvariate(0, 0.35)
        hotels.append({"slug": slug, "preco_total": round(per_night * nights, 2)})
    hotels.append({"slug": "own-hotel", "preco_total": round(level * nights, 2)})       # the property itself: excluded
    hotels.append({"slug": "not-in-catalog", "preco_total": round(level * 0.8 * nights, 2)})  # unknown: excluded
    hotels.append({"slug": slugs[-1], "preco_total": None})                               # no price: excluded
    return {"checkin": checkin.isoformat(), "checkout": (checkin + dt.timedelta(days=nights)).isoformat(),
            "tipo": "label", "hoteis": hotels}


def sweep(rng: random.Random, cad: dict) -> tuple[list[dict], list[dict], dict]:
    """Two sweep files (the second repeats one stay with other prices: the first file wins) and the forced types."""
    plan = [  # checkin, nights, competitors, level
        ("2026-12-01", 1, 44, 420), ("2026-12-03", 1, 22, 400), ("2026-12-04", 2, 52, 780), ("2026-12-08", 1, 12, 380),
        ("2026-12-11", 2, 47, 820), ("2026-12-15", 1, 41, 450), ("2026-12-18", 2, 38, 900), ("2026-12-24", 2, 50, 1400),
        ("2026-12-31", 2, 55, 1900), ("2027-01-05", 1, 30, 520), ("2027-01-08", 2, 45, 980), ("2027-01-12", 1, 43, 500),
        ("2027-01-15", 2, 39, 940), ("2027-01-20", 1, 18, 470), ("2027-01-22", 2, 42, 910), ("2027-02-02", 1, 40, 430),
        ("2027-02-05", 2, 33, 760), ("2027-02-13", 3, 48, 1300), ("2027-02-17", 1, 16, 390), ("2027-02-19", 2, 46, 800),
    ]
    first, second, types = [], [], {}
    for checkin, nights, count, level in plan:
        d = dt.date.fromisoformat(checkin)
        stay = stay_of(rng, cad, d, nights, count, level)
        first.append(stay)
        types[f"{stay['checkin']}/{stay['checkout']}"] = night_type(d)
    repeated = copy.deepcopy(first[2])
    for h in repeated["hoteis"]:
        if h["preco_total"]:
            h["preco_total"] = round(h["preco_total"] * 1.3, 2)
    second.append(repeated)
    extra = stay_of(rng, cad, dt.date(2027, 1, 29), 2, 47, 870)  # only in the second file
    second.append(extra)
    types[f"{extra['checkin']}/{extra['checkout']}"] = "weekend"
    mixed = stay_of(rng, cad, dt.date(2027, 2, 11), 2, 44, 700)  # a stay mixing night types: "ignorar"
    second.append(mixed)
    types[f"{mixed['checkin']}/{mixed['checkout']}"] = "ignorar"
    return first, second, types


# ---------------------------------------------------------------------------------------------- cases
def market_cases(fd) -> list[tuple[str, int]]:
    rng = random.Random(20261113)
    cad = catalog(rng)
    first, second, types = sweep(rng, cad)
    FROZEN.mkdir(parents=True, exist_ok=True)
    files = []
    for n, stays in ((1, first), (2, second)):
        path = FROZEN / f"sweep_{n}.json"
        with _real_open(path, "w", encoding="utf-8") as f:
            json.dump({"estadias": stays}, f, ensure_ascii=False, indent=1)
            f.write("\n")
        files.append(str(path))
    out = []

    q_inputs = [[100.0], [100.0, 200.0], [310.0, 120.0, 450.0, 200.0], [float(x) for x in range(1, 12)],
                [399.5, 400.5, 401.0, 380.0, 1200.0, 640.0, 512.25]]
    q_cases = [{"input": {"v": v, "p": p}, "expected": fd.quantil(v, p)}
               for v in q_inputs for p in (0, 10, 25, 40, 50, 65, 75, 90, 100)]
    out.append(write("faixas_dono.quantil", "faixas_dono.quantil", "Ritmo\\Domain\\Market\\Series\\Percentile::of",
                     "Percentile p (0-100) with linear interpolation, rank (n - 1) * p / 100; amounts within 0.01.",
                     q_cases))

    r_inputs = [({"r_hidro": 1.5, "r_hidro_fonte": "medido"}, 1.395), ({}, 1.395), ({"r_hidro": None}, 1.395),
                ({"r_hidro": 2.6, "r_hidro_fonte": "estimado (incerto)"}, 1.395),
                ({"r_hidro": 1.8, "r_hidro_fonte": "estimado (incerto)"}, 1.395),
                ({"r_hidro": 2.6, "r_hidro_fonte": "medido"}, 1.395), ({"r_hidro": 0}, 1.2)]
    r_cases = [{"input": {"competitor": h, "default": pad}, "expected": fd.r_hidro(h, pad)} for h, pad in r_inputs]
    out.append(write("faixas_dono.r_hidro", "faixas_dono.r_hidro", "Ritmo\\Domain\\Market\\Series\\ComparableFactor",
                     "Measured or default factor; an uncertain factor above 2.0 is capped at 2.0. In the plugin: "
                     "competitor.comparable_factor, factor_source estimated, market.comparable.estimated_factor_cap.",
                     r_cases))

    peers = {"r_hidro_padrao": cad["r_hidro_padrao"], "hoteis": [h for h in cad["hoteis"] if h["nota"] < 9.0]}
    e_cases = []
    for stay in first[:6] + second[:1]:
        for group, catalogue in (("all", cad), ("peer", peers)):
            hid, cru = fd.estadia_por_noite(stay, catalogue)
            e_cases.append({"input": {"stay": stay, "group": group},
                            "expected": {"comparable": hid, "cheapest": cru}})
    out.append(write("faixas_dono.estadia_por_noite", "faixas_dono.estadia_por_noite",
                     "Ritmo\\Domain\\Market\\Series\\StayValues",
                     "Per competitor of a stay, in the order of the stay's list: cheapest = total / nights; comparable = "
                     "cheapest x factor. Own hotels, competitors outside the catalogue and rows without a price are "
                     "left out. group 'peer' = the competitors tagged peer (here: rating below 9.0). The catalogue is "
                     "in faixas_dono.construir.json (input.catalog).", e_cases))

    with mock.patch.object(fd, "TIPOS", TYPES), mock.patch.object(fd, "NOSSOS", OWN), \
            mock.patch.object(fd, "date", FrozenDate), mock.patch.object(fd, "carregar_cfg", lambda: {}):
        fx = {}
        c_cases = []
        for min_n in (15, 40):
            built = fd.construir(files, cad, types, min_n=min_n)
            built.pop("versao", None)
            for e in built["estadias"]:
                e.pop("fonte", None)
                e.pop("rotulo", None)
            c_cases.append({"input": {"files": [Path(p).name for p in files], "catalog": cad, "types": types,
                                      "min_n": min_n}, "expected": built})
            if min_n == 15:
                fx = built
    out.append(write("faixas_dono.construir", "faixas_dono.construir", "Ritmo\\Domain\\Market\\Series\\SeriesBuilder",
                     "Sweep files in frozen/sweep_1.json and frozen/sweep_2.json (a stay already seen in an earlier "
                     "file is skipped: the first file wins; the plugin keeps each competitor's newest price instead, "
                     "divergence MK-X1). Per stay with at least min_n comparable values: n, percentiles 25/50/65/75/90 "
                     "of the comparable ('hidro') and cheapest ('lista') values rounded half to even, and the sorted "
                     "comparable values. Per type: stays, months, median competitors, small-sample flag (fewer than 4 "
                     "stays or a median below 40 competitors) and the type table = median of the monthly medians. "
                     "Types are forced per stay ('ignorar' drops a stay).", c_cases))

    m_cases = []
    for t in TYPES:
        es = [e for e in fx["estadias"] if e["tipo"] == t]
        if not es:
            continue
        for campo in ("hidro", "lista"):
            for p in (25, 50, 65, 75, 90):
                slim = [{"checkin": e["checkin"], campo: e[campo]} for e in es]
                m_cases.append({"input": {"stays": slim, "field": campo, "p": p},
                                "expected": fd.mediana_por_mes(es, campo, p)})
    out.append(write("faixas_dono.mediana_por_mes", "faixas_dono.mediana_por_mes",
                     "Ritmo\\Domain\\Market\\Series\\TypeTable",
                     "Median of the percentile within each check-in month, then the median of those medians, rounded "
                     "half to even.", m_cases))

    origin_code = [("medida na noite", "night"), ("medida nas estadias que contêm a noite", "containing"),
                   ("medida em noite vizinha", "neighbour"), ("tabela do tipo", "type_table")]
    n_cases = []
    d = dt.date(2026, 11, 28)
    while d <= dt.date(2027, 2, 24):
        t = night_type(d)
        if t in fx["por_tipo"]:
            values, origin = fd.regua_noite(fx, d, t)
            code = next(c for prefix, c in origin_code if origin.startswith(prefix))
            n_cases.append({"input": {"night": d.isoformat(), "type": t},
                            "expected": {"values": values, "origin": code, "origin_text": origin}})
        d += dt.timedelta(days=1)
    out.append(write("faixas_dono.regua_noite", "faixas_dono.regua_noite", "Ritmo\\Domain\\Market\\Series\\NightSeries",
                     "The comparable series of a night from the stays of faixas_dono.construir (min_n 15): stays of "
                     "the same type starting on the night with n >= 40 (median per percentile); else stays containing "
                     "the night; else the nearest stays of the type within 3 days (only those at the smallest "
                     "distance); else the type table. Values rounded half to even. origin_text is the engine's own.",
                     n_cases))

    sample = sorted(fx["estadias"][0]["valores_hidro"])
    p_cases = [{"input": {"x": x, "v": sample}, "expected": fd.percentil_de(x, sample)}
               for x in (0.0, sample[0], sample[len(sample) // 2], (sample[3] + sample[4]) / 2, sample[-1], 99999.0)]
    p_cases.append({"input": {"x": 500.0, "v": []}, "expected": fd.percentil_de(500.0, [])})
    out.append(write("faixas_dono.percentil_de", "faixas_dono.percentil_de",
                     "Ritmo\\Domain\\Market\\Series\\PricePosition::percentile",
                     "Share of values below x plus half the ties, x 100; an empty list gives NaN (the plugin: null).",
                     p_cases))
    bands = {"F1": 400.0, "F2": 520.0, "F3": 610.0, "F4": 700.0, "F5": 900.0}
    pos_cases = [{"input": {"value": v, "bands": bands, "tolerance": tol}, "expected": fd.posicao(v, bands, tol)}
                 for v in (300.0, 380.0, 400.0, 419.0, 470.0, 600.0, 640.0, 905.0, 960.0, 1000.0)
                 for tol in (0.05, 0.0)]
    out.append(write("faixas_dono.posicao", "faixas_dono.posicao", "Ritmo\\Domain\\Market\\Series\\PricePosition::band",
                     "Where an amount sits among the band values: '≈Fn' within tolerance x value, 'abaixo da F1', "
                     "'acima da F5', 'entre Fa e Fb'. The plugin returns {kind: near|below|above|between, bands}.",
                     pos_cases))
    return out


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    for guard in GUARDS:
        guard.start()
    try:
        sys.path.insert(0, str(ENGINE))
        import faixas_dono  # noqa: E402

        files = market_cases(faixas_dono)
    finally:
        for guard in reversed(GUARDS):
            guard.stop()
    manifest = {
        "generated_for": FROZEN_TODAY.isoformat(),
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": git("status", "--porcelain", "--", ENGINE_PATH + "/*.py") != "",
        "python": platform.python_version(),
        "frozen_inputs": sorted(p.name for p in FROZEN.iterdir()),
        "tolerance": {"amounts": 0.01, "percentiles_and_counts": "exact"},
        "files": dict(files),
    }
    with _real_open(OUT / "MANIFEST.json", "w", encoding="utf-8") as f:
        f.write(json.dumps(manifest, ensure_ascii=False, indent=1) + "\n")
    print(json.dumps(manifest["files"], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())

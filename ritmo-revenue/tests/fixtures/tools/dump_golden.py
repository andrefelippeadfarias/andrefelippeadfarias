#!/usr/bin/env python3
"""Dumps the level-1 golden fixtures of the rule engine.

Runs the functions of the reference Python engine (read from the repository, never changed)
with frozen inputs and writes, for each function, a JSON file

    {"function": ..., "php_target": ..., "notes": ..., "cases": [{"input": {...}, "expected": ...}]}

into tests/fixtures/golden/level1/. PHPUnit loads these files and requires the PHP port to return
the same results (amounts within 0.01, percentages and integers exactly).

Inputs are frozen: the engine configuration comes from tests/fixtures/frozen/quartos.json and the
market anchor from tests/fixtures/frozen/ancora_mercado.json (see freeze_inputs.py). No function
dumped here reads the clock: the ones that need a date or an instant get FROZEN_TODAY and
FROZEN_NOW, both recorded in the manifest. Calendars, bookings and ledgers are synthetic, drawn
from seeded generators.

The engine core of milestone 1 (pace.plano.core) is one whole run of pace.plano per scenario. The
rules of later milestones are kept out of it: the configuration is used without them (core_cfg),
there are no owner orders, and the two passes that belong to those milestones are replaced in
memory while the run lasts (core_scenario). Nothing is ever written to the engine's folder.
Case inputs use the engine's own argument names and vocabulary; MANIFEST.json maps the vocabulary
to the plugin's (semana -> weekday, frente -> ahead, ...). NaN is written as the string "NaN".

Usage: python3 tests/fixtures/tools/dump_golden.py [--generated-at YYYY-MM-DD]
"""

from __future__ import annotations

import argparse
import copy
import datetime as dt
import hashlib
import json
import math
import platform
import random
import re
import subprocess
import sys
from pathlib import Path
from unittest import mock

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
import beds24  # noqa: E402
import ocupacao  # noqa: E402
import pace  # noqa: E402
import plano_dono  # noqa: E402
import validar_plano  # noqa: E402
from comum import carregar_quartos, periodo_feriado  # noqa: E402
from vocabulary import PROPERTY_ID, VOCABULARY  # noqa: E402

ENGINE_PATH = ENGINE.relative_to(REPO).as_posix()
FROZEN_TODAY = "2026-11-11"
FROZEN_NOW = "2026-11-11T22:53:00+00:00"  # 19:53 at the properties: still the 11th there
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
    days = [-7, -1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 14, 15, 29, 30, 31, 32, 45, 60, 90, 120, 365]
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


def cases_tipo_noite(cfg: dict) -> list[dict]:
    """Every night from a week before the first configured period to a week after the last one: each
    weekday in and out of a period, and both sides of every period's first and last night."""
    first = min(dt.date.fromisoformat(f["inicio"]) for f in cfg["feriados"]) - dt.timedelta(days=7)
    last = max(dt.date.fromisoformat(f["fim"]) for f in cfg["feriados"]) + dt.timedelta(days=7)
    out = []
    for offset in range((last - first).days + 1):
        night = first + dt.timedelta(days=offset)
        period = periodo_feriado(cfg, night)
        out.append({"input": {"d": night.isoformat()},
                    "expected": {"tipo": ancora_mercado.tipo_noite(cfg, night), "feriado": period["nome"] if period else None}})
    return out


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
        values += [0.0, 0.01, base * 11]  # -100%, just above it and the pricing system's upper limit of +1000%
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


# ---------- the engine core of milestone 1 (floors, anchors, one decision per room night)
def core_cfg(cfg: dict) -> dict:
    """The configuration without the rules of later milestones, which the parity preset leaves out too: dated owner
    exceptions to the safety limit, per-room holiday floors and the sales ladder."""
    core = copy.deepcopy(cfg)
    core["limites_seguranca"]["excecoes_dono"] = []
    for period in core["feriados"]:
        period.pop("piso_real_por_quarto", None)
    core["parametros"]["pace"]["escada_vendas"] = {"ativa": False}
    core["por_curto"] = {q["curto"]: q for q in core["quartos"]}
    core["por_id"] = {q["id"]: q for q in core["quartos"]}
    return core


def nights(first: str, last: str) -> list[dt.date]:
    start, end = dt.date.fromisoformat(first), dt.date.fromisoformat(last)
    return [start + dt.timedelta(days=i) for i in range((end - start).days + 1)]


def cases_fator_publico(cfg: dict) -> list[dict]:
    return [{"input": {"pousada": p}, "expected": pace.fator_publico(cfg, p)} for p in PROPERTY_ID]


def cases_pisos_enviados(cfg: dict) -> list[dict]:
    """Every room on every night of 110 days: weekdays, weekends and each holiday period, with no owner order."""
    out = []
    with plano_dono.com_ordens({}):
        for q in cfg["quartos"]:
            for night in nights("2026-11-11", "2027-02-28"):
                piso, origem = pace.pisos_enviados(cfg, q["curto"], night)
                out.append({"input": {"curto": q["curto"], "d": night.isoformat()}, "expected": {"piso": piso, "origem": origem}})
    return out


def cases_limite_seguranca(cfg: dict) -> list[dict]:
    night = dt.date.fromisoformat(FROZEN_TODAY)
    return [{"input": {"curto": q["curto"]}, "expected": pace.limite_seguranca(cfg, q["curto"], night)} for q in cfg["quartos"]]


def cases_piso_absoluto(cfg: dict) -> list[dict]:
    today = dt.date.fromisoformat(FROZEN_TODAY)
    validator = validar_plano.Validador(cfg, today, ordens={})
    return [{"input": {"curto": q["curto"]}, "expected": validator.piso_absoluto(q, today)} for q in cfg["quartos"]]


def chained_rooms(cfg: dict) -> set:
    """Rooms whose highest floor the reference caps by a chain of relations between rooms (a room priced between two
    others, and the lower of the two): that chain belongs to a later milestone."""
    chained = {q["curto"] for q in cfg["quartos"] if q.get("entre")}
    return chained | {q["entre"][0] for q in cfg["quartos"] if q.get("entre")}


def cases_teto_anuncio(cfg: dict) -> list[dict]:
    """Rooms whose highest floor depends only on their own listing (see chained_rooms)."""
    return [{"input": {"curto": q["curto"]}, "expected": pace.teto_anuncio(cfg, q["curto"])}
            for q in cfg["quartos"] if q["curto"] not in chained_rooms(cfg)]


def cases_substitui(cfg: dict) -> list[dict]:
    today = dt.date.fromisoformat(FROZEN_TODAY)
    max_age = int(cfg["parametros"]["pace"]["idade_max_dias"])
    variants = [{"busca_em": captured, "n": n, "p50_medido": measured}
                for captured in (None, "2026-10-25", "2026-11-01", "2026-11-05", "2026-11-11")
                for n in (0, 10, 18) for measured in (False, True)]
    out = []
    for novo in variants:
        for velho in variants:
            args = [{k: v for k, v in x.items() if v is not None} for x in (novo, velho)]
            out.append({"input": {"novo": novo, "velho": velho, "hoje": FROZEN_TODAY, "idade_max": max_age},
                        "expected": ancora_mercado.substitui(args[0], args[1], today, max_age)})
    return out


def anchor_nights(cfg: dict, anchor_file: dict, only_type: str | None = None) -> dict:
    """The frozen nights as the anchor builder takes them, each typed by today's calendar."""
    out = {}
    for key, night in anchor_file["noites"].items():
        tipo = ancora_mercado.tipo_noite(cfg, dt.date.fromisoformat(key))
        if only_type is None or tipo == only_type:
            out[key] = {**{k: night[k] for k in ANCHOR_KEYS}, "n": night["n"], "busca_em": night["busca_em"], "tipo": tipo}
    return out


def synthetic_anchor_nights(cfg: dict, seed: int) -> dict:
    """Many nights per night type and month, so that the monthly medians take odd and even counts."""
    rng = random.Random(seed)
    out = {}
    for night in nights("2026-11-12", "2027-01-20"):
        if rng.random() < 0.45:
            continue
        points = sorted(rng.randint(180, 2400) for _ in ANCHOR_KEYS)
        out[night.isoformat()] = {**dict(zip(ANCHOR_KEYS, points)), "n": rng.randint(3, 40),
                                  "busca_em": (dt.date(2026, 11, 1) + dt.timedelta(days=rng.randint(0, 10))).isoformat(),
                                  "tipo": ancora_mercado.tipo_noite(cfg, night)}
    return out


def build_anchor(cfg: dict, noites: dict) -> dict:
    """ancora_mercado.construir with no new search: keeps the nights and recomputes the monthly fallbacks."""
    return ancora_mercado.construir(cfg, [], dt.date.fromisoformat(FROZEN_TODAY), {"noites": noites})


def cases_construir(cfg: dict, anchor_file: dict) -> list[dict]:
    out = []
    for noites in (anchor_nights(cfg, anchor_file), synthetic_anchor_nights(cfg, 20261115), synthetic_anchor_nights(cfg, 20261116)):
        public = {key: {k: v for k, v in night.items() if k != "tipo"} for key, night in noites.items()}
        out.append({"input": {"noites": public}, "expected": build_anchor(cfg, noites)["por_tipo"]})
    return out


def anchor_of(anchor: dict, cfg: dict, night: dt.date) -> dict | None:
    """The anchor of a night and where it came from: its own search ("propria"), the fallback of its night type in its
    month ("mes") or in the closest month ("mes_proximo"); None when there is no anchor."""
    found, origin = ancora_mercado.ancora_da_noite(anchor, cfg, night)
    if found is None:
        return None
    kind = "propria" if origin == "busca própria" else ("mes_proximo" if origin.endswith("(mais próximo)") else "mes")
    return {**{k: found[k] for k in ANCHOR_KEYS}, "n": int(found.get("n") or 0), "busca_em": found.get("busca_em"), "origem": kind}


def cases_ancora_da_noite(cfg: dict, anchor_file: dict) -> list[dict]:
    """"todas": every frozen night. "semana": only the weekday nights, so the other night types have no anchor."""
    out = []
    for name, only_type in (("todas", None), ("semana", "semana")):
        anchor = build_anchor(cfg, anchor_nights(cfg, anchor_file, only_type))
        for night in nights("2026-10-01", "2027-03-31"):
            out.append({"input": {"noites": name, "d": night.isoformat()}, "expected": anchor_of(anchor, cfg, night)})
    return out


CORE_ROOMS = ("Q7", "Double", "Q2", "VK7", "VK2", "Balcony")  # the single-unit room has a relation rule inside the core
HELD_AT_FLOOR = re.compile(r"presa no piso \((.*) = R\$ (-?\d+) enviados, público ~R\$ (-?\d+), percentil (-?\d+)\)")


def core_calendars(cfg: dict, rng: random.Random, today: dt.date, days: int) -> dict:
    cals = {}
    for curto in CORE_ROOMS:
        q = cfg["por_curto"][curto]
        cal = {}
        for k in range(days + 1):
            price = float(round(q["base"] * rng.uniform(0.4, 1.7)))
            roll = rng.random()
            if roll < 0.04 and curto not in chained_rooms(cfg):
                price = float(round(q["max"] * rng.uniform(1.02, 1.1)))  # above the listing maximum
            elif roll < 0.05:
                price = 0.0  # no price yet
            elif roll < 0.15:
                price = round(price + rng.random(), 2)
            sold = rng.choice([0, 0, 1, 1, 2, 3, q["unidades"] - 1, q["unidades"], q["unidades"] + 1])
            sold = max(0, min(sold, q["unidades"] + 1))
            row = {"price": price, "mu_occupancy": f"{sold}/{q['unidades']}"}
            in_force = rng.random()
            if in_force < 0.25:
                row["min_price"], row["max_price"] = price, price  # locked: floor = ceiling
            elif in_force < 0.5:
                row["min_price"], row["max_price"] = float(q["min"]), float(q["max"])
            elif in_force < 0.65:
                row["min_price"], row["max_price"] = round(price * 0.8, 2), round(price * 1.1, 2)
            cal[(today + dt.timedelta(days=k)).isoformat()] = row
        cals[curto] = cal
    return cals


def core_ledger(cals: dict, rng: random.Random, now: dt.datetime) -> dict:
    steps = []
    for curto, cal in cals.items():
        for noite in cal:
            if rng.random() < 0.35:
                steps.append({"quarto": curto, "noite": noite, "direcao": rng.choice(["sobe", "desce"]),
                              "gravado_em": (now - dt.timedelta(hours=rng.choice([2, 23, 25, 50]))).isoformat(timespec="seconds"),
                              "chegou_ao_canal": rng.choice([False, "2026-11-11T12:00+00:00"])})
    return {"passos": steps}


def core_line(linha: dict) -> dict:
    held = HELD_AT_FLOOR.search(linha["preso_no_piso"] or "")
    blocked = linha.get("cadencia") or ""
    return {
        "status": linha["status"], "vendido_pct": linha["vendido_pct"], "curva_pct": linha["curva_pct"],
        "percentil_alvo": linha["percentil_alvo"], "alvo_publico": linha["alvo_publico"], "alvo_enviado": linha["alvo_enviado"],
        "atual_publico": linha["atual_publico"], "percentil_atual": linha["percentil_atual"], "novo": linha["novo"],
        "direcao": linha["direcao"], "ancora_fraca": linha["ancora_fraca"],
        "preso_no_piso": None if not held else {"origem": held.group(1), "piso": int(held.group(2)),
                                                "publico": int(held.group(3)), "percentil": int(held.group(4))},
        "cadencia": "nao_chegou" if "não chegou ao canal" in blocked else ("menos_24h" if "menos de 24 h" in blocked else None),
    }


def core_scenario(cfg: dict, anchor: dict, seed: int, days: int) -> list[dict]:
    """One run of pace.plano over synthetic calendars, one case per room night.

    Owner orders are empty; the owner's dated plan (plano_da_noite) and the pass of relations between rooms
    (coerencia) are switched off in memory, because both belong to a later milestone. Sold-out nights are left to
    the protection rule of that milestone (regra_j_ativa only keeps the reference from writing a protection item)."""
    rng = random.Random(seed)
    today = dt.date.fromisoformat(FROZEN_TODAY)
    now = dt.datetime.fromisoformat(FROZEN_NOW)
    cals = core_calendars(cfg, rng, today, days)
    ledger = core_ledger(cals, rng, now)
    with mock.patch.object(pace, "plano_da_noite", lambda *args, **kwargs: {}), \
            mock.patch.object(pace, "coerencia", lambda cfg_, cals_, finais, itens, *args, **kwargs: itens):
        result = pace.plano(cfg, anchor, copy.deepcopy(cals), today, {}, copy.deepcopy(ledger), dias=days, agora=now,
                            regra_j_ativa=True)
    lines = {(x["quarto"], x["data"]): x for x in result["linhas"]}
    left_out = {(x["quarto"], x["data"]): x for x in result["fora"]}
    items = {(x["quarto"], x["data"]): x for x in result["itens"]}
    out = []
    for curto in CORE_ROOMS:
        q = cfg["por_curto"][curto]
        for noite, row in cals[curto].items():
            night = dt.date.fromisoformat(noite)
            sold = int(row["mu_occupancy"].split("/")[0])
            group = pace.ocupacao_pousada(cfg, cals, noite)[q["pousada"]]
            basis = tuple(group) if q["unidades"] <= 2 and group[1] else (sold, q["unidades"])
            last = pace.ultimo_passo(ledger, curto, noite)
            key = (curto, noite)
            item = items.get(key)
            if item is not None and (item["pace"]["vendidas"], item["pace"]["total"]) != basis:
                raise SystemExit(f"{key}: the pace basis of the tool {basis} is not the engine's")
            if key in left_out:
                reason = left_out[key]["motivo"]
                expected = {"fora": "esgotada" if reason.startswith("esgotada") else ("sem_ancora" if "sem âncora" in reason else reason)}
            else:
                expected = {"fora": None, "linha": core_line(lines[key]),
                            "item": None if item is None else {"min_price": item["min_price"], "max_price": item.get("max_price"),
                                                               "direcao": item["pace"]["direcao"]}}
            same_property = {other: [int(x) for x in cals[other][noite]["mu_occupancy"].split("/")]
                             for other in CORE_ROOMS if cfg["por_curto"][other]["pousada"] == q["pousada"]}
            out.append({"input": {
                "curto": curto, "data": noite, "hoje": FROZEN_TODAY, "agora": FROZEN_NOW, "price": row["price"],
                "vendidas": sold, "unidades": q["unidades"], "min_price": row.get("min_price"), "max_price": row.get("max_price"),
                "pousada_noite": same_property, "base_vendidas": basis[0], "base_total": basis[1],
                "anc": anchor_of(anchor, cfg, night),
                "ultimo_passo": None if last is None else {"direcao": last["direcao"], "gravado_em": last["gravado_em"],
                                                           "chegou_ao_canal": bool(last["chegou_ao_canal"])},
            }, "expected": expected})
    return out


def cases_plano_core(cfg: dict, anchor_file: dict) -> list[dict]:
    anchor = build_anchor(cfg, anchor_nights(cfg, anchor_file))
    out = core_scenario(cfg, anchor, 20261117, 60) + core_scenario(cfg, anchor, 20261118, 60)
    out += core_scenario(cfg, {"noites": {}, "por_tipo": {}}, 20261119, 3)  # no market anchor at all
    # most nights with an anchor of their own, fresh and large enough: steps at full strength
    out += core_scenario(cfg, build_anchor(cfg, synthetic_anchor_nights(cfg, 20261127)), 20261128, 60)
    return out


# ---------- validar_plano.montar_payload: floors and ceilings as whole percentages over the base
def cases_montar_payload(cfg: dict) -> list[dict]:
    out = []
    rng = random.Random(20261120)
    for q in cfg["quartos"]:
        base = float(q["base"])
        pairs = [(base * 0.69, None), (None, base * 1.2), (base, base), (base * 0.9005, base * 0.9005), (base * 0.9995, base * 0.9995),
                 (base * 1.004, base * 1.006), (base * 0.5, base * 2.0), (round(base * 0.731, 2), round(base * 0.737, 2))]
        pairs += [(low, round(low * rng.uniform(1.0, 1.02), 2)) for low in (round(rng.uniform(0.4, 1.8) * base, 2) for _ in range(12))]
        for low, high in pairs:
            item = {"tipo": "data", "quarto": q["curto"], "data": FROZEN_TODAY, "motivo": "golden"}
            if low is not None:
                item["min_price"] = low
            if high is not None:
                item["max_price"] = high
            override = validar_plano.montar_payload(cfg, [item])["update_listing_date_overrides"][0]["overrides"][0]
            out.append({"input": {"curto": q["curto"], "min_price": low, "max_price": high},
                        "expected": {"min_price": override.get("min_price"), "max_price": override.get("max_price")}})
    return out


# ---------- ocupacao.py and beds24.py: occupancy windows and sold units of the PMS against the pricing system
def sold_calendar(cfg: dict, rng: random.Random, today: dt.date, days: int, gaps: float) -> dict:
    """{room: {night: "sold/units"}}; some nights overbooked and, with `gaps`, some nights missing."""
    out = {}
    for q in cfg["quartos"]:
        row = {}
        for k in range(days):
            if rng.random() < gaps:
                continue
            sold = min(q["unidades"] + 1, max(0, round(rng.gauss(q["unidades"] * 0.45, q["unidades"] * 0.4))))
            row[(today + dt.timedelta(days=k)).isoformat()] = f"{sold}/{q['unidades']}"
        out[q["curto"]] = row
    return out


def cases_ocupacao(cfg: dict) -> list[dict]:
    today = dt.date.fromisoformat(FROZEN_TODAY)
    out = []
    for seed, gaps in ((20261121, 0.0), (20261122, 0.1), (20261123, 0.4)):
        calendar = sold_calendar(cfg, random.Random(seed), today, 75, gaps)
        entries = {curto: {"id": curto, "data": [{"date": night, "price": 1000, "multi_unit_occupancy": mu} for night, mu in row.items()]}
                   for curto, row in calendar.items()}
        result = ocupacao.calcular(entries, cfg, today, 0)
        groups = {name: windows for name, windows in result["janelas"].items() if name in cfg["por_curto"] or name in PROPERTY_ID}
        out.append({"input": {"hoje": FROZEN_TODAY, "calendario": calendar}, "expected": groups})
    return out


def cases_comparar(cfg: dict) -> list[dict]:
    """Bookings of the PMS (every status) against the units the pricing system counts as sold."""
    today = dt.date.fromisoformat(FROZEN_TODAY)
    last = today + dt.timedelta(days=30)
    room_ids = {code: room_id for room_id, (code, _) in beds24.QUARTOS.items()}
    out = []
    for seed in (20261124, 20261125, 20261126):
        rng = random.Random(seed)
        bookings = []
        for number in range(70):
            q = rng.choice(cfg["quartos"])
            arrival = today + dt.timedelta(days=rng.randint(-4, 31))
            bookings.append({"id": str(number + 1), "quarto": q["curto"], "arrival": arrival.isoformat(),
                             "departure": (arrival + dt.timedelta(days=rng.randint(1, 5))).isoformat(),
                             "status": rng.choice(["confirmed", "confirmed", "confirmed", "new", "request", "cancelled", "black", "inquiry"]),
                             "roomQty": rng.choice([1, 1, 1, 2])})
        raw = [{**{k: v for k, v in b.items() if k != "quarto"}, "roomId": room_ids[b["quarto"]]} for b in bookings]
        sold = beds24.noites(raw, today, last)
        pricing = {}
        for q in cfg["quartos"]:
            pricing[q["curto"]] = {}
            for k in range(-2, 33):
                night = (today + dt.timedelta(days=k)).isoformat()
                count = sold.get((q["curto"], night), 0)
                if rng.random() < 0.15:
                    count = max(0, count + rng.choice([-1, 1]))
                pricing[q["curto"]][night] = count
        coleta = {"rooms": {curto: {"days": [{"date": night, "multi_unit_occupancy": f"{count}/{cfg['por_curto'][curto]['unidades']}"}
                                             for night, count in row.items()]} for curto, row in pricing.items()}}
        out.append({"input": {"reservas": bookings, "pricelabs": pricing, "de": today.isoformat(), "ate": last.isoformat()},
                    "expected": beds24.comparar(raw, coleta, today, last)})
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


def git(*args: str) -> str:
    """Output of a read-only git command run on the repository."""
    done = subprocess.run(["git", "-C", str(REPO), *args], check=True, capture_output=True, text=True)
    return done.stdout.strip()


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
    core_note = cfg_note + " " + " ".join((core_cfg.__doc__ or "").split())
    core = core_cfg(cfg)
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
        {"function": "ancora_mercado.tipo_noite", "php_target": rules + "::nightType(night), holidayOn(night)",
         "notes": cfg_note + " expected = {tipo, feriado (name of the period of comum.periodo_feriado, or null)}.",
         "cases": cases_tipo_noite(cfg)},
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
        {"function": "pace.fator_publico", "php_target": "Ritmo\\Domain\\Market\\PriceFactor::factor(room, public, type)",
         "notes": cfg_note, "cases": cases_fator_publico(cfg)},
        {"function": "pace.pisos_enviados", "php_target": "Ritmo\\Domain\\Pricing\\Floors::hardFloor(room, night)",
         "notes": core_note + " No owner order is active. expected = {piso, origem}; origem starts with one of the labels of "
                  "the manifest vocabulary floor_label.", "cases": cases_pisos_enviados(core)},
        {"function": "pace.limite_seguranca", "php_target": "Ritmo\\Domain\\Pricing\\Floors::safetyLimit(room)",
         "notes": core_note + " Not rounded by the engine; the plugin keeps two decimals.", "cases": cases_limite_seguranca(core)},
        {"function": "validar_plano.piso_absoluto", "php_target": "Ritmo\\Domain\\Pricing\\Floors::safetyLimit(room)",
         "notes": core_note, "cases": cases_piso_absoluto(core)},
        {"function": "pace.teto_anuncio", "php_target": "Ritmo\\Domain\\Pricing\\Floors::highestFloor(room)",
         "notes": "Only the rooms whose result depends on their own listing alone (see cases_teto_anuncio).",
         "cases": cases_teto_anuncio(cfg)},
        {"function": "ancora_mercado.substitui", "php_target": "Ritmo\\Domain\\Market\\AnchorBuilder::replaces(new, old, today)",
         "notes": "busca_em null = no capture date. p50_medido maps to the anchor origin: true = measured, false = fallback "
                  "(an estimate). Anchors pinned by an owner order (ordem_id) belong to a later milestone.",
         "cases": cases_substitui(cfg)},
        {"function": "ancora_mercado.construir", "php_target": "Ritmo\\Domain\\Market\\AnchorBuilder::monthlyFallbacks(anchors)",
         "notes": cfg_note + " Called with no new search: expected is por_tipo, the monthly fallbacks of the nights given "
                  "(the first case holds the frozen nights). Night types come from today's calendar.",
         "cases": cases_construir(cfg, anchor_file)},
        {"function": "ancora_mercado.ancora_da_noite", "php_target": "Ritmo\\Domain\\Market\\AnchorLookup::forNight(night)",
         "notes": cfg_note + ' noites "todas" = the nights of tests/fixtures/frozen/ancora_mercado.json; "semana" = only its '
                  "weekday nights. expected = the anchor with n (0 for a fallback), busca_em and origem (propria, mes, "
                  "mes_proximo), or null.", "cases": cases_ancora_da_noite(cfg, anchor_file)},
        {"function": "pace.plano.core", "php_target": "Ritmo\\Domain\\Pricing\\PaceEngine::decide(context)",
         "notes": core_note + " " + " ".join((core_scenario.__doc__ or "").split()) + " pousada_noite = [sold, units] of every room of the same "
                  "property that night; base_vendidas/base_total = what the engine measured the pace over. expected = {fora "
                  "(esgotada, sem_ancora or null), linha (the diagnosis of the night, as the engine rounds it), item (what "
                  "would be written, or null)}.",
         "cases": cases_plano_core(core, anchor_file)},
        {"function": "validar_plano.montar_payload", "php_target": "Ritmo\\Domain\\Plan\\PercentRounding",
         "notes": "expected = min_price and max_price of the override, as the whole percentages over the base the engine "
                  "sends (strings), null when the item does not set them.", "cases": cases_montar_payload(cfg)},
        {"function": "ocupacao.calcular", "php_target": "Ritmo\\Domain\\Report\\OccupancyWindows",
         "notes": cfg_note + ' calendario = {room: {night: "sold/units"}}; expected = the windows of each room and of each '
                  "property: {window days: {vendidas, total, pct}}.", "cases": cases_ocupacao(cfg)},
        {"function": "beds24.comparar", "php_target": "Ritmo\\Domain\\Report\\PmsPricingMismatch",
         "notes": "reservas = bookings of the PMS (quarto = room code; status in the manifest vocabulary booking_status); "
                  "pricelabs = {room: {night: units sold}}; nights from de (inclusive) to ate (exclusive). expected = the "
                  "nights where the two differ.", "cases": cases_comparar(cfg)},
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

    engine_dirty = git("status", "--porcelain", "--", ENGINE_PATH) != ""
    if engine_dirty:
        print(f"WARNING: {ENGINE_PATH} has uncommitted changes; these goldens do not match any commit", file=sys.stderr)
    write_json(GOLDEN / "MANIFEST.json", {
        "generated_at": args.generated_at,
        "repo_commit": git("rev-parse", "HEAD"),
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": engine_dirty,
        "python": platform.python_version(),
        "frozen_today": FROZEN_TODAY,
        "frozen_now": FROZEN_NOW,
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

#!/usr/bin/env python3
"""Level-2 golden scenarios: whole plans of the reference engine, end to end.

Each scenario is one run of the reference engine's plan, as the routine runs it (`pace.py plano --sem-registro`), but
through its functions with frozen inputs: pace.plano (pace, sold-out protection, the owner's ladder, owner orders,
relations), ajustar_tetos (the delete-first plan), sem_efeito, then the validator (validar_plano.Validador, once on the
delete-first plan and once on the main plan, as the routine validates them), montar_payload and fatiar_payload.fatiar.
Nothing is ever written into the engine's folder and the clock is never read (the guards of golden_plan.py).

Inputs (tests/fixtures/golden/level2/frozen/<scenario>.json, written by this tool from seeded generators and the
targeted changes of SCENARIOS): the pricing calendar of every room (the recalculation format), the date rules in force
(get_listing_date_overrides), the step ledger, the nights blocked in the PMS, the owner orders and the light-run filter;
plus the M1 frozen configuration and market anchors, the frozen ruler of the ladder and the season calendar.

Output: tests/fixtures/golden/level2/pace.plano.json (one case per scenario: the plan, the validation of both plans as
issue codes, the payloads and the slices) and its MANIFEST.json.

Usage: python3 tests/fixtures/tools/golden_level2.py [--generated-at YYYY-MM-DD]
"""

from __future__ import annotations

import argparse
import copy
import datetime as dt
import json
import platform
import random
import sys
from pathlib import Path

sys.dont_write_bytecode = True  # the engine folder must stay untouched, bytecode cache included

TOOLS = Path(__file__).resolve().parent
sys.path.insert(0, str(TOOLS))
import golden_plan as GP  # noqa: E402  (installs nothing at import; guards below)

GP.install_guards()
import comum  # noqa: E402
import dump_golden as DG  # noqa: E402
import fatiar_payload as FP  # noqa: E402
import pace  # noqa: E402
import plano_dono as PD  # noqa: E402
import validar_plano as VP  # noqa: E402

pace.hoje_brt = GP.no_clock
OUT = GP.FIXTURES / "golden" / "level2"
FROZEN_OUT = OUT / "frozen"
ROOMS = ("Q7", "Double", "Q2", "Afrodite", "VK7", "VK2", "Balcony")
STANDING_ORDERS = GP.FIXTURES / "golden" / "m2" / "relations" / "frozen" / "ordens.json"
DAY = dt.timedelta(days=1)


# ---------------------------------------------------------------- scenarios
def order(oid: str, tipo: str, quartos: list, de: str, ate: str, **extra) -> dict:
    return {"id": oid, "data": extra.pop("data", "2026-11-10"), "tipo": tipo, "quartos": quartos, "de": de, "ate": ate, **extra}


SCENARIOS = [
    {"name": "weekday_sales_ramp", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 6, "seed": 2611201,
     "note": "Thursday: weekday nights of every room, the pace step with the sales ramp, the last-minute market floor of "
             "the owner's plan from D7 to D0 and the weekend nights of the ladder at the edge."},
    {"name": "weekend_owner_plan", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 10, "seed": 2611202,
     "first": "2026-11-13",
     "note": "Weekend nights priced by the owner's ladder (Friday and Saturday), with the relations of the ladder night."},
    {"name": "holiday_period", "today": "2026-11-16", "now": "2026-11-16T13:53:00Z", "days": 7, "seed": 2611203,
     "note": "The holiday of 19-21 Nov priced by the ladder and the holiday floors, with the nights around it."},
    {"name": "sold_out_and_reopened", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 9, "seed": 2611204,
     "tweaks": [{"rooms": ["VK7", "Q7", "Balcony"], "nights": ["2026-11-16", "2026-11-17", "2026-11-18"], "sold": "all",
                 "rule": {"min_price": 5, "max_price": 5, "reason": "protection"}},
                {"rooms": ["Double"], "nights": ["2026-11-17"], "sold": "all", "rule": {"min_price": -5, "max_price": 40}},
                {"rooms": ["VK2", "Double"], "nights": ["2026-11-18"], "sold": 1,
                 "rule": {"min_price": 30, "max_price": 30, "reason": "sold out, then a cancellation"}}],
     "note": "Sold-out nights get the protection floor (the locked rule in force is deleted first); nights that were "
             "sold out and reopened leave the locked rule."},
    {"name": "light_run_filter", "today": "2026-11-12", "now": "2026-11-12T19:53:00Z", "days": 14, "seed": 2611205,
     "filter": ["VK7=2026-11-14..2026-11-16", "Balcony=2026-11-17", "Q7=2026-11-13"],
     "note": "Light run: only the nights of the filter take a pace step or a protection; the others feed the relations."},
    {"name": "orphan_nights", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 9, "seed": 2611206,
     "tweaks": [{"rooms": ["VK7", "Double"], "nights": ["2026-11-15", "2026-11-18"], "sold": "all",
                 "rule": {"min_price": 0, "max_price": 0, "min_stay": 2, "reason": "locked"}},
                {"rooms": ["VK7", "Double"], "nights": ["2026-11-14", "2026-11-17"], "sold": 0,
                 "rule": {"min_price": 0, "max_price": 0, "min_stay": 2, "check_out": "1111101", "reason": "locked"}}],
     "note": "Sold-out nights next to free nights, all under locked rules: the sold-out nights' rules are deleted first and "
             "written again with their stay; the free nights keep their rules and get their own items. No rule of a free "
             "night is rewritten here, so these inputs produce no orphan claim."},
    {"name": "occupancy_switch_80", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 8, "seed": 2611207,
     "tweaks": [{"rooms": ["VK7"], "nights": ["2026-11-16", "2026-11-17", "2026-11-19"], "sold": 6},
                {"rooms": ["VK2"], "nights": ["2026-11-16", "2026-11-17", "2026-11-19"], "sold": "all"},
                {"rooms": ["Balcony"], "nights": ["2026-11-16", "2026-11-17", "2026-11-19"], "sold": 2}],
     "note": "With 80% of the tub suites of the second property sold, the room without a tub goes to the market."},
    {"name": "owner_orders_by_date", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 12, "seed": 2611208,
     "orders": [order("dono-2026-11-10-preco-q7", "preco_por_data", ["Q7"], "2026-11-16", "2026-11-17", libera=["fixo"]),
                order("dono-2026-11-10-piso-villa", "piso_por_data", ["VK7", "VK2"], "2026-11-16", "2026-11-20",
                      pisos_enviados={"VK7": 800, "VK2": 820}, libera=["abaixo_piso"]),
                order("dono-2026-11-10-alvo-balcony", "alvo_por_data", ["Balcony"], "2026-11-15", "2026-11-23",
                      percentil=50, passo_livre=True, pisos_enviados={"Balcony": 640}, libera=["abaixo_piso"])],
     "note": "Owner orders by date: a fixed price, a floor order and a market target order without a sales ramp."},
    {"name": "frozen_nights", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 8, "seed": 2611209,
     "orders": [order("manual-2026-11-10-vk7-2026-11-14", "manual", ["VK7"], "2026-11-14", "2026-11-17"),
                order("manual-2026-11-11-balcony-2026-11-16", "manual", ["Balcony"], "2026-11-16", "2026-11-18",
                      data="2026-11-11")],
     "blocked": [["Q2", "2026-11-15"], ["Balcony", "2026-11-13"]],
     "note": "Nights frozen by a manual change of the owner and nights blocked in the PMS stay as they are."},
    {"name": "band_released_by_order", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 8, "seed": 2611211,
     "tweaks": [{"rooms": ["Balcony"], "nights": ["2026-11-16", "2026-11-17"], "sold": 0,
                 "rule": {"min_price": -45, "max_price": -45, "reason": "owner low"}}],
     "orders": [order("dono-2026-11-11-balcony-baixa", "preco_por_data", ["Balcony"], "2026-11-16", "2026-11-17",
                      libera=["fixo", "regra_g"])],
     "note": "A fixed-price order that releases the discount band: the room under the band's lower edge is a warning."},
    {"name": "range_orders_d1", "today": "2026-11-11", "now": "2026-11-11T11:00:00Z", "days": 20, "real": True,
     "note": "Real inputs of a full run (calendars, rules, ledger and the owner's range orders with the sales ramp, "
             "fallback floors and descent steps); the occupancy switch item that changes nothing is dropped (L5)."},
    {"name": "range_orders_d0", "today": "2026-11-12", "now": "2026-11-12T14:45:00Z", "days": 20, "real": True,
     "note": "Real inputs of the next day's full run: the sales ramp of the range orders from D0, a protection that "
             "writes the floor in force again on D0 (L6) and the occupancy switch without effect (L5)."},
    {"name": "small_rooms", "today": "2026-11-12", "now": "2026-11-12T16:53:00Z", "days": 12, "seed": 2611210,
     "rooms_focus": ["Afrodite", "Q2", "VK2"],
     "tweaks": [{"rooms": ["Afrodite"], "nights": ["2026-11-16", "2026-11-19"], "sold": "all"},
                {"rooms": ["Q2", "VK2"], "nights": ["2026-11-17", "2026-11-18"], "sold": 1}],
     "note": "Rooms of one and two units read with their property's tub suites, and the single room above the others."},
]


# ---------------------------------------------------------------- inputs
def night_kind(c: dict, d: dt.date) -> str:
    return "feriado" if comum.periodo_feriado(c, d) else ("fds" if d.weekday() in (4, 5) else "semana")


def calendars(c: dict, rng: random.Random, today: dt.date, days: int) -> dict:
    """The recalculation rows of every room: prices around the base by night type, units sold drawn by distance."""
    level = {"semana": (0.72, 1.08), "fds": (0.95, 1.4), "feriado": (1.1, 1.6)}
    out = {}
    for curto in ROOMS:
        q = c["por_curto"][curto]
        units = int(q["unidades"])
        cal = {}
        for k in range(days + 1):
            d = today + k * DAY
            lo, hi = level[night_kind(c, d)]
            price = float(round(float(q["base"]) * rng.uniform(lo, hi)))
            share = min(1.0, max(0.0, rng.gauss(0.55 - 0.012 * k, 0.3)))
            sold = min(units, int(round(share * units)))
            cal[d.isoformat()] = {"price": price, "mu_occupancy": f"{sold}/{units}",
                                  "min_price": float(q["min"]), "max_price": float(q["max"])}
        out[curto] = cal
    return out


def rules_in_force(c: dict, rng: random.Random, cals: dict) -> dict:
    """Date rules of about a third of the nights, as get_listing_date_overrides gives them (percent of the base)."""
    out: dict = {}
    for curto, cal in cals.items():
        q = c["por_curto"][curto]
        base = float(q["base"])
        for night, row in cal.items():
            roll = rng.random()
            if roll > 0.35:
                continue
            low = int(round((row["price"] * rng.uniform(0.85, 1.0)) / base * 100 - 100))
            rule = {"date": night, "min_price": low, "min_price_type": "percent_base", "reason": "engine"}
            if roll < 0.12:
                rule["max_price"] = int(round(row["price"] * rng.uniform(1.0, 1.15) / base * 100 - 100))
                rule["max_price_type"] = "percent_base"
            if roll < 0.2:
                rule["min_stay"] = rng.choice([1, 2])
            out.setdefault(curto, {})[night] = rule
            apply_rule(q, row, rule)
    return out


def apply_rule(q: dict, row: dict, rule: dict) -> None:
    """The calendar shows the floor and ceiling of the rule, and the price within them."""
    base = float(q["base"])
    if rule.get("min_price") is not None:
        row["min_price"] = round(base * (1 + rule["min_price"] / 100), 2)
    if rule.get("max_price") is not None:
        row["max_price"] = round(base * (1 + rule["max_price"] / 100), 2)
    row["price"] = float(min(max(row["price"], row["min_price"]), row["max_price"]))


def ledger(cals: dict, rng: random.Random, now: dt.datetime) -> dict:
    steps = []
    for curto, cal in cals.items():
        for night, row in cal.items():
            if rng.random() > 0.3:
                continue
            sold, total = (int(x) for x in row["mu_occupancy"].split("/"))
            direction = rng.choice(["sobe", "desce"])
            steps.append({"quarto": curto, "noite": night, "de": row["price"], "para": row["price"], "direcao": direction,
                          "status": rng.choice(["ritmo", "atras", "frente", "plano"]), "vendidas": max(0, sold - rng.choice([0, 0, 1])),
                          "base": "quarto", "total": total,
                          "gravado_em": (now - dt.timedelta(hours=rng.choice([3, 20, 26, 49]))).isoformat(timespec="seconds"),
                          "chegou_ao_canal": rng.choice([False, (now - dt.timedelta(hours=2)).isoformat(timespec="minutes")])})
    return {"passos": steps}


def apply_tweaks(c: dict, cals: dict, rules: dict, tweaks: list[dict]) -> None:
    for tw in tweaks:
        for curto in tw["rooms"]:
            q = c["por_curto"][curto]
            units = int(q["unidades"])
            for night in tw["nights"]:
                row = cals[curto].get(night)
                if row is None:
                    continue
                if "sold" in tw:
                    sold = units if tw["sold"] == "all" else min(units, int(tw["sold"]))
                    row["mu_occupancy"] = f"{sold}/{units}"
                if "rule" in tw:
                    rule = {"date": night, "min_price_type": "percent_base", "reason": "owner", **tw["rule"]}
                    if "max_price" in rule:
                        rule["max_price_type"] = "percent_base"
                    rules.setdefault(curto, {})[night] = rule
                    row["min_price"], row["max_price"] = float(q["min"]), float(q["max"])
                    apply_rule(q, row, rule)


def build_inputs(c: dict, s: dict) -> dict:
    rng = random.Random(s["seed"])
    today = dt.date.fromisoformat(s["today"])
    now = dt.datetime.fromisoformat(s["now"].replace("Z", "+00:00"))
    cals = calendars(c, rng, today, s["days"])
    rules = rules_in_force(c, rng, cals)
    apply_tweaks(c, cals, rules, s.get("tweaks", []))
    standing = GP.read(STANDING_ORDERS)["ordens"]
    return {"today": s["today"], "now": s["now"], "days": s["days"], "calendars": cals, "rules": rules,
            "ledger": ledger(cals, rng, now), "orders": standing + s.get("orders", []),
            "blocked": s.get("blocked", []), "filter": s.get("filter")}


def collect(cals: dict, now: str) -> dict:
    """The calendars in the collect format the validator reads (precos_da_coleta), refreshed one hour before."""
    at = (dt.datetime.fromisoformat(now.replace("Z", "+00:00")) - dt.timedelta(hours=1)).isoformat()
    return {"rooms": {curto: {"last_refreshed_at": at, "days": [
        {"date": night, "price": row["price"], "multi_unit_occupancy": row["mu_occupancy"], "min_price": row["min_price"]}
        for night, row in cal.items()]} for curto, cal in cals.items()}}


# ---------------------------------------------------------------- run the reference engine
def anchor(c: dict) -> dict:
    return DG.build_anchor(c, DG.anchor_nights(c, GP.read(GP.FROZEN / "ancora_mercado.json")))


def plan_of(c: dict, inputs: dict) -> tuple[list[dict], list[dict], list[dict]]:
    today = dt.date.fromisoformat(inputs["today"])
    now = dt.datetime.fromisoformat(inputs["now"].replace("Z", "+00:00"))
    orders = {o["id"]: o for o in copy.deepcopy(inputs["orders"])}
    cals = copy.deepcopy(inputs["calendars"])
    rules = copy.deepcopy(inputs["rules"])
    blocked = {(a, b) for a, b in inputs["blocked"]}
    flt = pace.ler_filtro_noites(inputs["filter"]) if inputs["filter"] is not None else None
    motor = c["parametros"].get("motor") or {}
    res = pace.plano(c, anchor(c), cals, today, orders, copy.deepcopy(inputs["ledger"]), blocked, inputs["days"], agora=now,
                     regra_j_ativa=motor.get("regra_j_ativa", True), regras=rules, noites_filtro=flt)
    with PD.com_ordens(orders):
        items, delete = pace.ajustar_tetos(c, res["itens"], rules, cals, hoje=today)
        items, no_effect = pace.sem_efeito(c, items, cals, rules, {(x["quarto"], x["data"]) for x in delete})
    return items, delete, no_effect


def validate(c: dict, inputs: dict, items: list[dict], deleted: set | None = None, no_effect: list | None = None) -> dict:
    today = dt.date.fromisoformat(inputs["today"])
    now = dt.datetime.fromisoformat(inputs["now"].replace("Z", "+00:00"))
    orders = {o["id"]: o for o in copy.deepcopy(inputs["orders"])}
    prices = VP.precos_da_coleta(collect(inputs["calendars"], inputs["now"]), agora=now)
    v = VP.Validador(c, today, copy.deepcopy(prices), horas_max=6, aceitar_velho=False, ordens=orders)
    v.validar(copy.deepcopy(items))
    expected = GP.classify(v, items)
    php, applied = GP.plugin_expected(c, expected, items, prices, orders, today)
    applied = sorted(set(applied) | refine(c, inputs, items, php, deleted or set(), no_effect or []))
    return {"expected": expected, "php_expected": php, "divergences": applied, "valid": not v.erros,
            "hooks": GP.hooks(c, v, items, orders, today)}


# ---------------------------------------------------------------- level-2 divergences of the plugin
def refine(c: dict, inputs: dict, items: list[dict], php: dict, deleted: set, no_effect: list) -> set:
    """The deliberate divergences of whole runs (docs/ENGINE.md 1.8), applied to the plugin's expected codes:
    L5: an occupancy switch item dropped for having no effect leaves the night at the switched price; the band check
        accepts it (warning band_below_by_switch) where the reference refuses the band (band_below_lower).
    L6: an item that writes again the floor in force, and nothing else, changes nothing: the plugin refuses it as
        no_effect (the reference writes it) and it never raises tonight's price (no raise_today of V4)."""
    applied = set()
    switched = {(x["quarto"], x["data"]) for x in no_effect if "regra dos 80%" in str(x.get("motivo", ""))}
    for entry in php["plan"]:
        if (entry[0], entry[1]) in switched and entry[3] == "band_below_lower":
            entry[2:] = ["warning", "band_below_by_switch"]
            applied.add("L5")
    for (subject, index), it in zip(GP.subjects(items), items):
        if subject != "item" or it.get("tipo") != "data":
            continue
        codes = php["item"].setdefault(str(index), [])
        if rewrites_floor(c, inputs, it) and ["error", "raise_today"] in codes:
            codes.remove(["error", "raise_today"])
            applied.add("L6")
        if (it["quarto"], it["data"]) not in deleted and changes_nothing(c, inputs, it):
            codes.append(["error", "no_effect"])
            codes.sort()
            applied.add("L6")
    php["item"] = dict(sorted(((k, v) for k, v in php["item"].items() if v), key=lambda kv: int(kv[0])))
    return applied


def in_force(inputs: dict, it: dict) -> tuple[dict | None, dict]:
    return (inputs["calendars"].get(it["quarto"]) or {}).get(it["data"]), (inputs["rules"].get(it["quarto"]) or {}).get(it["data"]) or {}


def rewrites_floor(c: dict, inputs: dict, it: dict) -> bool:
    """The floor percentage of the item is the one of the rule in force and of the calendar's floor."""
    if it.get("min_price") is None:
        return False
    q = c["por_curto"][it["quarto"]]
    row, rule = in_force(inputs, it)
    pct = VP.piso_pct(q, float(it["min_price"]))
    if rule.get("min_price") is None or rule.get("min_price_type", "percent_base") != "percent_base" or float(rule["min_price"]) != pct:
        return False
    return row is None or row.get("min_price") is None or VP.piso_pct(q, float(row["min_price"])) == pct


def changes_nothing(c: dict, inputs: dict, it: dict) -> bool:
    """The validator's effect check of the plugin: every amount it sets within one currency unit of the calendar's,
    the same minimum stay as the rule in force, and no arrival or departure days."""
    q = c["por_curto"][it["quarto"]]
    row, rule = in_force(inputs, it)
    if row is None or it.get("check_in") is not None or it.get("check_out") is not None:
        return False
    base = float(q["base"])
    floor, ceiling = it.get("min_price"), it.get("max_price")
    lo = None if floor is None else VP.piso_pct(q, float(floor))
    hi = None if ceiling is None else VP.teto_pct(q, float(ceiling))
    if lo is not None and hi is not None and hi < lo:
        hi = lo
    same = lambda pct, live: pct is None or (live is not None and abs(round(base * (1 + pct / 100), 2) - float(live)) <= 1.0)
    return same(lo, row.get("min_price")) and same(hi, row.get("max_price")) \
        and (it.get("min_stay") is None or rule.get("min_stay") == it["min_stay"])


def plain(items: list[dict]) -> list[dict]:
    keep = ("tipo", "quarto", "data", "min_price", "max_price", "min_stay", "check_in", "check_out", "excecao", "ordem_id",
            "orfa", "orfa_prova", "preco_antes", "protecao")
    out = []
    for it in items:
        row = {k: it[k] for k in keep if k in it}
        row["reason"] = GP.reason_of(str(it.get("motivo", "")))
        row["motivo"] = str(it.get("motivo", ""))[:250]
        if it.get("pace"):
            row["pace"] = {k: it["pace"].get(k) for k in ("status", "direcao", "atual", "novo", "vendidas", "base", "total")}
        out.append(row)
    return out


def slices(c: dict, payload: dict, today: dt.date) -> list[dict]:
    return GP.slices_of(c, payload, today) if payload else []


def case_of(c: dict, s: dict) -> dict:
    frozen_name = f"{s['name']}.json"
    if s.get("real"):
        inputs = GP.read(FROZEN_OUT / frozen_name)  # real inputs, frozen once: read, never rewritten
    else:
        inputs = build_inputs(c, s)
        GP.write_json(FROZEN_OUT / frozen_name, inputs)
    items, delete, no_effect = plan_of(c, inputs)
    today = dt.date.fromisoformat(inputs["today"])
    deleted = {(x["quarto"], x["data"]) for x in delete}
    val_delete = validate(c, inputs, delete) if delete else None
    val_items = validate(c, inputs, items, deleted, no_effect)
    payload_delete = VP.montar_payload(c, copy.deepcopy(delete)) if delete else {}
    payload_items = VP.montar_payload(c, copy.deepcopy(items)) if items else {}
    return {"name": s["name"], "note": s["note"], "input": {"frozen": frozen_name},
            "expected": {"items": plain(items), "deletions": plain(delete),
                         "no_effect": [{"quarto": x["quarto"], "data": x["data"]} for x in no_effect],
                         "validation": {"deletions": val_delete, "items": val_items},
                         "payload": {"deletions": payload_delete, "items": payload_items},
                         "slices": {"deletions": slices(c, payload_delete, today), "items": slices(c, payload_items, today)}}}


def dump(generated_at: str) -> int:
    c = GP.cfg()
    cases = [case_of(c, s) for s in SCENARIOS]
    GP.write_golden(OUT / "pace.plano.json", {
        "function": "pace.plano", "php_target": "Ritmo\\Domain\\Plan\\Assembly\\PlanAssembler",
        "notes": "Whole runs of the reference engine (pace.plano, ajustar_tetos, sem_efeito, the validator, montar_payload, "
                 "fatiar) over frozen inputs; one case per scenario.", "cases": cases})
    engine_dirty = GP.git("status", "--porcelain", "--", GP.ENGINE_PATH) != ""
    if engine_dirty:
        print(f"WARNING: {GP.ENGINE_PATH} has uncommitted changes; these goldens do not match any commit", file=sys.stderr)
    inputs = [GP.FROZEN / "quartos.json", GP.FROZEN / "ancora_mercado.json", GP.RULER, GP.SEASONS, STANDING_ORDERS] \
        + sorted(FROZEN_OUT.glob("*.json"))
    GP.write_json(OUT / "MANIFEST.json", {
        "generated_at": generated_at,
        "repo_commit": GP.git("rev-parse", "HEAD"),
        "engine": GP.ENGINE_PATH,
        "engine_commit": GP.git("log", "-1", "--format=%H", "--", GP.ENGINE_PATH),
        "engine_dirty": engine_dirty,
        "python": platform.python_version(),
        "frozen_inputs": {p.relative_to(GP.FIXTURES).as_posix(): GP.sha256(p) for p in inputs},
        "golden_files": {(OUT / "pace.plano.json").relative_to(GP.FIXTURES).as_posix(): GP.sha256(OUT / "pace.plano.json")},
        "tolerance": {"amounts": 0.01, "percentages_and_integers": "exact"},
        "scenarios": [{"name": s["name"], "today": s["today"], "days": s["days"], "note": s["note"]} for s in SCENARIOS],
        "files": [{"file": "pace.plano.json", "function": "pace.plano", "cases": len(cases)}],
    })
    for case in cases:
        e = case["expected"]
        print(f"{case['name']:24s} items {len(e['items']):3d}  deletions {len(e['deletions']):2d}  no effect {len(e['no_effect']):2d}"
              f"  valid {e['validation']['items']['valid']}")
    return 0


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--generated-at", default=dt.datetime.now(dt.timezone.utc).date().isoformat())
    return dump(parser.parse_args(argv).generated_at)


if __name__ == "__main__":
    sys.exit(main())

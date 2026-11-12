#!/usr/bin/env python3
"""Golden fixtures of the stay and arrival/departure rules (M2).

Runs the stay functions of the reference Python engine (read from the repository, never changed) with frozen inputs
and writes one JSON file per function into tests/fixtures/golden/m2/stay/, in the format of the level-1 goldens:

    {"function": ..., "php_target": ..., "notes": ..., "cases": [{"name": ..., "input": {...}, "expected": ...}]}

It also writes the parity fixture of this area, tests/fixtures/presets/parity-stay.json: the owner's stay and
arrival/departure rules as the engine runs them today, in the plugin's configuration format.

Inputs:
  tests/fixtures/frozen/quartos.json                  the engine configuration (frozen by freeze_inputs.py of M1)
  tests/fixtures/golden/m2/stay/frozen/seasons.json   the season calendar (periods only), `freeze` below
  tests/fixtures/golden/m2/stay/frozen/scenario-*.json invented PMS inventories, pricing calendars, effective stays
                                                      and date rules (seeded, written by `freeze`)
Nothing is written into the engine's folder: the season calendar path points at the frozen copy, the orders file at
a missing temporary path, and the clock guard raises if the engine asks for today's date. Only pure functions are
called (classificar_beds24, comparar, lote_unico, ...); nothing reads the PMS or the pricing system.

Usage:
  python3 tests/fixtures/tools/golden_stay.py freeze   # refresh frozen/ (season calendar from the engine data, read
                                                       # only; scenarios from the seeded generator)
  python3 tests/fixtures/tools/golden_stay.py          # write the goldens, their MANIFEST and the parity fixture
"""

from __future__ import annotations

import argparse
import atexit
import datetime as dt
import hashlib
import json
import platform
import random
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

sys.dont_write_bytecode = True  # the engine folder must stay untouched, bytecode cache included

TOOLS = Path(__file__).resolve().parent
FIXTURES = TOOLS.parent
FROZEN = FIXTURES / "frozen"
GOLDEN = FIXTURES / "golden" / "m2" / "stay"
FROZEN_STAY = GOLDEN / "frozen"
PARITY = FIXTURES / "presets" / "parity-stay.json"
REPO = TOOLS.parents[3]
ENGINE = REPO / ".claude" / "skills" / "gestao-receita-pousadas" / "scripts"
ENGINE_DATA = ENGINE.parent / "dados"

sys.path.insert(0, str(TOOLS))
sys.path.insert(0, str(ENGINE))
import beds24  # noqa: E402  (constants only; nothing here calls it)
import calendario_12m as CAL  # noqa: E402
import comum  # noqa: E402
import ordens as ORD  # noqa: E402
import plano_dono as PD  # noqa: E402
import sazonalidade as SAZ  # noqa: E402
import validar_plano as VP  # noqa: E402
from vocabulary import PROPERTY_ID  # noqa: E402

ENGINE_PATH = ENGINE.relative_to(REPO).as_posix()
WORK = Path(tempfile.mkdtemp(prefix="ritmo-golden-stay-"))
atexit.register(shutil.rmtree, WORK, True)
UM_DIA = dt.timedelta(days=1)

# ---------------------------------------------------------------- vocabulary (engine -> plugin)
RESTRICTION = {"none": "none", "blackout": "blackout", "noCheckIn": "no_checkin", "noCheckOut": "no_checkout",
               "noCheckInOrCheckOut": "no_checkin_checkout"}
SIDE = {"sexta": "previous", "sábado": "closed_day"}
NEIGHBOUR = {"seguinte": "next", "anterior": "previous"}
SEASON_DAYS = {"todos": [0, 1, 2, 3, 4, 5, 6], "sex-sab": [4, 5], "dom-qui": [0, 1, 2, 3, 6]}
WEEKDAY_KEYS = comum.DIAS_CHAVE
# reason of an arrival/departure change of the PMS audit, by a marker of the engine's text ("" = no text)
ARRIVAL_REASON = (("sábado de festa", "closed_day_open_date"), ("sábado esgotado", "orphan_side_closed_day_sold_out"),
                  ("já passou", "orphan_side_previous_past"), ("sexta esgotada", "orphan_side_previous_sold_out"),
                  ("sexta com override", "closed_day"), ("entrada e saída abertas", "open_day"))
# finding codes of the pricing stay plan, by a marker of the engine's text
ISSUE = (("sem dado de preço", "no_pricing_data"), ("fora do plano só de estadia", "own_stay_outside_stay_plan"),
         ("sem min_stay na regra por data", "explicit_stay"), ("sábado órfão", "closed_day_orphan_today"),
         ("saída de sexta fechada", "open_day_masks"), ("(noite órfã)", "orphan_night"), (" > ", "stay_down"),
         (" < ", "stay_up"))
ACCEPTED = (("aguardando o dono", "held"), ("noite órfã (noite seguinte esgotada)", "orphan_accepted"),
            ("sábado órfão", "closed_day_orphan_accepted"), ("dia órfão", "isolated_day"))
STAY_KIND = {"sobe": "arrival_day", "desce": "arrival_day", "órfã": "orphan_night", "explícita": "explicit_rule",
             "sábado órfão": "closed_day_orphan"}
VOCABULARY = {"restriction": RESTRICTION, "orphan_side": SIDE, "proof_neighbour": NEIGHBOUR, "season_days": SEASON_DAYS,
              "property": PROPERTY_ID, "stay_kind": STAY_KIND,
              "arrival_reason": dict(ARRIVAL_REASON, **{"": "closed_day", "override_por_data": "package"}),
              "issue": dict(ISSUE), "accepted": dict(ACCEPTED)}

ROOMS = ("Q7", "Double", "Afrodite", "Q2", "VK7", "Balcony", "VK2")
ORPHAN_ORDER = "dono-2026-11-02-sabado-orfao"


# ---------------------------------------------------------------- engine set-up (read only, no clock)
def no_clock(*_args, **_kwargs):
    raise AssertionError("the engine asked for today's date: every golden case passes its own dates")


def install_guards() -> None:
    SAZ.ARQ = FROZEN_STAY / "seasons.json"
    VP._sazonalidade.cache_clear()
    ORD.ARQ_ORDENS = WORK / "no_orders_file.json"
    PD.ARQ_REGUA = WORK / "no_ruler_file.json"
    for module in (comum, CAL, VP, ORD, PD):
        if hasattr(module, "hoje_brt"):
            module.hoje_brt = no_clock


def cfg() -> dict:
    return comum.carregar_quartos(FROZEN / "quartos.json")


def orders() -> dict:
    """The owner order that authorises the orphan side of the closed day, in force for every room and night."""
    return {ORPHAN_ORDER: {"id": ORPHAN_ORDER, "tipo": "regra", "quartos": ["*"], "de": "2026-11-02", "ate": "2099-12-31",
                           "data": "2026-11-02", "texto": "orphan side of the closed day (golden fixture)"}}


def d(text: str) -> dt.date:
    return dt.date.fromisoformat(text)


def nights(first: dt.date, last: dt.date) -> list[dt.date]:
    return [first + dt.timedelta(days=i) for i in range((last - first).days + 1)]


def plain(value):
    if isinstance(value, dict):
        return {str(k): plain(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [plain(v) for v in value]
    if isinstance(value, (dt.date, dt.datetime)):
        return value.isoformat()
    return value


def case(name: str, inputs: dict, expected) -> dict:
    return {"name": name, "input": plain(inputs), "expected": plain(expected)}


def golden(function: str, php_target: str, notes: str, cases: list[dict]) -> dict:
    return {"function": function, "php_target": php_target, "notes": notes, "cases": cases}


# ---------------------------------------------------------------- frozen scenarios (invented, seeded)
SCENARIOS = {
    # name: (today, audit/plan last night, seed, share of sold-out nights, rooms planned from the pricing calendar)
    "midweek": ("2026-11-12", "2027-01-05", 11, 0.25, ()),
    "saturday_today": ("2026-11-14", "2026-12-20", 14, 0.30, ()),
    "sold_out_pressure": ("2026-11-16", "2027-01-10", 16, 0.55, ("Q2", "VK2")),
    "july_season": ("2027-07-01", "2027-07-31", 7, 0.35, ()),
}
MASK_ALL, MASK_FRIDAY_CLOSED, MASK_WEEKEND_CLOSED = "1111111", "1111011", "1111001"


def scenario_inventory(c: dict, rng: random.Random, today: dt.date, last: dt.date, sold_share: float) -> dict:
    """{room: {night: {numAvail, minStay, maxStay, override}}} from today (the PMS reports no past night) to last + 1."""
    inv = {}
    package_nights = set(c["parametros"]["regra_h_beds24"].get("override_por_data") or {})
    for room in ROOMS:
        units = int(c["por_curto"][room]["unidades"])
        rows = {}
        for n in nights(today, last + UM_DIA):
            roll = rng.random()
            if roll < sold_share:
                avail = -1 if rng.random() < 0.06 else 0
            else:
                avail = rng.randint(1, units)
            min_stay = rng.choice([1, 1, 2, 2, 2, 3, None])
            max_stay = rng.choice([365, 365, 365, 4, 2, None]) if n.isoformat() in package_nights or rng.random() < 0.05 else 365
            if n.weekday() == 5:
                override = rng.choice(["noCheckInOrCheckOut"] * 5 + ["none", "noCheckIn", "noCheckOut", "blackout"])
            elif n.weekday() == 4 and room == "Afrodite":
                override = rng.choice(["noCheckOut", "noCheckOut", "none", "noCheckIn", "noCheckInOrCheckOut"])
            elif n.isoformat() in package_nights:
                override = rng.choice(["none", "noCheckIn", "noCheckOut", "noCheckInOrCheckOut"])
            else:
                override = rng.choice(["none"] * 22 + ["noCheckIn", "blackout"])
            rows[n.isoformat()] = {"numAvail": avail, "minStay": min_stay, "maxStay": max_stay, "override": override}
        inv[room] = rows
    return inv


def scenario_pricing(c: dict, rng: random.Random, inv: dict, first: dt.date, last: dt.date) -> dict:
    """{room: [calendar rows of the pricing system]} with the engine's fields and, for the PHP side, sold and total."""
    out = {}
    for room in ROOMS:
        units = int(c["por_curto"][room]["unidades"])
        rows = []
        for n in nights(first, last + UM_DIA):
            if room == "Balcony" and n in (first + dt.timedelta(days=9), first + dt.timedelta(days=10)):
                continue  # nights the pricing calendar does not report
            ds = n.isoformat()
            avail = (inv[room].get(ds) or {}).get("numAvail")
            sold = units - max(int(avail), 0) if avail is not None else rng.randint(0, units)
            row = {"date": ds, "price": str(rng.choice([480, 520, 610, 735, 890, 1040])),
                   "min_stay": str(rng.choice([1, 1, 2, 2, 3])),
                   "mu_occupancy": f"{sold}/{units}" if units > 1 else "-1", "num_bookings": str(min(sold, units))}
            if room == "Afrodite":
                row["check_in"], row["check_out"] = MASK_ALL, rng.choice([MASK_FRIDAY_CLOSED, MASK_WEEKEND_CLOSED, MASK_ALL])
                if rng.random() < 0.1:
                    row["check_in_check_out_enabled"] = "0"
            elif rng.random() < 0.3:
                row["check_in"], row["check_out"] = MASK_ALL, MASK_ALL
            v, t = comum.vendidas_total(row, units)
            row["sold"], row["total"] = v, t
            rows.append(row)
        out[room] = rows
    return out


def scenario_effective(rng: random.Random, pricing: dict) -> dict:
    """Effective minimum stays of today for half of the rooms (the others fall back to the calendar's)."""
    out = {}
    for room in ("Q7", "Afrodite", "VK7", "VK2"):
        out[room] = {r["date"]: rng.choice([1, 1, 2, 2, 3]) for r in pricing[room] if rng.random() < 0.8}
    return out


def scenario_rules(rng: random.Random, first: dt.date, last: dt.date) -> dict:
    """Date rules in force in the pricing system: stays, masks, floors, ceilings, prices and reasons."""
    out = {}
    for room in ROOMS:
        rules = {}
        for n in nights(first, last):
            friday_mask = room == "Afrodite" and n.weekday() == 4 and rng.random() < 0.7
            if not friday_mask and rng.random() > 0.3:
                continue
            rule = {"date": n.isoformat()}
            kind = rng.choice(["stay", "stay", "floor", "price", "masks", "marker", "stay_floor"])
            if kind in ("stay", "stay_floor") or rng.random() < 0.2:
                rule["min_stay"] = rng.choice([1, 2, 2, 3])
            if kind in ("floor", "stay_floor"):
                rule["min_price"], rule["min_price_type"] = rng.choice([-20, -10, 5]), "percent"
                if rng.random() < 0.5:
                    rule["max_price"], rule["max_price_type"] = 60, "percent"
            if kind == "price":
                rule["price"], rule["price_type"] = rng.choice([900, 1100]), "fixed"
            if kind == "masks" or friday_mask:
                rule["check_in"] = MASK_ALL
                rule["check_out"] = MASK_FRIDAY_CLOSED if friday_mask else rng.choice([MASK_ALL, MASK_WEEKEND_CLOSED])
            if kind == "marker":
                rule["reason"] = "Regra J: protected night"
            elif rng.random() < 0.5:
                rule["reason"] = "date rule of the golden fixture"
            rules[n.isoformat()] = rule
        out[room] = rules
    return out


def make_scenario(c: dict, name: str) -> dict:
    today_s, last_s, seed, sold_share, from_calendar = SCENARIOS[name]
    rng = random.Random(seed)
    today, last = d(today_s), d(last_s)
    inv = scenario_inventory(c, rng, today, last, sold_share)
    if today.weekday() == 5:  # today's closed day free in three rooms, above the orphan stay: the previous night passed
        for room, stay in (("Q7", 2), ("VK7", 3), ("Afrodite", 2)):
            inv[room][today_s].update(numAvail=1, minStay=stay, override="noCheckInOrCheckOut")
    pricing = scenario_pricing(c, rng, inv, today, last)
    effective = scenario_effective(rng, pricing)
    if today.weekday() == 5:  # the pricing system holds 2 and 3 nights on two of them and 1 on the third
        for room, stay in (("Q7", 2), ("VK7", 3), ("Afrodite", 1)):
            effective[room][today_s] = stay
    with_rules = name != "saturday_today"  # one scenario plans without the date rules (the engine's --regras absent)
    return {
        "_doc": "Invented PMS inventory, pricing calendar, effective stays and date rules of a golden scenario (seeded).",
        "name": name, "today": today_s, "audit_from": today_s, "last": last_s,
        "plan_from": today_s,
        "read_at": f"{today_s}T10:15:00+00:00",
        "plan_from_calendar": list(from_calendar),
        "inventory": inv,
        "pricing": pricing,
        "effective": effective,
        "date_rules": scenario_rules(rng, today, last) if with_rules else None,
    }


def freeze() -> int:
    """Copies the season periods (dates, days, stay, name) and writes the seeded scenarios into frozen/."""
    source = json.loads((ENGINE_DATA / "sazonalidade.json").read_text(encoding="utf-8"))
    keep = ("nome", "inicio", "fim", "dias", "estadia_minima")
    periods = [{k: p[k] for k in keep if k in p} for p in source["periodos"]]
    write_json(FROZEN_STAY / "seasons.json", {"_doc": "Frozen season periods (dates, days, minimum stay, name) from the "
                                                       "engine data by golden_stay.py freeze.", "periodos": periods})
    c = cfg()
    for name in SCENARIOS:
        write_scenario(FROZEN_STAY / f"scenario-{name}.json", make_scenario(c, name))
    print(f"frozen: {len(periods)} season periods, {len(SCENARIOS)} scenarios")
    return 0


def scenario(name: str) -> dict:
    return json.loads((FROZEN_STAY / f"scenario-{name}.json").read_text(encoding="utf-8"))


# ---------------------------------------------------------------- A. pure rules (comum, validar_plano)
def stay_rule_cases(c: dict) -> list[dict]:
    fer = lambda n: comum.periodo_feriado(c, n)  # noqa: E731
    span = nights(d("2026-11-01"), d("2027-12-31"))
    required = [case(n.isoformat(), {"night": n}, VP.estadia_exigida(c, n, fer(n))) for n in span]
    sample = [n for i, n in enumerate(span) if i % 9 == 0 or fer(n) or comum.data_de_pacote(c, n)
              or n.weekday() in (4, 5) and i % 4 == 0]
    planned = []
    for n in sample:
        for current in (None, 1, 2, 3):
            for free, next_sold in ((True, True), (True, False), (False, True)):
                stay, orphan = VP.estadia_planejada(c, n, current, free, next_sold, quarto="Q7")
                planned.append(case(f"{n} {current} {free} {next_sold}",
                                    {"night": n, "room": "Q7", "current": current, "free": free, "next_sold_out": next_sold},
                                    {"min_stay": stay, "orphan": orphan is not None}))
    # the closed-day side is asked only of a closed day (the engine's Saturday)
    allowed = [case(f"{n} {side}", {"night": n, "closed_day_side": side}, comum.orfa_permitida(c, n, sabado=side))
               for n in span for side in (False, True) if (n.weekday() in (3, 4, 5) or fer(n)) and (not side or n.weekday() == 5)]
    reopened = [case(f"{room} {n}", {"room": room, "night": n}, comum.sexta_reaberta(c, room, n) is not None)
                for room in ROOMS for n in nights(d("2026-11-13"), d("2027-01-15")) if n.weekday() == 4]
    past = [case(f"{n} {today}", {"night": n, "today": today}, CAL.sabado_com_sexta_passada(n, today))
            for n in nights(d("2026-11-12"), d("2026-11-22")) for today in (d("2026-11-13"), d("2026-11-14"), d("2026-11-15"), None)]
    return [
        golden("validar_plano.estadia_exigida", "StayRules::requiredStay(room, night)",
               "Minimum stay a night requires: package by date, else holiday (own stay, else the first season period "
               "covering the night, else the holiday default), else the arrival weekday raised to the closed day's own "
               "stay and to the season outside holidays. null when nothing requires a stay.", required),
        golden("validar_plano.estadia_planejada", "StayRules::plannedStay(room, night, current, free, nextSoldOut)",
               "Stay the night must have from the current one: up to the target, down only to 1 (target 1 or orphan "
               "night); null where the arrival rule does not decide (package, closed day, holiday except its orphan "
               "night).", planned),
        golden("comum.orfa_permitida", "StayRules::orphanAllowed(night, closedDaySide)",
               "An orphan stay fits the night as to packages, open dates of the closed day and holidays (the closed-day "
               "side only on closed days).", allowed),
        golden("comum.sexta_reaberta", "StayRules::openDayApplies(room, night)",
               "The weekday is enforced open for the room (outside packages).", reopened),
        golden("calendario_12m.sabado_com_sexta_passada", "StayRules::closedDayWithPreviousPast(night, today)",
               "Today's closed day whose previous night has passed (it no longer sells and counts as sold out).", past),
    ]


# ---------------------------------------------------------------- A2. the hook of a rewritten date rule (pace.ajustar_tetos)
def hook_cases(c: dict) -> list[dict]:
    """estadia_da_noite and prova_sabado_orfao over the frozen scenarios, from the pricing calendar and from the PMS
    inventory: every decided night and every fifth undecided one."""
    stays, proofs = [], []
    for name in ("midweek", "saturday_today"):
        s = scenario(name)
        today = d(s["today"])
        for room in ROOMS:
            cal = {r["date"]: r for r in s["pricing"][room]}
            for i, n in enumerate(nights(d(s["plan_from"]), d(s["last"]))):
                current = (s["inventory"][room].get(n.isoformat()) or {}).get("minStay")
                for source in ("pricing", "pms"):
                    inv = s["inventory"][room] if source == "pms" else None
                    read_at = s["read_at"] if inv is not None else None
                    stay, orphan, p = CAL.estadia_da_noite(c, room, n, cal, current, inv, read_at)
                    if stay is not None or i % 5 == 0:
                        stays.append(case(f"{name} {room} {n} {source}", {"scenario": name, "room": room, "night": n,
                                                                          "current": current, "source": source},
                                          {"min_stay": stay, "orphan": orphan is not None, "proof": proof(p)}))
                    if n.weekday() == 5:
                        side = CAL.prova_sabado_orfao(c, room, n, cal, inv, read_at, hoje=today)
                        proofs.append(case(f"{name} {room} {n} {source}", {"scenario": name, "room": room, "night": n,
                                                                           "source": source, "today": today}, proof(side)))
    return [
        golden("calendario_12m.estadia_da_noite", "StayHook::nightStay(room, night, current, occupancy)",
               "The stay a rewritten date rule takes from the arrival rule, with the orphan proof of its occupancy "
               "source.", stays),
        golden("calendario_12m.prova_sabado_orfao", "StayHook::closedDayOrphanProof(room, night, occupancy, today)",
               "The proof of the free side of a closed day (night before sold out, or past on today's closed day), "
               "or null.", proofs),
    ]


# ---------------------------------------------------------------- B. the PMS audit (calendario_12m.classificar_beds24)
def proof(p: dict | None):
    if not p:
        return None
    neighbour = "seguinte" if any(k.startswith("seguinte") for k in p) else "anterior"
    out = {"source": "pms" if p.get("fonte") == "beds24" else "pricing", "neighbour": NEIGHBOUR[neighbour],
           "units": p.get("unidades"), "night_available": p.get("noite_numAvail"),
           "neighbour_available": p.get(f"{neighbour}_numAvail"), "night_sold": p.get("noite_vendidas"),
           "night_total": p.get("noite_total"), "neighbour_sold": p.get(f"{neighbour}_vendidas"),
           "neighbour_total": p.get(f"{neighbour}_total"), "read_at": p.get("lido_em"),
           "previous_past": p.get("anterior_passada")}
    return {k: v for k, v in out.items() if v is not None}


def arrival_reason(c: dict, it: dict) -> str:
    if it["data"] in (c["parametros"]["regra_h_beds24"].get("override_por_data") or {}):
        return "package"
    text = it.get("motivo") or ""
    for marker, code in ARRIVAL_REASON:
        if marker in text:
            return code
    if text:
        raise ValueError(f"unknown arrival reason: {text}")
    return "closed_day"


def audit_room(c: dict, x: dict) -> dict:
    assert not x["semana_estadia_acima"], "the legacy weekday stay of the season calendar is not ported"
    return {
        "room": x["quarto"],
        "arrival_changes": [{"night": s["data"], "before": RESTRICTION[s["override"]], "after": RESTRICTION[s["troca"]],
                             "reason": arrival_reason(c, s), "also_pricing": bool(s.get("pricelabs_tambem")),
                             "proof": proof(s.get("orfa_prova"))} for s in x["sabados_fora_da_regra"]],
        "closed_day_stay_below": list(x["sabados_estadia_abaixo"]),
        "other_restricted": [{"night": t.split(" ")[0], "restriction": RESTRICTION[t.split(" ")[1]]}
                             for t in x["outras_noites_bloqueadas"]],
        "orphan_stays": [{"night": s["data"], "min_stay": s["min_stay"], "target": s["alvo"], "side": SIDE[s["lado"]],
                          "proof": proof(s.get("orfa_prova"))} for s in x["orfas_estadia"]],
        "package_stays": [{"night": s["data"], "min_stay": s.get("min_stay"), "min_target": s.get("min_alvo"),
                           "max_stay": s.get("max_stay"), "max_target": s.get("max_alvo")} for s in x["estadia_por_data"]],
        "arrival_stays": [{"night": s["data"], "min_stay": s["min_stay"], "target": s["alvo"],
                           "orphan": s.get("orfa") is not None, "holiday": s.get("feriado")} for s in x["estadia_dia_chegada"]],
    }


def audit_cases(c: dict) -> list[dict]:
    out = []
    for name in SCENARIOS:
        s = scenario(name)
        classif = CAL.classificar_beds24(c, s["inventory"], d(s["audit_from"]), d(s["last"]), hoje=d(s["today"]),
                                         lido_em=s["read_at"])
        out.append(case(name, {"scenario": name}, [audit_room(c, x) for x in classif]))
    return [golden("calendario_12m.classificar_beds24", "InventoryAudit::audit(inventory, from, to, today, readAt)",
                   "Per room, sorted by code: arrival/departure changes for the PMS (closed day, its open dates and "
                   "orphan side, enforced open day, packages), closed days below their own stay, other restricted "
                   "nights (report only), orphan stays of the closed day, package stays and the arrival-day stays.",
                   out)]


# ---------------------------------------------------------------- C. the pricing stay plan (calendario_12m.comparar)
def codes(texts: list[str], table) -> list[str]:
    out = []
    for text in texts:
        for marker, code in table:
            if marker in text:
                out.append(code)
                break
        else:
            raise ValueError(f"unknown finding: {text}")
    return out


def plan_entry(x: dict, plan_item: dict | None) -> dict:
    item = x.get("item") or {}
    return {
        "room": x["quarto"], "night": x["data"],
        "issues": codes(x["falta"], ISSUE), "accepted": codes(x.get("info") or [], ACCEPTED),
        "stay_before": x.get("estadia_antes"), "holiday": x.get("feriado"),
        "min_stay": item.get("min_stay"), "kind": STAY_KIND.get(x.get("tipo_estadia")) if "min_stay" in item else None,
        "season": x.get("temporada"), "checkin_mask": item.get("check_in"), "checkout_mask": item.get("check_out"),
        "keep_reason": bool(item.get("manter_reason")), "proof": proof(item.get("orfa_prova")),
        "orphan": {"seguinte": "next_night", "sábado": "closed_day"}.get(item.get("orfa")),
        "in_plan": plan_item is not None,
    }


def plan_cases(c: dict) -> list[dict]:
    out = []
    for name in SCENARIOS:
        s = scenario(name)
        today, first, last = d(s["today"]), d(s["plan_from"]), d(s["last"])
        cal_rows = {room: {r["date"]: r for r in rows} for room, rows in s["pricing"].items()}
        entries = []
        for room in ROOMS:
            rules = None if s["date_rules"] is None else s["date_rules"].get(room, {})
            inv = None if room in s["plan_from_calendar"] else s["inventory"][room]
            entries += CAL.comparar(c, room, cal_rows[room], first, last, orders(), so_estadia=True, regras=rules,
                                    efetiva=s["effective"].get(room), inv=inv, lido_em=s["read_at"], hoje=today)
        plan = CAL.montar_plano([x for x in entries if x["falta"]], today)
        planned = {(i["quarto"], i["data"]) for i in plan["itens"]}
        result = [plan_entry(x, (x["quarto"], x["data"]) if (x["quarto"], x["data"]) in planned else None) for x in entries]
        out.append(case(name, {"scenario": name}, result))
    return [golden("calendario_12m.comparar", "PricingStayPlan::plan(room, calendar, from, to, today, ...) (stay only)",
                   "plano --so-estadia: per room and night, the issues and accepted findings, and the stay and "
                   "arrival-mask item for the pricing system (min stay, its kind and season, masks, keep the rule's "
                   "reason, orphan proof). The orphan side needs the owner order in force in the engine; it is given.",
                   out)]


# ---------------------------------------------------------------- D. the single PMS batch (calendario_12m.lote_unico)
def batch_reason(c: dict, it: dict) -> str:
    text = it.get("motivo") or ""
    if text == CAL.MOTIVO_SABADO:
        return "closed_day"
    if it.get("max_stay") is not None or text == (c["parametros"]["regra_h_beds24"].get("override_por_data_motivo") or "") \
            and it.get("override") is None:
        return "package"
    if it.get("override") is not None:
        return arrival_reason(c, {"data": it["de"], "motivo": text})
    if it.get("orfa") == "sábado":
        return "closed_day_orphan"
    if "libera órfã" in text:
        return "orphan_night"
    if "dia de chegada" in text:
        return "arrival_day"
    raise ValueError(f"unknown batch reason: {text}")


def batch_item(c: dict, it: dict) -> dict:
    out = {"room": it["quarto"], "from": it["de"], "to": it["ate"], "restriction": RESTRICTION.get(it.get("override")),
           "min_stay": it.get("min_stay"), "min_stay_before": it.get("min_stay_antes"), "max_stay": it.get("max_stay"),
           "also_pricing": bool(it.get("pricelabs_tambem")), "reason": batch_reason(c, it), "proof": proof(it.get("orfa_prova"))}
    return out


def batch_cases(c: dict) -> list[dict]:
    out = []
    for name in SCENARIOS:
        s = scenario(name)
        today = d(s["today"])
        classif = CAL.classificar_beds24(c, s["inventory"], d(s["audit_from"]), d(s["last"]), hoje=today,
                                         lido_em=s["read_at"])
        for stay_only in (False, True):
            lote = CAL.lote_unico(c, classif, today, so_estadia=stay_only)
            out.append(case(f"{name} {'stay_only' if stay_only else 'full'}", {"scenario": name, "stay_only": stay_only},
                            [batch_item(c, it) for it in lote]))
    return [golden("calendario_12m.lote_unico", "StayBatch::single(audit, today, stayOnly)",
                   "The single PMS batch: every change once, the urgent ones (today to today + 14) first by night, "
                   "property and room, then by property, room and night.", out)]


# ---------------------------------------------------------------- the parity fixture
def parity_fixture(c: dict) -> dict:
    p = c["parametros"]
    arrival, rh = p["estadia_por_dia_chegada"], p["regra_h_beds24"]
    orphan_side = rh.get("orfas") or {}
    assert not rh.get("sexta_override_por_quarto"), "a weekday override per room needs a closed-day rule per room"
    assert not arrival.get("aguardando_dono"), "nights held for the owner need held_weekdays"
    packages = {}
    for key, field in (("estadia_minima_por_data", "min_stay"), ("estadia_maxima_por_data", "max_stay"),
                       ("override_por_data", "restriction")):
        for night, value in (rh.get(key) or {}).items():
            packages.setdefault(night, {"date": night, "min_stay": None, "max_stay": None, "restriction": None})
            packages[night][field] = RESTRICTION[value] if field == "restriction" else int(value)
    seasons = json.loads((FROZEN_STAY / "seasons.json").read_text(encoding="utf-8"))["periodos"]
    return {
        "_doc": "Parity fixture of the stay and arrival/departure rules (M2), generated by "
                "tests/fixtures/tools/golden_stay.py from the frozen inputs. Test data only: never shipped.",
        "stay": {
            "arrival_rule": {"enabled": bool(arrival["ativa"]), "valid_from": arrival.get("desde"),
                             "valid_to": arrival.get("ate"),
                             "by_weekday": {str(i): int(arrival["por_dia"][k]) for i, k in enumerate(WEEKDAY_KEYS)},
                             "properties": {}, "rooms": {}, "held_weekdays": {}},
            "orphan": {"next_night": bool(arrival.get("orfa_noite_seguinte")),
                       "on_holidays": bool(arrival.get("orfa_em_feriado"))},
            "holiday_min_stay": 2,
            "closed_days": [{
                "weekday": 5, "restriction": RESTRICTION[rh["sabado_override"]], "rooms": [], "except_rooms": [],
                "min_stay": int(rh.get("estadia_minima_sabado", 2)),
                "open_dates": {k: RESTRICTION[v] for k, v in (rh.get("sabados_excecao") or {}).items()},
                "orphan_side": {"enabled": bool(orphan_side.get("ativa")), "on_holidays": bool(orphan_side.get("em_feriado")),
                                "when_closed_day_sold_out": RESTRICTION[orphan_side.get("sabado_esgotado", "noCheckIn")],
                                "when_previous_sold_out": RESTRICTION[orphan_side.get("sexta_esgotada", "noCheckOut")]},
            }],
            "open_days": [{"weekday": 4, "rooms": sorted(rh.get("sexta_reaberta_por_quarto") or {})}],
            "packages": [{"name": rh.get("override_por_data_motivo") or "package", "rooms": [],
                          "nights": [packages[k] for k in sorted(packages)]}],
            "seasons": [{"name": CAL.nome_temporada(s), "start": s["inicio"], "end": s["fim"],
                         "weekdays": SEASON_DAYS.get(s.get("dias", "todos"), []), "min_stay": s.get("estadia_minima")}
                        for s in seasons],
            # the pricing system's push carries the minimum stay of every room to the PMS and arrival/departure only
            # of the rooms whose date rules hold masks (calendario_12m: `pricelabs_tambem`)
            "pricing_sync": {"min_stay_rooms": ["*"], "max_stay_rooms": [], "arrival_departure_rooms": ["Afrodite"]},
            "keep_reason_markers": ["regra j"],
            "batch": {"urgent_days": 14, "max_ranges": beds24.MAX_FAIXAS_POST},
        },
    }


# ---------------------------------------------------------------- writing
def write_golden(path: Path, data: dict) -> None:
    head = {k: v for k, v in data.items() if k != "cases"}
    lines = [json.dumps(c, ensure_ascii=False, allow_nan=False) for c in data["cases"]]
    text = json.dumps(head, ensure_ascii=False, indent=1)[:-2] + ',\n "cases": [\n  ' + ",\n  ".join(lines) + "\n ]\n}\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")


def write_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=1, allow_nan=False) + "\n", encoding="utf-8")


def write_scenario(path: Path, data: dict) -> None:
    """One line per room of the inventory, the pricing calendar, the effective stays and the date rules."""
    def block(value) -> str:
        if not isinstance(value, dict):
            return json.dumps(value, ensure_ascii=False)
        rows = [f"  {json.dumps(k)}: {json.dumps(v, ensure_ascii=False, separators=(',', ':'))}" for k, v in value.items()]
        return "{\n" + ",\n".join(rows) + "\n }"
    lines = [f" {json.dumps(k)}: {block(v)}" for k, v in data.items()]
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("{\n" + ",\n".join(lines) + "\n}\n", encoding="utf-8")


def git(*args: str) -> str:
    return subprocess.run(["git", "-C", str(REPO), *args], check=True, capture_output=True, text=True).stdout.strip()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", nargs="?", default="dump", choices=("dump", "freeze"))
    parser.add_argument("--generated-at", default=dt.datetime.now(dt.timezone.utc).date().isoformat())
    args = parser.parse_args(argv)
    install_guards()
    if args.command == "freeze":
        return freeze()
    c = cfg()
    listing = []
    for data in stay_rule_cases(c) + hook_cases(c) + audit_cases(c) + plan_cases(c) + batch_cases(c):
        name = data["function"] + ".json"
        write_golden(GOLDEN / name, data)
        listing.append({"file": name, "function": data["function"], "cases": len(data["cases"])})
    write_json(PARITY, parity_fixture(c))
    engine_dirty = git("status", "--porcelain", "--", ENGINE_PATH) != ""
    if engine_dirty:
        print(f"WARNING: {ENGINE_PATH} has uncommitted changes; these goldens do not match any commit", file=sys.stderr)
    frozen = [FROZEN / "quartos.json", FROZEN_STAY / "seasons.json"] + sorted(FROZEN_STAY.glob("scenario-*.json"))
    write_json(GOLDEN / "MANIFEST.json", {
        "generated_at": args.generated_at,
        "repo_commit": git("rev-parse", "HEAD"),
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": engine_dirty,
        "python": platform.python_version(),
        "frozen_inputs": {p.relative_to(FIXTURES).as_posix(): sha256(p) for p in frozen},
        "parity_fixture": {"file": PARITY.relative_to(FIXTURES).as_posix(), "sha256": sha256(PARITY)},
        "tolerance": {"amounts": 0.01, "percentages_and_integers": "exact"},
        "vocabulary": VOCABULARY,
        "files": listing,
    })
    for item in listing:
        print(f"{item['cases']:5d} cases  {item['file']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""Golden fixtures of the sales ladder, the sold-out protection, the step cadence and the owner orders (M2).

Runs the functions of the reference Python engine (read from the repository, never changed) with frozen inputs and
writes one JSON file per function into tests/fixtures/golden/m2/ladder/, in the format of the level-1 goldens:

    {"function": ..., "php_target": ..., "notes": ..., "cases": [{"name": ..., "input": {...}, "expected": ...}]}

It also writes the parity fixture of this area, tests/fixtures/presets/parity-ladder.json: the owner's strategy as
the engine runs it today (the constants of plano_dono.py, the market ruler and the configuration values), in the
plugin's configuration format.

Inputs:
  tests/fixtures/frozen/quartos.json          the engine configuration (frozen by freeze_inputs.py of M1), used
                                              without the rules no milestone ports yet (dump_golden.core_cfg)
  tests/fixtures/frozen/ladder/regua_dono.json the market ruler of the owner's plan, numbers only (`freeze` below)
Calendars, ledgers, orders and anchors of the cases are invented. Nothing is written into the engine's folder: the
engine's data paths point at a temporary folder, the clock guard raises if the engine asks for today's date, and the
two functions that write a file (registrar_passos, marcar_envio, ordens.registrar_manual) get a temporary path.

Usage:
  python3 tests/fixtures/tools/golden_ladder.py freeze     # refresh frozen/ladder/ from the engine data (read only)
  python3 tests/fixtures/tools/golden_ladder.py            # write the goldens, their MANIFEST and the parity fixture
The temporary folder is created under $TMPDIR and removed at exit.
"""

from __future__ import annotations

import argparse
import atexit
import copy
import datetime as dt
import hashlib
import json
import platform
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

sys.dont_write_bytecode = True  # the engine folder must stay untouched, bytecode cache included

TOOLS = Path(__file__).resolve().parent
FIXTURES = TOOLS.parent
FROZEN = FIXTURES / "frozen"
FROZEN_LADDER = FROZEN / "ladder"
GOLDEN = FIXTURES / "golden" / "m2" / "ladder"
PARITY = FIXTURES / "presets" / "parity-ladder.json"
REPO = TOOLS.parents[3]
ENGINE = REPO / ".claude" / "skills" / "gestao-receita-pousadas" / "scripts"
ENGINE_DATA = ENGINE.parent / "dados"

sys.path.insert(0, str(TOOLS))
sys.path.insert(0, str(ENGINE))
import comum  # noqa: E402
import escada_reservas as ER  # noqa: E402
import ordens as ORD  # noqa: E402
import pace  # noqa: E402
import plano_dono as PD  # noqa: E402
from dump_golden import core_cfg  # noqa: E402
from vocabulary import PROPERTY_ID  # noqa: E402

ENGINE_PATH = ENGINE.relative_to(REPO).as_posix()
WORK = Path(tempfile.mkdtemp(prefix="ritmo-golden-ladder-"))
atexit.register(shutil.rmtree, WORK, True)

# ---------------------------------------------------------------- vocabulary (engine -> plugin)
NIGHT_LABEL = {"semana": "weekday", "inverno_semana": "winter_weekday", "fds": "weekend", "inverno_fds": "winter_weekend",
               "feriado": "holiday", "natal": "christmas", "entre_festas": "between_holidays", "reveillon": "new_year"}
SERIES = {"lista": "cheapest", "hidro": "comparable", "pares_lista": "peers_cheapest", "pares_hidro": "peers_comparable"}
ORDER_TYPE = {"preco_por_data": "fixed_price", "piso_por_data": "floor", "alvo_por_data": "target", "regra": "rule",
              "manual": "manual", "acompanhamento": "follow_up", "restricao": "restriction"}
RELEASE = {"fixo": "fixed_price", "abaixo_piso": "below_standard_floor", "acima_15": "above_weekly_rise_limit",
           "estadia": "stay", "estadia_1_orfa": "orphan_stay", "anuncio": "listing", "beds24_inventario": "inventory",
           "personalizacao": "customization"}
ORDER_KEYS = {"id": "id", "tipo": "type", "quartos": "rooms", "de": "from", "ate": "to", "noites": "nights",
              "noites_fora": "excluded_nights", "data": "given_on", "criada_em": "created_at", "libera": "releases",
              "pisos_enviados": "floors", "percentil": "percentile", "vendidas_base": "base_sold", "texto": "note",
              "escada_vendas": "sales_ramp", "passo_livre": "free_step", "so_desce": "only_down",
              "ocupacao_max": "strong_night_occupancy", "frente_ocupacao": "strong_night_ahead_occupancy",
              "topo_enviados": "top", "piso_atras": "fallback_floors", "gatilho_dias": "fallback_trigger_days",
              "gatilho_min_dias": "fallback_min_days", "degraus_atras": "descent_floors", "degraus_dias": "descent_marks"}
HOLD = {"D0 nunca sobe": "LADDER_TODAY_NEVER_UP", "fora do filtro": "OUT_OF_NIGHT_FILTER",
        "ainda não chegou": "CADENCE_NOT_ON_CHANNEL", "menos de 24 h": "CADENCE_WITHIN_24H"}
VOCABULARY = {"night_label": NIGHT_LABEL, "series": SERIES, "property": PROPERTY_ID, "order_type": ORDER_TYPE,
              "release": RELEASE, "order_keys": ORDER_KEYS, "step_direction": {"sobe": "up", "desce": "down"},
              "sold_base": {"quarto": "room", "pousada": "property"}, "step_status": {"plano": "ladder"}, "hold": HOLD}

STRATEGY_ID = "plan-nov-feb"
ROOMS = ("Q7", "Double", "Q2", "Afrodite", "VK7", "VK2", "Balcony")
UNITS = {"Q7": 7, "Double": 6, "Q2": 2, "Afrodite": 1, "VK7": 7, "VK2": 2, "Balcony": 6}
TIPOS = tuple(NIGHT_LABEL)


# ---------------------------------------------------------------- engine set-up (read only, no clock)
def no_clock(*_args, **_kwargs):
    raise AssertionError("the engine asked for today's date: every golden case passes its own dates")


def install_guards() -> None:
    PD.ARQ_REGUA = FROZEN_LADDER / "regua_dono.json"
    PD._regua.cache_clear()
    ORD.ARQ_ORDENS = WORK / "no_orders_file.json"
    pace.ARQ_PASSOS = WORK / "never_written_ledger.json"
    for module in (comum, pace, ORD, ER):
        if hasattr(module, "hoje_brt"):
            module.hoje_brt = no_clock


def cfg() -> dict:
    return core_cfg(comum.carregar_quartos(FROZEN / "quartos.json"))


def d(text: str) -> dt.date:
    return dt.date.fromisoformat(text)


def t(text: str) -> dt.datetime:
    value = dt.datetime.fromisoformat(text.replace("Z", "+00:00"))
    return value if value.tzinfo else value.replace(tzinfo=dt.timezone.utc)


def plain(value):
    """Engine values -> JSON (dates as ISO text, tuples as lists)."""
    if isinstance(value, dict):
        return {str(k): plain(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [plain(v) for v in value]
    if isinstance(value, (dt.date, dt.datetime)):
        return value.isoformat()
    return value


def run(fn, *args, **kwargs):
    try:
        return plain(fn(*args, **kwargs))
    except Exception as error:  # noqa: BLE001 - the golden records the exception class
        return {"raises": type(error).__name__}


def ruler() -> dict:
    return json.loads((FROZEN_LADDER / "regua_dono.json").read_text(encoding="utf-8"))["noites"]


def plan_order(**extra) -> dict:
    order = {"id": PD.ORDEM_ID, "data": "2026-11-09", "criada_em": "2026-11-10T02:35:00Z", "tipo": "regra",
             "texto": "ladder strategy active (golden fixture)", "quartos": ["*"], "de": PD.DE, "ate": PD.ATE, "libera": []}
    order.update(extra)
    return order


def orders(*items: dict) -> dict:
    return {o["id"]: o for o in items}


ON = orders(plan_order())
OFF: dict = {}


def row(night: str, price, sold: int, units: int, mn="=", mx="=", **extra) -> dict:
    """One night of a pricing calendar (invented). mn/mx: "=" -> same as the price, None -> absent."""
    r = {"date": night, "price": str(price), "mu_occupancy": f"{sold}/{units}" if units > 1 else "-1",
         "num_bookings": str(sold)}
    if mn is not None:
        r["min_price"] = str(price if mn == "=" else mn)
    if mx is not None:
        r["max_price"] = str(price if mx == "=" else mx)
    r.update(extra)
    return r


def step(room, night, direction, written, on_channel=False, sold=None, base="quarto", total=None, status="ritmo") -> dict:
    return {"quarto": room, "noite": night, "de": 0.0, "para": 0, "direcao": direction, "status": status, "vendidas": sold,
            "base": base, "total": total, "gravado_em": written, "chegou_ao_canal": on_channel}


def anchor(p25, p50, p75, p90, top, n=20, searched="2027-03-05", tipo="fds") -> dict:
    return {"p25": p25, "p50": p50, "p75": p75, "p90": p90, "max": top, "n": n, "busca_em": searched, "tipo": tipo,
            "p50_medido": True}


def hold_code(text: str | None):
    if not text:
        return None
    for marker, code in HOLD.items():
        if marker in text:
            return code
    raise ValueError(f"unknown hold text: {text}")


def case(name: str, inputs: dict, expected) -> dict:
    return {"name": name, "input": plain(inputs), "expected": plain(expected)}


# ---------------------------------------------------------------- A. ladder formulas (plano_dono.py)
def ladder_formulas() -> list[dict]:
    rn_all = ruler()
    files = [golden("plano_dono.degraus_escada", "SalesLadder::steps(sold, units)",
                    "Increments carried by the next unit: none until ceil(units x 0.5) units are sold, then one per unit; "
                    "a sold-out room is priced as its last unit.",
                    [case(f"{v}_of_{u}", {"vendidas": v, "unidades": u}, PD.degraus_escada(v, u))
                     for u in range(1, 11) for v in range(0, u + 2)])]
    drops = []
    for tipo in TIPOS:
        for days in (-1, 0, 1, 7, 8, 30):
            for v, u in ((0, 7), (4, 7), (5, 7), (2, 5), (3, 5), (1, 2), (2, 2), (0, 1), (1, 1), (0, 0)):
                drops.append(case(f"{tipo}_D{days}_{v}of{u}", {"D": days, "vendidas": v, "unidades": u, "tipo": tipo, "room": "Q7"},
                                  PD.desce(days, v, u, tipo)))
    files.append(golden("plano_dono.desce", "SalesLadder::dropApplies(D, sold, units, ruleFor(room, label))",
                        "Drop applies: the night type is priced by the strategy, 0 <= D <= 7, units > 0 and sold / units < 0.6. "
                        "In PHP a night type no rule prices has no rule, hence no drop.", drops))
    files.append(golden("plano_dono.governa", "SalesLadder::ruleFor(room, label) !== null",
                        "Does the strategy price this room on this night type?",
                        [case(f"{q}_{tipo}", {"room": q, "tipo": tipo}, PD.governa(q, tipo)) for q in ROOMS for tipo in TIPOS]))
    nights = ("2026-11-10", "2026-11-13", "2026-11-19", "2026-12-12", "2026-12-24", "2026-12-27", "2026-12-31", "2027-01-23",
              "2027-02-05")
    opening, ceiling = [], []
    for n in nights:
        for q in ROOMS:
            opening.append(case(f"{n}_{q}", {"room": q, "night": n}, run(PD.abertura_publico, q, rn_all[n], rn_all[n]["tipo"])))
            ceiling.append(case(f"{n}_{q}", {"room": q, "night": n}, run(PD.teto_publico, q, rn_all[n], rn_all[n]["tipo"])))
    base = copy.deepcopy(rn_all["2026-11-13"])
    variants = {
        "cap_binds_reference_room": dict(base, pedido_0911=dict(base["pedido_0911"], Q7=1000)),
        "cap_zero_is_ignored": dict(base, pedido_0911=dict(base["pedido_0911"], Q7=0)),
        "cap_missing": {k: v for k, v in base.items() if k != "pedido_0911"},
        "opening_percentile_missing": dict(base, lista={k: v for k, v in base["lista"].items() if k != "40"}),
        "opening_series_missing": {k: v for k, v in base.items() if k != "pares_hidro"},
        "ceiling_percentile_missing": dict(base, hidro={k: v for k, v in base["hidro"].items() if k not in ("50", "65")}),
        "ceiling_below_opening": dict(base, hidro=dict(base["hidro"], **{"50": 500, "65": 600})),
        "cap_binds_source_of_derived": dict(base, pedido_0911=dict(base["pedido_0911"], Double=1200)),
    }
    for name, rn in variants.items():
        for q in ("Q7", "Double", "Afrodite", "VK7"):
            opening.append(case(f"variant_{name}_{q}", {"room": q, "ruler_night": rn}, run(PD.abertura_publico, q, rn, rn["tipo"])))
            ceiling.append(case(f"variant_{name}_{q}", {"room": q, "ruler_night": rn}, run(PD.teto_publico, q, rn, rn["tipo"])))
    files.append(golden("plano_dono.abertura_publico", "SalesLadder::openingPublic(room, rulerNight)",
                        "Opening public price: ruler series at the percentile x multiplier, capped by the reference sent price "
                        "x the night's public factor (a zero or missing reference is no cap); a derived room takes its source "
                        "room's opening x 1.15. null = not priced. `night` refers to the frozen ruler; `ruler_night` is inline.",
                        opening))
    files.append(golden("plano_dono.teto_publico", "SalesLadder::ceilingPublic(room, rulerNight)",
                        "Ceiling public price: ruler value at the ceiling point, never below the opening; without that value, "
                        "the opening.", ceiling))
    prices = []
    for n in ("2026-11-13", "2026-12-24", "2027-02-05", "2026-11-19"):
        rn = rn_all[n]
        for q in ("Q7", "Double", "Q2", "VK7", "VK2"):
            for v in range(0, UNITS[q] + 1):
                for days in (30, 7):
                    prices.append(case(f"{n}_{q}_{v}of{UNITS[q]}_D{days}",
                                       {"room": q, "night": n, "vendidas": v, "unidades": UNITS[q], "D": days},
                                       run(PD.preco_publico, q, rn, rn["tipo"], v, UNITS[q], days)))
    rn = rn_all["2026-11-13"]
    for v, days, ref in ((0, 30, None), (0, 7, None), (1, 7, None), (0, 3, 950.0), (1, 30, 1010.5), (0, 0, 884.1)):
        prices.append(case(f"derived_{v}of1_D{days}_ref_{ref}", {"room": "Afrodite", "night": "2026-11-13", "vendidas": v,
                                                                 "unidades": 1, "D": days, "publico_double": ref},
                           run(PD.preco_publico, "Afrodite", rn, "fds", v, 1, days, ref)))
    files.append(golden("plano_dono.preco_publico", "SalesLadder::publicPrice(room, rulerNight, sold, units, D, referencePublic)",
                        "Public price of the next unit: min(opening x 1.07^steps, max(ceiling, opening)), then x 0.9 with the drop. "
                        "`regra` is free text (not compared). A derived room given its source's public price: that x 1.15, "
                        "no steps, no drop.", prices))
    sent = []
    for q, pub, n in (("Q7", 669, "2026-11-13"), ("Q7", 500, "2026-11-13"), ("Q7", 1615.7, "2026-11-13"), ("VK7", 946, "2026-11-13"),
                      ("VK7", 400, "2026-11-19"), ("Afrodite", 1017, "2026-11-13"), ("Afrodite", 500, "2026-12-31"),
                      ("Double", 579.5, "2026-12-12"), ("Q2", 615.71, "2026-12-27"), ("Q2", 2615.71, "2026-12-27")):
        rn = rn_all[n]
        sent.append(case(f"{q}_{n}_{pub}", {"room": q, "night": n, "publico": pub}, PD.enviado(q, rn, pub, rn["tipo"])))
    files.append(golden("plano_dono.enviado", "SalesLadder::toSent(room, rulerNight, public, rule)",
                        "Public -> sent with the night's public factor of the room's property; never below the quality standard "
                        "(1000 sent) for the rooms with its tag. Only room nights the strategy prices (where the engine calls it).",
                        sent))
    active = []
    others = {"x": {"id": "x", "tipo": "regra", "quartos": ["*"], "de": "2026-11-10", "ate": "2027-02-28"}}
    variants_o = (("on", ON), ("off", OFF), ("other_order_only", others),
                  ("order_rooms_subset", orders(plan_order(quartos=["Q7", "Double", "Q2", "Afrodite"]))),
                  ("order_night_excluded_for_room", orders(plan_order(noites_fora={"Q7": ["2026-11-13"]}))),
                  ("order_night_excluded_for_all", orders(plan_order(noites_fora=["2026-11-13"]))),
                  ("order_lists_nights", orders(plan_order(noites=["2026-11-13"]))),
                  ("order_type_follow_up", orders(plan_order(tipo="acompanhamento"))),
                  ("order_ends_early", orders(plan_order(ate="2026-12-31"))))
    for name, o in variants_o:
        for n, q in (("2026-11-09", "Q7"), ("2026-11-10", "Q7"), ("2026-11-13", "Q7"), ("2026-11-13", "VK7"),
                     ("2027-02-28", "Balcony"), ("2027-03-01", "Q7"), ("2027-01-15", "Double")):
            active.append(case(f"{name}_{n}_{q}", {"night": n, "room": q, "orders": o}, PD.ativo(n, o, q)))
    files.append(golden("plano_dono.ativo", "LadderActivation::isActive(strategy, room, night, orders)",
                        "The strategy acts on the room night: inside 2026-11-10..2027-02-28 and its order in force for the room "
                        "and night (covers, lists the night, not a follow-up).", active))
    return files


# ---------------------------------------------------------------- B. the ladder inside the engine (pace.py)
def weekend_rows(n="2026-11-13") -> dict:
    return {"Q7": {n: row(n, 2006, 2, 7)}, "Double": {n: row(n, 2013, 2, 6)}, "Q2": {n: row(n, 2019, 1, 2)},
            "Afrodite": {n: row(n, 2860, 0, 1)}, "VK7": {n: row(n, 2856, 4, 7)}, "VK2": {n: row(n, 2388, 2, 2)},
            "Balcony": {n: row(n, 2464, 0, 6)}}


def ladder_engine() -> list[dict]:
    c = cfg()
    files = []
    floors = []
    for n in ("2026-11-17", "2026-11-18", "2026-11-13", "2026-11-19", "2026-12-28", "2027-03-02", "2026-11-23"):
        for q in ROOMS:
            for today in ("2026-11-10", "2026-11-17"):
                k = (d(n) - d(today)).days
                if k < 0:
                    continue
                for name, o in (("on", ON), ("off", OFF)):
                    res = run(pace.piso_semana_mercado, c, q, d(n), k, o)
                    floors.append(case(f"{name}_{n}_{q}_D{k}", {"room": q, "night": n, "D": k, "orders": o},
                                       None if res is None else {"floor": res[0], "order_id": res[1]["id"]}))
    files.append(golden("pace.piso_semana_mercado", "LastMinuteMarketFloor::floorFor(room, night, D, orders)",
                        "Last-minute market floor: strategy active for the room night, not a holiday, not Friday/Saturday, ruler "
                        "night of the weekday labels, room listed, 0 <= D <= 7: ruler comparable p50 x multiplier / public "
                        "factor, never below the safety limit.", floors))
    hard = []
    for n in ("2026-11-13", "2026-11-19", "2026-12-24", "2026-12-27", "2027-02-06", "2026-11-05"):
        for q in ROOMS:
            res = pace.piso_duro_do_plano(c, q, d(n))
            hard.append(case(f"{n}_{q}", {"room": q, "night": n},
                             {"floor": res[0], "label": "holiday_safety_floor" if "feriado" in res[1] else "safety_limit"}))
    files.append(golden("pace.piso_duro_do_plano", "LadderHardFloor::for(room, night)",
                        "Floors a ladder price never breaks: the safety limit and, on a holiday night, the general holiday floor "
                        "(800 discounted) in the sent plan. The label is mapped from the engine's text.", hard))
    standard = []
    for n in ("2026-11-12", "2026-11-13", "2026-11-14", "2026-11-19", "2026-12-24", "2026-12-26", "2027-01-23", "2027-03-05",
              "2027-03-04", "2026-11-05"):
        for q in ROOMS:
            for name, o in (("on", ON), ("off", OFF)):
                with PD.com_ordens(o):
                    floor, _ = pace.pisos_enviados(c, q, d(n))
                standard.append(case(f"{name}_{n}_{q}", {"room": q, "night": n, "orders": o}, {"floor": floor}))
    files.append(golden("pace.pisos_enviados", "WeekendFloorOverride::standardFloor(Floors::hardFloor(room, night), ...)",
                        "Standard floor of the night with the strategy on/off: while it acts on a Friday/Saturday outside "
                        "holidays, the weekend owner floor of the rooms with the quality tag gives way to the quality standard.",
                        standard))
    files.append(golden("pace.plano_da_noite", "LadderNightEvaluator::evaluate(night, D, rows, orders, blocked)",
                        "Ladder evaluation of one night for every priced room: {room: {enviado, publico, abertura, degraus, "
                        "desceu, vendidas, total, piso_duro, fator, tipo, limites, base_enviado}} (free text dropped). {} = "
                        "nothing priced; {\"_sem_regua\": true} = ruler gap (night_type_fallback = the calendar label).",
                        night_cases(c)))
    files.append(golden("pace.item_do_plano", "LadderItemBuilder::build(room, quote, D, live, override, ledger, now, inFilter, top)",
                        "Ladder room night -> item (floor = ceiling) or null, plus the line. listing_top = pace.teto_anuncio; "
                        "line.hold_code maps the engine's hold text.", item_cases(c)))
    files.append(golden("pace.plano_no_ledger", "StepLedger::hasLadderStep(room, night)",
                        "Has the room night already had a ladder step (status 'plano')?", ledger_cases()))
    return files


def night_cases(c: dict) -> list[dict]:
    cases = []

    def night(name, n, today, cals, o=ON, blocked=()):
        k = (d(n) - d(today)).days
        with PD.com_ordens(o):
            res = pace.plano_da_noite(c, cals, d(n), k, o, {tuple(x) for x in blocked})
        for value in res.values():
            if isinstance(value, dict):
                parts = value.pop("partes", [])
                value.pop("origem_piso", None)
                value["relation_reasons"] = ([code for marker, code in (("ordem do Recanto: entre", "RELATION_BETWEEN"),
                                                                        ("acima da mais cara", "RELATION_PREMIUM_OVER_MAX"))
                                              if any(marker in p for p in parts)])
        cases.append(case(name, {"night": n, "today": today, "D": k, "calendars": cals, "orders": o, "blocks": list(blocked),
                                 "night_type_fallback": PD.tipo_da_noite(n, c)}, res))

    night("weekend_all_rooms", "2026-11-13", "2026-11-10", weekend_rows())
    night("weekend_inside_window_at_activation_no_drop", "2026-11-14", "2026-11-12", weekend_rows("2026-11-14"))
    n = "2026-11-27"
    base = {"Q7": {n: row(n, 2400, 2, 7)}, "Double": {n: row(n, 2400, 3, 6)}, "Q2": {n: row(n, 2400, 0, 2)},
            "Afrodite": {n: row(n, 3000, 0, 1)}, "VK7": {n: row(n, 2400, 5, 7)}, "VK2": {n: row(n, 2400, 1, 2)}}
    night("weekend_D8_no_drop", n, "2026-11-19", base)
    night("weekend_D7_drop_below_60pct", n, "2026-11-20", base)
    night("weekend_D0", n, "2026-11-27", base)
    night("order_missing_nothing_priced", n, "2026-11-20", base, o=OFF)
    night("order_covers_one_property", n, "2026-11-20", base, o=orders(plan_order(quartos=["VK7", "VK2", "Balcony"])))
    night("source_room_frozen_derived_uses_live_price", n, "2026-11-20", base,
          o=orders(plan_order(), {"id": "m", "tipo": "manual", "quartos": ["Double"], "de": n, "ate": n, "libera": []}))
    night("source_room_fixed_by_order", n, "2026-11-20", base,
          o=orders(plan_order(), {"id": "f", "tipo": "preco_por_data", "quartos": ["Double"], "de": n, "ate": n, "libera": ["fixo"]}))
    night("low_room_blocked_between_uses_other_ref", n, "2026-11-20", base, blocked=[("Q7", n)])
    night("derived_room_without_source_calendar", n, "2026-11-20", {"Afrodite": {n: row(n, 3000, 0, 1)}})
    night("between_room_alone", n, "2026-11-20", {"Q2": {n: row(n, 2400, 1, 2)}})
    night("references_sold_out_between_keeps_base", n, "2026-11-20", dict(base, Q7={n: row(n, 2400, 7, 7)}, Double={n: row(n, 2400, 6, 6)}))
    h = "2026-11-19"
    holiday = {"Q7": {h: row(h, 2733, 1, 7)}, "Double": {h: row(h, 3860, 0, 6)}, "Q2": {h: row(h, 3946, 0, 2)},
               "Afrodite": {h: row(h, 5216, 0, 1)}, "VK7": {h: row(h, 4262, 2, 7)}, "VK2": {h: row(h, 3668, 0, 2)},
               "Balcony": {h: row(h, 1943, 0, 6)}}
    night("holiday_reference_cap_and_safety_floor", h, "2026-11-12", holiday)
    x = "2026-12-24"
    night("festive_one_property_only", x, "2026-12-10", {q: {x: row(x, 4980, 0, UNITS[q])} for q in ROOMS})
    y = "2026-12-27"
    night("festive_sunday_weekday_premium", y, "2026-12-20", {q: {y: row(y, 3000, 1, UNITS[q])} for q in ROOMS})
    w = "2026-11-17"
    night("weekday_not_priced", w, "2026-11-10", {q: {w: row(w, 1232, 0, UNITS[q])} for q in ROOMS})
    night("outside_strategy_window", "2027-03-05", "2027-03-01", weekend_rows("2027-03-05"))
    night("units_from_calendar_total", n, "2026-11-20", {"Q7": {n: dict(row(n, 2400, 2, 7), mu_occupancy="2/5")}})
    night("sold_out_room_priced_as_last_unit", n, "2026-11-20", dict(base, VK7={n: row(n, 2400, 7, 7)}))
    return cases


def item_cases(c: dict) -> list[dict]:
    p = pace.par_pace(c)
    cases = []
    n = "2026-11-13"

    def quote(sent, q="Q7", hard=560.0, v=2, total=7, limits=None, factor=0.6157):
        return {"enviado": sent, "publico": sent * factor, "abertura": 669.0, "degraus": 0, "desceu": False, "vendidas": v,
                "total": total, "piso_duro": hard, "origem_piso": "limite de segurança", "fator": factor, "tipo": "fds",
                "partes": ["abertura", f"{v}/{total} vendidas"], "limites": limits}

    def item(name, q, sent, live, k, r=None, rule=None, ledger=None, now="2026-11-10T12:00:00Z", in_filter=True, **kw):
        r = r if r is not None else row(n, live, kw.get("v", 2), UNITS[q])
        pn = quote(sent, q, **kw)
        led = ledger or []
        it, line = pace.item_do_plano(c, p, copy.deepcopy(pn), q, d(n), k, r, rule, float(live), {"passos": copy.deepcopy(led)},
                                      t(now), in_filter)
        if it:
            it.pop("motivo", None)
        line = {key: value for key, value in line.items() if key not in ("ancora",)}
        line["hold_code"] = hold_code(line.pop("cadencia", None))
        pn = {key: value for key, value in pn.items() if key not in ("partes", "origem_piso")}
        cases.append(case(name, {"room": q, "night": n, "D": k, "ladder": pn, "calendar_row": r, "live_rule": rule,
                                 "live_price": float(live), "ledger": led, "now": now, "in_filter": in_filter,
                                 "listing_top": pace.teto_anuncio(c, q)}, {"item": it, "line": line}))

    item("down_far", "Q7", 1086.57, 2006, 3)
    item("up_far", "Q7", 1086.57, 900, 3)
    item("up_today_blocked", "Q7", 1086.57, 900, 0)
    item("down_today_allowed", "Q7", 1086.57, 2006, 0)
    item("within_tolerance_rule_in_place_no_item", "Q7", 1086.57, 1090, 3)
    item("within_tolerance_floor_far_item_without_direction", "Q7", 1086.57, 1090, 3, r=row(n, 1090, 2, 7, mn=900, mx=5000))
    item("within_tolerance_no_live_floor_item_without_direction", "Q7", 1086.57, 1090, 3, r=row(n, 1090, 2, 7, mn=None, mx=None))
    item("within_tolerance_live_rule_supplies_floor_and_ceiling", "Q7", 1086.57, 1090, 3, r=row(n, 1090, 2, 7, mn=None, mx=None),
         rule={"date": n, "min_price": "-7", "min_price_type": "percent_base", "max_price": "-7", "max_price_type": "percent_base"})
    item("up_exactly_3pct_counts_because_effective_value_is_above", "Q7", 1030.0, 1000, 3)
    item("up_2_1pct_counts_because_effective_value_crosses_3pct", "Q7", 1021.0, 1000, 3)
    item("up_1_5pct_effective_value_inside_tolerance_no_direction", "Q7", 1015.0, 1000, 3)
    item("up_1_5pct_live_rule_far_item_without_direction", "Q7", 1015.0, 1000, 3, r=row(n, 1000, 2, 7, mn=900, mx=5000))
    item("down_exactly_3pct_no_direction_item_because_live_rule_not_close", "Q7", 970.0, 1000, 3)
    item("down_exactly_3pct_no_direction_no_item_when_live_rule_close", "Q7", 970.0, 1000, 3, r=row(n, 1000, 2, 7, mn=975, mx=975))
    item("down_just_over_3pct", "Q7", 969.9, 1000, 3)
    item("capped_at_listing_max_chain", "Q7", 5400.0, 4000, 3)
    item("capped_at_listing_max_already_there", "Double", 6000.0, 4987.6, 3, r=row(n, 4987.6, 2, 6), v=2, total=6)
    item("hard_floor_rounding_up", "Q7", 1904.76, 2500, 3, hard=1904.7619)
    item("hard_floor_above_ladder_price_today", "Q7", 1904.76, 1500, 0, hard=1904.7619)
    item("out_of_filter_with_direction", "Q7", 1086.57, 2006, 3, in_filter=False)
    item("out_of_filter_already_in_place", "Q7", 1086.57, 1090, 3, in_filter=False)
    pace_step = [step("Q7", n, "desce", "2026-11-10T10:00:00+00:00", sold=2, status="atras")]
    item("first_ladder_step_ignores_pace_step_cadence", "Q7", 1086.57, 2006, 3, ledger=pace_step)
    ladder_step = pace_step + [step("Q7", n, "desce", "2026-11-10T11:00:00+00:00", sold=2, total=7, status="plano")]
    item("second_ladder_step_same_day_held", "Q7", 1086.57, 2006, 3, ledger=ladder_step)
    up = [step("Q7", n, "sobe", "2026-11-10T11:00:00+00:00", sold=3, total=7, status="plano")]
    item("booking_since_last_ladder_step_releases_up", "Q7", 1250.0, 1100, 3, ledger=up, v=4, r=row(n, 1100, 4, 7))
    item("no_booking_since_last_ladder_step_held", "Q7", 1250.0, 1100, 3, ledger=up, v=3, r=row(n, 1100, 3, 7))
    arrived = [step("Q7", n, "sobe", "2026-11-09T10:00:00+00:00", on_channel="2026-11-09T11:00+00:00", sold=3, total=7, status="plano")]
    item("previous_step_on_channel_over_24h_allowed", "Q7", 1250.0, 1100, 3, ledger=arrived, v=3, r=row(n, 1100, 3, 7))
    item("opposite_direction_after_ladder_step_allowed", "Q7", 1000.0, 1100, 3, ledger=up, v=3, r=row(n, 1100, 3, 7))
    limits = (1070.0, 1445.0)
    item("between_room_inside_limits_and_tolerance_no_item", "Q2", 1400.0, 1420, 3, r=row(n, 1420, 1, 2), v=1, total=2, limits=limits)
    item("between_room_price_outside_limits_forces_item", "Q2", 1440.0, 1470, 3, r=row(n, 1470, 1, 2), v=1, total=2, limits=limits)
    item("between_room_live_ceiling_outside_limits_forces_item", "Q2", 1400.0, 1420, 3, r=row(n, 1420, 1, 2, mn=1420, mx=1500),
         v=1, total=2, limits=limits)
    q2_step = [step("Q2", n, "desce", "2026-11-10T11:00:00+00:00", sold=1, total=2, status="plano")]
    item("between_room_outside_limits_ignores_cadence", "Q2", 1300.0, 1600, 3, r=row(n, 1600, 1, 2), v=1, total=2, limits=limits,
         ledger=q2_step)
    item("between_room_inside_limits_respects_cadence", "Q2", 1300.0, 1400, 3, r=row(n, 1400, 1, 2), v=1, total=2, limits=limits,
         ledger=q2_step)
    item("premium_room_below_lower_limit_forces_item", "Afrodite", 1770.0, 1730, 3, r=row(n, 1730, 0, 1), v=0, total=1,
         limits=(1749.0, None))
    item("premium_room_above_lower_limit_no_item", "Afrodite", 1770.0, 1790, 3, r=row(n, 1790, 0, 1), v=0, total=1,
         limits=(1749.0, None))
    item("single_unit_sold_out_still_priced", "Afrodite", 1770.0, 2860, 3, r=row(n, 2860, 1, 1), v=1, total=1)
    item("no_direction_rounds_half_to_even", "Q7", 1090.5, 1090.5, 3, r=row(n, 1090.5, 2, 7, mn=900, mx=5000))
    item("no_direction_rounds_half_to_even_odd", "Q7", 1091.5, 1091.5, 3, r=row(n, 1091.5, 2, 7, mn=900, mx=5000))
    return cases


def ledger_cases() -> list[dict]:
    n = "2026-11-13"
    pace_step = [step("Q7", n, "desce", "2026-11-10T10:00:00+00:00", sold=2, status="atras")]
    ladder_step = pace_step + [step("Q7", n, "desce", "2026-11-10T11:00:00+00:00", sold=2, total=7, status="plano")]
    return [case(name, {"ledger": led, "room": q, "night": night}, pace.plano_no_ledger({"passos": led}, q, night))
            for name, led, q, night in (("empty", [], "Q7", n), ("only_pace_steps", pace_step, "Q7", n),
                                        ("ladder_step", ladder_step, "Q7", n), ("ladder_step_other_room", ladder_step, "Q2", n),
                                        ("ladder_step_other_night", ladder_step, "Q7", "2026-11-14"))]


# ---------------------------------------------------------------- C. cadence and ledger
def cadence() -> list[dict]:
    n, now = "2027-03-16", "2027-03-08T16:53:00Z"
    permits = []

    def cad(name, ledger, direction, sold=None, base="quarto", total=None, now_=now, q="VK7"):
        ok, why = pace.cadencia_permite({"passos": ledger}, q, n, direction, t(now_), sold, base, total)
        permits.append(case(name, {"ledger": ledger, "room": q, "night": n, "direction": direction, "now": now_, "sold": sold,
                                   "base": base, "total": total}, {"allowed": ok, "hold_code": hold_code(why)}))

    s = lambda direction, written, **kw: step("VK7", n, direction, written, **kw)  # noqa: E731
    cad("no_previous_step", [], "desce")
    cad("previous_step_other_room", [step("Q7", n, "desce", "2027-03-08T10:58:00+00:00")], "desce")
    cad("previous_step_other_night", [step("VK7", "2027-03-17", "desce", "2027-03-08T10:58:00+00:00")], "desce")
    cad("same_direction_not_on_channel", [s("desce", "2027-03-08T10:58:00+00:00")], "desce")
    cad("same_direction_not_on_channel_even_after_48h", [s("desce", "2027-03-06T10:58:00+00:00")], "desce")
    cad("same_direction_on_channel_under_24h", [s("desce", "2027-03-08T10:58:00+00:00", on_channel="2027-03-08T11:30+00:00")], "desce")
    cad("same_direction_on_channel_exactly_24h", [s("desce", "2027-03-07T16:53:00+00:00", on_channel="2027-03-07T17:30+00:00")], "desce")
    cad("same_direction_on_channel_23h59", [s("desce", "2027-03-07T16:54:00+00:00", on_channel="2027-03-07T17:30+00:00")], "desce")
    cad("same_direction_on_channel_over_24h", [s("desce", "2027-03-07T10:58:00+00:00", on_channel="2027-03-07T11:30+00:00")], "desce")
    cad("opposite_direction_just_now", [s("sobe", "2027-03-08T16:50:00+00:00")], "desce")
    cad("opposite_direction_up_after_down", [s("desce", "2027-03-08T16:50:00+00:00")], "sobe")
    cad("only_last_step_counts_last_opposite", [s("desce", "2027-03-08T10:00:00+00:00"), s("sobe", "2027-03-08T12:00:00+00:00")], "desce")
    cad("only_last_step_counts_last_same", [s("sobe", "2027-03-08T10:00:00+00:00"), s("desce", "2027-03-08T12:00:00+00:00")], "desce")
    cad("last_step_by_timestamp_not_by_list_order", [s("desce", "2027-03-08T12:00:00+00:00"), s("sobe", "2027-03-08T10:00:00+00:00")], "desce")
    cad("timestamp_without_timezone_is_utc", [s("desce", "2027-03-07T10:58:00", on_channel="2027-03-07T11:30")], "desce")
    cad("timestamp_with_z", [s("desce", "2027-03-08T10:58:00Z")], "desce")
    cad("timestamp_minute_precision", [s("desce", "2027-03-07T16:53", on_channel="2027-03-07T17:30")], "desce")
    cad("now_in_other_offset", [s("desce", "2027-03-07T10:58:00+00:00", on_channel="2027-03-07T11:30+00:00")], "desce",
        now_="2027-03-08T08:00:00-03:00")
    up = lambda v, **kw: s("sobe", "2027-03-08T10:58:00+00:00", sold=v, **kw)  # noqa: E731
    dn = lambda v, **kw: s("desce", "2027-03-08T10:58:00+00:00", sold=v, **kw)  # noqa: E731
    cad("booking_releases_up", [up(3, total=7)], "sobe", sold=4, total=7)
    cad("same_count_no_release_up", [up(4, total=7)], "sobe", sold=4, total=7)
    cad("fewer_sold_no_release_up", [up(4, total=7)], "sobe", sold=3, total=7)
    cad("cancellation_releases_down", [dn(4, total=7)], "desce", sold=3, total=7)
    cad("same_count_no_release_down", [dn(4, total=7)], "desce", sold=4, total=7)
    cad("more_sold_no_release_down", [dn(3, total=7)], "desce", sold=4, total=7)
    cad("sold_not_passed_no_release", [up(3, total=7)], "sobe", sold=None, total=7)
    cad("ledger_without_sold_no_release", [up(None, total=7)], "sobe", sold=4, total=7)
    cad("different_base_no_release", [up(3, total=16, base="pousada")], "sobe", sold=4, base="quarto", total=7)
    cad("same_base_property_release", [up(3, total=16, base="pousada")], "sobe", sold=4, base="pousada", total=16)
    cad("ledger_without_base_counts_as_room", [dict(up(3, total=7), base=None)], "sobe", sold=4, base="quarto", total=7)
    cad("ledger_without_base_vs_property_no_release", [dict(up(3, total=7), base=None)], "sobe", sold=4, base="pousada", total=16)
    cad("different_total_no_release", [up(3, total=7)], "sobe", sold=4, total=5)
    cad("total_not_passed_release", [up(3, total=7)], "sobe", sold=4, total=None)
    cad("ledger_without_total_release", [up(3, total=None)], "sobe", sold=4, total=7)
    cad("zero_to_one_releases", [up(0, total=7)], "sobe", sold=1, total=7)
    cad("sold_zero_in_ledger_is_a_value", [dn(0, total=7)], "desce", sold=0, total=7)
    cad("equal_timestamps_first_in_list_wins_same_direction",
        [s("desce", "2027-03-08T10:58:00+00:00"), s("sobe", "2027-03-08T10:58:00+00:00")], "desce")
    cad("equal_timestamps_first_in_list_wins_opposite",
        [s("sobe", "2027-03-08T10:58:00+00:00"), s("desce", "2027-03-08T10:58:00+00:00")], "desce")
    files = [golden("pace.cadencia_permite", "StepCadence::permits(ledger, room, night, direction, now, sold, base, total)",
                    "One step per night per day and direction; only the last step (greatest instant, first on ties) counts; "
                    "opposite direction at once; booking/cancellation since that step (same base, same total) releases one "
                    "more; otherwise it must be on the channel and at least 24 h old.", permits)]

    bypass = []
    today = "2027-03-08"

    def new_order(name, order, ledger, q="Q7"):
        bypass.append(case(name, {"ledger": ledger, "room": q, "night": n, "order": order, "today": today},
                           run(pace.ordem_nova_sem_passo, {"passos": ledger}, q, n, order, d(today))))

    o = {"id": "o", "tipo": "alvo_por_data", "quartos": ["Q7"], "de": n, "ate": n, "data": today, "criada_em": "2027-03-08T15:00:00Z"}
    new_order("created_today_no_steps", o, [])
    new_order("created_today_step_before_creation", o, [step("Q7", n, "desce", "2027-03-08T10:58:00+00:00")])
    new_order("created_today_step_after_creation", o, [step("Q7", n, "desce", "2027-03-08T15:30:00+00:00")])
    new_order("created_today_step_at_creation_instant", o, [step("Q7", n, "desce", "2027-03-08T15:00:00+00:00")])
    new_order("created_today_step_after_creation_other_room", o, [step("Double", n, "desce", "2027-03-08T15:30:00+00:00")])
    new_order("created_today_step_after_creation_other_night", o, [step("Q7", "2027-03-17", "desce", "2027-03-08T15:30:00+00:00")])
    new_order("created_yesterday", dict(o, data="2027-03-07", criada_em="2027-03-07T15:00:00Z"), [])
    new_order("without_creation_instant", {k: v for k, v in o.items() if k != "criada_em"}, [])
    new_order("creation_instant_without_timezone", dict(o, criada_em="2027-03-08T15:00"), [step("Q7", n, "desce", "2027-03-08T14:59:00")])
    new_order("no_order", None, [])
    new_order("step_of_other_direction_after_creation_also_blocks", o, [step("Q7", n, "sobe", "2027-03-08T16:00:00+00:00")])
    files.append(golden("pace.ordem_nova_sem_passo", "StepCadence::newOrderBypass(ledger, room, night, order, today)",
                        "An owner order given today takes effect at once, once: it needs its creation instant and no step of the "
                        "room night written at or after it.", bypass))

    record = []
    written = {"VK7": {"tipo": "data", "quarto": "VK7", "data": n, "min_price": 1105, "max_price": 1105, "preco_antes": 1300.0,
                       "pace": {"status": "muito_atras", "direcao": "desce", "atual": 1300.0, "novo": 1105, "alvo_enviado": 446,
                                "vendidas": 0, "base": "quarto", "total": 7}},
               "ladder": {"tipo": "data", "quarto": "Q7", "data": n, "min_price": 1099, "max_price": 1099, "preco_antes": 1000.0,
                          "pace": {"status": "plano", "direcao": "sobe", "atual": 1000.0, "novo": 1099, "alvo_enviado": 1098,
                                   "vendidas": 2, "base": "quarto", "total": 7}},
               "no_direction": {"tipo": "data", "quarto": "Double", "data": n, "min_price": 1843, "max_price": 1843,
                                "preco_antes": 1900.0, "pace": {"status": "plano", "direcao": None, "atual": 1900.0, "novo": 1843,
                                                               "alvo_enviado": 1843, "vendidas": 5, "base": "quarto", "total": 6}},
               "property": {"tipo": "data", "quarto": "Q2", "data": n, "min_price": 766, "max_price": 766, "preco_antes": 1250.0,
                            "pace": {"status": "ritmo", "direcao": "desce", "atual": 1250.0, "novo": 766, "alvo_enviado": 653,
                                     "vendidas": 3, "base": "pousada", "total": 15}}}

    def reg(name, items, now_="2027-03-08T16:58:20Z", before=None):
        path = WORK / f"ledger-{len(record)}.json"
        ledger = {"passos": copy.deepcopy(before or [])}
        pace.registrar_passos(ledger, copy.deepcopy(items), t(now_), path)
        record.append(case(name, {"ledger_before": before or [], "written_steps": [it["pace"] for it in items if "pace" in it],
                                  "written_items": items, "now": now_},
                           json.loads(path.read_text(encoding="utf-8"))["passos"]))

    reg("pace_step_recorded", [written["VK7"]])
    reg("ladder_step_recorded", [written["ladder"]])
    reg("item_without_direction_not_recorded", [written["no_direction"]])
    reg("property_base_recorded", [written["property"]])
    reg("mixed_batch_appends", list(written.values()),
        before=[step("VK7", n, "sobe", "2027-03-07T10:00:00+00:00", on_channel="2027-03-07T11:00+00:00")])
    reg("instant_truncated_to_seconds", [written["VK7"]], now_="2027-03-08T16:58:20.987654Z")
    files.append(golden("pace.registrar_passos", "StepLedger::record(proposals, now)",
                        "Only written items carrying a step direction become rows: room, night, from/to, direction, status, "
                        "sold, base, total, written at (seconds), not yet on the channel. Items without a step block "
                        "(protection, relations) never reach this function's output.", record))

    mark = []

    def marked(name, ledger, until):
        path = WORK / f"ledger-mark-{len(mark)}.json"
        data = {"passos": copy.deepcopy(ledger)}
        limit = {k: t(v) for k, v in until.items()} if isinstance(until, dict) else t(until)
        count = pace.marcar_envio(data, limit, path)
        mark.append(case(name, {"ledger": ledger, "pushed_until": until},
                         {"marked": count, "ledger": json.loads(path.read_text(encoding="utf-8"))["passos"]}))

    three = [step("VK7", n, "desce", "2027-03-08T10:58:00+00:00"), step("Q7", n, "sobe", "2027-03-08T12:10:30+00:00"),
             step("Balcony", n, "desce", "2027-03-08T10:58")]
    marked("single_instant_marks_older_steps", three, "2027-03-08T11:30:00Z")
    marked("single_instant_equal_to_step_marks", three, "2027-03-08T12:10:30Z")
    marked("minute_precision_step_needs_whole_minute", three, "2027-03-08T10:58:30Z")
    marked("minute_precision_step_marked_at_59s", three, "2027-03-08T10:58:59Z")
    marked("per_room_instants", three, {"VK7": "2027-03-08T11:30:00Z", "Q7": "2027-03-08T11:30:00Z"})
    marked("per_room_room_missing_is_untouched", three, {"Balcony": "2027-03-08T11:30:00Z"})
    marked("already_marked_is_kept", [step("VK7", n, "desce", "2027-03-08T10:58:00+00:00", on_channel="2027-03-08T11:00+00:00")],
           "2027-03-08T15:00:00Z")
    files.append(golden("pace.marcar_envio", "StepLedger::markOnChannel(pushedAt)",
                        "Marks steps as arrived on the channel: written no later than the push (one instant, or one per room). "
                        "A step stored to the minute counts only when its whole minute ended before the push.", mark))
    return files


# ---------------------------------------------------------------- D. sold-out protection and no-effect filter
def protection() -> list[dict]:
    c = cfg()
    weekend = anchor(560, 760, 1100, 1420, 1800)
    weekday = anchor(290, 360, 520, 640, 780, tipo="semana")
    cases = []

    def prot(name, q, n, r, anc, rule=None, extra=None, ceiling=None, patch=None, o=OFF):
        cc = copy.deepcopy(c)
        for path, value in patch or []:
            target = cc
            parts = path.split(".")
            for part in parts[:-1]:
                target = target[part]
            target[parts[-1]] = value
        v, u = comum.vendidas_total(r, UNITS[q])
        with PD.com_ordens(o):
            standard, _ = pace.pisos_enviados(cc, q, d(n))
            item = pace.protecao_esgotada(cc, pace.par_pace(cc), anc, "fixture", q, d(n), r, v, u, rule, extra, ceiling)
        expected = None
        if item:
            text = item.pop("motivo")
            expected = dict(item, floor_source=("live_floor_kept" if "mantido" in text else "extra_floor" if "piso da ordem" in text
                                                else "standard_floor" if "piso do dono" in text else "market_percentile"))
        cases.append(case(name, {"room": q, "night": n, "calendar_row": r, "anchor": anc, "live_rule": rule, "extra_floor": extra,
                                 "relation_ceiling": ceiling, "sold": v, "units": u, "standard_floor": standard,
                                 "public_factor": pace.fator_publico(cc, cc["por_curto"][q]["pousada"]),
                                 "tolerance": pace.par_pace(cc)["tolerancia"]}, expected))

    f, w = "2027-03-12", "2027-03-10"
    prot("floor_raised_ceiling_free", "Q7", f, row(f, 2100, 7, 7, 2100, 5000), weekend)
    prot("already_protected_exact", "Q7", f, row(f, 2300, 7, 7, 2254, 5000), weekend)
    prot("already_protected_within_tolerance", "Q7", f, row(f, 2300, 7, 7, 2187, 5000), weekend)
    prot("just_below_tolerance_gets_item", "Q7", f, row(f, 2300, 7, 7, 2186, 5000), weekend)
    prot("already_protected_higher_floor", "Q7", f, row(f, 3000, 7, 7, 3000, 5000), weekend)
    prot("higher_floor_with_calendar_ceiling_no_rule_comes_down", "Q7", f, row(f, 3000, 7, 7, 3000, 3000), weekend)
    prot("higher_floor_with_ceiling_kept_when_rule_known", "Q7", f, row(f, 3000, 7, 7, 3000, 3000), weekend,
         rule={"date": f, "min_price": "156", "min_price_type": "percent_base", "max_price": "156", "max_price_type": "percent_base",
               "reason": "Plano do dono 09/11 (fds)"})
    prot("higher_floor_written_by_retired_rule_comes_down", "Q7", f, row(f, 3000, 7, 7, 3000, 3000), weekend,
         rule={"date": f, "min_price": "156", "max_price": "156", "reason": "Regra J: topo do mercado"})
    prot("retired_rule_marker_with_exemption_keeps_floor", "Q7", f, row(f, 3000, 7, 7, 3000, 3000), weekend,
         rule={"date": f, "min_price": "156", "max_price": "156", "reason": "Proteção (Regra J desligada pelo dono)"})
    prot("floor_ok_calendar_ceiling_within_1_of_max_is_free", "Q7", f, row(f, 3000, 7, 7, 3000, 4999), weekend)
    prot("floor_ok_calendar_ceiling_just_below_max", "Q7", f, row(f, 3000, 7, 7, 3000, 4998.5), weekend)
    prot("floor_ok_live_rule_has_ceiling", "Q7", f, row(f, 3000, 7, 7, 3000, None), weekend,
         rule={"date": f, "max_price": "330", "max_price_type": "percent_base", "reason": "teto antigo"})
    prot("unknown_live_floor_no_item", "Q7", f, row(f, 2100, 7, 7, None, None), weekend)
    prot("live_floor_zero_is_unknown", "Q7", f, row(f, 2100, 7, 7, 0, None), weekend)
    prot("live_floor_from_rule_percent", "Q7", f, row(f, 2100, 7, 7, None, None), weekend,
         rule={"date": f, "min_price": "70", "min_price_type": "percent_base"})
    prot("live_floor_from_rule_fixed", "Q7", f, row(f, 2100, 7, 7, None, None), weekend,
         rule={"date": f, "min_price": "2300", "min_price_type": "fixed"})
    prot("standard_floor_above_percentile_weekday", "Q7", w, row(w, 1000, 7, 7, 1000, 5000), weekday)
    prot("percentile_above_standard_floor_weekday_other_property", "VK7", w, row(w, 800, 7, 7, 800, 5000), weekday)
    prot("room_without_quality_tag", "Balcony", f, row(f, 1400, 6, 6, 1400, 1400), weekend)
    prot("two_unit_room", "VK2", f, row(f, 1700, 2, 2, 1480, 5000), weekend)
    prot("single_unit_room_already_at_standard_floor", "Afrodite", w, row(w, 1500, 1, 1, 1500, 10000), weekday)
    prot("single_unit_room_floor_raised", "Afrodite", w, row(w, 1300, 1, 1, 1300, 10000), weekday)
    prot("overbooked_counts_as_sold_out", "VK2", f, dict(row(f, 1700, 2, 2, 1480, 5000), mu_occupancy="3/2"), weekend)
    prot("extra_floor_above_percentile", "Double", f, row(f, 2300, 6, 6, 2100, 5000), weekend, extra=2600)
    prot("extra_floor_below_percentile_ignored", "Double", f, row(f, 2300, 6, 6, 2100, 5000), weekend, extra=1800)
    g = "2027-03-13"
    prot("relation_ceiling_floor_and_ceiling", "Afrodite", g, row(g, 2400, 1, 1, 2300, 10000), weekend, extra=2540, ceiling=2857)
    prot("relation_ceiling_floor_capped_by_it", "Afrodite", g, row(g, 2400, 1, 1, 2300, 10000), weekend, extra=3200, ceiling=2857)
    prot("relation_ceiling_already_there_within_1pct_base", "Afrodite", g, row(g, 2700, 1, 1, 2600, 2840), weekend, extra=2540,
         ceiling=2857)
    prot("relation_ceiling_differs_more_than_1pct_base", "Afrodite", g, row(g, 2700, 1, 1, 2600, 2800), weekend, extra=2540,
         ceiling=2857)
    prot("relation_ceiling_floor_low", "Afrodite", g, row(g, 2700, 1, 1, 2000, 2857), weekend, extra=2540, ceiling=2857)
    prot("percentile_above_listing_max_capped", "Q7", f, row(f, 3000, 7, 7, 3000, 5000), anchor(2000, 2600, 3000, 3400, 3900))
    prot("holiday_floor_is_the_standard_floor", "VK7", "2027-03-26", row("2027-03-26", 2000, 7, 7, 2000, 5000),
         anchor(700, 900, 1200, 1500, 1900, tipo="feriado"))
    prot("tolerance_from_config", "Q7", f, row(f, 2300, 7, 7, 2100, 5000), weekend, patch=[("parametros.pace.tolerancia", 0.10)])
    prot("flat_low_anchor_standard_floor_wins_already_protected", "VK7", f, row(f, 1500, 7, 7, 1480, 5000),
         anchor(500, 500, 500, 500, 500))
    prot("flat_low_anchor_standard_floor_wins_item", "VK7", f, row(f, 1500, 7, 7, 1200, 5000), anchor(500, 500, 500, 500, 500))
    prot("ladder_strategy_active_weekend_floor_is_quality_standard", "Double", "2026-12-05", row("2026-12-05", 1700, 6, 6, 1500, 5000),
         anchor(499, 714, 791, 1052, 1189, searched="2026-11-28"), o=ON)
    files = [golden("pace.protecao_esgotada", "SoldOutProtection::propose(room, live, override, anchor, factor, standardFloor, extra, ceiling)",
                    "Sold-out protection item or null. standard_floor = pace.pisos_enviados of the night (with the case's orders); "
                    "public_factor = the configured factor of the property. floor_source maps the reason text (the engine names "
                    "where the floor came from since 12 Nov). Since 12 Nov the protection keeps a higher live floor when it "
                    "releases a ceiling, unless no rule is known or the rule's reason marks the retired Regra J.", cases)]

    noop = []
    n = "2026-11-24"

    def effect(name, it, r, rule=None, delete=()):
        cals = {it["quarto"]: {n: r}} if r is not None else {}
        rules = {it["quarto"]: {n: rule}} if rule else {}
        kept, dropped = pace.sem_efeito(c, [copy.deepcopy(it)], cals, rules, {tuple(x) for x in delete})
        noop.append(case(name, {"item": it, "calendar_row": r, "live_rule": rule, "delete_first": list(delete)},
                         {"kept": bool(kept), "dropped_as_no_effect": bool(dropped)}))

    b = lambda mn, mx=None, **kw: dict({"tipo": "data", "quarto": "Balcony", "data": n, "min_price": mn},  # noqa: E731
                                       **({"max_price": mx} if mx is not None else {}), **kw)
    effect("same_floor_and_ceiling_dropped", b(825.5, 825.5), row(n, 825.5, 0, 6))
    effect("one_whole_percent_higher_kept", b(826, 826), row(n, 825.5, 0, 6))
    effect("live_values_within_1_dropped", b(825.5, 825.5), row(n, 826.4, 0, 6))
    effect("effective_rounding_makes_it_equal", b(820, 830), row(n, 825.5, 0, 6))
    effect("more_than_1_kept", b(840, 840), row(n, 825.5, 0, 6))
    effect("live_ceiling_differs_kept", b(825.5, 825.5), row(n, 825.5, 0, 6, 825.5, 5000))
    effect("live_floor_differs_kept", b(825.5, 825.5), row(n, 825.5, 0, 6, 690, 825.5))
    effect("item_without_ceiling_always_kept", b(825.5), row(n, 825.5, 0, 6))
    effect("item_with_min_stay_always_kept", b(825.5, 825.5, min_stay=2), row(n, 825.5, 0, 6))
    effect("night_in_delete_first_always_kept", b(825.5, 825.5), row(n, 825.5, 0, 6), delete=[("Balcony", n)])
    effect("unknown_live_floor_kept", b(825.5, 825.5), row(n, 825.5, 0, 6, None, None))
    effect("live_values_from_rule_dropped", b(825.5, 825.5), row(n, 825.5, 0, 6, None, None),
           rule={"date": n, "min_price": "-35", "max_price": "-35"})
    effect("no_calendar_row_kept", b(825.5, 825.5), None)
    effect("floor_below_ceiling_item_compares_greatest_effective_value", b(700, 830), row(n, 825.5, 0, 6))
    effect("floor_below_ceiling_item_kept_when_live_differs", b(700, 900), row(n, 825.5, 0, 6))
    q7 = {"tipo": "data", "quarto": "Q7", "data": n, "min_price": 985, "max_price": 985}
    effect("other_room_base_rounding", q7, row(n, 985.32, 1, 7))
    effect("other_room_kept", q7, row(n, 1232, 1, 7, 1220, 5000))
    files.append(golden("pace.sem_efeito", "NoEffectFilter::filter(items, rooms, live, overrides, deleteFirst) (rounding-only off)",
                        "An item with a ceiling whose greatest effective value equals the live floor and ceiling (within 1) changes "
                        "nothing and leaves the plan. Items without a ceiling, with a minimum stay or deleted first stay.", noop))
    return files


# ---------------------------------------------------------------- E. owner orders (ordens.py)
def owner_orders() -> list[dict]:
    files = []
    fixed = {"id": "fixed-a", "tipo": "preco_por_data", "quartos": ["VK7"], "de": "2026-12-01", "ate": "2026-12-03",
             "noites": ["2026-12-02"], "libera": ["fixo"]}
    manual = {"id": "manual-2026-11-28-q7-2026-12-01", "tipo": "manual", "quartos": ["Q7"], "de": "2026-12-01", "ate": "2026-12-05",
              "data": "2026-11-28", "libera": []}
    floor_a = {"id": "floor-a", "tipo": "piso_por_data", "quartos": ["Q7", "VK7"], "de": "2026-12-01", "ate": "2026-12-10",
               "noites": ["2026-12-01", "2026-12-02"], "pisos_enviados": {"Q7": 700, "VK7": 400}, "libera": ["abaixo_piso"]}
    target_a = {"id": "target-a", "tipo": "alvo_por_data", "quartos": ["Q7", "Double"], "de": "2026-12-01", "ate": "2026-12-10",
                "percentil": 50, "pisos_enviados": {"Q7": 650}, "libera": ["acima_15"],
                "vendidas_base": {"Q7": {"2026-12-03": 2}}}
    target_b = {"id": "target-b", "tipo": "alvo_por_data", "quartos": ["*"], "de": "2026-12-03", "ate": "2026-12-04", "percentil": 40,
                "pisos_enviados": {"Q7": 720, "Double": 900}, "libera": ["abaixo_piso"], "noites_fora": {"Double": ["2026-12-04"]},
                "vendidas_base": {"Q7": {"2026-12-03": 1}, "Double": {"2026-12-03": 3}}}
    target_c = {"id": "target-c", "tipo": "alvo_por_data", "quartos": ["Double"], "de": "2026-12-05", "ate": "2026-12-06",
                "libera": [], "noites": ["2026-12-06"]}
    follow = {"id": "follow-a", "tipo": "acompanhamento", "quartos": ["*"], "de": "2026-12-01", "ate": "2026-12-31", "libera": []}
    rule_x = {"id": "rule-x", "tipo": "regra", "quartos": ["*"], "de": "2026-12-01", "ate": "2026-12-31", "libera": ["estadia"],
              "noites_fora": ["2026-12-25"]}
    book = orders(fixed, manual, floor_a, target_a, target_b, target_c, follow, rule_x)
    probes = [(q, n) for q in ("Q7", "VK7", "Double", "Balcony") for n in
              ("2026-11-30", "2026-12-01", "2026-12-02", "2026-12-03", "2026-12-04", "2026-12-05", "2026-12-06", "2026-12-25")]
    cover, valid = [], []
    for o in book.values():
        for q, n in probes:
            cover.append(case(f"{o['id']}_{q}_{n}", {"order": o, "room": q, "night": n}, ORD.cobre(o, q, d(n))))
            valid.append(case(f"{o['id']}_{q}_{n}", {"orders": book, "order_id": o["id"], "room": q, "night": n},
                              (ORD.ordem_valida(book, o["id"], q, d(n)) or {}).get("id")))
    for q, n in (("Q7", "2026-12-02"), ("VK7", "2026-12-02")):
        valid.append(case(f"unknown_id_{q}_{n}", {"orders": book, "order_id": "missing", "room": q, "night": n},
                          (ORD.ordem_valida(book, "missing", q, d(n)) or {}).get("id")))
        valid.append(case(f"no_id_{q}_{n}", {"orders": book, "order_id": None, "room": q, "night": n},
                          (ORD.ordem_valida(book, None, q, d(n)) or {}).get("id")))
    files.append(golden("ordens.cobre", "OwnerOrder::covers(room, night)",
                        "Room listed (or *), night within from..to and not moved to a newer order (list for every room or map "
                        "room -> nights). The `noites` list is not part of coverage.", cover))
    files.append(golden("ordens.ordem_valida", "OrderBook::inForce(id, room, night)?->id",
                        "The order exists, covers the room and night, is not a follow-up and lists the night when it lists nights.",
                        valid))
    queries = []
    for q, n in probes:
        queries.append(case(f"{q}_{n}", {"orders": book, "room": q, "night": n}, {
            "manual_freeze": (ORD.congelada(book, q, d(n)) or {}).get("id"),
            "fixed_price": [o["id"] for o in ORD.ordens_por_data(book, q, d(n))],
            "floor": (lambda r: None if r is None else {"amount": r[0], "order_id": r[1]["id"]})(ORD.piso_da_ordem(book, q, d(n))),
            "target": (ORD.alvo_da_ordem(book, q, d(n)) or {}).get("id"),
            "base_sold": (lambda r: None if r is None else {"sold": r[0], "order_id": r[1]["id"]})(ORD.vendidas_base_escada(book, q, d(n))),
            "without_targets": sorted(pace.sem_alvo(book)),
        }))
    files.append(golden("ordens.consultas", "OrderBook::manualFreeze / fixedPrice / floorFor / targetFor / baseSold / withoutTargets",
                        "Per room night: the manual freeze (ordens.congelada), the fixed-price orders (ordens_por_data), the order "
                        "floor (piso_da_ordem: lowest of floor and target orders), the target order (alvo_da_ordem: lowest "
                        "percentile, default 50), the base sold count (vendidas_base_escada: first covering order) and the ids "
                        "left without target orders (pace.sem_alvo).", queries))
    manual_cases = []
    for name, room, first, last, today in (("short_change", "Q7", "2026-12-01", "2026-12-03", "2026-11-28"),
                                           ("long_change_cut_at_7_days", "VK7", "2026-11-29", "2026-12-20", "2026-11-28"),
                                           ("same_day", "Double", "2026-11-28", "2026-11-28", "2026-11-28")):
        path = WORK / f"orders-{name}.json"
        path.write_text(json.dumps({"ordens": [manual]}), encoding="utf-8")
        recorded = ORD.registrar_manual(room, first, last, "manual change (golden fixture)", 7, path, d(today))
        stored = json.loads(path.read_text(encoding="utf-8"))["ordens"]
        manual_cases.append(case(name, {"room": room, "from": first, "to": last, "today": today, "days": 7, "book": [manual]},
                                 {"order": recorded, "book_ids": [o["id"] for o in stored]}))
    files.append(golden("ordens.registrar_manual", "ManualFreeze::record(room, from, to, today) + OrderBook::with(order)",
                        "A manual change becomes a manual order: id manual-<today>-<room lowercase>-<from>, from..min(to, today + "
                        "7 days), given today; recording it again replaces it.", manual_cases))
    return files


# ---------------------------------------------------------------- F. proportional mode (escada_reservas.py)
MARKET_TOP = {"periodos": [{"rotulo": "long weekend", "datas": ["2027-03-26", "2027-03-27"], "topo_a": 3100.0},
                           {"rotulo": "no top measured", "datas": ["2027-04-21"], "topo_a": None}],
              "referencias_topo": [{"tipo": "semana", "data": "2027-03-02", "rotulo": "weekday ref A", "topo_a": 1800.0},
                                   {"tipo": "semana", "data": "2027-03-23", "rotulo": "weekday ref B", "topo_a": 1900.0},
                                   {"tipo": "fds", "data": "2027-03-05", "rotulo": "weekend ref", "topo_a": 2000.0}]}


def proportional() -> list[dict]:
    c = cfg()
    files = []
    line = []
    for current, target in ((1000.0, 2000.0), (2000.0, 1000.0), (1500.0, 1500.0)):
        for u, before, after in ((7, 0, 1), (7, 3, 4), (7, 6, 7), (7, 3, 6), (7, 4, 3), (7, 7, 7), (7, 0, 9), (2, 0, 1), (2, 1, 2),
                                 (1, 0, 1), (6, 2, 0)):
            line.append(case(f"{current:.0f}_to_{target:.0f}_{before}_{after}_of_{u}",
                             {"current": current, "target": target, "units": u, "sold_before": before, "sold_after": after},
                             ER.proporcional(current, target, u, before, after)))
    files.append(golden("escada_reservas.proporcional", "ProportionalLadder::proportional(current, target, units, before, after)",
                        "new = target - (target - current) x (units - min(after, units)) / (units - before); unchanged when "
                        "the room was sold out before.", line))
    steps = []
    for q in ("Q7", "VK7", "Balcony", "Afrodite"):
        for n in ("2027-03-10", "2027-03-12"):
            for current, bookings in ((739.0, 1), (739.0, 2), (739.0, -1), (739.0, 0), (1215.0, 1), (5000.0, 1), (1300.0, -2)):
                res = run(ER.passo, c, q, d(n), current, bookings, d("2027-03-08"))
                steps.append(case(f"{q}_{n}_{current:.0f}_{bookings:+d}", {"room": q, "night": n, "current": current,
                                                                         "bookings": bookings, "today": "2027-03-08"},
                                  {"new": res["novo"], "ceiling": res["teto"]}))
    for n in ("2027-03-08", "2027-03-07"):
        res = run(ER.passo, c, "Q7", d(n), 739.0, 1, d("2027-03-08"))
        steps.append(case(f"today_or_past_{n}", {"room": "Q7", "night": n, "current": 739.0, "bookings": 1, "today": "2027-03-08"},
                          {"new": res["novo"], "ceiling": res["teto"]}))
    files.append(golden("escada_reservas.passo", "ProportionalLadder::fallbackStep(room, night, current, bookings, today) and ceiling()",
                        "Fallback step without a market target: x (1 + 7%)^bookings, up rounded up (capped at the floor of the "
                        "ceiling; no move when that is not above), down rounded down; today and past nights never move. "
                        "ceiling = owner floor of the weekday/weekend / showcase factor (discount room: 0.9 x reference).", steps))
    files.append(golden("escada_reservas.piso_dono", "ProportionalLadder::ownerFloor(room, night)",
                        "Cancellation floor: the owner floor (temporary 300 until 2026-11-08) in the most discounted plan / its "
                        "factor (discount room: 0.9 x reference).",
                        [case(f"{q}_{n}", {"room": q, "night": n}, run(ER.piso_dono, c, q, d(n)))
                         for q in ROOMS for n in ("2026-11-06", "2026-11-08", "2026-11-09", "2027-03-10", "2027-03-12")]))
    files.append(golden("escada_reservas.topo_publico", "MarketTopTable::topFor(night)",
                        "Public market top: the holiday period that lists the night, else the nearest reference of the same kind "
                        "(weekday / Fri-Sat; first on ties). [value, label] or [null, null].",
                        [case(n, {"market": MARKET_TOP, "night": n}, run(ER.topo_publico, MARKET_TOP, d(n)))
                         for n in ("2027-03-26", "2027-04-21", "2027-03-10", "2027-03-12", "2027-03-13", "2027-03-15", "2027-03-01",
                                   "2027-06-01")]))
    files.append(golden("escada_reservas.alvo", "ProportionalLadder::target(room, night)",
                        "Sent target: market top / public factor of the property, capped at the listing maximum, two decimals "
                        "(discount room: 0.9 x reference's target). [value, label] or [null, null].",
                        [case(f"{q}_{n}", {"market": MARKET_TOP, "room": q, "night": n}, run(ER.alvo, c, MARKET_TOP, q, d(n)))
                         for q in ROOMS for n in ("2027-03-10", "2027-03-12", "2027-03-26", "2027-04-21")]))
    files.append(golden("escada_reservas.lotes", "BatchImportDetector::batchNights(bookings, since)",
                        "Room nights sold by batch imports: bookings created in the same minute, 3 or more, since the given instant; "
                        "one per booking and night. `room` is the room code of the listing.", batch_cases(c)))
    files.append(golden("escada_reservas.plano", "ProportionalLadder::plan(before, after, today, batch)",
                        "Moves of the proportional ladder between two readings, without the follow-up of the ordering relations "
                        "(escada_reservas.acompanhar is replaced by an empty list while the cases run: it belongs to the relations "
                        "pass). `motivo` is free text and dropped.", proportional_plans(c)))
    return files


def batch_cases(c: dict) -> list[dict]:
    listing = {q["curto"]: q["id"] for q in c["quartos"]}
    original = ER.carregar_quartos
    ER.carregar_quartos = lambda *a, **kw: c
    cases = []
    try:
        def booking(room, created, check_in, days=1, status="booked"):
            return {"listing_id": listing[room], "room": room, "booking_status": status, "booked_date": created,
                    "check_in": check_in, "no_of_days": days}

        three = [booking("Q7", "2027-03-01T15:03:10", "2027-03-10", 2), booking("VK7", "2027-03-01T15:03:40", "2027-03-11"),
                 booking("Q7", "2027-03-01T15:03:59", "2027-03-10")]
        for name, items, since in (
                ("three_in_one_minute", three, None),
                ("two_in_one_minute_not_a_batch", three[:2], None),
                ("spread_over_two_minutes", three[:2] + [booking("Q7", "2027-03-01T15:04:01", "2027-03-10")], None),
                ("cancelled_not_counted", three[:2] + [booking("Q7", "2027-03-01T15:03:59", "2027-03-10", status="cancelled")], None),
                ("since_excludes_older", three + [booking("VK2", "2027-02-27T09:00:00", "2027-03-10")] * 3, "2027-03-01T00:00"),
                ("since_after_batch", three, "2027-03-01T15:04")):
            res = ER.lotes(items, since, 3)
            cases.append(case(name, {"bookings": items, "since": since, "min_same_minute": 3},
                              {f"{k[0]}|{k[1]}": v for k, v in sorted(res.items())}))
    finally:
        ER.carregar_quartos = original
    return cases


def proportional_plans(c: dict) -> list[dict]:
    original = ER.acompanhar
    ER.acompanhar = lambda *a, **kw: []
    cases = []
    try:
        def plan(name, before, after, today="2027-03-08", batch=None, market=MARKET_TOP):
            res = ER.plano(c, market, before, after, d(today), {tuple(k.split("|")): v for k, v in (batch or {}).items()})
            for item in res:
                item.pop("motivo", None)
            cases.append(case(name, {"market": market, "calendars_before": before, "calendars_after": after, "today": today,
                                     "batch_nights": batch or {}}, res))

        n = "2027-03-10"
        plan("one_sale_weekday", {"Q7": {n: row(n, 1300, 2, 7)}}, {"Q7": {n: row(n, 1300, 3, 7)}})
        plan("two_sales_floor_ceiling_locked", {"VK7": {n: row(n, 1000, 2, 7)}}, {"VK7": {n: row(n, 1000, 4, 7)}})
        plan("free_ceiling_keeps_no_ceiling", {"VK7": {n: row(n, 1000, 2, 7, 944, 5000)}}, {"VK7": {n: row(n, 1000, 3, 7, 944, 5000)}})
        plan("cancellation_goes_down_not_below_owner_floor", {"VK7": {n: row(n, 1400, 4, 7)}}, {"VK7": {n: row(n, 1400, 2, 7)}})
        plan("cancellation_low_price_stops_at_owner_floor", {"VK7": {n: row(n, 960, 4, 7)}}, {"VK7": {n: row(n, 960, 3, 7)}})
        plan("no_change_no_item", {"Q7": {n: row(n, 1300, 2, 7)}}, {"Q7": {n: row(n, 1300, 2, 7)}})
        plan("sold_out_after_is_left_to_protection", {"Q7": {n: row(n, 1300, 6, 7)}}, {"Q7": {n: row(n, 1300, 7, 7)}})
        plan("today_never_moves", {"Q7": {"2027-03-08": row("2027-03-08", 1300, 2, 7)}}, {"Q7": {"2027-03-08": row("2027-03-08", 1300, 3, 7)}})
        plan("night_missing_before_skipped", {"Q7": {}}, {"Q7": {n: row(n, 1300, 3, 7)}})
        plan("batch_import_not_counted", {"Q7": {n: row(n, 1300, 2, 7)}}, {"Q7": {n: row(n, 1300, 5, 7)}}, batch={f"Q7|{n}": 3})
        plan("batch_import_partly_counted", {"Q7": {n: row(n, 1300, 2, 7)}}, {"Q7": {n: row(n, 1300, 5, 7)}}, batch={f"Q7|{n}": 2})
        plan("no_market_target_fallback_step", {"Q7": {n: row(n, 739, 2, 7)}}, {"Q7": {n: row(n, 739, 3, 7)}},
             market={"periodos": [], "referencias_topo": []})
        plan("discount_room_follows_band_cap",
             {"VK7": {n: row(n, 1000, 2, 7)}, "VK2": {n: row(n, 1100, 0, 2)}, "Balcony": {n: row(n, 880, 0, 6)}},
             {"VK7": {n: row(n, 1000, 4, 7)}, "VK2": {n: row(n, 1100, 1, 2)}, "Balcony": {n: row(n, 880, 2, 6)}})
        plan("discount_room_cancellation_ignored", {"VK7": {n: row(n, 1000, 2, 7)}, "Balcony": {n: row(n, 880, 3, 6)}},
             {"VK7": {n: row(n, 1000, 2, 7)}, "Balcony": {n: row(n, 880, 1, 6)}})
        plan("holiday_discount_room_without_band_cap", {"VK7": {"2027-03-26": row("2027-03-26", 1000, 2, 7)},
                                                        "Balcony": {"2027-03-26": row("2027-03-26", 880, 0, 6)}},
             {"VK7": {"2027-03-26": row("2027-03-26", 1000, 2, 7)}, "Balcony": {"2027-03-26": row("2027-03-26", 880, 3, 6)}})
    finally:
        ER.acompanhar = original
    return cases


# ---------------------------------------------------------------- the parity fixture of this area
def parity_fixture(c: dict) -> dict:
    """The owner's strategy (plano_dono.py) in the plugin's configuration format."""
    def opening(q, g):
        serie, pct, mult = PD.ABERTURA[g][q]
        return {"series": SERIES[serie], "percentile": float(pct), "multiplier": mult, "cap": "reference_snapshot"}

    def ceiling(q, g):
        serie, pct = PD.TETO[g].get(q) or PD.TETO[g]["*"]
        return {"series": SERIES[serie], "percentile": float(pct)}

    groups = {}
    for label, group in PD.GRUPO.items():
        groups.setdefault(group, []).append(NIGHT_LABEL[label])
    rules = []
    for group in ("fds", "feriado", "festas"):
        for q in PD.ABERTURA[group]:
            rules.append({"night_types": groups[group], "rooms": [q], "opening": opening(q, group), "ceiling": ceiling(q, group),
                          "apply_quality_floor": True, "apply_drop": True})
        rules.append({"night_types": groups[group], "rooms": ["Afrodite"], "derive": {"from_room": "Double",
                      "multiplier": PD.AFRODITE_SOBRE_DOUBLE}, "apply_quality_floor": True, "apply_drop": True})
    afrodite = c["por_curto"]["Afrodite"]
    escada = c["parametros"]["escada"]
    import ordem_recanto as OR
    strategy = {
        "id": STRATEGY_ID, "label": "Nov-Feb plan", "enabled": True, "mode": "stepped", "valid_from": PD.DE, "valid_to": PD.ATE,
        "activated_on": None, "authority_order_id": PD.ORDEM_ID, "lock_ceiling": True,
        "step": {"trigger_fraction": PD.ESCADA_APOS_FRACAO, "percent_per_unit": round(PD.PASSO_ESCADA * 100, 6)},
        "drop": {"enabled": True, "window_days": PD.DESCIDA_DIAS, "occupancy_below": PD.DESCIDA_OCUPACAO_MAX,
                 "percent": round(PD.DESCIDA * 100, 6), "skip_inside_window_at_activation": True},
        "weekend_floor_override": {"enabled": True, "weekdays": [4, 5]},
        "rules": rules,
        "relations": {"between": [{"room": "Q2", "low": "Q7", "high": "Double", "position": OR.POSICAO}],
                      "premium": [{"room": "Afrodite", "over": ["Q7", "Double", "Q2"],
                                   "rate_weekend_or_holiday": float(afrodite["premio_minimo"]),
                                   "rate_other": float(afrodite.get("premio_minimo_semana", afrodite["premio_minimo"]))}]},
        "last_minute_floor": {"enabled": True, "night_types": groups["semana"], "excluded_weekdays": [4, 5], "skip_holidays": True,
                              "window_days": PD.PISO_SEMANA_ATE_DIAS - 1, "series": SERIES["hidro"], "percentile": 50.0,
                              "rooms": dict(PD.SEMANA_ULTIMA)},
        "proportional": {"fallback_step_percent": round(escada["passo"] * 100, 6), "band_room": "Balcony", "band_reference_room": "VK2",
                         "band_factor": escada["fator_balcony"], "band_cap_rooms": ["VK7", "VK2"],
                         "band_cap_discount": c["parametros"].get("regra_g_desconto", 0.10),
                         "temporary_floor": {"amount": float(escada["piso_genius"]["valor"]), "until": escada["piso_genius"]["ate"]}},
    }
    return {
        "_doc": "Parity fixture of the sales ladder, sold-out protection, cadence and owner orders (M2), generated by "
                "tests/fixtures/tools/golden_ladder.py from plano_dono.py and the frozen inputs. Test data only: never shipped.",
        "ladder": {"enabled": True, "strategies": [strategy]},
        "soldout": {"protection": {"enabled": not c["parametros"].get("motor", {}).get("regra_j_ativa", False), "percentile": 90.0,
                                   "use_target_order_floor": True, "use_relation_bounds": True, "keep_live_floor_on_release": True,
                                   "retired_floor_markers": ["Regra J"], "retired_floor_exemptions": ["Regra J desligada"]},
                    "light_run_max_days": None},
        "cadence": {"min_hours_between_steps": 24},
        "orders": {"manual_freeze_days": 7},
        "no_effect": {"drop_rounding_only": False, "money_slack": 1.0},
        "ruler": {n: {"type": NIGHT_LABEL[v["tipo"]],
                      "series": {SERIES[s]: v[s] for s in SERIES if s in v},
                      "public_factor": {PROPERTY_ID[p]: f for p, f in v["fator_publico"].items()},
                      "reference_sent": v.get("pedido_0911", {})} for n, v in ruler().items()},
    }


# ---------------------------------------------------------------- writing
def golden(function: str, php_target: str, notes: str, cases: list[dict]) -> dict:
    return {"function": function, "php_target": php_target, "notes": notes, "cases": cases}


def write_golden(path: Path, data: dict) -> None:
    head = {k: v for k, v in data.items() if k != "cases"}
    lines = [json.dumps(c, ensure_ascii=False, allow_nan=False) for c in data["cases"]]
    text = json.dumps(head, ensure_ascii=False, indent=1)[:-2] + ',\n "cases": [\n  ' + ",\n  ".join(lines) + "\n ]\n}\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")


def write_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=1, allow_nan=False) + "\n", encoding="utf-8")


def git(*args: str) -> str:
    return subprocess.run(["git", "-C", str(REPO), *args], check=True, capture_output=True, text=True).stdout.strip()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def freeze() -> int:
    """Copies the market ruler of the owner's plan into frozen/ladder/, numbers only (no sources, no notes)."""
    source = json.loads((ENGINE_DATA / "regua_dono.json").read_text(encoding="utf-8"))
    keep = ("tipo", "lista", "hidro", "pares_lista", "pares_hidro", "fator_publico", "pedido_0911")
    nights = {n: {k: v[k] for k in keep if k in v} for n, v in source["noites"].items()}
    write_json(FROZEN_LADDER / "regua_dono.json", {"_doc": "Frozen market ruler of the owner's plan (numbers only), "
                                                           "from the engine data by golden_ladder.py freeze.", "noites": nights})
    print(f"frozen ruler written: {len(nights)} nights")
    return 0


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", nargs="?", default="dump", choices=("dump", "freeze"))
    parser.add_argument("--generated-at", default=dt.datetime.now(dt.timezone.utc).date().isoformat())
    args = parser.parse_args(argv)
    if args.command == "freeze":
        return freeze()
    install_guards()
    listing = []
    for data in ladder_formulas() + ladder_engine() + cadence() + protection() + owner_orders() + proportional():
        name = data["function"] + ".json"
        write_golden(GOLDEN / name, data)
        listing.append({"file": name, "function": data["function"], "cases": len(data["cases"])})
    write_json(PARITY, parity_fixture(cfg()))
    engine_dirty = git("status", "--porcelain", "--", ENGINE_PATH) != ""
    if engine_dirty:
        print(f"WARNING: {ENGINE_PATH} has uncommitted changes; these goldens do not match any commit", file=sys.stderr)
    frozen = [FROZEN / "quartos.json", FROZEN_LADDER / "regua_dono.json"]
    write_json(GOLDEN / "MANIFEST.json", {
        "generated_at": args.generated_at,
        "repo_commit": git("rev-parse", "HEAD"),
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": engine_dirty,
        "python": platform.python_version(),
        "frozen_inputs": {p.relative_to(FROZEN).as_posix(): sha256(p) for p in frozen},
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

#!/usr/bin/env python3
"""Golden cases of the confirmation, delivery and cancellation-wave area of the write path (M3).

Runs pure functions of the reference Python engine (read from the repository, never changed) with frozen invented
inputs and writes one JSON file per function into tests/fixtures/golden/m3/verify/, in the format of the level-1
goldens, plus its MANIFEST.json:

    {"function": ..., "php_target": ..., "notes": ..., "cases": [{"name": ..., "input": {...}, "expected": ...}]}

Functions: ler_recalculo.regra_inativa / tolerancia_percentual / dia_isolado, pace.envios_da_coleta,
beds24._conferir (comparison only; nothing is read or sent), vigia_seguranca.classificar (cancellation waves only).

Safety: no bytecode is written into the engine folder; urllib.request.urlopen raises if anything tries the network;
the two module-level data files the engine may write point at a temporary folder; the clock is always an input.
Inputs are invented (neutral room codes R1, R2, S1; booking ids are numbers); no guest data.

Usage: python3 -B tests/fixtures/tools/golden_verify.py
"""
from __future__ import annotations

import sys

sys.dont_write_bytecode = True

import copy  # noqa: E402
import datetime as dt  # noqa: E402
import json  # noqa: E402
import platform  # noqa: E402
import shutil  # noqa: E402
import subprocess  # noqa: E402
import tempfile  # noqa: E402
import urllib.request  # noqa: E402
from pathlib import Path  # noqa: E402

REPO = Path(__file__).resolve().parents[4]
PLUGIN = Path(__file__).resolve().parents[3]
HERE = PLUGIN / "tests" / "fixtures" / "golden" / "m3" / "verify"
ENGINE_PATH = ".claude/skills/gestao-receita-pousadas/scripts"
ENGINE = REPO / ENGINE_PATH
sys.path.insert(0, str(ENGINE))


def _no_network(*_a, **_k):
    raise RuntimeError("golden generator must not touch the network")


urllib.request.urlopen = _no_network

import beds24 as B  # noqa: E402
import cota_recalculo as CR  # noqa: E402
import ler_recalculo as LR  # noqa: E402
import pace  # noqa: E402
import vigia_seguranca as VS  # noqa: E402

TMP = Path(tempfile.mkdtemp(prefix="ritmo-golden-verify-"))
CR.ARQ_RECALCULOS = TMP / "recalculos.json"
pace.ARQ_PASSOS = TMP / "passos.json"
UTC = dt.timezone.utc


def iso(x):
    if x is None:
        return None
    return x.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


def write(name: str, function: str, php_target: str, notes: str, cases: list) -> None:
    out = {"function": function, "php_target": php_target, "notes": notes, "cases": cases}
    (HERE / name).write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")


def gen_conferencia():
    ri = []
    for name, it, x in [
        ("no_price_type_is_never_inactive", {"min_price": 900}, {"dso_flag": 0, "min_price": 500}),
        ("dso_flag_zero_is_inactive", {"price_type": "percent", "min_price": 900}, {"dso_flag": 0, "min_price": 900}),
        ("dso_flag_string_one_is_live", {"price_type": "percent", "min_price": 900}, {"dso_flag": "1", "min_price": 900}),
        ("floor_within_1_5_percent", {"price_type": "percent", "min_price": 1000}, {"dso_flag": 1, "min_price": 1014}),
        ("floor_beyond_1_5_percent", {"price_type": "percent", "min_price": 1000}, {"dso_flag": 1, "min_price": 1016}),
        ("small_floor_slack_is_at_least_one", {"price_type": "fixed", "min_price": 50}, {"dso_flag": 1, "min_price": 51}),
        ("small_floor_beyond_one", {"price_type": "fixed", "min_price": 50}, {"dso_flag": 1, "min_price": 51.5}),
        ("no_floor_in_item_only_flag", {"price_type": "percent"}, {"dso_flag": 1, "min_price": 3}),
        ("missing_floor_in_recalc_reads_zero", {"price_type": "percent", "min_price": 900}, {"dso_flag": 1})]:
        r = LR.regra_inativa(it, x)
        ri.append({"name": name, "input": {"item": it, "recalc_night": x},
                   "expected": {"inactive": bool(r),
                                "kind": ("" if not r else ("expired_or_out_of_reach" if r.startswith("dso_flag")
                                                            else "floor_differs"))}})
    write("ler_recalculo.regra_inativa.json", "ler_recalculo.regra_inativa",
          "Ritmo\\Domain\\Write\\Confirm\\RuleLiveness::check()",
          "A written date rule only counts as confirmed when the recalculated night has dso_flag 1 and its floor is the "
          "written one within max(1, 1.5 % of the floor). Only for items with price_type fixed or percent.", ri)

    tp = []
    for v, b in [(744, 1480), (1000, 1000), (2000, 900), (50, 0), (0, 0), (1569, 1480)]:
        tp.append({"name": f"value_{v}_base_{b}", "input": {"value": v, "base": b},
                   "expected": round(LR.tolerancia_percentual(v, b), 6)})
    write("ler_recalculo.tolerancia_percentual.json", "ler_recalculo.tolerancia_percentual",
          "Ritmo\\Domain\\Write\\Confirm\\PercentSlack::of()",
          "Slack of a floor/ceiling written as a whole percentage of the base: max(1.5 % of the value, 1 % of "
          "max(value, base) + 1, 1).", tp)

    di = []
    sold = {"mu_occupancy": "7/7"}
    free = {"mu_occupancy": "3/7"}
    single_sold = {"mu_occupancy": "-1", "num_bookings": "1"}
    single_free = {"mu_occupancy": "-1", "num_bookings": "0"}
    for name, cal, night, units in [
        ("free_between_two_sold", {"2026-11-09": sold, "2026-11-10": free, "2026-11-11": sold}, "2026-11-10", 7),
        ("sold_between_two_sold_is_not_isolated", {"2026-11-09": sold, "2026-11-10": sold, "2026-11-11": sold},
         "2026-11-10", 7),
        ("only_one_side_sold", {"2026-11-09": sold, "2026-11-10": free, "2026-11-11": free}, "2026-11-10", 7),
        ("missing_neighbour", {"2026-11-10": free, "2026-11-11": sold}, "2026-11-10", 7),
        ("single_unit_by_bookings", {"2026-11-09": single_sold, "2026-11-10": single_free,
                                     "2026-11-11": single_sold}, "2026-11-10", 1),
        ("collected_calendar_field", {"2026-11-09": {"multi_unit_occupancy": "2/2"}, "2026-11-10":
                                      {"multi_unit_occupancy": "1/2"}, "2026-11-11": {"multi_unit_occupancy": "2/2"}},
         "2026-11-10", 2)]:
        di.append({"name": name, "input": {"calendar": cal, "night": night, "units": units},
                   "expected": LR.dia_isolado(cal, night, units)})
    write("ler_recalculo.dia_isolado.json", "ler_recalculo.dia_isolado",
          "Ritmo\\Domain\\Write\\Confirm\\IsolatedNight::is()",
          "A free night between two sold-out nights: the pricing system shows minimum stay 1 by its own orphan-day "
          "rule; a minimum-stay mismatch there is not a write failure (do not rewrite).", di)


def gen_envios():
    cfg = {"por_id": {"L1": {"curto": "R1"}, "L2": {"curto": "R2"}, "L3": {"curto": "S1"}}}
    cases = []
    for name, sync in [
        ("each_room_by_its_own_push", [
            {"listing_id": "L1", "last_date_pushed": "2026-11-05T19:55:00Z", "push_enabled": True},
            {"listing_id": "L2", "last_date_pushed": "2026-11-05T18:15:00", "push_enabled": True}]),
        ("push_disabled_and_unreadable_left_out", [
            {"listing_id": "L1", "last_date_pushed": "2026-11-05T19:55:00Z", "push_enabled": False},
            {"listing_id": "L2", "last_date_pushed": "N/A"},
            {"listing_id": "L3", "last_date_pushed": None},
            {"listing_id": "L9", "last_date_pushed": "2026-11-05T19:55:00Z"}]),
        ("same_room_twice_keeps_the_oldest", [
            {"listing_id": "L1", "last_date_pushed": "2026-11-05T19:55:00Z"},
            {"listing_id": "L1", "last_date_pushed": "2026-11-05T08:00:00Z"}]),
        ("workflow_envelope", None)]:
        coleta = ({"result": {"contexto": {"sync": [{"listing_id": "L2", "last_date_pushed":
                                                     "2026-11-06T10:00:00+00:00"}]}}}
                  if sync is None else {"contexto": {"sync": sync}})
        fora: list = []
        r = pace.envios_da_coleta(cfg, copy.deepcopy(coleta), fora)
        cases.append({"name": name, "input": {"listing_room": {k: v["curto"] for k, v in cfg["por_id"].items()},
                                              "collect": coleta},
                      "expected": {"pushed_at": {k: iso(v) for k, v in sorted(r.items())},
                                   "left_out": len(fora)}})
    write("pace.envios_da_coleta.json", "pace.envios_da_coleta",
          "Ritmo\\Domain\\Write\\Channel\\PushTimes::fromListings()",
          "Last push per room from the listing directory: rooms with push disabled, no readable instant or no mapping "
          "are left out (and reported); the same room twice keeps the oldest push; an instant without zone is UTC.",
          cases)


# ---------------------------------------------------------------- PMS calendar re-read
def gen_beds24():
    cf = []
    for name, items, after in [
        ("all_fields_match",
         [{"quarto": "R1", "de": "2026-11-21", "ate": "2026-11-22", "min_stay": 2, "override": "noCheckInOrCheckOut"}],
         {"R1": {"2026-11-21": {"minStay": 2, "override": "noCheckInOrCheckOut"},
                 "2026-11-22": {"minStay": 2, "override": "noCheckInOrCheckOut"}}}),
        ("one_night_differs",
         [{"quarto": "R1", "de": "2026-11-21", "ate": "2026-11-22", "min_stay": 2}],
         {"R1": {"2026-11-21": {"minStay": 2}, "2026-11-22": {"minStay": 1}}}),
        ("availability_read_net_of_bookings",
         [{"quarto": "R2", "de": "2026-11-05", "ate": "2026-11-05", "num_avail": 7, "num_avail_esperado": 6}],
         {"R2": {"2026-11-05": {"numAvail": 6}}}),
        ("availability_without_expected_compares_written",
         [{"quarto": "R2", "de": "2026-11-05", "ate": "2026-11-05", "num_avail": 7}],
         {"R2": {"2026-11-05": {"numAvail": 6}}}),
        ("channel_limit_as_text",
         [{"quarto": "S1", "de": "2026-12-01", "ate": "2026-12-01", "canais_max": {"booking": "0"}}],
         {"S1": {"2026-12-01": {"canais_max": {"booking": "0"}}}}),
        ("night_missing_from_reread",
         [{"quarto": "S1", "de": "2026-12-01", "ate": "2026-12-02", "max_stay": 2}],
         {"S1": {"2026-12-01": {"maxStay": 2}}})]:
        errs, bad = B._conferir(copy.deepcopy(items), after)
        cf.append({"name": name, "input": {"items": items, "reread": after},
                   "expected": {"errors": len(errs), "items_with_error": bad}})
    write("beds24._conferir.json", "beds24._conferir", "Ritmo\\Domain\\Write\\Confirm\\PmsCalendarCheck::compare()",
          "Every written field of every night of the item against the re-read calendar; availability against the "
          "expected NET value (units minus bookings) when the item carries it.", cf)


# ---------------------------------------------------------------- cancellation waves
def gen_waves():
    par = {"origens_conhecidas": ["Booking.com"], "rajada_cancelamentos": 3, "rajada_horas": 3}

    def c(i, ch, t):
        return {"id": i, "roomId": 1, "status": "cancelled", "channel": ch, "referer": "Booking.com",
                "bookingTime": "2026-10-01T10:00:00Z", "modifiedTime": t, "cancelTime": t, "price": 500}
    cases = []
    for name, res in [
        ("three_in_three_hours_is_a_wave", [c(1, "booking", "2026-11-06T11:26:00Z"), c(2, "booking", "2026-11-06T12:10:00Z"),
                                            c(3, "booking", "2026-11-06T14:20:00Z")]),
        ("two_are_not_a_wave", [c(1, "booking", "2026-11-06T11:26:00Z"), c(2, "booking", "2026-11-06T12:10:00Z")]),
        ("three_spread_over_more_than_three_hours", [c(1, "booking", "2026-11-06T08:00:00Z"),
                                                     c(2, "booking", "2026-11-06T10:00:00Z"),
                                                     c(3, "booking", "2026-11-06T11:30:00Z")]),
        ("channels_count_apart", [c(1, "booking", "2026-11-06T11:26:00Z"), c(2, "airbnb", "2026-11-06T11:40:00Z"),
                                  c(3, "booking", "2026-11-06T12:00:00Z")]),
        ("two_waves_in_one_run", [c(i, "booking", t) for i, t in enumerate(
            ["2026-11-06T08:00:00Z", "2026-11-06T08:10:00Z", "2026-11-06T08:20:00Z",
             "2026-11-06T13:00:00Z", "2026-11-06T13:05:00Z", "2026-11-06T13:30:00Z", "2026-11-06T14:00:00Z"])]),
        ("cancellation_before_since_is_ignored", [c(1, "booking", "2026-11-06T05:00:00Z"),
                                                  c(2, "booking", "2026-11-06T11:00:00Z"),
                                                  c(3, "booking", "2026-11-06T11:30:00Z")])]:
        al = [x for x in VS.classificar(copy.deepcopy(res), "2026-11-06T06:00:00Z", par) if
              x["alerta"] == "rajada de cancelamentos"]
        cases.append({"name": name, "input": {"bookings": res, "since": "2026-11-06T06:00:00Z", "min_count": 3,
                                              "window_hours": 3},
                      "expected": [{"channel": x["canal"], "bookings": x["reservas"]} for x in al]})
    write("vigia_seguranca.classificar.waves.json", "vigia_seguranca.classificar (cancellation waves)",
          "Ritmo\\Domain\\Write\\Hold\\CancellationWave::detect()",
          "Per channel, every cancellation inside a window of N or more within H hours is marked; marked ones are "
          "grouped when consecutive ones are at most H apart. Only cancellations at or after `since` count.", cases)


def git(*args: str) -> str:
    return subprocess.run(["git", "-C", str(REPO), *args], check=True, capture_output=True, text=True).stdout.strip()


def manifest() -> None:
    files = {p.name: len(json.loads(p.read_text())["cases"]) for p in sorted(HERE.glob("*.json")) if p.name != "MANIFEST.json"}
    out = {
        "generated_at": dt.date.today().isoformat(),
        "repo_commit": git("rev-parse", "HEAD"),
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": git("status", "--porcelain", "--", ENGINE_PATH) != "",
        "python": platform.python_version(),
        "tolerance": {"amounts": 0.01, "percentages_and_integers": "exact"},
        "files": files,
    }
    (HERE / "MANIFEST.json").write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")


def main() -> int:
    HERE.mkdir(parents=True, exist_ok=True)
    try:
        gen_conferencia()
        gen_envios()
        gen_beds24()
        gen_waves()
    finally:
        shutil.rmtree(TMP, ignore_errors=True)
    manifest()
    for p in sorted(HERE.glob("*.json")):
        if p.name != "MANIFEST.json":
            print(f"{p.name}: {len(json.loads(p.read_text())['cases'])} cases")
    return 0


if __name__ == "__main__":
    sys.exit(main())

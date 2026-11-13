#!/usr/bin/env python3
"""Golden cases of the operations area (M3: scheduler, light run and alerts).

Runs pure functions of the reference Python engine (read from the repository, never changed) with frozen invented
inputs and writes one JSON file per function into tests/fixtures/golden/m3/ops/, in the format of the level-1
goldens, plus its MANIFEST.json:

    {"function": ..., "php_target": ..., "notes": ..., "cases": [{"name": ..., "input": {...}, "expected": ...}]}

Functions: vigia_seguranca.classificar, reservas_abaixo.avaliar, beds24.receita_por_noite, beds24.movimento_de,
beds24.alterada_desde, cota_recalculo.inicio_rodada and cota_recalculo.proxima_rotina_completa.

Safety: no bytecode is written into the engine folder; urllib.request.urlopen raises if anything tries the network;
the module-level data file the quota module may write points at a temporary folder; the clock is always an input.
Inputs are invented and carry no guest data. The reference keys its channel factors by its own property names and
maps its own room ids to room codes; the cases are written with neutral markers instead (property "prop-a" with id
"8001", room id "9101" with code "room-a", ...) and translated to the reference's values only for the call.

Usage: python3 -B tests/fixtures/tools/golden_ops.py
"""
from __future__ import annotations

import sys

sys.dont_write_bytecode = True

import copy  # noqa: E402
import datetime as dt  # noqa: E402
import json  # noqa: E402
import platform  # noqa: E402
import subprocess  # noqa: E402
import tempfile  # noqa: E402
import urllib.request  # noqa: E402
from pathlib import Path  # noqa: E402
from zoneinfo import ZoneInfo  # noqa: E402

REPO = Path(__file__).resolve().parents[4]
PLUGIN = Path(__file__).resolve().parents[3]
HERE = PLUGIN / "tests" / "fixtures" / "golden" / "m3" / "ops"
ENGINE_PATH = ".claude/skills/gestao-receita-pousadas/scripts"
ENGINE = REPO / ENGINE_PATH
sys.path.insert(0, str(ENGINE))


def _no_network(*_a, **_k):
    raise RuntimeError("golden generator must not touch the network")


urllib.request.urlopen = _no_network

import beds24 as B  # noqa: E402  (constants and pure functions only)
import cota_recalculo as CR  # noqa: E402
import reservas_abaixo as RA  # noqa: E402
import vigia_seguranca as VS  # noqa: E402

CR.ARQ_RECALCULOS = Path(tempfile.mkdtemp(prefix="ritmo-golden-ops-")) / "recalculos.json"

TZ_NAME = "America/Sao_Paulo"
TZ = ZoneInfo(TZ_NAME)
SINCE = "2026-11-10T13:40:00Z"

# Invented security parameters, in the shape of the reference's security section.
SECURITY = {"origens_conhecidas": ["Booking.com", "Airbnb", "API", "frontdesk-1"], "rajada_cancelamentos": 3,
            "rajada_horas": 3}
ROOM_MAP = {"9001": "room-a", "9002": "room-b"}

# Markers written in the cases <-> the reference's own ids and names, used only for the call.
_PROPERTY_IDS = sorted(B.PROPRIEDADES)
_ROOM_IDS = sorted(B.QUARTOS)
MARK_PROPERTY = {"8001": _PROPERTY_IDS[0], "8002": _PROPERTY_IDS[1]}
MARK_PROPERTY_NAME = {"prop-a": B.PROPRIEDADES[_PROPERTY_IDS[0]].split()[0],
                      "prop-b": B.PROPRIEDADES[_PROPERTY_IDS[1]].split()[0]}
MARK_ROOM = {"9101": _ROOM_IDS[0], "9102": _ROOM_IDS[-1]}
ROOM_CODE_MARK = {B.QUARTOS[_ROOM_IDS[0]][0]: "room-x", B.QUARTOS[_ROOM_IDS[-1]][0]: "room-y"}
FACTORS = {"prop-a": {"booking": 0.49, "airbnb": 0.41, "direct": 1.0},
           "prop-b": {"booking": 0.46, "airbnb": 0.31, "direct": 1.0}}


def to_reference(value):
    """Deep copy with every marker replaced by the reference's value (ids, names)."""
    table = {**MARK_PROPERTY, **MARK_PROPERTY_NAME, **MARK_ROOM}
    if isinstance(value, dict):
        return {table.get(k, k): to_reference(v) for k, v in value.items()}
    if isinstance(value, list):
        return [to_reference(v) for v in value]
    if isinstance(value, str):
        return table.get(value, value)
    return copy.deepcopy(value)


def bk(i, status="confirmed", channel="booking", referer="Booking.com", price=500.0, arrival="2026-11-20",
       departure="2026-11-22", booked=None, modified=None, cancelled=None, room="9001", prop=None, items=None,
       api_ref=None):
    b = {"id": i, "roomId": room, "arrival": arrival, "departure": departure, "status": status, "channel": channel,
         "referer": referer, "price": price}
    if prop:
        b["propertyId"] = prop
    for k, v in (("bookingTime", booked), ("modifiedTime", modified), ("cancelTime", cancelled)):
        if v is not None:
            b[k] = v
    if items is not None:
        b["invoiceItems"] = items
    if api_ref is not None:
        b["apiReference"] = api_ref
    return b


def write(name, function, php_target, notes, cases):
    out = {"function": function, "php_target": php_target, "notes": notes, "cases": cases}
    (HERE / f"{name}.json").write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    return f"{name}.json", len(cases)


def vigia_cases():
    t = "2026-11-10T"
    old = "2026-11-01T10:00:00Z"
    scen = {
        "unknown_origin": [bk(1, referer="new-user", booked=t + "14:00:00Z", modified=t + "14:00:00Z")],
        "known_origin_case_insensitive": [bk(2, referer="booking.COM", booked=t + "14:00:00Z")],
        "empty_origin_never_alerts": [bk(3, referer="", booked=t + "14:00:00Z")],
        "modified_before_since_ignored": [bk(4, referer="new-user", booked="2026-11-09T10:00:00Z",
                                             modified=t + "13:39:59Z")],
        "no_timestamps_ignored": [bk(5, referer="new-user", price=0)],
        "zero_value_created_in_window": [bk(6, price=0, booked=t + "15:00:00Z")],
        "zero_value_modified_old_booking": [bk(7, price=0, booked=old, modified=t + "15:00:00Z")],
        "zero_value_request_status_ignored": [bk(8, status="request", price=0, booked=t + "15:00:00Z")],
        "zero_value_new_status": [bk(9, status="new", price=None, booked=t + "15:00:00Z")],
        "burst_three_same_channel": [bk(10 + k, status="cancelled", booked=old, modified=t + f"1{4 + k}:00:00Z",
                                        cancelled=t + f"1{4 + k}:00:00Z") for k in range(3)],
        "two_cancellations_no_burst": [bk(20 + k, status="cancelled", booked=old, modified=t + f"1{4 + k}:00:00Z",
                                          cancelled=t + f"1{4 + k}:00:00Z") for k in range(2)],
        "burst_window_edge_exactly_3h": [bk(30 + k, status="cancelled", booked=old, modified=x, cancelled=x)
                                         for k, x in enumerate((t + "14:00:00Z", t + "15:30:00Z", t + "17:00:00Z"))],
        "burst_spread_over_3h_no_alert": [bk(40 + k, status="cancelled", booked=old, modified=x, cancelled=x)
                                          for k, x in enumerate((t + "14:00:00Z", t + "15:30:00Z", t + "17:00:01Z"))],
        "two_waves_two_alerts": [bk(50 + k, status="cancelled", booked=old, modified=x, cancelled=x)
                                 for k, x in enumerate((t + "14:00:00Z", t + "14:10:00Z", t + "14:20:00Z",
                                                        t + "20:00:00Z", t + "20:05:00Z", t + "20:06:00Z"))],
        "burst_split_by_channel": [bk(60 + k, status="cancelled", channel=c, booked=old,
                                      modified=t + "14:0%d:00Z" % k, cancelled=t + "14:0%d:00Z" % k)
                                   for k, c in enumerate(("booking", "airbnb", "booking", "airbnb"))],
        "cancel_time_before_since_not_in_burst": [bk(70 + k, status="cancelled", booked=old, modified=t + "14:00:00Z",
                                                     cancelled=x)
                                                  for k, x in enumerate((t + "13:00:00Z", t + "14:00:00Z",
                                                                         t + "14:05:00Z"))],
        "burst_long_wave_marks_all": [bk(80 + k, status="cancelled", booked=old,
                                         modified=t + "%02d:%02d:00Z" % (14 + k // 2, (k % 2) * 30),
                                         cancelled=t + "%02d:%02d:00Z" % (14 + k // 2, (k % 2) * 30))
                                      for k in range(6)],
    }
    cases = []
    for name, reservas in scen.items():
        out = VS.classificar(copy.deepcopy(reservas), SINCE, SECURITY, ROOM_MAP)
        cases.append({"name": name, "input": {"reservas": reservas, "desde": SINCE, "par": SECURITY,
                                              "quartos": ROOM_MAP},
                      "expected": [{k: v for k, v in a.items() if k != "detalhe"} for a in out]})
    return write("vigia_seguranca.classificar", "vigia_seguranca.classificar",
                 "Ritmo\\Domain\\Alerts\\SecurityWatch::inspect",
                 "Compare the finding kind, booking ids, channel and burst members; texts (detalhe) are the plugin's "
                 "own. Kinds: 'origem desconhecida' -> unknown_origin, 'reserva sem valor' -> zero_value, 'reserva "
                 "alterada sem valor' -> zero_value_modified, 'rajada de cancelamentos' -> cancellation_burst. The "
                 "reference reads the raw price field; the plugin reads BookingRecord::total (equal here: no invoice "
                 "items).", cases)


def receita_cases():
    raw = {
        "price_only": bk(1, price=840.0),
        "invoice_charges_sum": bk(2, price=999.0, items=[{"type": "charge", "lineTotal": 300.0},
                                                       {"type": "charge", "lineTotal": 320.5},
                                                       {"type": "payment", "lineTotal": -620.5}]),
        "charge_of_other_invoicee_excluded": bk(3, price=0, items=[{"type": "charge", "lineTotal": 400.0},
                                                                  {"type": "charge", "lineTotal": 90.0,
                                                                   "invoiceeId": 77}]),
        "amount_times_qty_without_line_total": bk(4, items=[{"type": "Charge", "amount": 250.0, "qty": 2}]),
        "qty_zero_counts_as_one": bk(5, items=[{"type": "charge", "amount": 250.0, "qty": 0}]),
        "same_day_departure_is_one_night": bk(6, arrival="2026-11-20", departure="2026-11-20", price=300.0),
        "no_charges_falls_back_to_price": bk(7, price=410.0, items=[{"type": "payment", "lineTotal": -410.0}]),
        "price_missing_is_zero": bk(8, price=None),
    }
    cases = []
    for name, b in raw.items():
        chegada, noites, total = B.receita_por_noite(copy.deepcopy(b))
        cases.append({"name": name, "input": b, "expected": {"chegada": chegada.isoformat(), "noites": noites,
                                                             "total": round(total, 6)}})
    return write("beds24.receita_por_noite", "beds24.receita_por_noite",
                 "Ritmo\\Connectors\\Beds24\\BookingMapper::map (total) + BookingRecord::nights()",
                 "The mapper implements this since M1; these cases pin it for the alerts. 'price_missing_is_zero': "
                 "the reference gives 0.0, the mapper gives null; both mean 'no value' to the alerts.", cases)


def avaliar_cases():
    def nights(room, d0, n, p):
        return [[room, (dt.date.fromisoformat(d0) + dt.timedelta(days=k)).isoformat(), p] for k in range(n)]

    ra, rb = "9101", "9102"
    scen = {
        "booking_below_70": ([bk(101, prop="8001", room=ra, price=300.0, api_ref="ch-1")],
                             nights(ra, "2026-11-20", 2, 1000.0)),
        "booking_at_threshold_not_alerted": ([bk(102, prop="8001", room=ra, price=686.0)],
                                             nights(ra, "2026-11-20", 2, 1000.0)),
        "booking_just_below_threshold": ([bk(103, prop="8001", room=ra, price=685.99)],
                                         nights(ra, "2026-11-20", 2, 1000.0)),
        "airbnb_factor": ([bk(104, prop="8002", room=rb, channel="airbnb", referer="Airbnb", price=200.0)],
                          nights(rb, "2026-11-20", 2, 1000.0)),
        "unknown_channel_is_direct": ([bk(105, prop="8001", room=ra, channel="expedia", referer="API", price=1300.0)],
                                      nights(ra, "2026-11-20", 2, 1000.0)),
        "empty_channel_is_direct": ([bk(106, prop="8001", room=ra, channel="", referer="frontdesk-1", price=900.0)],
                                    nights(ra, "2026-11-20", 2, 1000.0)),
        "missing_price_night_skipped": ([bk(107, prop="8001", room=ra, price=100.0)],
                                        nights(ra, "2026-11-20", 1, 1000.0)),
        "zero_price_night_skipped": ([bk(108, prop="8001", room=ra, price=100.0)],
                                     nights(ra, "2026-11-20", 1, 1000.0) + [[ra, "2026-11-21", 0]]),
        "zero_total_skipped": ([bk(109, prop="8001", room=ra, price=0)], nights(ra, "2026-11-20", 2, 1000.0)),
        "cancelled_skipped": ([bk(110, status="cancelled", prop="8001", room=ra, price=10.0)],
                              nights(ra, "2026-11-20", 2, 1000.0)),
        "invoice_total_used": ([bk(111, prop="8001", room=ra, price=5000.0,
                                   items=[{"type": "charge", "lineTotal": 350.0}])],
                               nights(ra, "2026-11-20", 2, 1000.0)),
        "sorted_by_ratio": ([bk(112, prop="8001", room=ra, price=400.0), bk(113, prop="8001", room=ra, price=150.0),
                             bk(114, prop="8002", room=rb, price=200.0)],
                            nights(ra, "2026-11-20", 2, 1000.0) + nights(rb, "2026-11-20", 2, 1000.0)),
        "new_status_counts": ([bk(115, status="new", prop="8001", room=ra, price=200.0)],
                              nights(ra, "2026-11-20", 2, 1000.0)),
    }
    cases = []
    for name, (reservas, cal_rows) in scen.items():
        real_rows = to_reference(cal_rows)
        cal = {(r, d): p for r, d, p in real_rows}
        out = RA.avaliar(to_reference(reservas), cal, to_reference(FACTORS), 0.70)
        expected = []
        for x in out:
            row = {k: v for k, v in x.items() if k not in ("_nome", "tarifa")}
            row["quarto"] = ROOM_CODE_MARK.get(row["quarto"], row["quarto"])
            expected.append(row)
        cases.append({"name": name, "input": {"reservas": reservas, "precos": cal_rows, "fatores": FACTORS,
                                              "alerta": 0.70},
                      "expected": expected})
    return write("reservas_abaixo.avaliar", "reservas_abaixo.avaliar",
                 "Ritmo\\Domain\\Alerts\\BelowExpected::evaluate",
                 "precos = [roomId, night, PMS price]. Neutral markers: property ids 8001/8002 are prop-a/prop-b (the "
                 "factor table), room ids 9101/9102 are room-x/room-y. The guest name the reference reads is never in "
                 "the inputs and the rate description (free text) is not ported: both are left out of the expected "
                 "rows. Ratio 3 decimals, amounts within 0.01. 'booking_id' (the channel's reference) has no field in "
                 "BookingRecord yet (decision D9 of the operations spec).", cases)


def movimento_cases():
    t = "2026-11-10T"
    lista = [
        bk(201, booked=t + "14:00:00Z", modified=t + "14:00:00Z"),
        bk(202, booked="2026-11-01T10:00:00Z", modified=t + "15:00:00Z"),
        bk(203, status="cancelled", booked="2026-11-01T10:00:00Z", modified=t + "15:10:00Z",
           cancelled=t + "15:10:00Z"),
        bk(204, status="cancelled", booked=t + "13:50:00Z", modified=t + "14:30:00Z", cancelled="2026-11-09T10:00:00Z"),
        bk(205, status="black", channel="", referer="", price=0, booked=t + "16:00:00Z"),
        bk(206, booked=t + "13:40:00Z", modified=t + "13:40:00Z"),
        bk(207, status="cancelled", booked="2026-11-01T10:00:00Z", modified=t + "13:50:00Z", cancelled=None),
    ]
    cases = []
    for b in lista:
        out = B.movimento_de([copy.deepcopy(b)], SINCE)[0]
        cases.append({"name": f"booking_{b['id']}", "input": {"reserva": b, "desde": SINCE},
                      "expected": {k: out[k] for k in ("tipo", "id", "status", "chegada", "noites", "receita")}})
    return write("beds24.movimento_de", "beds24.movimento_de",
                 "Ritmo\\Domain\\Operations\\MovementClassifier::classify",
                 "The reference compares ISO strings; the plugin compares instants (identical for these second-"
                 "precision UTC inputs). Kinds: nova -> new, alterada -> modified, cancelada -> cancelled. Status "
                 "'black' is BookingStatus::Blocked in the plugin.", cases)


def alterada_cases():
    raw = [("modified_after", bk(1, modified="2026-11-10T14:00:00Z")),
           ("modified_exactly_at_since", bk(2, modified=SINCE)),
           ("modified_before", bk(3, modified="2026-11-10T13:39:59Z")),
           ("only_booking_time_after", bk(4, booked="2026-11-10T14:00:00Z")),
           ("no_timestamp_counts", bk(5))]
    cases = [{"name": n, "input": {"reserva": b, "desde": SINCE}, "expected": B.alterada_desde(copy.deepcopy(b), SINCE)}
             for n, b in raw]
    return write("beds24.alterada_desde", "beds24.alterada_desde",
                 "Ritmo\\Domain\\Operations\\MovementClassifier::changedSince",
                 "A booking without timestamps counts as changed (the security watch skips it: see "
                 "vigia_seguranca.classificar 'no_timestamps_ignored').", cases)


def slot_cases():
    instants = ["2026-11-12T10:52:59Z", "2026-11-12T10:53:00Z", "2026-11-12T13:53:00Z", "2026-11-12T23:30:00Z",
                "2026-11-13T02:59:00Z", "2026-11-13T03:00:00Z", "2026-11-13T22:53:00Z", "2026-11-14T22:52:59Z",
                "2026-12-31T23:59:59Z", "2027-01-01T03:00:00Z"]
    last, nxt = [], []
    for s in instants:
        now = dt.datetime.fromisoformat(s.replace("Z", "+00:00"))
        last.append({"name": s, "input": {"agora": s, "fuso": TZ_NAME},
                     "expected": CR.inicio_rodada(now, TZ).strftime("%Y-%m-%dT%H:%M:%SZ")})
        nxt.append({"name": s, "input": {"agora": s, "fuso": TZ_NAME},
                    "expected": CR.proxima_rotina_completa(now, TZ).strftime("%Y-%m-%dT%H:%M:%SZ")})
    notes = ("The schedule is the one of tests/fixtures/presets/parity-ops.json (the reference's routines: full run "
             "and light runs at local times). inicio_rodada = latest slot at or before now (inclusive); "
             "proxima_rotina_completa = first full slot strictly after now.")
    a = write("cota_recalculo.inicio_rodada", "cota_recalculo.inicio_rodada",
              "Ritmo\\Domain\\Operations\\SlotPlanner::latestAtOrBefore", notes, last)
    b = write("cota_recalculo.proxima_rotina_completa", "cota_recalculo.proxima_rotina_completa",
              "Ritmo\\Domain\\Operations\\SlotPlanner::nextFullAfter", notes, nxt)
    return [a, b]


def git(*args):
    return subprocess.run(["git", "-C", str(REPO), *args], capture_output=True, text=True, check=True).stdout.strip()


def main():
    HERE.mkdir(parents=True, exist_ok=True)
    files = [vigia_cases(), receita_cases(), avaliar_cases(), movimento_cases(), alterada_cases(), *slot_cases()]
    manifest = {
        "generated_at": dt.date.today().isoformat(),
        "repo_commit": git("rev-parse", "HEAD"),
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": git("status", "--porcelain", "--", ENGINE_PATH) != "",
        "python": platform.python_version(),
        "tolerance": {"amounts": 0.01, "percentages_and_integers": "exact"},
        "files": {name: count for name, count in sorted(files)},
    }
    (HERE / "MANIFEST.json").write_text(json.dumps(manifest, indent=1) + "\n", encoding="utf-8")
    print(json.dumps(manifest, indent=1))


if __name__ == "__main__":
    main()

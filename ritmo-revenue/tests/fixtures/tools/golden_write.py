#!/usr/bin/env python3
"""Golden cases of the WRITE PATH of Ritmo Revenue (milestone M3, write area), from the reference engine's pure
functions with frozen invented inputs. Writes tests/fixtures/golden/m3/write/*.json in the level-1 format:

    {"function": "<module.function>", "php_target": "...", "notes": "...",
     "cases": [{"name": "...", "input": {...}, "expected": ...}]}

Functions: cota_recalculo.situacao / abre_em / registrar (dedupe rules); beds24._conferir / corpo_edicao
(validation only: nothing is sent; the PMS cases carry the room id and units of the reference's room codes, which
exist only in these fixtures).

Safety: no bytecode is written into the engine folder; urllib.request.urlopen raises if anything tries the network;
the one function that writes a file (registrar) gets a path in a temporary folder; the clock is always an explicit
input. Inputs are invented; no guest data.

Usage: python3 -B tests/fixtures/tools/golden_write.py
"""
from __future__ import annotations

import sys

sys.dont_write_bytecode = True

import copy  # noqa: E402
import datetime as dt  # noqa: E402
import json  # noqa: E402
import shutil  # noqa: E402
import tempfile  # noqa: E402
import urllib.request  # noqa: E402
from pathlib import Path  # noqa: E402

OUT = Path(__file__).resolve().parents[1] / "golden" / "m3" / "write"
ENGINE = Path("/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/scripts")
sys.path.insert(0, str(ENGINE))


def _no_network(*_a, **_k):
    raise RuntimeError("golden generator must not touch the network")


urllib.request.urlopen = _no_network

import beds24 as B  # noqa: E402
import cota_recalculo as CR  # noqa: E402

TMP = Path(tempfile.mkdtemp(prefix="ritmo-golden-write-"))
CR.ARQ_RECALCULOS = TMP / "recalculos.json"
TZ = CR.fuso({"fuso": "America/Sao_Paulo"})
UTC = dt.timezone.utc


def T(s: str) -> dt.datetime:
    return dt.datetime.fromisoformat(s.replace("Z", "+00:00")).astimezone(UTC)


def iso(x):
    if x is None:
        return None
    return x.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


def write(name: str, function: str, php_target: str, notes: str, cases: list) -> None:
    out = {"function": function, "php_target": php_target, "notes": notes, "cases": cases}
    (OUT / name).write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")


def room_of(code: str) -> dict:
    """Beds24 room id and units of a reference room code (fixtures only)."""
    return {"room_id": str(B.ID_DO_CURTO[code]), "units": B.UNIDADES[code]}

def motive_kinds(motivos: list[str]) -> list[str]:
    kinds = []
    for m in motivos:
        if m.startswith("cota do quarto esgotada"):
            kinds.append("room_quota_spent")
        elif m.startswith("a conta já fez"):
            kinds.append("account_minute_limit")
        elif m.startswith("a conta levou 429"):
            kinds.append("account_429_wait")
        elif m.startswith("429 nesta rodada"):
            kinds.append("room_429_this_run")
        elif "Beds24 instável" in m:
            kinds.append("room_upstream_unavailable_this_run")
        elif m.startswith("falha nesta rodada"):
            kinds.append("room_failure_this_run")
        else:
            kinds.append("unknown:" + m[:40])
    return kinds


UNAVAILABLE = "Beds24 appears to be temporarily unavailable or under high load"


def att(room, at, result, error=None):
    t = {"quarto": room, "em": at, "resultado": result}
    if error:
        t["erro"] = error
    return t


SITUACAO = [
    ("empty_ledger", [], "R1", "2026-11-07T12:00:00Z", None, 0),
    ("three_in_24h_block_until_oldest_plus_24h",
     [att("R1", "2026-11-07T10:55:00Z", "ok"), att("R1", "2026-11-07T13:00:00Z", "ok"),
      att("R1", "2026-11-07T20:00:00Z", "ok")], "R1", "2026-11-07T22:00:00Z", None, 0),
    ("one_second_before_the_slot_reopens",
     [att("R1", "2026-11-07T10:55:00Z", "ok"), att("R1", "2026-11-07T13:00:00Z", "ok"),
      att("R1", "2026-11-07T20:00:00Z", "ok")], "R1", "2026-11-08T10:54:59Z", None, 0),
    ("exactly_24h_after_the_oldest_is_free",
     [att("R1", "2026-11-07T10:55:00Z", "ok"), att("R1", "2026-11-07T13:00:00Z", "ok"),
      att("R1", "2026-11-07T20:00:00Z", "ok")], "R1", "2026-11-08T10:55:00Z", None, 0),
    ("other_room_unaffected",
     [att("R1", "2026-11-07T10:55:00Z", "ok"), att("R1", "2026-11-07T13:00:00Z", "ok"),
      att("R1", "2026-11-07T20:00:00Z", "ok")], "R2", "2026-11-07T22:00:00Z", None, 0),
    ("four_in_window_reopens_after_the_second",
     [att("R1", "2026-11-07T10:00:00Z", "ok"), att("R1", "2026-11-07T11:00:00Z", "ok"),
      att("R1", "2026-11-07T12:00:00Z", "ok"), att("R1", "2026-11-07T15:00:00Z", "429")],
     "R1", "2026-11-07T23:00:00Z", None, 0),
    ("failure_and_429_count",
     [att("R1", "2026-11-07T10:55:00Z", "ok"), att("R1", "2026-11-07T11:00:00Z", "falha", UNAVAILABLE),
      att("R1", "2026-11-07T14:00:00Z", "429")], "R1", "2026-11-07T20:00:00Z", None, 0),
    ("attempt_after_the_clock_does_not_count",
     [att("R1", "2026-11-07T12:00:00Z", "ok")], "R1", "2026-11-07T11:00:00Z", None, 0),
    ("attempt_3_min_ahead_counts_with_5_min_slack",
     [att("R1", "2026-11-07T12:03:00Z", "ok"), att("R1", "2026-11-07T09:00:00Z", "ok"),
      att("R1", "2026-11-07T10:00:00Z", "ok")], "R1", "2026-11-07T12:00:00Z", None, 300),
    ("upstream_unavailable_this_run_blocks_until_next_run",
     [att("R1", "2026-11-07T10:56:00Z", "falha", UNAVAILABLE)], "R1", "2026-11-07T11:30:00Z", None, 0),
    ("upstream_failure_before_the_run_does_not_block",
     [att("R1", "2026-11-07T10:56:00Z", "falha", UNAVAILABLE)], "R1", "2026-11-07T14:00:00Z", None, 0),
    ("429_this_run_blocks",
     [att("R1", "2026-11-07T14:00:00Z", "429")], "R1", "2026-11-07T14:30:00Z", None, 0),
    ("unknown_failure_this_run_blocks",
     [att("R1", "2026-11-07T14:00:00Z", "falha", "timeout")], "R1", "2026-11-07T14:30:00Z", None, 0),
    ("ok_this_run_does_not_block",
     [att("R1", "2026-11-07T14:00:00Z", "ok")], "R1", "2026-11-07T14:30:00Z", None, 0),
    ("explicit_run_start",
     [att("R1", "2026-11-07T14:00:00Z", "falha", "x")], "R1", "2026-11-07T14:30:00Z", "2026-11-07T14:10:00Z", 0),
    ("ten_per_minute_on_the_account",
     [att("R2" if k % 2 else "S1", f"2026-11-07T12:00:{k * 5:02d}Z", "ok") for k in range(10)],
     "R1", "2026-11-07T12:00:50Z", None, 0),
    ("429_of_another_room_holds_every_room_one_minute",
     [att("R2", "2026-11-07T12:00:10Z", "429")], "R1", "2026-11-07T12:00:40Z", None, 0),
    ("429_of_another_room_released_after_one_minute",
     [att("R2", "2026-11-07T12:00:10Z", "429")], "R1", "2026-11-07T12:01:11Z", None, 0),
    ("own_429_before_the_run_holds_one_minute",
     [att("R1", "2026-11-07T13:52:30Z", "429")], "R1", "2026-11-07T13:53:10Z", None, 0),
    ("400_of_another_room_does_not_hold_the_account",
     [att("R2", "2026-11-07T12:00:10Z", "falha", UNAVAILABLE)], "R1", "2026-11-07T12:00:40Z", None, 0),
    ("third_attempt_takes_tomorrows_morning_slot_warning",
     [att("R1", "2026-11-07T11:00:00Z", "ok"), att("R1", "2026-11-07T17:00:00Z", "ok")],
     "R1", "2026-11-07T22:30:00Z", None, 0),
    ("no_warning_when_the_slot_reopens_before_the_morning_run",
     [att("R1", "2026-11-07T09:00:00Z", "ok"), att("R1", "2026-11-07T20:00:00Z", "ok")],
     "R1", "2026-11-07T23:00:00Z", None, 0),
    ("no_warning_on_the_second_attempt",
     [att("R1", "2026-11-07T11:00:00Z", "ok")], "R1", "2026-11-07T22:30:00Z", None, 0),
    ("night_attempt_warns_about_todays_morning_run",
     [att("R1", "2026-11-07T11:00:00Z", "ok"), att("R1", "2026-11-07T15:00:00Z", "ok")],
     "R1", "2026-11-08T03:30:00Z", None, 0),
]


def gen_situacao():
    cases = []
    for name, tent, room, now, run_start, slack in SITUACAO:
        s = CR.situacao(copy.deepcopy(tent), room, T(now), T(run_start) if run_start else None, tz=TZ,
                        folga=dt.timedelta(seconds=slack))
        cases.append({"name": name,
                      "input": {"attempts": tent, "room": room, "now": now, "run_start": run_start,
                                "future_slack_seconds": slack},
                      "expected": {"allowed": s["pode"], "blocked_by": motive_kinds(s["motivos"]),
                                   "used": s["usados"], "left": s["restam"], "by_result": s["por_resultado"],
                                   "room_slot_opens_at": iso(s["vaga_quarto"]),
                                   "account_slot_opens_at": iso(s["vaga_conta"]),
                                   "account_429_wait_until": iso(s["vaga_429"]),
                                   "run_start": iso(s["rodada_desde"]), "account_last_minute": s["conta_minuto"],
                                   "morning_slot_warning": bool(s["aviso"])}})
    write("cota_recalculo.situacao.json", "cota_recalculo.situacao",
          "Ritmo\\Application\\Write\\Quota\\RecalcQuota::verdict()",
          "Limits of the reference: 3 per room in a rolling 24 h (strictly greater than now-24h), 10 per account per "
          "rolling minute, 1 minute account wait after any 429, failures and 429 of the room since the run start block "
          "the room until the next run. run_start null = the reference's last scheduled routine (07:53, 10:53, 13:53, "
          "16:53, 19:53 America/Sao_Paulo): parity preset only; the plugin passes the run's own start instant. "
          "blocked_by maps the reference's messages to codes. Timezone America/Sao_Paulo.", cases)


def gen_quota_helpers():
    ab = []
    for name, inst, lim, win in [
        ("below_limit", ["2026-11-07T10:00:00Z", "2026-11-07T11:00:00Z"], 3, 86400),
        ("at_limit", ["2026-11-07T10:00:00Z", "2026-11-07T11:00:00Z", "2026-11-07T12:00:00Z"], 3, 86400),
        ("over_limit_unsorted", ["2026-11-07T12:00:00Z", "2026-11-07T10:00:00Z", "2026-11-07T11:00:00Z",
                                 "2026-11-07T15:00:00Z"], 3, 86400),
        ("account_minute", [f"2026-11-07T12:00:{k:02d}Z" for k in range(0, 50, 5)], 10, 60)]:
        r = CR.abre_em([T(x) for x in inst], lim, dt.timedelta(seconds=win))
        ab.append({"name": name, "input": {"instants": inst, "limit": lim, "window_seconds": win},
                   "expected": iso(r)})
    write("cota_recalculo.abre_em.json", "cota_recalculo.abre_em",
          "Ritmo\\Application\\Write\\Quota\\RollingWindow::opensAt()",
          "When the count in the window falls below the limit: window after the (n-limit)-th oldest instant; null "
          "when already below.", ab)


ID_NOW = int(T("2026-11-07T11:59:30Z").timestamp() * 1000) + 123


def gen_registrar():
    cfg = {"quartos": [{"curto": "R1"}, {"curto": "R2"}], "por_curto": {"R1": {}, "R2": {}}}
    scripts = {
        "same_id_twice_counts_once": [
            {"room": "R1", "result": "ok", "id_ms": ID_NOW},
            {"room": "R1", "result": "ok", "id_ms": ID_NOW}],
        "different_ids_same_second_are_two_attempts": [
            {"room": "R1", "result": "ok", "id_ms": ID_NOW},
            {"room": "R1", "result": "falha", "id_ms": ID_NOW + 654, "error": "x"}],
        "same_room_and_second_without_id_counts_once": [
            {"room": "R1", "result": "ok", "at": "2026-11-07T12:00:00Z"},
            {"room": "R1", "result": "ok", "at": "2026-11-07T12:00:00.700Z"}],
        "same_attempt_other_result_is_a_conflict": [
            {"room": "R1", "result": "ok", "at": "2026-11-07T12:00:00Z"},
            {"room": "R1", "result": "429", "at": "2026-11-07T12:00:00Z"}],
        "same_id_other_room_is_a_conflict": [
            {"room": "R1", "result": "ok", "id_ms": ID_NOW},
            {"room": "R2", "result": "ok", "id_ms": ID_NOW}],
        "more_than_5_min_ahead_is_refused": [
            {"room": "R1", "result": "ok", "at": "2026-11-07T12:06:00Z"}],
        "4_min_ahead_is_accepted": [
            {"room": "R1", "result": "ok", "at": "2026-11-07T12:04:00Z"}],
        "id_in_seconds_is_refused": [
            {"room": "R1", "result": "ok", "id_ms": ID_NOW // 1000}],
        "time_without_zone_is_refused": [
            {"room": "R1", "result": "ok", "at": "2026-11-07T12:00:00"}],
        "unknown_result_is_refused": [
            {"room": "R1", "result": "maybe", "at": "2026-11-07T11:00:00Z"}],
        "error_text_is_one_line_and_cut_to_300": [
            {"room": "R1", "result": "falha", "at": "2026-11-07T11:00:00Z", "error": "a\n b  " + "x" * 400}],
    }
    now = T("2026-11-07T12:00:00Z")
    cases = []
    for name, ops in scripts.items():
        path = TMP / f"reg_{name}.json"
        outcomes = []
        for op in ops:
            try:
                t, new = CR.registrar(op["room"], op["result"], em=op.get("at"), erro=op.get("error"),
                                      id_ms=op.get("id_ms"), caminho=path, cfg=cfg, agora=now)
                outcomes.append({"recorded": new, "at": t["em"], "error_kept": t.get("erro")})
            except CR.ErroCota:
                outcomes.append({"refused": True})
        stored = json.loads(path.read_text()) if path.exists() else {"tentativas": []}
        cases.append({"name": name, "input": {"now": iso(now), "operations": ops},
                      "expected": {"outcomes": outcomes,
                                   "stored": [{k: v for k, v in x.items() if k != "registrado_em"}
                                              for x in stored["tentativas"]]}})
    write("cota_recalculo.registrar.json", "cota_recalculo.registrar",
          "Ritmo\\Application\\Write\\Quota\\AttemptLog::accept() (dedupe rules)",
          "Every attempt counts (ok, falha, 429). Dedupe: with ids on both sides the response id decides; otherwise "
          "room + whole second. Same attempt with another result, or one id on two rooms: refused, nothing stored. "
          "An instant more than 5 min after now is refused; error text squeezed to one line, cut to 300 characters.",
          cases)


def gen_beds24():
    cf = []
    for name, items, after in [
        ("all_fields_match",
         [{"quarto": "Q7", "de": "2026-11-21", "ate": "2026-11-22", "min_stay": 2, "override": "noCheckInOrCheckOut"}],
         {"Q7": {"2026-11-21": {"minStay": 2, "override": "noCheckInOrCheckOut"},
                 "2026-11-22": {"minStay": 2, "override": "noCheckInOrCheckOut"}}}),
        ("one_night_differs",
         [{"quarto": "Q7", "de": "2026-11-21", "ate": "2026-11-22", "min_stay": 2}],
         {"Q7": {"2026-11-21": {"minStay": 2}, "2026-11-22": {"minStay": 1}}}),
        ("availability_read_net_of_bookings",
         [{"quarto": "VK7", "de": "2026-11-05", "ate": "2026-11-05", "num_avail": 7, "num_avail_esperado": 6}],
         {"VK7": {"2026-11-05": {"numAvail": 6}}}),
        ("availability_without_expected_compares_written",
         [{"quarto": "VK7", "de": "2026-11-05", "ate": "2026-11-05", "num_avail": 7}],
         {"VK7": {"2026-11-05": {"numAvail": 6}}}),
        ("channel_limit_as_text",
         [{"quarto": "Q2", "de": "2026-12-01", "ate": "2026-12-01", "canais_max": {"booking": "0"}}],
         {"Q2": {"2026-12-01": {"canais_max": {"booking": "0"}}}}),
        ("night_missing_from_reread",
         [{"quarto": "Q2", "de": "2026-12-01", "ate": "2026-12-02", "max_stay": 2}],
         {"Q2": {"2026-12-01": {"maxStay": 2}}})]:
        errs, bad = B._conferir(copy.deepcopy(items), after)
        rooms = {code: room_of(code) for code in after} | {i["quarto"]: room_of(i["quarto"]) for i in items}
        cf.append({"name": name, "input": {"items": items, "reread": after, "rooms": rooms},
                   "expected": {"errors": len(errs), "items_with_error": bad}})
    write("beds24._conferir.json", "beds24._conferir", "Ritmo\\Application\\Write\\Execute\\StoredCheck::compare()",
          "Every written field of every night of the item against the re-read calendar; availability against the "
          "expected NET value (units minus bookings) when the item carries it.", cf)

    ce = []
    for name, kw in [
        ("min_stay_and_override", {"curto": "Q7", "de": "2026-11-21", "ate": "2026-11-21", "min_stay": 2,
                                   "override": "noCheckInOrCheckOut"}),
        ("min_above_max_refused", {"curto": "Q7", "de": "2026-12-23", "ate": "2026-12-24", "min_stay": 3,
                                   "max_stay": 2}),
        ("stay_out_of_range_refused", {"curto": "Q7", "de": "2026-12-23", "ate": "2026-12-24", "min_stay": 31}),
        ("unknown_restriction_refused", {"curto": "Q7", "de": "2026-12-23", "ate": "2026-12-24", "override": "closed"}),
        ("availability_above_units_refused", {"curto": "Q2", "de": "2026-12-23", "ate": "2026-12-24", "num_avail": 3}),
        ("channel_limit_written_as_text", {"curto": "Q2", "de": "2026-12-23", "ate": "2026-12-24",
                                           "canais_max": {"booking": 0, "airbnb": None}}),
        ("unknown_channel_refused", {"curto": "Q2", "de": "2026-12-23", "ate": "2026-12-24",
                                     "canais_max": {"vrbo": 0}}),
        ("nothing_to_change_refused", {"curto": "Q2", "de": "2026-12-23", "ate": "2026-12-24"}),
        ("end_before_start_refused", {"curto": "Q2", "de": "2026-12-24", "ate": "2026-12-23", "min_stay": 2})]:
        try:
            body = B.corpo_edicao(**kw)
            exp = {"calendar": body[0]["calendar"]}
        except B.ErroBeds24:
            exp = {"refused": True}
        ce.append({"name": name, "input": dict(kw, **room_of(kw["curto"])), "expected": exp})
    write("beds24.corpo_edicao.json", "beds24.corpo_edicao", "Ritmo\\Connectors\\Beds24\\CalendarWriteBody::build()",
          "Only availability (0..units), minimum and maximum stay (1..30, min <= max), arrival/departure restriction "
          "(none, blackout, noCheckIn, noCheckOut, noCheckInOrCheckOut) and per-channel limits (booking, expedia, "
          "airbnb; sent as text, null lifts the limit). Never a price. Room codes and unit counts are the reference's "
          "(fixtures only).", ce)


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    try:
        gen_situacao()
        gen_quota_helpers()
        gen_registrar()
        gen_beds24()
    finally:
        shutil.rmtree(TMP, ignore_errors=True)
    for p in sorted(OUT.glob("*.json")):
        n = len(json.loads(p.read_text())["cases"])
        print(f"{p.name}: {n} cases")
    return 0


if __name__ == "__main__":
    sys.exit(main())

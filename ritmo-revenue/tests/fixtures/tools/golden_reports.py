#!/usr/bin/env python3
"""Golden cases of the reports area (M4): occupancy with blocked and unknown nights and the night map,
month performance, the 12-month review, the weekly panel and the revenue targets and forecast.

Moved from the draft of spec 06 (ritmo-specs/golden/m4/dump.py, reports part) without changing a single input, so the cases
are the ones the spec counts.

Safety (all on while the engine runs):
  - no bytecode is written (sys.dont_write_bytecode; run it with -B);
  - no network: urllib's urlopen, http.client and socket connections raise;
  - open() of anything under the engine's dados/ folder is redirected to the frozen copies in ./frozen (only the
    invented metas_faturamento.json) or refused; open() for writing anywhere under the skill folder is refused;
  - the engine's own clock is never read for the cases (every function gets its day and instant as arguments).
Inputs are invented: bookings are ids, rooms, dates, statuses, channels and amounts only (no guest data); the rooms
and properties are those of the parity fixture (tests only).

Usage: PYTHONDONTWRITEBYTECODE=1 python3 -I -B tests/fixtures/tools/golden_reports.py
"""
from __future__ import annotations

import builtins
import copy
import datetime as dt
import http.client
import json
import platform
import random
import socket
import subprocess
import sys
import urllib.request
from pathlib import Path
from unittest import mock

sys.dont_write_bytecode = True

PLUGIN = Path(__file__).resolve().parents[3]
REPO = PLUGIN.parent
ENGINE_PATH = ".claude/skills/gestao-receita-pousadas/scripts"
SKILL = REPO / ".claude" / "skills" / "gestao-receita-pousadas"
ENGINE = REPO / ENGINE_PATH
PLUGIN_FROZEN = PLUGIN / "tests" / "fixtures" / "frozen"
OUT = PLUGIN / "tests" / "fixtures" / "golden" / "m4" / "reports"
FROZEN = OUT / "frozen"

FROZEN_TODAY = dt.date(2026, 11, 13)
FROZEN_NOW = "2026-11-13T13:00:00+00:00"  # 10:00 at the properties (UTC-3)


# ---------------------------------------------------------------------------------------------- guards
# ---------------------------------------------------------------------------------------------- guards
def _no_network(*_a, **_k):
    raise RuntimeError("network access is blocked while dumping goldens")


_real_open = builtins.open
FROZEN_DATA = {"metas_faturamento.json": FROZEN / "metas_faturamento.json"}


def _guarded_open(file, mode="r", *args, **kwargs):
    if isinstance(file, (str, Path)):
        path = Path(file).resolve()
        inside_skill = SKILL in path.parents
        if inside_skill and any(flag in mode for flag in "wax+"):
            raise RuntimeError(f"refused to write into the skill folder: {path}")
        if (SKILL / "dados") in path.parents:
            frozen = FROZEN_DATA.get(path.name)
            if frozen is None:
                raise RuntimeError(f"refused to read live engine data: {path}")
            file = frozen
    return _real_open(file, mode, *args, **kwargs)



GUARDS = [
    mock.patch.object(urllib.request, "urlopen", _no_network),
    mock.patch.object(http.client.HTTPConnection, "connect", _no_network),
    mock.patch.object(socket.socket, "connect", _no_network),
    mock.patch.object(builtins, "open", _guarded_open),
]



# ---------------------------------------------------------------------------------------------- frozen inputs
def write_frozen_inputs() -> None:
    """Invented monthly targets read by desempenho_mes and revisao_12m (no owner figures)."""
    FROZEN.mkdir(exist_ok=True)
    months = {}
    for i, month in enumerate(["2026-09", "2026-10", "2026-11", "2026-12", "2027-01", "2027-02", "2027-03", "2027-04",
                               "2027-05", "2027-06", "2027-07", "2027-08", "2027-09", "2027-10", "2027-11"]):
        high = month[5:] in ("12", "01", "07")
        months[month] = {"recanto": {"minimo_dono": 260000 if high else 180000, "realista": 300000 if high else 210000},
                         "villa": {"minimo_dono": 200000 if high else 120000, "realista": 240000 if high else 150000}}
        if i == 3:
            months[month]["villa"] = {"realista": 240000}  # no minimum: achieved share and gap stay empty
    data = {"_doc": "Invented monthly targets for the M4 goldens (not the owner's).", "por_pousada": months}
    with _real_open(FROZEN / "metas_faturamento.json", "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=1)



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



# ---------------------------------------------------------------------------------------------- B. reports
def occupancy_cases(ocupacao, cfg) -> list[tuple[str, int]]:
    rng = random.Random(20261114)
    cases = []
    for scenario, blocked_share, gaps in (("blocked_nights", 0.12, 0.0), ("blocked_and_unknown", 0.1, 0.08)):
        entries = {}
        for q in cfg["quartos"]:
            rows = []
            for k in range(62):
                if rng.random() < gaps:
                    continue
                night = FROZEN_TODAY + dt.timedelta(days=k)
                units = q["unidades"]
                sold = min(units, max(0, round(rng.gauss(units * 0.5, units * 0.4))))
                row = {"date": night.isoformat(), "price": round(rng.uniform(600, 1400)), "user_price": round(rng.uniform(600, 1400)),
                       "multi_unit_occupancy": f"{sold}/{units}", "min_stay": rng.choice([1, 2, 2, 3]),
                       "demand_desc": rng.choice(["Low", "Normal", "High"])}
                if sold < units and rng.random() < blocked_share:
                    if rng.random() < 0.5:
                        row["user_price"] = -1
                    else:
                        row["unbookable"] = 1
                rows.append(row)
            entries[q["curto"]] = {"id": q["curto"], "data": rows}
        result = ocupacao.calcular(entries, cfg, FROZEN_TODAY, 14)
        cases.append({"input": {"scenario": scenario, "hoje": FROZEN_TODAY.isoformat(), "n_noites": 14,
                                "entries": entries}, "expected": result})
    return [write("ocupacao.calcular", "ocupacao.calcular", "Ritmo\\Domain\\Report\\OccupancyWindows + OccupancyMap",
                  "cfg is tests/fixtures/frozen/quartos.json. A night reported unavailable (user_price -1 or "
                  "unbookable 1) with units left is blocked: a blocked single-unit night leaves the windows (sold and "
                  "capacity); a blocked multi-unit night counts its sold units over its capacity. Groups: each room, "
                  "'Hotel' (every room of the site), 'Suítes com banheira' (rooms with the bathtub flag; in the plugin "
                  "a room tag group) and each property. noites = the night map of the next 14 nights (free units, "
                  "sold, total, price, sent price, minimum stay, demand label, holiday name).", cases)]


ROOMS = [("722805", "350362"), ("722806", "350362"), ("722807", "350362"), ("722808", "350362"),
         ("722813", "350364"), ("722814", "350364"), ("722815", "350364")]


def bookings(seed: int, count: int) -> list[dict]:
    """Invented PMS bookings (ids, rooms, dates, statuses, channels, amounts; no guest data)."""
    rng = random.Random(seed)
    out = []
    for i in range(count):
        room, prop = rng.choice(ROOMS)
        booked = dt.datetime(2026, 9, 1, tzinfo=dt.timezone.utc) + dt.timedelta(minutes=rng.randrange(0, 104 * 24 * 60))
        lead = rng.choice([0, 1, 3, 7, 14, 30, 60, 120])
        arrival = (booked - dt.timedelta(hours=3)).date() + dt.timedelta(days=lead)
        nights = rng.choice([1, 2, 2, 3, 4])
        status = rng.choices(["confirmed", "new", "cancelled", "request"], [0.62, 0.18, 0.16, 0.04])[0]
        channel = rng.choice(["booking", "booking", "airbnb", "expedia", "direct", "direct"])
        b = {"id": 900000 + i, "propertyId": prop, "roomId": room, "roomQty": rng.choice([1, 1, 1, 2]),
             "status": status, "channel": channel,
             "referer": "API" if channel == "direct" and rng.random() < 0.5 else ("frontdesk" if channel == "direct" else channel),
             "arrival": arrival.isoformat(), "departure": (arrival + dt.timedelta(days=nights)).isoformat(),
             "bookingTime": booked.strftime("%Y-%m-%dT%H:%M:%SZ"), "price": round(rng.uniform(450, 1500) * nights, 2)}
        if rng.random() < 0.6:
            items = [{"type": "charge", "qty": 1, "amount": round(b["price"] * 0.9, 2), "lineTotal": round(b["price"] * 0.9, 2)}]
            if rng.random() < 0.3:
                items.append({"type": "charge", "qty": 2, "amount": 35.0})
            if rng.random() < 0.2:
                items.append({"type": "charge", "qty": 1, "amount": 120.0, "lineTotal": 120.0, "invoiceeId": 77})
            if rng.random() < 0.2:
                items.append({"type": "payment", "qty": 1, "amount": -300.0, "lineTotal": -300.0})
            b["invoiceItems"] = items
        if status == "cancelled":
            when = booked + dt.timedelta(hours=rng.randrange(1, 24 * 40))
            if rng.random() < 0.55:
                b["cancelTime"] = when.strftime("%Y-%m-%dT%H:%M:%SZ")
            b["modifiedTime"] = when.strftime("%Y-%m-%dT%H:%M:%SZ")
        out.append(b)
    return out


def performance_cases(dm, rev, panel) -> list[tuple[str, int]]:
    out = []
    lst = bookings(20261115, 420)
    now = dt.datetime.fromisoformat(FROZEN_NOW)
    l_cases = [{"input": {"booking": b}, "expected": jsonable(dm.linha(b))} for b in lst[:40]]
    out.append(write("desempenho_mes.linha", "desempenho_mes.linha", "Ritmo\\Domain\\Report\\BookingLine",
                     "One booking as the reports count it: property, room, status, channel label (direct split by "
                     "the API origin), arrival, nights, units, revenue (charge items without an invoicee, else the "
                     "price), creation instant, lead time in days at the property (UTC-3) and the cancellation instant "
                     "(cancelTime, else modifiedTime of a cancelled booking). Property and room ids map through "
                     "beds24.QUARTOS (fixtures only).", l_cases))
    a_cases = []
    for size in (0, 1, 5, 30):
        rows = [dm.linha(b) for b in lst[100:100 + size] if b["status"] in ("confirmed", "new", "cancelled")]
        a_cases.append({"input": {"bookings": lst[100:100 + size]}, "expected": jsonable(dm.agrega(rows))})
    out.append(write("desempenho_mes.agrega", "desempenho_mes.agrega", "Ritmo\\Domain\\Report\\BookingTotals",
                     "Totals of a list of booking lines (input: the raw bookings; request and other statuses are "
                     "dropped before, as calcular does).", a_cases))
    c_cases = []
    for month, ate in (("2026-10", "2026-10-31T23:00:00+00:00"), ("2026-11", FROZEN_NOW), ("2026-12", FROZEN_NOW),
                       ("2027-01", FROZEN_NOW)):
        c_cases.append({"input": {"bookings": "bookings_420", "mes": month, "ate": ate},
                        "expected": jsonable(dm.calcular(lst, month, dt.datetime.fromisoformat(ate)))})
    out.append(write("desempenho_mes.calcular", "desempenho_mes.calcular", "Ritmo\\Domain\\Report\\MonthPerformance",
                     "input.bookings 'bookings_420' = frozen/bookings_420.json. Targets from frozen/"
                     "metas_faturamento.json (invented). Capacity per property from beds24.CAPACIDADE (16 and 15 "
                     "units). Made in the month (by creation instant), stays in the month (realised before today, on "
                     "the books from today), cancellations made in the month.", c_cases))
    with _real_open(FROZEN / "bookings_420.json", "w", encoding="utf-8") as f:
        json.dump(lst, f, ensure_ascii=False, indent=1)

    # revisao_12m
    m_cases = [{"input": {"hoje": h, "n": n}, "expected": rev.meses(dt.date.fromisoformat(h), n)}
               for h, n in (("2026-11-13", 13), ("2026-12-31", 13), ("2027-01-01", 3), ("2026-02-28", 13))]
    out.append(write("revisao_12m.meses", "revisao_12m.meses", "Ritmo\\Domain\\Report\\TwelveMonthReview::months",
                     "The current month and the next n - 1.", m_cases))
    hist_cases = []
    for hist, today in (({"revisoes": []}, "2026-11-13"), ({"revisoes": [{"hoje": "2026-10-29"}]}, "2026-11-13"),
                        ({"revisoes": [{"hoje": "2026-10-29"}]}, "2026-11-12"), ({"revisoes": [{"hoje": "2026-11-13"}]}, "2026-11-13")):
        due, why = rev.devida(dt.date.fromisoformat(today), hist)
        hist_cases.append({"input": {"hist": hist, "hoje": today}, "expected": {"due": due, "why": why}})
    out.append(write("revisao_12m.devida", "revisao_12m.devida", "Ritmo\\Domain\\Report\\TwelveMonthReview::due",
                     "Due when never run or 15 days or more since the last snapshot (reports.review.interval_days).",
                     hist_cases))
    market = {"2026-12": {"mercado_vendido": 18.0, "mercado_ano_passado": 43.0},
              "2027-01": {"mercado_vendido": 9.5, "mercado_ano_passado": 36.0},
              "2027-02": {"mercado_vendido": 4.2, "mercado_ano_passado": 29.0},
              "2027-03": {"mercado_vendido": 2.0, "mercado_ano_passado": 27.0},
              "2026-11": {"mercado_vendido": 30.0, "mercado_ano_passado": 35.0}}
    prices_now = {("Recanto", "2026-11"): 1210, ("Recanto", "2026-12"): 1490, ("Villa", "2026-12"): 1100,
                  ("Recanto", "2027-01"): 1320, ("Villa", "2027-01"): 990}
    prices_before = {("Recanto", "2026-12"): 1400, ("Villa", "2026-12"): 1150, ("Recanto", "2027-01"): 1320}
    earlier = dt.datetime(2026, 10, 29, 12, 0, tzinfo=dt.timezone.utc)
    made_before = [b for b in lst if b["bookingTime"] < "2026-10-29T12:00:00Z"]
    first = rev.rodar(made_before, dt.date(2026, 10, 29), earlier, market, prices_before, {"revisoes": []})
    second = rev.rodar(lst, FROZEN_TODAY, dt.datetime.fromisoformat(FROZEN_NOW), market, prices_now,
                       {"revisoes": [first]})
    as_list = lambda prices: [[p, m, v] for (p, m), v in sorted(prices.items())]  # noqa: E731
    r_cases = [
        {"input": {"bookings": "bookings_420 made before 2026-10-29T12:00:00Z", "hoje": "2026-10-29",
                   "ate": earlier.isoformat(), "market": market, "prices": as_list(prices_before), "hist": {"revisoes": []}},
         "expected": jsonable(first)},
        {"input": {"bookings": "bookings_420", "hoje": FROZEN_TODAY.isoformat(), "ate": FROZEN_NOW, "market": market,
                   "prices": as_list(prices_now), "hist": "the expected output of case 0, as the only snapshot"},
         "expected": jsonable(second)},
    ]
    out.append(write("revisao_12m.rodar", "revisao_12m.rodar", "Ritmo\\Domain\\Report\\TwelveMonthReview",
                     "Per month (current + 12) and property: sold revenue, sold occupancy and ADR of the stays in the "
                     "month (desempenho_mes), owner minimum and realistic target, achieved share, pickup since the "
                     "previous snapshot, market sold occupancy and last year's, reading ('à frente' when own sold "
                     "occupancy / market sold occupancy >= 1.2, 'atrás' <= 0.8, 'no ritmo' between, 'cedo' when the "
                     "market sold less than 5%, none for the current month or without market data), the average sent "
                     "price of the selected rooms and its change since the previous snapshot. prices are "
                     "[property, month, average].", r_cases))

    # painel_semanal
    p_cases = [{"input": {"bookings": "bookings_420", "ate": ate}, "expected": jsonable(panel.montar(lst, dt.datetime.fromisoformat(ate)))}
               for ate in (FROZEN_NOW, "2026-11-16T03:30:00+00:00")]
    out.append(write("painel_semanal.montar", "painel_semanal.montar", "Ritmo\\Domain\\Report\\WeeklyPanel",
                     "Five tables: bookings made per week (Monday to Sunday at the property) and property since "
                     "2026-09-07 (reports.weekly.start_date), with cancellations made that week; stays per stay week "
                     "(8 back, 8 ahead) with occupancy, revenue, ADR and RevPAR; the current month and the next two "
                     "against the owner minimum; bookings made in the last 28 days per channel and per room with their "
                     "share of the property's revenue. The second case is a Monday 00:30 at the property.", p_cases))
    return out


def targets_cases(mf) -> list[tuple[str, int]]:
    cases_f, cases_m, cases_p = [], [], []
    reference = (mf.INSUMOS, mf.DONO)
    invented_inputs = {
        "unidades": {"recanto": 12, "villa": 9},
        "mercado_ano_passado": {"2027-05": {"recanto": (31, 560), "villa": (38, 500)},
                                "2027-06": {"recanto": (44, 690), "villa": (49, 610)},
                                "2027-07": {"recanto": (58, 820), "villa": (61, 700)},
                                "2027-08": {"recanto": (40, 640), "villa": (45, 560)}},
        "livro": {"2027-05": 15000, "2027-06": 61000, "2027-07": 180000, "2027-08": 9000},
        "cenarios": {"piso": {"indice": {"recanto": 1.0, "villa": 0.9}, "crescimento": 1.0},
                     "base": {"indice": {"recanto": 1.2, "villa": 1.1}, "crescimento": 1.04},
                     "meta": {"indice": {"recanto": 1.5, "villa": 1.3}, "crescimento": 1.06}},
        "teto_95_recanto": {"2027-07": 0.93},
        "outubro_ultima_hora": {"piso": 0, "base": 0, "meta": 0},
        "abril_sem_pascoa": {"piso": 0, "base": 0, "meta": 0},
        "pascoa_noites_normais_marco": {"piso": 0, "base": 0, "meta": 0},
        "feriados": {
            "Páscoa (invented, 3 noites)": {"vendidas": 10, "receita": 9000, "diarias": 63, "ocupacao": (0.5, 0.6, 0.7),
                                           "diaria": (700, 760, 820), "piso_skill": 650},
            "Winter break (invented, 4 noites)": {"vendidas": 70, "receita": 61000, "diarias": 84,
                                                 "ocupacao": (0.6, 0.75, 0.8), "diaria": (900, 950, 1000), "piso_skill": 900}},
    }
    invented_owner = {"baixa": {"recanto": 150000, "villa": 90000}, "alta": {"recanto": 260000, "villa": 160000},
                      "meses_alta": ["2027-07"], "indice_maximo": {"recanto": 1.8, "villa": 1.6}}
    for label, (inputs, owner) in (("module inputs", reference), ("invented", (invented_inputs, invented_owner))):
        months = list(inputs["mercado_ano_passado"])
        with mock.patch.object(mf, "INSUMOS", inputs), mock.patch.object(mf, "DONO", owner), \
                mock.patch.object(mf, "MESES", months):
            fer = mf.feriados()
            res = mf.meses(fer)
            per = mf.por_pousada()
        shown = {"insumos": jsonable(inputs), "dono": jsonable(owner), "meses": months}
        cases_f.append({"input": {"label": label, **shown}, "expected": jsonable(fer)})
        cases_m.append({"input": {"label": label, **shown}, "expected": jsonable(res)})
        cases_p.append({"input": {"label": label, **shown}, "expected": jsonable(per)})
    return [
        write("metas_faturamento.feriados", "metas_faturamento.feriados", "Ritmo\\Domain\\Report\\HolidayForecast",
              "Per holiday and scenario (piso, base, meta): sold revenue + max(0, target occupancy x room nights - sold "
              "nights) x ADR, rounded half to even; the engine asserts ADR >= the holiday floor.", cases_f),
        write("metas_faturamento.meses", "metas_faturamento.meses", "Ritmo\\Domain\\Report\\RevenueForecast",
              "Per month and scenario: sum over properties of market occupancy LY% / 100 x market ADR LY x growth x "
              "index x units x days (x the occupancy cap of the first property in its months), never below the book. "
              "The engine's month-specific adjustments (2026-10, 2027-03, 2027-04) become manual adjustments in the "
              "plugin (divergence R4); the invented case has none of those months.", cases_m),
        write("metas_faturamento.por_pousada", "metas_faturamento.por_pousada", "Ritmo\\Domain\\Report\\RevenueTargets",
              "Per month and property: today's forecast (base index, growth 1.05), realistic (goal index, growth 1.05), "
              "maximum (owner maximum index), owner minimum by season (high months listed), RevPAR of the minimum and "
              "the minimum against the realistic forecast (rounded to 2 decimals).", cases_p),
    ]


# ---------------------------------------------------------------------------------------------- main
def git(*args: str) -> str:
    return subprocess.run(["git", "-C", str(REPO), *args], capture_output=True, text=True, check=False).stdout.strip()


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    FROZEN.mkdir(exist_ok=True)
    for guard in GUARDS:
        guard.start()
    try:
        write_frozen_inputs()
        sys.path.insert(0, str(ENGINE))
        import desempenho_mes  # noqa: E402
        import metas_faturamento  # noqa: E402
        import ocupacao  # noqa: E402
        import painel_semanal  # noqa: E402
        import revisao_12m  # noqa: E402
        from comum import carregar_quartos  # noqa: E402

        cfg = carregar_quartos(PLUGIN_FROZEN / "quartos.json")
        files = []
        files += occupancy_cases(ocupacao, cfg)
        files += performance_cases(desempenho_mes, revisao_12m, painel_semanal)
        files += targets_cases(metas_faturamento)
    finally:
        for guard in reversed(GUARDS):
            guard.stop()
    manifest = {
        "generated_for": FROZEN_TODAY.isoformat(),
        "frozen_now": FROZEN_NOW,
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": git("status", "--porcelain", "--", ENGINE_PATH + "/*.py") != "",
        "python": platform.python_version(),
        "frozen_inputs": sorted(p.name for p in FROZEN.iterdir()),
        "tolerance": {"amounts": 0.01, "percentages_and_counts": "exact"},
        "files": dict(files),
    }
    with _real_open(OUT / "MANIFEST.json", "w", encoding="utf-8") as f:
        f.write(json.dumps(manifest, ensure_ascii=False, indent=1) + "\n")
    print(json.dumps(manifest["files"], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())

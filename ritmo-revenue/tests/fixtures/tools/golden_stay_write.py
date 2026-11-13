#!/usr/bin/env python3
"""Golden case of the stay-write area (M4, part D2 of spec 06): a date rule with a lead_time_expiry on the
write path (pace.ajustar_tetos). The stay writes themselves reuse the M2 stay goldens (golden/m2/stay).

Moved from the draft of spec 06 (ritmo-specs/golden/m4/dump.py, expiry part) without changing a single input, so the cases
are the ones the spec counts.

Safety (all on while the engine runs):
  - no bytecode is written (sys.dont_write_bytecode; run it with -B);
  - no network: urllib's urlopen, http.client and socket connections raise;
  - open() of anything under the engine's dados/ folder is redirected to the frozen copies in ./frozen (only the
    invented metas_faturamento.json) or refused; open() for writing anywhere under the skill folder is refused;
  - the engine's own clock is never read for the cases (every function gets its day and instant as arguments).
Inputs are invented: bookings are ids, rooms, dates, statuses, channels and amounts only (no guest data); the rooms
and properties are those of the parity fixture (tests only).

Usage: PYTHONDONTWRITEBYTECODE=1 python3 -I -B tests/fixtures/tools/golden_stay_write.py
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
OUT = PLUGIN / "tests" / "fixtures" / "golden" / "m4" / "stay"
FROZEN = OUT / "frozen"

FROZEN_TODAY = dt.date(2026, 11, 13)
FROZEN_NOW = "2026-11-13T13:00:00+00:00"  # 10:00 at the properties (UTC-3)


# ---------------------------------------------------------------------------------------------- guards
# ---------------------------------------------------------------------------------------------- guards
def _no_network(*_a, **_k):
    raise RuntimeError("network access is blocked while dumping goldens")


_real_open = builtins.open
FROZEN_DATA: dict = {}


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



# ---------------------------------------------------------------------------------------------- D. expiry of a date rule
def expiry_cases(pace, cfg) -> list[tuple[str, int]]:
    q = cfg["por_curto"]["Q7"]
    top = float(q["max"])
    rules = {"Q7": {
        "2026-11-20": {"date": "2026-11-20", "min_price": "10", "min_price_type": "percent_base", "min_stay": 2,
                       "check_in": "1111101", "check_out": "1111110", "lead_time_expiry": 3},
        "2026-11-21": {"date": "2026-11-21", "min_price": "10", "min_price_type": "percent_base", "max_price": "40",
                       "max_price_type": "percent_base", "lead_time_expiry": 5},
        "2026-11-22": {"date": "2026-11-22", "min_price": "10", "min_price_type": "percent_base", "min_stay": 3},
        "2026-11-23": {"date": "2026-11-23", "min_price": "5", "min_price_type": "percent_base", "lead_time_expiry": 2},
    }}
    items = [
        {"tipo": "data", "quarto": "Q7", "data": "2026-11-20", "min_price": 1300.0, "max_price": top, "motivo": "release"},
        {"tipo": "data", "quarto": "Q7", "data": "2026-11-21", "min_price": 1300.0, "max_price": top, "motivo": "release"},
        {"tipo": "data", "quarto": "Q7", "data": "2026-11-22", "min_price": 1300.0, "max_price": top, "motivo": "release"},
        {"tipo": "data", "quarto": "Q7", "data": "2026-11-23", "min_price": 1250.0, "motivo": "floor only"},
        {"tipo": "data", "quarto": "Q7", "data": "2026-11-24", "min_price": 1250.0, "max_price": top, "motivo": "no rule"},
    ]
    kept, delete = pace.ajustar_tetos(cfg, copy.deepcopy(items), rules, None, hoje=FROZEN_TODAY)
    cases = [{"input": {"items": items, "rules": rules, "hoje": FROZEN_TODAY.isoformat()},
              "expected": {"items": kept, "delete": [{k: v for k, v in d.items() if k != "motivo"} for d in delete]}}]
    return [write("pace.ajustar_tetos.expiry", "pace.ajustar_tetos", "Ritmo\\Domain\\Plan\\Assembly\\CeilingRelease + ExpiryRewrite",
                  "cfg is tests/fixtures/frozen/quartos.json. An item releasing the ceiling on a night whose rule has a "
                  "ceiling or a lead_time_expiry goes delete-first and inherits min_stay, check_in and check_out of the "
                  "deleted rule. The engine does NOT convert a floor-only item on a night whose rule has an expiry "
                  "(2026-11-23 here): the procedure deletes it by hand (SKILL.md step 6). The plugin converts every "
                  "write on such a night (W36, divergence X1).", cases)]


# ---------------------------------------------------------------------------------------------- main
def git(*args: str) -> str:
    return subprocess.run(["git", "-C", str(REPO), *args], capture_output=True, text=True, check=False).stdout.strip()


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    FROZEN.mkdir(exist_ok=True)
    for guard in GUARDS:
        guard.start()
    try:
        sys.path.insert(0, str(ENGINE))
        import pace  # noqa: E402
        from comum import carregar_quartos  # noqa: E402

        cfg = carregar_quartos(PLUGIN_FROZEN / "quartos.json")
        files = []
        files += expiry_cases(pace, cfg)
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

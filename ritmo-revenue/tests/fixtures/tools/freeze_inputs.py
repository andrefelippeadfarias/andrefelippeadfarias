#!/usr/bin/env python3
"""Freezes the inputs of the golden tests.

Copies the reference engine's data into tests/fixtures/frozen/ so that the golden fixtures and the
parity preset are always generated from the same, versioned inputs:

  quartos.json          full copy of the engine configuration, minus staff logins (see REDACTIONS)
  ancora_mercado.json   a small cut of the market anchor: a few nights of each night type plus the
                        per-type monthly fallback table

Run it only when the reference rules change and the goldens must follow; then run dump_golden.py and
make_parity_preset.py again. It reads the engine's folder and never writes to it.

Usage: python3 tests/fixtures/tools/freeze_inputs.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.dont_write_bytecode = True

TOOLS = Path(__file__).resolve().parent
FROZEN = TOOLS.parent / "frozen"
REPO = TOOLS.parents[3]
ENGINE_DATA = REPO / ".claude" / "skills" / "gestao-receita-pousadas" / "dados"

# Nights kept from the market anchor: weekday, weekend and holiday nights, measured and estimated
# medians, a night whose p50 equals its p75 and two nights fixed by an owner order.
ANCHOR_NIGHTS = (
    "2026-11-12", "2026-11-13", "2026-11-14", "2026-11-17", "2026-11-19", "2026-11-20",
    "2026-11-24", "2026-11-27", "2026-12-04", "2026-12-15", "2026-12-24",
)

# Origins that are sales channels or integrations. Everything else in the engine's list of known
# origins is a staff login, which the fixtures do not need.
CHANNEL_ORIGINS = {"Booking.com", "Airbnb", "Expedia", "Expedia Affiliate Network", "google", "setBooking JSON", "API"}
REDACTIONS = ["parametros.seguranca.origens_conhecidas: staff logins removed, channels kept"]


def read(path: Path) -> dict:
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


def write(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=1)
        handle.write("\n")


def frozen_rooms() -> dict:
    cfg = read(ENGINE_DATA / "quartos.json")
    security = cfg.get("parametros", {}).get("seguranca", {})
    if "origens_conhecidas" in security:
        security["origens_conhecidas"] = [o for o in security["origens_conhecidas"] if o in CHANNEL_ORIGINS]
    return cfg


def frozen_anchor() -> dict:
    anchor = read(ENGINE_DATA / "ancora_mercado.json")
    missing = [night for night in ANCHOR_NIGHTS if night not in anchor["noites"]]
    if missing:
        raise SystemExit(f"anchor nights not found in the engine data: {missing}")
    return {
        "_doc": anchor.get("_doc", ""),
        "atualizado_em": anchor.get("atualizado_em"),
        "fontes": ["frozen cut for the golden tests"],
        "noites": {night: anchor["noites"][night] for night in ANCHOR_NIGHTS},
        "por_tipo": anchor["por_tipo"],
    }


def main() -> int:
    write(FROZEN / "quartos.json", frozen_rooms())
    write(FROZEN / "ancora_mercado.json", frozen_anchor())
    write(FROZEN / "REDACTIONS.json", {"redactions": REDACTIONS})
    print(f"frozen inputs written to {FROZEN}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

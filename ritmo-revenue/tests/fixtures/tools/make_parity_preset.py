#!/usr/bin/env python3
"""Builds the parity preset: the reference engine's configuration in the plugin's preset format.

Reads tests/fixtures/frozen/quartos.json and writes tests/fixtures/presets/parity.json. The golden
tests load this preset on the PHP side, so that PHP and Python compute from the same rules.

The sections of milestone M2 come from the parity fixtures the golden generators of each module
write next to it (run them first): parity-relations.json (relations, price_bands), parity-ladder.json
(ladder, soldout, cadence, no_effect, orders), parity-stay.json (stay) and parity-plan.json (plan);
those of milestone M3 from parity-write.json (write, quota), parity-verify.json (verify, third_party,
waves, approval) and parity-ops.json (schedule, light_run, alerts, modes).
Inputs of a run that live in those fixtures (the market ruler, the weekly reference of the listings,
the PMS name) are not rules and stay out of the preset.

Only what the plugin's rule set schema knows is converted; what is left out is listed on the
screen. The preset is test data: it never ships with the plugin.

Usage: python3 tests/fixtures/tools/make_parity_preset.py
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

sys.dont_write_bytecode = True

from vocabulary import NIGHT_TYPE, PROPERTY_ID, PROPERTY_TIER, TARGET_STATUS  # noqa: E402

TOOLS = Path(__file__).resolve().parent
FROZEN = TOOLS.parent / "frozen"
OUTPUT = TOOLS.parent / "presets" / "parity.json"

# Fixed in the engine's code rather than in its data file.
WEEKEND_DAYS = [4, 5]  # ancora_mercado.tipo_noite: Friday and Saturday nights
ZERO_SOLD_DAYS = {"weekday": 7, "weekend": 14, "holiday": 14}  # pace.status_da_noite
TUB_TAG = "tub"
GROUP_PACE_MAX_UNITS = 2  # pace._plano: rooms of 1 or 2 units use pace.ocupacao_pousada (the rooms with a tub)

LEFT_OUT = [
    "feriados[].piso_real_por_quarto (per-room holiday floors)",
    "limites_seguranca.excecoes_dono (dated owner exceptions)",
    "parametros.fator_canal, seguranca and the rest of parametros",
]

# Rule set sections of milestones M2 and M3, by the module fixture that holds them.
MODULE_SECTIONS = {
    "parity-relations.json": ["relations", "price_bands"],
    "parity-ladder.json": ["ladder", "soldout", "cadence", "no_effect", "orders"],
    "parity-stay.json": ["stay"],
    "parity-plan.json": ["plan"],
    "parity-write.json": ["write", "quota"],
    "parity-verify.json": ["verify", "third_party", "waves", "approval"],
    "parity-ops.json": ["schedule", "light_run", "alerts", "modes"],
}


def properties(cfg: dict) -> list[dict]:
    full_names = list((cfg["parametros"].get("seguranca") or {}).get("booking_propriedades") or {})
    out = []
    for short in dict.fromkeys(q["pousada"] for q in cfg["quartos"]):
        name = next((n for n in full_names if n.startswith(short)), short)
        out.append({
            "id": PROPERTY_ID[short],
            "name": name,
            "timezone": cfg["fuso"],
            "currency": "BRL",
            "mode": "shadow",
            "tier": PROPERTY_TIER[short],
            "occupancy_goals": {},
            "room_types": [room_type(q) for q in cfg["quartos"] if q["pousada"] == short],
        })
    return out


def room_type(q: dict) -> dict:
    return {
        "code": q["curto"],
        "name": q["nome"],
        "units": q["unidades"],
        "pms_ref": q["id"].split("___")[1],
        "pricing_ref": q["id"],
        "base": q["base"],
        "min": q["min"],
        "max": q["max"],
        "reference_min": q["min_referencia"],
        "tags": [TUB_TAG] if q.get("banheira") else [],
    }


def holidays(cfg: dict) -> list[dict]:
    return [{
        "name": f["nome"],
        "start": f["inicio"],
        "end": f["fim"],
        "night_type": "holiday",
        "min_stay": f.get("estadia_minima"),
        "floor": f.get("piso_real"),
    } for f in cfg["feriados"]]


def max_step(pmax: dict) -> list[dict]:
    """{"31+": 5, "8-30": 10, ...} -> rows keyed by the first day of each band."""
    rows = [{"min_days": int(band.rstrip("+").split("-")[0]), "percent": percent} for band, percent in pmax.items()]
    return sorted(rows, key=lambda row: -row["min_days"])


def pace(cfg: dict) -> dict:
    p = cfg["parametros"]["pace"]
    return {
        "curves": {NIGHT_TYPE[t]: {str(d): v for d, v in curve.items()} for t, curve in p["curvas"].items()},
        "band": p["banda"],
        "very_behind": p["muito_atras"],
        "zero_sold_days": ZERO_SOLD_DAYS,
        "group_pace": {"max_units": GROUP_PACE_MAX_UNITS, "room_tags": [TUB_TAG]},
        "target_percentile": {
            PROPERTY_TIER[short]: {TARGET_STATUS[status]: value for status, value in table.items()}
            for short, table in p["alvo"].items()
        },
        "ahead_near_days": p["frente_perto_dias"],
        "sales_ramp": {"enabled": bool(p["escada_vendas"].get("ativa")), "top_percentile": float(p["escada_vendas"].get("p_topo", 90))},
        "k": p["k"],
        "max_step": max_step(p["pmax"]),
        "min_n": p["min_n"],
        "max_age_days": p["idade_max_dias"],
        "tolerance": p["tolerancia"],
        "protection_percentile": p["protecao_percentil"],
    }


def floors(cfg: dict) -> dict:
    lim = cfg["limites_seguranca"]
    return {
        "fraction_of_reference_min": lim["fracao_min_referencia"],
        "owner": [
            {"night_type": "weekday", "amount": lim["piso_vitrine_semana_suites"], "price_plan": "discounted", "room_tags": [TUB_TAG]},
            {"night_type": "weekend", "amount": lim["piso_vitrine_fds_suites"], "price_plan": "discounted", "room_tags": [TUB_TAG]},
        ],
        "quality_floor": {
            "amount": lim["padrao_qualidade_diaria"],
            "price_plan": "sent",
            "night_types": ["weekend", "holiday"],
            "room_tags": [TUB_TAG],
        },
        "holiday_floor": {"amount": lim["piso_feriado_real_seguranca"], "price_plan": "discounted"},
    }


def price_factors(cfg: dict) -> dict:
    """Public: one measured factor per property. Discounted: the deepest-discount factor on weekdays and
    weekends and, on holidays, the "real value" factor of the rooms (what the holiday floors are set in)."""
    par = cfg["parametros"]
    neutral = {"weekday": 1.0, "weekend": 1.0, "holiday": 1.0}
    tables = {"*": {"public": dict(neutral), "discounted": dict(neutral)}}
    overrides = []
    for short, property_id in PROPERTY_ID.items():
        rooms = [q for q in cfg["quartos"] if q["pousada"] == short]
        real = {q["fator_real"] for q in rooms}
        if len(real) != 1:
            raise SystemExit(f"{short}: rooms disagree on fator_real {sorted(real)}; add room overrides for them")
        public = par["fator_publico"][short]
        tables[property_id] = {
            "public": {"weekday": public, "weekend": public, "holiday": public},
            "discounted": {
                "weekday": par["fator_vitrine_semana"][short],
                "weekend": par["fator_vitrine_fds"][short],
                "holiday": real.pop(),
            },
        }
        overrides += [
            {"room": q["curto"], "price_plan": "discounted", "night_type": "holiday", "factor": q["fator_vitrine"]}
            for q in rooms if q.get("piso_feriado_base") == "vitrine"
        ]
    return {"properties": tables, "room_overrides": overrides}


def preset(cfg: dict) -> dict:
    return {
        "format": "ritmo-revenue-preset",
        "format_version": 1,
        "name": "Parity preset",
        "description": "Generated by tests/fixtures/tools/make_parity_preset.py from tests/fixtures/frozen/quartos.json "
                       "for the golden tests. Do not edit by hand.",
        "properties": properties(cfg),
        "rules": {
            "occupancy": {"goals": {str(window): goal for window, goal in cfg["metas"].items()}},
            "calendar": {"weekend_days": WEEKEND_DAYS, "holidays": holidays(cfg)},
            "pace": pace(cfg),
            "floors": floors(cfg),
            "price_factors": price_factors(cfg),
            "safety": {"weekly_listing_change_max": cfg["limites_seguranca"]["variacao_semanal_base_min"], "never_raise_today": True},
            **module_sections(),
        },
    }


def module_sections() -> dict:
    """The M2 and M3 sections, copied from the parity fixture of the module that owns each one and completed
    with the schema defaults (the fixtures leave out fields at their default), so that the preset
    passes the strict validator."""
    out: dict = {}
    for name, sections in MODULE_SECTIONS.items():
        with open(OUTPUT.parent / name, encoding="utf-8") as handle:
            fixture = json.load(handle)
        for section in sections:
            if section not in fixture:
                raise SystemExit(f"{name} has no section {section!r}: run the golden generator of that module first")
            out[section] = fixture[section]
    filled = subprocess.run(["php", str(TOOLS / "fill_defaults.php")], input=json.dumps(out), capture_output=True,
                            text=True, check=True)
    complete = json.loads(filled.stdout)
    return {section: complete[section] for section in out}


def main() -> int:
    with open(FROZEN / "quartos.json", encoding="utf-8") as handle:
        cfg = json.load(handle)
    data = preset(cfg)
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    rooms = sum(len(p["room_types"]) for p in data["properties"])
    print(f"{OUTPUT}: {len(data['properties'])} properties, {rooms} room types, {len(data['rules']['calendar']['holidays'])} holidays")
    print("M2 and M3 sections from the module fixtures: " + ", ".join(s for sections in MODULE_SECTIONS.values() for s in sections))
    print("left out (not in the rule set schema yet):")
    for item in LEFT_OUT:
        print("  - " + item)
    return 0


if __name__ == "__main__":
    sys.exit(main())

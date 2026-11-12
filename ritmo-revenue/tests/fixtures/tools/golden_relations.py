#!/usr/bin/env python3
"""Golden fixtures and parity preset of the relations between room types (milestone M2).

Runs the relation code of the reference Python engine (read from the repository, never changed) with
frozen inputs and writes, under tests/fixtures/golden/m2/relations/:

    <module>.<function>.json   {"function", "php_target", "notes", "cases": [{"name", "input", "expected"}]}
    MANIFEST.json              engine commit, frozen-input hashes and case counts

and the parity preset of the relations, tests/fixtures/presets/parity-relations.json: the owner's relations
written in the plugin's generic graph, with the values read from the frozen engine configuration
(tests/fixtures/frozen/quartos.json) and from the engine's own constants.

The relation stages ask other subsystems (floors, owner orders, sales ladder, market) for answers through hooks.
The engine computes those answers inline; this tool computes them with the engine's own helpers and RECORDS them
in each case ("hooks"), so that the PHP test checks the relation graph alone, with the same answers.

Inputs are frozen: the engine configuration is the M1 copy (tests/fixtures/frozen/quartos.json); the owner's
ruler, seasonality and the four standing orders the relations cite are copied by --freeze into
tests/fixtures/golden/m2/relations/frozen/ (numbers and configuration values only). "Today" is always an input;
reading the clock raises. Calendars are invented. No guest data anywhere. Nothing is written to the engine's
folder: no bytecode, and no engine function that writes is called.

Usage:
    python3 tests/fixtures/tools/golden_relations.py            regenerate goldens and the parity preset
    python3 tests/fixtures/tools/golden_relations.py --freeze   first copy the engine data (read-only) again
"""

from __future__ import annotations

import sys

sys.dont_write_bytecode = True  # the engine folder must stay untouched, bytecode cache included

import argparse  # noqa: E402
import copy  # noqa: E402
import datetime as dt  # noqa: E402
import hashlib  # noqa: E402
import json  # noqa: E402
import re  # noqa: E402
import subprocess  # noqa: E402
from pathlib import Path  # noqa: E402

TOOLS = Path(__file__).resolve().parent
FIXTURES = TOOLS.parent
M1_FROZEN = FIXTURES / "frozen"
OUT = FIXTURES / "golden" / "m2" / "relations"
OWN_FROZEN = OUT / "frozen"
PARITY = FIXTURES / "presets" / "parity-relations.json"
REPO = TOOLS.parents[3]
SKILL = REPO / ".claude" / "skills" / "gestao-receita-pousadas"
ENGINE = SKILL / "scripts"
ENGINE_DATA = SKILL / "dados"
STANDING_ORDERS = ("dono-2026-10-07-regra-g", "dono-2026-10-21-ordem-recanto", "dono-2026-10-22-afrodite-mais-cara",
                   "dono-2026-11-09-plano-nov-fev")
NIGHT_TYPE = {"semana": "weekday", "fds": "weekend", "feriado": "holiday"}


# ---------------------------------------------------------------- --freeze: sanitized copies of the engine data
def _write_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=1)
        handle.write("\n")


def freeze() -> None:
    """Copies what the relation code reads besides quartos.json: the owner's ruler by night (market series and
    measured factors), the seasonality periods and the structure of the standing orders (no text)."""
    ruler = json.load(open(ENGINE_DATA / "regua_dono.json", encoding="utf-8"))
    keep = ("tipo", "lista", "hidro", "pares_lista", "pares_hidro", "fator_publico", "pedido_0911")
    _write_json(OWN_FROZEN / "regua_dono.json", {"lido_em": ruler.get("lido_em"), "noites": {
        n: {k: v[k] for k in keep if k in v} for n, v in ruler["noites"].items()}})
    season = json.load(open(ENGINE_DATA / "sazonalidade.json", encoding="utf-8"))
    _write_json(OWN_FROZEN / "sazonalidade.json", {"periodos": [
        {k: p[k] for k in ("inicio", "fim", "dias", "estadia_minima") if k in p} for p in season["periodos"]]})
    orders = json.load(open(ENGINE_DATA / "ordens_dono.json", encoding="utf-8"))["ordens"]
    fields = ("id", "data", "criada_em", "tipo", "quartos", "de", "ate", "libera")
    _write_json(OWN_FROZEN / "ordens.json", {"ordens": [
        {k: o[k] for k in fields if k in o} for o in orders if o["id"] in STANDING_ORDERS]})


if "--freeze" in sys.argv:
    freeze()

# ---------------------------------------------------------------- the engine, read-only, on frozen inputs
sys.path.insert(0, str(ENGINE))
import comum  # noqa: E402
import ordens as ORD  # noqa: E402
import plano_dono as PD  # noqa: E402
import sazonalidade as SAZ  # noqa: E402

PD.ARQ_REGUA = OWN_FROZEN / "regua_dono.json"
PD._regua.cache_clear()
ORD.ARQ_ORDENS = OWN_FROZEN / "ordens.json"
SAZ.ARQ = OWN_FROZEN / "sazonalidade.json"
import validar_plano as VP  # noqa: E402

VP._sazonalidade.cache_clear()
import ordem_recanto as OR  # noqa: E402
import pace  # noqa: E402
from ancora_mercado import ancora_da_noite, tipo_noite, valor_percentil  # noqa: E402


def _no_clock(*_args, **_kwargs):
    raise RuntimeError("the golden generator must never read the clock")


comum.hoje_brt = _no_clock
pace.hoje_brt = _no_clock

CFG = comum.carregar_quartos(M1_FROZEN / "quartos.json")
ORDERS_FROZEN = ORD.carregar_ordens(OWN_FROZEN / "ordens.json")
PLAN_ID = PD.ORDEM_ID
PLANO = {PLAN_ID: ORDERS_FROZEN[PLAN_ID]}
SEM_ORDENS: dict = {}
ROOMS = ("Q7", "Double", "Q2", "Afrodite", "VK7", "VK2", "Balcony")
UNITS = {k: CFG["por_curto"][k]["unidades"] for k in ROOMS}
FILES: dict = {}


# ---------------------------------------------------------------- the parity preset of the relations
# The ruler's vocabulary of the plugin (tests/fixtures/golden/m2/ladder/MANIFEST.json, written by golden_ladder.py).
RULER_LABEL = {"semana": "weekday", "inverno_semana": "winter_weekday", "fds": "weekend", "inverno_fds": "winter_weekend",
               "feriado": "holiday", "natal": "christmas", "entre_festas": "between_holidays", "reveillon": "new_year"}
RULER_SERIES = {"lista": "cheapest", "hidro": "comparable", "pares_lista": "peers_cheapest", "pares_hidro": "peers_comparable"}


def relations_preset(cfg: dict) -> dict:
    """The owner's relations in the generic graph. Values come from the configuration and the engine's constants;
    the literals are the engine's own literals (cited)."""
    par = cfg["parametros"]
    top = cfg["por_curto"]["Afrodite"]
    discount = float(par.get("regra_g_desconto", 0.10))
    weekend = float(top["premio_minimo"]) * 100
    plan_period = [{"start": PD.DE, "end": PD.ATE}]
    scope = {"night_types": [], "weekdays": [], "periods": [], "exclude_periods": [], "min_days_out": None,
             "max_days_out": None, "requires_order": None}

    def rel(rid, label, prop, typ, target, priority, stages, scan, strategy, params, order, **scope_patch):
        return {"id": rid, "label": label, "enabled": True, "property": prop, "type": typ, "target": target,
                "priority": priority, "stages": stages, "scan": scan, "strategy_nights": strategy,
                "scope": dict(scope, **scope_patch), "params": params, "order_ref": order}

    items = [
        rel("recanto-double-at-least-q7", "Middle-high room never below the low room, unless the low room is on its last unit",
            "recanto", "at_least", "Double", 10, ["coherence"], "touched", "skip",
            {"reference": "Q7", "margin": {"type": "percent", "value": 0}, "reference_min_units_left": 2,   # pace.py sobra >= 2
             "derive": False, "derive_min": "none"}, None),
        rel("recanto-q2-between-q7-double", "Middle room between the low and the high room (up to 75% of the way)",
            "recanto", "between", "Q2", 20, ["strategy", "coherence", "audit"], "touched", "skip",
            {"mode": "path", "low_reference": "Q7", "high_reference": "Double", "position_min": 0.0,
             "position_max": OR.POSICAO, "references": "free_only"}, "dono-2026-10-21-ordem-recanto"),
        rel("recanto-afrodite-15-over-double", "Top room above the high room on the nights priced by the sales ladder",
            "recanto", "at_least", "Afrodite", 25, ["strategy", "audit"], "touched", "only",
            {"reference": "Double", "margin": {"type": "percent", "value": round((PD.AFRODITE_SOBRE_DOUBLE - 1) * 100, 6)},
             "reference_min_units_left": 0, "derive": True, "derive_min": "quality_floor"}, PLAN_ID,
            periods=plan_period, requires_order=PLAN_ID),
        rel("recanto-afrodite-premium-over-max", "Top room the most expensive, by a premium per night type",
            "recanto", "premium_over_max", "Afrodite", 30, ["strategy", "coherence", "audit"], "touched", "skip",
            {"set": {"room_tags": ["tub"]},
             "premium_percent": {"weekday": round(float(top.get("premio_minimo_semana", top["premio_minimo"])) * 100, 6),
                                 "weekend": round(weekend, 6), "holiday": round(weekend, 6)},
             "epsilon_amount": 0.5, "members": "free_only"}, "dono-2026-10-22-afrodite-mais-cara"),  # pace.py + 0.5
        rel("villa-balcony-switch-80", "Room without a tub at its market price when most tub suites are sold",
            "villa", "occupancy_switch", "Balcony", 10, ["coherence", "validate"], "horizon", "apply",
            {"trigger": {"set": {"room_tags": ["tub"]}, "unit": "percent", "threshold": round(PD.GATILHO_BALCONY * 100, 6)},
             # keyed by the ruler's night type and named by the plugin's ruler series (the ladder's vocabulary), so
             # that the plan assembly reads the market price from the same ruler as the ladder
             "then": {"kind": "market_percentile", "by_night_group": {
                 RULER_LABEL[label]: {"segment": RULER_SERIES[PD.BALCONY_MERCADO[g][0]], "percentile": float(PD.BALCONY_MERCADO[g][1])}
                 for label, g in PD.GRUPO.items()}},
             "cap_relation": "villa-balcony-discount-band", "floor": "safety",
             "suspends": ["villa-balcony-discount-band"]}, PLAN_ID, periods=plan_period, requires_order=PLAN_ID),
        rel("villa-balcony-discount-band", "Room without a tub below the cheapest free tub suite",
            "villa", "discount_band", "Balcony", 20, ["coherence", "validate"], "horizon", "apply",
            {"set": {"room_tags": ["tub"]}, "upper_percent": round((1 - discount) * 100, 6),
             "lower_percent": round(0.95 * (1 - discount) * 100, 6),          # pace.py 0.95 * (1 - desc)
             "reference_rounding_margin": 15.0, "lower_extra_amount": 1.0,     # pace.py ceil(... (ref + 15)) + 1
             "members": "free_only", "on_empty_set": "skip", "down_only_night_types": ["holiday"],
             "order_floor_yields": True, "follow_strategy_reference": True, "write_mode": "pin"},
            "dono-2026-10-07-regra-g"),
    ]
    low, high = par["afrodite_fds_publico"]
    band = {"id": "recanto-afrodite-weekend-market-band", "label": "Top room on weekends: high band of the market",
            "enabled": True, "property": "recanto", "room": "Afrodite", "night_types": ["weekend"],
            "price_plan": "public", "min_amount": float(low), "max_amount": float(high),
            "max_days_out": int(par.get("afrodite_fds_horizonte_dias", 95)), "skip_on_strategy_nights": True}
    return {
        "_doc": "Parity fixture of the relations (generated by tests/fixtures/tools/golden_relations.py; do not edit): "
                "the reference engine's relations between room types in the plugin's generic graph. Test data only.",
        "relations": {"tolerance": {"trigger_amount": 1.0, "audit_base_percent": 1.0, "audit_amount": 1.0},
                      "scan_horizon_days": 60, "audit_horizon_days": 366, "blocked_nights": "ignore",
                      "rounding_recheck": {"mode": "warn", "epsilon_amount": 0.005}, "items": items},
        "price_bands": {"items": [band]},
    }


def patches_of(patch: dict | None) -> tuple[dict, dict]:
    """A config_patch of the engine as (rooms_patch, relations_patch) of the plugin's fixtures."""
    rooms, rels = {}, {}
    for path, value in (patch or {}).items():
        parts = path.split(".")
        if parts[0] == "por_curto" and parts[2] in ("base", "max"):
            rooms.setdefault(parts[1], {})[parts[2]] = value
        elif path == "parametros.regra_g_desconto":
            rels["villa-balcony-discount-band"] = {"upper_percent": round((1 - value) * 100, 6),
                                                   "lower_percent": round(0.95 * (1 - value) * 100, 6)}
        elif path == "por_curto.Afrodite.premio_minimo_semana":
            rels.setdefault("recanto-afrodite-premium-over-max", {})["premium_percent.weekday"] = round(value * 100, 6)
        elif path == "por_curto.Afrodite.premio_minimo":
            for t in ("weekend", "holiday"):
                rels.setdefault("recanto-afrodite-premium-over-max", {})[f"premium_percent.{t}"] = round(value * 100, 6)
        elif path not in ("parametros.afrodite_fds_publico", "parametros.regra_j.fator_publico.Recanto",
                          "parametros.afrodite_fds_horizonte_dias"):
            raise ValueError(f"config_patch {path} has no plugin counterpart")
    return rooms, rels


# ---------------------------------------------------------------- helpers
def D(s: str) -> dt.date:
    return dt.date.fromisoformat(s)


def cfg_com(patch: dict | None) -> dict:
    if not patch:
        return CFG
    c = copy.deepcopy(CFG)
    for path, value in patch.items():
        o = c
        parts = path.split(".")
        for p in parts[:-1]:
            o = o[int(p)] if isinstance(o, list) else o[p]
        if value is None:
            o.pop(parts[-1], None)
        else:
            o[parts[-1]] = value
    return c


def row(n: str, price, v: int, u: int, mn=None, mx=None) -> dict:
    """Invented calendar night: price, sold/units and, optionally, the floor and ceiling in force."""
    r = {"date": n, "price": str(price), "mu_occupancy": f"{v}/{u}" if u > 1 else "-1", "num_bookings": str(v)}
    if mn is not None:
        r["min_price"] = str(mn)
    if mx is not None:
        r["max_price"] = str(mx)
    return r


def ordem(oid: str, tipo: str, quartos: list, de: str, ate: str, **extra) -> dict:
    o = {"id": oid, "data": "2027-02-20", "tipo": tipo, "quartos": quartos, "de": de, "ate": ate, "libera": []}
    o.update(extra)
    return o


def keyed(d: dict) -> dict:
    """{(room, night): v} -> {"room|night": v}"""
    return {f"{a}|{b}": v for (a, b), v in sorted(d.items())}


def key_list(s) -> list:
    return sorted(f"{a}|{b}" for (a, b) in (s or ()))


def num(v):
    return None if v is None else float(v)


def calendar(cfg: dict, cals: dict) -> dict:
    """Engine calendar rows as the plugin's price nights: price, sold, total, floor and ceiling in force."""
    out = {}
    for k, nights in cals.items():
        out[k] = {}
        for n, r in sorted(nights.items()):
            sold, total = comum.vendidas_total(r, cfg["por_curto"][k]["unidades"])
            out[k][n] = {"price": float(r["price"]), "sold": sold, "total": total,
                         "min": num(pace.vigente(cfg, k, r, None, "min_price")),
                         "max": num(pace.vigente(cfg, k, r, None, "max_price"))}
    return out


def night_type(cfg: dict, n: str) -> str:
    return NIGHT_TYPE[tipo_noite(cfg, D(n))]


def draft_item(it: dict) -> dict:
    return {"room": it["quarto"], "night": it["data"], "floor": num(it.get("min_price")), "ceiling": num(it.get("max_price")),
            "order_ref": it.get("ordem_id"), "price_before": num(it.get("preco_antes")),
            "engine_step": bool(it.get("pace")), "strategy_item": it.get("ordem_id") == PLAN_ID,
            "protection": bool(it.get("protecao"))}


def result_item(it: dict) -> dict:
    return {"room": it["quarto"], "night": it["data"], "floor": num(it.get("min_price")), "ceiling": num(it.get("max_price")),
            "order_ref": it.get("ordem_id"), "price_before": num(it.get("preco_antes"))}


def write_golden(name: str, function: str, php_target: str, notes: str, cases: list[dict]) -> None:
    names = [c["name"] for c in cases]
    assert len(names) == len(set(names)), f"{name}: repeated case name"
    _write_json(OUT / f"{name}.json", {"function": function, "php_target": php_target, "notes": notes, "cases": cases})
    FILES[f"{name}.json"] = len(cases)
    print(f"{name}.json: {len(cases)} cases")


# ---------------------------------------------------------------- hook answers (the parity adapter)
def ladder_on(cfg: dict) -> bool:
    return bool(pace.par_pace(cfg)["escada_vendas"].get("ativa"))


def floor_hooks(cfg: dict, ordens: dict, k: str, n: str) -> dict:
    d = D(n)
    floor, _, order = pace.piso_com_ordem(cfg, k, d, ordens)
    lowest = pace.pisos_enviados(cfg, k, d)[0]
    if order and "abaixo_piso" in (order.get("libera") or []):       # pace.py, the band's order floor gives way
        values = [float(floor)] + [float(x) for x in ((order.get("degraus_atras") or {}).get(k) or [])]
        if (order.get("piso_atras") or {}).get(k):
            values.append(float(order["piso_atras"][k]))
        lowest = max(min(values), pace.limite_seguranca(cfg, k, d))
    target = ORD.alvo_da_ordem(ordens, k, d)
    po = ORD.piso_da_ordem(ordens, k, d)
    return {"frozen": ORD.congelada(ordens, k, d) is not None, "owner_price": bool(ORD.ordens_por_data(ordens, k, d)),
            "floor_order_ref": po[1]["id"] if po else None,
            "ladder_pin": bool(ladder_on(cfg) and target and target.get("escada_vendas")),
            "floor": {"value": float(floor), "from_order": bool(order)}, "lowest_allowed": float(lowest),
            "switch_floor": float(pace.piso_duro_do_plano(cfg, k, d)[0]),
            "active_orders": [PLAN_ID] if pace.ordem_do_plano(ordens, k, d) else [],
            "governs": bool(PD.governa_noite(k, n, cfg))}


def between_hints(cfg: dict, cals: dict, ordens: dict, hoje: dt.date, ancora, k: str, n: str) -> dict:
    """pace.coerencia, middle room: its floor (lowered by the ladder order and by the lead-time market floor of the
    plan) and the ladder's hints. The engine computes this inline; the decomposition keeps the generic rule
    "cite the lowering order when it decided the lower edge by more than 0.5"."""
    esc = pace.par_pace(cfg)["escada_vendas"]
    d = D(n)
    days = (d - hoje).days
    target = ORD.alvo_da_ordem(ordens, k, d)
    ladder = ladder_on(cfg) and bool(target) and bool(target.get("escada_vendas"))
    occ, strong, sold, total = 0.0, False, 0, 0
    if target:
        units = cfg["por_curto"][k]["unidades"]
        sold, _ = comum.vendidas_total(cals[k][n], units)
        total = units
        prop = pace.ocupacao_pousada(cfg, cals, n)[cfg["por_curto"][k]["pousada"]]
        if units <= 2 and prop[1]:
            sold, total = prop
        occ = (sold / total) if total else 0.0
        status = pace.status_da_noite(cfg, tipo_noite(cfg, d), days, sold, total)[0]
        strong = not pace.noite_fraca(target, status, sold, total)
        if not ladder and strong:
            target = None
    floor, _, _ = pace.piso_com_ordem(cfg, k, d, ordens if target else pace.sem_alvo(ordens))
    if target and ladder:
        base = ORD.vendidas_base_escada(ordens, k, d)
        behind = pace.piso_atras_da_ordem(cfg, target, k, d, days, sold, base[0] if base else None)
        if behind is not None:
            floor = min(floor, behind)
        _, sold_expected, expected = pace.status_da_noite(cfg, tipo_noite(cfg, d), days, sold, total)
        step = pace.degrau_atras_da_ordem(cfg, target, k, d, days, sold_expected, expected)
        if step is not None:
            floor = min(floor, step[0])
            strong = False
        else:
            limit = pace.limite_volta_degrau(cfg, target, k, d, days, float(cals[k][n]["price"]), floor)
            if limit is not None:
                floor = limit
    market_floor = pace.piso_semana_mercado(cfg, k, d, days, ordens)
    standard = floor
    if market_floor and market_floor[0] < floor:
        floor = market_floor[0]
    hints = {"own_floor": float(floor), "standard_floor": float(standard),
             "lowering_order_ref": PLAN_ID if market_floor else None, "own_target_ceiling": None,
             "hold_current": bool(ladder and strong)}
    if target and ancora:
        anchor, _ = ancora_da_noite(ancora, cfg, d)
        if anchor:
            pct = float(target.get("percentil", 50))
            if ladder:
                occupancy = occ
                base = ORD.vendidas_base_escada(ordens, k, d)
                if base and total:
                    occupancy = max(0, sold - base[0]) / max(1, total - base[0])
                pct = pace.percentil_com_vendas(pct, occupancy, float(esc.get("p_topo", 90)))
            hints["own_target_ceiling"] = valor_percentil(anchor, pct) / pace.fator_publico(cfg, cfg["por_curto"][k]["pousada"])
    return hints


def sent_band(cfg: dict, n: str):
    band = OR.faixa_fds_afrodite(cfg, D(n))
    return [float(band[0]), float(band[1])] if band else None


def coherence_hooks(cfg: dict, cals: dict, ordens: dict, hoje: dt.date, ancora, nights: list) -> dict:
    out = {}
    for k in ROOMS:
        for n in nights:
            if n not in (cals.get(k) or {}):
                continue
            h = floor_hooks(cfg, ordens, k, n)
            if k == "Q2":
                h["between"] = between_hints(cfg, cals, ordens, hoje, ancora, k, n)
            if k == "Afrodite":
                h["band"] = sent_band(cfg, n)
            if k == "Balcony":
                rn = PD.noite_regua(n)
                h["market_sent"] = num(PD.balcony_mercado_enviado(rn, rn.get("tipo", "semana"))) if rn else None
            out[f"{k}|{n}"] = h
    return out


def audit_hooks(cfg: dict, k: str, n: str, hoje) -> dict:
    """calendario_12m.exigencias (rule floors), safety exceptions, the ladder's view and the band of a room."""
    d = D(n)
    q = cfg["por_curto"][k]
    owner = rule_floor(cfg, q, d, None)
    today = rule_floor(cfg, q, d, hoje) if hoje else owner
    h = {"rule_floor_owner": owner, "rule_floor_today": today, "governs": bool(PD.governa_noite(k, n, cfg)),
         "band": sent_band(cfg, n) if k == "Afrodite" else None, "safety_exception": None,
         # ordem_recanto.montar_plano cites the plan's order whenever the middle room goes below the owner's floor
         "between": {"own_floor": today or 0.0, "standard_floor": owner or 0.0, "lowering_order_ref": PLAN_ID,
                     "own_target_ceiling": None, "hold_current": False}}
    for ex in cfg["limites_seguranca"].get("excecoes_dono", []) or []:
        if ex.get("quarto") == k and ex["de"] <= n <= ex["ate"]:
            h["safety_exception"] = float(ex["limite"])
    return h


def rule_floor(cfg: dict, q: dict, d: dt.date, hoje):
    from calendario_12m import exigencias
    v = exigencias(cfg, q, d, hoje)["piso"]
    return None if v is None else float(v)
PLANO_ID = PLAN_ID
ORDENS_FROZEN = ORDERS_FROZEN


# ---------------------------------------------------------------- pace.coerencia (coherence stage)
def engine_nights(cals: dict, finais: dict | None, filtro, hoje: str, dias: int) -> list[str]:
    """The night set of pace.coerencia: touched nights plus the horizon of the band's room (or the light filter)."""
    nights = {n for (_, n) in (finais or {})}
    if filtro is not None:
        return sorted(nights | {n for (_, n) in filtro})
    return sorted(nights | {n for n in (cals.get("Balcony") or {}) if 0 <= (D(n) - D(hoje)).days <= dias})


def anc_noite(n, p25=286, p50=388, p75=500, p90=579, mx=724, tipo="semana", busca="2027-02-25", n_=19):
    return {"noites": {n: {"p25": p25, "p50": p50, "p75": p75, "p90": p90, "max": mx, "n": n_, "busca_em": busca,
                           "tipo": tipo, "p50_medido": True}}, "por_tipo": {}}


def coherence_cases() -> None:
    casos = []

    def add(nome, hoje, cals, finais=None, itens=None, ords=SEM_ORDENS, bloqueios=None, dias=60, ancora=None,
            filtro=None, plano_finais=None, patch=None, nota=None):
        c = cfg_com(patch)
        decided = dict(finais or {})
        items = copy.deepcopy(itens or [])
        nights = engine_nights(cals, finais, filtro, hoje, dias)
        with PD.com_ordens(ords):
            out = pace.coerencia(c, copy.deepcopy(cals), decided, items, D(hoje), ords,
                                 bloqueios=set(bloqueios or ()) or None, dias=dias, ancora=ancora,
                                 noites_filtro=(set(filtro) if filtro is not None else None),
                                 plano_finais=dict(plano_finais or {}) or None)
            hooks = coherence_hooks(c, cals, ords, D(hoje), ancora, nights)
        rooms_patch, rels_patch = patches_of(patch)
        inp = {"today": hoje, "scan_days": dias, "calendar": calendar(c, cals), "decided": keyed(finais or {}),
               "strategy_decided": keyed(plano_finais or {}),
               "light_filter": key_list(filtro) if filtro is not None else None, "blocked": key_list(bloqueios),
               "draft": [draft_item(it) for it in itens or []], "nights": nights,
               "night_types": {n: night_type(c, n) for n in nights}, "hooks": hooks,
               "band_last_night": OR.horizonte_teto_fds(c, D(hoje)).isoformat()}
        if rooms_patch:
            inp["rooms_patch"] = rooms_patch
        if rels_patch:
            inp["relations_patch"] = rels_patch
        if nota:
            inp["note"] = nota
        casos.append({"name": nome, "input": inp,
                      "expected": {"items": [result_item(it) for it in out], "decided": keyed(decided)}})
    H = "2027-03-01"            # Monday, outside the plan period
    WD = "2027-03-02"           # Tuesday
    FS = "2027-03-06"           # Saturday, inside the 95-day horizon of the weekend band
    FE = "2027-03-26"           # Friday, holiday (Semana Santa)

    def villa(n, p7=1000, p2=1100, pb=700, v7=1, v2=0, vb=0, bmn="=", bmx="="):
        bmn = pb if bmn == "=" else bmn
        bmx = pb if bmx == "=" else bmx
        return {"VK7": {n: row(n, p7, v7, 7)}, "VK2": {n: row(n, p2, v2, 2)}, "Balcony": {n: row(n, pb, vb, 6, bmn, bmx)}}

    # ---- discount band (Regra G): position of the room against the band of the cheapest free reference
    for pb in (700, 854, 855, 868, 869, 876.3, 889, 900, 913, 913.7, 914, 1000, 1001, 1200):
        add(f"band_weekday_balcony_{str(pb).replace('.', '_')}_ref_1000", H, villa(WD, pb=pb))
    add("band_weekday_ref_is_vk2_cheaper", H, villa(WD, p7=1300, p2=1100, pb=700))
    add("band_weekday_tie_refs", H, villa(WD, p7=1000, p2=1000, pb=1000))
    add("band_weekday_cheapest_ref_sold_out_uses_other", H, villa(WD, p7=1300, p2=1000, v2=2, pb=950))
    add("band_weekday_expensive_ref_sold_out", H, villa(WD, p7=1000, p2=1300, v2=2, pb=950))
    add("band_weekday_vk7_sold_out_uses_vk2", H, villa(WD, p7=1000, p2=1300, v7=7, pb=950))
    add("band_weekday_set_all_sold_out_no_action", H, villa(WD, v7=7, v2=2, pb=1400))
    add("band_weekday_set_overbooked_no_action", H, villa(WD, v7=8, v2=3, pb=1400))
    add("band_weekday_target_sold_out_no_action", H, villa(WD, pb=1500, vb=6))
    add("band_weekday_target_missing_floor_ceiling", H, villa(WD, pb=950, bmn=None, bmx=None))
    add("band_weekday_ref_calendar_missing", H, {k: v for k, v in villa(WD, pb=950).items() if k != "VK2"})
    add("band_weekday_no_ref_calendars", H, {"Balcony": villa(WD, pb=950)["Balcony"]})
    add("band_d0_today_is_raised", "2027-03-02", villa(WD, pb=700),
        nota="coerencia has no 'today never rises' guard: the room is raised on D0")
    add("band_weekday_decided_ref_price_from_engine", H, villa(WD, p7=1000, pb=880), finais={("VK7", WD): 902.8})
    add("band_weekday_target_blocked", H, villa(WD, pb=700), bloqueios={("Balcony", WD)})
    add("band_weekday_cheapest_ref_blocked", H, villa(WD, p7=1000, p2=1300, pb=880), bloqueios={("VK7", WD)})
    add("band_weekday_all_refs_blocked", H, villa(WD, pb=700), bloqueios={("VK7", WD), ("VK2", WD)})
    add("band_weekday_target_frozen_manual", H, villa(WD, pb=700),
        ords={"m1": ordem("m1", "manual", ["Balcony"], WD, WD)})
    add("band_weekday_target_owner_price_by_date", H, villa(WD, pb=700),
        ords={"p1": ordem("p1", "preco_por_data", ["Balcony"], WD, WD, libera=["fixo", "regra_g"])})
    add("band_weekday_ref_frozen_still_reference", H, villa(WD, pb=700),
        ords={"m2": ordem("m2", "manual", ["VK7"], WD, WD)})
    # floors of the target against the band
    add("band_floor_above_upper_bound_floor_wins", H, villa(WD, p7=750, p2=800, pb=640))
    add("band_floor_above_reference_floor_wins", H, villa(WD, p7=690, p2=800, pb=640))
    add("band_floor_order_inside_band_raises_low_edge", H, villa(WD, pb=700),
        ords={"f1": ordem("f1", "piso_por_data", ["Balcony"], WD, WD, pisos_enviados={"Balcony": 885},
                          libera=["abaixo_piso"])})
    add("band_floor_order_above_upper_without_release_uses_standard_floor", H, villa(WD, pb=1000),
        ords={"f2": ordem("f2", "piso_por_data", ["Balcony"], WD, WD, pisos_enviados={"Balcony": 950})})
    add("band_floor_order_above_upper_with_release_uses_lowest_step", H, villa(WD, pb=1000),
        ords={"f3": ordem("f3", "alvo_por_data", ["Balcony"], WD, WD, pisos_enviados={"Balcony": 950}, percentil=50,
                          libera=["abaixo_piso"], degraus_atras={"Balcony": [930, 880, 660, 640]},
                          piso_atras={"Balcony": 600})})
    add("band_floor_order_below_listing_min_with_release", H, villa(WD, p7=740, p2=800, pb=700),
        ords={"f4": ordem("f4", "piso_por_data", ["Balcony"], WD, WD, pisos_enviados={"Balcony": 640},
                          libera=["abaixo_piso"])})
    # existing draft item of the target (pace step of the same run)
    pace_it = lambda mn, mx=None, oid=None: [dict(  # noqa: E731
        {"tipo": "data", "quarto": "Balcony", "data": WD, "min_price": mn, "preco_antes": 950.0, "motivo": "Pace (teste)",
         "pace": {"status": "atras", "direcao": "desce", "atual": 950.0, "novo": mn}},
        **({"max_price": mx} if mx is not None else {}), **({"excecao": "dono", "ordem_id": oid} if oid else {}))]
    add("band_existing_item_inside_band", H, villa(WD, pb=950), itens=pace_it(880), finais={("Balcony", WD): 889.0})
    add("band_existing_item_below_band", H, villa(WD, pb=950), itens=pace_it(800, 800), finais={("Balcony", WD): 800.1})
    add("band_existing_item_above_band", H, villa(WD, pb=950), itens=pace_it(930), finais={("Balcony", WD): 939.8})
    add("band_existing_item_keeps_its_order_id", H, villa(WD, pb=950), itens=pace_it(800, 800, "f1"),
        finais={("Balcony", WD): 800.1},
        ords={"f1": ordem("f1", "piso_por_data", ["Balcony"], WD, WD, pisos_enviados={"Balcony": 700}, libera=["abaixo_piso"])})
    # holiday: only lowers, never below the holiday floor of the target
    add("band_holiday_above_90pct_lowered", H, villa(FE, p7=3000, p2=2578.1, pb=2425.7))
    add("band_holiday_below_90pct_not_raised", H, villa(FE, p7=3000, p2=2578.1, pb=2000))
    add("band_holiday_within_tolerance_no_action", H, villa(FE, p7=3000, p2=2578.1, pb=2333))
    add("band_holiday_target_below_its_holiday_floor_not_raised", H, villa(FE, p7=1600, p2=1700, pb=1650),
        nota="holiday floor of the target is 1000 / 0.53 = 1886.79 sent; the band never raises on a holiday")
    add("band_holiday_90pct_below_holiday_floor_lowered_only_to_floor", H, villa(FE, p7=2000, p2=2100, pb=1950),
        nota="90% of the reference (1800) is below the holiday floor (1886.79): the floor wins, 1892.30")
    add("band_holiday_already_at_holiday_floor_no_action", H, villa(FE, p7=2000, p2=2100, pb=1892.3))
    add("band_holiday_target_above_reference_lowered", H, villa(FE, p7=3000, p2=2578.1, pb=2700))
    add("band_holiday_set_all_sold_out_no_action", H, villa(FE, p7=3000, p2=2578.1, v7=7, v2=2, pb=2900))
    add("band_weekend_target_far_below_raised", H, villa(FS, p7=2400, p2=2600, pb=1500))
    add("band_weekend_target_above_lowered", H, villa(FS, p7=2400, p2=2600, pb=2450))
    add("band_holiday_existing_item_below_90pct_kept", H, villa(FE, p7=3000, p2=2578.1, pb=2425.7),
        itens=[{"tipo": "data", "quarto": "Balcony", "data": FE, "min_price": 2200, "preco_antes": 2425.7, "motivo": "Pace (teste)"}],
        finais={("Balcony", FE): 2209.8})
    add("band_holiday_existing_item_above_90pct_lowered", H, villa(FE, p7=3000, p2=2578.1, pb=2425.7),
        itens=[{"tipo": "data", "quarto": "Balcony", "data": FE, "min_price": 2400, "preco_antes": 2425.7, "motivo": "Pace (teste)"}],
        finais={("Balcony", FE): 2400.3})
    # configuration variations
    add("band_patched_discount_15pct", H, villa(WD, pb=950), patch={"parametros.regra_g_desconto": 0.15})
    add("band_patched_discount_key_absent_defaults_10pct", H, villa(WD, pb=950),
        patch={"parametros.regra_g_desconto": 0.10}, nota="same as default; documents the 0.10 fallback")
    add("band_patched_target_base_1000", H, villa(WD, pb=950), patch={"por_curto.Balcony.base": 1000})
    # horizon and light-run filter
    far = "2027-05-01"          # D61 from 2027-03-01
    add("band_night_beyond_horizon_not_scanned", H, villa(far, pb=700), dias=60)
    add("band_night_on_horizon_edge_scanned", H, villa("2027-04-30", pb=700), dias=60)
    add("band_night_beyond_horizon_scanned_when_engine_touched_it", H, villa(far, pb=700), dias=60,
        finais={("VK7", far): 1006.4})
    dois = villa(WD, pb=700)
    for k, v in villa("2027-03-03", pb=700).items():
        dois[k].update(v)
    add("band_two_nights_full_scan", H, dois)
    add("band_two_nights_light_run_filter_one_night", H, dois, filtro={("VK7", "2027-03-03")})
    add("band_light_run_empty_filter_nothing", H, dois, filtro=set())

    # ---- occupancy switch (80% rule) inside the plan period, ruler night available
    T2, N2 = "2026-12-01", "2026-12-12"      # Saturday, weekend ruler night

    def v2(n, s7, s2, pb=2000, p7=2400, p2=2400, vb=0, bmn=None, bmx=None):
        return {"VK7": {n: row(n, p7, s7, 7)}, "VK2": {n: row(n, p2, s2, 2)}, "Balcony": {n: row(n, pb, vb, 6, bmn, bmx)}}

    for s7, s2 in ((5, 2), (6, 1), (7, 0), (6, 2), (7, 1), (7, 2), (8, 2), (0, 0)):
        add(f"switch_weekend_{s7}_of_7_and_{s2}_of_2_sold", T2, v2(N2, s7, s2), ords=PLANO)
    add("switch_weekend_8_of_9_without_plan_order_band_applies", T2, v2(N2, 6, 2), ords=SEM_ORDENS)
    add("switch_weekend_already_at_market_no_item", T2, v2(N2, 7, 2, pb=1041.4, bmn=1041.4, bmx=1041.4), ords=PLANO)
    add("switch_weekend_within_tolerance_no_item", T2, v2(N2, 7, 2, pb=1050, bmn=1050, bmx=1050), ords=PLANO)
    add("switch_weekend_price_ok_but_no_ceiling_in_force", T2, v2(N2, 7, 2, pb=1041.4, bmn=1041.4), ords=PLANO)
    add("switch_weekend_price_ok_but_floor_far", T2, v2(N2, 7, 2, pb=1041.4, bmn=900, bmx=1041.4), ords=PLANO)
    add("switch_weekend_capped_at_90pct_of_free_ref", T2, v2(N2, 6, 2, p7=1100), ords=PLANO)
    add("switch_weekend_cap_below_safety_floor_floor_wins", T2, v2(N2, 6, 2, p7=650), ords=PLANO)
    add("switch_weekend_target_frozen_rule_applies_nothing_written", T2, v2(N2, 7, 2),
        ords=dict(PLANO, m1=ordem("m1", "manual", ["Balcony"], N2, N2)))
    add("switch_weekend_target_owner_price_by_date", T2, v2(N2, 7, 2),
        ords=dict(PLANO, p1=ordem("p1", "preco_por_data", ["Balcony"], N2, N2, libera=["fixo"])))
    add("switch_weekend_target_sold_out", T2, v2(N2, 7, 2, vb=6), ords=PLANO)
    add("switch_weekend_target_blocked", T2, v2(N2, 7, 2), ords=PLANO, bloqueios={("Balcony", N2)})
    add("switch_weekend_ref_blocked_counts_as_not_free", T2, v2(N2, 6, 2, p7=1100), ords=PLANO, bloqueios={("VK7", N2)})
    add("switch_weekend_one_ref_calendar_missing_7_of_9", T2,
        {k: v for k, v in v2(N2, 7, 2).items() if k != "VK2"}, ords=PLANO)
    add("switch_weekend_existing_pace_item_replaced", T2, v2(N2, 7, 2),
        itens=[{"tipo": "data", "quarto": "Balcony", "data": N2, "min_price": 1900, "preco_antes": 2000.0,
                "motivo": "Pace (teste)", "pace": {"status": "atras", "direcao": "desce", "atual": 2000.0, "novo": 1900}}],
        finais={("Balcony", N2): 1905.0}, ords=PLANO)
    for nome, n, hoje in (("weekday_tue", "2026-11-17", "2026-11-11"), ("holiday_fri", "2026-11-20", "2026-11-11"),
                          ("natal", "2026-12-24", "2026-12-01"), ("between_holidays_sun", "2026-12-27", "2026-12-01"),
                          ("reveillon", "2026-12-31", "2026-12-01"), ("last_night_of_period", "2027-02-28", "2027-02-01"),
                          ("carnaval_sat", "2027-02-06", "2027-02-01")):
        add(f"switch_{nome}_8_of_9", hoje, v2(n, 6, 2, pb=2500, p7=3000, p2=3000), ords=PLANO)
        add(f"switch_{nome}_9_of_9", hoje, v2(n, 7, 2, pb=2500, p7=3000, p2=3000), ords=PLANO)
    add("switch_first_night_after_period_no_ruler_band_applies", "2027-02-20", v2("2027-03-01", 6, 2, pb=2500, p7=3000, p2=3000),
        ords=PLANO)
    add("switch_patched_discount_changes_cap", T2, v2(N2, 6, 2, p7=1100), ords=PLANO,
        patch={"parametros.regra_g_desconto": 0.2})

    # ---- plan nights: reference room priced by the plan (plano_finais)
    add("plan_villa_ref_from_plan_target_goes_to_upper_edge", "2026-11-11", v2("2026-11-14", 4, 2, pb=2464, p7=2856, p2=2423),
        ords=PLANO, plano_finais={("VK7", "2026-11-14"): 1880.6, ("VK2", "2026-11-14"): 1905.0})
    add("plan_villa_pace_item_of_target_removed", "2026-11-11", v2("2026-11-14", 4, 1, pb=2464, p7=2856, p2=2423),
        ords=PLANO, plano_finais={("VK7", "2026-11-14"): 1880.6, ("VK2", "2026-11-14"): 1905.0},
        itens=[{"tipo": "data", "quarto": "Balcony", "data": "2026-11-14", "min_price": 2218, "preco_antes": 2464.0,
                "motivo": "Pace (teste)", "pace": {"status": "atras", "direcao": "desce", "atual": 2464.0, "novo": 2218}}],
        finais={("Balcony", "2026-11-14"): 2222.5})
    add("plan_villa_plan_price_ignored_outside_light_filter", "2026-11-11", v2("2026-11-14", 4, 1, pb=2464, p7=2856, p2=2423),
        ords=PLANO, plano_finais={("VK7", "2026-11-14"): 1880.6, ("VK2", "2026-11-14"): 1905.0},
        filtro={("Balcony", "2026-11-14")})
    add("plan_villa_plan_price_used_inside_light_filter", "2026-11-11", v2("2026-11-14", 4, 1, pb=2464, p7=2856, p2=2423),
        ords=PLANO, plano_finais={("VK7", "2026-11-14"): 1880.6, ("VK2", "2026-11-14"): 1905.0},
        filtro={("VK7", "2026-11-14"), ("VK2", "2026-11-14")})

    # ---- Recanto chain: Double >= Q7 (unless last units), Q2 between, Afrodite premium over the max
    def rec(n, a, b, c, f, va=1, vb=1, vc=0, vf=0, extra=None):
        r = {"Q7": {n: row(n, a, va, 7)}, "Double": {n: row(n, b, vb, 6)}, "Q2": {n: row(n, c, vc, 2)},
             "Afrodite": {n: row(n, f, vf, 1)}}
        for k, campos in (extra or {}).items():
            r[k][n].update(campos)
        return r

    T = lambda n, k="Q7", v=None, cals=None: {(k, n): float(v if v is not None else cals[k][n]["price"])}  # noqa: E731

    # the chain only runs on nights the engine touched (finais) and that are not plan nights
    c0 = rec(WD, 1300, 1400, 1700, 1500)
    add("chain_not_touched_by_engine_nothing", H, c0)
    add("chain_touched_q2_above_and_top_below", H, c0, finais=T(WD, cals=c0))
    add("chain_touched_only_top_suite_in_finais", H, c0, finais={("Afrodite", WD): 1500.0})
    # middle room positions (band = [low ref, low + 75% of the gap]; trigger tolerance of 1 currency unit)
    for nome, q2 in (("below_low_minus_1", 1299), ("below_low_minus_1_01", 1298.99), ("at_low", 1300), ("mid", 1350),
                     ("at_ceiling", 1375), ("at_ceiling_plus_1", 1376), ("at_ceiling_plus_1_01", 1376.01),
                     ("at_high_ref", 1400), ("far_above", 1900), ("far_below", 1000)):
        cx = rec(WD, 1300, 1400, q2, 2500)
        add(f"chain_q2_{nome}", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 2000, 2000, 2400, 2500)
    add("chain_q2_refs_tie_rounding_inverts_order", H, cx, finais=T(WD, cals=cx),
        nota="Q2 floor 2000 becomes 2003.40 effective (26% of its base), above the 2000 of the reference room")
    cx = rec(WD, 1500, 1400, 1450, 2500)
    add("chain_double_below_q7_raised_then_q2_inverts_by_rounding", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1401, 1400, 1400, 2500)
    add("chain_double_below_q7_by_exactly_1_not_raised", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1401.01, 1400, 1400, 2500)
    add("chain_double_below_q7_by_1_01_raised", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1500, 1400, 1450, 2500, va=6)
    add("chain_double_below_q7_last_unit_not_raised", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1500, 1400, 1450, 2500, va=5)
    add("chain_double_below_q7_two_units_left_raised", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1500, 1400, 1450, 2500, vb=6)
    add("chain_double_sold_out_q2_pinned_to_q7", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1500, 1400, 1650, 2500, va=7)
    add("chain_q7_sold_out_q2_pinned_to_double", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1500, 1400, 1650, 1500, va=7, vb=6)
    add("chain_both_refs_sold_out_q2_free_top_uses_q2", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1500, 1400, 1650, 1500, va=7, vb=6, vc=2)
    add("chain_all_others_sold_out_top_untouched", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1300, 1400, 1900, 1500, vc=2)
    add("chain_q2_sold_out_skipped_top_uses_free_ones", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 1300, 1400, 1350, 1500, vf=1)
    add("chain_top_sold_out_skipped", H, cx, finais=T(WD, cals=cx))
    cx = rec(WD, 900, 960, 1300, 2500)
    add("chain_q2_hard_floor_above_band_floor_wins", H, cx, finais=T(WD, cals=cx),
        nota="owner weekday floor of the middle room (1219.51) is above the reference rooms: floor = ceiling = 1220")
    cx = rec(FS, 1800, 1900, 2100, 2700)
    add("chain_weekend_q2_hard_floor_above_band", H, cx, finais=T(FS, cals=cx))
    cx = rec(WD, 1500, 1400, 1450, 2500)
    add("chain_double_frozen_not_raised_q2_uses_its_price", H, cx, finais=T(WD, cals=cx),
        ords={"m1": ordem("m1", "manual", ["Double"], WD, WD)})
    add("chain_double_owner_price_by_date_not_raised", H, cx, finais=T(WD, cals=cx),
        ords={"p1": ordem("p1", "preco_por_data", ["Double"], WD, WD, libera=["fixo"])})
    cx = rec(WD, 1300, 1400, 1900, 1500)
    add("chain_q2_frozen_top_uses_its_current_price", H, cx, finais=T(WD, cals=cx),
        ords={"m1": ordem("m1", "manual", ["Q2"], WD, WD)})
    add("chain_top_frozen_untouched", H, cx, finais=T(WD, cals=cx),
        ords={"m1": ordem("m1", "manual", ["Afrodite"], WD, WD)})
    cx = rec(WD, 1300, 1400, 1700, 1500)
    add("chain_low_ref_blocked_is_still_a_reference_in_this_stage", H, cx, finais=T(WD, cals=cx), bloqueios={("Q7", WD)},
        nota="the engine's chain does not look at blocked nights in the coherence stage (relations.blocked_nights = ignore)")
    add("chain_target_blocked_is_still_adjusted_in_this_stage", H, cx, finais=T(WD, cals=cx), bloqueios={("Q2", WD)})
    # existing draft items of the targets
    cx = rec(WD, 1500, 1400, 1450, 2500)
    add("chain_double_existing_item_floor_raised_ceiling_follows", H, cx,
        finais={("Q7", WD): 1500.0, ("Double", WD): 1361.6},
        itens=[{"tipo": "data", "quarto": "Double", "data": WD, "min_price": 1350, "max_price": 1350, "preco_antes": 1400.0,
                "motivo": "Pace (teste)"}])
    add("chain_double_existing_item_higher_floor_kept", H, cx,
        finais={("Q7", WD): 1500.0, ("Double", WD): 1613.2},
        itens=[{"tipo": "data", "quarto": "Double", "data": WD, "min_price": 1600, "preco_antes": 1400.0, "motivo": "Pace (teste)"}])
    cx = rec(WD, 1300, 1400, 1350, 2500)
    add("chain_q2_existing_item_gets_ceiling_even_inside_band", H, cx,
        finais={("Q7", WD): 1300.0, ("Q2", WD): 1351.5},
        itens=[{"tipo": "data", "quarto": "Q2", "data": WD, "min_price": 1340, "preco_antes": 1350.0, "motivo": "Pace (teste)"}])
    add("chain_q2_existing_item_only_ceiling_gets_floor_from_calendar", H, rec(WD, 1300, 1400, 1350, 2500, extra={"Q2": {"min_price": "1310"}}),
        finais={("Q7", WD): 1300.0},
        itens=[{"tipo": "data", "quarto": "Q2", "data": WD, "max_price": 1500, "preco_antes": 1350.0, "motivo": "teto (teste)"}])
    cx = rec(WD, 1300, 1400, 1350, 1500)
    add("chain_top_existing_item_lower_floor_raised", H, cx, finais={("Q7", WD): 1300.0, ("Afrodite", WD): 1600.0},
        itens=[{"tipo": "data", "quarto": "Afrodite", "data": WD, "min_price": 1600, "preco_antes": 1500.0, "motivo": "Pace (teste)"}])
    add("chain_top_existing_item_higher_floor_kept", H, cx, finais={("Q7", WD): 1300.0, ("Afrodite", WD): 1800.0},
        itens=[{"tipo": "data", "quarto": "Afrodite", "data": WD, "min_price": 1800, "max_price": 1790, "preco_antes": 1500.0,
                "motivo": "Pace (teste)"}])
    # top suite: premium by night type, trigger tolerance, weekend band ceiling and horizon
    for nome, af in (("at_floor_minus_1", 1679.5), ("below_floor_minus_1", 1679.49), ("at_premium", 1680), ("above", 1900),
                     ("far_below", 1200)):
        cx = rec(WD, 1300, 1400, 1350, af)
        add(f"chain_top_weekday_20pct_{nome}", H, cx, finais=T(WD, cals=cx))
    for nome, af in (("below_premium_gets_band_ceiling", 2100), ("inside", 2400), ("above_band_top_plus_1", 2859),
                     ("at_band_top_plus_1", 2858)):
        cx = rec(FS, 2000, 2100, 2050, af)
        add(f"chain_top_weekend_5pct_{nome}", H, cx, finais=T(FS, cals=cx))
    cx = rec(FS, 2000, 2900, 2050, 2100)
    add("chain_top_weekend_premium_floor_beats_band_ceiling", H, cx, finais=T(FS, cals=cx))
    cx = rec("2027-06-05", 2000, 2100, 2050, 3500)
    add("chain_top_weekend_beyond_band_horizon_no_ceiling", H, cx, finais=T("2027-06-05", cals=cx), dias=120)
    cx = rec("2027-06-04", 2000, 2100, 2050, 3500)
    add("chain_top_weekend_last_day_of_band_horizon", H, cx, finais=T("2027-06-04", cals=cx), dias=120)
    cx = rec(FE, 2500, 3000, 2700, 3100)
    add("chain_top_holiday_5pct_below", H, cx, finais=T(FE, cals=cx))
    cx = rec(FE, 2500, 3000, 3400, 6000)
    add("chain_holiday_q2_above_band_top_suite_ok", H, cx, finais=T(FE, cals=cx))
    cx = rec("2027-03-28", 1300, 1400, 1350, 1500)
    add("chain_top_sunday_after_holiday_20pct", H, cx, finais=T("2027-03-28", cals=cx))
    cx = rec(WD, 1300, 1400, 1350, 1500)
    add("chain_patched_premium_weekday_30pct", H, cx, finais=T(WD, cals=cx),
        patch={"por_curto.Afrodite.premio_minimo_semana": 0.30})
    # owner orders on the middle room
    cx = rec(WD, 900, 960, 1300, 2500)
    add("chain_q2_floor_order_by_date_lowers_floor_and_stamps_order", H, cx, finais=T(WD, cals=cx),
        ords={"f1": ordem("f1", "piso_por_data", ["Q2"], WD, WD, pisos_enviados={"Q2": 747}, libera=["fixo", "abaixo_piso"])})
    add("chain_q2_floor_order_below_safety_limit_bounded", H, cx, finais=T(WD, cals=cx),
        ords={"f1": ordem("f1", "piso_por_data", ["Q2"], WD, WD, pisos_enviados={"Q2": 400}, libera=["fixo", "abaixo_piso"])})
    alvo = lambda **kw: {"a1": ordem("a1", "alvo_por_data", ["Q7", "Double", "Q2"], WD, WD, noites=[WD], percentil=50,  # noqa: E731
                                     pisos_enviados={"Q7": 560, "Double": 630, "Q2": 595}, libera=["abaixo_piso"], **kw)}
    cx = rec(WD, 620, 700, 1300, 2500, va=0, vb=0)
    add("chain_q2_target_order_weak_night_ceiling_capped_by_market_base", H, cx, finais=T(WD, cals=cx),
        ords=alvo(), ancora=anc_noite(WD))
    add("chain_q2_target_order_without_anchor", H, cx, finais=T(WD, cals=cx), ords=alvo())
    cx = rec(WD, 620, 700, 1300, 2500, va=6, vb=6, vc=1)
    add("chain_q2_target_order_strong_night_keeps_standard_floor", H, cx, finais=T(WD, cals=cx),
        ords=alvo(), ancora=anc_noite(WD),
        nota="property sold 13 of 16 (>= 70%): the target order does not apply to the middle room (standard floor 1219.51); "
             "one reference is sold out, so the band collapses on the free one; the item is still stamped with the order "
             "that carries a floor by date for the room")
    cx = rec(WD, 620, 700, 1300, 2500, va=5, vb=4, vc=1)
    add("chain_q2_ladder_order_strong_night_hold_current", H, cx, finais=T(WD, cals=cx),
        ords=alvo(escada_vendas=True), ancora=anc_noite(WD))
    cx = rec(WD, 620, 700, 1300, 2500, va=1, vb=0, vc=0)
    add("chain_q2_ladder_order_weak_night", H, cx, finais=T(WD, cals=cx),
        ords=alvo(escada_vendas=True), ancora=anc_noite(WD))
    cx = rec(WD, 1500, 1400, 1450, 2500)
    add("chain_double_ladder_order_raise_pins_ceiling", H, cx, finais=T(WD, cals=cx),
        ords={"a2": ordem("a2", "alvo_por_data", ["Double"], WD, WD, noites=[WD], percentil=50, escada_vendas=True,
                          pisos_enviados={"Double": 630}, libera=["abaixo_piso"])})
    # plan period
    PN = "2026-11-16"   # Monday, D5 from 2026-11-11: weekday market floor of the plan
    cx = rec(PN, 910, 955, 1300, 1500)
    add("plan_weekday_d5_market_floor_for_q2_with_plan_order", "2026-11-11", cx, finais=T(PN, cals=cx), ords=PLANO)
    add("plan_weekday_d5_without_plan_order_standard_floor", "2026-11-11", cx, finais=T(PN, cals=cx))
    cx = rec("2026-11-23", 910, 955, 1300, 1500)
    add("plan_weekday_d12_standard_floor", "2026-11-11", cx, finais=T("2026-11-23", cals=cx), ords=PLANO)
    cx = rec(PN, 880, 955, 1300, 1500)
    add("plan_weekday_d5_market_floor_above_low_ref", "2026-11-11", cx, finais=T(PN, cals=cx), ords=PLANO)
    cx = rec(PN, 1300, 1400, 1700, 1500)
    add("plan_weekday_d5_market_floor_not_deciding_keeps_standing_order", "2026-11-11", cx, finais=T(PN, cals=cx), ords=PLANO)
    PF = "2026-11-14"
    cx = rec(PF, 1100, 1500, 1700, 1500)
    add("plan_night_chain_skipped_when_any_room_is_plan_priced", "2026-11-11", cx, finais=T(PF, cals=cx), ords=PLANO,
        plano_finais={("Q7", PF): 1100.0})
    add("plan_period_weekend_without_plan_prices_chain_runs_no_band", "2026-11-11", cx, finais=T(PF, cals=cx), ords=PLANO)
    add("plan_period_weekend_without_plan_order_band_ceiling_back", "2026-11-11", cx, finais=T(PF, cals=cx))
    # everything together on one night and across nights
    tudo = rec(WD, 1500, 1400, 1900, 1500)
    tudo.update(villa(WD, pb=700))
    for k, v in rec(FS, 2000, 2100, 2400, 2100).items():
        tudo[k].update(v)
    for k, v in villa(FS, p7=2400, p2=2600, pb=2450).items():
        tudo[k].update(v)
    add("all_relations_two_nights", H, tudo, finais={("Q7", WD): 1500.0, ("Double", FS): 2101.6})
    add("all_relations_two_nights_light_filter_one_room", H, tudo, finais={("Q7", WD): 1500.0},
        filtro={("Q7", WD)})
    write_golden("pace.coerencia", "pace.coerencia", "Ritmo\\Domain\\Relations\\Coherence\\CoherencePass::run()",
                 "Coherence stage over the engine's night set. Input: calendar rows (price, sold, total, floor and ceiling "
                 "in force), decided and ladder prices, light filter, blocked nights, the draft items and the hook answers "
                 "of the other subsystems per room-night. Expected: every draft item after the pass, in order (room, night, "
                 "floor, ceiling, order_ref, price_before) and the decided prices. Amounts within 0.01.", casos)


# ---------------------------------------------------------------- ordem_recanto.faixa (audit of a between relation)
def between_audit_cases() -> None:
    casos = []

    def add(nome, s, q7, db, q2, q2_min=None, hoje=None, ords=SEM_ORDENS, patch=None):
        r2 = {"price": str(q2)}
        if q2_min is not None:
            r2["min_price"] = str(q2_min)
        c = cfg_com(patch)
        with PD.com_ordens(ords):
            f = OR.faixa(c, D(s), {"price": str(q7)}, {"price": str(db)}, r2, D(hoje) if hoje else None)
            hooks = {f"Q2|{s}": audit_hooks(c, "Q2", s, D(hoje) if hoje else None)}
        rooms_patch, _ = patches_of(patch)
        inp = {"night": s, "today": hoje, "hooks": hooks,
               "calendar": calendar(c, {"Q7": {s: {"price": str(q7)}}, "Double": {s: {"price": str(db)}}, "Q2": {s: r2}})}
        if rooms_patch:
            inp["rooms_patch"] = rooms_patch
        casos.append({"name": nome, "input": inp, "expected": {
            "floor": float(f["piso"]), "ceiling": float(f["teto"]), "out": f["fora"], "final": float(f["q2_final"]),
            "owner_floor": float(f["piso_dono"])}})

    # weekday outside the plan period: floor of the rule = owner's weekday floor (R$ 500 at the discounted price)
    wd = "2027-05-04"
    t = OR.tol(CFG, "Q2")     # 16.9
    for nome, q2 in (("inside_mid", 1260), ("inside_at_low", 1232), ("inside_at_high", 1302),
                     ("inside_low_minus_tol", 1232 - t), ("out_low_minus_tol_minus_cent", round(1232 - t - 0.01, 2)),
                     ("inside_high_plus_tol", 1302 + t), ("out_high_plus_tol_plus_cent", round(1302 + t + 0.01, 2)),
                     ("out_far_above", 1613), ("out_far_below", 900)):
        add(f"weekday_{nome}", wd, 1232, 1302, q2)
    add("weekday_inside_price_but_floor_in_force_above_high", wd, 1232, 1302, 1290, q2_min=1400)
    add("weekday_inside_price_floor_in_force_at_high_plus_tol", wd, 1232, 1302, 1290, q2_min=1302 + t)
    add("weekday_reversed_refs_q7_above_double", wd, 1500, 1300, 1700)
    add("weekday_reversed_refs_inside", wd, 1500, 1300, 1400)
    add("weekday_tie_refs_q2_above", wd, 1400, 1400, 1500)
    add("weekday_tie_refs_q2_equal", wd, 1400, 1400, 1400)
    add("weekday_tie_refs_q2_below", wd, 1400, 1400, 1300)
    add("weekday_rule_floor_above_both_refs", wd, 900, 960, 1300)       # owner floor 1220 > Double: floor wins
    add("weekday_rule_floor_between_refs", wd, 1100, 1400, 1700)
    add("weekday_half_values_bankers_rounding", wd, 1300.5, 1400.5, 1700)
    add("weekday_half_values_bankers_rounding_2", wd, 1301.5, 1402.5, 1700)
    # weekend outside the plan period: owner's weekend floor
    fs = "2027-05-08"
    add("weekend_out_above", fs, 2018, 2028, 2400)
    add("weekend_inside", fs, 2018, 2028, 2020)
    add("weekend_refs_below_rule_floor", fs, 1800, 1900, 2100)
    add("weekend_refs_far_apart", fs, 2100, 2900, 3200)
    add("weekend_q2_below", fs, 2100, 2900, 1900)
    # holiday (Semana Santa): holiday floor of the room
    fe = "2027-03-26"
    add("holiday_out_above", fe, 2500, 3000, 3400)
    add("holiday_inside", fe, 2500, 3000, 2700)
    add("holiday_refs_below_holiday_floor", fe, 2000, 2200, 2600)
    # plan period (order in the orders in use): weekday D7..D0 uses the market floor when `hoje` is given
    for nome, s, hoje in (("d5_with_today", "2026-11-16", "2026-11-11"), ("d5_without_today", "2026-11-16", None),
                          ("d12_with_today", "2026-11-23", "2026-11-11"), ("d7_with_today", "2026-11-18", "2026-11-11"),
                          ("d8_with_today", "2026-11-19", "2026-11-11"), ("d0_with_today", "2026-11-11", "2026-11-11")):
        add(f"plan_weekday_{nome}_q2_reopened_high", s, 905, 950, 1300, hoje=hoje, ords=PLANO)
        add(f"plan_weekday_{nome}_q2_inside", s, 905, 950, 930, hoje=hoje, ords=PLANO)
    add("plan_weekday_d5_without_plan_order", "2026-11-16", 905, 950, 1300, hoje="2026-11-11", ords=SEM_ORDENS)
    add("plan_weekend_fri_out_above", "2026-11-13", 1100, 1500, 1700, hoje="2026-11-11", ords=PLANO)
    add("plan_weekend_fri_without_plan_order", "2026-11-13", 1100, 1500, 1700, hoje="2026-11-11", ords=SEM_ORDENS)
    add("plan_holiday_natal_out_above", "2026-12-24", 2400, 2700, 3100, hoje="2026-12-01", ords=PLANO)
    add("plan_holiday_natal_without_plan_order", "2026-12-24", 2400, 2700, 3100, hoje="2026-12-01", ords=SEM_ORDENS)
    add("plan_between_holidays_sun", "2026-12-27", 1400, 1700, 1900, hoje="2026-12-20", ords=PLANO)
    # another base for the middle room changes the tolerance (1% of base + 1)
    add("patched_q2_base_1352_tolerance", wd, 1232, 1302, 1317, patch={"por_curto.Q2.base": 1352})
    add("patched_q2_base_1352_out", wd, 1232, 1302, 1317.53, patch={"por_curto.Q2.base": 1352})
    write_golden("ordem_recanto.faixa", "ordem_recanto.faixa", "Ritmo\\Domain\\Relations\\Audit\\RelationAuditor::auditBetween()",
                 "Audit of the middle room of a between relation on one night (current prices only). `today` null = the "
                 "rule floor without the lead-time market floor. Bounds are whole currency (half to even).", casos)


# ---------------------------------------------------------------- ordem_recanto.faixa_afrodite (audit of a premium)
def premium_audit_cases() -> None:
    casos = []

    def add(nome, s, q7, db, q2_final, af, teto_ate="none", p1=None, hoje=None, ords=SEM_ORDENS, patch=None):
        c = cfg_com(patch)
        last = None if teto_ate == "none" else D(teto_ate)
        with PD.com_ordens(ords):
            g = OR.faixa_afrodite(c, D(s), {"price": str(q7)}, {"price": str(db)}, q2_final, {"price": str(af)},
                                  teto_ate=last, panoramica_1=({"price": str(p1)} if p1 is not None else None),
                                  hoje=D(hoje) if hoje else None)
            hooks = {f"Afrodite|{s}": audit_hooks(c, "Afrodite", s, D(hoje) if hoje else None)}
        rows = {"Afrodite": {s: {"price": str(af)}}}
        if p1 is not None:
            rows["Double"] = {s: {"price": str(p1)}}
        _, rels_patch = patches_of(patch)
        inp = {"night": s, "today": hoje, "others": max(float(q7), float(db), float(q2_final)), "derive": p1 is not None,
               "calendar": calendar(c, rows), "hooks": hooks, "band_last_night": None if teto_ate == "none" else teto_ate}
        if rels_patch:
            inp["relations_patch"] = rels_patch
        casos.append({"name": nome, "input": inp, "expected": {
            "floor": float(g["piso"]), "ceiling": num(g["teto"]), "out": g["fora"], "premium": g["premio"]}})

    wd, fs, fe = "2027-05-04", "2027-05-08", "2027-03-26"
    ta = OR.tol(CFG, "Afrodite")   # 21
    # weekday: 20% over the most expensive of the others
    add("weekday_below_premium", wd, 1232, 2235, 1300, 1500)
    add("weekday_floor_rule_wins_over_premium", wd, 1000, 1100, 1050, 1400)
    add("weekday_exactly_at_premium", wd, 1232, 1288, 1274, round(1288 * 1.2, 2))
    add("weekday_at_premium_minus_tol", wd, 1232, 1288, 1274, round(1288 * 1.2 - ta, 2))
    add("weekday_at_premium_minus_tol_minus_cent", wd, 1232, 1288, 1274, round(1288 * 1.2 - ta - 0.01, 2))
    add("weekday_above_premium", wd, 1232, 1288, 1274, 1700)
    add("weekday_q2_is_the_max", wd, 1232, 1288, 1900, 2000)
    add("weekday_q7_is_the_max", wd, 2100, 1288, 1300, 2400)
    add("weekday_tie_all_others", wd, 1300, 1300, 1300, 1560)
    add("weekday_tie_all_others_below", wd, 1300, 1300, 1300, 1530)
    add("weekday_q2_sold_out_zero", wd, 1232, 1288, 0, 1560)
    # weekend outside holiday: 5%, market band ceiling (public band / public factor) when above it
    lo_b, hi_b = OR.faixa_fds_afrodite(CFG, D(fs))
    add("weekend_inside_band", fs, 2018, 2028, 1900, 2700)
    add("weekend_above_band_gets_ceiling", fs, 2018, 2028, 1900, 3523)
    add("weekend_at_band_top_plus_tol", fs, 2018, 2028, 1900, hi_b + ta)
    add("weekend_at_band_top_plus_tol_plus_cent", fs, 2018, 2028, 1900, round(hi_b + ta + 0.01, 2))
    add("weekend_below_premium", fs, 2018, 2028, 1900, 2000)
    add("weekend_order_beats_band_ceiling", fs, 2018, 3100, 1900, 3600)
    add("weekend_above_band_beyond_horizon", fs, 2018, 2028, 1900, 3523, teto_ate="2027-05-07")
    add("weekend_above_band_horizon_is_the_night", fs, 2018, 2028, 1900, 3523, teto_ate="2027-05-08")
    add("weekend_below_premium_beyond_horizon", fs, 2018, 2900, 1900, 2950, teto_ate="2027-05-01")
    add("weekend_above_band_price_below_band_floor_rounding", fs, 2018, 2028, 1900, 2879)
    # holiday: 5%, never the weekend band
    add("holiday_fri_inside", fe, 3190, 3789, 3000, 4369)
    add("holiday_fri_below_premium", fe, 3190, 3789, 3000, 3800)
    add("holiday_fri_high_price_no_ceiling", fe, 2000, 2100, 2050, 6000)
    add("holiday_tue_tiradentes_below", "2027-04-20", 1500, 1600, 1550, 1650)
    # owner's exception to the safety limit lowers the rule floor inside its window
    add("owner_exception_window_weekday", "2026-11-03", 739, 755, 747, 800)
    add("owner_exception_window_weekday_above", "2026-11-03", 739, 755, 747, 950)
    add("owner_exception_after_window", "2026-11-10", 739, 755, 747, 800)
    # plan period (order in use): 15% over the reference room on governed nights, no weekend band
    add("plan_weekend_15pct_over_double_below", "2026-11-14", 1086, 1536, 1423, 1700, p1=1536, hoje="2026-11-11", ords=PLANO)
    add("plan_weekend_15pct_over_double_ok", "2026-11-14", 1086, 1536, 1423, 1767, p1=1536, hoje="2026-11-11", ords=PLANO)
    add("plan_weekend_15pct_minus_tol", "2026-11-14", 1086, 1536, 1423, round(1536 * 1.15 - ta, 2), p1=1536,
        hoje="2026-11-11", ords=PLANO)
    add("plan_weekend_15pct_minus_tol_minus_cent", "2026-11-14", 1086, 1536, 1423, round(1536 * 1.15 - ta - 0.01, 2),
        p1=1536, hoje="2026-11-11", ords=PLANO)
    add("plan_weekend_high_price_no_band_ceiling", "2026-11-14", 1086, 1536, 1423, 3600, p1=1536, hoje="2026-11-11", ords=PLANO)
    add("plan_weekend_without_panoramica_1_arg", "2026-11-14", 1086, 1536, 1423, 1700, hoje="2026-11-11", ords=PLANO)
    add("plan_weekend_no_plan_order_band_back", "2026-11-14", 1086, 1536, 1423, 3600, p1=1536, hoje="2026-11-11", ords=SEM_ORDENS)
    add("plan_weekend_ref_sold_out_higher_p1", "2026-11-14", 1086, 1086, 1050, 1500, p1=1536, hoje="2026-11-11", ords=PLANO)
    add("plan_weekday_no_15pct", "2026-11-16", 905, 950, 930, 1100, p1=950, hoje="2026-11-11", ords=PLANO)
    add("plan_weekday_no_15pct_ok", "2026-11-16", 905, 950, 930, 1600, p1=950, hoje="2026-11-11", ords=PLANO)
    add("plan_holiday_natal_15pct", "2026-12-24", 2400, 2700, 2600, 2900, p1=2700, hoje="2026-12-01", ords=PLANO)
    add("plan_holiday_natal_premium_over_max_beats_15pct", "2026-12-24", 3100, 2700, 2600, 3150, p1=2700,
        hoje="2026-12-01", ords=PLANO)
    add("plan_between_holidays_sun_20pct", "2026-12-27", 2400, 2400, 2400, 2800, p1=2400, hoje="2026-12-20", ords=PLANO)
    add("plan_between_holidays_sun_20pct_ok", "2026-12-27", 2400, 2400, 2400, 2900, p1=2400, hoje="2026-12-20", ords=PLANO)
    # configurable premium and band
    add("patched_premiums_10_and_30", wd, 1232, 1288, 1274, 1600,
        patch={"por_curto.Afrodite.premio_minimo": 0.10, "por_curto.Afrodite.premio_minimo_semana": 0.30})
    add("patched_band_weekend", fs, 2018, 2028, 1900, 3523,
        patch={"parametros.afrodite_fds_publico": [1500, 2000], "parametros.regra_j.fator_publico.Recanto": 0.6})
    write_golden("ordem_recanto.faixa_afrodite", "ordem_recanto.faixa_afrodite",
                 "Ritmo\\Domain\\Relations\\Audit\\PremiumAudit::check()",
                 "Audit of the top room of a premium relation on one night: `others` is the dearest other room, `derive` "
                 "whether the derived at_least relation is evaluated (the engine's panoramica_1 argument), "
                 "`band_last_night` null = no horizon limit. Floor and ceiling are whole currency.", casos)


# ---------------------------------------------------------------- ordem_recanto.comparar + montar_plano (whole audit)
def audit_run_cases() -> None:
    casos = []

    def cal(k, noites: dict) -> dict:
        return {n: row(n, p, v, UNITS[k]) | ({"min_price": str(mn)} if mn is not None else {})
                for n, (p, v, mn) in noites.items()}

    def add(nome, hoje, de, ate, q7, db, q2, af=None, ords=SEM_ORDENS):
        cals = {"Q7": cal("Q7", q7), "Double": cal("Double", db), "Q2": cal("Q2", q2)}
        if af is not None:
            cals["Afrodite"] = cal("Afrodite", af)
        hooks = {}
        with PD.com_ordens(ords):
            fora = OR.comparar(CFG, cals["Q7"], cals["Double"], cals["Q2"], D(de), D(ate), cals.get("Afrodite"), hoje=D(hoje))
            plan = OR.montar_plano(copy.deepcopy(fora), D(hoje))
            for k in ("Q2", "Afrodite"):
                for n in sorted(cals.get(k) or {}):
                    hooks[f"{k}|{n}"] = audit_hooks(CFG, k, n, D(hoje))
        findings = [{"room": x["quarto"], "night": x["data"], "floor": float(x["piso"]), "ceiling": num(x.get("teto")),
                     "current": float(x["panoramica_2"] if x["quarto"] == "Q2" else x["afrodite"])} for x in fora]
        casos.append({"name": nome, "input": {"today": hoje, "from": de, "to": ate, "calendar": calendar(CFG, cals),
                                              "hooks": hooks,
                                              "band_last_night": OR.horizonte_teto_fds(CFG, D(hoje)).isoformat()},
                      "expected": {"findings": findings, "items": [result_item(it) for it in plan["itens"]]}})

    # 1. a plain week outside the plan period (Mon 2027-03-01 .. Sun 2027-03-07), every night with an issue or not
    add("week_outside_plan_mixed", "2027-03-01", "2027-03-01", "2027-03-07",
        q7={"2027-03-01": (1232, 1, None), "2027-03-02": (1232, 1, None), "2027-03-03": (1232, 7, None),
            "2027-03-04": (1500, 6, None), "2027-03-05": (2018, 1, None), "2027-03-06": (2018, 1, None),
            "2027-03-07": (1232, 0, None)},
        db={"2027-03-01": (1302, 1, None), "2027-03-02": (1302, 6, None), "2027-03-03": (1302, 1, None),
            "2027-03-04": (1300, 1, None), "2027-03-05": (2028, 1, None), "2027-03-06": (2900, 1, None),
            "2027-03-07": (1302, 0, None)},
        q2={"2027-03-01": (1613, 0, None), "2027-03-02": (1613, 0, None), "2027-03-03": (1613, 0, None),
            "2027-03-04": (1700, 0, None), "2027-03-05": (2400, 0, None), "2027-03-06": (2020, 2, None),
            "2027-03-07": (1290, 0, 1500)},
        af={"2027-03-01": (1500, 0, None), "2027-03-02": (1500, 0, None), "2027-03-03": (1500, 0, None),
            "2027-03-04": (1900, 0, None), "2027-03-05": (3523, 0, None), "2027-03-06": (2950, 0, None),
            "2027-03-07": (1500, 1, None)})
    # 2. both references sold out: the top suite is skipped that night
    add("both_refs_sold_out_skips_top_suite", "2027-03-01", "2027-03-02", "2027-03-03",
        q7={"2027-03-02": (1232, 7, None), "2027-03-03": (1232, 7, None)},
        db={"2027-03-02": (1302, 6, None), "2027-03-03": (1302, 6, None)},
        q2={"2027-03-02": (1613, 0, None), "2027-03-03": (1613, 2, None)},
        af={"2027-03-02": (1400, 0, None), "2027-03-03": (1400, 0, None)})
    # 3. no calendar for the top suite; a night missing in one calendar is ignored
    add("without_top_suite_calendar_and_missing_night", "2027-03-01", "2027-03-01", "2027-03-04",
        q7={"2027-03-01": (1232, 1, None), "2027-03-02": (1232, 1, None), "2027-03-04": (1232, 1, None)},
        db={"2027-03-01": (1302, 1, None), "2027-03-02": (1302, 1, None), "2027-03-03": (1302, 1, None),
            "2027-03-04": (1302, 1, None)},
        q2={"2027-03-01": (1613, 0, None), "2027-03-02": (1250, 0, None), "2027-03-03": (1613, 0, None),
            "2027-03-04": (1100, 0, None)})
    # 4. holiday nights (Semana Santa Thu-Sat) and the Sunday after
    add("holiday_semana_santa", "2027-03-01", "2027-03-25", "2027-03-28",
        q7={"2027-03-25": (2500, 1, None), "2027-03-26": (2500, 1, None), "2027-03-27": (2500, 7, None),
            "2027-03-28": (1232, 1, None)},
        db={"2027-03-25": (3000, 1, None), "2027-03-26": (3000, 1, None), "2027-03-27": (3000, 1, None),
            "2027-03-28": (1302, 1, None)},
        q2={"2027-03-25": (3400, 0, None), "2027-03-26": (2700, 0, None), "2027-03-27": (3400, 0, None),
            "2027-03-28": (1290, 0, None)},
        af={"2027-03-25": (3100, 0, None), "2027-03-26": (6000, 0, None), "2027-03-27": (3100, 0, None),
            "2027-03-28": (1500, 0, None)})
    # 5. weekend band horizon: today + 95 days = 2027-06-04; Fri 2027-06-04 inside, Sat 2027-06-05 outside
    add("weekend_band_horizon_edge", "2027-03-01", "2027-06-04", "2027-06-05",
        q7={"2027-06-04": (2018, 1, None), "2027-06-05": (2018, 1, None)},
        db={"2027-06-04": (2028, 1, None), "2027-06-05": (2028, 1, None)},
        q2={"2027-06-04": (2020, 0, None), "2027-06-05": (2020, 0, None)},
        af={"2027-06-04": (3523, 0, None), "2027-06-05": (3523, 0, None)})
    # 6. plan period with the plan order: weekday D7..D0 market floor, governed weekend, holiday, between holidays
    plano_noites = dict(
        q7={"2026-11-13": (1100, 2, None), "2026-11-14": (1100, 7, None), "2026-11-16": (905, 1, None),
            "2026-11-17": (905, 1, None), "2026-11-20": (1700, 1, None), "2026-11-23": (905, 1, None)},
        db={"2026-11-13": (1500, 2, None), "2026-11-14": (1500, 6, None), "2026-11-16": (950, 1, None),
            "2026-11-17": (950, 1, None), "2026-11-20": (1900, 1, None), "2026-11-23": (950, 1, None)},
        q2={"2026-11-13": (1700, 1, None), "2026-11-14": (1700, 1, None), "2026-11-16": (1300, 0, None),
            "2026-11-17": (930, 0, None), "2026-11-20": (2100, 0, None), "2026-11-23": (1300, 0, None)},
        af={"2026-11-13": (1700, 0, None), "2026-11-14": (1700, 0, None), "2026-11-16": (1100, 0, None),
            "2026-11-17": (1600, 0, None), "2026-11-20": (2000, 0, None), "2026-11-23": (1300, 0, None)})
    add("plan_period_with_plan_order", "2026-11-11", "2026-11-11", "2026-11-25", ords=PLANO, **plano_noites)
    add("plan_period_without_plan_order", "2026-11-11", "2026-11-11", "2026-11-25", ords=SEM_ORDENS, **plano_noites)
    # 7. ties and reversed references
    add("ties_and_reversed", "2027-03-01", "2027-03-08", "2027-03-11",
        q7={"2027-03-08": (1400, 1, None), "2027-03-09": (1500, 1, None), "2027-03-10": (1400, 1, None),
            "2027-03-11": (1400, 1, None)},
        db={"2027-03-08": (1400, 1, None), "2027-03-09": (1300, 1, None), "2027-03-10": (1400, 1, None),
            "2027-03-11": (1400, 1, None)},
        q2={"2027-03-08": (1500, 0, None), "2027-03-09": (1700, 0, None), "2027-03-10": (1400, 0, None),
            "2027-03-11": (1300, 0, None)},
        af={"2027-03-08": (1680, 0, None), "2027-03-09": (1790, 0, None), "2027-03-10": (1650, 0, None),
            "2027-03-11": (1700, 0, None)})
    write_golden("ordem_recanto.comparar", "ordem_recanto.comparar+montar_plano",
                 "Ritmo\\Domain\\Relations\\Audit\\RelationAuditor::run() and toPlanItems()",
                 "Whole audit over a date range: findings in night order (middle room before top room) and the plan items "
                 "built from them (the middle room cites the order that allows going below the owner's floor when its "
                 "floor is below it, else its relation's order; the top room cites none).", casos)


# ---------------------------------------------------------------- pace.regra_afrodite (bounds of the top room)
def premium_bounds_cases() -> None:
    casos = []

    def add(nome, s, hoje, cals, finais=None, ords=SEM_ORDENS, patch=None):
        c = cfg_com(patch)
        with PD.com_ordens(ords):
            floor, ceiling = pace.regra_afrodite(c, cals, dict(finais or {}), D(s), D(hoje))
            band = sent_band(c, s)
        _, rels_patch = patches_of(patch)
        inp = {"night": s, "today": hoje, "calendar": calendar(c, cals), "decided": keyed(finais or {}), "band": band,
               "band_last_night": OR.horizonte_teto_fds(c, D(hoje)).isoformat()}
        if rels_patch:
            inp["relations_patch"] = rels_patch
        casos.append({"name": nome, "input": inp, "expected": {"floor": float(floor), "ceiling": num(ceiling)}})

    def tres(n, a, b, c, va=1, vb=1, vc=0):
        return {"Q7": {n: row(n, a, va, 7)}, "Double": {n: row(n, b, vb, 6)}, "Q2": {n: row(n, c, vc, 2)}}

    h = "2027-03-01"
    add("weekday_all_free", "2027-03-02", h, tres("2027-03-02", 1300, 1400, 1350))
    add("weekday_double_sold_out", "2027-03-02", h, tres("2027-03-02", 1300, 1400, 1350, vb=6))
    add("weekday_all_sold_out", "2027-03-02", h, tres("2027-03-02", 1300, 1400, 1350, va=7, vb=6, vc=2))
    add("weekday_decided_price_overrides_calendar", "2027-03-02", h, tres("2027-03-02", 1300, 1400, 1350),
        finais={("Double", "2027-03-02"): 1509.6})
    add("weekday_decided_price_of_sold_out_room_ignored", "2027-03-02", h, tres("2027-03-02", 1300, 1400, 1350, vb=6),
        finais={("Double", "2027-03-02"): 1900.0})
    add("weekday_no_calendars", "2027-03-02", h, {})
    add("weekday_only_q2", "2027-03-02", h, {"Q2": {"2027-03-02": row("2027-03-02", 1350, 0, 2)}})
    add("weekend_inside_horizon_band", "2027-03-06", h, tres("2027-03-06", 2000, 2100, 2050))
    add("weekend_inside_horizon_order_above_band_floor", "2027-03-06", h, tres("2027-03-06", 2000, 2900, 2050))
    add("weekend_all_sold_out_band_only", "2027-03-06", h, tres("2027-03-06", 2000, 2100, 2050, va=7, vb=6, vc=2))
    add("weekend_horizon_last_day", "2027-06-04", h, tres("2027-06-04", 2000, 2100, 2050))
    add("weekend_beyond_horizon", "2027-06-05", h, tres("2027-06-05", 2000, 2100, 2050))
    add("holiday_fri", "2027-03-26", h, tres("2027-03-26", 2500, 3000, 2700))
    add("holiday_sat", "2027-03-27", h, tres("2027-03-27", 2500, 3000, 2700))
    add("sunday_after_holiday", "2027-03-28", h, tres("2027-03-28", 1300, 1400, 1350))
    add("plan_weekend_governed_no_band", "2026-11-14", "2026-11-11", tres("2026-11-14", 1100, 1500, 1400), ords=PLANO)
    add("plan_weekend_without_plan_order_band", "2026-11-14", "2026-11-11", tres("2026-11-14", 1100, 1500, 1400))
    add("plan_weekday", "2026-11-16", "2026-11-11", tres("2026-11-16", 905, 950, 930), ords=PLANO)
    add("plan_between_holidays_sun", "2026-12-27", "2026-12-20", tres("2026-12-27", 2400, 2400, 2400), ords=PLANO)
    add("plan_reveillon_eve_tue_holiday", "2026-12-29", "2026-12-20", tres("2026-12-29", 2400, 2500, 2450), ords=PLANO)
    add("patched_horizon_10_days", "2027-03-12", h, tres("2027-03-12", 2000, 2100, 2050),
        patch={"parametros.afrodite_fds_horizonte_dias": 10})
    add("patched_horizon_11_days", "2027-03-12", h, tres("2027-03-12", 2000, 2100, 2050),
        patch={"parametros.afrodite_fds_horizonte_dias": 11})
    write_golden("pace.regra_afrodite", "pace.regra_afrodite", "Ritmo\\Domain\\Relations\\PremiumBounds::forNight()",
                 "Floor and ceiling of the top room before the coherence stage (for the pace step and the sold-out "
                 "protection). `band` is the room's sent band of the night (null when none), `band_last_night` the last "
                 "night it may be copied to. Floor 0 without a member that is not sold out.", casos)


# ---------------------------------------------------------------- pace.teto_anuncio / limitar_ao_maximo (cap chain)
def cap_chain_cases() -> None:
    caps, passes = [], []
    patches = {
        "default": None,
        "double_max_3000": {"por_curto.Double.max": 3000},
        "q2_max_2500": {"por_curto.Q2.max": 2500},
        "q7_max_1900": {"por_curto.Q7.max": 1900},
        "all_recanto_max_not_on_percent_grid": {"por_curto.Double.max": 4999, "por_curto.Q2.max": 4999,
                                                "por_curto.Q7.max": 4999},
        "q2_base_1352": {"por_curto.Q2.base": 1352},
    }
    for name, patch in patches.items():
        c = cfg_com(patch)
        rooms_patch, _ = patches_of(patch)
        for k in ROOMS:
            caps.append({"name": f"{name}_{k}", "input": {"room": k, "rooms_patch": rooms_patch, "rooms_removed": []},
                         "expected": pace.teto_anuncio(c, k)})
    without = copy.deepcopy(CFG)
    without["quartos"] = [q for q in without["quartos"] if q["curto"] != "Q2"]
    without["por_curto"] = {q["curto"]: q for q in without["quartos"]}
    for k in ("Q7", "Double"):
        caps.append({"name": f"chain_incomplete_without_Q2_{k}", "input": {"room": k, "rooms_patch": {},
                                                                          "rooms_removed": ["Q2"]},
                     "expected": pace.teto_anuncio(without, k)})

    def lim(nome, itens, patch=None):
        c = cfg_com(patch)
        after = copy.deepcopy(itens)
        pace.limitar_ao_maximo(c, after)
        rooms_patch, _ = patches_of(patch)
        dated = [it for it in itens if it.get("tipo") == "data"]
        passes.append({"name": nome, "input": {"items": [draft_item(it) for it in dated], "rooms_patch": rooms_patch},
                       "expected": [result_item(it) for it in after if it.get("tipo") == "data"]})

    def it(q, mn=None, mx=None, **kw):
        return dict({"tipo": "data", "quarto": q, "data": "2026-12-24", "preco_antes": 3000.0, "motivo": "teste"},
                    **({"min_price": mn} if mn is not None else {}), **({"max_price": mx} if mx is not None else {}), **kw)

    lim("floors_above_listing_max_chain", [it("Q7", 5024), it("Q2", 5024), it("Double", 5017), it("Afrodite", 10400)])
    lim("floors_below_cap_untouched", [it("Q7", 3000), it("Q2", 3100, 3200), it("Double", 3300)])
    lim("floor_at_cap_ceiling_follows", [it("Q2", 4990, 5000), it("Q7", 4985, 6000)])
    lim("floor_at_cap_protection_item_keeps_free_ceiling", [it("Q2", 4990, 5000, protecao=True)])
    lim("floor_near_cap_within_1pct_base_ceiling_follows", [it("Double", 4960, 5000)])
    lim("floor_far_from_cap_ceiling_kept", [it("Double", 4900, 5000)])
    lim("items_without_floor_ignored", [it("Double", None, 6000), {"tipo": "apagar", "quarto": "Q7", "data": "2026-12-24"}])
    lim("other_property_no_chain", [it("VK7", 5100), it("VK2", 5100, 5200), it("Balcony", 5100)])
    lim("patched_q2_max_2500_chain_lowers_q7", [it("Q7", 2600), it("Q2", 2600), it("Double", 2600)],
        patch={"por_curto.Q2.max": 2500})
    write_golden("pace.teto_anuncio", "pace.teto_anuncio", "Ritmo\\Domain\\Relations\\ListingCapChain::capFor()",
                 "Highest effective floor under the listing maximum, chained through the between relations (high "
                 "reference, then target, then low reference). Rooms patched or removed as given.", caps)
    write_golden("pace.limitar_ao_maximo", "pace.limitar_ao_maximo", "Ritmo\\Domain\\Relations\\ListingCapChain::apply()",
                 "The last pass over the plan items (date items only): floors above the cap cut to it; the ceiling of an "
                 "item at the cap follows, except for a sold-out protection.", passes)


# ---------------------------------------------------------------- plano_dono.balcony_no_mercado (switch trigger)
def trigger_cases() -> None:
    casos = []
    for total in (9, 5, 10, 7, 1, 0):
        for sold in range(0, total + 2):
            casos.append({"name": f"sold_{sold}_of_{total}", "input": {"sold": sold, "units": total},
                          "expected": PD.balcony_no_mercado(sold, total)})
    write_golden("plano_dono.balcony_no_mercado", "plano_dono.balcony_no_mercado",
                 "Ritmo\\Domain\\Relations\\Params\\SwitchParams::isHit()",
                 "Trigger of the occupancy switch of the parity preset: sold / units >= threshold - 1e-9, units > 0.", casos)


# ---------------------------------------------------------------- validar_plano.Validador.validar_balcony (band rule)
VALIDATOR_MESSAGES = (
    (re.compile(r"^ERRO Regra G (\S+): teto da Balcony"), "band.ceiling_above_reference", True),
    (re.compile(r"^ERRO Regra G (\S+): Balcony .* acima da suíte"), "band.above_reference", True),
    (re.compile(r"^aviso Regra G (\S+): Balcony .* acima de \d+%"), "band.above_upper", False),
    (re.compile(r"^aviso Regra G (\S+): .*no mercado do quarto sem banheira"), "band.below_by_switch", False),
    (re.compile(r"^aviso Regra G (\S+): .*\(libera regra_g\)"), "band.below_by_order", False),
    (re.compile(r"^aviso Regra G (\S+): .*é piso de percentual"), "band.below_confirm_after_recalc", False),
    (re.compile(r"^ERRO Regra G (\S+): .*mais de 5% abaixo"), "band.below_lower", True),
)


def classify(messages: list[str]) -> list[dict]:
    """The engine's Portuguese messages as the plugin's issue codes (night, code, error)."""
    out = []
    for message in messages:
        for pattern, code, error in VALIDATOR_MESSAGES:
            found = pattern.match(message)
            if found:
                out.append({"night": found.group(1), "code": code, "error": error})
                break
        else:
            raise ValueError(f"unknown validator message: {message}")
    return sorted(out, key=lambda x: (x["night"], x["code"]))


def band_check_cases() -> None:
    casos = []

    def add(nome, hoje, finais, precos=None, tetos=None, pisos=None, ordem_item=None, ords=None, patch=None,
            satisfied_by_switch=None, php_expected=None, nota=None):
        c = cfg_com(patch)
        ords = ORDENS_FROZEN if ords is None else ords
        pre = {tuple(k.split("|")): dict(v) for k, v in (precos or {}).items()}
        v = VP.Validador(c, D(hoje), dict(pre), ordens=ords)
        v.tetos = {tuple(k.split("|")): x for k, x in (tetos or {}).items()}
        v.pisos = {tuple(k.split("|")) for k in (pisos or [])}
        v.ordem_item = {tuple(k.split("|")): x for k, x in (ordem_item or {}).items()}
        with PD.com_ordens(ords):
            v.validar_balcony({tuple(k.split("|")): x for k, x in finais.items()})
        releases = {}
        for key in finais:
            n = key.split("|")[1]
            ids = [o.get("id") for o in ords.values() if ORD.cobre(o, "Balcony", D(n))
                   and "regra_g" in (o.get("libera") or []) and (not o.get("noites") or n in o["noites"])]
            if ids:
                releases[f"Balcony|{n}"] = ids[0]
        _, rels_patch = patches_of(patch)
        inp = {"today": hoje, "planned": finais, "ceilings": tetos or {}, "percent_floors": sorted(pisos or []),
               "order_refs": ordem_item or {}, "releases": releases, "satisfied_by_switch": satisfied_by_switch or [],
               "fresh": {k: {"price": num(x.get("preco")), "free": x.get("livres"), "sold": x.get("vendidas", 0),
                             "total": x.get("total")} for k, x in (precos or {}).items()}}
        if rels_patch:
            inp["relations_patch"] = rels_patch
        if nota:
            inp["note"] = nota
        case = {"name": nome, "input": inp, "expected": classify(v.erros) + classify(v.avisos)}
        case["expected"] = sorted(case["expected"], key=lambda x: (x["night"], x["code"]))
        if php_expected is not None:
            case["php_expected"] = php_expected
        casos.append(case)

    h, n = "2027-03-01", "2027-03-02"
    pre = lambda p7=1000, p2=1100, pb=880, l7=6, l2=2, lb=6: {  # noqa: E731
        f"VK7|{n}": {"preco": p7, "livres": l7, "vendidas": 7 - l7, "total": 7},
        f"VK2|{n}": {"preco": p2, "livres": l2, "vendidas": 2 - l2, "total": 2},
        f"Balcony|{n}": {"preco": pb, "livres": lb, "vendidas": 6 - lb, "total": 6}}
    add("in_band_fixed", h, {f"Balcony|{n}": 889.0}, pre())
    add("at_target", h, {f"Balcony|{n}": 900.0}, pre())
    add("above_target_within_slack", h, {f"Balcony|{n}": 913.7}, pre())
    add("above_target_warning", h, {f"Balcony|{n}": 913.71}, pre())
    add("equal_to_ref_plus_1_warning_only", h, {f"Balcony|{n}": 1001.0}, pre())
    add("above_ref_error", h, {f"Balcony|{n}": 1001.01}, pre())
    add("ceiling_above_ref_error", h, {f"Balcony|{n}": 889.0}, pre(), tetos={f"Balcony|{n}": 1001.5})
    add("ceiling_at_ref_plus_1_ok", h, {f"Balcony|{n}": 889.0}, pre(), tetos={f"Balcony|{n}": 1001.0})
    add("at_lower_edge", h, {f"Balcony|{n}": 855.0}, pre())
    add("below_lower_edge_error", h, {f"Balcony|{n}": 854.99}, pre())
    add("below_lower_edge_percent_floor_against_calendar_ref_warning", h, {f"Balcony|{n}": 850.9}, pre(),
        pisos=[f"Balcony|{n}"])
    add("below_lower_edge_percent_floor_against_planned_ref_error", h, {f"Balcony|{n}": 850.9, f"VK7|{n}": 1000.0}, pre(),
        pisos=[f"Balcony|{n}", f"VK7|{n}"])
    add("ref_planned_cheaper_than_calendar", h, {f"Balcony|{n}": 889.0, f"VK7|{n}": 947.2}, pre())
    add("cheapest_ref_sold_out_uses_other", h, {f"Balcony|{n}": 980.0}, pre(p7=1000, p2=1100, l7=0))
    add("all_refs_sold_out_skipped", h, {f"Balcony|{n}": 1500.0}, pre(l7=0, l2=0))
    add("target_sold_out_skipped", h, {f"Balcony|{n}": 1500.0}, pre(lb=0))
    add("no_calendar_prices_no_refs_skipped", h, {f"Balcony|{n}": 889.0}, {})
    add("only_ref_planned_target_from_calendar_in_band", h, {f"VK7|{n}": 1000.0}, pre(pb=889))
    add("only_ref_planned_target_from_calendar_too_high", h, {f"VK7|{n}": 900.0}, pre(pb=980))
    add("order_releases_band_below", h, {f"Balcony|{n}": 700.0}, pre(),
        ords=dict(ORDENS_FROZEN, lib=ordem("lib", "preco_por_data", ["Balcony"], n, n, libera=["fixo", "regra_g"])))
    add("order_releases_band_only_listed_nights", h, {f"Balcony|{n}": 700.0}, pre(),
        ords=dict(ORDENS_FROZEN, lib=ordem("lib", "preco_por_data", ["Balcony"], n, n, libera=["regra_g"], noites=["2027-03-03"])))
    add("order_release_does_not_cover_above_ref", h, {f"Balcony|{n}": 1100.0}, pre(),
        ords=dict(ORDENS_FROZEN, lib=ordem("lib", "preco_por_data", ["Balcony"], n, n, libera=["regra_g"])))
    add("patched_discount_15pct_target", h, {f"Balcony|{n}": 889.0}, pre(), patch={"parametros.regra_g_desconto": 0.15})
    fe = "2027-03-26"
    pf = lambda pb=2333: {f"VK7|{fe}": {"preco": 3000, "livres": 6, "vendidas": 1, "total": 7},  # noqa: E731
                          f"VK2|{fe}": {"preco": 2578.1, "livres": 2, "vendidas": 0, "total": 2},
                          f"Balcony|{fe}": {"preco": pb, "livres": 6, "vendidas": 0, "total": 6}}
    add("holiday_far_below_target_no_error", h, {f"Balcony|{fe}": 1511.3}, pf())
    add("holiday_above_90pct_warning", h, {f"Balcony|{fe}": 2425.7}, pf())
    add("holiday_above_ref_error", h, {f"Balcony|{fe}": 2600.0}, pf())
    # occupancy switch inside the plan period: below the band is a warning only with the plan order on the item
    n2 = "2026-12-12"
    p8 = lambda v7=6: {f"VK7|{n2}": {"preco": 1900, "livres": 7 - v7, "vendidas": v7, "total": 7},  # noqa: E731
                       f"VK2|{n2}": {"preco": 2400, "livres": 0, "vendidas": 2, "total": 2},
                       f"Balcony|{n2}": {"preco": 2000, "livres": 6, "vendidas": 0, "total": 6}}
    add("switch_8_of_9_plan_order_item_warning", "2026-12-01", {f"Balcony|{n2}": 1041.4, f"VK7|{n2}": 1909.2}, p8(6),
        ordem_item={f"Balcony|{n2}": PLANO_ID})
    add("switch_7_of_9_plan_order_item_error", "2026-12-01", {f"Balcony|{n2}": 1041.4, f"VK7|{n2}": 1909.2}, p8(5),
        ordem_item={f"Balcony|{n2}": PLANO_ID})
    add("switch_8_of_9_other_order_item_error", "2026-12-01", {f"Balcony|{n2}": 1041.4, f"VK7|{n2}": 1909.2}, p8(6),
        ordem_item={f"Balcony|{n2}": "dono-2026-10-07-regra-g"})
    add("switch_8_of_9_above_ref_still_error", "2026-12-01", {f"Balcony|{n2}": 1950.0, f"VK7|{n2}": 1909.2}, p8(6),
        ordem_item={f"Balcony|{n2}": PLANO_ID})
    add("switch_missing_ref_calendar_no_proof_error", "2026-12-01", {f"Balcony|{n2}": 1041.4, f"VK7|{n2}": 1909.2},
        {k: v for k, v in p8(6).items() if not k.startswith("VK2")}, ordem_item={f"Balcony|{n2}": PLANO_ID})
    # 12/11: the switch holds, the room without a tub is already at its market price (no item) and a member has an item
    # that night. The engine has no item to look at and reports a false "below the band" error. The plugin's coherence
    # stage records the decision "satisfied by the occupancy switch" and the validator accepts the night (divergence).
    p12 = {k: dict(v) for k, v in p8(6).items()}
    p12[f"Balcony|{n2}"]["preco"] = 1041.4
    add("switch_satisfied_without_item_engine_false_error", "2026-12-01", {f"VK7|{n2}": 1909.2}, p12,
        satisfied_by_switch=[f"Balcony|{n2}"],
        php_expected=[{"night": n2, "code": "band.below_by_switch", "error": False}],
        nota="divergence: with the coherence decision 'satisfied by the occupancy switch' the night is accepted")
    add("switch_satisfied_without_report_still_error", "2026-12-01", {f"VK7|{n2}": 1909.2}, p12,
        nota="without the coherence decision the plugin reports what the engine reports")
    add("switch_satisfied_decision_without_trigger_proof_still_error", "2026-12-01", {f"VK7|{n2}": 1909.2},
        {k: v for k, v in p12.items() if not k.startswith("VK2")}, satisfied_by_switch=[f"Balcony|{n2}"],
        nota="the decision needs the trigger proven by the fresh calendar: a member missing is no proof")
    write_golden("validar_plano.validar_balcony", "validar_plano.Validador.validar_balcony",
                 "Ritmo\\Domain\\Relations\\Validate\\DiscountBandCheck::check()",
                 "Validator rule of the discount band. Expected: issues sorted by night and code (the engine's Portuguese "
                 "messages classified). `releases` are the owner orders that release the band (hook), "
                 "`satisfied_by_switch` the coherence decisions given to the check. `php_expected` (when present) is "
                 "the plugin's deliberate result where it differs from the engine (see docs/RELATIONS.md).", casos)


# ---------------------------------------------------------------- manifest and entry point
def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def engine_state() -> tuple[str, bool]:
    commit = subprocess.run(["git", "-C", str(SKILL), "log", "-1", "--format=%H", "--", "scripts"],
                            capture_output=True, text=True, check=True).stdout.strip()
    dirty = subprocess.run(["git", "-C", str(SKILL), "status", "--porcelain", "--", "scripts"],
                           capture_output=True, text=True, check=True).stdout.strip() != ""
    return commit, dirty


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--freeze", action="store_true",
                        help="copy the engine data (read-only) into the frozen folder first (done before the engine loads)")
    parser.parse_args()
    _write_json(PARITY, relations_preset(CFG))
    coherence_cases()
    between_audit_cases()
    premium_audit_cases()
    audit_run_cases()
    premium_bounds_cases()
    cap_chain_cases()
    trigger_cases()
    band_check_cases()
    commit, dirty = engine_state()
    frozen = {"tests/fixtures/frozen/quartos.json": sha256(M1_FROZEN / "quartos.json")}
    for p in sorted(OWN_FROZEN.glob("*.json")):
        frozen[p.relative_to(FIXTURES.parent.parent).as_posix()] = sha256(p)
    _write_json(OUT / "MANIFEST.json", {"engine_commit": commit, "engine_dirty": dirty,
                                        "engine_path": ENGINE.relative_to(REPO).as_posix(), "frozen_inputs": frozen,
                                        "parity_preset": sha256(PARITY), "files": dict(sorted(FILES.items()))})
    print(f"total: {sum(FILES.values())} cases in {len(FILES)} files; engine {commit[:8]} dirty={dirty}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

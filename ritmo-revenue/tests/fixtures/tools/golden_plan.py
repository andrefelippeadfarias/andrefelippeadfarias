#!/usr/bin/env python3
"""Golden fixtures of the plan validator, the payload and its slices (M2).

Runs the reference engine's validator (validar_plano.Validador), montar_payload and fatiar_payload.fatiar, imported
from the repository and never changed, on frozen plans with their frozen calendars and owner orders, and writes into
tests/fixtures/golden/m2/plan/:

    validar_plano.validar.json        one case per plan: the errors and warnings of every entry, as issue codes
    validar_plano.montar_payload.json one case per plan that passes: the payload
    fatiar_payload.fatiar.json        the same plans: the calls in writing order (70 dates at most per call)
    MANIFEST.json                     engine commit, hashes of the frozen inputs, case counts, vocabulary

and the parity fixture of this area, tests/fixtures/presets/parity-plan.json (the "plan" section of the rule set with
the reference engine's values).

Inputs (tests/fixtures/golden/m2/plan/frozen/, written by `freeze`):
  plan-*.json         the plans: three real recent plans of the reference engine, copied as they were written,
                      and synthetic plans that walk the error paths
  calendar-*.json     the pricing calendars of those days, reduced to the room nights the plans touch (and neighbours)
  inventory-*.json    the PMS inventory of those days, reduced to the orphan nights of the plans
  orders.json         the owner orders in force, without their free texts
  scenarios.json      which plan runs on which day, calendar, clock instant and extra (synthetic) orders
plus the M1 frozen configuration (tests/fixtures/frozen/quartos.json), the frozen ruler of the ladder
(tests/fixtures/frozen/ladder/regua_dono.json) and the season calendar of the stay goldens.

Nothing is written into the engine's folder: the orders file points at a missing temporary path (the orders go in
as arguments), the ruler and the season calendar at the frozen copies, and a guard raises if the engine reads the
clock. The engine's messages are free Portuguese text; MESSAGES maps each one to the plugin's issue code.

Usage:
  python3 tests/fixtures/tools/golden_plan.py freeze --source c1112=DIR --source c1111=DIR
  python3 tests/fixtures/tools/golden_plan.py          # write the goldens, their MANIFEST and the parity fixture
"""

from __future__ import annotations

import argparse
import atexit
import copy
import datetime as dt
import hashlib
import json
import platform
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

sys.dont_write_bytecode = True  # the engine folder must stay untouched, bytecode cache included

TOOLS = Path(__file__).resolve().parent
FIXTURES = TOOLS.parent
FROZEN = FIXTURES / "frozen"
GOLDEN = FIXTURES / "golden" / "m2" / "plan"
FROZEN_PLAN = GOLDEN / "frozen"
PARITY = FIXTURES / "presets" / "parity-plan.json"
SEASONS = FIXTURES / "golden" / "m2" / "stay" / "frozen" / "seasons.json"
RULER = FROZEN / "ladder" / "regua_dono.json"
REPO = TOOLS.parents[3]
ENGINE = REPO / ".claude" / "skills" / "gestao-receita-pousadas" / "scripts"
ENGINE_DATA = ENGINE.parent / "dados"

sys.path.insert(0, str(TOOLS))
sys.path.insert(0, str(ENGINE))
import comum  # noqa: E402
import fatiar_payload as FP  # noqa: E402
import ordens as ORD  # noqa: E402
import plano_dono as PD  # noqa: E402
import sazonalidade as SAZ  # noqa: E402
import validar_plano as VP  # noqa: E402
from vocabulary import PROPERTY_ID  # noqa: E402

ENGINE_PATH = ENGINE.relative_to(REPO).as_posix()
WORK = Path(tempfile.mkdtemp(prefix="ritmo-golden-plan-"))
atexit.register(shutil.rmtree, WORK, True)
DAY = dt.timedelta(days=1)

# ---------------------------------------------------------------- vocabulary (engine -> plugin)
# Engine message -> issue code, first marker found wins (the order matters: specific before general).
MESSAGES = (
    ("remove a estadia mínima", "delete_removes_stay"), ("remove também o piso de proteção", "delete_removes_holiday_floor"),
    ("item duplicado", "duplicate_item"), ("quarto desconhecido", "unknown_room"), ("falta o motivo", "missing_reason"),
    ("data no passado", "past_date"), ("sem ordem_id vigente", "order_not_in_force"),
    ("não serve de exceção 'dono'", "order_does_not_release"), ("não vale como exceção 'dono'", "order_does_not_release"),
    ("noite com mudança manual", "night_frozen"),
    ("com todos os dias fechados", "mask_all_closed"), ("abre o sábado contra a ordem", "mask_opens_closed_day"),
    ("não libera 'sabado_aberto'", "mask_opens_closed_day"), ("exige o campo 'orfa'", "mask_beyond_orphan_side"),
    ("abre só o lado órfão", "mask_beyond_orphan_side"), ("check_out fecha a sexta", "mask_closes_open_day"),
    ("fecha uma noite que não é sábado", "mask_closes_open_night"),
    ("feriado: valor", "__holiday__"), ("abaixo do limite de segurança", "below_safety_limit"),
    ("max_price", "ceiling_below_floor"), ("teto", "__ceiling__"),  # resolved below: "abaixo do piso" or no-discount room
    ("não recebe desconto", "no_discount_room"), ("informe preco_antes", "no_discount_room"),
    ("acima do máximo do anúncio", "above_listing_max"),
    ("aceito sem saldo de recálculo", "stale_calendar_accepted"), ("recalcule e use o arquivo mesclado", "stale_calendar"),
    ("em feriado (", "below_listing_min"), ("não entra na Regra I", "below_listing_min"),
    ("a Regra I só vale até", "below_listing_min"), ("abaixo do piso da Regra I", "last_minute_too_deep"),
    ("Regra I exige --precos", "last_minute_without_calendar"), ("Regra I em noite esgotada", "last_minute_sold_out"),
    ("Regra I não começa", "last_minute_too_sold"), ("Regra I em D0", "last_minute_too_sold_tonight"),
    ("Regra I só para a última", "last_minute_last_unit_only"),
    ("por ordem do dono", "__by_order__"), ("por exceção de mercado", "below_quality_by_market"),
    ("padrão de qualidade do dono", "below_quality_floor"),
    ("pelo piso de mercado do plano", "below_floor_by_market_floor"), ("só libera o piso de mercado", "below_market_floor"),
    ("abaixo do piso de mercado do plano", "below_market_floor"), ("não libera 'abaixo_piso'", "below_owner_floor"),
    ("do dono (14/10)", "below_owner_floor"),
    ("a ordem do plano (", "order_out_of_scope"),
    ("é mais de 15% acima do preço atual", "rise_above_max_step"),
    ("sem min_stay no item", "stay_not_in_item"), ("manter_reason só em item", "keep_reason_with_price"),
    ("nem orfa_prova", "orphan_without_proof"), ("só na noite órfã", "orphan_not_allowed"),
    ("a sexta órfã é a noite órfã geral", "orphan_not_allowed"), ("com a regra de 02/11 desligada", "orphan_not_allowed"),
    ("orfa 'sábado' só num sábado", "orphan_not_allowed"), ("órfão sem prova", "orphan_without_proof"),
    ("órfã sem prova", "orphan_without_proof"), ("desmente", "orphan_contradicted"),
    ("só com estadia 1", "orphan_not_allowed"), ("exige estadia mínima", "stay_below_required"),
    ("abre 1 diária", "stay_above_one_night"), ("acima da do dia", "stay_above_required"),
    ("sábado com 1 noite", "stay_below_required"), ("fora do razoável", "stay_out_of_range"),
    ("Lembre-se tem ser correções percentuais", "listing_needs_order"),
    ("contra a referência da semana", "listing_weekly_change_above_limit"), ("base abaixo do mínimo", "listing_base_below_min"),
)
BAND = (("teto da Balcony", "band_ceiling_above_reference"), ("no mercado do quarto sem banheira", "band_below_by_switch"),
        ("(libera regra_g)", "band_below_by_order"), ("é piso de percentual", "band_below_confirm_after_recalc"),
        ("mais de 5% abaixo", "band_below_lower"), ("acima de ", "band_above_upper"), ("acima da suíte", "band_above_reference"))
# Reason code of a plan item, by a marker of the engine's reason text (the plugin carries codes, not text).
REASONS = (("Proteção da noite esgotada", "SOLD_OUT_PROTECTION"), ("solta o teto", "CEILING_RELEASE_DELETE"),
           ("regra dos 80%", "RELATION_OCCUPANCY_SWITCH"), ("Regra G", "RELATION_DISCOUNT_BAND"),
           ("mais cara das outras", "RELATION_PREMIUM_OVER_MAX"), ("Ordem do Recanto", "RELATION_BETWEEN"),
           ("Escada de vendas", "ORDER_SALES_RAMP"), ("Plano do dono", "LADDER_PRICE"), ("Pace (", "PACE_STEP"),
           ("órf", "STAY_ORPHAN"), ("estadia", "STAY_RULE"))
ORPHAN = {"seguinte": "next_night", "sábado": "closed_day_arrival", "sexta": "closed_day_departure"}
RELEASE = {"fixo": "fixed_price", "abaixo_piso": "below_standard_floor", "acima_15": "above_weekly_rise_limit",
           "estadia": "stay", "estadia_1_orfa": "orphan_stay", "estadia_1_orfa_feriado": "orphan_stay",
           "sabado_aberto_orfao": "orphan_stay", "sabado_aberto": "arrival_departure",
           "chegada_saida_por_data": "arrival_departure", "chegada_saida_afrodite_sexta": "arrival_departure",
           "estadia_por_data": "stay", "anuncio": "listing", "beds24_inventario": "inventory",
           "personalizacao": "customization"}
ORDER_TYPE = {"preco_por_data": "fixed_price", "piso_por_data": "floor", "alvo_por_data": "target", "regra": "rule",
              "manual": "manual", "acompanhamento": "follow_up", "restricao": "restriction"}
# Order keys of the engine -> the plugin's (OrderReader); "pousada_faixa" and "faixa" are kept for the engine only.
ORDER_KEYS = {"id": "id", "tipo": "type", "quartos": "rooms", "de": "from", "ate": "to", "noites": "nights",
              "noites_fora": "excluded_nights", "data": "given_on", "criada_em": "created_at", "libera": "releases",
              "pisos_enviados": "floors", "percentil": "percentile", "vendidas_base": "base_sold", "escada_vendas": "sales_ramp",
              "passo_livre": "free_step", "so_desce": "only_down", "ocupacao_max": "strong_night_occupancy",
              "frente_ocupacao": "strong_night_ahead_occupancy", "topo_enviados": "top", "piso_atras": "fallback_floors",
              "gatilho_dias": "fallback_trigger_days", "gatilho_min_dias": "fallback_min_days",
              "degraus_atras": "descent_floors", "degraus_dias": "descent_marks", "pousada_faixa": None, "faixa": None}
FLOOR_CODES = {"below_listing_min", "below_owner_floor", "below_quality_floor", "below_holiday_floor",
               "below_holiday_safety_floor", "below_market_floor", "below_floor_by_order", "below_floor_by_market_floor",
               "below_quality_by_order", "below_quality_by_market", "last_minute_too_deep", "last_minute_without_calendar",
               "last_minute_sold_out", "last_minute_too_sold", "last_minute_too_sold_tonight", "last_minute_last_unit_only"}
OCCUPANCY = re.compile(r"\+10|4c|flutua|regra g|saída|saida|esgot")
VOCABULARY = {"messages": dict(MESSAGES), "band": dict(BAND), "reasons": dict(REASONS), "orphan": ORPHAN,
              "release": RELEASE, "order_type": ORDER_TYPE, "order_keys": ORDER_KEYS, "occupancy_pattern": OCCUPANCY.pattern,
              "property": PROPERTY_ID}
# Where the plugin decides differently on purpose (VALIDATOR.md, section 6); applied to the expected codes only.
DIVERGENCES = {
    "V1": "under the safety limit, the plugin reports that floor alone (the reference also lists the others)",
    "V3": "the plugin warns (no_before) about an item whose night has nothing known in force (no price before and no "
          "calendar night): its effect cannot be checked; the reference says nothing",
    "V4": "tonight never rises, also through the rounding of the floor to a whole percentage of the base (M1): the "
          "reference's validator does not look at tonight; the plugin refuses the item (raise_today)",
    "V5": "an owner order silences the rise warning only when it releases a larger rise (acima_15) or backs the "
          "strategy; the reference silences it for any order in force",
}


# ---------------------------------------------------------------- engine set-up (read only, no clock)
def no_clock(*_args, **_kwargs):
    raise AssertionError("the engine asked for today's date: every golden case passes its own dates")


def install_guards() -> None:
    SAZ.ARQ = SEASONS
    VP._sazonalidade.cache_clear()
    ORD.ARQ_ORDENS = WORK / "no_orders_file.json"
    PD.ARQ_REGUA = RULER
    if hasattr(PD, "_regua_cache"):
        PD._regua_cache.clear()
    for module in (comum, VP, ORD, PD):
        if hasattr(module, "hoje_brt"):
            module.hoje_brt = no_clock


def cfg() -> dict:
    return comum.carregar_quartos(FROZEN / "quartos.json")


def d(text: str) -> dt.date:
    return dt.date.fromisoformat(text)


def read(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=1, allow_nan=False) + "\n", encoding="utf-8")


def write_golden(path: Path, data: dict) -> None:
    head = {k: v for k, v in data.items() if k != "cases"}
    lines = [json.dumps(c, ensure_ascii=False, allow_nan=False) for c in data["cases"]]
    text = json.dumps(head, ensure_ascii=False, indent=1)[:-2] + ',\n "cases": [\n  ' + ",\n  ".join(lines) + "\n ]\n}\n"
    path.write_text(text, encoding="utf-8")


# ---------------------------------------------------------------- synthetic plans (walk the error paths)
def item(room, night, motivo, **fields) -> dict:
    return {"tipo": "data", "quarto": room, "data": night, "motivo": motivo, **fields}


def synthetic_plans() -> dict[str, dict]:
    proof_next = {"noite_vendidas": 3, "noite_total": 6, "seguinte_vendidas": 6, "seguinte_total": 6}
    proof_sat = {"fonte": "beds24", "unidades": 7, "noite_numAvail": 2, "anterior_numAvail": 0, "lido_em": "2026-11-12T10:00:00Z"}
    old_read = dict(proof_sat, lido_em="2026-11-09T10:00:00Z")
    floors = [
        item("Q7", "2026-11-17", "Pace (synthetic: under the safety limit)", min_price=500, preco_antes=1300),
        item("Q7", "2026-11-24", "Pace (synthetic: under the listing minimum, far)", min_price=900, preco_antes=1300),
        item("VK7", "2026-11-24", "Pace (synthetic: a valid floor)", min_price=1000, preco_antes=1000),
        item("Double", "2026-11-20", "Pace (synthetic: holiday safety floor)", min_price=1200, preco_antes=1500),
        item("Q7", "2026-12-24", "Pace (synthetic: holiday period floor)", min_price=2000, preco_antes=2500, min_stay=2),
        item("VK2", "2026-11-14", "Pace (synthetic: quality floor, last minute)", min_price=900, preco_antes=1400),
        item("VK2", "2026-11-13", "Pace (synthetic: quality floor with market evidence)", min_price=980, preco_antes=1100,
             mercado="mediana da Booking R$ 620 com 12 concorrentes na noite"),
        item("Afrodite", "2026-11-17", "Pace (synthetic: ceiling under the floor)", min_price=2000, max_price=1900, preco_antes=2000),
        item("Q2", "2026-11-25", "Pace (synthetic: ceiling only)", max_price=1000, preco_antes=1500),
        item("Q7", "2026-11-26", "Pace (synthetic: over the listing maximum)", min_price=5100, preco_antes=4000),
        item("Q7", "2026-11-18", "Pace (synthetic: a large rise)", min_price=1500, preco_antes=1200),
        item("Balcony", "2026-11-24", "Regra G (synthetic: above the cheapest tub suite)", min_price=1500, preco_antes=900),
        item("Q7", "2026-11-27", "Plano do dono (synthetic: unknown order)", min_price=2100, max_price=2100,
             excecao="dono", ordem_id="dono-0000-00-00-unknown", preco_antes=2100),
        item("VK7", "2026-11-17", "Plano do dono (synthetic: market floor of the plan)", min_price=900, max_price=900,
             excecao="dono", ordem_id=PD.ORDEM_ID, preco_antes=1000),
        item("Balcony", "2026-11-25", "Plano do dono (synthetic: outside the plan's scope)", min_price=700, max_price=700,
             excecao="dono", ordem_id=PD.ORDEM_ID, preco_antes=800),
        item("Q7", "2026-11-23", "Pace (synthetic: below the weekday floor, released)", min_price=1100, preco_antes=1300,
             excecao="dono", ordem_id="synthetic-below-floor"),
    ]
    stay = [
        item("Q7", "2026-11-16", "estadia (synthetic: Monday needs two)", min_stay=1),
        item("Q7", "2026-11-17", "estadia (synthetic: Tuesday opens one)", min_stay=2),
        item("Q7", "2026-11-18", "estadia (synthetic: Wednesday above two)", min_stay=3),
        item("Q7", "2026-11-14", "estadia (synthetic: closed day with one night)", min_stay=1),
        item("VK7", "2026-11-20", "estadia (synthetic: holiday with one night)", min_stay=1),
        item("Double", "2026-11-23", "Noite órfã (synthetic: proven)", min_stay=1, orfa="seguinte", orfa_prova=proof_next),
        item("Double", "2026-11-25", "Noite órfã (synthetic: no proof)", min_stay=1, orfa="seguinte"),
        item("VK7", "2026-11-21", "Sábado órfão (synthetic: proven, on a holiday)", min_stay=1, orfa="sábado",
             orfa_prova=dict(proof_sat, noite_numAvail=3)),
        item("VK7", "2026-12-05", "Sábado órfão (synthetic: old read)", min_stay=1, orfa="sábado", orfa_prova=old_read),
        item("Q2", "2026-11-19", "estadia (synthetic: out of range)", min_stay=9),
        item("Afrodite", "2026-11-21", "chegada (synthetic: opens the closed day)", check_in="1111111", check_out="1111101"),
        item("Q7", "2026-11-24", "chegada (synthetic: closes a Tuesday)", check_out="1011111"),
        item("Q2", "2026-12-01", "chegada (synthetic: every day closed)", check_in="0000000"),
        item("Afrodite", "2026-11-27", "chegada (synthetic: closes the open Friday)", check_out="1111011"),
        item("VK2", "2026-11-21", "Sábado órfão (synthetic: orphan side opened)", min_stay=1, check_in="1111111",
             check_out="1111101", orfa="sábado", orfa_prova=dict(proof_sat, unidades=2, noite_numAvail=1),
             excecao="dono", ordem_id="dono-2026-11-02-sabado-orfao"),
        item("VK2", "2026-11-14", "Sábado órfão (synthetic: the wrong side)", check_in="1111111", check_out="1111101",
             orfa="sexta", excecao="dono", ordem_id="dono-2026-11-02-sabado-orfao"),
        item("Q7", "2026-11-30", "Pace (synthetic: keeps the reason with a price)", min_price=1300, preco_antes=1300, manter_reason=True),
        item("Q7", "2026-12-23", "estadia (synthetic: package night)", min_stay=1),
        item("Double", "2026-11-14", "Pace (synthetic: Saturday floor without its stay)", min_price=1500, preco_antes=1400),
        item("Q7", "2026-11-25", "estadia (synthetic: released by a newer order)", min_stay=1, excecao="dono", ordem_id="synthetic-stay-new"),
        item("Q7", "2026-12-02", "estadia (synthetic: order older than the rule)", min_stay=1, excecao="dono", ordem_id="synthetic-stay-old"),
    ]
    orders_listing = [
        item("Q2", "2026-11-18", "Pace (synthetic: a night the owner froze)", min_price=1300, preco_antes=1500),
        {"tipo": "anuncio", "quarto": "Q7", "campo": "min", "valor": 850, "antes": 800, "motivo": "synthetic: no order"},
        {"tipo": "anuncio", "quarto": "Q7", "campo": "base", "valor": 1500, "antes": 1173, "motivo": "synthetic: weekly limit",
         "excecao": "dono", "ordem_id": "synthetic-listing"},
        {"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 600, "antes": 944, "motivo": "synthetic: safety",
         "excecao": "dono", "ordem_id": "synthetic-listing"},
        {"tipo": "anuncio", "quarto": "Double", "campo": "base", "valor": 1000, "antes": 1480, "motivo": "synthetic: base under min",
         "excecao": "dono", "ordem_id": "synthetic-listing"},
    ]
    deletes = [{"tipo": "apagar", "quarto": q, "data": n, "motivo": f"solta o teto (synthetic {q} {n})"}
               for q, n in (("Q7", "2026-11-10"), ("Q7", "2026-11-14"), ("VK7", "2026-11-20"), ("Q2", "2026-11-15"),
                            ("Double", "2026-11-16"), ("Q2", "2026-11-12"))]
    occupancy = [
        item("VK2", "2026-11-16", "Proteção da noite esgotada (synthetic)", min_price=1400, preco_antes=1300),
        item("Q7", "2026-11-17", "Pace (synthetic: not by occupancy)", min_price=1250, preco_antes=1300),
        item("VK7", "2026-11-13", "Pace (synthetic: last minute under the minimum)", min_price=850, preco_antes=1000),
        item("Balcony", "2026-11-13", "Pace (synthetic: last minute, deeper than allowed)", min_price=540, preco_antes=700),
        item("Q2", "2026-11-13", "Pace (synthetic: no last-minute rule here)", min_price=950, preco_antes=1100),
    ]
    long_reason = "Pace (synthetic payload, " + "a long reason that goes on " * 12 + ")"
    payload = [item("Q7", n, long_reason if i < 3 else "Pace (synthetic payload)", min_price=2600)
               for i, n in enumerate(nights(d("2026-11-14"), d("2027-04-12")))]
    payload += [
        item("Q2", "2026-11-12", "Pace (synthetic: tonight)", min_price=900, preco_antes=1495, excecao="dono", ordem_id=PD.ORDEM_ID),
        item("Q2", "2026-11-13", "Pace (synthetic: tomorrow)", min_price=1300, max_price=1400, preco_antes=1300),
        item("Q2", "2026-11-17", "estadia (synthetic: keeps the reason)", min_stay=1, manter_reason=True),
        item("Afrodite", "2026-11-20", "chegada (synthetic: masks)", check_out="1111101", min_price=2600, min_stay=3),
        {"tipo": "apagar", "quarto": "Q2", "data": "2026-11-15", "motivo": "solta o teto (synthetic)"},
        {"tipo": "apagar", "quarto": "Q7", "data": "2026-11-12", "motivo": "solta o teto (synthetic tonight)"},
        {"tipo": "anuncio", "quarto": "Q7", "campo": "min", "valor": 850, "antes": 800, "motivo": "synthetic listing",
         "excecao": "dono", "ordem_id": "synthetic-listing"},
    ]
    return {"floors": floors, "stay-masks": stay, "orders-listing": orders_listing, "deletions": deletes,
            "occupancy": occupancy, "payload": payload}


SYNTHETIC_ORDERS = {
    "synthetic-orders-listing": [{"id": "manual-2026-11-12-q2-2026-11-18", "tipo": "manual", "quartos": ["Q2"], "de": "2026-11-18",
                        "ate": "2026-11-19", "data": "2026-11-12"}],
    "*": [
        {"id": "synthetic-below-floor", "tipo": "regra", "quartos": ["Q7"], "de": "2026-11-12", "ate": "2026-12-31",
         "data": "2026-11-12", "libera": ["abaixo_piso"]},
        {"id": "synthetic-stay-new", "tipo": "regra", "quartos": ["Q7"], "de": "2026-11-12", "ate": "2026-12-31",
         "data": "2026-11-10", "libera": ["estadia"]},
        {"id": "synthetic-stay-old", "tipo": "regra", "quartos": ["Q7"], "de": "2026-11-01", "ate": "2026-12-31",
         "data": "2026-11-01", "libera": ["estadia"]},
        {"id": "synthetic-listing", "tipo": "regra", "quartos": ["*"], "de": "2026-11-01", "ate": "2027-12-31",
         "data": "2026-11-12", "libera": ["anuncio"]},
    ],
}


def nights(first: dt.date, last: dt.date) -> list[str]:
    return [(first + dt.timedelta(days=i)).isoformat() for i in range((last - first).days + 1)]


# ---------------------------------------------------------------- freeze (read only from the engine's data)
def freeze(sources: dict[str, Path]) -> int:
    FROZEN_PLAN.mkdir(parents=True, exist_ok=True)
    scenarios = []
    plans: dict[str, list[dict]] = {}
    for day, folder in sorted(sources.items()):
        for name, file in (("final", "plano_final.json"), ("delete", "plano_pace_apagar.json")):
            if (folder / file).exists() and (day, name) != ("c1111", "delete"):
                plans[f"{day}-{name}"] = read(folder / file)["itens"]
    for name, items in synthetic_plans().items():
        plans[f"synthetic-{name}"] = items
    first_day = {"c1111": "2026-11-11", "c1112": "2026-11-12"}
    for name, items in plans.items():
        write_json(FROZEN_PLAN / f"plan-{name}.json", {"itens": items})
    for day, folder in sorted(sources.items()):
        used = [p for n, p in plans.items() if n.startswith(day) or (n.startswith("synthetic") and day == "c1112")]
        write_json(FROZEN_PLAN / f"calendar-{day}.json", reduce_calendar(read(folder / "mesclado.json"), used))
        write_json(FROZEN_PLAN / f"inventory-{day}.json", reduce_inventory(read(folder / "inv.json"), used))
    write_json(FROZEN_PLAN / "orders.json", {"ordens": reduce_orders(read(ENGINE_DATA / "ordens_dono.json"))})
    for name in plans:
        day = name.split("-")[0] if not name.startswith("synthetic") else "c1112"
        refreshed = max(r["last_refreshed_at"] for r in read(FROZEN_PLAN / f"calendar-{day}.json")["rooms"].values())
        now = (dt.datetime.fromisoformat(refreshed) + dt.timedelta(minutes=30)).astimezone(dt.timezone.utc)
        scenario = {"name": name, "plan": f"plan-{name}.json", "today": first_day[day], "calendar": f"calendar-{day}.json",
                    "now": now.isoformat().replace("+00:00", "Z"), "accept_stale": False, "max_hours": 6}
        scenarios.append(scenario)
        if name == "synthetic-occupancy":
            later = dict(scenario, name="synthetic-occupancy-stale", now=(now + dt.timedelta(hours=8)).isoformat().replace("+00:00", "Z"))
            scenarios += [later, dict(later, name="synthetic-occupancy-accepted", accept_stale=True)]
    write_json(FROZEN_PLAN / "scenarios.json", {"scenarios": scenarios, "synthetic_orders": SYNTHETIC_ORDERS})
    print(f"{len(plans)} plans, {len(scenarios)} scenarios frozen in {FROZEN_PLAN}")
    return 0


def touched(plans: list[list[dict]]) -> set[str]:
    out = set()
    for items in plans:
        for it in items:
            if it.get("data"):
                n = d(it["data"][:10])
                out |= {(n - DAY).isoformat(), n.isoformat(), (n + DAY).isoformat()}
    return out


def reduce_calendar(merged: dict, plans: list[list[dict]]) -> dict:
    keep = touched(plans)
    rooms = {}
    for code, room in merged["rooms"].items():
        days = [{k: x.get(k) for k in ("date", "price", "min_price", "multi_unit_occupancy", "booking_status")}
                for x in room.get("days") or [] if str(x["date"])[:10] in keep]
        rooms[code] = {"last_refreshed_at": room["last_refreshed_at"], "days": days}
    return {"hoje": merged["hoje"], "rooms": rooms}


def reduce_inventory(inv: dict, plans: list[list[dict]]) -> dict:
    keep = touched([[it for it in items if it.get("orfa_prova")] for items in plans])
    out = {"_lido_em": inv.get("_lido_em")}
    for code, nights_ in inv.items():
        if code.startswith("_"):
            continue
        out[code] = {n: {"numAvail": v.get("numAvail")} for n, v in nights_.items() if n in keep}
    return out


def reduce_orders(data: dict) -> list[dict]:
    return [{k: o[k] for k in ORDER_KEYS if k in o} for o in data["ordens"]]


# ---------------------------------------------------------------- dump
def orders_for(frozen: dict, scenario: str) -> dict:
    extra = frozen["synthetic_orders"]
    out = {o["id"]: o for o in read(FROZEN_PLAN / "orders.json")["ordens"]}
    for o in extra["*"] + extra.get(scenario, []):
        out[o["id"]] = o
    return out


def reason_of(motivo: str) -> str:
    for marker, code in REASONS:
        if marker in motivo:
            return code
    return "OWNER_ORDER"


def code_of(message: str) -> str:
    for marker, code in MESSAGES:
        if marker in message:
            if code == "__ceiling__":
                if "não recebe desconto" in message or "teto sem preco_antes" in message:
                    return "no_discount_room"
                if "abaixo do piso" in message:
                    return "ceiling_below_floor"
                continue
            if code == "__holiday__":
                return "below_holiday_safety_floor" if "limite de segurança" in message else "below_holiday_floor"
            if code == "__by_order__":
                return "below_quality_by_order" if "padrão de qualidade" in message else "below_floor_by_order"
            return code
    raise AssertionError(f"unmapped engine message: {message}")


def band_code(message: str) -> str:
    for marker, code in BAND:
        if marker in message:
            return code
    raise AssertionError(f"unmapped band message: {message}")


def subjects(items: list[dict]) -> list[tuple[str, int]]:
    """(subject, position in its list of the plugin's plan) of every engine item, in order."""
    count = {"item": 0, "deletion": 0, "listing": 0}
    out = []
    for it in items:
        subject = {"data": "item", "apagar": "deletion", "anuncio": "listing"}[it["tipo"]]
        out.append((subject, count[subject]))
        count[subject] += 1
    return out


def classify(v, items: list[dict]) -> dict:
    where = subjects(items)
    found: dict[str, dict[str, set]] = {"item": {}, "deletion": {}, "listing": {}}
    plan = set()
    for severity, messages in (("error", v.erros), ("warning", v.avisos)):
        for m in messages:
            hit = re.match(r"^(?:ERRO|aviso) item (\d+) \(", m)
            if hit:
                subject, index = where[int(hit.group(1)) - 1]
                found[subject].setdefault(str(index), set()).add((severity, code_of(m.split("): ", 1)[1])))
                continue
            hit = re.match(r"^(?:ERRO|aviso) Regra G (\d{4}-\d{2}-\d{2}):", m)
            if not hit:
                raise AssertionError(f"unmapped plan message: {m}")
            night = hit.group(1)
            index = next((i for (s, i), it in zip(where, items) if s == "item" and it["quarto"] == "Balcony" and it["data"] == night), None)
            entry = (severity, band_code(m))
            if index is None:
                plan.add(("Balcony", night) + entry)
            else:
                found["item"].setdefault(str(index), set()).add(entry)
    out = {s: {i: sorted(list(x) for x in codes) for i, codes in sorted(found[s].items(), key=lambda kv: int(kv[0]))}
           for s in found}
    out["plan"] = sorted(list(x) for x in plan)
    return out


def plugin_expected(c: dict, expected: dict, items: list[dict], prices: dict, orders: dict, today: dt.date) -> tuple[dict, list[str]]:
    """The expected codes with the deliberate divergences applied, and the ids of those that changed something."""
    out = copy.deepcopy(expected)
    applied = set()

    def add(index: int, entry: list[str], divergence: str) -> None:
        codes = out["item"].setdefault(str(index), [])
        if entry not in codes:
            codes.append(entry)
            codes.sort()
            applied.add(divergence)

    for (subject, index), it in zip(subjects(items), items):
        if subject != "item":
            continue
        q, night = c["por_curto"][it["quarto"]], d(it["data"])
        before = it.get("preco_antes")
        if before is None:
            before = (prices.get((it["quarto"], it["data"])) or {}).get("preco")
        if before is None and it.get("preco_antes") is None and (it["quarto"], it["data"]) not in prices:
            add(index, ["warning", "no_before"], "V3")
        if it.get("min_price") is None:
            continue
        floor = float(it["min_price"])
        if night == today and before is not None:
            applied_floor = round(float(q["base"]) * (1 + VP.piso_pct(q, floor) / 100), 2)
            if floor > float(before) or applied_floor > float(before):
                add(index, ["error", "raise_today"], "V4")
        order = ORD.ordem_valida(orders, it.get("ordem_id"), q["curto"], night) if it.get("excecao") == "dono" else None
        effective = VP.em_reais(q, VP.piso_pct(q, floor))
        if order is not None and order["id"] != PD.ORDEM_ID and "acima_15" not in (order.get("libera") or []) \
                and before and not comum.periodo_feriado(c, night) and "prote" not in str(it.get("motivo", "")).lower() \
                and effective > float(before) * 1.15 + 1:
            add(index, ["warning", "rise_above_max_step"], "V5")
    out["item"] = dict(sorted(out["item"].items(), key=lambda kv: int(kv[0])))
    for index, codes in out["item"].items():
        if ["error", "below_safety_limit"] in codes:
            kept = [c for c in codes if c[1] not in FLOOR_CODES]
            if kept != codes:
                applied.add("V1")
            out["item"][index] = kept
    return out, sorted(applied)


def hooks(c: dict, v, items: list[dict], orders: dict, today: dt.date) -> dict:
    """The answers of the strategy behind the owner's plan (scope, market floor, weekend floors) and of the band
    releases, computed with the engine's own helpers for every item of the plan."""
    acts, market, replaces, releases = {}, {}, {}, {}
    with PD.com_ordens(orders):
        for it in items:
            if it["tipo"] != "data":
                continue
            q, night = c["por_curto"][it["quarto"]], d(it["data"])
            key = f"{q['curto']}|{night.isoformat()}"
            fer = comum.periodo_feriado(c, night)
            replaces[key] = night.weekday() in (4, 5) and not fer and PD.ativo(night.isoformat(), orders, q["curto"])
            if it.get("excecao") == "dono" and it.get("ordem_id") == PD.ORDEM_ID:
                acts[f"{PD.ORDEM_ID}|{key}"] = v.escopo_plano(q, night) is None
                market[f"{PD.ORDEM_ID}|{key}"] = v.piso_mercado_do_plano(q, night)
            if q["curto"] == "Balcony":
                ids = [o.get("id") for o in orders.values() if ORD.cobre(o, "Balcony", night)
                       and "regra_g" in (o.get("libera") or []) and (not o.get("noites") or night.isoformat() in o["noites"])]
                if ids:
                    releases[key] = ids[0]
    return {"strategy_orders": [PD.ORDEM_ID], "acts": acts, "market_floor": market, "replaces_owner_floors": replaces,
            "band_releases": releases}


def run(c: dict, frozen: dict, scenario: dict) -> dict:
    items = read(FROZEN_PLAN / scenario["plan"])["itens"]
    orders = orders_for(frozen, scenario["name"])
    today = d(scenario["today"])
    now = dt.datetime.fromisoformat(scenario["now"].replace("Z", "+00:00"))
    prices = VP.precos_da_coleta(read(FROZEN_PLAN / scenario["calendar"]), agora=now)
    v = VP.Validador(c, today, copy.deepcopy(prices), horas_max=scenario["max_hours"], aceitar_velho=scenario["accept_stale"],
                     ordens=orders)
    v.validar(copy.deepcopy(items))
    expected = classify(v, items)
    php, applied = plugin_expected(c, expected, items, prices, orders, today)
    case = {"name": scenario["name"], "input": dict(scenario, hooks=hooks(c, v, items, orders, today)),
            "expected": expected, "valid": not v.erros}
    if php != expected:
        case["php_expected"] = php
        case["divergences"] = applied
        rejected = sorted(int(i) for i, codes in php["item"].items() if any(x[0] == "error" for x in codes))
        if not v.erros and rejected:
            case["php_rejected"] = rejected
    return case


def parity_fixture(c: dict, occupancy_codes: list[str]) -> dict:
    par = c["parametros"]
    rooms = c["quartos"]
    return {
        "_doc": "The plan section of the rule set with the reference engine's values (validar_plano.py, fatiar_payload.py, "
                "quartos.json). Written by tests/fixtures/tools/golden_plan.py; never edit by hand.",
        "plan": {
            "validator": {
                "calendar_max_age_hours": 6.0,
                "occupancy_reason_codes": occupancy_codes,
                "rise_warning_percent": 15.0,
                "rise_exempt_reason_codes": ["SOLD_OUT_PROTECTION"],
                "max_min_stay": 7,
                "market_evidence_min_length": 20,
                "no_discount_rooms": [q["curto"] for q in rooms if q.get("sem_desconto")],
                "last_minute": {
                    "enabled": True,
                    "rooms": [q["curto"] for q in rooms if q.get("regra_i")],
                    "max_days_out": int(par["regra_i_dias"]),
                    "max_discount": float(par["regra_i_desconto_max"]),
                    "max_sold_share": 0.7,
                    "last_unit_only_rooms": [q["curto"] for q in rooms if q.get("demanda") == "alta"],
                },
            },
            "payload": {"reason_max_length": 250, "slice_max_dates": FP.MAX_DATAS},
        },
        "weekly_reference": {k: {f: float(x) for f, x in v.items() if f in ("base", "min")}
                             for k, v in c["referencia_semanal"]["valores"].items()},
        "pms": c["pms"],
    }


def slices_of(c: dict, payload: dict, today: dt.date) -> list[dict]:
    return [{k: f[k] for k in ("n", "ferramenta", "quarto", "rotulo", "de", "ate", "datas", "args")}
            for f in FP.fatiar(c, payload, today, FP.MAX_DATAS)]


def git(*args: str) -> str:
    return subprocess.run(["git", "-C", str(REPO), *args], check=True, capture_output=True, text=True).stdout.strip()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def dump(generated_at: str) -> int:
    c = cfg()
    frozen = read(FROZEN_PLAN / "scenarios.json")
    occupancy_codes: list[str] = []  # the items say it themselves (reads_occupancy, from OCCUPANCY on the engine's text)
    validations, payloads, slices = [], [], []
    for scenario in frozen["scenarios"]:
        items = read(FROZEN_PLAN / scenario["plan"])["itens"]
        case = run(c, frozen, scenario)
        validations.append(case)
        if case["valid"]:
            today = d(scenario["today"])
            payload_case = {"name": scenario["name"], "input": {"plan": scenario["plan"], "today": scenario["today"]},
                            "expected": VP.montar_payload(c, copy.deepcopy(items))}
            slice_case = {"name": scenario["name"], "input": dict(payload_case["input"], max_dates=FP.MAX_DATAS),
                          "expected": slices_of(c, payload_case["expected"], today)}
            if case.get("php_rejected"):
                # the plugin writes the plan without the entries it refuses (a second validation, as the run does)
                kept = [it for (s_, i), it in zip(subjects(items), items) if s_ != "item" or i not in case["php_rejected"]]
                payload_case["php_expected"] = VP.montar_payload(c, copy.deepcopy(kept))
                slice_case["php_expected"] = slices_of(c, payload_case["php_expected"], today)
            payloads.append(payload_case)
            slices.append(slice_case)
    files = [
        ("validar_plano.validar.json", "validar_plano.Validador", "Ritmo\\Domain\\Plan\\Validator",
         "Errors and warnings of every entry of each frozen plan, as issue codes (MESSAGES of the generator).", validations),
        ("validar_plano.montar_payload.json", "validar_plano.montar_payload", "Ritmo\\Domain\\Plan\\Payload\\PayloadBuilder",
         "The payload of each plan that passes.", payloads),
        ("fatiar_payload.fatiar.json", "fatiar_payload.fatiar", "Ritmo\\Domain\\Plan\\Payload\\Slicer",
         "The calls of each payload in writing order.", slices),
    ]
    listing = []
    for name, function, target, notes, cases in files:
        write_golden(GOLDEN / name, {"function": function, "php_target": target, "notes": notes, "cases": cases})
        listing.append({"file": name, "function": function, "cases": len(cases)})
    write_json(PARITY, parity_fixture(c, occupancy_codes))
    engine_dirty = git("status", "--porcelain", "--", ENGINE_PATH) != ""
    if engine_dirty:
        print(f"WARNING: {ENGINE_PATH} has uncommitted changes; these goldens do not match any commit", file=sys.stderr)
    inputs = [FROZEN / "quartos.json", RULER, SEASONS] + sorted(FROZEN_PLAN.glob("*.json"))
    write_json(GOLDEN / "MANIFEST.json", {
        "generated_at": generated_at,
        "repo_commit": git("rev-parse", "HEAD"),
        "engine": ENGINE_PATH,
        "engine_commit": git("log", "-1", "--format=%H", "--", ENGINE_PATH),
        "engine_dirty": engine_dirty,
        "python": platform.python_version(),
        "frozen_inputs": {p.relative_to(FIXTURES).as_posix(): sha256(p) for p in inputs},
        "parity_fixture": {"file": PARITY.relative_to(FIXTURES).as_posix(), "sha256": sha256(PARITY)},
        "tolerance": {"amounts": 0.01, "percentages_and_integers": "exact"},
        "vocabulary": VOCABULARY,
        "divergences": DIVERGENCES,
        "files": listing,
    })
    for entry in listing:
        print(f"{entry['cases']:5d} cases  {entry['file']}")
    return 0


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", nargs="?", default="dump", choices=("dump", "freeze"))
    parser.add_argument("--source", action="append", default=[], help="NAME=DIR of a day's routine (freeze only)")
    parser.add_argument("--generated-at", default=dt.datetime.now(dt.timezone.utc).date().isoformat())
    args = parser.parse_args(argv)
    install_guards()
    if args.command == "freeze":
        return freeze({s.split("=", 1)[0]: Path(s.split("=", 1)[1]) for s in args.source})
    return dump(args.generated_at)


if __name__ == "__main__":
    sys.exit(main())

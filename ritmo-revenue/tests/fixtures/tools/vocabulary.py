"""Vocabulary shared by the fixture tools: the reference engine's terms and the plugin's."""

NIGHT_TYPE = {"semana": "weekday", "fds": "weekend", "feriado": "holiday"}
PACE_STATUS = {"frente": "ahead", "ritmo": "on_pace", "atras": "behind", "muito_atras": "very_behind"}
TARGET_STATUS = dict(PACE_STATUS, frente_perto="ahead_near")

# The engine keys its two properties by a short name; the parity preset gives each an id and a tier.
PROPERTY_ID = {"Recanto": "recanto", "Villa": "villa"}
PROPERTY_TIER = {"Recanto": "premium", "Villa": "standard"}

# pace.pisos_enviados names the floor that won with a sentence; each sentence starts with one of these.
FLOOR_LABEL = {
    "mínimo do anúncio": "listing_min",
    "limite de segurança": "reference_min_fraction",
    "piso de feriado": "holiday_floor",
    "piso do dono": "owner_floor",
    "padrão de qualidade": "quality_floor",
}
STEP_DIRECTION = {"sobe": "up", "desce": "down"}
# Why the cadence holds a step (dump_golden.core_line).
CADENCE = {"nao_chegou": "cadence_previous_step_not_pushed", "menos_24h": "cadence_step_within_24h"}
# Why a night is left out of the engine (dump_golden.core_scenario).
LEFT_OUT = {"esgotada": "sold_out_pending_m2", "sem_ancora": "no_market_anchor"}
# Where the anchor of a night came from (dump_golden.anchor_of): the plugin keeps own or fallback.
ANCHOR_ORIGIN = {"propria": "measured", "mes": "fallback", "mes_proximo": "fallback"}
# Booking statuses of the PMS.
BOOKING_STATUS = {"confirmed": "confirmed", "new": "new", "request": "request", "cancelled": "cancelled",
                  "black": "blocked", "inquiry": "inquiry"}

VOCABULARY = {
    "night_type": NIGHT_TYPE,
    "pace_status": PACE_STATUS,
    "property": PROPERTY_ID,
    "tier": PROPERTY_TIER,
    "floor_label": FLOOR_LABEL,
    "step_direction": STEP_DIRECTION,
    "cadence": CADENCE,
    "left_out": LEFT_OUT,
    "anchor_origin": ANCHOR_ORIGIN,
    "booking_status": BOOKING_STATUS,
}

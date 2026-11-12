"""Vocabulary shared by the fixture tools: the reference engine's terms and the plugin's."""

NIGHT_TYPE = {"semana": "weekday", "fds": "weekend", "feriado": "holiday"}
PACE_STATUS = {"frente": "ahead", "ritmo": "on_pace", "atras": "behind", "muito_atras": "very_behind"}
TARGET_STATUS = dict(PACE_STATUS, frente_perto="ahead_near")

# The engine keys its two properties by a short name; the parity preset gives each an id and a tier.
PROPERTY_ID = {"Recanto": "recanto", "Villa": "villa"}
PROPERTY_TIER = {"Recanto": "premium", "Villa": "standard"}

VOCABULARY = {
    "night_type": NIGHT_TYPE,
    "pace_status": PACE_STATUS,
    "property": PROPERTY_ID,
    "tier": PROPERTY_TIER,
}

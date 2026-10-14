"""Funções comuns aos scripts da Skill gestao-receita-pousadas.

Só biblioteca padrão. Nada aqui chama rede.
"""

from __future__ import annotations

import json
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

PASTA_SKILL = Path(__file__).resolve().parents[1]
RAIZ_REPO = Path(__file__).resolve().parents[4]
ARQ_QUARTOS = PASTA_SKILL / "dados" / "quartos.json"

# Reaproveita a leitura de calendário do programa de pacing (mesmo formato do MCP).
sys.path.insert(0, str(RAIZ_REPO / "automacao-pricelabs"))


def carregar_quartos(caminho: Path | str | None = None) -> dict:
    with open(caminho or ARQ_QUARTOS, encoding="utf-8") as f:
        cfg = json.load(f)
    cfg["por_curto"] = {q["curto"]: q for q in cfg["quartos"]}
    cfg["por_id"] = {q["id"]: q for q in cfg["quartos"]}
    return cfg


def ler_json(caminho: Path | str) -> dict:
    with open(caminho, encoding="utf-8") as f:
        texto = f.read()
    return json.loads(texto[texto.find("{"):])


def resultado_workflow(dados: dict) -> dict:
    """Aceita o arquivo de saída do Workflow ({"result": {...}}) ou o resultado direto."""
    return dados.get("result", dados) if isinstance(dados, dict) else {}


def data(texto: str) -> date:
    return date.fromisoformat(str(texto)[:10])


def periodo_feriado(cfg: dict, d: date) -> dict | None:
    for p in cfg.get("feriados", []):
        if data(p["inicio"]) <= d <= data(p["fim"]):
            return p
    return None


def dias(inicio: date, n: int) -> list[date]:
    return [inicio + timedelta(days=i) for i in range(n)]


DIAS_SEMANA = ["seg", "ter", "qua", "qui", "sex", "sáb", "dom"]


def reais(v) -> str:
    if v is None:
        return "—"
    return "R$ " + f"{float(v):,.0f}".replace(",", ".")


def hoje_brt(cfg: dict | None = None) -> date:
    """Data de hoje no fuso das pousadas (o contêiner roda em UTC: das 21h BRT em diante date.today() já é amanhã)."""
    from zoneinfo import ZoneInfo
    fuso = (cfg or {}).get("fuso") or "America/Sao_Paulo"
    return datetime.now(ZoneInfo(fuso)).date()


def ler_instante(texto) -> datetime | None:
    """ISO com ou sem fuso ('Z' vira UTC; sem fuso assume UTC). None quando vazio ou inválido."""
    if not texto:
        return None
    s = str(texto).strip().replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(s)
    except ValueError:
        return None
    return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)

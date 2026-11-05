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
# chaves de `parametros.estadia_por_dia_chegada.por_dia` (sem acento, segunda a domingo como date.weekday())
DIAS_CHAVE = ("seg", "ter", "qua", "qui", "sex", "sab", "dom")


def regra_dia_chegada(cfg: dict, d: date) -> dict | None:
    """Ordem do dono de 04/11/2026 21:00 BRT (`parametros.estadia_por_dia_chegada`): estadia mínima pelo DIA DE CHEGADA
    ("Abriremos apenas para uma diária. A diária de domingo, a diária de terça e a diária de quinta. Todas as outras
    deverão ser com no mínimo 2 dias"; "começa amanhã"). Devolve a regra se ativa e vigente na noite `d`; senão None."""
    r = (cfg.get("parametros") or {}).get("estadia_por_dia_chegada") or {}
    if not r.get("ativa") or (r.get("desde") and d < data(r["desde"])) or (r.get("ate") and d > data(r["ate"])):
        return None
    return r


def estadia_do_dia(cfg: dict, d: date) -> int | None:
    """Estadia mínima do dia da semana pela ordem de 04/11 (seg 2, ter 1, qua 2, qui 1, sex 2, sáb 2, dom 1), sem
    feriado, pacote nem temporada (isso é `validar_plano.estadia_exigida`); None fora da vigência da regra."""
    v = ((regra_dia_chegada(cfg, d) or {}).get("por_dia") or {}).get(DIAS_CHAVE[d.weekday()])
    return int(v) if v is not None else None


def estadia_pacote(cfg: dict, d: date) -> int | None:
    """Estadia mínima por data dos pacotes fechados do dono (03/11: Natal 23→25, Ano Novo 30→01 ou 30→03), em
    `regra_h_beds24.estadia_minima_por_data`; None fora deles."""
    v = (((cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("estadia_minima_por_data") or {}).get(d.isoformat())
    return int(v) if v is not None else None


def data_de_pacote(cfg: dict, d: date) -> bool:
    """A noite tem restrição por data de pacote do dono (03/11): estadia mínima, máxima ou chegada/saída por data
    (`regra_h_beds24`). A estadia dessas noites é do pacote: a regra do dia de chegada (04/11) não mexe nelas."""
    rh = ((cfg.get("parametros") or {}).get("regra_h_beds24") or {})
    ds = d.isoformat()
    return any(ds in (rh.get(k) or {}) for k in ("estadia_minima_por_data", "estadia_maxima_por_data", "override_por_data"))


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


def vendidas_total(r: dict, unidades: int) -> tuple[int, int]:
    """(vendidas, total) de uma noite do recálculo do PriceLabs. Unidade única (Afrodite): mu_occupancy vem "-1" e a
    venda aparece em num_bookings. A coleta resumida traz a ocupação em multi_unit_occupancy (31/10)."""
    mu = str(r.get("mu_occupancy") or r.get("multi_unit_occupancy") or "")
    if "/" in mu:
        v, t = mu.split("/", 1)
        try:
            return int(v), int(t)
        except ValueError:
            pass
    try:
        nb = int(float(r.get("num_bookings") or 0))
    except ValueError:
        nb = 0
    return (min(nb, unidades), unidades)


def esgotado(r: dict, unidades: int) -> bool:
    v, t = vendidas_total(r, unidades)
    return t > 0 and v >= t

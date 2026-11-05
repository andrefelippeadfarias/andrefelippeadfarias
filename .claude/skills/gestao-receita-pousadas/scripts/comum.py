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


def aguardando_dono(cfg: dict, quarto: str | None, d: date) -> str | None:
    """Noite fora da regra do dia de chegada até o dono decidir (`estadia_por_dia_chegada.aguardando_dono`, ex.:
    {"Afrodite": ["qua"], "motivo": "..."}): nem sobe nem desce no plano, no lote do Beds24 nem no validador. Devolve o
    motivo, ou None. Criada na revisão de 05/11 para as quartas da Afrodite (saída de sexta fechada); o dono decidiu em
    05/11 ~12:05 BRT ("pode abrir Afrodite, saída de sexta") e a chave foi apagada: hoje nenhuma noite aguarda."""
    r = regra_dia_chegada(cfg, d)
    esp = (r or {}).get("aguardando_dono") or {}
    if not quarto or not isinstance(esp.get(quarto), list) or DIAS_CHAVE[d.weekday()] not in esp[quarto]:
        return None
    return str(esp.get("motivo") or "aguardando decisão do dono")


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


def orfa_permitida(cfg: dict, d: date, sabado: bool = False) -> bool:
    """A liberação da órfã cabe na noite `d` quanto a pacote, festa e feriado. Nunca em noite de pacote do dono (Natal e
    Ano Novo, `data_de_pacote`) e, no sábado órfão (`sabado`), nunca no sábado de festa com exceção própria
    (`regra_h_beds24.sabados_excecao`, 26/12). Em feriado e véspera só com a decisão do dono de 05/11 ("As noites órfãs
    do feriado, a gente pode abrir"): `estadia_por_dia_chegada.orfa_em_feriado` para a noite órfã pela seguinte (ordem de
    04/11) e `regra_h_beds24.orfas.em_feriado` para o sábado órfão (ordem de 02/11). Fora disso, True. Quem chama confere
    o resto (regra ligada, dia da semana, noite livre e vizinha esgotada)."""
    if data_de_pacote(cfg, d):
        return False
    par = cfg.get("parametros") or {}
    rh = par.get("regra_h_beds24") or {}
    if sabado and d.isoformat() in (rh.get("sabados_excecao") or {}):
        return False
    if not periodo_feriado(cfg, d):
        return True
    if sabado:
        return bool((rh.get("orfas") or {}).get("em_feriado"))
    return bool((par.get("estadia_por_dia_chegada") or {}).get("orfa_em_feriado"))


def sexta_reaberta(cfg: dict, quarto: str | None, d: date) -> str | None:
    """Override de sexta que o dono tirou do quarto (`regra_h_beds24.sexta_reaberta_por_quarto`; dono 05/11: "Isso, pode
    abrir Afrodite, saída de sexta" tirou o noCheckOut da Afrodite de `sexta_override_por_quarto`): numa sexta fora de
    pacote devolve o override antigo, que a auditoria reabre ("none" no Beds24; no PriceLabs por data, check_out com a
    sexta em 1); senão None. Noite de pacote (25/12, 01/01) segue `override_por_data`."""
    if not quarto or d.weekday() != 4 or data_de_pacote(cfg, d):
        return None
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    if quarto in (rh.get("sexta_override_por_quarto") or {}):
        return None  # ordem mais nova do dono pôs o quarto de volta no override de sexta: vale ela
    return (rh.get("sexta_reaberta_por_quarto") or {}).get(quarto)


def mascara_valida(s) -> bool:
    """Máscara de chegada/saída do PriceLabs por data: 7 caracteres 0/1, de segunda a domingo."""
    return isinstance(s, str) and len(s) == 7 and not set(s) - {"0", "1"}


def abrir_dia(mascara: str, d: date) -> str:
    """A máscara (segunda a domingo) com o dia da semana de `d` em 1 e o resto como estava (ex.: check_out 1111011 de uma
    sexta vira 1111111)."""
    i = d.weekday()
    return mascara[:i] + "1" + mascara[i + 1:]


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

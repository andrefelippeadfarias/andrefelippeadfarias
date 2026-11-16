"""Config e ordens fixas para os testes (revisão de 16/11, Réveillon, 4ª verificação; noites de 19 a 23/11 fixas).

A operação do Réveillon 2026/27 (ordem dono-2026-11-16-reveillon-250k) muda o quartos.json real: o `aplicar-config` põe o
portão do 01→03 e as exceções das noites soltas em `regra_h_beds24.*_por_quarto`, e o `registrar` passa o `max` dos 6
quartos da lista a R$ 6.000. Os testes partem da config de ANTES dessas mudanças, para não ficarem vermelhos numa
operação prevista: em memória (`carregar`) e, nos testes de linha de comando, num arquivo temporário que substitui o
quartos.json de `comum` e do `reveillon_250k` (`quartos_em_arquivo`). Não é teste (o unittest só coleta test_*.py).

As noites de 19 a 23/11/2026 (Consciência Negra) estão em plena operação: em 16/11 o dono liberou a quinta 19/11 (o
quartos.json real passou o feriado a 20/11) e mandou lotar as duas pousadas, com ordens novas por data e `noites_fora` nas
ordens que elas substituem (inclusive as mudanças manuais de 15/11, que congelavam 19 a 22/11). Os testes conferem regras
nessas noites com o estado de ANTES, numa cópia fixa que não depende do que a operação ainda vai mudar:
  - feriado (`neutra`): a entrada inteira de FERIADOS_FIXOS no lugar de toda entrada do arquivo que encosta no período
    dela (casada pelo período, não pelo nome; o ano seguinte não é tocado);
  - ordens (`carregar_ordens` / `ordens_fixas`): sem as ordens por data (TIPOS_POR_DATA) que cobrem a janela e entraram
    no arquivo a partir do corte (CORTE_ORDENS, a ordem de lotar), e com as `noites_fora` da janela como estavam no
    corte (NOITES_FORA_FIXAS), seja qual for o motivo; fora da janela, as ordens ficam como no arquivo. As regras do dono
    (tipo `regra`, `restricao`, `acompanhamento`) ficam como no arquivo: são lógica aplicada por script, que os testes
    conferem como está.
"""

from __future__ import annotations

import contextlib
import copy
import json
import tempfile
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from unittest import mock

import comum
import ordens as _ordens
from comum import carregar_quartos

# chaves que a operação do Réveillon grava em parametros.regra_h_beds24 (portão e exceções, por quarto)
CHAVES_OPERACAO = ("override_por_data_por_quarto", "estadia_minima_por_data_por_quarto",
                   "override_por_data_por_quarto_motivo")

# feriados fixos dos testes: a entrada inteira, como no quartos.json antes de 16/11 (Consciência Negra de 19 a 21/11)
FERIADOS_FIXOS = (
    {"nome": "Consciência Negra", "inicio": "2026-11-19", "fim": "2026-11-21", "piso_real": None,
     "nota": "Piso real R$ 800 (não o padrão de R$ 1.000): decidido em 10/10 com o mercado a 23% a 36 dias e o Recanto já "
             "acima da mediana com hidro; rever na rotina completa de 20/10 (D-30)."},
)

# janela fixa das ordens (19 a 23/11/2026) e o corte: o registro da ordem de lotar (16/11 17:25 BRT); as ordens por data
# que entraram a partir dele e cobrem a janela ficam fora da cópia dos testes
JANELA = tuple((date(2026, 11, 19) + timedelta(days=i)).isoformat() for i in range(5))
CORTE_ORDENS = datetime(2026, 11, 16, 20, 25, tzinfo=timezone.utc)
TIPOS_POR_DATA = ("preco_por_data", "piso_por_data", "alvo_por_data", "manual")
# `noites_fora` dentro da janela no corte (ordens_dono.json de 0e44f8f); todas as outras ordens cobriam a janela inteira
NOITES_FORA_FIXAS = {
    "dono-2026-11-01-villa-base-mercado": {q: ["2026-11-22", "2026-11-23"] for q in ("VK7", "VK2", "Balcony")},
    "dono-2026-11-03-recanto-semana-hidro-1122": {q: ["2026-11-22"] for q in ("Q7", "Q2", "Double")},
    "dono-2026-11-03-recanto-semana-hidro-1123": {q: ["2026-11-23"] for q in ("Q7", "Q2", "Double")},
}


def _sobrepoe(p: dict, f: dict) -> bool:
    """A entrada de feriado `p` encosta no período de `f` (algum dia em comum)."""
    try:
        return comum.data(p["inicio"]) <= comum.data(f["fim"]) and comum.data(p.get("fim") or p["inicio"]) >= \
            comum.data(f["inicio"])
    except (KeyError, TypeError, ValueError):
        return False


def feriados_fixos(feriados: list) -> list:
    """Os feriados com as entradas de FERIADOS_FIXOS no lugar das que encostam no período delas (por período, não por
    nome: renomear, encurtar ou apagar a entrada real não muda o teste; a do ano seguinte, de mesmo nome, fica), em
    ordem de início. Devolve uma lista nova."""
    out = list(feriados or [])
    for f in FERIADOS_FIXOS:
        out = [p for p in out if not _sobrepoe(p, f)]
        pos = next((i for i, p in enumerate(out) if str(p.get("inicio", "")) > f["inicio"]), len(out))
        out.insert(pos, copy.deepcopy(f))
    return out


def neutra(cfg: dict) -> dict:
    """A config sem o que a operação do Réveillon muda: sem os `*_por_quarto` do portão e das exceções e com o `max`
    dos quartos da lista do máximo do anúncio no teto de fora (R$ 5.000, o de antes do `registrar`); e com os feriados
    fixos dos testes (feriados_fixos: Consciência Negra de 19 a 21/11). Muda e devolve `cfg` (com por_curto e por_id
    refeitos, quando ela os tinha)."""
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    for k in CHAVES_OPERACAO:
        rh.pop(k, None)
    lim = (cfg.get("parametros") or {}).get("maximo_anuncio_por_noite") or {}
    if lim.get("teto_fora") is not None:
        for q in cfg.get("quartos") or []:
            if q.get("curto") in (lim.get("quartos") or []):
                q["max"] = int(lim["teto_fora"])
    cfg["feriados"] = feriados_fixos(cfg.get("feriados"))
    if "por_curto" in cfg:
        cfg["por_curto"] = {q["curto"]: q for q in cfg["quartos"]}
        cfg["por_id"] = {q["id"]: q for q in cfg["quartos"]}
    return cfg


def carregar() -> dict:
    """carregar_quartos() do arquivo real, neutralizada."""
    return neutra(carregar_quartos())


def bruta() -> dict:
    """A config neutra como está no arquivo (sem por_curto e por_id)."""
    c = carregar()
    return {k: v for k, v in copy.deepcopy(c).items() if k not in ("por_curto", "por_id")}


def registrada_em(o: dict) -> datetime:
    """Quando a ordem entrou no arquivo: `criada_em`; sem ela, o fim do dia (BRT) de `data` (o `ordens.py manual` não
    grava a hora); sem as duas, o futuro."""
    t = o.get("criada_em")
    if t:
        try:
            m = datetime.fromisoformat(str(t).strip().replace("Z", "+00:00"))
            return m if m.tzinfo else m.replace(tzinfo=timezone.utc)
        except ValueError:
            pass
    try:
        d = date.fromisoformat(str(o.get("data"))[:10])
    except ValueError:
        return datetime.max.replace(tzinfo=timezone.utc)
    return datetime(d.year, d.month, d.day, tzinfo=timezone.utc) + timedelta(days=1, hours=3)


def cobre_janela(o: dict) -> bool:
    """O período da ordem (e as `noites`, quando ela as lista) tem alguma noite da JANELA."""
    if not (str(o.get("de") or "") <= JANELA[-1] and str(o.get("ate") or "9999") >= JANELA[0]):
        return False
    return not o.get("noites") or any(n in JANELA for n in o["noites"])


def _fixar_noites_fora(o: dict) -> None:
    """`noites_fora` da ordem com as noites da JANELA como no corte (NOITES_FORA_FIXAS) e as de fora dela como no
    arquivo; sem noite nenhuma, sai a chave (e o motivo). Muda `o`."""
    nf = o.get("noites_fora")
    fixo = NOITES_FORA_FIXAS.get(o.get("id")) or {}
    if not nf and not fixo:
        return
    if isinstance(nf, list) and not fixo:
        resto = [n for n in nf if n not in JANELA]
    else:
        if isinstance(nf, list):   # lista (todos os quartos da ordem) numa ordem com noites fixas por quarto
            nf = {q: list(nf) for q in o.get("quartos") or []}
        nf = nf or {}
        resto = {}
        for q in list(nf) + [q for q in fixo if q not in nf]:
            ns = [n for n in nf.get(q) or [] if n not in JANELA]
            if q in fixo:
                ns = sorted(set(ns) | set(fixo[q]))
            if ns:
                resto[q] = ns
    if resto:
        o["noites_fora"] = resto
    else:
        o.pop("noites_fora", None)
        o.pop("noites_fora_motivo", None)


def ordens_fixas(ordens: dict) -> dict:
    """As ordens ({id: ordem}) com as noites de 19 a 23/11 como estavam no corte: sem as ordens por data
    (TIPOS_POR_DATA) que cobrem a janela e entraram no arquivo a partir de CORTE_ORDENS (qualquer id) e com as
    `noites_fora` da janela de NOITES_FORA_FIXAS (qualquer motivo). Assim as mudanças manuais de 15/11 voltam a congelar
    19 a 22/11 e as ordens por data substituídas voltam a valer. Devolve uma cópia (não muda `ordens`)."""
    out = {}
    for k, o in ordens.items():
        if o.get("tipo") in TIPOS_POR_DATA and cobre_janela(o) and registrada_em(o) >= CORTE_ORDENS:
            continue
        o = copy.deepcopy(o)
        _fixar_noites_fora(o)
        out[k] = o
    return out


def carregar_ordens(caminho: Path | str | None = None) -> dict:
    """ordens.carregar_ordens() do arquivo real (ou de `caminho`), com a janela de 19 a 23/11 fixa (ordens_fixas)."""
    return ordens_fixas(_ordens.carregar_ordens(caminho))


@contextlib.contextmanager
def quartos_em_arquivo(cfg_bruta: dict | None = None):
    """Um quartos.json temporário (a config neutra, ou `cfg_bruta`) no lugar do real em `comum` e no `reveillon_250k`
    (quando importado): os comandos de linha de comando leem e gravam nele. Devolve o caminho."""
    import sys
    with tempfile.TemporaryDirectory() as d:
        arq = Path(d) / "quartos.json"
        arq.write_text(json.dumps(cfg_bruta if cfg_bruta is not None else bruta(), ensure_ascii=False, indent=1),
                       encoding="utf-8")
        with contextlib.ExitStack() as pilha:
            pilha.enter_context(mock.patch.object(comum, "ARQ_QUARTOS", arq))
            mod = sys.modules.get("reveillon_250k")
            if mod is not None:
                pilha.enter_context(mock.patch.object(mod, "ARQ_QUARTOS", arq))
            yield arq

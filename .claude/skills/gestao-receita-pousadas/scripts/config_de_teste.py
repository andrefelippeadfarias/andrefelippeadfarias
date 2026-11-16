"""Config fixa para os testes (revisão de 16/11, Réveillon, 4ª verificação).

A operação do Réveillon 2026/27 (ordem dono-2026-11-16-reveillon-250k) muda o quartos.json real: o `aplicar-config` põe o
portão do 01→03 e as exceções das noites soltas em `regra_h_beds24.*_por_quarto`, e o `registrar` passa o `max` dos 6
quartos da lista a R$ 6.000. Os testes partem da config de ANTES dessas mudanças, para não ficarem vermelhos numa
operação prevista: em memória (`carregar`) e, nos testes de linha de comando, num arquivo temporário que substitui o
quartos.json de `comum` e do `reveillon_250k` (`quartos_em_arquivo`). Não é teste (o unittest só coleta test_*.py).
"""

from __future__ import annotations

import contextlib
import copy
import json
import tempfile
from pathlib import Path
from unittest import mock

import comum
from comum import carregar_quartos

# chaves que a operação do Réveillon grava em parametros.regra_h_beds24 (portão e exceções, por quarto)
CHAVES_OPERACAO = ("override_por_data_por_quarto", "estadia_minima_por_data_por_quarto",
                   "override_por_data_por_quarto_motivo")


def neutra(cfg: dict) -> dict:
    """A config sem o que a operação do Réveillon muda: sem os `*_por_quarto` do portão e das exceções e com o `max`
    dos quartos da lista do máximo do anúncio no teto de fora (R$ 5.000, o de antes do `registrar`). Muda e devolve
    `cfg` (com por_curto e por_id refeitos, quando ela os tinha)."""
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    for k in CHAVES_OPERACAO:
        rh.pop(k, None)
    lim = (cfg.get("parametros") or {}).get("maximo_anuncio_por_noite") or {}
    if lim.get("teto_fora") is not None:
        for q in cfg.get("quartos") or []:
            if q.get("curto") in (lim.get("quartos") or []):
                q["max"] = int(lim["teto_fora"])
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

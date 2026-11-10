"""Plano do dono de 09/11/2026 (ordem `dono-2026-11-09-plano-nov-fev`): preço por noite e suíte de 10/11/2026 a
28/02/2027, a partir da régua de mercado por noite (`dados/regua_dono.json`, varredura da Booking de 09-10/11).

Regras aprovadas pelo dono ("Pode aplicar tudo, com exceção da questão do balcone"):
- fim de semana (sexta e sábado fora de feriado): abre na âncora de mercado da suíte (Moinhos no p40 do quarto mais
  barato; Panorâmicas e King Spa 5% acima da mediana do quarto com hidro dos hotéis de nota abaixo de 9; Afrodite 15%
  acima da Panorâmica I), nunca acima do pedido de 09/11;
- feriado e festas do Recanto (Natal, entre festas, Réveillon): abre na mediana do mercado com hidro (Moinhos no p40),
  nunca acima do pedido de 09/11; as festas da Villa ficam como estão;
- escada: +7% por unidade só depois de metade das unidades da suíte vendidas, até o teto (fim de semana: Moinhos p50 e
  as outras p65 do quarto com hidro; feriado: Moinhos p65, as outras p75; festas: p75);
- a 7 dias (D7 a D0), noite de fim de semana, feriado ou festa com menos de 60% da suíte vendida desce 10%;
- piso de fim de semana por suíte (no lugar do piso único de R$ 850 no Genius 3): o próprio preço do plano, nunca abaixo
  do padrão de qualidade (R$ 1.000 enviados nas suítes com banheira);
- dia de semana: o piso do dono (R$ 500 no Genius 3) vale até D8; de D7 a D0 o piso passa a ser a mediana do mercado
  com hidro da suíte (x 1,05 na Panorâmica I);
- Balcony (decisão 4 recusada): segue a Regra G (10% abaixo da suíte com banheira livre mais barata da Villa); só na
  noite com 80% ou mais das suítes com banheira da Villa vendidas vai ao preço de mercado do quarto sem banheira,
  nunca acima da Regra G.

Valores em preço PÚBLICO (Booking, sem desconto) convertidos em enviado pelo fator público medido na própria varredura
(por pousada, mês e tipo de noite; a Villa tem promoção ativa em algumas datas). Só biblioteca padrão; sem rede.
"""

from __future__ import annotations

import json
import math
from datetime import date
from functools import lru_cache
from pathlib import Path

PASTA = Path(__file__).resolve().parents[1]
ARQ_REGUA = PASTA / "dados" / "regua_dono.json"
ORDEM_ID = "dono-2026-11-09-plano-nov-fev"
DE, ATE = "2026-11-10", "2027-02-28"

POUSADA = {"Q7": "Recanto", "Double": "Recanto", "Q2": "Recanto", "Afrodite": "Recanto",
           "VK7": "Villa", "VK2": "Villa", "Balcony": "Villa"}
BANHEIRA_VILLA = ("VK7", "VK2")

# grupo do plano por tipo de noite (faixas_dono.tipo_da_noite)
GRUPO = {"semana": "semana", "inverno_semana": "semana", "fds": "fds", "inverno_fds": "fds", "feriado": "feriado",
         "natal": "festas", "entre_festas": "festas", "reveillon": "festas"}

# (série da régua, percentil, multiplicador) do preço público de abertura
ABERTURA = {
    "fds": {"Q7": ("lista", "40", 1.0), "Q2": ("pares_hidro", "50", 1.05), "Double": ("pares_hidro", "50", 1.05),
            "VK7": ("pares_hidro", "50", 1.05), "VK2": ("pares_hidro", "50", 1.05)},
    "feriado": {"Q7": ("hidro", "40", 1.0), "Q2": ("hidro", "50", 1.0), "Double": ("hidro", "50", 1.0),
                "VK7": ("hidro", "50", 1.0), "VK2": ("hidro", "50", 1.0)},
    "festas": {"Q7": ("hidro", "40", 1.0), "Q2": ("hidro", "50", 1.0), "Double": ("hidro", "50", 1.0)},
}
TETO = {
    "fds": {"Q7": ("hidro", "50"), "*": ("hidro", "65")},
    "feriado": {"Q7": ("hidro", "65"), "*": ("hidro", "75")},
    "festas": {"*": ("hidro", "75")},
}
# dia de semana, de D7 a D0: piso na mediana do mercado com hidro (Panorâmica I 5% acima, como na abertura do plano)
SEMANA_ULTIMA = {"Q7": 1.0, "Q2": 1.0, "Double": 1.05, "VK7": 1.0, "VK2": 1.0}
# Balcony com 80% das suítes com banheira da Villa vendidas: preço de mercado do quarto sem banheira
BALCONY_MERCADO = {"fds": ("pares_lista", "50"), "semana": ("lista", "40"), "feriado": ("lista", "50"),
                   "festas": ("lista", "50")}

AFRODITE_SOBRE_DOUBLE = 1.15
PASSO_ESCADA = 0.07
ESCADA_APOS_FRACAO = 0.5
DESCIDA_DIAS = 7
DESCIDA_OCUPACAO_MAX = 0.6
DESCIDA = 0.10
PISO_SEMANA_ATE_DIAS = 8        # o piso do dono de dia de semana vale de D8 em diante
GATILHO_BALCONY = 0.8           # fração das suítes com banheira da Villa vendidas
PADRAO_QUALIDADE = 1000.0       # limites_seguranca.padrao_qualidade_diaria (enviado; sexta, sábado e feriado)


@lru_cache(maxsize=2)
def _regua(caminho: str) -> dict:
    with open(caminho, encoding="utf-8") as f:
        return json.load(f)


def regua(caminho: Path | str | None = None) -> dict:
    return _regua(str(caminho or ARQ_REGUA))


def noite_regua(n: str, caminho: Path | str | None = None) -> dict | None:
    return (regua(caminho).get("noites") or {}).get(n)


def vale(n: str) -> bool:
    return DE <= n <= ATE


def grupo(tipo: str) -> str:
    return GRUPO.get(tipo, "semana")


def ancora(rn: dict, serie: str, pct: str) -> float | None:
    v = (rn.get(serie) or {}).get(str(pct))
    return float(v) if v is not None else None


def fator(rn: dict, q: str) -> float:
    return float(rn["fator_publico"][POUSADA[q]])


def governa(q: str, tipo: str) -> bool:
    """O plano fixa o preço da suíte nesta noite? Dia de semana e Balcony não (só mexem no piso / na Regra G);
    festas da Villa não (ficam como estão: decisão 6 é do Recanto)."""
    g = grupo(tipo)
    if q == "Balcony" or g == "semana":
        return False
    if g == "festas" and POUSADA[q] == "Villa":
        return False
    return True


def abertura_publico(q: str, rn: dict, tipo: str) -> float | None:
    """Preço público de abertura (0 vendida), já limitado ao pedido de 09/11. Afrodite = Panorâmica I x 1,15."""
    g = grupo(tipo)
    if not governa(q, tipo):
        return None
    if q == "Afrodite":
        d = abertura_publico("Double", rn, tipo)
        return None if d is None else d * AFRODITE_SOBRE_DOUBLE
    serie, pct, mult = ABERTURA[g][q]
    v = ancora(rn, serie, pct)
    if v is None:
        return None
    v *= mult
    ped = (rn.get("pedido_0911") or {}).get(q)
    if ped:
        v = min(v, float(ped) * fator(rn, q))
    return v


def teto_publico(q: str, rn: dict, tipo: str) -> float | None:
    g = grupo(tipo)
    if not governa(q, tipo):
        return None
    if q == "Afrodite":
        d = teto_publico("Double", rn, tipo)
        return None if d is None else d * AFRODITE_SOBRE_DOUBLE
    serie, pct = TETO[g].get(q) or TETO[g]["*"]
    v = ancora(rn, serie, pct)
    ab = abertura_publico(q, rn, tipo)
    if v is None:
        return ab
    return max(v, ab or 0.0)


def degraus_escada(vendidas: int, unidades: int) -> int:
    """Quantos degraus de +7% a próxima unidade leva: 0 até metade vendida (a unidade ceil(u/2) ainda abre no preço de
    abertura); depois 1 por unidade."""
    meio = math.ceil(unidades * ESCADA_APOS_FRACAO)
    return max(0, vendidas + 1 - meio) if vendidas < unidades else max(0, unidades - meio)


def desce(D: int, vendidas: int, unidades: int, tipo: str) -> bool:
    """A 7 dias (D7 a D0), fim de semana, feriado ou festa com menos de 60% da suíte vendida: -10%."""
    return grupo(tipo) != "semana" and 0 <= D <= DESCIDA_DIAS and unidades > 0 \
        and vendidas / unidades < DESCIDA_OCUPACAO_MAX


def preco_publico(q: str, rn: dict, tipo: str, vendidas: int, unidades: int, D: int,
                  publico_double: float | None = None) -> dict | None:
    """Preço público da próxima unidade: abertura, escada depois da metade (até o teto), descida a 7 dias.
    Afrodite: 15% acima do preço da Panorâmica I na noite (`publico_double`; sem ele, da abertura dela)."""
    if not governa(q, tipo):
        return None
    if q == "Afrodite" and publico_double is not None:
        v = publico_double * AFRODITE_SOBRE_DOUBLE
        return {"publico": v, "abertura": abertura_publico(q, rn, tipo), "teto": None, "degraus": 0,
                "desceu": False, "regra": "Afrodite 15% acima da Panorâmica I"}
    ab, tt = abertura_publico(q, rn, tipo), teto_publico(q, rn, tipo)
    if ab is None:
        return None
    k = degraus_escada(vendidas, unidades)
    v = min(ab * (1 + PASSO_ESCADA) ** k, max(tt or ab, ab))
    dz = desce(D, vendidas, unidades, tipo)
    if dz:
        v *= 1 - DESCIDA
    return {"publico": v, "abertura": ab, "teto": tt, "degraus": k, "desceu": dz,
            "regra": f"abertura {ABERTURA[grupo(tipo)].get(q, ('', '', 1))[:2]}"}


def enviado(q: str, rn: dict, publico: float, tipo: str) -> float:
    """Público ÷ fator medido; suítes com banheira nunca abaixo do padrão de qualidade em fim de semana, feriado e
    festas."""
    e = publico / fator(rn, q)
    if q != "Balcony" and grupo(tipo) != "semana":
        e = max(e, PADRAO_QUALIDADE)
    return e


def piso_semana_ultima(q: str, rn: dict, D: int) -> float | None:
    """Dia de semana de D7 a D0: piso enviado na mediana do mercado com hidro (None fora disso ou na Afrodite/Balcony,
    que seguem as regras delas)."""
    if q not in SEMANA_ULTIMA or not (0 <= D < PISO_SEMANA_ATE_DIAS):
        return None
    v = ancora(rn, "hidro", "50")
    return None if v is None else v * SEMANA_ULTIMA[q] / fator(rn, q)


def balcony_no_mercado(vendidas_banheira: int, unidades_banheira: int = 9) -> bool:
    return unidades_banheira > 0 and vendidas_banheira / unidades_banheira >= GATILHO_BALCONY - 1e-9


def balcony_mercado_enviado(rn: dict, tipo: str) -> float | None:
    serie, pct = BALCONY_MERCADO[grupo(tipo)]
    v = ancora(rn, serie, pct)
    return None if v is None else v / fator(rn, "Balcony")

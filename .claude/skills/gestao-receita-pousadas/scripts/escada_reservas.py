"""Escada de subida por reserva (dono, 26/10/2026).

"A cada reserva que entrar, vamos aumentando o preço gradualmente até chegarmos no nível de médio-alto padrão
[...] vamos começar com esse mínimo, que está pré-definido já agora, e vamos alavancar até chegar no valor de
alto padrão, para que possamos melhorar o ticket médio dos nossos hotéis."

Cada reserva nova (não lote do Beds24, não edição) sobe um degrau no quarto e nas noites da estadia:
  novo enviado = enviado atual × (1 + escada.passo) ^ reservas, arredondado para cima, até o teto.
Teto (alto padrão) = piso do dono no preço do hóspede mais descontado (Genius 3):
  domingo a quinta: limites_seguranca.piso_vitrine_semana_suites ÷ fator_vitrine_semana da pousada;
  sexta e sábado:   limites_seguranca.piso_vitrine_fds_suites ÷ fator_vitrine_fds da pousada;
  Balcony:          escada.fator_balcony × o teto da VK2 (Regra G).
A noite de hoje (D0) não sobe: é a última chance de vender a unidade. Cancelamento desce um degrau.

Uso:
  python3 escada_reservas.py passo --quarto Q7 --data 2026-10-27 --atual 739 [--reservas 1] [--hoje 2026-10-26]
  python3 escada_reservas.py ordem --q7 791 --q2 747 --double 755 --afrodite 800
"""

from __future__ import annotations

import argparse
import math
import sys
from datetime import date

from comum import carregar_quartos, data, hoje_brt


def teto(cfg: dict, curto: str, d: date) -> float:
    q = cfg["por_curto"][curto]
    lim, par = cfg["limites_seguranca"], cfg["parametros"]
    if curto == "Balcony":
        return round(par["escada"]["fator_balcony"] * teto(cfg, "VK2", d), 2)
    if d.weekday() in (4, 5):  # noites de sexta e sábado
        return round(lim["piso_vitrine_fds_suites"] / par["fator_vitrine_fds"][q["pousada"]], 2)
    return round(lim["piso_vitrine_semana_suites"] / par["fator_vitrine_semana"][q["pousada"]], 2)


def passo(cfg: dict, curto: str, d: date, atual: float, reservas: int = 1, hoje: date | None = None) -> dict:
    """Próximo degrau do enviado. reservas < 0 desce (cancelamento), sem passar do valor de partida da regra."""
    hoje = hoje or hoje_brt(cfg)
    lim = teto(cfg, curto, d)
    if d <= hoje:
        return {"novo": atual, "teto": lim, "motivo": "noite de hoje ou passada: não sobe"}
    fator = (1 + cfg["parametros"]["escada"]["passo"]) ** reservas
    novo = math.ceil(atual * fator) if reservas >= 0 else math.floor(atual * fator)
    if reservas > 0:
        novo = min(novo, math.floor(lim))
        if novo <= atual:
            return {"novo": atual, "teto": lim, "motivo": "já no teto (alto padrão)"}
    return {"novo": novo, "teto": lim, "motivo": f"{reservas:+d} degrau(s)"}


def ordem(q7: float, q2: float, double: float, afrodite: float, premio: float = 0.05) -> dict:
    """Ordem do Recanto: Moinhos ≤ Panorâmica II ≤ Panorâmica I < Afrodite (+5%). Só sobe, nunca desce."""
    q2n = max(q2, q7)
    dn = max(double, q2n)
    an = max(afrodite, math.ceil(dn * (1 + premio)))
    return {"Q7": q7, "Q2": q2n, "Double": dn, "Afrodite": an}


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("passo")
    p.add_argument("--quarto", required=True)
    p.add_argument("--data", required=True)
    p.add_argument("--atual", type=float, required=True)
    p.add_argument("--reservas", type=int, default=1)
    p.add_argument("--hoje")
    o = sub.add_parser("ordem")
    for nome in ("q7", "q2", "double", "afrodite"):
        o.add_argument(f"--{nome}", type=float, required=True)
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    if a.cmd == "passo":
        d = data(a.data)
        r = passo(cfg, a.quarto, d, a.atual, a.reservas, data(a.hoje) if a.hoje else None)
        q = cfg["por_curto"][a.quarto]
        fds = d.weekday() in (4, 5)
        fg = cfg["parametros"]["fator_vitrine_fds" if fds else "fator_vitrine_semana"][q["pousada"]]
        print(f"{a.quarto} {a.data}: R$ {a.atual:.0f} → R$ {r['novo']:.0f} enviados "
              f"(Genius 3 ~R$ {r['novo'] * fg:.0f}); teto R$ {r['teto']:.0f}; {r['motivo']}")
    else:
        print(ordem(a.q7, a.q2, a.double, a.afrodite))
    return 0


if __name__ == "__main__":
    sys.exit(main())

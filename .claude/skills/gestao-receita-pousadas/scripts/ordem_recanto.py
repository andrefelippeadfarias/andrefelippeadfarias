"""Ordem de preço do Recanto (dono, 21/10): "Os valores da Suíte Panorâmica II devem sempre estar entre a Moinhos e a
Panorâmica I." No PriceLabs: Suíte Moinhos = Queen (7) (Q7), Suíte Panorâmica I = Double, Suíte Panorâmica II = Queen (2) (Q2).

Uso:
  python3 ordem_recanto.py auditar --hoje AAAA-MM-DD Q7=<recalculo> Double=<recalculo> Q2=<recalculo>
  python3 ordem_recanto.py plano   --hoje AAAA-MM-DD Q7=<recalculo> Double=<recalculo> Q2=<recalculo> --saida plano.json

E a Suíte Afrodite (dono, 22/10: "A Suíte Afrodite tem que ser a mais cara, com valor mais alto da Recanto, pois ela é
considerada super luxo"): toda noite acima das outras três. Dono 27/10 ("Pode ser", reposicionamento da Afrodite):
  - domingo a quinta fora de feriado: pelo menos 20% sobre a mais cara das outras (Afrodite.premio_minimo_semana);
  - sexta, sábado e feriado: pelo menos 5% (Afrodite.premio_minimo);
  - sexta e sábado fora de feriado: faixa alta do mercado, sem passar do topo, R$ 1.600 a R$ 1.800 por noite na vitrine
    da Booking (parametros.afrodite_fds_publico ÷ regra_j.fator_publico do Recanto): acima disso o plano põe o teto.

Para cada noite, com os preços do recálculo do PriceLabs: menor = min(Q7, Double), maior = max(Q7, Double). A Q2 precisa
ficar entre os dois. Tolerância: 1% da base do quarto + R$ 1 (o PriceLabs só aceita piso e teto em percentual inteiro da
base; na Q2, 1% são R$ 18,70). `plano` gera, só para as noites fora da faixa, piso e teto da Q2 em reais
(o payload do validar_plano.py converte para percent_base):
  - piso = o maior entre o piso da regra da Q2 (dono ou feriado; calendario_12m.exigencias) e o menor preço;
  - teto = 75% do caminho do menor para o maior (perto da Panorâmica I, com folga para os dois lados), nunca abaixo do piso.
Os itens vão com excecao "dono" (é a ordem do dono que tira a Q2 da regra antiga de "sem desconto"). Os preços das três
mudam todo dia: a rotina completa roda `auditar` e regrava o que saiu da faixa.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from calendario_12m import exigencias, ler_recalculo  # noqa: E402
from comum import carregar_quartos, esgotado, periodo_feriado  # noqa: E402

POSICAO = 0.75


def tol(cfg: dict, quarto: str) -> float:
    return 0.01 * float(cfg["por_curto"][quarto]["base"]) + 1


def faixa(cfg: dict, d: dt.date, q7: dict, db: dict, q2: dict) -> dict:
    a, b, c = float(q7["price"]), float(db["price"]), float(q2["price"])
    menor, maior = min(a, b), max(a, b)
    piso_regra = exigencias(cfg, cfg["por_curto"]["Q2"], d)["piso"] or 0
    piso = round(max(piso_regra, menor))
    teto = round(max(piso, menor + POSICAO * (maior - menor)))
    t = tol(cfg, "Q2")
    fora = c > maior + t or c < menor - t or float(q2.get("min_price") or 0) > maior + t
    final = min(max(c, piso), teto) if fora else c
    return {"moinhos": a, "panoramica_1": b, "panoramica_2": c, "piso": piso, "teto": teto, "fora": fora, "q2_final": final}


def premio_afrodite(cfg: dict, d: dt.date) -> float:
    q = cfg["por_curto"]["Afrodite"]
    if d.weekday() in (4, 5) or periodo_feriado(cfg, d):
        return float(q["premio_minimo"])
    return float(q.get("premio_minimo_semana", q["premio_minimo"]))


def faixa_fds_afrodite(cfg: dict, d: dt.date):
    """(piso, teto) enviados da faixa de fim de semana, ou None em dia de semana e feriado."""
    if d.weekday() not in (4, 5) or periodo_feriado(cfg, d):
        return None
    par = cfg["parametros"]
    fat = par["regra_j"]["fator_publico"]["Recanto"]
    lo, hi = par["afrodite_fds_publico"]
    return round(lo / fat), round(hi / fat)


def faixa_afrodite(cfg: dict, d: dt.date, q7: dict, db: dict, q2_final: float, af: dict) -> dict:
    """Afrodite acima das outras três: piso = o maior entre o piso da regra e a mais cara das outras × (1 + prêmio).
    Fim de semana fora de feriado: teto na faixa alta do mercado, nunca abaixo do piso da ordem."""
    outras = max(float(q7["price"]), float(db["price"]), float(q2_final))
    premio = premio_afrodite(cfg, d)
    piso_regra = exigencias(cfg, cfg["por_curto"]["Afrodite"], d)["piso"] or 0
    for ex in cfg["limites_seguranca"].get("excecoes_dono", []) or []:
        # piso menor aberto pelo dono (26/10: R$ 300 no Genius 3 até 08/11) vale também aqui
        if ex.get("quarto") == "Afrodite" and ex["de"] <= d.isoformat() <= ex["ate"]:
            piso_regra = min(piso_regra, float(ex["limite"]))
    piso = round(max(piso_regra, outras * (1 + premio)))
    preco = float(af["price"])
    abaixo = preco < outras * (1 + premio) - tol(cfg, "Afrodite")
    teto = None
    fds = faixa_fds_afrodite(cfg, d)
    if fds and preco > fds[1] + tol(cfg, "Afrodite"):
        teto = max(fds[1], piso)
        piso = max(piso, min(fds[0], round(preco)))
    return {"outras": outras, "afrodite": preco, "piso": piso, "teto": teto, "premio": premio,
            "fora": abaixo or teto is not None}


def comparar(cfg, cal7, caldb, cal2, de, ate, calaf=None):
    out, d = [], de
    while d <= ate:
        s = d.isoformat()
        if s in cal7 and s in caldb and s in cal2:
            # Regra J (dono, 27/10): suíte esgotada vai ao topo do mercado e sai da ordem; a ordem vale entre as que vendem
            esg = {k: esgotado(c[s], cfg["por_curto"][k]["unidades"]) for k, c in (("Q7", cal7), ("Double", caldb), ("Q2", cal2))}
            f = faixa(cfg, d, cal7[s], caldb[s], cal2[s])
            if f["fora"] and not any(esg.values()):
                out.append({"data": s, "quarto": "Q2", **f})
            if calaf and s in calaf and not esgotado(calaf[s], 1):
                abertos = [c[s] for k, c in (("Q7", cal7), ("Double", caldb)) if not esg[k]]
                if not abertos:
                    d += dt.timedelta(days=1)
                    continue
                f = dict(f, q2_final=(f["q2_final"] if not esg["Q2"] else 0))
                ref = max(abertos, key=lambda r: float(r["price"]))
                g = faixa_afrodite(cfg, d, ref, ref, f["q2_final"], calaf[s])
                if g["fora"]:
                    out.append({"data": s, "quarto": "Afrodite", **g})
        d += dt.timedelta(days=1)
    return out


def montar_plano(fora: list[dict], hoje: dt.date) -> dict:
    itens = []
    for x in fora:
        if x["quarto"] == "Q2":
            itens.append({"tipo": "data", "quarto": "Q2", "data": x["data"], "min_price": x["piso"], "max_price": x["teto"],
                          "preco_antes": x["panoramica_2"], "excecao": "dono",
                          "motivo": (f"Dono 21/10: Panorâmica II entre a Moinhos (R$ {x['moinhos']:.0f}) e a Panorâmica I "
                                     f"(R$ {x['panoramica_1']:.0f}); estava R$ {x['panoramica_2']:.0f}")})
        else:
            it = {"tipo": "data", "quarto": "Afrodite", "data": x["data"], "min_price": x["piso"],
                  "preco_antes": x["afrodite"],
                  "motivo": (f"Dono 22 e 27/10: Afrodite a mais cara do Recanto ({x['premio']:.0%} sobre a mais cara "
                             f"das outras, R$ {x['outras']:.0f}); estava R$ {x['afrodite']:.0f}")}
            if x.get("teto"):
                it["max_price"] = x["teto"]
                it["motivo"] = (f"Dono 27/10: Afrodite no fim de semana na faixa alta do mercado, sem passar do topo "
                                f"(vitrine R$ 1.600 a 1.800); estava R$ {x['afrodite']:.0f}")
            itens.append(it)
    return {"hoje": hoje.isoformat(), "itens": itens}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("modo", choices=["auditar", "plano"])
    ap.add_argument("--hoje", required=True)
    ap.add_argument("--de")
    ap.add_argument("--ate", help="padrão: hoje + 366 dias")
    ap.add_argument("--saida", default="plano_ordem_recanto.json")
    ap.add_argument("recalculos", nargs="+", help="Q7=arquivo Double=arquivo Q2=arquivo [Afrodite=arquivo]")
    a = ap.parse_intermixed_args(argv)
    cfg = carregar_quartos()
    hoje = dt.date.fromisoformat(a.hoje)
    de = dt.date.fromisoformat(a.de) if a.de else hoje
    ate = dt.date.fromisoformat(a.ate) if a.ate else hoje + dt.timedelta(days=366)
    rec = dict(x.split("=", 1) for x in a.recalculos)
    fora = comparar(cfg, ler_recalculo(rec["Q7"]), ler_recalculo(rec["Double"]), ler_recalculo(rec["Q2"]), de, ate,
                    ler_recalculo(rec["Afrodite"]) if rec.get("Afrodite") else None)
    q2 = [x for x in fora if x["quarto"] == "Q2"]
    af = [x for x in fora if x["quarto"] == "Afrodite"]
    print(f"{len(q2)} noites com a Panorâmica II fora da faixa entre a Moinhos e a Panorâmica I, de {de} a {ate}")
    for x in q2[:5]:
        print(f"  {x['data']}: Moinhos R$ {x['moinhos']:.0f} | Panorâmica II R$ {x['panoramica_2']:.0f} | "
              f"Panorâmica I R$ {x['panoramica_1']:.0f} -> piso R$ {x['piso']} / teto R$ {x['teto']}")
    if rec.get("Afrodite"):
        print(f"{len(af)} noites com a Afrodite fora da ordem (20% dom-qui, 5% sex-sáb e feriado) ou acima da faixa do fds")
        for x in af[:5]:
            print(f"  {x['data']}: Afrodite R$ {x['afrodite']:.0f} | mais cara das outras R$ {x['outras']:.0f} -> piso R$ {x['piso']} / teto R$ {x.get('teto') or '-'}")
    if a.modo == "plano":
        json.dump(montar_plano(fora, hoje), open(a.saida, "w"), ensure_ascii=False, indent=1)
        print(f"plano em {a.saida}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

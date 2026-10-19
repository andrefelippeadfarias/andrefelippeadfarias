"""Plano de aproximação dos hotéis com hidro no quarto + vista para as montanhas a até 3 km do Capivari (dono, 19/10).

Uso:
  python3 concorrencia_hidro_vista.py --coleta SAIDA_WORKFLOW.json --hoje AAAA-MM-DD [--saida plano.json] [--sem-beds24]

Lê dados/concorrentes_hidro_vista.json (mediana da classe A por período e a vitrine do Recanto na mesma busca) e o
calendário da coleta. Para cada noite livre das suítes com banheira (regras.md, seção 5):
  - nós mais de 10% acima da mediana e a noite não está à frente da meta da janela (+10 pontos): desce até a mediana,
    no máximo 15% por rodada, nunca abaixo dos pisos do dono (R$ 500 de semana / R$ 850 sexta e sábado no preço
    final; feriado fica com o piso do feriado no validador). Vai pelo teto da noite; se o piso vigente da data
    estiver acima do novo teto, o item leva também o piso logo abaixo do teto (senão o piso vence);
  - nós mais de 8% abaixo e a noite na meta: +10% pelo piso;
  - senão, segura.
A Queen (2) (sem_desconto) só sobe. Na Villa, a vitrine é estimada pela VK7 × fator_vitrine (a Villa não aparece na
busca com hidro). Para cada data com corte na VK7 ou VK2, a Balcony volta a 10% abaixo da suíte livre mais barata
(Regra G). A ocupação vem do Beds24 (confirmadas + novas); com --sem-beds24, do calendário do PriceLabs.
Sempre passe a saída pelo validar_plano.py antes de gravar.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from comum import carregar_quartos, periodo_feriado  # noqa: E402
from validar_plano import em_reais  # noqa: E402

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
META = [(7, .70), (15, .50), (30, .35), (45, .25), (60, .15)]
SUITES = ["Q7", "Double", "Afrodite", "Q2", "VK7", "VK2"]


def carrega(f):
    d = json.load(open(f))
    d = d.get("result", d) if isinstance(d, dict) else d
    return json.loads(d) if isinstance(d, str) else d


def meta(hoje, d):
    dd = (d - hoje).days
    return next((v for lim, v in META if dd < lim), 0.10)


def montar(cfg, comp, coleta, hoje, vendidas=None):
    Q, L, P = cfg["por_curto"], cfg["limites_seguranca"], cfg["parametros"]
    rooms = coleta["rooms"]
    cal = {(k, x["date"][:10]): x for k, r in rooms.items() for x in r.get("days") or []}
    ov = {(k, o["date"][:10]): o for k, r in rooms.items() for o in (r.get("overrides") or [])}

    def ocupacao(k, s):
        """(vendidas, total). Com Beds24, as vendidas reais; mas um quarto fechado no calendário (bloqueio sem
        reserva, como a Villa em 05-07/11) conta como sem unidade livre, porque não aparece à venda."""
        q, x = Q[k], cal.get((k, s)) or {}
        mu = str(x.get("multi_unit_occupancy") or "")
        if "/" in mu:
            vc, tc = (int(n) for n in mu.split("/"))
        else:
            vc, tc = (1 if str(x.get("booking_status") or "").strip() else 0), 1
        if vendidas is None:
            return vc, tc
        v, t = vendidas.get((k, s), 0), q["unidades"]
        return (t, t) if vc >= tc else (v, t)

    def piso_vigente(k, s):
        x = cal.get((k, s)) or {}
        if x.get("min_price") not in (None, ""):
            return float(x["min_price"])
        o = ov.get((k, s)) or {}
        return float(Q[k]["min"]) if o.get("min_price") in (None, "") else em_reais(Q[k], float(o["min_price"]))

    def piso_dono(k, d):
        q, fer = Q[k], periodo_feriado(cfg, d)
        if fer:  # piso do feriado em valor real (validar_plano.py), nunca abaixo do limite de segurança
            piso = (fer.get("piso_real_por_quarto") or {}).get(k, fer.get("piso_real")) or 0
            real = max(float(piso), float(L["piso_feriado_real_seguranca"]))
            return max(float(q["min"]), real / float(q["fator_real"]))
        if d.weekday() in (4, 5):
            return max(float(q["min"]), L["piso_vitrine_fds_suites"] / P["fator_vitrine_fds"][q["pousada"]])
        return max(float(q["min"]), L["piso_vitrine_semana_suites"] / P["fator_vitrine_semana"][q["pousada"]])

    itens, notas, novos = [], [], {}
    for per in comp["periodos"]:
        rot, alvo, nosso = per["rotulo"], float(per["mediana_a"]), per.get("recanto")
        for s in per["datas"]:
            d = dt.date.fromisoformat(s)
            if d < hoje:
                continue
            fds, fer = d.weekday() in (4, 5), periodo_feriado(cfg, d)
            alvo_ef = max(alvo, 0 if fer else (L["piso_vitrine_fds_suites"] if fds else L["piso_vitrine_semana_suites"]))
            for k in SUITES:
                q, x = Q[k], cal.get((k, s))
                if not x:
                    continue
                vend, cap = ocupacao(k, s)
                if cap - vend <= 0:
                    continue
                preco = float(x["price"])
                if q["pousada"] == "Recanto":
                    if nosso is None:
                        continue
                    gap = float(nosso) / alvo_ef - 1
                else:
                    fv = P["fator_vitrine_fds"]["Villa"] if (fds or fer) else P["fator_vitrine_semana"]["Villa"]
                    gap = float((cal.get(("VK7", s)) or x)["price"]) * fv / alvo_ef - 1
                ocup, mt = vend / cap, meta(hoje, d)
                if gap > 0.10 and ocup < mt + 0.10 and not q.get("sem_desconto"):
                    passo = max(-0.15, 1 / (1 + gap) - 1)
                    novo = round(max(preco * (1 + passo), piso_dono(k, d)))
                    if novo >= preco * 0.98:
                        notas.append(f"{k} {s}: já no piso do dono (R$ {piso_dono(k, d):.0f})")
                        continue
                    motivo = (f"Concorrência hidro+vista ≤3 km do Capivari ({rot}): mediana R$ {alvo:.0f}/noite na Booking, "
                              f"nós {gap:+.0%} acima; {passo:+.0%} (R$ {preco:.0f} → R$ {novo}); {vend}/{cap} vendidas")
                    it = {"tipo": "data", "quarto": k, "data": s, "max_price": novo, "preco_antes": preco,
                          "motivo": motivo + "; pelo teto só nesta noite",
                          "mercado": f"mediana hidro+vista {rot} R$ {alvo:.0f}/noite, nós {gap:+.0%}, {vend}/{cap} vendidas"}
                    if piso_vigente(k, s) > novo - 1:
                        it["min_price"] = round(max(novo * 0.99, piso_dono(k, d)))
                    itens.append(it)
                    novos[(k, s)] = novo
                elif gap < -0.08 and ocup >= mt:
                    novo = round(preco * 1.10)
                    itens.append({"tipo": "data", "quarto": k, "data": s, "min_price": novo, "preco_antes": preco,
                                  "motivo": f"Concorrência hidro+vista ({rot}): mediana R$ {alvo:.0f}, nós {gap:+.0%} abaixo "
                                            f"e {vend}/{cap} vendidas (meta {mt:.0%}): +10% pelo piso (R$ {preco:.0f} → R$ {novo})"})
                else:
                    notas.append(f"{k} {s}: segura (diferença {gap:+.0%}, {vend}/{cap} vendidas, meta {mt:.0%})")

    # Regra G: Balcony 10% abaixo da suíte com banheira livre mais barata da Villa nas datas com corte
    desc = P.get("regra_g_desconto", 0.10) if isinstance(P, dict) else 0.10
    for s in sorted({s for (k, s) in novos if k in ("VK7", "VK2")}):
        d = dt.date.fromisoformat(s)
        if periodo_feriado(cfg, d) or not cal.get(("Balcony", s)):
            continue
        livres = [novos.get((k, s), float((cal.get((k, s)) or {}).get("price") or 0))
                  for k in ("VK7", "VK2") if cal.get((k, s)) and ocupacao(k, s)[0] < ocupacao(k, s)[1]]
        if not livres:
            continue
        alvo_b = round(min(livres) * (1 - desc))
        atual = float(cal[("Balcony", s)]["price"])
        if atual > min(livres) * (1 - desc / 2):
            itens.append({"tipo": "data", "quarto": "Balcony", "data": s, "min_price": alvo_b, "max_price": alvo_b,
                          "preco_antes": atual,
                          "motivo": f"Regra G: suíte com banheira livre mais barata da Villa vai a R$ {min(livres):.0f} "
                                    f"(concorrência hidro+vista); Balcony 10% abaixo, R$ {alvo_b} (antes R$ {atual:.0f}); piso e teto"})
    return itens, notas


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--coleta", required=True)
    ap.add_argument("--hoje", required=True)
    ap.add_argument("--saida", default="plano_concorrencia.json")
    ap.add_argument("--comp", default=os.path.join(RAIZ, "dados", "concorrentes_hidro_vista.json"))
    ap.add_argument("--sem-beds24", action="store_true")
    a = ap.parse_args(argv)
    hoje = dt.date.fromisoformat(a.hoje)
    cfg, comp, coleta = carregar_quartos(), json.load(open(a.comp)), carrega(a.coleta)
    vendidas = None
    if not a.sem_beds24:
        import beds24
        fim = max(dt.date.fromisoformat(s) for p in comp["periodos"] for s in p["datas"]) + dt.timedelta(days=2)
        vendidas = beds24.noites(beds24.reservas(hoje.isoformat(), fim.isoformat(), beds24.token_de_acesso()), hoje, fim)
    itens, notas = montar(cfg, comp, coleta, hoje, vendidas)
    json.dump({"hoje": a.hoje, "itens": itens}, open(a.saida, "w"), ensure_ascii=False, indent=1)
    for i in itens:
        print(i["quarto"], i["data"], "teto" if "max_price" in i else "piso", i.get("max_price") or i.get("min_price"),
              "antes", i["preco_antes"])
    print(f"{len(itens)} itens em {a.saida}")
    print("\n".join(notas))
    return 0


if __name__ == "__main__":
    sys.exit(main())

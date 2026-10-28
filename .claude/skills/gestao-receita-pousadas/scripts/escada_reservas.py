"""Escada de subida por reserva (dono, 26/10/2026; proporcional ao topo do mercado desde 28/10/2026).

Dono, 26/10: "A cada reserva que entrar, vamos aumentando o preço gradualmente até chegarmos no nível de médio-alto
padrão [...] vamos começar com esse mínimo, que está pré-definido já agora, e vamos alavancar até chegar no valor de
alto padrão, para que possamos melhorar o ticket médio dos nossos hotéis."
Dono, 28/10: "Ajuste a escada de aumento de preço de forma proporcional sempre para chegarmos ao valor da média dos
tops do mercado com nossas características sempre."

Alvo da noite (enviado) = média dos `parametros.escada.top_n` (3) maiores preços do QUARTO COM HIDRO da classe A
(hidro no quarto + vista, até 3 km do Capivari; dados/concorrentes_hidro_vista.json) ÷ regra_j.fator_publico da pousada.
  - feriado: `topo_a` do período que contém a noite;
  - fora de feriado: `topo_a` da referência mais próxima do mesmo tipo (semana = domingo a quinta, fds = sexta e sábado);
  - Balcony (sem banheira): escada.fator_balcony × o alvo da VK2 (Regra G).
Degrau proporcional: o preço anda sobre a reta entre o preço de partida e o alvo, na proporção das unidades vendidas.
  novo = alvo − (alvo − atual) × (unidades − vendidas_depois) ÷ (unidades − vendidas_antes)
Cada venda fecha a fração da distância que ela representa nas unidades livres; a última unidade vendida chega ao alvo
(e a Regra J leva a suíte esgotada ao topo). Só sobe; cancelamento desce pela mesma reta, nunca abaixo do mínimo do
dono. Vale em toda noite futura com venda nova (a noite de hoje não sobe; lote do Beds24 e edição da equipe não contam).
Sem alvo de mercado para a noite, cai no degrau antigo (+escada.passo por venda, até o teto de alto padrão).

Uso:
  python3 escada_reservas.py plano --hoje AAAA-MM-DD --antes Q7=<rec> ... --depois Q7=<rec> ... \
      [--reservas reservas.json --desde AAAA-MM-DDTHH:MM] [--saida plano_escada.json]
  python3 escada_reservas.py alvo --quarto Q7 --data 2026-11-03
  python3 escada_reservas.py passo --quarto Q7 --data 2026-10-27 --atual 739 [--reservas 1] [--hoje 2026-10-26]
  python3 escada_reservas.py ordem --q7 791 --q2 747 --double 755 --afrodite 800
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import math
import os
import sys
from collections import defaultdict
from datetime import date

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from calendario_12m import ler_recalculo  # noqa: E402
from comum import carregar_quartos, data, esgotado, hoje_brt, ler_json, periodo_feriado, vendidas_total  # noqa: E402

ARQ_HIDRO = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "dados", "concorrentes_hidro_vista.json")


def topo_publico(hidro: dict, d: date):
    """(média dos tops na vitrine por noite, fonte) para a noite, ou (None, None)."""
    s = d.isoformat()
    for p in hidro.get("periodos", []):
        if p.get("topo_a") and s in p.get("datas", []):
            return float(p["topo_a"]), f"feriado {p['rotulo']}"
    tipo = "fds" if d.weekday() in (4, 5) else "semana"
    refs = [r for r in hidro.get("referencias_topo", []) if r.get("tipo") == tipo and r.get("topo_a")]
    if not refs:
        return None, None
    r = min(refs, key=lambda r: abs((data(r["data"]) - d).days))
    return float(r["topo_a"]), f"{tipo} ({r['rotulo']})"


def alvo(cfg: dict, hidro: dict, curto: str, d: date):
    """(alvo enviado, fonte) da escada proporcional, ou (None, None) sem dado de mercado."""
    par = cfg["parametros"]
    if curto == "Balcony":
        a, f = alvo(cfg, hidro, "VK2", d)
        return (round(par["escada"]["fator_balcony"] * a, 2), f + "; Balcony 90% da VK2") if a else (None, None)
    pub, fonte = topo_publico(hidro, d)
    if not pub:
        return None, None
    q = cfg["por_curto"][curto]
    return round(min(pub / par["regra_j"]["fator_publico"][q["pousada"]], float(q["max"])), 2), fonte


def piso_dono(cfg: dict, curto: str, d: date) -> float:
    """Piso do cancelamento: o mínimo do dono no preço do hóspede mais descontado (Genius 3) ÷ fator da pousada.
    Até escada.piso_genius.ate vale escada.piso_genius.valor (dono 26/10: "Ninguém paga menos de 300"); depois,
    os pisos de vitrine do dono (R$ 500 dom-qui, R$ 850 sex-sáb). Balcony: 90% do piso da VK2."""
    if curto == "Balcony":
        return round(cfg["parametros"]["escada"]["fator_balcony"] * piso_dono(cfg, "VK2", d), 2)
    q, par, lim = cfg["por_curto"][curto], cfg["parametros"], cfg["limites_seguranca"]
    fds = d.weekday() in (4, 5)
    fat = par["fator_vitrine_fds" if fds else "fator_vitrine_semana"][q["pousada"]]
    pg = par["escada"].get("piso_genius") or {}
    if pg and d.isoformat() <= pg["ate"]:
        return round(float(pg["valor"]) / fat, 2)
    return round(float(lim["piso_vitrine_fds_suites" if fds else "piso_vitrine_semana_suites"]) / fat, 2)


def proporcional(atual: float, meta: float, unidades: int, antes: int, depois: int) -> float:
    """Preço sobre a reta (vendidas → preço) que termina no alvo quando a última unidade vende."""
    if antes >= unidades:
        return atual
    return meta - (meta - atual) * (unidades - min(depois, unidades)) / (unidades - antes)


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


def lotes(reservas: list[dict], desde: str | None, minimo: int = 3) -> dict:
    """(curto, noite) -> noites vendidas por lote do Beds24 (minimo+ lançamentos no mesmo minuto) desde `desde`.
    O lote de 27/10 15:03 UTC caiu em 3 segundos seguidos; por segundo, a VK7 de 08/11 (2 reservas) escapava."""
    por_seg = defaultdict(list)
    for r in reservas:
        if r.get("booking_status") == "booked" and (not desde or str(r.get("booked_date", "")) >= desde):
            por_seg[str(r.get("booked_date", ""))[:16]].append(r)
    out = defaultdict(int)
    cfg = carregar_quartos()
    for grupo in por_seg.values():
        if len(grupo) < minimo:
            continue
        for r in grupo:
            q = cfg["por_id"].get(r.get("listing_id"))
            if not q:
                continue
            ci = dt.date.fromisoformat(r["check_in"])
            for k in range(int(r.get("no_of_days") or 1)):
                out[(q["curto"], (ci + dt.timedelta(days=k)).isoformat())] += 1
    return out


def plano(cfg: dict, hidro: dict, antes: dict, depois: dict, hoje: date, lote: dict | None = None) -> list[dict]:
    """Itens da escada proporcional para as noites com venda nova (ou cancelamento) entre dois recálculos."""
    lote = lote or {}
    itens, finais = [], {}
    desc = cfg["parametros"].get("regra_g_desconto", 0.10)
    # a Balcony por último: o teto dela depende do preço final da VK7 e da VK2 (Regra G)
    for curto, cal in sorted(depois.items(), key=lambda kv: kv[0] == "Balcony"):
        q = cfg["por_curto"][curto]
        u = q["unidades"]
        for noite, r in sorted(cal.items()):
            d = data(noite)
            a = antes.get(curto, {}).get(noite)
            if d <= hoje or a is None:
                continue
            va, _ = vendidas_total(a, u)
            vd, _ = vendidas_total(r, u)
            vd_real = vd - lote.get((curto, noite), 0)
            if vd_real == va or va >= u or vd >= u:
                continue  # sem venda nova; esgotada antes ou agora: Regra J
            atual = float(r["price"])
            meta, fonte = alvo(cfg, hidro, curto, d)
            if meta is None:
                n = vd_real - va
                novo = passo(cfg, curto, d, atual, n, hoje)["novo"]
                fonte = f"sem dado de mercado: {n:+d} degrau(s) de {cfg['parametros']['escada']['passo']:.0%}"
            else:
                novo = proporcional(atual, meta, u, va, vd_real)
            if curto == "Balcony" and not periodo_feriado(cfg, d):
                refs = [finais.get((k, noite), float(depois[k][noite]["price"])) for k in ("VK7", "VK2")
                        if noite in depois.get(k, {}) and not esgotado(depois[k][noite], cfg["por_curto"][k]["unidades"])]
                if refs:
                    novo = min(novo, min(refs) * (1 - desc))  # Regra G: abaixo da suíte com banheira livre
            novo = math.ceil(novo) if vd_real > va else math.floor(novo)
            if vd_real < va:
                novo = max(novo, math.ceil(min(piso_dono(cfg, curto, d), atual)))
            if novo <= atual if vd_real > va else abs(novo - atual) < 1:
                continue
            fixo = r.get("min_price") is not None and str(r.get("min_price")) == str(r.get("max_price"))
            it = {"tipo": "data", "quarto": curto, "data": noite, "min_price": novo, "preco_antes": atual,
                  "excecao": "dono",
                  "motivo": (f"Escada proporcional (dono 26 e 28/10): {va}→{vd_real} de {u} vendidas; alvo R$ "
                             f"{meta:.0f} = média dos tops com hidro e vista ({fonte}); antes R$ {atual:.0f}"
                             if meta else f"Escada por reserva (dono 26/10): {fonte}; antes R$ {atual:.0f}")}
            if fixo:
                it["max_price"] = novo
            itens.append(it)
            finais[(curto, noite)] = novo
    todos = {}
    for it in itens + acompanhar(cfg, depois, finais):
        todos[(it["quarto"], it["data"])] = it  # a ordem e a Regra G vêm depois e já partem do preço da escada
    return list(todos.values())


def acompanhar(cfg: dict, cal: dict, finais: dict) -> list[dict]:
    """Ordem do Recanto e Regra G nas noites em que a escada mexeu (só sobe; suíte esgotada fica fora)."""
    from ordem_recanto import premio_afrodite
    itens = []
    noites = sorted({n for (_, n) in finais})
    for noite in noites:
        d = data(noite)

        def preco(k):
            r = cal.get(k, {}).get(noite)
            return None if r is None else finais.get((k, noite), float(r["price"]))

        def livre(k):
            r = cal.get(k, {}).get(noite)
            return r is not None and not esgotado(r, cfg["por_curto"][k]["unidades"])

        def subir(k, alvo_k, motivo):
            r = cal[k][noite]
            atual = preco(k)
            if alvo_k <= atual + 1:
                return
            novo = math.ceil(alvo_k)
            it = {"tipo": "data", "quarto": k, "data": noite, "min_price": novo, "preco_antes": float(r["price"]),
                  "excecao": "dono", "motivo": f"{motivo}; antes R$ {float(r['price']):.0f}"}
            if r.get("min_price") is not None and str(r.get("min_price")) == str(r.get("max_price")):
                it["max_price"] = novo
            finais[(k, noite)] = novo
            itens[:] = [x for x in itens if not (x["quarto"] == k and x["data"] == noite)] + [it]

        if any((k, noite) in finais for k in ("Q7", "Q2", "Double")):
            ref = [k for k in ("Q7",) if livre(k) and preco(k) is not None]
            if ref and livre("Q2"):
                subir("Q2", preco("Q7"), f"Ordem do Recanto: Panorâmica II acompanha a Moinhos (R$ {preco('Q7'):.0f})")
            base = max([preco(k) for k in ("Q7", "Q2") if livre(k) and preco(k) is not None] or [0])
            if base and livre("Double"):
                subir("Double", base, f"Ordem do Recanto: Panorâmica I acompanha a Panorâmica II (R$ {base:.0f})")
            outras = max([preco(k) for k in ("Q7", "Q2", "Double") if livre(k) and preco(k) is not None] or [0])
            if outras and livre("Afrodite"):
                pr = premio_afrodite(cfg, d)
                subir("Afrodite", outras * (1 + pr),
                      f"Dono 22 e 27/10: Afrodite {pr:.0%} acima da mais cara das outras (R$ {outras:.0f})")
        if any((k, noite) in finais for k in ("VK7", "VK2")) and livre("Balcony") and not periodo_feriado(cfg, d):
            refs = [preco(k) for k in ("VK7", "VK2") if livre(k) and preco(k) is not None]
            if refs:
                desc = cfg["parametros"].get("regra_g_desconto", 0.10)
                subir("Balcony", min(refs) * (1 - desc),
                      f"Regra G: Balcony {desc:.0%} abaixo da suíte com banheira mais barata livre (R$ {min(refs):.0f})")
    return itens


def ordem(q7: float, q2: float, double: float, afrodite: float, premio: float = 0.20) -> dict:
    """Ordem do Recanto: Moinhos ≤ Panorâmica II ≤ Panorâmica I < Afrodite (+20% de domingo a quinta, dono 27/10;
    passe premio=0.05 em sexta, sábado e feriado). Só sobe, nunca desce."""
    q2n = max(q2, q7)
    dn = max(double, q2n)
    an = max(afrodite, math.ceil(dn * (1 + premio)))
    return {"Q7": q7, "Q2": q2n, "Double": dn, "Afrodite": an}


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    pl = sub.add_parser("plano")
    pl.add_argument("--hoje", required=True)
    pl.add_argument("--antes", nargs="+", required=True, help="Q7=<recálculo da rodada anterior> ...")
    pl.add_argument("--depois", nargs="+", required=True, help="Q7=<recálculo novo> ...")
    pl.add_argument("--reservas", help="lista de reservas (get_pms_reservations), para tirar os lotes do Beds24")
    pl.add_argument("--desde", help="UTC do recálculo anterior (AAAA-MM-DDTHH:MM), para os lotes")
    pl.add_argument("--saida", default="plano_escada.json")
    al = sub.add_parser("alvo")
    al.add_argument("--quarto", required=True)
    al.add_argument("--data", required=True)
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
    hidro = json.load(open(ARQ_HIDRO, encoding="utf-8"))
    if a.cmd == "plano":
        if data(a.hoje) != hoje_brt(cfg):
            print(f"ERRO: --hoje {a.hoje} não é hoje em Brasília ({hoje_brt(cfg)}); a noite de hoje não pode subir")
            return 2
        ler = lambda xs: {k: ler_recalculo(v) for k, v in (x.split("=", 1) for x in xs)}  # noqa: E731
        lote = {}
        if a.reservas:
            d = ler_json(a.reservas)
            lote = lotes(((d.get("data") or {}).get("data")) or d.get("data") or [], a.desde)
        itens = plano(cfg, hidro, ler(a.antes), ler(a.depois), data(a.hoje), lote)
        json.dump({"hoje": a.hoje, "itens": itens}, open(a.saida, "w"), ensure_ascii=False, indent=1)
        print(f"{len(itens)} itens em {a.saida}")
        for it in itens:
            print(f"  {it['quarto']} {it['data']}: R$ {it['preco_antes']:.0f} -> R$ {it['min_price']:.0f} | {it['motivo'][:110]}")
        return 0
    if a.cmd == "alvo":
        meta, fonte = alvo(cfg, hidro, a.quarto, data(a.data))
        print(f"{a.quarto} {a.data}: alvo R$ {meta:.0f} enviados ({fonte})" if meta else f"{a.quarto} {a.data}: sem dado")
        return 0
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

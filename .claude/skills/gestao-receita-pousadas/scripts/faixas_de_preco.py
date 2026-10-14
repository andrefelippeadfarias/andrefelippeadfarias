"""Faixas de preço de mercado: muito baixo, baixo, médio, alto e muito alto.

Pedido do dono (13/10/2026): qualificar os preços em cinco faixas, medidas contra toda a concorrência,
para flutuar e ajustar em percentual conforme o plano e manter as metas de ocupação.

Como funciona
- A varredura da Booking (workflow `varredura-concorrencia`) devolve, por conjunto de datas, todas as
  propriedades disponíveis com o preço total da estadia. O preço por noite é o total ÷ noites.
- Em cada data e em cada conjunto de comparação, as faixas são os quintis do preço por noite:
  muito baixo (abaixo do 20º percentil), baixo (20º a 40º), médio (40º a 60º), alto (60º a 80º),
  muito alto (acima do 80º).
- O preço NOSSO entra na comparação pelo que o hóspede vê na Booking (vitrine): enviado × razão
  vitrine÷enviado de cada quarto (`quartos.json`, `fator_vitrine_*`). Para dias sem busca usa-se o
  tipo de dia (semana, fim de semana, feriado/evento).

Uso
  python3 faixas_de_preco.py mercado <varredura.json>
      tabela de cortes por data e por conjunto, e onde estão os nossos dois hotéis
  python3 faixas_de_preco.py quartos <coleta.json> <mercado.json> [--dias 21]
      faixa de cada noite de cada quarto e a ação sugerida (ver `acao_sugerida`)
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date, timedelta

FAIXAS = ["muito baixo", "baixo", "médio", "alto", "muito alto"]
EMOJI = ["🟣", "🔵", "🟢", "🟠", "🔴"]
PERCENTIS = (20, 40, 60, 80)
NOSSOS = {15459723: "Recanto", 14824789: "Villa"}


def quantil(valores: list[float], p: float) -> float:
    """Percentil p (0 a 100) com interpolação linear. Lista vazia dá erro."""
    v = sorted(valores)
    if not v:
        raise ValueError("sem valores")
    if len(v) == 1:
        return float(v[0])
    k = (len(v) - 1) * p / 100
    i = int(k)
    f = k - i
    return float(v[i] + (v[min(i + 1, len(v) - 1)] - v[i]) * f)


def cortes(valores: list[float], percentis=PERCENTIS) -> list[float]:
    return [round(quantil(valores, p), 2) for p in percentis]


def faixa(preco: float, cortes_: list[float]) -> int:
    """0 muito baixo, 1 baixo, 2 médio, 3 alto, 4 muito alto. Igual ao corte sobe para a faixa de cima."""
    for i, c in enumerate(cortes_):
        if preco < c:
            return i
    return 4


# --- varredura -----------------------------------------------------------------------------------

def juntar_varredura(resultado: dict) -> dict:
    """Agrupa as partes (baixa/alta) de cada data e as buscas de cobertura.

    Devolve {rotulo_da_data: {"checkin","checkout","noites","tipo","props":{id:{...,"noite":preço por noite}}}}.
    Duplicatas pelo id: vale o menor preço (a Booking mostra o preço de entrada do hotel)."""
    saida: dict = {}
    for r in resultado.get("datas", []):
        if not r:
            continue
        rot = r["rotulo"].rsplit("-", 1)[0]
        d = saida.setdefault(rot, {"checkin": r["checkin"], "checkout": r["checkout"],
                                   "noites": max(1, int(r.get("noites") or 1)), "tipo": r.get("tipo", ""), "props": {}, "partes": []})
        d["partes"].append(r.get("parte") or r["rotulo"].rsplit("-", 1)[-1])
        _acumular(d, r)
    # a cobertura por tipo de acomodação acrescenta propriedades (hostel, apartamento, casa) nas 3 datas de referência
    mapa_cob = {"cobertura-semana-20/10": "semana-20/10", "cobertura-fds-23/10": "fimdesemana-23/10",
                "cobertura-finados-30/10": "finados-30/10"}
    for r in resultado.get("cobertura", []) or []:
        if not r:
            continue
        rot = mapa_cob.get(r["rotulo"])
        if rot and rot in saida:
            _acumular(saida[rot], r)
    return saida


def _acumular(d: dict, r: dict) -> None:
    n = d["noites"]
    for p in r.get("props", []):
        if not p.get("price_total") or p["price_total"] <= 0:
            continue
        noite = p["price_total"] / n
        atual = d["props"].get(p["id"])
        if atual is None or noite < atual["noite"]:
            d["props"][p["id"]] = {**p, "noite": round(noite, 2)}


def conjunto(props: list[dict], nome: str) -> list[dict]:
    """Conjuntos de comparação.
    - geral: todos (sem os nossos);
    - recanto: pares do Recanto, com jacuzzi ou spa e 4 a 5 estrelas ou nota de 8,0 para cima;
    - villa: pares da Villa, até 4 estrelas e nota de 7,0 a 9,2, com ou sem jacuzzi."""
    sem_nos = [p for p in props if p["id"] not in NOSSOS]
    if nome == "geral":
        return sem_nos
    if nome == "recanto":
        return [p for p in sem_nos if (p["jacuzzi"] or p["spa"]) and (p["stars"] >= 4 or p["score"] >= 8.0)]
    if nome == "villa":
        return [p for p in sem_nos if 0 < p["stars"] <= 4 and 7.0 <= p["score"] <= 9.2]
    raise ValueError(nome)


def tabela_mercado(varr: dict) -> dict:
    """{rotulo: {"tipo","noites","checkin","conjuntos":{nome:{"n","cortes","mediana"}},"nossos":{nome:preco por noite}}}"""
    saida = {}
    for rot, d in varr.items():
        props = list(d["props"].values())
        conj = {}
        for nome in ("geral", "recanto", "villa"):
            vals = [p["noite"] for p in conjunto(props, nome)]
            if len(vals) >= 5:
                conj[nome] = {"n": len(vals), "cortes": cortes(vals), "mediana": round(quantil(vals, 50), 2)}
        nossos = {NOSSOS[p["id"]]: p["noite"] for p in props if p["id"] in NOSSOS}
        saida[rot] = {"tipo": d["tipo"], "noites": d["noites"], "checkin": d["checkin"],
                      "conjuntos": conj, "nossos": nossos,
                      # completa = as duas metades da varredura (baixa e alta) vieram; parcial só vale para a própria data
                      "completa": {"baixa", "alta"} <= set(d.get("partes", []))}
    return saida


def cortes_por_tipo(tab: dict, conjunto_: str) -> dict:
    """Cortes típicos por tipo de dia: mediana dos cortes das datas daquele tipo."""
    por_tipo: dict[str, list[list[float]]] = {}
    for d in tab.values():
        c = d["conjuntos"].get(conjunto_)
        if c and d.get("completa", True):
            por_tipo.setdefault(d["tipo"], []).append(c["cortes"])
    return {t: [round(quantil([c[i] for c in lst], 50), 2) for i in range(4)] for t, lst in por_tipo.items()}


# --- nossos quartos ------------------------------------------------------------------------------

def tipo_do_dia(d: date, feriados: set[date]) -> str:
    if d in feriados:
        return "feriado"
    if d.weekday() in (4, 5):
        return "fim de semana"
    return "semana"


def acao_sugerida(f: int, alvo: int, ocupacao: float, meta: float, esgotada: bool, dias_ate: int) -> tuple[str, int]:
    """Regra de flutuação (dono, 13/10/2026). Devolve (texto, passo em %).

    Entradas: f = faixa atual do nosso preço de vitrine (0 muito baixo ... 4 muito alto); alvo = faixa alvo da
    sazonalidade; ocupacao e meta da janela em 0 a 1; dias_ate = dias até a noite.
    Situação da noite: atrás (ocupação < meta - 0,20), na meta, à frente (ocupação >= meta + 0,10) ou esgotada.
    - esgotada: nada a mudar, só proteger o piso;
    - atrás: desce um degrau (-10%; -15% se já está em alto ou muito alto), mas não abaixo da faixa alvo menos um,
      e nunca abaixo de "baixo" fora de D0 a D3 (aí vale a Regra I);
    - à frente: sobe um degrau (+10%), até a faixa alvo mais um; em muito alto, segura;
    - na meta: aproxima da faixa alvo devagar (+5% se está duas faixas abaixo do alvo, -5% se duas acima); senão segura.
    Os limites de segurança, o padrão de qualidade, o piso de feriado, as Regras G e H e o ±15% por semana da base
    continuam valendo por cima disto (o validador barra)."""
    if esgotada:
        return ("esgotada: manter piso de proteção", 0)
    if ocupacao < meta - 0.20:
        if f <= max(0, alvo - 1) or (f <= 1 and dias_ate > 3):
            return ("atrás da meta, já no limite inferior da faixa alvo: segurar (D0 a D3: Regra I)", 0)
        return ("atrás da meta: descer uma faixa", -15 if f >= 3 else -10)
    if ocupacao >= meta + 0.10:
        if f >= min(4, alvo + 1):
            return ("à frente da meta, já no limite superior da faixa alvo: segurar", 0)
        return ("à frente da meta: subir uma faixa", 10)
    if f <= alvo - 2:
        return ("na meta, mas duas faixas abaixo do alvo: subir devagar", 5)
    if f >= alvo + 2:
        return ("na meta, mas duas faixas acima do alvo: descer devagar", -5)
    return ("na meta: segurar", 0)


def meta_da_janela(cfg: dict, dias_ate: int) -> float:
    """Meta de ocupação (0 a 1) da janela em que a noite cai: 0-6 dias 70%, 7-14 50%, 15-29 35%, 30-44 25%, 45+ 15%."""
    m = cfg["metas"]
    for lim in (7, 15, 30, 45):
        if dias_ate < lim:
            return float(m[str(lim)]) / 100
    return float(m["60"]) / 100


def cortes_da_noite(tab: dict, d: date, conj: str, tipo: str) -> tuple[list[float] | None, str]:
    """Cortes do conjunto `conj` para a noite `d`: da busca que cobre a noite ou, sem busca, do tipo de dia."""
    for rot, m in tab.items():
        c = m["conjuntos"].get(conj)
        if not c:
            continue
        ci = date.fromisoformat(m["checkin"])
        if ci <= d < ci + timedelta(days=int(m["noites"])):
            return c["cortes"], rot
    por_tipo = cortes_por_tipo(tab, conj)
    chave = {"feriado": "feriado", "fim de semana": "fim de semana", "semana": "semana"}[tipo]
    if chave not in por_tipo and chave == "feriado":
        chave = "evento" if "evento" in por_tipo else "fim de semana"
    return por_tipo.get(chave), f"tipo:{chave}"


def classificar_quartos(coleta: dict, tab: dict, cal: dict, cfg: dict, hoje: date, dias: int) -> list[dict]:
    """Uma linha por quarto e noite: faixa atual, faixa alvo, ocupação, meta e ação sugerida."""
    from sazonalidade import periodo, indice_faixa
    feriados = set()
    for fer in cfg.get("feriados", []):
        d0, d1 = date.fromisoformat(fer["inicio"]), date.fromisoformat(fer["fim"])
        while d0 <= d1:
            feriados.add(d0); d0 += timedelta(days=1)
    linhas = []
    for curto, quarto in coleta["rooms"].items():
        q = cfg["por_curto"][curto]
        conj = "recanto" if q["pousada"] == "Recanto" else "villa"
        for dia in quarto["days"]:
            d = date.fromisoformat(dia["date"])
            ate = (d - hoje).days
            if ate < 0 or ate >= dias:
                continue
            mu = dia.get("multi_unit_occupancy") or ""
            if "/" in mu:
                vend, tot = (int(x) for x in mu.split("/"))
            else:  # unidade única
                vend, tot = (1 if dia.get("booking_status") else 0), 1
            esgotada = vend >= tot
            enviado = dia["user_price"] if dia["user_price"] and dia["user_price"] > 0 else dia["price"]
            tipo = tipo_do_dia(d, feriados)
            # a Villa aparece mais cara na vitrine em sexta e sábado (0,66 contra 0,53 em semana e feriado)
            fator = q.get("fator_vitrine_fds", q["fator_vitrine"]) if tipo == "fim de semana" else q["fator_vitrine"]
            vitrine = enviado * fator
            cortes_, origem = cortes_da_noite(tab, d, conj, tipo)
            per = periodo(cal, d)
            alvo = indice_faixa(per["faixa_alvo"]) if per else 2
            fx = faixa(vitrine, cortes_) if cortes_ else None
            meta = meta_da_janela(cfg, ate)
            ocup = vend / tot
            texto, passo = acao_sugerida(fx if fx is not None else alvo, alvo, ocup, meta, esgotada, ate) if cortes_ else ("sem dado de mercado", 0)
            if passo < 0 and q.get("sem_desconto"):
                texto, passo = ("quarto sem desconto (Queen 2): só segura ou sobe", 0)
            elif passo < 0 and enviado <= q["min"] + 1:
                texto, passo = ("já no mínimo do quarto: abaixo só pela Regra I (D0 a D3) ou exceção de mercado", 0)
            linhas.append({"quarto": curto, "data": dia["date"], "dias_ate": ate, "tipo": tipo, "enviado": enviado,
                           "vitrine": round(vitrine), "cortes": cortes_, "origem": origem, "faixa": fx, "faixa_alvo": alvo,
                           "periodo": per["nome"][:50] if per else "", "vendidas": vend, "total": tot, "ocupacao": round(ocup, 2),
                           "meta": meta, "acao": texto, "passo": passo})
    return linhas


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    m = sub.add_parser("mercado")
    m.add_argument("varredura")
    m.add_argument("--json")
    qd = sub.add_parser("quartos")
    qd.add_argument("coleta")
    qd.add_argument("mercado")
    qd.add_argument("--dias", type=int, default=21)
    qd.add_argument("--hoje")
    qd.add_argument("--json")
    a = ap.parse_args(argv)
    if a.cmd == "mercado":
        with open(a.varredura, encoding="utf-8") as f:
            texto = f.read()
        dados = json.loads(texto[texto.find("{"):])
        dados = dados.get("result", dados)
        tab = tabela_mercado(juntar_varredura(dados))
        for rot, d in sorted(tab.items(), key=lambda kv: kv[1]["checkin"]):
            print(f"## {rot} ({d['tipo']}, {d['noites']} noite(s){'' if d.get('completa', True) else ', PARCIAL: só uma metade da varredura'})")
            for nome, c in d["conjuntos"].items():
                print(f"  {nome:8} n={c['n']:3}  cortes por noite {c['cortes']}  mediana {c['mediana']}")
            print(f"  nossos (por noite): {d['nossos']}")
        for conj in ("geral", "recanto", "villa"):
            print(f"cortes típicos por tipo de dia, {conj}: {cortes_por_tipo(tab, conj)}")
        if a.json:
            with open(a.json, "w", encoding="utf-8") as f:
                json.dump(tab, f, ensure_ascii=False, indent=1)
        return 0
    from comum import carregar_quartos, ler_json, resultado_workflow
    from sazonalidade import carregar
    cfg = carregar_quartos()
    coleta = resultado_workflow(ler_json(a.coleta))
    with open(a.mercado, encoding="utf-8") as f:
        tab = json.load(f)
    hoje = date.fromisoformat(a.hoje) if a.hoje else date.today()
    linhas = classificar_quartos(coleta, tab, carregar(), cfg, hoje, a.dias)
    print("| Quarto | Noite | Tipo | Enviado | Vitrine | Faixa | Alvo | Vendidas | Meta | Ação |")
    print("|---|---|---|---|---|---|---|---|---|---|")
    for l in linhas:
        fx = f"{EMOJI[l['faixa']]} {FAIXAS[l['faixa']]}" if l["faixa"] is not None else "—"
        print(f"| {l['quarto']} | {l['data']} | {l['tipo']} | R$ {l['enviado']:.0f} | R$ {l['vitrine']} | {fx} | {FAIXAS[l['faixa_alvo']]} | "
              f"{l['vendidas']}/{l['total']} | {int(l['meta']*100)}% | {l['acao']} ({l['passo']:+d}%) |")
    if a.json:
        with open(a.json, "w", encoding="utf-8") as f:
            json.dump(linhas, f, ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    sys.exit(main())

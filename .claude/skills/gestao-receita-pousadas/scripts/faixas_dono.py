"""Faixas de preço do dono por hotel e tipo de noite (pedido de 04/11/2026: "vamos graduar os valores de acordo com o
mercado. Crie uma tabela para cada hotel ... para que eu possa sempre orientar em qual faixa vamos flutuar").

Régua: o mercado com hidro da Booking. Lista de 77 concorrentes com hidromassagem e vista em
`pricelabs/pesquisa-booking/concorrentes.json`; café, 2 adultos, 1 quarto. A Booking devolve o quarto mais barato de cada
hotel; o quarto com hidro é esse preço por noite × r_hidro do hotel (padrão 1,395; fator "incerto" acima de 2,0 limitado
a 2,0, revisão de 04/11). Sem os nossos hotéis. Cinco faixas, cada uma um percentil dessa régua:

  F1 Ocupar   p25
  F2 Mercado  p50
  F3 Acima    p65
  F4 Premium  p75
  F5 Topo     p90

Valor de cada faixa por tipo de noite: mediana do percentil nas estadias medidas de cada mês e depois mediana entre os
meses (um mês com muitas noites medidas não domina o tipo).

Como uma faixa vira preço (regras.md 4e): ordem `alvo_por_data` nas noites e quartos pedidos, com `pisos_enviados` = valor
da faixa na régua da noite ÷ razão Booking/enviado (nunca abaixo do piso do dono da noite), `topo_enviados` = p90 ÷ razão
(a escada sobe com as vendas até ele), `piso_atras` = faixa de baixo ÷ razão com `gatilho_dias` 7 e `vendidas_base` = vendas
do dia; a ordem nova substitui as ordens por data das mesmas noites e quartos. O `percentil` da ordem é lido na âncora
geral do motor (outra régua, mais baixa), por isso o valor da faixa entra pelos pisos, não pelo percentil.

Tipos de noite:
  semana          domingo a quinta fora de feriado e fora do inverno
  fds             sexta e sábado fora de feriado e fora do inverno (Aniversário de SP entra aqui: o mercado não o trata
                  como feriado)
  feriado         feriados prolongados e isolados (Consciência Negra, Carnaval, Páscoa, Tiradentes, 7 de Setembro...)
  natal           noites de 23 a 26/12 (pacote 23→25)
  entre_festas    noites de 27 a 29/12 (29/12 é véspera fora do pacote)
  reveillon       noites de 30/12 a 02/01 (pacotes 30→01 e 30→03)
  inverno_semana  domingo a quinta de 30/05 a 31/07 fora de feriado
  inverno_fds     sexta e sábado de 30/05 a 31/07, Corpus Christi, Dia dos Namorados e 9 de Julho

Valores por quarto (regras vigentes do dono): Recanto: Moinhos, Panorâmica II e Panorâmica I no valor da faixa; Afrodite
+20% (domingo a quinta) ou +5% (sexta, sábado e feriado) e, no fim de semana fora de feriado até hoje + 95 dias, nunca
abaixo de R$ 1.600 públicos (faixa R$ 1.600-1.800, dono 27/10). Villa: King Spa 7 e King Spa 2 no valor da faixa; Balcony
10% abaixo (Regra G). Valor gravado no PriceLabs ≈ público ÷ `parametros.fator_publico` (a ordem usa a razão da noite).
Pisos do dono em preço público: piso enviado (`pace.pisos_enviados`) × fator público, de todas as noites do tipo nos
próximos 12 meses (mínimo e máximo).

Uso
  python3 faixas_dono.py construir --varredura <respostas.json> [...] [--tipos tipos.json] --saida dados/faixas_dono.json
  python3 faixas_dono.py tabela [--faixas dados/faixas_dono.json] [--precos Q7=rec.json ...] [--md saida.md] [--hoje D]
  python3 faixas_dono.py pagina [--faixas ...] [--precos Q7=rec.json ...] [--hoje D] --saida pagina.html
  python3 faixas_dono.py noite --data 2026-12-05 [--faixa 3]
"""
from __future__ import annotations

import argparse
import json
import statistics as st
import sys
from collections import Counter
from datetime import date, timedelta
from pathlib import Path

AQUI = Path(__file__).resolve().parent
RAIZ = AQUI.parent
REPO = RAIZ.parent.parent.parent
sys.path.insert(0, str(AQUI))

FAIXAS = [(1, "Ocupar", 25), (2, "Mercado", 50), (3, "Acima do mercado", 65), (4, "Premium", 75), (5, "Topo", 90)]
PCTS = [p for _, _, p in FAIXAS]
TIPOS = {
    "semana": "Dia de semana (dom-qui)",
    "fds": "Fim de semana (sex-sáb)",
    "feriado": "Feriado",
    "natal": "Natal (23-26/12)",
    "entre_festas": "Entre Natal e Réveillon (27-29/12)",
    "reveillon": "Réveillon (30/12-02/01)",
    "inverno_semana": "Inverno, dia de semana (30/05-31/07)",
    "inverno_fds": "Inverno, fim de semana e feriadões (Corpus Christi a 31/07)",
}
QUARTOS = {"Recanto": ["Q7", "Q2", "Double", "Afrodite"], "Villa": ["VK7", "VK2", "Balcony"]}
NOMES = {"Q7": "Moinhos", "Q2": "Panorâmica II", "Double": "Panorâmica I", "Afrodite": "Afrodite",
         "VK7": "King Spa 7", "VK2": "King Spa 2", "Balcony": "Balcony"}
REF = {"Recanto": "Q7", "Villa": "VK7"}
NOSSOS = {"pousada-vista-panoramica-campos-do-jordao", "villa-dolce-amore-boutique-panoramica",
          "pousada-recanto-dos-moinhos"}
INVERNO_FERIADOES = ("corpus christi", "namorados", "9 de julho")
NAO_FERIADO = ("aniversário de são paulo",)  # o mercado precifica como fim de semana comum (varredura de 01/11)
R_HIDRO_TETO_INCERTO = 2.0
AMOSTRA_MIN_ESTADIAS, AMOSTRA_MIN_CONCORRENTES = 4, 40


def quantil(v: list[float], p: float) -> float:
    """Percentil p (0-100) com interpolação linear (o mesmo da âncora). Lista vazia dá erro."""
    v = sorted(v)
    if not v:
        raise ValueError("sem valores")
    k = (len(v) - 1) * p / 100
    f = int(k)
    c = min(f + 1, len(v) - 1)
    return v[f] + (v[c] - v[f]) * (k - f)


def percentil_de(x: float, v: list[float]) -> float:
    """Em que percentil (0-100) o valor x cai na lista v (fração dos valores abaixo, empate conta metade)."""
    if not v:
        return float("nan")
    abaixo = sum(1 for y in v if y < x)
    igual = sum(1 for y in v if y == x)
    return 100.0 * (abaixo + igual / 2) / len(v)


def posicao(valor: float, faixas: dict, tol: float = 0.05) -> str:
    """Onde um preço em R$ cai entre os valores das faixas: "≈F3", "entre F1 e F2", "abaixo da F1", "acima da F5"."""
    itens = sorted(((int(k[1:]), v) for k, v in faixas.items()), key=lambda x: x[1])
    for n, v in itens:
        if abs(valor - v) <= v * tol:
            return f"≈F{n}"
    if valor < itens[0][1]:
        return "abaixo da F1"
    if valor > itens[-1][1]:
        return "acima da F5"
    for (n1, v1), (n2, v2) in zip(itens, itens[1:]):
        if v1 < valor < v2:
            return f"entre F{n1} e F{n2}"
    return "—"


def _d(s: str) -> date:
    return date.fromisoformat(s[:10])


def feriado_de(d: date, cfg: dict | None) -> str | None:
    """Nome do feriado de `quartos.json` (inicio/fim, o mesmo do piso de feriado) que cobre a noite, ou None."""
    from comum import periodo_feriado
    p = periodo_feriado(cfg or {}, d)
    return (p.get("nome") or "feriado") if p else None


def tipo_da_noite(d: date, cfg: dict | None = None) -> str:
    """Tipo de noite do calendário. Feriados pelo `quartos.json`; inverno = 30/05 a 31/07."""
    md = (d.month, d.day)
    if (12, 23) <= md <= (12, 26):
        return "natal"  # pacote de Natal 23→25 (dono 03/11) e 25-26/12
    if md in ((12, 27), (12, 28), (12, 29)):
        return "entre_festas"  # 29/12 véspera fora do pacote (dono 03/11): mercado igual a 27-28/12 (varredura 04/11)
    if md >= (12, 30) or md <= (1, 2):
        return "reveillon"  # pacotes 30→01 e 30→03 (dono 03/11)
    fer = feriado_de(d, cfg)
    if fer and any(k in fer.lower() for k in NAO_FERIADO):
        fer = None
    inverno = (5, 30) <= md <= (7, 31)
    if fer:
        if inverno or any(k in fer.lower() for k in INVERNO_FERIADOES):
            return "inverno_fds"
        return "feriado"
    fds = d.weekday() in (4, 5)
    if inverno:
        return "inverno_fds" if fds else "inverno_semana"
    return "fds" if fds else "semana"


def r_hidro(h: dict, pad: float) -> float:
    """Fator do quarto com hidro do hotel; fator "incerto" acima de 2,0 fica em 2,0 (revisão de 04/11)."""
    r = float(h.get("r_hidro") or pad)
    if "incerto" in str(h.get("r_hidro_fonte") or "") and r > R_HIDRO_TETO_INCERTO:
        return R_HIDRO_TETO_INCERTO
    return r


def estadia_por_noite(e: dict, cad: dict) -> tuple[list[float], list[float]]:
    """(com hidro, lista) por noite dos concorrentes da estadia (sem os nossos, só os do cadastro)."""
    ref = {h["slug"]: h for h in cad["hoteis"]}
    pad = float(cad["r_hidro_padrao"])
    n = (_d(e["checkout"]) - _d(e["checkin"])).days
    hid, cru = [], []
    for h in e.get("hoteis") or []:
        if not h.get("preco_total") or h.get("slug") in NOSSOS or h.get("slug") not in ref:
            continue
        pn = float(h["preco_total"]) / n
        cru.append(pn)
        hid.append(pn * r_hidro(ref[h["slug"]], pad))
    return hid, cru


def mediana_por_mes(es: list[dict], campo: str, p: int) -> int:
    """Mediana do percentil p dentro de cada mês e depois mediana entre os meses."""
    meses = {}
    for e in es:
        meses.setdefault(e["checkin"][:7], []).append(e[campo][str(p)])
    return round(st.median(st.median(v) for v in meses.values()))


def construir(arquivos: list[str], cad: dict, tipos_forcados: dict, min_n: int = 15) -> dict:
    """Estadias medidas → percentis por estadia e por tipo (mediana por mês, depois entre meses)."""
    cfg = carregar_cfg()
    vistos, estadias = set(), []
    for arq in arquivos:
        for e in json.load(open(arq))["estadias"]:
            chave = (e["checkin"], e["checkout"])
            if chave in vistos:
                continue
            hid, cru = estadia_por_noite(e, cad)
            if len(hid) < min_n:
                continue
            vistos.add(chave)
            tipo = tipos_forcados.get(f"{e['checkin']}/{e['checkout']}") or tipos_forcados.get(e["checkin"]) \
                or tipo_da_noite(_d(e["checkin"]), cfg)
            if tipo == "ignorar":
                continue  # estadia que mistura tipos de noite
            estadias.append({"checkin": e["checkin"], "checkout": e["checkout"], "tipo": tipo, "n": len(hid),
                             "rotulo": e.get("tipo"), "fonte": Path(arq).name,
                             "hidro": {str(p): round(quantil(hid, p)) for p in PCTS},
                             "lista": {str(p): round(quantil(cru, p)) for p in PCTS},
                             "valores_hidro": sorted(round(x, 2) for x in hid)})
    estadias.sort(key=lambda x: x["checkin"])
    por_tipo = {}
    for t in TIPOS:
        es = [e for e in estadias if e["tipo"] == t]
        if not es:
            continue
        conc = round(st.median(e["n"] for e in es))
        por_tipo[t] = {"estadias": len(es), "meses": sorted({e["checkin"][:7] for e in es}), "concorrentes": conc,
                       "de": es[0]["checkin"], "ate": es[-1]["checkout"],
                       "amostra_pequena": len(es) < AMOSTRA_MIN_ESTADIAS or conc < AMOSTRA_MIN_CONCORRENTES,
                       "hidro": {str(p): mediana_por_mes(es, "hidro", p) for p in PCTS},
                       "lista": {str(p): mediana_por_mes(es, "lista", p) for p in PCTS}}
    return {"versao": date.today().isoformat(), "faixas": [{"n": n, "nome": nm, "percentil": p} for n, nm, p in FAIXAS],
            "tipos": TIPOS, "por_tipo": por_tipo, "estadias": estadias,
            "r_hidro": {"padrao": float(cad["r_hidro_padrao"]), "teto_incerto": R_HIDRO_TETO_INCERTO,
                        "medidos": sum(1 for h in cad["hoteis"] if h.get("r_hidro")), "total": len(cad["hoteis"])}}


def carregar_cfg() -> dict:
    from comum import carregar_quartos
    return carregar_quartos()


def pisos_por_tipo(cfg: dict, hoje: date, dias: int = 366) -> dict:
    """{tipo: {curto: {"modal", "min", "max", "noites": [pisos], "excecoes": [{"valor", "noites", "nomes"}]}}}: piso do
    dono em preço público (piso enviado × fator público) em cada noite do tipo de hoje a hoje + `dias`."""
    from pace import pisos_enviados
    fp = cfg["parametros"]["fator_publico"]
    pous_de = {q: p for p, qs in QUARTOS.items() for q in qs}
    brutos = {}
    for i in range(dias):
        d = hoje + timedelta(days=i)
        t = tipo_da_noite(d, cfg)
        nome = feriado_de(d, cfg)
        for q in pous_de:
            pub = round(pisos_enviados(cfg, q, d)[0] * float(fp[pous_de[q]]))
            brutos.setdefault(t, {}).setdefault(q, []).append((pub, nome))
    out = {}
    for t, qs in brutos.items():
        for q, lst in qs.items():
            c = Counter(v for v, _ in lst)
            modal = c.most_common(1)[0][0]
            exc = []
            for v in sorted(set(c) - {modal}):
                nomes = sorted({nome_curto(n) for x, n in lst if x == v})
                exc.append({"valor": v, "noites": c[v], "nomes": nomes})
            out.setdefault(t, {})[q] = {"modal": modal, "min": min(c), "max": max(c), "noites": [v for v, _ in lst],
                                        "excecoes": exc}
    return out


def nome_curto(n: str | None) -> str:
    """Nome curto do feriado para a tabela ("Revolução Constitucionalista, 9 de Julho (...)" → "9 de Julho")."""
    if not n:
        return "outras noites"
    if "9 de julho" in n.lower():
        return "9 de Julho"
    if "véspera 29/12" in n.lower():
        return "véspera do Réveillon, 29/12"
    base = n.split(" (")[0].split(",")[0].strip()
    return base[:-5] if base[-5:-4] == " " and base[-4:].isdigit() else base


def parte_abaixo(valor: float, piso: dict) -> float:
    """Fração das noites do tipo em que o piso do dono fica acima do valor."""
    n = piso.get("noites") or []
    return sum(1 for v in n if v > valor) / len(n) if n else 0.0


def piso_texto(piso: dict) -> str:
    """"R$ 1.500 (R$ 1.200 em Consciência Negra, Tiradentes)"."""
    t = brl(piso["modal"])
    exc = [f"{brl(e['valor'])} em {', '.join(e['nomes'])}" for e in piso.get("excecoes") or []]
    return t + (f" ({'; '.join(exc)})" if exc else "")


def valores_quartos(cfg: dict, pous: str, publico_ref: float, tipo: str, afrodite_fds: bool = False) -> dict:
    """Valor público e gravado (≈ público ÷ fator público) de cada quarto para um valor de faixa (suíte de referência).
    `afrodite_fds`: fim de semana fora de feriado dentro do horizonte da faixa da Afrodite (R$ 1.600-1.800, dono 27/10)."""
    fp = float(cfg["parametros"]["fator_publico"][pous])
    g = float(cfg["parametros"].get("regra_g_desconto", 0.1))
    faixa_af = cfg["parametros"].get("afrodite_fds_publico") or [1600, 1800]
    out = {}
    for q in QUARTOS[pous]:
        nota = None
        if q == "Afrodite":
            pub = publico_ref * (1.20 if tipo in ("semana", "inverno_semana", "entre_festas") else 1.05)
            if afrodite_fds and pub < float(faixa_af[0]):
                pub, nota = float(faixa_af[0]), f"sua faixa da Afrodite no fim de semana ({brl(faixa_af[0])} a {brl(faixa_af[1])})"
            elif afrodite_fds and pub > float(faixa_af[1]):
                nota = f"acima da sua faixa de {brl(faixa_af[1])} porque fica 5% acima da Moinhos"
        elif q == "Balcony":
            pub = publico_ref * (1 - g)
        else:
            pub = publico_ref
        out[q] = {"publico": round(pub), "enviado": round(pub / fp)}
        if nota:
            out[q]["nota"] = nota
    return out


def tabela(fx: dict, cfg: dict, precos: dict | None = None, hoje: date | None = None) -> dict:
    """Linhas por hotel e tipo: valores das 5 faixas (suíte de referência), piso do dono (mínimo e máximo do tipo),
    faixas abaixo do piso (todas as noites) ou em parte delas, e onde estamos hoje (preço de hoje das estadias medidas)."""
    hoje = hoje or date.today()
    fp = cfg["parametros"]["fator_publico"]
    precos = precos or {}
    pisos = pisos_por_tipo(cfg, hoje)
    horiz_af = hoje + timedelta(days=int(cfg["parametros"].get("afrodite_fds_horizonte_dias", 95)))
    out = {}
    for pous, qs in QUARTOS.items():
        ref = REF[pous]
        linhas = []
        for t, nome in TIPOS.items():
            pt = fx["por_tipo"].get(t)
            if not pt:
                continue
            pr = (pisos.get(t) or {}).get(ref) or {"modal": 0, "min": 0, "max": 0, "noites": [], "excecoes": []}
            vals = {f"F{n}": pt["hidro"][str(p)] for n, _, p in FAIXAS}
            # riscada: abaixo do piso em todas as noites; em parte: em 10% ou mais das noites do tipo
            bloqueadas = [k for k, v in vals.items() if parte_abaixo(v, pr) >= 0.999]
            parciais = [k for k, v in vals.items() if 0.10 <= parte_abaixo(v, pr) < 0.999]
            hoje_es = []
            for e in fx["estadias"]:
                if e["tipo"] != t or ref not in precos or _d(e["checkin"]) < hoje:
                    continue
                noites = [(_d(e["checkin"]) + timedelta(days=i)).isoformat()
                          for i in range((_d(e["checkout"]) - _d(e["checkin"])).days)]
                ps = [precos[ref].get(n) for n in noites]
                if any(x is None for x in ps):
                    continue
                pub = st.mean(ps) * float(fp[pous])
                hoje_es.append({"checkin": e["checkin"], "publico": round(pub),
                                "percentil": round(percentil_de(pub, e["valores_hidro"]))})
            if hoje_es:  # mediana por mês e depois entre os meses (como as faixas)
                meses_h = {}
                for h in hoje_es:
                    meses_h.setdefault(h["checkin"][:7], []).append(h["publico"])
                hoje_pub = round(st.median(st.median(v) for v in meses_h.values()))
            else:
                hoje_pub = None
            abaixo_piso_n = sum(1 for h in hoje_es if h["publico"] < pr["min"] * 0.98)
            no_piso = bool(hoje_es) and any(abs(hoje_pub - v) <= 0.02 * max(v, 1) for v in {pr["modal"], pr["min"], pr["max"]})
            af_fds = t == "fds"
            linhas.append({
                "tipo": t, "nome": nome, "faixas": vals, "lista": pt["lista"],
                "piso_min": pr["min"], "piso_max": pr["max"], "piso_modal": pr["modal"], "piso_texto": piso_texto(pr),
                "pisos_quartos": {q: (pisos.get(t) or {}).get(q, {}).get("modal") for q in qs},
                "abaixo_do_piso": bloqueadas, "abaixo_em_parte": parciais,
                "hoje_publico": hoje_pub, "hoje_posicao": ("no seu piso" if no_piso else posicao(hoje_pub, vals))
                if hoje_es else None,
                "hoje_percentil": round(st.median(h["percentil"] for h in hoje_es)) if hoje_es else None,
                "hoje_estadias": hoje_es, "hoje_abaixo_do_piso": abaixo_piso_n,
                "estadias": pt["estadias"], "meses": pt.get("meses", []),
                "nota_medicao": ("medido em feriadões de 2 a 4 noites; Tiradentes (feriado isolado, terça e quarta) não foi "
                                 "medido") if t == "feriado" else None,
                "concorrentes": pt["concorrentes"], "amostra_pequena": pt.get("amostra_pequena", False),
                "quartos": {f"F{n}": valores_quartos(cfg, pous, pt["hidro"][str(p)], t, af_fds) for n, _, p in FAIXAS},
                "afrodite_fds_ate": horiz_af.isoformat() if pous == "Recanto" else None})
        out[pous] = linhas
    return out


def calendario(cfg: dict, saz: dict, hoje: date, dias: int = 366) -> list[dict]:
    """Períodos da sazonalidade de hoje a hoje + `dias`, com os tipos de noite da tabela que caem em cada um (todos os
    tipos e quantas noites; o filtro de dias do período vale: todos, dom-qui ou sex-sab)."""
    fim_h = hoje + timedelta(days=dias)
    out = []
    for p in saz.get("periodos") or []:
        ini, fim = _d(p["inicio"]), _d(p["fim"])
        if fim < hoje or ini > fim_h:
            continue
        c = Counter()
        d = max(ini, hoje)
        while d <= min(fim, fim_h):
            sexsab = d.weekday() in (4, 5)
            if p.get("dias") == "todos" or (p.get("dias") == "sex-sab") == sexsab:
                c[tipo_da_noite(d, cfg)] += 1
            d += timedelta(days=1)
        if not c:
            continue
        out.append({"nome": p.get("nome"), "inicio": p["inicio"], "fim": p["fim"], "dias": p.get("dias"),
                    "nivel": p.get("nivel"), "tipo": c.most_common(1)[0][0],
                    "tipos": [{"tipo": t, "noites": n} for t, n in c.most_common()]})
    return out


def brl(x: float) -> str:
    return "R$ " + f"{x:,.0f}".replace(",", ".")


def dm(s: str) -> str:
    d = _d(s)
    return f"{d.day:02d}/{d.month:02d}/{str(d.year)[2:]}"


def markdown(tab: dict, fx: dict, hoje: date) -> str:
    rh = fx.get("r_hidro") or {}
    L = ["# Faixas de preço por hotel", "",
         f"Medição: {fx.get('medido_em') or fx.get('versao')}. Coluna \"Hoje\": preços do PriceLabs de {hoje:%d/%m/%Y}. "
         "Página interativa: \"Régua de Preços das Pousadas\" (link no relatório).", "",
         "Preço por noite que o hóspede vê na Booking, quarto com hidromassagem, café, 2 adultos. Régua: 77 concorrentes "
         "com hidro e vista; a Booking mostra o quarto mais barato de cada hotel e o quarto com hidro é estimado por um "
         f"fator do hotel (medido em {rh.get('medidos', '?')} dos {rh.get('total', 77)}; nos outros, 1,395).", "",
         "| Faixa | Percentil | Leitura |", "|---|---|---|",
         "| F1 Ocupar | p25 | 25% dos concorrentes cobram menos que isso |",
         "| F2 Mercado | p50 | metade cobra menos |", "| F3 Acima do mercado | p65 | 65% cobram menos |",
         "| F4 Premium | p75 | 75% cobram menos |", "| F5 Topo | p90 | só 10% cobram mais |", "",
         "**Como pedir:** \"Recanto, fins de semana de dezembro, faixa 4\". Eu gravo os pisos de cada noite no valor da "
         "faixa (medido na noite, perto do valor da tabela), a noite sobe com as vendas até a F5 e, a partir de 7 dias "
         "antes da data, se não entrou reserva desde o pedido, o piso desce para a faixa de baixo ou até o seu piso, o "
         "que for maior. Nunca abaixo do seu piso. O motor grava de hoje a 60 dias: pedido para mais longe passa a valer quando a data entra nessa janela.",
         "",
         "**O que já vale hoje:** " + JA_VALE, "",
         "A régua de mercado é a mesma nas duas pousadas; mudam o seu piso, o preço de hoje e a relação entre os quartos.",
         ""]
    for pous, linhas in tab.items():
        ref = NOMES[REF[pous]]
        regra = ("Panorâmica I e Panorâmica II no mesmo valor da Moinhos; Afrodite +20% de domingo a quinta e +5% na "
                 "sexta, no sábado e no feriado (no fim de semana, até " + dm(linhas[0].get("afrodite_fds_ate") or
                 hoje.isoformat()) + ", nunca abaixo de R$ 1.600, sua faixa de 27/10)."
                 if pous == "Recanto" else "King Spa 2 no mesmo valor da King Spa 7; Balcony 10% abaixo (não tem banheira).")
        L += [f"## {'Pousada Recanto dos Moinhos' if pous == 'Recanto' else 'Villa Dolce Amore'} (valor da {ref})", "",
              regra, "",
              "| Tipo de noite | F1 Ocupar | F2 Mercado | F3 Acima | F4 Premium | F5 Topo | Seu piso | Hoje |",
              "|---|---|---|---|---|---|---|---|"]
        for ln in linhas:
            cel = []
            for k, v in ln["faixas"].items():
                marca = " ✗" if k in ln["abaixo_do_piso"] else (" ◐" if k in ln["abaixo_em_parte"] else "")
                cel.append(brl(v) + marca)
            piso = ln["piso_texto"]
            hoje_t = f"{brl(ln['hoje_publico'])} ({ln['hoje_posicao']})" if ln["hoje_publico"] else "—"
            nome = ln["nome"] + (" ⚠ amostra pequena" if ln["amostra_pequena"] else "")
            L.append(f"| {nome} | " + " | ".join(cel) + f" | {piso} | {hoje_t} |")
        L.append("")
        for ln in linhas:
            if ln.get("nota_medicao"):
                L.append(f"- {ln['nome']}: {ln['nota_medicao']}.")
            if ln.get("hoje_abaixo_do_piso"):
                L.append(f"- {ln['nome']}: hoje {ln['hoje_abaixo_do_piso']} noite(s) medida(s) abaixo do seu piso, por ordem "
                         "sua que suspende o piso nelas.")
        L.append("")
    L += ["✗ = abaixo do seu piso em todas as noites do tipo (não usamos sem você liberar). ◐ = abaixo do piso em parte "
          "das noites (10% ou mais; ex.: feriados com piso de R$ 1.000 líquidos contra R$ 800 em outros). ⚠ amostra pequena = menos "
          f"de {AMOSTRA_MIN_ESTADIAS} estadias ou menos de {AMOSTRA_MIN_CONCORRENTES} concorrentes com preço: refazer "
          "a medição uns 60 dias antes.", ""]
    return "\n".join(L)


JA_VALE = ("Recanto: dias de semana de 08 a 30/11 e 8 fins de semana de 13/11 a 30/01 seguem a régua com hidro que você "
           "aprovou em 03/11 (perto da F3; 08 a 12/11 já desceram ao seu piso pelo gatilho de 7 dias). Villa: fins de semana "
           "de 06/11 a 19/12 seguem a régua com hidro de 02/11 (entre F2 e F3); os dias de semana de novembro seguem a sua "
           "ordem de 01/11 pelo mercado geral (entre F1 e F2, abaixo do seu piso em algumas noites). Consciência Negra: "
           "ordem de 01/11 pelo mercado geral do motor, que fica abaixo desta régua. Nas outras noites o motor usa o mercado "
           "geral e os seus pisos, sem faixa.")


METODO = [
    "Busca pelo conector oficial da Booking: 1 quarto, 2 adultos, café da manhã, nos 77 concorrentes com hidromassagem "
    "e vista da sua lista (sem a Recanto, a Villa e a Vista Panorâmica).",
    "A Booking mostra o quarto mais barato de cada hotel. O quarto com hidro é estimado por um fator de cada hotel "
    "(medido em {medidos} dos {total}; nos outros, 1,395; fator incerto acima de 2,0 limitado a 2,0). Estadias de 1 a 4 "
    "noites: o total é dividido pelo número de noites.",
    "Cada faixa é a mediana do mesmo percentil nas estadias medidas daquele tipo de noite (primeiro por mês, depois "
    "entre os meses).",
    "Valor gravado no PriceLabs ≈ preço na Booking ÷ 0,63 na Recanto e ÷ 0,65 na Villa. A ordem de cada noite usa a "
    "razão medida pela antecedência (na última semana a Booking dá desconto de última hora e o hóspede vê cerca de 10% "
    "menos).",
    "Seu piso: R$ 500 no Genius 3 de domingo a quinta, R$ 850 na sexta e no sábado, R$ 1.000 líquidos no feriado (R$ 800 "
    "na Consciência Negra, no Aniversário de SP e em Tiradentes), convertidos para o preço da Booking. Ordens suas que suspendem o piso em "
    "algumas noites (ex.: Villa nos dias de semana de novembro) valem por cima desta tabela.",
    "Hoje: preço de hoje da suíte de referência na Booking (gravado × fator) nas noites medidas daquele tipo que ainda não "
    "passaram, mediana por mês e depois entre os meses.",
    "A régua de mercado é a mesma nas duas pousadas (os mesmos 77 concorrentes); mudam o seu piso, o preço de hoje e a "
    "relação entre os quartos.",
    "Preços de 2027 medidos com 3 a 11 meses de antecedência: menos hotéis com preço aberto (20 a 57) e alguns com tarifa "
    "de balcão. Refazemos a medição de cada período uns 60 dias antes.",
]


def pagina(fx: dict, cfg: dict, precos: dict, hoje: date, modelo: Path, saida: Path) -> dict:
    """Página HTML da régua (modelo `faixas_pagina.html` com os dados embutidos)."""
    tab = tabela(fx, cfg, precos, hoje)
    saz = json.load(open(RAIZ / "dados/sazonalidade.json"))
    es = fx["estadias"]
    rh = fx.get("r_hidro") or {"medidos": "?", "total": 77}
    dados = {"hoteis": tab, "hoje": hoje.isoformat(), "faixas": fx["faixas"], "tipos": TIPOS, "nomes": NOMES,
             "calendario": calendario(cfg, saz, hoje),
             "metodo": [m.format(medidos=rh.get("medidos"), total=rh.get("total")) for m in METODO],
             "medido_em": fx.get("medido_em") or fx.get("versao"), "n_estadias": len(es),
             "n_lista": rh.get("total", 77), "r_hidro_medidos": rh.get("medidos"), "ja_vale": JA_VALE,
             "fator_publico": {k: float(v) for k, v in cfg["parametros"]["fator_publico"].items()
                               if k in ("Recanto", "Villa")}}
    html = modelo.read_text().replace("/*DATA*/null", json.dumps(dados, ensure_ascii=False))
    saida.write_text(html)
    return dados


# --- Regra de faixas do dono (04/11/2026) → ordens alvo_por_data por noite -----------------------------------------
# revisão de 04/11: Afrodite e Balcony também, como na tabela que o dono viu (Afrodite +5% sex/sáb/feriado e +20% de
# domingo a quinta; Balcony 10% abaixo, Regra G). Sem elas, no Natal a Afrodite ficava presa a 5% acima das outras
# (limitadas ao máximo do anúncio) e a Balcony ficava fora da F5 dos feriados (a Regra G no feriado só baixa).
QUARTOS_ORDEM = {"Recanto": ["Q7", "Q2", "Double", "Afrodite"], "Villa": ["VK7", "VK2", "Balcony"]}
RAZAO = {"Recanto": 0.62, "Villa": 0.65}   # Booking ÷ enviado longe da data (medido: Recanto 0,61-0,63; Villa 0,62-0,65)
RAZAO_ULTIMA_SEMANA = 0.9                  # a até 7 dias a Booking mostra ~10% menos (Recanto: 0,5557 contra 0,6156)
PCT = {n: p for n, _, p in FAIXAS}


def fator_quarto(cfg: dict, q: str, d: date) -> float:
    """Valor do quarto em relação à faixa (suíte de referência): Afrodite pelo prêmio dela (dono 22 e 27/10), Balcony
    pela Regra G (dono 07/10), as outras no valor da faixa."""
    if q == "Afrodite":
        from ordem_recanto import premio_afrodite
        return 1 + float(premio_afrodite(cfg, d))
    if q == "Balcony":
        return 1 - float(cfg["parametros"].get("regra_g_desconto", 0.1))
    return 1.0


def regua_noite(fx: dict, d: date, tipo: str) -> tuple[dict, str]:
    """Percentis da régua com hidro na noite (revisão de 04/11): mediana das estadias medidas do mesmo tipo que começam
    na noite; sem elas, das que contêm a noite (25/12: a estadia 25->26, não as que começam em 24/12, fora do pacote);
    sem nenhuma, das estadias do mesmo tipo mais perto (até 3 dias: 20/12 usa 21-23/12, o Pré-Natal, e não a mediana
    de 10 meses de baixa temporada); por fim, o valor da tabela do tipo. ({"25": v, ...}, origem)."""
    mesmas = [e for e in fx["estadias"] if e["tipo"] == tipo]
    es = [e for e in mesmas if _d(e["checkin"]) == d]
    origem = "medida na noite"
    if not es:
        es = [e for e in mesmas if _d(e["checkin"]) <= d < _d(e["checkout"])]
    if not es:
        dist = {id(e): min(abs((_d(e["checkin"]) - d).days), abs((_d(e["checkout"]) - timedelta(days=1) - d).days))
                for e in mesmas}
        perto = [e for e in mesmas if dist[id(e)] <= 3]
        if perto:
            m = min(dist[id(e)] for e in perto)
            es = [e for e in perto if dist[id(e)] == m]
            origem = f"medida em noite vizinha ({es[0]['checkin'][8:10]}/{es[0]['checkin'][5:7]}, {m} dia{'s' if m > 1 else ''})"
    if es:
        return ({str(p): round(st.median(e["hidro"][str(p)] for e in es)) for p in PCTS},
                f"{origem} ({len(es)} estadia{'s' if len(es) > 1 else ''})")
    return dict(fx["por_tipo"][tipo]["hidro"]), "tabela do tipo"


def razao(pous: str, k: int) -> float:
    return RAZAO[pous] * (RAZAO_ULTIMA_SEMANA if k <= 7 else 1.0)


DIAS = {"dom-qui": (6, 0, 1, 2, 3), "sex-sab": (4, 5), "todos": (0, 1, 2, 3, 4, 5, 6)}


def regra_da_noite(regra: dict, d: date, tipo: str, pous: str | None = None) -> dict | None:
    """Sub-regra da decisão que vale na noite (e na pousada): filtra por `pousadas` (da decisão e da sub-regra),
    `tipos`, período `de`/`ate`, `dias` (dom-qui, sex-sab, todos) e `meses`/`noites`. None quando não cobre."""
    if pous and regra.get("pousadas") and pous not in regra["pousadas"]:
        return None
    n = d.isoformat()
    for r in regra.get("regras") or []:
        if pous and r.get("pousadas") and pous not in r["pousadas"]:
            continue
        if r.get("tipos") and tipo not in r["tipos"]:
            continue
        if (r.get("de") and n < r["de"]) or (r.get("ate") and n > r["ate"]):
            continue
        if r.get("dias") and d.weekday() not in DIAS.get(r["dias"], DIAS["todos"]):
            continue
        if (r.get("meses") or r.get("noites")) and n[:7] not in (r.get("meses") or []) \
                and n not in (r.get("noites") or []):
            continue
        return r
    return None


def decisoes_de(arq: dict) -> list[dict]:
    """Lista de decisões do dono (dados/faixas_decisoes.json → `decisoes`), da mais antiga para a mais nova.
    Aceita o formato antigo (uma regra só no topo do arquivo)."""
    ds = arq.get("decisoes") if "decisoes" in arq else [arq]
    return sorted(ds, key=lambda x: (x.get("data") or "", str(x.get("criada_em") or "")))


def decisao_da_noite(decisoes: list[dict], d: date, tipo: str, pous: str) -> tuple[dict, dict] | tuple[None, None]:
    """A decisão mais nova que cobre a noite na pousada (fora as `noites_fora` dela) e a sub-regra que vale."""
    for dec in reversed(decisoes):
        if d.isoformat() in (dec.get("noites_fora") or []):
            continue
        r = regra_da_noite(dec, d, tipo, pous)
        if r:
            return dec, r
    return None, None


def vendidas_inv(inv: dict, q: str, n: str, unidades: int) -> int:
    x = (inv.get(q) or {}).get(n) or {}
    if x.get("numAvail") is None:
        return 0
    return max(0, min(unidades, unidades - int(x["numAvail"])))


def gerar_ordens(fx: dict, cfg: dict, regra: dict, inv: dict, hoje: date, dias: int, existentes: dict,
                 criada_em: str, vale=None) -> tuple[list[dict], dict]:
    """(ordens novas, {ordem_id antiga: [noites que saem dela]}). Uma ordem por noite e pousada, como as de 02 e 03/11:
    pisos na faixa (÷ razão; nunca abaixo do piso do dono nem acima do máximo do anúncio), topo na F5, e perto da data
    o gatilho de D7 (F2/F3: desce uma faixa sem venda nova) ou a descida gradual (F5: degraus F4, F3, F2, F1 em D7, D5,
    D3 e D1 com o vendido abaixo da curva). Pula noites que já têm ordem desta regra (`id_base`), noites a D0-D1 e,
    por quarto, noites com ordem do dono mais nova que a regra (ela vence)."""
    from pace import pisos_enviados
    pc = cfg["por_curto"]
    base_id = regra["id_base"]
    ja = {(o.get("pousada_faixa"), n) for o in existentes.values() if o["id"].startswith(base_id + "-")
          for n in o.get("noites") or []}
    from ordens import cobre

    def pega(x: dict, q: str, n: str, d: date) -> bool:
        """Ordem antiga que a faixa substitui na noite e no quarto (regras.md 4e)."""
        if x["id"].startswith(base_id + "-"):
            return False
        # acompanhamento só os de preço listados na regra (ex.: gatilhos das festas); o de estadia/calendário fica
        # (revisão de 04/11: dono-2026-11-02-aniv-sp-gatilho perderia as suítes quando 24/01 entrasse na janela)
        acomp = x.get("tipo") == "acompanhamento" and x["id"] in (regra.get("substitui_acompanhamento") or [])
        if not (x.get("tipo") in ("alvo_por_data", "piso_por_data") or acomp
                or n in ((x.get("vendidas_base") or {}).get(q) or {})):
            return False
        return cobre(x, q, d) and (not x.get("noites") or n in x["noites"])

    def mais_nova(x: dict) -> bool:
        """Ordem do dono dada depois da regra (data maior, ou mesma data e criada_em posterior): vence a faixa."""
        dx, dr = x.get("data") or "", regra["data"]
        return dx > dr or (dx == dr and bool(x.get("criada_em")) and bool(regra.get("criada_em"))
                           and str(x["criada_em"]) > str(regra["criada_em"]))

    def bloqueia(x: dict, q: str, n: str, d: date) -> bool:
        """A noite do quarto fica fora da faixa: ordem do dono mais nova que a regra; preço por data ou mudança manual
        do dono (específicas, vencem a regra geral: Balcony 08-09/11); acompanhamento de estadia/calendário ainda
        aberto (domingo 24/01, gatilho do Aniversário de SP)."""
        if x["id"].startswith(base_id + "-") or not cobre(x, q, d) or (x.get("noites") and n not in x["noites"]):
            return False
        if x.get("tipo") in ("preco_por_data", "manual"):
            return True
        if x.get("tipo") == "acompanhamento" and x["id"] not in (regra.get("substitui_acompanhamento") or []):
            return True
        return mais_nova(x) and pega(x, q, n, d)

    fora_regra = set(regra.get("noites_fora") or [])
    novas, fora = [], {}
    # a partir de D2: a véspera já está decidida e no canal (a ordem nova não sobe nem derruba a noite de amanhã; nas
    # rotinas seguintes a noite já tem a ordem desde D60)
    for i in range(2, dias + 1):
        d = hoje + timedelta(days=i)
        n = d.isoformat()
        t = tipo_da_noite(d, cfg)
        if n in fora_regra or not any(regra_da_noite(regra, d, t, p) for p in QUARTOS_ORDEM):
            continue
        reg, origem = regua_noite(fx, d, t)
        for pous, qs_todos in QUARTOS_ORDEM.items():
            r = regra_da_noite(regra, d, t, pous)
            if not r or (pous, n) in ja or (vale is not None and not vale(pous, n)):
                continue
            f = int(r["faixa"])
            # ordem do dono mais nova, preço por data, mudança manual ou acompanhamento aberto: o quarto fica fora
            qs = [q for q in qs_todos if not any(bloqueia(x, q, n, d) for x in existentes.values())]
            if not qs:
                continue
            # revisão de 04/11: piso e topo com a razão de longe em todas as noites (antes a noite criada a D7 ou menos
            # ganhava ÷0,9 e ficava 11% acima da vizinha); degraus e piso de trás com a razão da última semana
            rz, rz7 = razao(pous, 60), razao(pous, 0)
            pisos, topo, atras, degraus, vb, limitado, los = {}, {}, {}, {}, {}, [], {}
            tot = sum(vendidas_inv(inv, q, n, int(pc[q]["unidades"])) for q in QUARTOS[pous] if q != "Balcony")
            for q in qs:
                mx = float(pc[q]["max"])
                fac = fator_quarto(cfg, q, d)
                # nunca abaixo do piso do dono (página "Como pedir": "Nunca abaixo do seu piso"); a suspensão de piso
                # das ordens antigas substituídas (ex.: base do mercado da Villa, 01/11) não passa para a faixa
                dono = pisos_enviados(cfg, q, d)[0]
                lo = max(dono, float(pc[q]["min"]))
                los[q] = lo
                v = reg[str(PCT[f])] * fac / rz
                if v > mx:
                    limitado.append(q)
                pisos[q] = round(min(mx, max(v, lo)), 2)
                topo[q] = round(min(mx, max(reg["90"] * fac / rz, pisos[q])), 2)
                # degraus e piso de trás sempre com o piso do quarto (a ordem pode levar 'abaixo_piso' por outro quarto)
                if r.get("descida_gradual"):
                    degraus[q] = [round(min(pisos[q], max(reg[str(PCT[x])] * fac / rz7, lo)), 2)
                                  for x in range(f - 1, 0, -1)]
                elif f > 1:
                    # página e tabela que o dono leu ("Como pedir"): a 7 dias sem reserva nova desde o pedido, o piso
                    # desce à faixa de baixo ou ao piso dele (gatilho do dono de 03/11)
                    atras[q] = round(min(pisos[q], max(reg[str(PCT[f - 1])] * fac / rz7, lo)), 2)
                u = int(pc[q]["unidades"])
                vb[q] = {n: tot if u <= 2 else vendidas_inv(inv, q, n, u)}
            ks = [x for x in ("VK7", "VK2") if x in qs]
            if "Balcony" in qs and ks:
                # Regra G (dono 07/10): a Balcony nunca acima de 90% da King Spa mais barata, também quando as King Spa
                # param no máximo do anúncio (Réveillon) ou descem pelo gatilho; nunca abaixo do piso da Balcony
                g = 1 - float(cfg["parametros"].get("regra_g_desconto", 0.1))
                lb = los["Balcony"]
                pisos["Balcony"] = round(max(lb, min(pisos["Balcony"], g * min(pisos[x] for x in ks))), 2)
                topo["Balcony"] = round(max(pisos["Balcony"], min(topo["Balcony"], g * min(topo[x] for x in ks))), 2)
                if "Balcony" in degraus:
                    degraus["Balcony"] = [round(max(lb, min(v, g * min(degraus[x][j] for x in ks))), 2)
                                          for j, v in enumerate(degraus["Balcony"])]
                if "Balcony" in atras:
                    atras["Balcony"] = round(max(lb, min(atras["Balcony"], g * min(atras[x] for x in ks))), 2)
            o = {"id": f"{base_id}-{pous.lower()}-{n.replace('-', '')}", "data": regra["data"], "criada_em": criada_em,
                 "texto": regra["texto"], "contexto": regra.get("contexto", ""), "pousada_faixa": pous,
                 "quartos": qs, "de": n, "ate": n, "noites": [n], "tipo": "alvo_por_data", "percentil": PCT[f],
                 "faixa": f, "passo_livre": True, "escada_vendas": True, "pisos_enviados": pisos, "topo_enviados": topo,
                 "vendidas_base": vb, "libera": ["acima_15"], "ocupacao_max": 0.7, "frente_ocupacao": 0.5}
            if degraus:
                o["degraus_atras"], o["degraus_dias"] = degraus, [7, 5, 3, 1][:f - 1]
            elif atras:
                # gatilho por bloco (revisão de 04/11, como as ordens de 03/11): a semana (dom-qui) ou o fim de semana
                # (sex-sáb) desce junto quando a 1ª noite do bloco chega a D7, sem a mais vendida ficar mais barata;
                # e só depois de 2 dias de ordem (a F2 recebe a chance de vender antes de cair)
                ini = 6 if t == "semana" else 4
                o["piso_atras"], o["gatilho_dias"] = atras, 7 + (d.weekday() - ini) % 7
                o["gatilho_min_dias"] = 2
            o["nota"] = (f"F{f} ({TIPOS[t]}): régua com hidro {origem}, p{PCT[f]} R$ {reg[str(PCT[f])]} público, p90 "
                         f"R$ {reg['90']}; ÷ {rz:.3f} (Booking/enviado; ÷ {rz7:.3f} nos degraus de D7 ou menos); nunca abaixo do piso do dono. "
                         + ("Descida gradual a partir de D7 com o vendido abaixo da curva: " + ", ".join(
                             f"D{dd} F{f - j - 1}" for j, dd in enumerate(o.get("degraus_dias", []))) + ". "
                            if degraus else ("Gatilho de D7 sem venda nova: F" + str(f - 1) + " (ou o piso do dono). "
                                             if atras else ""))
                         + ("Afrodite pelo prêmio dela e Balcony 10% abaixo (Regra G). "
                            if any(q in qs for q in ("Afrodite", "Balcony")) else "")
                         + (f"Limitado ao máximo do anúncio em {', '.join(NOMES[q] for q in limitado)} (subir o máximo "
                            f"é do dono). " if limitado else "")
                         + "Como desfazer: pedir 'Volte às ordens anteriores de " + dm(n) + "'.")
            novas.append(o)
            for oid, x in existentes.items():
                for q in qs:
                    if pega(x, q, n, d):
                        fora.setdefault(oid, {}).setdefault(q, set()).add(n)
    return novas, {k: {q: sorted(v) for q, v in qd.items()} for k, qd in fora.items()}


def gerar_todas(fx: dict, cfg: dict, decisoes: list[dict], inv: dict, hoje: date, dias: int, existentes: dict,
                criada_em: str) -> tuple[list[dict], dict, dict]:
    """Todas as decisões do dono (dono 04/11: faixa por hotel e período): em cada noite e pousada vale a decisão mais
    nova que a cobre; cada decisão gera as ordens das noites em que vale e tira essas noites das ordens anteriores
    (inclusive ordens de faixa de decisões mais velhas). (novas, fora, {id da decisão: nº de ordens})."""
    efetiva = {}
    for i in range(2, dias + 1):
        d = hoje + timedelta(days=i)
        t = tipo_da_noite(d, cfg)
        for pous in QUARTOS_ORDEM:
            dec, _ = decisao_da_noite(decisoes, d, t, pous)
            if dec:
                efetiva[(pous, d.isoformat())] = dec["id_base"]
    novas, fora, cont = [], {}, {}
    for dec in decisoes:
        ns, fr = gerar_ordens(fx, cfg, dec, inv, hoje, dias, existentes, criada_em,
                              vale=lambda p, n, b=dec["id_base"]: efetiva.get((p, n)) == b)
        novas += ns
        cont[dec["id_base"]] = len(ns)
        for oid, qd in fr.items():
            for q, nn in qd.items():
                fora.setdefault(oid, {}).setdefault(q, set()).update(nn)
    return novas, {k: {q: sorted(v) for q, v in qd.items()} for k, qd in fora.items()}, cont


def aplicar_ordens(arquivo: Path, novas: list[dict], fora: dict, regra: dict) -> None:
    d = json.load(open(arquivo))
    ids = {o["id"] for o in d["ordens"]}
    for o in d["ordens"]:
        if o["id"] in fora:
            nf = o.get("noites_fora") or {}
            if isinstance(nf, list):  # lista antiga vale para todos os quartos da ordem
                nf = {q: list(nf) for q in o.get("quartos") or []}
            for q, ns in fora[o["id"]].items():
                nf[q] = sorted(set(nf.get(q) or []) | set(ns))
            o["noites_fora"] = nf
            todas = sorted({n for ns in fora[o["id"]].values() for n in ns})
            quem = ", ".join(NOMES.get(q, q) for q in fora[o["id"]])
            o["nota"] = (o.get("nota") or "") + (f" {regra['data']}: noites {', '.join(dm(n) for n in todas)} de {quem} "
                                                 f"passaram às ordens de faixa {regra['id_base']} (dono: faixas).")
    for o in novas:
        if o["id"] not in ids:
            d["ordens"].append(o)
    Path(arquivo).write_text(json.dumps(d, ensure_ascii=False, indent=1))


def ler_precos(pares: list[str]) -> dict:
    out = {}
    for x in pares or []:
        k, v = x.split("=", 1)
        d = json.load(open(v))
        arr = (d.get("data") or {}).get("pricing_array") or d.get("pricing_array") or []
        out[k] = {r["date"]: float(r["price"]) for r in arr if r.get("price") not in (None, "")}
    return out


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    c = sub.add_parser("construir")
    c.add_argument("--varredura", nargs="+", required=True)
    c.add_argument("--tipos", help="JSON {checkin ou checkin/checkout: tipo ou 'ignorar'}")
    c.add_argument("--cadastro", default=str(REPO / "pricelabs/pesquisa-booking/concorrentes.json"))
    c.add_argument("--saida", default=str(RAIZ / "dados/faixas_dono.json"))
    c.add_argument("--medido-em")
    t = sub.add_parser("tabela")
    t.add_argument("--faixas", default=str(RAIZ / "dados/faixas_dono.json"))
    t.add_argument("--precos", nargs="*", default=[])
    t.add_argument("--md")
    t.add_argument("--hoje", default=date.today().isoformat())
    pg = sub.add_parser("pagina")
    pg.add_argument("--faixas", default=str(RAIZ / "dados/faixas_dono.json"))
    pg.add_argument("--precos", nargs="*", default=[])
    pg.add_argument("--hoje", default=date.today().isoformat())
    pg.add_argument("--modelo", default=str(AQUI / "faixas_pagina.html"))
    pg.add_argument("--saida", required=True)
    od = sub.add_parser("ordens", help="ordens por faixa da regra do dono nas noites de hoje+1 a hoje+dias")
    od.add_argument("--regra", default=str(RAIZ / "dados/faixas_decisoes.json"), help="decisões do dono por faixa")
    od.add_argument("--faixas", default=str(RAIZ / "dados/faixas_dono.json"))
    od.add_argument("--inv", required=True, help="beds24.py inventario --json")
    od.add_argument("--hoje", default=date.today().isoformat())
    od.add_argument("--dias", type=int, default=60)
    od.add_argument("--criada-em", required=True)
    od.add_argument("--saida", required=True)
    od.add_argument("--aplicar", action="store_true", help="grava em dados/ordens_dono.json")
    n = sub.add_parser("noite")
    n.add_argument("--data", required=True)
    n.add_argument("--faixa", type=int)
    n.add_argument("--faixas", default=str(RAIZ / "dados/faixas_dono.json"))
    a = ap.parse_args(argv)
    if a.cmd == "construir":
        cad = json.load(open(a.cadastro))
        tf = json.load(open(a.tipos)) if a.tipos else {}
        fx = construir(a.varredura, cad, tf)
        if a.medido_em:
            fx["medido_em"] = a.medido_em
        Path(a.saida).write_text(json.dumps(fx, ensure_ascii=False, indent=1))
        for t2, pt in fx["por_tipo"].items():
            print(f"{t2:15s} {pt['estadias']:2d} estadias {len(pt['meses'])} meses {pt['concorrentes']} conc"
                  f"{' (amostra pequena)' if pt['amostra_pequena'] else ''}  hidro {pt['hidro']}")
        return 0
    fx = json.load(open(a.faixas))
    cfg = carregar_cfg()
    if a.cmd == "tabela":
        tab = tabela(fx, cfg, ler_precos(a.precos), _d(a.hoje))
        md = markdown(tab, fx, _d(a.hoje))
        if a.md:
            Path(a.md).write_text(md)
        print(md)
        return 0
    if a.cmd == "ordens":
        from ordens import carregar_ordens
        decisoes = decisoes_de(json.load(open(a.regra)))
        existentes = carregar_ordens()
        novas, fora, cont = gerar_todas(fx, cfg, decisoes, json.load(open(a.inv)), _d(a.hoje), a.dias, existentes,
                                        a.criada_em)
        Path(a.saida).write_text(json.dumps({"novas": novas, "fora": fora, "por_decisao": cont}, ensure_ascii=False,
                                            indent=1))
        print(f"{len(novas)} ordens novas ({', '.join(f'{k}: {v}' for k, v in cont.items() if v) or 'nenhuma'}); "
              f"noites tiradas de {len(fora)} ordens antigas")
        if a.aplicar:
            aplicar_ordens(RAIZ / "dados/ordens_dono.json", novas, fora, {"data": _d(a.hoje).isoformat(),
                                                                        "id_base": "dono-*-faixa"})
            print("gravado em dados/ordens_dono.json")
        return 0
    if a.cmd == "pagina":
        dados = pagina(fx, cfg, ler_precos(a.precos), _d(a.hoje), Path(a.modelo), Path(a.saida))
        print(f"página em {a.saida}: {dados['n_estadias']} estadias, {len(dados['calendario'])} períodos")
        return 0
    d = _d(a.data)
    t2 = tipo_da_noite(d, cfg)
    pt = fx["por_tipo"].get(t2)
    print(f"{d} → {TIPOS[t2]}")
    if not pt:
        print("sem medição deste tipo")
        return 1
    for nf, nome, p in FAIXAS:
        if a.faixa and nf != a.faixa:
            continue
        for pous in QUARTOS:
            af = t2 == "fds" and feriado_de(d, cfg) is None and d <= date.today() + timedelta(
                days=int(cfg["parametros"].get("afrodite_fds_horizonte_dias", 95)))
            v = valores_quartos(cfg, pous, pt["hidro"][str(p)], t2, af)
            print(f"F{nf} {nome} (p{p}) {pous}: " + "; ".join(f"{NOMES[q]} {brl(x['publico'])} público / "
                                                          f"{brl(x['enviado'])} gravado" for q, x in v.items()))
    return 0


if __name__ == "__main__":
    sys.exit(main())

"""Tabela de estratégia de faixas por hotel e período e a pergunta diária ao dono.

Dono, 04/11/2026: "Agora vamos sempre me perguntar aqui, qual a faixa de preço que queremos ficar em cada hotel? Sempre
para que possamos aumentar o faturamento sempre ao máximo, tudo de forma evolutiva e cria uma tabela completa para que eu
possa avaliar quais as estratégias que devemos adotar com suas recomendações." Respostas do dono no mesmo dia: perguntar
todo dia na rotina das 07:53; decidir por hotel e período.

Períodos = os de `dados/sazonalidade.json` (nome, dias dom-qui / sex-sab / todos, nível de demanda). Para cada hotel e
período: faixas F1-F5 (régua com hidro, `faixas_dono.regua_noite`), piso do dono, preço de hoje, vendido contra a curva,
ocupação do mercado (opcional), faixa em vigor (`dados/faixas_decisoes.json`) e, por faixa, ocupação final e receita a
vender previstas pelo modelo de `dados/faixas_modelo.json`. Recomendação = faixa de maior receita prevista (empate: a de
maior ocupação). O modelo é recalibrado com o que cada período vendeu (`registrar` todo dia, `calibrar` na segunda).

Uso:
  python3 estrategia_faixas.py tabela --inv INV.json [--precos Q7=rec.json VK7=rec.json ...]
          [--mercado Recanto=nb.json Villa=nb.json] [--hoje AAAA-MM-DD] [--dias 365] --saida tabela.json [--md x.md]
  python3 estrategia_faixas.py perguntas --tabela tabela.json [--max 8]
  python3 estrategia_faixas.py decidir --hoje AAAA-MM-DD --criada-em <UTC> --texto "<resposta literal do dono>"
          --item "Recanto|<id do período>|4" [--item ...]
  python3 estrategia_faixas.py registrar --tabela tabela.json
  python3 estrategia_faixas.py calibrar [--hoje AAAA-MM-DD]
"""

from __future__ import annotations

import argparse
import json
import statistics as st
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import faixas_dono as fd  # noqa: E402
from faixas_dono import (DIAS, FAIXAS, PCT, QUARTOS, RAIZ, _d, brl, decisao_da_noite, decisoes_de, dm,  # noqa: E402
                         fator_quarto, razao, regua_noite, tipo_da_noite, vendidas_inv)

ARQ_DECISOES = RAIZ / "dados/faixas_decisoes.json"
ARQ_MODELO = RAIZ / "dados/faixas_modelo.json"
ARQ_RESULTADOS = RAIZ / "dados/faixas_resultados.json"
ARQ_SAZ = RAIZ / "dados/sazonalidade.json"
REF = {"Recanto": "Q7", "Villa": "VK7"}   # suíte de referência da faixa
NOMES_F = {n: nome for n, nome, _ in FAIXAS}


# ---------- modelo
def cdf_antecedencia(mod: dict, k: float) -> float:
    """Fração das reservas finais de uma noite feitas com até k dias de antecedência (= ainda por vir a k dias)."""
    pts = mod["antecedencia_cdf"]
    if k <= pts[0][0]:
        return pts[0][1]
    for (k0, v0), (k1, v1) in zip(pts, pts[1:]):
        if k <= k1:
            return v0 + (v1 - v0) * (k - k0) / (k1 - k0)
    return pts[-1][1]


def prever_noite(mod: dict, nivel: str, pous: str, k: int, unidades: int, vendidas: int, precos: dict[int, float],
                 p_hoje: float | None = None, ref: int = 2) -> dict:
    """Para uma noite: ocupação final e receita a vender previstas em cada faixa (preços públicos por faixa já com piso
    e máximo). Demanda final no preço da F2 = ocupação de referência do nível (mercado × índice do hotel); o nosso ritmo
    (vendido ÷ fração já vendida na antecedência) mede a demanda no PREÇO DE HOJE, que é levada à F2 por
    (hoje/F2)^elasticidade e puxa a referência pelo `peso_ritmo`. Ainda por vir = demanda × fração por vir; em outra
    faixa escala por (preço/F2)^-elasticidade; a venda nunca passa das unidades livres."""
    m = float(mod["ocupacao_mercado_por_nivel"].get(nivel, 0.3))
    o_ref = min(float(mod["ocupacao_max"]), m * float(mod["indice_hotel"][pous]))
    pico = nivel in ("alta", "muito alta")
    s = cdf_antecedencia(mod, k / (float(mod["antecedencia_pico_fator"]) if pico else 1.0))
    ja = vendidas / unidades if unidades else 0.0
    e = float(mod["elasticidade_por_nivel"].get(nivel, 1.2))
    p_ref = precos[ref]
    esperado_ja = o_ref * (1 - s)
    if 1 - s > 0.05:
        implicito = ja / (1 - s)
        if p_hoje and p_ref > 0:
            implicito *= min(2.0, max(0.5, (p_hoje / p_ref) ** e))  # vendido no preço de hoje, levado à F2
        implicito = min(implicito, 1.5 * float(mod["ocupacao_max"]))
        # confiança no nosso ritmo cresce com a parte da janela de vendas que já passou (a 30 dias ~7% das reservas
        # já entraram: 18% vendido ali é sinal, não prova)
        conf = min(1.0, (1 - s) / 0.5)
        o_ef = o_ref + float(mod["peso_ritmo"]) * conf * (implicito - o_ref)
    else:
        o_ef = max(o_ref, ja)
    o_ef = max(o_ef, ja)
    por_vir = o_ef * unidades * s
    out = {}
    for f, p in precos.items():
        dem = por_vir * (p / p_ref) ** (-e) if p_ref > 0 else por_vir
        vende = max(0.0, min(unidades - vendidas, dem))
        out[f] = {"vende": vende, "receita": vende * p, "ocupacao": (vendidas + vende) / unidades if unidades else 0.0}
    return {"faixas": out, "o_ref": o_ref, "o_ef": o_ef, "por_vir": s, "esperado_ja": esperado_ja, "ja": ja}


# ---------- períodos
def periodos(saz: dict, hoje: date, dias: int) -> list[dict]:
    fim_j = hoje + timedelta(days=dias)
    out = []
    for p in saz["periodos"]:
        ini, fim = _d(p["inicio"]), _d(p["fim"])
        if fim < hoje or ini > fim_j:
            continue
        wd = DIAS.get(p.get("dias") or "todos", DIAS["todos"])
        noites = [ini + timedelta(days=i) for i in range((fim - ini).days + 1)]
        noites = [x for x in noites if hoje <= x <= fim_j and x.weekday() in wd]
        if noites:
            out.append({"id": f"{p['inicio']}_{p['fim']}_{p.get('dias') or 'todos'}", "nome": p["nome"],
                        "inicio": p["inicio"], "fim": p["fim"], "dias": p.get("dias") or "todos", "nivel": p["nivel"],
                        "faixa_alvo_saz": p.get("faixa_alvo"), "noites": noites})
    return out


def _unidades(cfg: dict, pous: str) -> dict:
    return {q: int(cfg["por_curto"][q]["unidades"]) for q in QUARTOS[pous]}


def faixa_em_vigor(decisoes: list[dict], cfg: dict, pous: str, noites: list[date]) -> tuple[int | None, str | None, int]:
    """(faixa mais comum nas noites, id da decisão, nº de noites com decisão)."""
    achados = []
    for d in noites:
        dec, r = decisao_da_noite(decisoes, d, tipo_da_noite(d, cfg), pous)
        if dec:
            achados.append((int(r["faixa"]), dec["id_base"]))
    if not achados:
        return None, None, 0
    f = st.mode([a[0] for a in achados])
    ids = [a[1] for a in achados if a[0] == f]
    return f, st.mode(ids), len(achados)


def linha(fx: dict, cfg: dict, mod: dict, decisoes: list[dict], inv: dict, precos: dict, mercado: dict, pous: str,
          per: dict, hoje: date) -> dict:
    from pace import pisos_enviados, status_da_noite, tipo_noite
    un = _unidades(cfg, pous)
    U = sum(un.values())
    ref = REF[pous]
    mx = float(cfg["por_curto"][ref]["max"])
    tot = {f: {"receita": 0.0, "vende": 0.0} for f in range(1, 6)}
    vend_tot, cap, faixa_vals, pisos_pub, hoje_pub, status_n, merc = 0, 0, {f: [] for f in range(1, 6)}, [], [], [], []
    for d in per["noites"]:
        n = d.isoformat()
        k = (d - hoje).days
        t = tipo_da_noite(d, cfg)
        reg, _ = regua_noite(fx, d, t)
        rz = razao(pous, k)
        fac = sum(un[q] * fator_quarto(cfg, q, d) for q in un) / U
        piso_pub = pisos_enviados(cfg, ref, d)[0] * rz
        teto_pub = mx * rz
        pf = {}
        for f in range(1, 6):
            v = float(reg[str(PCT[f])])
            faixa_vals[f].append(v)
            pf[f] = min(teto_pub, max(v, piso_pub)) * fac
        vend = sum(vendidas_inv(inv, q, n, un[q]) for q in un)
        vend_tot += vend
        cap += U
        ph = float(precos[ref][n]) * rz * fac if precos.get(ref, {}).get(n) else None
        prev = prever_noite(mod, per["nivel"], pous, k, U, vend, pf, ph)
        for f in range(1, 6):
            tot[f]["receita"] += prev["faixas"][f]["receita"]
            tot[f]["vende"] += prev["faixas"][f]["vende"]
        pisos_pub.append(piso_pub)
        if precos.get(ref, {}).get(n):
            hoje_pub.append(float(precos[ref][n]) * rz)
        stt = status_da_noite(cfg, tipo_noite(cfg, d), k, vend, U)
        status_n.append((stt[0], stt[1], stt[2]))
        if mercado.get(pous, {}).get(n):
            merc.append(mercado[pous][n])
    med = {f: round(st.median(v)) for f, v in faixa_vals.items()}
    piso_m = round(st.median(pisos_pub))
    abaixo = [f for f in range(1, 6) if med[f] < piso_m]
    res = {f: {"receita": round(tot[f]["receita"]), "ocupacao": round(100 * (vend_tot + tot[f]["vende"]) / cap)}
           for f in range(1, 6)}
    mx_r = max(r["receita"] for r in res.values())
    empate = [f for f in range(1, 6) if res[f]["receita"] >= mx_r * (1 - float(mod["empate"]))]
    # empate (receita a menos de `empate` da maior): a faixa do meio das empatadas (a que menos perde se a elasticidade
    # real for maior ou menor que a premissa); entre duas, a mais alta (mesma receita com menos diárias)
    rec = st.median_high(sorted(empate))
    vig, vig_id, vig_n = faixa_em_vigor(decisoes, cfg, pous, [d for d in per["noites"] if (d - hoje).days >= 2])
    cont = {s: sum(1 for x in status_n if x[0] == s) for s in ("frente", "ritmo", "atras", "muito_atras")}
    vend_pct = round(100 * vend_tot / cap) if cap else 0
    curva = round(st.mean(x[2] for x in status_n)) if status_n else 0
    hoje_m = round(st.median(hoje_pub)) if hoje_pub else None
    ritmo = ("à frente" if vend_pct >= curva + 15 else "atrás" if vend_pct <= curva - 15 else "no ritmo")
    k0 = (per["noites"][0] - hoje).days
    lim = [f for f in range(1, 6) if med[f] > mx * razao(pous, 60) + 1]
    motivo = (f"demanda {per['nivel']}; vendido {vend_pct}% contra {curva}% esperado ({ritmo}); "
              f"elasticidade {mod['elasticidade_por_nivel'].get(per['nivel'])}")
    if abaixo:
        motivo += f"; F{', F'.join(map(str, abaixo))} abaixo do seu piso ({brl(piso_m)} público): nelas vale o piso"
    if lim:
        motivo += f"; F{', F'.join(map(str, lim))} acima do máximo do anúncio (R$ 5.000): ficam no máximo"
    return {"pousada": pous, "periodo": per["id"], "nome": per["nome"], "nivel": per["nivel"], "dias": per["dias"],
            "de": per["noites"][0].isoformat(), "ate": per["noites"][-1].isoformat(), "noites": len(per["noites"]),
            "antecedencia": k0, "unidades": U, "vendidas": vend_tot, "capacidade": cap, "vendido_pct": vend_pct,
            "curva_pct": curva, "ritmo": ritmo, "status_noites": cont, "faixas": med, "piso_publico": piso_m,
            "abaixo_piso": abaixo, "acima_maximo": lim, "hoje_publico": hoje_m,
            "hoje_posicao": fd.posicao(hoje_m, {f"F{f}": med[f] for f in med}) if hoje_m else None,
            "mercado_pct": round(st.mean(merc)) if merc else None, "previsto": res, "recomendada": rec,
            "em_vigor": vig, "decisao": vig_id, "noites_com_decisao": vig_n, "motivo": motivo,
            "ganho_vs_vigor": (res[rec]["receita"] - res[vig]["receita"]) if vig else None}


def ler_mercado(pares: list[str]) -> dict:
    """{pousada: {data: ocupação % do mercado}} de saídas de get_neighbourhood_data (`occupancy.daily`, aninhado)."""
    out = {}
    for x in pares or []:
        k, v = x.split("=", 1)
        bruto = json.load(open(v))
        achado = {}

        def anda(o):
            if isinstance(o, dict):
                if "daily" in o and isinstance(o["daily"], (list, dict)):
                    dl = o["daily"]
                    itens = dl if isinstance(dl, list) else [dict(date=a, **(b if isinstance(b, dict) else {"occ": b}))
                                                             for a, b in dl.items()]
                    for it in itens:
                        if not isinstance(it, dict):
                            continue
                        dt_ = it.get("date") or it.get("Date") or it.get("day")
                        val = next((it[c] for c in ("occupancy", "occ", "Occupancy", "value", "booked")
                                    if isinstance(it.get(c), (int, float))), None)
                        if dt_ and val is not None:
                            achado[str(dt_)[:10]] = round(val * 100 if val <= 1 else val, 1)
                for w in o.values():
                    anda(w)
            elif isinstance(o, list):
                for w in o:
                    anda(w)
        anda(bruto)
        out[k] = achado
    return out


def tabela(fx: dict, cfg: dict, mod: dict, decisoes: list[dict], saz: dict, inv: dict, precos: dict, mercado: dict,
           hoje: date, dias: int) -> dict:
    linhas = []
    for per in periodos(saz, hoje, dias):
        for pous in ("Recanto", "Villa"):
            linhas.append(linha(fx, cfg, mod, decisoes, inv, precos, mercado, pous, per, hoje))
    return {"hoje": hoje.isoformat(), "gerado_em": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M+00:00"),
            "modelo": {k: mod[k] for k in ("versao", "elasticidade_por_nivel", "indice_hotel", "peso_ritmo")},
            "medido_em": fx.get("medido_em"), "linhas": linhas}


# ---------- perguntas ao dono
def perguntas(tab: dict, mod: dict, maximo: int = 8) -> list[dict]:
    """Decisões a pedir hoje: períodos do horizonte (D2 até `pergunta_horizonte_dias`) sem decisão, ou com a faixa em
    vigor diferente da recomendada e ganho previsto acima de `pergunta_min_reais`. Mais perto primeiro."""
    out = []
    for ln in tab["linhas"]:
        if ln["antecedencia"] > int(mod["pergunta_horizonte_dias"]) or ln["ate"] < (_d(tab["hoje"]) + timedelta(days=2)).isoformat():
            continue
        if ln["em_vigor"] is None or (ln["recomendada"] != ln["em_vigor"]
                                      and abs(ln["ganho_vs_vigor"] or 0) >= float(mod["pergunta_min_reais"])):
            out.append(ln)
    out.sort(key=lambda x: (x["antecedencia"], -(abs(x["ganho_vs_vigor"] or 0))))
    return out[:maximo]


def texto_pergunta(i: int, ln: dict) -> str:
    rec, vig = ln["recomendada"], ln["em_vigor"]
    pr = ln["previsto"]
    base = (f"{i}. {ln['pousada']} · {ln['nome'].split(' – ')[0].split(':')[0]} ({dm(ln['de'])} a {dm(ln['ate'])}, "
            f"{ln['noites']} noites): vendido {ln['vendido_pct']}% (esperado {ln['curva_pct']}%). ")
    if vig is None:
        base += "Sem faixa decidida. "
    else:
        base += f"Em vigor F{vig} ({brl(pr[vig]['receita'])} a vender, ocupação {pr[vig]['ocupacao']}%). "
    base += (f"Recomendo F{rec} {NOMES_F[rec]} ({brl(ln['faixas'][rec])} público; {brl(pr[rec]['receita'])} a vender, "
             f"ocupação {pr[rec]['ocupacao']}%).")
    return base


# ---------- decisões
def decidir(arq: Path, itens: list[str], texto: str, hoje: date, criada_em: str, saz: dict) -> dict:
    d = json.load(open(arq))
    pers = {f"{p['inicio']}_{p['fim']}_{p.get('dias') or 'todos'}": p for p in saz["periodos"]}
    hhmm = criada_em[11:13] + criada_em[14:16]
    ids = {x["id_base"] for x in d["decisoes"]}
    base = f"dono-{hoje.isoformat()}-faixas{hhmm}"
    sufixo = ""
    while base + sufixo in ids:
        sufixo = chr(ord(sufixo or "a") + (1 if sufixo else 0))
    regras = []
    for it in itens:
        pous, pid, f = it.split("|")
        p = pers[pid]
        f = int(f)
        pss = ["Recanto", "Villa"] if pous.lower() in ("ambas", "os dois", "todos") else [pous]
        regras.append({"pousadas": pss, "de": max(p["inicio"], hoje.isoformat()), "ate": p["fim"],
                       "dias": p.get("dias") or "todos", "faixa": f, "descida_gradual": f == 5,
                       "periodo": p["nome"], "leitura": f"{', '.join(pss)}: F{f} em {p['nome']}"})
    dec = {"id_base": base + sufixo, "data": hoje.isoformat(), "criada_em": criada_em, "texto": texto,
           "contexto": "Resposta do dono à pergunta diária de faixas (estrategia_faixas.py, regras.md 4f).",
           "regras": regras}
    d["decisoes"].append(dec)
    Path(arq).write_text(json.dumps(d, ensure_ascii=False, indent=1) + "\n")
    return dec


def registrar(tab: dict, arq: Path = ARQ_RESULTADOS) -> int:
    """Foto do dia de cada hotel e período (para calibrar o modelo com o que vendeu de fato)."""
    d = json.load(open(arq)) if Path(arq).exists() else {"_doc": "Foto diária da tabela de estratégia (vendido, faixa "
                                                         "em vigor, previsão) por hotel e período; `calibrar` compara a "
                                                         "previsão com o vendido no fim do período.", "fotos": []}
    d["fotos"] = [x for x in d["fotos"] if x["dia"] != tab["hoje"]]
    for ln in tab["linhas"]:
        f = ln["em_vigor"] or ln["recomendada"]
        d["fotos"].append({"dia": tab["hoje"], "pousada": ln["pousada"], "periodo": ln["periodo"], "nivel": ln["nivel"],
                           "antecedencia": ln["antecedencia"], "vendidas": ln["vendidas"], "capacidade": ln["capacidade"],
                           "faixa": f, "em_vigor": ln["em_vigor"], "recomendada": ln["recomendada"],
                           "previsto_ocupacao": ln["previsto"][f]["ocupacao"], "preco_publico": ln["faixas"][f],
                           "hoje_publico": ln["hoje_publico"]})
    Path(arq).write_text(json.dumps(d, ensure_ascii=False, indent=1) + "\n")
    return len(tab["linhas"])


def calibrar(hoje: date, arq: Path = ARQ_RESULTADOS) -> list[dict]:
    """Períodos encerrados: ocupação prevista (na foto mais perto de 14 dias antes) contra a vendida (última foto, D0-D1).
    Razão média por nível de demanda: abaixo de 0,85 o modelo previu demais (elasticidade sobe 0,1); acima de 1,15,
    de menos (desce 0,1). Só sugere; a gravação em faixas_modelo.json vai com registro no relatório."""
    if not Path(arq).exists():
        return []
    fotos = json.load(open(arq))["fotos"]
    grupos = {}
    for x in fotos:
        grupos.setdefault((x["pousada"], x["periodo"]), []).append(x)
    por_nivel = {}
    for (pous, pid), xs in grupos.items():
        fim = pid.split("_")[1]
        if fim >= hoje.isoformat():
            continue
        xs.sort(key=lambda x: x["dia"])
        antes = min(xs, key=lambda x: abs(x["antecedencia"] - 14))
        ult = xs[-1]
        if antes is ult or not ult["capacidade"]:
            continue
        real = 100 * ult["vendidas"] / ult["capacidade"]
        if antes["previsto_ocupacao"]:
            por_nivel.setdefault(antes["nivel"], []).append(real / antes["previsto_ocupacao"])
    out = []
    for nivel, rs in por_nivel.items():
        r = st.mean(rs)
        out.append({"nivel": nivel, "periodos": len(rs), "real_sobre_previsto": round(r, 2),
                    "sugestao": ("elasticidade +0,1" if r < 0.85 else "elasticidade -0,1" if r > 1.15 else "manter")})
    return out


# ---------- markdown
def markdown(tab: dict, pergs: list[dict]) -> str:
    out = [f"# Estratégia de faixas por hotel e período — {dm(tab['hoje'])}", "",
           "Recomendação = faixa de maior receita prevista a vender (empate a menos de 3%: a do meio das empatadas). Valores no preço público da "
           "Booking, suíte de referência (Moinhos / King Spa 7); Afrodite e Balcony pelo fator delas na receita.", ""]
    if pergs:
        out += ["## Decisões pedidas hoje", ""] + [texto_pergunta(i + 1, ln) for i, ln in enumerate(pergs)] + [""]
    for pous in ("Recanto", "Villa"):
        out += [f"## {pous}", "", "| Período | Noites | Demanda | Vendido / esperado | Hoje | F1 | F2 | F3 | F4 | F5 | Em vigor | "
                "Recomendada | Receita a vender (vigor → rec.) |", "|---|---|---|---|---|---|---|---|---|---|---|---|---|"]
        for ln in tab["linhas"]:
            if ln["pousada"] != pous:
                continue
            fx = " | ".join(("~~" + brl(ln["faixas"][f]) + "~~") if f in ln["abaixo_piso"] else brl(ln["faixas"][f])
                            for f in range(1, 6))
            vig = f"F{ln['em_vigor']}" if ln["em_vigor"] else "—"
            rv = (f"{brl(ln['previsto'][ln['em_vigor']]['receita'])} → " if ln["em_vigor"] else "") + \
                brl(ln["previsto"][ln["recomendada"]]["receita"])
            out.append(f"| {ln['nome'].split(' – ')[0][:60]} | {dm(ln['de'])}-{dm(ln['ate'])} ({ln['noites']}) | "
                       f"{ln['nivel']} | {ln['vendido_pct']}% / {ln['curva_pct']}% | "
                       f"{brl(ln['hoje_publico']) if ln['hoje_publico'] else '—'} | {fx} | {vig} | F{ln['recomendada']} | {rv} |")
        out.append("")
    return "\n".join(out)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    t = sub.add_parser("tabela")
    t.add_argument("--inv", required=True)
    t.add_argument("--precos", nargs="*", default=[])
    t.add_argument("--mercado", nargs="*", default=[])
    t.add_argument("--hoje", default=date.today().isoformat())
    t.add_argument("--dias", type=int, default=365)
    t.add_argument("--saida", required=True)
    t.add_argument("--md")
    pg = sub.add_parser("perguntas")
    pg.add_argument("--tabela", required=True)
    pg.add_argument("--max", type=int, default=8)
    dc = sub.add_parser("decidir")
    dc.add_argument("--hoje", required=True)
    dc.add_argument("--criada-em", required=True)
    dc.add_argument("--texto", required=True)
    dc.add_argument("--item", action="append", required=True, help="Pousada|id do período|faixa (Pousada: Recanto, Villa ou ambas)")
    rg = sub.add_parser("registrar")
    rg.add_argument("--tabela", required=True)
    cb = sub.add_parser("calibrar")
    cb.add_argument("--hoje", default=date.today().isoformat())
    a = ap.parse_args(argv)
    mod = json.load(open(ARQ_MODELO))
    if a.cmd == "tabela":
        fx = json.load(open(RAIZ / "dados/faixas_dono.json"))
        cfg = fd.carregar_cfg()
        tab = tabela(fx, cfg, mod, decisoes_de(json.load(open(ARQ_DECISOES))), json.load(open(ARQ_SAZ)),
                     json.load(open(a.inv)), fd.ler_precos(a.precos), ler_mercado(a.mercado), _d(a.hoje), a.dias)
        Path(a.saida).write_text(json.dumps(tab, ensure_ascii=False, indent=1))
        pergs = perguntas(tab, mod)
        md = markdown(tab, pergs)
        if a.md:
            Path(a.md).write_text(md)
        print(f"{len(tab['linhas'])} linhas (hotel × período); {len(pergs)} decisões a pedir hoje")
        return 0
    if a.cmd == "perguntas":
        tab = json.load(open(a.tabela))
        for i, ln in enumerate(perguntas(tab, mod, a.max)):
            print(texto_pergunta(i + 1, ln))
        return 0
    if a.cmd == "decidir":
        dec = decidir(ARQ_DECISOES, a.item, a.texto, _d(a.hoje), a.criada_em, json.load(open(ARQ_SAZ)))
        print(json.dumps(dec, ensure_ascii=False, indent=1))
        return 0
    if a.cmd == "registrar":
        print(f"{registrar(json.load(open(a.tabela)))} fotos gravadas em {ARQ_RESULTADOS}")
        return 0
    for x in calibrar(_d(a.hoje)):
        print(x)
    return 0


if __name__ == "__main__":
    sys.exit(main())

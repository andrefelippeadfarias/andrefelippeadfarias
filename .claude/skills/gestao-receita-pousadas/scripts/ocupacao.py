"""Ocupação por janela e mapa das próximas noites, a partir dos preços do PriceLabs.

Uso:
  python3 ocupacao.py SAIDA_WORKFLOW.json [--hoje AAAA-MM-DD] [--noites 14] [--json]
  python3 ocupacao.py --quarto Q7=resposta_q7.json --quarto Double=resposta_double.json ...

SAIDA_WORKFLOW.json é o arquivo de saída do workflow coleta-pricelabs (tem result.rooms).
Os arquivos --quarto são respostas brutas do get_listing_prices (com "data": [{id, data: [...]}]).
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date

from comum import (DIAS_SEMANA, carregar_quartos, data, dias, ler_json, periodo_feriado,
                   reais, resultado_workflow)
from pacing.metricas import ler_calendario, ocupacao

JANELAS = [7, 15, 30, 45, 60]


def entradas_do_workflow(res: dict) -> dict:
    out = {}
    for curto, sala in (res.get("rooms") or {}).items():
        out[curto] = {"id": sala.get("listing_id", curto), "data": sala.get("days") or [],
                      "last_refreshed_at": sala.get("last_refreshed_at")}
    return out


def entradas_brutas(pares: list[str]) -> dict:
    out = {}
    for par in pares:
        curto, caminho = par.split("=", 1)
        bruto = ler_json(caminho)
        itens = bruto.get("data") or []
        if isinstance(itens, dict):
            itens = itens.get("data") or []
        out[curto] = itens[0] if itens else {"id": curto, "data": []}
    return out


def calcular(entradas: dict, cfg: dict, hoje: date, n_noites: int) -> dict:
    listagens, avisos = {}, []
    avisos += [f"quarto desconhecido: {k}" for k in entradas if k not in cfg["por_curto"]]
    for q in cfg["quartos"]:
        curto = q["curto"]
        if curto not in entradas:
            avisos.append(f"{curto}: sem dados nesta coleta")
            continue
        entrada = entradas[curto]
        lst = ler_calendario(entrada, q["unidades"])
        if lst.erro:
            avisos.append(f"{curto}: {lst.erro}")
        listagens[curto] = (q, lst, {str(d.get("date"))[:10]: d for d in entrada.get("data") or []})

    def janela(chaves, w):
        v = t = 0
        for k in chaves:
            if k in listagens:
                a, b, _ = ocupacao(listagens[k][1], hoje, 0, w - 1)
                v, t = v + a, t + b
        return {"vendidas": v, "total": t, "pct": round(100 * v / t, 1) if t else None}

    grupos = {k: [k] for k in listagens}
    todos = list(listagens)
    grupos["Hotel"] = todos
    grupos["Suítes com banheira"] = [k for k in todos if listagens[k][0].get("banheira")]
    grupos["Recanto"] = [k for k in todos if listagens[k][0]["pousada"] == "Recanto"]
    grupos["Villa"] = [k for k in todos if listagens[k][0]["pousada"] == "Villa"]
    janelas = {nome: {str(w): janela(ch, w) for w in JANELAS} for nome, ch in grupos.items()}

    noites = []
    for d in dias(hoje, n_noites):
        linha = {"data": d.isoformat(), "dia": DIAS_SEMANA[d.weekday()],
                 "feriado": (periodo_feriado(cfg, d) or {}).get("nome"), "quartos": {}}
        livres_total = 0
        for curto, (q, lst, brutos) in listagens.items():
            dia = lst.dias.get(d)
            b = brutos.get(d.isoformat(), {})
            if dia is None:
                linha["quartos"][curto] = {"livres": None}
                continue
            livres = 0 if dia.bloqueado else dia.total - dia.vendidas
            livres_total += livres
            linha["quartos"][curto] = {"livres": livres, "vendidas": dia.vendidas, "total": dia.total,
                                       "preco": dia.preco, "enviado": dia.enviado,
                                       "min_stay": b.get("min_stay"), "demanda": b.get("demand_desc")}
        linha["livres_total"] = livres_total
        noites.append(linha)
    return {"hoje": hoje.isoformat(), "metas": cfg["metas"], "janelas": janelas, "noites": noites,
            "avisos": avisos}


def imprimir(r: dict) -> None:
    metas = r["metas"]
    print(f"Ocupação a partir de {r['hoje']} (meta: " +
          ", ".join(f"{w}d {metas[str(w)]}%" for w in JANELAS) + ")\n")
    print("| Quarto | " + " | ".join(f"{w}d" for w in JANELAS) + " |")
    print("|---|" + "---|" * len(JANELAS))
    for nome, js in r["janelas"].items():
        cel = []
        for w in JANELAS:
            j = js[str(w)]
            if j["pct"] is None:
                cel.append("—")
            else:
                marca = "✅" if j["pct"] >= metas[str(w)] else "⚠️"
                cel.append(f"{j['pct']:.0f}% {marca} ({j['vendidas']}/{j['total']})")
        rotulo = f"**{nome}**" if nome in ("Hotel", "Suítes com banheira", "Recanto", "Villa") else nome
        print(f"| {rotulo} | " + " | ".join(cel) + " |")
    if r["noites"]:
        quartos = list(r["noites"][0]["quartos"])
        print("\nNoites (livres · calculado / enviado · estadia mínima)\n")
        print("| Data | " + " | ".join(quartos) + " | Livres |")
        print("|---|" + "---|" * (len(quartos) + 1))
        for n in r["noites"]:
            cels = []
            for k in quartos:
                c = n["quartos"][k]
                if c.get("livres") is None:
                    cels.append("?")
                elif c["livres"] == 0:
                    cels.append("esgotado")
                else:
                    env = c.get("enviado")
                    env_txt = reais(env) if env not in (None, -1) else "—"
                    cels.append(f"{c['livres']} · {reais(c['preco'])}/{env_txt} · {c.get('min_stay')}n")
            fer = f" ({n['feriado']})" if n["feriado"] else ""
            print(f"| {n['data'][8:10]}/{n['data'][5:7]} {n['dia']}{fer} | " + " | ".join(cels) +
                  f" | {n['livres_total']} |")
    for a in r["avisos"]:
        print(f"\nAVISO: {a}")


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("saida", nargs="?", help="arquivo de saída do workflow coleta-pricelabs")
    ap.add_argument("--quarto", action="append", default=[], help="CURTO=arquivo bruto do get_listing_prices")
    ap.add_argument("--hoje", help="AAAA-MM-DD (padrão: primeira data dos dados)")
    ap.add_argument("--noites", type=int, default=14)
    ap.add_argument("--json", action="store_true")
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    if a.saida:
        res = resultado_workflow(ler_json(a.saida))
        entradas = entradas_do_workflow(res)
        hoje_txt = a.hoje or res.get("hoje")
    else:
        entradas = entradas_brutas(a.quarto)
        hoje_txt = a.hoje
    if not entradas:
        print("Nenhum dado de quarto encontrado.", file=sys.stderr)
        return 2
    if not hoje_txt:
        primeiras = [str(e["data"][0]["date"]) for e in entradas.values() if e.get("data")]
        hoje_txt = min(primeiras)
    r = calcular(entradas, cfg, data(hoje_txt), a.noites)
    if a.json:
        print(json.dumps(r, ensure_ascii=False, indent=1))
    else:
        imprimir(r)
    return 0


if __name__ == "__main__":
    sys.exit(main())

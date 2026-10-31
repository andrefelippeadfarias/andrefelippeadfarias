"""Âncora de mercado por noite (30/10/2026): a única régua de preço do motor de pace.

Preço PÚBLICO por noite (2 adultos, 1 quarto, sem Genius) dos hotéis COM HIDRO que a Booking devolve para a mesma estadia,
em percentis (p25, p50, p75, p90, max). Entra pelas buscas da coleta (`concorrentes.searches`) ou pelo arquivo da
análise de 60 dias (`buscas`), que trazem por estadia: mediana e máximo com hidro, mediana geral, mínimo geral e até 8
comparáveis "do nosso nível" com o preço total. A busca da Booking devolve só ~10 hotéis por chamada, então os percentis
são aproximados (p50 e max são medidos; p75 e p90 vêm dos comparáveis, que são a metade de cima do conjunto; p25 fica a
meio caminho entre o mínimo geral e a mediana). `n` e `busca_em` ficam no arquivo para o motor reduzir o passo quando a
amostra é pequena (n < pace.min_n) ou velha (mais de pace.idade_max_dias).

Conversão para enviado: público ÷ parametros.fator_publico da pousada (Recanto 0,63; Villa 0,65 em 29-30/10). Os fatores
do Genius 3 (0,41/0,425; 0,53/0,575) servem só para os pisos do dono; fator_real (0,42/0,39) só para o piso de feriado.

Uso:
  python3 ancora_mercado.py construir --hoje AAAA-MM-DD --buscas <arquivo> [<arquivo> ...] [--saida dados/ancora_mercado.json] [--mesclar]
  python3 ancora_mercado.py ver --data AAAA-MM-DD [--ancora dados/ancora_mercado.json]
"""

from __future__ import annotations

import argparse
import json
import re
import statistics
import sys
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from comum import PASTA_SKILL, carregar_quartos, data, ler_json, periodo_feriado, resultado_workflow  # noqa: E402

ARQ_ANCORA = PASTA_SKILL / "dados" / "ancora_mercado.json"
HIDRO = re.compile(r"jacuzzi|hidro|whirlpool|spa bath|ofur|banheira", re.I)
SEM_HIDRO = re.compile(r"sem hidro|sem jacuzzi|n[aã]o listad|sem banheira|n[aã]o tem hidro", re.I)
PERCENTIS = ("p25", "p50", "p75", "p90", "max")


def quantil(valores: list[float], p: float) -> float:
    v = sorted(valores)
    if not v:
        return float("nan")
    k = (len(v) - 1) * p
    i, f = int(k), k - int(k)
    return v[i] if i + 1 >= len(v) else v[i] + (v[i + 1] - v[i]) * f


def tipo_noite(cfg: dict, d: date) -> str:
    if periodo_feriado(cfg, d):
        return "feriado"
    return "fds" if d.weekday() in (4, 5) else "semana"


def buscas_do_arquivo(dados: dict) -> list[dict]:
    """Lista de buscas em qualquer dos formatos que usamos (coleta, análise de 60 dias, varredura simplificada)."""
    r = resultado_workflow(dados) if isinstance(dados, dict) else {}
    r = r or dados
    if isinstance(r, dict):
        if isinstance(r.get("buscas"), list):
            return r["buscas"]
        c = r.get("concorrentes") or {}
        if isinstance(c, dict) and isinstance(c.get("searches"), list):
            return c["searches"]
        if isinstance(r.get("searches"), list):
            return r["searches"]
    return []


def percentis_da_busca(s: dict) -> dict | None:
    """p25/p50/p75/p90/max públicos POR NOITE da estadia, ou None sem dado com hidro."""
    ci, co = data(s["checkin"]), data(s["checkout"])
    n_noites = max(1, (co - ci).days)
    comps = []
    for c in s.get("comparables") or []:
        txt = f"{c.get('hydro_or_spa', '')} {c.get('note', '')}"
        if c.get("price_total") and HIDRO.search(txt) and not SEM_HIDRO.search(txt):
            comps.append(float(c["price_total"]) / n_noites)
    hm = s.get("hidro_median")
    hx = s.get("hidro_max") or s.get("price_total_max")
    gmin = s.get("price_total_min")
    medido = bool(hm)
    # sem mediana com hidro medida, a mediana geral da busca é a melhor aproximação (nas buscas de 30/10 as duas ficaram
    # a menos de 5%); os comparáveis são a metade de cima e NUNCA servem de p50
    p50 = float(hm) / n_noites if hm else (float(s["price_total_median"]) / n_noites if s.get("price_total_median") else None)
    if p50 is None:
        return None
    mx = float(hx) / n_noites if hx else (max(comps) if comps else p50)
    mx = max(mx, p50)
    if len(comps) >= 4:
        # os comparáveis são a metade de cima do conjunto com hidro: a mediana deles aproxima o p75 e o quantil 0,8 o p90
        p75 = min(max(statistics.median(comps), p50), mx)
        p90 = min(max(quantil(comps, 0.8), p75), mx)
    else:
        p75 = p50 + 0.5 * (mx - p50)
        p90 = p50 + 0.8 * (mx - p50)
    piso = float(gmin) / n_noites if gmin else p50 * 0.6
    p25 = min(p50, piso + 0.5 * (p50 - piso))
    n = int(s.get("properties_found") or 0) or len(comps)
    return {"p25": round(p25), "p50": round(p50), "p75": round(p75), "p90": round(p90), "max": round(mx),
            "n": n, "n_comparaveis": len(comps), "p50_medido": medido}


def substitui(novo: dict, velho: dict, hoje: date, idade_max: int) -> bool:
    """A busca nova entra no lugar da que já está na noite? (31/10/2026)

    Mediana COM HIDRO medida vale mais que a mediana geral (que traz quartos sem hidro e ficou até 34% abaixo em
    31/10: dom-qui 257 x 388). Então uma busca sem hidro medida não troca uma com hidro medida enquanto esta tiver até
    `idade_max` dias (pace.idade_max_dias); entre buscas do mesmo tipo vence a mais nova, e no empate a de maior n."""
    def fresca(x: dict) -> bool:
        try:
            return (hoje - data(str(x.get("busca_em", ""))[:10])).days <= idade_max
        except (ValueError, TypeError):
            return False

    med_n, med_v = bool(novo.get("p50_medido")), bool(velho.get("p50_medido"))
    if med_v and not med_n and fresca(velho):
        return False
    if med_n and not med_v and fresca(novo):
        return True
    return (novo.get("busca_em", ""), novo.get("n") or 0) >= (velho.get("busca_em", ""), velho.get("n") or 0)


def construir(cfg: dict, arquivos: list[str], hoje: date, anterior: dict | None = None) -> dict:
    noites = dict((anterior or {}).get("noites") or {})
    idade_max = int(((cfg.get("parametros") or {}).get("pace") or {}).get("idade_max_dias", 10))
    for arq in arquivos:
        for s in buscas_do_arquivo(ler_json(arq)):
            try:
                pct = percentis_da_busca(s)
            except (KeyError, ValueError, TypeError):
                pct = None
            if not pct:
                continue
            ci, co = data(s["checkin"]), data(s["checkout"])
            busca_em = str(s.get("busca_em") or s.get("captado") or hoje.isoformat())[:10]
            d = ci
            while d < co:
                k = d.isoformat()
                novo = dict(pct, tipo=tipo_noite(cfg, d), fonte=str(s.get("label") or f"{ci}→{co}"), busca_em=busca_em)
                velho = noites.get(k)
                if velho is None or substitui(novo, velho, hoje, idade_max):
                    noites[k] = novo
                d += timedelta(days=1)
    # referência por tipo de noite e mês (mediana dos percentis das noites com busca própria), para noite sem busca
    por_tipo: dict = {}
    grupos: dict = {}
    for k, v in noites.items():
        grupos.setdefault((v["tipo"], k[:7]), []).append(v)
    for (tipo, mes), lista in grupos.items():
        por_tipo.setdefault(tipo, {})[mes] = {p: round(statistics.median(x[p] for x in lista)) for p in PERCENTIS} | {
            "n_noites": len(lista), "busca_em": max(x["busca_em"] for x in lista)}
    return {"_doc": ("Âncora de mercado do motor de pace: percentis do preço público por noite dos hotéis com hidro na "
                     "Booking (2 adultos). p50 e max medidos; p75/p90/p25 aproximados (ver ancora_mercado.py). "
                     "Noite sem busca própria usa `por_tipo` do mesmo tipo e mês."),
            "atualizado_em": hoje.isoformat(), "fontes": arquivos, "noites": dict(sorted(noites.items())), "por_tipo": por_tipo}


def ancora_da_noite(ancora: dict, cfg: dict, d: date) -> tuple[dict | None, str]:
    """(percentis, origem) da noite: busca própria, senão o tipo de noite no mês, senão o tipo no mês mais próximo."""
    k = d.isoformat()
    if k in (ancora.get("noites") or {}):
        return ancora["noites"][k], "busca própria"
    tipo = tipo_noite(cfg, d)
    por_mes = (ancora.get("por_tipo") or {}).get(tipo) or {}
    if not por_mes:
        return None, "sem dado"
    mes = k[:7]
    if mes in por_mes:
        return por_mes[mes], f"tipo {tipo}, mês {mes}"
    prox = min(por_mes, key=lambda m: abs((data(m + "-15") - d).days))
    return por_mes[prox], f"tipo {tipo}, mês {prox} (mais próximo)"


def percentil_de(preco_publico: float, anc: dict) -> float:
    """Em que percentil (0 a 100) um preço público cai na âncora (linear entre p25, p50, p75, p90 e max)."""
    pontos = [(anc["p25"], 25.0), (anc["p50"], 50.0), (anc["p75"], 75.0), (anc["p90"], 90.0), (anc["max"], 100.0)]
    if preco_publico <= pontos[0][0]:
        return round(25.0 * preco_publico / pontos[0][0], 1) if pontos[0][0] else 0.0
    for (x0, y0), (x1, y1) in zip(pontos, pontos[1:]):
        if preco_publico <= x1:
            return round(y0 + (y1 - y0) * (preco_publico - x0) / (x1 - x0), 1) if x1 > x0 else y1
    return round(100.0 + 10.0 * (preco_publico - pontos[-1][0]) / pontos[-1][0], 1)


def valor_percentil(anc: dict, pct: float) -> float:
    """Preço público no percentil pedido (interpolação entre os pontos conhecidos; acima de 100 segue a reta do max)."""
    pontos = [(25.0, anc["p25"]), (50.0, anc["p50"]), (75.0, anc["p75"]), (90.0, anc["p90"]), (100.0, anc["max"])]
    if pct <= 25:
        return anc["p25"] * pct / 25.0
    for (x0, y0), (x1, y1) in zip(pontos, pontos[1:]):
        if pct <= x1:
            return y0 + (y1 - y0) * (pct - x0) / (x1 - x0)
    return anc["max"] * (1 + (pct - 100) / 100.0)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    c = sub.add_parser("construir")
    c.add_argument("--hoje", required=True)
    c.add_argument("--buscas", nargs="+", required=True)
    c.add_argument("--saida", default=str(ARQ_ANCORA))
    c.add_argument("--mesclar", action="store_true", help="mantém as noites do arquivo anterior que não forem cobertas")
    v = sub.add_parser("ver")
    v.add_argument("--data", required=True)
    v.add_argument("--ancora", default=str(ARQ_ANCORA))
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    if a.cmd == "construir":
        anterior = ler_json(a.saida) if a.mesclar and Path(a.saida).exists() else None
        anc = construir(cfg, a.buscas, data(a.hoje), anterior)
        with open(a.saida, "w", encoding="utf-8") as f:
            json.dump(anc, f, ensure_ascii=False, indent=1)
            f.write("\n")
        print(f"{len(anc['noites'])} noites com busca própria; tipos: "
              + "; ".join(f"{t}: {', '.join(sorted(m))}" for t, m in anc["por_tipo"].items()))
        for k, x in anc["noites"].items():
            print(f"  {k} {x['tipo']:8} p25 {x['p25']:5} p50 {x['p50']:5} p75 {x['p75']:5} p90 {x['p90']:5} max {x['max']:5} n={x['n']} ({x['fonte']})")
        return 0
    anc = ler_json(a.ancora)
    x, origem = ancora_da_noite(anc, cfg, data(a.data))
    print(json.dumps({"origem": origem, **(x or {})}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())

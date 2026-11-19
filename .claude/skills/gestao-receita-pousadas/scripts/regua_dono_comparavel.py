"""Régua do plano do dono (dados/regua_dono.json) pela régua de suítes comparáveis (dono, 18 e 19/11/2026).

Ordens: `dono-2026-11-18-suites-comparaveis` ("Ignore todos os outros valores de suítes sem banheira e café da manhã") e
`dono-2026-11-19-precificacao-dez-fev` ("já vamos ajustar dezembro, janeiro e fevereiro já na precificação, nesse
escopo"). O plano de 09/11 (plano_dono.py) abre fim de semana, feriado e festas por percentis da régua de cada noite;
até 19/11 a série `hidro` vinha da varredura de 09-10/11, com fator do quarto com banheira "incerto" (inflado, até 70%) e
a Moinhos abria no p40 de `lista` (o quarto mais barato de cada hotel). Este script troca, nas noites cobertas por uma
estadia do ranking comparável (scripts/ranking_comparaveis.py --json), as séries `hidro` e `pares_hidro` pelos percentis
das suítes comparáveis (menor quarto com café × r_hidro MEDIDO, certeza alta ou média) e marca a noite com
`comparaveis: true`; com a marca, plano_dono.abertura_publico abre a Moinhos no p40 de `hidro`.

- hidro: percentis 10, 25, 40, 50, 65, 75 e 90 do `suite_noite` dos comparáveis da estadia (só com `principal_ok`,
  8 ou mais hotéis);
- pares_hidro: os mesmos percentis só dos hotéis com nota abaixo de 9 (como a régua de 09/11), com 6 ou mais; senão,
  igual a `hidro` (marcado em `pares_fonte`);
- fator_publico.Recanto: o da estadia quando medido (público do Recanto na busca por coordenadas ÷ enviado); a Villa
  fica com o fator da régua (o da King Spa não se mede pela busca: o menor quarto livre é a Balcony);
- `lista` e `pares_lista` ficam como estão: só a Balcony (sem banheira) usa.

Estadia passada depois vence (passe o ranking mais novo por último). Noite sem estadia própria usa os comparáveis de todas
as estadias do mesmo grupo de noite (semana, fds, feriado, festas) no mesmo mês, juntos; sem nenhuma, fica como está.
Só biblioteca padrão; sem rede.

Uso (da pasta da skill):
  python3 scripts/regua_dono_comparavel.py --ranking <ranking1.json> [<ranking2.json> ...]
      [--regua dados/regua_dono.json] [--de 2026-12-01] [--ate 2027-02-28] [--aplicar]
Sem --aplicar só mostra o que mudaria.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ranking_comparaveis import MIN_N_PRINCIPAL, quantil  # noqa: E402

PASTA = Path(__file__).resolve().parents[1]
ARQ_REGUA = PASTA / "dados" / "regua_dono.json"
PCTS = ("10", "25", "40", "50", "65", "75", "90")
NOTA_PARES = 9.0
MIN_PARES = 6


def percentis(vals: list[float]) -> dict:
    return {p: round(quantil(vals, int(p) / 100.0)) for p in PCTS}


def noites_da_estadia(e: dict) -> list[str]:
    a, b = date.fromisoformat(e["checkin"]), date.fromisoformat(e["checkout"])
    return [(a + timedelta(days=i)).isoformat() for i in range((b - a).days)]


def series_da_estadia(e: dict) -> dict | None:
    """{hidro, pares_hidro, pares_fonte, n, n_pares, fator_recanto} da estadia, ou None sem régua principal."""
    comp = [c for c in (e.get("ranking") or []) if c.get("certeza") in ("alta", "media") and c.get("suite_noite")]
    if not e.get("principal_ok") or len(comp) < MIN_N_PRINCIPAL:
        return None
    vals = [float(c["suite_noite"]) for c in comp]
    pares = [float(c["suite_noite"]) for c in comp if c.get("nota") is not None and float(c["nota"]) < NOTA_PARES]
    out = {"hidro": percentis(vals), "n": len(vals), "n_pares": len(pares)}
    if len(pares) >= MIN_PARES:
        out["pares_hidro"], out["pares_fonte"] = percentis(pares), f"comparáveis com nota abaixo de {NOTA_PARES:g}"
    else:
        out["pares_hidro"], out["pares_fonte"] = dict(out["hidro"]), (f"só {len(pares)} comparáveis com nota abaixo de "
                                                                       f"{NOTA_PARES:g}: igual a hidro")
    fonte = str((e.get("fonte_fator") or {}).get("Recanto") or "")
    f = (e.get("fatores") or {}).get("Recanto")
    out["fator_recanto"] = float(f) if f and fonte.startswith("medido") else None
    return out


def _grupo(tipo: str | None) -> str:
    import plano_dono as PD
    return PD.grupo(tipo or "semana")


def series_do_mes(estadias: list[tuple[dict, dict]], regua: dict, mes: str, grupo: str) -> tuple[dict, dict] | None:
    """Noite sem estadia própria: os comparáveis de todas as estadias do mesmo grupo (semana, fds, feriado, festas) no
    mesmo mês, juntos (como o `por_tipo` da âncora). None sem estadia do grupo no mês."""
    from collections import Counter
    usadas = []
    for e, s in estadias:
        ns = [n for n in noites_da_estadia(e) if n in (regua.get("noites") or {})]
        if not ns or e["checkin"][:7] != mes:
            continue
        # a estadia conta para o grupo da maioria das noites (22→25/01: sexta, sábado e domingo = fds)
        g = Counter(_grupo(regua["noites"][n].get("tipo")) for n in ns).most_common(1)[0][0]
        if g == grupo:
            usadas.append(e)
    if not usadas:
        return None
    junta = {"checkin": min(e["checkin"] for e in usadas), "checkout": max(e["checkout"] for e in usadas),
             "principal_ok": True, "fatores": {}, "fonte_fator": {},
             "ranking": [c for e in usadas for c in (e.get("ranking") or [])]}
    s = series_da_estadia(junta)
    if s is None:
        return None
    s["fator_recanto"] = None  # o fator medido é da estadia; no agregado fica o da régua
    return {"checkin": f"{grupo} de {mes}", "checkout": f"{len(usadas)} estadias", "_mes": True}, s


def atualizar(regua: dict, rankings: list[dict], de: str, ate: str) -> list[dict]:
    """Aplica na régua (em memória) e devolve as mudanças [{noite, tipo, antes, depois, fonte}]."""
    por_noite: dict[str, tuple[dict, dict]] = {}
    todas: list[tuple[dict, dict]] = []
    for rk in rankings:
        for e in rk.get("estadias") or []:
            s = series_da_estadia(e)
            if s is None:
                continue
            todas.append((e, s))
            for n in noites_da_estadia(e):
                por_noite[n] = (e, s)  # o ranking passado depois vence
    mud = []
    cache: dict[tuple[str, str], tuple[dict, dict] | None] = {}
    for n, rn in sorted((regua.get("noites") or {}).items()):
        if not (de <= n <= ate):
            continue
        if n not in por_noite:
            ch = (n[:7], _grupo(rn.get("tipo")))
            if ch not in cache:
                cache[ch] = series_do_mes(todas, regua, *ch)
            if cache[ch] is None:
                continue
            e, s = cache[ch]
        else:
            e, s = por_noite[n]
        antes = {"hidro": dict(rn.get("hidro") or {}), "pares_hidro": dict(rn.get("pares_hidro") or {}),
                 "fator_publico": dict(rn.get("fator_publico") or {})}
        rn.setdefault("antes_comparaveis", {"hidro": antes["hidro"], "pares_hidro": antes["pares_hidro"],
                                            "fator_publico": antes["fator_publico"], "fonte": rn.get("fonte")})
        rn["hidro"], rn["pares_hidro"] = s["hidro"], s["pares_hidro"]
        rn["comparaveis"] = True
        origem = (f"{e['checkin']} ({e['checkout']})" if e.get("_mes") else f"estadia {e['checkin']}>{e['checkout']}")
        rn["fonte_comparavel"] = (f"suítes comparáveis (banheira + café, fator medido), {origem}: {s['n']} preços; "
                                  f"pares: {s['pares_fonte']} ({s['n_pares']})")
        if s["fator_recanto"]:
            rn.setdefault("fator_publico", {})["Recanto"] = round(s["fator_recanto"], 4)
            rn.setdefault("fator_fonte", {})["Recanto"] = f"medido na estadia {e['checkin']}>{e['checkout']} (ranking)"
        mud.append({"noite": n, "tipo": rn.get("tipo"), "antes": antes, "depois": {"hidro": rn["hidro"],
                    "pares_hidro": rn["pares_hidro"], "fator_publico": dict(rn.get("fator_publico") or {})},
                    "fonte": rn["fonte_comparavel"]})
    return mud


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--ranking", nargs="+", required=True, help="saída --json do ranking_comparaveis.py (mais novo por último)")
    ap.add_argument("--regua", default=str(ARQ_REGUA))
    ap.add_argument("--de", default="2026-12-01")
    ap.add_argument("--ate", default="2027-02-28")
    ap.add_argument("--aplicar", action="store_true")
    a = ap.parse_args(argv)
    with open(a.regua, encoding="utf-8") as f:
        regua = json.load(f)
    rankings = []
    for c in a.ranking:
        with open(c, encoding="utf-8") as f:
            rankings.append(json.load(f))
    mud = atualizar(regua, rankings, a.de, a.ate)
    sem = sorted(n for n in (regua.get("noites") or {}) if a.de <= n <= a.ate and n not in {m["noite"] for m in mud})
    print(f"{len(mud)} noites da régua do dono com suítes comparáveis; {len(sem)} sem estadia comparável (ficam como estão)"
          + (f": {', '.join(sem)}" if sem else ""))
    for m in mud:
        h0, h1 = m["antes"]["hidro"], m["depois"]["hidro"]
        print(f"  {m['noite']} {m['tipo']:>12}: hidro p40 {h0.get('40')} -> {h1['40']}, p50 {h0.get('50')} -> {h1['50']}; "
              f"pares p50 {m['antes']['pares_hidro'].get('50')} -> {m['depois']['pares_hidro']['50']}")
    if a.aplicar and mud:
        regua["comparaveis_em"] = date.today().isoformat()
        with open(a.regua, "w", encoding="utf-8") as f:
            json.dump(regua, f, ensure_ascii=False, indent=1)
        print(f"gravado em {a.regua}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

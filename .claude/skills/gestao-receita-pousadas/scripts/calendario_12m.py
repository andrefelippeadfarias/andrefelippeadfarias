"""Calendário de 12 meses (dono, 21/10: "Vamos rodar o plano agora e já atualizar tudo para os próximos 12 meses e manter
tudo em monitoramento. Inclusive restrições de permissão de check-in e check-outs, mínimo de dias da estadia.").

Uso:
  python3 calendario_12m.py plano --hoje AAAA-MM-DD [--de AAAA-MM-DD] [--ate AAAA-MM-DD] Q7=<recalculo> ... --saida plano.json
  python3 calendario_12m.py auditar --hoje AAAA-MM-DD [--ate AAAA-MM-DD] Q7=<recalculo> ... [--json saida.json]

Lê o recálculo do PriceLabs (refresh_listing_pricing: piso, estadia e chegada/saída efetivos de cada noite) e compara
cada noite com o que as regras da skill exigem. Nunca desce nada; só sobe o que falta:
  - piso das 6 suítes com banheira: domingo a quinta R$ 500 e sexta e sábado R$ 850 no preço final do Genius 3
    (limites_seguranca ÷ fator de vitrine), nunca abaixo do mínimo do quarto;
  - feriados de quartos.json: piso de feriado (piso_real ÷ fator_real, ou fator de vitrine na Balcony; sem valor,
    o de segurança de R$ 800), nas suítes nunca abaixo de R$ 1.000 enviados (os pisos de R$ 500/850 são fora de feriado);
  - estadia mínima: sábado 2 (Regra H) e feriado pela lista de feriados ou pelo calendário (validar_plano.estadia_exigida);
  - chegada e saída no sábado (Regra H): ficam no Beds24, onde o dono já bloqueia todo sábado, por quarto
    (`parametros.regra_h_restricao`: "chegada" = noCheckIn; "chegada_saida" = noCheckInOrCheckOut). O script confere
    o Beds24 e lista os sábados sem bloqueio para o dono estender. O PriceLabs só aceita chegada/saída por data com a
    personalização ligada na tela (teste de 21/10); com ela ligada, --restricoes-pricelabs grava o mesmo padrão.
O plano sai no formato do validar_plano.py (piso em reais; o payload converte para percent_base). `auditar` lista o que
estiver abaixo da regra (para a rotina e para o monitoramento) sem gerar plano.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from comum import carregar_quartos, periodo_feriado  # noqa: E402
from validar_plano import estadia_exigida  # noqa: E402

SEMANA_TODA = "1111111"


def ler_recalculo(caminho: str) -> dict:
    t = open(caminho, encoding="utf-8").read()
    d = json.loads(t[t.find("{"):])
    arr = (d.get("data") or d).get("pricing_array") or []
    return {r["date"][:10]: r for r in arr}


def padrao_sabado(regra: str) -> tuple[str, str]:
    """Strings de chegada e saída (segunda a domingo) com o sábado fechado."""
    fechado = SEMANA_TODA[:5] + "0" + SEMANA_TODA[6]
    return fechado, (fechado if regra == "chegada_saida" else SEMANA_TODA)


def exigencias(cfg: dict, q: dict, d: dt.date) -> dict:
    L, P = cfg["limites_seguranca"], cfg["parametros"]
    fer = periodo_feriado(cfg, d)
    fds = d.weekday() in (4, 5)
    piso = None
    if q.get("banheira"):
        alvo = L["piso_vitrine_fds_suites"] if fds else L["piso_vitrine_semana_suites"]
        fat = (P["fator_vitrine_fds"] if fds else P["fator_vitrine_semana"])[q["pousada"]]
        piso = max(float(q["min"]), float(alvo) / float(fat))
    if fer:
        real = (fer.get("piso_real_por_quarto") or {}).get(q["curto"], fer.get("piso_real")) or L["piso_feriado_real_seguranca"]
        real = max(float(real), float(L["piso_feriado_real_seguranca"]))
        fat = q["fator_vitrine"] if q.get("piso_feriado_base") == "vitrine" else q["fator_real"]
        pf = max(float(q["min"]), real / float(fat))
        if q.get("banheira"):
            pf = max(pf, float(L["padrao_qualidade_diaria"]))
        piso = pf   # feriado: vale o piso de feriado (os pisos de R$ 500/850 são "fora de feriado", regras.md §4)
    ms = estadia_exigida(cfg, d, fer)
    regra_h = (P.get("regra_h_restricao") or {}).get(q["curto"])
    ci = co = None
    if d.weekday() == 5 and regra_h:
        ci, co = padrao_sabado(regra_h)
    return {"piso": round(piso) if piso else None, "min_stay": ms, "check_in": ci, "check_out": co, "feriado": fer}


def _fechado(s, d: dt.date) -> bool:
    s = str(s or "-1")
    return len(s) == 7 and s[d.weekday()] == "0"


def comparar(cfg: dict, quarto: str, cal: dict, de: dt.date, ate: dt.date, restricoes: bool = False) -> list[dict]:
    q = cfg["por_curto"][quarto]
    out = []
    d = de
    while d <= ate:
        r = cal.get(d.isoformat())
        if r is None:
            out.append({"quarto": quarto, "data": d.isoformat(), "falta": ["sem dado de preço no recálculo"]})
            d += dt.timedelta(days=1)
            continue
        ex = exigencias(cfg, q, d)
        falta, item = [], {}
        piso_ef = float(r.get("min_price") or 0)
        if ex["piso"] and piso_ef < ex["piso"] - 1:
            falta.append(f"piso {piso_ef:.0f} < {ex['piso']}")
            item["min_price"] = ex["piso"]
        ms_ef = int(float(r.get("min_stay") or 1))
        if ex["min_stay"] and ms_ef < ex["min_stay"]:
            falta.append(f"estadia {ms_ef} < {ex['min_stay']}")
            item["min_stay"] = ex["min_stay"]
        if ex["check_in"] and restricoes:
            precisa_saida = ex["check_out"] != SEMANA_TODA
            if not _fechado(r.get("check_in"), d) or (precisa_saida and not _fechado(r.get("check_out"), d)):
                falta.append("sábado sem a restrição de chegada/saída do Beds24")
                item.update(check_in=ex["check_in"], check_out=ex["check_out"])
        if falta:
            # estadia exigida sempre explícita no item (a gravação junta campos; deixa a regra visível na data)
            if ex["min_stay"] and "min_stay" not in item:
                item["min_stay"] = max(ex["min_stay"], ms_ef)
            item["preco_antes"] = float(r.get("price") or 0) or None
            out.append({"quarto": quarto, "data": d.isoformat(), "falta": falta, "item": item,
                        "feriado": (ex["feriado"] or {}).get("nome"), "dso": r.get("dso_flag")})
        d += dt.timedelta(days=1)
    return out


def auditar_beds24(cfg: dict, de: dt.date, ate: dt.date, token=None) -> list[dict]:
    """Restrições de chegada e saída ficam no Beds24 (o PriceLabs só aceita chegada/saída por data com a personalização
    ligada na tela, 21/10). Lista, por quarto, sábados sem o bloqueio da Regra H e noites fora do sábado com bloqueio."""
    import beds24
    tok = token or beds24.token_de_acesso()
    esperado = {"chegada": "noCheckIn", "chegada_saida": "noCheckInOrCheckOut"}
    regra = (cfg["parametros"].get("regra_h_restricao") or {})
    out = []
    por_quarto = {}
    for p in beds24.paginas("/inventory/rooms/calendar", {"roomId": list(beds24.QUARTOS), "startDate": de.isoformat(),
                                                         "endDate": ate.isoformat(), "includeOverride": "true"}, tok):
        dia = por_quarto.setdefault(beds24.QUARTOS[str(p["roomId"])][0], {})   # um quarto pode vir em várias páginas
        for c in p.get("calendar") or []:
            d, e = dt.date.fromisoformat(c["from"]), dt.date.fromisoformat(c["to"])
            while d <= e:
                dia[d] = c.get("override") or "none"
                d += dt.timedelta(days=1)
    for q, dia in por_quarto.items():
        falta, outro, extra = [], [], []
        d = de
        while d <= ate:
            o = dia.get(d, "none")
            if d.weekday() == 5 and regra.get(q):
                if o == "none":
                    falta.append(d.isoformat())
                elif o != esperado[regra[q]]:
                    outro.append(f"{d.isoformat()} {o}")
            elif d.weekday() != 5 and o not in ("none",):
                extra.append(f"{d.isoformat()} {o}")
            d += dt.timedelta(days=1)
        out.append({"quarto": q, "esperado": esperado.get(regra.get(q), "-"), "sabados_sem_bloqueio": falta,
                    "sabados_com_outro_bloqueio": outro, "outras_noites_bloqueadas": extra})
    return out


def montar_plano(diferencas: list[dict], hoje: dt.date) -> dict:
    itens = []
    for x in diferencas:
        if not x.get("item"):
            continue
        partes = []
        if "min_price" in x["item"]:
            partes.append(f"piso feriado {x['feriado'].split(' (')[0]}" if x.get("feriado") else "piso dono 500/850 final")
        if "min_stay" in x["item"] and any(f.startswith("estadia") for f in x["falta"]):
            partes.append(f"estadia {x['item']['min_stay']}n" + (" feriado" if x.get("feriado") else " sábado"))
        if "check_in" in x["item"]:
            partes.append("sáb sem chegada" + ("/saída" if x["item"]["check_out"] != SEMANA_TODA else "") + " (Regra H, Beds24)")
        it = {"tipo": "data", "quarto": x["quarto"], "data": x["data"], **x["item"],
              "motivo": "12m 21/10: " + "; ".join(partes)}
        if x["quarto"] == "Q2" and "min_price" in x["item"]:
            # Queen (2) sem desconto: o piso só protege (o preço segue em percentual, sem negativo). É a ordem do dono de
            # 20/10 ("Serve para todo o piso") estendida a 12 meses (21/10), então vai com excecao "dono".
            it["excecao"] = "dono"
            it["motivo"] += "; dono 20/10 todo o piso"
        itens.append(it)
    return {"hoje": hoje.isoformat(), "itens": itens}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("modo", choices=["plano", "auditar"])
    ap.add_argument("--hoje", required=True)
    ap.add_argument("--de", help="primeira noite (padrão: hoje + 4, para não mexer no que a rotina decide de D0 a D3)")
    ap.add_argument("--ate", help="última noite (padrão: hoje + 366 dias, horizonte rolante de 12 meses)")
    ap.add_argument("--saida", default="plano_12m.json")
    ap.add_argument("--json")
    ap.add_argument("--restricoes-pricelabs", action="store_true",
                    help="gravar chegada/saída de sábado no PriceLabs (exige a personalização ligada na tela)")
    ap.add_argument("--sem-beds24", action="store_true", help="não conferir as restrições no Beds24")
    ap.add_argument("recalculos", nargs="+", help="QUARTO=arquivo do refresh_listing_pricing")
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    hoje = dt.date.fromisoformat(a.hoje)
    de = dt.date.fromisoformat(a.de) if a.de else hoje + dt.timedelta(days=4)
    ate = dt.date.fromisoformat(a.ate) if a.ate else hoje + dt.timedelta(days=366)
    ult = max((dt.date.fromisoformat(f["fim"]) for f in cfg.get("feriados", [])), default=hoje)
    if ult < ate:
        print(f"AVISO: a lista de feriados de quartos.json termina em {ult}; acrescente os feriados até {ate} "
              "(e sazonalidade.json) para o calendário cobrir o horizonte")
    dif = []
    for arg in a.recalculos:
        quarto, caminho = arg.split("=", 1)
        dif += comparar(cfg, quarto, ler_recalculo(caminho), de, ate, a.restricoes_pricelabs)
    resumo = {}
    for x in dif:
        for f in x["falta"]:
            chave = (x["quarto"], f.split(" ")[0])
            resumo[chave] = resumo.get(chave, 0) + 1
    for (quarto, tipo), n in sorted(resumo.items()):
        print(f"{quarto:9} {tipo:10} {n} noites")
    print(f"{len(dif)} noites com algo abaixo da regra, de {de} a {ate}")
    if a.modo == "plano":
        plano = montar_plano(dif, hoje)
        json.dump(plano, open(a.saida, "w"), ensure_ascii=False, indent=1)
        print(f"{len(plano['itens'])} itens em {a.saida}")
    b24 = []
    if not a.sem_beds24:
        b24 = auditar_beds24(cfg, de, ate)
        for x in b24:
            fs = x["sabados_sem_bloqueio"]
            ou = x["sabados_com_outro_bloqueio"]
            print(f"Beds24 {x['quarto']:9} sábados sem bloqueio: {len(fs)}" + (f" (de {fs[0]} a {fs[-1]})" if fs else "") +
                  (f"; {len(ou)} com padrão diferente de {x['esperado']} ({ou[0]} a {ou[-1]})" if ou else "") +
                  (f"; outras noites bloqueadas: {', '.join(x['outras_noites_bloqueadas'][:5])}" if x["outras_noites_bloqueadas"] else ""))
    if a.json:
        json.dump({"pricelabs": dif, "beds24": b24}, open(a.json, "w"), ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

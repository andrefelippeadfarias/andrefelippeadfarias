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
  - chegada e saída no sábado (dono 02/11: "mantenha todos os sábados fechados sempre, tanto para a entrada quanto para
    a saída"): ficam no Beds24, todo sábado `parametros.regra_h_beds24.sabado_override` (noCheckInOrCheckOut), inclusive
    em feriado. Exceções do dono de 02/11 ("Pode fazer"): os sábados de `regra_h_beds24.sabados_excecao` (26/12 e 02/01,
    entrada e saída abertas) e o lado órfão do fim de semana (`regra_h_beds24.orfas`: sábado esgotado com a sexta livre
    fica noCheckIn; sexta esgotada com o sábado livre fica noCheckOut, só naquele quarto; a noite órfã com estadia acima
    de 1 sai em `orfas_estadia`, para o PriceLabs por data). O script lê o Beds24 e lista os sábados fora da regra, os sábados com estadia 1, as noites de
    domingo a quinta fora de feriado com estadia acima do calendário de sazonalidade (dono 01/11: "Sim") e as outras
    noites com restrição; `--plano-beds24` gera o lote do `beds24.py editar-lote` que fecha o sábado.
  - ordens do dono (`dados/ordens_dono.json`, piso_por_data e alvo_por_data): piso abaixo do padrão numa noite coberta
    por ordem, e não abaixo do piso da ordem, sai como informação, não como falta. Dia órfão (noites vizinhas esgotadas)
    com estadia 1 também (regra de dia órfão do PriceLabs, aprendizado de 08/10).
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
from ordens import carregar_ordens, ordem_valida, piso_da_ordem  # noqa: E402
from validar_plano import estadia_exigida  # noqa: E402



def ler_recalculo(caminho: str) -> dict:
    t = open(caminho, encoding="utf-8").read()
    d = json.loads(t[t.find("{"):])
    arr = (d.get("data") or d).get("pricing_array") or []
    return {r["date"][:10]: r for r in arr}


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
    return {"piso": round(piso) if piso else None, "min_stay": ms, "feriado": fer}


def _cheia(r) -> bool:
    """Noite esgotada pelo recálculo (mu_occupancy "v/u" com v >= u)."""
    try:
        v, u = str((r or {}).get("mu_occupancy") or "").split("/")
        return int(u) > 0 and int(v) >= int(u)
    except ValueError:
        return False


def comparar(cfg: dict, quarto: str, cal: dict, de: dt.date, ate: dt.date, ordens: dict | None = None) -> list[dict]:
    """Noites abaixo da regra (`falta`, com o `item` que corrige) e noites abaixo do padrão por motivo conhecido
    (`info`: ordem do dono ou dia órfão), sem item."""
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
        falta, info, item = [], [], {}
        piso_ef = float(r.get("min_price") or 0)
        if ex["piso"] and piso_ef < ex["piso"] - 1:
            po = piso_da_ordem(ordens or {}, quarto, d)
            if po and piso_ef >= po[0] - 1:
                info.append(f"piso {piso_ef:.0f} < {ex['piso']} pela ordem {po[1].get('id')} (piso {po[0]:.0f})")
            else:
                falta.append(f"piso {piso_ef:.0f} < {ex['piso']}")
                item["min_price"] = ex["piso"]
        ms_ef = int(float(r.get("min_stay") or 1))
        if ex["min_stay"] and ms_ef < ex["min_stay"]:
            um = dt.timedelta(days=1)
            ant = cal.get((d - um).isoformat())
            orf = ((cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("orfas") or {}
            if ms_ef == 1 and _cheia(ant) and _cheia(cal.get((d + um).isoformat())):
                info.append(f"estadia {ms_ef} < {ex['min_stay']}: dia órfão (noites vizinhas esgotadas)")
            elif (ms_ef == 1 and d.weekday() == 5 and not ex["feriado"] and orf.get("ativa") and _cheia(ant)
                  and not _cheia(r) and ordem_valida(ordens or {}, orf.get("ordem_id"), quarto, d)):
                # dono 02/11 ("Pode fazer"): sexta esgotada com o sábado livre, o sábado órfão vende com estadia 1;
                # sem isto o plano de 12 meses devolvia a estadia 2 que a auditoria do Beds24 manda tirar (revisão 02/11)
                info.append(f"estadia 1 < {ex['min_stay']}: sábado órfão (sexta esgotada), ordem {orf.get('ordem_id')}")
            else:
                falta.append(f"estadia {ms_ef} < {ex['min_stay']}")
                item["min_stay"] = ex["min_stay"]
        if falta:
            # estadia exigida sempre explícita no item (a gravação junta campos; deixa a regra visível na data)
            if ex["min_stay"] and "min_stay" not in item:
                item["min_stay"] = max(ex["min_stay"], ms_ef)
            item["preco_antes"] = float(r.get("price") or 0) or None
            out.append({"quarto": quarto, "data": d.isoformat(), "falta": falta, "item": item, "info": info,
                        "feriado": (ex["feriado"] or {}).get("nome"), "dso": r.get("dso_flag")})
        elif info:
            out.append({"quarto": quarto, "data": d.isoformat(), "falta": [], "info": info,
                        "feriado": (ex["feriado"] or {}).get("nome"), "dso": r.get("dso_flag")})
        d += dt.timedelta(days=1)
    return out


def classificar_beds24(cfg: dict, inv: dict, de: dt.date, ate: dt.date) -> list[dict]:
    """Regra H no Beds24 (dono 01/11): por quarto, sábados com a chegada fechada (com a troca que a libera), sábados com
    estadia abaixo de 2 e noites fora do sábado com restrição (só o dono decide; vão ao relatório).
    `inv` = beds24.inventario(): {curto: {data: {numAvail, minStay, override}}}."""
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    alvo_sab = rh.get("sabado_override")  # dono 02/11: sábado fechado para entrada e saída, sempre
    troca = rh.get("troca_sabado") or {"noCheckIn": "none", "noCheckInOrCheckOut": "noCheckOut"}
    ms_sab = int(rh.get("estadia_minima_sabado", 2))
    # dono 02/11 ("Pode fazer"): sábados de festa com entrada e saída abertas, e o lado órfão do fim de semana
    # (sábado esgotado com a sexta livre, ou sexta esgotada com o sábado livre) abre só naquele quarto
    excecoes = rh.get("sabados_excecao") or {}
    orfas = rh.get("orfas") or {}
    # dono 01/11 ("Sim"): Afrodite com a saída de sexta fechada (02/11: o envio do PriceLabs tinha aberto as 52 sextas
    # por um check_out 1111100 gravado por engano; a auditoria passa a conferir a sexta desse quarto)
    sexta_alvo = rh.get("sexta_override_por_quarto") or {}
    try:
        from sazonalidade import carregar as _carregar_saz, periodo as _periodo_saz
        saz = _carregar_saz()
    except Exception:
        saz = None
    out = []
    for q in sorted(inv):
        chegada, estadia, outras, semana, orfa_estadia = [], [], [], [], []
        for ds, v in sorted(inv[q].items()):
            d = dt.date.fromisoformat(ds)
            if not de <= d <= ate:
                continue
            o = (v or {}).get("override") or "none"
            ms = int((v or {}).get("minStay") or 1)
            lado_orfao = None
            if d.weekday() == 5:
                if alvo_sab:
                    alvo, por_que = alvo_sab, None
                    if ds in excecoes:
                        alvo, por_que = excecoes[ds], "sábado de festa aberto (dono 02/11)"
                    elif orfas.get("ativa"):
                        sx = inv[q].get((d - dt.timedelta(days=1)).isoformat())
                        sab_av, sex_av = (v or {}).get("numAvail"), (sx or {}).get("numAvail")
                        if sab_av is not None and sex_av is not None:
                            if int(sab_av) <= 0 < int(sex_av):
                                alvo, lado_orfao = orfas.get("sabado_esgotado", "noCheckIn"), "sexta"
                                por_que = f"sábado esgotado, sexta livre ({int(sex_av)}): saída do sábado aberta (dono 02/11)"
                            elif int(sex_av) <= 0 < int(sab_av):
                                alvo, lado_orfao = orfas.get("sexta_esgotada", "noCheckOut"), "sábado"
                                por_que = f"sexta esgotada, sábado livre ({int(sab_av)}): entrada do sábado aberta (dono 02/11)"
                    if o != alvo:
                        it = {"data": ds, "override": o, "troca": alvo}
                        if por_que:
                            it["motivo"] = por_que
                        if q == "Afrodite":
                            it["pricelabs_tambem"] = True  # o envio do PriceLabs leva a chegada/saída da Afrodite ao Beds24
                        chegada.append(it)
                    # a noite órfã vende sozinha só com estadia 1 (no PriceLabs por data: o envio leva a estadia ao
                    # Beds24); feriado fica de fora (a estadia do feriado é do dono)
                    if lado_orfao == "sexta":
                        sxd = (d - dt.timedelta(days=1)).isoformat()
                        ms_sx = int((inv[q].get(sxd) or {}).get("minStay") or 1)
                        if ms_sx > 1 and not periodo_feriado(cfg, d - dt.timedelta(days=1)):
                            orfa_estadia.append({"data": sxd, "min_stay": ms_sx, "alvo": 1, "lado": "sexta"})
                    elif lado_orfao == "sábado" and ms > 1 and not periodo_feriado(cfg, d):
                        orfa_estadia.append({"data": ds, "min_stay": ms, "alvo": 1, "lado": "sábado"})
                elif o in troca:
                    chegada.append({"data": ds, "override": o, "troca": troca[o]})
                elif o != "none" and o != "noCheckOut":
                    outras.append(f"{ds} {o}")
                if ms < ms_sab and not (lado_orfao == "sábado" and not periodo_feriado(cfg, d)):
                    estadia.append(ds)
                continue
            if d.weekday() == 4 and q in sexta_alvo:
                if o != sexta_alvo[q]:
                    chegada.append({"data": ds, "override": o, "troca": sexta_alvo[q],
                                    "motivo": "sexta da Afrodite: chegada liberada, saída fechada (dono 01/11)",
                                    "pricelabs_tambem": True})
            elif o != "none" and not (q == "Afrodite" and d.weekday() == 4 and o == "noCheckOut"):
                outras.append(f"{ds} {o}")
            # domingo a quinta fora de feriado: estadia do calendário de sazonalidade (dono, 01/11: "Sim")
            if d.weekday() != 4 and saz is not None and not periodo_feriado(cfg, d):
                cal = int(((_periodo_saz(saz, d) or {}).get("estadia_minima")) or 1)
                if ms > cal:
                    semana.append({"data": ds, "min_stay": ms, "calendario": cal})
        out.append({"quarto": q, "sabados_fora_da_regra": chegada, "sabados_estadia_abaixo": estadia,
                    "outras_noites_bloqueadas": outras, "semana_estadia_acima": semana, "orfas_estadia": orfa_estadia})
    return out


def plano_beds24_sabados(classif: list[dict], motivo: str, motivo_semana: str | None = None) -> list[dict]:
    """Lote do `beds24.py editar-lote`: acerta o override do sábado (fechado; exceções do dono de 02/11) e, com
    `motivo_semana`, devolve ao calendário a estadia de domingo a quinta fora de feriado (dono, 01/11)."""
    lote = []
    for x in classif:
        for s in x["sabados_fora_da_regra"]:
            it = {"quarto": x["quarto"], "de": s["data"], "ate": s["data"], "override": s["troca"],
                  "motivo": s.get("motivo") or motivo}
            if s.get("pricelabs_tambem"):
                it["pricelabs_tambem"] = True  # Afrodite: grave também no PriceLabs por data, senão o envio desfaz
            lote.append(it)
    if motivo_semana:
        lote += [{"quarto": x["quarto"], "de": s["data"], "ate": s["data"], "min_stay": s["calendario"],
                  "motivo": motivo_semana} for x in classif for s in x.get("semana_estadia_acima", [])]
    return lote


def auditar_beds24(cfg: dict, de: dt.date, ate: dt.date, token=None) -> list[dict]:
    import beds24
    # a sexta anterior ao primeiro dia entra na leitura: o sábado órfão (dono 02/11) depende dela
    inv = beds24.inventario(token or beds24.token_de_acesso(), (de - dt.timedelta(days=1)).isoformat(), ate.isoformat())
    return classificar_beds24(cfg, inv, de, ate)


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
    ap.add_argument("--de", help="primeira noite (padrão: hoje + 4 no PriceLabs, para não mexer no que a rotina decide de "
                                 "D0 a D3; o Beds24 é auditado desde hoje)")
    ap.add_argument("--ate", help="última noite (padrão: hoje + 366 dias, horizonte rolante de 12 meses)")
    ap.add_argument("--saida", default="plano_12m.json")
    ap.add_argument("--json")
    ap.add_argument("--plano-beds24", help="grava o lote do beds24.py editar-lote que acerta a chegada/saída dos sábados")
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
    ordens = carregar_ordens()
    for arg in a.recalculos:
        quarto, caminho = arg.split("=", 1)
        dif += comparar(cfg, quarto, ler_recalculo(caminho), de, ate, ordens)
    infos = [x for x in dif if not x["falta"]]
    dif = [x for x in dif if x["falta"]]
    resumo = {}
    for x in infos:
        for f in x["info"]:
            chave = (x["quarto"], "info:" + ("ordem" if "ordem" in f else "órfão"))
            resumo[chave] = resumo.get(chave, 0) + 1
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
        # Beds24 desde hoje (revisão 02/11): o lado órfão do sábado muda com reserva e cancelamento de D0 a D3 também;
        # o corte em hoje + 4 vale só para os pisos e estadias do PriceLabs
        de_b24 = dt.date.fromisoformat(a.de) if a.de else hoje
        b24 = auditar_beds24(cfg, de_b24, ate)
        for x in b24:
            ch, es, ou = x["sabados_fora_da_regra"], x["sabados_estadia_abaixo"], x["outras_noites_bloqueadas"]
            se = x.get("semana_estadia_acima") or []
            oe = x.get("orfas_estadia") or []
            print(f"Beds24 {x['quarto']:9} sábados com chegada fechada: {len(ch)}" +
                  (f" (de {ch[0]['data']} a {ch[-1]['data']})" if ch else "") +
                  (f"; dom-qui com estadia acima do calendário: {len(se)} ({se[0]['data']} a {se[-1]['data']})" if se else "") +
                  (f"; sábados com estadia abaixo de 2: {', '.join(es[:5])}" if es else "") +
                  (f"; outras noites com restrição: {len(ou)} ({', '.join(ou[:3])}...)" if ou else "") +
                  (f"; noites órfãs com estadia acima de 1 (PriceLabs por data, ordem dono-2026-11-02-sabado-orfao): "
                   f"{', '.join(o['data'] for o in oe)}" if oe else ""))
            for s_ in ch:
                if s_.get("pricelabs_tambem"):
                    print(f"  PriceLabs também ({x['quarto']}): {s_['data']} -> {s_['troca']} (check_in/check_out por data)")
        if a.plano_beds24:
            lote = plano_beds24_sabados(b24, "Dono 02/11: sábado fechado para entrada e saída, sempre (noCheckInOrCheckOut); "
                                             "exceções do dono de 02/11 vão com o motivo próprio",
                                        "Regra A no Beds24 (dono 01/11, 'Sim'): domingo a quinta fora de feriado com a "
                                        "estadia do calendário de sazonalidade")
            json.dump(lote, open(a.plano_beds24, "w"), ensure_ascii=False, indent=1)
            print(f"{len(lote)} sábados no lote do Beds24 em {a.plano_beds24}")
    if a.json:
        json.dump({"pricelabs": dif, "info": infos, "beds24": b24}, open(a.json, "w"), ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

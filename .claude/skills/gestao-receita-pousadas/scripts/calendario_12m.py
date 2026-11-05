"""Calendário de 12 meses (dono, 21/10: "Vamos rodar o plano agora e já atualizar tudo para os próximos 12 meses e manter
tudo em monitoramento. Inclusive restrições de permissão de check-in e check-outs, mínimo de dias da estadia.").

Uso:
  python3 calendario_12m.py plano --hoje AAAA-MM-DD [--de AAAA-MM-DD] [--ate AAAA-MM-DD] Q7=<recalculo> ... --saida plano.json
  python3 calendario_12m.py auditar --hoje AAAA-MM-DD [--ate AAAA-MM-DD] Q7=<recalculo> ... [--json saida.json]
  Estadia pelo dia de chegada (dono 04/11), só estadia, com as regras por data e o inventário já lidos:
  python3 calendario_12m.py plano --so-estadia --hoje AAAA-MM-DD --de AAAA-MM-DD --ate AAAA-MM-DD Q7=<recalculo> ...
      --regras Q7=<get_listing_date_overrides> ... --inventario inv_b24.json --lotes-estadia <pasta>/lote_b24 --saida plano.json

Lê o recálculo do PriceLabs (refresh_listing_pricing: piso, estadia e chegada/saída efetivos de cada noite) e compara
cada noite com o que as regras da skill exigem. O piso só sobe; a estadia sobe e, pela ordem de 04/11, também desce:
  - piso das 6 suítes com banheira: domingo a quinta R$ 500 e sexta e sábado R$ 850 no preço final do Genius 3
    (limites_seguranca ÷ fator de vitrine), nunca abaixo do mínimo do quarto;
  - feriados de quartos.json: piso de feriado (piso_real ÷ fator_real, ou fator de vitrine na Balcony; sem valor,
    o de segurança de R$ 800), nas suítes nunca abaixo de R$ 1.000 enviados (os pisos de R$ 500/850 são fora de feriado);
  - estadia mínima (validar_plano.estadia_exigida/estadia_planejada): desde as noites de 05/11 pelo dia de chegada (dono
    04/11: domingo, terça e quinta 1; segunda, quarta e sexta 2; temporada acima do dia vale), subindo ao alvo e
    descendo a 1 no domingo, na terça e na quinta fora de temporada e na noite órfã (livre no quarto com a seguinte
    esgotada nele: item com `orfa` e `orfa_prova`); sábado 2 (Regra H), feriado pela lista de feriados ou pelo
    calendário e pacote pela estadia por data só sobem (com --so-estadia, nem isso: vão como falta sem item);
  - chegada e saída no sábado (dono 02/11: "mantenha todos os sábados fechados sempre, tanto para a entrada quanto para
    a saída"): ficam no Beds24, todo sábado `parametros.regra_h_beds24.sabado_override` (noCheckInOrCheckOut), inclusive
    em feriado. Exceções do dono de 02/11 ("Pode fazer"): os sábados de `regra_h_beds24.sabados_excecao` (26/12 e 02/01,
    entrada e saída abertas) e o lado órfão do fim de semana (`regra_h_beds24.orfas`: sábado esgotado com a sexta livre
    fica noCheckIn; sexta esgotada com o sábado livre fica noCheckOut, só naquele quarto; a noite órfã com estadia acima
    de 1 sai em `orfas_estadia`, para o PriceLabs por data). O script lê o Beds24 e lista os sábados fora da regra, os sábados com estadia 1, as noites de
    domingo a quinta fora de feriado com estadia acima do calendário de sazonalidade (dono 01/11: "Sim"; substituído
    nas noites desde 05/11 por `estadia_dia_chegada`, a mesma decisão do plano do PriceLabs, de domingo a sexta) e as
    outras noites com restrição; `--plano-beds24` gera o lote do `beds24.py editar-lote` que fecha o sábado e acerta a
    estadia; `--lotes-estadia` grava os lotes só de estadia, um por pousada.
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
from comum import DIAS_SEMANA, carregar_quartos, periodo_feriado, regra_dia_chegada, vendidas_total  # noqa: E402
from ordens import carregar_ordens, ordem_valida, piso_da_ordem  # noqa: E402
from validar_plano import estadia_exigida, estadia_planejada  # noqa: E402

UM_DIA = dt.timedelta(days=1)


def ler_recalculo(caminho: str) -> dict:
    t = open(caminho, encoding="utf-8").read()
    d = json.loads(t[t.find("{"):])
    arr = (d.get("data") or d).get("pricing_array") or []
    return {r["date"][:10]: r for r in arr}


def ler_regras_por_data(pares: list[str]) -> dict:
    """{curto: {data: regra}} de QUARTO=arquivo do get_listing_date_overrides (o mesmo formato do pace.ler_regras)."""
    out = {}
    for par in pares:
        k, v = par.split("=", 1)
        t = open(v, encoding="utf-8").read()
        j = json.loads(t[min(i for i in (t.find("{"), t.find("[")) if i >= 0):])
        lst = j if isinstance(j, list) else (((j.get("data") or {}).get("overrides") if isinstance(j.get("data"), dict)
                                              else None) or j.get("overrides") or [])
        out[k] = {o["date"][:10]: o for o in lst if o.get("date")}
    return out


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


def _cheia(r, unidades: int = 1) -> bool:
    """Noite esgotada pelo recálculo (mu_occupancy "v/u" com v >= u). Na Afrodite (1 unidade) o mu_occupancy vem "-1" e a
    venda está em num_bookings (comum.vendidas_total); antes ela nunca contava como esgotada (revisão de 05/11)."""
    if not r:
        return False
    v, t = vendidas_total(r, unidades)
    return t > 0 and v >= t


def estadia_da_noite(cfg: dict, quarto: str, d: dt.date, cal: dict,
                     atual: int | None = None) -> tuple[int | None, str | None, dict | None]:
    """(estadia planejada, 'seguinte' na noite órfã, prova da ocupação) da noite pelo recálculo do quarto, pela ordem de
    04/11 (validar_plano.estadia_planejada); (None, None, None) onde a regra do dia não decide (feriado, pacote, sábado,
    antes de 05/11). A prova vai no item da noite órfã (`orfa_prova`) para o validador conferir."""
    u = int(cfg["por_curto"][quarto].get("unidades") or 1)
    r, seg = cal.get(d.isoformat()), cal.get((d + UM_DIA).isoformat())
    novo, orfa = estadia_planejada(cfg, d, atual, livre=bool(r) and not _cheia(r, u), seguinte_esgotada=_cheia(seg, u))
    prova = None
    if orfa:
        (nv, nt), (sv, st) = vendidas_total(r, u), vendidas_total(seg, u)
        prova = {"noite_vendidas": nv, "noite_total": nt, "seguinte_vendidas": sv, "seguinte_total": st}
    return novo, orfa, prova


def comparar(cfg: dict, quarto: str, cal: dict, de: dt.date, ate: dt.date, ordens: dict | None = None,
             so_estadia: bool = False, regras: dict | None = None) -> list[dict]:
    """Noites fora da regra (`falta`, com o `item` que corrige) e noites fora do padrão por motivo conhecido (`info`:
    ordem do dono, dia órfão, noite órfã), sem item. Estadia (dono 04/11, desde 05/11): onde a regra do dia de chegada
    decide, sobe ao alvo e desce a 1 (domingo, terça e quinta fora de temporada, e a noite órfã); sábado, feriado e pacote
    só sobem até a exigida, como antes. `so_estadia`: sem piso, e sábado/feriado/pacote abaixo da exigida vão como falta
    sem item (o plano só de estadia não mexe neles). `regras` {data: regra por data vigente}: o item só de estadia numa
    data com regra leva `manter_reason` (o payload sai sem reason e o da regra fica)."""
    q = cfg["por_curto"][quarto]
    u = int(q.get("unidades") or 1)
    orf = ((cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("orfas") or {}
    out = []
    d = de
    while d <= ate:
        r = cal.get(d.isoformat())
        if r is None:
            out.append({"quarto": quarto, "data": d.isoformat(), "falta": ["sem dado de preço no recálculo"]})
            d += UM_DIA
            continue
        ex = exigencias(cfg, q, d)
        falta, info, item = [], [], {}
        piso_ef = float(r.get("min_price") or 0)
        if not so_estadia and ex["piso"] and piso_ef < ex["piso"] - 1:
            po = piso_da_ordem(ordens or {}, quarto, d)
            if po and piso_ef >= po[0] - 1:
                info.append(f"piso {piso_ef:.0f} < {ex['piso']} pela ordem {po[1].get('id')} (piso {po[0]:.0f})")
            else:
                falta.append(f"piso {piso_ef:.0f} < {ex['piso']}")
                item["min_price"] = ex["piso"]
        ms_ef = int(float(r.get("min_stay") or 1))
        ant, seg = cal.get((d - UM_DIA).isoformat()), cal.get((d + UM_DIA).isoformat())
        dia_orfao_pl = ms_ef == 1 and _cheia(ant, u) and _cheia(seg, u)
        novo, orfa, prova = estadia_da_noite(cfg, quarto, d, cal, ms_ef)
        estadia_info = False  # estadia abaixo da exigida por motivo conhecido: o item não regrava a estadia
        if novo is not None:
            # dono 04/11: a regra do dia de chegada decide a noite
            if novo > ms_ef and dia_orfao_pl:
                estadia_info = True
                info.append(f"estadia {ms_ef} < {novo}: dia órfão (noites vizinhas esgotadas)")
            elif novo > ms_ef:
                falta.append(f"estadia {ms_ef} < {novo}")
                item["min_stay"] = novo
            elif novo < ms_ef:
                falta.append(f"estadia {ms_ef} > {novo}" + (" (noite órfã)" if orfa else ""))
                item["min_stay"] = novo
                if orfa:
                    item.update(orfa=orfa, orfa_prova=prova)
            elif orfa and ex["min_stay"] and ms_ef < ex["min_stay"]:
                estadia_info = True
                info.append(f"estadia {ms_ef} < {ex['min_stay']}: noite órfã (noite seguinte esgotada), dono 04/11")
        elif ex["min_stay"] and ms_ef < ex["min_stay"]:
            # sábado, feriado e pacote (e as noites antes de 05/11): só sobe até a exigida, pela regra própria
            if dia_orfao_pl:
                estadia_info = True
                info.append(f"estadia {ms_ef} < {ex['min_stay']}: dia órfão (noites vizinhas esgotadas)")
            elif (ms_ef == 1 and d.weekday() == 5 and not ex["feriado"] and orf.get("ativa") and _cheia(ant, u)
                  and not _cheia(r, u) and ordem_valida(ordens or {}, orf.get("ordem_id"), quarto, d)):
                # dono 02/11 ("Pode fazer"): sexta esgotada com o sábado livre, o sábado órfão vende com estadia 1;
                # sem isto o plano de 12 meses devolvia a estadia 2 que a auditoria do Beds24 manda tirar (revisão 02/11)
                estadia_info = True
                info.append(f"estadia 1 < {ex['min_stay']}: sábado órfão (sexta esgotada), ordem {orf.get('ordem_id')}")
            elif so_estadia:
                # dono 04/11, implementação: o plano só de estadia nunca mexe em sábado, feriado e pacote; vai ao relatório
                falta.append(f"estadia {ms_ef} < {ex['min_stay']} (sábado, feriado ou pacote: fora do plano só de estadia)")
            else:
                falta.append(f"estadia {ms_ef} < {ex['min_stay']}")
                item["min_stay"] = ex["min_stay"]
        if falta and item:
            # estadia exigida sempre explícita no item com piso (a gravação junta campos; deixa a regra visível na data);
            # na noite órfã e no dia órfão com estadia 1 o item não leva estadia (antes levava a exigida e desfazia a órfã
            # quando o piso faltava)
            if ex["min_stay"] and "min_stay" not in item and not estadia_info:
                item["min_stay"] = max(ex["min_stay"], ms_ef)
            if regras is not None and d.isoformat() in regras and set(item) <= {"min_stay", "orfa", "orfa_prova"}:
                item["manter_reason"] = True
            item["preco_antes"] = float(r.get("price") or 0) or None
            out.append({"quarto": quarto, "data": d.isoformat(), "falta": falta, "item": item, "info": info,
                        "feriado": (ex["feriado"] or {}).get("nome"), "dso": r.get("dso_flag")})
        elif falta or info:
            out.append({"quarto": quarto, "data": d.isoformat(), "falta": falta, "info": info,
                        "feriado": (ex["feriado"] or {}).get("nome"), "dso": r.get("dso_flag")})
        d += UM_DIA
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

    def esgotada_b24(x) -> bool:
        return x is not None and x.get("numAvail") is not None and int(x["numAvail"]) <= 0

    for q in sorted(inv):
        chegada, estadia, outras, semana, orfa_estadia, por_data, dia_chegada = [], [], [], [], [], [], []
        for ds, v in sorted(inv[q].items()):
            d = dt.date.fromisoformat(ds)
            if not de <= d <= ate:
                continue
            o = (v or {}).get("override") or "none"
            ms = int((v or {}).get("minStay") or 1)
            lado_orfao = None
            # dono 03/11 (pacotes fechados): estadia mínima e máxima por data em todos os quartos. Os quartos do Beds24 usam
            # restrictionStrategy "stayThrough": a estadia vale para TODA noite coberta pela reserva, não só a de chegada
            mn_alvo = (rh.get("estadia_minima_por_data") or {}).get(ds)
            mx_alvo = (rh.get("estadia_maxima_por_data") or {}).get(ds)
            mx = int((v or {}).get("maxStay") or 365)
            if (mn_alvo is not None and ms != int(mn_alvo)) or (mx_alvo is not None and mx != int(mx_alvo)):
                it_e = {"data": ds, "motivo": rh.get("override_por_data_motivo") or "estadia por data do dono"}
                if mn_alvo is not None and ms != int(mn_alvo):
                    it_e.update({"min_stay": ms, "min_alvo": int(mn_alvo)})
                if mx_alvo is not None and mx != int(mx_alvo):
                    it_e.update({"max_stay": mx, "max_alvo": int(mx_alvo)})
                por_data.append(it_e)
            # dono 03/11 (pacotes fechados: Natal 23→25, Ano Novo 30→01 ou 30→03): chegada/saída por data em todos os
            # quartos, acima da regra do sábado e da sexta da Afrodite; a estadia máxima fica no Beds24 (maxStay)
            alvo_data = (rh.get("override_por_data") or {}).get(ds)
            if alvo_data:
                if o != alvo_data:
                    it = {"data": ds, "override": o, "troca": alvo_data,
                          "motivo": rh.get("override_por_data_motivo") or "chegada/saída por data do dono"}
                    if q == "Afrodite":
                        it["pricelabs_tambem"] = True
                    chegada.append(it)
                if d.weekday() == 5 and ms < ms_sab and mn_alvo is None:
                    estadia.append(ds)
                continue
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
                    # Beds24); feriado fica de fora (a estadia do feriado é do dono). Desde 05/11 a sexta órfã é a noite
                    # órfã geral da ordem de 04/11 (noite seguinte esgotada): vai em estadia_dia_chegada, com a sexta
                    if lado_orfao == "sexta" and not regra_dia_chegada(cfg, d - UM_DIA):
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
            if regra_dia_chegada(cfg, d):
                # dono 04/11 (desde 05/11): estadia pelo dia de chegada, de domingo a sexta (antes a sexta não era
                # auditada e segunda/quarta voltavam a 1 por `semana_estadia_acima`); temporada acima do dia vale; noite
                # órfã (livre no quarto com a seguinte esgotada nele) com 1; feriado e pacote ficam com a estadia própria
                # (estadia_planejada devolve None). Mesma decisão do plano do PriceLabs (comparar)
                livre = (v or {}).get("numAvail") is not None and int(v["numAvail"]) > 0
                prox = inv[q].get((d + UM_DIA).isoformat())
                novo, orfa_ = estadia_planejada(cfg, d, ms, livre, esgotada_b24(prox))
                # dia isolado entre noites esgotadas com 1: o PriceLabs manda 1 pela regra de dia órfão dele (o plano do
                # PriceLabs aceita como informação); sem isto o lote e o envio se desfariam a cada rotina
                isolado = ms == 1 and esgotada_b24(prox) and esgotada_b24(inv[q].get((d - UM_DIA).isoformat()))
                if novo is not None and novo != ms and not (novo > ms and isolado):
                    it_d = {"data": ds, "min_stay": ms, "alvo": novo}
                    if orfa_ and novo == 1:
                        it_d["orfa"] = orfa_
                    dia_chegada.append(it_d)
            # domingo a quinta fora de feriado: estadia do calendário de sazonalidade (dono, 01/11: "Sim"); substituída
            # pela regra do dia de chegada nas noites desde 05/11
            elif d.weekday() != 4 and saz is not None and not periodo_feriado(cfg, d):
                cal = int(((_periodo_saz(saz, d) or {}).get("estadia_minima")) or 1)
                if ms > cal:
                    semana.append({"data": ds, "min_stay": ms, "calendario": cal})
        out.append({"quarto": q, "sabados_fora_da_regra": chegada, "sabados_estadia_abaixo": estadia,
                    "outras_noites_bloqueadas": outras, "semana_estadia_acima": semana, "orfas_estadia": orfa_estadia,
                    "estadia_por_data": por_data, "estadia_dia_chegada": dia_chegada})
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
    # dono 04/11: estadia pelo dia de chegada e noite órfã; vai também ao PriceLabs por data (o envio dele leva a estadia
    # ao Beds24 e desfaria o lote)
    lote += lote_estadia_dia_chegada(classif)
    # dono 03/11 (pacotes fechados de Natal e Ano Novo): estadia mínima e máxima por data. A mínima vai também ao
    # PriceLabs por data (o envio dele leva a estadia mínima ao Beds24); a máxima só existe no Beds24
    for x in classif:
        for s in x.get("estadia_por_data", []):
            it = {"quarto": x["quarto"], "de": s["data"], "ate": s["data"], "motivo": s.get("motivo") or "estadia por data"}
            if "min_alvo" in s:
                it["min_stay"] = s["min_alvo"]
                it["pricelabs_tambem"] = True
            if "max_alvo" in s:
                it["max_stay"] = s["max_alvo"]
            lote.append(it)
    return lote


def lote_estadia_dia_chegada(classif: list[dict]) -> list[dict]:
    """Itens do `beds24.py editar-lote` só com min_stay (dono 04/11), um por noite que muda: nada de numAvail, override,
    estadia máxima ou preço. `pricelabs_tambem`: a mesma estadia vai ao PriceLabs por data (plano do PriceLabs)."""
    lote = []
    for x in classif:
        for s in x.get("estadia_dia_chegada", []):
            d = dt.date.fromisoformat(s["data"])
            motivo = ("Dono 04/11 (libera órfã): noite livre com a seguinte esgotada no quarto, estadia 1" if s.get("orfa")
                      else f"Dono 04/11: estadia pelo dia de chegada (chegada de {DIAS_SEMANA[d.weekday()]}: {s['alvo']})")
            lote.append({"quarto": x["quarto"], "de": s["data"], "ate": s["data"], "min_stay": s["alvo"],
                         "min_stay_antes": s["min_stay"], "pricelabs_tambem": True, "motivo": motivo})
    return lote


def lotes_estadia_por_pousada(cfg: dict, classif: list[dict]) -> dict[str, list[dict]]:
    """{pousada: lote só de estadia} (Recanto: Q7, Double, Afrodite, Q2; Villa: VK7, VK2, Balcony): um lote por
    propriedade do Beds24."""
    out = {p: [] for p in sorted({q["pousada"] for q in cfg["quartos"]})}
    for it in lote_estadia_dia_chegada(classif):
        out[cfg["por_curto"][it["quarto"]]["pousada"]].append(it)
    return out


def auditar_beds24(cfg: dict, de: dt.date, ate: dt.date, token=None) -> list[dict]:
    import beds24
    # a sexta anterior ao primeiro dia entra na leitura: o sábado órfão (dono 02/11) depende dela; e a noite seguinte ao
    # último dia também: a noite órfã (dono 04/11) depende dela
    inv = beds24.inventario(token or beds24.token_de_acesso(), (de - UM_DIA).isoformat(), (ate + UM_DIA).isoformat())
    return classificar_beds24(cfg, inv, de, ate)


def montar_plano(diferencas: list[dict], hoje: dt.date) -> dict:
    itens = []
    for x in diferencas:
        if not x.get("item"):
            continue
        partes = []
        d_ = dt.date.fromisoformat(x["data"])
        estadia_mudou = "min_stay" in x["item"] and any(f.startswith("estadia") for f in x["falta"])
        if "min_price" in x["item"]:
            partes.append(f"piso feriado {x['feriado'].split(' (')[0]}" if x.get("feriado") else "piso dono 500/850 final")
        if estadia_mudou:
            if x.get("feriado"):
                tipo = " feriado"
            elif x["item"].get("orfa") == "seguinte":
                tipo = " noite órfã (dono 04/11)"
            elif d_.weekday() == 5:
                tipo = " sábado"
            else:
                tipo = f" chegada de {DIAS_SEMANA[d_.weekday()]} (dono 04/11)"
            partes.append(f"estadia {x['item']['min_stay']}n" + tipo)
        motivo = "12m 21/10: " + "; ".join(partes)
        if estadia_mudou and "min_price" not in x["item"] and not x.get("feriado") and d_.weekday() != 5:
            # item só de estadia pela ordem de 04/11: o motivo vira o reason da regra por data nova (sem regra na data);
            # com regra na data o payload sai sem reason (manter_reason)
            motivo = (f"Estadia por dia de chegada (dono 04/11): chegada de {DIAS_SEMANA[d_.weekday()]}, "
                      f"{x['item']['min_stay']} noite(s)" +
                      ("; noite órfã (noite seguinte esgotada)" if x["item"].get("orfa") == "seguinte" else ""))
        it = {"tipo": "data", "quarto": x["quarto"], "data": x["data"], **x["item"], "motivo": motivo}
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
    ap.add_argument("--so-estadia", action="store_true",
                    help="só a estadia mínima (dono 04/11): o plano do PriceLabs sai sem piso (o piso de 12 meses não vê o "
                         "gatilho/degrau das ordens de faixa) e sem mexer em sábado, feriado e pacote")
    ap.add_argument("--regras", nargs="*", default=None,
                    help="QUARTO=get_listing_date_overrides.json: o item só de estadia numa data que já tem regra por data "
                         "leva manter_reason (o payload sai sem reason e o da regra fica)")
    ap.add_argument("--inventario", help="inventário do Beds24 já lido (beds24.py inventario --json), no lugar da leitura "
                                         "ao vivo; cobre de --de − 1 a --ate + 1 (sem a noite seguinte ao último dia, "
                                         "a órfã dele não se vê)")
    ap.add_argument("--lotes-estadia", metavar="PREFIXO",
                    help="grava PREFIXO_recanto.json e PREFIXO_villa.json: lotes do beds24.py editar-lote só com min_stay "
                         "(dono 04/11), um por pousada, só nas noites que mudam")
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
    regras = ler_regras_por_data(a.regras) if a.regras else {}
    sem_regras = [x.split("=", 1)[0] for x in a.recalculos if a.regras and x.split("=", 1)[0] not in regras]
    if sem_regras:
        # sem as regras do quarto o item iria com reason e apagaria o da regra da data (topo_mercado lê a Regra J nele)
        print(f"ERRO: --regras sem o arquivo de {', '.join(sem_regras)}: passe as regras por data de todos os quartos")
        return 1
    for arg in a.recalculos:
        quarto, caminho = arg.split("=", 1)
        dif += comparar(cfg, quarto, ler_recalculo(caminho), de, ate, ordens, so_estadia=a.so_estadia,
                        regras=regras.get(quarto) if a.regras else None)
    infos = [x for x in dif if not x["falta"]]
    dif = [x for x in dif if x["falta"]]
    resumo = {}
    for x in infos:
        for f in x["info"]:
            tipo = next((t for t in ("noite órfã", "dia órfão", "sábado órfão") if t in f), "ordem")
            chave = (x["quarto"], "info:" + tipo)
            resumo[chave] = resumo.get(chave, 0) + 1
    for x in dif:
        for f in x["falta"]:
            tipo = f.split(" ")[0]
            if tipo == "estadia" and "item" in x and "min_stay" in x["item"]:
                tipo += " sobe" if "<" in f else " desce"
            chave = (x["quarto"], tipo)
            resumo[chave] = resumo.get(chave, 0) + 1
    for (quarto, tipo), n in sorted(resumo.items()):
        print(f"{quarto:9} {tipo:18} {n} noites")
    print(f"{len(dif)} noites fora da regra, de {de} a {ate}")
    if a.modo == "plano":
        plano = montar_plano(dif, hoje)
        json.dump(plano, open(a.saida, "w"), ensure_ascii=False, indent=1)
        print(f"{len(plano['itens'])} itens em {a.saida}")
    b24 = []
    if not a.sem_beds24:
        # Beds24 desde hoje (revisão 02/11): o lado órfão do sábado muda com reserva e cancelamento de D0 a D3 também;
        # o corte em hoje + 4 vale só para os pisos e estadias do PriceLabs
        de_b24 = dt.date.fromisoformat(a.de) if a.de else hoje
        if a.inventario:
            b24 = classificar_beds24(cfg, json.load(open(a.inventario, encoding="utf-8")), de_b24, ate)
        else:
            b24 = auditar_beds24(cfg, de_b24, ate)
        for x in b24:
            ch, es, ou = x["sabados_fora_da_regra"], x["sabados_estadia_abaixo"], x["outras_noites_bloqueadas"]
            se = x.get("semana_estadia_acima") or []
            oe = x.get("orfas_estadia") or []
            ed = x.get("estadia_dia_chegada") or []
            print(f"Beds24 {x['quarto']:9} sábados com chegada fechada: {len(ch)}" +
                  (f" (de {ch[0]['data']} a {ch[-1]['data']})" if ch else "") +
                  (f"; dom-qui com estadia acima do calendário: {len(se)} ({se[0]['data']} a {se[-1]['data']})" if se else "") +
                  (f"; estadia fora do dia de chegada (dono 04/11): {len(ed)} ({sum(1 for e in ed if e['alvo'] > e['min_stay'])} "
                   f"sobem, {sum(1 for e in ed if e['alvo'] < e['min_stay'])} descem, "
                   f"{sum(1 for e in ed if e.get('orfa'))} órfãs)" if ed else "") +
                  (f"; sábados com estadia abaixo de 2: {', '.join(es[:5])}" if es else "") +
                  (f"; outras noites com restrição: {len(ou)} ({', '.join(ou[:3])}...)" if ou else "") +
                  (f"; noites órfãs com estadia acima de 1 (PriceLabs por data, ordem dono-2026-11-02-sabado-orfao): "
                   f"{', '.join(o['data'] for o in oe)}" if oe else ""))
            for s_ in ch:
                if s_.get("pricelabs_tambem"):
                    print(f"  PriceLabs também ({x['quarto']}): {s_['data']} -> {s_['troca']} (check_in/check_out por data)")
            for s_ in x.get("estadia_por_data") or []:
                print(f"  estadia por data do dono ({x['quarto']} {s_['data']}): " +
                      ", ".join(f"{k} {s_[k]} -> {s_[a_]}" for k, a_ in (("min_stay", "min_alvo"), ("max_stay", "max_alvo"))
                                if a_ in s_) + (" (mínima também no PriceLabs por data)" if "min_alvo" in s_ else ""))
        if a.plano_beds24:
            if a.so_estadia:
                lote = lote_estadia_dia_chegada(b24)
            else:
                lote = plano_beds24_sabados(b24, "Dono 02/11: sábado fechado para entrada e saída, sempre "
                                                 "(noCheckInOrCheckOut); exceções do dono de 02/11 vão com o motivo próprio",
                                            "Regra A no Beds24 (dono 01/11, 'Sim'): domingo a quinta fora de feriado com a "
                                            "estadia do calendário de sazonalidade")
            json.dump(lote, open(a.plano_beds24, "w"), ensure_ascii=False, indent=1)
            print(f"{len(lote)} itens no lote do Beds24 em {a.plano_beds24}")
        if a.lotes_estadia:
            for pousada, lote in lotes_estadia_por_pousada(cfg, b24).items():
                arq = f"{a.lotes_estadia}_{pousada.lower()}.json"
                json.dump(lote, open(arq, "w"), ensure_ascii=False, indent=1)
                print(f"{len(lote)} noites só de estadia no lote do Beds24 ({pousada}) em {arq}")
    if a.json:
        json.dump({"pricelabs": dif, "info": infos, "beds24": b24}, open(a.json, "w"), ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

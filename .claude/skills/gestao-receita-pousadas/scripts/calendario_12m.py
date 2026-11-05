"""Calendário de 12 meses (dono, 21/10: "Vamos rodar o plano agora e já atualizar tudo para os próximos 12 meses e manter
tudo em monitoramento. Inclusive restrições de permissão de check-in e check-outs, mínimo de dias da estadia.").

Uso:
  python3 calendario_12m.py plano --hoje AAAA-MM-DD [--de AAAA-MM-DD] [--ate AAAA-MM-DD] Q7=<recalculo> ... --saida plano.json
  python3 calendario_12m.py auditar --hoje AAAA-MM-DD [--ate AAAA-MM-DD] Q7=<recalculo> ... [--json saida.json]
  Estadia pelo dia de chegada (dono 04/11), só estadia, com as regras por data (12 meses), a estadia efetiva e o
  inventário já lidos (revisão de 05/11: é assim que a rotina completa roda; a rotina leve, com o inventário lido na hora):
  python3 calendario_12m.py plano --so-estadia --hoje AAAA-MM-DD --de AAAA-MM-DD --ate AAAA-MM-DD Q7=<recalculo> ...
      --regras Q7=<get_listing_date_overrides> ... --efetiva Q7=<get_listing_prices> ...
      --inventario inv_b24.json [--inventario-lido-em ISO] --lotes-estadia <pasta>/lote_b24 --saida plano.json
  e grave em fatias: python3 validar_plano.py plano.json --fatias <pasta> (até 70 datas por quarto; D0 e D1 à parte) e
  beds24.py editar-lote --plano <pasta>/lote_b24_<pousada>_urgente.json --aplicar, depois os lotes por quarto.

Lê o recálculo do PriceLabs (refresh_listing_pricing: piso, estadia e chegada/saída efetivos de cada noite) e compara
cada noite com o que as regras da skill exigem. O piso só sobe; a estadia sobe e, pela ordem de 04/11, também desce:
  - piso das 6 suítes com banheira: domingo a quinta R$ 500 e sexta e sábado R$ 850 no preço final do Genius 3
    (limites_seguranca ÷ fator de vitrine), nunca abaixo do mínimo do quarto;
  - feriados de quartos.json: piso de feriado (piso_real ÷ fator_real, ou fator de vitrine na Balcony; sem valor,
    o de segurança de R$ 800), nas suítes nunca abaixo de R$ 1.000 enviados (os pisos de R$ 500/850 são fora de feriado);
  - estadia mínima (validar_plano.estadia_exigida/estadia_planejada): desde as noites de 05/11 pelo dia de chegada (dono
    04/11: domingo, terça e quinta 1; segunda, quarta e sexta 2; temporada acima do dia vale, sem o período que é o
    próprio feriado), subindo ao alvo e descendo a 1 no domingo, na terça e na quinta fora de temporada e na noite órfã
    (livre no quarto com a seguinte esgotada nele: item com `orfa` e `orfa_prova`); sábado 2 (Regra H), feriado pela
    lista de feriados ou pelo calendário e pacote pela estadia por data só sobem (com --so-estadia, nem isso: vão como
    falta sem item). Dono 05/11 ("As noites órfãs do feriado, a gente pode abrir"; `orfa_em_feriado`): em feriado e
    véspera, fora de pacote e sábado, a noite órfã desce a 1, também com --so-estadia e no lote do Beds24; quando a
    seguinte reabre, a estadia do feriado volta pelo plano completo (sem --so-estadia), como toda estadia de feriado
    abaixo da exigida; o dia isolado de feriado com 1 é informação. Revisão de 05/11: a estadia
    de hoje é a efetiva do PriceLabs (--efetiva, get_listing_prices; o
    recálculo só sem ela); a noite órfã, o dia isolado e o sábado órfão saem do inventário do Beds24 quando houver
    (--inventario ou a leitura ao vivo: a MESMA fonte do lote do Beds24, prova com fonte "beds24" e lido_em), e o
    recálculo só sem ele; com --regras, toda segunda, quarta e sexta (e a temporada com 2) sem min_stay na regra por
    data ganha a estadia explícita (o padrão do PriceLabs muda sozinho); noite aguardando o dono
    (`estadia_por_dia_chegada.aguardando_dono`, nenhuma desde 05/11) nem sobe nem desce;
  - sexta da Afrodite (dono 05/11: "Isso, pode abrir Afrodite, saída de sexta";
    `regra_h_beds24.sexta_reaberta_por_quarto`): entrada e saída abertas, fora de pacote. No Beds24 o noCheckOut antigo volta a `none` (item com `pricelabs_tambem`);
    no PriceLabs por data (com --regras), o check_out que fecha a sexta (5º caractere 0) ganha item com a sexta em 1, o
    resto da máscara e o check_in mantidos;
  - chegada e saída no sábado (dono 02/11: "mantenha todos os sábados fechados sempre, tanto para a entrada quanto para
    a saída"): ficam no Beds24, todo sábado `parametros.regra_h_beds24.sabado_override` (noCheckInOrCheckOut), inclusive
    em feriado. Exceções do dono de 02/11 ("Pode fazer"): os sábados de `regra_h_beds24.sabados_excecao` (26/12 e 02/01,
    entrada e saída abertas) e o lado órfão do fim de semana (`regra_h_beds24.orfas`: sábado esgotado com a sexta livre
    fica noCheckIn; sexta esgotada com o sábado livre fica noCheckOut, só naquele quarto; a noite órfã com estadia acima
    de 1 sai em `orfas_estadia`, para o PriceLabs por data; em feriado também desde 05/11, `orfas.em_feriado`, nunca em
    pacote nem no sábado de festa). O script lê o Beds24 e lista os sábados fora da regra, os sábados com estadia 1, as noites de
    domingo a quinta fora de feriado com estadia acima do calendário de sazonalidade (dono 01/11: "Sim"; substituído
    nas noites desde 05/11 por `estadia_dia_chegada`, a mesma decisão do plano do PriceLabs, de domingo a sexta) e as
    outras noites com restrição; `--plano-beds24` gera o lote do `beds24.py editar-lote` que fecha o sábado e acerta a
    estadia; `--lotes-estadia` grava os lotes só de estadia: por pousada, um urgente (de hoje a hoje + 14) e os demais
    por quarto (beds24.editar_lote confere o min_stay_antes de cada item e grava em blocos de até 100 faixas).
  - ordens do dono (`dados/ordens_dono.json`, piso_por_data e alvo_por_data): piso abaixo do padrão numa noite coberta
    por ordem, e não abaixo do piso da ordem, sai como informação, não como falta. Dia órfão (a própria noite livre e as
    vizinhas esgotadas) com estadia 1 também (regra de dia órfão do PriceLabs, aprendizado de 08/10).
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
from comum import (DIAS_SEMANA, abrir_dia, aguardando_dono, carregar_quartos, estadia_do_dia,  # noqa: E402
                   mascara_valida, orfa_permitida, periodo_feriado, regra_dia_chegada, sexta_reaberta, vendidas_total)
from ordens import carregar_ordens, ordem_valida, piso_da_ordem  # noqa: E402
from validar_plano import estadia_exigida, estadia_planejada, periodo_temporada, regra_do_dia_decide  # noqa: E402

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


# campos de uma regra por data que não são de estadia: o item só de estadia mantém o reason dela (topo_mercado lê a Regra
# J nele; a escada e as faixas explicam o preço nele); nas regras só de estadia o reason novo entra (revisão 05/11)
CAMPOS_PRECO_REGRA = ("price", "min_price", "max_price", "base_price", "check_in", "check_out")


def regra_tem_preco(reg: dict | None) -> bool:
    """A regra por data vigente tem preço, piso, teto, base ou chegada/saída, ou reason da Regra J."""
    if not reg:
        return False
    if any(reg.get(k) not in (None, "") for k in CAMPOS_PRECO_REGRA):
        return True
    return "regra j" in str(reg.get("reason") or "").lower()


def regra_tem_preco_sem_mascara(reg: dict | None) -> bool:
    """Como regra_tem_preco, sem contar a chegada/saída: preço, piso, teto, base ou reason da Regra J."""
    if not reg:
        return False
    if any(reg.get(k) not in (None, "") for k in CAMPOS_PRECO_REGRA if k not in ("check_in", "check_out")):
        return True
    return "regra j" in str(reg.get("reason") or "").lower()


def _lista_com_datas(j):
    """Primeira lista de noites ({date, min_stay, ...}) dentro da resposta do get_listing_prices, e o id do anúncio."""
    if isinstance(j, list):
        if j and isinstance(j[0], dict) and "date" in j[0]:
            return j, None
        for x in j:
            lst, lid = _lista_com_datas(x)
            if lst is not None:
                return lst, (x.get("id") if isinstance(x, dict) and lid is None else lid)
        return None, None
    if isinstance(j, dict):
        for k in ("data", "result"):
            if k in j:
                lst, lid = _lista_com_datas(j[k])
                if lst is not None:
                    return lst, lid if lid is not None else j.get("id")
    return None, None


def ler_efetiva(caminho: str) -> tuple[dict, str | None]:
    """({data: estadia mínima efetiva}, id do anúncio) do get_listing_prices do PriceLabs (o que vale hoje na noite,
    com a regra por data, o padrão "PriceLabs Recommended" e o dia órfão dele). Revisão de 05/11: o recálculo
    (refresh_listing_pricing) mostrou 1 na Balcony 04/02/2027 com a efetiva 3; o plano decidia pelo recálculo e não descia."""
    t = open(caminho, encoding="utf-8").read()
    j = json.loads(t[min(i for i in (t.find("{"), t.find("[")) if i >= 0):])
    if isinstance(j, dict) and (j.get("error") or j.get("error_status") or
                                (isinstance(j.get("data"), dict) and (j["data"].get("error") or j["data"].get("error_status")))):
        raise SystemExit(f"ERRO: {caminho}: o get_listing_prices voltou com erro ({str(j)[:200]}); leia de novo ou "
                         "rode sem --efetiva para esse quarto")
    lst, lid = _lista_com_datas(j)
    if not lst:
        raise SystemExit(f"ERRO: {caminho}: nenhuma noite no get_listing_prices (verificação de 05/11: LISTING_NO_DATA)")
    out = {}
    for x in lst or []:
        if x.get("date") and x.get("min_stay") not in (None, "", -1, "-1"):
            out[str(x["date"])[:10]] = int(float(x["min_stay"]))
    return out, lid


def ler_inventario(caminho: str, lido_em: str | None = None) -> tuple[dict, str]:
    """(inventário {curto: {data: {numAvail, minStay, ...}}}, instante da leitura em ISO UTC) do `beds24.py inventario
    --json`. O instante vem de `lido_em`, senão da chave "_lido_em" do arquivo, senão da hora em que o arquivo foi
    gravado: vai na prova da noite órfã (`orfa_prova.lido_em`), que o validador aceita com a leitura de hoje ou ontem."""
    inv = json.load(open(caminho, encoding="utf-8"))
    meta = inv.pop("_lido_em", None) if isinstance(inv, dict) else None
    inv = {k: v for k, v in inv.items() if not str(k).startswith("_")}
    if not lido_em:
        lido_em = meta or dt.datetime.fromtimestamp(os.path.getmtime(caminho), dt.timezone.utc).isoformat(timespec="seconds")
    return inv, lido_em


class Ocupacao:
    """Noite livre e esgotada de UM quarto pela fonte única (revisão de 05/11, dono 04/11 "libera órfã"): com o
    inventário do Beds24 (o que vende de fato), numAvail > 0 é livre e numAvail <= 0 é esgotada (-1 = overbooking), e
    noite fora do inventário não é nem uma nem outra; sem inventário, o recálculo do PriceLabs (mu_occupancy, ou
    num_bookings na Afrodite). O plano do PriceLabs (comparar) e o lote do Beds24 (classificar_beds24) decidem a noite
    órfã, o dia isolado e o sábado órfão por aqui, para não um subir o que o outro desce."""

    def __init__(self, cal: dict | None, unidades: int, inv: dict | None = None, lido_em: str | None = None):
        self.cal, self.u, self.inv, self.lido_em = cal or {}, int(unidades or 1), inv, lido_em

    def _av(self, d: dt.date):
        v = (self.inv or {}).get(d.isoformat())
        return None if v is None or v.get("numAvail") is None else int(v["numAvail"])

    def livre(self, d: dt.date) -> bool:
        if self.inv is not None:
            a = self._av(d)
            return a is not None and a > 0
        r = self.cal.get(d.isoformat())
        return bool(r) and not _cheia(r, self.u)

    def esgotada(self, d: dt.date) -> bool:
        if self.inv is not None:
            a = self._av(d)
            return a is not None and a <= 0
        return _cheia(self.cal.get(d.isoformat()), self.u)

    def prova(self, d: dt.date, vizinha: str = "seguinte") -> dict:
        """`orfa_prova` da noite `d` com a vizinha esgotada ("seguinte" ou "anterior"), na forma do validador."""
        dv = d + UM_DIA if vizinha == "seguinte" else d - UM_DIA
        if self.inv is not None:
            return {"fonte": "beds24", "unidades": self.u, "noite_numAvail": self._av(d),
                    f"{vizinha}_numAvail": self._av(dv), "lido_em": self.lido_em}
        (nv, nt), (sv, st) = (vendidas_total(self.cal.get(x.isoformat()) or {}, self.u) for x in (d, dv))
        return {"noite_vendidas": nv, "noite_total": nt, f"{vizinha}_vendidas": sv, f"{vizinha}_total": st}


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


def estadia_da_noite(cfg: dict, quarto: str, d: dt.date, cal: dict, atual: int | None = None,
                     inv: dict | None = None, lido_em: str | None = None) -> tuple[int | None, str | None, dict | None]:
    """(estadia planejada, 'seguinte' na noite órfã, prova da ocupação) da noite pela ordem de 04/11
    (validar_plano.estadia_planejada; em feriado e véspera, só a noite órfã, dono 05/11); (None, None, None) onde nada
    decide (pacote, sábado, antes de 05/11, noite aguardando o dono). A ocupação vem do inventário do Beds24 do quarto
    (`inv`) quando houver, senão do
    recálculo (`cal`): a prova vai no item da noite órfã (`orfa_prova`) para o validador conferir."""
    oc = Ocupacao(cal, int(cfg["por_curto"][quarto].get("unidades") or 1), inv, lido_em)
    novo, orfa = estadia_planejada(cfg, d, atual, livre=oc.livre(d), seguinte_esgotada=oc.esgotada(d + UM_DIA),
                                   quarto=quarto)
    return novo, orfa, (oc.prova(d, "seguinte") if orfa else None)


def prova_sabado_orfao(cfg: dict, quarto: str, d: dt.date, cal: dict | None, inv: dict | None = None,
                       lido_em: str | None = None) -> dict | None:
    """`orfa_prova` do sábado órfão (dono 02/11, `regra_h_beds24.orfas`: sexta esgotada no quarto e o sábado livre, com a
    regra ligada; em feriado também desde 05/11, `orfas.em_feriado`; nunca em pacote nem no sábado de festa,
    `comum.orfa_permitida`), com a sexta como vizinha "anterior"; None quando não é. Pela fonte única (`Ocupacao`): o
    inventário do Beds24 quando houver, senão o recálculo."""
    orf = ((cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("orfas") or {}
    if d.weekday() != 5 or not orf.get("ativa") or not orfa_permitida(cfg, d, sabado=True):
        return None
    oc = Ocupacao(cal, int(cfg["por_curto"][quarto].get("unidades") or 1), inv, lido_em)
    if oc.livre(d) and oc.esgotada(d - UM_DIA):
        return oc.prova(d, "anterior")
    return None


def nome_temporada(p: dict | None) -> str | None:
    """Nome curto do período de sazonalidade para o motivo ("Pico de julho 2027", "XXII Congresso Brasileiro do Quadril –
    CBQ 2027")."""
    if not p:
        return None
    nome = str(p.get("nome") or "temporada")
    for sep in (" (", ":"):
        nome = nome.split(sep)[0]
    return nome.strip()[:80]


def comparar(cfg: dict, quarto: str, cal: dict, de: dt.date, ate: dt.date, ordens: dict | None = None,
             so_estadia: bool = False, regras: dict | None = None, efetiva: dict | None = None,
             inv: dict | None = None, lido_em: str | None = None) -> list[dict]:
    """Noites fora da regra (`falta`, com o `item` que corrige) e noites fora do padrão por motivo conhecido (`info`:
    ordem do dono, dia órfão, noite órfã, noite aguardando o dono), sem item. Estadia (dono 04/11, desde 05/11): onde a
    regra do dia de chegada decide, sobe ao alvo e desce a 1 (domingo, terça e quinta fora de temporada, e a noite
    órfã); sábado, feriado e pacote só sobem até a exigida, como antes. Feriado e véspera (dono 05/11, `orfa_em_feriado`,
    fora de pacote e sábado): a noite órfã desce a 1, também com `so_estadia`. `so_estadia`: sem piso, e
    sábado/feriado/pacote abaixo da exigida vão como falta sem item (o plano só de estadia não mexe neles; a órfã de
    feriado cuja seguinte reabriu volta à estadia do feriado pelo plano completo). Sexta da Afrodite (dono 05/11,
    `sexta_reaberta_por_quarto`): com `regras` (ou, sem elas, a máscara do recálculo), o check_out por data que fecha a
    sexta ganha item com a sexta em 1, o resto da máscara e o check_in mantidos (também com `so_estadia`: é restrição,
    não piso).
    Revisão de 05/11:
      - `efetiva` {data: estadia efetiva do get_listing_prices} decide subir/descer (o recálculo só sem ela);
      - `inv` (inventário do Beds24 do quarto) é a fonte única da noite livre/esgotada (noite órfã, dia isolado, sábado
        órfão), a mesma do lote do Beds24; a prova da órfã sai dele (`orfa_prova` com fonte "beds24" e `lido_em`);
      - `regras` {data: regra por data vigente}: onde o alvo é 2 ou mais (segunda, quarta e sexta; temporada) e a regra
        da data não tem min_stay, o item grava a estadia explícita (o padrão "PriceLabs Recommended" muda sozinho e o
        envio leva ao Beds24); no domingo, na terça e na quinta, só onde a efetiva difere. O item só de estadia leva
        `manter_reason` só quando a regra da data tem preço, piso, teto, base ou chegada/saída, ou reason da Regra J
        (nas regras só de estadia o reason novo entra);
      - a noite aguardando o dono (`estadia_por_dia_chegada.aguardando_dono`; nenhuma desde 05/11) nem sobe nem desce."""
    q = cfg["por_curto"][quarto]
    u = int(q.get("unidades") or 1)
    orf = ((cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("orfas") or {}
    oc = Ocupacao(cal, u, inv, lido_em)
    out = []
    d = de
    while d <= ate:
        ds = d.isoformat()
        r = cal.get(ds)
        if r is None:
            out.append({"quarto": quarto, "data": ds, "falta": ["sem dado de preço no recálculo"]})
            d += UM_DIA
            continue
        ex = exigencias(cfg, q, d)
        falta, info, item = [], [], {}
        tipo_est, temporada = None, None
        piso_ef = float(r.get("min_price") or 0)
        if not so_estadia and ex["piso"] and piso_ef < ex["piso"] - 1:
            po = piso_da_ordem(ordens or {}, quarto, d)
            if po and piso_ef >= po[0] - 1:
                info.append(f"piso {piso_ef:.0f} < {ex['piso']} pela ordem {po[1].get('id')} (piso {po[0]:.0f})")
            else:
                falta.append(f"piso {piso_ef:.0f} < {ex['piso']}")
                item["min_price"] = ex["piso"]
        ms_ef = int(efetiva[ds]) if efetiva and ds in efetiva else int(float(r.get("min_stay") or 1))
        livre, seg_esg = oc.livre(d), oc.esgotada(d + UM_DIA)
        # dia isolado (o "orphan days" do PriceLabs põe 1): a própria noite livre entre duas esgotadas (revisão 05/11:
        # sem exigir a noite livre, três noites esgotadas seguidas contavam como dia órfão)
        dia_orfao_pl = ms_ef == 1 and livre and oc.esgotada(d - UM_DIA) and seg_esg
        novo, orfa = estadia_planejada(cfg, d, ms_ef, livre, seg_esg, quarto=quarto)
        prova = oc.prova(d, "seguinte") if orfa else None
        espera = aguardando_dono(cfg, quarto, d) if regra_do_dia_decide(cfg, d, ex["feriado"]) else None
        reg = regras.get(ds) if regras is not None else None
        reg_ms = None
        if reg and reg.get("min_stay") not in (None, ""):
            try:
                reg_ms = int(float(reg["min_stay"]))
            except (TypeError, ValueError):
                reg_ms = None
        estadia_info = False  # estadia abaixo da exigida por motivo conhecido: o item não regrava a estadia
        if espera:
            # revisão de 05/11: até o dono decidir, nem sobe nem desce (nem a estadia explícita do item com piso)
            estadia_info = True
            info.append(f"estadia {ms_ef}: aguardando o dono ({espera[:80]})")
        elif novo is not None:
            # dono 04/11: a regra do dia de chegada decide a noite
            if novo > ms_ef and dia_orfao_pl:
                estadia_info = True
                info.append(f"estadia {ms_ef} < {novo}: dia órfão (noites vizinhas esgotadas)")
            elif novo > ms_ef:
                falta.append(f"estadia {ms_ef} < {novo}")
                item["min_stay"], tipo_est = novo, "sobe"
            elif novo < ms_ef:
                falta.append(f"estadia {ms_ef} > {novo}" + (" (noite órfã)" if orfa else ""))
                item["min_stay"], tipo_est = novo, ("órfã" if orfa else "desce")
                if orfa:
                    item.update(orfa=orfa, orfa_prova=prova)
            elif orfa and ex["min_stay"] and ms_ef < ex["min_stay"]:
                estadia_info = True
                # dono 05/11: o dia isolado (dia órfão, 1 entre noites esgotadas) do feriado também é informação aceita
                info.append(f"estadia {ms_ef} < {ex['min_stay']}: noite órfã (noite seguinte esgotada), dono 04/11"
                            + ("; dia órfão (noites vizinhas esgotadas)" if dia_orfao_pl else "")
                            + (f"; feriado ({ex['feriado']['nome']}), dono 05/11" if ex["feriado"] else ""))
            elif regras is not None and not orfa and ex["min_stay"] and ex["min_stay"] >= 2 and \
                    (reg_ms is None or reg_ms < ex["min_stay"]):
                # revisão de 05/11: segunda, quarta e sexta (e a temporada) com a estadia explícita na regra por data,
                # mesmo com a efetiva já certa: o padrão do PriceLabs muda sozinho e o envio leva ao Beds24. Domingo,
                # terça e quinta (alvo 1) não ganham regra nova só para 1. Acima do alvo sem regra por data é o padrão do
                # PriceLabs, não decisão: vai ao alvo ("no mínimo 2")
                # verificação de 05/11: efetiva acima do alvo de 2 fica (como estadia_planejada e o lote do Beds24)
                alvo_exp = max(int(ex["min_stay"]), int(ms_ef or 0))
                falta.append(f"estadia {ms_ef} sem min_stay na regra por data: explícita {alvo_exp}")
                item["min_stay"], tipo_est = alvo_exp, "explícita"
            if "min_stay" in item:
                p_t = periodo_temporada(cfg, d)
                if p_t and int(p_t["estadia_minima"]) > int(estadia_do_dia(cfg, d) or 1) and \
                        item["min_stay"] == int(p_t["estadia_minima"]):
                    temporada = nome_temporada(p_t)
        elif ex["min_stay"] and ms_ef < ex["min_stay"]:
            # sábado, feriado e pacote (e as noites antes de 05/11): só sobe até a exigida, pela regra própria
            if dia_orfao_pl:
                estadia_info = True
                info.append(f"estadia {ms_ef} < {ex['min_stay']}: dia órfão (noites vizinhas esgotadas)")
            elif (ms_ef == 1 and d.weekday() == 5 and orfa_permitida(cfg, d, sabado=True) and orf.get("ativa")
                  and oc.esgotada(d - UM_DIA) and livre and ordem_valida(ordens or {}, orf.get("ordem_id"), quarto, d)):
                # dono 02/11 ("Pode fazer"): sexta esgotada com o sábado livre, o sábado órfão vende com estadia 1;
                # sem isto o plano de 12 meses devolvia a estadia 2 que a auditoria do Beds24 manda tirar (revisão 02/11).
                # Dono 05/11: também em feriado (orfas.em_feriado), nunca em pacote nem no sábado de festa
                estadia_info = True
                info.append(f"estadia 1 < {ex['min_stay']}: sábado órfão (sexta esgotada), ordem {orf.get('ordem_id')}"
                            + (f"; feriado ({ex['feriado']['nome']}), dono 05/11" if ex["feriado"] else ""))
            elif so_estadia:
                # dono 04/11, implementação: o plano só de estadia nunca mexe em sábado, feriado e pacote; vai ao relatório
                falta.append(f"estadia {ms_ef} < {ex['min_stay']} (sábado, feriado ou pacote: fora do plano só de estadia)")
            else:
                falta.append(f"estadia {ms_ef} < {ex['min_stay']}")
                item["min_stay"], tipo_est = ex["min_stay"], "sobe"
        # dono 05/11 ("Isso, pode abrir Afrodite, saída de sexta"): na sexta do quarto cujo override de sexta o dono tirou
        # (`sexta_reaberta_por_quarto`; fora de pacote), o check_out por data que fecha a sexta (5º caractere 0) abre;
        # o resto da máscara e o check_in da regra ficam (o payload manda os dois). Com --regras a máscara é a da regra por
        # data; sem elas, a do recálculo quando vier como máscara
        if sexta_reaberta(cfg, quarto, d):
            # revisão de 05/11: a máscara é a da regra por data quando ela tem; sem máscara na regra (ou sem --regras), a
            # efetiva do recálculo, que traz o padrão do anúncio (Afrodite: 1111001, sexta e sábado fechados). A ordem do
            # dono é entrada e saída abertas na sexta: os dois campos abrem o dia
            fonte = reg if (regras is not None and mascara_valida(str((reg or {}).get("check_out") or ""))) else r
            co = str((fonte or {}).get("check_out") or "")
            ci = str((fonte or {}).get("check_in") or "")
            ligada = str((fonte or {}).get("check_in_check_out_enabled", "1")).strip().lower() not in ("0", "false")
            fecha = [m for m in (co, ci) if mascara_valida(m) and m[d.weekday()] == "0"]
            if fecha and ligada:
                item["check_out"] = abrir_dia(co, d) if mascara_valida(co) else "1111111"
                item["check_in"] = abrir_dia(ci, d) if mascara_valida(ci) else "1111111"
                falta.append(f"saída de sexta fechada no PriceLabs (check_in {ci or '-'}, check_out {co or '-'}): dono 05/11 abriu "
                              "entrada e saída")
        if falta and item:
            # estadia exigida sempre explícita no item com piso (a gravação junta campos; deixa a regra visível na data);
            # na noite órfã e no dia órfão com estadia 1 o item não leva estadia (antes levava a exigida e desfazia a órfã
            # quando o piso faltava). Item só de chegada/saída (sexta da Afrodite, 05/11) não ganha estadia
            if ex["min_stay"] and "min_stay" not in item and "min_price" in item and not estadia_info:
                item["min_stay"] = max(ex["min_stay"], ms_ef)
            # item sem preço, piso nem teto numa data cuja regra tem preço (ou chegada/saída, ou Regra J): o reason dela fica
            # revisão de 05/11: item que muda a chegada/saída numa regra só de chegada/saída leva o reason novo (o antigo
            # dizia "saída de sexta segue fechada"); sem --regras, o item só de chegada/saída mantém o reason da data
            muda_mascara = "check_in" in item or "check_out" in item
            if regras is not None and ds in regras and \
                    set(item) <= {"min_stay", "orfa", "orfa_prova", "check_in", "check_out"} and \
                    (regra_tem_preco_sem_mascara(regras[ds]) if muda_mascara else regra_tem_preco(regras[ds])):
                item["manter_reason"] = True
            elif regras is None and muda_mascara and set(item) <= {"min_stay", "check_in", "check_out"}:
                item["manter_reason"] = True
            item["preco_antes"] = float(r.get("price") or 0) or None
            x = {"quarto": quarto, "data": ds, "falta": falta, "item": item, "info": info,
                 "feriado": (ex["feriado"] or {}).get("nome"), "dso": r.get("dso_flag"), "estadia_antes": ms_ef}
            if tipo_est:
                x["tipo_estadia"] = tipo_est
            if temporada:
                x["temporada"] = temporada
            out.append(x)
        elif falta or info:
            out.append({"quarto": quarto, "data": ds, "falta": falta, "info": info,
                        "feriado": (ex["feriado"] or {}).get("nome"), "dso": r.get("dso_flag"), "estadia_antes": ms_ef})
        d += UM_DIA
    return out


def classificar_beds24(cfg: dict, inv: dict, de: dt.date, ate: dt.date) -> list[dict]:
    """Regra H no Beds24 (dono 01/11): por quarto, sábados com a chegada fechada (com a troca que a libera), sábados com
    estadia abaixo de 2 e noites fora do sábado com restrição (só o dono decide; vão ao relatório). A sexta da Afrodite
    (dono 05/11, `sexta_reaberta_por_quarto`) com o noCheckOut antigo volta a `none` (em `sabados_fora_da_regra`, com
    `pricelabs_tambem`). A órfã de feriado (dono 05/11) entra na estadia do dia de chegada e no sábado órfão.
    `inv` = beds24.inventario(): {curto: {data: {numAvail, minStay, override}}}."""
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    alvo_sab = rh.get("sabado_override")  # dono 02/11: sábado fechado para entrada e saída, sempre
    troca = rh.get("troca_sabado") or {"noCheckIn": "none", "noCheckInOrCheckOut": "noCheckOut"}
    ms_sab = int(rh.get("estadia_minima_sabado", 2))
    # dono 02/11 ("Pode fazer"): sábados de festa com entrada e saída abertas, e o lado órfão do fim de semana
    # (sábado esgotado com a sexta livre, ou sexta esgotada com o sábado livre) abre só naquele quarto
    excecoes = rh.get("sabados_excecao") or {}
    orfas = rh.get("orfas") or {}
    # override de sexta por quarto (dono 01/11: Afrodite noCheckOut; vazio desde 05/11, quando o dono abriu a saída de
    # sexta dela: "Isso, pode abrir Afrodite, saída de sexta"); o override tirado fica em `sexta_reaberta_por_quarto` e a
    # auditoria o devolve a "none" (comum.sexta_reaberta)
    sexta_alvo = rh.get("sexta_override_por_quarto") or {}
    try:
        from sazonalidade import carregar as _carregar_saz, periodo as _periodo_saz
        saz = _carregar_saz()
    except Exception:
        saz = None
    out = []

    for q in sorted(k for k in inv if not str(k).startswith("_")):
        chegada, estadia, outras, semana, orfa_estadia, por_data, dia_chegada = [], [], [], [], [], [], []
        # fonte única da noite livre/esgotada (revisão 05/11): a mesma classe que o plano do PriceLabs usa com o inventário
        oc = Ocupacao({}, int((cfg["por_curto"].get(q) or {}).get("unidades") or 1), inv[q])
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
                    # Beds24); feriado ficava de fora até 05/11 (dono 05/11: "As noites órfãs do feriado, a gente pode
                    # abrir", orfas.em_feriado; pacote e sábado de festa seguem de fora). Desde 05/11 a sexta órfã é a
                    # noite órfã geral da ordem de 04/11 (noite seguinte esgotada): vai em estadia_dia_chegada, com a sexta
                    if lado_orfao == "sexta" and not regra_dia_chegada(cfg, d - UM_DIA):
                        sxd = (d - dt.timedelta(days=1)).isoformat()
                        ms_sx = int((inv[q].get(sxd) or {}).get("minStay") or 1)
                        if ms_sx > 1 and not periodo_feriado(cfg, d - dt.timedelta(days=1)):
                            orfa_estadia.append({"data": sxd, "min_stay": ms_sx, "alvo": 1, "lado": "sexta"})
                    elif lado_orfao == "sábado" and ms > 1 and orfa_permitida(cfg, d, sabado=True):
                        orfa_estadia.append({"data": ds, "min_stay": ms, "alvo": 1, "lado": "sábado"})
                elif o in troca:
                    chegada.append({"data": ds, "override": o, "troca": troca[o]})
                elif o != "none" and o != "noCheckOut":
                    outras.append(f"{ds} {o}")
                if ms < ms_sab and not (lado_orfao == "sábado" and orfa_permitida(cfg, d, sabado=True)):
                    estadia.append(ds)
                continue
            antiga = sexta_reaberta(cfg, q, d)
            if d.weekday() == 4 and q in sexta_alvo:
                if o != sexta_alvo[q]:
                    it = {"data": ds, "override": o, "troca": sexta_alvo[q],
                          "motivo": f"sexta com override do dono ({sexta_alvo[q]}, regra_h_beds24.sexta_override_por_quarto)"}
                    if q == "Afrodite":
                        it["pricelabs_tambem"] = True
                    chegada.append(it)
            elif antiga and o in ("noCheckIn", "noCheckOut", "noCheckInOrCheckOut"):
                # dono 05/11 ("Isso, pode abrir Afrodite, saída de sexta"): o noCheckOut de 01/11 sai; a sexta fica sem
                # override (entrada e saída abertas; a chegada de sexta já era liberada pelo dono em 01/11). Na Afrodite
                # o envio do PriceLabs leva a chegada/saída ao Beds24: grave também no PriceLabs por data (check_out com
                # a sexta em 1), senão o envio desfaz. Blackout (disponibilidade) não é restrição de chegada/saída: fica
                it = {"data": ds, "override": o, "troca": "none",
                      "motivo": "sexta da Afrodite: entrada e saída abertas (dono 05/11, 'pode abrir Afrodite, saída de sexta')"}
                if q == "Afrodite":
                    it["pricelabs_tambem"] = True
                chegada.append(it)
            elif o != "none":
                outras.append(f"{ds} {o}")
            if regra_dia_chegada(cfg, d):
                # dono 04/11 (desde 05/11): estadia pelo dia de chegada, de domingo a sexta (antes a sexta não era
                # auditada e segunda/quarta voltavam a 1 por `semana_estadia_acima`); temporada acima do dia vale; noite
                # órfã (livre no quarto com a seguinte esgotada nele) com 1; feriado e pacote ficam com a estadia própria
                # (estadia_planejada devolve None), salvo a noite órfã de feriado e véspera (dono 05/11: desce a 1; a
                # volta à estadia do feriado é do plano completo). Mesma decisão do plano do PriceLabs (comparar)
                livre, seg_esg = oc.livre(d), oc.esgotada(d + UM_DIA)
                novo, orfa_ = estadia_planejada(cfg, d, ms, livre, seg_esg, quarto=q)
                # dia isolado entre noites esgotadas com 1: o PriceLabs manda 1 pela regra de dia órfão dele (o plano do
                # PriceLabs aceita como informação); sem isto o lote e o envio se desfariam a cada rotina. A própria noite
                # tem de estar livre (revisão 05/11: três noites esgotadas seguidas contavam como dia isolado)
                isolado = ms == 1 and livre and seg_esg and oc.esgotada(d - UM_DIA)
                if novo is not None and novo != ms and not (novo > ms and isolado):
                    it_d = {"data": ds, "min_stay": ms, "alvo": novo}
                    if orfa_ and novo == 1:
                        it_d["orfa"] = orfa_
                    fer_d = periodo_feriado(cfg, d)
                    if fer_d:
                        it_d["feriado"] = fer_d["nome"]
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
            if s.get("orfa"):
                motivo = "Dono 04/11 (libera órfã): noite livre com a seguinte esgotada no quarto, estadia 1"
                if s.get("feriado"):
                    motivo += f"; em feriado ({s['feriado']}), dono 05/11"
            else:
                motivo = f"Dono 04/11: estadia pelo dia de chegada (chegada de {DIAS_SEMANA[d.weekday()]}: {s['alvo']})"
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


def lotes_estadia(cfg: dict, classif: list[dict], hoje: dt.date, dias_urgente: int = 14) -> dict[str, list[dict]]:
    """Lotes só de estadia para a gravação segura (revisão de 05/11): por pousada, um lote **urgente** com as noites
    de hoje a hoje + `dias_urgente` (D0/D1 a D14: grave primeiro, é o que vende já) e os demais **por quarto**.
    Chaves: "<pousada>_urgente" e "<pousada>_<quarto>" (minúsculas na pousada), só as que têm item."""
    limite = hoje + dt.timedelta(days=dias_urgente)
    out: dict[str, list[dict]] = {}
    for it in lote_estadia_dia_chegada(classif):
        pousada = cfg["por_curto"][it["quarto"]]["pousada"].lower()
        chave = f"{pousada}_urgente" if dt.date.fromisoformat(it["de"]) <= limite else f"{pousada}_{it['quarto']}"
        out.setdefault(chave, []).append(it)
    return dict(sorted(out.items(), key=lambda kv: (kv[0].split("_")[0], not kv[0].endswith("_urgente"), kv[0])))


def ler_inventario_beds24(de: dt.date, ate: dt.date, token=None) -> tuple[dict, str]:
    """Inventário do Beds24 ao vivo (de − 1 a ate + 1) e o instante da leitura (ISO UTC). A sexta anterior ao primeiro
    dia entra na leitura: o sábado órfão (dono 02/11) depende dela; e a noite seguinte ao último dia também: a noite
    órfã (dono 04/11) depende dela."""
    import beds24
    lido_em = dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")
    inv = beds24.inventario(token or beds24.token_de_acesso(), (de - UM_DIA).isoformat(), (ate + UM_DIA).isoformat())
    return inv, lido_em


def auditar_beds24(cfg: dict, de: dt.date, ate: dt.date, token=None) -> list[dict]:
    inv, _ = ler_inventario_beds24(de, ate, token)
    return classificar_beds24(cfg, inv, de, ate)


def montar_plano(diferencas: list[dict], hoje: dt.date) -> dict:
    itens = []
    for x in diferencas:
        if not x.get("item"):
            continue
        partes = []
        d_ = dt.date.fromisoformat(x["data"])
        estadia_mudou = "min_stay" in x["item"] and any(f.startswith("estadia") for f in x["falta"])
        orfa = x["item"].get("orfa") == "seguinte"
        abre_sexta = x["item"].get("check_out") is not None and any(f.startswith("saída de sexta") for f in x["falta"])
        temporada = x.get("temporada")
        fonte = (x["item"].get("orfa_prova") or {}).get("fonte")
        if "min_price" in x["item"]:
            partes.append(f"piso feriado {x['feriado'].split(' (')[0]}" if x.get("feriado") else "piso dono 500/850 final")
        if estadia_mudou:
            if orfa:
                tipo = " noite órfã (dono 04/11" + ("; feriado, dono 05/11)" if x.get("feriado") else ")")
            elif x.get("feriado"):
                tipo = " feriado"
            elif d_.weekday() == 5:
                tipo = " sábado"
            else:
                tipo = f" chegada de {DIAS_SEMANA[d_.weekday()]} (dono 04/11)" + (f", temporada {temporada}" if temporada else "")
            partes.append(f"estadia {x['item']['min_stay']}n" + tipo)
        if abre_sexta:
            partes.append("saída de sexta aberta (dono 05/11)")
        motivo = "12m 21/10: " + "; ".join(partes)
        if estadia_mudou and "min_price" not in x["item"] and not x.get("feriado") and d_.weekday() != 5:
            # item só de estadia pela ordem de 04/11: o motivo vira o reason da regra por data nova ou só de estadia; com
            # preço na regra da data o payload sai sem reason (manter_reason)
            n = x["item"]["min_stay"]
            motivo = f"Estadia por dia de chegada (dono 04/11): chegada de {DIAS_SEMANA[d_.weekday()]}, {n} noite(s)"
            if temporada:
                motivo += f" pela temporada ({temporada}, sazonalidade)"
            if orfa:
                motivo += "; noite órfã (noite seguinte esgotada" + (" no Beds24)" if fonte == "beds24" else ")")
            if x.get("tipo_estadia") == "explícita":
                motivo += "; regra explícita (o padrão do PriceLabs muda sozinho)"
            if abre_sexta:
                motivo += "; saída de sexta aberta (dono 05/11)"
        elif estadia_mudou and orfa and x.get("feriado") and "min_price" not in x["item"]:
            # dono 05/11 ("As noites órfãs do feriado, a gente pode abrir"): a noite órfã do feriado com 1
            motivo = (f"Noite órfã em feriado ({x['feriado'].split(' (')[0]}; dono 05/11: 'As noites órfãs do feriado, "
                      "a gente pode abrir'): noite seguinte esgotada" + (" no Beds24" if fonte == "beds24" else "")
                      + ", 1 noite")
            if abre_sexta:
                motivo += "; saída de sexta aberta (dono 05/11)"
        elif abre_sexta and not estadia_mudou and "min_price" not in x["item"]:
            # dono 05/11 ("Isso, pode abrir Afrodite, saída de sexta"): item só de chegada/saída
            motivo = ("Dono 05/11 ('Isso, pode abrir Afrodite, saída de sexta'): saída de sexta aberta (check_out por "
                      "data com a sexta em 1)")
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
                         "gatilho/degrau das ordens de faixa) e sem mexer em sábado, feriado e pacote, salvo a noite "
                         "órfã de feriado (dono 05/11); a saída de sexta da Afrodite (dono 05/11) vem junto, com --regras")
    ap.add_argument("--regras", nargs="*", default=None,
                    help="QUARTO=get_listing_date_overrides.json (12 meses): o item só de estadia numa data cuja regra tem "
                         "preço, piso, teto ou chegada/saída leva manter_reason (o payload sai sem reason e o da regra "
                         "fica); e a segunda, a quarta e a sexta sem min_stay na regra ganham a estadia explícita")
    ap.add_argument("--efetiva", nargs="*", default=None,
                    help="QUARTO=get_listing_prices.json: estadia efetiva de hoje no PriceLabs, que decide subir/descer "
                         "(vence o min_stay do recálculo)")
    ap.add_argument("--inventario", help="inventário do Beds24 já lido (beds24.py inventario --json), no lugar da leitura "
                                         "ao vivo; cobre de --de − 1 a --ate + 1 (sem a noite seguinte ao último dia, "
                                         "a órfã dele não se vê). Com ele (ou com a leitura ao vivo), o plano do PriceLabs "
                                         "e o lote do Beds24 decidem a órfã pela mesma fonte")
    ap.add_argument("--inventario-lido-em", help="instante da leitura do --inventario (ISO; padrão: a hora do arquivo)")
    ap.add_argument("--lotes-estadia", metavar="PREFIXO",
                    help="grava os lotes do beds24.py editar-lote só com min_stay (dono 04/11), só nas noites que mudam: "
                         "por pousada, PREFIXO_<pousada>_urgente.json (de hoje a hoje + 14) e PREFIXO_<pousada>_<quarto>.json")
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
    quartos_rec = [x.split("=", 1)[0] for x in a.recalculos]
    regras = ler_regras_por_data(a.regras) if a.regras else {}
    sem_regras = [k for k in quartos_rec if a.regras and k not in regras]
    if sem_regras:
        # sem as regras do quarto o item iria com reason e apagaria o da regra da data (topo_mercado lê a Regra J nele)
        print(f"ERRO: --regras sem o arquivo de {', '.join(sem_regras)}: passe as regras por data de todos os quartos")
        return 1
    if not a.regras:
        print("AVISO: sem --regras, a estadia explícita de segunda, quarta e sexta não é conferida e todo item leva reason")
    efetivas = {}
    for par in a.efetiva or []:
        k, v = par.split("=", 1)
        efetivas[k], lid = ler_efetiva(v)
        if lid is not None and k in cfg["por_curto"] and str(lid) != str(cfg["por_curto"][k]["id"]):
            print(f"ERRO: --efetiva {k} é do anúncio {lid}, não do {cfg['por_curto'][k]['id']}: arquivo trocado")
            return 1
    sem_ef = [k for k in quartos_rec if a.efetiva is not None and k not in efetivas]
    if sem_ef:
        print(f"ERRO: --efetiva sem o arquivo de {', '.join(sem_ef)}: passe a estadia efetiva de todos os quartos")
        return 1
    # fonte única da órfã (revisão de 05/11): o inventário do Beds24 (arquivo ou leitura ao vivo) decide a noite
    # livre/esgotada no plano do PriceLabs e no lote do Beds24; o recálculo só serve sem inventário
    de_b24 = dt.date.fromisoformat(a.de) if a.de else hoje
    inv, lido_em = None, None
    if a.inventario:
        inv, lido_em = ler_inventario(a.inventario, a.inventario_lido_em)
    elif not a.sem_beds24:
        inv, lido_em = ler_inventario_beds24(min(de, de_b24), ate)
    if inv is not None:
        falta_inv = [k for k in quartos_rec if k not in inv]
        if falta_inv:
            print(f"AVISO: inventário do Beds24 sem {', '.join(falta_inv)}: nesses quartos a órfã sai do recálculo")
        print(f"Órfã e dia isolado pelo inventário do Beds24 (lido em {lido_em})")
    for arg in a.recalculos:
        quarto, caminho = arg.split("=", 1)
        dif += comparar(cfg, quarto, ler_recalculo(caminho), de, ate, ordens, so_estadia=a.so_estadia,
                        regras=regras.get(quarto) if a.regras else None, efetiva=efetivas.get(quarto),
                        inv=(inv or {}).get(quarto) if inv is not None and quarto in inv else None, lido_em=lido_em)
    infos = [x for x in dif if not x["falta"]]
    dif = [x for x in dif if x["falta"]]
    resumo = {}
    for x in infos:
        for f in x["info"]:
            tipo = next((t for t in ("noite órfã", "dia órfão", "sábado órfão", "aguardando o dono") if t in f), "ordem")
            chave = (x["quarto"], "info:" + tipo)
            resumo[chave] = resumo.get(chave, 0) + 1
    for x in dif:
        for f in x["falta"]:
            tipo = f.split(" ")[0]
            if tipo == "estadia" and "item" in x and "min_stay" in x["item"]:
                tipo += " " + x.get("tipo_estadia", "sobe" if "<" in f else "desce")
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
        b24 = classificar_beds24(cfg, inv or {}, de_b24, ate)
        for x in b24:
            ch, es, ou = x["sabados_fora_da_regra"], x["sabados_estadia_abaixo"], x["outras_noites_bloqueadas"]
            se = x.get("semana_estadia_acima") or []
            oe = x.get("orfas_estadia") or []
            ed = x.get("estadia_dia_chegada") or []
            print(f"Beds24 {x['quarto']:9} chegada/saída fora da regra (sábados; sextas da Afrodite): {len(ch)}" +
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
            # gravação segura (revisão de 05/11): por pousada, o urgente (D0 a D14) primeiro, depois um lote por quarto
            for chave, lote in lotes_estadia(cfg, b24, hoje).items():
                arq = f"{a.lotes_estadia}_{chave}.json"
                json.dump(lote, open(arq, "w"), ensure_ascii=False, indent=1)
                print(f"{len(lote)} noites só de estadia no lote do Beds24 {chave} "
                      f"({min(i['de'] for i in lote)} a {max(i['de'] for i in lote)}) em {arq}")
    if a.json:
        json.dump({"pricelabs": dif, "info": infos, "beds24": b24}, open(a.json, "w"), ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

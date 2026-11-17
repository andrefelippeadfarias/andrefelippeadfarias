"""Vender as janelas (dono 16/11/2026 ~20:05 BRT, ordem `dono-2026-11-16-vender-janelas`; `parametros.vender_janelas`).

Texto do dono: "Na verdade só venda onde tem uma janela. A preocupação é onde temos janelas, temos que vender as janelas."

Janela = unidade livre que NENHUMA estadia válida consegue vender com as restrições da regra normal no Beds24
(restrictionStrategy "stayThrough": a estadia mínima e a máxima valem em cada noite coberta; chegada bloqueada por
noCheckIn/noCheckInOrCheckOut/blackout na noite de chegada; saída bloqueada por noCheckOut/noCheckInOrCheckOut na data
de saída; noite com numAvail <= 0 ou blackout não vende; estadias de até `estadia_maxima_simulada` noites, chegada de
hoje em diante). Por noite n de um quarto: livres = numAvail; vendável = o máximo de estadias válidas simultâneas que
cobrem n, respeitando o numAvail de toda noite que elas cobrem; presas = livres − vendável.

A skill abre o MÍNIMO de restrição que torna a unidade presa vendável, sem mexer em preço (candidatos na ordem de menor
desvio, o primeiro que zera as presas da noite vence, nunca piorando outra noite de n − 7 a n + 7):
  (a) domingo a sexta: estadia mínima 1 na noite;
  (b) sábado: entrada no sábado (override noCheckOut: entrada aberta, saída no sábado segue fechada), com a estadia da
      regra (2) quando ela já basta (domingo com livres) — (b1) —, senão também estadia 1 — (b2);
  (c) sexta com o sábado quase esgotado: sábado noCheckIn (saída no sábado aberta, entrada fechada) e estadia 1 na sexta.
Trava de fragmentação: abre só com presas(n) >= livres da noite vizinha que a abertura põe em risco (sexta: o sábado;
sábado: a sexta; domingo a quinta: nenhuma); senão a noite é "janela a observar" (abre sozinha quando a vizinha esgotar,
pela noite órfã de 04/11 e pelo sábado órfão de 02/11). Nunca em pacote do Natal e do Ano Novo, nas noites da ordem do
Réveillon (restrições próprias), no sábado de festa, em noite com blackout ou esgotada, em noite congelada por mudança
manual, fora do horizonte (hoje a hoje + `horizonte_dias`) ou nas `noites_fora`. Feriado pode (dono 05/11 e 16/11).
Afrodite só com estadia (v1: o envio do PriceLabs dela leva chegada/saída ao Beds24; (b) e (c) dela ficam "a observar").

As presas se calculam no INVENTÁRIO DA REGRA (o inventário lido com a estadia e a chegada/saída que a regra normal, sem
janela, manda em cada noite: `calendario_12m.inventario_da_regra`), nunca no estado atual: é isso que mantém a janela já
aberta, fecha (volta à regra) quando ela some e deixa a noite órfã de 04/11 e o sábado órfão de 02/11 na frente.

Revisão de 16/11 (achados da verificação): a ordem tem de liberar "janela" (`ordem_janela`); a escolha da abertura é
uma só (`escolher`), no `propor` e no validador; a busca do validador usa a `estadia_maxima_simulada` da config; a noite
sem cobertura do inventário não se avalia (`noites_nao_avaliadas`: o `calendario_12m` não fecha nela o que uma janela
abre); o relatório separa a janela fechada (avaliada e sumiu) da não reavaliada (sem cobertura, fora do horizonte) e
ignora a noite que passou.

Funções puras, sem rede (importa só `comum` e `ordens`; `calendario_12m` e `validar_plano` importam este módulo). A prova
(`janela_prova`) vale por si: o validador recalcula o vendável a partir dela (`checar_prova_janela`).

Uso (só relatório, sem gravar nada):
  python3 vender_janelas.py relatorio --hoje AAAA-MM-DD --inventario inv.json [--inventario-lido-em ISO] [--json saida.json]
      [--anterior janelas_da_rodada_anterior.json]
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from comum import (DIAS_SEMANA, aguardando_dono, carregar_quartos, data, data_de_pacote,  # noqa: E402
                   periodo_feriado)
from ordens import carregar_ordens, cobre, congelada, ordem_valida  # noqa: E402

UM_DIA = dt.timedelta(days=1)
MAX_NOITES = 7
CHEGADA_FECHADA = ("noCheckIn", "noCheckInOrCheckOut", "blackout")
SAIDA_FECHADA = ("noCheckOut", "noCheckInOrCheckOut")
SABADO_FECHADO = "noCheckInOrCheckOut"

# padrões de `parametros.vender_janelas` (sem a chave em quartos.json, a regra fica desligada)
PADRAO = {"ativa": False, "ordem_id": None, "desde": None, "horizonte_dias": 60, "estadia_maxima_simulada": MAX_NOITES,
          "trava_fragmentacao": True, "quartos_so_estadia": ["Afrodite"], "sabado_entrada": "noCheckOut",
          "sabado_saida": "noCheckIn", "noites_fora": {}}

MOTIVO_AFRODITE = "Afrodite: chegada/saída só pelo PriceLabs (v1 só estadia)"


def parametros(cfg: dict) -> dict:
    """`parametros.vender_janelas` de quartos.json com os padrões (PADRAO) no que faltar."""
    par = dict(PADRAO)
    par.update(((cfg or {}).get("parametros") or {}).get("vender_janelas") or {})
    return par


def ordem_janela(ordens: dict | None, par: dict, quarto: str, d: dt.date) -> dict | None:
    """A ordem das janelas (`par["ordem_id"]`) quando vale para o quarto e a noite (`ordens.ordem_valida`: existe, cobre o
    quarto e a noite, não é acompanhamento) E libera "janela". Revisão de 16/11 (achado da verificação): o portão olhava
    só a vigência; com "janela" fora de `libera` (o dono tirou a liberação) a janela seguia abrindo e o validador
    aceitava. Sem a liberação, nada abre e a prova não vale."""
    o = ordem_valida(ordens or {}, par.get("ordem_id"), quarto, d)
    if o is None or "janela" not in (o.get("libera") or []):
        return None
    return o


def _d(x) -> dt.date:
    return x if isinstance(x, dt.date) else data(x)


def _noite(inv_q: dict, d: dt.date) -> dict | None:
    return (inv_q or {}).get(d.isoformat())


def _ov(v: dict | None) -> str:
    return str((v or {}).get("override") or "none")


def _av(v: dict | None) -> int:
    try:
        return int((v or {}).get("numAvail"))
    except (TypeError, ValueError):
        return 0


def vende(v: dict | None) -> bool:
    """A noite tem unidade que vende: numAvail > 0 e override diferente de blackout."""
    return bool(v) and v.get("numAvail") is not None and _av(v) > 0 and _ov(v) != "blackout"


def estadia_valida(inv_q: dict, a: dt.date, b: dt.date, hoje: dt.date, max_noites: int = MAX_NOITES) -> bool:
    """A estadia a→b (b = data de saída) vende no inventário do quarto (Beds24 stayThrough): chegada de hoje em diante e
    aberta (fora de noCheckIn, noCheckInOrCheckOut, blackout); toda noite de a a b − 1 presente, com numAvail > 0 e sem
    blackout; saída em b fora de noCheckOut/noCheckInOrCheckOut (b fora do inventário não bloqueia); a estadia mínima e
    a máxima de CADA noite coberta valem (1 <= L <= max_noites)."""
    n = (b - a).days
    if a < hoje or n < 1 or n > max_noites:
        return False
    va = _noite(inv_q, a)
    if not va or _ov(va) in CHEGADA_FECHADA:
        return False
    noites = [a + k * UM_DIA for k in range(n)]
    for x in noites:
        if not vende(_noite(inv_q, x)):
            return False
    if _ov(_noite(inv_q, b)) in SAIDA_FECHADA:
        return False
    mins = max(int((_noite(inv_q, x) or {}).get("minStay") or 1) for x in noites)
    maxs = min(int((_noite(inv_q, x) or {}).get("maxStay") or 365) for x in noites)
    return mins <= n <= maxs


def estadias_minimas(inv_q: dict, n: dt.date, hoje: dt.date, max_noites: int = MAX_NOITES) -> list[tuple]:
    """As estadias válidas que cobrem n e não contêm outra estadia válida que cubra n (a menor domina: usa menos
    capacidade das noites vizinhas e vende a mesma noite n)."""
    out = []
    for atras in range(max_noites):
        a = n - atras * UM_DIA
        if a < hoje:
            break
        for L in range(atras + 1, max_noites + 1):
            b = a + L * UM_DIA
            if estadia_valida(inv_q, a, b, hoje, max_noites):
                out.append((a, b))
    return [s for s in out if not any(t != s and t[0] >= s[0] and t[1] <= s[1] for t in out)]


def vendavel(inv_q: dict, n: dt.date, hoje: dt.date, max_noites: int = MAX_NOITES) -> tuple[int, list[str]]:
    """(vendável, exemplo) da noite n: o máximo de estadias válidas simultâneas (com repetição) entre as mínimas de n,
    respeitando o numAvail de cada noite que elas cobrem; busca com poda, parando nas livres da noite. O exemplo lista
    as estadias da melhor combinação ("11/12->12/12 x5")."""
    v = _noite(inv_q, n)
    if not vende(v):
        return 0, []
    livres = _av(v)
    cob = estadias_minimas(inv_q, n, hoje, max_noites)
    melhor = [0, {}]

    def busca(i, usado, esc):
        tot = sum(esc.values())
        if tot > melhor[0]:
            melhor[0], melhor[1] = tot, dict(esc)
        if melhor[0] >= livres or i >= len(cob):
            return
        a, b = cob[i]
        noites = [a + k * UM_DIA for k in range((b - a).days)]
        if all(usado.get(x, 0) < _av(_noite(inv_q, x)) for x in noites):
            for x in noites:
                usado[x] = usado.get(x, 0) + 1
            esc[(a, b)] = esc.get((a, b), 0) + 1
            busca(i, usado, esc)
            esc[(a, b)] -= 1
            if not esc[(a, b)]:
                del esc[(a, b)]
            for x in noites:
                usado[x] -= 1
        busca(i + 1, usado, esc)

    busca(0, {}, {})
    return melhor[0], [f"{a:%d/%m}->{b:%d/%m} x{c}" for (a, b), c in sorted(melhor[1].items())]


def presas_da_noite(inv_q: dict, n: dt.date, hoje: dt.date, max_noites: int = MAX_NOITES) -> dict:
    """{livres, vendavel, presas, exemplo} da noite n (noite que não vende: tudo 0)."""
    v = _noite(inv_q, n)
    if not vende(v):
        return {"livres": max(_av(v), 0) if v else 0, "vendavel": 0, "presas": 0, "exemplo": []}
    m, ex = vendavel(inv_q, n, hoje, max_noites)
    return {"livres": _av(v), "vendavel": m, "presas": _av(v) - m, "exemplo": ex}


def coberta(inv_q: dict, n: dt.date, hoje: dt.date, max_noites: int = MAX_NOITES) -> bool:
    """O inventário do quarto tem TODAS as noites de max(hoje, n − (max − 1)) a n + max (a saída da estadia mais longa).
    Sem isso a busca subestima o vendável (a estadia que passa do fim do arquivo vira inválida) e abriria janela falsa."""
    d = max(hoje, n - (max_noites - 1) * UM_DIA)
    while d <= n + max_noites * UM_DIA:
        if _noite(inv_q, d) is None:
            return False
        d += UM_DIA
    return True


def com_mudancas(inv_q: dict, mudancas: dict) -> dict:
    """Cópia rasa do inventário do quarto com as `mudancas` ({data: {"minStay": [antes, depois], "override": [antes,
    depois]}}) aplicadas (o "depois")."""
    out = dict(inv_q)
    for ds, campos in (mudancas or {}).items():
        v = dict(out.get(ds) or {})
        for campo, (_, depois) in campos.items():
            v[campo] = depois
        out[ds] = v
    return out


def vizinha_de_risco(n: dt.date) -> dt.date | None:
    """A noite vizinha que a abertura põe em risco (fragmentação): sexta → o sábado; sábado → a sexta; domingo a quinta →
    nenhuma (R = 0)."""
    if n.weekday() == 4:
        return n + UM_DIA
    if n.weekday() == 5:
        return n - UM_DIA
    return None


def candidatos(cfg: dict, quarto: str, n: dt.date, regra_q: dict) -> list[dict]:
    """Aberturas mínimas da noite n sobre o inventário da regra do quarto, na ordem de menor desvio, sem no-op:
    domingo a sexta (a) estadia 1; sexta, depois, (c) estadia 1 e o sábado seguinte noCheckIn (só com o sábado da regra
    noCheckInOrCheckOut); sábado da regra noCheckInOrCheckOut, (b1) entrada no sábado (noCheckOut) com a estadia da regra
    e (b2) entrada no sábado com estadia 1. Quarto de `quartos_so_estadia` (Afrodite): só (a). Cada candidato:
    {"codigo", "tipo", "abertura", "mudancas": {data: {campo: [antes, depois]}}}."""
    par = parametros(cfg)
    so_estadia = quarto in (par.get("quartos_so_estadia") or [])
    v = _noite(regra_q, n) or {}
    ms = int(v.get("minStay") or 1)
    ds = n.isoformat()
    out = []
    if n.weekday() != 5:
        if ms > 1:
            out.append({"codigo": "a", "tipo": "noite", "abertura": f"estadia 1 na noite ({DIAS_SEMANA[n.weekday()]})",
                        "mudancas": {ds: {"minStay": [ms, 1]}}})
        sab = n + UM_DIA
        vs = _noite(regra_q, sab)
        if n.weekday() == 4 and not so_estadia and vs is not None and _ov(vs) == SABADO_FECHADO:
            mud = {ds: {"minStay": [ms, 1]}} if ms > 1 else {}
            mud[sab.isoformat()] = {"override": [SABADO_FECHADO, par.get("sabado_saida") or "noCheckIn"]}
            out.append({"codigo": "c", "tipo": "sexta",
                        "abertura": (("estadia 1 na sexta e " if ms > 1 else "")
                                     + f"saída no sábado {sab:%d/%m} ({par.get('sabado_saida') or 'noCheckIn'})"),
                        "mudancas": mud})
        return out
    if so_estadia or _ov(v) != SABADO_FECHADO:
        return out
    entrada = par.get("sabado_entrada") or "noCheckOut"
    out.append({"codigo": "b1", "tipo": "sábado", "abertura": f"entrada no sábado ({entrada}), estadia {ms}",
                "mudancas": {ds: {"override": [SABADO_FECHADO, entrada]}}})
    if ms > 1:
        out.append({"codigo": "b2", "tipo": "sábado", "abertura": f"entrada no sábado ({entrada}) e estadia 1",
                    "mudancas": {ds: {"override": [SABADO_FECHADO, entrada], "minStay": [ms, 1]}}})
    return out


def motivo_exclusao(cfg: dict, ordens: dict, quarto: str, d: dt.date, hoje: dt.date, inv_q: dict | None) -> str | None:
    """Por que a janela nunca abre na noite d (None = pode abrir). Vale para a noite presa e para toda noite que a abertura
    muda. Na ordem: fora do horizonte ou antes de `desde`; fora da ordem de janelas; pacote do dono (Natal e Ano Novo,
    `comum.data_de_pacote`, com os `*_por_quarto` do Réveillon); sábado de festa (`regra_h_beds24.sabados_excecao`); ordem
    do dono com `restricoes` próprias que cobre o quarto e a noite (Réveillon 30/12 a 02/01); noite congelada por mudança
    manual (`ordens.congelada`); blackout ou numAvail <= 0 em `inv_q`; noite aguardando o dono; `noites_fora`. A ordem de
    janelas tem de liberar "janela" (`ordem_janela`, revisão de 16/11). NÃO exclui
    por ordem de tipo `restricao` em geral (a dos sábados fechados vale até 2099) nem por feriado (dono 05/11 e 16/11)."""
    par = parametros(cfg)
    ds = d.isoformat()
    if d < hoje or d > hoje + dt.timedelta(days=int(par.get("horizonte_dias") or 60)):
        return "fora do horizonte"
    if par.get("desde") and d < _d(par["desde"]):
        return f"antes de {par['desde']} (ordem de janelas)"
    if ordem_janela(ordens, par, quarto, d) is None:
        # revisão de 16/11: também a ordem sem "janela" em `libera`
        return f"fora da ordem {par.get('ordem_id')} (ou a ordem não libera 'janela')"
    if data_de_pacote(cfg, d):
        return "pacote do dono (03/11: Natal e Ano Novo)"
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    if ds in (rh.get("sabados_excecao") or {}):
        return "sábado de festa (dono 02/11)"
    for o in (ordens or {}).values():
        if o.get("restricoes") and cobre(o, quarto, d) and (not o.get("noites") or ds in o["noites"]):
            return f"ordem do dono com restrições próprias ({o.get('id')})"
    c = congelada(ordens or {}, quarto, d)
    if c:
        return f"noite congelada por mudança manual ({c.get('id')})"
    v = _noite(inv_q or {}, d)
    if v is not None and (_ov(v) == "blackout" or _av(v) <= 0):
        return "noite fechada pelo dono (blackout)" if _ov(v) == "blackout" else "noite esgotada"
    if aguardando_dono(cfg, quarto, d):
        return "noite aguardando o dono"
    nf = par.get("noites_fora") or {}
    if ds in ((nf.get(quarto) or []) if isinstance(nf, dict) else nf):
        return "noites_fora (exclusão manual)"
    return None


def _snapshot(regra_q: dict, n: dt.date, hoje: dt.date, max_noites: int) -> dict:
    """O inventário da regra de max(hoje, n − (max − 1)) a n + max, só com os campos que a busca usa."""
    out, d = {}, max(hoje, n - (max_noites - 1) * UM_DIA)
    while d <= n + max_noites * UM_DIA:
        v = _noite(regra_q, d)
        if v is not None:
            out[d.isoformat()] = {k: v.get(k) for k in ("numAvail", "minStay", "maxStay", "override")}
        d += UM_DIA
    return out


def _risco(regra_q: dict, n: dt.date, hoje: dt.date) -> dict | None:
    """{data, livres} da vizinha de risco (a sexta que já passou não vende: livres 0); None de domingo a quinta."""
    rv = vizinha_de_risco(n)
    if rv is None:
        return None
    return {"data": rv.isoformat(), "livres": 0 if rv < hoje else max(_av(_noite(regra_q, rv)), 0)}


def montar_prova(cfg: dict, quarto: str, n: dt.date, regra_q: dict, cand: dict, antes: dict, depois: dict,
                 risco: dict | None, hoje: dt.date, lido_em: str | None) -> dict:
    """`janela_prova`: vale por si (como a `orfa_prova` do Beds24): o validador recalcula o vendável antes e depois a
    partir do snapshot `regra` (noites de max(hoje, n − 6) a n + 7) e das `mudancas`."""
    par = parametros(cfg)
    mx = int(par.get("estadia_maxima_simulada") or MAX_NOITES)
    return {"fonte": "beds24", "lido_em": lido_em, "hoje": hoje.isoformat(), "ordem_id": par.get("ordem_id"),
            "quarto": quarto, "noite": n.isoformat(), "tipo": cand["tipo"], "codigo": cand["codigo"],
            "unidades": int(((cfg.get("por_curto") or {}).get(quarto) or {}).get("unidades") or 1),
            "livres": antes["livres"], "vendavel_antes": antes["vendavel"], "vendavel_depois": depois["vendavel"],
            "presas": antes["presas"], "vizinha_risco": risco, "max_noites": mx, "mudancas": cand["mudancas"],
            "regra": _snapshot(regra_q, n, hoje, mx), "exemplo_antes": antes["exemplo"],
            "exemplo_depois": depois["exemplo"]}


def _ja_no_atual(atual_q: dict, mudancas: dict) -> bool:
    """Toda mudança da janela já está no inventário atual (estadia e chegada/saída iguais ao "depois")."""
    for ds, campos in mudancas.items():
        v = (atual_q or {}).get(ds) or {}
        for campo, (_, depois) in campos.items():
            lido = _ov(v) if campo == "override" else int(v.get(campo) or 1)
            if lido != depois:
                return False
    return True


def escolher(cfg: dict, quarto: str, n: dt.date, regra_q: dict, hoje: dt.date, mx: int, antes: dict,
             pular=None, piora=None) -> tuple | None:
    """A abertura que a regra escolhe na noite n (revisão de 16/11: a MESMA escolha no `propor` e no validador, que
    antes aceitava qualquer candidato que vendesse mais): os candidatos na ordem de menor desvio; pula o que
    `pular(c)` recusa (noite excluída, conflito), o que não vende mais que `antes` e o que `piora(c, r2)` recusa (presas
    de outra noite); fica o que mais vende e para no primeiro que zera as presas ("estadia 2 se isso já basta, senão 1").
    Devolve (candidato, depois, inventário com a abertura) ou None."""
    melhor = None
    for c in candidatos(cfg, quarto, n, regra_q):
        if pular is not None and pular(c):
            continue
        r2 = com_mudancas(regra_q, c["mudancas"])
        dep = presas_da_noite(r2, n, hoje, mx)
        if dep["vendavel"] <= antes["vendavel"]:
            continue
        if piora is not None and piora(c, r2):
            continue
        if melhor is None or dep["vendavel"] > melhor[1]["vendavel"]:
            melhor = (c, dep, r2)
            if dep["presas"] <= 0:
                break      # o primeiro que zera vence (menor desvio)
    return melhor


def noites_nao_avaliadas(jan: dict | None) -> dict[str, set]:
    """{quarto: {data}} das noites que a rodada NÃO avaliou por falta de cobertura do inventário (`sem_cobertura`, fora
    das de pacote/dono, que nunca abrem). Revisão de 16/11 (achado da verificação, rotina leve com o inventário até
    hoje + 15): nelas a janela pode estar aberta e a regra normal não pode fechá-la (o `classificar_beds24` e o
    `comparar` não apertam a estadia 1 nem fecham a entrada/saída aberta no sábado); a rotina completa decide."""
    out: dict[str, set] = {}
    for x in (jan or {}).get("sem_cobertura") or []:
        if not x.get("motivo"):
            out.setdefault(x["quarto"], set()).add(x["noite"])
    return out


def propor(cfg: dict, ordens: dict, regra: dict, atual: dict, hoje: dt.date, ate: dt.date,
           lido_em: str | None) -> dict:
    """As janelas de hoje a min(ate, hoje + horizonte), por quarto e noite em ordem de data, sobre o inventário da regra
    (`regra`, {quarto: {data: noite}}); `atual` é o inventário lido (estado de agora: decide "abrir" ou "manter").
    Devolve {"hoje", "lido_em", "ordem_id", "horizonte", "alvos": {quarto: {data: alvo}}, "abrir", "mantidas",
    "observar", "excluidas", "sem_cobertura"}; o alvo de cada noite mudada é {"min_stay"?, "override"?, "tipo",
    "codigo", "noite_janela", "estado", "prova"}. A cópia de trabalho do quarto recebe cada abertura escolhida (a
    noite seguinte já a vê: sexta e sábado não abrem em conflito). Revisão de 16/11: "quartos" (os avaliados); a noite
    de `sem_cobertura` que nunca abriria (pacote, festa, Réveillon...) leva "motivo"; a escolha é a do `escolher`."""
    par = parametros(cfg)
    vazio = {"hoje": hoje.isoformat(), "lido_em": lido_em, "ordem_id": par.get("ordem_id"), "ativa": bool(par["ativa"]),
             "horizonte": [hoje.isoformat(), hoje.isoformat()], "quartos": [], "alvos": {}, "abrir": [],
             "mantidas": [], "observar": [], "excluidas": [], "sem_cobertura": []}
    if not par["ativa"]:
        return vazio
    mx = int(par.get("estadia_maxima_simulada") or MAX_NOITES)
    fim = min(ate, hoje + dt.timedelta(days=int(par.get("horizonte_dias") or 60)))
    # revisão de 16/11: "quartos" = os quartos avaliados (o `fechadas` não chama de fechada a janela de quarto fora da
    # leitura)
    res = dict(vazio, horizonte=[hoje.isoformat(), fim.isoformat()],
               quartos=sorted(k for k in (regra or {}) if not str(k).startswith("_")))
    for q in res["quartos"]:
        r = dict(regra[q])
        atual_q = (atual or {}).get(q) or {}
        alvos_q: dict = {}
        n = hoje
        while n <= fim:
            ds = n.isoformat()
            # revisão de 16/11: a ordem tem de liberar "janela" (ordem_janela)
            if (par.get("desde") and n < _d(par["desde"])) or \
                    ordem_janela(ordens, par, q, n) is None or not vende(r.get(ds)):
                n += UM_DIA
                continue
            if not coberta(r, n, hoje, mx):
                # revisão de 16/11: a noite de pacote/dono leva o motivo (nunca é janela; a regra normal vale nela);
                # as outras são "não avaliadas" (noites_nao_avaliadas): a regra normal não as fecha
                sc = {"quarto": q, "noite": ds}
                mot_sc = motivo_exclusao(cfg, ordens, q, n, hoje, atual_q)
                if mot_sc:
                    sc["motivo"] = mot_sc
                res["sem_cobertura"].append(sc)
                n += UM_DIA
                continue
            antes = presas_da_noite(r, n, hoje, mx)
            if antes["presas"] <= 0:
                n += UM_DIA
                continue
            base = {"quarto": q, "noite": ds, "dia": DIAS_SEMANA[n.weekday()], "livres": antes["livres"],
                    "vendavel_antes": antes["vendavel"], "presas": antes["presas"], "exemplo_antes": antes["exemplo"]}
            mot = motivo_exclusao(cfg, ordens, q, n, hoje, atual_q)
            if mot:
                res["excluidas"].append(dict(base, motivo=mot))
                n += UM_DIA
                continue

            def _pular(c, ds=ds, alvos_q=alvos_q, atual_q=atual_q, q=q):
                # a outra noite que a abertura muda (o sábado da (c)) excluída, ou conflito com abertura já escolhida
                if any(motivo_exclusao(cfg, ordens, q, _d(x), hoje, atual_q) for x in c["mudancas"] if x != ds):
                    return True
                for x, campos in c["mudancas"].items():
                    ja = alvos_q.get(x) or {}
                    for campo, (_, depois) in campos.items():
                        k = "min_stay" if campo == "minStay" else "override"
                        if k in ja and ja[k] != depois:
                            return True
                return False

            def _piora(c, r2, n=n, r=r):
                # trava (invariante: abertura pura nunca piora; fica como guarda): nenhuma noite de n − 7 a n + 7 com
                # mais presas que antes
                for k in range(-7, 8):
                    x = n + k * UM_DIA
                    if k == 0 or x < hoje or not coberta(r, x, hoje, mx) or not vende(r.get(x.isoformat())):
                        continue
                    if presas_da_noite(r2, x, hoje, mx)["presas"] > presas_da_noite(r, x, hoje, mx)["presas"]:
                        return True
                return False

            # revisão de 16/11: a escolha sai do `escolher`, o mesmo que o validador refaz sobre a prova
            melhor = escolher(cfg, q, n, r, hoje, mx, antes, _pular, _piora)
            if melhor is None:
                so = q in (par.get("quartos_so_estadia") or []) and n.weekday() in (4, 5)
                res["observar"].append(dict(base, motivo=MOTIVO_AFRODITE if so else "nenhuma abertura mínima vende"))
                n += UM_DIA
                continue
            c, dep, r2 = melhor
            risco = _risco(r, n, hoje)
            linha = dict(base, vendavel_depois=dep["vendavel"], exemplo_depois=dep["exemplo"], codigo=c["codigo"],
                         abertura=c["abertura"], mudancas=c["mudancas"], vizinha_risco=risco)
            if par.get("trava_fragmentacao") and risco and antes["presas"] < risco["livres"]:
                res["observar"].append(dict(linha, motivo=(f"trava: presas {antes['presas']} < livres da vizinha "
                                                           f"{_d(risco['data']):%d/%m} ({risco['livres']})")))
                n += UM_DIA
                continue
            prova = montar_prova(cfg, q, n, r, c, antes, dep, risco, hoje, lido_em)
            estado = "manter" if _ja_no_atual(atual_q, c["mudancas"]) else "abrir"
            for x, campos in c["mudancas"].items():
                alvo = alvos_q.setdefault(x, {"tipo": c["tipo"] if x == ds else "saída no sábado", "codigo": c["codigo"],
                                              "noite_janela": ds, "estado": estado, "prova": prova})
                for campo, (_, depois) in campos.items():
                    alvo["min_stay" if campo == "minStay" else "override"] = depois
            res["mantidas" if estado == "manter" else "abrir"].append(dict(linha, estado=estado))
            r = r2
            n += UM_DIA
        if alvos_q:
            res["alvos"][q] = alvos_q
    return res


def checar_prova_janela(cfg: dict, ordens: dict, it: dict, quarto: str, d: dt.date, hoje: dt.date) -> str | None:
    """None quando a `janela_prova` do item prova a janela na noite d do quarto; senão o porquê. Confere (o `lido_em`
    de hoje ou ontem é do `validar_plano._falha_lido_em`, chamado antes): regra ligada e a ordem de janelas da prova
    vigente para o quarto e a noite; quarto e noite do item; fonte Beds24 e `hoje` da prova de hoje ou ontem; o snapshot
    `regra` cobrindo a busca; o vendável recalculado antes e depois (presas > 0 e a abertura vende mais); as `mudancas`
    iguais a um dos candidatos mínimos da noite (nenhum aperto, nenhuma terceira noite); a trava de fragmentação; nenhuma
    noite mudada com exclusão (pacote, festa, Réveillon, congelada, blackout); o `min_stay` do item igual ao da janela e o
    item sem chegada/saída por data (v1). Revisão de 16/11 (achados da verificação): a ordem tem de liberar "janela"
    (`ordem_janela`); a busca usa a `estadia_maxima_simulada` da config (prova com outro `max_noites` é recusada); as
    `mudancas` têm de ser as que a regra escolhe (`escolher`), não só um candidato que venda mais."""
    par = parametros(cfg)
    p = it.get("janela_prova")
    if not isinstance(p, dict) or not p:
        return "sem janela_prova"
    if not par.get("ativa"):
        return "regra das janelas desligada (parametros.vender_janelas.ativa)"
    if not par.get("ordem_id") or p.get("ordem_id") != par.get("ordem_id"):
        return f"ordem da prova {p.get('ordem_id')!r} não é a das janelas ({par.get('ordem_id')})"
    if p.get("quarto") != quarto:
        return f"prova do quarto {p.get('quarto')!r}, o item é de {quarto}"
    try:
        noite, ph = _d(p["noite"]), _d(p["hoje"])
    except (KeyError, TypeError, ValueError):
        return "prova sem noite ou hoje"
    for x in (noite, d):
        # revisão de 16/11 (achado da verificação): a ordem também tem de liberar "janela"
        if ordem_janela(ordens, par, quarto, x) is None:
            return (f"a ordem {par.get('ordem_id')} não vale para {quarto} em {x.isoformat()} (ou não libera "
                    "'janela')")
    mud = p.get("mudancas")
    if not isinstance(mud, dict) or d.isoformat() not in mud:
        return f"a janela da prova não muda a noite {d.isoformat()}"
    if p.get("fonte") != "beds24":
        return "prova sem fonte beds24 (o inventário do Beds24 é a fonte da janela)"
    if not hoje - UM_DIA <= ph <= hoje:
        return f"prova de {ph.isoformat()} (hoje {hoje.isoformat()}): leia o inventário de novo"
    if noite < hoje:
        return f"a noite da janela {noite.isoformat()} já passou"
    # revisão de 16/11 (achado da verificação): a busca usa a estadia máxima da config, nunca a da prova (com
    # max_noites 1 a prova "provava" presas onde não há janela)
    mx = int(par.get("estadia_maxima_simulada") or MAX_NOITES)
    try:
        mx_prova = int(p["max_noites"]) if p.get("max_noites") is not None else mx
    except (TypeError, ValueError):
        mx_prova = None
    if mx_prova != mx:
        return (f"prova com max_noites {p.get('max_noites')!r}; a regra usa {mx} "
                "(parametros.vender_janelas.estadia_maxima_simulada)")
    regra = p.get("regra")
    if not isinstance(regra, dict) or not coberta(regra, noite, ph, mx):
        return "o snapshot da regra não cobre as noites da busca (noite − 6 a noite + 7)"
    try:
        livres, va, vd, pr = (int(p[k]) for k in ("livres", "vendavel_antes", "vendavel_depois", "presas"))
    except (KeyError, TypeError, ValueError):
        return "prova incompleta (livres, vendavel_antes, vendavel_depois, presas)"
    antes = presas_da_noite(regra, noite, ph, mx)
    if antes["livres"] != livres or antes["vendavel"] != va or antes["presas"] != pr or pr <= 0:
        return (f"o vendável recalculado não bate (livres {antes['livres']}, vendável {antes['vendavel']}, presas "
                f"{antes['presas']}; a prova diz {livres}, {va}, {pr})")
    cands = [c for c in candidatos(cfg, quarto, noite, regra) if c["mudancas"] == mud]
    if not cands:
        return "as mudanças da prova não são uma abertura mínima da noite (estadia 1, entrada ou saída no sábado)"
    depois = presas_da_noite(com_mudancas(regra, mud), noite, ph, mx)
    if depois["vendavel"] != vd or vd <= va:
        return f"o vendável depois da abertura não bate ({depois['vendavel']}; a prova diz {vd})"
    # revisão de 16/11 (achado da verificação): a abertura tem de ser a que a regra escolhe (`escolher`: a primeira,
    # na ordem de menor desvio, que zera ou mais reduz as presas), não qualquer uma que venda mais (ex.: sábado com
    # estadia 1 (b2) onde a entrada no sábado com estadia 2 (b1) já bastava)
    esc = escolher(cfg, quarto, noite, regra, ph, mx, antes, pular=lambda c: any(
        motivo_exclusao(cfg, ordens, quarto, _d(x), ph, regra) for x in c["mudancas"] if x != noite.isoformat()))
    if esc is None or esc[0]["mudancas"] != mud:
        return (f"a abertura da prova ({p.get('codigo')}) não é a que a regra escolhe na noite "
                f"({esc[0]['codigo'] + ': ' + esc[0]['abertura'] if esc else 'nenhuma'}; a primeira que zera ou mais "
                "reduz as presas)")
    risco = _risco(regra, noite, ph)
    if (p.get("vizinha_risco") or None) != risco:
        return f"vizinha de risco {p.get('vizinha_risco')} (pela regra: {risco})"
    if par.get("trava_fragmentacao") and risco and pr < int(risco["livres"]):
        return f"trava: presas {pr} < livres da vizinha {risco['data']} ({risco['livres']})"
    for x in mud:
        mot = motivo_exclusao(cfg, ordens, quarto, _d(x), hoje, regra)
        if mot:
            return f"noite {x} excluída da janela ({mot})"
    alvo_ms = (mud.get(d.isoformat()) or {}).get("minStay")
    if alvo_ms is None:
        return f"a janela não muda a estadia de {d.isoformat()} (só a chegada/saída no Beds24)"
    if it.get("min_stay") is None or int(it["min_stay"]) != int(alvo_ms[1]):
        return f"min_stay do item {it.get('min_stay')} diferente do da janela ({alvo_ms[1]})"
    if it.get("check_in") is not None or it.get("check_out") is not None:
        return "a janela não mexe em chegada/saída por data no PriceLabs (v1: Afrodite só estadia)"
    return None


def _avaliada(atual: dict | None, quarto: str, noite: str) -> bool:
    """A rodada `atual` avaliou a noite do quarto: janelas avaliadas, a noite no horizonte dela, o quarto na leitura
    (`quartos`; arquivo antigo sem a chave: todos) e a noite com cobertura do inventário (fora de `sem_cobertura`)."""
    if not atual or atual.get("avaliadas") is False or not atual.get("horizonte"):
        return False
    h0, h1 = atual["horizonte"][0], atual["horizonte"][1]
    if not h0 <= noite <= h1:
        return False
    if atual.get("quartos") is not None and quarto not in atual["quartos"]:
        return False
    return (quarto, noite) not in {(x["quarto"], x["noite"]) for x in atual.get("sem_cobertura") or []}


def _sumiram(anterior: dict | None, atual: dict | None) -> list[dict]:
    """As janelas abertas ou mantidas na rodada anterior que não estão abertas nem mantidas nesta, sem as noites que
    já passaram (não fecharam: passaram)."""
    if not anterior:
        return []
    agora = {(x["quarto"], x["noite"]) for k in ("abrir", "mantidas") for x in (atual or {}).get(k) or []}
    hoje = str((atual or {}).get("hoje") or "")
    return [x for k in ("abrir", "mantidas") for x in anterior.get(k) or []
            if (x["quarto"], x["noite"]) not in agora and x["noite"] >= hoje]


def fechadas(anterior: dict | None, atual: dict | None) -> list[dict]:
    """Janelas abertas ou mantidas na rodada anterior (`--janelas-json` dela) que sumiram nesta: voltam à regra.
    Revisão de 16/11 (achado da verificação): só as que esta rodada avaliou (`_avaliada`); a noite que passou não
    entra e a que esta rodada não avaliou (sem cobertura, fora do horizonte, quarto fora da leitura) vai a
    `nao_reavaliadas` (antes o relatório dizia "fechada" de uma janela que só ficou sem cobertura)."""
    return [x for x in _sumiram(anterior, atual) if _avaliada(atual, x["quarto"], x["noite"])]


def nao_reavaliadas(anterior: dict | None, atual: dict | None) -> list[dict]:
    """As janelas da rodada anterior que esta rodada não avaliou (nada muda nelas: a regra normal não as fecha)."""
    return [x for x in _sumiram(anterior, atual) if not _avaliada(atual, x["quarto"], x["noite"])]


def linha_relatorio(x: dict) -> str:
    viz = x.get("vizinha_risco")
    return (f"{x['quarto']:9} {x['noite']} {x['dia']}: {x['livres']} livre(s), vendável {x['vendavel_antes']}"
            + (f" -> {x['vendavel_depois']}" if x.get("vendavel_depois") is not None else "")
            + f", presas {x['presas']}"
            + (f"; {x['abertura']}" if x.get("abertura") else "")
            + (f"; vizinha {viz['data'][8:10]}/{viz['data'][5:7]} com {viz['livres']}" if viz else "")
            + (f"; {x['motivo']}" if x.get("motivo") else ""))


def resumo(jan: dict | None) -> str:
    """Uma linha para o relatório da rotina."""
    if not jan:
        return "Janelas (dono 16/11): não avaliadas"
    ab = ", ".join(f"{x['quarto']} {x['noite'][8:10]}/{x['noite'][5:7]} ({x['codigo']})" for x in jan.get("abrir") or [])
    return (f"Janelas (dono 16/11): {len(jan.get('abrir') or [])} a abrir" + (f" ({ab})" if ab else "")
            + f", {len(jan.get('mantidas') or [])} mantidas, {len(jan.get('observar') or [])} a observar, "
            f"{len(jan.get('excluidas') or [])} de pacote/dono, {len(jan.get('sem_cobertura') or [])} sem cobertura "
            f"(horizonte {jan['horizonte'][0]} a {jan['horizonte'][1]})")


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    rl = sub.add_parser("relatorio", help="só relatório: janelas a abrir, mantidas, a observar e de pacote/dono")
    rl.add_argument("--hoje", required=True)
    rl.add_argument("--inventario", required=True, help="beds24.py inventario --json (de hoje − 1 a hoje + 68, ou mais)")
    rl.add_argument("--inventario-lido-em")
    rl.add_argument("--ate", help="última noite (padrão: hoje + horizonte_dias)")
    rl.add_argument("--json", help="grava o resultado (o mesmo formato do --janelas-json do calendario_12m)")
    rl.add_argument("--anterior", help="--janelas-json da rodada anterior: lista as janelas que fecharam (voltam à regra)")
    a = ap.parse_args(argv)
    import calendario_12m as c12   # import tardio (o calendario_12m importa este módulo)
    cfg = carregar_quartos()
    hoje = data(a.hoje)
    inv, lido_em = c12.ler_inventario(a.inventario, a.inventario_lido_em)
    ate = data(a.ate) if a.ate else hoje + dt.timedelta(days=int(parametros(cfg).get("horizonte_dias") or 60))
    jan = c12.calcular_janelas(cfg, inv, hoje, lido_em, carregar_ordens(), ate)
    if jan is None:
        print("Janelas (dono 16/11): regra desligada (parametros.vender_janelas.ativa) ou inventário vazio")
        return 0
    print(resumo(jan))
    for titulo, k in (("A abrir", "abrir"), ("Mantidas", "mantidas"), ("A observar", "observar"),
                      ("De pacote/dono (nunca abrem)", "excluidas")):
        if jan.get(k):
            print(f"{titulo}:")
            for x in jan[k]:
                print("  " + linha_relatorio(x))
    if jan.get("sem_cobertura"):
        # revisão de 16/11: a noite sem cobertura não é avaliada; o lote não fecha nela o que uma janela abre
        print(f"Sem cobertura do inventário (janela não avaliada; o lote não fecha nelas a estadia 1 nem a entrada/saída "
              f"aberta no sábado): {len(jan['sem_cobertura'])} noite(s), de "
              f"{min(x['noite'] for x in jan['sem_cobertura'])} a {max(x['noite'] for x in jan['sem_cobertura'])}")
    if a.anterior:
        with open(a.anterior, encoding="utf-8") as f:
            ant = json.load(f)
        fe = fechadas(ant, jan)
        print(f"Fechadas nesta rodada (voltam à regra): {len(fe)}"
              + (": " + ", ".join(f"{x['quarto']} {x['noite']}" for x in fe) if fe else ""))
        nr = nao_reavaliadas(ant, jan)
        if nr:
            # revisão de 16/11: sem cobertura ou fora do horizonte nesta leitura: não é fechamento
            print(f"Não reavaliadas nesta rodada (sem cobertura do inventário ou fora do horizonte; a regra não as "
                  f"fecha): {len(nr)}: " + ", ".join(f"{x['quarto']} {x['noite']}" for x in nr))
    if a.json:
        with open(a.json, "w", encoding="utf-8") as f:
            json.dump(jan, f, ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

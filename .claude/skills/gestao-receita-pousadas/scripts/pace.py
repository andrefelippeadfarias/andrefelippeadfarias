"""Motor de pace (30/10/2026): UMA função de preço por suíte-noite, para "o maior valor de estadia com os 2 hotéis lotados".

Pedido do dono (30/10): "Melhore nossas estratégias para atingirmos o maior valor de estadia porém estar sempre com os 2
hotéis lotados." Diagnóstico do mesmo dia: 9 das 11 camadas de regra só subiam o preço; o Recanto era o mais caro da Booking
em 9 de 13 janelas (1,8 a 2,6× a mediana com hidro) com dezembro a 7-12%; o ADR realizado ficava 16-24% abaixo do mercado.

Como decide, noite a noite (D = dias até a noite):
 1. Ocupação da noite (mu_occupancy do recálculo; suítes de 1-2 unidades usam a ocupação das suítes com banheira da pousada).
 2. Curva de pace esperada por tipo de noite (dom-qui, sex-sáb, feriado), em % vendido por D (parametros.pace.curvas),
    terminando em 100% em D0. Status = vendido − curva(D): à frente (≥ +banda), no ritmo, atrás (≤ −banda) ou muito atrás
    (≤ −muito_atras, ou zero vendida perto da noite).
 3. Âncora de mercado da noite (dados/ancora_mercado.json: percentis do preço PÚBLICO por noite dos hotéis com hidro na
    Booking). Alvo = percentil que depende do status e da pousada (parametros.pace.alvo): o Recanto um degrau acima da Villa.
    Alvo enviado = alvo público ÷ parametros.fator_publico da pousada. Noite à frente da curva nunca desce (segura ou sobe).
 4. Passo = k × distância relativa até o alvo, limitado por D (pmax: >30 d 5%, 8-30 d 10%, 4-7 d 15%, 0-3 d 20%); pela
    metade quando a âncora é pequena (n < min_n) ou velha. UM passo por noite por dia (dados/passos.json): sem segundo passo
    na mesma direção em 24 h ou enquanto o anterior não chegou ao canal. Hoje (D0) nunca sobe.
 5. Pisos do dono (R$ 500 dom-qui e R$ 850 sex-sáb no Genius 3; feriado R$ 800 real; limites de segurança; mínimo do anúncio)
    valem por baixo: o motor para no piso e marca a noite "presa no piso", com o percentil em que o piso nos deixa.
 6. Sobe pelo piso (min_price) e tira o teto; desce baixando o piso e, se o algoritmo do PriceLabs estiver acima do alvo, com
    teto (max_price) no alvo. Nunca piso = teto. Depois, coerência entre suítes: Panorâmica II entre Moinhos e Panorâmica I,
    Afrodite acima das outras (+20% dom-qui, +5% sex-sáb e feriado), Balcony 10% abaixo da suíte com banheira livre (Regra G).
 7. Fora do motor: noite com ordem do dono por data ou mudança manual dele (dados/ordens_dono.json), noite esgotada ou com
    overbooking (Regra J, enquanto vigente), quarto com `fora_do_motor` (Panorâmica II com tarifa fixa na Booking), noite
    bloqueada sem reserva (lista em --bloqueios).
Modo sombra (parametros.motor.modo = "sombra" ou --sombra): calcula e relata, não grava passos nem é para gravar no PriceLabs.

Uso:
  python3 pace.py plano --hoje AAAA-MM-DD Q7=<rec> Double=<rec> ... [--ancora dados/ancora_mercado.json]
      [--ordens dados/ordens_dono.json] [--passos dados/passos.json] [--bloqueios bloqueios.json] [--dias 60]
      [--saida plano.json] [--md relatorio.md] [--sombra] [--noites Q7=2026-11-10..2026-11-11 ...] [--sem-registro]
      (fora da sombra o plano grava no ledger os passos que traz: grave no PriceLabs o plano inteiro; para gravar só
      parte, use --sem-registro e depois `registrar`; na rotina leve, --noites limita os passos às noites com venda nova)
  python3 pace.py registrar --plano <itens gravados.json> [--agora AAAA-MM-DDTHH:MMZ]
  python3 pace.py curva --tipo fds --dias 7
  python3 pace.py marcar-envio --ate AAAA-MM-DDTHH:MM  (marca como chegados ao canal os passos gravados antes do envio)
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import math
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ancora_mercado import ARQ_ANCORA, ancora_da_noite, percentil_de, tipo_noite, valor_percentil  # noqa: E402
from calendario_12m import estadia_da_noite, ler_recalculo  # noqa: E402
from comum import PASTA_SKILL, carregar_quartos, data, esgotado, hoje_brt, ler_json, periodo_feriado, vendidas_total  # noqa: E402
from ordens import (ARQ_ORDENS, alvo_da_ordem, carregar_ordens, congelada, ordens_por_data, piso_da_ordem,  # noqa: E402
                    vendidas_base_escada)

ARQ_PASSOS = PASTA_SKILL / "dados" / "passos.json"
STATUS = ("frente", "ritmo", "atras", "muito_atras")


# ---------- parâmetros
def par_pace(cfg: dict) -> dict:
    p = cfg["parametros"].get("pace") or {}
    return {
        "curvas": p.get("curvas") or {
            "semana": {"60": 3, "30": 8, "14": 15, "7": 28, "3": 42, "1": 55, "0": 100},
            "fds": {"60": 8, "30": 18, "14": 32, "7": 50, "3": 68, "1": 85, "0": 100},
            "feriado": {"60": 12, "30": 30, "14": 50, "7": 68, "3": 82, "1": 92, "0": 100}},
        "banda": p.get("banda", 20), "muito_atras": p.get("muito_atras", 30),
        "alvo": p.get("alvo") or {
            "Recanto": {"frente_perto": 90, "frente": 75, "ritmo": 65, "atras": 50, "muito_atras": 40},
            "Villa": {"frente_perto": 75, "frente": 65, "ritmo": 50, "atras": 40, "muito_atras": 25}},
        "frente_perto_dias": p.get("frente_perto_dias", 7),
        "k": p.get("k", 0.5),
        "pmax": p.get("pmax") or {"31+": 5, "8-30": 10, "4-7": 15, "0-3": 20},
        "min_n": p.get("min_n", 6), "idade_max_dias": p.get("idade_max_dias", 10),
        "tolerancia": p.get("tolerancia", 0.03),
        # dono 01/11: "vamos aumentando o valor gradualmente de acordo com que as datas que estão feitas as reservas forem
        # entrando": o percentil alvo sobe com a ocupação da noite (p_status + (p_topo − p_status) × vendidas/unidades)
        "escada_vendas": p.get("escada_vendas") or {"ativa": False, "p_topo": 90},
    }


def fator_publico(cfg: dict, pousada: str) -> float:
    fp = cfg["parametros"].get("fator_publico") or {}
    if pousada in fp:
        return float(fp[pousada])
    return float(cfg["parametros"]["regra_j"]["fator_publico"][pousada])


# ---------- curva, status, alvo, passo (funções puras, testadas)
def curva(cfg: dict, tipo: str, dias: int) -> float:
    """% vendido esperado a `dias` da noite, por interpolação linear entre os pontos da curva do tipo."""
    pontos = sorted(((int(k), float(v)) for k, v in par_pace(cfg)["curvas"][tipo].items()), reverse=True)
    if dias >= pontos[0][0]:
        return pontos[0][1]
    for (d1, v1), (d0, v0) in zip(pontos, pontos[1:]):
        if d0 <= dias <= d1:
            return v0 + (v1 - v0) * (dias - d0) / (d1 - d0) if d1 > d0 else v0
    return pontos[-1][1]


def status_da_noite(cfg: dict, tipo: str, dias: int, vendidas: int, unidades: int) -> tuple[str, float, float]:
    """(status, vendido %, curva %)."""
    p = par_pace(cfg)
    vend = 100.0 * vendidas / unidades if unidades else 0.0
    esp = curva(cfg, tipo, dias)
    gap = vend - esp
    if vendidas <= 0 and ((tipo == "semana" and dias <= 7) or (tipo != "semana" and dias <= 14)):
        return "muito_atras", vend, esp
    if gap <= -p["muito_atras"]:
        return "muito_atras", vend, esp
    if gap <= -p["banda"]:
        return "atras", vend, esp
    if gap >= p["banda"]:
        return "frente", vend, esp
    return "ritmo", vend, esp


def percentil_alvo(cfg: dict, pousada: str, status: str, dias: int) -> float:
    p = par_pace(cfg)
    tab = p["alvo"][pousada]
    if status == "frente" and dias <= p["frente_perto_dias"]:
        return float(tab["frente_perto"])
    return float(tab[status])


def pmax(cfg: dict, dias: int) -> float:
    t = par_pace(cfg)["pmax"]
    if dias > 30:
        return float(t["31+"])
    if dias >= 8:
        return float(t["8-30"])
    if dias >= 4:
        return float(t["4-7"])
    return float(t["0-3"])


def passo(cfg: dict, atual: float, alvo: float, dias: int, fraco: bool = False) -> float:
    """Novo preço enviado: k × distância relativa, limitado por pmax(D) (pela metade com âncora fraca)."""
    p = par_pace(cfg)
    lim = pmax(cfg, dias) / (2 if fraco else 1) / 100.0
    rel = p["k"] * (alvo - atual) / atual if atual else 0.0
    rel = max(-lim, min(lim, rel))
    return atual * (1 + rel)


def pisos_enviados(cfg: dict, curto: str, d: date) -> tuple[float, str]:
    """(piso enviado que vale na noite, origem): o maior entre mínimo do anúncio, limite de segurança, pisos do dono de
    vitrine (Genius 3), piso de feriado (real; vitrine na Balcony) e exceções do dono ao limite."""
    q, lim, par = cfg["por_curto"][curto], cfg["limites_seguranca"], cfg["parametros"]
    candidatos = [(float(q["min"]), "mínimo do anúncio"),
                  (round(lim["fracao_min_referencia"] * q["min_referencia"], 2), "limite de segurança")]
    fer = periodo_feriado(cfg, d)
    if fer:
        real = fer.get("piso_real_por_quarto", {}).get(curto, fer.get("piso_real")) or lim["piso_feriado_real_seguranca"]
        real = max(float(real), float(lim["piso_feriado_real_seguranca"]))
        fator = q["fator_vitrine"] if q.get("piso_feriado_base") == "vitrine" else q["fator_real"]
        candidatos.append((real / fator, f"piso de feriado R$ {real:.0f} real"))
    elif q.get("banheira"):
        fds = d.weekday() in (4, 5)
        chave = "piso_vitrine_fds_suites" if fds else "piso_vitrine_semana_suites"
        fat = par["fator_vitrine_fds" if fds else "fator_vitrine_semana"][q["pousada"]]
        candidatos.append((float(lim[chave]) / fat, f"piso do dono R$ {lim[chave]} no Genius 3"))
    piso, origem = max(candidatos)
    for ex in lim.get("excecoes_dono", []) or []:
        if ex.get("quarto") == curto and data(ex["de"]) <= d <= data(ex["ate"]):
            piso, origem = min(piso, float(ex["limite"])), origem + f" (exceção do dono até {ex['ate']})"
    return round(piso, 2), origem


def regra_afrodite(cfg: dict, cals: dict, finais: dict, d: date, hoje: date) -> tuple[float, float | None]:
    """(piso, teto) da ordem do dono para a Afrodite na noite: acima da mais cara das outras suítes do Recanto à venda
    (+20% dom-qui, +5% sex-sáb e feriado) e, em sexta e sábado fora de feriado até hoje + 95 dias, na faixa alta do
    mercado (vitrine R$ 1.600 a 1.800 ÷ fator público). teto None = sem teto pela regra."""
    from ordem_recanto import faixa_fds_afrodite, horizonte_teto_fds, premio_afrodite
    noite = d.isoformat()
    outras = []
    for k in ("Q7", "Double", "Q2"):
        r = (cals.get(k) or {}).get(noite)
        if r is not None and not esgotado(r, cfg["por_curto"][k]["unidades"]):
            outras.append(finais.get((k, noite), float(r["price"])))
    piso = max(outras) * (1 + premio_afrodite(cfg, d)) if outras else 0.0
    teto = None
    fx = faixa_fds_afrodite(cfg, d) if d <= horizonte_teto_fds(cfg, hoje) else None
    if fx:
        piso, teto = max(piso, float(fx[0])), float(fx[1])
    return piso, teto


def efetivo(cfg: dict, curto: str, reais: float, para_baixo: bool = False) -> float:
    """Valor que o PriceLabs aplica para um piso (para cima) ou teto (para baixo) gravado em percent_base: a base do
    anúncio × (1 + p/100), p inteiro (validar_plano.piso_pct/teto_pct). A coerência compara valores efetivos."""
    base = float(cfg["por_curto"][curto]["base"])
    pct = reais / base * 100 - 100
    p = math.floor(pct + 1e-9) if para_baixo else math.ceil(pct - 1e-9)
    return round(base * (1 + p / 100), 2)


def limite_seguranca(cfg: dict, curto: str, d: date) -> float:
    """Limite de segurança do quarto na noite (só o dono muda): fração do mínimo de referência, ou a exceção do dono."""
    q, lim = cfg["por_curto"][curto], cfg["limites_seguranca"]
    v = float(lim["fracao_min_referencia"]) * float(q["min_referencia"])
    for ex in lim.get("excecoes_dono", []) or []:
        if ex.get("quarto") == curto and data(ex["de"]) <= d <= data(ex["ate"]):
            v = min(v, float(ex["limite"]))
    return v


def piso_com_ordem(cfg: dict, curto: str, d: date, ordens: dict) -> tuple[float, str, dict | None]:
    """Piso do motor na noite: com ordem do dono do tipo piso_por_data, o piso dela SUBSTITUI os pisos padrão (nunca
    abaixo do limite de segurança); sem ordem, pisos_enviados."""
    po = piso_da_ordem(ordens, curto, d)
    if po:
        return max(po[0], limite_seguranca(cfg, curto, d)), f"piso da ordem do dono {po[1]['id']}", po[1]
    piso, origem = pisos_enviados(cfg, curto, d)
    return piso, origem, None


# ---------- ledger de passos
def carregar_passos(caminho=None) -> dict:
    p = Path(caminho or ARQ_PASSOS)
    if not p.exists():
        return {"_doc": "Um passo por noite por dia (regras.md, motor de pace). chegou_ao_canal é marcado às 10:53 pelo "
                        "last_date_pushed ou pela conferência na Booking 30 min depois do sync.", "passos": []}
    return ler_json(p)


def ultimo_passo(passos: dict, curto: str, noite: str) -> dict | None:
    xs = [x for x in passos.get("passos", []) if x["quarto"] == curto and x["noite"] == noite]
    return max(xs, key=lambda x: x["gravado_em"]) if xs else None


def cadencia_permite(passos: dict, curto: str, noite: str, direcao: str, agora: dt.datetime,
                     vendidas: int | None = None, base: str = "quarto", total: int | None = None) -> tuple[bool, str]:
    u = ultimo_passo(passos, curto, noite)
    if not u or u["direcao"] != direcao:
        return True, ""
    # escada de vendas (dono, 01/11): reserva nova na noite libera mais um passo para cima no mesmo dia (e cancelamento,
    # mais um para baixo), sem esperar as 24 h nem o envio do passo anterior. A contagem só compara com a do mesmo tipo
    # (`base`: "quarto", ou "pousada" nas suítes de 1-2 unidades, cuja reta anda com a ocupação da pousada); passo
    # antigo sem `base` conta como "quarto" (revisão 01/11: comparar 2/9 da Villa com 0/2 da King Spa 2 liberava passo falso)
    mesmo_total = total is None or u.get("total") is None or int(u["total"]) == int(total)  # calendários parciais (2ª revisão)
    if vendidas is not None and u.get("vendidas") is not None and (u.get("base") or "quarto") == base and mesmo_total:
        if (direcao == "sobe" and vendidas > int(u["vendidas"])) or (direcao == "desce" and vendidas < int(u["vendidas"])):
            return True, ""
    gravado = dt.datetime.fromisoformat(u["gravado_em"].replace("Z", "+00:00"))
    if gravado.tzinfo is None:
        gravado = gravado.replace(tzinfo=dt.timezone.utc)
    if not u.get("chegou_ao_canal"):
        return False, f"passo anterior ({u['direcao']}, {u['gravado_em'][:16]}) ainda não chegou ao canal"
    if (agora - gravado) < dt.timedelta(hours=24):
        return False, f"já houve passo {u['direcao']} há menos de 24 h ({u['gravado_em'][:16]})"
    return True, ""


def ordem_nova_sem_passo(passos: dict, curto: str, noite: str, ordem: dict, hoje: dt.date) -> bool:
    """Ordem do dono criada hoje vale na hora, mesmo com passo do motor hoje na noite (dono, 01/11) — mas uma vez só:
    exige `criada_em` (instante UTC) na ordem e nenhum passo da noite gravado depois dele. Sem `criada_em`, a
    cadência normal manda (evita que toda rodada do dia dê mais um passo pela mesma ordem)."""
    if not ordem or ordem.get("data") != hoje.isoformat() or not ordem.get("criada_em"):
        return False
    criada = dt.datetime.fromisoformat(str(ordem["criada_em"]).replace("Z", "+00:00"))
    if criada.tzinfo is None:
        criada = criada.replace(tzinfo=dt.timezone.utc)
    for x in passos.get("passos", []):
        if x.get("quarto") != curto or x.get("noite") != noite:
            continue
        g = dt.datetime.fromisoformat(str(x["gravado_em"]).replace("Z", "+00:00"))
        if g.tzinfo is None:
            g = g.replace(tzinfo=dt.timezone.utc)
        if g >= criada:
            return False
    return True


def registrar_passos(passos: dict, itens: list[dict], agora: dt.datetime, caminho=None) -> None:
    for it in itens:
        pc = it.get("pace") or {}
        if not pc.get("direcao"):
            continue
        passos.setdefault("passos", []).append({
            "quarto": it["quarto"], "noite": it["data"], "de": pc["atual"], "para": pc["novo"], "direcao": pc["direcao"],
            "status": pc["status"], "vendidas": pc.get("vendidas"), "base": pc.get("base", "quarto"), "total": pc.get("total"),
            "gravado_em": agora.isoformat(timespec="minutes"), "chegou_ao_canal": False})
    with open(caminho or ARQ_PASSOS, "w", encoding="utf-8") as f:
        json.dump(passos, f, ensure_ascii=False, indent=1)
        f.write("\n")


def marcar_envio(passos: dict, ate: dt.datetime, caminho=None) -> int:
    n = 0
    for x in passos.get("passos", []):
        g = dt.datetime.fromisoformat(x["gravado_em"].replace("Z", "+00:00"))
        if g.tzinfo is None:
            g = g.replace(tzinfo=dt.timezone.utc)
        if not x.get("chegou_ao_canal") and g <= ate:
            x["chegou_ao_canal"] = ate.isoformat(timespec="minutes")
            n += 1
    with open(caminho or ARQ_PASSOS, "w", encoding="utf-8") as f:
        json.dump(passos, f, ensure_ascii=False, indent=1)
        f.write("\n")
    return n


# ---------- o plano
def ocupacao_pousada(cfg: dict, cals: dict, noite: str) -> dict:
    out = defaultdict(lambda: [0, 0])
    for curto, cal in cals.items():
        q = cfg["por_curto"][curto]
        r = cal.get(noite)
        if r and q.get("banheira"):
            v, t = vendidas_total(r, q["unidades"])
            out[q["pousada"]][0] += min(v, t)
            out[q["pousada"]][1] += t
    return out


def vigente(cfg: dict, curto: str, r: dict, regra: dict | None, campo: str) -> float | None:
    """Piso (`min_price`) ou teto (`max_price`) vigente da noite em R$: o do recálculo; sem ele (a coleta resumida não
    traz piso nem teto, 31/10), o da regra por data vigente (percent_base = base × (1 + p/100); fixed em R$); senão None."""
    v = r.get(campo)
    if v not in (None, "") and float(v) > 0:
        return float(v)
    v = (regra or {}).get(campo)
    if v in (None, ""):
        return None
    tipo = (regra or {}).get(f"{campo}_type") or "percent_base"
    if tipo == "percent_base":
        return round(float(cfg["por_curto"][curto]["base"]) * (1 + float(v) / 100), 2)
    if tipo == "fixed":
        return float(v)
    return None


def noite_fraca(ao: dict, st: str, vendidas: int, unidades: int) -> bool:
    """Ordem alvo_por_data (dono, 01/11): a noite do quarto entra na base do mercado só se for fraca. Fica com o motor
    (e com os pisos padrão do dono) a noite com `ocupacao_max` (70%) ou mais vendido, ou à frente da curva com
    `frente_ocupacao` (50%) ou mais."""
    oc = vendidas / unidades if unidades > 0 else 0.0
    return not (oc >= float(ao.get("ocupacao_max", 0.7)) or (st == "frente" and oc >= float(ao.get("frente_ocupacao", 0.5))))


def percentil_com_vendas(pct: float, oc: float, topo: float) -> float:
    """Escada de vendas (dono, 01/11): cada reserva na noite aproxima o percentil alvo do topo, na proporção vendida.
    0% vendido = percentil do status; 100% = topo (p90, o mesmo da proteção da noite esgotada). Nunca desce o percentil."""
    oc = max(0.0, min(1.0, oc))
    return pct + (topo - pct) * oc if topo > pct else pct


def piso_com_vendas(piso_ordem: float, piso_padrao: float, oc: float) -> float:
    """Ordem de base do mercado com `escada_vendas` (dono, 01/11): o piso sai do piso da ordem (limite de segurança) e
    sobe com a ocupação até o piso padrão do dono, sem salto quando a noite passa a vender bem."""
    oc = max(0.0, min(1.0, oc))
    return piso_ordem + (max(piso_padrao, piso_ordem) - piso_ordem) * oc


def piso_atras_da_ordem(cfg: dict, ao: dict | None, curto: str, d: date, k: int, vendidas: int,
                        base: int | None) -> float | None:
    """Dono 03/11 ("1. Sim"): ordem de régua com hidro com `piso_atras` {curto: reais} e `gatilho_dias` (7). A noite a até
    `gatilho_dias` dias sem reserva nova desde a ordem (`vendidas_base`; sem base, nenhuma vendida) desce do piso da
    ordem (p65 com hidro) ao `piso_atras` (p50 com hidro), nunca abaixo do piso padrão do dono, salvo ordem que libera
    `abaixo_piso` (dias de semana de novembro, ordem de 01/11). None quando o gatilho não vale."""
    if not ao or curto not in (ao.get("piso_atras") or {}):
        return None
    if k > int(ao.get("gatilho_dias", 7)):
        return None
    if vendidas > (base if base is not None else 0):
        return None
    if ao.get("gatilho_min_dias") and ao.get("criada_em"):
        # revisão de 04/11 (ordens de faixa): só depois de `gatilho_min_dias` dias de ordem; a noite criada a D7 ou
        # menos não cai na hora para a faixa de baixo (King Spa 7 11/11 iria de R$ 814 a 710 à frente da curva)
        from zoneinfo import ZoneInfo
        criada = dt.datetime.fromisoformat(str(ao["criada_em"]).replace("Z", "+00:00"))
        if criada.tzinfo is None:
            criada = criada.replace(tzinfo=dt.timezone.utc)
        criada = criada.astimezone(ZoneInfo("America/Sao_Paulo")).date()  # dia da ordem no fuso de hoje (BRT)
        if ((d - dt.timedelta(days=k)) - criada).days < int(ao["gatilho_min_dias"]):
            return None
    pa = float(ao["piso_atras"][curto])
    if "abaixo_piso" not in (ao.get("libera") or []):
        pa = max(pa, pisos_enviados(cfg, curto, d)[0])
    return pa


def degrau_atras_da_ordem(cfg: dict, ao: dict | None, curto: str, d: date, k: int, vend_pct: float,
                          esp: float, atual: float | None = None, reta=None) -> tuple[float, int] | None:
    """Dono 04/11 ("Para feriados, vamos aplicar o F5 e baixar gradualmente caso a lotação esteja abaixo da expectativa
    7 dias antes"): ordem com `degraus_atras` {curto: [piso F4, F3, F2, F1 enviados]} e `degraus_dias` [7, 5, 3, 1].
    A partir de D7, com o vendido abaixo da curva esperada da noite, o piso desce um degrau por marco atingido (D7 → 1º,
    D5 → 2º...), nunca abaixo do piso padrão do dono, salvo ordem que libera `abaixo_piso`. (piso, degrau) ou None."""
    dg = ((ao or {}).get("degraus_atras") or {}).get(curto)
    if not dg:
        return None
    dias = sorted((int(x) for x in ao.get("degraus_dias", [7, 5, 3, 1])), reverse=True)
    n = sum(1 for x in dias if k <= x)
    if n == 0 or vend_pct >= esp:
        return None
    n = min(n, len(dg))
    if atual is not None:
        # revisão de 04/11: um degrau por vez (a noite que fica abaixo da curva só perto da data não pula da F5 à F1);
        # o degrau atual é o último que o preço já alcançou (2% de folga do arredondamento)
        ja = sum(1 for x in dg if float(reta(float(x)) if reta else x) >= float(atual) * 0.98)
        n = min(n, ja + 1)
    v = float(dg[n - 1])
    if "abaixo_piso" not in (ao.get("libera") or []):
        v = max(v, pisos_enviados(cfg, curto, d)[0])
    return v, n


def limite_volta_degrau(cfg: dict, ao: dict | None, curto: str, d: date, k: int, atual: float,
                        piso: float) -> float | None:
    """Revisão de 04/11: ordem com descida gradual, noite dentro da janela dos degraus (D7 ou menos), acima da curva
    de novo e com o preço abaixo do piso da faixa: o piso sobe no máximo o limite de passo da antecedência (pmax) por
    vez, nunca abaixo do piso padrão do dono. None quando não se aplica."""
    if not ao or not ((ao.get("degraus_atras") or {}).get(curto)):
        return None
    dias = [int(x) for x in ao.get("degraus_dias", [7, 5, 3, 1])]
    if not dias or k > max(dias) or atual >= piso:
        return None
    lim = max(atual * (1 + pmax(cfg, k) / 100.0), pisos_enviados(cfg, curto, d)[0])
    return lim if lim < piso else None


def teto_anuncio(cfg: dict, curto: str) -> float:
    """Maior piso que o PriceLabs aplica sem passar do máximo do anúncio (piso em % inteiro da base, para baixo). No
    Recanto, em cadeia: Panorâmica I, depois Panorâmica II até ela, depois Moinhos até a Panorâmica II (revisão de
    04/11: no Natal a Panorâmica II ia a R$ 5.024 e a Panorâmica I a R$ 5.017, acima do máximo de R$ 5.000)."""
    def topo(k: str, lim: float) -> float:
        return efetivo(cfg, k, min(float(cfg["por_curto"][k]["max"]), lim), para_baixo=True)
    if curto in ("Q7", "Q2", "Double") and all(k in cfg["por_curto"] for k in ("Q7", "Q2", "Double")):
        t = topo("Double", float("inf"))
        if curto == "Double":
            return t
        t = topo("Q2", t)
        return t if curto == "Q2" else topo("Q7", t)
    return topo(curto, float("inf"))


def limitar_ao_maximo(cfg: dict, itens: list[dict]) -> None:
    """Passe final (revisão de 04/11): piso efetivo nunca acima do máximo do anúncio (nem a coerência, que copia o valor
    efetivo da Moinhos para a Panorâmica II e a Panorâmica I) e teto nunca acima dele."""
    for it in itens:
        if it.get("tipo") != "data" or it.get("min_price") in (None, ""):
            continue
        k = it["quarto"]
        t = teto_anuncio(cfg, k)
        if efetivo(cfg, k, float(it["min_price"])) > t + 0.01:
            it["min_price"] = math.floor(t)
            it["motivo"] = (it.get("motivo", "") + f"; no máximo do anúncio (R$ {t:.0f} efetivos)")[:250]
        no_topo = efetivo(cfg, k, float(it["min_price"])) >= t - 0.01 * float(cfg["por_curto"][k]["base"])
        if no_topo and not it.get("protecao") and it.get("max_price") not in (None, "") \
                and float(it["max_price"]) > t + 0.01:
            # piso no topo da cadeia: o teto vai junto (Panorâmica II no Natal: com teto no máximo do anúncio, o teto
            # era solto e o algoritmo a levava a R$ 5.000, acima da Panorâmica I); proteção de esgotada segue livre
            it["max_price"] = float(max(math.floor(t), float(it["min_price"])))


def sem_alvo(ordens: dict) -> dict:
    """As ordens sem as do tipo alvo_por_data: o piso da ordem de base do mercado só vale na noite fraca (revisão 01/11)."""
    return {k: o for k, o in ordens.items() if o.get("tipo") != "alvo_por_data"}


def protecao_esgotada(cfg: dict, p: dict, anc: dict, origem: str, curto: str, d: date, r: dict, v: int, u: int,
                      regra: dict | None = None, piso_extra: float | None = None,
                      teto_regra: float | None = None) -> dict | None:
    """Noite esgotada (ou com overbooking) com a Regra J desligada (dono, 31/10): piso de proteção no percentil
    `pace.protecao_percentil` (90) da âncora, nunca abaixo dos pisos do dono, com o teto livre (nunca piso = teto).
    Devolve o item só quando o piso vigente difere ou quando há teto (Regra J ou outro) a soltar."""
    q = cfg["por_curto"][curto]
    fp = fator_publico(cfg, q["pousada"])
    pct = float(p.get("protecao_percentil", 90))
    pub = valor_percentil(anc, pct)
    # pisos PADRÃO do quarto: o piso de venda das ordens por data (R$ 300 no Genius 3) é para vender noite vazia, não
    # para proteger noite vendida (31/10: a Afrodite de 08/11 iria a R$ 937)
    piso_dono, _ = pisos_enviados(cfg, curto, d)
    teto = float(teto_regra) if teto_regra else float(q["max"])
    piso = min(max(pub / fp, piso_dono, float(piso_extra or 0)), teto)
    atual = float(r["price"])
    base = float(q["base"])
    atual_min = vigente(cfg, curto, r, regra, "min_price")
    if atual_min is None:
        # sem piso no calendário (coleta resumida) nem regra na data, o piso efetivo é desconhecido (o PriceLabs pode ter
        # piso de fim de semana próprio, ex.: Panorâmica I a 150% da base): não grava; a rodada com recálculo protege
        return None
    teto_cal = vigente(cfg, curto, r, regra, "max_price") or float(q["max"])
    tem_teto = teto_cal < float(q["max"]) - 1 or bool(regra and regra.get("max_price") not in (None, ""))
    # 31/10 (revisão da rotina das 07:53): a proteção só SOBE o piso; piso vigente igual ou acima (com tolerância) e sem
    # teto a soltar fica como está (noite vendida com piso mais alto não perde nada e evita regravar todo dia)
    if atual_min >= piso * (1 - p["tolerancia"]) and not tem_teto:
        return None
    it = {"tipo": "data", "quarto": curto, "data": d.isoformat(), "min_price": math.ceil(piso), "max_price": float(q["max"]),
            "preco_antes": atual, "protecao": True,
            "motivo": (f"Proteção da noite esgotada ({v}/{u}; Regra J desligada pelo dono em 31/10): piso no p{pct:.0f} do "
                       f"mercado com hidro, R$ {pub:.0f} público = R$ {piso:.0f} enviados ({origem}); teto livre")}
    if teto_regra:
        it["max_price"] = math.floor(max(teto, it["min_price"]))
        it["motivo"] = it["motivo"].replace("teto livre", f"teto da faixa do dono R$ {it['max_price']}")
    # o teto vai em percent_base arredondado para baixo em 1% da base: diferença menor que 1% da base já é o mesmo teto
    if teto_regra and atual_min >= piso * (1 - p["tolerancia"]) and abs(teto_cal - it["max_price"]) <= base * 0.01 + 1:
        return None
    return it


def sem_efeito(cfg: dict, itens: list[dict], cals: dict, regras: dict, apagar: set) -> tuple[list[dict], list[dict]]:
    """(itens, nulos): tira o item que trava piso = teto num valor que já é o vigente (31/10: a Balcony de 04/11 desceu
    pelo pace e voltou a R$ 723,90 pela Regra G, o mesmo de antes). Gravar não muda nada e o ledger registraria um passo
    que não houve, travando o passo verdadeiro do dia. Só vale para item com piso e teto, sem apagar nem min_stay novo."""
    fica, nulos = [], []
    for it in itens:
        k = (it["quarto"], it["data"])
        if it.get("max_price") in (None, "") or k in apagar or it.get("min_stay") is not None:
            fica.append(it)
            continue
        r = (cals.get(it["quarto"]) or {}).get(it["data"]) or {}
        rg = (regras.get(it["quarto"]) or {}).get(it["data"])
        cur_min, cur_max = vigente(cfg, it["quarto"], r, rg, "min_price"), vigente(cfg, it["quarto"], r, rg, "max_price")
        novo = max(efetivo(cfg, it["quarto"], float(it["min_price"])),
                   efetivo(cfg, it["quarto"], float(it["max_price"]), para_baixo=True))
        if cur_min is not None and cur_max is not None and abs(novo - cur_min) <= 1 and abs(novo - cur_max) <= 1:
            nulos.append(it)
        else:
            fica.append(it)
    return fica, nulos


def plano(cfg: dict, ancora: dict, cals: dict, hoje: date, ordens: dict, passos: dict, bloqueios: set | None = None,
          dias: int = 60, agora: dt.datetime | None = None, regra_j_ativa: bool = True,
          regras: dict | None = None, noites_filtro: set | None = None) -> dict:
    """Itens de preço (formato do validar_plano.py) e o diagnóstico por noite para o relatório.
    `regras`: {curto: {data: override vigente}} (get_listing_date_overrides), usado pela proteção da noite esgotada.
    `noites_filtro`: {(quarto, data)} da rotina leve (noites com reserva nova ou cancelamento): só elas recebem passo de
    pace e proteção; os outros quartos e noites seguem nos calendários para a coerência (revisão 01/11: passo que não
    seria gravado ficava no ledger e travava a cadência da noite)."""
    p = par_pace(cfg)
    regras = regras or {}
    cals = preco_no_vigente(cfg, cals, regras)
    agora = agora or dt.datetime.now(dt.timezone.utc)
    bloqueios = bloqueios or set()
    itens, linhas, fora = [], [], []
    finais: dict = {}
    for k in range(0, dias + 1):
        d = hoje + dt.timedelta(days=k)
        noite = d.isoformat()
        tipo = tipo_noite(cfg, d)
        anc, origem = ancora_da_noite(ancora, cfg, d)
        ocup_p = ocupacao_pousada(cfg, cals, noite)
        for curto in ("Q7", "Double", "Q2", "Afrodite", "VK7", "VK2", "Balcony"):
            cal = cals.get(curto)
            r = cal.get(noite) if cal else None
            if r is None:
                continue
            q = cfg["por_curto"][curto]
            u = q["unidades"]
            v, t = vendidas_total(r, u)
            atual = float(r["price"])
            regra_n = (regras.get(curto) or {}).get(noite)
            # coleta resumida sem piso/teto: vale a regra vigente; sem regra, o mínimo do anúncio (nunca 0, revisão 31/10)
            piso_vig = vigente(cfg, curto, r, regra_n, "min_price") or float(q["min"])
            teto_vig = vigente(cfg, curto, r, regra_n, "max_price") or float(q["max"])
            motivo_fora = None
            if q.get("fora_do_motor"):
                motivo_fora = f"quarto fora do motor ({q.get('fora_do_motor_nota', 'ver quartos.json')})"
            elif (curto, noite) in bloqueios:
                motivo_fora = "noite bloqueada sem reserva (Beds24)"
            elif congelada(ordens, curto, d):
                motivo_fora = f"mudança manual do dono vigente ({congelada(ordens, curto, d)['id']})"
            elif ordens_por_data(ordens, curto, d):
                motivo_fora = "ordem do dono por data (" + ", ".join(o["id"] for o in ordens_por_data(ordens, curto, d)) + ")"
            elif esgotado(r, u):
                if regra_j_ativa:
                    motivo_fora = "esgotada: Regra J (proteção)"
                elif anc is None:
                    motivo_fora = "esgotada sem âncora de mercado (sem piso de proteção novo)"
                else:
                    extra = teto_r = None
                    if curto == "Afrodite":
                        extra, teto_r = regra_afrodite(cfg, cals, finais, d, hoje)
                    # 04/11 (Panorâmica II 08-12/11 esgotada a R$ 1.220 com a régua com hidro em R$ 1.409-1.624; King Spa 2
                    # 12/12 a R$ 1.479 com a régua da Villa em R$ 2.059): a proteção não fica abaixo do piso da ordem por
                    # data da noite (o p90 da âncora geral pode ser menor que o p65 com hidro); só sobe (max), então o
                    # piso baixo das ordens de base do mercado não muda nada
                    ao_p = alvo_da_ordem(ordens, curto, d)
                    if ao_p and (ao_p.get("pisos_enviados") or {}).get(curto):
                        extra = max(float(extra or 0), float(ao_p["pisos_enviados"][curto]))
                    it = protecao_esgotada(cfg, p, anc, origem, curto, d, r, v, u, (regras.get(curto) or {}).get(noite),
                                           extra, teto_r)
                    filtrada = noites_filtro is not None and (curto, noite) not in noites_filtro
                    if filtrada:
                        it = None  # rotina leve: a proteção desta noite fica para a rodada completa
                    if it and curto == "Afrodite":
                        it["excecao"], it["ordem_id"] = "dono", "dono-2026-10-22-afrodite-mais-cara"
                    if it:
                        itens.append(it)
                    motivo_fora = "esgotada: piso de proteção p90" + (" (item)" if it else (" (fora do filtro --noites)"
                                                                                           if filtrada else " (já protegida)"))
            if motivo_fora:
                fora.append({"quarto": curto, "data": noite, "motivo": motivo_fora, "atual": atual, "vendidas": v, "unidades": u})
                continue
            if anc is None:
                fora.append({"quarto": curto, "data": noite, "motivo": "sem âncora de mercado", "atual": atual, "vendidas": v, "unidades": u})
                continue
            # suítes de 1-2 unidades: ocupação das suítes com banheira da pousada (0/50/100% enganam)
            vv, tt = (v, u)
            if u <= 2 and ocup_p[q["pousada"]][1]:
                vv, tt = ocup_p[q["pousada"]]
            st, vend_pct, esp = status_da_noite(cfg, tipo, k, vv, tt)
            fp = fator_publico(cfg, q["pousada"])
            pct = percentil_alvo(cfg, q["pousada"], st, k)
            ao = alvo_da_ordem(ordens, curto, d)
            esc = p["escada_vendas"]
            oc = (vv / tt) if tt else 0.0
            esc_on = bool(esc.get("ativa"))
            escada_ordem = esc_on and bool(ao) and bool(ao.get("escada_vendas"))
            if escada_ordem:
                # dono 01/11 ("aumentando o valor gradualmente ... as reservas forem entrando"): a noite fica na ordem e
                # sobe uma reta por reserva, da base do mercado (p50, piso da ordem) ao p90 e ao piso padrão do dono
                usa_ordem = True
                oc_esc = oc
                vb = vendidas_base_escada(ordens, curto, d)
                if vb and tt:
                    # régua nova (dono 02/11): a escada parte da base do mercado e sobe só com as reservas que entraram
                    # depois da troca; as vendidas de antes já estavam no preço (cancelamento abaixo da base: fica no p50)
                    oc_esc = max(0, vv - vb[0]) / max(1, tt - vb[0])
                pct = percentil_com_vendas(float(ao.get("percentil", 50)), oc_esc, float(esc.get("p_topo", 90)))
            else:
                # ordem do dono (01/11, base do mercado): vale para a noite fraca do quarto. Fica com o motor a noite que
                # vende bem: `ocupacao_max` (70%) ou mais vendido, ou à frente da curva com `frente_ocupacao` (50%) ou mais
                usa_ordem = bool(ao) and noite_fraca(ao, st, v, u)
                if usa_ordem:
                    pct = float(ao.get("percentil", 50))  # a base do mercado (p50), nem acima nem abaixo
                elif esc.get("ativa"):
                    pct = percentil_com_vendas(pct, oc, float(esc.get("p_topo", 90)))
            alvo_pub = valor_percentil(anc, pct)
            alvo_env = alvo_pub / fp
            idade = (hoje - data(anc.get("busca_em", hoje.isoformat()))).days
            fraco = int(anc.get("n") or 0) < p["min_n"] or idade > p["idade_max_dias"]
            if st == "frente" and not usa_ordem:
                alvo_env = max(alvo_env, atual)  # noite vendendo à frente da curva nunca desce: segura ou sobe (RevPAR)
            novo = passo(cfg, atual, alvo_env, k, fraco)
            if usa_ordem and ao.get("passo_livre") and alvo_env < atual and not escada_ordem:
                novo = alvo_env  # ordem do dono (01/11): direto à base do mercado, sem o limite de passo diário
            # noite que vende bem fica com os pisos padrão (ou piso_por_data): o piso da base do mercado é só da noite fraca
            piso, origem_piso, ordem_piso = piso_com_ordem(cfg, curto, d, ordens if usa_ordem or not ao else sem_alvo(ordens))
            reta = None
            if escada_ordem:
                pa = piso_atras_da_ordem(cfg, ao, curto, d, k, vv, vb[0] if vb else None)
                if pa is not None and pa < piso:
                    piso, ordem_piso = pa, ordem_piso or ao
                    origem_atras = f"gatilho de D{int(ao.get('gatilho_dias', 7))} sem venda nova (dono 03/11): p50 com hidro"
                else:
                    origem_atras = None
                # topo da rampa calculado antes (revisão de 04/11): o degrau já alcançado é medido contra a reta de cada
                # degrau (o preço fica na reta, acima do degrau, quando entra venda depois da ordem)
                piso_pad = max(piso_com_ordem(cfg, curto, d, sem_alvo(ordens))[0], pisos_enviados(cfg, curto, d)[0],
                               float((ao.get("topo_enviados") or {}).get(curto) or 0))
                dga = degrau_atras_da_ordem(cfg, ao, curto, d, k, vend_pct, esp, atual,
                                            reta=lambda x: max(alvo_env, piso_com_vendas(x, piso_pad, oc_esc)))
                degrau_on = False
                if dga is not None and dga[0] < piso:
                    piso, ordem_piso = dga[0], ordem_piso or ao
                    degrau_on = True
                    origem_atras = (f"descida gradual (dono 04/11): D{k}, {vend_pct:.0f}% vendido contra {esp:.0f}% esperado, "
                                    f"{dga[1]}º degrau abaixo da faixa")
                else:
                    lim_v = limite_volta_degrau(cfg, ao, curto, d, k, atual, piso)
                    if lim_v is not None:
                        # revisão de 04/11: a noite que volta à curva depois de descer sobe aos poucos (pmax da
                        # antecedência), não de uma vez à faixa (D3: R$ 2.800 -> 4.000 seria +43%)
                        piso = lim_v
                        origem_atras = f"volta à faixa depois da descida gradual: até +{pmax(cfg, k):.0f}% por vez (D{k})"
                # topo da rampa (dono 01/11; revisão 01/11): o piso padrão do dono (R$ 500/850 no Genius 3), mesmo quando
                # uma ordem piso_por_data da semana o suspende (senão piso = topo e a escada fica plana: King Spa 7 05/11),
                # ou o `topo_enviados` da ordem (festas da Villa: do alvo de hoje ao preço de antes, pelas vendas)
                # revisão de 03/11: a reta usa a mesma ocupação da escada (oc_esc: só as reservas depois de
                # `vendidas_base`); com a ocupação inteira, a noite que entrava numa ordem nova com vendas antigas subia
                # um passo por dia até a reta sem nenhuma venda nova (Panorâmica I 13-14/11: R$ 2.708 -> 2.979 -> 3.340)
                reta = max(alvo_env, piso_com_vendas(piso, piso_pad, oc_esc))
                if reta >= atual:
                    # sobe aos poucos: no máximo o limite de passo da antecedência (pmax) por vez; cada reserva nova
                    # libera mais um passo no mesmo dia (cadência por venda)
                    novo = min(reta, atual * (1 + pmax(cfg, k) / 100.0))
                elif noite_fraca(ao, st, vv, tt) or degrau_on:
                    # revisão de 04/11: na suíte de 1-2 unidades, a ocupação da pousada (a mesma do status); com a do
                    # quarto (0/1) a Afrodite era sempre "fraca" e caía com o Recanto à frente da curva
                    # cancelamento, noite fraca ou descida gradual do feriado (dono 04/11: "baixar gradualmente caso a
                    # lotação esteja abaixo da expectativa", mesmo com 70% ou mais vendido): direto à reta
                    novo = reta
                else:
                    novo = atual  # a noite que já vende bem nunca desce para a reta
                origem_piso = (f"escada de vendas da ordem {ao['id']} ({vv}/{tt} vendidas; reta R$ {reta:.0f}, piso "
                               f"padrão R$ {piso_pad:.0f})" + (f"; {origem_atras}" if origem_atras else ""))
            po = (piso, ordem_piso) if ordem_piso else None
            teto_af = None
            regra_af = False
            if curto == "Afrodite":
                piso_af, teto_af = regra_afrodite(cfg, cals, finais, d, hoje)
                if piso_af > piso:  # toda noite: acima da mais cara das outras (+20%/+5%); sex/sáb também a faixa
                    piso, regra_af = piso_af, True
                    origem_piso = ("faixa de fim de semana da Afrodite (dono 27/10)" if teto_af
                                   else "Afrodite acima da mais cara das outras (dono 22 e 27/10)")
                if teto_af:
                    novo = min(novo, max(teto_af, piso))
            if usa_ordem and ao.get("so_desce") and novo > atual:
                novo = atual  # ordem do dono só para baixar (01/11, Consciência Negra): nunca sobe a noite por ela
            preso = None
            if novo < piso:
                novo, preso = piso, f"presa no piso ({origem_piso} = R$ {piso:.0f} enviados, público ~R$ {piso * fp:.0f}, percentil {percentil_de(piso * fp, anc):.0f})"
            capado = novo >= teto_anuncio(cfg, curto) - 0.01
            novo = min(novo, teto_anuncio(cfg, curto))
            if k == 0 and novo > atual:
                novo = atual  # hoje nunca sobe
            novo_cmp = efetivo(cfg, curto, novo) if escada_ordem and novo > atual else novo
            # escada (revisão 01/11): o degrau compara o valor que o PriceLabs aplica (piso arredondado para cima em 1% da
            # base); com o valor cru, o degrau de uma reserva (ex.: R$ 755 → 777, 2,9%) ficava abaixo da tolerância e sumia
            direcao = "sobe" if novo_cmp > atual * (1 + p["tolerancia"]) else ("desce" if novo < atual * (1 - p["tolerancia"]) else None)
            novo_r = math.ceil(novo) if direcao == "sobe" else (math.floor(novo) if direcao == "desce" else round(novo))
            if capado:
                novo_r = math.floor(novo)  # no máximo do anúncio: o piso em % inteiro da base nunca passa dele
            linha = {"quarto": curto, "data": noite, "D": k, "tipo": tipo, "vendidas": v, "unidades": u, "vendido_pct": round(vend_pct),
                     "curva_pct": round(esp), "status": st, "percentil_alvo": pct, "alvo_publico": round(alvo_pub),
                     "alvo_enviado": round(alvo_env), "atual": atual, "atual_publico": round(atual * fp),
                     "percentil_atual": percentil_de(atual * fp, anc), "novo": novo_r, "direcao": direcao,
                     "preso_no_piso": preso, "ancora": origem, "ancora_fraca": fraco}
            linhas.append(linha)
            if not direcao:
                continue
            if noites_filtro is not None and (curto, noite) not in noites_filtro:
                linha["cadencia"] = "fora do filtro da rotina leve (--noites)"
                continue
            base_cont = "pousada" if (vv, tt) != (v, u) else "quarto"
            ok, por_que = cadencia_permite(passos, curto, noite, direcao, agora, vv if esc_on else None, base_cont, tt)
            if not ok and usa_ordem and ordem_nova_sem_passo(passos, curto, noite, ao, hoje):
                ok, por_que = True, ""  # ordem nova do dono vale na hora (01/11: "1 . Sim / 2. Sim"), uma vez por noite
            if not ok:
                linha["cadencia"] = por_que
                continue
            it = {"tipo": "data", "quarto": curto, "data": noite, "min_price": novo_r, "preco_antes": atual,
                  "motivo": (f"Pace ({st.replace('_', ' ')}: {v}/{u} vendidas, curva {esp:.0f}% a D{k}); alvo p{pct:.0f} "
                             f"R$ {alvo_pub:.0f} público = R$ {alvo_env:.0f} enviados ({origem}); antes R$ {atual:.0f}"),
                  "pace": {"status": st, "direcao": direcao, "atual": atual, "novo": novo_r, "alvo_enviado": round(alvo_env),
                           "vendidas": vv, "base": base_cont, "total": tt}}
            if escada_ordem:
                it["motivo"] = (f"Escada de vendas (dono 01/11): {vv}/{tt} vendidas; reta R$ {reta:.0f} (p{pct:.0f} R$ "
                                f"{alvo_pub:.0f} público); degrau até R$ {novo_r}; antes R$ {atual:.0f}")
            if direcao == "sobe":
                # revisão de 31/10: teto solto deixava o algoritmo pular além do passo (VK7 04/11: R$ 710 → R$ 1.100+);
                # sobe pelo piso com o teto no alvo do motor (nunca abaixo do piso novo)
                it["max_price"] = float(max(novo_r, math.floor(min(alvo_env, float(q["max"])))))
            elif atual > novo_r + 1 and (atual > piso_vig + 1 or teto_vig < float(q["max"]) - 1):
                # o algoritmo está acima do alvo, ou a noite tem teto (escada/Regra J antigas: piso = teto): o teto desce
                # junto, senão o preço fica preso no teto antigo ou no algoritmo
                it["max_price"] = novo_r
            if usa_ordem and ao.get("passo_livre") and (direcao == "desce" or escada_ordem):
                it["max_price"] = novo_r  # ordem do dono: preço travado no degrau (sem teto o algoritmo pula a escada)
            if po:
                it["excecao"], it["ordem_id"] = "dono", po[1]["id"]
            if teto_af or regra_af:
                it["excecao"], it["ordem_id"] = "dono", "dono-2026-10-22-afrodite-mais-cara"
            if teto_af:
                if it.get("max_price") is None or it["max_price"] > teto_af:
                    it["max_price"] = math.floor(max(teto_af, it["min_price"]))  # teto da faixa; descida com teto menor fica
            itens.append(it)
            finais[(curto, noite)] = efetivo(cfg, curto, float(it["min_price"]))
    itens = coerencia(cfg, cals, finais, itens, hoje, ordens, bloqueios, dias, ancora, noites_filtro)
    limitar_ao_maximo(cfg, itens)
    sincronizar_pace(itens, p["tolerancia"])
    return {"hoje": hoje.isoformat(), "itens": itens, "linhas": linhas, "fora": fora}


def preco_no_vigente(cfg: dict, cals: dict, regras: dict) -> dict:
    """Calendário velho (ex.: o da manhã na rotina leve) com o preço dentro do piso/teto das regras por data VIGENTES
    (get_listing_date_overrides): o preço do recálculo antigo não vê o que foi gravado depois dele (revisão 01/11: a Regra
    G com a King Spa de antes das 21:03 desfazia as descidas da Balcony). Com recálculo novo não muda nada."""
    if not regras:
        return cals
    out = {}
    for k, cal in cals.items():
        rk = regras.get(k) or {}
        if not rk or not cal:
            out[k] = cal
            continue
        novo = {}
        for noite, r in cal.items():
            g = rk.get(noite)
            if not g or r is None:
                novo[noite] = r
                continue
            preco = float(r["price"])
            lo = vigente(cfg, k, {}, g, "min_price")
            hi = vigente(cfg, k, {}, g, "max_price")
            x = preco
            if lo is not None and x < lo - 0.5:
                x = lo
            if hi is not None and x > hi + 0.5:
                x = max(hi, lo or 0)
            if abs(x - preco) >= 0.5:
                r = dict(r, price=x, preco_recalculo=preco)
            novo[noite] = r
        out[k] = novo
    return out


def sincronizar_pace(itens: list[dict], tol: float) -> None:
    """Depois da coerência (ordem do Recanto, Regra G, Afrodite), o `pace` do item passa a dizer o que vai ser gravado:
    `novo` = piso final e `direcao` pelo preço de antes. Sem mudança real, o passo sai do ledger (revisão 01/11: a
    Panorâmica I subia pela Moinhos e o ledger registrava 'desce', travando a descida dela por 24 h)."""
    for it in itens:
        pc = it.get("pace")
        if not pc or it.get("min_price") is None:
            continue
        final, atual = float(it["min_price"]), float(pc["atual"])
        if abs(final - float(pc["novo"])) < 0.5:
            continue
        pc["novo_motor"] = pc["novo"]
        pc["novo"] = final
        pc["direcao"] = "sobe" if final > atual * (1 + tol) else ("desce" if final < atual * (1 - tol) else None)


def ajustar_tetos(cfg: dict, itens: list[dict], regras: dict, cals: dict | None = None) -> tuple[list[dict], list[dict]]:
    """Teto livre no PriceLabs = item SEM max_price (a API mescla campos e um max_price "igual ao máximo do anúncio"
    seria um percentual enorme e inútil). Para cada item com max_price no máximo do anúncio: se a regra vigente da
    noite tem teto ou validade (lead_time_expiry), a noite vai para a lista `apagar` (plano separado, gravado antes) e
    o item herda min_stay e check-in/out da regra apagada; senão o max_price só sai do item. Devolve (itens, apagar).
    Dono 04/11 (desde 05/11), com `cals` (recálculos): onde a regra do dia de chegada decide a noite (fora de feriado,
    pacote e sábado), a estadia regravada é a dela (calendario_12m.estadia_da_noite), não a herdada: em 04/11 a
    proteção da sexta 06/11 da Panorâmica II regravou a estadia 1 antiga; a regra sem estadia voltaria à do PriceLabs."""
    apagar, saida = [], []
    for it in itens:
        it = dict(it)
        q = cfg["por_curto"][it["quarto"]]
        reg = (regras.get(it["quarto"]) or {}).get(it.get("data")) if it.get("tipo") == "data" else None
        teto_reg = None
        if reg and reg.get("max_price") not in (None, ""):
            try:
                teto_reg = float(q["base"]) * (1 + float(reg["max_price"]) / 100)
            except (TypeError, ValueError):
                teto_reg = None
        solta = it.get("max_price") is not None and float(it["max_price"]) >= float(q["max"]) - 1
        if not solta and it.get("max_price") is None and it.get("min_price") is not None and teto_reg is not None \
                and float(it["min_price"]) > teto_reg + 1:
            solta = True  # piso novo acima do teto vigente: o teto antigo sai (senão piso > teto no PriceLabs)
            it["max_price"] = float(q["max"])
        if solta:
            it.pop("max_price")
            if reg and (reg.get("max_price") not in (None, "") or reg.get("lead_time_expiry")):
                apagar.append({"tipo": "apagar", "quarto": it["quarto"], "data": it["data"],
                               "motivo": f"solta o teto (R$ {em_reais_pct(q, reg.get('max_price'))}) antes de regravar o piso: {it.get('motivo', '')[:120]}"})
                for campo in ("min_stay", "check_in", "check_out"):
                    if reg.get(campo) not in (None, "") and it.get(campo) is None:
                        it[campo] = int(reg[campo]) if campo == "min_stay" else reg[campo]
                if cals is not None:
                    novo, orfa, prova = estadia_da_noite(cfg, it["quarto"], data(it["data"]), cals.get(it["quarto"]) or {},
                                                         it.get("min_stay"))
                    if novo is not None:
                        it["min_stay"] = novo
                        if orfa and novo == 1:
                            it.update(orfa=orfa, orfa_prova=prova)
        saida.append(it)
    return saida, apagar


def em_reais_pct(q: dict, pct) -> str:
    try:
        return f"{float(q['base']) * (1 + float(pct) / 100):.0f}"
    except (TypeError, ValueError):
        return "?"


def ler_regras(pares: list[str]) -> dict:
    """{curto: {data: override}} a partir de Q7=overrides.json ... (saída de get_listing_date_overrides)."""
    out = {}
    for x in pares:
        k, v = x.split("=", 1)
        d = ler_json(v)
        lst = ((d.get("data") or {}).get("overrides")) if isinstance(d.get("data"), dict) else None
        lst = lst or d.get("overrides") or (d if isinstance(d, list) else [])
        out[k] = {o["date"]: o for o in lst if o.get("date")}
    return out


def coerencia(cfg: dict, cals: dict, finais: dict, itens: list[dict], hoje: date, ordens: dict,
              bloqueios: set | None = None, dias: int = 60, ancora: dict | None = None,
              noites_filtro: set | None = None) -> list[dict]:
    """Depois do motor: Panorâmica II entre Moinhos e Panorâmica I; Afrodite acima das outras (só nas noites em que o
    motor mexeu no Recanto); Balcony 10% abaixo da suíte com banheira livre (Regra G) em TODAS as noites de D0 a `dias`
    (regras.md: varredura de 60 dias; revisão de 31/10: Balcony a 99% da King Spa 7 em 14 e 17/12, noites que o motor
    não mexeu). No feriado a Regra G só baixa a Balcony (nunca sobe por causa dela), sem passar do piso de feriado."""
    bloqueios = bloqueios or set()
    from ordem_recanto import premio_afrodite
    esc = par_pace(cfg)["escada_vendas"]
    esc_on = bool(esc.get("ativa"))
    por_chave = {(it["quarto"], it["data"]): it for it in itens}
    desc = cfg["parametros"].get("regra_g_desconto", 0.10)

    def preco(k, noite):
        r = cals.get(k, {}).get(noite)
        return None if r is None else finais.get((k, noite), float(r["price"]))

    def livre(k, noite):
        # fora_do_motor tira o quarto do PASSO de pace, não das regras de ordem do dono (Panorâmica II segue entre as duas)
        r = cals.get(k, {}).get(noite)
        return r is not None and not esgotado(r, cfg["por_curto"][k]["unidades"])

    def sobra(k, noite):
        r = cals.get(k, {}).get(noite)
        if r is None:
            return 0
        v, t = vendidas_total(r, cfg["por_curto"][k]["unidades"])
        return max(0, t - v)

    def ajustar(k, noite, piso=None, teto=None, motivo="", ordem_id=None):
        d = data(noite)
        if congelada(ordens, k, d) or ordens_por_data(ordens, k, d) or not livre(k, noite):
            return
        r = cals[k][noite]
        atual = preco(k, noite)
        it = por_chave.get((k, noite))
        if it is None and (piso is None or atual >= piso - 1) and (teto is None or atual <= teto + 1):
            return  # regra já cumprida: não mexe (31/10: um item novo baixava o piso vigente da Afrodite de R$ 2.720 para R$ 1.810)
        po = piso_da_ordem(ordens, k, d)
        if po:
            # a ordem por data do dono é a que libera piso abaixo dos pisos padrão naquela noite (validador: 'abaixo_piso')
            ordem_id = po[1]["id"]
        if it is None:
            it = {"tipo": "data", "quarto": k, "data": noite, "preco_antes": float(r["price"]), "motivo": motivo}
            if ordem_id:
                it["excecao"], it["ordem_id"] = "dono", ordem_id
            por_chave[(k, noite)] = it
            itens.append(it)
        elif po and it.get("ordem_id") != po[1]["id"]:
            it["excecao"], it["ordem_id"] = "dono", po[1]["id"]
        if piso is not None and piso > (it.get("min_price") or 0):
            it["min_price"] = math.ceil(piso)
            if efetivo(cfg, k, it["min_price"]) < piso - 0.01:  # nunca abaixo depois do arredondamento
                it["min_price"] = math.ceil(efetivo(cfg, k, piso))
            if it.get("max_price") is not None and it["max_price"] < it["min_price"]:
                it["max_price"] = it["min_price"]
            ao_k = alvo_da_ordem(ordens, k, d)
            if esc_on and ao_k and ao_k.get("escada_vendas") and it.get("max_price") is None and teto is None:
                # noite na escada de vendas (dono 01/11): teto no degrau, senão o algoritmo do PriceLabs pula a escada;
                # com teto informado (Panorâmica II), vale o teto (revisão 01/11: a trava prendia a Q2 na Moinhos)
                it["max_price"] = it["min_price"]
        if teto is not None and (it.get("max_price") is None or teto < it["max_price"]):
            it["max_price"] = math.floor(max(teto, it.get("min_price") or 0))
            if it.get("min_price") is None:
                # piso vigente acima do teto novo deixaria piso > teto no PriceLabs (a API mescla os campos)
                it["min_price"] = min(math.floor(float(r.get("min_price") or teto)), it["max_price"])
        if motivo and motivo not in it["motivo"]:
            it["motivo"] = (it["motivo"] + "; " + motivo)[:250]
        finais[(k, noite)] = efetivo(cfg, k, float(it["min_price"])) if it.get("min_price") else float(atual)

    def livre_v(k, noite):
        return livre(k, noite) and (k, noite) not in bloqueios

    noites = {n for (_, n) in finais} | {n for n in (cals.get("Balcony") or {}) if 0 <= (data(n) - hoje).days <= dias}
    if noites_filtro is not None:
        # rotina leve (revisão 01/11): só as noites do filtro; a varredura de 60 noites da Regra G fica com a rodada completa
        noites = {n for (_, n) in finais} | {n for (_, n) in noites_filtro}
    for noite in sorted(noites):
        d = data(noite)
        if any((k, noite) in finais for k in ("Q7", "Double", "Q2", "Afrodite")):
            a, b = preco("Q7", noite), preco("Double", noite)
            # a última unidade da Moinhos (preço de fim de estoque) não puxa a Panorâmica I para cima (31/10); antes da
            # Panorâmica II, que fica entre as duas já ajustadas (01/11: a Panorâmica II ficava abaixo das duas)
            if a is not None and b is not None and a > b + 1 and livre("Double", noite) and sobra("Q7", noite) >= 2:
                ajustar("Double", noite, piso=a, motivo=f"Ordem do Recanto: Panorâmica I não fica abaixo da Moinhos (R$ {a:.0f})")
                b = preco("Double", noite)
            refs_q2 = [x for k2, x in (("Q7", a), ("Double", b)) if x is not None and livre(k2, noite)]
            if refs_q2 and livre("Q2", noite):
                # só as suítes que ainda vendem (regras.md: a esgotada sai da ordem do Recanto; revisão 01/11: o preço velho
                # da Panorâmica I esgotada puxava a Panorâmica II para baixo da Moinhos)
                menor, maior = min(refs_q2), max(refs_q2)
                a = a if livre("Q7", noite) else None
                b = b if livre("Double", noite) else None
                # revisão de 31/10: o piso da Panorâmica II nunca fica abaixo do piso dela (dono ou ordem por data)
                ao_q2 = alvo_da_ordem(ordens, "Q2", d)
                escada_q2 = esc_on and bool(ao_q2) and bool(ao_q2.get("escada_vendas"))
                oc2 = 0.0
                forte_q2 = False
                if ao_q2:
                    # revisão 01/11: a Panorâmica II só vai à base (e ao piso da ordem) na noite fraca, como no motor
                    rq2 = cals["Q2"][noite]
                    uq2 = cfg["por_curto"]["Q2"]["unidades"]
                    vq2, _ = vendidas_total(rq2, uq2)
                    vv2, tt2 = (vq2, uq2)
                    op = ocupacao_pousada(cfg, cals, noite)["Recanto"]
                    if uq2 <= 2 and op[1]:
                        vv2, tt2 = op
                    oc2 = (vv2 / tt2) if tt2 else 0.0
                    st_q2 = status_da_noite(cfg, tipo_noite(cfg, d), (d - hoje).days, vv2, tt2)[0]
                    forte_q2 = not noite_fraca(ao_q2, st_q2, vv2, tt2)  # revisão 04/11: ocupação da pousada
                    if not escada_q2 and forte_q2:
                        ao_q2 = None
                piso_q2, _, _ = piso_com_ordem(cfg, "Q2", d, ordens if ao_q2 else sem_alvo(ordens))
                if ao_q2 and escada_q2:
                    # dono 03/11 ("1. Sim"): o gatilho de D7 sem venda nova vale também para a Panorâmica II (pela pousada)
                    vb_q2 = vendidas_base_escada(ordens, "Q2", d)
                    pa_q2 = piso_atras_da_ordem(cfg, ao_q2, "Q2", d, (d - hoje).days, vv2, vb_q2[0] if vb_q2 else None)
                    if pa_q2 is not None:
                        piso_q2 = min(piso_q2, pa_q2)
                    _, vp2, esp2 = status_da_noite(cfg, tipo_noite(cfg, d), (d - hoje).days, vv2, tt2)
                    # sem limite de um degrau aqui: a Panorâmica II fica >= Moinhos, que já desce um degrau por vez
                    dg_q2 = degrau_atras_da_ordem(cfg, ao_q2, "Q2", d, (d - hoje).days, vp2, esp2)
                    if dg_q2 is not None:
                        piso_q2 = min(piso_q2, dg_q2[0])
                        forte_q2 = False  # descida gradual (dono 04/11) vale mesmo com a pousada vendendo bem
                    else:
                        lim_q2 = limite_volta_degrau(cfg, ao_q2, "Q2", d, (d - hoje).days,
                                                     float(cals["Q2"][noite]["price"]), piso_q2)
                        if lim_q2 is not None:
                            piso_q2 = lim_q2
                # escada de vendas (dono 01/11; revisão 01/11): a Panorâmica II não sai da ordem quando a pousada vende bem
                # (antes saltava para o piso padrão, acima das duas): o piso é a Moinhos, que já sobe pela escada, e o
                # teto anda com o percentil da escada (abaixo), sempre entre Moinhos e Panorâmica I
                lo_q2 = max(menor, piso_q2)
                teto_q2 = menor + 0.75 * (maior - menor)
                if ao_q2 and ancora:
                    # ordem do dono (01/11, base do mercado): a Panorâmica II também vai à base, entre as duas
                    anc_q2, _ = ancora_da_noite(ancora, cfg, d)
                    if anc_q2:
                        pct_q2 = float(ao_q2.get("percentil", 50))
                        if escada_q2:
                            oc_q2 = oc2
                            vb2 = vendidas_base_escada(ordens, "Q2", d)
                            if vb2 and tt2:
                                # régua nova (dono 02/11): como no motor, só as reservas depois da troca sobem a escada
                                oc_q2 = max(0, vv2 - vb2[0]) / max(1, tt2 - vb2[0])
                            pct_q2 = percentil_com_vendas(pct_q2, oc_q2, float(esc.get("p_topo", 90)))
                        base_q2 = valor_percentil(anc_q2, pct_q2) / fator_publico(cfg, "Recanto")
                        teto_q2 = min(teto_q2, base_q2)
                if escada_q2 and forte_q2:
                    # noite que já vende bem nunca desce para a reta (escada): só corta o que passar da Panorâmica I
                    teto_q2 = max(teto_q2, min(float(cals["Q2"][noite]["price"]), maior))
                ma = f"R$ {a:.0f}" if a is not None else "esgotada"
                mb = f"R$ {b:.0f}" if b is not None else "esgotada"
                ajustar("Q2", noite, piso=lo_q2, teto=max(lo_q2, teto_q2),
                        motivo=f"Ordem do Recanto: Panorâmica II entre Moinhos ({ma}) e Panorâmica I ({mb})",
                        ordem_id="dono-2026-10-21-ordem-recanto")
            outras = [preco(k, noite) for k in ("Q7", "Q2", "Double") if livre(k, noite) and preco(k, noite) is not None]
            if outras and livre("Afrodite", noite):
                pr = premio_afrodite(cfg, d)
                piso_af = max(outras) * (1 + pr) + 0.5
                # revisão de 03/11: em sexta e sábado dentro do horizonte da faixa (vitrine R$ 1.600-1.800), o piso vem com o
                # teto da faixa (nunca abaixo do piso); sem ele, ajustar_tetos apagava o teto e a Afrodite ia ao preço do
                # algoritmo (04/12: R$ 3.491, ~R$ 2.126 na vitrine)
                _, teto_fx = regra_afrodite(cfg, cals, finais, d, hoje)
                ajustar("Afrodite", noite, piso=piso_af, teto=(max(teto_fx, piso_af) if teto_fx else None),
                        motivo=f"Afrodite {pr:.0%} acima da mais cara das outras (R$ {max(outras):.0f})",
                        ordem_id="dono-2026-10-22-afrodite-mais-cara")
        fer = bool(periodo_feriado(cfg, d))
        if livre_v("Balcony", noite):
            refs = [preco(k, noite) for k in ("VK7", "VK2") if livre_v(k, noite) and preco(k, noite) is not None]
            if refs:
                # Regra G (dono 07/10: "diferença de 10%"): a Balcony fica entre 95% do alvo e o alvo (10% abaixo da suíte
                # com banheira livre mais barata), como o validador exige; o passo de pace da Balcony só escolhe DENTRO
                # dessa faixa (31/10: o motor queria a Balcony 0/6 bem abaixo, a regra do dono não deixa)
                ref = min(refs)
                hi = math.floor(ref * (1 - desc))
                lo = math.ceil(0.95 * (1 - desc) * (ref + 15)) + 1  # +15: arredondamento do percentual sobre a base
                piso_b, _, ordem_b = piso_com_ordem(cfg, "Balcony", d, ordens)
                if ordem_b and piso_b > hi:
                    # revisão de 04/11: piso de ordem (faixa do dono) acima de 90% da suíte livre mais barata, que desceu
                    # pelo gatilho ou pelo degrau, ou parou no máximo do anúncio: vale a Regra G (85,5% a 90%), sem
                    # passar do piso da própria Balcony (o padrão; com 'abaixo_piso', o menor valor dela na ordem)
                    if "abaixo_piso" in (ordem_b.get("libera") or []):
                        vals = [float(piso_b)] + [float(x) for x in ((ordem_b.get("degraus_atras") or {}).get("Balcony") or [])]
                        if (ordem_b.get("piso_atras") or {}).get("Balcony"):
                            vals.append(float(ordem_b["piso_atras"]["Balcony"]))
                        lo_b = max(min(vals), limite_seguranca(cfg, "Balcony", d))
                    else:
                        lo_b = pisos_enviados(cfg, "Balcony", d)[0]
                    piso_b = lo_b
                lo = max(lo, math.ceil(piso_b))
                it = por_chave.get(("Balcony", noite))
                atual_b = preco("Balcony", noite)
                base_b = float(it["min_price"]) if it is not None and it.get("min_price") is not None else atual_b
                # escolhe o piso em % inteiro da base (como o PriceLabs aplica) dentro de [lo, hi]
                bb = float(cfg["por_curto"]["Balcony"]["base"])
                p_lo = math.ceil((lo / bb - 1) * 100 - 1e-9)
                p_hi = math.floor((hi / bb - 1) * 100 + 1e-9)
                p_b = min(max(math.ceil((base_b / bb - 1) * 100 - 1e-9), p_lo), max(p_hi, p_lo))
                if fer:
                    # feriado: só o lado de cima (até 90% da suíte), nunca abaixo do piso de feriado da Balcony
                    p_b = max(p_hi, math.ceil((piso_b / bb - 1) * 100 - 1e-9))
                novo_b = round(bb * (1 + p_b / 100), 2)
                mot = f"Regra G: Balcony {desc:.0%} abaixo da suíte com banheira livre mais barata (R$ {ref:.0f})"
                # noite sem item do motor: só mexe fora da faixa do validador (85,5% a 90% + um degrau de 1% da base),
                # para não regravar a Balcony todo dia por arredondamento (31/10: 864 = 90,8% da King Spa 7 de R$ 952)
                ok_lo, ok_hi = 0.95 * (1 - desc) * ref, ref * (1 - desc) + 0.01 * bb + 1
                if it is None and atual_b is not None and (atual_b <= ok_hi if fer else ok_lo <= atual_b <= ok_hi):
                    pass  # já na faixa da Regra G (no feriado, basta não passar dos 90%)
                elif fer and it is not None and float(it.get("min_price") or atual_b or 0) <= hi + 1:
                    pass  # feriado: item do motor já abaixo dos 90%, a Regra G não sobe a Balcony
                elif fer and novo_b >= (atual_b or 0) - 1:
                    pass  # feriado: o piso de feriado não deixa baixar
                elif livre("Balcony", noite) and not congelada(ordens, "Balcony", d) and not ordens_por_data(ordens, "Balcony", d):
                    if it is None:
                        r = cals["Balcony"][noite]
                        it = {"tipo": "data", "quarto": "Balcony", "data": noite, "preco_antes": float(r["price"]),
                              "motivo": mot, "excecao": "dono", "ordem_id": "dono-2026-10-07-regra-g"}
                        por_chave[("Balcony", noite)] = it
                        itens.append(it)
                    elif mot not in it["motivo"]:
                        it["motivo"] = (it["motivo"] + "; " + mot)[:250]
                    it["min_price"] = it["max_price"] = novo_b
                    it.setdefault("excecao", "dono")
                    it.setdefault("ordem_id", "dono-2026-10-07-regra-g")
                    finais[("Balcony", noite)] = float(novo_b)
    return itens


# ---------- relatório
def relatorio_md(cfg: dict, res: dict, sombra: bool) -> str:
    linhas, itens, fora = res["linhas"], res["itens"], res["fora"]
    cont = defaultdict(int)
    for ln in linhas:
        cont[ln["status"]] += 1
    presas = [ln for ln in linhas if ln["preso_no_piso"]]
    out = [f"# Motor de pace — {res['hoje']} ({'MODO SOMBRA: nada a gravar' if sombra else 'plano para gravar'})", ""]
    out.append(f"Noites analisadas: {len(linhas)} (à frente {cont['frente']}, no ritmo {cont['ritmo']}, atrás {cont['atras']}, "
               f"muito atrás {cont['muito_atras']}); itens de preço: {len(itens)}; noites fora do motor: {len(fora)}; "
               f"presas no piso: {len(presas)}.")
    sobe = [it for it in itens if (it.get("pace") or {}).get("direcao") == "sobe"]
    desce = [it for it in itens if (it.get("pace") or {}).get("direcao") == "desce"]
    out.append(f"Passos: {len(sobe)} para cima, {len(desce)} para baixo, {len(itens) - len(sobe) - len(desce)} de coerência.")
    fora_perto = [x for x in fora if (data(x["data"]) - data(res["hoje"])).days <= 9]
    out += ["", f"## Primeiras noites no motor (D0-D9 fora do motor: {len(fora_perto)} suíte-noites)", "",
            "| Noite | Quarto | Vend. | Curva | Status | Atual (púb., pct) | Alvo (púb.) | Novo | Obs. |", "|---|---|---|---|---|---|---|---|---|"]
    for ln in sorted(linhas, key=lambda x: (x["D"], x["quarto"]))[:16]:
        obs = ln.get("cadencia") or (ln["preso_no_piso"] or "")[:60] or ("âncora fraca" if ln["ancora_fraca"] else "")
        out.append(f"| {ln['data']} D{ln['D']} | {ln['quarto']} | {ln['vendidas']}/{ln['unidades']} | {ln['curva_pct']}% | {ln['status'].replace('_', ' ')} | "
                   f"R$ {ln['atual']:.0f} (R$ {ln['atual_publico']}, p{ln['percentil_atual']:.0f}) | p{ln['percentil_alvo']:.0f} R$ {ln['alvo_publico']} | "
                   f"R$ {ln['novo']} {('↑' if ln['direcao'] == 'sobe' else '↓' if ln['direcao'] == 'desce' else '=')} | {obs} |")
    if presas:
        out += ["", "## Presas no piso (o motor queria descer e o piso não deixou)", ""]
        por = defaultdict(list)
        for ln in presas:
            por[(ln["quarto"], ln["tipo"])].append(ln)
        for (q, tipo), xs in sorted(por.items()):
            out.append(f"- {q}, {tipo}: {len(xs)} noites; ex.: {xs[0]['data']} ({xs[0]['preso_no_piso']})")
    if fora:
        out += ["", "## Fora do motor", ""]
        por = defaultdict(int)
        for x in fora:
            por[x["motivo"].split(" (")[0]] += 1
        for m, n in sorted(por.items(), key=lambda kv: -kv[1]):
            out.append(f"- {m}: {n} noites")
    return "\n".join(out) + "\n"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    pl = sub.add_parser("plano")
    pl.add_argument("--hoje", required=True)
    pl.add_argument("recalculos", nargs="+", help="Q7=<recálculo> Double=<recálculo> ...")
    pl.add_argument("--ancora", default=str(ARQ_ANCORA))
    pl.add_argument("--ordens", default=str(ARQ_ORDENS))
    pl.add_argument("--passos", default=str(ARQ_PASSOS))
    pl.add_argument("--bloqueios", help="JSON [{quarto, data}] de noites bloqueadas sem reserva (beds24.py comparar)")
    pl.add_argument("--dias", type=int, default=60)
    pl.add_argument("--saida")
    pl.add_argument("--md")
    pl.add_argument("--sombra", action="store_true")
    pl.add_argument("--regras", nargs="*", default=[], help="Q7=overrides.json ... (get_listing_date_overrides, D0-D60); obrigatório fora da sombra")
    pl.add_argument("--saida-apagar", help="plano separado com as noites cujo teto/validade precisa ser apagado antes (padrão: <saida> com sufixo _apagar)")
    pl.add_argument("--noites", nargs="*", help="rotina leve: só estas noites recebem passo de pace (Q7=2026-11-10 ou "
                    "Q7=2026-11-10..2026-11-12; noites da reserva, sem a de saída); os outros calendários servem à coerência")
    pl.add_argument("--sem-registro", action="store_true", help="(padrão desde 01/11: o plano nunca grava o ledger; "
                    "depois de gravar no PriceLabs, rode `pace.py registrar --plano <itens gravados>`)")
    rg = sub.add_parser("registrar", help="grava no ledger os passos de pace de um plano JÁ gravado no PriceLabs")
    rg.add_argument("--plano", required=True)
    rg.add_argument("--passos", default=str(ARQ_PASSOS))
    rg.add_argument("--agora", help="instante UTC da gravação (padrão: agora)")
    cv = sub.add_parser("curva")
    cv.add_argument("--tipo", default="semana")
    cv.add_argument("--dias", type=int, required=True)
    me = sub.add_parser("marcar-envio")
    me.add_argument("--ate", required=True)
    me.add_argument("--passos", default=str(ARQ_PASSOS))
    a = ap.parse_args(argv)
    cfg = carregar_quartos()
    if a.cmd == "curva":
        print(f"{a.tipo} D{a.dias}: {curva(cfg, a.tipo, a.dias):.0f}% vendido esperado")
        return 0
    if a.cmd == "registrar":
        pl_g = ler_json(a.plano)
        if not isinstance(pl_g, dict) or not isinstance(pl_g.get("itens"), list):
            print("ERRO: --plano tem de ser o plano do pace.py (com 'itens'), não o payload do validar_plano")
            return 1
        de_pace = [x for x in pl_g.get("itens", []) if (x.get("pace") or {}).get("direcao")]
        if pl_g.get("sombra"):
            print("ERRO: plano em modo sombra não vai para o ledger")
            return 1
        agora = dt.datetime.fromisoformat(a.agora.replace("Z", "+00:00")) if a.agora else dt.datetime.now(dt.timezone.utc)
        if agora.tzinfo is None:
            agora = agora.replace(tzinfo=dt.timezone.utc)
        sincronizar_pace(de_pace, par_pace(cfg)["tolerancia"])  # plano editado à mão: o ledger fica com o valor gravado
        registrar_passos(carregar_passos(a.passos), de_pace, agora, a.passos)
        print(f"{len([x for x in de_pace if (x.get('pace') or {}).get('direcao')])} passos registrados em {a.passos}")
        return 0
    if a.cmd == "marcar-envio":
        ate = dt.datetime.fromisoformat(a.ate.replace("Z", "+00:00"))
        if ate.tzinfo is None:
            ate = ate.replace(tzinfo=dt.timezone.utc)
        n = marcar_envio(carregar_passos(a.passos), ate, a.passos)
        print(f"{n} passos marcados como chegados ao canal até {a.ate}")
        return 0
    hoje = data(a.hoje)
    if hoje != hoje_brt(cfg):
        print(f"ERRO: --hoje {hoje} não é hoje em America/Sao_Paulo ({hoje_brt(cfg)}); refaça o plano com a data do relógio")
        return 1
    cals = {}
    for par in a.recalculos:
        k, v = par.split("=", 1)
        cals[k] = ler_recalculo(v)
    ancora = ler_json(a.ancora) if Path(a.ancora).exists() else {"noites": {}, "por_tipo": {}}
    ordens = carregar_ordens(a.ordens)
    passos = carregar_passos(a.passos)
    bloq = set()
    if a.bloqueios:
        bloq = {(x["quarto"], x["data"]) for x in ler_json(a.bloqueios).get("bloqueios", [])} if isinstance(ler_json(a.bloqueios), dict) else set()
    motor = cfg["parametros"].get("motor") or {}
    sombra = a.sombra or motor.get("modo", "sombra") == "sombra"
    regras = ler_regras(a.regras) if a.regras else {}
    if not sombra and not regras:
        print("ERRO: fora da sombra o plano exige --regras Q7=<overrides.json> ... (regras por data vigentes, D0-D60): "
              "sem elas o motor não sabe que teto soltar nem que min_stay preservar")
        return 1
    filtro = ler_filtro_noites(a.noites) if a.noites is not None else None  # --noites sem valor: nenhuma noite recebe passo
    res = plano(cfg, ancora, cals, hoje, ordens, passos, bloq, a.dias, regra_j_ativa=motor.get("regra_j_ativa", True), regras=regras,
                noites_filtro=filtro)
    itens, apagar = ajustar_tetos(cfg, res["itens"], regras, cals) if regras else (res["itens"], [])
    itens, nulos = sem_efeito(cfg, itens, cals, regras, {(x["quarto"], x["data"]) for x in apagar})
    # o relatório conta só o que vai para a gravação (revisão de 31/10: dizia 9 itens com 8 gravados)
    md = relatorio_md(cfg, dict(res, itens=itens), sombra)
    if nulos:
        md += "\n## Sem efeito (já vigentes, fora do plano e do ledger)\n\n" + "".join(
            f"- {x['quarto']} {x['data']}: piso/teto R$ {x['min_price']}\n" for x in nulos)
    print(md)
    if a.md:
        Path(a.md).write_text(md, encoding="utf-8")
    for x in nulos:
        print(f"sem efeito (já vigente): {x['quarto']} {x['data']} piso/teto R$ {x['min_price']}")
    if a.saida:
        with open(a.saida, "w", encoding="utf-8") as f:
            json.dump({"hoje": res["hoje"], "itens": itens, "sombra": sombra}, f, ensure_ascii=False, indent=1)
        print(f"{len(itens)} itens em {a.saida}{' (SOMBRA: não grave)' if sombra else ''}")
        sa = a.saida_apagar or re.sub(r"\.json$", "", a.saida) + "_apagar.json"
        if apagar:
            with open(sa, "w", encoding="utf-8") as f:
                json.dump({"hoje": res["hoje"], "itens": apagar, "sombra": sombra}, f, ensure_ascii=False, indent=1)
            print(f"{len(apagar)} noites com teto/validade a apagar ANTES, em {sa} (valide e grave este plano primeiro)")
        elif Path(sa).exists():
            Path(sa).unlink()  # revisão de 04/11: um plano de apagar de rodada anterior não pode sobrar para ser gravado
            print(f"nenhuma noite a apagar (plano de apagar antigo removido: {sa})")
    de_pace = [x for x in itens if (x.get("pace") or {}).get("direcao")]
    if not sombra and de_pace:
        # revisão 01/11: o ledger só recebe o que chegou ao PriceLabs (plano barrado no validador, API com erro ou plano
        # refeito no mesmo dia deixavam passo fantasma travando a cadência)
        print(f"{len(de_pace)} passos de pace NÃO registrados ainda: depois de gravar, rode "
              f"`pace.py registrar --plano <itens gravados>` (ou o próprio {a.saida or 'plano'} se gravou tudo)")
    return 0


def ler_filtro_noites(pares: list[str]) -> set:
    """Q7=2026-11-10 ou Q7=2026-11-10..2026-11-12 (inclusive) → {(quarto, data)}."""
    out = set()
    for par in pares:
        k, v = par.split("=", 1)
        for pedaco in v.split(","):
            de, _, ate = pedaco.partition("..")
            d0, d1 = data(de), data(ate or de)
            while d0 <= d1:
                out.add((k, d0.isoformat()))
                d0 += dt.timedelta(days=1)
    return out


if __name__ == "__main__":
    sys.exit(main())

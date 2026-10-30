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
      [--saida plano.json] [--md relatorio.md] [--sombra]
  python3 pace.py curva --tipo fds --dias 7
  python3 pace.py marcar-envio --ate AAAA-MM-DDTHH:MM  (marca como chegados ao canal os passos gravados antes do envio)
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import math
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ancora_mercado import ARQ_ANCORA, ancora_da_noite, percentil_de, tipo_noite, valor_percentil  # noqa: E402
from calendario_12m import ler_recalculo  # noqa: E402
from comum import PASTA_SKILL, carregar_quartos, data, esgotado, hoje_brt, ler_json, periodo_feriado, vendidas_total  # noqa: E402
from ordens import ARQ_ORDENS, carregar_ordens, congelada, ordens_por_data  # noqa: E402

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


def cadencia_permite(passos: dict, curto: str, noite: str, direcao: str, agora: dt.datetime) -> tuple[bool, str]:
    u = ultimo_passo(passos, curto, noite)
    if not u or u["direcao"] != direcao:
        return True, ""
    gravado = dt.datetime.fromisoformat(u["gravado_em"].replace("Z", "+00:00"))
    if gravado.tzinfo is None:
        gravado = gravado.replace(tzinfo=dt.timezone.utc)
    if not u.get("chegou_ao_canal"):
        return False, f"passo anterior ({u['direcao']}, {u['gravado_em'][:16]}) ainda não chegou ao canal"
    if (agora - gravado) < dt.timedelta(hours=24):
        return False, f"já houve passo {u['direcao']} há menos de 24 h ({u['gravado_em'][:16]})"
    return True, ""


def registrar_passos(passos: dict, itens: list[dict], agora: dt.datetime, caminho=None) -> None:
    for it in itens:
        pc = it.get("pace") or {}
        if not pc.get("direcao"):
            continue
        passos.setdefault("passos", []).append({
            "quarto": it["quarto"], "noite": it["data"], "de": pc["atual"], "para": pc["novo"], "direcao": pc["direcao"],
            "status": pc["status"], "gravado_em": agora.isoformat(timespec="minutes"), "chegou_ao_canal": False})
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


def plano(cfg: dict, ancora: dict, cals: dict, hoje: date, ordens: dict, passos: dict, bloqueios: set | None = None,
          dias: int = 60, agora: dt.datetime | None = None, regra_j_ativa: bool = True) -> dict:
    """Itens de preço (formato do validar_plano.py) e o diagnóstico por noite para o relatório."""
    p = par_pace(cfg)
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
            piso_vig = float(r.get("min_price") or 0)
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
                motivo_fora = "esgotada: Regra J (proteção)" if regra_j_ativa else "esgotada: piso de proteção p90"
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
            alvo_pub = valor_percentil(anc, pct)
            alvo_env = alvo_pub / fp
            idade = (hoje - data(anc.get("busca_em", hoje.isoformat()))).days
            fraco = int(anc.get("n") or 0) < p["min_n"] or idade > p["idade_max_dias"]
            if st == "frente":
                alvo_env = max(alvo_env, atual)  # noite vendendo à frente da curva nunca desce: segura ou sobe (RevPAR)
            novo = passo(cfg, atual, alvo_env, k, fraco)
            piso, origem_piso = pisos_enviados(cfg, curto, d)
            preso = None
            if novo < piso:
                novo, preso = piso, f"presa no piso ({origem_piso} = R$ {piso:.0f} enviados, público ~R$ {piso * fp:.0f}, percentil {percentil_de(piso * fp, anc):.0f})"
            novo = min(novo, float(q["max"]))
            if k == 0 and novo > atual:
                novo = atual  # hoje nunca sobe
            direcao = "sobe" if novo > atual * (1 + p["tolerancia"]) else ("desce" if novo < atual * (1 - p["tolerancia"]) else None)
            novo_r = math.ceil(novo) if direcao == "sobe" else (math.floor(novo) if direcao == "desce" else round(novo))
            linha = {"quarto": curto, "data": noite, "D": k, "tipo": tipo, "vendidas": v, "unidades": u, "vendido_pct": round(vend_pct),
                     "curva_pct": round(esp), "status": st, "percentil_alvo": pct, "alvo_publico": round(alvo_pub),
                     "alvo_enviado": round(alvo_env), "atual": atual, "atual_publico": round(atual * fp),
                     "percentil_atual": percentil_de(atual * fp, anc), "novo": novo_r, "direcao": direcao,
                     "preso_no_piso": preso, "ancora": origem, "ancora_fraca": fraco}
            linhas.append(linha)
            if not direcao:
                continue
            ok, por_que = cadencia_permite(passos, curto, noite, direcao, agora)
            if not ok:
                linha["cadencia"] = por_que
                continue
            it = {"tipo": "data", "quarto": curto, "data": noite, "min_price": novo_r, "preco_antes": atual,
                  "motivo": (f"Pace ({st.replace('_', ' ')}: {v}/{u} vendidas, curva {esp:.0f}% a D{k}); alvo p{pct:.0f} "
                             f"R$ {alvo_pub:.0f} público = R$ {alvo_env:.0f} enviados ({origem}); antes R$ {atual:.0f}"),
                  "pace": {"status": st, "direcao": direcao, "atual": atual, "novo": novo_r, "alvo_enviado": round(alvo_env)}}
            if direcao == "sobe":
                it["max_price"] = float(q["max"])  # sobe pelo piso e solta o teto
            elif atual > piso_vig + 1 and atual > novo_r + 1:
                it["max_price"] = novo_r  # o algoritmo está acima do alvo: o teto desce o preço; o piso vai junto
            itens.append(it)
            finais[(curto, noite)] = float(novo_r)
    itens = coerencia(cfg, cals, finais, itens, hoje, ordens)
    return {"hoje": hoje.isoformat(), "itens": itens, "linhas": linhas, "fora": fora}


def coerencia(cfg: dict, cals: dict, finais: dict, itens: list[dict], hoje: date, ordens: dict) -> list[dict]:
    """Depois do motor: Panorâmica II entre Moinhos e Panorâmica I; Afrodite acima das outras; Balcony 10% abaixo da suíte
    com banheira livre (Regra G). Só mexe nas noites em que o motor mexeu e nas suítes que estão à venda."""
    from ordem_recanto import premio_afrodite
    por_chave = {(it["quarto"], it["data"]): it for it in itens}
    desc = cfg["parametros"].get("regra_g_desconto", 0.10)

    def preco(k, noite):
        r = cals.get(k, {}).get(noite)
        return None if r is None else finais.get((k, noite), float(r["price"]))

    def livre(k, noite):
        r = cals.get(k, {}).get(noite)
        return r is not None and not esgotado(r, cfg["por_curto"][k]["unidades"]) and not cfg["por_curto"][k].get("fora_do_motor")

    def ajustar(k, noite, piso=None, teto=None, motivo="", ordem_id=None):
        d = data(noite)
        if congelada(ordens, k, d) or ordens_por_data(ordens, k, d) or not livre(k, noite):
            return
        r = cals[k][noite]
        atual = preco(k, noite)
        it = por_chave.get((k, noite))
        if it is None:
            it = {"tipo": "data", "quarto": k, "data": noite, "preco_antes": float(r["price"]), "motivo": motivo}
            if ordem_id:
                it["excecao"], it["ordem_id"] = "dono", ordem_id
            por_chave[(k, noite)] = it
            itens.append(it)
        if piso is not None and piso > (it.get("min_price") or 0):
            it["min_price"] = math.ceil(piso)
            if it.get("max_price") is not None and it["max_price"] < it["min_price"]:
                it["max_price"] = it["min_price"]
        if teto is not None and (it.get("max_price") is None or teto < it["max_price"]):
            it["max_price"] = math.floor(max(teto, it.get("min_price") or 0))
        if motivo and motivo not in it["motivo"]:
            it["motivo"] = (it["motivo"] + "; " + motivo)[:250]
        finais[(k, noite)] = float(it.get("min_price") or atual)

    for noite in sorted({n for (_, n) in finais}):
        d = data(noite)
        if any((k, noite) in finais for k in ("Q7", "Double", "Q2")):
            a, b = preco("Q7", noite), preco("Double", noite)
            if a is not None and b is not None and livre("Q2", noite):
                menor, maior = min(a, b), max(a, b)
                ajustar("Q2", noite, piso=menor, teto=menor + 0.75 * (maior - menor),
                        motivo=f"Ordem do Recanto: Panorâmica II entre Moinhos (R$ {a:.0f}) e Panorâmica I (R$ {b:.0f})",
                        ordem_id="dono-2026-10-21-ordem-recanto")
            if a is not None and b is not None and a > b + 1 and livre("Double", noite):
                ajustar("Double", noite, piso=a, motivo=f"Ordem do Recanto: Panorâmica I não fica abaixo da Moinhos (R$ {a:.0f})")
            outras = [preco(k, noite) for k in ("Q7", "Q2", "Double") if livre(k, noite) and preco(k, noite) is not None]
            if outras and livre("Afrodite", noite):
                pr = premio_afrodite(cfg, d)
                ajustar("Afrodite", noite, piso=max(outras) * (1 + pr),
                        motivo=f"Afrodite {pr:.0%} acima da mais cara das outras (R$ {max(outras):.0f})",
                        ordem_id="dono-2026-10-22-afrodite-mais-cara")
        if any((k, noite) in finais for k in ("VK7", "VK2", "Balcony")) and livre("Balcony", noite) and not periodo_feriado(cfg, d):
            refs = [preco(k, noite) for k in ("VK7", "VK2") if livre(k, noite) and preco(k, noite) is not None]
            if refs:
                alvo_b = min(refs) * (1 - desc)
                it = por_chave.get(("Balcony", noite))
                if it is not None:
                    it["min_price"] = min(it.get("min_price") or alvo_b, math.floor(alvo_b))
                ajustar("Balcony", noite, teto=alvo_b,
                        motivo=f"Regra G: Balcony {desc:.0%} abaixo da suíte com banheira livre mais barata (R$ {min(refs):.0f})",
                        ordem_id="dono-2026-10-07-regra-g")
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
    res = plano(cfg, ancora, cals, hoje, ordens, passos, bloq, a.dias, regra_j_ativa=motor.get("regra_j_ativa", True))
    md = relatorio_md(cfg, res, sombra)
    print(md)
    if a.md:
        Path(a.md).write_text(md, encoding="utf-8")
    if a.saida:
        with open(a.saida, "w", encoding="utf-8") as f:
            json.dump({"hoje": res["hoje"], "itens": res["itens"], "sombra": sombra}, f, ensure_ascii=False, indent=1)
        print(f"{len(res['itens'])} itens em {a.saida}{' (SOMBRA: não grave)' if sombra else ''}")
    if not sombra and res["itens"]:
        registrar_passos(passos, res["itens"], dt.datetime.now(dt.timezone.utc), a.passos)
    return 0


if __name__ == "__main__":
    sys.exit(main())

"""Réveillon 2026/27 e fim de semana 01→03/01 (dono, 16/11/2026: "Pode fazer"; ordem dono-2026-11-16-reveillon-250k).

O dono aprovou a proposta de pricelabs/reveillon-2026-meta-250k.md (seções 6, 7 e 9): Réveillon do Recanto no pedido de
09/11, fim de semana pela proposta (alvo público ÷ fator público medido), escada por PACOTE vendido na pousada (+3% no
Réveillon, +2% no fim de semana), descida só a partir de D7 pela linha de pacotes, pisos do período (alvo × 0,95^4),
máximo do anúncio de R$ 6.000, abertura do 01→03 por pousada depois do portão e as exceções das noites soltas. A ordem é
`preco_por_data` nas noites 30/12 a 02/01 nos 7 quartos: o motor de pace não mexe nelas; este script decide o preço.

Os parâmetros vêm da ordem (dados/ordens_dono.json); o estado que muda (fatores medidos, degraus, escada, promoção da
Villa, concorrente mais caro, portão) fica em dados/reveillon_250k.json. Só lê arquivos: nada aqui chama API.

Uso (da pasta da skill), na ordem da rotina:
  python3 scripts/reveillon_250k.py plano --hoje AAAA-MM-DD --inventario <inv.json> --reservas <reservas.json>
      --regras Q7=<ov.json> Double=<ov.json> Afrodite=<ov.json> Q2=<ov.json> VK7=<ov.json> Balcony=<ov.json> VK2=<ov.json>
      [--fator Recanto:fds=0.5832 ...] [--concorrente-max 2871] [--promocao-villa ativa|retirada]
      [--receita 46580.68] [--listings <listings.json>] [--saida plano.json] [--md relatorio.md]
  python3 scripts/reveillon_250k.py orfas --hoje AAAA-MM-DD --inventario <inv.json> --regras ... --saida <orfas.json>
      [--lote-beds24 <lote_orfas.json>]
  python3 scripts/reveillon_250k.py portao --hoje AAAA-MM-DD --inventario <inv_precos.json> --regras Q7=<ov.json> ...
      --plano <plano.json> --envio Q7=<last_date_pushed UTC> Q2=... (os 7; vale o mais antigo da pousada)
      --publico Recanto=<R$ por noite> [...] [--promocao-villa ativa|retirada] --saida <portao.json>
      [--lote-beds24 <lote_portao.json>] [--mostrar]
  python3 scripts/reveillon_250k.py juntar --plano <plano.json> <orfas.json> <portao.json> --saida <unico.json>
  python3 validar_plano.py <unico.json> --hoje AAAA-MM-DD     (da pasta scripts)
  python3 scripts/reveillon_250k.py payloads --plano <unico.json> --hoje AAAA-MM-DD --pasta <pasta vazia>
      (valida de novo e grava um arquivo por chamada: payload_<quarto>.json para update_listing_date_overrides e
      payload_anuncio_<quarto>.json para update_listing_data, gravado antes; recusa sobrescrever; conferir_gravacao.json
      diz o que conferir na resposta)
  python3 scripts/reveillon_250k.py registrar --plano <unico.json> --em <UTC da gravação>   (depois de gravar e conferir;
      funde no estado em disco e, com item de anúncio, passa o max de quartos.json; rode também sem item gravado: os
      marcos da descida avaliados no dia ficam no estado)
  python3 scripts/reveillon_250k.py aplicar-config --hoje AAAA-MM-DD --portao <portao.json> --orfas <orfas.json>
      (depois do PriceLabs e do Beds24: aplica em quartos.json a decisão gravada, sem reavaliar)
  python3 scripts/reveillon_250k.py vigia-maximo --hoje AAAA-MM-DD [--coleta <saida mesclada>]
      [--precos Q7=<get_listing_prices 12 meses> ...] [--regras Q7=<overrides 12 meses> ...] [--antes] [--saida <x.json>]
  python3 scripts/reveillon_250k.py estado

`plano`: itens de preço (formato do validar_plano.py, piso = teto em reais que o payload converte para percent_base,
excecao "dono" com o ordem_id) só onde o preço muda; só piso e teto (a estadia e a máscara ficam na regra: a API mescla).
`orfas`: cotas de venda direta das noites soltas e se as exceções 1 (saída em 31/12) e 2 (entrada no sábado 02/01)
abrem nas OTAs em cada quarto, com o que gravar ou desfazer (itens com a prova do inventário). `portao`: diz por pousada
se o 01→03 pode abrir e, se pode, os comandos do Beds24 (simulação), os itens do PriceLabs (estadia 2 e a sexta da
Afrodite, com a prova do portão) e o patch de quartos.json (a auditoria diária do Beds24 confere a config). `juntar`:
um arquivo só para validar e gerar os pedidos (o plano manda no preço; órfãs e portão, na estadia e na máscara).
"""

from __future__ import annotations

import argparse
import copy
import hashlib
import json
import math
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from comum import (PASTA_SKILL, ARQ_QUARTOS, carregar_quartos, data, hoje_brt, ler_instante, ler_json,  # noqa: E402
                   maximo_da_noite, periodo_feriado)
from ordens import carregar_ordens  # noqa: E402
import plano_dono as PD  # noqa: E402

ORDEM_ID = "dono-2026-11-16-reveillon-250k"
ARQ_ESTADO = PASTA_SKILL / "dados" / "reveillon_250k.json"
POUSADAS = {"Recanto": ("Q7", "Q2", "Double", "Afrodite"), "Villa": ("VK7", "VK2", "Balcony")}
POUSADA_DE = {q: p for p, qs in POUSADAS.items() for q in qs}
QUARTOS = ("Q7", "Double", "Afrodite", "Q2", "VK7", "Balcony", "VK2")
BLOCOS = {"reveillon": ("2026-12-30", "2026-12-31"), "fds": ("2027-01-01", "2027-01-02")}
NOITES = tuple(n for b in BLOCOS.values() for n in b)
BLOCO_DE = {n: b for b, ns in BLOCOS.items() for n in ns}
NOME_BLOCO = {"reveillon": "Réveillon", "fds": "fim de semana"}
ATIVAS = {"new", "confirmed", "request"}
VESPERA, DOMINGO = "2026-12-29", "2027-01-03"
TOL_ORDEM = 1.0   # R$: o % inteiro da base não deixa igualar (proposta: Q2 4.070,40 "igual" à Double 4.070,00)
NOME_SUITE = {"Q7": "Moinhos", "Q2": "Panorâmica II", "Double": "Panorâmica I"}


# ---------------------------------------------------------------- utilidades
def dia(n: str, k: int) -> str:
    return (data(n) + timedelta(days=k)).isoformat()


def base(cfg: dict, q: str) -> float:
    return float(cfg["por_curto"][q]["base"])


def pct_de(cfg: dict, q: str, reais: float, modo: str = "round") -> int:
    """Variação inteira sobre a base do anúncio (percent_base) para um valor em reais."""
    x = reais / base(cfg, q) * 100 - 100
    if modo == "floor":
        return int(math.floor(x + 1e-9))
    if modo == "ceil":
        return int(math.ceil(x - 1e-9))
    return int(math.floor(x + 0.5))


def reais_de(cfg: dict, q: str, p: int) -> float:
    """Valor que o PriceLabs aplica e manda ao Beds24 (price1) para p% sobre a base: base × (1 + p/100)."""
    return round(base(cfg, q) * (1 + p / 100), 2)


def br(v: float | None, casas: int = 0) -> str:
    if v is None:
        return "—"
    s = f"{float(v):,.{casas}f}"
    return "R$ " + s.replace(",", "X").replace(".", ",").replace("X", ".")


def fx(v: float) -> str:
    """Fator com vírgula (0,5832)."""
    return f"{float(v):.4f}".replace(".", ",")


def livres(inv: dict, q: str, n: str) -> int | None:
    v = ((inv.get(q) or {}).get(n) or {}).get("numAvail")
    return None if v is None else int(v)


def unidades(cfg: dict, q: str) -> int:
    return int(cfg["por_curto"][q]["unidades"])


def ler_regras(pares: list[str] | None) -> dict:
    """{curto: {data: regra}} do get_listing_date_overrides (o mesmo formato do pace.ler_regras)."""
    out = {}
    for x in pares or []:
        k, v = x.split("=", 1)
        d = ler_json(v)
        lst = ((d.get("data") or {}).get("overrides")) if isinstance(d.get("data"), dict) else None
        lst = lst or d.get("overrides") or []
        out[k] = {o["date"][:10]: o for o in lst if o.get("date")}
    return out


def pct_vigente(regra: dict | None, campo: str) -> int | None:
    v = (regra or {}).get(campo)
    if v in (None, "") or ((regra or {}).get(f"{campo}_type") or "percent_base") != "percent_base":
        return None
    return int(round(float(v)))


def ordem_do_dono(ordens: dict | None = None) -> dict:
    ordens = ordens if ordens is not None else carregar_ordens()
    o = ordens.get(ORDEM_ID)
    if not o:
        raise SystemExit(f"ERRO: a ordem {ORDEM_ID} não está em dados/ordens_dono.json")
    return o


def carregar_estado(caminho: Path | str | None = None) -> dict:
    p = Path(caminho or ARQ_ESTADO)
    with open(p, encoding="utf-8") as f:
        return json.load(f)


def salvar_json(caminho: Path | str, d: dict) -> None:
    with open(caminho, "w", encoding="utf-8") as f:
        json.dump(d, f, ensure_ascii=False, indent=1)
        f.write("\n")


# ---------------------------------------------------------------- pacotes vendidos (escada e linha da descida)
def pacotes_livres(cfg: dict, inv: dict) -> dict:
    """{pousada: {bloco: n}}: pacotes que ainda cabem (as 2 noites livres no mesmo quarto: o menor entre as duas)."""
    out = {p: {b: 0 for b in BLOCOS} for p in POUSADAS}
    for q in QUARTOS:
        for b, (n1, n2) in BLOCOS.items():
            a, c = livres(inv, q, n1), livres(inv, q, n2)
            if a is not None and c is not None:
                out[POUSADA_DE[q]][b] += max(0, min(a, c))
    return out


def chave_reserva(r: dict) -> str:
    """Chave da reserva na linha de base, sem dado de hóspede: hash do id do Beds24 (o arquivo não guarda id nem nome)."""
    return hashlib.sha256(f"beds24:{r.get('id')}".encode()).hexdigest()[:16]


def blocos_da_estadia(chega: date, sai: date) -> list[str]:
    """Blocos que a estadia cobre inteiros (30→01: Réveillon; 01→03: fim de semana; 30→03: os dois)."""
    return [b for b, (n1, n2) in BLOCOS.items() if chega <= data(n1) and sai >= data(n2) + timedelta(days=1)]


def _quarto_da_reserva(cfg: dict, r: dict) -> str | None:
    rid = {int(str(q["id"]).split("___")[1]): q["curto"] for q in cfg["quartos"]}
    q = rid.get(int(r.get("roomId") or 0))
    return q if q in POUSADA_DE else None


def cobertura_reservas(cfg: dict, reservas: list[dict]) -> dict:
    """{chave: {"quarto", "reveillon": n, "fds": n}} das reservas ATIVAS que cobrem algum bloco inteiro (n = roomQty).
    Linha de base da contagem por diferença (revisão de 16/11: reserva antiga estendida de 30→01 para 30→03, encurtada ou
    trocada de quarto não entrava na contagem por bookingTime)."""
    out = {}
    for r in reservas or []:
        q = _quarto_da_reserva(cfg, r)
        if q is None or r.get("id") is None or str(r.get("status") or "").lower() not in ATIVAS:
            continue
        bl = blocos_da_estadia(data(r["arrival"]), data(r["departure"]))
        if not bl:
            continue
        qty = int(r.get("roomQty") or 1)
        out[chave_reserva(r)] = dict({"quarto": q}, **{b: (qty if b in bl else 0) for b in BLOCOS})
    return out


def contar_pacotes(cfg: dict, reservas: list[dict], corte: datetime, base: dict | None = None) -> dict:
    """Pacotes NOVOS desde a linha de base, líquidos. Devolve {"Recanto": {bloco: n}, "Villa": {...}, "total": {bloco: n},
    "detalhe": [...]} (sem dado de hóspede: só quarto, datas, canal e sinal).

    Com `base` (linha_base.cobertura_reservas do estado: {chave: {quarto, reveillon, fds}}), a contagem é a diferença por
    reserva entre a leitura de agora e a de 16/11: reserva nova que cobre o bloco conta +roomQty; cancelada (ou que saiu do
    período) −roomQty; estendida (30→01 para 30→03) +1 no fim de semana; encurtada −1; trocada de pousada, −1 numa e +1 na
    outra. Leitura com mais de 1/5 das reservas da base sumidas (nem canceladas aparecem) levanta ValueError (leitura
    incompleta). Sem `base` (estado antigo), pelo instante: reserva ativa feita depois do `corte` conta +roomQty; reserva
    de antes do corte cancelada depois dele conta −roomQty."""
    out = {p: {b: 0 for b in BLOCOS} for p in POUSADAS}
    det = []
    if base is not None:
        atual = cobertura_reservas(cfg, reservas)
        info = {chave_reserva(r): r for r in reservas or [] if r.get("id") is not None}
        sumidas = [k for k in base if k not in info]
        if len(sumidas) > max(2, len(base) // 5):
            raise ValueError(f"{len(sumidas)} das {len(base)} reservas da linha de base não estão na leitura (nem canceladas): "
                             "leitura incompleta? Leia de novo (beds24.py reservas --de 2026-12-28 --ate 2027-01-04 --json)")
        for k in sorted(set(atual) | set(base)):
            a, b0 = atual.get(k), base.get(k)
            r = info.get(k) or {}
            for bl in BLOCOS:
                na, nb = int((a or {}).get(bl) or 0), int((b0 or {}).get(bl) or 0)
                pa = POUSADA_DE[a["quarto"]] if a else None
                pb = POUSADA_DE[b0["quarto"]] if b0 else None
                if na == nb and (pa == pb or na == 0):
                    continue
                if na:
                    out[pa][bl] += na
                if nb:
                    out[pb][bl] -= nb
                tipo = "nova" if not b0 else ("cancelada ou fora do período" if not a else "alterada")
                det.append({"quarto": (a or b0)["quarto"], "bloco": bl, "chegada": r.get("arrival"),
                            "saida": r.get("departure"), "canal": r.get("channel"), "sinal": na - nb, "tipo": tipo})
        tot = {b: out["Recanto"][b] + out["Villa"][b] for b in BLOCOS}
        return {"Recanto": out["Recanto"], "Villa": out["Villa"], "total": tot, "detalhe": det, "modo": "diferença"}
    for r in reservas or []:
        q = _quarto_da_reserva(cfg, r)
        if q is None:
            continue
        chega, sai = data(r["arrival"]), data(r["departure"])
        qty = int(r.get("roomQty") or 1)
        st = str(r.get("status") or "").lower()
        bt, ct = ler_instante(r.get("bookingTime")), ler_instante(r.get("cancelTime"))
        nova = bt is not None and bt > corte
        if nova and st in ATIVAS:
            sinal = 1
        elif not nova and st == "cancelled" and ct is not None and ct > corte:
            sinal = -1
        else:
            continue
        for b in blocos_da_estadia(chega, sai):
            out[POUSADA_DE[q]][b] += sinal * qty
            det.append({"quarto": q, "bloco": b, "chegada": r["arrival"], "saida": r["departure"],
                        "canal": r.get("channel"), "sinal": sinal * qty})
    tot = {b: out["Recanto"][b] + out["Villa"][b] for b in BLOCOS}
    return {"Recanto": out["Recanto"], "Villa": out["Villa"], "total": tot, "detalhe": det, "modo": "instante"}


# ---------------------------------------------------------------- escada, alarme e degraus
def linha_alarme(ordem: dict, hoje: date) -> tuple[str, float, int] | None:
    ult = None
    for ds, receita, qn in (ordem.get("pausa_alarme") or {}).get("linhas") or []:
        if data(ds) <= hoje:
            ult = (ds, float(receita), int(qn))
    return ult


def quarto_noites_vendidas(cfg: dict, inv: dict) -> int:
    return sum(unidades(cfg, q) - int(livres(inv, q, n) or 0) for q in QUARTOS for n in NOITES
               if livres(inv, q, n) is not None)


def abaixo_do_alarme(ordem: dict, hoje: date, qn: int, receita: float | None) -> tuple[bool, str]:
    lin = linha_alarme(ordem, hoje)
    if not lin:
        return False, "sem linha de alarme ainda (primeira em 23/11)"
    ds, rec, q_lin = lin
    falta = []
    if qn < q_lin:
        falta.append(f"{qn} quarto-noites < {q_lin}")
    if receita is not None and receita < rec:
        falta.append(f"receita {br(receita)} < {br(rec)}")
    txt = f"linha de {data(ds).strftime('%d/%m')}: " + (", ".join(falta) if falta else f"{qn} quarto-noites ≥ {q_lin}")
    return bool(falta), txt


def escada_usada(cont: int, anterior: int | None, pausa: bool) -> int:
    """Pacotes que a escada usa: o líquido de agora; abaixo do alarme a escada para (fica no que estava, e só desce com
    cancelamento)."""
    cont = max(0, int(cont))
    if pausa and anterior is not None:
        return min(cont, int(anterior))
    return cont


def parte_da_pousada(base_livres: dict, pousada: str, bloco: str) -> float:
    tot = sum(base_livres[p][bloco] for p in POUSADAS)
    return base_livres[pousada][bloco] / tot if tot else 0.5


def linha_vigente(ordem: dict, bloco: str, hoje: date) -> tuple[str, int] | None:
    """(data, pacotes) do último marco da descida já chegado no bloco (D7, D5, D3 ou D1); None antes do D7."""
    ult = None
    for ds, lin in ordem["descida"]["linhas"][bloco]:
        if data(ds) <= hoje:
            ult = (ds, int(lin))
    return ult


def abaixo_da_linha(linha: int, total_bloco: int, n_pousada: int, parte: float) -> bool:
    """O bloco abaixo da linha (pacotes novos, as duas pousadas somadas) e a pousada abaixo da sua parte dela."""
    return total_bloco < linha and n_pousada < linha * parte - 1e-9


def degraus_do_dia(ordem: dict, bloco: str, hoje: date, total_bloco: int, n_pousada: int, parte: float,
                   anterior: dict | None) -> dict:
    """Degraus de descida da pousada no bloco hoje, com o histórico no estado ({valor, em, marcos}). Revisão de 16/11
    (a conta sem histórico dava menos degraus que a regra com venda parcial e subia com o bloco ainda abaixo da linha):
      - cada marco (D7, D5, D3 e D1 do bloco) é avaliado UMA vez, na primeira rodada em ou depois dele (`marcos`): com o
        bloco abaixo da linha DAQUELE marco e a pousada abaixo da sua parte, +1 degrau (no máximo 4), na hora;
      - fora disso, sobe um degrau por dia (proposta: "Se o bloco voltar acima da linha, sobe um degrau (5%) por vez")
        só quando o bloco não está abaixo da linha vigente (a do último marco chegado) ou a pousada passou da sua parte;
        nunca sobe com o bloco abaixo dela, e nunca duas vezes no mesmo dia (o D0 o plano segura).
    Marco que passou sem rodada (rotina perdida) é avaliado com a contagem de agora."""
    desc = ordem["descida"]
    mx = int(desc.get("max_degraus", 4))
    ant = anterior or {}
    valor, em = int(ant.get("valor") or 0), ant.get("em")
    marcos = list(ant.get("marcos") or [])
    novos = 0
    for ds, lin in desc["linhas"][bloco]:
        if data(ds) <= hoje and ds not in marcos:
            marcos.append(ds)
            if abaixo_da_linha(int(lin), total_bloco, n_pousada, parte):
                novos += 1
    if novos:
        v2 = min(mx, valor + novos)
        if v2 != valor:
            valor, em = v2, hoje.isoformat()
    elif valor > 0:
        vig = linha_vigente(ordem, bloco, hoje)
        if vig and not abaixo_da_linha(vig[1], total_bloco, n_pousada, parte) and not (em and data(em) >= hoje):
            valor, em = valor - 1, hoje.isoformat()
    return {"valor": valor, "em": em, "marcos": sorted(marcos)}


def adotar_fator(atual: dict, medido: float | None, hoje: date, tol: float, forcar: bool = False) -> tuple[dict, str]:
    """Correção diária do fator público: o medido entra quando o público sai de ±tol do alvo (fator mais de tol
    diferente do guardado) ou quando `forcar` (promoção mudou)."""
    if medido is None:
        return atual, ""
    v = float(atual["valor"])
    if forcar or abs(medido / v - 1) > tol + 1e-12:
        return {"valor": round(float(medido), 4), "medido_em": hoje.isoformat()}, f"fator {fx(v)} → {fx(medido)}"
    return atual, f"fator medido {fx(medido)} dentro de ±{tol:.0%} de {fx(v)}: mantido"


def villa_fds_liberada(estado: dict) -> bool:
    """O fim de semana da Villa vai ao alvo público só depois de a promoção sair e de medir o fator novo."""
    pr = estado.get("promocao_villa") or {}
    if pr.get("ativa", True):
        return False
    f = ((estado.get("fatores") or {}).get("Villa") or {}).get("fds") or {}
    ret = pr.get("retirada_em")
    return bool(ret and f.get("medido_em") and f["medido_em"] >= ret)


# ---------------------------------------------------------------- preço de cada suíte-noite
def pedido(ordem: dict, q: str, n: str) -> float:
    v = ordem["pedido_0911"][q]
    return float(v[n] if isinstance(v, dict) else v)


def alvo_da_noite(ordem: dict, estado: dict, q: str, n: str) -> dict | None:
    """{enviado, publico (ou None), fator, origem, cortado_pelo_pedido}; None = mantém o vigente (fim de semana da Villa
    com a promoção). Fim de semana: enviado = público ÷ fator, nunca acima do pedido de 09/11 (aprovado em 16/11; revisão
    de 16/11: com o fator abaixo de ~0,51 a Moinhos passava do pedido). Cortado pelo pedido, o público fica abaixo do alvo:
    é sinal de promoção ou Genius somados (a conferir com o dono), não de preço a subir."""
    b, p = BLOCO_DE[n], POUSADA_DE[q]
    fator = float(estado["fatores"][p][b]["valor"])
    if b == "reveillon":
        env = float(ordem["alvos"]["reveillon_enviado"][q])
        orig = "pedido de 09/11" if p == "Recanto" else ("Regra G" if q == "Balcony" else "reta de R$ 5.000 (sem mudança)")
        return {"enviado": env, "publico": None, "fator": fator, "origem": orig, "cortado_pelo_pedido": False}
    if p == "Villa" and not villa_fds_liberada(estado):
        return None
    pub = float(ordem["alvos"]["fds_publico"][q])
    env, lim = pub / fator, pedido(ordem, q, n)
    orig = f"alvo público {br(pub)} ÷ fator {fx(fator)}"
    cortado = env > lim + 0.005
    if cortado:
        env = lim
        orig += f", limitado ao pedido de 09/11 ({br(lim)}; público ~{br(lim * fator)}, abaixo do alvo)"
    return {"enviado": env, "publico": pub, "fator": fator, "origem": orig, "cortado_pelo_pedido": cortado}


def maximo_aprovado(cfg: dict, ordem: dict, q: str, n: str) -> float:
    """Máximo do anúncio que a ordem aprovou para a noite (R$ 6.000 de 30/12 a 02/01 nos 6 quartos da lista); o que vale
    hoje é o de quartos.json (comum.maximo_da_noite): até o item de anúncio ser gravado e conferido, o preço fica nele."""
    atual = maximo_da_noite(cfg, q, n)
    ma = ordem.get("maximo_anuncio") or {}
    if q in (ma.get("quartos") or []) and n in (ma.get("noites") or NOITES) and ma.get("para") is not None:
        return max(atual, float(ma["para"]))
    return atual


def teto_da_noite(cfg: dict, ordem: dict, estado: dict, q: str, n: str, fator: float, mx: float | None = None) -> float:
    mx = maximo_da_noite(cfg, q, n) if mx is None else mx
    t = ordem["tetos"]
    if BLOCO_DE[n] == "reveillon":
        v = float(t["reveillon"]["Afrodite"]) if q == "Afrodite" else float(t["reveillon"]["maximo_anuncio"])
        return min(v, mx)
    if q == "Afrodite":
        v = float(t["fds"]["Afrodite"][n])
    elif q == "Balcony":
        v = mx  # Balcony: Regra G sobre a King Spa
    else:
        conc = float((estado.get("concorrente_max_fds") or {}).get("publico") or t["fds"]["concorrente_hidro_max_publico"])
        v = min(conc / fator, pedido(ordem, q, n))
    return min(v, mx)


def bonus_marco(ordem: dict, estado: dict, hoje: date, total: dict) -> dict:
    """Marco de 21/12 (final.marcos da proposta aprovada): com 10 ou mais dos 12 pacotes do Réveillon vendidos, os que
    restam sobem mais 5%, uma vez só, além da escada, só datas futuras e dentro do teto. Ligado, fica no estado
    (bonus_marco) e não soma de novo. Devolve {"ativo", "em", ...}."""
    ja = estado.get("bonus_marco") or {}
    if ja.get("ativo"):
        return dict(ja)
    m = ordem.get("marco_21_12") or {}
    if m and hoje >= data(m["data"]) and int(total.get(m["bloco"], 0)) >= int(m["pacotes_minimos"]):
        return {"ativo": True, "em": hoje.isoformat(), "pacotes": int(total[m["bloco"]])}
    return {"ativo": False, "em": None}


def piso_do_periodo(ordem: dict, q: str, n: str, a: dict) -> float:
    """Piso do período em enviado (ordem: "alvo × 0,95^4, em público, convertido pelo fator medido"). Réveillon: o alvo
    enviado × 0,95^4. Fim de semana: o alvo PÚBLICO da ordem × 0,95^4 ÷ fator, nunca acima do pedido de 09/11 (revisão
    de 16/11: com o alvo cortado no pedido, o piso saía do pedido × 0,95^4 e o público ficava abaixo do piso aprovado)."""
    f = float(ordem["pisos_periodo"]["fator"])
    if BLOCO_DE[n] == "fds" and a.get("publico"):
        return min(pedido(ordem, q, n), float(ordem["alvos"]["fds_publico"][q]) * f / float(a["fator"]))
    return float(a["enviado"]) * f


def preco_bruto(cfg: dict, ordem: dict, estado: dict, q: str, n: str, escada: dict, degraus: dict,
                bonus: bool = False) -> dict | None:
    """Preço da suíte-noite antes da coerência, em % inteiro da base, nunca abaixo do piso do período:
      - sem degrau de descida: alvo × escada (× bônus do marco de 21/12 no Réveillon), limitado ao teto (o teto limita
        a escada, não o alvo);
      - com k degraus: o alvo do degrau, alvo × 0,95^k, sem a escada (proposta aprovada, final.plano_de_acao: "Cada
        degrau respeita o maior valor entre o piso do período e o alvo do degrau, nunca o público de hoje"; revisão de
        16/11: o degrau sobre o preço com a escada deixava a pousada que vendeu cedo e parou acima do alvo).
    `desejado` é o valor com o máximo aprovado (R$ 6.000); `valor`, o que cabe no máximo de hoje (quartos.json):
    `segurado_no_maximo` quando o máximo do anúncio ainda não subiu."""
    a = alvo_da_noite(ordem, estado, q, n)
    if a is None:
        return None
    b, p = BLOCO_DE[n], POUSADA_DE[q]
    passo = float(ordem["escada"][b])
    k_esc = int(escada[p][b])
    k_desc = int(degraus[p][b])
    alvo = a["enviado"]
    mx_hoje = maximo_da_noite(cfg, q, n)
    mx = maximo_aprovado(cfg, ordem, q, n)
    teto = teto_da_noite(cfg, ordem, estado, q, n, a["fator"], mx)
    piso = piso_do_periodo(ordem, q, n, a)
    usa_bonus = bool(bonus and k_desc == 0 and b == (ordem.get("marco_21_12") or {}).get("bloco"))
    if k_desc > 0:
        v = alvo * (1 - float(ordem["descida"]["degrau"])) ** k_desc   # o alvo do degrau, sem a escada
    else:
        mult = (1 + passo) ** k_esc
        if usa_bonus:
            mult *= 1 + float(ordem["marco_21_12"]["aumento"])
        v = min(alvo * mult, max(teto, alvo))   # o teto limita a escada, não o alvo
    v = max(v, piso)
    v = min(v, mx)
    lim_sup = min(max(teto, alvo), mx)
    pc = pct_de(cfg, q, v)
    if reais_de(cfg, q, pc) > lim_sup + 0.01:
        pc = pct_de(cfg, q, lim_sup, "floor")
    elif reais_de(cfg, q, pc) < piso - 0.01 and reais_de(cfg, q, pct_de(cfg, q, piso, "ceil")) <= mx + 0.01:
        pc = pct_de(cfg, q, piso, "ceil")
    desejado = reais_de(cfg, q, pc)
    segurado = desejado > mx_hoje + 0.5
    if segurado:
        pc = pct_de(cfg, q, mx_hoje, "floor")
    return dict(a, alvo=alvo, teto=min(teto, mx_hoje), piso=piso, escada=k_esc, degraus=k_desc, bonus=usa_bonus,
                pct=pc, valor=reais_de(cfg, q, pc), desejado=desejado, segurado_no_maximo=segurado, mx=mx_hoje)


def _piso_ef(cfg: dict, k: str, x: dict) -> float:
    """Piso do período no % inteiro da base (para cima): o menor valor que o quarto pode ter."""
    return reais_de(cfg, k, pct_de(cfg, k, x["piso"], "ceil"))


def _teto_pct(cfg: dict, k: str, x: dict) -> int:
    return pct_de(cfg, k, min(max(x["teto"], x["alvo"]), x["mx"]), "floor")


def _por_pct(cfg: dict, k: str, x: dict, pc: int) -> None:
    x["pct"], x["valor"] = pc, reais_de(cfg, k, pc)


def encadear_pisos(cfg: dict, ps: dict) -> dict:
    """Pisos em cadeia no Recanto (proposta aprovada, final.precos_recomendados: piso da Panorâmica II no Réveillon 4.070
    enviados, +156, "igual ao da Double, para manter a ordem Moinhos ≤ Panorâmica II ≤ Panorâmica I"): o piso da Q2 nunca
    fica abaixo do piso efetivo da Q7, e o da Double nunca abaixo do da Q2 menos R$ 1 (o % inteiro da base não deixa
    igualar). Quem fica abaixo do piso novo sobe até ele (dentro do teto). Revisão de 16/11: com 4 degraus a coerência
    baixava a Moinhos para R$ 4.046,85, abaixo do piso dela (R$ 4.051,35)."""
    notas = {}
    for de_baixo, de_cima, folga in (("Q7", "Q2", 0.0), ("Q2", "Double", TOL_ORDEM)):
        if de_baixo not in ps or de_cima not in ps:
            continue
        pe = _piso_ef(cfg, de_baixo, ps[de_baixo]) - folga
        x = ps[de_cima]
        if pe > x["piso"] + 0.01:
            x["piso"] = pe
            if x["valor"] < _piso_ef(cfg, de_cima, x) - 0.01:
                _por_pct(cfg, de_cima, x, min(pct_de(cfg, de_cima, x["piso"], "ceil"), max(_teto_pct(cfg, de_cima, x), x["pct"])))
                notas[de_cima] = f"piso em cadeia: não abaixo do piso de {de_baixo} ({br(pe + folga, 2)})"
    return notas


def coerencia_recanto(cfg: dict, ps: dict, n: str) -> dict:
    """Moinhos ≤ Panorâmica II ≤ Panorâmica I (dono 21/10; quem passa desce ao valor da de cima, nunca abaixo do próprio piso
    do período: aí a de cima sobe, dentro do teto; tolerância de R$ 1 do % inteiro da base) e Afrodite a mais cara (15%
    acima da Panorâmica I e o prêmio sobre a mais cara das outras; sobe até o teto dela). Devolve {quarto: nota}."""
    notas = encadear_pisos(cfg, ps)

    def acima(a: str, b: str) -> bool:
        return a in ps and b in ps and ps[a]["valor"] > ps[b]["valor"] + TOL_ORDEM

    def ajustar(de_baixo: str, de_cima: str) -> None:
        xb, xc = ps[de_baixo], ps[de_cima]
        pc = pct_de(cfg, de_baixo, xc["valor"], "floor")
        if reais_de(cfg, de_baixo, pc) >= _piso_ef(cfg, de_baixo, xb) - 0.01:
            _por_pct(cfg, de_baixo, xb, pc)
            notas[de_baixo] = f"{NOME_SUITE[de_baixo]} limitada à {NOME_SUITE[de_cima]} (ordem do Recanto)"
        else:
            _por_pct(cfg, de_cima, xc, min(pct_de(cfg, de_cima, xb["valor"], "ceil"), max(_teto_pct(cfg, de_cima, xc), xc["pct"])))
            notas[de_cima] = (f"{NOME_SUITE[de_cima]} levada à {NOME_SUITE[de_baixo]}, que está no piso do período "
                              "(ordem do Recanto)")

    if acima("Q2", "Double"):
        ajustar("Q2", "Double")
    if acima("Q7", "Q2"):
        ajustar("Q7", "Q2")
        if acima("Q2", "Double"):
            ajustar("Q2", "Double")
    if "Afrodite" in ps and "Double" in ps:
        from ordem_recanto import premio_afrodite
        outras = max(ps[k]["valor"] for k in ("Q7", "Q2", "Double") if k in ps)
        ref = max(ps["Double"]["valor"] * PD.AFRODITE_SOBRE_DOUBLE, outras * (1 + premio_afrodite(cfg, data(n))))
        if ps["Afrodite"]["valor"] < ref - 0.01:
            pc = min(pct_de(cfg, "Afrodite", ref, "ceil"), pct_de(cfg, "Afrodite", ps["Afrodite"]["teto"], "floor"))
            ps["Afrodite"]["pct"], ps["Afrodite"]["valor"] = pc, reais_de(cfg, "Afrodite", pc)
            notas["Afrodite"] = "Afrodite levada a 15% acima da Panorâmica I (a mais cara do Recanto)"
    return notas


def regra_g(cfg: dict, ordem: dict, estado: dict, ps: dict, inv: dict, n: str) -> str | None:
    """Balcony: 90% da suíte com banheira livre mais barata da Villa (Regra G; no feriado só o lado de cima, 85,5-90% no
    validador) ou, com 8 ou 9 das 9 suítes com banheira vendidas, o mercado do quarto sem banheira (lista p50 da régua
    ÷ fator da Villa), nunca acima da Regra G nem abaixo do piso do período. Revisão de 16/11: o piso do período da Balcony
    vale também na Regra G quando cabe na folga do validador (90% + 1% da base, a do % inteiro: a proposta pôs o piso do
    fim de semana em +138, 90,2% do piso da King Spa 2); acima disso fica a Regra G, com nota."""
    if "Balcony" not in ps:
        return None
    refs = [ps[k]["valor"] for k in PD.BANHEIRA_VILLA if k in ps and (livres(inv, k, n) or 0) > 0]
    if not refs:
        refs = [ps[k]["valor"] for k in PD.BANHEIRA_VILLA if k in ps]
    if not refs:
        return None
    ref = min(refs)
    desc = float(cfg["parametros"].get("regra_g_desconto", 0.10))
    hi = math.floor(ref * (1 - desc))
    p_hi = pct_de(cfg, "Balcony", hi, "floor")
    bx = ps["Balcony"]
    vend = sum(unidades(cfg, k) - int(livres(inv, k, n) or 0) for k in PD.BANHEIRA_VILLA)
    tot = sum(unidades(cfg, k) for k in PD.BANHEIRA_VILLA)
    rn = PD.noite_regua(n)
    merc_pub = PD.ancora(rn, "lista", "50") if rn else None
    if PD.balcony_no_mercado(vend, tot) and merc_pub:
        merc = merc_pub / bx["fator"]
        pc = max(pct_de(cfg, "Balcony", merc, "ceil"), pct_de(cfg, "Balcony", bx["piso"], "ceil"))
        pc = min(pc, p_hi)
        bx["pct"], bx["valor"] = pc, reais_de(cfg, "Balcony", pc)
        return (f"regra dos 80% ({vend}/{tot} suítes com banheira vendidas): mercado do quarto sem banheira "
                f"{br(merc_pub)} público ÷ {fx(bx['fator'])}, até 90% da suíte livre ({br(ref)})")
    nota = f"Regra G: 90% da suíte com banheira livre mais barata ({br(ref)})"
    pc = p_hi
    p_piso = pct_de(cfg, "Balcony", bx["piso"], "ceil")
    if p_piso > p_hi:
        folga = 0.01 * base(cfg, "Balcony") + 1
        if reais_de(cfg, "Balcony", p_piso) <= ref * (1 - desc) + folga and reais_de(cfg, "Balcony", p_piso) < ref - 1:
            pc = p_piso
            nota += f"; no piso do período ({br(bx['piso'], 2)})"
        else:
            nota += f"; abaixo do piso do período ({br(bx['piso'], 2)}): a Regra G vence"
    bx["pct"], bx["valor"] = pc, reais_de(cfg, "Balcony", pc)
    return nota


# ---------------------------------------------------------------- plano
def contexto(cfg: dict, ordem: dict, estado: dict, inv: dict, reservas: list[dict] | None, hoje: date,
             receita: float | None = None) -> dict:
    """Pacotes, alarme, escada, degraus e bônus do marco de 21/12 de hoje (sem gravar nada); devolve também o estado novo.
    `reservas` None = sem leitura: nenhum pacote contado (a contagem por diferença daria todas as reservas da base como
    canceladas)."""
    novo = copy.deepcopy(estado)
    corte = ler_instante(estado["linha_base"]["reservas_lidas_em"])
    if reservas is None:
        cont = {"Recanto": {b: 0 for b in BLOCOS}, "Villa": {b: 0 for b in BLOCOS}, "total": {b: 0 for b in BLOCOS},
                "detalhe": [], "modo": "sem leitura"}
    else:
        cont = contar_pacotes(cfg, reservas, corte, (estado.get("linha_base") or {}).get("cobertura_reservas"))
    qn = quarto_noites_vendidas(cfg, inv)
    pausa, txt_alarme = abaixo_do_alarme(ordem, hoje, qn, receita)
    base_livres = estado["linha_base"]["pacotes_livres"]
    escada, degraus = {}, {}
    for p in POUSADAS:
        escada[p], degraus[p] = {}, {}
        for b in BLOCOS:
            ant = ((estado.get("escada") or {}).get(p) or {}).get(b)
            escada[p][b] = escada_usada(cont[p][b], ant, pausa)
            ant_d = ((estado.get("degraus") or {}).get(p) or {}).get(b) or {}
            dg = degraus_do_dia(ordem, b, hoje, cont["total"][b], cont[p][b], parte_da_pousada(base_livres, p, b), ant_d)
            degraus[p][b] = dg["valor"]
            novo.setdefault("escada", {}).setdefault(p, {})[b] = escada[p][b]
            novo.setdefault("degraus", {}).setdefault(p, {})[b] = dg
    bonus = bonus_marco(ordem, estado, hoje, cont["total"])
    novo["bonus_marco"] = bonus
    novo["pacotes"] = {"Recanto": cont["Recanto"], "Villa": cont["Villa"], "total": cont["total"],
                       "contados_em": hoje.isoformat()}
    return {"pacotes": cont, "quarto_noites": qn, "pausa": pausa, "alarme": txt_alarme, "escada": escada,
            "degraus": degraus, "bonus_marco": bonus, "estado_novo": novo}


def _estadia_padrao(cfg: dict, ordem: dict, q: str, n: str) -> int:
    """Estadia mínima da noite quando não há regra por data para herdar (o item cria a regra): 2 no Réveillon; 01 e 02/01
    em 2 com o portão aberto para o quarto (config) ou na estadia de antes do portão (3)."""
    if BLOCO_DE[n] == "reveillon":
        return 2
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    if (((rh.get("override_por_data_por_quarto") or {}).get(BLOCOS["fds"][0]) or {}).get(q)) == "none":
        return 2
    return int(((ordem.get("portao_0103") or {}).get("estadia_antes") or {}).get(n, 3))


def mascara_padrao_afrodite(cfg: dict, n: str) -> tuple[str, str] | None:
    """(check_in, check_out) da Afrodite numa noite sem regra por data (revisão de 16/11: sem a máscara, o item que cria a
    regra deixava a Afrodite na máscara padrão do anúncio, 1111101, e abria a sexta 01/01 antes do portão e a quinta
    31/12). Pela config do quarto: 31/12 sem entrada e saída (saída só com a exceção 1 aplicada); 01/01 sem entrada (só
    com o portão aberto); 02/01 sem entrada e saída (entrada só com a exceção 2 aplicada). 30/12: a do anúncio (None)."""
    ovq = (((cfg.get("parametros") or {}).get("regra_h_beds24") or {}).get("override_por_data_por_quarto") or {})
    v = (ovq.get(n) or {}).get("Afrodite")
    if n == "2026-12-31":
        return "1110111", ("1111111" if v == "noCheckIn" else "1110111")
    if n == "2027-01-01":
        return ("1111111" if v == "none" else "1111011"), "1111111"
    if n == "2027-01-02":
        return ("1111111" if v == "noCheckOut" else "1111101"), "1111101"
    return None


def conferir_da_regra(reg: dict) -> dict:
    """O que a regra por data tem e o item de preço não leva (a API mescla os campos): a estadia e, com a máscara ligada,
    check_in/check_out. Vai em `conferir` no item (o payload ignora) para conferir na resposta da gravação (persisted
    overrides) que nada sumiu (revisão de 16/11)."""
    out = {}
    if reg.get("min_stay") not in (None, ""):
        out["min_stay"] = int(reg["min_stay"])
    if str(reg.get("check_in_check_out_enabled") or "") == "1":
        out.update(check_in_check_out_enabled="1", check_in=str(reg.get("check_in")), check_out=str(reg.get("check_out")))
    return out


def itens_de_anuncio(cfg: dict, ordem: dict, finais: dict, hoje: date, listings: dict | None) -> tuple[list[dict], list[str]]:
    """Item do máximo do anúncio (R$ 6.000) só quando o preço planejado de uma pousada precisa passar do máximo de hoje e
    não antes de execucao_skill.maximo_anuncio.gravar_a_partir_de (revisão de 16/11: as noites de 19 a 22/11 têm teto
    manual do dono em 'percent_max' sobre o máximo do anúncio). Sobe só os quartos da lista na pousada que precisa."""
    ma = ordem.get("maximo_anuncio") or {}
    ex = (ordem.get("execucao_skill") or {}).get("maximo_anuncio") or ma.get("execucao") or {}
    precisa = {}
    for n, ps in finais.items():
        if data(n) < hoje:
            continue
        for q, x in ps.items():
            if x.get("segurado_no_maximo"):
                precisa.setdefault(POUSADA_DE[q], set()).add(q)
    itens, avisos = [], []
    for p, qs in sorted(precisa.items()):
        desde = ex.get("gravar_a_partir_de")
        if desde and hoje < data(desde):
            avisos.append(f"{p}: {', '.join(sorted(qs))} seguram no máximo de hoje (R$ 5.000) até o anúncio subir a "
                          f"{br(ma.get('para'))}, a partir de {data(desde).strftime('%d/%m')} (noites de 19 a 22/11 com "
                          "teto manual do dono sobre o máximo)")
            continue
        if not listings:
            avisos.append(f"{p}: {', '.join(sorted(qs))} precisam do máximo de {br(ma.get('para'))}: rode com --listings "
                          "(get_listing_data) para gerar o item de anúncio")
            continue
        for q in ma.get("quartos") or []:
            v = (listings or {}).get(q)
            if POUSADA_DE.get(q) != p or not isinstance(v, dict) or v.get("max") is None \
                    or float(v["max"]) >= float(ma["para"]):
                continue
            itens.append({"tipo": "anuncio", "quarto": q, "campo": "max", "valor": int(ma["para"]),
                          "antes": int(float(v["max"])), "excecao": "dono", "ordem_id": ORDEM_ID,
                          "motivo": (f"Dono 16/11 'Pode fazer' ({ORDEM_ID}): máximo do anúncio de {br(v['max'])} para "
                                     f"{br(ma['para'])} (Réveillon, 30/12 a 02/01: {', '.join(sorted(qs))} no máximo); "
                                     "nas outras noites a skill segura R$ 5.000 por data")})
        avisos.append(f"{p}: item de anúncio (máximo {br(ma.get('para'))}) no plano; antes de gravar, `vigia-maximo --antes` "
                      "nos 12 meses; depois de gravar e conferir, `registrar` passa quartos.json a "
                      f"{int(ma.get('para') or 0)} e o próximo plano sobe o preço")
    return itens, avisos


def montar_plano(cfg: dict, ordem: dict, estado: dict, inv: dict, regras: dict, hoje: date,
                 reservas: list[dict] | None = None, receita: float | None = None,
                 listings: dict | None = None) -> dict:
    """Itens de preço (validar_plano.py) para as 4 noites e os 7 quartos, mais a linha de diagnóstico de cada suíte-noite
    e o estado novo (degraus, escada). Item só onde o percentual muda; nunca sobe a noite de hoje (nem grava a de hoje sem
    regra por data vigente) e nunca grava noite passada. O item leva só piso e teto (a API mescla os campos: a estadia e a
    máscara da regra ficam; revisão de 16/11: copiá-las desfazia o portão e as exceções gravados na mesma rotina), com a
    estadia da regra em `estadia_vigente` para o validador; sem regra na noite, leva a estadia padrão."""
    ctx = contexto(cfg, ordem, estado, inv, reservas, hoje, receita)
    finais: dict = {}
    notas_noite: dict = {}
    for n in NOITES:
        ps = {}
        for q in QUARTOS:
            x = preco_bruto(cfg, ordem, estado, q, n, ctx["escada"], ctx["degraus"], ctx["bonus_marco"].get("ativo"))
            if x is not None:
                ps[q] = x
        notas = coerencia_recanto(cfg, ps, n)
        g = regra_g(cfg, ordem, estado, ps, inv, n)
        if g:
            notas["Balcony"] = g
        finais[n] = ps
        notas_noite[n] = notas
    itens, linhas = [], []
    for n in NOITES:
        for q in QUARTOS:
            reg = (regras.get(q) or {}).get(n) or {}
            p_min, p_max = pct_vigente(reg, "min_price"), pct_vigente(reg, "max_price")
            antes = reais_de(cfg, q, p_min) if p_min is not None else None
            x = finais[n].get(q)
            lin = {"quarto": q, "noite": n, "bloco": BLOCO_DE[n], "antes": antes, "pct_antes": p_min,
                   "livres": livres(inv, q, n)}
            if data(n) < hoje:
                lin.update(novo=antes, pct=p_min, acao="noite passada (não grava)")
                linhas.append(lin)
                continue
            if x is None:
                lin.update(novo=antes, pct=p_min, acao="mantém (Villa no fim de semana: aguarda a promoção sair e o "
                           "fator novo)")
                linhas.append(lin)
                continue
            pc, val = x["pct"], x["valor"]
            if n == hoje.isoformat() and (antes is None or val > antes + 0.01):
                lin.update(novo=antes, pct=p_min, acao=("D0: a noite de hoje nunca sobe" if antes is not None else
                                                        "D0 sem regra por data vigente: não grava (nunca subir hoje)"))
                linhas.append(lin)
                continue
            pub = val * x["fator"]
            lin.update(novo=val, pct=pc, publico=round(pub, 2), alvo_publico=x["publico"], escada=x["escada"],
                       degraus=x["degraus"], teto=round(x["teto"], 2), piso=round(x["piso"], 2), origem=x["origem"])
            if x.get("cortado_pelo_pedido"):
                lin["aviso"] = (f"alvo limitado ao pedido de 09/11: público ~{br(pub)} abaixo do alvo "
                                f"{br(x['publico'])} (fator {fx(x['fator'])}: promoção ou Genius somados?)")
            if x.get("segurado_no_maximo"):
                lin["aviso"] = (f"segurado no máximo de hoje ({br(x['mx'])}); o plano pede {br(x['desejado'], 2)} "
                                "(máximo do anúncio a subir)")
            if p_min == pc and p_max == pc:
                lin["acao"] = "já vigente"
                linhas.append(lin)
                continue
            lin["acao"] = "grava"
            linhas.append(lin)
            b = BLOCO_DE[n]
            partes = [f"Dono 16/11 'Pode fazer' ({ORDEM_ID}): {NOME_BLOCO[b]}, {x['origem']}"]
            if x["escada"]:
                partes.append(f"escada {x['escada']} pacote(s) +{float(ordem['escada'][b]):.0%}")
            if x.get("bonus"):
                partes.append("marco de 21/12 (+5%, uma vez)")
            if x["degraus"]:
                partes.append(f"descida {x['degraus']} degrau(s) de 5%")
            if x.get("segurado_no_maximo"):
                partes.append(f"no máximo de hoje {br(x['mx'])}")
            if notas_noite[n].get(q):
                partes.append(notas_noite[n][q])
            partes.append(f"público ~{br(pub)}" + ("" if x["publico"] else f" (fator {fx(x['fator'])})"))
            if antes is not None:
                partes.append(f"antes {br(antes)}")
            it = {"tipo": "data", "quarto": q, "data": n, "min_price": val, "max_price": val,
                  "preco_antes": antes, "motivo": "; ".join(partes)[:250], "excecao": "dono", "ordem_id": ORDEM_ID}
            if reg.get("min_stay") not in (None, ""):
                it["estadia_vigente"] = int(reg["min_stay"])   # fica na regra (a API mescla); só para o validador
            elif not reg:
                it["min_stay"] = _estadia_padrao(cfg, ordem, q, n)   # noite sem regra: o item cria a regra
                masc = mascara_padrao_afrodite(cfg, n) if q == "Afrodite" else None
                if masc:
                    it["check_in"], it["check_out"] = masc
            cf = conferir_da_regra(reg)
            if cf:
                it["conferir"] = cf
            itens.append(it)
    an, avisos = itens_de_anuncio(cfg, ordem, finais, hoje, listings)
    itens += an
    for x in linhas:
        if x.get("aviso"):
            avisos.append(f"{x['quarto']} {x['noite']}: {x['aviso']}")
    return {"hoje": hoje.isoformat(), "itens": itens, "linhas": linhas, "notas": notas_noite, "avisos": avisos,
            "contexto": {k: v for k, v in ctx.items() if k != "estado_novo"}, "estado_novo": ctx["estado_novo"]}


def relatorio_md(cfg: dict, res: dict) -> str:
    c = res["contexto"]
    pk = c["pacotes"]
    out = [f"# Réveillon 250k — plano de {res['hoje']}", "",
           f"Pacotes novos desde 16/11 (líquidos): Réveillon Recanto {pk['Recanto']['reveillon']}, Villa "
           f"{pk['Villa']['reveillon']} (total {pk['total']['reveillon']}); fim de semana Recanto {pk['Recanto']['fds']}, "
           f"Villa {pk['Villa']['fds']} (total {pk['total']['fds']}).",
           f"Contagem: {pk.get('modo', 'instante')}. Quarto-noites vendidas nas 4 noites: {c['quarto_noites']}. "
           f"Alarme: {c['alarme']}"
           + (" — escada PARADA." if c["pausa"] else "."),
           "Escada (pacotes usados): " + "; ".join(f"{p} {c['escada'][p]['reveillon']}/{c['escada'][p]['fds']}"
                                                  for p in POUSADAS)
           + ". Degraus de descida: " + "; ".join(f"{p} {c['degraus'][p]['reveillon']}/{c['degraus'][p]['fds']}"
                                                  for p in POUSADAS) + " (Réveillon/fim de semana).", "",
           "| Quarto | Noite | Antes | Novo | % base | Público ~ | Alvo público | Livres | Ação |",
           "|---|---|---|---|---|---|---|---|---|"]
    for x in res["linhas"]:
        out.append(f"| {x['quarto']} | {x['noite'][8:10]}/{x['noite'][5:7]} | {br(x.get('antes'), 2)} | "
                   f"{br(x.get('novo'), 2)} | {x.get('pct') if x.get('pct') is not None else '—'} | "
                   f"{br(x.get('publico'))} | {br(x.get('alvo_publico'))} | {x.get('livres')} | {x['acao']} |")
    n_it = len([i for i in res["itens"] if i["tipo"] == "data"])
    n_an = len([i for i in res["itens"] if i["tipo"] == "anuncio"])
    out += ["", f"{n_it} itens por data e {n_an} de anúncio."]
    bm = c.get("bonus_marco") or {}
    if bm.get("ativo"):
        out.append(f"Marco de 21/12: bônus de +5% no Réveillon ligado em {bm.get('em')} (uma vez só).")
    if res.get("avisos"):
        out += ["", "Avisos:"] + [f"- {m}" for m in res["avisos"]]
    return "\n".join(out) + "\n"


# ---------------------------------------------------------------- portão do 01→03
def _comando_beds24(q: str, n: str, motivo: str, override: str | None = None, min_stay: int | None = None) -> str:
    partes = [f"python3 scripts/beds24.py editar --quarto {q} --de {n} --ate {n}"]
    if override:
        partes.append(f"--override {override}")
    if min_stay is not None:
        partes.append(f"--min-stay {min_stay}")
    partes.append(f"--motivo \"{motivo}\"")
    return " ".join(partes)


def juntar_itens(itens: list[dict], novos: list[dict]) -> list[dict]:
    """Junta itens da mesma noite e quarto num só (o validador barra item duplicado: a API mescla campos). Os `novos`
    (portão e exceções) vencem em estadia e máscara; o preço fica o dos `itens` (plano)."""
    out = [dict(i) for i in itens]
    por = {(i["quarto"], i.get("data")): i for i in out if i.get("tipo") == "data"}
    for it in novos:
        k = (it["quarto"], it.get("data"))
        if it.get("tipo") == "data" and k in por:
            x = por[k]
            for c, v in it.items():
                if c in ("motivo",):
                    x["motivo"] = (x.get("motivo", "") + "; " + v)[:250]
                elif c == "manter_reason":
                    continue
                elif c not in x or c in ("min_stay", "check_in", "check_out"):
                    x[c] = v
        else:
            out.append(dict(it))
            if it.get("tipo") == "data":
                por[k] = out[-1]
    for x in out:
        # item que ficou com preço não mantém o reason da regra (o validador barra manter_reason com preço)
        if x.get("manter_reason") and any(x.get(c) is not None for c in ("min_price", "max_price", "preco")):
            x.pop("manter_reason")
    return out


def envio_da_pousada(envio: dict, p: str) -> tuple[datetime | None, str | None]:
    """(instante, falta) do último envio do PriceLabs da pousada. O last_date_pushed é por anúncio: com `--envio
    Q7=... Q2=...`, vale o MAIS ANTIGO entre os quartos da pousada (todos têm de ter enviado); sem os quartos, o valor
    dado para a pousada (`--envio Recanto=...`)."""
    qs = POUSADAS[p]
    por_q = {q: envio[q] for q in qs if q in envio}
    if por_q:
        falta = [q for q in qs if q not in por_q or ler_instante(por_q[q]) is None]
        if falta:
            return None, f"sem last_date_pushed de {', '.join(falta)} (--envio por quarto: passe os {len(qs)} quartos)"
        return min(ler_instante(v) for v in por_q.values()), None
    return ler_instante(envio.get(p)), None


def pousadas_com_preco_fds(itens: list[dict] | None) -> dict:
    """{pousada: ["Q7 2027-01-01", ...]} com item de preço (piso, teto ou preço) em 01 ou 02/01 no plano da rotina."""
    out: dict = {}
    for it in itens or []:
        if it.get("tipo") == "data" and it.get("data") in BLOCOS["fds"] and it.get("quarto") in POUSADA_DE \
                and any(it.get(c) is not None for c in ("min_price", "max_price", "preco")):
            out.setdefault(POUSADA_DE[it["quarto"]], []).append(f"{it['quarto']} {it['data']}")
    return out


def alvo_publico_portao(ordem: dict, estado: dict, p: str, q: str) -> float:
    """Alvo público da ordem no fim de semana para o quarto (alvos.fds_publico), com os degraus de descida da pousada e
    sem a escada: a condição 2 do portão pede o público "dentro do alvo (±5%)", e a escada só sobe."""
    k = int((((estado.get("degraus") or {}).get(p) or {}).get("fds") or {}).get("valor") or 0)
    return float(ordem["alvos"]["fds_publico"][q]) * (1 - float(ordem["descida"]["degrau"])) ** k


def avaliar_portao(cfg: dict, ordem: dict, estado: dict, inv: dict, regras: dict, envio: dict, publico: dict,
                   tol_preco: float = 1.0, hoje: date | None = None, itens_plano: list[dict] | None = None) -> dict:
    """{pousada: {pode, faltam, conferido, prova}}. Condições (ordem, portao_0103):
      (1) PriceLabs enviou depois da gravação dos preços do fim de semana (estado.gravado_fds[pousada]; o envio mais
          antigo entre os quartos) e o price1 do Beds24 em 01 e 02/01 bate com o planejado (regra por data vigente, piso
          = teto) nos quartos com vaga na noite (revisão de 16/11: noite vendida não trava a pousada); e o plano de preço
          da MESMA rotina (`itens_plano`) não muda o fim de semana da pousada (senão o que foi conferido na Booking é o
          preço velho: o portão espera o próximo envio);
      (2) público do fim de semana medido na Booking dentro de ±5% do esperado (o quarto livre mais barato: enviado
          planejado × fator) E não abaixo do alvo público da ordem (alvos.fds_publico do quarto × 0,95^degraus, −5%;
          revisão de 16/11: com o fim de semana cortado no pedido de 09/11, o esperado caía junto e o portão abria com o
          público 12% abaixo do alvo);
      (3) na Villa, promoção retirada e fator novo medido.
    `prova` vai nos itens do PriceLabs do portão (o validador confere)."""
    tol = float(ordem["fator_publico"].get("tolerancia", 0.05))
    muda = pousadas_com_preco_fds(itens_plano)
    out = {}
    for p, qs in POUSADAS.items():
        faltam, conferido = [], []
        ja = ((estado.get("portao") or {}).get(p) or {}).get("aberto")
        grav = ler_instante((estado.get("gravado_fds") or {}).get(p))
        env, falta_env = envio_da_pousada(envio, p)
        if grav is None:
            faltam.append("preços do fim de semana ainda não gravados no PriceLabs (estado.gravado_fds)")
        elif falta_env:
            faltam.append(falta_env)
        elif env is None or env <= grav:
            faltam.append(f"PriceLabs sem envio depois da gravação ({env.isoformat() if env else 'sem leitura'} ≤ "
                          f"{grav.isoformat()})")
        else:
            conferido.append(f"envio {env.isoformat()} depois da gravação {grav.isoformat()}")
        if p in muda:
            faltam.append(f"o preço do fim de semana muda nesta rotina ({', '.join(muda[p])}): o portão espera o próximo "
                          "envio e a conferência na Booking")
        esperado = {}
        for q in qs:
            for n in BLOCOS["fds"]:
                if (livres(inv, q, n) or 0) <= 0:
                    continue   # sem vaga na noite: o price1 dela não vende nada
                reg = (regras.get(q) or {}).get(n) or {}
                pm, px = pct_vigente(reg, "min_price"), pct_vigente(reg, "max_price")
                if pm is None or pm != px:
                    faltam.append(f"{q} {n}: regra por data sem piso = teto")
                    continue
                plan = reais_de(cfg, q, pm)
                esperado[(q, n)] = plan
                p1 = ((inv.get(q) or {}).get(n) or {}).get("price1")
                if p1 is None:
                    faltam.append(f"{q} {n}: sem price1 do Beds24 (rode o inventário com --precos)")
                elif abs(float(p1) - plan) > tol_preco + 0.5:
                    faltam.append(f"{q} {n}: price1 {br(p1)} ≠ planejado {br(plan, 2)}")
        promo_ok = p != "Villa" or villa_fds_liberada(estado)
        if not promo_ok:
            faltam.append("promoção da Villa não retirada ou fator novo não medido depois da retirada")
        fator = float(estado["fatores"][p]["fds"]["valor"])
        abertos = [q for q in qs if min(livres(inv, q, n) or 0 for n in BLOCOS["fds"]) > 0]
        cand = sorted((esperado[(q, n)] * fator, q) for q in abertos for n in BLOCOS["fds"] if (q, n) in esperado)
        esp_pub, q_ref = (cand[0][0], cand[0][1]) if cand else (None, None)
        alvo_ord = alvo_publico_portao(ordem, estado, p, q_ref) if q_ref else None
        med = publico.get(p)
        if med is None:
            faltam.append("público do fim de semana não medido na Booking")
        elif esp_pub is None:
            faltam.append("sem quarto com o fim de semana livre para comparar o público")
        elif abs(float(med) / esp_pub - 1) > tol + 1e-9:
            faltam.append(f"público medido {br(med)} fora de ±{tol:.0%} do esperado {br(esp_pub)} ({q_ref}: enviado × "
                          f"fator {fx(fator)})")
        elif float(med) < alvo_ord * (1 - tol) - 1e-9:
            faltam.append(f"público medido {br(med)} abaixo do alvo da ordem ({q_ref} {br(alvo_ord)}, −{tol:.0%}): fim de "
                          "semana cortado no pedido de 09/11 ou fator baixo (promoção ou Genius somados?); avisar o dono, "
                          "o portão espera")
        else:
            conferido.append(f"público {br(med)} dentro de ±{tol:.0%} de {br(esp_pub)} e não abaixo do alvo da ordem "
                             f"({q_ref} {br(alvo_ord)})")
        prova = {"pousada": p, "avaliado_em": (hoje or date.today()).isoformat(),
                 "envio": env.strftime("%Y-%m-%dT%H:%M:%SZ") if env else None,
                 "gravado_fds": grav.strftime("%Y-%m-%dT%H:%M:%SZ") if grav else None,
                 "publico": float(med) if med is not None else None,
                 "esperado": round(esp_pub, 2) if esp_pub is not None else None,
                 "alvo_publico": round(alvo_ord, 2) if alvo_ord is not None else None, "quarto_ref": q_ref,
                 "promocao_retirada": bool(promo_ok) if p == "Villa" else None}
        out[p] = {"pode": not faltam, "ja_aberto": bool(ja), "faltam": faltam, "conferido": conferido, "prova": prova}
    return out


def decidir_portao(cfg: dict, ordem: dict, estado: dict, inv: dict, regras: dict, envio: dict, publico: dict,
                   hoje: date, itens_plano: list[dict] | None, mostrar: bool = False) -> tuple[dict, list[str]]:
    """A decisão do portão da rotina ({hoje, itens, portao, lote_beds24, comandos_beds24, patch_quartos}) e o texto.
    Só a pousada que passou (e ainda fechada) vai para os itens, o lote e o patch, e o patch dela fica em
    portao[pousada].patch_quartos para o `aplicar-config` (revisão de 16/11: com --mostrar e o portão fechado, o lote que
    ABRE o 01→03 era gravado; e o --aplicar-config reavaliava depois do `registrar`). Com `mostrar`, a pousada fechada
    só aparece no texto, como simulação."""
    av = avaliar_portao(cfg, ordem, estado, inv, regras, envio, publico, hoje=hoje, itens_plano=itens_plano)
    saida = {"hoje": hoje.isoformat(), "itens": [], "portao": av, "lote_beds24": [], "comandos_beds24": [],
             "patch_quartos": {}}
    linhas = []
    for p, x in av.items():
        abre = x["pode"] and not x["ja_aberto"]
        linhas.append(f"{p}: {'PODE ABRIR o 01→03' if x['pode'] else 'portão FECHADO'}"
                      + (" (já aberto)" if x["ja_aberto"] else ""))
        linhas += [f"  falta: {f}" for f in x["faltam"]] + [f"  ok: {c}" for c in x["conferido"]]
        if not (abre or mostrar):
            continue
        ac = acoes_portao(cfg, ordem, inv, regras, p, x["prova"] if abre else None, hoje)
        if abre:
            x["patch_quartos"] = ac["patch_quartos"]
            saida["itens"] = juntar_itens(saida["itens"], ac["itens_pricelabs"])
            saida["lote_beds24"] += [dict(i, portao_0103={"pousada": p, "avaliado_em": hoje.isoformat()})
                                     for i in ac["lote_beds24"]]
            saida["comandos_beds24"] += ac["comandos_beds24"]
            for k, v in ac["patch_quartos"].items():
                if isinstance(v, dict):
                    for ds, porq in v.items():
                        saida["patch_quartos"].setdefault(k, {}).setdefault(ds, {}).update(porq)
                else:
                    saida["patch_quartos"][k] = v
            linhas.append("  PriceLabs primeiro (itens no --saida; `juntar` com o plano e as órfãs, validar_plano.py), "
                          "depois o Beds24 (lote; --aplicar grava), depois `aplicar-config --portao`:")
        else:
            linhas.append("  SIMULAÇÃO (--mostrar, portão fechado): NÃO grave; nada vai para --saida nem --lote-beds24")
        linhas += [f"    {c}" for c in ac["comandos_beds24"]]
    return saida, linhas


def acoes_portao(cfg: dict, ordem: dict, inv: dict, regras: dict, pousada: str, prova: dict | None = None,
                 hoje: date | None = None) -> dict:
    """O que gravar para abrir o 01→03 numa pousada: comandos do Beds24 (simulação; --aplicar grava), lote do
    `beds24.py editar-lote`, itens do PriceLabs (estadia 2 em 01 e 02/01; sexta da Afrodite aberta; com a `prova` do portão
    em `portao_0103`, que o validador confere) e o patch de quartos.json (a auditoria diária confere; sem ele, desfaz).
    Noite já passada fica de fora."""
    n1, n2 = BLOCOS["fds"]
    mot = (f"Dono 16/11 ('Pode fazer', {ORDEM_ID}): abre o fim de semana 01→03/01 (chegada sexta 01/01, mínimo 2); "
           "30→01 e 30→03 mantidos; 02/01 segue sem entrada e saída")
    noites = [n for n in (n1, n2) if hoje is None or data(n) >= hoje]
    comandos, lote, itens = [], [], []
    patch = {"override_por_data_por_quarto": {n1: {}}, "estadia_minima_por_data_por_quarto": {n: {} for n in noites},
             "override_por_data_por_quarto_motivo": ("Dono 16/11 ('Pode fazer', ordem dono-2026-11-16-reveillon-250k): "
                                                     "01→03 aberto por pousada depois do portão; exceções das noites "
                                                     "soltas por quarto")}
    for q in POUSADAS[pousada]:
        for n in noites:
            v = (inv.get(q) or {}).get(n) or {}
            if n == n1:
                comandos.append(_comando_beds24(q, n1, mot, override="none", min_stay=2))
                lote.append({"quarto": q, "de": n1, "ate": n1, "override": "none", "motivo": mot})
            else:
                comandos.append(_comando_beds24(q, n, mot, min_stay=2))
            it_l = {"quarto": q, "de": n, "ate": n, "min_stay": 2, "motivo": mot, "pricelabs_tambem": True}
            if v.get("minStay") is not None:
                it_l["min_stay_antes"] = int(v["minStay"])
            lote.append(it_l)
            it = {"tipo": "data", "quarto": q, "data": n, "min_stay": 2, "manter_reason": True,
                  "excecao": "dono", "ordem_id": ORDEM_ID, "motivo": mot}
            if prova:
                it["portao_0103"] = dict(prova)
            if q == "Afrodite" and n == n1:
                it.update(check_in="1111111", check_out="1111111")  # sexta 01/01 com chegada e saída
            itens.append(it)
            patch["estadia_minima_por_data_por_quarto"][n][q] = 2
        if n1 in noites:
            patch["override_por_data_por_quarto"][n1][q] = "none"
    patch = {k: v for k, v in patch.items() if not isinstance(v, dict) or any(v.values())}
    return {"comandos_beds24": comandos, "lote_beds24": lote, "itens_pricelabs": itens, "patch_quartos": patch}


def aplicar_patch(cfg_bruto: dict, patch: dict) -> dict:
    """Aplica o patch em parametros.regra_h_beds24 (fusão por data e quarto; valor None apaga a chave)."""
    rh = cfg_bruto["parametros"]["regra_h_beds24"]
    for chave, v in patch.items():
        if not isinstance(v, dict):
            rh[chave] = v
            continue
        dst = rh.setdefault(chave, {})
        for ds, porq in v.items():
            if porq is None:
                dst.pop(ds, None)
                continue
            alvo = dst.setdefault(ds, {})
            for q, x in porq.items():
                if x is None:
                    alvo.pop(q, None)
                else:
                    alvo[q] = x
            if not alvo:
                dst.pop(ds, None)
        if not dst:
            rh.pop(chave, None)
    return cfg_bruto


# ---------------------------------------------------------------- noites soltas (exceções 1 e 2)
def avaliar_orfas(cfg: dict, ordem: dict, estado: dict, inv: dict, regras: dict | None = None,
                  hoje: date | None = None) -> dict:
    """Por quarto: cotas de venda direta (29→31 e 02→04) e se a exceção abre (ou fecha) nas OTAs, com o que gravar. Os
    itens do PriceLabs levam `excecao_noite_solta` e a prova do inventário do Beds24 (`orfa_prova`: o quarto, a noite
    livre e a vizinha esgotada nele, com o lido_em), que o validador confere. Com `hoje`: a exceção 1 só abre até 29/12
    (a chegada do 29→31) e a 2 até 02/01; noite já passada não gera item.
    Fecha (revisão de 16/11; a regra de dia órfão do PriceLabs manda mínimo 1 à noite isolada mesmo com regra por data,
    aprendizados.md): a exceção 1 quando 31/12 reabre, quando 29/12 esgota ou quando 29/12 passa (ninguém mais faz o
    29→31; a 30/12 isolada viraria 30→31); a 2 quando 01/01 reabre ou quando 03/01 esgota (a 02/01 isolada viraria 02→03)."""
    regras = regras or {}
    lido_em = inv.get("_lido_em")

    def prova(q: str, noite: str, vizinha: str, lado: str) -> dict:
        return {"fonte": "beds24", "quarto": q, "unidades": unidades(cfg, q), "noite": noite,
                "noite_numAvail": livres(inv, q, noite), "vizinha_noite": vizinha,
                f"{lado}_numAvail": livres(inv, q, vizinha), "lido_em": lido_em}
    rh = (cfg.get("parametros") or {}).get("regra_h_beds24") or {}
    ovq = rh.get("override_por_data_por_quarto") or {}
    msq = rh.get("estadia_minima_por_data_por_quarto") or {}
    r31, f30, s01, s02 = "2026-12-31", "2026-12-30", "2027-01-01", "2027-01-02"
    out = {"quartos": {}, "comandos_beds24": [], "lote_beds24": [], "itens_pricelabs": [], "patch_quartos": {},
           "avisos": []}
    patch = {"override_por_data_por_quarto": {}, "estadia_minima_por_data_por_quarto": {}}
    mot1 = (f"Dono 16/11 ('Pode fazer', {ORDEM_ID}), exceção 1: 31/12 esgotada, noite solta em 30/12; saída em 31/12 "
            "liberada (29→31); 30→31 barrada pela mínima 2 de 30/12")
    mot2 = (f"Dono 16/11 ('Pode fazer', {ORDEM_ID}), exceção 2: 01/01 esgotada; entrada no sábado 02/01 liberada "
            "(02→04, mínimo 2); saída em 02/01 segue fechada")
    mot_volta = f"Dono 16/11 ({ORDEM_ID}): a noite vizinha reabriu; volta o pacote (sem entrada e saída)"
    ms03 = {}
    for q in QUARTOS:
        L = {n: livres(inv, q, n) for n in (VESPERA, f30, r31, s01, s02, DOMINGO)}
        if any(v is None for v in L.values()):
            out["avisos"].append(f"{q}: inventário sem as noites de 29/12 a 03/01")
            continue
        soltas30, soltas02 = max(0, L[f30] - L[r31]), max(0, L[s02] - L[s01])
        e1 = L[r31] <= 0 < L[f30] and L[VESPERA] > 0 and (hoje is None or hoje <= data(VESPERA))
        e2 = L[s01] <= 0 < L[s02] and L[DOMINGO] > 0 and (hoje is None or hoje <= data(s02))
        # desfaz quando a noite vizinha reabre (ordem: "quando a noite vizinha reabrir, volta") e quando a estadia aprovada
        # não cabe mais (29/12 esgotada ou passada; 03/01 esgotada), enquanto a noite não passou
        fecha1 = (L[r31] > 0 or L[VESPERA] <= 0 or (hoje is not None and hoje > data(VESPERA))) \
            and (hoje is None or data(r31) >= hoje)
        fecha2 = (L[s01] > 0 or L[DOMINGO] <= 0) and (hoje is None or data(s02) >= hoje)
        e1_aplicada = (ovq.get(r31) or {}).get(q) == "noCheckIn"
        e2_aplicada = (ovq.get(s02) or {}).get(q) == "noCheckOut"
        portao_aberto = (ovq.get(s01) or {}).get(q) == "none" or (rh.get("override_por_data") or {}).get(s01) == "none"
        ms_volta_02 = 2 if portao_aberto else int(((ordem.get("portao_0103") or {}).get("estadia_antes") or {}).get(s02, 3))
        ms03[q] = ((inv.get(q) or {}).get(DOMINGO) or {}).get("minStay")
        info = {"livres": L, "soltas_30_12": soltas30, "venda_direta_29_31": min(soltas30, L[VESPERA]),
                "soltas_02_01": soltas02, "venda_direta_02_04": min(soltas02, L[DOMINGO]),
                "excecao_1_ota": "abre" if e1 else "fechada", "excecao_2_ota": "abre" if e2 else "fechada",
                "excecao_1_aplicada": e1_aplicada, "excecao_2_aplicada": e2_aplicada}
        reg31, reg02 = (regras.get(q) or {}).get(r31) or {}, (regras.get(q) or {}).get(s02) or {}
        if e1 and not e1_aplicada:
            info["acao_1"] = "abrir"
            p1 = prova(q, f30, r31, "seguinte")
            out["comandos_beds24"].append(_comando_beds24(q, r31, mot1, override="noCheckIn"))
            out["lote_beds24"].append({"quarto": q, "de": r31, "ate": r31, "override": "noCheckIn", "motivo": mot1})
            patch["override_por_data_por_quarto"].setdefault(r31, {})[q] = "noCheckIn"
            if q == "Afrodite":
                out["itens_pricelabs"].append({"tipo": "data", "quarto": q, "data": r31, "manter_reason": True,
                                               "check_in": str(reg31.get("check_in") or "1110111"), "check_out": "1111111",
                                               "excecao": "dono", "ordem_id": ORDEM_ID, "motivo": mot1,
                                               "excecao_noite_solta": 1, "orfa_prova": p1})
        elif e1_aplicada and fecha1:
            info["acao_1"] = "fechar"
            out["comandos_beds24"].append(_comando_beds24(q, r31, mot_volta, override="noCheckInOrCheckOut"))
            out["lote_beds24"].append({"quarto": q, "de": r31, "ate": r31, "override": "noCheckInOrCheckOut",
                                       "motivo": mot_volta})
            patch["override_por_data_por_quarto"].setdefault(r31, {})[q] = None
            if q == "Afrodite":
                out["itens_pricelabs"].append({"tipo": "data", "quarto": q, "data": r31, "manter_reason": True,
                                               "check_in": "1110111", "check_out": "1110111",
                                               "excecao": "dono", "ordem_id": ORDEM_ID, "motivo": mot_volta})
        if e2 and not e2_aplicada:
            info["acao_2"] = "abrir"
            p2 = prova(q, s02, s01, "anterior")
            out["comandos_beds24"].append(_comando_beds24(q, s02, mot2, override="noCheckOut", min_stay=2))
            ms_antes = ((inv.get(q) or {}).get(s02) or {}).get("minStay")
            out["lote_beds24"].append({"quarto": q, "de": s02, "ate": s02, "override": "noCheckOut", "motivo": mot2})
            it_l = {"quarto": q, "de": s02, "ate": s02, "min_stay": 2, "motivo": mot2, "pricelabs_tambem": True}
            if ms_antes is not None:
                it_l["min_stay_antes"] = int(ms_antes)
            out["lote_beds24"].append(it_l)
            patch["override_por_data_por_quarto"].setdefault(s02, {})[q] = "noCheckOut"
            patch["estadia_minima_por_data_por_quarto"].setdefault(s02, {})[q] = 2
            it = {"tipo": "data", "quarto": q, "data": s02, "min_stay": 2, "manter_reason": True,
                  "excecao": "dono", "ordem_id": ORDEM_ID, "motivo": mot2, "excecao_noite_solta": 2, "orfa_prova": p2}
            if q == "Afrodite":
                it.update(check_in="1111111", check_out=str(reg02.get("check_out") or "1111101"))
            out["itens_pricelabs"].append(it)
            if ms03[q] is not None and int(ms03[q]) > 2:
                out["avisos"].append(f"{q}: 03/01 com estadia mínima {ms03[q]} no Beds24: o 02→04 não cabe (stayThrough)")
        elif e2_aplicada and fecha2:
            info["acao_2"] = "fechar"
            out["comandos_beds24"].append(_comando_beds24(q, s02, mot_volta, override="noCheckInOrCheckOut",
                                                          min_stay=ms_volta_02))
            out["lote_beds24"].append({"quarto": q, "de": s02, "ate": s02, "override": "noCheckInOrCheckOut",
                                       "motivo": mot_volta})
            out["lote_beds24"].append({"quarto": q, "de": s02, "ate": s02, "min_stay": ms_volta_02, "motivo": mot_volta,
                                       "pricelabs_tambem": True})
            patch["override_por_data_por_quarto"].setdefault(s02, {})[q] = None
            patch["estadia_minima_por_data_por_quarto"].setdefault(s02, {})[q] = 2 if portao_aberto else None
            it = {"tipo": "data", "quarto": q, "data": s02, "min_stay": ms_volta_02, "manter_reason": True,
                  "excecao": "dono", "ordem_id": ORDEM_ID, "motivo": mot_volta}
            if q == "Afrodite":
                it.update(check_in="1111101", check_out="1111101")
            out["itens_pricelabs"].append(it)
        out["quartos"][q] = info
    out["patch_quartos"] = {k: v for k, v in patch.items() if v}
    if out["patch_quartos"]:
        out["patch_quartos"]["override_por_data_por_quarto_motivo"] = (
            "Dono 16/11 ('Pode fazer', ordem dono-2026-11-16-reveillon-250k): 01→03 aberto por pousada depois do "
            "portão; exceções das noites soltas por quarto")
    return out


CHAVES_DO_PLANO = ("escada", "degraus", "pacotes", "bonus_marco")


def fundir_estado(atual: dict, plano: dict) -> dict:
    """Estado em disco + o que o plano decide (revisão de 16/11: gravar o estado_novo inteiro desfazia o portão aberto
    entre o `plano` e o `registrar`). Do plano: escada, degraus, pacotes, bônus do marco e as chaves que as flags da rodada
    mudaram (`estado_flags`: fatores medidos, promoção da Villa, concorrente). O resto (portão, linha de base, gravado_fds
    de outra pousada) fica o do disco."""
    novo = copy.deepcopy(atual)
    en = plano.get("estado_novo") or {}
    for k in CHAVES_DO_PLANO:
        if k in en:
            novo[k] = copy.deepcopy(en[k])
    for caminho in plano.get("estado_flags") or []:
        orig, dst = en, novo
        for k in caminho[:-1]:
            orig = (orig or {}).get(k) or {}
            dst = dst.setdefault(k, {})
        if caminho[-1] in (orig or {}):
            dst[caminho[-1]] = copy.deepcopy(orig[caminho[-1]])
    return novo


def registrar(plano: dict, em: datetime, atual: dict | None = None) -> dict:
    """Estado depois de gravar e conferir o plano no PriceLabs: o estado em disco (`atual`; sem ele, o do plano) fundido com
    o que o plano decide (fundir_estado) e, para a pousada com item de preço no fim de semana, o instante da gravação
    (condição 1 do portão: o envio tem de ser posterior)."""
    novo = fundir_estado(atual if atual is not None else (plano.get("estado_novo") or {}), plano)
    for it in plano.get("itens") or []:
        if it.get("tipo") == "data" and it.get("data") in BLOCOS["fds"] and it.get("min_price") is not None:
            novo.setdefault("gravado_fds", {})[POUSADA_DE[it["quarto"]]] = em.astimezone(timezone.utc).strftime(
                "%Y-%m-%dT%H:%M:%SZ")
    novo["atualizado_em"] = em.astimezone(timezone(timedelta(hours=-3))).date().isoformat()
    return novo


def aplicar_config(cfg_bruto: dict, estado: dict, hoje: date, portao: dict | None = None,
                   orfas: dict | None = None) -> tuple[list[str], list[str]]:
    """Aplica em quartos.json (regra_h_beds24) e no estado a DECISÃO gravada pelo `portao` e pelo `orfas` desta rotina,
    sem reavaliar (revisão de 16/11: o `portao --aplicar-config` reavaliava depois do `registrar` e, com o preço do fim de
    semana regravado, a condição 1 falhava e a config ficava sem o patch: a auditoria do Beds24 fechava o 01→03 de novo).
    Só a pousada com `pode` e a prova avaliada hoje; decisão de outro dia é recusada. Devolve (feitas, recusas)."""
    feitas, recusas = [], []
    h = hoje.isoformat()
    for nome, d in (("portao", portao), ("orfas", orfas)):
        if d is not None and d.get("hoje") != h:
            recusas.append(f"{nome}: decisão de {d.get('hoje')}, não de hoje ({h}): rode de novo")
    if portao and portao.get("hoje") == h:
        for p, x in (portao.get("portao") or {}).items():
            if not x.get("pode") or x.get("ja_aberto"):
                continue
            if (x.get("prova") or {}).get("avaliado_em") != h or not x.get("patch_quartos"):
                recusas.append(f"portão {p}: sem prova de hoje ou sem o patch na decisão")
                continue
            aplicar_patch(cfg_bruto, copy.deepcopy(x["patch_quartos"]))
            estado.setdefault("portao", {})[p] = {"aberto": True, "em": h}
            feitas.append(f"portão do 01→03 aberto: {p}")
    if orfas and orfas.get("hoje") == h and orfas.get("patch_quartos"):
        aplicar_patch(cfg_bruto, copy.deepcopy(orfas["patch_quartos"]))
        feitas.append("exceções das noites soltas")
    return feitas, recusas


def aplicar_anuncio(cfg_bruto: dict, itens: list[dict]) -> list[str]:
    """quartos.json depois de gravar e CONFERIR o update_listing_data: o `max` dos quartos com item de anúncio da ordem
    passa ao valor gravado (antes disso o script segura o preço no máximo de hoje). Devolve as mudanças."""
    por = {q["curto"]: q for q in cfg_bruto["quartos"]}
    feitas = []
    for it in itens or []:
        if it.get("tipo") != "anuncio" or it.get("ordem_id") != ORDEM_ID or it.get("campo") != "max":
            continue
        q = por.get(it["quarto"])
        if q is not None and float(q["max"]) != float(it["valor"]):
            feitas.append(f"{it['quarto']}: max {q['max']} → {int(it['valor'])}")
            q["max"] = int(it["valor"])
    return feitas


# ---------------------------------------------------------------- payloads das ferramentas do PriceLabs
def juntar_planos(planos: list[dict]) -> dict:
    """Um plano só a partir do `plano`, das `orfas` e do `portao` da mesma rotina (revisão de 16/11: validados em separado,
    o duplicado passava e um sobrescrevia o outro na API): o primeiro manda no preço; os seguintes, na estadia e na máscara
    (juntar_itens). Todos do mesmo dia."""
    dias = {p.get("hoje") for p in planos if p.get("hoje")}
    if len(dias) > 1:
        raise ValueError(f"planos de dias diferentes: {sorted(dias)}")
    itens: list[dict] = []
    for p in planos:
        itens = juntar_itens(itens, [dict(i) for i in p.get("itens") or []])
    # revisão de 16/11: o portão não abre na rotina em que o preço do fim de semana da pousada muda (a Booking conferida
    # mostrava o preço velho; o envio novo ainda não saiu)
    abre = {POUSADA_DE[i["quarto"]] for i in itens if i.get("portao_0103") and i.get("quarto") in POUSADA_DE}
    muda = pousadas_com_preco_fds(itens)
    conflito = sorted(abre & set(muda))
    if conflito:
        raise ValueError("o portão do 01→03 de " + ", ".join(conflito) + " não abre na rotina em que o preço do fim de "
                         "semana muda (" + "; ".join(", ".join(muda[p]) for p in conflito) + "): rode o `portao` com "
                         "--plano <plano de preço> e ele espera o próximo envio")
    out = {"hoje": next(iter(dias)) if dias else None, "itens": itens}
    for p in planos:
        for k in ("estado_novo", "estado_flags"):
            if k in p and k not in out:
                out[k] = p[k]
    return out


def conferencia_da_gravacao(cfg: dict, itens: list[dict]) -> dict:
    """{quarto: [{date, min_price, max_price, min_stay?, check_in?...}]}: o que a resposta da gravação (persisted
    overrides) tem de mostrar em cada data. O preço vem do item (percent_base, como no payload); a estadia e a máscara,
    da regra lida antes (`conferir`; o item de preço não as leva: a API mescla). Revisão de 16/11: sem registro direto de
    que check_in/check_out sobrevivem a uma gravação só de preço; se algo sumir, regravar na hora só a estadia e a
    máscara, com manter_reason, e anotar o aprendizado."""
    from validar_plano import piso_pct, teto_pct
    out: dict = {}
    for it in itens or []:
        if it.get("tipo") != "data":
            continue
        q = cfg["por_curto"][it["quarto"]]
        x = {"date": it["data"]}
        if it.get("min_price") is not None:
            x["min_price"] = str(piso_pct(q, float(it["min_price"])))
        if it.get("max_price") is not None:
            x["max_price"] = str(max(teto_pct(q, float(it["max_price"])), int(x.get("min_price") or -999)))
        x.update({k: (str(v) if k == "min_stay" else v) for k, v in (it.get("conferir") or {}).items()})
        for k in ("min_stay", "check_in", "check_out"):
            if it.get(k) is not None:
                x[k] = str(it[k])
        out.setdefault(it["quarto"], []).append(x)
    return out


def payloads(cfg: dict, plano: dict, hoje: date, ordens: dict | None = None, estados: dict | None = None) -> dict:
    """Valida o plano (validar_plano.Validador) e devolve os pedidos EXATOS das ferramentas do PriceLabs: um
    update_listing_date_overrides por quarto ({listing_id, pms, overrides}) e um update_listing_data por quarto
    ({"listings": [{"id", "pms", "max"}]}: o formato da ferramenta, não o do montar_payload), e a `conferencia` da
    resposta de cada gravação. Com erro, levanta."""
    from validar_plano import Validador, montar_payload
    v = Validador(cfg, hoje, {}, ordens=ordens, estados=estados,
                  estado_plano={k: plano.get(k) for k in ("estado_novo", "estado_flags")})
    v.validar(plano.get("itens", []))
    if v.erros:
        raise ValueError("plano com erro no validador:\n" + "\n".join(v.erros))
    pl = montar_payload(cfg, plano["itens"])
    por_id = {q["id"]: q["curto"] for q in cfg["quartos"]}
    out = {"datas": {}, "anuncio": {}, "apagar": {}, "avisos": v.avisos,
           "conferencia": conferencia_da_gravacao(cfg, plano["itens"])}
    for c in pl["update_listing_date_overrides"]:
        out["datas"][por_id[c["listing_id"]]] = {"listing_id": c["listing_id"], "pms": c["pms"],
                                                 "overrides": sorted(c["overrides"], key=lambda o: o["date"])}
    for c in pl["delete_listing_date_overrides"]:
        out["apagar"][por_id[c["listing_id"]]] = c
    for c in pl["update_listing_data"]:
        item = {"id": c["listing_id"], "pms": c["pms"]}
        item.update({k: int(round(float(x))) for k, x in c.items() if k in ("min", "base", "max")})
        out["anuncio"][por_id[c["listing_id"]]] = {"listings": [item]}
    return out


# ---------------------------------------------------------------- vigia do máximo fora do Réveillon
def calendario_para_vigia(coleta: dict | None, precos: dict | None) -> dict:
    """{curto: {data: {price, uncustomized_price}}} da coleta (60 dias) e/ou do get_listing_prices de cada quarto
    (`precos` {curto: json}, os 12 meses; vence a coleta na mesma noite)."""
    from comum import resultado_workflow
    from calendario_12m import _lista_com_datas
    out: dict = {}
    if coleta:
        for q, sala in ((resultado_workflow(coleta).get("rooms")) or {}).items():
            for dd in sala.get("days") or []:
                if dd.get("date"):
                    out.setdefault(q, {})[str(dd["date"])[:10]] = dd
    for q, j in (precos or {}).items():
        lst, _ = _lista_com_datas(j)
        for dd in lst or []:
            if dd.get("date"):
                out.setdefault(q, {})[str(dd["date"])[:10]] = dd
    return out


def vigia_maximo(cfg: dict, coleta: dict | None, hoje: date, precos: dict | None = None, regras: dict | None = None,
                 antes: bool = False, ordens: dict | None = None) -> dict:
    """O máximo do anúncio sobe a R$ 6.000 só por causa de 30/12 a 02/01 (o PriceLabs não tem máximo por data). Nas outras
    noites dos quartos da lista, devolve {"itens", "bloqueios", "riscos"}:
      - itens: teto de R$ 5.000 por data (sem exceção do dono: só o teto) na noite com o preço do calendário acima dele;
        com `antes` (antes de gravar o máximo novo), também na noite encostada no teto (≥ R$ 4.990) ou com o preço do
        algoritmo (uncustomized_price) acima de R$ 5.000 e sem teto por data na regra;
      - bloqueios: noite futura com teto 'percent_max' na regra (sobe junto com o máximo do anúncio: 19 a 22/11 tinham
        +10% do dono) ou noite congelada por mudança manual do dono que precisaria do teto (nenhum script mexe nela);
      - riscos: o que foi olhado e ficou de fora, para o relatório.
    `coleta` = saída do coleta-pricelabs (60 dias); `precos` = get_listing_prices por quarto (12 meses, revisão de 16/11:
    a coleta não cobre Carnaval nem julho); `regras` = get_listing_date_overrides por quarto ({curto: {data: regra}})."""
    from ordens import congelada
    lim = (cfg.get("parametros") or {}).get("maximo_anuncio_por_noite") or {}
    teto = lim.get("teto_fora")
    res = {"itens": [], "bloqueios": [], "riscos": []}
    if teto is None:
        return res
    teto = float(teto)
    ordens = ordens if ordens is not None else carregar_ordens()
    cal = calendario_para_vigia(coleta, precos)
    regras = regras or {}
    for q in lim.get("quartos") or []:
        datas = set((cal.get(q) or {})) | set(regras.get(q) or {})
        for n in sorted(datas):
            if n in (lim.get("noites") or []) or data(n) < hoje:
                continue
            dd = (cal.get(q) or {}).get(n) or {}
            reg = (regras.get(q) or {}).get(n) or {}
            if reg.get("max_price") not in (None, "") and (reg.get("max_price_type") or "percent_base") == "percent_max":
                res["bloqueios"].append(f"{q} {n}: teto 'percent_max' {reg['max_price']}% na regra (sobe junto com o "
                                        "máximo do anúncio)")
                continue
            try:
                preco = float(dd.get("price")) if dd.get("price") not in (None, "") else None
                unc = float(dd.get("uncustomized_price")) if dd.get("uncustomized_price") not in (None, "") else None
            except (TypeError, ValueError):
                preco = unc = None
            tem_teto = pct_vigente(reg, "max_price") is not None and reais_de(cfg, q, pct_vigente(reg, "max_price")) <= teto + 0.5
            acima = preco is not None and preco > teto + 0.5
            perto = antes and not tem_teto and ((preco is not None and preco >= teto - 10) or (unc is not None and unc > teto + 0.5))
            if not (acima or perto):
                continue
            cong = congelada(ordens, q, data(n))
            if cong:
                res["bloqueios"].append(f"{q} {n}: noite congelada pelo dono ({cong['id']}): calendário {br(preco)}, "
                                        f"algoritmo {br(unc)}; o teto dela segue o máximo do anúncio")
                continue
            res["itens"].append({"tipo": "data", "quarto": q, "data": n, "max_price": teto, "preco_antes": preco,
                                 "motivo": (f"Teto de {br(teto)} fora do Réveillon (dono 16/11: máximo do anúncio de "
                                            f"R$ 6.000 só por causa de 30/12 a 02/01); calendário {br(preco)}, algoritmo "
                                            f"{br(unc)}")})
            res["riscos"].append(f"{q} {n}: calendário {br(preco)}, algoritmo {br(unc)}"
                                 + (" (sem teto por data)" if not tem_teto else ""))
    return res


# ---------------------------------------------------------------- estado inicial
def estado_inicial(cfg: dict, ordem: dict, inv: dict, reservas_lidas_em: str, reservas: list[dict] | None = None) -> dict:
    """Estado da linha de base (Beds24 de 16/11). Com `reservas` (a leitura da linha de base), guarda a cobertura por
    reserva (hash do id, quarto e blocos; sem dado de hóspede) para a contagem por diferença."""
    noites_base = (VESPERA,) + NOITES + (DOMINGO,)
    est = {
        "_doc": ("Estado da ordem dono-2026-11-16-reveillon-250k (scripts/reveillon_250k.py). Linha de base = Beds24 "
                 "lido em 16/11 (sem dados de hóspede). fatores: fator público medido por pousada e bloco (o novo só "
                 "entra fora de ±5%); degraus: descida de 5% por pousada e bloco ({valor, em}); escada: pacotes que a "
                 "escada usa (parada abaixo do alarme); gravado_fds: instante UTC da gravação dos preços do fim de "
                 "semana no PriceLabs (condição 1 do portão); portao: 01→03 aberto por pousada; degraus.marcos: "
                 "marcos da descida já avaliados (uma vez cada)."),
        "ordem_id": ORDEM_ID,
        "linha_base": {
            "reservas_lidas_em": reservas_lidas_em,
            "inventario_lido_em": inv.get("_lido_em"),
            "livres": {q: {n: livres(inv, q, n) for n in noites_base} for q in QUARTOS},
            "pacotes_livres": pacotes_livres(cfg, inv),
        },
        "fatores": {p: {b: {"valor": float(ordem["fator_publico"][p][b]), "medido_em": ordem["fator_publico"]["medido_em"]}
                        for b in BLOCOS} for p in POUSADAS},
        "promocao_villa": {"ativa": True, "retirada_em": None},
        "concorrente_max_fds": {"publico": float(ordem["tetos"]["fds"]["concorrente_hidro_max_publico"]),
                                "medido_em": ordem["tetos"]["fds"]["medido_em"]},
        "degraus": {p: {b: {"valor": 0, "em": None, "marcos": []} for b in BLOCOS} for p in POUSADAS},
        "escada": {p: {b: 0 for b in BLOCOS} for p in POUSADAS},
        "pacotes": {"Recanto": {b: 0 for b in BLOCOS}, "Villa": {b: 0 for b in BLOCOS}, "total": {b: 0 for b in BLOCOS},
                    "contados_em": None},
        "gravado_fds": {"Recanto": None, "Villa": None},
        "portao": {p: {"aberto": False, "em": None} for p in POUSADAS},
        "bonus_marco": {"ativo": False, "em": None},
        "atualizado_em": None,
    }
    if reservas is not None:
        est["linha_base"]["cobertura_reservas"] = cobertura_reservas(cfg, reservas)
    return est


# ---------------------------------------------------------------- CLI
def _pares(lst: list[str] | None) -> dict:
    out = {}
    for x in lst or []:
        k, v = x.split("=", 1)
        out[k] = v
    return out


def _aplicar_flags(estado: dict, ordem: dict, a, hoje: date) -> list[str]:
    msgs = []
    if getattr(a, "promocao_villa", None):
        val, _, quando = a.promocao_villa.partition("@")
        ativa = val == "ativa"
        pr = estado.setdefault("promocao_villa", {})
        if pr.get("ativa", True) != ativa:
            pr["ativa"] = ativa
            pr["retirada_em"] = None if ativa else (quando or hoje.isoformat())
            msgs.append(f"promoção da Villa: {'ativa' if ativa else 'retirada em ' + pr['retirada_em']}")
    tol = float(ordem["fator_publico"].get("tolerancia", 0.05))
    for x in getattr(a, "fator", None) or []:
        chave, v = x.split("=", 1)
        p, b = chave.split(":", 1)
        atual = estado["fatores"][p][b]
        forcar = getattr(a, "forcar_fator", False)
        pr = estado.get("promocao_villa") or {}
        if p == "Villa" and b == "fds" and not pr.get("ativa", True) and (atual.get("medido_em") or "") < (pr.get("retirada_em") or ""):
            forcar = True  # primeira medida depois de a promoção sair
        novo, msg = adotar_fator(atual, float(v), hoje, tol, forcar)
        estado["fatores"][p][b] = novo
        if msg:
            msgs.append(f"{p} {NOME_BLOCO[b]}: {msg}")
    if getattr(a, "concorrente_max", None):
        estado["concorrente_max_fds"] = {"publico": float(a.concorrente_max), "medido_em": hoje.isoformat()}
        msgs.append(f"concorrente com hidro mais caro do fim de semana: {br(a.concorrente_max)} público")
    return msgs


def _flags_mudadas(antes: dict, depois: dict) -> list[list[str]]:
    """Caminhos do estado que as flags da rodada mudaram (o `registrar` grava só esses e o que o plano decide)."""
    out = []
    for p in POUSADAS:
        for b in BLOCOS:
            if ((antes.get("fatores") or {}).get(p) or {}).get(b) != ((depois.get("fatores") or {}).get(p) or {}).get(b):
                out.append(["fatores", p, b])
    for k in ("promocao_villa", "concorrente_max_fds"):
        if antes.get(k) != depois.get(k):
            out.append([k])
    return out


def _salvar_payloads(r: dict, pasta: Path, sobrescrever: bool) -> list[str]:
    arqs = [(pasta / f"payload_apagar_{q}.json", c) for q, c in r["apagar"].items()]
    arqs += [(pasta / f"payload_anuncio_{q}.json", c) for q, c in r["anuncio"].items()]
    arqs += [(pasta / f"payload_{q}.json", c) for q, c in r["datas"].items()]
    existem = [str(a) for a, _ in arqs if a.exists()]
    if existem and not sobrescrever:
        raise FileExistsError("já existem na pasta (outro plano da mesma rotina?): " + ", ".join(existem)
                              + ". Junte os planos (`juntar` ou vários --plano) ou use --sobrescrever")
    for a, c in arqs:
        salvar_json(a, c)
    if r.get("conferencia"):
        # não é pedido: o que conferir na resposta (persisted overrides) de cada payload_<quarto>.json
        salvar_json(pasta / "conferir_gravacao.json",
                    {"_doc": "Conferir na resposta de cada update_listing_date_overrides (persisted overrides): o "
                             "percentual e, vindos da regra lida antes, a estadia e a máscara (o item de preço não as "
                             "leva; a API mescla). Se a estadia ou a máscara sumir, regrave na hora só elas, com "
                             "manter_reason, e anote o aprendizado.", "quartos": r["conferencia"]})
    return [str(a) for a, _ in arqs]


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    comuns = argparse.ArgumentParser(add_help=False)
    comuns.add_argument("--hoje", required=True)
    comuns.add_argument("--replay", action="store_true", help="aceita --hoje diferente do relógio (testes, reprocesso)")
    comuns.add_argument("--inventario", required=True, help="beds24.py inventario --json (noites 29/12 a 03/01)")
    comuns.add_argument("--regras", nargs="*", default=[], help="Q7=<get_listing_date_overrides.json> ...")
    comuns.add_argument("--estado", default=str(ARQ_ESTADO))
    comuns.add_argument("--ordens", default=None)
    comuns.add_argument("--saida")
    comuns.add_argument("--promocao-villa", help="ativa | retirada[@AAAA-MM-DD]")
    pl = sub.add_parser("plano", parents=[comuns])
    pl.add_argument("--reservas", help="beds24.py reservas --json (28/12 a 04/01)")
    pl.add_argument("--fator", nargs="*", default=[], help="Recanto:fds=0.5832 ... (medido na Booking hoje)")
    pl.add_argument("--forcar-fator", action="store_true")
    pl.add_argument("--concorrente-max", type=float, help="público do concorrente com hidro mais caro do fim de semana")
    pl.add_argument("--receita", type=float, help="receita das 4 noites (beds24.py livro), para a linha de alarme")
    pl.add_argument("--listings", help="min/base/max atuais do anúncio ({curto: {max}}): gera o item do máximo 6.000 "
                                       "quando a escada precisar (a partir de 23/11)")
    pl.add_argument("--md")
    pl.add_argument("--gravar-estado", action="store_true",
                    help="funde no estado em disco o que o plano decide (sem gravação no PriceLabs: prefira `registrar`)")
    po = sub.add_parser("portao", parents=[comuns])
    po.add_argument("--plano", nargs="+", help="o plano de preço da rotina (--saida do `plano`): com item de preço no "
                                               "fim de semana de uma pousada, o portão dela espera o próximo envio")
    po.add_argument("--envio", nargs="*", default=[],
                    help="last_date_pushed UTC: por quarto (Q7=... Q2=...; vale o mais antigo da pousada) ou por pousada")
    po.add_argument("--publico", nargs="*", default=[], help="Recanto=<público por noite do fim de semana> ...")
    po.add_argument("--lote-beds24")
    po.add_argument("--aplicar-config", action="store_true",
                    help="(saiu em 16/11: use `aplicar-config --portao <saida>` depois de gravar)")
    po.add_argument("--mostrar", action="store_true",
                    help="só imprime as ações (simulação) com o portão fechado; nada vai para --saida nem --lote-beds24")
    orf = sub.add_parser("orfas", parents=[comuns])
    orf.add_argument("--lote-beds24")
    orf.add_argument("--aplicar-config", action="store_true",
                     help="aplica o patch já (prefira `aplicar-config --orfas <saida>` depois de gravar)")
    apc = sub.add_parser("aplicar-config", help="depois de gravar PriceLabs e Beds24: aplica em quartos.json a decisão "
                                                "gravada do `portao` e do `orfas` desta rotina (não reavalia)")
    apc.add_argument("--hoje", required=True)
    apc.add_argument("--portao", help="o --saida do `portao` desta rotina")
    apc.add_argument("--orfas", help="o --saida do `orfas` desta rotina")
    apc.add_argument("--estado", default=str(ARQ_ESTADO))
    rg = sub.add_parser("registrar", help="depois de gravar e conferir no PriceLabs: funde o plano no estado")
    rg.add_argument("--plano", required=True, help="o --saida do plano (ou o `juntar`), com o estado novo")
    rg.add_argument("--em", help="instante UTC da gravação (padrão: agora)")
    rg.add_argument("--estado", default=str(ARQ_ESTADO))
    jt = sub.add_parser("juntar", help="junta plano, orfas e portao da rotina num arquivo só (valide e gere dele)")
    jt.add_argument("--plano", nargs="+", required=True, help="o plano de preço primeiro; depois orfas e portao")
    jt.add_argument("--saida", required=True)
    pay = sub.add_parser("payloads", help="valida e grava um arquivo por chamada do PriceLabs")
    pay.add_argument("--plano", nargs="+", required=True, help="um arquivo (o do `juntar`) ou vários (juntados aqui)")
    pay.add_argument("--hoje", required=True)
    pay.add_argument("--pasta", required=True)
    pay.add_argument("--sobrescrever", action="store_true")
    vg = sub.add_parser("vigia-maximo", help="noites fora de 30/12-02/01 acima de R$ 5.000 (ou perto, com --antes)")
    vg.add_argument("--coleta", help="saída do coleta-pricelabs (60 dias)")
    vg.add_argument("--precos", nargs="*", default=[], help="Q7=<get_listing_prices.json> ... (12 meses)")
    vg.add_argument("--regras", nargs="*", default=[], help="Q7=<get_listing_date_overrides.json> ... (12 meses)")
    vg.add_argument("--antes", action="store_true", help="antes de gravar o máximo novo: inclui noite perto do teto")
    vg.add_argument("--hoje", required=True)
    vg.add_argument("--saida")
    sub.add_parser("estado")
    a = ap.parse_args(argv)
    if a.cmd == "vigia-maximo":
        cfg = carregar_quartos()
        if not a.coleta and not a.precos:
            print("ERRO: passe --coleta e/ou --precos")
            return 1
        precos = {k: ler_json(v) for k, v in _pares(a.precos).items()}
        r = vigia_maximo(cfg, ler_json(a.coleta) if a.coleta else None, data(a.hoje), precos, ler_regras(a.regras),
                         a.antes)
        for x in r["riscos"]:
            print("teto R$ 5.000:", x)
        for x in r["bloqueios"]:
            print("BLOQUEIO:", x)
        print(f"{len(r['itens'])} noite(s) com teto de R$ 5.000 a gravar; {len(r['bloqueios'])} bloqueio(s)"
              + (" (não grave o máximo novo enquanto houver bloqueio)" if r["bloqueios"] and a.antes else ""))
        if a.saida:
            salvar_json(a.saida, {"hoje": a.hoje, "itens": r["itens"], "bloqueios": r["bloqueios"]})
        return 2 if r["bloqueios"] else (1 if r["itens"] else 0)
    if a.cmd == "juntar":
        try:
            pl = juntar_planos([ler_json(x) for x in a.plano])
        except ValueError as e:
            print(f"ERRO: {e}")
            return 1
        salvar_json(a.saida, pl)
        print(f"{len(pl['itens'])} itens em {a.saida} (valide e gere os pedidos deste arquivo)")
        return 0
    if a.cmd == "payloads":
        cfg = carregar_quartos()
        try:
            pl = juntar_planos([ler_json(x) for x in a.plano])
            r = payloads(cfg, pl, data(a.hoje))
            Path(a.pasta).mkdir(parents=True, exist_ok=True)
            arqs = _salvar_payloads(r, Path(a.pasta), a.sobrescrever)
        except (ValueError, FileExistsError) as e:
            print(f"ERRO: {e}\nNÃO GRAVE.")
            return 1
        for x in arqs:
            nome = Path(x).name
            tipo = ("delete_listing_date_overrides — ANTES" if nome.startswith("payload_apagar_") else
                    "update_listing_data — antes das datas" if nome.startswith("payload_anuncio_") else
                    "update_listing_date_overrides")
            print(f"{x}: {tipo}")
        if r.get("conferencia"):
            print(f"{Path(a.pasta) / 'conferir_gravacao.json'}: o que conferir na resposta de cada gravação (não é pedido)")
        for m in r["avisos"]:
            print(m)
        return 0
    if a.cmd == "aplicar-config":
        hoje = data(a.hoje)
        if not a.portao and not a.orfas:
            print("ERRO: passe --portao e/ou --orfas (as saídas desta rotina)")
            return 1
        bruto = json.loads(Path(ARQ_QUARTOS).read_text(encoding="utf-8"))
        disco = carregar_estado(a.estado)
        feitas, recusas = aplicar_config(bruto, disco, hoje, ler_json(a.portao) if a.portao else None,
                                         ler_json(a.orfas) if a.orfas else None)
        for m in recusas:
            print("recusado:", m)
        if not feitas:
            print("nada a aplicar na config")
            return 1 if recusas else 0
        salvar_json(ARQ_QUARTOS, bruto)
        if any(f.startswith("portão") for f in feitas):
            disco["atualizado_em"] = hoje.isoformat()
            salvar_json(a.estado, disco)
        print(f"config gravada em {ARQ_QUARTOS}: {'; '.join(feitas)}")
        return 1 if recusas else 0

    if a.cmd == "estado":
        print(json.dumps(carregar_estado(), ensure_ascii=False, indent=1))
        return 0
    if a.cmd == "registrar":
        pl = ler_json(a.plano)
        if not isinstance(pl.get("estado_novo"), dict):
            print("ERRO: --plano sem 'estado_novo' (use o --saida do `plano` ou do `juntar`)")
            return 1
        em = ler_instante(a.em) if a.em else datetime.now(timezone.utc)
        novo = registrar(pl, em, carregar_estado(a.estado))
        salvar_json(a.estado, novo)
        print(f"estado gravado em {a.estado} (gravado_fds: {novo.get('gravado_fds')}; portão: {novo.get('portao')})")
        an = [i for i in pl.get("itens") or [] if i.get("tipo") == "anuncio"]
        if an:
            bruto = json.loads(Path(ARQ_QUARTOS).read_text(encoding="utf-8"))
            feitas = aplicar_anuncio(bruto, an)
            if feitas:
                salvar_json(ARQ_QUARTOS, bruto)
                print(f"quartos.json (máximo do anúncio gravado e conferido): {'; '.join(feitas)}")
        return 0
    cfg = carregar_quartos()
    hoje = data(a.hoje)
    if hoje != hoje_brt(cfg) and not a.replay:
        print(f"ERRO: --hoje {hoje} não é hoje em America/Sao_Paulo ({hoje_brt(cfg)}); use a data do relógio")
        return 1
    ordem = ordem_do_dono(carregar_ordens(a.ordens) if a.ordens else None)
    estado = carregar_estado(a.estado)
    estado_disco = copy.deepcopy(estado)
    inv = ler_json(a.inventario)
    regras = ler_regras(a.regras)
    msgs = _aplicar_flags(estado, ordem, a, hoje)
    flags = _flags_mudadas(estado_disco, estado)
    for m in msgs:
        print("estado:", m)

    if a.cmd == "plano":
        if set(regras) != set(QUARTOS):
            print("ERRO: passe --regras dos 7 quartos (a regra vigente dá o preço de antes e a estadia)")
            return 1
        reservas = None
        if a.reservas:
            res_r = ler_json(a.reservas)
            reservas = res_r.get("data") if isinstance(res_r, dict) else res_r
            if isinstance(res_r, dict) and res_r.get("de") and res_r.get("ate") and \
                    (res_r["de"] > BLOCOS["reveillon"][0] or res_r["ate"] < BLOCOS["fds"][1]):
                print(f"ERRO: --reservas de {res_r['de']} a {res_r['ate']} não cobre 30/12 a 02/01 (leia 28/12 a 04/01)")
                return 1
        else:
            print("aviso: sem --reservas, nenhum pacote novo é contado (escada e linha da descida em 0)")
        listings = {}
        if a.listings:
            lj = ler_json(a.listings)
            listings = {q: v for q, v in lj.items() if q in QUARTOS}
        try:
            res = montar_plano(cfg, ordem, estado, inv, regras, hoje, reservas, a.receita, listings)
        except ValueError as e:
            print(f"ERRO: {e}")
            return 1
        md = relatorio_md(cfg, res)
        print(md)
        if a.md:
            Path(a.md).write_text(md, encoding="utf-8")
        res["estado_novo"]["atualizado_em"] = hoje.isoformat()
        saida = {"hoje": res["hoje"], "itens": res["itens"], "diagnostico": res["linhas"], "avisos": res["avisos"],
                 "contexto": res["contexto"], "estado_novo": res["estado_novo"], "estado_flags": flags}
        if a.saida:
            salvar_json(a.saida, saida)
            print(f"{len(res['itens'])} itens em {a.saida} (depois de gravar e conferir: `registrar --plano {a.saida}`)")
        if a.gravar_estado:
            novo = fundir_estado(carregar_estado(a.estado), saida)
            novo["atualizado_em"] = hoje.isoformat()
            salvar_json(a.estado, novo)
            print(f"estado gravado em {a.estado}")
        return 0

    if a.cmd == "portao":
        if a.aplicar_config:
            print("ERRO: a config do portão se aplica depois de gravar o PriceLabs e o Beds24, pela decisão gravada: "
                  "`aplicar-config --hoje <hoje> --portao <saida do portao>` (revisão de 16/11: reavaliar depois do "
                  "`registrar` deixava a config sem o patch)")
            return 1
        if not a.plano:
            print("ERRO: passe --plano <plano de preço da rotina> (o portão não abre na rotina em que o preço do fim de "
                  "semana da pousada muda)")
            return 1
        itens_plano = [i for x in a.plano for i in (ler_json(x).get("itens") or [])]
        saida, linhas = decidir_portao(cfg, ordem, estado, inv, regras, _pares(a.envio),
                                       {k: float(v) for k, v in _pares(a.publico).items()}, hoje, itens_plano, a.mostrar)
        for x in linhas:
            print(x)
        if a.saida:
            salvar_json(a.saida, saida)
        if a.lote_beds24:
            # sempre escreve (lista vazia com o portão fechado): um lote velho na pasta não fica para trás
            Path(a.lote_beds24).write_text(json.dumps(saida["lote_beds24"], ensure_ascii=False, indent=1) + "\n",
                                           encoding="utf-8")
            if not saida["lote_beds24"]:
                print(f"{a.lote_beds24}: lote vazio (nenhuma pousada passou no portão)")
        if saida["patch_quartos"]:
            print("patch de quartos.json (regra_h_beds24; `aplicar-config --portao` depois de gravar):",
                  json.dumps(saida["patch_quartos"], ensure_ascii=False))
        return 0

    if a.cmd == "orfas":
        r = avaliar_orfas(cfg, ordem, estado, inv, regras, hoje)
        print("| Quarto | Livres 29/30/31/01/02/03 | Soltas 30/12 | 29→31 direta | Exceção 1 OTA | Soltas 02/01 | "
              "02→04 direta | Exceção 2 OTA |")
        print("|---|---|---|---|---|---|---|---|")
        for q, x in r["quartos"].items():
            L = x["livres"]
            print(f"| {q} | {'/'.join(str(L[n]) for n in (VESPERA, '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', DOMINGO))} | "
                  f"{x['soltas_30_12']} | {x['venda_direta_29_31']} | {x['excecao_1_ota']}"
                  f"{' (' + x['acao_1'] + ')' if x.get('acao_1') else ''} | {x['soltas_02_01']} | {x['venda_direta_02_04']} | "
                  f"{x['excecao_2_ota']}{' (' + x['acao_2'] + ')' if x.get('acao_2') else ''} |")
        for m in r["avisos"]:
            print("aviso:", m)
        for c in r["comandos_beds24"]:
            print("Beds24 (simulação):", c)
        if r["patch_quartos"]:
            print("patch de quartos.json (regra_h_beds24):", json.dumps(r["patch_quartos"], ensure_ascii=False))
        if a.saida:
            salvar_json(a.saida, {"hoje": hoje.isoformat(), "itens": r["itens_pricelabs"], "quartos": r["quartos"],
                                  "comandos_beds24": r["comandos_beds24"], "patch_quartos": r["patch_quartos"]})
        if a.lote_beds24:   # sempre escreve (lista vazia sem exceção a mexer): lote velho não fica na pasta
            Path(a.lote_beds24).write_text(json.dumps(r["lote_beds24"], ensure_ascii=False, indent=1) + "\n",
                                           encoding="utf-8")
        if a.aplicar_config and r["patch_quartos"]:
            bruto = json.loads(Path(ARQ_QUARTOS).read_text(encoding="utf-8"))
            salvar_json(ARQ_QUARTOS, aplicar_patch(bruto, r["patch_quartos"]))
            print(f"config gravada em {ARQ_QUARTOS}")
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())

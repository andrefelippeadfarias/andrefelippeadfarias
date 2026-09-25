"""Portões, candidatos, perguntas ao Jev, decisões e limpeza.

Funções puras: recebem o contexto já coletado e devolvem listas. Os efeitos
externos ficam em acoes.py.
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta

from . import metricas
from .jev import margem
from .tempo import dias_bloqueados, ler_instante, proxima_sincronizacao

CONTEXTO_JEV = (
    "Boutique spa hotel rooms in southern Brazil. Online travel agencies always apply 30-50% guest "
    "discounts on top of our price and we cannot change them. Goal: reach 70% occupancy for the next "
    "6 days without giving away revenue. Every signal below was computed by code."
)
MAX_IDADE_CALENDARIO_H = 36


@dataclass
class Contexto:
    cfg: dict
    agora: datetime
    calendarios: dict            # id -> metricas.Listing
    listings_api: dict           # id -> item de GET /listings
    reservas: list | None        # None = não foi possível ler
    mercado: dict                # id -> ocupação de mercado 7d (ou None)
    estado: dict
    substituicoes: dict = field(default_factory=dict)  # id -> {date: override}
    trava_receita: bool = False

    @property
    def hoje(self) -> date:
        return self.agora.date()

    @property
    def proxima_sync(self) -> datetime:
        return proxima_sincronizacao(self.agora, self.cfg["horarios_sincronizacao"])

    @property
    def na_janela(self) -> bool:
        return (self.proxima_sync - self.agora) <= timedelta(hours=self.cfg["janela_criacao_horas"])

    def item(self, listing_id: str) -> dict:
        return next(x for x in self.cfg["listings"] if x["id"] == listing_id)

    def minimo(self, listing_id: str):
        v = (self.listings_api.get(listing_id) or {}).get("min")
        return float(v) if isinstance(v, (int, float)) and not isinstance(v, bool) and v > 0 else None


@dataclass
class Bloco:
    listing: dict
    tipo: str                    # "weekday" ou "fri_sat"
    datas: list
    bandas: dict
    sombra: bool

    @property
    def chave(self) -> str:
        return f"{self.listing['codigo']}_{self.tipo}"


def tipo_bloco(d: date) -> str:
    return "fri_sat" if d.weekday() in (4, 5) else "weekday"


def dados_frescos(ctx: Contexto, listing_id: str) -> str:
    """Devolve '' se os dados da listing servem para decidir, senão o motivo."""
    cal = ctx.calendarios.get(listing_id)
    api = ctx.listings_api.get(listing_id)
    if cal is None or cal.erro:
        return f"calendário indisponível ({cal.erro if cal else 'ausente'})"
    if cal.atualizado_em is None or ctx.agora - cal.atualizado_em > timedelta(hours=MAX_IDADE_CALENDARIO_H):
        return "calendário desatualizado"
    if not api or api.get("push_enabled") is not True:
        return "sincronização desligada ou listing ausente"
    if ctx.minimo(listing_id) is None:
        return "preço mínimo desconhecido"
    return ""


def resumo(ctx: Contexto, listing_id: str) -> metricas.ResumoReservas:
    return metricas.resumir_reservas(ctx.reservas or [], listing_id, ctx.agora)


def candidatos(ctx: Contexto, incluir_sombra: bool) -> tuple[list[Bloco], list[dict]]:
    """Blocos que passaram por todos os portões, e os bloqueios com o motivo."""
    blocos, bloqueios = [], []
    cfg = ctx.cfg
    pct = cfg["desconto"]["percentual"]
    feriados = dias_bloqueados(ctx.hoje, ctx.hoje + timedelta(days=7), cfg.get("feriados_locais", []))
    for item in cfg["listings"]:
        lid, nome = item["id"], item["apelido"]
        if item["papel"] == "transbordo":
            continue
        real = item["papel"] == "sem_tabela" and item["desconto_permitido"]
        if not real and not incluir_sombra:
            continue

        def bloquear(regra):
            bloqueios.append({"alvo": nome, "regra": regra})

        motivo = dados_frescos(ctx, lid)
        if motivo:
            bloquear(motivo)
            continue
        if ctx.reservas is None:
            bloquear("reservas ilegíveis (a chave precisa ser a do dono da conta)")
            continue
        if not ctx.na_janela:
            bloquear("fora da janela de criação (próxima sincronização distante)")
            continue
        if ctx.trava_receita:
            bloquear("trava de receita ativa")
            continue
        cal = ctx.calendarios[lid]
        ocup = metricas.ocupacao(cal, ctx.hoje, 0, 6)[2]
        if ocup is None or ocup >= cfg["metas"]["0-6"]:
            bloquear("ocupação de 0 a 6 dias já na meta")
            continue
        merc = ctx.mercado.get(lid)
        if merc is None:
            bloquear("ocupação do mercado desconhecida")
            continue
        if merc >= cfg["mercado_visibilidade"]:
            bloquear("mercado forte: problema de visibilidade, não de preço")
            continue
        res = resumo(ctx, lid)
        if res.novas_48h > 0 and res.cancel_proximos_3d_48h == 0:
            bloquear("houve reserva nova nas últimas 48 h")
            continue
        minimo = ctx.minimo(lid)
        existentes = ctx.substituicoes.get(lid)
        if existentes is None:
            bloquear("substituições da listing não lidas")
            continue
        inicio = max(ctx.hoje, ctx.proxima_sync.date())
        por_tipo = {}
        for i in range((ctx.hoje + timedelta(days=6) - inicio).days + 1):
            d = inicio + timedelta(days=i)
            dia = cal.dias.get(d)
            chave = f"{lid}|{d.isoformat()}"
            if dia is None or not dia.livre or d in feriados or d.isoformat() in existentes:
                continue
            if chave in ctx.estado["dsos"] or _descontada_recente(ctx, chave):
                continue
            if not dia.preco or dia.preco * (1 + pct / 100) < minimo:
                continue
            por_tipo.setdefault(tipo_bloco(d), []).append(d)
        if not por_tipo:
            bloquear("nenhuma data elegível (feriado, lotada, com substituição, já descontada ou no piso)")
            continue
        for tipo, datas in sorted(por_tipo.items()):
            mediana = metricas.mediana_preco(cal, fim_de_semana=(tipo == "fri_sat"))
            bandas = _bandas(item, tipo, datas, cal, ocup, merc, res, mediana, minimo, pct, cfg["metas"]["0-6"])
            blocos.append(Bloco(item, tipo, sorted(datas), bandas, sombra=not real))
    return blocos, bloqueios


def _descontada_recente(ctx: Contexto, chave: str) -> bool:
    quando = ler_instante(ctx.estado["descontadas"].get(chave))
    return quando is not None and ctx.agora - quando < timedelta(days=ctx.cfg["desconto"]["sem_repetir_dias"])


def _bandas(item, tipo, datas, cal, ocup, merc, res, mediana, minimo, pct, meta) -> dict:
    precos = [cal.dias[d].preco for d in datas]
    relacao = (sum(precos) / len(precos)) / mediana if mediana else 1.0
    folga = min(p * (1 + pct / 100) for p in precos) / minimo
    n = len(datas)
    return {
        "block": tipo,
        "room_size": "single_unit" if item["unidades"] == 1 else ("two_units" if item["unidades"] == 2 else "multi_unit"),
        "occupancy_0_6d": "far_below_target" if ocup < meta / 2 else "below_target",
        "empty_dates_in_block": "one" if n == 1 else ("two_or_three" if n <= 3 else "four_or_more"),
        "new_bookings_last_48h": "some" if res.novas_48h else "none",
        "recent_cancellation_next_3d": "yes" if res.cancel_proximos_3d_48h else "no",
        "price_vs_room_median": "low" if relacao < 0.9 else ("high" if relacao > 1.1 else "typical"),
        "headroom_above_floor": "tight" if folga < 1.15 else ("moderate" if folga < 1.4 else "wide"),
        "market_next_7d": "weak" if merc < 30 else "moderate",
    }


def montar_pedido(blocos: list[Bloco], modelo: str, percentual: int = -10) -> dict:
    rooms, perguntas = {}, {}
    corte = f"{abs(int(percentual))}%"
    for b in blocos:
        k = b.chave
        rooms[k] = dict(b.bandas)
        perguntas[f"d_{k}"] = {
            "type": "choice",
            "instructions": (f"Look only at `rooms.{k}` and decide for its empty dates: should we apply a "
                             f"temporary {corte} price cut to raise occupancy in the next 6 days?"),
            "criteria": {
                "hold": "Keep the current price: signals are adequate, the price is already low, the headroom "
                        "above the floor is tight, or a cut would mostly give away revenue.",
                "discount": f"Apply the temporary {corte} cut: occupancy is below target, no recent bookings or a "
                            "recent cancellation opened space, the market is not strong and the price is not low.",
                "visibility_issue": "Do not cut the price: the gap looks like a visibility or distribution "
                                    "problem rather than a price problem.",
            },
        }
        perguntas[f"v_{k}"] = {
            "type": "noul",
            "instructions": (f"For `rooms.{k}`: would a {corte} price cut on these dates most likely reduce total "
                             "revenue or teach guests to wait for last-minute deals?"),
            "criteria": {
                "true": "Yes: the cut likely loses revenue or trains guests to wait (price already low, "
                        "dates likely to sell anyway, or little room above the floor).",
                "false": "No: the dates are likely to stay empty at the current price and the cut is a "
                         "reasonable way to fill them.",
            },
        }
    return {"state": {"context": CONTEXTO_JEV, "rooms": rooms}, "model": modelo, "questions": perguntas}


def impressao(pedido: dict) -> str:
    return hashlib.sha256(json.dumps(pedido, sort_keys=True).encode("utf-8")).hexdigest()


@dataclass
class Decisao:
    bloco: Bloco
    escolha: str
    confianca: float
    probabilidade: float
    margem: float
    veto: float
    agir: bool
    motivo: str


def decidir(blocos: list[Bloco], respostas: dict, cfg_jev: dict) -> list[Decisao]:
    decisoes = []
    for b in blocos:
        d, v = respostas[f"d_{b.chave}"], respostas[f"v_{b.chave}"]
        escolha, conf = d["choice"], float(d["confidence"])
        prob, mg, veto = float(d["probabilities"]["discount"]), margem(d), float(v["noul"])
        falhas = []
        if escolha != "discount":
            falhas.append(f"Jev escolheu {escolha}")
        else:
            if conf < cfg_jev["confianca_min"]:
                falhas.append(f"confiança {conf:.2f} abaixo de {cfg_jev['confianca_min']:.2f}")
            if prob < cfg_jev["probabilidade_min"]:
                falhas.append(f"probabilidade {prob:.2f} abaixo de {cfg_jev['probabilidade_min']:.2f}")
            if mg < cfg_jev["margem_min"]:
                falhas.append(f"margem {mg:.2f} abaixo de {cfg_jev['margem_min']:.2f}")
            if veto >= cfg_jev["veto_max"]:
                falhas.append(f"veto {veto:.2f} acima de {cfg_jev['veto_max']:.2f}")
        decisoes.append(Decisao(b, escolha, conf, prob, mg, veto, not falhas,
                                "; ".join(falhas) if falhas else "Jev recomendou desconto com folga"))
    return decisoes


def confirmar(decisoes: list[Decisao], estado: dict, request_id: str, agora: datetime, exigir: bool) -> None:
    """Exige a mesma recomendação numa chamada anterior (outra requisição) nas últimas 12 h."""
    propostas = estado.setdefault("propostas", {})
    for dec in decisoes:
        if dec.bloco.sombra:
            continue
        k = dec.bloco.chave
        if not dec.agir:
            propostas.pop(k, None)
            continue
        anterior = propostas.get(k)
        quando = ler_instante(anterior.get("ts")) if anterior else None
        confirmada = (anterior is not None and bool(request_id) and bool(anterior.get("request_id"))
                      and anterior.get("request_id") != request_id
                      and quando is not None and agora - quando <= timedelta(hours=12))
        if exigir and not confirmada:
            if anterior is None or anterior.get("request_id") != request_id:
                propostas[k] = {"ts": agora.isoformat(), "request_id": request_id}
            dec.agir = False
            dec.motivo = "aguardando confirmação na próxima execução da janela"
        else:
            propostas.pop(k, None)


def plano_criacao(decisoes: list[Decisao], ctx: Contexto, run_id: str, modo: str) -> tuple[list, list]:
    acoes, bloqueios = [], []
    desc = ctx.cfg["desconto"]
    hoje = ctx.hoje.isoformat()
    criadas_hoje = ctx.estado["contadores"]["criadas"] if ctx.estado["contadores"]["dia"] == hoje else 0
    ativas = len([x for x in ctx.estado["dsos"].values() if x["data"] >= hoje])  # divergentes também estão na conta
    vagas = min(desc["teto_execucao"], desc["teto_dia"] - criadas_hoje, desc["teto_ativas"] - ativas)
    pares = sorted(((d, dec) for dec in decisoes for d in dec.bloco.datas), key=lambda x: (x[0], x[1].bloco.chave))
    for d, dec in pares:  # datas mais próximas primeiro, em qualquer bloco
        base = {"listing": dec.bloco.listing["id"], "data": d.isoformat(), "origem": "jev"}
        if dec.bloco.sombra:
            if dec.escolha == "discount":
                acoes.append({**base, "tipo": "sombra", "motivo": "quarto fora da automação: " + dec.motivo,
                              "confianca": dec.confianca})
            continue
        if not dec.agir:
            continue
        payload = {"date": d.isoformat(), "price": str(desc["percentual"]), "price_type": "percent",
                   "reason": f"auto-jev {run_id}"}
        if modo != "ativo":
            acoes.append({**base, "tipo": "faria_criar", "payload": payload, "motivo": dec.motivo,
                          "confianca": dec.confianca})
            continue
        if vagas <= 0:
            bloqueios.append({"alvo": f"{dec.bloco.listing['apelido']} {d.isoformat()}",
                              "regra": "teto de descontos atingido"})
            continue
        vagas -= 1
        acoes.append({**base, "tipo": "criar", "payload": payload, "motivo": dec.motivo,
                      "confianca": dec.confianca})
    return acoes, bloqueios


def primeira_sync(dso: dict, horarios: list[str], fuso_ref) -> datetime | None:
    criado = ler_instante(dso.get("criado_em"))
    if criado is None:
        return None
    return proxima_sincronizacao(criado.astimezone(fuso_ref), horarios)


def plano_limpeza(ctx: Contexto) -> tuple[list, list, bool]:
    """Ações de remoção e alertas. Devolve (acoes, alertas, acionar_disjuntor)."""
    acoes, alertas, disjuntor = [], [], False
    vida = timedelta(hours=ctx.cfg["desconto"]["vida_horas"])
    for chave, dso in sorted(ctx.estado["dsos"].items()):
        lid, dstr = dso["listing"], dso["data"]
        d = date.fromisoformat(dstr)
        base = {"tipo": "apagar", "listing": lid, "data": dstr, "origem": "regra"}
        if dso.get("status") == "divergente":
            alertas.append(f"Substituição do programa alterada por outra pessoa em {dstr}: revisar na tela")
            continue
        if dso.get("status") != "ativa" or d < ctx.hoje:
            continue
        cal = ctx.calendarios.get(lid)
        dia = cal.dias.get(d) if cal and not cal.erro else None
        criado = ler_instante(dso.get("criado_em"))
        res = resumo(ctx, lid)
        if (dia is not None and dia.lotado) or (criado and metricas.vendeu_depois(res, d, criado)):
            acoes.append({**base, "motivo": "a data vendeu"})
            continue
        ps = primeira_sync(dso, ctx.cfg["horarios_sincronizacao"], ctx.agora.tzinfo)
        if ps is not None and ctx.proxima_sync >= ps + vida:
            acoes.append({**base, "motivo": "prazo de 48 h nos canais cumprido"})
            continue
        minimo = ctx.minimo(lid)
        if (ps is not None and cal and cal.atualizado_em and cal.atualizado_em > ps and dia is not None
                and dia.preco is not None and minimo is not None and dia.preco < minimo):
            acoes.append({**base, "motivo": "preço abaixo do mínimo depois da sincronização"})
            alertas.append(f"Preço abaixo do mínimo em {dstr}: desconto removido e automação em contenção")
            disjuntor = True
    return acoes, alertas, disjuntor


def alertas_gerais(ctx: Contexto) -> list[tuple[str, bool]]:
    """Alertas determinísticos: (texto, grave). Grave deixa a execução pelo menos amarela."""
    cfg, msgs = ctx.cfg, []
    for item in cfg["listings"]:
        lid, nome = item["id"], item["apelido"]
        cal = ctx.calendarios.get(lid)
        if cal is None or cal.erro:
            msgs.append((f"{nome}: calendário indisponível ({cal.erro if cal else 'ausente'})", True))
            continue
        api = ctx.listings_api.get(lid) or {}
        if api.get("push_enabled") is not True:
            msgs.append((f"{nome}: sincronização com o Beds24 desligada no PriceLabs", True))
        totais = {d.total for d in cal.dias.values() if d.total > 1}
        if item["unidades"] > 1 and totais and max(totais) != item["unidades"]:
            msgs.append((f"{nome}: o PriceLabs mostra {max(totais)} unidades, o config diz {item['unidades']}", True))
        enviado = ler_instante(api.get("last_date_pushed"))
        if enviado is not None and ctx.agora - enviado > timedelta(hours=30):
            msgs.append((f"{nome}: última sincronização com o Beds24 há mais de 30 h", True))
        if item["papel"] == "transbordo":
            continue
        f = metricas.ocupacao_faixas(cal, ctx.hoje)
        o06, o714, o1529 = f["0-6"], f["7-14"], f["15-29"]
        merc = ctx.mercado.get(lid)
        if o06 is not None and o06 < cfg["metas"]["0-6"] and merc is not None and merc >= cfg["mercado_visibilidade"]:
            msgs.append((f"{nome}: 0 a 6 dias em {o06:.0f}% com mercado em {merc:.0f}%. Checar visibilidade na Booking", False))
        if o06 is not None and o06 > 85:
            msgs.append((f"{nome}: 0 a 6 dias em {o06:.0f}%. Conferir se o máximo não trava o preço", False))
        if o714 is not None and o714 < cfg["metas"]["7-14"]:
            msgs.append((f"{nome}: 7 a 14 dias em {o714:.0f}% (meta {cfg['metas']['7-14']}%). Conferir estadia mínima e ofertas", False))
        if o1529 is not None and o1529 < cfg["metas"]["15-29"]:
            msgs.append((f"{nome}: 15 a 29 dias em {o1529:.0f}% (meta {cfg['metas']['15-29']}%)", False))
        minimo = ctx.minimo(lid)
        livres_no_piso = [d for d in cal.dias.values() if 0 <= (d.data - ctx.hoje).days <= 6 and d.livre
                          and d.preco and minimo and d.preco <= minimo * 1.001]
        if livres_no_piso:
            msgs.append((f"{nome}: {len(livres_no_piso)} data(s) livre(s) nos próximos 7 dias já no preço mínimo. "
                         "Checar canal e conteúdo", False))
    if ctx.trava_receita:
        msgs.append(("Trava de receita: ocupação sobe e RevPAR cai há 2 semanas. Novos descontos pausados; "
                     "reduza 3 pontos a linha de 0 a 6 dias da tabela de ocupação", True))
    return msgs

"""Cálculos determinísticos sobre o calendário e as reservas.

Nada aqui chama rede. As entradas são as respostas já recebidas da API.
"""

from __future__ import annotations

import re
import statistics
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta

from .tempo import ler_data, ler_instante

FAIXAS = {"0-6": (0, 6), "7-14": (7, 14), "15-29": (15, 29)}
_OCUP = re.compile(r"^\s*(\d+)\s*/\s*(\d+)\s*$")


def _numero(v):
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    return x


@dataclass
class Dia:
    data: date
    vendidas: int
    total: int
    preco: float | None      # preço calculado pelo PriceLabs para a data
    enviado: float | None    # último preço visto no PMS (-1 = indisponível)
    bloqueado: bool           # indisponível sem venda (bloqueio ou restrição)

    @property
    def livre(self) -> bool:
        return not self.bloqueado and self.vendidas < self.total

    @property
    def lotado(self) -> bool:
        return self.vendidas >= self.total


@dataclass
class Listing:
    id: str
    erro: str
    moeda: str
    atualizado_em: datetime | None
    dias: dict = field(default_factory=dict)  # date -> Dia


def ler_calendario(entrada: dict, unidades: int) -> Listing:
    erro = str(entrada.get("error_status") or entrada.get("error") or "")
    lst = Listing(entrada.get("id", ""), erro, str(entrada.get("currency") or ""),
                  ler_instante(entrada.get("last_refreshed_at")))
    dados = entrada.get("data")
    if erro or not isinstance(dados, list):
        lst.erro = lst.erro or "SEM_DADOS"
        return lst
    for linha in dados:
        if not isinstance(linha, dict):
            continue
        d = ler_data(linha.get("date"))
        if d is None:
            continue
        m = _OCUP.match(str(linha.get("multi_unit_occupancy") or ""))
        if m and int(m.group(2)) > 0:
            vendidas, total = int(m.group(1)), int(m.group(2))
        elif unidades == 1:
            vendidas, total = (1 if str(linha.get("booking_status") or "").strip() else 0), 1
        else:
            continue  # quarto com várias unidades sem ocupação informada: data desconhecida
        enviado = _numero(linha.get("user_price"))
        indisponivel = enviado == -1 or _numero(linha.get("unbookable")) == 1
        bloqueado = indisponivel and vendidas < total
        lst.dias[d] = Dia(d, min(vendidas, total), total, _numero(linha.get("price")), enviado, bloqueado)
    return lst


def ocupacao(lst: Listing, hoje: date, de: int, ate: int) -> tuple[int, int, float | None]:
    vendidas = total = 0
    for i in range(de, ate + 1):
        dia = lst.dias.get(hoje + timedelta(days=i))
        if dia is None or (dia.bloqueado and dia.total == 1):
            continue
        vendidas += dia.vendidas
        total += dia.total
    return vendidas, total, (100.0 * vendidas / total if total else None)


def ocupacao_faixas(lst: Listing, hoje: date) -> dict:
    return {nome: ocupacao(lst, hoje, a, b)[2] for nome, (a, b) in FAIXAS.items()}


def mediana_preco(lst: Listing, fim_de_semana: bool | None = None) -> float | None:
    """Mediana dos preços; com fim_de_semana, só sextas e sábados (True) ou só os outros dias (False)."""
    precos = [d.preco for d in lst.dias.values() if d.preco and d.preco > 0
              and (fim_de_semana is None or (d.data.weekday() in (4, 5)) == fim_de_semana)]
    return statistics.median(precos) if precos else None


@dataclass
class ResumoReservas:
    novas_48h: int = 0
    cancel_proximos_3d_48h: int = 0
    noites_vendidas_apos: dict = field(default_factory=dict)  # date -> [booked_at, ...]


def noites(reserva: dict) -> list[date]:
    chegada = ler_data(reserva.get("check_in"))
    if chegada is None:
        return []
    n = reserva.get("no_of_days")
    if not isinstance(n, int) or isinstance(n, bool) or n < 1:
        saida = ler_data(reserva.get("check_out"))  # na API real, check_out é a última noite
        n = (saida - chegada).days + 1 if saida and saida >= chegada else 1
    return [chegada + timedelta(days=i) for i in range(min(n, 60))]


def resumir_reservas(reservas: list, listing_id: str, agora: datetime) -> ResumoReservas:
    r = ResumoReservas()
    limite = agora - timedelta(hours=48)
    hoje = agora.date()
    for res in reservas:
        if res.get("listing_id") != listing_id:
            continue
        status = str(res.get("booking_status") or "").lower()
        # data sem hora: fim do dia local, para contar como recente (bloqueia desconto, remove desconto)
        reservada_em = ler_instante(res.get("booked_date"), agora.tzinfo, fim_do_dia=True)
        if status == "booked":
            if reservada_em and reservada_em >= limite:
                r.novas_48h += 1
            for d in noites(res):
                r.noites_vendidas_apos.setdefault(d, []).append(reservada_em)
        elif status == "cancelled":
            cancelada_em = ler_instante(res.get("cancelled_on"), agora.tzinfo, fim_do_dia=False)  # só abre o portão se certo
            chegada = ler_data(res.get("check_in"))
            if cancelada_em and cancelada_em >= limite and chegada and 0 <= (chegada - hoje).days <= 2:
                r.cancel_proximos_3d_48h += 1
    return r


def vendeu_depois(resumo: ResumoReservas, dia: date, instante: datetime) -> bool:
    return any(t is not None and t > instante for t in resumo.noites_vendidas_apos.get(dia, []))


def _ocupacao_valida(v):
    """A API usa negativos como códigos (-1, -2 pendente, -4 indisponível): não são ocupação."""
    x = _numero(v)
    return x if x is not None and 0 <= x <= 100 else None


def mercado_7d(metricas: dict):
    try:
        v = metricas["market_level"]["occupancy"]["7"]
    except (KeyError, TypeError):
        return None
    return _ocupacao_valida(v)


def amostra_receita(metricas_por_listing: dict) -> dict | None:
    """Média de RevPAR e ocupação dos últimos 30 dias (chave DFD "-30")."""
    revpar, ocup = [], []
    for m in metricas_por_listing.values():
        try:
            rv = _numero(m["listing_level"]["revpar"]["-30"])
            oc = _numero(m["listing_level"]["occupancy"]["-30"])
        except (KeyError, TypeError):
            continue
        oc = _ocupacao_valida(oc)
        if rv is not None and rv >= 0 and oc is not None:
            revpar.append(rv)
            ocup.append(oc)
    if not revpar:
        return None
    return {"revpar": sum(revpar) / len(revpar), "ocupacao": sum(ocup) / len(ocup)}


def trava_receita(amostras: list, hoje: date) -> bool:
    """Ocupação subindo e RevPAR caindo em duas comparações semanais seguidas."""
    por_dia = {ler_data(a.get("dia")): a for a in amostras if ler_data(a.get("dia"))}

    def perto(alvo: date):
        for delta in (0, -1, 1):
            a = por_dia.get(alvo + timedelta(days=delta))
            if a:
                return a
        return None

    a0, a7, a14 = perto(hoje), perto(hoje - timedelta(days=7)), perto(hoje - timedelta(days=14))
    if not (a0 and a7 and a14):
        return False
    return (a0["ocupacao"] > a7["ocupacao"] > a14["ocupacao"]) and (a0["revpar"] < a7["revpar"] < a14["revpar"])


def conflitos_ocupacao(lst: Listing, reservas: list, listing_id: str, hoje: date, dias: int = 7) -> list:
    """Datas em que as reservas mostram mais unidades vendidas que o calendário do PriceLabs.

    Só conta reservas feitas antes da última atualização do calendário, que ele já deveria conhecer.
    """
    if lst.atualizado_em is None:
        return []
    contagem = {}
    for res in reservas:
        if res.get("listing_id") != listing_id or str(res.get("booking_status") or "").lower() != "booked":
            continue
        feita = ler_instante(res.get("booked_date"))
        if feita is None or feita >= lst.atualizado_em:
            continue
        for d in noites(res):
            if 0 <= (d - hoje).days < dias:
                contagem[d] = contagem.get(d, 0) + 1
    return sorted(d for d, n in contagem.items() if d in lst.dias and n > lst.dias[d].vendidas)

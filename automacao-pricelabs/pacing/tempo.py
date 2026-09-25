"""Datas, horários de sincronização e feriados nacionais do Brasil."""

from __future__ import annotations

from datetime import date, datetime, time, timedelta, timezone


def fuso(horas: int) -> timezone:
    return timezone(timedelta(hours=horas))


def agora(fuso_utc: int) -> datetime:
    return datetime.now(fuso(fuso_utc))


def ler_instante(texto, fuso_local=None, fim_do_dia: bool = False) -> datetime | None:
    """ISO 8601 com fuso (ex.: 2026-08-27T18:17:10.000Z). Sem fuso, assume UTC.

    Só a data (AAAA-MM-DD) com fuso_local: início ou fim daquele dia no horário local,
    para quem chama escolher o lado conservador.
    """
    if not isinstance(texto, str) or len(texto) < 10:
        return None
    if fuso_local is not None and len(texto.strip()) == 10:
        d = ler_data(texto)
        if d is None:
            return None
        return datetime.combine(d, time(23, 59, 59) if fim_do_dia else time(0, 0), tzinfo=fuso_local)
    t = texto.strip().replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(t if "T" in t or " " in t else t + "T00:00:00+00:00")
    except ValueError:
        try:
            dt = datetime.fromisoformat(t[:19])
        except ValueError:
            return None
    return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)


def ler_data(texto) -> date | None:
    try:
        return date.fromisoformat(str(texto)[:10])
    except ValueError:
        return None


def proxima_sincronizacao(instante: datetime, horarios: list[str]) -> datetime:
    """Primeira sincronização estritamente depois de `instante`, no fuso de `instante`."""
    candidatos = []
    for dia in (instante.date(), instante.date() + timedelta(days=1)):
        for h in horarios:
            hh, mm = (int(x) for x in h.split(":"))
            momento = datetime.combine(dia, time(hh, mm), tzinfo=instante.tzinfo)
            if momento > instante:
                candidatos.append(momento)
    return min(candidatos)


def pascoa(ano: int) -> date:
    """Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher)."""
    a, b, c = ano % 19, ano // 100, ano % 100
    d, e = b // 4, b % 4
    f = (b + 8) // 25
    g = (b - f + 1) // 3
    h = (19 * a + b - d - g + 15) % 30
    i, k = c // 4, c % 4
    l = (32 + 2 * e + 2 * i - h - k) % 7
    m = (a + 11 * h + 22 * l) // 451
    mes = (h + l - 7 * m + 114) // 31
    dia = ((h + l - 7 * m + 114) % 31) + 1
    return date(ano, mes, dia)


FIXOS = ((1, 1), (4, 21), (5, 1), (9, 7), (10, 12), (11, 2), (11, 15), (11, 20), (12, 25))


def feriados(ano: int, locais=()) -> set[date]:
    p = pascoa(ano)
    dias = {date(ano, m, d) for m, d in FIXOS}
    dias |= {p - timedelta(days=48), p - timedelta(days=47),  # Carnaval
             p - timedelta(days=2), p - timedelta(days=1), p,  # Sexta-feira Santa a Páscoa
             p + timedelta(days=60)}  # Corpus Christi
    dias |= {d for d in (ler_data(x) for x in locais) if d and d.year == ano}
    return dias


def dias_bloqueados(inicio: date, fim: date, locais=()) -> set[date]:
    """Feriados e vésperas entre inicio e fim (inclusive)."""
    todos = set()
    for ano in range(inicio.year, fim.year + 2):
        todos |= feriados(ano, locais)
    com_vespera = todos | {d - timedelta(days=1) for d in todos}
    return {d for d in com_vespera if inicio <= d <= fim}

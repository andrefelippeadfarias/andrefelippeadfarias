"""Base da análise nov/2026-fev/2027: leitura dos dados próprios (preços do PriceLabs, inventário e reservas do Beds24).

Só leitura de arquivos; nada é gravado fora do scratchpad.
"""
from __future__ import annotations

import json
import sys
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path

A = Path('/tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/an1110')
SKILL = Path('/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas')
sys.path.insert(0, str(SKILL / 'scripts'))
import faixas_dono  # noqa: E402
from comum import carregar_quartos  # noqa: E402

CFG = carregar_quartos()
QJ = json.load(open(SKILL / 'dados' / 'quartos.json'))
P = QJ['parametros']
HOJE = date(2026, 11, 10)          # primeira noite analisada (terça)
FIM = date(2027, 2, 28)            # última noite analisada
QUARTOS = ['Q7', 'Double', 'Q2', 'Afrodite', 'VK7', 'VK2', 'Balcony']
NOME = {'Q7': 'Moinhos', 'Double': 'Panorâmica I', 'Q2': 'Panorâmica II', 'Afrodite': 'Afrodite',
        'VK7': 'King Spa 7', 'VK2': 'King Spa 2', 'Balcony': 'Balcony'}
POUSADA = {'Q7': 'Recanto', 'Double': 'Recanto', 'Q2': 'Recanto', 'Afrodite': 'Recanto',
           'VK7': 'Villa', 'VK2': 'Villa', 'Balcony': 'Villa'}
UNID = {q['curto']: int(q['unidades']) for q in QJ['quartos']}
ROOMID = {'722805': 'Q7', '722806': 'Double', '722807': 'Afrodite', '722808': 'Q2',
          '722813': 'VK7', '722814': 'Balcony', '722815': 'VK2'}
F_PUB = {k: P['fator_publico'][k] for k in ('Recanto', 'Villa')}
F_G3 = {'semana': {k: P['fator_vitrine_semana'][k] for k in ('Recanto', 'Villa')},
        'fds': {k: P['fator_vitrine_fds'][k] for k in ('Recanto', 'Villa')}}
CAP = {'Recanto': sum(UNID[q] for q in QUARTOS if POUSADA[q] == 'Recanto'),
       'Villa': sum(UNID[q] for q in QUARTOS if POUSADA[q] == 'Villa')}
DIAS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom']
TIPO_GRUPO = {'semana': 'semana', 'fds': 'fds', 'feriado': 'feriado', 'natal': 'festas', 'entre_festas': 'festas',
              'reveillon': 'festas', 'inverno_fds': 'fds', 'inverno_semana': 'semana'}
GRUPOS = ['semana', 'fds', 'feriado', 'festas']
GRUPO_NOME = {'semana': 'Dia de semana (dom-qui)', 'fds': 'Fim de semana (sex-sáb)', 'feriado': 'Feriado',
              'festas': 'Natal e Réveillon (23/12-02/01)'}


def noites(de: date = HOJE, ate: date = FIM):
    d = de
    while d <= ate:
        yield d
        d += timedelta(days=1)


def tipo(d: date) -> str:
    return faixas_dono.tipo_da_noite(d, CFG)


def grupo(d: date) -> str:
    return TIPO_GRUPO[tipo(d)]


def g3(q: str, d: date) -> float:
    """Fator do Genius 3: sexta e sábado usam o de fim de semana (como nos relatórios da rotina)."""
    return F_G3['fds' if d.weekday() in (4, 5) else 'semana'][POUSADA[q]]


def precos() -> dict:
    """{quarto: {data ISO: registro do get_listing_prices}} (lido em 09/11 à noite)."""
    out = {}
    for q in QUARTOS:
        d = json.load(open(A / 'precos' / f'{q}.json'))['data'][0]
        out[q] = {x['date']: x for x in d['data']}
    return out


def inventario() -> dict:
    """{quarto: {data ISO: {numAvail, minStay, maxStay, override, price1}}} do Beds24 (lido em 09/11 à noite)."""
    j = json.load(open(A / 'inv.json'))
    return {q: j[q] for q in QUARTOS}


def reservas() -> list[dict]:
    """Reservas do Beds24 com chegada de 01/09/2026 a 28/02/2027 (todas as situações), sem nomes."""
    j = json.load(open(A / 'reservas.json'))
    out = []
    for b in j['data']:
        q = ROOMID.get(str(b.get('roomId')))
        if not q:
            continue
        ci, co = date.fromisoformat(b['arrival']), date.fromisoformat(b['departure'])
        n = (co - ci).days
        if n <= 0:
            continue
        out.append({'id': b['id'], 'quarto': q, 'pousada': POUSADA[q], 'status': b.get('status'), 'chegada': ci,
                    'saida': co, 'noites': n, 'qtd': int(b.get('roomQty') or 1),
                    'receita': float(b.get('receita_hospedagem') or 0.0), 'preco': float(b.get('price') or 0.0),
                    'canal': (b.get('channel') or 'direct').lower(), 'feita_em': b.get('bookingTime'),
                    'cancelada_em': b.get('cancelTime')})
    return out


ATIVAS = ('confirmed', 'new')


def noites_vendidas(res: list[dict], de: date, ate: date) -> dict:
    """{(quarto, data): {'un': unidades vendidas, 'rec': receita de hospedagem da noite}} das reservas ativas.
    A receita da reserva é dividida igualmente pelas noites dela."""
    out = defaultdict(lambda: {'un': 0, 'rec': 0.0, 'canais': defaultdict(float)})
    for b in res:
        if b['status'] not in ATIVAS:
            continue
        por_noite = b['receita'] / b['noites']
        d = b['chegada']
        while d < b['saida']:
            if de <= d <= ate:
                c = out[(b['quarto'], d)]
                c['un'] += b['qtd']
                c['rec'] += por_noite
                c['canais'][b['canal']] += por_noite
            d += timedelta(days=1)
    return out


def mes(d: date) -> str:
    return f'{d.year}-{d.month:02d}'


def reais(v) -> str:
    if v is None:
        return '—'
    return 'R$ ' + f'{v:,.0f}'.replace(',', '.')

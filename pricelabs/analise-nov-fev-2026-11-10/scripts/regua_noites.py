"""Régua de mercado por noite (10/11/2026 a 28/02/2027) a partir da varredura de 09-10/11 (análise nov-fev).

Por noite: tipo, fonte (estadia medida que contém a noite, ou agregado mês×tipo, ou média do tipo nos outros meses),
percentis do quarto mais barato (lista) e do quarto com hidro (todos e pares de nota < 9), fator público medido
(Booking ÷ enviado) por pousada e o preço enviado pedido em 09/11 por suíte (teto "nunca acima de hoje")."""
import json, statistics as st, sys
from datetime import date, timedelta
from pathlib import Path
AN = Path('/tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/an1110')
sys.path.insert(0, str(AN))
from base import tipo, precos, QUARTOS, POUSADA, F_PUB, TIPO_GRUPO  # noqa: E402
DM = json.load(open(AN / 'dados_mercado.json'))
PL = json.load(open(AN / 'dados_plano.json'))
PR = precos()
SER = ('lista', 'hidro', 'pares_lista', 'pares_hidro')


def da_estadia(e):
    return {'lista': {k: v for k, v in e['lista'].items() if k != 'n'}, 'hidro': {k: v for k, v in e['hidro'].items() if k != 'n'},
            'pares_lista': {k: v for k, v in e['pares']['lista'].items() if k != 'n'},
            'pares_hidro': {k: v for k, v in e['pares']['hidro'].items() if k != 'n'},
            'com_preco': e['com_preco'], 'n_pares': e['pares']['lista']['n']}


def da_regua(g):
    return {s: g[s] for s in SER} | {'com_preco': g.get('com_preco'), 'n_pares': g.get('n_pares')}


def media_tipo(s):
    outros = [v for k, v in PL['regua'].items() if v['sub'] == s]
    out = {}
    for ser in SER:
        out[ser] = {p: round(st.mean(o[ser][p] for o in outros if o[ser].get(p) is not None)) for p in outros[0][ser]}
    return out | {'com_preco': None, 'n_pares': None}


FM = PL['fatores_medidos']


def fpub(p, m, s):
    for k in (f'{p}|{m}|{s}', f'{p}|*|{s}', f'{p}|*|{TIPO_GRUPO[s]}'):
        if k in FM:
            return FM[k]['mediana'], k
    # fallback: agregados por tipo
    for alvo in (lambda k: k.split('|')[2] == s, lambda k: TIPO_GRUPO.get(k.split('|')[2]) == TIPO_GRUPO[s]):
        vals = [v['mediana'] for k, v in FM.items() if k.startswith(p + '|') and alvo(k)]
        if vals:
            return round(st.median(vals), 4), f'{p}|*|{s} (mediana das medidas do tipo/grupo)'
    return F_PUB[p], 'padrao'


noites = {}
d = date(2026, 11, 10)
while d <= date(2027, 2, 28):
    iso, t, m = d.isoformat(), tipo(d), d.isoformat()[:7]
    est = [e for e in DM['estadias'] if e['checkin'] <= iso < e['checkout'] and e.get('tipo') == t]
    if est:
        r, fonte = da_estadia(est[0]), f"estadia {est[0]['checkin']}>{est[0]['checkout']}"
    elif f'{m}|{t}' in PL['regua']:
        r, fonte = da_regua(PL['regua'][f'{m}|{t}']), f'agregado {m}|{t} ({", ".join(PL["regua"][f"{m}|{t}"]["estadias"])})'
    else:
        r, fonte = media_tipo(t), f'média de {t} nos outros meses'
    fp = {p: fpub(p, m, t) for p in ('Recanto', 'Villa')}
    if est:
        for p, v in (est[0].get('fator_medido') or {}).items():
            fp[p] = (v, f"medido na estadia {est[0]['checkin']}>{est[0]['checkout']}")
    noites[iso] = {'tipo': t, 'fonte': fonte, **r,
                   'fator_publico': {p: v[0] for p, v in fp.items()}, 'fator_fonte': {p: v[1] for p, v in fp.items()},
                   'pedido_0911': {q: (round(float(PR[q][iso]['price'])) if iso in PR[q] else None) for q in QUARTOS}}
    d += timedelta(days=1)
out = {'_doc': __doc__, 'lido_em': '2026-11-09/10', 'fonte_respostas': 'pricelabs/pesquisa-booking/faixas-2026-11/novfev_respostas_1..8.json',
       'fonte_precos': 'get_listing_prices 09/11 20:04 UTC (VK2 22:55)', 'noites': noites}
json.dump(out, open('regua_dono.json', 'w'), ensure_ascii=False, indent=1)
from collections import Counter
print(len(noites), Counter(v['fonte'].split(' ')[0] for v in noites.values()), Counter(v['tipo'] for v in noites.values()))
for k in ('2026-11-13', '2026-11-14', '2026-11-17', '2026-11-20', '2026-12-24', '2026-12-31', '2027-01-16', '2027-02-06'):
    v = noites[k]; print(k, v['tipo'], v['fonte'][:40], 'lista40', v['lista']['40'], 'pares_l50', v['pares_lista']['50'], 'pares_h50', v['pares_hidro']['50'], 'h50', v['hidro']['50'], 'f', v['fator_publico'], v['pedido_0911']['Q7'])

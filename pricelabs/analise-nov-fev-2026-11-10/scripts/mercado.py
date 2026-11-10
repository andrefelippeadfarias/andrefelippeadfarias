"""Mercado da Booking (varredura de 09-10/11/2026, conector oficial): régua por estadia.

Por estadia: preço por noite do quarto mais barato com café de cada concorrente da lista (77 hotéis com hidro no quarto,
café e até 3 km do Capivari) = "lista"; × r_hidro do hotel = estimativa do quarto COM hidro. Percentis para todos, para os
pares por nota (nota da Booking abaixo de 9,0) e para os de nota 9,0 ou mais. Saída: dados_mercado.json."""
from __future__ import annotations

import glob

from base import *  # noqa: F401,F403

REPO = Path('/home/user/andrefelippeadfarias')
CAD = json.load(open(REPO / 'pricelabs' / 'pesquisa-booking' / 'concorrentes.json'))
REF = {h['slug']: h for h in CAD['hoteis']}
PAD = float(CAD['r_hidro_padrao'])
PCTS = (10, 25, 40, 50, 65, 75, 90)


def quantil(v, p):
    return faixas_dono.quantil(sorted(v), p) if v else None


def perc(v):
    return {str(p): (round(quantil(v, p)) if v else None) for p in PCTS} | {'n': len(v)}


def posicao(v, x):
    """Percentil (0-100) em que o valor x cai na lista v (fração de hotéis com preço menor ou igual)."""
    if not v or x is None:
        return None
    return round(100 * sum(1 for a in v if a <= x) / len(v))


def main():
    est = {}
    for f in sorted(glob.glob(str(A / 'sweep' / 'respostas_*.json'))):
        for e in json.load(open(f))['estadias']:
            ci, co = date.fromisoformat(e['checkin']), date.fromisoformat(e['checkout'])
            n = (co - ci).days
            lista, hidro, grupos = [], [], {'pares': {'lista': [], 'hidro': []}, 'nota9': {'lista': [], 'hidro': []},
                                           'A': {'lista': [], 'hidro': []}}
            det = []
            for h in e['hoteis']:
                ref = REF.get(h['slug'])
                if not ref or h.get('preco_total') in (None, 0):
                    continue
                pn = float(h['preco_total']) / n
                ph = pn * faixas_dono.r_hidro(ref, PAD)
                lista.append(pn)
                hidro.append(ph)
                nota = ref.get('nota')
                if nota is not None:
                    g = 'pares' if nota < 9.0 else 'nota9'
                    grupos[g]['lista'].append(pn)
                    grupos[g]['hidro'].append(ph)
                if ref.get('classe') == 'A':
                    grupos['A']['lista'].append(pn)
                    grupos['A']['hidro'].append(ph)
                det.append({'slug': h['slug'], 'nome': ref['nome'], 'nota': nota, 'classe': ref.get('classe'),
                            'noite': round(pn), 'hidro': round(ph), 'r_medido': bool(ref.get('r_hidro'))})
            est[e['checkin'] + '>' + e['checkout']] = {
                'checkin': e['checkin'], 'checkout': e['checkout'], 'noites': n, 'tipo': e.get('tipo'), 'rotulo': e.get('rotulo'),
                'grupo': grupo(ci), 'com_preco': len(lista), 'total': len(REF),
                'lista': perc(lista), 'hidro': perc(hidro),
                'pares': {'lista': perc(grupos['pares']['lista']), 'hidro': perc(grupos['pares']['hidro'])},
                'nota9': {'lista': perc(grupos['nota9']['lista']), 'hidro': perc(grupos['nota9']['hidro'])},
                'classeA': {'lista': perc(grupos['A']['lista']), 'hidro': perc(grupos['A']['hidro'])},
                '_lista': sorted(round(x) for x in lista), '_hidro': sorted(round(x) for x in hidro),
                '_pares_lista': sorted(round(x) for x in grupos['pares']['lista']),
                '_pares_hidro': sorted(round(x) for x in grupos['pares']['hidro']),
                'hoteis': sorted(det, key=lambda x: x['noite'])}
    # nossos hotéis na Booking
    for f in sorted(glob.glob(str(A / 'sweep' / 'nossos_[0-9].json'))):
        for e in json.load(open(f)):
            k = e['checkin'] + '>' + e['checkout']
            if k not in est:
                continue
            n = est[k]['noites']
            est[k]['nossos_booking'] = {
                'Recanto': round(e['recanto_total'] / n) if e.get('recanto_total') else None,
                'Villa': round(e['villa_total'] / n) if e.get('villa_total') else None,
                'recanto_obs': e.get('recanto_obs'), 'villa_obs': e.get('villa_obs')}
    # nosso preço pedido nas mesmas noites (PriceLabs): quarto mais barato com unidade livre em TODAS as noites da estadia
    pr, inv = precos(), inventario()
    for k, e in est.items():
        ci = date.fromisoformat(e['checkin'])
        ns = [ci + timedelta(days=i) for i in range(e['noites'])]
        ped = {}
        for q in QUARTOS:
            if not all(d.isoformat() in pr[q] and d.isoformat() in inv[q] for d in ns):
                continue
            livre = min(inv[q][d.isoformat()]['numAvail'] for d in ns)
            env = sum(float(pr[q][d.isoformat()]['price']) for d in ns) / len(ns)
            vend = sum(UNID[q] - max(inv[q][d.isoformat()]['numAvail'], 0) for d in ns)
            ped[q] = {'enviado': round(env), 'livres_min': max(livre, 0), 'vendidas': vend, 'cap': UNID[q] * len(ns)}
        # fator público medido nesta estadia: preço que a Booking mostrou ÷ enviado do quarto livre mais barato
        nb = e.get('nossos_booking') or {}
        e['fator_medido'] = {}
        for p in ('Recanto', 'Villa'):
            liv = [v['enviado'] for q, v in ped.items() if POUSADA[q] == p and v['livres_min'] > 0]
            if nb.get(p) and liv:
                e['fator_medido'][p] = round(nb[p] / min(liv), 4)
        for q, v in ped.items():
            fp = e['fator_medido'].get(POUSADA[q]) or F_PUB[POUSADA[q]]
            v['publico'] = round(v['enviado'] * fp)
            for nome, lst in (('pos_lista', e['_lista']), ('pos_hidro', e['_hidro']), ('pos_pares_lista', e['_pares_lista']),
                              ('pos_pares_hidro', e['_pares_hidro'])):
                v[nome + '_publico'] = posicao(lst, v['publico'])
        e['pedido'] = ped
        for p in ('Recanto', 'Villa'):
            liv = [(v['publico'], q) for q, v in ped.items() if POUSADA[q] == p and v['livres_min'] > 0]
            e.setdefault('mais_barato', {})[p] = {'quarto': min(liv)[1], 'publico': min(liv)[0]} if liv else None
            cap = sum(v['cap'] for q, v in ped.items() if POUSADA[q] == p)
            vend = sum(v['vendidas'] for q, v in ped.items() if POUSADA[q] == p)
            e.setdefault('ocup', {})[p] = round(vend / cap, 4) if cap else None
    out = {'lido_em': '2026-11-09/10', 'r_hidro_padrao': PAD, 'concorrentes': len(REF),
           'notas': {'pares(<9,0)': sum(1 for h in CAD['hoteis'] if h.get('nota') is not None and h['nota'] < 9),
                     'nota9+': sum(1 for h in CAD['hoteis'] if h.get('nota') is not None and h['nota'] >= 9),
                     'sem_nota': sum(1 for h in CAD['hoteis'] if h.get('nota') is None)},
           'estadias': [est[k] for k in sorted(est)]}
    json.dump(out, open(A / 'dados_mercado.json', 'w'), ensure_ascii=False, indent=1)
    print(f"{len(est)} estadias; concorrentes {len(REF)}; notas {out['notas']}")
    print('estadia              tipo     c/preço | LISTA p25/p50/p75 | HIDRO p25/p50/p75 | PARES lista p50 / hidro p50 (n) | nossos Booking Rec/Villa | pedido mais barato Rec / Villa (público) | ocup Rec/Villa')
    for e in out['estadias']:
        nb = e.get('nossos_booking') or {}
        mb = e['mais_barato']
        print(f"{e['checkin']}>{e['checkout'][5:]} {str(e['rotulo'])[:22]:22} {e['com_preco']:>2}/77 | "
              f"{e['lista']['25']}/{e['lista']['50']}/{e['lista']['75']} | {e['hidro']['25']}/{e['hidro']['50']}/{e['hidro']['75']} | "
              f"{e['pares']['lista']['50']}/{e['pares']['hidro']['50']} ({e['pares']['lista']['n']}) | "
              f"{nb.get('Recanto')}/{nb.get('Villa')} | "
              f"{(mb['Recanto'] or {}).get('quarto')} {(mb['Recanto'] or {}).get('publico')} / {(mb['Villa'] or {}).get('quarto')} {(mb['Villa'] or {}).get('publico')} | "
              f"{e['ocup']['Recanto']:.0%}/{e['ocup']['Villa']:.0%} | fator {e['fator_medido']}")


if __name__ == '__main__':
    main()

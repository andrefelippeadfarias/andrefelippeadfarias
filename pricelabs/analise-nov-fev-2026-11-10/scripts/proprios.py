"""Dados próprios da análise nov-fev: realizado, fins de semana passados, preço pago, livro × meta e posição atual.
Saída: dados_proprios.json (nada de nomes de hóspedes)."""
from __future__ import annotations

import statistics as st

from base import *  # noqa: F401,F403


def pct(v, p):
    v = sorted(v)
    if not v:
        return None
    k = (len(v) - 1) * p / 100
    f = int(k)
    c = min(f + 1, len(v) - 1)
    return round(v[f] + (v[c] - v[f]) * (k - f), 2)


def linhas_noite(res):
    """Uma linha por noite vendida (reservas ativas): preço pago pelo hóspede por noite, receita, canal, antecedência."""
    out = []
    for b in res:
        if b['status'] not in ATIVAS:
            continue
        feita = date.fromisoformat(b['feita_em'][:10]) if b['feita_em'] else None
        d = b['chegada']
        while d < b['saida']:
            out.append({'q': b['quarto'], 'p': b['pousada'], 'd': d, 'g': grupo(d), 'pago': b['preco'] / b['noites'],
                        'rec': b['receita'] / b['noites'], 'canal': b['canal'], 'qtd': b['qtd'],
                        'ante': (d - feita).days if feita else None, 'id': b['id']})
            d += timedelta(days=1)
    return out


def main():
    res = reservas()
    pr = precos()
    inv = inventario()
    L = linhas_noite(res)
    PASSADO_DE, PASSADO_ATE = date(2026, 9, 1), date(2026, 11, 9)
    dados = {'hoje': HOJE.isoformat(), 'fim': FIM.isoformat(), 'cap': CAP, 'unid': UNID, 'nome': NOME,
             'pousada': POUSADA, 'fatores': {'publico': F_PUB, 'g3': F_G3},
             'reservas_lidas_em': json.load(open(A / 'reservas.json')).get('lido_em'),
             'precos_lidos_em': {q: json.load(open(A / 'precos' / f'{q}.json'))['data'][0]['last_refreshed_at'] for q in QUARTOS}}

    # ---------- A. realizado por mês × pousada × grupo ----------
    real = []
    for m_de, m_ate, rot in ((date(2026, 9, 1), date(2026, 9, 30), 'set/2026'),
                             (date(2026, 10, 1), date(2026, 10, 31), 'out/2026'),
                             (date(2026, 11, 1), date(2026, 11, 9), 'nov/2026 (1 a 9)')):
        for p in ('Recanto', 'Villa'):
            for g in GRUPOS:
                ns = [d for d in noites(m_de, m_ate) if grupo(d) == g]
                if not ns:
                    continue
                r = [x for x in L if x['p'] == p and x['g'] == g and m_de <= x['d'] <= m_ate]
                un = sum(x['qtd'] for x in r)
                rec = sum(x['rec'] for x in r)
                pg = [x['pago'] for x in r if x['pago'] > 0]
                cap = CAP[p] * len(ns)
                real.append({'periodo': rot, 'pousada': p, 'grupo': g, 'noites': len(ns), 'cap': cap, 'vendidas': un,
                             'ocup': round(un / cap, 4), 'receita': round(rec), 'adr': round(rec / un) if un else None,
                             'revpar': round(rec / cap), 'pago_p25': pct(pg, 25), 'pago_p50': pct(pg, 50),
                             'pago_p75': pct(pg, 75)})
    dados['realizado'] = real

    # ---------- B. fins de semana passados (sex + sáb) ----------
    fp = []
    d = date(2026, 9, 4)
    while d <= date(2026, 11, 7):
        s = d + timedelta(days=1)
        item = {'sexta': d.isoformat(), 'tipo': grupo(d), 'pousadas': {}, 'quartos': {}}
        for p in ('Recanto', 'Villa'):
            r = [x for x in L if x['p'] == p and x['d'] in (d, s)]
            un = sum(x['qtd'] for x in r)
            pg = [x['pago'] for x in r if x['pago'] > 0]
            ult = sum(x['qtd'] for x in r if x['ante'] is not None and x['ante'] <= 3)
            sem = sum(x['qtd'] for x in r if x['ante'] is not None and x['ante'] <= 7)
            item['pousadas'][p] = {'vendidas': un, 'cap': 2 * CAP[p], 'ocup': round(un / (2 * CAP[p]), 4),
                                   'pago_p50': pct(pg, 50), 'ult3d': round(ult / un, 4) if un else None,
                                   'ult7d': round(sem / un, 4) if un else None}
        for q in QUARTOS:
            r = [x for x in L if x['q'] == q and x['d'] in (d, s)]
            un = sum(x['qtd'] for x in r)
            pg = [x['pago'] for x in r if x['pago'] > 0]
            item['quartos'][q] = {'vendidas': un, 'cap': 2 * UNID[q], 'pago_p50': pct(pg, 50)}
        fp.append(item)
        d += timedelta(days=7)
    dados['fds_passados'] = fp

    # ---------- C. preço pago pelo hóspede (estadias de 01/09 a 09/11) e antecedência ----------
    pago = {'por_pousada': [], 'por_quarto': [], 'por_canal': [], 'antecedencia': []}
    passado = [x for x in L if PASSADO_DE <= x['d'] <= PASSADO_ATE]
    for p in ('Recanto', 'Villa'):
        for g in ('fds', 'semana', 'feriado'):
            v = [x['pago'] for x in passado if x['p'] == p and x['g'] == g and x['pago'] > 0]
            pago['por_pousada'].append({'pousada': p, 'grupo': g, 'n': len(v), **{f'p{k}': pct(v, k) for k in (10, 25, 50, 75, 90)}})
            a = [x['ante'] for x in passado if x['p'] == p and x['g'] == g and x['ante'] is not None]
            if a:
                fa = {'D0-3': (0, 3), 'D4-7': (4, 7), 'D8-14': (8, 14), 'D15-30': (15, 30), 'D31+': (31, 9999)}
                pago['antecedencia'].append({'pousada': p, 'grupo': g, 'n': len(a),
                                             **{k: round(sum(1 for z in a if lo <= z <= hi) / len(a), 4) for k, (lo, hi) in fa.items()}})
            for c in ('booking', 'airbnb', 'direct', 'expedia'):
                v = [x['pago'] for x in passado if x['p'] == p and x['g'] == g and x['canal'] == c and x['pago'] > 0]
                if len(v) >= 5:
                    pago['por_canal'].append({'pousada': p, 'grupo': g, 'canal': c, 'n': len(v), 'p25': pct(v, 25), 'p50': pct(v, 50), 'p75': pct(v, 75)})
    for q in QUARTOS:
        for g in ('fds', 'semana', 'feriado'):
            v = [x['pago'] for x in passado if x['q'] == q and x['g'] == g and x['pago'] > 0]
            ns = [d for d in noites(PASSADO_DE, PASSADO_ATE) if grupo(d) == g]
            un = sum(x['qtd'] for x in passado if x['q'] == q and x['g'] == g)
            pago['por_quarto'].append({'quarto': q, 'grupo': g, 'n': len(v), 'ocup': round(un / (UNID[q] * len(ns)), 4),
                                       **{f'p{k}': pct(v, k) for k in (10, 25, 50, 75, 90)}})
    dados['pago'] = pago

    # ---------- D. noites futuras (10/11 a 28/02): preço pedido e vendido ----------
    nv = noites_vendidas(res, HOJE, FIM)
    noi = []
    for q in QUARTOS:
        for d in noites():
            x = pr[q].get(d.isoformat())
            i = inv[q].get(d.isoformat())
            if not x or not i:
                continue
            env = float(x['price'])
            vend = UNID[q] - max(int(i['numAvail']), 0)
            noi.append({'q': q, 'd': d.isoformat(), 'dia': DIAS[d.weekday()], 'tipo': tipo(d), 'g': grupo(d), 'mes': mes(d),
                        'D': (d - HOJE).days, 'enviado': round(env), 'publico': round(env * F_PUB[POUSADA[q]]),
                        'g3': round(env * g3(q, d)), 'vendidas': vend, 'un': UNID[q],
                        'vendidas_reservas': nv[(q, d)]['un'] if (q, d) in nv else 0,
                        'receita': round(nv[(q, d)]['rec']) if (q, d) in nv else 0,
                        'min_stay': i.get('minStay'), 'override': i.get('override'),
                        'uncustomized': x.get('uncustomized_price'), 'demanda': x.get('demand_desc')})
    dados['noites'] = noi

    # ---------- E. livro × meta por mês e pousada ----------
    livro = json.load(open(A / 'livro.json'))
    metas = json.load(open(SKILL / 'dados' / 'metas_faturamento.json'))
    md = metas['metas_dono']
    lm = []
    for m in ('2026-11', '2026-12', '2027-01', '2027-02'):
        y, mm = int(m[:4]), int(m[5:])
        ini = date(y, mm, 1)
        fim = (date(y + (mm == 12), mm % 12 + 1, 1) - timedelta(days=1))
        for p in ('Recanto', 'Villa'):
            lv = next((x for x in livro if x['mes'] == m and x['pousada'] == p), None)
            alta = m in md['meses_alta']
            meta = md['alta' if alta else 'baixa'][p.lower()]
            pp = metas['por_pousada'].get(m, {}).get(p.lower(), {})
            dias_mes = (fim - ini).days + 1
            cap_mes = CAP[p] * dias_mes
            fut = [n for n in noi if n['mes'] == m and POUSADA[n['q']] == p]
            por_g = {}
            for g in GRUPOS:
                f = [n for n in fut if n['g'] == g]
                if not f:
                    continue
                cap = sum(n['un'] for n in f)
                vend = sum(n['vendidas'] for n in f)
                livres = [n for n in f if n['vendidas'] < n['un']]
                un_livres = sum(n['un'] - n['vendidas'] for n in livres)
                por_g[g] = {'noites': len({n['d'] for n in f}), 'cap': cap, 'vendidas': vend, 'ocup': round(vend / cap, 4),
                            'livres': un_livres,
                            'pedido_enviado': round(sum(n['enviado'] * (n['un'] - n['vendidas']) for n in livres) / un_livres) if un_livres else None,
                            'pedido_publico': round(sum(n['publico'] * (n['un'] - n['vendidas']) for n in livres) / un_livres) if un_livres else None,
                            'pedido_g3': round(sum(n['g3'] * (n['un'] - n['vendidas']) for n in livres) / un_livres) if un_livres else None,
                            'receita_vendida': sum(n['receita'] for n in f)}
            lm.append({'mes': m, 'pousada': p, 'temporada': 'alta' if alta else 'baixa', 'meta_dono': meta,
                       'realista_1410': pp.get('realista'), 'maximo_1410': pp.get('maximo'),
                       'livro': round(lv['receita']) if lv else 0, 'livro_confirmadas': round(lv['receita_confirmadas']) if lv else 0,
                       'unidades_noite_vendidas': lv['unidades_noite'] if lv else 0, 'adr_livro': round(lv['adr']) if lv else None,
                       'cap_mes': cap_mes, 'ocup_livro_mes': round((lv['unidades_noite'] if lv else 0) / cap_mes, 4),
                       'falta': round(meta - (lv['receita'] if lv else 0)),
                       'futuro': por_g,
                       'livres_futuro': sum(g['livres'] for g in por_g.values()),
                       'revpar_meta': round(meta / cap_mes)})
    dados['livro_meta'] = lm

    # ---------- F. o que mudou desde 01/11 (inventário do Beds24 lido em 01/11 à noite × hoje) ----------
    ant = json.load(open(A.parent / 'fds3m' / 'inv_nov_jan.json'))
    nb = {x['checkin']: x for x in json.load(open(A.parent / 'fds3m' / 'nossos.json'))}
    desde = []
    for sx in ('2026-11-13', '2026-11-20', '2026-11-27', '2026-12-04', '2026-12-11', '2026-12-18'):
        d = date.fromisoformat(sx)
        s = d + timedelta(days=1)
        it = {'sexta': sx, 'tipo': grupo(d), 'pousadas': {}, 'quartos': {}}
        for q in QUARTOS:
            va = sum(UNID[q] - max(int(ant[q][x.isoformat()]['numAvail']), 0) for x in (d, s))
            vh = sum(UNID[q] - max(int(inv[q][x.isoformat()]['numAvail']), 0) for x in (d, s))
            it['quartos'][q] = {'antes': va, 'hoje': vh, 'cap': 2 * UNID[q]}
        for p in ('Recanto', 'Villa'):
            qs = [q for q in QUARTOS if POUSADA[q] == p]
            it['pousadas'][p] = {'antes': round(sum(it['quartos'][q]['antes'] for q in qs) / (2 * CAP[p]), 4),
                                 'hoje': round(sum(it['quartos'][q]['hoje'] for q in qs) / (2 * CAP[p]), 4)}
        x = nb.get(sx) or {}
        it['booking_0111'] = {'Recanto': round(x['recanto_total'] / x['noites']) if x.get('recanto_total') else None,
                              'Villa': round(x['villa_total'] / x['noites']) if x.get('villa_total') else None}
        desde.append(it)
    dados['desde_0111'] = desde

    json.dump(dados, open(A / 'dados_proprios.json', 'w'), ensure_ascii=False, indent=1)

    # ---------- resumo no terminal ----------
    print('LIVRO × META (mínimo do dono)')
    for x in lm:
        fg = ' | '.join(f"{g}: {v['ocup']:.0%} ({v['vendidas']}/{v['cap']}), livres {v['livres']} a {reais(v['pedido_publico'])} púb/{reais(v['pedido_g3'])} G3"
                        for g, v in x['futuro'].items())
        nec = x['falta'] / x['livres_futuro'] if x['livres_futuro'] else 0
        print(f"{x['mes']} {x['pousada']:8} meta {reais(x['meta_dono'])} livro {reais(x['livro'])} ({x['livro'] / x['meta_dono']:.0%}) falta {reais(x['falta'])}"
              f" | ocup. do mês no livro {x['ocup_livro_mes']:.0%} ADR {reais(x['adr_livro'])} | livres {x['livres_futuro']} → {reais(nec)}/unidade livre se vender 100%")
        print('     ', fg)
    print('\nPREÇO PAGO por quarto (estadias 01/09-09/11)')
    for x in pago['por_quarto']:
        if x['grupo'] in ('fds', 'semana'):
            print(f"  {NOME[x['quarto']]:14} {x['grupo']:7} ocup {x['ocup']:.0%} n={x['n']:>3} p25 {x['p25']} p50 {x['p50']} p75 {x['p75']} p90 {x['p90']}")


if __name__ == '__main__':
    main()

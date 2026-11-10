"""Plano nov/2026-fev/2027: régua do mercado por mês e tipo de noite, posição atual, preço proposto e expectativa.

Entradas: dados_proprios.json (proprios.py), dados_mercado.json (mercado.py), mercado/mercado.json (PriceLabs).
Saída: dados_plano.json. Tudo aqui é proposta: nada é gravado no PriceLabs.

Conversões (medidas):
  público  = enviado × fator_publico (Booking sem Genius; Recanto 0,63, Villa 0,65; conferido com a busca dos nossos hotéis)
  Genius 3 = enviado × 0,41/0,425 (Recanto, semana/fds) e 0,53/0,575 (Villa)
  pago     = enviado × R_PAGO: média do que o hóspede paga por noite ÷ preço enviado no dia da reserva
             (reservas feitas de 02 a 09/11/2026: Recanto 0,49-0,50, n=28; Villa 0,44-0,48, n=74)
"""
from __future__ import annotations

import math
import statistics as st

from base import *  # noqa: F401,F403

R_PAGO = {'Recanto': 0.49, 'Villa': 0.46}
MESES = ['2026-11', '2026-12', '2027-01', '2027-02']
MES_NOME = {'2026-11': 'Novembro (10 a 30)', '2026-12': 'Dezembro', '2027-01': 'Janeiro', '2027-02': 'Fevereiro'}
# subtipos para a régua: festas separadas
SUB = {'semana': 'semana', 'fds': 'fds', 'feriado': 'feriado', 'natal': 'natal', 'entre_festas': 'entre_festas',
       'reveillon': 'reveillon'}
SUB_NOME = {'semana': 'Dia de semana (dom-qui)', 'fds': 'Fim de semana (sex-sáb)', 'feriado': 'Feriado',
            'natal': 'Natal (23-26/12)', 'entre_festas': 'Entre festas (27-29/12)', 'reveillon': 'Réveillon (30/12-02/01)'}
SUBS = ['semana', 'fds', 'feriado', 'natal', 'entre_festas', 'reveillon']

# Regra de abertura (preço PÚBLICO de partida) e teto da escada, por tipo de noite e suíte: (série, percentil, multiplicador)
ABERTURA = {
    'fds': {'Balcony': ('pares_lista', '50', 1.0), 'Q7': ('lista', '40', 1.0), 'Q2': ('pares_hidro', '50', 1.05),
            'Double': ('pares_hidro', '50', 1.05), 'Afrodite': ('pares_hidro', '50', 1.05 * 1.15), 'VK7': ('pares_hidro', '50', 1.05),
            'VK2': ('pares_hidro', '50', 1.05)},
    # dia de semana das suítes com banheira: a ABERTURA é o piso atual do dono (R$ 500 no Genius 3); esta linha é o
    # preço da ÚLTIMA SEMANA (D7 a D0) para as unidades ainda livres; a Balcony abre já no preço daqui
    'semana': {'Balcony': ('lista', '40', 1.0), 'Q7': ('hidro', '50', 1.0), 'Q2': ('hidro', '50', 1.0),
               'Double': ('hidro', '50', 1.05), 'Afrodite': ('hidro', '50', 1.2), 'VK7': ('hidro', '50', 1.0),
               'VK2': ('hidro', '50', 1.0)},
    'feriado': {'Balcony': ('lista', '50', 1.0), 'Q7': ('hidro', '40', 1.0), 'Q2': ('hidro', '50', 1.0),
                'Double': ('hidro', '50', 1.0), 'Afrodite': ('hidro', '50', 1.15), 'VK7': ('hidro', '50', 1.0),
                'VK2': ('hidro', '50', 1.0)},
}
for _s in ('natal', 'entre_festas', 'reveillon'):
    ABERTURA[_s] = {'Balcony': ('lista', '50', 1.0), 'Q7': ('hidro', '40', 1.0), 'Q2': ('hidro', '50', 1.0),
                    'Double': ('hidro', '50', 1.0), 'Afrodite': ('hidro', '50', 1.15), 'VK7': ('hidro', '50', 1.0),
                    'VK2': ('hidro', '50', 1.0)}
TETO = {
    'fds': {'Balcony': ('lista', '50', 1.0), 'Q7': ('hidro', '50', 1.0), '*': ('hidro', '65', 1.0)},
    'semana': {'Balcony': ('lista', '65', 1.0), '*': ('hidro', '65', 1.0)},
    'feriado': {'Balcony': ('lista', '75', 1.0), 'Q7': ('hidro', '65', 1.0), '*': ('hidro', '75', 1.0)},
}
for _s in ('natal', 'entre_festas', 'reveillon'):
    TETO[_s] = {'Balcony': ('lista', '75', 1.0), '*': ('hidro', '75', 1.0)}
# ocupação final: "sem mudar" = realizado de 01/09 a 09/11 por pousada e grupo; "provável" e "meta" abaixo
OCUP = {'provavel': {'semana': {'baixa': 0.55, 'alta': 0.60}, 'fds': {'baixa': 0.80, 'alta': 0.85}, 'feriado': {'baixa': 0.90, 'alta': 0.90},
                     'festas': {'baixa': 0.90, 'alta': 0.90}},
        'meta': {'semana': {'baixa': 0.65, 'alta': 0.70}, 'fds': {'baixa': 0.95, 'alta': 0.97}, 'feriado': {'baixa': 0.97, 'alta': 0.97},
                 'festas': {'baixa': 0.97, 'alta': 0.97}}}
PASSO_ESCADA = 0.07      # cada unidade vendida depois da metade sobe o preço das seguintes
DESCIDA_ULTIMA = 0.10    # fim de semana, feriado e festas: a 7 dias, com menos de 60% vendido, a abertura desce 10%
PARTE_ANTECIPADA = {'Recanto': 0.36, 'Villa': 0.34}  # fração das diárias de dia de semana vendidas com 8 dias ou mais
PISO_SEMANA = {p: math.ceil(QJ['limites_seguranca']['piso_vitrine_semana_suites'] / F_G3['semana'][p]) for p in ('Recanto', 'Villa')}
MIN_BALCONY = next(q['min'] for q in QJ['quartos'] if q['curto'] == 'Balcony')


def valor(regua: dict, spec) -> float | None:
    serie, p, mult = spec
    v = {'lista': regua['lista'], 'hidro': regua['hidro'], 'pares_lista': regua['pares_lista'],
         'pares_hidro': regua['pares_hidro']}[serie].get(p)
    return None if v is None else v * mult


def escada(abertura: float, teto: float, un: int) -> list[float]:
    """Preço de cada unidade, da 1ª à última: a primeira metade na abertura; depois +7% por unidade, até o teto."""
    meio = math.ceil(un / 2)
    out = []
    for i in range(1, un + 1):
        p = abertura if i <= meio else abertura * (1 + PASSO_ESCADA) ** (i - meio)
        out.append(min(p, max(teto, abertura)))
    return out


def main():
    dp = json.load(open(A / 'dados_proprios.json'))
    dm = json.load(open(A / 'dados_mercado.json'))
    pl = json.load(open(A / 'mercado' / 'mercado.json'))
    noi = dp['noites']
    # ---------- régua por mês e subtipo: média das estadias medidas ----------
    reg = defaultdict(list)
    for e in dm['estadias']:
        ci = date.fromisoformat(e['checkin'])
        s = tipo(ci)
        m = mes(ci)
        if s == 'reveillon':
            m = '2026-12'
        reg[(m, s)].append(e)
    regua = {}
    for (m, s), es in reg.items():
        def med(f):
            v = [f(e) for e in es if f(e) is not None]
            return round(st.mean(v)) if v else None
        regua[f'{m}|{s}'] = {
            'mes': m, 'sub': s, 'estadias': [e['rotulo'] for e in es], 'n_estadias': len(es),
            'com_preco': med(lambda e: e['com_preco']),
            'lista': {p: med(lambda e, p=p: e['lista'][p]) for p in ('25', '40', '50', '65', '75', '90')},
            'hidro': {p: med(lambda e, p=p: e['hidro'][p]) for p in ('25', '40', '50', '65', '75', '90')},
            'pares_lista': {p: med(lambda e, p=p: e['pares']['lista'][p]) for p in ('25', '50', '75')},
            'pares_hidro': {p: med(lambda e, p=p: e['pares']['hidro'][p]) for p in ('25', '50', '75')},
            'nota9_lista': {p: med(lambda e, p=p: e['nota9']['lista'][p]) for p in ('50',)},
            'nota9_hidro': {p: med(lambda e, p=p: e['nota9']['hidro'][p]) for p in ('50',)},
            'n_pares': med(lambda e: e['pares']['lista']['n']), 'n_nota9': med(lambda e: e['nota9']['lista']['n']),
            'nossos_booking': {p: med(lambda e, p=p: (e.get('nossos_booking') or {}).get(p)) for p in ('Recanto', 'Villa')},
        }

    # ---------- fator público medido (Booking ÷ enviado do quarto livre mais barato), por pousada, mês e subtipo ----------
    fat_obs = defaultdict(list)
    for e in dm['estadias']:
        ci = date.fromisoformat(e['checkin'])
        s, m = tipo(ci), mes(ci)
        if s == 'reveillon':
            m = '2026-12'
        for p, v in (e.get('fator_medido') or {}).items():
            if 0.30 <= v <= 0.80:
                fat_obs[(p, m, s)].append(v)
                fat_obs[(p, '*', s)].append(v)
                fat_obs[(p, '*', TIPO_GRUPO[s])].append(v)

    def fpub(p: str, m: str, s: str) -> float:
        for k in ((p, m, s), (p, '*', s), (p, '*', TIPO_GRUPO[s])):
            if fat_obs.get(k):
                return round(st.median(fat_obs[k]), 4)
        return F_PUB[p]

    def fg3(p: str, m: str, s: str, base: float) -> float:
        # Genius 3 acompanha o público medido: mantém a distância Genius 3 ÷ público dos fatores do dono
        return fpub(p, m, s) * base / F_PUB[p]

    def regua_de(m: str, s: str) -> dict | None:
        if f'{m}|{s}' in regua:
            return regua[f'{m}|{s}']
        # sem estadia medida no mês: usa a média do mesmo subtipo nos outros meses
        outros = [v for k, v in regua.items() if v['sub'] == s]
        if not outros:
            return None
        def md(f):
            v = [f(o) for o in outros if f(o) is not None]
            return round(st.mean(v)) if v else None
        return {'mes': m, 'sub': s, 'estadias': ['média dos outros meses'], 'n_estadias': 0, 'com_preco': None,
                **{serie: {p: md(lambda o, serie=serie, p=p: o[serie].get(p)) for p in outros[0][serie]}
                   for serie in ('lista', 'hidro', 'pares_lista', 'pares_hidro', 'nota9_lista', 'nota9_hidro')},
                'n_pares': None, 'n_nota9': None, 'nossos_booking': {'Recanto': None, 'Villa': None}}

    # ---------- mercado do PriceLabs (Airbnb próximo): ocupação por mês e grupo ----------
    occ_pl = {}
    for p in ('Recanto', 'Villa'):
        dias = {x['date']: x for x in pl['pousadas'][p]['dias']}
        for m in MESES:
            for g in GRUPOS:
                ds = [d for d in noites() if mes(d) == m and grupo(d) == g and d.isoformat() in dias]
                if not ds:
                    continue
                ag = [dias[d.isoformat()]['agregado'] for d in ds]
                occ_pl[f'{p}|{m}|{g}'] = {
                    'agora': round(st.mean(a['occ_mercado'] for a in ag), 1),
                    'stly': round(st.mean(a['occ_mercado_stly'] for a in ag if a.get('occ_mercado_stly') is not None), 1),
                    'ly_final': round(st.mean(a['occ_mercado_ly_final'] for a in ag if a.get('occ_mercado_ly_final') is not None), 1),
                    'p50': round(st.mean(a['p50'] for a in ag)), 'p75': round(st.mean(a['p75'] for a in ag))}
    mensal_pl = {p: {x['mes']: x for x in pl['pousadas'][p]['mensal']} for p in ('Recanto', 'Villa')}

    # ---------- posição atual e proposta por mês × subtipo × quarto ----------
    pago_q = {(x['quarto'], x['grupo']): x for x in dp['pago']['por_quarto']}
    linhas = []
    for m in MESES:
        for s in SUBS:
            ns = [n for n in noi if n['mes'] == m and n['tipo'] == s]
            if not ns:
                continue
            rg = regua_de(m, s)
            for q in QUARTOS:
                nq = [n for n in ns if n['q'] == q]
                if not nq:
                    continue
                p = POUSADA[q]
                cap = sum(n['un'] for n in nq)
                vend = sum(n['vendidas'] for n in nq)
                livres = sum(n['un'] - n['vendidas'] for n in nq)
                peso = lambda campo: (round(sum(n[campo] * (n['un'] - n['vendidas']) for n in nq) / livres) if livres else None)  # noqa: E731
                FP = fpub(p, m, s)
                RPG = min(R_PAGO[p], FP)   # o hóspede não paga, em média, mais que o preço público mostrado pela Booking
                atual_env = peso('enviado')
                atual_pub = round(atual_env * FP) if atual_env else None
                # fator Genius 3 médio das noites (sexta e sábado usam o de fim de semana), ajustado ao público medido
                fg3_ = fg3(p, m, s, st.mean(g3(q, date.fromisoformat(n['d'])) for n in nq))
                ab = valor(rg, ABERTURA[s][q]) if rg else None
                tt = valor(rg, TETO[s].get(q) or TETO[s]['*']) if rg else None
                linha = {'mes': m, 'sub': s, 'grupo': TIPO_GRUPO[s], 'q': q, 'pousada': p, 'noites': len(nq), 'cap': cap,
                         'vendidas': vend, 'ocup': round(vend / cap, 4), 'livres': livres,
                         'fator_publico': FP,
                         'atual': {'enviado': atual_env, 'publico': atual_pub, 'g3': round(atual_env * fg3_) if atual_env else None,
                                   'pago': round(atual_env * RPG) if atual_env else None},
                         'regra_abertura': ABERTURA[s][q], 'regra_teto': TETO[s].get(q) or TETO[s]['*']}
                if ab:
                    def tres(pub):
                        e = pub / FP
                        return {'publico': round(pub), 'enviado': round(e), 'g3': round(e * fg3_), 'pago': round(e * RPG)}
                    if s == 'semana' and q != 'Balcony':
                        # abre no piso atual do dono; na última semana as unidades livres vão ao preço do mercado (F2)
                        mult = ABERTURA[s][q][2]
                        abre = PISO_SEMANA[p] * mult * FP
                        ultima = min(abre, ab)
                        pm = PARTE_ANTECIPADA[p] * abre + (1 - PARTE_ANTECIPADA[p]) * ultima
                        prop = {'abertura': tres(abre), 'ultima': tres(ultima), 'teto': tres(max(tt or abre, abre)),
                                'pago_medio': round(pm / FP * RPG), 'modo': 'piso atual até D8; mercado na última semana'}
                    elif s == 'semana':
                        abre = max(ab, MIN_BALCONY * FP)
                        if atual_pub:
                            abre = min(abre, atual_pub)
                        prop = {'abertura': tres(abre), 'ultima': tres(abre), 'teto': tres(max(tt or abre, abre)),
                                'pago_medio': round(abre / FP * RPG), 'modo': 'preço próprio, sem seguir a King Spa'}
                    else:
                        # o plano nunca abre acima do pedido de hoje: subir é só pela escada, depois de vender
                        if atual_pub:
                            ab = min(ab, atual_pub)
                        esc = escada(ab, tt or ab, UNID[q])
                        prop = {'abertura': tres(ab), 'ultima': tres(ab * (1 - DESCIDA_ULTIMA)), 'teto': tres(max(tt or ab, ab)),
                                'pago_medio': round(st.mean(e / FP * RPG for e in esc)),
                                'modo': 'escada depois da metade vendida; desce 10% a 7 dias se abaixo de 60%'}
                    prop['var_vs_atual'] = round(prop['abertura']['publico'] / atual_pub - 1, 4) if atual_pub else None
                    linha['proposta'] = prop
                h = pago_q.get((q, TIPO_GRUPO[s]))
                if h and TIPO_GRUPO[s] in ('fds', 'semana', 'feriado'):
                    linha['historico'] = {'ocup': h['ocup'], 'pago_p25': h['p25'], 'pago_p50': h['p50'], 'pago_p75': h['p75'], 'n': h['n']}
                if rg and atual_pub:
                    # posição do preço público atual na régua (interpolada entre os percentis medidos)
                    for serie in ('lista', 'hidro'):
                        pts = sorted((v, int(k)) for k, v in rg[serie].items() if v)
                        pos = None
                        if pts:
                            if atual_pub <= pts[0][0]:
                                pos = f'≤p{pts[0][1]}'
                            elif atual_pub >= pts[-1][0]:
                                pos = f'≥p{pts[-1][1]}'
                            else:
                                for (v0, k0), (v1, k1) in zip(pts, pts[1:]):
                                    if v0 <= atual_pub <= v1:
                                        pos = f'p{round(k0 + (k1 - k0) * (atual_pub - v0) / (v1 - v0))}' if v1 > v0 else f'p{k0}'
                                        break
                        linha['atual'][f'pos_{serie}'] = pos
                linhas.append(linha)

    # ---------- expectativa por mês e pousada ----------
    real = {(x['pousada'], x['grupo']): x for x in dp['realizado']}  # último período vence; refaz com a média abaixo
    hist = defaultdict(lambda: {'cap': 0, 'vend': 0, 'rec': 0.0})
    for x in dp['realizado']:
        h = hist[(x['pousada'], x['grupo'])]
        h['cap'] += x['cap']
        h['vend'] += x['vendidas']
        h['rec'] += x['receita']
    exp = []
    for lm in dp['livro_meta']:
        m, p = lm['mes'], lm['pousada']
        temp = lm['temporada']
        cen = {}
        for nome in ('sem_mudar', 'provavel', 'meta'):
            tot = lm['livro']
            det = {}
            for g, f in lm['futuro'].items():
                ls = [x for x in linhas if x['mes'] == m and x['pousada'] == p and x['grupo'] == g]
                if nome == 'sem_mudar':
                    # "no ritmo de setembro a novembro": ocupação e diária realizadas de 01/09 a 09/11 por pousada e tipo;
                    # festas: ocupação dos feriados do período e a diária das festas já vendidas
                    hg = hist.get((p, g if g != 'festas' else 'feriado'))
                    oc = hg['vend'] / hg['cap'] if hg and hg['cap'] else 0.5
                    adr = hg['rec'] / hg['vend'] if hg and hg['vend'] else 500
                    if g == 'festas' and f['vendidas']:
                        adr = f['receita_vendida'] / f['vendidas']
                else:
                    oc = OCUP[nome][g][temp]
                    num = sum(x['proposta']['pago_medio'] * x['livres'] for x in ls if x.get('proposta'))
                    den = sum(x['livres'] for x in ls if x.get('proposta'))
                    adr = num / den if den else 0
                alvo_un = oc * f['cap']
                novas = max(0.0, alvo_un - f['vendidas'])
                novas = min(novas, f['livres'])
                rec = novas * adr
                tot += rec
                det[g] = {'ocup_final': round(max(oc, f['ocup']), 4), 'novas_unidades': round(novas), 'pago_medio': round(adr),
                          'receita_nova': round(rec)}
            cen[nome] = {'receita': round(tot), 'vs_meta': round(tot / lm['meta_dono'], 4), 'por_grupo': det,
                         'ocup_mes': round((lm['unidades_noite_vendidas'] + sum(d['novas_unidades'] for d in det.values())) / lm['cap_mes'], 4),
                         'revpar': round(tot / lm['cap_mes'])}
        ml = mensal_pl[p].get(m, {})
        exp.append({'mes': m, 'pousada': p, 'temporada': temp, 'meta_dono': lm['meta_dono'], 'livro': lm['livro'],
                    'cap_mes': lm['cap_mes'], 'revpar_meta': lm['revpar_meta'], 'cenarios': cen,
                    'mercado_pl': {'occ_ly_final': (ml.get('agregado') or {}).get('occ_mercado_ly_final'),
                                   'adr_ly': ((ml.get('agregado') or {}).get('ano_passado') or {}).get('adr'),
                                   'revpar_ly': ((ml.get('agregado') or {}).get('ano_passado') or {}).get('revpar_adr_x_occ'),
                                   'occ_agora': (ml.get('agregado') or {}).get('occ_mercado')}})

    # ---------- fins de semana e feriados à frente, um por um ----------
    est_m = {e['checkin']: e for e in dm['estadias']}
    fds = []
    d = HOJE
    while d <= FIM:
        if d.weekday() == 4:
            s = d + timedelta(days=1)
            if s > FIM:
                break
            item = {'sexta': d.isoformat(), 'sub': tipo(d), 'D': (d - HOJE).days, 'quartos': {}, 'pousadas': {}}
            em = est_m.get(d.isoformat()) or {}
            for q in QUARTOS:
                a = next(n for n in noi if n['q'] == q and n['d'] == d.isoformat())
                b = next(n for n in noi if n['q'] == q and n['d'] == s.isoformat())
                fq = (em.get('fator_medido') or {}).get(POUSADA[q]) or fpub(POUSADA[q], mes(d), tipo(d))
                env_ = (a['enviado'] + b['enviado']) / 2
                item['quartos'][q] = {'sex': a['vendidas'], 'sab': b['vendidas'], 'un': UNID[q],
                                      'enviado': round(env_), 'publico': round(env_ * fq)}
            for p in ('Recanto', 'Villa'):
                qs = [q for q in QUARTOS if POUSADA[q] == p]
                item['pousadas'][p] = {'ocup': round(sum(item['quartos'][q]['sex'] + item['quartos'][q]['sab'] for q in qs) / (2 * CAP[p]), 4)}
                dia = next((x for x in pl['pousadas'][p]['dias'] if x['date'] == s.isoformat()), None)
                if dia:
                    item['pousadas'][p]['mercado_pl'] = {'agora': dia['agregado']['occ_mercado'], 'ly_final': dia['agregado'].get('occ_mercado_ly_final')}
            e = est_m.get(d.isoformat())
            if e:
                item['mercado'] = {'com_preco': e['com_preco'], 'lista': {k: e['lista'][k] for k in ('25', '40', '50', '75')},
                                   'hidro': {k: e['hidro'][k] for k in ('25', '40', '50', '65', '75')},
                                   'pares_lista50': e['pares']['lista']['50'], 'pares_hidro50': e['pares']['hidro']['50'],
                                   'nossos_booking': e.get('nossos_booking')}
            fds.append(item)
        d += timedelta(days=1)

    # ---------- festas: nosso ritmo × mercado, noite a noite ----------
    festas = []
    for d in noites(date(2026, 12, 23), date(2027, 1, 2)):
        it = {'d': d.isoformat(), 'sub': tipo(d)}
        for p in ('Recanto', 'Villa'):
            ns = [n for n in noi if n['d'] == d.isoformat() and POUSADA[n['q']] == p]
            dia = next((x for x in pl['pousadas'][p]['dias'] if x['date'] == d.isoformat()), None)
            it[p] = {'ocup': round(sum(n['vendidas'] for n in ns) / sum(n['un'] for n in ns), 4),
                     'mercado_agora': dia['agregado']['occ_mercado'] if dia else None,
                     'mercado_ly_final': dia['agregado'].get('occ_mercado_ly_final') if dia else None}
        festas.append(it)

    fatores_medidos = {f'{p}|{m}|{s}': {'n': len(v), 'mediana': round(st.median(v), 4), 'min': round(min(v), 4), 'max': round(max(v), 4)}
                       for (p, m, s), v in sorted(fat_obs.items()) if m != '*'}
    out = {'r_pago': R_PAGO, 'passo_escada': PASSO_ESCADA, 'descida_ultima': DESCIDA_ULTIMA, 'festas_ritmo': festas,
           'fatores_medidos': fatores_medidos, 'fator_padrao': F_PUB,
           'piso_semana': PISO_SEMANA, 'parte_antecipada': PARTE_ANTECIPADA, 'ocup_alvo': OCUP, 'regua': regua, 'linhas': linhas, 'expectativa': exp,
           'fds_futuros': fds, 'occ_pl': occ_pl,
           'mercado_mensal_pl': {p: {m: {'occ_agora': (mensal_pl[p].get(m, {}).get('agregado') or {}).get('occ_mercado'),
                                         'occ_ly_final': (mensal_pl[p].get(m, {}).get('agregado') or {}).get('occ_mercado_ly_final'),
                                         'adr_ly': ((mensal_pl[p].get(m, {}).get('agregado') or {}).get('ano_passado') or {}).get('adr')}
                                     for m in MESES} for p in ('Recanto', 'Villa')}}
    json.dump(out, open(A / 'dados_plano.json', 'w'), ensure_ascii=False, indent=1)

    # ---------- resumo ----------
    print('RÉGUA por mês e tipo (público por noite): lista p25/p40/p50/p75 | hidro p40/p50/p65 | pares lista p50 / hidro p50 | nota 9+ lista p50')
    for k in sorted(regua):
        r = regua[k]
        print(f"  {k:22} ({r['n_estadias']} est.) lista {r['lista']['25']}/{r['lista']['40']}/{r['lista']['50']}/{r['lista']['75']} | hidro {r['hidro']['40']}/{r['hidro']['50']}/{r['hidro']['65']}"
              f" | pares {r['pares_lista']['50']}/{r['pares_hidro']['50']} | 9+ {r['nota9_lista']['50']} | nossos na Booking {r['nossos_booking']}")
    print('\nPOSIÇÃO E PROPOSTA (preço público; atual → abertura proposta; pago médio)')
    for x in linhas:
        if x['mes'] in ('2026-11',) and x['sub'] in ('fds', 'semana'):
            pr_ = x.get('proposta') or {}
            ab_, ul_, tt_ = pr_.get('abertura') or {}, pr_.get('ultima') or {}, pr_.get('teto') or {}
            print(f"  {x['mes']} {x['sub']:7} {NOME[x['q']]:14} ocup {x['ocup']:>4.0%} livres {x['livres']:>3} | atual env {x['atual']['enviado']} púb {x['atual']['publico']} ({x['atual'].get('pos_lista')} lista, {x['atual'].get('pos_hidro')} hidro) pago~{x['atual']['pago']}"
                  f" → abertura env {ab_.get('enviado')} púb {ab_.get('publico')} G3 {ab_.get('g3')} pago~{ab_.get('pago')} ({(pr_.get('var_vs_atual') or 0):+.0%}) | última semana env {ul_.get('enviado')} pago~{ul_.get('pago')} | teto env {tt_.get('enviado')} | pago médio {pr_.get('pago_medio')}; hist. p50 {(x.get('historico') or {}).get('pago_p50')}")
    print('\nEXPECTATIVA (receita do mês; livro + vendas novas)')
    for e in exp:
        c = e['cenarios']
        print(f"  {e['mes']} {e['pousada']:8} meta {reais(e['meta_dono'])} | livro {reais(e['livro'])} | sem mudar {reais(c['sem_mudar']['receita'])} ({c['sem_mudar']['vs_meta']:.0%}, ocup {c['sem_mudar']['ocup_mes']:.0%})"
              f" | provável {reais(c['provavel']['receita'])} ({c['provavel']['vs_meta']:.0%}, ocup {c['provavel']['ocup_mes']:.0%}) | meta de lotação {reais(c['meta']['receita'])} ({c['meta']['vs_meta']:.0%}, ocup {c['meta']['ocup_mes']:.0%})"
              f" | mercado LY: ocup {e['mercado_pl']['occ_ly_final']}% ADR {e['mercado_pl']['adr_ly']}")


if __name__ == '__main__':
    main()

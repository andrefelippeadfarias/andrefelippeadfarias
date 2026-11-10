"""Monta a página 'Plano de preços nov-fev' a partir de dados_proprios.json, dados_mercado.json e dados_plano.json.
Os números saem dos três arquivos; as frases usam valores calculados aqui."""
from __future__ import annotations

import html
import json
from datetime import date
from pathlib import Path

A = Path('/tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/an1110')
DP = json.load(open(A / 'dados_proprios.json'))
DM = json.load(open(A / 'dados_mercado.json'))
PL = json.load(open(A / 'dados_plano.json'))
e = html.escape
NOME = DP['nome']
POUSADA = DP['pousada']
UNID = DP['unid']
QUARTOS = ['Q7', 'Q2', 'Double', 'Afrodite', 'VK7', 'VK2', 'Balcony']
DIAS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom']
MES_NOME = {'2026-11': 'Novembro (de 10 a 30)', '2026-12': 'Dezembro', '2027-01': 'Janeiro', '2027-02': 'Fevereiro'}
MES_CURTO = {'2026-11': 'nov', '2026-12': 'dez', '2027-01': 'jan', '2027-02': 'fev'}
SUB_NOME = {'semana': 'Dia de semana (dom a qui)', 'fds': 'Fim de semana (sex e sáb)', 'feriado': 'Feriado',
            'natal': 'Natal (23 a 26/12)', 'entre_festas': 'Entre festas (27 a 29/12)', 'reveillon': 'Réveillon (30/12 a 02/01)'}
GRUPO_NOME = {'semana': 'Dia de semana', 'fds': 'Fim de semana', 'feriado': 'Feriado', 'festas': 'Natal e Réveillon'}
FERIADO_NOME = {'2026-11': 'Consciência Negra (20 e 21/11)', '2027-02': 'Carnaval (5 a 8/02)'}


def r(v):
    if v is None:
        return '—'
    return 'R$ ' + f'{v:,.0f}'.replace(',', '.')


def n(v):
    return '—' if v is None else f'{v:,.0f}'.replace(',', '.')


def p(v, casas=0):
    return '—' if v is None else f'{v * 100:.{casas}f}%'.replace('.', ',')


def dm(iso):
    d = date.fromisoformat(iso)
    return f'{d.day:02d}/{d.month:02d}'


def chip_var(v):
    if v is None:
        return ''
    if abs(v) < 0.005:
        return "<span class='chip neutro'>igual</span>"
    s = f'{v * 100:+.0f}%'.replace('-', '−')
    return f"<span class='chip {'sobe' if v > 0 else 'desce'}'>{s}</span>"


def ocup_cls(v, bom=0.7, medio=0.45):
    return 'ok' if v >= bom else ('meio' if v >= medio else 'baixo')


def tabela(cab, linhas, classe=''):
    th = ''.join(f"<th class='{c}'>{t}</th>" for t, c in cab)
    return f"<div class='rolagem'><table class='{classe}'><thead><tr>{th}</tr></thead><tbody>{''.join(linhas)}</tbody></table></div>"


# ------------------------------------------------------------------ números de abertura
real = DP['realizado']


def soma_real(pousada, grupo):
    xs = [x for x in real if x['pousada'] == pousada and x['grupo'] == grupo]
    cap = sum(x['cap'] for x in xs)
    vend = sum(x['vendidas'] for x in xs)
    rec = sum(x['receita'] for x in xs)
    return {'ocup': vend / cap if cap else None, 'adr': rec / vend if vend else None, 'vend': vend, 'cap': cap}


FDS_R, FDS_V = soma_real('Recanto', 'fds'), soma_real('Villa', 'fds')
SEM_R, SEM_V = soma_real('Recanto', 'semana'), soma_real('Villa', 'semana')
pago_p = {(x['pousada'], x['grupo']): x for x in DP['pago']['por_pousada']}
pago_q = {(x['quarto'], x['grupo']): x for x in DP['pago']['por_quarto']}
ante = {(x['pousada'], x['grupo']): x for x in DP['pago']['antecedencia']}
linhas_pl = PL['linhas']


def pedido_pago(pousada, mes, sub):
    xs = [x for x in linhas_pl if x['pousada'] == pousada and x['mes'] == mes and x['sub'] == sub and x['atual']['pago']]
    lv = sum(x['livres'] for x in xs)
    return sum(x['atual']['pago'] * x['livres'] for x in xs) / lv if lv else None


RAZAO = {pp: (pedido_pago(pp, '2026-11', 'fds') or 0) / pago_p[(pp, 'fds')]['p50'] for pp in ('Recanto', 'Villa')}
EST = {x['checkin']: x for x in DM['estadias']}
este = EST.get('2026-11-13')
ULT7 = {pp: ante[(pp, 'fds')]['D0-3'] + ante[(pp, 'fds')]['D4-7'] for pp in ('Recanto', 'Villa')}

# ------------------------------------------------------------------ 2. onde estamos
TAG_FERIADO_0 = " <span class='tag'>feriado</span>"
lm_linhas = []
for x in DP['livro_meta']:
    lm_linhas.append(
        f"<tr><td>{MES_NOME[x['mes']].split(' (')[0]}</td><td>{x['pousada']}</td><td class='num'>{r(x['meta_dono'])}</td>"
        f"<td class='num forte'>{r(x['livro'])}</td><td class='num'>{p(x['livro'] / x['meta_dono'])}</td>"
        f"<td class='num'>{p(x['ocup_livro_mes'])}</td><td class='num'>{r(x['adr_livro'])}</td>"
        f"<td class='num'>{n(x['livres_futuro'])}</td><td class='num'>{r(x['falta'] / x['livres_futuro'] if x['livres_futuro'] else None)}</td></tr>")
TAB_LIVRO = tabela([('Mês', ''), ('Pousada', ''), ('Meta mínima', 'num'), ('Já vendido', 'num'), ('% da meta', 'num'),
                    ('Ocupação já vendida', 'num'), ('Diária média vendida', 'num'), ('Diárias livres', 'num'),
                    ('Falta por diária livre', 'num')], lm_linhas)

fds_linhas = []
for f in PL['fds_futuros']:
    cel = ''
    for q in QUARTOS:
        c = f['quartos'][q]
        cheio = c['sex'] >= c['un'] and c['sab'] >= c['un']
        zero = c['sex'] == 0 and c['sab'] == 0
        cel += f"<td class='num {'cheio' if cheio else ('zero' if zero else '')}'>{c['sex']}·{c['sab']}<span class='de'>/{c['un']}</span></td>"
    oc = f['pousadas']
    mk = (oc['Recanto'].get('mercado_pl') or {})
    rot = {'fds': '', 'feriado': " <span class='tag'>feriado</span>", 'natal': " <span class='tag'>Natal</span>",
           'reveillon': " <span class='tag'>Réveillon</span>", 'entre_festas': ''}.get(f['sub'], '')
    fds_linhas.append(
        f"<tr><td class='num'>{dm(f['sexta'])}{rot}</td><td class='num'>{f['D']}</td>"
        f"<td class='num'><span class='pct {ocup_cls(oc['Recanto']['ocup'])}'>{p(oc['Recanto']['ocup'])}</span></td>"
        f"<td class='num'><span class='pct {ocup_cls(oc['Villa']['ocup'])}'>{p(oc['Villa']['ocup'])}</span></td>{cel}"
        f"<td class='num'>{n(mk.get('agora'))}%</td><td class='num'>{n(mk.get('ly_final'))}%</td></tr>")
TAB_FDS = tabela([('Sexta', 'num'), ('Dias até lá', 'num'), ('Recanto', 'num'), ('Villa', 'num')] +
                 [(NOME[q], 'num') for q in QUARTOS] + [('Mercado hoje', 'num'), ('Mercado no fim (ano passado)', 'num')], fds_linhas)

# ------------------------------------------------------------------ 2b. desde 01/11
DESDE = DP['desde_0111']
ds_linhas = []
for f in DESDE:
    hoje_b = (EST.get(f['sexta']) or {}).get('nossos_booking') or {}
    cel = ''
    for q in QUARTOS:
        c = f['quartos'][q]
        dif = c['hoje'] - c['antes']
        cls = 'cheio' if dif > 0 else ('zero' if dif < 0 else '')
        cel += f"<td class='num {cls}'>{c['antes']}→{c['hoje']}<span class='de'>/{c['cap']}</span></td>"
    R_, V_ = f['pousadas']['Recanto'], f['pousadas']['Villa']
    ds_linhas.append(
        f"<tr><td class='num'>{dm(f['sexta'])}{TAG_FERIADO_0 if f['tipo'] == 'feriado' else ''}</td>"
        f"<td class='num'>{p(R_['antes'])} → <b>{p(R_['hoje'])}</b></td><td class='num'>{p(V_['antes'])} → <b>{p(V_['hoje'])}</b></td>"
        f"<td class='num'>{r(f['booking_0111']['Recanto'])} → <b>{r(hoje_b.get('Recanto'))}</b></td>"
        f"<td class='num'>{r(f['booking_0111']['Villa'])} → <b>{r(hoje_b.get('Villa'))}</b></td>{cel}</tr>")
TAB_DESDE = tabela([('Sexta', 'num'), ('Recanto: ocupação 01/11 → hoje', 'num'), ('Villa: ocupação 01/11 → hoje', 'num'),
                    ('Recanto na Booking: 01/11 → hoje', 'num'), ('Villa na Booking: 01/11 → hoje', 'num')] +
                   [(NOME[q], 'num') for q in QUARTOS], ds_linhas)
SEM_VENDA = [NOME[q] for q in QUARTOS if all(f['quartos'][q]['hoje'] <= f['quartos'][q]['antes'] for f in DESDE if f['tipo'] == 'fds')]

# ------------------------------------------------------------------ 3. mercado
reg_linhas = []
ORD_SUB = ['semana', 'fds', 'feriado', 'natal', 'entre_festas', 'reveillon']
for m in MES_NOME:
    for s in ORD_SUB:
        k = f'{m}|{s}'
        if k not in PL['regua']:
            continue
        g = PL['regua'][k]
        nb = g.get('nossos_booking') or {}
        rot = SUB_NOME[s] if s != 'feriado' else FERIADO_NOME.get(m, 'Feriado')
        reg_linhas.append(
            f"<tr><td>{MES_CURTO[m]}</td><td>{rot}</td><td class='num'>{n(g['com_preco'])}/77</td>"
            f"<td class='num'>{r(g['lista']['25'])}</td><td class='num forte'>{r(g['lista']['50'])}</td><td class='num'>{r(g['lista']['75'])}</td>"
            f"<td class='num'>{r(g['hidro']['40'])}</td><td class='num forte'>{r(g['hidro']['50'])}</td><td class='num'>{r(g['hidro']['65'])}</td>"
            f"<td class='num'>{r(g['pares_lista']['50'])}</td><td class='num'>{r(g['pares_hidro']['50'])}</td>"
            f"<td class='num'>{r(g['nota9_lista']['50'])}</td>"
            f"<td class='num dest'>{r(nb.get('Recanto'))}</td><td class='num dest'>{r(nb.get('Villa'))}</td></tr>")
TAB_REGUA = tabela([('Mês', ''), ('Tipo de noite', ''), ('Com vaga', 'num'), ('Mais barato: 25%', 'num'), ('Mais barato: mediana', 'num'),
                    ('Mais barato: 75%', 'num'), ('Com hidro: 40%', 'num'), ('Com hidro: mediana', 'num'), ('Com hidro: 65%', 'num'),
                    ('Nota abaixo de 9: mais barato', 'num'), ('Nota abaixo de 9: com hidro', 'num'), ('Nota 9 ou mais: mais barato', 'num'),
                    ('Recanto na Booking', 'num'), ('Villa na Booking', 'num')], reg_linhas)

# ------------------------------------------------------------------ 4. o que o hóspede paga
pq_linhas = []
for q in QUARTOS:
    f_, s_ = pago_q[(q, 'fds')], pago_q[(q, 'semana')]
    af = next((x for x in linhas_pl if x['q'] == q and x['mes'] == '2026-11' and x['sub'] == 'fds'), None)
    as_ = next((x for x in linhas_pl if x['q'] == q and x['mes'] == '2026-11' and x['sub'] == 'semana'), None)
    pq_linhas.append(
        f"<tr><td>{NOME[q]}</td><td class='num'>{UNID[q]}</td>"
        f"<td class='num'><span class='pct {ocup_cls(f_['ocup'])}'>{p(f_['ocup'])}</span></td><td class='num'>{r(f_['p25'])}</td><td class='num forte'>{r(f_['p50'])}</td><td class='num'>{r(f_['p75'])}</td>"
        f"<td class='num dest'>{r(af['atual']['pago'] if af else None)}</td>"
        f"<td class='num'><span class='pct {ocup_cls(s_['ocup'], 0.6, 0.4)}'>{p(s_['ocup'])}</span></td><td class='num'>{r(s_['p25'])}</td><td class='num forte'>{r(s_['p50'])}</td><td class='num'>{r(s_['p75'])}</td>"
        f"<td class='num dest'>{r(as_['atual']['pago'] if as_ else None)}</td></tr>")
TAB_PAGO = tabela([('Suíte', ''), ('Unid.', 'num'), ('Fim de semana: ocupação', 'num'), ('pagou 25%', 'num'), ('pagou mediana', 'num'), ('pagou 75%', 'num'),
                   ('pedido hoje', 'num'), ('Dia de semana: ocupação', 'num'), ('pagou 25%', 'num'), ('pagou mediana', 'num'), ('pagou 75%', 'num'),
                   ('pedido hoje', 'num')], pq_linhas)

TAG_FERIADO = " <span class='tag'>feriado</span>"
fp_linhas = []
for f in DP['fds_passados']:
    R_, V_ = f['pousadas']['Recanto'], f['pousadas']['Villa']
    cel = ''.join(f"<td class='num'>{f['quartos'][q]['vendidas']}<span class='de'>/{f['quartos'][q]['cap']}</span></td>" for q in QUARTOS)
    fp_linhas.append(
        f"<tr><td class='num'>{dm(f['sexta'])}{TAG_FERIADO if f['tipo'] == 'feriado' else ''}</td>"
        f"<td class='num'><span class='pct {ocup_cls(R_['ocup'])}'>{p(R_['ocup'])}</span></td><td class='num'>{r(R_['pago_p50'])}</td>"
        f"<td class='num'><span class='pct {ocup_cls(V_['ocup'])}'>{p(V_['ocup'])}</span></td><td class='num'>{r(V_['pago_p50'])}</td>{cel}</tr>")
TAB_FP = tabela([('Sexta', 'num'), ('Recanto', 'num'), ('pago (mediana)', 'num'), ('Villa', 'num'), ('pago (mediana)', 'num')] +
                [(NOME[q], 'num') for q in QUARTOS], fp_linhas)

# ------------------------------------------------------------------ gráfico: fim de semana de novembro, por suíte
RG = PL['regua'].get('2026-11|fds')


def grafico():
    if not RG:
        return ''
    W, ESQ, DIR, TOPO, ALT = 860, 128, 26, 30, 46
    xmax = 2200
    larg = W - ESQ - DIR
    X = lambda v: ESQ + larg * min(v, xmax) / xmax  # noqa: E731
    H = TOPO + ALT * len(QUARTOS) + 34
    s = [f"<svg viewBox='0 0 {W} {H}' role='img' aria-label='Preço público por noite no fim de semana de novembro: pedido hoje e abertura proposta, por suíte' class='viz'>"]
    # faixa do mercado (quarto mais barato dos concorrentes, 25% a 75%) e mediana com hidro
    x0, x1 = X(RG['lista']['25']), X(RG['lista']['75'])
    s.append(f"<rect x='{x0:.1f}' y='{TOPO - 6}' width='{x1 - x0:.1f}' height='{ALT * len(QUARTOS) + 6}' class='faixa'><title>Concorrentes: quarto mais barato, de {r(RG['lista']['25'])} a {r(RG['lista']['75'])}</title></rect>")
    for v in range(0, xmax + 1, 500):
        s.append(f"<line x1='{X(v):.1f}' y1='{TOPO - 6}' x2='{X(v):.1f}' y2='{TOPO + ALT * len(QUARTOS)}' class='grade'/>")
        s.append(f"<text x='{X(v):.1f}' y='{TOPO + ALT * len(QUARTOS) + 18}' class='eixo' text-anchor='middle'>{n(v)}</text>")
    xh = X(RG['hidro']['50'])
    s.append(f"<line x1='{xh:.1f}' y1='{TOPO - 6}' x2='{xh:.1f}' y2='{TOPO + ALT * len(QUARTOS)}' class='ref'><title>Concorrentes: mediana do quarto com hidro, {r(RG['hidro']['50'])}</title></line>")
    s.append(f"<text x='{xh + 5:.1f}' y='{TOPO - 12}' class='eixo'>mediana com hidro {r(RG['hidro']['50'])}</text>")
    for i, q in enumerate(QUARTOS):
        y = TOPO + ALT * i + ALT / 2
        ln = next((x for x in linhas_pl if x['q'] == q and x['mes'] == '2026-11' and x['sub'] == 'fds'), None)
        if not ln or not ln.get('proposta'):
            continue
        hoje, prop = ln['atual']['publico'], ln['proposta']['abertura']['publico']
        s.append(f"<text x='{ESQ - 12}' y='{y + 4:.1f}' class='rot' text-anchor='end'>{e(NOME[q])}</text>")
        if hoje:
            s.append(f"<line x1='{X(prop):.1f}' y1='{y:.1f}' x2='{X(hoje):.1f}' y2='{y:.1f}' class='liga'/>")
            s.append(f"<circle cx='{X(hoje):.1f}' cy='{y:.1f}' r='7' class='hoje' tabindex='0' data-dica='{e(NOME[q])}: pedido hoje {r(hoje)} por noite (preço público)'/>")
        s.append(f"<circle cx='{X(prop):.1f}' cy='{y:.1f}' r='7' class='prop' tabindex='0' data-dica='{e(NOME[q])}: abertura proposta {r(prop)} por noite (preço público)'/>")
        s.append(f"<text x='{X(prop) - 12:.1f}' y='{y + 4:.1f}' class='val' text-anchor='end'>{n(prop)}</text>")
        if hoje:
            s.append(f"<text x='{X(hoje) + 12:.1f}' y='{y + 4:.1f}' class='val'>{n(hoje)}</text>")
    s.append('</svg>')
    leg = (f"<div class='legenda'><span><i class='b prop'></i>Abertura proposta</span><span><i class='b hoje'></i>Pedido hoje</span>"
           "<span><i class='b faixa'></i>Quarto mais barato dos concorrentes, 25% a 75% ({r(RG['lista']['25'])} a {r(RG['lista']['75'])})</span><span><i class='b ref'></i>Mediana do quarto com hidro</span></div>")
    return f"<figure class='grafico'><figcaption>Fim de semana de novembro, preço público por noite (R$): onde cada suíte está e onde o plano a coloca</figcaption>{leg}<div class='rolagem semborda'>{''.join(s)}</div><div id='dica' class='dica' hidden></div></figure>"


# ------------------------------------------------------------------ 5. plano por mês
def tabela_mes(m):
    out = []
    for s in ORD_SUB:
        ls = [x for x in linhas_pl if x['mes'] == m and x['sub'] == s]
        if not ls:
            continue
        rot = SUB_NOME[s] if s != 'feriado' else FERIADO_NOME.get(m, 'Feriado')
        g = PL['regua'].get(f'{m}|{s}')
        ref = (f"concorrentes: mais barato {r(g['lista']['50'])}, com hidro {r(g['hidro']['50'])} (medianas)" if g else 'sem estadia medida neste mês: média dos outros meses')
        out.append(f"<tr class='grupo'><td colspan='11'>{rot} <span class='sub'>{ls[0]['noites']} noites · {ref}</span></td></tr>")
        for q in QUARTOS:
            x = next((y for y in ls if y['q'] == q), None)
            if not x:
                continue
            a, pr_ = x['atual'], x.get('proposta')
            if not pr_:
                continue
            ab, ul, tt = pr_['abertura'], pr_['ultima'], pr_['teto']
            ult = r(ul['enviado']) if ul['enviado'] != ab['enviado'] else 'igual'
            out.append(
                f"<tr><td>{NOME[q]}</td><td class='num'><span class='pct {ocup_cls(x['ocup'])}'>{p(x['ocup'])}</span> <span class='de'>{x['vendidas']}/{x['cap']}</span></td>"
                f"<td class='num'>{r(a['enviado'])}</td><td class='num'>{r(a['publico'])}</td>"
                f"<td class='num forte dest'>{r(ab['enviado'])}</td><td class='num dest'>{r(ab['publico'])}</td><td class='num dest'>{r(ab['g3'])}</td><td class='num dest'>{r(ab['pago'])}</td>"
                f"<td class='num'>{chip_var(pr_.get('var_vs_atual'))}</td><td class='num'>{ult}</td><td class='num'>{r(tt['enviado'])}</td></tr>")
    return tabela([('Suíte', ''), ('Já vendido', 'num'), ('Hoje: enviado', 'num'), ('Hoje: público', 'num'), ('Proposto: enviado', 'num'),
                   ('público', 'num'), ('Genius 3', 'num'), ('hóspede paga ~', 'num'), ('Mudança', 'num'), ('Última semana (enviado)', 'num'),
                   ('Teto da escada (enviado)', 'num')], out, 'plano')


# ------------------------------------------------------------------ 6. expectativa
exp_linhas = []
for x in PL['expectativa']:
    c = x['cenarios']
    mk = x['mercado_pl']

    def cel(k):
        v = c[k]
        cls = 'ok' if v['vs_meta'] >= 1 else ('meio' if v['vs_meta'] >= 0.85 else 'baixo')
        return f"<td class='num'>{r(v['receita'])}</td><td class='num'><span class='pct {cls}'>{p(v['vs_meta'])}</span></td><td class='num'>{p(v['ocup_mes'])}</td>"
    exp_linhas.append(
        f"<tr><td>{MES_NOME[x['mes']].split(' (')[0]}</td><td>{x['pousada']}</td><td class='num'>{r(x['meta_dono'])}</td><td class='num'>{r(x['livro'])}</td>"
        f"{cel('sem_mudar')}{cel('provavel')}{cel('meta')}<td class='num'>{n(mk.get('occ_ly_final'))}%</td><td class='num'>{r(mk.get('adr_ly'))}</td></tr>")
TAB_EXP = tabela([('Mês', ''), ('Pousada', ''), ('Meta mínima', 'num'), ('Já vendido', 'num'),
                  ('No ritmo de set a nov', 'num'), ('% da meta', 'num'), ('ocupação', 'num'),
                  ('Plano: alvo', 'num'), ('% da meta', 'num'), ('ocupação', 'num'),
                  ('Plano: lotação ideal', 'num'), ('% da meta', 'num'), ('ocupação', 'num'),
                  ('Mercado: ocupação final (ano passado)', 'num'), ('Mercado: diária (ano passado)', 'num')], exp_linhas)

ideal_linhas = []
for x in PL['expectativa']:
    for g, d in x['cenarios']['meta']['por_grupo'].items():
        pv = x['cenarios']['provavel']['por_grupo'][g]
        ideal_linhas.append(
            f"<tr><td>{MES_NOME[x['mes']].split(' (')[0]}</td><td>{x['pousada']}</td><td>{GRUPO_NOME[g]}</td>"
            f"<td class='num'>{p(pv['ocup_final'])}</td><td class='num forte'>{p(d['ocup_final'])}</td><td class='num forte'>{r(d['pago_medio'])}</td>"
            f"<td class='num'>{n(d['novas_unidades'])}</td><td class='num'>{r(d['receita_nova'])}</td></tr>")
TAB_IDEAL = tabela([('Mês', ''), ('Pousada', ''), ('Tipo de noite', ''), ('Ocupação alvo', 'num'), ('Lotação ideal', 'num'),
                    ('Diária média que o hóspede paga', 'num'), ('Diárias ainda a vender', 'num'), ('Receita dessas diárias', 'num')], ideal_linhas)

# ------------------------------------------------------------------ textos com números
def linha_q(q, mes, sub):
    return next((x for x in linhas_pl if x['q'] == q and x['mes'] == mes and x['sub'] == sub), None)


def faixa_prop(q, sub, campo='enviado'):
    vs = [x['proposta']['abertura'][campo] for x in linhas_pl if x['q'] == q and x['sub'] == sub and x.get('proposta')]
    return (min(vs), max(vs)) if vs else (None, None)


def faixa_txt(q, sub, campo='enviado'):
    a, b = faixa_prop(q, sub, campo)
    return '—' if a is None else (r(a) if a == b else f"{r(a)} a {r(b)}")


NOTAS = DM['notas']
bal_f, bal_s = pago_q[('Balcony', 'fds')], pago_q[('Balcony', 'semana')]
q7_f = pago_q[('Q7', 'fds')]
vk7_f = pago_q[('VK7', 'fds')]
L = {q: linha_q(q, '2026-11', 'fds') for q in QUARTOS}
com_vaga = f"{este['com_preco']} de 77" if este else '—'
exp_tot = {k: sum(x['cenarios'][k]['receita'] for x in PL['expectativa']) for k in ('sem_mudar', 'provavel', 'meta')}
meta_tot = sum(x['meta_dono'] for x in PL['expectativa'])
jan = [x for x in PL['expectativa'] if x['mes'] == '2027-01']

# ---- frases calculadas
import math
FP = DP['fds_passados']
cheios = [f for f in FP if max(f['pousadas']['Recanto']['ocup'], f['pousadas']['Villa']['ocup']) >= 0.95]
resto = [f['pousadas'][pp]['ocup'] for f in FP if f not in cheios for pp in ('Recanto', 'Villa')]
FRASE_FDS = (f"Dos {len(FP)} últimos, " + ('nenhum lotou' if not cheios else
             ('só o de ' + ' e '.join(dm(f['sexta']) for f in cheios) + ' lotou (Recanto ' + p(cheios[0]['pousadas']['Recanto']['ocup']) + ', Villa ' + p(cheios[0]['pousadas']['Villa']['ocup']) + ')'))
             + f". Nos demais, cada pousada ficou entre {p(min(resto))} e {p(max(resto))}.")
FR = PL['festas_ritmo']
natal = [x for x in FR if x['sub'] == 'natal']
media = lambda xs: sum(xs) / len(xs)  # noqa: E731
NATAL = {pp: {'nos': media([x[pp]['ocup'] for x in natal]), 'merc': media([x[pp]['mercado_agora'] for x in natal]) / 100} for pp in ('Recanto', 'Villa')}
d31 = next(x for x in FR if x['d'] == '2026-12-31')
fest_v = sum(x['futuro']['festas']['vendidas'] for x in DP['livro_meta'] if 'festas' in x['futuro'])
fest_r = sum(x['futuro']['festas']['receita_vendida'] for x in DP['livro_meta'] if 'festas' in x['futuro'])
PAGO_FESTAS = fest_r / fest_v if fest_v else None
G3F = DP['fatores']['g3']['fds']
PISO_FDS_ENV = {pp: math.ceil(850 / G3F[pp] - 1e-9) for pp in ('Recanto', 'Villa')}
PISO_FDS_PUB = {pp: PISO_FDS_ENV[pp] * DP['fatores']['publico'][pp] for pp in ('Recanto', 'Villa')}
f0210 = next(f for f in FP if f['sexta'] == '2026-10-02')['pousadas']['Recanto']
f0611 = next(f for f in FP if f['sexta'] == '2026-11-06')['pousadas']['Recanto']
OA = PL['ocup_alvo']
hid_fds_nov = (PL['regua'].get('2026-11|fds') or {}).get('hidro', {}).get('50')
lis_fds_nov = (PL['regua'].get('2026-11|fds') or {}).get('lista', {}).get('50')
nb_este = (este or {}).get('nossos_booking') or {}
mb_este = (este or {}).get('mais_barato') or {}

CSS = """
/* Layout: uma coluna de leitura (relatório de gestão de receita); tabelas largas rolam dentro da própria caixa;
   cor só para estado (bom, atenção, baixo), mudança de preço e as duas séries do gráfico. */
:root {
  --fundo: #f4f6f3; --papel: #ffffff; --tinta: #17211c; --suave: #5b6a62; --linha: #dbe2dc;
  --pinho: #1f5a47; --pinho-claro: #e3efe9; --sobe: #9a5b0f; --sobe-fundo: #f8ecd9;
  --desce: #1d5f8a; --desce-fundo: #e2eef6; --alerta: #a3332b; --alerta-fundo: #f7e3e0;
  --meio: #8a6410; --serie-prop: #2a78d6; --serie-hoje: #eb6834; --viz-faixa: #e6ebe7; --viz-grade: #e3e8e4;
  --f-titulo: "Literata", Georgia, "Times New Roman", serif;
  --f-texto: "IBM Plex Sans", "Segoe UI", Helvetica, Arial, sans-serif;
  --f-dado: "IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace;
}
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {
  --fundo: #111814; --papel: #18211c; --tinta: #e6ece8; --suave: #9fb0a7; --linha: #2c3a33;
  --pinho: #7cc4a8; --pinho-claro: #1f3029; --sobe: #f0b464; --sobe-fundo: #3a2b17;
  --desce: #8ec5ea; --desce-fundo: #172b38; --alerta: #f09a90; --alerta-fundo: #3a1e1b;
  --meio: #e0b860; --serie-prop: #3987e5; --serie-hoje: #d95926; --viz-faixa: #24302a; --viz-grade: #26332c; color-scheme: dark } }
:root[data-theme="dark"] {
  --fundo: #111814; --papel: #18211c; --tinta: #e6ece8; --suave: #9fb0a7; --linha: #2c3a33;
  --pinho: #7cc4a8; --pinho-claro: #1f3029; --sobe: #f0b464; --sobe-fundo: #3a2b17;
  --desce: #8ec5ea; --desce-fundo: #172b38; --alerta: #f09a90; --alerta-fundo: #3a1e1b;
  --meio: #e0b860; --serie-prop: #3987e5; --serie-hoje: #d95926; --viz-faixa: #24302a; --viz-grade: #26332c; color-scheme: dark }
body { background: var(--fundo); color: var(--tinta); font-family: var(--f-texto); font-size: 15px; line-height: 1.55; }
.pagina { max-width: 1040px; margin: 0 auto; padding-block: 28px 56px;
  padding-left: max(16px, env(safe-area-inset-left, 0px)); padding-right: max(16px, env(safe-area-inset-right, 0px));
  display: grid; gap: 38px; }
header { display: grid; gap: 12px; }
.olho { font-family: var(--f-dado); font-size: 12px; letter-spacing: .06em; text-transform: uppercase; color: var(--pinho); }
h1 { font-family: var(--f-titulo); font-weight: 650; font-size: clamp(26px, 4.4vw, 38px); line-height: 1.15; margin: 0; text-wrap: balance; }
h2 { font-family: var(--f-titulo); font-weight: 650; font-size: 23px; line-height: 1.25; margin: 0; text-wrap: balance; }
h3 { font-size: 16px; margin: 0; font-weight: 600; }
p { margin: 0; max-width: 70ch; }
ul, ol { margin: 0; padding-left: 20px; display: grid; gap: 7px; max-width: 72ch; }
.lede { font-size: 17px; color: var(--suave); }
.numeros { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.numero { background: var(--papel); border: 1px solid var(--linha); border-radius: 8px; padding: 12px 14px; display: grid; gap: 2px; align-content: start; }
.numero b { font-family: var(--f-dado); font-size: 22px; font-weight: 500; font-variant-numeric: tabular-nums; }
.numero span { font-size: 13px; color: var(--suave); }
section { display: grid; gap: 14px; min-width: 0; }
.caixa { background: var(--papel); border: 1px solid var(--linha); border-radius: 10px; padding: 18px; display: grid; gap: 12px; min-width: 0; }
.rolagem { overflow-x: auto; border: 1px solid var(--linha); border-radius: 8px; background: var(--papel); }
.rolagem.semborda { border: 0; background: transparent; }
table { border-collapse: collapse; width: 100%; font-size: 13.5px; }
th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--linha); white-space: nowrap; }
th { font-size: 12px; font-weight: 600; color: var(--suave); background: var(--fundo); white-space: normal; vertical-align: bottom; min-width: 64px; }
tbody tr:last-child td { border-bottom: 0; }
td.num, th.num { font-family: var(--f-dado); font-variant-numeric: tabular-nums; text-align: right; }
td.forte { font-weight: 600; }
td.dest { background: var(--pinho-claro); }
td.cheio { color: var(--pinho); font-weight: 600; }
td.zero { color: var(--alerta); }
tr.grupo td { background: var(--fundo); font-weight: 600; font-family: var(--f-texto); white-space: normal; }
tr.grupo .sub { font-weight: 400; color: var(--suave); font-size: 12.5px; margin-left: 8px; }
.de { color: var(--suave); font-size: 12px; }
.tag { font-family: var(--f-texto); font-size: 11px; color: var(--suave); border: 1px solid var(--linha); border-radius: 999px; padding: 0 6px; margin-left: 4px; }
.chip { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 12px; }
.chip.sobe { color: var(--sobe); background: var(--sobe-fundo); }
.chip.desce { color: var(--desce); background: var(--desce-fundo); }
.chip.neutro { color: var(--suave); background: var(--fundo); }
.pct.ok { color: var(--pinho); font-weight: 600; }
.pct.meio { color: var(--meio); font-weight: 600; }
.pct.baixo { color: var(--alerta); font-weight: 600; }
.nota { font-size: 13px; color: var(--suave); max-width: 78ch; }
.aviso { background: var(--alerta-fundo); border-left: 3px solid var(--alerta); border-radius: 6px; padding: 12px 14px; display: grid; gap: 6px; }
.decisao { background: var(--papel); border: 1px solid var(--linha); border-radius: 10px; padding: 14px 16px; display: grid; gap: 6px; min-width: 0; }
.decisao .n { font-family: var(--f-dado); font-size: 12px; color: var(--pinho); letter-spacing: .05em; text-transform: uppercase; }
.decisoes { display: grid; gap: 10px; }
.duas { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
.grafico { margin: 0; display: grid; gap: 8px; background: var(--papel); border: 1px solid var(--linha); border-radius: 10px; padding: 16px; position: relative; min-width: 0; }
.grafico figcaption { font-weight: 600; }
.viz { width: 100%; min-width: 620px; height: auto; display: block; }
.viz .faixa { fill: var(--viz-faixa); }
.viz .grade { stroke: var(--viz-grade); stroke-width: 1; }
.viz .ref { stroke: var(--suave); stroke-width: 1.5; }
.viz .eixo { fill: var(--suave); font-family: var(--f-dado); font-size: 11px; }
.viz .rot { fill: var(--tinta); font-family: var(--f-texto); font-size: 13px; }
.viz .val { fill: var(--tinta); font-family: var(--f-dado); font-size: 12px; }
.viz .liga { stroke: var(--suave); stroke-width: 2; stroke-linecap: round; opacity: .55; }
.viz .hoje { fill: var(--serie-hoje); stroke: var(--papel); stroke-width: 2; }
.viz .prop { fill: var(--serie-prop); stroke: var(--papel); stroke-width: 2; }
.viz circle:focus-visible { outline: 2px solid var(--tinta); outline-offset: 2px; }
.legenda { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12.5px; color: var(--suave); }
.legenda span { display: inline-flex; align-items: center; gap: 6px; }
.legenda .b { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
.legenda .b.prop { background: var(--serie-prop); } .legenda .b.hoje { background: var(--serie-hoje); }
.legenda .b.faixa { background: var(--viz-faixa); border-radius: 2px; border: 1px solid var(--linha); }
.legenda .b.ref { width: 2px; height: 14px; border-radius: 0; background: var(--suave); }
.dica { position: absolute; pointer-events: none; background: var(--tinta); color: var(--papel); font-size: 12.5px; padding: 5px 8px; border-radius: 6px; max-width: 260px; z-index: 2; }
a { color: var(--pinho); }
"""

JS = """
(function () {
  var fig = document.querySelector('.grafico'); if (!fig) return;
  var dica = document.getElementById('dica');
  function mostra(ev) {
    var t = ev.target; if (!t.dataset || !t.dataset.dica) return;
    dica.textContent = t.dataset.dica; dica.hidden = false;
    var b = fig.getBoundingClientRect(), c = t.getBoundingClientRect();
    var x = c.left - b.left + c.width / 2, y = c.top - b.top;
    dica.style.left = Math.max(8, Math.min(x - 110, b.width - 270)) + 'px';
    dica.style.top = Math.max(4, y - 38) + 'px';
  }
  function some() { dica.hidden = true; }
  fig.addEventListener('mouseover', mostra); fig.addEventListener('focusin', mostra);
  fig.addEventListener('mouseout', some); fig.addEventListener('focusout', some);
})();
"""

RP = PL['r_pago']
FM = PL['fatores_medidos']
FPAD = PL['fator_padrao']
_fv = [v['mediana'] for k, v in FM.items() if k.startswith('Villa|')]
FV_MIN, FV_MAX = (f"{min(_fv):.2f}".replace('.', ','), f"{max(_fv):.2f}".replace('.', ','))
N_VILLA = sum(1 for x in DM['estadias'] if (x.get('nossos_booking') or {}).get('Villa'))
N_REC = sum(1 for x in DM['estadias'] if (x.get('nossos_booking') or {}).get('Recanto'))


def _abaixo(chave):
    v = FM.get(chave)
    return None if not v else 1 - v['mediana'] / FPAD['Villa']


def _est(ci):
    return next((x for x in DM['estadias'] if x['checkin'] == ci), None)


# ---- este fim de semana: preço que seria gravado com a decisão 1 (abertura; escada só depois da metade vendida)
F0 = PL['fds_futuros'][0]
M0 = F0['mercado']
E0 = _est(F0['sexta'])
FP0 = {pp: (E0['fator_medido'].get(pp) or FPAD[pp]) for pp in ('Recanto', 'Villa')}
G3P = {pp: DP['fatores']['g3']['fds'][pp] / DP['fatores']['publico'][pp] for pp in ('Recanto', 'Villa')}
AB0 = {'Balcony': M0['pares_lista50'], 'Q7': M0['lista']['40'], 'Double': M0['pares_hidro50'] * 1.05, 'Q2': M0['pares_hidro50'] * 1.05,
       'Afrodite': M0['pares_hidro50'] * 1.05 * 1.15, 'VK7': M0['pares_hidro50'] * 1.05, 'VK2': M0['pares_hidro50'] * 1.05}
TT0 = {'Balcony': M0['lista']['50'], 'Q7': M0['hidro']['50']}


def _preco0(q, vendidas, un, hoje_pub):
    if vendidas >= un:
        return None
    meio, i = math.ceil(un / 2), vendidas + 1
    ab = AB0[q]
    v = ab if i <= meio else min(ab * (1 + PL['passo_escada']) ** (i - meio), max(TT0.get(q, M0['hidro']['65']), ab))
    return min(v, hoje_pub)


def _par(a, b, f=r):
    if a is None and b is None:
        return 'lotada'
    if a is None or b is None or round(a) == round(b):
        return f(a if a is not None else b) + (' (só sexta)' if b is None else ' (só sábado)' if a is None else '')
    return f"{f(a)} · {f(b)}"


_l0, _d0 = [], []
for q in QUARTOS:
    v = F0['quartos'][q]
    pp = POUSADA[q]
    ps, pb = _preco0(q, v['sex'], v['un'], v['publico']), _preco0(q, v['sab'], v['un'], v['publico'])
    env = lambda x: None if x is None else x / FP0[pp]  # noqa: E731
    g3_ = lambda x: None if x is None else x * G3P[pp]  # noqa: E731
    pg = lambda x: None if x is None else x / FP0[pp] * min(RP[pp], FP0[pp])  # noqa: E731
    ref = pb if pb is not None else ps
    _d0.append({'q': q, 'nome': NOME[q], 'un': v['un'], 'vend_sex': v['sex'], 'vend_sab': v['sab'], 'hoje_enviado': v['enviado'], 'hoje_publico': v['publico'],
                'sexta': None if ps is None else {'publico': round(ps), 'enviado': round(env(ps)), 'g3': round(g3_(ps)), 'pago': round(pg(ps))},
                'sabado': None if pb is None else {'publico': round(pb), 'enviado': round(env(pb)), 'g3': round(g3_(pb)), 'pago': round(pg(pb))}})
    _l0.append(f"<tr><td>{NOME[q]}</td><td class='num'>{v['sex']} · {v['sab']} <span class='de'>/ {v['un']}</span></td>"
               f"<td class='num'>{r(v['enviado'])}</td><td class='num'>{r(v['publico'])}</td>"
               f"<td class='num forte dest'>{_par(env(ps), env(pb))}</td><td class='num dest'>{_par(ps, pb)}</td>"
               f"<td class='num dest'>{_par(g3_(ps), g3_(pb))}</td><td class='num dest'>{_par(pg(ps), pg(pb))}</td>"
               f"<td class='num'>{chip_var(ref / v['publico'] - 1) if ref else ''}</td></tr>")
json.dump({'sexta': F0['sexta'], 'regua': M0, 'fator_publico': FP0, 'suites': _d0}, open(A / 'dados_este_fds.json', 'w'), ensure_ascii=False, indent=1)
TAB_ESTE = tabela([('Suíte', ''), ('Vendidas sexta · sábado', 'num'), ('Hoje: enviado', 'num'), ('Hoje: público', 'num'),
                   ('Proposto: enviado (sexta · sábado)', 'num'), ('público', 'num'), ('Genius 3', 'num'), ('hóspede paga ~', 'num'), ('Mudança', 'num')], _l0, 'plano')

D0111 = DP['desde_0111'][0]['booking_0111']
_ly = [x['mercado_pl'].get('occ_ly_final') for x in PL['expectativa'] if x['mercado_pl'].get('occ_ly_final') is not None]
OCC_LY_MIN, OCC_LY_MAX = n(min(_ly)), n(max(_ly))
_nat, _rev, _sem = _est('2026-12-23'), _est('2026-12-30'), [x for x in DM['estadias'] if x['checkin'][:7] == '2026-11' and x.get('tipo') == 'semana']
_semv = [x['nossos_booking']['Villa'] for x in _sem if (x.get('nossos_booking') or {}).get('Villa')]
_menor = lambda e: min(v['enviado'] for q, v in e['pedido'].items() if POUSADA[q] == 'Villa' and v['livres_min'] > 0)
FRASE_VILLA = (f"Nos dias de semana de novembro a Villa apareceu por {r(min(_semv))} a {r(max(_semv))} a noite, cerca de {p(_abaixo('Villa|2026-11|semana'))} abaixo da nossa conta de costume "
               f"(preço enviado × {str(FPAD['Villa']).replace('.', ',')}). No Natal (23 a 25/12) apareceu por {r(_nat['nossos_booking']['Villa'])} a noite, com o menor preço enviado em {r(_menor(_nat))}, "
               f"que daria {r(_menor(_nat) * FPAD['Villa'])}. No Réveillon (30/12 a 03/01), {r(_rev['nossos_booking']['Villa'])} contra {r(_menor(_rev) * FPAD['Villa'])}. "
               "No Beds24 o preço está igual ao do PriceLabs, então a diferença nasce na Booking: promoção, plano tarifário ou desconto que só aparece na extranet. Em janeiro e fevereiro a relação volta ao normal. "
               "Vale o senhor conferir em Promoções, principalmente as datas de Natal e Réveillon.")
nossos_b = [x for x in DM['estadias'] if x.get('nossos_booking')]

corpo = f"""
<div class="pagina">
<header>
  <div class="olho">Recanto dos Moinhos · Villa Dolce Amore · medido em 9 e 10/11/2026</div>
  <h1>Plano de preços de novembro a fevereiro</h1>
  <p class="lede">Os fins de semana fecham com cerca de {p(FDS_R['ocup'])} de ocupação no Recanto e {p(FDS_V['ocup'])} na Villa, e não lotados. O preço pedido hoje pelas diárias livres está muito acima do que os concorrentes cobram e do que os nossos próprios hóspedes pagaram. O plano coloca cada suíte no preço em que ela vende e só sobe depois de vender.</p>
  <div class="numeros">
    <div class="numero"><b>{p(FDS_R['ocup'])} · {p(FDS_V['ocup'])}</b><span>Ocupação dos fins de semana de 01/09 a 09/11 (Recanto · Villa)</span></div>
    <div class="numero"><b>{f"{RAZAO['Recanto']:.1f}".replace('.', ',')}× · {f"{RAZAO['Villa']:.1f}".replace('.', ',')}×</b><span>Preço pedido hoje no fim de semana ÷ o que o hóspede pagou (mediana)</span></div>
    <div class="numero"><b>{com_vaga}</b><span>Concorrentes com hidro que ainda têm vaga neste fim de semana (13 a 15/11)</span></div>
    <div class="numero"><b>{p(q7_f['ocup'])}</b><span>Ocupação da Moinhos nos fins de semana (7 unidades)</span></div>
    <div class="numero"><b>{p(bal_f['ocup'])} · {p(bal_s['ocup'])}</b><span>Ocupação da Balcony: fim de semana · dia de semana (6 unidades)</span></div>
  </div>
</header>

<section>
  <h2>O que encontrei</h2>
  <ul>
    <li><b>Os fins de semana não estão lotando.</b> {FRASE_FDS}</li>
    <li><b>Duas suítes puxam a ocupação para baixo: a Moinhos e a Balcony.</b> Juntas são 13 das 31 unidades. A Moinhos ocupou {p(q7_f['ocup'])} nos fins de semana; a Balcony, {p(bal_f['ocup'])} nos fins de semana e {p(bal_s['ocup'])} nos dias de semana. A King Spa 7 ocupou {p(vk7_f['ocup'])}.</li>
    <li><b>O preço pedido hoje está acima do que vende.</b> No fim de semana, o hóspede pagou em média (mediana) {r(pago_p[('Recanto', 'fds')]['p50'])} por noite no Recanto e {r(pago_p[('Villa', 'fds')]['p50'])} na Villa. As diárias livres dos fins de semana de novembro pedem o equivalente a {r(pedido_pago('Recanto', '2026-11', 'fds'))} e {r(pedido_pago('Villa', '2026-11', 'fds'))}.</li>
    <li><b>Estamos caros diante de hotéis mais bem avaliados.</b> Dos 77 concorrentes com hidro, {NOTAS['nota9+']} têm nota 9,0 ou mais na Booking e {NOTAS['pares(<9,0)']} têm nota abaixo de 9. A Villa tem 8,3 e o Recanto aparece sem nota. Neste fim de semana, o nosso quarto livre mais barato pede {r((mb_este.get('Recanto') or {}).get('publico'))} públicos no Recanto e {r((mb_este.get('Villa') or {}).get('publico'))} na Villa; a mediana dos concorrentes é {r(este['lista']['50'] if este else None)} no quarto mais barato e {r(este['hidro']['50'] if este else None)} no quarto com hidro.</li>
    <li><b>{p(ULT7['Recanto'])} das diárias de fim de semana do Recanto e {p(ULT7['Villa'])} das da Villa são vendidas nos últimos 7 dias.</b> É justamente quando o nosso preço está mais alto, porque a escada sobe a cada reserva e os pisos não deixam descer.</li>
    <li><b>A Booking mostra a Villa mais barata do que a nossa tabela indica.</b> {FRASE_VILLA}</li>
  </ul>
</section>

<section>
  <h2>Onde estamos: vendido contra a meta</h2>
  <p>A meta mínima é a sua: R$ 220 mil no Recanto e R$ 150 mil na Villa na baixa; R$ 350 mil e R$ 300 mil em dezembro e janeiro. "Falta por diária livre" é quanto cada diária ainda livre precisaria render para chegar à meta se todas fossem vendidas.</p>
  {TAB_LIVRO}
  <h3>Fins de semana e feriados à frente, suíte por suíte</h3>
  <p class="nota">Em cada suíte: vendidas na sexta · vendidas no sábado / unidades. "Mercado" é a ocupação dos imóveis vizinhos no Airbnb (PriceLabs): quanto já está reservado hoje e quanto fechou no mesmo fim de semana do ano passado.</p>
  {TAB_FDS}
  <h3>O que aconteceu desde 01/11</h3>
  <p>Em 01/11 medi os mesmos fins de semana. Depois vieram as subidas de 02 a 04/11 (fim de semana pelo mercado com hidro e as faixas F3 e F5). A tabela compara aquele dia com hoje, 9 dias depois. Nesses 9 dias, nos fins de semana comuns, {', '.join(SEM_VENDA[:-1]) + ' e ' + SEM_VENDA[-1] if len(SEM_VENDA) > 1 else ''.join(SEM_VENDA)} não ganharam nenhuma diária.</p>
  {TAB_DESDE}
  <p class="nota">Por suíte: diárias vendidas de sexta e sábado em 01/11 → hoje / diárias disponíveis. "Na Booking" é o preço por noite que a Booking mostrava para a estadia de sexta a domingo.</p>
</section>

<section>
  <h2>O que os concorrentes cobram</h2>
  <p>Busca nova na Booking (conector oficial), em 9 e 10/11: os 77 hotéis com hidromassagem no quarto, café e até 3 km do Capivari, em {len(DM['estadias'])} estadias de novembro a fevereiro. Preço público por noite, 2 adultos, com café.</p>
  <ul>
    <li><b>Mais barato</b> é o quarto de entrada de cada hotel, o preço que aparece na lista da Booking.</li>
    <li><b>Com hidro</b> é a estimativa do quarto com hidromassagem de cada hotel (quarto de entrada × a diferença medida em 33 hotéis; nos demais, a mediana de {str(DM['r_hidro_padrao']).replace('.', ',')}).</li>
    <li><b>Nota abaixo de 9</b> são os {NOTAS['pares(<9,0)']} hotéis com avaliação parecida com a nossa. São os que estão no mesmo parâmetro.</li>
  </ul>
  {TAB_REGUA}
  <p class="nota">"Recanto na Booking" e "Villa na Booking" são o preço por noite que a Booking mostrou para os nossos hotéis nas mesmas datas (o quarto livre mais barato). A Villa foi medida nas {N_VILLA} estadias; o Recanto, em {N_REC} (as demais buscas não foram feitas nesta rodada). Em busca de 1 noite com chegada na quarta o Recanto não aparece, porque a estadia mínima de quarta é de 2 noites.</p>
  {grafico()}
</section>

<section>
  <h2>O que o nosso hóspede paga de fato</h2>
  <p>Reservas ativas do Beds24 com estadia de 01/09 a 09/11. "Pagou" é o valor da reserva por noite, em todos os canais. "Pedido hoje" é o que o hóspede pagaria em média pelo preço atual das diárias livres de novembro (preço enviado × {str(RP['Recanto']).replace('.', ',')} no Recanto e × {str(RP['Villa']).replace('.', ',')} na Villa, medido nas reservas de 02 a 09/11).</p>
  {TAB_PAGO}
  <h3>Os 10 últimos fins de semana</h3>
  <p class="nota">Ocupação de sexta e sábado e o preço pago mediano por noite. Por suíte: diárias vendidas / diárias disponíveis nas duas noites.</p>
  {TAB_FP}
</section>

<section>
  <h2>A solução</h2>
  <div class="duas">
    <div class="caixa"><h3>Fim de semana</h3><ul>
      <li>Cada suíte abre no preço em que ela vende: a Balcony pelo quarto mais barato dos hotéis de nota parecida; a Moinhos um pouco abaixo da mediana do quarto mais barato; as King Spa e as Panorâmicas 5% acima da mediana do quarto com hidro dos hotéis de nota parecida com a nossa; a Afrodite 15% acima da Panorâmica I.</li>
      <li>A escada só começa depois que metade das unidades da suíte for vendida: +{p(PL['passo_escada'])} por unidade, até o teto.</li>
      <li>A 7 dias, noite com menos de 60% vendido desce {p(PL['descida_ultima'])} sozinha, sem precisar de ordem sua.</li>
      <li>A Balcony deixa de seguir a King Spa. Os 10% abaixo viram teto, não piso.</li>
    </ul></div>
    <div class="caixa"><h3>Dia de semana</h3><ul>
      <li>As suítes com banheira continuam abrindo no seu piso (R$ 500 no Genius 3) para quem reserva com antecedência.</li>
      <li>Na última semana, as unidades ainda livres vão ao preço mediano do mercado com hidro. É onde hoje vendemos.</li>
      <li>A Balcony ganha preço próprio de dia de semana, perto de {faixa_txt('Balcony', 'semana')} enviados ({faixa_txt('Balcony', 'semana', 'pago')} para o hóspede).</li>
    </ul></div>
    <div class="caixa"><h3>Feriados, Natal e Réveillon</h3><ul>
      <li>Abrem na mediana do mercado com hidro, nunca acima do preço de hoje, e sobem com as vendas até os 75%.</li>
      <li>No Natal (23 a 26/12) estamos atrás do mercado: {p(NATAL['Recanto']['nos'])} vendidos no Recanto e {p(NATAL['Villa']['nos'])} na Villa, contra {p(NATAL['Recanto']['merc'])} e {p(NATAL['Villa']['merc'])} dos imóveis vizinhos. Em 31/12 estamos à frente: {p(d31['Recanto']['ocup'])} e {p(d31['Villa']['ocup'])} contra {n(d31['Recanto']['mercado_agora'])}% e {n(d31['Villa']['mercado_agora'])}%.</li>
      <li>Os pacotes e as estadias mínimas não mudam.</li>
    </ul></div>
  </div>
  <p class="nota">Três preços, como sempre: <b>enviado</b> é a tabela do PriceLabs; <b>público</b> é o que a Booking mostra sem desconto; <b>Genius 3</b> é o hóspede mais descontado. "Hóspede paga ~" é a média de todos os canais. "Última semana" vale só para as unidades ainda livres a 7 dias. Na Villa, "público" e "Genius 3" usam a relação que a Booking mostrou de fato em cada mês e tipo de noite (de {FV_MIN} a {FV_MAX} do enviado), e não o 0,65 de costume; por suíte, são estimativas. O que eu gravaria é o preço público: se a promoção da Villa sair, o enviado é recalculado para manter o mesmo público.</p>
  <h3>Este fim de semana (13 e 14/11): o que eu gravaria com a decisão 1</h3>
  <p class="nota">Régua desta estadia: quarto mais barato dos hotéis de nota parecida {r(M0['pares_lista50'])}; quarto com hidro dos mesmos hotéis {r(M0['pares_hidro50'])}; {M0['com_preco']} de 77 concorrentes ainda com vaga. Suíte com mais da metade vendida já entra um degrau acima (+{p(PL['passo_escada'])}). Quando sexta e sábado diferem, aparecem os dois valores.</p>
  {TAB_ESTE}
  {''.join(f"<h3>{MES_NOME[m]}</h3>{tabela_mes(m)}" for m in MES_NOME)}
</section>

<section>
  <h2>Expectativa de ocupação, diária e receita</h2>
  <p>Três cenários por mês, somando o que já está vendido com as diárias que faltam vender.</p>
  <ul>
    <li><b>No ritmo de setembro a novembro:</b> a ocupação e a diária que tivemos de 01/09 a 09/11 se repetem.</li>
    <li><b>Plano, alvo:</b> fins de semana a {p(OA['provavel']['fds']['baixa'])} ({p(OA['provavel']['fds']['alta'])} em dezembro e janeiro), dias de semana a {p(OA['provavel']['semana']['baixa'])} ({p(OA['provavel']['semana']['alta'])}), feriados e festas a {p(OA['provavel']['feriado']['baixa'])}.</li>
    <li><b>Plano, lotação ideal:</b> fins de semana a {p(OA['meta']['fds']['baixa'])} ({p(OA['meta']['fds']['alta'])}), dias de semana a {p(OA['meta']['semana']['baixa'])} ({p(OA['meta']['semana']['alta'])}), feriados e festas a {p(OA['meta']['feriado']['baixa'])}.</li>
  </ul>
  {TAB_EXP}
  <div class="aviso"><b>O que sustenta cada cenário.</b><span>O ritmo de setembro a novembro é o que os dados já mostraram: com preços de entrada perto dos que proponho (em 01/11 a Booking mostrava {r(D0111['Recanto'])} no Recanto e {r(D0111['Villa'])} na Villa para este fim de semana), os fins de semana fecharam em {p(FDS_R['ocup'])} e {p(FDS_V['ocup'])}. O alvo do plano conta com três mudanças ainda não testadas: o preço de venda valendo desde a abertura, a Moinhos e a Balcony com preço próprio e a descida na última semana. A lotação ideal não tem apoio nos dados para a baixa temporada só com preço: a 3 dias deste fim de semana, {M0['com_preco']} de 77 concorrentes ainda têm vaga, e os imóveis vizinhos fecharam esses meses no ano passado entre {OCC_LY_MIN}% e {OCC_LY_MAX}% de ocupação. Chegar lá depende também da nota do anúncio, da venda direta e das promoções.</span></div>
  <p>No total dos quatro meses, contra {r(meta_tot)} de meta: {r(exp_tot['sem_mudar'])} no ritmo atual, {r(exp_tot['provavel'])} no alvo do plano e {r(exp_tot['meta'])} com a lotação ideal.</p>
  <div class="aviso"><b>Janeiro não fecha a meta só com preço.</b><span>Com a lotação ideal, janeiro chega a {p(jan[0]['cenarios']['meta']['vs_meta'])} da meta no Recanto e {p(jan[1]['cenarios']['meta']['vs_meta'])} na Villa. Para os R$ 350 mil e R$ 300 mil, cada unidade teria de render {r(jan[0]['revpar_meta'])} e {r(jan[1]['revpar_meta'])} por dia do mês. O mercado vizinho fechou janeiro passado com {n(jan[0]['mercado_pl']['occ_ly_final'])}% a {n(jan[1]['mercado_pl']['occ_ly_final'])}% de ocupação e diária de {r(jan[1]['mercado_pl']['adr_ly'])} a {r(jan[0]['mercado_pl']['adr_ly'])}.</span></div>
  <h3>Ocupação e diária ideais por tipo de noite</h3>
  {TAB_IDEAL}
</section>

<section>
  <h2>Decisões que são suas</h2>
  <p>Estes pontos mudam pisos e ordens que o senhor definiu. Não alterei nada. Responda com os números que aprova.</p>
  <div class="decisoes">
    <div class="decisao"><span class="n">Decisão 1 · urgente, vale para sexta</span><b>Aplicar já os preços de abertura neste fim de semana (13 e 14/11).</b><span>Estamos a 3 dias, com {p(PL['fds_futuros'][0]['pousadas']['Recanto']['ocup'])} no Recanto e {p(PL['fds_futuros'][0]['pousadas']['Villa']['ocup'])} na Villa. A Balcony pede {r(L['Balcony']['atual']['publico'] if L['Balcony'] else None)} públicos e não vendeu nenhuma das 6.</span></div>
    <div class="decisao"><span class="n">Decisão 2</span><b>Fim de semana: trocar o piso único de R$ 850 (Genius 3) por um piso por suíte.</b><span>No Recanto, Moinhos abrindo em {faixa_txt('Q7', 'fds')} enviados; Panorâmicas em {faixa_txt('Double', 'fds')}. O piso atual equivale a {r(PISO_FDS_ENV['Recanto'])} enviados e {r(PISO_FDS_PUB['Recanto'])} públicos; a mediana do quarto com hidro dos concorrentes nos fins de semana de novembro é {r(hid_fds_nov)}. Na Villa, as King Spa abrem perto do piso atual ({r(PISO_FDS_ENV['Villa'])} enviados) e só passam abaixo dele na última semana, se a noite estiver com menos de 60% vendido.</span></div>
    <div class="decisao"><span class="n">Decisão 3</span><b>Fins de semana abrem no mercado e sobem pela escada só depois de metade vendida.</b><span>Substitui a faixa F5 (topo) e F3 dos fins de semana, da sua ordem de 04/11, e a subida a cada reserva desde a primeira.</span></div>
    <div class="decisao"><span class="n">Decisão 4</span><b>Balcony com preço próprio.</b><span>Fim de semana em {faixa_txt('Balcony', 'fds')} enviados e dia de semana em {faixa_txt('Balcony', 'semana')}. Hoje ela fica presa a 10% abaixo da King Spa e sobe quando a King Spa vende.</span></div>
    <div class="decisao"><span class="n">Decisão 5</span><b>Dia de semana: na última semana, as suítes ainda livres descem ao preço mediano do mercado.</b><span>O piso de R$ 500 no Genius 3 continua valendo até 8 dias antes. Na última semana, a Moinhos iria a cerca de {r(linha_q('Q7', '2026-11', 'semana')['proposta']['ultima']['g3'])} no Genius 3 em novembro ({r(linha_q('Q7', '2026-11', 'semana')['proposta']['ultima']['pago'])} pagos em média).</span></div>
    <div class="decisao"><span class="n">Decisão 6</span><b>Natal e Réveillon abrem na mediana do mercado com hidro, não no topo.</b><span>Vale para o Recanto: hoje pedimos {r(linha_q('Q7', '2026-12', 'reveillon')['atual']['publico'])} públicos na Moinhos no Réveillon. A Villa já aparece na Booking abaixo da mediana do mercado nessas datas (ver o achado sobre a Booking). Quem já reservou as festas pagou em média {r(PAGO_FESTAS)} por noite ({n(fest_v)} diárias).</span></div>
    <div class="decisao"><span class="n">Decisão 7</span><b>Afrodite no fim de semana: 15% acima da Panorâmica I.</b><span>Substitui a faixa fixa de R$ 1.600 a R$ 1.800 públicos de 27/10. É 1 unidade; pesa pouco no total.</span></div>
  </div>
</section>

<section>
  <h2>Como foi medido e o que fica de fora</h2>
  <ul>
    <li><b>Nossos dados:</b> {n(len(DP['noites']))} noites-suíte de 10/11 a 28/02 (preços do PriceLabs e inventário do Beds24 lidos em 9/11 à noite) e as reservas do Beds24 com chegada desde 01/09.</li>
    <li><b>Concorrentes:</b> só o conector oficial da Booking. Ele devolve o quarto mais barato de cada hotel; o quarto com hidro é estimado.</li>
    <li><b>Mercado do PriceLabs:</b> são imóveis do Airbnb próximos, não hotéis. Servem para o ritmo de ocupação, não para o nível de preço.</li>
    <li><b>As ocupações do plano são alvos, não garantia.</b> Em 02/10 e 06/11, com preço pago mediano de {r(f0210['pago_p50'])} e {r(f0611['pago_p50'])}, o Recanto fechou com {p(f0210['ocup'])} e {p(f0611['ocup'])}; em 02/10, {p(f0210['ult3d'])} das diárias saíram nos últimos 3 dias. A proposta é ter o preço que vende valendo desde já, não só na véspera, e medir toda segunda.</li>
    <li><b>O que não consegui fazer nesta rodada:</b> a conferência do quarto com hidro hotel a hotel (preço de cada categoria) e a revisão independente dos números pararam no limite de uso da conta. A revisão roda ainda nesta madrugada; se algum número mudar, eu aviso e corrijo esta página.</li>
    <li><b>Fora desta análise:</b> as promoções e os descontos ativos na extranet da Booking, que eu não enxergo, e a nota dos anúncios. As duas coisas pesam na venda.</li>
  </ul>
</section>
</div>
"""

pagina = ('<title>Plano de Preços Nov–Fev</title>\n<meta name="claude:viewport-fit-sides" content="cover">\n'
          '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Literata:opsz,wght@7..72,500;7..72,650&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">\n'
          f'<style>{CSS}</style>\n{corpo}\n<script>{JS}</script>\n')
(A / 'pagina').mkdir(exist_ok=True)
open(A / 'pagina' / 'index.html', 'w', encoding='utf-8').write(pagina)
print('página', len(pagina), 'bytes')

"""Gera o resumo em Markdown da análise nov-fev para o repositório (pricelabs/analise-nov-fev-2026-11-10.md)."""
from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path

A = Path('/tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/an1110')
DP = json.load(open(A / 'dados_proprios.json'))
DM = json.load(open(A / 'dados_mercado.json'))
PL = json.load(open(A / 'dados_plano.json'))
NOME, UNID = DP['nome'], DP['unid']
QUARTOS = ['Q7', 'Q2', 'Double', 'Afrodite', 'VK7', 'VK2', 'Balcony']
MES = {'2026-11': 'nov (10-30)', '2026-12': 'dez', '2027-01': 'jan', '2027-02': 'fev'}
SUB = {'semana': 'dia de semana', 'fds': 'fim de semana', 'feriado': 'feriado', 'natal': 'Natal', 'entre_festas': 'entre festas',
       'reveillon': 'Réveillon'}
URL = sys.argv[1] if len(sys.argv) > 1 else ''
EF = json.load(open(A / 'dados_este_fds.json'))
POUS = DP['pousada']
_fv = [v['mediana'] for k, v in PL['fatores_medidos'].items() if k.startswith('Villa|')]
FV_MIN, FV_MAX = f'{min(_fv):.2f}'.replace('.', ','), f'{max(_fv):.2f}'.replace('.', ',')


def _est(ci):
    return next(x for x in DM['estadias'] if x['checkin'] == ci)


def _menor(e):
    return min(v['enviado'] for q, v in e['pedido'].items() if POUS[q] == 'Villa' and v['livres_min'] > 0)


def r(v):
    return '—' if v is None else 'R$ ' + f'{v:,.0f}'.replace(',', '.')


def p(v):
    return '—' if v is None else f'{v * 100:.0f}%'


def dm(iso):
    d = date.fromisoformat(iso)
    return f'{d.day:02d}/{d.month:02d}'


def tab(cab, linhas):
    return '\n'.join(['| ' + ' | '.join(cab) + ' |', '|' + '---|' * len(cab)] + ['| ' + ' | '.join(str(c) for c in ln) + ' |' for ln in linhas])


_sv = [x['nossos_booking']['Villa'] for x in DM['estadias'] if x['checkin'][:7] == '2026-11' and x.get('tipo') == 'semana' and (x.get('nossos_booking') or {}).get('Villa')]
VS_MIN, VS_MAX = r(min(_sv)), r(max(_sv))
NAT_B, NAT_E = r(_est('2026-12-23')['nossos_booking']['Villa']), r(_menor(_est('2026-12-23')))
REV_B, REV_E = r(_est('2026-12-30')['nossos_booking']['Villa']), r(_menor(_est('2026-12-30')))
pq = {(x['quarto'], x['grupo']): x for x in DP['pago']['por_quarto']}
pp_ = {(x['pousada'], x['grupo']): x for x in DP['pago']['por_pousada']}
out = ['# Análise de preços e plano de novembro de 2026 a fevereiro de 2027', '',
       'Pedido do dono em 09/11/2026 (noite): análise completa dos fins de semana e dos dias de semana contra os hotéis do mesmo '
       'parâmetro, plano de novembro a fevereiro e expectativa de valor e lotação. **Nada foi gravado no PriceLabs**: o plano '
       'depende das decisões do dono listadas no fim.', '']
if URL:
    out += [f'Página com todas as tabelas: {URL}', '']
out += ['## Como foi medido', '',
        f"- Nossos dados: preços do PriceLabs (`get_listing_prices`, 7 quartos) e inventário e reservas do Beds24, lidos em 09/11 à noite; {len(DP['noites'])} noites-suíte de 10/11 a 28/02; reservas com chegada desde 01/09.",
        f"- Concorrentes: conector oficial da Booking, 09-10/11, {DM['concorrentes']} hotéis com hidro no quarto, café e até 3 km do Capivari (`pricelabs/pesquisa-booking/concorrentes.json`), {len(DM['estadias'])} estadias; respostas em `pricelabs/pesquisa-booking/faixas-2026-11/novfev_respostas_*.json`.",
        f"- \"Mais barato\" = quarto de entrada de cada hotel; \"com hidro\" = × r_hidro do hotel (33 medidos; padrão {DM['r_hidro_padrao']}). Notas na Booking: {DM['notas']['nota9+']} hotéis com 9,0 ou mais, {DM['notas']['pares(<9,0)']} abaixo de 9 (pares), {DM['notas']['sem_nota']} sem nota. Villa 8,3; Recanto sem nota no anúncio.",
        f"- Conversões: de costume, público = enviado × {DP['fatores']['publico']['Recanto']} (Recanto) / {DP['fatores']['publico']['Villa']} (Villa). Nesta medição a Booking mostrou o Recanto em 0,62 a 0,63 do enviado e a Villa de {FV_MIN} a {FV_MAX} conforme o mês e o tipo de noite; o plano usa o medido. O hóspede paga em média enviado × {PL['r_pago']['Recanto']} / {PL['r_pago']['Villa']} (reservas de 02 a 09/11), limitado ao preço público.",
        f"- Villa na Booking abaixo da tabela: dias de semana de novembro {VS_MIN} a {VS_MAX} a noite; Natal (23 a 25/12) {NAT_B} com menor enviado de {NAT_E}; Réveillon (30/12 a 03/01) {REV_B} com menor enviado de {REV_E}. No Beds24 o preço é igual ao do PriceLabs: a diferença nasce na Booking (promoção, plano tarifário ou desconto da extranet). Pendente de conferência pelo dono.",
        '- Não feito nesta rodada (limite de uso da conta): conferência do quarto com hidro hotel a hotel e revisão independente dos números; o Recanto foi medido na Booking em 7 das 32 estadias.',
        '- Mercado do PriceLabs: imóveis do Airbnb próximos (ritmo de ocupação, não nível de preço).', '']

out += ['## 1. Fins de semana realizados (01/09 a 09/11)', '',
        tab(['Sexta', 'Recanto', 'pago (mediana)', 'Villa', 'pago (mediana)'] + [NOME[q] for q in QUARTOS],
            [[dm(f['sexta']) + (' (feriado)' if f['tipo'] == 'feriado' else ''), p(f['pousadas']['Recanto']['ocup']), r(f['pousadas']['Recanto']['pago_p50']),
              p(f['pousadas']['Villa']['ocup']), r(f['pousadas']['Villa']['pago_p50'])] +
             [f"{f['quartos'][q]['vendidas']}/{f['quartos'][q]['cap']}" for q in QUARTOS] for f in DP['fds_passados']]), '']

out += ['## 2. O que o hóspede paga, por suíte (estadias de 01/09 a 09/11) e o pedido de hoje', '',
        tab(['Suíte', 'Unid.', 'Fds: ocupação', 'pago 25%', 'pago mediana', 'pago 75%', 'pedido hoje (pago equivalente, nov)',
             'Semana: ocupação', 'pago mediana', 'pedido hoje'],
            [[NOME[q], UNID[q], p(pq[(q, 'fds')]['ocup']), r(pq[(q, 'fds')]['p25']), r(pq[(q, 'fds')]['p50']), r(pq[(q, 'fds')]['p75']),
              r(next((x['atual']['pago'] for x in PL['linhas'] if x['q'] == q and x['mes'] == '2026-11' and x['sub'] == 'fds'), None)),
              p(pq[(q, 'semana')]['ocup']), r(pq[(q, 'semana')]['p50']),
              r(next((x['atual']['pago'] for x in PL['linhas'] if x['q'] == q and x['mes'] == '2026-11' and x['sub'] == 'semana'), None))]
             for q in QUARTOS]), '']

out += ['## 3. Desde 01/11 (antes das subidas de 02 a 04/11) até hoje', '',
        tab(['Sexta', 'Recanto: ocupação', 'Villa: ocupação', 'Recanto na Booking em 01/11', 'Villa na Booking em 01/11'] + [NOME[q] for q in QUARTOS],
            [[dm(f['sexta']), f"{p(f['pousadas']['Recanto']['antes'])} → {p(f['pousadas']['Recanto']['hoje'])}",
              f"{p(f['pousadas']['Villa']['antes'])} → {p(f['pousadas']['Villa']['hoje'])}", r(f['booking_0111']['Recanto']), r(f['booking_0111']['Villa'])] +
             [f"{f['quartos'][q]['antes']}→{f['quartos'][q]['hoje']}" for q in QUARTOS] for f in DP['desde_0111']]), '']

out += ['## 4. Régua dos concorrentes (preço público por noite)', '',
        tab(['Mês', 'Tipo', 'Estadias', 'Com vaga', 'Mais barato 25%', 'mediana', '75%', 'Com hidro 40%', 'mediana', '65%',
             'Pares: mais barato', 'Pares: com hidro', 'Recanto na Booking', 'Villa na Booking'],
            [[MES[g['mes']], SUB[g['sub']], g['n_estadias'], f"{g['com_preco']}/77", r(g['lista']['25']), r(g['lista']['50']), r(g['lista']['75']),
              r(g['hidro']['40']), r(g['hidro']['50']), r(g['hidro']['65']), r(g['pares_lista']['50']), r(g['pares_hidro']['50']),
              r((g.get('nossos_booking') or {}).get('Recanto')), r((g.get('nossos_booking') or {}).get('Villa'))]
             for k, g in sorted(PL['regua'].items())]), '']

out += ['## 5. Vendido contra a meta mínima do dono', '',
        tab(['Mês', 'Pousada', 'Meta', 'Já vendido', '% da meta', 'Ocupação já vendida', 'Diárias livres'],
            [[MES[x['mes']].split(' ')[0], x['pousada'], r(x['meta_dono']), r(x['livro']), p(x['livro'] / x['meta_dono']), p(x['ocup_livro_mes']), x['livres_futuro']]
             for x in DP['livro_meta']]), '']

out += ['## 6. Proposta por mês, tipo de noite e suíte', '',
        f"Fim de semana, feriado e festas: abertura na âncora de mercado; escada de +{PL['passo_escada'] * 100:.0f}% por unidade depois da metade vendida, até o teto; "
        f"a 7 dias com menos de 60% vendido, −{PL['descida_ultima'] * 100:.0f}%. Dia de semana das suítes com banheira: abertura no piso atual do dono e, na última semana, "
        'o preço mediano do mercado com hidro. Balcony com preço próprio.', '']
linhas = []
for x in PL['linhas']:
    pr_ = x.get('proposta')
    if not pr_:
        continue
    linhas.append([MES[x['mes']], SUB[x['sub']], NOME[x['q']], f"{p(x['ocup'])} ({x['vendidas']}/{x['cap']})", r(x['atual']['enviado']), r(x['atual']['publico']),
                   r(pr_['abertura']['enviado']), r(pr_['abertura']['publico']), r(pr_['abertura']['g3']), r(pr_['abertura']['pago']),
                   (f"{pr_['var_vs_atual'] * 100:+.0f}%" if pr_.get('var_vs_atual') is not None else '—'), r(pr_['ultima']['enviado']), r(pr_['teto']['enviado'])])
out += [tab(['Mês', 'Tipo', 'Suíte', 'Já vendido', 'Hoje: enviado', 'Hoje: público', 'Proposto: enviado', 'público', 'Genius 3', 'hóspede paga ~',
             'Mudança', 'Última semana (enviado)', 'Teto (enviado)'], linhas), '']

def _dupla(a, b, campo):
    if a is None and b is None:
        return 'lotada'
    va, vb = (a or {}).get(campo), (b or {}).get(campo)
    if va is None or vb is None or va == vb:
        return r(va if va is not None else vb)
    return f'{r(va)} · {r(vb)}'


out += ['### Este fim de semana (13 e 14/11): preços que seriam gravados com a decisão 1', '',
        f"Régua da estadia: quarto mais barato dos hotéis de nota abaixo de 9 {r(EF['regua']['pares_lista50'])}; com hidro dos mesmos hotéis {r(EF['regua']['pares_hidro50'])}; {EF['regua']['com_preco']} de 77 concorrentes com vaga. Valores de sexta · sábado quando diferem.", '',
        tab(['Suíte', 'Vendidas sexta · sábado / unid.', 'Hoje: enviado', 'Hoje: público', 'Proposto: enviado', 'público', 'Genius 3', 'hóspede paga ~'],
            [[x['nome'], f"{x['vend_sex']} · {x['vend_sab']} / {x['un']}", r(x['hoje_enviado']), r(x['hoje_publico']), _dupla(x['sexta'], x['sabado'], 'enviado'),
              _dupla(x['sexta'], x['sabado'], 'publico'), _dupla(x['sexta'], x['sabado'], 'g3'), _dupla(x['sexta'], x['sabado'], 'pago')] for x in EF['suites']]), '']

out += ['## 7. Expectativa (receita do mês: já vendido + diárias a vender)', '',
        tab(['Mês', 'Pousada', 'Meta', 'No ritmo de set-nov', '% meta', 'Plano: alvo', '% meta', 'ocupação', 'Plano com lotação ideal', '% meta', 'ocupação',
             'Mercado: ocupação final ano passado'],
            [[MES[x['mes']].split(' ')[0], x['pousada'], r(x['meta_dono']), r(x['cenarios']['sem_mudar']['receita']), p(x['cenarios']['sem_mudar']['vs_meta']),
              r(x['cenarios']['provavel']['receita']), p(x['cenarios']['provavel']['vs_meta']), p(x['cenarios']['provavel']['ocup_mes']),
              r(x['cenarios']['meta']['receita']), p(x['cenarios']['meta']['vs_meta']), p(x['cenarios']['meta']['ocup_mes']),
              f"{x['mercado_pl'].get('occ_ly_final')}%"] for x in PL['expectativa']]), '',
        f"Ocupações do alvo do plano: {json.dumps(PL['ocup_alvo']['provavel'], ensure_ascii=False)}; lotação ideal: {json.dumps(PL['ocup_alvo']['meta'], ensure_ascii=False)}. "
        'São alvos, não garantia; medir toda segunda. O ritmo de set-nov é o que os dados já mostraram (com entrada perto da proposta, os fins de semana fecharam em 65% e 68%); '
        'o alvo depende de três mudanças ainda não testadas (preço de venda desde a abertura, Moinhos e Balcony com preço próprio, descida na última semana); '
        'a lotação ideal não tem apoio nos dados da baixa temporada só com preço.', '']

out += ['## 8. Decisões do dono (aguardando resposta)', '',
        '1. Aplicar já os preços de abertura no fim de semana de 13 e 14/11.',
        '2. Fim de semana: trocar o piso único de R$ 850 (Genius 3) por piso por suíte (na Villa, as King Spa só passam abaixo do piso atual na última semana, com menos de 60% vendido).',
        '3. Fins de semana abrem no mercado e sobem pela escada só depois de metade vendida (substitui F5/F3 da ordem de 04/11 e a subida desde a primeira reserva).',
        '4. Balcony com preço próprio; os 10% abaixo da King Spa viram teto.',
        '5. Dia de semana: na última semana, as suítes ainda livres descem ao preço mediano do mercado (o piso de R$ 500 no Genius 3 vale até 8 dias antes).',
        '6. Natal e Réveillon do Recanto abrem na mediana do mercado com hidro, não no topo (F5); a Villa já aparece na Booking abaixo dessa mediana.',
        '7. Afrodite no fim de semana: 15% acima da Panorâmica I, no lugar da faixa de R$ 1.600 a R$ 1.800 públicos.', '']
open(A / 'analise.md', 'w', encoding='utf-8').write('\n'.join(out))
print('md', len('\n'.join(out)), 'bytes')

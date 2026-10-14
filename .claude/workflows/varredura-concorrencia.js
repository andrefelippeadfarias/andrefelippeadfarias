export const meta = {
  name: 'varredura-concorrencia',
  description: 'Varredura somente leitura de toda a concorrência de Campos do Jordão na Booking (17 conjuntos de datas, cobertura por tipo) e dados de mercado do PriceLabs, para qualificar os preços em 5 faixas de mercado',
  phases: [
    { title: 'Datas', detail: '2 agentes por conjunto de datas: faixas de preço baixas e altas' },
    { title: 'Cobertura', detail: 'busca por tipo de acomodação em 3 datas de referência' },
    { title: 'PriceLabs', detail: 'dados de mercado e compset do PriceLabs' },
  ],
}

const FIXOS = `REGRA ABSOLUTA: somente leitura. Use apenas a ferramenta mcp__Booking_com__accommodations_search (carregue com ToolSearch usando a consulta "select:mcp__Booking_com__accommodations_search"). Não use nenhuma ferramenta que grave algo. Se o servidor MCP estiver desconectado, chame ToolSearch de novo (ele espera reconectar), até 3 vezes.
Parâmetros fixos em TODA chamada: destination "Campos do Jordão, Brazil"; number_of_adults 2; user_country_code "br"; user_locale "pt-br"; currency "BRL"; user_query em inglês, curta (ex.: "Hotels in Campos do Jordão for the given dates"); view_mode "LIST". Não use hotel_names, exceto na chamada dos nossos hotéis.
A resposta de cada chamada devolve no máximo 10 propriedades. Por isso, cada busca tem que ser repetida até esgotar a oferta: se uma faixa de preço devolver 10 propriedades, DIVIDA a faixa ao meio (por exemplo 401-650 vira 401-525 e 526-650) e repita, até cada sub-faixa devolver menos de 10 (no máximo 3 níveis de divisão). O filtro de preço é por noite, em reais (price.minimum e price.maximum).
Para cada propriedade devolvida copie EXATAMENTE (não invente nada): id; name; stars = rating.stars (0 se faltar); score = rating.review_score (0 se faltar); reviews = rating.number_of_reviews (0 se faltar); price_total = price.book (total da estadia, em reais); jacuzzi = true se facilities tiver "Jacuzzi" ou "Banheira de hidromassagem" ou "Banheira de hidromassagem/spa" (ou similar); spa = true se tiver "Spa e centro de bem-estar", "Sauna" ou "Spa"; pool = true se tiver "Piscina". Elimine duplicatas pelo id. Marque nosso=true para os ids 15459723 (Pousada Recanto dos Moinhos) e 14824789 (Villa Dolce Amore).`

const SCHEMA_PROPS = {
  type: 'object',
  properties: {
    rotulo: { type: 'string' },
    checkin: { type: 'string' },
    checkout: { type: 'string' },
    noites: { type: 'number' },
    chamadas: { type: 'number' },
    props: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'number' }, name: { type: 'string' }, stars: { type: 'number' }, score: { type: 'number' },
          reviews: { type: 'number' }, price_total: { type: 'number' },
          jacuzzi: { type: 'boolean' }, spa: { type: 'boolean' }, pool: { type: 'boolean' }, nosso: { type: 'boolean' },
        },
        required: ['id', 'name', 'stars', 'score', 'price_total', 'jacuzzi', 'spa', 'pool'],
      },
    },
    observacoes: { type: 'string' },
  },
  required: ['rotulo', 'checkin', 'checkout', 'noites', 'props'],
}

const DATAS = [
  { r: 'semana-20/10', tipo: 'semana', ci: '2026-10-20', co: '2026-10-22' },
  { r: 'semana-27/10', tipo: 'semana', ci: '2026-10-27', co: '2026-10-29' },
  { r: 'semana-03/11', tipo: 'semana', ci: '2026-11-03', co: '2026-11-05' },
  { r: 'semana-24/11', tipo: 'semana', ci: '2026-11-24', co: '2026-11-26' },
  { r: 'semana-08/12', tipo: 'semana', ci: '2026-12-08', co: '2026-12-10' },
  { r: 'fimdesemana-23/10', tipo: 'fim de semana', ci: '2026-10-23', co: '2026-10-25' },
  { r: 'fimdesemana-06/11', tipo: 'fim de semana', ci: '2026-11-06', co: '2026-11-08' },
  { r: 'fimdesemana-04/12', tipo: 'fim de semana', ci: '2026-12-04', co: '2026-12-06' },
  { r: 'oktoberfest-16/10', tipo: 'evento', ci: '2026-10-16', co: '2026-10-18' },
  { r: 'finados-30/10', tipo: 'feriado', ci: '2026-10-30', co: '2026-11-01' },
  { r: 'proclamacao-13/11', tipo: 'feriado', ci: '2026-11-13', co: '2026-11-15' },
  { r: 'consciencia-19/11', tipo: 'feriado', ci: '2026-11-19', co: '2026-11-21' },
  { r: 'natal-24/12', tipo: 'feriado', ci: '2026-12-24', co: '2026-12-26' },
  { r: 'reveillon-30/12', tipo: 'feriado', ci: '2026-12-30', co: '2027-01-01' },
  { r: 'ultimahora-14/10', tipo: 'ultima hora', ci: '2026-10-14', co: '2026-10-15' },
  { r: 'ultimahora-15/10', tipo: 'ultima hora', ci: '2026-10-15', co: '2026-10-16' },
  { r: 'semana-01/12', tipo: 'semana', ci: '2026-12-01', co: '2026-12-03' },
]

const jobs = []
for (const d of DATAS) {
  jobs.push({
    d, parte: 'baixa',
    prompt: `${FIXOS}\n\nTAREFA: datas ${d.ci} a ${d.co} (${d.tipo}). Use checkin_date "${d.ci}" e checkout_date "${d.co}". Varra a OFERTA DE PREÇO BAIXO E MÉDIO, em busca geral (sem filtro de facilities): faixas por noite de 1-400, 401-650 e 651-900 reais, dividindo ao meio sempre que uma faixa devolver 10. Devolva rotulo "${d.r}-baixa", as datas, noites (diferença entre checkin e checkout), o número de chamadas feitas e todas as propriedades únicas encontradas. Se uma faixa vier vazia, anote nas observações.`,
  })
  jobs.push({
    d, parte: 'alta',
    prompt: `${FIXOS}\n\nTAREFA: datas ${d.ci} a ${d.co} (${d.tipo}). Use checkin_date "${d.ci}" e checkout_date "${d.co}". Faça (1) a chamada dos nossos hotéis com hotel_names ["Pousada Recanto dos Moinhos", "Villa Dolce Amore"] (use destination "Campos do Jordão, Brazil" também; se vier hotel_names_no_availability, anote como indisponível); (2) a varredura da OFERTA DE PREÇO ALTO em busca geral: faixas por noite de 901-1300, 1301-1900, 1901-2800 e 2801-12000 reais; (3) a mesma varredura com facilities ["HOT_TUB_JACUZZI"] nas faixas 301-900, 901-1900 e 1901-12000, dividindo ao meio sempre que uma faixa devolver 10. Devolva rotulo "${d.r}-alta", as datas, noites, o número de chamadas e todas as propriedades únicas (inclusive as nossas, com nosso=true, se vierem), mais nas observações o preço total dos nossos dois hotéis ou "indisponível".`,
  })
}

const TIPOS = ['HOTEL', 'APARTMENT', 'GUEST_HOUSE', 'BED_AND_BREAKFAST', 'HOLIDAY_HOME', 'RESORT', 'HOSTEL', 'VILLA']
const REFS = [
  { r: 'cobertura-semana-20/10', ci: '2026-10-20', co: '2026-10-22' },
  { r: 'cobertura-fds-23/10', ci: '2026-10-23', co: '2026-10-25' },
  { r: 'cobertura-finados-30/10', ci: '2026-10-30', co: '2026-11-01' },
]
const jobsCob = REFS.map(x => ({
  x,
  prompt: `${FIXOS}\n\nTAREFA DE COBERTURA: datas ${x.ci} a ${x.co}. Use checkin_date "${x.ci}" e checkout_date "${x.co}". Para CADA tipo de acomodação em ${JSON.stringify(TIPOS)}, faça a busca com accommodation_types [tipo] (uma chamada por tipo; se devolver 10, repita com price.minimum/price.maximum dividindo em faixas por noite 1-500, 501-1000, 1001-2000, 2001-12000). Devolva rotulo "${x.r}", datas, noites, o número de chamadas e todas as propriedades únicas. Em observacoes escreva quantas propriedades únicas vieram por tipo (ex.: "HOTEL 38; APARTMENT 12; ...").`,
}))

const SCHEMA_PL = {
  type: 'object',
  properties: {
    fontes_usadas: { type: 'array', items: { type: 'string' } },
    resumo: { type: 'string' },
    mercado_por_data: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          pousada: { type: 'string' }, date: { type: 'string' },
          p25: { type: 'number' }, p50: { type: 'number' }, p75: { type: 'number' }, p90: { type: 'number' },
          adr_mercado: { type: 'number' }, ocupacao_mercado: { type: 'number' }, ocupacao_mercado_ano_passado: { type: 'number' },
        },
        required: ['pousada', 'date'],
      },
    },
    compset: { type: 'array', items: { type: 'object', properties: { nome: { type: 'string' }, detalhe: { type: 'string' } }, required: ['nome'] } },
    limitacoes: { type: 'string' },
  },
  required: ['fontes_usadas', 'resumo'],
}
const promptPL = `REGRA ABSOLUTA: somente leitura. Use apenas ferramentas get_* e market_research do PriceLabs (carregue com ToolSearch, por exemplo "select:mcp__PriceLabs__get_neighbourhood_data,mcp__PriceLabs__get_listing_neighborhood_market,mcp__PriceLabs__get_md_report_compsets,mcp__PriceLabs__get_listing_optimizer_report,mcp__PriceLabs__get_str_index"). NUNCA chame update_*, delete_*, refresh_listing_pricing, accept_nudge, map/unmap ou set_*. Copie números exatamente como vieram; não invente.
CONTEXTO: duas pousadas em Campos do Jordão, pms "beds24". Recanto dos Moinhos: listing 350362___722805 (Queen 7), grupo 124405. Villa Dolce Amore: listing 350364___722813 (King Spa 7). Hoje é 2026-10-13.
TAREFA: descubra tudo o que o PriceLabs oferece de dados de MERCADO de hotel para esses listings nos próximos 60 dias (2026-10-14 a 2026-12-12): percentis de preço do mercado (25, 50, 75, 90 ou equivalentes), ADR do mercado, ocupação do mercado (e do mesmo dia do ano passado), e o conjunto de concorrentes do PriceLabs (compset de hotéis, 'Hotel Data'), com nomes e preços se existirem. Ferramentas candidatas: get_neighbourhood_data, get_listing_neighborhood_market, get_md_report_compsets, get_listing_optimizer_report/summary/ranking, get_neighborhood_data_sources, get_str_index. Teste cada uma para os dois listings; use as datas pedidas. Devolva em mercado_por_data uma linha por pousada e por data (pelo menos 1 linha por semana, e todas as datas de feriado: 2026-10-30, 31, 11-01, 11-13, 11-14, 11-19, 11-20, 11-21), com os campos que existirem. No resumo diga quais fontes funcionaram e o que cada uma traz (em português). Em limitacoes diga o que não existe.`

phase('Datas')
const resDatas = await parallel(jobs.map(j => () => agent(j.prompt, { label: `${j.d.r}:${j.parte}`, phase: 'Datas', schema: SCHEMA_PROPS })))
phase('Cobertura')
const resCob = await parallel(jobsCob.map(j => () => agent(j.prompt, { label: j.x.r, phase: 'Cobertura', schema: SCHEMA_PROPS })))
phase('PriceLabs')
const resPL = await agent(promptPL, { label: 'pricelabs-mercado', phase: 'PriceLabs', schema: SCHEMA_PL })

const falhas = resDatas.map((r, i) => r ? null : jobs[i].d.r + ':' + jobs[i].parte).filter(Boolean)
log(`Datas: ${resDatas.filter(Boolean).length}/${jobs.length} agentes ok; falhas: ${falhas.join(', ') || 'nenhuma'}`)
return { datas: resDatas.map((r, i) => r ? { ...r, tipo: jobs[i].d.tipo, parte: jobs[i].parte } : null), falhas, cobertura: resCob, pricelabs: resPL }

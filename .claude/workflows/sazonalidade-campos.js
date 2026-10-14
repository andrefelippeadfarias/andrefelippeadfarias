export const meta = {
  name: 'sazonalidade-campos',
  description: 'Pesquisa somente leitura da sazonalidade de Campos do Jordão (12 meses): feriados, eventos, estações, dados de mercado e histórico próprio no PriceLabs, com síntese em calendário de demanda',
  phases: [
    { title: 'Pesquisa', detail: 'web (feriados, eventos, estações) e PriceLabs (mercado 12 meses e histórico próprio) em paralelo' },
    { title: 'Síntese', detail: 'calendário de demanda por período com nível e evidência' },
  ],
}
const SO_LEITURA = `REGRA ABSOLUTA: somente leitura. Nunca grave nada em lugar nenhum. Responda em português do Brasil. Copie datas e números exatamente como vieram das fontes e cite a fonte (URL ou ferramenta) de cada fato. Se não achar, diga "não encontrado"; não invente. Hoje é 2026-10-13. As ferramentas ficam disponíveis via ToolSearch (ex.: "select:WebSearch,WebFetch" ou "select:mcp__PriceLabs__get_neighbourhood_data"). Se um servidor MCP estiver desconectado, chame ToolSearch de novo (até 3 vezes).`
const S_PERIODOS = {
  type: 'object',
  properties: {
    itens: { type: 'array', items: { type: 'object', properties: {
      nome: { type: 'string' }, inicio: { type: 'string' }, fim: { type: 'string' }, tipo: { type: 'string' },
      abrangencia: { type: 'string' }, impacto_esperado: { type: 'string' }, fonte: { type: 'string' }, confianca: { type: 'string' } },
      required: ['nome', 'inicio', 'fim', 'tipo', 'fonte'] } },
    resumo: { type: 'string' }, lacunas: { type: 'string' },
  }, required: ['itens', 'resumo'] }
const S_PL = {
  type: 'object',
  properties: {
    mensal: { type: 'array', items: { type: 'object', properties: {
      pousada: { type: 'string' }, mes: { type: 'string' }, ocupacao_mercado_media: { type: 'number' }, ocupacao_mercado_ano_passado: { type: 'number' },
      adr_mercado: { type: 'number' }, nossa_ocupacao: { type: 'number' }, nosso_adr: { type: 'number' }, nossa_receita: { type: 'number' }, nota: { type: 'string' } },
      required: ['pousada', 'mes'] } },
    picos: { type: 'array', items: { type: 'object', properties: { data: { type: 'string' }, ocupacao_mercado: { type: 'number' }, motivo_provavel: { type: 'string' } }, required: ['data'] } },
    fontes_usadas: { type: 'array', items: { type: 'string' } }, resumo: { type: 'string' }, limitacoes: { type: 'string' },
  }, required: ['mensal', 'fontes_usadas', 'resumo'] }

phase('Pesquisa')
const tarefas = [
  { label: 'web:feriados', schema: S_PERIODOS, prompt: `${SO_LEITURA}
TAREFA: levante TODOS os feriados e pontes de 2026-10-13 a 2027-12-31 que movem turismo para Campos do Jordão (SP): nacionais, do estado de São Paulo (9 de julho), municipais de Campos do Jordão (aniversário da cidade, padroeiro), facultativos (Carnaval, Quarta-feira de Cinzas, Corpus Christi), e férias escolares da rede estadual de SP e das principais redes particulares (julho, dezembro-janeiro). Para cada um dê inicio e fim das NOITES de hospedagem afetadas (ex.: feriado na sexta 1/5/2027: noites de 30/4 e 1/5), tipo ("feriado nacional", "feriado estadual", "feriado municipal", "ponte", "férias escolares"), abrangencia (quem viaja: SP capital, Vale do Paraíba, nacional), impacto_esperado (alto/médio/baixo para Campos do Jordão, com 1 frase do porquê) e a fonte. Use WebSearch e WebFetch (planalto.gov.br, calendário oficial, prefeitura de Campos do Jordão, Secretaria da Educação de SP).` },
  { label: 'web:eventos', schema: S_PERIODOS, prompt: `${SO_LEITURA}
TAREFA: levante os EVENTOS de Campos do Jordão e região (Santo Antônio do Pinhal, São Bento do Sapucaí) de 2026-10-13 a 2027-12-31 que puxam hospedagem: Festival de Inverno (Festival Internacional de Inverno de Campos do Jordão, Auditório Claudio Santoro), Oktoberfest de Campos do Jordão, Festa de Natal/Natal Luz local, Festival de Cerveja, eventos gastronômicos, esportivos (corridas, ciclismo, Audax), religiosos, congressos, shows, Festa das Flores, Festa do Pinhão, e o período da "temporada de inverno" (junho-agosto) com datas típicas. Para cada um: nome, inicio, fim (noites afetadas), tipo "evento", abrangencia (público), impacto_esperado e fonte (site oficial, prefeitura, Visite Campos do Jordão, imprensa). Se 2027 não estiver anunciado, use a data de 2026 e marque confianca "estimado pela edição anterior".` },
  { label: 'web:estacoes', schema: S_PERIODOS, prompt: `${SO_LEITURA}
TAREFA: descreva a SAZONALIDADE anual do turismo em Campos do Jordão por período (mês a mês ou por quinzena), de outubro a dezembro de 2026 e janeiro a dezembro de 2027: alta temporada (inverno: junho a agosto, com o pico em julho), média (maio, setembro, feriados prolongados, Natal e Réveillon), baixa (março-abril fora de feriados, outubro-novembro em semana, primeira quinzena de dezembro, fevereiro fora do Carnaval), clima (frio e chuvas), comportamento de preços dos hotéis em cada período (quanto sobem na alta, ex.: 2 a 3 vezes), antecedência típica de reserva. Entregue um item por período com inicio, fim, tipo ("alta", "média-alta", "média", "baixa", "muito baixa"), impacto_esperado (frase com o porquê e, quando houver, número de ocupação ou de preço), fonte (Prefeitura, Secretaria de Turismo de SP, Visite Campos, reportagens, Booking/Trivago com dados de preço médio por mês, PriceLabs/AirDNA se público). Use WebSearch e WebFetch.` },
  { label: 'pricelabs:mercado-12m', schema: S_PL, prompt: `${SO_LEITURA} Use só ferramentas get_* e market_research do PriceLabs. NUNCA update_*, delete_*, refresh_listing_pricing, accept_nudge, set_*.
CONTEXTO: pms "beds24". Recanto dos Moinhos: listing 350362___722805 (Queen 7), grupo 124405, 4 tipos de quarto (listings 350362___722805, 722806, 722807, 722808). Villa Dolce Amore: 350364___722813 (King Spa 7), 722814, 722815.
TAREFA: (1) com get_neighbourhood_data (e get_listing_neighborhood_market, se existir) pegue a ocupação do mercado por dia dos próximos 365 dias e do mesmo dia do ano passado para os dois listings principais; agregue por MÊS (média) de 2026-10 a 2027-10 e liste os 15 maiores picos diários (data, ocupação, motivo provável: feriado/evento). (2) com get_account_review_report (start_date 2025-10-01, end_date 2026-10-13, recommended_actions false) e get_listing_performance_metrics ou get_bookings_report, pegue a NOSSA ocupação, ADR e receita por mês nos últimos 12 meses, por pousada (se der por listing, some/média por pousada). (3) teste get_str_index e market_research para "Campos do Jordão, SP" com pergunta "ocupação e ADR por mês dos últimos 12 meses e sazonalidade"; se market_research devolver requires_selection, NÃO escolha opção: apenas registre as opções em limitacoes. Devolva uma linha por pousada e mês em mensal, os picos, as fontes que funcionaram e o que não existe.` },
]
const res = await parallel(tarefas.map(t => () => agent(t.prompt, { label: t.label, phase: 'Pesquisa', schema: t.schema })))
const [feriados, eventos, estacoes, pl] = res
log(`Pesquisa: ${res.filter(Boolean).length}/4 ok`)

phase('Síntese')
const S_CAL = {
  type: 'object',
  properties: {
    calendario: { type: 'array', items: { type: 'object', properties: {
      inicio: { type: 'string' }, fim: { type: 'string' }, nome: { type: 'string' },
      nivel: { type: 'string', enum: ['muito baixa', 'baixa', 'média', 'alta', 'muito alta'] },
      faixa_alvo: { type: 'string', enum: ['muito baixo', 'baixo', 'médio', 'alto', 'muito alto'] },
      estadia_minima: { type: 'number' }, antecedencia_tipica_dias: { type: 'number' },
      motivos: { type: 'string' }, evidencia: { type: 'string' }, confianca: { type: 'string', enum: ['alta', 'média', 'baixa'] } },
      required: ['inicio', 'fim', 'nome', 'nivel', 'faixa_alvo', 'motivos', 'evidencia', 'confianca'] } },
    regras_gerais: { type: 'array', items: { type: 'string' } },
    datas_para_quartos_json: { type: 'array', items: { type: 'object', properties: { nome: { type: 'string' }, inicio: { type: 'string' }, fim: { type: 'string' }, tipo: { type: 'string' } }, required: ['nome', 'inicio', 'fim', 'tipo'] } },
    lacunas: { type: 'string' }, resumo: { type: 'string' },
  }, required: ['calendario', 'regras_gerais', 'datas_para_quartos_json', 'resumo'] }
const sintese = await agent(`${SO_LEITURA}
Você recebe quatro pesquisas (abaixo, em JSON). Monte o CALENDÁRIO DE SAZONALIDADE de 2026-10-14 a 2027-12-31 para duas pousadas de Campos do Jordão (Recanto dos Moinhos, 16 unidades, 5 estrelas na Booking, nota 8,4; Villa Dolce Amore, 15 unidades, 3 estrelas, nota 8,0). Metas de ocupação: 7 dias 70%, 15 dias 50%, 30 dias 35%, 45 dias 25%, 60 dias 15%.
Regras do calendário: períodos contíguos sem buraco e sem sobreposição (cada noite cai em um só período); separe semana de fim de semana só quando o nível for diferente (ex.: "novembro, dias de semana" baixa e "novembro, sextas e sábados" média); feriados e eventos como períodos próprios; nivel pela demanda esperada; faixa_alvo = em que faixa de preço do mercado (quintis da Booking: muito baixo, baixo, médio, alto, muito alto) nosso preço deve ficar para vender no ritmo da meta (demanda muito alta pede faixa alta ou muito alta; demanda baixa pede faixa baixa; nunca abaixo de "baixo" fora de última hora); estadia_minima sugerida (1, 2 ou 3 noites); antecedencia_tipica_dias; motivos (1 a 2 frases) e evidencia (fonte + número). Em regras_gerais escreva 6 a 10 regras de sazonalidade reutilizáveis (ex.: "Festival de Inverno: 2 a 3 vezes o preço de dia útil de baixa, estadia mínima 2, antecedência de 30 a 60 dias"). Em datas_para_quartos_json liste cada feriado e evento com nome, inicio e fim das NOITES e tipo ("feriado", "evento", "ferias", "temporada"). Em lacunas o que ficou sem fonte.
PESQUISAS:
FERIADOS: ${JSON.stringify(feriados)}
EVENTOS: ${JSON.stringify(eventos)}
ESTAÇÕES: ${JSON.stringify(estacoes)}
PRICELABS: ${JSON.stringify(pl)}`, { label: 'sintese-calendario', phase: 'Síntese', schema: S_CAL })
return { feriados, eventos, estacoes, pricelabs: pl, sintese }

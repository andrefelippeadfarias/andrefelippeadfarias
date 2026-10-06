export const meta = {
  name: 'coleta-pricelabs',
  description: 'Coleta somente leitura para a skill gestao-receita-pousadas: precos e ocupacao dos 7 quartos, reservas, logs, envio; no modo completo tambem mercado, concorrentes da Booking e eventos',
  whenToUse: 'Chamado pela skill gestao-receita-pousadas no inicio de uma rotina completa ou de um pedido do dono.',
  phases: [
    { title: 'Coleta', detail: 'quartos, contexto e (modo completo) mercado, concorrentes e eventos em paralelo' },
  ],
}

// args: { hoje: 'AAAA-MM-DD', fim: 'AAAA-MM-DD' (hoje+59), desde: 'AAAA-MM-DD' (reservas feitas desde),
//         modo: 'rotina' | 'completo', buscas: [{label, checkin, checkout}], eventos: bool, rooms?: [...] }
const A = args || {}
const HOJE = A.hoje
const FIM = A.fim
const DESDE = A.desde || A.hoje
const MODO = A.modo || 'rotina'
const ROOMS = A.rooms || [
  { id: '350362___722805', short: 'Q7', name: 'Queen (7)', units: 7 },
  { id: '350362___722806', short: 'Double', name: 'Double', units: 6 },
  { id: '350362___722807', short: 'Afrodite', name: 'Afrodite (1 unidade)', units: 1 },
  { id: '350362___722808', short: 'Q2', name: 'Queen (2)', units: 2 },
  { id: '350364___722813', short: 'VK7', name: 'Villa King Spa (7)', units: 7 },
  { id: '350364___722814', short: 'Balcony', name: 'Balcony (6)', units: 6 },
  { id: '350364___722815', short: 'VK2', name: 'Villa King Spa (2)', units: 2 },
]

if (!HOJE || !FIM) throw new Error('args.hoje e args.fim sao obrigatorios')

const READONLY = `REGRA ABSOLUTA: somente leitura. Use apenas ferramentas get_* do PriceLabs e a busca do Booking.com. NUNCA chame update_*, delete_*, refresh_listing_pricing, accept_nudge, update_customizations, map/unmap ou set_*. Carregue as ferramentas MCP com ToolSearch (ex.: "select:mcp__PriceLabs__get_listing_prices"). Se o servidor MCP estiver desconectado, chame ToolSearch de novo (ele espera reconectar) e tente ate 3 vezes. Copie numeros exatamente como vieram; nao invente.`

const LISTING_SCHEMA = {
  type: 'object',
  properties: {
    listing_id: { type: 'string' },
    last_refreshed_at: { type: 'string' },
    error: { type: 'string' },
    days: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          date: { type: 'string' }, price: { type: 'number' }, user_price: { type: 'number' },
          uncustomized_price: { type: 'number' }, min_stay: { type: 'number' },
          multi_unit_occupancy: { type: 'string' }, booking_status: { type: 'string' },
          unbookable: { type: 'number' }, demand_desc: { type: 'string' },
        },
        required: ['date', 'price', 'user_price', 'min_stay', 'booking_status'],
      },
    },
    overrides: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          date: { type: 'string' }, price: { type: 'string' }, price_type: { type: 'string' },
          min_price: { type: 'number' }, min_stay: { type: 'number' }, reason: { type: 'string' },
        },
        required: ['date'],
      },
    },
  },
  required: ['listing_id', 'days', 'overrides'],
}

const CONTEXTO_SCHEMA = {
  type: 'object',
  properties: {
    bookings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          listing_id: { type: 'string' }, reservation_id: { type: 'string' }, check_in: { type: 'string' },
          check_out: { type: 'string' }, no_of_days: { type: 'number' }, rental_revenue: { type: 'string' },
          total_cost: { type: 'string' }, ota_commission: { type: 'string' }, channel: { type: 'string' },
          booked_date: { type: 'string' }, status: { type: 'string' }, cancelled_on: { type: 'string' },
        },
        required: ['listing_id', 'check_in', 'check_out', 'status', 'booked_date'],
      },
    },
    logs: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          created_at: { type: 'string' }, action: { type: 'string' }, listing_id: { type: 'string' },
          device_type: { type: 'string' }, via_api: { type: 'boolean' },
        },
        required: ['created_at', 'action'],
      },
    },
    sync: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          listing_id: { type: 'string' }, push_enabled: { type: 'boolean' }, last_date_pushed: { type: 'string' },
          last_refreshed_at: { type: 'string' }, min: { type: 'number' }, base: { type: 'number' }, max: { type: 'number' },
        },
        required: ['listing_id', 'push_enabled', 'last_date_pushed'],
      },
    },
    notes: { type: 'string' },
  },
  required: ['bookings', 'logs', 'sync'],
}

const COMP_SCHEMA = {
  type: 'object',
  properties: {
    searches: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          label: { type: 'string' }, checkin: { type: 'string' }, checkout: { type: 'string' },
          properties_found: { type: 'number' }, availability_note: { type: 'string' },
          price_total_min: { type: 'number' }, price_total_median: { type: 'number' }, price_total_max: { type: 'number' },
          comparables: {
            type: 'array',
            items: {
              type: 'object',
              properties: {
                name: { type: 'string' }, review_score: { type: 'number' }, price_total: { type: 'number' },
                hydro_or_spa: { type: 'string' }, note: { type: 'string' },
              },
              required: ['name', 'price_total'],
            },
          },
          ours: {
            type: 'array',
            items: {
              type: 'object',
              properties: {
                property: { type: 'string' }, available: { type: 'boolean' }, price_total: { type: 'number' },
                rank_note: { type: 'string' },
              },
              required: ['property', 'available'],
            },
          },
        },
        required: ['label', 'checkin', 'checkout', 'comparables', 'ours'],
      },
    },
    notes: { type: 'string' },
  },
  required: ['searches'],
}

const MARKET_SCHEMA = {
  type: 'object',
  properties: {
    days: {
      type: 'array',
      items: {
        type: 'object',
        properties: { date: { type: 'string' }, market_occupancy: { type: 'number' }, market_occupancy_stly: { type: 'number' } },
        required: ['date'],
      },
    },
    notes: { type: 'string' },
  },
  required: ['days'],
}

phase('Coleta')

const tarefas = ROOMS.map(r => () => agent(
  `${READONLY}

Tarefa: dados do quarto "${r.name}" (listing_id "${r.id}", pms "beds24") no PriceLabs.
1) mcp__PriceLabs__get_listing_prices com listing_id="${r.id}", pms_name="beds24", date_from="${HOJE}", date_to="${FIM}". Se vier LISTING_NO_DATA ou erro, tente mais uma vez; se persistir, devolva days vazio e explique em error.
2) mcp__PriceLabs__get_listing_date_overrides com listing_id="${r.id}", pms="beds24", start_date="${HOJE}", end_date="${FIM}".
Devolva TODOS os dias de ${HOJE} a ${FIM} com: date, price, user_price, uncustomized_price, min_stay, multi_unit_occupancy (string "vendidas/total"; vazia se nao existir), booking_status (pode ser vazia), unbookable, demand_desc. Em overrides: date, price (string), price_type, min_price, min_stay e reason (ate 80 caracteres). last_refreshed_at = campo do topo da resposta de precos.`,
  { label: `quarto:${r.short}`, phase: 'Coleta', schema: LISTING_SCHEMA, effort: 'low' }
))

tarefas.push(() => agent(
  `${READONLY}

Tarefa: contexto operacional no PriceLabs (pms "beds24").
A) mcp__PriceLabs__get_pms_reservations com pms="beds24", booked_start_date="${DESDE}", booked_end_date="${HOJE}" (reservas FEITAS nesse periodo; pagine se next_page=true). Cada reserva em "bookings": listing_id, reservation_id, check_in, check_out, no_of_days, rental_revenue, total_cost, ota_commission, channel=booking_channel, booked_date, status=booking_status, cancelled_on.
B) mcp__PriceLabs__get_user_logs com log_type="listing", start_date="${DESDE}", end_date="${HOJE}", limit=200. Em "logs": created_at, action, listing_id (entity.listing_id), device_type (metadata.device_type) e via_api (true se a action comecar com "api_"). NAO copie e-mails.
C) mcp__PriceLabs__get_listing_data (question qualquer). Em "sync", para cada um dos 7 quartos: listing_id, push_enabled, last_date_pushed, last_refreshed_at, min, base, max.
Em notes: quantas reservas e cancelamentos, e qualquer erro.`,
  { label: 'contexto', phase: 'Coleta', schema: CONTEXTO_SCHEMA, effort: 'low' }
))

const completo = MODO === 'completo'
if (completo) {
  tarefas.push(() => agent(
    `${READONLY}

Tarefa: ocupacao do mercado no PriceLabs. Chame mcp__PriceLabs__get_neighbourhood_data com listing_id="350362___722805" e pms="beds24" (leia o schema; peca ocupacao futura se houver opcao). Os dados podem vir aninhados (data.data...). Extraia, para cada data de ${HOJE} ate 30 dias depois, market_occupancy (0 a 100) e, se houver, market_occupancy_stly (mesmo periodo do ano passado). Em notes, de onde saiu cada numero.`,
    { label: 'mercado', phase: 'Coleta', schema: MARKET_SCHEMA, effort: 'low' }
  ))
  const buscas = (A.buscas || []).map(b => `- "${b.label}": checkin ${b.checkin}, checkout ${b.checkout}`).join('\n')
  if (buscas) {
    tarefas.push(() => agent(
      `${READONLY}

Tarefa: concorrentes na Booking.com para Campos do Jordao (SP), 2 adultos, 1 quarto, BRL, user_country_code "br", user_locale "pt-br". Use mcp__Booking_com__accommodations_search (carregue com ToolSearch).
Buscas:
${buscas}
Para cada busca faca (a) busca geral e (b) busca com facilities HOT_TUB_JACUZZI. Calcule min, mediana e max do preco TOTAL entre os concorrentes distintos (sem os nossos). Liste ate 8 comparaveis do nosso nivel (boutique/romantico, hidro ou spa, nota alta): nome, nota, preco total, hidro/spa. Para os NOSSOS ("Pousada Recanto dos Moinhos" e "Villa Dolce Amore"), faca uma busca por hotel_names com destination "Campos do Jordao, Brazil" e diga available (true/false), price_total e posicao/observacao. Resultado "hotel_names_no_availability" significa indisponivel. Em notes, limitacoes.`,
      { label: 'concorrentes', phase: 'Coleta', schema: COMP_SCHEMA }
    ))
  }
  if (A.eventos) {
    tarefas.push(() => agent(
      `Pesquisa na web (WebSearch/WebFetch; carregue com ToolSearch "select:WebSearch,WebFetch"). Contexto: pousadas boutique de casais em Campos do Jordao (SP). Hoje e ${HOJE}. Liste eventos em Campos do Jordao nos proximos 30 dias, feriados e emendas escolares (SP, RJ, MG) e previsao de frio/chuva relevante. Para cada achado: o que, datas, relevancia para hospedagem e fonte (URL). Nao invente; se nao achar, diga.`,
      { label: 'eventos', phase: 'Coleta' }
    ))
  }
}

const res = await parallel(tarefas)
const rooms = {}
const faltando = []
ROOMS.forEach((r, i) => {
  const L = res[i]
  if (!L || !L.days || !L.days.length) { faltando.push(r.short); return }
  rooms[r.short] = { ...L, units: r.units }
})
let k = ROOMS.length
const contexto = res[k++] || null
const mercado = completo ? res[k++] || null : null
const concorrentes = completo && (A.buscas || []).length ? res[k++] || null : null
const eventos = completo && A.eventos ? res[k++] || null : null
if (faltando.length) log(`Sem dados: ${faltando.join(', ')}`)
return { hoje: HOJE, fim: FIM, modo: MODO, rooms, faltando, contexto, mercado, concorrentes, eventos }

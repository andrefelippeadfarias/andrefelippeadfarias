# APIs verificadas (25/09/2026)

Fontes oficiais consultadas em 25/09/2026 e conferidas por um segundo agente. Revalide antes de mudar a integração.

## Jev (TypeSafe "System One")

### O modelo gratuito

- O nome `jev-1.13-free` **não existe** em nenhum catálogo oficial.
- O modelo gratuito oficial é `typesafe/jev-1.13:free`, com dois-pontos, servido pelo **OpenRouter**. Preço zero para entrada e saída.
- Em 25/09/2026 a lista de servidores desse modelo no OpenRouter estava vazia. Ele pode estar temporariamente indisponível. O programa verifica isso a cada execução e, sem resposta, não faz nada.
- Limites do OpenRouter para modelos `:free`: 20 chamadas por minuto e 50 por dia, ou 1.000 por dia depois de US$ 10 em créditos comprados. Sete chamadas por dia cabem com folga.
- Alternativa paga, desligada por padrão: TypeSafe direto, modelo `jev-1.13.0`, US$ 0,042 por milhão de tokens de entrada, saída grátis. Sete chamadas de 3 mil tokens custam cerca de US$ 0,001 por dia.

### Contrato da chamada

| Item | OpenRouter (gratuito) | TypeSafe direto (pago) |
|---|---|---|
| URL | `POST https://openrouter.ai/api/v1/systemone` | `POST https://api.typesafe.ai/v1/systemone` |
| Autenticação | `Authorization: Bearer <OPENROUTER_API_KEY>` | `Authorization: Bearer <TYPESAFE_API_KEY>` |
| Modelo | `typesafe/jev-1.13:free` | `jev-1.13.0` (ou `jev-latest`) |
| Contexto | 32 mil tokens | 64 mil por chamada; 32 mil para estado + maior pergunta |

Corpo: `{"state": <texto ou objeto>, "model": "...", "questions": {"<id>": <pergunta>}}`.

| Tipo | Campos da pergunta | Resposta |
|---|---|---|
| `choice` | `instructions`, `criteria` = mapa opção → descrição (até 255 opções) | `choice`, `probabilities` (somam 1), `confidence` 0..1 |
| `score` | `instructions`, `criteria` = lista ordenada de 2 a 10 níveis | `score`, `legend`, `probabilities`, `confidence` |
| `noul` | `instructions`, `criteria` = {`true`, `false`} | `noul` 0..1, sem confiança |

Resposta completa: `{"model": "jev-1.13.0", "answers": {...}, "usage": {"input_tokens", "output_tokens"}}`. O cabeçalho `x-typesafe-request-id` identifica a chamada.

Erros: 401 chave inválida, 403 chave ausente, 422 validação (`{"detail": [...]}`), 429 limite, 402 saldo negativo no OpenRouter, 5xx e 529 sobrecarga. 401, 402, 403 e 422 não se repetem. 429 e 5xx permitem no máximo duas novas tentativas curtas.

Cuidados documentados pelo fabricante:

- O Jev não faz contas, contagens nem comparação de datas. O código calcula e envia faixas com nome.
- As respostas variam um pouco entre chamadas iguais. Perto do limiar, a escolha pode mudar. Use faixa de incerteza e, na dúvida, não aja.
- Perguntas e critérios em inglês dão mais precisão. Os ids das perguntas não são vistos pelo modelo.
- Várias perguntas numa só chamada custam o mesmo estado uma única vez.

## PriceLabs Customer API

- Base: `https://api.pricelabs.co/v1`. Cabeçalho `X-API-Key: <chave>` em todas as chamadas.
- Habilitar: app.pricelabs.co → Account Settings → API Details → Enable → "I Need API Access" → digitar `API`. Custa **US$ 1 por listing por mês**, mais impostos. Com 7 listings, US$ 7 por mês.
- Limites: 60 chamadas por minuto e 1.000 por hora. `refresh_listing`: 3 por listing a cada 24 horas e 10 por minuto na conta. Tempo limite recomendado: 300 segundos.
- Chave ausente ou errada: 403 `API_KEY_MISSING` ou `API_KEY_INVALID`. Algumas páginas documentam 401. Os dois são falha de autenticação.

| Uso no projeto | Chamada | Observações |
|---|---|---|
| Diretório | `GET /listings` | `min`, `base`, `max`, `currency`, `push_enabled`, `last_date_pushed`, `last_refreshed_at` |
| Calendário de 60 dias | `POST /listing_prices` com `{"listings":[{"id","pms","dateFrom","dateTo"}]}` | Resposta é uma lista. Por data: `price`, `user_price` (−1 = indisponível), `min_stay`, `booking_status` ("" = livre), `multi_unit_occupancy` ("3/7"), `unbookable`. Listing pode vir com `error_status` (`LISTING_TOGGLE_OFF`, `LISTING_NO_DATA`, `LISTING_NOT_PRESENT`) |
| Mercado | `GET /listing_metrics?listing_id=&pms_name=` | `data.market_level.occupancy["7"]`. Uma listing por chamada |
| Reservas dos próximos dias | `GET /reservation_data?pms=beds24&start_date=&end_date=` (data de chegada, `end_date` exclusivo) | Traz reservas e cancelamentos com `booked_date` e `cancelled_on`. A API também aceita `booked_start_date`/`booked_end_date` (data da reserva), mas o programa usa a data de chegada para enxergar cancelamentos de reservas antigas. Paginação por `offset` enquanto `next_page` for verdadeiro. Exige a chave do **dono** da conta (subusuário recebe 403) |
| Ler substituições | `GET /listings/{id}/overrides?pms=&start_date=&end_date=` | Campos `date`, `price`, `price_type`, `min_stay`, `reason`, `lead_time_expiry` |
| Criar substituições | `POST /listings/{id}/overrides` | `price` é **texto**; `price_type` obrigatório com `price`; `percent` de −75 a 1000; `currency` obrigatória em valores fixos e igual à do PMS. Se um item falhar, nada é salvo (400) |
| Apagar substituições | `DELETE /listings/{id}/overrides` com corpo `{"pms","overrides":[{"date"}]}` | Apaga **tudo** naquela data. Pode responder 204 vazio ou 200 com corpo |
| Recalcular | `POST /refresh_listing` | Não envia ao PMS. Cota de 3 por listing por dia |

### Sincronização com o Beds24

- Não existe chamada documentada para "Sync Now". As mudanças chegam ao Beds24 na próxima sincronização programada.
- A sincronização diária é gratuita. Cada horário extra custa US$ 1 por listing por mês e é configurado na tela: Account → Settings → Sync Settings → "Specify Your Own Time" → "+Add Hour".
- Cada sincronização recalcula os preços antes de enviar. Por isso o programa não precisa gastar a cota de `refresh_listing` depois de gravar uma substituição.
- O changelog de 2020 cita `/v1/push_prices` e `/v1/push_price_status`. Eles não estão documentados hoje. O projeto **não** os usa.

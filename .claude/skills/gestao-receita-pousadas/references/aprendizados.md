# Aprendizados medidos

Cada item tem data e evidência. Para incluir um item novo: data, o fato, a evidência (número e fonte) e o efeito na regra, se houver. Se um aprendizado mudar um parâmetro, atualize `../dados/quartos.json` e anote em `historico-da-skill.md`.

## Valor real pago e vitrine
| Data | Fato | Evidência |
|---|---|---|
| 28/09 a 05/10 | Valor real ÷ preço enviado, na Booking: Queen (2) 33–39%; Balcony 39–47%; Queen (7) 42–54%; Double 53%; Villa King Spa (7) 38–41%; Villa King Spa (2) 39%. Airbnb na mesma faixa (39–54%). Expedia bem acima. | Reservas do Beds24 (campo total_cost) contra o user_price. Registro: L123–125, L494–501, L593 e L688. |
| 05–06/10 | Última hora de meio de semana: Queen (7) pagou 50% do enviado (R$ 807 por 2 diárias a R$ 800), R$ 330–404 por noite. | Reservas de 05/10. |
| 30/09 | Vitrine da Booking ≈ 53% do enviado na Villa (o "a partir de" da Villa é o quarto mais barato) e ≈ 60% no Recanto (Queen 7). | Registro L238, L275 e L298. |
| 06/10 | Vitrine de 16 a 18/10 ≈ 65–66% do enviado (Queen 7 e Villa King Spa 2). A proporção muda com as ofertas ativas. | Busca na Booking de 06/10. |
| 06/10 10:55 | Depois do envio, a vitrine ficou em: Queen (7) a R$ 640 → R$ 403 (63%); Queen (7) a R$ 1.250 × 2 → R$ 1.519 (61%); Villa King Spa (2) a R$ 720 → R$ 423 (59%); Villa King Spa (2) a R$ 1.200 × 2 → R$ 1.588 (66%). Faixa usual: **59–66% do enviado**. | Busca na Booking por nome, depois do envio das 08:48. |
| 06/10 | O envio diário de 06/10 saiu às 08:48. Uma mudança na Balcony gravada às 09:34 foi enviada às 09:47. | `last_date_pushed`. |
| 06/10 19:56 | **O envio extra não é garantido.** As mudanças da Villa gravadas e recalculadas às 16:10 não tinham sido enviadas 3 h 45 min depois (último envio às 08:49). Mudança gravada depois do envio diário só chega aos canais no envio do dia seguinte, a menos que o dono clique "Sync Now". Provável causa do envio das 09:47: o Real-Time Sync, que só dispara com evento do PMS (reserva, cancelamento ou bloqueio). | `last_date_pushed` dos 3 quartos da Villa; Booking por nome às 19:56 (Villa 07→08/10 a R$ 423 e 16→18/10 a R$ 1.588, iguais às 10:55). |
| — | Fatores usados para pisos (o pior caso): Recanto 0,42, Queen (2) 0,34, Villa 0,39; vitrine da Balcony 0,53. | `quartos.json`. |

## Comportamento do PriceLabs e dos canais
| Data | Fato | Evidência |
|---|---|---|
| 30/09 | Percentual respeita o piso de fim de semana (150% da base); preço fixo passa por cima dele. | Portão 1: VKS7 16/10 a R$ 1.536 com piso de R$ 2.220. |
| 06/10 | Preço fixo abaixo do mínimo do anúncio funciona se a substituição levar `min_price` igual ao preço. | Recálculo de 06/10: Queen (7) R$ 640, VKS7 R$ 800 e VKS2 R$ 720 conferidos. |
| 30/09 | A suavização anula percentual em dia útil isolado e dilui o percentual em uma noite só de fim de semana. Nesses casos, use preço fixo. | Queen 13/10: −35% deu R$ 1.405. |
| 06/10 | Pela API, `update_listing_date_overrides` junta os campos novos aos que já existem na data (min_price e min_stay foram mantidos). Pela tela, uma substituição nova apaga a anterior. | Verificação da própria API em 06/10; registro L71 e L617. |
| 30/09 | Preço fixo não sobe quando o recomendado sobe; percentual sobe. Preço fixo pode ficar acima do algoritmo depois que a base muda. | Registro L149 e L329. |
| 25/09 | `refresh_listing_pricing` (recalcular) não envia aos canais e tem limite de 3 por quarto a cada 24 h (erro 429). | apis-verificadas.md; registro L333 e L403. |
| 30/09 a 06/10 | O envio aos canais acontece uma vez por dia, entre 05:40 e 12:42 (no começo de outubro foi às 09:43), ou no Sync Now (só pela tela). `last_date_pushed` mostra o último envio. `user_price` atrasa de minutos a horas. | Registro L108, L356, L422, L469, L600 e L707. |
| 30/09 | O PriceLabs só vê uma reserva nova depois do próximo envio do Beds24. A lista de reservas por data de criação é mais rápida que a ocupação por quarto. | Registro L354 e L592. |
| 01/10 | "Indisponível" pode ser bloqueio, e não reserva. Antes de tirar uma data da escada, confira na lista de reservas. | Erro de 01/10 corrigido (L450). |
| 30/09 e 06/10 | A estadia mínima do PriceLabs não manda sozinha na Booking. O sábado sozinho fica bloqueado no Beds24/Booking por decisão do dono (Regra H). | 5 buscas em 30/09; busca de 06/10 (sábado 10→11 indisponível, sexta 09→10 disponível no Recanto). |
| 07/10 | **Ordem do cálculo do PriceLabs:** mercado (sazonalidade e demanda) → ajustes de ocupação (multi-quarto e do quarto) → percentual da data → suavização → piso e teto. Preço fixo vem por último e passa por cima de tudo. | Detalhamento do recálculo (`parse_reasons_json`), Queen (7), 23/10: R$ 1.769 × −20% = R$ 1.415; a suavização com o sábado levou a R$ 1.666. |
| 07/10 | **A suavização tira a média do bloco:** domingo a quinta e sexta e sábado no Recanto, VK7 e VK2; a semana inteira na Balcony. A data esgotada fica fora da média. O percentual de uma data só "vaza" para o bloco todo; o fixo não vaza. | Média do bloco igual ao preço final em todos os quartos (0 divergência no Recanto e na Villa). Erro medido: VK2 25/10 com −28% sozinha foi a R$ 984, e não R$ 765, porque o bloco era 25, 27, 28 e 29/10 (26/10 esgotado). |
| 07/10 | O `min_price` da data substitui o piso de fim de semana (150% da base): o limiar passa a ser "Min Price (Listing Override)". Sem `min_price` na data, vale o piso de fim de semana (Queen 7 em 09/10: R$ 2.070). | Detalhamento do recálculo de 07/10. |
| 07/10 | O +20% de Finados nos domingos e segundas (01 e 02/11) puxou 03 a 05/11 para R$ 1.482–1.506 na Queen (7) (o normal seria cerca de R$ 1.000) e para R$ 1.836–2.043 na Villa. Efeito da suavização. | Detalhamento do recálculo de 07/10. Rever na rotina completa. |
| 27/09 a 05/10 | O MCP cai com frequência (LISTING_NO_DATA, conector desconectado). Recarregue com ToolSearch e tente de novo; se continuar, registre a rotina perdida. | Registro L104, L107, L155 e L705. |

## Mercado e concorrência
| Data | Fato | Evidência |
|---|---|---|
| 06/10 | Feriado de 12/10: o mercado estava em 32%, 43% e 44% (09 a 11/10), contra 25%, 22% e 19% no mesmo dia do ano passado. O final do ano passado foi 40%, 53% e 34%. Nós estávamos em 45%, 48% e 100%. | get_neighbourhood_data. |
| 06/10 | Com o piso de R$ 1.200 reais, o Recanto era o 2º mais caro de 21 no feriado (R$ 1.816 por noite na vitrine) e ficou 3 dias sem vender. | Busca na Booking; reservas. |
| 06/10 | Em 16 a 18/10 estávamos 47–53% acima da mediana (R$ 690 por noite); domingo 18/10, duas vezes a mediana (R$ 313); meio de semana 13 a 15/10, na mediana. | Busca na Booking. |
| 06/10 16h | Na Booking, mediana dos concorrentes para 2 noites: 07–09/10 R$ 554 (com hidro R$ 788); feriado R$ 1.610 (R$ 2.433); 13–15/10 R$ 1.061 (R$ 1.358); 16–18/10 R$ 1.348 (R$ 1.944); 20–22/10 R$ 646 (R$ 788); 23–25/10 R$ 1.105 (R$ 1.392). A semana 3 é bem mais fraca que a 2, com mercado em 7–9% de ocupação e eleição em 25/10. | Coleta da Booking de 06/10, 16h. |
| 07/10 08h | Booking (2 noites), mediana geral × mediana com hidro: 07–09/10 R$ 576 × R$ 788; feriado R$ 1.550 × R$ 2.400; 13–15/10 R$ 1.029 × R$ 1.291; 16–18/10 R$ 1.479 × R$ 2.001; 20–22/10 R$ 721 × R$ 788; 23–25/10 R$ 1.029 × R$ 1.582. Villa ainda com preços antigos (envio de 06/10): R$ 847, R$ 2.000, R$ 953, R$ 1.588, R$ 1.309 e R$ 2.253. Vitrine da Villa ÷ enviado: 0,53 nos dias úteis e no feriado, 0,59 em 07–08/10 e 0,66 em 16–17/10. | Coleta de 07/10, 08h (18 buscas). |
| 06/10 | A Villa Dolce Amore não lista hidromassagem, Wi-Fi nem aquecimento nas comodidades e não aparece nas buscas com filtro de hidro. O Recanto aparece em 1º. | Busca na Booking. |

## Efeito das mudanças nas vendas (preencher a cada rodada)
| Mudança (data) | Antes → depois | Vendas nos 3 dias seguintes | Leitura |
|---|---|---|---|
| Feriado com piso de R$ 1.200 (03/10) | R$ 2.690 → R$ 2.890 (Queen 7 sexta) | 0 vendas de feriado de 03 a 06/10 | caro demais contra o mercado |
| Feriado com piso de R$ 1.000 (06/10) | R$ 2.890 → R$ 2.390 | 0 vendas até 13:54 de 06/10 (5 h depois do envio) | medir até 09/10 |
| Fim de semana 16–17/10 perto do mercado (06/10) | R$ 1.668 → R$ 1.250 (Queen 7); Double R$ 2.069 → R$ 1.400 | 1 venda em cerca de 3 h: Double, 16–17/10, 2 noites, R$ 630 reais por noite (direto) | resposta rápida ao corte |
| Villa agressiva de 06 a 25/10 (06/10 16h) | Mínimos −15%, dias úteis no mínimo, feriado a R$ 800 reais, fins de semana perto da mediana | 0 vendas até 19:56 de 06/10, mas os preços ainda não tinham chegado aos canais (só no envio de 07/10). Medir a partir do envio, até 09/10 e em 13/10. | |
| Regra I de 06 a 08/10 (06/10) | R$ 800 → R$ 640 (Queen 7); Double R$ 1.132 → R$ 720 | 2 vendas para a mesma noite (06/10): Queen 7 R$ 320 (direto) e Double R$ 360 (Airbnb), 50% do enviado | última hora vende |
| Domingo 18/10 no mínimo (06/10) | Double R$ 1.225 → R$ 900; Afrodite R$ 1.500 (piso) | 2 vendas: Double R$ 382 (Airbnb, 42%) e Afrodite R$ 675 (direto, 45%) | domingo no mínimo vende |

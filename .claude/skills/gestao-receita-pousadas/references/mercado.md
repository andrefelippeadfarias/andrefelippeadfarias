# Mercado, concorrentes e calendário

## Nossas propriedades na Booking
- **Pousada Recanto dos Moinhos - Boutique**: 5 estrelas, nota 8,4 (194 avaliações). Lista Jacuzzi e aparece bem posicionada nas buscas com filtro de hidro.
- **Villa Dolce Amore - Boutique Hotel**: nota 8,1 (178 avaliações). Não lista hidro, Wi-Fi nem aquecimento (pendência na extranet). O "a partir de" da Villa é o quarto mais barato, por isso vale a Regra G.

## Concorrentes de referência (casais, boutique ou romântico, hidro ou spa)
| Hotel | Nota | Observação |
|---|---|---|
| L.A.H. Hostellerie | 9,8 | 5 estrelas; sempre o mais caro (cerca de R$ 2.000 por noite no feriado) |
| Pousada Murano | 9,7 | 5 estrelas, Jacuzzi |
| Carballo Hotel & Spa | 9,7 | 4 estrelas, só adultos |
| Villa Casato Residenza Boutique | 9,0 | 5 estrelas, só adultos, piscina coberta |
| Hotel Serra da Estrela | 9,0 | 4 estrelas, spa |
| Hotel Estoril | 8,8 | 4 estrelas, spa; bom espelho de preço para nós |
| Pousada Boutique Village della Nonna | 9,5 | 4 estrelas, preço mediano |
| Casa Três Rios | 9,5 | boutique |
| Pousada Nacional Inn | 9,1 | 3 estrelas, muita avaliação, barata |
| Pousada Luis XV, Pousada Telhado de Ouro, Le Suisse Elegance | 9,1–9,6 | referência de meio de semana |

## Como pesquisar (MCP da Booking: accommodations_search)
- Destino "Campos do Jordão, Brazil"; 2 adultos; `user_country_code` "br"; `user_locale` "pt-br"; moeda BRL.
- Cada busca devolve no máximo 10 propriedades, então faça duas por data:
  1. uma geral;
  2. uma com `facilities` ["HOT_TUB_JACUZZI"].
- Para os nossos hotéis, busque com `hotel_names` e o mesmo destino. A resposta "hotel_names_no_availability" quer dizer indisponível para aquelas datas, e costuma ser restrição de estadia ou de chegada. O preço que vem é o total da estadia, sem informar quarto, Genius ou taxas.
- Datas típicas por rotina completa:
  - os próximos 2 dias úteis;
  - o próximo fim de semana (sexta a domingo);
  - o próximo feriado, se for em até 30 dias;
  - o fim de semana seguinte.
- As páginas web da Booking, Expedia e Airbnb bloqueiam (erros 403, 429 e 410). Use só o conector.

## Calendário 2026 (noites de feriado em `quartos.json`)
- Nossa Senhora Aparecida: segunda 12/10; noites de 09 a 11/10.
- Finados: segunda 02/11; noites de 30/10 a 01/11. O dono pôs +20% e 3 noites de 30/10 a 02/11. A Prefeitura de SP deu ponto facultativo em 30/10, e a rede estadual de SP folga em 28/10.
- Proclamação da República: domingo 15/11; noites de 13 e 14/11.
- Consciência Negra: sexta 20/11; noites de 19 a 21/11.
- Natal (noites de 24 e 25/12) e Réveillon (noites de 30/12 a 01/01).
- Segundo turno presidencial no domingo 25/10: a demanda do sábado 24/10 cai, porque o eleitor vota onde mora.

## Eventos em Campos do Jordão já mapeados (outubro de 2026)
- Oktoberfest no Parque Capivari, de 15 a 18/10.
- Corrida WTR (trail, MTB e duathlon), 17 e 18/10.
- Festival Curta, de 17 a 24/10.
- Abertura do Natal em 30 e 31/10.
- Recesso escolar da rede estadual de MG de 13 a 16/10.
- Mackenzie e outras escolas de SP emendam a terça 13/10.

## Padrões de demanda observados
- O feriado vende cedo. Nos últimos dias sobram unidades caras: com o preço acima do 2º mais caro, não vende.
- Domingo e meio de semana sem evento têm mediana de R$ 300 a R$ 550 por noite na vitrine, abaixo do nosso mínimo: segure no mínimo e use a Regra I.
- Frio e chuva puxam casais para suítes com hidro. Use isso como gancho de campanha no canal direto.

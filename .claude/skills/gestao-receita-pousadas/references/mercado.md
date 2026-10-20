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

## Referência hidro+vista a até 3 km do Capivari (dono, 19/10)
- **Classe A (12 hotéis, varredura de 19/10):** Hotel Ascona (0,4 km), Pousada das Lavandas (0,4), Villa Casato Residenza Boutique (0,9), Matsubara Pousada (0,9), L.A.H. Hostellerie (1,3), DOM Hotel (1,4), Secreto Boutique Hotel (1,5), Europa Hotel (1,5), Villa Belle Chalés (1,5), Pousada Cafe Poesia (1,9), Pousada Murano (2,2), Chalé Villa Nature (2,3). Classe B (hidro só em algumas unidades, vista não confirmada no mesmo quarto): Pousada Big Bear, Pousada Villa Capivary. IDs e medianas por período em `dados/concorrentes_hidro_vista.json`.
- **Quarto com hidro × Recanto (19/10 tarde, escada):** 23-25/10 R$ 935 × R$ 825 (-12%); Finados R$ 1.604 × R$ 1.473 (-8%); 06-08/11 R$ 1.171 × R$ 1.163 (-1%); 11/11 R$ 954 × R$ 642 (-33%); Consciência Negra R$ 1.604 × R$ 1.428 (-11%); 04-06/12 R$ 1.255 × R$ 1.093 (-13%). A leitura da manhã (quarto mais barato: R$ 774, R$ 1.250, R$ 715, R$ 295, R$ 1.579, R$ 600) estava errada para esta comparação.
- **Quarto com hidro de cada hotel e posição na lista de quartos (Booking, 19/10):** Ascona Suite with Whirlpool (5º); Lavandas Deluxe with Spa Bath (4º); Villa Casato King Suite with Spa Bath (4º); Matsubara Suite with Spa Bath (4º); L.A.H. Double Room with Spa Bath (2º); DOM Deluxe Suite with Spa Bath (4º); Secreto Premium Suite with Spa Bath (3º; a Comfort não tem); Europa Double Room with Spa Bath (5º); Villa Belle Deluxe Suite with Spa Bath (3º); Cafe Poesia (todos com hidro); Murano Double Room with Spa Bath (2º); Villa Nature (chalé único).
- **Como refazer (segunda, na rotina completa, ou em pedido do dono):**
  1. **O conector não tem os filtros de quarto do site** ("Banheira de hidromassagem" e "Vista para a montanha" no quarto): cada busca devolve só o quarto mais barato de cada hotel. As páginas da Booking bloqueiam robôs e os termos proíbem (19/10: 403 com aviso); não tente navegador. Use a **escada de preço mínimo**: `accommodations_search` com `hotel_names` (os 12 da classe A em 2 lotes, mais o Recanto) e `destination` "Campos do Jordão, Brazil", 2 adultos, 1 quarto, BRL, pt-br, e `price.minimum` por noite sem mínimo, 500, 800, 1100, 1500 (e 2000, 2500, 3000 para quem ainda não chegou no quarto com hidro). Cada degrau revela o próximo quarto; o preço do quarto com hidro é o k-ésimo degrau distinto, com k = posição dele na lista acima. Faça por um agente (são dezenas de buscas). Para o Europa Hotel use "Europa Hotel Campos do Jordao" (o nome sozinho cai em Brasília).
  2. Confira a distância em linha reta (corte de 3,0 km) e, para hotel novo, pergunte à Booking (`answer_property_qa_by_ids_v2`) se o quarto tem banheira de hidromassagem privativa e vista para as montanhas; as duas confirmadas = classe A.
  3. Grave a mediana da classe A e o preço do Recanto da mesma busca por período em `dados/concorrentes_hidro_vista.json` (`periodos`, `atualizado_em`).
  4. `python3 scripts/concorrencia_hidro_vista.py --coleta <saida mesclada> --hoje <hoje> --saida <rascunho>/plano_comp.json`, junte ao plano e passe pelo `validar_plano.py`.
- **A Villa não aparece nessa busca:** a extranet não lista hidromassagem (pendência do dono). Enquanto isso, a vitrine da Villa é estimada.

## Base do dono pela busca filtrada da Booking (20/10, a fonte mais precisa)
- **Como chega:** o dono abre no navegador dele a URL de `dados/base_booking_dono.json` → `url_filtros` (hidro no quarto, menos de 3 km, nota 8+, café incluído), troca `checkin` e `checkout`, salva a página em PDF e manda. A Skill **não** abre a Booking por navegador nem Playwright: a Booking bloqueia robôs e proíbe isso nos termos (403 de 19/10).
- **Extração:** `pip install pymupdf` e `python3 -I scripts/base_booking_pdf.py ARQUIVO.pdf --noites N --json saida.json` (resumo com mediana, quartis, faixas de nota e o percentil dos nossos). Se o PDF não trouxer as datas, deduza pelo riscado do Recanto ÷ enviado da Q7 (≈ 0,81). Detalhe da leitura: spans (nome do hotel: fonte 10, cor 27876; preço final: fonte 10, cor 1710618; riscado: fonte 6, cor 13898014; "+R$ X em impostos" somar; nota: fonte 8, cor 11250603). Juntar nome quebrado em duas linhas e cards partidos entre páginas. O preço vem por estadia: dividir pelas noites.
- **Atenção:** o PDF mostra a vitrine de quem está logado como Genius nível 3, a mais descontada. Compare com o nosso preço na mesma página, nunca com o preço do conector.
- **30/11 a 02/12 (segunda e terça), 70 concorrentes:** mediana R$ 646/noite (25% a 75%: R$ 423 a R$ 1.094); nota 9+ R$ 736; nota 8 a 8,9 R$ 389. Recanto R$ 350 (percentil 10; Q7 enviado R$ 852, vitrine 0,41 do enviado). Villa R$ 522 (percentil 37).
- **27 a 29/11 (sexta e sábado), 59 concorrentes:** mediana R$ 1.022/noite (25% a 75%: R$ 721 a R$ 1.550); nota 9+ R$ 1.229; nota 8 a 8,9 R$ 721. Recanto R$ 749 (percentil 29; desconto de 47,5%). Villa R$ 995 (percentil 46; desconto de 32%). Concorrentes com 19,5% de desconto na mediana.
- **Finados, 30/10 a 02/11 (3 diárias), 48 concorrentes:** mediana R$ 1.100/noite (25% a 75%: R$ 871 a R$ 1.516); nota 9+ R$ 1.272; nota 8 a 8,9 R$ 922. Recanto R$ 1.036 (percentil 44; desconto de 46%). Villa R$ 1.586 (percentil 81; desconto de 30%).
- **Desconto do riscado ao final:** concorrentes 15% na mediana; Recanto 50% (R$ 1.395 → R$ 700 nas 2 noites); Villa 35% (R$ 1.611 → R$ 1.045). O empilhamento de promoções da Booking no Recanto é mais que o triplo do mercado.

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

## Sazonalidade e faixas de preço (13/10/2026)
- **Calendário de sazonalidade** em `dados/sazonalidade.json` (77 períodos de 14/10/2026 a 31/12/2027, 55 feriados e eventos, regras gerais e mercado mês a mês). Consulte com `python3 scripts/sazonalidade.py hoje --dias 60` ou `... data AAAA-MM-DD`.
- **Mercado mês a mês** (ocupação do mercado no PriceLabs, vizinhança do Recanto | da Villa; ADR em R$): out/25 29|35 (571|516); nov 35|42 (640|547); dez 43|50 (733|703); jan/26 36|45 (645|583); fev 29|34 (652|573); mar 27|34 (563|511, o menor); abr 37|42 (696|651); mai 42|46 (691|659); jun 50|54 (834|788); **jul 62|67 (835|819, pico)**; ago 36|44 (691|637); set 37|40 (695|634). Mercado de fim de semana: sex/sáb têm cerca de 2 vezes a ocupação e o preço de dom a qui (quarta R$ 247 contra sábado R$ 511 no KAYAK). Julho vale 2 a 2,6 vezes o dia útil de março.
- **Picos declarados pela Prefeitura:** Réveillon 95%, Corpus Christi 95%, Dia dos Namorados 95%, 9 de Julho 90% a 95%, Consciência Negra 80%, Natal 70% a 80%. Antecedência típica: 60 a 90 dias nos picos, 30 a 45 nos feriadões, 7 a 20 nos vales.
- **Nossa posição (account review de 12 meses):** ocupação 40,3% contra 38,4% do mercado, com diária média 8% abaixo (R$ 632). Em setembro de 2026: Recanto 59,8% a R$ 635; Villa 49,8% a R$ 636.
- **Faixas de preço de mercado** (`dados/mercado_faixas.json`, refeito na revisão semanal pela varredura): cortes dos quintis por data e por conjunto (geral, pares do Recanto, pares da Villa).
  | Conjunto | Tipo de dia | muito baixo até | baixo até | médio até | alto até | muito alto acima de |
  |---|---|---|---|---|---|---|
  | Pares do Recanto | semana | R$ 329 | R$ 727 | R$ 1.258 | R$ 1.787 | R$ 1.787 |
  | Pares do Recanto | sexta e sábado | R$ 530 | R$ 719 | R$ 1.165 | R$ 1.679 | R$ 1.679 |
  | Pares da Villa | semana | R$ 221 | R$ 305 | R$ 480 | R$ 744 | R$ 744 |
  | Pares da Villa | sexta e sábado | R$ 321 | R$ 478 | R$ 572 | R$ 931 | R$ 931 |
  | Geral | semana | R$ 238 | R$ 431 | R$ 776 | R$ 1.683 | R$ 1.683 |
  | Geral | sexta e sábado | R$ 322 | R$ 506 | R$ 724 | R$ 1.204 | R$ 1.204 |

  Preço por noite na vitrine da Booking (2 adultos, quarto mais barato de cada propriedade), varredura de 13/10/2026 para datas de 20/10 a 10/12. Feriados, Natal, Réveillon e última hora ainda sem varredura própria (usam os cortes de fim de semana até a retomada). Classificação dos nossos quartos: `python3 scripts/faixas_de_preco.py quartos <coleta> dados/mercado_faixas.json --dias 21`.

## Pesquisa com agentes de IA (como refazer)
- **Concorrência inteira:** Workflow `varredura-concorrencia` (script salvo em `.claude/workflows/varredura-concorrencia.js`): para cada conjunto de datas, dois agentes varrem a Booking por faixa de preço (dividindo a faixa ao meio sempre que vierem 10 resultados), mais a busca com hidro, a busca dos nossos hotéis e, em 3 datas de referência, a busca por tipo de acomodação; um agente lê o mercado e o compset do PriceLabs. Saída: `faixas_de_preco.py mercado <saida> --json dados/mercado_faixas.json`.
- **Sazonalidade:** Workflow `sazonalidade-campos` (`.claude/workflows/sazonalidade-campos.js`): quatro pesquisadores (feriados oficiais e férias escolares; eventos; estações e preços; mercado e histórico no PriceLabs) e um sintetizador que monta o calendário. Saída vai para `dados/sazonalidade.json` (validar com `sazonalidade.py validar`).
- Fontes que funcionaram: Portaria MGI de feriados, ANBIMA 2027, Alesp (9 de Julho), TRT-15 (feriados municipais), SEDUC (férias), Prefeitura e Band Vale (ocupação declarada), KAYAK (preço por mês), PriceLabs `get_neighbourhood_data` (ocupação do mercado por dia e do ano anterior), `get_account_review_report`, `get_bookings_report`. `market_research` do PriceLabs não está habilitado na conta (pedir ao suporte, é grátis em beta).

## Calendário de feriados e eventos
- A lista viva é `dados/quartos.json` → `feriados` (noites de feriado com piso) e `dados/sazonalidade.json` → `datas` (55 feriados, eventos, férias e temporadas até 2027). Não repita datas aqui.
- Lembretes de leitura: Proclamação da República 2026 cai no domingo 15/11, então 13 e 14/11 são sexta e sábado comuns (faixa médio, padrão de qualidade R$ 1.000, sábado 2 noites); Natal e Réveillon são picos em qualquer dia da semana; o segundo turno presidencial (domingo 25/10/2026) derruba o sábado 24/10, porque o eleitor vota onde mora.

## Padrões de demanda observados
- O feriado vende cedo. Nos últimos dias sobram unidades caras: com o preço acima do 2º mais caro, não vende.
- Domingo e meio de semana sem evento têm mediana de R$ 300 a R$ 550 por noite na vitrine, abaixo do nosso mínimo: segure no mínimo e use a Regra I.
- Frio e chuva puxam casais para suítes com hidro. Use isso como gancho de campanha no canal direto.

## Visibilidade e canais (auditoria de 06/10/2026, só leitura)
- **Busca no Google:** nenhuma das duas aparece nas buscas genéricas ("pousada com hidromassagem", "suíte com hidro casal", "pousada romântica"). Exceção: o Recanto aparece em 15º no Maps em "hotel boutique Campos do Jordão". Topo do Maps nessas buscas: Grom's Village, Campos dos Holandeses, Kaliman, Alpenhaus, Campos de Provence, La Toscana, Chateau La Villette, todos com nota de 4,5 a 5,0.
- **Notas no Google:** Recanto 4,1 e Villa 4,0.
- **Google Hotels:** o site oficial é a oferta MAIS CARA.
  - Recanto: US$ 129 no site contra US$ 74–99 em Traveluro, trivago DEALS, Central de Reservas, Agoda e Etrip.
  - Villa: US$ 145 no site contra US$ 99 na Booking.
  - Há tarifa de revenda vazando (atacadistas/pacotes via Beds24 ou Expedia).
  - A Booking nem aparece como parceira do Recanto no Google Hotels.
- **Perfil da Empresa no Google:**
  - categorias erradas ("Butique", "Loja de macarrão", "Pizzaria", "Cafeteria");
  - Recanto com endereço antigo (R. Januário Pereira);
  - Villa sem o atributo de banheira de hidromassagem e com "Piscina" marcado.
- **Booking:**
  - existem anúncios antigos com notas melhores: Recanto 9,3 com 1.138 avaliações e Villa 8,5 com 283 (provavelmente inativos). Vale pedir ao suporte para reativar ou mesclar com os atuais;
  - a Villa não lista Jacuzzi, Wi-Fi nem café da manhã;
  - os quartos "Queen" do Recanto têm cama king;
  - a Afrodite não se distingue das outras suítes.
- **Airbnb** (anfitrião 382819269, nota 4,66, 228 avaliações, 7 anúncios):
  - dois anúncios com o tipo "Moinho de vento";
  - um diz "banheiro compartilhado" e tem o pino do mapa errado;
  - títulos sem marca, sem "hidromassagem" e sem "Campos do Jordão";
  - a "Mansão" (sem hidro) custa mais que a suíte com hidro.
- **Expedia/Hoteis.com:** a Villa aparece com 18 quartos (são 15) e com endereço divergente.
- **Duplicados da Villa:** TripAdvisor d27796379 e Trivago 100-47648928.
- **Omnibees:** os motores antigos (13841 e 19543) seguem no ar, com nomes, endereço e telefone diferentes.
- **Nome, endereço e telefone** divergem entre os canais (Candelas ou Candeias; "79 161").
- **Sites próprios:**
  - URLs antigas dão erro 404;
  - "HotelClaw Beta" aparece no topo;
  - não têm marcação estruturada de hotel (schema);
  - fala-se em "imersão" e em "hidromassagem" para a mesma banheira.
- Bloqueados para pesquisa automática: Expedia, Hoteis.com, TripAdvisor e Decolar (403/429). Google Busca exige JavaScript (usar o Maps).

## Preços por canal (pesquisa de 06/10/2026 à tarde, antes do envio da estratégia agressiva da Villa)
- **Villa na Expedia, Hoteis.com e Decolar:** tarifa fixa de R$ 2.037 por noite no meio de semana e R$ 4.075 no fim de semana e no feriado, de 3 a 5 vezes o preço da Booking. Não varia com a data. A Villa está, na prática, fora desses canais. Suspeita: o quarto ou a tarifa ligada à Expedia no Beds24 não recebe o preço do PriceLabs.
- **Recanto na Expedia e na Hoteis.com:** 38–44% acima da própria Booking em todas as datas (provável multiplicador de canal no Beds24). Os concorrentes ficam entre −18% e +16% contra a Booking deles.
- **Site oficial:** 50–89% mais caro que a Booking (recebe o preço cheio, sem os descontos da OTA). Nos concorrentes (Murano, L.A.H., Carballo, Estoril, Canada Lodge, Geada), o site é o canal mais barato.
- **Airbnb, mediana por noite das suítes com hidro (1 quarto, com taxas):**
  - meio de semana R$ 555 (P75 R$ 715);
  - fim de semana R$ 825 (P75 R$ 1.050);
  - feriado R$ 1.089 (P90 cerca de R$ 1.450). No feriado sobravam só 16 chalés com hidro em Campos.
- **Airbnb, nossos anúncios:**
  - os "premium" (Afrodite/Panorâmica do Recanto, "Lua de Mel" da Villa) ficam acima do P75, com poucas avaliações;
  - os anúncios por unidade estão na faixa;
  - há preço dobrado entre anúncios do mesmo prédio na mesma data.
- **Expedia, mediana das pousadas com hidro:** meio de semana R$ 694, fim de semana R$ 1.161, feriado R$ 1.320.
- **Booking, mediana da mesma amostra:** meio de semana R$ 679, fim de semana R$ 1.100, feriado R$ 1.256.
- **Notas no Google:** Recanto 4,1 (341 avaliações), Villa 4,0 (201). Concorrentes de 4,5 a 5,0.
- **Fontes que funcionam:**
  - Airbnb: o HTML bruto via curl (busca com `amenities[]=25`, de hidro, 15 páginas) traz os dados embutidos;
  - Google Hotels: as páginas de preço de cada hotel (`/travel/hotels/entity/<id>/prices?hl=pt-BR&gl=br`) mostram o preço por canal em R$. IDs: Recanto `ChoI1dWu6_WU4siuARoNL2cvMTFxcTI0dGJfOBAB`, Villa `ChkI7tXx2bqr6od-Gg0vZy8xMXZ4MGx3YjY5EAE`.
  - Expedia, Hoteis.com e Decolar bloqueiam acesso direto (429/403).

## Anúncios por quarto: Queen 7 × Double (análise de 07/10/2026, só leitura)
- **Booking:**
  - 4 quartos: três "Queen Room with Spa Bath" (30, 35 e 40 m²) e um "Double Room with Spa Bath" (30 m²).
  - Todos com cama king, a mesma lista de comodidades e a mesma descrição genérica.
  - O "a partir de" (R$ 540 em 20/10) é a Queen 7, que só ganha pelo preço. Por isso vende quase só na última hora.
- **Airbnb, anúncios ligados pelo calendário** (confiança média; confirmar no Beds24):
  - Double = 1761651514687322007, "Suíte Premium - Vista para o Por do Sol e Banheira". 3º lugar na busca com hidro; 49 fotos; tipo "Moinho de vento"; pino e texto da Villa.
  - Queen 7 = 1760278410253811726, "Hidro e Pôr do Sol: O Refúgio Romântico Perfeito". 54º lugar (página 3); diz "banheiro compartilhado" e "1 queen + 1 solteiro"; as 3 primeiras fotos são do deck; pino na Villa.
  - Afrodite e Queen (2) estão no anúncio de pousada 1755047981565641196 / 1755046111583719490.
  - Os anúncios 1439757086392620947, 1632051425580163508 e 1790820536724720410 são da Villa.
- **Airbnb, preço exibido:** a Double aparece mais barata que a Queen 7 (R$ 450 contra R$ 520 em 20/10), o contrário do que o PriceLabs envia. A proporção Airbnb ÷ enviado varia de 0,35 a 0,70.
- **Risco:** em 11/10, com tudo esgotado, o anúncio da Queen 7 e o "Suite Luxuosa", da Villa, apareciam reserváveis no Airbnb (R$ 605 e R$ 336). Possível overbooking ou calendário fora de sincronia no Beds24.
- **Mercado no Airbnb, suítes com hidro em 20/10:** mediana R$ 630 (P25 R$ 549, P75 R$ 785).

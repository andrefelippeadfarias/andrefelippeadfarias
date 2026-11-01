# Dezembro de 2026: análise completa e estratégia para bater a meta com a menor lotação

Feita em 01/11/2026 (19:00-19:45 BRT), a pedido do dono: "refinar a estratégia para dezembro ... análise completa do mês de forma que possamos superar o objetivo e fazer ainda uma comparação com o mercado com o objetivo de atingir o faturamento desejado de cada hotel com o mínimo de lotação".

**Fontes (todas somente leitura):** livro do Beds24 (receita de hospedagem, confirmadas + novas); calendários do PriceLabs recalculados em 01/11 20:40 UTC; mercado do PriceLabs (`get_neighbourhood_data` e `get_listing_neighborhood_market`, conjunto Airbnb, e a categoria R de quartos); varredura da Booking pelo conector oficial em 01/11 (77 concorrentes do perfil, com hidro, vista e café, até 3 km do Capivari, 10 estadias de dezembro, 80 buscas; o Recanto pelas coordenadas, id 7562933); histórico próprio de 2024 (sistema anterior). O PriceLabs não tem o nosso dezembro de 2025 (as reservas só existem a partir de 31/07/2026).

## 1. Resumo

1. **Meta:** Recanto R$ 350 mil e Villa R$ 300 mil. **Vendido hoje:** R$ 53,9 mil e R$ 46,7 mil (13% das diárias nas duas, igual ao mercado: 13-14%).
2. **No ritmo atual não se chega à meta.** Com a ocupação final do mercado de 2025 e os preços de hoje, o Recanto fecha entre R$ 242 mil e R$ 329 mil (69 a 94%) e a Villa entre R$ 236 mil e R$ 268 mil (79 a 89%).
3. **Começo de dezembro à frente do mercado, festas atrás.** Estamos à frente em 01-17/12 (dias de semana 11% contra 7%; fins de semana 25% e 18% contra 9%). Estamos atrás onde está o dinheiro: no Natal (Recanto 6% e Villa 4% contra 26-27% do mercado), em 27-28/12 (0% e 3% contra 16-19%) e, no Recanto, também no pré-Natal e no Réveillon.
4. **Três travas fora do preço explicam parte do atraso:**
   - o Recanto não vende o pacote de Natal 24→26/12 (sábado com "sem saída");
   - o anúncio ativo do Recanto na Booking aparece sem nota de avaliações;
   - a Villa não tem o selo de hidromassagem na Booking.
5. **Menor lotação para a meta:** com diária média de R$ 1.300 bastam 59% no Recanto e 55% na Villa. Com R$ 900 seriam 80% e 74%. A meta se faz com diária alta nas festas, não enchendo dia de semana barato.
6. **Com a estratégia abaixo:** o Recanto chega a cerca de R$ 338 mil (97%) e a Villa a cerca de R$ 285 mil (95%), com 58% e 52% de ocupação. Para superar a meta faltam alavancas do dono: avaliações do Recanto, pacotes com ceia e festa nas festas, canal direto.

## 2. Meta, vendido e o que falta

| | Recanto | Villa |
|---|---|---|
| Meta do dono (alta temporada) | R$ 350.000 | R$ 300.000 |
| Vendido (Beds24, confirmadas + novas) | R$ 53.857 (66 diárias, diária média R$ 816) | R$ 46.666 (62 diárias, R$ 753) |
| Falta | R$ 296.143 | R$ 253.334 |
| Diárias livres | 430 de 496 | 403 de 465 |
| Dezembro de 2024 (sistema anterior) | R$ 253.178, 64%, diária média R$ 794 | R$ 131.151, 50%, R$ 563 |
| Natal 2024 / Réveillon 2024 | 69% a R$ 712 / 90% a R$ 1.329 | 53% a R$ 596 / 76% a R$ 994 |

A meta pede +38% sobre dezembro de 2024 no Recanto e +129% na Villa.

## 3. A menor lotação que bate a meta

A receita por diária é estimada como o preço público × 0,92 (Genius e celular). Por esse critério, as diárias de 01-17/12 do Recanto saíram a R$ 699, com o quarto mais barato a R$ 748.

| Diária média das vendas que faltam | Recanto: diárias a vender (ocupação final do mês) | Villa: diárias a vender (ocupação final do mês) |
|---|---|---|
| R$ 700 | 423 (99%) | 362 (91%) |
| R$ 900 | 329 (80%) | 281 (74%) |
| R$ 1.100 | 269 (68%) | 230 (63%) |
| R$ 1.300 | 228 (59%) | 195 (55%) |
| R$ 1.500 | 197 (53%) | 169 (50%) |
| R$ 1.800 | 165 (46%) | 141 (44%) |

**Leitura:** a meta só cabe numa lotação possível com diária média de R$ 1.100 a R$ 1.300. Isso só existe de 18 a 31/12 e nos fins de semana. Dia de semana barato no começo do mês quase não move a meta.

**Ponto de equilíbrio nos dias de semana:** a R$ 748, 25% de ocupação rende o mesmo que 42% a R$ 450. Baixar para R$ 450 (a base do mercado de novembro) só compensa se a ocupação quase dobrar. Em 2025 o mercado fechou os dias de semana de 01-17/12 em 26-32%.

## 4. Mercado

### 4a. Booking: concorrentes do perfil (preço por noite, com café, 2 adultos)

O preço do quarto com hidro é o menor quarto × `r_hidro`, que está medido em 33 dos 77 hotéis; os demais usam o padrão de 1,395. A coluna "mediana crua" é o menor quarto, sem o fator.

| Estadia | Hotéis | p25 | Mediana (hidro) | p75 | p90 | Mediana crua | Recanto (percentil) | Villa (percentil) |
|---|---|---|---|---|---|---|---|---|
| 02-03/12 (qua) | 68 | 472 | 702 | 1.180 | 1.567 | 449 | 748 (p53) | 518 (p35) |
| 04-06/12 (fds) | 66 | 923 | 1.261 | 1.803 | 2.342 | 855 | 1.229 (p50) | 839 (p21) |
| 09-10/12 (qua) | 68 | 475 | 702 | 1.180 | 1.687 | 432 | 748 (p54) | 534 (p40) |
| 11-13/12 (fds) | 64 | 964 | 1.272 | 1.811 | 2.176 | 834 | 1.229 (p50) | 1.016 (p31) |
| 16-17/12 (qua) | 67 | 463 | 713 | 1.210 | 1.750 | 470 | 748 (p52) | 526 (p39) |
| 18-20/12 (fds, férias) | 70 | 953 | 1.212 | 1.657 | 2.574 | 825 | 1.229 (p51) | 957 (p24) |
| 21-23/12 (seg-qua) | 64 | 577 | 868 | 1.373 | 1.969 | 562 | 978 (p56) | **1.416 (p78)** |
| 24-26/12 (Natal) | 48 | 1.300 | 1.749 | 2.981 | 4.098 | 1.321 | **não vende** | 1.326 (p25) |
| 26-28/12 (fds pós-Natal) | 47 | 994 | 1.348 | 2.089 | 3.567 | 894 | 1.412 (p57) | 1.588 (p57) |
| 30/12-02/01 (Réveillon, 3 noites) | 55 | 1.554 | 2.358 | 3.401 | 4.460 | 1.526 | 1.941 (p40) | 1.838 (p38) |

Recanto no Natal, pelas coordenadas:
- 24→27/12 (3 noites): R$ 1.450 por noite.
- 25→27/12: R$ 1.450 por noite.
- 24→26/12: não vende.

### 4b. PriceLabs: ritmo do mercado (Airbnb) e o nosso, por segmento

Ocupação: já reservado hoje / mesma época de 2025 / final de 2025. Preço do mercado: p50 / p90 (público, Airbnb).

| Segmento | Mercado (conjunto do Recanto) | Recanto hoje | Mercado (conjunto da Villa) | Villa hoje |
|---|---|---|---|---|
| 01-17/12 dom-qui | 6,6 / 8,5 / 26,2%; 649 / 1.054 | **11%** | 7,4 / 9,6 / 31,8%; 557 / 1.096 | **11%** |
| 01-17/12 sex-sáb | 8,8 / 14,8 / 45,1%; 806 / 1.324 | **25%** | 9,2 / 13,2 / 54,2%; 700 / 1.459 | **18%** |
| 18-23/12 | 14,5 / 13,8 / 47,3%; 887 / 1.313 | 11% | 13,4 / 17,6 / 57,0%; 801 / 1.378 | 12% |
| 24-26/12 (Natal) | 27,1 / 15,1 / 51,6%; 1.105 / 1.580 | **6%** | 25,6 / 23,5 / 61,3%; 1.062 / 1.713 | **4%** |
| 27-28/12 | 18,8 / 15,2 / 55,0%; 1.062 / 1.577 | **0%** | 16,2 / 19,4 / 65,8%; 1.023 / 1.772 | **3%** |
| 29/12-01/01 (Réveillon) | 35,8 / 32,1 / 85,0%; 1.243 / 1.802 | 27% | 32,1 / 29,4 / 90,1%; 1.205 / 1.996 | 33% |
| Dezembro inteiro | 13,8 / 13,9 / 43,2% | 13% | 13,4 / 15,7 / 50,5% | 13% |

- **A oferta do mercado cresceu ~60% contra dezembro de 2025 e a procura acompanhou.** Por isso a ocupação está igual à do ano passado.
- **O Natal do mercado está 12 pontos acima da mesma época de 2025** (27% contra 15%). A procura do Natal existe, e nós não a estamos pegando.
- **O PriceLabs aponta preços do mercado 35% mais altos em 27-31/12** e ocupação 18-21% maior no Réveillon.
- **Q7 nos próximos 30 dias:** 37% contra 17% do mercado (MPI 2,16). Nos próximos 60 dias, MPI 1,41.

## 5. Diagnóstico

1. **Natal do Recanto travado.** Os 7 quartos têm "sem saída" no sábado 26/12 no Beds24 (a trava de sábado da Regra H, contra a sexta sozinha). Na Booking isso impede o pacote 24→26/12 no Recanto. A Villa, com a mesma trava no Beds24, vende 24→26/12. A estadia mínima de 2 noites em 24-26/12 já impede a sexta 25→26 sozinha, então a trava de saída não tem função no Natal.
2. **Recanto sem nota na Booking.** O anúncio ativo (id 7562933) não mostra nota nem número de avaliações. O anúncio antigo (15459723) tinha 226. Isso pesa na conversão em todas as datas e explica parte do atraso do Recanto nas festas, mesmo com preço na mediana.
3. **Villa sem selo de hidromassagem na Booking** (pendência do dono). A Villa fica fora do filtro "banheira de hidromassagem", justamente o do nosso perfil de concorrência.
4. **Preço acima da curva onde estamos atrás:**
   - Villa 21-23/12 a R$ 1.416 (p78, 2,5× a mediana crua);
   - 27-28/12 a R$ 1.412 (Recanto) e R$ 1.588 (Villa), no p57, com 0 e 1 diária vendida contra 16-19% do mercado.
5. **Preço abaixo da curva onde a procura é certa:**
   - Réveillon no p38-40 dos quartos com hidro (Recanto R$ 1.941, Villa R$ 1.838, contra a mediana de R$ 2.358);
   - fins de semana de 01-17/12 da Villa no p21-31, já vendendo o dobro do mercado.
6. **Dias de semana de 01-17/12:** Recanto no p53 e Villa no p35-40, já à frente do mercado (11% contra 7%). Pelo ponto de equilíbrio (seção 3), baixar aqui não vale a pena.

## 6. Cenários (receita de dezembro)

Premissas:
- **Ocupação de cada segmento:** a final do mercado em 2025 × um índice. Recanto 1,4 (2024: 64% contra 45% do mercado); Villa 0,9 (2024: 50% contra 56%). Teto de 95%.
- **Preço das vendas que faltam:** o preço público médio das diárias livres × 0,92.

| Cenário | Recanto | Villa |
|---|---|---|
| A. Como está, ocupação = mercado 2025 | R$ 242 mil (69%), 43% | R$ 268 mil (89%), 51% |
| A. Como está, índice histórico | R$ 329 mil (94%), 58% | R$ 236 mil (79%), 45% |
| B. + Natal vendável e selo de hidromassagem (Villa no índice 1,0) | R$ 329 mil (94%) | R$ 268 mil (89%) |
| B. + Réveillon na mediana com hidro (Recanto +20%, Villa +25%) | R$ 341 mil (97%) | R$ 279 mil (93%) |
| B. + fins de semana 01-17/12 e Natal da Villa no p40 (+10%) | — | R$ 285 mil (95%) |
| B. + 27-28/12 e 21-23/12 na mediana (−5 a −10%, mais ocupação) | R$ 336-338 mil (96-97%) | R$ 285 mil (95%) |

**Para superar a meta (R$ 12-15 mil a mais em cada pousada), o que está fora do PriceLabs:**
- **Avaliações do Recanto:** subir o índice de 1,4 para 1,5 vale cerca de R$ 16 mil e leva o Recanto a ~R$ 354 mil (101%). Sem as avaliações (índice 1,0), mesmo com a estratégia, fica em ~R$ 261 mil (74%).
- **Pacotes de Natal (24→26 ou 24→27) e de Réveillon (29/12→02/01) com ceia ou festa:** sobem a diária média sem pedir lotação. Os modelos estão em `pricelabs/pacotes`.
- **Canal direto e Airbnb** com os multiplicadores certos (pendência do dono).

## 7. Estratégia proposta

| # | Segmento | O que fazer | Quem |
|---|---|---|---|
| 1 | Natal 24-26/12 | Tirar "sem saída" do sábado 26/12 nos 7 quartos (Beds24 e PriceLabs), mantendo a estadia mínima de 2. O pacote 24→26 passa a vender no Recanto. | Skill, com o "sim" do dono (exceção à trava de sábado) |
| 2 | Réveillon 29-31/12 | Subir o quarto mais barato do Recanto e da Villa para a mediana dos quartos com hidro (~R$ 2.350 público por noite, hoje R$ 1.941 e R$ 1.838), com a escada de vendas por cima e a estadia mínima de 3 mantida. Revisar a cada rotina: se o ritmo cair abaixo do mercado, voltar. | Skill, com o "sim" do dono |
| 3 | 27-28/12 e Villa 21-23/12 | Levar para perto da mediana com hidro: 27-28/12 Recanto ~R$ 1.300 e Villa ~R$ 1.350 (hoje R$ 1.412 e R$ 1.588); Villa 21-23/12 ~R$ 950 (hoje R$ 1.416). A escada sobe de novo a cada venda. | Skill, com o "sim" do dono |
| 4 | Fins de semana 01-17/12 | Manter. A escada de vendas sobe a cada reserva, e já estamos com o dobro do mercado. | Automático (motor) |
| 5 | Dias de semana 01-17/12 | Manter os preços, sem base do mercado, e usar só a oferta de última hora (D0-D3). | Automático (motor) |
| 6 | Régua do motor para dezembro | Atualizar a âncora de mercado das noites de dezembro com a varredura de hoje (64-70 hotéis por estadia, contra 17-20 em 30/10). | Skill, na rotina das 07:53 |
| 7 | Extranet | (a) Pedir à Booking a migração das 226 avaliações para o anúncio 7562933; (b) selo de hidromassagem da Villa; (c) pacotes de Natal e Réveillon; (d) multiplicadores do direto e do Airbnb; (e) Sync Now depois de cada mudança. | Dono |

**Acompanhamento:** toda rotina completa compara nosso ritmo com o do mercado por segmento (tabela 4b). Na segunda de manhã, o faturamento de dezembro contra a meta. Gatilhos:
- segmento das festas mais de 5 pontos atrás do mercado por 2 rotinas seguidas: baixar até a mediana com hidro;
- segmento à frente: a escada sobe.

## 8. Riscos e premissas

- **O índice de 1,4 do Recanto vem de 2024**, quando o anúncio tinha avaliações. Sem elas, o índice pode ficar perto de 1,0 (cenário de R$ 242 mil).
- **O fator `r_hidro` está medido em 43% dos concorrentes.** Pela mediana crua (menor quarto), o Recanto fica 27 a 74% acima do mercado, e não na mediana. A verdade fica entre as duas leituras.
- **O mercado do PriceLabs é só Airbnb** (apartamentos e casas). A categoria de quartos (R) tem ocupação menor (Recanto 34%, Villa 27% no dezembro de 2025).
- **52% das nossas reservas entram até 3 dias antes** (2024). Dezembro ainda vai mudar muito, e o Réveillon é a exceção (vende antes).
- **A receita do Beds24 é a de hospedagem** (confirmadas + novas). Uma reserva da Villa de 04-06/12 tem valor de R$ 1 (pendência do dono).

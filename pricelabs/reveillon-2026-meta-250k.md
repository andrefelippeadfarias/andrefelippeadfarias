# Réveillon 2026/27: preços, vendido e estratégia para R$ 250 mil

**Pedido do dono (16/11/2026):** ver o valor cobrado no Réveillon e no fim de semana seguinte, sempre em duas diárias: Réveillon de quarta a sexta (30/12→01/01) e fim de semana de sexta a domingo (01→03/01). Meta: pelo menos R$ 250 mil nas duas pousadas, nessas 4 noites.

**Fontes:**
- Beds24 (inventário, livro e reservas), lido em 16/11 às 11:36 BRT.
- Booking pelo conector oficial, por volta de 11:45 BRT. A Villa no 01→03 foi recapturada às 12:47 BRT.
- PriceLabs: dados de mercado e regras por data.
- Workflow com 10 agentes: 3 estrategistas, 1 síntese e uma verificação adversarial com 15 apontamentos, todos corrigidos.
- Dados completos em `reveillon-2026-meta-250k/dados.json`. Não há dados de hóspedes.

**Nada foi gravado.** Toda mudança abaixo depende do "Pode fazer" do dono. O PriceLabs está com o recálculo e o envio parados por causa da cobrança em aberto.

## 1. Alerta: a Villa vende o 01→03 sozinho na Booking

- **Na Booking:** o 01→03 da Villa (id 14824789) aparece à venda por **R$ 3.027,38**, ou **R$ 1.514 por noite**. Foram 3 buscas iguais, às 11:45 e às 12:47 BRT.
- **No Beds24:** os 3 quartos da Villa têm em 01/01 `noCheckIn` e mínimo de 3 noites, e em 02/01 `noCheckInOrCheckOut`.
- **No Recanto,** com a mesma regra, o 01→03 aparece fechado.
- **O que isso indica:** algum quarto ou tarifa da Villa na Booking não recebe a restrição do Beds24, ou uma promoção tem regras próprias.
- **Razão Booking ÷ enviado:** 0,338, abaixo do limite de alerta de 0,42. A vigilância de segurança aponta isso como tarifa ou promoção alterada na extranet.
- **Só o dono resolve, pela extranet.** Fechar já e reabrir no preço certo.

## 2. Quanto cobramos hoje (preço por noite do pacote de 2 diárias)

Na tabela:
- **Enviado** é o preço que o PriceLabs manda (price1 no Beds24).
- **Público** é o que a Booking mostra. No Recanto, o fator medido hoje é 0,5832; em 09/11 era 0,6156. Na Villa, os fatores medidos hoje são 0,4461 no Réveillon e 0,3377 no fim de semana, por causa da promoção ativa.
- **Genius 3** é estimado: Recanto público × 0,425 ÷ 0,63; Villa público × 0,575 ÷ 0,65.

| Quarto | Bloco | Pacotes livres | Enviado (n1 / n2) | Público por noite | Genius 3 por noite |
|---|---|---|---|---|---|
| Moinhos (Q7) | Réveillon | 2 | 3.191 / 3.413 | R$ 1.926 (medido) | R$ 1.299 |
| Panorâmica I (Double) | Réveillon | 3 | 3.567 / 3.818 | R$ 2.153 | R$ 1.453 |
| Afrodite | Réveillon | 1 | 4.120 / 4.400 | R$ 2.484 | R$ 1.676 |
| Panorâmica II (Q2) | Réveillon | 1 | 3.482 / 3.721 | R$ 2.100 | R$ 1.417 |
| King Spa 7 | Réveillon | 3 | 4.988 / 4.988 | R$ 2.225 | R$ 1.968 |
| Balcony | Réveillon | 1 | 4.483 / 4.483 | R$ 2.000 (medido) | R$ 1.769 |
| King Spa 2 | Réveillon | 1 | 4.991 / 4.991 | R$ 2.226 | R$ 1.970 |
| Moinhos (Q7) | Fim de semana | 4 | 2.979 / 2.979 | fechado (se abrir: R$ 1.737) | R$ 1.172 |
| Panorâmica I (Double) | Fim de semana | 4 | 3.567 / 3.567 | fechado (R$ 2.080) | R$ 1.403 |
| Afrodite | Fim de semana | 1 | 4.120 / 4.120 | fechado (R$ 2.403) | R$ 1.621 |
| Panorâmica II (Q2) | Fim de semana | 2 | 3.434 / 3.434 | fechado (R$ 2.003) | R$ 1.351 |
| King Spa 7 | Fim de semana | 5 | 4.988 / 4.988 | R$ 1.684 (aberto por erro) | R$ 1.490 |
| Balcony | Fim de semana | 5 | 4.483 / 4.483 | R$ 1.514 (medido, erro) | R$ 1.339 |
| King Spa 2 | Fim de semana | 2 | 4.991 / 4.991 | R$ 1.685 (aberto por erro) | R$ 1.491 |

**Pacotes possíveis:** um pacote precisa das 2 noites livres no mesmo quarto, então conta o menor número de vagas entre as duas noites.
- Réveillon: 12 pacotes (24 diárias).
- Fim de semana: 23 pacotes (46 diárias).

## 3. Vendido e o que falta

| | Diárias vendidas | Receita (Beds24, valor pago) |
|---|---|---|
| Réveillon (30 e 31/12) | 28 | R$ 32.456,13 |
| Fim de semana (01 e 02/01) | 12 | R$ 14.124,55 |
| **Total** | **40 de 124** | **R$ 46.580,68** (diária média R$ 1.165) |

- **Faltam R$ 203.419.** Há 84 diárias livres, e 70 delas vendem nas regras atuais (em pacotes).
- **Noites soltas:** as outras 14 só vendem com exceção às regras.
  - 10 em 30/12. Só venderiam com saída em 31/12, que a ordem de 03/11 não permite.
  - 4 em 02/01. Só venderiam com entrada no sábado, que a ordem de 02/11 não permite.
- **Quanto cada diária precisa render:** para fechar a meta só com as 70 diárias vendáveis, cada uma precisa render **R$ 2.906 pagos pelo hóspede**. Hoje, o público médio dessas 70 é R$ 1.910.
- **Teto vendendo tudo aos preços de hoje:**
  - R$ 167 mil sem abrir o 01→03 (contando as vendas pelo erro da Villa);
  - cerca de R$ 191 mil abrindo o 01→03.

## 4. Mercado

- **Concorrentes com hidro ainda à venda na Booking (16/11, preço por noite):**
  - Réveillon (n = 11): p50 R$ 1.233, p75 R$ 1.919, p90 R$ 2.618, máximo R$ 2.893.
  - Fim de semana (n = 12): p50 R$ 1.434, p75 R$ 2.149, p90 R$ 2.412, máximo R$ 2.871.
- **Demanda:** 13 dos 22 concorrentes da lista estão sem vaga para as duas estadias, quase todo o grupo premium. Por isso a régua de hoje está puxada para baixo e não quer dizer que o mercado esteja barato. Em 09/11, a estadia 30→03 tinha p50 com hidro de R$ 2.190, p75 de R$ 3.135 e p90 de R$ 3.890.
- **Ocupação do mercado no PriceLabs, hoje contra a mesma época de 2025:**
  - 30/12: 42,4% contra 41,7%;
  - 31/12: 48,9% contra 40,6%;
  - 01/01: 47,4% contra 26,5%;
  - 02/01: 24,9% contra 14,9%.
- **A nossa ocupação:** 29%, 61%, 26% e 13% nas mesmas noites.
- **O sábado 02/01 é o ponto fraco** do período, tanto no mercado quanto em nós.

## 5. Cenários (receita total das 4 noites)

| Cenário | Total | Fecha R$ 250 mil? |
|---|---|---|
| 1. Nada muda (PriceLabs parado, erro da Villa aberto) | R$ 106 mil | não |
| 2. Só o PriceLabs pago (o motor segue o plano de 09/11) | R$ 115 mil | não |
| 3. Plano completo, ritmo provável | **R$ 169 mil** | não |
| 4. Plano completo, tudo vendido, sem exceções | R$ 215 mil | não |
| 5. Igual ao 4, com a Booking rendendo 0,88 do público | R$ 233 mil | não |
| 6. Igual ao 5, com as duas exceções das noites soltas e o máximo do anúncio em R$ 6.000 | R$ 254 mil (folga de R$ 4,3 mil) | sim, no limite |
| 8. Pior caso | R$ 74 mil | não |

**Conclusão:** R$ 250 mil só fecham com tudo junto: preço no pedido de 09/11, o 01→03 aberto, a promoção da Villa fora, a Booking rendendo cerca de 0,88 do público (hoje rende cerca de 0,74), o máximo do anúncio em R$ 6.000, as duas exceções e quase 100% vendido. O provável com o plano completo é R$ 169 mil.

**Alavanca que não entra na conta: venda extra na própria reserva.** Ceia, espumante e saída tardia, oferecidos também a quem já reservou: são cerca de 14 pacotes de Réveillon já vendidos mais os 12 que restam. Só conta para a meta se o valor entrar na reserva no Beds24.

## 6. Preços propostos (dependem do "Pode fazer")

O "pedido de 09/11" são os preços que o dono pediu em 09/11, guardados como `pedido_0911` em `dados/regua_dono.json`. O plano aprovado em 09/11 diz que o Réveillon do Recanto abre na mediana do mercado e sobe pela escada, sem passar do pedido. A proposta abaixo vai direto ao pedido.

**Réveillon, Recanto (público com o desconto de volta a 5%):**

| Quarto | Enviado | Público por noite |
|---|---|---|
| Moinhos (Q7) | 4.974 | cerca de R$ 3.062 |
| Panorâmica II (Q2) | 4.977 | cerca de R$ 3.064 |
| Panorâmica I (Double) | 4.988 | cerca de R$ 3.071 |
| Afrodite | 6.700 | cerca de R$ 4.125 |

**Réveillon, Villa:**
- O enviado continua o mesmo, porque já está no máximo de R$ 5.000.
- O público só sobe se a promoção sair: cerca de R$ 3.093 nas King Spa e R$ 2.779 na Balcony.

**Fim de semana (proposta abaixo do pedido de 09/11):**

| Quarto | Público por noite |
|---|---|
| Moinhos (Q7) | R$ 2.550 |
| Panorâmica II (Q2) | R$ 2.600 |
| Panorâmica I (Double) | R$ 2.650 |
| Afrodite | R$ 3.400 |
| King Spa 7 e King Spa 2 | R$ 2.550 (enviado cai para 4.113, só sem a promoção) |
| Balcony | R$ 2.295 |

**Escada:**
- +3% por pacote vendido na pousada no Réveillon e +2% no fim de semana.
- Teto: o máximo do anúncio no Réveillon e o concorrente com hidro mais caro no fim de semana.
- Nunca sobe a noite de hoje.

**Descida:**
- Só a partir de D7 (23/12 no Réveillon e 25/12 no fim de semana), em degraus de 5%, se o bloco estiver abaixo da linha.
- Para nos pisos do período, entre o p50 e o p75 com hidro.

## 7. Abertura do 01→03 (sexta a domingo)

Precisa do "Pode fazer" do dono, porque muda a ordem de 03/11 ("só 30→01 ou 30→03"). Ficam valendo o 30→01 e o 30→03.

**Restrições no Beds24:**
- 01/01 com chegada liberada e mínimo de 2 noites.
- 02/01 com mínimo de 2 noites e o sábado ainda sem entrada nem saída.
- No PriceLabs vai a estadia mínima por data, porque o envio do PriceLabs sobrescreve a do Beds24.

**Ordem da gravação, depois do "Pode fazer":**
1. PriceLabs pago e preços do fim de semana já enviados.
2. Preço público medido na Booking dentro do alvo.
3. Promoção da Villa fora.
4. Só então as restrições.

**Por quê:** abrir antes venderia o Recanto a R$ 1.737 a 2.403 por noite.

## 8. Marcos de acompanhamento (receita total acumulada das 4 noites)

| Data | Alvo (meta) | Linha de alarme (provável) |
|---|---|---|
| 23/11 | R$ 56,5 mil / 44 diárias | R$ 53,1 mil / 43 |
| 30/11 | R$ 66,4 mil / 47 | R$ 59,6 mil / 46 |
| 07/12 | R$ 85,2 mil / 54 | R$ 72,0 mil / 51 |
| 14/12 | R$ 105,8 mil / 62 | R$ 85,5 mil / 57 |
| 21/12 | R$ 146,8 mil / 78 | R$ 107,1 mil / 67 |
| 28/12 | R$ 208,6 mil / 102 | R$ 141,7 mil / 82 |
| 01/01 | R$ 254,3 mil / 120 | R$ 168,6 mil / 94 |

## 9. Decisões do dono (em ordem)

1. **Hoje:** fechar na extranet o 01→03 da Villa que está à venda por erro a R$ 1.514 por noite.
2. **Hoje:** pagar a cobrança do PriceLabs. Sem isso, nenhum preço muda.
3. **Booking da Villa:** tirar a promoção de 30/12 a 02/01.
4. **Booking do Recanto:** conferir por que o fator caiu de 0,6156 para 0,5832 em uma semana. Pode ser um desconto que passou de 5% para 10% ou uma campanha (Black Friday). Também não deixar promoção somar com o Genius nessas noites.
5. **"Pode fazer" do 01→03** (sexta a domingo), gravado só depois dos passos da seção 7.
6. **"Pode fazer" dos preços:** Réveillon no pedido de 09/11, fim de semana pela proposta (ou manter o pedido), escada nova e descida a partir de D7.
7. **Máximo do anúncio:** subir de R$ 5.000 para R$ 6.000 em 30/12 a 02/01. Só o dono pode mexer nisso.
8. **Exceções das noites soltas,** cada uma com o seu "Pode fazer": saída em 31/12 (estadias 29→31) e entrada no sábado 02/01 (02→04). Antes, a skill confere as vagas de 29/12 e de 03/01.
9. **Venda direta:** campanha pelo preço público do dia, com alvo de 1/3 das vendas, mais a venda extra na reserva (ceia e outros).
10. **Booking da Villa:** incluir "Jacuzzi" nas comodidades. Hoje ela não aparece no filtro de hidromassagem.

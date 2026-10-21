# Plano de 12 meses para alavancar reservas (nov/2026 a out/2027)

Versão 1.0, 21/10/2026. Pedido do dono: "Defina agora a estratégia para alavancar as reservas para os próximos 12 meses. Pesquise as melhores práticas no mercado e vamos estruturar isso tudo fazendo tudo de acordo com nossa skill."

Este plano **não muda regra nem limite**. Ele organiza o que a skill já faz sozinha (preço e estadia por data, dentro de `regras.md` e de `limites_seguranca`) e lista o que só o dono pode decidir ou fazer na tela. Onde este arquivo divergir de `regras.md` ou de `dados/quartos.json`, valem eles. A revisão é na primeira segunda de cada mês (seção 8).

## 1. Diagnóstico (Beds24 e PriceLabs, 21/10/2026)

| Indicador | Recanto | Villa | Leitura |
|---|---|---|---|
| Outubro, realizado + vendido | R$ 144 mil (mínimo R$ 220 mil) | R$ 115 mil (mínimo R$ 150 mil) | Recanto acima do realista (R$ 141,5 mil); Villa ainda pode chegar |
| Ocupação de 07/09 a 20/10, domingo a quinta | 55% (diária R$ 480) | 40% (diária R$ 512) | **o ponto fraco**: dias de semana |
| Ocupação de 07/09 a 20/10, sexta e sábado | 62% (R$ 707) | 66% (R$ 678) | — |
| Antecedência mediana da reserva | 5 dias (46% em até 3 dias) | 4,5 dias (44% em até 3 dias) | mercado de **última hora**, de carro, vindo de SP |
| Receita pela Booking (últimos 28 dias) | 78% | 63% | dependência alta; Preferred Partner a 18% desde 01/07/2026 |
| Diária média no Airbnb | R$ 455 | **R$ 300** | Villa vende barato no Airbnb (fator 0,31) |
| Lançamentos manuais da equipe | diária R$ 397; 42% cancelados | 25% cancelados | erro de tarifa e bloqueios disfarçados de reserva |
| Reservas do site (direto, API) | 48% canceladas | 42% canceladas | pagamento não garantido no motor |
| Livro de novembro | R$ 105 mil (31% da ocupação) | R$ 45 mil (14%) | Villa em novembro é o mês mais atrasado |
| Nota | ~9 | 8,2 | subir a nota da Villa rende mais que qualquer desconto |

Fatos de mercado (PriceLabs, vizinhança; ocupação do ano passado):
- O **pico é julho** (61%). Depois vêm junho (50%), dezembro e maio (42%), novembro, janeiro e abril (35–36%), agosto e setembro (36–37%). O vale é março e outubro (27–28%).
- O mercado é de fim de semana. Os picos de preço do mercado ficam no Réveillon (+30%), na Páscoa (+30%) e em Tiradentes (+32%).

### 1a. Histórico próprio (planilhas do sistema anterior, enviadas pelo dono em 21/10; `dados/historico_proprio.json`)

**Esta é a descoberta principal do plano: o Recanto já vendeu muito mais do que vende hoje.** O mercado não explica a diferença.

| Mês | Recanto em 2024 (receita, ocupação, diária) | Recanto em 2026 | Villa em 2024 | Villa em 2026 |
|---|---|---|---|---|
| jan | R$ 257 mil, 73%, R$ 706 | R$ 215 mil, 59%, R$ 735 | (não existia) | R$ 119 mil, 42%, R$ 608 |
| fev | R$ 218 mil, 69%, R$ 680 | R$ 155 mil, 44%, R$ 795 | — | R$ 115 mil, 40%, R$ 686 |
| mar | R$ 269 mil, 74%, R$ 731 | R$ 134 mil, 51%, R$ 530 | — | R$ 64 mil, 33%, R$ 424 |
| abr | R$ 270 mil, 68%, R$ 829 | (sem dado) | — | — |
| mai | R$ 328 mil, 74%, R$ 899 | (sem dado) | R$ 41 mil (abertura) | — |
| jun | R$ 303 mil, 49%, R$ 1.290 | (sem dado) | R$ 105 mil, 25% | — |
| jul | **R$ 540 mil, 87%, R$ 1.246** | (sem dado) | R$ 199 mil, 41%, R$ 1.048 | — |
| ago | R$ 372 mil, 90%, R$ 831 | (sem dado) | R$ 116 mil, 31% | — |
| set | R$ 260 mil, 70%, R$ 773 | R$ 182 mil (Beds24) | R$ 78 mil, 35%, R$ 491 | R$ 142 mil (Beds24) |
| out | R$ 259 mil, 78%, R$ 665 | R$ 144 mil (realizado + vendido) | R$ 89 mil, 41%, R$ 462 | R$ 115 mil |
| nov | R$ 262 mil, 74%, R$ 735 | R$ 105 mil vendidos | R$ 105 mil, 46%, R$ 510 | R$ 45 mil vendidos |
| dez | R$ 253 mil, 64%, R$ 794 | R$ 48 mil vendidos | R$ 131 mil, 50%, R$ 563 | R$ 26 mil vendidos |

Leituras:
1. **2024 no total:** o Recanto fez cerca de R$ 3,6 milhões, e nenhum mês ficou abaixo de R$ 217 mil. O mínimo do dono (R$ 220 mil) é, portanto, o piso histórico, não uma ambição.
2. **2026 contra 2024:** em outubro, as duas pousadas juntas (R$ 259 mil) ficam 25% abaixo de 2024 (R$ 347 mil). No 1º trimestre, as duas juntas fizeram R$ 802 mil, contra R$ 745 mil só do Recanto em 2024. Ou seja, a Villa cresceu, mas o Recanto encolheu de 16% a 50% por mês. **Uma pousada está tomando hóspedes da outra.** Por isso o posicionamento separado (Recanto no topo, Villa na base; `estrategia.md`) é obrigatório e precisa aparecer na comunicação também.
3. **Dias de semana do Recanto:** em 2024, 64% de ocupação a R$ 717, e sexta e sábado 77% a R$ 1.111. Em 2026, 44% a R$ 540 de domingo a quinta.
4. **Canal direto:** em 2024, o motor do site trouxe **35% da receita do Recanto** (R$ 1,22 milhão) e 28% da Villa. Hoje, as reservas diretas sem lançamento à mão são cerca de 8%. Foi o canal que mais se perdeu na troca de sistema.
5. **Cancelamentos em 2024:** 42% das reservas da Booking ficaram sem valor, contra 18% das do site. O canal direto cancela menos da metade.
6. **Antecedência em 2024:** mediana de 3 dias (52% em até 3 dias). O mercado de última hora não é novo.
7. **Feriados em 2024 no Recanto (ocupação e diária recebida):**

   | Feriado | Ocupação | Diária |
   |---|---|---|
   | Sábados do Festival de Inverno | 100% | R$ 2.002 |
   | Corpus Christi | 88% | R$ 1.720 |
   | 9 de Julho | 98% | R$ 1.425 |
   | Réveillon | 90% | R$ 1.329 |
   | Páscoa | 88% | R$ 1.242 |
   | Carnaval | 61% | R$ 1.206 |

   **As diárias de feriado no bolso foram de R$ 1.200 a R$ 2.000,** contra o piso atual de R$ 800 a R$ 1.000. Em outubro de 2026, porém, um piso de R$ 1.200 ficou 3 dias sem venda (`aprendizados.md`). O inverno e Corpus Christi de 2027 são onde testar pisos mais altos (seção 4, item 2).
8. **Carteira em 11/03/2026 para 27/05 a 28/06/2026:** 67 reservas e R$ 116 mil, com Dia dos Namorados no Recanto a R$ 41,6 mil (14 reservas). A comparar com a carteira de 2027 na mesma data (revisão de março).

Problemas que bloqueiam o crescimento hoje (pendências de urgência 0, do dono):
1. O **Recanto sumiu da busca da Booking** em 21/10, e 4 cancelamentos chegaram em 2 minutos.
2. A **Q2 na Booking** tem tarifa fixa de R$ 412 a R$ 495.
3. Há **reservas manuais abaixo da tabela**: 8 graves, de 17% a 45% do esperado.
4. Overbooking na VK2 em 24/10.

Sem resolver isso, nenhuma tática abaixo rende.

## 2. Objetivos e indicadores (12 meses)

| Indicador | Hoje | Meta em abr/2027 | Meta em out/2027 | Onde se mede |
|---|---|---|---|---|
| Receita contra o mínimo do dono | outubro: 66% (Recanto), 77% (Villa) | ≥ 85% em cada mês | ≥ 100% em cada mês | painel semanal, `desempenho_mes.py` |
| Ocupação de domingo a quinta | 55% / 40% | 55% / 48% | 60% / 55% | painel, aba "Ocupação por semana" |
| Reservas diretas (receita) | ~13% / ~31%¹ | 20% | 25–30% | painel, "Por canal" |
| Cancelamento das reservas com valor | Booking ~20%; site ~45% | site < 25% | Booking < 15%; site < 15% | `desempenho_mes.py` |
| Reservas abaixo de 70% do planejado | 8 em 3 semanas | 0 graves por semana | 0 | `reservas_abaixo.py`, diário |
| Nota na Booking | ~9 / 8,2 | ≥ 9 / ≥ 8,5 | ≥ 9,2 / ≥ 8,8 | extranet |
| Tempo de resposta (WhatsApp e OTA) | não medido | < 15 min | < 5 min | WhatsApp Business |

¹ Na Villa, o "direto" inclui os lançamentos à mão da equipe (R$ 26 mil em outubro, com reservas a 17–26% da tabela). O direto saudável é só o do site, da API e do WhatsApp com preço da tabela.

Referência de mercado: na hotelaria brasileira, 45% das reservas são diretas (FOHB/Noctua, 2024). Reserva direta custa de 4% a 5%, contra 15% a 18% da Booking.

## 3. Metas de faturamento por mês

Em R$ mil. O livro é o vendido até 21/10. Novembro a abril vêm de `dados/metas_faturamento.json`. Maio a outubro de 2027 são **estimativas novas** pelo mesmo método (`metodo` do arquivo: ocupação do mercado no ano passado × diária do mercado × crescimento de 5% × índice × quartos × dias), gravadas como proposta. Cada célula traz mínimo do dono / realista / livro.

| Mês | Recanto | Villa | O que puxa o mês |
|---|---|---|---|
| nov/26 | 220 / 183 / 105 | 150 / 162 / 45 | Consciência Negra (20–22/11), Natal de Campos (desde 30/10) |
| dez/26 | 350 / 265 / 48 | 300 / 257 / 26 | Natal (25–27/12), Réveillon, férias escolares |
| jan/27 | 350 / 206 / 30 | 300 / 192 / 6 | férias; Aniversário de SP (23–25/01) |
| fev/27 | 220 / 142 / 8 | 150 / 129 / 3 | Carnaval (06–10/02), depois o vale |
| mar/27 | 220 / 135 / 5 | 150 / 127 / 2 | Páscoa (26–28/03); vale de dias de semana |
| abr/27 | 220 / 208 / 1 | 150 / 193 / 3 | Tiradentes (quarta 21/04) |
| mai/27 | 220 / 299 / 8 | 150 / 247 / 2 | Corpus Christi (27–30/05), frio começando |
| jun/27 | 350 / 347 / 14 | 300 / 287 / 7 | **Dia dos Namorados (sábado 12/06)**; férias a partir de 24/06 (Lei 15.421/2026, a confirmar) |
| jul/27 | 350 / 447 / 2 | 300 / 370 / 1 | **pico**: Festival de Inverno (estimado 03/07 a 01/08) e 9 de Julho (sexta) |
| ago/27 | 350 / 234 / 3 | 300 / 194 / 0 | Festa da Cerejeira (fins de semana), queda depois do inverno |
| set/27 | 220 / 217 / 3 | 150 / 180 / 0 | 7 de Setembro (terça, emenda 04–07/09); congresso CBQ (15–18/09); L'Étape (24–25/09) |
| out/27 | 220 / 178 / 0 | 150 / 147 / 0 | 12 de Outubro (terça, emenda 09–12/10); Finados 2027 |

**O mercado manda no preço; o histórico mostra o potencial** (dono, 21/10: "Temos que sempre levar em consideração a precificação do mercado como um todo também"). As metas continuam pelo método de mercado ("realista" na tabela), e 2024 entra como referência de até onde o Recanto já chegou. Nenhuma noite é precificada para "voltar a 2024". O preço de cada noite segue a ordem da skill:
- faixa de mercado e sazonalidade (`faixas_de_preco.py`, `sazonalidade.py`);
- referência hidro+vista e percentil na busca diária (`mercado_diario.py`, `concorrencia_hidro_vista.py`);
- trava de lotação.

Se a carteira se aproximar de 2024 com a noite na meta e o preço dentro da faixa, a skill sobe pelos passos de sempre.

Proposta para o dono decidir (pendência `metas-mai-out-2027`):
- **Recanto:** mínimo = R$ 220 mil em todos os meses e meta = o realista do mercado (R$ 299 mil a R$ 447 mil de maio a julho). 2024 fica como teto de referência: R$ 328 mil em maio, R$ 303 mil em junho, R$ 540 mil em julho, R$ 372 mil em agosto, R$ 260 mil em setembro e R$ 259 mil em outubro.
- **Villa:** mínimo = R$ 150 mil e meta pelo realista do mercado.
- **Inverno:** junho a agosto já são alta pela decisão do dono de 14/10 (`regras.md` §6a), com mínimo de R$ 350 mil no Recanto e R$ 300 mil na Villa, e assim entraram na tabela.
  - Julho é o maior mês do ano, no mercado (61% de ocupação) e no Recanto (R$ 540 mil em 2024).
  - **Agosto é o risco:** o realista do mercado (R$ 234 mil e R$ 194 mil) fica abaixo do mínimo. A Festa da Cerejeira e os fins de semana precisam carregar o mês; o Recanto já fez R$ 372 mil em agosto de 2024.

## 4. Pilar A: preço e estadia (a skill faz sozinha, dentro das regras)

Tudo continua em percentual (`percent_base`), com os pisos do dono (R$ 500 de domingo a quinta e R$ 850 em sexta e sábado no preço final do Genius 3; R$ 800 no bolso em feriado), pelo validador. O que muda é o **foco por temporada**.

1. **Mercado de última hora: proteger o preço cedo e decidir perto.** Com metade das reservas entrando a 5 dias, a curva de venda é curta.
   - Não cortar preço de dia útil antes de D-14 (o degrau da trava de lotação continua valendo).
   - O degrau de D-7 a D-3 é o que mais rende: a Regra I vendeu 5 noites em 7 horas em 13/10.
   - Os pisos de 20/10 tiraram o Recanto do jogo de última hora em dia útil (Q7 a R$ 1.232 enviados). **Medir por 2 semanas** (`aprendizados.md`, 20/10). Se o ritmo de dia útil do Recanto continuar abaixo de 6 reservas por dia em 03/11, levar ao dono a proposta de piso de dia útil diferente por janela (D0 a D3), sem mudar o limite sozinha.
2. **Datas de pico: abrir cedo e caro, sem desconto antes de D10** (`estrategia.md` §5).
   - Réveillon e Natal já estão abertos.
   - Carnaval: abre em 22/12 (45 dias).
   - Páscoa: abre em 09/02.
   - Corpus Christi: abre em 27/03 (60 dias).
   - Dia dos Namorados: abre em 12/04 (60 dias).
   - 9 de Julho e Festival de Inverno: abrem em 01/04 (90 dias).
   - 7 de Setembro: abre em 05/07.
   - 12 de Outubro: abre em 10/08.
   - Em cada abertura, a varredura de feriado de 60 dias confere os pisos e a estadia mínima.
   - **Teste de piso mais alto, só em pico** (Corpus Christi, Dia dos Namorados, 9 de Julho e sábados do Festival), usando 2024 como referência: o Recanto recebeu de R$ 1.425 a R$ 2.002 nessas noites. A skill pode subir o piso de feriado em `quartos.json` (nunca abaixo de R$ 800 no bolso; `regras.md` §4):
     - só quando o mercado da mesma noite sustentar o valor: a mediana hidro+vista da busca diária (vezes o fator de vitrine) precisa estar acima do piso novo, e o Recanto não pode passar do 3º quartil (p75) daquela busca. Sem busca da noite, não há teste;
     - abrir a R$ 1.400 no bolso com 60 a 90 dias;
     - se a 21 dias a noite estiver atrás da meta da janela, voltar para R$ 1.000;
     - registrar cada passo em `aprendizados.md`.
     - Feriado fora do pico continua com o padrão de R$ 1.000.
3. **Inverno (junho a agosto):**
   - faixa alto de domingo a quinta, muito alto em sexta, sábado e feriadão;
   - 2 noites em sexta, sábado e julho inteiro (pendência `estadia-inverno`, para alinhar `sazonalidade.json`, que tem trechos com 1 noite);
   - sem baixar de alto antes de D10.
   - Com as férias escolares a partir de 24/06 (se a lei valer), o fim de junho sobe para alta. A confirmação vai na revisão de março.
4. **Vales (fevereiro e março depois do Carnaval, agosto, setembro, outubro e novembro de domingo a quinta):**
   - a escada (Regra F) e o degrau por noite atrás da meta;
   - a Regra I nos 3 últimos dias;
   - a Balcony pela Regra G, como porta de entrada da Villa.
   - O preço sozinho não cria demanda de dia útil: o pilar D (pacotes) é o que muda o vale.
5. **Noites órfãs:** ativar "Orphan Day Prices" no PriceLabs (pendência de tela `orphan-days`). O padrão é −20% em buracos de 1 a 2 noites, sempre acima do mínimo do anúncio, com regras de dia útil e fim de semana. Só o dono liga.
6. **Reservas abaixo do planejado:** `reservas_abaixo.py` em toda rotina, no topo do relatório (já em vigor).

## 5. Pilar B: canais e OTAs (decisões e telas do dono)

Mantido por decisão do dono (20/10): **as promoções da Booking ficam como estão** e o Genius vai só até o nível 3. Mobile Rate, Country Rate, Getaway e Late Escape não entram neste plano; só voltam se o dono pedir.

Fora isso, em ordem de retorno:
1. **Recanto de volta na busca da Booking.** É urgência 0: extranet → status da propriedade, avisos, comissão em aberto e conteúdo pendente.
2. **Corrigir os mapeamentos de tarifa no Beds24:**
   - Q2 na Booking (Rate 50067466 fixa);
   - Villa na Expedia, no Hoteis.com e na Decolar;
   - repasse do Airbnb da Villa (diária de R$ 300).
3. **Tarifa não reembolsável ao lado da flexível** na Booking. É um plano de tarifa, não uma promoção:
   - desconto de 7% a 10% (as fontes trazem de 5% a 10%; a Booking cita cerca de 9% menos cancelamentos e 5% mais reservas);
   - em feriado e pico, cobrar pré-pagamento ao fim da janela grátis e encurtar o cancelamento grátis para 7 dias;
   - o efeito real é medido pelo cancelamento no painel.

   Precisa do dono e entra nos fatores de canal da skill (`fator_canal`).
4. **Página da propriedade a 100%** (Property Page Score):
   - na Villa, marcar hidromassagem, Wi-Fi e aquecimento (pendência `booking-villa-hidro`) e pôr fotos da hidro com vista;
   - no Recanto, conferir o mesmo.
   - É a forma mais barata de converter.
5. **Selo Travel Proud** (Booking, curso gratuito de 75 minutos): abre o público LGBTQIA+ de casais sem desconto.
6. **Preferred Partner (18%):** calcular, com 3 meses de dados, se a visibilidade extra paga os 3 pontos. Na alta a demanda já existe; o Visibility Booster, se um dia for usado, é só para dias úteis do vale. É decisão do dono.
7. **Airbnb:**
   - Smart Pricing desligado (o PriceLabs manda);
   - reserva instantânea ligada;
   - desconto semanal (7 noites ou mais) para atrair o workation;
   - corrigir o repasse da Villa.
8. **Expedia:** prioridade baixa (1% da receita). Só VIP Access, que é gratuito, se a nota permitir.

## 6. Pilar C: canal direto (growth; o dono executa, a skill mede)

Objetivo: passar de cerca de 13% para 25–30% de reservas diretas. Cada R$ 1 que sai da Booking para o direto economiza cerca de R$ 0,15 de comissão.

- **Paridade (regra do Cade):** o preço no site aberto ao público não pode ser menor que o da Booking. **Pode** ser menor no WhatsApp, no telefone, para hóspede que volta e em área fechada de cliente. As vantagens do direto, portanto, são benefício (upgrade, café no quarto, late checkout) e preço fechado, nunca preço público mais baixo.

0. **Recuperar o motor de reservas:** em 2024, o site trouxe 35% da receita do Recanto e cancelou 18%, contra 42% da Booking.
   - Instalar o motor do Beds24 no site das duas pousadas, com pagamento ou Pix na reserva.
   - Botão "Reservar" em destaque no site, no Instagram e no Perfil da Empresa no Google.
   - Garantir que as tarifas do motor sigam o PriceLabs (preço do Beds24).
   - É o pré-requisito dos itens 1 e 2.
1. **Google, primeiro mês (nov/26):**
   - ativar os links gratuitos de reserva pelo Hotel Center no Beds24. Exige o motor de reservas do Beds24 no site, o Perfil da Empresa no Google com nome e endereço idênticos aos do Beds24 e preço para 2 pessoas;
   - completar o Perfil da Empresa no Google: fotos por suíte, a hidro com vista, atributos e post semanal.
   - Atenção: com estadia mínima de 2, o Google mostra a data como fechada. É o custo da Regra H.
2. **Google Hotel Ads pago só no vale** (dias úteis de fevereiro, março, agosto a novembro):
   - num estudo de Cornell (2025) com 26 hotéis boutique, o link grátis somado ao anúncio pago deu +41% de reservas pelo Google, e 4 em 5 das reservas a mais foram diretas;
   - com a demanda forte, desligar. O orçamento é do dono.
3. **WhatsApp Business como balcão de vendas:**
   - catálogo de pacotes, respostas rápidas e etiquetas por etapa;
   - meta de resposta em menos de 5 minutos;
   - fechar com link de pagamento ou Pix (pré-pagamento, que derruba o cancelamento do direto de cerca de 45%);
   - o preço do WhatsApp segue a tabela (`reservas_abaixo.py` confere).
4. **Base de hóspedes (CRM):**
   - no check-in, pegar e-mail e WhatsApp reais e a data do aniversário de namoro ou casamento;
   - mandar oferta 45 dias antes da data;
   - na 3ª estadia, uma noite ou um jantar de cortesia, só no direto;
   - pela Revinate, cada e-mail capturado vale cerca de US$ 10 de receita.
5. **Vale-presente:**
   - Natal: vender de 01/11 a 24/12;
   - Dia dos Namorados: vender de 01/05 a 12/06;
   - vale para usar de domingo a quinta ou no vale, com datas de bloqueio.
   - Entra caixa antes e enche os dias úteis.
6. **Instagram e Meta Ads:**
   - 1 Reels por semana ("a hidro com neblina", "monte seu pedido de casamento");
   - anúncio para casais num raio de 250 a 400 km, mais remarketing do site, com público semelhante tirado da base de hóspedes;
   - campanhas por gatilho: Natal, Namorados, pacote de dia útil;
   - medir o custo por reserva desde a primeira semana (não há referência brasileira confiável).
7. **Reservas lançadas pela equipe:**
   - tabela de preço por canal, impressa, para a recepção;
   - toda reserva manual com o preço da tabela;
   - bloqueio vira bloqueio, e não "reserva" de R$ 1.

## 7. Pilar D: criar demanda nos dias úteis e no vale (o dono decide; a skill precifica)

Pacotes são vendidos no direto e no WhatsApp, com o preço da diária pela tabela do dia mais o serviço. A skill não grava pacote no PriceLabs: ela só garante que a diária base respeite os pisos.

| Quando | Pacote ou ação | Público |
|---|---|---|
| Todo domingo a quinta | "Leve 3, pague 2" (diretamente, preço fechado) e café da manhã no quarto | casais de SP |
| nov a dez | "Natal a dois": luzes da cidade, fondue e vinho | casais |
| fev (depois do Carnaval) a mar | "Temporada do fondue" e "detox a dois" (hidro com sais, massagem) | casais 30+ |
| mar a mai e set a nov, dias úteis | 3 noites para casais 50+, com passeios (Amantikir, bondinho) | aposentados |
| todo o ano, dias úteis | workation: 4 a 5 noites com Wi-Fi testado | casais em home office |
| abr a jun | pedido de casamento, aniversário de namoro, lua de mel | noivos |
| baixa | mini-wedding e elopement (casamento só do casal), retiro de diretoria | eventos pequenos |
| a decidir | 2 ou 3 suítes que aceitem pet, com taxa (46% dos viajantes brasileiros preferem) | casais com pet |

Parcerias: restaurantes de fondue, vinícolas e cervejarias da Mantiqueira, Amantikir e passeios a cavalo. Comissão cruzada ou cortesia.

## 8. Pilar E: reputação

1. Responder 100% das avaliações em até 48 horas, com a resposta personalizada. Nas negativas, dizer o que foi corrigido.
2. Pedir avaliação no check-out com QR code do Google e mandar um WhatsApp 24 horas depois.
3. Perguntar no meio da estadia: "está tudo certo?". Resolver antes de virar nota.
4. Villa: atacar as 3 queixas mais citadas nas avaliações de 2026 (a Booking dá mais peso às recentes desde 2025).
5. Efeito medido no mercado: +1 ponto de reputação (escala de 5) permite cobrar cerca de 11% a mais com a mesma ocupação (Cornell, 2012).

## 9. Calendário de execução

Cada mês: o que abre na skill (preço e estadia), que campanha o dono lança e o que a skill confere.

| Mês | Skill (preço e estadia) | Dono (canais e marketing) | Conferir |
|---|---|---|---|
| nov/26 | Consciência Negra; Natal e Réveillon abertos; vales com escada; abrir o Carnaval em 22/12 | Recanto de volta na Booking; mapeamentos (Q2, Airbnb da Villa); Google Hotel Center e Perfil da Empresa; WhatsApp Business; vale-presente de Natal; tarifa não reembolsável | ritmo de dia útil do Recanto (03/11); livro de novembro da Villa |
| dez/26 | Natal e Réveillon sem desconto antes de D10; janeiro com escada | campanha "fim de ano" no Meta; captar contatos dos hóspedes das festas | reservas abaixo do planejado nas festas |
| jan/27 | Aniversário de SP; abrir o Carnaval; dias úteis de janeiro com Regra I | e-mail para os hóspedes de 2026 ("volte no Carnaval ou no vale"); Google pago nos dias úteis | cancelamento do Carnaval |
| fev/27 | Carnaval (3 noites); vale depois do Carnaval; abrir a Páscoa em 09/02 | "Temporada do fondue"; pacote 50+; Travel Proud | ocupação de dias úteis do vale |
| mar/27 | Páscoa; abrir o Corpus Christi em 27/03; **revisão: férias escolares de 2027 e metas de maio a outubro** | campanha do Dia dos Namorados (pedido de casamento); vale-presente | lei das férias confirmada? |
| abr/27 | Tiradentes; abrir o 9 de Julho e o Festival em 01/04; abrir os Namorados em 12/04 | Meta Ads de inverno ("reserve cedo"); parceria com o Festival | ritmo de julho contra o ano passado |
| mai/27 | Corpus Christi (3 noites); frio começando: subir a faixa de sexta e sábado | vale-presente dos Namorados (01/05 a 12/06) | vendido de junho e julho |
| jun/27 | Namorados (sábado, 2 noites); fim de junho em alta se as férias se confirmarem | conteúdo diário de inverno; Google pago desligado | cancelamento do inverno |
| jul/27 | **pico**: 9 de Julho (3 noites), Festival; sem desconto antes de D10; 2 noites | captar contatos e avaliações (maior volume do ano) | meta de julho |
| ago/27 | Festa da Cerejeira (fins de semana); queda depois do inverno com escada | e-mail para os hóspedes do inverno ("volte na primavera"); Google pago nos dias úteis | ocupação de dias úteis |
| set/27 | 7 de Setembro (emenda); CBQ; L'Étape; abrir o 12 de Outubro em 10/08 | pacote de primavera; eventos pequenos | — |
| out/27 | 12 de Outubro; Finados 2027; abrir o Natal de 2027 | balanço dos 12 meses; vale-presente de Natal de 2027 | plano do ano 2 |

## 10. Governança (dentro da skill)

- **Todo dia** (rotinas das 07:53 às 19:53):
  - preço e estadia pelas regras;
  - `reservas_abaixo.py` no topo do relatório;
  - busca diária dos concorrentes.
- **Toda segunda:**
  - revisão semanal;
  - painel semanal (`painel_semanal.py`);
  - `desempenho_mes.py`;
  - 3 a 5 leituras no relatório, com a linha "plano de 12 meses: no ritmo / atrás" do mês.
- **Primeira segunda do mês:**
  - revisar este plano (seção 9: o que abre no mês e o que o dono lança);
  - sazonalidade (`sazonalidade-campos`);
  - metas dos 6 meses seguintes;
  - fechar o mês anterior contra a meta.
  - Registrar em `historico-da-skill.md`.
- **A cada trimestre (jan, abr, jul, out):**
  - reavaliar os indicadores da seção 2;
  - decidir com o dono o Preferred Partner, o Google pago e os pacotes que ficam.
- **O que a skill não faz:** promoções e Genius da Booking, limites, base, mínimo e máximo do anúncio, estadia de feriado, Beds24, extranets, marketing e pacotes. Tudo isso fica em `dados/pendencias.json` como responsável "dono".

## 11. Fontes (pesquisa de 21/10/2026)

- PriceLabs: personalizações, noites órfãs e ajuste por ocupação (help.pricelabs.co; hello.pricelabs.co; hotels.pricelabs.co).
- Cornell CHR-2025-07 (Wang, Larson e Anderson), sobre links gratuitos e anúncios pagos do Google em 26 hotéis boutique; Anderson (2012), sobre avaliações e diária (sha.cornell.edu).
- Beds24 Wiki: Google Hotel Ads e trivago.
- Brasilturis e Mercado & Eventos (06/2026): comissão do Preferred Partner da Booking a 18% e representação no Cade.
- FOHB/Noctua (09/2025): 45% de reservas diretas na hotelaria brasileira.
- D-EDGE, via Hospitality Net: cancelamentos por canal (Booking 37%, direto 11%).
- Revinate 2025: valor por e-mail capturado.
- Booking Holdings: Travel Proud; Booking: tarifa não reembolsável, via Bluetent.
- ANBIMA (feriados de 2027); Poder360 e Band (Lei 15.421/2026, férias escolares de 2027); Band Vale e Brasilturis (Festival de Inverno 2025 e 2026; ocupação acima de 85% no 9 de Julho de 2026); travel.com.br (Natal de Campos 2026).
- PriceLabs, dados de vizinhança (21/10/2026): ocupação mensal do mercado no ano passado e mediana de preço futuro por mês.

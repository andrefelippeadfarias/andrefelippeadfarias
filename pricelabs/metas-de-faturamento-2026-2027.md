# Metas e previsão de faturamento: outubro de 2026 a abril de 2027

Pedido do dono em 14/10/2026: metas de faturamento da baixa e da alta temporada, com os feriados dos próximos 6 meses, e previsões coerentes com os ajustes que estamos fazendo no PriceLabs. Versão 2 (revisada por três revisores independentes de IA; gerada por `scripts/metas_faturamento.py`).

**O que chamamos de faturamento:** o valor das diárias de cada reserva como está no Beds24 (campo "revenue" do PriceLabs), **antes** da comissão da Booking e do Airbnb e sem extras. É o número do relatório "Revenue On The Books" do PriceLabs, por mês de estadia, e é por ele que a rotina vai acompanhar as metas. Atenção: nas reservas do Airbnb esse campo chega ao PriceLabs com cerca de 40% a menos do que o hóspede paga (mapeamento do Beds24; pendência sua).

## 1. De onde vêm os números
- **Só há histórico nosso desde 27/08/2026** (início da conexão Beds24 → PriceLabs). Setembro é o único mês cheio: **R$ 324.612**, 511 diárias de 950 (54%), diária média R$ 635. De 1 a 14/10: 48% de ocupação, diária média R$ 559. Outubro inteiro já tem **R$ 238.221** no livro (realizado 1 a 13/10 R$ 118 mil + reservas de 14 a 31/10 R$ 120 mil).
- **O mercado** é a vizinhança de cada pousada no PriceLabs (anúncios Studio/1 quarto do Airbnb, cerca de 200 por vizinhança): ocupação e diária média realizadas em cada mês do ano passado. O ritmo de reservas do mercado para os próximos meses está no nível do ano passado (outubro e janeiro iguais; novembro e dezembro um pouco atrás).
- **Nosso índice contra o mercado** (nosso RevPAR ÷ RevPAR do mercado no mesmo mês do mesmo ano): setembro Recanto **1,48** e Villa **1,25**; de 1 a 14/10 o portfólio caiu para **1,25**. Ou seja: faturamos por unidade entre 1,25 e 1,5 vez o mercado. O cenário base usa 1,35 (Recanto) e 1,20 (Villa), entre setembro e a queda de outubro; a meta usa 1,70 e 1,50, que é o que a flutuação por faixas e as metas de ritmo de ocupação (70% a 7 dias, 50% a 15, 35% a 30) devem entregar; o piso usa 1,15 e 1,00.
- **Previsão do mês = RevPAR do mercado no mesmo mês do ano passado × crescimento × índice × unidades × dias**, por pousada. Crescimento: 1,05 na base e na meta (o mercado pede hoje de 3% a 28% acima do ano passado; nas reservas já feitas, de −8% a +28% conforme o mês; o RevPAR do mercado oscilou de −37% a +35% contra o ano anterior nos últimos 12 meses), 1,00 no piso. Teto de 95% de ocupação nos meses com pico (novembro, dezembro, fevereiro e abril no Recanto). Nenhum mês fica abaixo do livro.
- **Outubro:** livro do mês inteiro + última hora (setembro vendeu 6,6 diárias por dia com 0 a 1 dia de antecedência): base + R$ 27 mil (metade do ritmo de setembro), meta + R$ 60 mil (ritmo cheio).
- **Páscoa:** em 2027 cai em março (no ano passado, abril): março recebe o efeito líquido da Páscoa da tabela de feriados (meta do feriado menos 3 noites comuns) e abril perde R$ 4 mil (o pouco que a Páscoa de 2026 pôs no mercado de abril).
- **Feriados:** vendido até 14/10 + (ocupação-alvo × diárias − já vendidas) × diária real por noite (o que o hóspede paga), nunca abaixo do piso de feriado da skill (R$ 800 reais; R$ 1.000 no Natal, Réveillon, Carnaval e Páscoa). As metas de feriado fazem parte das metas do mês, não se somam a elas.

## 2. Previsão e metas por mês

| Mês | Mercado no ano passado (ocupação Recanto/Villa × diária média) | Já no livro em 14/10 | Piso | **Previsão base** | **Meta** |
|---|---|---|---|---|---|
| Outubro (mês inteiro) | 28%/35% × R$ 571/515 | R$ 238.221 | R$ 238.221 | **R$ 265.221** | **R$ 298.221** |
| Novembro | 35%/42% × R$ 641/545 | R$ 97.762 | R$ 220.654 | **R$ 274.802** | **R$ 344.845** |
| Dezembro | 43%/50% × R$ 734/702 | R$ 58.598 | R$ 334.243 | **R$ 416.462** | **R$ 522.529** |
| Janeiro | 36%/45% × R$ 645/583 | R$ 26.096 | R$ 254.440 | **R$ 316.966** | **R$ 397.719** |
| Fevereiro | 29%/34% × R$ 652/572 | R$ 8.239 | R$ 173.251 | **R$ 215.788** | **R$ 270.780** |
| Março | 27%/34% × R$ 564/511 (+ Páscoa 2027) | R$ 6.416 | R$ 206.416 | **R$ 257.777** | **R$ 323.658** |
| Abril | 36%/42% × R$ 695/648 (− Páscoa 2026) | R$ 4.370 | R$ 252.439 | **R$ 315.444** | **R$ 396.834** |
| **Total (1/10/2026 a 30/04/2027)** | | R$ 439.702 | R$ 1.679.664 | **R$ 2.062.460** | **R$ 2.554.586** |

Leitura: setembro fez R$ 324.612. Outubro fecha abaixo de setembro (mercado mais fraco em outubro e primeira quinzena com 48% de ocupação e diária menor); novembro fica uns 15% abaixo de setembro (mercado parecido, mas o índice da base é menor que o de setembro); dezembro sobe forte (Natal e Réveillon); janeiro fica no nível de novembro (verão é vale em Campos: concorre com a praia); fevereiro e março são o fundo do ano, com o Carnaval e a Páscoa segurando; abril volta a subir. Nota sobre novembro: o calendário mostra 72 unidades-noite ocupadas sem reserva no PriceLabs (45 delas são a Villa inteira em 5, 6 e 7/11, marcada como indisponível): se for um grupo ou bloqueio seu, o faturamento real de novembro é maior do que o livro mostra; confirme no Beds24.

## 3. Metas por temporada

Por decisão sua (14/10: "Sim janeiro é alta"), a alta destes 6 meses é **dezembro e janeiro**; novembro, fevereiro e março são baixa, e outubro e abril ficam como transição. A outra alta do ano é o inverno (junho a agosto), fora deste horizonte: a meta dela entra na revisão de abril. O calendário de demanda da skill, que vem dos dados do mercado, continua vendo janeiro como semanas de vale (3 a 21/1 e 25/1 a 4/2) com fins de semana médios: ele decide o preço de cada noite, e a sua temporada decide a meta do mês.

| Temporada | Piso | **Previsão base** | **Meta** |
|---|---|---|---|
| **Alta (sua definição): dezembro e janeiro** | R$ 588.683 | **R$ 733.428** | **R$ 920.248** |
| **dentro da alta: festas de fim de ano (18/12 a 2/1)** | R$ 237.383 | **R$ 321.695** | **R$ 395.313** |
| **Baixa: novembro, fevereiro e março** | R$ 600.321 | **R$ 748.367** | **R$ 939.283** |
| **Transição: outubro e abril** | R$ 490.660 | **R$ 580.665** | **R$ 695.055** |

Festas de fim de ano = Natal + Réveillon + 8 noites de ombro (18 a 23 e 27 a 28/12); elas já estão dentro da alta (não somar com a primeira linha).

## 4. Metas por feriado

| Feriado | Vendido em 14/10 | Piso | **Base** | **Meta** |
|---|---|---|---|---|
| Finados (30/10 a 1/11, 3 noites) | 45 de 93 diárias (48%), R$ 30.932 | R$ 39.572 (60% × R$ 800) | **R$ 50.696** (72% × R$ 900) | **R$ 63.280** (85% × R$ 950) |
| Consciência Negra (19 a 21/11, 3 noites) | 21 de 93 diárias (23%), R$ 18.989 | R$ 50.549 (65% × R$ 800) | **R$ 65.375** (78% × R$ 900) | **R$ 79.829** (88% × R$ 1000) |
| Natal (24 a 26/12, 3 noites) | 2 de 93 diárias (2%), R$ 1.614 | R$ 55.414 (60% × R$ 1000) | **R$ 76.139** (75% × R$ 1100) | **R$ 94.074** (85% × R$ 1200) |
| Réveillon (29/12 a 2/1, 5 noites) | 23 de 155 diárias (15%), R$ 20.959 | R$ 115.009 (70% × R$ 1100) | **R$ 156.896** (85% × R$ 1250) | **R$ 188.399** (92% × R$ 1400) |
| Aniversário de SP (22 a 24/1, 3 noites) | 3 de 93 diárias (3%), R$ 2.521 | R$ 33.601 (45% × R$ 800) | **R$ 47.401** (60% × R$ 850) | **R$ 60.085** (72% × R$ 900) |
| Carnaval (5 a 9/2, 5 noites) | 5 de 155 diárias (3%), R$ 5.603 | R$ 101.353 (65% × R$ 1000) | **R$ 122.416** (75% × R$ 1050) | **R$ 150.143** (88% × R$ 1100) |
| Páscoa (25 a 27/3, 3 noites) | 0 de 93 diárias (0%), R$ 0 | R$ 55.800 (60% × R$ 1000) | **R$ 70.308** (72% × R$ 1050) | **R$ 86.955** (85% × R$ 1100) |
| Tiradentes (20 a 21/4, 2 noites) | 2 de 62 diárias (3%), R$ 924 | R$ 21.644 (45% × R$ 800) | **R$ 28.209** (55% × R$ 850) | **R$ 35.394** (65% × R$ 900) |
| **Soma dos 8 feriados** | | R$ 472.942 | **R$ 617.440** | **R$ 758.159** |

- Como está hoje (calendário de 14/10): Oktoberfest (16 e 17/10) já com 90% vendido (R$ 31 mil); Finados 48% (Queen 7 com 2 de 21 e King Spa 2 com 1 de 6; o que falta está carregado a R$ 1.095 reais por noite pelo +20% seu); Consciência Negra 27%; Natal 2%; Réveillon 17% nas 5 noites (31/12 com 42%; Balcony 5 de 6). 15/11 cai no domingo: fim de semana comum, sem meta própria.
- Natal e Réveillon estão carregados hoje acima da diária da base (R$ 1.400 a 1.500 reais nas noites livres): a flutuação só desce um degrau quando a noite estiver atrás da meta de ritmo; a 7 dias, com sobra, pico desce para "alto", nunca para "médio".
- Aniversário de São Paulo e Tiradentes são feriados fracos (mercado de janeiro igual ao de novembro; Tiradentes isolado na quarta): o piso deles em `quartos.json` passou de R$ 1.000 para R$ 800 reais, dentro do que a regra permite (nunca abaixo de R$ 800).

## 5. Como vamos acompanhar
- **Toda segunda (revisão semanal):** livro de cada mês (Report Builder por mês de estadia) contra piso, base e meta, com a leitura "no ritmo / atrás / à frente" pelo que o mês costuma ter vendido a 30, 15 e 7 dias (em setembro, 34% das diárias foram vendidas com 0 a 1 dia de antecedência e pelo menos 48% dentro de 7 dias). A revisão só relata; a reação por noite continua a da flutuação por faixas, um degrau por noite por dia.
- **Primeiro dia útil do mês:** mês fechado contra a meta, diária média e ocupação por quarto; o índice contra o mercado é refeito com o mês realizado e as metas dos 6 meses seguintes são recalibradas (basta atualizar os insumos do script).
- **Feriados:** vendido contra a meta a 30, 15 e 7 dias. A 7 dias com menos de 50% vendido, a rotina sugere a você liberar 1 noite (feriadões; o Réveillon não) e o preço desce um degrau, nunca para "baixo"; a decisão de liberar é sua.
- Os números ficam em `.claude/skills/gestao-receita-pousadas/dados/metas_faturamento.json`; o relatório da rotina ganha o item "Faturamento".

## 6. Metas do dono (14/10) e os três níveis de operação

Você fixou as metas: **baixa temporada, Villa R$ 150 mil e Recanto R$ 220 mil; alta temporada, Villa no mínimo R$ 300 mil e Recanto R$ 350 mil.** Li como faturamento **mensal por pousada** (se for por temporada inteira, avise que refaço). Por decisão sua (14/10), a alta destes 6 meses é dezembro e janeiro e, no ano, também o inverno (junho a agosto); outubro, novembro, fevereiro, março e abril são baixa.

| Pousada e temporada | Mínimo (sua meta) | RevPAR exigido (R$ por unidade-noite) | Realista (estratégia atual a pleno, índice 1,70/1,50) | Máximo (índice 2,0/1,8: topo da faixa alvo e ocupação nas metas de ritmo) | Hoje (ritmo atual, índice 1,35/1,20) |
|---|---|---|---|---|---|
| Recanto, baixa (novembro) | **R$ 220.000** | R$ 458 | R$ 182.612 | R$ 214.838 | R$ 145.015 |
| Villa, baixa (novembro) | **R$ 150.000** | R$ 333 | R$ 162.233 | R$ 194.679 | R$ 129.786 |
| Recanto, alta (dezembro) | **R$ 350.000** | R$ 706 | R$ 265.465 | R$ 312.312 | R$ 210.811 |
| Villa, alta (dezembro) | **R$ 300.000** | R$ 645 | R$ 257.064 | R$ 308.476 | R$ 205.651 |
| Recanto, alta (janeiro) | **R$ 350.000** | R$ 706 | R$ 205.581 | R$ 241.860 | R$ 163.255 |
| Villa, alta (janeiro) | **R$ 300.000** | R$ 645 | R$ 192.139 | R$ 230.566 | R$ 153.711 |

Leitura honesta: na **Villa**, sua meta da baixa (R$ 150 mil) cabe no realista (R$ 162 mil em novembro) e a da alta (R$ 300 mil) está entre o realista e o máximo em dezembro. No **Recanto**, a meta da baixa (R$ 220 mil) é 20% acima do realista e igual ao máximo; a da alta (R$ 350 mil) passa do máximo que o mercado do ano passado permite. Setembro, o melhor mês medido, fez R$ 182 mil no Recanto. Para o Recanto chegar lá é preciso subir ocupação **e** diária ao mesmo tempo: por exemplo, 60% de ocupação a R$ 765 reais por noite na baixa (hoje: 57% a R$ 667) e 70% a R$ 1.010 na alta.

**Janeiro como alta:** a meta sobe para R$ 350.000 no Recanto e R$ 300.000 na Villa. Pelo mercado do ano passado (janeiro: 36% de ocupação no Recanto e 45% na Villa), o realista é R$ 205.581 e R$ 192.139 e o máximo R$ 241.860 e R$ 230.566: sua meta fica 45% acima do máximo no Recanto e 30% na Villa. Na prática, janeiro só chega lá se vender bem acima do mercado do ano passado (RevPAR de R$ 706 no Recanto e R$ 645 na Villa por unidade-noite, contra R$ 414 e R$ 413 no realista). Hoje o livro de janeiro tem R$ 26.096 (as duas pousadas). O modo por faturamento cuida disso sem mexer nas travas: enquanto o livro de janeiro estiver abaixo do realista no ritmo esperado, a pousada fica em modo volume (vender diárias); só passa a modo preço se alcançar a sua meta.

**O que muda na estratégia (modo por faturamento, regras.md 4c):** toda segunda a revisão compara o livro do mês com estes níveis e grava um modo por pousada em `quartos.json`, que a flutuação por faixas passa a obedecer:
- **Modo volume** (livro atrás do realista no ritmo esperado): todos os quartos da pousada agem como classe baixa: a flutuação pode descer um degrau já na meta (até a faixa alvo −1), corte de última hora pleno em D0 a D3, dias úteis no mínimo do quarto, sábado abaixo de 50% vira sugestão de liberar 1 noite. Objetivo: diárias.
- **Modo padrão** (entre os dois limiares; o menor deles é a linha de baixo, o maior é a de cima, porque na Villa o seu mínimo fica abaixo do realista e no Recanto acima): as regras de hoje, classe de cada quarto.
- **Modo preço** (no seu mínimo ou acima): todos os quartos agem como classe alta: +5% já na meta até a faixa alvo +1, +10% à frente até alvo +2, corte de última hora só na última unidade, base do anúncio +5% a +10% na segunda. Objetivo: diária.
- As travas não mudam em nenhum modo: padrão de qualidade de R$ 1.000, pisos de feriado, descontos da Booking, sábado sozinho fechado, ±15% da base por semana. O modo nunca é mudado pela rotina leve.
- **Ritmo esperado do livro** (referência inicial, recalibrada todo mês): 40% do realista no primeiro dia do mês, 65% no dia 15, 80% a 7 dias do fim; em setembro, 34% das diárias vieram com 0 a 1 dia de antecedência.

## 7. O que pode mudar as previsões
- Primeira temporada com dados: o índice foi medido em um mês e meio; a cada mês fechado ele é refeito e as metas, ajustadas.
- Dezembro depende de vender Natal com 2 noites e Réveillon com 3 noites a R$ 1.000 reais ou mais; hoje estão em 2% e 17%, normal a 70 dias, mas é o ponto a vigiar de 15/11 em diante.
- O mercado perdeu de 11% a 37% de RevPAR em 5 dos 7 meses equivalentes do ano passado e ganhou 35% em setembro: a base assume +5%; se o mercado cair na última hora, a Villa (dias de semana de última hora) sente primeiro.
- O que mais pesa e só você resolve: visibilidade dos anúncios (Google, Booking, canais fixos da Villa) e o mapeamento do Airbnb no Beds24; Queen 7 e Balcony só vendem de última hora.

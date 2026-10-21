# Plano de 12 meses para alavancar reservas (nov/2026 a out/2027)

Versão 1.1, de 21/10/2026, revisada contra `regras.md`, `estrategia.md` e os arquivos de dados por um revisor independente (19 correções).

**Pedidos do dono:**
- "Defina agora a estratégia para alavancar as reservas para os próximos 12 meses. Pesquise as melhores práticas no mercado e vamos estruturar isso tudo fazendo tudo de acordo com nossa skill."
- "Temos que sempre levar em consideração a precificação do mercado como um todo também. Vamos continuar fazendo como planejamos nas skills."

Este plano **não muda regra nem limite**:
- **A skill faz sozinha:** preço e estadia por data, dentro de `regras.md`, `estrategia.md` e `limites_seguranca`, sempre em percentual e ancorados no mercado (faixas 4c, referência hidro+vista do §5 e busca diária).
- **O plano lista à parte:** o que só o dono decide ou faz na tela.
- **Em caso de conflito:** onde este arquivo divergir de `regras.md`, `estrategia.md`, `dados/quartos.json` ou `dados/sazonalidade.json`, valem eles.
- **Revisão:** na primeira segunda de cada mês (seção 10).

## 1. Diagnóstico

### 1a. Hoje (Beds24 e PriceLabs, 21/10/2026)

| Indicador | Recanto | Villa | Leitura |
|---|---|---|---|
| Outubro, realizado + vendido | R$ 144 mil (mínimo de R$ 220 mil) | R$ 115 mil (mínimo de R$ 150 mil) | Recanto acima do realista do mercado (R$ 141,5 mil); a Villa ainda pode chegar |
| Ocupação de domingo a quinta, 07/09 a 20/10 | 55%, diária de R$ 480 | 40%, diária de R$ 512 | **o ponto fraco** |
| Ocupação de sexta e sábado, 07/09 a 20/10 | 62%, R$ 707 | 66%, R$ 678 | — |
| Antecedência mediana da reserva | 5 dias (46% em até 3 dias) | 4,5 dias (44% em até 3 dias) | mercado de última hora, de carro, vindo de SP |
| Receita pela Booking (últimos 28 dias) | 78% | 63% | Preferred Partner a 18% desde 01/07/2026 |
| Receita direta pelo site ou API (outubro) | 8% | 11% | com os lançamentos à mão: 12% e 31% |
| Diária média no Airbnb | R$ 455 | **R$ 300** | Villa barata no Airbnb (fator 0,31) |
| Lançamentos à mão da equipe | diária de R$ 397; 42% cancelados | 25% cancelados | erro de tarifa e bloqueio disfarçado de reserva |
| Reservas do site canceladas | 48% | 42% | pagamento não garantido no motor |
| Livro de novembro | R$ 105 mil | R$ 45 mil | Villa em novembro é o mês mais atrasado |
| Nota na Booking (`regras.md` §5) | 8,4 | 8,1 | subir a nota rende mais que desconto |

**Mercado** (PriceLabs, vizinhança; ocupação do ano passado):

| Grupo | Meses |
|---|---|
| Pico | julho (61%) |
| Fortes | junho (50%); maio e dezembro (42%) |
| Médios | abril, novembro e janeiro (35–36%); agosto e setembro (36–37%) |
| Vale | fevereiro (29%), março (27%) e outubro (28%) |

Fim de semana bem acima do dia útil.

### 1b. Histórico próprio do sistema anterior (`dados/historico_proprio.json`, planilhas enviadas em 21/10)

O valor é a diária média bruta recebida. "Sem valor" inclui cancelamento e não comparecimento. A ocupação pode estar até cerca de 2 pontos acima, porque em 20 noites de 2024 o Recanto passa de 16 unidades. A Villa abriu em maio de 2024. Falta 2025 inteiro (pendência `historico-beds24-2025`).

| Mês | Recanto 2024 | Recanto 2026 | Villa 2024 | Villa 2026 |
|---|---|---|---|---|
| jan | R$ 257 mil, 73%, R$ 706 | R$ 215 mil, 59%, R$ 735 | — | R$ 119 mil, 42%, R$ 608 |
| fev | R$ 218 mil, 69%, R$ 680 | R$ 155 mil, 44%, R$ 795 | — | R$ 115 mil, 40%, R$ 686 |
| mar | R$ 269 mil, 74%, R$ 731 | R$ 134 mil, 51%, R$ 530 | — | R$ 64 mil, 33%, R$ 424 |
| abr | R$ 270 mil, 68%, R$ 829 | sem dado | — | — |
| mai | R$ 328 mil, 74%, R$ 899 | sem dado | R$ 41 mil (abertura) | — |
| jun | R$ 303 mil, 49%, R$ 1.290 | sem dado | R$ 105 mil, 25% | — |
| jul | **R$ 540 mil, 87%, R$ 1.246** | sem dado | R$ 199 mil, 41%, R$ 1.048 | — |
| ago | R$ 372 mil, 90%, R$ 831 | sem dado | R$ 116 mil, 31% | — |
| set | R$ 260 mil, 70%, R$ 773 | R$ 182 mil (Beds24) | R$ 78 mil, 35% | R$ 142 mil (Beds24) |
| out | R$ 259 mil, 78%, R$ 665 | R$ 144 mil (realizado + vendido) | R$ 89 mil, 41% | R$ 115 mil |
| nov | R$ 262 mil, 74%, R$ 735 | R$ 105 mil vendidos | R$ 105 mil, 46% | R$ 45 mil vendidos |
| dez | R$ 253 mil, 64%, R$ 794 | R$ 48 mil vendidos | R$ 131 mil, 50% | R$ 26 mil vendidos |

Leituras (o mercado continua mandando no preço; o histórico mostra potencial):

1. **2024 no total:** o Recanto fez cerca de R$ 3,6 milhões, e nenhum mês ficou abaixo de R$ 217 mil. O mínimo do dono, de R$ 220 mil, fica perto do piso histórico.
2. **2026 contra 2024:**
   - No 1º trimestre de 2026, as duas pousadas juntas fizeram R$ 802 mil, contra R$ 745 mil só do Recanto em 2024. O Recanto caiu de 16% a 50% por mês.
   - Em outubro, as duas juntas (R$ 259 mil) ficam 25% abaixo de 2024 (R$ 347 mil).
   - **Hipótese a testar** com 2025: a Villa pode estar tirando hóspedes do Recanto. Isso reforça o posicionamento separado (`estrategia.md`: Recanto no terço superior da faixa e Villa no inferior), também na comunicação.
3. **Dias de semana do Recanto:**

   | Período | Domingo a quinta | Sexta e sábado |
   |---|---|---|
   | 2024 | 64% a R$ 717 | 77% a R$ 1.111 |
   | 1º trimestre de 2026 | 44% a R$ 540 | — |
   | 07/09 a 20/10/2026 | 55% a R$ 480 | — |
4. **Canal direto:** em 2024, o motor do site trouxe **34% da receita do Recanto** (R$ 1,22 milhão) e 27% da Villa. Hoje são 8% e 11%.
5. **Reservas sem valor em 2024** (aproximação do cancelamento): Booking 42% no Recanto e 50% na Villa; site 18% nas duas.
6. **Antecedência em 2024:** mediana de 3 dias (52% em até 3 dias). A última hora sempre foi o padrão.
7. **Feriados de 2024 no Recanto** (ocupação e diária bruta recebida):

   | Feriado | Ocupação | Diária |
   |---|---|---|
   | Sábados do Festival | 100% | R$ 2.002 |
   | Corpus Christi | 88% | R$ 1.720 |
   | 9 de Julho | 98% | R$ 1.425 |
   | Réveillon | 90% | R$ 1.329 |
   | Páscoa | 88% | R$ 1.242 |
   | Carnaval | 61% | R$ 1.206 |
   | 12 de Outubro | 84% | R$ 855 |
   | Natal | 69% | R$ 712 |
   | Consciência Negra | 94% | R$ 587 |

   Os picos de inverno e Corpus Christi são onde o mercado já pagou mais. O teste de piso da seção 4 vale só lá e só se a busca do mesmo dia sustentar.
8. **Carteira em 11/03/2026 para 27/05 a 28/06/2026:** 67 reservas e R$ 116 mil (Recanto R$ 84,6 mil; Villa R$ 31,1 mil). Comparar com a carteira de 2027 na revisão de março.

### 1c. O que bloqueia o crescimento hoje (pendências do dono, urgência 0)

1. **Recanto fora da busca da Booking** desde 21/10, com 4 cancelamentos em 2 minutos.
2. **Q2 na Booking com tarifa fixa** de R$ 412 a R$ 495.
3. **Reservas lançadas à mão abaixo da tabela:** 8 graves, de 17% a 45% do esperado.
4. **Overbooking na VK2 em 24/10.**

Também bloqueia o canal direto: **o direto vende a 36%–45% do preço do PriceLabs** (pendência `canal-direto-45pct`). Enquanto isso não for corrigido no motor e no Beds24, levar reserva para o direto não economiza comissão: perde diária.

## 2. Objetivos e indicadores

| Indicador | Hoje | Abril/2027 | Outubro/2027 | Onde se mede |
|---|---|---|---|---|
| Receita contra o mínimo do dono | outubro: 66% (Recanto), 77% (Villa) | ≥ 85% em cada mês | ≥ 100% em cada mês | painel semanal; `desempenho_mes.py` |
| Ocupação de domingo a quinta | 55% / 40% | 55% / 48% | 60% / 55% | painel, "Ocupação por semana" |
| Receita direta pelo site, API ou WhatsApp (sem lançamento à mão) | 8% / 11% | 15% | 25% | painel, "Por canal" |
| Diária do direto ÷ price1 | 0,36 / 0,45 | ≥ 0,85 | ≥ 0,95 | `reservas_abaixo.py`, fator_canal |
| Reservas do site canceladas | 48% / 42% | < 25% | < 15% | `desempenho_mes.py` |
| Reservas abaixo de 70% do planejado | 8 em 3 semanas | 0 graves por semana | 0 | `reservas_abaixo.py` |
| Nota na Booking | 8,4 / 8,1 | 8,6 / 8,4 | 8,8 / 8,6 | extranet |
| Tempo de resposta (WhatsApp e OTA) | não medido | < 15 min | < 5 min | WhatsApp Business |

Referências de mercado:
- 45% das reservas da hotelaria brasileira são diretas (pesquisa FOHB/Noctua com dados de 2024, publicada em 09/2025).
- A comissão da Booking é de 15% a 18%. O custo do direto é indicativo, de 4% a 5% (Sojern, fornecedor) quando não depende de anúncio pago.

## 3. Metas de faturamento por mês

Valores em R$ mil, no formato **mínimo do dono / realista do mercado / livro**. O livro é o que estava vendido em 21/10.

- **Novembro a abril:** `dados/metas_faturamento.json`.
- **Maio a outubro de 2027:** estimados em 21/10 pelo mesmo método (ocupação do mercado no ano passado × diária do mercado × 1,05 × índice × quartos × dias). O mínimo segue a temporada do dono (`regras.md` §6a, 14/10: dezembro, janeiro e junho a agosto são alta), a confirmar (pendência `metas-mai-out-2027`).
- **Coluna "Recanto 2024":** a receita do Recanto em 2024, só como referência de potencial, nunca como alvo de preço (dono, 21/10).

| Mês | Recanto | Villa | Recanto 2024 | O que puxa o mês (noites dos arquivos) |
|---|---|---|---|---|
| nov/26 | 220 / 183 / 105 | 150 / 162 / 45 | 262 | Consciência Negra (noites de 19 a 21/11); Natal de Campos (desde 30/10) |
| dez/26 | 350 / 265 / 48 | 300 / 257 / 26 | 253 | Natal (24 a 26/12); Réveillon (29/12 a 02/01); férias |
| jan/27 | 350 / 206 / 30 | 300 / 192 / 6 | 257 | férias; Aniversário de SP (22 a 24/01) |
| fev/27 | 220 / 142 / 8 | 150 / 129 / 3 | 218 | Carnaval (05 a 09/02); depois, o vale |
| mar/27 | 220 / 135 / 5 | 150 / 127 / 2 | 269 | Páscoa (25 a 27/03); vale de dias úteis |
| abr/27 | 220 / 208 / 1 | 150 / 193 / 3 | 270 | Tiradentes (20 e 21/04) |
| mai/27 | 220 / 299 / 8 | 150 / 247 / 2 | 328 | Corpus Christi (26 a 29/05); frio começando |
| jun/27 | 350 / 347 / 14 | 300 / 287 / 7 | 303 | Dia dos Namorados (sábado 12/06); recesso escolar a partir de 24/06 (Lei 15.421/2026, a confirmar) |
| jul/27 | 350 / 447 / 2 | 300 / 370 / 1 | 540 | **pico**: 9 de Julho (08 a 10/07); Festival de Inverno (estimado de 03/07 a 01/08) |
| ago/27 | 350 / 234 / 3 | 300 / 194 / 0 | 372 | Festa da Cerejeira (fins de semana) e queda depois do inverno |
| set/27 | 220 / 217 / 3 | 150 / 180 / 0 | 260 | 7 de Setembro (03 a 06/09); congresso CBQ (15 a 18/09); L'Étape (24 e 25/09) |
| out/27 | 220 / 178 / 0 | 150 / 147 / 0 | 259 | 12 de Outubro (08 a 11/10); Finados 2027 (29/10 a 01/11) |

**Meses em que o realista do mercado fica abaixo do mínimo:**
- **Os dois:** dezembro a março, junho (por pouco), agosto e outubro.
- **Só o Recanto:** novembro, abril e setembro.

Nesses meses vale a trava de lotação e o modo por faturamento (`regras.md` §4c). O que fecha a diferença não é preço acima do mercado: é ocupação de dia útil (pilares C e D) e mais canal direto. Julho é o maior mês do ano, no mercado e no Recanto em 2024. **Agosto é o risco** do inverno.

## 4. Pilar A: preço e estadia (a skill faz sozinha, dentro das regras)

Tudo continua como a skill já faz:
- percentual em `percent_base`;
- pisos do dono: R$ 500 de domingo a quinta e R$ 850 em sexta e sábado, no preço final do Genius 3;
- pisos de feriado de `quartos.json`: R$ 1.000 no bolso nos principais e R$ 800 na Consciência Negra, no Aniversário de SP e em Tiradentes;
- validador antes de gravar e conferência no recálculo.

O preço de cada noite segue a ordem de `estrategia.md` §4 e `regras.md` §4c e §5. A faixa vem só de `sazonalidade.json`.

1. **Mercado de última hora.** Metade das reservas entra a 5 dias, então a decisão que mais rende é a dos últimos dias:
   - degrau por noite atrás da meta da janela (4c) e aproximação hidro+vista (§5), como hoje;
   - Regra I de D0 a D3: em 13/10 vendeu 5 noites em 7 horas.
   - **Medir até 03/11** (pendência `ritmo-dia-util-recanto-0311`) o efeito dos pisos de 20/10 no dia útil do Recanto (Q7 a R$ 1.232 enviados). Se o ritmo de dia útil continuar abaixo de 6 reservas por dia, levar ao dono uma proposta de piso de dia útil por janela. Só ele muda o limite.
2. **Abertura de feriados e picos** (`estrategia.md` §3: picos com 60 a 90 dias e sem desconto antes de D10; feriadões com 30 a 45 dias e, a 7 dias com menos de 50%, sugerir 1 noite ao dono):

   | Data | Tipo | Abre em |
   |---|---|---|
   | Carnaval (noites 05 a 09/02) | feriadão | 22/12 |
   | Páscoa (25 a 27/03) | feriadão | 09/02 |
   | Corpus Christi (26 a 29/05) | pico | 27/03 |
   | Dia dos Namorados (11 e 12/06) | pico | 12/04 |
   | 9 de Julho (08 a 10/07) | pico | entre 09/04 e 09/05 |
   | 7 de Setembro (03 a 06/09) | feriadão | entre 20/07 e 04/08 |
   | 12 de Outubro (08 a 11/10) | feriadão | entre 24/08 e 08/09 |
   | Finados 2027 (29/10 a 01/11) | feriadão | entre 14 e 29/09 |
   | Natal 2027 (24 e 25/12) | 15 a 30 dias | entre 24/11 e 09/12 |
   | Réveillon 2027 | pico | 60 a 90 dias antes |

   - **Festival de Inverno 2027:** a data é estimada. Pela regra de evento estimado, a faixa só sobe com confirmação. Em D-90 (04/04), a rotina abre "confirmar evento".
   - Em cada abertura, a varredura de feriado de 60 dias confere os pisos e a estadia mínima.
3. **Teste de piso acima do padrão, só em picos** (Corpus Christi, Dia dos Namorados, 9 de Julho e sábados do Festival), **sempre pelo mercado:**
   - O piso novo convertido para vitrine (piso no bolso ÷ `fator_real` × fator de vitrine) precisa ficar em até 1,10 × a mediana hidro+vista da busca diária da mesma noite. Acima disso, não há teste: o §5 de `regras.md` desceria o preço.
   - O valor não vem de 2024. Em 06/10, com um piso de R$ 1.200, o Recanto foi o 2º mais caro de 21 hotéis e ficou 3 dias sem vender.
   - **Volta ao padrão** assim que a noite ficar atrás da meta da janela em que está (4c: 25% de D30 a D44, 15% de D45 em diante), e não só a D21.
   - **Para rodar**, a skill precisa antes (pendência `teste-piso-picos`):
     - pôr os sábados do Dia dos Namorados e do Festival em `feriados` (com estadia e Regra I revistas) ou usar piso por data;
     - ampliar `mercado_diario.py` para buscar os picos a até 90 dias.
   - Cada passo vai para `aprendizados.md`.
4. **Inverno pela sazonalidade:**
   - junho: médio de domingo a quinta e alto em sexta e sábado;
   - 11 a 25/07: alto de domingo a quinta e muito alto em sexta e sábado;
   - agosto: baixo de domingo a quinta e médio em sexta e sábado.
   - A estadia mínima do inverno precisa ser alinhada: `estrategia.md` pede 2 noites, mas há trechos de `sazonalidade.json` com 1 (pendência `estadia-inverno`).
   - Se a lei do recesso escolar valer, 24/06 a 01/07 sobe para alta na revisão de março (pendência `ferias-escolares-2027`).
5. **Vales** (fevereiro e março depois do Carnaval, agosto, setembro a novembro de domingo a quinta):
   - escada (Regra F), degrau 4c e Regra I;
   - Balcony pela Regra G como porta de entrada da Villa.
   - Preço sozinho não cria demanda de dia útil: os pilares C e D é que mudam o vale.
6. **Noites órfãs:** a ferramenta "Orphan Day Prices" do PriceLabs, que vende buracos de 1 a 2 noites, fica com o dono (pendência de tela `orphan-days`).
   - Ela conflita com a Regra H (sábado sozinho) e com os pisos do dono (R$ 500 e R$ 850 no preço final, não o mínimo do anúncio).
   - Se o dono quiser, a proposta é só para buracos de domingo a quinta e sempre acima do piso de dia útil.
   - Há um conflito em `regras.md`: o §2 deixa personalizações com a skill, mas a Regra H põe noites órfãs só na tela. Fica com o dono.
7. **Reservas abaixo do planejado:** `reservas_abaixo.py` em toda rotina, no topo do relatório (já em vigor).

## 5. Pilar B: canais e OTAs (decisões e telas do dono)

**Mantido pelo dono (20/10):** as promoções da Booking ficam como estão, e o Genius vai até o nível 3. Mobile Rate, Country Rate, Getaway e Late Escape ficam fora deste plano, e só voltam se o dono pedir.

Em ordem de retorno:
1. **Recanto de volta na busca da Booking** (urgência 0): extranet → status, avisos, comissão em aberto, conteúdo pendente.
2. **Mapeamentos do Beds24:**
   - Q2 na Booking (Rate 50067466 fixa);
   - Villa na Expedia, no Hoteis.com e na Decolar;
   - repasse do Airbnb da Villa (R$ 300 de diária);
   - **preço do motor de reservas direto** (36%–45% do price1).
3. **Tarifa não reembolsável ao lado da flexível na Booking** (plano de tarifa, não promoção):
   - 7% a 10% abaixo da flexível. As fontes trazem de 5% a 10%; a Booking fala em pelo menos 9% menos cancelamentos e 5% mais reservas, citada pela Bluetent.
   - Em feriado e pico: pré-pagamento ao fim da janela grátis e cancelamento grátis até 7 dias.
   - Ela soma com o Genius e as ofertas. Antes de ligar, a skill recalibra `fator_vitrine_semana`, `fator_vitrine_fds` e `fator_canal` e regrava os pisos. Sem isso, os pisos de R$ 500 e R$ 850 deixam de valer.
4. **Página da propriedade a 100%** (Property Page Score, nota oficial de 0 a 100):
   - Villa: marcar hidromassagem, Wi-Fi e aquecimento (pendência `booking-villa-hidro`) e pôr fotos da hidro com vista;
   - Recanto: conferir o mesmo.
5. **Selo Travel Proud da Booking** (curso gratuito de 75 minutos): abre o público de casais LGBTQIA+ sem desconto.
6. **Preferred Partner (18%):** com 3 meses de dados, calcular se a visibilidade paga os 3 pontos de comissão. Na alta, a demanda já existe. O Visibility Booster, se o dono quiser, só em dia útil do vale.
7. **Airbnb:**
   - Smart Pricing desligado;
   - reserva instantânea ligada;
   - desconto semanal para 7 noites ou mais, com cuidado: o early bird do Airbnb pode furar o mínimo;
   - corrigir o repasse da Villa.
8. **Expedia:** prioridade baixa (1% da receita).

## 6. Pilar C: canal direto (o dono executa, a skill mede)

Objetivo: de 8% e 11% para 25% de receita direta sem lançamento à mão, **com a diária da tabela**. Cada R$ 1 que sai da Booking para o direto economiza cerca de R$ 0,15 de comissão, mas só depois do item 0.

**Paridade (hipótese a confirmar com o contrato da Booking):**
- A paridade "estreita", em que o site público não pode ser mais barato que a Booking, é cláusula de contrato. Ela foi aceita pelo Cade no acordo com Booking, Decolar e Expedia (IstoÉ, 2018).
- Vantagem fechada (WhatsApp, hóspede que volta, área de cliente) **parece** permitida, mas não está provado.
- O caminho seguro para o direto é benefício: upgrade, café no quarto, late checkout, jantar. Não preço público mais baixo.

0. **Recuperar o motor de reservas (pendência `motor-reservas-direto`):**
   - em 2024, o site trouxe 34% da receita do Recanto e teve 18% de reservas sem valor, contra 42% da Booking;
   - motor do Beds24 no site das duas pousadas, com o preço do PriceLabs (corrigir os 36%–45% do price1) e pagamento ou Pix na reserva;
   - botão "Reservar" no site, no Instagram e no Perfil da Empresa no Google.
1. **Google (pendência `google-hotel-center`):**
   - links gratuitos de reserva pelo Hotel Center no Beds24. Exigem o motor do Beds24, Perfil da Empresa com nome e endereço iguais e preço para 2 pessoas;
   - completar o Perfil da Empresa: fotos por suíte e da hidro com vista, atributos e post semanal;
   - pela Wiki do Beds24, o Google consulta 1 noite por data, então datas com estadia mínima de 2 aparecem fechadas. É o custo da Regra H.
2. **Google Hotel Ads pago, só no vale** (dias úteis de fevereiro, março e agosto a novembro):
   - no estudo de Cornell CHR-2025-07, com 26 hotéis boutique, o link grátis somado ao anúncio pago deu +41% de reservas pelo Google, e 4 em 5 das reservas extras foram diretas;
   - desligar com a demanda forte;
   - o orçamento é do dono.
3. **WhatsApp Business como balcão:**
   - catálogo de pacotes, respostas rápidas e etiquetas;
   - meta de resposta em até 5 minutos;
   - fechamento com link de pagamento ou Pix;
   - a diária segue a tabela (`reservas_abaixo.py` confere).
4. **Base de hóspedes:**
   - no check-in, pegar o e-mail e o WhatsApp reais e a data especial do casal;
   - mandar oferta 45 dias antes dessa data;
   - na 3ª estadia, jantar ou fondue de cortesia (benefício, não desconto), só no direto;
   - pela Revinate 2025, cada e-mail capturado gerou cerca de US$ 10.
5. **Vale-presente** (pendência `whatsapp-crm-vales`):
   - Natal: vendido de 01/11 a 24/12;
   - Dia dos Namorados: vendido de 01/05 a 12/06;
   - uso de domingo a quinta ou no vale, com datas de bloqueio.
6. **Instagram e Meta Ads:**
   - 1 Reels por semana;
   - público de casais num raio de 250 a 400 km, mais remarketing e público semelhante tirado da base;
   - campanhas de Natal, Dia dos Namorados e dia útil;
   - medir o custo por reserva desde a 1ª semana (não há referência brasileira confiável).
7. **Lançamentos da equipe:**
   - tabela de preço por canal na recepção;
   - reserva à mão sempre com o preço da tabela;
   - bloqueio é bloqueio, não reserva de R$ 1.

## 7. Pilar D: demanda de dias úteis e do vale (o dono decide, a skill garante os pisos)

Pacotes são vendidos no direto e no WhatsApp. **A diária é sempre a da tabela do dia** (nunca abaixo dos pisos nem de 70% do planejado), e o valor extra vem do serviço incluído. A skill não grava pacote no PriceLabs.

| Quando | Pacote ou ação | Público |
|---|---|---|
| Todo domingo a quinta | "3 noites com jantar ou fondue incluído" e café no quarto | casais de SP |
| Novembro e dezembro | "Natal a dois": luzes da cidade, fondue e vinho | casais |
| Fevereiro, depois do Carnaval, e março | "Temporada do fondue" e "detox a dois" (hidro com sais e massagem) | casais 30+ |
| Março a maio e setembro a novembro, em dia útil | 3 noites para casais 50+, com passeios (Amantikir e bondinho) | aposentados |
| O ano todo, em dia útil | workation: 4 a 5 noites, com Wi-Fi testado | casais em home office |
| Abril a junho | pedido de casamento, aniversário de namoro e lua de mel | noivos |
| Baixa | mini-wedding, elopement e retiro de diretoria | eventos pequenos |
| A decidir | 2 ou 3 suítes pet friendly com taxa (46% dos viajantes brasileiros preferem, segundo pesquisa da Booking citada pela Brasilturis em 2025) | casais com pet |

Parcerias: restaurantes de fondue, vinícolas e cervejarias da Mantiqueira, Amantikir e passeios a cavalo.

## 8. Pilar E: reputação (pendência `reputacao-villa`)

1. Responder 100% das avaliações em até 48 horas, de forma personalizada. Nas negativas, dizer o que foi corrigido.
2. Pedir a avaliação no check-out com QR code do Google e mandar um WhatsApp 24 horas depois.
3. Perguntar no meio da estadia e resolver o problema antes que vire nota.
4. Na Villa, atacar as 3 queixas mais citadas em 2026. Segundo a Hospitality Net (2026), a Booking dá mais peso às avaliações recentes desde 2025.
5. No mercado, +1 ponto de reputação (numa escala de 5) permitiu cerca de 11% a mais de diária com a mesma ocupação (Cornell, Anderson 2012).

## 9. Calendário de execução

| Mês | Skill (preço e estadia) | Dono (canais e marketing) | Conferir |
|---|---|---|---|
| nov/26 | Consciência Negra; Natal e Réveillon abertos; vales com escada | Recanto na Booking; mapeamentos (Q2, Airbnb da Villa, preço do motor direto); Google Hotel Center e Perfil da Empresa; WhatsApp Business; vale-presente de Natal; tarifa não reembolsável (depois que a skill recalibrar os fatores) | ritmo de dia útil do Recanto (03/11); livro de novembro da Villa |
| dez/26 | Natal e Réveillon sem desconto antes de D10; **abrir o Carnaval em 22/12**; Aniversário de SP (feriadão, entre 08 e 23/12) | campanha de fim de ano; captar contatos nas festas | reservas abaixo do planejado nas festas |
| jan/27 | Aniversário de SP; dias úteis com a Regra I | e-mail para os hóspedes de 2026; Google pago nos dias úteis | cancelamentos do Carnaval |
| fev/27 | Carnaval; vale depois do Carnaval; **abrir a Páscoa em 09/02** | "Temporada do fondue"; pacote 50+; Travel Proud | ocupação de dias úteis no vale |
| mar/27 | Páscoa; **abrir o Corpus Christi em 27/03**; revisão: recesso escolar e metas de maio a outubro | campanha do Dia dos Namorados; vale-presente | carteira de maio e junho contra 11/03/2026 (R$ 116 mil) |
| abr/27 | Tiradentes; Festival: "confirmar evento" em 04/04; **abrir os Namorados em 12/04 e o 9 de Julho entre 09/04 e 09/05** | Meta Ads de inverno; parceria com o Festival | ritmo de julho contra o ano passado |
| mai/27 | Corpus Christi (3 noites) | vale-presente dos Namorados (01/05 a 12/06) | vendido de junho e julho |
| jun/27 | Dia dos Namorados (2 noites); fim de junho em alta, se o recesso escolar se confirmar | conteúdo diário de inverno; Google pago desligado | cancelamentos do inverno |
| jul/27 | **pico**: 9 de Julho (3 noites) e Festival; sem desconto antes de D10; **abrir o 7 de Setembro entre 20/07 e 04/08** | captar contatos e avaliações | meta de julho |
| ago/27 | Festa da Cerejeira; queda depois do inverno com a escada; **abrir o 12 de Outubro entre 24/08 e 08/09** | e-mail para os hóspedes do inverno; Google pago nos dias úteis | ocupação de dias úteis |
| set/27 | 7 de Setembro; CBQ; L'Étape; **abrir Finados 2027 entre 14 e 29/09** | pacote de primavera; eventos pequenos | — |
| out/27 | 12 de Outubro; Finados 2027 | balanço dos 12 meses; vale-presente de Natal 2027 | plano do ano 2; **abrir o Natal 2027 entre 24/11 e 09/12** |

## 10. Governança (dentro da skill)

- **Todo dia (07:53 a 19:53):**
  - preço e estadia pelas regras;
  - `reservas_abaixo.py` no topo do relatório;
  - busca diária dos concorrentes.
- **Toda segunda:**
  - revisão semanal, painel semanal (`painel_semanal.py`) e `desempenho_mes.py`;
  - no relatório, a linha "plano de 12 meses: no ritmo / atrás" do mês.
- **A cada 15 dias (dono, 21/10):** revisão dos 12 meses futuros (`revisao_12m.py`, SKILL.md 4c): vendido contra o mínimo, o que entrou em 15 dias, ritmo contra o mercado e preço médio por mês e pousada. Mês atrás do mercado em duas revisões seguidas vira pendência do dono com proposta.
- **Primeira segunda do mês:**
  - revisar este plano (seção 9);
  - sazonalidade (`sazonalidade-campos`) e metas dos 6 meses seguintes;
  - fechar o mês anterior contra a meta;
  - registrar em `historico-da-skill.md`.
- **A cada trimestre (janeiro, abril, julho e outubro):** indicadores da seção 2 e decisões com o dono (Preferred Partner, Google pago, pacotes).
- **O que a skill não faz:**
  - promoções e Genius da Booking;
  - `limites_seguranca`;
  - base, mínimo e máximo do anúncio;
  - **reduzir** a estadia de feriado (manter ou subir pode);
  - Beds24, extranets, personalizações de tela que tocam a Regra H, marketing e pacotes.

  Tudo isso fica em `dados/pendencias.json` com o dono como responsável.

## 11. Fontes (pesquisa de 21/10/2026)

**Preço e distribuição**
- PriceLabs: personalizações, noites órfãs e ajuste por ocupação (help.pricelabs.co, hello.pricelabs.co, hotels.pricelabs.co).
- Cornell CHR-2025-07 (Wang, Larson e Anderson): links gratuitos e anúncios pagos do Google em 26 hotéis boutique (ecommons.cornell.edu).
- Anderson (2012): avaliações e diária (sha.cornell.edu).
- Beds24 Wiki: Google Hotel Ads e trivago (wiki.beds24.com).
- Brasilturis e Mercado & Eventos (06/2026): Preferred Partner da Booking a 18% e representação no Cade.
- IstoÉ: acordo do Cade com Booking, Decolar e Expedia sobre paridade.

**Benchmarks**
- FOHB/Noctua (publicado em 09/2025, dados de 2024): 45% de reservas diretas.
- D-EDGE, via Hospitality Net: cancelamentos por canal.
- Revinate 2025: valor por e-mail capturado.
- Sojern: custo do direto (indicativo).
- Bluetent: tarifa não reembolsável na Booking.

**Reputação e públicos**
- Booking Holdings e Smoobu: Travel Proud.
- Brasilturis (06/2025): turismo pet.
- Hospitality Net (2026): nota da Booking.

**Calendário e demanda local**
- ANBIMA: feriados de 2027.
- Poder360 e Band: Lei 15.421/2026 e férias escolares de 2027.
- Band Vale e Brasilturis: Festival de Inverno de 2025 e 2026; ocupação acima de 85% no 9 de Julho de 2026.
- travel.com.br: Natal de Campos 2026.

**Dados próprios**
- PriceLabs, vizinhança (21/10/2026).
- `dados/historico_proprio.json` (planilhas do dono, 21/10/2026).

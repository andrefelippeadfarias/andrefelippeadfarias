# Estratégia de dezembro e janeiro: Recanto dos Moinhos e Villa Dolce Amore

**Versão de 27/11/2026, revisada, para o dono decidir.** Nada foi gravado. Não chamei o PriceLabs nem o Beds24. Li a Booking só pelo conector oficial.

## Como ler

**Os três preços de cada suíte**
- **Enviado** é a tabela do PriceLabs.
- **Público** é o que a Booking mostra sem Genius.
  - Recanto: enviado × 0,585 em todas as janelas [QJ `fator_publico`; BK1].
    - Na busca de 27/11, o Recanto saiu a 0,583-0,585 do enviado também perto da data: D7 (04-06/12), D11 (08/12) e D14 (11-13/12) [BK1].
    - O 0,63 de até 14 dias saiu, porque não tem fonte. A nota de 23/11 em `quartos.json` só registra 0,63-0,66 nas noites de 21-22/11, perto da chegada [QJ; PEN `fator-publico-recanto-0583-20261118`].
  - Villa: enviado × 0,637 sem promoção [QJ `fator_publico.revisao_semanal_2026-11-23`]. É estimativa: o fator certo é medido depois de a promoção sair (decisão 4).
- **Genius 3** é o preço do hóspede mais descontado.
  - Recanto: × 0,41 de domingo a quinta e × 0,425 na sexta e no sábado.
  - Villa: × 0,53 e × 0,575 [QJ `fator_vitrine_semana` e `fator_vitrine_fds`].
- **Nas tabelas do plano** (fins de semana e festas do Recanto), o enviado sai do público da régua pelo fator da própria régua: 0,6156-0,6157 no Recanto [`dados/regua_dono.json`, `fator_publico`]. A 0,585, a Booking mostra cerca de 5% menos que esse público.
- **A Villa com promoção ativa mostra outro público:** de 0,38 a 0,51 do enviado em dia de semana e de 0,32 (Balcony) a 0,38 (King Spa 7) no Natal [PEN `villa-booking-razao-0436-20261123`; BK1]. As tabelas da Villa supõem a promoção fora (decisão 4).

**Posição no mercado**
- Decido e posiciono só pela régua comparável: suíte com banheira e café, com prova [RÉG; ORD `dono-2026-11-18-suites-comparaveis`; REG 5 e 6a].
- A busca da Booking com filtro de hidro (BK1 e BK2) traz o quarto mais barato de cada hotel, não a suíte. Os números dela vêm marcados como "apoio, fora da régua".

**Estimativas** levam "(est.)".
- A receita nova é: suítes-noite a vender × enviado-alvo × 0,49 no Recanto ou 0,46 na Villa. Esse fator é o que chega da Booking por real enviado [QJ `fator_canal.booking`].
- A conta usa o preço de abertura e não desconta cancelamentos.
- Onde esta revisão trocou a tabela, refiz a conta com o preço novo e a mesma ocupação.
- Receita que depende de decisão nova fica fora do alvo até o seu sim.

**Fontes**
- [PAC] `pricelabs/estrategia-dez-jan/dados.json`, gerado em 27/11 às 13:15Z. Campos: `vendidas_reservas`, `vendidas_lote_suspeito`, `receita_noite` e `enviado`. As contas por segmento são minhas, feitas sobre esses campos.
- [MET] `dados/metas_faturamento.json`: `por_pousada` (hoje, realista, minimo_dono), `metodo` e `insumos.mercado_ano_passado`.
- [RÉG] `pricelabs/pesquisa-booking/diaria-2026-11-19/ranking-suites-comparaveis-dez-fev.md` e `ranking-suites-comparaveis.md` (ranking de 19/11), e `dados/regua_dono.json` (campos `hidro`, `pares_hidro` e `fator_publico`). Só suítes com banheira e café, preço público por noite.
- [BK1] Busca na Booking em 27/11: 7 datas de dezembro, filtro de hidro, 2 adultos. Apoio, fora da régua.
- [BK2] Busca na Booking em 27/11: Réveillon e 8 datas de janeiro. Apoio, fora da régua.
- Scripts da skill: [PLN] `scripts/plano_dono.py` (funções `desce` e `piso_semana_ultima`) e `scripts/pace.py` (`piso_semana_mercado`).
- Arquivos da skill: [REG] `regras.md`, [EST] `estrategia.md`, [ORD] `ordens_dono.json`, [PEN] `pendencias.json`, [APR] `aprendizados.md`, [SAZ] `sazonalidade.json`, [QJ] `quartos.json`, [P12] `plano-12-meses.md`.
- Arquivos de `pricelabs/`: [REV] `reveillon-2026-meta-250k.md`, [NOV] `desempenho/2026-11_ate_2026-11-23.md`, [EDZ] `estrategia-dezembro-2026.md`, [LOG] `registro-de-mudancas.md`.

---

## 1. Resumo

1. **Onde estamos.** Dezembro tem R$ 171.046 vendidos: 253 de 961 suítes-noite (26%). Janeiro tem R$ 57.559: 80 de 961 (8%). O Recanto é o buraco, com 19% em dezembro e 7% em janeiro [PAC; MET].
2. **Quanto falta.** Para a meta realista faltam R$ 351.483 em dezembro e R$ 340.161 em janeiro. Para o seu mínimo de R$ 650 mil por mês faltam R$ 478.954 e R$ 592.441 [MET].
3. **O que isso pede.** Com os preços desta estratégia, a meta pede 64% de ocupação em dezembro e 51% em janeiro. O mínimo pede 78% e 83% (est.). O mercado fez 43-50% em dezembro e 36-45% em janeiro no ano passado [MET].
4. **A aposta.**
   - Destravar as festas que hoje não vendem por regra: Natal só com chegada em 23/12 e 29/12 com no máximo 2 noites.
   - Manter o Recanto no plano de 09/11, pela régua comparável: Moinhos no p40 e Panorâmicas no p50.
   - Rever o preço de 01-03/01 pela régua comparável. Nela, o Recanto fica entre o p50 e o p65, não no p92 (decisão 3).
   - Tirar a promoção que desarruma a vitrine da Villa (item seu, decisão 4).
   - Nos dias de semana, segurar o preço e vender no direto. De D7 a D0, o piso é a mediana comparável.
   - Se você quiser mais volume no Natal do Recanto, o piso de feriado está na decisão 9.
5. **O alvo.** 51% de ocupação em dezembro (cerca de R$ 401 mil) e 46% em janeiro (cerca de R$ 357 mil) (est.). O provável é R$ 352 mil e R$ 307 mil (est.).
   - Janeiro fica acima da base do arquivo no total. Na Villa, fica R$ 2.076 abaixo.
   - Dezembro fica R$ 15.064 abaixo da base no total. Na Villa, fica R$ 1.605 acima.
   - O motivo em dezembro: sem a decisão 9, o alvo não conta venda nova do Recanto no Natal, e a base conta [MET `metodo`]. Com a 9, dezembro fica R$ 24.180 acima da base.
6. **O que destrava, até 01/12:**
   - o token do Beds24, nos segredos do ambiente (`BEDS24_TOKEN` ou `BEDS24_REFRESH_TOKEN`), nunca no chat [REG §1];
   - a confirmação dos lotes suspeitos, com cerca de R$ 36,2 mil em jogo;
   - as respostas de sim ou não da seção 7 (decisões 1, 2 e 4 a 9; a 3 vem depois da medição).

---

## 2. Diagnóstico

### 2.1 Vendido, livre e lacuna [PAC; MET]

| Mês | Pousada | Vendidas (de lote) | Livres | Receita vendida | Meta realista | Falta para a meta | Mínimo do dono | Falta para o mínimo |
|---|---|---|---|---|---|---|---|---|
| Dez | Recanto | 94 de 496 (8) | 402 | R$ 57.743 | R$ 265.465 | R$ 207.722 | R$ 350.000 | R$ 292.257 |
| Dez | Villa | 159 de 465 (35) | 306 | R$ 113.303 | R$ 257.064 | R$ 143.761 | R$ 300.000 | R$ 186.697 |
| Jan | Recanto | 37 de 496 (3) | 459 | R$ 26.054 | R$ 205.581 | R$ 179.527 | R$ 350.000 | R$ 323.946 |
| Jan | Villa | 43 de 465 (3) | 422 | R$ 31.505 | R$ 192.139 | R$ 160.634 | R$ 300.000 | R$ 268.495 |

- "Vendidas" é `vendidas_reservas`, limitado à lotação do quarto. O lote já está dentro desse número, pelo mesmo critério em toda a estratégia.
- A receita é a `receita_noite` do PriceLabs, antes da comissão. Não é o valor pago do Beds24.
- **Ritmo:** desde 14/10, dezembro vendeu R$ 2.556 por dia e janeiro R$ 715 por dia. A meta pede 4,0 vezes isso em dezembro e 7,3 vezes em janeiro [MET `insumos.livro`].
  - A conta compara o livro de 14/10 (Beds24, `insumos.livro`) com o PAC de hoje (PriceLabs). As fontes são diferentes, e o ritmo é aproximado.

### 2.2 Para onde esta estratégia leva (est.)

| Mês | Pousada | Provável | Ocupação | Alvo | Ocupação | Base do arquivo | Meta realista |
|---|---|---|---|---|---|---|---|
| Dez | Recanto | R$ 166.156 | 39% | R$ 194.141 | 45% | R$ 210.811 | R$ 265.465 |
| Dez | Villa | R$ 185.931 | 52% | R$ 207.256 | 58% | R$ 205.651 | R$ 257.064 |
| **Dez** | **Total** | **R$ 352.087** | **45%** | **R$ 401.398** | **51%** | **R$ 416.462** | **R$ 522.529** |
| Jan | Recanto | R$ 176.584 | 43% | R$ 205.046 | 48% | R$ 163.255 | R$ 205.581 |
| Jan | Villa | R$ 130.301 | 39% | R$ 151.635 | 44% | R$ 153.711 | R$ 192.139 |
| **Jan** | **Total** | **R$ 306.884** | **41%** | **R$ 356.681** | **46%** | **R$ 316.966** | **R$ 397.720** |

- **O que o alvo supõe:**
  - "sim" nas decisões 1 a 4 (a 3 só depois da medição pela régua comparável; a 4 é item seu);
  - no Natal da Villa, o piso de R$ 800, se a skill o registrar com a prova (3.4).
- **Fora da conta, como folga:** as decisões 6, 7 e 9 e a venda nova do Recanto no Natal pelo preço do plano. A decisão 9 vale até R$ 39 mil em dezembro (est.).
- **O que mudou na conta nesta revisão:**
  - fins de semana pelo preço do plano, com a mesma ocupação (a receita sobe);
  - Natal do Recanto fora até o sim na decisão 9 (a receita desce);
  - 01-02/01 a 80% e 03/01 a 35% (a receita sobe).
- **Do alvo à meta** faltam R$ 121.131 em dezembro e R$ 41.039 em janeiro (est.). Com a decisão 9, dezembro cai para R$ 81.887.
- **Cancelamentos:** com 20% das reservas novas canceladas e sem revenda [NOV], o alvo cai cerca de R$ 46 mil em dezembro e R$ 60 mil em janeiro (est.).
- **Piso de D7 nos dias de semana:** em janeiro e na Villa em dezembro, a tabela fica abaixo do piso de D7 a D0, e o preço sobe até ele (3.1 e 3.8). A conta usa o preço de abertura e não mede o efeito disso na venda.
- **A escada só melhora a conta:** o preço sobe com a venda, e a conta usa o preço de abertura.
- **Referências próprias [P12 §1b]:**
  - Recanto em dezembro de 2024: R$ 253 mil, a 64% e R$ 794 de diária.
  - Recanto em janeiro: R$ 257 mil (73%) em 2024 e R$ 215 mil (59%) em 2026.
  - O alvo do Recanto fica abaixo desses três. Há espaço para mais.

### 2.3 Os buracos

1. **Dias de semana de dezembro.** O Recanto tem 224 suítes-noite livres (12% vendido) e a Villa 160 [PAC].
2. **Natal (23-26/12).**
   - Recanto: 4 de 64. Só a Panorâmica I vendeu.
   - Villa: 11 de 60, 3 delas de lote. As noites de 25 e 26/12 venderam 3 de 62 nas duas pousadas [PAC].
   - A busca por 24→26 não acha nenhuma das duas pousadas [BK, 27/11].
   - Pela ordem de 03/11, o Natal só tem chegada em 23/12 [ORD `dono-2026-11-03-festas-pacotes`].
3. **Entre festas (27-29/12).**
   - Recanto: 1 de 48. Villa: 8 de 45 [PAC].
   - A noite de 29/12 aceita no máximo 2 noites e barra o 29→01 [QJ `feriados`, "Réveillon (véspera 29/12)"].
   - Os melhores concorrentes só vendem o Réveillon em estadia longa, 29/12→02/01 [BK2].
4. **01-02/01.**
   - Recanto: 8 de 32 (25%), a R$ 2.552 por noite [PAC; BK2]. Na régua comparável de 30/12→03/01, isso fica entre o p50 (R$ 2.123) e o p65 (R$ 2.829) [RÉG].
   - Villa: 5 de 30 (17%), a R$ 1.498 por noite, com a promoção [PAC; BK2].
5. **Janeiro inteiro.** São 459 livres no Recanto e 422 na Villa. Pela curva, janeiro está "no ritmo", porque ela só pede 3% a 14% de 35 a 65 dias da data. Janeiro vende com 10 a 14 dias de antecedência [SAZ].
6. **Balcony.** Vendeu 22 de 186 em dezembro e 1 de 186 em janeiro. São 349 livres nos dois meses [PAC].
7. **A Villa não aparece no filtro de hidro.** Isso vale para as 7 datas de dezembro. A lista de comodidades da propriedade não tem "Jacuzzi" [BK1, achado 1].
8. **O Réveillon de R$ 250 mil não fecha.**
   - Faltam R$ 201.594 em 77 diárias livres: R$ 2.618 por diária, mesmo vendendo 100% [REV].
   - A meta é sua. A proposta de medir pela meta realista do mês está na decisão 8. Até o seu sim, ficam a meta, as linhas de alarme e os marcos da ordem.

### 2.4 Se o lote for falso

- A Villa de dezembro cai de R$ 113.303 para cerca de R$ 85.861. A ocupação cai de 34% para 27% (est.: receita da noite × lote ÷ reservas) [PAC].
- O lote soma 49 suítes-noite em dezembro e janeiro (Recanto 11, Villa 38), cerca de R$ 36,2 mil (est.) [PAC].
- **A força da Villa em 13-16/12 e no Réveillon depende do lote.** Vem da King Spa 7, e boa parte é lote (em 14/12, 4 de 9) [PAC].

---

## 3. Estratégia por segmento de noite

**O que vale em todos os segmentos**
- O preço muda só por regra por data no PriceLabs, em `percent_base` [REG §4].
- A skill não mexe em desconto, promoção nem Genius [REG §1].
- **Plano do dono:** no fim de semana e nas festas do Recanto, o preço é o do plano de 09/11, pela régua comparável [ORD `dono-2026-11-09-plano-nov-fev`, decisões 3 e 6; ORD `dono-2026-11-18-suites-comparaveis` e `dono-2026-11-19-precificacao-dez-fev`; REG 4f].
  - A descida é uma só: −10% a D7, na suíte com menos de 60% vendida [PLN `desce`].
  - Mudar isso pede o seu sim.
- O motor de pace usa a régua comparável, com a reposição de 01/12 [ORD `dono-2026-11-19-precificacao-dez-fev`].
- **Coerência:** Moinhos ≤ Panorâmica II ≤ Panorâmica I < Afrodite. A Balcony segue a Regra G [REG].
  - A Afrodite fica pelo menos 20% acima da mais cara de domingo a quinta fora de feriado, inclusive em 27-28/12.
  - Na sexta, no sábado e no feriado, fica 15% acima da Panorâmica I [REG "Suíte Afrodite"; `ordem_recanto.premio_afrodite`].

**Pisos**
- **Dia de semana, até D8:** R$ 1.220 enviados no Recanto e R$ 943 na Villa (R$ 500 no Genius 3).
- **Dia de semana, de D7 a D0:** a mediana comparável da noite ÷ o fator da régua. Vale para a Moinhos, as Panorâmicas (× 1,05 na Panorâmica I) e as King Spa [REG 4f, decisão 5; PLN `piso_semana_ultima`; `pace.piso_semana_mercado`]. Pela `regua_dono.json` (`hidro`, p50):
  - dezembro: R$ 795-871 públicos, cerca de R$ 1.256-1.377 enviados no Recanto e R$ 1.316-1.441 na Villa;
  - 21-22/12: R$ 1.036, cerca de R$ 1.637 no Recanto;
  - janeiro: R$ 999-1.049, cerca de R$ 1.579-1.658 no Recanto e R$ 1.467-1.680 na Villa.
  - A descida para nesse piso, nunca no p40. Não é a mediana BK1/BK2 de R$ 446-538.
  - Ficar abaixo dele só com o seu sim para mudar a decisão 5.
- **Sexta e sábado:** R$ 1.000 enviados. O piso de R$ 850 no Genius 3 está suspenso até 28/02 [REG 4f].
- **Feriado:** R$ 800 líquidos, que dão R$ 1.905 enviados no Recanto, R$ 2.052 nas King Spa e R$ 1.510 na Balcony [REG "Feriados"].
  - No Natal (23-26/12) e em 29/12, o piso vigente é R$ 1.000 líquidos [QJ `feriados`, `piso_real`]: R$ 2.564 nas King Spa e R$ 1.887 na Balcony.
  - O Recanto vai a R$ 800 nessas noites só pelo plano de 09/11 [REG 4f].
  - R$ 800 líquidos é limite de segurança. Só você muda [REG §1 e §2].

**Curva de pace** [EST §5]
- Semana: 15% a D14, 28% a D7, 42% a D3.
- Fim de semana: 32%, 50% e 68%.
- Feriado: 50%, 68% e 82%.
- Os gatilhos abaixo usam essa curva, contada em suítes.

### 3.1 Dias de semana de dezembro (01-17/12, domingo a quinta)

**Hoje:** Recanto com 26 de 208 (12%; 4 de lote). Villa com 68 de 195 (35%; 21 de lote, 24% sem ele) [PAC].

**Objetivo:** Recanto 30% e Villa 45%. O provável é 25% e 40%. No ano passado o mercado fez 18-28% [SAZ]. Receita a mais no alvo: R$ 23 mil no Recanto e R$ 9 mil na Villa (est.).

**Preço-alvo até D8:** Recanto a 0,585; Villa sem promoção.

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos | R$ 1.262 | R$ 738 | R$ 517 |
| Panorâmica II | R$ 1.270 | R$ 743 | R$ 521 |
| Panorâmica I | R$ 1.325 | R$ 775 | R$ 543 |
| Afrodite | R$ 1.590 | R$ 930 | R$ 652 |
| King Spa 7 | R$ 1.130 | R$ 720 | R$ 599 |
| King Spa 2 | R$ 1.099 | R$ 700 | R$ 582 |
| Balcony | R$ 989 | R$ 630 | R$ 524 |

- A Afrodite fica 20% acima da Panorâmica I: R$ 1.325 × 1,20 = R$ 1.590 [REG, Afrodite, `premio_minimo_semana`].
- **De D7 a D0, o piso sobe à mediana comparável** (seção 3, Pisos).
  - Recanto: cerca de R$ 1.256-1.377 (Panorâmica I, cerca de R$ 1.319-1.446). A Moinhos da tabela já está nele nas noites de mediana R$ 795 e sobe até R$ 1.377 nas de R$ 871.
  - Villa: cerca de R$ 1.316-1.441. As King Spa sobem da tabela até ele a D7.
- A conta usa o preço da tabela. A D7, o preço da Villa sobe ao piso: cada diária dessa semana rende mais, mas a venda pode cair. A estimativa não mede isso.

**Hoje no PAC:** a Moinhos vai de R$ 1.267 a R$ 1.630 (mediana R$ 1.466). A King Spa 7 vai de R$ 1.110 a R$ 1.865 (mediana R$ 1.421).
- A reposição de 01/12 já leva a Moinhos a R$ 1.270 [PAC, `regra` de 01/12]. Ela mira o p50 de R$ 800 públicos pelo fator da régua (0,6327). A 0,585, a Booking mostra cerca de R$ 743.

**Posição**
- **Recanto:** a R$ 738-775 públicos, abaixo da mediana da régua comparável (R$ 795-871), cerca de p37 [RÉG; `regua_dono.json`].
- **Villa:** a R$ 700-720 públicos, um degrau abaixo do Recanto, perto do p40 da régua [EST §5; RÉG]. A nota dela é 8,3, contra 9,2 a 9,7 dos comparáveis [BK1].
- **Apoio, fora da régua [BK1]:** a mediana do mercado com hidro é R$ 446-480 por noite. DOM, Casa Redonda e Geada cobram R$ 347-360.

**Estadia:** 1 noite com chegada no domingo, na terça e na quinta; 2 com chegada na segunda e na quarta. A noite órfã abre 1 [REG Regra A].

**Táticas**
- **O preço não é a alavanca principal aqui.** As janelas de 1 diária em dia de semana tiveram 0 venda de 16 a 21/11. Preço de piso só trouxe volume na véspera de feriado [APR, efeito das mudanças].
- **Vender as janelas** sem mexer no preço [ORD `dono-2026-11-16-vender-janelas`].
- **Última hora:** de D3 a D0, o motor dá passos de até 20%. Nas suítes com banheira, o piso de D7 segura; a Regra I fica para a Balcony [REG Regra I].
- **Direto:** pacote de 2 ou 3 noites de domingo a quinta, pela tabela do direto, com benefício (decisão 6).
- **Balcony:** Regra G, 85,5% a 90% da suíte com banheira livre mais barata, nunca abaixo de R$ 630 [REG 4f].
- **Villa de 05 a 16/12:** as noites com lote ficam fora do motor até a confirmação [PEN `booking-lote-16-reservas-20261117`].

**Gatilho de ajuste**
- **Venda no ritmo:** a D7, 5 ou mais de 16 vendidas no Recanto, ou 4 de 15 na Villa. A noite segura, e a escada sobe com a ocupação.
- **Venda abaixo disso:** de D7 a D0, o preço só desce até o piso de D7, a mediana comparável. Nunca ao p40 e nunca abaixo do piso da noite.

### 3.2 Pré-Natal (20-22/12, domingo a terça)

**Hoje:** Recanto com 6 de 48 (12%). Villa com 12 de 45 (27%) [PAC].

**Objetivo:** Recanto 55% e Villa 60%. O provável é 45% e 55%.
- O mercado fez 45-61% em 18-23/12 [SAZ].
- Há a Corrida Iluminada em 19/12, e as férias da rede estadual começam em 19/12 [SAZ].
- Receita a mais no alvo: R$ 17 mil no Recanto e R$ 10 mil na Villa (est.).

**Preço-alvo:** Recanto a 0,585.

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos | R$ 1.644 | R$ 962 | R$ 674 |
| Panorâmica II | R$ 1.667 | R$ 975 | R$ 683 |
| Panorâmica I | R$ 1.730 | R$ 1.012 | R$ 709 |
| Afrodite | R$ 2.079 | R$ 1.216 | R$ 853 |
| King Spa 7 | R$ 1.500 | R$ 956 | R$ 795 |
| King Spa 2 | R$ 1.500 | R$ 956 | R$ 795 |
| Balcony | R$ 1.350 | R$ 860 | R$ 716 |

- Os R$ 1.500 das King Spa são proposta minha, acima do piso da ordem de faixa (abaixo).

**Posição**
- **Recanto:** a R$ 962-1.012 públicos, logo abaixo da mediana da régua de 21→22/12 (R$ 1.036; p75 de R$ 1.395) [RÉG].
- **Villa:** vale a ordem de faixa de 04/11 (`dono-2026-11-04-faixa-villa-20261220`, `-20261221` e `-20261222`) [ORD].
  - Faixa F2 (p50), piso de R$ 1.335 enviados nas King Spa e R$ 1.202 na Balcony, escada a cada venda.
  - A ordem de 01/11 (`dono-2026-11-01-villa-pre-natal-mediana`) não vale mais. Ela cobre 21-23/12, e as três noites estão em `noites_fora` na King Spa 7 e na King Spa 2 desde 04/11 [ORD, campo `noites_fora`].

**Estadia:** Regra A.

**Gatilho de ajuste**
- **D14 (06-08/12):** 3 de 16 no Recanto e 2 de 15 na Villa.
- **D7 (13-15/12):** 5 e 4.
- **Acima:** a escada sobe.
- **Abaixo:** a descida para no piso de D7, a mediana comparável: R$ 871 em 20/12 e R$ 1.036 em 21-22/12, em público.
  - No Recanto, isso dá cerca de R$ 1.377 e R$ 1.637 enviados. Na Villa, cerca de R$ 1.441 e R$ 1.449.
  - Em 21-22/12, a Moinhos da tabela já está nesse piso. Nunca ao p40.

### 3.3 Fins de semana de dezembro (04-05, 11-12 e 18-19/12)

**Hoje:** Recanto com 44 de 96 (46%). Villa com 39 de 90 (43%; 5 de lote) [PAC].
- 04-05/12: o Recanto tem 13 de 16 nas duas noites.
- 11-12/12: o Recanto tem 4 de 16 a D14, e a curva pede 5.
- 18-19/12: o Recanto tem 5 de 16. A Villa está perto de 3% sem o lote [PAC].

**Objetivo:** Recanto 80% e Villa 75%. O provável é 70%.
- O mercado fez 50-55% de 01 a 17/12 e 45-61% de 18 a 23/12 [SAZ].
- Receita a mais no alvo: R$ 34 mil no Recanto e R$ 28 mil na Villa (est.).

**Preço: a abertura do plano de 09/11, pela régua comparável** [ORD `dono-2026-11-09-plano-nov-fev`, decisão 3; ORD 18/11 e 19/11; REG 4f; `regua_dono.json`].
- Moinhos no p40 comparável. Panorâmicas e King Spa no p50 dos pares × 1,05. Afrodite 15% acima da Panorâmica I. Nunca acima do pedido de 09/11.
- Régua, em público: em 11-12/12, Moinhos R$ 1.310 e Panorâmica I R$ 1.504. Em 18-19/12, Moinhos R$ 996 e as demais R$ 1.200.

| Suíte | 11-12/12: enviado | Público | Genius 3 | 18-19/12: enviado | Público | Genius 3 |
|---|---|---|---|---|---|---|
| Moinhos | R$ 2.128 | R$ 1.245 | R$ 904 | R$ 1.618 | R$ 946 | R$ 688 |
| Panorâmica II | R$ 2.433 | R$ 1.423 | R$ 1.034 | R$ 1.949 | R$ 1.140 | R$ 828 |
| Panorâmica I | R$ 2.442 | R$ 1.429 | R$ 1.038 | R$ 1.949 | R$ 1.140 | R$ 828 |
| Afrodite | R$ 2.808 | R$ 1.643 | R$ 1.193 | R$ 2.242 | R$ 1.311 | R$ 953 |
| King Spa 7 | R$ 2.279 | R$ 1.452 | R$ 1.310 | R$ 2.148 | R$ 1.368 | R$ 1.235 |
| King Spa 2 | R$ 2.324 | R$ 1.480 | R$ 1.336 | R$ 2.148 | R$ 1.368 | R$ 1.235 |
| Balcony (Regra G) | R$ 2.051 | R$ 1.307 | R$ 1.179 | R$ 1.933 | R$ 1.231 | R$ 1.112 |

- As King Spa de 11-12/12 são as de 11/12. Em 12/12 elas estão esgotadas.
- **A tabela é o preço de hoje:** em 11-12/12, Moinhos a R$ 2.135 e Panorâmica I a R$ 2.442 [PAC]. Em 18-19/12, a Moinhos fica onde está, R$ 1.619 (cerca de R$ 944 públicos [BK1]).
- **04-05/12:** a abertura do plano é R$ 2.069 na Moinhos. A escada já a levou a R$ 2.334 [PAC; `regua_dono.json`].
- **Não proponho abrir abaixo do plano.** Isso mudaria a decisão 3 do plano de 09/11 e a ordem de 19/11, e só com o seu sim.

**Posição**
- **Régua de 11→13/12:** p40 de R$ 1.310, mediana de R$ 1.432 e p75 de R$ 1.643 [RÉG; `regua_dono.json`].
- **Régua de 18→20/12:** p25 de R$ 872, mediana de R$ 1.306 e p75 de R$ 1.546 [RÉG].
- A 0,585, a Booking mostra cerca de 5% menos que a régua: Moinhos a R$ 1.245, contra R$ 1.310.
- **Apoio, fora da régua [BK1]:** mediana do mercado com hidro de R$ 760-791 por noite. Casa Redonda R$ 540, Chateau Colinas R$ 588-636, DOM R$ 711-737.

**Estadia:** chegada na sexta com 2 noites. O sábado fica fechado para entrada e saída. Sábado órfão e sexta órfã abrem 1 noite [REG Regra H e Regra A].

**Táticas**
- **04-05/12:** quase lotado. Fica a proteção da noite esgotada e a escada. Não desce.
- **11-12/12:** a Villa só tem Balcony à venda. King Spa 7 e King Spa 2 estão esgotadas em 12/12; a King Spa 7 tem 9 reservas para 7 unidades [PAC; BK1]. A King Spa 2 de 11/12 vende como sexta órfã.
- **18-19/12:** leitura diária da Villa.
  - A Balcony, sem banheira, sai a R$ 1.149 por noite e passa a Moinhos, com banheira, a R$ 944 [BK1, apoio].
  - A Regra G liga a Balcony à King Spa da Villa, não à Moinhos. No plano, ela segue acima da Moinhos (risco 5).
- **Balcony:** com 8 ou 9 das 9 suítes com banheira vendidas, vale a regra dos 80%: preço de mercado do quarto sem banheira. Exemplo: 12/12 a R$ 1.041 [REG 4f; PAC].

**Gatilho de ajuste**
- **Venda no ritmo:** a D7 (sexta anterior), 8 de 16 no Recanto e 8 de 15 na Villa. A noite segura.
- **Escada:** com metade da suíte vendida, sobe +7% por unidade [REG 4f].
- **Venda fraca:** suíte com menos de 60% vendida a D7 desce 10%, uma vez, nunca abaixo de R$ 1.000 enviados [REG 4f; PLN `desce`].
- O D7 de 11-12/12 cai em 04/12. O de 18-19/12, em 11/12.

### 3.4 Natal (23-26/12)

**Hoje:** Recanto com 4 de 64 (6%). Villa com 11 de 60 (18%; 3 de lote). A curva pede 30-35% a 26-28 dias [PAC].

**Objetivo:** Recanto 70% e Villa 65%, com a decisão 1. No Recanto, também com a decisão 9. O provável é 55%.
- Mercado em 2025: 51-54% pelo STR e 70% pela Prefeitura [SAZ].
- Recanto em 2024: 69% a R$ 712 [SAZ; P12 §1b].
- Receita a mais no alvo: R$ 25 mil na Villa (est.).
- No Recanto, sem a decisão 9, o alvo não conta venda nova. O que vender no preço do plano é folga. Com a 9, até R$ 39 mil (est.).

**Preço**
- **Recanto:** o plano de 09/11, pela régua comparável. Moinhos no p40 e Panorâmicas no p50; Afrodite 15% acima da Panorâmica I [ORD `dono-2026-11-09-plano-nov-fev`, decisão 6; ORD 18/11 e 19/11; `regua_dono.json`, `hidro`].
  - Régua, em público: 23-24/12, p40 de R$ 1.721 e p50 de R$ 1.969; 25-26/12, p40 de R$ 1.393 e p50 de R$ 1.605.
  - O enviado sai pelo fator da régua, 0,6156.
- **Villa:** o piso vigente, sem a promoção (decisão 4). A Balcony vai pela Regra G, 10% abaixo da King Spa.

| Suíte | 23-24/12: enviado | Público | Genius 3 | 25-26/12: enviado | Público | Genius 3 |
|---|---|---|---|---|---|---|
| Moinhos | R$ 2.796 | R$ 1.636 | R$ 1.146 | R$ 2.263 | R$ 1.324 | R$ 962 |
| Panorâmica II | R$ 3.199 | R$ 1.871 | R$ 1.312 | R$ 2.607 | R$ 1.525 | R$ 1.108 |
| Panorâmica I | R$ 3.199 | R$ 1.871 | R$ 1.312 | R$ 2.607 | R$ 1.525 | R$ 1.108 |
| Afrodite | R$ 3.678 | R$ 2.152 | R$ 1.508 | R$ 2.998 | R$ 1.754 | R$ 1.274 |
| King Spa 7 | R$ 2.564 (piso) | R$ 1.633 | R$ 1.359 | R$ 2.564 (piso) | R$ 1.633 | R$ 1.474 |
| King Spa 2 | R$ 2.564 (piso) | R$ 1.633 | R$ 1.359 | R$ 2.564 (piso) | R$ 1.633 | R$ 1.474 |
| Balcony | R$ 2.308 | R$ 1.470 | R$ 1.223 | R$ 2.308 | R$ 1.470 | R$ 1.327 |

- O Genius 3 usa o fator de dia de semana em 23-24/12 e o de fim de semana em 25-26/12.

**Hoje no PAC:**
- Moinhos: R$ 2.803 em 23-24/12 e R$ 2.264 em 25-26/12.
- Panorâmica I: R$ 3.212 e R$ 2.620.
- Afrodite: R$ 3.700 e R$ 3.020.
- O Recanto de hoje já é o preço do plano.
- King Spa 7: R$ 4.070-4.292. Em 27/11 foi gravada a R$ 3.730-3.863 [LOG 27/11].

**Posição**
- **Régua comparável:** 23→25/12 com p25 de R$ 1.358, mediana de R$ 1.969 e p75 de R$ 2.300; 25→27/12 com p25 de R$ 1.170, mediana de R$ 1.605 e p75 de R$ 2.228 [RÉG].
- O plano põe a Moinhos no p40 e as Panorâmicas na mediana dessa régua.
- **Apoio, fora da régua [BK1]:** em 23→25/12, mediana do mercado com hidro de R$ 1.100 por noite, p75 de R$ 1.550 e nota 9 ou mais em R$ 1.459.
  - Esgotados: DOM, Chateau Colinas, Lavandas e Secreto.
  - Ainda à venda, por noite: Geada R$ 677, Casa Redonda R$ 708, Della Nonna R$ 920, Villa Casato R$ 1.400, vila grega R$ 2.304.
  - Nós hoje: Recanto a R$ 1.635. Villa a R$ 1.209, pela Balcony.

**Por que o Recanto fica no plano**
- É o que a decisão 6 do plano de 09/11 manda com a régua comparável, que vale desde 19/11 [ORD `dono-2026-11-18-suites-comparaveis` e `dono-2026-11-19-precificacao-dez-fev`].
- A mediana de R$ 1.100 e o p75 de R$ 1.550 vêm da BK1, com o quarto mais barato de cada hotel. A ordem de 18/11 manda não usar esses números.
- A régua do plano está atrasada desde 24/11 [PEN `plano-dono-regua-refazer`]. A skill a refaz pelo ranking comparável (`scripts/ranking_comparaveis.py`), não pela BK1, e avisa no relatório antes de gravar.
- O plano desce só uma vez: −10% em 16/12 (D7), na suíte com menos de 60% vendida [PLN `desce`].
- Ir ao piso de feriado seria mudança no plano. Está na decisão 9, com o efeito em R$, e só gravo com o seu sim.

**Villa**
- **Com a promoção:** R$ 3.730-3.863 enviados × 0,383 dão cerca de R$ 1.430-1.480 públicos na King Spa 7 [LOG 27/11; BK1].
  - Isso fica entre o público da tabela com o piso de R$ 800 (R$ 1.307) e o do piso vigente (R$ 1.633).
  - A descida de 5% por dia deve parar aí. O motor já usa o fator medido por tipo de noite [REG 6a].
- **Sem a promoção:** a King Spa vai ao piso vigente, R$ 2.564 (R$ 1.000 líquidos), e a Balcony fica pela Regra G, nunca abaixo de R$ 1.887 [QJ `feriados`, "Natal" e "Natal (25 e 26/12, depois do pacote)", `piso_real` 1000].
  - O plano de 09/11 dispensa esse piso só no Recanto. As festas da Villa ficam como estão.
  - A skill pode baixar o piso a R$ 800 pelos dados (King Spa a R$ 2.052 e Balcony a R$ 1.847, pela Regra G).
  - Só depois de registrar a mudança em `quartos.json`, com o motivo e a prova: a régua comparável do Natal (p25 de R$ 1.358 e p50 de R$ 1.969 públicos) e o ritmo de vendas. A BK1 não serve de prova [ORD 18/11].
  - A receita do alvo usa esses valores (est.). Sem a mudança, cada diária rende mais, mas a venda pode cair.

**Estadia**
- **Hoje:** só 23→25. O sábado 26/12 está aberto, com mínimo de 2 noites [ORD `festas-pacotes`; ORD `festas-sabado-aberto`].
- **Com a decisão 1:** 23→25, 23→26, 24→26 e 25→27, com mínimo de 2. A decisão lista as restrições que mudam e as estadias que passam a caber.

**Táticas**
- **Pré-pagamento sem desconto.** Em novembro, 20% das reservas foram canceladas [NOV].
- **Tarifa não reembolsável:** é da Booking, e só você cria [REG §1; PEN `tarifa-nao-reembolsavel`].
  - Ela fica 7% a 10% abaixo da flexível.
  - Na noite no piso, leva o líquido abaixo do piso. Ali, só entra com o piso subido pelo desconto (ex.: R$ 1.905 ÷ 0,90 ≈ R$ 2.117).
- **Direto com benefício** (ceia, saída tardia) e nunca abaixo da tabela. A King Spa 7 do Natal já foi vendida a 6% e a 17% do esperado [PEN `vendas-abaixo-recepcao-expedia-20261117`].
- **A Moinhos é a última a vender** [APR, feriado de 20-21/11]. A única descida do plano é a de D7 (16/12). Não há outra antes.
- **Balcony:** Regra G, nunca abaixo de R$ 1.887 até a mudança do piso.

**Gatilho de ajuste**
- **D14 (09/12):** 8 de 16 no Recanto e 8 de 15 na Villa, por noite.
- **D7 (16/12):** 11 e 10.
- **Acima:** +7% por unidade depois de metade da suíte vendida, até o p75 comparável: R$ 2.300 em 23-24/12 e R$ 2.228 em 25-26/12, em público [`regua_dono.json`; REG 4f].
- **Abaixo:** −10% uma vez em 16/12, só na suíte com menos de 60% vendida [PLN `desce`].
  - Depois disso, a alavanca é a estadia (decisão 1), o direto (decisão 6), o Booster (decisão 7) e, com o seu sim, o piso (decisão 9).

### 3.5 Entre festas (27-29/12)

**Hoje:** Recanto com 1 de 48. Villa com 8 de 45 [PAC].

**Objetivo:** 45% nas duas. O provável é 37%. O mercado fez cerca de 53% em 27-28/12 [SAZ]. Receita a mais no alvo: R$ 18 mil no Recanto e R$ 11 mil na Villa (est.).

**Preço-alvo de 27 e 28/12:** Recanto a 0,585.

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos | R$ 1.709 | R$ 1.000 | R$ 701 |
| Panorâmica II | R$ 1.795 | R$ 1.050 | R$ 736 |
| Panorâmica I | R$ 1.906 | R$ 1.115 | R$ 781 |
| Afrodite | R$ 2.287 | R$ 1.338 | R$ 938 |
| King Spa 7 | R$ 2.077 | R$ 1.323 | R$ 1.101 |
| King Spa 2 | R$ 2.077 | R$ 1.323 | R$ 1.101 |
| Balcony | R$ 1.869 | R$ 1.191 | R$ 991 |

- 27/12 (domingo) e 28/12 (segunda) não são feriado. A Afrodite fica 20% acima da Panorâmica I: R$ 1.906 × 1,20 = R$ 2.287 [REG, Afrodite; `ordem_recanto.premio_afrodite`].
- **29/12 tem piso de feriado:** R$ 1.905 no Recanto (R$ 800 líquidos, plano de 09/11) e R$ 2.564 nas King Spa (R$ 1.000 líquidos) [QJ `feriados`; REG 4f; PAC].
- **Moinhos no alvo; Panorâmica I R$ 86 abaixo dele.** Hoje, a Moinhos está a R$ 1.713 e a Panorâmica I a R$ 1.820 em 27-28/12 [PAC].

**Posição**
- Régua de 27→29/12: p25 de R$ 869, mediana de R$ 1.115 e p75 de R$ 1.663 [RÉG].
- Moinhos perto do p40 e Panorâmica I na mediana [REG 4f].
- Villa pela ordem de 01/11: mediana com hidro e piso de R$ 2.077 [ORD `dono-2026-11-01-villa-27-28-mediana`].

**Estadia**
- **Hoje:** 27/12 (domingo) com 1 noite; 28/12 (segunda) com 2.
- **29/12 só vende 29→30 e 28→30** (no máximo 2 noites) [QJ `feriados`].
- **Com a decisão 2:** 29/12 vende também 29→01 e 29→03.

**Táticas**
- **27/12 é o domingo depois do Natal.** A noite seguinte a um feriado "não vende nem no p25" [APR].
  - Não gastar descida nessa noite.
  - A "mais uma noite" de 27/12 só existe com a decisão 1. Hoje o pacote é só 23→25, e estender dá 23→26, que é proibido [ORD `festas-pacotes`].
  - Com a decisão 1, a recepção oferece a noite a quem sai em 27/12, pelo mínimo que você der. Em 20/11 foi R$ 1.000 [ORD `dono-2026-11-20-extensao-domingo-1000`, vencida]. Está na decisão 6.
- **Gatilho das festas:** mais de 5 pontos atrás do mercado em 2 rotinas completas seguidas desce 1 degrau, até o piso de feriado [ORD `dono-2026-11-01-gatilhos-festas`].

**Gatilho de ajuste**
- **D7 (20-22/12):** 5 de 16 no Recanto e 4 de 15 na Villa.
- **Acima:** a escada sobe.
- **Abaixo:** no Recanto, vale o plano: −10% uma vez a D7, na suíte com menos de 60% vendida [PLN `desce`]. Na Villa, vale o gatilho das festas, nunca abaixo do piso de R$ 2.077 [ORD].

### 3.6 Réveillon (30-31/12)

**Hoje:** Recanto com 13 de 32 (41%). Villa com 21 de 30 (70%; 6 de lote, 50% sem ele) [PAC].
- No Recanto, quem vende é a Moinhos: 9 de 14 [PAC].
- As diárias já vendidas do bloco valem, em média, R$ 1.030 pela `receita_noite` do PriceLabs: R$ 48.406 em 47 diárias, 6 delas de lote [PAC].
- Em 16/11, eram R$ 1.165 pelo valor pago no Beds24: R$ 46.580,68 em 40 diárias [REV §3].
- As fontes são diferentes. Os dados não mostram uma queda de R$ 1.165 para R$ 1.030.

**Objetivo:** 95%. O provável é 90%. O mercado fez 85-96% [SAZ]. Receita a mais no alvo: R$ 44 mil no Recanto e R$ 10 mil na Villa (est.).

**Preço-alvo:** o Recanto segue a ordem de 16/11, sem mudança. A Villa aparece como ficaria sem a promoção (decisão 4).

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos | R$ 4.974 | R$ 2.910 | R$ 2.039 |
| Panorâmica II | R$ 4.977 | R$ 2.912 | R$ 2.041 |
| Panorâmica I | R$ 4.988 | R$ 2.918 | R$ 2.045 |
| Afrodite | R$ 6.700 | R$ 3.919 | R$ 2.747 |
| King Spa 7 | R$ 3.297 | R$ 2.100 | R$ 1.747 |
| King Spa 2 | R$ 3.218 | R$ 2.050 | R$ 1.706 |
| Balcony | R$ 2.896 | R$ 1.845 | R$ 1.535 |

**A Villa hoje:** R$ 4.988 / R$ 4.991 / R$ 4.483 enviados, com a promoção. O público medido em 30/12 foi R$ 1.978 na King Spa 2 e R$ 1.664 na Balcony [BK2].

**Posição**
- **Régua comparável de 30/12→03/01:** p50 de R$ 2.123, p65 de R$ 2.829 e p75 de R$ 3.174 [RÉG; `regua_dono.json`].
- **Nós:** o Recanto, a R$ 2.901 [BK2], fica entre o p65 e o p75.
- **Apoio, fora da régua [BK2]:**
  - em 30/12→01/01, mediana do mercado com hidro de R$ 1.674, p75 de R$ 2.356 e grupo boutique (nota 9 ou mais) em R$ 2.327;
  - Villa a R$ 1.664;
  - comparáveis que vendem 2 noites: Geada R$ 1.005, Carballo R$ 2.327, Vila Grega R$ 2.366, Murano R$ 2.618, Estoril R$ 3.220;
  - DOM, Casa Redonda, Chateau, Lavandas, Della Nonna, Secreto e Apple House só têm vaga em 29/12→02/01.

**Leitura**
- A hidro está escassa: a Booking completou a lista com 15 de 50 hotéis sem hidro [BK2].
- O Recanto, mesmo acima da mediana comparável, ainda vende a Moinhos. Segurar o preço.
- O Recanto não aparece na busca de 4 noites, 29/12→02/01 [BK2]. A saída no sábado 02/01 é fechada pela ordem [REG 4g]. Isso provavelmente explica, mas falta conferir.

**Estadia**
- Pacotes 30→01 e 30→03. O dia 31/12 não aceita entrada.
- Exceção 1: 29→31, só no quarto com 31/12 esgotado [ORD `festas-pacotes`; REG 4g].

**Táticas**
- O preço segue o script do Réveillon. Nenhuma mudança fora da ordem [REG 4g].
- A recepção vende as noites soltas.
- Pré-pagamento sem desconto. A tarifa não reembolsável é ação sua na extranet [REG §1] e fica 7% a 10% abaixo da flexível.

**Gatilho de ajuste (ordem de 16/11)**
- **Escada:** +3% por pacote novo.
- **Marco de 21/12:** com 10 dos 12 pacotes vendidos, +5% uma vez.
- **Descida de 5%** quando os pacotes novos ficam abaixo da linha do marco: 6 em 23/12, 7 em 25/12, 8 em 27/12 e 9 em 29/12.
- **Piso:** alvo × 0,95⁴. Dá R$ 4.051 na Moinhos e R$ 5.457 na Afrodite [REG 4g].
- **Alarme de 30/11:** R$ 59,6 mil e 46 diárias [REV §8].
  - Nas diárias, temos 47, acima do alarme. Só sem o lote (41) fica abaixo [PAC].
  - Na receita, temos cerca de R$ 48,4 mil pelo PriceLabs [PAC]. A comparação é aproximada: o alarme é do Beds24, e o PAC parece subcontar a Panorâmica I no bloco (3 diárias por R$ 850 em 31/12; 4 diárias por R$ 676 em 01-02/01, R$ 169 cada).
  - O marco só fecha com o Beds24, quando a credencial voltar.
  - Sem o Beds24, a leitura é incompleta, e a escada fica parada [REG L337]. A contagem de pacotes também não roda (seção 6).

### 3.7 Fim de semana do Ano-Novo (01-03/01)

**Hoje:** em 01-02/01, Recanto com 8 de 32 (25%) e Villa com 5 de 30 (17%). Em 03/01, 2 de 16 e 2 de 15 [PAC].

**Objetivo:** 80% em 01-02/01 e 35% em 03/01, cerca de 65% nas três noites. O provável é 58%. Receita a mais no alvo: cerca de R$ 32 mil no Recanto e R$ 24 mil na Villa (est.).

**Preço-alvo de 01-02/01:** com a decisão 3; Villa sem promoção.

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos | R$ 3.333 | R$ 1.950 | R$ 1.417 |
| Panorâmica II | R$ 3.419 | R$ 2.000 | R$ 1.453 |
| Panorâmica I | R$ 3.504 | R$ 2.050 | R$ 1.489 |
| Afrodite | R$ 4.034 | R$ 2.360 | R$ 1.715 |
| King Spa 7 | R$ 2.747 | R$ 1.750 | R$ 1.580 |
| King Spa 2 | R$ 2.669 | R$ 1.700 | R$ 1.535 |
| Balcony | R$ 2.402 | R$ 1.530 | R$ 1.381 |

- A noite de 03/01 usa os preços de dia de semana de janeiro (3.8).
- **A ordem de hoje pede, em público:** Moinhos R$ 2.550, Panorâmica II R$ 2.600, Panorâmica I R$ 2.650 e Afrodite R$ 3.400. Na Villa, King Spa R$ 2.550 e Balcony R$ 2.295, só sem a promoção [REG 4g].
- **O Recanto hoje:** R$ 4.375 / R$ 4.452 / R$ 4.544 / R$ 5.820 enviados [PAC].

**Posição**
- **Régua comparável de 30/12→03/01:** p40 de R$ 1.859, p50 de R$ 2.123 e p65 de R$ 2.829 [RÉG; `regua_dono.json`].
- **Nós:** o Recanto, a R$ 2.552 por noite no 01→03 [BK2], fica entre o p50 e o p65, não no p92.
- **A Moinhos da tabela** (R$ 1.950) ficaria entre o p40 e o p50.
- **Falta medir o 01→03 pela régua comparável** antes de pedir a decisão 3.
- **Apoio, fora da régua [BK2]:** em 01→03/01, mediana do mercado com hidro de R$ 1.421, p75 de R$ 1.779 e boutique em R$ 1.934. Villa a R$ 1.498. Comparáveis de 2 noites: Geada R$ 707, Murano R$ 1.779, Vila Grega R$ 2.090, Carballo R$ 2.327, Estoril R$ 2.422.
- **O sábado 02/01 é o ponto fraco:** o mercado estava a 24,9% [REV §4].

**Estadia**
- **01→03:** pelo portão. Está aberto no Recanto e fechado na Villa até a promoção sair. Também valem 01→04 e 01→05.
- **02/01:** não aceita saída. A exceção 2 (02→04) vale só com 01/01 esgotado [REG 4g].
- **03/01 (domingo):** 1 noite.

**Táticas**
- O dono fecha o 01→03 da Villa, que vaza a R$ 1.498 por noite, ou tira a promoção [PEN `villa-0103-aberto-booking-20261116`].
- **Em 03/01, "mais uma noite" só a quem está no 01→03.** O 01→04 já foi aceito em 16/11 ("Pode de 1 a 4 e de 1 a 5") [REG 4g].
- Para quem está no 30→03, só com o seu sim explícito (decisão 6). Daria 30→04, contra a ordem de 03/11 ("estender até o dia 03 apenas") e a máxima de 4 noites [ORD `festas-pacotes`; ORD `dono-2026-11-16-reveillon-250k`].

**Gatilho de ajuste (ordem de 16/11)**
- **Escada:** +2% por pacote novo.
- **Descida de 5%** quando os pacotes novos ficam abaixo da linha do marco: 9 em 25/12, 10 em 27/12, 12 em 29/12 e 14 em 31/12.
- **Piso:** alvo público × 0,95⁴ ÷ fator [REG 4g].
- Com a decisão 3, os marcos passam a valer sobre o alvo novo.

### 3.8 Janeiro, dias de semana (04-07, 10-14, 17-21, 25-28 e 31/01)

**Hoje:** Recanto com 22 de 304 (7%). Villa com 23 de 285 (8%) [PAC].
- A Villa tem 18-21/01 e 25-28/01 com zero ou quase zero.
- Pela curva, as noites estão no ritmo.

**Objetivo:** Recanto 40% e Villa 35%. O provável é 35% e 30%.
- O mercado fez 30% de segunda a quinta em 03-21/01. A semana de 25-31/01 é "muito baixa" [SAZ].
- Receita a mais no alvo: R$ 72 mil no Recanto e R$ 41 mil na Villa (est.). **É o maior bloco dos dois meses.**
- A conta usa o preço da tabela. A D7, o preço sobe ao piso: cada diária dessa semana rende mais, mas a ocupação do objetivo fica mais difícil. A estimativa não mede isso.

**Preço-alvo até D8:** Recanto a 0,585.

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos | R$ 1.429 | R$ 836 | R$ 586 |
| Panorâmica II | R$ 1.444 | R$ 845 | R$ 592 |
| Panorâmica I | R$ 1.500 | R$ 878 | R$ 615 |
| Afrodite | R$ 1.802 | R$ 1.054 | R$ 739 |
| King Spa 7 | R$ 1.224 | R$ 780 | R$ 649 |
| King Spa 2 | R$ 1.177 | R$ 750 | R$ 624 |
| Balcony | R$ 1.060 | R$ 675 | R$ 562 |

- **De D7 a D0, o piso sobe à mediana comparável:** cerca de R$ 1.579-1.658 no Recanto (Panorâmica I, cerca de R$ 1.658-1.741) e R$ 1.467-1.680 na Villa. A tabela fica abaixo dele, e a D7 o preço sobe até esse piso [REG 4f, decisão 5].

**Hoje no PAC:** Moinhos R$ 1.654, Panorâmica I R$ 1.658, King Spa 7 R$ 1.598 e Balcony R$ 1.435 (medianas).
- A Moinhos de hoje sai a cerca de R$ 965 públicos [BK2].
- O alvo corta 14% no enviado (R$ 1.429 contra R$ 1.654). No público, o alvo é cerca de R$ 836.
- O motor chega lá até D8, se a noite ficar atrás. A D7, sobe ao piso.

**Posição**
- **Régua de janeiro (terças):** p25 de R$ 438-565, mediana de R$ 999-1.049 e p75 de R$ 1.254-1.417 [RÉG].
- **Onde o alvo põe cada uma:** Recanto perto do p43 da régua e Villa perto do p40.
- **Apoio, fora da régua [BK2]:** mediana do mercado com hidro de R$ 490-538 e boutique de R$ 609-801. Nós hoje: Recanto a R$ 930-978 e Villa a R$ 678-754. Comparáveis: DOM R$ 319-411, Casa Redonda R$ 360, Lavandas R$ 609-934.

**Estadia:** Regra A.

**Táticas**
- Janeiro vende com cerca de 10 dias de antecedência [SAZ]. Não cortar antes de D14.
- Pacote de "3 noites de semana" no direto, com benefício (decisão 6).
- Na Villa, as inversões de preço somem com a promoção fora. Em 05/01, a King Spa 2 saía a R$ 678 e a Balcony a R$ 785 [BK2].
- Balcony: Regra G e Regra I de D0 a D3 [REG].

**Gatilho de ajuste**
- **D14:** 3 de 16 no Recanto e 2 de 15 na Villa.
- **D7:** 5 e 4.
- **Acima:** a noite segura.
- **Abaixo, até D8:** o motor desce até a tabela, nunca abaixo de R$ 1.220 no Recanto e R$ 943 na Villa.
- **De D7 a D0:** o piso é a mediana comparável (R$ 999-1.049 públicos): cerca de R$ 1.579-1.658 no Recanto e R$ 1.467-1.680 na Villa [REG 4f; PLN `piso_semana_ultima`]. Nunca ao p40.
- Ficar abaixo desse piso só com o seu sim para mudar a decisão 5.

### 3.9 Janeiro, fins de semana (08-09, 15-16 e 29-30/01) e feriadão do Aniversário de SP (22-24/01)

**Hoje**
- Fins de semana: Recanto com 2 de 96 (2%) e Villa com 12 de 90 (13%).
- Feriadão: 3 de 48 e 1 de 45 [PAC].

**Objetivo**
- Fins de semana: Recanto 60% e Villa 55%. O provável é 55% e 50%.
- Feriadão: 60%. O provável é 50%.
- O mercado fez 40% na sexta e no sábado de 03-21/01, e o feriadão é de demanda "média" [SAZ].
- Receita a mais no alvo:
  - fins de semana: R$ 50 mil no Recanto e R$ 32 mil na Villa;
  - feriadão: R$ 24 mil em cada pousada (est.).

**Preço dos fins de semana: a abertura do plano de 09/11, pela régua comparável** [ORD `dono-2026-11-09-plano-nov-fev`, decisão 3; ORD 18/11 e 19/11; `regua_dono.json`].
- Régua, em público: Moinhos no p40 comparável, R$ 979-1.012. Panorâmicas e King Spa no p50 dos pares × 1,05, R$ 1.224. A King Spa 2 fica em R$ 1.176-1.204 em 15-16 e 29-30/01, pelo teto do pedido de 09/11.

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos | R$ 1.590-1.644 | R$ 930-962 | R$ 676-699 |
| Panorâmica II | R$ 1.988 | R$ 1.163 | R$ 845 |
| Panorâmica I | R$ 1.988 | R$ 1.163 | R$ 845 |
| Afrodite | R$ 2.287 | R$ 1.338 | R$ 972 |
| King Spa 7 | R$ 1.878-1.983 | R$ 1.196-1.263 | R$ 1.080-1.140 |
| King Spa 2 | R$ 1.878-1.951 | R$ 1.196-1.243 | R$ 1.080-1.122 |
| Balcony (Regra G) | R$ 1.690-1.756 | R$ 1.077-1.119 | R$ 972-1.010 |

- **A tabela é o preço de hoje.** Não proponho abrir abaixo do plano: isso mudaria a decisão 3 do plano de 09/11 e a ordem de 19/11, e só com o seu sim.

**Preço-alvo do feriadão:** piso de feriado, Recanto a 0,585.

| Suíte | Enviado | Público | Genius 3 |
|---|---|---|---|
| Moinhos e Panorâmicas | R$ 1.905 | R$ 1.114 | R$ 810 |
| Afrodite | R$ 2.191 | R$ 1.282 | R$ 931 |
| King Spa 7 e King Spa 2 | R$ 2.052 | R$ 1.307 | R$ 1.180 |
| Balcony | R$ 1.847 | R$ 1.176 | R$ 1.062 |

**Hoje no PAC:** Moinhos a R$ 1.595-1.654, Panorâmica I a R$ 1.998, King Spa 7 a R$ 1.880-1.998 e Balcony a R$ 1.689-1.791. No feriadão: Moinhos a R$ 1.912-2.135 e King Spa 7 a R$ 2.057.

**Posição**
- **Régua:** p25 de R$ 827-876, mediana de R$ 1.127-1.230 e p75 de R$ 1.619-1.839. Em 22→25/01, mediana de R$ 1.051 [RÉG].
- **Onde o plano põe cada uma:** Moinhos no p40 (R$ 979-1.012) e Panorâmica I e King Spa no p50 dos pares × 1,05 (R$ 1.224). A 0,585, a Booking mostra cerca de 5% menos no Recanto.
- **Apoio, fora da régua [BK2]:**
  - mediana do mercado com hidro de R$ 656-702 e boutique de R$ 661-1.030;
  - nos fins de semana, Recanto a R$ 930-965 e Villa a R$ 1.043-1.106, com a Balcony sem banheira como o menor preço;
  - no feriadão, Recanto a R$ 1.113 e Villa a R$ 1.137;
  - comparáveis: DOM R$ 614, Casa Redonda R$ 540, Chateau R$ 501, Lavandas R$ 700-1.030, Della Nonna R$ 552-702.

**Estadia**
- Chegada na sexta com 2 noites. O sábado fica fechado. No feriadão, o mínimo é 2 noites [SAZ; REG].
- **Gatilho de 01/01:** se 22-24/01 estiver abaixo de 20% vendido, o domingo 24/01 sai do feriado e passa a 1 noite [ORD `dono-2026-11-02-aniv-sp-gatilho`].

**Táticas**
- A Villa precisa voltar a ficar abaixo do Recanto, porque a nota dela é 8,3. No plano, as duas abrem no mesmo público da régua; a diferença na vitrine sai do fator medido de cada uma (decisão 4).
- Ondas à base de clientes (decisão 6).

**Gatilho de ajuste**
- **Fins de semana:**
  - D14: 5 de 16 no Recanto e 5 de 15 na Villa.
  - D7: 8 e 8.
  - Suíte com menos de 60% vendida a D7 desce 10%, uma vez, nunca abaixo de R$ 1.000 enviados [REG 4f; PLN `desce`].
  - O D7 cai em 01/01 (08-09/01), 08/01 (15-16/01) e 22/01 (29-30/01).
- **Feriadão:**
  - 01/01: o gatilho do domingo.
  - D14 (08/01): 8 de 16 e 8 de 15.
  - D7 (15/01): 11 e 10.

---

## 4. Canais e distribuição

### O peso de cada canal (reservas feitas em novembro) [NOV]

| Canal | Recanto: parte da receita | Recanto: diária | Villa: parte da receita | Villa: diária |
|---|---|---|---|---|
| Booking | 66% | R$ 763 | 71% | R$ 613 |
| Direto (à mão + API/site) | 21% | R$ 839 | 19% | R$ 566 |
| Airbnb | 11% | R$ 430 | 6% | R$ 342 |
| Expedia | 2% | R$ 524 | 4% | R$ 374 |

- No Recanto, o direto soma o lançado à mão (R$ 19.751 em 23 diárias) e o API/site (R$ 8.771 em 11 diárias). Na Villa, o API/site tem R$ 0.

### O que a skill faz sozinha, dentro das regras

- **Preço:**
  - regra por data em `percent_base`;
  - motor de pace e reposição de 01/12;
  - coerência entre suítes, Regra G e regra dos 80% [REG].
- **Fatores, na revisão de 30/11:**
  - Recanto: 0,585 em todas as janelas. A nota de 23/11 manda passar a 0,60 se a medição seguir em 0,58 [QJ `fator_publico`]. A busca de 27/11 mediu 0,583-0,585 também a D7, D11 e D14 [BK1]. O 0,63 de até 14 dias sai [PEN `fator-publico-recanto-0583-20261118`].
  - Villa: o fator medido por tipo de noite, já. Depois de a promoção sair, mede de novo no mesmo dia (decisão 4). Abaixo de 0,55, a regra trata como promoção a confirmar com você [QJ].
- **Régua e revisões:**
  - refazer a régua do plano, atrasada desde 24/11, pelo ranking comparável (`scripts/ranking_comparaveis.py`), não pela BK1 [PEN `plano-dono-regua-refazer`];
  - revisão dos 12 meses em 01/12 [LOG 23/11].
- **Réveillon pelo script:** escada, marcos, noites soltas e portão [REG 4g]. Depende do Beds24.
- **Estadia:** Regra A, noites órfãs e janelas [REG; ORD `vender-janelas`]. Depende do Beds24.
- **Mínimo do anúncio, passo 4:** Moinhos para R$ 1.220 em 30/11 [ORD `dono-2026-11-09-minimo-piso-semana`].
- **Acompanhamento:** leitura diária da Booking, só de leitura, e o relatório com os três preços.
- **Textos prontos:**
  - as ondas para a base de clientes;
  - a mensagem para a recepção;
  - o pedido ao suporte da Booking sobre a nota do Recanto [ORD `dono-2026-11-19-pode-fazer-tudo`, item 2].

### O que o dono faz

**Na extranet da Booking, nesta ordem**
1. **Segurança da conta:** verificação em 2 etapas e limpeza de usuários [PEN `booking-invadida-2026-11-03`]. Vem antes das ondas.
2. **Promoções da Villa** (decisão 4). Hoje o público sai a 0,38-0,51 do enviado em dia de semana, a 0,32 (Balcony) a 0,38 (King Spa 7) no Natal e a 0,33-0,41 no Réveillon e em 01-03/01 [PEN `villa-booking-razao-0436-20261123`; BK1; BK2].
3. **Marcar "Banheira de hidromassagem/Jacuzzi"** na propriedade da Villa. Hoje ela some do filtro de hidro [BK1, achado 1].
4. **Nota do Recanto.** O conector não devolve nota para o Recanto. Conferir também o anúncio duplicado [BK1, achado 2; PEN `booking-listagem-duplicada-recanto`].
5. **Desconto do Recanto na busca.** Em todas as datas, a busca mostra 0,8824 da tarifa do quarto [BK1, achado 3; BK2, achado 10]. Falta saber que desconto é e se soma com o Genius [REV §9, item 4].
6. **Genius:** no máximo o nível 3 [REG §1]. Conferir se soma com a promoção da Villa: nesse caso o hóspede pode pagar menos de R$ 500.
7. **Visibility Booster**, se a decisão 7 for sim.
8. **Tarifa não reembolsável**, se você quiser criar. É sua [REG §1]. Fora das noites no piso, ou com o piso subido pelo desconto (3.4).

**Airbnb**
- Rende 41% do enviado no Recanto e 31% na Villa, contra 49% e 46% da Booking [QJ `fator_canal`].
- Manter aberto, com o multiplicador corrigido no Beds24 [PEN `airbnb-repasse`].

**Expedia**
- A Villa está fechada até você reabrir [ORD `dono-2026-11-18-expedia-villa-fechada`]. Não recomendo reabrir nas festas.
- O Recanto vende pela Expedia a 41-46% do esperado [APR, 19/11].

**Venda direta e recepção**
- **Tabela do direto** com o preço do dia e o mínimo. A recepção nunca vende abaixo dela [PEN `vendas-abaixo-recepcao-expedia-20261117` e `vk7-diretas-abaixo-20261110`].
- **Benefício no lugar de desconto** na sexta, no sábado e nas festas: ceia, saída tardia, fondue na 3ª noite [EDZ §5].
- **Nas festas:** pré-pagamento, sem desconto.
- **Tudo lançado no Beds24 no mesmo dia.**
- **A Balcony vende no direto** como "suíte com varanda", pela tabela.
- **Referência:** em 2024, o site trouxe 34% da receita do Recanto [P12 §1b].
- **Opção do plano de 12 meses:** Google pago nos dias úteis de janeiro [P12].

---

## 5. Calendário semana a semana

| Semana | A skill faz | O dono e a recepção fazem |
|---|---|---|
| 30/11-06/12 | **30/11:** revisão semanal, fatores, passo 4 do mínimo (Moinhos R$ 1.220) e marco do Réveillon (só fecha com o Beds24; sem ele, a escada fica parada). **01/12:** começa a reposição de dez-fev, refaço a régua do plano pelo ranking comparável e faço a revisão dos 12 meses. **04/12:** D7 de 11-12/12 (−10% onde a suíte tiver menos de 60%). Leitura diária de 18-19/12 na Villa. | Token do Beds24 nos segredos do ambiente (`BEDS24_TOKEN` ou `BEDS24_REFRESH_TOKEN`), nunca no chat. Confirmação dos lotes e do overbooking de 06 e 12/12. Respostas da seção 7 (1, 2 e 4 a 9). Promoções da Villa, Jacuzzi e nota do Recanto. Segurança da conta. Tabela do direto na recepção. Onda 1 (Natal e Réveillon), se a decisão 6 for sim. |
| 07-13/12 | **07/12:** vencem a ordem do mínimo e o teste da King Spa 7; relato e pergunto se renova. Faixas de fevereiro e Carnaval até 07/12 [PEN `faixas-remedir`]. Posicionar o feriadão de SP (plano: entre 08 e 23/12) [P12]. **09/12:** Natal a D14. **11/12:** D7 de 18-19/12. **13/12:** entre festas a D14. | Booster das festas, se a decisão 7 for sim. Conferir as vendas da recepção. |
| 14-20/12 | **14/12:** revisão semanal. **15/12:** régua nova, a cada 15 dias [REG 4f]. **16/12:** Natal a D7 (−10% onde a suíte tiver menos de 60%, até o piso). **20/12:** entre festas a D7. | Onda 2, "última chamada" das festas. Se a decisão 1 for sim: combinar com a recepção a "mais uma noite" de 27/12. |
| 21-27/12 | **21/12:** marco do Réveillon (+5% com 10 de 12 pacotes). **22/12:** abrir o Carnaval [P12]. **23, 25 e 27/12:** marcos de descida. Natal na última hora, sem descida além da de D7. | Controle do pré-pagamento. |
| 28/12-03/01 | **29/12:** marcos e régua de março em diante, antes de 31/12 [REG 4f]. **31/12:** marco do fim de semana (menos de 14 pacotes). **01/01:** gatilho do feriadão de SP e D7 de 08-09/01. **Depois de 02/01:** o máximo do anúncio volta a R$ 5.000 [REG 4g]. | Onda 3 (janeiro) em 28/12. "Mais uma noite" em 03/01, só para o 01→03 (o 30→03 só com o seu sim). |
| 04-10/01 | **04/01:** revisão semanal. Dias de semana de janeiro de D14 a D7. **08/01:** feriadão a D14 e D7 de 15-16/01. | Venda direta do pacote "3 noites de semana". |
| 11-17/01 | **11/01:** revisão semanal. **12/01:** régua nova. **15/01:** feriadão a D7. | Onda 4 (fim de janeiro e feriadão) em 11/01. |
| 18-24/01 | **18/01:** revisão semanal. Leitura diária da Villa em 18-21/01, que está sem venda. **22/01:** D7 de 29-30/01. Feriadão de 22-24/01. | Recepção reforçada no feriadão. |
| 25-31/01 | **25/01:** revisão semanal. Baixa temporada [SAZ]. **26/01:** régua de fevereiro. Fechamento do mês. | Decisões de fevereiro e do Carnaval. |

---

## 6. Riscos e travas

1. **Lotes suspeitos.**
   - São 49 suítes-noite em dez-jan, cerca de R$ 36,2 mil (est.). Valem 24% da receita da Villa em dezembro [PAC].
   - Houve um 4º lote em 24/11, com 4 reservas no mesmo segundo e comissão 0 [LOG 27/11].
   - As noites ficam fora do motor até a confirmação [PEN `booking-lote-16-reservas-20261117`].
   - **Ação:** a recepção confirma até 01/12. Se não forem reais, você pede o cancelamento à Booking.
2. **Overbooking na King Spa 7.**
   - 06/12: 8 reservas para 7 unidades, 3 delas de lote.
   - 12/12: 9 para 7, só 1 de lote. Mesmo sem o lote, sobra 1 reserva [PAC].
   - A recepção precisa resolver antes da chegada.
3. **Credencial do Beds24.**
   - A API devolve 401 desde 24/11 [PEN `beds24-credencial-perdida-20261124`].
   - O token entra nos segredos do ambiente (`BEDS24_TOKEN` ou `BEDS24_REFRESH_TOKEN`), nunca no chat [REG §1].
   - Sem ela não há prova de noite órfã nem auditoria de estadia.
   - Também não há contagem dos pacotes do Réveillon nem conferência do price1 do portão.
   - Leitura incompleta é recusada, e a escada fica parada [REG L337].
   - **Prazo útil:** antes de 21/12. Sem ela, os marcos de 21 a 31/12 não rodam.
4. **Piso da vitrine.**
   - Promoção e Genius somados podem passar abaixo de R$ 500 em dia de semana.
   - No Natal da Villa, a Balcony já vendeu a R$ 497-1.118 por noite, cerca de R$ 700-780 líquidos, abaixo do seu limite de R$ 800 [EDZ §5, pergunta 12].
   - O desconto de 0,8824 do Recanto na busca precisa ser explicado (seção 4).
5. **Coerência entre suítes.**
   - Na Villa, King Spa mais barata que a Balcony em 05, 12 e 26/01 [BK2].
   - King Spa 7 mais barata que a King Spa 2 em 15/12 [BK1].
   - Panorâmica I a 1,98 vez a Moinhos em 03/12 [BK1].
   - Balcony acima da Moinhos em 18-20/12 [BK1].
   - Afrodite presa no piso em 21 noites [APR].
6. **Receita baixa ou bloqueio lançado como reserva.**
   - São 34 suítes-noite com menos de R$ 300, quase todas na Panorâmica I [PAC].
   - Se forem bloqueios, o Recanto tem cerca de 30 livres a mais [APR, 23/11].
7. **Cancelamentos.**
   - 20% das reservas feitas em novembro foram canceladas.
   - Os cancelamentos feitos em novembro tiraram R$ 117.386 [NOV].
8. **Corte do Natal do Recanto (só com a decisão 9).**
   - Descer 32% a 39% em 23-24/12 com o mercado apertando pode deixar dinheiro na mesa.
   - A defesa é a escada: +7% por unidade depois de metade da suíte vendida, até o p75 comparável (R$ 2.300 e R$ 2.228 públicos).
9. **Dados.**
   - O enviado do PAC é anterior à gravação da Villa de 27/11 [LOG 27/11].
   - A Moinhos e a Afrodite vêm sem a ocupação do PriceLabs no pacote.
   - 13 quartos-noite divergem do PriceLabs [PAC].
   - A cobrança do PriceLabs segue em aberto. O envio diário voltou [LOG 27/11].
10. **Mínimo do dono.**
    - Pede 78% e 83% de ocupação com estes preços (est.).
    - Buscar esse número pelo preço contraria a régua e o seu pedido de não vender barato [ORD `vender-janelas`].

---

## 7. Decisões do dono (responda sim ou não)

**1. Natal flexível.** Posso abrir também 23→26, 24→26 e 25→27 nos 7 quartos, com mínimo de 2 noites, mantendo o 23→25 e sem mudar o preço?
- **O que muda:** a ordem `dono-2026-11-03-festas-pacotes`, que hoje só aceita chegada em 23/12.
- **Restrições que mudam no Beds24.** A estadia vai por stayThrough: mínimo e máximo por noite. Não dá para abrir só as estadias pedidas.
  - A máxima de 23-24/12 vai de 2 para 3 noites (para o 23→26).
  - A chegada abre em 24/12 (para o 24→26) e em 25/12 (para o 25→27).
- **Estadias que passam a caber sem terem sido pedidas:** 22→25, pela máxima de 3 em 23-24/12. Também 24→27, se 25-26/12 aceitarem 3 noites. O validador lista as outras antes de gravar.
- **Preciso do seu ok para elas**, ou proponho antes uma trava que as barre. Sem isso, não gravo. Em 16/11, o efeito colateral 01→04 e 01→05 precisou do seu ok explícito [REG 4g, 4ª condição do portão].
- **Efeito:** cerca de R$ 14 a 18 mil (est.). As noites de 25-26/12 venderam 3 de 62. Com 55% em 25/12, entram cerca de 15 suítes-noite.
- **Se não:** o objetivo do Natal cai de 70%/65% para cerca de 55%.

**2. Réveillon a partir de 29/12.** Posso tirar a máxima de 2 noites de 29/12 e abrir 29→01 e 29→03, mantendo 30→01, 30→03 e 01→03?
- **O que muda:**
  - a ordem `festas-pacotes`;
  - a máxima de 29/12 em `quartos.json` → `feriados`;
  - a ordem do Réveillon, que diz que 29/12 "não muda".
- **Restrições que mudam no Beds24:**
  - a máxima de 29/12 (hoje 2 noites);
  - para o 29→03, que tem 5 noites, a máxima de 4 em 30/12-02/01 teria de ir a 5 [ORD `dono-2026-11-16-reveillon-250k`].
- **Estadias que passam a caber sem terem sido pedidas:**
  - com a máxima de 5 em 30/12-02/01: 30→04, contra a ordem de 03/11 ("Podendo o hóspede estender até o dia 03 apenas"), e 01→06;
  - sem a máxima em 29/12: estadias como 27→30;
  - e outras que o validador listar.
- **Preciso do seu ok para cada uma**, ou proponho antes uma trava que as barre. Sem isso, não gravo.
- **Uma saída mais simples:** abrir só o 29→01. Ele cabe na máxima de 4 de hoje em 30/12-02/01, mas ainda abre estadias como 27→30.
- **Condição:** só gravo depois de o script do Réveillon e o validador contarem esses pacotes.
- **Efeito:** cerca de R$ 8 a 10 mil (est.), se vender 4 ou 5 suítes de 29/12 em cada pousada. Hoje o Recanto tem 16 de 16 livres nessa noite.

**3. Preço do fim de semana de 01-03/01 (depois da medição).** Antes de pedir o seu sim, meço o 01→03 pela régua comparável.
- **O que já se sabe:** na régua de 30/12→03/01, o Recanto (R$ 2.552 por noite) fica entre o p50 (R$ 2.123) e o p65 (R$ 2.829), não no p92 [RÉG; BK2].
- **O que vou pedir, se a medição confirmar:** trocar o alvo público da ordem de 16/11 (Moinhos R$ 2.550; King Spa da Villa R$ 2.550) pelo da tabela 3.7 (Moinhos R$ 1.950; King Spa 7 R$ 1.750), mantendo a escada e os marcos.
- **O que muda:** a ordem `dono-2026-11-16-reveillon-250k`.
- **Efeito:** cerca de R$ 10 a 15 mil (est.), quase tudo no Recanto. A hipótese é passar de 21% (13 de 62 no PAC; Recanto 8 de 32, Villa 5 de 30) para 80% em 01-02/01. O resultado depende de quanto a venda responde ao preço.
- **Se não:** os marcos descem até 4 vezes 5% até 31/12, cerca de R$ 2.080 na Moinhos. É tarde para um mercado que reserva com 60 dias [SAZ].

**4. Lembrete: promoções da Villa (item seu).** Tirar as promoções da Villa na extranet (dia de semana, Natal, Réveillon e 01-03/01) já é o item 3 seu na pendência `reveillon-250k-decisoes-20261116`. Você consegue fazer até 01/12?
- **A sequência, no mesmo dia:**
  1. você tira a promoção;
  2. a skill mede o fator na Booking, pelo conector;
  3. só então a skill repõe o enviado pelas tabelas da seção 3, com o fator medido.
- **No Réveillon e em 01-02/01:** o preço da Villa só entra depois de a promoção sair e de medir o fator novo. O fator novo só substitui o guardado se ficar fora de ±5% dele [REG 4g, portão].
- **Com ou sem resposta,** a skill já usa o fator medido por tipo de noite [REG 6a]. As tabelas usam 0,637 só como estimativa até a medição.
- **O que muda:**
  - a decisão 6 do plano de 09/11, "festas da Villa ficam como estão";
  - a Villa "sem mudança" na ordem do Réveillon [REG 4f e 4g].
- **Efeito:** quase neutro na vitrine, porque as tabelas mantêm o público perto do de hoje (no Natal, só com o piso de R$ 800 registrado). Estimo de 0 a R$ 5 mil a mais (est.).
- **O ganho principal:**
  - deixar de vender abaixo dos seus pisos: R$ 500 no Genius 3 e, no Natal, o limite de R$ 800 líquidos e o piso vigente de R$ 1.000;
  - pôr de novo a Balcony abaixo das King Spa;
  - abrir o portão do 01→03 da Villa.
- **Por que tudo no mesmo dia:** sem a reposição, a vitrine sobe de 55% a 66% e a Villa para de vender.

**5. Escada só em noite no ritmo.** Posso deixar a escada subir só em noite no ritmo ou à frente da curva? A noite atrás seguraria o preço.
- **O que muda:** uma regra [PEN `escada-nao-sobe-atras-20261123`].
- **Efeito:** proteção. As subidas de 17, 18 e 21/11 trouxeram 0 venda em 2 a 6 dias [APR]. Estimo até R$ 5 mil nos dois meses (est.).

**6. Venda direta com benefício.** Posso preparar e você aprovar este pacote?
- **(a) Tabela do direto para a recepção:**
  - de domingo a quinta, 10% abaixo do público da Booking, acima do Genius 3 e de R$ 500 nas suítes com banheira;
  - na sexta, no sábado e nas festas, o preço público com benefício.
- **(b) 4 ondas para a sua base:** 01/12, 15/12, 28/12 e 11/01, com ceia, saída tardia e fondue na 3ª noite.
- **(c) "Mais uma noite",** por no mínimo R$ 1.000 a noite:
  - em 03/01, só a quem está no 01→03 (o 01→04 já foi aceito em 16/11) [REG 4g];
  - para quem está no 30→03, só com o seu sim explícito. Daria 30→04, contra a ordem de 03/11 ("estender até o dia 03 apenas") [ORD `festas-pacotes`]. Você autoriza?
  - no Natal (27/12), só se a decisão 1 for sim.
- **Efeito:** cerca de R$ 10 a 20 mil nos dois meses (est.).
  - A premissa é a de 17/11: 1.500 contatos e 1% de conversão.
  - Cada 500 contatos a menos tiram cerca de R$ 2 mil na conta das 3 ondas de 17/11 [EDZ §5, perguntas 7 e 8].
- **Condição:** a onda 1 só sai depois da segurança da conta.

**7. Visibility Booster nas festas.** Você liga o Booster no menor nível no Recanto de 20 a 29/12 e de 01 a 03/01?
- **O que muda:** é exceção ao plano de 12 meses, que guarda o Booster para dia útil de baixa [EDZ §5, pergunta 11].
- **O limite de R$ 800:**
  - em 29/12, o Recanto fica no piso, R$ 1.905, que é exatamente R$ 800 líquidos (fator_real 0,42) [REG "Feriados"]. Com a decisão 9, também em 23-26/12;
  - a comissão extra do Booster leva o líquido dessas noites abaixo de R$ 800, que é limite seu [REG §1 e §2];
  - preciso de um destes: o seu sim para ficar abaixo de R$ 800 nessas noites, ou subir o piso delas pela comissão extra.
- **Efeito:** cerca de R$ 5 a 7 mil (est., conta de 17/11), menos a comissão extra dessas reservas.

**8. Meta do Réveillon.** Posso medir o Réveillon pela meta realista do mês, no lugar da meta de R$ 250 mil?
- **O que muda:** a ordem `dono-2026-11-16-reveillon-250k`. Só você muda metas [REG 6a, regra de 14/10].
- **Por quê:** faltam R$ 201.594 em 77 diárias livres, R$ 2.618 por diária mesmo vendendo 100% [REV].
- **Até o seu sim:** ficam a meta, as linhas de alarme e os marcos da ordem.

**9. Natal do Recanto no piso de feriado.** Posso descer o Recanto ao piso de feriado em 23-26/12, antes de D7? Moinhos e Panorâmica II a R$ 1.905, Panorâmica I a R$ 2.000 e Afrodite a R$ 2.300.
- **O que muda:** a decisão 6 do plano de 09/11 e a ordem de 19/11, que põem a Moinhos no p40 e as Panorâmicas no p50 da régua comparável [ORD `dono-2026-11-09-plano-nov-fev`; ORD `dono-2026-11-19-precificacao-dez-fev`].
- **Quanto desce:**
  - 23-24/12, de 32% a 39%: Moinhos R$ 2.803 → R$ 1.905; Panorâmica II R$ 3.116 → R$ 1.905; Panorâmica I R$ 3.212 → R$ 2.000; Afrodite R$ 3.700 → R$ 2.300 [PAC];
  - 25-26/12, de 16% a 24%: Moinhos R$ 2.264 → R$ 1.905; Panorâmica I R$ 2.620 → R$ 2.000; Afrodite R$ 3.020 → R$ 2.300 [PAC].
- **Por quê:** o ritmo. O Recanto tem 4 de 64 (6%) a 26-28 dias, e a curva pede 30-35% [PAC; EST §5]. Pela régua comparável, o plano não está caro: o motivo é só a venda.
- **Efeito:** até R$ 39 mil (est.), a receita a mais do Recanto no Natal com 70% e esses preços. Sem a 9, o alvo não conta venda nova do Recanto no Natal. Parte dela viria também no preço do plano, e a conta não separa quanto.
- **Riscos:**
  - deixar dinheiro na mesa se o mercado apertar (risco 8). A defesa é a escada até o p75 comparável;
  - no piso, o líquido é exatamente R$ 800. Booster e tarifa não reembolsável o levariam abaixo (decisão 7 e 3.4).
- Não é desconto de OTA: é preço por data. O plano de 12 meses pede "sem desconto antes de D10" nas festas [P12].
- **Só gravo com o seu sim.**

**O que não pergunto de novo:** Balcony mais barata. Você recusou em 09/11 e em 16/11 [REG 4f; ORD `vender-janelas`]. Ela segue na Regra G. O custo é ter 349 Balcony livres nos dois meses.

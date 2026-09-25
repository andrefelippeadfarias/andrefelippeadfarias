# Relatório PriceLabs — Pousada Recanto dos Moinhos e Villa Dolce Amore

**Versão 3 · 25/09/2026.** Premissa: **os descontos das OTAs são fixos e não podem ser alterados.** Esta versão passou por uma revisão independente de números, de funcionamento técnico e de risco operacional.

**Fontes:**
- Conta PriceLabs, lida pelo conector MCP.
- 600 reservas do Beds24 feitas nos últimos 60 dias.
- 290 reservas com estadia nos próximos 60 dias, cruzadas com o calendário de preços do PriceLabs.
- Documentação oficial do PriceLabs, do Beds24, da Booking.com e do Airbnb (lista no fim).

**Cópia da configuração atual, para desfazer a Fase 1:** `snapshot-configuracao-2026-09-25.json`, na mesma pasta. Ela traz preços, personalizações do grupo e substituições por data das 7 listings. O que só aparece na tela (regras de estadia mínima, mínimos avançados, Safety Minimum Price e tudo do Beds24) precisa ser anotado à mão antes do Dia 1.

**Meta de lotação antes de cada data:**

| Janela | 7 dias | 15 dias | 30 dias | 45 dias | 60 dias |
|---|---|---|---|---|---|
| Meta | 70% | 50% | 35% | 25% | 15% |

---

## 1. Resumo executivo

1. **Nenhuma janela bate a meta hoje.** O portfólio está em 39% nos próximos 7 dias, contra 70% de meta. A janela de 60 dias está praticamente na meta, com 14,9% contra 15%. Vocês estão acima do mercado na janela de 7 dias, que está em 28%. O problema não é falta de demanda na cidade.
2. **O desconto real das OTAs foi medido: o hóspede da Booking paga cerca de metade do preço que o PriceLabs envia.** A mediana é 48% do preço enviado nas reservas dos últimos 3 dias e 44% nos últimos 10 dias. O desconto efetivo fica entre 51% e 59%, acima do teto de 50% que vocês estimavam.
3. **Todo valor em reais no PriceLabs vale cerca de metade para o hóspede.** O mínimo de R$ 800 vira cerca de R$ 330 num dia de semana e R$ 390 no fim de semana, ainda antes da comissão da Booking.
4. **A King Suite with Balcony (6 unidades) está quase vazia:** 5 quartos-noite vendidos nos próximos 60 dias, todos em 05–06/10 e 11/10. Isso acontece mesmo com o hóspede vendo cerca de R$ 330 por noite no meio da semana. O problema dessa categoria não é preço, é canal, mapeamento ou anúncio. Não mexa no preço dela antes de checar o Beds24 e a Booking.
5. **O fim de semana está fraco em 2 dos 7 tipos de quarto:** Queen Spa de 7 unidades (14%) e Queen Spa de 2 unidades (25%). O mercado está em 48%. Quatro dos outros cinco vendem melhor no fim de semana do que na semana. A Balcony não vende em nenhum dos dois. A medição cobre um único fim de semana, então vale confirmar nas próximas semanas.
6. **O tom "Agressivo" em Sazonalidade e Fator de Demanda faz o preço oscilar ao máximo.** Ele cai muito quando a demanda cai e sobe muito quando ela sobe. Com a OTA tirando mais de 50% por cima, isso gera dia de semana barato demais e fim de semana caro demais. O tom "Recomendado" suaviza os dois lados.
7. **Os descontos do PriceLabs se somam aos da OTA.** A última hora está em "Market Driven Agressivo". A recomendação é deixá-la leve ou desligá-la, conforme as ofertas que estão ativas na Booking.
8. **Estratégia em duas fases.**
   - **Fase 1, já:** ajustes dentro do PriceLabs, na escala atual, sem mexer no Beds24. É segura e reversível.
   - **Fase 2, opcional:** a "virada". Os valores do PriceLabs passam a significar o preço do hóspede, e o Beds24 multiplica por canal. Só com procedimento controlado e depois de resolver as pendências de cadastro.
9. **O ajuste por ocupação por tipo de quarto** (Multi-Room Occupancy-Based Adjustment) está no padrão automático do PriceLabs. A tabela proposta aqui converte as suas metas em gatilhos. Ela vale só para os quartos com 6 ou 7 unidades.
10. **Há cinco pendências de cadastro para checar antes das mudanças de preço:**
    - A Villa King Spa de 7 unidades não devolve calendário de preços pela API do PriceLabs.
    - A Double Spa tem "7 Units" no nome, mas aparece com 6 unidades no PriceLabs.
    - A Queen Spa de 2 unidades tem desconto efetivo de 66%, muito acima dos outros quartos.
    - A Villa King Spa de 2 unidades aparece com 1 de 2 quartos ocupados no PriceLabs, mas o Beds24 tem reservas para 2 de 2 entre 25 e 29/09. Pode ser overbooking ou mapeamento errado.
    - Os valores de reserva do Airbnb vêm inconsistentes do Beds24.

---

## 2. Situação atual contra as metas

Ocupação hoje por janela, em quartos-noite vendidos sobre disponíveis. 🟢 bate a meta, 🔴 abaixo. Fonte: calendário do PriceLabs. A Villa King Spa de 7 unidades foi calculada pelas reservas do Beds24, porque não tem calendário no PriceLabs.

| Tipo de quarto (unid.) | 7d (meta 70) | 15d (meta 50) | 30d (meta 35) | 45d (meta 25) | 60d (meta 15) | Fds 7d | Semana 7d |
|---|---|---|---|---|---|---|---|
| Moinhos · Queen Spa (7) | 🔴 43% | 🔴 23% | 🔴 17% | 🔴 12% | 🔴 10% | 14% | 54% |
| Moinhos · Double Spa (6 no PriceLabs) | 🔴 43% | 🔴 31% | 🔴 29% | 🔴 23% | 🟢 21% | 75% | 30% |
| Moinhos · Queen Spa Afrodite (1) | 🟢 71% | 🟢 60% | 🟢 53% | 🟢 42% | 🟢 35% | 100% | 60% |
| Moinhos · Queen Spa (2) | 🔴 50% | 🔴 27% | 🔴 27% | 🟢 29% | 🟢 28% | 25% | 60% |
| Villa · King Spa (7) | 🔴 59% | 🔴 38% | 🔴 32% | 🔴 23% | 🟢 18% | 71% | 54% |
| Villa · King Balcony (6) | 🔴 0% | 🔴 2% | 🔴 3% | 🔴 2% | 🔴 1% | 0% | 0% |
| Villa · King Spa (2) ⚠️ | 🔴 36% | 🔴 27% | 🔴 22% | 🔴 23% | 🟢 18% | 50% | 30% |
| **Portfólio** | 🔴 **39%** | 🔴 **26%** | 🔴 **22%** | 🔴 **18%** | 🔴 **14,9%** | | |
| **Mercado (350 similares, 15 km)** | 28% | 22% | 19% | 15% | 14% | 48% | 19% |

⚠️ Pelas reservas do Beds24, a Villa King Spa (2) estaria com 71% em 7 dias. Ver a pendência 10.

**"Fds 7d" é um único fim de semana:** 25 e 26/09, sendo o dia 25 hoje.

### O que as suas metas significam dia a dia

As metas são cumulativas. Traduzidas para cada faixa de dias, ficam assim:

| Faixa de dias até a data (hoje = dia 0) | Ocupação que a faixa precisa ter | Ocupação hoje |
|---|---|---|
| 0 a 6 | ≈ 70% | 39% |
| 7 a 14 | ≈ 30% | 14% |
| 15 a 29 | ≈ 20% | 18% |
| 30 a 44 | ≈ 5% | 9% ✅ |
| 45 a 59 | já coberta pelas anteriores | 7% ✅ |

**Leitura:** o funil de longo prazo está bom. O déficit está concentrado nos próximos 15 dias, e principalmente nos próximos 7. A janela de 45 dias (18%) está abaixo da meta de 25% porque carrega o déficit das faixas mais próximas, não por falta de reservas antecipadas.

### Como o hóspede reserva (600 reservas feitas entre 27/07 e 25/09)

| Indicador | Valor |
|---|---|
| Reservas feitas no dia ou na véspera da chegada | 29% |
| Reservas feitas até 7 dias antes | 50% |
| Reservas feitas com 60 dias ou mais | 20% |
| Estadias de 1 noite | 74% (média de 1,33 noite)* |
| Canal | Booking.com 70% · Airbnb 19% · direto e outros 10% · Expedia <1% |
| Valor pago por noite, mediana | R$ 850 |
| Valor pago por noite, por dia de chegada | Sex 1.693 · Sáb 1.123 · Qua 991 · Ter 883 · Qui 846 · Seg 844 · Dom 752 |

\* Medido com a regra de 2 noites no fim de semana já em vigor. Parte da preferência por 1 noite vem dos dias de semana.

---

## 3. O desconto real das OTAs, medido

**Método:** para cada reserva ativa com estadia nos próximos 60 dias, dividi o valor pago por noite pelo preço atual que o PriceLabs envia para aquelas noites. As reservas dos últimos 3 dias são as mais confiáveis, porque o preço quase não mudou desde a reserva. A comissão da Booking não aparece nos dados, então os valores são o que o hóspede pagou, antes da comissão.

| Canal e janela | Reservas | Pago ÷ enviado (mediana) | Desconto efetivo |
|---|---|---|---|
| Booking.com, reservas dos últimos 3 dias | 14 | 0,48 | 52% |
| Booking.com, reservas dos últimos 10 dias | 36 | 0,44 | 56% |
| Booking.com, estadias só em dias de semana (10 dias) | 26 | 0,41 | 59% |
| Booking.com, estadias com sexta ou sábado (10 dias) | 10 | 0,49 | 51% |
| Direto e outros | 3 | 0,48 | 52% (amostra pequena) |
| Airbnb | 3 | inconsistente | os valores do Beds24 não fecham |

| Tipo de quarto (só Booking, reservas dos últimos 10 dias) | Enviado (mediana) | Pago por noite (mediana) | Pago ÷ enviado |
|---|---|---|---|
| Queen Spa (7), 9 reservas | R$ 1.481 | R$ 562 | 0,49 |
| Double Spa, 10 reservas | R$ 1.856 | R$ 896 | 0,49 |
| Queen Spa (2) | R$ 1.333 | R$ 455 | **0,34** |
| Villa King Spa (2) | R$ 1.045 | R$ 540 | 0,51 |

**O que isso significa**

- **O desconto efetivo da Booking fica entre 51% e 59%**, acima do teto que vocês estimavam. É compatível com as regras da Booking. O hóspede pode somar o desconto Genius, a tarifa Mobile ou a tarifa por país, uma oferta (básica, de última hora ou antecipada) e ainda o desconto do plano tarifário. A Booking chama isso de desconto "cumulativo". A conta exata não é documentada, mas o valor medido confirma o tamanho.
- **O hóspede de dia de semana paga em torno de R$ 508 por noite** (mediana, só Booking). Hoje só a Balcony (R$ 800 enviado) e a Afrodite (R$ 1.500 enviado) estão no piso no calendário dos próximos 60 dias.
- **A Queen Spa de 2 unidades perde 66% entre o preço enviado e o pago**, sempre. Vale checar se há um multiplicador menor que 1 no Beds24 ou uma oferta extra só para esse quarto na Booking.
- **O PriceLabs não sabe desse desconto.** A documentação dele diz que taxas e descontos de cada canal entram depois que o preço sai do PriceLabs.

### Consequências práticas

| Valor no PriceLabs | Enviado | Hóspede em dia de semana (≈ 0,41) | Hóspede no fim de semana (≈ 0,49) |
|---|---|---|---|
| Mínimo da Queen Spa (7) ou da Balcony | R$ 800 | ≈ R$ 330 | ≈ R$ 390 |
| Mínimo da Afrodite | R$ 1.500 | ≈ R$ 615 | ≈ R$ 735 |
| Preço base da Double Spa | R$ 1.600 | ≈ R$ 655 | ≈ R$ 785 |

- **Os descontos do PriceLabs se somam aos da OTA.** Por exemplo, 20% de desconto de última hora do PriceLabs combinados com 52% da Booking deixam o hóspede pagando cerca de 38% do preço recomendado. O piso é o limite.
- **O comparativo com o mercado fica distorcido, mas não em dobro para todo mundo.** Os anúncios de temporada usados como comparação no PriceLabs já incluem as promoções deles. Contra eles, vocês aparecem com o dobro do preço real. O comparador de hotéis usa a tarifa pública da Booking, e o PriceLabs não informa se ela inclui Genius ou Mobile. Contra hotéis, a distância é menor que o dobro.
- **O valor base recomendado pelo PriceLabs vem do desempenho**, não dessa distorção. Ele considera ocupação, diária média, pickup, avaliações e comparação com o mercado. Para Double, Queen Spa (2) e Villa King Spa (7), ele sugere cerca de 13% abaixo do atual. Para a Villa King Spa (2), 26% abaixo, e para a Balcony, 40% abaixo.

---

## 4. Estratégia: duas fases

### Fase 1, recomendada já: ajustar dentro do PriceLabs, na escala atual

- **Não mexe no Beds24 nem nas OTAs.** Todos os valores continuam significando "preço enviado". O hóspede da Booking paga cerca de metade disso.
- **Cada mudança é pequena, datada e reversível.** O valor anterior está no arquivo de configuração salvo.
- **A maior parte eu faço pelo conector.** O que exige a tela está marcado na seção 8.

Três cuidados enquanto estiver nessa escala:

1. **Leia todo valor em reais pensando "o hóspede paga cerca de metade".** Isso vale para mínimos, base e ofertas.
2. **No gráfico de vizinhança, use o ajuste de −50% só para visualizar a sua posição real** contra os anúncios de temporada. O caminho é Neighborhood Data → engrenagem de Future Prices → "I Add a Markup". No comparador de hotéis, use no máximo o percentual das ofertas públicas da Booking, como a oferta básica. Genius e Mobile não entram.
3. **Ignore os valores absolutos do assistente de preço base.** Siga só a variação recomendada, como "baixe 13%".

### Fase 2, opcional: a "virada" para preço do hóspede

Os valores do PriceLabs são divididos por 2 e o Beds24 multiplica por 2 em cada canal. O benefício é que o PriceLabs, os gráficos e os pisos passam a falar em preço do hóspede, e cada canal pode ter um fator próprio.

O risco é operacional:

- **Quarto sem calendário:** a Villa King Spa (7) teria o preço dobrado, porque não recebe preço do PriceLabs.
- **Datas esgotadas:** o PriceLabs não envia preço para elas. Se houver cancelamento, a data reabre com o dobro do preço até a próxima sincronização.
- **Taxa de limpeza do Airbnb:** o multiplicador do Beds24 também a multiplica.
- **Canais esquecidos e cotações manuais:** o que não receber o multiplicador fica pela metade do preço.
- **Premissa não confirmada:** a documentação não confirma que o multiplicador do Beds24 se aplica ao preço que o PriceLabs escreve. É provável, mas precisa ser testado.

O procedimento seguro está no **anexo A**. Só vale a pena depois que a Fase 1 estiver rodando e as pendências de cadastro estiverem resolvidas.

**O "Ajuste de preços" (Pricing Offset) do PriceLabs não substitui o multiplicador do Beds24.** Ele vale igual para todos os canais ao mesmo tempo, inclusive a venda direta. Também não pode ser configurado pelo conector.

---

## 5. Configuração recomendada — Fase 1 (valores em "preço enviado")

### 5.1 Grupo "Recanto dos Moinhos" (Dynamic Pricing → Personalizações → Grupos)

| Configuração (nome em inglês) | Hoje | Recomendado | Efeito esperado |
|---|---|---|---|
| Fator de demanda (Demand Factor Sensitivity) | Aggressive | **Recommended** | Oscila menos: dia de semana fraco tende a subir, fim de semana forte tende a cair |
| Sazonalidade (Seasonality) | Aggressive | **Recommended** | Picos de temporada e evento menos extremos |
| Última hora (Last Minute Prices) | Market Driven Aggressive | **Depende das ofertas ativas na Booking.** Com oferta de última hora ativa: "No last minute adjustment". Sem essa oferta: % Gradual −10%, começando 7 dias antes | Evita somar dois descontos de última hora |
| Compset de hotéis e peso (Hotel Weights) | Personalizado, Mostly Hotel | igual | Já está adequado para hotel |
| Far-out (Far Out Prices) | Market Driven Balanced | igual | Não afeta os próximos 15 dias |
| Safety Minimum Price | conferir na tela | **"Do Not Apply"** | Ele usa a diária paga no ano anterior. Evita que vire um piso inesperado |

**Atenção ao desligar uma personalização.** Desligar não significa "sem ajuste": o algoritmo passa a usar o padrão de mercado. Para anular de fato, escolha "Nenhum" com a chave ligada.

### 5.2 Ajuste por ocupação por tipo de quarto (Multi-Room Occupancy-Based Adjustment)

**Aplicar só nos quartos com 6 ou 7 unidades:** Queen Spa (7) e Double Spa. A Villa King Spa (7) e a Balcony entram depois de resolvidas as pendências delas. A Afrodite (1 unidade) e os quartos de 2 unidades ficam de fora. Neles há poucos quartos-noite em cada faixa: uma única noite vendida mexe a ocupação da faixa de 0 a 6 dias em 7 a 14 pontos, e a tabela ficaria pulando de coluna.

**Como o PriceLabs mede a ocupação:** em cada linha, ele soma as noites vendidas de todas as unidades dentro daquela faixa de dias e divide pelas noites disponíveis. Por isso cada linha tem uma meta própria, a da tabela "O que as suas metas significam dia a dia", na seção 2.

A tabela abaixo substitui o ajuste automático que o PriceLabs já aplica hoje. Os valores são em % sobre o preço recomendado. O desconto nunca fura o preço mínimo. Os descontos são leves porque se somam aos mais de 50% da OTA.

| Faixa de dias (meta da faixa) | < 15% | 15–29% | 30–49% | 50–69% | 70–84% | 85–94% | ≥ 95% |
|---|---|---|---|---|---|---|---|
| 0 a 6 (≈ 70%) | −12% | −10% | −7% | −4% | **0%** | +6% | +12% |
| 7 a 14 (≈ 30%) | −8% | −4% | **0%** | +3% | +6% | +9% | +12% |
| 15 a 29 (≈ 20%) | −4% | **0%** | +3% | +5% | +8% | +10% | +12% |
| 30 a 60 (≈ 5%) | **0%** | +2% | +4% | +6% | +8% | +10% | +12% |

Cada linha fica neutra, em 0%, na faixa que contém a sua meta. As faixas de dias são crescentes. Os ajustes sobem conforme a ocupação sobe, como o PriceLabs exige.

**Pior caso:** chegada no mesmo dia, ocupação abaixo de 15% e última hora em −10%. O preço fica 0,88 × 0,90 ≈ 21% abaixo do recomendado. Com a OTA por cima, o hóspede paga entre 32% e 39% do preço recomendado, e cerca de 27% na Queen Spa (2). O preço mínimo segura o resto.

**Regra para não empilhar:** num quarto com a tabela ligada, não crie substituição de desconto. Uma substituição de −10% por cima levaria o pior caso a cerca de 29% abaixo do recomendado.

**Antes de ligar:** peça ao conector a comparação do preço de uma data com e sem a tabela (prompt 12 da seção 7). Depois de ligar, observe por uma semana antes de mexer em qualquer outra coisa.

### 5.3 Preço base, mínimo e máximo por tipo de quarto (Gerenciar propriedades)

Regra do "passo 1": percorrer 60% do caminho até o preço base recomendado pelo PriceLabs, só onde ele dá recomendação. O restante fica para daqui a 10 dias, se ainda indicado.

| Tipo de quarto | Base hoje → passo 1 | Hóspede no dia de semana ≈ | Mínimo | Máximo | Base recomendada pelo PriceLabs | Observação |
|---|---|---|---|---|---|---|
| Queen Spa (7) | 1.500 → **1.380** | R$ 565 | 800 (manter) | 5.000 (manter) | não informada | Corte de 8% por decisão própria: 30, 45 e 60 dias abaixo do mercado |
| Double Spa | 1.600 → **1.480** | R$ 605 | 900 (manter) | 5.000 (manter) | 1.399 (−13%) | Regra dos 60% |
| Afrodite (1) | 2.000 (manter) | R$ 820 | 1.500 (manter) | 10.000 (manter) | não informada | Bate todas as metas. Não mexer |
| Queen Spa (2) | 1.700 → **1.555** | R$ 530 (este quarto paga ≈ 0,34) | 850 (manter) | 5.000 (manter) | 1.457 (−14%) | Checar antes o desconto de 66% |
| Villa King Spa (7) | 1.600 → **1.480** | R$ 605 | 1.000 (manter) | 5.000 (manter) | 1.392 (−13%) | Só depois de confirmar que o PriceLabs precifica esse quarto |
| Villa Balcony (6) | 1.400 (manter) | R$ 575 | 800 (manter) | 5.000 (manter) | 840 (−40%) | Não é preço. Diagnosticar canal e anúncio primeiro |
| Villa King Spa (2) | 1.500 → **1.270** | R$ 520 | 900 (manter) | 5.000 (manter) | 1.114 (−26%) | Regra dos 60%. Checar antes o conflito de ocupação |

**Mínimo: manter todos.** O hóspede já paga cerca de R$ 330 a R$ 390 no piso, antes da comissão da Booking. O mínimo certo só pode ser definido por vocês. A conta é:

> custo variável por quarto ocupado + margem mínima ≤ mínimo × 0,41 × (1 − comissão da Booking)

O custo variável inclui lavanderia, amenities, café da manhã, energia e água. Com comissão de 15%, um mínimo de R$ 800 rende cerca de R$ 280 líquidos num dia de semana.

**Máximo: manter.** O teto atual não está limitando nenhum preço. O maior preço enviado da Queen Spa (7) é R$ 3.706 em 10/10, com 5 de 7 quartos vendidos, e o hóspede pagou entre R$ 950 e R$ 1.300 por noite. Um teto menor cortaria esses picos de feriado.

### 5.4 Fim de semana e estadia mínima

A regra de 2 noites no fim de semana fica **mantida** nos quartos que já vendem bem o fim de semana e em todos os feriados. Só muda onde o fim de semana está fraco.

| Ação | Onde | Como |
|---|---|---|
| Liberar 1 noite em sexta e sábado nos próximos fins de semana sem feriado | Queen Spa (7) e Queen Spa (2) | Pelo conector, substituição por data com estadia mínima 1 em 02–03/10, 16–17/10 e 23–24/10 |
| Desconto de fim de semana | Queen Spa (2) nas três datas. Queen Spa (7) só em 02–03/10, porque depois ela entra na tabela de ocupação | Substituição de −10% no mesmo registro da estadia mínima 1. Apagar pelo conector depois da data ou se vender. Sem regra permanente até medir 4 a 6 fins de semana |
| Garantir 2 noites nos feriados | Todos os quartos. Na Villa King Spa (7), conferir também no Beds24 | Substituição por data com estadia mínima 2 em 09–11/10 (Nossa Senhora Aparecida, segunda 12/10), 30/10–01/11 (Finados, segunda 02/11) e 20–21/11 (Consciência Negra, sexta 20/11; 19/11 opcional como véspera). Com expiração de 3 dias antes da data, para liberar 1 noite perto da chegada |
| Intervalo livre entre reservas | Todos | Na tela: "Escolha um número" = 1 |
| Estadia mínima do quarto no Beds24 | Todos | Conferir que está em 1 (Configurações → Propriedades → Quartos → Setup). Senão, ela trava o PriceLabs |

### 5.5 Sincronização

- **Sincronização programada grátis:** às 06:00, horário local, para pegar as reservas da madrugada. Fica em Account → Settings → Sync Settings → "Specify Your Own Time".
- **Sincronizações extras:** US$ 1 por listing por mês, cada uma. Sugestão: 12:00 e 18:00. É o jeito de o ajuste por ocupação reagir no mesmo dia.
- **Real-Time Sync não está disponível para Beds24.** Ele fica fora da lista de sistemas compatíveis do PriceLabs.

### 5.6 O que saiu das versões anteriores

- **Reduzir ou desligar promoções da Booking e do Airbnb:** fora de questão, porque os descontos são fixos.
- **Tarifa não reembolsável nova na Booking:** fora do escopo. Seria um desconto novo na OTA.
- **Real-Time Sync:** não existe para Beds24.
- **Baixar máximos e baixar mínimos:** saiu. A revisão mostrou que cortaria receita de feriado e tiraria piso da Afrodite.
- **Regra permanente de dia da semana:** trocada por substituições com data de validade.

---

## 6. Playbook de pacing: o que fazer quando a janela está abaixo da meta

Verificação diária de 5 minutos, pelo Claude com o conector ou pelo Multi Calendar.

| Situação | Ação |
|---|---|
| 7 dias abaixo de 70% e mercado de 7 dias abaixo de 30% | Deixar a tabela de ocupação agir. Nos quartos sem tabela, se passarem 2 dias sem reserva, substituição de −10% nas datas vazias. Apagar pelo conector se a data vender ou em 48 horas |
| 7 dias abaixo de 70% e mercado de 7 dias acima de 40% | Problema de visibilidade, não de preço. Checar posição na Booking, fotos e disponibilidade. Não baixar mais |
| Quarto no piso e ainda sem reserva | Mesmo diagnóstico da Balcony: canal, mapeamento e conteúdo. Não baixar o piso |
| Faixa de 7 a 14 dias abaixo de 30% | Conferir estadia mínima e ofertas. Checar se há fim de semana mais de 60% acima da semana |
| Faixa de 15 a 29 dias abaixo de 20% | Manter. Revisar o preço base se a relação recomendada/base ficar abaixo de 0,93 por duas semanas |
| Faixas de 30 a 60 dias | Já no alvo da faixa (9% e 7%). Não descontar: metade do público reserva com até 7 dias. A janela de 45 dias sobe quando as faixas mais próximas melhorarem |
| 7 dias acima de 85% | A tabela já sobe de 6% a 12%. Conferir se o máximo não trava |
| Cancelamento grande a menos de 3 dias | Nos quartos sem tabela, substituição de −10% na data. Apagar pelo conector depois de 48 horas ou se vender |

**Trava de receita:** acompanhar a receita por quarto disponível (RevPAR) toda semana. Se a ocupação subir e esse valor cair por 2 semanas seguidas, reduza em 3 pontos os descontos da linha de 0 a 7 dias.

---

## 7. Rotina com o Claude e o conector do PriceLabs

Todos os valores estão em "preço enviado", a escala atual.

1. "Mostre a ocupação dos próximos 7, 15, 30, 45 e 60 dias de cada tipo de quarto e compare com as metas 70/50/35/25/15."
2. "Mostre a ocupação por faixa de dias (0–7, 8–15, 16–30, 31–60) de cada tipo de quarto."
3. "Quais tipos de quarto estão abaixo de 70% nos próximos 7 dias? Mostre preço e estadia mínima de cada data."
4. "Mude Fator de Demanda e Sazonalidade do grupo para Recommended, mantendo compset e peso de hotéis."
5. "Configure a Última hora do grupo como % Gradual, −10%, começando 7 dias antes." Ou: "Configure a Última hora do grupo como 'No last minute adjustment'."
6. "Atualize o preço base da Double Spa para 1.480."
7. "Leia as substituições existentes e crie, em 02 e 03/10, na Queen Spa 7 unidades e na Queen Spa 2 unidades, um único registro por data com estadia mínima 1 e −10%, motivo 'fim de semana fraco'."
8. "Crie substituição de estadia mínima 2 em 09, 10 e 11/10 em todos os quartos, expirando 3 dias antes da data."
9. "Apague as substituições de 02 e 03/10 da Queen Spa 2 unidades."
10. "Quais reservas foram feitas nas últimas 24 horas e por qual canal?"
11. "Compare o valor pago nas reservas da última semana com o preço enviado pelo PriceLabs, por canal."
12. "Recalcule os preços da Queen Spa 7 unidades e mostre o motivo de cada preço nos próximos 7 dias."
13. "Diagnostique por que a King Suite with Balcony não recebe reservas."
14. "Qual o pickup dos últimos 7 dias por tipo de quarto?"
15. "Gere um relatório de revisão da conta dos últimos 30 dias com ações recomendadas."

**O que o conector faz:**
- preço base, mínimo e máximo;
- ligar e desligar a sincronização de cada listing;
- sazonalidade, última hora, far-out, dia da semana, fator de demanda e perfil sazonal;
- substituições por data, incluindo preço, piso, teto e estadia mínima.

**Cuidados com substituições por data:** cada data tem um único registro por quarto. Apagar remove o registro inteiro, inclusive estadia mínima e preço. Por isso: leia antes de escrever, mande preço e estadia mínima juntos, e para desfazer só um campo, reenvie a data com os campos que devem ficar. Na primeira substituição de −10%, confira com o prompt 12 se o desconto saiu sobre o preço recomendado.

**O que só dá para fazer na tela:**
- a tabela de ajuste por ocupação;
- as regras gerais de estadia mínima;
- os mínimos avançados e o Safety Minimum Price;
- a sincronização programada e o Ajuste de preços;
- tudo no Beds24.

---

## 8. Sequência de implantação (uma alavanca por vez)

| Quando | Ação | Quem | Como desfazer |
|---|---|---|---|
| Hoje | Pendências de cadastro: Villa King Spa (7) sem calendário, Double com 6 ou 7 unidades, Queen Spa (2) com 66%, conflito na Villa King Spa (2), Balcony no Beds24 e na Booking | Você, com o Claude | — |
| Hoje | Listar as ofertas ativas na extranet da Booking: nível Genius, Mobile, tarifa por país, oferta básica, de última hora, antecipada, campanhas e Genius dinâmico | Você | — |
| Hoje | Conferir no Beds24: mínimo e estadia mínima dos quartos, multiplicadores existentes e a lista de canais | Você | — |
| Dia 1 | Fator de Demanda e Sazonalidade → Recommended | Claude | Voltar para Aggressive |
| Dia 1 | Estadia mínima 2 nos feriados, por substituição por data | Claude | Apagar as substituições dos feriados |
| Dia 2 | Última hora conforme as ofertas da Booking | Claude | Voltar para Market Driven Aggressive |
| Dia 2 | Safety Minimum Price → "Do Not Apply". Sincronização às 06:00 | Você, na tela | Anotar o valor anterior |
| Dia 3 | Fim de semana das Queen Spa: 1 noite em 02–03, 16–17 e 23–24/10. −10% nas mesmas datas na Queen Spa (2) e só em 02–03/10 na Queen Spa (7) | Claude | Apagar as substituições pelo conector |
| Dia 4 | Passo 1 do preço base: Double, Queen Spa (2), Villa King Spa (2) e Queen Spa (7). A Villa King Spa (7) só se o calendário estiver resolvido | Claude | Valores no arquivo de configuração salvo |
| Dia 7 | Ligar a tabela de ocupação na Queen Spa (7) e na Double | Você, na tela | Desligar |
| Dia 10 | Revisão: pickup, RevPAR e desconto medido por canal. Passo 2 do preço base se ainda indicado | Claude | — |
| Dia 14 | Revisão completa contra as metas. Decidir sobre a Fase 2 | Você e o Claude | — |

---

## 9. Riscos e armadilhas

- **Somar descontos.** Oferta de última hora da Booking mais última hora do PriceLabs é desconto em dobro. O mesmo vale para a tabela de ocupação com descontos fortes.
- **Lotar a 100% com receita menor.** A trava é o RevPAR semanal e o mínimo calculado sobre o custo variável.
- **Ensinar o hóspede a esperar.** A tabela só desconta quando o quarto está vazio, não sempre na última hora.
- **Substituição com preço fixo fura o mínimo.** Use sempre percentual.
- **Apagar uma substituição apaga tudo naquela data**, inclusive a estadia mínima. Leia antes de apagar.
- **Bloqueios no Beds24** não contam como ocupados no cálculo do PriceLabs. Mesmo assim, tiram quartos da venda.
- **Desligar uma personalização** faz o padrão de mercado assumir. Para anular, use "Nenhum" com a chave ligada.
- **Decidir com uma amostra pequena.** O fim de semana foi medido em uma só semana. O desconto do Airbnb não tem dado confiável.

---

## Anexo A — Procedimento seguro para a "virada" (Fase 2, opcional)

**Condições para começar. Todas precisam estar cumpridas:**

1. Todos os quartos recebem preço do PriceLabs. A Villa King Spa (7) está resolvida.
2. Em Beds24 → PRICES → CHANNEL MAPPING, cada canal usa apenas o Daily Price 1. Todos os canais estão listados, inclusive os que caem em "outros".
3. O mínimo e a estadia mínima dos quartos no Beds24 estão em 0 e 1.
4. Você anotou os multiplicadores atuais de cada canal e da página de reserva direta.
5. A recepção sabe que, depois da virada, o calendário do Beds24 mostra o preço do hóspede. Cotações e reservas manuais passam a usar esse valor multiplicado pelo fator.

**Antes de tudo:** gerar um snapshot novo, com o conector e com anotações da tela. O arquivo de 25/09 desfaz a Fase 1, não a virada.

**Piloto em um quarto:** a Balcony, por ser a de menor risco. Ela tem só 5 quartos-noite vendidos. As reservas já feitas não mudam de preço.

1. Fechar a Balcony para venda em todos os canais, inclusive a página direta. O multiplicador da página direta vale para a propriedade inteira, não para um quarto.
2. Com o quarto fechado: dividir por 2 o base, o mínimo e o máximo dela no PriceLabs e clicar em "Sync Now".
3. Conferir no calendário do Beds24 que o Daily Price 1 dela caiu pela metade em 3 datas.
4. Colocar o multiplicador `*2` no mapeamento desse quarto na Booking. Se já existir um multiplicador, usar o existente × 2. Clicar em "Update".
5. Conferir na extranet da Booking que a tarifa, antes das promoções, voltou ao valor anterior.
6. Reabrir a Balcony só na Booking durante o piloto. Os outros canais continuam fechados para ela.
7. Se não bater, voltar o multiplicador e os valores anotados antes de reabrir.

**Depois de 3 a 5 dias de piloto sem problemas, estender aos outros quartos na mesma ordem:**

1. Dividir no PriceLabs todos os valores em reais: base, mínimo, máximo, mínimos avançados, perfis sazonais e substituições com preço fixo.
2. Sync Now.
3. Conferir no Beds24, inclusive nas datas esgotadas. Nelas o PriceLabs não envia preço, então escreva à mão o valor pela metade.
4. Multiplicadores em todos os canais e na página direta, com Update.
5. Airbnb: definir a taxa de limpeza como valor CUSTOM em Channel Manager → Airbnb → Specific Content e conferir no Airbnb que ficou igual à atual.
6. Conferir uma data por canal.

Reserve de 1 a 2 horas, não 15 minutos.

**Como desfazer:** restaurar os multiplicadores anotados e os valores do snapshot feito imediatamente antes da virada, depois Sync Now.

---

## Fontes

**Dados da conta:** conector MCP do PriceLabs, 25/09/2026. Ferramentas: get_listings, get_listing_data, get_listing_performance_metrics, get_listing_prices, get_customizations, get_customization_schema, get_neighbourhood_data, get_pms_reservations, get_listing_date_overrides e get_listing_rate_plans.

**PriceLabs**
- https://help.pricelabs.co/portal/en/kb/articles/how-does-pricelabs-manage-fees-from-multiple-channels
- https://help.pricelabs.co/portal/en/kb/articles/pricing-offsets-for-mapped-listings
- https://help.pricelabs.co/portal/pt/kb/articles/article-11-2-2024
- https://hello.pricelabs.co/blog/pricing-offset-a-complete-guide/
- https://help.pricelabs.co/portal/en/kb/articles/pms
- https://help.pricelabs.co/portal/en/kb/articles/how-to-integrate-pricelabs-with-beds24
- https://help.pricelabs.co/portal/en/kb/articles/listing-market-data
- https://help.pricelabs.co/portal/en/kb/articles/listing-hotel-data
- https://hotels.pricelabs.co/hotel-rate-shopper/
- https://help.pricelabs.co/portal/en/kb/articles/customizations-which-allow-below-minimum-prices
- https://help.pricelabs.co/portal/en/kb/articles/occupancy-based-adjustments
- https://help.pricelabs.co/portal/en/kb/articles/portfolio-occupancy-based-adjustments
- https://help.pricelabs.co/portal/en/kb/articles/last-minute-prices
- https://help.pricelabs.co/portal/en/kb/articles/demand-factor-sensitivity
- https://help.pricelabs.co/portal/en/kb/articles/setting-base-price
- https://help.pricelabs.co/portal/en/kb/articles/customization-hierarchy
- https://help.pricelabs.co/portal/en/kb/articles/date-specific-overrides
- https://help.pricelabs.co/portal/en/kb/articles/safety-minimum-price-15-10-2024
- https://help.pricelabs.co/portal/en/kb/articles/timed-sync
- https://help.pricelabs.co/portal/en/kb/articles/real-time-sync
- https://help.pricelabs.co/portal/en/kb/articles/performance-metrics
- https://help.pricelabs.co/portal/pt/kb/articles/list-of-all-pricelabs-customizations-4-6-2024
- https://help.pricelabs.co/portal/pt/kb/articles/setting-dynamic-minimum-stay-restrictions-in-pricelabs
- https://hotels.pricelabs.co/blog/guide-to-hotel-dynamic-pricing/
- https://developers.pricelabs.co/customer-api/api-reference/customer-api/customizations/update-capi-listing-customizations.md
- https://developers.pricelabs.co/mcp/overview

**Beds24**
- https://wiki.beds24.com/index.php/Booking.com:_Synchronise_bookings_prices_availability
- https://wiki.beds24.com/index.php/Airbnb_Mapping
- https://wiki.beds24.com/index.php/Expedia_Mapping
- https://wiki.beds24.com/index.php/Setting_Prices_for_Booking_Channels
- https://wiki.beds24.com/index.php/Category:Discounts
- https://wiki.beds24.com/index.php/Pricelabs.co

**Booking.com e Airbnb**
- https://partner.booking.com/sites/default/files/article_attachments/how-deals-and-rates-stack-together__1_.pdf
- https://partner.booking.com/en-us/help/growing-your-business/increase-revenue/becoming-genius-partner
- https://partner.booking.com/en-us/help/rates-availability/rates-special-offers/setting-mobile-rates
- https://partner.booking.com/en-us/help/rates-availability/rates-special-offers/setting-country-rates
- https://partner.booking.com/en-us/help/rates-availability/rates-special-offers/setting-deals-and-discounts
- https://www.airbnb.com/help/article/3421
- https://www.airbnb.com/resources/hosting-homes/a/combining-discounts-and-rule-sets-694

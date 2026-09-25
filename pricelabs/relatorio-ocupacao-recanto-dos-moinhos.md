# Relatório PriceLabs — Pousada Recanto dos Moinhos e Villa Dolce Amore

**Versão 2 · 25/09/2026** · revisada com a premissa de que **os descontos das OTAs são fixos e não podem ser alterados**.

**Fontes:** conta PriceLabs via conector MCP. 600 reservas do Beds24 feitas nos últimos 60 dias. 290 reservas com estadia nos próximos 60 dias, cruzadas com o calendário de preços do PriceLabs. Documentação oficial do PriceLabs, do Beds24, da Booking.com e do Airbnb (lista no fim).

**Meta de lotação antes de cada data:** 7 dias 70% · 15 dias 50% · 30 dias 35% · 45 dias 25% · 60 dias 15%.

---

## 1. Resumo executivo

1. **Hoje o portfólio está em 39% de ocupação nos próximos 7 dias**, contra a meta de 70%. Só a janela de 60 dias bate a meta. Vocês estão acima do mercado (28% em 7 dias), então o problema não é falta de demanda na cidade. É conversão nas datas certas.
2. **O desconto real das OTAs foi medido: o hóspede da Booking.com paga cerca de metade do preço que o PriceLabs envia.** A mediana é 48% do preço enviado, ou seja, 52% de desconto efetivo. Nos dias de semana chega a 59%. Esse desconto é fixo, então toda a configuração precisa ser pensada a partir dele.
3. **O preço mínimo de R$ 800 no PriceLabs vira cerca de R$ 400 para o hóspede.** Os pisos atuais não são os pisos reais. Qualquer desconto do PriceLabs se soma aos 50% da OTA.
4. **A King Suite with Balcony (6 unidades) tem zero reservas nos próximos 7 dias**, com o hóspede vendo cerca de R$ 400 por noite no meio da semana. Preço não é o problema dessa categoria. É canal, mapeamento ou anúncio. Precisa de verificação imediata no Beds24 e na Booking.
5. **Fim de semana é o maior vazamento em 4 dos 7 tipos de quarto.** O PriceLabs sobe sexta e sábado para R$ 1.925 a 2.761 e o algoritmo exige 2 noites, em um negócio em que 74% das estadias são de 1 noite. A Queen Spa de 7 unidades está com 14% de ocupação no fim de semana, contra 48% do mercado.
6. **Tom "Agressivo" em Sazonalidade, Fator de Demanda e Última hora** no grupo faz o algoritmo segurar preço esperando o hóspede tardio e ainda empilha desconto em cima da OTA. Para uma meta de ocupação, o tom certo é "Recomendado", com desconto de última hora leve.
7. **Recomendação principal: fazer o PriceLabs trabalhar com o preço que o hóspede paga.** Os valores do PriceLabs são divididos por 2 e o Beds24 multiplica por 2 antes de enviar para cada OTA. No primeiro dia nada muda para o hóspede. A partir daí, preço base, mínimo, máximo, gráficos de mercado e comparação com hotéis passam a falar a mesma língua do hóspede. Os descontos das OTAs continuam intactos.
8. **O Ajuste por Ocupação por tipo de quarto** (Multi-Room Occupancy-Based Adjustment) não está ativo. É a ferramenta desenhada para metas de lotação por janela. A tabela proposta aqui foi recalibrada para não empilhar desconto além do que a OTA já dá.
9. **Os cancelamentos são altos:** 149 de 290 reservas com estadia nos próximos 60 dias estão canceladas, quase todas na Booking. Parte parece ruído de sincronização, como duplicatas criadas em 02/09 na Villa King Spa, mas o volume afeta o pacing.
10. **Há quatro anomalias de cadastro para checar antes de qualquer mudança:**
    - A Villa King Spa de 7 unidades não devolve calendário de preços pela API.
    - A Double Spa aparece com 6 unidades no PriceLabs, mas o nome diz 7.
    - A Queen Spa de 2 unidades tem desconto efetivo de 67%, muito acima das outras.
    - Os valores de reserva do Airbnb vêm inconsistentes.

---

## 2. Situação atual contra as metas de pacing

Ocupação hoje por janela (quartos-noite vendidos ÷ disponíveis). 🟢 bate a meta, 🔴 abaixo.

| Tipo de quarto (unid.) | 7d (meta 70) | 15d (meta 50) | 30d (meta 35) | 45d (meta 25) | 60d (meta 15) | Fds 7d | Semana 7d |
|---|---|---|---|---|---|---|---|
| Moinhos · Queen Spa (7) | 🔴 43% | 🔴 23% | 🔴 17% | 🔴 12% | 🔴 10% | 14% | 54% |
| Moinhos · Double Spa (6 no PriceLabs) | 🔴 43% | 🔴 31% | 🔴 29% | 🔴 23% | 🟢 21% | 75% | 30% |
| Moinhos · Queen Spa Afrodite (1) | 🟢 71% | 🟢 60% | 🟢 53% | 🟢 42% | 🟢 35% | 100% | 60% |
| Moinhos · Queen Spa (2) | 🔴 50% | 🔴 27% | 🔴 27% | 🟢 29% | 🟢 28% | 25% | 60% |
| Villa · King Spa (7) | 🔴 59% | 🔴 38% | 🔴 32% | 🔴 23% | 🟢 18% | 71% | 57% |
| Villa · King Balcony (6) | 🔴 0% | 🔴 2% | 🔴 3% | 🔴 2% | 🔴 1% | 0% | 0% |
| Villa · King Spa (2) | 🔴 36% | 🔴 27% | 🔴 22% | 🔴 23% | 🟢 18% | 50% | 30% |
| **Portfólio** | 🔴 **39%** | 🔴 **26%** | 🔴 **22%** | 🔴 **18%** | 🟢 **15%** | | |
| **Mercado (350 similares, 15 km)** | 28% | 22% | 19% | 15% | 14% | 48% | 19% |

Leitura: vocês vencem o mercado na semana e perdem no fim de semana. O funil de longo prazo existe, e 5 tipos batem a meta de 60 dias. O buraco está em 7 e 15 dias, que é justamente onde se somam os descontos do algoritmo e da OTA sem gerar a conversão esperada.

### Como o hóspede reserva (600 reservas feitas entre 27/07 e 25/09)

| Indicador | Valor |
|---|---|
| Reservas feitas no dia ou na véspera da chegada | 29% |
| Reservas feitas até 7 dias antes | 50% |
| Reservas feitas com 60 dias ou mais | 20% |
| Estadias de 1 noite | 74% (média de 1,33 noite) |
| Canal | Booking.com 70% · Airbnb 19% · direto/outros 10% · Expedia <1% |
| Valor pago por noite, mediana | R$ 850 |
| Valor pago por noite, por dia de chegada | Sex 1.693 · Sáb 1.123 · Qua 991 · Ter 883 · Qui 846 · Seg 844 · Dom 752 |

---

## 3. O desconto real das OTAs, medido

Método: para cada reserva ativa com estadia nos próximos 60 dias, dividi o valor pago por noite pelo preço atual que o PriceLabs envia para aquelas noites. Reservas feitas nos últimos 3 dias são as mais confiáveis, porque o preço quase não mudou desde a reserva.

| Canal e janela | Reservas | Pago ÷ enviado (mediana) | Desconto efetivo |
|---|---|---|---|
| Booking.com, reservas dos últimos 3 dias | 14 | 0,48 | **52%** |
| Booking.com, reservas dos últimos 10 dias | 36 | 0,44 | 56% |
| Booking.com, estadias só em dias de semana (10 dias) | 26 | 0,41 | 59% |
| Booking.com, estadias com sexta ou sábado (10 dias) | 10 | 0,49 | 51% |
| Direto/outros | 3 | 0,48 | 52% (amostra pequena) |
| Airbnb | 3 | inconsistente | valores do Beds24 não fecham |

| Tipo de quarto (Booking, 10 dias) | Enviado (mediana) | Pago por noite (mediana) | Pago ÷ enviado |
|---|---|---|---|
| Queen Spa (7) | R$ 1.481 | R$ 555 | 0,45 |
| Double Spa | R$ 1.733 | R$ 863 | 0,49 |
| Queen Spa (2) | R$ 1.333 | R$ 455 | **0,34** |
| Villa King Balcony | R$ 1.440 | R$ 674 | 0,47 (2 reservas) |
| Villa King Spa (2) | R$ 1.045 | R$ 540 | 0,51 |

O que isso significa:

- **O desconto efetivo da Booking está entre 50% e 55%**, no teto do que você informou. Isso bate com as regras da Booking. Um hóspede pode acumular até três descontos: Genius, mais Mobile ou tarifa por país, mais uma oferta, como a de última hora ou a básica. Eles se aplicam em cascata. Por exemplo, 20% de Genius, 15% de Mobile e 30% de oferta dão cerca de 52%.
- **Nos dias de semana o desconto é maior (59%).** É exatamente onde o PriceLabs já está no piso. O hóspede está pagando perto de R$ 400.
- **A Queen Spa de 2 unidades perde 66% entre o preço enviado e o pago.** Vale checar se há um multiplicador menor que 1 no Beds24 ou uma oferta extra na Booking só para esse quarto.
- **O PriceLabs não sabe nada disso.** A documentação dele é explícita: taxas e descontos de cada canal são aplicados depois que o preço sai do PriceLabs.

### Consequências práticas hoje

| Configuração no PriceLabs | Valor configurado | O que o hóspede da Booking vê (≈ 50%) |
|---|---|---|
| Preço mínimo Queen Spa (7) | R$ 800 | ≈ R$ 400 |
| Preço mínimo Villa Balcony | R$ 800 | ≈ R$ 400 |
| Preço mínimo Afrodite | R$ 1.500 | ≈ R$ 750 |
| Preço base Double Spa | R$ 1.600 | ≈ R$ 800 |
| Sexta Queen Spa (7), 02/10 | R$ 2.250 | ≈ R$ 1.125, com mínimo de 2 noites |

- **Os gráficos de vizinhança e o comparador de hotéis mostram vocês com o dobro do preço real.** A documentação do PriceLabs diz que os preços dos concorrentes já incluem as promoções deles. O comparador de hotéis usa a tarifa pública da Booking. O seu preço aparece antes do desconto.
- **Os descontos do PriceLabs se somam à OTA.** Hoje a última hora está em "Market Driven Agressivo". Um desconto de 20% do PriceLabs mais 52% da Booking entrega 62% abaixo do preço base, até bater no piso.
- **Uma correção em relação à versão 1:** trocar a escala dos valores não altera, sozinha, a relação entre preço recomendado e preço base, nem os dias no piso. O algoritmo aplica multiplicadores sobre o preço base. A recomendação de baixar o base de 13% a 40% é baseada em desempenho e continua válida por si só. A seção 5 trata disso.

---

## 4. Como compensar o desconto fixo sem mexer nas OTAs

### Recomendado: PriceLabs em "preço do hóspede" com multiplicador por canal no Beds24

Como funciona:

1. **No PriceLabs, divida preço base, mínimo e máximo por 2.** Faça o mesmo com todo valor fixo em reais: mínimos de fim de semana, de datas distantes e de última hora, perfis sazonais e substituições por data com preço fixo. A partir daí, o número do PriceLabs significa "o que o hóspede paga".
2. **No Beds24, coloque multiplicador `*2` nos canais**, em Configurações → Channel Manager → Booking.com → Mapeamento → campo "Multiplier". Faça o mesmo no Airbnb, no Expedia e na página de reservas direta (Booking Engine → Booking Page → "Booking Page Price Multiplier"). O Beds24 multiplica o preço que o PriceLabs escreve antes de mandar para cada canal.
3. **No dia da virada, o preço enviado para cada OTA é exatamente o mesmo de hoje:** metade vezes 2. O hóspede não percebe nada. A diferença é que o PriceLabs passa a enxergar a realidade.
4. **Depois, cada canal pode ser ajustado separadamente.** Exemplos: Airbnb `*1,6` se o desconto real lá for menor, ou a página direta com um multiplicador próprio. Isso não é possível com um ajuste único dentro do PriceLabs.

Por que o Beds24 e não o "Ajuste de preços" (Pricing Offset) do PriceLabs:

- **O ajuste do PriceLabs vale para todos os canais ao mesmo tempo.** Ele entra depois de todas as regras e pode passar do máximo, com limite de +500%. Mas, com Beds24, o preço ajustado vai igual para Booking, Airbnb e site direto.
- **O multiplicador do Beds24 é por canal.** O próprio PriceLabs recomenda que, quando há um PMS no meio, os acréscimos por canal fiquem no PMS.
- **Nunca use os dois juntos.** O acréscimo seria aplicado duas vezes.
- **Nenhum dos dois pode ser configurado pelo conector do Claude.** A virada exige 15 minutos na tela do Beds24. A divisão dos valores do PriceLabs eu faço pelo conector.

O que o conector faz e o que precisa ser feito na mão:

| Passo | Pelo conector (Claude) | Na tela |
|---|---|---|
| Dividir base, mínimo e máximo por 2 | ✅ | |
| Converter substituições por data de preço fixo | ✅ | |
| Converter mínimos de fim de semana, datas distantes, última hora e sazonal | | ✅ PriceLabs |
| Multiplicador por canal | | ✅ Beds24 |
| Preço mínimo do quarto no Beds24 = 0 | | ✅ Beds24 |
| Safety Minimum Price | | ✅ PriceLabs |

### Checklist obrigatório antes da virada

1. **Beds24 → Configurações → Propriedades → Quartos → Setup: "Minimum Price" = 0 e "Minimum Stay" = 1.** O PriceLabs não consegue passar abaixo desses valores. Se o Beds24 tiver R$ 800 como mínimo e o PriceLabs escrever R$ 400, o preço trava.
2. **Ver se algum canal já tem multiplicador.** Se a Booking já tiver `*1,15`, o novo passa a ser `*2,30`.
3. **Ver o multiplicador da página direta**, para saber quanto o hóspede direto paga hoje. A amostra sugere que ele também paga cerca de metade.
4. **Listar as promoções ativas na extranet da Booking:** nível Genius, Mobile, tarifa por país, ofertas básica, de última hora e antecipada, campanhas e Genius dinâmico. Se houver oferta de última hora da Booking, a última hora do PriceLabs precisa ser ainda mais leve.
5. **Airbnb:** ver se os descontos de última hora e antecipado estão configurados no próprio Beds24 (Channel Manager → Airbnb → Specific Content). Lembre que o multiplicador do Airbnb também multiplica a taxa de limpeza, se houver.
6. **Safety Minimum Price:** ele usa a diária paga no ano anterior, que já está em "preço do hóspede". Depois da virada, ele pode virar um piso acima do novo mínimo e bloquear vendas. Deixe em "Do Not Apply" ou em no máximo 100%.
7. **Horário da virada:** fazer tudo em sequência, no mesmo bloco de 15 minutos, em horário de pouca reserva, entre 1h e 5h. No Beds24, clicar em "Update" em cada canal para enviar na hora.
8. **Conferir uma data por canal na extranet.** O valor diário 1 do Beds24 multiplicado pelo fator tem que ser igual à tarifa mostrada na Booking antes das promoções.

### Alternativa sem mexer no Beds24: continuar no "preço de vitrine"

Se preferir não mexer no Beds24, a estratégia inteira da seção 5 continua valendo. Basta multiplicar por 2 todos os valores em reais. E adotar três cuidados:

- **Marcar −50% nos gráficos**, em Neighborhood Data → engrenagem do Future Prices → "I Add a Markup" e no Hotel Rate Shopper → Markup/Markdown, só para visualizar a posição real frente ao mercado.
- **Ignorar os valores absolutos do assistente de preço base** e seguir apenas a relação recomendada (por exemplo, "baixe 13%").
- **Lembrar sempre que todo piso configurado vale metade para o hóspede.**

---

## 5. Configuração recomendada

Valores já em **"preço do hóspede"**, ou seja, depois da virada. Na alternativa sem Beds24, multiplique cada valor em reais por 2.

### 5.1 Nível grupo (Dynamic Pricing → Personalizações → Grupos → Recanto dos Moinhos)

| Configuração (nome em inglês) | Hoje | **Recomendado** | Por quê |
|---|---|---|---|
| Fator de demanda (Demand Factor Sensitivity) | Aggressive | **Recommended** | O agressivo segura preço esperando o hóspede tardio |
| Sazonalidade (Seasonality) | Aggressive | **Recommended** | Reduz picos de fim de semana e datas de evento |
| Última hora (Last Minute Prices) | Market Driven Aggressive | **% Gradual, −10%, começando 7 dias antes** | A OTA já dá cerca de 50% perto da data. O "Market Driven" não sabe disso |
| Compset de hotéis e peso (Hotel Weights) | Personalizado · Mostly Hotel | igual | Já está certo para hotel |
| Far-out (Far Out Prices) | Market Driven Balanced | igual | Não afeta a janela de 7 dias |
| Multi-Room Occupancy-Based Adjustment | desligado | **ligar com a tabela abaixo** | É a ferramenta de pacing por janela |
| Dias órfãos (Orphan Day Prices) | padrão −20% | **"Sem ajuste"** | Em hotel com várias unidades não há "buraco" a preencher |
| Safety Minimum Price | verificar | **"Do Not Apply"** até 60 dias depois da virada | Ver o item 6 do checklist |
| Arredondamento (Rounding) | — | terminar em 9 | Estética de preço |

**Tabela do Multi-Room Occupancy-Based Adjustment.** Aplicar no grupo, em "All Days". Os valores são em % sobre o preço recomendado. Os descontos foram reduzidos em relação à versão 1, porque se somam aos cerca de 50% da OTA. Nunca furam o preço mínimo.

| Dias até a data (meta) | Ocup. < 25% | 25–49% | 50–69% | 70–84% | 85–94% | ≥ 95% |
|---|---|---|---|---|---|---|
| 0 a 3 (meta 70%) | −15% | −10% | −5% | 0% | +8% | +15% |
| 4 a 7 (meta 70%) | −12% | −8% | −4% | 0% | +6% | +12% |
| 8 a 15 (meta 50%) | −10% | −5% | 0% | +5% | +8% | +12% |
| 16 a 30 (meta 35%) | −6% | 0% | +4% | +7% | +10% | +12% |
| 31 a 60 (meta 25% e 15%) | −2% | +3% | +5% | +8% | +10% | +12% |

Cada linha fica neutra, com 0%, na faixa que contém a meta daquela janela. Abaixo da meta, o preço cai. Acima, sobe. Com 7 unidades, cada quarto vendido vale cerca de 14 pontos de ocupação, então a tabela reage a cada reserva.

Pior caso de desconto do PriceLabs: data a 2 dias, com menos de 25% de ocupação. Última hora −10% e ocupação −15% resultam em cerca de 24% abaixo do recomendado. Somado ao desconto da OTA, o hóspede paga cerca de 36% do preço base configurado antes da virada. Por isso o preço mínimo é a trava principal.

### 5.2 Nível tipo de quarto (Gerenciar propriedades / Revisar preços)

Coluna "Virada": só a divisão por 2, sem mudança real de preço para o hóspede. Coluna "Passo 1": o ajuste estratégico. Ele segue a recomendação de preço base do próprio PriceLabs, percorrendo 60% do caminho agora e o restante em 10 dias, se ainda indicado.

| Tipo de quarto | Base hoje → virada → **passo 1** | Mínimo hoje → virada → **passo 1** | Máximo virada → **passo 1** | Relação recomendada / base | Justificativa |
|---|---|---|---|---|---|
| Queen Spa (7) | 1.500 → 750 → **690** | 800 → 400 → **400** | 2.500 → **1.750** | sem dado | 17% dos dias no piso. Fim de semana a 14% |
| Double Spa | 1.600 → 800 → **740** | 900 → 450 → **450** | 2.500 → **1.750** | 0,87 | Recomendado 1.399 antes da virada, ou 700 depois |
| Afrodite (1) | 2.000 → 1.000 → **1.000** | 1.500 → 750 → **700** | 5.000 → **3.000** | sem dado | Já bate todas as metas |
| Queen Spa (2) | 1.700 → 850 → **780** | 850 → 425 → **425** | 2.500 → **1.750** | 0,86 | Desconto efetivo de 66%: checar antes |
| Villa King Spa (7) | 1.600 → 800 → **740** | 1.000 → 500 → **475** | 2.500 → **1.750** | 0,87 | Recomendado 1.392 antes da virada, ou 696 depois |
| Villa Balcony (6) | 1.400 → 700 → **600** | 800 → 400 → **400** | 2.500 → **1.500** | 0,60 | O problema não é preço. Corte moderado até diagnosticar o canal |
| Villa King Spa (2) | 1.500 → 750 → **630** | 900 → 450 → **425** | 2.500 → **1.750** | 0,74 | Recomendado 1.114 antes da virada, ou 557 depois |

**Sobre o preço mínimo.** Os mínimos de "passo 1" mantêm o piso real atual do hóspede, que fica entre R$ 400 e R$ 475, e R$ 700 na Afrodite. Não recomendo baixar mais. Com a OTA levando cerca de 50%, o hóspede já paga perto de R$ 400 nos dias de semana. O próximo passo, que só você pode dar, é calcular o **custo variável por quarto ocupado** (lavanderia, amenities, café da manhã, energia, comissão da OTA sobre o valor pago) mais a margem mínima. Esse número é o mínimo correto.

**Sobre o máximo.** Um teto de R$ 1.750 para o hóspede, ou R$ 3.500 antes da virada, evita picos irreais em feriado que espantam o hóspede de última hora. Ajuste para cima em datas de evento, com substituição por data.

**Mínimos avançados** (em preço do hóspede):

| Configuração | Recomendado |
|---|---|
| Preço mínimo de fim de semana (Minimum Weekend Price) | mínimo + 15% |
| Preço mínimo para datas distantes (Minimum Far-out Price) | igual ao preço base, para 60 dias ou mais |
| Preço mínimo de última hora (Minimum Last Minute Price) | não definir. O piso real já é baixo |

### 5.3 Dia da semana (só onde o fim de semana está fraco)

| Tipo de quarto | Sexta | Sábado | Motivo |
|---|---|---|---|
| Queen Spa (7) | −10% | −10% | Fim de semana com 14% contra 48% do mercado |
| Queen Spa (2) | −10% | −10% | Fim de semana com 25% |
| Demais | 0 | 0 | Double, Afrodite e Villa King Spa (7) já vendem fim de semana |

Aplicar no nível da listing, não do grupo. O conector faz isso.

### 5.4 Estadia mínima (Personalizações → Restrições de estadia)

| Regra | Hoje (observado) | Recomendado |
|---|---|---|
| Estadia mínima padrão (Default Min Stay) | 2 noites no fim de semana | 1 noite todos os dias |
| Última hora (Last-Minute Bookings) | — | Dentro de 14 dias: 1 noite, inclusive sexta e sábado |
| Reserva distante (Far-Out Bookings) | — | Com 30 dias ou mais: 2 noites em sexta e sábado só em feriados, via perfil sazonal |
| Intervalos livres (Orphan Gap) | — | "Escolha um número" = 1 |
| Menor estadia permitida | — | 1 |
| Beds24: Minimum Stay do quarto | verificar | 1 |

Hierarquia do PriceLabs: substituição por data vence reserva distante, que vence última hora, que vence a regra padrão. A regra de 14 dias vence a padrão sem precisar mexer nas datas.

### 5.5 Sincronização

- **Sync programado grátis às 06:00 (horário local).** Pega as reservas da madrugada.
- **Real-Time Sync (US$ 2 por unidade por mês).** Reage a reservas e cancelamentos em até 60 minutos e recalcula o ajuste por ocupação na hora. Com 29% das reservas no mesmo dia e muitos cancelamentos, se paga com uma diária por mês.

### 5.6 O que foi retirado da versão 1

- **Reduzir promoções da Booking, desligar a oferta de última hora da Booking e desligar descontos do Airbnb:** fora de questão, porque os descontos são fixos.
- **Criar tarifa não reembolsável com −12%:** só se vocês quiserem atacar cancelamento. Ela adiciona mais um desconto à cascata da Booking. Se fizer, compense com +12% no multiplicador desse plano.

---

## 6. Playbook de pacing: o que fazer quando a janela está abaixo da meta

Verificação diária de 5 minutos, pelo Claude com o conector ou pelo Multi Calendar.

| Situação | Ação |
|---|---|
| 7 dias abaixo de 70% e mercado de 7 dias abaixo de 30% | Deixar o ajuste por ocupação agir. Se passar 2 dias sem reserva, substituição por data com −10% do preço recomendado nas datas vazias, expirando em 48 horas |
| 7 dias abaixo de 70% e mercado de 7 dias acima de 40% | Problema de visibilidade, não de preço. Checar ranking e posição na Booking, fotos e disponibilidade. Não baixar mais |
| Tipo de quarto no piso e ainda sem reserva | Mesmo diagnóstico da Balcony: canal, mapeamento, conteúdo. Não baixar o piso |
| 15 dias abaixo de 50% | Conferir se a estadia mínima está em 1 noite. Checar se há fim de semana com preço mais do que 60% acima da semana |
| 30 dias abaixo de 35% | Manter. Revisar o preço base se a relação recomendada/base ficar abaixo de 0,93 por duas semanas |
| 45 e 60 dias abaixo de 25% e 15% | Não descontar. É cedo para 50% do público, que reserva em até 7 dias |
| 7 dias acima de 85% | Subir. A tabela de ocupação já aplica +8% a +15%. Conferir se o máximo não está travando |
| Cancelamento grande a menos de 3 dias | Substituição por data com −10% por 48 horas, expirando sozinha |

**Trava de receita:** acompanhar o RevPAR semanal. Se a ocupação subir e o RevPAR cair por 2 semanas seguidas, reduzir em 5 pontos os descontos das faixas de 0 a 7 dias.

---

## 7. Rotina com o Claude e o conector do PriceLabs

Prompts prontos, para usar com o conector ligado:

1. "Mostre a ocupação dos próximos 7, 15, 30, 45 e 60 dias de cada tipo de quarto e compare com as metas 70/50/35/25/15."
2. "Quais tipos de quarto estão abaixo de 70% nos próximos 7 dias? Mostre preço e estadia mínima de cada data."
3. "Divida por 2 o preço base, o mínimo e o máximo de todas as listings." Usar só no dia da virada, junto com o multiplicador do Beds24.
4. "Mude Fator de Demanda e Sazonalidade do grupo para Recommended, mantendo compset e peso de hotéis."
5. "Configure a Última hora do grupo como % Gradual, −10%, começando 7 dias antes."
6. "Ative ajuste por dia da semana na Queen Spa 7 unidades e na Queen Spa 2 unidades: sexta −10, sábado −10."
7. "Atualize o preço base da Villa King Balcony para 600 e o máximo para 1.500."
8. "Crie substituição por data nas sextas e sábados dos próximos 21 dias da Queen Spa 7 unidades com estadia mínima 1."
9. "Crie substituição de −10% do preço recomendado para as datas X e Y, com motivo 'pickup fraco'."
10. "Remova as substituições de 25 a 27/09 da Queen Spa 7 unidades."
11. "Quais reservas foram feitas nas últimas 24 horas e por qual canal?"
12. "Compare o valor pago nas reservas da última semana com o preço enviado pelo PriceLabs, por canal."
13. "Diagnostique por que a King Suite with Balcony não recebe reservas."
14. "Compare a Villa King Spa 7 unidades com os concorrentes: preço futuro e ocupação do mercado."
15. "Qual o pickup dos últimos 7 dias por tipo de quarto?"
16. "Gere um relatório de revisão da conta dos últimos 30 dias com ações recomendadas."

O conector **não** configura: multiplicador do Beds24, "Ajuste de preços" do PriceLabs, Multi-Room Occupancy-Based Adjustment, regras de estadia mínima, mínimos avançados, Safety Minimum Price e sincronização. Todos esses são feitos na tela, com o passo a passo acima.

---

## 8. Sequência de implantação

| Quando | Ação | Quem |
|---|---|---|
| Hoje | Verificar a Balcony no Beds24 e na Booking: categoria ativa, mapeada, com fotos e disponível | Você |
| Hoje | Checar a Villa King Spa (7) no PriceLabs, sem calendário de preço pela API, e a contagem de unidades da Double Spa | Você, com o Claude |
| Hoje | Fator de demanda e Sazonalidade em Recommended. Última hora em % Gradual −10% em 7 dias | Claude, pelo conector |
| Hoje | Estadia mínima de 1 noite, na regra padrão e na de última hora com 14 dias | Você, na tela |
| Dia 1 | Checklist da seção 4, itens 1 a 6 | Você |
| Dia 2, madrugada | **Virada:** multiplicadores `*2` no Beds24 e divisão por 2 no PriceLabs, no mesmo bloco. Conferir uma data por canal | Você e o Claude |
| Dia 3 | Passo 1 de base, mínimo e máximo. Dia da semana nas duas Queen Spa. Ligar a tabela de ocupação por tipo de quarto | Claude e você |
| Dia 3 | Sync às 06:00 e Real-Time Sync | Você |
| Dia 7 | Revisar pickup, RevPAR e o desconto medido por canal. Ajustar o multiplicador do Airbnb se o desconto real for diferente | Claude |
| Dia 10 | Passo 2 do preço base, se a relação recomendada/base ainda estiver abaixo de 0,93 | Claude |
| Dia 14 | Revisão completa: ocupação por janela contra as metas | Claude |

---

## 9. Riscos e armadilhas

- **Virada pela metade.** Dividir o PriceLabs por 2 sem o multiplicador no Beds24 derruba os preços em 50% em todas as OTAs. Fazer o multiplicador sem dividir dobra os preços. Faça sempre as duas coisas juntas e confira uma data.
- **Mínimo do quarto no Beds24.** Se estiver preenchido, trava o novo preço do PriceLabs.
- **Safety Minimum Price** pode virar piso alto depois da virada.
- **Oferta de última hora da Booking mais desconto de última hora do PriceLabs.** É desconto duplo. Se a Booking tiver oferta ativa, baixe o do PriceLabs para −5% ou "Nenhum".
- **Um único fator para a Booking.** Hóspedes sem Genius ou fora do celular pagam mais que a média, e isso já acontece hoje. O multiplicador é uma média. Revise-o a cada 30 dias com o prompt 12.
- **Lotar a 100% com RevPAR menor.** A trava é o RevPAR semanal e o piso calculado sobre o custo variável.
- **Ensinar o hóspede a esperar.** A tabela desconta só quando o quarto está vazio, não sempre na última hora.
- **Substituição por data com preço fixo fura o mínimo.** Use "% do preço recomendado".
- **Datas bloqueadas contam como ocupadas** no cálculo de ocupação. Não bloqueie quartos para "guardar".
- **Desligar uma personalização não significa "sem ajuste".** O algoritmo assume o padrão de mercado. Para anular, escolha "Nenhum" com a chave ligada.

---

## 10. Fontes

**Dados da conta:** conector MCP do PriceLabs, em 25/09/2026. Ferramentas usadas: get_listings, get_listing_data, get_listing_performance_metrics, get_listing_prices, get_customizations, get_customization_schema, get_neighbourhood_data, get_pms_reservations, get_listing_date_overrides e get_listing_rate_plans.

**PriceLabs**
- https://help.pricelabs.co/portal/en/kb/articles/pricing-offsets-for-mapped-listings
- https://help.pricelabs.co/portal/pt/kb/articles/article-11-2-2024
- https://hello.pricelabs.co/blog/pricing-offset-a-complete-guide/
- https://help.pricelabs.co/portal/en/kb/articles/how-does-pricelabs-manage-fees-from-multiple-channels
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
- https://help.pricelabs.co/portal/pt/kb/articles/configura%C3%A7%C3%B5es-avan%C3%A7adas-de-pre%C3%A7o-m%C3%ADnimo
- https://help.pricelabs.co/portal/pt/kb/articles/list-of-all-pricelabs-customizations-4-6-2024
- https://help.pricelabs.co/portal/pt/kb/articles/setting-dynamic-minimum-stay-restrictions-in-pricelabs
- https://help.pricelabs.co/portal/en/kb/articles/customization-hierarchy
- https://help.pricelabs.co/portal/en/kb/articles/date-specific-overrides
- https://help.pricelabs.co/portal/en/kb/articles/safety-minimum-price-15-10-2024
- https://help.pricelabs.co/portal/en/kb/articles/timed-sync
- https://hello.pricelabs.co/blog/real-time-pricing-updates/
- https://help.pricelabs.co/portal/en/kb/articles/performance-metrics
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

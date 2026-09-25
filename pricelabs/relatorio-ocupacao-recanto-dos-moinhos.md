# Relatório PriceLabs — Pousada Recanto dos Moinhos e Villa Dolce Amore

**Versão 4 · 25/09/2026.** Premissa: **os descontos das OTAs são fixos e todas as ofertas da Booking são obrigatórias.** Esta versão incorpora as suas respostas às pendências e os relatórios de promoções ativas da Booking. As versões anteriores passaram por revisão independente de números, de funcionamento técnico e de risco operacional.

**Fontes:**
- Conta PriceLabs, lida pelo conector MCP.
- 600 reservas do Beds24 feitas nos últimos 60 dias.
- 290 reservas com estadia nos próximos 60 dias, cruzadas com o calendário de preços do PriceLabs.
- Dois relatórios de promoções ativas exportados da extranet da Booking, em 25/09/2026.
- Documentação oficial do PriceLabs, do Beds24, da Booking.com e do Airbnb (lista no fim).

**Cópia da configuração atual, para desfazer a Fase 1:** `snapshot-configuracao-2026-09-25.json`, na mesma pasta. Ela traz preços, personalizações do grupo e substituições por data das 7 listings. O que só aparece na tela (regras de estadia mínima, mínimos avançados, Safety Minimum Price e tudo do Beds24) precisa ser anotado à mão antes do Dia 1.

**Meta de lotação antes de cada data:**

| Janela | 7 dias | 15 dias | 30 dias | 45 dias | 60 dias |
|---|---|---|---|---|---|
| Meta | 70% | 50% | 35% | 25% | 15% |

---

## 1. Resumo executivo

1. **Nenhuma janela do portfólio bate a meta hoje.** O portfólio está em 40% nos próximos 7 dias, contra 70% de meta. Sem a Balcony, que é vendida por último de propósito, fica em 49%, e só a janela de 60 dias bate a meta (18% contra 15%). Vocês estão acima do mercado na janela de 7 dias, que está em 28%. O problema não é falta de demanda na cidade.
2. **O desconto real das OTAs foi medido: o hóspede da Booking paga cerca de metade do preço que o PriceLabs envia.** Sem a Queen Spa (2), que é uma exceção, o hóspede paga entre 49% e 51% do preço enviado. Isso está dentro do que as ofertas explicam: Genius até 20%, mais tarifa Mobile ou nacional de 10%, mais uma oferta de 20% a 40%, somando até 57% de desconto.
3. **Todo valor em reais no PriceLabs vale cerca de metade para o hóspede.** O mínimo de R$ 800 vira cerca de R$ 400, e R$ 345 no pior caso das ofertas, ainda antes da comissão da Booking.
4. **A King Suite with Balcony (6 unidades) é a categoria de transbordo.** São suítes sem banheira, e vocês mantêm o preço perto das outras para que ela venda só quando a Villa estiver quase lotada. Os dados confirmam que funciona: em 11/10, quando a King Spa (7) lotou, a Balcony vendeu 3 de 6. O ajuste é tirá-la das metas e impedir que o próprio PriceLabs a puxe para o piso.
5. **O fim de semana está fraco em 2 dos 7 tipos de quarto:** Queen Spa de 7 unidades (14%) e Queen Spa de 2 unidades (25%). O mercado está em 48%. Quatro dos outros cinco vendem melhor no fim de semana do que na semana. A Balcony não vende em nenhum dos dois. A medição cobre um único fim de semana, então vale confirmar nas próximas semanas.
6. **O tom "Agressivo" em Sazonalidade e Fator de Demanda faz o preço oscilar ao máximo.** Ele cai muito quando a demanda cai e sobe muito quando ela sobe. Com a OTA tirando mais de 50% por cima, isso gera dia de semana barato demais e fim de semana caro demais. O tom "Recomendado" suaviza os dois lados.
7. **Os descontos do PriceLabs se somam aos da OTA.** A última hora do PriceLabs está em "Market Driven Agressivo", e a Booking já tem oferta de última hora de 25% a 40% em praticamente todos os quartos. A última hora do PriceLabs deve ir para "No last minute adjustment".
8. **Estratégia em duas fases.**
   - **Fase 1, já:** ajustes dentro do PriceLabs, na escala atual, sem mexer no Beds24. É segura e reversível.
   - **Fase 2, opcional:** a "virada". Os valores do PriceLabs passam a significar o preço do hóspede, e o Beds24 multiplica por canal. Só com procedimento controlado e depois de resolver as pendências de cadastro.
9. **O ajuste por ocupação por tipo de quarto** (Multi-Room Occupancy-Based Adjustment) está no padrão automático do PriceLabs. A tabela proposta aqui converte as suas metas em gatilhos. Ela vale só para os quartos com 6 ou 7 unidades.
10. **Ainda há quatro pontos de cadastro para checar.** A Villa King Spa (7) já foi resolvida.
    - **Queen Spa de 2 unidades:** o hóspede paga 66% menos do que o PriceLabs envia. É mais do que o máximo que as promoções permitem, que fica entre 50% e 57%. Por isso ela sai mais barata que a Queen Spa de 7 unidades, que é categoria inferior. Ver a seção 1A.
    - **Double Spa:** tem "7 Units" no nome, mas aparece com 6 unidades no PriceLabs.
    - **Villa King Spa de 2 unidades:** aparece com 1 de 2 quartos ocupados no PriceLabs, mas o Beds24 tem reservas para 2 de 2 entre 25 e 29/09.
    - **Airbnb:** os valores de reserva vêm inconsistentes do Beds24.

---

## 1A. Suas respostas e o que muda

| Pendência | Sua resposta | O que os dados mostram | O que muda no plano |
|---|---|---|---|
| Balcony vazia | Suítes sem banheira. Preço perto das outras até a Villa estar quase lotada | A estratégia funciona. Em 11/10 a King Spa (7) lotou e a Balcony vendeu 3 de 6. Em dias de semana, o hóspede pagou R$ 534 na Balcony, contra R$ 571 a R$ 610 nas King Spa. Mas o PriceLabs, por ver ocupação zero, empurra a Balcony para o piso de R$ 800 em 10 dos 42 dias de semana até 08/10 | A Balcony sai das metas de ocupação e da tabela de ocupação. Última hora "No last minute adjustment" no nível dela. Mínimo subido para R$ 950, para ficar mais perto das King Spa (aplicado em 25/09) |
| Ofertas da Booking | Relatórios anexos. Genius disponível até o nível 3 | Oferta básica de 20% a 25% em todas as datas. Última hora de 25% em quase todos os quartos, e de 30% ou 40% em alguns. Oferta antecipada de 10% a 30%, a maioria de 25%. Mobile 10% e tarifa nacional 10%. Detalhes na seção 3 | Última hora do PriceLabs em "No last minute adjustment". Pior caso de desconto recalculado |
| Mínimo e estadia no Beds24 | Mínimo de R$ 200. 2 diárias em fins de semana e feriados, 1 diária nos dias de semana | O mínimo de R$ 200 não interfere, porque os mínimos do PriceLabs são de R$ 800 ou mais. Mas o PriceLabs envia 1 noite nas vésperas de feriado que não caem em sexta ou sábado: 11/10, 01/11 e 19/11 | Substituições de 2 noites nessas três datas. A liberação de 1 noite no fim de semana das Queen Spa vira teste opcional, porque contraria a política de vocês |
| Villa King Spa (7) sem calendário | Bastou recarregar a página | Calendário funcionando: 61% nos próximos 7 dias, com 30 de 49 quartos-noite | Entra na tabela de ocupação e no passo 1 do preço base |
| Queen Spa (2) com 66% de desconto | É categoria intermediária | A escada de preço está invertida dos dois lados. O PriceLabs envia mais por ela (mediana de R$ 1.333 em dia de semana) do que pela Double (R$ 1.232), que é categoria acima. O hóspede paga menos por ela (R$ 451) do que pela Queen Spa (7) (R$ 510), que é categoria abaixo | Preço base de 1.450 no passo 1, entre a Queen Spa (7) e a Double. Isso corrige só a ordem dos preços enviados. Para o hóspede, a inversão só se resolve depois de achar a causa dos 66%, então este passo fica para depois dessa checagem. E conferir o Beds24 e a Booking: em 30/09 o PriceLabs envia R$ 1.267 para esse quarto. Veja na extranet qual tarifa aparece antes das promoções e se há multiplicador no Beds24 |

---

## 2. Situação atual contra as metas

Ocupação hoje por janela, em quartos-noite vendidos sobre disponíveis. 🟢 bate a meta, 🔴 abaixo. Fonte: calendário do PriceLabs.

| Tipo de quarto (unid.) | 7d (meta 70) | 15d (meta 50) | 30d (meta 35) | 45d (meta 25) | 60d (meta 15) | Fds 7d | Semana 7d |
|---|---|---|---|---|---|---|---|
| Moinhos · Queen Spa (7) | 🔴 43% | 🔴 23% | 🔴 17% | 🔴 12% | 🔴 10% | 14% | 54% |
| Moinhos · Double Spa (6 no PriceLabs) | 🔴 43% | 🔴 31% | 🔴 29% | 🔴 23% | 🟢 21% | 75% | 30% |
| Moinhos · Queen Spa Afrodite (1) | 🟢 71% | 🟢 60% | 🟢 53% | 🟢 42% | 🟢 35% | 100% | 60% |
| Moinhos · Queen Spa (2) | 🔴 50% | 🔴 27% | 🔴 27% | 🟢 29% | 🟢 28% | 25% | 60% |
| Villa · King Spa (7) | 🔴 61% | 🔴 39% | 🔴 32% | 🔴 24% | 🟢 19% | 71% | 57% |
| Villa · King Balcony (6), transbordo | 0% | 2% | 3% | 2% | 1% | 0% | 0% |
| Villa · King Spa (2) ⚠️ | 🔴 36% | 🔴 27% | 🔴 22% | 🔴 23% | 🟢 18% | 50% | 30% |
| **Portfólio** | 🔴 **40%** | 🔴 **26%** | 🔴 **22%** | 🔴 **18%** | 🔴 **14,9%** | | |
| **Portfólio sem a Balcony** | 🔴 **49%** | 🔴 **32%** | 🔴 **27%** | 🔴 **22%** | 🟢 **18%** | | |
| **Mercado (350 similares, 15 km)** | 28% | 22% | 19% | 15% | 14% | 48% | 19% |

⚠️ Pelas reservas do Beds24, a Villa King Spa (2) estaria com 71% em 7 dias. Ver a pendência 10.

**"Fds 7d" é um único fim de semana:** 25 e 26/09, sendo o dia 25 hoje.

### O que as suas metas significam dia a dia

As metas são cumulativas. Traduzidas para cada faixa de dias, ficam assim:

| Faixa de dias até a data (hoje = dia 0) | Ocupação que a faixa precisa ter | Hoje, portfólio | Hoje, sem a Balcony |
|---|---|---|---|
| 0 a 6 | ≈ 70% | 40% | 49% |
| 7 a 14 | ≈ 30% | 14% | 16% |
| 15 a 29 | ≈ 20% | 18% | 22% ✅ |
| 30 a 44 | ≈ 5% | 9% ✅ | 11% ✅ |
| 45 a 59 | já coberta pelas anteriores | 7% ✅ | 8% ✅ |

**Leitura:** sem a Balcony, o funil a partir de 15 dias já está no ritmo. O déficit está concentrado nos próximos 15 dias, e principalmente nos próximos 7. A janela de 45 dias (18%) está abaixo da meta de 25% porque carrega o déficit das faixas mais próximas, não por falta de reservas antecipadas.

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

Entre parênteses, os valores sem a Queen Spa (2), que tem um desconto fora do padrão.

| Canal e janela | Reservas | Pago ÷ enviado (mediana) | Desconto efetivo |
|---|---|---|---|
| Booking.com, reservas dos últimos 3 dias | 16 (13) | 0,48 (0,51) | 52% (49%) |
| Booking.com, reservas dos últimos 10 dias | 42 (31) | 0,45 (0,49) | 55% (51%) |
| Booking.com, estadias só em dias de semana (10 dias) | 31 (22) | 0,42 (0,50) | 58% (50%) |
| Booking.com, estadias com sexta ou sábado (10 dias) | 11 (9) | 0,49 (0,49) | 51% (51%) |
| Direto e outros | 3 | 0,48 | 52% (amostra pequena) |
| Airbnb | 3 | inconsistente | os valores do Beds24 não fecham |

| Tipo de quarto (só Booking, reservas dos últimos 10 dias) | Enviado (mediana) | Pago por noite (mediana) | Pago ÷ enviado |
|---|---|---|---|
| Queen Spa (7), 9 reservas | R$ 1.481 | R$ 562 | 0,49 |
| Double Spa, 10 reservas | R$ 1.856 | R$ 896 | 0,49 |
| Queen Spa (2) | R$ 1.333 | R$ 455 | **0,34** |
| Villa King Spa (2) | R$ 1.045 | R$ 540 | 0,51 |

### Ofertas ativas na Booking (relatórios de 25/09/2026)

Os dois arquivos, provavelmente um por propriedade, somam 297 linhas de promoção. Muitas se repetem por quarto e plano tarifário. Resumo:

| Oferta | Desconto | Vale para | Observação |
|---|---|---|---|
| Oferta básica (Basic Deal) | 25% em um arquivo, 20% no outro | Todas as estadias até fev a abr/2027 | Na prática, todo hóspede da Booking tem pelo menos essa oferta |
| Última hora (Last Minute Deal) | 25% na maioria. 30% em um caso, até 18/10. 40% em outro, até 09/12 | Estadias até meados de 2027 | Substitui a básica quando é maior |
| "Para este final de semana" | 30% | Estadias até 22/11 | Oferta própria de fim de semana em um dos arquivos |
| "Para feriado 7 de setembro" | 35% | Estadias até 05/12, com 65 datas excluídas | Continua ativa, apesar do nome. Obrigatória: mantida |
| Antecipada (Early Booker Deal) | 10% a 30%, a maioria de 25% | Estadias até meados de 2027 | Mesma categoria da básica e da última hora: vale a maior |
| Tarifa Mobile e tarifa nacional | 10% cada | Sempre ativas | Não se somam entre si |
| Late Escape (campanha) | 27% a 28% | Estadias de 01/10 a 07/01 | Campanha. Não soma com Genius, Mobile nem nacional |
| Getaway Deal (campanha) | 20% a 29% | Estadias até 30/09 | Termina na próxima semana |
| Limited Time Deal | 30% | Reservas feitas só em 25 e 26/09, para estadias até 2027 | Quem reservar hoje ou amanhã tem 30% em qualquer data futura, inclusive feriados |
| Genius | até 20% (nível 3) | Hóspedes Genius | Não aparece nos relatórios. Informado por vocês |

**Quanto o hóspede pode chegar a pagar a menos.** A conta abaixo assume que os descontos se aplicam em cascata, como a Booking descreve.

| Perfil do hóspede | Oferta de 25% | Oferta de 30% | Oferta de 40% |
|---|---|---|---|
| Sem Genius, computador, estrangeiro | 25% | 30% | 40% |
| Sem Genius, celular ou brasileiro | 32,5% | 37% | 46% |
| Genius nível 1 (10%), celular ou brasileiro | 39% | 43% | 51% |
| Genius nível 3 (20%), celular ou brasileiro | 46% | 50% | 57% |

Muitos hóspedes são brasileiros ou reservam pelo celular, então os 10% da tarifa nacional ou da Mobile valem para a maioria. Sem a Queen Spa (2), o desconto medido fica entre 49% e 51%, dentro da tabela. Isso é coerente com muitos hóspedes Genius e com ofertas de 30% a 40% em parte dos quartos. Com a Queen Spa (2), a mediana sobe para 52% a 58%, acima do máximo possível, o que confirma que o problema dela está fora das ofertas.

**Cancelamentos:** a oferta básica de 25% teve 888 noites reservadas e 437 canceladas no último ano. A oferta antecipada de 30% teve 104 reservadas e 72 canceladas.

**O que isso significa**

- **O desconto efetivo da Booking fica em torno de 50%**, sem contar a Queen Spa (2). É o resultado esperado com Genius até o nível 3, tarifa Mobile ou nacional e ofertas de 20% a 40%. O pior caso possível pelas ofertas é 57%.
- **O hóspede de dia de semana paga em torno de R$ 610 por noite** (mediana, só Booking, sem a Queen Spa (2)). Hoje só a Balcony (R$ 800 enviado) e a Afrodite (R$ 1.500 enviado) estão no piso no calendário dos próximos 60 dias.
- **A Queen Spa de 2 unidades perde 66% entre o preço enviado e o pago**, sempre. É mais do que qualquer combinação das ofertas ativas. A causa provável está fora das promoções: um multiplicador menor que 1 no Beds24, o quarto mapeado para outra tarifa na Booking ou um plano tarifário derivado com desconto extra.
- **O PriceLabs não sabe desse desconto.** A documentação dele diz que taxas e descontos de cada canal entram depois que o preço sai do PriceLabs.

### Consequências práticas

| Valor no PriceLabs | Enviado | Hóspede, caso típico (≈ 0,50) | Hóspede, pior caso das ofertas (≈ 0,43) |
|---|---|---|---|
| Mínimo da Queen Spa (7) | R$ 800 | ≈ R$ 400 | ≈ R$ 345 |
| Mínimo da Afrodite | R$ 1.500 | ≈ R$ 750 | ≈ R$ 645 |
| Preço base da Double Spa | R$ 1.600 | ≈ R$ 800 | ≈ R$ 690 |

- **Os descontos do PriceLabs se somam aos da OTA.** Por exemplo, 20% de desconto de última hora do PriceLabs combinados com 50% da Booking deixavam o hóspede pagando cerca de 40% do preço recomendado. O piso é o limite.
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

- **Quarto sem calendário:** qualquer quarto que não receba preço do PriceLabs teria o preço dobrado. A Villa King Spa (7) chegou a ficar assim até a página ser recarregada.
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
| Última hora (Last Minute Prices) | Market Driven Aggressive | **"No last minute adjustment"**, com a chave ligada | A Booking já dá 25% a 40% de última hora. Evita somar dois descontos |
| Compset de hotéis e peso (Hotel Weights) | Personalizado, Mostly Hotel | igual | Já está adequado para hotel |
| Far-out (Far Out Prices) | Market Driven Balanced | igual | Não afeta os próximos 15 dias |
| Safety Minimum Price | conferir na tela | **"Do Not Apply"** | Ele usa a diária paga no ano anterior. Evita que vire um piso inesperado |

**Atenção ao desligar uma personalização.** Desligar não significa "sem ajuste": o algoritmo passa a usar o padrão de mercado. Para anular de fato, escolha "Nenhum" com a chave ligada.

### 5.2 Ajuste por ocupação por tipo de quarto (Multi-Room Occupancy-Based Adjustment)

**Aplicar só nos quartos com 6 ou 7 unidades que vocês querem vender primeiro:** Queen Spa (7), Double Spa e Villa King Spa (7). A Balcony fica de fora por ser a categoria de transbordo: a tabela a descontaria justamente por estar vazia. A Afrodite (1 unidade) e os quartos de 2 unidades também ficam de fora. Neles há poucos quartos-noite em cada faixa: uma única noite vendida mexe a ocupação da faixa de 0 a 6 dias em 7 a 14 pontos, e a tabela ficaria pulando de coluna.

**Como o PriceLabs mede a ocupação:** em cada linha, ele soma as noites vendidas de todas as unidades dentro daquela faixa de dias e divide pelas noites disponíveis. Por isso cada linha tem uma meta própria, a da tabela "O que as suas metas significam dia a dia", na seção 2.

A tabela abaixo substitui o ajuste automático que o PriceLabs já aplica hoje. Os valores são em % sobre o preço recomendado. O desconto nunca fura o preço mínimo. Os descontos são leves porque se somam aos mais de 50% da OTA.

| Faixa de dias (meta da faixa) | < 15% | 15–29% | 30–49% | 50–69% | 70–84% | 85–94% | ≥ 95% |
|---|---|---|---|---|---|---|---|
| 0 a 6 (≈ 70%) | −12% | −10% | −7% | −4% | **0%** | +6% | +12% |
| 7 a 14 (≈ 30%) | −8% | −4% | **0%** | +3% | +6% | +9% | +12% |
| 15 a 29 (≈ 20%) | −4% | **0%** | +3% | +5% | +8% | +10% | +12% |
| 30 a 60 (≈ 5%) | **0%** | +2% | +4% | +6% | +8% | +10% | +12% |

Cada linha fica neutra, em 0%, na faixa que contém a sua meta. As faixas de dias são crescentes. Os ajustes sobem conforme a ocupação sobe, como o PriceLabs exige.

**Pior caso:** chegada no mesmo dia e ocupação abaixo de 15%. Com a última hora do PriceLabs em "Nenhum", o único desconto do PriceLabs é o da tabela: 12% abaixo do recomendado. Com o desconto máximo da Booking por cima (Genius nível 3, tarifa nacional e oferta de 30% a 40%), o hóspede paga entre 38% e 44% do preço recomendado. O preço mínimo segura o resto.

**Regra para não empilhar:** num quarto com a tabela ligada, não crie substituição de desconto. Uma substituição de −10% por cima levaria o pior caso a cerca de 21% abaixo do recomendado, com o hóspede pagando entre 34% e 40% do recomendado.

**Antes de ligar:** peça ao conector a comparação do preço de uma data com e sem a tabela (prompt 12 da seção 7). Depois de ligar, observe por uma semana antes de mexer em qualquer outra coisa.

### 5.3 Preço base, mínimo e máximo por tipo de quarto (Gerenciar propriedades)

Regra do "passo 1": percorrer 60% do caminho até o preço base recomendado pelo PriceLabs, só onde ele dá recomendação. O restante fica para daqui a 10 dias, se ainda indicado.

| Tipo de quarto | Base hoje → passo 1 | Base para o hóspede ≈ (× 0,50) | Mínimo | Máximo | Base recomendada pelo PriceLabs | Observação |
|---|---|---|---|---|---|---|
| Queen Spa (7) | 1.500 → **1.380** | R$ 690 | 800 (manter) | 5.000 (manter) | não informada | Corte de 8% por decisão própria: 30, 45 e 60 dias abaixo do mercado |
| Double Spa | 1.600 → **1.480** | R$ 740 | 900 (manter) | 5.000 (manter) | 1.399 (−13%) | Regra dos 60% |
| Afrodite (1) | 2.000 (manter) | R$ 1.000 | 1.500 (manter) | 10.000 (manter) | não informada | Bate todas as metas. Não mexer |
| Queen Spa (2) | 1.700 → **1.450**, depois de checar os 66% | R$ 495 (este quarto paga ≈ 0,34) | 850 (manter) | 5.000 (manter) | 1.457 (−14%) | Fora da regra dos 60%: 1.450 coloca a categoria intermediária entre a Queen Spa (7) e a Double. Checar antes o desconto de 66% |
| Villa King Spa (7) | 1.600 → **1.480** | R$ 740 | 1.000 (manter) | 5.000 (manter) | 1.392 (−13%) | Regra dos 60%. Calendário confirmado |
| Villa Balcony (6), transbordo | 1.400 (manter) | R$ 700 | 800 → **950** (aplicado) | 5.000 (manter) | 840 (−40%) | Ignorar a recomendação de baixar: ela vem da ocupação zero, que é intencional. Com mínimo de 950 ela fica mais perto das King Spa, que estão entre R$ 1.100 e R$ 1.230 enviados em dia de semana. Para nunca ficar abaixo delas, o mínimo teria de ser cerca de R$ 1.100 |
| Villa King Spa (2) | 1.500 → **1.270** | R$ 635 | 900 (manter) | 5.000 (manter) | 1.114 (−26%) | Regra dos 60%. Checar antes o conflito de ocupação |

**Mínimo: manter todos. O da Balcony já foi para R$ 950.** No desconto máximo da Booking, o hóspede paga entre 43% e 50% do mínimo. O mínimo certo só pode ser definido por vocês. A conta é:

> custo variável por quarto ocupado + margem mínima ≤ mínimo × 0,43 × (1 − comissão da Booking)

O fator 0,43 corresponde ao pior caso das ofertas: Genius nível 3, tarifa nacional e oferta de 40%. A Queen Spa (2) fica abaixo disso até a causa dos 66% ser resolvida. O custo variável inclui lavanderia, amenities, café da manhã, energia e água. Com comissão de 15%, um mínimo de R$ 800 rende cerca de R$ 290 líquidos no pior caso.

**Máximo: manter.** O teto atual não está limitando nenhum preço. O maior preço enviado da Queen Spa (7) é R$ 3.706 em 10/10, com 5 de 7 quartos vendidos, e o hóspede pagou entre R$ 950 e R$ 1.300 por noite. Um teto menor cortaria esses picos de feriado.

### 5.4 Fim de semana e estadia mínima

A política de vocês fica **mantida**: 2 diárias em fins de semana e feriados, 1 diária nos dias de semana. O PriceLabs já envia 2 noites em todas as sextas e sábados. O buraco está nas vésperas de feriado que caem em outro dia da semana.

| Ação | Onde | Como |
|---|---|---|
| Garantir 2 noites nas vésperas de feriado | Todos os quartos | Hoje o PriceLabs envia 1 noite em 11/10 (domingo, véspera de 12/10), 01/11 (domingo, véspera de Finados) e 19/11 (quinta, véspera de Consciência Negra). Criar substituição com estadia mínima 2 nessas datas, com expiração de 3 dias antes, para liberar 1 noite perto da chegada. Se a regra do Beds24 já cobre essas datas, este passo é desnecessário |
| Teste opcional: 1 noite no fim de semana | Só Queen Spa (7) e Queen Spa (2), só em 02–03/10 | Contraria a política de vocês. Só faz sentido se quiserem medir o efeito no fim de semana fraco dessas duas categorias. Atenção: se a regra de 2 noites estiver no cadastro do quarto no Beds24, o PriceLabs não consegue reduzir |
| Intervalo livre entre reservas | Todos | Na tela: "Escolha um número" = 1 |
| Estadia mínima do quarto no Beds24 | Todos | Onde a regra de 2 noites está configurada: no Beds24 ou no PriceLabs? O PriceLabs não consegue reduzir abaixo do mínimo do Beds24 |

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
| Quarto no piso e ainda sem reserva | Checar canal, mapeamento e conteúdo. Não baixar o piso. A Balcony é exceção: ela vende por último de propósito |
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
5. "Configure a Última hora do grupo como 'No last minute adjustment', com a chave ligada."
6. "Atualize o preço base da Double Spa para 1.480."
7. "Leia as substituições existentes e crie estadia mínima 2 em 11/10, 01/11 e 19/11 em todos os quartos, expirando 3 dias antes da data."
8. "Atualize o preço mínimo da Balcony para 950."
9. "Mostre o preço enviado pela Queen Spa 2 unidades em 30/09 e compare com o que os hóspedes pagaram nas últimas reservas desse quarto."
10. "Quais reservas foram feitas nas últimas 24 horas e por qual canal?"
11. "Compare o valor pago nas reservas da última semana com o preço enviado pelo PriceLabs, por canal."
12. "Recalcule os preços da Queen Spa 7 unidades e mostre o motivo de cada preço nos próximos 7 dias."
13. "Mostre a ocupação da Villa King Spa 7 e 2 unidades nos dias em que a Balcony vendeu."
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
| Feito | Ofertas da Booking, mínimo e estadia do Beds24, Villa King Spa (7) e estratégia da Balcony | Você | — |
| Hoje | Pendências que faltam: Queen Spa (2) com 66%, Double com 6 ou 7 unidades, conflito na Villa King Spa (2), multiplicadores dos canais no Beds24 | Você, com o Claude | — |
| ✅ 25/09 | Fator de Demanda e Sazonalidade → Recommended. Última hora → "No last minute adjustment". Mínimo da Balcony → 950. Estadia mínima 2 em 11/10, 01/11 e 19/11. Ver `registro-de-mudancas.md` | Claude | Pelo registro de mudanças |
| Dia 2 | Safety Minimum Price → "Do Not Apply". Sincronização às 06:00 | Você, na tela | Anotar o valor anterior |
| ✅ 25/09 | Teste de 1 noite nas Queen Spa em 02–03/10 | Claude | Apagar as substituições pelo conector |
| ✅ 25/09 | Passo 1 do preço base: Double 1.480, Villa King Spa (7) 1.480, Villa King Spa (2) 1.270 e Queen Spa (7) 1.380. Queen Spa (2) só depois de achar a causa dos 66% | Claude | Valores no arquivo de configuração salvo |
| Dia 7 | Ligar a tabela de ocupação na Queen Spa (7), na Double e na Villa King Spa (7). Na Balcony, deixar o ajuste por ocupação em "Nenhum" | Você, na tela | Desligar |
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

# Relatório PriceLabs — Pousada Recanto dos Moinhos e Villa Dolce Amore

**Data:** 25/09/2026 · **Fonte:** conta PriceLabs (conector MCP), 600 reservas do Beds24 dos últimos 60 dias, documentação oficial do PriceLabs.
**Meta de lotação antes de cada data:** 7 dias 70% · 15 dias 50% · 30 dias 35% · 45 dias 25% · 60 dias 15%.
**Premissa informada por você:** as plataformas (Booking.com, Airbnb) aplicam de 30% a 50% de desconto sobre o preço enviado pelo PriceLabs.

---

## 1. Resumo executivo

1. **Hoje o portfólio está em 40% de ocupação nos próximos 7 dias** (86 de 217 diárias disponíveis), contra a meta de 70%. Só a suíte Afrodite (1 unidade) bate a meta. Vocês estão bem acima do mercado (28%), então o problema não é demanda, é conversão nas datas certas.
2. **Metade das reservas nasce dentro de 7 dias** e 29% no próprio dia ou véspera. A estadia média é de 1,3 noites. O jogo de vocês é de última hora e de 1 noite. Toda a configuração precisa refletir isso.
3. **Fim de semana é o maior vazamento.** O algoritmo sobe a diária de sexta e sábado para R$ 1.900 a 2.500 e exige 2 noites. Resultado: 14% a 25% de ocupação nos fins de semana dos próximos 7 dias em quatro dos sete tipos, com o mercado a 45% a 48%.
4. **O PriceLabs não sabe que as OTAs cortam 30% a 50%.** Ele enxerga bases de R$ 1.400 a 2.000 num mercado cuja mediana é R$ 662 e percentil 90 é R$ 1.148, conclui que vocês estão caros e empurra descontos de última hora que se somam ao desconto da plataforma. É o desconto duplo: o hóspede paga pouco e vocês ainda parecem caros na vitrine.
5. **Tom "Agressivo" em Sazonalidade e Fator de Demanda** no grupo faz o algoritmo segurar preço esperando o hóspede tardio. Para uma meta de ocupação, o tom certo é "Recomendado".
6. **King Suite with Balcony (6 unidades) está com 0% nos próximos 60 dias**, base R$ 1.400 contra R$ 840 recomendada, e nenhuma reserva desde 19/09. Precisa de ação imediata e verificação de canal.
7. **Cancelamentos:** 354 dos 600 registros dos últimos 60 dias estão cancelados, quase todos Booking.com. Parte pode ser modificação de reserva, mas o padrão indica que o calendário "enche" e depois esvazia perto da data. Isso derruba o pacing e exige tarifa não reembolsável.
8. **Ajustes por ocupação por tipo de quarto (MROBA) não estão ativos.** É a ferramenta desenhada exatamente para a sua meta de lotação por janela e não custa nada.
9. **Sincronização diária única.** Com 29% das reservas no mesmo dia, vale ativar sync programado extra ou Real-Time Sync (Beds24 é compatível).
10. **Substituições manuais por data** (−20% e +20% em 25 a 27/09 na Queen 7 un.) mostram intervenção manual. Com a configuração certa, isso deixa de ser necessário.

---

## 2. Situação atual contra as metas de pacing

Ocupação hoje por janela (dados PriceLabs, 25/09). Verde = bate a meta, vermelho = abaixo.

| Tipo de quarto (unid.) | 7d (meta 70) | 15d (meta 50) | 30d (meta 35) | 45d (meta 25) | 60d (meta 15) | Fds 7d | Semana 7d |
|---|---|---|---|---|---|---|---|
| Moinhos · Queen Spa (7) | 🔴 43% | 🔴 23% | 🔴 17% | 🔴 12% | 🔴 11% | 14% | 54% |
| Moinhos · Double Spa (7) | 🔴 43% | 🔴 31% | 🔴 29% | 🔴 23% | 🟢 21% | 75% | 30% |
| Moinhos · Queen Spa Afrodite (1) | 🟢 71% | 🟢 60% | 🟢 53% | 🟢 42% | 🟢 35% | 100% | 60% |
| Moinhos · Queen Spa (2) | 🔴 50% | 🔴 27% | 🔴 27% | 🟢 29% | 🟢 28% | 25% | 60% |
| Villa · King Spa (7) | 🔴 61% | 🔴 39% | 🔴 32% | 🔴 24% | 🟢 19% | 71% | 57% |
| Villa · King Balcony (6) | 🔴 0% | 🔴 2% | 🔴 3% | 🔴 2% | 🔴 1% | 0% | 0% |
| Villa · King Spa (2) | 🔴 36% | 🔴 27% | 🔴 22% | 🔴 23% | 🟢 18% | 50% | 30% |
| **Mercado (350 similares, 15 km)** | 28% | 22% | 19% | 15% | 14% | 48% | 19% |

Leitura: vocês vencem o mercado na semana e perdem no fim de semana. As janelas longas (45 e 60 dias) já estão perto da meta em cinco tipos, ou seja, o funil de longo prazo funciona. O buraco está em 7 e 15 dias, justamente onde o algoritmo hoje segura preço.

### Preços atuais e o que o PriceLabs recomenda

| Tipo de quarto | Mín | Base | Máx | Base recomendada | Relação Rec/Base | Dias no preço mínimo (7d) |
|---|---|---|---|---|---|---|
| Queen Spa (7) | 800 | 1.500 | 5.000 | n/d | n/d | 17% |
| Double Spa (7) | 900 | 1.600 | 5.000 | 1.399 | 0,87 | 29% |
| Afrodite (1) | 1.500 | 2.000 | 10.000 | n/d | n/d | 100% |
| Queen Spa (2) | 850 | 1.700 | 5.000 | 1.457 | 0,86 | 0% |
| Villa King Spa (7) | 1.000 | 1.600 | 5.000 | 1.392 | 0,87 | 17% |
| Villa Balcony (6) | 800 | 1.400 | 5.000 | 840 | 0,60 | 100% |
| Villa King Spa (2) | 900 | 1.500 | 5.000 | 1.114 | 0,74 | 0% |

Regra do PriceLabs: relação abaixo de 0,93 é sinal para baixar a base. Todas as que têm recomendação estão abaixo.

### Como o hóspede reserva (600 reservas, 27/07 a 25/09)

| Indicador | Valor |
|---|---|
| Reservas dentro de 1 dia da chegada | 29% |
| Reservas dentro de 7 dias | 50% |
| Reservas com 60+ dias | 20% |
| Estadia de 1 noite | 74% (média 1,33 noites) |
| Canal | Booking.com 70% · Airbnb 19% · direto/outros 10% · Expedia <1% |
| Diária realizada mediana (bruta, PMS) | R$ 850 |
| Diária realizada por dia de check-in | Sex 1.693 · Sáb 1.123 · Qua 991 · Ter 883 · Qui 846 · Seg 844 · Dom 752 |
| Registros cancelados | 59% (354 de 600), quase todos Booking.com |

Observação: o ADR que o PriceLabs mostra (R$ 500 a 670) é menor que o bruto do PMS porque desconta o que a plataforma abate. A diferença é justamente o efeito dos 30% a 50% de desconto das OTAs.

---

## 3. Diagnóstico detalhado

### 3.1 Desconto duplo: OTA + algoritmo

Fluxo hoje: PriceLabs calcula R$ 1.500 → Booking aplica Genius, Mobile e promoções (−30% a −50%) → hóspede vê R$ 750 a 1.050. Ao mesmo tempo o PriceLabs compara R$ 1.500 com a mediana de R$ 662 e conclui "listing cara", então aplica desconto de última hora "Market Driven Agressivo" e bate no mínimo de R$ 800 nos dias de semana. O hóspede final paga R$ 400 a 560 líquidos.

Consequências: recomendações de base distorcidas, dias de semana no piso, fim de semana inflado para compensar, e o ranking da Booking prejudicado pelo "preço de vitrine" alto.

Duas formas de corrigir (escolha uma):

- **Opção A (recomendada): reduzir as promoções de OTA para 10% a 20%** (mantendo Genius nível básico) e deixar o PriceLabs precificar perto do mercado. O algoritmo passa a ver preços reais, o "Market Driven" funciona como desenhado, e o ranking melhora porque o preço mostrado cai.
- **Opção B: manter as promoções e compensar com "Ajuste de preços" (Pricing Offset)** no nível da conta, por canal, com +40% a +60% para Booking.com. A base volta para perto do mercado (R$ 900 a 1.100) e o offset recompõe o desconto. O PriceLabs documenta que offsets por canal devem ser aplicados no nível da conta. Desvantagem: preço de vitrine continua alto e o desconto de última hora continua se somando.

Em ambos os casos, o **preço mínimo precisa ser calculado sobre o líquido**: se o piso operacional é R$ 500 líquidos e a OTA corta 40%, o mínimo no PriceLabs precisa ser R$ 835. Hoje os mínimos de R$ 800 a 900 com 50% de desconto entregam R$ 400 a 450.

### 3.2 Fim de semana: preço e estadia mínima

74% das estadias são de 1 noite e o algoritmo pede 2 noites na sexta e no sábado (visível em `min_stay: 2` nas datas 25, 26/09, 02, 03 e 09/10). Uma pessoa que quer só sábado não consegue reservar. Somado ao salto de preço (Queen 7 un.: R$ 872 na quinta → R$ 1.925 na sexta), a ocupação de fim de semana fica em 14% a 25% contra 48% do mercado.

A sexta fechou a R$ 1.693 de diária média bruta nos últimos 60 dias, então há demanda que paga. O ajuste não é "baratear tudo", é **deixar a diária de 1 noite existir** e reduzir o degrau de 120% para algo como 40% a 60%.

### 3.3 Tom agressivo no grupo

Configuração atual no grupo "Recanto dos Moinhos": Sazonalidade = Agressivo, Fator de Demanda = Agressivo, Última hora = Market Driven Agressivo, Far-out = Market Driven Balanceado, Dia da semana = desligado, Compset de hotéis = personalizado, Peso = "Mostly Hotel".

Segundo a documentação, "Aggressive" no Fator de Demanda "maximiza preço em feriados e eventos; melhor para quem quer esperar reservas". Isso é estratégia de ADR, não de ocupação. Com sazonalidade também agressiva, o algoritmo amplifica picos de preço no fim de semana e em datas de evento.

### 3.4 King Balcony (6 unidades)

Zero reservas nos próximos 60 dias e uma única reserva em outubro (feita em 28/08, R$ 430 líquidos). Preço de semana já no piso (R$ 800) e fim de semana a R$ 2.100 com 2 noites. Como as outras categorias da Villa vendem, há três hipóteses a checar hoje: (1) categoria inativa ou sem disponibilidade no Beds24/Booking; (2) fotos/descrição fracas fazem o hóspede escolher a King Spa pelo mesmo preço; (3) o piso R$ 800 com desconto de OTA rende R$ 400 e ainda assim não converte, o que confirma problema de canal ou conteúdo, não de preço.

### 3.5 Cancelamentos

59% dos registros cancelados. Mesmo descontando modificações, o volume é alto e concentrado na Booking. Cada cancelamento tardio devolve um quarto para venda com poucos dias de janela, exatamente onde vocês menos convertem. Mitigação: tarifa não reembolsável com 10% a 15% de desconto (o PriceLabs suporta planos tarifários por listing), política de cancelamento até 7 dias antes em vez de 1 dia, e pré-pagamento nas datas de alta.

---

## 4. Configuração recomendada, passo a passo

Ordem de aplicação. Colunas: conservador / equilibrado / agressivo em relação à meta de ocupação. A coluna "Equilibrado" é a minha recomendação de partida.

### 4.1 Nível grupo (Dynamic Pricing → Personalizações → Grupos → Recanto dos Moinhos)

| Configuração (nome em inglês) | Hoje | Conservador | **Equilibrado** | Agressivo |
|---|---|---|---|---|
| Fator de demanda (Demand Factor Sensitivity) | Aggressive | Recommended | **Recommended** | Moderately Conservative |
| Sazonalidade (Seasonality) | Aggressive | Recommended | **Recommended** | Moderately Conservative |
| Última hora (Last Minute Prices) | Market Driven Aggressive | Market Driven Balanced | **% Gradual −20% em 10 dias** | % Gradual −30% em 14 dias |
| Preço mínimo de última hora (Minimum Last Minute Price) | não definido | mín. atual | **mín. atual × 0,90 dentro de 7 dias** | mín. atual × 0,85 |
| Far-out (Far Out Prices) | Market Driven Balanced | igual | **igual** | igual |
| Piso far-out (Minimum Far-out Price) | não definido | — | **= preço base para 60+ dias** | idem |
| Dia da semana (Day of Week Adjustments) | desligado | desligado | **Sex −10%, Sáb −5%, Dom −5%** | Sex −15%, Sáb −10%, Dom −10% |
| Multi-Room Occupancy-Based Adjustment (POBA em modo hotel) | desligado | perfil Short Booking Window | **Custom (tabela abaixo)** | Custom com bandas −25% |
| Fator adjacente / Dias órfãos (Orphan Day Prices) | padrão −20% | "Sem ajuste" | **"Sem ajuste"** | — |
| Arredondamento (Rounding) | — | terminar em 9 | **terminar em 9** | idem |
| Safety Minimum Price | verificar | 100% do ADR do ano passado | **105%** | desligado |
| Compset de hotéis / Hotel Weights | Custom / Mostly Hotel | igual | **igual** | igual |

Por que Última hora em % Gradual e não Market Driven: o Market Driven é calibrado pelo mercado de aluguel de temporada, não pelos seus 30% a 50% de OTA. Com a tabela de ocupação por janela (abaixo) fazendo o trabalho pesado, a última hora vira um complemento previsível.

**Tabela sugerida para o Multi-Room Occupancy-Based Adjustment** (aplicar no grupo, "All Days"; valores em % sobre o preço recomendado; permitido de −50% a +500%):

| Dias até a data | Ocup. < 25% | 25–49% | 50–69% | 70–84% | 85–94% | ≥ 95% |
|---|---|---|---|---|---|---|
| 0 a 3 | −25% | −18% | −10% | 0% | +8% | +15% |
| 4 a 7 | −20% | −14% | −7% | 0% | +6% | +12% |
| 8 a 15 | −15% | −10% | −4% | 0% | +5% | +10% |
| 16 a 30 | −10% | −6% | 0% | +3% | +6% | +10% |
| 31 a 60 | −5% | 0% | +3% | +5% | +8% | +12% |

As bandas de ocupação são as suas metas convertidas em gatilhos: abaixo da meta da janela, desconta; acima, sobe. A regra do PriceLabs exige que cada banda tenha ajuste menor que a anterior e que as faixas de dias sejam crescentes. O ajuste nunca fura o preço mínimo.

### 4.2 Nível listing (Gerenciar propriedades / Revisar preços)

Preços base e mínimo. Os valores abaixo assumem a **Opção A** (promoções de OTA reduzidas para 10% a 20%). Se ficar com a Opção B, mantenha as bases atuais e aplique o offset por canal.

| Tipo de quarto | Base hoje → sugerida | Mín hoje → sugerido | Máx | Justificativa |
|---|---|---|---|---|
| Queen Spa (7) | 1.500 → 1.250 | 800 → 780 | 3.500 | 17% dos dias no piso; sem base recomendada ainda |
| Double Spa (7) | 1.600 → 1.400 | 900 → 850 | 3.500 | rec. 1.399 |
| Afrodite (1) | 2.000 → 2.000 | 1.500 → 1.400 | 6.000 | vende a 71%, só afrouxar piso |
| Queen Spa (2) | 1.700 → 1.450 | 850 → 850 | 3.500 | rec. 1.457 |
| Villa King Spa (7) | 1.600 → 1.400 | 1.000 → 950 | 3.500 | rec. 1.392 |
| Villa Balcony (6) | 1.400 → 950 | 800 → 700 | 3.000 | rec. 840; zero reservas |
| Villa King Spa (2) | 1.500 → 1.150 | 900 → 850 | 3.500 | rec. 1.114 |

Máximo em 5.000 a 10.000 é inofensivo, mas 3.500 evita picos irreais em feriados que espantam o hóspede de última hora.

Ajuste em passos: o PriceLabs recomenda mudar base em 5% a 10% por vez e esperar 1 a 2 semanas. Como a distância é grande e a meta é urgente, proponho fazer 60% do caminho agora e o restante em 10 dias.

### 4.3 Estadia mínima (Personalizações → Restrições de estadia)

| Regra | Hoje (observado) | Sugerido |
|---|---|---|
| Estadia mínima padrão (Default Min Stay) | 2 noites fim de semana | **1 noite todos os dias** |
| Última hora (Last-Minute Bookings) | — | **dentro de 14 dias: 1 noite**, inclusive sexta e sábado |
| Reserva distante (Far-Out Bookings) | — | 30+ dias: 2 noites sex/sáb apenas em feriados via perfil sazonal |
| Intervalos livres (Orphan Gap) | — | "Escolha um número" = 1 |
| Menor estadia permitida (Lowest Min Stay Allowed) | — | 1 |
| Check-in/check-out | — | sem restrição |

Hierarquia do PriceLabs: Substituição por data > Distante > Última hora > Padrão. Então a regra de 14 dias vence a padrão sem precisar mexer nas datas.

### 4.4 Planos tarifários e políticas (Beds24 + Booking)

- Criar plano **não reembolsável com −12%** e manter o flexível com cancelamento até 7 dias antes.
- Reduzir promoções empilhadas na Booking: manter Genius básico (10%), tirar "Oferta de última hora" da Booking (o PriceLabs já faz isso) e limitar Mobile Rate a 10%.
- Airbnb: desligar descontos semanal/mensal na plataforma. Descontos configurados no Airbnb sobrescrevem o PriceLabs.
- Verificar hoje a categoria Balcony no Beds24 e na Booking (ativa, mapeada, com fotos).

### 4.5 Sincronização

- Sync programado grátis: escolher 06:00 (horário local) para pegar reservas da madrugada.
- Adicionar sync extra às 14:00 (US$ 1/listing/mês) ou **Real-Time Sync** (US$ 2/unidade/mês, reage a reserva e cancelamento em até 60 min). Com 29% de reservas no mesmo dia e cancelamentos altos, o Real-Time Sync se paga com uma diária por mês.

---

## 5. Playbook de pacing: o que fazer quando a janela está abaixo da meta

Verificação diária (5 minutos, no chat do Claude com o conector ou no Multi Calendar):

| Situação | Ação |
|---|---|
| 7d abaixo de 70% e mercado 7d abaixo de 30% | deixar o MROBA agir; se ficar 2 dias sem pickup, DSO "% do preço recomendado" −10% nas datas específicas com expiração automática |
| 7d abaixo de 70% mas mercado 7d acima de 40% | problema de visibilidade, não de preço: checar ranking Booking, fotos, promoções; não baixar mais |
| 15d abaixo de 50% | conferir se estadia mínima está em 1 noite; ativar banda 8–15 dias mais forte por 1 semana |
| 30d abaixo de 35% | manter; revisar base se relação Rec/Base < 0,93 |
| 45d e 60d abaixo de 25/15% | não descontar; ativar Far-out Balanced e piso far-out para não queimar receita futura |
| Ocupação 7d acima de 85% | subir: MROBA já aplica +8% a +15%; conferir se máximo não está travando |
| Cancelamento grande a menos de 3 dias | DSO −15% na data por 48 h, expira sozinho |

Guardrail de receita: acompanhar RevPAR semanal. Se ocupação subir e RevPAR cair por 2 semanas seguidas, reduzir descontos das bandas 0–7 dias em 5 pontos.

---

## 6. Rotina com o Claude + conector MCP

Prompts prontos (cole no chat com o conector PriceLabs ligado):

1. "Liste a ocupação dos próximos 7, 15, 30, 45 e 60 dias de cada tipo de quarto e compare com as metas 70/50/35/25/15."
2. "Quais tipos de quarto estão abaixo de 70% nos próximos 7 dias? Mostre preço e estadia mínima de cada data."
3. "Mostre os preços dos próximos 14 dias da King Suite with Balcony com o preço sem personalização."
4. "Quais personalizações estão ativas no grupo Recanto dos Moinhos? Explique cada uma."
5. "Mude o Fator de Demanda do grupo para Recommended, mantendo compset e peso de hotéis."
6. "Mude a Sazonalidade do grupo para Recommended."
7. "Configure Última hora do grupo como % Gradual, −20%, começando 10 dias antes."
8. "Ative ajuste por dia da semana no grupo: sexta −10, sábado −5, domingo −5."
9. "Atualize o preço base da Villa King Balcony para 950 e o mínimo para 700."
10. "Crie substituição por data nas sextas e sábados dos próximos 21 dias da Queen Spa 7 unidades com estadia mínima 1."
11. "Crie substituição de −10% do preço recomendado para as datas X e Y, com motivo 'pickup fraco'."
12. "Remova as substituições de 25 a 27/09 da Queen Spa 7 unidades."
13. "Quais reservas foram feitas nas últimas 24 horas e por qual canal?"
14. "Diagnostique por que a King Suite with Balcony não recebe reservas."
15. "Compare a Villa King Spa 7 unidades com os concorrentes: preço futuro e ocupação do mercado."
16. "Gere um relatório de revisão da conta dos últimos 30 dias com ações recomendadas."
17. "Qual o pickup dos últimos 7 dias por tipo de quarto?"
18. "Atualize o preço para o próximo sábado em todos os quartos de 7 unidades para −15% do recomendado, expirando em 48 horas."

O que o conector **não** faz hoje e precisa ser feito na tela: Multi-Room Occupancy-Based Adjustment, regras de estadia mínima, Safety Minimum Price, offsets por canal, sync programado, planos tarifários. O conector cobre leitura completa, preços base/mín/máx, as seis personalizações (sazonalidade, última hora, far-out, dia da semana, fator de demanda, perfil sazonal) e substituições por data.

---

## 7. Mapa do PriceLabs para esta operação

| Área | O que faz | Impacto na meta de 7 dias |
|---|---|---|
| Hyper Local Pulse | algoritmo: 350 similares em até 15 km, pacing, eventos, dados de hotéis da Booking | alto |
| Base / Mín / Máx | âncora e limites; base = diária média anual | alto |
| Última hora | desconto conforme a data se aproxima (até 90 dias, sem degraus) | alto |
| Occupancy Based Adjustments | por listing, olha 60 dias, perfis Market-Driven / Aggressive / Step | médio (substituído pelo MROBA) |
| Multi-Room Occupancy-Based Adjustment | por tipo de quarto e por faixa de dias, −50% a +500% | **muito alto** |
| Estadia mínima dinâmica | padrão, última hora, distante, órfãos, hierarquia | **muito alto** (74% são 1 noite) |
| Dia da semana | −75% a +500% sobre a recomendação | alto |
| Sazonalidade / Fator de demanda | tom conservador ↔ agressivo | alto |
| Far-out e piso far-out | protege datas distantes | médio |
| Safety Minimum Price | piso = ADR do ano passado × 100–110% | médio |
| Substituições por data (DSO) | fixo, % do recomendado, % da base, mín/máx, estadia, expiração | alto (emergência) |
| Ajuste de preços por canal (offset) | +/−% ou fixo por conta/grupo | alto se mantiver promoções OTA |
| Hotel Rate Shopper / Compset | até 350 hotéis da Booking, match de tipo de quarto | médio |
| Market Dashboards | ocupação futura, pacing, janela de reserva, LOS do mercado | médio |
| Portfolio Analytics / Report Builder | pacing, pickup, RevPAR, STLY | alto (medição) |
| Central de Ações | alertas e nudges de preço | médio |
| Listing Optimizer | nota A–D do anúncio Airbnb, ranking | baixo aqui (Booking domina) |
| Sync / Real-Time Sync | 1×/dia grátis; até 24×/dia por webhook | alto |
| API / MCP | leitura e escrita por chat | alto (rotina) |

---

## 8. Sequência sugerida de implantação

| Dia | Ação |
|---|---|
| Hoje | Checar Balcony no Beds24/Booking · Fator de demanda e Sazonalidade → Recommended · Estadia mínima 1 noite (padrão e última hora 14 dias) · DSO estadia 1 nos fins de semana das próximas 3 semanas · Remover DSO manuais de 25–27/09 |
| Dia 1–2 | Decidir Opção A ou B para promoções OTA · Aplicar bases e mínimos (60% do caminho) · Ativar MROBA com a tabela · Dia da semana Sex −10 Sáb −5 Dom −5 |
| Dia 3 | Sync 06:00 + Real-Time Sync · Plano não reembolsável −12% |
| Dia 7 | Revisar pickup e RevPAR; ajustar bandas 0–7 dias em ±5 pontos |
| Dia 10 | Segundo passo de base (restante dos 40%) se relação Rec/Base ainda < 0,93 |
| Dia 14 | Revisão completa: ocupação por janela vs metas; consolidar |

---

## 9. Riscos e armadilhas

- **Lotar a 100% com RevPAR menor.** Se as bandas descontarem demais, a meta de ocupação é atingida e a receita cai. O guardrail é o RevPAR semanal e o piso calculado sobre o líquido.
- **Ensinar o hóspede a esperar.** Descontos previsíveis nos últimos 3 dias criam o hábito. Mitigação: bandas por ocupação (só desconta quando está vazio) em vez de desconto fixo por data.
- **Preço fixo em DSO fura o mínimo.** Usar "% do preço recomendado", que respeita o piso.
- **Descontos no Airbnb sobrescrevem o PriceLabs.** Desligar semanal/mensal na plataforma.
- **Booking só atualiza se a diferença for de US$ 5 ou mais** (Delta Push). Ajustes de 1% a 2% podem não chegar.
- **Datas bloqueadas contam como ocupadas** no cálculo de ocupação. Não bloquear quartos para "guardar".
- **Desligar uma personalização não é "sem ajuste".** O algoritmo assume o padrão de mercado. Para anular, escolha "Nenhum" com a chave ligada.

---

## 10. Fontes

- Conta PriceLabs via conector MCP (get_listings, get_listing_data, get_listing_performance_metrics, get_listing_prices, get_customizations, get_customization_schema, get_neighbourhood_data, get_pms_reservations, get_listing_date_overrides), 25/09/2026.
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
- https://help.pricelabs.co/portal/en/kb/articles/guide-for-using-both-airbnb-and-booking-with-pricelabs
- https://help.pricelabs.co/portal/en/kb/articles/account-group-customization
- https://help.pricelabs.co/portal/en/kb/articles/performance-metrics
- https://help.pricelabs.co/portal/en/kb/articles/pricing-recipe-using-booking-curves-a-step-by-step-guide
- https://hotels.pricelabs.co/blog/guide-to-hotel-dynamic-pricing/
- https://hotels.pricelabs.co/blog/7-pricelabs-customization-settings-every-small-hotel-must-use/
- https://help.pricelabs.co/portal/pt/kb/articles/dados-de-hot%C3%A9is
- https://developers.pricelabs.co/mcp/overview e https://developers.pricelabs.co/mcp/tools/overview

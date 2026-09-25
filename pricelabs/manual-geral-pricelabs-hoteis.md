# Playbook PriceLabs para hotéis: ocupação mínima de 70% e alvo de 100% na janela de 7 dias

> Guia prático para um grupo hoteleiro brasileiro (várias propriedades, vários tipos de quarto) que quer usar o PriceLabs, com a interface em português e o conector MCP do Claude, para garantir pelo menos 70% de ocupação nos próximos 7 dias e buscar 100% sem destruir a diária média (ADR) e o RevPAR.
>
> Convenção: o nome da configuração aparece em português com o nome em inglês entre parênteses, por exemplo "Preços de última hora (Last Minute Prices)". Onde a tradução oficial não foi confirmada nas fontes, o nome em inglês é mantido e marcado como "a confirmar na sua conta".

> **Leia antes: como este manual se relaciona com o relatório da Pousada Recanto dos Moinhos e da Villa Dolce Amore.**
>
> Este é o manual geral do PriceLabs para hotéis. Ele foi escrito antes de conhecermos três fatos da sua operação. Por isso, onde houver conflito, **vale o `relatorio-ocupacao-recanto-dos-moinhos.md`**, que usa os dados reais da conta.
>
> 1. **Os descontos das OTAs são fixos.** Ignore as recomendações da seção "Canais e visibilidade" que mudam Genius, tarifa mobile, promoções do Airbnb ou planos não reembolsáveis. Ignore também a meta de desconto total por noite de até 30%. O desconto medido na Booking fica entre 51% e 59%.
> 2. **O PMS é o Beds24.** O Real-Time Sync não está disponível para ele. Use sincronizações programadas.
> 3. **Preços base, mínimo e máximo, tabela de ocupação e regras de fim de semana:** use os valores do relatório, que já consideram o desconto das OTAs.
>
> O resto do manual vale como referência: funcionamento do algoritmo, mapa de recursos, hierarquia das personalizações, limites do conector, rotina diária e armadilhas.

### Glossário PT/EN (rótulos verificados na central de ajuda em português do PriceLabs)

| Rótulo em português (interface / KB PT) | Nome em inglês |
|---|---|
| Painel de Preços | Pricing Dashboard |
| Revisar preços / Calendário de Preços | Review Prices / Pricing Calendar |
| Multicalendário | Multi Calendar |
| Preço base / Preço mínimo / Preço máximo | Base Price / Minimum Price / Maximum Price |
| Preços de sincronização (Sincronizar preços) / Sincronizar já / Última sincronização | Sync Prices / Sync Now / Last Synced |
| Personalizações > Editar > Todas as personalizações > Salvar personalizações | Customizations > Edit > All Customizations > Save |
| Predefinições Inteligentes / Aplicado atualmente | Smart Presets / Currently Applied |
| Grupos / Subgrupos | Groups / Sub Groups |
| Adicionar Métricas | Add Metrics (Performance Metrics) |
| Alertas de preço base | Base Price Nudge |
| Mapear propriedades / Configurações da tabela | Map Listings / Table Preferences |
| Ajustes baseados na ocupação de múltiplos quartos | Multi-Room Occupancy-Based Adjustment (MROBA) |
| Ajustes baseados na ocupação do portfólio | Portfolio Occupancy Based Adjustment |
| Janela de reserva curta / média / longa / Personalizado / Nenhum | Short / Medium / Long Booking Window / Custom / None |
| Ajustes Baseados na Ocupação | Occupancy Based Adjustments (OBA, nível do anúncio) |
| Preços de última hora (Preços para reservas de última hora) | Last Minute Prices |
| Preços distantes / Acréscimo para reservas distantes | Far Out Prices / Far-Out Premium |
| Preço mínimo distante | Minimum Far Out Price |
| Preços dos Dias Órfãos (Preços para dias órfãos) / Sem ajuste de dia órfão | Orphan Day Prices / No Orphan Day Adjustment |
| Personalização de preços em dias adjacentes (Fator adjacente) | Adjacent Factor |
| Ajustes de preços do dia da semana | Day of Week Pricing Adjustments |
| Defina o seu próprio fim de semana | Weekend Days |
| Preço mínimo de fim de semana | Minimum Weekend Price |
| Sensibilidade ao fator de demanda | Demand Factor Sensitivity |
| Personalização da fonte de dados sobre preços ("Principalmente hotéis") | Customize Pricing Data Source / Hotel Weights ("Mostly Hotel") |
| Personalização dos grupos concorrentes de hotéis ("Padrão do PriceLabs") | Customize Hotel Comp Set ("PriceLabs Default") |
| Sazonalidade | Seasonality Factor Sensitivity |
| Fator por Poucas Reservas | Booking Recency Factor |
| Preço Mínimo de Segurança | Safety Minimum Price |
| Preços personalizados de temporada (Perfis sazonais personalizados) | Custom Seasonal Profile |
| Estadia mínima dinâmica (Regras dinâmicas de estadia mínima) | Minimum Stay Settings |
| Substituições manuais para datas específicas | Date-Specific Overrides (DSO) |
| Compensações de preços para listagens mapeadas | Pricing Offsets for mapped listings |
| Arredondamento | Rounding |
| Dados da Vizinhança / Dados de hotéis | Neighborhood Data / Hotel Data |
| Análises de portfólio | Portfolio Analytics |
| Conector de IA (MCP) | AI Connector (MCP) |
| A confirmar na sua conta: rótulo PT de Intra-Day Multi-Room OBA; "Central de Ações" para Action Center (não verificado na KB PT); nomes dos cartões do Listing Optimizer | Intra-Day Multi-Room Occupancy-Based Adjustments; Action Center; Listing Optimizer score cards |

---

## Resumo executivo

As 10 decisões que mais pesam para sair de 70% e chegar perto de 100% de ocupação na janela de 7 dias:

1. **Um tipo de quarto = um anúncio (listing) e um Grupo (Group) por hotel.** Todas as regras de ocupação vivem no nível do grupo; o nível do anúncio só guarda Preço base / mínimo / máximo. Assim a ocupação que dirige o preço é a ocupação real do hotel.
2. **Preço mínimo (Minimum Price) explícito por tipo de quarto, nunca em branco.** Em branco, o PriceLabs usa 30% abaixo do base. Para meta de ocupação use 55-75% do base (piso = custo variável por quarto ocupado + comissão de OTA + margem) e adicione um piso mais baixo só para a janela curta com Preço mínimo de última hora (Minimum Last Minute Price, por solicitação ao suporte).
3. **Ajustes baseados na ocupação de múltiplos quartos (Multi-Room / Portfolio Occupancy-Based Adjustment) com perfil Custom ancorado em 0-3 e 4-7 dias.** O padrão de hotel ("Medium Booking Window") mira 50% de ocupação entre 16 e 30 dias, o que não serve para uma meta medida em 7 dias.
4. **Sincronização mais de uma vez por dia.** A sincronização padrão é uma por noite; com janela de 7 dias, um preço decidido às 9h só chega ao canal na madrugada seguinte. Use Timed Sync + 1-2 sincronizações extras (US$ 1/anúncio/mês cada) ou Real-Time Sync (US$ 2/unidade/mês) se o PMS for suportado.
5. **Estadia mínima de 1 noite dentro de 7 dias e "Lowest Minimum Stay Allowed" = 1.** Nenhuma noite dentro da janela pode ficar bloqueada por regra de 2 noites.
6. **Preços de última hora (Last Minute Prices): "No last minute adjustment" no cenário equilibrado** (recomendação oficial para hotéis, porque o MROBA já faz o trabalho) ou "% Gradual" 20-25% em 7 dias no cenário agressivo, sempre com piso. Nunca "Fixed" (fura o preço mínimo).
7. **Fator por Poucas Reservas (Booking Recency Factor) ligado, Preço Mínimo de Segurança (Safety Minimum Price) em 100% ou desligado**, senão a ADR do ano passado bloqueia os descontos de que a janela curta precisa.
8. **Fonte de dados de hotéis (Pricing Data Source / Hotel Weights) em "Mostly Hotel" (90%) ou "Fully Hotel", com comp set curado de 8-10 hotéis na aba Dados de hotéis (Hotel Data).** Sem isso o algoritmo compara seu hotel com apartamentos do Airbnb que reservam com 30+ dias.
9. **Rotina diária de 10 minutos:** Central de Ações (Action Center) zerada, Multicalendário ordenado por "Ocupação total próximos 7 dias", pickup dos últimos 7 dias, "Datas com preço mínimo" e uma leitura da aba Dados de hotéis. Rotina semanal com Pacing Reports e Report Builder.
10. **Meta medida em RevPAR, não só ocupação.** 95% de ocupação com diária destruída pode reduzir a margem; se a ocupação sobe e o RevPAR cai por duas semanas seguidas, reduza cada faixa de desconto em 5 pontos.

---

## Como o PriceLabs precifica

**Ordem de cálculo (7 etapas, fixa):**

1. **Preço base (Base Price)**: a diária média que você cobraria ao longo do ano por aquele tipo de quarto. Tudo o mais é percentual sobre ele.
2. **Sazonalidade (Seasonality)**: curva hiperlocal no algoritmo Hyper Local Pulse (HLP). Sensibilidade ajustável em 6 níveis; padrão para hotéis = "Conservative".
3. **Fator de demanda (Demand Factor)**: dia da semana, eventos e feriados. Sensibilidade ajustável em 6 níveis; padrão = "Recommended".
4. **Fator de ritmo (Pacing Factor)**: em alguns mercados, quando a demanda projetada diverge do histórico.
5. **Personalizações (Customizations) e substituições por data (Date-Specific Overrides)**: última hora, distantes, dias órfãos, ajustes por ocupação, dia da semana etc.
6. **Preço mínimo e máximo**: limites rígidos. Exceções documentadas que conseguem furar o mínimo: substituição por data com preço fixo (Fixed DSO), Perfil sazonal personalizado, preço de última hora Fixo, Pricing Offset e descontos semanais/mensais. Todo desconto percentual (última hora %, ajustes por ocupação, Fator por Poucas Reservas) para no mínimo.
7. **Ajustes finais**: Pricing Offset é aplicado por último e pode ultrapassar mínimo e máximo. Arredondamento (Rounding) também é aplicado no fim, mas respeita mínimo e máximo.

**Padrões de segurança:** Preço mínimo em branco = Base x 0,70; Preço máximo em branco = Base x 10.

**Mercado e algoritmo:** o HLP define o mercado como cerca de 350 anúncios similares num raio máximo de 15 km e escolhe o preço que maximiza a receita esperada (preço x probabilidade de reserva). Ele acompanha ritmo (pacing) e pickup contra datas de referência, e recalcula diariamente os padrões "Market Driven" de última hora e de preços distantes. O desconto de última hora "Market Driven" tem teto de 40% e só atua até 60 dias antes; o prêmio de preços distantes tem teto de 20% a partir de 60 dias. Importante: como o algoritmo otimiza receita esperada e não ocupação, um hotel com KPI de ocupação precisa enviesá-lo de propósito (base e mínimo mais baixos, ajustes por ocupação mais agressivos). A pesquisa do próprio PriceLabs alerta que preços extremamente baixos reduzem conversão (preço sinaliza qualidade), então não vá mais de ~20% abaixo do percentil 25 do mercado.

**Como a sua ocupação entra no preço:**

- **Ajustes Baseados na Ocupação (Occupancy Based Adjustments, OBA)** no nível do anúncio: usam a ocupação daquele anúncio (para multiunidade, noites vendidas / noites disponíveis, ex.: 8 quartos x 2 noites = 16, 11 vendidas = 69%). Perfil padrão "Market-Driven": janela de 60 dias, desconto até 20%, prêmio até 15%, comparado com a ocupação do mercado. Perfil "Aggressive": desconto até 30%. Para hotéis, o PriceLabs recomenda desligar este e usar o de múltiplos quartos.
- **Ajustes baseados na ocupação do portfólio / de múltiplos quartos (Portfolio OBA / MROBA)**: usam a ocupação diária do tipo de quarto ou do grupo (média entre os anúncios do grupo naquela data). Tabela de janelas de dias x faixas de ocupação x % (de -50% a +500%). Só anúncios com sincronização ligada entram na conta; datas bloqueadas contam como ocupadas, exceto em dezenas de integrações listadas no artigo de Occupancy Based Adjustments (a lista muda com o tempo; confira se o seu PMS está nela). Precisa de 2+ quartos, funciona bem com 5+.
- **Fator por Poucas Reservas (Booking Recency Factor)**: ligado por padrão; desconto automático de 5% (15 dias sem reserva) a 15% (45 dias), só nos próximos 30 dias, quando a ocupação está abaixo de 10% ou abaixo de 80% do mercado e de 70% no total. Nunca fura o mínimo.
- **Preço base recomendado / Alertas de preço base (Recommended Base Price / Base Price Nudge)**: após 7 dias de sincronização estável (14-21 para anúncios novos), o PriceLabs compara sua ADR, ocupação e avaliações com o mercado numa janela de 60 dias e sugere alterar o base quando a diferença passa de 7% (o artigo em português diz 5%: a confirmar na sua conta). Nunca aplica sozinho.
- **Saúde / métricas (Performance Metrics)**: atualizadas uma vez a cada 24h; "Salvar e atualizar" (Save and Refresh) força a atualização do lado do anúncio, não do mercado.

---

## Mapa completo do PriceLabs

Impacto na ocupação de 7 dias: **alto / médio / baixo**.

### Dynamic Pricing (Painel de Preços)

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| Preço base (Base Price) e ferramenta "Help me choose a base price" | Âncora anual de todo o cálculo; a ferramenta usa hotéis do Booking.com com percentil 25 (Economy), 50 (Midscale) ou 75 (Upscale), filtro por estrelas, mínimo de 20 comparáveis | alto |
| Preço mínimo / máximo (Minimum / Maximum Price) | Piso e teto; edição individual, em massa por CSV (Manage Listings) ou no Multicalendário | alto |
| Preços de sincronização (Sync Prices) e Sincronizar já (Sync Now) | Liga o envio ao PMS; Sync Now empurra em até 15 min | alto |
| Última sincronização (Last Synced) | Auditoria de que o preço chegou ao canal | médio |
| Revisar preços / Calendário de preços (Review Prices / Pricing Calendar) | Passar o mouse mostra fator de demanda, oferta, ajustes e restrições; cores de demanda, comparação com ADR do ano anterior e recência de reservas | alto |
| Multicalendário (Multi Calendar) | Visão de todos os tipos de quarto, filtros salvos (Quick Filters), ações em massa (Save and Refresh, Sync Now, Apply Overrides), métricas por período personalizado | alto |
| Adicionar Métricas (Add Metrics / Performance Metrics) | Ocupação total/ajustada (próx. 7/30 dias), MPI, Datas com preço mínimo, Booking Pickup, Nights Booked, Goal Completion %, razão base recomendado/base | alto |
| Alertas de preço base / mínimo (Nudges) | Sugestão de base (>7% de diferença entre base atual e recomendado) e de mínimo (pelo menos 21 noites disponíveis nos próximos 30 dias no preço mínimo E mínimo sem alteração nos últimos 10 dias -> sugere -5%). Não aparecem se houver perfil sazonal (base) ou mínimos de fim de semana / distante / sazonal em nível de grupo ou conta (mínimo) | alto |
| Grupos e Subgrupos (Groups / Sub Groups) | Regras compartilhadas por hotel; subgrupos por solicitação ao suporte | alto |
| Mapear propriedades (Map Listings, pai/filho) | Mesmo quarto em vários canais ou quartos idênticos; filhos herdam base/mín/máx e personalizações | médio |
| Planos tarifários (Rate Plan Customization) | Plano padrão + ajustes fixos/% por plano; tipo de atualização Price and Restrictions / Price / None | alto |
| Algorithm Version / migração para HLP | Verifica se cada anúncio está no Hyper Local Pulse | alto |
| Predefinições Inteligentes / Tipo de propriedade (Smart Presets / Property Type = Hotels) | Aplica os padrões de hotel automaticamente; "Apart-Hotels" é um tipo de propriedade separado de "Hotels"; conferir o resultado na aba "Aplicado atualmente" (Currently Applied) | alto |

### Customizations (Personalizações)

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| Ajustes baseados na ocupação de múltiplos quartos / do portfólio (Multi-Room / Portfolio OBA) | Desconto/prêmio pela ocupação real do tipo de quarto ou do hotel por janela de dias | alto |
| Intra-Day Multi-Room OBA (a confirmar o nome em PT) | Preços diferentes por horário para hoje (Dia 0) e amanhã (Dia 1); exige 1-2 sincronizações extras | alto |
| Ajustes Baseados na Ocupação (OBA, nível do anúncio) | Versão mono-anúncio; para hotéis, "No Occupancy Based Adjustment" | médio |
| Preços de última hora (Last Minute Prices) + Minimum Last Minute Price | Desconto por proximidade da chegada (Market Driven, % Flat, % Gradual, Fixed, None); piso próprio da janela curta | alto |
| Preços distantes (Far Out Prices) + Preço mínimo distante (Minimum Far Out Price) | Prêmio para datas longe; piso separado para 90+ dias | baixo |
| Preços dos Dias Órfãos (Orphan Day Prices) | Desconto padrão de 20% em vãos de 1-2 noites; para hotéis, "No Orphan Day Adjustments" | baixo |
| Fator adjacente (Adjacent Factor) | Ajuste 1-30 dias antes/depois de reservas; conceito de calendário único | baixo |
| Ajustes de preços do dia da semana (Day of Week Pricing Adjustments) | -75% a +500% por dia; no HLP recomenda-se 0 | médio |
| Sensibilidade ao fator de demanda (Demand Factor Sensitivity) + Hotel Weights + Hotel Compsets | 6 níveis; fonte hotel vs STR (0/10/50/90/100%); comp set padrão (10 mais próximos) ou selecionado | alto |
| Sazonalidade (Seasonality Factor Sensitivity) | 6 níveis; "Conservative" para hotéis | baixo |
| Fator por Poucas Reservas (Booking Recency Factor) | Desconto automático 5-15% quando não há reservas há 15+ dias | alto |
| Preço Mínimo de Segurança (Safety Minimum Price) | Piso = ADR do mesmo dia do ano passado x 1,1 (ajustável); precisa de receita por noite vinda do PMS | alto (pode bloquear) |
| Preços personalizados de temporada (Custom Seasonal Profile) + Pricing Profiles | Mín/máx por temporada; perfis de personalizações por temporada (por solicitação) | médio |
| Preço mínimo de fim de semana / de evento / de dia órfão / de última hora (Advanced Minimum Price Settings) | Pisos contextuais; só Minimum Orphan Day Price e Minimum Last Minute Price são por solicitação; Minimum Event Price = mínimo por data (DSO) | médio |
| Estadia mínima (Minimum Stay Settings: Default, Last-Minute, Far-Out, Orphan Gaps, Adjacent, Lowest Minimum Stay Allowed) | Restrições dinâmicas de noites; hierarquia fixa | alto |
| Check-in/Check-out (Check In/Check Out restrictions + perfis) | Dias de chegada/saída permitidos | médio (negativo se ligado) |
| Length of Stay Pricing | % por número de noites (>=N); depende do PMS (Stays sim, Cloudbeds não) | baixo |
| Descontos semanais/mensais (Weekly / Monthly Discounts) | 0-75% para 7+/28+ noites; podem furar o mínimo | baixo |
| Pricing Offset | Ajuste final -40 a +500; fura mínimo/máximo; uso só para comissão de canal | baixo (perigoso) |
| Taxa de hóspede extra (Extra Person Fee) | Fixa ou % (só na noite do check-in); depende do PMS | baixo |
| Arredondamento / Suavização (Rounding / Smoothing) | Terminação 9 ou 0; suavização achata descontos diários (deixar off) | baixo |
| Substituições manuais para datas específicas (Date-Specific Overrides) | Preço fixo, % do recomendado, % do base, mín/máx, estadia mínima, check-in/out por data; expiração automática | alto |
| Hierarquia de personalizações | Anúncio > Subgrupo > Grupo > Conta; Fixed DSO > última hora > órfão; entre descontos vale o maior, prêmios somam | alto |

### Market Research (Pesquisa de mercado)

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| Dados da Vizinhança (Neighborhood Data) | Preços futuros por percentil (25/50/75/90), Market Booked Price, ocupação futura com "Last Year Today/Final" e "Add Pickup" (7 dias), histórico, calendário de até 10 concorrentes; ocupação atualizada a cada 2 dias, tarifas a cada 5 | alto |
| Dados de hotéis / Hotel Rate Shopper (Hotel Data) | Tarifas de até 350 hotéis do Booking.com, atualização a cada 24h, correspondência de tipo de quarto, filtros LOS 1-4, reembolsável, café; variação em 48h | alto |
| Market Dashboards | KPIs de mercado (7/30/365 dias), ocupação futura + pickup + cancelamentos, Key Future Dates, curva de reservas, LOS vs janela de reserva, dia da semana; só dados Airbnb/Vrbo; US$ 9,99-39,99/mês; 1 crédito grátis; limite de até 30 comp sets por dashboard com até 2.000 anúncios cada; local e moeda não mudam depois de criado | médio |
| AI Insights (Neighborhood Data) | Resumo em texto dos gráficos | baixo |
| Revenue Estimator Pro | Receita/ADR/ocupação por endereço (percentis 25/50/75); benchmark, não operação | baixo |

### Portfolio Analytics (Análises de portfólio)

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| KPIs & Historic Reports | Receita, RevPAR, ocupação, ADR por data de estadia ou de reserva; canais; Listing Level Metrics (ocupação próx. 30 dias x dias desde a última reserva) | médio |
| Pacing Reports (Pacing, Booking Curves) | Ritmo vs mesmo dia do ano passado e vs mercado; curvas por "dias até a data" | alto |
| Report Builder | 12 modelos + modelos de hotel (Hotel KPIs On The Books, Hotel Pickup Trends com janelas de 3/7/30 dias), ~90 métricas, fórmulas Excel, AI Insight, agendamento de e-mail; limite de 1 relatório customizado a cada 5 anúncios sincronizando (ex.: 20 tipos de quarto = 4 relatórios próprios) | alto |
| Bookings Report | Lista de reservas por data de reserva/estadia e canal | médio |
| Goals / Goal Tracker / Goal Completion % | Metas mensais de ocupação, ADR, RevPAR, receita | médio |
| Owner Analytics (beta) | PDF white-label por hotel com meta de receita | baixo |
| Refresh manual de reservas | Permitido quando a última atualização tem mais de 12h | médio |

### Action Center (Central de Ações, nome em PT a confirmar)

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| Alertas de configuração (OBA turned off, Last-minute conservative vs market, Minimum stay higher than market) | Disparam quando a ocupação próxima está fraca; contas com ~50 anúncios ou menos | alto |
| Alertas de disponibilidade (>3 datas bloqueadas em 12 meses, >3 datas não reserváveis) | Inventário escondido por restrições | alto |
| Recomendações de preço (base/mínimo) com aceite em massa | Mesmo conteúdo dos nudges | alto |
| Informação faltante (base, localização, quartos) | Pré-requisitos do algoritmo | médio |

### Listing Optimizer

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| Notas A-D por componente (Imagens peso 1,5; Amenidades, Estrelas, Guest Favorites, Reviews, Título, Consistência 1,0; Descrição e Resumo de reviews 0,5) | Qualidade do anúncio no Airbnb; reescritas por IA só de título/descrição | médio (só Airbnb) |
| Ranking Overview | Posição e página na busca do Airbnb nos próximos ~60 dias, atualização ~48h | médio |
| Top Performer Insights | Comparação com os melhores da vizinhança | baixo |
| Assinatura US$ 12,99 -> 3,99/anúncio/mês, 4 reexecuções por ciclo | Custo | - |

### Sync / DSO (Sincronização e substituições)

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| Sincronização noturna padrão (23:00-13:00 GMT) | 1x/dia, grátis | alto (limitante) |
| Timed Sync ("Specify Your Own Time") + sincronizações adicionais | Horário local; extras a US$ 1/anúncio/mês, até 3/dia | alto |
| Real-Time Sync | Webhooks do PMS; até 24/dia, máx. 1 por 60 min; US$ 2/unidade/mês; 22 PMS suportados | alto |
| Date-Specific Overrides (anúncio e grupo, CSV, expiração) | Correções cirúrgicas por data | alto |
| Pricing Logs (1 ano) e Account Logs (quem mudou o quê) | Auditoria | médio |

### Integrations (Integrações)

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| PMS / channel managers hoteleiros confirmados (Cloudbeds, Mews, Apaleo, Octorate, RoomRaccoon, Staah, Beds24, Stays.net, entre 160+) | Envio de tarifas, estadia mínima e restrições | alto |
| Booking.com direto | Cada tipo de quarto vira um anúncio; só planos "Standard"; Delta Push de US$ 5; 720 dias sob pedido | alto |
| Airbnb oficial | Tarifas, estadia mínima e check-in/out até 540 dias; Smart Pricing tem de estar OFF | médio |
| PMS brasileiros (Omnibees, HSystem, Desbravador, CM Net/TOTVS, Hospedin, Silbeck) | Não aparecem nas listas de integração: a confirmar com o suporte | crítico |

### API / MCP

| Recurso | Para que serve | Impacto 7 dias |
|---|---|---|
| Conector MCP (https://mcp.pricelabs.co/mcp) | Claude lê métricas, reservas, vizinhança, ações, nudges e escreve base/mín/máx, DSOs, 6 famílias de personalizações | alto |
| Customer API (US$ 1/anúncio/mês, 60 req/min) | Automação própria; ponte para PMS não integrado | médio |
| Report Builder via MCP (templates + dados) | Relatórios de pickup no chat | médio |

---

## Configuração ideal para a meta de 7 dias

Aplique na ordem abaixo, um hotel por vez. Depois de cada etapa: "Salvar e atualizar" (Save and Refresh) e "Sincronizar já" (Sync Now). Segure as configurações por 7 dias antes da próxima alteração (as métricas atualizam diariamente, as recomendações semanalmente).

### Passo 0: pré-requisitos

| Item | Onde | Valor |
|---|---|---|
| Tipo de propriedade (Property Type) | Manage Listings > coluna Property Type (edição em massa) | Hotels (Independent Hotels ou Group Hotels) para todo tipo de quarto. Atenção: "Apart-Hotels" é um tipo separado de "Hotels"; escolha o que corresponde à operação |
| Verificação do preset | Revisar preços > Personalizações > Editar > aba "Aplicado atualmente" (Currently Applied) | Conferir, por tipo de quarto, que os padrões de hotel realmente foram aplicados (Mostly Hotel, Conservative, MROBA Medium, Booking Recency ON, Last Minute/Far Out/Orphan/OBA em "No ..."); se algum campo estiver diferente, o preset não pegou e precisa ser aplicado manualmente |
| Algoritmo | Painel de Preços > Row/Column Visibility > Algorithm Version | Todos em Hyper Local Pulse; migrar os que estiverem no Old Algorithm antes de qualquer ajuste |
| Grupos | Dynamic Pricing > Customizations > Groups > Create Group; Manage Listings > Assign Group | 1 grupo por hotel com todos os tipos de quarto; subgrupo "Suítes" (pedir ao suporte) se as suítes tiverem ritmo diferente |
| Tags | Manage Listings | hotelX, std, sup, suite |
| Preços de sincronização | Manage Listings | ON em 100% dos tipos de quarto (anúncio com sync OFF some do cálculo de ocupação do grupo) |

### Passo 1: Preço base (Base Price)

| Cenário | Valor por tipo de quarto | Como |
|---|---|---|
| Conservador | ADR realizada dos últimos 90 dias, excluindo feriados e picos, líquida de impostos | Revisar preços > "Help me choose a base price" > Custom |
| Equilibrado | Percentil do mercado correspondente ao posicionamento (Economy = 25, Midscale = 50, Upscale = 75), filtro de estrelas correto | Mesma ferramenta > Market-Based Price |
| Agressivo | 5-10% abaixo do valor equilibrado enquanto a ocupação de 7 dias estiver abaixo de 70% | Reduzir em passos de 5-10% e esperar 1-2 semanas |

Raciocínio: todo percentual é calculado sobre o base. Base de alta temporada faz o algoritmo aplicar sazonalidade duas vezes. Risco do agressivo: ADR estrutural mais baixa em datas que venderiam de qualquer jeito; revisite a cada 2-3 semanas usando a métrica "Recommended BP to BP Ratio" (abaixo de 0,93 com ocupação < 70% = reduzir base; acima de 1,10 com ocupação > 90% = subir 5-10%).

### Passo 2: Preço mínimo e máximo

| Ajuste | Conservador | Equilibrado | Agressivo |
|---|---|---|---|
| Preço mínimo (Minimum Price) | Base x 0,75 (regra de 20-30% abaixo do base) | Base x 0,65 | Base x 0,55-0,60, nunca abaixo de (custo variável por quarto ocupado x 1,15) / (1 - comissão OTA) |
| Minimum Last Minute Price (0-7 dias; pedir ao suporte se não aparecer) | = mínimo normal | Base x 0,60 | Base x 0,50-0,55 |
| Preço mínimo distante (Minimum Far Out Price, reservas 90+ dias; fica em Far Out Prices > Set Minimum Far Out Prices e NÃO é por solicitação; os únicos pisos por solicitação são Minimum Orphan Day Price e Minimum Last Minute Price) | Base | Base | Base x 0,95 |
| Preço mínimo de fim de semana (Minimum Weekend Price) | Mínimo + 15% | Mínimo + 10% se sex/sáb já rodam > 80% | sem piso extra |
| Preço máximo (Maximum Price) | 2,5-3x base | 2,5-3x base | 3x base |

Exemplo: base R$ 400, custo variável R$ 60, comissão 18% -> piso de custo = 60 x 1,15 / 0,82 = R$ 84; mínimo equilibrado R$ 260; Minimum Last Minute Price R$ 240. Risco do agressivo: um quarto colado no mínimo por muito tempo reduz visibilidade nas OTAs (aviso oficial, sem número de dias) e ensina o mercado a esperar. Regra de controle: "Datas com preço mínimo" (Minimum Price Hitting Dates) nos próximos 7 dias deve ficar abaixo de 30%; se passar de 50% com ocupação < 70%, o piso, e não o algoritmo, está travando a venda.

**Aviso importante sobre o piso em dois níveis (não documentado).** O desenho acima (mínimo normal = base x 0,65 e um Minimum Last Minute Price mais baixo para 0-7 dias) supõe que o MROBA, o Fator por Poucas Reservas e as faixas de -20%/-25% conseguem descer até o Minimum Last Minute Price dentro da janela curta. Nenhuma página oficial diz isso: os artigos de OBA e Portfolio OBA afirmam apenas que os preços "nunca ficam abaixo do seu preço mínimo", e o Minimum Last Minute Price é documentado só como piso "para os seus dias de última hora" dentro do bloco Last Minute Prices, sem dizer se vale quando esse bloco está em "No last minute adjustment". Se o piso que o MROBA respeita for o Minimum Price do anúncio, as faixas de -20%/-25% do cenário equilibrado serão cortadas em base x 0,65 e nunca executarão em tipos de quarto de ADR baixa.

Alternativa verificável e que o MCP consegue escrever hoje: **substituições de preço mínimo por data (date-specific Minimum Price)** nos próximos 7 dias. Via Claude, `update_listing_date_overrides` ou `update_group_date_overrides` aceitam, por data, `min_price` + `min_price_type` (`fixed`, `percent_base` = % do base ou `percent_min` = % do mínimo) e `lead_time_expiry` (1-999 dias). Exemplo de grupo: para as 7 próximas datas, `min_price = -20`, `min_price_type = percent_min`, `lead_time_expiry = 1` -> o piso daquelas datas cai 20% e a substituição se apaga sozinha depois da estadia. Isso fixa o piso das datas independentemente do Minimum Last Minute Price (que é por solicitação e de comportamento não documentado). Na interface: calendário > selecionar datas > Substituição por data > Preço mínimo.

Protocolo de teste único (30 minutos, uma vez por conta) para saber qual piso manda:

1. Escolha um tipo de quarto de teste com ocupação baixa numa data D+2 e anote Minimum Price, Minimum Last Minute Price e o preço recomendado (`get_listing_prices`).
2. No perfil MROBA custom, coloque -25% na faixa 0-3 dias / < 50% (ou use uma DSO "% of Recommended Price" -25% só nessa data).
3. Save and Refresh; leia de novo `get_listing_prices` e passe o mouse na data no calendário (o balão mostra "min/max constraints").
4. Se o preço parou em Minimum Price, o Minimum Last Minute Price não é o piso do MROBA: use substituições de preço mínimo por data (acima) para a janela de 7 dias. Se parou em Minimum Last Minute Price, o desenho em dois níveis funciona e você pode mantê-lo.
5. Desfaça o teste e registre o resultado no playbook do hotel.

**Nudges e pisos não padrão.** O nudge de preço mínimo (sugestão de -5%) NÃO aparece quando você usa Preço mínimo de fim de semana (Minimum Weekend Price), Preço mínimo distante (Minimum Far Out Price) ou mínimos sazonais em nível de grupo/subgrupo/conta. Se adotar esses pisos, não conte com o nudge: acompanhe "Datas com preço mínimo" (Minimum Price Hitting Dates) e a "Recommended BP to BP Ratio".

### Passo 3: Preço Mínimo de Segurança (Safety Minimum Price)

| Cenário | Valor |
|---|---|
| Conservador | PriceLabs Recommended a 110% (padrão) |
| Equilibrado | PriceLabs Recommended a 100% |
| Agressivo | "Do Not Apply Safety Minimum Price" durante a fase de rampa; religar a 100-110% quando a ocupação estabilizar acima de 85% |

Só existe se o PMS envia receita por noite. Ele nunca abaixa o piso, só sobe; se a mesma semana do ano passado vendeu caro, ele bloqueia silenciosamente os descontos de última hora.

### Passo 4: Fonte de dados e sensibilidades

| Configuração | Valor recomendado | Observação |
|---|---|---|
| Sensibilidade ao fator de demanda (Demand Factor Sensitivity) | Recommended (equilibrado); Moderately Conservative (agressivo em ocupação) | Nunca "Aggressive": ele segura preço esperando demanda tardia |
| Hotel Weights / Pricing Data Source | Mostly Hotel (90%) no início; Fully Hotel (100%) só com comp set selecionado (a combinação Fully Hotel + PriceLabs Default é inválida na API) | Por solicitação em contas não-hotel |
| Hotel Comp Set | PriceLabs Default (10 mais próximos) -> depois "Selected in Hotel Data" com 8-10 concorrentes reais, tipos de quarto correspondidos, copiado para todos os tipos de quarto | Mínimo 5 hotéis |
| Sazonalidade (Seasonality Factor Sensitivity) | Conservative (padrão hotel); No Seasonality só se usar perfil sazonal próprio | Ajuste gradual |
| Ajustes de preços do dia da semana | 0 em todos os dias no HLP (equilibrado); ver Passo 9 para exceções | O HLP já modela dia da semana |

### Passo 5: Ajustes baseados na ocupação de múltiplos quartos (MROBA) — a alavanca principal

Onde: Dynamic Pricing > Customizations > Groups > Editar o grupo do hotel > All Customizations > Portfolio / Multi-Room Occupancy Based Adjustment > "Weekend/Weekday Specific" > Custom > Edit Profile (há "Download Profile / Upload Profile" em CSV: a confirmar as colunas na sua conta). Não é editável via MCP.

Perfil equilibrado (linhas = dias antes da chegada, colunas = ocupação do grupo naquela data):

| Janela | < 50% | 50-69% | 70-84% | 85-94% | >= 95% |
|---|---|---|---|---|---|
| 0-3 dias | -20% | -12% | 0% | +8% | +15% |
| 4-7 dias | -15% | -8% | 0% | +6% | +12% |
| 8-14 dias | -10% | -5% | 0% | +5% | +8% |
| 15-30 dias | -5% | 0% | 0% | +3% | +5% |
| 31-60 dias | 0% | 0% | 0% | 0% | +3% |

Variantes:

| Cenário | 0-3 dias, < 50% | 0-3 dias, 50-69% | Prêmio >= 95% |
|---|---|---|---|
| Conservador (primeiras 2-3 semanas) | -15% | -8% | +10% |
| Equilibrado | -20% | -12% | +15% |
| Agressivo | -25 a -30% | -15% | +12% (menor, para não travar a última venda) |

Regras da tabela: janelas de dias crescentes e sequenciais; para cada janela, o ajuste diminui conforme a ocupação sobe; faixa -50% a +500%. A coluna 70-84% em 0% é o coração da estratégia: o sistema para de descontar exatamente no piso de 70% e começa a cobrar prêmio rumo a 100%. Coloque no nível do grupo (ocupação do hotel inteiro). Só adicione MROBA no nível do tipo de quarto para categorias com 5+ quartos que se comportam diferente (ex.: suítes: -5 pontos a mais nas linhas 0-7 dias). Tipos com menos de 5 quartos seguem o grupo e o quarto "pai" via offsets.

Riscos: MROBA se soma a última hora e dia da semana (por isso o cenário equilibrado desliga última hora); os descontos param no mínimo, então o mínimo tem de deixar espaço para -20%; Fixed DSOs e overrides "% of Base Price" de grupo/conta têm precedência e desligam o MROBA naquela data.

Intra-Day MROBA (Dia 0 / Dia 1): com 1-2 sincronizações extras compradas para todos os tipos de quarto, ligue "Enable Intra-Day Multi-Room Occupancy-Based Adjustments" e defina, por horário: Dia 0 às 15h, ocupação < 60% -> -20%; às 19h, < 70% -> -25%; Dia 1, < 60% -> -12%. É o único mecanismo nativo que reprecifica hoje e amanhã mais de uma vez ao dia sem Real-Time Sync.

### Passo 6: Preços de última hora (Last Minute Prices)

| Cenário | Configuração | Piso |
|---|---|---|
| Conservador / Equilibrado (recomendação oficial para hotéis com MROBA) | Toggle ON + "No last minute adjustment" | Não é documentado se o Minimum Last Minute Price continua valendo com "No last minute adjustment" (ele é descrito como piso "para os seus dias de última hora"). Configure-o mesmo assim se o suporte liberar, mas garanta o piso da janela curta com substituições de preço mínimo por data (Passo 2 e Passo 11) até o protocolo de teste do Passo 2 confirmar qual piso o MROBA respeita |
| Agressivo (ocupação de 7 dias < 70% há 2 semanas) | "% Gradual" 20-25% em 7 dias (cerca de 3%/dia; no Airbnb, a partir de 10% de desconto o preço original aparece riscado, o que acontece por volta do dia 3-4; o PriceLabs não documenta limiar equivalente para o Booking.com) ou "Market Driven (Aggressive)" | Minimum Last Minute Price = Base x 0,55-0,60 |
| Tipo de quarto com menos de 5 unidades (MROBA instável) | "Market Driven (Aggressive)" | idem |

Atenção: desligar o toggle NÃO desliga o desconto; o padrão "Market Driven (Balanced)" do algoritmo (até 40%) assume o controle. Para não descontar, selecione explicitamente "No last minute adjustment". Nunca use "Fixed": é o único tipo que fura o mínimo, só existe no nível do anúncio e precisa ser >= 20% do base. Entre última hora, dia órfão e fator adjacente, só o maior desconto vale, mas o MROBA se soma por cima: -20% MROBA + -15% última hora + -8% dia da semana compõem cerca de -38%, e esse é o pior caso que você deve permitir, com o Minimum Last Minute Price como trava final.

### Passo 7: Ajustes por ocupação (OBA mono-anúncio), dias órfãos, fator adjacente, distantes

| Configuração | Valor | Motivo |
|---|---|---|
| Ajustes Baseados na Ocupação (OBA, nível do anúncio) | "No Occupancy Based Adjustment" | Recomendação oficial; MROBA substitui |
| Preços dos Dias Órfãos (Orphan Day Prices) | "No Orphan Day Adjustments" (equilibrado). Exceção: tipo de quarto com estadia mínima padrão de 2 noites -> 1 regra: vão de 1 noite = -10% dia útil / 0% fim de semana, "apply only within" 7 dias | Vão de um quarto entre duas reservas não é inventário aflito num hotel; o desconto padrão de 20% erode ADR |
| Fator adjacente (Adjacent Factor) | OFF | Conceito de calendário único |
| Preços distantes (Far Out Prices) | Toggle ON + "No Far Out Prices" (padrão hotel) ou Market Driven (Conservative) | Prêmio distante reduz reservas antecipadas que elevam a ocupação de partida; desligar o toggle devolve o controle ao padrão de mercado |
| Fator por Poucas Reservas (Booking Recency Factor) | ON (PriceLabs Recommended) | Rede de segurança automática abaixo de 70%; empilha com MROBA, por isso o piso é a trava. Pré-requisitos completos (todos precisam valer): sem reservas há 15+ dias; dados de reserva com menos de 3 dias de idade; anúncio sincronizando há pelo menos 7 dias; e ocupação dos próximos 30 dias < 10% OU < 80% da ocupação do mercado E < 70% no total. Bloqueios contam como reservas. Num PMS com feed de reservas parado ou ausente (ou no Booking.com direto, cujo histórico é de 15 dias), a rede de segurança simplesmente nunca dispara: por isso o refresh de reservas entra na rotina diária |

### Passo 8: Estadia mínima (Minimum Stay Settings)

Onde: Customizations > Edit > Stay Restrictions > Minimum Stay Settings, no nível do grupo (as regras de estadia são "tudo ou nada" por nível: uma regra no anúncio anula todo o bloco do grupo). Crie como "Min-Stay Profile" e aplique ao grupo.

| Regra | Hotel urbano/corporativo | Hotel de lazer |
|---|---|---|
| Padrão (Default Min Stay Rule, Fixed) | 1 noite todos os dias | 1 noite dias úteis, 2 noites sexta/sábado (apenas alta temporada, via perfil sazonal) |
| Last-Minute Bookings, regra 1 | 1 noite todos os dias "within 7 days" | 1 noite todos os dias "within 3 days" |
| Last-Minute Bookings, regra 2 | - | 1 noite dias úteis / 2 sábado "within 7 days" |
| Orphan Gaps | "Choose a number" = 1 para vãos de 1-4 dias | idem |
| Far-Out Bookings | nenhuma | 2 noites sexta/sábado acima de 30 dias só na alta |
| Lowest Minimum Stay Allowed | 1 | 1 |
| Check-in/Check-out | OFF | OFF (só por DSO em Réveillon/Carnaval) |

Hierarquia oficial: Lowest Minimum Stay Allowed > Orphan > DSO > Adjacent After > Adjacent Before > Far-Out > Last-Minute > Default (órfão só vence quando reduz). Não ative "allow minstay increase" (prevenção de vãos): ela bloqueia reservas de propósito. Alternativa automatizada: "PriceLabs Recommended (Dynamic)" com Lowest = 1, Highest = 2 e Adaptive Occupancy Adjustment (reduz 1-2 noites quando você fica 10-20%+ atrás do mercado nos próximos 90 dias). No Stays.net a estadia mínima precisa estar configurada no PriceLabs e igual no PMS, senão a sincronização falha.

### Passo 9: Dia da semana (Day of Week Pricing Adjustments)

Padrão no HLP: 0 em todos os dias. Só ajuste os dias que cronicamente ficam abaixo de 70% no Report Builder, e no máximo 10% (o algoritmo já aplica seu próprio fator e o MROBA soma por cima). Exemplos de partida, a calibrar com o gráfico "Day of the Week Occupancy and Price Factor" dos Market Dashboards:

| Dia | Urbano/corporativo | Lazer |
|---|---|---|
| Seg | 0% | -8% |
| Ter / Qua | 0% (o HLP já premia) | -10% |
| Qui | 0% | -5% |
| Sex | -8% | 0% |
| Sáb | -10% | +5% |
| Dom | -10% | -5% |

Use as tabelas "Weekend/Weekday Specific" do MROBA para dar descontos mais fundos nos dias fracos em vez de empilhar dia da semana com MROBA. Weekend Days: sexta e sábado (padrão); inclua domingo só se ele realmente lota.

### Passo 10: Preços distantes e perfil sazonal

- Far Out: ver Passo 7. Preço mínimo distante (Minimum Far Out Price) = base para 90+ dias, para concentrar todo desconto dentro da janela de reserva.
- Preços personalizados de temporada (Custom Seasonal Profile): no nível do grupo, modo percentual, só mínimo e máximo, NUNCA preço base sazonal (o PriceLabs diz que não recomenda perfis sazonais porque a sazonalidade já está no algoritmo; base sazonal aplica duas vezes). Alta temporada: mínimo +25%, máximo +60%; baixa temporada: mínimo -15% (dá espaço para os descontos de 7 dias nos meses em que 70% é mais difícil). Carnaval e Réveillon: temporadas não repetitivas (por solicitação) ou DSOs de grupo com mínimo +80 a +150% e perfil de estadia mínima de 3-4 noites. Pricing Profiles (por solicitação) permitem um perfil "Baixa temporada - pickup" (MROBA fundo, última hora % Gradual) e um "Alta temporada - proteger ADR" (MROBA limitado a -10%, última hora Conservative) trocados automaticamente por temporada.
- Efeito colateral dos perfis sazonais e dos pisos em nível de grupo: um Custom Seasonal Profile desliga o nudge de preço base (Base Price Nudge) e, se editar o base em percentual, a métrica "Recommended BP to BP Ratio" aparece como "unavailable"; mínimos de fim de semana / distante / sazonais em grupo, subgrupo ou conta desligam o nudge de preço mínimo. Ou seja: quem adota o Passo 10 (e os pisos extras do Passo 2) perde os nudges da rotina diária e precisa se guiar por "Datas com preço mínimo" (Minimum Price Hitting Dates) e pela razão base recomendado/base (quando disponível) ou pela ferramenta "Help me choose a base price".

### Passo 11: Substituições por data (DSO) como ferramenta de emergência

| Uso | Tipo | Valor | Expiração |
|---|---|---|---|
| Data dentro de 7 dias abaixo de 50% em D-3 | "% of Recommended Price" (respeita mínimo, continua dinâmico) | -10 a -15% | "Set Override Expiry" / lead_time_expiry = 2 dias |
| Data dentro de 7 dias abaixo de 50% em D-1 | idem | até -25% (limitado pelo Minimum Last Minute Price) | 1 dia |
| Evento / Key Future Date | "% of Recommended Price" + DSO de estadia mínima | +10 a +25%, 2 noites | até a data |
| Grupo negociado | "Fixed Price" com campo reason | preço do contrato | - |
| Baixar o piso só das próximas 7 datas (quando o Minimum Last Minute Price não está liberado ou não é o piso que o MROBA respeita) | "Minimum Price" por data (date-specific Minimum Price; via MCP `min_price` + `min_price_type` fixed / percent_base / percent_min) | -15 a -25% do mínimo (percent_min) ou valor fixo = piso de custo | lead_time_expiry = 1 dia (some depois da estadia) |
| Data de evento em que o MROBA não pode descontar | "Minimum Price" por data = Minimum Event Price (calendário > arrastar datas > Add) | base x 0,9 a base x 1,2 | até a data |
| Teto por data (não deixar o prêmio passar do que o mercado aceita) | "Maximum Price" por data (via MCP `max_price` + `max_price_type` fixed / percent_base / percent_max) | 2-3x base | até a data |
| Liberar/limitar dias de chegada numa data | DSO de check-in / check-out (exige regra padrão de check-in/out configurada; via MCP `check_in` / `check_out` em máscara de 7 dígitos seg-dom) | todos os dias liberados dentro de 7 dias | até a data |

Tipos percentuais, na prática: "% of Recommended Price" (anúncio, grupo e conta; no MCP `price_type = percent`) é dinâmico e respeita mínimo e máximo; "% of Base Price (Fixed)" (só grupo/conta; no MCP `percent_base`) é estático, tratado como preço fixo e ignora os limites; "percent_stacked" só existe no nível do anúncio e depende de liberação pelo suporte. Uma escrita de DSO não recalcula o preço sozinha: rode refresh (ou Save and Refresh) depois.

Nunca use "Fixed Price" para descontar: congela a data, desliga MROBA e fura o mínimo. Nunca use "% of Base Price" (grupo/conta) casualmente: é tratado como fixo e vence até o mínimo do anúncio. Regra: nunca faça um corte público mais fundo do que a tabela MROBA já produz; prefira tarifas cercadas no channel manager (mobile, membro, direto) e valor agregado (late check-out, café) antes de baixar a BAR pública.

### Passo 12: Sincronização

| Cenário | Configuração | Custo |
|---|---|---|
| Conservador | Timed Sync grátis às 08:00 de Brasília (11:00 GMT, dentro da janela permitida de 00:00-18:00 GMT) | 0 |
| Equilibrado | Timed Sync 06:00-08:00 + 1 sincronização extra ~16:00 (habilita Intra-Day MROBA com 2 horários) | US$ 1/tipo de quarto/mês |
| Agressivo | 2 sincronizações extras (3 horários: manhã, meio-dia, 18-19h) + Real-Time Sync se o PMS estiver na lista (Apaleo, Cloudbeds, Octorate, RMS Cloud, RoomRaccoon, Staah, Stays, Airbnb, Guesty, Hostaway, entre 22) | US$ 2 + US$ 2/unidade/mês |

Real-Time Sync recalcula e envia preços quando o PMS avisa (webhook) uma reserva, cancelamento, mudança de datas, troca de tipo de quarto ou bloqueio, com no máximo uma atualização a cada 60 minutos e até 24 por dia; é um limitador de frequência (nunca mais de uma recalculação por hora, e só se houve evento), não uma garantia de reprecificação em 60 minutos. Mews e Booking.com direto não estão na lista. Após qualquer mudança feita por você ou pelo Claude: Save and Refresh + Sync Now, e confirme "Última sincronização" nos últimos 15 minutos. Detalhe que muda o desenho do loop diário com o Claude: a ferramenta `refresh_listing_pricing` do MCP é o equivalente do botão "Save and Refresh" apenas (recalcula o calendário, não envia ao PMS); escritas de DSO e de personalizações ficam gravadas, mas "não disparam uma recalculação por si só"; e o refresh tem limite de 3 chamadas por anúncio a cada 24 horas (10 por conta por minuto). Para 20-40 tipos de quarto, isso significa: uma única rodada de refresh por dia depois de todas as DSOs (não uma por DSO), e o envio ao canal fica por conta do Timed Sync/extras, do Real-Time Sync ou do Sync Now manual no Multicalendário. Cobrança: sem rateio; anúncio com sync desligado no meio do ciclo ainda é cobrado naquele ciclo, então não fique ligando e desligando para testar.

### Ordem de implementação resumida

Base/mín/máx por tipo de quarto -> Property Type Hotel + HLP -> Hotel Weights + comp set -> MROBA Custom no grupo -> última hora "None" (ou % Gradual + piso) -> OBA/órfão/adjacente/far out em "No ..." -> estadia mínima 1 noite dentro de 7 dias -> Sync ON + Timed Sync/extras -> Adicionar Métricas -> segurar 7 dias.

---

## Hotéis: quartos, tipos e integração

**Modelo de inventário.** O PriceLabs importa apenas o tipo de quarto (unit-type / room-type / parent unit) do PMS; cada tipo vira um anúncio com seu próprio estoque, e o PMS/channel manager distribui a tarifa para as OTAs. A ocupação do anúncio é noites vendidas / noites disponíveis de todos os quartos daquele tipo. Não separe quartos idênticos em anúncios individuais: um anúncio de 1 quarto oscila entre 0% e 100% a cada reserva e desestabiliza o MROBA. Se o PMS só expõe quartos físicos, use Mapear propriedades (Map Listings) para colocar todos os idênticos como filhos de um pai (mesma localização, mesmo base, até 4 filhos por padrão, filho cobrado à parte: a KB cita BRL 4,75 e outra fonte BRL 22,50 por filho/mês, a confirmar com o faturamento).

**Estrutura recomendada por hotel.**

| Elemento | Configuração |
|---|---|
| Quarto "pai" / benchmark | O tipo com mais unidades (Standard); recebe Base/Mín/Máx via "Help me choose a base price" |
| Outros tipos | Superior +15-20%, Suíte +35-50% como Pricing Offset percentual no nível de conta/grupo (offsets no nível do anúncio são apagados quando o pai muda); ou anúncios independentes com base próprio se a demanda for descorrelacionada |
| Grupo | 1 por hotel, com todos os tipos; MROBA, estadia mínima, última hora e dia da semana no grupo |
| Subgrupo | "Suítes" (por solicitação ao suporte) se precisar de descontos mais fundos |
| Planos tarifários | BAR flexível como plano padrão com "Price and Restrictions"; não reembolsável derivado a -8 a -12% com "Price"; corporativo/contrato em "None"; derivados do PMS em "None" para não marcar duas vezes |
| Comissão de OTA | Markup por canal no channel manager, não no PriceLabs; se precisar, Pricing Offset +12 a +15% no filho do Booking.com (cobre comissão + Genius 10%) |

**O que o PriceLabs envia.** Tarifas diárias, estadia mínima e restrições de check-in/out, dependendo da integração; nunca disponibilidade ou stop-sell (isso fica no PMS). Cloudbeds: tarifas, estadia mínima, check-in/out e taxa de hóspede extra (esta última pedindo ao suporte) até 540 dias, só dentro dos intervalos de tarifa definidos no Cloudbeds (defina base rates para 365+ dias), atualiza planos base e não derivados, sem Length of Stay Pricing; Real-Time Sync suportado. Mews: só planos base, 365 dias, sem Real-Time Sync. Booking.com direto: cada tipo de quarto vira um anúncio; só planos "Standard" XML; reservas em planos filhos/não-XML não são lidas (ocupação subestimada); Delta Push só envia mudanças de US$ 5 ou mais (use passos de pelo menos 5%); histórico de reservas de 15 dias; 1 ano por padrão (720 dias sob pedido); a visão mensal do calendário da extranet deixa de existir. Stays.net: tarifas, estadia mínima, LOS (até 14 noites) e check-in/out até 540 dias; modelo "básico" de tarifas recebe só diárias; estadia mínima tem de coincidir nos dois sistemas; Real-Time Sync suportado.

**PMS e channel managers suportados.** 160+ integrações no total, 60+ hoteleiras: Cloudbeds, Mews, Apaleo, Octorate, RoomRaccoon, MiniHotel, Eviivo, Amenitiz, Staah, RMS Cloud, Channex, Beds24, Smoobu, Lodgify, Guesty, Hostaway, Rentals United, WebHotelier, Newbook, ResNexus, StayNTouch, SabeeApp, Yield Planet, Zak, Stays, mais Airbnb, Booking.com e Vrbo diretos. Expedia só via PMS/channel manager. SiteMinder e Little Hotelier aparecem em blogs, não em lista de integração: a confirmar com support@pricelabs.co.

**Nota crítica para o mercado brasileiro.** Omnibees, HSystem, Desbravador, CM Net/TOTVS, Hospedin e Silbeck não constam em nenhuma lista de integração nem na lista de Real-Time Sync. O único PMS brasileiro encontrado é o Stays.net (orientado a temporada, mas suporta multiunidade). Antes de qualquer ajuste, confirme o PMS de cada hotel (o Claude lê `pms_name` com get_listings). Se for um dos não integrados, os caminhos realistas são: (a) conectar o Booking.com diretamente (rápido, mas só o Booking.com recebe preço; 3 sincronizações/dia, sem Real-Time Sync); (b) espelhar o inventário num channel manager suportado (Cloudbeds, Staah, Channex, Octorate) que alimente Booking.com/Expedia/Airbnb; (c) usar a Customer API (US$ 1/anúncio/mês, 60 req/min) para ler os preços recomendados e empurrá-los para o PMS brasileiro por um job próprio (o PriceLabs avisa que essa API não é o caminho oficial de integração de PMS). Verifique também se o PMS não está na lista de exclusão do Portfolio Analytics (Staah, Channex, Zak, Hotres, MasterYield, MisterBooking estão) e se envia bloqueios/manutenção, porque a ocupação ajustada depende disso; o artigo de Occupancy Based Adjustments traz a lista (dezenas de PMS/channel managers, atualizada com o tempo) de integrações em que bloqueio NÃO conta como ocupado: confira se o seu PMS está na lista.

**Outras notas Brasil.** Crie Market Dashboards e Comp Sets em BRL e no endereço real do hotel (moeda e local não mudam depois). Delta Push de US$ 5 no Booking.com: confirme com o suporte como é aplicado em BRL. Team Settings exige e-mails de domínio corporativo (gmail não é aceito) e é ativado pelo suporte; se o admin for gmail, conecte o MCP com o login do admin. Preço do PriceLabs no Brasil: BRL 47,50 por anúncio/mês no primeiro, escala decrescente até BRL 22,50 (251+); teste grátis de 30 dias. Benchmarks publicados pelo PriceLabs para hotéis: RevPAR 15-20% maior no primeiro ano; The Castle Inn RevPAR de GBP 57,18 para 82,20; 25-35% das reservas de hotéis independentes chegam nos últimos 14 dias. Faça 7-10 dias em modo monitoramento (sync OFF, comparando 10-15 datas com a BAR atual) e desligue qualquer regra de yield do PMS antes de ligar o envio, para evitar dois sistemas escrevendo a mesma tarifa.

---

## Canais e visibilidade (o que decide se o corte de 0-7 dias é visto)

Regra geral: **um dono por tipo de desconto.** O PriceLabs é dono dos descontos por data (última hora, dias órfãos, MROBA, DSO); as OTAs ficam só com programas por segmento (Genius, tarifa mobile, tarifa por país). Promoções da OTA são aplicadas em cima do preço que o PriceLabs já reduziu: 20% no PriceLabs + 20% no Airbnb dá cerca de 36% de desconto (0,8 x 0,8). Meta: desconto efetivo total numa noite <= 30%.

### Airbnb

| Item | Configuração | Motivo |
|---|---|---|
| Smart Pricing (Preço Inteligente) | OFF, obrigatoriamente | Sobrepõe as tarifas do PriceLabs; a sincronização do PriceLabs também apaga as Pricing Tips |
| Conjuntos de regras (rule-sets), descontos de última hora e de compra antecipada | Remover | O PriceLabs já aplica última hora/far-out; um rule-set + restrição de check-in/out no PriceLabs pode fazer a sincronização falhar |
| Descontos semanal, mensal e por duração de estadia | Manter no Airbnb | Não podem ser definidos no PriceLabs para o Airbnb |
| Promoções personalizadas (custom promotions) | Evitar em datas que já têm desconto do PriceLabs | O Airbnb calcula o "preço riscado" pela mediana dos últimos 30-60 dias, não pelo preço do PriceLabs: soma desconto sobre desconto |
| Selo de desconto | A partir de 10% de desconto o Airbnb risca o preço original | Um "% Gradual" de 20% em 7 dias cruza esse limiar por volta de D-3/D-4 |
| Reserva instantânea (Instant Book) | ON, com aviso prévio "mesmo dia" e exigência de identidade verificada | O PriceLabs cita ranking ~15-25% maior e é o que permite reservar em D0-D3 sem fricção |
| Política de cancelamento | Moderada como padrão; "Flexível" (24h) nas datas abertas dentro de 7-14 dias via configurações personalizadas por data (recurso sazonal do Airbnb, manual, só desktop); "Firme" só em datas de evento | Cancelamento grátis é filtro de busca de quem viaja de última hora; as políticas por data são "grudentas" e não são apagadas pela sincronização do PriceLabs |
| Estadia mínima | 1 noite dentro de 7 dias (vem do PriceLabs, aparece em "Duração personalizada da viagem") | Restrições longas somem com o sinal de disponibilidade |

### Booking.com

| Item | Configuração | Motivo |
|---|---|---|
| Plano tarifário padrão | BAR flexível (Standard, XML) com "Price and Restrictions" | É o plano que o PriceLabs escreve e que os filtros de "cancelamento grátis" mostram |
| Plano não reembolsável | Derivado do BAR a -8 a -12% com "Price" | Converte quem compara preço na última hora e reduz cancelamento dentro da janela |
| Genius | Nível 1 (10%) ligado (exige 3 avaliações e nota 7,5+); níveis 2-3 chegam a 20% | Visibilidade e selo sem mexer na tarifa pública; o PriceLabs registra que Genius PODE se somar a outras promoções do Booking.com, então mantenha só uma promoção ativa por vez |
| Tarifa mobile | 10% | Conversão maior no app; soma com Genius para membros no celular |
| "Last-minute deal" do Booking.com | Não usar enquanto o PriceLabs tiver desconto de última hora/MROBA ativo | Sobreposição de dono; se quiser usar, coloque Last Minute Prices em "No last minute adjustment" para esses anúncios |
| Compensação de comissão | Pricing Offset +12 a +15% no filho do Booking.com (nível de conta/grupo) ou markup no channel manager | Cobre comissão (15-25%) + Genius 10% e mantém paridade com Airbnb/direto |
| Delta Push | Passos de desconto de pelo menos 5% | Mudanças menores que US$ 5 não são enviadas |

### Canal direto e channel manager

- Tarifas "cercadas" (membro, mobile, país) e valor agregado (café, late check-out) antes de cortar a BAR pública; assim o corte de D0-D3 do PriceLabs não vira referência permanente para o mercado.
- Desligue qualquer regra de yield do PMS/channel manager que escreva tarifa em paralelo ao PriceLabs.
- Verifique em cada canal que a tarifa exibida para 2 hóspedes, 1 noite, com cancelamento grátis, é a que o PriceLabs enviou (Hotel Data e Pricing Logs ajudam).

---

## Rotina diária e semanal de revenue

### Checklist diário (10 minutos, antes das 9h)

1. **Central de Ações (Action Center, rótulo PT a confirmar)**: zerar. Alertas "Last-minute settings too conservative", "Minimum stay higher than market", "Many unbookable dates" (>3 datas) e "Many blocked dates" são correções do mesmo dia. **Exceção: "OBA turned off".** Com a configuração recomendada para hotéis (OBA do anúncio em "No Occupancy Based Adjustment" e MROBA ativo no grupo) esse alerta pode ficar permanentemente aceso, porque ele dispara quando o OBA "parece ligado mas está efetivamente desabilitado" e a ocupação próxima está fraca (contas com ~50 anúncios ou menos). NÃO religue o OBA do anúncio por causa dele (seria desconto em dobro por cima do MROBA): confirme com get_customizations que o MROBA do grupo está ativo, oculte o alerta (get_actions, modo hidden / ocultar na interface) e pergunte ao suporte se o alerta considera o Multi-Room OBA. Obs.: os alertas de configuração podem não disparar em contas com mais de ~50 anúncios; nesse caso confira get_customizations.
2. **Portfolio Analytics > Refresh de reservas** se a última atualização tiver mais de 12h (permitido a cada 12h). Isso também mantém a condição "dados de reserva com menos de 3 dias" de que o Fator por Poucas Reservas depende; se o PMS não envia reservas (ou envia com atraso), a rede de segurança automática não dispara.
3. **Multicalendário** com Quick Filter por hotel e colunas: Ocupação ajustada próximos 7 dias, MPI, Booking Pickup 7 dias, Nights Booked 7 dias, Last Booked Date, Datas com preço mínimo (próx. 7 dias). Ordene por ocupação crescente. Modo de cor "recência de reservas" (últimos 3/7/14/30 dias).
4. **Aba Dados de hotéis (Hotel Data)**: preço dos próximos 7 dias vs. comp set, variação de 48h, quantos concorrentes "NA" (esgotados).
5. **Aba Dados da Vizinhança**: ocupação de mercado dos próximos 7 dias com "Add Pickup" (lembrando que atualiza a cada 2 dias).
6. **Nudges**: aceitar nudges de preço mínimo em tipos de quarto abaixo de 70%; adiar nudges de base para cima. Lembrete: se o hotel usa perfil sazonal (Passo 10) ou mínimos de fim de semana / distante / sazonais em nível de grupo (Passo 2), os nudges de base e de mínimo não aparecem; nesse caso o sinal equivalente é "Datas com preço mínimo" (Minimum Price Hitting Dates) e a razão base recomendado/base ("unavailable" se o perfil sazonal edita o base em %).
7. Intervir só com DSO "% of Recommended Price" com expiração; não editar regras no dia a dia.
8. Terminar com Save and Refresh + Sync Now nos tipos alterados.

### Regras de decisão diárias

| Situação (próximos 7 dias) | Diagnóstico | Ação |
|---|---|---|
| Ocupação do tipo < 70% e MPI vermelho/amarelo (< 1,0) e mercado >= 60% | Problema de preço/posição | Deixar MROBA descontar; se preço acima da mediana do comp set, DSO -10% nas datas fracas; conferir plano tarifário padrão |
| Ocupação < 70% e MPI verde/azul (>= 1,0) e mercado < 50% | Mercado fraco: "um corte não cria demanda que não existe" | Não aprofundar; manter piso, estadia mínima 1, canais e valor agregado; aceitar que 70% pode não vir nessa semana |
| Ocupação < 70% e Datas com preço mínimo > 50% | Piso travando | Reduzir mínimo (ou Minimum Last Minute Price) 5-10% |
| Ocupação < 70% e Datas com preço mínimo < 20% | Desconto insuficiente | Base -5% ou perfil MROBA um degrau mais fundo |
| Ocupação < 70% e sem reserva há 3+ dias (Last Booked Date) | Sem pickup | DSO -10 a -15% em D0-D3, estadia 1 noite; rodar diagnose_no_bookings |
| Mercado >= 80% na data e você < 70% | Mal posicionado ou invisível | Corrigir no dia (preço <= percentil 50 / Market Booked Price; visibilidade) |
| >= 6 de 10 concorrentes esgotados numa data e você < 70% | Não é demanda | Preço ao percentil 25 imediatamente |
| Tipo de quarto >= 90% em 7 dias | Compressão | Nenhum desconto; deixar prêmio MROBA; DSO +5-10% se pickup forte |
| Pickup diário necessário = (0,70 x quartos x 7 - noites vendidas) / 7 acima do realizado por 2 dias | Atraso | Próximo degrau de desconto por DSO -5% |

### Revisão semanal (segunda-feira, 30 minutos)

1. **Pacing Reports > Booking Curves**, modo Custom Dates, mês atual e próximo, "Pacing Against Yourself" com "Final last year", "Against Market" se houver Market Dashboard. Regra: atrás do ano passado no mesmo "dias até a data" em >10 pontos e pickup de mercado maior que o seu -> aprofundar linhas 0-3 e 4-7 do MROBA em 5 pontos; acima do ano passado e pickup >= mercado -> reduzir descontos 5 pontos e subir prêmios. Alvo de curva: 50% em D-7, 70% em D-3, 85%+ em D-1.
2. **Report Builder**: "Hotel Pickup Trends (Current Month)" (janelas 3/7/30 dias) e o relatório customizado "Próximos 7 dias por tipo de quarto" (Adjusted Occupancy %, Market Occupancy %, MPI %, Occupancy Pickup 3 dias, Booked Nights Pickup 7 dias, Minimum Stay, Final Price, Market 50th Percentile Price, coluna de fórmula "Noites para 70%" = 0,70 x noites disponíveis - noites vendidas). Agende por e-mail (5 agendamentos por conta, Excel à meia-noite).
3. **Opportunities**: qualquer "Unbookable Dates Potential Revenue" > 0 dentro de 7 dias = restrição escondendo estoque.
4. **Market Dashboards**: template "Understand future market demand" (Key Future Dates -> DSO +10-20% e 2 noites) e "Optimize revenue based on booking patterns" (LOS vs Booking Window: se >= 40% das reservas caem em 0-7 dias, janela de última hora = 7 dias; se em 8-14, começar em 14).
5. **Ajustes de regras**: no máximo +/-5 pontos por linha do MROBA e +/-2 dias por janela por semana.
6. **Auditoria**: Account Logs / get_user_logs (quem mudou o quê); apagar DSOs "fill7d" vencidos; conferir que nenhum Fixed DSO sobrou nos próximos 14 dias (ícone de olho no calendário).

### Mensal e trimestral

Mensal: recalibrar preço base (Recommended BP to BP Ratio), dia da semana e perfil MROBA; Listing Optimizer (se houver Airbnb). Trimestral: comp set do Hotel Rate Shopper, piso de custo, perfis sazonais.

---

## KPIs e metas

| KPI | Onde | Fórmula / fonte | Meta |
|---|---|---|---|
| Ocupação ajustada próximos 7 dias (Adjusted Occupancy) | Adicionar Métricas (Painel / Multicalendário) | noites vendidas / noites disponíveis excl. bloqueios; atualização diária | >= 70% em todo tipo de quarto; 85-100% em datas-chave |
| Pickup (Booking Pickup / Nights Booked, 7 dias) | Adicionar Métricas (exige Portfolio Analytics) | reservas únicas / noites recebidas nos últimos X dias | >= pickup necessário = (0,70 x quartos x 7 - OTB) / 7 |
| MPI (Market Penetration Index) | Adicionar Métricas | ocupação do anúncio / ocupação do mercado (350 anúncios STR num raio de 15 km, Airbnb por padrão) | >= 1,0 (verde); usar como sinal de direção, não como alvo hoteleiro |
| Datas com preço mínimo (Minimum Price Hitting Dates) | Adicionar Métricas | datas disponíveis no mínimo / datas disponíveis | < 30% nos próximos 7 dias |
| ADR | Adicionar Métricas / Portfolio Analytics | receita / noites vendidas; STLY casado por dia da semana | >= STLY x 0,95 durante a rampa |
| RevPAR | Adicionar Métricas / Pacing | receita / total de datas | >= STLY x 1,05 |
| Goal Completion % | Manage Goals + Adicionar Métricas | valor atual / meta (ocupação mensal) | meta mensal de 85% (ponto médio da faixa 70-100%) |
| Curva de reservas | Pacing Reports | ocupação por "dias até a data" | 50% em D-7, 70% em D-3, 85% em D-1 |
| Posição de preço no comp set | Hotel Data | sua BAR vs mediana dos 8-10 concorrentes | entre 0,95 e 1,05 da mediana quando no ritmo; <= percentil 50 quando < 70% |
| Última sincronização | Painel de Preços | timestamp | dentro do horário programado; nunca > 24h |

**Guardrails para 100% não destruir o RevPAR:**

- Ocupação sobe e RevPAR dos próximos 7 dias fica abaixo do STLY por 2 semanas seguidas = canibalização: reduzir cada linha de desconto do MROBA em 5 pontos e subir Minimum Last Minute Price.
- Desconto efetivo total numa noite (MROBA + última hora + dia da semana + promoções da OTA como Genius/mobile) <= 30%; Genius pode se somar a outras promoções do Booking.com, então mantenha só uma promoção ativa por vez.
- As faixas de prêmio (>= 85% -> +8-15%) são obrigatórias: são elas que fazem os últimos 15-30% dos quartos pagarem os descontos dos dias fracos.
- Não coloque Pricing Offset negativo, Fixed DSO ou última hora Fixed como "promoção": os três furam o piso.
- Meça semanalmente Revenue Capture % (receita real / receita potencial a tarifas ótimas): 65-80% em demanda normal, 80-90%+ em pico.
- Nunca cancele hóspedes por overbooking (sinal mais danoso para ranking em OTA).

---

## Usando o Claude com o conector MCP do PriceLabs

### Conexão

1. No PriceLabs: Account Settings > aba **AI Connector (MCP)** > copie a URL `https://mcp.pricelabs.co/mcp` e o Client ID.
2. No Claude: Customize (maleta) > Connectors > "+ Add custom connector" > nome "PriceLabs MCP", URL acima, Client ID em Advanced settings > Connect > faça login no PriceLabs e aprove.
3. Escopos independentes: **Read** (ler preços, mercado, métricas), **Write** (base/mín/máx, sync on/off, DSOs, nudges, refresh, mapeamento) e **Customization write** (as regras de preço). Para trocar escopos, desconecte e reconecte; conexões anteriores a 2 de setembro de 2026 precisam reconectar para ganhar Customization write.
4. As ferramentas só aparecem em **conversas novas**. Teste com "Quais ferramentas do PriceLabs você tem?" e "Quem sou eu?" (get_me).
5. Beta gratuito por tempo limitado; o PriceLabs pode armazenar as perguntas. Membros de equipe precisam de "MCP Access" e herdam suas permissões de anúncio.

### O que o Claude consegue ler e escrever

| Ler (Read) | Escrever (Write / Customization write) |
|---|---|
| get_listings, get_listing_data (sync, último push), get_listing_prices (preço, preço no PMS, status de reserva por data), get_listing_rate_plans | update_listing_data (mín/base/máx, tags, push_enabled: ligar sync exige revisão de preços na UI nos últimos 7 dias) |
| get_listing_performance_metrics (chave `occupancy:7` com vs_market e vs_stly, bp_ratio, min_prices), get_listing_health_and_recommendations, diagnose_no_bookings | update_listing_date_overrides / update_group_date_overrides (preço fixo ou %, estadia mínima, mín/máx, check-in/out, lead_time_expiry 1-999) e delete_* |
| get_customizations, get_customization_schema, get_customization_profiles, get_groups, get_group_listings | update_customizations: SOMENTE seasonality, last_minute_prices, day_of_week_adjustment, far_out_premium, demand_factor, custom_seasonal_profile (tudo numa chamada, aplicado ou rejeitado junto) |
| get_listing_date_overrides, get_group_date_overrides | accept_nudge, map_listings / unmap_listings, set_neighborhood_data_source |
| get_pms_reservations, get_bookings_report, get_actions (modo visible/all/hidden), get_available_nudges, get_user_logs | refresh_listing_pricing (recalcula o calendário; máx. 3 por anúncio a cada 24h; NÃO envia ao PMS) |
| get_neighbourhood_data (ocupação e preços futuros do mercado, concorrentes), get_listing_neighborhood_market, get_neighborhood_data_sources, get_md_report_compsets, market_research, get_str_index | pricelabs_feedback |
| get_report_builder_templates / get_report_builder_data (ex.: "Hotel Pickup Trends"), get_listing_optimizer_* (só Airbnb), get_knowledge, pricelabs_help | - |

**Limites que mudam o desenho da operação:** (1) não existe "Sync Now" via MCP: o que o Claude escreve chega ao PMS só na sincronização programada, no Real-Time Sync ou num Sync Now manual; (2) MROBA / Portfolio OBA, OBA, estadia mínima, dias órfãos, Preço Mínimo de Segurança, LOS, planos tarifários, Pricing Offset e Hotel Weights (se a feature não estiver liberada) são só na interface; o Claude apenas audita; (3) desligar o toggle de última hora ou distante via API devolve o controle ao padrão de mercado; para suprimir, envie `type: none` com o toggle ON; (4) toda escrita exige confirmação sua no chat.

### 20 prompts prontos (português)

**Monitoramento**

1. "Quem sou eu e quais anúncios tenho, agrupados por hotel (grupo), com PMS e status de sincronização de cada um?"
2. "Para cada tipo de quarto, mostre a ocupação dos próximos 7 dias (occupancy:7) comparada com o mercado e com o mesmo período do ano passado, e liste os que estão abaixo de 70%."
3. "Para o grupo Hotel X, mostre preço recomendado, preço no PMS e status de reserva de cada data dos próximos 7 dias, por tipo de quarto."
4. "Quais tipos de quarto estão com sincronização desligada ou sem envio de preço nas últimas 24 horas?"
5. "Liste todas as reservas com chegada nos próximos 7 dias por canal e por hotel, e as reservas criadas ontem para estadias dentro de 7 dias."
6. "Quais substituições por data existem nos próximos 14 dias em todos os hotéis? Destaque as de preço fixo."
7. "O que a Central de Ações (get_actions, modo all) recomenda corrigir? Agrupe por tipo de alerta."
8. "Por que a Suíte do Hotel X não está recebendo reservas para os próximos 7 dias? (diagnose_no_bookings)"
9. "Compare meus preços dos próximos 7 dias com os percentis 25, 50 e 75 da vizinhança e com o Market Booked Price (get_neighbourhood_data com ocupação e preços futuros)."
10. "Rode o relatório 'Hotel Pickup Trends (current month)' e me diga quais datas dos próximos 7 dias têm OTB < 50% e pickup de 3 dias igual a zero."
11. "Há nudges pendentes de preço mínimo ou base? Mostre valor atual, sugerido e motivo."

**Correção**

12. "Aplique uma substituição de -15% do preço recomendado, estadia mínima 1 noite, nos dias 27 a 29 de setembro em todos os anúncios do grupo Hotel X, com expiração de 2 dias e motivo 'fill7d'. Antes, mostre a tabela do que vai mudar e peça minha confirmação."
13. "Reduza o preço mínimo do anúncio Y em 10% e mantenha base e máximo."
14. "Configure para o grupo Hotel X o Last Minute Prices como % Gradual de -20% em 7 dias e o Far Out como 'none' com o toggle ligado; use get_customization_schema antes e get_customizations depois para confirmar."
15. "Mude a Sensibilidade ao fator de demanda do grupo Hotel X para Moderately Conservative e mantenha Hotel Weights em Mostly Hotel."
16. "Aceite o nudge de preço mínimo do anúncio Y aplicando também aos filhos."
17. "Atualize o cálculo de preços (refresh) de todos os anúncios do Hotel X e me mostre os novos preços dos próximos 7 dias e o timestamp do último envio; lembre-me de clicar em Sincronizar já."
18. "Apague as substituições 'fill7d' já vencidas ou em datas que agora estão acima de 90% de ocupação."

**Auditoria**

19. "Mostre todas as mudanças de preço, mínimo e substituições feitas nos últimos 7 dias e quem as fez (get_user_logs)."
20. "Verifique se algum anúncio ainda está no algoritmo antigo, se todos estão com Property Type Hotel e se o Preço Mínimo de Segurança está ligado em cada grupo."

**Rotina sugerida em dois prompts:** às 8h30, "Rode a checagem de 7 dias: para cada tipo de quarto calcule noites vendidas / 7, liste datas abaixo de 70% e proponha uma DSO percentual por data usando a escada -8% (D5-D7), -15% (D2-D4), -25% (D0-D1), estadia 1 noite, expiração 2 dias, SEM aplicar"; após revisão, "Aplique as substituições propostas, faça o refresh e mostre os novos preços". Semanalmente: prompts 19 e 18.

---

## Erros comuns e armadilhas

1. **Deixar o Preço mínimo em branco**: vira base -30%, sem relação com custo nem com a meta.
2. **Mínimo alto demais**: MROBA, Fator por Poucas Reservas e última hora % param no mínimo; se "Datas com preço mínimo" está alto, nenhum desconto extra adianta.
3. **Desligar o toggle de última hora achando que desligou o desconto**: o padrão de mercado (até 40%) assume; use "No last minute adjustment".
4. **Manter o padrão de hotel "Medium Booking Window"**: ele mira 50% entre 16 e 30 dias e não faz nada dentro de 7 dias.
5. **Última hora Market Driven (Aggressive) ou % Gradual 30% em cima de um MROBA fundo**: empilha até -40% e treina o mercado a esperar.
6. **Preço fixo (Fixed DSO ou última hora Fixed) para descontar**: congela a data, desliga MROBA e fura o piso.
7. **Pricing Offset negativo como promoção**: aplicado depois do mínimo, vende abaixo do custo.
8. **Regra de estadia mínima solta no nível do anúncio**: anula em silêncio todo o bloco do grupo ("tudo ou nada").
9. **Preço base sazonal**: sazonalidade aplicada duas vezes; use só mínimo e máximo sazonais.
10. **Preço Mínimo de Segurança a 110%** numa semana que vendeu caro no ano passado: bloqueia os descontos de última hora.
11. **Sincronização única por noite** com janela de 7 dias: o preço da manhã só chega no dia seguinte.
12. **Tipo de quarto com sync OFF "para segurar preço"**: some da ocupação do grupo e distorce o MROBA.
13. **Regras de yield do PMS ligadas em paralelo**: dois sistemas escrevendo a mesma tarifa.
14. **Planos filhos/não-XML no Booking.com**: reservas invisíveis, ocupação subestimada, descontos indevidos.
15. **Mudanças menores que US$ 5 no Booking.com direto** não são enviadas (Delta Push).
16. **Confiar no MPI como benchmark hoteleiro**: ele compara com 350 anúncios do Airbnb; use a aba Dados de hotéis.
17. **Descontar num mercado uniformemente fraco**: "se todo mundo está com 20%, um corte de preço não cria demanda que não existe".
18. **Julgar pela ocupação e não pelo RevPAR**: 95% com diária destruída reduz margem.
19. **Ficar por período prolongado com quartos colados no mínimo**: o PriceLabs avisa que manter o preço no mínimo por muito tempo pode prejudicar a visibilidade nas OTAs (sem citar um número de dias); os limiares documentados são os do nudge de mínimo: 21 das próximas 30 noites disponíveis no mínimo com o mínimo sem alteração há 10 dias.
20. **Mexer em várias regras ao mesmo tempo**: mude 5-10% por vez e espere 7 dias; métricas atualizam diariamente, recomendações semanalmente.
21. **Ocupação de vizinhança lida como dado do dia**: atualiza a cada 2 dias, tarifas a cada 5; para decisão do dia use Hotel Data (24h).
22. **Restrições de check-in/out e mínimo de 2 noites dentro da janela**: cada restrição some com um dia de chegada vendável.
23. **Mapear tipos de quarto diferentes como pai/filho para "copiar configurações"**: apaga as DSOs futuras dos filhos.
24. **Esperar que o Claude edite MROBA ou estadia mínima**: não estão na API; faça na interface e peça auditoria.
25. **Promoções do Airbnb somadas ao desconto do PriceLabs**: 20% + 20% dá ~36%; Smart Pricing do Airbnb tem de estar OFF.

---

## Fontes

### Algoritmo e preços base/mín/máx
- https://help.pricelabs.co/portal/en/kb/articles/how-is-pricing-calculated
- https://help.pricelabs.co/portal/en/kb/articles/setting-base-price
- https://help.pricelabs.co/portal/en/kb/articles/what-are-minimum-base-and-maximum-prices-how-to-set-them-up
- https://help.pricelabs.co/portal/en/kb/articles/about-hyper-local-pulse-new-algorithm-and-faq
- https://help.pricelabs.co/portal/en/kb/articles/moving-to-hyper-local-pulse
- https://help.pricelabs.co/portal/en/kb/articles/migrating-to-hyper-local-more-than-10-listings
- https://hello.pricelabs.co/blog/overview-of-pricelabs-dynamic-pricing-algorithm-part-1/
- https://hello.pricelabs.co/blog/overview-of-pricelabs-dynamic-pricing-algorithm-part-2/
- https://help.pricelabs.co/portal/en/kb/articles/default-discounts-and-premiums
- https://help.pricelabs.co/portal/en/kb/articles/recommendation-nudges
- https://help.pricelabs.co/portal/en/kb/articles/what-to-do-after-finishing-your-free-trial-a-step-by-step-guide-to-optimize-your-listings
- https://help.pricelabs.co/portal/en/kb/articles/competition-pricing
- https://help.pricelabs.co/portal/en/kb/articles/getting-started-with-pricelabs-a-comprehensive-guide

### Personalizações (ocupação, última hora, pisos, estadia mínima)
- https://help.pricelabs.co/portal/en/kb/articles/occupancy-based-adjustments
- https://help.pricelabs.co/portal/en/kb/articles/portfolio-occupancy-based-adjustments
- https://help.pricelabs.co/portal/en/kb/articles/intra-day-portfolio-occupancy-based-adjustments
- https://help.pricelabs.co/portal/en/kb/articles/last-minute-prices
- https://help.pricelabs.co/portal/en/kb/articles/far-out-prices
- https://help.pricelabs.co/portal/en/kb/articles/advanced-minimum-price-settings
- https://help.pricelabs.co/portal/en/kb/articles/safety-minimum-price-15-10-2024
- https://help.pricelabs.co/portal/en/kb/articles/booking-recency-factor
- https://help.pricelabs.co/portal/en/kb/articles/demand-factor-sensitivity
- https://help.pricelabs.co/portal/en/kb/articles/seasonality
- https://help.pricelabs.co/portal/en/kb/articles/seasonal-minimum-base-and-max-price-settings
- https://help.pricelabs.co/portal/en/kb/articles/how-to-get-seasonal-prices-right-in-pricelabs
- https://help.pricelabs.co/portal/en/kb/articles/pricing-customizations-profiles
- https://help.pricelabs.co/portal/en/kb/articles/pricing-customizations
- https://help.pricelabs.co/portal/en/kb/articles/list-of-all-pricelabs-customizations
- https://help.pricelabs.co/portal/en/kb/articles/adj
- https://help.pricelabs.co/portal/en/kb/articles/customizations-which-allow-below-minimum-prices
- https://help.pricelabs.co/portal/en/kb/articles/customization-hierarchy
- https://help.pricelabs.co/portal/en/kb/articles/understanding-min-nights
- https://help.pricelabs.co/portal/en/kb/articles/hierarchy-of-minimum-stay-restrictions
- https://help.pricelabs.co/portal/en/kb/articles/preventing-gaps
- https://help.pricelabs.co/portal/en/kb/articles/creating-and-using-min-stay-profiles
- https://help.pricelabs.co/portal/en/kb/articles/checkin-checkout-feature
- https://help.pricelabs.co/portal/en/kb/articles/length-of-stay-pricing
- https://help.pricelabs.co/portal/en/kb/articles/weekly-monthly-discount
- https://help.pricelabs.co/portal/en/kb/articles/pricing-offsets-for-mapped-listings
- https://help.pricelabs.co/portal/en/kb/articles/rounding-and-smoothing
- https://help.pricelabs.co/portal/en/kb/articles/extra-person-fee
- https://help.pricelabs.co/portal/en/kb/articles/date-specific-overrides
- https://help.pricelabs.co/portal/en/kb/articles/account-group-customization
- https://help.pricelabs.co/portal/en/kb/articles/editing-pricing-customizations-for-multiple-accounts-groups-or-listings
- https://hello.pricelabs.co/blog/how-to-apply-last-minute-discounts-in-pricelabs/
- https://hello.pricelabs.co/blog/last-minute-pricing-strateg-for-property-managers/

### Hotéis e integrações
- https://help.pricelabs.co/portal/en/kb/articles/hotels-default-customization
- https://help.pricelabs.co/portal/en/kb/articles/recommended-hotel-pricing-settings-to-maximize-revenue-performance
- https://help.pricelabs.co/portal/en/kb/articles/customizations-for-hotel
- https://help.pricelabs.co/portal/en/kb/articles/setting-up-hotel-listings-in-pricelabs
- https://help.pricelabs.co/portal/en/kb/articles/customizations-smart-presets
- https://help.pricelabs.co/portal/en/kb/articles/pricelabs-for-hotels
- https://help.pricelabs.co/portal/en/kb/articles/rate-plan
- https://help.pricelabs.co/portal/en/kb/articles/mapping-listings-within-the-channels-same-pms-mapping
- https://help.pricelabs.co/portal/en/kb/articles/mapping-listings
- https://help.pricelabs.co/portal/en/kb/articles/combined-listings
- https://help.pricelabs.co/portal/en/kb/articles/available-integrations
- https://hotels.pricelabs.co/integrations/
- https://help.pricelabs.co/portal/en/kb/articles/pms
- https://help.pricelabs.co/portal/en/kb/articles/how-to-integrate-pricelabs-with-cloudbeds
- https://help.pricelabs.co/portal/en/kb/articles/mews
- https://help.pricelabs.co/portal/en/kb/articles/how-to-integrate-pricelabs-with-booking
- https://help.pricelabs.co/portal/en/kb/articles/stays
- https://help.pricelabs.co/portal/en/kb/articles/how-to-integrate-pricelabs-with-airbnb-official-airbnb-integration
- https://help.pricelabs.co/portal/en/kb/articles/pricelabs-airbnb-integration
- https://help.pricelabs.co/portal/en/kb/articles/minimum-stay-on-arrival-vs-minimum-stay-through
- https://help.pricelabs.co/portal/en/kb/articles/how-much-does-pricelabs-costs
- https://hello.pricelabs.co/plans/
- https://help.pricelabs.co/portal/en/kb/articles/billing
- https://help.pricelabs.co/portal/en/kb/articles/giving-your-team-access-to-your-pricelabs-account
- https://hotels.pricelabs.co/blog/guide-to-hotel-dynamic-pricing/
- https://hotels.pricelabs.co/blog/7-pricelabs-customization-settings-every-small-hotel-must-use/
- https://hotels.pricelabs.co/blog/hotel-comp-set-analysis/
- https://hotels.pricelabs.co/blog/hotel-pricing-strategies/
- https://hotels.pricelabs.co/blog/8-proven-ways-hotels-use-dynamic-pricing-to-enhance-occupancy/
- https://hotels.pricelabs.co/blog/the-definitive-how-to-for-small-hotels-integrating-pricelabs-with-every-channel-manager/
- https://hotels.pricelabs.co/blog/hotel-revenue-management-guide/
- https://hotels.pricelabs.co/blog/key-metrics-to-track-hotel-dynamic-pricing-success-in-2026/
- https://hotels.pricelabs.co/blog/dynamic-pricing-tools-for-independent-hotels/
- https://hello.pricelabs.co/blog/pricelabs-in-portuguese/
- https://hello.pricelabs.co/pt-br/personalizacoes-pricelabs-faq-essencial-para-gerentes/

### Pesquisa de mercado e visibilidade
- https://help.pricelabs.co/portal/en/kb/articles/listing-market-data
- https://help.pricelabs.co/portal/en/kb/articles/listing-hotel-data
- https://help.pricelabs.co/portal/en/kb/articles/market-intel-dashboard
- https://help.pricelabs.co/portal/en/kb/articles/market-dashboard-faqs
- https://help.pricelabs.co/portal/en/kb/articles/creating-a-market-dashboard
- https://help.pricelabs.co/portal/en/kb/articles/market-dashboard-use-case-guided-templates
- https://help.pricelabs.co/portal/en/kb/articles/key-performance-indicators
- https://help.pricelabs.co/portal/en/kb/articles/price-occupancy-trends
- https://help.pricelabs.co/portal/en/kb/articles/los-booking-window
- https://help.pricelabs.co/portal/en/kb/articles/market-dashboard-how-to-create-and-apply-custom-compsets
- https://help.pricelabs.co/portal/en/kb/articles/understanding-comp-sets-in-pricelabs-a-comprehensive-guide
- https://help.pricelabs.co/portal/en/kb/articles/how-to-use-comp-sets-for-benchmarking
- https://help.pricelabs.co/portal/en/kb/articles/ai-insights-neighborhood-data
- https://help.pricelabs.co/portal/en/kb/articles/understanding-getting-started-pricelabs-revenue-estimator-pro
- https://help.pricelabs.co/portal/en/kb/articles/introduction-to-listing-optimizer
- https://help.pricelabs.co/portal/en/kb/articles/listing-optimizer-dashboard
- https://help.pricelabs.co/portal/en/kb/articles/understanding-how-we-grade-your-listing-quality
- https://help.pricelabs.co/portal/en/kb/articles/ranking-overview-page
- https://help.pricelabs.co/portal/en/kb/articles/pricelabs-listing-optimizer-billing-subscription-cancellation-and-invoices
- https://hello.pricelabs.co/blog/airbnb-ranking-algorithm/
- https://hello.pricelabs.co/blog/booking-com-promotion-discount-logic/
- https://hello.pricelabs.co/blog/how-does-booking-com-work/
- https://hello.pricelabs.co/blog/airbnb-seasonal-cancellation-policy/
- https://hello.pricelabs.co/blog/instant-booking-all-you-need-to-know-about-it/
- https://hello.pricelabs.co/blog/airbnb-listing-optimization-guide/
- https://help.pricelabs.co/portal/en/kb/articles/guide-for-using-both-airbnb-booking-com-and-vrbo-with-pricelabs
- https://help.pricelabs.co/portal/en/kb/articles/how-pricelabs-dynamic-pricing-strategy-can-affect-your-airbnb-ranking

### Análises, métricas e Central de Ações
- https://help.pricelabs.co/portal/en/kb/articles/performance-metrics
- https://help.pricelabs.co/portal/en/kb/articles/multicalendar
- https://help.pricelabs.co/portal/en/kb/articles/understanding-your-dashboard
- https://help.pricelabs.co/portal/pt/kb/articles/compreendendo-o-seu-painel-de-pre%C3%A7os
- https://help.pricelabs.co/portal/pt/kb/articles/pricing-customizations-7-6-2023
- https://help.pricelabs.co/portal/pt/kb/articles/portfolio-occupancy-based-adjustments-11-2-2024
- https://help.pricelabs.co/portal/pt/kb/articles/configura%C3%A7%C3%B5es-de-pre%C3%A7os-recomendadas-para-hot%C3%A9is-para-maximizar-a-performance-de-receita
- https://help.pricelabs.co/portal/pt/kb/articles/personaliza%C3%A7%C3%B5es-para-propriedades-de-hotel
- https://help.pricelabs.co/portal/pt/kb/articles/sugest%C3%B5es-de-recomenda%C3%A7%C3%A3o-de-pre%C3%A7os
- https://help.pricelabs.co/portal/en/kb/articles/pricing-calendar
- https://help.pricelabs.co/portal/en/kb/articles/what-do-the-colors-mean-a-guide-to-pricelabs-calendar-color-coding
- https://help.pricelabs.co/portal/en/kb/articles/action-center
- https://help.pricelabs.co/portal/en/kb/articles/what-is-portfolio-analytics-and-how-to-use-it-2-1-2024
- https://help.pricelabs.co/portal/en/kb/articles/portfolio-analytics-14-12-2023
- https://help.pricelabs.co/portal/en/kb/articles/portfolio-analytics-kpi
- https://help.pricelabs.co/portal/en/kb/articles/portfolio-analytics-for-hotels
- https://help.pricelabs.co/portal/en/kb/articles/portfolio-analytics-integrations
- https://help.pricelabs.co/portal/en/kb/articles/portfolio-analytics-terminology
- https://help.pricelabs.co/portal/en/kb/articles/mastering-portfolio-analytics-using-pacing-reports-to-track-and-adapt
- https://help.pricelabs.co/portal/en/kb/articles/pricing-recipe-using-booking-curves-a-step-by-step-guide
- https://help.pricelabs.co/portal/en/kb/articles/refresh-reservations-data-in-portfolio-analytics
- https://help.pricelabs.co/portal/en/kb/articles/getting-started-with-report-builder
- https://help.pricelabs.co/portal/en/kb/articles/creating-reports-tailored-to-your-hotel-report-builder
- https://help.pricelabs.co/portal/en/kb/articles/a-complete-guide-to-report-builder-metrics
- https://help.pricelabs.co/portal/en/kb/articles/report-builder-excel-formulas-for-custom-metrics
- https://help.pricelabs.co/portal/en/kb/articles/ai-insights-in-report-builder
- https://help.pricelabs.co/portal/en/kb/articles/scheduling-report-emails-in-report-builder
- https://help.pricelabs.co/portal/en/kb/articles/how-are-market-values-populated-in-report-builder
- https://help.pricelabs.co/portal/en/kb/articles/forecasting-with-report-builder
- https://help.pricelabs.co/portal/en/kb/articles/setting-up-and-managing-goals-beta
- https://help.pricelabs.co/portal/en/kb/articles/bookings-table-in-portfolio-analytics
- https://help.pricelabs.co/portal/en/kb/articles/understanding-owner-analytics-dashboard
- https://hello.pricelabs.co/revenue-potential-with-the-new-report-builder/
- https://hotels.pricelabs.co/blog/how-pacing-reports-drive-smarter-decisions-and-revenue-growth-in-hotels/
- https://hotels.pricelabs.co/blog/dynamic-pricing-benchmarks-for-hotels-in-2026-stay-competitive/

### Sincronização, logs e API/MCP
- https://help.pricelabs.co/portal/en/kb/articles/how-often-are-my-rates-sycned-to-my-pms-and-how-does-sync-now-work
- https://help.pricelabs.co/portal/en/kb/articles/timed-sync
- https://help.pricelabs.co/portal/en/kb/articles/real-time-sync
- https://help.pricelabs.co/portal/en/kb/articles/understanding-the-settings-page
- https://help.pricelabs.co/portal/en/kb/articles/understanding-manage-listings-page
- https://help.pricelabs.co/portal/en/kb/articles/upload-download-features
- https://help.pricelabs.co/portal/en/kb/articles/price-evolution-logs-track-price-changes
- https://help.pricelabs.co/portal/en/kb/articles/account-logs-track-settings-changes-made-in-your-account
- https://help.pricelabs.co/portal/en/kb/articles/pricelabs-mcp-ai-connector
- https://help.pricelabs.co/portal/en/kb/articles/pricelabs-api
- https://developers.pricelabs.co/mcp/overview
- https://developers.pricelabs.co/mcp/connectors/connect-to-claude
- https://developers.pricelabs.co/mcp/tools/overview
- https://developers.pricelabs.co/mcp/tools/customizations.md
- https://developers.pricelabs.co/mcp/tools/date-specific-overrides.md
- https://developers.pricelabs.co/mcp/tools/pricing.md
- https://developers.pricelabs.co/mcp/tools/listings.md
- https://developers.pricelabs.co/mcp/tools/actions.md
- https://developers.pricelabs.co/mcp/tools/diagnostics-and-kpis.md
- https://developers.pricelabs.co/mcp/tools/market-insights.md
- https://developers.pricelabs.co/mcp/tools/pricelabs-agents.md
- https://developers.pricelabs.co/mcp/tools/listing-optimizer.md
- https://developers.pricelabs.co/mcp/changelog/changelog.md
- https://developers.pricelabs.co/mcp/cookbook/empty-calendars.md
- https://developers.pricelabs.co/mcp/cookbook/market-position.md
- https://developers.pricelabs.co/customer-api/api-reference/enable-the-api.md
- https://developers.pricelabs.co/customer-api/api-reference/customer-api/customizations/update-capi-listing-customizations.md
- https://developers.pricelabs.co/customer-api/api-reference/customer-api/date-specific-overrides-dso/listing-date-level-overrides-1.md
- https://developers.pricelabs.co/customer-api/api-reference/customer-api/date-specific-overrides-dso/update-group-overrides.md
- https://developers.pricelabs.co/customer-api/api-reference/customer-api/prices/refresh-listing.md
- https://developers.pricelabs.co/llms.txt

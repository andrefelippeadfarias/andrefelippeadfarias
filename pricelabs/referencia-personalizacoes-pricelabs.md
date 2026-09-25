# Rótulos em PT-BR das personalizações do PriceLabs (fonte: help.pricelabs.co/portal/pt "Lista de todas as personalizações do PriceLabs")

## Restrições de estadia (nível: propriedade)
- Regra de estadia mínima padrão (Default Min Stay Rule) — opções: Fixo / Valor da Reserva / Nenhum
- Intervalos livres (Minimum Stay for Orphan Bookings / gap-fill) — Comprimento do intervalo / Duração do intervalo −1 ou −2 / Escolha um número
- Reservas de última hora (Minimum Stay for Last-Minute Bookings) — até 3 regras, semana/fim de semana
- Reserva distante (Minimum Stay for Far-Out Bookings) — até 3 regras
- Dia adjacente antes/após uma noite indisponível (Adjacent Bookings)
- Menor estadia mínima permitida (Lowest Minimum Stay Allowed) — prioridade máxima
- Restrições de check-in/check-out (Check-in/Check-out restrictions)
- Hierarquia (maior→menor prioridade): Menor estadia permitida > Órfãs > Substituições de data > Adjacente depois > Adjacente antes > Distante > Última hora > Padrão
- Botão "Sugestões de configuração disponíveis!" gera regras a partir de dados hiperlocais
- "Selecione um perfil de estadia mínima" / "+ Criar perfil"
- Caminho: Dynamic Pricing > Personalização > "Editar" no painel de preços da propriedade

## Personalizações de preços – Geral
- Personalizar preços de última hora (Last Minute Prices) — padrão: desconto GRADUAL de 30% nos próximos 15 dias; opções: % fixa / % gradual / valor fixo / nenhum
- Personalizar preços de dia dos órfãos (Orphan Day Prices) — padrão: 20% de desconto para intervalos de 1–2 dias; até 5 níveis; semana/fim de semana separados; "Sem ajuste de dia órfão"
- Ajustes de preços para dias da semana (Day of Week Pricing Adjustments) — −75% a +500%, aplicado POR CIMA da recomendação
- Ajustes baseados na taxa de ocupação (Occupancy Based Adjustments) — % personalizada ou perfis de estratégia pré-carregados
- Descontos semanais / Descontos Mensais (Weekly / Monthly discounts)
- Ajustes de preços com base na duração da estadia (Length of Stay pricing)
- Taxas por pessoa adicional (Extra person fee)

## Personalizações de preços – Sazonais e mínimos
- Perfis sazonais personalizados (Custom Seasonal Profiles) — mín/base/máx por temporada
- Sazonalidade (Seasonality) — magnitude da flutuação sazonal
- Preço mínimo de fim de semana (Minimum Weekend Price) — Fixo / % do preço base / % do preço mínimo
- Preço mínimo para reservas distantes (Minimum Far-out Price) — piso maior para além de X dias (preserva descontos de última hora dentro da janela)

## Personalizações de preços – Avançadas
- Dias de fim de semana (Define Your Own Weekend) — Sex-Sáb padrão; Qui-Sex-Sáb, Sex-Sáb-Dom, Qui-Sex-Sáb-Dom
- Sensibilidade ao fator de demanda (Demand Factor Sensitivity)
- Personalizar preços de reservas distantes (Far-out Prices)
- Fonte de dados do perfil da vizinhança (Neighborhood profile data source)
- Ajuste de preços (Pricing offsets for mapped listings) — Fixo ou %
- Fator adjacente (Adjacent factor)
- Arredondamento (Rounding) / Atenuação (Smoothing)

## Personalizações em massa (nível: conta)
- Ajustes baseados na taxa de ocupação do portfólio (Portfolio Occupancy Based Adjustments)

## Preço mínimo – configurações avançadas (artigo "Configurações avançadas de preço mínimo")
- Preço mínimo da propriedade (campo obrigatório recomendado; canto superior esquerdo em Revisar preços; em massa via "Gerenciar propriedades")
- Preço mínimo para reservas distantes (ex.: 80 última hora / 100 para 7+ dias)
- Preço mínimo de fim de semana
- Preço mínimo sazonal (via perfis sazonais)
- Preço mínimo para eventos (via substituição de data específica; % ex. "preço base − 20%")
- Substituição manual (preço fixo ou %) IGNORA a faixa mín–máx quando aplicada explicitamente pelo usuário

## Interações
- Órfão + última hora, ambos desconto → aplica o MAIOR desconto; ambos aumento → ambos; misto → ambos
- Conta/grupo se aplicam a propriedades SEM personalização individual
- Ao remover uma personalização, o PMS/OTA mantém o último valor enviado até você alterar

## Market Dashboards (rótulos PT)
- Datas futuras importantes; Taxa de ocupação, reservas e cancelamentos futuros; Preços futuros; Taxa de ocupação em dias de semana; Fator de preços para dias da semana

## Dados de hotéis (artigo "Dados de hotéis")
- Fonte: Booking.com; preços atualizados a cada 24h (outra página: tarifas de hotéis a cada 48h, metadados a cada 15 dias)
- Buscador de preços de hotéis; seleção de concorrentes: 10 mais próximos / lista até 350 / mapa; filtros: 1–4 noites, reembolsável, café da manhã
- Correspondência de tipos de quarto (room type matching); tabela com variação 48h; gráfico com preço recomendado; markup/markdown; CSV 366 dias

## MCP (developers.pricelabs.co)
- Escopos: Read / Write / Customization write (separado)
- Ferramentas: pricelabs_help, get_me, get_knowledge, get_listings, get_listing_data, update_listing_data (min/base/max, tags, sync), get_groups, get_group_listings, map_listings/unmap_listings, get_listing_prices, refresh_listing_pricing, get_listing_rate_plans, get_customizations, update_customizations, get_customization_schema, get_customization_profiles, get_listing_date_overrides, update_listing_date_overrides, delete_listing_date_overrides, get_pms_reservations, get_str_index, get_listing_neighborhood_market, get_neighbourhood_data (include_prices/include_future_prices/include_occupancy), get_listing_performance_metrics (DFD keys; -999 all-time, -997 current month; occupancy/ADR/RevPAR/adjusted occ/days at min price/STLY + market_level), get_account_review_report, get_listing_health_and_recommendations, diagnose_no_bookings, get_listing_optimizer_summary/report, get_report_builder_templates/data/poll, pricelabs_feedback
- Team: Account Settings → Team Settings → Edit → "MCP Access" = Allow; segue permissões de leitura/escrita por listing já definidas
- Só aparece em NOVAS conversas

## Ajustes baseados na ocupação (Occupancy Based Adjustments) – artigo EN
- "Ocupação horizontal": noites reservadas / noites disponíveis numa janela; padrão Market-Driven olha os PRÓXIMOS 60 DIAS
- Multi-unidade: ocupação COMBINADA das unidades (ex.: 8 unidades, 11 de 16 noites = 69%)
- Exemplo tabela padrão: 20% ocup. → −15%; 40% ocup. → −5%
- Perfis: Market-Driven (padrão; compara com ocupação do mercado; desconto máx 20%, prêmio máx 15%), Aggressive (descontos até 30%), Step Last-Minute Discount (degraus; DESLIGA a lógica de ocupação padrão), Far-Out Premium, Super Aggressive Discounting, Custom, PriceLabs Default (legado)
- Empilha com sazonalidade, dia da semana, eventos; NUNCA abaixo do mín nem acima do máx; preço fixo (override) tem prioridade
- Datas bloqueadas contam como reservadas (exceto PMSs específicos)
- UI: Pricing Dashboard → Review Prices → Edit (Customizations) → All Customizations → Occupancy-Based Adjustments → ligar → perfil → Save

## Ajustes baseados na ocupação do portfólio (Portfolio Occupancy Based Adjustments) – no "hotel mode" aparece como "Multi-Room Occupancy-Based Adjustment"
- Nível listing/tipo de quarto: ocupação daquele tipo; nível grupo: média das listings do grupo por data (só listings com Sync ON); janela mais curta prevalece
- Requer 2+ quartos; mais consistente com 5+ unidades
- Faixas de dias (0–15, 16–30…) crescentes; cada banda de ocupação com ajuste menor que a anterior
- Perfis: Short Booking Window (alvo 50% ocupação a 11–20 dias), Medium (16–30), Long (31–60), Custom, None
- Faixa permitida: −50% a +500%; exemplos −25% a +15%
- Camada sobre o algoritmo e demais personalizações; sobrescrito por preço fixo (listing) e "% do preço base" (grupo/conta); respeita mín/máx
- UI listing: Pricing Calendar → Customizations → Edit → "Portfolio Occupancy-Based Adjustment" → All Days ou Weekend/Weekday → perfil → Save
- UI grupo: Dynamic Pricing → Customizations → aba Groups → Edit → ...

## Métricas de desempenho (Performance Metrics – dashboard/multi calendar)
- Janelas: next 7d, 30d; past 7d, 15d; Multi Calendar permite datas selecionadas
- Total Occupancy = datas reservadas / total (bloqueios contam); Adjusted Occupancy exclui bloqueios do proprietário (quando o PMS informa; Booking.com/Tokeet não)
- "Minimum Price Hitting Dates" = % de datas disponíveis no preço mínimo
- Cores vs mercado (~350 listings similares em 15 km): vermelho <80%, amarelo 80–100%, verde 100–120%, azul >120%
- MPI = ocupação da listing / ocupação do mercado; STLY para Revenue/RevPAR/ADR

## Guia pós-trial (ordem recomendada)
1 Base Price (ajustes de 5–10%; Base Price Help Tool; alerta se desvio >5% da recomendação) → 2 Performance Metrics diariamente → 3 Sync ("Last Synced") → 4 Min Price (alerta: 21+ dias no mínimo por mais de 10 dias → reduzir) → 5 Min Stay (reduzir última hora, aumentar distante; perfis sazonais) → 6 Benchmark (Future Prices chart, Neighborhood Data); revisão quinzenal; "Aggressive" se ocupação baixa; muitas reservas rápidas = subprecificado

## Multi-unit (hello.pricelabs.co)
- Unidade base = mais padrão/menor; outras com prêmio % (ex. +5% vista) ou fixo (+R$20); cobertura +15–20%
- POBA para o conjunto; Multi Calendar para acompanhar por unidade

## Última hora (Last Minute Prices) – artigo EN
- 4 modos: Preço fixo (ex. R$70 por 10 dias), % fixa (ex. 25% por 5 dias), % gradual (divide % pelos dias: 35% em 7 dias ≈ 5%/dia até 35% no dia 0), Nenhum
- Padrão: Hyper Local Pulse → "Market Driven (Balanced)" (ajusta diariamente conforme mercado); algoritmo antigo → gradual 30% em 15 dias
- Janela máxima: 90 dias; NÃO permite degraus (30/14/7) dentro do recurso → usar Occupancy-Based Adjustments (perfil Step Last-Minute Discount)
- "Last-Minute Minimum Price": piso separado, só dentro da janela de última hora, pode ficar abaixo do mínimo padrão
- Níveis: listing, grupo, conta; Market Driven exige HLP e aplicação no nível listing
- UI: Pricing Dashboard → Review Prices → Edit Customizations → All Customizations → Last Minute Prices

## Hotéis (hotels.pricelabs.co)
- Hyper Local Pulse (HLP): preço por quarto usando desempenho da propriedade, tarifas de concorrentes, eventos e demanda; recalcula diariamente; mercado hiperlocal = 350 listings similares até 15 km (hexágonos H3); detecção de eventos 4 vias (pacing ano anterior, sinais precoces, preços de concorrentes, preços de hotéis); usa OCUPAÇÃO PREVISTA; até +9% receita
- Market-Driven defaults (só HLP): última hora e far-out recalculados diariamente conforme ocupação, pacing e velocidade de reservas do mercado
- MROBA (Multi-Room Occupancy-Based Adjustments): ajusta cada TIPO de quarto conforme a velocidade de ocupação daquele tipo
- Room-Type Specific Rules: Property Dashboard → tipo de quarto → Base Price and Dynamic Pricing Settings
- Real-Time Sync: até 24 atualizações/dia em PMSs selecionados; sync padrão diário (24h)
- Compset: até 350 hotéis/STRs próximos; dados de hotéis via Booking.com
- Offsets típicos entre categorias: Superior +15%, Deluxe +30%, Suíte +70%; preferir % (escala com sazonalidade); quarto standard = piso/âncora
- Hotel Weights: calibrar peso de dados de hotéis vs STR no HLP
- 60+ integrações hoteleiras / 160+ PMS no total; 35k+ quartos; trial 30 dias sem cartão; preço por quarto
- Táticas: limiares de ocupação 60/75/90%; 25–35% das reservas nos últimos 14 dias; early bird 10–15% a 60+ dias; comissão OTA 18–23%

## Recomendações / Revenue Accelerator 2026
- Recomendações personalizadas após 7 dias de sync; janela de 60 dias; atualizadas semanalmente, válidas por 7 dias; se ocupação < mercado → reduzir base ou perfil "Aggressive" no OBA
- Market-Driven Base Price Helper; Market-Driven OBA; Group-Level Preview Prices (sandbox); Advanced DSO management; Alerts (em breve); Safety Minimum Price (piso dinâmico vs STLY); Listing Optimizer (rank diário, 60 dias); Min Stay Recommendation Engine (anual + mensal); Report Builder com AI Insights; relatórios: Goal Achievement & Budget Tracker, Historical Event Performance, Events Pickup & Pricing Strategy; Bookings Report; Forecasting (semanal, receita e ocupação); Owner Analytics (em breve); Group Creation Wizard; Bulk Updates; app mobile (em breve)
- Sync empurra: diárias, estadia mínima, disponibilidade/bloqueios, descontos de última hora, ajustes de eventos; guardrails mín/máx
- Integrações BR confirmadas na KB: Stays.net (envia diárias, estadia mínima, LOS pricing, check-in/out até 540 dias). Omnibees/HSystem/Desbravador: não encontrados na KB (a confirmar)

## Safety Minimum Price (SMP)
- Piso = ADR do mesmo dia da semana no ano anterior, média ponderada de 3 semanas (eventos: máximo das 3 semanas) × fator (padrão 110%; recomendado 100–110%)
- Opções: PriceLabs Recommended / Do Not Apply / Custom (faixas de datas e feriados)
- Se SMP < Min Price → vale o Min Price. Requer PMS que envie receita por noite
- UI listing: Review Prices → Customizations Edit → All Customizations → Safety Minimum Price; grupo/conta: Dynamic Pricing → Customizations → Accounts/Groups → Edit
- Habilitado por padrão na maioria das contas

## Sync
- Automático 1×/dia entre 23:00/0:00 e 12:00/13:00 GMT; "Sync Now" manual (exige "Enable Price Sync"); 99,97% confiabilidade
- Timed Sync: 1 horário próprio grátis (00:00–18:00 GMT); syncs adicionais US$1/listing/mês (ex. 0h, 8h, 16h) — recomendado para OBA/POBA e portfólios grandes; Account → Settings → Sync Settings → "Specify Your Own Time"
- Real-Time Sync: até 24 syncs/dia, US$2/unidade/mês, via webhooks do PMS (nova reserva, cancelamento, modificação, bloqueios), intervalo mínimo 60 min; PMSs hoteleiros com webhook (Cloudbeds, Mews, Apaleo, MiniHotel, Octorate…); Account Settings → Sync Settings; recalcula com OBA na hora
- Stays.net: envia diárias, estadia mínima, LOS pricing, check-in/out até 540 dias

## Top-10 Q&A (blog)
- REC/Base Price Ratio: 1,0 = ok; 0,98 sugere baixar base
- OBA exemplo: −15% se ocupação baixa a 15 dias
- Adjacent Day Factor: desconto em dias adjacentes a reservas
- Min stay dinâmico: 5 noites longe → 2 noites perto
- Report Builder: Leaderboard e Opportunities Reports; métrica "last booked date"
- Descontos semanais/mensais configurados no Airbnb SOBRESCREVEM tarifas do PriceLabs (evitar duplicar descontos)
- Smart Presets como base por tipo de propriedade

## Base Price (artigo)
- Base = diária média do ANO; "Help me choose a base price": Market-Driven (percentis 25/50/75; ≥30 listings; informar taxa de limpeza e markup do PMS; categoria Economy/Midscale/Upscale), Imported, Recommended (após 2–3 semanas), Custom
- Nudges quando recomendação diverge >7% da base; ajustar 5–10% por vez, esperar 1–2 semanas; revisar a cada 2–3 semanas no início
- Recomendações após 7 dias (14–21 novos): pirâmides verdes (subir) / vermelhas (baixar); fatores: ADR, ocupação, pickup vs mercado, avaliações, amenidades, taxas, Superhost
- Não incluir impostos na base; não usar tarifa de alta temporada como base; sempre definir Min Price

## Guia hoteleiro (hotels.pricelabs.co/blog/guide-to-hotel-dynamic-pricing)
- Ordem: 1 Smart Presets (Settings > Advanced Settings) → 2 Orphan Day Prices (padrão −20%; até 5 faixas; fixo ou %; semana/fds; ex. "gap de 1 noite em 7 dias → R$150 fixo") → 3 Last-Minute (gradual 30%/15 dias) e Far-Out Floor (piso p/ 60+ dias; ex. mín R$250 vs mín base R$150) → 4 Day-of-Week (−75% a +500%; ex. ter/qua +10% corporativo) → 5 OBA (ex. +10% quando ocupação semanal ≥80%) → 6 Perfis sazonais (ex. baixa temporada mín R$120 vs R$200)
- Prioridade: Listing (tipo de quarto) > Subgrupo > Grupo > Conta
- Empilhamento: vários DESCONTOS → só o maior; vários PRÊMIOS → somam
- Podem furar o Min Price: DSO fixo, perfis sazonais, Last-Minute com PREÇO FIXO (não %), offsets e descontos de longa estadia (pós-algoritmo). Descontos % de última hora respeitam o piso
- Preview Prices Graph (30/60/365 dias: linha vermelha tracejada atual vs preta proposta); Table View (círculo verde ativo / cinza inativo ou sobreposto)

## Grupos (account-group-customization)
- Conta → Grupo → Subgrupo (habilitar via suporte) → Listing; listing sobrescreve grupo/conta; não dá para "reverter ao padrão", só atualizar
- Ajustes % ou fixos (fixo só no nível grupo); preferir % para listings heterogêneas; bulk refresh & sync por grupo (3 pontinhos); notas/lembretes
- Aba Accounts/Groups/Listings → marcar → Edit Customization → Preview & Save; ao mapear, personalizações do pai são copiadas ao filho (DSOs do filho são removidas)

## Manage Listings
- Colunas: nome, Sync Prices ON/OFF, Property Type (Short Term Rental / Hotel / Apart Hotel / Mid Term Rental / More), Airbnb ID, BR count, localização, Min/Base/Max, Fees; bulk: tags, grupos, ocultar/excluir (só sem sync), preços; CSV import/export; Map Listings

## Demand Factor Sensitivity (6 níveis)
- No Demand Factor / Conservative / Moderately Conservative / Recommended (padrão) / Moderately Aggressive / Aggressive; alto = maior variação em eventos e última hora, baixo = preços estáveis e reservas antecipadas; Hotel Weights e Hotel Compsets sob solicitação

## Hotel Rate Shopper (Listing Hotel Data)
- Booking.com, 24h; "Select 10 Nearest"; lista até 350; mapa; busca por nome; Room Type Matching; filtros LOS 1–4, reembolso, café; markup/markdown; CSV 366 dias; Hotel Weights: padrão 10 hotéis mais próximos influenciam a recomendação

## Portfolio Analytics
- 3 áreas: KPIs & Historic Reports; Pacing Reports (ocupação, ADR, RevPAR, tarifas listadas, mercado; comparações vs mercado, vs STLY mesmo dia, vs resultado final ano passado; janelas 1/3/6/12 meses; diário/semanal/mensal); Report Builder (templates: Revenue On The Books, Occupancy Pacing, Market Opportunities, Leaderboard, Opportunities; AI Insights)
- Pickup = quanto da ocupação entrou nos últimos 7 dias; Market Pickup idem do mercado
- Receita de booking curves: à frente da curva → segurar/subir; atrás + dentro da janela de reservas → agir agressivo (cortes, descontos maiores); fora da janela + ADR similar → esperar; perto de lotar → subir levemente
- Leitura: ADR alto + ocupação baixa = preço alto; RevPAR baixo = ocupação baixa ou preço inadequado; ADR abaixo = vendendo barato para ganhar ocupação

## Airbnb + Booking.com
- Offsets por canal no nível CONTA; Booking.com atualiza até 720 dias, Airbnb 540; Booking.com "Delta Push": só atualiza se diferença ≥ US$5; PriceLabs não gerencia disponibilidade (iCal)
- Descontos configurados no Airbnb (semanal/mensal) sobrescrevem o PriceLabs → evitar duplicar
- Preço competitivo é 1 dos 3 fatores do ranking Airbnb (com qualidade e popularidade)

## Listing Optimizer
- Nota A–D; conteúdo, fotos, experiência, amenidades; Ranking Analysis (Airbnb, semanal); só Airbnb (Host ID ou URL)

## Integrações hoteleiras (hotels.pricelabs.co/integrations – 62 nomes)
- Confirmados: Cloudbeds, Mews, Octorate, Apaleo, RoomRaccoon, Eviivo, MiniHotel, STAAH, SabeeApp, Rentlio, RMS Cloud, NewBook, ResNexus, Amenitiz, PXSOL, ChanneX, Zak, WebHotelier, Yield Planet, Update247, BookandLink, HotelAvailabilities, Previo, Elina, etc.
- NÃO listados: Omnibees, HSystem, Desbravador, CM Net, Silbeck, TOTVS, Hospedin, SiteMinder, Little Hotelier, Beds24 (Beds24 aparece no hello.pricelabs.co/integrations)
- Omnibees "parceiros" não cita PriceLabs

## Integrações (artigo PT "Integrações disponíveis" – 170+)
- OTAs diretas: Airbnb, Booking.com, Vrbo, Houfy
- PMS/CM (100+): Cloudbeds, Mews, Apaleo, Beds24, Guesty, Hostaway, Lodgify, Smoobu, Octorate, RoomRaccoon, Eviivo, Staah, MiniHotel, Rentlio, ResNexus, RMS Cloud, NewBook, Avantio, Channex, Rentals United, Tokeet, Uplisting, OwnerRez, Hostfully, Hostify, iGMS, Zeevou, WebHotelier, YieldPlanet…
- Via Dynamic Pricing API (70+): Amenitiz, BBPlanner, Hotel Link, My Bookings, StayFlexi, Update247, HotelAvailabilities, QloApps, Resly…
- BR/LatAm: só Avantio (ES); Stays.net tem artigo próprio na KB; Omnibees, HSystem, Desbravador, CM Net, Silbeck, TOTVS, Hospedin NÃO aparecem (nem PriceLabs aparece nos parceiros da Omnibees/HSystem) → caminho para hotel BR: PMS/CM integrado (ex. Cloudbeds, Mews, Beds24, Octorate, Staah, Channex) ou Booking.com direto + Airbnb, ou Dynamic Pricing API
- Hotel: "Property Type" = Hotel / Apart Hotel em Manage Listings

## Market Dashboards (detalhe)
- KPIs (7/30/365 dias): Estimated Revenue, RevPAR, Occupancy %, ADR, Available Listings, Bookings, Booking Window, Length of Stay (vs período anterior)
- Price & Occupancy Trends: Key Future Dates; Future Occupancy/Bookings/Cancellations; Future Prices (Median Booked Price)
- Booking Curves (Pacing): Current Months (Days Until Stay Date) e Compare With Last Year
- Future LOS; Recent Bookings by booking window; Day of Week Occupancy & Price Factor (30 dias); Listing Map; Amenities; Policies & Fees (descontos semanais/mensais, limpeza, cancelamento)
- Comp Sets + Comp Set KPI Quick View; Competitor Calendar (preço, min stay, disponibilidade por data); ligar compset ao Neighborhood Data: Review Prices → Neighborhood Data → ícone de engrenagem → Market Dashboard → CompSet
- Case Guided Templates (6 casos); 1 dashboard grátis; US$9,99/dashboard/mês; atualiza diariamente; export PDF

## DSO (Substituições específicas de data) – artigo EN
- Criar: arrastar no calendário (mensal/anual), botão "Add Date Specific Override", ou Multi Calendar (várias listings); faixa roxa no calendário
- Tipos: Fixed Price (listing/grupo; IGNORA mín/máx), % of Recommended Price (respeita mín/máx), % of Base Price (fixo; conta/grupo), Min/Max Price override, Minimum Stay, Check-In/Check-Out
- Extras: filtro por dia da semana; copiar overrides; Override Expiry (data de expiração automática); "Add and Refresh" / "Save and Refresh"; ver via botão "View" ou página Customizations; excluir pela lixeira (não via CSV)
- Listing-level DSO > account-level; exige estadia mínima padrão definida para override de min stay

## Hierarquia (customization-hierarchy)
- Níveis: Listing > Subgrupo > Grupo > Conta
- Preços fixos: DSO (Fixed Price) → Last Minute Price (fixo) → Orphan Day Price; "% change on Base Price" de conta/grupo conta como fixo e passa por cima de mín/máx da listing
- Última hora + órfão: ambos descontos → maior; misto ou ambos prêmios → ambos
- Min stay: DSO → Far-Out → Last-Minute → Default; órfão só sobrescreve quando resulta em MENOR mínimo

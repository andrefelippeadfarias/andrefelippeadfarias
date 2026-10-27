# Registro de mudanças no PriceLabs

Cada mudança feita na conta, com o valor anterior e como desfazer. O estado antes da primeira mudança está em `snapshot-configuracao-2026-09-25.json`.

## 25/09/2026, 14:01 — Fase 1, Dia 1 (aprovado por você)

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Fator de demanda | Grupo Recanto dos Moinhos | Aggressive | Recommended. Compset de hotéis "Selected in Hotel Data tab" e peso "Mostly Hotel" mantidos | "Volte o Fator de Demanda do grupo para Aggressive" |
| Sazonalidade | Grupo Recanto dos Moinhos | Aggressive | Recommended | "Volte a Sazonalidade do grupo para Aggressive" |
| Última hora | Grupo Recanto dos Moinhos | Market Driven (Aggressive) | No last minute adjustment ("No Discount"), chave ligada | "Volte a Última hora do grupo para Market Driven Aggressive" |
| Preço mínimo | Villa King Balcony (6) | R$ 800 | R$ 950 | "Volte o mínimo da Balcony para 800" |
| Estadia mínima 2 noites, expirando 3 dias antes da data | Os 7 quartos, em 11/10, 01/11 e 19/11 | 1 noite (regra do PriceLabs) | 2 noites até 3 dias antes da data. Depois volta a 1 noite | "Apague as substituições de 11/10, 01/11 e 19/11 dos 7 quartos". Não havia outras substituições nessas datas |

**Ofertas da Booking:** nenhuma foi alterada. Todas são tratadas como obrigatórias.

### Efeito nos preços (recalculado no PriceLabs às 14:02, próximos 60 dias)

| Quarto | Dia de semana, mediana | Fim de semana, mediana |
|---|---|---|
| Queen Spa (7) | R$ 1.076 → R$ 1.126 (+5%) | R$ 2.776 → R$ 2.291 (−17%) |
| Double Spa | R$ 1.232 → R$ 1.248 (+1%) | R$ 3.051 → R$ 2.464 (−19%) |
| Afrodite (1) | R$ 1.582 → R$ 1.683 (+6%) | R$ 4.206 → R$ 3.527 (−16%) |
| Queen Spa (2) | R$ 1.333 → R$ 1.418 (+6%) | R$ 3.603 → R$ 2.988 (−17%) |
| Villa King Spa (7) | R$ 1.148 → R$ 1.229 (+7%) | R$ 2.880 → R$ 2.503 (−13%) |
| Balcony (6) | R$ 1.011 → R$ 1.058 (+5%) | R$ 2.411 → R$ 2.100 (−13%) |
| Villa King Spa (2) | R$ 1.151 → R$ 1.216 (+6%) | R$ 2.703 → R$ 2.349 (−13%) |

Valores em "preço enviado", antes das ofertas da Booking. O recálculo não envia nada ao Beds24. Os novos preços chegam aos canais na próxima sincronização automática do PriceLabs, ou antes, se você clicar em "Sync Now" no PriceLabs.

## 25/09/2026, 14:11 — Fase 1, passo 1 do preço base e teste de fim de semana ("Pode executar tudo")

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Preço base | Queen Spa (7) | 1.500 | 1.380 | "Volte o preço base da Queen Spa 7 unidades para 1.500" |
| Preço base | Double Spa | 1.600 | 1.480 | "Volte o preço base da Double Spa para 1.600" |
| Preço base | Villa King Spa (7) | 1.600 | 1.480 | "Volte o preço base da Villa King Spa 7 unidades para 1.600" |
| Preço base | Villa King Spa (2) | 1.500 | 1.270 | "Volte o preço base da Villa King Spa 2 unidades para 1.500" |
| Estadia mínima 1 noite (teste) | Queen Spa (7) e Queen Spa (2), em 02 e 03/10 | 2 noites | 1 noite | "Apague as substituições de 02 e 03/10 da Queen Spa 7 e 2 unidades" |

**Não aplicado de propósito:** preço base da Queen Spa (2) para 1.450. Ele depende de achar antes a causa do desconto de 66% desse quarto. Baixar agora deixaria mais barato para o hóspede um quarto que já sai abaixo da Queen Spa (7).

### Efeito acumulado nos preços (recalculado às 14:12, próximos 60 dias, preço enviado)

| Quarto | Dia de semana, mediana | Fim de semana, mediana |
|---|---|---|
| Queen Spa (7) | R$ 1.076 → R$ 1.036 (−4%) | R$ 2.776 → R$ 2.108 (−24%) |
| Double Spa | R$ 1.232 → R$ 1.155 (−6%) | R$ 3.051 → R$ 2.279 (−25%) |
| Queen Spa (2) | R$ 1.333 → R$ 1.418 (+6%) | R$ 3.603 → R$ 2.988 (−17%) |
| Villa King Spa (7) | R$ 1.148 → R$ 1.137 (−1%) | R$ 2.880 → R$ 2.316 (−20%) |
| Villa King Spa (2) | R$ 1.151 → R$ 1.029 (−11%) | R$ 2.703 → R$ 1.989 (−26%) |
| Afrodite (1) | R$ 1.582 → R$ 1.683 (+6%) | R$ 4.206 → R$ 3.527 (−16%) |
| Balcony (6) | R$ 1.011 → R$ 1.058 (+5%) | R$ 2.411 → R$ 2.100 (−13%) |

Com isso, a Balcony ficou acima da Villa King Spa (2) nos dias de semana, o que ajuda a vender primeiro as suítes com banheira.

**Observação do dia:** entre a manhã e as 14h, a ocupação dos próximos 7 dias da Queen Spa (2) caiu de 50% para 7% no PriceLabs. Isso indica cancelamentos, a conferir no Beds24.

## 25/09/2026, 16:23 — Agenda do dia ("Aprovo tudo")

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Estadia mínima 1 noite | Queen Spa (7), 25 e 26/09 | 2 noites (desconto de −20% já existente mantido) | 1 noite, −20% mantido | "Volte a estadia mínima da Queen Spa 7 em 25 e 26/09 para 2" |
| Estadia mínima 1 noite | Double Spa, 25/09 | 2 noites (−20% mantido) | 1 noite, −20% mantido | "Volte a estadia mínima da Double em 25/09 para 2" |
| Estadia mínima 1 noite | Villa King Spa (2), 25/09 | 2 noites | 1 noite | "Apague a substituição de 25/09 da Villa King Spa 2" |

**Não aplicado:** reaplicar a Fase 1 (base 1.380 / 1.480 / 1.480 / 1.270 e mínimo da Balcony 950), que foi revertida pela tela às 13:59. A mudança foi bloqueada pela proteção de permissões da sessão e depende do dono.

## 26/09/2026, 09:46 — Agenda das 07:53 ("Pode aplicar tudo")

**Achado:** o −35% colocado pela tela em 25/09 às 19:18 não baixava o preço. Desconto em % respeita o piso de fim de semana (Queen Spa 7 e Villa King Spa 2 em R$ 2.250; Double e Villa King Spa 7 em R$ 2.400). Preço fixo passa por cima do piso. Aquele −35% também apagou o teste de 1 noite da Fase 1 na Queen Spa (7) em 02 e 03/10.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Preço fixo −15% | Queen Spa (7), 02 e 03/10 | −35% sem efeito (R$ 2.250 no piso), estadia mínima 2 | R$ 1.910 fixo + estadia mínima 1 noite (teste da Fase 1 recolocado) | "Volte 02 e 03/10 da Queen Spa 7 para −35% sem estadia mínima" |
| Preço fixo −15% | Double Spa, 02 e 03/10 | −35% sem efeito (R$ 2.400) | R$ 2.040 fixo | "Volte 02 e 03/10 da Double para −35%" |
| Preço fixo −15% | Villa King Spa (7), 02 e 03/10 | −35% sem efeito (R$ 2.400) | R$ 2.040 fixo | "Volte 02 e 03/10 da Villa King Spa 7 para −35%" |
| Preço fixo −15% | Villa King Spa (2), 02 e 03/10 | −35% sem efeito (R$ 2.250) | R$ 1.910 fixo | "Volte 02 e 03/10 da Villa King Spa 2 para −35%" |
| Leitura liberada para a rotina | `.claude/settings.json` do repositório | leituras da PriceLabs bloqueadas às vezes | `get_listing_prices`, `get_user_logs`, `get_pms_reservations`, `get_listing_date_overrides` e `get_listings` liberadas (só leitura) | apagar o arquivo `.claude/settings.json` |

**Não aplicado (a proteção da sessão bloqueia mudança de preço base e mínimo):** Balcony com base 800 → 1.400 e mínimo 800 → 950, e Fase 1 com base 1.380 na Queen Spa (7), 1.480 na Double, 1.480 na Villa King Spa (7) e 1.270 na Villa King Spa (2). Fica com o dono, na tela ou liberando `mcp__PriceLabs__update_listing_data`.

**Com o dono:** conferir no Beds24 a reserva da Balcony de 25 e 26/09 e o bloqueio da Villa de 5 a 7/11. A Queen Spa (2) ficou de fora do −15%, pela regra; o −35% dela em 02 e 03/10 continua sem efeito por causa do piso.

## 26/09/2026, 17:58 — Modo automático ligado ("Sempre apresente a análise e aplique a estratégia. Só pare de aplicar caso eu avise para parar")

A rotina das 5 análises diárias passa a aplicar sozinha as regras abaixo, só por substituição por data e sempre registrando aqui:

- A. Estadia mínima de 1 noite nas datas dos próximos 14 dias com unidade livre. Ficam mantidas as regras de 2 noites da Fase 1 em 11/10, 01/11 e 19/11.
- B. Fim de semana preso no piso, nos quartos principais com ocupação abaixo de 50% na data: preço fixo 15% abaixo, sem nunca descer do mínimo.
- C. Afrodite com noite livre nos próximos 7 dias: preço fixo entre R$ 1.500 e 90% do preço atual.
- D. Nunca mexe em descontos de OTA, ofertas da Booking, preço base, mínimo ou máximo, nem no preço da Queen Spa (2) ou da Balcony.

**Para parar:** basta dizer "pare de aplicar". A rotina volta a só analisar.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Estadia mínima 1 noite | Double, Villa King Spa (7) e Villa King Spa (2), em 02 e 03/10 | 2 noites (preço fixo −15% mantido) | 1 noite | "Volte a estadia mínima de 02 e 03/10 para 2 na Double e nas Villa King Spa" |
| Estadia mínima 1 noite (teste da Fase 1 recolocado) | Queen Spa (2), em 02 e 03/10 | 2 noites (o −35% da tela tinha apagado o teste) | 1 noite, −35% do dono mantido | "Volte a estadia mínima da Queen Spa 2 em 02 e 03/10 para 2" |
| Preço fixo R$ 1.500 (−9%, até o mínimo) | Afrodite, em 28 e 30/09 | R$ 1.642 | R$ 1.500 | "Apague as substituições de 28 e 30/09 do Afrodite" |

## 28/09/2026, 13:04 — Rodada refeita ("Tentar novamente") e revisão de 3 dias

As rodadas de 27/09 (13:53, 16:53 e 19:53) e de 28/09 (07:53, 09:00 e 10:53) não rodaram, porque o conector caiu. Esta rodada junta todas.

**Estado da PriceLabs agora:**
- Os 7 quartos respondem "sem dados" (LISTING_NO_DATA). Tentei de novo e o erro continuou.
- O recálculo está em branco, e o último envio aos canais foi em 27/09 às 09:01.
- Hoje ainda não houve envio. Os canais seguem com os preços de ontem, que já têm as substituições automáticas.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Preço fixo R$ 1.500 (−5%, até o mínimo), regra C | Afrodite, em 04/10 | R$ 1.572 | R$ 1.500 | "Apague a substituição de 04/10 do Afrodite" |

Conferi as regras A e B e não havia o que aplicar:
- A: a estadia mínima de 1 noite já estava em todas as datas com vaga até 11/10.
- B: 02–03/10 já tem o corte, e 09–10/10 é feriado.

**Revisão de 3 dias (reservas feitas desde 25/09):**
- **Estadia mínima de 1 noite funcionou no sábado 26/09.** Vieram vendas de última hora na Queen (7), Double, Balcony e VKS2, a maior parte pelo canal "others".
- **O teste de 1 noite em 02–03/10 não gerou reservas de 1 noite.** A única venda nessas datas foi a Queen Spa (2), de 2 noites.
- **Valor pago na Booking contra o preço enviado:**
  - Queen Spa (2): 33% em 02–03/10 e 39% em 27–28/09.
  - Balcony: 47% em 27–28/09 e 39% em 31/10–01/11.
  - Queen Spa (7): 54% em 20–21/11.
- **Cancelamentos:** nenhum na Queen Spa (2). Houve 1 na Queen Spa (7), para 25/09, e 3 na Balcony, para 25 e 26/09.
- **Preços da Fase 1 que continuam sem aplicar (só pela tela):** preço base da Queen (7) em 1.500, da Double em 1.600, da VKS7 em 1.600 e da VKS2 em 1.500. A Balcony está com base e mínimo de 800.
- **Lote de reservas às 10:44–10:48 de 27/09**, com horários idênticos, na Balcony, na Queen (2), na Queen (7) e no Afrodite. Conferir no Beds24 se não são duplicadas, principalmente as 2 da Balcony em 11/10.

## 28/09/2026, 13:10 — Plano executado ("Pode executar tudo que está planejado")

A PriceLabs continuava sem dados de calendário nesta hora. As substituições e os preços base ficam gravados e vão aos canais no próximo envio.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Fim de semana −25% do piso (fixo, estadia mínima 1 mantida) | Queen Spa (7) e Villa King Spa (2), em 02 e 03/10 | R$ 1.910 | R$ 1.680 | "Volte 02 e 03/10 da Queen 7 e da VKS2 para R$ 1.910" |
| Fim de semana −25% do piso (fixo, estadia mínima 1 mantida) | Double e Villa King Spa (7), em 02 e 03/10 | R$ 2.040 | R$ 1.800 | "Volte 02 e 03/10 da Double e da VKS7 para R$ 2.040" |
| Meio de semana −10% (fixo) | Queen Spa (7), em 30/09 e 01/10 | R$ 1.099 | R$ 980 | "Apague as substituições de 30/09 e 01/10 da Queen 7" |
| Meio de semana −10% (fixo) | Double, em 30/09 e 01/10 | R$ 1.563 | R$ 1.400 | "Apague as substituições de 30/09 e 01/10 da Double" |
| Preço base (Fase 1) | Queen Spa (7) | R$ 1.500 | R$ 1.380 | "Volte o preço base da Queen 7 para 1.500" |
| Preço base (Fase 1) | Double | R$ 1.600 | R$ 1.480 | "Volte o preço base da Double para 1.600" |
| Preço base (Fase 1) | Villa King Spa (7) | R$ 1.600 | R$ 1.480 | "Volte o preço base da VKS7 para 1.600" |
| Preço base (Fase 1) | Villa King Spa (2) | R$ 1.500 | R$ 1.270 | "Volte o preço base da VKS2 para 1.500" |

**Segurado, esperando confirmação:** Balcony com base 1.400 e mínimo 950 (hoje 800 e 800). Nos últimos 3 dias, a Balcony a R$ 800 foi o quarto que mais vendeu na última hora.

## 28/09/2026, 13:59 — Ajuste depois do recálculo (Double)

Com o preço base novo (1.480), a PriceLabs recalculou a Double no meio de semana para cerca de R$ 1.136. A substituição fixa de R$ 1.400 em 30/09 e 01/10 passou a segurar o preço acima disso, o contrário do desconto aprovado. Por isso, apaguei essas 2 substituições.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Substituição removida | Double, em 30/09 e 01/10 | R$ 1.400 fixo | Preço automático, cerca de R$ 1.136 | "Recoloque R$ 1.400 fixo na Double em 30/09 e 01/10" |

**Sincronização:** o Sync Now do dono em 28/09 fez a PriceLabs recalcular às 13:47 e enviar aos canais às 16:35. Em 29/09 às 07:53, os quartos voltaram a aparecer sem dados, e o envio das 06:00 não aconteceu.

## 29/09/2026, 11:41 — Modo agressivo de 14 dias ("Vamos ser mais agressivos para as proximas 2 semanas até atingirmos 50% de lotação")

**Regra E (vale para as próximas 2 semanas, até os 14 dias chegarem a 50% no hotel todo):**
- **Onde vale:** Queen Spa (7), Double, Villa King Spa (7), Villa King Spa (2) e Afrodite, só nas datas em que o quarto estiver abaixo de 50% de ocupação.
- **Meio de semana (domingo a quinta):**
  - até 7 dias antes, o preço vai para o **mínimo do quarto**;
  - de 8 a 13 dias antes, vai para o **mínimo +10%**.
- **Fim de semana (sexta e sábado):** fica **40% abaixo do piso de fim de semana**. O piso é 150% do preço base. Os valores são Queen (7) R$ 1.240, Double e VKS7 R$ 1.330, VKS2 R$ 1.140.
- **Feriado de 09 a 12/10:** **−10%** sobre o preço atual, mantendo a estadia mínima de 2 noites.
- **Afrodite:** R$ 1.500, que é o mínimo, em qualquer noite livre.
- **Fora da regra:** Queen Spa (2), por causa da anomalia de 33%, e Balcony, que já está no mínimo de R$ 800.
- **Quando parar:**
  - quando uma data de um quarto passar de 50%, a rotina apaga a substituição daquela data e ela volta ao preço automático;
  - quando o hotel todo chegar a 50% nos 14 dias, o modo termina.
- **Nunca:** preço abaixo do mínimo, mudança em descontos de OTA ou ofertas da Booking.

| Quarto | Datas | Antes | Depois |
|---|---|---|---|
| Queen Spa (7) | 30/09, 01/10, 04 a 06/10 | R$ 980 / cerca de 1.000 | R$ 800 (mínimo) |
| Queen Spa (7) | 07 e 08/10 | cerca de 1.000 | R$ 880 |
| Queen Spa (7) | 02 e 03/10 (1 noite mantida) | R$ 1.680 | R$ 1.240 |
| Double | 30/09, 01/10, 04 a 06/10 | R$ 1.005 a 1.026 | R$ 900 (mínimo) |
| Double | 07 e 08/10 | R$ 1.026 | R$ 990 |
| Double | 02 e 03/10 (1 noite mantida) | R$ 1.800 | R$ 1.330 |
| Double | 09 e 10/10 (2 noites mantidas) | R$ 3.168 | R$ 2.850 |
| Double | 12/10 | R$ 1.709 | R$ 1.530 |
| Villa King Spa (7) | 02 e 03/10 (1 noite mantida) | R$ 1.800 | R$ 1.330 |
| Villa King Spa (7) | 12/10 | R$ 1.171 | R$ 1.050 |
| Villa King Spa (2) | 02 e 03/10 (1 noite mantida) | R$ 1.680 | R$ 1.140 |
| Afrodite | 07 e 08/10 | cerca de R$ 1.572 | R$ 1.500 |

**Sem mudança:** o meio de semana da VKS7 e da VKS2 já estava no mínimo (R$ 1.000 e R$ 900). Na Queen (7) e no Afrodite, a PriceLabs estava sem dados nas datas do feriado, então o −10% fica para quando os dados voltarem.

**Para desfazer:** pedir "desligue o modo agressivo". Apago as substituições "Agressivo 29/09" e volto os valores anteriores desta tabela.

## 29/09/2026, 19:58 — Modo agressivo: feriado da Queen (7) e do Afrodite

A PriceLabs voltou a mostrar dados desses 2 quartos (recálculo às 11:46, já enviado aos canais), então apliquei o −10% do feriado que estava pendente.

| Quarto | Data | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Queen Spa (7) | 09/10 (2 de 7 vendidas) | R$ 2.695 | R$ 2.420 | "Apague a substituição de 09/10 da Queen 7" |
| Queen Spa (7) | 12/10 (2 de 7) | R$ 1.460 | R$ 1.310 | "Apague a substituição de 12/10 da Queen 7" |
| Afrodite | 09 e 10/10 (livres, 2 noites mantidas) | R$ 4.519 | R$ 4.060 | "Apague as substituições de 09 e 10/10 do Afrodite" |
| Afrodite | 12/10 (livre) | R$ 2.359 | R$ 2.120 | "Apague a substituição de 12/10 do Afrodite" |

A Queen (7) em 10/10 (4 de 7, 57%) e em 11/10 (5 de 7) ficou sem desconto, porque já passou de 50%.

**Vendas desde a manhã:**
- Nova reserva na Queen (7) para 11–12/10, 2 noites, pela Expedia, por R$ 1.583 (R$ 791 por noite).
- A ocupação da Queen (7) subiu em 30/09 (de 1 para 3 de 7) e em 01/10 (de 1 para 2 de 7), com os preços de meio de semana mais baixos.

## 30/09/2026, 07:09 — Modo agressivo: datas novas na janela + revisão da estratégia ("Fiz o Sync, revise se nossa estratégia está funcionando")

| Quarto | Data | Antes | Depois | Por quê |
|---|---|---|---|---|
| Queen Spa (7) | 07/10 | R$ 880 | R$ 800 | Entrou nos 7 dias, então vai para o mínimo |
| Double | 07/10 | R$ 990 | R$ 900 | Entrou nos 7 dias, então vai para o mínimo |
| Queen Spa (7) | 13/10 | sem substituição | R$ 880 | Entrou na janela de 14 dias (mínimo +10%) |
| Villa King Spa (7) | 13/10 | R$ 1.171 | R$ 1.100 | Entrou na janela de 14 dias (mínimo +10%) |
| Villa King Spa (2) | 13/10 | R$ 1.063 | R$ 990 | Entrou na janela de 14 dias (mínimo +10%) |

**Para desfazer:** apagar as substituições "Agressivo 30/09".

**Revisão da estratégia:**
- **Vendas de 25/09 a 29/09:** 46 reservas ativas, cerca de 87 noites e cerca de R$ 66 mil. O valor médio pago ficou em torno de R$ 760 por noite. Alguns lotes podem ter duplicadas, a conferir no Beds24.
- **Modo agressivo:** está nos canais desde 29/09 às 11:45. Até 30/09 às 07:09 não entrou nenhuma reserva registrada depois disso. É cedo para avaliar, e a avaliação fica para 02/10.
- **Ocupação dos próximos 7 dias:** a própria PriceLabs calcula cerca de 19% no hotel todo, igual ao mercado (18–19%).
- **Riscos:**
  - O hóspede paga 30 a 55% do preço enviado, porque os descontos das OTAs se somam.
  - A PriceLabs falha no recálculo diário e só volta com Sync manual.
  - As reservas chegam do Booking ao Beds24 em lotes.

## 30/09/2026, 10:33 — Estratégia do fim de semana 02–04/10 (painel de 3 estrategistas + preços públicos da Booking)

**Lotação (dados das 09:41):** sex 02/10 16% (5 de 31), sáb 03/10 23% (7 de 31), dom 04/10 16% (5 de 31). Fim de semana inteiro 18% (17 de 93), igual ao mercado (18% a 19%). O sábado pode ser 6 de 31 se a unidade da Queen Spa (7) for bloqueio e não reserva (sem valor pago no calendário).

**Preço público na Booking (Booking.com, 2 adultos, BRL):**
- Recanto: R$ 742 (sex, 1 noite), R$ 753 (sáb, 1 noite), R$ 703 por noite (sex e sáb juntos). Nota 8,4.
- Villa: R$ 635 por noite (sex e sáb juntos). Nota 8,1. **Sem disponibilidade na Booking para 1 noite** (sexta 02/10 e sábado 03/10), embora o Beds24 mostre 13 quartos livres na sexta. Conferido 2 vezes.
- Concorrentes de Campos do Jordão: mediana de R$ 293 (1 noite) e R$ 381 (2 noites); com jacuzzi, R$ 424 e R$ 606. Notas dos pares: 9,0 a 9,5.
- A busca não diz qual quarto aparece. Se for a Queen (7), o hóspede vê cerca de 60% do preço enviado.

**Airbnb e Expedia:** não consegui ler (Airbnb devolveu erro 410, Expedia 429, Hoteis.com 503). As páginas existem. O dono precisa conferir pelas extranets.

**Mercado:** previsão de chuva nos 3 dias (fontes divergem), primeiro turno das eleições no domingo 04/10, sem evento grande confirmado. A data da Oktoberfest está em conflito (prefeitura: 02 a 12/10; notícia de 25/09: 15 a 18/10). Confirmar antes de usar como argumento.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Pacote 1: sexta −15% (fixo, 1 noite mantida) | Queen Spa (7), 02/10 | R$ 1.240 | R$ 1.050 | "Volte a Queen 7 de 02/10 para R$ 1.240" |
| Pacote 1: sexta −14% (fixo, 1 noite mantida) | Double, 02/10 | R$ 1.330 | R$ 1.150 | "Volte a Double de 02/10 para R$ 1.330" |

**Propostas aguardando resposta do dono (nada aplicado):**
- **Pacote 2, domingo do feriado 11/10:** Queen (7) de R$ 1.411 para R$ 1.900 e Double de R$ 1.726 para R$ 2.000. O dia está 90% vendido e o preço enviado está a metade do algoritmo. A base é fraca (Double sem venda há 9 dias), por isso é um teste com revisão na quinta às 10h.
- **Pacote 3, sexta na Villa:** VKS7 R$ 1.330 para R$ 1.150 e VKS2 R$ 1.140 para R$ 1.000. Só depois de resolver o bloqueio da Villa na Booking.
- **Pacote 4, exceção de regra (baixa a Balcony):** sexta R$ 1.200 para R$ 850 e sábado R$ 1.200 para R$ 1.000. A Balcony hoje está mais cara que a VKS2 com spa (R$ 1.140) e 45% acima do algoritmo. Só com aprovação explícita e depois do bloqueio da Villa.
- **Passo 2 da sexta, se a quinta às 10h não tiver pelo menos +3 quartos-noite vendidos em sex e sáb:** Queen (7) R$ 900, Double R$ 1.000, VKS7 R$ 1.050, VKS2 R$ 900 (todos acima do mínimo). Se vierem +8 ou mais, manter e subir o sábado.

**Limites da análise:** não há dado de elasticidade nem venda depois das 09:41; os efeitos são cenários e os limiares (+3 e +8) são critério do estrategista. A meta de 70% em 7 dias não sai só com preço.

## 30/09/2026, 10:40 — Verificação do que o hóspede vê nas OTAs (Booking, Expedia, Airbnb)

**Nenhuma mudança de preço foi feita nesta rodada** (só leitura).

| Fonte | Resultado |
|---|---|
| Booking, página do Recanto (navegador automatizado) | Bloqueio **403**: o site detecta robô e exige identificação. Não contornei. |
| Expedia, página do Recanto (navegador automatizado) | **429 "Bot or Not?"** (verificação humana). Não contornei. |
| Airbnb | Sem URL enviada; as tentativas anteriores retornaram 410. |
| Conector oficial da Booking (busca pública, 02→03 e 02→04/10) | **Funcionou** e é a única fonte de preço público desta rodada. |

**Preço público na Booking agora (Recanto, 2 adultos):**
- Sex 02/10, 1 noite: **R$742,14** (mesmo valor da checagem anterior).
- Sex+Sáb 02→04/10: **R$1.406,16** no total, ou R$703 por noite.
- Rating 8,4 (178 avaliações), 5 estrelas oficiais.
- A Villa não apareceu nesta busca (a lista devolve poucos hotéis). Na checagem anterior ela tinha R$635 por noite para 2 noites e nenhuma disponibilidade para 1 noite.

**Cruzamento com o PriceLabs (último envio, 09:41 BRT):**
- Queen Spa (7): sex e sáb 02–03/10 enviadas a R$1.240 (piso do modo agressivo). O público de R$742 na Booking equivale a cerca de 60% do enviado.
- Villa King Spa (7): sex e sáb enviadas a R$1.330.
- O corte de sexta do pacote 1 (Queen 1.050) ainda **não foi enviado**: depende do próximo Sync. Depois dele, o público esperado da Queen na sexta é de cerca de R$630.

**Concorrentes com preço público na Booking, sex 02/10 (1 noite):** Champet Boutique R$798, Le Suisse Elegance R$370, Solar d'Izabel R$370, Monte Carlo R$345, Cantinho da Serra R$340, Café Poesia R$330 (com jacuzzi), Leão da Montanha R$326. O Recanto é o segundo mais caro da região e tem a nota mais baixa entre os de 4–5 estrelas.

**Ajuste técnico no ambiente (não afeta o repositório):** o navegador não confiava na CA do proxy do ambiente; importei o bundle oficial `/root/.ccr/ca-bundle.crt` no NSS. A verificação TLS nunca foi desativada.

## 30/09/2026, 10:46 — Villa Dolce Amore: o que o hóspede vê e por que a Booking não vende noite avulsa no fim de semana

**Nenhuma mudança de preço foi feita** (só leitura). As páginas diretas continuam bloqueadas: Booking (conteúdo vazio para leitura automática), Airbnb (HTTP 410) e Expedia (HTTP 429). O dado veio do conector oficial da Booking, 2 adultos, em reais.

| Estadia | Booking (Villa) | Enviado pelo PriceLabs (último push) |
|---|---|---|
| Sex 02/10, 1 noite | **indisponível** | Balcony 1.200 / VKS2 1.140 / VKS7 1.330 |
| Sáb 03/10, 1 noite | **indisponível** | Balcony 1.200 / VKS2 1.140 / VKS7 1.330 |
| Sex+Sáb 02→04/10, 2 noites | **R$ 1.270,08** (R$ 635,04 por noite) | idem |
| Sáb+Dom 03→05/10, 2 noites | **indisponível** | — |
| Dom 04/10, 1 noite | R$ 423,36 | Balcony 800 / VKS2 900 / VKS7 1.000 |
| Ter 06/10, 1 noite | R$ 423,36 | — |
| Sex 09/10, 1 noite | **indisponível** | — |

**Leituras:**
1. **O preço de vitrine da Villa na Booking é o do Balcony.** R$ 635,04 é 52,92% de R$ 1.200 e R$ 423,36 é 52,92% de R$ 800, a mesma proporção. Ou seja, o hóspede paga cerca de 53% do que enviamos, depois dos descontos empilhados.
2. **A Villa só vende, na Booking, a chegada de sexta para 2 noites.** Sexta avulsa, sábado avulso, sáb+dom e a sexta de 09/10 aparecem indisponíveis, embora o PriceLabs mostre quartos livres (sex e sáb: 2 de 15 vendidos, 13%; dom 04/10: 4 de 15, 27%) e `min_stay` = 1. A restrição vem do Beds24 ou da extranet da Booking (estadia mínima 2 noites e/ou chegada fechada no sábado), fora do alcance do PriceLabs. Confirmada em 5 consultas.
3. **Comparação com vizinhos (raio de 400 m, Booking):** Casa Redonda (9,2, jacuzzi) R$ 540 na sex e no sáb; Blue Village (9,0, spa) R$ 279 na sex e R$ 405 no sáb; Chateau Colinas (9,7, jacuzzi e sauna) cerca de R$ 505 por noite na sex+sáb; Capivari Lodge R$ 240 na sex e R$ 264 no sáb. A Villa (nota 8,1, 175 avaliações) fica a R$ 635 por noite, acima de todos com nota maior.

**Impacto nas propostas pendentes:**
- Proposta 3 (Villa sexta): antes de cortar preço, a prioridade é liberar a venda de 1 noite e a chegada de sábado. Corte de preço não resolve quarto que o hóspede não consegue reservar.
- Proposta 4 (Balcony): o dado reforça, pois o Balcony define a vitrine da Villa. Continua dependendo de aprovação explícita (regra D).

**Pendente com o dono (só ele consegue):** conferir no Beds24 (calendário, estadia mínima e restrições) e na extranet da Booking (Tarifas e disponibilidade > Calendário > restrições) os quartos VKS7, VKS2 e Balcony em 02, 03 e 09/10.

## 30/09/2026, 12:20 — Plano em porcentagem (Regra F): conversão dos dias úteis e pilotos ("Vamos aplicar as estratégias nas 2 pousadas ... usar % ao invés do valor fixo")

Plano completo em `pricelabs/plano-de-atingimento-das-metas.md`.

**Regra F (vale para as 5 análises diárias, no lugar dos valores fixos da regra E):** substituição de preço em **% sobre o preço recomendado**, com **piso da data = mínimo do quarto** (Queen 7 R$ 800, Double R$ 900, Villa King Spa 7 R$ 1.000, Villa King Spa 2 R$ 900, Afrodite R$ 1.500).
- Dia útil: 0 a 13 dias −35%; 14 a 21 dias −25%; 22 a 31 dias −15%.
- Sexta e sábado: 14 a 21 dias −20%; 22 a 31 dias −12%; 2 a 7 dias mantém o nível vigente (fixo até o Portão 1).
- Feriado 09 a 12/10: −10%. Afrodite: noite livre até 13 dias −30%, piso R$ 1.500.
- Data com 50% ou mais vendido no quarto volta ao algoritmo; Queen Spa (2) e Balcony seguem fora (regra D).
- **Portão 1:** só estender a escada de 14 a 31 dias e passar sexta, sábado e feriado para % depois de conferir o piloto da Villa King Spa (7): 16 e 17/10 ≈ R$ 1.538 e 20/10 ≈ R$ 1.006 no cálculo do PriceLabs.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Fixo → −35% do recomendado, piso R$ 800 | Queen (7), dias úteis 30/09, 01/10, 04, 05, 06, 07, 08 e 13/10 | fixo R$ 800 (08 e 13/10: R$ 880) | −35%, piso R$ 800 | "Volte os dias úteis da Queen 7 de 30/09 a 13/10 para fixo R$ 800 (08 e 13/10: R$ 880)" |
| Fixo → −35%, piso R$ 900 | Double, dias úteis 30/09, 01/10, 04, 05, 06, 07 e 08/10 | fixo R$ 900 (08/10: R$ 990) | −35%, piso R$ 900 | "Volte os dias úteis da Double de 30/09 a 08/10 para fixo R$ 900 (08/10: R$ 990)" |
| Fixo → −30%, piso R$ 1.500 | Afrodite, noites livres 30/09, 04, 07 e 08/10 | fixo R$ 1.500 | −30%, piso R$ 1.500 | "Volte as noites livres do Afrodite de 30/09 a 08/10 para fixo R$ 1.500" |
| Fixo → −35%, piso R$ 900 | Villa King Spa (2), 13/10 | fixo R$ 990 | −35%, piso R$ 900 | "Volte 13/10 da Villa King Spa 2 para fixo R$ 990" |
| Fixo → −35%, piso R$ 1.000 | Villa King Spa (7), 13/10 | fixo R$ 1.100 | −35%, piso R$ 1.000 | "Volte 13/10 da Villa King Spa 7 para fixo R$ 1.100" |
| **Piloto novo** −20%, piso R$ 1.000 | Villa King Spa (7), sexta 16 e sábado 17/10 | sem substituição (piso de fim de semana R$ 2.220, enviado R$ 2.220) | −20% do recomendado | "Apague as substituições de 16 e 17/10 da Villa King Spa 7" |
| **Piloto novo** −5%, piso R$ 1.000 | Villa King Spa (7), terça 20/10 | sem substituição (R$ 1.059) | −5% do recomendado | "Apague a substituição de 20/10 da Villa King Spa 7" |

**Efeito no preço de hoje:** nenhum. Os dias úteis já estavam no mínimo do quarto; com o % o preço passa a subir sozinho se o algoritmo recomendar mais de 1,5 vez o mínimo.

**O que não mudou:** sexta e sábado 02 e 03/10 (Queen 1.050 e 1.240; Double 1.150 e 1.330; Villa King Spa 7 1.330; Villa King Spa 2 1.140), feriado 09 a 12/10, Queen Spa (2) e Balcony. Passei sexta e sábado da Villa King Spa (2), da Villa King Spa (7) e da Double para % por cerca de 30 minutos e voltei ao fixo antes de qualquer recálculo, porque o teste do piso de fim de semana não pôde ser conferido (item abaixo). As substituições fixas de 02 e 03/10 dessas três categorias ficaram com o campo de mínimo da data preenchido (R$ 900 ou R$ 1.000); é inerte, preço fixo ignora mínimo.

**Recálculo:** recalculei a Queen (7) e a Double (a sexta 02/10 já está calculada em R$ 1.050 e R$ 1.150; o enviado continua R$ 1.240 e R$ 1.330 até o Sync). Depois dos pilotos, o PriceLabs respondeu "muitas requisições" (429) nos recálculos da Villa King Spa (7), da Villa King Spa (2) e da Double (limite de 3 por quarto a cada 24 h). Por isso o Portão 1 fica para o próximo recálculo.

**Base de conhecimento do PriceLabs (consultada hoje):** o % da substituição é aplicado sobre o preço recomendado já com as personalizações; o mínimo da data na substituição prevalece sobre o "Preço mínimo de fim de semana"; preço fixo pode passar por cima desse piso.

## 30/09/2026, 13:45 — Depois do Sync das 12:42: reservas que entraram, Portão 1 do plano em % e ajustes

**Reservas:** o PriceLabs passou a ler 453 reservas criadas desde 09/09 (antes 443): **10 novas** e 1 mudança de status (uma Double de 28/09 cancelada em 30/09). Todas as novas são de datas futuras ou de hoje e nenhuma foi cancelada.

| Quarto | Entrada | Noites | Canal | Valor | Criada (BRT) |
|---|---|---|---|---|---|
| Queen (7) | 03/10 | 1 | Booking | R$ 691 | 29/09 19:44 |
| Villa King Spa (7) | 30/10 | 3 | direto/outros | R$ 3.202 | 30/09 09:48 |
| Villa King Spa (7) | 30/09 | 1 | Booking | R$ 410 | 30/09 10:07 |
| Villa King Spa (7) | 02/10 | 2 | Booking | R$ 1.021 | 30/09 10:07 |
| Villa King Spa (7) | 18/10 | 1 | Booking | R$ 605 | 30/09 10:07 |
| Villa King Spa (2) | 19/11 | 3 | Booking | R$ 3.838 | 30/09 10:07 |
| Balcony | 20/11 | 2 | Booking | R$ 1.060 | 30/09 10:07 |
| Queen (7) | 02/10 | 2 | Booking | R$ 1.223 | 30/09 10:08 |
| Double | 11/10 | 2 | Booking | R$ 1.488 | 30/09 10:08 |
| Queen (2) | 22/11 | 3 | Booking | R$ 1.470 | 30/09 10:08 |

Total: 20 diárias, R$ 15.008. Dessas, 6 diárias caem nos próximos 7 dias (30/09 e 02 e 03/10). Oito reservas da Booking foram criadas no Beds24 em 2 minutos (10:07 e 10:08), por isso a análise das 10:53 não as via: **o PriceLabs só enxerga reserva nova no Sync**. O que o hóspede pagou contra o que enviamos: Villa King Spa (7) 02/10 R$ 510 por noite contra R$ 1.330 (38%); 30/09 R$ 410 contra R$ 1.000 (41%); Queen (7) 02 e 03/10 R$ 611 contra R$ 1.240 (49%). As 8 canceladas de 26 a 28/09 são de datas passadas e não afetam a ocupação futura.

Ocupação dos próximos 7 dias segundo o PriceLabs após o Sync: Queen (7) 18%, Double 17%, Afrodite 71%, Queen (2) 36%, Villa King Spa (7) 22%, Balcony 14%, Villa King Spa (2) 7%; hotel cerca de 20% (era 19%). Último envio aos canais: 12:42 nos 7 quartos.

**Portão 1 (plano em %):** passou nos fins de semana e falhou em dia útil isolado (detalhe em `plano-de-atingimento-das-metas.md`, seção 2).
- Villa King Spa (7), 16 e 17/10: recomendado R$ 1.923, piso R$ 2.220, agora R$ 1.536 com −20% e mínimo da data R$ 1.000. O mínimo da data vence o piso de fim de semana e o % incide sobre o recomendado.
- Queen (7) em 13/10 (−35%): ficou em **R$ 1.405**, contra R$ 880 fixos de antes, porque a suavização (Atenuação) somou R$ 774 depois do %. Villa King Spa (7) em 20/10 (−5%): R$ 1.062, igual aos vizinhos. O % de dia útil isolado é anulado.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Volta ao fixo R$ 880 (corrige o efeito do % anulado) | Queen (7), 13/10 | −35% (calculava R$ 1.405) | fixo R$ 880 | "Apague a substituição de 13/10 da Queen 7" |
| Fixo R$ 880 (mínimo +10%) novo | Queen (7), 14 e 15/10 (quarta e quinta depois do feriado, 0 de 7 vendidas) | sem substituição (calculava R$ 1.405) | fixo R$ 880 | "Apague as substituições de 14 e 15/10 da Queen 7" |
| **Fase 2:** −20% (16 e 17/10) e −12% (23, 24, 30 e 31/10), mínimo da data = mínimo do quarto | Queen (7) R$ 800, Double R$ 900, Villa King Spa (2) R$ 900: sextas e sábados 16, 17, 23, 24, 30 e 31/10 | sem substituição (piso de fim de semana: R$ 2.070, R$ 2.220 e R$ 1.905) | % do recomendado | "Apague as substituições de sexta e sábado de 16 a 31/10 da Queen 7, da Double e da Villa King Spa 2" |
| **Fase 2:** −12%, mínimo R$ 1.000 | Villa King Spa (7), 23, 24, 30 e 31/10 (16 e 17/10 já em −20%) | sem substituição (piso R$ 2.220) | −12% do recomendado | "Apague as substituições de 23, 24, 30 e 31/10 da Villa King Spa 7" |
| Piloto retirado | Villa King Spa (7), 20/10 | −5% (sem efeito) | sem substituição | recolocar: "−5% em 20/10 da Villa King Spa 7" |

**Efeito esperado após o próximo Sync (estimativa com o recomendado de hoje):** Queen (7) 16 e 17/10 de R$ 2.070 para cerca de R$ 1.420; 23, 24, 30 e 31/10 de R$ 2.070–2.084 para cerca de R$ 1.540–1.830. Double, Villa King Spa (7) e Villa King Spa (2): queda de 15% a 31% nas mesmas datas.

**Não mexi:** sexta e sábado 02 e 03/10, feriado 09 a 12/10, dias úteis de 14 a 31 dias, Queen Spa (2) e Balcony. Afrodite conferido: noites livres de 04, 07 e 08/10 calculadas em R$ 1.500.

**Recálculo:** a Queen (7) foi recalculada de novo às 13h (o limite de 429 já liberou). Os outros quartos foram recalculados pelo próprio Sync das 12:42; as substituições de fim de semana de 13:42 só entram no cálculo no próximo Sync ou recálculo.

## 30/09/2026, 13:58 — Rotina das 13:53 (Regra F): uma saída da escada e alerta de preço no ar

**Situação (dados de 13:53):** próximos 7 dias **20%** (44 de 217 diárias; meta 70%), 15 dias 27% (125 de 465), 30 dias 19%, 45 dias 19%, 60 dias cerca de 17% (estimado). Sem reserva nova depois do lote das 10:07–10:08; o último envio aos canais foi às 12:42 nos 7 quartos (a Villa King Spa 7 voltou a enviar). Os 27 registros de mudança das últimas 24 h vieram todos da nossa integração; nenhuma mudança manual.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Volta ao algoritmo (Regra F: data com 50% ou mais vendido sai da escada) | Double, 12/10 (3 de 6 vendidas) | fixo R$ 1.530 (acima do recomendado de R$ 1.284) | sem substituição | "Recoloque R$ 1.530 fixo em 12/10 na Double" |

**Alerta:** o Sync das 12:42 enviou R$ 1.405 para a Queen (7) em 13, 14 e 15/10 (o % de dia útil foi anulado pela suavização). A correção para R$ 880 fixo foi gravada às 13:42 e só chega aos canais no próximo Sync. A Fase 2 (sextas e sábados de 16 a 31/10 em %) também aguarda o Sync.

## 30/09/2026, 13:59 — "Aprovo tudo": Balcony, sexta da Villa e domingo 11/10 da Queen

Aprovados pelo dono: propostas 2, 3, 4 e 5 da agenda, a exceção à regra D para a Balcony (com a escada em % no mesmo formato do plano) e a recomendação de baixar o piso de fim de semana na tela.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| **Exceção à regra D (aprovo 4):** % com mínimo da data no valor aprovado | Balcony, sexta 02/10 e sábado 03/10 | R$ 1.200 (piso de fim de semana); substituição antiga −35% sem efeito | sexta **R$ 850**, sábado **R$ 1.000** (calculado e conferido) | "Volte a Balcony em 02 e 03/10 para −35% sem mínimo de data" (volta a R$ 1.200) |
| −20% (16 e 17/10) e −12% (23, 24 e 30/10), mínimo da data R$ 800 | Balcony, sextas e sábados à frente | R$ 1.200 (piso de fim de semana) | **R$ 800** (calculado e conferido) | "Apague as substituições de 16, 17, 23, 24 e 30/10 da Balcony" |
| Fixo → % (aprovo 3) | Villa King Spa (7), sexta 02/10 | fixo R$ 1.330 | −30% do recomendado, mínimo R$ 1.000 (esperado cerca de R$ 1.143 a R$ 1.150; **não conferido**, limite de recálculos) | "Volte 02/10 da Villa King Spa 7 para fixo R$ 1.330" |
| Fixo → % (aprovo 3) | Villa King Spa (2), sexta 02/10 | fixo R$ 1.140 | −29% do recomendado, mínimo R$ 900 (esperado cerca de R$ 1.003; **não conferido**) | "Volte 02/10 da Villa King Spa 2 para fixo R$ 1.140" |
| Preço fixo de teste (aprovo 2), 2 diárias mantidas | Queen (7), domingo 11/10 (5 de 7 vendidas) | R$ 1.405 (recomendado; enviado R$ 1.405) | fixo **R$ 1.900** (fixo porque a suavização anula o %); revisão quinta 01/10, 10h | "Apague o preço de 11/10 da Queen 7" (mantém as 2 diárias) |

- **Double 11/10:** a proposta 2 previa R$ 2.000, mas a categoria está esgotada (6 de 6); nada a fazer.
- **Balcony 31/10:** sábado com 3 de 6 vendidas (50%), fica de fora da escada (regra de saída); 09 e 10/10 (feriado) seguem no piso de R$ 1.200.
- **Proposta 5 adotada como regra fixa:** quinta 01/10, 10h, com menos de +3 diárias vendidas em sexta e sábado em relação a 30/09 (Queen, Double, Villa King Spa 7 e 2, Balcony), sobem mais 10 pontos de desconto (sempre acima do mínimo); com +8 ou mais, mantém e sobe o sábado.
- **Balcony, por que o % não flutua:** o recomendado dela está em R$ 295 a R$ 700, muito abaixo do mínimo (R$ 800), então o preço fica sempre no piso; o mínimo da data define o valor.
- **Piso de fim de semana na tela (aprovado; feito só pelo dono):** não pode ser alterado por aqui (as personalizações aceitas pela integração não incluem o "Preço mínimo de fim de semana"). Passo a passo no registro do plano, seção 7.
- **Recálculo:** Balcony recalculada e conferida. Villa King Spa (7) e Villa King Spa (2) não puderam ser recalculadas (429, limite de 3 por quarto a cada 24 h); o Sync do dono recalcula.

## 30/09/2026, 14:10 — "Feito" (Sync das 14:04): conferência do cálculo e correção das sextas da Villa

**Conferido no cálculo (recálculo das 14:04):** Queen (7) 13, 14 e 15/10 em R$ 880; 11/10 em R$ 1.900; 16 e 17/10 em R$ 1.422; 23 e 24/10 em R$ 1.548; 30 e 31/10 em R$ 1.894. Double, Villa King Spa (7) e Villa King Spa (2): sextas e sábados de 16 a 31/10 entre R$ 1.421 e R$ 2.076 (queda de 15% a 31% contra o piso). Balcony: sexta R$ 850, sábado R$ 1.000, 16, 17, 23, 24 e 30/10 em R$ 800. Double 12/10 em R$ 1.284 (sem a substituição).

**Problema encontrado:** a sexta 02/10 da Villa King Spa (7) calculou **R$ 1.451** (−30% diluído pela suavização; o fixo anterior era R$ 1.330) e a da Villa King Spa (2) **R$ 1.262** (antes R$ 1.140). O Sync das 14:04 já pode ter enviado esses valores.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Volta ao valor aprovado em preço fixo | Villa King Spa (7), sexta 02/10 | −30% (calculou R$ 1.451) | fixo **R$ 1.150** | "Volte 02/10 da Villa King Spa 7 para −30% do recomendado" |
| Volta ao valor aprovado em preço fixo | Villa King Spa (2), sexta 02/10 | −29% (calculou R$ 1.262) | fixo **R$ 1.000** | "Volte 02/10 da Villa King Spa 2 para −29% do recomendado" |

**Pendências para o próximo Sync:** as duas sextas acima (hoje podem estar no ar a R$ 1.451 e R$ 1.262). A **Balcony não foi enviada** no Sync das 14:04 (último envio 12:42; calculada em R$ 850 e R$ 1.000; no ar ainda R$ 1.200). O piso de fim de semana na tela **não aparece alterado** (Queen, 27 e 28/11, segue em R$ 2.070); se já foi trocado, falta o Save and Refresh. O `user_price` dos dias alterados só atualiza depois que o envio termina (levou cerca de 20 minutos no Sync das 12:42).

**Aprendizado:** o % de uma só noite de fim de semana é diluído pela suavização (plano, seção 2). Regra F ajustada: sexta e sábado só recebem % quando as duas noites entram juntas com o mesmo %; corte em uma data só é fixo.

## 01/10/2026, 11:40 — Rotina de quinta (10:53): regra de sexta e sábado aplicada só na Queen (7); a Villa foi negada pelo dono

**Leitura (último Sync às 08:42):** sexta 02/10 com 11 de 31 vendidas e sábado 03/10 com 12 de 31, contra 8 e 9 em 30/09 (+3 e +3). Os ganhos são do Double: 02/10 foi de 0 para 4 de 6 e 03/10 de 1 para 4 de 6, mas sem reserva, sem data de reserva e sem receita (ADR −1) nessas noites, e a lista de reservas do PriceLabs não mostra nenhuma reserva nova desde o lote de 30/09 às 10:08. Leitura: bloqueio de unidades no Beds24, não demanda. Sem o Double, sexta ficou em 7 (−1) e sábado em 9 (0), abaixo do limite de +3. **A regra de quinta (passo 2, aprovada em 30/09) dispara.**

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Passo 2 da sexta (mais 10 pontos), fixo, 1 noite | Queen (7), sexta 02/10 | R$ 1.050 | **R$ 900** | "Volte a Queen 7 de 02/10 para R$ 1.050" |
| Passo 2, sábado (mais 10%), fixo, 1 noite | Queen (7), sábado 03/10 | R$ 1.240 | **R$ 1.115** | "Volte a Queen 7 de 03/10 para R$ 1.240" |

Gravado e conferido na leitura da substituição (atualizado às 11:39). O valor chega aos canais no próximo Sync.

**Não aplicado (a chamada da Villa King Spa 7 foi negada pelo dono; parei sem tentar de novo nem aplicar nos demais):**

| Quarto | Sexta 02/10 | Sábado 03/10 |
|---|---|---|
| Double | R$ 1.150 → R$ 1.000 | R$ 1.330 → R$ 1.200 |
| Villa King Spa (7) | R$ 1.150 → R$ 1.050 | R$ 1.330 → R$ 1.200 |
| Villa King Spa (2) | R$ 1.000 → R$ 900 | R$ 1.140 → R$ 1.025 |
| Balcony (mínimo da data) | R$ 850 → R$ 800 | R$ 1.000 → R$ 900 |

Todos ficam acima do mínimo do quarto. Aguardam o "aprovo" do dono.

**Mantido de propósito:**
- **Queen (7), domingo 11/10:** segue em R$ 1.900 (5 de 7 vendidas, sem venda nova em cerca de 19 h; faltam 10 dias). Tirar a substituição devolveria o preço a cerca de R$ 1.405 por causa da suavização, então fica o teste até 08/10.
- **Double, substituições de 01, 02 e 03/10:** não saem da escada pela regra de 50% vendido, porque o dado é de bloqueio e não de demanda.

**Alertas:** o Double 02 e 03/10 precisa ser conferido no Beds24 (reserva ou bloqueio). Os preços recalculados às 08:42 de várias datas ainda não aparecem como enviados (por exemplo Double 12 a 15/10: calculado R$ 1.375, enviado R$ 1.284). O piso de fim de semana na tela segue em 150% da base (Queen, 27 e 28/11, em R$ 2.070).

## 01/10/2026, 23:05 — Rotina das 19:53: correção da regra de quinta e Double liberado (regra F)

**Erro corrigido:** na entrada das 11:40 eu li o salto do Double em 01, 02 e 03/10 como bloqueio no Beds24. Estava errado. A lista de reservas mostra reservas reais da Booking feitas de 30/09 17:40 a 01/10 10:23 (Double: duas de 2 diárias em 02 e 03/10, uma de 1 diária em 03/10, uma de 2 diárias em 01 e 02/10 e duas de 1 diária em 01/10, todas com comissão de OTA preenchida). Com os números certos, sexta e sábado ganharam +3 e +3 (total +6) contra 30/09 às 13:53, que fica entre +3 e +8: a regra de quinta manda **manter**. O corte da Queen (7) feito às 11:40 não valia.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Volta ao valor aprovado (fixo, 1 noite) | Queen (7), sexta 02/10 | R$ 900 (corte das 11:40) | **R$ 1.050** | "Baixe a Queen 7 de 02/10 para R$ 900" |
| Volta ao valor aprovado (fixo, 1 noite) | Queen (7), sábado 03/10 | R$ 1.115 (corte das 11:40) | **R$ 1.240** | "Baixe a Queen 7 de 03/10 para R$ 1.115" |
| Regra F: ≥ 50% vendido sai da escada. Preço fixo apagado, estadia mínima de 1 noite mantida | Double, sexta 02/10 (4 de 6) | fixo R$ 1.150 | sem preço fixo (o algoritmo calculava R$ 2.108; piso de fim de semana R$ 2.220) | "Volte o Double de 02/10 para fixo R$ 1.150" |
| Idem | Double, sábado 03/10 (4 de 6) | fixo R$ 1.330 | sem preço fixo (algoritmo R$ 2.431) | "Volte o Double de 03/10 para fixo R$ 1.330" |

Os dois cortes da Queen (7) não chegaram a ir para os canais (alterados às 11:39 e corrigidos antes do próximo Sync). Nenhum Sync desde 08:42.

**Mantido:** Double 01/10 (hoje, 4 de 6, −35% com mínimo R$ 900) segue como está até o fim do dia. A proposta de passo 2 da Villa e do Double (−10 pontos) está **cancelada**, porque a regra não disparou.

**Alertas desta rodada:**
- **Villa, canal "outros":** 4 reservas de R$ 546 para 15/10 criadas entre 21:38 e 22:16 de 30/09, 3 canceladas e 1 ativa; mais 2 canceladas e 1 ativa de R$ 500 em 30/09. Padrão de criar e cancelar em sequência, conferir a origem (balcão, Beds24 manual ou integração).
- A lista de reservas do PriceLabs só atualiza no Sync. Reservas feitas depois de 08:42 de 01/10 não aparecem.

## 02/10/2026, 11:00 — Rotina das 10:53: regra F nas datas que passaram de 50% vendido

**Sync das 08:25 de hoje recebido** (todos os 7 quartos, push ligado). A ocupação dos próximos 7 dias foi de 21% para **30%** (65 de 217 diárias). Sexta 02/10 está em 17 de 31 (55%) e sábado 03/10 em 19 de 31 (61%); no fim de semana de sexta a domingo, 42 de 93 (45%). Semana de 05 a 11/10: 78 de 217 (36%), com domingo 11/10 em 30 de 31.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra F: ≥ 50% vendido sai da escada. Preço fixo apagado, 1 noite mantida | Queen (7), sexta 02/10 (5 de 7) | fixo R$ 1.050 | sem preço fixo (algoritmo R$ 1.879; piso de fim de semana R$ 2.070) | "Volte a Queen 7 de 02/10 para fixo R$ 1.050" |
| Idem | Queen (7), sábado 03/10 (6 de 7) | fixo R$ 1.240 | sem preço fixo (algoritmo R$ 2.366) | "Volte a Queen 7 de 03/10 para fixo R$ 1.240" |
| Idem, −35% apagado | Double, terça 06/10 (4 de 6) | −35%, mínimo R$ 900 (enviado R$ 900) | sem desconto (algoritmo R$ 1.303) | "Volte o Double de 06/10 para −35% com mínimo 900" |
| Idem | Double, quarta 07/10 (3 de 6) | −35%, mínimo R$ 900 | sem desconto (algoritmo R$ 1.176) | "Volte o Double de 07/10 para −35% com mínimo 900" |
| Idem | Double, quinta 08/10 (3 de 6) | −35%, mínimo R$ 900 | sem desconto (algoritmo R$ 1.194) | "Volte o Double de 08/10 para −35% com mínimo 900" |
| Idem, preço fixo apagado, 1 noite mantida | Villa King Spa (7), sábado 03/10 (4 de 7) | fixo R$ 1.330 (mínimo R$ 1.000) | sem preço fixo (algoritmo R$ 2.257; piso de fim de semana R$ 2.220) | "Volte a Villa King Spa 7 de 03/10 para fixo R$ 1.330" |

Todas as datas acima chegam aos canais só no próximo Sync.

**Conferido sem mudança:**
- **Double, sexta e sábado (liberado ontem às 23:04):** o PriceLabs calcula R$ 2.220 nas duas noites (5 de 6 vendidas), mas o último preço confirmado nos canais ainda é R$ 1.150 e R$ 1.330, mesmo com o Sync das 08:25. Conferir no Beds24 e na Booking; se não mudar, outro Sync Now.
- **Afrodite 02 e 03/10:** o PriceLabs mostra as noites como livres a R$ 3.000, mas a reserva da Booking de R$ 3.465 (01 a 03/10) está ativa, sem cancelamento. Tratei como vendida e **não** apliquei a regra C. Afrodite 12 a 15/11 aparece como "indisponível" sem reserva (bloqueio ou reserva ainda não importada).
- **Queen (7), domingo 11/10:** 6 de 7 vendidas a R$ 1.900 (era 5 de 7), teste mantido.
- A lista de reservas por data de reserva só devolve até 01/10; o ganho de ontem à noite aparece na ocupação por quarto.

## 02/10/2026, 13:55 — Pedido do dono: sábado com valor real ≥ R$ 800, sexta mantida e feriado 09–11/10 conferido com os concorrentes

### 1) Sábado 03/10: valor real (o que o hóspede paga) de pelo menos R$ 800 nas suítes com banheira

O valor real vem da lista de reservas do Beds24 (campo de receita da reserva) comparado ao preço enviado à Booking. Reservas de sábado 03/10 feitas até hoje 13:47:

| Quarto | Enviado | Real pago | Real ÷ enviado |
|---|---|---|---|
| Villa King Spa (2) | R$ 1.140 | R$ 443 | 39% |
| Villa King Spa (7) | R$ 1.330 | R$ 528 | 40% |
| Queen (7) | R$ 1.240 | R$ 523 (hoje) e R$ 666 | 42% a 54% |
| Double | R$ 1.330 | R$ 702 | 53% |

O real fica entre 39% (Villa) e 54% (Recanto) do enviado, porque o desconto da Booking muda de reserva para reserva e eu não posso mexer nele. Para chegar a R$ 800 no pior caso observado, o preço **enviado** de sábado precisa ser de pelo menos **R$ 2.060 na Villa** (800 ÷ 0,39) e **R$ 1.900 no Recanto** (800 ÷ 0,42). Coloquei um piso de preço na própria data (o piso da data vale no lugar do piso de fim de semana).

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso da data R$ 2.100, 1 noite mantida | Queen (7), sábado 03/10 | sem piso da data (piso de fim de semana R$ 2.070) | piso R$ 2.100; o PriceLabs calcula R$ 2.100 (algoritmo R$ 2.474). **Já está 7 de 7 vendida** | "Tire o piso de R$ 2.100 da Queen 7 de 03/10" |
| Piso da data R$ 2.220, 1 noite mantida | Double, sábado 03/10 | sem piso da data (piso de fim de semana R$ 2.220) | piso R$ 2.220; calcula R$ 2.220 (4 de 6 vendidas) | "Tire o piso de R$ 2.220 do Double de 03/10" |
| Piso da data R$ 2.220, 1 noite mantida | Villa King Spa (7), sábado 03/10 | sem piso da data; calculado R$ 2.220 mas enviado R$ 1.330 | piso R$ 2.220; calcula R$ 2.220 (5 de 7 vendidas) | "Tire o piso de R$ 2.220 da Villa King Spa 7 de 03/10" |
| Preço fixo R$ 1.140 apagado e recriado só com piso R$ 2.100 e 1 noite | Villa King Spa (2), sábado 03/10 | fixo R$ 1.140 (mínimo R$ 900); real ~R$ 443 | piso R$ 2.100; calcula R$ 2.100 (algoritmo R$ 1.835) | "Volte a Villa King Spa 2 de 03/10 para fixo R$ 1.140 com mínimo 900" |

Real esperado com esses pisos: Double R$ 2.220 × 53% = R$ 1.177; Villa King Spa (7) R$ 2.220 × 40% = R$ 888; Villa King Spa (2) R$ 2.100 × 39% = R$ 819; Queen (7) R$ 2.100 × 42% = R$ 882. Fora do pedido (sem banheira): Balcony 03/10 segue R$ 1.000. Queen (2) e Afrodite do sábado já estão vendidas.

### 2) Sexta 02/10: mesmo patamar, sem ajuste

A regra F de hoje cedo tinha apagado o preço fixo da Queen (7) (R$ 1.050) e o do Double já estava apagado desde 01/10. Pelo seu pedido, **voltei os dois**.

| Mudança | Onde | Antes | Depois | Como desfazer |
|---|---|---|---|---|
| Fixo recriado, 1 noite | Queen (7), sexta 02/10 | sem preço fixo (algoritmo R$ 1.879 / piso R$ 2.070) | fixo R$ 1.050 | "Apague o fixo da Queen 7 de 02/10" |
| Fixo recriado, 1 noite | Double, sexta 02/10 | sem preço fixo (calculado R$ 2.220, enviado R$ 1.150) | fixo R$ 1.150 | "Apague o fixo do Double de 02/10" |

Sem mudança: Villa King Spa (7) R$ 1.150, Villa King Spa (2) R$ 1.000, Balcony R$ 850. **Alerta:** o último valor confirmado do Double na sexta aparece como R$ 2.220 depois do recálculo; o preço calculado voltou a R$ 1.150, mas só chega aos canais no próximo **Sync Now**.

### 3) Feriado 09–11/10: conferência com os concorrentes (Booking, 2 adultos, 2 noites, quarto mais barato)

Busca na Booking de hoje com filtro de hidromassagem/jacuzzi e vista para a montanha, em Campos do Jordão. Preço público do quarto de entrada de cada hotel; não é o enviado.

| Hotel | Nota | Estrelas | 2 noites | Por noite |
|---|---|---|---|---|
| Hotel Toriba | 9,4 | 5 | R$ 8.971 | R$ 4.486 |
| Hotel Boutique Quebra-Noz | 9,0 | 5 | R$ 6.480 | R$ 3.240 |
| L.A.H. Hostellerie | 9,8 | 5 | R$ 5.100 | R$ 2.550 |
| Pousada D'Biagy Premium | 9,2 | 5 | R$ 4.978 | R$ 2.489 |
| Pousada Murano | 9,7 | 5 | R$ 4.668 | R$ 2.334 |
| Secreto Boutique Hotel | 9,2 | 5 | R$ 3.948 | R$ 1.974 |
| Carballo Hotel & Spa | 9,7 | 4 | R$ 3.418 | R$ 1.709 |
| Hotel Serra da Estrela | 9,0 | 4 | R$ 3.058 | R$ 1.529 |
| Gran Paradiso | 8,2 | 5 | R$ 3.041 | R$ 1.520 |
| **Recanto dos Moinhos (nós)** | **8,4** | **5** | **R$ 3.062** | **R$ 1.531** |
| Vila Grega | 9,8 | 4 | R$ 2.723 | R$ 1.361 |
| Hotel Estoril | 8,8 | 4 | R$ 2.475 | R$ 1.238 |
| Village della Nonna | 9,5 | 4 | R$ 2.254 | R$ 1.127 |
| Pousada Casa Redonda | 9,2 | 4 | R$ 1.904 | R$ 952 |
| Pousada Recanto Feliz | 8,8 | 4 | R$ 1.336 | R$ 668 |
| **Villa Dolce Amore (nós, busca anterior de hoje)** | **8,1** | **3** | **R$ 1.270** | **R$ 635** |

A Booking confirmou banheira/hidromassagem com vista para a montanha em Gran Paradiso, D'Biagy, Village della Nonna e Serra da Estrela; nos demais há "jacuzzi" na lista de facilidades, mas a ferramenta não confirmou o quarto nem a vista. A busca pelo nome dos nossos dois hotéis para 09 a 11/10 devolveu "sem disponibilidade", mas a busca geral mostra o Recanto disponível por R$ 3.062; não confiei na busca por nome.

**Leitura:** a mediana dos seis hotéis de luxo de nota 9,0 a 9,8 (Secreto, Murano, D'Biagy, L.A.H., Quebra-Noz, Toriba) é **R$ 2.520 por noite**. O quarto de entrada do Recanto (Queen 7) sai a R$ 1.531, empatado com o Gran Paradiso (nota 8,2) e com o Serra da Estrela (nota 9,0), e cerca de 39% abaixo dessa mediana. Com nota 8,4, contra 9,0 a 9,8 dos concorrentes, uma diferença de preço de 10% a 25% é coerente (estimativa de mercado, não medida por mim); abaixo disso estamos pagando um desconto maior que o da nota. A Villa (nota 8,1, 3 estrelas) está no patamar de Recanto Feliz e Casa Redonda e abaixo dos de nota 9+.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Fixo R$ 2.850 nas 2 noites (2 noites mínimas) | Queen (2), 09/10 e 10/10 | sem DSO; enviado R$ 3.558 a R$ 3.667, 52% acima da Queen (7), 0 de 2 vendidas em 09/10 e 1 de 2 em 10/10 | fixo R$ 2.850 (igual ao Double); público ≈ R$ 1.800 por noite, ~9% abaixo do Secreto (nota 9,2) | "Apague o fixo da Queen 2 de 09 e 10/10" |
| Preço fixo R$ 1.900 apagado; 2 noites mantidas (aviso 3 dias) | Queen (7), domingo 11/10 | fixo R$ 1.900 (6 de 7 vendidas, teste aprovado) | algoritmo R$ 3.094 (regra F: ≥ 50% vendido) | "Volte a Queen 7 de 11/10 para fixo R$ 1.900" |

**Mantidos, com o motivo:** Queen (7) 09/10 R$ 2.420 (2 de 7; já no patamar de Gran Paradiso e Serra da Estrela) e 10/10 calculado R$ 2.682 (4 de 7); Double R$ 2.850 nas duas noites (3 de 6 em 09/10, 1 de 6 em 10/10); Villa King Spa (7) calculado R$ 2.852 (4 de 7); Villa King Spa (2) vendida; Afrodite R$ 4.060; Balcony R$ 1.200 (1 de 6). Queen (2) e Double de domingo 11/10 estão vendidos.

### Pendências do dono

- **Sync Now agora:** VK2 de sábado ainda está enviado a R$ 1.140 e VK7 a R$ 1.330; o Double de sexta aparece enviado R$ 2.220. Sobram 5 suítes com banheira para sábado (Double 2, VK7 2, VK2 1); enquanto não houver Sync, vendem pelo preço antigo.

## 02/10/2026, 14:30 — Rotina das 13:53: ocupação, regra C no feriado da Afrodite e alertas

**Ocupação (cumulativa a partir de hoje, 02/10), com os dados das 13:55:**

| Quarto | 7 dias (meta 70%) | 15 dias (50%) | 30 dias (35%) | 45 dias (25%) | 60 dias (15%) |
|---|---|---|---|---|---|
| Queen (7) | 24% ⚠️ | 30% ⚠️ | 15% ⚠️ | 12% ⚠️ | 11% ⚠️ |
| Double | 55% ⚠️ | 54% ✅ | 37% ✅ | 27% ✅ | 25% ✅ |
| Afrodite | 57% ⚠️ | 53% ✅ | 43% ✅ | 33% ✅ | 30% ✅ |
| Queen (2) | 64% ⚠️ | 40% ⚠️ | 42% ✅ | 43% ✅ | 38% ✅ |
| Villa King Spa (7) | 37% ⚠️ | 37% ⚠️ | 27% ⚠️ | 28% ✅ | 22% ✅ |
| Balcony | 12% ⚠️ | 16% ⚠️ | 12% ⚠️ | 16% ⚠️ | 13% ⚠️ |
| Villa King Spa (2) | 21% ⚠️ | 33% ⚠️ | 27% ⚠️ | 28% ✅ | 24% ✅ |
| **Hotel todo** | **34% (74/217)** ⚠️ | **35% (163/465)** ⚠️ | **25% (230/930)** ⚠️ | **23% (323/1395)** ⚠️ | **20% (367/1860)** ✅ |
| **Hotel sem Balcony** | 39% ⚠️ | 40% ⚠️ | 28% ⚠️ | 25% ✅ | 21% ✅ |

Às 11:00 a ocupação de 7 dias era 30% (65 de 217): entraram 9 diárias desde então (Queen (7) sábado 03/10 ficou 7 de 7, Villa King Spa (7) sexta e sábado 5 de 7). Por noite: sex 02/10 65%, sáb 03/10 68%, dom 04/10 19%, seg 23%, ter 32%, qua 16%, qui 16%, sex 09/10 39%, sáb 10/10 45%, dom 11/10 100%.

**Mudança desta rotina (regra C, Afrodite):**

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra C, noite livre a menos de 14 dias, −30% (piso R$ 1.500) | Afrodite, sexta 09/10 (0 vendida) | fixo R$ 4.060 (2 noites) | fixo R$ 2.840 (2 noites mantidas); público ≈ R$ 1.790 por noite, abaixo do Secreto (nota 9,2) pela nossa nota 8,4 | "Volte a Afrodite de 09/10 para fixo R$ 4.060" |
| Idem | Afrodite, sábado 10/10 (0 vendida) | fixo R$ 4.060 (2 noites) | fixo R$ 2.840 (2 noites mantidas) | "Volte a Afrodite de 10/10 para fixo R$ 4.060" |
| Idem, −30% do algoritmo R$ 2.505 | Afrodite, segunda 12/10 (0 vendida) | fixo R$ 2.120 | fixo R$ 1.750 | "Volte a Afrodite de 12/10 para fixo R$ 2.120" |

Sem outras mudanças: as datas de meio de semana (04 a 08/10) já estão no mínimo do quarto (Queen (7) R$ 800, Double R$ 925, Villa King Spa (7) R$ 1.023, Villa King Spa (2) R$ 900, Balcony R$ 800); reduzir mais exige baixar o mínimo, proposta "aprovo 1" ainda pendente. A regra F não encontrou data nova com ≥ 50% vendido nas escadas de 16/10 em diante.

**Conferências:**
- Registro de ações das últimas 24 h: só as chamadas do Claude (usuário de integração); nenhuma mudança manual de preço base, mínimo ou máximo pela tela.
- Último Sync confirmado: 11:25 UTC (08:25 de Brasília), push ligado nos 7 quartos. Nenhuma das mudanças de hoje depois disso chegou aos canais.
- Reservas importadas em lote às 13:35 e 13:36 UTC (Beds24 → Booking), com comissão 0,0: não é sinal de problema, parece só o momento da importação.
- Airbnb vendeu 7 reservas do Double a R$ 294 a R$ 496 por noite no total pago, a mesma proporção de 39% a 54% do enviado que vemos na Booking; a Expedia vendeu a Villa King Spa (7) de 02 a 03/10 por R$ 7.761 (2 noites, 1 reserva), muito acima da Booking.
- Cancelamento: Double sexta 02/10 (Airbnb, R$ 376), substituído por outra reserva de R$ 376 minutos depois.

## 02/10/2026, 16:30 e 19:30 e 03/10/2026, 07:55 — Rotinas das 16:53, 19:53 e 07:53

**16:53 e 19:53 (02/10):** sem dado novo (último Sync às 08:25 de 02/10), nenhuma mudança. Recalculei a Afrodite e o PriceLabs confirmou 09 e 10/10 em R$ 2.840 e 12/10 em R$ 1.750.

**07:53 (03/10):** o Sync saiu às 05:40 (08:40 UTC) nos 7 quartos, push ligado. O painel ainda mostra como "último valor confirmado" os valores antigos (Villa King Spa (2) sábado R$ 1.140, Afrodite 09 e 10/10 R$ 4.060, Queen (2) 09 e 10/10 R$ 3.558); nas vezes anteriores essa confirmação demorou horas, então conferir na Booking.

Ocupação a partir de hoje, 03/10: **hotel 34% em 7 dias (74/217)**, 34% em 15 (156/465), 26% em 30 (238/930), 23% em 45 (321/1395), 20% em 60 (365/1860). Sem a Balcony: 39%, 38%, 28%, 25%, 21%. Hoje, sábado 03/10: 24 de 31 vendidas (77%); Queen (7), Villa King Spa (7), Queen (2) e Afrodite esgotadas; livres: Double 2, Villa King Spa (2) 1 e Balcony 4. Por noite: dom 04/10 23%, seg 29%, ter 32%, qua 16%, qui 19%, sex 09/10 42%, sáb 10/10 45%, dom 11/10 100%. A Afrodite agora conta as noites "indisponíveis" mesmo sem reserva visível (03/10 e 12 a 15/11), como no número do próprio PriceLabs.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra F: ≥ 50% vendido sai da escada. −35% apagado, 1 noite mantida | Double, domingo 04/10 (3 de 6) | −35%, mínimo R$ 900 (enviado R$ 900) | sem desconto (algoritmo R$ 1.199) | "Volte o Double de 04/10 para −35% com mínimo 900" |
| Idem | Double, segunda 05/10 (4 de 6) | −35%, mínimo R$ 900 (enviado R$ 900) | sem desconto (algoritmo R$ 1.305) | "Volte o Double de 05/10 para −35% com mínimo 900" |
| Regra A: sexta 16/10 entra na janela de 14 dias, 1 noite | Double, Villa King Spa (7), Villa King Spa (2), Queen (2), sexta 16/10 | estadia mínima 2 | estadia mínima 1 (preços do −20% e da Queen (2) sem mudança) | "Volte a estadia mínima de 16/10 para 2 no(s) quarto(s) X" |
| Regra C (−30%) + regra A | Afrodite, sexta 16/10 (livre, a 13 dias) | calculado R$ 3.212 (enviado R$ 3.181), estadia mínima 2 | fixo R$ 2.250, estadia mínima 1 | "Apague o fixo da Afrodite de 16/10" |

**Conferências:** nenhuma mudança manual de base, mínimo ou máximo; só as chamadas do Claude. A lista de reservas por data de reserva não devolve nada depois de 02/10 13:47 UTC, embora a ocupação tenha subido (Villa King Spa (7) sábado 5 → 7 de 7, Double 05/10 2 → 4, Double 08/10 3 → 4, Queen (7) 17/10 0 → 1, Balcony sábado 1 → 2): usar a ocupação por quarto, não essa lista. Preços de domingo 04/10 calculados: Queen (7) R$ 800, Double R$ 921 (passa ao algoritmo no próximo Sync), Villa King Spa (7) R$ 1.039, Villa King Spa (2) R$ 912, Balcony R$ 800, Afrodite R$ 1.500.

## 03/10/2026, 14:10 — Rotina das 13:53: regra F na Villa (dias úteis), Double 09/10 liberado e sexta 23/10 na faixa de −20%

**Sync Now às 13:34 (16:34 UTC)** nos 7 quartos, push ligado: as mudanças pendentes desde 05:40 chegaram aos canais (Afrodite 16/10 enviada a R$ 2.250, Double 04 e 05/10 no algoritmo).

**Mudança feita pela tela, fora deste registro:** às 13:35 (16:35 UTC), pelo navegador, entrou nos 7 quartos uma substituição de **+20% com estadia mínima de 3 noites de 30/10 a 02/11** (Finados). Ela trocou o −12% da escada em 30 e 31/10. Respeitei e não mexi. Ainda não foi enviada (o Sync foi 1 minuto antes).

**Ocupação a partir de hoje (dados das 13:36):** hotel **35% em 7 dias (75/217)**, 35% em 15 (162/465), 27% em 30, 24% em 45, 21% em 60. Sem a Balcony: 39%, 40%, 30%, 26%, 22%. Hoje, sábado 03/10: 23 de 31 (74%); livres Double 3, Queen (2) 1 e Balcony 4. Por noite: dom 04/10 23%, seg 29%, ter 35%, qua 16%, qui 23%, sex 09/10 42%, sáb 10/10 45%, **dom 11/10 esgotado (31/31)**.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra F, dia útil D1 a D5: −35% do recomendado, piso = mínimo do quarto, 1 noite | Villa King Spa (7), 04 a 08/10 (2, 1, 3, 1 e 3 de 7 vendidas) | sem substituição (calculado R$ 1.076) | −35%, mínimo R$ 1.000 (≈ R$ 1.000) | "Apague as substituições de 04 a 08/10 da Villa King Spa 7" |
| Idem | Villa King Spa (2), 05 a 08/10 (0 de 2 vendidas) | sem substituição (calculado R$ 939) | −35%, mínimo R$ 900 (≈ R$ 900) | "Apague as substituições de 05 a 08/10 da Villa King Spa 2" |
| Regra F: ≥ 50% vendido sai da escada. Fixo apagado, 2 noites do feriado mantidas | Double, sexta 09/10 (4 de 6) | fixo R$ 2.850 | algoritmo (≈ R$ 2.986), estadia mínima 2 | "Volte o Double de 09/10 para fixo R$ 2.850 com 2 noites" |
| Regra F: sexta entrou na faixa de 14 a 21 dias (D20) | Queen (7), Double, Villa King Spa (7), Villa King Spa (2), sexta 23/10 | −12% do recomendado (piso = mínimo do quarto) | −20% do recomendado (mesmo piso) | "Volte a sexta 23/10 para −12% nos 4 quartos" |

**Mantido de propósito:** sábado 24/10 segue em −12%. Pela escada iria a −20%, mas ontem você pediu valor real de pelo menos R$ 800 no sábado (o hóspede paga 40% a 55% do enviado). Fica para você decidir se essa regra vale para todos os sábados. Queen (7) 04 a 08/10 já está no mínimo (R$ 800) com 0 de 7 vendidas; Afrodite e feriado sem data nova para a regra C.

**Reservas desde a rotina das 07:53:** Queen (7) 14–15/10 (R$ 465 por noite) e 15–16/10 (R$ 648 por noite), Queen (2) 17/10 (R$ 782), Villa King Spa (7) 06/10 (R$ 595) e 08/10 (R$ 319, canal "outros"), Villa King Spa (2) 03/10 (R$ 568), Balcony 30/10 a 01/11 (R$ 1.660, 3 noites) e 01/11 (R$ 470). Cancelamento: Queen (7) 03/10 (Booking, feita em 02/10). Alerta: as vendas de sábado 03/10 feitas antes de o piso chegar aos canais ficaram abaixo de R$ 800 reais (Villa King Spa (7) R$ 450 e R$ 550, Villa King Spa (2) R$ 568, Queen (7) R$ 645); e a Villa King Spa (7) 08/10 por R$ 319 no canal "outros" precisa ser conferida.

As mudanças desta rotina chegam aos canais no próximo Sync.

## 03/10/2026, 15:00 — Pedido do dono: feriado 09–11/10 reposicionado ("nosso valor parece estar abaixo de mercado")

**Situação do feriado (dados das 13:36):** domingo 11/10 **esgotado nos 7 quartos (31/31)**. Sobram 18 unidades na sexta 09/10 (42% vendido) e 17 no sábado 10/10 (45%). Como domingo está esgotado, só dá para vender sexta + sábado (saída no domingo) ou noites avulsas.

**Mercado (PriceLabs, 144 anúncios de 1 quarto no bairro):** ocupação do mercado no sábado 10/10 em 41% contra 16% na mesma data do ano passado (+25 pontos); sexta 26,5% contra 14,9%; domingo 40,9% contra 15,3%. Segunda 12/10 está fraca (15,7%, igual ao ano passado). O PriceLabs marca o feriado como evento ("Dia das Crianças") e aponta alta de preço de 36% no dia 11.

**Concorrentes na Booking hoje (2 adultos, 09 a 11/10, 2 noites, quarto mais barato, preço público):**

| Hotel | Nota | Estrelas | 2 noites | Por noite |
|---|---|---|---|---|
| Hotel Toriba | 9,4 | 5 | R$ 8.971 | R$ 4.486 |
| Hotel Boutique Quebra-Noz | 9,0 | 5 | R$ 6.480 | R$ 3.240 |
| Secreto Boutique Hotel | 9,2 | 5 | R$ 4.986 (ontem R$ 3.948, +26%) | R$ 2.493 |
| Alma Hotel | 9,1 | 5 | R$ 4.812 | R$ 2.406 |
| Pousada D'Biagy Premium | 9,2 | 5 | R$ 4.810 | R$ 2.405 |
| Pousada Murano | 9,7 | 5 | R$ 4.668 | R$ 2.334 |
| Pousada Luis XV | 9,4 | 5 | R$ 4.138 | R$ 2.069 |
| L.A.H. Hostellerie | 9,8 | 5 | R$ 4.092 | R$ 2.046 |
| Figueira da Serra | 9,2 | 5 | R$ 3.753 | R$ 1.876 |
| Carballo Hotel & Spa | 9,7 | 4 | R$ 3.418 | R$ 1.709 |
| Casa Regaleira | 9,8 | 5 | R$ 3.348 | R$ 1.674 |
| Hotel Serra da Estrela | 9,0 | 4 | R$ 3.058 | R$ 1.529 |
| **Recanto dos Moinhos (antes)** | **8,4** | **5** | **R$ 3.042** | **R$ 1.521** |
| Casablanca Hotel Boutique | 9,6 | 5 | R$ 2.880 | R$ 1.440 |
| Vila Grega | 9,8 | 4 | R$ 2.723 | R$ 1.361 |
| Le Renard | 9,3 | 5 | R$ 2.662 | R$ 1.331 |
| Villa Casato | 9,0 | 5 | R$ 2.500 | R$ 1.250 |
| Hotel Estoril | 8,8 | 4 | R$ 2.475 | R$ 1.238 |
| Village della Nonna | 9,5 | 4 | R$ 2.254 | R$ 1.127 |
| Pousada Casa Redonda | 9,2 | 4 | R$ 1.904 | R$ 952 |
| Europa Hotel | 8,7 | 3 | R$ 1.320 | R$ 660 |
| **Villa Dolce Amore (antes, Balcony)** | **8,1** | **3** | **R$ 1.270** | **R$ 635** |

Mediana dos 13 hotéis 5 estrelas com nota 9 ou mais: **R$ 4.138 (R$ 2.069 por noite)**. O Recanto estava 26% abaixo dessa mediana; com nota 8,4, o desconto coerente fica entre 10% e 25%. Sábado avulso (10 a 11/10): L.A.H. R$ 2.462, Recanto R$ 1.660 (antes).

**Problema encontrado:** no Double, sábado 10/10 tinha estadia mínima de 2 noites e o domingo está esgotado. Ninguém conseguia chegar no sábado: **3 das 5 unidades livres estavam presas** (por isso o Double tinha só 1 de 6 vendida no sábado e 4 de 6 na sexta).

| Mudança | Onde | Antes | Depois | Público estimado (Booking) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|---|
| Fixo | Queen (7), sexta 09/10 (2 de 7) | fixo R$ 2.420 | **fixo R$ 2.690** | pacote sexta + sábado ≈ R$ 3.510 (R$ 1.755 por noite), 15% abaixo da mediana | "Volte a Queen 7 de 09/10 para R$ 2.420" |
| Fixo | Queen (7), sábado 10/10 (5 de 7) | algoritmo R$ 2.588 | **fixo R$ 3.090** | sábado avulso ≈ R$ 1.980 | "Apague o fixo da Queen 7 de 10/10" |
| Fixo e **1 noite** | Double, sábado 10/10 (1 de 6) | fixo R$ 2.850, 2 noites | **fixo R$ 3.190, 1 noite** | sábado avulso ≈ R$ 2.045 | "Volte o Double de 10/10 para R$ 2.850 com 2 noites" |
| Fixo, 2 noites | Afrodite, 09 e 10/10 (livre) | fixo R$ 2.840 (regra C) | **fixo R$ 3.390** | pacote ≈ R$ 4.115, na mediana | "Volte a Afrodite de 09 e 10/10 para R$ 2.840" |
| Fixo, 2 noites | Queen (2), sábado 10/10 (1 de 2) | fixo R$ 2.850 | **fixo R$ 3.290** (≈ 6% acima da Queen 7) | pacote ≈ R$ 3.730 | "Volte a Queen 2 de 10/10 para R$ 2.850" |
| Fixo | Balcony, 09 e 10/10 (1 de 6) | R$ 1.200 (travado no teto) | **fixo R$ 1.400** | ≈ R$ 740 por noite, entre Europa (8,7) e Casa Redonda (9,2) | "Apague o fixo da Balcony de 09 e 10/10" |

Recalculado e conferido: Queen (7) R$ 2.690 e R$ 3.090; Double 09/10 R$ 3.101 (algoritmo, liberado pela regra F às 14:00) e 10/10 R$ 3.190 com 1 noite; Afrodite R$ 3.390; Queen (2) R$ 2.850 e R$ 3.290; Balcony R$ 1.400. Os valores chegam aos canais no próximo Sync.

**Mantidos, com o motivo:** Villa King Spa (7) no algoritmo (R$ 2.942, 4 de 7 nas duas noites): público estimado ≈ R$ 3.120 o pacote, já no nível do Serra da Estrela (4 estrelas, 9,0) com nota 8,1. Villa King Spa (2) esgotada. Queen (2) sexta R$ 2.850 (≈ 6% acima da Queen 7). Segunda 12/10 sem mudança (mercado em 15,7%).

**Regra de vigilância:** ~~se até terça 06/10 à noite a sexta 09/10 da Queen (7) continuar com 3 ou menos vendidas, volta para R$ 2.420~~ (substituída às 15:10 pelo piso de R$ 1.200 reais definido pelo dono; corte só com aprovação dele). Se até quarta 07/10 as sextas da Villa King Spa (7), Queen (2) e Afrodite não venderem, abrir o sábado com 1 noite.

## 03/10/2026, 15:10 — Pedido do dono: feriado com no mínimo R$ 1.200 por diária ("Ainda estamos com valores muito baixos para o feriado")

**Regra definida pelo dono:** nas suítes com banheira, o valor **real** (o que o hóspede paga) deve ser de pelo menos R$ 1.200 por diária em 09 e 10/10. Na Balcony (sem banheira), pelo menos R$ 1.200 **na vitrine da Booking**. Conta usada: o hóspede paga, no pior caso, 42% do enviado no Recanto, 34% na Queen (2) e 39% na Villa; a vitrine da Booking mostra cerca de 53% do enviado na Villa.

| Mudança | Onde | Antes | Depois (recalculado e conferido) | Real estimado por diária | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|---|
| Fixo | Queen (7), sexta 09/10 | R$ 2.690 | **R$ 2.890** | ≈ R$ 1.214 | "Volte a Queen 7 de 09/10 para R$ 2.690" |
| Sem mudança (já acima) | Queen (7), sábado 10/10 | R$ 3.090 | R$ 3.090 | ≈ R$ 1.298 | — |
| Piso da data R$ 2.890 (preço segue no algoritmo, 2 noites) | Double, sexta 09/10 | algoritmo R$ 3.101, sem piso | R$ 3.101, piso R$ 2.890 | ≈ R$ 1.302 | "Tire o piso de R$ 2.890 do Double de 09/10" |
| Sem mudança (já acima) | Double, sábado 10/10 | R$ 3.190 | R$ 3.190 | ≈ R$ 1.340 | — |
| Fixo, 2 noites | Afrodite, 09 e 10/10 | R$ 3.390 | **R$ 3.690** (suíte topo acima da Queen 2) | ≈ R$ 1.550 | "Volte a Afrodite de 09 e 10/10 para R$ 3.390" |
| Fixo, 2 noites | Queen (2), 09 e 10/10 | R$ 2.850 e R$ 3.290 | **R$ 3.550** | ≈ R$ 1.207 | "Volte a Queen 2 para R$ 2.850 (09/10) e R$ 3.290 (10/10)" |
| Piso da data R$ 3.090 (2 noites) | Villa King Spa (7), 09 e 10/10 | algoritmo R$ 2.942 | **R$ 3.090** | ≈ R$ 1.205 | "Tire o piso de R$ 3.090 da Villa King Spa 7 de 09 e 10/10" |
| Fixo | Balcony, 09 e 10/10 | R$ 1.400 | **R$ 2.290** | vitrine ≈ R$ 1.214 | "Volte a Balcony de 09 e 10/10 para R$ 1.400" |

Villa King Spa (2) segue esgotada. Segunda 12/10 sem mudança. Os valores chegam aos canais no próximo Sync.

**Risco a acompanhar:** na Booking, o pacote de 2 noites da Queen (7) passa para cerca de R$ 3.640 (antes R$ 3.042), acima do Carballo (9,7) e do Casa Regaleira (9,8) e perto do Figueira da Serra (9,2). A Balcony passa para cerca de R$ 2.430 o pacote, no nível do Hotel Estoril (4 estrelas, 8,8). Regra de vigilância: se até terça 06/10 à noite a sexta 09/10 não vender nenhuma unidade nova na Queen (7) e na Balcony, avisar o dono antes de qualquer corte (o piso de R$ 1.200 é decisão dele).

## 05/10/2026, 14:15 — Rotina das 13:53 (as rotinas de 04/10 e das 07:53 e 10:53 de 05/10 não rodaram: sessão parada)

**Sync às 09:43 de hoje** (12:43 UTC) nos 7 quartos, push ligado: os preços do feriado (piso de R$ 1.200 reais) já aparecem como enviados. Nenhuma mudança manual no PriceLabs em 04 e 05/10.

**Ocupação a partir de hoje (dados das 09:43):** hotel **47% em 7 dias (103/217)**, era 35% no sábado; 36% em 15 dias (166/465). Sem a Balcony: 52% e 41%. Pelo próprio PriceLabs: Queen (7) 39% em 7 dias, Double 71%, Queen (2) 71%, Villa King Spa (7) 47%, mercado 25%.

**Reservas desde sábado:** Queen (7) 05–06/10 (R$ 404 por noite, feita hoje às 12:25, ainda não aparece na ocupação), 06–07/10 e 06–08/10 (R$ 330 e R$ 404 por noite); Double 04/10, 07–08/10 (duas), 13/10, 18/10 e 30/10–01/11 (R$ 2.882, canal "outros"); Queen (2) 07–08/10, 14–15/10, 16–17/10 e 18/10; Villa King Spa (7) 04/10, 06–07/10, 16–17/10 (R$ 1.647, "outros"), 19/10 (R$ 400, "outros"), 02/11 e 30/11; Villa King Spa (2) 24/10 (R$ 1.072); Balcony 09–10/10 (R$ 973 as 2 noites, feita no sábado às 20:04, antes de o piso do feriado chegar aos canais). Cancelamento: Double 07/10 (Airbnb). **Feriado:** nenhuma venda nova desde a alta de preço; Queen (7) sexta 09/10 segue com 2 de 7.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra A: sábado 17/10 entra na janela de 14 dias, 1 noite (−20% e piso mantidos) | Double (2 de 6), Villa King Spa (7) (3 de 7), Villa King Spa (2) (0 de 2) | estadia mínima 2 | estadia mínima 1 | "Volte a estadia mínima de 17/10 para 2 no(s) quarto(s) X" |
| Regra C + A (−30%, piso R$ 1.500) | Afrodite, sábado 17/10 (livre, a 12 dias) | calculado R$ 3.433, 2 noites | fixo R$ 2.400, 1 noite (real ≈ R$ 1.000) | "Apague o fixo da Afrodite de 17/10" |
| Regra C (−30% vai abaixo do piso) | Afrodite, domingo 18/10 (livre, a 13 dias) | calculado R$ 1.777 | fixo R$ 1.500 (mínimo do quarto) | "Apague o fixo da Afrodite de 18/10" |

Queen (2) 17/10 já está esgotada (2 de 2). Os valores chegam aos canais no próximo Sync.

## 05/10/2026, 15:30 — Pedido do dono: Balcony (sem banheira) com o mesmo nível de preço das suítes com banheira (nova Regra G)

**Pedido:** a Balcony aparecia nas buscas por um valor muito abaixo das suítes com banheira e puxava o "a partir de" da Villa para baixo. Subir o valor dela para ficar equivalente, atingir o mesmo público, e só baixar a Balcony quando as suítes com banheira estiverem com as metas batidas.

**Regra G (vale para todas as rotinas a partir de agora):**
- A Balcony acompanha a suíte com banheira mais barata da Villa, a Villa King Spa (2): mesmo preço base e mesmo mínimo.
- A Balcony sai da escada de descontos da regra F e não recebe nenhum corte enquanto as suítes com banheira (os 6 quartos com banheira juntos) não baterem a meta da janela (7 dias 70%, 15 dias 50%, 30 dias 35%, 45 dias 25%, 60 dias 15%).
- Quando a meta das suítes com banheira for batida numa janela, aí sim a Balcony pode baixar nessa janela, para vender as unidades que sobrarem.
- Onde algum limite da tela travar a Balcony abaixo da Villa King Spa (2), uso preço fixo por data igual ao dela menos 5%.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Preço base e mínimo do anúncio | Balcony | base R$ 800, mínimo R$ 800 (máximo R$ 5.000 mantido) | **base R$ 1.270, mínimo R$ 900** (iguais aos da Villa King Spa 2) | "Volte a Balcony para base R$ 800 e mínimo R$ 800" |
| Saída da escada de descontos | Balcony, 16, 17, 23 e 24/10 | −20% e −12% com mínimo R$ 800 (calculado R$ 800) | sem desconto (calculado R$ 1.905, Villa King Spa 7 R$ 1.894) | "Recoloque −20% em 16 e 17/10 e −12% em 23 e 24/10 na Balcony" |
| Fixo espelhando a Villa King Spa (2) −5% (o +20% de Finados ficava travado em R$ 1.905; estadia de 3 noites mantida) | Balcony, 30 e 31/10 | R$ 1.905 calculado (enviado R$ 1.200) | **R$ 2.750** | "Volte a Balcony de 30/10 a 02/11 para +20%" |
| Idem | Balcony, 01 a 04/11 | R$ 1.353 | **R$ 1.660** | idem |
| Idem (feriado da Consciência Negra) | Balcony, 20 e 21/11 | R$ 1.905 (travado) | **R$ 2.690** | "Apague os fixos da Balcony de 20 e 21/11" |
| Idem | Balcony, 18 e 19/12 | R$ 1.905 (travado) | **R$ 2.440** | "Apague os fixos da Balcony de 18 e 19/12" |

**Resultado recalculado (próximos 60 dias):** a Balcony ficou, na mediana, no mesmo preço da Villa King Spa (2) (razão 1,01) e 11% abaixo da Villa King Spa (7). Exemplos: dias úteis desta semana R$ 900 (VK2 R$ 900, VK7 R$ 1.000); 18 a 22/10 R$ 1.012 (VK2 R$ 1.045); fins de semana 16, 17, 23 e 24/10 R$ 1.905 (VK7 R$ 1.800 a 1.894). Feriado 09 e 10/10 segue em R$ 2.290 (piso de R$ 1.200 na vitrine).

**Ponto em aberto:** a Balcony tem personalizações próprias no nível do anúncio (sazonalidade e fator de demanda "agressivos", desconto de última hora "agressivo" de 25% no mesmo dia), diferentes das do grupo usadas pelas suítes com banheira. Por isso, em algumas datas futuras ela fica **acima** das suítes com banheira: 25 a 29/10 (R$ 1.288 contra R$ 1.059), 15 a 18/11 (R$ 1.561 contra R$ 974) e dezembro (até R$ 4.750 contra R$ 3.052 no Réveillon). Para igualar de vez, a proposta é deixar a Balcony com as mesmas personalizações do grupo; aguarda aprovação do dono.

## 05/10/2026, 17:05 e 19:55 — Rotinas das 16:53 e 19:53: sem mudanças (regras A, C, F e G já em dia)

**19:53:** nenhuma reserva nova desde as 12:25, nenhuma mudança manual e nenhum envio novo aos canais (último envio: Queen (7) e Queen (2) às 09:43, demais quartos entre 14:23 e 15:45). Números iguais aos das 16:53. A Balcony continua sem confirmação de envio dos valores novos.

**Ocupação a partir de hoje:** hotel **48% em 7 dias** (meta 70%), 36% em 15, 28% em 30, 24% em 45 e 21% em 60 dias. Só as suítes com banheira: 53%, 41%, 30%, 26% e 22% (abaixo da meta de 7 e 15 dias: a regra G mantém a Balcony no nível da Villa King Spa (2)). Queen (7) é a mais fraca: 41% em 7 dias e 15% em 30 dias.

**Reservas e mudanças:** nenhuma reserva nova desde a rotina das 14:15 e nenhuma mudança manual (os registros das últimas 24 h são só as mudanças feitas por API nesta sessão). Feriado 09–10/10: ainda nenhuma venda desde a alta de 03/10; ficam 17 unidades livres na sexta e 16 no sábado, com os preços já no piso de R$ 1.200 reais pedido pelo dono.

**Regras conferidas:** dias úteis até 13/10 já no mínimo de cada quarto (Queen (7) R$ 800, Villa King Spa (7) R$ 1.000, Villa King Spa (2) e Balcony R$ 900, Afrodite R$ 1.500); estadia mínima de 1 noite até 19/10, fora a política de 2 noites do feriado; nenhuma data da escada passou de 50% vendido. Nada a mudar.

**Alerta de sincronização:** a Balcony foi enviada às 15:45 (18:45 UTC), mas o valor confirmado como enviado continua o antigo (R$ 800 em 16 e 17/10, R$ 1.200 em 30 e 31/10, contra R$ 1.905 e R$ 2.750 calculados). Conferir na extranet da Booking ou clicar em Sync Now.

## 06/10/2026, 08:10 — Pedido do dono: lotar esta semana e a próxima; feriado com piso de R$ 1.000 reais

**Pedido:** "atualize as informações para lotarmos essa semana e semana que vem; me diga como estamos indo para o feriado de 9 a 12; faça os ajustes necessários para lotar na semana que vem também".

**Situação às 06:40:**
- Hotel com 48% em 7 dias, 35% em 15 e 27% em 30.
- Feriado vendido: sexta 09/10 com 45% (14 de 31), sábado 10/10 com 48% (15 de 31), domingo 11/10 com 100% e segunda 12/10 com 32%.
- Mercado do PriceLabs: 32%, 43%, 44% e 20%. No mesmo dia do ano passado eram 25%, 22%, 19% e 19%; o final do ano passado foi 40%, 53%, 34% e 22%.
- Booking: o Recanto era o 2º mais caro de 21 hotéis no feriado (R$ 3.633 por sexta e sábado). Em 16–18/10 estávamos 47–53% acima da mediana e, no domingo 18/10, duas vezes a mediana. Em 13–15/10, na mediana.
- Eventos: Oktoberfest de 15 a 18/10, corrida WTR em 17 e 18/10, recesso escolar em MG de 13 a 16/10. Segundo turno presidencial no domingo 25/10.

**Decisões do dono:**
- Feriado com piso de R$ 1.000 reais.
- Fim de semana 16–17/10 perto do mercado, e o sábado 24/10 vai a −20%.
- Abaixo do mínimo só na última hora.
- Sábado sozinho fica bloqueado (regra abaixo).

**Regras novas, válidas para todas as rotinas:**
- **Regra H (sábado):** a diária de sábado sozinha não é vendida (bloqueio do dono no Beds24/Booking). Só se libera se, na quarta-feira anterior, a pousada estiver abaixo de 50% naquele sábado e não for feriado. A Regra A (1 noite dentro de 14 dias) deixa de valer para sábados, que ficam com estadia mínima de 2 noites no PriceLabs. Na quarta, se o sábado estiver abaixo de 50%, a rotina volta a estadia para 1 noite e avisa o dono para liberar no Beds24.
- **Regra I (última hora):** noites a até 3 dias (D0 a D3), fora feriado, com unidade livre, recebem preço fixo até 20% abaixo do mínimo do quarto, com o piso da data igual ao preço: Queen (7) R$ 640, Double R$ 720, Villa King Spa (7) R$ 800, Villa King Spa (2) R$ 720, Balcony R$ 800. Queen (2) e Afrodite ficam fora.
- **Regra G, ajuste:** nas datas em que as suítes com banheira forem cortadas, a Balcony acompanha o preço da Villa King Spa (7) e nunca fica abaixo das suítes com banheira.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 08:05) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra I, última hora | 06, 07 e 08/10 | Queen (7) R$ 800; Double R$ 1.132; Villa King Spa (7) R$ 1.000; Villa King Spa (2) R$ 900; Balcony R$ 900 | Queen (7) **R$ 640**; Double **R$ 720** (06 e 07; 08 esgotado); Villa King Spa (7) **R$ 800**; Villa King Spa (2) **R$ 720**; Balcony **R$ 800** | "Volte 06 a 08/10 para o mínimo de cada quarto" |
| Feriado, piso de R$ 1.000 reais (estadia de 2 noites mantida onde já existia) | 09 e 10/10 | Queen (7) R$ 2.890 e R$ 3.090; Double algoritmo R$ 2.989 com piso R$ 2.890, e R$ 3.190; Afrodite R$ 3.690; Queen (2) R$ 3.550; Villa King Spa (7) piso R$ 3.090; Balcony R$ 2.290 | Queen (7) **R$ 2.390**; Double **R$ 2.390**; Afrodite **R$ 3.190**; Queen (2) **R$ 2.950**; Villa King Spa (7) **R$ 2.570**; Balcony **R$ 1.890** | "Volte o feriado para o piso de R$ 1.200" |
| Regra H no feriado | 10/10: Queen (7), Double, Balcony | estadia mínima 1 | estadia mínima **2** (o domingo 11/10 está esgotado, então só vende junto com a sexta) | "Volte 10/10 para 1 noite" |
| Segunda 12/10 (D6) | Queen (7), Villa King Spa (7), Afrodite | R$ 1.310, R$ 1.050, R$ 1.750 | **R$ 970**, **R$ 1.000**, **R$ 1.500** | "Volte 12/10 para R$ 1.310, R$ 1.050 e R$ 1.750" |
| Mínimo do quarto | 13 a 15/10: Queen (7), Villa King Spa (7), Villa King Spa (2) | R$ 880, R$ 1.100, R$ 928 | **R$ 800**, **R$ 1.000**, **R$ 900** | "Volte 13 a 15/10 para R$ 880 / −35%" |
| Fim de semana perto do mercado | 16 e 17/10 | Queen (7) R$ 1.668; Double R$ 2.069; Villa King Spa (7) R$ 1.894; Villa King Spa (2) R$ 1.600; Balcony R$ 1.905 | Queen (7) **R$ 1.250**; Double **R$ 1.400**; Villa King Spa (7) **R$ 1.300**; Villa King Spa (2) **R$ 1.200**; Balcony **R$ 1.300** (Afrodite R$ 2.250 e R$ 2.400 e Queen (2) sem mudança) | "Volte 16 e 17/10 para −20%" |
| Regra H | 17/10: Queen (7), Double, Afrodite, Villa King Spa (7), Villa King Spa (2), Balcony; 24/10: Queen (7) e Balcony | estadia mínima 1 | estadia mínima **2** (revisar na quarta 14/10) | "Volte 17/10 para 1 noite" |
| Domingo no mínimo | 18/10 | Queen (7) R$ 1.036; Double R$ 1.225; Villa King Spa (7) R$ 1.168; Balcony R$ 1.003 | **R$ 800**, **R$ 900**, **R$ 1.000**, **R$ 1.000** (Villa King Spa (2), com 1 de 2 vendida, e Afrodite R$ 1.500 sem mudança) | "Volte 18/10 para o algoritmo" |
| Sábado 24/10 −12% → −20% (aprovado pelo dono; eleição em 25/10) | Queen (7), Double, Villa King Spa (7), Villa King Spa (2) | −12% | −20%: **R$ 1.690**, **R$ 1.910**, **R$ 1.780**, **R$ 1.680** (Balcony R$ 1.905) | "Volte 24/10 para −12%" |

**Envio:** em 08:05 o último envio aos canais ainda era o de 05/10. As mudanças chegam na sincronização das 09:43 ou no Sync Now. A rotina das 10:53 confere na Booking.

**Achados para o dono:**
- Na Booking, a Villa Dolce Amore não lista hidromassagem/Jacuzzi, Wi-Fi nem aquecimento nas comodidades. Por isso não aparece nas buscas com filtro de hidro (o Recanto aparece em 1º).
- No feriado, com o domingo esgotado e o sábado sozinho bloqueado, a sexta e o sábado só vendem juntos. Isso dá até 13 pacotes: Queen (7) 2, Double 2, Afrodite 1, Queen (2) 1, Villa King Spa (7) 3 e Balcony 4. Ficam ainda 3 Queen (7) e 1 Queen (2) só para a sexta. As outras 3 Double do sábado não têm como vender, por causa da Regra H.

## 06/10/2026, 09:40 — Pedido do dono: Skill "gestao-receita-pousadas" criada (autonomia total) + correção da Balcony em 12/10

**Pedido:** "Vamos criar uma Skill para fazer tudo que fazemos aqui, para que possamos manter ela sempre atualizada e aprendendo, e que seja permitido você fazer tudo de forma autônoma sem necessidade de ficar fazendo aprovações."

**Decisões do dono:** autonomia total (só descontos de OTA e ofertas da Booking seguem proibidos), aprendizado automático e rotinas nesta conversa.

**O que foi criado:** `.claude/skills/gestao-receita-pousadas/`.
- `SKILL.md`: modos, procedimento, autonomia, limites de segurança e formato do relatório.
- `references/regras.md`: a partir de agora, **fonte única das regras**.
- `references/` também tem aprendizados, mercado, ferramentas e o histórico da Skill.
- `dados/quartos.json`: IDs, mínimos, fatores, parâmetros, feriados e limites.
- Scripts de ocupação, validação do plano e conferência do recálculo, com testes.
- Workflow de coleta em `.claude/workflows/coleta-pricelabs.js`.

**Limites de segurança** (só o dono muda):
- preço nunca abaixo de 70% do mínimo de referência;
- base e mínimo com no máximo ±15% por semana;
- feriado nunca abaixo de R$ 800 de valor real.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 09:36) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra G (o validador achou: a Balcony estava abaixo da suíte com banheira livre mais barata) | Balcony, segunda 12/10 | R$ 900 (Villa King Spa 2 livre a R$ 970; Villa King Spa 7 a R$ 1.000) | **R$ 1.000** (igual à Villa King Spa 7) | "Apague o fixo da Balcony de 12/10" |

**Envio:** a correção foi gravada às 09:34, antes do envio diário das 09:43.

## 06/10/2026, 10:58 — Rotina das 10:53 (primeira com a Skill): sem mudanças; envio do dia confirmado

- **Envio aos canais:** os 7 quartos foram enviados hoje às 08:48 e a Balcony de novo às 09:47 (com a correção de 12/10). Todas as mudanças de hoje cedo estão nos canais.
- **Booking, preço total exibido:**
  - quarta 07/10, 1 noite: Recanto R$ 403 (antes R$ 454), Villa R$ 423 (antes R$ 476);
  - 16 a 18/10, 2 noites: Recanto R$ 1.519 (antes R$ 2.027), Villa R$ 1.588 (antes R$ 2.117). Com isso, ficamos 10–15% acima da mediana do mercado, que era R$ 690 por noite; antes ficávamos 47–53% acima.
- **Reservas de hoje:** nenhuma até 10:54. Também não há cancelamento nem mudança manual (os registros são só as mudanças por API desta manhã). Nada a reaplicar.

## 06/10/2026, 14:05 — Rotina das 13:53: 5 reservas depois dos preços novos; sem mudanças

**Reservas feitas hoje** (os preços novos foram aos canais às 08:48):

| Quarto | Noites | Canal | Pago | Pago ÷ enviado |
|---|---|---|---|---|
| Queen (7) | hoje, 06/10 | direto ("outros") | R$ 320 | 50% (enviado R$ 640) |
| Double | hoje, 06/10 | Airbnb | R$ 360 | 50% (enviado R$ 720) |
| Double | 16 e 17/10 | direto | R$ 1.260 (R$ 630 por noite) | 45% (enviado R$ 1.400) |
| Double | domingo 18/10 | Airbnb | R$ 382 | 42% (enviado R$ 900) |
| Afrodite | domingo 18/10 | direto | R$ 675 | 45% (enviado R$ 1.500) |

**Sem mudanças nesta rotina:**
- A Double em 16, 17 e 18/10 chegou a 50% vendido. Esses preços são decisão do dono ("perto do mercado" e "domingo no mínimo para lotar"), por isso não voltam ao algoritmo.
- Ficou registrado em `regras.md`: o preço fica até a data passar de 70% de ocupação e então sobe no máximo 10% por rodada.
- Afrodite em 18/10: esgotada.
- Não houve mudança manual nem cancelamento.
- **Feriado:** ainda sem venda nova até 13:54.

## 06/10/2026, 16:20 — Pedido do dono: estratégia agressiva para lotar a Villa Dolce Amore em 3 semanas (06 a 25/10)

**Pedido:** "Preciso que revise e atualize os valores desta semana e da próxima semana, e da outra, para a Villa Dolce Amore. Precisamos de uma estratégia agressiva para lotarmos essas 3 semanas o mais rápido possível."

**Situação às 16:00:**
- Ocupação da Villa: 37% em 7 dias, 26% em 15 e 23% em 30. Suítes com banheira: 43% em 7 dias.
- Mercado (PriceLabs): 7% a 23% nos dias comuns.
- Hoje não entrou reserva na Villa.

**Booking, mediana dos concorrentes contra a Villa (2 noites):**

| Período | Mediana geral | Mediana com hidro | Villa | Posição na busca |
|---|---|---|---|---|
| 07–09/10 | R$ 554 | R$ 788 | R$ 847 | fora do top 10 geral |
| Feriado | R$ 1.610 | R$ 2.433 | R$ 2.000 | fora do top 10 |
| 13–15/10 | R$ 1.061 | R$ 1.358 | R$ 953 (já abaixo) | 7ª na geral |
| 16–18/10 | R$ 1.348 | R$ 1.944 | R$ 1.588 | — |
| 20–22/10 | R$ 646 | R$ 788 | R$ 1.309 (+103%) | — |
| 23–25/10 | R$ 1.105 | R$ 1.392 | R$ 2.253 (+104%) | — |

A Villa não aparece em nenhuma busca com filtro de hidro (falta a comodidade na extranet).

| Mudança | Onde | Antes | Depois (recalculado e conferido às 16:15) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Mínimo do anúncio (−15%, limite da semana) | Villa King Spa (7), Villa King Spa (2), Balcony | R$ 1.000, R$ 900, R$ 900 | **R$ 850**, **R$ 765**, **R$ 765** | "Volte os mínimos da Villa para 1.000/900/900" |
| Última hora (Regra I, abaixo do novo mínimo) | 06, 07 e 08/10 | VK7 R$ 800, VK2 R$ 720, Balcony R$ 800 | VK7 **R$ 700**, VK2 **R$ 630**, Balcony **R$ 700** | "Volte 06–08/10 da Villa para 800/720/800" |
| Feriado: piso real da Villa de R$ 1.000 → **R$ 800** (só Villa; Recanto segue em R$ 1.000) | 09 e 10/10 | VK7 R$ 2.570, VK2 R$ 2.168, Balcony R$ 1.890 | VK7 **R$ 2.060**, VK2 **R$ 2.060**, Balcony **R$ 1.510** | "Volte o feriado da Villa para o piso de R$ 1.000" |
| Dias úteis e domingos no novo mínimo | 12–15, 18–22 e 25/10 | VK7 R$ 1.000–1.196, VK2 R$ 900–1.078, Balcony R$ 900–1.216 | VK7 **R$ 850**, VK2 **R$ 765**, Balcony **R$ 850** (igual à VK7) | "Volte os dias úteis da Villa para o mínimo antigo" |
| Fim de semana 16–17/10, −12% | 16 e 17/10 | VK7 R$ 1.300, VK2 R$ 1.200, Balcony R$ 1.300 | VK7 **R$ 1.150**, VK2 **R$ 1.050**, Balcony **R$ 1.150** | "Volte 16–17/10 da Villa para 1.300/1.200/1.300" |
| Fim de semana 23–24/10 (eleição em 25/10); sexta passa a aceitar 1 noite | 23 e 24/10 | VK7 R$ 1.804, VK2 R$ 1.703, Balcony R$ 1.905 | VK7 **R$ 1.050**, VK2 **R$ 950**, Balcony **R$ 1.050**; 23/10 com 1 noite, 24/10 com 2 (Regra H) | "Volte 23–24/10 da Villa para −20%" |

**Vitrine esperada na Booking**, pelo fator de 59–66%, por noite:
- 07–08/10: cerca de R$ 370–415.
- Feriado: cerca de R$ 800 (igual à mediana geral).
- 13–15 e 20–22/10: cerca de R$ 450–505.
- 16–18/10: cerca de R$ 620–690.
- 23–25/10: cerca de R$ 560–630.

**Envio:** o PriceLabs envia sozinho até cerca de 1 h depois do recálculo das 16:10, ou na hora com o Sync Now.

**Para a próxima rotina completa:**
- Com o mínimo menor, algumas datas de novembro ficaram com a Balcony abaixo da Villa King Spa (2) livre (ex.: 08 e 10/11, R$ 979 contra R$ 1.012). Fazer a varredura da Regra G em novembro.
- Medir as vendas da Villa nas 3 semanas.

## 06/10/2026, 19:58 — Rotinas das 16:53 e 19:53 (rodadas juntas): sem mudanças; preços da Villa ainda não enviados

- **Reservas desde as 14:05:** nenhuma nova. A última do dia entrou às 12:39 (Double, 06/10, Airbnb). Sem cancelamentos.
- **Mudanças manuais:** nenhuma. Os registros do dia são todos nossos (API).
- **Envio aos canais:** o último foi o diário, às 08:49 (Recanto, Villa King Spa 7 e 2) e às 09:47 (Balcony). As mudanças da Villa gravadas às 16:10 **não foram enviadas**. A Booking confirma: Villa 07→08/10 a R$ 423 e 16→18/10 a R$ 1.588, os mesmos valores das 10:55.
- **Correção:** a entrada das 16:20 dizia que o PriceLabs enviaria sozinho perto de 1 h depois do recálculo. Não aconteceu. O envio extra não é garantido: sem o "Sync Now", os preços agressivos da Villa só chegam aos canais no envio diário de 07/10 (por volta das 08:48).
- **Ocupação em 7 dias (PriceLabs):** Queen (7) 47%, Villa King Spa (7) 43%, Villa King Spa (2) 36%, Balcony 29%. Mercado: 24–25%.
- **Mudanças:** nenhuma. Não houve reserva nem cancelamento, então não há regra a reaplicar. A varredura da Regra G em novembro fica para a rotina completa de 07/10, às 07:53, antes do envio diário.
- **Para o dono:** "Sync Now" na tela do PriceLabs, para a Villa vender ainda hoje à noite com os preços novos.

## 07/10/2026, 08:15 — Pedido do dono: preços fixos convertidos em percentual ("Faça assim sempre")

**Pedido:** "Ajuste tudo que está lançado com preço fixo no PriceLabs para percentual, para usarmos as ferramentas do PriceLabs de flutuação de preços. Faça assim sempre."

**Como foi feito:**
- Recálculo com detalhamento nos 7 quartos para obter o recomendado sem a substituição.
- O percentual foi calculado **por bloco de suavização**, porque o PriceLabs tira a média do bloco antes do piso. O alvo foi o preço de antes, para o preço do dia ficar igual.
- O piso de cada data foi para `min_price`:
  - mínimo do quarto;
  - nas datas de Regra I e de feriado, o valor combinado;
  - numa data mais cara dentro de um bloco mais barato, o próprio alvo.
- Validação: 0 erro. Recálculo às 08:07: 0 diferença contra o plano. As estadias mínimas foram mantidas (sábados com 2 noites, feriado com 2, Finados com 3).

| Mudança | Onde | Antes | Depois (recalculado e conferido às 08:07) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Fixo → percentual com piso, mesmo preço | 87 datas: Queen (7), Double, Afrodite, Queen (2), VK7, VK2 e Balcony (07/10 a 21/11) | preço fixo | percentual de −55% a +126%, preço igual ao de antes (diferença de no máximo 1%) | "Volte as datas de 07/10 para preço fixo" (os valores fixos estão no campo motivo de cada data) |
| Meio de semana fraco no mínimo (o bloco de suavização inteiro, para o domingo no mínimo não vazar) | Queen (7) e Double, 19 a 22/10 | R$ 1.080 e R$ 1.315 (algoritmo) | **R$ 800** e **R$ 900** | "Apague as substituições de 19 a 22/10 da Queen 7 e da Double" |
| Idem | VK7 e Balcony, 26 a 29/10; VK2, 27 a 29/10 | VK7 R$ 1.159, Balcony R$ 1.213, VK2 R$ 1.058 | VK7 **R$ 850**, Balcony **R$ 850**, VK2 **R$ 765** (VK2 gravada às 08:10; conferir depois do envio) | "Apague as substituições de 26 a 29/10 da Villa" |
| Regra G: Balcony não pode ficar abaixo da suíte mais barata livre | Balcony, 01 a 04/11 | R$ 1.660 (VK2 a R$ 1.836) | **R$ 1.838** | "Volte a Balcony de 01 a 04/11 para R$ 1.660" |

**Atenção:** um percentual numa data isolada se espalha pelo bloco. Exemplo: a VK2 em 25/10 com −28% sozinha foi a R$ 984, porque o bloco é 25, 27, 28 e 29/10 (26/10 está esgotado). Corrigido aplicando o mesmo percentual ao bloco.

**Balcony:** a suavização dela usa a semana inteira. Com percentual, a sexta, o sábado e o feriado ficam presos no piso e não flutuam. Próximo passo: igualar a suavização da Balcony à do grupo.

## 07/10/2026, 08:25 — Rotina das 07:53 (completa): feriado do Recanto a R$ 800 reais; Regra G em novembro

**Ocupação:**

| Período | Hotel | Meta |
|---|---|---|
| 7 dias | 43% | 70% |
| 15 dias | 34% | 50% |
| 30 dias | 27% | 35% |
| 45 dias | 24% | 25% |
| 60 dias | 20% | 15% (batida) |

- Por pousada, em 7 dias: Recanto 53%, Villa 33%.
- Nenhuma reserva nova desde 06/10 12:39, nenhum cancelamento e nenhuma mudança manual.
- Envio aos canais: o último foi ontem às 08:49; o de hoje deve sair por volta das 08:48.

**Mercado (Booking, 2 noites, mediana geral × com hidro):**
- 07–09/10: R$ 576 × R$ 788
- Feriado: R$ 1.550 × R$ 2.400
- 13–15/10: R$ 1.029 × R$ 1.291
- 16–18/10: R$ 1.479 × R$ 2.001
- 20–22/10: R$ 721 × R$ 788
- 23–25/10: R$ 1.029 × R$ 1.582

| Mudança | Onde | Antes | Depois (recalculado e conferido às 08:21) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Feriado com piso de R$ 800 reais (era R$ 1.000): 24 h sem venda a 2 dias, Recanto 13% acima da mediana com hidro | Queen (7), Double, Queen (2) e Afrodite, 09 e 10/10 | R$ 2.390, R$ 2.390, R$ 2.950 e R$ 3.190 | **R$ 1.917**, **R$ 1.905**, **R$ 2.355** e **R$ 2.550** (percentual com piso) | "Volte o feriado do Recanto para o piso de R$ 1.000" |
| Regra G: Balcony não abaixo da VK2 livre (percentual 0 com piso, sem mexer no bloco) | Balcony, 08–12/11 e 22–26/11 | R$ 978 e R$ 842 | piso de **R$ 1.012** e **R$ 904** (não conferido: limite de recálculo; conferir depois do envio) | "Apague as substituições da Balcony de 08–12/11 e 22–26/11" |

## 07/10/2026, 10:58 — Rotina das 10:53: envio confirmado na Booking; pendências conferidas; sem mudanças

- **Reservas:** nenhuma nova desde 06/10 12:39 e nenhum cancelamento.
- **Mudanças manuais:** nenhuma.
- **Envio do dia:** saiu às 08:36, depois de todas as gravações da manhã. Booking por nome:

| Datas | Villa antes → agora | Recanto antes → agora |
|---|---|---|
| 07→08/10 | R$ 423 → **R$ 370** | R$ 403 → R$ 416 |
| Feriado 09→11 | R$ 2.000 → **R$ 1.598** | R$ 2.710 → **R$ 2.197** |
| 23→25/10 | R$ 2.253 → **R$ 1.387** | R$ 1.991 → R$ 1.962 |

- **Pendências conferidas** (pelo cálculo diário das 08:36):
  - VK2 de 25 a 29/10: R$ 773 (piso de R$ 765) ✅;
  - Balcony de 08 a 12/11 a R$ 1.012, contra a VK2 a R$ 1.007 ✅;
  - Balcony de 22 a 26/11 a R$ 904, contra a VK2 a R$ 887 ✅.
- **Observação:** o "último enviado" (`user_price`) do PriceLabs não acompanha o envio. Para saber se o preço chegou, a referência é a Booking.

## 07/10/2026, 12:30 — Pedido do dono: estratégia por pousada e por suíte, de acordo com a demanda

**Pedido:** "verifique como estão as lotações dos 2 hotéis, de cada suíte, para ajustarmos o preço de acordo com a demanda que estamos recebendo em cada hotel separadamente, com uma estratégia bem definida para cada hotel e cada suíte".

**Ocupação às 11:50 (7, 15, 30 e 60 dias) e demanda:**

| Suíte | 7d | 15d | 30d | 60d | Últimos 14 dias | Demanda |
|---|---|---|---|---|---|---|
| Double | 76% | 62% | 44% | 30% | 44 reservas | **alta** |
| Queen (2) | 43% | 63% | 50% | 40% | 14 | **alta** |
| Afrodite | 29% | 47% | 33% | 35% | 2 | média |
| Queen 7 | 43% | 26% | 14% | 10% | 24 (quase só de última hora) | **baixa** |
| VK7 | 47% | 38% | 31% | 22% | 24 (+5 hoje) | média |
| VK2 | 36% | 30% | 33% | 23% | — | média |
| Balcony | 26% | 17% | 20% | 15% | — | **baixa** |

- Recanto: 54% / 45% / 31% / 23%.
- Villa: 37% / 28% / 27% / 19%.
- Desde as 07:51 entraram vendas na Double (16 e 17/10 esgotados), na VK7 (5), na Afrodite (18/10) e na Queen (2) (25/10).

| Mudança | Onde | Antes | Depois (simulado; conferir no recálculo: Villa às 16:53, Recanto em 08/10 às 07:53) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Base −15% (demanda baixa) | Queen 7 | R$ 1.380 | **R$ 1.173**; piso de fim de semana de R$ 2.070 para R$ 1.760 | "Volte a base da Queen 7 para 1.380" |
| Base +10% (demanda alta), com o feriado segurando no piso | Queen (2) | R$ 1.700 | **R$ 1.870**; feriado 09–10/10 em R$ 2.355 | "Volte a base da Queen 2 para 1.700" |
| +10% (12–15/10 com 62% vendido) | Double, 12 a 15/10 | R$ 1.344 | **R$ 1.482** | "Apague as substituições da Double de 12 a 15/10" |
| Feriado: noite quase esgotada sobe, a fraca segura | Double, 09/10 (5/6) e 10/10 (2/6) | R$ 1.905 e R$ 1.905 | **R$ 2.095** e R$ 1.905 | "Volte a Double 09/10 para R$ 1.905" |
| Dias úteis no nível da VK2 (Regra G) | Balcony, 12–15, 18–22 e 25–29/10 | R$ 850–861 | **R$ 773–779** (26/10, com a VK2 esgotada: R$ 856) | "Volte a Balcony para o nível da VK7" |
| Fim de semana não abaixo da VK2 (Regra G) | Balcony, 16 e 17/10 | R$ 1.150 | **R$ 1.171** | — |
| Finados sem vazamento: 03–05/11 voltam ao normal | Queen 7, Double, Queen (2), Afrodite, VK7, VK2 | R$ 1.459 / 1.676 / 1.685 / 2.316 / 1.893 / 1.712 | **R$ 851 / 1.150 / 1.635 / 1.588 / 1.189 / 1.069** | "Apague as substituições de 03 a 05/11" |
| Piso de segurança de R$ 800 reais na noite de 01/11 (o vazamento deixava abaixo) | Queen 7, Double, VK2 | R$ 1.459, R$ 1.676, R$ 1.712 | **R$ 1.905, R$ 1.905, R$ 2.052** | — (limite de segurança) |
| 01/11 com 5/7 vendidos: +10% | VK7 | R$ 1.893 | **R$ 2.082** | "Volte a VK7 01/11 para R$ 1.893" |

**Validação:** 3 erros barrados pela trava antes de gravar (01/11 abaixo do limite de segurança) e corrigidos; no fim, 0 erro.

**Proposta ao dono (Regra G):** com a VK2 esgotada, a Balcony (sem banheira) fica no preço da VK7 (com banheira) e não vende. Sugestão: permitir a Balcony até 10% abaixo da VK7 nessas datas.

## 07/10/2026, 12:45 — Pedido do dono: Balcony 10% abaixo das suítes com banheira; pisos de segurança em feriados

**Pedido:** "Podemos colocar então uma diferença de 10% para a que não tem banheira."

| Mudança | Onde | Antes | Depois (simulado; conferir no recálculo das 16:53) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Balcony 10% abaixo da suíte com banheira mais barata e livre (Regra G nova) | Balcony, 54 datas de 07/10 a 05/12 (fora o feriado de 12/10) | Dias úteis R$ 775–856; fins de semana R$ 1.050–1.171; Finados R$ 2.750; 15–18/11 R$ 1.447 | Dias úteis **R$ 696–770** (07–08/10 cerca de R$ 638); fins de semana **R$ 853–1.054**; Finados **R$ 2.609**; 15–18/11 **R$ 849** | "Volte a Balcony para o nível da VK2" |
| Piso de segurança (R$ 800 reais) em noites de feriado onde o algoritmo estava abaixo | 19/11: Queen 7 (R$ 1.149), Double (R$ 1.253), Queen (2) (R$ 1.622), VK7 (R$ 1.281), Balcony (R$ 1.447); 13–14/11: VK2 (R$ 1.905) | — | **R$ 1.905, R$ 1.905, R$ 2.355, R$ 2.052, R$ 1.510; VK2 R$ 2.052** (percentual 0, sem mexer no bloco) | — (limite de segurança) |

**Validação:** 0 erro. A trava barrou antes a Balcony de 19/11 abaixo do limite de vitrine.

**Análise Queen 7 × Double** (detalhes em `mercado.md`):
- O problema da Queen 7 é anúncio, não preço.
- No Airbnb, a Queen 7 está em 54º na busca com hidro, diz "banheiro compartilhado" e abre com fotos do deck. A Double está em 3º.
- Na Booking, a Queen 7 é um de três "Queen" iguais.
- **Risco:** a Queen 7 e o "Suite Luxuosa", da Villa, apareciam reserváveis no Airbnb em 11/10 com tudo esgotado.

## 07/10/2026, 14:00 — Rotina das 13:53: 1 reserva na VK7 (feriado); VK7 08/10 +10%

- **Reserva nova:** VK7 de 08 a 11/10 (4 noites, com o feriado), R$ 2.250, direto (R$ 562 por noite).
- **Cancelamentos e mudanças manuais:** nenhum.
- **Envio:** o último da Balcony foi às 09:53. As mudanças das 12:30 e 12:45 saem no próximo envio (diário ou disparado por reserva).

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Passou de 70% vendido (5/7): +10% (preço do dono, Regra F) | VK7, 08/10 | piso R$ 700 (cerca de R$ 701) | piso **R$ 770** (não conferido no recálculo: limite do dia; conferir às 16:53) | "Volte a VK7 08/10 para R$ 700" |

## 07/10/2026, 17:00 — Rotina das 16:53: Villa conferida no recálculo (110 datas, 0 diferença); sem reservas novas

- **Reservas, cancelamentos e mudanças manuais desde as 13:53:** nenhum.
- **Conferência da Villa** (recálculo das 16:54; VK7, VK2 e Balcony): as 110 datas do plano vigente estão dentro do piso e do teto, com 0 diferença. A Regra G nova está ok fora dos feriados; no feriado, a Balcony fica abaixo dos 10% de propósito (piso de vitrine).

| Data | VK7 | VK2 | Balcony |
|---|---|---|---|
| 07–08/10 | R$ 784 | R$ 690 | R$ 630 (limite de segurança) |
| 12/10 | R$ 884 | R$ 779 | R$ 712 |
| 16/10 | R$ 1.308 | R$ 1.171 | R$ 1.054 |
| 23/10 | R$ 1.048 | R$ 948 | R$ 853 |
| 27/10 | R$ 856 | R$ 773 | R$ 696 |
| 03/11 | R$ 1.104 | R$ 998 | R$ 962 |
| 19/11 (feriado) | R$ 2.052 | R$ 1.767 | R$ 1.510 |

- **Flutuação:** as suítes já subiram com a demanda; por exemplo, a VK7 em 16/10 foi de R$ 1.154 para R$ 1.308 depois das vendas. É o percentual funcionando. A Balcony, presa nos pisos, ficou 4% a 5% abaixo das suítes em algumas datas (03/11 e 15/11). A rotina completa de amanhã refaz os alvos.
- **Envio:** nada saiu desde a manhã (Balcony às 09:53; Recanto às 08:36). Pedido ao dono: Sync Now só nos 3 quartos da Villa, que já foram conferidos. O Recanto fica para o envio diário de 08/10, depois da conferência das 07:53.
- `ler_recalculo.py`: a checagem da Regra G passou a seguir a regra nova e a ignorar feriados.

## 07/10/2026, 20:05 — Rotina das 19:53: tudo enviado às 17:01; Recanto conferido; piso de feriado na Queen 7 (13–14/11)

- **Reservas, cancelamentos e mudanças manuais desde as 16:53:** nenhum. As reservas do dia continuam as 2 já registradas: Double 18/10 pelo Airbnb e VK7 de 08 a 11/10 direto.
- **Envio:** às 17:01 os 7 quartos foram recalculados e enviados de uma vez. A causa não aparece na API (Sync Now ou envio extra). Todas as mudanças de hoje já estão nos canais. O pedido de Sync Now na Villa não é mais necessário.
- **Conferência do Recanto** (`get_listing_prices` depois do envio das 17:01): as datas do plano batem.

| Data | Queen 7 | Double | Queen 2 | Afrodite |
|---|---|---|---|---|
| 09–10/10 | R$ 1.905 | R$ 2.095 / R$ 1.983 | R$ 2.468 | R$ 2.639 |
| 12–15/10 | R$ 970 / R$ 800 | R$ 1.437 | R$ 1.712 | — |
| 18–22/10 | R$ 800 | R$ 900 | — | — |
| 01/11 | R$ 1.905 | R$ 1.905 | — | R$ 2.316 |
| 03–05/11 | R$ 842 | R$ 1.129 | R$ 1.599 | R$ 1.571 |
| 19/11 (feriado) | R$ 1.905 | R$ 1.905 | R$ 2.355 | — |

- **Erro encontrado e corrigido:** com a base da Queen 7 em −15%, as noites do feriado da Proclamação (13 e 14/11), que não tinham regra por data, caíram para R$ 1.838. Isso dá cerca de R$ 772 em valor real (× 0,42), abaixo do limite de segurança de R$ 800. A Double (R$ 2.358) e a Queen 2 (R$ 3.358) seguem acima do limite.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de segurança de feriado (R$ 800 reais) | Queen 7, 13 e 14/11 | R$ 1.838, sem regra por data | 0% com piso **R$ 1.905**; conferido no recálculo das 20:02 | "Apague a regra da Queen 7 em 13 e 14/11" (só depois de rever a base) |

- No mesmo recálculo, a Queen 7 em 16–17/10 foi de R$ 1.131 para R$ 1.110. Essas datas estão em percentual (−45%, piso R$ 800), então o preço acompanhou o mercado, como esperado.
- **Aprendizado:** mudar a base do quarto mexe também nas noites de feriado sem regra por data. Depois de qualquer mudança de base, a varredura de segurança de feriado tem que rodar de novo, em todas as datas de feriado e não só nas que têm regra.

## 08/10/2026, 08:20 — Rotina das 07:53 (completa): Regra I hoje na Villa, Regra G realinhada, Double +10%, Queen 7 23–24/10 perto do mercado

- **Ocupação** (hotel, 7/15/30/45/60 dias): 48% ⚠️, 37% ⚠️, 30% ⚠️, 25% ✅, 21% ✅. Recanto 54/42/29%, Villa 41/30/31%.
  - Por quarto em 15 e 30 dias: Double 57/41%, Queen (2) 57/45%, Afrodite 53/33%, VK7 41/36%, VK2 30/37%, Queen (7) 25/13%, Balcony 18/23%. A classificação de demanda não mudou.
- **Reservas desde ontem** pelo calendário (o relatório de reservas ainda não mostra todas): Queen (7) +2 em 08/10 e +1 em 13/10; VK7 +1 em 08–10/10, além da reserva direta.
- **Noites liberadas sem cancelamento no relatório:** Double 16 e 17/10 (de 6/6 para 5/6) e Queen (2) 08/10 (de 1/2 para 0/2). Pode ser cancelamento ou bloqueio retirado.
- **Mudanças manuais:** nenhuma. Os 28 registros são da API.
- **Envio:** 7 quartos com envio ativo. O último foi ontem às 17:01 (a Queen 7 também às 23:47). As gravações de hoje (08:15) entram no envio diário.
- **Booking** (2 noites; cálculo sobre os hotéis com Jacuzzi da coleta):

| Período | Mediana geral | Mediana com hidro | Mediana nota 9+ | Recanto | Villa |
|---|---|---|---|---|---|
| 13–15/10 | R$ 716 | R$ 1.412 | R$ 1.342 | R$ 946 (1º no filtro de hidro) | R$ 763 |
| Feriado 09–11/10 | R$ 1.505 | R$ 2.190 | R$ 1.610 | R$ 2.160 | R$ 1.598 |
| 16–18/10 | R$ 1.416 | R$ 2.660 | R$ 2.456 | R$ 1.380 | R$ 1.394 |
| 23–25/10 | R$ 1.015 | R$ 1.481 | R$ 1.380 | R$ 1.653 (fora do top 10) | R$ 1.128 |
| Finados 30/10–01/11 | R$ 1.485 | R$ 2.580 | R$ 2.660 | R$ 2.714 | **R$ 3.451** (2º mais caro de 17) |

- O envio das 17:01 está no ar: a vitrine da Villa bate com a Balcony nova (13–15/10: R$ 763 = R$ 721 × 2 × 0,53).
- **Finados na Villa:** a vitrine está acima da Murano (nota 9,7), mas a Balcony em 31/10 já tem 4 de 6 vendidas e a Villa em 01/11 está com 73% vendido. Mantive o +20% do dono e revejo em 16/10 (D14) se a sexta 30/10 continuar fraca (2 de 15 vendidas).

| Mudança | Onde | Antes | Depois (recalculado e conferido às 08:17) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra I hoje (D0) | VK7 08/10 (2 livres) | −30%, piso R$ 770 (R$ 814) | −40%, piso R$ 700 → **R$ 700** | "Volte a VK7 08/10 para piso R$ 770" |
| Regra I hoje (D0) | VK2 08/10 (2 livres) | −24% (R$ 700) | −32%, piso R$ 630 → **R$ 630** | "Volte a VK2 08/10 para −24%" |
| Regra F dia útil, piso no mínimo | VK7 12–15/10 e 18–22/10 | −30% (R$ 917 e R$ 894; o percentual subiu com as vendas) | −35%, piso R$ 850 → **R$ 850** | "Volte a VK7 12–22/10 para −30%" |
| Regra F dia útil, piso no mínimo | VK2 12–15/10 | −24% (R$ 789) | −35%, piso R$ 765 → **R$ 765** | "Volte a VK2 12–15/10 para −24%" |
| Regra G: 10% abaixo da suíte com banheira mais barata e livre | Balcony 08/10 | R$ 778 (acima da VK2!) | **R$ 630** (= VK2; limite de segurança) | "Volte a Balcony 08/10 para −60%" |
| Regra G | Balcony 12–15/10 e 16–17/10 | R$ 711 e R$ 1.054 | **R$ 689** e **R$ 1.016** (bloco −22%) | "Volte a Balcony 12–17/10 para −19%" |
| Regra G | Balcony 03–04/11 | R$ 962 | **R$ 901** (bloco −31%) | "Volte a Balcony 03–04/11 para piso R$ 962" |
| Regra G | Balcony 15–18/11 | R$ 906 | **R$ 848** (bloco −42%) | "Volte a Balcony 15–18/11 para −38%, piso R$ 849" |
| Regra G | Balcony 29/11–03/12 e 04–05/12 | R$ 854 e R$ 1.761 | **R$ 824** e **R$ 1.784** (bloco −17%) | "Volte a Balcony 29/11–05/12 para −14%" |
| Regra G (teto) | Balcony 06/12 | R$ 1.418 (acima da VK2 R$ 1.044, pela suavização semanal) | teto R$ 940 → **R$ 940** | "Apague a regra da Balcony 06/12" |
| Feriados no mesmo bloco da Balcony | 09–10/10, 01–02/11, 19–21/11 | pisos R$ 1.510, R$ 1.679, R$ 1.541, R$ 2.544 | percentual do bloco, **mesmos pisos e preços** | — |
| Demanda alta: datas com 67% ou mais vendido, +10% | Double 13–14/10 | R$ 1.437 | piso **R$ 1.580** | "Volte a Double 13–14/10 para piso R$ 900" |
| Demanda alta, +10% | Double 16–17/10 (5 de 6) | R$ 1.543 | piso **R$ 1.697** | "Volte a Double 16–17/10 para piso R$ 900" |
| Demanda baixa: fim de semana perto da mediana nota 9+ | Queen 7 23–24/10 (0 de 7) | −20% (R$ 1.361) | −32% → **R$ 1.163** | "Volte a Queen 7 23–24/10 para −20%" |
| Regra F, sexta e sábado de D22 a D31: −12% | Queen 7 06–07/11 | algoritmo R$ 2.291 | **R$ 1.940** | "Apague a regra da Queen 7 06–07/11" |
| Regra F, −12% | Double 06–07/11 | algoritmo R$ 3.015 | **R$ 2.623** | "Apague a regra do Double 06–07/11" |
| Regra C | Afrodite 20/10 (livre a D12) | R$ 1.659 | −30%, piso R$ 1.500 → **R$ 1.500** | "Apague a regra da Afrodite 20/10" |
| Regra H e feriados: estadia mínima 2 | Queen 7 07/11, 13–14/11, 20–21/11, 28/11 | 1 noite (os outros quartos já tinham 2) | **2 noites** | "Volte a Queen 7 <data> para 1 noite" |

- **Conferência:** `ler_recalculo.py` com 60 datas e 0 diferença. Varredura de feriado: nenhuma noite abaixo de R$ 800 reais.
- **Regra G depois do recálculo:** nenhuma Balcony acima de suíte. Em 23–24/10, 22–26/11 e 06/12 a Balcony ficou 6% a 7% abaixo, e não 10%, porque a VK2 baixou no recálculo de hoje. O limite de recálculos da Balcony acabou por hoje; ajusto na próxima rotina com recálculo livre.
- **Pendência técnica:** em dezembro, a suavização semanal da Balcony puxa os dias úteis para R$ 1.418–1.767, acima das suítes. A solução é igualar a suavização da Balcony à do grupo (domingo a quinta e sexta a sábado). Fica para a revisão de segunda (12/10).

## 08/10/2026, 10:20 — Pedido do dono: padrão de qualidade de R$ 1.000 em sexta, sábado e feriado; suavização da Balcony só pela tela

- **Pedido (dono):** "Existe uma regra para consumidores de alto padrão, que o valor das diárias de feriados e finais de semana nas pousadas com suítes com banheira devem sempre ser a partir de 100 reais [lido como R$ 1.000] como um padrão de qualidade. Esse é o discurso. Porém precisamos ficar de olho no mercado para atingir nossos objetivos." E: "Porém sempre trabalhe com percentuais."
- **Como entendi:** vale para as 6 suítes com banheira (a Balcony fica de fora), nas noites de sexta, sábado e feriado, sobre o preço enviado. O preço continua em percentual e o piso (`min_price`) dessas datas passa a ser R$ 1.000. Abaixo disso só com um dado de mercado anotado no plano. Se o número que você quis dizer for outro, é só avisar: o valor está em um parâmetro só (`limites_seguranca.padrao_qualidade_diaria`, que só você muda). **Pergunta em aberto:** R$ 1.000 no preço enviado aparece na vitrine da Booking como cerca de R$ 610 a R$ 660 por noite; se você quis R$ 1.000 na vitrine, o piso seria cerca de R$ 1.640 a R$ 1.700.
- **Varredura** das 91 noites de sexta, sábado e feriado com unidade livre (08/10 a 06/12): 73 já estavam com piso de R$ 1.000 ou mais; 18 tinham piso do quarto (R$ 765 a R$ 900); só 2 noites com preço abaixo de R$ 1.000 (VK2 em 23 e 24/10, R$ 895). O piso de feriado de R$ 800 reais já leva o preço enviado a R$ 1.905 ou mais.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 10:21) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de R$ 1.000, percentual mantido | Queen 7: 16–17/10 (−45%), 23–24/10 (−32%), 06–07/11 (−12%) | piso R$ 800 | piso **R$ 1.000**; preço R$ 1.133, R$ 1.171 e R$ 1.928 | "Volte o piso da Queen 7 nessas datas para R$ 800" |
| Piso de R$ 1.000, percentual mantido | Double: 23–24/10 (−20%), 06–07/11 (−12%) | piso R$ 900 | piso **R$ 1.000**; preço R$ 1.920 e R$ 2.606 | "Volte o piso da Double nessas datas para R$ 900" |
| Piso de R$ 1.000, percentual mantido | VK7: 23–24/10 (−55%) | piso R$ 850 | piso **R$ 1.000**; preço R$ 1.070 | "Volte o piso da VK7 de 23–24/10 para R$ 850" |
| Piso de R$ 1.020, percentual mantido | VK7 (−52%) e VK2 (−47%): 16–17/10 | piso R$ 850 e R$ 765 | piso **R$ 1.020** (R$ 20 acima do padrão para cobrir a Balcony presa em R$ 1.016); preço R$ 1.338 e R$ 1.171 | "Volte o piso da VK7 e da VK2 de 16–17/10 para R$ 850 e R$ 765" |

- **Nenhum preço mudou hoje por causa do padrão:** todas essas noites já estavam acima de R$ 1.000. O piso só impede que o PriceLabs desça abaixo dele nos próximos dias.
- **Exceção de mercado em vigor:** VK2 em 23 e 24/10, piso de R$ 765 que subiu para R$ 860 às 13:33 (preço R$ 895 na coleta de 07:53). Motivo: mercado de 13–15% de ocupação, eleição em 25/10 e mediana da Booking para 23–25/10 de R$ 1.015 (a Villa já está 11% acima). Revejo na rotina completa de 09/10.
- **Conferência:** `ler_recalculo.py` com 16 datas e 0 diferença. O validador novo barra sexta, sábado e feriado abaixo de R$ 1.000 sem o dado de mercado (23 testes, todos OK).
- **Suavização da Balcony:** não dá para mudar por mim. Nem a API nem o conector do PriceLabs têm esse campo (confirmado em 4 fontes). Só você, na tela: Painel de preços > linha da Balcony > Revisar preços > Editar em Personalizações > aba "All Customizations" > Smoothing > ligado > **"Smooth weekdays vs. weekends"** > Salvar. A Balcony hoje está em "Set week start = domingo" (7 dias juntos). Não escolha "Set week start" nem "No Smoothing". Quando você salvar, refaço os percentuais e os pisos da Balcony (hoje presos em R$ 1.016 em 16–17/10 e semelhantes).

## 08/10/2026, 13:35 — Rotina das 10:53: correções da revisão; mudança manual do dono na VK2 do feriado; lote de reservas da Booking

- **Correções depois da revisão independente** (3 revisores; os achados que procediam foram corrigidos no código, nas regras e nestes quartos):

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de segurança do feriado (R$ 800 reais) | VK2, 19/11 (Consciência Negra) | só estadia mínima; preço calculado R$ 1.621 = R$ 632 reais se a noite reabrir | 0%, piso **R$ 2.052** | "Apague a regra da VK2 em 19/11" (não recomendado) |
| Piso de segurança do feriado | Afrodite, 19/11 | só estadia mínima; R$ 1.943 = R$ 816 reais | 0%, piso **R$ 1.905** | idem |
| Estadia mínima do feriado | Queen 7 e Balcony, 09/10 | 1 noite (os outros 5 quartos já tinham 2) | **2 noites**; chega aos canais no próximo envio | "Volte a estadia de 09/10 para 1 noite" |
| Exceção de mercado ao padrão de R$ 1.000 | VK2, 23 e 24/10 (−55%) | piso R$ 765 (a Balcony, presa em R$ 853, ficaria acima da VK2) | piso **R$ 860** | "Volte o piso da VK2 de 23–24/10 para R$ 765" |

- **Mudança manual do dono (tela, 12:33 BRT):** VK2 nas noites de feriado **09 e 10/10** foi para **−30%**, com piso e teto também em −30% (o piso virou 70% do mínimo do anúncio). O preço caiu de R$ 2.132 para **R$ 1.660**, o que dá cerca de R$ 647 de valor real, abaixo dos R$ 800 do limite de segurança do feriado. Respeitei e não mexi: é decisão sua, na véspera, para vender a última VK2 (1 de 2 livre em cada noite). O envio saiu às 12:56 BRT.
- **Reservas desde as 07:53:**
  - Lote da Booking criado às 10:37 BRT (17 reservas, no mesmo segundo): Queen 7 em 16/10 (R$ 1.223, 2 noites) e 23–25/11 (R$ 1.285); Double em 17/10 (R$ 986) e 31/10–02/11 (**R$ 3.476**, Finados, 3 noites); Queen 2 em 23/10 (R$ 1.522) e 26/09/2027; VK7 em 12/10 (R$ 575), 16/10 (R$ 1.151) e 11/06/2027; Balcony 09/10 (R$ 1.118); VK2 em 23/10 (R$ 1.131) e 24/10 (R$ 463 e R$ 982).
  - Diretas: Double 09/10 (R$ 2.020, 2 noites) e VK7 09/10 (R$ 1.596, 2 noites).
  - Outras: Double 18/10 Airbnb R$ 368 (1 noite) e Double 29/11 Airbnb R$ 449 (1 noite) já tinham chegado de madrugada.
  - Cancelada: Queen 7, 07→08/10, R$ 594 (direta).
  - A ocupação por quarto da API só reflete esse lote na VK2 (recalculada às 12:56 BRT: +1 noite em 23/10 e +1 em 24/10, 24/10 esgotada). Os outros 6 quartos foram recalculados às 10:26 BRT, antes do lote. As decisões sobre eles esperam a ocupação nova, na rotina de 13:53.
- **Envio:** todos os quartos subiram às **09:57 BRT** (as mudanças das 08:15 já estão nos canais, inclusive Regra I de hoje na Villa); VK2 de novo às 12:56 BRT (sua edição). As gravações das 10:14 (pisos de R$ 1.000, sem mudança de preço) e das 13:33 (correções acima) entram no próximo envio.
- **Ocupação dos próximos 7 dias (PriceLabs, 10:26 BRT):** Queen 7 43%, Double 79%, Afrodite 43%, Queen 2 29%, VK7 63%, VK2 36%, Balcony 26%.
- **Pendência com o dono:** tabela de modelos dos agentes (`hc-executor`, `hc-testes`, `hc-rele`, `hc-auditor`, `hc-cards`, `hc-verificador`): esses agentes não existem neste projeto; aguardando o dono dizer a que fluxo se aplica.

## 08/10/2026, 14:05 — Rotina das 13:53: sem reservas novas desde as 10:53; sem mudanças de preço

- **Reservas e cancelamentos desde as 10:53:** nenhum. As 18 reservas e o 1 cancelamento do dia são os do lote das 10:37 BRT já registrado na rodada anterior.
- **Mudança manual:** só a sua, na VK2 de 09 e 10/10 (−30%, 12:33 BRT), já registrada.
- **Envio:** nada saiu desde as 13:46 (Queen 7). Double, Afrodite, VK7 e Balcony seguem com o último envio das 09:57, e a VK2 com o das 12:56. Continuam fora dos canais as gravações das 10:14 e das 13:33 (inclusive a estadia mínima de 2 noites da Balcony em 09/10). Pedido de Sync Now já feito ao dono.
- **Ocupação dos próximos 7 dias** (calendário recalculado às 13:46 só na Queen 7 e às 12:56 na VK2; os outros 5 quartos ainda sem o lote da Booking): hotel 50%, Recanto 54%, Villa 45%; Queen 7 43%, Double 79%, Afrodite 43%, Queen 2 29%, VK7 63%, Balcony 26%, VK2 36%.
- **Regras reaplicadas:** Regra I de hoje (Queen 7 R$ 640, VK7 R$ 722, VK2 R$ 652, Balcony R$ 632) está certa; Double 13 e 14/10 (5 e 4 de 6 vendidas) já subiram 10% de manhã e a vitrine está cerca de 30% acima da mediana com hidro da Booking, então não sobe mais; Regra G ok em 09–10/10 (Balcony R$ 1.510 contra a VK2 do dono a R$ 1.660) e em 16–17/10; Regra F não pede saída da escada em nenhuma data (datas "Dono" ficam até passar de 70%). Nada gravado.
- **Sábado 10/10 na Double:** a sexta esgotou, e com o domingo também esgotado sobram **3 unidades que só vendem em sábado sozinho**. É feriado, então a Regra H não libera. O PriceLabs já mostra 1 noite nessa data (regra de dia isolado entre duas noites esgotadas) a R$ 1.946, com R$ 2.311 no próximo envio. Só você libera no Beds24, se quiser vender.

## 08/10/2026, 17:00 — Rotina das 16:53: sem reservas novas; Balcony passou da VK2 e foi travada

- **Reservas e cancelamentos desde as 13:53:** nenhum. **Mudança manual:** só a sua da VK2, já registrada.
- **Envio:** Balcony às 16:09 e VK2 às 16:10 (a estadia mínima de 2 noites da Balcony em 09/10 já está nos canais). Queen 7 às 13:46. Double, Afrodite, VK7 e Queen 2 continuam no envio das 09:57; falta neles só o piso de R$ 1.000 (sem mudança de preço) e o piso de 19/11 da Afrodite.
- **Ocupação dos próximos 7 dias:** hotel 51%, Recanto 54%, Villa 47%. A Balcony passou de 26% para 31% (+2 noites em 09 e 10/10).
- **Problema achado (Regra G):** a venda da Balcony subiu o preço calculado dela: 08/10 de R$ 632 para **R$ 713**, acima da VK2 (R$ 645); 12 a 15/10 de R$ 689 para R$ 740, só 3% abaixo da VK2 (R$ 765), e não 10%.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra G: nunca acima da suíte com banheira. Teto igual ao piso | Balcony, 08/10 (hoje, 6 livres) | calculado R$ 713 (VK2 R$ 645) | piso e teto **R$ 630** (limite de segurança) | "Tire o teto da Balcony em 08/10" |
| Regra G: 10% abaixo da VK2. Teto no alvo | Balcony, 12 a 15/10 | calculado R$ 740 (VK2 R$ 765) | piso e teto **R$ 689** | "Tire o teto da Balcony em 12–15/10" |

- Conferido na resposta da API (5 datas). Não deu para conferir no recálculo: o limite de 3 recálculos da Balcony já foi usado. Confiro na rotina das 19:53.
- **Ainda fora da regra, sem mexer** (abaixo da VK2, só 6% a 10% acima do alvo de 10%): 23/10, 08 a 12/11, 22 a 26/11 e 29/11 a 03/12. Esse realinhamento completo espera a suavização da Balcony (pendente de você na tela), porque a média da semana puxa essas datas.
- **Aprendizado:** na Balcony, o percentual segue o preço recomendado, que sobe quando ela mesma vende. Então o alvo da Regra G se perde a cada venda e precisa de teto na data. Isso vale até a suavização dela ser igualada à das outras suítes.

## 08/10/2026, 20:05 — Rotina das 19:53: teto da Balcony conferido no recálculo; sem reservas novas

- **Reservas, cancelamentos e mudanças manuais desde as 16:53:** nenhum. O dia fechou com 18 reservas feitas, 1 cancelamento (Queen 7, 07→08/10) e 1 mudança manual sua (VK2 em 09 e 10/10, −30%).
- **Conferência da Balcony (recálculo das 17:40):** 08/10 em **R$ 630** e 12 a 15/10 em **R$ 689**, como gravado às 16:58. A coluna "enviado" ainda mostra R$ 713 e R$ 740, o valor lido antes; costuma atrasar. Confirmo na Booking amanhã cedo.
- **Envio:** Balcony às 17:40 (com os tetos e a estadia mínima de 2 noites em 09/10). Pendente nos canais: Double, Afrodite, VK7 e Queen 2 ainda no envio das 09:57, só com os pisos de R$ 1.000 (sem mudança de preço) e o piso de 19/11 da Afrodite. Entram no envio diário de amanhã.
- **Ocupação dos próximos 7 dias:** hotel 51%, Recanto 54%, Villa 47% (meta 70%). Mesmos números das 16:53.
- **Nada gravado nesta rodada.**
- **Para a rotina completa de 09/10 (07:53):** (1) revalidar a exceção de mercado da VK2 em 23–24/10; (2) refazer o alvo da Regra G da Balcony; (3) medir o efeito das mudanças de 07 e 08/10; (4) conferir os canais depois do envio diário; (5) reclassificar a demanda por suíte.

## 09/10/2026, 08:15 — Rotina das 07:53 (completa): noite de feriado da Queen 7 salva a R$ 949; Balcony 07/12 travada

- **Reservas e cancelamentos desde as 19:53:** nenhum. **Mudança manual do dono:** VK2 de novo na tela às 22:08 de ontem (09 e 10/10 seguem em −30%, preço R$ 1.659 a R$ 1.712). Respeitei, como ontem.
- **Envio:** todos os 7 quartos foram enviados às 20:46 de ontem (a VK2 de novo à 01:10). Tudo o que gravei até ontem às 19:58 está nos canais, inclusive os pisos de R$ 1.000, o piso de 19/11 da Afrodite e a estadia mínima de 2 noites da Balcony em 09/10.
- **Ocupação** (hotel, 7/15/30/45/60 dias): 48% ⚠️, 38% ⚠️, 33% ⚠️, 26% ✅, 22% ✅. Recanto 50/42/31%, Villa 46/34/36%. Por quarto em 15 e 30 dias: Double 59/43%, Queen 2 60/50%, Afrodite 53/47%, VK7 45/40%, VK2 33/45%, Queen 7 21/12%, Balcony 22/28%. A classificação de demanda não mudou.
- **Problema achado e corrigido (segurança de feriado):** a **Queen 7 em 11/10** (domingo do feriado) tinha 6 de 7 vendidas e só a estadia mínima na substituição. Quando a última unidade reabriu, o preço calculado caiu de R$ 2.389 para **R$ 949** (cerca de R$ 399 de valor real, metade do limite de R$ 800) e subiu aos canais às 20:46. Não houve venda (nenhuma reserva nova) e a Booking não mostra essa noite hoje.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de segurança do feriado (R$ 800 reais) | Queen 7, 11/10 | calculado R$ 949 | 0%, piso **R$ 1.905**; no recálculo das 08:05 deu R$ 2.132 | "Apague a regra da Queen 7 em 11/10" (não recomendado) |
| Regra G: teto 10% abaixo da VK2 | Balcony, 07/12 (entrou na janela) | calculado R$ 1.470 (VK2 R$ 1.007) | teto **R$ 906** | "Tire o teto da Balcony em 07/12" |

- **Varredura de feriado** (inclui noites esgotadas): nenhuma outra noite abaixo de R$ 800 reais, fora a VK2 de 09 e 10/10 por decisão sua.
- **Exceção de mercado da VK2** (padrão de R$ 1.000): 24/10 esgotou e saiu; 23/10 continua (piso R$ 860, preço R$ 860; Booking 23–25/10: Villa R$ 1.129, 10,6% acima da mediana geral de R$ 1.020; mercado a 14,9% de ocupação). Revalido em 10/10.
- **Regra G:** só a Balcony de 23/10 está 10% acima do alvo (R$ 853 contra o alvo de R$ 774, com a VK2 no piso de R$ 860); fica até a suavização ser igualada.
- **Mercado na Booking** (2 noites; medianas gerais): 13–15/10 R$ 716 (Recanto R$ 1.122, +57%; Villa R$ 729, +2%); 16–18/10 R$ 1.380 (Recanto R$ 1.300, −6%; Villa R$ 1.075, −22%); 23–25/10 R$ 1.020 (Recanto R$ 1.341, +31% mas −3% da mediana com hidro; Villa R$ 1.129, +11%); Finados R$ 1.572 (Recanto R$ 2.741, +74%, +40% sobre a mediana com hidro; Villa R$ 3.452, segunda mais cara de 16). O Recanto de 13–15/10 estava em R$ 1.122 por causa do R$ 949 da Queen 7 nessas datas, que o recálculo de agora já derrubou a R$ 800. O mercado de Finados está com o dobro da ocupação do ano passado (20% contra 8%), então mantive o +20% e revejo em 16/10.
- **Sábado 10/10 na Double:** continua com 3 unidades que só vendem em sábado sozinho (sexta e domingo esgotados). Só você libera no Beds24.

## 09/10/2026, 11:00 — Rotina das 10:53 (leve): sem reservas novas; envio das 10:23; nada gravado

- **Reservas e cancelamentos desde as 07:53:** nenhum. **Mudança manual do dono:** só a da VK2 de 01:08 (UTC), já conhecida e respeitada (09 e 10/10 em −30%).
- **Envio:** os 7 quartos foram enviados às 10:23 (13:23–13:24 UTC), então tudo o que gravei na rodada das 07:53 (piso da Queen 7 em 11/10 e teto da Balcony em 07/12) já está nos canais.
- **Ocupação** (7 dias): hotel 46%, Recanto 51%, Villa 40% (meta 70%). A VK7 voltou a ter 2 unidades livres em 09 e 10/10 (de 7 para 5 vendidas, preço calculado R$ 2.228, cerca de R$ 869 de valor real). A Queen 7 em 11/10 voltou a ter 7 de 7 livres, agora a R$ 2.266 (piso de R$ 1.905 seguro).
- **Varredura de feriado** (77 noites, inclui esgotadas): só a VK2 de 09 e 10/10 fica abaixo de R$ 800 de valor real (preço calculado R$ 1.645, enviado R$ 1.659, cerca de R$ 642 reais), por decisão sua de −30%. Nenhuma outra.
- **Regra G:** Balcony nunca acima da suíte com banheira mais barata livre. Desvios abertos iguais aos das 07:53 (à espera da suavização).
- **Nada gravado nesta rodada.**
- **Pendências suas:** (1) salvar a suavização da Balcony na tela; (2) confirmar a base dos R$ 1.000 (preço enviado, vitrine da Booking ou valor real); (3) liberar no Beds24 as 3 unidades da Double no sábado 10/10, se quiser; (4) confirmar o quadro dos agentes `hc-*`.

## 09/10/2026, 14:15 — Rotina das 13:53 (leve): lote de 15 reservas da Booking (33 noites); nada gravado

- **Reservas:** 15 reservas feitas no mesmo segundo (10:59 BRT, comissão 0,0, padrão de lote da Booking), 33 noites e R$ 19.685 pagos. Recanto 11 reservas: Queen 7 em 15–17/10 (3 reservas), 26–28/10 e 15–16/11; Double em 25–26/10, 28–31/10, 09–10/11 e 05–06/12; Queen (2) em 13–14/10 e 06–07/11. Villa 4: VK7 em 18–19/10 e 23–24/10; VK2 em 13–15/10 e 23/10. Nenhum cancelamento. Valor pago por noite na faixa de 45% a 60% do enviado (Queen (2) em 20%, como já sabemos).
- **O calendário do PriceLabs ainda não tem o lote** (último recálculo às 10:23 BRT, antes das reservas). A ocupação abaixo é a do calendário; **projetando o lote**, o hotel iria a 48% em 7 dias, 40% em 15, 36% em 30, 28% em 45 e 23% em 60. A Queen 7 é a que mais ganha (+11 noites). Não decidi preço nenhum com base em número que ainda não está no calendário; reavalio na rotina das 16:53, depois do recálculo que as reservas disparam.
- **Ocupação do calendário** (7/15/30/45/60 dias): hotel 46% ⚠️, 37% ⚠️, 33% ⚠️, 26% ✅, 22% ✅. Recanto 51/42/31%, Villa 40/32/35%.
- **Envio:** os 7 quartos foram enviados às 10:23 BRT; tudo o que gravei até agora está nos canais. Sem mudança manual nova (a da VK2 é a de 01:08 UTC, já conhecida).
- **Correção do meu registro das 08:15:** a noite da Queen 7 em 11/10 que caiu para R$ 949 **foi vendida**, às 21:01 BRT de 08/10 (15 minutos depois do envio das 20:46), por R$ 536 pagos. Eu havia escrito "não houve venda": a lista de reservas do PriceLabs só recebe as reservas novas por volta das 10:59 BRT, até um dia depois da venda, e eu li a lista vazia como ausência de venda. O prejuízo é pequeno e já não tem volta (a noite está esgotada, e o piso de R$ 1.905 só entrou depois). Aprendizado e regra de detecção atualizados.
- **Varredura de feriado, Regra G e estadia mínima:** inalteradas desde as 10:53 (calendário idêntico, exceto o lote que ainda não entrou). Só a VK2 de 09 e 10/10 fica abaixo de R$ 800 reais, por decisão sua de −30%.
- **Exceção de mercado da VK2 em 23/10:** vai perder o sentido quando o calendário refletir o lote, porque a reserva nova de 23/10 esgota a data (2 de 2). Se esgotar, apago a exceção na rotina das 16:53.
- **Nada gravado nesta rodada.**

## 10/10/2026, 08:20 — Rotina das 07:53 (completa), com as rodadas das 16:53 e 19:53 de ontem: regras de feriado vencidas por validade corrigidas

- **Resumo:** uma auditoria independente achou que as regras por data de **11/10 e 19/11 tinham validade de 3 dias** (`lead_time_expiry`, da regra antiga "Véspera de feriado: política de 2 diárias") nos 7 quartos. Depois dos 3 dias a regra inteira (preço, piso e estadia) deixa de valer. Por isso: (1) a Queen 7 em 11/10 caiu a R$ 949 em 08/10 e vendeu; (2) a correção que gravei às 08:02 de 09/10 (0% e piso R$ 1.905) **nunca valeu**, e eu a dei como conferida porque a noite estava esgotada com preço alto; (3) o mesmo vale para a VK7 de 11/10 gravada às 16:57 de ontem. Corrigi tudo às 07:56 de hoje.
- **Reservas e cancelamentos desde as 19:53 de ontem:** nenhum. A lista de reservas do PriceLabs só recebe as vendas novas por volta das 10:59, então confirmo pela diferença do calendário depois do recálculo das 10:23. **Mudanças manuais do dono (tela):** VK7 em 10/10 às 19:48 de ontem (−35%, piso e teto −35% sobre o mínimo e o máximo); Queen 2 em 10/10 às 20:29 e 20:30 (−40%, com piso e teto −40%); VK2 em 09 e 10/10 (−30%) já conhecida. Respeitei as três. Valor real estimado em 10/10: VK7 R$ 726, VK2 R$ 658 e Queen 2 R$ 749, todos abaixo do limite de R$ 800, por decisão sua.
- **Envio:** os 7 quartos estão com `push_enabled`; ontem a VK7 e a Queen 2 enviaram à noite (depois das suas edições na tela, 22:50 e 23:30 UTC) e os outros 5 enviaram às 10:23. O envio de hoje ainda não saiu (10:23 BRT).
- **Ocupação** (hotel 7/15/30/45/60 dias): 50% ⚠️, 40% ⚠️, 36% ✅, 29% ✅, 24% ✅. Recanto 60/46/35/30/25%, Villa 39/33/36/29/23%. Por quarto em 15 e 30 dias: Q7 29/18%, Double 58/46%, Afrodite 53/50%, Q2 70/60%, VK7 39/41%, Balcony 20/26%, VK2 50/50%. Classificação de demanda sem mudança de nível (Q7 sobe, mas segue baixa; Afrodite e VK2 candidatas a alta).

| Mudança | Onde | Antes | Depois (conferido no recálculo das 08:01, flag da regra viva e piso igual ao gravado) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra por data de 11/10 recriada sem validade, com percentual e piso de feriado | 7 quartos | regra com validade 3 (vencida); piso do anúncio | Queen 7 −40% e R$ 1.905; Double −59% e R$ 1.905; Afrodite 0% e R$ 2.550; Queen 2 0% e R$ 2.355; VK7 −48% e R$ 2.060; VK2 −60% e R$ 2.060; Balcony 0% e R$ 1.510. Recálculo: R$ 1.905 (Q7, Double) e R$ 2.060 (VK2), que seria o preço se uma unidade reabrisse | "Apague as regras de 11/10" (não recomendado) |
| Regra por data de 19/11 recriada sem validade | 7 quartos | validade 3, venceria em 16/11 | mesmos valores, estadia 2; pisos 1.905 (Q7, Double, Afrodite), 2.355 (Q2), 2.052 (VK7, VK2), 1.510 (Balcony) | idem |
| Piso de segurança nas noites de Finados e Consciência Negra | Queen 7 e VK2: 30 e 31/10 (com o +20% do dono, 3 noites) e 20 e 21/11; Queen 2: 01/11 (+20%, 3 noites) | piso de fim de semana do PriceLabs (R$ 1.760 e R$ 1.905: R$ 739 e R$ 743 reais) | Queen 7 R$ 1.905, VK2 R$ 2.060, Queen 2 R$ 2.355 (R$ 800 reais) | "Apague o piso de Finados da Queen 7" |
| Fim da exceção de mercado da VK2 (23 e 24/10 esgotadas) | VK2 | piso R$ 860 | piso **R$ 1.000** (padrão de qualidade) | "Volte o piso da VK2 em 23/10 para R$ 860" |
| Regra G: Balcony a 10% abaixo da VK7 (VK2 esgotada) | Balcony 23 e 24/10 | R$ 853 (20% abaixo) | piso **R$ 963** (recálculo R$ 963) | "Volte o piso da Balcony para R$ 853" |

- **VK7 em 11/10:** a regra recriada (−48% e R$ 2.060) está gravada e sem validade (conferido na leitura da API), mas o recálculo dela não coube hoje: a VK7 já usou os 3 recálculos de 24 h. Confiro com o `dso_flag` na rotina das 16:53.
- **Mercado na Booking** (2 noites; mediana geral / mediana com hidro e nota 9+): 13–15/10 R$ 594 / R$ 1.728 (Recanto R$ 946, Villa R$ 729); 16–18/10 R$ 1.136 / R$ 2.660 (Recanto R$ 1.319, Villa R$ 1.075); 23–25/10 R$ 821 / R$ 1.899 (Recanto R$ 1.312, Villa R$ 1.129); Finados 30/10–01/11 R$ 1.413 / R$ 2.914 (Recanto R$ 2.760, Villa R$ 3.452). O Recanto está abaixo da mediana com hidro e nota 9+ em todas as datas (em Finados, 5% abaixo). A Villa está dentro da faixa nas três primeiras datas (a Villa não tem hidro listada na Booking) e acima em Finados: R$ 3.452, 18% sobre a mediana com hidro e nota 9+, segunda mais cara de 16; mantido o +20% do dono, com o mercado a 3 vezes o ano passado, e revisão em 16/10. Nenhum motivo para mexer em preço nesta rodada. Ocupação do mercado: 16 e 17/10 em 25%, igual ao ano passado; Finados 31/10 e 01/11 a 20%, o triplo do ano passado.
- **Estadia mínima** dos sábados e feriados nos 60 dias: tudo certo (2 noites; Finados 3), exceto 11/10 em 1 noite nos 7 quartos (última hora, esgotada) e o sábado 10/10 da Double (dia isolado entre duas noites esgotadas; 3 unidades presas, só você libera no Beds24).
- **Correção do registro das 08:15 de 09/10:** a Queen 7 em 11/10 "corrigida a 0% e piso R$ 1.905, recálculo R$ 2.132" não estava protegida; o R$ 2.132 era o preço natural de uma noite esgotada.

## 12/10/2026, 15:00 — Rotina completa e revisão semanal (recuperação depois de 2 dias parada)

- **O que aconteceu:** o ambiente reiniciou e as 10 rotinas de 10/10 (16:53) a 12/10 (10:53) **não rodaram**. Nesse tempo valeram só as regras já gravadas (os pisos de feriado sem validade de 10/10 seguraram) e o envio diário do PriceLabs. Fiz hoje uma rotina completa de recuperação, com a revisão de segunda.
- **Vendas da semana** (reservas feitas de 06 a 12/10, 187 diárias e R$ 110,5 mil): Queen 7 47 diárias (R$ 460 por noite, 49% do enviado, antecedência média 8 dias); Double 48 (R$ 693); Afrodite 3 (R$ 799); Queen 2 14 (R$ 687, só 23% do enviado: segue a anomalia); VK7 49 (R$ 623); Balcony 16 (R$ 520); VK2 10 (R$ 479). 48 reservas chegaram só depois das 10:53 de sábado. Cancelamentos: Double 12 e 14/10, Queen 7 10 e 11/10, Balcony 10/10 e VK2 09/10.
- **Ocupação** (hotel 7/15/30/45/60 dias): 54% ⚠️, 40% ⚠️, 36% ✅, 29% ✅, 24% ✅. Recanto **70% ✅** em 7 dias (48/37/31/25%); Villa 35% (30/34/27/22%). Queen 7 sexta e sábado 16 e 17/10 **esgotadas** (R$ 1.200 e R$ 1.227), Double e Queen 2 também.
- **Mudanças manuais do dono (tela):** Double em 11/10 às 12:25 e Balcony em 10/10 às 16:37 e 17:07 (datas fora de 12 a 26/10; sem efeito nos preços de 12 a 26/10). Respeitadas.
- **Envio de hoje:** Queen 7, Afrodite e VK2 às 10:25; Double e Balcony depois das edições (13:19 e 13:53); Queen 2 e VK7 saem por volta das 13:55 a 15:30.
- **Revisão semanal:** `referencia_semanal` atualizada para os valores de hoje (Queen 7 base 1.173, Double 1.480, Afrodite 2.000, Queen 2 1.870, VK7 1.480, Balcony e VK2 1.270). **Nenhuma base mexeu**: o mercado do Recanto está 5% a 40% abaixo da mediana com hidro e nota 9+ e as sextas e sábados de 23 e 24/10 já estão no teto da faixa (Double R$ 1.911, mercado a 8% a 17% de ocupação contra 12% a 19% do ano passado); Double e Queen 2 seguem em alta, mas a +10% por rodada só vale para datas com 67% ou mais vendido e preço abaixo da faixa (13 a 15/10 já estão 29% a 36% acima da mediana por noite). Classificação: **Queen 7 sobe de baixa para média** (15 dias 34%, 30 dias 21%, acima de 60% da meta nas duas janelas); Afrodite e VK2 candidatas a alta.
- **Regra I e Regra G de 13 a 17/10:** plano de 22 itens validado (0 erros) e auditado por 2 revisores independentes (ressalvas incorporadas: Queen 7 15/10 fica em R$ 800 por estar 86% vendida; percentuais com folga; pisos de R$ 1.220 da VK2 e VK7 em 16 e 17/10 acima do piso de R$ 1.213 da Balcony; sem teto em 16 e 17/10). **Não foi gravado**: a sessão foi interrompida antes e as datas andaram um dia; refaço na rotina de 13/10.
- **Mercado na Booking** (2 noites; mediana da união / hidro e nota 9+): 13–15/10 R$ 720 / R$ 1.495 (Recanto R$ 907, Villa R$ 729); 23–25/10 R$ 952 / R$ 1.533 (Recanto R$ 1.450, Villa R$ 1.274); Finados R$ 1.800 / R$ 2.986 (Recanto R$ 2.788, Villa R$ 3.452, 16% acima da mediana com hidro e nota 9+). Eventos: Oktoberfest 15 a 18/10 (Parque Capivari), WTR 17 e 18/10 e Festival Curta 17 a 24/10.

## 13/10/2026, 13:10 — Rotina das 07:53 (completa) e das 10:53 (leve): Regra I em D0 a D2, VK7 em demanda alta e Balcony alinhada à Regra G

- **O que aconteceu:** a rotina das 07:53 coletou com o calendário velho da VK7 e da Balcony (19 a 22 h; só recalculam à tarde). Gravei a Regra I na Queen 7 às 08:40 e, ao refazer a leitura na rotina das 10:53, o recálculo mostrou outra realidade: **VK7 com 74% em 7 dias (não 53%)**, Balcony esgotada em 16 e 17/10 (não 3 de 6) e VK2 esgotada em 16 e 17/10. Refiz o plano, passei por **auditoria independente de 2 revisores** (aprovou com ajustes; todos incorporados) e gravei. Aprendizado e script novo para não repetir (`mesclar_recalculo.py`).
- **Reservas:** a lista do PriceLabs ainda não traz as vendas de 12/10 11:26 em diante (atraso conhecido); pelo calendário, desde a última coleta: Queen 7 13/10 de 4 para 6 de 7 (+2 em 3 h depois do envio a R$ 640), VK7 16/10 esgotou (7 de 7), VK7 17/10 6 de 7, VK7 15/10 e 20/10 em 5 de 7 (+2 cada), Balcony 16 e 17/10 esgotadas (6 de 6), VK2 15, 16 e 17/10 esgotadas. Cancelamentos: Double (2, em 12/10, já conhecidos).
- **Ocupação** (hotel 7/15/30/45/60 dias, calendário recalculado): 62% ⚠️, 44% ⚠️, 37% ✅, 29% ✅, 24% ✅. Suítes com banheira 70% ✅, 49%, 40%, 31%, 26%. Recanto 69/46/36/30/25%; Villa 54/41/38/28/22%. Por quarto em 7 dias: Q7 63%, Double 71%, Afrodite 83%, Q2 79%, VK7 74%, Balcony 29%, VK2 64%.
- **Demanda:** **VK7 sobe de média para alta** (15d 51,4%, 30d 44,8%, 49 diárias na semana passada; margem pequena de 1,4 ponto no 15d: volta a média se o 15d ficar abaixo de 50% em 2 recálculos seguidos). VK2 é candidata (15d 60%, 30d 47%, 2 unidades). Mercado: ocupação de 13 a 17/10 entre 20% e 30,5%, 3 a 4 vezes a do ano passado (Oktoberfest).
- **Envio:** Queen 7, Afrodite e VK2 saíram às 09:52 BRT (a Queen 7 já levou os R$ 640 de 13 e 14/10). Double, Queen 2, VK7 e Balcony ainda não enviaram hoje (saem a partir de 13:19 BRT e levam o que gravei). **A VK2 gravada depois do envio** (R$ 630 em 13 e 14/10) só chega aos canais amanhã às 09:53, a não ser que você aperte o **Sync Now** da VK2.
- **Booking (12:50 BRT):** Recanto 14/10 a partir de R$ 403 (Queen 7 enviada a R$ 640, vitrine 63%); Villa R$ 365 (Balcony a R$ 689, 53%): o preço enviado de manhã já está na Booking.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 12:44 e 13:08 BRT, flag da regra viva e piso igual ao gravado) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra I (D0 e D1, unidade livre) | Queen 7 13 e 14/10 | R$ 800 | **R$ 640** (−45%, piso 640) | "Volte a Queen 7 de 13 e 14/10 para R$ 800" |
| Sobe 10% pelo piso (data com 71% vendida) | Queen 7 15/10 e 18/10 (domingo) | R$ 800 | **R$ 880** (15/10 conferido; 18/10 gravado, conferido na próxima rodada) | "Volte a Queen 7 de 15 e 18/10 para R$ 800" |
| Regra I (última hora, só a última unidade) | VK2 13 e 14/10 | R$ 765 | **R$ 630** (−45%, piso 630) | "Volte a VK2 de 13 e 14/10 para R$ 765" |
| VK7 volta ao mínimo (demanda alta: Regra I só na última unidade); +10% em 15/10 e 20/10 | VK7 13, 14, 15 e 20/10 | R$ 850 (13/10 e 14/10 passaram por R$ 700 sem chegar aos canais) | 13 e 14/10 **R$ 850**; 15/10 **R$ 935** (vista em R$ 850 no último recálculo); 20/10 **R$ 935** (gravado; conferido na próxima rodada) | "Volte a VK7 de 15 e 20/10 para R$ 850" |
| Piso da suíte acima do piso da Balcony em sexta e sábado (Regra G) | VK7 e VK2 16 e 17/10 | R$ 1.020 | **R$ 1.280** (VK7 R$ 1.366 e R$ 1.553 calculados; VK2 R$ 1.280 e R$ 1.495; esgotadas) | "Volte o piso de 16 e 17/10 para R$ 1.020" |
| Regra G: Balcony ≈10% abaixo da suíte livre mais barata | Balcony 13 e 14/10 | R$ 689 | **R$ 630** (piso e teto; igual à VK2 livre e ao limite de segurança) | "Volte a Balcony de 13 e 14/10 para R$ 689" |
| Idem | Balcony 15/10 | R$ 689 | **R$ 842** (piso e teto; 10% abaixo da VK7 a R$ 935, VK2 esgotada); gravado, conferido na próxima rodada | "Volte a Balcony de 15/10 para R$ 689" |
| Idem | Balcony 18 a 22/10 e 25/10 | R$ 698 e R$ 696 (17% a 20% abaixo da suíte) | **R$ 765** (alvo R$ 760, mínimo do anúncio) em 18 a 22/10; **R$ 780** em 25/10; gravado, conferido na próxima rodada | "Volte a Balcony de 18 a 22/10 para R$ 698 e a de 25/10 para R$ 696" |
| Balcony esgotada: piso e teto para um cancelamento não abrir acima da suíte | Balcony 16 e 17/10 | piso R$ 1.016, sem teto (preço natural R$ 1.459 e R$ 1.661) | 16/10 **R$ 1.264** (piso e teto); 17/10 piso **R$ 1.264**, teto **R$ 1.398** (R$ 1.398 conferido no recálculo) | "Volte o piso de 16 e 17/10 da Balcony para R$ 1.016 e apague o teto" |

- **Não conferido no recálculo (limite de 3 recálculos por quarto em 24 h):** VK7 (13, 14, 15 e 20/10, gravadas às 13:07 BRT), Balcony (15/10 a R$ 842; 18 a 22/10 e 25/10) e Queen 7 18/10. As regras foram lidas de volta da API (sem `lead_time_expiry`); o recálculo delas fica para as rotinas das 13:53 (Queen 7) e das 16:53 (VK7 e Balcony). O mecanismo é o mesmo já conferido nas outras datas.
- **Estadia mínima:** sábado 17/10 mostra 1 noite na VK7 no recálculo, apesar da regra por data em 2: é o dia isolado entre duas noites esgotadas (16 e 18/10 em 7 de 7), já conhecido da Double em 10/10. A última unidade de sábado fica protegida no Beds24 pelo bloqueio de sábado sozinho que você mantém.
- **Mercado (Booking, 2 noites; mediana da união / hidro e nota 9+):** 14–16/10 R$ 1.246 / R$ 1.798 (Recanto R$ 907, Villa R$ 729); 16–18/10 R$ 2.189 / R$ 2.660 (**Recanto esgotado na Booking**, Villa R$ 1.798); 23–25/10 R$ 952 / R$ 1.106 (Recanto R$ 1.450, Villa R$ 1.274); Finados R$ 1.800 / R$ 2.914 (Recanto R$ 2.788, 4% abaixo; Villa R$ 3.452, 18% acima).
- **Exceções de mercado:** nenhuma em vigor.
- **Pendências suas:** (1) **Sync Now** da VK2 (R$ 630 em 13 e 14/10) e, se quiser, da VK7 e da Balcony depois das 13:55 (D0 a D2); (2) salvar a suavização da Balcony na tela (All Customizations > Smoothing > "Smooth weekdays vs. weekends"); (3) confirmar a base dos R$ 1.000 (preço enviado, vitrine da Booking ou valor real). Balcony em 27, 28 e 29/10 segue em R$ 696 (abaixo do mínimo de R$ 765, regra antiga): trato na rotina das 16:53.

## 13/10/2026, 14:05 — Rotina das 13:53 (leve): VK7 esgotou 15/10; piso de 14/10 sobe; sábado 05/12 da Queen 7 em 2 noites

- **Reservas e calendário desde as 10:53 (calendário recalculado de todos os quartos):** a Villa King Spa 7 vendeu mais 2 noites em 13/10 (agora 4 de 7), 2 em 14/10 (6 de 7), 2 em 15/10 (**esgotou**, 7 de 7) e 1 em 23 e 24/10 (5 de 7). A Queen 7 em 13/10 já está com 6 de 7 (+2 em 3 horas depois do envio a R$ 640). **A Double perdeu 4 diárias** (13/10 de 4 para 2 de 6 vendidas, 14/10 de 3 para 2, 15/10 de 2 para 1): são cancelamentos de reservas antigas ou ajuste no Beds24, a lista de reservas só mostra o que foi feito desde 11/10. Balcony, King Spa 2, Queen 2 e Afrodite sem venda nova. Sem mudança manual sua desde 11/10 (as 11 ações no log de hoje são minhas).
- **Ocupação** (hotel 7/15/30/45/60 dias): 63% ⚠️, 45% ⚠️, 38% ✅, 29% ✅, 24% ✅. Suítes com banheira **72% ✅**, 50% ✅, 40%, 32%, 26%. Recanto 67/45/36/30/25%; Villa 60/45/40/29/23%. A VK7 está em 86%, 59%, 49%, 35% e 29%: **demanda alta confirmada**. A Double cai para 64% e 49% em 7 e 15 dias: reavalio a classe na rotina de 14/10.
- **Envio de hoje:** Double 11:33 BRT, Balcony 13:09 (já levou a Regra G e os tetos), Queen 2 13:34 e VK7 13:53 (levou R$ 850 em 13/10, R$ 935 em 15 e 20/10). **Falta enviar:** Queen 7 (15/10 R$ 880 e 18/10 R$ 880) e King Spa 2 (R$ 630 em 13 e 14/10), que só saem amanhã às 09:52, a não ser que você aperte o **Sync Now**.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| +10% pelo piso: 6 de 7 vendidas, demanda alta | VK7 14/10 | R$ 850 | piso **R$ 935** (gravado e lido de volta; recálculo no limite de 3 por quarto em 24 h, confiro às 16:53) | "Volte a VK7 de 14/10 para R$ 850" |
| Estadia mínima do sábado novo na janela (Regra H) | Queen 7 05/12 | 1 noite | **2 noites** (gravado e lido de volta; recálculo às 16:53) | "Apague a regra de 05/12 da Queen 7" |

- **Conferido no recálculo desta rodada:** Queen 7 15/10 R$ 880; VK7 13 e 14/10 R$ 850, 15/10 R$ 935 (esgotada) e 20/10 R$ 935; Balcony 15/10 R$ 842, 18 a 22/10 R$ 765 e 25/10 R$ 780. **Não conferido:** Queen 7 em 18/10 (R$ 880, gravado às 13:10; o recálculo bate no limite) e as duas gravações de agora.
- **Balcony 27, 28 e 29/10 em R$ 696:** correção do meu registro das 13:10 (eu havia tratado como abaixo do mínimo sem exceção). Está dentro da Regra G: a King Spa 2 livre custa R$ 765 e 10% abaixo é R$ 689. Nada a fazer.
- **Regra I da Queen 7 em 13/10:** a data passou de 70% vendida (6 de 7) por causa do próprio corte; mantive R$ 640 até o fim do dia para vender a última unidade. Regra esclarecida em `regras.md`: o corte só continua no próprio dia (D0); de D1 a D3 volta ao mínimo na rodada seguinte.
- **Estadia mínima:** sábados e feriados dos 60 dias conferidos: tudo em 2 noites (feriados 2 e 3), exceto o sábado 17/10 da VK7, que o PriceLabs mostra com 1 noite (dia isolado entre duas noites esgotadas, 16 e 18/10).
- **Pendências suas:** (1) **Sync Now** da Queen 7 e da King Spa 2; (2) olhar no Beds24 a última unidade da VK7 no sábado 17/10 (estadia mínima 1 no PriceLabs; se quiser trancar de vez, desligue a regra de dia isolado em estadia mínima); (3) salvar a suavização da Balcony na tela.

## 13/10/2026, 17:10 — Rotina das 16:53 (leve): Queen 7 esgotou 13, 16 e 17/10; piso de 15/10 volta a R$ 800

- **O que descobri:** a coleta das 16:53 veio idêntica à das 13:53 (calendário parado, nenhuma reserva nova na lista). Recalculei Queen 7, VK7, Double, VK2 e Balcony para ver o que havia por trás.
- **Vendas e cancelamento desde as 13:53 (pelos recálculos de 17:00):**
  - **Queen 7:** 13/10 esgotou (6 para 7 de 7, a R$ 640), 14/10 foi de 2 para 4 de 7, 16 e 17/10 esgotaram, 18/10 foi de 5 para 6, 23 e 24/10 ganharam 1 cada. **Cancelamento em 15/10** (5 para 4 de 7).
  - **VK7:** 13/10 de 4 para 5 de 7; 14/10 em 6 de 7 (último apartamento a R$ 935 calculado).
  - **Double:** +1 em 13, 14 e 15/10, +1 em 20 e 21/10 e mais 8 diárias em novembro; **Balcony:** primeira venda em 15/10 (1 de 6) ao preço da Regra G (R$ 842). King Spa 2 sem venda nova.
- **Ocupação** (hotel 7/15/30/45/60 dias): **68%** ⚠️ (era 63%), 48% ⚠️, 39% ✅, 32% ✅, 26% ✅. Suítes com banheira **77% ✅**, 54%, 42%, 35%, 28%. Recanto **74% ✅**, 50% ✅, 39%, 34%, 28%; Villa 62% ⚠️, 46% ⚠️, 40%, 29%, 23%. Por quarto em 7 dias: Queen 7 74%, Double 71%, Afrodite 83%, Queen 2 79%, VK7 88%, Balcony 31%, VK2 64%.
- **Demanda:** Double volta a se confirmar em alta (15d 54%, 30d 46%); VK7 alta (60%, 49%); Queen 7 média (7 dias 74%, mas 15d 42% e 30d 25%).
- **Envio:** nada saiu desde as 13:53. Falta enviar a Queen 7 (09:52, ainda com R$ 800 em 14, 15 e 18/10 nos canais) e a King Spa 2 (R$ 630 em 13 e 14/10). Double, Balcony, VK7 e Queen 2 já enviaram; o R$ 935 da VK7 em 14/10 só sai no próximo envio.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso volta ao mínimo: 15/10 caiu de 71% para 57% vendida (cancelamento), sem direito ao +10% | Queen 7 15/10 | piso R$ 880 (nunca enviado; enviado R$ 800) | piso **R$ 800** (gravado e lido de volta; recálculo amanhã no envio das 09:52) | "Suba a Queen 7 de 15/10 para R$ 880" |

- **Conferido no recálculo desta rodada:** Queen 7 14/10 R$ 640 e 18/10 R$ 880 (86% vendida, mantive: +10% sobre o enviado de R$ 800, sem empilhar nova alta), 05/12 com **2 noites**; VK7 14/10 **R$ 935** (flag da regra viva e piso igual), 15/10 R$ 935 (esgotada) e 20/10 R$ 935; Balcony 13 e 14/10 R$ 630, 15/10 R$ 842 e 18 a 22/10 R$ 765 (enviados e já na Booking). Queen 7 13, 16 e 17/10 esgotadas: o preço calculado delas (R$ 640, R$ 1.325 e R$ 1.386) é o que valeria se reabrissem.
- **VK7 em 13/10** (5 de 7, 2 livres, D0 às 17h): segurei R$ 850. Subir o piso de uma data que perde valor à meia-noite não compensa.
- **Estadia mínima:** nada novo; o sábado 17/10 da VK7 segue com 1 noite no PriceLabs (dia isolado entre 16 e 18/10 esgotados).
- **Pendências suas:** (1) **Sync Now** da Queen 7 e da King Spa 2; (2) Beds24: última unidade da VK7 no sábado 17/10 com estadia mínima 1; (3) salvar a suavização da Balcony na tela.

## 13/10/2026, 22:00 — Rotina das 19:53 (leve): sexta 16/10 esgotada nos 7 quartos; King Spa 2 esgotou 13 e 14/10; Balcony 14/10 vai a R$ 842

- **Recálculo de todos os 7 quartos** (calendários com 3 a 10 h): a coleta idêntica de novo escondia vendas. Desde as 17:00:
  - **King Spa 2:** a última unidade de 13 e 14/10 vendeu a R$ 765 enviado (os R$ 630 da Regra I ainda não tinham chegado aos canais): esgotada nas duas noites.
  - **Queen 2:** 16/10 esgotou (de 1 para 2 de 2). **VK7:** +1 em 21 e 22/10. **Queen 7:** +1 em 4 e 5/11; 15/10 recalculou em **R$ 800** (a reversão das 17:00 pegou).
  - **Afrodite:** 16/10 aparece indisponível no calendário (sem reserva listada; mesmo caso do sábado 17/10, que você mantém bloqueado no Beds24).
  - Sem mudança manual sua e sem reserva nova na lista (a lista segue atrasada).
- **Ocupação** (hotel 7/15/30/45/60 dias): **70%** (150 de 215), 49% ⚠️, 40% ✅, 32% ✅, 26% ✅. Suítes com banheira 79% ✅, 55%, 43%, 35%, 29%. **Recanto 76% ✅**, 51% ✅, 39%, 34%, 28%; Villa 64% ⚠️, 48% ⚠️, 41%, 30%, 24%. Por quarto em 7 dias: Queen 7 74%, Double 71%, Afrodite 100%, Queen 2 86%, VK7 88%, Balcony 31%, King Spa 2 79%.
- **Sexta 16/10: esgotada nos 7 quartos.** Sábado 17/10 só a King Spa 7 tem 1 unidade (R$ 1.622, estadia mínima 1 no PriceLabs por ser dia isolado).
- **Envio:** nada saiu desde as 13:53. Queen 7 (R$ 800 em 14, 15 e 18/10 nos canais), King Spa 2 e King Spa 7 (R$ 935 em 14/10) saem amanhã de manhã ou numa venda; **Sync Now** da Queen 7 e da King Spa 7 antes disso ajuda D1 (3 unidades da Queen 7 a R$ 640 e a última da King Spa 7 em 14/10).

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra G: com a King Spa 2 esgotada, a referência passa a ser a King Spa 7 (R$ 935 calculado) | Balcony 14/10 | piso e teto R$ 630 (igual à King Spa 2 livre) | piso e teto **R$ 842** (10% abaixo; recálculo das 21:55 conferido) | "Volte a Balcony de 14/10 para R$ 630" |

- **Conferido no recálculo:** Queen 7 15/10 R$ 800 (flag da regra viva, piso R$ 800); VK7 14/10 R$ 935; Balcony 15/10 R$ 842 (vendeu 1 de 6), 18 a 22/10 R$ 765 e 25/10 R$ 780. Balcony 14/10 **R$ 842 conferido às 21:55** (flag da regra viva, piso e teto R$ 842); enviado amanhã.
- **Balcony em 13/10** (6 livres, 20h): fica em R$ 630; a noite acaba em poucas horas.
- **Pendências suas (nesta ordem):** (1) **Sync Now** da Queen 7, da King Spa 7 e da King Spa 2; (2) Beds24: última unidade da King Spa 7 no sábado 17/10; (3) salvar a suavização da Balcony na tela.

## 14/10/2026, 02:20 — Pedido do dono (13/10): faixas de preço de mercado, sazonalidade e flutuação percentual

- **O pedido:** qualificar os preços em cinco faixas (muito baixo, baixo, médio, alto e muito alto), prever a sazonalidade de cada época, feriado e dia, e flutuar em percentual para manter as metas; pesquisar a concorrência inteira com IA.
- **Pesquisa feita por agentes de IA (só leitura):** (1) sazonalidade: 5 agentes leram feriados oficiais (Portaria MGI, ANBIMA 2027, Alesp, TRT-15), férias escolares (SEDUC), eventos (Festival de Inverno, Oktoberfest, Natal FLMA, provas esportivas, congressos), balanços da Prefeitura e os dados de mercado do PriceLabs mês a mês; (2) concorrência: 38 agentes varreram a Booking por faixa de preço em 17 conjuntos de datas; 16 concluíram (semanas e fins de semana de 20/10 a 10/12, 1.288 chamadas, 150 a 180 propriedades por data) e 22 falharam por limite da sessão de IA (feriados, Natal, Réveillon, última hora): retomo na rotina das 07:53.
- **O que entrou na skill (sem mudar preço nenhum ainda):**
  - calendário de sazonalidade de 14/10/2026 a 31/12/2027 (77 períodos, cada noite em um só; nível de demanda, faixa alvo, estadia e antecedência, com fonte) e 12 feriados de 2027 na lista de pisos de feriado;
  - cortes das cinco faixas por conjunto de pares (tabela em `mercado.md`);
  - regra de flutuação (regras.md 4c): por noite, faixa atual da vitrine contra a faixa alvo da sazonalidade e ocupação contra a meta da janela: atrás da meta desce um degrau (−10%, −15% em alto/muito alto, nunca abaixo de "baixo" fora de D0 a D3), à frente sobe +10% até o alvo mais um, na meta aproxima devagar (±5%); um passo por rodada; todos os limites atuais continuam por cima.
- **Leitura dos nossos preços hoje (vitrine da Booking, próximos 21 dias):** Queen 7 em dia de semana está na faixa **baixo** dos pares do Recanto (R$ 390 a 561 por noite; corte do baixo R$ 727); Double, Afrodite e Queen 2 no **médio** (R$ 742, 1.002 e 927). Na Villa, VK7 (R$ 461), VK2 (R$ 442) e Balcony (R$ 405) estão no **médio** dos pares dela mesmo no mínimo do anúncio, porque metade da oferta comparável custa menos de R$ 480 por noite. Fins de semana: Recanto no médio/alto (R$ 1.162), Villa de alto a muito alto. A classificação sugeriu 5 subidas (Queen 7 18/10, Double 20, 21/10 e 01/11, Balcony 01/11: noites acima da meta) e descidas só onde o preço está acima do mínimo (fins de semana de 23 e 24/10, Finados do Recanto a 0 de 7 e Afrodite); as descidas nas noites já no mínimo ficam para a Regra I.
- **Nada gravado no PriceLabs nesta rodada:** a regra começa a valer na rotina das 07:53 de 14/10, com o calendário recalculado e a varredura retomada. Pendências suas de ontem seguem (Sync Now; sábado 17/10 da VK7 no Beds24; suavização da Balcony).

## 14/10/2026, 08:30 — Pedido do dono: estratégia consolidada e skill ajustada (nada gravado no PriceLabs nesta rodada)

- **O que fiz:** escrevi a estratégia única (`estrategia.md`, versão 1.2), passei por 3 revisores e 5 auditores independentes de IA (91 achados) e apliquei a consolidação: regras sem contradição (gatilho de subida único, saída da escada que não apaga, um passo por noite por dia, classe de demanda com histerese), pendências numa lista só (`dados/pendencias.json`), trechos vencidos removidos, validador com 9 travas novas (40 testes ok) e os aprendizados resumidos em 18 princípios.
- **Decisões registradas para a rotina completa de hoje (a gravar e conferir):** mínimo da Balcony de R$ 765 para R$ 690 (Regra G do dono só fecha assim); 13 e 14/11 refeitos como fim de semana comum; Natal e Réveillon com piso de feriado novo; Queen 7 de 25 a 29/10 no mínimo; Recanto 23 e 24/10 um degrau e Afrodite com a Regra C; varredura dos feriados (rodada 2) fora da sessão da rotina.
- **Pendências suas (as 3 mais urgentes):** confirmar a base dos R$ 1.000; Beds24 (tarifa da Queen 2 e canais da Villa); suavização da Balcony na tela. Lista completa em `dados/pendencias.json`.

## 14/10/2026, 12:10 — Rotina das 07:53 (completa) e das 10:53 (leve): primeira rodada da flutuação por faixas, Balcony com mínimo R$ 690, 13 e 14/11 refeitos, Natal e Réveillon protegidos

- **Como foi:** coleta completa às 08:00 (preços até 02/01, reservas, logs, mercado); a busca de concorrentes caiu no limite da sessão de IA e a rodada ficou parada das 08:05 às 11:20 pelo limite de uso. Às 11:25 recalculei 6 quartos de uma vez com o detalhamento (a Balcony deu 429: calendário de 13 h) e às 11:27 reli reservas, logs e envio. Esta entrada cobre também a rotina leve das 10:53.
- **Reservas:** lista com 39 reservas feitas de 12 a 14/10, **nenhuma nova** desde ontem à noite. Pelo calendário (13/10 22:00 → 14/10 11:25): Double +1 em 14/10, 15/10 e 12/11; VK2 +1 e VK7 −1 em 21 e 22/10 (remanejamento no Beds24, não é venda); Queen 7 −1 em 14/10 e Queen 2 −1 em 16/10 e 24–25/11 (cancelamentos de reservas antigas). Sem mudança manual sua (15 logs, todos nossos).
- **Ocupação** (hotel 7/15/30/45/60 dias): **67%** ⚠️ (145 de 216), 46% ⚠️, 39% ✅, 31% ✅, 25% ✅. Suítes com banheira 75% ✅, 51% ✅, 41%, 34%, 28%. **Recanto 70% ✅**, 47% ⚠️, 38%, 33%, 27%; Villa 64% ⚠️, 45% ⚠️, 40%, 29%, 23%. Em 7 dias: Queen 7 57%, Double 81%, Afrodite 67%, Queen 2 86%, VK7 88%, Balcony 36%, King Spa 2 64%.
- **Classes de demanda (rotina completa):** Double alta, Queen 2 alta, **VK7 alta confirmada** (7d 88%, 15d 56%, 30d 47%; candidata desde 13/10), King Spa 2 **candidata a alta** (15d 70%, 30d 50%; confirma em 15/10), Queen 7 média, Afrodite média, Balcony baixa.
- **Faixas:** cortes de 14/10 (varredura da rodada 1, 13/10; busca própria de 20/10 a 10/12; feriados sem busca própria, sem passo pela faixa). Classificação das 60 noites em `faixas_quartos_1410c.md` (rascunho).
- **Envio:** VK7 (09:38) e Balcony (09:10) enviaram hoje; **Queen 7, Afrodite e King Spa 2 não** (último envio 13/10 09:52). Booking às 11:30: Recanto a partir de R$ 403 em 14/10 (Queen 7 a R$ 640, vitrine 0,63) e R$ 504 em 15/10 (R$ 800); Villa R$ 446 nas duas noites (Balcony a R$ 842, 0,53): o que foi gravado ontem chegou aos canais. O que gravei hoje só chega no próximo envio ou com o **Sync Now**.
- **Plano:** 270 itens validados (0 erros; 225 avisos, quase todos "valor final só no recálculo"), gravados pela API em 7 chamadas (todas 200; as 270 regras voltaram iguais ao enviado, sem validade) e o mínimo da Balcony pelo `update_listing_data`.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 12:00 só na Afrodite e na Queen 2) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Mínimo do anúncio da Balcony (Regra G só fecha com o mínimo 10% abaixo da VK2) | Balcony, anúncio | R$ 765 | **R$ 690** (−9,8%; API confirmou min 690) | "Volte o mínimo da Balcony para R$ 765" |
| Regra I (D1, 3 de 7 livres, 57%) | Queen 7 15/10 | R$ 800 | piso **R$ 640** (não conferido; nos canais segue R$ 800 até o Sync Now) | "Volte a Queen 7 de 15/10 para R$ 800" |
| Flutuação 4c: atrás da meta, −10% com piso R$ 1.000 | Queen 7 23 e 24/10 | R$ 1.303 | −43% no bloco → ~R$ 1.092 (não conferido) | "Volte a Queen 7 de 23 e 24/10 para −32%" |
| Regra F D9 a D13: mínimo em semana fraca (29 de 35 livres) | Queen 7 25 a 29/10 | R$ 959 (sem regra) | −17% no bloco, piso **R$ 800** (não conferido) | "Apague as regras da Queen 7 de 25 a 29/10" |
| 4c: −10% em 02/11 pelo piso; bloco dom–qui refeito (01 e 03–05/11 sem mudança) | Queen 7 01 a 05/11 | R$ 1.240 (02/11) | piso **R$ 1.116** em 02/11, −25% no bloco (não conferido) | "Volte a Queen 7 de 02/11 para piso R$ 1.240" |
| 4c: −15% (faixa alto, alvo médio, 0–1 de 7) | Queen 7 06 e 07/11 | R$ 1.880 | −25% no bloco → ~R$ 1.600, piso R$ 1.000 (não conferido) | "Volte a Queen 7 de 06 e 07/11 para −12%" |
| 13 e 14/11 viram sexta e sábado comuns (saíram da lista de feriados) | Queen 7 13 e 14/11 | piso de feriado R$ 1.905, 2 noites | −2% → ~R$ 1.711, piso R$ 1.000; 13/11 com 1 noite (não conferido) | "Volte a Queen 7 de 13 e 14/11 para piso R$ 1.905 e 2 noites" |
| 4c: à frente da meta, +10% pelo piso | Queen 7 15/11 e 23/11 | R$ 957 e R$ 819 | pisos **R$ 1.053** e **R$ 901** (não conferido) | "Apague os pisos da Queen 7 de 15 e 23/11" |
| 4c: −5% (muito alto com alvo médio) | Queen 7 04 e 05/12 | R$ 1.870 | −5% → R$ 1.777, piso R$ 1.000 (não conferido) | "Apague as regras da Queen 7 de 04 e 05/12" |
| 4c (demanda alta): +5% e +10% pelo piso | Double 19, 20 e 21/10 | R$ 900 | pisos **R$ 945, 990 e 990** (não conferido) | "Volte a Double de 19 a 21/10 para piso R$ 900" |
| 4c: −10% com piso R$ 1.000; Regra A 1 noite na sexta | Double 23 e 24/10 | R$ 1.982, 2 noites | −33% no bloco → R$ 1.660; 23/10 com 1 noite (não conferido) | "Volte a Double de 23 e 24/10 para −20% e 2 noites" |
| 4c (alta): +5% em 25, 26 e 28/10 e +10% em 29/10 pelo piso | Double 25 a 29/10 | R$ 1.240 (sem regra) | pisos **R$ 1.277** e **R$ 1.338** (não conferido) | "Apague os pisos da Double de 25 a 29/10" |
| Finados: piso de proteção R$ 1.905 (R$ 800 reais) em noites sem piso; +20% e 3 noites mantidos | Double e Afrodite 30 e 31/10; Queen 2 30 e 31/10 (R$ 2.355) | sem piso | pisos gravados (Afrodite e Queen 2 ✅; Double não conferida) | "Apague os pisos de 30 e 31/10 da Double, Afrodite e Queen 2" |
| 4c: −15% (alto com alvo médio) | Double 06 e 07/11 | R$ 2.729 | −25% no bloco → ~R$ 2.326, piso R$ 1.000 (não conferido) | "Volte a Double de 06 e 07/11 para −12%" |
| 4c (alta): subidas pelo piso | Double 09–10/11 (R$ 1.383), 15–17/11 (R$ 1.331), 18/11 (R$ 1.395), 22–24/11 (R$ 1.164), 29/11 (R$ 1.210), 30/11–03/12 (R$ 1.155), 06, 07 e 10/12 (R$ 1.193), 11–12/12 (R$ 2.624) | R$ 1.257, 1.268, 1.268, 1.109, 1.100, 1.100, 1.136 e 2.385 | pisos gravados (não conferido) | "Apague os pisos da Double de novembro e dezembro" |
| 13 e 14/11 sexta e sábado comuns: 4c −15% | Double 13 e 14/11 | R$ 2.349 (sem regra) | −20% → ~R$ 1.879, piso R$ 1.000 (não conferido) | "Apague as regras da Double de 13 e 14/11" |
| Regra C (−30%, piso R$ 1.500) a 9–13 dias; 28 e 29/10 acompanham o bloco; Regra A 1 noite em 23/10 | Afrodite 23 a 29/10 | R$ 3.348 (sex–sáb) e R$ 1.675 (dom–qui), sem regra | **R$ 2.395** em 23–24/10 e **R$ 1.500** em 25–29/10 ✅ | "Apague as regras da Afrodite de 23 a 29/10" |
| 4c: −5% em 02/11 e −10% em 04–05/11 pelo piso; bloco refeito | Afrodite 01 a 05/11 | R$ 2.316 (02/11), R$ 1.753 (04–05/11) | **R$ 2.200** e **R$ 1.514** ✅ (01 e 03/11 sem mudança) | "Volte a Afrodite de 02, 04 e 05/11 para −25% e pisos R$ 2.316/1.500" |
| 4c: −15% (06–07/11), −10% (26/11), −5% (27–28/11 e 04–05/12), piso R$ 1.500 | Afrodite | R$ 4.256; 1.709; 3.328; 3.592 | **R$ 3.735; 1.524; 3.244; 3.426** ✅ | "Apague as regras da Afrodite de 06–07/11, 26–28/11 e 04–05/12" |
| Regra A: 1 noite a até 14 dias | Queen 2 23/10 | 2 noites | **1 noite** ✅ | "Volte a Queen 2 de 23/10 para 2 noites" |
| 4c: à frente da meta, +10% pelo piso (sem percentual negativo) | Queen 2 15/11 e 23/11 | R$ 1.666 e R$ 1.457 | **R$ 1.833** e **R$ 1.603** ✅ | "Apague os pisos da Queen 2 de 15 e 23/11" |
| 4c (alta): +10% pelo piso (5 de 7 a 9 dias) | VK7 23 e 24/10 | R$ 1.130 | piso **R$ 1.243** (não conferido) | "Volte a VK7 de 23 e 24/10 para piso R$ 1.000" |
| 4c: −10% leva ao mínimo | VK7 25 a 29/10 | R$ 875 | −29% no bloco, piso **R$ 850** (não conferido) | "Volte a VK7 de 25 a 29/10 para −27%" |
| Finados: piso de proteção R$ 2.052 em 30–31/10; 4b (alta): 01/11 quase esgotada +10%; 4c: 02/11 −5%, 03/11 −15%, 04/11 −5% | VK7 30/10 a 04/11 | sem piso; R$ 2.082; 1.893; 1.161; 1.161 | pisos **R$ 2.052; 2.290; 1.798; 987; 1.103** (não conferido) | "Volte a VK7 de 30/10 a 04/11 para os valores de 13/10" |
| Padrão de qualidade: piso R$ 1.000 em sexta e sábado esgotadas sem piso | VK7 e King Spa 2 06 e 07/11 | sem piso | piso R$ 1.000 (não conferido) | "Apague os pisos de 06 e 07/11 da VK7 e da VK2" |
| 4c, Villa de novembro e dezembro um degrau para baixo (alto/muito alto com alvo baixo/médio; dias de semana rumo ao mínimo) | VK7 08–12/11 (−15% → R$ 1.053), 15–18/11 (→ R$ 1.039), 22/11 (→ R$ 940), 23–26/11 (→ R$ 850), 27/11 (→ R$ 1.883), 28/11 (→ R$ 2.109), 29/11–03/12 (→ R$ 979), 04–05/12 (→ R$ 2.112), 06–10/12 (→ R$ 1.026), 11–12/12 (→ R$ 2.171) | R$ 1.239; 1.222; 989; 989; 2.220; 2.220; 1.030; 2.220; 1.080; 2.285 | gravado (não conferido) | "Apague as regras da VK7 de 08/11 a 12/12" |
| 4c: −10% leva ao mínimo; teto R$ 765 para cortar só estas noites do bloco | King Spa 2 21 e 22/10 | R$ 855 | piso e teto **R$ 765** (não conferido) | "Tire o teto da King Spa 2 de 21 e 22/10" |
| 4c: 02/11 −5%, 03/11 −15%, 04/11 −5% pelo piso (01/11 mantido) | King Spa 2 01 a 04/11 | R$ 1.712; 973; 973 | pisos **R$ 1.626; 827; 924** (não conferido) | "Volte a King Spa 2 de 02 a 04/11 para −34% e piso R$ 765" |
| 13 e 14/11 sexta e sábado comuns: piso de feriado sai; 4c −5% | King Spa 2 13 e 14/11 | piso R$ 2.052 | +7% no bloco → ~R$ 1.947, piso igual (não conferido) | "Volte a King Spa 2 de 13 e 14/11 para piso R$ 2.052" |
| 4c, um degrau para baixo | King Spa 2 08–12/11 (→ R$ 897), 15–18/11 (→ R$ 801), 22–26/11 (→ R$ 772), 27/11 (→ R$ 1.619), 28/11 (→ R$ 1.810), 04–05/12 (→ R$ 1.816), 06–10/12 (→ R$ 912), 11–12/12 (→ R$ 1.908) | R$ 1.056; 890; 858; 1.905; 1.905; 1.905; 960; 2.008 | gravado, pisos iguais aos alvos (não conferido) | "Apague as regras da King Spa 2 de 08/11 a 12/12" |
| Regra G nos 81 dias lidos: piso e teto 10% abaixo da suíte com banheira livre mais barata (preço novo das suítes) | Balcony 18–20/10 (R$ 770), 21–22/10 (R$ 690), 23–24/10 (R$ 1.119), 25–26/10 (R$ 765), 27–29/10 (R$ 690), 02/11 (R$ 1.463), 03–04/11 (R$ 744/832), 06–07/11 esgotadas (teto R$ 2.084/2.889), 08–12/11 (R$ 808), 13–14/11 (R$ 1.752), 15–18/11 (R$ 721), 20–21/11 (R$ 2.243, estava acima da VK2), 22–26/11 (R$ 695), 27–28/11 (R$ 1.457/1.629), 29/11–03/12 (R$ 806), 04–05/12 (R$ 1.634), 06–10/12 (R$ 821), 11–12/12 (R$ 1.717), 13–17/12 (R$ 904), 18–19/12 (R$ 1.724; estava **fixo R$ 2.440**, virou percentual), 20–24/12 (R$ 2.565), 25–26/12 (R$ 3.045), 27–31/12 (R$ 2.755), 01–02/01 (R$ 3.382) | R$ 765; 765; 963; 780/770; 696; 1.541; 845; 2.672/3.512; 906; 1.714; 765; 2.544; 798; 1.714; 827; 1.784; 940–1.070; 1.905; 1.284; 2.440; 3.714; 3.714; 4.781; 4.781 | gravado (não conferido: Balcony sem recálculo até o envio da tarde) | "Volte as regras da Balcony de 13/10" (lista no rascunho `payload_1410.json`) |
| Natal e Réveillon: piso de feriado novo (R$ 1.000 reais), 2 noites em 24–25/12 (eram 3) e 3 noites de 29/12 a 1/1 | Queen 7, Double e Afrodite (R$ 2.381), Queen 2 (R$ 2.941), VK7 e King Spa 2 (R$ 2.564), Balcony (R$ 1.887 com os tetos acima) | sem regra; preços naturais R$ 2.091 a 5.488 | pisos gravados; Afrodite e Queen 2 ✅ com 2 e 3 noites | "Apague as regras de 24 a 31/12 e 1/1 dos 7 quartos" |

- **Conferido no recálculo (12:00):** Afrodite e Queen 2, 38 itens ✅, 0 diferenças (inclusive 2 noites em 24–25/12 e 3 de 29/12 a 1/1). **Não conferido (limite de recálculo, janela móvel de 24 h):** Queen 7, Double, VK7, King Spa 2 e Balcony; as 232 regras voltaram da API iguais ao enviado e sem validade; a conferência fica para a rotina das 13:53 (envio da tarde de Double, Queen 2, VK7 e Balcony) e das 16:53.
- **Sync Now (D0 a D1):** Queen 7 (15/10 a R$ 640; o envio das 09:52 não saiu hoje), e também Afrodite e King Spa 2 (sem envio hoje). Sem ele, o que gravei hoje só chega no próximo envio.
- **Pendências suas (nesta ordem):** (1) Sync Now dos 7 quartos; (2) confirmar a base dos R$ 1.000 do padrão de qualidade; (3) Beds24: tarifa da Queen 2 e canais da Villa. Suavização da Balcony na tela continua pendente.
- **Aprendi:** janela móvel de 24 h do recálculo (a Balcony deu 429 às 11:25 por causa dos 3 de ontem); envio das 09:52 não é garantido; vitrine do Recanto 0,63 em dia útil (R$ 403 ÷ 640); remanejamento no Beds24 aparece como −1/+1 entre quartos. Detalhes em `aprendizados.md`.

## 14/10/2026, 18:40 — Pedido do dono: metas de faturamento por pousada (baixa R$ 150/220 mil; alta R$ 300/350 mil) e modo por faturamento

- **Decisão sua:** baixa temporada Villa R$ 150 mil e Recanto R$ 220 mil; alta temporada Villa no mínimo R$ 300 mil e Recanto R$ 350 mil. Li como faturamento **mensal por pousada** (alta = dezembro e, no inverno, junho a agosto; os outros meses são baixa pelo calendário de demanda).
- **Leitura contra o modelo (novembro e dezembro):** Villa baixa R$ 150 mil cabe no realista (R$ 162 mil); Villa alta R$ 300 mil fica entre o realista (R$ 257 mil) e o máximo (R$ 308 mil); Recanto baixa R$ 220 mil é igual ao máximo (realista R$ 183 mil; setembro fez R$ 182 mil); Recanto alta R$ 350 mil passa do máximo (R$ 312 mil). Para o Recanto chegar lá precisa de ocupação e diária subindo juntas (60% a R$ 765 reais na baixa; 70% a R$ 1.010 na alta).
- **O que mudou na estratégia:** modo por faturamento por pousada (`quartos.json` → `modo`): toda segunda a revisão compara o livro do mês com os níveis (mínimo seu, realista, máximo) no ritmo esperado e grava volume / padrão / preço; a flutuação por faixas passa a usar a classe baixa (volume) ou alta (preço) em todos os quartos da pousada. Travas inalteradas. Hoje as duas pousadas estão em "padrão" até a revisão de segunda (19/10).
- **Nada gravado no PriceLabs nesta rodada.** Documento: `pricelabs/metas-de-faturamento-2026-2027.md`, seção 6.

## 14/10/2026, 17:30 — Regra nova do dono (tudo em percentual): 332 regras convertidas; rotinas leves das 13:53 e 16:53

- **Regra sua (14/10):** "Trabalhe sempre com percentuais nunca com valores absolutos no pricelabs." Nada por data vai mais em reais: o preço já era percentual desde 07/10 e agora o **piso e o teto também** são variação % sobre a base do anúncio. Base, mínimo e máximo do anúncio continuam em reais, porque são a âncora dos percentuais.
- **Como o PriceLabs lê o percentual (medido hoje):** na King Spa 2 (base R$ 1.270), piso −39% virou R$ 775 e piso 61% virou R$ 2.045. É variação sobre a base, igual ao preço. A base de conhecimento do PriceLabs dizia o contrário e errou. O piloto da Queen 7 em 15/10 ficou 34 minutos com piso de base +69% (R$ 1.982): corrigi às 17:01 para −31% (R$ 809), antes de qualquer cálculo ou envio. O envio das 17:16 já levou R$ 809.
- **Conversão (17:09 a 17:20):** 343 regras por data lidas nos 7 quartos; 332 tinham piso ou teto em reais e passaram para percentual (410 campos), sem mexer em preço, estadia ou motivo. Piso arredonda para cima e teto para baixo, nunca abaixo do piso: cada piso subiu no máximo 1% da base (R$ 11 a R$ 19; ex.: Queen 7 R$ 800 → R$ 809, VK7 R$ 850 → R$ 858, Balcony R$ 690 → R$ 698). Conferência antes × depois nos 7 quartos: 0 erros, 0 "fixed" restante. Verificador independente por quarto (releitura às 17:30 e cruzamento com a gravação das 11:45): Queen 7, Double, Afrodite, Queen 2 e VK7 ✅; Balcony e King Spa 2 ✅ fora das 5 datas que regravei de propósito às 17:24 na rotina leve (Balcony 14, 15, 19 e 20/10; King Spa 2 20/10), que ele apontou como mudança posterior. O PriceLabs recalculou e enviou os 7 quartos às 17:16 (VK7 e Balcony recalcularam às 17:19 já com os percentuais).

| Quarto | Regras lidas | Convertidas | Maior subida do piso |
|---|---|---|---|
| Queen 7 | 41 | 38 | R$ 11 |
| Double | 52 | 51 | R$ 14 |
| Afrodite | 32 | 31 | R$ 19 |
| Queen 2 | 18 | 15 | R$ 18 |
| VK7 | 61 | 60 | R$ 12 |
| Balcony | 80 | 80 (piso e teto) | R$ 12 |
| King Spa 2 | 59 | 57 | R$ 12 |

- **Rotina das 13:53 (leve):** vendas Queen 7 15/10 (R$ 332 reais, a R$ 800), Queen 7 24–25/10 (R$ 898), Queen 7 31/12 a 02/01 (R$ 4.219 por 3 noites, cerca de 0,73 do enviado), Double 11/11 ×2 (R$ 616 cada), Double 14–15/10 (R$ 542) e Balcony 14/10 (R$ 300, a R$ 842); cancelamento Queen 7 14/10. Os 7 quartos foram enviados às 12:08. Decisões: Queen 7 15/10 passou de 70% vendido e voltou ao mínimo pelo piso; Double 11/11 +10% pelo piso. As duas ficaram para depois da conversão (o pedido em reais foi recusado por você) e foram gravadas em percentual nesta rodada.
- **Rotina das 16:53 (leve, às 17:15):** lista de reservas sem nada novo (71 registros; última venda às 12:43). Calendário: Double 3 de 6 em 14/10 e 2 de 6 em 15/10 (eram 4 e 3); Queen 2 esgotou 15 e 16/10; King Spa 2 esgotou 18/10 e vendeu 1 em 19/10; Queen 7 6 de 7 em 15/10 e reabriu 1 unidade em 16 e 17/10 (agora R$ 1.310); Balcony 0 de 6 em 14/10 com a reserva de R$ 300 na lista (provável remanejamento no Beds24). Sem reserva nova na lista para essas noites: parte é remanejamento.
- **Plano:** 9 itens validados (0 erros), gravados pela API às 17:24 em 3 chamadas (todas 200). O PriceLabs recalculou sozinho 26 segundos depois e os 9 valores bateram.

| Mudança | Onde | Antes | Depois (conferido no recálculo das 17:25) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Regra I (D0 e D1): VK7 e King Spa 2 esgotadas, a Balcony é o único quarto livre da Villa (6 e 5 de 6 livres) | Balcony 14 e 15/10 | piso e teto R$ 842 | piso e teto −50% = **R$ 635** ✅ | "Volte a Balcony de 14 e 15/10 para piso e teto −33%" |
| Regra G: 10% abaixo da suíte livre mais barata | Balcony 19/10 (King Spa 2 a R$ 811) e 20/10 (King Spa 2 a R$ 775) | R$ 775 | −42% = **R$ 737** e −45% = **R$ 698** ✅ | "Volte a Balcony de 19 e 20/10 para −39%" |
| 4c: atrás da meta (0 de 2 a D6), −10% leva ao mínimo; teto só nesta noite | King Spa 2 20/10 | R$ 811 | piso e teto −39% = **R$ 775** ✅ | "Tire o teto da King Spa 2 de 20/10" |
| 4c (alta): 15/10 caiu para 2 de 6 em D1, −10% pelo teto (14/10 em D0 fica) | Double 15/10 | R$ 1.583 | teto −4% = **R$ 1.421** ✅ | "Tire o teto da Double de 15/10" |
| 4c (alta): à frente da meta (5 de 6), +10% pelo piso | Double 18/10 | R$ 903 | piso −32% = **R$ 1.006** ✅ | "Volte a Double de 18/10 para piso −39%" |
| 4c (alta): na meta, +5% pelo piso | Double 22/10 | R$ 903 | piso −35% = **R$ 962** ✅ | "Volte a Double de 22/10 para piso −39%" |
| 4c (alta, rotina das 13:53): 11/11 de 1 para 3 de 6, +10% pelo piso (igual a 09 e 10/11) | Double 11/11 | R$ 1.252 | piso −6% = **R$ 1.391** ✅ | "Tire o piso da Double de 11/11" |
| Regra I (rotina das 13:53): 15/10 passou de 70% vendido, volta ao mínimo | Queen 7 15/10 | piso R$ 640 | piso −31% = **R$ 809** ✅ (enviado às 17:16) | "Volte a Queen 7 de 15/10 para piso −45%" |

- **Envio:** o que gravei às 17:24 ainda não chegou aos canais (o calendário mostra o preço novo, os canais o antigo). Hoje a gravação pela API foi seguida de recálculo em segundos e de envio em 6 a 23 minutos (12:08 depois das gravações das 11:45; 17:16 depois das de 17:10); às 17:27 o último envio da Balcony ainda era o das 17:16. Se até a rotina das 19:53 o envio não tiver saído, Balcony 14 e 15/10 e Double 15/10 dependem do Sync Now.
- **Pendências:** Natal e Réveillon na janela nova (noites 26/12, 29/12 e 02/01 com piso de feriado; 02/01 com 3 noites) ficam para a rotina completa de 15/10; os preços naturais dessas noites já passam do piso.
- **Aprendi:** percentual do piso é variação sobre a base; piso em percentual acompanha a base (mudar a base muda todos os pisos por data); gravação pela API dispara recálculo em segundos. Detalhes em `aprendizados.md`.

## 14/10/2026, 18:00 — Decisão sua: janeiro é alta temporada

- **Você disse:** "Sim janeiro é alta." Janeiro de 2027 passa a ter a meta da alta: Recanto R$ 350 mil e Villa R$ 300 mil no mês, como dezembro.
- **O que mudou:** metas (`metas_faturamento.py`, `metas_faturamento.json` e o documento `pricelabs/metas-de-faturamento-2026-2027.md`, seções 3 e 6) e `regras.md` 6a. A temporada da alta destes 6 meses agora é dezembro e janeiro; baixa é novembro, fevereiro e março; outubro e abril são transição.
- **O que não mudou:** o calendário de demanda de cada noite (vem dos dados do mercado e vê as semanas de janeiro como vale) e as travas. O preço de cada noite segue a demanda; a sua temporada define a meta do mês e, pelo modo por faturamento, se a pousada trabalha para vender volume ou diária.
- **Leitura:** pelo mercado do ano passado, janeiro tem realista de R$ 206 mil no Recanto e R$ 192 mil na Villa; a sua meta fica 45% e 30% acima do máximo. Livro de janeiro hoje: R$ 26 mil.
- **Nada gravado no PriceLabs.**

## 14/10/2026, 18:20 — Decisão sua: metas mantidas, de olho na lotação

- **Você disse:** "Sim. Esse é o objetivo. Vamos trabalhar para isso. Porém devemos sempre ficar de olho na lotação." As metas ficam como estão, inclusive o Recanto R$ 350 mil na alta, acima do máximo calculado.
- **Trava de lotação nova (`regras.md` 4c):** noite atrás da meta de ocupação da janela (7 dias 70%, 15 dias 50%, 30 dias 35%) desce um degrau em qualquer modo; o modo preço só vale com a pousada na meta de ocupação em pelo menos 2 das 3 janelas; a rotina completa confere todo dia e rebaixa para padrão quando falhar; o relatório abre com a ocupação antes do faturamento.
- **Nada gravado no PriceLabs.** Hoje as duas pousadas estão em modo padrão.

## 14/10/2026, 18:00 — Pedido seu: "Vamos executar agora" (modo por faturamento com a trava de lotação e pisos de Natal e Réveillon)

- **Modo por faturamento decidido agora** (em vez de segunda): **Recanto em padrão** (livro de outubro R$ 130 mil, acima do ritmo do realista e abaixo do ritmo da sua meta, que hoje seria R$ 139 mil). **Villa em padrão pela trava de lotação**: o livro (R$ 108 mil) já está no ritmo da sua meta e daria modo preço, mas a ocupação está atrás em 7 dias (67% contra 70%) e 15 dias (47% contra 50%). Gravado em `quartos.json` → `modo` e `modo_decisao`.
- **Flutuação:** sem passo novo hoje; todas as noites já levaram o passo do dia nas rotinas de 12:10 e 17:24 (um passo por noite por dia).
- **Pisos de Natal e Réveillon na janela nova** (19 itens validados, 0 erros; 7 chamadas, todas 200 e devolvidas iguais):

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de feriado R$ 1.000 reais (mesmo percentual de 24/12) | Queen 7 (+103%), Double (+61%), Afrodite (+20%), Queen 2 (+58%), VK7 (+74%), King Spa 2 (+102%) em 26/12, 29/12 e 02/01 | sem piso | piso em % da base (R$ 2.381 a R$ 2.955); preços naturais de hoje (R$ 2.651 a R$ 5.488) acima | "Tire os pisos de 26/12, 29/12 e 02/01" |
| Estadia: Natal 2 noites; Réveillon 3 noites | 26/12 (2), 29/12 e 02/01 (3) nos 6 quartos; Balcony 02/01 de 2 para 3 | 2 ou sem regra | 2 e 3 noites | "Volte 02/01 para 2 noites" |

- **Conferência:** a API devolveu as 19 datas iguais ao enviado; o calendário ainda não tinha recalculado às 18:00 (conferir na rotina das 19:53).

## 14/10/2026, 20:00 — Pedido seu: suítes com banheira de domingo a quinta a partir de R$ 500 no preço final (e rotina leve das 19:53)

- **Você pediu:** "Vamos ajustar os valores das suítes com banheira para dia de semana, no mínimo de R$500. Preço final nas plataformas. Ajuste isso percentualmente para podermos termos a flutuação de preço natural do Price Labs."
- **Como li:** as 6 suítes com banheira (a Balcony não), noites de domingo a quinta fora de feriado; "preço final" = vitrine da Booking, a mais descontada. Piso enviado = R$ 500 ÷ 0,63 = **R$ 794 no Recanto** e R$ 500 ÷ 0,53 = **R$ 943 na Villa**. Piso em percentual da base; o preço continua flutuando acima. Vale também para o corte de última hora.
- **Quem mudou:** o Recanto já estava no piso (Queen 7 R$ 800 = R$ 504 na vitrine; Double, Afrodite e Queen 2 acima); só a Queen 7 de hoje (R$ 645 pelo corte de última hora) subiu. Na Villa, VK7 (R$ 850–858) e King Spa 2 (R$ 765–811) estavam em R$ 405 a R$ 455 na vitrine.

| Mudança | Onde | Antes | Depois (conferido) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de dia de semana R$ 500 no preço final | VK7, 32 noites de 18/10 a 10/12 | piso R$ 850–858 | piso −36% = **R$ 947** ✅ (recálculo das 20:01) | "Volte os pisos de semana da VK7 para −42%" |
| Idem | King Spa 2, 50 noites de 14/10 a 28/12 (tetos de 20 a 22/10 saem) | R$ 765–811 | piso −25% = **R$ 953** (API devolveu igual; recálculo no limite) | "Volte os pisos de semana da King Spa 2 para −39%" |
| Idem (corte de última hora de hoje) | Queen 7 14/10 | piso R$ 645 | piso −32% = **R$ 798** | "Volte a Queen 7 de 14/10 para −45%" |
| Mínimo do anúncio (âncora) | VK7; King Spa 2 | R$ 850; R$ 765 | **R$ 944**; **R$ 879** (+15%, limite da semana; R$ 944 na próxima) | "Volte o mínimo da VK7 e da King Spa 2" |
| Regra G: Balcony 10% abaixo da suíte livre mais barata | Balcony, 43 noites de 19/10 a 28/12 | R$ 698–775 (out/nov); R$ 1.070–4.781 em 14 noites de dezembro, acima das suítes | **R$ 864** na maior parte; dezembro R$ 864 a R$ 2.578 ✅ (recálculo das 20:01, 43 de 43) | "Volte a Balcony para os pisos de antes" |

- **Atenção:** a Balcony está fraca (33% em 7 dias) e subiu de R$ 698–775 para R$ 864 nos dias de semana por causa da Regra G. Nas suítes de dezembro (22, 23, 27 e 28/12), o piso da King Spa 2 ficou no preço atual dela (R$ 3.003 a 3.089) para a Balcony seguir 10% abaixo.
- **Rotina das 19:53 (leve):** nenhuma reserva nova desde as 12:43; a Balcony 14 e 15/10 a R$ 635 já está nos canais (envio depois das 17:24).
- **Plano:** 128 itens validados (0 erros), gravados em 6 chamadas de datas e 1 de anúncio, todas 200, 0 divergências.

## 14/10/2026, 20:45 — Pedido seu: suítes com banheira em sexta e sábado a partir de R$ 850 no preço final

- **Você pediu:** "Para o fim de semana a diária. Mínima pra sweet, com banheira, deve ser na faixa de R$ 850,00". Li como preço final nas plataformas (vitrine da Booking), como o pedido de R$ 500 nos dias de semana.
- **Piso enviado:** R$ 850 ÷ 0,61 = **R$ 1.394 no Recanto**; R$ 850 ÷ 0,66 = **R$ 1.288 na Villa**. Em percentual da base; o preço flutua acima. O antigo padrão de R$ 1.000 enviados dava R$ 610 a R$ 660 na vitrine; ele fica só nas noites de feriado (que têm pisos próprios mais altos). Isso fecha a dúvida pendente sobre a base do padrão.
- **Plano:** 27 noites (0 erros), 5 chamadas, todas 200 e devolvidas iguais. Os outros fins de semana até 02/01 já estavam acima (pisos ou preços maiores, ou o piso de 150% da base sem regra na data).

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de fim de semana R$ 850 no preço final | Queen 7 16, 17, 23 e 24/10, 06, 07, 13 e 14/11, 04 e 05/12 | piso R$ 1.009 (preços R$ 1.144 a 1.784) | piso +19% = **R$ 1.396**; sobe hoje 16, 17, 23 e 24/10 | "Volte os pisos de sexta e sábado da Queen 7 para −14%" |
| Idem | Double 23 e 24/10, 06, 07, 13 e 14/11 | piso R$ 1.006 | piso −5% = **R$ 1.406** (preços já acima) | "Volte a Double para −32%" |
| Idem | VK7 23 e 24/10, 06 e 07/11 | piso R$ 1.006–1.243 | piso −12% = **R$ 1.302**; sobe 23 e 24/10 (eram R$ 1.243) | "Volte a VK7 para −16%" |
| Idem | King Spa 2 16, 17, 23 e 24/10, 06 e 07/11 | piso R$ 1.003–1.283 | piso +2% = **R$ 1.295** (noites esgotadas ou preço acima) | "Volte a King Spa 2 para −21%" |
| Regra G: proteção da Balcony esgotada | Balcony 17/10 | piso R$ 1.270, teto R$ 1.397 | piso e teto +18% = **R$ 1.499** (10% abaixo da VK7 livre) | "Volte a Balcony de 17/10" |

- **Atenção à lotação:** a Queen 7 em 23/10 tem 1 de 7 vendida e em 24/10 tem 3 de 7, e o preço sobe de R$ 1.144 para R$ 1.396. A partir de agora a flutuação não desce abaixo desse piso; se o fim de semana não vender, a rotina avisa, mas não corta abaixo dos R$ 850 no preço final sem ordem sua.

## 14/10/2026, 21:10 — Lembrete seu: correções sempre em percentual

- **Você disse:** "Lembre-se tem ser correções percentuais". Todas as regras por data de hoje já estavam em percentual da base. A exceção era o mínimo do anúncio da VK7 e da King Spa 2, que eu tinha subido em reais na rodada das 20:00.
- **O que fiz:** primeiro gravei 168 pisos por data em percentual nas noites de domingo a quinta, fora de feriado, que dependiam só do mínimo do anúncio. São 90 na VK7, de 05/11/2026 a 29/04/2027, e 78 na King Spa 2, de 03/01 a 29/04/2027. O piso é −36% na VK7, R$ 947, e −25% na King Spa 2, R$ 953. Em 20 a 23/12 e 27 e 28/12, a VK7 ficou no preço atual dela, para a Balcony seguir 10% abaixo. Depois voltei o mínimo do anúncio a R$ 850 e R$ 765. As 7 chamadas deram 200, sem divergência, e nenhuma dessas datas tinha regra antes.
- **Trava nova:** o validador barra qualquer mudança de base, mínimo ou máximo do anúncio sem ordem sua. A partir de agora, toda correção é percentual por data.
- **Pendência:** no dia 1 de cada mês, a rotina estende esses pisos por mais um mês.

| Mudança | Onde | Antes | Depois | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de R$ 500 no preço final em % por data | VK7 90 noites; King Spa 2 78 noites, até 29/04/2027 | mínimo do anúncio R$ 944 / R$ 879 | piso −36% / −25% da base | "Apague os pisos de semana de 2027 da VK7 e da King Spa 2" |
| Mínimo do anúncio de volta | VK7; King Spa 2 | R$ 944; R$ 879 | R$ 850; R$ 765 | "Suba o mínimo do anúncio" (só com ordem sua) |

## 15/10/2026, 08:40 — Rotina das 07:53 (completa)

- **Coleta completa** às 07:55 (preços até 13/12, reservas desde 13/10, logs, envio, mercado e concorrentes em 5 conjuntos de datas). Calendário dos 7 quartos de 21:05 de ontem.
- **Reservas:** nenhuma nova desde 12:43 de ontem (a lista atualiza por volta das 10:59).
- **Mudança manual pela tela (log via web, não API):** às 21:03 de ontem, o mínimo do anúncio da VK7 subiu de R$ 850 para R$ 944 e o da King Spa 2 de R$ 765 para R$ 879, depois de eu ter voltado os dois. Tratei como decisão sua e mantive (`quartos.json` atualizado). Se não foi você, avise que eu volto.
- **Envio:** os 7 quartos foram enviados às 21:05 de ontem.
- **Ocupação (7/15/30 dias):** hotel 66%, 45%, 39%; Recanto 69%, 48%, 38%; Villa 62%, 43%, 39%. A Balcony está fraca, com 36%, 20% e 26%.
- **Classes:** a King Spa 2 passou para demanda alta, repetindo o critério nas duas rotinas completas (15 dias 70%, 30 dias 50%). O modo das duas pousadas segue padrão: a trava de lotação falha nas duas, com só a janela de 30 dias na meta.
- **Concorrentes (Booking):** segunda 19/10, mediana R$ 272; Recanto R$ 532, Villa R$ 457. Fim de semana de 23 a 25/10, mediana R$ 948 em 2 noites; Recanto R$ 1.696, Villa R$ 1.475. Finados, mediana R$ 2.735 em 3 noites; Recanto R$ 4.144, Villa R$ 4.395. Com os seus pisos de R$ 500 e R$ 850 no preço final, as suítes ficam bem acima da mediana.
- **Plano:** 28 itens validados (0 erros), em 6 chamadas, todas 200 e devolvidas iguais. A Balcony foi conferida no recálculo, 13 de 13; nos outros quartos o recálculo automático não veio, então confiro na rotina das 10:53.

| Mudança (flutuação por faixas, tudo em %) | Onde | Antes | Depois |
|---|---|---|---|
| +10% à frente da meta | Queen 7 18/10 (6 de 7) | R$ 891 | piso −16% = R$ 985 |
| +5% e +10% | Double 19 a 22/10 e 29/10 | R$ 947–1.347 | pisos −32% a +1% = R$ 1.006 a 1.495 |
| −10% pelo teto, atrás da meta (1 de 6) | Double 23/10 | R$ 1.733 | teto +5% = R$ 1.554 |
| +5% | Double 24/10 | R$ 1.733 | piso +23% = R$ 1.820 |
| −15% e −5% | Afrodite 23/10 (teto) e 02/11 (piso) | R$ 2.447; R$ 2.200 | R$ 2.080; R$ 2.100 |
| +10% e −5% | VK7 20/10 (6 de 7); VK7 02, 03 e 04/11 | R$ 947; R$ 1.806, 992, 1.110 | R$ 1.050; R$ 1.717, 947, 1.066 |
| −5% | King Spa 2 02/11 | R$ 1.638 | R$ 1.562 |
| Regra I em D3, sem suíte livre | Balcony 18/10 (6 de 6 livres) | R$ 775 | R$ 635 |
| −10%, até o limite da Regra G | Balcony 19 a 22/10, 25 a 29/10, 03 e 04/11 | R$ 838–864 | R$ 813–826 |
| −15%, até o limite da Regra G | Balcony 02/11 | R$ 1.473 | R$ 1.410 |

- **Não mexi:**
  - **Noites de hoje:** não sobem com só horas de venda (Queen 7, Double e Queen 2).
  - **Cortes de dia de semana da VK7 e da King Spa 2:** travados no seu piso de R$ 500 no preço final.
  - **VK7 em 23 e 24/10, +10%:** obrigaria a Balcony, com 5 de 6 livres, a subir 9% pela Regra G. Segurei pela trava de lotação.
  - **Queen 7 em 23/10, −10%:** travado no piso de R$ 850 no preço final.

## 15/10/2026, 11:05 — Rotina das 10:53 (leve)

- **Reservas e cancelamentos:** nenhum novo desde ontem às 12:43 (41 registros desde 13/10). **Mudanças manuais:** nenhuma nova.
- **Envio do dia:** ainda não saiu; o último envio dos 7 quartos foi às 21:05 de ontem. Os ajustes das 08:20 não chegaram aos canais. Nenhum deles é de hoje ou amanhã: o mais próximo é a Balcony em 18/10 (D3).
- **Conferência no recálculo:** Double 7 de 7 ✅; Balcony 13 de 13 ✅ (às 08:07). VK7 e Afrodite não conferidas: o PriceLabs respondeu "Beds24 appears to be temporarily unavailable or under high load". Queen 7 e King Spa 2 estão no limite de recálculo. A API devolveu iguais as 28 regras gravadas.
- **Nada gravado nesta rodada.**

## 15/10/2026, 14:05 — Rotina das 13:53 (leve)

- **Lote de reservas** importado às 11:05: 27 novas e 3 cancelamentos ou trocas de quarto. Os destaques:
  - **Queen 7:** esgotou de 15 a 18/10. Vendeu também 30/12 a 01/01 por R$ 4.466, 04 a 07/11 por R$ 2.345, 06 a 09/11 (2 reservas, R$ 1.776 e R$ 1.783), 21 e 22/10, 25/10 e até 01 a 03/08/2027.
  - **Double:** 19, 20 e 22/10; 05 e 06/12 (R$ 1.863); 07 e 08/12. O dia 20/10 esgotou.
  - **VK7:** 20 a 24/10 pelo canal "outros" (R$ 2.200), 01/11, 08/11 e 22 a 24/11.
  - **King Spa 2:** 18 e 19/10, 08/11 e 15/11.
  - **Trocas de quarto:** a reserva da Afrodite de 16 e 17/10 veio da Queen 7, e a da Balcony de 14/10 passou para a VK7.
- **Mudança sua pela tela:** às 12:19 e 12:26, Queen 7 e Double em 16/10 ficaram com preço, piso e teto **fixos de R$ 10.000**. As duas estão esgotadas nessa noite. Respeitei e não mexi.
- **Envio:** Queen 7 e Double foram enviadas às 12:26, junto com a sua mudança. **Afrodite, Queen 2, VK7, Balcony e King Spa 2 seguem sem envio hoje** (último às 21:05 de ontem), e os ajustes das 08:20 desses quartos ainda não estão nos canais. O mais próximo é a Balcony em 18/10, a R$ 635 (D3).
- **Recálculo** às 13:51: os 7 quartos foram recalculados pelo PriceLabs.
- **Nada gravado:** as noites que venderam já levaram o passo de hoje (um passo por noite por dia), e os cortes da VK7 e da King Spa 2 seguem travados no piso de R$ 500 no preço final.

## 15/10/2026, 17:00 — Rotina das 16:53 (leve)

- Nenhuma reserva, cancelamento ou mudança manual nova desde as 13:53; ocupação igual. Nada gravado.
- **Envio:** Afrodite, Queen 2, VK7, Balcony e King Spa 2 seguem sem envio desde as 21:05 de ontem (o recálculo das 14:30 não enviou). Os ajustes das 08:20 desses quartos não estão nos canais. Sync Now pedido de novo.

## 15/10/2026, 20:00 — Rotina das 19:53 (leve)

- **Envio:** os 7 quartos foram enviados às 18:09. Com isso, os ajustes das 08:20 (Afrodite, Queen 2, VK7, Balcony e King Spa 2) chegaram aos canais.
- **Calendário:** só trocas de quarto, sem reserva nova na lista: Queen 7 −1 e Queen 2 +1 em 15/10; Double +1 em 19/10 e −1 em 20/10; VK7 +1 em 20/10; Balcony −1 em 15/10 (6 de 6 livres hoje, a R$ 635 pela Regra I).
- **Nada gravado:** a Queen 7 em 15/10 (1 livre, R$ 809) já está no piso de R$ 500 no preço final; as outras noites já levaram o passo de hoje.

## 16/10/2026, 08:25 — Rotina das 07:53 (completa)

- **Coleta completa** às 07:55 (preços até 14/12, reservas desde 14/10, logs, envio, mercado e concorrentes em 5 conjuntos de datas). **O envio e o recálculo da madrugada não aconteceram**: o último envio dos 7 quartos foi ontem às 18:10. Recalculei os 7 quartos às 08:00. Seis dos sete falharam com erro vazio quando disparei juntos; um por vez deu certo.
- **Reservas:** nenhuma nova nem cancelamento desde ontem às 19:53. **Mudanças manuais:** nenhuma nova (seguem as de 16/10 da Queen 7 e Double a R$ 10.000, esgotadas).
- **Ocupação (7/15/30 dias):** hotel 63%, 45%, 40%; Recanto 70%, 49%, 42%; Villa 55%, 41%, 39%. Suítes com banheira 70%, 52%, 44%. Balcony 33%, 20%, 25%.
- **Modo por faturamento:** segue padrão. O Recanto cumpre a trava de lotação (2 de 3 janelas), mas o livro de outubro não chegou à sua meta; a Villa não cumpre. Só a revisão de segunda (19/10) muda o modo.
- **Concorrentes (Booking), mediana contra o nosso preço:**
  - **Segunda 19/10, 1 noite:** mediana R$ 313; Recanto R$ 532, Villa R$ 430.
  - **Quarta 21/10, 1 noite:** mediana R$ 313; Recanto R$ 532, Villa R$ 430.
  - **23 a 25/10, 2 noites:** mediana R$ 840; Recanto R$ 1.651, Villa R$ 1.196.
  - **Finados, 3 noites:** mediana R$ 2.496; Recanto R$ 4.312, Villa R$ 4.395.
  - **Mercado:** o fim de semana de 23 e 24/10 está com 17% e 16% de ocupação, quase metade do ano passado.
- **Plano:** 12 itens validados (0 erros), em 5 chamadas, todas 200 e devolvidas iguais. O calendário de 08:04 ainda não refletia a gravação; confiro na rodada das 10:53.

| Mudança (flutuação por faixas, tudo em %) | Onde | Antes | Depois (piso/teto) |
|---|---|---|---|
| +10%, +5%, +5%, à frente da meta | Double 20, 21 e 22/10 | R$ 1.095; 1.051; 1.021 | piso −18%, −25%, −27% = R$ 1.214; 1.110; 1.080 |
| −9% pelo teto, limitado ao piso de R$ 850 no preço final | Double 23/10 (1 de 6) | R$ 1.554 | teto −5% = R$ 1.406 |
| +5% e +10% | Double 24/10 e 29/10 | R$ 1.820; 1.495 | piso +30% e +12% = R$ 1.924; 1.658 |
| +5% | Double 05/11 | R$ 1.231 | piso −9% = R$ 1.347 |
| −15% pelo teto, atrás da meta (0 de 1) | Afrodite 23/10 | R$ 2.080 | teto −12% = R$ 1.760 |
| −5% | VK7 02/11 e 04/11; King Spa 2 02/11; Balcony 02/11 | R$ 1.717; 1.066; 1.562; 1.410 | R$ 1.643; 1.021; 1.486; 1.346 |

- **Não mexi (e por quê):**
  - **VK7 e King Spa 2 de 19 a 29/10, dias de semana:** os cortes pararam no piso de R$ 500 no preço final.
  - **VK7 em 23 e 24/10, +10%:** a Regra G obrigaria a Balcony, com 1 e 2 de 6 vendidas, a subir 13%. Segurei pela trava de lotação.
  - **Queen 7 em 19 e 23/10 e Balcony de 19 a 29/10:** já no limite do piso do dono ou da Regra G.
  - **Finados (30/10 a 02/11):** melhorou desde 14/10 (Queen 7 de 0 para 1 de 7 em 30 e 31/10; Double 3 e 6 de 6; VK7 3 e 4 de 7). Não cortei o seu +20%. Reavalio em 20/10, a 10 dias.

## 16/10/2026, 11:00 — Rotina das 10:53 (leve)

- **Reservas, cancelamentos e mudanças manuais:** nenhum novo desde ontem. O lote das 11:05 ainda não tinha chegado ao PriceLabs; a lista atualiza por volta das 10:59.
- **Envio do dia:** ainda não saiu. O último envio dos 7 quartos foi ontem às 18:10, então as 12 mudanças das 08:20 e todo o calendário de hoje cedo não estão nos canais. Sync Now pedido de novo.
- **Conferência no recálculo:** a VK7 recalculada às 10:55 confirmou as 2 gravações (02/11 a R$ 1.643 e 04/11 a R$ 1.021). Os outros 10 ajustes da Double, Afrodite, King Spa 2 e Balcony voltaram iguais da API e ficam para a rodada das 13:53. O recálculo automático das 08:04 foi anterior às gravações.
- **Nada gravado nesta rodada.**

## 16/10/2026, 14:00 — Rotina das 13:53 (leve)

- **Envio do dia:** saiu. Os 7 quartos foram enviados às 11:26 (horário de Brasília) e o Sync Now não é mais necessário.
- **Conferência no calendário:** 11 das 12 gravações da manhã batem (Double 20, 21, 22, 23, 24 e 29/10 e 05/11; Afrodite 23/10; VK7 02 e 04/11; King Spa 2 02/11). A Balcony 02/11 aparece a R$ 1.346 contra R$ 1.334 esperados; é arredondamento do percentual gravado (piso e teto +6%), não divergência.
- **Reservas novas:** lote de 16 reservas, 25 noites e R$ 17,5 mil. Destaques: Finados na Queen 7 (30/10 a 01/11, R$ 3.310) e na Double (31/10 a 01/11, R$ 2.869), e Double 20 e 21/11 a R$ 2.476. Cancelamentos da Queen 7 em 16 e 17/10 foram repostos por novas reservas.
- **Balcony em 16 e 17/10:** 4 de 6 vendidas em cada noite. Estão na meta, mantive o preço.
- **Mudanças manuais suas:** nenhuma nova.
- **Nada gravado nesta rodada.**

## 16/10/2026, 17:00 — Rotina das 16:53 (leve)

- **Envio:** novo recálculo e envio dos 7 quartos às 15:27 (Brasília). Os preços variaram menos de 1% nas datas futuras; é a flutuação normal do PriceLabs dentro dos pisos.
- **Reservas e mudanças manuais:** nenhuma reserva nova desde as 13:53 e nenhuma mudança manual sua. Três cancelamentos da Queen 7 em 16/10 já estavam contabilizados.
- **Vagas que reapareceram na Villa:** VK7 liberou 1 unidade em 16 e 17/10 (agora 6/7 e 5/7) e a King Spa 2 liberou 1 em ambas (1/2). Os preços das duas desceram sozinhos para R$ 1.519 e R$ 1.439 em 17/10.
- **Gravado (1 mudança, Regra G):** Balcony 17/10 estava a R$ 1.499, mais cara que as suítes com banheira livres (R$ 1.519 e R$ 1.439). A proteção de R$ 1.499 tinha sido posta quando elas estavam esgotadas. Passou a R$ 1.295, 10% abaixo da mais barata livre, com piso e teto em +2% da base. A ocupação (4/6) segue na meta. Antes: piso +18%. Para desfazer: min_price +18% percent_base.
- **Conferência:** recálculo da Balcony às 16:58 mostra 17/10 a R$ 1.295 com estadia mínima 2. As demais datas da Balcony estão dentro da Regra G (varredura nos 60 dias).
- **Validador:** 0 erros e 0 avisos.

## 16/10/2026, 20:00 — Rotina das 19:53 (leve)

- **Reservas, cancelamentos e mudanças manuais:** nenhuma reserva nova desde as 16:53 (as 14 reservas feitas hoje já estavam contabilizadas) e nenhuma mudança manual sua. O log só mostra as gravações do robô.
- **Preços e ocupação:** sem variação nos 6 quartos recalculados às 15:27; só a Balcony foi recalculada de novo (19:58) por causa da minha gravação.
- **Envio:** o último envio aos canais foi às 15:27, antes da correção da Balcony 17/10. Nos canais ela ainda aparece a R$ 1.499; o PriceLabs já calcula R$ 1.295 e deve enviar no próximo ciclo. Sync Now no PriceLabs adianta, já que 17/10 é amanhã.
- **Regra G:** sem outros desvios. Finados e Regra H seguem nas pendências (20/10 e 21 a 23/10).
- **Nada gravado nesta rodada.**

## 17/10/2026, 11:40 — Rotina das 07:53 (completa, rodada até 11:40) e das 10:53

- **Ocupação (sábado 17/10):** hotel 54% em 7 dias, 43% em 15 e 38% em 30. Recanto 59%, 48% e 40%; Villa 50%, 38% e 36%. Fora da meta de 7 e 15 dias: Queen 7 (37% e 30%), VK7 (67% e 49%) e Balcony (17% e 16%). Double, Afrodite, Queen 2 e King Spa 2 na meta.
- **Reservas e mudanças manuais:** nenhuma reserva nova desde ontem à noite. Os 4 cancelamentos (Queen 7 em 16/10 e Airbnb da Balcony) já estavam contabilizados. Pelo calendário: cancelamento na Double 12/11 (3 para 2 de 6) e na King Spa 2 29/10 (0 para 1 de 2), e uma venda nova na King Spa 2 em 17/10, que esgotou. Nenhuma mudança manual sua além das de 15/10 (mínimo da VK7 R$ 944 e da King Spa 2 R$ 879, ainda a confirmar).
- **Envio:** o PriceLabs não enviou nada hoje cedo. O último envio aos canais foi ontem às 15:27, então a Balcony de hoje (R$ 1.295 calculado) ainda está a R$ 1.499 nos canais. Sync Now pedido ao dono.
- **Recálculos:** VK7 e Balcony bateram no limite de 3 recálculos em 24 h (erro 429), com calendário de 17 h. Pela regra da Skill, **sem passos de flutuação nesses dois quartos hoje** (4 itens da VK7 e 1 da Balcony ficaram de fora). Double, Queen 7, Afrodite, Queen 2 e King Spa 2 foram recalculadas.
- **Mercado (Booking, mediana da noite):** segunda 19/10 R$ 308; quarta 21/10 R$ 313; 17–18/10 R$ 540; 23–25/10 R$ 743; Finados 3 noites R$ 2.572. Ocupação do mercado em 23 e 24/10 de 17% e 16%, contra 25% e 36% há um ano.
- **Gravado (10 datas, conferido no recálculo, 0 diferença):**

| Mudança | Onde | Antes | Depois (recalculado e conferido às 11:37) | Como desfazer |
|---|---|---|---|---|
| Piso +10% (à frente da meta, 5 de 6) | Double 20/10 | R$ 1.214 | R$ 1.347 | pedir ao Claude (piso −9%) |
| Piso +5% (4 de 6) | Double 21/10 | R$ 1.110 | R$ 1.169 | piso −21% |
| Piso +5% (3 de 6) | Double 22/10 | R$ 1.080 | R$ 1.140 | piso −23% |
| Piso +5% (2 de 6), estadia 2 noites | Double 24/10 | R$ 1.924 | R$ 2.028 | piso +37% |
| Piso +10% (4 de 6) | Double 25/10 | R$ 1.288 | R$ 1.421 | piso −4% |
| Piso +10% (5 de 6) | Double 29/10 | R$ 1.658 | R$ 1.835 | piso +24% |
| Teto −15% (0 de 6, só esta noite) | Double 06/11 | R$ 2.549 | R$ 2.161 | teto +46% |
| Teto −10% (0 de 1, só esta noite) | Afrodite 23/10 | R$ 1.760 | R$ 1.580 | teto −21% |
| Teto −5% (0 de 1, só esta noite) | Afrodite 06/11 | R$ 4.058 | R$ 3.840 | teto +92% |
| Piso −5% (1 de 2) | King Spa 2 02/11 | R$ 1.486 | R$ 1.422 | piso +12% |

- **Barrei:** King Spa 2 em 20/10 (+10%) porque a Regra G obrigaria a Balcony, com 1 de 6 vendida, a subir.
- **Validador:** 0 erros e 0 avisos no plano final (a primeira versão deu 8 erros por calendário velho da VK7 e da Balcony e pela Regra G; corrigi tirando esses itens).

## 17/10/2026, 14:00 — Rotina das 13:53 (leve)

- **Envio:** o PriceLabs enviou os 7 quartos às 11:45 (Brasília), levando as gravações da manhã e a Balcony 17/10 a R$ 1.295. O Sync Now pedido às 11:40 não é mais necessário.
- **Reservas novas (8 desde a rodada anterior):** VK7 em 17/10 (R$ 2.600, canal "outros"), que esgotou a noite de hoje; King Spa 2 em 16/10, 20/10 e 29/10; Queen 7 em 18/10 (R$ 546); Double e Afrodite em 25/10 (R$ 1.053 e uma sem valor); VK7 em 12 a 14/01/2027 (R$ 1.485).
- **Cancelamentos pelo calendário da VK7:** 1 em 19/10, 2 em 20/10 e 1 em 21/10. A VK7 fica em 19/10 4 de 7, 20/10 5 de 7 e 21/10 1 de 7, todas no piso do dono de R$ 943 de dia de semana. Nada a baixar.
- **Mudanças manuais suas:** nenhuma nova (as 4 de 15/10 seguem registradas).
- **Balcony hoje (D0):** com a VK7 e a King Spa 2 esgotadas, a Balcony é a única livre da Villa (4 de 6 vendidas). Não apliquei a Regra I de R$ 630: a gravação de hoje só chegaria aos canais amanhã e a mediana da Booking para a noite é R$ 540, com a noite já 67% vendida.
- **Nada gravado nesta rodada.**

## 17/10/2026, 17:00 — Rotina das 16:53 (leve)

- **Reservas, cancelamentos e mudanças manuais:** nenhum novo desde as 13:53. O log só mostra as gravações do robô.
- **Preços e ocupação:** sem variação nos 7 quartos desde o envio das 11:45. A Afrodite só mudou o formato do dado (agora vem com a contagem 0/1 ou 1/1), sem venda nova.
- **Envio:** nenhum envio novo desde as 11:45; os 7 quartos seguem com envio ligado.
- **Recálculo:** não usei nenhum (saldo da VK7 e da Balcony esgotado por 24 h).
- **Nada gravado nesta rodada.**

## 17/10/2026, 20:00 — Rotina das 19:53 (leve)

- **Reservas, cancelamentos e mudanças manuais:** nenhum novo desde as 16:53. O log só mostra as gravações do robô.
- **Preços, ocupação e envio:** sem variação nos 7 quartos. O último envio segue o das 11:45.
- **Nada gravado nesta rodada.** A rodada de amanhã às 07:53 é a completa de domingo; segunda (19/10) vem a revisão semanal.

## 18/10/2026, 08:15 — Rotina das 07:53 (completa, domingo) e das 10:53

- **Ocupação (domingo 18/10):** hotel 48% em 7 dias, 43% em 15 e 36% em 30. Recanto 49%, 48% e 38%; Villa 48%, 39% e 34%. Fora da meta de 7 e 15 dias: Queen 7 (29% e 30%), VK7 (65% e 49%) e Balcony (14% e 18%); Double e Afrodite abaixo só em 7 dias. Queen 2 e King Spa 2 na meta.
- **Reservas e mudanças manuais:** nenhuma reserva nova na lista (21 reservas de 16 a 18/10, todas já conhecidas) e nenhuma mudança manual sua. Pelo calendário recalculado: VK7 esgotou em 23 e 24/10 (7 de 7), Balcony vendeu mais 1 em 24/10 (3 de 6), Queen 7 vendeu em 26 a 28/10, 31/10, 01/11, 13 e 14/11 e em 4 a 7/12 (2 de 7 em cada).
- **Envio:** sem envio novo de madrugada de novo (último ontem às 11:45). Os 7 quartos foram recalculados por mim às 08:00 e as gravações de hoje saem no próximo envio.
- **Mercado (Booking, mediana da noite):** segunda 19/10 R$ 272; quarta 21/10 R$ 272; 23–25/10 R$ 840 (2 noites); Finados 3 noites R$ 2.576. Ocupação do mercado em 19 e 20/10 de 19% e 15%.
- **Gravado (15 datas, conferido no recálculo às 08:08, 0 diferença):**

| Mudança | Onde | Antes | Depois | Como desfazer |
|---|---|---|---|---|
| Piso +10% (5 de 6) | Double 19/10 | R$ 1.006 | R$ 1.110 | piso −25% |
| Piso +10% (5 de 6) | Double 20/10 | R$ 1.347 | R$ 1.495 | piso +1% |
| Piso +5% (4 de 6) | Double 21/10 | R$ 1.169 | R$ 1.228 | piso −17% |
| Piso +5% (3 de 6) | Double 22/10 | R$ 1.140 | R$ 1.199 | piso −19% |
| Piso −15% (2 de 6), estadia 2 noites | Double 24/10 | R$ 2.028 | R$ 1.732 | piso +37% |
| Piso +10% (4 de 6) | Double 25/10 | R$ 1.421 | R$ 1.569 | piso +6% |
| Piso +10% (5 de 6) | Double 29/10 | R$ 1.835 | R$ 2.028 | piso +24% |
| Teto −15% (0 de 6, só esta noite) | Double 06/11 | R$ 2.161 | R$ 1.835 | teto +46% |
| Teto −10% (0 de 1) | Afrodite 23/10 | R$ 1.580 | R$ 1.500 | teto −21% |
| Teto −15% (0 de 1) | Afrodite 24/10 | R$ 2.342 | R$ 1.980 | teto sem limite (remover) |
| Teto −5% (0 de 1) | Afrodite 06/11 | R$ 3.826 | R$ 3.620 | teto +92% |
| Piso −5% (2 de 7) | VK7 02/11 | R$ 1.643 | R$ 1.569 | piso +12% |
| Piso −5% (2 de 7) | VK7 04/11 | R$ 1.021 | R$ 977 | piso −36% |
| Piso −5% (1 de 2) | King Spa 2 02/11 | R$ 1.422 | R$ 1.359 | piso +12% |
| Piso e teto −15% pela Regra G | Balcony 02/11 | R$ 1.346 | R$ 1.219 | piso e teto +6% |

- **Double 24/10:** ontem subi o piso 5% (R$ 1.924 para R$ 2.028) com 2 de 6 vendidas. Hoje a janela de 7 dias passou a cobrir a noite, a meta é 70% e a noite ficou na faixa "alto" contra o mercado: voltou 15% (R$ 1.732). Mantém o piso de R$ 1.394 de sábado.
- **Regra G:** varredura nos 60 dias sem desvio em dias comuns; a Balcony ficou mais de 5% abaixo do alvo só em feriados (Finados, 13 a 15/11, 19/11), onde a regra não vale.
- **Validador:** 0 erros e 1 aviso (estadia do sábado, já em 2 noites).

## 18/10/2026, 11:00 — Rotina das 10:53 (leve)

- **Envio:** o PriceLabs recalculou e enviou os 7 quartos às 10:28 (Brasília), levando as 15 gravações da manhã. O Sync Now pedido no relatório das 08:15 não é mais necessário.
- **Conferência no calendário:** as 15 gravações batem com o planejado (dentro de 1%): Double 19, 20, 21, 22, 24, 25 e 29/10 e 06/11; Afrodite 23 e 24/10 e 06/11; VK7 02 e 04/11; King Spa 2 02/11; Balcony 02/11.
- **Reservas e mudanças manuais:** nenhuma reserva nova na lista (21, todas conhecidas) e nenhuma mudança manual sua. Pelo calendário: Balcony vendeu 1 hoje (1 de 6), VK7 vendeu 1 em 28/10 e a King Spa 2 liberou 1 vaga hoje e amanhã (1 de 2 em 18 e 19/10), por cancelamento.
- **Regra G:** a King Spa 2 voltou a ter vaga hoje e amanhã, então a Balcony (R$ 635 hoje e R$ 813 amanhã) fica no limite do alvo. Não mexi: para hoje à noite a gravação só chegaria amanhã e para 19/10 a diferença é de R$ 1.
- **Nada gravado nesta rodada.**

## 18/10/2026, 14:00 — Rotina das 13:53 (leve)

- **Envio:** sem envio novo desde as 10:28; os 7 quartos seguem com envio ligado.
- **Reservas (lista atualizada, 33 de 16 a 18/10):** entraram na lista as vendas que o calendário já mostrava de manhã. Queen 7: lote da Booking de 17/10 à noite (17/10 R$ 907, 26 a 28/10 R$ 1.312, 31/10 R$ 2.143, 13/11 R$ 1.371 e 4 a 7/12 R$ 2.168, esta última aparece duas vezes e vai para a pendência de lotes duplicados do Beds24). VK7 28/10 R$ 563; Balcony hoje R$ 299; Afrodite 12 a 14/01/2027 R$ 2.373; King Spa 2 em 05/02/2027 R$ 2.809 e 19/10 R$ 291 (repôs o cancelamento do Airbnb). Double 18 a 20/10 no canal "outros" com valor R$ 1 (provável bloqueio, a conferir no Beds24).
- **Cancelamento:** King Spa 2, reserva do Airbnb de 18/10 (cancelada hoje), já refletida na vaga de hoje.
- **Mudanças manuais suas:** nenhuma. O log só mostra as gravações do robô.
- **Calendário e preços:** sem variação desde as 10:53.
- **Nada gravado nesta rodada.**

## 18/10/2026, 17:00 — Rotina das 16:53 (leve)

- **Reservas, cancelamentos e mudanças manuais:** nenhum novo desde as 13:53. O log só mostra as gravações do robô.
- **Preços, ocupação e envio:** sem variação em nenhum dos 7 quartos; o último envio segue o das 10:28.
- **Nada gravado nesta rodada.**

## 18/10/2026, 20:00 — Rotina das 19:53 (leve)

- **Reservas, cancelamentos e mudanças manuais:** nenhum novo desde as 16:53. O log só mostra as gravações do robô.
- **Preços, ocupação e envio:** sem variação nos 7 quartos; o último envio segue o das 10:28.
- **Nada gravado nesta rodada.** Amanhã às 07:53 é a rotina completa de segunda, com a revisão semanal.

## 19/10/2026, 08:15 — Rotina das 07:53 (completa, segunda) com revisão semanal

- **Ocupação (segunda 19/10):** hotel 42% em 7 dias, 40% em 15 e 34% em 30. Recanto 42%, 42% e 35%; Villa 42%, 37% e 32%. Na meta: Double, Queen 2 e King Spa 2 em 15 e 30 dias; fora da meta de 7 dias: Queen 7 (20%), Double (62%), Afrodite (57%), Queen 2 (50%), VK7 (53%) e Balcony (14%).
- **Envio e recálculo:** o PriceLabs recalculou os 7 quartos às 07:35 de hoje, mas o último envio aos canais ainda é o de 18/10 às 10:28. As gravações abaixo só chegam no próximo envio.
- **Reservas e mudanças manuais:** 17 reservas novas desde sexta (lote da Booking e vendas diretas), nenhum cancelamento e nenhuma mudança manual sua. Pelo calendário: Double vendeu em 23 e 24/10 (1 e 2 para 3 de 6), Queen 2 teve cancelamentos em 19 a 21/10 e a King Spa 2 esgotou hoje.
- **Mercado (Booking, mediana da noite):** quarta 21/10 R$ 313; 23–25/10 R$ 770 (2 noites); 6–8/11 R$ 1.529 (2 noites); Finados 3 noites R$ 3.036 (16 imóveis, de R$ 1.790 a R$ 5.370). Ocupação do mercado em 21 a 25/10 entre 9% e 17%.
- **Eventos:** abertura do Natal em 30 ou 31/10 (data sem confirmação da Prefeitura), concerto "Uma Noite de Luz" de 30/10 a 08/11; nada novo em novembro.
- **Gravado (13 datas; conferido no recálculo às 08:25: Afrodite e VK7 5 de 5 sem diferença; Double 8 datas só pela regra por data, o recálculo bateu no limite de 3 por 24 h e fica para a rotina das 10:53):**

| Mudança | Onde | Antes | Depois (calculado) | Como desfazer |
|---|---|---|---|---|
| Piso +10% (5 de 6) | Double 20/10 | R$ 1.495 | R$ 1.645 | piso −12% sobre a base |
| Piso +5% (3 de 6) | Double 21/10 | R$ 1.228 | R$ 1.289 | piso −12% |
| Piso +5% (4 de 6) | Double 22/10 | R$ 1.199 | R$ 1.259 | piso −14% |
| Piso +5% (3 de 6) | Double 23/10 | R$ 1.406 | R$ 1.476 | piso 0% |
| Piso +5% (3 de 6), estadia 2 | Double 24/10 | R$ 1.732 | R$ 1.819 | piso +17% |
| Piso +10% (5 de 6) | Double 29/10 | R$ 2.028 | R$ 2.231 | piso +24% |
| Teto −15% (0 de 6) | Double 06/11 | R$ 1.835 | R$ 1.560 | teto +24% |
| Piso +5% (1 de 6) | Double 08/11 | R$ 1.251 | R$ 1.314 | piso −17% |
| Teto −10% (0 de 1) | Afrodite 24/10 | R$ 1.980 | R$ 1.782 | teto −1% |
| Piso −10% (0 de 1) | Afrodite 02/11 | R$ 2.100 | R$ 1.890 | piso 5% |
| Teto −5% (0 de 1) | Afrodite 06/11 | R$ 3.620 | R$ 3.439 | teto +81% |
| Piso −15% (2 de 7) | VK7 02/11 | R$ 1.569 | R$ 1.334 | piso +6% |
| Piso −5% (2 de 7) | VK7 04/11 | R$ 977 | R$ 944 | piso −34% |

- **Barrei:** VK7 e Balcony em 08/11 (o teto da VK7 cairia a R$ 932, abaixo do piso de R$ 943 do dono); Balcony em 02/11 (travada pela Regra G); Double 19/10 (hoje à noite não sobe).
- **Validador:** 0 erros e 0 avisos no plano final (a primeira versão deu 1 erro, corrigido).

### Revisão semanal
- **Faturamento do mês (livro por estadia, lado dos canais "outros" sem valor):** outubro R$ 241 mil (Recanto R$ 139 mil, Villa R$ 102 mil), novembro R$ 113 mil, dezembro R$ 56 mil, janeiro R$ 29 mil. Ritmo do dia 19: ~70% do mês. Recanto a 63% da meta do dono (R$ 220 mil), pouco atrás do ritmo; Villa a 68% da meta do dono (R$ 150 mil), no ritmo.
- **Modo por faturamento:** Recanto e Villa ficam em **padrão**; a trava de lotação não permite preço (Recanto na meta só em 30 dias, Villa em nenhuma janela).
- **Fatores:** mantive `fator_real` 0,42 (Recanto) e 0,39 (Villa); Airbnb pagou 0,30 a 0,32 do enviado e a semana não confirmou a subida a 0,45.
- **Finados (D-11):** Queen 7 com 1 e 2 de 7 em 30 e 31/10 e King Spa 2 com 0 de 2; mediana da Booking R$ 3.036 e o Recanto fica ~35% acima. A condição de corte de um degrau foi atingida e fica para a rodada completa de 20/10, como combinado.
- **Não feito nesta sessão:** varredura de concorrência (rodada 2) e medição do fator de vitrine da VK7 e King Spa 2; ficam para uma sessão à parte.

## 19/10/2026, 12:00 — Pedido do dono: livro do mês com as reservas sem valor

- **Decisão:** o número principal do faturamento passa a somar as reservas que chegam ao PriceLabs sem valor (quase todas do canal "outros") pelo valor total da reserva. A leitura só com o valor do PriceLabs e a com o Airbnb pelo valor total vão numa linha de apoio.
- **Livro por mês de estadia (número principal):** outubro R$ 269 mil (Recanto R$ 152 mil, Villa R$ 117 mil); novembro R$ 122 mil (Recanto R$ 90 mil, Villa R$ 32 mil); dezembro R$ 75 mil (Recanto R$ 50 mil, Villa R$ 25 mil).
- **Modo:** segue padrão nas duas pousadas. A Villa já passa do ritmo da sua meta em outubro, mas a trava de lotação segura (ocupação fora da meta nas três janelas).
- **Nada gravado no PriceLabs.**

## 19/10/2026, 11:00 — Rotina das 10:53 (leve)

- **Envio:** o PriceLabs enviou os 7 quartos às 08:45 (Brasília), já com as 13 gravações das 08:15. O Sync Now pedido no relatório da manhã não é mais necessário.
- **Conferência no calendário:** Double 8 de 8 bate com o plano (20/10 R$ 1.658, 21/10 R$ 1.302, 22/10 R$ 1.273, 23/10 R$ 1.480, 24/10 R$ 1.820, 29/10 R$ 2.235, 06/11 R$ 1.554, 08/11 R$ 1.317); Afrodite 3 de 3 bate. A VK7 não veio nesta coleta (falha de leitura) e já tinha sido conferida no recálculo das 08:25.
- **Reservas, cancelamentos e mudanças manuais:** nenhum novo desde as 07:53; o log só mostra as gravações do robô.
- **Ocupação:** sem variação nos 6 quartos lidos.
- **Nada gravado nesta rodada.**

## 19/10/2026, 14:30 — Pedido do dono: origem dos valores e leitura direta do Beds24

- **Origem até hoje:** as reservas e os valores vinham do PriceLabs (cópia das reservas do Beds24). A ocupação vinha do calendário do PriceLabs.
- **Contra o consolidado do Beds24 de 19/10:** o Beds24 conta só "Confirmado" e deixa fora 48 "Novo" e 19 "Inquiry"; por isso a ocupação dele é menor (60 noites: Recanto 205 contra 258 U/N; Villa 115 contra 133). A receita por noite é parecida (Recanto R$ 654 contra R$ 608; Villa R$ 579 contra R$ 522).
- **Correção de cálculo:** o livro do mês passou a ratear as noites por `no_of_days` (o `check_out` do PriceLabs é a última noite). Outubro fica R$ 257 mil (Recanto R$ 144 mil, Villa R$ 113 mil), não os R$ 269 mil informados às 12:00; novembro R$ 132 mil; dezembro R$ 72 mil. O modo não muda.
- **Leitura direta:** `scripts/beds24.py` pronto e testado; a API V2 do Beds24 responde daqui. Falta o token só de leitura do dono, cadastrado como `BEDS24_REFRESH_TOKEN` nos segredos do ambiente (passo a passo em `pricelabs/beds24-acesso-direto.md`).
- **Nada gravado no PriceLabs.**

## 19/10/2026, 15:30 — Leitura direta do Beds24 ligada

- **Acesso:** o convite enviado pelo dono foi trocado por um token de atualização guardado só neste contêiner (fora do repositório, nunca impresso). O convite já foi consumido e não serve mais.
- **Conferência:** as reservas confirmadas batem com o consolidado das 10:58 (Recanto exato; Villa +3 a 4 unidades-noite de reservas confirmadas depois).
- **O que o relatório do Beds24 deixava de fora:** 53 reservas "Novo" (Booking 23, Airbnb 20, direto 10), que têm fatura e ocupam o quarto, e 23 "Inquiry" do Airbnb, que são só consultas.
- **Ocupação real (confirmadas + novas):** 7 noites Recanto 43,8%, Villa 44,8%; 15 noites 42,5% e 38,7%; 30 noites 36,2% e 23,8%.
- **Livro por mês pelo Beds24 (confirmadas + novas):** outubro R$ 259 mil (Recanto R$ 145 mil, Villa R$ 115 mil); novembro R$ 137 mil; dezembro R$ 74 mil. Só confirmadas, outubro dá R$ 209 mil.
- **Nada gravado no PriceLabs nem no Beds24.** O token tem escopo com dados de hóspedes; a Skill não guarda esses campos.

## 19/10/2026, 17:00 — Pedido do dono: máxima precisão com o Beds24

- **Decisão:** o Beds24 direto passa a ser a fonte das reservas, da ocupação real (Confirmado + Novo) e do faturamento em todas as rotinas; o PriceLabs segue para preço e calendário de venda.
- **Achados da primeira varredura (Beds24 contra PriceLabs, 19/10 a 17/12):**
  - **Overbooking:** King Spa 2 em 24/10 com 4 reservas confirmadas da Booking para 2 quartos; duas sem unidade atribuída (lote de 08/10). Precisa de ação do dono.
  - **Villa fechada em 5, 6 e 7/11** sem reservas que justifiquem (só 1 a 2 na VK7): cerca de 37 unidades-noite paradas.
  - **Balcony 24/10:** 2 unidades fechadas sem reserva.
  - **Valor real ÷ enviado (exato, no dia da reserva):** Booking 0,49 (Recanto) e 0,46 (Villa); Airbnb 0,41 e 0,31; direto 0,36 e 0,45.
- **Nada gravado no PriceLabs nem no Beds24.**

## 19/10/2026, 17:05 UTC (14:05 BRT) — Pedido do dono: aproximar dos hotéis com hidro e vista a até 3 km do Capivari

- **Pedido:** "vamos tentar nos aproximar mais dos Hotéis que tem Banheira de Hidromassagem e Vista para as Montanhas na Booking . e estão as menos de 3 km do Centro do Capivari . Faça essa busca para criar o plano de ajuste de preços."
- **Busca na Booking** (raio de 3 km de -22.7288, -45.5797; filtro hidro; tema vista para as montanhas; hidro no quarto e vista confirmadas pela Booking): 12 hotéis classe A (Ascona, Lavandas, Villa Casato, Matsubara, L.A.H., DOM, Secreto, Europa, Villa Belle, Cafe Poesia, Murano, Villa Nature). A Villa não aparece nessa busca.
- **Mediana da classe A por noite × Recanto na mesma busca:** 21/10 R$ 254 × R$ 532; 23-25/10 R$ 774 × R$ 825; Finados R$ 1.250 × R$ 1.473; 06-08/11 R$ 715 × R$ 1.163; 11/11 R$ 295 × R$ 642; Consciência Negra R$ 1.579 × R$ 1.428; 04-06/12 R$ 600 × R$ 1.093.
- **Regra usada:** acima da mediana mais de 10% e noite fora da frente da meta → até -15% pelo teto, nunca abaixo dos pisos do dono; abaixo mais de 8% e noite na meta → +10% pelo piso. Tudo em `percent_base`.

| Quarto | Datas | Antes (calendário) | Depois | Conferido no recálculo |
|---|---|---|---|---|
| Q7 | 30-31/10 | R$ 2.680 | teto 94% (R$ 2.276) | ✅ R$ 2.276 |
| Q7 | 11/11 | R$ 951 | teto -32% (R$ 800) | ✅ R$ 800 |
| Q7 | 20-21/11 | R$ 2.351 | piso 121% (R$ 2.592) | ✅ R$ 2.592 |
| Double | 30/10; 06/11; 07/11 | R$ 3.156; R$ 1.554; R$ 2.453 | teto 81% (R$ 2.683); -5% (R$ 1.410); 40% (R$ 2.085) | não conferido (limite de recálculo) |
| Double | 20-21/11 | R$ 2.919 | piso 117% (R$ 3.211) | não conferido (limite de recálculo) |
| Afrodite | 01/11 | R$ 2.320 | teto -1% (R$ 1.980) | ✅ R$ 1.980 |
| Afrodite | 06/11; 07/11 | R$ 3.420; R$ 3.939 | teto 45% (R$ 2.900); 67% (R$ 3.340) | ✅ |
| Afrodite | 11/11 | R$ 1.642 | teto -25% (R$ 1.500) | ✅ R$ 1.500 |
| Afrodite | 04-05/12 | R$ 3.453 | teto 46% (R$ 2.920) | ✅ R$ 2.920 |
| VK7 | 30-31/10; 06/11; 07/11; 20-21/11; 04-05/12 | R$ 3.834; R$ 2.788; R$ 3.529; R$ 2.937; R$ 2.118 | teto 120%; 60%; 102%; 68%; 21% | não conferido (limite de recálculo) |
| VK2 | 30-31/10 | R$ 3.143 | teto 110% (R$ 2.667) | ✅ R$ 2.667 |
| VK2 | 06/11; 07/11 | R$ 2.521; R$ 3.161 | teto 68%; 111% | esgotada/fechada no calendário (vale se reabrir) |
| VK2 | 04-05/12 | R$ 1.863 | teto 24% e **piso 43% → 23%** (R$ 1.575) | 1ª conferência: R$ 1.816 (o piso de 14/10 vencia o teto); piso corrigido às 16:56 UTC |
| Balcony | 04-05/12 | R$ 1.638 | piso e teto 29% → **12%** (R$ 1.422), Regra G | gravado; conferir amanhã |
| Q2 | 30/10; 04-05/12 | R$ 3.987; R$ 3.136 | tetos 81% e 42% **desfeitos** às 16:57 UTC | 30/10 recriado com +20%, 3 noites e piso 26% |

- **Erro e correção:** a Queen (2) não recebe desconto, e o teto abaixo do preço atual é desconto. O validador não pegava isso nem o teto abaixo do piso vigente (fim de semana a 150% da base ou piso da data). Os dois casos agora dão ERRO; o leitor do recálculo passou a conferir item só de teto.
- **Seguradas:** 21/10 e 23-24/10 (já perto do alvo ou no piso do dono); Q7 01/11 (piso do feriado); Villa 06-07/11 (estoque fechado no Beds24: os tetos só valem se o dono reabrir).
- **Como desfazer:** apagar o `max_price` das datas acima (ou a regra da data, quando ela só tinha o teto) e voltar o piso da VK2 04-05/12 a 43% e o da Balcony a 29%.
- **Sync Now:** não é preciso para o curto prazo (a data mais próxima é 30/10); o envio normal da madrugada leva.

## 19/10/2026, 17:10 UTC (14:10 BRT) — Rotina leve das 13:53

- **Beds24 (movimento desde 13:50 UTC):** 3 reservas novas da Booking, nenhum cancelamento. VK7 de 19 a 21/10 (3 noites, R$ 1.346 na fatura, confirmada); VK7 em 27/11 (1 noite, R$ 909); Q7 em 22/10 (1 noite, R$ 382, status "novo"). As 7 alterações são de estadias já iniciadas (mudança de status).
- **Ocupação depois das vendas:** VK7 com 5/7 em 19/10, 6/7 em 20/10 e 2/7 em 21/10, pela soma com o calendário de 11:45 UTC. Q7 com 2/7 em 22/10, já no piso de dia de semana (R$ 809 enviados, cerca de R$ 510 na Booking).
- **Regra G conferida (19 a 22/10):** a Balcony fica de 13% a 14% abaixo da suíte com banheira livre mais barata. Dentro da faixa.
- **Nada gravado.** A VK7 está sem saldo de recálculo (429), então não recebe passo de flutuação nesta rodada. A Q7 já está no piso do dono.

## 19/10/2026, 18:01 UTC (15:01 BRT) — Concorrência refeita pelo quarto com hidro (pedido do dono: "aplique os filtros dentro da Booking")

- **O que estava errado:** a busca da manhã comparou o Recanto, em que todo quarto tem banheira, com o quarto mais barato de cada concorrente, que muitas vezes não tem hidro. As medianas ficaram 20% a 66% abaixo do quarto com hidro, e os cortes da manhã não se sustentam.
- **Como medi agora:** o conector da Booking não tem os filtros de quarto do site, e as páginas da Booking bloqueiam robôs: houve um 403 com aviso dos termos de uso, então parei. Usei a escada de preço mínimo (sem mínimo, 500, 800, 1.100, 1.500 e acima) por hotel, cruzada com o nome e a posição do quarto com hidro informados pela Booking. Certeza média.
- **Quarto com hidro × Recanto:** 23-25/10 R$ 935 × R$ 825; Finados R$ 1.604 × R$ 1.473; 06-08/11 R$ 1.171 × R$ 1.163; 11/11 R$ 954 × R$ 642; Consciência Negra R$ 1.604 × R$ 1.428; 04-06/12 R$ 1.255 × R$ 1.093. O Recanto está de 1% a 33% abaixo.

| Quarto | Datas | Estado final (tudo em percentual) |
|---|---|---|
| Q7 | 30-31/10, 11/11 | cortes desfeitos (volta ao +20% com piso 63% em Finados; 11/11 sem regra) |
| Q7 | 01/11; 04-05/12 | +10% pelo piso: 80% (R$ 2.111); 69% (R$ 1.982) |
| Q7 | 20-21/11 | mantido +10% pelo piso 121% (R$ 2.592) |
| Double | 30/10; 01/11; 11/11; 04-05/12 | +10% pelo piso: 135% (R$ 3.478); 42% (R$ 2.102); 4% (R$ 1.539); 75% (R$ 2.590) |
| Double | 06-07/11 | cortes desfeitos (06/11 volta ao teto de 5% de 4c; 07/11 piso -5%) |
| Double | 20-21/11 | mantido +10% pelo piso 117% (R$ 3.212) |
| Q2 | 30/10 | +10% pelo piso 135% (R$ 4.395); só sobe, regra sem desconto respeitada |
| Afrodite | 01/11, 06-07/11, 11/11, 04-05/12 | todos os cortes desfeitos (volta ao estado de antes) |
| VK7 | 30-31/10 | teto 120% (R$ 3.256) mantido: Villa estimada 58% acima |
| VK7 | 20-21/11 | teto 68% (R$ 2.486) mantido: 21% acima e 0/7 vendidas |
| VK7 | 04-05/12 | piso e teto 28% (R$ 1.894), antes R$ 2.118; corte menor (-10%) que o da manhã |
| VK7 | 06-07/11 | cortes desfeitos (estoque fechado no Beds24) |
| VK2 | 30-31/10 | teto 110% (R$ 2.667) mantido |
| VK2 | 04-05/12 | piso e teto 31% (R$ 1.664), antes R$ 1.863 |
| VK2 | 06-07/11 | cortes desfeitos |
| Balcony | 04-05/12 | piso e teto 19% (R$ 1.511), Regra G 10% abaixo da VK2 |

- **Validador:** 25 itens, 0 erro.
- **Recálculo:** não conferido, porque o limite de 3 recálculos por quarto em 24 h já foi usado. A conferência fica para a rotina das 07:53 de 20/10.
- **Como desfazer:** as razões das regras citam o estado anterior. O plano está em `plano_comp_1910d.json` e a reversão em `reversao_1910.json` (rascunho da sessão).

## 19/10/2026, 19:55 UTC (16:55 BRT) — Rotina leve das 16:53

- **Beds24 (movimento desde 16:50 UTC):**
  - 1 reserva nova direta: Double de 20 a 22/10, 3 noites, R$ 4.233 na fatura (R$ 1.411 por noite).
  - 1 cancelamento da Booking: Double de 20 a 21/10, 2 noites, R$ 973.
  - Q2 93847392 (chegada em 19/10, 3 noites) passou a constar como cancelada.
  - O resto são mudanças de status em reservas antigas.
- **Ocupação depois das mudanças:**
  - Double com 5/6 em 20/10, 3/6 em 21/10 e 5/6 em 22/10.
  - Q2 com 1/2 de 19 a 22/10, já no calendário das 16:53 UTC.
- **Nada gravado.** O Double está sem saldo de recálculo (429), então fica sem passo de flutuação; a alta de 22/10 entra na rotina de 20/10. A Q2 não recebe desconto nem a Regra I.

## 19/10/2026, 22:55 UTC (19:55 BRT) — Rotina leve das 19:53

- **Beds24 (movimento desde 19:50 UTC):**
  - 1 reserva nova do Airbnb: VK2 em 20/10, 1 noite, R$ 357 na fatura, status "novo".
  - 1 cancelamento do Airbnb: VK2 em 21/10, 1 noite, sem valor na fatura.
- **Ocupação da VK2:** esgotada em 20/10 (2/2); 21/10 com 1/2.
- **Regra G:** em 21/10 a Balcony (R$ 813) está 15% abaixo da VK2 livre (R$ 952), dentro da faixa. Em 20/10, com a VK2 esgotada, a referência passa a ser a VK7 (R$ 1.051, 1 unidade livre), e a Balcony (R$ 826, 1/6 vendida) fica 21% abaixo. Mantida assim: é D1, a Regra I vem antes da G e subir a Balcony com 5 unidades livres na véspera vai contra a trava de lotação. Também falta saldo de recálculo.
- **Nada gravado.**

## 20/10/2026, 10:10 UTC (07:10 BRT) — Base do dono pela busca filtrada da Booking (PDF)

- **Pedido do dono:** fazer a busca no navegador com os filtros da Booking. Ele mandou a URL e o PDF da busca de 30/11 a 02/12: hidro no quarto, menos de 3 km, nota 8+ e café incluído.
- **Navegador:** não usado. A Booking bloqueia robôs e avisou em 19/10 que isso viola os termos. O PDF do dono traz exatamente os dados.
- **Leitura dos 70 concorrentes (por noite, vitrine Genius 3):** mediana R$ 646; nota 9+ R$ 736; nota 8 a 8,9 R$ 389. Recanto R$ 350 (percentil 10). Villa R$ 522 (percentil 37).
- **Achados para o dono:**
  1. A vitrine Genius 3 do Recanto é 0,41 do enviado (Q7 R$ 852 → R$ 350), abaixo do piso de R$ 500 no preço final de semana.
  2. O desconto empilhado do riscado ao final é de 50% no Recanto e 35% na Villa, contra 15% nos concorrentes.
- **Nada gravado no PriceLabs:** os dois achados mudam a base dos pisos e as promoções, e só o dono decide.
- **Arquivos:** `dados/base_booking_dono.json`; método em `mercado.md`.

## 20/10/2026, 10:30 UTC (07:30 BRT) — 2º PDF do dono: fim de semana de 27 a 29/11

- **Datas:** o PDF não traz as datas. Pelo riscado do Recanto ÷ enviado da Q7 (0,81, igual a 30/11), é o fim de semana de 27 a 29/11.
- **59 concorrentes com hidro no quarto, menos de 3 km, nota 8+ e café incluído (por noite, vitrine Genius 3):** mediana R$ 1.022; nota 9+ R$ 1.229; nota 8 a 8,9 R$ 721. Recanto R$ 749 (percentil 29). Villa R$ 995 (percentil 46).
- **Regra da seção 5 (mediana geral):**
  - Recanto: 27% abaixo do mercado, mas com 1/7 vendida em 27 e 28/11, abaixo da meta de 15% a 38 dias. Segura, sem subida.
  - Villa: 3% abaixo do mercado, dentro da faixa. Segura.
- **Nada gravado.** O ganho maior está nas duas decisões do dono abertas desde o 1º PDF: a base do piso de R$ 500 / R$ 850 e o desconto empilhado de 47% a 50% no Recanto.
- **Código:** `scripts/base_booking_pdf.py`, que leu os dois PDFs; ele reproduz a leitura manual do 1º (70 concorrentes, mediana R$ 645,50).

## 20/10/2026, 10:16 UTC (07:16 BRT) — 3º PDF do dono: Finados (30/10 a 02/11)

- **Datas:** o PDF mostra 3 diárias e não traz as datas. O riscado do Recanto ÷ enviado médio da Q7 dá 0,79, como nos outros PDFs, então é Finados.
- **48 concorrentes (por noite):** mediana R$ 1.100; nota 9+ R$ 1.272; nota 8 a 8,9 R$ 922. Recanto R$ 1.036 (percentil 44). Villa R$ 1.586 (percentil 81).
- **Gravado (validador: 2 itens, 0 erro):**

| Quarto | Datas | Antes | Depois |
|---|---|---|---|
| VK2 | 30-31/10 | teto 110% (R$ 2.667, conferido no recálculo de 10:15 UTC) | teto 78% (R$ 2.261); piso 63% e 3 noites mantidos |

- **Por quê:** mesmo com o teto de 19/10, a vitrine da Villa fica cerca de 28% acima da mediana, e a VK2 está com 0/2 vendidas. A VK2 é o quarto que aparece na vitrine.
- **Seguradas:**
  - Recanto: 6% abaixo do mercado, dentro da faixa.
  - VK7: 31/10 já está na meta (4/7), e não é o quarto da vitrine.
  - VK2 em 01/11: esgotada (2/2).
- **Recálculo:** não conferido, porque o limite de recálculo da VK2 acabou (429). Confiro às 07:53.
- **Como desfazer:** voltar `max_price` a 110 (`percent_base`) na VK2 em 30-31/10.

## 20/10/2026, 11:48 UTC (08:48 BRT) — Rotina completa: pisos para o Genius 3 gravados e conferidos

- **Decisão do dono (20/10):**
  - "Serve para todo o piso": os pisos de R$ 500 e R$ 850 no preço final valem também para o hóspede Genius 3.
  - "Não": as promoções da Booking ficam como estão.
- **Gravado:** 418 pisos por data até 30/04/2027, em `percent_base`, fora dos feriados. Validador com 0 erro; todos os lotes conferidos na API.

| Quarto | Noites | Piso antes | Piso novo |
|---|---|---|---|
| Q7 | 166 | R$ 800-975 dom-qui; R$ 1.396-1.760 sex-sáb | 5% (R$ 1.232) dom-qui; 72% (R$ 2.018) sex-sáb |
| Q2 | 122 | R$ 850-860 dom-qui | -34% (R$ 1.234) |
| Double | 114 | R$ 900-1.258 dom-qui; sex-sáb abaixo de R$ 2.000 | -17% (R$ 1.228) dom-qui; 37% (R$ 2.028) sex-sáb; tetos de 23/10 e 06/11 sobem ao máximo |
| Afrodite | 8 | R$ 1.500 sex-sáb | 0% (R$ 2.000); tetos de 23 e 24/10 sobem ao máximo |
| VK7 / VK2 | 4 + 4 | R$ 1.295-1.302 sex-sáb | 0% (R$ 1.480) / 18% (R$ 1.499) |

- **Recálculo (Q7, Double, Q2, Afrodite e VK7):** 414 de 414 conferidos, 0 diferença. Preço no piso: Q7 R$ 1.232 / R$ 2.018 e Double R$ 1.228 a R$ 2.028. A VK2 (4 noites) fica para a próxima rotina, porque está sem saldo de recálculo.
- **Concorrência de 19/10 conferida:** a Balcony em 04-05/12 está em R$ 1.511 (19%; plano R$ 1.506, diferença de arredondamento). `ler_recalculo.py` passou a tolerar 1% no teto.
- **4c:** Double em 22/10, com 5/6 a D2, sobe +10% pelo piso, de -14% para -5% (R$ 1.273 → R$ 1.406).
- **Beds24 (desde 22:50 UTC):**
  - 4 reservas diretas novas na VK7, todas criadas entre 00:56 e 01:29 UTC: 30-31/10 R$ 1.096; 13-14/11 R$ 1.190; **20-21/11 R$ 1,10**; 07/12 R$ 450.
  - A de R$ 1,10 em feriado parece bloqueio ou erro de digitação; vai para o dono.
  - 1 cancelamento do Airbnb: Double em 21/10.
- **Ocupação real (confirmadas + novas):** 7 dias Recanto 45,5% e Villa 41,9%; 15 dias 41,3% e 36,9%; 30 dias Recanto 36,0%.
- **Coleta:** o workflow completo não rodou. Os 6 recálculos e o Beds24 cobriram a medição; concorrência pela varredura do conector e pelos 3 PDFs do dono.
- **Como desfazer os pisos:** voltar os fatores em `quartos.json` e regravar os pisos com o validador. As razões citam "Dono 20/10".

## 20/10/2026, 13:00 UTC (10:00 BRT) — Skill booking-hotel-research adotada; 1ª rodada de concorrentes

- **Pedido do dono:** "vamos usar essa Skill" (pacote `booking-hotel-research`). Conteúdo lido antes do uso, sem acesso à rede; instalado no projeto.
- **Cadastro:** 23 hotéis com hidro no quarto a até 3 km: 19 classe A, 3 classe B e o Recanto como referência. 10 estadias de 23/10 a 13/12. Total de 230 combinações.
- **1ª rodada:** 41 capturas vindas dos 3 PDFs do dono, com quarto com hidro e café incluído confirmados pelos filtros e vitrine Genius 3. Tabela em `pricelabs/pesquisa-booking/rodada-2026-10-20/resumo.md`. As outras 7 estadias seguem pendentes até chegarem novos PDFs.
- **Nada gravado no PriceLabs nesta etapa.**

## 20/10/2026, 14:45 UTC (11:45 BRT) — 1ª busca diária dos 77 concorrentes do perfil

- **Cobertura:** 48 buscas pelo conector oficial (café incluído, 2 adultos), com preço para 64 a 69 concorrentes por estadia.

| Estadia | Concorrentes | Mediana/noite | 25%-75% | Recanto (percentil) | Villa (percentil) |
|---|---|---|---|---|---|
| 21/10 (1 noite) | 69 | R$ 347 | R$ 257-787 | R$ 532 (65) | R$ 430 (57) |
| 23-25/10 | 66 | R$ 583 | R$ 427-1.056 | R$ 825 (68) | R$ 598 (53) |
| Finados (3 noites) | 64 | R$ 899 | R$ 636-1.350 | R$ 1.566 (84) | R$ 1.465 (81) |
| 06-08/11 | 67 | R$ 801 | R$ 610-1.260 | R$ 1.199 (73) | fechada |
| 13-15/11 | 67 | R$ 860 | R$ 640-1.290 | R$ 1.182 (69) | R$ 1.144 (69) |
| Consciência Negra (3 noites) | 65 | R$ 1.066 | R$ 732-1.394 | R$ 1.437 (78) | R$ 1.237 (69) |

- **Leitura:** os concorrentes entram pelo menor quarto com café, que nem sempre tem hidro, e nós entramos com quarto com hidro. Por isso parecemos mais caros: em Finados o Recanto está no percentil 84 nesta busca e no percentil 44 no PDF do dono (hidro contra hidro).
- **Trava:** enquanto o `r_hidro` não estiver medido em pelo menos metade dos concorrentes, a busca diária não gera corte, só subida e tendência. Calibração dos 77 em andamento pela escada.
- **Preços nossos na busca:** ainda sem os pisos novos, que a Booking só recebe no envio da madrugada.
- **Nada gravado no PriceLabs.**

## 20/10/2026, 15:30 UTC (12:30 BRT) — r_hidro calibrado em 33 concorrentes; busca diária refeita

- **Escada de preço mínimo (conector, com café, 13-15/11 e 25/11):** r_hidro medido em 33 dos 77 concorrentes. Em 7 deles o menor quarto já tem hidro. Mediana 1,395, usada como padrão para os 44 sem medida. Arquivo `pricelabs/pesquisa-booking/calibracao-r_hidro-2026-10-20.json`.
- **Busca de hoje com o quarto com hidro estimado (por noite):**

| Estadia | Mediana | Recanto (percentil) | Villa (percentil) |
|---|---|---|---|
| 21/10 | R$ 570 | R$ 532 (46) | R$ 430 (33) |
| 23-25/10 | R$ 882 | R$ 825 (41) | R$ 598 (18) |
| Finados | R$ 1.326 | R$ 1.566 (59) | R$ 1.465 (56) |
| 06-08/11 | R$ 1.274 | R$ 1.199 (45) | fechada |
| 13-15/11 | R$ 1.309 | R$ 1.182 (45) | R$ 1.144 (42) |
| Consciência Negra | R$ 1.518 | R$ 1.437 (46) | R$ 1.237 (37) |

- **Leitura:** bate com os PDFs do dono. Finados dá percentil 59 aqui e 44 a 50 no PDF. O Recanto está no meio do mercado e a Villa abaixo dele. Os preços nossos ainda são os de antes dos pisos novos.
- **Trava:** segue ligada (33 de 77 medidos; precisa de 39). A revisão de segunda mede os que faltam.
- **Nada gravado no PriceLabs.**

## 20/10/2026, 16:00 UTC (13:00 BRT) — Reserva de feriado por R$ 1.100: lançamento manual no Beds24

- **Pedido do dono:** "verifique os valores corretos; acabamos de receber uma reserva para o feriado do dia 20 de novembro de 2 diárias por apenas 1100 reais".
- **Achado:** VK7, reserva 94953589 (20-22/11), R$ 1.096,20 na fatura. Foi criada à mão no painel do Beds24 (usuário "isaacmiguel", canal direto) em 20/10 às 01:18 UTC. A descrição da tarifa traz os preços de 19 e 20/10 (R$ 947 e R$ 1.051): a reserva foi aberta com as datas do dia, depois as datas foram trocadas e o preço não foi recalculado.
- **Mesmo padrão nas outras reservas da mesma noite:**
  - 94953814: Finados, 2 noites contra a mínima de 3, R$ 1.096.
  - 94953197: 13-15/11, R$ 1.190.
  - 94953351: 07/12, R$ 450.
  - Preço do Beds24 por noite: R$ 3.000 (20/11), R$ 3.663 (30/10), R$ 2.220 (13/11). As 4 juntas: cerca de R$ 16 mil pela tabela, lançadas por R$ 3,8 mil.
- **PriceLabs e Booking:** os preços estão certos; as reservas da Booking de hoje entraram coerentes.
- **Nada alterado.** Reservas ficam com o dono e a equipe.

## 20/10/2026, 13:58 UTC (10:58 BRT) — Rotina leve das 10:53

- **Beds24, reservas desde 11:40 UTC:** 7 novas da Booking, todas confirmadas, entre 12:27 e 12:43 UTC.

| Quarto | Chegada | Noites | Valor |
|---|---|---|---|
| Q7 | 21/10 | 2 | R$ 847 |
| Q7 | 25/10 | 6 | R$ 3.519 |
| Q7 | 13/11 | 2 | R$ 1.571 |
| Q7 | 17/11 | 2 | R$ 1.153 |
| Double | 21/11 | 3 | R$ 3.362 |
| Balcony | 23/10 | 2 | R$ 891 |
| VK2 | 09/11 | 1 | R$ 553 |

- **Reservas diretas:** nenhuma nova. A checagem "valor por noite abaixo de 60% do price1" não pegou nada.
- **Envio aos canais:** os pisos de 20/10 ainda não chegaram ao Beds24. O price1 da Q7 segue em R$ 809 de 21 a 29/10 (piso novo R$ 1.232) e o do Double em R$ 1.480 em 23/10 (piso novo R$ 2.028). As reservas de hoje entraram pelo preço antigo. Pedido ao dono: **Sync Now** no PriceLabs, porque não há API para isso.
- **Nada gravado.**

## 20/10/2026, 16:56 UTC (13:56 BRT) — Rotina leve das 13:53

- **Beds24 (desde 13:50 UTC):** nenhuma reserva nova e nenhum cancelamento; só uma alteração de status de estadia em andamento.
- **Envio:** os pisos novos ainda não chegaram. O price1 da Q7 segue em R$ 809 de 21 a 22/10 e R$ 1.396 em 23-24/10; o do Double, R$ 1.480 em 23/10. Sync Now continua pendente com o dono.
- **Nada gravado.**

## 20/10/2026, 19:57 UTC (16:57 BRT) — Rotina leve das 16:53

- **Reserva direta nova:** Afrodite, reserva 94997273, de 18 a 21/01/2027 (3 noites), R$ 2.335,50 (R$ 778/noite), status "novo".
  - Veio pela API (`referer` API, sem oferta e sem descrição de tarifa), não foi digitada no painel.
  - O price1 do Beds24 para essas noites é R$ 1.730, então ela entrou a **45% da tabela**; o alerta é abaixo de 60%.
  - É coerente com o valor real ÷ enviado já medido no canal direto (0,36 no Recanto, 19/10): o canal direto vende a cerca de 40% do preço do PriceLabs. Verificar no Beds24 (motor de reservas ou a integração que usa a API) se há multiplicador ou desconto no canal direto.
- **Outras mudanças:** alterações de status em estadias de hoje.
- **Envio:** os pisos novos ainda não chegaram ao Beds24 (Q7 a R$ 809 em 21-22/10). Sync Now segue pendente; senão, sai no envio da madrugada.
- **Nada gravado.**

## 20/10/2026, 22:58 UTC (19:58 BRT) — Rotina leve das 19:53

- **Envio:** os pisos de 20/10 chegaram ao Beds24 (Q7 com price1 de R$ 1.232 em 21-22/10 e R$ 2.018 em 23-24/10).
- **Reserva direta abaixo do limite do dono:** VK7, reserva 94999600, Finados de 30/10 a 02/11 (3 noites), R$ 1.800 ("600 por noite").
  - Criada à mão no painel pelo usuário "VillaRecep1" às 20:37 UTC.
  - O price1 do Beds24 nessas noites é R$ 3.256 + R$ 3.256 + R$ 2.294 = R$ 8.806; a reserva entrou a **20% da tabela**.
  - R$ 600 por noite fica abaixo do limite de segurança do dono para feriado (R$ 800 reais por noite).
  - A VK7 fica esgotada em 01/11.
- **Cancelamentos:** 2 da Booking no Double em 18/10, estadia passada, sem efeito.
- **Nada gravado.**

## 21/10/2026, 11:30 UTC (08:30 BRT) — Rotina completa

- **Conferência no recálculo dos 7 quartos:**
  - 418 pisos de 20/10: 0 diferença, incluindo as 4 noites da VK2.
  - Concorrência refeita em 19/10: 0 diferença.
  - VK2 em Finados (teto 78%): conferido.
  - Double em 22/10: conferido.
- **ALERTA: o Recanto sumiu da busca da Booking.** O conector oficial não acha a propriedade (`hotel_names_not_resolved`) em 28/10, 13-15/11 e 08-10/12, nem por coordenadas. Ontem aparecia em todas as estadias, e a Villa aparece normalmente. No Beds24 a disponibilidade, as estadias mínimas e o price1 estão normais. A última reserva da Booking no Recanto entrou em 21/10 às 00:02 UTC. Pedido ao dono: conferir na extranet se o Recanto está aberto e visível.
- **Q2 com tarifa errada na Booking:** reservas 95003950 e 95003949 (20-22/11) a R$ 412,50 e R$ 495 por noite, com price1 de R$ 4.433 e R$ 4.213. A tarifa 50067466 tem valor fixo; na tarifa padrão a Q2 recebe cerca de 0,20 do price1, como se estivesse ligada ao preço da Q7. Os outros quartos estão entre 0,28 e 0,44 (normal). Pendência urgente do dono: mapeamento no Beds24.
- **Busca diária:** 63 a 68 concorrentes por estadia, sem o Recanto. A Villa fica nos percentis 19 a 56; mediana de R$ 894 em 23-25/10, R$ 1.344 em Finados e R$ 1.309 em 13-15/11. Trava de corte ligada.
- **Ocupação real (confirmadas + novas):**

| Janela | Recanto | Villa |
|---|---|---|
| 7 dias | 49,1% | 39,1% |
| 15 dias | 43,8% | 36,4% |
| 30 dias | 38,3% | 23,3% |

- **Reservas da madrugada:** 2 da Q2 (acima), Double em 23-25/10 pelo Airbnb (R$ 1.724) e 2 da VK2 pelo Airbnb; o resto são consultas.
- **Nada gravado no PriceLabs:** sem o Recanto na vitrine e com a trava de corte ligada, nenhum passo de concorrência; a Villa está abaixo da meta, então nada sobe.

## 21/10/2026, 14:00 UTC (11:00 BRT) — Rotina leve das 10:53: 7 cancelamentos da Booking; 2 subidas de piso revertidas

- **Beds24 (movimento desde 11:00 UTC):**
  - 9 reservas novas da Booking na Villa, todas importadas às 11:06 UTC com números de reserva diferentes (sem duplicata). São 6 na VK7 (21-22/10, 29/10, 20-21/11 e 22/11) e 3 na Balcony (21/10, 22/10 e 01/11), de R$ 318 a R$ 1.359 por noite.
  - **7 cancelamentos da Booking.** No Recanto foram 4, entre 12:28 e 12:30 UTC: Double em 22-23/10, Q7 em 24-25/10, Q7 em 31/10-01/11 e Q7 em 01/11. Na Villa foram 3, entre 13:37 e 13:42 UTC: VK7 em 23-24/10 (2 reservas) e VK2 em 23-24/10.
  - O Recanto continua fora da busca da Booking (`unresolved_hotel_names` às 13:59 UTC), enquanto a Villa aparece a R$ 430 em 28/10.
- **Reservas abaixo do planejado (`reservas_abaixo.py`):** só a Double em 25/10 (Booking 6255306720), a 50%. Foi feita em 09/09 a R$ 387, antes da subida dos pisos; não é erro de lançamento.
- **Sem overbooking:** a soma das noites no Beds24 fica dentro das unidades de 21/10 a 03/11.
- **Ocupação real (confirmadas + novas):**

| Janela | Recanto | Villa |
|---|---|---|
| 7 dias | 46,4% | 40,0% |
| 15 dias | 41,2% | 37,8% |
| 30 dias | 37,1% | 24,0% |

- **Q7 no Finados:** 2/7 em 30/10, 1/7 em 31/10 e 2/7 em 01/11.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 14:00) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| 4c revertido (cancelamento: 4/6, meta 70%) | Double 22/10 | piso −5% (R$ 1.406) | piso −13% (R$ 1.288) ✅ | voltar o piso a −5% |
| Concorrência revertida (2/7, meta 50%) | Q7 01/11 | piso 80% (R$ 2.111) | piso 64% (R$ 1.924), estadia 3 ✅ | voltar o piso a 80% |

- **Envio:** o envio da madrugada chegou (Q7 com price1 de R$ 1.232 / R$ 2.018). As duas mudanças só chegam ao canal no envio desta noite; a Double 22/10 é D1, então pedi o Sync Now.
- **Sem corte no Finados:** a trava sem_corte segue ligada, e o Recanto não aparece na Booking, então cortar não traria venda.

## 21/10/2026, 17:00 UTC (14:00 BRT) — Pedido do dono: plano de 12 meses em execução (pisos, estadia mínima e restrições até 31/12/2027)

- **Pedido:** "Vamos rodar o plano agora e já atualizar tudo para os próximos 12 meses e manter tudo em monitoramento. Inclusive restrições de permissão de check-in e check-outs, mínimo de dias da estadia."
- **Como:** `calendario_12m.py` comparou cada noite de 25/10/2026 a 31/12/2027 dos 7 quartos com as regras, usando o recálculo do PriceLabs (piso, estadia e chegada/saída efetivos). Só subiu o que faltava. Passou no validador (966 itens, 0 erro, 0 aviso) e foi gravado em 16 lotes, todos com sucesso.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 16:50 UTC) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso do dono (R$ 500 dom-qui / R$ 850 sex-sáb no preço final do Genius 3), em percentual | 6 suítes com banheira, 01/05 a 31/12/2027 (Q7 247, Double 188, Q2 189, VK2 190, VK7 25, Afrodite 12 noites) | sem regra por data (piso do anúncio ou fim de semana a 150% da base) | piso por data em `percent_base`; Q7 R$ 2.000 sex-sáb e R$ 1.220 dom-qui | apagar as regras com motivo "12m 21/10" |
| Piso de feriado | Aniversário de SP, Carnaval, Páscoa, Tiradentes, Corpus Christi, 9 de Julho, 7 de Setembro, 12 de Outubro, Finados 2027, Proclamação, Natal e Réveillon 2027, nos 7 quartos | sem piso de feriado depois de 60 dias | piso de feriado de `quartos.json` (R$ 800 a R$ 1.000 no bolso ÷ fator; suítes com pelo menos R$ 1.000 enviados) | idem |
| Estadia mínima | sábados (2) e feriados (2 ou 3), 7 quartos | 1 em vários sábados e feriados de 2027 | 2 nos sábados; 3 em Carnaval, Corpus Christi, 9 de Julho e Réveillon; 2 nos outros | idem |

- **Conferência (1 recálculo por quarto):** `calendario_12m.py auditar` deu **0 noites abaixo da regra** nos 7 quartos até 31/12/2027. Nenhum piso e nenhuma estadia gravados desceram. A véspera do Carnaval (04/02/2027) passou de 3 para 1 noite na Afrodite, na VK7 e na VK2 por cálculo automático do PriceLabs; Q7, Double e Q2 já tinham 1. A regra não exige estadia nessa noite, então ficou assim.
- **Preço médio de maio a dezembro de 2027 (efeito dos pisos):**

  | Quarto | Antes | Depois |
  |---|---|---|
  | Q7 | R$ 1.646 | R$ 1.725 |
  | Double | R$ 2.077 | R$ 2.096 |
  | Q2 | R$ 2.624 | R$ 2.645 |
  | VK7 | R$ 2.004 | R$ 2.047 |
  | VK2 | R$ 1.720 | R$ 1.790 |
- **Restrições de chegada e saída:** o PriceLabs recusou a gravação por data ("Enable check-in/check-out customization first"); a personalização é só pela tela e a API não tem a chave. Elas seguem no Beds24, com o dono (Regra H). Auditoria do Beds24 até 31/12/2027:

  | Quarto | Sábados sem bloqueio de chegada | Padrão misturado |
  |---|---|---|
  | Q2 | 48, a partir de 30/01/2027 | — |
  | Q7 | 39, a partir de 03/04/2027 | noCheckInOrCheckOut de 30/01 a 27/03 (o resto do Recanto usa noCheckIn) |
  | Afrodite | 39, a partir de 03/04/2027 | — |
  | VK2 | 39, a partir de 03/04/2027 | — |
  | Double | 17, a partir de 04/09/2027 | — |
  | VK7 | 17, a partir de 04/09/2027 | noCheckIn de 03/04 a 28/08 (a Villa usa noCheckInOrCheckOut) |
  | Balcony | 17, a partir de 04/09/2027 | noCheckIn de 03/04 a 28/08 |

  Pendência do dono: `beds24-sabados-12m`.
- **Monitoramento:** SKILL.md, passo 4b. A rotina completa roda a auditoria de 12 meses todo dia, com horizonte rolante. Nota abaixo da regra vira plano no mesmo dia; sábado sem bloqueio no Beds24 vai para o relatório.
- **Envio:** as regras chegam aos canais no envio desta madrugada.

## 21/10/2026, 17:00 UTC (14:00 BRT) — Rotina leve das 13:53: mais 4 cancelamentos da Booking no Recanto

- **Cancelamentos entre 14:05 e 16:08 UTC, todos do Recanto pela Booking:**
  - Q7, 21–23/10, 2 noites (chegada hoje);
  - Q7, 24–28/10, 4 noites;
  - Double, 25/10;
  - Q7, 25–31/10, 6 noites, R$ 3.519.

  No dia são 8 cancelamentos da Booking no Recanto. A propriedade continua fora da busca da Booking às 17:00 UTC (`hotel_names_not_resolved`), e a Villa não teve nenhum desde as 13:42.
- **Ocupação:** Recanto em 37,5% nos próximos 7 dias (era 46,4% às 10:53); Villa em 40,0%. A Q7 tem só 1 ou 2 de 7 vendidas de 21/10 a 01/11, e 0 em 29/10.
- **Reservas abaixo do planejado:** nenhuma nova.
- **Nada gravado:**
  - a Q7 já está no piso do dono (R$ 1.232 enviados de domingo a quinta; R$ 2.018 em sexta e sábado), que não desce;
  - a Q7 e a Double estão sem recálculo até amanhã cedo (3 de 3 usados hoje); a reversão do +10% da Double em 25/10 (agora com 3 de 6 vendidas) fica para a rotina das 07:53;
  - sem vitrine na Booking, cortar preço não traz venda.
- **Urgente para o dono:** extranet da Booking do Recanto (pendência `recanto-fora-booking-2110`).

## 21/10/2026, 17:15 UTC (14:15 BRT) — Pergunta do dono: tarifários, feriados e datas comemorativas

- **Pergunta do dono:** "fez também as restrições dos tarifários? Feriados e datas comemorativas?"
- **Feriados:** os 16 feriados de `quartos.json` até o Réveillon de 2027 têm piso de feriado e estadia mínima (gravação de 21/10, 14:00 BRT).
- **Dia dos Namorados 2027:** entrou como pico em `quartos.json` (noites 11 e 12/06, R$ 1.000 no bolso, 2 noites; `estrategia.md` §3).

| Mudança | Onde | Antes | Depois (lido de volta às 17:14 UTC; recálculo amanhã) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso de pico e 2 noites | Q7, Double, Q2, VK7 e VK2, 11 e 12/06/2027 | piso do dono (R$ 2.006 na Q7) | Q7 R$ 2.381; Double R$ 2.383; Q2 R$ 2.955; VK7 R$ 2.575; VK2 R$ 2.565 | voltar ao piso do dono (motivo "12m 21/10") |

- **Afrodite e Balcony:** já estavam acima do piso nas noites dos Namorados.
- **Outras datas comemorativas e eventos:** abertura e pico do Festival, junho, CBQ, L'Étape e Oktoberfest 2027.
  - Seguem com os pisos do dono, a estadia de sábado e a faixa alta ou muito alta da sazonalidade na flutuação diária.
  - O PriceLabs já mostra 2 noites em julho e nos eventos.
  - Ficam com 1 noite as sextas de junho.
- **Tarifários:** o PriceLabs envia preço e restrições para um único plano em cada quarto ("Melhor tarifa disponível", p1, `update_type` "price and restrictions"). No Beds24 há uma regra de preço e uma oferta por quarto. As outras tarifas da Booking (não reembolsável e as tarifas 64070790, 65710721 e 50067466) são mapeadas no canal. Pisos, estadia e bloqueios do Beds24 valem por quarto. Restrições criadas direto na extranet por tarifa não aparecem nem no PriceLabs nem no Beds24: precisam ser conferidas pelo dono.

## 21/10/2026, 18:00 UTC (15:00 BRT) — Pedido do dono: Panorâmica II sempre entre a Moinhos e a Panorâmica I

- **Pedido:** "Os valores da Suíte Panorâmica II devem sempre estar entre a Moinhos e a Panorâmica I. Faça a verificação se isso está correto na Recanto." No PriceLabs: Moinhos = Queen (7); Panorâmica I = Double; Panorâmica II = Queen (2).
- **Verificação (recálculo de 21/10, 16:4x UTC):** a Panorâmica II estava acima da Panorâmica I em 435 de 437 noites até 31/12/2027, 27% acima em média. Nenhuma noite abaixo da Moinhos.
  - Exemplos: 21/10 Moinhos R$ 1.232, Panorâmica II R$ 1.613, Panorâmica I R$ 1.302; Finados 30/10 R$ 2.620, R$ 4.394 e R$ 3.478.
  - Causas: base do anúncio da Queen (2) em R$ 1.870 (Double R$ 1.480, Q7 R$ 1.173); regra antiga de "sem desconto"; pisos de feriado com fator 0,34.

| Mudança | Onde | Antes | Depois (lido de volta; recálculo na rotina de 22/10, o PriceLabs devolveu 429) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Faixa por noite: piso = maior entre o piso da regra e o menor dos dois; teto = 75% do caminho até o maior (`percent_base`) | Queen (2), 435 noites de 21/10/2026 a 31/12/2027 | acima da Panorâmica I | entre a Moinhos e a Panorâmica I (21/10: R$ 1.232 a R$ 1.284; 23/10: R$ 2.018 a R$ 2.026) | apagar piso e teto com motivo "Dono 21/10: Panorâmica II entre…" |
| Regra da Queen (2) | `regras.md` e `quartos.json` | "sem desconto"; fator 0,34 | entre Q7 e Double (`ordem_recanto.py`, passo 4b2 de toda rotina completa); fator 0,42 | voltar o texto e o fator |

- **O que não mudou:** estadia mínima e percentuais de outras regras (o +20% do dono no Finados continua; o teto segura).
- **Na Booking:** a Queen (2) segue presa à tarifa fixa 50067466 (R$ 412 a R$ 495) até o dono corrigir o mapeamento. A faixa vale para o price1 do Beds24 e para os demais canais.
- **Sugestão ao dono:** baixar a base do anúncio da Queen (2) de R$ 1.870 para cerca de R$ 1.330, em dois passos de até 15% por semana, para a faixa depender menos do teto.

## 21/10/2026, 19:55 UTC (16:55 BRT) — Rotina leve das 16:53

- **Beds24 (desde 16:55 UTC):** nenhuma reserva nova e nenhum cancelamento; 7 alterações de status em estadias em curso (check-in de hoje).
- **Reservas abaixo do planejado:**
  - Q2 de 21 a 23/10 (Booking 5449340417), a 54% do esperado: feita em 24/09 na tarifa fixa da Booking (R$ 423 por noite), não é erro de lançamento.
  - Double 94878118 (direta, estadia de 18 a 21/10) lançada a R$ 1,92: provável erro de digitação; conferir a fatura.
- **Recanto:** continua fora da busca da Booking às 19:54 UTC.
- **Nada gravado.** A Q7, a Double e a Q2 estão sem saldo de recálculo até amanhã; a conferência da ordem do Recanto e a reversão da Double em 25/10 ficam para a rotina das 07:53.

## 21/10/2026, 22:55 UTC (19:55 BRT) — Rotina leve das 19:53

- **Beds24 (desde 19:50 UTC):** nenhuma reserva nova e nenhum cancelamento; 1 alteração de status (check-in da Afrodite).
- **Reservas abaixo do planejado:** nenhuma.
- **Nada gravado.** As regras gravadas hoje (calendário de 12 meses e ordem do Recanto) chegam aos canais no envio desta madrugada; conferência na rotina completa das 07:53.

## 22/10/2026, 12:35 UTC (09:35 BRT) — Rotina completa + pedido do dono: Afrodite a mais cara do Recanto

- **Reservas abaixo do planejado (lançadas à mão pela VillaRecep1 ontem, de 23:40 a 23:46 UTC):**
  - VK7 em 02/11, 1 noite, R$ 412 (31% do esperado);
  - VK7 em 22/10, 1 noite, R$ 430 (45%).

  Também entrou uma nova pela Expedia: VK7 de 21 a 23/10, R$ 2.156.
- **Ocupação real (confirmadas + novas):**

| Janela | Recanto | Villa |
|---|---|---|
| 7 dias | 36,6% | 37,1% |
| 15 dias | 35,8% | 36,4% |
| 30 dias | 35,2% | 24,0% |

- **Recálculo:** Q7, Double, Q2, Afrodite, VK7 e Balcony às 11:58–12:30 UTC. A VK2 não recalculou ("Beds24 temporarily unavailable"); usei o recálculo dela de ontem, com regras sem mudança desde então.
- **Calendário de 12 meses:** 0 noites abaixo da regra, fora as 2 noites dos Namorados na VK2, que foram gravadas ontem depois do arquivo usado. Beds24 com os mesmos sábados sem bloqueio (pendência `beds24-sabados-12m`).
- **Ordem do Recanto (21/10):** 395 das 435 noites dentro da faixa. Das 40 restantes, 30 estavam dentro do degrau de 1% da base (R$ 18,70 na Q2); a tolerância passou a ser essa. As outras 10 foram regravadas.
- **Pedido do dono (22/10):** "A Suíte Afrodite tem que ser a mais cara, com valor mais alto da Recanto, pois ela é considerada super luxo." Regra nova: Afrodite pelo menos 5% acima da mais cara das outras três, toda noite (`ordem_recanto.py`). Ela já era a mais cara em todas as noites, menos 5.

| Mudança | Onde | Antes | Depois (lido de volta; recálculo na rotina das 10:53, o PriceLabs devolveu 429) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| 4c revertido (cancelamento: 3/6, meta 70%) | Double 25/10 | piso R$ 1.569 | piso R$ 1.421 (−3%) | voltar o piso a +6% |
| Afrodite a mais cara | Afrodite 25/10, 29/10, 24/01, 25/03, 14/11/2027 | R$ 1.500 a R$ 2.415 | piso 5% sobre a mais cara das outras (R$ 1.647 a R$ 2.514) | apagar o piso com motivo "Dono 22/10" |
| Faixa da Panorâmica II | Q2 15-16/01, 05-06/02, 04-05/06, 11-12/06, 18-19/06/2027 | acima da Panorâmica I | entre a Moinhos e a Panorâmica I | idem |
| Regra G (Finados) | Balcony 30 e 31/10 | R$ 2.616, acima da VK2 livre (R$ 2.261) | teto R$ 2.035 (10% abaixo), piso de feriado R$ 1.510 | tirar o teto |

- **Revisão quinzenal dos 12 meses:** em dia (próxima em 05/11).

## 22/10/2026, 16:55 UTC (13:55 BRT) — Rotina leve das 13:53

- **Beds24:** 2 cancelamentos: Expedia VK7 de 21 a 23/10 (R$ 2.156) e Airbnb Double 25/10 (sem valor). Nenhuma reserva nova.
- **Reservas abaixo do planejado:** nenhuma.
- **Nada gravado** além da base da Q2 (abaixo).

## 22/10/2026, 19:55 UTC (16:55 BRT) — Rotina leve das 16:53

- **Beds24:** nenhuma reserva nova abaixo do planejado desde as 13:55 BRT.
- **Nada gravado** nesta rotina; a conversão da Q2 seguiu em lotes (abaixo).

## 22/10/2026, 21:20 UTC (18:20 BRT) — Pedido do dono: base da Panorâmica II (Q2), 1º passo

- **Pedido do dono (22/10):** "3. Pode fazer." Base da Q2 de **R$ 1.870 para R$ 1.590** (−15%, o máximo semanal do limite de segurança). 2º passo em 29/10: R$ 1.590 → R$ 1.352 (pendência `q2-base-passo-2`).
- **Conversão das regras por data:** 436 regras da Q2 (pisos e tetos em percentual da base, até 31/12/2027) regravadas em 7 lotes com os mesmos valores em reais: piso arredondado para cima e teto para baixo. **Conferência: 436 de 436, nenhuma diferença.**
- **Recálculo da Q2 (20:45 UTC):** ordem do Recanto com 15 noites fora da tolerância e 2 fora da ordem estrita em 23 e 24/10, todas pelo degrau de 1% da base nova (R$ 15,90). Regravei:

| Mudança | Onde | Antes | Depois (lido de volta; recálculo deu 429) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Faixa da Panorâmica II | Q2 23 e 24/10 | R$ 2.035 (acima da Panorâmica I, R$ 2.028) | R$ 2.019 (27%), entre a Moinhos (R$ 2.018) e a Panorâmica I | voltar a 28% |
| Faixa da Panorâmica II | Q2 19/11 e 24/01 | R$ 1.940 | R$ 1.908 (20%), acima do piso de feriado (R$ 1.905) | voltar a 22% |
| Faixa da Panorâmica II | Q2 07-09/02, 25/03, 26-27/05, 08/07, 05-06/09, 08-11/10/2027 | R$ 2.401 | R$ 2.385 (50%), o mais perto possível de Moinhos R$ 2.381 / Panorâmica I R$ 2.383 | voltar a 51% |
| Afrodite a mais cara | Afrodite 29/10, 24/01, 25/03 | R$ 1.500 / R$ 1.920 / R$ 2.400 | piso R$ 2.360 / R$ 2.020 / R$ 2.520 (5% sobre a mais cara das outras) | apagar o piso com motivo "Dono 22/10" |

- **Afrodite 25/10:** já vendida, fica como está.
- **Recálculo de conferência:** Q2 com 429 e Afrodite com "Beds24 temporarily unavailable"; fica para a próxima rotina (pendência `recalculo-q2-afrodite-2210`).

## 22/10/2026, 22:58 UTC (19:58 BRT) — Rotina leve das 19:53

- **Beds24 (desde 19:50 UTC):**
  - 6 reservas novas da Booking na Villa, todas às 22:10 UTC (entrega em lote): VK7 22/10 (3 noites, R$ 2.155), 23/10 (2, R$ 1.313) e 26/10 (3, R$ 1.346); VK2 23/10 (1, R$ 707), 12/12 (1, R$ 1.083) e 30/12 (2, R$ 3.197).
  - 2 cancelamentos: VK7 24/10 (Booking, 2 noites, R$ 983) e VK2 02/11 (Airbnb, sem valor).
- **Reservas abaixo do planejado** (check-in de hoje, status alterado): Q7 22/10 Booking a 63% do esperado e Double 22/10 Airbnb a 70%. O price1 de agora é o do piso novo; não parece erro de lançamento.
- **Recálculo:** Afrodite, VK7 (2×) e VK2. A Q2 deu 429 de novo.
- **Afrodite conferida:** 29/10 R$ 2.360, 24/01 R$ 2.020 e 25/03 R$ 2.520 (a mais cara do Recanto).
- **Calendário de 12 meses (VK7, VK2, Afrodite):** 0 noites abaixo da regra.

| Mudança | Onde | Antes | Depois (recálculo) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| 4c: à frente da meta (6 de 7; Villa 8 de 9 com banheira, D2) | VK7 24/10 | R$ 1.480 | piso +10%: **R$ 1.628** ✅ | voltar o piso a 0% |

## 23/10/2026, 11:20 UTC (08:20 BRT) — Rotina completa das 07:53: Recanto de volta à Booking; Double e Afrodite sobem, ordem do Recanto regravada

- **Reserva abaixo do planejado:** VK7 em 02/11, 1 noite, direta (95102304), lançada à mão pela VillaRecep1 às 22:57 UTC de 22/10: R$ 300 de R$ 1.347 esperados (22%).
- **Beds24 desde 22:50 UTC:** 1 nova (a de cima) e 1 cancelamento (VK2 08/11, Airbnb, sem valor).
- **Ocupação real (Beds24, confirmadas + novas):**

| Janela | Recanto | Villa |
|---|---|---|
| 7 dias | 33,9% | 37,1% |
| 15 dias | 34,2% | 36,4% |
| 30 dias | 35,6% | 24,9% |

- **Booking (conector):**
  - **o Recanto voltou à busca** (fds 23-25/10 a R$ 1.656 por 2 noites, 7º com hidro; 1º com hidro em 26 e 27/10);
  - **Finados (30/10 a 02/11) sem disponibilidade para as duas pousadas** na Booking, com quartos livres (pendência do dono `booking-finados-sem-disponibilidade`);
  - mediana dos concorrentes: fds R$ 736 (2 noites), dia de semana R$ 260, Finados R$ 3.492 (3 noites), fds 06-08/11 R$ 1.706.
- **Envio:** Afrodite, Q2 e VK7 com último envio em 22/10 05:35 UTC; não saíram no envio desta madrugada (os outros saíram às 02:29 UTC).
- **Recálculo:** Q7, Q2, Afrodite, Balcony e VK2. A Double deu 429 e Beds24 indisponível; usei o calendário da coleta (02:29 UTC). A VK7 está sem saldo até 12:30 UTC; ficou sem passo (sem calendário do dia).
- **Faixas 4c:** cortes de 14/10 (9 dias). As descidas sugeridas no Recanto e na Villa caem quase todas no piso do dono (R$ 500 / R$ 850 no preço final) e ficam paradas.
- **Calendário de 12 meses:** 0 noites abaixo da regra (6 quartos; Double sem recálculo do dia). Beds24 com os mesmos sábados sem bloqueio.
- **Ordem do Recanto:** 10 noites da Panorâmica II e 3 da Afrodite fora; regravadas (a Afrodite de 30/10 está vendida e ficou como está).

| Mudança | Onde | Antes | Depois (recalculado e conferido às 11:10 UTC, salvo indicação) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| 4c: à frente da meta (5/6, D1) | Double 24/10 | R$ 2.028 | **recusado pelo PriceLabs** (pede recálculo); refazer às 10:53 | — |
| 4c: à frente da meta (3/6 a 17-19 dias) | Double 09-10/11 e 11/11 | R$ 1.383 / R$ 1.530 | piso R$ 1.524 / R$ 1.687 (lido de volta; recálculo deu 429) | voltar o piso a −6% / +4% |
| Afrodite a mais cara | Afrodite 23/10, 24/10, 11/11 e 19/11 | R$ 2.000 / R$ 2.325 / R$ 1.665 / R$ 1.950 | piso R$ 2.140 (lido de volta) / **R$ 2.360 ✅** / **R$ 1.780 ✅** / R$ 2.020 (lido de volta) | apagar o piso com motivo "Dono 22/10" |
| Faixa da Panorâmica II | Q2 03-04/11, 06-07/11, 13-14/11, 18-21/12 | R$ 1.288 / R$ 2.337 / R$ 2.051 / R$ 2.242 a R$ 2.385 | entre a Moinhos e a Panorâmica I (R$ 1.240; R$ 2.019 a R$ 2.194; R$ 2.019; R$ 1.797 a R$ 2.162; lido de volta) | idem, motivo "Dono 21/10" |
| 4c: na meta, acima da classe (1/2) | VK2 02/11 | R$ 1.359 | **R$ 1.295 ✅** | voltar o piso a +7% |
| Regra G (10% abaixo da VK2 livre) | Balcony 02/11 | R$ 1.219 | **R$ 1.168 ✅** (piso e teto) | voltar piso e teto a −4% |

- **Revisão quinzenal dos 12 meses:** em dia (próxima em 05/11).

## 23/10/2026, 12:50 UTC (09:50 BRT) — Pedido do dono: lotar o fim de semana do Recanto

- **Pedido do dono (23/10):** "Verifique a lotação para esse final de semana da Recanto dos Moinhos e vamos fazer uma estratégia para lotarmos dentro de um escopo aceitável de valor de estadia." Escolhas dele: piso da Moinhos de **R$ 700** no preço final e **sábado com 1 noite**, só na Moinhos e só neste fim de semana.
- **Lotação (Beds24, 09:40 BRT):**

| Noite | Recanto | Moinhos | Panorâmica I | Panorâmica II | Afrodite |
|---|---|---|---|---|---|
| Sex 23/10 | 7/16 (44%) | 1/7 | 4/6 | 2/2 | 0/1 |
| Sáb 24/10 | 8/16 (50%) | 1/7 | 5/6 | 2/2 | 0/1 |
| Dom 25/10 | 5/16 (31%) | 1/7 | 2/6 | 1/2 | 1/1 |

- **Mercado:** 23,5% de ocupação no fim de semana (34% no ano passado). Booking, 2 noites: Recanto R$ 1.656; 5 estrelas de R$ 2.274 a R$ 3.200; pousadas com hidro e nota 8+ de R$ 770 a R$ 1.440.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 12:45 UTC) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Piso do dono do fim de semana | Moinhos (Q7) 23 e 24/10 | piso 72% (R$ 2.018, R$ 850 final) | **piso 42% (R$ 1.666, cerca de R$ 700 final) ✅** | voltar o piso a 72% |
| Regra H liberada pelo dono | Moinhos 24/10 | estadia mínima 2 | **1 noite ✅** (no Beds24 ainda há bloqueio de chegada no sábado: pendência do dono) | voltar a 2 noites |

- **Validador:** item com excecao "dono" passa a liberar o sábado de 1 noite fora de feriado (`historico-da-skill.md`, 23/10).
- **Sync Now:** Moinhos (D0 e D1).

## 23/10/2026, 14:05 UTC (11:05 BRT) — Rotina leve das 10:53: mudanças de hoje ainda não chegaram ao Beds24

- **Beds24 (desde 10:50 UTC):**
  - 7 novas: 6 da Booking em lote às 12:23 UTC (3 Balcony 23-25/10 a R$ 831 cada; VK7 20/11 e 11-15/12; VK2 25/10) e 1 pedido do Airbnb (VK2 25/10).
  - 4 canceladas: VK2 23/10, Balcony 23-25/10 (2) e VK7 23-25/10.
  - Saldo no fim de semana da Villa: VK7 6/7, Balcony 3/6 e VK2 1/2 em 23/10. No Recanto, sem mudança: a Moinhos segue em 1/7.
- **Reservas abaixo do planejado:** nenhuma.
- **Envio:** o Beds24 ainda mostra os preços antigos, ou seja, o Sync Now não foi feito:
  - Moinhos 23 e 24/10 a R$ 2.018, quando deveria estar a R$ 1.666, e sábado com 2 noites e bloqueio de chegada;
  - Afrodite a R$ 2.325, quando deveria estar a R$ 2.140 e R$ 2.360;
  - VK7 24/10 a R$ 1.480, quando deveria estar a R$ 1.628.
- **Recálculo:** VK7, VK2 e Balcony. A Double deu 429 de novo; o +10% de 24/10 segue pendente.
- **Nada gravado.** A VK7 em 24/10 segue em 6/7, e o +10% fica. A Balcony em 23/10 está abaixo do alvo da Regra G (R$ 1.332) e fica, pela trava de lotação (3/6).

## 23/10/2026, 17:00 UTC (14:00 BRT) — Rotina leve das 13:53: Moinhos ainda sem Sync Now; 2 reservas manuais abaixo da tabela

- **Reservas abaixo do planejado:**
  - Q2 95126778 (direta, lançada por isaacmiguel): era 23-25/10 por R$ 4.040 e foi alterada para 24-26/10 por **R$ 983** (30% do esperado);
  - VK7 95134237 (direta, VillaRecep1, 11:57 BRT): 23-25/10 por **R$ 960** (R$ 480 por noite, 31%).
- **Beds24 (desde 13:50 UTC):** 3 novas (VK7 acima; VK2 11/11 no Airbnb, R$ 952; pedido do Airbnb na VK2 em 09/11). Sem cancelamentos.
- **Envio da tarde:** Q2 (R$ 2.019) e VK7 24/10 (R$ 1.628) chegaram ao Beds24. **Moinhos (R$ 2.018 no lugar de R$ 1.666, sábado ainda com 2 noites e bloqueio de chegada) e Afrodite (R$ 2.325) não chegaram:** falta o Sync Now.
- **Lotação de 23 e 24/10:** VK7 esgotada nas duas noites. Livres: Moinhos 6 e 6, Panorâmica I 2 e 1, Afrodite 1 e 1, Balcony 3 e 3, VK2 1 e 0.
- **Double 24/10 (+10%):** recusado de novo ("Pricing data for this date isn't available yet"); o recálculo da Double segue em 429 desde as 07:55 BRT.
- **Nada gravado.**

## 23/10/2026, 20:00 UTC (17:00 BRT) — Rotina leve das 16:53: Moinhos no ar a R$ 700; sábado ainda com bloqueio de chegada

- **Envio:** agora o Beds24 mostra a Moinhos a R$ 1.666 em 23 e 24/10, com o sábado de 1 noite, e a Afrodite a R$ 2.140 / R$ 2.360 (Sync Now feito). **O sábado 24/10 da Moinhos ainda tem "noCheckIn" no Beds24:** chegada no sábado bloqueada (pendência do dono `beds24-moinhos-sabado-2410`).
- **Beds24 (desde 16:50 UTC):** 2 novas (VK7 15-18/12 na Booking, R$ 1.275; VK2 02/11 no Airbnb, R$ 544). Sem cancelamentos. A Moinhos segue com 6 livres em 23 e 24/10 (sem venda desde a mudança).
- **Reserva abaixo do planejado:** VK7 94847819 (direta pela API, feita em 18/10), 23-25/10 por R$ 1.302 (42%); apareceu pela mudança de status no check-in.
- **Double 24/10 (+10%):** recálculo ainda em 429; não gravado (1 livre a R$ 2.028).
- **Nada gravado.**

## 23/10/2026, 23:10 UTC (20:10 BRT) — Pedido do dono: preços para vender até domingo + rotina leve das 19:53

- **Pedido do dono (17h BRT):** "Vamos colocar valores que vão vender essas suítes entre hoje e domingo." O plano foi validado às 17h (16 itens, 0 erros), mas **só foi gravado às 20:05 BRT**: a gravação não foi feita na hora, e 3 horas de venda de última hora se perderam.
- **Referência de mercado (Booking, 2 noites, 23-25/10):** pousadas com hidro e nota 8+ de R$ 285 a R$ 720 por noite; 5 estrelas a partir de R$ 444 (Casa Gialla, sem hidro) e R$ 840 (Telhado de Ouro).

| Mudança (preço final aproximado, Genius 3) | Onde | Antes (enviado) | Depois (piso = teto, enviado) | Conferido |
|---|---|---|---|---|
| Moinhos | Q7 23 e 24/10 / 25/10 | R$ 1.666 / R$ 1.232 | R$ 1.302 (~R$ 553) / R$ 938 (~R$ 385) | lido de volta |
| Panorâmica I | Double 23 e 24/10 / 25/10 | R$ 2.028 / R$ 1.436 | R$ 1.539 (~R$ 654) / R$ 1.036 (~R$ 425) | lido de volta (recálculo em 429) |
| Panorâmica II (esgotada; ordem) | Q2 23 e 24/10 / 25/10 | R$ 2.019 / R$ 1.240 | R$ 1.431 / R$ 1.002 | lido de volta |
| Afrodite (5% acima) | 23 e 24/10 | R$ 2.140 / R$ 2.360 | R$ 1.780 (~R$ 756) | lido de volta |
| Balcony (Regra G) | 24/10 / 25/10 | R$ 1.130 / R$ 813 | **R$ 737 (~R$ 424) / R$ 635 (~R$ 336) ✅** | recálculo |
| VK2 | 23/10 (1 livre) | R$ 1.499 | R$ 838 (~R$ 482) | lido de volta |
| VK7 | 25/10 | R$ 947 | **R$ 710 (~R$ 376), limite de segurança ✅** | recálculo |

- A Balcony em 23/10 esgotou antes da gravação e ficou como estava. Como desfazer: apagar as regras de 23 a 25/10 com motivo "Dono 23/10 17h" e regravar as anteriores (registro de hoje). Elas vencem sozinhas depois de 25/10.
- **Rotina leve (desde 19:50 UTC):** 2 novas (VK2 04/11 e 22/11, Airbnb). Sem cancelamentos. Reserva abaixo do planejado: Double 94085266 (direta pela API, feita em 02/10), 23-25/10 por R$ 1.576 (39%); apareceu pela mudança de status no check-in.
- **Sync Now nos 7 quartos** (D0 a D2).

## 24/10/2026, 11:40 UTC (08:40 BRT) — Rotina completa das 07:53: preços de última hora não foram enviados; recalculados

- **Envio:** o último envio aos canais foi em 23/10 às 21:24 UTC (Sync Now do dono). As regras do pedido "vender até domingo" foram gravadas às 23:04 UTC e o PriceLabs não recalculou os quartos depois disso. **O envio da madrugada não aconteceu, e o Beds24 segue com os preços antigos** (Moinhos R$ 1.666, Panorâmica I R$ 2.028, Afrodite R$ 2.360, Balcony R$ 1.130).
- **Recalculei às 11:30 UTC:** Moinhos 24/10 R$ 1.302 e 25/10 R$ 938; Panorâmica I R$ 1.539 e R$ 1.036; Afrodite 24/10 R$ 1.780; VK7 25/10 R$ 710 ✅. Panorâmica II e Balcony deram 429 (a Balcony já tinha recalculado às 23:06 UTC com R$ 737 / R$ 635). **Falta o Sync Now do dono** para chegar aos canais.
- **Beds24 (desde 22:50 UTC):** 1 venda na Moinhos para hoje (direta 95150099, 1 noite, R$ 3.332); 1 cancelamento (VK2 03/11, Airbnb, sem valor); 2 pedidos do Airbnb na VK2 em novembro. Nenhuma reserva abaixo do planejado.
- **Ocupação real (Beds24):** 7 dias Recanto 36,6% / Villa 34,3%; 15 dias 36,7% / 33,3%; 30 dias 36,0% / 24,9%.
- **Booking (conector):** sábado 24/10, 1 noite: mediana R$ 383, máximo R$ 909. Dias de semana 26-27/10: mediana R$ 250. Finados, 3 noites: mediana R$ 2.079 (o Recanto voltou a aparecer na busca com hidro). Fds 06-08/11: mediana R$ 1.086.
- **Calendário de 12 meses:** 0 noites abaixo da regra.
- **Ordem do Recanto:** 3 noites da Afrodite regravadas (10/11, 26-27/05/2027). As faixas da Panorâmica II ficam para quando ela recalcular (429; o arquivo dela é de 23/10).

| Mudança | Onde | Antes | Depois (lido de volta) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| Afrodite a mais cara | Afrodite 10/11 | R$ 1.500 | piso R$ 1.600 | apagar o piso "Dono 22/10" |
| Afrodite a mais cara | Afrodite 26-27/05/2027 | R$ 2.400 | piso R$ 2.520 | idem |

## 24/10/2026, 14:10 UTC (11:10 BRT) — Rotina leve das 10:53: preços de última hora no ar; ordem da Panorâmica II

- **Envio:** o Beds24 agora mostra os preços de última hora (Sync Now do dono): Moinhos R$ 1.302 / R$ 938, Panorâmica I R$ 1.539 / R$ 1.036, Afrodite R$ 1.780, Balcony R$ 737 / R$ 635, VK7 25/10 R$ 710. Na Villa, os sábados 24/10 ficaram sem bloqueio de chegada. Na Moinhos 24/10 o "noCheckIn" continua.
- **Beds24 (desde 10:50 UTC):** só alterações de status (check-ins). Nada novo, nada cancelado, nenhuma reserva abaixo do planejado. Livres hoje: Moinhos 5, Panorâmica I 1, Afrodite 1, Balcony 3. Amanhã: Moinhos 6, Panorâmica I 4, VK7 6, Balcony 6.
- **Ordem do Recanto:** Panorâmica II recalculada; 27 noites fora da faixa, regravadas e lidas de volta sem divergência: 20-31/12/2026, 14-18/02, 20-21/04, 23-25/05 e 01-05/08/2027. Como desfazer: regravar o piso e o teto anteriores, motivo "Dono 21/10".

## 24/10/2026, 17:00 UTC (14:00 BRT) — Rotina leve das 13:53

- **Reserva abaixo do planejado:** VK2 em 03/11, 1 noite, direta (95175599), lançada pela VillaRecep1 às 11:40 BRT por **R$ 280** (29% de R$ 952).
- **Beds24 (desde 13:50 UTC):** 2 novas (a de cima e VK2 29/11 no Airbnb, R$ 357). Sem cancelamentos. Os quartos com preço de última hora ainda não venderam: hoje seguem livres Moinhos 5, Panorâmica I 1, Afrodite 1 e Balcony 3; amanhã Moinhos 6, Panorâmica I 4, VK7 6 e Balcony 6.
- **Nada gravado.**

## 24/10/2026, 20:00 UTC (17:00 BRT) — Rotina leve das 16:53

- **Beds24 (desde 16:50 UTC):**
  - nova: Moinhos 31/10, **1 noite** pela Booking, R$ 1.701, numa noite de Finados com estadia mínima de 3 no PriceLabs. Conferir as restrições do plano de tarifa na Booking (pendência `booking-finados-sem-disponibilidade`);
  - a Airbnb 94439616 da Panorâmica I passou de 3 para 4 noites (até 25/10);
  - a Q2 95126778 segue abaixo do planejado (R$ 983 por 2 noites, 40%), já relatada.
- **Moinhos 24/10:** o dono tirou o "noCheckIn" no Beds24. Agora o sábado de 1 noite está aberto a R$ 1.302 (~R$ 553). Seguem 5 livres hoje e 6 amanhã.
- **Nada gravado.** Preços de última hora mantidos até domingo.

## 24/10/2026, 23:00 UTC (20:00 BRT) — Rotina leve das 19:53

- **Beds24 (desde 19:50 UTC):** 1 pedido do Airbnb (VK2 17/11) e 1 alteração de status (check-in da Double). Sem vendas nem cancelamentos; nenhuma reserva abaixo do planejado.
- **Preços de última hora:** sem venda desde ontem. Hoje à noite seguem livres Moinhos 5, Panorâmica I 1, Afrodite 1 e Balcony 3. Domingo: Moinhos 6, Panorâmica I 3, VK7 6 e Balcony 6. A medição do teste fica para a revisão de segunda (`moinhos-fds-2310-medir`).
- **Nada gravado.**

## 25/10/2026, 12:05 UTC (09:05 BRT) — Rotina completa das 07:53

- **Beds24 (desde 22:50 UTC):** 1 pedido do Airbnb (Moinhos 22/11) e 1 cancelamento (VK2 22/11, sem valor). Nenhuma reserva abaixo do planejado. Preços de última hora de domingo no ar e sem venda (Moinhos 6, Panorâmica I 3, VK7 6 e Balcony 6 livres hoje).
- **Ocupação real (Beds24):** 7 dias Recanto 39,3% / Villa 32,4%; 15 dias 36,3% / 29,3%; 30 dias 36,3% / 22,4%.
- **Envio:** último envio em 24/10 14:48 UTC (os 7 quartos).
- **Booking:** domingo, mediana R$ 254 (máx. R$ 463); dias de semana 26-27/10, R$ 253; **Finados (3 noites), mediana R$ 2.312, mínimo R$ 1.231**, com o Recanto na busca com hidro; fds 06-08/11, R$ 1.086. Mercado no Finados: 23,5% / 37,3% / 41,0% (no ano passado, 16% / 22% / 10%).
- **Finados:** a Moinhos está com 1 de 7 em 30 e 31/10 a R$ 2.359 (cerca de R$ 1.000 no preço final, acima da mediana de R$ 771 por noite). O +20% do dono segue valendo (regras 6b); fica a proposta ao dono.
- **Calendário de 12 meses:** 3 sábados da Moinhos em março/2027 tinham caído para 1 noite (PriceLabs automático); voltaram a 2. **Ordem do Recanto:** 11 noites da Panorâmica II regravadas.
- **4c:** as descidas de dia de semana no Recanto, na VK7 e na VK2 param no piso do dono (R$ 500). Desci a Balcony, que não tem piso do dono, e a Panorâmica I e a Afrodite no fds de 06-07/11.

| Mudança | Onde | Antes | Depois (recalculado e conferido às 12:02 UTC) | Como desfazer (pedir ao Claude) |
|---|---|---|---|---|
| 4c: atrás da meta (0/6) | Balcony 26-29/10, 02/11, 03/11 | R$ 813 / R$ 1.168 / R$ 826 | **R$ 737 / R$ 1.003 / R$ 749 ✅** (piso e teto) | voltar a −36% / −8% / −35% |
| 4c: atrás da meta (0-1/6) | Panorâmica I 06-07/11 | R$ 2.175 | **R$ 2.028 ✅** (teto no piso do dono) | voltar o teto a 237% |
| 4c: −5% | Afrodite 06-07/11 | R$ 3.322 | **R$ 3.140 ✅** (teto) | tirar o teto |
| Ordem do Recanto | Panorâmica II 06-07/11, 01-02/01, 21-25/02, 28-29/05/2027 | R$ 2.337 / R$ 3.641 / R$ 1.272 / R$ 2.687 | **R$ 2.019 / R$ 3.355 / R$ 1.240 / R$ 2.480 ✅** | motivo "Dono 21/10" |
| Sábado com 2 noites (Regra H) | Moinhos 06, 13 e 20/03/2027 | 1 noite | **2 noites ✅** | — |

## 25/10/2026, 14:05 UTC (11:05 BRT) — Rotina leve das 10:53: sem acesso ao Beds24

- **Beds24:** o ambiente reiniciou e o arquivo `/root/.beds24/refresh_token` sumiu; não há `BEDS24_REFRESH_TOKEN` nos segredos. Usei a lista do PriceLabs, que atrasa até um dia. **`reservas_abaixo.py` e o movimento do Beds24 ficaram sem rodar.**
- **PriceLabs (reservas feitas de 24 a 25/10):** nenhuma nova desde as 12:59 BRT de ontem (a lista do dia só entra por volta das 10:59 BRT).
- **Nada gravado.** Os preços de última hora de hoje seguem no ar até o fim do dia.

## 25/10/2026, 17:05 UTC (14:05 BRT) — Pedido do dono: lotar as diárias em aberto das próximas 2 semanas

- **Pedido do dono:** "Verifique uma estratégia de precificação para lotarmos as diárias em aberto nas próximas 2 semanas." Diagnóstico: 290 diárias livres de 26/10 a 08/11 (Moinhos 70, Panorâmica I 51, Panorâmica II 14, Afrodite 10, VK7 58, Balcony 73, VK2 14). Encalhe principal nos dias de semana: Recanto e Villa a ~R$ 500 (piso do dono), contra mediana ~R$ 340 das pousadas com hidro e nota 8+ (Booking, 28/10: DOM R$ 337, Canada Lodge R$ 394, Serra da Estrela R$ 579). Fds 06-08/11: mediana ~R$ 770 por noite.
- **Escolhas do dono:** dias de semana com Recanto ~R$ 420 e Villa ~R$ 380; **Finados mantido** (+20%); fds 06-07/11 com Panorâmica I ~R$ 760 e Moinhos ~R$ 720 na sexta.

| Mudança (preço final aproximado, Genius 3) | Onde | Depois (piso = teto, enviado) | Conferido |
|---|---|---|---|
| Dias de semana 26-29/10, 02-05/11 e 08/11 | Moinhos | R$ 1.032 (~R$ 423) | ✅ recálculo |
| idem | Panorâmica I (fora 29/10, 5/6 vendidas) | R$ 1.110 (~R$ 455) | ✅ |
| idem | Panorâmica II (entre as duas; fora 29/10) | R$ 1.081 (~R$ 443) | ✅ |
| idem | Afrodite (5% acima; fora 29/10 e as noites vendidas) | R$ 1.180 (~R$ 484) | ✅ |
| idem | VK7 (limite de segurança R$ 700) | R$ 725 (~R$ 384) | ✅ |
| idem | VK2 (noites livres) | R$ 737 (~R$ 390) | lido de volta (recálculo: Beds24 indisponível) |
| idem | Balcony (Regra G abaixo da VK7) | R$ 660 (~R$ 350) | lido de volta (idem) |
| Fds 06/11 | Moinhos | R$ 1.713 (~R$ 728) | ✅ |
| Fds 06-07/11 | Panorâmica I | R$ 1.806 (~R$ 767) | ✅ |
| Fds 06/11 e 07/11 | Panorâmica II (ordem) | R$ 1.749 / R$ 1.892 | ✅ |

- **Sem mudança:** Finados (30/10 a 01/11), Moinhos 07/11 (6/7) e as noites esgotadas. As regras com motivo "Dono 25/10" vencem sozinhas depois de 08/11.
- **Recálculo feito** para os preços saírem no próximo envio. **Sync Now** pedido ao dono (26/10 é D1).

## 25/10/2026, 17:15 UTC (14:15 BRT) — Rotina leve das 13:53 (sem Beds24)

- **PriceLabs (reservas feitas em 25/10):**
  - VK7 25-26/10, direta, 2 noites por R$ 696 (R$ 348 por noite; ~49% do preço de hoje). É lançamento manual; conferir com a recepção;
  - VK7 08-10/11 pela Booking, 3 noites, R$ 1.240 (R$ 413 por noite), já no preço novo;
  - VK2 29/11 pelo Airbnb (R$ 357).
- **Recálculo:** VK2 ✅ (R$ 737 nos dias de semana). Balcony em 429; as regras dela estão lidas de volta.
- **Nada gravado.**

## 25/10/2026, 17:50 UTC (14:50 BRT) — Pedido do dono: validar os valores finais depois do Sync Now

- **Envio:** os 7 quartos foram enviados às 17:23 UTC (14:23 BRT), depois das gravações e do recálculo.
- **Propriedade certa na Booking (dono, 25/10):** `pousada-recanto-dos-moinhos` (**id 7562933**, "Recanto dos Moinhos - Boutique Hotel") é a que recebe os preços do Beds24. A `pousada-vista-panoramica-campos-do-jordao` (id 15459723, "Pousada Recanto dos Moinhos - Boutique") **continua ativa com os preços antigos** (R$ 560 a R$ 607 nos dias de semana). Era ela que as buscas por nome encontravam.
- **Booking, id 7562933 (preço público, sem Genius):**
  - dias de semana 26, 28 e 29/10, 03 e 08/11: R$ 679,06 = Moinhos R$ 1.032 × 0,658 ✅ (preço novo);
  - fds 06-08/11 (2 noites): R$ 2.376,70 = Panorâmica I R$ 1.806 × 2 × 0,658 ✅;
  - Finados 30/10-02/11: R$ 4.428 (3 noites), mantido.
- **Booking, Villa (id 14824789):** 03, 04 e 08/11 R$ 430,77 = Balcony R$ 660 × 0,653 ✅. 26 a 28/10 R$ 349,27 = cerca de 20% abaixo disso: indica uma oferta de última hora ativa na extranet da Villa.
- **Leitura:** o preço público da Booking é cerca de 65,8% do enviado. Os "valores finais" do plano (R$ 423 / R$ 384) são do Genius 3 (fator 0,41 / 0,53). O hóspede sem Genius vê R$ 679 na Moinhos e R$ 434 na Balcony, e os concorrentes da busca também aparecem no preço público (mediana com hidro ~R$ 340).

## 25/10/2026, 17:40 UTC (14:40 BRT) — Pedido do dono: preço público da Booking ~R$ 450 nos dias de semana

- **Escolha do dono:** "Público ~R$ 450": a Moinhos aparece por ~R$ 450 na Booking sem Genius (fator 0,658 do enviado); os outros quartos seguem a ordem do Recanto e a Regra G. Datas: 26-29/10, 02-05/11 e 08/11, só nas noites com quarto livre.

| Quarto | Antes (enviado) | Depois (piso = teto, enviado) | Público Booking | Genius 3 (aprox.) |
|---|---|---|---|---|
| Moinhos | R$ 1.032 | R$ 692 | ~R$ 455 | ~R$ 284 |
| Panorâmica II (fora 29/10 e 08/11) | R$ 1.081 | R$ 731 | ~R$ 481 | ~R$ 300 |
| Panorâmica I (fora 29/10) | R$ 1.110 | R$ 740 | ~R$ 487 | ~R$ 303 |
| Afrodite (fora 26/10, 29/10 e 08/11) | R$ 1.180 | R$ 1.060 (limite de segurança) | ~R$ 697 | ~R$ 435 |
| VK7 | R$ 725 | R$ 710 (limite de segurança) | ~R$ 467 | ~R$ 376 |
| VK2 (noites livres) | R$ 737 | R$ 711 | ~R$ 465 | ~R$ 377 |
| Balcony | R$ 660 a R$ 1.003 | R$ 635 (limite de segurança) | ~R$ 415 | ~R$ 337 |

- **Validação:** 53 itens, 0 erros. As 7 gravações foram lidas de volta ✅.
- **Recálculo:** Panorâmica II ✅ (R$ 731). Os outros 6 deram 429 ou Beds24 indisponível; nova tentativa na próxima rotina. Sem recálculo o envio sai com o preço antigo, então o **Sync Now do dono** é necessário.
- **Sem mudança:** fds 06-07/11 e Finados.

## 25/10/2026, 19:55 UTC (16:55 BRT) — Rotina leve das 16:53 (sem Beds24)

- **PriceLabs (reservas feitas em 25/10):** nenhuma nova desde a rotina das 13:53 (seguem a VK7 25-26/10 direta, a VK7 08-10/11 Booking e a VK2 29/11 Airbnb).
- **Recálculo:** Moinhos ainda em 429. O último recálculo dos 6 quartos sem a mudança do "público ~R$ 450" segue sendo o de 17:23 UTC, e não houve Sync Now depois disso. Sem o Sync do dono, o preço novo só sai no envio automático da madrugada.
- **Nada gravado.**

## 25/10/2026, 22:55 UTC (19:55 BRT) — Rotina leve das 19:53 (sem Beds24)

- **PriceLabs (reservas feitas em 25/10):** nenhuma nova desde as 13:53.
- **Recálculo:** os 6 quartos ainda em 429; o último recálculo continua sendo o das 17:23 UTC, sem Sync Now depois disso. O preço "público ~R$ 450" (exceto a Panorâmica II) só sai no envio automático da madrugada ou no Sync do dono. Conferir às 07:53 de 26/10 (rotina completa e revisão semanal).
- **Nada gravado.**

## 26/10/2026, 11:00 UTC (08:00 BRT) — Pedido do dono: completar os dias de semana do Recanto, mínimo de R$ 300

- **Pedido:** "Vamos ajustar o valor da recanto dos moinhos. Estamos praticamente sem reservas durante a semana para essas duas semanas. Vamos completar as reservas. Valor minimo de diária de 300 reais."
- **Escolhas do dono:** R$ 300 é o mínimo que qualquer hóspede paga, inclusive o Genius 3 ("Ninguém paga menos de 300"). A Afrodite segue a ordem do Recanto (5% acima da Panorâmica I), abaixo do limite de segurança dela por ordem expressa (exceção registrada em `quartos.json`, até 08/11).
- **Noites:** 26-29/10, 02-05/11 e 08/11, só as que têm quarto livre (fora Panorâmica I, Panorâmica II e Afrodite em 29/10; Panorâmica II e Afrodite em 08/11; Afrodite em 26/10). Fds 06-07/11 e Finados sem mudança. Villa sem mudança.

| Quarto | Antes (enviado) | Depois (piso = teto) | Genius 3 | Público Booking |
|---|---|---|---|---|
| Moinhos | R$ 692 | R$ 739 (−37%) | ~R$ 303 | ~R$ 486 |
| Panorâmica II | R$ 731 | R$ 747 (−53%) | ~R$ 306 | ~R$ 492 |
| Panorâmica I | R$ 740 | R$ 755 (−49%) | ~R$ 310 | ~R$ 497 |
| Afrodite | R$ 1.060 | R$ 800 (−60%) | ~R$ 328 | ~R$ 526 |

- **Validação:** 30 itens, 0 erros (validador com a exceção nova do dono). As 4 gravações foram lidas de volta ✅.
- **Recálculo:** 429 nos quartos testados (Moinhos, Panorâmica I e Afrodite); o saldo volta por volta das 16-17 UTC. **Sync Now do dono** pedido.
- **Como desfazer:** voltar às regras "Dono 25/10" (Moinhos −41%, Panorâmica II −54%, Panorâmica I −50%, Afrodite −47%).

## 26/10/2026, 11:10 UTC (08:10 BRT) — Pedido do dono: conferir se o mínimo de R$ 300 propagou

- **PriceLabs:** recálculo automático às 10:57 UTC (Moinhos R$ 739, Panorâmica II R$ 747, Panorâmica I R$ 755) e **envio às 10:57:52 UTC** ✅.
- **Booking, id 7562933 (preço público):** 27-28/10 e 03-04/11 a **R$ 486,26** = Moinhos R$ 739 × 0,658 ✅. O Genius 3 (~R$ 303) não aparece no conector.
- **Anúncio antigo (id 15459723) continua no ar** com os preços antigos: R$ 500,83 (27/10) e R$ 607,27 (03/11). Pendência do dono.

## 26/10/2026, 11:40 UTC (08:40 BRT) — Rotina das 07:53 (segunda): coleta completa, nada gravado além do pedido do dono

- **Envio:** os 7 quartos foram enviados entre 10:57 e 10:58 UTC, com o mínimo de R$ 300 do Recanto ✅.
- **Ocupação (calendário do PriceLabs):**
  - Hotel: 47% em 7 dias, 45% em 15 dias e 35% em 30 dias.
  - Recanto: 41% / 38% / 36%.
  - Villa: 53% / 53% / 35%.
  - Atrás da meta de 7 dias (70%): todos os quartos menos a VK7 (71%).
- **Reservas (24 a 26/10):** 7, sem cancelamentos. No Recanto: direta na Moinhos 24/10 (R$ 3.332 por 1 noite, a conferir) e Booking na Moinhos 31/10 (R$ 1.500). Duas reservas do Airbnb iguais na VK2 em 29/11 (possível duplicata).
- **Mercado (Booking, preço público por estadia):**

  | Período | Mediana | Máximo | Recanto (id 7562933) | Villa |
  |---|---|---|---|---|
  | 27-28/10 | R$ 250 | R$ 394 | ~R$ 486 | R$ 336 |
  | fds 06-08/11 (2 noites) | R$ 1.100 | R$ 2.160 | R$ 2.377 | sem disponibilidade |
  | Consciência Negra 20-22/11 (2 noites) | R$ 1.571 | R$ 3.157 | R$ 3.411 | R$ 2.934 |

  O agente de concorrentes leu o anúncio antigo (id 15459723) nas datas úteis (R$ 501 e R$ 560); os valores do Recanto acima são do anúncio certo.
- **Ocupação do mercado (PriceLabs):** 26/10 a 05/11 de 6% a 18% nos dias úteis e 27% a 44% em Finados; novembro em 13% (14% no ano passado).
- **Teste da Moinhos de 23-25/10 (piso R$ 700 e sábado de 1 noite):** nenhuma venda pela Booking depois do teste.
- **Não feito hoje:**
  - recálculo e conferência por quarto, auditoria de 12 meses e da ordem do Recanto (429: saldo de recálculo usado em 25/10);
  - painel semanal, desempenho do mês, faturamento e `reservas_abaixo.py` (sem o segredo do Beds24);
  - varredura de concorrência (roda em outra sessão).
- **Nada gravado** além do pedido do dono das 11:00 UTC: os dias úteis até 08/11 e o fds 06-07/11 estão sob ordens do dono.

## 26/10/2026, 14:00 UTC (11:00 BRT) — Rotina leve das 10:53 (sem Beds24)

- **Envio do dia:** feito às 10:57-10:58 UTC nos 7 quartos ✅. Booking (id 7562933): Moinhos a R$ 486,26 em 27/10 e 03/11 ✅ (conferido às 11:10 UTC).
- **Reservas feitas em 26/10:** a lista do PriceLabs ainda não foi importada (chega por volta das 10:59 BRT), e o calendário segue o recálculo das 10:57 UTC. Fica para a rotina das 13:53.
- **Nada gravado.** Esperando o dono rever Genius e promoções na extranet (pendência genius-alavanca-2610).

## 26/10/2026, 17:00 UTC (14:00 BRT) — Rotina leve das 13:53 (sem Beds24)

- **Reservas novas na lista do PriceLabs (todas da Villa):**
  - Balcony 26/10, Booking, 1 noite, R$ 248 (feita às 09:54 BRT de hoje; 85% do esperado, R$ 635 × 0,46);
  - Balcony 30-31/10 (Finados), Booking, **2 noites**, R$ 1.383 (R$ 691 por noite). Finados tem estadia mínima de 3 noites: a recepção alterou ou o canal deixou passar;
  - VK2 25/10 (R$ 394) e 25-27/10 (R$ 1.296), Booking. As duas foram lançadas juntas com a Balcony de Finados às 16:49 BRT de 25/10: lote do Beds24 (edição da equipe), não conta como ritmo de venda;
  - VK2 pelo Airbnb: 08/11 (R$ 380) e 22/11 (R$ 476).
- **Recanto:** nenhuma reserva nova desde o mínimo de R$ 300 (público ~R$ 486).
- **Abaixo de 70% do esperado:** nenhuma (conta pelo fator do canal, sem o Beds24).
- **Nada gravado:** nenhuma noite cruzou o gatilho da 4c. Dias úteis e fds 06-07/11 seguem sob ordens do dono.

## 26/10/2026, 19:55 UTC (16:55 BRT) — Rotina leve das 16:53 (sem Beds24)

- **Reservas feitas em 26/10:** só a Balcony 26/10 (R$ 248), já relatada às 13:53. Recanto sem vendas novas.
- **Nada gravado.**

## 26/10/2026, 20:30 UTC (17:30 BRT) — Pedido do dono: escada de subida por reserva até o alto padrão

- **Pedido:** "a cada reserva que entrar, vamos aumentando o preço gradualmente até chegarmos no nível de médio-alto padrão [...] começar com esse mínimo [...] e alavancar até chegar no valor de alto padrão [...]. Faça uma análise 4 vezes ao dia."
- **Regra nova (regras.md 6b; `scripts/escada_reservas.py`):**
  - cada reserva nova sobe 7% o piso = teto do quarto nas noites da estadia;
  - teto de alto padrão: R$ 500 no Genius 3 de domingo a quinta (Recanto R$ 1.220 enviados; Villa R$ 943) e R$ 850 na sexta e no sábado (Recanto R$ 2.000; Villa R$ 1.478); Balcony 90% do teto da VK2;
  - a noite de hoje não sobe; lote do Beds24 e edição da equipe não contam; cancelamento desce um degrau;
  - no Recanto, a ordem é refeita na mesma noite.
- **Análise:** nas 4 rotinas leves (10:53, 13:53, 16:53 e 19:53) e na completa das 07:53, que já estão na agenda.
- **Reservas desde as regras de mínimo (25/10, 17:40 UTC):**
  - Balcony 26/10, R$ 248: noite de hoje, não sobe;
  - VK2 22/11 pelo Airbnb: fora da janela da escada;
  - VK2 25/10, VK2 25-27/10 e Balcony 30-31/10, lançadas no mesmo segundo: lote do Beds24, não contam.
- **Nada gravado:** nenhum degrau devido agora.

## 26/10/2026, 22:55 UTC (19:55 BRT) — Rotina leve das 19:53 (sem Beds24)

- **Reservas feitas em 26/10:** só a Balcony 26/10 (R$ 248, noite de hoje). Nenhuma nova desde as 13:53.
- **Escada por reserva:** nenhum degrau devido. **Nada gravado.**

## 27/10/2026, 00:35 UTC (26/10, 21:35 BRT) — Pedido do dono: subir os preços onde já vendeu e chegar perto das metas

- **Pedido:** "Verifique novamente e vamos ajustar os valores para cima. Pois já temos datas vendidas e preciso que cheguemos perto dos nossos objetivos."
- **Vendas vistas no recálculo dos 7 quartos (00:18 UTC; a lista de reservas do PriceLabs ainda não as traz):**
  - Panorâmica I: +1 em 01, 02 e 08/11, +2 em 07/11, +3 em 13/11, +4 em 14/11, +2 em 18 e 19/11, +3 em 20/11, +1 em 21 e 22/11. Em 28, 29 e 30/10, −1 em cada (cancelamento ou troca de quarto);
  - Moinhos: +2 em 31/10 e 01/11 (Finados);
  - VK2: +1 em 29/10, 04/11 e 10/11;
  - **cancelamentos em Finados:** Panorâmica II de 30/10 a 01/11 ficou com as 2 livres; VK7 −1 em cada noite. Finados fica como está (dono, 25/10).

| Mudança | Onde | Antes | Depois (recalculado e conferido às 00:30 UTC) | Como desfazer |
|---|---|---|---|---|
| Escada por reserva | Panorâmica I 02/11 e 08/11 | R$ 755 | **R$ 814 ✅** (piso = teto) | voltar a −49% |
| Escada (+2) e ordem | Panorâmica I 07/11 | R$ 1.806 | **R$ 2.028 ✅** (alto padrão, ≥ Moinhos R$ 2.018) | voltar a +22% |
| Ordem do Recanto | Afrodite 02/11 | R$ 800 | **R$ 860 ✅** | voltar a −60% |
| 4c: à frente da meta | Panorâmica I 13 e 14/11 (4/6) | R$ 2.193 | **R$ 2.412 ✅** (piso) | piso +37% |
| 4c: à frente da meta | Panorâmica I 19/11 (5/6) | R$ 1.909 | **R$ 2.102 ✅** | piso +29% |
| 4c: à frente da meta | Panorâmica I 22/11 (4/6) | R$ 1.228 | **R$ 1.362 ✅** | piso −17% |
| Ordem do Recanto | Afrodite 19/11 | R$ 2.020 | **R$ 2.220 ✅** | piso +1% |

- **Validação:** 9 itens, 0 erros. Envio: no envio automático da madrugada (todas as datas a partir de 02/11).
- **Novembro contra o mínimo do dono (reservas na lista do PriceLabs, sem as vendas de hoje):** Recanto ~R$ 99 mil de R$ 220 mil (45%); Villa ~R$ 47 mil de R$ 150 mil (31%). Ritmo esperado no dia 1: 40%. Recanto no ritmo, Villa atrás. Para fechar o mínimo, as noites livres precisam render ~R$ 390 (Recanto) e ~R$ 350 (Villa) por noite já com o desconto das plataformas, ou seja, ~R$ 800 e ~R$ 765 enviados em média.

## 27/10/2026, 11:10 UTC (08:10 BRT) — Rotina das 07:53: troca do anúncio do Recanto na Booking, nada gravado

- **Coleta:** o workflow pegou reservas, logs, mercado e concorrentes, mas os 7 calendários vieram vazios (LISTING_NO_DATA: o PriceLabs estava refazendo os dados). Recalculei os 7 quartos às 11:01 UTC ✅.
- **Envio:** o último é de 26/10 às 20:57 UTC. As gravações de 00:24 UTC (Panorâmica I e Afrodite, de 02 a 22/11) estão recalculadas (R$ 814, R$ 2.028, R$ 2.412, R$ 2.102, R$ 860, R$ 2.220), mas ainda não foram enviadas. Sync Now pedido ao dono.
- **Vendas desde 00:18 UTC (pelo calendário):** só a Panorâmica I de hoje (27/10, +1). Noite de hoje não sobe: **nenhum degrau devido**.
- **Ocupação:**
  - Recanto: 40% em 7 dias, 39% em 15 dias e 40% em 30 dias;
  - Villa: 51% / 52% / 35%.
- **Booking: o Recanto mudou de anúncio.** A 7562933 não aparece mais (sem disponibilidade, por nome e por coordenadas). A 15459723 (5 estrelas, 226 avaliações, nota 8,4) agora segue o Beds24: Moinhos a R$ 436,97 em 28/10 e 03/11 (R$ 739 × 0,591) e R$ 2.194,29 no fds 06-08/11 (R$ 3.731 × 0,588). O fator público ÷ enviado caiu de 0,658 para ~0,59. O desconto do Genius 3 nesse anúncio é desconhecido.
- **Mercado (preço público por estadia):** dia útil 28/10 mediana R$ 248 e máximo R$ 446; 03/11 mediana R$ 275 e máximo R$ 394; fds 06-08/11 mediana R$ 1.062; fds 13-15/11 mediana R$ 1.317. Villa: R$ 336 nos dias úteis (4ª mais cara de 19); R$ 2.288 no fds 13-15/11 (3ª mais cara).
- **Nada gravado.**

> **Regras em vigor:** desde 06/10/2026 elas ficam em `.claude/skills/gestao-receita-pousadas/references/regras.md`. Este arquivo guarda só o histórico das mudanças.

## O que só pode ser feito na tela (passo a passo para você)

1. **Safety Minimum Price → "Do Not Apply"** (Dynamic Pricing → Customizations → aba Groups → Edit no grupo Recanto dos Moinhos → All Customizations → Safety Minimum Price). Anote o valor atual antes.
2. **Sincronização às 06:00** (Account Settings → Sync Settings → "Specify Your Own Time" → 06:00). Opcional: 12:00 e 18:00 como sincronizações extras (US$ 1 por quarto por mês, cada uma).
3. **Tabela de ajuste por ocupação** na Queen Spa (7), na Double e na Villa King Spa (7): Review Prices do quarto → Edit em Customizations → All Customizations → "Multi-Room Occupancy-Based Adjustment" → Custom → "All Days" → preencher a tabela da seção 5.2 do relatório → Save. Ideal fazer só depois de 28/09, para medir antes o efeito das mudanças de hoje.
4. **Balcony:** no mesmo menu, deixar o ajuste por ocupação em "None".
5. **"Sync Now"** no PriceLabs, se quiser que os preços novos cheguem aos canais antes da sincronização automática.

## Próximos passos

- **28/09:** revisão automática pelo Claude: ocupação contra as metas, pickup, valor pago contra o enviado e resultado do teste de 1 noite.
- **Queen Spa (2):** checar na extranet e no Beds24 a causa dos 66% e confirmar os cancelamentos de hoje.
- **Dia 10:** passo 2 do preço base, se a recomendação do PriceLabs ainda indicar.

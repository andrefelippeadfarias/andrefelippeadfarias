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

**Regra de vigilância:** se até terça 06/10 à noite a sexta 09/10 da Queen (7) continuar com 3 ou menos vendidas, volta para R$ 2.420. Se até quarta 07/10 as sextas da Villa King Spa (7), Queen (2) e Afrodite não venderem, abrir o sábado com 1 noite.

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

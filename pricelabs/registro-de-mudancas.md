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

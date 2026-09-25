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

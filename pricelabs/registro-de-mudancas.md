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

### Próximos passos previstos

- **Dia 4:** passo 1 do preço base (Double 1.480, Queen Spa (2) 1.450, Villa King Spa (7) 1.480, Villa King Spa (2) 1.270, Queen Spa (7) 1.380).
- **Dia 7:** ligar a tabela de ocupação na tela, para Queen Spa (7), Double e Villa King Spa (7). Na Balcony, deixar o ajuste por ocupação em "Nenhum".
- **Antes disso:** acompanhar por 3 dias o pickup e o valor pago, para separar o efeito de cada mudança.

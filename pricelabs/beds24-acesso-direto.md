# Beds24: de onde vêm os valores e como ler direto (19/10/2026)

## 1. De onde a Skill lia os valores até hoje
- **Fonte:** PriceLabs, ferramenta `get_pms_reservations` (API `reservation_data`), que é uma cópia das reservas do Beds24 feita pelo PriceLabs.
- **Campos usados:** `rental_revenue` (diária, antes da comissão), `total_cost` quando o primeiro vinha vazio, `no_of_days`, `booking_status`.
- **Ocupação:** calendário do PriceLabs (`multi_unit_occupancy`), que conta como vendida qualquer unidade que o Beds24 marcou como indisponível.

## 2. Comparação com o relatório do Beds24 (consolidado executivo de 19/10, 10:58)
Unidades-noite (U/N) e receita de hospedagem, noites de 19/10 em diante:

| Janela | Pousada | Beds24 U/N (só "Confirmado") | PriceLabs U/N (todas as ativas) | Beds24 receita | PriceLabs `rental_revenue` |
|---|---|---|---|---|---|
| 7 noites | Recanto | 36 | 48 | R$ 16.607 | R$ 21.710 |
| 7 noites | Villa | 37 | 44 | R$ 19.228 | R$ 20.114 |
| 30 noites | Recanto | 132 | 170 | R$ 83.760 | R$ 99.010 |
| 30 noites | Villa | 87 | 103 | R$ 47.601 | R$ 49.668 |
| 60 noites | Recanto | 205 | 258 | R$ 134.141 | R$ 156.840 |
| 60 noites | Villa | 115 | 133 | R$ 66.618 | R$ 69.465 |

**Por que diferem:**
1. **Status.** O relatório do Beds24 conta só "Confirmado" (156 reservas) e deixa de fora "Novo" (48) e "Inquiry" (19). O PriceLabs conta todas as que ocupam o quarto. No Beds24, as reservas das OTAs costumam entrar como "Novo" e ocupam o quarto igual. Se elas forem reais, a ocupação verdadeira fica entre os dois números.
2. **Receita.** O Beds24 usa a receita de hospedagem cadastrada dia a dia (Daily Financial Summary). O PriceLabs traz o valor da reserva, com falhas: reservas do canal "outros" sem valor e Airbnb com cerca de 60% do total.
3. **Contagem de noites (erro meu, corrigido em 19/10).** No PriceLabs o `check_out` é a última noite, não o dia da saída. Até 19/10 eu rateava pela diferença das datas, o que contava uma noite a menos por reserva e jogava receita para o começo da estadia. O correto é ratear por `no_of_days`.

## 3. Como ler direto do Beds24 (API V2, só leitura)
- **Teste de rede feito em 19/10:** `https://beds24.com/api/v2` responde daqui ("Token not valid" sem credencial). O acesso de rede está liberado; falta só a credencial.
- **Script pronto:** `.claude/skills/gestao-receita-pousadas/scripts/beds24.py` (`teste`, `reservas`, `janelas`). Conta as noites com saída exclusiva, separa os status (confirmado, novo, pedido, inquiry, cancelado), usa os itens de fatura de diária quando vierem e não lê dados de hóspedes.
- **O que o dono faz (uma vez), pelo caminho mais simples (token de longa duração, só leitura):**
  1. Entrar no painel do Beds24 com o usuário dono da conta e abrir **https://beds24.com/control3.php?pagetype=apiv2** (no menu: CONFIGURAÇÕES/SETTINGS → APPS & INTEGRATIONS → API; em telas antigas, SETTINGS → MARKETPLACE → API).
  2. Na área **API V2**, escolher gerar um **Long Life Token** (token de longa duração; o Beds24 só permite leitura nele).
  3. Marcar os escopos: **read:bookings**, **read:bookings-financial**, **read:inventory** e **read:properties**. **Não** marcar bookings-personal (dados de hóspedes). Se o Recanto e a Villa estiverem em propriedades vinculadas, marcar **Allow linked properties**.
  4. Copiar o token gerado e cadastrar no Claude Code: menu do ambiente na barra de título da sessão → **Edit** → **Network secrets** (ou "API credentials"; em app antigo, variável de ambiente) com o nome **`BEDS24_TOKEN`**. Uma sessão nova lê a variável.
  5. **Nunca colar o token no chat.**
  - Alternativa: código de convite (vale poucos minutos) trocado por um token de atualização; aí o nome da variável é `BEDS24_REFRESH_TOKEN`.
- **Depois disso, a Skill:** roda `beds24.py teste`, compara com este relatório (as janelas devem bater com o "Confirmado" + "Novo") e passa a usar o Beds24 como fonte do faturamento e da ocupação real. O PriceLabs continua sendo a fonte de preço e de calendário de venda.

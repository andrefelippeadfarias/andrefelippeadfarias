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
- **O que o dono faz (uma vez).** Na tela de 19/10 (Marketplace → API) não há botão para "Long Life Token"; o caminho é o código de convite trocado por um token de atualização:
  1. Em **https://beds24.com/control3.php?pagetype=apiv2** (Configurações → Marketplace → API), clicar em **Generate invite code**.
  2. Marcar só **leitura** (read) em: **bookings**, **bookings-financial**, **inventory** e **properties**. **Não** marcar bookings-personal (hóspedes), accounts nem channels, e nada de write/all. Se a janela oferecer gerar um **long life token** em vez do convite, prefira ele: é só de leitura e não precisa de troca (variável `BEDS24_TOKEN`).
  3. O convite vale poucos minutos. Logo em seguida, abrir **https://beds24.com/api/v2**, seção **Authentication → GET /authentication/setup → Try it out**, colar o convite no campo **code**, pôr em **deviceName** um nome como `Claude-leitura` e clicar em **Execute**.
  4. Na resposta, copiar o valor de **`refreshToken`** (ele aparece também na lista "Refresh Tokens" da tela do Beds24).
  5. No Claude Code: menu do ambiente na barra de título da sessão → **Edit** → **Network secrets** (ou "API credentials"; em app antigo, variável de ambiente) → criar **`BEDS24_REFRESH_TOKEN`** com esse valor. Uma sessão nova lê a variável. **Nunca colar no chat.**
  6. O token de atualização não vence enquanto for usado ao menos uma vez a cada 30 dias (as rotinas usam todo dia).
- **Não reaproveitar o token "HotelClaw-v57"** que já existe na tela: ele tem acesso total (leitura e escrita, dados de hóspedes, conta e canais). O nosso deve ser separado e só de leitura.
- **Depois disso, a Skill:** roda `beds24.py teste`, compara com este relatório (as janelas devem bater com o "Confirmado" + "Novo") e passa a usar o Beds24 como fonte do faturamento e da ocupação real. O PriceLabs continua sendo a fonte de preço e de calendário de venda.

# Previsão realista de faturamento, nov/2026 a abr/2027 (feita em 27/10/2026)

Faturamento = valor das diárias no Beds24 (rental_revenue; sem ele, total_cost), antes da comissão das OTAs e sem extras. Livro = reservas confirmadas na lista do PriceLabs em 27/10 (as vendas vistas só no calendário entram na rodada seguinte).

## Como foi feita

Três leituras por mês; a previsão é a mediana delas (nov e dez) ou a média das duas (jan a abr), nunca abaixo do livro:
- **A. Mesmo mês, nível atual:** Recanto = 2024 × 0,69 (o Recanto hoje fatura ~69% de 2024: 1º tri 0,68, setembro 0,70); jan a mar = o realizado de 2026 (março +R$ 20 mil pela Páscoa, que em 2027 cai em março). Villa = 2024 × 1,45 (out/2026 ÷ out/2024) em nov e dez; jan a mar = 2026 (março +R$ 12 mil pela Páscoa); abril = média do 1º tri de 2026.
- **B. Curva de reservas** (estadias de 07/09 a 26/10, 822 noites): com 3 dias de antecedência, só ~52% (Recanto) e ~57% (Villa) da receita está vendida; com 7 dias, 35% e 40%; com 14 dias, 15% e 23%; com 30 dias, ~8%. Projeção noite a noite = livro ÷ fração da curva, até a lotação. Só para nov e dez: depois disso o livro é pequeno demais e a curva multiplica ruído.
- **C. Modelo de mercado** de 14/10 (`dados/metas_faturamento.json`, cenário realista).

Ticket médio realizado de 07/09 a 26/10: Recanto R$ 553 e Villa R$ 563 por noite.

## Mês a mês

| Pousada | Mês | Livro hoje | A | B | C | **Previsão realista** | Mínimo do dono | % do mínimo | Falta |
|---|---|---|---|---|---|---|---|---|---|
| Recanto | nov/26 | R$ 105.150 (149 noites) | R$ 180.563 | R$ 309.836 | R$ 182.612 | **R$ 182.612** | R$ 220.000 | 83% | R$ 37.388 |
| Recanto | dez/26 | R$ 48.347 (61 noites) | R$ 174.693 | R$ 245.951 | R$ 265.465 | **R$ 245.951** | R$ 350.000 | 70% | R$ 104.049 |
| Recanto | jan/27 | R$ 30.135 (36 noites) | R$ 214.715 | — | R$ 205.581 | **R$ 210.148** | R$ 350.000 | 60% | R$ 139.852 |
| Recanto | fev/27 | R$ 7.588 (9 noites) | R$ 155.011 | — | R$ 142.131 | **R$ 148.571** | R$ 220.000 | 68% | R$ 71.429 |
| Recanto | mar/27 | R$ 5.274 (10 noites) | R$ 154.068 | — | R$ 134.823 | **R$ 144.446** | R$ 220.000 | 66% | R$ 75.554 |
| Recanto | abr/27 | R$ 1.202 (1 noites) | R$ 186.578 | — | R$ 207.940 | **R$ 197.259** | R$ 220.000 | 90% | R$ 22.741 |
| Villa | nov/26 | R$ 50.806 (79 noites) | R$ 151.982 | R$ 174.237 | R$ 162.233 | **R$ 162.233** | R$ 150.000 | 108% | R$ 0 |
| Villa | dez/26 | R$ 33.910 (45 noites) | R$ 189.831 | R$ 225.945 | R$ 257.064 | **R$ 225.945** | R$ 300.000 | 75% | R$ 74.055 |
| Villa | jan/27 | R$ 6.456 (10 noites) | R$ 118.636 | — | R$ 192.139 | **R$ 155.388** | R$ 300.000 | 52% | R$ 144.612 |
| Villa | fev/27 | R$ 3.460 (3 noites) | R$ 115.321 | — | R$ 128.649 | **R$ 121.985** | R$ 150.000 | 81% | R$ 28.015 |
| Villa | mar/27 | R$ 1.619 (3 noites) | R$ 75.973 | — | R$ 127.243 | **R$ 101.608** | R$ 150.000 | 68% | R$ 48.392 |
| Villa | abr/27 | R$ 3.167 (7 noites) | R$ 99.310 | — | R$ 192.893 | **R$ 146.102** | R$ 150.000 | 97% | R$ 3.898 |
| **Recanto** | **6 meses** | R$ 197.696 | | | | **R$ 1.128.987** | R$ 1.580.000 | 71% | R$ 451.013 |
| **Villa** | **6 meses** | R$ 99.418 | | | | **R$ 913.261** | R$ 1.200.000 | 76% | R$ 286.739 |

## Limites
- A curva usa só setembro e outubro de 2026 (o Beds24 começou no fim de agosto) e falta 2025 inteiro no histórico. Na Villa de jan a abr, as leituras A e C se afastam muito (ex.: jan R$ 119 mil × R$ 192 mil): é o mês menos previsível.
- Refazer no dia 1 de cada mês com o livro novo e o mês fechado.

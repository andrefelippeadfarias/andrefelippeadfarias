# Fins de semana e feriados, novembro de 2026 a janeiro de 2027, contra a concorrência

Varredura de 01/11/2026, 21:10 a 21:20 BRT (02/11 00:10 a 00:20 UTC), pelo conector oficial da Booking.

**Como os números foram montados**

- Mercado: 77 concorrentes com hidromassagem e vista (`pricelabs/pesquisa-booking/concorrentes.json`), com café da manhã, 2 adultos e 1 quarto.
- A Booking mostra o quarto mais barato disponível de cada hotel. Isso é o "preço da lista", o que o hóspede vê na busca.
- "Com hidro" é o preço da lista × r_hidro do hotel. É uma estimativa do quarto com banheira; onde ela não foi medida, usei o padrão de 1,395.
- A média é aparada em 10% de cada lado, porque um hotel marcou R$ 13.246 em 29-31/01.
- Nossos preços são os da Booking na mesma hora: Recanto pelas coordenadas (id 7562933) e Villa pelo nome (id 14824789).
- Os preços de cada quarto vêm do PriceLabs (preço calculado, 01/11 23:25 UTC). Cada um foi multiplicado pela razão Booking ÷ enviado medida na mesma estadia: 0,61 a 0,63 no Recanto e 0,62 a 0,65 na Villa.
- "pN" é o percentil, ou seja, quantos concorrentes cobram menos que nós. Balcony e preço de vitrine são comparados com a lista crua; as suítes com banheira, com o preço com hidro.

## 1. Valor médio por noite: mercado e nós

| Estadia | Concorrentes com preço | Média da lista | Mediana da lista | Mediana com hidro | Recanto na Booking | Villa na Booking | Vendido Recanto / Villa |
|---|---|---|---|---|---|---|---|
| 06/11→08/11 (fim de semana) | 63 de 77 | R$ 918 | R$ 784 | R$ 1.235 | R$ 1.704 (p94) | R$ 679 (p33) | 81% / 20% |
| 13/11→15/11 (fim de semana) | 62 de 77 | R$ 914 | R$ 827 | R$ 1.247 | R$ 720 (p37) | R$ 655 (p26) | 53% / 27% |
| 20/11→22/11 (Consciência Negra) | 53 de 77 | R$ 1.197 | R$ 1.129 | R$ 1.679 | R$ 2.065 (p94) | R$ 1.194 (p57) | 81% / 37% |
| 27/11→29/11 (fim de semana) | 65 de 77 | R$ 889 | R$ 816 | R$ 1.306 | R$ 635 (p28) | R$ 659 (p29) | 31% / 17% |
| 04/12→06/12 (fim de semana) | 66 de 77 | R$ 931 | R$ 855 | R$ 1.261 | R$ 1.229 (p74) | R$ 839 (p47) | 31% / 20% |
| 11/12→13/12 (fim de semana) | 64 de 77 | R$ 928 | R$ 834 | R$ 1.272 | R$ 1.229 (p73) | R$ 1.016 (p62) | 19% / 17% |
| 18/12→20/12 (fim de semana, férias) | 70 de 77 | R$ 903 | R$ 825 | R$ 1.212 | R$ 1.229 (p77) | R$ 957 (p60) | 12% / 3% |
| 24/12→27/12 (Natal, 3 noites) | 67 de 77 | R$ 1.325 | R$ 1.248 | R$ 1.772 | R$ 1.450 (p66) | R$ 1.383 (p58) | 6% / 4% |
| 30/12→03/01 (Réveillon, 4 noites) | 66 de 77 | R$ 1.591 | R$ 1.563 | R$ 2.348 | R$ 1.910 (p71) | R$ 1.880 (p67) | 32% / 28% |
| 08/01→10/01 (fim de semana) | 65 de 77 | R$ 900 | R$ 810 | R$ 1.199 | R$ 1.229 (p75) | R$ 1.176 (p74) | 0% / 0% |
| 15/01→17/01 (fim de semana) | 64 de 77 | R$ 880 | R$ 797 | R$ 1.189 | R$ 1.229 (p77) | R$ 1.176 (p75) | 6% / 0% |
| 22/01→25/01 (Aniversário de SP, 3 noites) | 64 de 77 | R$ 791 | R$ 699 | R$ 1.023 | R$ 1.181 (p80) | R$ 1.095 (p78) | 6% / 0% |
| 29/01→31/01 (fim de semana) | 65 de 77 | R$ 871 | R$ 794 | R$ 1.179 | R$ 1.246 (p78) | R$ 1.176 (p75) | 0% / 10% |

**Ressalvas da tabela**

- **Recanto em 06-08/11:** na Booking aparece a Afrodite, porque o sábado 07/11 esgotou nos outros três quartos.
- **Villa em 06-08/11:** a Booking ainda mostrava o preço de antes da escada de novembro (gravada às 18:03 BRT). Ele muda no envio diário ou num Sync Now.
- **Natal e Réveillon:** a ocupação de mercado medida em 31/10 foi de 26-31% no Natal, contra 6% e 4% nossos (`analise-dezembro-2026.md`).

**Valor médio do mercado na lista, por noite:**

- **fim de semana comum:** R$ 870 a 930, com mediana de R$ 795 a 855. É estável de novembro a janeiro. O quarto com hidro equivalente custa cerca de R$ 1.180 a 1.310;
- **Consciência Negra:** R$ 1.197, 31% acima de um fim de semana comum;
- **Natal (24-27/12):** R$ 1.325, 45% acima;
- **Réveillon (30/12-03/01):** R$ 1.591, 74% acima;
- **Aniversário de SP (22-25/01):** R$ 791, abaixo de um fim de semana comum. O mercado não trata essa data como feriado: o domingo 24/01 entra a preço de domingo.

## 2. Cada quarto contra o mercado

Preço por noite na Booking (estimado pela razão da estadia), percentil e quanto já está vendido.

| Estadia | Moinhos (Queen 7) | Panorâmica I (Double) | Panorâmica II (Queen 2) | Afrodite | King Spa 7 | King Spa 2 | Balcony (lista) |
|---|---|---|---|---|---|---|---|
| 06/11→08/11 | R$ 1.353 p56 · 93% | R$ 1.162 p49 · 83% | R$ 966 p35 · 75% | R$ 1.720 p67 · 0% | R$ 654 p6 · 14% | R$ 1.313 p56 · 100% | R$ 586 p16 · 0% |
| 13/11→15/11 | R$ 720 p10 · 36% | R$ 1.713 p73 · 67% | R$ 1.082 p44 · 50% | R$ 1.810 p74 · 100% | R$ 1.725 p73 · 57% | R$ 754 p11 · 0% | R$ 655 p26 · 0% |
| 20/11→22/11 | R$ 2.065 p70 · 71% | R$ 2.444 p79 · 100% | R$ 3.083 p94 · 100% | R$ 3.246 p94 · 0% | R$ 1.343 p28 · 36% | R$ 1.343 p28 · 50% | R$ 1.194 p57 · 33% |
| 27/11→29/11 | R$ 635 p5 · 14% | R$ 1.739 p72 · 67% | R$ 641 p5 · 0% | R$ 1.832 p77 · 0% | R$ 777 p25 · 36% | R$ 751 p20 · 0% | R$ 659 p29 · 0% |
| 04/12→06/12 | R$ 1.229 p50 · 29% | R$ 1.523 p62 · 50% | R$ 1.414 p53 · 0% | R$ 1.699 p74 · 0% | R$ 1.060 p33 · 29% | R$ 933 p24 · 0% | R$ 839 p47 · 17% |
| 11/12→13/12 | R$ 1.229 p50 · 0% | R$ 1.503 p62 · 33% | R$ 1.395 p55 · 0% | R$ 1.730 p73 · 100% | R$ 1.371 p55 · 29% | R$ 1.164 p44 · 25% | R$ 1.016 p62 · 0% |
| 18/12→20/12 | R$ 1.229 p51 · 0% | R$ 1.352 p54 · 33% | R$ 1.317 p54 · 0% | R$ 1.657 p76 · 0% | R$ 1.243 p51 · 7% | R$ 1.067 p36 · 0% | R$ 957 p60 · 0% |
| 24/12→27/12 | R$ 1.450 p39 · 0% | R$ 1.659 p46 · 17% | R$ 1.601 p43 · 0% | R$ 2.331 p66 · 0% | R$ 1.694 p49 · 0% | R$ 1.604 p43 · 0% | R$ 1.383 p58 · 11% |
| 30/12→03/01 | R$ 1.910 p41 · 46% | R$ 2.379 p50 · 29% | R$ 2.172 p44 · 12% | R$ 2.864 p67 · 0% | R$ 2.025 p42 · 29% | R$ 1.858 p38 · 25% | R$ 1.880 p67 · 29% |
| 08/01→10/01 | R$ 1.229 p52 · 0% | R$ 1.352 p54 · 0% | R$ 1.317 p54 · 0% | R$ 1.827 p75 · 0% | R$ 1.371 p58 · 0% | R$ 1.176 p48 · 0% | R$ 1.176 p74 · 0% |
| 15/01→17/01 | R$ 1.229 p53 · 14% | R$ 1.376 p62 · 0% | R$ 1.317 p58 · 0% | R$ 1.859 p78 · 0% | R$ 1.494 p66 · 0% | R$ 1.282 p58 · 0% | R$ 1.176 p75 · 0% |
| 22/01→25/01 | R$ 1.181 p59 · 14% | R$ 1.307 p62 · 0% | R$ 1.270 p61 · 0% | R$ 1.651 p80 · 0% | R$ 1.441 p69 · 0% | R$ 1.297 p62 · 0% | R$ 1.095 p78 · 0% |
| 29/01→31/01 | R$ 1.246 p58 · 0% | R$ 1.371 p63 · 0% | R$ 1.324 p60 · 0% | R$ 1.853 p77 · 0% | R$ 1.470 p65 · 21% | R$ 1.213 p57 · 0% | R$ 1.176 p75 · 0% |

## 3. Leitura

1. **Fins de semana comuns de dezembro e janeiro: estamos à altura.**
   - O Recanto aparece a R$ 1.229. É uma suíte com banheira, no piso de fim de semana do dono (R$ 850 na vitrine Genius), bem na mediana de quem tem hidro (p50 a p59).
   - Na lista crua, o Recanto fica em p73 a p78, porque boa parte dos concorrentes mostra primeiro um quarto sem banheira.
   - As suítes da Villa ficam entre p33 e p69.
   - **Janeiro na Villa:** os três quartos estão no piso de fim de semana do próprio PriceLabs (150% da base). Por isso a Villa aparece a R$ 1.176 (p74 da lista) com a Balcony, que não tem banheira. A 70-90 dias, todos ainda estão vazios (64-65 dos 77 concorrentes com preço). O motor ajusta perto da data.
2. **Novembro (ordem de base do mercado de 01/11): estamos baratos.**
   - **Moinhos e Panorâmica II:** R$ 635-720 em 13-15/11 e 27-29/11, abaixo da mediana da lista (R$ 816-827) e muito abaixo da mediana com hidro (R$ 1.247-1.306). A Panorâmica II está no piso da faixa entre a Moinhos e a Panorâmica I.
   - **Panorâmica I e King Spa 7:** nos mesmos fins de semana, vendem a p72-p73 com hidro (67% e 57%).
   - **Moinhos a 14% e Panorâmica II a 0% em 27-29/11:** com preço de p5, o que segura a venda não é o preço.
   - **A causa é a régua do motor.** A âncora de 30/10 tem 16-20 hotéis e p50 de fim de semana de R$ 650. A mediana da lista de hoje, com 62-66 hotéis, é R$ 784-855, e a com hidro é R$ 1.212-1.306.
   - A ordem do dono manda ir à "mediana dos hotéis com hidro". Pela medição de hoje, até a lista crua já fica 25-30% acima da régua.
3. **Consciência Negra.**
   - **Recanto:** quase cheio. Panorâmica I e II esgotadas, Moinhos a 71% a R$ 2.065 (p94 da lista).
   - **Afrodite:** p94 com hidro e nada vendido a 18 dias. O motor desce um degrau por dia se ela ficar atrás.
   - **Villa:** está no piso de feriado do dono (R$ 800 reais; R$ 1.343 nas King Spa, p28 com hidro), com 36-50% vendido. Abaixo disso, só com nova ordem do dono. Ela vende menos que o Recanto mesmo mais barata. Isso pesa contra ela:
     - nota 8,2;
     - falta o selo de hidro;
     - a Balcony aparece como vitrine na Booking.
4. **Natal e Réveillon:** o preço está perto da mediana. As suítes estão em p38 a p50 com hidro, e a Afrodite em p66-67. O que trava a venda são as datas:
   - **Natal:** o sábado fechado deixa só 24→27/12 ou 25→27/12. O pacote 24→26/12, que 48 concorrentes vendiam em 31/10, não vende mais aqui. Isso vale também para o 26→28/12 (47 concorrentes).
   - **Réveillon:** o 30/12→02/01, de 55 concorrentes, também fecha.
   - Com 6% e 4% vendidos no Natal, contra 26-31% do mercado, é a oportunidade mais clara do trimestre.
5. **Aniversário de SP (22-25/01):** o mercado está mais fraco que num fim de semana comum, e nós estamos em p59-p81. O domingo 24/01 está com piso de feriado (R$ 800 reais): Moinhos R$ 1.912 e King Spa 7 R$ 2.057 enviados, contra R$ 1.232 e R$ 1.315 num domingo comum.
6. **Noites órfãs do sábado fechado, varredura no Beds24 de 06/11 a 31/01:**
   - **Agora:** sexta 06/11 com Moinhos (1), Panorâmica I (2) e Panorâmica II (1) livres, mas o sábado 07/11 já esgotou nesses quartos. A sexta sozinha precisa de saída no sábado, e a saída está fechada. São 4 diárias que não podem ser vendidas.
   - **Daqui para a frente:** nenhuma outra noite está nessa situação até janeiro. A situação volta sempre que um lado do fim de semana esgotar antes do outro.

## 4. Propostas (cada uma só com o "sim" do dono)

| # | Proposta | Efeito | Quem |
|---|---|---|---|
| 1 | **Sábado esgotado abre a saída só naquele quarto** e **sexta esgotada abre a entrada do sábado** só naquele quarto. Começa já pela sexta 06/11: Moinhos, Panorâmica I e Panorâmica II, com estadia mínima de 1 na Panorâmica II nessa sexta. | Vende as 4 diárias de 06/11 (D4). A regra continua protegendo o sábado sempre que houver o que proteger. | Skill (Beds24), com o "sim" |
| 2 | **Exceção nos dois sábados de festa (26/12 e 02/01):** entrada e saída abertas, como estava antes da ordem de 01/11 às 21:15. | Volta a vender 24→26/12, 26→28/12 e 30/12→02/01, os pacotes que o mercado mais vende. O resto do ano segue fechado. | Skill (Beds24 + Afrodite por data no PriceLabs), com o "sim" |
| 3 | **Novembro: a ordem de base do mercado passa a mirar a mediana da lista medida hoje** (sextas e sábados de 13-14/11 e 27-28/11). Moinhos, Panorâmica II e King Spa 2 ficariam por volta de R$ 1.300 enviados, ou R$ 816-827 na Booking. A escada continua subindo a cada reserva. | +15% a +18% nesses quartos. Ainda abaixo do que se cobra por hidro, mas para de vender abaixo da lista. | Skill, com o "sim" |
| 4 | **Aniversário de SP, gatilho em 01/01:** se 22-24/01 estiver abaixo de 20% vendido, o domingo 24/01 sai do feriado e vira preço de domingo, com estadia mínima de 1. | Vende domingo→segunda ao paulistano com a segunda de folga. | Skill, com o "sim" |
| 5 | **Régua do motor:** esta varredura entra como fonte da revisão em sombra de 09/11, já combinada. Nada muda antes. | — | Skill |

## 5. Arquivos de trabalho

Na pasta temporária da sessão (`fds3m/`):

- `final_fds.json`, a consolidação;
- `respostas_1-4.json`, a Booking por concorrente;
- `nossos_pricelabs.json`, os nossos preços;
- `inv_nov_jan.json`, o inventário do Beds24.

# Análise de mercado dos próximos 60 dias (30/10/2026)

Pedido do dono (30/10): "Faça uma nova análise de mercado para os próximos 60 dias". Fontes: conector oficial da Booking (15 estadias, 2 adultos, 1 quarto, preço público), PriceLabs (`get_neighbourhood_data`: ocupação do mercado por data e mesmo período do ano passado; diária do mercado nos percentis 50/75/90; `get_listing_performance_metrics`; account review), reservas de 01/09 a 30/10 (`get_pms_reservations`) e nossos recálculos de 30/10. Arquivos brutos na pasta de rascunho da sessão (`mercado60_3010.json`, `diagnostico_3010.json`).

## 1. Onde estamos contra o mercado, janela a janela

Preços **por noite**, públicos (sem Genius). "Mediana hidro" = mediana dos concorrentes com jacuzzi/hidro que aparecem na busca; "p75 / p90" = diária anunciada do mercado do PriceLabs (vizinhança, todos os tipos) na semana. Ocupação = nossa, nas noites da estadia, pelo recálculo de 30/10.

| Estadia | Mediana geral | Mediana hidro / máximo hidro | Mercado p75 / p90 | Recanto (mais barato disponível) | Ocup. Recanto | Villa | Ocup. Villa |
|---|---|---|---|---|---|---|---|
| Finados sab-seg 31/10-02/11 | R$ 1.144 | R$ 956 / R$ 1.750 | R$ 1.027 / R$ 1.309 | **indisponível** | 75% | **indisponível** | 90% |
| semana 03-05/11 | R$ 304 | R$ 388 / R$ 846 | R$ 837 / R$ 1.125 | R$ 732 (1,9× med.) | 31% | R$ 420 (1,1× med.) | 33% |
| fds 06-08/11 | R$ 651 | R$ 653 / R$ 1.144 | R$ 837 / R$ 1.125 | R$ 1.344 (2,1× med.) | 59% | **indisponível** | 100% |
| semana 10-12/11 | R$ 303 | R$ 353 / R$ 720 | R$ 851 / R$ 1.134 | R$ 738 (2,1× med.) | 41% | R$ 564 (1,6× med.) | 10% |
| fds 13-15/11 | R$ 654 | R$ 650 / R$ 1.189 | R$ 851 / R$ 1.134 | R$ 1.210 (1,9× med.) | 53% | R$ 1.144 (1,8× med.) | 27% |
| semana 17-19/11 | R$ 284 | R$ 294 / R$ 720 | R$ 922 / R$ 1.152 | R$ 718 (2,4× med.) | 31% | R$ 564 (1,9× med.) | 7% |
| Consciencia Negra 20-22/11 | R$ 852 | R$ 829 / R$ 1.361 | R$ 922 / R$ 1.152 | R$ 1.957 (2,4× med.) | 81% | R$ 1.467 (1,8× med.) | 37% |
| semana 24-26/11 | R$ 344 | R$ 348 / R$ 724 | R$ 855 / R$ 1.188 | R$ 718 (2,1× med.) | 25% | R$ 564 (1,6× med.) | 3% |
| fds 27-29/11 | R$ 597 | R$ 644 / R$ 1.189 | R$ 855 / R$ 1.188 | R$ 1.177 (1,8× med.) | 31% | R$ 1.069 (1,7× med.) | 17% |
| semana 01-03/12 | R$ 278 | R$ 288 / R$ 720 | R$ 915 / R$ 1.261 | R$ 718 (2,5× med.) | 22% | R$ 534 (1,9× med.) | 10% |
| fds 04-06/12 | R$ 487 | R$ 554 / R$ 850 | R$ 915 / R$ 1.261 | R$ 1.177 (2,1× med.) | 31% | R$ 933 (1,7× med.) | 13% |
| fds 11-13/12 | R$ 550 | R$ 640 / R$ 791 | R$ 909 / R$ 1.174 | R$ 1.177 (1,8× med.) | 19% | R$ 1.086 (1,7× med.) | 17% |
| semana 15-17/12 | R$ 352 | R$ 367 / R$ 750 | R$ 952 / R$ 1.178 | R$ 718 (2,0× med.) | 6% | R$ 581 (1,6× med.) | 10% |
| fds 18-20/12 | R$ 566 | R$ 714 / R$ 1.189 | R$ 952 / R$ 1.178 | R$ 1.177 (1,6× med.) | 12% | R$ 1.067 (1,5× med.) | 3% |
| Natal 24-26/12 | R$ 874 | R$ 1.100 / R$ 1.956 | R$ 1.297 / R$ 1.478 | **indisponível** | 6% | R$ 1.502 (1,4× med.) | 7% |

Leitura:
- **O Recanto é o hotel mais caro da busca em 9 das 13 janelas em que aparece** (todos os 16 a 19 concorrentes abaixo), de 1,8 a 2,6 vezes a mediana dos hotéis com hidro e, nos fins de semana de 06-08/11, 04-06/12 e 11-13/12, **17% a 49% acima do máximo** do conjunto. A Villa fica acima de 13 a 18 dos 19 concorrentes, com nota 8,2 contra 9,0 a 9,8 deles.
- **O Recanto está invisível na Booking no Finados com 2 noites (estadia mínima 3) e no Natal (24 a 26/12).** Quem procura 2 noites não nos vê. Conferir na extranet.
- A Villa esgotou o fim de semana de 06-08/11 (VK7 7/7 e Balcony 6/6) a R$ 2.179 a R$ 3.226 enviados: o fim de semana de novembro tem demanda ao nosso preço; o problema está em dezembro e nos dias de semana.
- Pelo PriceLabs, o mercado anuncia alto e vende com desconto: a mediana **reservada** fica abaixo da anunciada (24/12 anunciado R$ 1.120, reservado R$ 705; 20/11 R$ 920 contra R$ 719). Para lotar sem perder valor, a posição certa é entre a mediana reservada e o p75, não no p90.

## 2. Demanda do mercado (PriceLabs, vizinhança) e ano passado

| Semana (seg) | Ocup. mercado | Ano passado | p50 | p75 | p90 | Observação |
|---|---|---|---|---|---|---|
| 2026-10-26 | 53.5% | 21.4% | R$ 800 | R$ 1.027 | R$ 1.309 | 2026-10-31 (66,4%), 2026-11-01 (51,5%) - semana parcial, so 30/10 a 01 |
| 2026-11-02 | 13.8% | 14% | R$ 643 | R$ 837 | R$ 1.125 | nenhum (max 21,0% em 07/11 sab) |
| 2026-11-09 | 10.4% | 14.8% | R$ 658 | R$ 851 | R$ 1.134 | nenhum (max 17,8% em 14/11 sab, Proclamacao) |
| 2026-11-16 | 15.5% | 19.2% | R$ 703 | R$ 922 | R$ 1.152 | nenhum (max 28,9% em 21/11 sab, Consciencia Negra; 20/11 28,7%) |
| 2026-11-23 | 9.4% | 11.3% | R$ 665 | R$ 855 | R$ 1.188 | nenhum (max 13,0% em 28/11 sab) |
| 2026-11-30 | 7.4% | 10.3% | R$ 689 | R$ 915 | R$ 1.261 | nenhum (max 9,7% em 05/12 sab) |
| 2026-12-07 | 5.8% | 11% | R$ 704 | R$ 909 | R$ 1.174 | nenhum (max 7,9% em 12/12 sab) - semana mais fria do periodo |
| 2026-12-14 | 9.3% | 8.1% | R$ 774 | R$ 952 | R$ 1.178 | nenhum (max 13,6% em 20/12 dom; 19/12 12,9%) |
| 2026-12-21 | 20.3% | 14.2% | R$ 1.040 | R$ 1.297 | R$ 1.478 | nenhum acima de 50% (max 29,0% em 24/12; 25/12 25,7%; 23/12 21,8%; 26/ |
| 2026-12-28 | 18.5% | 18.9% | R$ 1.083 | R$ 1.373 | R$ 1.588 | nenhum - semana parcial, so 28/12 |

- Finados está **muito mais forte que o ano passado** (31/10 a 66% contra 30%; 01/11 a 52% contra 9%): são as duas únicas datas quentes dos 60 dias.
- Novembro e a primeira quinzena de dezembro estão **mais fracos que o ano passado** (semanas de 09/11 a 07/12: 6% a 15% contra 10% a 19%). A semana de 07/12 é a mais fria do período (5,8%).
- A semana do Natal (21 a 27/12) está à frente do ano passado (20% contra 14%; 24/12 a 29%). A diária do mercado só muda de patamar a partir de 18/12 e salta de 23/12 em diante (p50 R$ 1.054 a 1.148; p75 R$ 1.400 a 1.456; p90 até R$ 1.592, o teto dos 60 dias).
- Em 2025, o mercado fechou novembro em 34% e dezembro em 42% de ocupação (ADR R$ 685 e R$ 786); hoje marca 14% e 13%, com janela de reserva de 7 a 10 dias e estadia mediana de 2 noites. **A demanda chega em cima da hora**; desconto antecipado em meio de semana frio tem pouco efeito, e o que decide é o preço nos últimos 10 dias.
- Os feriados de novembro ainda não decolaram (Proclamação 14/11 a 18%; Consciência Negra 20-21/11 a 29%), mas em 2025 fecharam em 63% e 81% a 89%: há muito pickup na última semana.

## 3. Como estamos vendendo (01/09 a 30/10, 724 reservas confirmadas)

- **Ocupação acima do mercado, diária abaixo:** set-out 53% contra 34% do mercado (+56%), mas ADR realizado R$ 579 contra R$ 691 (−16%); em outubro, R$ 519 contra R$ 683 (−24%). O RevPAR 31% acima do mercado vem de volume, não de preço.
- **O preço anunciado é 2× o mercado e o realizado é 0,75× o mercado**: entre um e outro ficam Genius e promoções empilhadas (46% a 50% no Recanto, contra 15% a 19% nos concorrentes). É a maior alavanca de ADR, e é do dono (extranet).
- **Última hora:** 39% das reservas entram com 0 a 1 dia (mediana 4 dias); Moinhos mediana 2 dias (49% em 0-1); Balcony mediana 0; direto mediana 1 dia; Booking mediana 10 dias.
- **Noites que sobram:** terça, quarta e quinta (142 a 161 diárias a R$ 473 a 566) contra sábado (241 a R$ 798) e sexta (184 a R$ 755). Na Villa, ter/qua/qui têm 56 a 59 diárias contra 106 no sábado.
- **Canais:** Booking 68% das reservas e 74% da receita (ADR R$ 643); direto 20%/19% (R$ 598, mediana 1 dia); Airbnb 11%/6% (ADR R$ 386; na Villa paga 0,31 do enviado).
- **Cancelamento real 31%** (320 de 1.044), 66% deles no dia ou na véspera: a vaga volta sem tempo de revender a preço cheio. Recanto 34%, Villa 25%.
- **Balcony:** 18% de ocupação em set-out (mercado 32%), 100% dos próximos 60 dias no piso; o PriceLabs não consegue baixar e não recomenda nada para ela.
- **PriceLabs pede preço menor que o nosso piso em 80% a 96% dos dias dos quartos** (Moinhos 96% dos próximos 30 dias no piso) e recomenda fator de última hora de até 15% a 16% nos últimos 8 a 9 dias (hoje 0%).

## 4. O que isso pede (resumo; a mudança de estratégia está em `.claude/skills/gestao-receita-pousadas/references/estrategia.md`)

1. Dezembro (alta temporada do dono, metas R$ 350 mil / R$ 300 mil) está a 7% a 12% com preços 1,8× a 2,6× a mediana e acima do máximo do mercado: precisa voltar à faixa p60-p75 do mercado agora, e subir pela escada só quando o ritmo de venda estiver à frente do esperado.
2. Dias de semana (ter a qui) a 45%/40%: preço de última hora (D0-D3) ao redor da mediana reservada do mercado, dentro dos pisos do dono.
3. Finados e Natal: tirar o Recanto da invisibilidade (estadia mínima e disponibilidade na extranet).
4. Genius e promoções: reduzir o empilhamento para o preço público cair até o mercado sem o hóspede pagar menos (decisão do dono).

# Análise de preços e plano de novembro de 2026 a fevereiro de 2027

Pedido do dono em 09/11/2026 (noite): análise completa dos fins de semana e dos dias de semana contra os hotéis do mesmo parâmetro, plano de novembro a fevereiro e expectativa de valor e lotação. **Nada foi gravado no PriceLabs**: o plano depende das decisões do dono listadas no fim.

Página com todas as tabelas: https://claude.ai/artifact/4fe5rrZh7CqWP4v74wXuTG

## Como foi medido

- Nossos dados: preços do PriceLabs (`get_listing_prices`, 7 quartos) e inventário e reservas do Beds24, lidos em 09/11 à noite; 777 noites-suíte de 10/11 a 28/02; reservas com chegada desde 01/09.
- Concorrentes: conector oficial da Booking, 09-10/11, 77 hotéis com hidro no quarto, café e até 3 km do Capivari (`pricelabs/pesquisa-booking/concorrentes.json`), 32 estadias; respostas em `pricelabs/pesquisa-booking/faixas-2026-11/novfev_respostas_*.json`.
- "Mais barato" = quarto de entrada de cada hotel; "com hidro" = × r_hidro do hotel (33 medidos; padrão 1.395). Notas na Booking: 55 hotéis com 9,0 ou mais, 17 abaixo de 9 (pares), 5 sem nota. Villa 8,3; Recanto sem nota no anúncio.
- Conversões: de costume, público = enviado × 0.63 (Recanto) / 0.65 (Villa). Nesta medição a Booking mostrou o Recanto em 0,62 a 0,63 do enviado e a Villa de 0,36 a 0,66 conforme o mês e o tipo de noite; o plano usa o medido. O hóspede paga em média enviado × 0.49 / 0.46 (reservas de 02 a 09/11), limitado ao preço público.
- Villa na Booking abaixo da tabela: dias de semana de novembro R$ 392 a R$ 448 a noite; Natal (23 a 25/12) R$ 1.584 com menor enviado de R$ 4.483; Réveillon (30/12 a 03/01) R$ 1.850 com menor enviado de R$ 4.483. No Beds24 o preço é igual ao do PriceLabs: a diferença nasce na Booking (promoção, plano tarifário ou desconto da extranet). Pendente de conferência pelo dono.
- Não feito nesta rodada (limite de uso da conta): conferência do quarto com hidro hotel a hotel e revisão independente dos números; o Recanto foi medido na Booking em 7 das 32 estadias.
- Mercado do PriceLabs: imóveis do Airbnb próximos (ritmo de ocupação, não nível de preço).

## 1. Fins de semana realizados (01/09 a 09/11)

| Sexta | Recanto | pago (mediana) | Villa | pago (mediana) | Moinhos | Panorâmica II | Panorâmica I | Afrodite | King Spa 7 | King Spa 2 | Balcony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 04/09 | 81% | R$ 755 | 77% | R$ 914 | 9/14 | 3/4 | 14/12 | 0/2 | 12/14 | 3/4 | 8/12 |
| 11/09 | 50% | R$ 818 | 67% | R$ 733 | 2/14 | 3/4 | 9/12 | 2/2 | 15/14 | 3/4 | 2/12 |
| 18/09 | 78% | R$ 731 | 57% | R$ 754 | 8/14 | 4/4 | 11/12 | 2/2 | 11/14 | 5/4 | 1/12 |
| 25/09 | 47% | R$ 826 | 57% | R$ 613 | 3/14 | 1/4 | 9/12 | 2/2 | 10/14 | 3/4 | 4/12 |
| 02/10 | 59% | R$ 612 | 53% | R$ 511 | 11/14 | 2/4 | 6/12 | 0/2 | 10/14 | 4/4 | 2/12 |
| 09/10 (feriado) | 41% | R$ 1.010 | 77% | R$ 687 | 4/14 | 1/4 | 8/12 | 0/2 | 13/14 | 3/4 | 7/12 |
| 16/10 | 100% | R$ 634 | 87% | R$ 575 | 15/14 | 4/4 | 11/12 | 2/2 | 14/14 | 4/4 | 8/12 |
| 23/10 | 44% | R$ 775 | 77% | R$ 645 | 3/14 | 3/4 | 8/12 | 0/2 | 14/14 | 3/4 | 6/12 |
| 30/10 (feriado) | 50% | R$ 1.073 | 80% | R$ 692 | 6/14 | 1/4 | 7/12 | 2/2 | 13/14 | 2/4 | 9/12 |
| 06/11 | 62% | R$ 589 | 67% | R$ 557 | 8/14 | 2/4 | 8/12 | 2/2 | 12/14 | 4/4 | 4/12 |

## 2. O que o hóspede paga, por suíte (estadias de 01/09 a 09/11) e o pedido de hoje

| Suíte | Unid. | Fds: ocupação | pago 25% | pago mediana | pago 75% | pedido hoje (pago equivalente, nov) | Semana: ocupação | pago mediana | pedido hoje |
|---|---|---|---|---|---|---|---|---|---|
| Moinhos | 7 | 53% | R$ 504 | R$ 559 | R$ 657 | R$ 1.098 | 43% | R$ 430 | R$ 606 |
| Panorâmica II | 2 | 69% | R$ 663 | R$ 712 | R$ 820 | R$ 1.125 | 41% | R$ 416 | R$ 611 |
| Panorâmica I | 6 | 79% | R$ 645 | R$ 743 | R$ 862 | R$ 1.166 | 64% | R$ 471 | R$ 634 |
| Afrodite | 1 | 62% | R$ 656 | R$ 843 | R$ 1.100 | R$ 1.558 | 71% | R$ 402 | R$ 804 |
| King Spa 7 | 7 | 88% | R$ 591 | R$ 700 | R$ 824 | R$ 1.307 | 66% | R$ 449 | R$ 495 |
| King Spa 2 | 2 | 91% | R$ 533 | R$ 580 | R$ 994 | R$ 1.250 | 62% | R$ 449 | R$ 486 |
| Balcony | 6 | 36% | R$ 394 | R$ 441 | R$ 653 | R$ 1.116 | 12% | R$ 332 | R$ 426 |

## 3. Desde 01/11 (antes das subidas de 02 a 04/11) até hoje

| Sexta | Recanto: ocupação | Villa: ocupação | Recanto na Booking em 01/11 | Villa na Booking em 01/11 | Moinhos | Panorâmica II | Panorâmica I | Afrodite | King Spa 7 | King Spa 2 | Balcony |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 13/11 | 53% → 44% | 27% → 40% | R$ 720 | R$ 655 | 5→5 | 2→2 | 8→5 | 2→2 | 8→8 | 0→4 | 0→0 |
| 20/11 | 81% → 88% | 37% → 53% | R$ 2.065 | R$ 1.194 | 10→10 | 4→4 | 12→12 | 0→2 | 5→9 | 2→3 | 4→4 |
| 27/11 | 31% → 31% | 17% → 37% | R$ 635 | R$ 659 | 2→2 | 0→0 | 8→8 | 0→0 | 5→11 | 0→0 | 0→0 |
| 04/12 | 31% → 28% | 20% → 33% | R$ 1.229 | R$ 839 | 4→4 | 0→0 | 6→5 | 0→0 | 4→6 | 0→2 | 2→2 |
| 11/12 | 19% → 19% | 17% → 30% | R$ 1.229 | R$ 1.016 | 0→0 | 0→0 | 4→4 | 2→2 | 4→7 | 1→2 | 0→0 |
| 18/12 | 12% → 12% | 3% → 3% | R$ 1.229 | R$ 957 | 0→0 | 0→0 | 4→4 | 0→0 | 1→1 | 0→0 | 0→0 |

## 4. Régua dos concorrentes (preço público por noite)

| Mês | Tipo | Estadias | Com vaga | Mais barato 25% | mediana | 75% | Com hidro 40% | mediana | 65% | Pares: mais barato | Pares: com hidro | Recanto na Booking | Villa na Booking |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| nov (10-30) | fim de semana | 2 | 64/77 | R$ 618 | R$ 784 | R$ 1.193 | R$ 996 | R$ 1.190 | R$ 1.440 | R$ 620 | R$ 862 | R$ 1.361 | R$ 1.398 |
| nov (10-30) | feriado | 1 | 53/77 | R$ 910 | R$ 1.143 | R$ 1.550 | R$ 1.570 | R$ 1.650 | R$ 2.101 | R$ 949 | R$ 1.324 | R$ 1.822 | R$ 1.268 |
| nov (10-30) | dia de semana | 3 | 69/77 | R$ 280 | R$ 388 | R$ 716 | R$ 501 | R$ 572 | R$ 796 | R$ 269 | R$ 411 | R$ 794 | R$ 418 |
| dez | entre festas | 1 | 56/77 | R$ 553 | R$ 730 | R$ 1.136 | R$ 910 | R$ 1.055 | R$ 1.362 | R$ 590 | R$ 823 | — | R$ 1.157 |
| dez | fim de semana | 3 | 68/77 | R$ 643 | R$ 838 | R$ 1.180 | R$ 1.120 | R$ 1.181 | R$ 1.456 | R$ 615 | R$ 931 | R$ 1.495 | R$ 1.296 |
| dez | Natal | 2 | 48/77 | R$ 845 | R$ 1.122 | R$ 1.610 | R$ 1.400 | R$ 1.549 | R$ 2.046 | R$ 905 | R$ 1.386 | — | R$ 1.594 |
| dez | Réveillon | 1 | 67/77 | R$ 1.052 | R$ 1.556 | R$ 2.168 | R$ 1.833 | R$ 2.190 | R$ 2.829 | R$ 918 | R$ 1.507 | R$ 3.062 | R$ 1.850 |
| dez | dia de semana | 4 | 68/77 | R$ 332 | R$ 486 | R$ 778 | R$ 576 | R$ 685 | R$ 879 | R$ 344 | R$ 494 | — | R$ 629 |
| jan | fim de semana | 4 | 66/77 | R$ 610 | R$ 792 | R$ 1.159 | R$ 989 | R$ 1.166 | R$ 1.430 | R$ 624 | R$ 998 | R$ 1.567 | R$ 1.176 |
| jan | dia de semana | 4 | 64/77 | R$ 341 | R$ 468 | R$ 879 | R$ 560 | R$ 660 | R$ 986 | R$ 332 | R$ 530 | — | R$ 680 |
| fev | fim de semana | 3 | 60/77 | R$ 512 | R$ 722 | R$ 1.101 | R$ 955 | R$ 1.082 | R$ 1.337 | R$ 596 | R$ 922 | — | R$ 1.176 |
| fev | feriado | 1 | 59/77 | R$ 853 | R$ 1.044 | R$ 1.358 | R$ 1.433 | R$ 1.488 | R$ 1.748 | R$ 845 | R$ 1.318 | — | R$ 1.252 |
| fev | dia de semana | 3 | 61/77 | R$ 294 | R$ 387 | R$ 980 | R$ 504 | R$ 663 | R$ 881 | R$ 331 | R$ 494 | — | R$ 654 |

## 5. Vendido contra a meta mínima do dono

| Mês | Pousada | Meta | Já vendido | % da meta | Ocupação já vendida | Diárias livres |
|---|---|---|---|---|---|---|
| nov | Recanto | R$ 220.000 | R$ 133.481 | 61% | 44% | 206 |
| nov | Villa | R$ 150.000 | R$ 96.620 | 64% | 43% | 210 |
| dez | Recanto | R$ 350.000 | R$ 54.352 | 16% | 14% | 429 |
| dez | Villa | R$ 300.000 | R$ 65.623 | 22% | 19% | 376 |
| jan | Recanto | R$ 350.000 | R$ 30.799 | 9% | 7% | 459 |
| jan | Villa | R$ 300.000 | R$ 17.056 | 6% | 4% | 445 |
| fev | Recanto | R$ 220.000 | R$ 7.590 | 3% | 2% | 439 |
| fev | Villa | R$ 150.000 | R$ 4.813 | 3% | 2% | 413 |

## 6. Proposta por mês, tipo de noite e suíte

Fim de semana, feriado e festas: abertura na âncora de mercado; escada de +7% por unidade depois da metade vendida, até o teto; a 7 dias com menos de 60% vendido, −10%. Dia de semana das suítes com banheira: abertura no piso atual do dono e, na última semana, o preço mediano do mercado com hidro. Balcony com preço próprio.

| Mês | Tipo | Suíte | Já vendido | Hoje: enviado | Hoje: público | Proposto: enviado | público | Genius 3 | hóspede paga ~ | Mudança | Última semana (enviado) | Teto (enviado) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| nov (10-30) | dia de semana | Moinhos | 21% (21/98) | R$ 1.236 | R$ 782 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -1% | R$ 904 | R$ 1.258 |
| nov (10-30) | dia de semana | Panorâmica I | 37% (31/84) | R$ 1.294 | R$ 819 | R$ 1.281 | R$ 810 | R$ 527 | R$ 628 | -1% | R$ 949 | R$ 1.281 |
| nov (10-30) | dia de semana | Panorâmica II | 46% (13/28) | R$ 1.246 | R$ 788 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -2% | R$ 904 | R$ 1.258 |
| nov (10-30) | dia de semana | Afrodite | 43% (6/14) | R$ 1.640 | R$ 1.038 | R$ 1.464 | R$ 926 | R$ 603 | R$ 717 | -11% | R$ 1.085 | R$ 1.464 |
| nov (10-30) | dia de semana | King Spa 7 | 44% (43/98) | R$ 1.076 | R$ 511 | R$ 944 | R$ 448 | R$ 365 | R$ 434 | -12% | R$ 944 | R$ 1.677 |
| nov (10-30) | dia de semana | King Spa 2 | 75% (21/28) | R$ 1.056 | R$ 501 | R$ 944 | R$ 448 | R$ 365 | R$ 434 | -11% | R$ 944 | R$ 1.677 |
| nov (10-30) | dia de semana | Balcony | 1% (1/84) | R$ 926 | R$ 439 | R$ 690 | R$ 327 | R$ 267 | R$ 317 | -26% | R$ 690 | R$ 1.127 |
| nov (10-30) | fim de semana | Moinhos | 25% (7/28) | R$ 2.240 | R$ 1.379 | R$ 1.153 | R$ 710 | R$ 479 | R$ 565 | -49% | R$ 1.038 | R$ 1.933 |
| nov (10-30) | fim de semana | Panorâmica I | 54% (13/24) | R$ 2.379 | R$ 1.465 | R$ 1.470 | R$ 905 | R$ 611 | R$ 720 | -38% | R$ 1.323 | R$ 2.339 |
| nov (10-30) | fim de semana | Panorâmica II | 25% (2/8) | R$ 2.295 | R$ 1.413 | R$ 1.470 | R$ 905 | R$ 611 | R$ 720 | -36% | R$ 1.323 | R$ 2.339 |
| nov (10-30) | fim de semana | Afrodite | 50% (2/4) | R$ 3.180 | R$ 1.958 | R$ 1.691 | R$ 1.041 | R$ 702 | R$ 828 | -47% | R$ 1.522 | R$ 2.339 |
| nov (10-30) | fim de semana | King Spa 7 | 68% (19/28) | R$ 2.841 | R$ 1.638 | R$ 1.570 | R$ 905 | R$ 801 | R$ 722 | -45% | R$ 1.413 | R$ 2.498 |
| nov (10-30) | fim de semana | King Spa 2 | 50% (4/8) | R$ 2.718 | R$ 1.567 | R$ 1.570 | R$ 905 | R$ 801 | R$ 722 | -42% | R$ 1.413 | R$ 2.498 |
| nov (10-30) | fim de semana | Balcony | 0% (0/24) | R$ 2.426 | R$ 1.398 | R$ 1.076 | R$ 620 | R$ 548 | R$ 495 | -56% | R$ 968 | R$ 1.360 |
| nov (10-30) | feriado | Moinhos | 52% (11/21) | R$ 2.299 | R$ 1.454 | R$ 2.298 | R$ 1.454 | R$ 969 | R$ 1.126 | +0% | R$ 2.069 | R$ 3.321 |
| nov (10-30) | feriado | Panorâmica I | 94% (17/18) | R$ 2.102 | R$ 1.330 | R$ 2.102 | R$ 1.330 | R$ 887 | R$ 1.030 | +0% | R$ 1.892 | R$ 3.874 |
| nov (10-30) | feriado | Panorâmica II | 67% (4/6) | R$ 1.924 | R$ 1.217 | R$ 1.924 | R$ 1.217 | R$ 811 | R$ 943 | +0% | R$ 1.731 | R$ 3.874 |
| nov (10-30) | feriado | Afrodite | 100% (3/3) | — | — | R$ 3.000 | R$ 1.897 | R$ 1.265 | R$ 1.470 | — | R$ 2.700 | R$ 3.874 |
| nov (10-30) | feriado | King Spa 7 | 43% (9/21) | R$ 2.709 | R$ 1.768 | R$ 2.528 | R$ 1.650 | R$ 1.422 | R$ 1.163 | -7% | R$ 2.276 | R$ 3.756 |
| nov (10-30) | feriado | King Spa 2 | 67% (4/6) | R$ 2.908 | R$ 1.898 | R$ 2.528 | R$ 1.650 | R$ 1.422 | R$ 1.163 | -13% | R$ 2.276 | R$ 3.756 |
| nov (10-30) | feriado | Balcony | 22% (4/18) | R$ 1.758 | R$ 1.147 | R$ 1.751 | R$ 1.143 | R$ 985 | R$ 806 | -0% | R$ 1.576 | R$ 2.375 |
| dez | dia de semana | Moinhos | 10% (11/112) | R$ 1.292 | R$ 817 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -6% | R$ 1.083 | R$ 1.389 |
| dez | dia de semana | Panorâmica I | 15% (14/96) | R$ 1.311 | R$ 829 | R$ 1.281 | R$ 810 | R$ 527 | R$ 628 | -2% | R$ 1.137 | R$ 1.389 |
| dez | dia de semana | Panorâmica II | 6% (2/32) | R$ 1.322 | R$ 836 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -8% | R$ 1.083 | R$ 1.389 |
| dez | dia de semana | Afrodite | 25% (4/16) | R$ 1.625 | R$ 1.028 | R$ 1.464 | R$ 926 | R$ 603 | R$ 717 | -10% | R$ 1.299 | R$ 1.464 |
| dez | dia de semana | King Spa 7 | 30% (34/112) | R$ 1.102 | R$ 666 | R$ 944 | R$ 570 | R$ 465 | R$ 434 | -14% | R$ 944 | R$ 1.455 |
| dez | dia de semana | King Spa 2 | 31% (10/32) | R$ 1.040 | R$ 628 | R$ 944 | R$ 570 | R$ 465 | R$ 434 | -9% | R$ 944 | R$ 1.455 |
| dez | dia de semana | Balcony | 0% (0/96) | R$ 964 | R$ 583 | R$ 690 | R$ 417 | R$ 340 | R$ 317 | -28% | R$ 690 | R$ 958 |
| dez | fim de semana | Moinhos | 10% (4/42) | R$ 2.418 | R$ 1.489 | R$ 1.265 | R$ 779 | R$ 526 | R$ 620 | -48% | R$ 1.139 | R$ 1.918 |
| dez | fim de semana | Panorâmica I | 36% (13/36) | R$ 2.441 | R$ 1.503 | R$ 1.588 | R$ 978 | R$ 659 | R$ 778 | -35% | R$ 1.429 | R$ 2.365 |
| dez | fim de semana | Panorâmica II | 0% (0/12) | R$ 2.433 | R$ 1.498 | R$ 1.588 | R$ 978 | R$ 659 | R$ 778 | -35% | R$ 1.429 | R$ 2.365 |
| dez | fim de semana | Afrodite | 33% (2/6) | R$ 2.615 | R$ 1.610 | R$ 1.826 | R$ 1.124 | R$ 758 | R$ 895 | -30% | R$ 1.643 | R$ 2.365 |
| dez | fim de semana | King Spa 7 | 33% (14/42) | R$ 2.349 | R$ 1.410 | R$ 1.629 | R$ 978 | R$ 865 | R$ 749 | -31% | R$ 1.466 | R$ 2.426 |
| dez | fim de semana | King Spa 2 | 33% (4/12) | R$ 2.351 | R$ 1.411 | R$ 1.629 | R$ 978 | R$ 865 | R$ 749 | -31% | R$ 1.466 | R$ 2.426 |
| dez | fim de semana | Balcony | 6% (2/36) | R$ 2.168 | R$ 1.301 | R$ 1.025 | R$ 615 | R$ 544 | R$ 471 | -53% | R$ 922 | R$ 1.396 |
| dez | Natal | Moinhos | 0% (0/28) | R$ 4.974 | R$ 3.062 | R$ 2.274 | R$ 1.400 | R$ 928 | R$ 1.114 | -54% | R$ 2.047 | R$ 3.886 |
| dez | Natal | Panorâmica I | 17% (4/24) | R$ 4.988 | R$ 3.071 | R$ 2.516 | R$ 1.549 | R$ 1.027 | R$ 1.233 | -50% | R$ 2.265 | R$ 3.886 |
| dez | Natal | Panorâmica II | 0% (0/8) | R$ 4.977 | R$ 3.064 | R$ 2.516 | R$ 1.549 | R$ 1.027 | R$ 1.233 | -49% | R$ 2.265 | R$ 3.886 |
| dez | Natal | Afrodite | 0% (0/4) | R$ 5.935 | R$ 3.654 | R$ 2.894 | R$ 1.781 | R$ 1.180 | R$ 1.418 | -51% | R$ 2.604 | R$ 3.886 |
| dez | Natal | King Spa 7 | 14% (4/28) | R$ 4.988 | R$ 1.774 | R$ 4.356 | R$ 1.549 | R$ 1.317 | R$ 1.549 | -13% | R$ 3.920 | R$ 6.727 |
| dez | Natal | King Spa 2 | 12% (1/8) | R$ 4.991 | R$ 1.775 | R$ 4.356 | R$ 1.549 | R$ 1.317 | R$ 1.549 | -13% | R$ 3.920 | R$ 6.727 |
| dez | Natal | Balcony | 17% (4/24) | R$ 4.483 | R$ 1.594 | R$ 3.155 | R$ 1.122 | R$ 954 | R$ 1.122 | -30% | R$ 2.840 | R$ 4.528 |
| dez | entre festas | Moinhos | 0% (0/21) | R$ 2.194 | R$ 1.351 | R$ 1.478 | R$ 910 | R$ 592 | R$ 724 | -33% | R$ 1.330 | R$ 2.710 |
| dez | entre festas | Panorâmica I | 0% (0/18) | R$ 2.664 | R$ 1.640 | R$ 1.714 | R$ 1.055 | R$ 687 | R$ 840 | -36% | R$ 1.542 | R$ 2.710 |
| dez | entre festas | Panorâmica II | 0% (0/6) | R$ 2.587 | R$ 1.593 | R$ 1.714 | R$ 1.055 | R$ 687 | R$ 840 | -34% | R$ 1.542 | R$ 2.710 |
| dez | entre festas | Afrodite | 0% (0/3) | R$ 3.920 | R$ 2.413 | R$ 1.971 | R$ 1.213 | R$ 790 | R$ 966 | -50% | R$ 1.774 | R$ 2.710 |
| dez | entre festas | King Spa 7 | 14% (3/21) | R$ 2.390 | R$ 1.476 | R$ 1.709 | R$ 1.055 | R$ 860 | R$ 786 | -29% | R$ 1.538 | R$ 2.702 |
| dez | entre festas | King Spa 2 | 0% (0/6) | R$ 2.303 | R$ 1.422 | R$ 1.709 | R$ 1.055 | R$ 860 | R$ 786 | -26% | R$ 1.538 | R$ 2.702 |
| dez | entre festas | Balcony | 0% (0/18) | R$ 2.015 | R$ 1.244 | R$ 1.182 | R$ 730 | R$ 595 | R$ 544 | -41% | R$ 1.064 | R$ 1.840 |
| dez | Réveillon | Moinhos | 64% (9/14) | R$ 4.974 | R$ 3.062 | R$ 2.978 | R$ 1.833 | R$ 1.193 | R$ 1.459 | -40% | R$ 2.680 | R$ 5.093 |
| dez | Réveillon | Panorâmica I | 25% (3/12) | R$ 4.988 | R$ 3.071 | R$ 3.558 | R$ 2.190 | R$ 1.425 | R$ 1.743 | -29% | R$ 3.202 | R$ 5.093 |
| dez | Réveillon | Panorâmica II | 25% (1/4) | R$ 4.977 | R$ 3.064 | R$ 3.558 | R$ 2.190 | R$ 1.425 | R$ 1.743 | -29% | R$ 3.202 | R$ 5.093 |
| dez | Réveillon | Afrodite | 0% (0/2) | R$ 6.700 | R$ 4.125 | R$ 4.091 | R$ 2.518 | R$ 1.639 | R$ 2.005 | -39% | R$ 3.682 | R$ 5.093 |
| dez | Réveillon | King Spa 7 | 36% (5/14) | R$ 4.988 | R$ 2.059 | R$ 4.989 | R$ 2.059 | R$ 1.679 | R$ 2.059 | +0% | R$ 4.490 | R$ 7.596 |
| dez | Réveillon | King Spa 2 | 50% (2/4) | R$ 4.991 | R$ 2.060 | R$ 4.992 | R$ 2.060 | R$ 1.680 | R$ 2.060 | +0% | R$ 4.492 | R$ 7.596 |
| dez | Réveillon | Balcony | 50% (6/12) | R$ 4.483 | R$ 1.850 | R$ 3.770 | R$ 1.556 | R$ 1.269 | R$ 1.556 | -16% | R$ 3.393 | R$ 5.253 |
| jan | dia de semana | Moinhos | 4% (6/147) | R$ 1.273 | R$ 805 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -4% | R$ 1.043 | R$ 1.558 |
| jan | dia de semana | Panorâmica I | 6% (8/126) | R$ 1.324 | R$ 838 | R$ 1.281 | R$ 810 | R$ 527 | R$ 628 | -3% | R$ 1.095 | R$ 1.558 |
| jan | dia de semana | Panorâmica II | 5% (2/42) | R$ 1.300 | R$ 823 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -6% | R$ 1.043 | R$ 1.558 |
| jan | dia de semana | Afrodite | 43% (9/21) | R$ 1.726 | R$ 1.092 | R$ 1.464 | R$ 926 | R$ 603 | R$ 717 | -15% | R$ 1.252 | R$ 1.558 |
| jan | dia de semana | King Spa 7 | 5% (8/147) | R$ 1.288 | R$ 804 | R$ 944 | R$ 589 | R$ 481 | R$ 434 | -27% | R$ 944 | R$ 1.579 |
| jan | dia de semana | King Spa 2 | 7% (3/42) | R$ 1.140 | R$ 712 | R$ 944 | R$ 589 | R$ 481 | R$ 434 | -17% | R$ 944 | R$ 1.579 |
| jan | dia de semana | Balcony | 0% (0/126) | R$ 1.224 | R$ 764 | R$ 690 | R$ 431 | R$ 351 | R$ 317 | -44% | R$ 690 | R$ 1.011 |
| jan | fim de semana | Moinhos | 7% (4/56) | R$ 2.354 | R$ 1.449 | R$ 1.156 | R$ 712 | R$ 480 | R$ 567 | -51% | R$ 1.041 | R$ 1.894 |
| jan | fim de semana | Panorâmica I | 0% (0/48) | R$ 2.448 | R$ 1.507 | R$ 1.702 | R$ 1.048 | R$ 707 | R$ 834 | -30% | R$ 1.532 | R$ 2.323 |
| jan | fim de semana | Panorâmica II | 0% (0/16) | R$ 2.405 | R$ 1.481 | R$ 1.702 | R$ 1.048 | R$ 707 | R$ 834 | -29% | R$ 1.532 | R$ 2.323 |
| jan | fim de semana | Afrodite | 0% (0/8) | R$ 2.918 | R$ 1.797 | R$ 1.957 | R$ 1.205 | R$ 813 | R$ 959 | -33% | R$ 1.762 | R$ 2.323 |
| jan | fim de semana | King Spa 7 | 9% (5/56) | R$ 2.291 | R$ 1.414 | R$ 1.698 | R$ 1.048 | R$ 927 | R$ 781 | -26% | R$ 1.528 | R$ 2.317 |
| jan | fim de semana | King Spa 2 | 0% (0/16) | R$ 1.954 | R$ 1.206 | R$ 1.698 | R$ 1.048 | R$ 927 | R$ 781 | -13% | R$ 1.528 | R$ 2.317 |
| jan | fim de semana | Balcony | 0% (0/48) | R$ 1.880 | R$ 1.161 | R$ 1.011 | R$ 624 | R$ 552 | R$ 465 | -46% | R$ 910 | R$ 1.283 |
| jan | Réveillon | Moinhos | 29% (4/14) | R$ 4.974 | R$ 3.062 | R$ 2.978 | R$ 1.833 | R$ 1.237 | R$ 1.459 | -40% | R$ 2.680 | R$ 5.093 |
| jan | Réveillon | Panorâmica I | 33% (4/12) | R$ 4.988 | R$ 3.071 | R$ 3.558 | R$ 2.190 | R$ 1.477 | R$ 1.743 | -29% | R$ 3.202 | R$ 5.093 |
| jan | Réveillon | Panorâmica II | 0% (0/4) | R$ 4.977 | R$ 3.064 | R$ 3.558 | R$ 2.190 | R$ 1.477 | R$ 1.743 | -29% | R$ 3.202 | R$ 5.093 |
| jan | Réveillon | Afrodite | 0% (0/2) | R$ 6.560 | R$ 4.038 | R$ 4.091 | R$ 2.518 | R$ 1.699 | R$ 2.005 | -38% | R$ 3.682 | R$ 5.093 |
| jan | Réveillon | King Spa 7 | 21% (3/14) | R$ 4.988 | R$ 2.059 | R$ 4.989 | R$ 2.059 | R$ 1.821 | R$ 2.059 | +0% | R$ 4.490 | R$ 7.596 |
| jan | Réveillon | King Spa 2 | 0% (0/4) | R$ 4.991 | R$ 2.060 | R$ 4.992 | R$ 2.060 | R$ 1.822 | R$ 2.060 | +0% | R$ 4.492 | R$ 7.596 |
| jan | Réveillon | Balcony | 8% (1/12) | R$ 4.483 | R$ 1.850 | R$ 3.770 | R$ 1.556 | R$ 1.376 | R$ 1.556 | -16% | R$ 3.393 | R$ 5.253 |
| fev | dia de semana | Moinhos | 1% (1/119) | R$ 1.279 | R$ 809 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -5% | R$ 1.048 | R$ 1.392 |
| fev | dia de semana | Panorâmica I | 2% (2/102) | R$ 1.350 | R$ 854 | R$ 1.281 | R$ 810 | R$ 527 | R$ 628 | -5% | R$ 1.100 | R$ 1.392 |
| fev | dia de semana | Panorâmica II | 0% (0/34) | R$ 1.322 | R$ 836 | R$ 1.220 | R$ 772 | R$ 502 | R$ 598 | -8% | R$ 1.048 | R$ 1.392 |
| fev | dia de semana | Afrodite | 0% (0/17) | R$ 1.818 | R$ 1.150 | R$ 1.464 | R$ 926 | R$ 603 | R$ 717 | -19% | R$ 1.257 | R$ 1.464 |
| fev | dia de semana | King Spa 7 | 0% (0/119) | R$ 1.309 | R$ 831 | R$ 944 | R$ 599 | R$ 489 | R$ 434 | -28% | R$ 944 | R$ 1.388 |
| fev | dia de semana | King Spa 2 | 3% (1/34) | R$ 1.125 | R$ 714 | R$ 944 | R$ 599 | R$ 489 | R$ 434 | -16% | R$ 944 | R$ 1.388 |
| fev | dia de semana | Balcony | 0% (0/102) | R$ 1.352 | R$ 858 | R$ 690 | R$ 438 | R$ 357 | R$ 317 | -49% | R$ 690 | R$ 920 |
| fev | fim de semana | Moinhos | 0% (0/42) | R$ 2.018 | R$ 1.242 | R$ 984 | R$ 606 | R$ 409 | R$ 482 | -51% | R$ 886 | R$ 1.757 |
| fev | fim de semana | Panorâmica I | 3% (1/36) | R$ 2.220 | R$ 1.367 | R$ 1.572 | R$ 968 | R$ 653 | R$ 770 | -29% | R$ 1.415 | R$ 2.172 |
| fev | fim de semana | Panorâmica II | 0% (0/12) | R$ 2.134 | R$ 1.314 | R$ 1.572 | R$ 968 | R$ 653 | R$ 770 | -26% | R$ 1.415 | R$ 2.172 |
| fev | fim de semana | Afrodite | 0% (0/6) | R$ 3.000 | R$ 1.847 | R$ 1.808 | R$ 1.113 | R$ 751 | R$ 886 | -40% | R$ 1.627 | R$ 2.172 |
| fev | fim de semana | King Spa 7 | 0% (0/42) | R$ 2.220 | R$ 1.370 | R$ 1.568 | R$ 968 | R$ 856 | R$ 721 | -29% | R$ 1.411 | R$ 2.166 |
| fev | fim de semana | King Spa 2 | 0% (0/12) | R$ 1.905 | R$ 1.176 | R$ 1.568 | R$ 968 | R$ 856 | R$ 721 | -18% | R$ 1.411 | R$ 2.166 |
| fev | fim de semana | Balcony | 0% (0/36) | R$ 1.905 | R$ 1.176 | R$ 965 | R$ 596 | R$ 527 | R$ 444 | -49% | R$ 869 | R$ 1.170 |
| fev | feriado | Moinhos | 6% (2/35) | R$ 2.381 | R$ 1.506 | R$ 2.265 | R$ 1.433 | R$ 946 | R$ 1.110 | -5% | R$ 2.039 | R$ 2.763 |
| fev | feriado | Panorâmica I | 10% (3/30) | R$ 2.500 | R$ 1.582 | R$ 2.352 | R$ 1.488 | R$ 983 | R$ 1.153 | -6% | R$ 2.117 | R$ 3.073 |
| fev | feriado | Panorâmica II | 0% (0/10) | R$ 2.404 | R$ 1.521 | R$ 2.352 | R$ 1.488 | R$ 983 | R$ 1.153 | -2% | R$ 2.117 | R$ 3.073 |
| fev | feriado | Afrodite | 0% (0/5) | R$ 3.144 | R$ 1.989 | R$ 2.705 | R$ 1.711 | R$ 1.130 | R$ 1.325 | -14% | R$ 2.435 | R$ 3.073 |
| fev | feriado | King Spa 7 | 0% (0/35) | R$ 2.741 | R$ 1.808 | R$ 2.256 | R$ 1.488 | R$ 1.254 | R$ 1.038 | -18% | R$ 2.030 | R$ 2.947 |
| fev | feriado | King Spa 2 | 20% (2/10) | R$ 2.566 | R$ 1.693 | R$ 2.256 | R$ 1.488 | R$ 1.254 | R$ 1.038 | -12% | R$ 2.030 | R$ 2.947 |
| fev | feriado | Balcony | 13% (4/30) | R$ 1.897 | R$ 1.251 | R$ 1.583 | R$ 1.044 | R$ 880 | R$ 728 | -17% | R$ 1.424 | R$ 2.059 |

### Este fim de semana (13 e 14/11): preços que seriam gravados com a decisão 1

Régua da estadia: quarto mais barato dos hotéis de nota abaixo de 9 R$ 609; com hidro dos mesmos hotéis R$ 842; 61 de 77 concorrentes com vaga. Valores de sexta · sábado quando diferem.

| Suíte | Vendidas sexta · sábado / unid. | Hoje: enviado | Hoje: público | Proposto: enviado | público | Genius 3 | hóspede paga ~ |
|---|---|---|---|---|---|---|---|
| Moinhos | 2 · 3 / 7 | R$ 2.006 | R$ 1.235 | R$ 1.087 | R$ 669 | R$ 451 | R$ 532 |
| Panorâmica II | 1 · 1 / 2 | R$ 2.019 | R$ 1.243 | R$ 1.536 | R$ 946 | R$ 638 | R$ 753 |
| Panorâmica I | 2 · 3 / 6 | R$ 2.013 | R$ 1.239 | R$ 1.436 · R$ 1.536 | R$ 884 · R$ 946 | R$ 596 · R$ 638 | R$ 704 · R$ 753 |
| Afrodite | 1 · 1 / 1 | R$ 2.860 | R$ 1.761 | lotada | lotada | lotada | lotada |
| King Spa 7 | 4 · 4 / 7 | R$ 2.856 | R$ 1.655 | R$ 1.632 | R$ 946 | R$ 837 | R$ 751 |
| King Spa 2 | 2 · 2 / 2 | R$ 2.406 | R$ 1.394 | lotada | lotada | lotada | lotada |
| Balcony | 0 · 0 / 6 | R$ 2.464 | R$ 1.428 | R$ 1.051 | R$ 609 | R$ 539 | R$ 483 |

## 7. Expectativa (receita do mês: já vendido + diárias a vender)

| Mês | Pousada | Meta | No ritmo de set-nov | % meta | Plano: alvo | % meta | ocupação | Plano com lotação ideal | % meta | ocupação | Mercado: ocupação final ano passado |
|---|---|---|---|---|---|---|---|---|---|---|---|
| nov | Recanto | R$ 220.000 | R$ 168.990 | 77% | R$ 188.264 | 86% | 62% | R$ 210.177 | 96% | 70% | 35% |
| nov | Villa | R$ 150.000 | R$ 135.132 | 90% | R$ 155.091 | 103% | 65% | R$ 171.609 | 114% | 73% | 42% |
| dez | Recanto | R$ 350.000 | R$ 218.319 | 62% | R$ 304.063 | 87% | 74% | R$ 339.181 | 97% | 83% | 42% |
| dez | Villa | R$ 300.000 | R$ 215.019 | 72% | R$ 262.301 | 87% | 73% | R$ 290.352 | 97% | 83% | 49% |
| jan | Recanto | R$ 350.000 | R$ 174.574 | 50% | R$ 246.131 | 70% | 69% | R$ 280.580 | 80% | 79% | 35% |
| jan | Villa | R$ 300.000 | R$ 161.983 | 54% | R$ 197.573 | 66% | 68% | R$ 223.752 | 75% | 79% | 45% |
| fev | Recanto | R$ 220.000 | R$ 161.519 | 73% | R$ 223.150 | 101% | 67% | R$ 255.100 | 116% | 77% | 29% |
| fev | Villa | R$ 150.000 | R$ 136.554 | 91% | R$ 165.522 | 110% | 67% | R$ 189.274 | 126% | 77% | 34% |

Ocupações do alvo do plano: {"semana": {"baixa": 0.55, "alta": 0.6}, "fds": {"baixa": 0.8, "alta": 0.85}, "feriado": {"baixa": 0.9, "alta": 0.9}, "festas": {"baixa": 0.9, "alta": 0.9}}; lotação ideal: {"semana": {"baixa": 0.65, "alta": 0.7}, "fds": {"baixa": 0.95, "alta": 0.97}, "feriado": {"baixa": 0.97, "alta": 0.97}, "festas": {"baixa": 0.97, "alta": 0.97}}. São alvos, não garantia; medir toda segunda. O ritmo de set-nov é o que os dados já mostraram (com entrada perto da proposta, os fins de semana fecharam em 65% e 68%); o alvo depende de três mudanças ainda não testadas (preço de venda desde a abertura, Moinhos e Balcony com preço próprio, descida na última semana); a lotação ideal não tem apoio nos dados da baixa temporada só com preço.

## 8. Decisões do dono (aguardando resposta)

1. Aplicar já os preços de abertura no fim de semana de 13 e 14/11.
2. Fim de semana: trocar o piso único de R$ 850 (Genius 3) por piso por suíte (na Villa, as King Spa só passam abaixo do piso atual na última semana, com menos de 60% vendido).
3. Fins de semana abrem no mercado e sobem pela escada só depois de metade vendida (substitui F5/F3 da ordem de 04/11 e a subida desde a primeira reserva).
4. Balcony com preço próprio; os 10% abaixo da King Spa viram teto.
5. Dia de semana: na última semana, as suítes ainda livres descem ao preço mediano do mercado (o piso de R$ 500 no Genius 3 vale até 8 dias antes).
6. Natal e Réveillon do Recanto abrem na mediana do mercado com hidro, não no topo (F5); a Villa já aparece na Booking abaixo dessa mediana.
7. Afrodite no fim de semana: 15% acima da Panorâmica I, no lugar da faixa de R$ 1.600 a R$ 1.800 públicos.

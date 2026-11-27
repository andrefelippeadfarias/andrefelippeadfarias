# Levantamentos da estratégia de dez-jan (27/11/2026)

### metas
# Diagnóstico de metas e lacunas: dez/2026 e jan/2027 (27/11/2026)

## Fontes e método
- **Vendas, livres, receita e preço enviado:** `pricelabs/estrategia-dez-jan/dados.json`, gerado em 27/11 às 13:15Z (`noites[].quartos.<quarto>`). Usei os campos `vendidas_reservas`, `vendidas_lote_suspeito`, `receita_noite`, `enviado` e `ocupacao_pricelabs`.
- **Como contei:**
  - **Vendidas** = `vendidas_reservas`, limitado à lotação do quarto.
  - **Lote** está dentro de `vendidas_reservas`, não somado. A prova está nos dados: Afrodite 12-14/01 tem 1 reserva e 1 de lote (1 unidade), King Spa 2 03/12 tem 1+1 e o PriceLabs marca 1/2, King Spa 7 14/12 tem 7 reservas, 4 de lote, e o PriceLabs marca 7/7.
  - **Livres** = lotação − vendidas.
  - **Receita do lote (estimativa):** receita da noite × lote ÷ reservas. É exata quando todas as reservas da noite são de lote.
- **Metas:** `.claude/skills/gestao-receita-pousadas/dados/metas_faturamento.json`.
  - **Base** = `por_pousada.<mês>.<pousada>.hoje`.
  - **Meta** = `realista`.
  - **Máximo** = `maximo`.
  - **Mínimo do dono** = `minimo_dono`. É o "mínimo" do relatório de novembro e de `regras.md` §6a (14/10).
  - **Piso** (por pousada) eu recalculei pelo método do arquivo: `insumos.mercado_ano_passado` × `cenarios.piso` × unidades × 31 × `teto_95_recanto`. A soma das duas pousadas bate com `meses.<mês>.piso`: R$ 334.243 em dez e R$ 254.440 em jan.
- **Curva de pace:** `quartos.json → parametros.pace.curvas` (estratégia v2.0, §5), aplicada à pousada inteira (o motor mede por quarto).
  - Semana: dom-qui e 27-29/12.
  - Fim de semana: sex-sáb.
  - Feriado: 23-26/12, 30-31/12, 01-03/01 e 22-24/01.
  - Antecedência D contada a partir de 27/11.
- **Diária** = receita (antes da comissão, como no livro) ÷ suíte-noites vendidas.
- **Não chamei** PriceLabs, Beds24 nem Booking. Todos os números vêm dos arquivos citados.

## 1. Resumo
- **Dezembro (as duas pousadas):**
  - Vendido: R$ 171.046, com 253 de 961 suíte-noites (26%). Desses, 43 são de lote suspeito (cerca de R$ 31,6 mil).
  - Mínimo do dono (R$ 650 mil): falta R$ 478.954.
  - Meta (R$ 522.529): falta R$ 351.483.
- **Janeiro (as duas pousadas):**
  - Vendido: R$ 57.559, com 80 de 961 (8%).
  - Mínimo do dono (R$ 650 mil): falta R$ 592.441.
  - Meta (R$ 397.720): falta R$ 340.161.
- **O mínimo do dono fica acima da meta nos 4 casos** e, no Recanto, acima até do máximo. O arquivo registra essa distância em `minimo_dono_vs_realista`: Recanto 0,32 em dez e 0,70 em jan; Villa 0,17 em dez e 0,56 em jan.
- **Para fechar o mínimo do dono** vendendo as livres ao preço público de hoje (estimado), seria preciso vender 60% a 69% das noites livres. A ocupação do mês ficaria em 67% a 74%. No ano passado, o mercado fez 36% a 50% (`insumos.mercado_ano_passado`).
- **A meta fecha com menos:** 35% a 46% das livres ao preço público de hoje.
- **No ritmo de venda dos últimos 44 dias** (desde o livro de 14/10), dezembro precisa vender 4,0 vezes mais rápido para a meta e 5,5 vezes para o mínimo. Janeiro precisa de 7,3 e 12,7 vezes.
- **Onde está o buraco:**
  - Dias de semana de dezembro: 224 livres no Recanto e 160 na Villa.
  - Natal e entre festas: 107 livres no Recanto e 86 na Villa.
  - Janeiro quase inteiro.
  - Na Villa, 164 das 306 livres de dezembro e 185 das 422 de janeiro são Balcony (sem banheira).
- **A força de dezembro da Villa depende do lote.** Os 35 quarto-noites de lote valem cerca de R$ 27,4 mil, 24% da receita da Villa em dezembro. Sem o lote, a ocupação da Villa em dezembro cai de 34% para 27%.

## 2. Metas por pousada (metas_faturamento.json)

| Mês | Pousada | Piso | Base | Meta | Máximo | Mínimo do dono |
|---|---|---|---|---|---|---|
| Dez | Recanto | R$ 171.028 | R$ 210.811 | R$ 265.465 | R$ 312.312 | R$ 350.000 |
| Dez | Villa | R$ 163.215 | R$ 205.651 | R$ 257.064 | R$ 308.476 | R$ 300.000 |
| Jan | Recanto | R$ 132.447 | R$ 163.255 | R$ 205.581 | R$ 241.860 | R$ 350.000 |
| Jan | Villa | R$ 121.993 | R$ 153.711 | R$ 192.139 | R$ 230.566 | R$ 300.000 |

## 3. Vendido contra as metas

| Mês | Pousada | Vendido | Suíte-noites vendidas (lote) | Livres | % do mínimo | Falta p/ mínimo | % da meta | Falta p/ meta | Falta p/ base | Falta p/ piso |
|---|---|---|---|---|---|---|---|---|---|---|
| Dez | Recanto | R$ 57.743 | 94 de 496 (8) | 402 | 16% | R$ 292.257 | 22% | R$ 207.722 | R$ 153.068 | R$ 113.285 |
| Dez | Villa | R$ 113.303 | 159 de 465 (35) | 306 | 38% | R$ 186.697 | 44% | R$ 143.761 | R$ 92.348 | R$ 49.912 |
| Jan | Recanto | R$ 26.054 | 37 de 496 (3) | 459 | 7% | R$ 323.946 | 13% | R$ 179.527 | R$ 137.201 | R$ 106.393 |
| Jan | Villa | R$ 31.505 | 43 de 465 (3) | 422 | 11% | R$ 268.495 | 16% | R$ 160.634 | R$ 122.206 | R$ 90.488 |

**Se o lote for falso** (receita estimada de R$ 4.162 no Recanto dez, R$ 27.442 na Villa dez, R$ 2.373 no Recanto jan e R$ 2.188 na Villa jan), muda sobretudo a Villa em dezembro:
- Vendido cai para R$ 85.861.
- As livres passam a 341.
- Falta R$ 214.139 para o mínimo e R$ 171.203 para a meta.

## 4. Por tipo de noite (dados.json)

### Recanto dos Moinhos (16 suítes)
| Tipo de noite | Noites | Suíte-noites | Vendidas | Das quais lote | Livres | Ocup. | Receita vendida | Diária do livro |
|---|---|---|---|---|---|---|---|---|
| Semana dom-qui (01-03, 06-10, 13-17, 20-22/12) | 16 | 256 | 32 | 4 | 224 | 12% | R$ 19.987 | R$ 625 |
| Fim de semana sex-sáb (04-05, 11-12, 18-19/12) | 6 | 96 | 44 | 4 | 52 | 46% | R$ 21.002 | R$ 477 |
| Natal 23-26/12 | 4 | 64 | 4 | 0 | 60 | 6% | R$ 5.279 | R$ 1.320 |
| Entre festas 27-29/12 | 3 | 48 | 1 | 0 | 47 | 2% | R$ 734 | R$ 734 |
| Réveillon 30-31/12 | 2 | 32 | 13 | 0 | 19 | 41% | R$ 10.741 | R$ 826 |
| **Total dez** | **31** | **496** | **94** | **8** | **402** | **19%** | **R$ 57.743** | **R$ 614** |
| Fim de semana 01-03/01 | 3 | 48 | 10 | 0 | 38 | 21% | R$ 7.439 | R$ 744 |
| S1 04-10/01, semana (04-07 e 10) | 5 | 80 | 7 | 0 | 73 | 9% | R$ 5.172 | R$ 739 |
| S1, fim de semana (08-09) | 2 | 32 | 0 | 0 | 32 | 0% | R$ 0 | — |
| S2 11-17/01, semana (11-14 e 17) | 5 | 80 | 6 | 3 | 74 | 8% | R$ 5.103 | R$ 850 |
| S2, fim de semana (15-16) | 2 | 32 | 2 | 0 | 30 | 6% | R$ 2.580 | R$ 1.290 |
| S3 18-21/01, semana | 4 | 64 | 7 | 0 | 57 | 11% | R$ 2.956 | R$ 422 |
| S3, feriadão do Aniversário de SP 22-24/01 (sex-dom) | 3 | 48 | 3 | 0 | 45 | 6% | R$ 2.520 | R$ 840 |
| S4 25-31/01, semana (25-28 e 31) | 5 | 80 | 2 | 0 | 78 | 2% | R$ 284 | R$ 142 |
| S4, fim de semana (29-30) | 2 | 32 | 0 | 0 | 32 | 0% | R$ 0 | — |
| **Total jan** | **31** | **496** | **37** | **3** | **459** | **7%** | **R$ 26.054** | **R$ 704** |

### Villa Dolce Amore (15 suítes)
| Tipo de noite | Noites | Suíte-noites | Vendidas | Das quais lote | Livres | Ocup. | Receita vendida | Diária do livro |
|---|---|---|---|---|---|---|---|---|
| Semana dom-qui (01-03, 06-10, 13-17, 20-22/12) | 16 | 240 | 80 | 21 | 160 | 33% | R$ 42.729 | R$ 534 |
| Fim de semana sex-sáb (04-05, 11-12, 18-19/12) | 6 | 90 | 39 | 5 | 51 | 43% | R$ 27.999 | R$ 718 |
| Natal 23-26/12 | 4 | 60 | 11 | 3 | 49 | 18% | R$ 9.594 | R$ 872 |
| Entre festas 27-29/12 | 3 | 45 | 8 | 0 | 37 | 18% | R$ 8.040 | R$ 1.005 |
| Réveillon 30-31/12 | 2 | 30 | 21 | 6 | 9 | 70% | R$ 24.941 | R$ 1.188 |
| **Total dez** | **31** | **465** | **159** | **35** | **306** | **34%** | **R$ 113.303** | **R$ 713** |
| Fim de semana 01-03/01 | 3 | 45 | 7 | 0 | 38 | 16% | R$ 7.972 | R$ 1.139 |
| S1 04-10/01, semana (04-07 e 10) | 5 | 75 | 10 | 2 | 65 | 13% | R$ 6.712 | R$ 671 |
| S1, fim de semana (08-09) | 2 | 30 | 5 | 1 | 25 | 17% | R$ 3.903 | R$ 781 |
| S2 11-17/01, semana (11-14 e 17) | 5 | 75 | 11 | 0 | 64 | 15% | R$ 6.471 | R$ 588 |
| S2, fim de semana (15-16) | 2 | 30 | 4 | 0 | 26 | 13% | R$ 1.922 | R$ 480 |
| S3 18-21/01, semana | 4 | 60 | 0 | 0 | 60 | 0% | R$ 0 | — |
| S3, feriadão do Aniversário de SP 22-24/01 | 3 | 45 | 1 | 0 | 44 | 2% | R$ 0 | — |
| S4 25-31/01, semana (25-28 e 31) | 5 | 75 | 2 | 0 | 73 | 3% | R$ 1.648 | R$ 824 |
| S4, fim de semana (29-30) | 2 | 30 | 3 | 0 | 27 | 10% | R$ 2.877 | R$ 959 |
| **Total jan** | **31** | **465** | **43** | **3** | **422** | **9%** | **R$ 31.505** | **R$ 733** |

### Por quarto
| Pousada | Quarto | Un. | Dez vendidas (lote) | Dez livres | Dez receita | Dez diária | Jan vendidas (lote) | Jan livres | Jan receita | Jan diária |
|---|---|---|---|---|---|---|---|---|---|---|
| Recanto | Moinhos (Q7) | 7 | 35 (8) | 182 | R$ 20.858 | R$ 596 | 14 (0) | 203 | R$ 14.788 | R$ 1.056 |
| Recanto | Panorâmica I (Double) | 6 | 47 (0) | 139 | R$ 24.081 | R$ 512 | 12 (0) | 174 | R$ 5.348 | R$ 446 |
| Recanto | Afrodite | 1 | 5 (0) | 26 | R$ 5.630 | R$ 1.126 | 9 (3) | 22 | R$ 4.794 | R$ 533 |
| Recanto | Panorâmica II (Q2) | 2 | 7 (0) | 55 | R$ 7.174 | R$ 1.025 | 2 (0) | 60 | R$ 1.124 | R$ 562 |
| Villa | King Spa 7 | 7 | 113 (30) | 104 | R$ 82.545 | R$ 730 | 20 (0) | 197 | R$ 16.775 | R$ 839 |
| Villa | King Spa 2 | 2 | 24 (5) | 38 | R$ 15.919 | R$ 663 | 22 (3) | 40 | R$ 14.072 | R$ 640 |
| Villa | Balcony (sem banheira) | 6 | 22 (0) | 164 | R$ 14.839 | R$ 674 | 1 (0) | 185 | R$ 658 | R$ 658 |

## 5. Ocupação × diária que fecham as metas nas noites livres
Cada célula é a diária média necessária nas livres vendidas quando se vende a porcentagem da coluna sobre as livres. A última linha de cada tabela é a ocupação final do mês nesse caso.

**Recanto, dez (402 livres; por livre: R$ 727 para o mínimo e R$ 517 para a meta)**
| Alvo | 40% | 50% | 60% | 70% | 80% | 100% |
|---|---|---|---|---|---|---|
| Mínimo do dono R$ 350.000 | R$ 1.818 | R$ 1.454 | R$ 1.212 | R$ 1.039 | R$ 909 | R$ 727 |
| Meta R$ 265.465 | R$ 1.292 | R$ 1.033 | R$ 861 | R$ 738 | R$ 646 | R$ 517 |
| Base R$ 210.811 | R$ 952 | R$ 762 | R$ 635 | R$ 544 | R$ 476 | R$ 381 |
| Piso R$ 171.028 | R$ 705 | R$ 564 | R$ 470 | R$ 403 | R$ 352 | R$ 282 |
| Ocupação final do mês | 51% | 59% | 68% | 76% | 84% | 100% |

**Villa, dez (306 livres; por livre: R$ 610 para o mínimo e R$ 470 para a meta)**
| Alvo | 40% | 50% | 60% | 70% | 80% | 100% |
|---|---|---|---|---|---|---|
| Mínimo do dono R$ 300.000 | R$ 1.525 | R$ 1.220 | R$ 1.017 | R$ 872 | R$ 763 | R$ 610 |
| Meta R$ 257.064 | R$ 1.175 | R$ 940 | R$ 783 | R$ 671 | R$ 587 | R$ 470 |
| Base R$ 205.651 | R$ 754 | R$ 604 | R$ 503 | R$ 431 | R$ 377 | R$ 302 |
| Piso R$ 163.215 | R$ 408 | R$ 326 | R$ 272 | R$ 233 | R$ 204 | R$ 163 |
| Ocupação final do mês | 61% | 67% | 74% | 80% | 87% | 100% |

Sem o lote, a Villa em dez fica assim (341 livres):
- Mínimo: R$ 628 por livre, ou R$ 897 a 70%.
- Meta: R$ 502 por livre, ou R$ 717 a 70%.

**Recanto, jan (459 livres; por livre: R$ 706 para o mínimo e R$ 391 para a meta)**
| Alvo | 40% | 50% | 60% | 70% | 80% | 100% |
|---|---|---|---|---|---|---|
| Mínimo do dono R$ 350.000 | R$ 1.764 | R$ 1.412 | R$ 1.176 | R$ 1.008 | R$ 882 | R$ 706 |
| Meta R$ 205.581 | R$ 978 | R$ 782 | R$ 652 | R$ 559 | R$ 489 | R$ 391 |
| Base R$ 163.255 | R$ 747 | R$ 598 | R$ 498 | R$ 427 | R$ 374 | R$ 299 |
| Piso R$ 132.447 | R$ 579 | R$ 464 | R$ 386 | R$ 331 | R$ 290 | R$ 232 |
| Ocupação final do mês | 44% | 54% | 63% | 72% | 81% | 100% |

**Villa, jan (422 livres; por livre: R$ 636 para o mínimo e R$ 381 para a meta)**
| Alvo | 40% | 50% | 60% | 70% | 80% | 100% |
|---|---|---|---|---|---|---|
| Mínimo do dono R$ 300.000 | R$ 1.591 | R$ 1.272 | R$ 1.060 | R$ 909 | R$ 795 | R$ 636 |
| Meta R$ 192.139 | R$ 952 | R$ 761 | R$ 634 | R$ 544 | R$ 476 | R$ 381 |
| Base R$ 153.711 | R$ 724 | R$ 579 | R$ 483 | R$ 414 | R$ 362 | R$ 290 |
| Piso R$ 121.993 | R$ 536 | R$ 429 | R$ 357 | R$ 306 | R$ 268 | R$ 214 |
| Ocupação final do mês | 46% | 55% | 64% | 73% | 82% | 100% |

**Villa: suítes com banheira × Balcony.** Hipótese: a Balcony vende 50% das livres a R$ 674 (a diária dela no livro de dezembro). Então:
- Dez: a Balcony traz R$ 55.268. As 142 livres com banheira (King Spa 7 e King Spa 2) precisam de R$ 926 (100%), R$ 1.028 (90%) ou R$ 1.322 (70%) para o mínimo, e de R$ 623, R$ 692 ou R$ 890 para a meta.
- Jan: a Balcony traz R$ 62.008. As 237 livres com banheira precisam de R$ 871, R$ 968 ou R$ 1.245 para o mínimo, e de R$ 416, R$ 462 ou R$ 594 para a meta.

**Teto aos preços enviados de hoje** (todas as livres vendidas). É estimativa: enviado × fator.
- Fatores do Recanto: público 0,63 até 14 dias da noite e 0,585 depois; Genius 3 0,41 em dom-qui e 0,425 em sex-sáb.
- Fatores da Villa: público 0,637 em sex-sáb e 0,41 em dom-qui (ponto médio de 0,383-0,436, `pendencias.json → villa-booking-razao-0436-20261123`); "sem promoção" usa 0,637 em todas as noites; Genius 3 0,53 em dom-qui e 0,575 em sex-sáb (`quartos.json → parametros.fator_vitrine_*`).

| Mês | Pousada | Público médio das livres | Teto ao público | % das livres para o mínimo | % das livres para a meta | Teto ao Genius 3 | % para o mínimo / a meta no Genius 3 |
|---|---|---|---|---|---|---|---|
| Dez | Recanto | R$ 1.188 | R$ 477.573 | 61% | 43% | R$ 330.843 | 88% / 63% |
| Dez | Villa | R$ 1.011 (R$ 1.319 sem promoção) | R$ 309.325 (R$ 403.734) | 60% (46%) | 46% (36%) | R$ 345.723 | 54% / 42% |
| Jan | Recanto | R$ 1.105 | R$ 507.160 | 64% | 35% | R$ 360.558 | 90% / 50% |
| Jan | Villa | R$ 920 (R$ 1.161 sem promoção) | R$ 388.067 (R$ 489.778) | 69% (55%) | 41% (33%) | R$ 421.945 | 64% / 38% |

Na Villa, o teto ao Genius 3 passa o teto ao público em dias de semana. Isso só acontece com uma promoção ativa derrubando a vitrine abaixo do fator Genius de 0,53, o que confirma a suspeita da pendência.

**Referências para julgar o realismo:**
- **Mercado no ano passado** (`metas_faturamento.json → insumos.mercado_ano_passado`):
  - Dez: Recanto 43% de ocupação a R$ 734; Villa 50% a R$ 702.
  - Jan: Recanto 36% a R$ 645; Villa 45% a R$ 583.
- **Régua de suítes comparáveis de 19/11** (`pricelabs/pesquisa-booking/diaria-2026-11-19/ranking-suites-comparaveis-dez-fev.md`; preço público, mediana / p75):
  - Dias de semana de dez: R$ 795-800 / R$ 1.159.
  - 18→20/12: R$ 1.306 / R$ 1.546.
  - 21→22/12: R$ 1.036 / R$ 1.395.
  - 27→29/12: R$ 1.115 / R$ 1.663.
  - Dias de semana de jan: R$ 999-1.049 / R$ 1.254-1.417.
  - Fins de semana de jan: R$ 1.127-1.230 / R$ 1.619-1.839.
  - 22→25/01: R$ 1.051 / R$ 1.437.
- **Nosso realizado em novembro, até 22/11** (`pricelabs/desempenho/2026-11_ate_2026-11-23.md`): Recanto R$ 692 a 59%; Villa R$ 532 a 67%.

## 6. Janelas de feriado e temporada contra as metas do arquivo (as duas pousadas)

| Janela (metas_faturamento.json) | Vendido | Suíte-noites (lote) | Livres | Piso | Base | Meta | % do piso |
|---|---|---|---|---|---|---|---|
| Natal 24-26/12 (`feriados`) | R$ 11.229 | 10 de 93 (2) | 83 | R$ 55.414 | R$ 76.139 | R$ 94.074 | 20% |
| Réveillon 29/12-02/01 | R$ 51.692 | 50 de 155 (6) | 105 | R$ 115.009 | R$ 156.896 | R$ 188.399 | 45% |
| Festas 18/12-02/01 (`temporadas`) | R$ 84.832 | 102 de 496 (11) | 394 | R$ 237.383 | R$ 321.695 | R$ 395.313 | 36% |
| Alta dez+jan | R$ 228.605 | 333 de 1.922 (49) | 1.589 | R$ 588.683 | R$ 733.428 | R$ 920.248 | 39% |
| Aniversário de SP 22-24/01 | R$ 2.520 | 4 de 93 (0) | 89 | R$ 33.601 | R$ 47.401 | R$ 60.085 | 8% |

**Meta de R$ 250 mil do Réveillon (30/12 a 02/01; `pricelabs/reveillon-2026-meta-250k.md`):**
- Vendido: R$ 48.406 em 47 diárias, 6 delas de lote. Sem o lote, cerca de R$ 39,7 mil em 41 diárias.
- Falta R$ 201.594 em 77 livres: R$ 2.618 por diária livre, mesmo vendendo 100%.
- Marcos de §8:
  - 23/11: alvo R$ 56,5 mil com 44 diárias; alarme R$ 53,1 mil com 43.
  - 30/11: alvo R$ 66,4 mil com 47 diárias; alarme R$ 59,6 mil com 46.
- Leitura: as diárias estão no alvo de 30/11, mas a receita está abaixo até do alarme de 23/11. A receita por diária caiu de R$ 1.165 (16/11, §3) para R$ 1.030.
- Restrições: Natal só em pacote 23→25 e Réveillon só 30→01, 30→03 (e 01→03, aprovado em 16/11) (`ordens_dono.json → dono-2026-11-03-festas-pacotes` e `dono-2026-11-16-reveillon-250k`). Por isso, parte das livres de 24/12, 29/12 e 02/01 só vende como noite solta ou em exceção.

## 7. Ritmo de venda
- **Dezembro:** o livro passou de R$ 58.598 (`metas_faturamento.json → insumos.livro`, 14/10) para R$ 171.046 hoje. São R$ 2.556 por dia em 44 dias.
  - Para fechar até 31/12 (34 dias): piso R$ 4.800/dia (1,9×), base R$ 7.218 (2,8×), meta R$ 10.338 (4,0×), mínimo R$ 14.087 (5,5×).
- **Janeiro:** o livro passou de R$ 26.096 para R$ 57.559, ou R$ 715 por dia.
  - Até 31/01 (65 dias): piso R$ 3.029/dia (4,2×), base R$ 3.991 (5,6×), meta R$ 5.233 (7,3×), mínimo R$ 9.114 (12,7×).
- **Ressalvas:**
  - A venda acelera perto da data: 39% das reservas chegam com 0 a 1 dia (`estrategia.md` §1).
  - A fonte de 14/10 (livro) e a de hoje (`get_pms_reservations`) não são idênticas.

## 8. As 15 piores noites (maior atraso contra a curva de pace, em suítes)
Atraso = curva × lotação − vendidas. O enviado é o de `dados.json → enviado`.

### Recanto
| # | Noite | Tipo | D | Vendidas (lote) | Ocup. | Curva | Atraso | Livres (Q7/Double/Afrodite/Q2) | Enviado Moinhos / Panorâmica I |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 01/12 ter | semana | 4 | 1 (0) | 6% | 38% | 5,2 | 7/6/1/1 | R$ 1.279 / R$ 1.288 |
| 2 | 02/12 qua | semana | 5 | 1 (0) | 6% | 35% | 4,6 | 7/5/1/2 | R$ 1.466 / R$ 1.480 |
| 3 | 23/12 qua | Natal | 26 | 1 (0) | 6% | 35% | 4,6 | 7/5/1/2 | R$ 2.803 / R$ 3.212 |
| 4 | 24/12 qui | Natal | 27 | 1 (0) | 6% | 34% | 4,4 | 7/5/1/2 | R$ 2.803 / R$ 3.212 |
| 5 | 25/12 sex | Natal | 28 | 1 (0) | 6% | 32% | 4,2 | 7/5/1/2 | R$ 2.264 / R$ 2.620 |
| 6 | 26/12 sáb | Natal | 29 | 1 (0) | 6% | 31% | 4,0 | 7/5/1/2 | R$ 2.264 / R$ 2.620 |
| 7 | 09/12 qua | semana | 12 | 0 (0) | 0% | 19% | 3,0 | 7/6/1/2 | R$ 1.466 / R$ 1.480 |
| 8 | 10/12 qui | semana | 13 | 0 (0) | 0% | 17% | 2,7 | 7/6/1/2 | R$ 1.466 / R$ 1.480 |
| 9 | 08/01 sex | fim de semana S1 | 42 | 0 (0) | 0% | 14% | 2,2 | 7/6/1/2 | R$ 1.654 / R$ 1.998 |
| 10 | 09/01 sáb | fim de semana S1 | 43 | 0 (0) | 0% | 14% | 2,2 | 7/6/1/2 | R$ 1.654 / R$ 1.998 |
| 11 | 03/01 dom | fim de semana 01-03/01 | 37 | 2 (0) | 12% | 26% | 2,1 | 7/6/0/1 | R$ 1.654 / R$ 1.658 |
| 12 | 03/12 qui | semana | 6 | 3 (0) | 19% | 32% | 2,0 | 7/5/0/1 | R$ 1.466 / R$ 1.613 |
| 13 | 22/01 sex | feriadão de SP | 56 | 1 (0) | 6% | 14% | 1,3 | 6/6/1/2 | R$ 1.912 / R$ 1.909 |
| 14 | 08/12 ter | semana | 11 | 2 (0) | 12% | 21% | 1,3 | 7/4/1/2 | R$ 1.267 / R$ 1.273 |
| 15 | 29/01 sex | fim de semana S4 | 63 | 0 (0) | 0% | 8% | 1,3 | 7/6/1/2 | R$ 1.595 / R$ 1.998 |

### Villa
| # | Noite | Tipo | D | Vendidas (lote) | Ocup. | Curva | Atraso | Livres (VK7/VK2/Balcony) | Enviado King Spa 7 / Balcony |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 01/12 ter | semana | 4 | 1 (0) | 7% | 38% | 4,8 | 6/2/6 | R$ 1.391 / R$ 1.206 |
| 2 | 26/12 sáb | Natal | 29 | 0 (0) | 0% | 31% | 4,7 | 7/2/6 | R$ 4.292 / R$ 3.861 |
| 3 | 02/12 qua | semana | 5 | 1 (0) | 7% | 35% | 4,2 | 6/2/6 | R$ 1.421 / R$ 1.270 |
| 4 | 25/12 sex | Natal | 28 | 1 (1) | 7% | 32% | 3,9 | 6/2/6 | R$ 4.292 / R$ 3.861 |
| 5 | 18/12 sex | fim de semana | 21 | 1 (1) | 7% | 26% | 2,9 | 6/2/6 | R$ 2.161 / R$ 1.930 |
| 6 | 03/12 qui | semana | 6 | 2 (1) | 13% | 32% | 2,7 | 6/1/6 | R$ 1.421 / R$ 1.270 |
| 7 | 23/01 sáb | feriadão de SP | 57 | 0 (0) | 0% | 14% | 2,1 | 7/2/6 | R$ 2.057 / R$ 1.842 |
| 8 | 24/01 dom | feriadão de SP | 58 | 0 (0) | 0% | 13% | 2,0 | 7/2/6 | R$ 2.057 / R$ 1.626 |
| 9 | 02/01 sáb | fim de semana 01-03/01 | 36 | 2 (0) | 13% | 26% | 2,0 | 6/1/6 | R$ 4.988 / R$ 4.483 |
| 10 | 03/01 dom | fim de semana 01-03/01 | 37 | 2 (0) | 13% | 26% | 1,9 | 6/1/6 | R$ 1.598 / R$ 1.435 |
| 11 | 19/12 sáb | fim de semana | 22 | 2 (1) | 13% | 25% | 1,8 | 5/2/6 | R$ 2.161 / R$ 1.930 |
| 12 | 23/12 qua | Natal | 26 | 4 (1) | 27% | 35% | 1,2 | 4/1/6 | R$ 4.070 / R$ 3.658 |
| 13 | 22/01 sex | feriadão de SP | 56 | 1 (0) | 7% | 14% | 1,2 | 6/2/6 | R$ 2.057 / R$ 1.842 |
| 14 | 01/01 sex | fim de semana 01-03/01 | 35 | 3 (0) | 20% | 27% | 1,1 | 5/2/5 | R$ 4.988 / R$ 4.483 |
| 15 | 17/01 dom | semana S2 | 51 | 0 (0) | 0% | 4% | 0,7 | 7/2/6 | R$ 1.598 / R$ 1.435 |

**Status contra a curva (por pousada):**
- Recanto dez: 3 à frente, 22 no ritmo, 5 atrás, 1 muito atrás.
- Villa dez: 11 à frente, 16 no ritmo, 2 atrás, 2 muito atrás.
- Janeiro: todas as noites saem "no ritmo" (só uma da Villa à frente), porque a curva pede 3% a 14% a D35-65. Contra as metas, janeiro é o maior buraco (seções 3 e 5).
- Noites de semana com zero venda ou quase: Villa 18-21/01, 25-28/01, 17/01 e 10/01; Recanto 25-30/01 e 08-10/01.

## 9. Noites já fortes (ocupação de 50% ou mais, ou 20 pontos acima da curva)

### Recanto
| Noite | Tipo | D | Vendidas | Firmes (sem lote) | vs curva | Livres |
|---|---|---|---|---|---|---|
| 04/12 sex | fim de semana | 7 | 13/16 (81%) | 11 (69%) | +31 pts | Q7 2, Afrodite 1 |
| 05/12 sáb | fim de semana | 8 | 13/16 (81%) | 11 (69%) | +34 pts | Q7 2, Afrodite 1 |
| 31/12 qui | Réveillon | 34 | 9/16 (56%) | 9 (56%) | +29 pts | Q7 2, Double 3, Afrodite 1, Q2 1 |

### Villa
| Noite | Tipo | D | Vendidas | Firmes (sem lote) | vs curva | Livres |
|---|---|---|---|---|---|---|
| 04/12 sex | fim de semana | 7 | 8/15 (53%) | 7 (47%) | +3 pts | VK7 4, Balcony 3 |
| 05/12 sáb | fim de semana | 8 | 12/15 (80%) | 10 (67%) | +33 pts | Balcony 3 |
| 06/12 dom | semana | 9 | 10/15 (67%) | 7 (47%) | +42 pts | Balcony 5 (VK7 em overbooking) |
| 07/12 seg | semana | 10 | 8/15 (53%) | 6 (40%) | +31 pts | VK7 1, Balcony 6 |
| 12/12 sáb | fim de semana | 15 | 10/15 (67%) | 10 (67%) | +36 pts | Balcony 5 (VK7 em overbooking) |
| 13/12 dom | semana | 16 | 8/15 (53%) | 7 (47%) | +39 pts | VK7 1, Balcony 6 |
| 14/12 seg | semana | 17 | 9/15 (60%) | 5 (33%) | +46 pts | Balcony 6 |
| 15/12 ter | semana | 18 | 5/15 (33%) | 2 (13%) | +20 pts | VK7 2, VK2 2, Balcony 6 |
| 16/12 qua | semana | 19 | 7/15 (47%) | 3 (20%) | +34 pts | VK2 2, Balcony 6 |
| 21/12 seg | semana | 24 | 5/15 (33%) | 5 (33%) | +23 pts | VK7 4, Balcony 6 |
| 30/12 qua | Réveillon | 33 | 8/15 (53%) | 5 (33%) | +25 pts | VK7 1, VK2 1, Balcony 5 |
| 31/12 qui | Réveillon | 34 | 13/15 (87%) | 10 (67%) | +59 pts | VK2 1, Balcony 1 |
| 14/01 qui | semana S2 | 48 | 4/15 (27%) | 4 (27%) | +22 pts | VK7 5, Balcony 6 |

Leitura: a força da Villa de 13 a 16/12 e do Réveillon vem da King Spa 7, e boa parte é do lote (14/12: 4 de 9; 16/12: 4 de 7). Sem o lote, 14-16/12 cai para 13% a 33%. A Balcony fica livre em todas as noites fortes.

## 10. Alertas de dados (conferir antes de decidir)
1. **Overbooking na King Spa 7:**
   - 06/12: 8 reservas para 7 unidades, 3 delas do lote; o PriceLabs marca 6/7.
   - 12/12: 9 reservas para 7, só 1 do lote. **Sem o lote ainda sobra 1.** É novo em relação à pendência `booking-lote-16-reservas-20261117`, que cita só 06/12.
2. **Receita baixa ou zero:** 34 suíte-noites têm menos de R$ 300 por reserva-noite.
   - Recanto em dez, 21 suíte-noites: Panorâmica I em 02-03/12 (R$ 0), 11-12/12 (R$ 168), 18-19/12 (R$ 113), 20-22/12 (R$ 150) e 31/12 (R$ 283).
   - Recanto em jan, 9 suíte-noites: Panorâmica I em 01-02/01 (R$ 169) e 31/01 (R$ 142); Afrodite em 18-20/01 (R$ 0).
   - Villa, 4 suíte-noites: King Spa 2 em 08/12 (R$ 250) e 15-16/01 (R$ 233); King Spa 7 em 22/01 (R$ 0).
   - Efeitos: derruba a diária do livro (Recanto fim de semana dez R$ 477) e pode ser bloqueio lançado como reserva (`aprendizados.md`, 23/11). Se forem bloqueios, o Recanto tem cerca de 30 livres a mais.
3. **Lote suspeito em dez-jan:** 49 suíte-noites (Recanto 11, Villa 38), com cerca de R$ 36,2 mil.
   - Recanto: Moinhos 04-07/12 e Afrodite 12-14/01. O pacote marca lote também no Recanto, não só na Villa.
   - Villa: King Spa 7 de 05/12 a 31/12 e King Spa 2 em 03-07/12 e 06-08/01.
4. **Divergência com o PriceLabs:** 13 quarto-noites diferem de `ocupacao_pricelabs` (5 no Recanto, 8 na Villa). Exemplo: King Spa 7 em 05/12 tem 7 reservas e o PriceLabs marca 5/7. A Moinhos (Q7) e a Afrodite vêm sem ocupação do PriceLabs no pacote.

## 11. Leituras para a estratégia
- **Mínimo do dono:** em dezembro, pede 61% (Recanto) e 60% (Villa) das livres ao público de hoje, com ocupação final de 69% a 74%, contra 43-50% do mercado no ano passado. Em janeiro, pede 64% e 69%. É improvável sem descontos menores e venda direta.
- **Meta:** em dezembro, fecha com 43% (Recanto) e 46% (Villa) das livres ao público de hoje; em janeiro, com 35% e 41%. As diárias necessárias a 50-60% (Recanto dez R$ 861-1.033; Villa dez R$ 783-940) ficam perto da mediana comparável dos dias de semana e abaixo da de fim de semana.
- **Volume:** as maiores massas de livres estão nos dias de semana de dez e jan, em Natal e entre festas (23-29/12), no feriadão de SP e na Balcony (349 livres nos dois meses).
- **Antes de fixar alvos:** confirmar o lote (pesa 24% da Villa em dez), o overbooking de 12/12 e as reservas de valor baixo da Panorâmica I.

Arquivos de trabalho (cálculos reproduzíveis):
- /tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/rows.json
- /tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/nights.json
- /tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/diag.py a diag5.py

### booking_dez
# Concorrência na Booking em dezembro: 7 datas, 2 adultos, Campos do Jordão

**Fonte:** buscas feitas em 27/11/2026, só pelo conector oficial (mcp__Booking_com__*, somente leitura), em BRL.
- Concorrentes: `accommodations_search_headless` com destination "Campos do Jordão, Brazil", facilities `HOT_TUB_JACUZZI`, result_count 50.
- Recanto: busca por coordinates -22.728453, -45.556345, raio 0,3.
- Villa: busca por hotel_names ["Villa Dolce Amore"] com destination.
- Preço por tipo de quarto: `accommodations_room_search` (ids 7562933 e 14824789).
- Todos os preços são o **total da estadia**.
- Nenhuma busca falhou. Uma busca por nome em 23→25/12 voltou "sem disponibilidade". Isso é um dado, ver item 7.

## 1. Preço total dos concorrentes com hidro e o nosso preço público

| Data | Noites | Mín | P25 | Mediana | P75 | Máx | Mediana nota ≥9 | Recanto (posição · percentil) | Villa (percentil) |
|---|---|---|---|---|---|---|---|---|---|
| 03→04/12 (qui) | 1 | R$ 134,94 | R$ 290,30 | R$ 446,35 | R$ 679,00 | R$ 1.092,50 | R$ 778,67 (n17) | **R$ 474,98** · 1º · p51 | **R$ 533,81** · p61 |
| 04→06/12 | 2 | R$ 524,16 | R$ 1.100,00 | R$ 1.520,00 | R$ 2.160,00 | R$ 3.059,37 | R$ 1.474,20 (n21) | **R$ 2.722,38** · 18º · p88 | **R$ 1.949,22** · p67 |
| 08→09/12 (ter) | 1 | R$ 140,25 | R$ 315,00 | R$ 480,00 | R$ 705,92 | R$ 1.748,11 | R$ 844,36 (n18) | **R$ 741,20** · 6º · p76 | **R$ 550,37** · p61 |
| 11→13/12 | 2 | R$ 768,00 | R$ 1.094,94 | R$ 1.530,00 | R$ 1.944,00 | R$ 3.745,94 | R$ 1.530,00 (n23) | **R$ 2.497,95** · 6º · p88 | **R$ 1.965,78** · p76 |
| 15→16/12 (ter) | 1 | R$ 140,25 | R$ 315,00 | R$ 471,50 | R$ 675,00 | R$ 1.357,00 | R$ 799,00 (n17) | **R$ 738,91** · 6º · p78 | **R$ 526,16** · p59 |
| 18→20/12 | 2 | R$ 652,86 | R$ 1.158,30 | R$ 1.582,00 | R$ 2.160,00 | R$ 3.745,94 | R$ 1.600,00 (n23) | **R$ 1.888,40** · 1º · p67 | **R$ 2.297,66** · p82 |
| 23→25/12 (Natal) | 2 | R$ 1.016,06 | R$ 1.456,38 | R$ 2.200,00 | R$ 3.100,00 | R$ 5.724,00 | R$ 2.918,00 (n23) | **R$ 3.269,42** · 7º · p76 | **R$ 2.418,69** · p53 |

Como ler a tabela:
- Cada data tem 49 concorrentes: o top 50 da busca com hidro, sem nós.
- "Posição" é a ordem em que o conector devolveu a busca com hidro.
- "Percentil" é a % dos 49 concorrentes mais baratos que nós.
- **A Villa não aparece na busca com hidro em nenhuma das 7 datas.**
- O preço por noite nos fins de semana:
  - Recanto: R$ 1.361,19 (04-06), R$ 1.248,97 (11-13), R$ 944,20 (18-20) e R$ 1.634,71 (Natal).
  - Villa: R$ 974,61, R$ 982,89, R$ 1.148,83 e R$ 1.209,35.

## 2. Cinco comparáveis com nota (total da estadia, menor tarifa)

Fixos: DOM Hotel (9,3 · 973 aval.), Pousada Casa Redonda (9,2 · 437), Pousada Geada de Campos (9,6 · 318), Pousada das Lavandas (9,7 · 76) e Pousada Chateau Colinas (9,7 · 170). Casa Redonda e Chateau Colinas ficam a cerca de 165 m e 70 m da Villa, pelas coordenadas da Booking.

| Data | DOM | Casa Redonda | Geada | Lavandas | 5º comparável |
|---|---|---|---|---|---|
| 03/12 | R$ 347,49 | R$ 360,00 | R$ 355,81 | R$ 609,12 | Chateau não voltou na busca por nome → Secreto Boutique (9,2 · 185) R$ 778,67 |
| 04-06/12 | R$ 1.474,20 | R$ 1.080,00 | R$ 830,62 | R$ 2.579,62 | Chateau R$ 1.176,00 |
| 08/12 | R$ 347,49 | R$ 360,00 | R$ 355,81 | R$ 609,12 | Chateau fora do top 50 → Secreto R$ 1.071,77 |
| 11-13/12 | R$ 1.474,20 | R$ 1.080,00 | R$ 973,42 | R$ 1.868,41 | Chateau R$ 1.272,00 |
| 15/12 | R$ 347,49 | R$ 360,00 | R$ 355,81 | R$ 609,12 | Chateau fora do top 50 → Secreto R$ 1.071,77 |
| 18-20/12 | R$ 1.421,55 | R$ 1.080,00 | R$ 1.116,22 | R$ 1.694,69 | Chateau R$ 1.272,00 |
| 23-25/12 | **ESGOTADO** | R$ 1.416,00 | R$ 1.354,22 | **ESGOTADA** | Chateau e Secreto **esgotados** |

Comparáveis de Natal no lugar dos esgotados: Village della Nonna (9,5 · 186) R$ 1.840,00, Villa Casato (9,1 · 84) R$ 2.800,00 e vila grega (9,8 · 68) R$ 4.607,50.

## 3. Nossos quartos: menor tarifa e quartos à venda (busca de quartos, 27/11)

Mapeamento provável dos quartos, pela disponibilidade máxima vista:
- Recanto: …304 "Suíte Vista Montanha" = Moinhos (7); …302 "Suíte King hidro 25 m²" = Panorâmica I (6); …301 "Quarto King hidro 22 m²" = Panorâmica II (2); …303 "Suíte King hidro 35 m² varanda" = Afrodite (1).
- Villa: …901 40 m² = VK7; …903 31 m² = VK2; …902 Varanda = Balcony.

| Data | R …304 | R …302 | R …301 | R …303 | Recanto à venda | V VK7 | V VK2 | V Balcony | Villa à venda |
|---|---|---|---|---|---|---|---|---|---|
| 03/12 | R$ 538,31 (7) | R$ 1.066,13 (5) | R$ 1.040,35 (1) | – | 13/16 | R$ 640,82 (5) | R$ 606,42 (1) | R$ 533,81 (6) | 12/15 |
| 04-06 | R$ 3.085,36 (2) | – | – | R$ 3.780,69 (1) | 3/16 | R$ 3.026,39 (2) | – | R$ 1.949,22 (3) | 5/15 |
| 08/12 | R$ 840,03 (7) | R$ 844,00 (4) | R$ 843,34 (2) | R$ 1.021,02 (1) | 14/16 | R$ 697,52 (4) | R$ 606,42 (1) | R$ 550,37 (6) | 11/15 |
| 11-13 | R$ 2.831,01 (6) | R$ 3.238,09 (4) | R$ 3.141,29 (2) | – | 12/16 | – | – | R$ 1.965,78 (3) | 3/15 |
| 15/12 | R$ 837,43 (6) | R$ 841,40 (6) | R$ 840,74 (2) | R$ 1.017,88 (1) | 15/16 | R$ 603,24 (2) | R$ 606,42 (2) | R$ 526,16 (6) | 10/15 |
| 18-20 | R$ 2.140,19 (5) | R$ 2.758,85 (3) | R$ 2.585,68 (2) | R$ 3.172,61 (1) | 11/16 | R$ 2.705,34 (5) | R$ 2.661,39 (2) | R$ 2.297,66 (6) | 13/15 |
| 23-25 | R$ 3.705,34 (7) | R$ 4.246,01 (5) | R$ 4.119,10 (2) | R$ 4.891,10 (1) | 15/16 | R$ 3.202,36 (4) | R$ 3.267,81 (1) | R$ 2.418,69 (2) | 7/15 |

"–" quer dizer que o quarto não foi listado (sem disponibilidade). O número entre parênteses é o availability_count.

## 4. Principais achados (todos da busca de 27/11)

1. **A Villa não aparece na busca com hidro em nenhuma das 7 datas.**
   - A lista de facilidades da Villa não tem "Jacuzzi". Só os quartos VK7 e VK2 trazem "Banheira de hidromassagem privativa".
   - O Recanto tem "Jacuzzi" na propriedade e aparece entre 1º e 18º.
   - Provável causa: falta marcar a facilidade da propriedade na extranet.
2. **O conector não devolve nota para o Recanto**, nem na busca nem no `accommodations_details`.
   - A Villa aparece com 8,3 (229 aval.).
   - Os 5 comparáveis têm de 9,2 a 9,7.
3. **A busca mostra o Recanto 11,76% abaixo da busca de quartos.** O preço na busca é 0,8824 × a menor tarifa da busca de quartos nas 7 datas. Exemplos: R$ 474,98 / R$ 538,31 e R$ 3.269,42 / R$ 3.705,34. Na Villa o fator é 1,00.
4. **Fins de semana 04-06 e 11-13: Recanto no p88, acima do p75.**
   - Os 5 comparáveis cobram de R$ 415 a R$ 1.290 por noite (ex.: Casa Redonda R$ 540, Chateau R$ 588-636, DOM R$ 737).
   - Em 04-06 o Recanto tem só 3 de 16 à venda. O preço alto se sustenta.
   - Em 11-13 tem 12 de 16 à venda a 14 dias da chegada. Aqui o risco é real.
5. **Terças 08/12 e 15/12: Recanto a R$ 741,20 e R$ 738,91 (p76-p78).**
   - É cerca de 2× DOM, Casa Redonda e Geada (R$ 347-360).
   - Tem 14 e 15 de 16 à venda.
   - A Villa fica no p59-p61 com o Balcony, que não tem banheira.
6. **03/12: a Panorâmica I (…302) custa R$ 1.066,13, 1,98× a Moinhos (…304, R$ 538,31).** Em 08/12 e 15/12 os dois tipos têm quase o mesmo preço (R$ 840-844). Em 18-20 a Panorâmica I custa 1,29× a Moinhos.
7. **Natal 23-25: o mercado está apertando.**
   - DOM, Chateau Colinas, Lavandas e Secreto estão esgotados. A busca por nome voltou com o erro `hotel_names_no_availability`.
   - O Recanto ainda tem 15 de 16 à venda, a R$ 3.269,42 (p76; 12% acima da mediana nota ≥9, R$ 2.918,00).
   - A Villa tem 7 de 15 à venda (Balcony 2 de 6), a R$ 2.418,69 (p53).
8. **Em 11-13 a Villa não tem nenhuma das 9 suítes com banheira à venda.** VK7 e VK2 não foram listados, só o Balcony (3). Em 04-06 o VK2 também está esgotado.
   - Isso precisa ser cruzado com "vendidas_lote_suspeito". Se forem reservas duvidosas, há suítes com banheira travadas no fim de semana.
9. **Em 18-20 a Villa está mais cara que o Recanto:** p82 contra p67.
   - O Balcony sem banheira custa R$ 2.297,66, com 6 de 6 à venda.
   - A Moinhos com banheira custa R$ 2.140,19 na busca de quartos e R$ 1.888,40 na busca.
10. **As tarifas da Villa variam sem padrão. Isso reforça a suspeita de promoção na extranet.**
    - No Balcony, a tarifa 445484962 é a mais barata em 11-13 (R$ 1.965,78). Mas custa R$ 4.789,60 no Natal, contra R$ 2.418,69 da tarifa 432138662.
    - A razão entre a tarifa 432138662 e a 419782010 vai de 0,62 (11-13) a 0,86 (03/12 e Natal).
    - O VK2 fica fixo em R$ 606,42 em 03, 08 e 15/12. Parece um piso.
    - Em 15/12 o VK7, maior, custa menos que o VK2: R$ 603,24 contra R$ 606,42.

**Limites dos dados:**
- As faixas de preço vêm do top 50 da ordem da Booking. Incluem chalés e casas.
- O filtro de hidro é da propriedade. A menor tarifa de um concorrente pode ser de um quarto sem banheira.
- Muitos concorrentes vieram sem nota no conector.
- "Fora do top 50" não foi checado como esgotado. Só o Natal foi checado por nome.

## Arquivos
- Notas consolidadas: /tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/notas.md
- Resultados brutos das buscas com hidro (JSON), na ordem das datas:
  - /root/.claude/projects/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/tool-results/mcp-Booking_com-accommodations_search_headless-1795785209613-5pb6o7.txt (03/12)
  - /root/.claude/projects/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/tool-results/mcp-Booking_com-accommodations_search_headless-1795785269208-n1k0g9.txt (04-06/12)
  - /root/.claude/projects/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/tool-results/mcp-Booking_com-accommodations_search_headless-1795785352541-reh7fd.txt (08/12)
  - /root/.claude/projects/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/tool-results/mcp-Booking_com-accommodations_search_headless-1795785383072-ok4a5n.txt (11-13/12)
  - /root/.claude/projects/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/tool-results/mcp-Booking_com-accommodations_search_headless-1795785410484-ik3hz5.txt (15/12)
  - /root/.claude/projects/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/tool-results/mcp-Booking_com-accommodations_search_headless-1795785438030-xl4lak.txt (18-20/12)
  - /root/.claude/projects/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/tool-results/mcp-Booking_com-accommodations_search_headless-1795785473799-y815h2.txt (23-25/12)
- Script de estatística: /tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/scripts/resumo2.py

Nenhum arquivo do repositório foi alterado. PriceLabs e Beds24 não foram chamados.

### booking_jan
# Concorrência na Booking: Réveillon e janeiro (busca de 27/11/2026)

Busquei só com leitura, pelo conector oficial da Booking.com. Não chamei PriceLabs nem Beds24. Nenhum arquivo do repositório foi alterado. Busca com 2 adultos, em BRL.

## Como fiz
- **Mercado:** `accommodations_search_headless` com o filtro HOT_TUB_JACUZZI em Campos do Jordão, até 50 resultados por data. A Booking afrouxou o filtro no Réveillon. Por isso só conto como concorrente quem tem "Jacuzzi" nas facilities. Recanto e Villa ficam fora da conta.
- **Nosso preço público:**
  - Recanto: busca por coordenadas (-22.728453, -45.556345, raio 0,3).
  - Villa: busca por hotel_names "Villa Dolce Amore", destino "Campos do Jordão, Brazil".
  - Quartos e tarifas: `accommodations_room_search` para as duas pousadas.
- **Posição:** "x/N" é quantos concorrentes com hidro estão mais baratos que nós. "pNN" é o percentil. "lista #" é a ordem de relevância da Booking.
- **Fontes:** o campo `price.book` é o total da estadia. Nas tabelas, o preço é por noite.
- **Arquivos** (na pasta `/tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/jan27/`):
  - Mercado: `hidro_<checkin>_<checkout>.json`, campos `accommodations[].price.book`, `.facilities` e `.rating`.
  - Estatística: `mercado_hidro_estrito.txt`.
  - Quartos: `quartos_room_search_2711.json`.

## Tabela 1. Mercado com hidro × nosso preço público (por noite)

| Datas | Noites | N com hidro | Mín | P25 | Mediana | P75 | Máx | Recanto | Posição Recanto | Recanto ÷ mediana | Villa | Posição Villa | Villa ÷ mediana |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 30/12→01/01 | 2 | 34 | R$ 512 | R$ 1.181 | R$ 1.674 | R$ 2.356 | R$ 3.500 | R$ 2.901 | 31/34 (p91) · lista #1 | 1,73 | R$ 1.664 | 17/34 (p50) | 0,99 |
| 01→03/01 | 2 | 37 | R$ 517 | R$ 999 | R$ 1.421 | R$ 1.779 | R$ 3.009 | R$ 2.552 | 34/37 (p92) · lista #7 | 1,80 | R$ 1.498 | 20/37 (p54) | 1,05 |
| 05→06/01 (ter) | 1 | 49 | R$ 140 | R$ 343 | R$ 538 | R$ 883 | R$ 1.770 | R$ 978 | 40/49 (p82) · lista #22 | 1,82 | R$ 678 | 31/49 (p63) | 1,26 |
| 08→10/01 | 2 | 49 | R$ 200 | R$ 537 | R$ 702 | R$ 962 | R$ 2.124 | R$ 965 | 37/49 (p76) · lista #10 | 1,38 | R$ 1.043 | 38/49 (p78) | 1,49 |
| 12→13/01 (ter) | 1 | 49 | R$ 113 | R$ 319 | R$ 538 | R$ 883 | R$ 1.770 | R$ 972 | 39/49 (p80) · lista #14 | 1,81 | R$ 754 | 33/49 (p67) | 1,40 |
| 15→17/01 | 2 | 49 | R$ 200 | R$ 529 | R$ 682 | R$ 945 | R$ 2.124 | R$ 930 | 35/49 (p71) · lista #4 | 1,36 | R$ 1.106 | 41/49 (p84) | 1,62 |
| 22→24/01 | 2 | 49 | R$ 294 | R$ 495 | R$ 656 | R$ 949 | R$ 1.700 | R$ 1.113 | 41/49 (p84) · lista #8 | 1,70 | R$ 1.137 | 41/49 (p84) | 1,73 |
| 26→27/01 (ter) | 1 | 49 | R$ 113 | R$ 302 | R$ 490 | R$ 800 | R$ 1.944 | R$ 930 | 38/49 (p78) · lista #13 | 1,90 | R$ 695 | 33/49 (p67) | 1,42 |

**Totais da estadia que usei:**
- Recanto: 5.801,67 · 5.103,00 · 978,03 · 1.929,23 · 971,61 · 1.860,41 · 2.226,66 · 930,20.
- Villa: 3.328,73 · 2.995,12 · 677,60 · 2.085,92 · 753,97 · 2.211,89 · 2.274,87 · 695,18.

**Só o grupo boutique.** Recorte: hotel, pousada ou casa de hóspedes com hidro, nota ≥ 9 e ≥ 50 avaliações. Fonte: `mercado_hidro_estrito.txt`.

| Datas | n | Mediana por noite | Percentil Recanto | Percentil Villa |
|---|---|---|---|---|
| 30/12 | 7 | R$ 2.327 | p86 | p43 |
| 01/01 | 6 | R$ 1.934 | p83 | p33 |
| 05/01 | 12 | R$ 801 | p75 | p42 |
| 08/01 | 13 | R$ 1.030 | p46 | p54 |
| 12/01 | 11 | R$ 609 | p73 | p55 |
| 15/01 | 13 | R$ 661 | p62 | p62 |
| 22/01 | 13 | R$ 700 | p54 | p54 |
| 26/01 | 13 | R$ 619 | p69 | p54 |

## Tabela 2. Cinco comparáveis (por noite, nota/avaliações, posição na lista)

**Grupo fixo:** DOM Hotel (9,3/973), Casa Redonda (9,2/437), Chateau Colinas (9,7/170), Pousada das Lavandas (9,7/76) e Village della Nonna (9,5/186).

| Datas | DOM | Casa Redonda | Chateau Colinas | Lavandas | Della Nonna |
|---|---|---|---|---|---|
| 30/12→01/01 | sem disp. | sem disp. | sem disp. | sem disp. | sem disp. |
| 01→03/01 | sem disp. | sem disp. | sem disp. | sem disp. | sem disp. |
| 05/01 | R$ 411 (#12) | R$ 360 (#6) | sem disp. | R$ 934 (#33) | sem disp. |
| 08→10/01 | R$ 614 (#3) | R$ 540 (#8) | R$ 501 (#9) | R$ 1.030 (#23) | R$ 702 (#24) |
| 12/01 | R$ 319 (#3) | R$ 360 (#7) | sem disp. | R$ 609 (#11) | sem disp. |
| 15→17/01 | R$ 614 (#8) | R$ 540 (#12) | R$ 501 (#9) | R$ 700 (#3) | R$ 661 (#19) |
| 22→24/01 | R$ 614 (#6) | R$ 540 (#12) | sem disp. | R$ 700 (#4) | R$ 552 (#9) |
| 26/01 | R$ 319 (#4) | R$ 360 (#10) | sem disp. | R$ 609 (#11) | sem disp. |

"Sem disp." foi confirmado por busca hotel_names em 27/11, que devolveu o erro `hotel_names_no_availability`.

**Réveillon: o grupo fixo exige estadia longa.** Para 2 noites, nenhum dos 7 tem vaga. Para 4 noites (29/12→02/01, por noite) todos têm:
- DOM R$ 1.444
- Casa Redonda R$ 911
- Chateau Colinas R$ 1.266
- Lavandas R$ 2.337
- Della Nonna R$ 1.351
- Secreto R$ 2.137
- Apple House R$ 2.159

A Villa vende essas 4 noites a R$ 1.558 por noite (total R$ 6.230,98). O Recanto não aparece para 4 noites. Isso é inferido das buscas de 29/12→02/01.

**Réveillon: 5 comparáveis com hidro que vendem 2 noites** (por noite, posição na lista):

| Datas | Geada de Campos (9,6) | Vila Grega (9,8) | Pousada Murano (9,7) | Carballo Hotel & Spa (9,7) | Hotel Estoril (8,9) |
|---|---|---|---|---|---|
| 30/12→01/01 | R$ 1.005 (#4) | R$ 2.366 (#8) | R$ 2.618 (#11) | R$ 2.327 (#15) | R$ 3.220 (#13) |
| 01→03/01 | R$ 707 (#4) | R$ 2.090 (#10) | R$ 1.779 (#8) | R$ 2.327 (#18) | R$ 2.422 (#15) |

**Outros comparáveis em janeiro** (por noite): Estoril R$ 622–945, Apple House R$ 346–646, Geada R$ 320–463, Secreto R$ 1.050–1.575, Vila Grega R$ 1.187–1.332 (só nos fins de semana).

## Tabela 3. Nossos quartos na Booking (menor tarifa por noite; disponíveis/unidades)

Fonte: `quartos_room_search_2711.json`. A correspondência com Q7, Double, Q2 e Afrodite é inferida pela disponibilidade máxima observada.

| Datas | Q7 (Vista Mont.) | Double (King 25 m²) | Q2 (King 22 m²) | Afrodite (35 m²) | Recanto aberto | VK7 (40 m²) | VK2 (31 m²) | Balcony (sem banheira) | Villa aberto |
|---|---|---|---|---|---|---|---|---|---|
| 30/12 | R$ 3.288 · 2/7 | R$ 3.297 · 3/6 | R$ 3.290 · 1/2 | R$ 4.428 · 1/1 | 7/16 | não ofertado | R$ 1.978 · 1/2 | R$ 1.664 · 1/6 | 2/15 |
| 01/01 | R$ 2.892 · 4/7 | R$ 3.003 · 4/6 | R$ 2.943 · 2/2 | R$ 3.847 · 1/1 | 11/16 | R$ 1.856 · 5/7 | R$ 1.681 · 1/2 | R$ 1.498 · 5/6 | 11/15 |
| 05/01 | R$ 1.108 · 7/7 | R$ 1.115 · 5/6 | R$ 1.114 · 2/2 | não ofertado | 14/16 | R$ 789 · 6/7 | R$ 678 · 1/2 | R$ 785 · 6/6 | 13/15 |
| 08/01 | R$ 1.093 · 7/7 | R$ 1.321 · 6/6 | R$ 1.272 · 2/2 | R$ 1.520 · 1/1 | 16/16 | R$ 1.161 · 6/7 | não ofertado | R$ 1.043 · 6/6 | 12/15 |
| 12/01 | R$ 1.101 · 7/7 | R$ 1.105 · 6/6 | R$ 1.104 · 2/2 | não ofertado | 15/16 | R$ 754 · 6/7 | não ofertado | R$ 836 · 6/6 | 12/15 |
| 15/01 | R$ 1.054 · 6/7 | R$ 1.321 · 6/6 | R$ 1.261 · 2/2 | R$ 1.520 · 1/1 | 15/16 | R$ 1.234 · 6/7 | R$ 1.281 · 1/2 | R$ 1.106 · 6/6 | 13/15 |
| 22/01 | R$ 1.264 · 6/7 | R$ 1.262 · 6/6 | R$ 1.272 · 2/2 | R$ 1.454 · 1/1 | 15/16 | R$ 1.270 · 6/7 | R$ 1.270 · 2/2 | R$ 1.137 · 6/6 | 14/15 |
| 26/01 | R$ 1.054 · 7/7 | R$ 1.056 · 6/6 | R$ 1.062 · 2/2 | R$ 1.282 · 1/1 | 16/16 | R$ 810 · 7/7 | R$ 695 · 2/2 | R$ 724 · 6/6 | 15/15 |

"Não ofertado" quer dizer que o tipo de quarto não apareceu na busca.

## O que os dados mostram
1. **Hidro está escasso no Réveillon.** Para completar a lista, a Booking incluiu 15 de 50 resultados sem hidro em 30/12 e 12 de 50 em 01/01. Nas datas de janeiro, isso não aconteceu.
2. **Os melhores concorrentes não vendem 2 noites no Réveillon, só pacote longo.** DOM, Casa Redonda, Chateau, Lavandas, Della Nonna, Secreto e Apple estão fora em 30/12→01/01 e em 01→03/01, mas vendem 29/12→02/01. Concluo que exigem estadia mínima, perto de 4 noites. É inferência minha.
3. **Recanto no Réveillon:**
   - R$ 2.901 por noite: p91 do mercado com hidro, 1,73× a mediana.
   - Fica abaixo de L.A.H. (R$ 3.225 por noite) e Estoril (R$ 3.220). Fica acima de Murano, Carballo e Vila Grega.
   - Só 7 de 16 suítes abertas na Booking.
   - Não aparece para 29/12→02/01, então não disputa o pacote de 4 noites.
4. **Villa no Réveillon e no Ano-Novo:**
   - O preço público vem do plano 432138662 e fica em 49% a 64% do plano 419782010, fixo em R$ 6.175.
   - Exemplos: Balcony a R$ 3.328,73 contra R$ 6.175,00 em 30/12. Balcony a R$ 2.995,12 contra R$ 6.175,00 em 01/01. VK7 a R$ 3.711,21 contra R$ 6.175,00 em 01/01.
   - Em 30/12 só 2 de 15 abertas e a VK7 não aparece. Esse dado pode estar distorcido pelos lotes suspeitos. Trate como duvidoso.
5. **Villa com preços invertidos.** Nas terças 05/01, 12/01 e 26/01, uma suíte com hidro (VK2 ou VK7) sai mais barata que a Balcony, que não tem banheira:
   - 05/01: VK2 R$ 677,60, Balcony R$ 785,14.
   - 12/01: VK7 R$ 753,97, Balcony R$ 836,10.
   - 26/01: VK2 R$ 695,18, Balcony R$ 724,33.
6. **Villa nos fins de semana de janeiro:** p78 a p84 do mercado com hidro, 1,49× a 1,73× a mediana. Fica acima do Recanto nos três fins de semana.
   - Nesses dias, o preço público é o da Balcony, sem banheira.
   - A nota da Villa é 8,3 (229 avaliações). Os comparáveis têm 9,2 a 9,7.
7. **Recanto nas terças:** p78 a p82, 1,8× a 1,9× a mediana. Os comparáveis cobram entre R$ 319 e R$ 934 por noite.
8. **Recanto nos fins de semana:** p71 a p84 no mercado todo. Contra o grupo boutique (nota ≥ 9), fica entre p46 e p62.
9. **Comparáveis com preço parado.** O DOM cobra R$ 1.228,50 nos três fins de semana. A Casa Redonda cobra R$ 1.080,00 nos fins de semana e R$ 360,00 nas terças.
10. **Preço de vitrine.**
    - Recanto: o preço da busca é sempre 0,8824 × a menor tarifa do room_search, nas 8 datas.
    - Villa: o preço da busca é igual à menor tarifa do room_search.
    - O conector não devolve a nota do Recanto.

## Scripts (somente leitura, na pasta de rascunho)
`/tmp/claude-0/-home-user-andrefelippeadfarias/02bd5933-6de8-57dc-9e2b-aeb0b12a4913/scratchpad/jan27scripts/an.py`, `strict.py` e `tab.py`.

### regras
# Dez-jan: o que já está decidido e quais alavancas sobram

Leitura feita em 27/11/2026. Não gravei nada. Não chamei o PriceLabs nem o Beds24. Na Booking fiz duas buscas só de leitura, pelo conector oficial.

**Fontes e códigos usados nas citações**
- [REG] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/references/regras.md (L = linha)
- [EST] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/references/estrategia.md
- [ORD] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/ordens_dono.json (id da ordem)
- [PEN] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/pendencias.json (id)
- [APR] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/references/aprendizados.md (L)
- [QJ] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/quartos.json (campo)
- [MET] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/metas_faturamento.json
- [SAZ] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/sazonalidade.json
- [PAC] /home/user/andrefelippeadfarias/pricelabs/estrategia-dez-jan/dados.json (gerado em 27/11 13:15Z)
- [REV] /home/user/andrefelippeadfarias/pricelabs/reveillon-2026-meta-250k.md
- [REVE] /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/reveillon_250k.json
- [EDZ] /home/user/andrefelippeadfarias/pricelabs/estrategia-dezembro-2026.md
- [LOG] /home/user/andrefelippeadfarias/pricelabs/registro-de-mudancas.md
- [BK] busca na Booking de 27/11, Villa id 14824789, 2 adultos

## Resumo
- **Quase todo o preço de dez-jan já tem decisão do dono.** Valem a régua de suítes comparáveis com reposição (19/11), o plano de 09/11, o Réveillon 250k (16/11), os pacotes (03/11), a estadia pelo dia de chegada (04/11), os sábados fechados (02/11) e a venda das janelas (16/11).
- **A skill sozinha só tem alavancas de execução:**
  - motor, escada, descida a 7 dias e coerência entre quartos;
  - Regra G e regra dos 80% da Balcony;
  - marcos do Réveillon, órfãs e janelas;
  - recalibrar os fatores e refazer a régua.
- **As alavancas grandes são do dono:** promoções e Genius, Natal flexível, piso de dia de semana, escada só em noite no ritmo, Natal da Villa direto ao mercado e janeiro da Villa.
- **Três travas param a execução hoje:**
  - o Beds24 está sem credencial desde 24/11;
  - os lotes suspeitos tiram noites da Villa do motor;
  - a promoção da Villa segura o portão do 01→03.
- **O buraco é o Recanto:** sem os lotes, vendeu 17,3% de dezembro e 6,9% de janeiro.

## 0. Retrato de dez-jan em números [PAC]
Fonte: campos `vendidas_reservas`, `vendidas_lote_suspeito` e `receita_noite` somados por noite. O campo `vendidas_reservas` já inclui o lote. Em toda noite as vendidas são iguais ou maiores que o lote; na King Spa 7, em 12/12, somam 9 para 7 unidades.

| | Diárias vendidas / capacidade | Do lote suspeito | Sem lote | Receita da noite |
|---|---|---|---|---|
| Recanto, dezembro | 94 / 496 (19,0%) | 8 | 17,3% | R$ 57.743 |
| Villa, dezembro | 162 / 465 (34,8%) | 37 | 26,9% | R$ 113.303 |
| Villa com banheira, dezembro | 140 / 279 (50,2%) | 37 | 36,9% | — |
| Recanto, janeiro | 37 / 496 (7,5%) | 3 | 6,9% | R$ 26.054 |
| Villa, janeiro | 43 / 465 (9,2%) | 3 | 8,6% | R$ 31.505 |

**Blocos de festa [PAC]:**
- Natal 23-24/12: 12 de 62, com 2 do lote. O Recanto tem 1 de 16 por noite, só na Panorâmica I.
- 25-26/12: 3 de 62.
- Entre festas 27-29/12: 9 de 93.
- Réveillon 30/12 a 02/01: 47 de 124, com 6 do lote (King Spa 7, 3 em 30/12 e 3 em 31/12). Receita da noite R$ 48.406.

**Metas [MET `por_pousada`]:**
- Dezembro: mínimo do dono R$ 350.000 no Recanto e R$ 300.000 na Villa. Realista R$ 265.465 e R$ 257.064.
- Janeiro: mínimo do dono igual. Realista R$ 205.581 e R$ 192.139.

**Marco do Réveillon:**
- Marco de 30/11 [REV §8]: alvo R$ 66,4 mil e 47 diárias; linha de alarme R$ 59,6 mil e 46 diárias.
- Sem o lote, são 41 diárias, abaixo da linha. A regra diz: "Abaixo da linha de alarme dos marcos [...] a escada para" [REG L337].
- A receita do [PAC] é a do PriceLabs, não o valor pago do Beds24 que o [REV] usa. A comparação é aproximada.
- O estado do Réveillon parou em 17/11 [REVE `atualizado_em`]: 0 pacotes novos, 0 degraus, `promocao_villa.ativa` true, portão do Recanto aberto, portão da Villa fechado.

## 1. O que já está decidido (vale sem perguntar)

| Tema | O que vale em dez-jan | Citação |
|---|---|---|
| Precedência | 1) limites e proibições; 2) pisos e ordens do dono; 3) motor de pace; 4) coerência entre quartos; 5) cadência e validador | [REG L9] "Precedência (lista única, 30/10): 1. limites de segurança e proibições [...] 2. pisos e ordens do dono com validade [...] 3. motor de pace" |
| Proibições | Descontos de OTA, ofertas da Booking, Genius acima do 3, sábado sozinho, limites de segurança | [REG L12] "Mexer em descontos de OTA (30–50%), em ofertas da Booking (todas obrigatórias) ou no Genius acima do nível 3." |
| Forma de gravar | Tudo em percentual (`percent_base`). Base, mínimo e máximo do anúncio só com ordem expressa | [REG L39] "mudar base, mínimo ou máximo só com ordem expressa do dono" (vence a autonomia de ±15% de [REG L21]) |
| Piso de dia de semana | R$ 500 no Genius 3 nas 6 suítes com banheira = R$ 1.220 enviados no Recanto e R$ 943 na Villa. **No plano 10/11-28/02, só de D8 em diante; de D7 a D0, mediana com banheira** | [REG L43] "piso enviado = R$ 500 ÷ `parametros.fator_vitrine_semana` [...] Recanto 0,41 → R$ 1.220; Villa 0,53 → R$ 943"; [REG L323] "o piso do dono de R$ 500 no Genius 3 vale de D8 em diante. De D7 a D0 [...] o piso passa à mediana do mercado com hidro" |
| Piso de fim de semana | R$ 850 no Genius 3 (R$ 2.000 / R$ 1.478 enviados). **Suspenso no plano até 28/02;** fica o padrão de R$ 1.000 enviados | [REG L49]; [REG L322] "no período, o piso de R$ 850 no Genius 3 de sexta e sábado sai; fica o padrão de qualidade (R$ 1.000 enviados)" |
| Feriado | Nunca abaixo de R$ 800 líquidos | [QJ `limites_seguranca.piso_feriado_real_seguranca`] 800; [REG L170] |
| Motor de pace | Ligado. Âncora só de suítes comparáveis (banheira e café) | [REG L271] "Ligado em 31/10"; [REG L361] "Ignore todos os outros valores de suítes sem banheira e café da manhã" |
| Reposição dez-fev | De 01/12 a 28/02 o motor vai direto ao alvo da régua, uma vez por noite, e depois volta ao passo diário | [ORD `dono-2026-11-19-precificacao-dez-fev`] `reposicionar: true`; [REG L369] "Reposição de uma vez: de 01/12/2026 a 28/02/2027 o motor vai direto ao alvo da régua [...] Depois da reposição, o motor volta ao passo diário normal." Em [PAC], 298 regras por quarto-noite já citam "Reposição pela régua comparável" |
| Plano de 09/11 (fim de semana, feriado, festas do Recanto) | Abre na mediana; escada de +7% só depois de metade vendida; −10% de D7 a D0 abaixo de 60%; Afrodite 15% acima da Panorâmica I; festas da Villa "ficam como estão" | [REG L316] "Escada: +7% por unidade só depois de metade das unidades da suíte vendidas"; [REG L317] "de D7 a D0, suíte com menos de 60% vendida na noite: −10%"; [REG L321] |
| Escada de vendas | Permanente: o percentil sobe com a ocupação da noite | [REG L446] ordem `dono-2026-11-01-subir-com-reservas`, "permanente" |
| Faixas do dono | F2 nos dias de semana (gatilho de D7 para F1 ou piso); F3 nos fins de semana de dezembro; F5 no Natal e no Réveillon, com descida gradual. O plano de 09/11 substitui a F5 e a F3 nas noites dele | [ORD `dono-2026-11-04-faixa-*`] (ex.: `faixa-recanto-20261208`: `faixa` 2, `percentil` 50); [REG L316] "Substitui, nessas noites, as faixas F5/F3 da ordem de 04/11" |
| King Spa no Natal fora da F5 | Natal da Villa (23-26/12) sai da faixa fixa e desce aos poucos pela régua comparável | [ORD `dono-2026-11-19-pode-fazer-tudo`] "(3) King Spa no Natal (F5, p76-p81 da régua): sai da F5 fixa e passa à régua comparável pelo motor de pace (desce aos poucos)"; `noites_fora` em `faixa-villa-20261224` |
| Villa pré-Natal e 27-28/12 | King Spa perto da mediana com banheira, com escada | [ORD `dono-2026-11-01-villa-pre-natal-mediana`] 21-23/12 e [ORD `dono-2026-11-01-villa-27-28-mediana`]; [REG L442] |
| Gatilhos das festas | Sobram 27, 28 e 29/12 (as outras noites passaram às faixas). Mais de 5 pontos atrás do mercado em 2 rotinas completas seguidas: desce um degrau até o piso de feriado | [ORD `dono-2026-11-01-gatilhos-festas`] `noites_fora` = 24-26/12 e 30/12-01/01; [REG L441] |
| Balcony | Regra G (85,5% a 90% da suíte com banheira livre mais barata; nunca abaixo de R$ 630) e regra dos 80% | [REG L324] "na noite com 80% ou mais das suítes com banheira da Villa vendidas (8 ou 9 de 9), a Balcony vai ao preço de mercado do quarto sem banheira [...] nunca acima da Regra G" (ex.: [PAC] Balcony 12/12, 8 de 9, R$ 1.041) |
| Ordem do Recanto | Moinhos ≤ Panorâmica II ≤ Panorâmica I < Afrodite (+20% de domingo a quinta; +5% em sexta, sábado e feriado) | [REG L138], [REG L150-152] |
| Estadia pelo dia de chegada | Domingo, terça e quinta: 1 noite. Segunda, quarta e sexta: 2. Sábado: 2 e fechado | [REG L85-88]; [SAZ]: Natal 24-26/12 com 2, Réveillon 29/12-02/01 com 3, Aniversário de SP 22-24/01 com 2 |
| Sábados | Fechados para entrada e saída. Exceção: 26/12 aberto | [REG L70] "Todo sábado fica `noCheckInOrCheckOut`"; [ORD `dono-2026-11-02-festas-sabado-aberto`] (26/12) |
| Pacotes | Natal só 23→25. Ano Novo 30→01 e 30→03, mais o 01→03 pelo portão (01→04 e 01→05 aceitos) | [ORD `dono-2026-11-03-festas-pacotes`] "Natal de 23 a 25 [...] de 30 a 01 ou de 30 a 03"; [REG L342] "Pode de 1 a 4 e de 1 a 5, sem problema." |
| Réveillon 250k | Recanto no pedido de 09/11 (Moinhos R$ 4.974; Panorâmica II 4.977; Panorâmica I 4.988; Afrodite 6.700). Escada de +3% (Réveillon) e +2% (fim de semana) por pacote novo. Descida de 5% nos marcos. Marco de 21/12 (+5% com 10 de 12). Máximo de R$ 6.000 só quando precisar. Fim de semana da Villa só sem a promoção | [REG L335-L341]; [ORD `dono-2026-11-16-reveillon-250k`] `descida.linhas`: Réveillon 23/12 (<6), 25/12 (<7), 27/12 (<8), 29/12 (<9); fim de semana 25/12 (<9), 27/12 (<10), 29/12 (<12), 31/12 (<14) |
| Noites soltas do Réveillon | Exceção 1: saída em 31/12 (29→31) só no quarto com 31/12 esgotado. Exceção 2: entrada no sábado 02/01 (02→04) só no quarto com 01/01 esgotado | [REG L343] |
| Noite órfã | 1 noite quando a seguinte está esgotada, também em feriado e véspera; nunca em pacote | [REG L96] "nunca em noite de pacote (Natal 23→25/12, Ano Novo 30/12→01 e 30/12→03/01)" |
| Vender as janelas | Abrir o mínimo de restrição, sem mexer em preço | [REG L415] "Na verdade só venda onde tem uma janela"; [ORD `dono-2026-11-16-vender-janelas`] até 16/11/2027 |
| Regra I | De D0 a D3, nunca abaixo dos pisos. Dominada pelos pisos e, no plano, pela mediana de D7 a D0 | [REG L76-80] "a Regra I não desce abaixo de R$ 794 enviados no Recanto e R$ 943 na Villa" |
| Mínimo do anúncio ao piso | Passos de 09, 16 e 23/11 feitos; falta Moinhos de R$ 1.216 para R$ 1.220 em 30/11. Vence em 07/12 | [ORD `dono-2026-11-09-minimo-piso-semana`] `passos.2026-11-30` {"Q7": 1220}; [LOG 23/11] "R$ 1.216; R$ 1.220; R$ 1.220" |
| Expedia da Villa | Fechada (limite 0) até o dono reabrir | [ORD `dono-2026-11-18-expedia-villa-fechada`] |
| Aniversário de SP | Gatilho em 01/01: com 22-24/01 abaixo de 20% vendido na pousada, o domingo 24/01 sai do feriado | [ORD `dono-2026-11-02-aniv-sp-gatilho`]; [REG L439] |

## 2. O que a skill pode fazer sozinha
1. **Rodar a reposição de dez-fev uma vez por noite e depois o passo diário.** Inclui escada, descida a 7 dias, proteção da noite esgotada, coerência, Regra G e regra dos 80%, nas 60 noites à frente [REG L369, L278].
2. **Seguir a descida gradual do Natal da Villa** pela régua comparável [ORD `dono-2026-11-19-pode-fazer-tudo`]. Em 27/11 foi gravada a King Spa 7 de 23/12 a R$ 3.730 (antes R$ 4.070) e de 24-26/12 a R$ 3.863 (antes R$ 4.292) [LOG 27/11]. Ir direto ao mercado é pergunta ao dono (seção 4).
3. **Executar o Réveillon pelo `reveillon_250k.py`:** escada, degraus nos marcos, marco de 21/12, noites soltas e o máximo de R$ 6.000 só quando o preço planejado passar de R$ 5.000 [ORD `dono-2026-11-16-reveillon-250k` → `execucao_skill.maximo_anuncio`: "gravar_a_partir_de": "2026-11-23"]. Tudo isso depende da leitura do Beds24 (seção 4).
4. **Aplicar o gatilho das festas em 27-29/12** e o gatilho do Aniversário de SP em 01/01 [PEN `aniv-sp-gatilho-0101`].
5. **Estadia:** Regra A, órfãs, janelas e a auditoria de 12 meses [REG L99].
6. **Passo 4 do mínimo do anúncio** (Moinhos para R$ 1.220 em 30/11), dentro da ordem que vence em 07/12.
7. **Recalibrar os fatores na revisão de segunda (30/11)** [EST L88]:
   - `fator_publico` ainda é 0,63 no Recanto e 0,65 na Villa [QJ `parametros.fator_publico`, medido em 29/10];
   - em 23/11 a Booking mediu 0,585 no Recanto e 0,637 nos fins de semana da Villa [LOG 23/11, "Fator público"];
   - pendência da skill: [PEN `fator-publico-recanto-0583-20261118`] "Com 0,63 o piso de D7 do motor cai no p42 e não na mediana aprovada em 09/11."
8. **Refazer a régua:**
   - régua do plano (atrasada, alvo 24/11): [PEN `plano-dono-regua-refazer`];
   - faixas de fevereiro e do Carnaval até 07/12: [PEN `faixas-remedir`];
   - revisão dos 12 meses em 01/12: [LOG 23/11] "próxima em 01/12".
9. **Exceção de mercado do padrão de qualidade** (até 21 dias, com dado) e piso de feriado pelos dados, nunca abaixo de R$ 800 líquidos [REG L59-63, L170].

## 3. O que só o dono decide
- **Mínimo, base e máximo do anúncio** [REG L39]. Inclui passar de R$ 6.000 no Réveillon e subir o máximo para a F5 [PEN `maximo-anuncio-festas`, alvo 01/12]. Essa pendência ficou em parte superada: o Natal saiu da F5 na Villa e segue o plano no Recanto.
- **Limites de segurança:** R$ 500 e R$ 850 no Genius 3, R$ 1.000 enviados, R$ 800 líquidos e 70% do mínimo [REG L14; QJ `limites_seguranca`: "Só o dono muda estes limites"].
- **Promoções, Genius e descontos de OTA** [REG L12; REG L405]: "Eu mesmo vou fazer então manualmente". Isso inclui:
  - tirar a promoção da Villa em 30/12-02/01, que é condição do portão [REG L342, condição 3];
  - a promoção da Villa no Natal [EDZ §5, pergunta 12];
  - a promoção de dia de semana medida em 27/11 (seção 4);
  - o desconto do Recanto: [REV §9 item 4] "conferir por que o fator caiu de 0,6156 para 0,5832".
- **Mudanças de regra pedidas e sem resposta:**
  - escada só em noite no ritmo ou à frente [PEN `escada-nao-sobe-atras-20261123`];
  - Natal flexível 24→26 e 23→26 [EDZ §5, pergunta 1];
  - 29/12 com máxima 3 [EDZ, pergunta 5];
  - gatilho de festas em 20-22/12 [EDZ, pergunta 4];
  - dia de semana do Recanto, opção A ou B [EDZ, pergunta 6];
  - janeiro da Villa [PEN `villa-janeiro-atras-20261116`]: "(a) estadia de 1 noite também às segundas e quartas de janeiro na Villa [...] (c) piso de dia de semana de janeiro de R$ 500 para R$ 450";
  - leitura dos 80% da Balcony [PEN `balcony-80-interpretacao`];
  - teste de flutuação da King Spa 7: só se estende com novo "sim" [ORD `dono-2026-11-09-sombra-flutuacao-vk7`, vence 07/12].
- **Canais e marketing:**
  - Visibility Booster: [EDZ, pergunta 11] "É exceção ao plano de 12 meses";
  - ondas para a base de clientes, anúncio com clique para o WhatsApp e tabela do direto [EDZ, perguntas 7 a 9];
  - Balcony do Natal só no direto [EDZ, pergunta 10];
  - reabrir a Expedia da Villa.
- **Afrodite presa no piso:** 21 noites presas pela regra "acima da mais cara das outras" [APR L595]. Mudar esse prêmio (+20% / +5%) é ordem do dono [REG L150-152].

## 4. Pendências abertas que afetam dez-jan

| Pendência [PEN id] | Responsável | Efeito em dez-jan | Citação |
|---|---|---|---|
| `escada-nao-sobe-atras-20261123` | dono | A escada sobe noites "atrás" sem trazer venda | "Pergunta: 'Posso deixar a escada subir só noite no ritmo ou à frente (em noite atrás, segura o preço)?' Mudança de regra: só com o sim do dono."; [APR L159] "subidas: 0 venda por canal em 2-6 dias; descidas: 2 vendas em 2-3 dias" |
| `villa-booking-razao-0436-20261123` | dono | Promoção provável de dia de semana na Villa: público bem abaixo do enviado | "razão 0,436 (alerta abaixo de 0,42; promoção ativa abaixo de 0,55)"; atualização: "30/11→02/12 [...] = 0,383". **Nova medição [BK 27/11], terça 08→09/12, 1 noite, café incluído, contra o enviado do [PAC]:** King Spa 7 R$ 697,52 / R$ 764,47 / R$ 876,51 sobre R$ 1.376 = 0,507 / 0,556 / 0,637; King Spa 2 R$ 606,42 / R$ 716,92 / R$ 930,02 sobre R$ 1.460 = 0,415 / 0,491 / 0,637; Balcony R$ 550,37 / R$ 669,37 / R$ 784,78 sobre R$ 1.232 = 0,447 / 0,543 / 0,637. **Fim de semana 11→13/12 (só a Balcony à venda):** R$ 1.965,78 / R$ 2.005,90 / R$ 3.214,71 sobre R$ 3.086 = 0,637 / 0,650 / 1,04. A tarifa da Booking de código (bloco) 432138662 sai a 0,447 do enviado na Balcony na terça e a 0,650 no fim de semana; a de código 445484962 fica em 0,637 nos dois. Leitura: desconto de dia de semana em uma tarifa. Só o dono confere na extranet |
| `booking-lote-16-reservas-20261117`, `booking-lote-3-reservas-20261121`, `villa-itens-lote-segurados-20261118` | dono / skill | As noites dos lotes ficam fora do motor até a confirmação. Houve um 4º lote em 24/11 | "Até lá, nenhuma gravação de preço nessas noites."; "as noites da Villa de 02 a 14/01/2027 ficam fora do plano do motor"; [LOG 23/11] "38 itens do motor [...] esperam a confirmação do dono"; [LOG 27/11] "4º lote suspeito da Booking (4 reservas no mesmo segundo, 24/11 09:13 BRT, comissão 0): [...] King Spa 7 07→09/12 [...] 16/12 [...] 30/12→01/01". Em [PAC], 48 diárias de dez-jan estão marcadas como lote. Também no Recanto (Moinhos 04-07/12, Afrodite 12-14/01) |
| `beds24-credencial-perdida-20261124` | dono | Sem inventário não há prova de órfã nem auditoria do Beds24, nem contagem de pacotes do Réveillon (os marcos começam em 23/12), nem conferência do price1 do portão ou do padrão dos lotes | "a API devolve 401. Sem ela, as rotinas não veem reservas, cancelamentos, inventário nem alertas de segurança, e não editam estadia"; "o cartão de credencial [...] foi recusado ou não pôde ser mostrado; não pedir de novo"; [REG L97] "A prova do item sai do Beds24"; [REG L337] "leitura incompleta é recusada". Junto: [PEN `plano-20261124-por-gravar`] |
| `villa-0103-aberto-booking-20261116`, `reveillon-250k-decisoes-20261116` | dono | Portão do 01→03 da Villa fechado; fim de semana da Villa preso no preço de antes | "o 01→03/01 aparece à venda na Booking por R$ 3.027,38 (R$ 1.514 por noite; razão 0,338)"; "Seguem com o dono: [...] (3) tirar a promoção da Villa, (4) desconto do Recanto"; [REVE] `promocao_villa` {"ativa": true}. Faltam confirmar os itens (a), divisão da descida por pousada, e (c), marco de 21/12 |
| `villa-natal-reposicao-comparavel-20261119` | dono | O Natal da Villa desce 5% por dia | "A régua comparável levaria de uma vez a cerca de R$ 2.640-3.380 enviados (−32% a −46%). Perguntado ao dono: manter a descida gradual ou ir direto ao mercado?"; [APR L160] "0 venda em 4 dias" |
| `estrategia-dezembro-perguntas-20261117` | dono | 12 perguntas Sim/Não sem resposta | "12 perguntas Sim/Não na seção 5 [...] Nada gravado por ela." |
| `villa-janeiro-atras-20261116` | dono | Janeiro da Villa atrás do mercado | "atrás do mercado em duas revisões seguidas (01/11: 3% × 7%; 16/11: 6% × 8% vendido)" |
| `balcony-80-interpretacao` | dono | A leitura dos 80% já define preço | "a leitura já define preço real (Balcony a R$ 686 em 23/11, 24/11 e 06/12)" |
| `fator-publico-recanto-0583-20261118` | skill | O alvo do motor no Recanto fica cerca de 7% abaixo do que se pediu | ver seção 2, item 7 |
| `minimo-piso-semana-passos`, `sombra-flutuacao-teste-vk7` | skill | As duas ordens vencem em 07/12 | ver seções 1 e 3 |
| `vendas-abaixo-recepcao-expedia-20261117`, `expedia-balcony-20261204-r292` | dono | Natal da King Spa 7 vendido a 6% e 17% do esperado | "King Spa 7 Natal 23→25/12 por R$ 545 (6% do esperado [...])"; a Expedia da Villa foi fechada em 18/11 |
| `booking-invadida-2026-11-03`, `recanto-natal-invisivel-booking`, `booking-listagem-duplicada-recanto` | dono | Segurança da conta e visibilidade do Recanto no Natal | "falta conferir se o Recanto aparece em 23→25" |
| `pricelabs-cobranca-20261116` | dono | Segue aberta no arquivo, mas o envio voltou | [LOG 27/11] "Envio: diário saindo (26/11 07:47; Afrodite 27/11 00:29)" |

## 5. Pontos de atenção nos dados
- **O enviado do [PAC] é anterior à gravação da Villa de 27/11.** O [PAC] mostra a King Spa 7 do Natal a R$ 4.070-4.292. O [LOG 27/11] registra R$ 3.730-3.863 gravados, ainda sem chegar à Booking. A busca [BK] bate com o [PAC]: a tarifa de 0,637 é exata nos 3 quartos.
- **A receita do [PAC] não é a do Beds24.** É o `receita_noite` do PriceLabs, com os lotes incluídos. Não serve para fechar o marco do Réveillon nem a meta sem esse aviso.
- **A Moinhos não tem ocupação do PriceLabs no [PAC]** (`ocupacao_pricelabs` vazio). Só valem as vendidas das reservas.
- **O que a evidência recente diz sobre dias de semana:**
  - em dez-jan, fora das festas, os dias de semana do Recanto já estão acima do piso: Moinhos de R$ 1.267 a R$ 1.677, mediana R$ 1.654 [PAC];
  - a Moinhos vendeu 15 de 252 diárias nesses dias;
  - [APR L157] diz que no domingo e na segunda depois do feriado "não vendem nem no p25", e [APR L582] diz que "domingo depois do feriado é outro mercado";
  - [APR L153] mostra que o preço de piso "funcionou em volume [...] com diária baixa".


### demanda
# Demanda e canais em dez-jan: o que os números mostram

**O que usei.** Usei os arquivos do pacote e 6 buscas da Booking pelo conector oficial em 27/11, só de leitura. Não chamei PriceLabs nem Beds24. Não há dados de hóspedes.

**Cuidados antes de ler:**
- A ocupação "sem lote" exclui `vendidas_lote_suspeito`.
- No `dados.json`, a receita do Double parece subcontada. Em 18-19/12 dá R$ 113 por diária, em 01-02/01 R$ 169 e em 31/12 R$ 850 por 3 diárias. Por isso, a receita do Recanto no pacote é um piso.

## Os 12 achados

### 1. O Natal é o maior buraco, e quem trava é a janela de estadia, não o preço
- **O que está vendido:**
  - Noites 23-24/12: Recanto 2 de 32 (6%); Villa 8 de 30 sem lote (27%).
  - Noites 25-26/12: Recanto 2 de 32; Villa 0 sem lote.
  - Moinhos, Afrodite e Panorâmica II: 0 vendas de 23 a 26/12.
  - Fonte: `dados.json`.
- **A régua manda 35% a D-30** (`regras.md` §3). Hoje estamos a D26-D28.
- **O mercado em 2025:** 51-54% (STR) e 70% (Prefeitura). Fonte: `sazonalidade.json`, período Natal 24-26/12.
- **Histórico próprio de 2024:** Recanto 69% a R$ 712; Villa 53% a R$ 596 (`historico_proprio.json`).
- **Booking, 27/11:**
  - A estadia clássica 24→26 não aparece em nenhuma das duas. A Villa volta "no availability". O Recanto não sai na busca por coordenadas.
  - Ao mesmo tempo, os 50 resultados com jacuzzi e café seguem à venda. Mediana de R$ 1.808 pelas 2 noites, no menor quarto de cada hotel.
- **Por quê:** a ordem `dono-2026-11-03-festas-pacotes` só permite o Natal 23→25.
- **Ação (decisão do dono):** abrir um segundo pacote, 24→26 ou 25→27. A ordem `dono-2026-11-02-festas-sabado-aberto` já deixa o sábado 26/12 aberto. A janela típica do Natal é de 30 dias (`sazonalidade.json`), então a venda é agora.

### 2. Réveillon: o 30-31/12 vende, o 01-02/01 não; estamos abaixo da linha de alarme de 30/11
- **30-31/12:** Recanto 41%; Villa 70% (50% sem lote).
- **01-02/01:** Recanto 25%; Villa 17%.
- **Totais nas 4 noites:** 47 diárias (41 sem lote) e cerca de R$ 48,4 mil (`dados.json`).
- **Marco de 30/11** (`reveillon-2026-meta-250k.md` §8):
  - Alvo: R$ 66,4 mil e 47 diárias.
  - Alarme: R$ 59,6 mil e 46 diárias.
  - Ressalva: o marco usa o valor pago do Beds24, e a receita do Double está subcontada. A comparação é aproximada.
- **No Recanto, quem vende é o quarto mais barato:**
  - Moinhos 4 de 7 em 30/12 e 5 de 7 em 31/12.
  - Afrodite 0, e Panorâmica I 0 em 30/12.
- **O sábado 02/01 é o ponto fraco:** mercado a 24,9%, contra 14,9% um ano antes (mesmo arquivo, §4).
- **A Villa segue vendendo o 01→03 sozinho:** R$ 2.995,12, ou R$ 1.498 por noite. A razão é cerca de 0,33 sobre a Balcony enviada (R$ 4.483). O vazamento é o mesmo de 16/11 (Booking, 27/11).
- **Ação:**
  - Manter o preço até D-10 (o mercado de Réveillon reserva com 60 dias).
  - Se sobrar vaga em D-7, descer para "alto", nunca para "médio" (`sazonalidade.json`, notas).
  - O dono fecha o 01→03 da Villa na extranet antes de qualquer outra ação.

### 3. A venda é de última hora: cada fim de semana fecha numa janela de 2 a 4 semanas
- **Antecedência histórica:**
  - 2024: mediana de 3 dias; 52% com até 3 dias; 74% com até 14 dias.
  - 1º trimestre de 2026 (inclui janeiro): mediana de 4 dias; 49% com até 3 dias; 79% com até 14 dias; só 2,4% com mais de 60 dias.
  - Fonte: `historico_proprio.json`.
- **Novembro:** antecedência média de 11,6 dias no Recanto e 23,0 na Villa. Fonte: `2026-11_ate_2026-11-23.md`. A média da Villa é inflada pelos lotes (ex.: King Spa 2 de 02→15/01, pendência `booking-lote-3-reservas-20261121`).
- **Fins de semana, sem lote:**

| Fim de semana | Antecedência | Recanto | Villa |
|---|---|---|---|
| 04-05/12 | D7 | 69% | 57% |
| 11-12/12 | D14 | 25% | 57% |
| 18-19/12 | D21 | 31% | 3% |

  Fonte: `dados.json`.
- **Ação:**
  - A ocupação de janeiro hoje (Recanto 7,5%, Villa 9,2%) está dentro do padrão. Não cortar preço antes de D-14.
  - De D-14 a D0, concentrar o esforço: Regra I e janelas.
  - 18-19/12 é o próximo fim de semana a entrar na janela. A Villa está a 3% e precisa de leitura diária.
  - O Recanto em 11-12/12 está a metade da régua de D14 (50%).

### 4. Em dias de semana, preço baixo não traz volume, salvo em véspera de feriado
- **Primeira quinzena de dezembro, domingo a quinta:** Recanto 10,6% sem lote; Villa 24,1% sem lote. Os fins de semana estão em 47% e 57% (`dados.json`).
- **Mercado no mesmo período de 2025:** 18-28% em dia de semana e 50-55% no fim de semana (`sazonalidade.json`).
- **O que já testamos** (`aprendizados.md`, tabela "Efeito das mudanças"):
  - A quinta 19/11 a preço de piso fez 15 vendas, 14 delas de 1 noite a R$ 331-507. Mas era véspera de feriado.
  - As janelas de 1 diária em dias de semana e em dezembro tiveram 0 venda (16-21/11).
- **Ação:**
  - Nos dias de semana de dezembro e janeiro, não usar a descida de preço como alavanca principal.
  - Só Regra I em D0-D1.
  - Pacotes de domingo a quinta no direto, com a diária da tabela (pendência `pacotes-dias-uteis`).

### 5. A noite depois do feriado não vende nem no p25
- **Teste de 22-23/11:**
  - Domingo: 1 venda por canal em cerca de 40 h.
  - Segunda: 0 vendas.
  - Também não vendeu a R$ 751-770 enviados.
  - Fonte: `aprendizados.md`, efeito das mudanças.
- **O mercado muda de patamar:** o quarto com banheira foi de p50 R$ 1.260 no sábado para p50 R$ 469 no domingo (`aprendizados.md`, 20/11).
- **As noites equivalentes de dez-jan:**
  - 27/12: Recanto 1 vendida de 16; Villa 1 de 15.
  - 03/01: Recanto 2; Villa 2.
  - 04/01: Recanto 3; Villa 3.
  - Fonte: `dados.json`.
- **Ação:**
  - Não gastar descida nessas noites esperando hóspede novo.
  - Oferecer "mais uma noite" a quem já está hospedado, com o mínimo do dono. Precedente: ordem `dono-2026-11-20-extensao-domingo-1000`.
  - Medir 27/12 e 03/01 pela busca da própria noite, não pela régua do feriado.

### 6. No feriado, as suítes premium aceitam preço alto; a Moinhos só vende no dia
- **Feriadão de 20-21/11 a R$ 3.000 ou mais enviados:**
  - 7 vendas em 3 dias.
  - Panorâmicas e King Spa a R$ 1.548-1.886 pagos por noite. Antes, a Panorâmica I vendia a R$ 984 (+78%).
  - Moinhos: 0 vendas aberta; esgotou só no dia, com −20% (R$ 2.195).
- **Sábado 21/11 com as suítes de banheira mais baratas:** 3 vendas em cerca de 16 h, e esgotou.
- Fonte: `aprendizados.md`, efeito das mudanças.
- **Ação:**
  - No Natal e no Réveillon, segurar preço na Afrodite, nas Panorâmicas e nas King Spa.
  - A Moinhos (7 unidades) é a última a vender. Planejar a descida dela para D0-D3, sem fechar o quarto.

### 7. Subir sem venda não vende; descer devagar, numa noite cara, também não
- **Escada de vendas:** as subidas de 17, 18 e 21/11 trouxeram 0 venda em 2-6 dias. As descidas a 7 dias trouxeram 2 vendas em 2-3 dias.
- **Natal da King Spa 7:** desceu de R$ 4.988 para R$ 4.070-4.292 e fez 0 venda em 4 dias. A vitrine estava cerca de 28% acima da mediana comparável.
- Fonte: `aprendizados.md`, efeito das mudanças e 23/11.
- **Ação:**
  - Aprovar a regra em que a escada só sobe noite "no ritmo" ou "à frente" (pendência `escada-nao-sobe-atras-20261123`).
  - Noite muito acima da régua: ir ao nível do mercado num passo, com aprovação do dono, ou segurar. Degraus de 5% não mudam nada.

### 8. A Booking é o funil; Airbnb e Expedia rendem muito menos por diária
Reservas feitas em novembro (`2026-11_ate_2026-11-23.md`):

| Canal | Recanto: parte da receita | Recanto: diária | Villa: parte da receita | Villa: diária |
|---|---|---|---|---|
| Booking | 66% | R$ 763 | 71% | R$ 613 |
| Direto lançado à mão | 15% | R$ 859 | 19% | R$ 566 |
| Airbnb | 11% | R$ 430 | 6% | R$ 342 |
| Expedia | 2% | R$ 524 | 4% | R$ 374 |

- **Airbnb:** repassa 31% (Villa) e 41% (Recanto) do enviado, contra 46-49% da Booking (pendência `airbnb-repasse`).
- **Expedia:**
  - Na Villa, vendia a 13-34% do esperado e está fechada (ordem `dono-2026-11-18-expedia-villa-fechada`).
  - No Recanto, vende a 41-46% (`aprendizados.md`, 19/11).
- **Ação:**
  - Nas festas, a Booking vem primeiro.
  - Airbnb e Expedia só com o multiplicador corrigido no Beds24.
  - Não reabrir a Expedia da Villa.

### 9. A recepção vende abaixo da tabela, justamente nas noites caras
- **Casos registrados:**
  - King Spa 7 no Natal 23→25 a R$ 861 por noite: 17% do pacote.
  - King Spa 7 no Réveillon a R$ 3.200, de R$ 9.976 esperados: 32%.
  - 22/11: Afrodite a R$ 450 e Panorâmica I a R$ 500, contra o mínimo de R$ 1.000 do dono.
  - Fonte: pendências `vendas-abaixo-recepcao-expedia-20261117`, `vk7-diretas-abaixo-20261110` e `dono-extensao-domingo-abaixo-1000-20261122`.
- **Em 2024, o site trouxe 34% da receita do Recanto** e cancelou 18%, contra 42% da Booking (`plano-12-meses.md` §1b).
- **Ação:** antes das festas, o dono passa à recepção uma tabela do direto com o preço do dia (o price1) e o mínimo. A venda direta deve ganhar com benefício (ceia, saída tardia), não com desconto.

### 10. O cancelamento come uma parte grande do vendido
- **Reservas feitas em novembro:** 20% foram canceladas (80 de 396).
- **Cancelamentos feitos em novembro:** 136, com R$ 117.386 de receita que saiu. Recanto R$ 69.923. 56 deles eram de reservas antigas.
- Fonte: `2026-11_ate_2026-11-23.md`.
- **Ação:**
  - Projetar as festas com cerca de 20% de folga.
  - Priorizar a tarifa não reembolsável e o pré-pagamento no Natal e no Réveillon (pendência `tarifa-nao-reembolsavel`).
  - Manter a proteção de noite esgotada que reabre.

### 11. A Villa vende mais quartos; o Recanto é o gargalo, e o freio dele é a conversão
- **Novembro:**
  - Villa: 209 reservas ativas e R$ 204.613, a R$ 562 de diária.
  - Recanto: 107 reservas e R$ 133.039, a R$ 711.
  - Mês de novembro: Recanto R$ 183.003 contra o mínimo de R$ 220.000; Villa R$ 153.538 contra R$ 150.000 (`2026-11_ate_2026-11-23.md`).
- **Dezembro já vendido:**
  - Villa: 162 quartos-noite (125 sem lote), R$ 113.303.
  - Recanto: 94 quartos-noite, R$ 57.743 (receita subcontada).
  - Fonte: `dados.json`.
- **O Recanto ficou parado em dezembro:** 94 quartos-noite em 16/11 (Beds24, `estrategia-dezembro-2026.md`) e 94 em 27/11 (PriceLabs). Saldo zero em 11 dias, com a ressalva de que as fontes são diferentes.
- **Na Booking, 27/11:**
  - O Recanto (7562933) aparece sem nota.
  - A Villa aparece com 8,3 e 229 avaliações.
  - A Villa não lista "Jacuzzi" nas comodidades.
- **Ação:** o esforço das festas vai para o Recanto. Primeiro a conversão: nota e anúncio duplicado (pendência `booking-listagem-duplicada-recanto`). O preço vem depois.

### 12. Na Villa, o preço público é definido por promoção, e os lotes distorcem a leitura
- **Booking, 27/11:** a Villa no 23→25/12 aparece a R$ 2.418,69, ou R$ 1.209 por noite.
  - Razão de 0,29 a 0,32 sobre os enviados de R$ 3.658-4.292.
  - O Recanto, na mesma busca, fica em 0,583 (R$ 3.269,42 com a Moinhos a R$ 2.803).
  - Ou seja, a promoção da Villa segue no Natal (pendência `plano-dono-regua-refazer`).
- **Lotes suspeitos:** 40 das 205 noites vendidas da Villa em dez-jan (20%) vêm de lote suspeito.
  - Dias de semana da primeira quinzena de dezembro: 22 de 69 (32%).
  - Réveillon 30-31/12: 6 de 21.
- **Ação:**
  - O dono tira a promoção da Villa no Natal e no Réveillon pela extranet.
  - Ler o ritmo da Villa sempre sem os lotes.
  - Não subir preço nas noites dos lotes até a recepção confirmar (`aprendizados.md`, 18/11).

## Como é a temporada em Campos do Jordão (`sazonalidade.json`)

| Período | Nível | Mercado no ano passado | Antecedência típica |
|---|---|---|---|
| 01-17/12, domingo a quinta | muito baixa | 18-28% | 7 dias |
| 01-17/12, sexta e sábado (Natal FLMA até 20/12) | média | 50-55% | 14 dias |
| 18-23/12 (Corrida Iluminada 19/12; férias da rede estadual a partir de 19/12) | média | 45-61% | 14 dias |
| Natal, 24-26/12 | alta | 51-54% (STR) / 70% (Prefeitura) | 30 dias |
| 27-28/12 | média | cerca de 53% | 20 dias |
| Réveillon, 29/12-02/01 | muito alta | 85-96% | 60 dias |
| 03-21/01, domingo a quinta | baixa | 30% (segunda a quinta) | 10 dias |
| 03-21/01, sexta e sábado | média | 40% | 14 dias |
| Aniversário de SP, 22-24/01 | média | — | 20 dias |
| 25-31/01 | muito baixa | — | 7 dias |

- **Por que janeiro é fraco:** compete com a praia e é o mês mais chuvoso (365 mm).
- **O nosso janeiro de 2026 ficou acima do mercado:** Recanto 58,9% a R$ 735; Villa 41,9% a R$ 608 (`historico_proprio.json`).
- **O mínimo do dono para janeiro está longe do realista:**
  - Recanto: R$ 350 mil, contra R$ 205.581 realista.
  - Villa: R$ 300 mil, contra R$ 192.139.
  - Fonte: `metas_faturamento.json` → `por_pousada`.

## Fontes
- `/home/user/andrefelippeadfarias/pricelabs/estrategia-dez-jan/dados.json` (campos `vendidas_reservas`, `vendidas_lote_suspeito`, `receita_noite`, `enviado`; gerado em 27/11 às 13:15Z).
- `/home/user/andrefelippeadfarias/pricelabs/desempenho/2026-11_ate_2026-11-23.md` e `2026-10_ate_2026-10-21.md`.
- `/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/references/aprendizados.md` (tabela "Efeito das mudanças nas vendas"; entradas de 01/11, 03/11, 10/11, 18-23/11).
- `/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/sazonalidade.json` (`periodos`, `mercado_mensal`, `notas_de_pesquisa`).
- `/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/historico_proprio.json`.
- `/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/metas_faturamento.json`.
- `/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/ordens_dono.json` (`festas-pacotes`, `sabados-fechados`, `festas-sabado-aberto`, `estadia-dia-chegada`, `expedia-villa-fechada`).
- `/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/dados/pendencias.json`.
- `/home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/references/plano-12-meses.md`.
- `/home/user/andrefelippeadfarias/pricelabs/reveillon-2026-meta-250k.md`.
- `/home/user/andrefelippeadfarias/pricelabs/estrategia-dezembro-2026.md`.
- Booking pelo conector oficial, 27/11/2026:
  - Recanto por coordenadas: 24→26/12 (não aparece) e 23→25/12 (R$ 3.269,42).
  - Villa por nome: 24→26/12 (sem disponibilidade), 23→25/12 (R$ 2.418,69) e 01→03/01 (R$ 2.995,12).
  - Campos do Jordão com jacuzzi e café, 24→26/12: 50 resultados, mediana de R$ 1.808 pelas 2 noites.

# Plano para atingir as metas de ocupação — Recanto dos Moinhos e Villa Dolce Amore

Criado em 30/09/2026, 12:18, a pedido do dono: "usar % ao invés do valor fixo, pois assim o PriceLabs consegue flutuar melhor os valores" e "atingir todos os nossos objetivos". Vale para as duas pousadas, no modo agressivo, até o hotel chegar a 50% nos próximos 15 dias ou o dono mandar parar.

Metas: 7 dias 70% (ideal 100%), 15 dias 50%, 30 dias 35%, 45 dias 25%, 60 dias 15%.

## 1. Onde estamos (30/09, 12h)

| Janela | Vendidas | Ocupação | Meta | Faltam |
|---|---|---|---|---|
| 7 dias | 41 de 217 | 19% | 70% | +111 diárias |
| 15 dias | 120 de 465 | 26% | 50% | +112 |
| 30 dias | 168 de 930 | 18% | 35% | +158 |
| 45 dias | 258 de 1.395 | 18% | 25% | +91 |
| 60 dias | 291 de 1.860 | 16% | 15% | atingida |

Capacidade: 31 unidades por noite (Recanto 16, Villa 15). O mercado está em 18–19% nos próximos 7 dias, ou seja, estamos no mesmo nível dele.

**Onde está o buraco de verdade** (ocupação por faixa de antecedência, contra o que as metas pedem em cada faixa):

| Faixa | Ocupação hoje | Meta implícita | Leitura |
|---|---|---|---|
| 0 a 6 dias | 19% | 70% | buraco principal, faltam 111 diárias |
| 7 a 14 dias | 32% | 33% | no alvo (puxada pelo feriado de 09 a 12/10) |
| 15 a 29 dias | 10% | 20% | atrasada, faltam cerca de 46 diárias |
| 30 a 44 dias | 19% | 5% | à frente |
| 45 a 59 dias | 7% | 0% | à frente |

As metas de 30 e 45 dias estão baixas só porque carregam o buraco dos 0 a 6 dias. Por isso o plano ataca duas faixas: a venda de última hora (0 a 6 dias) e o pré-enchimento de 15 a 29 dias.

**O que os dados mostram** (443 reservas criadas desde 09/09, todas lidas):
1. **Ritmo:** 130 diárias vendidas nos últimos 8 dias (cerca de 16 por dia), contra 182 na semana anterior.
2. **Antecedência:** 45% das diárias entram com 0 a 3 dias, 10% com 4 a 7, 7% com 8 a 14, 10% com 15 a 30, 16% com 31 a 60 e 11% com mais de 60. A última hora domina.
3. **Canais:** Booking 71% das diárias, outros (venda direta e afins) 20%, Airbnb 8%, Expedia 1%.
4. **Cancelamentos:** 157 das 443 reservas (35%) foram canceladas; nas vendas de 23 a 27/09 foram 16 de 67 (24%). Isso segura a ocupação e precisa ser investigado (lotes duplicados no Beds24 e política de cancelamento).
5. **Piso de fim de semana:** é 150% do preço base (Queen 2.070, Double e Villa King Spa 7 2.220, Villa King Spa 2 1.905). Ele fica acima do preço que o algoritmo recomenda em todos os fins de semana à frente. Exemplo: Queen (7) em 16 e 17/10, recomendado R$ 1.773 e enviado R$ 2.070, sem nenhuma venda.
6. **Suavização (Atenuação):** infla os dias úteis depois do feriado. Queen (7) em 14 e 15/10: o algoritmo chega a R$ 942 e a suavização soma R$ 506, indo a R$ 1.448.
7. **Ajuste por ocupação de vários quartos:** já corta de 15% a 25% enquanto o hotel está a 19%. Ele é uma personalização de tela e não muda por aqui.
8. **As reservas só chegam ao PriceLabs no Sync.** Às 10:07 e 10:08 o Beds24 criou 8 reservas da Booking de uma vez; a análise das 10:53 não as via. Elas apareceram depois do Sync das 12:42 (lista no registro de 30/09, 13:45).

## 2. O que muda: Regra F (porcentagem)

A partir de 30/09 toda substituição de preço nasce em **porcentagem sobre o preço recomendado** (o PriceLabs aplica o percentual depois de todas as personalizações), com **piso da data igual ao mínimo do quarto**. Assim o preço continua acompanhando demanda, ocupação do hotel e feriados, em vez de ficar congelado num valor fixo.

- **Nunca abaixo do mínimo do quarto:** Queen (7) R$ 800, Double R$ 900, Villa King Spa (7) R$ 1.000, Villa King Spa (2) R$ 900, Afrodite R$ 1.500.
- Não mexe em descontos de OTA, ofertas da Booking, preço base, mínimo, máximo nem personalizações. Queen Spa (2) segue de fora (regra D). A Balcony entrou em 30/09 com aprovação explícita do dono (exceção à regra D): ela sempre fica no piso (recomendado de R$ 295 a R$ 700 contra mínimo R$ 800), então o mínimo da data define o preço.
- Estadia mínima: mantém 1 noite nos próximos 14 dias com unidade livre e as 2 noites do feriado (09 a 11/10, 01/11, 19/11).
- Data com **50% ou mais das unidades vendidas** no quarto sai da escada e volta ao algoritmo. Data com 70% ou mais: avaliar subir 10%.

**Conferência feita em 30/09 (Portão 1), depois do Sync das 12h42:**
- **Confirmado:** o mínimo da data na substituição vence o piso de fim de semana, e o % incide sobre o preço recomendado. Villa King Spa (7), sexta e sábado 16 e 17/10: recomendado R$ 1.923, enviado antes R$ 2.220 (piso), agora **R$ 1.536** (−20%).
- **Limite descoberto:** o PriceLabs aplica o % **antes da suavização (Atenuação)**, que puxa cada dia de volta para o nível dos vizinhos. Um dia útil isolado com % é anulado: Queen (7) em 13/10 com −35% ficou em R$ 1.405 (a suavização somou R$ 774); Villa King Spa (7) em 20/10 com −5% ficou em R$ 1.062, igual aos vizinhos. O % funciona quando o preço já está no **mínimo** (dias úteis de 0 a 8 dias) e em **sexta e sábado**.
- **Consequência:** nos dias úteis depois do feriado (13 a 15/10, onde a suavização espalha o pico de domingo) a substituição segue **fixa**, que é a única que passa por cima da suavização. Nos dias úteis de 14 a 31 dias o algoritmo já está perto do mínimo (Queen R$ 955, Double R$ 1.020, Villa King Spa (7) R$ 1.062, Villa King Spa (2) R$ 945); não criei substituição neles.

## 3. Escada de desconto por antecedência

Percentual sobre o recomendado, para Queen (7), Double, Villa King Spa (7) e Villa King Spa (2):

| Antecedência | Dia útil (dom a qui) | Sexta e sábado |
|---|---|---|
| 0 a 8 dias | −35%, piso = mínimo (o preço fica no mínimo e sobe sozinho se o algoritmo subir) | 2 e 3/10: segue o valor fixo vigente (Queen sex 1.050 e sáb 1.240; Double 1.150 e 1.330; Villa King Spa (7) 1.330; Villa King Spa (2) 1.140) até o preço de sexta ser conferido em % |
| 9 a 13 dias | fixo no mínimo +10% onde a suavização anula o % (13 a 15/10 na Queen); feriado −10% | feriado 09 e 10/10: fixo até ser convertido |
| 14 a 21 dias | algoritmo (sem substituição) | **−20%**, piso = mínimo |
| 22 a 31 dias | algoritmo (sem substituição) | **−12%**, piso = mínimo |
| 32 dias em diante | algoritmo puro | algoritmo puro |

- **Feriado 09 a 12/10:** −10% sobre o recomendado (regra E), hoje ainda em valor fixo.
- **Afrodite:** acima da meta em todas as janelas (71%, 53%, 40%, 36%, 30%). Só noites livres em até 13 dias, −30% com piso R$ 1.500 (regra C). Sem escada adiante.
- A faixa de 32 a 45 dias ficou fora de propósito: está à frente da meta implícita (19% contra 5%).

**Efeito esperado no preço enviado em sexta e sábado** (estimativa com o recomendado de hoje, só datas com menos de 50% vendido):

| Quarto | 14–21 dias (−20%) | 22–31 dias (−12%) |
|---|---|---|
| Queen (7) | 2.070 → 1.418 (−31%) | 2.077 → 1.687 (−19%) |
| Double | 2.220 → 1.534 (−31%) | 2.242 → 1.829 (−18%) |
| Villa King Spa (7) | 2.220 → 1.536 (−31%) | 2.252 → 1.845 (−18%) |
| Villa King Spa (2) | 1.905 → 1.423 (−25%) | 1.980 → 1.682 (−15%) |

O hóspede paga de 38% a 57% do valor enviado na Booking (descontos empilhados; nas reservas de hoje, Villa King Spa (7) 38% a 41% e Queen 49% a 56%).

## 4. Fases e portões

| Fase | O quê | Situação |
|---|---|---|
| 1 | Dias úteis de 0 a 13 dias da Queen, da Double e do Afrodite convertidos de fixo para %; Villa King Spa (2) e Villa King Spa (7) em 13/10. | feita em 30/09 |
| Portão 1 | Conferir o piloto no cálculo depois do Sync. | **feito às 13h: passou nos fins de semana, falhou em dia útil isolado** (seção 2) |
| 2 | Sexta e sábado de 16, 17, 23, 24, 30 e 31/10 em % (−20% e −12%, piso = mínimo) na Queen (7), na Double, na Villa King Spa (7) e na Villa King Spa (2). | **aplicada às 13h42**; vai ao ar no próximo Sync |
| 2b | Sexta e sábado 02 e 03/10 e feriado 09 a 12/10 passam para % depois de o primeiro fim de semana em % ser conferido no cálculo e nas reservas. | pendente |
| Rotina diária | Nas 5 análises: sexta e sábado que entram na faixa de 14 a 31 dias recebem o %, data com 50% ou mais sai, dia útil que vira plateau (suavização) recebe valor fixo. | todos os dias |

## 5. Gatilhos de escalada e de freio

- **Quinta 01/10, 10h:** se sexta e sábado (02 e 03/10) tiverem menos de +3 diárias vendidas em relação a hoje, sobe mais 10 pontos de desconto em sexta e sábado (piso = mínimo). Com +8 ou mais, mantém e sobe o sábado.
- **Segunda 05/10:** revisão semanal. Faixa de 15 a 29 dias abaixo de 15% de ocupação: +5 pontos na escada de 14 a 31 dias. Janela de 7 dias acima de 50%: mantém.
- **Freio:** janela de 15 dias acima de 50%, ou pedido do dono, encerra o modo agressivo (as substituições em % voltam para 0% ou são apagadas).

## 6. Expectativa honesta

Fechar 70% nos próximos 7 dias só com preço não é possível: faltam 111 diárias em 7 dias (16 por dia), e o hotel inteiro vende hoje cerca de 16 diárias por dia somando todas as datas. Nessas 7 datas o ritmo foi de cerca de 3 por dia. Estimativas para 06/10:
- Pelo ritmo atual: cerca de 29% (62 diárias).
- Pelo padrão de antecedência das últimas duas semanas (amostra pequena, 69 diárias): cerca de 52%.
- Faixa razoável: **30% a 50%**.

Os preços já estão no piso nos dias úteis, então o próximo ganho não vem de cortar mais e sim de converter mais (canais, restrições, venda direta) e de pré-encher as faixas de 15 a 29 dias. Quando essa faixa chegar perto de 20% (meta implícita), a janela de 7 dias passa a nascer mais cheia e a venda de última hora leva a 70%.

## 7. O que só o dono resolve (maior impacto primeiro)

1. **Sync Now** depois de cada rodada: o PriceLabs só envia ao Beds24 e aos canais no Sync diário (06h) ou no Sync Now. O corte de sexta (Queen R$ 1.050 e Double R$ 1.150) já está calculado e ainda não foi enviado.
2. **Villa, restrições na Booking:** a Booking só vende a chegada de sexta para 2 noites. Sexta avulsa, sábado avulso, sábado+domingo e a sexta de 09/10 aparecem indisponíveis. Conferir Beds24 (calendário e estadia mínima) e a extranet (Tarifas e disponibilidade > Calendário > restrições) em 02, 03 e 09/10. Libera 13 unidades por noite vendáveis avulsas.
3. **Cancelamentos (35%):** investigar no Beds24 os lotes duplicados de 09 a 14/09 e a política de cancelamento das tarifas.
4. **Piso de fim de semana na tela (aprovado em 30/09, só o dono consegue):** em Personalizações (grupo Recanto dos Moinhos, ou em cada anúncio se estiver definido lá) > Configurações avançadas de preço mínimo > "Preço mínimo de fim de semana" (Minimum Weekend Price), trocar "% do preço base = 150%" por "% do preço mínimo = 130%" e salvar (Save and Refresh) e depois Sync Now. Piso resultante: Queen (7) R$ 1.040, Double R$ 1.170, Villa King Spa (7) R$ 1.300, Villa King Spa (2) R$ 1.170. O algoritmo passa a flutuar sozinho em todos os fins de semana, inclusive de 32 a 60 dias, sem depender de substituições. Desfazer: voltar para "% do preço base = 150%".
5. **Balcony (vitrine da Villa na Booking):** aprovada e aplicada em 30/09 (sexta R$ 850, sábado R$ 1.000 e R$ 800 nos fins de semana à frente). Feito.
6. **Venda direta de última hora:** campanha para hóspedes anteriores nos 0 a 6 dias (WhatsApp), com condição fechada que não vira preço público. Só escrevo quando o dono pedir.

## 8. Como desfazer

Cada mudança está no `registro-de-mudancas.md` com o valor de antes e o pedido para desfazer. Pedido geral: **"Volte a Regra F para preço fixo"** (recoloco os valores fixos anteriores) ou **"Pare de aplicar"** (a rotina volta a só analisar).

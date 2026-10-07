# Regras da operação (fonte única)

Versão: 2026-10-07. Os números de máquina (mínimos, fatores, parâmetros, feriados) ficam em `../dados/quartos.json`. As regras abaixo dizem o que fazer com eles. Ao mudar qualquer regra: edite aqui, registre em `historico-da-skill.md` e faça commit.

Siglas usadas: D = dias de antecedência (D0 é hoje). "Suítes com banheira" são os 6 quartos sem a Balcony.

## 1. Proibido sempre (só o dono muda)
- Mexer em descontos de OTA (30–50%), em ofertas da Booking (todas obrigatórias) ou no Genius acima do nível 3.
- Mudar a política de **sábado sozinho** (Regra H).
- Mudar os limites de segurança em `quartos.json` → `limites_seguranca`.
- Pedir senha, token ou chave no chat.
- Se o dono disse "pare" ou "pare de aplicar", só analisar e propor até ele liberar de novo. Se ele negar uma chamada de ferramenta, não repetir.

## 2. Autonomia (decisão do dono em 06/10: autonomia total)
A Skill aplica sozinha, sem pedir aprovação:
- preço e estadia mínima por data;
- base, mínimo e máximo do anúncio (no máximo ±15% por semana contra `referencia_semanal`);
- personalizações (com "como desfazer");
- pisos de feriado (editando `quartos.json`, nunca abaixo de R$ 800 de valor real).

Toda gravação passa antes pelo `scripts/validar_plano.py`, é conferida depois no recálculo e fica no registro com antes, depois e como desfazer.

## 3. Metas e prioridade
- Ocupação acumulada a partir de hoje: 7 dias 70% (o objetivo é 100%), 15 dias 50%, 30 dias 35%, 45 dias 25%, 60 dias 15%.
- Medir no hotel todo, sem a Balcony (suítes com banheira) e por pousada.
- Prioridade máxima: encher os próximos 7 dias.

## 4. Regras de preço e estadia (aplicar nesta ordem)

**Forma de gravar o preço (dono, 07/10: "Faça assim sempre")**
- Preço por data **sempre em percentual** sobre o recomendado, para o PriceLabs continuar flutuando com a demanda. O piso da data vai em `min_price`; se precisar, o teto em `max_price`. Preço fixo só com ordem expressa do dono (`excecao: "dono"`); o validador barra o resto.
- O piso é o mínimo do quarto. Nas datas de Regra I e de feriado, o piso é o valor combinado. Numa data mais cara dentro de um bloco mais barato, o piso é o alvo dela.
- A suavização do PriceLabs tira a média do bloco: domingo a quinta e sexta e sábado; na Balcony, a semana inteira. Por isso o percentual se calcula **por bloco**, com `scripts/percentual.py`. Um percentual numa data só "vaza" para as outras datas do bloco. Se a regra não permitir mexer nelas, use teto na data.
- Ao converter, o alvo é o preço de antes. Assim a mudança de forma não muda o preço do dia.

**Regra H, sábado (dono, 06/10)**
- A diária de sábado sozinha não é vendida. O bloqueio é do dono, no Beds24/Booking; no PriceLabs, todo sábado fica com estadia mínima 2.
- Exceção: na quarta a sexta antes, se a pousada estiver abaixo de 50% naquele sábado e não for feriado, volte o sábado para 1 noite (`excecao: "regra_h"`, com `ocupacao_sabado`) e avise o dono para liberar no Beds24.
- Com o domingo esgotado, o sábado só vende junto com a sexta. As unidades de sábado acima das livres na sexta ficam presas: cite isso no relatório.

**Regra I, última hora (dono, 06/10)**
- Vale para noites de D0 a D3 (`regra_i_dias`), fora de feriado, com unidade livre.
- Até 20% (`regra_i_desconto_max`) abaixo do mínimo do quarto: percentual com `min_price` igual ao valor da Regra I, nunca abaixo do limite de segurança de 70% do mínimo de referência. Hoje: Queen (7) R$ 640, Double R$ 720; na Villa (novo mínimo desde 06/10 16h) Villa King Spa (7) R$ 700, Villa King Spa (2) R$ 630, Balcony R$ 700 (igual à Villa King Spa 7).
- Queen (2) e Afrodite ficam fora.

**Regra A, estadia mínima**
- 1 noite nas datas dos próximos 14 dias (`regra_a_janela_dias`) com unidade livre.
- Exceções: sábados (Regra H); feriados mantêm 2 noites; Finados 3 noites (dono); vésperas com política de 2 diárias.
- Uma substituição nova feita pela tela apaga a anterior. Pela API os campos se juntam, mas confira a estadia mínima depois de gravar.

**Regra F, escada por antecedência** (Queen 7, Double, Villa King Spa 7, Villa King Spa 2)
- Percentual sobre o recomendado, com o piso da data igual ao mínimo do quarto (`min_price`).
- Dia útil:
  - de D0 a D8, −35%; se a suavização diluir o percentual, calcule o percentual do bloco inteiro (`percentual.py`), com o piso no mínimo;
  - de D9 a D13, mínimo do quarto quando a semana estiver fraca (pedido do dono de 06/10 para lotar).
- Sexta e sábado:
  - de D14 a D21, −20%;
  - de D22 a D31, −12%;
  - de D2 a D13, mantém o nível vigente ou desce para perto do mercado (ver seção 5).
  - Sexta e sábado formam um bloco de suavização: dê o mesmo percentual às duas noites. Para cortar uma noite só, use o piso e o teto da data.
- Saída da escada: quarto com 50% ou mais vendido na data (`regra_f_saida_vendido`) apaga a substituição de preço e mantém a estadia mínima. Antes, confirme que é reserva e não bloqueio.
- A saída da escada **não vale** para datas com preço por decisão do dono, como "perto do mercado" ou "domingo no mínimo para lotar" (motivo começando com "Dono"). Nelas o preço fica até a ocupação da data passar de 70%. Aí sobe no máximo 10% por rodada. Esclarecido em 06/10: a prioridade do dono é lotar.

**Regra C, Afrodite**
- Noite livre a até 13 dias: −30% (`regra_c_desconto`) sobre o calculado, em percentual com piso de R$ 1.500.
- Afrodite fica acima da Queen (2) nos feriados.

**Regra G, Balcony (sem banheira; dono, 05/10; nova versão do dono em 07/10)**
- A Balcony fica **10% abaixo** (`regra_g_desconto`) da suíte com banheira mais barata e livre da Villa na mesma data: a VK2, ou a VK7 se a VK2 estiver esgotada.
  - Pedido do dono (07/10): "colocar uma diferença de 10% para a que não tem banheira".
- Nunca fica acima da suíte com banheira livre (o validador barra).
- Nunca fica abaixo do limite de segurança: R$ 630 (70% do mínimo de referência).
- Abaixo do mínimo do anúncio (R$ 765) só com `excecao: "dono"`.
- Como a suavização da Balcony junta a semana inteira, o alvo de cada data vai no piso (`min_price`), com o percentual do bloco calculado no `percentual.py`. A cada rotina, refaça o alvo quando as suítes mudarem de preço.
- No feriado, a Balcony segue o piso de vitrine (R$ 800 na vitrine = R$ 1.510) e nunca sobe por causa desta regra.

**Queen (2), sem desconto**
- Anomalia: o hóspede paga cerca de 34% do preço enviado.
- Não entra nas regras F e I e não recebe percentual negativo.
- Só baixa para acompanhar o piso de feriado (`excecao: "piso_feriado"`) ou por ordem do dono.

**Feriados** (as noites e os pisos estão em `quartos.json` → `feriados`)
- Piso de valor real por noite: preço × `fator_real`; na Balcony, preço × `fator_vitrine`.
- Nossa Senhora Aparecida (noites de 09 a 11/10/2026): piso de R$ 800 reais nos 7 quartos.
  - Histórico: R$ 1.000 (dono, 06/10); Villa a R$ 800 (dono, 06/10 16h); Recanto a R$ 800 (Skill, 07/10 08:20), depois de 24 h sem venda a 2 dias do feriado e 13% acima da mediana com hidro.
  - 09 e 10/10, em percentual com piso: Queen (7) e Double R$ 1.905, Queen (2) R$ 2.355, Afrodite R$ 2.550, Villa King Spa (7) e (2) R$ 2.060, Balcony R$ 1.510.
- Finados (30/10 a 02/11): +20% e 3 noites nos 7 quartos, feito pelo dono na tela em 03/10. Não sobrescrever sem motivo forte; se mudar, registre.
- O piso padrão de um feriado novo é R$ 1.000 reais. A Skill pode mudar o piso pelos dados (concorrentes, ritmo de vendas), mas nunca abaixo de R$ 800 reais.

## 4b. Estratégia por pousada e por suíte, pela demanda (dono, 07/10)
Pedido do dono: "ajustar o preço de acordo com a demanda que estamos recebendo em cada hotel separadamente, com uma estratégia bem definida para cada hotel e cada suíte".

**Como classificar** (toda rotina completa; o resultado vai em `quartos.json` → `demanda`):
- **Alta:** ocupação de 15 e de 30 dias acima da meta (50% e 35%) e ritmo forte nos últimos 14 dias.
- **Baixa:** ocupação de 15 e de 30 dias abaixo de 60% da meta, ou semanas futuras com 0% a 12%.
- **Média:** o resto.

**O que fazer em cada nível** (sempre em percentual com piso, por bloco de suavização):
- **Alta: cobrar mais.**
  - Base +5% a +10% por semana, dentro do limite de 15%.
  - Datas com 67% ou mais vendido a até 14 dias: +10% por rodada.
  - No feriado, a noite quase esgotada sobe e a fraca segura.
  - A Regra I continua só para a última unidade do dia.
- **Média: segurar e deixar o PriceLabs flutuar.** A saída da escada (Regra F) vale normalmente.
- **Baixa: vender volume.**
  - Base −10% a −15% por semana.
  - Dias úteis no mínimo.
  - Fim de semana perto da mediana do mercado.
  - Regra I nos dias D0 a D3.
- **Decisões do dono por data** ("perto do mercado", "domingo no mínimo") valem enquanto o quarto não estiver em demanda alta. Na alta, a demanda manda.

**Perfil das pousadas (07/10):**
- **Recanto, duas velocidades.**
  - Double e Queen (2) com demanda alta.
  - Afrodite com demanda média; vale a Regra C.
  - Queen 7 com demanda baixa: 7 unidades e vendas quase só de última hora (26 de 49 reservas com 0 a 1 dia de antecedência).
- **Villa, agressiva desde 06/10.**
  - VK7 e VK2 com demanda média, já reagindo: 5 vendas da VK7 em 3 horas depois do envio de 07/10.
  - Balcony com demanda baixa: 10% abaixo da suíte com banheira mais barata e livre (Regra G).

## 5. Posicionamento contra o mercado (Booking)
- Nossas notas: Recanto 8,4; Villa 8,1. Fique abaixo dos hotéis de mesmo preço com nota de 9 ou mais. Referência: entre 10% e 25% abaixo da mediana dos hotéis 5 estrelas com nota 9 ou mais nas datas fortes, e perto da mediana nas fracas.
- Se estivermos mais de 40% acima da mediana numa data com estoque sobrando a até 14 dias, desça para perto do mercado (feito em 06/10 para 16 e 17/10).
- Domingo e meio de semana fracos (mediana do mercado abaixo do nosso mínimo): mínimo do quarto e, de D0 a D3, Regra I.

## 6. Decisões do dono em vigor (com data)
- 07/10 13h: Balcony 10% abaixo da suíte com banheira mais barata e livre (Regra G nova).
- 07/10 11:46: estratégia por pousada e por suíte, de acordo com a demanda de cada uma (seção 4b).
- 07/10: "Ajuste tudo que está lançado com preço fixo no PriceLabs para percentual, para usarmos as ferramentas do PriceLabs de flutuação de preços. Faça assim sempre." Feito às 08:10: 103 datas convertidas, conferidas no recálculo.
- 06/10 16h: estratégia agressiva para lotar a Villa Dolce Amore de 06 a 25/10.
  - Mínimos da Villa: R$ 850 / R$ 765 / R$ 765 (VK7 / VK2 / Balcony), com corte de 15%; nesta semana não se corta mais (limite semanal).
  - Feriado da Villa com piso de R$ 800 reais (desde 07/10, o Recanto também).
  - Dias úteis e domingos no mínimo.
  - Fins de semana perto da mediana geral da Booking.
  - Sexta 23/10 com 1 noite.
- 25/09: ofertas da Booking obrigatórias; descontos de OTA intocáveis.
- 26/09: modo automático. "Sempre apresente a análise e aplique a estratégia. Só pare de aplicar caso eu avise para parar."
- 03/10: Finados com +20% e 3 noites.
- 05/10: Regra G.
- 06/10:
  - lotar esta semana e a próxima;
  - 16 e 17/10 perto do mercado;
  - 24/10 a −20%;
  - Regras H e I;
  - feriado com piso de R$ 1.000;
  - autonomia total, aprendizado automático e rotinas nesta conversa.
- A regra de sábado com valor real de pelo menos R$ 800 (02/10) **não vale como regra geral**: as decisões de 06/10 a superaram.

## 7. Pendências que dependem só da tela ou do dono
- Personalizações próprias da Balcony (sazonalidade e demanda agressivas, última hora de 25%) diferem das do grupo e a deixam acima das suítes em datas futuras. Pela autonomia total, a Skill pode igualar ao grupo, registrando o que era antes.
  - A suavização da Balcony junta a semana inteira num bloco só. Com percentual, a sexta e o sábado ficam presos no piso e não flutuam. Igualar a suavização ao grupo (blocos de domingo a quinta e de sexta a sábado) é o próximo passo para a Balcony flutuar de verdade.
- Só pela tela:
  - Safety Minimum Price em "Do Not Apply";
  - sincronização às 06:00 (e extras às 12:00 e 18:00, a US$ 1 por quarto por mês);
  - tabela de ajuste por ocupação;
  - piso de fim de semana (150% da base);
  - Sync Now.
- Extranet da Booking, Villa Dolce Amore: marcar hidromassagem/Jacuzzi, Wi-Fi e aquecimento.
- **URGENTE, canais no Beds24 (06/10):**
  - a Villa está na Expedia, Hoteis.com e Decolar com tarifa fixa de R$ 2.037 a R$ 4.075, 3 a 5 vezes a Booking: conferir o mapeamento do quarto e da tarifa;
  - o Recanto está 38–44% acima da Booking na Expedia: baixar o acréscimo do canal para 10–15%;
  - o site oficial está 50–89% acima da Booking: criar uma tarifa direta igual ou menor que a da Booking.
- Visibilidade e paridade (auditoria de 06/10, detalhes em `mercado.md`):
  - o site oficial está mais caro que os revendedores no Google Hotels;
  - o Perfil do Google tem categorias e endereço errados;
  - existem anúncios antigos da Booking com notas melhores;
  - os anúncios do Airbnb têm erros;
  - há duplicados no TripAdvisor e na Trivago;
  - os motores Omnibees antigos seguem no ar.
- Beds24:
  - reservas no canal "outros" a R$ 320–450 por noite na Villa King Spa (7);
  - Afrodite de 12 a 15/11 "indisponível" sem reserva;
  - lotes de reservas que podem estar duplicados.
- Queen (2): achar a causa do valor real baixo, por volta de 34% do enviado.

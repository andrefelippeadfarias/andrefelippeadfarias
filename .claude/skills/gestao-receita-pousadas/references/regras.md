# Regras da operação (fonte única)

Versão: 2026-10-08. Os números de máquina (mínimos, fatores, parâmetros, feriados) ficam em `../dados/quartos.json`. As regras abaixo dizem o que fazer com eles. Ao mudar qualquer regra: edite aqui, registre em `historico-da-skill.md` e faça commit.

Siglas usadas: D = dias de antecedência (D0 é hoje). "Suítes com banheira" são os 6 quartos sem a Balcony.

> **Estratégia consolidada:** `references/estrategia.md` diz o que queremos, a ordem de precedência e as táticas por tipo de noite; este arquivo guarda as regras detalhadas.

## 1. Proibido sempre (só o dono muda)
- Mexer em descontos de OTA (30–50%), em ofertas da Booking (todas obrigatórias) ou no Genius acima do nível 3.
- Mudar a política de **sábado sozinho** (Regra H).
- Mudar os limites de segurança em `quartos.json` → `limites_seguranca`, que inclui o valor do padrão de qualidade (`padrao_qualidade_diaria`).
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
- O piso é o mínimo do quarto (em sexta, sábado e feriado das suítes com banheira, no mínimo o padrão de qualidade, abaixo). Nas datas de Regra I e de feriado, o piso é o valor combinado. Numa data mais cara dentro de um bloco mais barato, o piso é o alvo dela.
- A suavização do PriceLabs tira a média do bloco: domingo a quinta e sexta e sábado; na Balcony, a semana inteira. Por isso o percentual se calcula **por bloco**, com `scripts/percentual.py`. Um percentual numa data só "vaza" para as outras datas do bloco. Se a regra não permitir mexer nelas, use teto na data.
- Ao converter, o alvo é o preço de antes. Assim a mudança de forma não muda o preço do dia.

**Padrão de qualidade, sexta, sábado e feriado (dono, 08/10)**
- Pedido do dono (literal): "Existe uma regra para consumidores de alto padrão, que o valor das diárias de feriados e finais de semana nas pousadas com suítes com banheira devem sempre ser a partir de 100 reais [lido como R$ 1.000] como um padrão de qualidade. Esse é o discurso. Porém precisamos ficar de olho no mercado para atingir nossos objetivos." E depois: "Porém sempre trabalhe com percentuais."
- Leitura da Skill (08/10): vale para as 6 suítes com banheira (Queen 7, Double, Afrodite, Queen 2, VK7 e VK2; **a Balcony fica de fora**), nas noites de **sexta e sábado e nas noites de feriado**, sobre o **preço enviado** (o da tabela do PriceLabs, antes dos descontos da OTA). O valor está em `quartos.json` → `limites_seguranca.padrao_qualidade_diaria` (hoje R$ 1.000) e só o dono muda. **Base ainda sem confirmação do dono:** R$ 1.000 enviados aparecem na vitrine da Booking como cerca de R$ 610 (Recanto) a R$ 660 (Villa) e rendem cerca de R$ 390 a R$ 420 de valor real. Se ele quis dizer vitrine ou valor real, o piso muda para cerca de R$ 1.640 a R$ 1.700 (vitrine) ou R$ 2.400 a R$ 2.560 (real). Perguntado no relatório de 08/10.
- **Como aplicar:** o preço fica em percentual e o piso (`min_price`) dessas datas é no mínimo o padrão. Nunca preço fixo (regra de 07/10). Nas sextas e sábados o `percentual.py` já usa o padrão como piso mínimo. No feriado vale o piso de segurança (R$ 800 de valor real), que já leva o preço enviado a R$ 1.905 ou mais.
- **Sem substituição de preço** na data (por exemplo depois da saída da escada da Regra F), vale o piso de fim de semana do PriceLabs: 150% da base (Queen 7 R$ 1.760, Double e VK7 R$ 2.220, VK2 R$ 1.905, Queen 2 R$ 2.805, Afrodite R$ 3.000), acima do padrão. Por isso apagar a substituição não fere o padrão. Se uma base cair abaixo de R$ 667, esse piso deixa de cobrir e a rotina precisa gravar o piso na data.
- **Queen (2):** o piso de sexta e sábado fica no preço atual dela (já acima do padrão), por causa da regra sem desconto.
- **Quando o mercado libera ficar abaixo** (todas as condições, com os números no campo `mercado` do item; sem ele o validador dá ERRO):
  - a data está a até 21 dias e tem unidade livre;
  - e vale um destes dados: (a) preço enviado × razão vitrine÷enviado de fim de semana (Recanto 0,61; Villa 0,66) mais de 10% acima da mediana por noite da Booking (total de 2 noites ÷ 2) para as mesmas noites, sendo a mediana com hidro e nota 9 ou mais para o Recanto e a geral para a Villa (que não aparece no filtro de hidro); ou (b) ocupação do mercado da data abaixo de 25% **e** abaixo da do mesmo dia do ano passado (`get_neighbourhood_data`), com a pousada abaixo da meta da janela da data (7 dias 70%, 15 dias 50%, 30 dias 35%);
  - o piso não desce abaixo do mínimo do quarto (a Regra I segue com os próprios limites);
  - datas a mais de 21 dias só pelo dado (a), com a busca da Booking daquelas noites.
- **Registro das exceções:** cada exceção entra em `quartos.json` → `excecoes_mercado` (quarto, datas, piso, dado, `revisar_em`). A rotina completa lê a lista, confere com o dado do dia e volta ao padrão (piso R$ 1.000) quando o dado não se sustenta ou a data vendeu. Exceção vale só para as datas citadas. A `excecao: "dono"` também libera, com aviso.
- **Acima do padrão continuam valendo:** os limites de segurança, o piso de feriado e a Regra G (a Balcony fica 10% abaixo da suíte com banheira mais barata e livre; enquanto a Balcony estiver presa em piso, o piso de sexta e sábado da VK7 e da VK2 fica acima do dela: R$ 1.020 em 16–17/10, porque o validador compara piso com piso).
- **A Regra I** (última hora, D0 a D3) pode ir abaixo do padrão numa sexta ou sábado com unidade livre, sempre com `mercado` no item.
- **Exceções de mercado em vigor (08/10):** VK2 sexta 23 e sábado 24/10, piso R$ 860 (preço R$ 895 na coleta de 07:53). Em 08/10 a Villa vendeu mais uma noite em cada uma dessas datas, então a rotina de 13:53 revalida.

**Regra H, sábado (dono, 06/10)**
- A diária de sábado sozinha não é vendida. O bloqueio é do dono, no Beds24/Booking; no PriceLabs, todo sábado fica com estadia mínima 2.
- Exceção: na quarta a sexta antes, se a pousada estiver abaixo de 50% naquele sábado e não for feriado, volte o sábado para 1 noite (`excecao: "regra_h"`, com `ocupacao_sabado`) e avise o dono para liberar no Beds24.
- Com o domingo esgotado, o sábado só vende junto com a sexta. As unidades de sábado acima das livres na sexta ficam presas: cite isso no relatório.

**Regra I, última hora (dono, 06/10)**
- Vale para noites de D0 a D3 (`regra_i_dias`), fora de feriado, com unidade livre.
- Até 20% (`regra_i_desconto_max`) abaixo do mínimo do quarto: percentual com `min_price` igual ao valor da Regra I, nunca abaixo do limite de segurança de 70% do mínimo de referência. Hoje: Queen (7) R$ 640, Double R$ 720; na Villa (novo mínimo desde 06/10 16h) Villa King Spa (7) R$ 700, Villa King Spa (2) R$ 630, Balcony R$ 700 (igual à Villa King Spa 7).
- Queen (2) e Afrodite ficam fora.
- **Data com mais de 70% vendida (esclarecido em 13/10):** a Regra I não **começa** um corte nela; a data fica no mínimo do quarto e sobe 10% por rodada pelo piso (`min_price`), mantendo o percentual do bloco. Um corte que já estava aplicado e passou de 70% por causa das vendas que ele mesmo gerou (Queen 7 em 13/10: de 4 para 6 de 7 em 3 h) **segue só no próprio dia (D0)**, para vender a última unidade; de D1 a D3 volta ao mínimo na rodada seguinte. Subir só o piso é de propósito: apagar a substituição (saída da escada pela letra) tiraria o percentual do bloco e levantaria as datas vizinhas com Regra I.
- **Demanda alta:** a Regra I vale só para a última unidade do dia (seção 4b). Uma data com várias unidades livres volta ao mínimo do quarto.

**Regra A, estadia mínima**
- 1 noite nas datas dos próximos 14 dias (`regra_a_janela_dias`) com unidade livre.
- Exceções: sábados (Regra H); feriados mantêm 2 noites; Finados 3 noites (dono); Carnaval, Corpus Christi, 9 de Julho e Réveillon 3 noites (`sazonalidade.json`); vésperas com política de 2 diárias. Feriado nacional que cai em sábado ou domingo é fim de semana comum (sem piso nem estadia de feriado).
- Uma substituição nova feita pela tela apaga a anterior. Pela API os campos se juntam, mas confira a estadia mínima depois de gravar.

**Regra F, escada por antecedência** (Queen 7, Double, Villa King Spa 7, Villa King Spa 2)
- Percentual sobre o recomendado, com o piso da data igual ao mínimo do quarto (`min_price`); em sexta e sábado das suítes com banheira, o padrão de qualidade.
- Dia útil:
  - de D0 a D8, −35%; se a suavização diluir o percentual, calcule o percentual do bloco inteiro (`percentual.py`), com o piso no mínimo;
  - de D9 a D13, mínimo do quarto quando a semana estiver fraca (pedido do dono de 06/10 para lotar).
- Sexta e sábado:
  - de D14 a D21, −20%;
  - de D22 a D31, −12%;
  - de D2 a D13, mantém o nível vigente ou desce para perto do mercado (ver seção 5).
  - Sexta e sábado formam um bloco de suavização: dê o mesmo percentual às duas noites. Para cortar uma noite só, use o piso e o teto da data.
  - Nas suítes com banheira, o piso de sexta e sábado é o padrão de qualidade (R$ 1.000), e não o mínimo do quarto; abaixo disso só com `mercado`.
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
- **Balcony esgotada na data (13/10):** o preço natural dela numa noite esgotada pode passar da suíte (16 e 17/10: R$ 1.459 e R$ 1.661 contra a VK7 a R$ 1.366 e R$ 1.553). Ponha piso e teto na data (cerca de 10% abaixo da suíte com banheira livre mais barata), para um cancelamento não reabrir a Balcony acima da suíte.
- **Referência quando a VK2 está esgotada:** a VK7. Se a VK2 reabrir por cancelamento, ela volta ao piso (R$ 765 em dia útil) e a Balcony pode ficar acima dela até a rodada seguinte; refaça o alvo na rodada seguinte. O validador compara com a referência vigente.
- **Piso do anúncio:** o alvo da Regra G não vai abaixo do mínimo do anúncio (R$ 765) sem `excecao: "dono"`, exceto nas datas D0 a D3 da Regra I (piso de segurança R$ 630). Em 18 a 22/10 o alvo calculado era R$ 760 e o piso gravado foi R$ 765.

**Queen (2), sem desconto**
- Anomalia: o hóspede paga cerca de 34% do preço enviado.
- Não entra nas regras F e I e não recebe percentual negativo.
- Enquanto a anomalia dura: pisos com `fator_real` 0,34; cada reserva nova entra no relatório com valor real ÷ enviado e canal; três reservas seguidas acima de 0,42 mudam o fator em `quartos.json` e fecham a investigação; a conferência do mapeamento de tarifa no Beds24 fica com o dono.
- Só baixa para acompanhar o piso de feriado (`excecao: "piso_feriado"`) ou por ordem do dono.

**Feriados** (as noites e os pisos estão em `quartos.json` → `feriados`)
- Piso de valor real por noite: preço × `fator_real`; na Balcony, preço × `fator_vitrine`.
- Nossa Senhora Aparecida (noites de 09 a 11/10/2026): piso de R$ 800 reais nos 7 quartos.
  - Histórico: R$ 1.000 (dono, 06/10); Villa a R$ 800 (dono, 06/10 16h); Recanto a R$ 800 (Skill, 07/10 08:20), depois de 24 h sem venda a 2 dias do feriado e 13% acima da mediana com hidro.
  - 09 e 10/10, em percentual com piso: Queen (7) e Double R$ 1.905, Queen (2) R$ 2.355, Afrodite R$ 2.550, Villa King Spa (7) e (2) R$ 2.060, Balcony R$ 1.510.
- Finados (30/10 a 02/11): +20% e 3 noites nos 7 quartos, feito pelo dono na tela em 03/10. Não sobrescrever sem motivo forte; se mudar, registre.
- **Regras de proteção nunca levam validade (`lead_time_expiry`)** (10/10): a regra por data com validade de N dias deixa de valer a N dias da noite, e uma gravação em cima dela herda a validade. Em 11/10 e 19/11 existia a regra "Véspera de feriado: política de 2 diárias" (validade 3) nos 7 quartos; em 08/10 ela venceu e a Queen 7 caiu a R$ 949. Em 10/10 apaguei e recriei as duas datas nos 7 quartos sem validade (11/10: sem estadia mínima, que vale 1 por ser D1; 19/11 com 2 noites). Antes de gravar numa data a até 3 dias, leia a regra existente; se tiver `lead_time_expiry`, apague e recrie. O recálculo só confirma a proteção com `dso_flag 1` e `min_price` igual ao gravado.
- **Pisos em 11/10 depois do ajuste de 10/10** (percentual com piso, noite esgotada nos 7 quartos): Queen (7) -40% e R$ 1.905; Double -59% e R$ 1.905; Afrodite 0% e R$ 2.550; Queen (2) 0% e R$ 2.355; VK7 -48% e R$ 2.060; VK2 -60% e R$ 2.060; Balcony 0% e R$ 1.510 (vitrine). O percentual negativo tira o peso da noite alta da média do bloco domingo-quinta, para que a reabertura não levante 12 a 15/10 (VK7 foi de R$ 850 a R$ 1.278 em 09/10).
- Pisos de segurança acrescentados em 10/10 onde o piso de fim de semana do PriceLabs (150% da base) dá menos de R$ 800 reais: Queen (7) R$ 1.905 e VK2 R$ 2.060 em 30 e 31/10 (com o +20% do dono) e em 20 e 21/11; Queen (2) R$ 2.355 em 01/11 (com o +20% do dono).
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
  - Fim de semana perto da mediana do mercado, sem passar do padrão de qualidade (só com exceção de mercado).
  - Regra I nos dias D0 a D3.
- **Decisões do dono por data** ("perto do mercado", "domingo no mínimo") valem enquanto o quarto não estiver em demanda alta. Na alta, a demanda manda.

**Perfil das pousadas (07/10):**
- **Recanto, duas velocidades.**
  - Double e Queen (2) com demanda alta.
  - Afrodite com demanda média; vale a Regra C.
  - Queen 7: era baixa em 07/10 e passou a **média** em 12/10 (classe viva em `quartos.json` → `demanda`); 7 unidades e vendas quase só de última hora (26 de 49 reservas com 0 a 1 dia de antecedência).
- **Villa, agressiva desde 06/10.**
  - VK7 e VK2 com demanda média, já reagindo: 5 vendas da VK7 em 3 horas depois do envio de 07/10. **VK7 passou a alta em 13/10** (7 dias 74%, 15 dias 51%, 30 dias 45%): Regra I só na última unidade e +10% por rodada nas datas com 67% ou mais vendido a até 14 dias. VK2 é candidata (15d 60%, 30d 47%, mas só 2 unidades).
  - Balcony com demanda baixa: 10% abaixo da suíte com banheira mais barata e livre (Regra G).

## 4c. Faixas de preço de mercado, sazonalidade e flutuação percentual (dono, 13/10)
Pedido do dono: "qualificar os preços: muito baixo, baixo, médio, alto e muito alto, para fazer a flutuação e os ajustes percentuais de acordo com o planejado e manter o trabalho dentro da métrica", "prever as sazonalidades para estar sempre dentro do escopo da época, do ano, do feriado, de cada dia" e "usar a IA para pesquisar".

**As cinco faixas** (`scripts/faixas_de_preco.py`)
- Medidas contra TODA a oferta disponível de Campos do Jordão na Booking para as mesmas noites (varredura por agentes de IA, workflow `varredura-concorrencia`: busca por faixa de preço até esgotar a lista, mais busca por tipo de acomodação). Preço por noite = total da estadia ÷ noites.
- Em cada data e em cada conjunto de comparação, os cortes são os quintis do preço por noite: **muito baixo** abaixo do 20º percentil; **baixo** do 20º ao 40º; **médio** do 40º ao 60º; **alto** do 60º ao 80º; **muito alto** acima do 80º.
- Conjuntos: `geral` (tudo), `recanto` (pares do Recanto: hidro ou spa e 4 a 5 estrelas ou nota 8,0 ou mais) e `villa` (pares da Villa: até 4 estrelas, nota de 7,0 a 9,2). O Recanto se compara com `recanto`; VK7, VK2 e Balcony com `villa`.
- O nosso preço entra pelo que o hóspede vê (vitrine): enviado × `fator_vitrine` do quarto (Recanto 0,61; Villa 0,53).
- Noite sem busca própria usa os cortes típicos do tipo de dia (semana, fim de semana, feriado/evento), que são a mediana dos cortes das datas pesquisadas daquele tipo. Os cortes vigentes ficam em `dados/mercado_faixas.json` e são refeitos na revisão semanal (segunda, 07:53) rodando a varredura de novo.

**Sazonalidade** (`dados/sazonalidade.json`, `scripts/sazonalidade.py`)
- Calendário de 14/10/2026 a 31/12/2027 em períodos (cada noite cai em exatamente um; semana e fim de semana separados quando o nível difere), com **nível de demanda** (muito baixa, baixa, média, alta, muito alta), **faixa alvo**, estadia mínima e antecedência típica, cada um com motivo e evidência (feriados oficiais, eventos, ocupação do mercado no mesmo período do ano anterior pelo PriceLabs, balanços da Prefeitura).
- Regra fixa: a faixa alvo segue o nível (muito baixa e baixa → baixo; média → médio; alta → alto; muito alta → muito alto). Nunca abaixo de "baixo" fora de D0 a D3 (Regra I).
- Campos do Jordão é contra-sazonal ao estado: pico no inverno (junho a agosto, julho no topo: mercado 62% a 67%, Corpus Christi e 9 de Julho acima de 90%), segundo pico em dezembro (Réveillon 96%), vales em fevereiro e março (26% a 34%) e nas semanas de outubro, novembro e primeira quinzena de dezembro.
- Revisão: todo mês na rotina completa de segunda (rodar o workflow `sazonalidade-campos`), e sempre que um evento marcado "estimado" for confirmado (Festival de Inverno 2027, Oktoberfest 2027, férias de julho de 2027).

**Regra de flutuação por noite e por quarto** (`acao_sugerida` em `faixas_de_preco.py`; o validador continua barrando o que passar dos limites)
- Entradas: faixa atual da vitrine, faixa alvo da sazonalidade, ocupação da noite (vendidas ÷ unidades) e meta da janela em que a noite cai (0 a 6 dias 70%, 7 a 14 dias 50%, 15 a 29 dias 35%, 30 a 44 dias 25%, 45 dias ou mais 15%).
- **Esgotada:** nada muda; só o piso de proteção (um cancelamento não pode reabrir barato).
- **Atrás da meta** (ocupação abaixo da meta menos 20 pontos): desce um degrau, −10% (−15% se já está em alto ou muito alto), **sem passar abaixo da faixa alvo menos uma** e nunca abaixo de "baixo" fora de D0 a D3.
- **À frente da meta** (ocupação de meta mais 10 pontos para cima): sobe um degrau, +10%, até a faixa alvo mais uma; em muito alto, segura.
- **Na meta:** aproxima do alvo devagar (+5% se duas faixas abaixo, −5% se duas acima); senão segura.
- Os passos entram sempre em **percentual com piso**, por bloco de suavização (`percentual.py`), e por cima deles continuam valendo: limites de segurança, padrão de qualidade de R$ 1.000, piso de feriado (R$ 800 reais), Regras G, H e I e o ±15% por semana da base. Um passo por rodada, no máximo; a rodada seguinte mede o efeito antes do próximo.
- **Combinação com a escada (Regra F):** a Regra F dá o patamar inicial de uma data que entra no plano (percentual por antecedência); a flutuação move um degrau por rodada a partir do preço vigente, dentro da faixa alvo ±1. Quando as duas discordam, vale o passo da flutuação (ele já olha ocupação e mercado); a escada só manda em data sem substituição ainda.
- **Saída da escada (Regra F, 50% vendido) não apaga a substituição:** zera o percentual negativo e mantém piso e estadia mínima (apagar levanta o bloco inteiro: Queen 7, 13/10, média de R$ 595 para R$ 776). Gatilho de subida único: ocupação da noite de meta mais 10 pontos para cima (o "67% a até 14 dias" da demanda alta é esse gatilho na janela de 7 a 14 dias).
- **Um passo por noite por dia:** um passo só conta como medido depois de chegar aos canais (envio diário ou Sync Now, conferido na Booking, nunca pelo `user_price`) e de passar uma rodada; até lá a noite não recebe segundo passo na mesma direção. Em D0 a D1 o pedido de Sync Now sai na primeira linha do relatório.
- **Sem classificação, sem passo:** noite sem faixa (script falhou, "sem dado de mercado", feriado ou evento sem busca própria, noite fora do calendário) não recebe passo de flutuação; valem travas, pisos de proteção, Regras A, G, H e I e as regras de feriado. Cortes com mais de 14 dias só permitem subir ou segurar.
- **Classe de demanda com histerese:** classificada só na rotina completa com calendário recalculado; ritmo forte = diárias vendidas nos últimos 14 dias de pelo menos 1,5 vez o número de unidades; a classe só muda com o critério valendo em duas rotinas completas seguidas; rotina leve só anota "candidata".
- **Classe × faixa alvo:** a faixa alvo vem só da sazonalidade; a classe desloca o teto da subida e o piso da descida (alta pode chegar a alvo+1 mesmo na meta; média fica no alvo; baixa pode descer a alvo−1 já na meta).
- **Evento estimado** (sem data confirmada em `sazonalidade.json`): usa a faixa do período de fundo até D-90; de D-90 em diante, sem confirmação, a rotina abre "confirmar evento" no relatório e não sobe a faixa por ele; com `confianca: baixa` o alvo do evento nunca passa de alvo+1 do fundo.
- **Orçamento de recálculo** (3 por quarto por dia): 1 medir (07:53), 1 detalhar quando houver mudança, 1 conferir; rotina leve só recalcula quarto com reserva, cancelamento ou mudança manual, e só com saldo; sem saldo, sem passo (só travas e Regra I em D0 a D1 com dado da Booking), "não conferido (limite de recálculo)".
- **Exceção de mercado não é um passo:** quando o passo bate no padrão de qualidade, a rotina para no padrão; a exceção só entra pelos critérios da seção de feriados, com `revisar_em` na rotina completa seguinte.
- **Fator de vitrine da Villa:** 0,53 em dia de semana e feriado, 0,66 em sexta e sábado (`fator_vitrine_fds`, medido em 06 e 07/10); o script usa o de fim de semana nessas noites.
- **"Topo" e "base" da faixa:** o alvo de preço do Recanto é o corte superior da faixa alvo e o da Villa é o corte inferior; o passo percentual leva até lá, nunca além da faixa alvo mais uma.
- Recanto (5 estrelas, nota 8,4) opera no topo da faixa alvo; Villa (3 estrelas, nota 8,1) opera na base.

## 5. Posicionamento contra o mercado (Booking)
- Nossas notas: Recanto 8,4; Villa 8,1. Fique abaixo dos hotéis de mesmo preço com nota de 9 ou mais. Referência: entre 10% e 25% abaixo da mediana dos hotéis 5 estrelas com nota 9 ou mais nas datas fortes, e perto da mediana nas fracas. Em sexta, sábado e feriado das suítes com banheira, o piso é o padrão de qualidade.
- Se estivermos mais de 40% acima da mediana numa data com estoque sobrando a até 14 dias, desça para perto do mercado (feito em 06/10 para 16 e 17/10). Em sexta, sábado e feriado das suítes com banheira, a descida para no padrão de qualidade (R$ 1.000); abaixo disso só com o dado no campo `mercado`.
- Domingo e meio de semana fracos (mediana do mercado abaixo do nosso mínimo): mínimo do quarto e, de D0 a D3, Regra I.

## 6. Decisões do dono em vigor (com data)
- 13/10: preços qualificados em cinco faixas de mercado (muito baixo a muito alto), sazonalidade prevista para 2026 e 2027 e flutuação percentual por faixa e ocupação contra a meta; pesquisa de mercado feita por agentes de IA (seção 4c).
- 08/10: padrão de qualidade. Sexta, sábado e feriado das suítes com banheira a partir de R$ 1.000 por diária, "mas de olho no mercado", e "sempre trabalhe com percentuais" (piso em `min_price`, nunca preço fixo). Aplicado em 16 datas (Queen 7, Double, VK7 e VK2 de 16/10 a 07/11).
- 08/10: "Pode igualar a suavização da Balcony às outras suítes". A API não permite; só pela tela (seção 7). Aguardando o dono salvar.
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
  - A suavização da Balcony junta a semana inteira num bloco só. Com percentual, a sexta e o sábado ficam presos no piso e não flutuam. O dono pediu em 08/10 para igualar ao grupo (blocos de domingo a quinta e de sexta a sábado).
  - **Não dá pela API nem pelo MCP** (pesquisa de 08/10: `update_customizations` recusa `smoothing`; `update_listing_data` não tem o campo). Só pela tela, pelo dono: Painel de preços > linha da Balcony (350364___722814) > Revisar preços > Editar em Personalizações > aba "All Customizations" > Smoothing > ligado > **"Smooth weekdays vs. weekends"** > Salvar. Não escolher "Set week start" (é o modo atual, de 7 dias) nem "No Smoothing".
  - Depois que ele salvar: no primeiro recálculo com `parse_reasons_json` da Balcony, confirmar os blocos (domingo a quinta e sexta a sábado), refazer os percentuais da Balcony com `percentual.py` e trocar os pisos de sexta e sábado presos pelo alvo da Regra G (cerca de 10% abaixo do preço da VK2; em 16–17/10, VK2 a R$ 1.178 dá alvo de cerca de R$ 1.060, hoje a Balcony está presa em R$ 1.016).
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

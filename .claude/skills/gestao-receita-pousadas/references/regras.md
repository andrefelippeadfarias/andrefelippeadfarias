# Regras da operação (fonte única)

Versão: 2026-10-14 (consolidação). Os números de máquina (metas, limites, mínimos, fatores, parâmetros, feriados) ficam em `../dados/quartos.json`, que é a única fonte deles; pendências operacionais ficam em `../dados/pendencias.json`. As regras abaixo dizem o que fazer com eles. Ao mudar qualquer regra: edite aqui, registre em `historico-da-skill.md` e faça commit.

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
- Ocupação acumulada a partir de hoje (`quartos.json` → `metas`, única fonte): 7 dias 70% (o objetivo é 100%), 15 dias 50%, 30 dias 35%, 45 dias 25%, 60 dias 15%. Cada noite é julgada pela meta da janela em que cai.
- Medir no hotel todo, sem a Balcony (suítes com banheira) e por pousada.
- Prioridade máxima: encher os próximos 7 dias.

## 4. Regras de preço e estadia (aplicar nesta ordem)

**Forma de gravar o preço (dono, 07/10: "Faça assim sempre")**
- Preço por data **sempre em percentual** sobre o recomendado, para o PriceLabs continuar flutuando com a demanda. O piso da data vai em `min_price`; se precisar, o teto em `max_price`. Preço fixo só com ordem expressa do dono (`excecao: "dono"`); o validador barra o resto.
- O piso é o mínimo do quarto (em sexta, sábado e feriado das suítes com banheira, no mínimo o padrão de qualidade, abaixo). Nas datas de Regra I e de feriado, o piso é o valor combinado. Numa data mais cara dentro de um bloco mais barato, o piso é o alvo dela.
- A suavização do PriceLabs tira a média do bloco: domingo a quinta e sexta e sábado; na Balcony, a semana inteira. Por isso o percentual se calcula **por bloco**, com `scripts/percentual.py`. Um percentual numa data só "vaza" para as outras datas do bloco. Se a regra não permitir mexer nelas, use teto na data.
- Ao converter, o alvo é o preço de antes. Assim a mudança de forma não muda o preço do dia.
- **Piso e teto também em percentual (dono, 14/10: "Trabalhe sempre com percentuais nunca com valores absolutos no pricelabs.")** Nada por data vai em reais: `min_price` e `max_price` saem como `percent_base`, que no PriceLabs é a **variação sobre a base do anúncio** (igual ao `price_type` "percent": −31 = base × 0,69; 61 daria base × 1,61; medido em 14/10 na VK2). O plano continua em reais (as travas, a Regra G e o padrão de qualidade pensam em reais) e `montar_payload` converte: piso arredondado **para cima** (nunca abaixo do pedido, até 1% da base acima, R$ 12 a R$ 20), teto arredondado **para baixo** e nunca abaixo do piso. O validador já usa o valor efetivo do percentual nas travas. Um piso em percentual acompanha a base do anúncio: se a base mudar (±15% por semana), todos os pisos por data mudam junto; por isso toda mudança de base passa a exigir conferência dos pisos de feriado e do padrão de qualidade. A base, o mínimo e o máximo do anúncio (`update_listing_data`) são a âncora dos percentuais e **não são mais usados para corrigir preço** (dono, 14/10: "Lembre-se tem ser correções percentuais"): toda correção vai em percentual por data; mudar base, mínimo ou máximo só com ordem expressa do dono. `scripts/pisos_percentual.py` converte regras antigas e confere.

**Piso de dia de semana das suítes com banheira: R$ 500 no preço final (dono, 14/10)**
- Pedido do dono (literal): "Vamos ajustar os valores das suítes com banheira para dia de semana, no mínimo de R$500. Preço final nas plataformas. Ajuste isso percentualmente para podermos termos a flutuação de preço natural do Price Labs, caso ele decida aumentar um pouco mais, para performarmos melhor, aproveitando melhor as oportunidades."
- Vale para as 6 suítes com banheira (**não a Balcony**), nas noites de domingo a quinta fora de feriado, e **também para a Regra I** (o corte de última hora não desce abaixo disso). "Preço final" = vitrine da Booking, a mais descontada: piso enviado = R$ 500 ÷ `parametros.fator_vitrine_semana` (**dono, 20/10: "Serve para todo o piso": vale para todos os hóspedes, inclusive o Genius 3, o mais descontado**; Recanto 0,41 → R$ 1.220; Villa 0,53 → R$ 943). O valor de R$ 500 está em `limites_seguranca.piso_vitrine_semana_suites` e só o dono muda; os fatores são medição e a revisão de segunda recalibra.
- **Como aplicar:** piso (`min_price`) em percentual da base; o preço segue em percentual e flutua acima do piso. Teto por data abaixo desse piso sai (vai ao máximo do anúncio) para o PriceLabs poder subir. O mínimo do anúncio **não** sobe para isso (dono, 14/10: "Lembre-se tem ser correções percentuais"): todas as noites de domingo a quinta das suítes cujo mínimo dá menos de R$ 500 na vitrine (VK7 e King Spa 2) levam piso por data em percentual até 30/04/2027; a rotina completa estende esse horizonte a cada mês. Sem exceção de mercado; o validador dá ERRO.
- **Efeito na Regra G:** a Balcony segue 10% abaixo da suíte com banheira livre mais barata da Villa, então sobe junto nos dias de semana.

**Piso de fim de semana das suítes com banheira: R$ 850 no preço final (dono, 14/10)**
- Pedido do dono (literal): "Para o fim de semana a diária. Mínima pra sweet, com banheira, deve ser na faixa de R$ 850,00". Lido como preço final nas plataformas, como o pedido anterior de R$ 500 para os dias de semana.
- Vale para as 6 suítes com banheira (não a Balcony), nas noites de sexta e sábado fora de feriado, inclusive na Regra I e sem exceção de mercado. Piso enviado = R$ 850 ÷ `parametros.fator_vitrine_fds` (dono, 20/10: vale para o Genius 3; Recanto 0,425 → R$ 2.000; Villa 0,575 → R$ 1.478). Sem regra na data, o piso de fim de semana do PriceLabs (150% da base) cobre Double, Afrodite, Q2, VK7 e VK2, mas não a Q7 (R$ 1.760): ela leva piso por data em toda sexta e sábado até 30/04/2027. Valor em `limites_seguranca.piso_vitrine_fds_suites`; só o dono muda.
- Responde a dúvida do padrão de qualidade abaixo: a base é o preço final. O padrão de R$ 1.000 enviados continua só como piso mínimo nas noites de feriado (onde valem também os pisos de feriado, mais altos).
- Sem regra na data vale o piso de fim de semana do PriceLabs (150% da base), acima do piso em todas as suítes.

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
- **Acima do padrão continuam valendo:** os limites de segurança, o piso de feriado e a Regra G (a Balcony fica 10% abaixo da suíte com banheira mais barata e livre; enquanto a Balcony estiver presa em piso, o piso de sexta e sábado da VK7 e da VK2 fica pelo menos R$ 20 acima do dela, porque o validador compara piso com piso).
- **A Regra I** (última hora, D0 a D3) pode ir abaixo do padrão numa sexta ou sábado com unidade livre, sempre com `mercado` no item.
- **Exceções em vigor:** a lista viva é `quartos.json` → `excecoes_mercado` (hoje vazia); o validador avisa quando `revisar_em` venceu; o histórico fica no registro.

**Regra H, sábado (dono, 06/10)**
- A diária de sábado sozinha não é vendida. O bloqueio é do dono, no Beds24/Booking; no PriceLabs, todo sábado fica com estadia mínima 2.
- Exceção: na quarta a sexta antes, se a pousada estiver abaixo de 50% naquele sábado e não for feriado, volte o sábado para 1 noite (`excecao: "regra_h"`, com `ocupacao_sabado`) e avise o dono para liberar no Beds24.
- Com o domingo esgotado, o sábado só vende junto com a sexta. As unidades de sábado acima das livres na sexta ficam presas: cite isso no relatório.
- **Sábado isolado entre duas noites esgotadas:** o PriceLabs mostra estadia 1 mesmo com a regra por data em 2 (regra de dia órfão; Double 10/10, VK7 17/10). Não regrave; o `ler_recalculo.py` marca ⚠️ e o relatório diz "última unidade presa; o bloqueio de sábado sozinho do Beds24 protege". Desligar "orphan days" é só pela tela (pendência do dono).

**Regra I, última hora (dono, 06/10)**
- Vale para noites de D0 a D3 (`regra_i_dias`), fora de feriado, com unidade livre.
- Até 20% (`regra_i_desconto_max`) abaixo do mínimo do quarto, travado em 70% do mínimo de referência (`limites_seguranca`); percentual com `min_price` igual ao valor da Regra I. Valores derivados de `quartos.json`: Queen (7) R$ 640, Double R$ 720, VK7 R$ 700, VK2 R$ 630, Balcony R$ 630 (trava de 70% × 900). O validador exige o calendário mesclado (`--precos`) e barra noite esgotada, data com mais de 70% vendida (fora de D0) e, em demanda alta, mais de uma unidade livre.
- Queen (2) e Afrodite ficam fora.
- **Data com mais de 70% vendida (esclarecido em 13/10):** a Regra I não **começa** um corte nela; a data fica no mínimo do quarto e só sobe pelo gatilho único da flutuação (à frente da meta da janela, seção 4c), pelo piso (`min_price`), mantendo o percentual do bloco. Um corte que já estava aplicado e passou de 70% por causa das vendas que ele mesmo gerou (Queen 7 em 13/10: de 4 para 6 de 7 em 3 h) **segue só no próprio dia (D0)**, para vender a última unidade; de D1 a D3 volta ao mínimo na rodada seguinte. Subir só o piso é de propósito: apagar a substituição (saída da escada pela letra) tiraria o percentual do bloco e levantaria as datas vizinhas com Regra I.
- **Piso de R$ 500 no preço final (dono, 14/10):** nas suítes com banheira, de domingo a quinta, a Regra I não desce abaixo de R$ 794 enviados no Recanto e R$ 943 na Villa; na prática, ela fica só para a Balcony nesses dias.
- **Demanda alta:** a Regra I vale só para a última unidade do dia (seção 4b). Uma data com várias unidades livres volta ao mínimo do quarto.

**Regra A, estadia mínima**
- 1 noite nas datas dos próximos 14 dias (`regra_a_janela_dias`) com unidade livre.
- Exceções: sábados (Regra H); feriados mantêm 2 noites; Finados 3 noites (dono); Carnaval, Corpus Christi, 9 de Julho e Réveillon 3 noites (`sazonalidade.json`); vésperas com política de 2 diárias. Feriado nacional que cai em sábado ou domingo é fim de semana comum (sem piso nem estadia de feriado), **exceto Natal e Réveillon**, que são picos em qualquer dia da semana (Prefeitura: 70% a 95%).
- **Feriado e feriadão:** a rotina não reduz sozinha a estadia do período. A 7 dias da noite, com a pousada abaixo de 50% nas noites do feriado, o relatório **sugere ao dono** liberar 1 noite (ou 2 nos de 3) e só grava com decisão dele (`excecao: "dono"`). O validador barra estadia abaixo da exigida (sábado 2; feriado pela lista de feriados ou pelo calendário).
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
- **Saída da escada:** com 50% ou mais vendido na data (`regra_f_saida_vendido`), o percentual negativo da data vai a 0% (ou ao passo da flutuação 4c, se ela mandar subir), mantendo `min_price` e `min_stay`. **Nunca apagar a substituição:** apagar tira o percentual do bloco de suavização e levanta as datas vizinhas (13/10: a média do bloco da Queen 7 iria de R$ 595 para R$ 776). Apagar só quando todas as datas do bloco já saíram da escada e nenhuma tem piso de proteção. Antes, confirme que é reserva e não bloqueio.
- A saída da escada **não vale** para datas com preço por decisão do dono, como "perto do mercado" ou "domingo no mínimo para lotar" (motivo começando com "Dono"). Nelas o preço fica até a noite ficar à frente da meta da janela (seção 4c). Aí sobe 10%, um passo por noite por dia. Esclarecido em 06/10: a prioridade do dono é lotar.

**Regra C, Afrodite**
- Noite livre a até 13 dias: −30% (`regra_c_desconto`) sobre o calculado, em percentual com piso de R$ 1.500.
- Afrodite fica acima da Queen (2) nos feriados.

**Regra G, Balcony (sem banheira; dono, 05/10; nova versão do dono em 07/10)**
- A Balcony fica **10% abaixo** (`regra_g_desconto`) da suíte com banheira mais barata e livre da Villa na mesma data: a VK2, ou a VK7 se a VK2 estiver esgotada.
  - Pedido do dono (07/10): "colocar uma diferença de 10% para a que não tem banheira".
- Nunca fica acima da suíte com banheira livre (o validador barra).
- Nunca fica abaixo do limite de segurança: R$ 630 (70% do mínimo de referência).
- **Piso do anúncio e os 10%:** com o mínimo da Balcony igual ao da VK2 (R$ 765), os 10% abaixo são impossíveis em dia de semana (alvo R$ 689). Decisão de 14/10: o mínimo do anúncio da Balcony passa a **R$ 690** (−9,8%, dentro do ±15% semanal e acima dos R$ 630), a gravar na rotina completa de 14/10 com `quartos.json` e `referencia_semanal` no mesmo commit; a partir daí o alvo da Regra G vale até R$ 690 sem exceção.
- **D0 a D7: piso e teto** iguais ao alvo (o ajuste de ocupação da própria Balcony sobe o preço acima do alvo: 08/10, R$ 689 → R$ 740). Esgotada: piso e teto sempre. Além de D7: piso; teto quando a suavização semanal a puxar acima da suíte.
- Como a suavização da Balcony junta a semana inteira, o alvo de cada data vai no piso (`min_price`), com o percentual do bloco calculado no `percentual.py`. A cada rotina, refaça o alvo quando as suítes mudarem de preço.
- No feriado, a Balcony segue o piso de vitrine (R$ 800 na vitrine = R$ 1.510) e nunca sobe por causa desta regra.
- **Balcony esgotada na data (13/10):** o preço natural dela numa noite esgotada pode passar da suíte (16 e 17/10: R$ 1.459 e R$ 1.661 contra a VK7 a R$ 1.366 e R$ 1.553). Ponha piso e teto na data (cerca de 10% abaixo da suíte com banheira livre mais barata), para um cancelamento não reabrir a Balcony acima da suíte.
- **Referência quando a VK2 está esgotada:** a VK7. Se a VK2 reabrir por cancelamento, ela volta ao piso (R$ 765 em dia útil) e a Balcony pode ficar acima dela até a rodada seguinte; refaça o alvo na rodada seguinte. O validador compara com a referência vigente.
- **Varredura da Regra G:** em toda rotina completa, nos 60 dias inteiros (não só nos 21 dias das faixas), comparando com a suíte com banheira livre mais barata da data; o `ler_recalculo.py` avisa quando a Balcony passa da suíte.

**Queen (2) = Suíte Panorâmica II: sempre entre a Moinhos e a Panorâmica I (dono, 21/10)**
- Ordem do dono: "Os valores da Suíte Panorâmica II devem sempre estar entre a Moinhos e a Panorâmica I." No PriceLabs: Moinhos = Queen (7), Panorâmica I = Double, Panorâmica II = Queen (2).
- Noite a noite, o preço da Queen (2) fica entre o menor e o maior dos dois, pelo `scripts/ordem_recanto.py`:
  - piso da data = o maior entre o piso da regra (dono ou feriado) e o menor dos dois;
  - teto = 75% do caminho do menor para o maior, perto da Panorâmica I.
  - Tudo em `percent_base`.
- Em toda rotina completa, `ordem_recanto.py auditar` com os recálculos do dia; noite fora da faixa (tolerância de R$ 5) é regravada no mesmo dia. **Esta regra vence as outras na Queen (2):** flutuação 4c, concorrência hidro+vista e +10% pelo piso não mexem na Queen (2) fora da faixa.
- Substitui a regra antiga de "sem desconto" (19/10). Aquela regra nasceu da "anomalia de 0,34", que em 21/10 se viu ser a tarifa fixa 50067466 na Booking (pendência do dono). O `fator_real` da Queen (2) volta a 0,42, como o resto do Recanto.
- A base do anúncio da Queen (2) (R$ 1.870, acima da Double, R$ 1.480) só muda por ordem do dono. A sugestão está no relatório: levar a cerca de R$ 1.330 em dois passos de até 15% por semana, para a faixa precisar de menos teto.
- Continua fora das Regras F e I.

**Suíte Afrodite: sempre a mais cara do Recanto (dono, 22/10)**
- Ordem do dono: "A Suíte Afrodite tem que ser a mais cara, com valor mais alto da Recanto, pois ela é considerada super luxo."
- Toda noite, a Afrodite fica acima da mais cara das outras três (Moinhos/Queen 7, Panorâmica I/Double, Panorâmica II/Queen 2). Isso é garantido pelo piso da data, em `percent_base`, no `ordem_recanto.py`, junto com a faixa da Panorâmica II. Distância (dono, 27/10, "Pode ser"):
  - domingo a quinta fora de feriado: pelo menos **20%** (`Afrodite.premio_minimo_semana`; antes 5%);
  - sexta, sábado e feriado: pelo menos 5% (`Afrodite.premio_minimo`).
- **Fim de semana fora de feriado (dono, 27/10):** a Afrodite fica na faixa alta do mercado sem passar do topo, R$ 1.600 a R$ 1.800 por noite na vitrine da Booking (`parametros.afrodite_fds_publico`; enviado = vitrine ÷ 0,59, ~R$ 2.712 a R$ 3.051). Acima disso o `ordem_recanto.py` põe teto de R$ 1.800 na vitrine e piso de R$ 1.600; a ordem vence a faixa (o teto nunca fica abaixo de 5% sobre a mais cara das outras). Motivo: ADR realizado da Afrodite (R$ 535) abaixo da Panorâmica I (R$ 601), ocupação de 30% em 30 dias e vitrine de fds ~R$ 2.100 contra mediana da classe A com hidro de ~R$ 1.270 a 1.310 e topo ~R$ 2.000.
- Feriados: a Afrodite segue a Regra J e as faixas de feriado (topo do mercado), sem a faixa de fim de semana.
- A Regra C (−30% a até 13 dias, piso R$ 1.500) e qualquer corte só valem acima desse piso.
- Ordem completa do Recanto: Moinhos ≤ Panorâmica II ≤ Panorâmica I < Afrodite.

**Feriados** (as noites e os pisos estão em `quartos.json` → `feriados`)
- Piso de valor real por noite: preço × `fator_real`; na Balcony, preço × `fator_vitrine`.
- **Pisos em preço enviado** (piso real ÷ `fator_real`; Balcony ÷ `fator_vitrine`), para não recalcular toda vez:

  | Piso real | Queen 7, Double, Afrodite e Queen (2) (0,42; Queen 2 desde 21/10) | (antes Queen 2 a 0,34) | VK7 e VK2 (0,39) | Balcony (vitrine 0,53) |
  |---|---|---|---|---|
  | R$ 800 (limite) | R$ 1.905 | R$ 2.355 | R$ 2.052 | R$ 1.510 |
  | R$ 1.000 (padrão de feriado novo) | R$ 2.381 | R$ 2.941 | R$ 2.564 | R$ 1.887 |

- Cada feriado em `quartos.json` → `feriados` traz `piso_real` (null = só o limite de R$ 800) e uma `nota` com o motivo quando foge do padrão (Finados 2026: +20% e 3 noites do dono em 03/10; Consciência Negra 2026: R$ 800 porque em 10/10 o mercado estava a 23% a 36 dias e o Recanto já acima da mediana com hidro). O histórico dos feriados passados vai para `aprendizados.md`.
- **Regras de proteção nunca levam validade (`lead_time_expiry`)** (10/10): a regra por data com validade de N dias deixa de valer a N dias da noite, e uma gravação em cima dela herda a validade (em 08/10 a Queen 7 caiu a R$ 949 por isso). **Antes de gravar em qualquer data**, leia a regra existente (`get_listing_date_overrides`, 7 chamadas na rotina completa); se tiver `lead_time_expiry`, apague e recrie sem validade. O recálculo só confirma a proteção com `dso_flag 1` e `min_price` igual ao gravado.
- **Noites esgotadas também recebem piso** (um cancelamento reabre a noite); o percentual negativo nelas tira o peso da noite alta da média do bloco.
- O piso padrão de um feriado novo é R$ 1.000 reais. A Skill pode mudar o piso pelos dados (concorrentes, ritmo de vendas), mas nunca abaixo de R$ 800 reais.

**Regra J, lotação e overbooking → topo do mercado (dono, 27/10: "quando estivermos com overbooking assim, ou a pousada lotada, essa suíte, suba o valor para o top de mercado para que possamos evitar que entrem reservas, e se entrar a reserva, que entre com o valor mais alto do mercado. Coloque isso dentro da nossa skill para sempre executarmos diariamente essas regras.")**
- **Gatilhos**, noite a noite, de amanhã em diante, em toda rotina (leve e completa):
  - a suíte está esgotada no calendário (vendidas ≥ unidades);
  - há overbooking na lista de reservas (reservas confirmadas na noite > unidades), mesmo que o calendário não acuse (ele mostra no máximo a lotação);
  - a pousada está lotada: ≥ 90% das unidades vendidas na noite (`parametros.regra_j.limiar_pousada`). Aí as suítes que ainda têm unidade livre também vão ao topo.
- **Topo do mercado** = maior preço público por noite do quarto com hidro da classe A no período (`dados/concorrentes_hidro_vista.json` → `maximo_a`). Sem esse dado: o maior preço das buscas de concorrentes da coleta que cobrem a noite. Sem nenhum dado: o preço atual × 1,5. Enviado = topo ÷ fator público da Booking da pousada (Recanto 0,59; Villa 0,589), nunca abaixo do preço atual nem acima do máximo do anúncio; piso = teto.
- **Precedência:** vence a escada por reserva, a flutuação 4c, a ordem do Recanto e a Regra G naquela suíte e noite. A suíte esgotada sai da ordem do Recanto e da Regra G, que seguem valendo entre as suítes que ainda vendem.
- **Quando a noite reabre** (cancelamento ou overbooking resolvido): `topo_mercado.py restaurar` devolve o preço de antes (guardado no motivo) como piso, com o teto no máximo do anúncio; a partir daí valem as outras regras.
- **Overbooking** vai sempre para o topo do relatório, com as noites e o número de reservas. A Skill não cancela nem move reserva: o dono decide.

## 4b. Estratégia por pousada e por suíte, pela demanda (dono, 07/10)
Pedido do dono: "ajustar o preço de acordo com a demanda que estamos recebendo em cada hotel separadamente, com uma estratégia bem definida para cada hotel e cada suíte".

**Como classificar** (só na rotina completa, com calendário recalculado; o resultado vai em `quartos.json` → `demanda`):
- **Alta:** ocupação de 15 e de 30 dias acima da meta (50% e 35%) e ritmo forte: diárias vendidas nos últimos 14 dias de pelo menos 1,5 vez o número de unidades do quarto.
- **Baixa:** ocupação de 15 e de 30 dias abaixo de 60% da meta, ou semanas futuras com 0% a 12%.
- **Média:** o resto.
- **Histerese:** a classe só muda com o critério valendo em **duas rotinas completas seguidas** (saída de alta: 15 dias abaixo da meta em duas seguidas). A rotina leve nunca reclassifica, só anota "candidata" no relatório. A classe "alta" da VK7 (dada em 13/10 numa rotina leve) fica como candidata confirmada só se a rotina completa de 14/10 repetir o critério.

**O que a classe faz** (complemento da seção 4c; a faixa alvo da noite vem só da sazonalidade):
- **Alta:** o passo pode subir até a faixa alvo +1 mesmo "na meta" (+5%) e até alvo +2 à frente da meta; a Regra I vale só para a última unidade; no feriado, a noite quase esgotada sobe e a fraca segura. Base do anúncio +5% a +10% por semana na revisão de segunda, dentro do ±15%.
- **Média:** segue a 4c sem deslocamento.
- **Baixa:** o passo pode descer até a faixa alvo −1 já "na meta" (−5%), nunca abaixo de "baixo" fora de D0 a D3; fim de semana no padrão de qualidade (abaixo só com exceção de mercado). Base −10% a −15% por semana na revisão de segunda.
- A Regra I vale para todos os quartos com `regra_i`, em qualquer classe (na alta, só a última unidade).
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
- O nosso preço entra pelo que o hóspede vê (vitrine): enviado × fator de vitrine do tipo de dia (`quartos.json`).
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
- Os passos entram sempre em **percentual com piso**, por bloco de suavização (`percentual.py`), e por cima deles continuam valendo: limites de segurança, padrão de qualidade de R$ 1.000, piso de feriado (R$ 800 reais), Regras G, H e I e o ±15% por semana da base.
- **Combinação com a escada (Regra F):** a Regra F dá o patamar inicial de uma data que entra no plano (percentual por antecedência); a flutuação move um degrau por rodada a partir do preço vigente, dentro da faixa alvo ±1. Quando as duas discordam, vale o passo da flutuação (ele já olha ocupação e mercado); a escada só manda em data sem substituição ainda.
- **Saída da escada (Regra F, 50% vendido) não apaga a substituição:** zera o percentual negativo e mantém piso e estadia mínima (apagar levanta o bloco inteiro: Queen 7, 13/10, média de R$ 595 para R$ 776). **Gatilho de subida único** (substitui os antigos 67% e 70%): ocupação da noite de meta mais 10 pontos para cima: 0 a 6 dias 80% (6 de 7, 5 de 6, 2 de 2); 7 a 14 dias 60% (5 de 7, 4 de 6); 15 a 29 dias 45% (4 de 7, 3 de 6); 30 a 44 dias 35%; 45 dias ou mais 25%. Quartos de 1 e 2 unidades usam a ocupação das suítes com banheira da pousada na noite (0, 50 e 100% enganam). O 70% continua só como o limiar de "não iniciar corte da Regra I".
- **Um passo por noite por dia:** um passo só conta como medido depois de chegar aos canais (envio diário ou Sync Now, conferido na Booking, nunca pelo `user_price`) e de passar uma rodada; até lá a noite não recebe segundo passo na mesma direção. Em D0 a D1 o pedido de Sync Now sai na primeira linha do relatório.
- **Sem classificação, sem passo:** noite sem faixa (script falhou, "sem dado de mercado", feriado ou evento sem busca própria, noite fora do calendário) não recebe passo de flutuação; valem travas, pisos de proteção, Regras A, G, H e I e as regras de feriado. Cortes com mais de 14 dias só permitem subir ou segurar.
- **Classe de demanda com histerese:** classificada só na rotina completa com calendário recalculado; ritmo forte = diárias vendidas nos últimos 14 dias de pelo menos 1,5 vez o número de unidades; a classe só muda com o critério valendo em duas rotinas completas seguidas; rotina leve só anota "candidata".
- **Classe × faixa alvo:** a faixa alvo vem só da sazonalidade; a classe desloca o teto da subida e o piso da descida (alta pode chegar a alvo+1 mesmo na meta; média fica no alvo; baixa pode descer a alvo−1 já na meta).
- **Evento estimado** (sem data confirmada em `sazonalidade.json`): usa a faixa do período de fundo até D-90; de D-90 em diante, sem confirmação, a rotina abre "confirmar evento" no relatório e não sobe a faixa por ele; com `confianca: baixa` o alvo do evento nunca passa de alvo+1 do fundo.
- **Orçamento de recálculo** (3 por quarto por dia): 1 medir (07:53), 1 detalhar quando houver mudança, 1 conferir; rotina leve só recalcula quarto com reserva, cancelamento ou mudança manual, e só com saldo; sem saldo, sem passo (só travas e Regra I em D0 a D1 com dado da Booking), "não conferido (limite de recálculo)".
- **Exceção de mercado não é um passo:** quando o passo bate no padrão de qualidade, a rotina para no padrão; a exceção só entra pelos critérios da seção de feriados, com `revisar_em` na rotina completa seguinte.
- **Fator de vitrine por tipo de dia** (`fator_vitrine` e `fator_vitrine_fds` em `quartos.json`): Recanto 0,59 em dia de semana e feriado, 0,61 em sexta e sábado; Villa 0,53 e 0,66. Recalibrados na revisão semanal pela busca da Booking dos nossos hotéis.
- **"Topo" e "base" da faixa:** o alvo de preço do Recanto é o corte superior da faixa alvo e o da Villa é o corte inferior; o passo percentual leva até lá, nunca além da faixa alvo mais uma.
- Recanto (5 estrelas, nota 8,4) opera no topo da faixa alvo; Villa (3 estrelas, nota 8,1) opera na base.
- **Modo por faturamento (dono, 14/10; `quartos.json` → `modo`, por pousada):** a revisão de segunda (e o dia 1) compara o livro do mês com os níveis de `dados/metas_faturamento.json` (mínimo do dono, realista e máximo) no ritmo esperado (40% do realista no dia 1, 65% no dia 15, 80% a 7 dias do fim) e grava o modo: **volume** quando o livro está atrás do menor dos dois limiares (realista ou mínimo do dono), **preco** quando está no maior ou acima, **padrão** entre eles. `faixas_de_preco.demanda_efetiva` aplica: volume = todos os quartos da pousada agem como classe baixa; preco = como classe alta; padrão = classe de cada quarto. As travas (padrão de qualidade, pisos de feriado, descontos da Booking, Regra H, ±15% da base) não mudam com o modo. A rotina leve nunca muda o modo.
- **Como medir o livro (dono, 19/10: "Sim"):** número principal = soma do `rental_revenue` das reservas `booked` (canceladas fora), rateada em partes iguais pelas noites (`no_of_days`; no PriceLabs o `check_out` é a última noite, não a saída) e somada por mês de estadia, **mais as reservas que vêm sem `rental_revenue` (quase todas do canal "outros") pelo `total_cost`**. É diária bruta, antes da comissão da OTA e sem extras. No relatório mostre também, em uma linha, a leitura só com `rental_revenue` (piso) e a com o Airbnb pelo `total_cost` (o PriceLabs recebe só ~60% do valor do Airbnb). O modo por faturamento usa o número principal. Nunca compare a soma da API com o Report Builder.
- **Fonte preferida do faturamento e da ocupação real (dono, 19/10): o Beds24 direto** (`scripts/beds24.py`, API V2 só leitura, variável `BEDS24_TOKEN` com token de longa duração, ou `BEDS24_REFRESH_TOKEN`). Enquanto a credencial não existir, vale o PriceLabs pelo método acima. No Beds24, conte "Confirmado" + "Novo" como ocupado e mostre "Confirmado" sozinho ao lado; "Inquiry" e "Pedido" ficam fora. Detalhes em `pricelabs/beds24-acesso-direto.md`.
- **Trava de lotação (dono, 14/10: "Vamos trabalhar para isso. Porém devemos sempre ficar de olho na lotação."):** a meta de faturamento nunca passa por cima da ocupação. (1) Por noite, continua valendo a flutuação 4c: noite atrás da meta da janela (7 dias 70%, 15 dias 50%, 30 dias 35%) desce um degrau em qualquer modo; o modo preço só muda o que acontece **na** meta ou à frente dela. (2) Por pousada, o modo **preco** só vale se a ocupação da pousada estiver na meta em pelo menos duas das três janelas (7, 15 e 30 dias); se não estiver, fica em **padrão**, mesmo com o livro na meta do dono. (3) A rotina completa confere essa condição todo dia e pode rebaixar preco → padrão (nunca subir o modo; subir é só na revisão de segunda). (4) O relatório abre sempre com a ocupação de 7/15/30 dias por pousada contra a meta, antes do faturamento.

## 5. Referência hidro+vista (dono, 19/10) e mediana da Booking
- O posicionamento de preço é o da seção 4c (faixa alvo da sazonalidade; topo para o Recanto, base para a Villa), **mais a aproximação da referência hidro+vista** abaixo. Não existe outra régua.
- **Referência hidro+vista (dono, 19/10: "vamos tentar nos aproximar mais dos Hotéis que tem Banheira de Hidromassagem e Vista para as Montanhas na Booking . e estão as menos de 3 km do Centro do Capivari"):** hotéis da Booking a até 3 km do centro do Capivari (-22.7288, -45.5797) com hidro **no quarto** e vista para as montanhas, as duas confirmadas pela Booking (classe A; lista e medianas em `dados/concorrentes_hidro_vista.json`, procedimento em `mercado.md`). **Base diária (dono, 20/10):** "vamos buscar ao menos 50 hotéis que estão no nosso perfil, banheira, vista, café da manhã, a 3 km do centro. Diariamente quando formos fazer a busca de valores. Para usar essa base para fazermos ajustes mais dentro do mercado". A mediana vem da busca diária (`mercado-diario.md`); a escada e o PDF do dono calibram o `r_hidro` de cada hotel. O alvo de cada noite é a **mediana do preço do QUARTO COM HIDRO** da classe A na vitrine (2 adultos, 1 quarto; dono, 19/10 tarde: "aplique os filtros dentro da Booking"), medida pela escada de preço mínimo (`mercado.md`); nunca o quarto mais barato de cada hotel, que em 19/10 deu medianas 20% a 66% abaixo e cortes errados, nunca abaixo dos pisos do dono (R$ 500 de semana e R$ 850 em sexta e sábado no preço final; feriado com o piso do período e nunca abaixo de R$ 800 reais).
  - Nós mais de 10% acima da mediana e a noite **não** à frente da meta da janela (+10 pontos): desce em direção à mediana, **no máximo 15% por rodada**, pelo teto da noite (`max_price` em `percent_base`). Se o piso vigente da data (regra por data ou personalização de fim de semana de 150% da base) estiver acima do novo teto, o item leva também o piso logo abaixo do teto; senão o piso vence e nada muda (erro de 19/10 no Q2 e na VK2 em 04-05/12).
  - Nós mais de 8% abaixo da mediana e a noite na meta: +10% pelo piso.
  - Senão, segura. Essa aproximação é o passo da noite naquele dia (4c: um passo por noite por dia); trava de lotação, Regras G, H e I e o padrão de qualidade continuam valendo.
  - A Queen (2) não recebe corte nem por teto (sem desconto). Noite fechada no calendário sem reserva (bloqueio) fica fora. A Villa não aparece na busca com hidro: a vitrine dela é estimada pela VK7 × `fator_vitrine`. Depois de cortar a VK7 ou a VK2, a Balcony volta a 10% abaixo da suíte livre mais barata (Regra G).
  - No Recanto todos os quartos têm banheira, então o preço dele na busca já é de quarto com hidro. Período sem escada (só quarto mais barato) não gera corte.
- A mediana da Booking (com hidro e nota 9 ou mais para o Recanto; geral para a Villa) serve só para (a) a exceção de mercado do padrão de qualidade (critérios na seção 4, feriados) e (b) o relatório ao dono. Nossas notas: Recanto 8,4; Villa 8,1.
- Domingo e meio de semana fracos (mediana do mercado abaixo do nosso mínimo): mínimo do quarto e, de D0 a D3, Regra I.

## 6a. Decisões permanentes do dono
- 26/09: modo automático. "Sempre apresente a análise e aplique a estratégia. Só pare de aplicar caso eu avise para parar."
- 25/09: ofertas da Booking obrigatórias; descontos de OTA intocáveis; Genius até o nível 3.
- 06/10: autonomia total, aprendizado automático e rotinas nesta conversa; Regras H (sábado sozinho fechado) e I (última hora); feriado com piso padrão de R$ 1.000 reais, nunca abaixo de R$ 800.
- 07/10: "Ajuste tudo que está lançado com preço fixo no PriceLabs para percentual, para usarmos as ferramentas do PriceLabs de flutuação de preços. Faça assim sempre." (feito em 07/10, 103 datas).
- 07/10 11:46: estratégia por pousada e por suíte, de acordo com a demanda de cada uma (seção 4b).
- 07/10 13h: Balcony 10% abaixo da suíte com banheira mais barata e livre (Regra G).
- 08/10: padrão de qualidade. Sexta, sábado e feriado das suítes com banheira a partir de R$ 1.000 por diária, "mas de olho no mercado", e "sempre trabalhe com percentuais".
- 08/10: "Pode igualar a suavização da Balcony às outras suítes" (só pela tela; pendência do dono).
- 13/10: preços qualificados em cinco faixas de mercado, sazonalidade prevista para 2026 e 2027 e flutuação percentual por faixa e ocupação contra a meta; pesquisa de mercado feita por agentes de IA (seção 4c); estratégia consolidada em `estrategia.md` (14/10).
- 19/10: o livro do mês inclui as reservas sem valor no PriceLabs pelo valor total da reserva ("Sim"); detalhe em 4c, "Como medir o livro".
- 19/10 (tarde): "Faça tudo que precisar para sermos o mais precisos possíveis e atingirmos nossos objetivos." O Beds24 direto passa a ser a fonte das reservas, da ocupação real e do faturamento; ocupação real = "Confirmado" + "Novo" (as novas têm fatura e ocupam o quarto), com as confirmadas mostradas ao lado; "Inquiry" (consulta do Airbnb) fica fora. O PriceLabs segue como fonte de preço e do calendário de venda.
- 20/10: "Serve para todo o piso": os pisos de R$ 500 (domingo a quinta) e R$ 850 (sexta e sábado) no preço final valem para todos os hóspedes, inclusive o Genius 3. "Não": as promoções da Booking ficam como estão. Pisos por data regravados até 30/04/2027 (418 noites).
- 27/10: Regra J. Suíte lotada ou com overbooking, ou pousada lotada, vai ao topo do mercado, em toda rotina (seção 4, `scripts/topo_mercado.py`).
- 26/10 (tarde): escada de subida por reserva até o alto padrão (tabela da seção 6b; `scripts/escada_reservas.py`, `parametros.escada` em `quartos.json`).
- 28/10: "Ajuste a escada de aumento de preço de forma proporcional sempre para chegarmos ao valor da média dos tops do mercado com nossas características sempre." A escada passa a valer em toda noite futura com venda nova, de qualquer quarto, e o alvo é a **média dos 3 maiores preços do quarto com hidro da classe A** (hidro no quarto e vista, até 3 km do Capivari) na vitrine da Booking, ÷ fator público (Recanto 0,59; Villa 0,589); Balcony 90% do alvo da VK2 e sempre 10% abaixo da suíte com banheira livre mais barata (Regra G). Cada venda move o preço na reta até o alvo: novo = alvo − (alvo − atual) × livres depois ÷ livres antes; a última unidade chega ao alvo e a Regra J leva a suíte esgotada ao topo. Só sobe; cancelamento desce pela mesma reta, até o piso do dono (R$ 300 no Genius 3 até 08/11; depois R$ 500 dom-qui e R$ 850 sex-sáb). Alvos de 28/10 (vitrine por noite): semana R$ 1.839; fds R$ 1.989 (06-08/11) e R$ 2.084 (27-29/11); Finados R$ 2.244; Consciência Negra R$ 2.405; Natal R$ 1.775; Réveillon R$ 2.728; Aniversário de SP R$ 2.251 (`dados/concorrentes_hidro_vista.json`: `topo_a` e `referencias_topo`; refazer na revisão de segunda). `scripts/escada_reservas.py plano`.
- 26/10: "1. Sim" à proposta de rever Genius e promoções da Booking: o dono revisa na extranet o que se soma ao Genius 3 (~38% sobre o público), para o público descer até perto do mercado (~R$ 340 em dia útil) sem ninguém pagar menos de R$ 300. Substitui o "Não" de 20/10 sobre as promoções. A mudança é do dono na extranet (a Skill não mexe em Genius nem em promoções); depois dela, a Skill recalcula o enviado pelo novo fator do hóspede mais descontado.
- 20/10: buscas na Booking só pelo conector ou pelo PDF que o dono salva no navegador dele; nada de navegador automático, Playwright ou browser-harness (a Booking bloqueia e proíbe nos termos).
- 19/10 (noite): referência de preço = hotéis da Booking com hidro no quarto e vista para as montanhas a até 3 km do centro do Capivari (seção 5); plano pelo `scripts/concorrencia_hidro_vista.py`, refeito toda segunda.
- 14/10 (tarde): metas mensais por pousada: baixa temporada Villa R$ 150 mil e Recanto R$ 220 mil; alta temporada Villa no mínimo R$ 300 mil e Recanto R$ 350 mil (lidas como mês; alta = dezembro e janeiro, confirmado pelo dono em 14/10: "Sim janeiro é alta", e, no inverno, junho a agosto). A temporada do dono define a meta do mês; o nível de demanda de cada noite continua vindo de `sazonalidade.json`. Três níveis de operação (mínimo do dono, realista, máximo) e modo por faturamento (4c).
- 14/10: metas de faturamento por temporada e por feriado para os próximos 6 meses (`dados/metas_faturamento.json`, documento `pricelabs/metas-de-faturamento-2026-2027.md`); acompanhamento na revisão de segunda e no dia 1; só o dono muda as metas, a Skill recalibra a previsão.
- 14/10 (noite): "Lembre-se tem ser correções percentuais": nenhuma correção pelo mínimo, base ou máximo do anúncio; tudo em percentual por data (o mínimo da VK7 e da King Spa 2 voltou a R$ 850 e R$ 765, com pisos por data até 30/04).
- 14/10 (noite): suítes com banheira em sexta e sábado a partir de R$ 850 no preço final (seção 4, "Piso de fim de semana"); sem exceção de mercado.
- 14/10 (noite): suítes com banheira de domingo a quinta a partir de R$ 500 no preço final, em percentual (seção 4, "Piso de dia de semana"); vale para a Regra I.
- 14/10 (noite): metas confirmadas pelo dono, inclusive o Recanto R$ 350 mil na alta acima do máximo calculado ("Sim . Esse é o objetivo . Vamos trabalhar para isso . Porém devemos sempre ficar de olho na lotação ."): metas mantidas e trava de lotação (4c).
- 14/10 (noite): "Trabalhe sempre com percentuais nunca com valores absolutos no pricelabs." Piso e teto por data saem como variação % sobre a base (`percent_base`), nunca em reais; preço já era percentual desde 07/10. Base, mínimo e máximo do anúncio seguem em reais como âncora (premissa dita ao dono). Todas as regras por data existentes foram convertidas em 14/10 (seção 4, "Forma de gravar").
- 21/10: "Temos que sempre levar em consideração a precificação do mercado como um todo também. Vamos continuar fazendo como planejamos nas skills." O histórico próprio (2024, `dados/historico_proprio.json`) mostra potencial e serve de referência, mas o preço de cada noite continua ancorado no mercado (faixas 4c, referência hidro+vista da seção 5 e busca diária), nunca em "repetir 2024". Plano de 12 meses em `references/plano-12-meses.md`.
- 21/10: "Vamos rodar o plano agora e já atualizar tudo para os próximos 12 meses e manter tudo em monitoramento. Inclusive restrições de permissão de check-in e check-outs, mínimo de dias da estadia." Pisos do dono e de feriado e estadia mínima (sábado 2; feriados pela lista) gravados em percentual até 31/12/2027 e auditados todo dia pelo `calendario_12m.py` (horizonte rolante de 12 meses). As restrições de chegada e saída do sábado (Regra H) seguem no Beds24, com o dono: o PriceLabs recusa chegada/saída por data sem a personalização ligada na tela ("Enable check-in/check-out customization first"); a skill audita o Beds24 e aponta os sábados a estender.
- 21/10: "A cada 15 dias sempre faça a análise dos 12 meses futuros." Revisão quinzenal pelo `revisao_12m.py` (SKILL.md 4c), na rotina completa do dia em que vencer; fotografias em `dados/revisao_12m.json`, relatórios em `pricelabs/revisao-12-meses/`.
- 21/10: "Os valores da Suíte Panorâmica II devem sempre estar entre a Moinhos e a Panorâmica I. Faça a verificação se isso está correto na Recanto." Na verificação, a Panorâmica II estava acima da Panorâmica I em 435 de 437 noites. Regra nova na seção da Queen (2), aplicada pelo `ordem_recanto.py`.
- 22/10: "A Suíte Afrodite tem que ser a mais cara, com valor mais alto da Recanto, pois ela é considerada super luxo." Regra na seção "Suíte Afrodite"; aplicada pelo `ordem_recanto.py` (5% sobre a mais cara das outras).
- 27/10: "Pode ser" (proposta de reposicionamento da Afrodite): dias de semana 20% acima da mais cara das outras do Recanto (antes 5%); fim de semana fora de feriado na faixa alta do mercado, sem passar do topo (vitrine R$ 1.600 a R$ 1.800); feriados no topo. Regra na seção "Suíte Afrodite"; aplicada pelo `ordem_recanto.py`.
- A regra de sábado com valor real de pelo menos R$ 800 (02/10) **não vale como regra geral**: as decisões de 06/10 a superaram.

## 6b. Decisões do dono por data (vencem; apagar quando passarem)
| Decidida em | Datas alvo | O que vale | Vence em |
|---|---|---|---|
| 03/10 | Finados, 30/10 a 02/11/2026 | +20% e 3 noites nos 7 quartos (feito na tela). Não sobrescrever sem motivo forte; a flutuação só mexe por cima do +20% com registro | 02/11/2026 |
| 06/10 16h | Villa, 06 a 25/10/2026 | estratégia agressiva: mínimos VK7 R$ 850, VK2 e Balcony R$ 765 (corte de 15%); dias úteis e domingos no mínimo; fins de semana perto da mediana geral; sexta 23/10 com 1 noite | 25/10/2026; depois vale a 4c salvo nova ordem |
| 06/10 | Recanto, 24/10/2026 | "24/10 a −20%": substituído em 14/10 pela flutuação 4c (a noite segue a faixa alvo e a ocupação) | 24/10/2026 |
| 26/10 tarde (28/10: proporcional ao topo, em toda noite; ver 6a) | os 7 quartos, noites com regra "Dono 25/10" ou "Dono 26/10" (dias úteis 26/10 a 08/11 e fds 06-07/11) | **Escada por reserva:** "a cada reserva que entrar, vamos aumentando o preço gradualmente [...] começar com esse mínimo [...] e alavancar até chegar no valor de alto padrão". Cada reserva nova sobe 7% o piso = teto do quarto nas noites da estadia (`escada_reservas.py`), até o teto de alto padrão (R$ 500 dom-qui / R$ 850 sex-sáb no Genius 3; Balcony 90% do teto da VK2). Noite de hoje não sobe; lote do Beds24 e edição da equipe não contam; cancelamento desce um degrau. No Recanto, refaça a ordem na mesma noite (`escada_reservas.py ordem`). Análise nas 4 rotinas leves (10:53, 13:53, 16:53 e 19:53) e na completa | 08/11/2026 (depois valem os pisos do dono) |
| 26/10 | Recanto (4 quartos), dias de semana 26-29/10, 02-05/11 e 08/11/2026 | "Vamos completar as reservas. Valor mínimo de diária de 300 reais": R$ 300 é o que o hóspede Genius 3 paga ("Ninguém paga menos de 300"; fator 0,41). Piso = teto por data: Moinhos R$ 739, Panorâmica II R$ 747, Panorâmica I R$ 755 e Afrodite R$ 800 ("Seguir a ordem": 5% acima da Panorâmica I), ou seja, Genius 3 R$ 303 / 306 / 310 / 328 e público ~R$ 486 / 492 / 497 / 526. Afrodite abaixo do limite de segurança por ordem do dono (`limites_seguranca.excecoes_dono`, limite R$ 800 até 08/11). Substitui o "público ~R$ 450" nessas noites; a Villa não muda | 08/11/2026 |
| 25/10 | os 7 quartos, 26/10 a 08/11/2026 | "lotarmos as diárias em aberto nas próximas 2 semanas": dias de semana (26-29/10, 02-05/11, 08/11) com piso e teto por data; ajuste do dono às 17:40 UTC: "Público ~R$ 450", ou seja, a Moinhos a ~R$ 455 no preço público da Booking (R$ 692 enviados; Genius 3 ~R$ 284) e os outros pela ordem (Panorâmica II R$ 731, Panorâmica I R$ 740, Afrodite R$ 1.060, VK7 R$ 710, VK2 R$ 711, Balcony R$ 635), abaixo do piso de R$ 500; fds 06-07/11 Panorâmica I ~R$ 760 e Moinhos 06/11 ~R$ 720, abaixo do piso de R$ 850. Finados mantido com os +20%. Ordem do Recanto e limites de segurança valem | 08/11/2026 |
| 23/10 17h | os 7 quartos, 23 a 25/10/2026 | "Vamos colocar valores que vão vender essas suítes entre hoje e domingo": piso e teto fixados por data (percent_base) em preço de última hora. Sex e sáb: Moinhos ~R$ 553, Panorâmica I ~R$ 654, Afrodite ~R$ 756, Balcony ~R$ 424 e VK2 ~R$ 482 no preço final. Dom: Moinhos ~R$ 385, Panorâmica I ~R$ 425, VK7 ~R$ 376 (limite de segurança) e Balcony ~R$ 336. A ordem do Recanto e a Regra G continuam valendo | 25/10/2026 |
| 23/10 | Recanto, Suíte Moinhos (Queen 7), 23 e 24/10/2026 | piso de R$ 700 no preço final, em vez de R$ 850 (min_price 42%, R$ 1.666 enviados), e sábado 24/10 com 1 noite (Regra H liberada pelo dono só nesta suíte). As outras suítes e a ordem do Recanto não mudam | 25/10/2026 |
| 06/10 | semana de 06 a 19/10 | "lotar esta semana e a próxima": dias úteis fracos no mínimo (Regra F D9 a D13). **Perguntar ao dono se é permanente**; até resposta, vale "mínimo do quarto quando a noite estiver atrás da meta (4c)" | 19/10/2026 |

## 7. Pendências
A lista única (dono, tela e Skill) é `dados/pendencias.json`; o relatório escolhe dali os 3 itens mais urgentes do dono. Não repita pendências aqui nem no `SKILL.md`.

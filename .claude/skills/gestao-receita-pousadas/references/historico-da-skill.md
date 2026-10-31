# Histórico da Skill

Cada mudança feita na própria Skill (regras, parâmetros, scripts, fluxo) entra aqui: data, o que mudou, por quê (evidência) e como desfazer. A entrada mais nova fica em cima.

## 31/10/2026 08:40: ajustes do motor na primeira rotina das 07:53 com ele ligado
- **Por quê:** as buscas de concorrentes do dia vieram sem a mediana dos hotéis com hidro (a mediana geral ficou 9% a 34% abaixo) e, ao rodar o plano de novo com as regras gravadas às 05:29, a proteção repropunha baixar pisos de noites vendidas e "soltar" tetos que só diferiam pelo arredondamento; a Balcony de 04/11 virava um passo sem efeito.
- **O que mudou:**
  - `scripts/ancora_mercado.py`: `substitui()` — busca sem mediana com hidro medida não troca a medida enquanto ela tiver até `pace.idade_max_dias` (10) dias; medida nova sempre entra.
  - `scripts/pace.py`: `protecao_esgotada` só sobe o piso, lê piso/teto da regra vigente quando o calendário não traz e compara o teto com folga de 1% da base; `sem_efeito()` tira do plano e do ledger o item igual ao vigente.
  - `scripts/comum.py`: `vendidas_total` aceita `multi_unit_occupancy` (calendário da coleta).
  - Testes novos: âncora (medida x geral), proteção só sobe e regra vigente, `sem_efeito`, ocupação da coleta.
  - Docs: `regras.md` (âncora, proteção, item sem efeito), `aprendizados.md` (31/10).
- **Revisão independente (09:00, 3 revisores + verificação):** 3 falhas confirmadas e corrigidas: `pace.vigente()` (piso/teto vigentes da regra quando o calendário não traz, usado no passo de descida, na proteção e em `sem_efeito`; sem nenhum dos dois a proteção não grava); `coerencia` varre a Regra G nas 60 noites (com `bloqueios`) e aplica no feriado só o lado de cima, sem passar do piso de feriado; faixa sem item = a do validador (85,5% a 90% + 1% da base). `validar_plano.validar_balcony` avisa Balcony acima de 90% também no feriado. Relatório do motor gerado depois de `sem_efeito`. Teste novo `test_revisao_rotina_3110_coleta_resumida_e_regra_g_em_todas_as_noites`; 83 OK.
- **Resultado:** âncora sem mudança; 8 passos novos gravados (registro de 31/10 08:40) e 6 noites da Balcony na Regra G (registro de 31/10 09:10); conferência da gravação de 05:29 completa (123/123).
- **Como desfazer:** reverter o commit; a âncora volta a aceitar a busca mais nova mesmo sem hidro medida.

## 31/10/2026 01:00: motor de pace LIGADO (dono: "Pode ligar o motor de pace ... Vamos vender.")
- **O quê:**
  - `quartos.json`: `parametros.motor.modo` = "ligado", `regra_j_ativa` = false, `ordem` dono-2026-10-31-ligar-motor; `pace.protecao_percentil` 90.
  - `ordens_dono.json`: escada (26/10) e Regra J (27/10) com `ate` 30/10 (encerradas); ordens de 25/10 ("público ~R$ 450") e 26/10 ("R$ 300 no Genius 3") de `preco_por_data` para `piso_por_data` com `pisos_enviados` e `noites` (02-05/11 e 08/11; fds 06-07/11 da Moinhos e da Panorâmica I numa ordem separada); ordem nova dono-2026-10-31-ligar-motor.
  - `ordens.py`: `piso_da_ordem` (tipo piso_por_data; o menor se houver mais de uma).
  - `pace.py`: `piso_com_ordem` (o piso da ordem substitui os pisos padrão, nunca abaixo de `limite_seguranca`); `protecao_esgotada` (p90, nunca abaixo dos pisos PADRÃO, `piso_extra`/`teto_regra`); `regra_afrodite` (mais cara das outras +20%/+5%; faixa de fds R$ 1.600-1.800 públicos até 95 dias, no motor e na proteção); descida com teto vigente leva o teto junto; `ajustar_tetos` (teto livre = sem max_price; regra com teto ou validade vai para o plano `_apagar`, gravado antes, e o item herda min_stay); `ler_regras` e `--regras` (obrigatório fora da sombra); coerência com a Panorâmica II, sem puxar a Panorâmica I pela última unidade da Moinhos, sem item quando a regra já está cumprida, Balcony dentro da faixa da Regra G (85,5% a 90%); ledger só com itens de pace.
  - Testes novos: `test_protecao_esgotada_e_ajuste_de_tetos`, `test_piso_da_ordem_teto_vigente_e_ultima_unidade`, `test_coerencia_ultima_unidade_e_panoramica_2`, `test_protecao_ignora_piso_de_venda_e_respeita_afrodite` (78 OK).
  - Docs: `regras.md` (versão 31/10, precedência, Regra J encerrada, 4d "Ligado em 31/10", 6a), `estrategia.md` (motor ligado, D3 decidida), `SKILL.md` (escada e Regra J encerradas; passo 4d em modo ligado; rotina leve), `pendencias.json` (D3 resolvida; D1 com proposta de R$ 350 no Genius 3 de D0 a D3; D2 no Beds24; Balcony R$ 600 não aplicado: abaixo do limite de segurança e sem efeito com a Regra G).
- **Revisão independente antes de gravar** (workflow de 3 lentes + verificação, 11 agentes): 4 achados graves confirmados e corrigidos: `regra_afrodite` toda noite no motor; Panorâmica II com o próprio piso na coerência; subida com teto no alvo; ledger registrado na gravação. Junto: `efetivo()` (valor que o PriceLabs aplica em percentual inteiro) na coerência; Balcony escolhida em percentual inteiro dentro da faixa da Regra G; coerência cita a ordem por data que cobre a noite; `validar_plano.py` exige `abaixo_piso` em `libera` da ordem citada. Teste `test_revisao_3110_afrodite_toda_noite_q2_no_piso_e_libera` (79 OK).
- **Primeira gravação:** 31/10 05:26-05:29 BRT, 23 regras apagadas e 123 noites gravadas (registro de 31/10).
- **Por quê:** ordem do dono (D3). Os ajustes vieram da primeira rodada real: as noites de 02 a 08/11 estavam fora do motor e presas no piso = teto da escada; a proteção usava o piso de venda (Afrodite de 08/11 iria a R$ 937); a coerência criava item que baixava o piso vigente da Afrodite; a Balcony descia abaixo do que a Regra G permite.
- **Como desfazer:** `motor.modo` = "sombra" (o motor só relata) e `regra_j_ativa` = true; as ordens de 25 e 26/10 voltam a `preco_por_data` em `ordens_dono.json`; os preços gravados se desfazem pela tabela do registro de 31/10.

## 30/10/2026 19:30: reformulação da estratégia (v2.0), ordens do dono com validade, âncora de mercado e motor de pace em sombra
- **O quê (pedido do dono: "Melhore nossas estratégias ... maior valor de estadia porém ... os 2 hotéis lotados ... melhore nossa skill"):**
  - `dados/ordens_dono.json` (novo) e `scripts/ordens.py` (novo): ordens literais do dono com id, quartos, período, tipo (`preco_por_data`, `regra`, `manual`) e o que liberam. `validar_plano.py`: `excecao: "dono"` exige `ordem_id` vigente (ERRO sem ele); noite congelada por mudança manual dá ERRO. `escada_reservas.py`, `topo_mercado.py` e `ordem_recanto.py` passam o `ordem_id` da ordem que implementam.
  - `scripts/ancora_mercado.py` (novo) e `dados/ancora_mercado.json` (novo): percentis p25/p50/p75/p90/máx do preço público por noite dos hotéis com hidro (30 noites com busca própria em 30/10; `por_tipo` por mês para as demais).
  - `scripts/pace.py` (novo): curvas por tipo de noite, status, percentil-alvo por pousada, passo proporcional limitado por antecedência, pisos do dono como trava ("presa no piso"), "à frente nunca desce", "D0 nunca sobe", cadência com `dados/passos.json`, coerência (Panorâmica II, Afrodite, Balcony), modo sombra. Sombra de 30/10 em `pricelabs/pace-sombra/2026-10-30.md`.
  - `quartos.json` (versão 2026-10-30T23): `parametros.fator_publico` (Recanto 0,63; Villa 0,65), `parametros.pace`, `parametros.motor` (modo sombra, `regra_j_ativa`), `regra_j.fator_publico` atualizado, Queen 2 `fora_do_motor`, `afrodite_fds_horizonte_dias` 95.
  - `ordem_recanto.py`: teto de fim de semana da Afrodite só até hoje + 95 dias (`horizonte_teto_fds`; `faixa_afrodite(..., teto_ate)`); no PriceLabs, os 50 tetos de 2027 gravados às 11:41 BRT foram apagados e os pisos regravados às 19:22 BRT (sábados com `min_stay` 2).
  - `pace.py`: `novo` da linha do relatório usa o mesmo arredondamento do item (teto para baixo, piso para cima).
  - Docs: `estrategia.md` v2.0 (reescrita), `regras.md` (versão 30/10, precedência no topo, seção 4d, 6a), `SKILL.md` (arquivos, rotina leve, pedido do dono, passo 4d, relatório 3b, revisão semanal), `aprendizados.md`, `pendencias.json` (D1, D2, D3, Natal na Booking, Balcony R$ 600, Beds24 token).
  - Testes: `TestOrdensDono`, `TestAncoraMercado`, `TestPace`, horizonte da Afrodite em `test_afrodite_fim_de_semana_e_feriado`; 74 testes OK.
- **Por quê:** diagnóstico de 30 dias (ocupação 53% contra 34% do mercado, diária média 16% abaixo, 80% a 96% dos dias no piso, regras sobrepostas e dominadas pelos pisos; detalhes em `estrategia.md` seção 2 e `aprendizados.md` 30/10). Painel de 3 desenhos independentes com juízes; síntese = uma régua, uma curva, um passo, pisos como trava explícita.
- **Como desfazer:** `parametros.motor.modo` já é "sombra" (nada gravado pelo motor); para voltar o validador ao comportamento antigo, remova a checagem de `ordem_id` em `validar_plano.validar_data` (não recomendado: a trava "preço fixo só com ordem do dono" deixa de ser verificável). Tetos da Afrodite em 2027: `ordem_recanto.py plano` com `afrodite_fds_horizonte_dias` 366 regrava.

## 08/10/2026 10:20: padrão de qualidade de R$ 1.000 em sexta, sábado e feriado
- **O quê:**
  - `quartos.json`: `limites_seguranca.padrao_qualidade_diaria` = 1000 (só o dono muda) e lista `excecoes_mercado`.
  - `validar_plano.py`: ERRO quando o piso, preço ou teto de sexta, sábado ou feriado de suíte com banheira fica abaixo do padrão sem o campo `mercado` (texto com número, mínimo de 20 caracteres); aviso quando tem o campo ou a ordem do dono; ERRO se o padrão estiver ausente ou zerado; teto sozinho também passa pela checagem.
  - `percentual.py`: piso mínimo por data (sexta e sábado das suítes com banheira = padrão).
  - 26 testes (eram 20).
  - `regras.md`: bloco "Padrão de qualidade" com critérios de exceção de mercado, base ainda sem confirmação do dono, piso de fim de semana do PriceLabs, Regras F, G e I, seções 1, 4b, 5, 6 e 7. `SKILL.md`: passo 4, limites e relatório. `aprendizados.md` e `ferramentas.md`.
- **Por quê:** decisão do dono (08/10): "devem sempre ser a partir de 100 reais" (lido como R$ 1.000, único número plausível: R$ 100 fica abaixo de qualquer mínimo de quarto), "mas de olho no mercado" e "sempre trabalhe com percentuais". Três revisores independentes conferiram a mudança; os achados que procederam (teto sozinho, texto de mercado sem número, parâmetro editável pela Skill, `percentual.py` sem o piso, critérios de exceção vagos, feriado esgotado fora da varredura) foram corrigidos no mesmo dia.
- **Como desfazer:** o dono edita `limites_seguranca.padrao_qualidade_diaria` (a Skill não pode zerar: o validador barra) e a Skill volta os pisos das 16 datas ao mínimo do quarto.

## 08/10/2026 08:20: conferência da estadia mínima de sábados e feriados
- **O quê:** `SKILL.md` passo 4 passou a conferir a estadia mínima de sábados e feriados em todos os quartos. `aprendizados.md` ganhou 5 fatos: vitrine ÷ enviado, percentual de dia útil que sobe com as vendas, blocos semanais da Balcony, estadia mínima da Queen 7 e mercado de 08/10. `quartos.json` ganhou a nota de demanda de 08/10 (versão 2026-10-08T08).
- **Por quê:** a Queen (7) tinha sábados de 07/11 a 28/11 e os feriados de novembro com 1 noite sem regra por data; corrigido nesta rotina.
- **Como desfazer:** tirar a linha nova do passo 4.

## 07/10/2026 20:05: varredura de feriado depois de mudar a base
- **O quê:** `SKILL.md` passo 4: a varredura de feriado inclui as noites sem regra por data e roda de novo sempre que a base de um quarto mudar. `aprendizados.md`: 2 fatos novos (efeito da base no feriado e o envio extra das 17:01).
- **Por quê:** a base −15% da Queen (7) deixou 13–14/11 em R$ 1.838 (cerca de R$ 772 reais), abaixo do limite de segurança. O erro foi achado na conferência das 19:53 e corrigido.
- **Como desfazer:** tirar a frase nova do passo 4 (não recomendado).

## 07/10/2026 12:45: Regra G nova (Balcony 10% abaixo) e varredura de feriados
- **O quê:**
  - `regras.md`: Regra G reescrita (Balcony 10% abaixo da suíte com banheira mais barata e livre).
  - `quartos.json`: `parametros.regra_g_desconto` = 0.10.
  - `validar_plano.py`: erro se a Balcony ficar acima da suíte ou mais de 5% abaixo do alvo; teste novo, 20 OK.
  - `SKILL.md` passo 4: varrer as noites de feriado contra o limite de segurança.
- **Por quê:** decisão do dono (07/10, "diferença de 10% para a que não tem banheira") e 6 noites de feriado achadas abaixo do limite de segurança.
- **Como desfazer:** voltar a Regra G para "nunca abaixo da suíte com banheira mais barata e livre" e remover o parâmetro.

## 07/10/2026 12:30: estratégia por pousada e por suíte, pela demanda
- **O quê:**
  - `regras.md` seção 4b: classificação alta/média/baixa e o que fazer em cada nível.
  - `quartos.json`: campo `demanda` por quarto; base da Queen 7 em 1173 e da Queen (2) em 1870.
  - `SKILL.md` passo 4: classificar a demanda antes de aplicar as regras.
  - `percentual.py`: ignora o percentual antigo da data e aceita `escala` (mudança de base).
- **Por quê:** pedido do dono em 07/10, 11:46.
- **Como desfazer:** base da Queen 7 em 1380 e da Queen (2) em 1700; remover a seção 4b e o campo `demanda`.

## 07/10/2026 08:20: piso do feriado de 12/10 em R$ 800 para todos os quartos
- **O quê:** `quartos.json`: N. S. Aparecida com `piso_real` 800 (era 1000), sem piso por quarto. `percentual.py`: no feriado, o piso é o alvo. O teste do piso de feriado ficou independente do valor.
- **Por quê:** 24 h sem venda de feriado, a 2 dias, com 19 de 31 unidades livres na sexta. Recanto 13% acima da mediana com hidro da Booking (R$ 2.710 contra R$ 2.400 em 2 noites). A regra manda ficar de 10% a 25% abaixo. O limite de segurança de R$ 800 reais foi respeitado.
- **Como desfazer:** `piso_real` 1000 e as datas de 09 e 10/10 do Recanto de volta para os pisos de R$ 2.390, R$ 2.950 e R$ 3.190.

## 07/10/2026 08:15: preço por data sempre em percentual
- **O quê:**
  - `regras.md`: nova forma de gravar o preço (percentual com piso; fixo só com ordem do dono) e Regras I, F e C reescritas para percentual.
  - `validar_plano.py`: barra preço fixo sem `excecao: "dono"`. Com percentual, as travas (segurança, Regra I, feriado, Regra G) olham o piso (`min_price`). Aceita `max_price`.
  - `ler_recalculo.py`: no percentual, confere se o preço ficou dentro de piso e teto; avisa Regra G.
  - Script novo `percentual.py`: percentual por bloco de suavização.
  - Testes: 20, todos OK.
  - `SKILL.md` (passo 4) e `ferramentas.md` atualizados.
- **Por quê:** pedido do dono em 07/10, para o PriceLabs flutuar os preços: "Faça assim sempre".
- **Como medimos o PriceLabs:** o percentual entra depois dos ajustes de ocupação e antes da suavização. A suavização tira a média do bloco (domingo a quinta e sexta e sábado; Balcony, a semana inteira) e pula datas esgotadas. O `min_price` da data substitui o piso de fim de semana.
- **Como desfazer:** reverter o commit. Os preços no PriceLabs têm um "como desfazer" no registro de 07/10.

## 06/10/2026 19:58: envio aos canais depois do recálculo
- **O quê:** `SKILL.md` (passo 7) passa a mandar conferir o `last_date_pushed` depois de gravar e a pedir o "Sync Now" ao dono quando a mudança vier depois do envio diário e mexer em D0 a D3 ou for um corte grande. `ferramentas.md` e `aprendizados.md` corrigidos: o envio extra perto de 1 h depois do recálculo não é garantido.
- **Por quê:** as mudanças da Villa das 16:10 não tinham sido enviadas às 19:56 (último envio às 08:49), e a Booking mostrava os preços antigos.
- **Como desfazer:** remover a linha nova do passo 7 do `SKILL.md`.

## 06/10/2026 16:20: Villa agressiva e piso de feriado por quarto
- **O quê:**
  - `quartos.json`: mínimos da Villa em 850, 765 e 765.
  - Feriado de 12/10 com `piso_real_por_quarto` (Villa a R$ 800).
  - `validar_plano.py` passa a ler o piso por quarto, com teste novo (15 testes OK).
- **Por quê:** pedido do dono de estratégia agressiva para a Villa em 3 semanas.
- **Como desfazer:** voltar os mínimos para 1000, 900 e 900 no PriceLabs e no `quartos.json`, e remover `piso_real_por_quarto`.

## 06/10/2026 14:05: esclarecimento da Regra F
- **O quê:** a saída da escada (50% vendido) não vale para preços definidos por decisão do dono. Nesses preços, a Skill segura até 70% de ocupação e depois sobe no máximo 10% por rodada.
- **Por quê:** a Double em 16, 17 e 18/10 chegou a 50% horas depois do corte pedido pelo dono para lotar. Voltar ao algoritmo levaria a Double de R$ 1.400 para cerca de R$ 2.600 e desfaria a decisão dele.
- **Como desfazer:** remover o item novo da Regra F em `regras.md`.

## 06/10/2026: v1, criação
- **O quê:** a Skill nasceu a partir do registro de 25/09 a 06/10 e desta conversa. As regras foram consolidadas em `regras.md`; os dados de máquina, em `dados/quartos.json`. Também foram criados os scripts (ocupação, validação e conferência do recálculo) e o workflow de coleta. A ampliação de `.claude/settings.json` (liberar gravação no PriceLabs, Booking, git e scripts) foi bloqueada pela proteção da sessão: fica para o dono fazer. A rotina `trig_01BMLPUb6e9LDw1Xx1oYy3iT` passou a chamar a Skill.
- **Decisões do dono:** autonomia total, aprendizado automático e rotinas nesta conversa.
- **Limites de segurança:** preço nunca abaixo de 70% do mínimo de referência; base e mínimo com no máximo ±15% por semana; feriado nunca abaixo de R$ 800 de valor real.
- **Como desfazer:** reverter o commit da criação. A rotina volta ao texto antigo, salvo em `historico-da-skill.md` (seção "Texto antigo da rotina").

### Texto antigo da rotina (até 06/10)
Rotina "Agenda PriceLabs (5x ao dia)", `trig_01BMLPUb6e9LDw1Xx1oYy3iT`, cron `CRON_TZ=America/Sao_Paulo 53 7,10,13,16,19 * * *`. Início do texto antigo, para restaurar se preciso:

```text
Hora da ANÁLISE E AGENDA DIÁRIA do PriceLabs (rotina "Agenda diária PriceLabs", pedida pelo usuário). Responda em português do Brasil, cordial e direta, como gestora de receita e growth hacker da Pousada Recanto dos Moinhos e da Villa Dolce Amore.

## Regra de ouro: só leitura
- Use só ferramentas de LEITURA do conector PriceLabs (get_*). Não chame update_*, delete_*, accept_nudge, refresh_listing_pricing, map/unmap_listings, set_neighborhood_data_source nem update_customizations.
- Não faça commit nem push nesta análise.
- Só aplique uma mudança se o usuário responder aprovando aquele item (ex.: "aprovo 1 e 3"). Aí aplique só o aprovado, registre em pricelabs/registro-de-mudancas.md (antes, depois e como desfazer), faça commit e push na branch claude/pricelabs-hotel-occupancy-ku6zn5.
- Economize tokens: poucas chamadas, sem reler arquivos grandes.
- Se as ferramentas do PriceLabs não estiverem disponíveis (procure com ToolSearch "PriceLabs"), diga isso em uma linha e peça para reconectar o conector em claude.ai. Não invente números.

(Seguiam: tabela dos 7 quartos, metas, regras de negócio, passos 1 a 6 e o formato da resposta em 6 itens; o formato foi mantido no SKILL.md.)
```

## 09/10/2026, 14:20 — Lista de reservas atrasa
- **Motivo:** a Queen 7 em 11/10 vendeu por R$ 949 às 21:01 de 08/10 e a lista de reservas só mostrou a venda às 10:59 de 09/10; três coletas seguidas diziam "sem reservas novas".
- **Mudança:** `SKILL.md` (rotina leve e passo 2: `desde` = 2 dias antes de hoje, comparar por ID e pelo calendário), `ferramentas.md` e `aprendizados.md`.

## 10/10/2026, 08:00 — Regras por data com validade (`lead_time_expiry`)
- **Motivo:** auditoria independente (3 revisores) achou que a regra "Véspera de feriado" de 11/10 e 19/11 (validade de 3 dias) vencia a 3 dias da noite e carregava para o PriceLabs a validade junto com qualquer piso gravado em cima. A correção das 08:02 de 09/10 (Queen 7) e a da VK7 das 16:57 nunca valeram; a conferência passou porque o preço de noite esgotada parecia certo.
- **Mudança:** `ler_recalculo.py` marca ❌ "regra por data INATIVA" quando `dso_flag` não é 1 ou o `min_price` recalculado difere do gravado (teste novo `TestRecalculo`, 27 testes ok; reproduz o caso da VK7). `SKILL.md` (passos 6 e 7), `regras.md` (seção 4, feriados) e `aprendizados.md`. Dados: 11/10 e 19/11 recriadas sem validade nos 7 quartos; pisos de segurança em Finados (Queen 7, VK2, Queen 2) e Consciência Negra (Queen 7, VK2).

## 13/10/2026, 13:10 — Calendário velho, VK7 em demanda alta, Regra I e Regra G esclarecidas
- **Motivo:** a coleta das 07:53 usou calendários de 19 a 22 h para Double, Queen 2, VK7 e Balcony (eles só recalculam no envio da tarde). A VK7 estava com 53% em 7 dias e na verdade 74%; a Balcony tinha 3 de 6 vendidas em 16 e 17/10 e estava esgotada. A Regra I da VK7 (R$ 700 em 13 a 15/10) foi gravada com esse calendário e desfeita 3 horas depois. Uma auditoria independente (2 revisores) aprovou o plano corrigido com ajustes, todos incorporados.
- **Mudança:**
  - `scripts/mesclar_recalculo.py` novo (lista os quartos com recálculo velho e junta o recálculo novo no arquivo da coleta) e teste `TestMesclar` (28 testes ok); `SKILL.md` passo 2 e `ferramentas.md`.
  - `regras.md`: Regra I (data com mais de 70% vendida não é cortada e sobe 10% por rodada pelo piso, sem apagar o percentual; em demanda alta só a última unidade), Regra G (Balcony esgotada com piso e teto; referência VK7 quando a VK2 esgota; alvo não vai abaixo de R$ 765) e perfil da Villa (4b).
  - `quartos.json` (versão 2026-10-13T13): VK7 de média para **alta** (7d 73,5%, 15d 51,4%, 30d 44,8%; margem de 1,4 ponto no 15d).
- **Como desfazer:** voltar `demanda` da VK7 para "media" e apagar os bullets novos de `regras.md`.

## 13/10/2026, 17:10 — Rotina leve recalcula os quartos com calendário velho
- **Motivo:** a coleta das 16:53 saiu idêntica à das 13:53 e o recálculo manual mostrou 8 noites vendidas e 1 cancelamento na Queen 7 (e vendas na Double e na Balcony). Uma rodada leve "sem mudança" era só calendário parado.
- **Mudança:** `SKILL.md` (rotina leve): rodar `mesclar_recalculo.py --velhos --horas 2`, recalcular os quartos listados com unidade livre de D0 a D14 e mesclar antes de decidir. `aprendizados.md` e `quartos.json` (nota de demanda).
- **Como desfazer:** apagar o bullet novo da rotina leve no `SKILL.md`.

## 13/10/2026, 22:10 — `mesclar_recalculo.py` e a Afrodite
- **Motivo:** o merge marcava a Afrodite (unidade única, `mu_occupancy` "-1" no recálculo) como 0% vendida e tirava a Afrodite do total do hotel.
- **Mudança:** no merge, unidade única passa a contar como vendida quando `num_bookings` é maior que 0; teste novo em `TestMesclar` (28 testes ok).
- **Como desfazer:** voltar `mesclar_recalculo.py` ao commit a3d14b7.

## 14/10/2026, 02:20 — Faixas de preço de mercado, sazonalidade 2026-2027 e flutuação percentual (pedido do dono, 13/10)
- **Motivo:** o dono pediu para qualificar os preços em cinco faixas (muito baixo a muito alto), prever a sazonalidade de cada época, feriado e dia, flutuar em percentual para ficar dentro das metas e usar a IA para pesquisar a concorrência inteira.
- **Mudança:**
  - `dados/sazonalidade.json` (novo): 77 períodos de 14/10/2026 a 31/12/2027 com nível, faixa alvo, estadia e antecedência, 55 feriados e eventos, regras gerais e mercado mês a mês; `scripts/sazonalidade.py` (consulta e validação). Pesquisa pelo workflow `sazonalidade-campos` (5 agentes: feriados, eventos, estações, PriceLabs, síntese).
  - `dados/mercado_faixas.json` (novo): cortes dos quintis por data e por conjunto (geral, pares do Recanto, pares da Villa), da varredura `varredura-concorrencia` (16 de 38 agentes; o resto fica para a retomada).
  - `scripts/faixas_de_preco.py`: `mercado` (calibra os cortes) e `quartos` (classifica cada noite dos 7 quartos e sugere o passo percentual pela regra de flutuação; respeita Queen 2 sem desconto e o mínimo do quarto). 35 testes ok.
  - `quartos.json`: 12 feriados de 2027. `regras.md` 4c, `mercado.md`, `SKILL.md` (passo 4 e revisão semanal), `.claude/workflows/varredura-concorrencia.js` e `sazonalidade-campos.js`.
- **Como desfazer:** apagar a seção 4c de `regras.md` e os dois arquivos novos em `dados/`; a rotina volta a decidir só pelas Regras A a I.

## 14/10/2026, 08:30 — Consolidação (pedido do dono: "consolide tudo que sabemos e aprendemos e ajuste a skill")
- **Motivo:** três revisores da estratégia e cinco auditores da skill (91 achados, só leitura) apontaram contradições, trechos vencidos, aprendizados fora do código e lacunas de procedimento.
- **Mudança (texto):** `regras.md` reescrito nos pontos: saída da escada zera o percentual e não apaga; gatilho de subida único (meta + 10 pontos por janela; os 67% e 70% caem como gatilho de subida); um passo por noite por dia; Regra I com valores derivados e Balcony R$ 630; 4b com critério numérico e histerese de duas rotinas completas; 4b como complemento da 4c; Regra A não reduz estadia de feriado sozinha; Regra G com piso e teto em D0 a D7 e mínimo da Balcony a R$ 690 (a gravar); Regra H com dia órfão; feriados em tabela atemporal de pisos; §5 vira "mediana só para exceção e relatório"; §6 dividida em permanentes e por data (vencidas apagadas); §7 e pendências em `dados/pendencias.json` (novo, lista única). `estrategia.md` 1.2 alinhada; `SKILL.md` com orçamento de recálculo, lista não importada antes das 11:00, varredura em duas rodadas, limites e pendências só por referência; `mercado.md` e `ferramentas.md` sem datas vencidas; `aprendizados.md` com 18 princípios consolidados e a leitura correta do caso VK2 13/10.
- **Mudança (dados):** `quartos.json` Recanto `fator_vitrine` 0,59 (fds 0,61), notas de piso em Finados e Consciência Negra, Natal e Réveillon como exceção da regra de fim de semana, `estadia_minima` 3 em Finados; `sazonalidade.json` `regras_gerais` → `notas_de_pesquisa`, Villa 8,1.
- **Mudança (código, 40 testes ok):** `validar_plano.py` exige calendário mesclado para a Regra I e barra noite esgotada, data acima de 70% (fora de D0), demanda alta com mais de uma livre, estadia abaixo da exigida em feriado e sábado, duplicatas, Queen 2 sem preço anterior, teto da Balcony acima da suíte, calendário velho (`--horas`, `--aceitar-velho`) e plano de outro dia (`--hoje`); avisa passo acima de 15%, Balcony esgotada sem teto e exceção de mercado vencida. `faixas_de_preco.py` recebe a classe de demanda, ignora cortes com mais de 14 dias para descer, usa a ocupação da pousada em quartos de 1 e 2 unidades, calcula o preço alvo (topo/base) e usa hoje em BRT. `mesclar_recalculo.py` falha quando o recálculo vem vazio e grava `last_refreshed_at`. `ler_recalculo.py` marca dia órfão e confere itens "apagar". `comum.hoje_brt`/`ler_instante`. Workflow `varredura-concorrencia` com `args.rodada`/`conjuntos`.
- **Como desfazer:** `git revert` dos commits de 14/10 da consolidação; os dados de `pendencias.json` voltam para `regras.md` §7.


## 14/10/2026, 11:40 — Primeira rotina completa com a flutuação 4c; mínimo da Balcony R$ 690; classes de demanda
- **Mudança (dados):** `quartos.json` Balcony `min` 765 → **690** (decisão de 14/10, Regra G; `referencia_semanal` segue 765 até a revisão de 19/10 para o ±15% ser medido contra o começo da semana); VK7 `demanda` "alta" confirmada na rotina completa (candidata desde 13/10); notas de classe em Double (alta), Queen 7 (média), VK2 (candidata a alta) e Balcony (baixa). `pendencias.json`: 6 itens da Skill fechados (balcony-min-690, refazer-13-14-11, natal-reveillon-pisos, q7-dias-uteis-25-29-10, recanto-23-24-10, recanto-06-07-11) e 3 abertos (vk2-candidata-alta, push-q7-afrodite-vk2-1410, balcony-24-26-12-min-stay).
- **Como a rotina rodou (para repetir):** 1 recálculo por quarto com `parse_reasons_json: true` serviu para medir e detalhar; `faixas_de_preco.py quartos --dias 60` deu os passos; os cortes passaram por `percentual.py` com o **bloco inteiro** nos alvos (datas do bloco sem alvo entram com o preço atual, porque o cálculo assume 0% nelas); subidas só pelo piso; Balcony com piso e teto por data; nas datas em que a Balcony e a suíte entram juntas no plano, o piso da suíte é o próprio alvo (o validador compara piso com piso). Plano de 270 itens montado por script na pasta de rascunho (`montar_plano_1410.py`, base para `faixas --alvos` / `percentual --plano`).
- **Aprendizados novos:** janela móvel de 24 h do recálculo; envio das 09:52 não garantido; remanejamento no Beds24 no calendário; vitrine do Recanto 0,63 em dia útil (`aprendizados.md`).
- **Como desfazer:** `update_listing_data` Balcony `min` 765; `git revert` do commit da rotina de 14/10.

## 14/10/2026, 16:10 — Metas de faturamento por temporada e por feriado (pedido do dono)
- **Mudança (dados):** `dados/metas_faturamento.json` (novo): metas por mês de out/2026 a abr/2027, por temporada (alta dez–jan; baixa nov, fev e mar) e por 8 feriados, em três cenários (piso, base, meta), com a calibração (set/2026 R$ 324.612; índice de RevPAR contra o mercado 1,48 Recanto / 1,25 Villa) e a fonte. Documento para o dono em `pricelabs/metas-de-faturamento-2026-2027.md`.
- **Mudança (texto):** `estrategia.md` 1.3 (faturamento como métrica de resultado; ocupação segue como métrica de ritmo); `SKILL.md` (revisão semanal compara livro × meta; dia 1 fecha o mês e recalibra; item 8 do relatório); `regras.md` 6a (decisão de 14/10).
- **Achado importante:** o PriceLabs só tem dados dos nossos anúncios desde 27/08/2026 (nada antes); o histórico de 2025 fica só no Beds24 e na Booking. A previsão usa o mercado do ano passado (vizinhanças) × nosso índice medido em set/out.
- **Como desfazer:** apagar `metas_faturamento.json` e reverter o commit.

## 14/10/2026, 17:30 — Metas de faturamento, versão 2 (3 revisores de IA: 25 achados, 6 erros corrigidos)
- **Mudança (código):** `scripts/metas_faturamento.py` (novo): gera `dados/metas_faturamento.json` e `pricelabs/metas-de-faturamento-2026-2027.md` a partir de insumos declarados (mercado do ano passado, set/2026 realizado, livro por mês de estadia, vendido por feriado). Correções dos revisores: índice numa base só (mesmo mês do mesmo ano: set 1,48/1,25; 1 a 14/10 1,25), livro por mês de estadia do Report Builder (nov R$ 97.762, não R$ 142.741), Páscoa pelo efeito líquido da tabela de feriados, outubro = livro + última hora declarada, feriados = vendido + (alvo − vendido) × diária ≥ piso da skill, teto de 95% no Recanto, temporadas alinhadas à sazonalidade (alta = festas 18/12 a 2/1), regra de feriado a 7 dias igual à da skill (50%, decisão do dono, Réveillon fora).
- **Mudança (dados):** `quartos.json` Natal 24 a 26/12 (2 noites) e Réveillon 29/12 a 2/1 (3 noites), alinhados à sazonalidade; Aniversário de SP e Tiradentes com piso R$ 800 reais (feriados fracos; regra permite). Atenção: as noites 26/12, 29/12 e 2/1 passam a exigir piso de feriado nas próximas rotinas (hoje os preços naturais já estão acima).
- **Como desfazer:** reverter o commit; os feriados voltam às janelas anteriores.

## 14/10/2026, 18:30 — Metas do dono por pousada e modo por faturamento
- **Decisão do dono (14/10):** baixa temporada Villa R$ 150 mil e Recanto R$ 220 mil; alta temporada Villa ≥ R$ 300 mil e Recanto ≥ R$ 350 mil (lidas como faturamento mensal por pousada; alta = dezembro e inverno). Pedido: ajustar a estratégia para mínimo, realista e máximo.
- **Mudança (código, 41 testes ok):** `faixas_de_preco.demanda_efetiva` (o modo por faturamento da pousada sobrepõe a classe do quarto: volume = baixa, preco = alta); `metas_faturamento.py` ganha `DONO`, `por_pousada()` (mínimo do dono, realista, máximo, hoje) e a seção 6 do documento.
- **Mudança (dados e texto):** `quartos.json` → `modo` (Recanto e Villa em "padrão"); `metas_faturamento.json` com `metas_dono` e `por_pousada`; `regras.md` 4c (modo por faturamento) e 6a; `estrategia.md` 1 e 2; `SKILL.md` revisão semanal grava o modo.
- **Como desfazer:** `modo` em "padrão" nas duas pousadas e reverter o commit.

## 14/10/2026, 17:30 — Piso e teto por data em percentual da base (regra do dono)
- **Decisão do dono (14/10):** "Trabalhe sempre com percentuais nunca com valores absolutos no pricelabs."
- **Medição:** `percent_base` é variação sobre a base do anúncio (King Spa 2: −39 → R$ 775; 61 → R$ 2.045). A base de conhecimento do PriceLabs dizia "fração da base" e errou; o piloto errado da Queen 7 15/10 foi corrigido em 34 minutos, antes de qualquer envio.
- **Mudança (código, 45 testes ok):** `validar_plano.py` (piso_pct, teto_pct, em_reais, teto_efetivo; payload sem "fixed"; travas com o valor efetivo do percentual); `pisos_percentual.py` novo (gerar e conferir a conversão das regras antigas; corrigido o caso do piso 0%); `ler_recalculo.py` com tolerância de 1,5% no piso.
- **Mudança (PriceLabs):** 332 de 343 regras por data dos 7 quartos convertidas (410 campos), conferidas antes × depois e por um verificador independente por quarto.
- **Mudança (texto):** `regras.md` (forma de gravar e 6a), `SKILL.md` passo 6, `estrategia.md`, `ferramentas.md`, `aprendizados.md`.
- **Como desfazer:** reverter os commits; as regras no PriceLabs voltam a reais com `pisos_percentual.py` invertido (o estado de antes dos 7 quartos está em `pricelabs/snapshot-regras-por-data-2026-10-14.json`).

## 14/10/2026, 18:00 — Janeiro é alta temporada (dono)
- **Decisão do dono:** "Sim janeiro é alta."
- **Mudança:** `metas_faturamento.py` (`DONO.meses_alta` com 2027-01; temporadas: alta = dezembro e janeiro, festas como linha de detalhe, baixa = novembro, fevereiro e março; leitura de janeiro calculada na seção 6), `metas_faturamento.json` e o documento regenerados; `regras.md` 6a.
- **Sem mudança:** `sazonalidade.json` (nível de demanda por noite) e travas.
- **Como desfazer:** tirar 2027-01 de `meses_alta` e regenerar.

## 14/10/2026, 18:20 — Metas mantidas e trava de lotação (dono)
- **Decisão do dono:** "Sim . Esse é o objetivo . Vamos trabalhar para isso . Porém devemos sempre ficar de olho na lotação ."
- **Mudança (texto):** `regras.md` 4c (trava de lotação) e 6a; `estrategia.md`; `SKILL.md` (revisão semanal e rotina completa); documento de metas (seção 6) regenerado.
- **Como desfazer:** apagar o item "Trava de lotação" de `regras.md` 4c.

## 14/10/2026, 20:00 — Piso de dia de semana das suítes com banheira: R$ 500 no preço final (dono)
- **Decisão do dono:** "Vamos ajustar os valores das suítes com banheira para dia de semana, no mínimo de R$500. Preço final nas plataformas. Ajuste isso percentualmente para podermos termos a flutuação de preço natural do Price Labs."
- **Mudança (código, 46 testes):** `validar_plano.py` (`piso_semana_enviado`, `piso_vitrine_semana`: ERRO de domingo a quinta fora de feriado nas suítes com banheira abaixo de R$ 500 ÷ fator de vitrine; vale para a Regra I); testes da Regra I passam a usar a Balcony.
- **Mudança (dados):** `limites_seguranca.piso_vitrine_semana_suites` 500 (só o dono muda); `parametros.fator_vitrine_semana` Recanto 0,63 e Villa 0,53 (medição); mínimo do anúncio VK7 944 e King Spa 2 879.
- **Mudança (texto):** `regras.md` 4 (regra nova), Regra I e 6a.
- **Como desfazer:** zerar `piso_vitrine_semana_suites` só com ordem do dono; as regras por data voltam pelo snapshot da rodada.

## 14/10/2026, 20:45 — Piso de fim de semana das suítes com banheira: R$ 850 no preço final (dono)
- **Decisão do dono:** "Para o fim de semana a diária. Mínima pra sweet, com banheira, deve ser na faixa de R$ 850,00".
- **Mudança (código, 46 testes):** `validar_plano.piso_vitrine_semana(fds=True)` em sexta e sábado fora de feriado; testes do padrão de qualidade atualizados.
- **Mudança (dados):** `limites_seguranca.piso_vitrine_fds_suites` 850; `parametros.fator_vitrine_fds` Recanto 0,61 e Villa 0,66; pendência do padrão de qualidade fechada.
- **Como desfazer:** só com ordem do dono; as regras por data têm o valor de antes no registro.

## 14/10/2026, 21:10 — Correções só em percentual (dono)
- **Decisão do dono:** "Lembre-se tem ser correções percentuais".
- **Mudança (PriceLabs):** mínimo do anúncio da VK7 (944 → 850) e da King Spa 2 (879 → 765) voltou ao valor de antes; o piso de R$ 500 no preço final das noites de domingo a quinta sem regra por data foi para 168 pisos por data em percentual (VK7 90, King Spa 2 78) até 30/04/2027.
- **Mudança (código):** `validar_plano.validar_anuncio` dá ERRO para mudar base, mínimo ou máximo do anúncio sem `excecao: "dono"`; teste atualizado; `regras.md` (forma de gravar, piso de semana, 6a) e documento de metas (modo preço sobe pisos por data, não a base).
- **Como desfazer:** só com ordem do dono.

## 19/10/2026, 11:30 — Revisão semanal
- **Modo por faturamento:** Recanto e Villa seguem em **padrão**. Outubro no dia 19 (ritmo esperado 70%): Recanto R$ 139 mil entre o realista (R$ 99 mil) e a meta do dono (R$ 154 mil); Villa R$ 102 mil entre R$ 92 mil e R$ 105 mil. Trava de lotação: Recanto na meta só em 30 dias (35%), Villa em nenhuma das três janelas.
- **Fatores de valor real:** `fator_real` não mudou (Recanto 0,42, Villa 0,39). Proxy desta semana (valor real por diária ÷ preço do calendário, reservas de 12 a 19/10): Booking Recanto mediana 0,67 (p25 0,56), Airbnb Recanto 0,31; Booking Villa mediana 0,82, Airbnb Villa 0,32. O piso fica pelo pior caso (Airbnb). A proposta de subir o Recanto a 0,45 não foi aceita.
- **Fator de vitrine da Villa:** não medido nesta revisão (sem busca de dia de semana para VK7 e King Spa 2); fica para a semana de 26/10.
- **Pendência encerrada:** base da Double e da VK7 +5%: por ordem do dono de 14/10, base só muda com ordem expressa dele.
- **Varredura de concorrência (rodada 2):** não rodada nesta sessão (a Skill manda rodá-la em sessão à parte).

## 19/10/2026, 12:00 — Livro do mês com as reservas sem valor (dono)
- **Decisão do dono:** "Sim" (à pergunta: passar a reportar como número principal a leitura com as reservas sem `rental_revenue` incluídas pelo `total_cost`).
- **Mudança (texto e dados):** `regras.md` 4c ("Como medir o livro") e 6a; `metas_faturamento.json` → `faturamento`; `quartos.json` → `modo_decisao` refeito com o número principal (Recanto R$ 152 mil, Villa R$ 117 mil em outubro; modo segue padrão nas duas, a Villa pela trava de lotação); pendência `faturamento-script` atualizada.
- **Como desfazer:** voltar o item "Como medir o livro" para só `rental_revenue`.

## 19/10/2026, 14:30 — Leitura direta do Beds24 (dono)
- **Pedido do dono:** verificar de onde vêm os valores das reservas e ler o Beds24 direto pela API, com o consolidado executivo do Beds24 de 19/10 como referência.
- **Mudança (código, 48 testes):** `scripts/beds24.py` (API V2, só leitura, sem dados de hóspedes; `teste`, `reservas`, `janelas`) e 2 testes.
- **Mudança (texto):** `regras.md` 4c (noites por `no_of_days`; Beds24 como fonte preferida do faturamento e da ocupação real); `ferramentas.md`; `pricelabs/beds24-acesso-direto.md`; pendências `beds24-token` (dono) e `beds24-integrar` (Skill).
- **Correção:** livro do mês recalculado com `no_of_days` (outubro R$ 257 mil, não R$ 269 mil); modo segue padrão nas duas pousadas.
- **Como desfazer:** apagar `scripts/beds24.py` e o item da fonte preferida em `regras.md`.

## 19/10/2026, 17:00 — Beds24 como fonte das reservas, da ocupação real e do faturamento (dono)
- **Decisão do dono:** "Faça tudo que precisar para sermos o mais precisos possíveis e atingirmos nossos objetivos."
- **Mudança (código, 50 testes):** `beds24.py` ganhou `livro`, `movimento` e `comparar` (overbooking, bloqueio sem reserva e venda que o PriceLabs não viu); `roomQty` e `lineTotal` conferidos no apiV2.yaml.
- **Mudança (texto):** `SKILL.md` (rotina leve pelo `movimento`; Meça com `janelas` e `comparar`; faturamento pelo `livro`); `regras.md` 6a (ocupação real = Confirmado + Novo); pendências novas do dono (overbooking da King Spa 2 em 24/10, repasse do Airbnb, bloqueio da Balcony em 24/10) e atualização do bloqueio da Villa em 5–7/11.
- **Como desfazer:** voltar os três trechos do `SKILL.md` para o PriceLabs.

## 19/10/2026, 17:05 UTC (14:05 BRT) — Referência hidro+vista a até 3 km do Capivari (dono)
- **Pedido do dono:** "vamos tentar nos aproximar mais dos Hotéis que tem Banheira de Hidromassagem e Vista para as Montanhas na Booking . e estão as menos de 3 km do Centro do Capivari . Faça essa busca para criar o plano de ajuste de preços."
- **Mudança (texto):** `regras.md` seção 5 (régua nova: mediana da classe A, -15% no máximo por rodada pelo teto, +10% pelo piso, travas) e 6a; `mercado.md` (12 hotéis classe A, medianas, como refazer); `SKILL.md` (passo Decida e revisão de segunda); `ferramentas.md`.
- **Mudança (dados e código, 52 testes):** `dados/concorrentes_hidro_vista.json`; `scripts/concorrencia_hidro_vista.py` (plano automático); `validar_plano.py` barra teto abaixo do preço atual na Queen (2) e teto abaixo do piso vigente da data; `ler_recalculo.py` confere item só de piso ou só de teto (antes passava como "—"); a coleta passa a trazer `min_price` e `max_price` do dia; `test_scripts.py` com o `unittest.main()` no fim (8 testes não rodavam com `python3 test_scripts.py`).
- **Como desfazer:** voltar a seção 5 de `regras.md` ao texto anterior e apagar o script e o arquivo de dados.

## 19/10/2026, 18:05 UTC (15:05 BRT) — Referência pelo quarto com hidro (dono: "aplique os filtros dentro da Booking")
- **Mudança (texto):** `regras.md` seção 5: o alvo é a mediana do quarto com hidro, medida pela escada de preço mínimo; período sem escada não gera corte. `mercado.md`: o método da escada, os quartos com hidro de cada hotel e as medianas novas.
- **Mudança (dados):** `dados/concorrentes_hidro_vista.json` com `mediana_a` do quarto com hidro, `mediana_mais_barato`, `quartos_hidro` e `metodo_preco`; o período de 21/10, sem escada, saiu.
- **Como desfazer:** voltar `mediana_a` ao quarto mais barato (não recomendado).

## 20/10/2026, 10:45 UTC — Pisos para o hóspede Genius 3 (dono: "Serve para todo o piso")
- **Mudança (dados):** `quartos.json` → `fator_vitrine_semana` Recanto 0,63 → 0,41; `fator_vitrine_fds` Recanto 0,61 → 0,425 e Villa 0,66 → 0,575 (PDFs do dono de 20/10).
- **Mudança (texto):** `regras.md` (pisos de semana e de fim de semana; 6a).
- **Mudança (PriceLabs):** 418 pisos por data até 30/04/2027 (Q7 166, Q2 122, Double 114, Afrodite 8, VK7 4, VK2 4); validador 0 erro.
- **Como desfazer:** voltar os fatores e regravar os pisos com o validador.

## 20/10/2026, 13:00 UTC — Skill booking-hotel-research adotada (dono: "vamos usar essa Skill")
- **Origem:** pacote enviado pelo dono. Lido por inteiro antes de usar: o script `plan_matrix.py` usa só a biblioteca padrão, não acessa a rede e só cria arquivos novos. Instalado em `.claude/skills/booking-hotel-research/` sem mudanças.
- **Integração:** cadastro `pricelabs/pesquisa-booking/brief.json` (23 hotéis, 10 estadias); 1ª rodada `rodada-2026-10-20` (230 combinações, 41 capturas dos 3 PDFs do dono, `resumo.md`); `base_booking_pdf.py --rodada`; `SKILL.md` (revisão de segunda); `ferramentas.md`.
- **Fontes:** só o PDF do dono e o conector oficial. Sem navegador automático, como a própria skill pede quando há bloqueio.

## 20/10/2026, 14:00 UTC — Cadastro de 77 concorrentes do perfil e busca diária (dono)
- **Pedido do dono:** "vamos buscar ao menos 50 hotéis que estão no nosso perfil, banheira, vista, café da manhã, a 3 km do centro. Diariamente quando formos fazer a busca de valores. Para usar essa base para fazermos ajustes mais dentro do mercado. Vamos incorporar ele em nossa skill".
- **Cadastro** (`pricelabs/pesquisa-booking/concorrentes.json`): 77 hotéis, 39 de classe A (vista confirmada) e 38 de classe B (vista não confirmada).
  - Origem: os links dos 3 PDFs do dono (80 hotéis; saem 5 a mais de 3 km, 1 não encontrado e os nossos 2) mais a classe A da varredura do conector.
  - Hidro no quarto e café: confirmados pelos filtros do PDF.
- **Código:** `scripts/mercado_diario.py` (`planejar` e `consolidar`) e 1 teste; `references/mercado-diario.md` (roteiro do agente); `SKILL.md` (passo diário antes de Decida); `regras.md` seção 5 (base diária).

## 21/10/2026, 12:30 UTC — Levantamento diário de reservas abaixo do planejado (dono)
- **Pedido do dono:** "Faça diariamente esse levantamento para não termos reservas abaixo do que planejamos" e "Considere o valor de desconto nas plataformas".
- **Código:** `scripts/reservas_abaixo.py`. Esperado = price1 do Beds24 × `fator_canal` (Booking: Recanto 0,49, Villa 0,46; Airbnb: 0,41 e 0,31; direto 1,0); alerta abaixo de 70%. Em `quartos.json` → `parametros`: `fator_canal` e `alerta_reserva`.
- **Rotina:** passo em toda rotina, leve e completa (`SKILL.md`), com o resultado no topo do relatório. Nomes de hóspedes só na tela, para o dono.
- **1º levantamento (desde 19/10):** 8 reservas graves, de 17% a 45% do esperado (5 manuais na VK7, 2 da Q2 na Booking e 1 direta da Afrodite), e 3 leves (63% a 66%), feitas antes da alta dos pisos.


## 21/10/2026 — desempenho_mes.py (pedido do dono)
- Novo `scripts/desempenho_mes.py`: reservas feitas no mês (por pousada, canal, quarto, semana e mês da estadia), cancelamentos do mês e estadias do mês contra o mínimo do dono, com a diária necessária nas noites livres. Sem nomes. Entra na revisão semanal; relatório em `pricelabs/desempenho/`.
- Cancelamento sem `cancelTime` (35 de 80 em outubro) usa a data da última alteração.

## 21/10/2026 — Painel semanal (pedido do dono)
- Painel "Desempenho Semanal das Pousadas" (https://claude.ai/artifact/HzGExqdFhYp3CnTe6pvWBf) e `scripts/painel_semanal.py`, que gera os 5 arquivos do painel a partir do Beds24. Atualizado toda segunda na revisão semanal (SKILL.md, modo 4; ferramentas.md, "Painel semanal").

## 21/10/2026 — Plano de 12 meses e histórico próprio (pedidos do dono)
- `references/plano-12-meses.md` (nov/26 a out/27): diagnóstico, histórico de 2024, metas, 5 pilares (preço e estadia, canais, direto, demanda de dia útil, reputação), calendário de execução e governança. Pesquisa de mercado com fontes na seção 11.
- `dados/historico_proprio.json`: totais das planilhas do sistema anterior (2024 e jan-mar/2026), sem dados de hóspedes.
- `dados/metas_faturamento.json`: maio a outubro de 2027 estimados pelo mesmo método, com o mínimo pela temporada do dono (jun-ago = alta), a confirmar.
- `regras.md` §6a: decisão do dono de 21/10 (preço sempre ancorado no mercado como um todo).
- 12 pendências novas do plano em `dados/pendencias.json`.

## 21/10/2026 — Calendário de 12 meses e monitoramento (pedido do dono)
- `scripts/calendario_12m.py` (`plano` e `auditar`): pisos do dono e de feriado e estadia mínima por noite, horizonte rolante de 12 meses, a partir do recálculo do PriceLabs; auditoria das restrições de sábado no Beds24.
- `validar_plano.py`: aceita `check_in`/`check_out` (7 caracteres, só sábado fechado pela Regra H, ou `excecao: "dono"`) e põe no payload.
- `quartos.json` → `parametros.regra_h_restricao` (padrão do Beds24 por quarto).
- SKILL.md passo 4b: auditoria do calendário em toda rotina completa.
- 2 testes novos (59 no total).

## 21/10/2026 — Revisão quinzenal dos 12 meses (pedido do dono)
- `scripts/revisao_12m.py` (`devida` e `rodar`): 13 meses por pousada, com vendido contra o mínimo, entrada em 15 dias, ocupação vendida contra o mercado (PriceLabs), diária e preço médio das suítes; fotografias em `dados/revisao_12m.json`. SKILL.md passo 4c; 1ª revisão (base) em 21/10, a próxima vence em 05/11. 1 teste novo (60 no total).

## 21/10/2026 — Ordem de preço do Recanto (pedido do dono)
- `scripts/ordem_recanto.py`: a Panorâmica II (Queen 2) fica entre a Moinhos (Queen 7) e a Panorâmica I (Double) noite a noite (piso e teto em percent_base). Passo 4b2 do SKILL.md; regras.md seção da Queen (2) reescrita (sai o "sem desconto"; `fator_real` de 0,34 para 0,42). A concorrência hidro+vista deixa de mexer na Queen (2). 1 teste novo.

## 22/10/2026 — Afrodite a mais cara do Recanto (pedido do dono)
- `ordem_recanto.py` passa a garantir também a Afrodite pelo menos 5% acima da mais cara das outras três (piso por data) e usa tolerância de 1% da base (o percentual inteiro do PriceLabs dá degraus de cerca de R$ 19 na Queen 2). regras.md: seção "Suíte Afrodite"; quartos.json: `acima_de` e `premio_minimo`; SKILL.md 4b2. 1 teste novo.

## 23/10/2026 — Ordem do dono libera sábado de 1 noite no validador
- `validar_plano.py`: um item com excecao "dono" passa a liberar o sábado de 1 noite fora de feriado. A política de não vender sábado sozinho é do dono; antes, só a excecao "regra_h" liberava, e um item não podia levar as duas. Teste novo em `test_sabado_sozinho`. Uso: Moinhos 24/10 (dono, 23/10).

## 26/10/2026 — Exceção do dono ao limite de segurança, por quarto e período
- `validar_plano.py`: `piso_absoluto(q, d)` lê `limites_seguranca.excecoes_dono` em `quartos.json` (quarto, de, até, limite, ordem citada) e usa esse limite dentro do período; fora dele volta 70% do mínimo de referência. Só o dono abre exceção. Uso: Afrodite R$ 800 de 26/10 a 08/11 (dono, 26/10: "Seguir a ordem"). Teste novo `test_limite_seguranca_com_excecao_do_dono` (61 testes OK).

## 26/10/2026 — Escada de subida por reserva (pedido do dono)
- `scripts/escada_reservas.py` (novo): `passo` sobe 7% por reserva o enviado de um quarto numa noite, até o teto de alto padrão (pisos do dono no Genius 3; Balcony 90% da VK2); a noite de hoje não sobe; com reservas negativas, desce. `ordem` refaz Moinhos ≤ Panorâmica II ≤ Panorâmica I < Afrodite (+5%). `quartos.json` → `parametros.escada`. SKILL.md (rotina leve) e regras.md (6b e decisões). Teste novo `test_escada_por_reserva` (62 testes OK).

## 27/10/2026 — Beds24 pelo segredo de rede do ambiente
- `beds24.py`: sem BEDS24_TOKEN, BEDS24_REFRESH_TOKEN nem arquivo, chama a API sem o cabeçalho `token` e conta com o segredo de rede do ambiente (Network secrets: cabeçalho `token`, sem prefixo, site beds24.com), que o proxy injeta. 401/403 nesse modo dá a mensagem de como cadastrar. `test_sem_credencial` cobre os dois modos.

## 27/10/2026 — Regra J: lotação e overbooking no topo do mercado (pedido do dono)
- `scripts/topo_mercado.py` (novo): `plano` acha suíte esgotada, overbooking pela lista de reservas e pousada lotada (≥ 90%) e põe piso = teto no topo do mercado (`maximo_a` da classe A, ou o maior preço da coleta, ou o atual × 1,5) ÷ fator público da Booking; `restaurar` devolve o preço de antes quando a noite reabre. `comum.vendidas_total` e `comum.esgotado` (novos). `ordem_recanto.py`: suíte esgotada sai da ordem. `validar_plano.py`: Balcony esgotada sai da Regra G; saiu o aviso "Balcony esgotada sem teto". `quartos.json` → `parametros.regra_j`; `concorrentes_hidro_vista.json` → `maximo_a` nos 5 feriados. regras.md (Regra J e 6a), SKILL.md (rotina leve). Teste novo `test_regra_j_topo_do_mercado` (63 testes OK).

## 27/10/2026 — Afrodite reposicionada (dono: "Pode ser")
- `ordem_recanto.py`: o prêmio da Afrodite sobre a mais cara das outras depende da noite (`premio_afrodite`): 20% de domingo a quinta fora de feriado (`premio_minimo_semana`), 5% em sexta, sábado e feriado. `faixa_fds_afrodite`: em sexta e sábado fora de feriado, acima de `parametros.afrodite_fds_publico[1]` ÷ fator público põe teto (e piso na ponta de baixo da faixa), sem ficar abaixo da ordem. O piso aberto pelo dono em `limites_seguranca.excecoes_dono` também vale no piso da Afrodite (antes o piso da regra subia as noites de R$ 800 para R$ 1.500).
- `escada_reservas.ordem`: prêmio padrão de 20% (passe 0,05 em fds e feriado).
- `quartos.json`: `Afrodite.premio_minimo_semana` 0,2 e `parametros.afrodite_fds_publico` [1600, 1800].
- Testes: `test_afrodite_mais_cara` (20%), `test_afrodite_respeita_excecao_do_dono`, `test_afrodite_fim_de_semana_e_feriado`; 65 testes OK.

## 28/10/2026 — Regra J sem acúmulo
- `topo_mercado.py plano` aceita `--regras Q7=<overrides.json> ...` (no fim da linha, depois dos recálculos): a noite que já tem a Regra J usa o "antes R$ X" do motivo como base do "sem dado × 1,5", e o motivo novo guarda o mesmo preço de antes. Sem isso o multiplicador se acumulava a cada rodada. `ler_regras` serve ao `plano` e ao `restaurar`. Teste em `test_regra_j_topo_do_mercado`.

## 28/10/2026 — Escada proporcional ao topo do mercado (dono)
- `escada_reservas.py`: `alvo` (média dos `escada.top_n` maiores preços do quarto com hidro da classe A ÷ fator público; feriado pelo `topo_a` do período, fora dele pela referência mais próxima do mesmo tipo), `proporcional` (reta até o alvo pelas unidades vendidas), `piso_dono` (piso do cancelamento), `lotes` (3+ reservas no mesmo minuto), `plano` (diferença entre dois recálculos → itens, com a ordem do Recanto e a Regra G em `acompanhar`; Balcony com teto da Regra G). O degrau fixo de 7% fica só sem dado de mercado.
- `concorrentes_hidro_vista.json`: `topo_a` e `topo_a_hoteis` nos 5 feriados; `referencias_topo` (semana 03/11 e 24/11, fds 06-08/11 e 27-29/11; 4 agentes, conector oficial, escada de preço mínimo).
- `quartos.json`: `escada.top_n` 3, `escada.piso_genius` (R$ 300 até 08/11), `nota_proporcional`.
- Teste `test_escada_proporcional_ao_topo`; 66 OK.

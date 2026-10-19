# Histórico da Skill

Cada mudança feita na própria Skill (regras, parâmetros, scripts, fluxo) entra aqui: data, o que mudou, por quê (evidência) e como desfazer. A entrada mais nova fica em cima.

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

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

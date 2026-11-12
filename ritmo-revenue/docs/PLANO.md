# Plano: plugin WordPress de gestão de receita para pousadas ("Ritmo Revenue")

## Contexto

Há cerca de 5 semanas a skill `gestao-receita-pousadas` gerencia sozinha os preços do Recanto dos Moinhos e da Villa Dolce Amore: lê reservas no Beds24, calcula preço por ritmo de venda e mercado, grava no PriceLabs, confere e relata. O que funciona está em 31 scripts Python (13,5 mil linhas, 341 testes), nos dados de `dados/` e nas regras e aprendizados de `references/`.

O dono quer transformar isso num **plugin WordPress completo e 100% editável** (entendi "evitável" como "editável": tudo configurável pelo painel, nada fixo das pousadas dele), para vender a qualquer pousada ou hotel pequeno.

Decisões do dono (11/11):

1. **Conectores da v1:** Beds24 (reservas e inventário; grava só estadia, chegada/saída e disponibilidade) + PriceLabs (preços). Estrutura com adaptadores para outros sistemas depois.
2. **Dados de mercado:** concorrentes cadastrados pelo cliente, preços digitados ou importados por CSV, mais os dados de mercado do PriceLabs. Sem raspagem.
3. **IA:** motor de regras fixo e auditável, que funciona sem IA; assistente opcional com a chave da Claude do próprio cliente.
4. **Venda:** assinatura com chave de licença e atualizações automáticas; módulo de licença pronto para ligar numa plataforma (Freemius ou loja própria), ainda sem fornecedor escolhido.

## O que será construído

- **Onde:** pasta nova `ritmo-revenue/` na raiz deste repositório, na branch `claude/pricelabs-hotel-occupancy-ku6zn5`. Nome provisório "Ritmo Revenue" (slug `ritmo-revenue`, namespace `Ritmo\`, prefixo `ritmo_`); trocar o nome antes do lançamento é simples.
- **Requisitos do cliente:** WordPress ≥ 6.5, PHP ≥ 8.1, contas no Beds24 e no PriceLabs (com a API do PriceLabs ligada).
- **Nada das pousadas do dono fica no código.** A configuração atual dele vira um arquivo de preset usado só nos testes de paridade (fora do zip vendido).

### Arquitetura

```
ritmo-revenue/
  ritmo-revenue.php  uninstall.php  composer.json
  src/Domain/         PHP puro, sem chamadas ao WordPress (testável sozinho)
    Config/ Calendar/ Market/ Pricing/ Relations/ Orders/ Plan/ Alerts/ Report/ Support/
  src/Contracts/      PmsConnector, PricingConnector, MarketDataProvider, LicenceProvider, AiProvider
  src/Connectors/     Beds24/, PriceLabs/, Market/ManualCsv, Licence/Stub, Ai/Claude
  src/Infrastructure/ Db (migrações e repositórios), Http (limites e cotas), Secrets, Scheduler, Lock, Log
  src/Application/    RunPipeline (coletar → medir → decidir → validar → gravar → conferir → registrar → relatar),
                      WriteGate, UndoService, ApprovalService
  src/Admin/ src/Rest/ templates/ assets/ languages/ presets/default.json
  tests/              Unit/ Golden/ Integration/ e2e/ Fakes/ fixtures/
```

- **Validação impossível de pular:** os conectores só aceitam gravar um `ValidatedPlan`, que só o `Validator` consegue criar, e com um `WriteToken` emitido pelo `WriteGate`.
- **Relógio e arredondamento centralizados** (`Clock`, `Num`), para reproduzir o Python (arredondamento bancário, piso para cima, teto para baixo).
- **Painel:** PHP renderizado no servidor + JavaScript simples (sem etapa de build), falando com a REST `ritmo/v1`. Zip pequeno e testável aqui.
- **Agendador:** Action Scheduler embutido; horários configuráveis por propriedade (1 rodada completa + rodadas leves).

### Dados (tabelas próprias `wp_ritmo_*`, várias propriedades por site)

`properties`, `room_types`, `relations`, `periods` (feriados, temporadas, pacotes), `rulesets` (versões imutáveis), `price_factors`, `competitors`, `price_samples`, `market_anchor`, `orders`, `bookings` (sem dados do hóspede), `runs`, `plan_items`, `write_ops`, `step_ledger`, `change_log` (com "como desfazer"), `alerts`, `pending_items`, `api_quota_log`, `snapshots`. Chaves de API cifradas (sodium), campos só de escrita.

### Regras como configuração

Um esquema único (`Domain/Config/Schema.php`) define tipo, faixa, padrão e texto de ajuda de cada campo e gera a validação, os formulários e o importar/exportar.

- **Pisos e segurança:** piso duro = maior entre mínimo do anúncio, fração do mínimo de referência, pisos do dono por tipo de noite e piso de feriado; cada piso diz em que "plano de preço" está (enviado, público, com desconto) e converte pelos fatores medidos; limite de ±15% por semana no anúncio.
- **Ritmo (pace):** curvas por tipo de noite, bandas, percentil alvo por situação, tamanho do passo por antecedência, meia força com mercado fraco, um passo por noite por dia.
- **Escada de vendas:** abertura num percentil do mercado, +X% por unidade depois de uma fração vendida, teto, queda nos últimos dias com ocupação baixa (o `plano_dono.py` vira estratégia genérica por tipo de noite e quarto).
- **Relações entre quartos (grafo genérico):** `at_least`, `between`, `premium_over_max`, `discount_band` (ex.: 85,5–90% do quarto livre mais barato de um conjunto) e `occupancy_switch` (ex.: regra dos 80%). Ciclos recusados ao salvar; ordem reconferida em reais depois do arredondamento.
- **Estadia e chegada/saída:** por dia de chegada, dias fechados, noite órfã, feriados e pacotes; gravado no PMS e no sistema de preço.
- **Proteção de noite esgotada, âncora de mercado (p25–p90, validade, reserva pela mediana do tipo de noite), ordens do dono, congelamento de mudança manual, precedência** (segurança > ordens e pisos > escada e ritmo > coerência).
- **Presets:** `presets/default.json` neutro; `tests/fixtures/presets/parity.json` gerado de `dados/quartos.json`, `regua_dono.json` e ordens ativas.

### Do Python para o PHP

| Python (em `.claude/skills/gestao-receita-pousadas/scripts/`) | PHP |
|---|---|
| `comum.py`, `ancora_mercado.py` | `Calendar/*`, `Market/*` |
| `pace.py` (curva, status, passo, pisos, cadência, proteção, sem efeito) | `Pricing/*` |
| `plano_dono.py`, `escada_reservas.py`, faixas de `faixas_dono.py` | `SalesLadder`, faixas configuráveis |
| `pace.coerencia`, `ordem_recanto.py` | `Relations/*` |
| `ordens.py` | `Orders/*` |
| `validar_plano.py`, `fatiar_payload.py` | `Plan/*` (Validator, PayloadBuilder, Slicer) |
| `calendario_12m.py` | `StayRules`, `InventoryAudit` |
| `beds24.py`, `automacao-pricelabs/pacing/pricelabs.py`, `cota_recalculo.py` | `Connectors/*`, `Http/*` |
| `vigia_seguranca.py`, `reservas_abaixo.py` | `Alerts/*` |
| `ocupacao.py`, `revisao_12m.py`, `metas_faturamento.py`, `painel_semanal.py` | `Report/*` |

**Fica de fora:** `sombra_flutuacao.py`, leitura de PDF, buscas pelo conector da Booking, ordens datadas do dono, conversores de saída do conector.

**Paridade garantida por "testes de ouro":** `tests/fixtures/tools/dump_golden.py` roda o motor Python atual com entradas congeladas e grava `{função, entrada, esperado}`; o PHPUnit carrega o preset de paridade e exige o mesmo resultado (valor com diferença ≤ 0,01; percentual exato). Nível 1: funções puras (`curva`, `status_da_noite`, `passo`, `quantil`, `preco_publico`, `montar_payload`, `fatiar`, `classificar`…). Nível 2: cerca de 10 cenários completos (plano → validador → payload → fatias).

### Travas de segurança embutidas (aprendizados)

Nunca subir o preço de hoje; "hoje" vem do relógio na hora de gravar e plano velho é refeito; apagar e regravar formam um grupo, e após queda o estado é relido antes de reenviar; gravações em série, em fatias (≤ 70 datas; hoje e amanhã à parte), parando em erro 400/429; cota de recálculo contada (falha conta); item sem efeito ou só de arredondamento é descartado; envio só conta quando o preço no PMS e o horário do último envio confirmam; onda de cancelamentos segura cortes; nomes de hóspedes nunca guardados.

**Modos por propriedade:** sombra (padrão: mostra o que gravaria, não grava), aprovação (dono aprova) e autônomo (opção explícita). Chave geral de emergência conferida antes de cada fatia.

### Painel

Assistente de instalação (conectar, mapear quartos, escolher preset, rodada em sombra), painel geral, grade de noites (preço, ocupação e o porquê), editor de regras, relações entre quartos, temporadas e feriados, mercado (concorrentes, CSV, lembrete semanal), ordens, alertas, registro de mudanças com desfazer, pendências, conexões, licença, assistente, diagnóstico. Papéis e permissões próprios; inglês como base e `pt_BR` incluído.

### IA opcional e licença

- **IA:** cliente HTTP direto para a API da Claude com a chave do cliente (sem SDK, para o zip ficar leve); modelo configurável (ids e parâmetros conferidos na skill `claude-api` na hora de implementar). Usos: texto do relatório, explicação de alertas, pedido em texto livre → rascunho de ordem ou regra. **A IA nunca grava preço:** só cria rascunho, que passa por aprovação humana e pelo validador. Entradas sem dados de hóspedes.
- **Licença:** interface `LicenceProvider` com fornecedor de teste; checagem diária e na tela de licença, nunca dentro de uma rodada; servidor fora do ar mantém o último estado por 30 dias; licença vencida devolve a propriedade ao modo sombra após aviso, sem cortar nada no meio. Canal de atualização com assinatura conferida.

## Antes do plugin: rotina de preço pendente

A rotina das 16:53 de 11/11 rodou só em leitura por causa do modo de planejamento. Logo após a aprovação, e antes do M1, faço as gravações pendentes dela pelo procedimento normal da skill (validar, gravar, conferir, registrar, commit): estadia de 1 noite na King Spa 7 de hoje (noite órfã), Balcony de 24/11 pela regra dos 80%, escada da King Spa 7 em 25/11 e 20/12, proteção das noites esgotadas de D0-D1 e a restrição do sábado 21/11 da King Spa 7 no Beds24.

## Marcos (cada um termina com testes verdes, commit e push)

| # | Entrega | Porta de saída |
|---|---|---|
| M1 | Esqueleto, migrações, cofre de chaves, HTTP, conectores só de leitura (com simuladores), ocupação, pisos, âncora, núcleo do ritmo, validador mínimo, painel de leitura, rodada em sombra | Testes de unidade e de ouro nível 1; plugin ativa; rodada em sombra com zero gravações |
| M2 | Relações, estadia, escada, ordens, proteção de esgotada, validador completo, payload e fatias | Testes de ouro nível 2 |
| M3 | WriteGate, execução, conferência, histórico de passos, registro com desfazer, modos aprovação e autônomo, agendador, alertas | Testes de queda e retomada contra simuladores |
| M4 | Assistente de instalação, editores, grade, mercado por CSV, relatórios, `pt_BR` | Fluxos no navegador (Playwright), padrão de código |
| M5 | Assistente de IA, licença e atualização (fornecedor de teste), empacotamento do zip | Zip instala num site limpo; testes da IA com fornecedor falso |

Execução: um Workflow por marco (implementar → revisão adversarial → corrigir), como nas mudanças anteriores do motor. As rotinas diárias de preço continuam normalmente entre os marcos.

## Verificação

- **Motor:** PHPUnit sem WordPress + testes de ouro contra o Python.
- **Conectores:** simuladores em PHP no formato das respostas reais (`automacao-pricelabs/docs/apis-verificadas.md`, `automacao-pricelabs/tests/simulador.py`, `scripts/test_beds24_rodada.py`). Nenhuma chamada às contas reais.
- **WordPress descartável:** núcleo e o plugin de SQLite baixados de wordpress.org, servido com `php -S`; ativar o plugin, importar o preset de paridade, rodar o assistente e uma rodada em sombra; Playwright tira as telas e confere que o simulador não recebeu nenhum POST nem DELETE.
- **Empacotamento:** `bin/build.sh` gera `dist/ritmo-revenue-x.y.z.zip`, reinstalado num site limpo.

## Riscos e pontos em aberto

- **Downloads do GitHub estão bloqueados neste ambiente** (conferido: 403): uso PHPUnit em `.phar` e WordPress, SQLite e Action Scheduler pelos zips de wordpress.org. O verificador de padrão de código (WPCS) depende do GitHub; se continuar bloqueado, peço a liberação do host ou fica como passo do dono.
- **Nas pousadas do dono, o plugin fica só em sombra** até uma troca combinada: a skill e o plugin nunca gravam ao mesmo tempo.
- Termos de uso das APIs do PriceLabs e do Beds24 para revenda de integração: o dono precisa conferir antes de vender.
- Sem servidor MySQL aqui: os testes rodam em SQLite; um teste em MySQL fica para a hospedagem do dono.
- A decidir antes do lançamento: nome final, fornecedor de licença, prazo de guarda das reservas (LGPD).
- O motor Python muda todo dia: os testes de ouro fixam um commit e são regerados quando uma regra mudar.

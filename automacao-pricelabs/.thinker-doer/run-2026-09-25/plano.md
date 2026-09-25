# Plano de execução

## Demanda e escopo

- **Run ID / responsável / data:** run-2026-09-25 / coordenador (Claude Code nesta sessão) / 25/09/2026.
- **Resultado observável:** programa local que roda 7 vezes por dia no Windows do dono. Lê o PriceLabs, mede a ocupação contra as metas, pergunta ao Jev e aplica só descontos permitidos, com relatório e desfazer.
- **Comportamento anterior:** rotina manual, com o Claude e o conector do PriceLabs.
- **Fora do escopo:**
  - Beds24, OTAs e ofertas da Booking;
  - preço base, mínimo e máximo;
  - personalizações do grupo;
  - tabela de ocupação (só na tela);
  - Fase 2 (virada).
- **Autorizações existentes:** o dono pediu o projeto. Não houve autorização para gravar na conta real a partir desta sessão: o programa vem em modo `observar`.
- **Perfil:** crítico, porque altera preço automaticamente.
- **Sala do conselho:** `conselho/`. O briefing tem o pedido original.
- **Especialistas:** domínio, arquitetura, segurança, operação/QA e dois auditores.
- **Critérios de aceitação:** `conselho/briefing.md` e `tarefas/T*.json`.

## Arquitetura

- **Stack:**
  - Python ≥ 3.10, só biblioteca padrão;
  - Windows, com Agendador de Tarefas e Gerenciador de Credenciais;
  - PriceLabs Customer API;
  - Jev (System One) via OpenRouter `typesafe/jev-1.13:free`.
- **Contratos:**
  - `schemas/config.schema.json` v1;
  - `jev-pedido.schema.json` v1;
  - `plano.schema.json` v1;
  - `diario-escrita.schema.json` v1;
  - APIs em `docs/apis-verificadas.md`.
- **Invariantes e decisões:** `conselho/01-arquitetura.md`.
- **Hipóteses a confirmar na conta real, pelo ensaio `testar-gravacao` e pelo `verificar`:**
  - `reason` volta na leitura;
  - formato de `last_date_pushed`;
  - valor de `model` devolvido pelo OpenRouter;
  - o modelo gratuito tem servidores;
  - a chave é a do dono.

## Execução

| Tarefa | Critérios | Dependências | Dono | Arquivos | Papel/modelo efetivo | Checks exigidos |
|---|---|---|---|---|---|---|
| T1-infra | AC1–AC4 | — | coordenador | rede, credenciais, estado, config, tempo, simulador | Claude Code (sessão) | unit, schemas, coverage ≥ 90% |
| T2-integracoes | AC1–AC2 | T1 | coordenador | pricelabs, jev | idem | unit, coverage ≥ 85% |
| T3-dominio | AC1–AC5 | T1, T2 | coordenador | metricas, regras, fixture real | idem | unit, schemas, coverage ≥ 90% |
| T4-execucao | AC1–AC5 | T2, T3 | coordenador | acoes, relatorio, principal | idem | unit, coverage ≥ 85% |
| T5-operacao | AC1–AC2 | T4 | coordenador | windows/*.bat, agenda, __main__, README | idem | unit (coerência de scripts e XML) |

- **Contexto fornecido:** `context_allowlist` das tarefas.
- **Concorrência:** 1 editor. Não houve conflito de escrita.
- **Inventários:** `evidencias/inventory-*.json`, um antes de cada delegação (conselho de arquitetura, decomposição e auditoria). Só o Claude Code existe nesta máquina.
- **Seleção:** um único ambiente elegível. Os subagentes usam o mesmo modelo da sessão, e isso está registrado como limitação de diversidade.
- **Integração:** o coordenador integra no branch `claude/pricelabs-hotel-occupancy-ku6zn5`.
- **Limite de tentativas:** 3 por tarefa. Depois disso, a questão volta à arquitetura.

## Qualidade e custo

- **Comandos de validação** (em `automacao-pricelabs/`, com Python 3.11 do contêiner):
  ```
  python -m unittest discover -s tests -t .
  python -m coverage run --branch ...
  ```
  Também `coverage report --include ... --fail-under`. Os logs estão em `evidencias/g3/`.
- **Cobertura:** linhas e ramos por tarefa. Limiares de 90% (T1, T3) e 85% (T2, T4). T5 é isento: são scripts de uma linha, com o XML testado.
- **Auditoria:** dois auditores independentes, um de segurança e dinheiro e outro de domínio e operação, sobre o snapshot dos `result.json`.
- **Custo de execução do produto:**

  | Item | Custo mensal |
  |---|---|
  | PriceLabs API (7 × US$ 1) | US$ 7 |
  | Jev gratuito | US$ 0 |
  | Sincronização extra (opcional), por horário | US$ 7 |

  Preços consultados em 25/09/2026 (`docs/apis-verificadas.md`).
- **Custo de desenvolvimento:** tokens da sessão e dos subagentes. O host não expõe o valor em dinheiro, então fica como desconhecido.

## Entrega

- **G1–G5:** ver as atas 01 a 05 e `resultados/`, `revisoes/`.
- **Operação e rollback:**
  - `verificar.bat` para conferir;
  - `parar.bat` e `desfazer.bat` para parar e reverter;
  - `snapshot-configuracao-2026-09-25.json` guarda a conta antes da Fase 1.
- **Deploy:** preparado, não executado. A instalação no Windows e a troca para `ativo` são do dono, depois dos critérios do README.

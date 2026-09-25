# Conselho: Decomposição

Status: decidido em 25/09/2026. Uma rodada de objeções sobre as tarefas propostas pelo coordenador.

## Pergunta da etapa

- **Resultado:** tarefas pequenas, com dependências, dono, arquivos, critérios e checks para implementar a decisão de 01-arquitetura.md.
- **Restrição:**
  - só o Claude Code existe nesta máquina (`evidencias/inventory-conselho-decomposicao-20260925T145114Z.json`);
  - os módulos compartilham contratos;
  - há um único editor.

## Participantes reais

| Ator | Especialidades | Harness/modelo efetivo | Consulta |
|---|---|---|---|
| Coordenador | Decomposição | Claude Code 2.1.282 | inventory-conselho-decomposicao-20260925T145114Z.json |
| Subagente "arquitetura" (continuação) | Arquitetura, integrações | Claude Code, subagente Plan | idem |
| Subagente "operação" (continuação) | QA, operação | Claude Code, subagente Plan | idem |

## Proposta do coordenador

Tarefas `tarefas/T1..T5.json` (validadas com `validate.py validate --schema task`):

- **T1:** infraestrutura (rede, credenciais, estado, config, tempo).
- **T2:** integrações (PriceLabs, Jev).
- **T3:** domínio (métricas, regras).
- **T4:** execução (ações, relatório, orquestração).
- **T5:** operação no Windows.

Um único editor, porque há contratos compartilhados. O paralelismo só geraria conflito de escrita.

## Objeções e respostas

| Especialista | Objeção | Resposta | Situação |
|---|---|---|---|
| Arquitetura | A cobertura do pacote inteiro esconde um arquivo fraco | Cada tarefa mede os próprios arquivos com `--branch`: 90% para T1 e T3, 85% para T2 e T4 | Resolvida (tarefas revisadas) |
| Arquitetura | T3 depende de T2 (`jev.margem`, campos de reserva) | Dependência declarada | Resolvida |
| Arquitetura | O simulador precisa trocar o transporte HTTP, não a classe PriceLabs, para provar a lista fechada | `tests/simulador.py` passa a ser de T1 e substitui o transporte | Resolvida |
| Arquitetura | Defeito: a confirmação usava o `x-typesafe-request-id`, que pode vir vazio pelo OpenRouter, e nunca confirmaria | Id local por chamada real (`chamada_id`). A resposta reaproveitada mantém o id antigo e não confirma | Corrigido no código |
| Arquitetura | Defeito: substituições reconstruídas pelo diário ficavam sem status, e datas passadas ocupavam vagas para sempre | A reconstrução grava o status por evento. A conciliação remove datas passadas de qualquer status. O teto conta só datas de hoje em diante | Corrigido no código |
| Arquitetura | Defeito: o urllib segue redirecionamentos e levaria as chaves a outro endereço | Abridor que recusa redirecionamento (vira erro 3xx) | Corrigido no código |
| Operação | Faltam: um dia simulado inteiro; parada silenciosa e fuso; arquivos com acento, OneDrive e CSV do Excel | Critério AC5 em T4 (dia simulado). O `verificar` acusa fuso diferente e última execução com mais de 26 h. Testes com pasta acentuada e CSV com `;`, BOM e vírgula decimal | Resolvida |
| Operação | Os scripts Windows não podem ser executados aqui | Toda a lógica fica no Python. Os `.bat` têm uma linha, só ASCII, com CRLF garantido por `.gitattributes`. A tarefa é criada por XML gerado pelo Python e `schtasks /Create /XML`, sem PowerShell. Roda com `pyw`. O `verificar` roda os testes na máquina do dono | Resolvida |

## Decisão do responsável

- **Responsável e data:** coordenador, 25/09/2026.
- **Opção escolhida:** as 5 tarefas revisadas. Grafo acíclico: T1 → T2 → T3 → T4 → T5. Execução sequencial com um único editor. Os critérios de aceitação ligam cada requisito a checks.
- **Alternativas descartadas:**
  - vários doers em paralelo, por conflito em contratos compartilhados;
  - `agendar.ps1`, bloqueado por padrão no Windows;
  - CI em `windows-latest`, que o pedido não exige e que alteraria o repositório. Fica como sugestão.
- **Pendências:**
  - os scripts Windows só são verificados por coerência e pelo XML;
  - o teste real acontece no `verificar`, na máquina do dono.
- **Status final:** decidido.

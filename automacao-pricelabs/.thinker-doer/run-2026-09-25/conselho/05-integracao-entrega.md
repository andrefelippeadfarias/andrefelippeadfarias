# Conselho: Integração e entrega

Status: decidido em 25/09/2026. O fechamento dos gates está registrado no fim desta ata.

## Pergunta da etapa

O que falta para o dono, que não é programador e usa Windows, instalar e operar sozinho? Qual é a ordem de ativação segura?

## Participantes reais

| Ator | Especialidade | Harness/modelo | Consulta |
|---|---|---|---|
| Subagente "operação" (continuação) | Operação, QA, experiência do dono | Claude Code, subagente Plan | `evidencias/inventory-conselho-entrega-20260925T152843Z.json` |
| Coordenador | Integração | Claude Code | idem |

## Objeções e respostas

| Objeção | Resposta | Situação |
|---|---|---|
| A primeira verificação sempre falhava ("última execução: nenhuma") | `instalar.bat` roda `executar` antes de `verificar` | Resolvida |
| README sem o que fazer se aparecer FALHA (o Jev gratuito hoje está sem servidores) | Seção "Se aparecer FALHA" com Jev, PriceLabs 403, reservas, fuso, tarefa, autoteste e Área de Trabalho | Resolvida |
| ZIP baixado da internet bloqueia os `.bat`; README não diz de onde baixar | Passo "Download ZIP → Propriedades → Desbloquear". Aviso de que a chave colada não aparece | Resolvida |
| Comando de ensaio exigia `cd` e digitação de id | `ensaio.bat` pergunta a data e usa o quarto com desconto permitido | Resolvida |
| Não havia como testar o DESFAZER | `ensaio-manter.bat` deixa o +0% para o `desfazer.bat` remover | Resolvida |
| Editar `teto_dia` à mão pode quebrar o JSON em silêncio (pythonw) | `ativar.bat` liga com teto 1; `ampliar.bat` sobe para 6. Erro no config vira "PRECOS ERRO" na Área de Trabalho | Resolvida |
| Autoteste nunca rodou num Windows real; sugestão de CI em windows-latest | Não adotada: o pedido não inclui CI e alteraria o repositório. O auditor B rodou a suíte com a plataforma simulada como Windows. O autoteste roda no `verificar` da máquina do dono. Fica como risco aceito (T5 B-4, B-7) | Risco aceito |
| `agendar.bat` desnecessário | Removido; `instalar.bat` já agenda | Resolvida |

## Decisão

**Ordem de ativação** (README, "Quando passar de Observar para Ativo"):
1. Observar por pelo menos 5 dias com o Jev respondendo e 20 decisões.
2. Ensaio de gravação e teste do DESFAZER.
3. `ativar.bat`, com 1 desconto por dia.
4. Depois de uma semana, `ampliar.bat`.

**Integração:**
- Um único branch, `claude/pricelabs-hotel-occupancy-ku6zn5`, sem conflitos, porque houve um único editor.
- A mudança de `"schemas/"` para `"schemas"` no `context_allowlist` das tarefas foi feita pelo coordenador às 15:30Z. O validador recusa caminho com barra final. A mudança não altera escopo, checks nem contrato, então não subiu `task_revision`.
- T5 subiu para `task_revision` 2 porque a lista de arquivos mudou.

**Deploy:**
- Preparado, não executado. Instalar no Windows e ligar o modo Ativo são passos do dono.
- Nenhuma alteração na conta do PriceLabs foi feita por esta etapa.

## Fechamento dos gates

(preenchido após os pareceres finais)

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

Gate oficial da skill (`validate.py gate`) em 25/09/2026. Saída completa em `evidencias/gates-finais.txt`.

| Tarefa | Resultado (revisão) | Auditor | Decisão | Gate | Riscos aceitos |
|---|---|---|---|---|---|
| T1-infra | 3 | auditor-a | approved | passed | N1b (low), R1 (low) |
| T2-integracoes | 3 | auditor-a | approved | passed | J1 (low) |
| T3-dominio | 3 | auditor-b | approved | passed | — |
| T4-execucao | 5 | auditor-a | approved | passed | N4r (low) |
| T5-operacao | 5 (tarefa rev. 2) | auditor-b | approved | passed | B-4 e B-7 (medium): só verificáveis num Windows real, dono via verificar.bat |

- **G4 (auditoria):** atendido. Checks exigidos aprovados, cobertura aplicável atendida (95 a 99% por tarefa), sem achados altos ou críticos abertos, revisão independente do artefato atual.
- **G5 (integração):**
  - suíte completa no estado final: 108 testes, aprovados com e sem jsonschema;
  - o README cobre operação, recuperação e rollback;
  - riscos e pendências declarados.
- **Deploy:** preparado, não executado.

**Status final:** entregue para instalação. A validação real no Windows e na conta fica com o dono, pela ordem descrita.

## Revisão posterior: pedido "Execute em meu computador" (25/09/2026)

- **Limite do ambiente:** esta sessão roda em contêiner na nuvem e não alcança o computador do dono. A instalação no Windows continua sendo passo do dono.
- **Instalação por um comando:** `windows/instalar-da-internet.ps1`, chamado por `irm ... | iex` no PowerShell. Ele:
  - instala o Python se faltar;
  - baixa o ZIP do ramo;
  - desbloqueia os arquivos;
  - preserva o `config.json`;
  - recusa pasta alheia.
- **Achado do auditor B:** o ZIP incluía `.thinker-doer`, com caminhos acima de 260 caracteres que quebrariam o Windows PowerShell 5.1. Corrigido com `export-ignore` e extração em caminho curto. O ZIP real foi conferido.
- **Ensaio somente leitura com dados reais da conta:** coleta paralela de 8 agentes pelo conector, e o programa rodado com transporte de replay às 12:56, 23:30 e 05:30.
  - Zero escritas.
  - Ocupação de 0 a 6 dias igual à do PriceLabs (39, 57, 71, 7, 61, 5 e 36%).
  - O Jev gratuito está sem servidores (HTTP 404 simulado, que é o estado real), então não há decisão.
- **Verificação independente** (workflow verificar-ensaio-real: 3 lentes e céticos):
  - números e portões conferidos sem importar o código;
  - achados confirmados e corrigidos na revisão 7: conflito calendário × reservas, códigos negativos das métricas, eco de mínimo/máximo que o ensaio de gravação não revelava, paginação, janela de reservas e destino do instalador;
  - refutados: métricas com `data.data` na API direta (é o envelope do conector) e "datas esgotadas" do R7 (o PMS ainda vende a unidade).
- **Achado de negócio para o dono:** Queen Spa (7) tem 7 reservas confirmadas para 7 unidades em 27/09, com o calendário do PriceLabs em 6/7 e a data ainda à venda. É risco de overbooking ou de mapeamento no Beds24.

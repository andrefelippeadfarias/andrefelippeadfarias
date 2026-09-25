# Conselho: Auditoria

Status: segunda rodada em andamento (reauditoria das correções).

## Pergunta da etapa

- **Hipóteses de falha e testes discriminantes:** nas lentes (A) segurança e dinheiro e (B) domínio e operação no Windows.
- **Revisão independente:** obrigatória pelo perfil crítico.

## Participantes reais

| Ator | Lente | Harness/modelo efetivo | Consulta |
|---|---|---|---|
| Auditor A | Invariantes de segurança e de dinheiro | Claude Code, subagente general-purpose, mesmo modelo da sessão, contexto independente do autor | `evidencias/inventory-auditoria-20260925T150810Z.json` |
| Auditor B | Domínio (pacing, datas, fuso, sincronização) e operação no Windows | idem | idem |

Os auditores receberam requisitos, contratos, código e testes. Não receberam a autoavaliação do autor. Trabalharam em cópias fora do repositório.

**Limitação:** mesmo modelo do autor. A independência vem do contexto separado, não de outro modelo.

## Rodada 1: achados (snapshot do commit 4883b8c)

### Auditor A: 12 hipóteses, 7 achados

| # | Sev. | Achado | Correção (commit 7291b49) | Teste de regressão |
|---|---|---|---|---|
| A1 | HIGH | O DESFAZER podia apagar uma substituição humana marcada como divergente. A leitura divergente sobrescrevia o `lido`, inclusive na reconstrução pelo diário | Leitura divergente vai para `lido_divergente`. Só criada/adotada gravam `lido`. `apagar` recusa divergente ou sem `lido` | `test_h1_*`, `test_h1b_*` |
| A2 | MED | O disjuntor não sobrevivia à recuperação do estado | Disjuntor gravado na hora, no estado e no diário (`acionar_disjuntor`). Estado vindo do .bak ou do diário entra em contenção até RETOMAR. Contadores e datas descontadas são reconstruídos pelo diário | `test_h2_*`, `test_estado_perdido_*` |
| A3 | MED | UTF-8 truncado no estado ou no diário derrubava a execução e o verificar em silêncio | Captura de `ValueError`, diário lido com `errors="replace"`, `fsync` no .tmp | `test_h3_*` |
| A4 | MED | `HTTPException` escapava da rede e o relatório saía verde | Conversão para `ErroRede`. Qualquer exceção em `executar` gera relatório vermelho | `test_h4_*` |
| A5 | MED | Casar datas por `str[:10]` falhava aberto; "ausente" depois do POST apagava o registro | Item com data ilegível conta como existente. A listing fica bloqueada nos candidatos. "Ausente" depois do POST continua como incerta | `test_h5_*` |
| A6 | LOW | `testar-gravacao` ignorava PARAR, disjuntor e papel do quarto, e gastava teto | Recusa nos três casos e restaura os contadores | `test_h6_*` |
| A7 | LOW | Trava vazia tratada como órfã; trava apagada sem conferir o dono; divergentes fora do teto; divergência na conciliação não acionava o disjuntor | Órfã só por idade (mtime), marca única e remoção só pelo dono. Divergentes contam no teto. Divergência na conciliação aciona o disjuntor | `test_trava_*`, `test_h7_*` |

### Auditor B: 12 hipóteses e força dos testes, 12 achados

| # | Sev. | Achado | Correção (commit 128b3b8) | Teste |
|---|---|---|---|---|
| B1 | HIGH | Erro inesperado gerava relatório verde | Coberto pela correção A4 | `test_h4_*` |
| B2 | HIGH | No Windows, o autoteste do VERIFICAR rodava `schtasks /Create` de verdade e falhava | Plataforma e executor de comandos injetáveis em `Ambiente`. O teste cobre o ramo win32 com um executor falso. `agendar` devolve 1 se falhar | `test_agendar_e_chaves` |
| B3 | MED | Alertas graves não mudavam a cor | `alertas_gerais` classifica a gravidade. Graves, limpeza e mercado indisponível dão amarelo | `test_alertas_graves_deixam_amarelo` |
| B4 | MED | A Área de Trabalho do OneDrive em português não era encontrada | `SHGetFolderPathW(CSIDL_DESKTOPDIRECTORY)`, depois `%OneDrive%`. O verificar mostra o caminho ou FALHA | `TestRelatorio` |
| B5 | MED | A mediana misturava semana e fim de semana, o que empurrava o Jev a descontar sexta e sábado | Mediana por tipo de bloco | `test_mediana_por_tipo_de_bloco` |
| B6 | MED | Data sem hora abria o portão de 48 h cedo | Data sem hora vale como fim do dia local para reservas e início do dia para cancelamentos (lado conservador) | `test_data_sem_hora_*` |
| B7 | MED | A tarefa de logon sem UserId exigia administrador, e o agendar devolvia 0 | `UserId` do usuário atual no gatilho e no principal. O verificar confere as duas tarefas | `test_xml_do_logon_*`, `test_verificar` |
| B8 | LOW | O cabeçalho do `historico.csv` variava | Colunas fixas | `test_historico_com_colunas_fixas` |
| B9 | LOW | A pergunta fixava "10%" | Texto com o percentual do config | `test_pergunta_usa_percentual_do_config` |
| B10 | LOW | O padrão `PRECOS * *.txt` apagava arquivos do dono | Expressão regular exata (`trocar_status`) | `test_verificar` |
| B11 | LOW | O verificar silencioso escondia fuso errado | Arquivo ATENCAO na Área de Trabalho | `test_verificar` |
| B12 | LOW | Tokens acima do declarado | README corrigido: cerca de 1 mil em Ativo, até cerca de 5 mil em Observar | — |
| Testes | LOW | CRLF não conferido nos bytes; limite de mercado em 40 sem teste | Asserções adicionadas | `test_bats_*`, `test_portoes` |

- **Documentação:** `docs/apis-verificadas.md` foi alinhada ao uso de `start_date`/`end_date` (data de chegada) em reservation_data.
- **Contratos:** o `diario-escrita.schema.json` ganhou os eventos `disjuntor` e `retomada` (mudança aditiva).
- **Evidência G3 revisão 2:**
  - 107 testes, 3 pulados sem jsonschema;
  - cobertura por tarefa de 95% (T1), 99% (T2), 99% (T3) e 98% (T4);
  - logs em `evidencias/g3/` e resultados em `resultados/*.result.json` (revision 2).

## Rodada 2: reauditoria

Pendente.

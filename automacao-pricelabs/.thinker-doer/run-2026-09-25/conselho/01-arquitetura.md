# Conselho: Arquitetura

Status: decidido em 25/09/2026. Duas rodadas realizadas.

## Pergunta da etapa

- **Resultado necessário:** programa local no Windows do dono que roda 7 vezes por dia e executa o playbook de pacing (relatório v4, seção 6). As decisões de julgamento são do Jev, com uma chamada por execução. O código coleta, calcula, limita e executa.
- **Critérios e limites:** briefing.md. Perfil crítico: altera preços automaticamente.
- **Decisões anteriores:**
  - Ofertas da Booking fixas.
  - Sem mudança automática em base, mínimo, máximo ou personalizações.
  - Balcony é transbordo.
  - Queen Spa (2) tem anomalia de 66%.
  - Regra de não empilhar desconto com a tabela de ocupação.

## Participantes reais

| Ator | Especialidades | Harness/modelo efetivo | Consulta de capacidade / recibo |
|---|---|---|---|
| Coordenador (sessão principal) | Síntese, thinker | Claude Code 2.1.282, modelo da sessão | `evidencias/inventory-conselho-arquitetura-20260925T143314Z.json` (preflight 14:33Z) |
| Subagente "domínio" | Produto, revenue management | Claude Code, subagente Plan, mesmo modelo | Mesmo inventário. Só existe o Claude Code nesta máquina |
| Subagente "arquitetura" | Arquitetura, integrações | Claude Code, subagente Plan, mesmo modelo | Idem |
| Subagente "segurança" | Segurança, invariantes | Claude Code, subagente Plan, mesmo modelo | Idem |
| Subagente "operação" | Operação, QA, experiência do dono | Claude Code, subagente Plan, mesmo modelo | Idem |

**Limitação:** todos os participantes usam o mesmo modelo. Os contextos são separados e as propostas foram independentes, mas não há diversidade de modelo.

## Rodada 1: propostas independentes

| Proposta / autor | Abordagem | Benefícios | Código/componentes | Riscos/custo |
|---|---|---|---|---|
| Domínio | Portões determinísticos. O Jev só decide se age: `hold` / `discount` / `visibility` por quarto e por bloco de 0 a 6 dias, com veto `noul`. Só Afrodite e Villa King Spa (2). −10% por 48 h | Pouca chamada, fiel ao playbook | Regras + 2 perguntas | Ganho marginal, porque há poucas unidades elegíveis |
| Arquitetura | Python só com biblioteca padrão. 8 módulos. Estado JSON atômico e trava. Pula o Jev sem candidato ou com hash igual. Execuções antes das sincronizações | Instalação simples, tokens mínimos | ~8 módulos, JSON Schemas nos testes | `DELETE` apaga a data inteira; dados velhos |
| Segurança | 11 invariantes. Lista fechada de rotas. Limiares 0,85/0,80. Confirmação em 2 execuções. Modos observar → ensaio → ativo. Disjuntor. Desfazer pelo diário | Contenção forte | Diário só de acréscimo, testes negativos | Complexidade |
| Operação | winget, `.bat`, Gerenciador de Credenciais. 7 horários (05:30…23:30). Relatório HTML, CSV, alerta na Área de Trabalho. Critérios para sair da observação | Uso por não programador | Scripts Windows, relatório | PC desligado; Jev fora do ar |

## Rodada 2: objeções e respostas

| Especialista | Objeção relevante | Resposta | Situação |
|---|---|---|---|
| Arquitetura | `reason` no POST, campos nulos e `last_date_pushed` não verificados. A comparação "idêntica" pode travar | Comparação normalizada com o que a releitura devolveu. Nulos são ignorados; campo extra preenchido conta como divergente. `reason` é secundário e o diário prova a posse. A vida do desconto é contada pelos horários de sincronização do config. `last_date_pushed` vira só alerta de saúde | Resolvida |
| Arquitetura | Cancelamento de reserva antiga não aparece na busca por data de reserva | Busca por data de chegada, de hoje a hoje+8. Ela devolve `booking_status` cancelado e `cancelled_on` (documentado; na amostra real, 149 canceladas) | Resolvida |
| Arquitetura | O Gerenciador de Credenciais falha sem usuário conectado | A tarefa roda com o usuário conectado (tela bloqueada serve). Há variável de ambiente como alternativa. O Gerenciador é mantido porque cifra a chave no disco | Resolvida, documentada |
| Arquitetura | Cortar o modo ensaio, a trava de RevPAR e a planilha CSV | Ensaio cortado: vira teto configurável. RevPAR vira alerta com pausa. A planilha fica, porque o dono usa Excel | Parcial |
| Segurança | Desconto órfão se o processo morre entre o POST e o salvamento | Intenção "pendente" gravada no diário antes do POST e contada nos tetos. Com resultado incerto, o POST não se repete. A execução seguinte concilia | Resolvida |
| Segurança | Pisos rígidos iguais aos limiares adotados; a configuração só aperta | Aceito: 0,80 / 0,70 / 0,30 / 0,30 | Resolvida |
| Segurança | Confirmação em 2 execuções compatível com o reaproveitamento por hash (23:30 propõe, 05:30 confirma, em dias diferentes) | Adotada como opção padrão ligada | Resolvida |
| Domínio | A trava de RevPAR sem a condição "ocupação sobe" desliga por sazonalidade | Alerta semanal no portfólio sem a Balcony, com RevPAR e ocupação de 30 dias. Só dispara com ocupação subindo e RevPAR caindo 2 semanas seguidas. Nesse caso pausa novos descontos | Resolvida |
| Domínio | Feriados vindos de lista manual podem vencer | O código calcula os feriados nacionais fixos e móveis e as vésperas. O config só acrescenta os locais | Resolvida |
| Domínio | Só a Afrodite fica elegível, e o desconto quase nunca dispara | O relatório diz isso e lembra o que move a meta (tabela, preço base, pendências) | Resolvida |
| Operação | A observação pode "passar" sem nenhuma chamada ao Jev | Na observação, candidatos-sombra em todos os quartos menos a Balcony, nunca graváveis. Amostra mínima: 20 decisões e 5 dias com o Jev respondendo | Resolvida |
| Operação | Tarefa parada sem aviso | Arquivo de status na Área de Trabalho com hora no nome, trocado a cada execução. Tarefa extra "ao fazer logon" roda o `verificar` | Resolvida |
| Operação | Ensaio numa data com estadia mínima manual | Rejeitado: viola a regra de não escrever em data de outra origem. O ensaio grava +0% numa data livre e compara `last_date_pushed` antes e depois | Aceito pelo autor |

## Decisão do responsável

- **Responsável e data:** coordenador, 25/09/2026.

**Stack e infraestrutura**

- **Linguagem:** Python ≥ 3.10, só biblioteca padrão. `jsonschema` e `coverage` só no desenvolvimento.
- **Onde fica cada coisa:**
  - projeto em `C:\RecantoPrecos`;
  - dados em `%LOCALAPPDATA%\automacao-pricelabs` (fora do Git);
  - chaves no Gerenciador de Credenciais do Windows, via ctypes, com variável de ambiente como alternativa.
- **Fuso:** fixo em UTC−3, definido no config.

**Rede**

- Cliente HTTP com lista fechada de rotas.
- As escritas só existem em modo `ativo`.
- Espaçamento de 1,1 s entre chamadas.
- Sem `refresh_listing` e sem `push_prices`.

**Papéis dos quartos (config)**

| Papel | Quartos | O que a automação faz |
|---|---|---|
| `tabela` | Queen Spa (7), Double, Villa King Spa (7) | Só monitora |
| `transbordo` | Balcony | Nada |
| `sem_tabela` | Afrodite | Desconto permitido |
| `sem_tabela` | Villa King Spa (2) | Desconto desligado até o dono resolver a pendência |
| `sem_tabela` | Queen Spa (2) | Desconto desligado até o dono resolver a pendência |

Não existe chave para ligar desconto nos quartos com tabela.

**Portões determinísticos**

- quarto `sem_tabela` com desconto permitido;
- ocupação de 0 a 6 dias abaixo de 70%;
- mercado de 7 dias abaixo de 40%. Com 40% ou mais, vira alerta de visibilidade;
- nenhuma reserva nova para os próximos 7 dias nas últimas 48 h, ou cancelamento para 0 a 2 dias nas últimas 48 h;
- calendário atualizado há menos de 36 h, sem `error_status` e com sincronização ligada;
- reservas legíveis;
- próxima sincronização em até 7 h.

Datas candidatas:
- entre a data da próxima sincronização e hoje+6;
- livres, sem feriado nem véspera;
- sem nenhuma substituição;
- não descontadas nos últimos 7 dias;
- preço × 0,90 ≥ mínimo.

Unidade de decisão: quarto × bloco, sendo os blocos "semana" e "sexta/sábado".

**Jev**

- **Quando chama:** no máximo uma vez por execução, e só com candidato.
- **Perguntas, com estado em inglês e faixas com nome:** uma `choice` por bloco (`hold` / `discount` / `visibility_issue`) e um veto `noul`.
- **Critério para agir:** confiança ≥ 0,80, probabilidade ≥ 0,70, margem ≥ 0,30 e veto < 0,30. São pisos rígidos: a configuração só aperta.
- **Confirmação:** por padrão, exige a mesma escolha na execução anterior da janela de criação.
- **Validação:** estrita. Qualquer desvio significa não agir.
- **Falhas:** tempo limite de 60 s. 429 e 5xx têm até 2 novas tentativas; 401, 402, 403 e 422, nenhuma.
- **Economia:** teto de 7 chamadas por dia. Se o estado for igual ao da última chamada do mesmo dia, reaproveita a resposta. Na observação, os candidatos-sombra são sempre perguntados.
- **Provedores:** OpenRouter `typesafe/jev-1.13:free` é o padrão. TypeSafe pago fica desligado.

**Ação única**

- **Formato:** substituição por data com `price` "−10" em percentual e `reason` "auto-jev \<id da execução\>".
- **Proibido:** preço fixo e mexer em estadia ou mínimo.
- **Ordem:**
  1. releitura imediatamente antes;
  2. intenção gravada no diário;
  3. POST;
  4. releitura de conferência.
- **Tetos:**
  - 3 por execução;
  - 6 por dia;
  - 6 ativas;
  - "1 por dia" recomendado na primeira semana.

**Remoção**

- É determinística e roda mesmo sem o Jev.
- **Quando:**
  - a data vendeu;
  - a sincronização seguinte já é pelo menos 48 h depois da primeira sincronização após a criação;
  - a data passou.
- `DELETE` só se a substituição estiver idêntica, após normalização. Se divergir, alerta.
- O `desfazer` usa a mesma regra.

**Salvaguardas**

- **Conferências:** releitura depois do POST; preço ≥ mínimo depois da sincronização. Se falhar, apaga a própria substituição e aciona o disjuntor.
- **Disjuntor:** volta para `observar` até o dono rodar RETOMAR.
- **Modos:** `observar` (padrão) e `ativo`. O arquivo `PARAR` é conferido no início e antes de cada escrita.
- **Teste de gravação:** +0% numa data livre, com `last_date_pushed` antes e depois.

**Agenda e saída para o dono**

- **Agenda:** 05:30, 08:30, 11:30, 14:30, 17:30, 20:30 e 23:30, mais uma execução do `verificar` ao fazer logon.
- **Arquivos:**
  - `relatorio.html` gerado por modelo fixo;
  - `historico.csv`;
  - status na Área de Trabalho, com hora no nome;
  - `ATENCAO-PRECOS.txt` quando for o caso.
- **Scripts `.bat`:** instalar, configurar chaves, verificar, executar, parar, retomar e desfazer.

**Alertas determinísticos**

- visibilidade;
- faixa de 7 a 14 dias abaixo de 30%;
- faixa de 15 a 29 dias abaixo de 20%;
- quarto no piso;
- 0 a 6 dias acima de 85%;
- divergência de unidades;
- sincronização não confirmada;
- dados velhos;
- Jev fora do ar;
- trava de receita;
- substituição divergente.

**Por que é a solução adequada mais simples:** um processo, sem servidor, sem dependências em produção, e uma única escrita possível. Toda a complexidade serve às invariantes do perfil crítico.

**Design e experiência de uso:**
- Relatório em português com faixa verde, amarela ou vermelha.
- Scripts `.bat` de um clique.
- Verificação com OK ou FALHA por item.
- Na verificação, uma execução simulada com dados sintéticos precisa gerar o relatório e zero escritas.

**Alternativas descartadas:**
- `typesafe-sdk` e `requests`, por serem dependência para poucos POSTs;
- `refresh_listing`, porque a sincronização já recalcula;
- `push_prices`, que não está documentado;
- grupo de controle, porque a amostra é pequena demais;
- modo ensaio, substituído por teto;
- trava de RevPAR automática em −7%, substituída por alerta e pausa;
- ensaio em data com substituição manual;
- arquivo `segredos.env`, que fica sem cifra;
- abrir o navegador sozinho.

**Pendências, riscos e verificação necessária:**
- confirmar na conta real, pelo teste de +0%, que `reason` volta na leitura;
- confirmar o formato de `last_date_pushed`;
- confirmar o valor de `model` devolvido pelo OpenRouter;
- confirmar que o modelo gratuito voltou a ter servidores;
- confirmar que a chave do PriceLabs é a do dono.

**Próximas tarefas:**
- contratos em JSON Schema e plano (G1);
- decomposição (G2);
- implementação com testes simulados (G3);
- auditoria independente (G4);
- integração e documentação (G5).

O coordenador executa. Um subagente independente audita.

**Status final:** decidido.

## Revisão posterior

Registrar nova evidência e mudança de decisão sem apagar o histórico.

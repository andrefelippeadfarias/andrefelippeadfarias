# Ferramentas externas de economia de tokens

Repositórios oficiais conferidos no README de cada projeto em 28/09/2026. Antes de instalar, releia o README, porque os comandos mudam. **Confirme sempre com o usuário:** hooks e servidores MCP alteram o comportamento de todas as sessões.

| Frente | Ferramenta | Repositório | O que faz | Integração com Claude Code |
|---|---|---|---|---|
| Entrada | rtk (Rust Token Killer) | `rtk-ai/rtk` | Proxy de CLI que filtra e resume a saída de git, testes e builds | Hook PreToolUse no Bash (reescreve `git status` como `rtk git status`). Não cobre Read/Grep |
| Entrada | headroom | `headroomlabs-ai/headroom` | Comprime localmente saídas de ferramentas, logs, JSON, RAG e histórico | Proxy (`headroom wrap claude`) e MCP |
| Entrada | Repomix | `yamadashy/repomix` | Empacota o repositório num único arquivo para LLM | CLI, MCP e plugin |
| Entrada | Deblank | `anpl-code/Deblank` | Minificador reversível: tira a formatação na ida e restaura na volta | Nenhuma (só um serviço REST em Docker) |
| Saída | caveman | `JuliusBrussee/caveman` | Skill que força respostas em "estilo homem das cavernas" | Skill/plugin, hooks e proxy |
| Navegação | code-review-graph | `tirth8205/code-review-graph` | Grafo local do código (tree-sitter) para ler só o contexto relevante | MCP, hooks e skills |
| Navegação | Token Savior | `Mibayy/token-savior` | Servidor MCP de navegação por símbolos, com memória | MCP e hooks |

## Instalação (conforme os READMEs)

- **rtk:** `brew install rtk` ou `cargo install --git https://github.com/rtk-ai/rtk`, depois `rtk init -g`. Atenção: o crate `rtk` do crates.io é outro projeto.
- **headroom:** `pip install "headroom-ai[all]"`, depois `headroom wrap claude`. Para MCP: `headroom mcp install`.
- **Repomix:** `npx repomix@latest`. Para MCP: `claude mcp add repomix -- npx -y repomix --mcp`.
- **caveman:** `npx skills add JuliusBrussee/caveman -g`. Como plugin: `claude plugin marketplace add JuliusBrussee/caveman && claude plugin install caveman@caveman`.
- **code-review-graph:** `pip install code-review-graph`, depois `code-review-graph install` e `code-review-graph build`.
- **Token Savior:** `pip install "token-savior-recall[mcp]"`, depois `claude mcp add token-savior -- <venv>/bin/token-savior`. O próprio README avisa que o benchmark divulgado não é reproduzível publicamente.
- **Deblank:** imagem Docker `zhangcen456/deblank`. É um projeto acadêmico, sem integração pronta com agentes.

## Relação com esta skill

| Ideia externa | Equivalente embutido (sem instalar nada) |
|---|---|
| rtk / headroom (comprimir saída) | `scripts/compactar.py` |
| code-review-graph / Token Savior (mapa de símbolos) | `scripts/mapa_codigo.py --simbolo` |
| Repomix (contexto do repositório) | `scripts/mapa_codigo.py` + leitura por trechos |
| caveman (saída curta) | seção "Saída" e "Modo caverna" do SKILL.md |
| Deblank (minificar código) | ler só o trecho necessário. Minificar código para o agente arrisca editar errado, sobretudo em Python, onde a indentação é sintaxe |

Os números de economia divulgados pelos projetos (60–90% na entrada, cerca de 65–75% na saída) são medições dos próprios autores e variam com o tipo de tarefa.

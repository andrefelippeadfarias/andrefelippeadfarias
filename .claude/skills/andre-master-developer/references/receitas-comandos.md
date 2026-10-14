# Receitas de comandos enxutos

Flags que reduzem a saída na origem. Combine com `compactar.py` quando a saída ainda puder ser longa.

## Python
| Objetivo | Comando |
|---|---|
| Testes (pytest) | `pytest -q --tb=short`; para parar na 1ª falha: `pytest -q -x --tb=short` |
| Testes (unittest) | `python -m unittest discover -q` |
| Instalar | `pip install -q --disable-pip-version-check -r requirements.txt` |
| Lint | `ruff check --output-format concise` |

## Node / TypeScript
| Objetivo | Comando |
|---|---|
| Instalar | `npm ci --silent --no-audit --no-fund` |
| Testes | `npm test --silent`; `npx jest --silent`; `npx vitest run --reporter=dot` |
| Tipos | `npx tsc --noEmit --pretty false` |
| Lint | `npx eslint --quiet .` (só erros) |

## Go / Rust / JVM
| Objetivo | Comando |
|---|---|
| Go | `go test ./...` (sem `-v`); `go vet ./...` |
| Rust | `cargo test -q`; `cargo build -q --message-format short` |
| Maven / Gradle | `mvn -q test`; `./gradlew test -q` |

## Git
| Objetivo | Comando |
|---|---|
| Estado | `git status -sb` |
| O que mudou | `git diff --stat`, depois `git diff -- caminho` |
| Diff com pouco contexto | `git diff -U1` |
| Histórico | `git log --oneline -n 10`; `git show --stat HEAD` |
| Quem mexeu num trecho | `git blame -L 80,100 arquivo` |

## Logs, dados e rede
| Objetivo | Comando |
|---|---|
| Fim do log | `tail -n 100 app.log` |
| Só erros | `grep -n -m 20 -i error app.log`; `journalctl -u svc -p err -n 100 --no-pager` |
| Contar | `grep -c ERROR app.log`; `wc -l arquivo` |
| Docker | `docker logs --tail 100 ctr`; `docker compose logs --tail 50 svc` |
| JSON | `jq 'keys'`, `jq '.items \| length'`, `jq '.items[0]'` |
| CSV | `head -5 dados.csv`; `wc -l dados.csv` |
| HTTP | `curl -sS -o /dev/null -w '%{http_code}\n' URL`; `curl -sS URL \| head -c 2000` |
| Busca | `rg -l termo` (só arquivos); `rg -n -m 5 termo` (5 por arquivo) |

## Anti-padrões (evite)
- `cat` de arquivo grande, `npm install` sem `--silent`, `pytest -v` em suíte grande, `git diff` sem `--stat` antes, `ls -R` na raiz, `find /`.
- Rodar a mesma suíte inteira várias vezes: depois da primeira falha, rode só o teste que falhou (`pytest -q caminho::teste`).

#!/usr/bin/env python3
"""Compacta saídas de terminal para gastar menos tokens com agentes de IA.

Uso:
  python3 compactar.py -- COMANDO [ARGS...]    roda o comando e compacta a saída
  python3 compactar.py -- "cmd1 && cmd2"       um único argumento roda via shell
  COMANDO 2>&1 | python3 compactar.py          compacta o que chegar pelo stdin

No modo "--" o código de saída do comando é preservado: falha continua falha.

Opções:
  --max-linhas N   teto de linhas na saída (padrão 120)
  --contexto N     linhas de contexto em volta de cada erro (padrão 2)
  --manter-ok      não descarta linhas de sucesso (testes que passaram etc.)

O que faz, nesta ordem:
  1. remove cores ANSI e barras de progresso redesenhadas com \\r
  2. descarta ruído conhecido (testes que passaram, pip/npm verboso, progresso)
  3. junta linhas repetidas ou que só diferem em números
  4. se ainda passar do teto: mantém início, fim e toda linha de erro com contexto
"""

import re
import subprocess
import sys

ANSI = re.compile(r"\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(\x07|\x1b\\)|\x1b[=>]")

RUIDO = [re.compile(p) for p in (
    # testes que passaram
    r"\bPASSED\b",                                  # pytest -v
    r"\.\.\. ok$",                                  # unittest -v, cargo test
    r"^\s*(✓|✔|√)\s",                              # jest, vitest, mocha
    r"^\s*=== (RUN|PAUSE|CONT)\s",                  # go test -v
    r"^\s*--- PASS:",                               # go test -v
    # instaladores
    r"^\s*(Requirement already satisfied|Collecting|Downloading|Using cached|"
    r"Obtaining|Installing build dependencies|Getting requirements|"
    r"Preparing metadata|Building wheel|Created wheel|Stored in directory)\b",
    r"^npm (http|timing|sill|verb)\b",
    r"^\s*(Fetching|Resolving|Progress: resolved)\b",
    # barras de progresso
    r"^\s*\d{1,3}(\.\d+)?%\s*[|\[]",
    r"^\s*[\[(]?[#=>\-.\s]{8,}[\])]?\s*\d{1,3}(\.\d+)?%",
)]

IMPORTANTE = re.compile(
    r"error|erro|exception|traceback|fail|falh|fatal|panic|warn|aviso|assert|"
    r"denied|negad|not found|não encontrad|undefined|cannot|can't|unable|"
    r"segmentation|critical|abort|✗|✘|×|^E\s|^>\s",
    re.IGNORECASE,
)

DIGITOS = re.compile(r"\d+")


def limpar(texto):
    linhas = []
    for bruta in ANSI.sub("", texto).split("\n"):
        # progresso redesenhado com \r: fica só o último estado visível
        partes = [p for p in bruta.split("\r") if p.strip()]
        linhas.append((partes[-1] if partes else "").rstrip())
    while linhas and not linhas[-1]:
        linhas.pop()
    return linhas


def descartar_ruido(linhas, manter_ok):
    saida = []
    for linha in linhas:
        if not manter_ok and any(r.search(linha) for r in RUIDO):
            continue
        if not linha and saida and not saida[-1]:
            continue  # uma linha em branco basta
        saida.append(linha)
    while saida and not saida[0]:
        saida.pop(0)
    while saida and not saida[-1]:
        saida.pop()
    return saida


def juntar_repetidas(linhas):
    saida, i = [], 0
    while i < len(linhas):
        chave = DIGITOS.sub("#", linhas[i])
        j = i + 1
        while j < len(linhas) and DIGITOS.sub("#", linhas[j]) == chave:
            j += 1
        bloco = j - i
        if bloco >= 3 and linhas[i]:
            saida.append(linhas[i])
            if linhas[j - 1] == linhas[i]:
                saida.append(f"  ⟲ repetida mais {bloco - 1}x")
            else:
                saida.append(f"  ⟲ +{bloco - 2} linhas semelhantes")
                saida.append(linhas[j - 1])
        else:
            saida.extend(linhas[i:j])
        i = j
    return saida


def recortar(linhas, max_linhas, contexto):
    if len(linhas) <= max_linhas:
        return linhas
    cabeca = min(15, max_linhas // 6)
    cauda = max_linhas // 3
    manter = set(range(cabeca)) | set(range(len(linhas) - cauda, len(linhas)))
    orcamento = max_linhas - len(manter)
    erros_fora = 0
    # erros em ordem: o primeiro costuma ser a causa raiz; o fim já está na cauda
    for idx, linha in enumerate(linhas):
        if idx in manter or not IMPORTANTE.search(linha):
            continue
        janela = [k for k in range(idx - contexto, idx + contexto + 1)
                  if 0 <= k < len(linhas) and k not in manter]
        if len(janela) <= orcamento:
            manter.update(janela)
            orcamento -= len(janela)
        else:
            erros_fora += 1
    saida, anterior = [], -1
    for idx in sorted(manter):
        if idx != anterior + 1:
            saida.append(f"… [{idx - anterior - 1} linhas omitidas] …")
        saida.append(linhas[idx])
        anterior = idx
    if erros_fora:
        saida.append(f"… [+{erros_fora} linhas de erro não exibidas; "
                     f"aumente --max-linhas ou filtre com grep] …")
    return saida


def compactar(texto, max_linhas=120, contexto=2, manter_ok=False):
    brutas = limpar(texto)
    linhas = recortar(juntar_repetidas(descartar_ruido(brutas, manter_ok)),
                      max_linhas, contexto)
    return linhas, len(brutas)


def main(argv):
    max_linhas, contexto, manter_ok, comando = 120, 2, False, None
    i = 0
    while i < len(argv):
        arg = argv[i]
        if arg == "--":
            comando = argv[i + 1:]
            break
        if arg in ("-h", "--help"):
            print(__doc__)
            return 0
        if arg == "--max-linhas":
            max_linhas = int(argv[i + 1]); i += 1
        elif arg == "--contexto":
            contexto = int(argv[i + 1]); i += 1
        elif arg == "--manter-ok":
            manter_ok = True
        else:
            print(f"opção desconhecida: {arg} (use -- antes do comando)", file=sys.stderr)
            return 2
        i += 1

    codigo = 0
    if comando:
        usar_shell = len(comando) == 1
        try:
            proc = subprocess.run(comando[0] if usar_shell else comando, shell=usar_shell,
                                  stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        except FileNotFoundError as exc:
            print(f"[compactar] comando não encontrado: {exc.filename}")
            return 127
        texto, codigo = proc.stdout.decode("utf-8", "replace"), proc.returncode
    elif comando == [] or sys.stdin.isatty():
        print(__doc__)
        return 2
    else:
        texto = sys.stdin.buffer.read().decode("utf-8", "replace")

    linhas, total = compactar(texto, max_linhas, contexto, manter_ok)
    if linhas:
        print("\n".join(linhas))
    reducao = f" (−{100 - round(100 * len(linhas) / total)}%)" if total else ""
    rodape = f"[compactar] {total} → {len(linhas)} linhas{reducao}"
    if comando:
        rodape += f" · código de saída {codigo}"
    print(rodape)
    return codigo


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

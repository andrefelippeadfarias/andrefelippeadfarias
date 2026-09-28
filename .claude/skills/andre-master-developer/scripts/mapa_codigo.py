#!/usr/bin/env python3
"""Mapa de símbolos do código: onde está cada classe/função, sem abrir arquivos inteiros.

Uso:
  python3 mapa_codigo.py [CAMINHO]                   mapa de arquivos + símbolos com linha
  python3 mapa_codigo.py [CAMINHO] --simbolo NOME    só definições (e arquivos) cujo nome contém NOME
  python3 mapa_codigo.py [CAMINHO] --arvore          só arquivos e tamanhos, sem símbolos

Opções:
  --max-linhas N   teto de linhas do mapa (padrão 400)
  --sem-md         ignora títulos de Markdown

Respeita o .gitignore (via git ls-files) e sempre ignora dependências, gerados,
lockfiles e minificados. Python é lido por AST; as demais linguagens por padrões.
"""

import ast
import os
import re
import subprocess
import sys
from collections import Counter

IGNORAR_DIRS = {
    ".git", "node_modules", "vendor", "dist", "build", "out", "target", ".next",
    ".nuxt", ".venv", "venv", "env", "__pycache__", ".mypy_cache", ".pytest_cache",
    ".ruff_cache", ".tox", "coverage", ".coverage", "htmlcov", ".cache", ".idea",
    ".vscode", ".gradle", "Pods", "bower_components",
}
IGNORAR_ARQS = re.compile(
    r"(\.min\.(js|css)|\.map|\.lock|-lock\.json|-lock\.yaml|\.pyc|\.so|\.dll|\.exe|"
    r"\.png|\.jpe?g|\.gif|\.webp|\.ico|\.pdf|\.zip|\.gz|\.tar|\.woff2?|\.ttf|\.mp[34])$",
    re.IGNORECASE,
)
ARQUIVO_GRANDE = 400  # linhas; acima disso, leia por trechos

JS = [
    ("func", r"^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*(\w+)\s*(\([^)]*\)?)"),
    ("class", r"^\s*(?:export\s+)?(?:default\s+)?(?:abstract\s+)?class\s+(\w+)"),
    ("func", r"^\s*(?:export\s+)?(?:const|let|var)\s+(\w+)\s*(?::[^=]+)?=\s*(?:async\s+)?"
             r"(?:\([^)]*\)|\w+)\s*(?::[^=]+)?=>"),
    ("tipo", r"^\s*(?:export\s+)?(?:declare\s+)?(?:interface|type|enum)\s+(\w+)"),
    ("metodo", r"^\s{2,}(?:(?:public|private|protected|static|async|readonly|override|get|set)\s+)*"
               r"(?!(?:if|for|while|switch|catch|return|function|constructor)\b)(\w+)\s*(\([^)]*\))"
               r"\s*(?::\s*[^{]+)?\{\s*$"),
]
PADROES = {
    ".py": [("class", r"^\s*class\s+(\w+)"), ("func", r"^\s*(?:async\s+)?def\s+(\w+)\s*(\([^)]*\)?)")],
    ".js": JS, ".jsx": JS, ".ts": JS, ".tsx": JS, ".mjs": JS, ".cjs": JS, ".vue": JS, ".svelte": JS,
    ".go": [("func", r"^func\s+(?:\([^)]*\)\s*)?(\w+)\s*(\([^)]*\)?)"),
            ("tipo", r"^type\s+(\w+)\s+(?:struct|interface)")],
    ".rs": [("func", r"^\s*(?:pub(?:\([^)]*\))?\s+)?(?:async\s+)?(?:unsafe\s+)?fn\s+(\w+)"),
            ("tipo", r"^\s*(?:pub(?:\([^)]*\))?\s+)?(?:struct|enum|trait|mod)\s+(\w+)"),
            ("impl", r"^\s*impl\b(?:<[^>]*>)?\s*([\w:<>, ]+?)\s*\{")],
    ".java": [("class", r"^\s*(?:public|private|protected|abstract|final|static|\s)*"
                        r"(?:class|interface|enum|record)\s+(\w+)"),
              ("metodo", r"^\s+(?:public|private|protected|static|final|abstract|synchronized|\s)+"
                         r"[\w<>\[\],.? ]+\s+(\w+)\s*(\([^)]*\)?)\s*(?:throws [\w., ]+)?\{?\s*$")],
    ".kt": [("class", r"^\s*(?:\w+\s+)*(?:class|interface|object)\s+(\w+)"),
            ("func", r"^\s*(?:\w+\s+)*fun\s+(?:<[^>]*>\s*)?([\w.]+)\s*(\([^)]*\)?)")],
    ".cs": [("class", r"^\s*(?:\w+\s+)*(?:class|interface|enum|record|struct)\s+(\w+)"),
            ("metodo", r"^\s+(?:public|private|protected|internal|static|async|override|virtual|\s)+"
                       r"[\w<>\[\],.? ]+\s+(\w+)\s*(\([^)]*\)?)\s*\{?\s*$")],
    ".php": [("class", r"^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+(\w+)"),
             ("func", r"^\s*(?:(?:public|private|protected|static|abstract|final)\s+)*function\s+(\w+)\s*(\([^)]*\)?)")],
    ".rb": [("class", r"^\s*(?:class|module)\s+([\w:]+)"), ("func", r"^\s*def\s+([\w.?!=]+)")],
    ".sh": [("func", r"^\s*(?:function\s+)?(\w[\w-]*)\s*\(\)\s*\{?"), ("func", r"^\s*function\s+(\w[\w-]*)")],
    ".sql": [("sql", r"(?i)^\s*create\s+(?:or\s+replace\s+)?(?:table|view|function|procedure|trigger|index)"
                     r"\s+(?:if\s+not\s+exists\s+)?([\w.\"]+)")],
    ".ps1": [("func", r"(?i)^\s*function\s+([\w-]+)")],
    ".bat": [("rotulo", r"^:(\w+)")],
    ".c": [("func", r"^[A-Za-z_][\w\s\*]*?\b(\w+)\s*(\([^;{]*\)?)\s*\{?\s*$")],
}
for _ext in (".bash", ".zsh"):
    PADROES[_ext] = PADROES[".sh"]
PADROES[".cmd"] = PADROES[".bat"]
for _ext in (".h", ".cpp", ".cc", ".hpp"):
    PADROES[_ext] = PADROES[".c"]
PADROES_COMPILADOS = {ext: [(k, re.compile(p)) for k, p in ps] for ext, ps in PADROES.items()}
PALAVRAS_C = {"if", "for", "while", "switch", "return", "sizeof", "else", "do"}


def listar_arquivos(raiz):
    try:
        saida = subprocess.run(
            ["git", "-C", raiz, "ls-files", "-co", "--exclude-standard"],
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True,
        ).stdout.decode("utf-8", "replace").splitlines()
        arquivos = [a for a in saida if a]
    except (OSError, subprocess.CalledProcessError):
        arquivos = []
        for pasta, dirs, nomes in os.walk(raiz):
            dirs[:] = sorted(d for d in dirs if d not in IGNORAR_DIRS)
            for nome in nomes:
                arquivos.append(os.path.relpath(os.path.join(pasta, nome), raiz))
    return sorted(
        a for a in arquivos
        if not IGNORAR_ARQS.search(a)
        and not any(p in IGNORAR_DIRS for p in a.replace("\\", "/").split("/")[:-1])
        and os.path.isfile(os.path.join(raiz, a))
    )


def encurtar(assinatura, limite=60):
    assinatura = re.sub(r"\s+", " ", assinatura or "")
    return assinatura if len(assinatura) <= limite else assinatura[:limite - 1] + "…)"


def args_py(no):
    nomes = [a.arg for a in no.args.posonlyargs + no.args.args if a.arg not in ("self", "cls")]
    if no.args.vararg:
        nomes.append("*" + no.args.vararg.arg)
    nomes += [a.arg for a in no.args.kwonlyargs]
    if no.args.kwarg:
        nomes.append("**" + no.args.kwarg.arg)
    return "(" + ", ".join(nomes) + ")"


def simbolos_python(texto):
    arvore = ast.parse(texto)
    simbolos = []

    def visitar(corpo, nivel):
        for no in corpo:
            if isinstance(no, ast.ClassDef):
                simbolos.append((no.lineno, nivel, "class", no.name, ""))
                visitar(no.body, nivel + 1)
            elif isinstance(no, (ast.FunctionDef, ast.AsyncFunctionDef)):
                simbolos.append((no.lineno, nivel, "def", no.name, encurtar(args_py(no))))

    visitar(arvore.body, 0)
    return simbolos


def simbolos_regex(linhas, ext):
    simbolos = []
    for num, linha in enumerate(linhas, 1):
        for tipo, padrao in PADROES_COMPILADOS[ext]:
            m = padrao.match(linha)
            if not m:
                continue
            nome = m.group(1)
            if ext in (".c", ".h", ".cpp", ".cc", ".hpp") and nome in PALAVRAS_C:
                break
            assinatura = m.group(2) if m.lastindex and m.lastindex >= 2 else ""
            nivel = 1 if tipo == "metodo" else 0
            simbolos.append((num, nivel, tipo, nome.strip(), encurtar(assinatura)))
            break
    return simbolos


def simbolos_markdown(linhas):
    simbolos, em_bloco = [], False
    for num, linha in enumerate(linhas, 1):
        if linha.lstrip().startswith("```"):
            em_bloco = not em_bloco
            continue
        m = None if em_bloco else re.match(r"^(#{1,2})\s+(.+?)\s*#*\s*$", linha)
        if m:
            simbolos.append((num, len(m.group(1)) - 1, "#" * len(m.group(1)), m.group(2)[:70], ""))
    return simbolos


def analisar(caminho, ext, com_md):
    try:
        with open(caminho, encoding="utf-8", errors="replace") as f:
            texto = f.read()
    except OSError:
        return None, 0
    linhas = texto.splitlines()
    if ext == ".py":
        try:
            return simbolos_python(texto), len(linhas)
        except (SyntaxError, ValueError):
            return simbolos_regex(linhas, ext), len(linhas)
    if ext in PADROES_COMPILADOS:
        return simbolos_regex(linhas, ext), len(linhas)
    if ext in (".md", ".mdx") and com_md:
        return simbolos_markdown(linhas), len(linhas)
    return None, len(linhas)


def tamanho(caminho):
    b = os.path.getsize(caminho)
    return f"{b}B" if b < 1024 else f"{b / 1024:.0f}KB" if b < 1024 ** 2 else f"{b / 1024 ** 2:.1f}MB"


def main(argv):
    raiz, busca, so_arvore, com_md, max_linhas = ".", None, False, True, 400
    i = 0
    while i < len(argv):
        arg = argv[i]
        if arg in ("-h", "--help"):
            print(__doc__)
            return 0
        if arg == "--simbolo":
            busca = argv[i + 1].lower(); i += 1
        elif arg == "--arvore":
            so_arvore = True
        elif arg == "--sem-md":
            com_md = False
        elif arg == "--max-linhas":
            max_linhas = int(argv[i + 1]); i += 1
        elif arg.startswith("-"):
            print(f"opção desconhecida: {arg}", file=sys.stderr)
            return 2
        else:
            raiz = arg
        i += 1
    if not os.path.isdir(raiz):
        print(f"não é um diretório: {raiz}", file=sys.stderr)
        return 2

    arquivos = listar_arquivos(raiz)
    saida, outros, n_simbolos, n_codigo = [], Counter(), 0, 0

    for rel in arquivos:
        caminho = os.path.join(raiz, rel)
        ext = os.path.splitext(rel)[1].lower()
        if so_arvore:
            saida.append(f"{rel}  {tamanho(caminho)}")
            continue
        simbolos, n_linhas = analisar(caminho, ext, com_md)
        if simbolos is None:
            outros[ext or "(sem ext)"] += 1
            continue
        n_codigo += 1
        if busca is not None:
            if busca in os.path.basename(rel).lower():
                saida.append(f"{rel}  (arquivo, {n_linhas}L)")
            for num, _, tipo, nome, assinatura in simbolos:
                if busca in nome.lower():
                    saida.append(f"{rel}:{num}  {tipo} {nome}{assinatura}")
                    n_simbolos += 1
            continue
        aviso = " ⚠ grande: leia por trechos" if n_linhas > ARQUIVO_GRANDE else ""
        saida.append(f"{rel} [{n_linhas}L{aviso}]")
        limite = 12 if ext in (".md", ".mdx") else None
        for k, (num, nivel, tipo, nome, assinatura) in enumerate(simbolos):
            if limite and k == limite:
                saida.append(f"  … +{len(simbolos) - limite} títulos")
                break
            saida.append(f"  {num:>5} {'  ' * nivel}{tipo} {nome}{assinatura}")
        n_simbolos += len(simbolos)

    if busca is not None and not saida:
        saida.append(f"nenhuma definição contendo '{busca}'. "
                     f"Tente Grep pelo uso (ex.: chamadas) em vez da definição.")
    if len(saida) > max_linhas:
        resto = len(saida) - max_linhas
        saida = saida[:max_linhas]
        saida.append(f"… mapa truncado (+{resto} linhas). Passe um subdiretório, "
                     f"use --simbolo ou --sem-md.")

    if so_arvore:
        cabecalho = f"# árvore: {len(arquivos)} arquivos (raiz: {raiz})"
    elif busca is not None:
        cabecalho = f"# '{busca}': {n_simbolos} definições em {n_codigo} arquivos analisados"
    else:
        cabecalho = f"# mapa: {n_codigo} arquivos, {n_simbolos} símbolos (raiz: {raiz})"
    print(cabecalho)
    if saida:
        print("\n".join(saida))
    if outros and not so_arvore and busca is None:
        resumo = ", ".join(f"{e} {n}" for e, n in outros.most_common(8))
        print(f"# outros {sum(outros.values())} arquivos sem símbolos: {resumo}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

"""Estado local, trava de instância única e diários.

Tudo fica fora do Git, na pasta de dados do usuário. A gravação é atômica
(arquivo temporário + os.replace) e mantém uma cópia .bak.
"""

from __future__ import annotations

import csv
import json
import os
import shutil
import sys
import time
from pathlib import Path


def pasta_padrao() -> Path:
    if sys.platform == "win32":
        base = os.environ.get("LOCALAPPDATA") or str(Path.home() / "AppData" / "Local")
        return Path(base) / "automacao-pricelabs"
    return Path.home() / ".local" / "share" / "automacao-pricelabs"


class OutraExecucao(Exception):
    pass


class Trava:
    """Arquivo criado com O_EXCL. Considerado órfão depois de max_idade_s."""

    def __init__(self, pasta: Path, max_idade_s: float = 1800, relogio=time.time):
        self.caminho = Path(pasta) / "execucao.trava"
        self.max_idade_s = max_idade_s
        self._relogio = relogio

    def __enter__(self):
        self.caminho.parent.mkdir(parents=True, exist_ok=True)
        for _ in range(2):
            try:
                fd = os.open(self.caminho, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            except FileExistsError:
                if self._orfa():
                    self.caminho.unlink(missing_ok=True)
                    continue
                raise OutraExecucao("outra execução em andamento") from None
            with os.fdopen(fd, "w") as f:
                f.write(f"{os.getpid()} {self._relogio():.0f}")
            return self
        raise OutraExecucao("não foi possível obter a trava")

    def _orfa(self) -> bool:
        try:
            criada = float(self.caminho.read_text().split()[1])
        except (OSError, IndexError, ValueError):
            return True
        return self._relogio() - criada > self.max_idade_s

    def __exit__(self, *exc):
        self.caminho.unlink(missing_ok=True)
        return False


def estado_vazio() -> dict:
    return {
        "versao": 1,
        "dsos": {},
        "descontadas": {},
        "jev": {"dia": "", "chamadas": 0, "ultimo_hash": "", "ultima_resposta": None,
                "falhas_seguidas": 0, "ultima_ok_em": ""},
        "contadores": {"dia": "", "criadas": 0},
        "disjuntor": {"ativo": False, "motivo": "", "desde": ""},
        "mercado": {"dia": "", "ocupacao_7d": None},
        "revpar": [],
        "precos_vistos": {},
        "saude": {"ultima_execucao": "", "amarelas_seguidas": 0, "erros_escrita_seguidos": 0},
    }


class Armazem:
    def __init__(self, pasta: Path):
        self.pasta = Path(pasta)
        self.pasta.mkdir(parents=True, exist_ok=True)
        (self.pasta / "execucoes").mkdir(exist_ok=True)
        self.arquivo = self.pasta / "estado.json"
        self.diario = self.pasta / "diario-escritas.jsonl"

    def carregar(self) -> tuple[dict, str]:
        """Devolve (estado, origem). origem: 'ok', 'novo', 'bak' ou 'diario'."""
        for caminho, origem in ((self.arquivo, "ok"), (self.arquivo.with_suffix(".json.bak"), "bak")):
            if caminho.exists():
                try:
                    dados = json.loads(caminho.read_text(encoding="utf-8"))
                    if isinstance(dados, dict) and dados.get("versao") == 1:
                        base = estado_vazio()
                        base.update(dados)
                        return base, origem
                except (OSError, json.JSONDecodeError):
                    pass
        estado = estado_vazio()
        if self.diario.exists():
            estado["dsos"] = self.reconstruir_dsos()
            return estado, "diario"
        return estado, "novo"

    def salvar(self, estado: dict) -> None:
        tmp = self.arquivo.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(estado, ensure_ascii=False, indent=1, sort_keys=True), encoding="utf-8")
        if self.arquivo.exists():
            shutil.copyfile(self.arquivo, self.arquivo.with_suffix(".json.bak"))
        os.replace(tmp, self.arquivo)

    def registrar_escrita(self, evento: dict) -> None:
        """Diário só de acréscimo. Gravado antes e depois de cada escrita."""
        with open(self.diario, "a", encoding="utf-8") as f:
            f.write(json.dumps(evento, ensure_ascii=False, sort_keys=True) + "\n")
            f.flush()
            os.fsync(f.fileno())

    def ler_diario(self) -> list[dict]:
        if not self.diario.exists():
            return []
        eventos = []
        for linha in self.diario.read_text(encoding="utf-8").splitlines():
            try:
                eventos.append(json.loads(linha))
            except json.JSONDecodeError:
                continue
        return eventos

    def reconstruir_dsos(self) -> dict:
        """Substituições criadas com sucesso e ainda não apagadas, segundo o diário."""
        status_por_evento = {"intencao": "pendente", "incerta": "incerta", "criada": "ativa",
                             "adotada": "ativa", "divergente": "divergente"}
        ativas = {}
        for ev in self.ler_diario():
            chave = f"{ev.get('listing')}|{ev.get('data')}"
            evento = ev.get("evento")
            if evento == "intencao" and ev.get("payload"):
                ativas[chave] = {"listing": ev["listing"], "data": ev["data"], "payload": ev["payload"],
                                 "run_id": ev.get("run_id", ""), "criado_em": ev.get("ts", ""),
                                 "status": "pendente", "lido": None}
            elif evento in status_por_evento and chave in ativas:
                ativas[chave]["status"] = status_por_evento[evento]
                if ev.get("lido") is not None:
                    ativas[chave]["lido"] = ev["lido"]
            elif evento in ("falhou", "ausente", "apagada", "expirada"):
                ativas.pop(chave, None)
        return ativas

    def registrar_execucao(self, registro: dict, mes: str) -> None:
        with open(self.pasta / "execucoes" / f"{mes}.jsonl", "a", encoding="utf-8") as f:
            f.write(json.dumps(registro, ensure_ascii=False, sort_keys=True) + "\n")

    def anexar_historico(self, linha: dict) -> None:
        caminho = self.pasta / "historico.csv"
        novo = not caminho.exists()
        with open(caminho, "a", encoding="utf-8-sig" if novo else "utf-8", newline="") as f:
            escritor = csv.DictWriter(f, fieldnames=list(linha.keys()), delimiter=";")
            if novo:
                escritor.writeheader()
            escritor.writerow(linha)

"""Cliente HTTP mínimo com lista fechada de rotas.

Somente rotas conhecidas saem da máquina. Cada escrita (POST ou DELETE de
substituição) só passa se o método estiver em `escritas`, definido pelo modo
de operação. Cabeçalhos nunca entram em mensagens de erro, porque carregam
as chaves.
"""

from __future__ import annotations

import http.client
import json
import re
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Callable, Optional

PRICELABS = "https://api.pricelabs.co/v1"
OPENROUTER = "https://openrouter.ai/api/v1"
TYPESAFE = "https://api.typesafe.ai/v1"

_ID = r"[0-9]+___[0-9]+"

# (método, padrão da URL, tipo). "escrita" altera a conta do PriceLabs.
ROTAS = (
    ("GET", re.escape(PRICELABS) + r"/listings", "leitura"),
    ("POST", re.escape(PRICELABS) + r"/listing_prices", "leitura"),
    ("GET", re.escape(PRICELABS) + r"/listing_metrics", "leitura"),
    ("GET", re.escape(PRICELABS) + r"/reservation_data", "leitura"),
    ("GET", re.escape(PRICELABS) + r"/listings/" + _ID + r"/overrides", "leitura"),
    ("POST", re.escape(PRICELABS) + r"/listings/" + _ID + r"/overrides", "escrita"),
    ("DELETE", re.escape(PRICELABS) + r"/listings/" + _ID + r"/overrides", "escrita"),
    ("POST", re.escape(OPENROUTER) + r"/systemone", "leitura"),
    ("POST", re.escape(TYPESAFE) + r"/systemone", "leitura"),
)

REPETIVEIS = {408, 429, 500, 502, 503, 504, 529}

# O firewall da PriceLabs recusa (403) a assinatura padrão "Python-urllib/x.y".
AGENTE = "automacao-pricelabs/0.1"


class ErroRede(Exception):
    """Falha de rede ou HTTP. Nunca contém cabeçalhos nem corpo enviado."""

    def __init__(self, mensagem: str, status: Optional[int] = None, codigo: str = ""):
        super().__init__(mensagem)
        self.status = status
        self.codigo = codigo


class RotaProibida(ErroRede):
    pass


@dataclass
class Resposta:
    status: int
    dados: object
    cabecalhos: dict


Transporte = Callable[[str, str, dict, Optional[bytes], float], tuple]


class _SemRedirecionar(urllib.request.HTTPRedirectHandler):
    """Redirecionamento levaria as chaves para outro endereço: vira erro HTTP 3xx."""

    def redirect_request(self, *args, **kwargs):
        return None


_ABRIDOR = urllib.request.build_opener(_SemRedirecionar)


def transporte_urllib(metodo: str, url: str, cabecalhos: dict, corpo: Optional[bytes], timeout: float):
    pedido = urllib.request.Request(url, data=corpo, method=metodo, headers=cabecalhos)
    try:
        with _ABRIDOR.open(pedido, timeout=timeout) as r:
            return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read()
    except urllib.error.HTTPError as e:
        try:
            corpo_erro = e.read() or b""
        except (OSError, http.client.HTTPException):
            corpo_erro = b""
        return e.code, {k.lower(): v for k, v in (e.headers or {}).items()}, corpo_erro
    except (urllib.error.URLError, TimeoutError, OSError, http.client.HTTPException, ValueError) as e:
        raise ErroRede(f"sem conexão: {type(e).__name__}") from None


def classificar(metodo: str, url: str) -> Optional[str]:
    base = url.split("?", 1)[0]
    for m, padrao, tipo in ROTAS:
        if m == metodo and re.fullmatch(padrao, base):
            return tipo
    return None


def _codigo_erro(dados) -> str:
    if isinstance(dados, dict):
        erro = dados.get("error") or dados.get("detail")
        if isinstance(erro, dict):
            return str(erro.get("code") or erro.get("error_type") or "")[:60]
        if isinstance(erro, str):
            return erro[:60]
    return ""


class Rede:
    def __init__(
        self,
        transporte: Transporte = transporte_urllib,
        escritas: frozenset = frozenset(),
        espaco_s: float = 1.1,
        dormir: Callable[[float], None] = time.sleep,
        relogio: Callable[[], float] = time.monotonic,
    ):
        self._transporte = transporte
        self.escritas = frozenset(escritas)
        self._espaco = espaco_s
        self._dormir = dormir
        self._relogio = relogio
        self._ultima = None
        self.chamadas = []  # (método, rota sem query, status) para auditoria

    def pedir(self, metodo, url, cabecalhos, corpo=None, timeout=60.0, novas_tentativas=2):
        tipo = classificar(metodo, url)
        if tipo is None:
            raise RotaProibida(f"rota fora da lista: {metodo} {url.split('?', 1)[0]}")
        if tipo == "escrita" and metodo not in self.escritas:
            raise RotaProibida(f"escrita bloqueada neste modo: {metodo}")
        bruto = None if corpo is None else json.dumps(corpo).encode("utf-8")
        cab = dict(cabecalhos)
        cab.setdefault("Accept", "application/json")
        cab.setdefault("User-Agent", AGENTE)
        if bruto is not None:
            cab["Content-Type"] = "application/json"
        tentativa = 0
        while True:
            self._espacar()
            try:
                status, cab_resp, conteudo = self._transporte(metodo, url, cab, bruto, timeout)
            except ErroRede:
                if tentativa < novas_tentativas:
                    tentativa += 1
                    self._dormir(2.0 * tentativa)
                    continue
                self.chamadas.append((metodo, url.split("?", 1)[0], None))
                raise
            self.chamadas.append((metodo, url.split("?", 1)[0], status))
            dados = _json(conteudo)
            if 200 <= status < 300:
                return Resposta(status, dados, cab_resp)
            if status in REPETIVEIS and tentativa < novas_tentativas:
                tentativa += 1
                self._dormir(_espera(cab_resp, tentativa))
                continue
            raise ErroRede(f"HTTP {status}", status=status, codigo=_codigo_erro(dados))

    def _espacar(self):
        agora = self._relogio()
        if self._ultima is not None:
            falta = self._espaco - (agora - self._ultima)
            if falta > 0:
                self._dormir(falta)
        self._ultima = self._relogio()


def _json(conteudo: bytes):
    if not conteudo:
        return None
    try:
        return json.loads(conteudo.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None


def _espera(cabecalhos: dict, tentativa: int) -> float:
    try:
        pedido = float(cabecalhos.get("retry-after", ""))
        return max(0.0, min(pedido, 30.0))
    except ValueError:
        return 2.0 * tentativa

"""Leitura das chaves de API.

No Windows, as chaves ficam no Gerenciador de Credenciais (protegidas pela
conta do usuário). Em outros sistemas, ou para testes, usa variáveis de
ambiente. As chaves nunca são gravadas em arquivo pelo programa.
"""

from __future__ import annotations

import os
import sys

ALVOS = {
    "pricelabs": ("automacao-pricelabs/pricelabs", "PRICELABS_API_KEY"),
    "openrouter": ("automacao-pricelabs/openrouter", "OPENROUTER_API_KEY"),
    "typesafe": ("automacao-pricelabs/typesafe", "TYPESAFE_API_KEY"),
}


class ChaveAusente(Exception):
    pass


def ler(nome: str, ambiente=os.environ, cofre=None) -> str:
    alvo, variavel = ALVOS[nome]
    cofre = cofre if cofre is not None else _cofre_padrao()
    valor = cofre.ler(alvo) if cofre else None
    if not valor:
        valor = ambiente.get(variavel, "")
    valor = (valor or "").strip()
    if not valor:
        raise ChaveAusente(f"chave '{nome}' não configurada (rode configurar-chaves)")
    if not valor.isascii() or not valor.isprintable() or " " in valor:
        raise ChaveAusente(f"chave '{nome}' com caracteres inválidos")
    return valor


def salvar(nome: str, valor: str, cofre=None) -> None:
    alvo, _ = ALVOS[nome]
    cofre = cofre if cofre is not None else _cofre_padrao()
    if cofre is None:
        raise ChaveAusente("Gerenciador de Credenciais só existe no Windows; use variáveis de ambiente")
    cofre.salvar(alvo, valor.strip())


def _cofre_padrao():
    return CofreWindows() if sys.platform == "win32" else None


class CofreWindows:  # pragma: no cover - só roda no Windows
    """Acesso ao Gerenciador de Credenciais pela API do Windows (advapi32)."""

    def __init__(self):
        import ctypes
        from ctypes import wintypes as wt

        class CREDENTIAL(ctypes.Structure):
            _fields_ = [
                ("Flags", wt.DWORD),
                ("Type", wt.DWORD),
                ("TargetName", wt.LPWSTR),
                ("Comment", wt.LPWSTR),
                ("LastWritten", wt.FILETIME),
                ("CredentialBlobSize", wt.DWORD),
                ("CredentialBlob", ctypes.POINTER(ctypes.c_char)),
                ("Persist", wt.DWORD),
                ("AttributeCount", wt.DWORD),
                ("Attributes", ctypes.c_void_p),
                ("TargetAlias", wt.LPWSTR),
                ("UserName", wt.LPWSTR),
            ]

        self._ct = ctypes
        self._CRED = CREDENTIAL
        self._api = ctypes.WinDLL("advapi32", use_last_error=True)
        self._api.CredReadW.argtypes = [wt.LPCWSTR, wt.DWORD, wt.DWORD, ctypes.POINTER(ctypes.POINTER(CREDENTIAL))]
        self._api.CredReadW.restype = wt.BOOL
        self._api.CredWriteW.argtypes = [ctypes.POINTER(CREDENTIAL), wt.DWORD]
        self._api.CredWriteW.restype = wt.BOOL
        self._api.CredFree.argtypes = [ctypes.c_void_p]

    def ler(self, alvo: str):
        ct = self._ct
        ponteiro = ct.POINTER(self._CRED)()
        if not self._api.CredReadW(alvo, 1, 0, ct.byref(ponteiro)):
            return None
        try:
            cred = ponteiro.contents
            return ct.string_at(cred.CredentialBlob, cred.CredentialBlobSize).decode("utf-8")
        finally:
            self._api.CredFree(ponteiro)

    def salvar(self, alvo: str, valor: str) -> None:
        ct = self._ct
        dados = valor.encode("utf-8")
        blob = ct.create_string_buffer(dados, len(dados))
        cred = self._CRED()
        cred.Type = 1  # CRED_TYPE_GENERIC
        cred.TargetName = alvo
        cred.CredentialBlobSize = len(dados)
        cred.CredentialBlob = ct.cast(blob, ct.POINTER(ct.c_char))
        cred.Persist = 2  # CRED_PERSIST_LOCAL_MACHINE
        cred.UserName = "automacao-pricelabs"
        if not self._api.CredWriteW(ct.byref(cred), 0):
            raise ChaveAusente(f"falha ao gravar no Gerenciador de Credenciais (erro {ct.get_last_error()})")

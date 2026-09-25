"""Chamada única ao Jev (System One) e validação estrita da resposta.

Qualquer desvio do contrato invalida a resposta inteira. Quem chama trata
isso como "não agir".
"""

from __future__ import annotations

import math
import uuid
from dataclasses import dataclass

from .rede import OPENROUTER, TYPESAFE, ErroRede, Rede

PROVEDORES = {
    "openrouter": {"url": f"{OPENROUTER}/systemone", "chave": "openrouter", "modelo": "typesafe/jev-1.13:free"},
    "typesafe": {"url": f"{TYPESAFE}/systemone", "chave": "typesafe", "modelo": "jev-1.13.0"},
}


class JevInvalido(Exception):
    pass


@dataclass
class RespostaJev:
    modelo: str
    respostas: dict
    tokens_entrada: int
    request_id: str
    chamada_id: str = ""


def perguntar(rede: Rede, provedor: str, chave: str, modelo: str, estado: dict, perguntas: dict,
              familia_modelo: str = "jev-1.13", timeout: float = 60.0) -> RespostaJev:
    if provedor not in PROVEDORES:
        raise JevInvalido(f"provedor desconhecido: {provedor}")
    if not perguntas:
        raise JevInvalido("nenhuma pergunta")
    corpo = {"state": estado, "model": modelo, "questions": perguntas}
    r = rede.pedir("POST", PROVEDORES[provedor]["url"], {"Authorization": f"Bearer {chave}"}, corpo,
                   timeout=timeout, novas_tentativas=2)
    resposta = validar(r.dados, perguntas, familia_modelo, r.cabecalhos.get("x-typesafe-request-id", ""))
    resposta.chamada_id = uuid.uuid4().hex  # identifica esta chamada real, mesmo sem request-id do provedor
    return resposta


def _prob(v) -> bool:
    return isinstance(v, (int, float)) and not isinstance(v, bool) and math.isfinite(v) and 0.0 <= v <= 1.0


def validar(dados, perguntas: dict, familia_modelo: str, request_id: str = "") -> RespostaJev:
    if not isinstance(dados, dict):
        raise JevInvalido("resposta não é objeto")
    modelo = dados.get("model")
    if not isinstance(modelo, str) or familia_modelo not in modelo:
        raise JevInvalido(f"modelo inesperado: {str(modelo)[:40]}")
    respostas = dados.get("answers")
    if not isinstance(respostas, dict) or set(respostas) != set(perguntas):
        raise JevInvalido("respostas não correspondem às perguntas")
    for pid, pergunta in perguntas.items():
        _validar_resposta(pid, pergunta, respostas[pid])
    uso = dados.get("usage") or {}
    tokens = uso.get("input_tokens") if isinstance(uso, dict) else None
    if not isinstance(tokens, int) or tokens < 0:
        tokens = 0
    return RespostaJev(modelo, respostas, tokens, str(request_id)[:80])


def _validar_resposta(pid: str, pergunta: dict, resp) -> None:
    tipo = pergunta["type"]
    if not isinstance(resp, dict) or resp.get("type") != tipo:
        raise JevInvalido(f"{pid}: tipo divergente")
    if tipo == "noul":
        if not _prob(resp.get("noul")):
            raise JevInvalido(f"{pid}: noul fora de 0..1")
        return
    if tipo != "choice":
        raise JevInvalido(f"{pid}: tipo não suportado")
    opcoes = set(pergunta["criteria"])
    probs = resp.get("probabilities")
    if not isinstance(probs, dict) or set(probs) != opcoes or not all(_prob(v) for v in probs.values()):
        raise JevInvalido(f"{pid}: probabilidades inválidas")
    if abs(sum(probs.values()) - 1.0) > 0.01:
        raise JevInvalido(f"{pid}: probabilidades não somam 1")
    escolha = resp.get("choice")
    if escolha not in opcoes or probs[escolha] + 1e-9 < max(probs.values()):
        raise JevInvalido(f"{pid}: escolha inválida")
    if not _prob(resp.get("confidence")):
        raise JevInvalido(f"{pid}: confiança fora de 0..1")


def margem(resp: dict) -> float:
    """Diferença entre a opção escolhida e a segunda mais provável."""
    valores = sorted(resp["probabilities"].values(), reverse=True)
    return valores[0] - (valores[1] if len(valores) > 1 else 0.0)


__all__ = ["PROVEDORES", "JevInvalido", "RespostaJev", "perguntar", "validar", "margem", "ErroRede"]

"""Leitura e validação do config.json.

A validação repete as regras de schemas/config.schema.json sem depender de
bibliotecas externas. Os limites de segurança (pisos dos limiares do Jev e
tetos de desconto) são rígidos: a configuração só pode apertá-los.
"""

from __future__ import annotations

import json
import re
from pathlib import Path


class ConfigInvalida(Exception):
    pass


PISOS_JEV = {"confianca_min": 0.80, "probabilidade_min": 0.70, "margem_min": 0.30}
TETO_VETO = 0.30
TETOS_DESCONTO = {"teto_execucao": 3, "teto_dia": 6, "teto_ativas": 6}
_ID = re.compile(r"^[0-9]+___[0-9]+$")
_HORA = re.compile(r"^([01][0-9]|2[0-3]):[0-5][0-9]$")
_DATA = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}$")


def carregar(caminho) -> dict:
    try:
        dados = json.loads(Path(caminho).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as e:
        raise ConfigInvalida(f"não foi possível ler {caminho}: {e}") from None
    validar(dados)
    return dados


def _exigir(condicao: bool, mensagem: str) -> None:
    if not condicao:
        raise ConfigInvalida(mensagem)


def _num(v) -> bool:
    return isinstance(v, (int, float)) and not isinstance(v, bool)


def validar(c: dict) -> None:
    _exigir(isinstance(c, dict), "config deve ser um objeto")
    permitidas = {"versao", "modo", "pms", "fuso_utc", "horarios_sincronizacao", "janela_criacao_horas", "metas",
                  "mercado_visibilidade", "listings", "desconto", "jev", "feriados_locais", "pasta_dados",
                  "area_de_trabalho"}
    obrigatorias = permitidas - {"feriados_locais", "pasta_dados", "area_de_trabalho"}
    _exigir(obrigatorias <= set(c), f"faltam campos: {sorted(obrigatorias - set(c))}")
    _exigir(set(c) <= permitidas, f"campos desconhecidos: {sorted(set(c) - permitidas)}")
    _exigir(c["versao"] == 1, "versao deve ser 1")
    _exigir(c["modo"] in ("observar", "ativo"), "modo deve ser 'observar' ou 'ativo'")
    _exigir(isinstance(c["pms"], str) and c["pms"], "pms vazio")
    _exigir(isinstance(c["fuso_utc"], int) and -12 <= c["fuso_utc"] <= 14, "fuso_utc inválido")
    horas = c["horarios_sincronizacao"]
    _exigir(isinstance(horas, list) and 1 <= len(horas) <= 24 and len(set(horas)) == len(horas)
            and all(isinstance(h, str) and _HORA.match(h) for h in horas), "horarios_sincronizacao inválidos")
    _exigir(_num(c["janela_criacao_horas"]) and 0 < c["janela_criacao_horas"] <= 12, "janela_criacao_horas inválida")
    metas = c["metas"]
    _exigir(isinstance(metas, dict) and set(metas) == {"0-6", "7-14", "15-29"}
            and all(_num(v) and 0 <= v <= 100 for v in metas.values()), "metas inválidas")
    _exigir(_num(c["mercado_visibilidade"]) and 0 <= c["mercado_visibilidade"] <= 100, "mercado_visibilidade inválido")
    _validar_listings(c["listings"])
    _validar_desconto(c["desconto"])
    _validar_jev(c["jev"])
    feriados = c.get("feriados_locais", [])
    _exigir(isinstance(feriados, list) and all(isinstance(d, str) and _DATA.match(d) for d in feriados),
            "feriados_locais inválidos")
    for campo in ("pasta_dados", "area_de_trabalho"):
        _exigir(isinstance(c.get(campo, ""), str), f"{campo} deve ser texto")


def _validar_listings(listings) -> None:
    _exigir(isinstance(listings, list) and 1 <= len(listings) <= 7, "listings: de 1 a 7 itens")
    ids, codigos = set(), set()
    chaves = {"id", "apelido", "codigo", "unidades", "papel", "desconto_permitido", "pendencia"}
    for item in listings:
        _exigir(isinstance(item, dict) and set(item) <= chaves and chaves - {"pendencia"} <= set(item),
                "listing com campos inválidos")
        _exigir(isinstance(item["id"], str) and bool(_ID.match(item["id"])), f"id inválido: {item.get('id')}")
        _exigir(isinstance(item["apelido"], str) and 0 < len(item["apelido"]) <= 60, "apelido inválido")
        _exigir(isinstance(item["codigo"], str) and re.fullmatch(r"R[1-7]", item["codigo"]) is not None, "codigo inválido")
        _exigir(isinstance(item["unidades"], int) and not isinstance(item["unidades"], bool)
                and 1 <= item["unidades"] <= 50, "unidades inválidas")
        _exigir(item["papel"] in ("tabela", "sem_tabela", "transbordo"), "papel inválido")
        _exigir(isinstance(item["desconto_permitido"], bool), "desconto_permitido deve ser verdadeiro/falso")
        _exigir(not (item["desconto_permitido"] and item["papel"] != "sem_tabela"),
                f"{item['apelido']}: desconto só é permitido em quarto sem tabela de ocupação")
        _exigir(isinstance(item.get("pendencia", ""), str), "pendencia deve ser texto")
        _exigir(not (item["desconto_permitido"] and item.get("pendencia")),
                f"{item['apelido']}: resolva a pendência antes de permitir desconto")
        _exigir(item["id"] not in ids and item["codigo"] not in codigos, "id ou codigo repetido")
        ids.add(item["id"])
        codigos.add(item["codigo"])


def _validar_desconto(d) -> None:
    chaves = {"percentual", "vida_horas", "teto_execucao", "teto_dia", "teto_ativas", "sem_repetir_dias"}
    _exigir(isinstance(d, dict) and set(d) == chaves, "desconto com campos inválidos")
    _exigir(d["percentual"] in (-5, -7, -10) and not isinstance(d["percentual"], bool),
            "percentual deve ser -5, -7 ou -10")
    _exigir(_num(d["vida_horas"]) and 12 <= d["vida_horas"] <= 48, "vida_horas deve ficar entre 12 e 48")
    for campo, teto in TETOS_DESCONTO.items():
        _exigir(isinstance(d[campo], int) and not isinstance(d[campo], bool) and 0 <= d[campo] <= teto,
                f"{campo} deve ficar entre 0 e {teto}")
    _exigir(isinstance(d["sem_repetir_dias"], int) and 7 <= d["sem_repetir_dias"] <= 60,
            "sem_repetir_dias deve ficar entre 7 e 60")


def _validar_jev(j) -> None:
    chaves = {"provedor", "modelo", "familia_modelo", "provedor_reserva", "modelo_reserva", "max_chamadas_dia",
              "confianca_min", "probabilidade_min", "margem_min", "veto_max", "confirmar_duas_execucoes",
              "medir_estabilidade"}
    obrig = chaves - {"provedor_reserva", "modelo_reserva"}
    _exigir(isinstance(j, dict) and obrig <= set(j) <= chaves, "jev com campos inválidos")
    _exigir(j["provedor"] in ("openrouter", "typesafe"), "provedor inválido")
    _exigir(j.get("provedor_reserva", "") in ("", "typesafe"), "provedor_reserva inválido")
    for campo in ("modelo", "familia_modelo"):
        _exigir(isinstance(j[campo], str) and 3 <= len(j[campo]) <= 80, f"{campo} inválido")
    _exigir(isinstance(j.get("modelo_reserva", ""), str), "modelo_reserva inválido")
    _exigir(isinstance(j["max_chamadas_dia"], int) and 0 <= j["max_chamadas_dia"] <= 7, "max_chamadas_dia entre 0 e 7")
    for campo, piso in PISOS_JEV.items():
        _exigir(_num(j[campo]) and piso <= j[campo] <= 1, f"{campo} não pode ser menor que {piso}")
    _exigir(_num(j["veto_max"]) and 0 <= j["veto_max"] <= TETO_VETO, f"veto_max não pode passar de {TETO_VETO}")
    for campo in ("confirmar_duas_execucoes", "medir_estabilidade"):
        _exigir(isinstance(j[campo], bool), f"{campo} deve ser verdadeiro/falso")

"""Escritas seguras no PriceLabs: criar, conferir, conciliar e apagar.

Ordem de uma criação: releitura da data, intenção no diário, POST sem nova
tentativa, releitura de conferência. Só apaga substituição idêntica à que
o programa registrou; qualquer diferença vira alerta.
"""

from __future__ import annotations

import math
from datetime import datetime

from .rede import ErroRede
from .tempo import ler_data

EXTRAS = ("min_stay", "min_price", "max_price", "base_price", "lead_time_expiry")


def _num(v):
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    return x if math.isfinite(x) else None


def _preenchido(v) -> bool:
    if v is None or (isinstance(v, str) and v.strip() == ""):
        return False
    n = _num(v)
    return not (n is not None and n in (0.0, -1.0))


def normalizar(o: dict) -> dict:
    norm = {"date": str(o.get("date", ""))[:10], "price": _num(o.get("price")),
            "price_type": str(o.get("price_type") or "")}
    if o.get("reason"):
        norm["reason"] = str(o["reason"])
    extras = {c: str(o[c]) for c in EXTRAS if _preenchido(o.get(c))}
    if str(o.get("check_in_check_out_enabled") or "0").lower() in ("1", "true"):
        extras["check_in_check_out_enabled"] = "1"
    norm["extras"] = extras
    return norm


def corresponde(norm: dict, payload: dict) -> bool:
    """A substituição lida é a que o programa enviou?"""
    return (norm["date"] == payload["date"] and norm["price"] is not None
            and abs(norm["price"] - float(payload["price"])) < 0.01 and norm["price_type"] == "percent"
            and not norm["extras"] and norm.get("reason", payload["reason"]) == payload["reason"])


def identica(atual: dict, registrada: dict) -> bool:
    if not registrada:
        return False
    chaves = ("date", "price", "price_type", "extras")
    if any(atual.get(k) != registrada.get(k) for k in chaves):
        return False
    return atual.get("reason", registrada.get("reason")) == registrada.get("reason", atual.get("reason"))


class Executor:
    def __init__(self, pricelabs, armazem, estado: dict, run_id: str, agora: datetime, parar=lambda: False):
        self.pl = pricelabs
        self.armazem = armazem
        self.estado = estado
        self.run_id = run_id
        self.agora = agora
        self.parar = parar
        self.alertas = []
        self.disjuntor = ""

    # -- apoio -------------------------------------------------------------
    def _evento(self, evento: str, listing: str, data: str, **extra) -> None:
        registro = {"ts": self.agora.isoformat(), "run_id": self.run_id, "evento": evento,
                    "listing": listing, "data": data}
        registro.update({k: v for k, v in extra.items() if v is not None})
        self.armazem.registrar_escrita(registro)

    def _salvar(self) -> None:
        self.armazem.salvar(self.estado)

    def _lidas(self, listing: str, data: str) -> list:
        """Substituições da data. Item com data ilegível conta como existente (falha fechada)."""
        alvo = ler_data(data)
        lidas = []
        for o in self.pl.substituicoes(listing, data, data):
            d = ler_data(o.get("date"))
            if d is None or d == alvo:
                lidas.append(o)
        return lidas

    def acionar_disjuntor(self, motivo: str) -> None:
        """Grava o disjuntor na hora, no estado e no diário, para sobreviver a qualquer falha."""
        if self.disjuntor:
            return
        self.disjuntor = motivo
        self.estado["disjuntor"] = {"ativo": True, "motivo": motivo, "desde": self.agora.isoformat()}
        self.armazem.registrar_escrita({"ts": self.agora.isoformat(), "run_id": self.run_id,
                                        "evento": "disjuntor", "motivo": motivo[:200]})
        self._salvar()

    def _erro_escrita(self, motivo: str) -> None:
        saude = self.estado["saude"]
        saude["erros_escrita_seguidos"] = saude.get("erros_escrita_seguidos", 0) + 1
        if saude["erros_escrita_seguidos"] >= 2:
            self.acionar_disjuntor(f"2 erros de escrita seguidos ({motivo})")

    # -- criar -------------------------------------------------------------
    def criar(self, acao: dict) -> str:
        lid, data, payload = acao["listing"], acao["data"], acao["payload"]
        chave = f"{lid}|{data}"
        if self.parar():
            return "bloqueado: arquivo PARAR"
        if self.disjuntor:
            return "bloqueado: disjuntor"
        try:
            if self._lidas(lid, data):
                return "pulado: a data já tem substituição"
        except ErroRede as e:
            return f"pulado: releitura falhou ({e})"
        self._evento("intencao", lid, data, payload=payload)
        self.estado["dsos"][chave] = {"listing": lid, "data": data, "payload": payload, "run_id": self.run_id,
                                      "criado_em": self.agora.isoformat(), "status": "pendente", "lido": None}
        cont = self.estado["contadores"]
        hoje = self.agora.date().isoformat()
        if cont.get("dia") != hoje:
            cont["dia"], cont["criadas"] = hoje, 0
        cont["criadas"] += 1
        self.estado["descontadas"][chave] = self.agora.isoformat()
        self._salvar()
        try:
            self.pl.criar_substituicoes(lid, [payload])
        except ErroRede as e:
            if e.status is not None and 400 <= e.status < 500:
                self._evento("falhou", lid, data, payload=payload, motivo=str(e))
                del self.estado["dsos"][chave]
                self._erro_escrita(str(e))
                self._salvar()
                return f"falhou: {e}"
            self.estado["dsos"][chave]["status"] = "incerta"
            self._evento("incerta", lid, data, payload=payload, motivo=str(e))
            self._erro_escrita(str(e))
            self._salvar()
            return "incerto: será conferido na próxima execução"
        return self._conferir(chave)

    def _conferir(self, chave: str) -> str:
        dso = self.estado["dsos"][chave]
        lid, data, payload = dso["listing"], dso["data"], dso["payload"]
        try:
            lidas = self._lidas(lid, data)
        except ErroRede as e:
            dso["status"] = "incerta"
            self._evento("incerta", lid, data, payload=payload, motivo=f"releitura falhou: {e}")
            self._salvar()
            return "incerto: releitura falhou"
        normas = [normalizar(o) for o in lidas]
        if len(normas) == 1 and corresponde(normas[0], payload):
            dso.update(status="ativa", lido=normas[0])
            self._evento("criada", lid, data, payload=payload, lido=normas[0])
            self.estado["saude"]["erros_escrita_seguidos"] = 0
            self._salvar()
            return "criada"
        if not normas:
            dso["status"] = "incerta"  # continua rastreada; a próxima execução concilia
            self._evento("incerta", lid, data, payload=payload, motivo="POST aceito mas a releitura não achou")
        else:
            dso.update(status="divergente", lido_divergente=normas[0])
            self._evento("divergente", lid, data, payload=payload, lido=normas[0], motivo="releitura difere do enviado")
        self.acionar_disjuntor("a releitura não confirmou a substituição gravada")
        return "não confirmada: disjuntor acionado"

    # -- conciliar ---------------------------------------------------------
    def conciliar(self) -> list[str]:
        resultados = []
        hoje = self.agora.date().isoformat()
        for chave, dso in list(self.estado["dsos"].items()):
            lid, data, payload = dso["listing"], dso["data"], dso.get("payload")
            if data < hoje:
                del self.estado["dsos"][chave]
                self._evento("expirada", lid, data, payload=payload, motivo="a data passou")
                continue
            if dso.get("status") not in ("pendente", "incerta"):
                continue
            try:
                lidas = self._lidas(lid, data)
            except ErroRede:
                resultados.append(f"{data}: conciliação adiada")
                continue
            normas = [normalizar(o) for o in lidas]
            if not normas:
                del self.estado["dsos"][chave]
                self._evento("ausente", lid, data, payload=payload, motivo="gravação incerta não se confirmou")
                resultados.append(f"{data}: gravação anterior não existe")
            elif len(normas) == 1 and corresponde(normas[0], payload):
                dso.update(status="ativa", lido=normas[0])
                self._evento("adotada", lid, data, payload=payload, lido=normas[0])
                resultados.append(f"{data}: gravação anterior confirmada")
            else:
                dso.update(status="divergente", lido_divergente=normas[0])
                self._evento("divergente", lid, data, payload=payload, lido=normas[0],
                             motivo="gravação incerta difere do enviado")
                self.alertas.append(f"Substituição em {data} difere do que o programa enviou: revisar na tela")
                self.acionar_disjuntor(f"gravação incerta em {data} não confere com o enviado")
        self._salvar()
        return resultados

    # -- apagar ------------------------------------------------------------
    def apagar(self, acao: dict, respeitar_parar: bool = True) -> str:
        lid, data = acao["listing"], acao["data"]
        chave = f"{lid}|{data}"
        dso = self.estado["dsos"].get(chave)
        if dso is None:
            return "ignorado: não é do programa"
        if dso.get("status") == "divergente" or not dso.get("lido"):
            self.alertas.append(f"Substituição em {data} não confere com o que o programa gravou: revisar na tela")
            return "não apagada: não confere com o que o programa gravou"
        if respeitar_parar and self.parar():
            return "bloqueado: arquivo PARAR"
        try:
            lidas = self._lidas(lid, data)
        except ErroRede as e:
            return f"adiado: releitura falhou ({e})"
        normas = [normalizar(o) for o in lidas]
        if not normas:
            del self.estado["dsos"][chave]
            self._evento("ausente", lid, data, motivo="já não existia na conta")
            self._salvar()
            return "já ausente"
        if len(normas) != 1 or not identica(normas[0], dso.get("lido")):
            dso.update(status="divergente", lido_divergente=normas[0])
            self._evento("divergente", lid, data, lido=normas[0], motivo="alterada fora do programa; não apagada")
            self.alertas.append(f"Substituição em {data} foi alterada fora do programa e não foi apagada")
            self._salvar()
            return "não apagada: divergente"
        try:
            self.pl.apagar_substituicoes(lid, [data])
            restantes = self._lidas(lid, data)
        except ErroRede as e:
            self._erro_escrita(str(e))
            self._salvar()
            return f"falhou: {e}"
        if restantes:
            self._erro_escrita("DELETE aceito mas a substituição continua")
            self._salvar()
            return "falhou: continua na conta"
        del self.estado["dsos"][chave]
        self._evento("apagada", lid, data, motivo=acao.get("motivo", ""))
        self.estado["saude"]["erros_escrita_seguidos"] = 0
        self._salvar()
        return "apagada"

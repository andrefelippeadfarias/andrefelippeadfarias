"""Chamadas à PriceLabs Customer API usadas pela automação.

Só aceita as listings configuradas. As escritas (criar e apagar substituição
por data) passam pela lista fechada de rotas do módulo rede e só funcionam
se o cliente de rede permitir escrita.
"""

from __future__ import annotations

import urllib.parse

from .rede import PRICELABS, ErroRede, Rede

MAX_PAGINAS = 20


class ListingProibida(ErroRede):
    pass


class PriceLabs:
    def __init__(self, rede: Rede, chave: str, pms: str, ids_permitidos, timeout: float = 120.0):
        self._rede = rede
        self._chave = chave
        self.pms = pms
        self.ids = set(ids_permitidos)
        self._timeout = timeout

    def _cab(self):
        return {"X-API-Key": self._chave}

    def _checar(self, listing_id: str) -> str:
        if listing_id not in self.ids:
            raise ListingProibida(f"listing fora da configuração: {listing_id}")
        return urllib.parse.quote(listing_id, safe="_")

    def _get(self, caminho, params):
        url = f"{PRICELABS}{caminho}?{urllib.parse.urlencode(params)}" if params else f"{PRICELABS}{caminho}"
        return self._rede.pedir("GET", url, self._cab(), timeout=self._timeout).dados

    def listings(self) -> list:
        dados = self._get("/listings", {"skip_hidden": "false"})
        lista = dados.get("listings") if isinstance(dados, dict) else None
        if not isinstance(lista, list):
            raise ErroRede("resposta de /listings fora do contrato")
        return [x for x in lista if isinstance(x, dict) and x.get("id") in self.ids]

    def calendario(self, ids, de: str, ate: str) -> list:
        corpo = {"listings": [{"id": i, "pms": self.pms, "dateFrom": de, "dateTo": ate} for i in ids if self._checar(i)]}
        dados = self._rede.pedir("POST", f"{PRICELABS}/listing_prices", self._cab(), corpo, timeout=self._timeout).dados
        if not isinstance(dados, list):
            raise ErroRede("resposta de /listing_prices fora do contrato")
        return [x for x in dados if isinstance(x, dict) and x.get("id") in self.ids]

    def metricas(self, listing_id: str) -> dict:
        self._checar(listing_id)
        dados = self._get("/listing_metrics", {"listing_id": listing_id, "pms_name": self.pms})
        if not isinstance(dados, dict) or not isinstance(dados.get("data"), dict):
            raise ErroRede("resposta de /listing_metrics fora do contrato")
        return dados["data"]

    def reservas(self, checkin_de: str, checkin_ate_exclusivo: str) -> list:
        """Reservas (inclusive canceladas) com chegada no intervalo [de, ate)."""
        linhas, offset = [], 0
        for _ in range(MAX_PAGINAS):
            dados = self._get("/reservation_data", {
                "pms": self.pms, "start_date": checkin_de, "end_date": checkin_ate_exclusivo,
                "limit": 100, "offset": offset,
            })
            if not isinstance(dados, dict) or not isinstance(dados.get("data"), list):
                raise ErroRede("resposta de /reservation_data fora do contrato")
            pagina = dados["data"]
            linhas.extend(r for r in pagina if isinstance(r, dict) and r.get("listing_id") in self.ids)
            if not pagina or (not dados.get("next_page") and len(pagina) < 100):
                return linhas  # página cheia sem next_page: segue para não perder reservas
            offset += len(pagina)
        raise ErroRede("paginação de reservas excedeu o limite")

    def substituicoes(self, listing_id: str, de: str, ate: str) -> list:
        lid = self._checar(listing_id)
        dados = self._get(f"/listings/{lid}/overrides", {"pms": self.pms, "start_date": de, "end_date": ate})
        lista = dados.get("overrides") if isinstance(dados, dict) else None
        if not isinstance(lista, list):
            raise ErroRede("resposta de overrides fora do contrato")
        return [o for o in lista if isinstance(o, dict)]

    def criar_substituicoes(self, listing_id: str, itens: list) -> list:
        lid = self._checar(listing_id)
        corpo = {"pms": self.pms, "update_children": False, "overrides": itens}
        dados = self._rede.pedir("POST", f"{PRICELABS}/listings/{lid}/overrides", self._cab(), corpo,
                                 timeout=self._timeout, novas_tentativas=0).dados
        return dados.get("overrides", []) if isinstance(dados, dict) else []

    def apagar_substituicoes(self, listing_id: str, datas: list) -> None:
        lid = self._checar(listing_id)
        corpo = {"pms": self.pms, "update_children": False, "overrides": [{"date": d} for d in datas]}
        self._rede.pedir("DELETE", f"{PRICELABS}/listings/{lid}/overrides", self._cab(), corpo,
                         timeout=self._timeout, novas_tentativas=0)

"""Simulador em memória da PriceLabs Customer API e do Jev.

Substitui o transporte HTTP, então todo pedido passa pela lista fechada de
rotas do módulo rede. Registra cada chamada para os testes provarem, por
exemplo, que o modo observar não faz nenhum POST ou DELETE de substituição.
"""

from __future__ import annotations

import copy
import json
import urllib.parse
from datetime import date, datetime, timedelta, timezone

BRT = timezone(timedelta(hours=-3))
PL = "https://api.pricelabs.co/v1"

LISTINGS = {
    "350362___722805": {"min": 800, "unidades": 7, "preco": 1300},
    "350362___722806": {"min": 900, "unidades": 6, "preco": 1400},
    "350362___722807": {"min": 1500, "unidades": 1, "preco": 1900},
    "350362___722808": {"min": 850, "unidades": 2, "preco": 1500},
    "350364___722813": {"min": 1000, "unidades": 7, "preco": 1300},
    "350364___722814": {"min": 950, "unidades": 6, "preco": 1100},
    "350364___722815": {"min": 900, "unidades": 2, "preco": 1250},
}


def resposta_jev_padrao(pedido: dict, escolha="discount", confianca=0.9, prob=0.9, veto=0.1) -> dict:
    respostas = {}
    for pid, q in pedido["questions"].items():
        if q["type"] == "choice":
            opcoes = list(q["criteria"])
            resto = (1 - prob) / (len(opcoes) - 1)
            probs = {o: (prob if o == escolha else resto) for o in opcoes}
            respostas[pid] = {"type": "choice", "choice": escolha, "probabilities": probs, "confidence": confianca}
        else:
            respostas[pid] = {"type": "noul", "noul": veto}
    return {"model": "jev-1.13.0", "answers": respostas, "usage": {"input_tokens": 900, "output_tokens": 40}}


class Simulador:
    def __init__(self, agora: datetime):
        self.agora = agora
        self.chamadas = []
        self.falhas = []            # lista de (metodo, trecho_da_url, status) consumida em ordem
        self.jev = resposta_jev_padrao
        self.jev_status = 200
        self.mercado = 25.0
        self.override_extras = {"min_stay": None, "min_price": None, "lead_time_expiry": None, "currency": "BRL"}
        self.ignorar_post = False
        self.quebrar_resposta_post = False  # grava, mas responde 503 (resultado incerto)
        self.mutar_post = None  # função que altera o que a API guarda (ex.: preço diferente do enviado)
        self.reservas = []
        self.overrides = {lid: {} for lid in LISTINGS}
        self.push = {lid: True for lid in LISTINGS}
        self.revpar = {}
        self.cal = {}
        for lid, info in LISTINGS.items():
            self.cal[lid] = {}
            for i in range(60):
                d = agora.date() + timedelta(days=i)
                vendidas = info["unidades"] // 2 if info["unidades"] > 1 else 0
                self.cal[lid][d] = {"vendidas": vendidas, "preco": float(info["preco"]), "enviado": float(info["preco"])}

    # -- utilidades para os testes -------------------------------------------
    def vender(self, lid, d: date, n=None):
        u = LISTINGS[lid]["unidades"]
        self.cal[lid][d]["vendidas"] = u if n is None else n

    def escritas(self):
        return [c for c in self.chamadas if c[0] in ("POST", "DELETE") and "/overrides" in c[1]]

    def chamadas_jev(self):
        return [c for c in self.chamadas if c[1].endswith("/systemone")]

    # -- transporte ----------------------------------------------------------
    def __call__(self, metodo, url, cabecalhos, corpo, timeout):
        partes = urllib.parse.urlsplit(url)
        consulta = dict(urllib.parse.parse_qsl(partes.query))
        dados = json.loads(corpo.decode("utf-8")) if corpo else None
        self.chamadas.append((metodo, partes.scheme + "://" + partes.netloc + partes.path, dados, dict(cabecalhos)))
        for i, (m, trecho, status) in enumerate(self.falhas):
            if m == metodo and trecho in url:
                del self.falhas[i]
                return status, {}, json.dumps({"error": {"code": "SIMULADO"}}).encode()
        if partes.netloc in ("openrouter.ai", "api.typesafe.ai"):
            return self._jev(dados)
        caminho = partes.path.replace("/v1", "", 1)
        if caminho == "/listings":
            return self._ok({"listings": [self._listing(lid) for lid in LISTINGS]})
        if caminho == "/listing_prices":
            return self._ok([self._calendario(x["id"]) for x in dados["listings"]])
        if caminho == "/listing_metrics":
            return self._ok({"data": {"listing_level": {"occupancy": {"-30": 40.0}, "revpar": {"-30": self.revpar.get(consulta["listing_id"], 500.0)}},
                                      "market_level": {"occupancy": {"7": self.mercado}}}})
        if caminho == "/reservation_data":
            ini, fim = date.fromisoformat(consulta["start_date"]), date.fromisoformat(consulta["end_date"])
            linhas = [r for r in self.reservas if ini <= date.fromisoformat(r["check_in"]) < fim]
            off, lim = int(consulta.get("offset", 0)), int(consulta.get("limit", 100))
            pagina = linhas[off:off + lim]
            return self._ok({"pms_name": "beds24", "next_page": len(pagina) == lim, "data": pagina})
        if caminho.startswith("/listings/") and caminho.endswith("/overrides"):
            lid = caminho.split("/")[2]
            return self._overrides(metodo, lid, consulta, dados)
        return 404, {}, b'{"error":"not found"}'

    def _ok(self, dados, status=200):
        return status, {"content-type": "application/json"}, json.dumps(dados).encode()

    def _listing(self, lid):
        return {"id": lid, "pms": "beds24", "name": f"Quarto {lid[-3:]}", "min": LISTINGS[lid]["min"],
                "base": 1500, "max": 5000, "currency": "BRL", "push_enabled": self.push[lid],
                "last_date_pushed": (self.agora - timedelta(hours=6)).astimezone(timezone.utc).isoformat(),
                "last_refreshed_at": (self.agora - timedelta(hours=2)).isoformat()}

    def _calendario(self, lid):
        u = LISTINGS[lid]["unidades"]
        dados = []
        for d, dia in sorted(self.cal[lid].items()):
            if d < self.agora.date():
                continue
            vend = dia["vendidas"]
            linha = {"date": d.isoformat(), "price": dia["preco"], "user_price": -1 if (u == 1 and vend) else dia["enviado"],
                     "min_stay": 1, "booking_status": "Booked" if vend else "", "unbookable": 0}
            if u > 1:
                linha["multi_unit_occupancy"] = f"{vend}/{u}"
            dados.append(linha)
        return {"id": lid, "pms": "beds24", "currency": "BRL",
                "last_refreshed_at": (self.agora - timedelta(hours=2)).isoformat(), "data": dados}

    def _overrides(self, metodo, lid, consulta, dados):
        lista = self.overrides[lid]
        if metodo == "GET":
            ini = consulta.get("start_date", self.agora.date().isoformat())
            fim = consulta.get("end_date", "9999-12-31")
            return self._ok({"overrides": [copy.deepcopy(o) for d, o in sorted(lista.items()) if ini <= d <= fim]})
        if metodo == "POST":
            salvos = []
            for o in dados["overrides"]:
                if "price" in o and "price_type" not in o:
                    return 400, {}, b'{"error":"price_type required"}'
                novo = {**self.override_extras, **o, "created_at": self.agora.isoformat()}
                if self.mutar_post:
                    novo = self.mutar_post(novo)
                if not self.ignorar_post:
                    lista[o["date"]] = novo
                salvos.append(novo)
            if self.quebrar_resposta_post:
                return 503, {}, b""
            return self._ok({"overrides": salvos})
        if metodo == "DELETE":
            for o in dados["overrides"]:
                lista.pop(o["date"], None)
            return 204, {}, b""
        return 405, {}, b""

    def _jev(self, pedido):
        if self.jev_status != 200:
            return self.jev_status, {}, json.dumps({"detail": {"error_type": "simulado", "message": "x"}}).encode()
        return 200, {"x-typesafe-request-id": ""}, json.dumps(self.jev(pedido)).encode()


def reserva(lid, chegada: date, noites=1, reservada_em=None, status="booked", cancelada_em=None):
    return {"listing_id": lid, "listing_name": "x", "reservation_id": f"r-{lid[-3:]}-{chegada.isoformat()}",
            "check_in": chegada.isoformat(), "check_out": (chegada + timedelta(days=noites - 1)).isoformat(),
            "booking_status": status, "booked_date": (reservada_em or datetime(2026, 9, 1, tzinfo=timezone.utc)).isoformat(),
            "no_of_days": noites, "cancelled_on": cancelada_em.isoformat() if cancelada_em else None,
            "rental_revenue": "1000", "currency": "BRL", "guestName": "Hidden", "booking_channel": "bcom"}

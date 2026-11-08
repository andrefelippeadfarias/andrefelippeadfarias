"""Testes do passo 2 do plano de redução de chamadas (Beds24: uma leitura de cada tipo por rotina e um lote de gravação
por rodada). Rodar na pasta scripts/: python3 -m unittest test_beds24_rodada

Tudo offline: a camada HTTP (urllib.request.urlopen) é trocada por um Beds24 falso com reservas sintéticas; o relógio e
a espera do crédito de 5 minutos são injetados (nenhum sleep real); o token de escrita fica num diretório temporário.
Os nomes de hóspedes abaixo são inventados e só servem para provar que não vão para arquivo."""

from __future__ import annotations

import contextlib
import copy
import email.message
import io
import json
import os
import shutil
import stat
import sys
import tempfile
import unittest
import urllib.error
import urllib.parse
from unittest import mock

AQUI = os.path.dirname(os.path.abspath(__file__))
if AQUI not in sys.path:
    sys.path.insert(0, AQUI)

DESDE = "2026-11-07T10:00:00Z"
AGORA = 1794070800.0                        # relógio dos testes: 2026-11-07T17:00:00Z (14:00 BRT), depois das reservas
NOME = "Sintetica-Kw"                       # sobrenome inventado: não pode aparecer em nenhum arquivo
TOKEN_ESCRITA = "tok-escrita-sintetico-NAO-IMPRIMIR"
PRECO1 = {722805: 1000, 722806: 1000, 722807: 1800, 722808: 900, 722813: 1400, 722814: 1500, 722815: 1300}
FATORES = {"Recanto": {"booking": 0.5, "airbnb": 0.4, "direct": 1.0},
           "Villa": {"booking": 0.5, "airbnb": 0.3, "direct": 1.0}}
PAR_SEGURANCA = {"origens_conhecidas": ["Booking.com", "Airbnb", "VillaRecep1"], "rajada_cancelamentos": 3,
                 "rajada_horas": 3}
CFG_ABAIXO = {"parametros": {"fator_canal": FATORES, "alerta_reserva": 0.70}}


def _reserva(id_, room, prop, status, chegada, saida, preco, criada, alterada=None, **kw):
    b = {"id": id_, "propertyId": prop, "roomId": room, "unitId": 1, "roomQty": 1, "status": status, "subStatus": "",
         "arrival": chegada, "departure": saida, "numAdult": 2, "numChild": 0, "price": preco, "deposit": 0, "tax": 0,
         "commission": 0, "channel": "booking", "referer": "Booking.com", "apiReference": f"55500{id_ % 100:02d}",
         "rateDescription": "Tarifa flexível", "bookingTime": criada, "modifiedTime": alterada or criada,
         "cancelTime": None, "title": "Sra.", "firstName": "Fulana", "lastName": NOME,
         "email": f"fulana.{NOME.lower()}@exemplo.invalid", "phone": "+55 11 90000-0000", "mobile": "",
         "address": f"Rua {NOME}, 1", "city": "Cidade", "comments": f"hóspede Fulana {NOME}",
         "notes": f"ligar para {NOME}", "message": "", "custom1": NOME, "reference": f"ref {NOME}", "lang": "pt",
         "guests": [{"firstName": "Fulana", "lastName": NOME}], "infoItems": [{"code": "nome", "text": NOME}]}
    b.update(kw)
    return b


def _charge(valor, **kw):
    return dict({"id": 1, "bookingId": 0, "type": "charge", "subType": None, "qty": 1, "amount": valor,
                 "lineTotal": valor, "vatRate": 0, "invoiceeId": None, "description": f"Diárias {NOME}",
                 "createdBy": 0, "status": "", "createTime": "2026-11-07T11:00:00Z"}, **kw)


def reservas_sinteticas() -> list[dict]:
    R, V = 350362, 350364
    return [
        # nova, Booking, acima do esperado (R$ 1.500 de R$ 1.000 esperados)
        _reserva(9001, 722805, R, "confirmed", "2026-11-20", "2026-11-22", 1500, "2026-11-07T11:00:00Z",
                 invoiceItems=[_charge(1500)]),
        # nova, Booking, abaixo de 70% (R$ 600 de R$ 1.000)
        _reserva(9002, 722806, R, "confirmed", "2026-11-25", "2026-11-27", 600, "2026-11-07T11:20:00Z",
                 invoiceItems=[_charge(600)]),
        # nova com R$ 0 (lançada pela recepção)
        _reserva(9003, 722813, V, "confirmed", "2026-11-14", "2026-11-15", 0, "2026-11-07T11:40:00Z",
                 channel=None, referer="VillaRecep1"),
        # antiga, alterada na rodada e confirmada com R$ 0 (06/11: cancelada que voltou)
        _reserva(9004, 722814, V, "confirmed", "2026-11-10", "2026-11-12", 0, "2026-10-01T09:00:00Z",
                 "2026-11-07T12:00:00Z"),
        # rajada: 3 cancelamentos da Booking em pouco mais de 1 h
        _reserva(9005, 722805, R, "cancelled", "2026-11-13", "2026-11-15", 2000, "2026-10-02T09:00:00Z",
                 "2026-11-07T13:00:00Z", cancelTime="2026-11-07T13:00:00Z"),
        _reserva(9006, 722806, R, "cancelled", "2026-11-13", "2026-11-15", 1800, "2026-10-03T09:00:00Z",
                 "2026-11-07T13:30:00Z", cancelTime="2026-11-07T13:30:00Z"),
        _reserva(9007, 722807, R, "cancelled", "2026-11-20", "2026-11-21", 1900, "2026-10-04T09:00:00Z",
                 "2026-11-07T14:10:00Z", cancelTime="2026-11-07T14:10:00Z"),
        # nova (status new), Airbnb, Villa
        _reserva(9008, 722814, V, "new", "2026-12-01", "2026-12-03", 800, "2026-11-07T15:00:00Z", channel="airbnb",
                 referer="Airbnb", invoiceItems=[_charge(800)]),
        # origem desconhecida
        _reserva(9009, 722805, R, "confirmed", "2026-11-15", "2026-11-16", 1200, "2026-11-07T15:30:00Z",
                 channel=None, referer="intruso-x"),
        # pedido de informação (inquiry): entra no movimento, não na avaliação
        _reserva(9010, 722815, V, "inquiry", "2026-11-21", "2026-11-23", 1500, "2026-11-07T16:00:00Z"),
        # alterada ANTES da rodada: fora do modifiedFrom, dentro das janelas de estadia
        _reserva(9011, 722808, R, "confirmed", "2026-11-08", "2026-11-09", 700, "2026-10-20T09:00:00Z",
                 "2026-11-06T08:00:00Z", invoiceItems=[_charge(700)]),
        # com extra (invoiceeId) e pagamento: a receita de hospedagem vem só da diária
        _reserva(9012, 722807, R, "confirmed", "2026-11-09", "2026-11-11", 2300, "2026-10-21T09:00:00Z",
                 "2026-11-05T08:00:00Z",
                 invoiceItems=[_charge(2000), _charge(300, invoiceeId=55), _charge(2300, type="payment")]),
    ]


def _cabecalhos(d: dict) -> email.message.Message:
    m = email.message.Message()
    for k, v in d.items():
        m[k] = str(v)
    return m


class _Resposta:
    def __init__(self, corpo, cab):
        self._corpo, self.headers, self.status = json.dumps(corpo).encode("utf-8"), _cabecalhos(cab), 200

    def read(self):
        return self._corpo

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False


class Beds24Falso:
    """Beds24 falso no lugar de urllib.request.urlopen: /bookings (status, modifiedFrom, arrivalTo, departureFrom,
    includeInvoiceItems), /inventory/rooms/calendar (GET com include*, POST que grava), /authentication/token e
    /properties. Guarda cada chamada (método, caminho, parâmetros) para contar as leituras."""

    BASE = "https://api.beds24.com/v2"

    def __init__(self, reservas=None, cab=None):
        self.reservas = reservas if reservas is not None else reservas_sinteticas()
        self.cab = dict(cab or {"X-Request-Cost": "1", "X-Five-Min-Limit-Resets-In": "200",
                                "X-Five-Min-Limit-Remaining": "80.0"})
        self.estado: dict[int, dict[str, dict]] = {}
        self.chamadas: list[tuple[str, str, dict]] = []
        self.posts: list = []
        # (método, caminho, código HTTP, cabeçalhos) a devolver em ordem; código 0 = falha de rede (URLError)
        self.erros: list[tuple[str, str, int, dict]] = []
        self.expires_in = 3600
        self.tokens_emitidos = 0
        self.tokens_usados: list = []                      # token de cada POST (só na memória do teste)
        self.por_pagina: int | None = None                 # /bookings paginado (None = tudo numa página)

    def contar(self, metodo, caminho):
        return sum(1 for m, c, _ in self.chamadas if m == metodo and c == caminho)

    def dia(self, rid, ds):
        return self.estado.setdefault(rid, {}).setdefault(ds, {"numAvail": 3, "minStay": 2, "maxStay": 30,
                                                                  "override": "none", "price1": PRECO1[rid]})

    def __call__(self, req, timeout=None):
        url = urllib.parse.urlsplit(req.full_url)
        caminho = url.path[len("/v2"):]
        q = urllib.parse.parse_qs(url.query)
        metodo = req.get_method()
        self.chamadas.append((metodo, caminho, q))
        if metodo == "POST":
            self.tokens_usados.append(req.get_header("Token"))
        for k, (m, c, codigo, cab) in enumerate(self.erros):
            if (m, c) == (metodo, caminho):
                self.erros.pop(k)
                if codigo == 0:
                    raise urllib.error.URLError("sem rede (sintético)")
                raise urllib.error.HTTPError(req.full_url, codigo, "erro sintético", _cabecalhos(cab),
                                             io.BytesIO(b'{"success": false, "error": "sintetico"}'))
        um = lambda k: (q.get(k) or [None])[0]
        if metodo == "POST" and caminho == "/inventory/rooms/calendar":
            corpo = json.loads(req.data.decode("utf-8"))
            self.posts.append(corpo)
            for x in corpo:
                for fx in x["calendar"]:
                    for ds in _datas(fx["from"], fx["to"]):
                        v = self.dia(int(x["roomId"]), ds)
                        for k in ("minStay", "maxStay", "override", "numAvail"):
                            if k in fx:
                                v[k] = fx[k]
            return _Resposta([{"success": True}], self.cab)
        if caminho == "/authentication/token":
            self.tokens_emitidos += 1
            r = {"token": TOKEN_ESCRITA}
            if self.expires_in is not None:
                r["expiresIn"] = self.expires_in
            return _Resposta(r, self.cab)
        if caminho == "/properties":
            return _Resposta({"data": [{"id": 350362, "name": "Recanto"}, {"id": 350364, "name": "Villa"}]}, self.cab)
        if caminho == "/bookings":
            lista = copy.deepcopy(self.reservas)
            if q.get("status"):
                lista = [b for b in lista if b["status"] in q["status"]]
            if um("modifiedFrom"):
                lista = [b for b in lista if b["modifiedTime"] >= um("modifiedFrom")]
            if um("arrivalTo"):
                lista = [b for b in lista if b["arrival"] <= um("arrivalTo")]
            if um("departureFrom"):
                lista = [b for b in lista if b["departure"] >= um("departureFrom")]
            if um("includeInvoiceItems") != "true":
                for b in lista:
                    b.pop("invoiceItems", None)
            mais = False
            if self.por_pagina:
                ini = (int(um("page") or 1) - 1) * self.por_pagina
                mais, lista = len(lista) > ini + self.por_pagina, lista[ini:ini + self.por_pagina]
            return _Resposta({"success": True, "type": "booking", "count": len(lista),
                              "pages": {"nextPageExists": mais, "nextPageLink": None}, "data": lista}, self.cab)
        if caminho == "/inventory/rooms/calendar":
            dados = []
            for rid in (int(x) for x in q["roomId"]):
                cal = []
                for ds in _datas(um("startDate"), um("endDate")):
                    v = self.dia(rid, ds)
                    fx = {"from": ds, "to": ds}
                    for k, inc in (("numAvail", "includeNumAvail"), ("minStay", "includeMinStay"),
                                   ("maxStay", "includeMaxStay"), ("override", "includeOverride")):
                        if um(inc) == "true":
                            fx[k] = v[k]
                    if um("includePrices") == "true":
                        fx["price1"] = v["price1"]
                    cal.append(fx)
                dados.append({"roomId": rid, "propertyId": 350362 if rid < 722810 else 350364, "name": "q",
                              "calendar": cal})
            return _Resposta({"success": True, "type": "calendar", "count": len(dados),
                              "pages": {"nextPageExists": False, "nextPageLink": None}, "data": dados}, self.cab)
        raise AssertionError(f"chamada inesperada: {metodo} {caminho}")


def _datas(de, ate):
    import datetime as dt
    d, fim = dt.date.fromisoformat(de), dt.date.fromisoformat(ate)
    while d <= fim:
        yield d.isoformat()
        d += dt.timedelta(days=1)


@contextlib.contextmanager
def ambiente(falso: Beds24Falso, pasta_token: str | None = None):
    """Beds24 falso na camada HTTP, credencial de leitura sintética, parâmetros fixos (não dependem do quartos.json),
    relógio e espera injetados e o token de escrita num diretório temporário."""
    import beds24
    import reservas_abaixo
    import vigia_seguranca
    tmp = pasta_token or tempfile.mkdtemp(prefix="b24tok_")
    env = {"BEDS24_TOKEN": "tok-leitura-sintetico", "BEDS24_REFRESH_TOKEN": "refresh-sintetico"}
    antes_env = {k: os.environ.get(k) for k in env}
    os.environ.update(env)
    salvos = {}
    for nome, valor in (("ARQ_TOKEN_ESCRITA", os.path.join(tmp, "token_escrita")), ("_dormir", lambda s: None),
                        ("_agora", lambda: AGORA)):
        if hasattr(beds24, nome):
            salvos[nome] = getattr(beds24, nome)
            setattr(beds24, nome, valor)
    if hasattr(beds24, "_CREDITO"):
        beds24._CREDITO.clear()
    try:
        with mock.patch("urllib.request.urlopen", falso), \
                mock.patch.object(vigia_seguranca, "parametros", lambda: dict(PAR_SEGURANCA)), \
                mock.patch.object(reservas_abaixo, "carregar_quartos", lambda: copy.deepcopy(CFG_ABAIXO)):
            yield tmp
    finally:
        for nome, valor in salvos.items():
            setattr(beds24, nome, valor)
        if hasattr(beds24, "_CREDITO"):
            beds24._CREDITO.clear()
        for k, v in antes_env.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v
        if pasta_token is None:
            shutil.rmtree(tmp, ignore_errors=True)


def rodar(func, argv):
    """(código, saída, erro) de um main(argv), com a saída capturada."""
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        rc = func(argv)
    return rc, out.getvalue(), err.getvalue()


# ---------------------------------------------------------------------------------------------------------------------
import hashlib  # noqa: E402

import beds24  # noqa: E402
import concorrencia_hidro_vista  # noqa: E402
import reservas_abaixo  # noqa: E402
import vigia_seguranca  # noqa: E402

COLETA = {"rooms": {"Q7": {"days": [{"date": "2026-11-15", "multi_unit_occupancy": "2/7"},
                                     {"date": "2026-11-20", "multi_unit_occupancy": "1/7"}]},
                    "Q2": {"days": [{"date": "2026-11-08", "multi_unit_occupancy": "0/2"}]}}}
PLANO_SIM = [{"quarto": "Q7", "de": "2026-11-16", "ate": "2026-11-16", "min_stay": 1, "min_stay_antes": 2, "motivo": "t"},
             {"quarto": "VK7", "de": "2026-11-18", "ate": "2026-11-18", "min_stay": 1, "min_stay_antes": 3, "motivo": "t"}]
GB, GC = ("GET", "/bookings"), ("GET", "/inventory/rooms/calendar")
# Saídas dos comandos ANTES do passo 2 (beds24.py, vigia_seguranca.py e reservas_abaixo.py do commit 74dff40), com este
# mesmo Beds24 falso: sha256 (16 primeiros) da saída na tela e as chamadas feitas. O código novo tem de dar o mesmo.
ANTES = {"movimento": ("efc03d515c29584c", [GB]), "vigia": ("d5ba7e3e231e4b56", [GB]),
         "abaixo": ("3e967724d7bb1a22", [GB, GC]), "janelas": ("bbeb2e0d5bd39502", [GB]),
         "comparar": ("03810b165b5609f6", [GB]), "reservas": ("9f43bf01f2ce7d6f", [GB]), "livro": ("019332692ce9989d", [GB]),
         "inventario": ("af54421fc826831a", [GC]), "editar_lote_sim": ("c17f03a150c8e710", [GC])}
VIGIA_ANTES = """10 reservas criadas ou alteradas desde 2026-11-07T10:00:00Z; 4 alerta(s) de segurança
- reserva sem valor: reserva confirmada ou nova com preço 0 | reserva 9003 VK7 2026-11-14 canal None
- reserva alterada sem valor: reserva antiga alterada ou reativada na rodada, confirmada com preço 0 | reserva 9004 Balcony 2026-11-10 canal booking
- origem desconhecida: reserva criada ou alterada por 'intruso-x', fora das origens conhecidas | reserva 9009 Q7 2026-11-15 canal None
- rajada de cancelamentos: 3 cancelamentos em até 3 h entre um e outro (07/11 13:00 a 07/11 14:10 UTC): confirme com a recepção e com os hóspedes se foram eles | reserva [9005, 9006, 9007]   canal booking
"""
ABAIXO_ANTES = """9002 | ref. no canal 5550002 | Double 2026-11-25 2n | booking | R$ 600 de R$ 1000 esperados (60%) | por Booking.com
1 reserva(s) abaixo de 70% do esperado (o price1 é o de agora: se o preço subiu depois da reserva, a razão cai sem erro de lançamento; abaixo de 50% é quase sempre erro)
"""


def ler(caminho):
    with open(caminho, encoding="utf-8") as f:
        return json.load(f)


def _texto(caminho):
    with open(caminho, encoding="utf-8") as f:
        return f.read()


def _sha(texto: str) -> str:
    return hashlib.sha256(texto.encode("utf-8")).hexdigest()[:16]


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="b24teste_")
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.falso = Beds24Falso()

    def arq(self, nome, dados=None):
        p = os.path.join(self.tmp, nome)
        if dados is not None:
            with open(p, "w", encoding="utf-8") as f:
                json.dump(dados, f, ensure_ascii=False)
        return p

    def executar(self, func, argv, falso=None):
        with ambiente(falso or self.falso, self.tmp):
            return rodar(func, argv)

    @staticmethod
    def chamadas(falso):
        return [(m, c) for m, c, _ in falso.chamadas]


class TestComandosAntigos(Base):
    """Os comandos de hoje (rotina de 08/11) continuam com a mesma saída e as mesmas chamadas."""

    def test_mesma_saida_e_mesmas_chamadas_de_antes(self):
        coleta, plano = self.arq("coleta.json", COLETA), self.arq("plano.json", PLANO_SIM)
        casos = [("movimento", beds24.main, ["movimento", "--desde", DESDE]),
                 ("vigia", vigia_seguranca.main, ["--desde", DESDE]),
                 ("abaixo", reservas_abaixo.main, ["--desde", DESDE]),
                 ("janelas", beds24.main, ["janelas", "--hoje", "2026-11-07"]),
                 ("comparar", beds24.main, ["comparar", "--coleta", coleta, "--de", "2026-11-07", "--ate", "2027-01-06"]),
                 ("reservas", beds24.main, ["reservas", "--de", "2026-11-07", "--ate", "2027-01-06"]),
                 ("livro", beds24.main, ["livro", "--de", "2026-11-01", "--ate", "2027-01-01"]),
                 ("inventario", beds24.main, ["inventario", "--de", "2026-11-08", "--ate", "2026-11-10", "--quarto", "Q7"]),
                 ("editar_lote_sim", beds24.main, ["editar-lote", "--plano", plano])]
        for nome, func, argv in casos:
            with self.subTest(nome):
                falso = Beds24Falso()
                rc, out, err = self.executar(func, argv, falso)
                self.assertEqual(rc, 0, err)
                if nome == "inventario":
                    d = json.loads(out)
                    d.pop("_lido_em")
                    out = json.dumps(d, ensure_ascii=False, indent=1)
                if nome == "vigia":
                    self.assertEqual(out, VIGIA_ANTES)
                if nome == "abaixo":
                    self.assertEqual(out, ABAIXO_ANTES)
                self.assertEqual(_sha(out), ANTES[nome][0], out[:3000])
                self.assertEqual(self.chamadas(falso), ANTES[nome][1])


class TestRodada(Base):
    def test_paridade_com_os_tres_comandos_e_uma_leitura(self):
        f_mov, f_vig, f_ab, f_rod = (self.arq(n) for n in ("mov.json", "vig.json", "ab.json", "rodada.json"))
        # revisão de 07/11: reserva com price (1.500) diferente das diárias da fatura (1.300 + extra de 250 sem
        # invoiceeId = 1.550). O `movimento` de hoje lê sem invoiceItems (receita pelo price); a rodada tem de dar o mesmo
        extra = _reserva(9013, 722805, 350362, "confirmed", "2026-12-05", "2026-12-07", 1500, "2026-11-07T16:30:00Z",
                         invoiceItems=[_charge(1300), _charge(250, id=2)])
        reservas = reservas_sinteticas() + [extra]
        sep = Beds24Falso(reservas=reservas)
        self.assertEqual(self.executar(beds24.main, ["movimento", "--desde", DESDE, "--json", f_mov], sep)[0], 0)
        _, out_v, _ = self.executar(vigia_seguranca.main, ["--desde", DESDE, "--json", f_vig], sep)
        _, out_a, _ = self.executar(reservas_abaixo.main, ["--desde", DESDE, "--json", f_ab], sep)
        self.assertEqual((sep.contar(*GB), sep.contar(*GC)), (3, 1))     # hoje: 3 GET iguais + o preço
        rod = Beds24Falso(reservas=reservas)
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f_rod], rod)
        self.assertEqual(rc, 0, err)
        self.assertEqual((rod.contar(*GB), rod.contar(*GC)), (1, 1))     # rodada: 1 GET + o preço
        q = next(x for m, c, x in rod.chamadas if c == "/bookings")
        self.assertEqual((q["modifiedFrom"], q["includeInvoiceItems"]), ([DESDE], ["true"]))
        self.assertEqual(sorted(q["status"]), sorted(beds24.STATUS_TODOS))
        r = ler(f_rod)
        self.assertEqual(r["movimento"], ler(f_mov))
        self.assertEqual(r["vigia"], ler(f_vig))
        self.assertEqual(r["abaixo"], ler(f_ab))
        self.assertIn(out_v, out)                                        # a mesma tela do vigia e do reservas_abaixo
        self.assertIn(out_a, out)
        self.assertLess(out.index("Segurança"), out.index("abaixo do planejado"))   # o vigia vem no topo
        # os casos: rajada, R$ 0 nova e alterada, origem desconhecida, abaixo de 70%, nova, alterada, cancelada
        al = {(x["alerta"], str(x.get("id", x.get("reservas")))) for x in r["vigia"]["alertas"]}
        self.assertEqual(al, {("rajada de cancelamentos", "[9005, 9006, 9007]"), ("reserva sem valor", "9003"),
                              ("reserva alterada sem valor", "9004"), ("origem desconhecida", "9009")})
        self.assertEqual([(x["id"], x["recebe_do_esperado"]) for x in r["abaixo"]], [(9002, 0.6)])
        tipos = {x["id"]: x["tipo"] for x in r["movimento"]}
        self.assertEqual((tipos[9001], tipos[9004], tipos[9005], tipos[9008], tipos[9010], tipos[9013]),
                         ("nova", "alterada", "cancelada", "nova", "nova", "nova"))
        self.assertNotIn(9011, tipos)                                    # alterada antes do --desde
        receita = {x["id"]: x["receita"] for x in r["movimento"]}
        self.assertEqual(receita[9013], 1500.0)                          # pelo price, como o `movimento`
        b13 = next(b for b in r["reservas"] if b["id"] == 9013)
        self.assertEqual(beds24.receita_por_noite(b13)[2], 1550.0)       # o arquivo guarda a fatura (para o abaixo)
        self.assertEqual(r["leituras_bookings"], 1)
        self.assertEqual(r["chamadas"], {"GET /bookings": 1, "GET /inventory/rooms/calendar": 1})

    def test_json_sem_nomes_e_nomes_so_na_tela(self):
        f = self.arq("rodada.json")
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f])
        self.assertEqual(rc, 0, err)
        texto = _texto(f)
        for proibido in (NOME, "Fulana", "exemplo.invalid", "90000-0000", "Sra."):
            self.assertNotIn(proibido, texto)
        self.assertNotIn(NOME, out + err)
        r = json.loads(texto)
        self.assertEqual(len(r["reservas"]), r["lidas"])
        cru = {b["id"]: b for b in r["reservas"]}
        self.assertEqual(cru[9001]["invoiceItems"][0]["lineTotal"], 1500)   # com invoiceItems, sem a descrição
        self.assertNotIn("description", cru[9001]["invoiceItems"][0])
        self.assertEqual(set(cru[9001]) - set(beds24.CAMPOS_RESERVA), {"invoiceItems"})
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f, "--nomes"], Beds24Falso())
        self.assertEqual(rc, 0, err)
        self.assertIn(f"Fulana {NOME}", out)                             # --nomes: na tela
        self.assertNotIn(NOME, _texto(f))         # e nunca no arquivo

    def test_beds24_fora_repete_uma_vez_e_sai_com_vigia_sem_beds24(self):
        f = self.arq("rodada.json")
        falso, dormidas = Beds24Falso(), []
        falso.erros = [("GET", "/bookings", 500, {}), ("GET", "/bookings", 0, {})]
        with ambiente(falso, self.tmp):
            beds24._dormir = dormidas.append
            rc, out, err = rodar(beds24.main, ["rodada", "--desde", DESDE, "--json", f])
        self.assertEqual(rc, 3)
        self.assertIn("vigia sem Beds24", err)
        self.assertIn("get_pms_reservations", err)
        self.assertEqual(falso.contar(*GB), 2)
        self.assertEqual(dormidas, [beds24.PAUSA_REPETICAO_S])
        self.assertFalse(os.path.exists(f))
        # uma falha só: a repetição resolve
        falso = Beds24Falso()
        falso.erros = [("GET", "/bookings", 0, {})]
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f], falso)
        self.assertEqual(rc, 0, err)
        self.assertEqual(falso.contar(*GB), 2)
        self.assertEqual(len(ler(f)["vigia"]["alertas"]), 4)

    def test_leituras_bookings_conta_a_repeticao_e_as_paginas(self):
        """Revisão de 07/11: o JSON gravava leituras_bookings = 1 fixo (o plano mede as chamadas por 2 dias)."""
        f = self.arq("rodada.json")
        falso = Beds24Falso()
        falso.erros = [("GET", "/bookings", 0, {})]                     # 1 falha de rede: a rodada repete
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f], falso)
        self.assertEqual(rc, 0, err)
        r = ler(f)
        self.assertEqual((falso.contar(*GB), r["leituras_bookings"]), (2, 2))
        self.assertEqual(r["chamadas"]["GET /bookings"], 2)
        self.assertIn("2 leitura(s)", out)
        falso = Beds24Falso()
        falso.por_pagina = 4                                             # 10 reservas no período: 3 páginas
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f], falso)
        self.assertEqual(rc, 0, err)
        r = ler(f)
        self.assertEqual((falso.contar(*GB), r["leituras_bookings"], r["lidas"]), (3, 3, 10))
        self.assertEqual(len(r["vigia"]["alertas"]), 4)
        falso = Beds24Falso()                                            # 429: a 2ª tentativa do _get também conta
        falso.erros = [("GET", "/bookings", 429, {"X-Five-Min-Limit-Resets-In": "17"})]
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f], falso)
        self.assertEqual(rc, 0, err)
        r = ler(f)
        self.assertEqual((r["leituras_bookings"], r["espera_credito_s"]), (2, 18.0))

    def test_429_nao_e_repetido_de_novo_pela_rodada(self):
        falso, dormidas = Beds24Falso(), []
        cab = {"X-Five-Min-Limit-Resets-In": "17", "X-Five-Min-Limit-Remaining": "0"}
        falso.erros = [("GET", "/bookings", 429, cab), ("GET", "/bookings", 429, cab)]
        with ambiente(falso, self.tmp):
            beds24._dormir = dormidas.append
            rc, out, err = rodar(beds24.main, ["rodada", "--desde", DESDE])
        self.assertEqual((rc, falso.contar(*GB)), (3, 2))                # o _get já esperou e tentou 1 vez
        self.assertEqual(dormidas, [18.0])
        self.assertIn("vigia sem Beds24", err)

    def test_preco_fora_nao_derruba_o_vigia(self):
        f = self.arq("rodada.json")
        self.falso.erros = [("GET", "/inventory/rooms/calendar", 500, {})]
        rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f])
        self.assertEqual(rc, 2)
        r = ler(f)
        self.assertIsNone(r["abaixo"])
        self.assertEqual(len(r["vigia"]["alertas"]), 4)
        self.assertTrue(any("sem o price1" in x for x in r["avisos"]))
        self.assertIn("rajada de cancelamentos", out)

    def test_price1_do_inventario_no_lugar_do_get(self):
        finv, f_vivo, f_inv, f_parc = (self.arq(n) for n in ("inv.json", "vivo.json", "r_inv.json", "r_parc.json"))
        rc, _, err = self.executar(beds24.main, ["inventario", "--de", "2026-11-07", "--ate", "2027-01-06", "--precos",
                                                 "--json", finv])
        self.assertEqual(rc, 0, err)
        self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f_vivo], Beds24Falso())
        falso = Beds24Falso()
        rc, _, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f_inv, "--inventario", finv],
                                   falso)
        self.assertEqual(rc, 0, err)
        self.assertEqual(self.chamadas(falso), [GB])                     # nenhum GET de preço
        abaixo = ler(f_vivo)["abaixo"]
        self.assertEqual(ler(f_inv)["abaixo"], abaixo)
        # noites da Double (9002) fora do arquivo: 1 GET só daquele quarto e daquelas noites
        inv = ler(finv)
        for ds in ("2026-11-25", "2026-11-26"):
            del inv["Double"][ds]
        self.arq("inv_parcial.json", inv)
        falso = Beds24Falso()
        rc, _, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f_parc, "--inventario",
                                                 self.arq("inv_parcial.json")], falso)
        self.assertEqual(rc, 0, err)
        q = next(x for m, c, x in falso.chamadas if c == "/inventory/rooms/calendar")
        self.assertEqual((q["roomId"], q["startDate"], q["endDate"]), (["722806"], ["2026-11-25"], ["2026-11-26"]))
        r = ler(f_parc)
        self.assertEqual(r["abaixo"], abaixo)
        self.assertEqual(r["precos"]["noites_fora_do_inventario"], 2)


class TestArquivoAvulso(Base):
    """vigia_seguranca.py e reservas_abaixo.py com --arquivo: a resposta crua salva, sem nova leitura."""

    def setUp(self):
        super().setUp()
        self.rod = self.arq("rodada.json")
        rc, _, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", self.rod], Beds24Falso())
        self.assertEqual(rc, 0, err)
        self.r = ler(self.rod)

    def test_vigia_e_reservas_abaixo_com_arquivo(self):
        fv, fa = self.arq("v.json"), self.arq("a.json")
        falso = Beds24Falso()
        rc, out, err = self.executar(vigia_seguranca.main, ["--desde", DESDE, "--arquivo", self.rod, "--json", fv], falso)
        self.assertEqual(rc, 0, err)
        self.assertEqual(out, VIGIA_ANTES)
        self.assertEqual(ler(fv), self.r["vigia"])
        rc, out, err = self.executar(reservas_abaixo.main, ["--desde", DESDE, "--arquivo", self.rod, "--json", fa], falso)
        self.assertEqual(rc, 0, err)
        self.assertEqual(out, ABAIXO_ANTES)
        self.assertEqual(ler(fa), self.r["abaixo"])
        self.assertEqual(self.chamadas(falso), [GC])                     # só o preço; nenhuma leitura de reservas

    def test_reservas_abaixo_com_arquivo_e_inventario_sem_chamada(self):
        finv = self.arq("inv.json")
        self.executar(beds24.main, ["inventario", "--de", "2026-11-07", "--ate", "2027-01-06", "--precos", "--json", finv],
                      Beds24Falso())
        falso = Beds24Falso()
        rc, out, err = self.executar(reservas_abaixo.main, ["--desde", DESDE, "--arquivo", self.rod, "--inventario", finv],
                                     falso)
        self.assertEqual((rc, out), (0, ABAIXO_ANTES), err)
        self.assertEqual(falso.chamadas, [])

    def test_arquivo_lido_depois_do_desde_pedido_e_recusado(self):
        falso = Beds24Falso()
        rc, out, err = self.executar(vigia_seguranca.main, ["--desde", "2026-11-07T08:00:00Z", "--arquivo", self.rod],
                                     falso)
        self.assertEqual(rc, 2)
        self.assertIn("faltariam", err)
        rc, out, err = self.executar(reservas_abaixo.main, ["--desde", "2026-11-07T08:00:00Z", "--arquivo", self.rod],
                                     falso)
        self.assertEqual(rc, 2)
        self.assertEqual(falso.chamadas, [])
        # --desde depois do arquivo: só as alteradas a partir dele
        rc, out, err = self.executar(vigia_seguranca.main, ["--desde", "2026-11-07T13:15:00Z", "--arquivo", self.rod],
                                     falso)
        self.assertEqual(rc, 0, err)
        self.assertTrue(out.startswith("5 reservas criadas ou alteradas desde 2026-11-07T13:15:00Z"), out)


    def test_so_o_arquivo_da_rodada_no_modo_desde(self):
        """Revisão de 07/11: o `reservas --json` (janela de estadia), a lista solta e o {data} sem desde passavam sem
        conferência, e o vigia dava falso negativo com código 0 (rajada de estadia fora da janela)."""
        canc = [_reserva(9100 + k, 722805, 350362, "cancelled", "2027-12-10", "2027-12-12", 2000, "2026-10-02T09:00:00Z",
                         f"2026-11-07T13:{10 * k:02d}:00Z", cancelTime=f"2026-11-07T13:{10 * k:02d}:00Z",
                         channel="airbnb", referer="Airbnb") for k in range(3)]
        falso = Beds24Falso(reservas=reservas_sinteticas() + canc)
        res, rod = self.arq("reservas.json"), self.arq("rodada2.json")
        self.assertEqual(self.executar(beds24.main, ["reservas", "--de", "2026-11-07", "--ate", "2027-01-06", "--json",
                                                     res], falso)[0], 0)
        self.assertEqual(self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", rod], falso)[0], 0)
        rc, vivo, err = self.executar(vigia_seguranca.main, ["--desde", DESDE], falso)
        self.assertIn("[9100, 9101, 9102]", vivo)                        # a rajada da Airbnb (estadia em dez/2027)
        rc, out, err = self.executar(vigia_seguranca.main, ["--desde", DESDE, "--arquivo", rod], Beds24Falso())
        self.assertEqual((rc, out), (0, vivo), err)                      # o arquivo da rodada dá a mesma tela
        self.assertIn("o que mudou depois disso não entra", err)
        dados = ler(res)
        lista, so_data = self.arq("lista.json", dados["data"]), self.arq("so_data.json", {"data": dados["data"]})
        for arq, trecho in ((res, "janela de estadia"), (lista, "lista solta"), (so_data, "não é um `beds24.py rodada")):
            for func in (vigia_seguranca.main, reservas_abaixo.main):
                with self.subTest(arq=os.path.basename(arq), func=func.__module__):
                    f = Beds24Falso()
                    rc, out, err = self.executar(func, ["--desde", DESDE, "--arquivo", arq], f)
                    self.assertEqual((rc, out), (2, ""))
                    self.assertIn(trecho, err)
                    self.assertEqual(f.chamadas, [])

    def test_desde_depois_da_leitura_do_arquivo_e_recusado(self):
        """--desde depois do lido_em: não há nada lido depois dele (dava '0 alerta(s)' com código 0)."""
        self.assertEqual(self.r["lido_em"], "2026-11-07T17:00:00Z")
        for desde in ("2099-01-01T00:00:00Z", "2026-11-07T17:00:01Z"):
            for func in (vigia_seguranca.main, reservas_abaixo.main):
                with self.subTest(desde=desde, func=func.__module__):
                    rc, out, err = self.executar(func, ["--desde", desde, "--arquivo", self.rod], Beds24Falso())
                    self.assertEqual((rc, out), (2, ""))
                    self.assertIn("antes do --desde", err)
        rc, out, err = self.executar(vigia_seguranca.main, ["--desde", "2026-11-07T17:00:00Z", "--arquivo", self.rod],
                                     Beds24Falso())
        self.assertEqual(rc, 0, err)                                     # no próprio lido_em: aceito
        rc, out, err = self.executar(vigia_seguranca.main, ["--desde", "ontem", "--arquivo", self.rod], Beds24Falso())
        self.assertEqual(rc, 2)
        self.assertIn("--desde inválido", err)

    def test_inventario_de_outra_rotina_vira_aviso(self):
        """Revisão de 07/11: o price1 do --inventario de outra rotina (lido há mais de 2 h) ou sem _lido_em: aviso."""
        finv = self.arq("inv.json")
        rc, _, err = self.executar(beds24.main, ["inventario", "--de", "2026-11-07", "--ate", "2027-01-06", "--precos",
                                                 "--json", finv], Beds24Falso())
        self.assertEqual(rc, 0, err)
        inv = ler(finv)
        self.assertEqual(inv["_lido_em"], "2026-11-07T17:00:00Z")
        velho = self.arq("inv_velho.json", dict(inv, _lido_em="2026-11-07T14:30:00Z"))      # 2,5 h antes
        sem = self.arq("inv_sem.json", {k: v for k, v in inv.items() if k != "_lido_em"})
        for arq, trecho in ((velho, "de outra rotina"), (sem, "sem _lido_em"), (finv, None)):
            with self.subTest(os.path.basename(arq)):
                f = self.arq("r.json")
                rc, out, err = self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", f, "--inventario", arq],
                                             Beds24Falso())
                self.assertEqual(rc, 0, err)
                r = ler(f)
                self.assertEqual(r["abaixo"], self.r["abaixo"])          # segue com o price1 do arquivo
                rc, out, err = self.executar(reservas_abaixo.main, ["--desde", DESDE, "--arquivo", self.rod,
                                                                    "--inventario", arq], Beds24Falso())
                self.assertEqual((rc, out), (0, ABAIXO_ANTES), err)
                if trecho is None:
                    self.assertEqual(r["avisos"], [])
                    self.assertNotIn("AVISO: price1", err)
                else:
                    self.assertTrue(any(trecho in x for x in r["avisos"]), r["avisos"])
                    self.assertIn(trecho, err)


class TestReservasArquivo(Base):
    """reservas --json (crua, sem nomes) reaproveitada por janelas, comparar e concorrencia_hidro_vista.py."""

    def setUp(self):
        super().setUp()
        self.res = self.arq("reservas.json")
        rc, out, err = self.executar(beds24.main, ["reservas", "--de", "2026-11-07", "--ate", "2027-01-06", "--json",
                                                   self.res])
        self.assertEqual(rc, 0, err)
        self.assertEqual(out, f"gravado em {self.res}\n")

    def test_arquivo_cru_sem_nomes_com_as_chaves_antigas(self):
        texto = _texto(self.res)
        self.assertNotIn(NOME, texto)
        self.assertNotIn("Fulana", texto)
        d = json.loads(texto)
        self.assertEqual((d["de"], d["ate"], d["lidas"]), ("2026-11-07", "2027-01-06", len(d["data"])))
        antigas = ("id", "propertyId", "roomId", "status", "arrival", "departure", "roomQty", "price", "commission",
                   "referer", "channel", "bookingTime", "cancelTime", "receita_hospedagem")
        self.assertTrue(all(set(antigas) <= set(b) for b in d["data"]))
        b12 = next(b for b in d["data"] if b["id"] == 9012)
        self.assertEqual((len(b12["invoiceItems"]), b12["receita_hospedagem"]), (3, 2000.0))

    def test_janelas_e_comparar_sem_nova_leitura(self):
        coleta = self.arq("coleta.json", COLETA)
        for argv in (["janelas", "--hoje", "2026-11-07"],
                     ["comparar", "--coleta", coleta, "--de", "2026-11-07", "--ate", "2027-01-06"],
                     ["comparar", "--coleta", coleta, "--de", "2026-11-10", "--ate", "2026-12-01"]):
            with self.subTest(argv[0]):
                vivo, arq = Beds24Falso(), Beds24Falso()
                rc1, out1, _ = self.executar(beds24.main, argv, vivo)
                rc2, out2, err = self.executar(beds24.main, argv + ["--reservas", self.res], arq)
                self.assertEqual((rc1, rc2), (0, 0), err)
                self.assertEqual(out2, out1)
                self.assertEqual((vivo.contar(*GB), arq.chamadas), (1, []))

    def test_pela_linha_de_comando_sem_rede(self):
        """O script chamado pela linha de comando (o __main__ roda pelo módulo importado) dá a mesma saída."""
        import subprocess
        rc, esperado, _ = self.executar(beds24.main, ["janelas", "--hoje", "2026-11-07", "--reservas", self.res],
                                        Beds24Falso())
        import datetime as dt                                            # o subprocesso usa o relógio de verdade
        d = ler(self.res)
        d["lido_em"] = dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        self.arq("reservas.json", d)
        env = dict(os.environ, BEDS24_SEM_SEGREDO_DE_REDE="1")
        for k in ("BEDS24_TOKEN", "BEDS24_REFRESH_TOKEN"):
            env.pop(k, None)
        p = subprocess.run([sys.executable, os.path.join(AQUI, "beds24.py"), "janelas", "--hoje", "2026-11-07",
                            "--reservas", self.res], capture_output=True, text=True, cwd=self.tmp, env=env, timeout=60)
        self.assertEqual((p.returncode, p.stdout), (0, esperado), p.stderr)

    def test_janela_fora_do_arquivo_ou_arquivo_da_rodada_recusados(self):
        self.falso = Beds24Falso()
        rc, out, err = self.executar(beds24.main, ["janelas", "--hoje", "2026-12-01", "--reservas", self.res])
        self.assertEqual(rc, 2)
        self.assertIn("cobre 2026-11-07 a 2027-01-06", err)
        rod = self.arq("rodada.json")
        self.executar(beds24.main, ["rodada", "--desde", DESDE, "--json", rod], Beds24Falso())
        rc, out, err = self.executar(beds24.main, ["janelas", "--hoje", "2026-11-07", "--reservas", rod], self.falso)
        self.assertEqual(rc, 2)
        self.assertIn("alteradas desde", err)
        self.assertEqual(self.falso.chamadas, [])

    def test_arquivo_velho_lista_ou_sem_janela_recusados(self):
        """Revisão de 07/11: --reservas aceitava arquivo de ontem, lista solta e {data} sem de/ate, com código 0."""
        d = ler(self.res)
        self.assertEqual(d["lido_em"], "2026-11-07T17:00:00Z")
        velho = self.arq("velho.json", dict(d, lido_em="2026-11-07T13:59:00Z"))       # 3 h e 1 min antes do relógio
        ontem = self.arq("ontem.json", dict(d, lido_em="2026-11-06T10:55:00Z"))
        sem_lido = self.arq("sem_lido.json", {k: v for k, v in d.items() if k != "lido_em"})
        lista = self.arq("lista.json", d["data"])
        so_data = self.arq("so_data.json", {"data": d["data"], "lido_em": d["lido_em"]})
        coleta = self.arq("coleta.json", COLETA)
        comandos = (["janelas", "--hoje", "2026-11-07"],
                    ["comparar", "--coleta", coleta, "--de", "2026-11-07", "--ate", "2027-01-06"])
        for arq, trecho in ((velho, "há 3.0 h"), (ontem, "de outra rotina"), (sem_lido, "não diz quando foi lido"),
                            (lista, "lista solta"), (so_data, "faltam de, ate ou data")):
            for argv in comandos:
                with self.subTest(arq=os.path.basename(arq), cmd=argv[0]):
                    f = Beds24Falso()
                    rc, out, err = self.executar(beds24.main, argv + ["--reservas", arq], f)
                    self.assertEqual((rc, out), (2, ""))
                    self.assertIn(trecho, err)
                    self.assertEqual(f.chamadas, [])
        quase = self.arq("quase.json", dict(d, lido_em="2026-11-07T14:00:00Z"))        # 3 h exatas: ainda vale
        rc, out, err = self.executar(beds24.main, comandos[0] + ["--reservas", quase], Beds24Falso())
        self.assertEqual(rc, 0, err)

    def test_concorrencia_hidro_vista_com_reservas(self):
        dia = lambda d, p, mu: {"date": d, "price": p, "min_stay": 2, "multi_unit_occupancy": mu, "booking_status": ""}
        datas = ("2026-11-14", "2026-11-15", "2026-11-20")
        coleta = self.arq("coleta_c.json", {"rooms": {k: {"days": [dia(s, p, f"0/{u}") for s in datas]}
                                                      for k, p, u in (("Q7", 2000, 7), ("Double", 2100, 6),
                                                                      ("Afrodite", 2600, 1), ("Q2", 1800, 2),
                                                                      ("VK7", 2100, 7), ("VK2", 1900, 2),
                                                                      ("Balcony", 1640, 6))}})
        comp = self.arq("comp.json", {"periodos": [{"rotulo": "t", "datas": list(datas), "mediana_a": 700,
                                                    "recanto": 1300}]})
        s1, s2 = self.arq("p1.json"), self.arq("p2.json")
        base = ["--coleta", coleta, "--hoje", "2026-11-07", "--comp", comp]
        vivo, arq = Beds24Falso(), Beds24Falso()
        rc1, out1, err1 = self.executar(concorrencia_hidro_vista.main, base + ["--saida", s1], vivo)
        rc2, out2, err2 = self.executar(concorrencia_hidro_vista.main, base + ["--saida", s2, "--reservas", self.res], arq)
        self.assertEqual((rc1, rc2), (0, 0), err1 + err2)
        self.assertEqual(ler(s1), ler(s2))
        self.assertEqual(out1.replace(s1, "X"), out2.replace(s2, "X"))
        self.assertEqual((vivo.contar(*GB), arq.chamadas), (1, []))
        velho = self.arq("velho.json", dict(ler(self.res), lido_em="2026-11-07T10:00:00Z"))
        f = Beds24Falso()
        rc, out, err = self.executar(concorrencia_hidro_vista.main, base + ["--saida", s2, "--reservas", velho], f)
        self.assertEqual((rc, f.chamadas), (2, []))                     # arquivo velho: recusado, sem leitura
        self.assertIn("de outra rotina", err)
        with self.assertRaises(SystemExit):                              # --sem-beds24 e --reservas não se misturam
            with contextlib.redirect_stderr(io.StringIO()):
                concorrencia_hidro_vista.main(base + ["--sem-beds24", "--reservas", self.res])


class TestInventarioPrecos(Base):
    def test_price1_entra_sem_mudar_as_outras_chaves(self):
        f0, f1 = self.arq("inv0.json"), self.arq("inv1.json")
        sem, com = Beds24Falso(), Beds24Falso()
        self.executar(beds24.main, ["inventario", "--de", "2026-11-08", "--ate", "2026-11-12", "--json", f0], sem)
        self.executar(beds24.main, ["inventario", "--de", "2026-11-08", "--ate", "2026-11-12", "--precos", "--json", f1],
                      com)
        self.assertNotIn("includePrices", sem.chamadas[0][2])
        self.assertEqual(com.chamadas[0][2]["includePrices"], ["true"])
        i0, i1 = ler(f0), ler(f1)
        self.assertEqual(set(i0), set(i1))
        for q in (k for k in i0 if not k.startswith("_")):
            for ds, v in i0[q].items():
                self.assertNotIn("price1", v)
                self.assertEqual({k: x for k, x in i1[q][ds].items() if k != "price1"}, v)
                self.assertEqual(i1[q][ds]["price1"], PRECO1[beds24.ID_DO_CURTO[q]])
        import calendario_12m                                            # quem já lê o inventário continua lendo
        inv, lido_em = calendario_12m.ler_inventario(f1)
        self.assertEqual(lido_em, i1["_lido_em"])
        self.assertEqual(inv["Q7"]["2026-11-08"]["numAvail"], 3)
        self.assertEqual(reservas_abaixo.precos_do_inventario(i1)[("722805", "2026-11-08")], 1000)


class TestEditarLoteVariosPlanos(Base):
    def setUp(self):
        super().setUp()
        self.urgente = [{"quarto": "Q7", "de": "2026-11-09", "ate": "2026-11-09", "min_stay": 1, "min_stay_antes": 2,
                         "motivo": "t"},
                        {"quarto": "VK7", "de": "2026-11-10", "ate": "2026-11-10", "min_stay": 1, "min_stay_antes": 2,
                         "motivo": "t"}]
        self.resto = [{"quarto": "Q7", "de": "2026-12-01", "ate": "2026-12-01", "min_stay": 3, "min_stay_antes": 2,
                       "motivo": "t", "price1": 999, "preco": 999},          # preço no plano nunca vai ao POST
                      {"quarto": "Double", "de": "2026-12-02", "ate": "2026-12-02", "override": "noCheckIn",
                       "motivo": "t"},
                      {"quarto": "Double", "de": "2026-12-03", "ate": "2026-12-03", "min_stay": 1, "min_stay_antes": 2,
                       "motivo": "t"}]                                       # já no alvo: pulado
        self.falso.dia(722806, "2026-12-03")["minStay"] = 1
        self.fa, self.fb = self.arq("lote_urgente.json", self.urgente), self.arq("lote_quartos.json", self.resto)

    def test_dois_planos_num_post_so_com_leitura_antes_e_releitura(self):
        rc, out, err = self.executar(beds24.main, ["editar-lote", "--plano", self.fa, "--plano", self.fb, "--aplicar"])
        self.assertEqual(rc, 0, err)
        r = json.loads(out)
        self.assertEqual(r["planos"], [{"arquivo": self.fa, "itens": 2}, {"arquivo": self.fb, "itens": 3}])
        self.assertEqual((r["itens"], r["a_gravar"], r["n_blocos"], r["conferidos"], r["erros"]), (5, 4, 1, 4, []))
        self.assertEqual([p["por_que"] for p in r["pulados"]], ["já no alvo"])
        self.assertEqual(self.chamadas(self.falso),
                         [GC, ("GET", "/authentication/token"), ("POST", "/inventory/rooms/calendar"), GC])
        corpo = self.falso.posts[0]
        self.assertEqual(corpo[0]["calendar"][0]["from"], "2026-11-09")  # o urgente na frente
        self.assertEqual([x["roomId"] for x in corpo], [722805, 722813, 722806])
        self.assertFalse(any(k.startswith("price") for x in corpo for fx in x["calendar"] for k in fx))
        self.assertEqual((self.falso.dia(722805, "2026-11-09")["minStay"], self.falso.dia(722805, "2026-12-01")["minStay"],
                          self.falso.dia(722806, "2026-12-02")["override"]), (1, 3, "noCheckIn"))
        self.assertNotIn(TOKEN_ESCRITA, out + err)
        # na repetição, tudo já está no alvo ou é o mesmo valor: os de min_stay_antes são pulados
        falso2 = self.falso
        rc, out, err = self.executar(beds24.main, ["editar-lote", "--plano", self.fa, "--plano", self.fb], falso2)
        r2 = json.loads(out)
        self.assertEqual(sorted(p["por_que"] for p in r2["pulados"]), ["já no alvo"] * 4)

    def test_blocos_menores_mantem_a_ordem(self):
        rc, out, err = self.executar(beds24.main, ["editar-lote", "--plano", self.fa, "--plano", self.fb, "--aplicar",
                                                   "--max-faixas", "2"])
        self.assertEqual(rc, 0, err)
        self.assertEqual(json.loads(out)["n_blocos"], 2)
        primeiro = {fx["from"] for x in self.falso.posts[0] for fx in x["calendar"]}
        self.assertEqual(primeiro, {"2026-11-09", "2026-11-10"})
        self.assertEqual(self.falso.contar("GET", "/authentication/token"), 1)   # 1 token para a rodada inteira

    def test_conflito_entre_planos_para_antes_de_ler(self):
        fc = self.arq("lote_c.json", [dict(self.urgente[0], min_stay=2, min_stay_antes=2)])
        rc, out, err = self.executar(beds24.main, ["editar-lote", "--plano", self.fa, "--plano", fc, "--aplicar"])
        self.assertEqual(rc, 2)
        self.assertIn("planos em conflito", err)
        self.assertEqual(self.falso.chamadas, [])
        rc, out, err = self.executar(beds24.main, ["editar-lote", "--plano", self.fa, "--plano", self.fa])
        self.assertEqual(rc, 2)

    def test_um_plano_sem_chave_nova(self):
        rc, out, err = self.executar(beds24.main, ["editar-lote", "--plano", self.fa, "--aplicar"])
        self.assertEqual(rc, 0, err)
        self.assertNotIn("planos", json.loads(out))


class TestTokenEscrita(Base):
    def test_guardado_valido_vencido_e_permissao_600(self):
        relogio = [1_800_000_000.0]
        with ambiente(self.falso, self.tmp):
            beds24._agora = lambda: relogio[0]
            arq = beds24.ARQ_TOKEN_ESCRITA = os.path.join(self.tmp, "nova", "token_escrita")
            self.assertEqual(beds24.token_escrita(), TOKEN_ESCRITA)
            self.assertEqual(self.falso.tokens_emitidos, 1)
            self.assertEqual(stat.S_IMODE(os.stat(arq).st_mode), 0o600)
            self.assertEqual(stat.S_IMODE(os.stat(os.path.dirname(arq)).st_mode), 0o700)
            self.assertEqual(ler(arq)["vence_em"], relogio[0] + 3600)
            relogio[0] += 3600 - 301                                     # válido: fora da margem de 5 min
            self.assertEqual(beds24.token_escrita(), TOKEN_ESCRITA)
            self.assertEqual(self.falso.tokens_emitidos, 1)
            relogio[0] += 2                                              # dentro da margem: vencido
            beds24.token_escrita()
            self.assertEqual(self.falso.tokens_emitidos, 2)
            self.assertEqual(ler(arq)["vence_em"], relogio[0] + 3600)
            os.chmod(arq, 0o644)                                         # aberto demais: não usa, regrava com 600
            beds24.token_escrita()
            self.assertEqual(self.falso.tokens_emitidos, 3)
            self.assertEqual(stat.S_IMODE(os.stat(arq).st_mode), 0o600)
            self.assertEqual(os.listdir(os.path.dirname(arq)), ["token_escrita"])   # sem sobra do arquivo temporário

    def test_sem_expires_in_nao_guarda(self):
        with ambiente(self.falso, self.tmp):
            for validade in (None, 200):                                 # sem expiresIn, ou menor que a margem
                self.falso.expires_in = validade
                beds24.descartar_token_escrita()
                beds24.token_escrita()
                beds24.token_escrita()
                self.assertFalse(os.path.exists(beds24.ARQ_TOKEN_ESCRITA))
            self.assertEqual(self.falso.tokens_emitidos, 4)

    def test_401_com_token_guardado_troca_e_tenta_uma_vez(self):
        fa = self.arq("lote.json", [{"quarto": "Q7", "de": "2026-11-09", "ate": "2026-11-09", "min_stay": 1,
                                     "min_stay_antes": 2, "motivo": "t"}])
        self.falso.erros = [("POST", "/inventory/rooms/calendar", 401, {})]
        with ambiente(self.falso, self.tmp):
            beds24._guardar_token("tok-velho-revogado", 3600)
            rc, out, err = rodar(beds24.main, ["editar-lote", "--plano", fa, "--aplicar"])
            guardado = ler(beds24.ARQ_TOKEN_ESCRITA)["token"]
        self.assertEqual(rc, 0, err)
        self.assertEqual(self.falso.tokens_usados, ["tok-velho-revogado", TOKEN_ESCRITA])
        self.assertEqual((self.falso.tokens_emitidos, guardado), (1, TOKEN_ESCRITA))
        self.assertEqual(json.loads(out)["conferidos"], 1)
        self.assertNotIn("tok-velho", out + err)
        self.assertNotIn(TOKEN_ESCRITA, out + err)


    def test_editar_com_token_guardado_revogado_troca_e_tenta_uma_vez(self):
        """Revisão de 07/11: o `editar --aplicar` usava o token guardado sem o tratamento de 401 do editar-lote e falhava
        em toda execução até o vencimento guardado."""
        argv = ["editar", "--quarto", "Q7", "--de", "2026-11-09", "--ate", "2026-11-09", "--min-stay", "1", "--motivo",
                "t", "--aplicar"]
        self.falso.erros = [("POST", "/inventory/rooms/calendar", 401, {})]
        with ambiente(self.falso, self.tmp):
            beds24._guardar_token("tok-velho-revogado", 3600)
            rc, out, err = rodar(beds24.main, argv)
            guardado = ler(beds24.ARQ_TOKEN_ESCRITA)["token"]
        self.assertEqual(rc, 0, err)
        self.assertEqual(self.falso.tokens_usados, ["tok-velho-revogado", TOKEN_ESCRITA])
        self.assertEqual((self.falso.tokens_emitidos, guardado), (1, TOKEN_ESCRITA))
        self.assertEqual(self.falso.dia(722805, "2026-11-09")["minStay"], 1)
        self.assertNotIn("tok-velho", out + err)
        self.assertNotIn(TOKEN_ESCRITA, out + err)
        # 401 também com o token novo: troca só 1 vez e sai com erro (o guardado acima é usado primeiro)
        falso = Beds24Falso()
        falso.erros = [("POST", "/inventory/rooms/calendar", 401, {})] * 2
        rc, out, err = self.executar(beds24.main, argv, falso)
        self.assertEqual(rc, 2)
        self.assertIn("HTTP 401", err)
        self.assertEqual((falso.contar("POST", "/inventory/rooms/calendar"), falso.tokens_emitidos), (2, 1))


class TestCredito(Base):
    def _com(self, cab, relogio=None):
        falso, dormidas = Beds24Falso(cab=cab), []
        ctx = ambiente(falso, self.tmp)
        ctx.__enter__()
        self.addCleanup(ctx.__exit__, None, None, None)
        beds24._dormir = dormidas.append
        if relogio is not None:
            beds24._agora = lambda: relogio[0]
        return falso, dormidas

    def test_saldo_baixo_espera_a_janela_zerar(self):
        falso, dormidas = self._com({"X-Request-Cost": "1", "X-Five-Min-Limit-Resets-In": "40",
                                     "X-Five-Min-Limit-Remaining": "2.5"})
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            beds24._get("/properties")
            self.assertEqual(dormidas, [])
            beds24._get("/properties")
        self.assertEqual(dormidas, [41.0])
        self.assertIn("crédito de 5 min baixo", err.getvalue())

    def test_saldo_alto_nao_espera_e_nomes_da_spec_valem(self):
        falso, dormidas = self._com({"X-Five-Min-Limit-Resets-In": "40", "X-Five-Min-Limit-Remaining": "80"})
        for _ in range(5):
            beds24._get("/properties")
        self.assertEqual(dormidas, [])
        falso.cab = {"X-FiveMinCreditLimit-ResetsIn": "30", "X-FiveMinCreditLimit-Remaining": "1",
                     "X-RequestCost": "1"}
        with contextlib.redirect_stderr(io.StringIO()):
            beds24._get("/properties")
            beds24._post("/inventory/rooms/calendar", [], {"token": "x"})   # o POST também espera
        self.assertEqual(dormidas, [31.0])

    def test_janela_ja_zerada_nao_espera(self):
        relogio = [1_800_000_000.0]
        falso, dormidas = self._com({"X-Five-Min-Limit-Resets-In": "40", "X-Five-Min-Limit-Remaining": "1"}, relogio)
        beds24._get("/properties")
        relogio[0] += 41
        beds24._get("/properties")
        self.assertEqual(dormidas, [])

    def test_429_espera_o_resets_in_e_tenta_uma_vez_so(self):
        falso, dormidas = self._com(None)
        falso.erros = [("GET", "/properties", 429, {"X-Five-Min-Limit-Resets-In": "17"})]
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertIn("data", beds24._get("/properties"))
        self.assertEqual((falso.contar("GET", "/properties"), dormidas), (2, [18.0]))
        falso.erros = [("GET", "/properties", 429, {"X-Five-Min-Limit-Resets-In": "900"}),
                       ("GET", "/properties", 429, {})]
        with contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(beds24.ErroBeds24) as e:
                beds24._get("/properties")
        self.assertEqual(e.exception.codigo, 429)
        self.assertEqual(falso.contar("GET", "/properties"), 4)          # 1 tentativa a mais, não 2
        self.assertEqual(dormidas, [18.0, beds24.ESPERA_MAXIMA_S])       # nunca mais que a janela de 5 min

    def test_editar_lote_soma_as_esperas_e_teto_configuravel(self):
        """Revisão de 07/11: a espera vale para os comandos antigos (até 310 s por chamada); o resumo do editar-lote
        diz quanto esperou, e BEDS24_ESPERA_MAXIMA_S baixa o teto de cada espera."""
        plano = self.arq("lote.json", [{"quarto": "Q7", "de": "2026-11-09", "ate": "2026-11-09", "min_stay": 1,
                                        "min_stay_antes": 2, "motivo": "t"}])
        falso, dormidas = self._com({"X-Five-Min-Limit-Resets-In": "300", "X-Five-Min-Limit-Remaining": "80",
                                     "X-Request-Cost": "1"})
        falso.erros = [("POST", "/inventory/rooms/calendar", 429, {"X-Five-Min-Limit-Resets-In": "300"})]
        rc, out, err = rodar(beds24.main, ["editar-lote", "--plano", plano, "--aplicar"])
        r = json.loads(out)
        self.assertEqual((rc, r["conferidos"], r["espera_credito_s"], dormidas), (0, 1, 301.0, [301.0]))
        self.assertIn("429", err)
        for teto, esperado in (("30", 30.0), ("9999", 301.0), ("x", 301.0), ("0", None)):
            with self.subTest(teto=teto):
                dormidas.clear()
                falso.erros = [("POST", "/inventory/rooms/calendar", 429, {"X-Five-Min-Limit-Resets-In": "300"})]
                falso.dia(722805, "2026-11-09")["minStay"] = 2
                with mock.patch.dict(os.environ, {"BEDS24_ESPERA_MAXIMA_S": teto}):
                    rc, out, err = rodar(beds24.main, ["editar-lote", "--plano", plano, "--aplicar"])
                r = json.loads(out)
                self.assertEqual((rc, r["conferidos"]), (0, 1), err)
                self.assertEqual((dormidas, r.get("espera_credito_s")), ([esperado] if esperado else [], esperado))
        dormidas.clear()                                                 # sem espera: o resumo de sempre
        falso.dia(722805, "2026-11-09")["minStay"] = 2
        rc, out, err = rodar(beds24.main, ["editar-lote", "--plano", plano, "--aplicar"])
        self.assertNotIn("espera_credito_s", json.loads(out))



class TestConferenciaFinal0711(unittest.TestCase):
    """Achados da conferência final de 07/11: item repetido entre planos e rateDescription longo."""

    def test_item_igual_em_dois_planos_vai_uma_vez(self):
        a = [{"quarto": "Q2", "de": "2026-11-14", "ate": "2026-11-14", "override": "noCheckOut"}]
        b = [{"quarto": "Q2", "de": "2026-11-14", "ate": "2026-11-14", "override": "noCheckOut"},
             {"quarto": "Q7", "de": "2026-11-15", "ate": "2026-11-15", "min_stay": 1}]
        itens, resumo = beds24.juntar_planos([("urgente", a), ("Q2", b)])
        self.assertEqual(len(itens), 2)
        self.assertEqual(resumo[1].get("repetidos_ignorados"), 1)
        self.assertNotIn("repetidos_ignorados", resumo[0])

    def test_item_com_parte_nova_nao_some(self):
        a = [{"quarto": "Q2", "de": "2026-11-14", "ate": "2026-11-14", "override": "noCheckOut"}]
        b = [{"quarto": "Q2", "de": "2026-11-14", "ate": "2026-11-15", "override": "noCheckOut"}]
        itens, _ = beds24.juntar_planos([("urgente", a), ("Q2", b)])
        self.assertEqual(len(itens), 2)

    def test_guarda_diferente_nao_some(self):
        a = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-14", "min_stay": 1, "min_stay_antes": 2}]
        b = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-14", "min_stay": 1, "min_stay_antes": 3}]
        itens, resumo = beds24.juntar_planos([("urgente", a), ("Q7", b)])
        self.assertEqual(len(itens), 2)
        self.assertNotIn("repetidos_ignorados", resumo[1])

    def test_faixa_menor_coberta_nao_some(self):
        a = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-16", "min_stay": 1, "min_stay_antes": 2}]
        b = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-14", "min_stay": 1, "min_stay_antes": 2}]
        itens, _ = beds24.juntar_planos([("urgente", a), ("Q7", b)])
        self.assertEqual(len(itens), 2)

    def test_sem_guarda_depois_de_com_guarda_nao_some(self):
        a = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-14", "min_stay": 1, "min_stay_antes": 2}]
        b = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-14", "min_stay": 1}]
        itens, _ = beds24.juntar_planos([("urgente", a), ("Q7", b)])
        self.assertEqual(len(itens), 2)

    def test_num_avail_esperado_diferente_nao_some(self):
        a = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-14", "num_avail": 0, "num_avail_esperado": 1}]
        b = [{"quarto": "Q7", "de": "2026-11-14", "ate": "2026-11-14", "num_avail": 0, "num_avail_esperado": 2}]
        itens, _ = beds24.juntar_planos([("urgente", a), ("Q7", b)])
        self.assertEqual(len(itens), 2)

    def test_campos_iguais_ao_lote_unico(self):
        import calendario_12m
        self.assertEqual(beds24.CAMPOS_ITEM_LOTE_UNICO, calendario_12m.CAMPOS_ITEM_LOTE)

    def test_mesmo_plano_nao_deduplica(self):
        a = [{"quarto": "Q2", "de": "2026-11-14", "ate": "2026-11-14", "override": "noCheckOut"}] * 2
        itens, _ = beds24.juntar_planos([("urgente", a)])
        self.assertEqual(len(itens), 2)

    def test_conflito_segue_barrado(self):
        a = [{"quarto": "Q2", "de": "2026-11-14", "ate": "2026-11-14", "min_stay": 1}]
        b = [{"quarto": "Q2", "de": "2026-11-14", "ate": "2026-11-14", "min_stay": 2}]
        with self.assertRaises(beds24.ErroBeds24):
            beds24.juntar_planos([("urgente", a), ("Q2", b)])

    def test_rate_description_cortado_em_120(self):
        r = beds24.sem_nomes({"id": 1, "rateDescription": "x" * 300, "firstName": "Fulano"})
        self.assertEqual(len(r["rateDescription"]), 120)
        self.assertNotIn("firstName", r)


if __name__ == "__main__":
    unittest.main()

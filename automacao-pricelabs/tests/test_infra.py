import http.server
import json
import os
import tempfile
import threading
import unittest
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from pacing import config, credenciais, estado, tempo
from pacing.rede import ErroRede, Rede, RotaProibida, classificar, transporte_urllib

PL = "https://api.pricelabs.co/v1"


class TransporteFalso:
    def __init__(self, respostas):
        self.respostas = list(respostas)
        self.pedidos = []

    def __call__(self, metodo, url, cab, corpo, timeout):
        self.pedidos.append((metodo, url, cab, corpo))
        r = self.respostas.pop(0)
        if isinstance(r, Exception):
            raise r
        return r


def ok(dados=None, status=200, cab=None):
    return status, cab or {}, json.dumps(dados if dados is not None else {}).encode()


class TestRede(unittest.TestCase):
    def rede(self, respostas, escritas=frozenset()):
        t = TransporteFalso(respostas)
        self.esperas = []
        return Rede(t, escritas, espaco_s=0, dormir=self.esperas.append), t

    def test_rota_fora_da_lista_nao_sai(self):
        rede, t = self.rede([])
        for metodo, url in [("GET", "https://evil.example/v1/listings"), ("POST", PL + "/listings"),
                            ("POST", PL + "/refresh_listing"), ("POST", PL + "/push_prices"),
                            ("GET", PL + "/listings/../customizations/overrides"), ("DELETE", PL + "/listings")]:
            with self.assertRaises(RotaProibida):
                rede.pedir(metodo, url, {})
        self.assertEqual(t.pedidos, [])

    def test_escrita_exige_metodo_liberado(self):
        url = PL + "/listings/350362___722807/overrides"
        rede, t = self.rede([ok()])
        with self.assertRaises(RotaProibida):
            rede.pedir("POST", url, {}, {"x": 1})
        rede_delete, _ = self.rede([ok()], frozenset({"DELETE"}))
        with self.assertRaises(RotaProibida):
            rede_delete.pedir("POST", url, {}, {"x": 1})
        rede_delete.pedir("DELETE", url, {}, {"x": 1})
        self.assertEqual(classificar("GET", url + "?pms=beds24"), "leitura")
        self.assertIsNone(classificar("GET", PL + "/listings/abc/overrides"))

    def test_repete_429_e_5xx_ate_duas_vezes(self):
        rede, t = self.rede([ok(status=429, cab={"retry-after": "3"}), ok(status=503), ok({"a": 1})])
        r = rede.pedir("GET", PL + "/listings", {})
        self.assertEqual(r.dados, {"a": 1})
        self.assertEqual(len(t.pedidos), 3)
        self.assertEqual(self.esperas, [3.0, 4.0])
        rede, t = self.rede([ok(status=500)] * 3)
        with self.assertRaises(ErroRede) as e:
            rede.pedir("GET", PL + "/listings", {})
        self.assertEqual(e.exception.status, 500)
        self.assertEqual(len(t.pedidos), 3)

    def test_4xx_nao_repete_e_nao_vaza_cabecalho(self):
        rede, t = self.rede([ok({"error": {"code": "API_KEY_INVALID"}}, status=403)])
        with self.assertRaises(ErroRede) as e:
            rede.pedir("GET", PL + "/listings", {"X-API-Key": "segredo-123"})
        self.assertEqual((e.exception.status, e.exception.codigo), (403, "API_KEY_INVALID"))
        self.assertNotIn("segredo-123", str(e.exception) + e.exception.codigo)
        self.assertEqual(len(t.pedidos), 1)

    def test_falha_de_conexao_repete_e_depois_propaga(self):
        rede, t = self.rede([ErroRede("sem conexão"), ok({"b": 2})])
        self.assertEqual(rede.pedir("GET", PL + "/listings", {}).dados, {"b": 2})
        rede, t = self.rede([ErroRede("x")] * 3)
        with self.assertRaises(ErroRede):
            rede.pedir("GET", PL + "/listings", {})
        self.assertEqual(rede.chamadas[-1][2], None)

    def test_espacamento_entre_chamadas(self):
        instantes = iter([0.0, 0.0, 0.5, 1.1])
        esperas = []
        rede = Rede(TransporteFalso([ok(), ok()]), espaco_s=1.1, dormir=esperas.append, relogio=lambda: next(instantes))
        rede.pedir("GET", PL + "/listings", {})
        rede.pedir("GET", PL + "/listings", {})
        self.assertAlmostEqual(esperas[0], 0.6)

    def test_corpo_invalido_vira_none(self):
        rede, _ = self.rede([(200, {}, b"<html>")])
        self.assertIsNone(rede.pedir("GET", PL + "/listings", {}).dados)


class TestTransporteReal(unittest.TestCase):
    def test_redirecionamento_nao_e_seguido(self):
        recebidos = []

        class H(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                recebidos.append(self.path)
                self.send_response(302)
                self.send_header("Location", "/outro")
                self.end_headers()

            def log_message(self, *a):
                pass

        srv = http.server.HTTPServer(("127.0.0.1", 0), H)
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        try:
            status, _, _ = transporte_urllib("GET", f"http://127.0.0.1:{srv.server_port}/x", {"X-API-Key": "k"}, None, 5)
        finally:
            srv.shutdown()
            srv.server_close()
        self.assertEqual(status, 302)
        self.assertEqual(recebidos, ["/x"])

    def test_sem_conexao_vira_erro_rede(self):
        with self.assertRaises(ErroRede):
            transporte_urllib("GET", "http://127.0.0.1:9/", {}, None, 2)


class CofreFalso:
    def __init__(self):
        self.dados = {}

    def ler(self, alvo):
        return self.dados.get(alvo)

    def salvar(self, alvo, valor):
        self.dados[alvo] = valor


class TestCredenciais(unittest.TestCase):
    def test_ordem_cofre_depois_ambiente(self):
        cofre = CofreFalso()
        self.assertEqual(credenciais.ler("pricelabs", {"PRICELABS_API_KEY": " abc "}, cofre), "abc")
        credenciais.salvar("pricelabs", "do-cofre", cofre)
        self.assertEqual(credenciais.ler("pricelabs", {"PRICELABS_API_KEY": "abc"}, cofre), "do-cofre")

    def test_ausente_ou_invalida(self):
        with self.assertRaises(credenciais.ChaveAusente):
            credenciais.ler("openrouter", {}, CofreFalso())
        for ruim in ("com espaço", "acentuação", "a\tb"):
            with self.assertRaises(credenciais.ChaveAusente) as e:
                credenciais.ler("openrouter", {"OPENROUTER_API_KEY": ruim}, CofreFalso())
            self.assertNotIn(ruim, str(e.exception))

    def test_salvar_sem_cofre(self):
        if os.name != "nt":
            with self.assertRaises(credenciais.ChaveAusente):
                credenciais.salvar("pricelabs", "x")


class TestEstado(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="André Área "))

    def test_trava_unica_e_orfa(self):
        with estado.Trava(self.tmp):
            with self.assertRaises(estado.OutraExecucao):
                with estado.Trava(self.tmp):
                    pass
        self.assertFalse((self.tmp / "execucao.trava").exists())
        trava = self.tmp / "execucao.trava"
        trava.write_text("")  # recém-criada por outra execução, ainda vazia: não é órfã
        with self.assertRaises(estado.OutraExecucao):
            with estado.Trava(self.tmp):
                pass
        velho = trava.stat().st_mtime - 1801
        os.utime(trava, (velho, velho))
        with estado.Trava(self.tmp):
            trava.write_text("outra execução")  # trava trocada por outra instância
        self.assertTrue(trava.exists(), "não apaga trava que não é sua")

    def test_salvar_carregar_e_bak(self):
        a = estado.Armazem(self.tmp)
        est, origem = a.carregar()
        self.assertEqual(origem, "novo")
        est["dsos"]["x|2026-09-26"] = {"listing": "x", "data": "2026-09-26"}
        a.salvar(est)
        a.salvar(est)
        self.assertEqual(a.carregar()[1], "ok")
        a.arquivo.write_text("{corrompido", encoding="utf-8")
        est2, origem = a.carregar()
        self.assertEqual(origem, "bak")
        self.assertIn("x|2026-09-26", est2["dsos"])

    def test_reconstroi_pelo_diario_com_status(self):
        a = estado.Armazem(self.tmp)
        p = {"date": "2026-09-27", "price": "-10", "price_type": "percent", "reason": "auto-jev r1"}
        eventos = [("intencao", "1___1", "2026-09-27"), ("criada", "1___1", "2026-09-27"),
                   ("intencao", "1___1", "2026-09-28"), ("incerta", "1___1", "2026-09-28"),
                   ("intencao", "1___1", "2026-09-29"), ("apagada", "1___1", "2026-09-29"),
                   ("intencao", "1___1", "2026-09-30")]
        for ev, lid, d in eventos:
            a.registrar_escrita({"ts": "2026-09-26T05:30:00-03:00", "run_id": "r1", "evento": ev, "listing": lid,
                                 "data": d, "payload": {**p, "date": d}, "lido": {"date": d} if ev == "criada" else None})
        with open(a.diario, "a") as f:
            f.write("linha quebrada\n")
        est, origem = a.carregar()
        self.assertEqual(origem, "diario")
        status = {k.split("|")[1]: v["status"] for k, v in est["dsos"].items()}
        self.assertEqual(status, {"2026-09-27": "ativa", "2026-09-28": "incerta", "2026-09-30": "pendente"})
        self.assertEqual(est["dsos"]["1___1|2026-09-27"]["lido"], {"date": "2026-09-27"})

    def test_historico_csv_para_excel(self):
        a = estado.Armazem(self.tmp)
        a.anexar_historico({"data_hora": "25/09/2026 23:30", "ocup": "43,5"})
        a.anexar_historico({"data_hora": "26/09/2026 05:30", "ocup": "44,0"})
        bruto = (self.tmp / "historico.csv").read_bytes()
        self.assertTrue(bruto.startswith(b"\xef\xbb\xbf"))
        self.assertEqual(bruto.count(b"\xef\xbb\xbf"), 1)
        self.assertIn("data_hora;ocup", bruto.decode("utf-8-sig"))
        self.assertIn("43,5", bruto.decode("utf-8-sig"))
        a.registrar_execucao({"run_id": "r"}, "2026-09")
        self.assertTrue((self.tmp / "execucoes" / "2026-09.jsonl").exists())

    def test_pasta_padrao(self):
        self.assertIn("automacao-pricelabs", str(estado.pasta_padrao()))


RAIZ = Path(__file__).resolve().parent.parent


class TestConfig(unittest.TestCase):
    def base(self):
        return json.loads((RAIZ / "config.json").read_text(encoding="utf-8"))

    def test_config_padrao_valido_e_seguro(self):
        c = config.carregar(RAIZ / "config.json")
        self.assertEqual(c["modo"], "observar")
        permitidos = [x["apelido"] for x in c["listings"] if x["desconto_permitido"]]
        self.assertEqual(permitidos, ["Moinhos Afrodite (1)"])

    def test_recusa_config_perigoso(self):
        casos = [
            lambda c: c["jev"].update(confianca_min=0.79),
            lambda c: c["jev"].update(probabilidade_min=0.6),
            lambda c: c["jev"].update(margem_min=0.2),
            lambda c: c["jev"].update(veto_max=0.31),
            lambda c: c["jev"].update(max_chamadas_dia=8),
            lambda c: c["desconto"].update(percentual=-15),
            lambda c: c["desconto"].update(percentual=True),
            lambda c: c["desconto"].update(vida_horas=72),
            lambda c: c["desconto"].update(teto_dia=7),
            lambda c: c["desconto"].update(sem_repetir_dias=3),
            lambda c: c["listings"][0].update(desconto_permitido=True),        # quarto com tabela
            lambda c: c["listings"][5].update(desconto_permitido=True),        # Balcony
            lambda c: c["listings"][3].update(desconto_permitido=True),        # pendência aberta
            lambda c: c["listings"][1].update(id="../../x"),
            lambda c: c["listings"][1].update(codigo="R3"),
            lambda c: c.update(modo="agressivo"),
            lambda c: c.update(horarios_sincronizacao=["25:00"]),
            lambda c: c.update(janela_criacao_horas=24),
            lambda c: c.update(extra=1),
            lambda c: c.pop("jev"),
            lambda c: c.update(feriados_locais=["31/12"]),
            lambda c: c["metas"].update({"0-6": 170}),
        ]
        for i, mudar in enumerate(casos):
            c = self.base()
            mudar(c)
            with self.assertRaises(config.ConfigInvalida, msg=f"caso {i}"):
                config.validar(c)

    def test_arquivo_ilegivel(self):
        with self.assertRaises(config.ConfigInvalida):
            config.carregar(RAIZ / "nao-existe.json")


class TestTempo(unittest.TestCase):
    def test_pascoa_e_feriados(self):
        self.assertEqual(tempo.pascoa(2026), date(2026, 4, 5))
        self.assertEqual(tempo.pascoa(2027), date(2027, 3, 28))
        self.assertEqual(tempo.pascoa(2028), date(2028, 4, 16))
        f = tempo.feriados(2027, ["2027-06-13"])
        for d in (date(2027, 2, 8), date(2027, 2, 9), date(2027, 3, 26), date(2027, 5, 27), date(2027, 11, 20), date(2027, 6, 13)):
            self.assertIn(d, f)
        bloq = tempo.dias_bloqueados(date(2026, 9, 25), date(2026, 11, 30))
        for d in ("2026-10-11", "2026-10-12", "2026-11-01", "2026-11-02", "2026-11-19", "2026-11-20"):
            self.assertIn(date.fromisoformat(d), bloq)
        self.assertNotIn(date(2026, 10, 2), bloq)
        self.assertIn(date(2026, 12, 31), tempo.dias_bloqueados(date(2026, 12, 20), date(2027, 1, 2)))

    def test_proxima_sincronizacao(self):
        brt = tempo.fuso(-3)
        t = datetime(2026, 9, 25, 23, 30, tzinfo=brt)
        self.assertEqual(tempo.proxima_sincronizacao(t, ["06:00"]), datetime(2026, 9, 26, 6, 0, tzinfo=brt))
        t = datetime(2026, 9, 26, 6, 0, tzinfo=brt)
        self.assertEqual(tempo.proxima_sincronizacao(t, ["06:00", "12:00"]), datetime(2026, 9, 26, 12, 0, tzinfo=brt))

    def test_ler_instante(self):
        self.assertEqual(tempo.ler_instante("2026-08-27T18:17:10.000Z"), datetime(2026, 8, 27, 18, 17, 10, tzinfo=timezone.utc))
        self.assertEqual(tempo.ler_instante("2026-08-27").tzinfo, timezone.utc)
        self.assertEqual(tempo.ler_instante("2026-08-27 10:00:00").hour, 10)
        for ruim in (None, "", "ontem", 123):
            self.assertIsNone(tempo.ler_instante(ruim))
        self.assertEqual(tempo.ler_instante("2026-08-27T18:17:10 UTC"), datetime(2026, 8, 27, 18, 17, 10, tzinfo=timezone.utc))
        self.assertIsNone(tempo.ler_instante("2026-13-45T99:00:00"))
        self.assertIsNone(tempo.ler_data("xx"))
        self.assertIsNotNone(tempo.agora(-3).tzinfo)
        self.assertEqual(tempo.agora(-3).utcoffset(), timedelta(hours=-3))


if __name__ == "__main__":
    unittest.main()

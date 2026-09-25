import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from datetime import date, datetime, timedelta
from pathlib import Path
from unittest import mock

from pacing import __main__ as cli
from pacing import principal
from pacing.estado import Armazem, Trava
from tests.simulador import BRT, Simulador, reserva

RAIZ = Path(__file__).resolve().parent.parent
AFRODITE = "350362___722807"
CANARIO_PL, CANARIO_OR = "pl-canario-7f3a9c", "or-canario-2b8e11"


class CofreVazio:
    def __init__(self):
        self.dados = {}

    def ler(self, alvo):
        return self.dados.get(alvo)

    def salvar(self, alvo, valor):
        self.dados[alvo] = valor


class Base(unittest.TestCase):
    modo = "observar"
    confirmar = True

    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="Recanto André "))
        self.mesa = self.tmp / "Área de Trabalho"
        self.mesa.mkdir()
        cfg = json.loads((RAIZ / "config.json").read_text(encoding="utf-8"))
        cfg.update(modo=self.modo, pasta_dados=str(self.tmp / "dados"), area_de_trabalho=str(self.mesa))
        cfg["jev"]["confirmar_duas_execucoes"] = self.confirmar
        self.cfg_path = self.tmp / "config.json"
        self.cfg_path.write_text(json.dumps(cfg, ensure_ascii=False), encoding="utf-8")
        self.agora = datetime(2026, 9, 25, 23, 30, tzinfo=BRT)
        self.sim = Simulador(self.agora)
        self.sim.overrides[AFRODITE]["2026-09-29"] = {"date": "2026-09-29", "min_stay": 2, "reason": "Vespera manual",
                                                      "price": None, "price_type": None}
        self.variaveis = {"PRICELABS_API_KEY": CANARIO_PL, "OPENROUTER_API_KEY": CANARIO_OR}
        self.cofre = CofreVazio()
        self.comandos = []
        self.amb = principal.Ambiente(transporte=self.sim, variaveis=self.variaveis, cofre=self.cofre,
                                      relogio=lambda: self.agora, dormir=lambda s: None, espaco_s=0,
                                      plataforma="linux", comando=self._comando)
        self.dados = self.tmp / "dados"

    def _comando(self, args, **kw):
        self.comandos.append(args)
        return mock.Mock(returncode=self.retorno_comando if hasattr(self, "retorno_comando") else 0, stdout="", stderr="erro")

    def rodar(self, quando=None):
        if quando:
            self.agora = quando
            self.sim.agora = quando
        return principal.executar(self.cfg_path, self.amb)

    def cli(self, *args):
        saida = io.StringIO()
        with redirect_stdout(saida):
            codigo = cli.main(["--config", str(self.cfg_path), *args], self.amb)
        return codigo, saida.getvalue()

    def estado(self):
        return Armazem(self.dados).carregar()[0]

    def nossas(self):
        return {d: o for d, o in self.sim.overrides[AFRODITE].items() if str(o.get("reason", "")).startswith("auto-jev")}

    def posts(self):
        return [c for c in self.sim.escritas() if c[0] == "POST"]


class TestObservar(Base):
    def test_observar_nao_escreve_e_registra_o_que_faria(self):
        r = self.rodar()
        self.assertEqual(self.sim.escritas(), [])
        self.assertEqual(len(self.sim.chamadas_jev()), 1)
        self.assertEqual(r["modo"], "observar")
        self.assertTrue(any("Sombra" in a for a in r["acoes"]))
        self.assertTrue(any(d["resultado"].startswith("sombra") for d in r["decisoes"]))
        self.assertTrue((self.dados / "relatorio.html").exists())
        self.assertEqual(len(list(self.mesa.glob("PRECOS * *.txt"))), 1)
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        self.assertEqual(self.sim.escritas(), [])
        self.assertTrue(any(a.startswith("Faria: desconto") for a in r["acoes"]))
        self.assertEqual(len(list(self.mesa.glob("PRECOS * *.txt"))), 1, "um único arquivo de status")
        est = self.estado()
        self.assertEqual(est["estabilidade"]["dias_com_jev"], ["2026-09-25", "2026-09-26"])
        self.assertGreater(est["estabilidade"]["comparacoes"], 0)
        self.assertIn("Ainda não é hora", r["saude"]["observacao"])

    def test_parar_nao_le_nem_escreve(self):
        self.dados.mkdir(parents=True)
        (self.dados / "PARAR").write_text("x")
        r = self.rodar()
        self.assertEqual(self.sim.chamadas, [])
        self.assertEqual((r["status"], r["modo"]), ("amarelo", "parado"))

    def test_chave_ausente_e_pricelabs_fora(self):
        self.variaveis.clear()
        r = self.rodar()
        self.assertEqual(r["status"], "vermelho")
        self.assertEqual(self.sim.chamadas, [])
        self.variaveis["PRICELABS_API_KEY"] = CANARIO_PL
        self.sim.falhas += [("GET", "/v1/listings", 500)] * 3
        r = self.rodar()
        self.assertEqual(r["status"], "vermelho")
        self.assertIn("falha ao ler o PriceLabs", r["resumo"])
        self.assertTrue((self.mesa / "ATENCAO-PRECOS.txt").exists())

    def test_segunda_instancia(self):
        self.dados.mkdir(parents=True)
        with Trava(self.dados):
            r = self.rodar()
        self.assertIn("outra execução", r["resumo"])
        self.assertEqual(self.sim.chamadas, [])

    def test_reservas_ilegiveis_e_mercado_indisponivel(self):
        self.sim.falhas.append(("GET", "reservation_data", 403))
        for _ in range(6):
            self.sim.falhas.append(("GET", "listing_metrics", 404))
        r = self.rodar()
        self.assertEqual(r["status"], "amarelo")
        texto = " ".join(r["alertas"])
        self.assertIn("dono da conta", texto)
        self.assertIn("mercado indisponível", texto)
        self.assertEqual(self.sim.chamadas_jev(), [])


class TestAtivo(Base):
    modo = "ativo"

    def test_confirma_em_duas_execucoes_e_cria_com_seguranca(self):
        self.rodar()
        self.assertEqual(self.posts(), [])
        self.assertEqual(len(self.sim.chamadas_jev()), 1)
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        self.assertEqual(len(self.sim.chamadas_jev()), 2)
        self.assertEqual(sorted(self.nossas()), ["2026-09-26", "2026-09-27", "2026-09-28"])
        for d, o in self.nossas().items():
            self.assertEqual((o["price"], o["price_type"]), ("-10", "percent"))
            self.assertNotIn("min_price", {k for k, v in o.items() if v is not None} - {"min_price"})
        self.assertNotIn("2026-09-29", [c[2]["overrides"][0]["date"] for c in self.posts()])
        est = self.estado()
        self.assertEqual({d["status"] for d in est["dsos"].values()}, {"ativa"})
        eventos = [json.loads(l)["evento"] for l in (self.dados / "diario-escrita.jsonl").read_text().splitlines()] \
            if (self.dados / "diario-escrita.jsonl").exists() else \
            [json.loads(l)["evento"] for l in (self.dados / "diario-escritas.jsonl").read_text().splitlines()]
        self.assertEqual(eventos, ["intencao", "criada"] * 3)
        self.assertTrue(any("criada" in a for a in r["acoes"]))
        self.assertEqual(self.sim.overrides[AFRODITE]["2026-09-29"]["reason"], "Vespera manual")

    def test_mesmo_dia_mesmos_dados_reaproveita_e_nao_confirma(self):
        self.rodar(datetime(2026, 9, 25, 23, 0, tzinfo=BRT))
        r = self.rodar(datetime(2026, 9, 25, 23, 30, tzinfo=BRT))
        self.assertEqual(len(self.sim.chamadas_jev()), 1)
        self.assertEqual(self.posts(), [])
        self.assertTrue(any("reaproveitada" in a for a in r["acoes"]))

    def test_jev_fora_do_ar_ou_invalido_nao_cria(self):
        for status in (503, 422, 402):
            self.sim.jev_status = status
            self.rodar()
            self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
            self.agora = datetime(2026, 9, 25, 23, 30, tzinfo=BRT)
        self.assertEqual(self.posts(), [])
        self.sim.jev_status = 200
        self.sim.jev = lambda pedido: {"model": "outro-modelo", "answers": {}, "usage": {}}
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        self.assertEqual(self.posts(), [])
        self.assertIn("Jev sem resposta válida", " ".join(r["alertas"]))
        self.assertEqual(r["status"], "amarelo")

    def test_jev_com_confianca_baixa_ou_veto_nao_cria(self):
        from tests.simulador import resposta_jev_padrao
        self.sim.jev = lambda p: resposta_jev_padrao(p, confianca=0.79)
        self.rodar()
        self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        self.sim.jev = lambda p: resposta_jev_padrao(p, veto=0.5)
        self.rodar(datetime(2026, 9, 26, 23, 30, tzinfo=BRT))
        self.rodar(datetime(2026, 9, 27, 5, 30, tzinfo=BRT))
        self.assertEqual(self.posts(), [])

    def test_teto_diario_de_chamadas_ao_jev(self):
        cfg = json.loads(self.cfg_path.read_text(encoding="utf-8"))
        cfg.update(modo="observar", janela_criacao_horas=12)   # observar mede estabilidade: chama sempre
        cfg["jev"]["max_chamadas_dia"] = 2
        self.cfg_path.write_text(json.dumps(cfg), encoding="utf-8")
        for i in range(4):
            r = self.rodar(datetime(2026, 9, 25, 18, 0, tzinfo=BRT) + timedelta(minutes=30 * i))
        self.assertEqual(len(self.sim.chamadas_jev()), 2)
        self.assertIn("Teto diário", " ".join(r["alertas"]))


class TestAtivoSemConfirmacao(Base):
    modo = "ativo"
    confirmar = False

    def test_post_incerto_e_conciliado(self):
        self.sim.quebrar_resposta_post = True
        r = self.rodar()
        self.assertEqual(len(self.posts()), 2, "cada POST uma vez; o segundo erro seguido aciona o disjuntor")
        resultados = [a for a in r["acoes"] if a.startswith("Desconto")]
        self.assertEqual(sum("incerto" in a for a in resultados), 2)
        self.assertEqual(sum("bloqueado: disjuntor" in a for a in resultados), 1)
        est = self.estado()
        self.assertTrue(est["disjuntor"]["ativo"])
        self.sim.quebrar_resposta_post = False
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        est = self.estado()
        self.assertEqual({d["status"] for d in est["dsos"].values()}, {"ativa"})
        self.assertTrue(any("confirmada" in a for a in r["acoes"]))
        self.assertEqual(r["modo"], "contencao")
        self.assertEqual(len(self.posts()), 2, "em contenção não cria")

    def test_post_recusado_nao_repete(self):
        self.sim.falhas.append(("POST", "/overrides", 400))
        self.rodar()
        est = self.estado()
        self.assertEqual(len(self.posts()), 3)
        self.assertEqual(len(est["dsos"]), 2)

    def test_humano_edita_substituicao_do_programa(self):
        self.rodar()
        data = sorted(self.nossas())[-1]
        self.sim.overrides[AFRODITE][data]["min_stay"] = 3
        codigo, saida = self.cli("desfazer", "--confirmar")
        self.assertIn("não apagada: divergente", saida)
        self.assertIn(data, self.sim.overrides[AFRODITE])
        self.assertEqual(len(self.nossas()), 1)
        r = self.rodar(datetime(2026, 9, 26, 8, 30, tzinfo=BRT))
        self.assertIn("alterada por outra pessoa", " ".join(r["alertas"]))
        self.assertIn(data, self.sim.overrides[AFRODITE])
        self.assertEqual(self.sim.overrides[AFRODITE]["2026-09-29"]["reason"], "Vespera manual")

    def test_desfazer_so_remove_o_do_programa(self):
        self.rodar()
        self.assertEqual(len(self.nossas()), 3)
        codigo, saida = self.cli("desfazer")
        self.assertIn("Nada foi alterado", saida)
        self.assertEqual(len(self.nossas()), 3)
        codigo, saida = self.cli("desfazer", "--confirmar")
        self.assertEqual(self.nossas(), {})
        self.assertIn("2026-09-29", self.sim.overrides[AFRODITE])
        codigo, saida = self.cli("desfazer", "--confirmar")
        self.assertIn("Nenhum desconto", saida)

    def test_venda_remove_e_contencao_so_remove(self):
        self.rodar()
        data = sorted(self.nossas())[0]
        self.sim.reservas.append(reserva(AFRODITE, date.fromisoformat(data), reservada_em=self.agora + timedelta(hours=2)))
        r = self.rodar(datetime(2026, 9, 26, 8, 30, tzinfo=BRT))
        self.assertNotIn(data, self.nossas())
        self.assertTrue(any("a data vendeu" in a and "apagada" in a for a in r["acoes"]))
        est = self.estado()
        est["disjuntor"] = {"ativo": True, "motivo": "teste", "desde": "x"}
        Armazem(self.dados).salvar(est)
        self.sim.cal[AFRODITE][date(2026, 9, 27)]["preco"] = 1400.0
        antes = len(self.posts())
        r = self.rodar(datetime(2026, 9, 26, 23, 30, tzinfo=BRT))
        self.assertEqual(r["modo"], "contencao")
        self.assertEqual(len(self.posts()), antes)

    def test_estado_perdido_reconstroi_e_fica_em_contencao(self):
        self.rodar()
        (self.dados / "estado.json").unlink()
        (self.dados / "estado.json.bak").unlink(missing_ok=True)
        antes = len(self.posts())
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        self.assertEqual(r["modo"], "contencao")
        self.assertEqual(len(self.posts()), antes)
        est = self.estado()
        self.assertEqual(len(est["dsos"]), 3)
        self.assertEqual(est["contadores"], {"dia": "2026-09-25", "criadas": 3})
        self.assertEqual(len(est["descontadas"]), 3)
        self.assertIn("recuperado", " ".join(r["alertas"]))
        r = self.rodar(datetime(2026, 9, 26, 23, 30, tzinfo=BRT))
        self.assertEqual(r["modo"], "contencao", "continua em contenção até RETOMAR")
        self.cli("retomar")
        self.assertEqual(self.rodar(datetime(2026, 9, 27, 5, 30, tzinfo=BRT))["modo"], "ativo")

    def test_canario_nao_aparece_em_arquivo_nenhum(self):
        self.rodar()
        self.cli("desfazer", "--confirmar")
        self.assertIn(CANARIO_PL, json.dumps([c[3] for c in self.sim.chamadas]))
        for arq in self.tmp.rglob("*"):
            if arq.is_file():
                conteudo = arq.read_bytes().decode("utf-8", "ignore") + arq.read_bytes().decode("utf-16", "ignore")
                self.assertNotIn(CANARIO_PL, conteudo, arq)
                self.assertNotIn(CANARIO_OR, conteudo, arq)
                self.assertNotIn("Hidden", conteudo, arq)


class TestDiaSimulado(Base):
    modo = "ativo"

    def test_um_dia_com_sete_execucoes_e_pc_desligado(self):
        dia = lambda d, h, m: datetime(2026, 9, d, h, m, tzinfo=BRT)  # noqa: E731
        horarios = [(5, 30), (8, 30), (11, 30), (14, 30), (17, 30), (20, 30), (23, 30)]
        self.rodar(dia(25, 23, 30))
        jev_por_execucao = []
        for h, m in horarios:
            antes = len(self.sim.chamadas_jev())
            self.rodar(dia(26, h, m))
            jev_por_execucao.append(len(self.sim.chamadas_jev()) - antes)
        self.assertEqual(jev_por_execucao, [1, 0, 0, 0, 0, 0, 1], "Jev só nas janelas antes da sincronização")
        criadas = [c[2]["overrides"][0]["date"] for c in self.posts()]
        self.assertEqual(criadas, ["2026-09-26", "2026-09-27", "2026-09-28"])
        self.rodar(dia(27, 5, 30))
        self.assertEqual([c[2]["overrides"][0]["date"] for c in self.posts()][3:], ["2026-09-30", "2026-10-01", "2026-10-02"])
        # computador desligado até 29/09 10:00 (execução atrasada, fora da janela)
        antes_posts = len(self.posts())
        r = self.rodar(dia(29, 10, 0))
        self.assertEqual(len(self.posts()), antes_posts, "execução atrasada não cria")
        restantes = sorted(self.nossas())
        self.assertNotIn("2026-09-30", restantes, "vencida removida logo ao voltar")
        est = self.estado()
        self.assertTrue(all(d["data"] >= "2026-09-29" for d in est["dsos"].values()))
        deletes = [c for c in self.sim.escritas() if c[0] == "DELETE"]
        self.assertTrue(all(c[2]["overrides"][0]["date"] >= "2026-09-29" for c in deletes), "datas passadas não geram DELETE")
        self.assertIn("2026-09-29", self.sim.overrides[AFRODITE], "substituição humana intacta")


class TestComandos(Base):
    def test_parar_retomar_modo(self):
        self.assertEqual(self.cli("parar")[0], 0)
        self.assertTrue((self.dados / "PARAR").exists())
        est = self.estado()
        est["disjuntor"] = {"ativo": True, "motivo": "x", "desde": "y"}
        Armazem(self.dados).salvar(est)
        self.assertEqual(self.cli("retomar")[0], 0)
        self.assertFalse((self.dados / "PARAR").exists())
        self.assertFalse(self.estado()["disjuntor"]["ativo"])
        self.assertEqual(self.cli("modo", "ativo")[0], 0)
        self.assertEqual(json.loads(self.cfg_path.read_text(encoding="utf-8"))["modo"], "ativo")
        self.assertEqual(self.cli("executar")[0], 0)

    def test_verificar(self):
        (self.mesa / "PRECOS de Natal.txt").write_text("do dono")
        codigo, saida = self.cli("verificar", "--silencioso")
        self.assertEqual(codigo, 1)
        self.assertEqual(len(list(self.mesa.glob("PRECOS ATRASADO *.txt"))), 1)
        self.assertTrue((self.mesa / "PRECOS de Natal.txt").exists(), "não apaga arquivo do dono")
        self.assertIn("Área de Trabalho para avisos", saida)
        self.rodar()
        with mock.patch("time.localtime", return_value=mock.Mock(tm_gmtoff=-3 * 3600)):
            codigo, saida = self.cli("verificar", "--sem-autoteste")
        self.assertIn("OK    fuso do computador UTC-3", saida)
        self.assertIn("Moinhos Afrodite (1): sincronização ligada", saida)
        self.assertIn("leitura de reservas", saida)
        self.assertIn("OK    Jev", saida)
        self.assertNotIn(CANARIO_PL, saida)
        with mock.patch("time.localtime", return_value=mock.Mock(tm_gmtoff=0)):
            codigo, saida = self.cli("verificar", "--sem-autoteste")
        self.assertIn("FALHA fuso do computador UTC+0", saida)
        with mock.patch("time.localtime", return_value=mock.Mock(tm_gmtoff=0)):
            self.cli("verificar", "--silencioso")
        aviso = list(self.mesa.glob("PRECOS ATENCAO *.txt"))
        self.assertEqual(len(aviso), 1, "fuso errado aparece na Área de Trabalho")
        self.assertIn("fuso", aviso[0].read_text(encoding="utf-8"))
        self.amb.plataforma = "win32"
        with mock.patch("time.localtime", return_value=mock.Mock(tm_gmtoff=-3 * 3600)):
            codigo, saida = self.cli("verificar", "--sem-autoteste")
        self.assertIn("tarefa agendada RecantoPrecos-Verificar", saida)
        self.amb.plataforma = "linux"
        self.sim.jev_status = 401
        codigo, saida = self.cli("verificar", "--sem-autoteste")
        self.assertIn("FALHA Jev", saida)

    def test_testar_gravacao(self):
        self.assertEqual(self.cli("testar-gravacao", "--listing", AFRODITE, "--data", "2026-10-05")[0], 2)
        codigo, saida = self.cli("testar-gravacao", "--listing", AFRODITE, "--data", "2026-10-05", "--confirmar")
        self.assertIn("Gravar +0%: criada", saida)
        self.assertIn("Remover: apagada", saida)
        self.assertIn("last_date_pushed antes", saida)
        self.assertEqual(self.nossas(), {})
        codigo, saida = self.cli("testar-gravacao", "--listing", AFRODITE, "--data", "2026-09-29", "--confirmar")
        self.assertIn("pulado: a data já tem substituição", saida)

    def test_agendar_e_chaves(self):
        codigo, saida = self.cli("agendar")
        self.assertEqual(saida.count("-m pacing executar"), 7)
        self.assertEqual(self.comandos, [])
        self.amb.plataforma = "win32"
        with mock.patch.dict("os.environ", {"USERDOMAIN": "PC", "USERNAME": "André"}):
            codigo, saida = self.cli("agendar")
        self.assertEqual(codigo, 0)
        self.assertEqual([c[3] for c in self.comandos], ["RecantoPrecos", "RecantoPrecos-Verificar"])
        self.retorno_comando = 1
        codigo, saida = self.cli("agendar")
        self.assertEqual(codigo, 1)
        self.assertIn("FALHA", saida)
        self.amb.plataforma = "linux"
        with mock.patch("getpass.getpass", side_effect=["nova-chave-pl", ""]):
            self.cli("configurar-chaves")
        self.assertEqual(self.cofre.dados, {"automacao-pricelabs/pricelabs": "nova-chave-pl"})
        self.cfg_path.write_text("{}", encoding="utf-8")
        self.assertEqual(self.cli("executar")[0], 2)


if __name__ == "__main__":
    unittest.main()


class TestExecutorCaminhosDeFalha(unittest.TestCase):
    def setUp(self):
        from pacing.acoes import Executor
        from pacing.estado import estado_vazio
        from pacing.pricelabs import PriceLabs
        from pacing.rede import Rede
        self.tmp = Path(tempfile.mkdtemp())
        self.agora = datetime(2026, 9, 26, 5, 30, tzinfo=BRT)
        self.sim = Simulador(self.agora)
        self.pl = PriceLabs(Rede(self.sim, frozenset({"POST", "DELETE"}), espaco_s=0, dormir=lambda s: None), "k", "beds24",
                           list(self.sim.overrides))
        self.est = estado_vazio()
        self.parar = False
        self.ex = Executor(self.pl, Armazem(self.tmp), self.est, "r1", self.agora, parar=lambda: self.parar)
        self.acao = {"listing": AFRODITE, "data": "2026-09-27", "motivo": "teste",
                     "payload": {"date": "2026-09-27", "price": "-10", "price_type": "percent", "reason": "auto-jev r1"}}

    def test_bloqueios_antes_de_escrever(self):
        self.parar = True
        self.assertIn("PARAR", self.ex.criar(self.acao))
        self.parar = False
        self.ex.disjuntor = "x"
        self.assertIn("disjuntor", self.ex.criar(self.acao))
        self.ex.disjuntor = ""
        self.sim.falhas += [("GET", "/overrides", 500)] * 3
        self.assertIn("releitura falhou", self.ex.criar(self.acao))
        self.assertEqual(self.sim.escritas(), [])
        self.assertEqual(self.ex.apagar(self.acao), "ignorado: não é do programa")

    def test_post_aceito_mas_ausente_ou_diferente(self):
        self.sim.ignorar_post = True
        self.assertIn("disjuntor", self.ex.criar(self.acao))
        self.assertEqual(self.est["dsos"][f"{AFRODITE}|2026-09-27"]["status"], "incerta", "continua rastreada")
        self.assertTrue(self.ex.disjuntor)
        self.assertTrue(self.est["disjuntor"]["ativo"])
        del self.est["dsos"][f"{AFRODITE}|2026-09-27"]
        self.ex.disjuntor = ""
        self.sim.ignorar_post = False
        self.sim.override_extras = {"min_stay": 1}   # a API acrescenta um padrão: continua nosso e removível
        self.assertIn("campos extras", self.ex.criar(self.acao))
        self.assertEqual(self.est["dsos"][f"{AFRODITE}|2026-09-27"]["status"], "ativa")
        self.assertTrue(self.est["disjuntor"]["ativo"])
        self.assertEqual(self.ex.apagar(self.acao), "apagada")
        self.ex.disjuntor = ""
        self.sim.override_extras = {}
        self.sim.mutar_post = lambda o: dict(o, price="-20")
        self.assertIn("disjuntor", self.ex.criar(self.acao))
        self.assertEqual(self.est["dsos"][f"{AFRODITE}|2026-09-27"]["status"], "divergente")
        self.assertIn("não apagada", self.ex.apagar(self.acao))

    def test_releitura_depois_do_post_falha(self):
        self.sim.falhas += [("GET", "/overrides", 200)]  # primeira leitura passa (lista vazia simulada abaixo)
        self.sim.falhas.clear()
        original = self.pl.substituicoes
        chamadas = []

        def lendo(*a):
            chamadas.append(a)
            if len(chamadas) == 2:
                from pacing.rede import ErroRede
                raise ErroRede("queda")
            return original(*a)
        self.pl.substituicoes = lendo
        self.assertIn("releitura falhou", self.ex.criar(self.acao))
        self.assertEqual(self.est["dsos"][f"{AFRODITE}|2026-09-27"]["status"], "incerta")

    def test_conciliacao_divergente_e_adiada(self):
        chave = f"{AFRODITE}|2026-09-27"
        self.est["dsos"][chave] = {"listing": AFRODITE, "data": "2026-09-27", "payload": self.acao["payload"],
                                   "status": "incerta", "criado_em": self.agora.isoformat(), "lido": None}
        self.sim.overrides[AFRODITE]["2026-09-27"] = {**self.acao["payload"], "price": "-20"}
        self.ex.conciliar()
        self.assertEqual(self.est["dsos"][chave]["status"], "divergente")
        self.assertTrue(self.ex.alertas)
        self.est["dsos"][chave]["status"] = "pendente"
        self.sim.falhas += [("GET", "/overrides", 500)] * 3
        self.assertIn("adiada", self.ex.conciliar()[0])

    def test_apagar_falhas(self):
        self.assertEqual(self.ex.criar(self.acao), "criada")
        self.sim.falhas += [("GET", "/overrides", 500)] * 3
        self.assertIn("adiado", self.ex.apagar(self.acao))
        self.sim.falhas += [("DELETE", "/overrides", 500)]
        self.assertIn("falhou", self.ex.apagar(self.acao))
        self.parar = True
        self.assertIn("PARAR", self.ex.apagar(self.acao))
        self.parar = False
        original = self.sim._overrides

        def delete_ignorado(metodo, lid, consulta, dados):
            if metodo == "DELETE":
                return 204, {}, b""
            return original(metodo, lid, consulta, dados)
        self.sim._overrides = delete_ignorado
        self.assertIn("continua na conta", self.ex.apagar(self.acao))
        self.assertTrue(self.ex.disjuntor, "dois erros de escrita seguidos")
        self.sim._overrides = original
        del self.sim.overrides[AFRODITE]["2026-09-27"]
        self.assertEqual(self.ex.apagar(self.acao), "já ausente")

    def test_normalizacao(self):
        from pacing.acoes import corresponde, identica, normalizar
        n = normalizar({"date": "2026-09-27", "price": "-10.0", "price_type": "percent", "min_stay": None,
                        "min_price": "0", "lead_time_expiry": -1, "currency": "BRL", "check_in_check_out_enabled": "0"})
        self.assertEqual(n["extras"], {})
        self.assertTrue(corresponde(n, self.acao["payload"]))
        self.assertFalse(identica(n, None))
        self.assertTrue(identica(n, dict(n, reason="auto-jev r1")))
        self.assertFalse(identica(dict(n, reason="outro"), dict(n, reason="auto-jev r1")))
        self.assertEqual(normalizar({"check_in_check_out_enabled": "1", "price": "x"})["extras"], {"check_in_check_out_enabled": "1"})
        self.assertIsNone(normalizar({"price": float("inf")})["price"])


class TestRelatorio(unittest.TestCase):
    def test_area_de_trabalho_e_escape(self):
        from pacing import relatorio
        tmp = Path(tempfile.mkdtemp(prefix="Usuário "))
        (tmp / "Desktop").mkdir()
        with mock.patch.dict("os.environ", {"USERPROFILE": str(tmp), "OneDrive": ""}), \
                mock.patch("pacing.relatorio._pasta_shell", return_value=None):
            self.assertEqual(relatorio.area_de_trabalho({}), tmp / "Desktop")
        onedrive = Path(tempfile.mkdtemp(prefix="OneDrive "))
        (onedrive / "Área de Trabalho").mkdir()
        with mock.patch.dict("os.environ", {"USERPROFILE": str(tmp), "OneDrive": str(onedrive)}), \
                mock.patch("pacing.relatorio._pasta_shell", return_value=None):
            self.assertEqual(relatorio.area_de_trabalho({}), onedrive / "Área de Trabalho", "OneDrive tem prioridade")
        vazio = Path(tempfile.mkdtemp())
        with mock.patch.dict("os.environ", {"USERPROFILE": str(vazio), "OneDrive": ""}), \
                mock.patch("pathlib.Path.home", return_value=vazio), mock.patch("pacing.relatorio._pasta_shell", return_value=None):
            self.assertIsNone(relatorio.area_de_trabalho({}))
            r = {"status": "verde", "resumo": "<script>x</script>", "run_id": "r", "hora": "h", "hora_curta": "01-01 00h00",
                 "modo": "observar", "proxima_sync": "", "alertas": [], "ocupacao": [], "decisoes": [], "acoes": [],
                 "ativas": [], "bloqueios": [], "precisa_atencao": False,
                 "saude": {"execucoes_hoje": 1, "jev_chamadas_hoje": 0, "jev_tokens": 0, "observacao": ""}}
            cfg = json.loads((RAIZ / "config.json").read_text(encoding="utf-8"))
            relatorio.publicar(r, cfg, vazio)
        html = (vazio / "relatorio.html").read_text(encoding="utf-8")
        self.assertNotIn("<script>x", html)
        self.assertIn("&lt;script&gt;", html)


class TestRegressaoAuditoriaA(Base):
    modo = "ativo"
    confirmar = False

    def test_h1_desfazer_nao_apaga_humana_depois_de_post_incerto(self):
        self.sim.falhas.append(("POST", "/overrides", 503))  # nada gravado, resultado incerto
        self.rodar()
        data = "2026-09-26"
        self.assertEqual(self.estado()["dsos"][f"{AFRODITE}|{data}"]["status"], "incerta")
        humana = {"date": data, "price": "20", "price_type": "percent", "reason": "dono"}
        self.sim.overrides[AFRODITE][data] = dict(humana)
        codigo, saida = self.cli("desfazer", "--confirmar")
        self.assertEqual(self.sim.overrides[AFRODITE][data]["reason"], "dono")
        self.assertIn("não confere", saida)

    def test_h1b_edicao_humana_sobrevive_a_perda_de_estado(self):
        self.rodar()
        data = sorted(self.nossas())[-1]
        self.sim.overrides[AFRODITE][data]["min_stay"] = 3
        self.cli("desfazer", "--confirmar")
        (self.dados / "estado.json").unlink()
        (self.dados / "estado.json.bak").unlink(missing_ok=True)
        self.cli("desfazer", "--confirmar")
        self.assertIn(data, self.sim.overrides[AFRODITE])
        self.assertEqual(self.sim.overrides[AFRODITE][data]["min_stay"], 3)

    def test_h2_disjuntor_sobrevive_a_estado_corrompido(self):
        self.sim.quebrar_resposta_post = True
        self.rodar()
        self.assertTrue(self.estado()["disjuntor"]["ativo"])
        (self.dados / "estado.json").write_bytes(b"{corrompido")
        (self.dados / "estado.json.bak").write_bytes(b"{corrompido")
        self.sim.quebrar_resposta_post = False
        for quando in (datetime(2026, 9, 26, 5, 30, tzinfo=BRT), datetime(2026, 9, 26, 23, 30, tzinfo=BRT)):
            self.assertEqual(self.rodar(quando)["modo"], "contencao")
        self.assertEqual(len(self.posts()), 2)

    def test_h3_utf8_truncado_nao_derruba(self):
        self.rodar()
        (self.dados / "estado.json").write_bytes('{"versao": 1, "x": "\u00e7'.encode() + b"\xc3")
        (self.dados / "estado.json.bak").write_bytes(b"\xff\xfe")
        with open(self.dados / "diario-escritas.jsonl", "ab") as f:
            f.write(b"\xc3\x28 lixo\n")
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        self.assertEqual(r["modo"], "contencao")
        self.assertEqual(len(self.estado()["dsos"]), 3)
        with mock.patch("time.localtime", return_value=mock.Mock(tm_gmtoff=-3 * 3600)):
            codigo, saida = self.cli("verificar", "--silencioso")
        self.assertIn("última execução", saida)

    def test_h4_excecao_inesperada_sai_vermelha(self):
        import http.client
        from pacing.rede import ErroRede, transporte_urllib
        with mock.patch("pacing.rede._ABRIDOR.open", side_effect=http.client.IncompleteRead(b"x")):
            with self.assertRaises(ErroRede):
                transporte_urllib("GET", "https://api.pricelabs.co/v1/listings", {}, None, 5)
        original = self.sim.__call__

        def quebra(*a):
            if "/listing_metrics" in a[1]:
                raise RuntimeError("inesperado")
            return original(*a)
        self.amb.transporte = quebra
        r = self.rodar()
        self.assertEqual(r["status"], "vermelho")
        self.assertEqual(list(self.mesa.glob("PRECOS OK *.txt")), [])
        self.assertTrue((self.mesa / "ATENCAO-PRECOS.txt").exists())

    def test_h5_data_em_outro_formato_conta_como_existente(self):
        self.sim.overrides[AFRODITE]["2026-09-26"] = {"date": "26/09/2026", "min_stay": 2, "reason": "dono"}
        r = self.rodar()
        self.assertEqual(self.posts(), [])
        self.assertIn("formato desconhecido", " ".join(r["alertas"]))

    def test_h6_ensaio_respeita_parar_disjuntor_e_papel(self):
        balcony = "350364___722814"
        self.assertEqual(self.cli("testar-gravacao", "--listing", balcony, "--data", "2026-10-05", "--confirmar")[0], 2)
        self.cli("parar")
        self.assertEqual(self.cli("testar-gravacao", "--listing", AFRODITE, "--data", "2026-10-05", "--confirmar")[0], 2)
        self.cli("retomar")
        est = self.estado()
        est["disjuntor"] = {"ativo": True, "motivo": "x", "desde": "y"}
        Armazem(self.dados).salvar(est)
        self.assertEqual(self.cli("testar-gravacao", "--listing", AFRODITE, "--data", "2026-10-05", "--confirmar")[0], 2)
        self.cli("retomar")
        self.cli("testar-gravacao", "--listing", AFRODITE, "--data", "2026-10-05", "--confirmar")
        self.assertEqual(self.estado()["contadores"]["criadas"], 0)
        self.assertEqual(len(self.posts()), 1)

    def test_h7_divergentes_ocupam_vagas_e_conciliacao_divergente_aciona_disjuntor(self):
        self.sim.quebrar_resposta_post = True
        self.sim.falhas.append(("POST", "/overrides", 503))
        self.rodar()
        chave = next(k for k, v in self.estado()["dsos"].items() if v["status"] == "incerta" and k.endswith("2026-09-27"))
        self.sim.overrides[AFRODITE]["2026-09-27"]["price"] = "-20"
        self.sim.quebrar_resposta_post = False
        self.cli("retomar")
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        est = self.estado()
        self.assertEqual(est["dsos"][chave]["status"], "divergente")
        self.assertTrue(est["disjuntor"]["ativo"])
        self.assertIsNone(est["dsos"][chave].get("lido"))


class TestRegressaoAuditoriaB(Base):
    def test_alertas_graves_deixam_amarelo(self):
        for lid in self.sim.push:
            self.sim.push[lid] = False
        r = self.rodar()
        self.assertEqual(r["status"], "amarelo")
        self.assertEqual(list(self.mesa.glob("PRECOS OK *.txt")), [])

    def test_historico_com_colunas_fixas(self):
        self.variaveis.clear()
        self.rodar()
        self.variaveis.update(PRICELABS_API_KEY=CANARIO_PL, OPENROUTER_API_KEY=CANARIO_OR)
        self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        linhas = (self.dados / "historico.csv").read_text(encoding="utf-8-sig").splitlines()
        self.assertEqual({len(l.split(";")) for l in linhas}, {13})
        self.assertIn("ocup_0_6_R3", linhas[0])


class TestReauditoriaA(Base):
    modo = "ativo"
    confirmar = False

    def test_n1_contencao_de_recuperacao_vale_para_todo_comando(self):
        self.rodar()
        (self.dados / "estado.json").unlink()
        (self.dados / "estado.json.bak").unlink(missing_ok=True)
        self.cli("desfazer")  # sem --confirmar: só carrega e concilia
        self.assertTrue(self.estado()["disjuntor"]["ativo"])
        self.assertEqual(self.cli("testar-gravacao", "--listing", AFRODITE, "--data", "2026-10-05", "--confirmar")[0], 2)
        r = self.rodar(datetime(2026, 9, 26, 5, 30, tzinfo=BRT))
        self.assertEqual(r["modo"], "contencao")
        self.assertIn("recuperado", " ".join(r["alertas"]))

    def test_n4_campo_extra_da_api_ainda_sai_pelo_desfazer(self):
        self.sim.override_extras = {"min_stay": 1}
        self.rodar()
        self.assertEqual(len(self.nossas()), 1, "o disjuntor para depois da primeira")
        codigo, saida = self.cli("desfazer", "--confirmar")
        self.assertEqual(self.nossas(), {})


class TestReauditoriaB(Base):
    def test_n2_historico_travado_nao_esconde_o_status(self):
        (self.dados).mkdir(parents=True, exist_ok=True)
        (self.dados / "historico.csv").mkdir()   # simula o arquivo travado pelo Excel
        r = self.rodar()
        self.assertEqual(r["status"], "amarelo")
        self.assertIn("historico.csv", " ".join(r["alertas"]))
        self.assertEqual(len(list(self.mesa.glob("PRECOS ATENCAO *.txt"))), 1)

    def test_erro_inesperado_grava_log_tecnico(self):
        def quebra(*a):
            raise RuntimeError("falha interna")
        self.amb.transporte = quebra
        r = self.rodar()
        self.assertEqual(r["status"], "vermelho")
        self.assertIn("RuntimeError", (self.dados / "erros.log").read_text(encoding="utf-8"))

    def test_n1_sincronizacao_so_com_data_nao_gera_alerta_falso(self):
        original = self.sim._listing
        self.sim._listing = lambda lid: dict(original(lid), last_date_pushed="2026-09-26")
        r = self.rodar(datetime(2026, 9, 27, 5, 30, tzinfo=BRT))
        self.assertNotIn("mais de 30 h", " ".join(r["alertas"]))

    def test_descontos_ativos_em_observar_deixam_amarelo(self):
        est = self.estado()
        est["dsos"]["x"] = {"listing": AFRODITE, "data": "2026-09-27", "status": "ativa", "criado_em": "",
                            "payload": {"price": "-10"}}
        Armazem(self.dados).salvar(est)
        r = self.rodar()
        self.assertEqual(r["status"], "amarelo")

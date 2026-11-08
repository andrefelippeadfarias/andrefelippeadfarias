"""Testes da cota de recálculo do PriceLabs (cota_recalculo.py). Rodar na pasta scripts/:
python3 -m unittest test_cota_recalculo

Relógio injetado (--agora / agora=) e registro sintético em pasta temporária criada pelo próprio teste: nada de rede,
nada de dados/recalculos.json real.
"""

from __future__ import annotations

import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))
import cota_recalculo as cota  # noqa: E402
from comum import carregar_quartos  # noqa: E402

CFG = carregar_quartos()
TZ = cota.fuso(CFG)
BEDS24_FORA = "400 Beds24 appears to be temporarily unavailable or under high load"


def T(texto: str) -> datetime:
    return datetime.fromisoformat(texto.replace("Z", "+00:00"))


class BaseCota(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.pasta = Path(self._tmp.name)
        self.arq = self.pasta / "recalculos.json"

    def tearDown(self):
        self._tmp.cleanup()

    def reg(self, quarto: str, resultado: str, em: str, erro: str | None = None):
        return cota.registrar(quarto, resultado, em=em, erro=erro, caminho=self.arq, cfg=CFG,
                              agora=T(em) + timedelta(minutes=1))

    def sit(self, quarto: str, agora: str, rodada: str | None = None) -> dict:
        return cota.situacao(cota.carregar(self.arq)["tentativas"], quarto, T(agora), T(rodada) if rodada else None,
                             tz=TZ)

    def cli(self, *args) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            codigo = cota.main([*args, "--arquivo", str(self.arq)])
        return codigo, out.getvalue(), err.getvalue()


class TestJanelaDoQuarto(BaseCota):
    def test_janela_movel_exata(self):
        # 3 tentativas da Queen 7: a vaga abre 24 h depois da mais antiga das 3, nem um segundo antes
        for em in ("2026-11-07T10:55:00Z", "2026-11-07T13:00:00Z", "2026-11-07T20:00:00Z"):
            self.reg("Q7", "ok", em)
        s = self.sit("Q7", "2026-11-07T22:00:00Z")
        self.assertFalse(s["pode"])
        self.assertEqual((s["usados"], s["restam"]), (3, 0))
        self.assertEqual(s["vaga_quarto"], T("2026-11-08T10:55:00Z"))
        self.assertIn("cota do quarto esgotada", s["motivos"][0])
        self.assertFalse(self.sit("Q7", "2026-11-08T10:54:59Z")["pode"])
        s = self.sit("Q7", "2026-11-08T10:55:00Z")
        self.assertTrue(s["pode"])
        self.assertEqual((s["usados"], s["restam"]), (2, 1))
        # o outro quarto não é afetado pela cota da Queen 7
        self.assertEqual(self.sit("Double", "2026-11-07T22:00:00Z")["usados"], 0)

    def test_quarta_tentativa_na_janela_abre_pela_segunda_mais_antiga(self):
        # 4 na janela (ex.: um 429 depois das 3): a vaga abre quando sobram 2, isto é, 24 h depois da 2ª
        for em, r in (("2026-11-07T10:00:00Z", "ok"), ("2026-11-07T11:00:00Z", "ok"),
                      ("2026-11-07T12:00:00Z", "ok"), ("2026-11-07T15:00:00Z", "429")):
            self.reg("VK7", r, em)
        s = self.sit("VK7", "2026-11-07T23:00:00Z")
        self.assertEqual(s["usados"], 4)
        self.assertEqual(s["vaga_quarto"], T("2026-11-08T11:00:00Z"))

    def test_falha_e_429_contam(self):
        # Balcony em 06/11: 1 recálculo + 2 falhas e a 4ª deu 429; aqui ok + falha + 429 já esgotam a cota
        self.reg("Balcony", "ok", "2026-11-07T10:55:00Z")
        self.reg("Balcony", "falha", "2026-11-07T11:00:00Z", BEDS24_FORA)
        self.reg("Balcony", "429", "2026-11-07T14:00:00Z")
        s = self.sit("Balcony", "2026-11-07T20:00:00Z")
        self.assertFalse(s["pode"])
        self.assertEqual(s["por_resultado"], {"ok": 1, "falha": 1, "429": 1})
        self.assertEqual(s["vaga_quarto"], T("2026-11-08T10:55:00Z"))

    def test_tentativa_depois_do_relogio_nao_conta(self):
        self.reg("Q2", "ok", "2026-11-07T12:00:00Z")
        self.assertEqual(self.sit("Q2", "2026-11-07T11:00:00Z")["usados"], 0)


class TestTravaDaRodada(BaseCota):
    def test_falha_beds24_barra_ate_a_proxima_rodada(self):
        self.reg("Double", "falha", "2026-11-07T10:56:00Z", BEDS24_FORA)
        s = self.sit("Double", "2026-11-07T11:05:00Z", rodada="2026-11-07T10:53:00Z")
        self.assertFalse(s["pode"])
        self.assertEqual(s["usados"], 1)  # não é a cota: é a trava da rodada
        self.assertIn("Beds24 instável", s["motivos"][0])
        self.assertIn("próxima rodada", s["motivos"][0])
        # sem --rodada-desde, a rodada começa na última rotina agendada (07:53 BRT = 10:53 UTC)
        self.assertFalse(self.sit("Double", "2026-11-07T11:05:00Z")["pode"])
        # rotina das 10:53 BRT (13:53 UTC): rodada nova, pode tentar de novo
        s = self.sit("Double", "2026-11-07T14:00:00Z")
        self.assertTrue(s["pode"])
        self.assertEqual(s["rodada_desde"], T("2026-11-07T13:53:00Z"))

    def test_429_na_rodada_espera_e_nao_insiste(self):
        self.reg("VK7", "429", "2026-11-07T10:57:00Z", "You have fired too many requests")
        s = self.sit("VK7", "2026-11-07T11:00:00Z", rodada="2026-11-07T10:53:00Z")
        self.assertFalse(s["pode"])
        self.assertIn("429", s["motivos"][0])
        self.assertIn("não insista", s["motivos"][0])

    def test_falha_sem_motivo_conhecido(self):
        self.reg("VK2", "falha", "2026-11-07T10:57:00Z")
        s = self.sit("VK2", "2026-11-07T11:00:00Z", rodada="2026-11-07T10:53:00Z")
        self.assertFalse(s["pode"])
        self.assertIn("sem saber o motivo", s["motivos"][0])

    def test_falha_antes_da_rodada_nao_barra(self):
        self.reg("Afrodite", "falha", "2026-11-07T10:57:00Z", BEDS24_FORA)
        s = self.sit("Afrodite", "2026-11-07T14:00:00Z", rodada="2026-11-07T13:53:00Z")
        self.assertTrue(s["pode"])
        self.assertEqual(s["usados"], 1)

    def test_ok_na_rodada_nao_barra(self):
        self.reg("Q2", "ok", "2026-11-07T10:56:00Z")
        s = self.sit("Q2", "2026-11-07T11:00:00Z", rodada="2026-11-07T10:53:00Z")
        self.assertTrue(s["pode"])
        self.assertEqual(len(s["na_rodada"]), 1)

    def test_inicio_rodada_padrao(self):
        # 06:00 BRT: a última rotina foi a das 19:53 BRT da véspera
        self.assertEqual(cota.inicio_rodada(T("2026-11-08T09:00:00Z"), TZ), T("2026-11-07T22:53:00Z"))
        self.assertEqual(cota.inicio_rodada(T("2026-11-08T10:53:00Z"), TZ), T("2026-11-08T10:53:00Z"))
        self.assertEqual(cota.inicio_rodada(T("2026-11-08T16:00:00Z"), TZ), T("2026-11-08T13:53:00Z"))


class TestContaPorMinuto(BaseCota):
    def test_dez_por_minuto_na_conta(self):
        quartos = [q["curto"] for q in CFG["quartos"]]
        base = T("2026-11-07T11:00:00Z")
        for i in range(10):
            em = (base + timedelta(seconds=5 * i)).strftime("%Y-%m-%dT%H:%M:%SZ")
            self.reg(quartos[i % len(quartos)], "ok", em)
        # VK2 tem só 1 na janela, mas a conta já fez 10 no último minuto
        s = self.sit("VK2", "2026-11-07T11:00:50Z")
        self.assertFalse(s["pode"])
        self.assertEqual(s["usados"], 1)
        self.assertEqual(s["conta_minuto"], 10)
        self.assertEqual(s["vaga_conta"], T("2026-11-07T11:01:00Z"))
        self.assertIn("último minuto", s["motivos"][0])
        self.assertFalse(self.sit("VK2", "2026-11-07T11:00:59Z")["pode"])
        s = self.sit("VK2", "2026-11-07T11:01:00Z")
        self.assertTrue(s["pode"])
        self.assertEqual(s["conta_minuto"], 9)


class TestAvisoDeManha(BaseCota):
    def test_terceira_tentativa_tira_a_vaga_da_rotina_de_amanha(self):
        self.reg("Q7", "ok", "2026-11-07T12:00:00Z")  # 09:00 BRT
        self.reg("Q7", "ok", "2026-11-07T19:00:00Z")
        s = self.sit("Q7", "2026-11-07T22:56:00Z")  # 19:56 BRT: esta seria a 3ª
        self.assertTrue(s["pode"])  # não barra: só avisa
        self.assertIn("rotina completa de amanhã", s["aviso"])
        self.assertIn("sem vaga", s["aviso"])
        codigo, out, _ = self.cli("pode", "--quarto", "Q7", "--agora", "2026-11-07T22:56:00Z")
        self.assertEqual(codigo, 0)
        self.assertIn("AVISO", out)

    def test_sem_aviso_quando_a_vaga_reabre_antes_das_0753(self):
        self.reg("Q7", "ok", "2026-11-07T10:00:00Z")  # 07:00 BRT: sai da janela antes das 07:53 de amanhã
        self.reg("Q7", "ok", "2026-11-07T19:00:00Z")
        s = self.sit("Q7", "2026-11-07T22:56:00Z")
        self.assertTrue(s["pode"])
        self.assertEqual(s["aviso"], "")

    def test_sem_aviso_na_segunda_tentativa(self):
        self.reg("Q7", "ok", "2026-11-07T19:00:00Z")
        self.assertEqual(self.sit("Q7", "2026-11-07T22:56:00Z")["aviso"], "")

    def test_madrugada_avisa_a_rotina_de_hoje(self):
        self.reg("Double", "ok", "2026-11-07T11:00:00Z")
        self.reg("Double", "ok", "2026-11-07T20:00:00Z")
        s = self.sit("Double", "2026-11-08T08:00:00Z")  # 05:00 BRT: a próxima 07:53 é hoje
        self.assertTrue(s["pode"])
        self.assertIn("rotina completa de hoje", s["aviso"])


class TestArquivo(BaseCota):
    def test_arquivo_inexistente(self):
        self.assertEqual(cota.carregar(self.arq)["tentativas"], [])
        codigo, out, _ = self.cli("pode", "--quarto", "Q7", "--agora", "2026-11-07T22:00:00Z")
        self.assertEqual(codigo, 0)
        self.assertIn("não existe", out)
        self.assertFalse(self.arq.exists())  # consultar não cria o arquivo
        codigo, out, _ = self.cli("registrar", "--quarto", "Q7", "--resultado", "ok", "--em", "2026-11-07T10:55:12Z",
                                  "--agora", "2026-11-07T11:00:00Z")
        self.assertEqual(codigo, 0)
        d = json.loads(self.arq.read_text(encoding="utf-8"))
        self.assertEqual([(t["quarto"], t["em"], t["resultado"]) for t in d["tentativas"]],
                         [("Q7", "2026-11-07T10:55:12Z", "ok")])
        codigo, out, _ = self.cli("ver", "--agora", "2026-11-07T11:00:00Z")
        self.assertEqual(codigo, 0)
        self.assertIn("| Q7 | 1 (1 ok) | 2 | agora |", out)
        self.assertIn("| Double | 0 | 3 | agora | — |", out)

    def test_arquivo_corrompido_erro_claro_sem_apagar(self):
        lixo = '{"tentativas": [ {"quarto": "Q7", "em": "2026-11-07T10:55:12Z", '
        self.arq.write_text(lixo, encoding="utf-8")
        for args in (("registrar", "--quarto", "Q7", "--resultado", "ok", "--em", "2026-11-07T11:00:00Z",
                      "--agora", "2026-11-07T11:01:00Z"),
                     ("pode", "--quarto", "Q7", "--agora", "2026-11-07T11:05:00Z"),
                     ("ver", "--agora", "2026-11-07T11:05:00Z")):
            codigo, out, err = self.cli(*args)
            self.assertEqual(codigo, 2, args)
            self.assertIn("corrompido", err)
            self.assertIn("Nada foi apagado", err)
            self.assertEqual(self.arq.read_text(encoding="utf-8"), lixo)
        self.assertEqual(sorted(p.name for p in self.pasta.iterdir()), ["recalculos.json"])  # nem temporário sobrando

    def test_formato_invalido_e_corrompido(self):
        casos = ({"tentativas": [{"quarto": "Q7", "em": "ontem", "resultado": "ok"}]},
                 {"tentativas": [{"quarto": "Q7", "em": "2026-11-07T10:55:12Z", "resultado": "talvez"}]},
                 {"outra": []}, [])
        for d in casos:
            self.arq.write_text(json.dumps(d), encoding="utf-8")
            with self.assertRaises(cota.ErroCota) as ctx:
                cota.carregar(self.arq)
            self.assertIn("corrompido", str(ctx.exception))

    def test_gravacao_atomica_preserva_o_arquivo_se_a_escrita_falhar(self):
        self.reg("Q7", "ok", "2026-11-07T10:55:00Z")
        antes = self.arq.read_text(encoding="utf-8")
        with mock.patch.object(cota.json, "dump", side_effect=RuntimeError("disco cheio")):
            with self.assertRaises(RuntimeError):
                self.reg("Q7", "ok", "2026-11-07T13:00:00Z")
        self.assertEqual(self.arq.read_text(encoding="utf-8"), antes)
        self.assertEqual(sorted(p.name for p in self.pasta.iterdir()), ["recalculos.json"])

    def test_ordem_por_horario_e_registro_repetido_nao_conta_duas_vezes(self):
        self.reg("Q7", "ok", "2026-11-07T13:00:00Z")
        self.reg("Q7", "falha", "2026-11-07T10:55:00Z", BEDS24_FORA)  # registrada depois, aconteceu antes
        t, nova = self.reg("Q7", "ok", "2026-11-07T13:00:00Z")
        self.assertFalse(nova)
        ems = [t["em"] for t in cota.carregar(self.arq)["tentativas"]]
        self.assertEqual(ems, ["2026-11-07T10:55:00Z", "2026-11-07T13:00:00Z"])
        with self.assertRaises(cota.ErroCota):
            self.reg("Q7", "429", "2026-11-07T13:00:00Z")  # mesmo segundo com outro resultado: conflito


class TestRegistrar(BaseCota):
    def test_id_ms_vira_horario_em_utc_com_segundos(self):
        codigo, out, _ = self.cli("registrar", "--quarto", "VK7", "--resultado", "ok", "--id-ms", "1794135312345",
                                  "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(codigo, 0)
        self.assertIn("07:55:12 BRT", out)
        t = cota.carregar(self.arq)["tentativas"][0]
        self.assertEqual((t["em"], t["id_ms"]), ("2026-11-08T10:55:12Z", 1794135312345))
        # o mesmo id de novo não conta duas vezes
        self.cli("registrar", "--quarto", "VK7", "--resultado", "ok", "--id-ms", "1794135312345",
                 "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(len(cota.carregar(self.arq)["tentativas"]), 1)

    def test_id_ms_em_segundos_e_recusado(self):
        codigo, _, err = self.cli("registrar", "--quarto", "VK7", "--resultado", "ok", "--id-ms", "1794135312",
                                  "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(codigo, 2)
        self.assertIn("milissegundos", err)
        self.assertFalse(self.arq.exists())

    def test_em_com_fuso_e_fracao(self):
        self.reg("Q2", "ok", "2026-11-08T07:55:12.987-03:00")
        self.assertEqual(cota.carregar(self.arq)["tentativas"][0]["em"], "2026-11-08T10:55:12Z")

    def test_em_e_id_ms_juntos_e_recusado(self):
        with redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            cota.main(["registrar", "--quarto", "Q7", "--resultado", "ok", "--em", "2026-11-08T10:55:12Z",
                       "--id-ms", "1794135312345", "--arquivo", str(self.arq)])

    def test_quarto_desconhecido(self):
        codigo, _, err = self.cli("registrar", "--quarto", "Queen (7)", "--resultado", "ok",
                                  "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(codigo, 2)
        self.assertIn("quarto desconhecido", err)
        self.assertIn("Balcony", err)
        self.assertFalse(self.arq.exists())
        codigo, _, err = self.cli("pode", "--quarto", "q7", "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(codigo, 2)

    def test_resultado_invalido(self):
        with redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            cota.main(["registrar", "--quarto", "Q7", "--resultado", "400", "--arquivo", str(self.arq)])
        with self.assertRaises(cota.ErroCota):
            cota.registrar("Q7", "erro", caminho=self.arq, cfg=CFG, agora=T("2026-11-08T11:00:00Z"))

    def test_horario_no_futuro_e_recusado(self):
        codigo, _, err = self.cli("registrar", "--quarto", "Q7", "--resultado", "ok", "--em", "2026-11-18T10:55:00Z",
                                  "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(codigo, 2)
        self.assertIn("futuro", err)
        self.assertFalse(self.arq.exists())

    def test_erro_em_uma_linha_e_curto(self):
        self.reg("Afrodite", "falha", "2026-11-08T10:55:00Z", "linha 1\n  linha 2 " + "x" * 500)
        erro = cota.carregar(self.arq)["tentativas"][0]["erro"]
        self.assertTrue(erro.startswith("linha 1 linha 2 x"))
        self.assertNotIn("\n", erro)
        self.assertLessEqual(len(erro), cota.TAM_ERRO)


class TestIdMs(BaseCota):
    """O id do resultado da ferramenta MCP identifica a tentativa: o segundo sozinho não basta."""

    def registrar_id(self, quarto: str, resultado: str, id_ms: int, erro: str | None = None):
        return cota.registrar(quarto, resultado, erro=erro, id_ms=id_ms, caminho=self.arq, cfg=CFG,
                              agora=T("2026-11-08T11:00:00Z"))

    def test_ids_diferentes_no_mesmo_segundo_sao_duas_tentativas(self):
        # um 400 rápido seguido de um retry imediato: 2 respostas do MCP no mesmo segundo, ids diferentes
        _, n1 = self.registrar_id("Balcony", "falha", 1794135312100, BEDS24_FORA)
        _, n2 = self.registrar_id("Balcony", "falha", 1794135312900, BEDS24_FORA)
        self.assertEqual((n1, n2), (True, True))
        tentativas = cota.carregar(self.arq)["tentativas"]
        self.assertEqual([t["em"] for t in tentativas], ["2026-11-08T10:55:12Z"] * 2)
        self.assertEqual(self.sit("Balcony", "2026-11-08T11:00:00Z")["usados"], 2)
        # resultados diferentes no mesmo segundo, com ids diferentes, também são duas tentativas (não é conflito)
        _, n3 = self.registrar_id("Balcony", "429", 1794135312950)
        self.assertTrue(n3)
        self.assertEqual(self.sit("Balcony", "2026-11-08T11:00:00Z")["usados"], 3)

    def test_mesmo_id_e_repeticao_e_outro_resultado_e_conflito(self):
        self.registrar_id("VK2", "falha", 1794135312100, BEDS24_FORA)
        _, nova = self.registrar_id("VK2", "falha", 1794135312100, BEDS24_FORA)
        self.assertFalse(nova)
        with self.assertRaises(cota.ErroCota):
            self.registrar_id("VK2", "ok", 1794135312100)
        with self.assertRaises(cota.ErroCota) as ctx:
            self.registrar_id("Q2", "falha", 1794135312100)  # o mesmo id em outro quarto: engano de cópia
        self.assertIn("já está registrado para o VK2", str(ctx.exception))
        self.assertEqual(len(cota.carregar(self.arq)["tentativas"]), 1)

    def test_sem_id_ms_vale_quarto_e_segundo(self):
        # registrado uma vez pelo --em e outra pelo --id-ms do mesmo segundo: é a mesma tentativa
        self.reg("VK7", "ok", "2026-11-08T10:55:12Z")
        _, nova = self.registrar_id("VK7", "ok", 1794135312345)
        self.assertFalse(nova)
        self.assertEqual(len(cota.carregar(self.arq)["tentativas"]), 1)

    def test_id_ms_que_nao_e_inteiro_no_arquivo_e_corrompido(self):
        self.arq.write_text(json.dumps({"tentativas": [{"quarto": "Q7", "em": "2026-11-08T10:55:12Z", "resultado": "ok",
                                                        "id_ms": "1794135312345"}]}), encoding="utf-8")
        with self.assertRaises(cota.ErroCota) as ctx:
            cota.carregar(self.arq)
        self.assertIn("id_ms", str(ctx.exception))


class TestRelogioReal(BaseCota):
    """Sem --agora vale o relógio do computador (injetado aqui por cota.relogio)."""

    def cli_no_relogio(self, quando: str, *args) -> tuple[int, str, str]:
        with mock.patch.object(cota, "relogio", return_value=T(quando)):
            return self.cli(*args)

    def test_tentativa_com_relogio_do_mcp_adiantado_ja_conta(self):
        self.reg("Q7", "ok", "2026-11-08T08:00:00Z")
        self.reg("Q7", "ok", "2026-11-08T09:00:00Z")
        # a 3ª veio com o id do MCP 90 s à frente do relógio do computador: o registrar aceita (folga de 5 min)
        id_ms = str(int(T("2026-11-08T11:01:30Z").timestamp() * 1000))
        codigo, _, _ = self.cli_no_relogio("2026-11-08T11:00:00Z", "registrar", "--quarto", "Q7", "--resultado", "ok",
                                           "--id-ms", id_ms)
        self.assertEqual(codigo, 0)
        # 10 s depois, no relógio do computador, a 3ª já conta: a 4ª daria 429
        codigo, out, _ = self.cli_no_relogio("2026-11-08T11:00:10Z", "pode", "--quarto", "Q7")
        self.assertEqual(codigo, 1)
        self.assertIn("3 de 3 em 24 h", out)
        self.assertIn("cota do quarto esgotada", out)
        _, out, _ = self.cli_no_relogio("2026-11-08T11:00:10Z", "ver")
        self.assertIn("| Q7 | 3 (3 ok) | 0 |", out)
        # com --agora (repetição de um horário passado) a tentativa depois desse horário ainda não existe
        codigo, out, _ = self.cli("pode", "--quarto", "Q7", "--agora", "2026-11-08T11:00:10Z")
        self.assertEqual(codigo, 0)
        self.assertIn("2 de 3 em 24 h", out)

    def test_mais_de_5_min_no_futuro_nao_conta_nem_com_relogio_real(self):
        self.reg("Q2", "ok", "2026-11-08T12:00:00Z")
        codigo, out, _ = self.cli_no_relogio("2026-11-08T11:00:00Z", "pode", "--quarto", "Q2")
        self.assertEqual(codigo, 0)
        self.assertIn("0 de 3 em 24 h", out)


class TestFuso(BaseCota):
    """Horário sem fuso é recusado: a operação pensa em BRT e o registro é em UTC."""

    def test_em_sem_fuso_e_recusado_e_nada_e_gravado(self):
        codigo, _, err = self.cli("registrar", "--quarto", "Double", "--resultado", "falha", "--em",
                                  "2026-11-08T07:58:00", "--erro", BEDS24_FORA, "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(codigo, 2)
        self.assertIn("sem fuso", err)
        self.assertIn("-03:00", err)
        self.assertFalse(self.arq.exists())
        with self.assertRaises(cota.ErroCota):
            cota.registrar("Double", "falha", em="2026-11-08T07:58:00", caminho=self.arq, cfg=CFG,
                           agora=T("2026-11-08T11:00:00Z"))

    def test_rodada_desde_e_agora_sem_fuso_sao_recusados(self):
        codigo, _, err = self.cli("pode", "--quarto", "Double", "--agora", "2026-11-08T11:02:00Z",
                                  "--rodada-desde", "2026-11-08T07:53:00")
        self.assertEqual(codigo, 2)
        self.assertIn("--rodada-desde", err)
        codigo, _, err = self.cli("pode", "--quarto", "Double", "--agora", "2026-11-08T08:02:00")
        self.assertEqual(codigo, 2)
        self.assertIn("--agora", err)
        codigo, _, _ = self.cli("ver", "--agora", "2026-11-08T08:02:00")
        self.assertEqual(codigo, 2)

    def test_em_com_fuso_de_brasilia_barra_na_mesma_rodada(self):
        # a falha do Beds24 às 07:58 BRT, com o fuso escrito, cai na rodada das 07:53 e barra o pode das 08:02 BRT
        codigo, out, _ = self.cli("registrar", "--quarto", "Double", "--resultado", "falha", "--em",
                                  "2026-11-08T07:58:00-03:00", "--erro", BEDS24_FORA, "--agora", "2026-11-08T11:00:00Z")
        self.assertEqual(codigo, 0)
        self.assertIn("07:58:00 BRT", out)
        self.assertEqual(cota.carregar(self.arq)["tentativas"][0]["em"], "2026-11-08T10:58:00Z")
        codigo, out, _ = self.cli("pode", "--quarto", "Double", "--agora", "2026-11-08T08:02:00-03:00")
        self.assertEqual(codigo, 1)
        self.assertIn("Beds24 instável", out)

    def test_arquivo_com_em_sem_fuso_e_corrompido(self):
        self.arq.write_text(json.dumps({"tentativas": [{"quarto": "Q7", "em": "2026-11-08T07:58:00",
                                                        "resultado": "falha"}]}), encoding="utf-8")
        with self.assertRaises(cota.ErroCota) as ctx:
            cota.carregar(self.arq)
        self.assertIn("sem fuso", str(ctx.exception))


class Test429DaConta(BaseCota):
    """Um 429 pode ser do limite da conta: nenhum quarto recalcula por 1 minuto, para não virar cascata."""

    def test_429_de_um_quarto_segura_os_outros_por_um_minuto(self):
        self.reg("Q7", "429", "2026-11-08T10:57:00Z", "You have fired too many requests")
        s = self.sit("Double", "2026-11-08T10:57:05Z")
        self.assertFalse(s["pode"])
        self.assertEqual(s["usados"], 0)
        self.assertEqual(s["vaga_429"], T("2026-11-08T10:58:00Z"))
        self.assertIn("a conta levou 429", s["motivos"][0])
        self.assertIn("(Q7)", s["motivos"][0])
        self.assertIn("qualquer quarto", s["motivos"][0])
        self.assertFalse(self.sit("Double", "2026-11-08T10:57:59Z")["pode"])
        s = self.sit("Double", "2026-11-08T10:58:00Z")
        self.assertTrue(s["pode"])
        self.assertIsNone(s["vaga_429"])
        # a Q7 continua barrada pela trava da rodada, sem repetir o motivo da conta
        s = self.sit("Q7", "2026-11-08T10:57:05Z")
        self.assertEqual(len(s["motivos"]), 1)
        self.assertIn("429 nesta rodada", s["motivos"][0])
        self.assertFalse(self.sit("Q7", "2026-11-08T10:58:00Z")["pode"])
        codigo, out, _ = self.cli("pode", "--quarto", "Double", "--agora", "2026-11-08T10:57:05Z")
        self.assertEqual(codigo, 1)
        self.assertIn("a conta levou 429", out)
        _, out, _ = self.cli("ver", "--agora", "2026-11-08T10:57:05Z")
        self.assertIn("nenhum quarto recalcula até 08/11 07:58:00 BRT", out)

    def test_429_do_proprio_quarto_antes_da_rodada_segura_um_minuto(self):
        # 429 às 10:52:40 UTC, antes da rodada das 07:53 BRT: a trava da rodada não pega, a espera da conta sim
        self.reg("VK2", "429", "2026-11-08T10:52:40Z")
        s = self.sit("VK2", "2026-11-08T10:53:10Z")
        self.assertFalse(s["pode"])
        self.assertIn("a conta levou 429", s["motivos"][0])
        self.assertTrue(self.sit("VK2", "2026-11-08T10:53:40Z")["pode"])

    def test_falha_400_de_outro_quarto_nao_segura_a_conta(self):
        self.reg("Q7", "falha", "2026-11-08T10:57:00Z", BEDS24_FORA)
        self.assertTrue(self.sit("Double", "2026-11-08T10:57:05Z")["pode"])


class TestComandos(BaseCota):
    def test_pode_sai_1_quando_barrado_e_mostra_a_vaga(self):
        for em in ("2026-11-07T10:55:00Z", "2026-11-07T13:00:00Z", "2026-11-07T20:00:00Z"):
            self.reg("Balcony", "ok", em)
        codigo, out, _ = self.cli("pode", "--quarto", "Balcony", "--agora", "2026-11-07T22:00:00Z",
                                  "--rodada-desde", "2026-11-07T22:00:00Z")
        self.assertEqual(codigo, 1)
        self.assertIn("NÃO recalcule", out)
        self.assertIn("Próxima vaga: 08/11 07:55 BRT (10:55 UTC)", out)

    def test_ver_tabela(self):
        for em in ("2026-11-07T10:55:00Z", "2026-11-07T13:00:00Z"):
            self.reg("VK7", "ok", em)
        self.reg("VK7", "falha", "2026-11-07T20:00:00Z", BEDS24_FORA)
        self.reg("Q7", "ok", "2026-11-06T10:55:00Z")  # fora da janela de 24 h
        out = cota.tabela(cota.carregar(self.arq)["tentativas"], CFG, T("2026-11-07T22:00:00Z"), TZ)
        self.assertIn("| VK7 | 3 (2 ok, 1 falha) | 0 | 08/11 07:55 BRT (10:55 UTC) |", out)
        self.assertIn("| Q7 | 0 | 3 | agora | 06/11 07:55 BRT (10:55 UTC) ok |", out)
        for q in CFG["quartos"]:
            self.assertIn(f"| {q['curto']} |", out)


if __name__ == "__main__":
    unittest.main()

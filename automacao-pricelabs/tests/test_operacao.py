import re
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

from pacing import agenda

RAIZ = Path(__file__).resolve().parent.parent
NS = {"t": "http://schemas.microsoft.com/windows/2004/02/mit/task"}
COMANDOS = {"executar", "verificar", "desfazer", "parar", "retomar", "modo", "configurar-chaves", "testar-gravacao", "agendar"}


def _xml(texto):
    return ET.fromstring(texto.split("?>", 1)[1])


class TestAgenda(unittest.TestCase):
    def test_xml_das_execucoes(self):
        raiz = _xml(agenda.xml_execucoes(r"C:\RecantoPrecos", r"C:\Python312\pythonw.exe"))
        horas = [e.text[11:16] for e in raiz.findall(".//t:CalendarTrigger/t:StartBoundary", NS)]
        self.assertEqual(horas, ["05:30", "08:30", "11:30", "14:30", "17:30", "20:30", "23:30"])
        s = raiz.find("t:Settings", NS)
        self.assertEqual(s.find("t:MultipleInstancesPolicy", NS).text, "IgnoreNew")
        self.assertEqual(s.find("t:WakeToRun", NS).text, "true")
        self.assertEqual(s.find("t:StartWhenAvailable", NS).text, "true")
        self.assertEqual(s.find("t:ExecutionTimeLimit", NS).text, "PT20M")
        self.assertEqual(raiz.find(".//t:LogonType", NS).text, "InteractiveToken")
        self.assertEqual(raiz.find(".//t:Exec/t:Arguments", NS).text, "-m pacing executar")
        self.assertTrue(raiz.find(".//t:Exec/t:Command", NS).text.endswith("pythonw.exe"))

    def test_xml_do_logon_e_escape(self):
        raiz = _xml(agenda.xml_logon(r"C:\Pasta & Cia", "pyw.exe", usuario="PC\\André"))
        self.assertIsNotNone(raiz.find(".//t:LogonTrigger", NS))
        self.assertEqual(raiz.find(".//t:LogonTrigger/t:UserId", NS).text, "PC\\André")
        self.assertEqual(raiz.find(".//t:Principal/t:UserId", NS).text, "PC\\André")
        sem = _xml(agenda.xml_logon("C:\\x", "pyw.exe"))
        self.assertIsNone(sem.find(".//t:LogonTrigger/t:UserId", NS))
        self.assertEqual(raiz.find(".//t:Exec/t:WorkingDirectory", NS).text, r"C:\Pasta & Cia")
        self.assertIn("verificar --silencioso", raiz.find(".//t:Exec/t:Arguments", NS).text)

    def test_cron(self):
        linhas = agenda.linhas_cron("/opt/p", "/usr/bin/python3")
        self.assertEqual(len(linhas), 7)
        self.assertTrue(linhas[0].startswith("30 5 * * *"))


class TestScriptsWindows(unittest.TestCase):
    def test_bats_de_uma_linha_ascii(self):
        bats = sorted((RAIZ / "windows").glob("*.bat"))
        self.assertEqual({b.name for b in bats}, {
            "instalar.bat", "configurar-chaves.bat", "verificar.bat", "executar.bat", "parar.bat", "retomar.bat",
            "desfazer.bat", "ativar.bat", "ampliar.bat", "observar.bat", "ensaio.bat", "ensaio-manter.bat"})
        instalar = (RAIZ / "windows" / "instalar.bat").read_text(encoding="ascii")
        self.assertLess(instalar.index("pacing executar"), instalar.index("pacing verificar"),
                        "a primeira verificação precisa de uma execução registrada")
        for bat in bats:
            bruto = bat.read_bytes()
            bruto.decode("ascii")
            self.assertTrue(bruto.endswith(b"\r\n"), bat.name)
            self.assertEqual(bruto.count(b"\n"), bruto.count(b"\r\n"), f"{bat.name}: LF sem CR")
            linhas = [l for l in bruto.decode("ascii").splitlines() if l.strip()]
            self.assertEqual(len(linhas), 1, bat.name)
            self.assertTrue(linhas[0].startswith('@cd /d "%~dp0.." && '), bat.name)
            for cmd in re.findall(r"py -3 -m pacing ([a-z-]+)", linhas[0]):
                self.assertIn(cmd, COMANDOS, bat.name)
        self.assertIn("*.bat text eol=crlf", (RAIZ / ".gitattributes").read_text())

    def test_readme_cobre_operacao(self):
        texto = (RAIZ / "README.md").read_text(encoding="utf-8")
        for trecho in ("instalar.bat", "verificar.bat", "parar.bat", "desfazer.bat", "retomar.bat", "ativar.bat",
                       "typesafe/jev-1.13:free", "US$ 7", "Pendências", "Gerenciador de Credenciais",
                       "ensaio.bat", "ensaio-manter.bat", "ampliar.bat", "Desbloquear", "Se aparecer FALHA"):
            self.assertIn(trecho, texto)


if __name__ == "__main__":
    unittest.main()

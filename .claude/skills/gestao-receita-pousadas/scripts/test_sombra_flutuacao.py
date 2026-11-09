"""Testes da sombra da flutuação (sombra_flutuacao.py). Rodar na pasta scripts/:
python3 -m unittest test_sombra_flutuacao

Recálculos sintéticos (rec_<Q>.json) e registro em pasta temporária criada pelo próprio teste: nada de rede, nada de
dados/sombra_flutuacao.json real. A configuração é a do quartos.json com a lista de feriados e as datas de pacote
trocadas por valores do teste, para o teste não mudar quando o dono acrescentar um feriado.
"""

from __future__ import annotations

import copy
import io
import json
import math
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from datetime import date, datetime, timezone
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))
import sombra_flutuacao as sf  # noqa: E402
from comum import carregar_quartos  # noqa: E402

CFG_REAL = carregar_quartos()
FERIADO_TESTE = {"nome": "Feriado do teste", "inicio": "2026-11-19", "fim": "2026-11-20", "piso_real": 800}
PACOTE_TESTE = "2026-11-22"


def cfg_teste() -> dict:
    cfg = copy.deepcopy(CFG_REAL)
    cfg["feriados"] = [dict(FERIADO_TESTE)]
    rh = cfg["parametros"].setdefault("regra_h_beds24", {})
    for k in ("estadia_minima_por_data", "estadia_maxima_por_data", "override_por_data"):
        rh[k] = {}
    rh["estadia_minima_por_data"][PACOTE_TESTE] = 2
    return cfg


CFG = cfg_teste()
PAR, LIM = CFG["parametros"], CFG["limites_seguranca"]
PISO_SEM_RECANTO = math.ceil(LIM["piso_vitrine_semana_suites"] / PAR["fator_vitrine_semana"]["Recanto"] - 1e-9)
PISO_SEM_VILLA = math.ceil(LIM["piso_vitrine_semana_suites"] / PAR["fator_vitrine_semana"]["Villa"] - 1e-9)
PISO_FDS_VILLA = math.ceil(LIM["piso_vitrine_fds_suites"] / PAR["fator_vitrine_fds"]["Villa"] - 1e-9)
AGORA = datetime(2026, 11, 10, 10, 55, tzinfo=timezone.utc)


def noite(d: str, price, min_p, max_p, uncust, mu="0/7", dso="1", hol="0", nb="0") -> dict:
    """Uma noite do pricing_array como o PriceLabs devolve (tudo texto)."""
    return {"date": d, "price": str(price), "min_price": str(min_p), "max_price": str(max_p), "dso_flag": dso,
            "uncustomized_price": None if uncust is None else str(uncust), "mu_occupancy": mu, "num_bookings": nb,
            "holiday_flag": hol, "user_price": "-1"}


def escrever_rec(pasta: Path, quarto: str, noites: list[dict]) -> None:
    (pasta / f"rec_{quarto}.json").write_text(
        json.dumps({"success": True, "status_code": 200, "data": {"pricing_array": noites}}), encoding="utf-8")


class Base(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.pasta = Path(self._tmp.name)
        self.arq = self.pasta / "sombra.json"

    def tearDown(self):
        self._tmp.cleanup()

    def rec_dir(self, nome: str, por_quarto: dict) -> Path:
        p = self.pasta / nome
        p.mkdir()
        for q, noites in por_quarto.items():
            escrever_rec(p, q, noites)
        return p

    def registrar(self, rec: Path, hoje: str, dias: int = 7, **kw):
        return sf.registrar(rec, date.fromisoformat(hoje), dias, caminho=self.arq, cfg=CFG, agora=AGORA, **kw)

    def cli(self, *args) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with mock.patch.object(sf, "carregar_quartos", return_value=CFG), redirect_stdout(out), redirect_stderr(err):
            codigo = sf.main(list(args))
        return codigo, out.getvalue(), err.getvalue()


class TestPisos(Base):
    def test_piso_semana_e_fds_das_suites(self):
        q7, vk7 = sf.limites_do_quarto(CFG, "Q7"), sf.limites_do_quarto(CFG, "VK7")
        self.assertEqual(q7["semana"], PISO_SEM_RECANTO)
        self.assertEqual(vk7["semana"], PISO_SEM_VILLA)
        self.assertEqual(vk7["fds"], PISO_FDS_VILLA)
        # arredondado para cima: nunca abaixo do valor exato
        self.assertGreaterEqual(vk7["semana"], LIM["piso_vitrine_semana_suites"] / PAR["fator_vitrine_semana"]["Villa"])
        # terça usa o piso de semana; sexta e sábado o de fds
        e_ter = sf.noite_da_foto(CFG, "VK7", noite("2026-11-10", 1199, 1199, 1199, 500), date(2026, 11, 10), vk7)
        e_sex = sf.noite_da_foto(CFG, "VK7", noite("2026-11-13", 2856, 2856, 2856, 500), date(2026, 11, 13), vk7)
        self.assertEqual((e_ter["tipo"], e_ter["piso"], e_ter["solto"]), ("semana", PISO_SEM_VILLA, PISO_SEM_VILLA))
        self.assertEqual((e_sex["tipo"], e_sex["piso"], e_sex["solto"]), ("fds", PISO_FDS_VILLA, PISO_FDS_VILLA))
        self.assertEqual(e_sex["dia"], "sex")

    def test_ceil_sem_residuo_de_ponto_flutuante(self):
        cfg = copy.deepcopy(CFG)
        cfg["limites_seguranca"]["piso_vitrine_fds_suites"] = 850
        cfg["parametros"]["fator_vitrine_fds"]["Recanto"] = 0.425
        cfg["limites_seguranca"]["piso_vitrine_semana_suites"] = 500
        cfg["parametros"]["fator_vitrine_semana"]["Recanto"] = 0.41
        lim = sf.limites_do_quarto(cfg, "Double")
        self.assertEqual((lim["semana"], lim["fds"]), (1220, 2000))

    def test_balcony_fica_no_minimo_do_anuncio(self):
        q = CFG["por_curto"]["Balcony"]
        self.assertFalse(q["banheira"])
        lim = sf.limites_do_quarto(CFG, "Balcony")
        self.assertEqual((lim["semana"], lim["fds"]), (math.ceil(q["min"]), math.ceil(q["min"])))
        lim = sf.limites_do_quarto(CFG, "Balcony", contexto={"Balcony": {"min": 640, "base": 1270, "max": 4000}})
        self.assertEqual((lim["semana"], lim["fds"], lim["teto"]), (640, 640, 4000))
        lim = sf.limites_do_quarto(CFG, "Balcony", contexto={"Balcony": {"min": 640}}, min_balcony=700)
        self.assertEqual((lim["semana"], lim["fds"]), (700, 700))
        # sábado da Balcony: o mínimo do anúncio, não o piso de R$ 850 das suítes
        e = sf.noite_da_foto(CFG, "Balcony", noite("2026-11-14", 1943, 1943, 1943, 600, mu="2/6"),
                             date(2026, 11, 14), sf.limites_do_quarto(CFG, "Balcony", min_balcony=700))
        self.assertEqual((e["piso"], e["solto"], e["vendidas"], e["total"]), (700, 700, 2, 6))

    def test_teto_do_anuncio_e_contexto_da_coleta(self):
        self.assertEqual(sf.limites_do_quarto(CFG, "Afrodite")["teto"], CFG["por_curto"]["Afrodite"]["max"])
        coleta = {"result": {"contexto": {"sync": [{"listing_id": CFG["por_curto"]["VK7"]["id"], "min": 944,
                                                     "base": 1480, "max": 3000}]}}}
        ctx = sf.anuncios_do_contexto(CFG, coleta)
        self.assertEqual(ctx, {"VK7": {"min": 944, "base": 1480, "max": 3000}})
        lim = sf.limites_do_quarto(CFG, "VK7", contexto=ctx)
        self.assertEqual(lim["teto"], 3000)
        # uncustomized acima do teto: o solto para no teto
        e = sf.noite_da_foto(CFG, "VK7", noite("2026-11-10", 1199, 1199, 1199, 4200), date(2026, 11, 10), lim)
        self.assertEqual(e["solto"], 3000)
        # entre piso e teto: o próprio uncustomized_price
        e = sf.noite_da_foto(CFG, "VK7", noite("2026-11-10", 1199, 1199, 1199, 1300), date(2026, 11, 10), lim)
        self.assertEqual(e["solto"], 1300)


class TestFeriadoEEstado(Base):
    def test_feriado_e_pacote_ficam_fora_da_comparacao(self):
        lim = sf.limites_do_quarto(CFG, "VK7")
        fer = sf.noite_da_foto(CFG, "VK7", noite("2026-11-19", 2057, 2057, 2057, 1500), date(2026, 11, 19), lim)
        self.assertEqual((fer["tipo"], fer["feriado"], fer["solto"], fer["piso"]),
                         ("feriado", "Feriado do teste", None, None))
        pac = sf.noite_da_foto(CFG, "VK7", noite(PACOTE_TESTE, 1051, 1051, 1051, 1300), date(2026, 11, 22), lim)
        self.assertEqual((pac["tipo"], pac["feriado"]), ("feriado", "pacote do dono"))
        # holiday_flag do PriceLabs fora da lista do dono: anotado, a noite segue como fds
        pl = sf.noite_da_foto(CFG, "VK7", noite("2026-11-13", 2856, 2856, 2856, 2400, hol="1"), date(2026, 11, 13), lim)
        self.assertEqual((pl["tipo"], pl.get("feriado_pl")), ("fds", 1))
        # no resumo, o feriado não entra nos preços
        rec = self.rec_dir("r1", {"VK7": [noite("2026-11-18", 947, 947, 947, 1066, mu="2/7"),
                                          noite("2026-11-19", 2057, 2057, 2057, 1500, mu="0/7")]})
        self.registrar(rec, "2026-11-18", dias=2)
        r = sf.resumo(sf.carregar(self.arq), date(2026, 11, 18), date(2026, 11, 18), CFG)
        vk7 = r["quartos"]["VK7"]
        self.assertEqual(vk7["precos"]["semana"]["noites"], 1)
        self.assertEqual(vk7["feriado_ou_pacote"], 1)

    def test_estados_da_regra(self):
        self.assertEqual(sf.estado_da_regra(noite("2026-11-10", 1232, 1232, 1232, 967)), "travada")
        self.assertEqual(sf.estado_da_regra(noite("2026-11-20", 4417, 2575, 5000, 5028)), "so_piso")
        self.assertEqual(sf.estado_da_regra(noite("2026-11-18", 1500, 1500, 10000, 1723, dso="0")), "sem_regra")
        self.assertEqual(sf.estado_da_regra({"dso_flag": "1", "price": "900", "min_price": None, "max_price": ""}),
                         "travada")

    def test_so_piso_e_marcado_e_nao_entra_na_comparacao(self):
        rec = self.rec_dir("r1", {"VK7": [noite("2026-11-10", 1060, 947, 5000, 1454, mu="6/7"),
                                          noite("2026-11-11", 947, 947, 947, 1175, mu="3/7")]})
        foto, _ = self.registrar(rec, "2026-11-10", dias=2)
        e10, e11 = foto["quartos"]["VK7"]
        self.assertEqual((e10["estado"], e10["gravado"], e10["min"], e10["max"]), ("so_piso", 1060, 947, 5000))
        self.assertEqual(e11["estado"], "travada")
        r = sf.resumo(sf.carregar(self.arq), date(2026, 11, 10), date(2026, 11, 10), CFG)
        vk7 = r["quartos"]["VK7"]
        self.assertEqual(vk7["estados"], {"travada": 1, "so_piso": 1, "sem_regra": 0})
        self.assertEqual(vk7["precos"]["semana"]["noites"], 1)
        # calibração: preço real 1060 contra a fórmula max(947, min(1454, 5000)) = 1454
        self.assertEqual(vk7["calibracao"]["noites"], 1)
        self.assertAlmostEqual(vk7["calibracao"]["erro_medio_pct"], (1060 / 1454 - 1) * 100, places=1)

    def test_noite_esgotada_fica_fora_da_comparacao_e_da_calibracao(self):
        rec = self.rec_dir("r1", {"VK7": [noite("2026-11-10", 1060, 947, 5000, 1454, mu="7/7"),
                                          noite("2026-11-11", 947, 947, 947, 1175, mu="7/7"),
                                          noite("2026-11-12", 1199, 1199, 1199, 1045, mu="2/7")]})
        self.registrar(rec, "2026-11-10", dias=3)
        vk7 = sf.resumo(sf.carregar(self.arq), date(2026, 11, 10), date(2026, 11, 10), CFG)["quartos"]["VK7"]
        self.assertEqual(vk7["esgotadas"], 2)
        self.assertEqual(vk7["precos"]["semana"]["noites"], 1)
        self.assertEqual(vk7["precos"]["semana"]["gravado_medio"], 1199)
        self.assertEqual(vk7["calibracao"]["noites"], 0)

    def test_valores_vazios_nao_quebram(self):
        lim = sf.limites_do_quarto(CFG, "Q7")
        r = {"date": "2026-11-10", "price": "", "min_price": None, "max_price": "", "dso_flag": None,
             "uncustomized_price": "", "mu_occupancy": None, "num_bookings": None}
        e = sf.noite_da_foto(CFG, "Q7", r, date(2026, 11, 10), lim)
        self.assertEqual((e["gravado"], e["solto"], e["uncust"], e["vendidas"]), (None, None, None, 0))
        self.assertEqual(e["total"], CFG["por_curto"]["Q7"]["unidades"])
        # unidade única (Afrodite): mu_occupancy "-1" e a venda em num_bookings
        e = sf.noite_da_foto(CFG, "Afrodite", noite("2026-11-10", 1740, 1740, 1740, 1735, mu="-1", nb="1"),
                             date(2026, 11, 10), sf.limites_do_quarto(CFG, "Afrodite"))
        self.assertEqual((e["vendidas"], e["total"]), (1, 1))


class TestRegistrar(Base):
    def rec_padrao(self, nome="r1", vend_12="2/7") -> Path:
        return self.rec_dir(nome, {
            "VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                    noite("2026-11-11", 947, 947, 947, 1175, mu="3/7"),
                    noite("2026-11-12", 1199, 1199, 1199, 1045, mu=vend_12)],
            "Balcony": [noite("2026-11-10", 698, 698, 698, 794, mu="0/6")]})

    def test_idempotente_no_mesmo_dia(self):
        rec = self.rec_padrao()
        foto1, subst1 = self.registrar(rec, "2026-11-10", dias=3)
        texto1 = self.arq.read_text(encoding="utf-8")
        foto2, subst2 = self.registrar(rec, "2026-11-10", dias=3)
        self.assertFalse(subst1)
        self.assertTrue(subst2)
        self.assertEqual(foto1, foto2)
        self.assertEqual(self.arq.read_text(encoding="utf-8"), texto1)
        d = sf.carregar(self.arq)
        self.assertEqual(list(d["fotos"]), ["2026-11-10"])
        # sem temporário esquecido na pasta (gravação atômica)
        self.assertEqual(sorted(p.name for p in self.pasta.iterdir() if p.is_file()), ["sombra.json"])

    def test_mesmo_dia_substitui_e_outro_dia_acrescenta(self):
        self.registrar(self.rec_padrao("r1", "2/7"), "2026-11-10", dias=3)
        self.registrar(self.rec_padrao("r2", "4/7"), "2026-11-10", dias=3)
        d = sf.carregar(self.arq)
        self.assertEqual(len(d["fotos"]), 1)
        self.assertEqual(d["fotos"]["2026-11-10"]["quartos"]["VK7"][2]["vendidas"], 4)
        self.registrar(self.rec_padrao("r3", "4/7"), "2026-11-11", dias=2)
        d = sf.carregar(self.arq)
        self.assertEqual(list(d["fotos"]), ["2026-11-10", "2026-11-11"])
        self.assertEqual([e["data"] for e in d["fotos"]["2026-11-11"]["quartos"]["VK7"]], ["2026-11-11", "2026-11-12"])
        # quarto sem rec_<Q>.json fica fora da foto e é anotado; noite fora do recálculo também
        f = d["fotos"]["2026-11-11"]
        self.assertIn("Q7", f["faltando"])
        self.assertEqual(f["noites_sem_dado"], {"Balcony": 2})

    def test_arquivo_corrompido_nao_e_regravado(self):
        self.arq.write_text("{ meio arquivo", encoding="utf-8")
        with self.assertRaises(sf.ErroSombra):
            self.registrar(self.rec_padrao(), "2026-11-10", dias=3)
        self.assertEqual(self.arq.read_text(encoding="utf-8"), "{ meio arquivo")

    def test_pasta_sem_rec(self):
        vazio = self.pasta / "vazio"
        vazio.mkdir()
        with self.assertRaises(sf.ErroSombra):
            self.registrar(vazio, "2026-11-10")


class TestResumo(Base):
    def duas_fotos(self):
        # foto de 10/11: noite 12/11 travada a R$ 1.199 com 2/7 (solto 1.300: uncustomized acima do gravado);
        # noite 13/11 (sex) travada a R$ 2.856 com 4/7 (solto 2.418: abaixo); noite 11/11 com 3/7 sem venda depois
        r10 = self.rec_dir("r10", {"VK7": [
            noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
            noite("2026-11-11", 947, 947, 947, 1175, mu="3/7"),
            noite("2026-11-12", 1199, 1199, 1199, 1300, mu="2/7"),
            noite("2026-11-13", 2856, 2856, 2856, 2418, mu="4/7")]})
        # foto de 11/11: +1 na noite 12/11, +2 na noite 13/11 (preço novo nesta foto não muda a venda: vale a foto anterior)
        r11 = self.rec_dir("r11", {"VK7": [
            noite("2026-11-11", 947, 947, 947, 1175, mu="3/7"),
            noite("2026-11-12", 1250, 1250, 1250, 1300, mu="3/7"),
            noite("2026-11-13", 2900, 2900, 2900, 2418, mu="6/7")]})
        # foto de 12/11: a noite 11/11 passou com 4 livres e nenhuma venda no período; 13/11 perde 1 (cancelamento)
        r12 = self.rec_dir("r12", {"VK7": [
            noite("2026-11-12", 1250, 1250, 1250, 1300, mu="3/7"),
            noite("2026-11-13", 2900, 2900, 2900, 2418, mu="5/7")]})
        self.registrar(r10, "2026-11-10", dias=4)
        self.registrar(r11, "2026-11-11", dias=3)
        self.registrar(r12, "2026-11-12", dias=2)
        return sf.resumo(sf.carregar(self.arq), date(2026, 11, 10), date(2026, 11, 12), CFG)

    def test_venda_detectada_entre_fotos(self):
        r = self.duas_fotos()
        vk7 = r["quartos"]["VK7"]
        vendas = {v["noite"]: v for v in vk7["vendas"]}
        self.assertEqual(set(vendas), {"2026-11-12", "2026-11-13"})
        v12 = vendas["2026-11-12"]
        self.assertEqual((v12["unidades"], v12["entre"], v12["gravado"], v12["solto"]),
                         (1, ["2026-11-10", "2026-11-11"], 1199, 1300))
        self.assertEqual(vendas["2026-11-13"]["unidades"], 2)
        self.assertEqual(vk7["canceladas"], 1)

    def test_resumo_com_dinheiro_na_mesa(self):
        r = self.duas_fotos()
        t = r["quartos"]["VK7"]["vendas_total"]
        # 12/11: solto 1.300 > gravado 1.199 → R$ 101 na mesa; 13/11: solto 2.418 < 2.856 em 2 unidades → R$ 876
        self.assertEqual(t["dinheiro_na_mesa"], 101)
        self.assertEqual(t["vendas_com_dinheiro_na_mesa"], 1)
        self.assertEqual(t["mais_barata_no_solto"], 2 * (2856 - 2418))
        self.assertEqual(t["vendas_mais_baratas_no_solto"], 1)
        self.assertEqual((t["unidades"], t["receita_gravada"], t["receita_solta"]),
                         (3, 1199 + 2 * 2856, 1300 + 2 * 2418))

    def test_noites_que_passaram_sem_venda(self):
        r = self.duas_fotos()
        enc = {e["noite"]: e for e in r["quartos"]["VK7"]["encalhadas"]}
        # 10/11 (vista só na própria foto, 2 livres) e 11/11 (4 livres, nenhuma venda); 12/11 é a última foto: não passou
        self.assertEqual(set(enc), {"2026-11-10", "2026-11-11"})
        self.assertEqual((enc["2026-11-11"]["livres"], enc["2026-11-11"]["vendas_no_periodo"]), (4, 0))
        t = r["quartos"]["VK7"]["encalhadas_total"]
        self.assertEqual((t["noites"], t["unidades_livres"], t["sem_venda_no_periodo"]), (2, 6, 2))

    def test_precos_pela_ultima_foto_de_cada_noite(self):
        p = self.duas_fotos()["quartos"]["VK7"]["precos"]
        # semana: 10/11 (1.199 → 1.300), 11/11 (947 → 1.175), 12/11 da última foto (1.250 → 1.300)
        self.assertEqual(p["semana"]["noites"], 3)
        self.assertEqual(p["semana"]["acima"]["noites"], 3)
        self.assertEqual(p["semana"]["gravado_medio"], round((1199 + 947 + 1250) / 3, 2))
        self.assertEqual((p["fds"]["noites"], p["fds"]["abaixo"]["noites"]), (1, 1))
        self.assertAlmostEqual(p["fds"]["abaixo"]["media_pct"], (2418 / 2900 - 1) * 100, places=1)

    def test_texto_destaca_vk7_e_json_pela_linha_de_comando(self):
        self.duas_fotos()
        codigo, out, err = self.cli("resumo", "--de", "2026-11-10", "--ate", "2026-11-12", "--arquivo", str(self.arq))
        self.assertEqual(codigo, 0, err)
        self.assertIn("## VK7", out)
        self.assertLess(out.index("## VK7"), out.index("## Preço por quarto"))
        self.assertIn("ESTIMATIVA", out)
        self.assertIn("uncustomized_price", out)
        codigo, out, _ = self.cli("resumo", "--de", "2026-11-10", "--ate", "2026-11-12", "--arquivo", str(self.arq),
                                  "--json")
        self.assertEqual(codigo, 0)
        j = json.loads(out)
        self.assertEqual((j["destaque"], j["fotos"]), ("VK7", ["2026-11-10", "2026-11-11", "2026-11-12"]))
        self.assertEqual(j["quartos"]["VK7"]["vendas_total"]["dinheiro_na_mesa"], 101)

    def test_linha_de_comando_registrar_e_erros(self):
        rec = self.rec_dir("r1", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7")]})
        codigo, out, err = self.cli("registrar", "--rec-dir", str(rec), "--hoje", "2026-11-10", "--dias", "1",
                                    "--arquivo", str(self.arq))
        self.assertEqual(codigo, 0, err)
        self.assertIn("gravada", out)
        self.assertIn("nada foi gravado no PriceLabs", out)
        codigo, _, err = self.cli("registrar", "--rec-dir", str(rec), "--hoje", "10/11/2026", "--arquivo", str(self.arq))
        self.assertEqual(codigo, 2)
        self.assertIn("data inválida", err)
        codigo, _, err = self.cli("resumo", "--de", "2026-12-01", "--ate", "2026-12-07", "--arquivo", str(self.arq))
        self.assertEqual(codigo, 2)
        self.assertIn("nenhuma foto", err)


if __name__ == "__main__":
    unittest.main()

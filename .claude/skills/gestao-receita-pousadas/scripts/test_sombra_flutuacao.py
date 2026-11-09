"""Testes da sombra da flutuação (sombra_flutuacao.py). Rodar na pasta scripts/:
python3 -m unittest test_sombra_flutuacao

Recálculos sintéticos (rec_<Q>.json), regras por data (ov_pl_<Q>.json), planos e inventários do Beds24 em pasta temporária
criada pelo próprio teste: nada de rede, nada de dados/sombra_flutuacao.json real. A configuração é a do quartos.json
com a lista de feriados e as datas de pacote trocadas por valores do teste, para o teste não mudar quando o dono
acrescentar um feriado. A hora da coleta (mtime do rec) e a do registro são fixadas por foto (07:53 e 08:30 em Brasília);
a hora da gravação de cada plano também (mtime do plano).
"""

from __future__ import annotations

import copy
import io
import json
import math
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from datetime import date, datetime, timedelta, timezone
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
PISO_FDS_RECANTO = math.ceil(LIM["piso_vitrine_fds_suites"] / PAR["fator_vitrine_fds"]["Recanto"] - 1e-9)
AGORA = datetime(2026, 11, 10, 10, 55, tzinfo=timezone.utc)


def coleta_em(hoje: str) -> datetime:
    """07:53 em Brasília (10:53 UTC) do dia: a hora da coleta do PriceLabs."""
    return datetime.fromisoformat(hoje + "T10:53:00+00:00")


def noite(d: str, price, min_p, max_p, uncust, mu="0/7", dso="1", hol="0", nb="0") -> dict:
    """Uma noite do pricing_array como o PriceLabs devolve (tudo texto)."""
    return {"date": d, "price": str(price), "min_price": str(min_p), "max_price": str(max_p), "dso_flag": dso,
            "uncustomized_price": None if uncust is None else str(uncust), "mu_occupancy": mu, "num_bookings": nb,
            "holiday_flag": hol, "user_price": "-1"}


def escrever_rec(pasta: Path, quarto: str, noites: list[dict]) -> None:
    (pasta / f"rec_{quarto}.json").write_text(
        json.dumps({"success": True, "status_code": 200, "data": {"pricing_array": noites}}), encoding="utf-8")


def escrever_ov(pasta: Path, quarto: str, regras: list[dict]) -> None:
    """ov_pl_<Q>.json no formato da API (get_listing_date_overrides)."""
    (pasta / f"ov_pl_{quarto}.json").write_text(
        json.dumps({"success": True, "status_code": 200, "data": {"overrides": regras}}), encoding="utf-8")


def inventario(lido_em: str, por_quarto: dict) -> dict:
    """Inventário do Beds24 como a rotina leve salva: {quarto: {data: {numAvail, ...}}, "_lido_em"}."""
    inv = {q: {d: {"numAvail": n, "minStay": 1, "maxStay": 7, "override": "none"} for d, n in noites.items()}
           for q, noites in por_quarto.items()}
    inv["_lido_em"] = lido_em
    return inv


def escrever_inv(pasta: Path, lido_em: str, por_quarto: dict) -> Path:
    """inv.json da coleta na pasta do rec (a leitura do Beds24 da rotina das 07:53, base da foto)."""
    arq = pasta / "inv.json"
    arq.write_text(json.dumps(inventario(lido_em, por_quarto)), encoding="utf-8")
    return arq


def escrever_plano(arq: Path, itens, gravado_em: str) -> Path:
    """Plano gravado no formato do validar_plano, com a hora da gravação no mtime do arquivo."""
    arq.write_text(json.dumps(itens if isinstance(itens, list) else {"hoje": gravado_em[:10], "itens": itens["itens"]}),
                   encoding="utf-8")
    ts = datetime.fromisoformat(gravado_em.replace("Z", "+00:00")).timestamp()
    os.utime(arq, (ts, ts))
    return arq


def cobrado(quarto: str, reais_: float, base: float | None = None) -> int:
    """O que o PriceLabs cobra por um piso do plano: percent_base sobre a base do anúncio, arredondado para cima, e o
    valor em reais arredondado ao real (validar_plano.montar_payload)."""
    b = float(base or CFG["por_curto"][quarto]["base"])
    return round(b * (1 + math.ceil(reais_ / b * 100 - 100 - 1e-9) / 100))


class Base(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.pasta = Path(self._tmp.name)
        self.arq = self.pasta / "sombra.json"

    def tearDown(self):
        self._tmp.cleanup()

    def rec_dir(self, nome: str, por_quarto: dict, ov: dict | None = None) -> Path:
        p = self.pasta / nome
        p.mkdir()
        for q, noites in por_quarto.items():
            escrever_rec(p, q, noites)
        for q, regras in (ov or {}).items():
            escrever_ov(p, q, regras)
        return p

    def registrar(self, rec: Path, hoje: str, dias: int = 7, agora: datetime | None = None,
                  coleta: datetime | None = None, **kw):
        ts = (coleta or coleta_em(hoje)).timestamp()
        for f in rec.iterdir():
            os.utime(f, (ts, ts))
        agora = agora or (coleta or coleta_em(hoje)) + timedelta(minutes=37)
        return sf.registrar(rec, date.fromisoformat(hoje), dias, caminho=self.arq, cfg=CFG, agora=agora, **kw)

    def fechar(self, lido_em: str, por_quarto: dict, hoje: str | None = None, dias: int = 7):
        inv = inventario(lido_em, por_quarto)
        return sf.fechar(inv, sf.ler_instante(lido_em), date.fromisoformat(hoje) if hoje else None, dias, self.arq, CFG)

    def resumo(self, de: str, ate: str) -> dict:
        return sf.resumo(sf.carregar(self.arq), date.fromisoformat(de), date.fromisoformat(ate), CFG)

    def cli(self, *args, hoje: str = "2026-11-10") -> tuple[int, str, str]:
        """Linha de comando com o relógio de Brasília fixo em `hoje`."""
        out, err = io.StringIO(), io.StringIO()
        with mock.patch.object(sf, "carregar_quartos", return_value=CFG), \
                mock.patch.object(sf, "hoje_brt", return_value=date.fromisoformat(hoje)), \
                redirect_stdout(out), redirect_stderr(err):
            codigo = sf.main(list(args))
        return codigo, out.getvalue(), err.getvalue()


class TestPisos(Base):
    def test_piso_semana_e_fds_das_suites(self):
        q7, vk7 = sf.limites_do_quarto(CFG, "Q7"), sf.limites_do_quarto(CFG, "VK7")
        self.assertEqual(q7["semana"], max(PISO_SEM_RECANTO, math.ceil(CFG["por_curto"]["Q7"]["min"])))
        self.assertEqual(vk7["semana"], max(PISO_SEM_VILLA, math.ceil(CFG["por_curto"]["VK7"]["min"])))
        self.assertEqual(vk7["fds"], PISO_FDS_VILLA)
        # arredondado para cima: nunca abaixo do valor exato
        self.assertGreaterEqual(vk7["semana"], LIM["piso_vitrine_semana_suites"] / PAR["fator_vitrine_semana"]["Villa"])
        # terça usa o piso de semana; sexta e sábado o de fds
        e_ter = sf.noite_da_foto(CFG, "VK7", noite("2026-11-10", 1199, 1199, 1199, 500), date(2026, 11, 10), vk7)
        e_sex = sf.noite_da_foto(CFG, "VK7", noite("2026-11-13", 2856, 2856, 2856, 500), date(2026, 11, 13), vk7)
        self.assertEqual((e_ter["tipo"], e_ter["piso"], e_ter["solto"]), ("semana", vk7["semana"], vk7["semana"]))
        self.assertEqual((e_sex["tipo"], e_sex["piso"], e_sex["solto"]), ("fds", PISO_FDS_VILLA, PISO_FDS_VILLA))
        self.assertEqual(e_sex["dia"], "sex")

    def test_ceil_sem_residuo_de_ponto_flutuante(self):
        cfg = copy.deepcopy(CFG)
        cfg["limites_seguranca"]["piso_vitrine_fds_suites"] = 850
        cfg["parametros"]["fator_vitrine_fds"]["Recanto"] = 0.425
        cfg["limites_seguranca"]["piso_vitrine_semana_suites"] = 500
        cfg["parametros"]["fator_vitrine_semana"]["Recanto"] = 0.41
        cfg["por_curto"]["Double"]["min"] = 900
        lim = sf.limites_do_quarto(cfg, "Double")
        self.assertEqual((lim["semana"], lim["fds"]), (1220, 2000))

    def test_piso_da_suite_respeita_o_minimo_do_anuncio(self):
        # Achado: a Afrodite tem mínimo do anúncio R$ 1.500 e o piso do dono (500 ÷ 0,41) dá R$ 1.220: o PriceLabs nunca
        # cobraria abaixo de R$ 1.500, então o solto não pode ficar em R$ 1.220
        cfg = copy.deepcopy(CFG)
        cfg["por_curto"]["Afrodite"]["min"] = 1500
        lim = sf.limites_do_quarto(cfg, "Afrodite")
        self.assertEqual((lim["semana"], lim["fds"]), (1500, PISO_FDS_RECANTO))
        self.assertEqual(lim["origem"], {"semana": "anuncio", "fds": "dono"})
        self.assertIn("mínimo do anúncio R$ 1.500", lim["origem_piso"])
        e = sf.noite_da_foto(cfg, "Afrodite", noite("2026-11-10", 1740, 1740, 1740, 1300, mu="-1"), date(2026, 11, 10), lim)
        self.assertEqual((e["piso"], e["solto"]), (1500, 1500))
        # mínimo = o maior entre o --contexto e o quartos.json
        lim = sf.limites_do_quarto(cfg, "Afrodite", contexto={"Afrodite": {"min": 1600}})
        self.assertEqual(lim["semana"], 1600)
        self.assertIn("--contexto", lim["origem_piso"])
        lim = sf.limites_do_quarto(cfg, "Afrodite", contexto={"Afrodite": {"min": 1400}})
        self.assertEqual(lim["semana"], 1500)
        self.assertIn("quartos.json", lim["origem_piso"])
        # Moinhos: mínimo do anúncio abaixo do piso do dono, vence o dono; acima, vence o anúncio
        cfg["por_curto"]["Q7"]["min"] = 920
        lim = sf.limites_do_quarto(cfg, "Q7", contexto={"Q7": {"min": 800}})
        self.assertEqual((lim["semana"], lim["origem"]["semana"], lim["minimo_anuncio"]), (PISO_SEM_RECANTO, "dono", 920))
        lim = sf.limites_do_quarto(cfg, "Q7", contexto={"Q7": {"min": 1300}})
        self.assertEqual((lim["semana"], lim["origem"]["semana"]), (1300, "anuncio"))

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
        vk7 = self.resumo("2026-11-18", "2026-11-18")["quartos"]["VK7"]
        self.assertEqual(vk7["precos"]["semana"]["noites"], 1)
        self.assertEqual(vk7["feriado_ou_pacote"], 1)

    def test_festas_entram_como_feriado_pela_classificacao_dos_scripts(self):
        # Achado: 27 e 28/12 (entre festas, domingo e segunda) entravam como dia de semana comum. O CFG do teste não tem
        # Natal nem Réveillon na lista de feriados: a classificação vem de faixas_dono.tipo_da_noite
        self.assertEqual(sf.tipo_da_noite(CFG, date(2026, 12, 27)), ("feriado", "Entre Natal e Réveillon (27-29/12)"))
        self.assertEqual(sf.tipo_da_noite(CFG, date(2026, 12, 28))[0], "feriado")
        self.assertEqual(sf.tipo_da_noite(CFG, date(2026, 12, 25)), ("feriado", "Natal (23-26/12)"))
        self.assertEqual(sf.tipo_da_noite(CFG, date(2026, 12, 31)), ("feriado", "Réveillon (30/12-02/01)"))
        self.assertEqual(sf.tipo_da_noite(CFG, date(2027, 1, 2))[0], "feriado")
        self.assertEqual(sf.tipo_da_noite(CFG, date(2027, 1, 3)), ("semana", None))
        self.assertEqual(sf.tipo_da_noite(CFG, date(2026, 11, 26)), ("semana", None))
        rec = self.rec_dir("r1", {"VK7": [noite("2026-12-26", 2500, 2500, 2500, 1800, mu="2/7"),
                                          noite("2026-12-27", 1800, 1800, 1800, 1500, mu="2/7"),
                                          noite("2026-12-28", 1800, 1800, 1800, 1500, mu="2/7"),
                                          noite("2026-12-29", 1800, 1800, 1800, 1500, mu="2/7")]})
        self.registrar(rec, "2026-12-26", dias=4)
        vk7 = self.resumo("2026-12-26", "2026-12-26")["quartos"]["VK7"]
        self.assertEqual((vk7["precos"]["semana"]["noites"], vk7["feriado_ou_pacote"]), (0, 4))

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
        vk7 = self.resumo("2026-11-10", "2026-11-10")["quartos"]["VK7"]
        self.assertEqual(vk7["estados"], {"travada": 1, "so_piso": 1, "sem_regra": 0, "ajustada": 0})
        self.assertEqual(vk7["precos"]["semana"]["noites"], 1)
        # calibração: preço real 1060 contra a fórmula max(947, min(1454, 5000)) = 1454; 1 noite não dá fator
        self.assertEqual(vk7["calibracao"]["noites"], 1)
        self.assertAlmostEqual(vk7["calibracao"]["erro_medio_pct"], (1060 / 1454 - 1) * 100, places=1)
        self.assertIsNone(vk7["calibracao"]["fator"])

    def test_noite_esgotada_fica_fora_da_comparacao_e_da_calibracao(self):
        rec = self.rec_dir("r1", {"VK7": [noite("2026-11-10", 1060, 947, 5000, 1454, mu="7/7"),
                                          noite("2026-11-11", 947, 947, 947, 1175, mu="7/7"),
                                          noite("2026-11-12", 1199, 1199, 1199, 1045, mu="2/7")]})
        self.registrar(rec, "2026-11-10", dias=3)
        vk7 = self.resumo("2026-11-10", "2026-11-10")["quartos"]["VK7"]
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
    def rec_padrao(self, nome="r1", vend_12="2/7", inicio_balcony="2026-11-10") -> Path:
        return self.rec_dir(nome, {
            "VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                    noite("2026-11-11", 947, 947, 947, 1175, mu="3/7"),
                    noite("2026-11-12", 1199, 1199, 1199, 1045, mu=vend_12)],
            "Balcony": [noite(inicio_balcony, 698, 698, 698, 794, mu="0/6")]})

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
        # a hora da leitura do PriceLabs é a do rec (coleta), não a do registro
        self.assertEqual(foto1["por_quarto"]["VK7"]["observado_em"], "2026-11-10T10:53:00Z")
        self.assertEqual(foto1["registrado_em"], "2026-11-10T11:30:00Z")

    def test_mesmo_dia_atualiza_e_outro_dia_acrescenta(self):
        self.registrar(self.rec_padrao("r1", "2/7"), "2026-11-10", dias=3)
        self.registrar(self.rec_padrao("r2", "4/7"), "2026-11-10", dias=3)
        d = sf.carregar(self.arq)
        self.assertEqual(len(d["fotos"]), 1)
        self.assertEqual(d["fotos"]["2026-11-10"]["quartos"]["VK7"][2]["vendidas"], 4)
        self.registrar(self.rec_dir("r3", {"VK7": [noite("2026-11-11", 947, 947, 947, 1175, mu="3/7"),
                                                   noite("2026-11-12", 1199, 1199, 1199, 1045, mu="4/7")],
                                           "Balcony": [noite("2026-11-11", 698, 698, 698, 794, mu="0/6")]}),
                       "2026-11-11", dias=2)
        d = sf.carregar(self.arq)
        self.assertEqual(list(d["fotos"]), ["2026-11-10", "2026-11-11"])
        self.assertEqual([e["data"] for e in d["fotos"]["2026-11-11"]["quartos"]["VK7"]], ["2026-11-11", "2026-11-12"])
        # quarto sem rec_<Q>.json fica fora da foto e é anotado com o motivo; noite fora do recálculo também
        f = d["fotos"]["2026-11-11"]
        self.assertEqual(f["faltando"]["Q7"], "sem rec_<Q>.json")
        self.assertEqual(f["noites_sem_dado"], {"Balcony": 1})

    def test_registrar_de_novo_com_menos_quartos_junta_por_quarto(self):
        # Achado: registrar de novo no mesmo dia com menos quartos trocava a foto inteira e apagava os quartos já gravados
        self.registrar(self.rec_padrao("r1", "2/7"), "2026-11-10", dias=3)
        so_vk7 = self.rec_dir("r2", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                                             noite("2026-11-11", 947, 947, 947, 1175, mu="3/7"),
                                             noite("2026-11-12", 1199, 1199, 1199, 1045, mu="4/7")]})
        foto, existia = self.registrar(so_vk7, "2026-11-10", dias=3)
        self.assertTrue(existia)
        self.assertEqual(list(foto["quartos"]), ["VK7", "Balcony"])
        self.assertEqual(foto["quartos_mantidos"], ["Balcony"])
        self.assertNotIn("Balcony", foto["faltando"])
        f = sf.carregar(self.arq)["fotos"]["2026-11-10"]
        self.assertEqual(f["quartos"]["VK7"][2]["vendidas"], 4)
        self.assertEqual(f["quartos"]["Balcony"][0]["gravado"], 698)
        self.assertIn("Balcony", f["pisos"])
        self.assertIn("Balcony", f["por_quarto"])
        texto = sf.texto_registro(foto, existia, self.arq)
        self.assertIn("mantidos como estavam: Balcony", texto)
        # --substituir troca a foto inteira
        foto, _ = self.registrar(so_vk7, "2026-11-10", dias=3, substituir=True)
        self.assertEqual(list(sf.carregar(self.arq)["fotos"]["2026-11-10"]["quartos"]), ["VK7"])
        self.assertNotIn("quartos_mantidos", foto)

    def test_rec_de_outro_dia_vai_para_faltando(self):
        # Achado: --hoje não era conferido com a primeira data do rec
        rec = self.rec_padrao("r1", inicio_balcony="2026-11-11")
        foto, _ = self.registrar(rec, "2026-11-10", dias=3)
        self.assertEqual(list(foto["quartos"]), ["VK7"])
        self.assertTrue(foto["faltando"]["Balcony"].startswith("rec de outro dia"))
        # rec que começa antes de --hoje (coleta de ontem): faltando; com forcar, aceito (reprocessar coleta antiga)
        foto, _ = self.registrar(rec, "2026-11-11", dias=2)
        self.assertEqual(list(foto["quartos"]), ["Balcony"])
        self.assertIn("rec de outro dia", foto["faltando"]["VK7"])
        foto, _ = self.registrar(rec, "2026-11-11", dias=2, forcar=True, substituir=True)
        self.assertEqual(list(foto["quartos"]), ["VK7", "Balcony"])
        self.assertEqual([e["data"] for e in foto["quartos"]["VK7"]], ["2026-11-11", "2026-11-12"])
        # com forcar, rec que começa DEPOIS de --hoje continua de outro dia
        foto, _ = self.registrar(rec, "2026-11-10", dias=3, forcar=True, substituir=True)
        self.assertIn("rec de outro dia", foto["faltando"]["Balcony"])
        # nenhum quarto do dia: erro, nada gravado
        so_amanha = self.rec_dir("r9", {"VK7": [noite("2026-11-12", 1199, 1199, 1199, 1045)]})
        antes = self.arq.read_text(encoding="utf-8")
        with self.assertRaises(sf.ErroSombra) as cm:
            self.registrar(so_amanha, "2026-11-10", dias=3)
        self.assertIn("rec de outro dia", str(cm.exception))
        self.assertEqual(self.arq.read_text(encoding="utf-8"), antes)

    def test_hoje_conferido_com_o_relogio_de_brasilia(self):
        rec = self.rec_dir("r1", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7")]})
        codigo, _, err = self.cli("registrar", "--rec-dir", str(rec), "--hoje", "2026-11-10", "--dias", "1",
                                  "--arquivo", str(self.arq), hoje="2026-11-11")
        self.assertEqual(codigo, 2)
        self.assertIn("não é hoje em Brasília", err)
        self.assertFalse(self.arq.exists())
        codigo, out, err = self.cli("registrar", "--rec-dir", str(rec), "--hoje", "2026-11-10", "--dias", "1",
                                    "--forcar", "--arquivo", str(self.arq), hoje="2026-11-11")
        self.assertEqual(codigo, 0, err)
        self.assertIn("gravada", out)

    def test_arquivo_corrompido_nao_e_regravado(self):
        self.arq.write_text("{ meio arquivo", encoding="utf-8")
        with self.assertRaises(sf.ErroSombra):
            self.registrar(self.rec_padrao(), "2026-11-10", dias=3)
        self.assertEqual(self.arq.read_text(encoding="utf-8"), "{ meio arquivo")
        self.arq.write_text(json.dumps({"fotos": {}, "beds24": {"2026-11-10": {"VK7": [{"lido_em": 1}]}}}),
                            encoding="utf-8")
        with self.assertRaises(sf.ErroSombra):
            self.fechar("2026-11-10T22:00:00Z", {"VK7": {"2026-11-10": 0}})

    def test_pasta_sem_rec(self):
        vazio = self.pasta / "vazio"
        vazio.mkdir()
        with self.assertRaises(sf.ErroSombra):
            self.registrar(vazio, "2026-11-10")


class TestGravadosEAjustes(Base):
    def test_preco_gravado_pelo_plano_depois_da_coleta(self):
        # Achado: a foto era tirada antes da gravação da rotina; o gravado usado nas vendas não era o que valia
        rec = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                                           noite("2026-11-11", 947, 947, 947, 1175, mu="3/7"),
                                           noite("2026-11-12", 1199, 1199, 1199, 1045, mu="2/7"),
                                           noite("2026-11-15", 1021, 1021, 1021, 1264, mu="3/7"),
                                           noite("2026-11-16", 1100, 1100, 1100, 1200, mu="3/7")],
                                   "Balcony": [noite("2026-11-10", 698, 698, 698, 794, mu="0/6")]})
        # gravados às 08:04 de 10/11 (depois da coleta das 07:53)
        plano1 = escrever_plano(self.pasta / "plano_pace.json", {"itens": [
            {"tipo": "data", "quarto": "VK7", "data": "2026-11-11", "min_price": 1175, "max_price": 1175, "motivo": "x"},
            {"tipo": "data", "quarto": "VK7", "data": "2026-11-12", "min_price": 1000, "motivo": "só piso"},
            {"tipo": "data", "quarto": "VK7", "data": "2026-11-16", "min_stay": 2, "motivo": "só estadia"},
            {"tipo": "data", "quarto": CFG["por_curto"]["Balcony"]["id"], "data": "2026-11-10", "min_price": 650,
             "max_price": 650, "motivo": "listing_id"}]}, "2026-11-10T11:04:00Z")
        plano2 = escrever_plano(self.pasta / "plano_faixa.json",
                                [{"tipo": "apagar", "quarto": "VK7", "data": "2026-11-15", "motivo": "y"}],
                                "2026-11-10T11:04:30Z")
        foto, _ = self.registrar(rec, "2026-11-10", dias=7, gravados=sf.ler_gravados([plano1, plano2]))
        n = {e["data"]: e for e in foto["quartos"]["VK7"]}
        # o valor do plano vira o que o PriceLabs cobra (percent_base sobre a base): R$ 1.175 na VK7 vira R$ 1.184
        g11 = cobrado("VK7", 1175)
        self.assertEqual(g11, 1184)
        self.assertEqual((n["2026-11-11"]["gravado"], n["2026-11-11"]["estado"], n["2026-11-11"]["gravado_fonte"],
                          n["2026-11-11"]["gravado_rec"], n["2026-11-11"]["gravado_em"]),
                         (g11, "travada", "plano", 947, "2026-11-10T11:04:00Z"))
        self.assertEqual([(t["em"], t["gravado"], t["gravado_fonte"]) for t in n["2026-11-11"]["trocas"]],
                         [("2026-11-10T10:53:00Z", 947, "rec"), ("2026-11-10T11:04:00Z", g11, "plano")])
        self.assertEqual((n["2026-11-12"]["estado"], n["2026-11-12"]["gravado"], n["2026-11-12"]["min"]),
                         ("so_piso", None, cobrado("VK7", 1000)))
        self.assertEqual((n["2026-11-15"]["estado"], n["2026-11-15"]["gravado"]), ("sem_regra", None))
        self.assertEqual((n["2026-11-16"]["gravado"], n["2026-11-16"]["gravado_fonte"]), (1100, "rec"))
        self.assertNotIn("trocas", n["2026-11-16"])
        self.assertEqual((n["2026-11-10"]["gravado"], n["2026-11-10"]["gravado_fonte"]), (1199, "rec"))
        self.assertEqual(foto["quartos"]["Balcony"][0]["gravado"], cobrado("Balcony", 650))
        self.assertEqual(foto["gravados"]["noites"], 4)
        self.assertEqual(foto["por_quarto"]["VK7"]["gravados_do_plano"], 3)
        # a venda seguinte entra com o preço do plano (o que valia), não com o do rec: solto 1.175 < 1.184
        r11 = self.rec_dir("r11", {"VK7": [noite("2026-11-11", g11, g11, g11, 1175, mu="4/7")]})
        self.registrar(r11, "2026-11-11", dias=1)
        v = self.resumo("2026-11-10", "2026-11-11")["quartos"]["VK7"]["vendas"]
        self.assertEqual([(x["noite"], x["gravado"], x["gravado_fonte"], x["na_mesa"], x["mais_barata"]) for x in v],
                         [("2026-11-11", g11, "plano", 0, g11 - 1175)])
        # pela linha de comando, --gravados pode repetir
        codigo, out, err = self.cli("registrar", "--rec-dir", str(rec), "--hoje", "2026-11-10", "--dias", "7",
                                    "--gravados", str(plano1), "--gravados", str(plano2), "--arquivo",
                                    str(self.pasta / "cli.json"))
        self.assertEqual(codigo, 0, err)
        self.assertIn("Preço gravado pelo plano", out)
        codigo, _, err = self.cli("registrar", "--rec-dir", str(rec), "--hoje", "2026-11-10", "--gravados",
                                  str(self.pasta / "nao_existe.json"), "--arquivo", str(self.pasta / "cli.json"))
        self.assertEqual(codigo, 2)
        self.assertIn("--gravados", err)

    def test_plano_vale_da_hora_em_que_foi_gravado(self):
        # Achado: o plano da rotina leve (10:53) era aplicado à foto das 07:53 e dava o preço novo a vendas feitas antes
        rec = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                                           noite("2026-11-24", 1199, 1199, 1199, 1300, mu="2/7"),
                                           noite("2026-11-25", 1199, 1199, 1199, 1300, mu="0/7")]})
        escrever_inv(rec, "2026-11-10T10:52:00Z", {"VK7": {"2026-11-10": 2, "2026-11-24": 5, "2026-11-25": 7}})
        p0 = escrever_plano(self.pasta / "p0.json", [{"tipo": "data", "quarto": "VK7", "data": "2026-11-25",
                                                      "min_price": 999, "max_price": 999}], "2026-11-10T10:00:00Z")
        ontem = escrever_plano(self.pasta / "ontem.json", [{"tipo": "data", "quarto": "VK7", "data": "2026-11-24",
                                                            "min_price": 2000, "max_price": 2000}], "2026-11-09T20:00:00Z")
        p1 = escrever_plano(self.pasta / "p1.json", [{"tipo": "data", "quarto": "VK7", "data": "2026-11-24",
                                                      "min_price": 1350, "max_price": 1350}], "2026-11-10T11:04:10Z")
        # o da rotina das 10:53, com a hora dada à mão (o arquivo foi copiado depois)
        p2 = escrever_plano(self.pasta / "p2.json", [{"tipo": "data", "quarto": "VK7", "data": "2026-11-24",
                                                      "min_price": 1500, "max_price": 1500},
                                                     {"tipo": "data", "quarto": "VK7", "data": "2026-11-25",
                                                      "min_price": 1350, "max_price": 1350}], "2026-11-11T09:00:00Z")
        gravados = sf.ler_gravados([p0, ontem, p1, f"{p2}@2026-11-10T13:56:49Z"])
        foto, _ = self.registrar(rec, "2026-11-10", dias=16, gravados=gravados,
                                 agora=datetime(2026, 11, 10, 14, 30, tzinfo=timezone.utc))
        planos = {Path(x["arquivo"]).name: x for x in foto["gravados"]["planos"]}
        self.assertEqual((planos["p0.json"]["noites"], planos["p0.json"]["ja_no_rec"]), (0, 1))
        self.assertIn("fora do dia da foto", planos["ontem.json"]["ignorado"])
        self.assertEqual((planos["p1.json"]["noites"], planos["p2.json"]["noites"]), (1, 2))
        self.assertEqual(planos["p2.json"]["gravado_em"], "2026-11-10T13:56:49Z")
        c1350, c1500 = cobrado("VK7", 1350), cobrado("VK7", 1500)
        n = {e["data"]: e for e in foto["quartos"]["VK7"]}
        self.assertEqual([(t["em"], t["gravado"]) for t in n["2026-11-24"]["trocas"]],
                         [("2026-11-10T10:53:00Z", 1199), ("2026-11-10T11:04:10Z", c1350),
                          ("2026-11-10T13:56:49Z", c1500)])
        self.assertEqual((n["2026-11-24"]["gravado"], n["2026-11-25"]["gravado"]), (c1500, c1350))
        # Beds24 às 10:55 (antes do plano das 10:56) e às 16:54
        self.fechar("2026-11-10T13:55:53Z", {"VK7": {"2026-11-24": 4, "2026-11-25": 6}}, dias=16)
        self.fechar("2026-11-10T19:54:00Z", {"VK7": {"2026-11-24": 3, "2026-11-25": 5}}, dias=16)
        r = self.resumo("2026-11-10", "2026-11-10")
        vendas = [(v["noite"], v["entre"][1][11:16], v["gravado"], v["gravado_fonte"], v.get("gravado_em"),
                   bool(v.get("troca_no_intervalo"))) for v in r["quartos"]["VK7"]["vendas"]]
        self.assertEqual(vendas, [
            # vista às 10:55: valia o plano das 08:04 (o das 10:56 ainda não)
            ("2026-11-24", "13:55", c1350, "plano", "2026-11-10T11:04:10Z", True),
            # vista às 16:54: vale o plano das 10:56
            ("2026-11-24", "19:54", c1500, "plano", "2026-11-10T13:56:49Z", True),
            # 25/11 vista às 10:55: ainda o preço do rec (o plano das 07:00 é anterior à leitura e o rec já o tem)
            ("2026-11-25", "13:55", 1199, "rec", None, False),
            ("2026-11-25", "19:54", c1350, "plano", "2026-11-10T13:56:49Z", True)])
        # os preços do resumo são os do fim do dia
        self.assertEqual(r["quartos"]["VK7"]["precos"]["semana"]["gravado_medio"], round((1199 + c1500 + c1350) / 3, 2))
        texto = sf.texto_resumo(r, CFG)
        self.assertIn("preço do plano gravado em 10/11 08:04", texto)
        self.assertIn("troca de preço no intervalo", texto)
        reg = sf.texto_registro(foto, False, self.arq, cfg=CFG)
        self.assertIn("p2.json (gravado em 10/11 10:56): 2 noite(s)", reg)
        self.assertIn("1 já no rec", reg)
        self.assertIn("ignorado, gravado em 09/11 17:00, fora do dia da foto", reg)

    def test_plano_convertido_como_a_gravacao(self):
        # Achado: o gravado era o valor em reais do plano, não o que o PriceLabs cobra depois do percent_base
        self.assertEqual(sf.em_reais_do_plano(1480, 1232, 1232), (1243, 1243))  # Double 09/11 real: cobra R$ 1.243
        self.assertEqual(sf.em_reais_do_plano(1480, 1351, 1351), (1362, 1362))  # Double 24/11 real: R$ 1.362
        self.assertEqual(sf.em_reais_do_plano(1270, 1285, 1285), (1295, 1295))  # VK2 23/11: o do rec (R$ 1.295)
        self.assertEqual(sf.em_reais_do_plano(1480, 1000, 1500), (1006, 1495))  # piso para cima, teto para baixo
        self.assertEqual(sf.em_reais_do_plano(None, 1232, 1232), (1232, 1232))
        # a base vem do --contexto quando há; senão, do quartos.json
        self.assertEqual(sf.limites_do_quarto(CFG, "Double")["base"], CFG["por_curto"]["Double"]["base"])
        rec = self.rec_dir("r10", {"Double": [noite("2026-11-10", 1495, 1495, 1495, 1071, mu="2/6")]})
        plano = escrever_plano(self.pasta / "p.json", [{"tipo": "data", "quarto": "Double", "data": "2026-11-10",
                                                        "min_price": 1232, "max_price": 1232}], "2026-11-10T11:04:10Z")
        foto, _ = self.registrar(rec, "2026-11-10", dias=1, gravados=sf.ler_gravados([plano]))
        e = foto["quartos"]["Double"][0]
        self.assertEqual((e["gravado"], e["min"], e["max"], e["estado"], e["gravado_rec"]),
                         (cobrado("Double", 1232), cobrado("Double", 1232), cobrado("Double", 1232), "travada", 1495))
        foto, _ = self.registrar(rec, "2026-11-10", dias=1, gravados=sf.ler_gravados([plano]),
                                 contexto={"Double": {"min": 1035, "base": 1500, "max": 5000}})
        self.assertEqual(foto["quartos"]["Double"][0]["gravado"], cobrado("Double", 1232, base=1500))
        self.assertEqual(foto["pisos"]["Double"]["base"], 1500)

    def test_ler_gravados_hora_do_arquivo_ou_dada(self):
        plano = escrever_plano(self.pasta / "plano@v2.json", [{"tipo": "data", "quarto": "VK7", "data": "2026-11-10",
                                                               "min_price": 1000}], "2026-11-10T11:04:10Z")
        self.assertEqual(sf.ler_gravados([plano])[0]["_gravado_em"], "2026-11-10T11:04:10Z")
        it = sf.ler_gravados([f"{plano}@2026-11-10T13:56:49Z"])[0]
        self.assertEqual((it["_gravado_em"], it["_arquivo"]), ("2026-11-10T13:56:49Z", str(plano)))

    def tres_flutuando(self, nome: str, ov: dict | None = None) -> Path:
        # 3 noites que flutuam (so_piso) com preço real ÷ fórmula = 0,8, 0,9 e 1,0; uma quarta com o nosso -5%;
        # noites travadas a R$ 1.199 com uncustomized 1.300 (solto bruto 1.300; calibrado 1.300 × 0,9 = 1.170)
        return self.rec_dir(nome, {"VK7": [
            noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
            noite("2026-11-11", 1199, 1199, 1199, 1300, mu="3/7"),
            noite("2026-11-12", 1200, 947, 5000, 1500, mu="2/7"),
            noite("2026-11-15", 1350, 947, 5000, 1500, mu="2/7"),
            noite("2026-11-16", 1500, 947, 5000, 1500, mu="2/7"),
            noite("2026-11-17", 900, 800, 5000, 1500, mu="2/7"),
            noite("2026-11-18", 1199, 1199, 1199, 1300, mu="2/7")]}, ov)

    def test_calibracao_pelo_fator_mediano_e_solto_calibrado(self):
        # Achado: os números principais usavam o solto bruto, e a calibração do resumo invertia o sinal
        self.registrar(self.tres_flutuando("r10", {"VK7": []}), "2026-11-10", dias=9)
        r11 = self.rec_dir("r11", {"VK7": [noite("2026-11-11", 1199, 1199, 1199, 1300, mu="4/7"),
                                           noite("2026-11-12", 1200, 947, 5000, 1500, mu="2/7"),
                                           noite("2026-11-15", 1350, 947, 5000, 1500, mu="2/7"),
                                           noite("2026-11-16", 1500, 947, 5000, 1500, mu="2/7"),
                                           noite("2026-11-17", 900, 800, 5000, 1500, mu="2/7"),
                                           noite("2026-11-18", 1199, 1199, 1199, 1300, mu="2/7")]})
        self.registrar(r11, "2026-11-11", dias=8)
        vk7 = self.resumo("2026-11-10", "2026-11-11")["quartos"]["VK7"]
        cal = vk7["calibracao"]
        # 17/11 (900 contra a fórmula 1.500) também flutua: razões 0,8; 0,9; 1,0; 0,6 → mediana 0,85
        self.assertEqual(cal["noites"], 4)
        self.assertAlmostEqual(cal["fator"], 0.85, places=4)
        p = vk7["precos"]["semana"]
        self.assertEqual((p["solto_medio"], p["acima"]["noites"]), (1300, 3))
        self.assertEqual((p["calibrado"]["solto_medio"], p["calibrado"]["abaixo"]["noites"]), (1105, 3))
        # venda na 11/11 (gravado 1.199): bruto 101 na mesa; calibrado 1.105 → venderia R$ 94 mais barato
        t = vk7["vendas_total"]
        self.assertEqual((t["dinheiro_na_mesa"], t["dinheiro_na_mesa_cal"], t["mais_barata_no_solto_cal"]), (101, 0, 94))
        enc = {e["noite"]: e for e in vk7["encalhadas"]}
        self.assertEqual(enc["2026-11-10"]["solto_cal"], 1105)
        self.assertEqual(vk7["encalhadas_total"]["solto_mais_barato_cal"], 1)
        texto = sf.texto_resumo(self.resumo("2026-11-10", "2026-11-11"), CFG)
        destaque = texto[texto.index("## VK7"):texto.index("## Preço por quarto")]
        self.assertIn("Calibração: fator 0,850", destaque)
        linha = next(x for x in destaque.splitlines() if x.startswith("- Vendas novas nos dias de semana"))
        self.assertIn("mesmo volume de vendas (limite otimista)", linha)
        self.assertLess(linha.index("calibrado"), linha.index("bruto"))
        self.assertIn("dinheiro na mesa R$ 0 calibrado (bruto R$ 101)", linha)
        linha = next(x for x in destaque.splitlines() if x.startswith("- Dias de semana"))
        self.assertLess(linha.index("solto calibrado"), linha.index("solto bruto"))
        self.assertIn("solto calibrado", sf.NOTA_ESTIMATIVA)
        self.assertIn("limite otimista", sf.NOTA_ESTIMATIVA)

    def test_calibracao_indicativa_com_menos_de_tres_noites(self):
        # Achado 8 no caso real: com 2 noites medidas a VK7 ficava "sem calibração" e a manchete saía só no bruto
        # (+12,2%), escondendo que a própria medição (preço real 24,6% abaixo da fórmula) invertia o sinal
        rec = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                                           noite("2026-11-12", 1200, 947, 5000, 1500, mu="2/7"),
                                           noite("2026-11-15", 1350, 947, 5000, 1500, mu="2/7")]}, {"VK7": []})
        self.registrar(rec, "2026-11-10", dias=6)
        r = self.resumo("2026-11-10", "2026-11-10")
        vk7 = r["quartos"]["VK7"]
        cal = vk7["calibracao"]
        # razões 0,8 e 0,9: fator indicativo 0,85 (sem fator medido)
        self.assertEqual((cal["fator"], cal["fator_indicativo"], cal["fator_usado"], cal["tipo"], cal["noites"]),
                         (None, 0.85, 0.85, "indicativa", 2))
        p = vk7["precos"]["semana"]
        self.assertEqual((p["solto_medio"], p["calibrado"]["solto_medio"]), (1300, 1105))
        self.assertGreater(p["dif_pct"], 0)
        self.assertLess(p["calibrado"]["dif_pct"], 0)
        texto = sf.texto_resumo(r, CFG)
        destaque = texto[texto.index("## VK7"):texto.index("## Preço por quarto")]
        self.assertIn("Calibração INDICATIVA: fator 0,850", destaque)
        self.assertIn("só 2 noite(s)", destaque)
        self.assertIn("preço real -15,0% em média contra a fórmula", destaque)
        alerta = next(x for x in destaque.splitlines() if x.startswith("- ATENÇÃO"))
        self.assertIn("o bruto e o indicativo apontam para lados opostos", alerta)
        self.assertIn("+8,4% no bruto e -7,8% no indicativo", alerta)
        self.assertIn("não decida pelo bruto", alerta)
        linha = next(x for x in destaque.splitlines() if x.startswith("- Dias de semana"))
        self.assertLess(linha.index("solto indicativo R$ 1.105"), linha.index("solto bruto R$ 1.300"))
        self.assertNotIn("Os números ficam só no solto bruto", destaque)
        self.assertIn("R$ 1.105 (-7,8%) ind.", texto)
        self.assertIn("0,850 (indicativo, 2 noite(s))", texto)
        # nenhuma noite medida: sem calibração, só o bruto, sem alerta
        self.arq.unlink()
        rec = self.rec_dir("r10b", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7")]}, {"VK7": []})
        self.registrar(rec, "2026-11-10", dias=1)
        r = self.resumo("2026-11-10", "2026-11-10")
        vk7 = r["quartos"]["VK7"]
        self.assertEqual((vk7["calibracao"]["fator_usado"], vk7["calibracao"]["tipo"]), (None, None))
        self.assertIsNone(vk7["precos"]["semana"]["calibrado"])
        self.assertIsNone(vk7["vendas_total"]["dinheiro_na_mesa_cal"])
        texto = sf.texto_resumo(r, CFG)
        self.assertIn("Sem calibração: nenhuma noite", texto)
        self.assertIn("(sem calibração)", texto)
        self.assertNotIn("ATENÇÃO", texto)

    def test_alerta_quando_o_saldo_das_vendas_muda_de_lado(self):
        p = {"noites": 0}
        cal = {"tipo": "medida", "noites": 4}
        v = {"vendas": 2, "dinheiro_na_mesa": 300, "mais_barata_no_solto": 0, "dinheiro_na_mesa_cal": 0,
             "mais_barata_no_solto_cal": 120}
        alerta = sf._alerta_sinal(p, v, cal, "calibrado")
        self.assertIn("R$ 300 no bruto e R$ -120 no calibrado", alerta)
        self.assertIn("vale o calibrado", alerta)
        v.update(dinheiro_na_mesa_cal=50, mais_barata_no_solto_cal=0)
        self.assertIsNone(sf._alerta_sinal(p, v, cal, "calibrado"))
        self.assertIsNone(sf._alerta_sinal(p, v, cal, None))

    def test_nosso_ajuste_percentual_fica_fora_da_calibracao(self):
        # Achado: a calibração contava como flutuação real noites com o nosso ajuste percentual na regra por data
        ov = {"VK7": [{"date": "2026-11-17", "price": "-5", "price_type": "percent", "min_price": -46,
                       "min_price_type": "percent_base", "reason": "teste"},
                      {"date": "2026-11-10", "price": "-15", "price_type": "percent", "min_price": -19,
                       "min_price_type": "percent_base", "max_price": -19, "max_price_type": "percent_base"},
                      {"date": "2026-11-12", "price": "0", "price_type": "percent", "min_price": -36,
                       "min_price_type": "percent_base"}]}
        foto, _ = self.registrar(self.tres_flutuando("r10", ov), "2026-11-10", dias=9)
        n = {e["data"]: e for e in foto["quartos"]["VK7"]}
        self.assertEqual(n["2026-11-17"]["estado"], "ajustada")
        self.assertEqual(n["2026-11-10"]["estado"], "travada")  # travada com percentual: o preço é o min = max
        self.assertEqual(n["2026-11-12"]["estado"], "so_piso")  # percentual zero não é ajuste
        self.assertTrue(foto["por_quarto"]["VK7"]["ov_pl"])
        r = self.resumo("2026-11-10", "2026-11-10")
        cal = r["quartos"]["VK7"]["calibracao"]
        self.assertEqual((cal["noites"], cal["ajustadas"], cal["fator"], cal["ajustes_desconhecidos"]), (3, 1, 0.9, False))
        self.assertEqual(r["quartos"]["VK7"]["estados"]["ajustada"], 1)
        self.assertNotIn("pode incluir nossos ajustes", sf.texto_resumo(r, CFG))
        # sem o ov_pl_<Q>.json a noite não é reconhecida, e o texto avisa
        self.arq.unlink()
        foto, _ = self.registrar(self.tres_flutuando("r10b"), "2026-11-10", dias=9)
        self.assertFalse(foto["por_quarto"]["VK7"]["ov_pl"])
        r = self.resumo("2026-11-10", "2026-11-10")
        self.assertTrue(r["quartos"]["VK7"]["calibracao"]["ajustes_desconhecidos"])
        self.assertIn("a calibração pode incluir nossos ajustes", sf.texto_resumo(r, CFG))
        self.assertIn("calibração pode incluir nossos ajustes", sf.texto_registro(foto, False, self.arq))


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
        return self.resumo("2026-11-10", "2026-11-12")

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

    def test_vk7_separa_semana_e_fim_de_semana(self):
        # Achado: o destaque da VK7 somava venda de sábado num teste que só destrava os dias de semana
        r = self.duas_fotos()
        vk7 = r["quartos"]["VK7"]
        sem, fds = vk7["vendas_total"]["por_tipo"]["semana"], vk7["vendas_total"]["por_tipo"]["fds"]
        self.assertEqual((sem["unidades"], sem["dinheiro_na_mesa"], sem["mais_barata_no_solto"]), (1, 101, 0))
        self.assertEqual((fds["unidades"], fds["dinheiro_na_mesa"], fds["mais_barata_no_solto"]), (2, 0, 876))
        enc = vk7["encalhadas_total"]["por_tipo"]
        self.assertEqual((enc["semana"]["noites"], enc["fds"]["noites"]), (2, 0))
        texto = sf.texto_resumo(r, CFG)
        destaque = texto[texto.index("## VK7"):texto.index("## Preço por quarto")]
        manchete = next(x for x in destaque.splitlines() if x.startswith("- Vendas novas nos dias de semana"))
        self.assertIn("1 unidade(s)", manchete)
        self.assertNotIn("876", manchete)
        self.assertNotIn("noite 13/11", destaque)  # a venda da sexta não aparece na lista dos dias de semana
        fora = next(x for x in destaque.splitlines() if x.startswith("- Fora do teste"))
        self.assertIn("R$ 876", fora)
        self.assertIn("2 unidade(s)", fora)

    def test_noites_que_passaram_sem_venda(self):
        r = self.duas_fotos()
        enc = {e["noite"]: e for e in r["quartos"]["VK7"]["encalhadas"]}
        # 10/11 (vista só na própria foto, 2 livres) e 11/11 (4 livres, nenhuma venda); 12/11 é a última foto: não passou
        self.assertEqual(set(enc), {"2026-11-10", "2026-11-11"})
        self.assertEqual((enc["2026-11-11"]["livres"], enc["2026-11-11"]["vendas_no_periodo"]), (4, 0))
        self.assertIsNone(enc["2026-11-10"]["leitura_do_fim"])
        t = r["quartos"]["VK7"]["encalhadas_total"]
        self.assertEqual((t["noites"], t["unidades_livres"], t["sem_venda_no_periodo"]), (2, 6, 2))
        self.assertEqual(t["sem_leitura_do_fim_do_dia"], 2)
        texto = sf.texto_resumo(r, CFG)
        self.assertIn("2 de 7 livres às 07:53 do dia (sem leitura do fim do dia)", texto)
        self.assertNotIn("passou com", texto)

    def test_precos_pela_ultima_foto_de_cada_noite(self):
        p = self.duas_fotos()["quartos"]["VK7"]["precos"]
        # semana: 10/11 (1.199 → 1.300), 11/11 (947 → 1.175), 12/11 da última foto (1.250 → 1.300)
        self.assertEqual(p["semana"]["noites"], 3)
        self.assertEqual(p["semana"]["acima"]["noites"], 3)
        self.assertEqual(p["semana"]["gravado_medio"], round((1199 + 947 + 1250) / 3, 2))
        self.assertEqual((p["fds"]["noites"], p["fds"]["abaixo"]["noites"]), (1, 1))
        self.assertAlmostEqual(p["fds"]["abaixo"]["media_pct"], (2418 / 2900 - 1) * 100, places=1)

    def test_foto_sem_o_quarto_nao_apaga_vendas_nem_cancelamentos(self):
        # Achado: quarto ausente ou vazio numa foto do meio fazia sumir as vendas e os cancelamentos dos dois intervalos
        r10 = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                                            noite("2026-11-12", 1199, 1199, 1199, 1300, mu="2/7"),
                                            noite("2026-11-13", 2856, 2856, 2856, 2418, mu="5/7")],
                                    "Balcony": [noite("2026-11-10", 698, 698, 698, 794, mu="0/6")]})
        r11 = self.rec_dir("r11", {"Balcony": [noite("2026-11-11", 698, 698, 698, 794, mu="1/6")],
                                   "VK7": []})
        r12 = self.rec_dir("r12", {"VK7": [noite("2026-11-12", 1250, 1250, 1250, 1300, mu="4/7"),
                                           noite("2026-11-13", 2900, 2900, 2900, 2418, mu="4/7")],
                                   "Balcony": [noite("2026-11-12", 698, 698, 698, 794, mu="1/6")]})
        self.registrar(r10, "2026-11-10", dias=4)
        self.registrar(r11, "2026-11-11", dias=3)  # rec da VK7 vazio: fica de fora da foto
        self.registrar(r12, "2026-11-12", dias=2)
        self.assertNotIn("VK7", sf.carregar(self.arq)["fotos"]["2026-11-11"]["quartos"])
        vk7 = self.resumo("2026-11-10", "2026-11-12")["quartos"]["VK7"]
        self.assertEqual([(v["noite"], v["unidades"], v["entre"], v["gravado"]) for v in vk7["vendas"]],
                         [("2026-11-12", 2, ["2026-11-10", "2026-11-12"], 1199)])
        self.assertEqual(vk7["canceladas"], 1)

    def test_texto_destaca_vk7_e_json_pela_linha_de_comando(self):
        self.duas_fotos()
        codigo, out, err = self.cli("resumo", "--de", "2026-11-10", "--ate", "2026-11-12", "--arquivo", str(self.arq))
        self.assertEqual(codigo, 0, err)
        self.assertIn("## VK7", out)
        self.assertLess(out.index("## VK7"), out.index("## Preço por quarto"))
        self.assertIn("ESTIMATIVA", out)
        self.assertIn("uncustomized_price", out)
        self.assertIn("## Limitações", out)
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


class TestBaseBeds24(Base):
    """Achados 1 e 4 da revisão: a diferença entre o PriceLabs e o Beds24 que já existia na hora da foto virava venda
    nova depois da foto. As vendas depois da foto agora são Beds24 contra Beds24, a partir da base da foto."""

    def test_diferenca_que_ja_existia_na_foto_nao_vira_venda(self):
        # caso real de 09/11: a Afrodite com 10/11 e 11/11 ocupadas no Beds24 (reserva de R$ 0 que o PriceLabs não vê)
        rec = self.rec_dir("r10", {"Afrodite": [noite("2026-11-10", 1740, 1740, 1740, 1735, mu="-1", nb="0"),
                                                noite("2026-11-11", 1500, 1500, 1500, 1742, mu="-1", nb="0"),
                                                noite("2026-11-12", 1800, 1800, 1800, 1799, mu="-1", nb="0")]})
        # a leitura do Beds24 da coleta (07:52, antes do rec das 07:53) já mostra as duas noites ocupadas
        escrever_inv(rec, "2026-11-10T10:52:00Z", {"Afrodite": {"2026-11-10": 0, "2026-11-11": 0, "2026-11-12": 1}})
        foto, _ = self.registrar(rec, "2026-11-10", dias=3)
        self.assertEqual(foto["base_beds24"]["lido_em"], "2026-11-10T10:52:00Z")
        self.fechar("2026-11-10T13:55:53Z", {"Afrodite": {"2026-11-10": 0, "2026-11-11": 0, "2026-11-12": 1}})
        self.fechar("2026-11-10T19:54:00Z", {"Afrodite": {"2026-11-10": 0, "2026-11-11": 0, "2026-11-12": 0}})
        r = self.resumo("2026-11-10", "2026-11-10")
        af = r["quartos"]["Afrodite"]
        # nada vendido depois da foto em 10 e 11/11; a 12/11 vendeu às 16:54 (Beds24 contra Beds24)
        self.assertEqual([(v["noite"], v["unidades"], v["entre"]) for v in af["vendas"]],
                         [("2026-11-12", 1, ["2026-11-10T13:55:53Z", "2026-11-10T19:54:00Z"])])
        self.assertEqual(af["vendas_total"]["dinheiro_na_mesa"], 0)
        self.assertEqual([(d["noite"], d["pricelabs"], d["beds24"], d["base"]) for d in af["divergencias"]],
                         [("2026-11-10", 0, 1, True), ("2026-11-11", 0, 1, True)])
        self.assertEqual(af["divergencias_total"], {"na_foto": 2, "beds24_acima": 2, "sem_base": 0,
                                                    "unidades_sem_base": 0})
        self.assertEqual(r["bases_beds24"], ["2026-11-10T10:52:00Z"])
        texto = sf.texto_resumo({**r, "destaque": "Afrodite"}, CFG)
        self.assertIn("## Diferença PriceLabs × Beds24 (fora das vendas)", texto)
        self.assertIn("10/11 (PriceLabs 0, Beds24 1)", texto)
        self.assertIn("não é venda nova", texto)

    def test_noite_ocupada_no_beds24_na_foto_nao_passa_livre(self):
        # a Afrodite 10/11 ocupada no Beds24 (reserva de R$ 0) e livre no PriceLabs: sem leitura do fim do dia, a noite
        # que passou fica com a base do Beds24 da foto, não com o PriceLabs
        r10 = self.rec_dir("r10", {"Afrodite": [noite("2026-11-10", 1740, 1740, 1740, 1735, mu="-1", nb="0"),
                                                noite("2026-11-11", 1500, 1500, 1500, 1742, mu="-1", nb="0")]})
        escrever_inv(r10, "2026-11-10T10:52:00Z", {"Afrodite": {"2026-11-10": 0, "2026-11-11": 1}})
        self.registrar(r10, "2026-11-10", dias=2)
        self.registrar(self.rec_dir("r11", {"Afrodite": [noite("2026-11-11", 1500, 1500, 1500, 1742, mu="-1",
                                                               nb="0")]}), "2026-11-11", dias=1)
        af = self.resumo("2026-11-10", "2026-11-11")["quartos"]["Afrodite"]
        self.assertEqual(af["encalhadas"], [])
        self.assertEqual(af["vendas"], [])

    def test_beds24_sem_mudanca_nao_tem_venda(self):
        # caso sintético da revisão: VK7 3/7 no PriceLabs, numAvail 3 no Beds24 às 07:52 e às 16:54 (nada mudou)
        rec = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1000, 1000, 1000, 1300, mu="3/7"),
                                           noite("2026-11-11", 1000, 1000, 1000, 1300, mu="3/7")]})
        self.registrar(rec, "2026-11-10", dias=2)
        self.fechar("2026-11-10T10:52:00Z", {"VK7": {"2026-11-10": 3, "2026-11-11": 3}})
        self.fechar("2026-11-10T19:54:00Z", {"VK7": {"2026-11-10": 3, "2026-11-11": 3}})
        r = self.resumo("2026-11-10", "2026-11-10")
        vk7 = r["quartos"]["VK7"]
        self.assertEqual(vk7["vendas"], [])
        self.assertEqual(vk7["vendas_total"]["por_tipo"]["semana"]["dinheiro_na_mesa"], 0)
        self.assertEqual(vk7["divergencias_total"]["na_foto"], 2)
        texto = sf.texto_resumo(r, CFG)
        destaque = texto[texto.index("## VK7"):texto.index("## Preço por quarto")]
        self.assertIn("nenhuma venda nova em noite travada", destaque)
        self.assertIn("- Diferença PriceLabs × Beds24 (fora das vendas): 2 noite(s)", destaque)

    def test_sem_base_o_salto_da_foto_fica_fora_das_vendas(self):
        rec = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1000, 1000, 1000, 1300, mu="3/7"),
                                           noite("2026-11-11", 1000, 1000, 1000, 1300, mu="3/7")]})
        foto, _ = self.registrar(rec, "2026-11-10", dias=2)
        self.assertIn("sem inv.json", foto["base_beds24"]["sem_base"])
        self.fechar("2026-11-10T19:54:00Z", {"VK7": {"2026-11-11": 3}})  # 4 vendidas no Beds24 contra 3 no PriceLabs
        self.fechar("2026-11-10T22:54:00Z", {"VK7": {"2026-11-11": 2}})  # +1 de verdade (Beds24 contra Beds24)
        vk7 = self.resumo("2026-11-10", "2026-11-10")["quartos"]["VK7"]
        self.assertEqual([(v["noite"], v["unidades"], v["entre"]) for v in vk7["vendas"]],
                         [("2026-11-11", 1, ["2026-11-10T19:54:00Z", "2026-11-10T22:54:00Z"])])
        self.assertEqual([(d["noite"], d["pricelabs"], d["beds24"], d["base"]) for d in vk7["divergencias"]],
                         [("2026-11-11", 3, 4, False)])
        self.assertEqual((vk7["divergencias_total"]["sem_base"], vk7["divergencias_total"]["unidades_sem_base"]), (1, 1))
        texto = sf.texto_resumo(self.resumo("2026-11-10", "2026-11-10"), CFG)
        self.assertIn("1 salto(s) da foto para o Beds24 sem leitura de base", texto)
        self.assertIn("Sem base do Beds24 na foto", sf.texto_registro(foto, False, self.arq))

    def test_venda_entre_a_base_e_a_foto_nao_entra_duas_vezes(self):
        # foto de 10/11 lida às 10:55 (13:55 UTC) e base do Beds24 das 07:55: a venda entre as duas aparece no PriceLabs
        # (foto 09/11 → foto 10/11) e não pode entrar de novo na leitura das 16:54
        self.registrar(self.rec_dir("r09", {"VK7": [noite("2026-11-09", 1199, 1199, 1199, 1300, mu="5/7"),
                                                    noite("2026-11-12", 1199, 1199, 1199, 1300, mu="5/7")]}),
                       "2026-11-09", dias=4)
        r10 = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                                           noite("2026-11-12", 1199, 1199, 1199, 1300, mu="6/7")]})
        escrever_inv(r10, "2026-11-10T10:55:00Z", {"VK7": {"2026-11-10": 2, "2026-11-12": 2}})
        self.registrar(r10, "2026-11-10", dias=3, coleta=datetime(2026, 11, 10, 13, 55, tzinfo=timezone.utc))
        self.fechar("2026-11-10T19:54:00Z", {"VK7": {"2026-11-10": 2, "2026-11-12": 1}})
        vk7 = self.resumo("2026-11-09", "2026-11-10")["quartos"]["VK7"]
        self.assertEqual([(v["noite"], v["unidades"], v["fonte"]) for v in vk7["vendas"]], [("2026-11-12", 1, "foto")])

    def test_base_ate_30_minutos_depois_da_foto(self):
        rec = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1000, 1000, 1000, 1300, mu="3/7"),
                                           noite("2026-11-11", 1000, 1000, 1000, 1300, mu="3/7")]})
        self.registrar(rec, "2026-11-10", dias=2)
        self.fechar("2026-11-10T11:10:00Z", {"VK7": {"2026-11-11": 3}})  # 17 minutos depois da foto: é a base
        self.fechar("2026-11-10T19:54:00Z", {"VK7": {"2026-11-11": 3}})
        vk7 = self.resumo("2026-11-10", "2026-11-10")["quartos"]["VK7"]
        self.assertEqual(vk7["vendas"], [])
        self.assertEqual(vk7["divergencias_total"]["na_foto"], 1)
        # 2 horas depois já não é base: o salto da foto para ela fica de fora, como diferença sem base
        self.arq.unlink()
        self.registrar(rec, "2026-11-10", dias=2)
        self.fechar("2026-11-10T13:00:00Z", {"VK7": {"2026-11-11": 3}})
        vk7 = self.resumo("2026-11-10", "2026-11-10")["quartos"]["VK7"]
        self.assertEqual(vk7["vendas"], [])
        self.assertEqual((vk7["divergencias_total"]["na_foto"], vk7["divergencias_total"]["sem_base"]), (0, 1))

    def test_registrar_guarda_a_base_do_inv_da_pasta(self):
        rec = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1000, 1000, 1000, 1300, mu="3/7")]})
        escrever_inv(rec, "2026-11-10T10:52:00Z", {"VK7": {"2026-11-10": 3, "2026-11-11": 2}})
        foto, _ = self.registrar(rec, "2026-11-10", dias=2)
        self.assertEqual(foto["base_beds24"], {"lido_em": "2026-11-10T10:52:00Z", "fonte": str(rec / "inv.json"),
                                               "noites": 2})
        b = sf.carregar(self.arq)["beds24"]
        self.assertEqual(b["2026-11-10"]["VK7"], [{"lido_em": "2026-11-10T10:52:00Z", "vendidas": 4}])
        self.assertEqual(b["2026-11-11"]["VK7"], [{"lido_em": "2026-11-10T10:52:00Z", "vendidas": 5}])
        texto = self.arq.read_text(encoding="utf-8")
        self.registrar(rec, "2026-11-10", dias=2)  # de novo: a mesma leitura não se repete
        self.assertEqual(self.arq.read_text(encoding="utf-8"), texto)
        self.assertIn("Base do Beds24 da foto: leitura de 10/11 07:52", sf.texto_registro(foto, False, self.arq))
        # registrar de novo no mesmo dia sem inv.json (outra pasta): a base já guardada continua
        foto, _ = self.registrar(self.rec_dir("r10b", {"VK7": [noite("2026-11-10", 1000, 1000, 1000, 1300, mu="3/7")]}),
                                 "2026-11-10", dias=1)
        self.assertEqual(foto["base_beds24"]["lido_em"], "2026-11-10T10:52:00Z")
        # inv.json de outro dia ou sem _lido_em: a foto fica sem base (e diz por quê)
        escrever_inv(rec, "2026-11-09T22:00:00Z", {"VK7": {"2026-11-10": 3}})
        foto, _ = self.registrar(rec, "2026-11-10", dias=2, substituir=True)
        self.assertIn("não do dia da foto", foto["base_beds24"]["sem_base"])
        (rec / "inv.json").write_text(json.dumps({"VK7": {"2026-11-10": {"numAvail": 3}}}), encoding="utf-8")
        foto, _ = self.registrar(rec, "2026-11-10", dias=2, substituir=True)
        self.assertIn("_lido_em", foto["base_beds24"]["sem_base"])
        # --inv explícito de outro dia: erro, nada gravado
        outro = self.pasta / "inv_outro.json"
        outro.write_text(json.dumps(inventario("2026-11-09T22:00:00Z", {"VK7": {"2026-11-10": 3}})), encoding="utf-8")
        antes = self.arq.read_text(encoding="utf-8")
        codigo, _, err = self.cli("registrar", "--rec-dir", str(rec), "--hoje", "2026-11-10", "--dias", "1",
                                  "--inv", str(outro), "--arquivo", str(self.arq))
        self.assertEqual(codigo, 2)
        self.assertIn("--inv", err)
        self.assertEqual(self.arq.read_text(encoding="utf-8"), antes)


class TestFechar(Base):
    def foto_10_e_11(self):
        # foto de 10/11 (07:53): noite 10/11 com 5/7 a R$ 1.199 (solto 1.300); noite 11/11 com 3/7 a R$ 947 (solto 1.175)
        r10 = self.rec_dir("r10", {"VK7": [noite("2026-11-10", 1199, 1199, 1199, 1300, mu="5/7"),
                                           noite("2026-11-11", 947, 947, 947, 1175, mu="3/7")],
                                   "Afrodite": [noite("2026-11-10", 1740, 1740, 1740, 1735, mu="-1", nb="0")]})
        r11 = self.rec_dir("r11", {"VK7": [noite("2026-11-11", 947, 947, 947, 1175, mu="4/7")],
                                   "Afrodite": [noite("2026-11-11", 1740, 1740, 1740, 1735, mu="-1", nb="0")]})
        self.registrar(r10, "2026-11-10", dias=2)
        self.registrar(r11, "2026-11-11", dias=1)

    def test_fechar_guarda_as_vendidas_do_beds24_e_e_idempotente(self):
        info = self.fechar("2026-11-10T19:54:30Z", {"VK7": {"2026-11-09": 7, "2026-11-10": 0, "2026-11-11": 3,
                                                            "2026-11-12": -1, "2026-11-20": 7},
                                                    "Afrodite": {"2026-11-10": 1}, "Desconhecido": {"2026-11-10": 0}},
                           dias=3)
        self.assertEqual((info["hoje"], info["noites"], info["quartos"], info["repetida"]),
                         ("2026-11-10", 3, ["Afrodite", "VK7"], False))
        b = sf.carregar(self.arq)["beds24"]
        self.assertEqual(sorted(b), ["2026-11-10", "2026-11-11", "2026-11-12"])  # 09/11 é antes; 20/11 fora dos 3 dias
        self.assertEqual(b["2026-11-10"]["VK7"], [{"lido_em": "2026-11-10T19:54:30Z", "vendidas": 7}])
        self.assertEqual(b["2026-11-11"]["VK7"][0]["vendidas"], 4)
        self.assertEqual(b["2026-11-12"]["VK7"][0]["vendidas"], 7)  # numAvail negativo conta como 0
        self.assertEqual(b["2026-11-10"]["Afrodite"][0]["vendidas"], 0)
        texto = self.arq.read_text(encoding="utf-8")
        info = self.fechar("2026-11-10T19:54:30Z", {"VK7": {"2026-11-10": 0, "2026-11-11": 3, "2026-11-12": -1},
                                                    "Afrodite": {"2026-11-10": 1}}, dias=3)
        self.assertTrue(info["repetida"])
        self.assertEqual(self.arq.read_text(encoding="utf-8"), texto)
        self.fechar("2026-11-10T22:54:00Z", {"VK7": {"2026-11-11": 2}}, dias=3)
        b = sf.carregar(self.arq)["beds24"]
        self.assertEqual([x["lido_em"] for x in b["2026-11-11"]["VK7"]], ["2026-11-10T19:54:30Z", "2026-11-10T22:54:00Z"])
        # o dia padrão é o da leitura em Brasília (01:30 UTC de 11/11 = 22:30 de 10/11)
        info = self.fechar("2026-11-11T01:30:00Z", {"VK7": {"2026-11-10": 0, "2026-11-11": 2}}, dias=3)
        self.assertEqual(info["hoje"], "2026-11-10")

    def test_venda_do_proprio_dia_entra_e_a_noite_nao_passa_livre(self):
        # Achado: vendas do próprio dia, depois da foto das 07:53, nunca entravam
        self.foto_10_e_11()
        # leitura da noite de 09/11 (23:00 em Brasília): outro dia, não é base da foto de 10/11 nem venda depois dela
        self.fechar("2026-11-10T02:00:00Z", {"VK7": {"2026-11-10": 0}}, hoje="2026-11-09", dias=3)
        # base da foto (07:52, um minuto antes): o Beds24 concorda com o PriceLabs (5/7 e 3/7; a Afrodite livre)
        self.fechar("2026-11-10T10:52:00Z", {"VK7": {"2026-11-10": 2, "2026-11-11": 4}, "Afrodite": {"2026-11-10": 1}})
        self.fechar("2026-11-10T19:54:30Z", {"VK7": {"2026-11-10": 1, "2026-11-11": 4}, "Afrodite": {"2026-11-10": 0}})
        self.fechar("2026-11-10T22:54:00Z", {"VK7": {"2026-11-10": 0, "2026-11-11": 3}, "Afrodite": {"2026-11-10": 1}})
        r = self.resumo("2026-11-10", "2026-11-11")
        vk7 = r["quartos"]["VK7"]
        vendas = [(v["noite"], v["unidades"], v["entre"], v["fonte"], v["gravado"], v["na_mesa"]) for v in vk7["vendas"]]
        self.assertEqual(vendas, [
            ("2026-11-10", 1, ["2026-11-10", "2026-11-10T19:54:30Z"], "beds24", 1199, 101),
            ("2026-11-10", 1, ["2026-11-10T19:54:30Z", "2026-11-10T22:54:00Z"], "beds24", 1199, 101),
            # 11/11: a foto de 11/11 é depois das leituras de 10/11, então a cadeia é foto 10 → foto 11
            ("2026-11-11", 1, ["2026-11-10", "2026-11-11"], "foto", 947, 228)])
        self.assertEqual(vk7["canceladas"], 0)
        # a VK7 10/11 esgotou no fim do dia: não passa com unidades livres
        self.assertEqual(vk7["encalhadas"], [])
        self.assertEqual(vk7["vendas_total"]["dinheiro_na_mesa"], 101 * 2 + 228)
        # a Afrodite: livre às 07:53 e livre na última leitura (o bloqueio de 16:54 sumiu às 19:54: cancelamento)
        af = r["quartos"]["Afrodite"]
        self.assertEqual([(e["noite"], e["livres"], e["leitura_do_fim"]) for e in af["encalhadas"]],
                         [("2026-11-10", 1, "2026-11-10T22:54:00Z")])
        self.assertEqual(af["canceladas"], 1)
        self.assertEqual(r["leituras_beds24"], ["2026-11-10T19:54:30Z", "2026-11-10T22:54:00Z"])
        self.assertEqual(r["bases_beds24"], ["2026-11-10T10:52:00Z"])
        self.assertEqual(vk7["divergencias"], [])
        texto = sf.texto_resumo(r, CFG)
        self.assertIn("a leitura do Beds24 de 10/11 16:54", texto)
        self.assertIn("Bloqueio sem reserva conta como vendido no Beds24", texto)
        af_txt = sf.texto_resumo({**r, "destaque": "Afrodite"}, CFG)
        self.assertIn("passou com 1 de 1 livres (Beds24 10/11 19:54)", af_txt)
        # resumo só até 10/11: sem a foto de 11/11, as leituras de 10/11 também valem para a noite 11/11 (+1 às 19:54)
        vk7 = self.resumo("2026-11-10", "2026-11-10")["quartos"]["VK7"]
        self.assertEqual([(v["noite"], v["unidades"], v["entre"][1]) for v in vk7["vendas"]],
                         [("2026-11-10", 1, "2026-11-10T19:54:30Z"), ("2026-11-10", 1, "2026-11-10T22:54:00Z"),
                          ("2026-11-11", 1, "2026-11-10T22:54:00Z")])

    def test_fechar_pela_linha_de_comando(self):
        inv = self.pasta / "inv.json"
        inv.write_text(json.dumps(inventario("2026-11-10T19:54:30Z", {"VK7": {"2026-11-10": 0}})), encoding="utf-8")
        codigo, out, err = self.cli("fechar", "--inv", str(inv), "--arquivo", str(self.arq), hoje="2026-11-10")
        self.assertEqual(codigo, 0, err)
        self.assertIn("Leitura do Beds24 de 10/11 16:54", out)
        self.assertIn("Bloqueio sem reserva conta como vendido", out)
        # leitura de outro dia: erro, salvo --forcar
        codigo, _, err = self.cli("fechar", "--inv", str(inv), "--arquivo", str(self.arq), hoje="2026-11-11")
        self.assertEqual(codigo, 2)
        self.assertIn("não de hoje", err)
        codigo, _, err = self.cli("fechar", "--inv", str(inv), "--arquivo", str(self.arq), "--forcar", hoje="2026-11-11")
        self.assertEqual(codigo, 0, err)
        # --hoje diferente do dia da leitura
        codigo, _, err = self.cli("fechar", "--inv", str(inv), "--hoje", "2026-11-11", "--arquivo", str(self.arq),
                                  hoje="2026-11-11")
        self.assertEqual(codigo, 2)
        self.assertIn("não é o dia da leitura", err)
        # --lido-em longe do _lido_em do inventário
        codigo, _, err = self.cli("fechar", "--inv", str(inv), "--lido-em", "2026-11-10T22:54:00Z", "--arquivo",
                                  str(self.arq), hoje="2026-11-10")
        self.assertEqual(codigo, 2)
        self.assertIn("difere do _lido_em", err)
        # sem _lido_em nem --lido-em
        sem = self.pasta / "sem.json"
        sem.write_text(json.dumps({"VK7": {"2026-11-10": {"numAvail": 0}}}), encoding="utf-8")
        codigo, _, err = self.cli("fechar", "--inv", str(sem), "--arquivo", str(self.arq), hoje="2026-11-10")
        self.assertEqual(codigo, 2)
        self.assertIn("--lido-em", err)
        codigo, _, err = self.cli("fechar", "--inv", str(sem), "--lido-em", "2026-11-10T22:54:00Z", "--arquivo",
                                  str(self.arq), hoje="2026-11-10")
        self.assertEqual(codigo, 0, err)


if __name__ == "__main__":
    unittest.main()

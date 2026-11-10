"""Plano do dono de 09/11 no motor (pace.py), no validador, nas auditorias e nas faixas (ordem dono-2026-11-09-plano-nov-fev).
Rodar na pasta scripts/: python3 -m unittest -q test_plano_motor"""
from __future__ import annotations

import datetime as dt
import math
import unittest
from datetime import date

import calendario_12m as c12
import faixas_dono
import ordem_recanto
import pace
import plano_dono as PD
from comum import carregar_quartos
from ordens import carregar_ordens
from validar_plano import Validador

ORDENS_REAIS = carregar_ordens()
PLANO = {PD.ORDEM_ID: ORDENS_REAIS[PD.ORDEM_ID]}


def row(noite, preco, v, u, mn=None, mx=None):
    """Noite do recálculo: piso e teto = preço (como a escada de hoje), salvo informados."""
    return {"date": noite, "price": str(preco), "mu_occupancy": f"{v}/{u}" if u > 1 else "-1", "num_bookings": str(v),
            "min_price": str(mn if mn is not None else preco), "max_price": str(mx if mx is not None else preco),
            "min_stay": "2"}


def anc_semana(*noites):
    return {"noites": {n: {"p25": 286, "p50": 353, "p75": 500, "p90": 579, "max": 724, "n": 19, "busca_em": "2026-11-09",
                           "tipo": "semana", "p50_medido": True} for n in noites}, "por_tipo": {}}


class TestPlanoMotor(unittest.TestCase):
    def setUp(self):
        self.cfg = carregar_quartos()
        self.passos = {"passos": []}

    def plano(self, cals, hoje, dias, ordens=None, anc=None, **kw):
        return pace.plano(self.cfg, anc or {"noites": {}, "por_tipo": {}}, cals, hoje,
                          PLANO if ordens is None else ordens, self.passos, dias=dias, regra_j_ativa=False, **kw)

    @staticmethod
    def por(res):
        return {(i["quarto"], i["data"]): i for i in res["itens"]}

    def fds_1311(self):
        n13, n14 = "2026-11-13", "2026-11-14"
        cals = {"Q7": {n13: row(n13, 2006, 2, 7), n14: row(n14, 2006, 3, 7)},
                "Double": {n13: row(n13, 2013, 2, 6), n14: row(n14, 2013, 3, 6)},
                "Q2": {n13: row(n13, 2019, 1, 2), n14: row(n14, 2019, 1, 2)},
                "Afrodite": {n13: row(n13, 2860, 1, 1), n14: row(n14, 2860, 1, 1)},
                "VK7": {n13: row(n13, 2856, 4, 7), n14: row(n14, 2856, 4, 7)},
                "VK2": {n13: row(n13, 2388, 2, 2), n14: row(n14, 2423, 2, 2)},
                "Balcony": {n13: row(n13, 2464, 0, 6), n14: row(n14, 2464, 0, 6)}}
        return cals

    def test_fds_13_e_14_11_abertura_escada_afrodite_q2(self):
        """Decisão 1: preços de abertura já em 13 e 14/11 (tabela aprovada, sem a descida: a noite já estava a menos
        de 7 dias quando o dono deu a ordem)."""
        res = self.plano(self.fds_1311(), date(2026, 11, 10), 4)
        it = self.por(res)
        fr, fv = 0.6157, 0.5795
        q7 = it[("Q7", "2026-11-13")]
        self.assertEqual(q7["min_price"], q7["max_price"])
        self.assertAlmostEqual(q7["min_price"], 669 / fr, delta=1.5)               # Moinhos 2/7: p40 da lista
        self.assertEqual((q7["excecao"], q7["ordem_id"]), ("dono", PD.ORDEM_ID))
        self.assertAlmostEqual(it[("Double", "2026-11-13")]["min_price"], 842 * 1.05 / fr, delta=1.5)  # 2/6: abertura
        self.assertAlmostEqual(it[("Double", "2026-11-14")]["min_price"], 842 * 1.05 * 1.07 / fr, delta=1.5)  # 3/6: +7%
        vk7 = it[("VK7", "2026-11-13")]
        self.assertAlmostEqual(vk7["min_price"], 842 * 1.05 * 1.07 / fv, delta=1.5)  # 4/7: +7% = público ~946, ~1.632
        self.assertAlmostEqual(vk7["min_price"] * fv, 946, delta=1.5)
        for n in ("2026-11-13", "2026-11-14"):
            ef = {k: pace.efetivo(self.cfg, k, float(it[(k, n)]["min_price"])) for k in ("Q7", "Double", "Q2", "Afrodite")}
            # Afrodite 15% acima da Panorâmica I (o preço efetivo dela; sem a faixa de R$ 1.600-1.800, decisão 7)
            self.assertAlmostEqual(it[("Afrodite", n)]["min_price"], ef["Double"] * 1.15, delta=1.5)
            self.assertNotIn("1.600", it[("Afrodite", n)]["motivo"])
            # Panorâmica II entre a Moinhos e 75% do caminho até a Panorâmica I (ordem do Recanto)
            tol = 0.01 * float(self.cfg["por_curto"]["Q2"]["base"]) + 1
            self.assertGreaterEqual(ef["Q2"], ef["Q7"] - tol)
            self.assertLessEqual(ef["Q2"], ef["Q7"] + 0.75 * (ef["Double"] - ef["Q7"]) + tol)
            # pace do item marca o plano (ledger: a troca de regra vale uma vez por noite)
            self.assertEqual(it[("Q7", n)]["pace"]["status"], "plano")
        # Balcony: 6/9 das suítes com banheira da Villa vendidas -> Regra G; com a King Spa vinda do plano, no alvo dela
        # (90% da suíte livre mais barata, no % inteiro da base logo abaixo; revisão de 10/11), sem o passo de pace
        b = it[("Balcony", "2026-11-13")]
        ref = pace.efetivo(self.cfg, "VK7", float(vk7["min_price"]))
        bb = float(self.cfg["por_curto"]["Balcony"]["base"])
        self.assertTrue(0.9 * ref - 0.01 * bb - 1 <= b["min_price"] <= 0.9 * ref, (b["min_price"], ref))
        self.assertEqual(b["min_price"], b["max_price"])
        self.assertEqual(b["ordem_id"], "dono-2026-10-07-regra-g")
        # o validador aceita os itens do plano (sem --precos, a Regra G confere contra os itens)
        v = Validador(self.cfg, date(2026, 11, 10), {}, ordens=ORDENS_REAIS)
        v.validar(res["itens"])
        self.assertEqual(v.erros, [])

    def test_descida_a_7_dias_com_menos_de_60(self):
        n = "2026-11-27"  # sexta, D7 a partir de 20/11
        rn = PD.noite_regua(n)
        ab = PD.abertura_publico("Q7", rn, "fds")
        fr = rn["fator_publico"]["Recanto"]
        cal = {"Q7": {n: row(n, 2400, 2, 7)}}
        d7 = self.por(self.plano(cal, date(2026, 11, 20), 8))[("Q7", n)]
        self.assertAlmostEqual(d7["min_price"], max(ab * 0.9 / fr, 1000), delta=1.5)
        self.assertIn("-10%", d7["motivo"])
        d8 = self.por(self.plano(cal, date(2026, 11, 19), 9))[("Q7", n)]
        self.assertAlmostEqual(d8["min_price"], max(ab / fr, 1000), delta=1.5)
        # 5/7 (71%) a D3: não desce; 1 degrau (meio = 4)
        cal5 = {"Q7": {n: row(n, 2400, 5, 7)}}
        d3 = self.por(self.plano(cal5, date(2026, 11, 24), 4))[("Q7", n)]
        tt = PD.teto_publico("Q7", rn, "fds")
        self.assertAlmostEqual(d3["min_price"], max(min(ab * 1.07 ** 2, tt) / fr, 1000), delta=1.5)

    def test_d0_nunca_sobe(self):
        n = "2026-11-13"
        cal = {"Q7": {n: row(n, 900, 2, 7)}}
        res = self.plano(cal, date(2026, 11, 13), 0)
        self.assertEqual(res["itens"], [])
        self.assertEqual(res["linhas"][0]["cadencia"], "plano: D0 nunca sobe")
        # D0 desce
        cal = {"Q7": {n: row(n, 2006, 2, 7)}}
        it = self.por(self.plano(cal, date(2026, 11, 13), 0))[("Q7", n)]
        self.assertEqual(it["pace"]["direcao"], "desce")

    def test_fora_do_periodo_e_sem_a_ordem_nada_muda(self):
        n = "2027-03-05"  # sexta depois do plano
        anc = {"noites": {n: {"p25": 456, "p50": 653, "p75": 800, "p90": 1080, "max": 1144, "n": 24,
                              "busca_em": "2027-03-01", "tipo": "fds"}}, "por_tipo": {}}
        cals = {"Q7": {n: row(n, 2006, 2, 7)}, "VK7": {n: row(n, 1900, 2, 7)}, "Balcony": {n: row(n, 1500, 0, 6)}}
        com = self.plano(cals, date(2027, 3, 1), 5, anc=anc)
        sem = self.plano(cals, date(2027, 3, 1), 5, ordens={}, anc=anc)
        self.assertEqual(com["itens"], sem["itens"])
        self.assertFalse(any(i.get("ordem_id") == PD.ORDEM_ID for i in com["itens"]))
        # fim de semana fora do período: o piso de R$ 850 no Genius 3 continua
        fat = self.cfg["parametros"]["fator_vitrine_fds"]["Recanto"]
        self.assertAlmostEqual(pace.pisos_enviados(self.cfg, "Q7", date(2027, 3, 5))[0],
                               max(self.cfg["por_curto"]["Q7"]["min"], 850 / fat), places=1)
        # no período (R2): sem o 850, vale o padrão de qualidade
        self.assertEqual(pace.pisos_enviados(self.cfg, "Q7", date(2026, 11, 13))[0],
                         max(1000.0, float(self.cfg["por_curto"]["Q7"]["min"])))
        # sem a ordem no arquivo, a noite do período fica com o motor de antes
        sem13 = self.plano(self.fds_1311(), date(2026, 11, 10), 4, ordens={}, anc=anc_semana())
        self.assertFalse(any(i.get("ordem_id") == PD.ORDEM_ID for i in sem13["itens"]))

    def test_dia_de_semana_piso_de_mercado_d7_e_piso_do_dono_d8(self):
        hoje = date(2026, 11, 10)
        n7, n8 = "2026-11-17", "2026-11-18"  # terça D7, quarta D8
        cals = {"Q7": {n7: row(n7, 950, 0, 7, mx=5000), n8: row(n8, 950, 0, 7, mx=5000)}}
        res = self.plano(cals, hoje, 8, anc=anc_semana(n7, n8))
        it = self.por(res)
        pm = PD.piso_semana_ultima("Q7", PD.noite_regua(n7), 7)
        self.assertAlmostEqual(it[("Q7", n7)]["min_price"], pm, delta=1.5)          # mediana com hidro ÷ fator
        self.assertLess(it[("Q7", n7)]["min_price"], self.cfg["por_curto"]["Q7"]["min"])  # abaixo do mínimo do anúncio
        self.assertEqual(it[("Q7", n7)]["ordem_id"], PD.ORDEM_ID)
        dono = pace.pisos_enviados(self.cfg, "Q7", date(2026, 11, 18))[0]
        self.assertGreaterEqual(it[("Q7", n8)]["min_price"], dono - 1)               # D8: piso do dono (R$ 500 Genius 3)
        self.assertNotEqual(it[("Q7", n8)].get("ordem_id"), PD.ORDEM_ID)
        # Panorâmica I: mediana x 1,05; Afrodite e Balcony fora; Villa: o piso do mercado é maior, nada muda
        self.assertAlmostEqual(pace.piso_semana_mercado(self.cfg, "Double", date(2026, 11, 17), 7, PLANO)[0],
                               PD.piso_semana_ultima("Double", PD.noite_regua(n7), 7))
        self.assertIsNone(pace.piso_semana_mercado(self.cfg, "Afrodite", date(2026, 11, 17), 7, PLANO))
        self.assertIsNone(pace.piso_semana_mercado(self.cfg, "Q7", date(2026, 11, 17), 7, {}))
        self.assertGreater(pace.piso_semana_mercado(self.cfg, "VK7", date(2026, 11, 17), 7, PLANO)[0],
                           pace.pisos_enviados(self.cfg, "VK7", date(2026, 11, 17))[0])
        # validador: o item do plano de D7 passa; abaixo do piso de mercado, em D8 ou com outra ordem, ERRO
        v = Validador(self.cfg, hoje, {}, ordens=ORDENS_REAIS)
        v.validar(res["itens"])
        self.assertEqual(v.erros, [])

        def errs(**kw):
            base = {"tipo": "data", "quarto": "Q7", "data": n7, "min_price": math.ceil(pm), "max_price": math.ceil(pm),
                    "preco_antes": 950, "motivo": "teste", "excecao": "dono", "ordem_id": PD.ORDEM_ID}
            base.update(kw)
            vv = Validador(self.cfg, hoje, {}, ordens=ORDENS_REAIS)
            vv.validar([base])
            return vv.erros
        self.assertEqual(errs(), [])
        self.assertTrue(errs(min_price=round(pm - 60), max_price=round(pm - 60)))
        self.assertTrue(errs(data=n8))
        self.assertTrue(errs(ordem_id="dono-2026-10-21-ordem-recanto"))
        self.assertTrue(errs(min_price=550, max_price=550))  # abaixo do limite de segurança (R$ 560)

    def test_balcony_7_de_9_regra_g_e_8_de_9_mercado(self):
        n = "2026-12-12"  # sábado
        hoje = date(2026, 12, 1)
        rn = PD.noite_regua(n)

        def cals(v7, v2):
            return {"VK7": {n: row(n, 2400, v7, 7)}, "VK2": {n: row(n, 2400, v2, 2)},
                    "Balcony": {n: row(n, 2000, 0, 6)}}
        it7 = self.por(self.plano(cals(5, 2), hoje, 12))
        b, vk = it7[("Balcony", n)], it7[("VK7", n)]
        ref = pace.efetivo(self.cfg, "VK7", float(vk["min_price"]))
        self.assertTrue(0.855 * ref - 1 <= b["min_price"] <= 0.9 * ref + 1)
        self.assertNotEqual(b["ordem_id"], PD.ORDEM_ID)
        it8 = self.por(self.plano(cals(6, 2), hoje, 12))
        b = it8[("Balcony", n)]
        merc = PD.balcony_mercado_enviado(rn, "fds")
        self.assertEqual(b["min_price"], b["max_price"])
        self.assertEqual((b["excecao"], b["ordem_id"]), ("dono", PD.ORDEM_ID))
        self.assertIn("8/9 vendidas", b["motivo"])
        bb = float(self.cfg["por_curto"]["Balcony"]["base"])
        self.assertTrue(merc - 1 <= b["min_price"] <= merc + 0.01 * bb + 1, (b["min_price"], merc))
        # as duas esgotadas: também o mercado
        it9 = self.por(self.plano(cals(7, 2), hoje, 12))
        self.assertEqual(it9[("Balcony", n)]["ordem_id"], PD.ORDEM_ID)
        # validador com --precos: 8/9 dá aviso; 7/9 com o mesmo preço é ERRO; acima da suíte segue ERRO
        it_b = dict(b, motivo=b["motivo"])
        vk_it = {"tipo": "data", "quarto": "VK7", "data": n, "min_price": 1900, "max_price": 1900, "preco_antes": 2400,
                 "motivo": "teste", "excecao": "dono", "ordem_id": PD.ORDEM_ID}

        def val(v7, itens):
            pre = {("VK7", n): {"preco": 1900, "livres": 7 - v7, "vendidas": v7, "total": 7},
                   ("VK2", n): {"preco": 2400, "livres": 0, "vendidas": 2, "total": 2},
                   ("Balcony", n): {"preco": 2000, "livres": 6, "vendidas": 0, "total": 6},
                   "_meta": {"idade_h": {"VK7": 1, "VK2": 1, "Balcony": 1}}}
            vv = Validador(self.cfg, hoje, pre, ordens=ORDENS_REAIS)
            vv.validar(itens)
            return vv
        self.assertEqual(val(6, [it_b, vk_it]).erros, [])
        self.assertTrue(any("abaixo do alvo" in a for a in val(6, [it_b, vk_it]).avisos))
        self.assertTrue(any("Regra G" in e for e in val(5, [it_b, vk_it]).erros))
        alto = dict(it_b, min_price=1950, max_price=1950)
        self.assertTrue(any("acima da suíte" in e for e in val(6, [alto, vk_it]).erros))
        # sem a ordem do plano no item, a regra dos 80% não libera
        outra = dict(it_b, ordem_id="dono-2026-10-07-regra-g")
        self.assertTrue(any("Regra G" in e for e in val(6, [outra, vk_it]).erros))

    def test_natal_recanto_no_plano_villa_fora(self):
        n = "2026-12-24"
        hoje = date(2026, 12, 10)
        anc = {"noites": {n: {"p25": 900, "p50": 1100, "p75": 1400, "p90": 1700, "max": 2000, "n": 20,
                              "busca_em": "2026-12-09", "tipo": "feriado"}}, "por_tipo": {}}
        cals = {"Q7": {n: row(n, 4974, 0, 7)}, "Double": {n: row(n, 4988, 0, 6)}, "Q2": {n: row(n, 4977, 0, 2)},
                "Afrodite": {n: row(n, 5940, 0, 1)}, "VK7": {n: row(n, 4988, 0, 7)}, "VK2": {n: row(n, 4991, 0, 2)}}
        it = self.por(self.plano(cals, hoje, 20, anc=anc))
        for k in ("Q7", "Double", "Q2", "Afrodite"):
            self.assertEqual(it[(k, n)]["ordem_id"], PD.ORDEM_ID)
            # nunca abaixo de R$ 800 reais (o piso_real de R$ 1.000 do Natal fica dispensado pela ordem)
            self.assertGreaterEqual(it[(k, n)]["min_price"] * self.cfg["por_curto"][k]["fator_real"], 800 - 1)
        self.assertLess(it[("Q7", n)]["min_price"] * self.cfg["por_curto"]["Q7"]["fator_real"], 1000)
        for k in ("VK7", "VK2"):
            self.assertNotEqual((it.get((k, n)) or {}).get("ordem_id"), PD.ORDEM_ID)
        v = Validador(self.cfg, hoje, {}, ordens=ORDENS_REAIS)
        v.validar([x for x in it.values() if x["quarto"] in ("Q7", "Double", "Q2", "Afrodite")])
        self.assertEqual(v.erros, [])
        # abaixo de R$ 800 reais: ERRO mesmo com a ordem do plano
        baixo = dict(it[("Q7", n)], min_price=1800, max_price=1800)
        v = Validador(self.cfg, hoje, {}, ordens=ORDENS_REAIS)
        v.validar([baixo])
        self.assertTrue(any("800" in e for e in v.erros))

    def test_cadencia_troca_de_regra_uma_vez_por_noite(self):
        n = "2026-11-13"
        cal = {"Q7": {n: row(n, 2006, 2, 7)}}
        agora = dt.datetime(2026, 11, 10, 12, 0, tzinfo=dt.timezone.utc)
        # passo do motor de hoje ainda não chegou ao canal: o plano vale na hora (primeiro passo do plano na noite)
        self.passos = {"passos": [{"quarto": "Q7", "noite": n, "direcao": "desce", "gravado_em": "2026-11-10T10:00:00+00:00",
                                   "chegou_ao_canal": False, "vendidas": 2, "status": "atras"}]}
        self.assertTrue(self.plano(cal, date(2026, 11, 10), 4, agora=agora)["itens"])
        # depois do primeiro passo do plano, a cadência normal: sem venda nova, nada no mesmo dia
        self.passos["passos"].append({"quarto": "Q7", "noite": n, "direcao": "desce", "gravado_em": "2026-11-10T11:00:00+00:00",
                                      "chegou_ao_canal": False, "vendidas": 2, "status": "plano", "base": "quarto",
                                      "total": 7})
        cal2 = {"Q7": {n: row(n, 2006, 2, 7)}}
        self.assertEqual(self.plano(cal2, date(2026, 11, 10), 4, agora=agora)["itens"], [])

    def test_rotina_leve_so_as_noites_do_filtro(self):
        # só as noites do filtro; a Panorâmica II e a Afrodite da noite acompanham a Panorâmica I (revisão de 10/11:
        # antes só a suíte do filtro mudava e a ordem do Recanto ficava errada até a rodada completa)
        n = "2026-11-13"
        res = self.plano(self.fds_1311(), date(2026, 11, 10), 4, noites_filtro={("Double", n)})
        it = self.por(res)
        self.assertEqual({k for k in it}, {("Double", n), ("Q2", n), ("Afrodite", n)})
        ef_db = pace.efetivo(self.cfg, "Double", float(it[("Double", n)]["min_price"]))
        # a Moinhos fica onde está (R$ 2.006, fora do filtro): a Panorâmica II entre ela e a Panorâmica I real
        tol = 0.01 * float(self.cfg["por_curto"]["Q2"]["base"]) + 1
        q2 = pace.efetivo(self.cfg, "Q2", float(it[("Q2", n)]["min_price"]))
        self.assertTrue(ef_db - tol <= q2 <= ef_db + 0.75 * (2006 - ef_db) + tol, (q2, ef_db))
        # Afrodite: 5% acima da mais cara das outras que ficam no canal (a Moinhos a R$ 2.006) e 15% acima da Panorâmica I
        self.assertGreaterEqual(it[("Afrodite", n)]["min_price"], max(2006 * 1.05, ef_db * 1.15) - 1)
        # Panorâmica II no filtro: a Afrodite vem junto; Moinhos e Panorâmica I não
        res = self.plano(self.fds_1311(), date(2026, 11, 10), 4, noites_filtro={("Q2", n)})
        self.assertTrue({(i["quarto"], i["data"]) for i in res["itens"]} <= {("Q2", n), ("Afrodite", n)})


class TestPlanoAuditorias(unittest.TestCase):
    def setUp(self):
        self.cfg = carregar_quartos()

    def test_exigencias_calendario_12m(self):
        q7, vk7 = self.cfg["por_curto"]["Q7"], self.cfg["por_curto"]["VK7"]
        L = self.cfg["limites_seguranca"]
        self.assertEqual(c12.exigencias(self.cfg, q7, date(2026, 11, 13))["piso"], round(max(q7["min"], 1000)))
        fat = self.cfg["parametros"]["fator_vitrine_fds"]["Recanto"]
        self.assertEqual(c12.exigencias(self.cfg, q7, date(2027, 3, 5))["piso"], round(max(q7["min"], 850 / fat)))
        # Natal: Recanto sem o piso_real do período (só R$ 800 reais); Villa como está
        self.assertEqual(c12.exigencias(self.cfg, q7, date(2026, 12, 24))["piso"], round(800 / q7["fator_real"]))
        self.assertEqual(c12.exigencias(self.cfg, vk7, date(2026, 12, 24))["piso"], round(1000 / vk7["fator_real"]))
        # dia de semana D7 com hoje: piso de mercado; sem hoje ou em D8: o do dono
        pm = PD.piso_semana_ultima("Q7", PD.noite_regua("2026-11-17"), 7)
        self.assertEqual(c12.exigencias(self.cfg, q7, date(2026, 11, 17), date(2026, 11, 10))["piso"],
                         round(max(pm, L["fracao_min_referencia"] * q7["min_referencia"])))
        dono = round(max(q7["min"], 500 / self.cfg["parametros"]["fator_vitrine_semana"]["Recanto"]))
        self.assertEqual(c12.exigencias(self.cfg, q7, date(2026, 11, 17))["piso"], dono)
        self.assertEqual(c12.exigencias(self.cfg, q7, date(2026, 11, 18), date(2026, 11, 10))["piso"], dono)

    def test_ordem_recanto_afrodite(self):
        self.assertIsNone(ordem_recanto.faixa_fds_afrodite(self.cfg, date(2026, 11, 14)))
        self.assertIsNotNone(ordem_recanto.faixa_fds_afrodite(self.cfg, date(2027, 3, 6)))
        self.assertEqual(ordem_recanto.premio_afrodite(self.cfg, date(2026, 12, 27)), 0.2)    # entre festas (domingo)
        self.assertEqual(ordem_recanto.premio_afrodite(self.cfg, date(2027, 3, 7)), 0.2)      # domingo fora do plano
        g = ordem_recanto.faixa_afrodite(self.cfg, date(2026, 11, 14), {"price": "1086"}, {"price": "1536"}, 1423,
                                         {"price": "1700"}, panoramica_1={"price": "1536"})
        self.assertTrue(g["fora"])
        self.assertGreaterEqual(g["piso"], round(1536 * 1.15))
        self.assertIsNone(g["teto"])
        g = ordem_recanto.faixa_afrodite(self.cfg, date(2026, 11, 14), {"price": "1086"}, {"price": "1536"}, 1423,
                                         {"price": "1767"}, panoramica_1={"price": "1536"})
        self.assertFalse(g["fora"])

    def test_faixas_dono_nao_cria_ordem_nas_noites_do_plano(self):
        ex = dict(PLANO)
        qs = faixas_dono.QUARTOS_ORDEM
        self.assertTrue(faixas_dono.plano_governa(self.cfg, ex, "Recanto", qs["Recanto"], "2026-12-24"))
        self.assertFalse(faixas_dono.plano_governa(self.cfg, ex, "Villa", qs["Villa"], "2026-12-24"))
        self.assertTrue(faixas_dono.plano_governa(self.cfg, ex, "Villa", qs["Villa"], "2026-12-12"))
        self.assertFalse(faixas_dono.plano_governa(self.cfg, ex, "Recanto", qs["Recanto"], "2026-12-15"))
        self.assertFalse(faixas_dono.plano_governa(self.cfg, ex, "Recanto", qs["Recanto"], "2027-03-05"))
        self.assertFalse(faixas_dono.plano_governa(self.cfg, {}, "Recanto", qs["Recanto"], "2026-12-24"))



class TestRevisao1011(unittest.TestCase):
    """Achados da revisão independente de 10/11 sobre o plano do dono no motor."""

    def setUp(self):
        self.cfg = carregar_quartos()

    def plano(self, cals, hoje, dias, ordens=None, **kw):
        return pace.plano(self.cfg, {"noites": {}, "por_tipo": {}}, cals, hoje, PLANO if ordens is None else ordens,
                          {"passos": []}, dias=dias, regra_j_ativa=False, **kw)

    def test_concorrencia_hidro_vista_fora_das_noites_do_plano(self):
        """Achado 1: o script não gera item nas suítes e noites do plano (VK2 19/11, feriado: +10% pelo piso)."""
        import concorrencia_hidro_vista as c
        n = "2026-11-19"
        dia = lambda p, mu: {"date": n, "price": p, "min_stay": 2, "multi_unit_occupancy": mu, "booking_status": "",
                             "min_price": p}
        coleta = {"rooms": {"VK7": {"days": [dia(2096, "4/7")]}, "VK2": {"days": [dia(2096, "1/2")]},
                            "Balcony": {"days": [dia(1500, "0/6")]}}}
        comp = {"periodos": [{"rotulo": "t", "datas": [n], "mediana_a": 3000, "recanto": None}]}
        sem, _ = c.montar(self.cfg, comp, coleta, date(2026, 11, 10), ordens={})
        self.assertIn("VK2", {i["quarto"] for i in sem})             # sem a ordem: o +10% de antes
        com, notas = c.montar(self.cfg, comp, coleta, date(2026, 11, 10), ordens=ORDENS_REAIS)
        self.assertEqual(com, [])
        self.assertTrue(any("plano do dono" in x for x in notas))
        # R2 no piso do dono do script: sexta/sábado do período sem o R$ 850 (padrão de qualidade)
        n2 = "2026-12-04"
        coleta2 = {"rooms": {"Q7": {"days": [dict(dia(2000, "0/7"), date=n2)]}}}
        comp2 = {"periodos": [{"rotulo": "t", "datas": [n2], "mediana_a": 600, "recanto": 1100}]}
        it, _ = c.montar(self.cfg, comp2, coleta2, date(2026, 11, 10), ordens=ORDENS_REAIS)
        self.assertEqual(it, [])                                       # noite do plano

    def test_q2_e_afrodite_perto_do_plano_mas_fora_da_ordem_do_recanto(self):
        """Achado 2: a tolerância de 3% não deixa a Panorâmica II acima dos 75% do caminho nem a Afrodite abaixo dos 15%."""
        n = "2026-11-13"
        base = TestPlanoMotor.fds_1311(None)
        ref = self.por(self.plano(base, date(2026, 11, 10), 4))
        q2_plano, af_plano = ref[("Q2", n)]["min_price"], ref[("Afrodite", n)]["min_price"]
        cals = TestPlanoMotor.fds_1311(None)
        q2_vig = round(q2_plano * 1.025)        # dentro dos 3%, acima da faixa (75% + tolerância)
        af_vig = round(af_plano * 0.975)        # dentro dos 3%, abaixo dos 15% sobre a Panorâmica I
        cals["Q2"][n] = row(n, q2_vig, 1, 2)
        cals["Afrodite"][n] = row(n, af_vig, 0, 1)
        it = self.por(self.plano(cals, date(2026, 11, 10), 4))
        self.assertIn(("Q2", n), it)
        self.assertLessEqual(it[("Q2", n)]["max_price"], q2_plano + 2)
        self.assertIn(("Afrodite", n), it)
        self.assertGreaterEqual(it[("Afrodite", n)]["min_price"], af_plano - 2)

    @staticmethod
    def por(res):
        return {(i["quarto"], i["data"]): i for i in res["itens"]}

    def test_afrodite_20_em_27_e_28_12(self):
        """Achados 3 e 9: entre festas fora de sexta, sábado e feriado, o prêmio da Afrodite é o de dia de semana."""
        n = "2026-12-27"
        cals = {"Q7": {n: row(n, 2400, 0, 7)}, "Double": {n: row(n, 2400, 0, 6)}, "Q2": {n: row(n, 2400, 0, 2)},
                "Afrodite": {n: row(n, 2900, 0, 1)}}
        it = self.por(self.plano(cals, date(2026, 12, 20), 8))
        outras = max(pace.efetivo(self.cfg, k, float(it[(k, n)]["min_price"])) for k in ("Q7", "Double", "Q2"))
        self.assertGreaterEqual(it[("Afrodite", n)]["min_price"], outras * 1.2 - 1)

    def test_balcony_completa_igual_a_leve(self):
        """Achado 4: na noite em que a King Spa vem do plano, a Balcony vai a 90% dela nas duas rotinas."""
        n = "2026-11-14"
        cheia = self.por(self.plano(TestPlanoMotor.fds_1311(None), date(2026, 11, 10), 4))
        leve = self.por(self.plano(TestPlanoMotor.fds_1311(None), date(2026, 11, 10), 4,
                                   noites_filtro={("VK7", n), ("Balcony", "2026-11-13")}))
        self.assertEqual(cheia[("Balcony", n)]["min_price"], leve[("Balcony", n)]["min_price"])
        self.assertNotIn("pace", cheia[("Balcony", n)])

    def test_sem_a_ordem_nada_do_plano_vale(self):
        """Achados 5 e 10: sem a ordem nas ordens em uso, nem a R2 nem a regra da Afrodite valem pela data."""
        d = date(2026, 11, 13)
        fat = self.cfg["parametros"]["fator_vitrine_fds"]["Recanto"]
        with PD.com_ordens({}):
            self.assertAlmostEqual(pace.pisos_enviados(self.cfg, "Q7", d)[0],
                                   max(float(self.cfg["por_curto"]["Q7"]["min"]), 850 / fat), places=1)
            self.assertIsNotNone(ordem_recanto.faixa_fds_afrodite(self.cfg, date(2026, 11, 14)))
            self.assertFalse(PD.governa_noite("Q7", "2026-11-13", self.cfg))
            self.assertEqual(c12.exigencias(self.cfg, self.cfg["por_curto"]["Q7"], d)["piso"],
                             round(max(self.cfg["por_curto"]["Q7"]["min"], 850 / fat)))
        with PD.com_ordens(PLANO):
            self.assertEqual(pace.pisos_enviados(self.cfg, "Q7", d)[0], 1000.0)
            self.assertIsNone(ordem_recanto.faixa_fds_afrodite(self.cfg, date(2026, 11, 14)))
        # o validador olha as ordens dele: sem o plano, o R$ 850 de sexta volta a valer
        it = {"tipo": "data", "quarto": "Q7", "data": "2026-11-13", "min_price": 1100, "max_price": 1100,
              "preco_antes": 2006, "motivo": "teste"}
        v = Validador(self.cfg, date(2026, 11, 10), {}, ordens={})
        v.validar([dict(it)])
        self.assertTrue(any("850" in e or "vitrine" in e for e in v.erros), v.erros)
        v = Validador(self.cfg, date(2026, 11, 10), {}, ordens=ORDENS_REAIS)
        v.validar([dict(it)])
        self.assertFalse(any("850" in e for e in v.erros), v.erros)

    def test_suite_com_mudanca_manual_e_referencia_da_q2_e_da_afrodite(self):
        """Achado 6: a Moinhos congelada pelo dono a R$ 2.500 puxa a Panorâmica II e a Afrodite (preço do canal)."""
        n = "2026-11-13"
        cals = TestPlanoMotor.fds_1311(None)
        cals["Q7"][n] = row(n, 2500, 2, 7)
        ordens = dict(PLANO, m={"id": "m", "data": "2026-11-10", "tipo": "manual", "quartos": ["Q7"], "de": n, "ate": n,
                                "libera": []})
        it = self.por(self.plano(cals, date(2026, 11, 10), 4, ordens=ordens))
        self.assertNotIn(("Q7", n), it)
        ef_db = pace.efetivo(self.cfg, "Double", float(it[("Double", n)]["min_price"]))
        q2 = pace.efetivo(self.cfg, "Q2", float(it[("Q2", n)]["min_price"]))
        self.assertGreaterEqual(q2, min(ef_db, 2500) - 1)
        self.assertLessEqual(q2, min(ef_db, 2500) + 0.75 * abs(2500 - ef_db) + 1)
        self.assertGreaterEqual(it[("Afrodite", n)]["min_price"], 2500 * 1.05 - 1)

    def test_ordem_recanto_com_hoje_no_piso_de_mercado(self):
        """Achado 7: dia de semana D6, Moinhos e Panorâmica I no piso de mercado; a Panorâmica II reaberta a R$ 1.300
        volta para dentro da faixa (não para R$ 1.220, acima da Panorâmica I), com a ordem do plano."""
        hoje, d = date(2026, 11, 10), date(2026, 11, 16)
        pm = PD.piso_semana_ultima("Q2", PD.noite_regua(d.isoformat()), 6)
        q7, db, q2 = {"price": str(round(pm + 1))}, {"price": str(round(pm * 1.05))}, {"price": "1300"}
        f = ordem_recanto.faixa(self.cfg, d, q7, db, q2, hoje)
        self.assertTrue(f["fora"])
        self.assertLessEqual(f["teto"], float(db["price"]))
        self.assertLess(f["piso"], f["piso_dono"])
        pl = ordem_recanto.montar_plano([{"quarto": "Q2", "data": d.isoformat(), **f}], hoje)
        self.assertEqual(pl["itens"][0]["ordem_id"], PD.ORDEM_ID)
        v = Validador(self.cfg, hoje, {}, ordens=ORDENS_REAIS)
        v.validar(pl["itens"])
        self.assertEqual(v.erros, [])
        # sem hoje (chamada antiga), o piso do dono
        self.assertEqual(ordem_recanto.faixa(self.cfg, d, q7, db, q2)["piso"], round(f["piso_dono"]))

    def test_validador_ordem_do_plano_so_no_escopo(self):
        """Achado 8: a ordem do plano não serve de exceção 'dono' fora de R1, R3 e R4."""
        hoje = date(2026, 11, 10)

        def erros(q, n, preco):
            it = {"tipo": "data", "quarto": q, "data": n, "min_price": preco, "max_price": preco, "preco_antes": 3000,
                  "motivo": "teste", "excecao": "dono", "ordem_id": PD.ORDEM_ID}
            v = Validador(self.cfg, hoje, {}, ordens=ORDENS_REAIS)
            v.validar([it])
            return [e for e in v.erros if PD.ORDEM_ID in e and "não vale" in e or "só libera a Balcony" in e]
        self.assertTrue(erros("VK7", "2026-12-24", 2100))       # Natal da Villa: o plano não governa
        self.assertTrue(erros("Afrodite", "2026-11-24", 1300))  # terça D14, Afrodite fora da R3
        self.assertTrue(erros("Balcony", "2026-11-24", 640))    # Balcony sem a prova dos 80%
        self.assertFalse(erros("Q7", "2026-11-13", 1086))       # R1
        self.assertFalse(erros("Q7", "2026-11-17", 950))        # R3 (D7)

    def test_exigencias_afrodite_no_plano_sem_o_minimo_do_anuncio(self):
        """Achado 11: nas noites do plano a Afrodite pode ficar abaixo dos R$ 1.500 dela (padrão e limite de segurança)."""
        af = self.cfg["por_curto"]["Afrodite"]
        lim = float(self.cfg["limites_seguranca"]["fracao_min_referencia"]) * float(af["min_referencia"])
        with PD.com_ordens(PLANO):
            self.assertEqual(c12.exigencias(self.cfg, af, date(2027, 2, 12))["piso"], round(max(1000, lim)))
        self.assertLess(round(max(1000, lim)), float(af["min"]))
        fat = self.cfg["parametros"]["fator_vitrine_fds"]["Recanto"]
        self.assertEqual(c12.exigencias(self.cfg, af, date(2027, 3, 5))["piso"], round(max(af["min"], 850 / fat)))


if __name__ == "__main__":
    unittest.main()

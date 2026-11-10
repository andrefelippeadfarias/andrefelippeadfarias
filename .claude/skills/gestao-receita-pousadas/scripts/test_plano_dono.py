"""Testes do plano do dono de 09/11 (scripts/plano_dono.py). Rodar: python3 -m unittest -q test_plano_dono"""
from __future__ import annotations

import unittest

import plano_dono as P

RN = {  # régua de uma noite de fim de semana (13/11/2026, varredura de 09-10/11)
    "tipo": "fds",
    "lista": {"25": 604, "40": 669, "50": 758, "65": 1000, "75": 1189},
    "hidro": {"25": 826, "40": 941, "50": 1180, "65": 1364, "75": 1547},
    "pares_lista": {"25": 520, "50": 609, "75": 700},
    "pares_hidro": {"25": 781, "50": 842, "75": 1364},
    "fator_publico": {"Recanto": 0.6157, "Villa": 0.5795},
    "pedido_0911": {"Q7": 2006, "Double": 2013, "Q2": 2019, "Afrodite": 2860, "VK7": 2856, "VK2": 2406, "Balcony": 2464},
}


class TestPlanoDono(unittest.TestCase):
    def test_abertura_fds(self):
        self.assertAlmostEqual(P.abertura_publico("Q7", RN, "fds"), 669)
        self.assertAlmostEqual(P.abertura_publico("Double", RN, "fds"), 842 * 1.05)
        self.assertAlmostEqual(P.abertura_publico("VK7", RN, "fds"), 842 * 1.05)
        self.assertAlmostEqual(P.abertura_publico("Afrodite", RN, "fds"), 842 * 1.05 * 1.15)
        self.assertIsNone(P.abertura_publico("Balcony", RN, "fds"))

    def test_abertura_nunca_acima_do_pedido(self):
        rn = dict(RN, pedido_0911=dict(RN["pedido_0911"], Q7=1000))
        self.assertAlmostEqual(P.abertura_publico("Q7", rn, "fds"), 1000 * 0.6157)

    def test_escada_so_depois_da_metade(self):
        # 7 unidades: meio = 4; a 4ª unidade (3 vendidas) ainda na abertura; a 5ª (4 vendidas) +7%
        self.assertEqual(P.degraus_escada(0, 7), 0)
        self.assertEqual(P.degraus_escada(3, 7), 0)
        self.assertEqual(P.degraus_escada(4, 7), 1)
        self.assertEqual(P.degraus_escada(6, 7), 3)
        # 2 unidades: meio = 1; com 1 vendida a 2ª sobe 7%
        self.assertEqual(P.degraus_escada(0, 2), 0)
        self.assertEqual(P.degraus_escada(1, 2), 1)
        # 6 unidades: meio = 3
        self.assertEqual(P.degraus_escada(2, 6), 0)
        self.assertEqual(P.degraus_escada(3, 6), 1)

    def test_preco_com_escada_e_teto(self):
        r = P.preco_publico("VK7", RN, "fds", 4, 7, D=30)
        self.assertAlmostEqual(r["publico"], 842 * 1.05 * 1.07)
        r = P.preco_publico("VK7", RN, "fds", 6, 7, D=30)   # 3 degraus: 1083 < teto p65 1364
        self.assertAlmostEqual(r["publico"], 842 * 1.05 * 1.07 ** 3)
        r = P.preco_publico("Q7", RN, "fds", 6, 7, D=30)    # Moinhos: teto no p50 do quarto com hidro
        self.assertLessEqual(r["publico"], 1180 + 1e-6)

    def test_descida_a_7_dias(self):
        r = P.preco_publico("Q7", RN, "fds", 2, 7, D=7)
        self.assertTrue(r["desceu"])
        self.assertAlmostEqual(r["publico"], 669 * 0.9)
        r = P.preco_publico("Q7", RN, "fds", 2, 7, D=8)
        self.assertFalse(r["desceu"])
        r = P.preco_publico("Q7", RN, "fds", 5, 7, D=3)     # 71% vendido: não desce
        self.assertFalse(r["desceu"])
        self.assertFalse(P.desce(3, 1, 7, "semana"))

    def test_enviado_e_padrao_de_qualidade(self):
        self.assertAlmostEqual(P.enviado("Q7", RN, 669, "fds"), 669 / 0.6157)
        self.assertEqual(P.enviado("Q7", RN, 500, "fds"), 1000.0)       # nunca abaixo do padrão no fim de semana
        self.assertAlmostEqual(P.enviado("Balcony", RN, 400, "fds"), 400 / 0.5795)

    def test_villa_festas_e_semana_fora(self):
        self.assertFalse(P.governa("VK7", "natal"))
        self.assertFalse(P.governa("VK2", "reveillon"))
        self.assertTrue(P.governa("Q7", "natal"))
        self.assertFalse(P.governa("Q7", "semana"))
        self.assertFalse(P.governa("Balcony", "fds"))

    def test_piso_semana_ultima(self):
        rn = dict(RN, fator_publico={"Recanto": 0.6327, "Villa": 0.4746})
        self.assertIsNone(P.piso_semana_ultima("Q7", rn, 8))
        self.assertAlmostEqual(P.piso_semana_ultima("Q7", rn, 7), 1180 / 0.6327)
        self.assertAlmostEqual(P.piso_semana_ultima("Double", rn, 0), 1180 * 1.05 / 0.6327)
        self.assertIsNone(P.piso_semana_ultima("Afrodite", rn, 3))
        self.assertIsNone(P.piso_semana_ultima("Balcony", rn, 3))

    def test_balcony_gatilho_80(self):
        self.assertFalse(P.balcony_no_mercado(7, 9))   # 77,8%
        self.assertTrue(P.balcony_no_mercado(8, 9))    # 88,9%
        self.assertAlmostEqual(P.balcony_mercado_enviado(RN, "fds"), 609 / 0.5795)

    def test_regua_do_repositorio_cobre_o_periodo(self):
        r = P.regua()["noites"]
        self.assertIn("2026-11-10", r)
        self.assertIn("2027-02-28", r)
        self.assertEqual(len(r), 111)
        for n, v in r.items():
            for s in ("lista", "hidro", "pares_lista", "pares_hidro"):
                self.assertIn("50", v[s], (n, s))
            self.assertGreater(v["fator_publico"]["Recanto"], 0.3)
            self.assertGreater(v["fator_publico"]["Villa"], 0.3)


if __name__ == "__main__":
    unittest.main()

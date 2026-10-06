"""Testes dos scripts da Skill. Rodar na pasta scripts/: python3 -m unittest test_scripts"""

from __future__ import annotations

import unittest
from datetime import date

from comum import carregar_quartos, periodo_feriado
from ocupacao import calcular
from validar_plano import Validador, montar_payload

CFG = carregar_quartos()
HOJE = date(2026, 10, 6)  # terça


def item(**kw):
    base = {"tipo": "data", "motivo": "teste"}
    base.update(kw)
    return base


def erros(itens, precos=None, hoje=HOJE):
    v = Validador(CFG, hoje, precos or {})
    v.validar(itens)
    return v.erros


class TestValidador(unittest.TestCase):
    def test_regra_i_valida(self):
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-08", preco=640, price_type="fixed", min_price=640)]), [])

    def test_regra_i_sem_min_price(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-08", preco=640, price_type="fixed")]))

    def test_abaixo_do_minimo_longe(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-20", preco=700, price_type="fixed", min_price=700)]))

    def test_limite_seguranca(self):
        e = erros([item(quarto="Q7", data="2026-10-07", preco=500, price_type="fixed", min_price=500)])
        self.assertTrue(any("segurança" in x for x in e))

    def test_afrodite_fora_da_regra_i(self):
        self.assertTrue(erros([item(quarto="Afrodite", data="2026-10-07", preco=1300, price_type="fixed", min_price=1300)]))

    def test_q2_sem_desconto(self):
        self.assertTrue(erros([item(quarto="Q2", data="2026-10-20", preco=1200, price_type="fixed", preco_antes=1432)]))
        self.assertEqual(erros([item(quarto="Q2", data="2026-10-09", preco=2950, price_type="fixed",
                                     preco_antes=3550, excecao="piso_feriado")]), [])

    def test_sabado_sozinho(self):
        self.assertTrue(erros([item(quarto="Double", data="2026-10-17", min_stay=1)]))
        quarta = date(2026, 10, 14)
        self.assertEqual(erros([item(quarto="Double", data="2026-10-17", min_stay=1, excecao="regra_h",
                                     ocupacao_sabado=26)], hoje=quarta), [])
        # feriado nunca libera sábado sozinho
        self.assertTrue(erros([item(quarto="Double", data="2026-10-10", min_stay=1, excecao="regra_h",
                                    ocupacao_sabado=10)], hoje=date(2026, 10, 7)))

    def test_piso_feriado(self):
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-09", preco=2390, price_type="fixed")]), [])
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-09", preco=2000, price_type="fixed")]))
        self.assertEqual(erros([item(quarto="Balcony", data="2026-10-09", preco=1890, price_type="fixed")]), [])

    def test_noite_de_volta_nao_e_feriado(self):
        self.assertIsNone(periodo_feriado(CFG, date(2026, 10, 12)))
        self.assertIsNotNone(periodo_feriado(CFG, date(2026, 10, 11)))

    def test_regra_g_balcony(self):
        precos = {("VK2", "2026-10-12"): {"preco": 970, "livres": 1}, ("Balcony", "2026-10-12"): {"preco": 900, "livres": 5}}
        e = erros([item(quarto="VK7", data="2026-10-12", preco=1000, price_type="fixed")], precos)
        self.assertTrue(any("Regra G" in x for x in e))
        e = erros([item(quarto="VK7", data="2026-10-12", preco=1000, price_type="fixed"),
                   item(quarto="Balcony", data="2026-10-12", preco=1000, price_type="fixed")], precos)
        self.assertEqual(e, [])
        # Balcony 980: acima da VK2 livre (970) passa; com a VK2 esgotada, a referência vira a VK7 (1000)
        plano = [item(quarto="VK7", data="2026-10-12", preco=1000, price_type="fixed"),
                 item(quarto="Balcony", data="2026-10-12", preco=980, price_type="fixed")]
        self.assertEqual(erros(plano, precos), [])
        precos[("VK2", "2026-10-12")]["livres"] = 0
        self.assertTrue(any("Regra G" in x for x in erros(plano, precos)))

    def test_anuncio_limite_semanal(self):
        self.assertTrue(erros([{"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 700, "motivo": "x"}]))
        self.assertEqual(erros([{"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 900, "motivo": "x"}]), [])

    def test_proibidos(self):
        self.assertTrue(erros([{"tipo": "personalizacao", "quarto": "VK7", "descricao": "tirar oferta Genius",
                                "motivo": "x", "como_desfazer": "y"}]))
        self.assertTrue(erros([{"tipo": "desconto_ota", "quarto": "VK7", "motivo": "x"}]))

    def test_payload(self):
        p = montar_payload(CFG, [item(quarto="Q7", data="2026-10-08", preco=640, price_type="fixed", min_price=640, min_stay=1)])
        o = p["update_listing_date_overrides"][0]
        self.assertEqual(o["listing_id"], "350362___722805")
        self.assertEqual(o["overrides"][0]["price"], "640")
        self.assertEqual(o["overrides"][0]["min_price_type"], "fixed")


class TestOcupacao(unittest.TestCase):
    def test_janelas_e_noites(self):
        dias = [{"date": f"2026-10-{6 + i:02d}", "price": 800, "user_price": 800, "min_stay": 1,
                 "multi_unit_occupancy": "7/7" if i < 3 else "0/7", "booking_status": ""} for i in range(7)]
        afro = [{"date": f"2026-10-{6 + i:02d}", "price": 1500, "user_price": 1500, "min_stay": 1,
                 "multi_unit_occupancy": "", "booking_status": "Booked" if i == 0 else ""} for i in range(7)]
        r = calcular({"Q7": {"id": "q7", "data": dias}, "Afrodite": {"id": "af", "data": afro}}, CFG, HOJE, 3)
        self.assertEqual(r["janelas"]["Q7"]["7"]["vendidas"], 21)
        self.assertEqual(r["janelas"]["Q7"]["7"]["total"], 49)
        self.assertEqual(r["janelas"]["Afrodite"]["7"]["vendidas"], 1)
        self.assertEqual(r["janelas"]["Hotel"]["7"]["total"], 56)
        self.assertEqual(r["noites"][0]["quartos"]["Q7"]["livres"], 0)


if __name__ == "__main__":
    unittest.main()

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
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-08", preco=-35, price_type="percent", min_price=640)]), [])

    def test_regra_i_sem_min_price(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-08", preco=-35, price_type="percent")]))

    def test_fixo_so_com_ordem_do_dono(self):
        e = erros([item(quarto="Q7", data="2026-10-08", preco=640, price_type="fixed", min_price=640)])
        self.assertTrue(any("percentual" in x for x in e))
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-20", preco=900, price_type="fixed",
                                     excecao="dono")]), [])

    def test_percentual_piso_abaixo_do_minimo_longe(self):
        self.assertTrue(erros([item(quarto="VK7", data="2026-10-20", preco=-35, price_type="percent", min_price=700)]))
        self.assertEqual(erros([item(quarto="VK7", data="2026-10-20", preco=-35, price_type="percent", min_price=850)]), [])

    def test_max_price_abaixo_do_piso(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-20", preco=-10, price_type="percent",
                                    min_price=1200, max_price=1100)]))

    def test_abaixo_do_minimo_longe(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-20", preco=-30, price_type="percent", min_price=700)]))

    def test_limite_seguranca(self):
        e = erros([item(quarto="Q7", data="2026-10-07", preco=-50, price_type="percent", min_price=500)])
        self.assertTrue(any("segurança" in x for x in e))

    def test_afrodite_fora_da_regra_i(self):
        self.assertTrue(erros([item(quarto="Afrodite", data="2026-10-07", preco=-30, price_type="percent", min_price=1300)]))

    def test_q2_sem_desconto(self):
        self.assertTrue(erros([item(quarto="Q2", data="2026-10-20", preco=-15, price_type="percent", min_price=850)]))
        self.assertEqual(erros([item(quarto="Q2", data="2026-10-09", preco=-17, price_type="percent", min_price=2950,
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
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-09", preco=-10, price_type="percent", min_price=2390)]), [])
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-09", preco=-10, price_type="percent", min_price=2000)]))
        # percentual sem piso no feriado: o mais baixo possível é o mínimo do anúncio, abaixo do piso
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-09", preco=0, price_type="percent")]))
        self.assertEqual(erros([item(quarto="Balcony", data="2026-10-09", preco=0, price_type="percent", min_price=1890)]), [])

    def test_piso_feriado_por_quarto(self):
        fer = CFG["feriados"][0]
        if (fer.get("piso_real_por_quarto") or {}).get("VK7"):
            piso = fer["piso_real_por_quarto"]["VK7"]
            ok = round(piso / 0.39) + 10
            self.assertEqual(erros([item(quarto="VK7", data="2026-10-09", preco=-20, price_type="percent", min_price=ok)]), [])

    def test_noite_de_volta_nao_e_feriado(self):
        self.assertIsNone(periodo_feriado(CFG, date(2026, 10, 12)))
        self.assertIsNotNone(periodo_feriado(CFG, date(2026, 10, 11)))

    def test_regra_g_balcony(self):
        precos = {("VK2", "2026-10-12"): {"preco": 970, "livres": 1}, ("Balcony", "2026-10-12"): {"preco": 900, "livres": 5}}
        e = erros([item(quarto="VK7", data="2026-10-12", preco=-35, price_type="percent", min_price=1000)], precos)
        self.assertTrue(any("Regra G" in x for x in e))
        e = erros([item(quarto="VK7", data="2026-10-12", preco=-35, price_type="percent", min_price=1000),
                   item(quarto="Balcony", data="2026-10-12", preco=-35, price_type="percent", min_price=1000)], precos)
        self.assertEqual(e, [])
        # Balcony com piso 980: acima da VK2 livre (970) passa; com a VK2 esgotada, a referência vira a VK7 (1000)
        plano = [item(quarto="VK7", data="2026-10-12", preco=-35, price_type="percent", min_price=1000),
                 item(quarto="Balcony", data="2026-10-12", preco=-35, price_type="percent", min_price=980)]
        self.assertEqual(erros(plano, precos), [])
        precos[("VK2", "2026-10-12")]["livres"] = 0
        self.assertTrue(any("Regra G" in x for x in erros(plano, precos)))
        # piso da Balcony contra suíte fora do plano: só aviso
        precos[("VK2", "2026-10-12")]["livres"] = 1
        v = Validador(CFG, HOJE, precos)
        v.validar([item(quarto="Balcony", data="2026-10-12", preco=-35, price_type="percent", min_price=850)])
        self.assertEqual(v.erros, [])
        self.assertTrue(any("Regra G" in x for x in v.avisos))

    def test_anuncio_limite_semanal(self):
        self.assertTrue(erros([{"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 700, "motivo": "x"}]))
        self.assertEqual(erros([{"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 900, "motivo": "x"}]), [])

    def test_proibidos(self):
        self.assertTrue(erros([{"tipo": "personalizacao", "quarto": "VK7", "descricao": "tirar oferta Genius",
                                "motivo": "x", "como_desfazer": "y"}]))
        self.assertTrue(erros([{"tipo": "desconto_ota", "quarto": "VK7", "motivo": "x"}]))

    def test_payload(self):
        p = montar_payload(CFG, [item(quarto="Q7", data="2026-10-08", preco=-35, price_type="percent", min_price=640,
                                      max_price=900, min_stay=1)])
        o = p["update_listing_date_overrides"][0]
        self.assertEqual(o["listing_id"], "350362___722805")
        self.assertEqual(o["overrides"][0]["price"], "-35")
        self.assertEqual(o["overrides"][0]["price_type"], "percent")
        self.assertEqual(o["overrides"][0]["min_price"], "640")
        self.assertEqual(o["overrides"][0]["min_price_type"], "fixed")
        self.assertEqual(o["overrides"][0]["max_price"], "900")


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


class TestPercentual(unittest.TestCase):
    def dia(self, x, cust, suav=True):
        passos = [("listing_occ_pricing_factor", "-3%", x)] + ([("price_smoothing", "+0", cust)] if suav else [])
        return {"price": cust, "cust": cust, "passos": passos, "mu": "0/2"}

    def test_bloco_com_data_esgotada_no_meio(self):
        from percentual import blocos, calcular
        det = {"2026-10-25": self.dia(1060, 1058), "2026-10-26": self.dia(974, 974, suav=False),
               "2026-10-27": self.dia(949, 1058), "2026-10-28": self.dia(1078, 1058), "2026-10-29": self.dia(1147, 1058)}
        self.assertIn(["2026-10-25", "2026-10-27", "2026-10-28", "2026-10-29"], blocos(det))
        # alvo só no domingo: o percentual vaza para o bloco e o simulado fica longe do alvo
        so_domingo = calcular(det, {"2026-10-25": 765}, 765)["2026-10-25"]
        self.assertTrue(so_domingo["vaza_para"])
        # alvo no bloco inteiro: todos chegam ao mínimo
        r = calcular(det, {d: 765 for d in ("2026-10-25", "2026-10-27", "2026-10-28", "2026-10-29")}, 765)
        self.assertTrue(all(abs(v["simulado"] - 765) <= 5 for v in r.values()))

    def test_alvo_maior_vira_piso(self):
        from percentual import calcular
        det = {"2026-10-12": self.dia(1534, 1147), "2026-10-13": self.dia(992, 1147),
               "2026-10-14": self.dia(995, 1147), "2026-10-15": self.dia(1069, 1147)}
        r = calcular(det, {"2026-10-12": 970, "2026-10-13": 800, "2026-10-14": 800, "2026-10-15": 800}, 800)
        self.assertEqual(r["2026-10-12"]["piso"], 970)
        self.assertEqual(r["2026-10-12"]["simulado"], 970)
        self.assertEqual(r["2026-10-13"]["piso"], 800)


if __name__ == "__main__":
    unittest.main()

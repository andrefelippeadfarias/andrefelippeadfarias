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
        piso = CFG["feriados"][0]["piso_real"]
        ok, baixo = round(piso / 0.42) + 5, round(piso / 0.42) - 50
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-09", preco=-10, price_type="percent", min_price=ok)]), [])
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-09", preco=-10, price_type="percent", min_price=baixo)]))
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
        # Regra G (07/10): Balcony cerca de 10% abaixo da suíte com banheira mais barata e livre
        precos = {("VK2", "2026-10-12"): {"preco": 1000, "livres": 1}, ("Balcony", "2026-10-12"): {"preco": 900, "livres": 5}}
        ok = [item(quarto="VK7", data="2026-10-12", preco=-35, price_type="percent", min_price=1100),
              item(quarto="Balcony", data="2026-10-12", preco=-35, price_type="percent", min_price=900)]
        self.assertEqual(erros(ok, precos), [])
        # acima da suíte com banheira: erro
        caro = [item(quarto="VK7", data="2026-10-12", preco=-35, price_type="percent", min_price=1100),
                item(quarto="Balcony", data="2026-10-12", preco=-35, price_type="percent", min_price=1050)]
        self.assertTrue(any("Regra G" in x for x in erros(caro, precos)))
        # muito abaixo do alvo (900) com a VK2 também no plano: erro
        barato = [item(quarto="VK2", data="2026-10-12", preco=-24, price_type="percent", min_price=1000),
                  item(quarto="Balcony", data="2026-10-12", preco=-35, price_type="percent", min_price=800)]
        self.assertTrue(any("Regra G" in x for x in erros(barato, precos)))
        # a mesma Balcony contra a VK2 fora do plano: só aviso (o preço final pode subir)
        v = Validador(CFG, HOJE, precos)
        v.validar([item(quarto="Balcony", data="2026-10-12", preco=-35, price_type="percent", min_price=800)])
        self.assertEqual(v.erros, [])
        self.assertTrue(any("Regra G" in x for x in v.avisos))
        # VK2 esgotada: a referência vira a VK7 (1100 -> alvo 990)
        precos[("VK2", "2026-10-12")]["livres"] = 0
        self.assertTrue(any("Regra G" in x for x in erros(ok, precos)))

    def test_padrao_de_qualidade(self):
        # sexta 23/10 e sábado 24/10 (fora de feriado), suítes com banheira: a partir de R$ 1.000
        base = dict(data="2026-10-23", preco=-20, price_type="percent")
        e = erros([item(quarto="Q7", min_price=800, **base)])
        self.assertTrue(any("padrão de qualidade" in x for x in e))
        self.assertTrue(any("padrão de qualidade" in x for x in erros(
            [item(quarto="VK2", data="2026-10-24", preco=-30, price_type="percent", min_price=765)])))
        # sem min_price o piso é o mínimo do anúncio, também abaixo do padrão
        self.assertTrue(any("padrão de qualidade" in x for x in erros(
            [item(quarto="Double", data="2026-10-23", preco=-10, price_type="percent")])))
        # piso de R$ 1.000 passa; dia útil, Balcony (sem banheira) e Afrodite (piso alto) não entram
        self.assertEqual(erros([item(quarto="Q7", min_price=1000, **base)]), [])
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-21", preco=-35, price_type="percent",
                                     min_price=800)]), [])
        self.assertEqual(erros([item(quarto="Balcony", data="2026-10-23", preco=-30, price_type="percent",
                                     min_price=765)]), [])
        self.assertEqual(erros([item(quarto="Afrodite", min_price=1500, **base)]), [])

    def test_padrao_de_qualidade_com_justificativa_de_mercado(self):
        base = dict(quarto="VK2", data="2026-10-24", preco=-30, price_type="percent", min_price=765)
        # justificativa curta demais não vale
        self.assertTrue(erros([item(mercado="fraco", **base)]))
        just = "mediana Booking 23-25/10 R$ 1.015 e mercado a 13% de ocupação"
        v = Validador(CFG, HOJE, {})
        v.validar([item(mercado=just, **base)])
        self.assertEqual(v.erros, [])
        self.assertTrue(any("exceção de mercado" in a for a in v.avisos))
        # o campo 'mercado' não vai para o payload do PriceLabs
        pl = montar_payload(CFG, [item(mercado=just, **base)])
        self.assertNotIn("mercado", str(pl))

    def test_padrao_de_qualidade_teto_e_mercado_com_numero(self):
        # item só com teto abaixo do padrão em sexta de suíte com banheira: ERRO
        self.assertTrue(any("padrão de qualidade" in x for x in erros(
            [item(quarto="Q7", data="2026-10-23", max_price=700)])))
        # teto abaixo do limite de segurança: ERRO mesmo em dia útil
        self.assertTrue(any("limite de segurança" in x for x in erros(
            [item(quarto="Q7", data="2026-10-21", max_price=400)])))
        # justificativa sem número, ou que não é texto, não vale
        base = dict(quarto="VK2", data="2026-10-24", preco=-30, price_type="percent", min_price=765)
        self.assertTrue(erros([item(mercado="sem dado nenhum aqui ok mesmo", **base)]))
        self.assertTrue(erros([item(mercado=["mediana", "booking", 1015], **base)]))
        self.assertTrue(erros([item(mercado=12345678901234567890, **base)]))
        # fronteira: 999 passa (tolerância de 1), 998 não
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-23", preco=-20, price_type="percent",
                                     min_price=999)]), [])
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-23", preco=-20, price_type="percent",
                                    min_price=998)]))

    def test_padrao_de_qualidade_so_o_dono_muda(self):
        import copy
        cfg = copy.deepcopy(CFG)
        cfg["limites_seguranca"]["padrao_qualidade_diaria"] = 0
        v = Validador(cfg, HOJE, {})
        v.validar([item(quarto="Q7", data="2026-10-21", preco=-35, price_type="percent", min_price=800)])
        self.assertTrue(any("padrão de qualidade ausente" in x for x in v.erros))
        # ordem do dono libera, mas avisa
        v = Validador(CFG, HOJE, {})
        v.validar([item(quarto="Q7", data="2026-10-23", preco=900, price_type="fixed", min_price=900,
                        excecao="dono")])
        self.assertFalse(any("padrão de qualidade" in x for x in v.erros))
        self.assertTrue(any("ordem do dono" in a for a in v.avisos))

    def test_padrao_de_qualidade_no_feriado(self):
        # feriado: o piso de segurança de R$ 800 reais já leva o preço enviado a mais de R$ 1.000
        self.assertEqual(erros([item(quarto="Q7", data="2026-11-13", preco=0, price_type="percent",
                                     min_price=1905)]), [])

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

    def test_minimo_por_data(self):
        # minimo como função: sexta e sábado sobem para 1000, dia útil fica no mínimo do quarto
        det = {d: {"price": 1100.0, "cust": 1100.0, "mu": 0,
                   "passos": [("price_smoothing", "+0", 1100.0)]} for d in ("2026-10-22", "2026-10-23")}
        alvos = {"2026-10-22": 1100.0, "2026-10-23": 1100.0}
        from percentual import calcular as calc_pct
        r = calc_pct(det, alvos, lambda d: 1000.0 if d == "2026-10-23" else 800.0)
        self.assertEqual(r["2026-10-23"]["piso"], 1000)
        self.assertEqual(r["2026-10-22"]["piso"], 800)

    def test_alvo_maior_vira_piso(self):
        from percentual import calcular
        det = {"2026-10-12": self.dia(1534, 1147), "2026-10-13": self.dia(992, 1147),
               "2026-10-14": self.dia(995, 1147), "2026-10-15": self.dia(1069, 1147)}
        r = calcular(det, {"2026-10-12": 970, "2026-10-13": 800, "2026-10-14": 800, "2026-10-15": 800}, 800)
        self.assertEqual(r["2026-10-12"]["piso"], 970)
        self.assertEqual(r["2026-10-12"]["simulado"], 970)
        self.assertEqual(r["2026-10-13"]["piso"], 800)


class TestRecalculo(unittest.TestCase):
    def test_regra_vencida_por_validade_nao_vale(self):
        from ler_recalculo import regra_inativa
        item = {"tipo": "data", "quarto": "Q7", "data": "2026-10-11", "preco": 0, "price_type": "percent", "min_price": 1905}
        # recálculo de uma data com lead_time_expiry vencido: dso_flag 0 e piso do anúncio
        self.assertIn("dso_flag 0", regra_inativa(item, {"dso_flag": "0", "min_price": "800", "price": "2416"}))
        # regra viva com o piso gravado
        self.assertEqual(regra_inativa(item, {"dso_flag": "1", "min_price": "1905"}), "")
        # regra viva, mas com outro piso (ex.: algo sobrescreveu)
        self.assertIn("diferente", regra_inativa(item, {"dso_flag": "1", "min_price": "850"}))
        # item só de estadia mínima não entra na checagem
        self.assertEqual(regra_inativa({"tipo": "data", "quarto": "Q7", "data": "2026-10-11", "min_stay": 2}, {"dso_flag": "0"}), "")


class TestMesclar(unittest.TestCase):
    def test_mescla_ocupacao_e_preco_do_recalculo(self):
        from datetime import datetime, timezone
        from mesclar_recalculo import mesclar_quarto, quartos_velhos
        quarto = {"last_refreshed_at": "2026-10-12T17:59:15+00:00", "days": [
            {"date": "2026-10-16", "price": 1456, "user_price": 1404, "min_stay": 1, "multi_unit_occupancy": "5/7", "booking_status": "Booked (Check-In)"},
            {"date": "2026-10-17", "price": 1456, "user_price": 1404, "min_stay": 2, "multi_unit_occupancy": "5/7", "booking_status": "Booked"},
            {"date": "2026-12-11", "price": 900, "user_price": 900, "min_stay": 1, "multi_unit_occupancy": "0/7", "booking_status": ""}]}
        refresh = {"data": {"pricing_array": [
            {"date": "2026-10-16", "price": "1366", "user_price": "-1", "min_stay": "1", "mu_occupancy": "7/7", "uncustomized_price": "3060"},
            {"date": "2026-10-17", "price": "1553", "user_price": "1456", "min_stay": "1", "mu_occupancy": "6/7", "uncustomized_price": "3169"}]}}
        m = mesclar_quarto(quarto, refresh)
        self.assertEqual(m["days"][0]["multi_unit_occupancy"], "7/7")
        self.assertEqual(m["days"][0]["user_price"], -1.0)
        self.assertEqual(m["days"][1]["min_stay"], 1)
        self.assertEqual(m["days"][2]["price"], 900)  # data fora do recálculo não muda
        # unidade única (Afrodite): mu_occupancy "-1"; vendida pelo num_bookings
        afro = {"days": [{"date": "2026-10-13", "price": 1546, "user_price": -1, "min_stay": 1, "multi_unit_occupancy": None, "booking_status": "Booked"},
                         {"date": "2026-10-16", "price": 2288, "user_price": 2292, "min_stay": 1, "multi_unit_occupancy": None, "booking_status": ""}]}
        ra = {"data": {"pricing_array": [
            {"date": "2026-10-13", "price": "1728", "user_price": "-1", "min_stay": "1", "mu_occupancy": "-1", "num_bookings": "1"},
            {"date": "2026-10-16", "price": "2506", "user_price": "-1", "min_stay": "1", "mu_occupancy": "-1", "num_bookings": "0"}]}}
        ma = mesclar_quarto(afro, ra)
        self.assertEqual([d["booking_status"] for d in ma["days"]], ["Booked", ""])
        self.assertIsNone(ma["days"][0]["multi_unit_occupancy"])
        agora = datetime(2026, 10, 13, 12, 0, tzinfo=timezone.utc)
        self.assertEqual([c for c, _ in quartos_velhos({"rooms": {"VK7": quarto, "Q7": {"last_refreshed_at": "2026-10-13T11:00:00+00:00"}}}, 6, agora)], ["VK7"])


class TestFaixas(unittest.TestCase):
    def test_cortes_e_faixa(self):
        from faixas_de_preco import cortes, faixa, quantil
        vals = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000]
        self.assertEqual(cortes(vals), [280.0, 460.0, 640.0, 820.0])
        self.assertEqual(quantil([5], 50), 5.0)
        c = [200, 400, 600, 800]
        self.assertEqual([faixa(p, c) for p in (100, 200, 399, 600, 799, 800, 5000)], [0, 1, 1, 3, 3, 4, 4])

    def test_regra_de_flutuacao(self):
        from faixas_de_preco import acao_sugerida as a
        # esgotada: nada
        self.assertEqual(a(2, 2, 1.0, 0.7, True, 5)[1], 0)
        # atrás da meta: desce; em alto desce mais
        self.assertEqual(a(2, 2, 0.2, 0.7, False, 5)[1], -10)
        self.assertEqual(a(3, 2, 0.2, 0.7, False, 5)[1], -15)
        # atrás, mas já uma faixa abaixo do alvo: segura (Regra I cuida de D0 a D3)
        self.assertEqual(a(1, 2, 0.2, 0.7, False, 5)[1], 0)
        # atrás, em "baixo", a mais de 3 dias: não desce abaixo de baixo
        self.assertEqual(a(1, 3, 0.1, 0.5, False, 10)[1], 0)
        # à frente: sobe até alvo+1
        self.assertEqual(a(2, 2, 0.9, 0.7, False, 5)[1], 10)
        self.assertEqual(a(3, 2, 0.9, 0.7, False, 5)[1], 0)
        self.assertEqual(a(4, 4, 0.9, 0.7, False, 5)[1], 0)
        # na meta: aproxima devagar
        self.assertEqual(a(0, 2, 0.6, 0.7, False, 5)[1], 5)
        self.assertEqual(a(4, 2, 0.6, 0.7, False, 5)[1], -5)
        self.assertEqual(a(2, 2, 0.6, 0.7, False, 5)[1], 0)

    def test_meta_da_janela(self):
        from faixas_de_preco import meta_da_janela
        self.assertEqual([meta_da_janela(CFG, d) for d in (0, 6, 7, 14, 15, 29, 30, 44, 45, 90)],
                         [0.7, 0.7, 0.5, 0.5, 0.35, 0.35, 0.25, 0.25, 0.15, 0.15])

    def test_juntar_varredura_e_conjuntos(self):
        from faixas_de_preco import juntar_varredura, tabela_mercado, conjunto
        res = {"datas": [
            {"rotulo": "semana-20/10-baixa", "checkin": "2026-10-20", "checkout": "2026-10-22", "noites": 2, "tipo": "semana",
             "props": [{"id": i, "name": f"h{i}", "stars": 3, "score": 8.5, "price_total": 400 * i, "jacuzzi": False, "spa": False, "pool": False} for i in range(1, 8)]},
            {"rotulo": "semana-20/10-alta", "checkin": "2026-10-20", "checkout": "2026-10-22", "noites": 2, "tipo": "semana",
             "props": [{"id": 7, "name": "h7", "stars": 3, "score": 8.5, "price_total": 2000, "jacuzzi": False, "spa": False, "pool": False},
                       {"id": 15459723, "name": "Recanto", "stars": 5, "score": 8.4, "price_total": 1800, "jacuzzi": True, "spa": False, "pool": False, "nosso": True}]},
        ]}
        v = juntar_varredura(res)
        self.assertEqual(set(v), {"semana-20/10"})
        self.assertEqual(v["semana-20/10"]["props"][7]["noite"], 1000.0)  # duplicata: fica o menor preço
        tab = tabela_mercado(v)
        self.assertEqual(tab["semana-20/10"]["conjuntos"]["geral"]["n"], 7)  # os nossos ficam fora
        self.assertEqual(tab["semana-20/10"]["nossos"], {"Recanto": 900.0})
        self.assertEqual(len(conjunto(list(v["semana-20/10"]["props"].values()), "recanto")), 0)


class TestClassificarQuartos(unittest.TestCase):
    def test_sem_desconto_nao_desce_e_esgotada_segura(self):
        from faixas_de_preco import classificar_quartos
        from sazonalidade import carregar
        coleta = {"rooms": {
            "Q2": {"days": [{"date": "2026-10-27", "price": 1519, "user_price": 1519, "min_stay": 1, "multi_unit_occupancy": "0/2", "booking_status": ""}]},
            "Q7": {"days": [{"date": "2026-10-27", "price": 919, "user_price": -1, "min_stay": 1, "multi_unit_occupancy": "7/7", "booking_status": "Booked"}]}}}
        tab = {"semana-27/10": {"tipo": "semana", "noites": 2, "checkin": "2026-10-27", "completa": True, "nossos": {},
                                 "conjuntos": {"recanto": {"n": 50, "cortes": [330, 727, 1258, 1787], "mediana": 888}}}}
        linhas = classificar_quartos(coleta, tab, carregar(), CFG, date(2026, 10, 14), 21)
        por = {l["quarto"]: l for l in linhas}
        self.assertEqual(por["Q2"]["passo"], 0)          # sem desconto: não desce mesmo atrás da meta
        self.assertEqual(por["Q2"]["faixa"], 2)          # 1519 × 0,61 = 927 → médio no conjunto do Recanto
        self.assertEqual(por["Q7"]["passo"], 0)          # esgotada
        self.assertTrue(por["Q7"]["acao"].startswith("esgotada"))


class TestSazonalidade(unittest.TestCase):
    def test_calendario_cobre_cada_noite_uma_vez(self):
        from sazonalidade import carregar, validar, periodo, datas_especiais
        cal = carregar()
        self.assertEqual(validar(cal), [])
        p = periodo(cal, date(2027, 7, 9))
        self.assertEqual((p["nivel"], p["faixa_alvo"], p["estadia_minima"]), ("muito alta", "muito alto", 3))
        # sexta e quarta do mesmo intervalo caem em períodos diferentes
        self.assertNotEqual(periodo(cal, date(2026, 11, 6))["nome"], periodo(cal, date(2026, 11, 4))["nome"])
        self.assertTrue(any("Finados" in x["nome"] for x in datas_especiais(cal, date(2026, 10, 31))))


if __name__ == "__main__":
    unittest.main()

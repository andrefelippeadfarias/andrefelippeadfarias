"""Testes dos scripts da Skill. Rodar na pasta scripts/: python3 -m unittest test_scripts"""

from __future__ import annotations

import unittest
from datetime import date

import os

from comum import carregar_quartos, periodo_feriado
from ocupacao import calcular
from validar_plano import Validador, montar_payload

CFG = carregar_quartos()
# os testes conferem a lógica, não a calibração do dia: fatores de vitrine fixos (medição de 14/10)
CFG["parametros"]["fator_vitrine_semana"].update({"Recanto": 0.63, "Villa": 0.53})
CFG["parametros"]["fator_vitrine_fds"].update({"Recanto": 0.61, "Villa": 0.66})
# o mecanismo "sem desconto" do validador continua testado na Queen (2) como antes; no arquivo real ela saiu dessa regra
# em 21/10 (ordem do dono: entre a Queen 7 e a Double, ordem_recanto.py)
CFG["por_curto"]["Q2"].update({"sem_desconto": True, "fator_real": 0.34})
HOJE = date(2026, 10, 6)  # terça


def item(**kw):
    base = {"tipo": "data", "motivo": "teste"}
    base.update(kw)
    return base


def ocup(quarto, dia, vendidas, total, preco=None, idade_h=1.0):
    """Entrada de --precos para uma noite (com a idade do calendário do quarto)."""
    return {(quarto, dia): {"preco": preco, "livres": total - vendidas, "vendidas": vendidas, "total": total},
            "_meta": {"idade_h": {quarto: idade_h}}}


def erros(itens, precos=None, hoje=HOJE, **kw):
    v = Validador(CFG, hoje, dict(precos or {}), **kw)
    v.validar(itens)
    return v.erros


def avisos(itens, precos=None, hoje=HOJE, **kw):
    v = Validador(CFG, hoje, dict(precos or {}), **kw)
    v.validar(itens)
    return v.avisos


class TestValidador(unittest.TestCase):
    def test_regra_i_valida(self):
        # Regra I segue valendo na Balcony (sem banheira): 20% abaixo do mínimo, travado em 70% de R$ 900 = R$ 630
        self.assertEqual(erros([item(quarto="Balcony", data="2026-10-08", preco=-35, price_type="percent", min_price=630)],
                               ocup("Balcony", "2026-10-08", 3, 6)), [])
        # nas suítes com banheira, dia de semana não desce abaixo de R$ 500 no preço final (dono, 14/10)
        e = erros([item(quarto="Q7", data="2026-10-08", preco=-35, price_type="percent", min_price=640)],
                  ocup("Q7", "2026-10-08", 3, 7))
        self.assertTrue(any("piso de dia de semana" in x for x in e))

    def test_piso_vitrine_dia_de_semana(self):
        # Recanto: R$ 500 ÷ 0,63 = R$ 794 enviados; Villa: R$ 500 ÷ 0,53 = R$ 943
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-21", preco=-30, price_type="percent", min_price=800)]), [])
        self.assertTrue(any("piso de dia de semana" in x for x in erros(
            [item(quarto="VK7", data="2026-10-21", preco=-30, price_type="percent", min_price=850)])))
        self.assertEqual(erros([item(quarto="VK7", data="2026-10-21", preco=-30, price_type="percent", min_price=944)]), [])
        self.assertEqual(erros([item(quarto="VK2", data="2026-10-21", preco=-30, price_type="percent", min_price=944)]), [])
        # sexta não entra (vale o padrão de qualidade); a Balcony não entra
        self.assertEqual(erros([item(quarto="Balcony", data="2026-10-21", preco=-30, price_type="percent", min_price=690)]), [])
        # teto abaixo do piso de semana também é barrado
        self.assertTrue(any("piso de dia de semana" in x for x in erros(
            [item(quarto="VK2", data="2026-10-21", max_price=800)])))
        # ordem do dono libera com aviso
        v = Validador(CFG, HOJE, {})
        v.validar([item(quarto="VK7", data="2026-10-21", preco=-30, price_type="percent", min_price=850, excecao="dono")])
        self.assertFalse(any("piso de dia de semana" in x for x in v.erros))

    def test_regra_i_exige_calendario_e_respeita_ocupacao_e_classe(self):
        it = item(quarto="Balcony", data="2026-10-08", preco=-35, price_type="percent", min_price=630)
        self.assertTrue(any("exige --precos" in e for e in erros([it])))
        self.assertTrue(any("esgotada" in e for e in erros([it], ocup("Balcony", "2026-10-08", 6, 6))))
        self.assertTrue(any("70%" in e for e in erros([it], ocup("Balcony", "2026-10-08", 5, 6))))      # 83% a D2
        it0 = item(quarto="Balcony", data="2026-10-06", preco=-35, price_type="percent", min_price=630)
        self.assertEqual(erros([it0], ocup("Balcony", "2026-10-06", 5, 6)), [])                         # D0: só aviso
        self.assertTrue(any("D0" in a for a in avisos([it0], ocup("Balcony", "2026-10-06", 5, 6))))
        alta = item(quarto="VK7", data="2026-10-08", preco=-50, price_type="percent", min_price=760)   # VK7 é alta
        self.assertTrue(any("última unidade" in e for e in erros([alta], ocup("VK7", "2026-10-08", 3, 7))))
        # em alta e com 7 unidades, a última unidade já passa de 70%: só em D0 o corte segue (aviso), nunca começa em D1+
        alta0 = item(quarto="VK7", data="2026-10-06", preco=-50, price_type="percent", min_price=760)
        e0 = erros([alta0], ocup("VK7", "2026-10-06", 6, 7))
        self.assertTrue(e0 and all("piso de dia de semana" in x for x in e0))  # só a regra de R$ 500 barra (dono, 14/10)
        # calendário velho barra decisão por ocupação; --aceitar-velho rebaixa para aviso
        self.assertTrue(any("calendário" in e for e in erros([it], ocup("Balcony", "2026-10-08", 3, 6, idade_h=20))))
        self.assertEqual(erros([it], ocup("Balcony", "2026-10-08", 3, 6, idade_h=20), aceitar_velho=True), [])

    def test_estadia_de_feriado_duplicata_e_queen2_sem_antes(self):
        fin = item(quarto="Q7", data="2026-10-30", preco=0, price_type="percent", min_price=1905, min_stay=1)
        self.assertTrue(any("estadia mínima 3" in e for e in erros([fin])))                           # Finados: 3 noites
        dup = [item(quarto="Q7", data="2026-10-21", min_stay=1), item(quarto="Q7", data="2026-10-21", min_stay=2)]
        self.assertTrue(any("duplicado" in e for e in erros(dup)))
        q2 = item(quarto="Q2", data="2026-10-21", preco=0, price_type="percent", min_price=900)
        self.assertTrue(any("preco_antes" in e for e in erros([q2])))
        self.assertEqual(erros([dict(q2, preco_antes=900)]), [])

    def test_balcony_teto_acima_da_suite(self):
        itens = [item(quarto="VK2", data="2026-10-21", preco=-24, price_type="percent", min_price=765),
                 item(quarto="Balcony", data="2026-10-21", preco=-31, price_type="percent", min_price=689, max_price=900)]
        self.assertTrue(any("teto da Balcony" in e for e in erros(itens)))

    def test_regra_i_sem_min_price(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-08", preco=-35, price_type="percent")]))

    def test_fixo_so_com_ordem_do_dono(self):
        e = erros([item(quarto="Q7", data="2026-10-08", preco=640, price_type="fixed", min_price=640)])
        self.assertTrue(any("percentual" in x for x in e))
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-20", preco=900, price_type="fixed",
                                     excecao="dono")]), [])

    def test_percentual_piso_abaixo_do_minimo_longe(self):
        self.assertTrue(erros([item(quarto="VK7", data="2026-10-20", preco=-35, price_type="percent", min_price=700)]))
        self.assertEqual(erros([item(quarto="VK7", data="2026-10-20", preco=-35, price_type="percent", min_price=944)]), [])

    def test_max_price_abaixo_do_piso(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-20", preco=-10, price_type="percent",
                                    min_price=1200, max_price=1100)]))

    def test_abaixo_do_minimo_longe(self):
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-20", preco=-30, price_type="percent", min_price=700)]))

    def test_limite_seguranca(self):
        e = erros([item(quarto="Q7", data="2026-10-07", preco=-50, price_type="percent", min_price=500)])
        self.assertTrue(any("segurança" in x for x in e))

    def test_limite_seguranca_com_excecao_do_dono(self):
        cfg = dict(CFG, limites_seguranca=dict(CFG["limites_seguranca"], excecoes_dono=[
            {"quarto": "Afrodite", "de": "2026-10-26", "ate": "2026-11-08", "limite": 800}]))
        it = dict(tipo="data", motivo="teste", quarto="Afrodite", min_price=800, max_price=800, excecao="dono")
        v = Validador(cfg, date(2026, 10, 26), {})
        v.validar([dict(it, data="2026-10-27")])
        self.assertFalse(any("segurança" in x for x in v.erros))
        v = Validador(cfg, date(2026, 10, 26), {})
        v.validar([dict(it, data="2026-11-10")])  # fora do período: o limite normal volta
        self.assertTrue(any("segurança" in x for x in v.erros))

    def test_escada_por_reserva(self):
        from escada_reservas import passo, ordem, teto
        hoje = date(2026, 10, 26)
        self.assertEqual(passo(CFG, "Q7", date(2026, 10, 27), 739, 1, hoje)["novo"], 791)
        # D0 não sobe; o teto segura; cancelamento desce
        self.assertEqual(passo(CFG, "Q7", hoje, 739, 1, hoje)["novo"], 739)
        lim = teto(CFG, "Q7", date(2026, 10, 27))
        self.assertLessEqual(passo(CFG, "Q7", date(2026, 10, 27), lim - 5, 3, hoje)["novo"], lim)
        self.assertLess(passo(CFG, "Q7", date(2026, 10, 27), 791, -1, hoje)["novo"], 791)
        # fim de semana usa o teto de sexta e sábado; Balcony fica abaixo da VK2
        self.assertGreater(teto(CFG, "Q7", date(2026, 11, 6)), lim)
        self.assertLess(teto(CFG, "Balcony", date(2026, 10, 27)), teto(CFG, "VK2", date(2026, 10, 27)))
        self.assertEqual(ordem(791, 747, 755, 800), {"Q7": 791, "Q2": 791, "Double": 791, "Afrodite": 831})

    def test_afrodite_fora_da_regra_i(self):
        self.assertTrue(erros([item(quarto="Afrodite", data="2026-10-07", preco=-30, price_type="percent", min_price=1300)]))

    def test_q2_sem_desconto(self):
        self.assertTrue(erros([item(quarto="Q2", data="2026-10-20", preco=-15, price_type="percent", min_price=850)]))
        self.assertEqual(erros([item(quarto="Q2", data="2026-10-09", preco=-17, price_type="percent", min_price=2950,
                                     preco_antes=3550, excecao="piso_feriado")]), [])

    def test_teto_e_desconto_e_piso_vigente(self):
        # 19/10: teto abaixo do preço atual na Queen (2) é desconto
        e = erros([item(quarto="Q2", data="2026-10-30", max_price=3389, preco_antes=3987)])
        self.assertTrue(any("não recebe desconto" in x for x in e))
        self.assertTrue(erros([item(quarto="Q2", data="2026-10-30", max_price=3389)]))
        self.assertFalse(any("não recebe desconto" in x for x in
                             erros([item(quarto="Q2", data="2026-10-30", max_price=4200, preco_antes=3987)])))
        # piso vigente (personalização de fim de semana) acima do teto deixa o teto sem efeito
        p = ocup("VK2", "2026-12-04", 0, 2, preco=1863)
        p[("VK2", "2026-12-04")]["piso"] = 1816
        self.assertTrue(any("piso vigente" in x for x in erros([item(quarto="VK2", data="2026-12-04", max_price=1584)], p)))
        self.assertFalse(any("piso vigente" in x for x in erros(
            [item(quarto="VK2", data="2026-12-04", min_price=1560, max_price=1584)], p)))

    def test_sabado_sozinho(self):
        self.assertTrue(erros([item(quarto="Double", data="2026-10-17", min_stay=1)]))
        quarta = date(2026, 10, 14)
        self.assertEqual(erros([item(quarto="Double", data="2026-10-17", min_stay=1, excecao="regra_h",
                                     ocupacao_sabado=26)], hoje=quarta), [])
        # feriado nunca libera sábado sozinho
        self.assertTrue(erros([item(quarto="Double", data="2026-10-10", min_stay=1, excecao="regra_h",
                                    ocupacao_sabado=10)], hoje=date(2026, 10, 7)))
        # ordem expressa do dono libera fora de feriado (dono, 23/10: Moinhos em 24/10)
        self.assertFalse(any("sábado com 1 noite" in x for x in erros(
            [item(quarto="Double", data="2026-10-17", min_stay=1, excecao="dono")], hoje=quarta)))
        self.assertTrue(any("sábado com 1 noite" in x for x in erros(
            [item(quarto="Double", data="2026-10-10", min_stay=1, excecao="dono")], hoje=date(2026, 10, 7))))

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
        # dono (14/10): sexta e sábado a partir de R$ 850 no preço final = R$ 1.394 enviados no Recanto (0,61) e
        # R$ 1.288 na Villa (0,66); o R$ 1.000 enviado de antes já não basta
        self.assertTrue(any("piso de fim de semana" in x for x in erros([item(quarto="Q7", min_price=1000, **base)])))
        self.assertEqual(erros([item(quarto="Q7", min_price=1394, **base)]), [])
        self.assertEqual(erros([item(quarto="VK2", data="2026-10-24", preco=-30, price_type="percent", min_price=1288)]), [])
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-21", preco=-35, price_type="percent",
                                     min_price=800)]), [])
        self.assertEqual(erros([item(quarto="Balcony", data="2026-10-23", preco=-30, price_type="percent",
                                     min_price=765)]), [])
        self.assertEqual(erros([item(quarto="Afrodite", min_price=1500, **base)]), [])

    def test_padrao_de_qualidade_com_justificativa_de_mercado(self):
        base = dict(quarto="VK2", data="2026-10-24", preco=-30, price_type="percent", min_price=879)
        # justificativa curta demais não vale
        self.assertTrue(erros([item(mercado="fraco", **base)]))
        just = "mediana Booking 23-25/10 R$ 1.015 e mercado a 13% de ocupação"
        # desde 14/10 o piso de R$ 850 no preço final de sexta e sábado não aceita exceção de mercado (só o dono)
        v = Validador(CFG, HOJE, {})
        v.validar([item(mercado=just, **base)])
        self.assertTrue(any("piso de fim de semana" in e for e in v.erros))
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
        base = dict(quarto="VK2", data="2026-10-24", preco=-30, price_type="percent", min_price=879)
        self.assertTrue(erros([item(mercado="sem dado nenhum aqui ok mesmo", **base)]))
        self.assertTrue(erros([item(mercado=["mediana", "booking", 1015], **base)]))
        self.assertTrue(erros([item(mercado=12345678901234567890, **base)]))
        # fronteira: o piso vai ao PriceLabs como variação % sobre a base (dono 14/10), então vale o valor efetivo:
        # 1.385 vira +19% = R$ 1.396 e passa; 1.380 vira +18% = R$ 1.384 e não passa (piso R$ 1.393)
        self.assertEqual(erros([item(quarto="Q7", data="2026-10-23", preco=-20, price_type="percent",
                                     min_price=1385)]), [])
        self.assertTrue(erros([item(quarto="Q7", data="2026-10-23", preco=-20, price_type="percent",
                                    min_price=1380)]))

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
        # dono (14/10): correções só em percentual por data; mudar o anúncio exige a ordem do dono
        self.assertTrue(any("correções percentuais" in e for e in erros(
            [{"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 900, "motivo": "x"}])))
        self.assertTrue(erros([{"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 700, "motivo": "x", "excecao": "dono"}]))
        self.assertEqual(erros([{"tipo": "anuncio", "quarto": "VK7", "campo": "min", "valor": 900, "motivo": "x", "excecao": "dono"}]), [])

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
        # regra do dono (14/10): piso e teto sempre em percentual da base, como VARIAÇÃO sobre a base (Q7 base 1.173):
        # piso 640 = base -45,4% arredonda para cima (-45% = R$ 645); teto 900 = base -23,3% arredonda para baixo (-24% = R$ 891)
        self.assertEqual(o["overrides"][0]["min_price"], "-45")
        self.assertEqual(o["overrides"][0]["min_price_type"], "percent_base")
        self.assertEqual(o["overrides"][0]["max_price"], "-24")
        self.assertEqual(o["overrides"][0]["max_price_type"], "percent_base")
        self.assertEqual(o["overrides"][0]["currency"], "BRL")
        self.assertNotIn("fixed", str(o["overrides"][0]))

    def test_payload_teto_nunca_abaixo_do_piso_em_percentual(self):
        from validar_plano import piso_pct, teto_pct, em_reais
        q = CFG["por_curto"]["Q7"]
        # convenção medida em 14/10 (VK2 18/10): -39 percent_base = base x 0,61 = R$ 775; 61 daria R$ 2.045
        self.assertEqual(piso_pct(CFG["por_curto"]["VK2"], 765), -39)
        self.assertEqual(em_reais(CFG["por_curto"]["VK2"], -39), 775)
        self.assertEqual(piso_pct(q, 800), -31)       # 800/1173 = base -31,8% -> -31% (R$ 809, nunca abaixo do pedido)
        self.assertGreaterEqual(em_reais(q, piso_pct(q, 800)), 800)
        self.assertEqual(teto_pct(q, 1173), 0)
        self.assertEqual(piso_pct(q, 1173), 0)
        self.assertEqual(piso_pct(q, 2000), 71)       # acima da base o percentual é positivo
        self.assertEqual(em_reais(q, 71), 2006)
        # piso e teto iguais em reais: o teto em % nunca fica abaixo do piso em %
        p = montar_payload(CFG, [item(quarto="Q7", data="2026-10-08", preco=-30, price_type="percent",
                                      min_price=805, max_price=805)])
        o = p["update_listing_date_overrides"][0]["overrides"][0]
        self.assertEqual(o["min_price"], "-31")
        self.assertEqual(o["max_price"], "-31")


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
        m, n = mesclar_quarto(quarto, refresh)
        self.assertEqual(n, 2)
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
        ma, _ = mesclar_quarto(afro, ra)
        self.assertEqual([d["booking_status"] for d in ma["days"]], ["Booked", ""])
        self.assertIsNone(ma["days"][0]["multi_unit_occupancy"])
        agora = datetime(2026, 10, 13, 12, 0, tzinfo=timezone.utc)
        self.assertEqual([c for c, _ in quartos_velhos({"rooms": {"VK7": quarto, "Q7": {"last_refreshed_at": "2026-10-13T11:00:00Z"}}}, 6, agora)], ["VK7"])
        # refresh vazio: zero trocados (o chamador não grava)
        self.assertEqual(mesclar_quarto(quarto, {"success": False})[1], 0)


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
        # classe alta: na meta ainda sobe devagar até alvo+1 e, à frente, chega a alvo+2
        self.assertEqual(a(2, 2, 0.6, 0.7, False, 5, "alta")[1], 5)
        self.assertEqual(a(3, 2, 0.9, 0.7, False, 5, "alta")[1], 10)
        # classe baixa: na meta desce devagar até alvo−1 (não abaixo de 'baixo' fora de D0-D3)
        self.assertEqual(a(2, 2, 0.6, 0.7, False, 5, "baixa")[1], -5)
        self.assertEqual(a(1, 1, 0.6, 0.7, False, 5, "baixa")[1], 0)

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
        self.assertGreaterEqual(por["Q2"]["passo"], 0)   # sem desconto: nunca desce (2 unidades: usa a ocupação da pousada)
        self.assertEqual(por["Q2"]["faixa"], 2)          # 1519 × 0,61 = 927 → médio no conjunto do Recanto
        self.assertEqual(por["Q7"]["passo"], 0)          # esgotada
        self.assertTrue(por["Q7"]["acao"].startswith("esgotada"))

    def test_fator_de_vitrine_de_fim_de_semana_na_villa(self):
        from faixas_de_preco import classificar_quartos
        from sazonalidade import carregar
        coleta = {"rooms": {"VK7": {"days": [
            {"date": "2026-10-23", "price": 1101, "user_price": 1101, "min_stay": 1, "multi_unit_occupancy": "5/7", "booking_status": "Booked"},
            {"date": "2026-10-21", "price": 850, "user_price": 850, "min_stay": 1, "multi_unit_occupancy": "2/7", "booking_status": "Booked"}]}}}
        tab = {"x": {"tipo": "semana", "noites": 1, "checkin": "2026-10-21", "completa": True, "nossos": {},
                     "conjuntos": {"villa": {"n": 40, "cortes": [221, 305, 480, 744], "mediana": 400}}}}
        linhas = {l["data"]: l for l in classificar_quartos(coleta, tab, carregar(), CFG, date(2026, 10, 14), 21)}
        self.assertEqual(linhas["2026-10-23"]["vitrine"], round(1101 * 0.66))   # sexta: fator de fim de semana
        self.assertEqual(linhas["2026-10-21"]["vitrine"], round(850 * 0.53))    # quarta: fator de semana


class TestFaixasBordas(unittest.TestCase):
    def test_meta_no_arquivo_e_feriado_sem_busca(self):
        from faixas_de_preco import cortes_da_noite, classificar_quartos
        from sazonalidade import carregar
        tab = {"_meta": {"versao": "x", "conjuntos": {"recanto": "texto"}},
               "semana-20/10": {"tipo": "semana", "noites": 2, "checkin": "2026-10-20", "completa": True, "nossos": {},
                                "conjuntos": {"recanto": {"n": 50, "cortes": [330, 727, 1258, 1787], "mediana": 888}}},
               "fimdesemana-23/10": {"tipo": "fim de semana", "noites": 2, "checkin": "2026-10-23", "completa": True, "nossos": {},
                                     "conjuntos": {"recanto": {"n": 58, "cortes": [530, 719, 1165, 1679], "mediana": 826}}}}
        c, origem = cortes_da_noite(tab, date(2026, 10, 30), "recanto", "feriado")   # não quebra com _meta
        self.assertEqual((c, origem), ([530, 719, 1165, 1679], "tipo:fim de semana"))  # feriado sem busca própria usa fim de semana
        coleta = {"rooms": {"Q7": {"days": [{"date": "2026-10-30", "price": 2325, "user_price": 2325, "min_stay": 3, "multi_unit_occupancy": "0/7", "booking_status": ""}]}}}
        linhas = classificar_quartos(coleta, tab, carregar(), CFG, date(2026, 10, 14), 21)
        self.assertEqual(linhas[0]["passo"], 0)
        self.assertIn("feriado sem busca própria", linhas[0]["acao"])


class TestDiaIsolado(unittest.TestCase):
    def test_sabado_entre_esgotadas(self):
        from ler_recalculo import dia_isolado
        q = {"2026-10-16": {"mu_occupancy": "7/7"}, "2026-10-17": {"mu_occupancy": "6/7", "min_stay": "1"}, "2026-10-18": {"mu_occupancy": "7/7"}}
        self.assertTrue(dia_isolado(q, "2026-10-17"))
        q["2026-10-18"]["mu_occupancy"] = "5/7"
        self.assertFalse(dia_isolado(q, "2026-10-17"))


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




class TestModoFaturamento(unittest.TestCase):
    def test_modo_sobrepoe_classe(self):
        import faixas_de_preco as fp
        q = {"pousada": "Villa", "demanda": "media"}
        self.assertEqual(fp.demanda_efetiva(q, {}), "media")
        self.assertEqual(fp.demanda_efetiva(q, {"modo": {"Villa": "volume"}}), "baixa")
        self.assertEqual(fp.demanda_efetiva(q, {"modo": {"Villa": "preco"}}), "alta")
        self.assertEqual(fp.demanda_efetiva(q, {"modo": {"Recanto": "preco"}}), "media")
        self.assertEqual(fp.demanda_efetiva({"pousada": "Recanto", "demanda": "alta"}, {"modo": {"Recanto": "volume"}}), "baixa")


class TestPisosPercentual(unittest.TestCase):
    """Conversão das regras antigas (piso/teto em reais) para variação % sobre a base (dono, 14/10)."""
    ANTES = [
        {"date": "2026-10-21", "price": "-24", "price_type": "percent", "min_price": 765, "min_price_type": "fixed",
         "currency": "BRL", "max_price": 765, "max_price_type": "fixed", "reason": "teto"},
        {"date": "2026-10-30", "price": "20", "price_type": "percent", "min_stay": 3, "min_price": 2060,
         "min_price_type": "fixed", "currency": "BRL", "reason": "feriado"},
        {"date": "2026-11-06", "min_price": 1000, "min_price_type": "fixed", "currency": "BRL", "reason": "padrao"},
        {"date": "2026-12-29", "min_stay": 3, "reason": "so estadia"},
        {"date": "2026-10-18", "price": "-24", "price_type": "percent", "min_price": -39, "min_price_type": "percent_base"},
    ]

    def _arquivos(self, depois):
        import json
        import tempfile
        d = tempfile.mkdtemp()
        a, b = os.path.join(d, "antes.json"), os.path.join(d, "depois.json")
        with open(a, "w") as f:
            json.dump({"success": True, "data": {"overrides": self.ANTES}}, f)
        with open(b, "w") as f:
            json.dump(depois, f)
        return a, b, os.path.join(d, "payload.json")

    def test_gerar_converte_so_os_fixos(self):
        import json
        import pisos_percentual as pp
        a, _, saida = self._arquivos([])
        self.assertEqual(pp.main(["gerar", a, "VK2", "--saida", saida, "--lote", "2"]), 0)
        p = json.load(open(saida))
        self.assertEqual(p["listing_id"], "350364___722815")
        lotes = p["lotes"]
        self.assertEqual([len(l) for l in lotes], [2, 1])
        o = {x["date"]: x for l in lotes for x in l}
        self.assertEqual(set(o), {"2026-10-21", "2026-10-30", "2026-11-06"})
        self.assertEqual(o["2026-10-21"], {"date": "2026-10-21", "min_price": "-39", "min_price_type": "percent_base",
                                           "currency": "BRL", "max_price": "-39", "max_price_type": "percent_base"})
        self.assertEqual(o["2026-10-30"]["min_price"], "63")     # 2060/1270 = +62,2% -> +63% = R$ 2.070
        self.assertEqual(o["2026-11-06"]["min_price"], "-21")    # 1000/1270 = -21,3% -> -21% = R$ 1.003
        for x in o.values():  # só piso/teto: a gravação junta campos e preço, estadia e motivo ficam
            self.assertFalse({"price", "min_stay", "reason"} & set(x))

    def test_conferir_aceita_a_conversao_certa_e_barra_a_errada(self):
        import copy
        import pisos_percentual as pp
        certo = [
            {"date": "2026-10-21", "price": "-24", "price_type": "percent", "min_price": -39,
             "min_price_type": "percent_base", "max_price": -39, "max_price_type": "percent_base", "reason": "teto"},
            {"date": "2026-10-30", "price": "20", "price_type": "percent", "min_stay": 3, "min_price": 63,
             "min_price_type": "percent_base", "reason": "feriado"},
            {"date": "2026-11-06", "min_price": -21, "min_price_type": "percent_base", "reason": "padrao"},
            {"date": "2026-12-29", "min_stay": 3, "reason": "so estadia"},
            {"date": "2026-10-18", "price": "-24", "price_type": "percent", "min_price": -39, "min_price_type": "percent_base"},
        ]
        a, b, _ = self._arquivos(certo)
        self.assertEqual(pp.main(["conferir", a, b, "VK2"]), 0)
        ruim = copy.deepcopy(certo)
        ruim[0]["max_price"] = -45                 # teto abaixo do piso
        ruim[1]["min_price"] = 62                  # piso efetivo abaixo do antigo
        ruim[2]["price"] = "-10"                   # campo que mudou sem ser conversão
        ruim[3].update(min_price=700, min_price_type="fixed")  # fixed que sobrou
        del ruim[4]                                # regra que sumiu
        a, b, _ = self._arquivos(ruim)
        self.assertEqual(pp.main(["conferir", a, b, "VK2"]), 1)

    def test_conferir_piso_zero_por_cento_com_teto_levantado(self):
        # Balcony 16/10 (14/10): piso e teto R$ 1.264 na base 1.270 viram 0% e 0% (R$ 1.270). O teto sobe R$ 6
        # porque nunca fica abaixo do piso; o 0 não pode ser lido como "sem piso".
        import json
        import tempfile
        import pisos_percentual as pp
        d = tempfile.mkdtemp()
        a, b = os.path.join(d, "a.json"), os.path.join(d, "b.json")
        json.dump([{"date": "2026-10-16", "min_price": 1264, "min_price_type": "fixed", "max_price": 1264,
                    "max_price_type": "fixed"}], open(a, "w"))
        json.dump([{"date": "2026-10-16", "min_price": 0, "min_price_type": "percent_base", "max_price": 0,
                    "max_price_type": "percent_base"}], open(b, "w"))
        self.assertEqual(pp.main(["conferir", a, b, "Balcony"]), 0)


class TestBeds24(unittest.TestCase):
    """Contagem do beds24.py: checkout exclusivo, status separados, receita por itens de fatura."""

    def test_janelas(self):
        import beds24
        lista = [{"propertyId": 350362, "status": "confirmed", "arrival": "2026-10-19", "departure": "2026-10-21", "price": 1000},
                 {"propertyId": 350362, "status": "new", "arrival": "2026-10-20", "departure": "2026-10-21", "price": 600},
                 {"propertyId": 350364, "status": "inquiry", "arrival": "2026-10-19", "departure": "2026-10-20", "price": 500},
                 {"propertyId": 350364, "status": "confirmed", "arrival": "2026-10-19", "departure": "2026-10-20", "price": 0,
                  "invoiceItems": [{"type": "charge", "amount": 450, "qty": 1}, {"type": "payment", "amount": 450}]}]
        rec, villa = beds24.janelas(lista, date(2026, 10, 19), dias=(7,))
        self.assertEqual((rec["un_confirmadas"], rec["un_novas"], rec["un_ocupadas"], rec["capacidade"]), (2, 1, 3, 112))
        self.assertEqual(rec["receita"], 1600.0)
        self.assertEqual((villa["un_ocupadas"], villa["receita"], villa["outros_status"]), (1, 450.0, {"inquiry": 1}))

    def test_sem_credencial(self):
        import beds24
        antes = {k: os.environ.pop(k, None) for k in ("BEDS24_TOKEN", "BEDS24_REFRESH_TOKEN", "BEDS24_REFRESH_TOKEN_FILE")}
        os.environ["BEDS24_REFRESH_TOKEN_FILE"] = "/nao/existe"
        os.environ["BEDS24_SEM_SEGREDO_DE_REDE"] = "1"
        try:
            self.assertEqual(beds24.main(["teste"]), 2)
            os.environ.pop("BEDS24_SEM_SEGREDO_DE_REDE")
            self.assertIsNone(beds24.token_de_acesso())  # modo segredo de rede: o proxy põe o token
        finally:
            os.environ.pop("BEDS24_SEM_SEGREDO_DE_REDE", None)
            os.environ.pop("BEDS24_REFRESH_TOKEN_FILE", None)
            for k, v in antes.items():
                if v is not None:
                    os.environ[k] = v


class TestBeds24Extra(unittest.TestCase):
    def setUp(self):
        self.lista = [{"propertyId": 350362, "roomId": 722805, "status": "confirmed", "arrival": "2026-10-30",
                       "departure": "2026-11-02", "price": 3000, "channel": "booking"},
                      {"propertyId": 350364, "roomId": 722815, "status": "new", "arrival": "2026-10-31",
                       "departure": "2026-11-01", "price": 900, "channel": "airbnb"},
                      {"propertyId": 350364, "roomId": 722813, "status": "inquiry", "arrival": "2026-10-31",
                       "departure": "2026-11-01", "price": 900}]

    def test_livro_por_mes(self):
        import beds24
        res = beds24.livro(self.lista, date(2026, 10, 1), date(2026, 12, 1))
        por = {(r["mes"], r["pousada"]): r for r in res}
        self.assertEqual(por[("2026-10", "Recanto")]["receita"], 2000.0)
        self.assertEqual(por[("2026-11", "Recanto")]["unidades_noite"], 1)
        self.assertEqual(por[("2026-10", "Villa")]["receita_confirmadas"], 0.0)
        self.assertEqual(por[("2026-10", "Villa")]["por_canal"], {"airbnb": 900.0})

    def test_comparar(self):
        import beds24
        coleta = {"rooms": {"Q7": {"days": [{"date": "2026-10-30", "multi_unit_occupancy": "1/7"},
                                             {"date": "2026-10-31", "multi_unit_occupancy": "3/7"}]},
                            "VK7": {"days": [{"date": "2026-10-31", "multi_unit_occupancy": "0/7"}]}}}
        dif = beds24.comparar(self.lista, coleta, date(2026, 10, 30), date(2026, 11, 2))
        self.assertEqual(dif, [{"quarto": "Q7", "data": "2026-10-31", "pricelabs": 3, "beds24": 1, "diferenca": 2}])


class TestConcorrenciaHidroVista(unittest.TestCase):
    def test_corta_sem_q2_e_respeita_bloqueio(self):
        import concorrencia_hidro_vista as c
        dia = lambda d, p, mu: {"date": d, "price": p, "min_stay": 2, "multi_unit_occupancy": mu, "booking_status": ""}
        coleta = {"rooms": {
            "Q7": {"days": [dia("2026-12-04", 2000, "0/7")]},
            "Q2": {"days": [dia("2026-12-04", 3000, "0/2")]},
            "VK7": {"days": [dia("2026-12-04", 2100, "7/7")]},   # fechado no calendário
            "VK2": {"days": [dia("2026-12-04", 1860, "0/2")], "overrides": [{"date": "2026-12-04", "min_price": 43}]},
            "Balcony": {"days": [dia("2026-12-04", 1640, "0/6")]}}}
        comp = {"periodos": [{"rotulo": "t", "datas": ["2026-12-04"], "mediana_a": 600, "recanto": 1100}]}
        itens, _ = c.montar(CFG, comp, coleta, date(2026, 10, 19), {("VK7", "2026-12-04"): 1})
        por = {i["quarto"]: i for i in itens}
        self.assertNotIn("Q2", por)                      # sem desconto
        self.assertNotIn("VK7", por)                     # bloqueado: não está à venda
        self.assertEqual(por["Q7"]["max_price"], 1700)   # -15% no máximo
        self.assertIn("min_price", por["VK2"])           # piso de 43% vigente baixa junto com o teto
        self.assertLess(por["VK2"]["min_price"], por["VK2"]["max_price"])
        self.assertLessEqual(por["Balcony"]["max_price"], round(por["VK2"]["max_price"] * 0.9))


class TestMercadoDiario(unittest.TestCase):
    def test_estadias_do_dia(self):
        import mercado_diario as m
        e = m.estadias(date(2026, 10, 20), CFG)
        datas = [(a.isoformat(), b.isoformat()) for a, b, _ in e]
        self.assertIn(("2026-10-23", "2026-10-25"), datas)          # próxima sexta a domingo
        self.assertIn(("2026-10-21", "2026-10-22"), datas)          # próxima quarta
        self.assertIn(("2026-10-30", "2026-11-02"), datas)          # Finados (feriado a até 60 dias)
        self.assertEqual(len([1 for _, _, t in e if t == "fim de semana"]), 3)


class TestDesempenhoMes(unittest.TestCase):
    def test_feitas_e_estadias(self):
        import datetime as dt
        import desempenho_mes as m
        b = lambda i, st, ch, chegada, saida, preco, feita, canc=None: {
            "id": i, "propertyId": 350362, "roomId": 722805, "status": st, "channel": ch, "referer": "x",
            "arrival": chegada, "departure": saida, "price": preco, "bookingTime": feita, "cancelTime": canc}
        L = [b(1, "confirmed", "booking", "2026-10-10", "2026-10-12", 1000, "2026-10-02T12:00:00Z"),
             b(2, "cancelled", "airbnb", "2026-10-25", "2026-10-26", 500, "2026-10-03T12:00:00Z", "2026-10-05T12:00:00Z"),
             b(3, "confirmed", "booking", "2026-10-30", "2026-11-01", 2000, "2026-09-20T12:00:00Z")]
        r = m.calcular(L, "2026-10", dt.datetime(2026, 10, 21, 14, tzinfo=dt.timezone.utc))
        g = r["feitas"]["Recanto"]["geral"]
        self.assertEqual((g["reservas"], g["canceladas"], g["ativas"], g["diarias"], g["adr"]), (2, 1, 1, 2, 500))
        e = r["estadias"]["Recanto"]
        self.assertEqual(e["realizado"]["receita"], 1000)          # 10 e 11/10, antes de hoje
        self.assertEqual(e["vendido_ate_o_fim"]["receita"], 2000)  # 30 e 31/10, reserva feita em setembro
        self.assertEqual(r["cancelamentos_no_mes"]["Recanto"]["quantidade"], 1)


class TestPainelSemanal(unittest.TestCase):
    def test_semanas_e_estadias(self):
        import datetime as dt
        import painel_semanal as ps
        b = lambda i, st, chegada, saida, preco, feita: {"id": i, "propertyId": 350364, "roomId": 722813, "status": st,
                                                          "channel": "booking", "arrival": chegada, "departure": saida,
                                                          "price": preco, "bookingTime": feita}
        L = [b(1, "confirmed", "2026-10-20", "2026-10-22", 1200, "2026-10-13T15:00:00Z"),
             b(2, "confirmed", "2026-10-24", "2026-10-25", 900, "2026-10-20T15:00:00Z")]
        r = ps.montar(L, dt.datetime(2026, 10, 21, 14, tzinfo=dt.timezone.utc))
        s = {(x["semana"], x["pousada"]): x for x in r["semanas"]}
        self.assertTrue(s[("2026-10-12", "Villa")]["completa"])
        self.assertEqual(s[("2026-10-12", "Villa")]["receita"], 1200)
        self.assertFalse(s[("2026-10-19", "Villa")]["completa"])
        e = {(x["semana"], x["pousada"]): x for x in r["estadias"]}
        self.assertEqual(e[("2026-10-19", "Villa")]["diarias"], 3)   # 20, 21 e 24/10
        self.assertEqual(e[("2026-10-19", "Villa")]["momento"], "atual")


class TestCalendario12m(unittest.TestCase):
    def test_exigencias_e_comparacao(self):
        import datetime as dt
        import calendario_12m as c12
        # sábado comum de maio/27 na Queen 7: piso de fim de semana (850 ÷ 0,425 = 2.000) e 2 noites
        ex = c12.exigencias(CFG, CFG["por_curto"]["Q7"], dt.date(2027, 5, 1))
        self.assertEqual(ex["min_stay"], 2)
        fat = CFG["parametros"]["fator_vitrine_fds"]["Recanto"]
        self.assertEqual(ex["piso"], round(max(CFG["por_curto"]["Q7"]["min"], CFG["limites_seguranca"]["piso_vitrine_fds_suites"] / fat)))
        # Balcony fora de feriado: sem piso de vitrine
        self.assertIsNone(c12.exigencias(CFG, CFG["por_curto"]["Balcony"], dt.date(2027, 5, 4))["piso"])
        cal = {"2027-05-01": {"min_price": "900", "min_stay": "1", "price": "2179", "check_in": "-1", "check_out": "-1"},
               "2027-05-04": {"min_price": "1300", "min_stay": "1", "price": "1400", "check_in": "-1", "check_out": "-1"}}
        dif = c12.comparar(CFG, "Q7", cal, dt.date(2027, 5, 1), dt.date(2027, 5, 4))
        datas = {x["data"]: x for x in dif}
        self.assertIn("2027-05-01", datas)                         # piso e estadia abaixo da regra
        self.assertEqual(datas["2027-05-01"]["item"]["min_stay"], 2)
        self.assertNotIn("2027-05-04", datas)                      # terça com piso acima de 1.220: nada a fazer
        self.assertIn("sem dado de preço no recálculo", datas["2027-05-02"]["falta"])

    def test_validador_chegada_saida(self):
        from datetime import date
        v = Validador(CFG, date(2026, 10, 21))
        v.validar([{"tipo": "data", "quarto": "Double", "data": "2027-05-04", "check_in": "1011111", "motivo": "teste"}])
        self.assertTrue(any("não é sábado" in e for e in v.erros))
        v = Validador(CFG, date(2026, 10, 21))
        v.validar([{"tipo": "data", "quarto": "Double", "data": "2027-05-01", "check_in": "1111101", "min_stay": 2,
                    "motivo": "teste"}])
        self.assertFalse(any("chegada" in e or "check_in" in e for e in v.erros))


class TestRevisao12m(unittest.TestCase):
    def test_devida_e_meses(self):
        import datetime as dt
        import revisao_12m as r
        self.assertTrue(r.devida(dt.date(2026, 10, 21), {"revisoes": []})[0])
        h = {"revisoes": [{"hoje": "2026-10-21", "linhas": []}]}
        self.assertFalse(r.devida(dt.date(2026, 11, 4), h)[0])     # 14 dias
        self.assertTrue(r.devida(dt.date(2026, 11, 5), h)[0])      # 15 dias
        m = r.meses(dt.date(2026, 10, 21))
        self.assertEqual((m[0], m[-1], len(m)), ("2026-10", "2027-10", 13))


class TestOrdemRecanto(unittest.TestCase):
    def test_faixa(self):
        import datetime as dt
        import ordem_recanto as o
        d = dt.date(2027, 5, 4)   # terça comum
        f = o.faixa(CFG, d, {"price": "1232"}, {"price": "1302"}, {"price": "1613", "min_price": "1232"})
        self.assertTrue(f["fora"])
        self.assertGreaterEqual(f["teto"], f["piso"])
        self.assertTrue(1232 <= f["teto"] <= 1302)
        f = o.faixa(CFG, d, {"price": "1232"}, {"price": "1302"}, {"price": "1290", "min_price": "1232"})
        self.assertFalse(f["fora"])
        p = o.montar_plano([{"data": "2027-05-04", "quarto": "Q2", "moinhos": 1232, "panoramica_1": 1302, "panoramica_2": 1613,
                             "piso": 1232, "teto": 1284}], dt.date(2026, 10, 21))
        v = Validador(CFG, dt.date(2026, 10, 21))
        v.validar(p["itens"])
        self.assertEqual(v.erros, [])


class TestOrdemAfrodite(unittest.TestCase):
    def test_afrodite_mais_cara(self):
        import datetime as dt
        import ordem_recanto as o
        g = o.faixa_afrodite(CFG, dt.date(2027, 5, 4), {"price": "1232"}, {"price": "2235"}, 1300, {"price": "1500"})
        self.assertTrue(g["fora"])
        self.assertGreaterEqual(g["piso"], round(2235 * 1.05))
        g = o.faixa_afrodite(CFG, dt.date(2027, 5, 4), {"price": "1232"}, {"price": "1288"}, 1274, {"price": "1500"})
        self.assertFalse(g["fora"])


if __name__ == "__main__":
    unittest.main()

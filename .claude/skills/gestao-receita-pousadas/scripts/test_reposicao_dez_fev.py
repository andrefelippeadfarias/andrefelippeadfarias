"""Reposição de dez/2026 a fev/2027 pela régua de suítes comparáveis (dono 19/11, ordem
dono-2026-11-19-precificacao-dez-fev): régua do plano do dono, abertura da Moinhos, motor direto ao alvo e cadência.
Rodar na pasta scripts/: python3 -m unittest -q test_reposicao_dez_fev"""
from __future__ import annotations

import copy
import datetime as dt
import unittest
from datetime import date

import pace
import plano_dono as PD
import regua_dono_comparavel as RDC
from comum import carregar_quartos

HOJE = date(2026, 11, 19)
AGORA = dt.datetime(2026, 11, 19, 15, 0, tzinfo=dt.timezone.utc)
ORDEM = {"id": "dono-teste-reposicao", "data": "2026-11-19", "criada_em": "2026-11-19T14:20:00Z", "tipo": "regra",
         "quartos": ["Q7", "Double", "Afrodite", "Q2", "VK7", "VK2", "Balcony"], "de": "2026-12-01",
         "ate": "2027-02-28", "reposicionar": True, "libera": ["reposicionar"]}


def estadia(checkin, checkout, precos, notas=None, fator=0.62, medido=True, ok=True):
    notas = notas or [9.5] * len(precos)
    return {"checkin": checkin, "checkout": checkout, "principal_ok": ok,
            "fatores": {"Recanto": fator, "Villa": 0.56},
            "fonte_fator": {"Recanto": "medido (Moinhos)" if medido else "padrão", "Villa": "padrão"},
            "ranking": [{"slug": f"h{i}", "suite_noite": p, "nota": n, "certeza": "media"}
                        for i, (p, n) in enumerate(zip(precos, notas))]}


def row(noite, preco, v, u):
    return {"date": noite, "price": str(preco), "mu_occupancy": f"{v}/{u}" if u > 1 else "-1", "num_bookings": str(v),
            "min_price": str(preco), "max_price": str(preco), "min_stay": "2"}


class TestReguaDonoComparavel(unittest.TestCase):
    def test_series_percentis_pares_e_fator(self):
        precos = [600, 700, 800, 900, 1000, 1100, 1200, 1300, 1400, 1500]
        notas = [8.5, 8.6, 8.7, 8.8, 9.0, 9.2, 9.4, 8.9, 8.2, 9.6]  # 6 abaixo de 9
        s = RDC.series_da_estadia(estadia("2027-01-15", "2027-01-17", precos, notas))
        self.assertEqual(s["n"], 10)
        self.assertEqual(s["hidro"]["50"], 1050)
        self.assertEqual(s["n_pares"], 6)
        self.assertIn("nota abaixo de 9", s["pares_fonte"])
        self.assertAlmostEqual(s["fator_recanto"], 0.62)
        # poucos pares: pares = hidro; fator só quando medido
        s2 = RDC.series_da_estadia(estadia("2027-01-15", "2027-01-17", precos, [9.5] * 10, medido=False))
        self.assertEqual(s2["pares_hidro"], s2["hidro"])
        self.assertIsNone(s2["fator_recanto"])
        # menos de 8 comparáveis, ou sem principal_ok: não vira régua
        self.assertIsNone(RDC.series_da_estadia(estadia("2027-01-15", "2027-01-17", precos[:7])))
        self.assertIsNone(RDC.series_da_estadia(estadia("2027-01-15", "2027-01-17", precos, ok=False)))

    def test_atualizar_so_noites_cobertas_e_no_periodo(self):
        regua = {"noites": {
            "2027-01-15": {"tipo": "fds", "lista": {"40": 713}, "hidro": {"40": 986, "50": 1156},
                           "pares_hidro": {"50": 898}, "fator_publico": {"Recanto": 0.6157, "Villa": 0.6173}},
            "2027-01-16": {"tipo": "fds", "lista": {"40": 713}, "hidro": {"40": 986}, "pares_hidro": {},
                           "fator_publico": {"Recanto": 0.6157, "Villa": 0.6173}},
            "2027-01-22": {"tipo": "feriado", "hidro": {"40": 999}, "fator_publico": {"Recanto": 0.6, "Villa": 0.6}},
            "2027-03-05": {"tipo": "fds", "hidro": {"40": 1}, "fator_publico": {"Recanto": 0.6, "Villa": 0.6}}}}
        rk = {"estadias": [estadia("2027-01-15", "2027-01-17", [600 + 100 * i for i in range(10)], fator=0.63),
                           estadia("2027-03-05", "2027-03-07", [600 + 100 * i for i in range(10)])]}
        mud = RDC.atualizar(regua, [rk], "2026-12-01", "2027-02-28")
        self.assertEqual([m["noite"] for m in mud], ["2027-01-15", "2027-01-16"])
        n15 = regua["noites"]["2027-01-15"]
        self.assertTrue(n15["comparaveis"])
        self.assertEqual(n15["lista"], {"40": 713})                      # lista fica (Balcony)
        self.assertEqual(n15["antes_comparaveis"]["hidro"]["40"], 986)   # régua de antes guardada
        self.assertEqual(n15["fator_publico"], {"Recanto": 0.63, "Villa": 0.6173})
        self.assertNotIn("comparaveis", regua["noites"]["2027-01-22"])   # feriado sem estadia do grupo no mês
        self.assertNotIn("comparaveis", regua["noites"]["2027-03-05"])   # fora do período
        # rodar de novo não perde a régua original
        RDC.atualizar(regua, [rk], "2026-12-01", "2027-02-28")
        self.assertEqual(regua["noites"]["2027-01-15"]["antes_comparaveis"]["hidro"]["40"], 986)

    def test_noite_sem_estadia_usa_o_grupo_no_mes(self):
        regua = {"noites": {
            "2027-01-12": {"tipo": "semana", "hidro": {"50": 500}, "fator_publico": {"Recanto": 0.6, "Villa": 0.6}},
            "2027-01-14": {"tipo": "semana", "hidro": {"50": 500}, "fator_publico": {"Recanto": 0.6, "Villa": 0.6}},
            "2027-01-15": {"tipo": "fds", "hidro": {"50": 900}, "fator_publico": {"Recanto": 0.6, "Villa": 0.6}},
            "2027-01-16": {"tipo": "fds", "hidro": {"50": 900}, "fator_publico": {"Recanto": 0.6, "Villa": 0.6}},
            "2027-01-29": {"tipo": "fds", "hidro": {"50": 900}, "fator_publico": {"Recanto": 0.6, "Villa": 0.6}}}}
        rk = {"estadias": [estadia("2027-01-12", "2027-01-13", [600 + 50 * i for i in range(10)], fator=0.61),
                           estadia("2027-01-15", "2027-01-17", [1000 + 100 * i for i in range(10)], fator=0.63)]}
        mud = {m["noite"]: m for m in RDC.atualizar(regua, [rk], "2026-12-01", "2027-02-28")}
        self.assertEqual(sorted(mud), ["2027-01-12", "2027-01-14", "2027-01-15", "2027-01-16", "2027-01-29"])
        n14, n29 = regua["noites"]["2027-01-14"], regua["noites"]["2027-01-29"]
        self.assertEqual(n14["hidro"], regua["noites"]["2027-01-12"]["hidro"])   # semana do mês (só uma estadia)
        self.assertEqual(n29["hidro"], regua["noites"]["2027-01-15"]["hidro"])   # fds do mês
        self.assertIn("semana de 2027-01", n14["fonte_comparavel"])
        self.assertEqual(n14["fator_publico"]["Recanto"], 0.6)                   # agregado não troca o fator
        self.assertEqual(regua["noites"]["2027-01-12"]["fator_publico"]["Recanto"], 0.61)


class TestAberturaMoinhosComparavel(unittest.TestCase):
    def test_moinhos_fds_p40_da_lista_ou_da_comparavel(self):
        rn = {"tipo": "fds", "lista": {"40": 713, "50": 794}, "hidro": {"40": 1100, "50": 1250, "65": 1400},
              "pares_hidro": {"50": 1200, "65": 1350}, "fator_publico": {"Recanto": 0.62, "Villa": 0.56}}
        self.assertAlmostEqual(PD.abertura_publico("Q7", rn, "fds"), 713)
        rc = dict(rn, comparaveis=True)
        self.assertAlmostEqual(PD.abertura_publico("Q7", rc, "fds"), 1100)
        # Panorâmica I segue nos pares × 1,05; a Afrodite 15% acima dela; a Balcony fora do plano
        self.assertAlmostEqual(PD.abertura_publico("Double", rc, "fds"), 1200 * 1.05)
        self.assertAlmostEqual(PD.abertura_publico("Afrodite", rc, "fds"), 1200 * 1.05 * 1.15)
        self.assertIsNone(PD.abertura_publico("Balcony", rc, "fds"))
        # o pedido de 09/11 continua sendo o teto da abertura
        rp = dict(rc, pedido_0911={"Q7": 1500})
        self.assertAlmostEqual(PD.abertura_publico("Q7", rp, "fds"), 1500 * 0.62)


class TestReposicaoMotor(unittest.TestCase):
    def setUp(self):
        self.cfg = carregar_quartos()

    def test_ordem_reposicao_cobre(self):
        o = {ORDEM["id"]: ORDEM}
        self.assertIsNotNone(pace.ordem_reposicao(o, "Q7", date(2026, 12, 1)))
        self.assertIsNotNone(pace.ordem_reposicao(o, "Balcony", date(2027, 2, 28)))
        self.assertIsNone(pace.ordem_reposicao(o, "Q7", date(2026, 11, 30)))
        self.assertIsNone(pace.ordem_reposicao(o, "Q7", date(2027, 3, 1)))
        self.assertIsNone(pace.ordem_reposicao({"x": dict(ORDEM, reposicionar=False)}, "Q7", date(2027, 1, 5)))
        self.assertIsNone(pace.ordem_reposicao({"x": dict(ORDEM, revogada=True)}, "Q7", date(2027, 1, 5)))
        self.assertIsNone(pace.ordem_reposicao({"x": dict(ORDEM, quartos=["VK7"])}, "Q7", date(2027, 1, 5)))
        # uma vez só: no dia da ordem vale; no dia seguinte o motor volta ao passo diário
        self.assertIsNotNone(pace.ordem_reposicao(o, "Q7", date(2027, 1, 5), HOJE))
        self.assertIsNone(pace.ordem_reposicao(o, "Q7", date(2027, 1, 5), date(2026, 11, 20)))

    def test_no_dia_seguinte_volta_ao_passo(self):
        n = "2027-02-16"
        anc = {"noites": {n: {"p25": 530, "p50": 945, "p75": 1300, "p90": 1700, "max": 2500, "n": 20,
                              "busca_em": "2026-11-20", "tipo": "semana", "p50_medido": True, "comparaveis": True}},
               "por_tipo": {}}
        cals = {"Q7": {n: row(n, 1200, 0, 7)}, "Double": {n: row(n, 1400, 0, 6)}}
        res = pace.plano(self.cfg, anc, cals, date(2026, 11, 20), {ORDEM["id"]: ORDEM}, {"passos": []}, dias=88,
                         agora=AGORA + dt.timedelta(days=1), regra_j_ativa=False)
        q7 = {(i["quarto"], i["data"]): i for i in res["itens"]}[("Q7", n)]
        self.assertLessEqual(q7["min_price"], 1200 * 1.05 + 1)
        self.assertIsNone(q7.get("reposicao"))

    def cenario(self, ordens, passos=None):
        n = "2027-02-16"  # terça, D89, fora das faixas e do plano (dia de semana)
        anc = {"noites": {n: {"p25": 530, "p50": 945, "p75": 1300, "p90": 1700, "max": 2500, "n": 20,
                              "busca_em": "2026-11-19", "tipo": "semana", "p50_medido": True, "comparaveis": True}},
               "por_tipo": {}}
        cals = {"Q7": {n: row(n, 1200, 0, 7)}, "Double": {n: row(n, 1400, 0, 6)}}
        res = pace.plano(self.cfg, anc, cals, HOJE, ordens, passos or {"passos": []}, dias=89, agora=AGORA,
                         regra_j_ativa=False)
        return {(i["quarto"], i["data"]): i for i in res["itens"]}, {(l["quarto"], l["data"]): l for l in res["linhas"]}

    def test_direto_ao_alvo_com_a_ordem_e_passo_de_5pct_sem(self):
        n = "2027-02-16"
        sem, ls = self.cenario({})
        q7 = sem[("Q7", n)]
        self.assertLessEqual(q7["min_price"], 1200 * 1.05 + 1)          # pmax 5% a mais de 30 dias
        com, lc = self.cenario({ORDEM["id"]: ORDEM})
        q7r = com[("Q7", n)]
        self.assertAlmostEqual(q7r["min_price"], lc[("Q7", n)]["alvo_enviado"], delta=2)  # direto ao alvo
        self.assertGreater(q7r["min_price"], 1200 * 1.05 + 1)
        self.assertEqual(q7r.get("reposicao"), ORDEM["id"])
        self.assertTrue(q7r["motivo"].startswith("Reposição pela régua comparável"))
        self.assertEqual((q7r.get("excecao"), q7r.get("ordem_id")), ("dono", ORDEM["id"]))

    def test_ancora_do_mes_comparavel_reposiciona_e_mes_vizinho_nao(self):
        n = "2027-02-16"
        cals = {"Q7": {n: row(n, 1200, 0, 7)}, "Double": {n: row(n, 1400, 0, 6)}}
        base = {"p25": 530, "p50": 945, "p75": 1300, "p90": 1700, "max": 2500, "busca_em": "2026-11-19",
                "comparaveis": True}
        mesmo = {"noites": {}, "por_tipo": {"semana": {"2027-02": dict(base)}}}
        vizinho = {"noites": {}, "por_tipo": {"semana": {"2027-01": dict(base)}}}
        o = {ORDEM["id"]: ORDEM}
        r1 = pace.plano(self.cfg, mesmo, cals, HOJE, o, {"passos": []}, dias=89, agora=AGORA, regra_j_ativa=False)
        r2 = pace.plano(self.cfg, vizinho, cals, HOJE, o, {"passos": []}, dias=89, agora=AGORA, regra_j_ativa=False)
        i1 = {(i["quarto"], i["data"]): i for i in r1["itens"]}
        i2 = {(i["quarto"], i["data"]): i for i in r2["itens"]}
        self.assertEqual(i1[("Q7", n)].get("reposicao"), ORDEM["id"])
        self.assertIsNone(i2.get(("Q7", n), {}).get("reposicao"))

    def test_cadencia_dispensada_uma_vez(self):
        n = "2027-02-16"
        antes = {"passos": [{"quarto": "Q7", "noite": n, "direcao": "sobe", "gravado_em": "2026-11-19T11:30:00Z",
                             "chegou_ao_canal": True, "vendidas": 0, "base": "quarto", "total": 7}]}
        sem, ls = self.cenario({}, copy.deepcopy(antes))
        self.assertNotIn(("Q7", n), sem)                                  # passo hoje: a cadência segura
        com, _ = self.cenario({ORDEM["id"]: ORDEM}, copy.deepcopy(antes))
        self.assertIn(("Q7", n), com)                                     # passo antes da ordem: a ordem vale na hora
        depois = {"passos": [dict(antes["passos"][0], gravado_em="2026-11-19T14:40:00Z")]}
        com2, _ = self.cenario({ORDEM["id"]: ORDEM}, depois)
        self.assertNotIn(("Q7", n), com2)                                 # já reposicionada depois da ordem: uma vez só

    def test_item_do_plano_cadencia_com_reposicao(self):
        p = pace.par_pace(self.cfg)
        n = "2027-01-15"
        d = date(2027, 1, 15)
        pn = {"enviado": 1800.0, "piso_duro": 1000.0, "vendidas": 0, "total": 7, "tipo": "fds", "publico": 1116.0,
              "fator": 0.62, "partes": ["abertura R$ 1116 público"]}
        r = row(n, 1161, 0, 7)
        passos = {"passos": [{"quarto": "Q7", "noite": n, "direcao": "sobe", "gravado_em": "2026-11-19T11:30:00Z",
                              "chegou_ao_canal": False, "vendidas": 0, "base": "quarto", "total": 7,
                              "status": "plano", "ordem_id": PD.ORDEM_ID}]}
        it, linha = pace.item_do_plano(self.cfg, p, pn, "Q7", d, 57, r, None, 1161.0, passos, AGORA)
        self.assertIsNone(it)
        it2, _ = pace.item_do_plano(self.cfg, p, pn, "Q7", d, 57, r, None, 1161.0, passos, AGORA, rep=ORDEM, hoje=HOJE)
        self.assertIsNotNone(it2)
        self.assertEqual(it2["min_price"], 1800)
        self.assertTrue(it2["motivo"].startswith("Reposição pela régua comparável"))


if __name__ == "__main__":
    unittest.main()

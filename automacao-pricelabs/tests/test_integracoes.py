import json
import unittest
from datetime import datetime

from pacing import jev
from pacing.pricelabs import ListingProibida, PriceLabs
from pacing.rede import ErroRede, Rede
from tests.simulador import BRT, LISTINGS, Simulador, reserva, resposta_jev_padrao

IDS = list(LISTINGS)


class TestPriceLabs(unittest.TestCase):
    def setUp(self):
        self.sim = Simulador(datetime(2026, 9, 25, 23, 30, tzinfo=BRT))
        self.pl = PriceLabs(Rede(self.sim, frozenset({"POST", "DELETE"}), espaco_s=0), "chave", "beds24", IDS)

    def test_listing_fora_do_config_nao_sai(self):
        for f in (lambda: self.pl.substituicoes("999___1", "2026-09-26", "2026-09-26"),
                  lambda: self.pl.criar_substituicoes("999___1", []),
                  lambda: self.pl.apagar_substituicoes("999___1", ["2026-09-26"]),
                  lambda: self.pl.metricas("999___1"),
                  lambda: self.pl.calendario(["999___1"], "2026-09-25", "2026-09-30")):
            with self.assertRaises(ListingProibida):
                f()
        self.assertEqual(self.sim.chamadas, [])

    def test_leituras(self):
        self.assertEqual(len(self.pl.listings()), 7)
        cal = self.pl.calendario(IDS, "2026-09-25", "2026-11-23")
        self.assertEqual(len(cal), 7)
        m = self.pl.metricas(IDS[0])
        self.assertEqual(m["market_level"]["occupancy"]["7"], 25.0)
        self.assertEqual(self.sim.chamadas[0][3]["X-API-Key"], "chave")

    def test_reservas_paginam_e_filtram(self):
        from datetime import date, timedelta
        for i in range(150):
            self.sim.reservas.append(reserva(IDS[i % 7], date(2026, 9, 26) + timedelta(days=i % 5)))
        self.sim.reservas.append({**reserva(IDS[0], date(2026, 9, 26)), "listing_id": "outro"})
        linhas = self.pl.reservas("2026-09-25", "2026-10-03")
        self.assertEqual(len(linhas), 150)
        self.assertEqual(sum(1 for c in self.sim.chamadas if "reservation_data" in c[1]), 2)

    def test_paginacao_infinita_e_contrato_quebrado(self):
        class Sempre:
            def __call__(self, m, url, cab, corpo, t):
                return 200, {}, json.dumps({"next_page": True, "data": [{"listing_id": IDS[0]}]}).encode()
        pl = PriceLabs(Rede(Sempre(), espaco_s=0), "k", "beds24", IDS)
        with self.assertRaises(ErroRede):
            pl.reservas("2026-09-25", "2026-10-03")
        class Torto:
            def __call__(self, m, url, cab, corpo, t):
                return 200, {}, b'{"listings": "x", "data": 1, "overrides": null}'
        pl = PriceLabs(Rede(Torto(), espaco_s=0), "k", "beds24", IDS)
        for f in (pl.listings, lambda: pl.calendario(IDS[:1], "a", "b"), lambda: pl.metricas(IDS[0]),
                  lambda: pl.reservas("a", "b"), lambda: pl.substituicoes(IDS[0], "a", "b")):
            with self.assertRaises(ErroRede):
                f()

    def test_escritas_formato(self):
        p = {"date": "2026-09-27", "price": "-10", "price_type": "percent", "reason": "auto-jev r"}
        self.pl.criar_substituicoes(IDS[2], [p])
        corpo = self.sim.chamadas[-1][2]
        self.assertEqual(corpo, {"pms": "beds24", "update_children": False, "overrides": [p]})
        self.assertIsInstance(corpo["overrides"][0]["price"], str)
        self.pl.apagar_substituicoes(IDS[2], ["2026-09-27"])
        self.assertEqual(self.sim.chamadas[-1][0], "DELETE")
        self.assertEqual(self.sim.overrides[IDS[2]], {})

    def test_post_de_substituicao_nao_repete(self):
        self.sim.falhas.append(("POST", "/overrides", 503))
        with self.assertRaises(ErroRede):
            self.pl.criar_substituicoes(IDS[2], [{"date": "2026-09-27", "price": "-10", "price_type": "percent", "reason": "auto-jev r"}])
        self.assertEqual(len(self.sim.escritas()), 1)


PERGUNTAS = {
    "d_R3_weekday": {"type": "choice", "instructions": "x", "criteria": {"hold": "a", "discount": "b", "visibility_issue": "c"}},
    "v_R3_weekday": {"type": "noul", "instructions": "y", "criteria": {"true": "t", "false": "f"}},
}


class TestJev(unittest.TestCase):
    def resposta(self):
        return resposta_jev_padrao({"questions": PERGUNTAS})

    def test_valida_resposta_correta(self):
        r = jev.validar(self.resposta(), PERGUNTAS, "jev-1.13", "req")
        self.assertEqual(r.respostas["d_R3_weekday"]["choice"], "discount")
        self.assertEqual(r.tokens_entrada, 900)
        self.assertAlmostEqual(jev.margem(r.respostas["d_R3_weekday"]), 0.85)

    def test_recusa_respostas_fora_do_contrato(self):
        casos = [
            lambda r: r.update(model="gpt-x"),
            lambda r: r.update(model=None),
            lambda r: r["answers"].pop("v_R3_weekday"),
            lambda r: r["answers"].update(extra={"type": "noul", "noul": 0.1}),
            lambda r: r["answers"]["d_R3_weekday"].update(choice="raise_price"),
            lambda r: r["answers"]["d_R3_weekday"].update(choice="hold"),  # não é a mais provável
            lambda r: r["answers"]["d_R3_weekday"]["probabilities"].update(discount=0.5),
            lambda r: r["answers"]["d_R3_weekday"]["probabilities"].pop("hold"),
            lambda r: r["answers"]["d_R3_weekday"]["probabilities"].update(hold=True),
            lambda r: r["answers"]["d_R3_weekday"].update(confidence=1.2),
            lambda r: r["answers"]["d_R3_weekday"].update(confidence=float("nan")),
            lambda r: r["answers"]["d_R3_weekday"].update(type="score"),
            lambda r: r["answers"]["v_R3_weekday"].update(noul=-0.1),
            lambda r: r["answers"]["v_R3_weekday"].update(noul="0.1"),
            lambda r: r.update(answers=[]),
        ]
        for i, mudar in enumerate(casos):
            r = self.resposta()
            mudar(r)
            with self.assertRaises(jev.JevInvalido, msg=f"caso {i}"):
                jev.validar(r, PERGUNTAS, "jev-1.13")
        with self.assertRaises(jev.JevInvalido):
            jev.validar("texto", PERGUNTAS, "jev-1.13")
        with self.assertRaises(jev.JevInvalido):
            jev.validar(self.resposta(), {"s": {"type": "score", "criteria": ["a", "b"]}}, "jev-1.13")

    def test_uso_ausente_vira_zero(self):
        r = self.resposta()
        r["usage"] = {"input_tokens": "muitos"}
        self.assertEqual(jev.validar(r, PERGUNTAS, "jev-1.13").tokens_entrada, 0)

    def test_perguntar_via_openrouter(self):
        sim = Simulador(datetime(2026, 9, 25, 23, 30, tzinfo=BRT))
        rede = Rede(sim, espaco_s=0)
        r = jev.perguntar(rede, "openrouter", "k-or", "typesafe/jev-1.13:free", {"rooms": {}}, PERGUNTAS)
        metodo, url, corpo, cab = sim.chamadas[-1]
        self.assertEqual((metodo, url), ("POST", "https://openrouter.ai/api/v1/systemone"))
        self.assertEqual(cab["Authorization"], "Bearer k-or")
        self.assertEqual(corpo["model"], "typesafe/jev-1.13:free")
        self.assertTrue(r.chamada_id)
        r2 = jev.perguntar(rede, "openrouter", "k-or", "typesafe/jev-1.13:free", {"rooms": {}}, PERGUNTAS)
        self.assertNotEqual(r.chamada_id, r2.chamada_id)
        with self.assertRaises(jev.JevInvalido):
            jev.perguntar(rede, "outro", "k", "m", {}, PERGUNTAS)
        with self.assertRaises(jev.JevInvalido):
            jev.perguntar(rede, "openrouter", "k", "m", {}, {})
        sim.jev_status = 422
        with self.assertRaises(ErroRede) as e:
            jev.perguntar(rede, "openrouter", "k", "m", {}, PERGUNTAS)
        self.assertEqual(e.exception.status, 422)


if __name__ == "__main__":
    unittest.main()

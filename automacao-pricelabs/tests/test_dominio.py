import copy
import json
import unittest
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from pacing import config, metricas, regras
from pacing.estado import estado_vazio
from tests.simulador import BRT, LISTINGS, Simulador, reserva, resposta_jev_padrao

RAIZ = Path(__file__).resolve().parent.parent
CFG = config.carregar(RAIZ / "config.json")
AFRODITE, QUEEN7, BALCONY = "350362___722807", "350362___722805", "350364___722814"
UNID = {x["id"]: x["unidades"] for x in CFG["listings"]}


def contexto(sim, agora=None, cfg=None, reservas="sim", estado=None, substituicoes=None, mercado=None, **kw):
    cfg = cfg or copy.deepcopy(CFG)
    original, agora = sim.agora, agora or sim.agora
    sim.agora = agora
    cal = {lid: metricas.ler_calendario(sim._calendario(lid), UNID[lid]) for lid in LISTINGS}
    api = {lid: sim._listing(lid) for lid in LISTINGS}
    sim.agora = original
    ctx = regras.Contexto(cfg, agora, cal, api, sim.reservas if reservas == "sim" else reservas,
                          mercado if mercado is not None else {lid: sim.mercado for lid in LISTINGS},
                          estado or estado_vazio(), **kw)
    ctx.substituicoes = substituicoes if substituicoes is not None else {lid: {} for lid in LISTINGS}
    return ctx


class TestMetricasReais(unittest.TestCase):
    def test_ocupacao_igual_ao_relatorio_v4(self):
        dados = json.loads((RAIZ / "tests/fixtures/calendario-2026-09-25.json").read_text(encoding="utf-8"))
        esperado = {"350362___722805": 43, "350362___722806": 43, "350362___722807": 71, "350362___722808": 50,
                    "350364___722813": 61, "350364___722814": 0, "350364___722815": 36}
        for ent in dados:
            lst = metricas.ler_calendario(ent, UNID[ent["id"]])
            self.assertEqual(round(metricas.ocupacao(lst, date(2026, 9, 25), 0, 6)[2]), esperado[ent["id"]], ent["id"])

    def test_leitura_defensiva(self):
        lst = metricas.ler_calendario({"id": "x", "error_status": "LISTING_TOGGLE_OFF"}, 1)
        self.assertEqual(lst.erro, "LISTING_TOGGLE_OFF")
        lst = metricas.ler_calendario({"id": "x", "data": "nada"}, 1)
        self.assertEqual(lst.erro, "SEM_DADOS")
        ent = {"id": "x", "last_refreshed_at": "2026-09-25T09:00:00Z", "data": [
            {"date": "2026-09-25", "price": 100, "user_price": -1, "booking_status": ""},     # bloqueado
            {"date": "2026-09-26", "price": "abc", "user_price": 100, "booking_status": "Booked"},
            {"date": "ruim"}, "lixo"]}
        lst = metricas.ler_calendario(ent, 1)
        self.assertTrue(lst.dias[date(2026, 9, 25)].bloqueado)
        self.assertIsNone(lst.dias[date(2026, 9, 26)].preco)
        self.assertEqual(metricas.ocupacao(lst, date(2026, 9, 25), 0, 1)[:2], (1, 1))
        multi = metricas.ler_calendario({"id": "y", "data": [{"date": "2026-09-25", "booking_status": "Booked"}]}, 2)
        self.assertEqual(multi.dias, {})
        self.assertIsNone(metricas.ocupacao(multi, date(2026, 9, 25), 0, 6)[2])
        self.assertIsNone(metricas.mediana_preco(multi))

    def test_reservas_e_receita(self):
        agora = datetime(2026, 9, 25, 23, 30, tzinfo=BRT)
        rs = [reserva(AFRODITE, date(2026, 9, 27), 2, reservada_em=agora - timedelta(hours=10)),
              reserva(AFRODITE, date(2026, 9, 26), reservada_em=agora - timedelta(hours=60)),
              reserva(AFRODITE, date(2026, 9, 26), status="cancelled", cancelada_em=agora - timedelta(hours=5)),
              reserva(AFRODITE, date(2026, 10, 5), status="cancelled", cancelada_em=agora - timedelta(hours=5)),
              {**reserva(AFRODITE, date(2026, 9, 29)), "no_of_days": None, "check_out": "2026-10-01"},
              reserva(QUEEN7, date(2026, 9, 27), reservada_em=agora)]
        r = metricas.resumir_reservas(rs, AFRODITE, agora)
        self.assertEqual((r.novas_48h, r.cancel_proximos_3d_48h), (1, 1))
        self.assertTrue(metricas.vendeu_depois(r, date(2026, 9, 28), agora - timedelta(hours=20)))
        self.assertFalse(metricas.vendeu_depois(r, date(2026, 9, 28), agora))
        self.assertEqual(metricas.noites(rs[4]), [date(2026, 9, 29), date(2026, 9, 30)])
        self.assertEqual(metricas.noites({"check_in": "x"}), [])
        self.assertIsNone(metricas.mercado_7d({}))
        self.assertIsNone(metricas.amostra_receita({"a": {}}))
        self.assertEqual(metricas.amostra_receita({"a": {"listing_level": {"revpar": {"-30": 10}, "occupancy": {"-30": 50}}}}),
                         {"revpar": 10.0, "ocupacao": 50.0})

    def test_trava_de_receita(self):
        h = date(2026, 10, 20)
        amostras = [{"dia": (h - timedelta(days=14)).isoformat(), "revpar": 600, "ocupacao": 40},
                    {"dia": (h - timedelta(days=8)).isoformat(), "revpar": 550, "ocupacao": 45},
                    {"dia": h.isoformat(), "revpar": 500, "ocupacao": 50}]
        self.assertTrue(metricas.trava_receita(amostras, h))
        amostras[2]["ocupacao"] = 44  # ocupação caiu: é sazonal, não diluição
        self.assertFalse(metricas.trava_receita(amostras, h))
        self.assertFalse(metricas.trava_receita(amostras[1:], h))


class TestCandidatos(unittest.TestCase):
    def setUp(self):
        self.sim = Simulador(datetime(2026, 9, 25, 23, 30, tzinfo=BRT))

    def test_so_afrodite_real_e_balcony_nunca(self):
        blocos, _ = regras.candidatos(contexto(self.sim), incluir_sombra=False)
        self.assertEqual({b.listing["id"] for b in blocos}, {AFRODITE})
        self.assertEqual({b.tipo: [d.isoformat() for d in b.datas] for b in blocos},
                         {"fri_sat": ["2026-09-26"], "weekday": ["2026-09-27", "2026-09-28", "2026-09-29", "2026-09-30", "2026-10-01"]})
        blocos, _ = regras.candidatos(contexto(self.sim), incluir_sombra=True)
        ids = {b.listing["id"] for b in blocos}
        self.assertNotIn(BALCONY, ids)
        self.assertIn(QUEEN7, ids)
        self.assertTrue(all(b.sombra for b in blocos if b.listing["id"] != AFRODITE))

    def test_datas_de_feriado_vespera_e_substituicao_humana_ficam_fora(self):
        agora = datetime(2026, 10, 8, 23, 30, tzinfo=BRT)
        sim = Simulador(agora)
        humana = {AFRODITE: {"2026-10-13": {"date": "2026-10-13", "min_stay": 2, "reason": "manual"}}}
        blocos, _ = regras.candidatos(contexto(sim, substituicoes={**{l: {} for l in LISTINGS}, **humana}), False)
        datas = {d.isoformat() for b in blocos for d in b.datas}
        self.assertEqual(datas, {"2026-10-09", "2026-10-10", "2026-10-14"})

    def test_portoes(self):
        def bloqueio(ctx, sombra=False):
            blocos, bloq = regras.candidatos(ctx, sombra)
            self.assertEqual([b for b in blocos if b.listing["id"] == AFRODITE], [])
            return " | ".join(x["regra"] for x in bloq if x["alvo"].startswith("Moinhos Afrodite"))

        self.assertIn("janela", bloqueio(contexto(self.sim, agora=datetime(2026, 9, 25, 14, 30, tzinfo=BRT))))
        self.assertIn("visibilidade", bloqueio(contexto(self.sim, mercado={AFRODITE: 45.0})))
        self.assertIn("mercado desconhecida", bloqueio(contexto(self.sim, mercado={AFRODITE: None})))
        self.assertIn("reservas ilegíveis", bloqueio(contexto(self.sim, reservas=None)))
        self.assertIn("trava", bloqueio(contexto(self.sim, trava_receita=True)))
        self.assertIn("substituições", bloqueio(contexto(self.sim, substituicoes={})))
        sim = Simulador(self.sim.agora)
        sim.reservas.append(reserva(AFRODITE, date(2026, 9, 26), reservada_em=sim.agora - timedelta(hours=3)))
        self.assertIn("reserva nova", bloqueio(contexto(sim)))
        sim.reservas.append(reserva(AFRODITE, date(2026, 9, 26), status="cancelled", cancelada_em=sim.agora - timedelta(hours=2)))
        blocos, _ = regras.candidatos(contexto(sim), False)
        self.assertTrue(blocos)
        self.assertEqual(blocos[0].bandas["recent_cancellation_next_3d"], "yes")
        sim = Simulador(self.sim.agora)
        for i in range(6):
            sim.vender(AFRODITE, date(2026, 9, 25) + timedelta(days=i))
        self.assertIn("meta", bloqueio(contexto(sim)))
        sim = Simulador(self.sim.agora)
        sim.push[AFRODITE] = False
        self.assertIn("sincronização", bloqueio(contexto(sim)))
        ctx = contexto(Simulador(self.sim.agora))
        ctx.calendarios[AFRODITE].atualizado_em = ctx.agora - timedelta(hours=40)
        self.assertIn("desatualizado", bloqueio(ctx))
        ctx = contexto(Simulador(self.sim.agora))
        ctx.listings_api[AFRODITE]["min"] = None
        self.assertIn("mínimo", bloqueio(ctx))
        sim = Simulador(self.sim.agora)
        for d in sim.cal[AFRODITE]:
            sim.cal[AFRODITE][d]["preco"] = 1600.0  # 1600 x 0,9 = 1440 < mínimo 1500
        self.assertIn("nenhuma data", bloqueio(contexto(sim)))
        est = estado_vazio()
        for i in range(1, 7):
            est["descontadas"][f"{AFRODITE}|{(date(2026, 9, 25) + timedelta(days=i)).isoformat()}"] = "2026-09-24T05:30:00-03:00"
        self.assertIn("nenhuma data", bloqueio(contexto(Simulador(self.sim.agora), estado=est)))

    def test_bandas_e_pedido_sem_identificadores(self):
        blocos, _ = regras.candidatos(contexto(self.sim), True)
        pedido = regras.montar_pedido(blocos, "typesafe/jev-1.13:free")
        texto = json.dumps(pedido, ensure_ascii=False)
        for proibido in ("350362", "350364", "2026-", "Afrodite", "Moinhos", "Villa", "Hidden"):
            self.assertNotIn(proibido, texto)
        b = next(x for x in blocos if x.listing["id"] == AFRODITE and x.tipo == "weekday")
        self.assertEqual(b.bandas, {"block": "weekday", "room_size": "single_unit", "occupancy_0_6d": "far_below_target",
                                    "empty_dates_in_block": "four_or_more", "new_bookings_last_48h": "none",
                                    "recent_cancellation_next_3d": "no", "price_vs_room_median": "typical",
                                    "headroom_above_floor": "tight", "market_next_7d": "weak"})
        self.assertEqual(len(pedido["questions"]), 2 * len(blocos))
        self.assertEqual(regras.impressao(pedido), regras.impressao(json.loads(texto)))
        try:
            from jsonschema import Draft202012Validator
        except ImportError:
            return
        Draft202012Validator(json.loads((RAIZ / "schemas/jev-pedido.schema.json").read_text(encoding="utf-8"))).validate(pedido)


def decisoes_com(sim, **resp):
    blocos, _ = regras.candidatos(contexto(sim), False)
    pedido = regras.montar_pedido(blocos, "m")
    return regras.decidir(blocos, resposta_jev_padrao(pedido, **resp)["answers"], CFG["jev"])


class TestDecisao(unittest.TestCase):
    def setUp(self):
        self.sim = Simulador(datetime(2026, 9, 25, 23, 30, tzinfo=BRT))

    def test_limiares(self):
        self.assertTrue(all(d.agir for d in decisoes_com(self.sim)))
        for kw, trecho in (({"confianca": 0.79}, "confiança"), ({"prob": 0.69}, "probabilidade"),
                           ({"veto": 0.30}, "veto"), ({"escolha": "hold"}, "hold"),
                           ({"escolha": "visibility_issue"}, "visibility")):
            for d in decisoes_com(self.sim, **kw):
                self.assertFalse(d.agir, kw)
                self.assertIn(trecho, d.motivo)

    def test_margem_minima(self):
        blocos, _ = regras.candidatos(contexto(self.sim), False)
        pedido = regras.montar_pedido(blocos, "m")
        resp = resposta_jev_padrao(pedido)["answers"]
        for v in resp.values():
            if v["type"] == "choice":
                v.update(probabilities={"discount": 0.55, "hold": 0.45, "visibility_issue": 0.0}, confidence=0.85)
        frouxo = dict(CFG["jev"], probabilidade_min=0.5)  # só para isolar a margem; o config real não aceita
        decs = regras.decidir(blocos, resp, frouxo)
        self.assertTrue(all(not d.agir and "margem 0.10" in d.motivo for d in decs))

    def test_confirmacao_exige_outra_chamada_real(self):
        est = estado_vazio()
        t0 = datetime(2026, 9, 25, 23, 30, tzinfo=BRT)
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, est, "id-1", t0, True)
        self.assertTrue(all(not d.agir and "confirmação" in d.motivo for d in decs))
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, est, "id-1", t0 + timedelta(hours=6), True)   # mesma chamada reaproveitada
        self.assertFalse(any(d.agir for d in decs))
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, est, "id-2", t0 + timedelta(hours=6), True)
        self.assertTrue(all(d.agir for d in decs))
        self.assertEqual(est["propostas"], {})
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, est, "", t0, True)
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, est, "", t0 + timedelta(hours=1), True)
        self.assertFalse(any(d.agir for d in decs), "id vazio nunca confirma")
        est = estado_vazio()
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, est, "a", t0, True)
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, est, "b", t0 + timedelta(hours=13), True)
        self.assertFalse(any(d.agir for d in decs), "proposta velha não confirma")
        decs = decisoes_com(self.sim, escolha="hold")
        regras.confirmar(decs, est, "c", t0, True)
        self.assertEqual(est["propostas"], {})
        decs = decisoes_com(self.sim)
        regras.confirmar(decs, estado_vazio(), "z", t0, False)
        self.assertTrue(all(d.agir for d in decs))

    def test_plano_respeita_modo_e_tetos(self):
        ctx = contexto(self.sim)
        decs = decisoes_com(self.sim)
        acoes, _ = regras.plano_criacao(decs, ctx, "r1", "observar")
        self.assertEqual({a["tipo"] for a in acoes}, {"faria_criar"})
        acoes, bloq = regras.plano_criacao(decs, ctx, "r1", "ativo")
        self.assertEqual(len([a for a in acoes if a["tipo"] == "criar"]), 3)
        self.assertEqual(len(bloq), 3)
        a = acoes[0]
        self.assertEqual(a["payload"], {"date": a["data"], "price": "-10", "price_type": "percent", "reason": "auto-jev r1"})
        ctx.estado["contadores"] = {"dia": "2026-09-25", "criadas": 6}
        self.assertEqual([x for x in regras.plano_criacao(decs, ctx, "r1", "ativo")[0] if x["tipo"] == "criar"], [])
        ctx.estado["contadores"] = {"dia": "2026-09-24", "criadas": 6}
        ctx.estado["dsos"] = {f"x|2026-09-{d}": {"data": f"2026-09-{d}", "status": "ativa"} for d in range(10, 16)}
        self.assertEqual(len([x for x in regras.plano_criacao(decs, ctx, "r1", "ativo")[0] if x["tipo"] == "criar"]), 3,
                         "datas passadas não ocupam vagas")
        blocos, _ = regras.candidatos(contexto(self.sim), True)
        pedido = regras.montar_pedido(blocos, "m")
        decs = regras.decidir(blocos, resposta_jev_padrao(pedido)["answers"], CFG["jev"])
        acoes, _ = regras.plano_criacao(decs, contexto(self.sim), "r1", "ativo")
        self.assertTrue(all(a["listing"] == AFRODITE for a in acoes if a["tipo"] == "criar"))
        self.assertTrue(any(a["tipo"] == "sombra" for a in acoes))


class TestLimpezaEAlertas(unittest.TestCase):
    def dso(self, data="2026-09-27", criado="2026-09-26T05:30:00-03:00", status="ativa"):
        return {f"{AFRODITE}|{data}": {"listing": AFRODITE, "data": data, "criado_em": criado, "status": status,
                                        "payload": {"date": data, "price": "-10", "price_type": "percent", "reason": "auto-jev r"}}}

    def test_remocao_deterministica(self):
        sim = Simulador(datetime(2026, 9, 26, 23, 30, tzinfo=BRT))
        est = estado_vazio()
        est["dsos"] = self.dso()
        self.assertEqual(regras.plano_limpeza(contexto(sim, estado=est))[0], [])
        sim.reservas.append(reserva(AFRODITE, date(2026, 9, 27), reservada_em=datetime(2026, 9, 26, 12, 0, tzinfo=BRT)))
        acoes, _, _ = regras.plano_limpeza(contexto(sim, estado=est))
        self.assertEqual(acoes[0]["motivo"], "a data vendeu")
        sim = Simulador(datetime(2026, 9, 26, 23, 30, tzinfo=BRT))
        sim.vender(AFRODITE, date(2026, 9, 27))
        self.assertEqual(regras.plano_limpeza(contexto(sim, estado=est))[0][0]["motivo"], "a data vendeu")
        est["dsos"] = self.dso(data="2026-09-30")
        # criada 26/09 05:30, enviada na sincronização de 26/09 06:00: fica nos canais até 28/09 06:00
        sim = Simulador(datetime(2026, 9, 27, 5, 30, tzinfo=BRT))
        self.assertEqual(regras.plano_limpeza(contexto(sim, estado=est))[0], [])
        sim = Simulador(datetime(2026, 9, 27, 8, 30, tzinfo=BRT))
        self.assertIn("48 h", regras.plano_limpeza(contexto(sim, estado=est))[0][0]["motivo"])

    def test_preco_abaixo_do_minimo_aciona_disjuntor(self):
        sim = Simulador(datetime(2026, 9, 26, 8, 30, tzinfo=BRT))
        sim.cal[AFRODITE][date(2026, 9, 27)]["preco"] = 1400.0
        est = estado_vazio()
        est["dsos"] = self.dso()
        acoes, alertas, disj = regras.plano_limpeza(contexto(sim, estado=est))
        self.assertTrue(disj)
        self.assertIn("mínimo", acoes[0]["motivo"])
        sim = Simulador(datetime(2026, 9, 26, 5, 45, tzinfo=BRT))   # antes da sincronização: preço ainda não reflete
        sim.cal[AFRODITE][date(2026, 9, 27)]["preco"] = 1400.0
        self.assertFalse(regras.plano_limpeza(contexto(sim, estado=est))[2])

    def test_divergente_passada_e_pendente(self):
        sim = Simulador(datetime(2026, 9, 26, 23, 30, tzinfo=BRT))
        est = estado_vazio()
        est["dsos"] = {**self.dso(status="divergente"), **self.dso(data="2026-09-20"), **self.dso(data="2026-09-28", status="pendente")}
        acoes, alertas, _ = regras.plano_limpeza(contexto(sim, estado=est))
        self.assertEqual(acoes, [])
        self.assertEqual(len(alertas), 1)

    def test_alertas_gerais(self):
        sim = Simulador(datetime(2026, 9, 25, 23, 30, tzinfo=BRT))
        for i in range(7):
            sim.vender(QUEEN7, date(2026, 9, 25) + timedelta(days=i))
        sim.cal["350364___722815"][date(2026, 9, 26)]["preco"] = 900.0
        cfg = copy.deepcopy(CFG)
        cfg["listings"][1]["unidades"] = 7
        ctx = contexto(sim, cfg=cfg, mercado={lid: 45.0 for lid in LISTINGS}, trava_receita=True)
        ctx.listings_api[AFRODITE]["last_date_pushed"] = "2026-09-20T09:00:00Z"
        ctx.calendarios["350362___722808"].erro = "LISTING_NO_DATA"
        texto = " | ".join(regras.alertas_gerais(ctx))
        for trecho in ("mostra 6 unidades, o config diz 7", "visibilidade", "Conferir se o máximo", "7 a 14 dias",
                       "preço mínimo", "sincronização", "Trava de receita", "LISTING_NO_DATA"):
            self.assertIn(trecho, texto)
        self.assertNotIn("Balcony", texto.split("unidades")[0])


if __name__ == "__main__":
    unittest.main()

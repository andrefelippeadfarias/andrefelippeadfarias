"""Testes do scripts/reveillon_250k.py (dono 16/11/2026, "Pode fazer"; ordem dono-2026-11-16-reveillon-250k).

Usam o quartos.json e a ordem reais e um inventário/regras por data iguais aos lidos em 16/11 (sem dados de hóspede).
Rodar da pasta scripts: python3 -m unittest test_reveillon_250k
"""

from __future__ import annotations

import copy
import unittest
from datetime import date, datetime, timezone

import reveillon_250k as R
from comum import carregar_quartos, maximo_da_noite
from ordens import carregar_ordens
from validar_plano import Validador, em_reais, piso_pct

CFG = carregar_quartos()
# a config real muda com a operação (16/11 16:40 BRT: portão do 01→03 do Recanto aberto, `*_por_quarto` em
# regra_h_beds24): os testes partem da config de antes do portão e das exceções e cada um monta o seu cenário
for _k in ("override_por_data_por_quarto", "estadia_minima_por_data_por_quarto", "override_por_data_por_quarto_motivo"):
    CFG["parametros"]["regra_h_beds24"].pop(_k, None)
# depois de gravado e conferido o máximo de R$ 6.000 (reveillon_250k.py registrar com o item de anúncio)
CFG6 = copy.deepcopy(CFG)
for _q in ("Q7", "Double", "Q2", "VK7", "VK2", "Balcony"):
    CFG6["por_curto"][_q]["max"] = 6000
ORDENS = carregar_ordens()
ORDEM = R.ordem_do_dono(ORDENS)
# 4ª condição do portão (revisão de 16/11, 3ª verificação, R3-03): o dono AINDA NÃO respondeu sobre 01→04 e 01→05. Os
# testes que abrem o portão usam uma CÓPIA da ordem com um ok fictício de teste (não é resposta do dono)
ORDEM_OK = copy.deepcopy(ORDEM)
ORDEM_OK["execucao_skill"]["ok_01_04_05"] = dict(ORDEM["execucao_skill"]["ok_01_04_05"], resposta="aceita",
                                                 texto_dono="(ok fictício de teste)", respondido_em="2026-11-17 10:00 BRT")
ORDENS_OK = dict(ORDENS, **{R.ORDEM_ID: ORDEM_OK})
# ordem com o ok do dono ainda pendente (estado antes da resposta dele; a ordem real tem a resposta de 16/11)
ORDEM_PENDENTE = copy.deepcopy(ORDEM)
ORDEM_PENDENTE["execucao_skill"]["ok_01_04_05"] = dict(ORDEM["execucao_skill"]["ok_01_04_05"], resposta=None,
                                                       texto_dono=None, respondido_em=None)
ORDENS_PENDENTE = dict(ORDENS, **{R.ORDEM_ID: ORDEM_PENDENTE})
HOJE = date(2026, 11, 16)
CORTE = "2026-11-16T14:39:12Z"

# Beds24 de 16/11 (numAvail, minStay, maxStay, override, price1), noites de 29/12 a 03/01
_INV = {
    "Q7": {"2026-12-29": [7, 1, 2, "none", 1912], "2026-12-30": [3, 2, 4, "none", 3191], "2026-12-31": [2, 2, 4, "noCheckInOrCheckOut", 3413], "2027-01-01": [4, 3, 4, "noCheckIn", 2979], "2027-01-02": [6, 3, 4, "noCheckInOrCheckOut", 2979], "2027-01-03": [7, 1, 7, "none", 1232]},
    "Double": {"2026-12-29": [6, 1, 2, "none", 1909], "2026-12-30": [6, 2, 4, "none", 3567], "2026-12-31": [3, 2, 4, "noCheckInOrCheckOut", 3818], "2027-01-01": [4, 3, 4, "noCheckIn", 3567], "2027-01-02": [4, 3, 4, "noCheckInOrCheckOut", 3567], "2027-01-03": [6, 1, 7, "none", 1228]},
    "Afrodite": {"2026-12-29": [1, 1, 2, "none", 2200], "2026-12-30": [1, 2, 4, "none", 4120], "2026-12-31": [1, 2, 4, "noCheckInOrCheckOut", 4400], "2027-01-01": [1, 3, 4, "noCheckIn", 4120], "2027-01-02": [1, 3, 4, "noCheckInOrCheckOut", 4120], "2027-01-03": [0, 1, 7, "none", 1943]},
    "Q2": {"2026-12-29": [2, 1, 2, "none", 1924], "2026-12-30": [2, 2, 4, "none", 3482], "2026-12-31": [1, 2, 4, "noCheckInOrCheckOut", 3721], "2027-01-01": [2, 3, 4, "noCheckIn", 3434], "2027-01-02": [2, 3, 4, "noCheckInOrCheckOut", 3434], "2027-01-03": [1, 1, 7, "none", 1240]},
    "VK7": {"2026-12-29": [5, 1, 2, "none", 3052], "2026-12-30": [4, 2, 4, "none", 4988], "2026-12-31": [3, 2, 4, "noCheckInOrCheckOut", 4988], "2027-01-01": [5, 3, 4, "noCheckIn", 4988], "2027-01-02": [6, 3, 4, "noCheckInOrCheckOut", 4988], "2027-01-03": [6, 1, 7, "none", 1006]},
    "Balcony": {"2026-12-29": [6, 1, 2, "none", 2299], "2026-12-30": [5, 2, 4, "none", 4483], "2026-12-31": [1, 2, 4, "noCheckInOrCheckOut", 4483], "2027-01-01": [5, 3, 4, "noCheckIn", 4483], "2027-01-02": [6, 3, 4, "noCheckInOrCheckOut", 4483], "2027-01-03": [6, 1, 7, "none", 902]},
    "VK2": {"2026-12-29": [2, 1, 2, "none", 2771], "2026-12-30": [1, 2, 4, "none", 4991], "2026-12-31": [1, 2, 4, "noCheckInOrCheckOut", 4991], "2027-01-01": [2, 3, 4, "noCheckIn", 4991], "2027-01-02": [2, 3, 4, "noCheckInOrCheckOut", 4991], "2027-01-03": [2, 1, 7, "none", 1003]},
}
# regras por data do PriceLabs de 16/11 (min = max em percent_base; estadia; máscara da Afrodite)
_REG = {
    "Q7": {"2026-12-30": (172, 2), "2026-12-31": (191, 2), "2027-01-01": (154, 3), "2027-01-02": (154, 3)},
    "Double": {"2026-12-30": (141, 2), "2026-12-31": (158, 2), "2027-01-01": (141, 3), "2027-01-02": (141, 3)},
    "Afrodite": {"2026-12-30": (106, 2), "2026-12-31": (120, 2), "2027-01-01": (106, 3), "2027-01-02": (106, 3)},
    "Q2": {"2026-12-30": (119, 2), "2026-12-31": (134, 2), "2027-01-01": (116, 3), "2027-01-02": (116, 3)},
    "VK7": {n: (237, 2 if n < "2027" else 3) for n in R.NOITES},
    "Balcony": {n: (253, 2 if n < "2027" else 3) for n in R.NOITES},
    "VK2": {n: (293, 2 if n < "2027" else 3) for n in R.NOITES},
}
_MASCARAS = {"2026-12-31": ("1110111", "1110111"), "2027-01-01": ("1111011", "1111111"),
             "2027-01-02": ("1111101", "1111101")}


def inv_hoje() -> dict:
    out = {q: {n: {"numAvail": v[0], "minStay": v[1], "maxStay": v[2], "override": v[3], "price1": v[4]}
               for n, v in ns.items()} for q, ns in _INV.items()}
    out["_lido_em"] = "2026-11-16T14:36:55Z"
    return out


def regras_hoje() -> dict:
    out = {}
    for q, ns in _REG.items():
        out[q] = {}
        for n, (p, ms) in ns.items():
            r = {"date": n, "min_price": p, "min_price_type": "percent_base", "max_price": p,
                 "max_price_type": "percent_base", "min_stay": ms}
            if q == "Afrodite" and n in _MASCARAS:
                r.update(check_in_check_out_enabled="1", check_in=_MASCARAS[n][0], check_out=_MASCARAS[n][1])
            out[q][n] = r
    return out


def estado_hoje() -> dict:
    return R.estado_inicial(CFG, ORDEM, inv_hoje(), CORTE)


def reserva(q: str, chega: str, sai: str, quando: str = "2026-11-20T12:00:00Z", status: str = "confirmed",
            qty: int = 1, cancelada: str | None = None, rid: int | None = None) -> dict:
    room = int(CFG["por_curto"][q]["id"].split("___")[1])
    r = {"roomId": room, "arrival": chega, "departure": sai, "status": status, "roomQty": qty,
         "bookingTime": quando, "cancelTime": cancelada, "channel": "booking"}
    if rid is not None:
        r["id"] = rid
    return r


def por(res: dict) -> dict:
    return {(i["quarto"], i["data"]): i for i in res["itens"] if i["tipo"] == "data"}


def valor_final(res: dict, q: str, n: str) -> float:
    """O preço da suíte-noite no plano: o do item, senão o vigente (linha do diagnóstico)."""
    for x in res["linhas"]:
        if x["quarto"] == q and x["noite"] == n:
            return float(x["novo"])
    raise KeyError((q, n))


def validar(itens: list[dict], hoje: date = HOJE, cfg: dict | None = None, estado: dict | None = None,
            ordens: dict | None = None, precos: dict | None = None) -> Validador:
    """O validador com o estado da ordem do teste (sem ele, o estado de 16/11: a promoção da Villa ativa)."""
    v = Validador(cfg or CFG, hoje, dict(precos or {}), ordens=ordens or ORDENS,
                  estados={R.ORDEM_ID: estado if estado is not None else estado_hoje()})
    v.validar(copy.deepcopy(itens))
    return v


def resumo_ok(lote: list[dict]) -> dict:
    """O JSON do `beds24.py editar-lote --aplicar` sem erro, com o lote inteiro gravado e conferido."""
    return {"itens": len(lote), "aplicar": True, "pulados": [], "a_gravar": len(lote), "n_blocos": 1, "resposta_ok": True,
            "blocos": [], "conferidos": len(lote), "erros": []}


def gravado_de(itens: list[dict]) -> dict:
    """A releitura das regras do PriceLabs ({curto: {data: regra}}) depois de gravar a estadia e a máscara dos itens."""
    out: dict = {}
    for it in itens:
        r = out.setdefault(it["quarto"], {}).setdefault(it["data"], {"date": it["data"]})
        for k in ("min_stay", "check_in", "check_out"):
            if it.get(k) is not None:
                r[k] = str(it[k])
        if it.get("check_in") is not None:
            r["check_in_check_out_enabled"] = "1"
    return out


def prova_orfa(q: str, k: int, **mudar) -> dict:
    """orfa_prova do inventário de 16/11 para a exceção k (1: 30/12 livre e 31/12 esgotada; 2: 02/01 livre e 01/01)."""
    p = {"fonte": "beds24", "quarto": q, "unidades": CFG["por_curto"][q]["unidades"], "noite_numAvail": 1,
         "lido_em": "2026-11-16T14:36:55Z"}
    if k == 1:
        p.update(noite="2026-12-30", vizinha_noite="2026-12-31", seguinte_numAvail=0)
    else:
        p.update(noite="2027-01-02", vizinha_noite="2027-01-01", anterior_numAvail=0)
    p.update(mudar)
    return p


class TestAlvosDeHoje(unittest.TestCase):
    def setUp(self):
        self.res = R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE, [], None,
                                  {q: {"max": 5000} for q in ("Q7", "Double", "Q2", "VK7", "VK2", "Balcony")})
        self.it = por(self.res)

    def test_reveillon_recanto_no_pedido(self):
        # pedido de 09/11 em percentual inteiro: Q7 4.974 → +324, Q2 4.977 → +213, Double 4.988 → +237, Afrodite +235
        for q, p in (("Q7", 324), ("Q2", 213), ("Double", 237), ("Afrodite", 235)):
            for n in ("2026-12-30", "2026-12-31"):
                it = self.it[(q, n)]
                self.assertEqual(piso_pct(CFG["por_curto"][q], it["min_price"]), p, (q, n))
                self.assertEqual(it["min_price"], it["max_price"])
                self.assertEqual((it["excecao"], it["ordem_id"]), ("dono", R.ORDEM_ID))
                self.assertLessEqual(it["min_price"], R.pedido(ORDEM, q, n) + 0.01)

    def test_fim_de_semana_recanto_no_alvo_publico(self):
        alvo = ORDEM["alvos"]["fds_publico"]
        for q, p in (("Q7", 273), ("Q2", 180), ("Double", 207), ("Afrodite", 191)):
            for n in ("2027-01-01", "2027-01-02"):
                it = self.it[(q, n)]
                pc = piso_pct(CFG["por_curto"][q], it["min_price"])
                self.assertEqual(pc, p, (q, n))
                enviado = em_reais(CFG["por_curto"][q], pc)   # round(base × (1 + pct/100))
                self.assertLessEqual(abs(enviado * 0.5832 / alvo[q] - 1), 0.01, (q, n, enviado))
                self.assertLessEqual(it["min_price"], R.pedido(ORDEM, q, n))

    def test_villa_sem_mudanca_e_item_so_com_preco(self):
        self.assertFalse([k for k in self.it if k[0] in ("VK7", "VK2", "Balcony")])
        # a estadia e a máscara ficam na regra (a API mescla): o item leva só piso e teto, com a estadia lida
        for k, it in self.it.items():
            self.assertNotIn("min_stay", it, k)
            self.assertNotIn("check_in", it, k)
            self.assertNotIn("check_out", it, k)
        self.assertEqual(self.it[("Q7", "2027-01-01")]["estadia_vigente"], 3)
        self.assertEqual(self.it[("Q7", "2026-12-30")]["estadia_vigente"], 2)

    def test_plano_de_hoje_valida_sem_erro_nem_aviso_e_sem_anuncio(self):
        self.assertEqual(len(self.it), 16)
        # hoje nada passa de R$ 5.000 e o máximo só sobe a partir de 23/11: nenhum item de anúncio
        self.assertFalse([i for i in self.res["itens"] if i["tipo"] == "anuncio"])
        v = validar(self.res["itens"])
        self.assertEqual(v.erros, [])
        self.assertEqual(v.avisos, [])

    def test_payloads_no_formato_das_ferramentas(self):
        pl = R.payloads(CFG, {"hoje": "2026-11-16", "itens": self.res["itens"]}, HOJE, ORDENS)
        self.assertEqual(pl["anuncio"], {})
        q7 = pl["datas"]["Q7"]
        self.assertEqual((q7["listing_id"], q7["pms"]), ("350362___722805", "beds24"))
        o = {x["date"]: x for x in q7["overrides"]}
        self.assertEqual((o["2026-12-30"]["min_price"], o["2026-12-30"]["max_price"], o["2026-12-30"]["min_price_type"]),
                         ("324", "324", "percent_base"))
        self.assertNotIn("min_stay", o["2027-01-01"])
        af = {x["date"]: x for x in pl["datas"]["Afrodite"]["overrides"]}
        self.assertNotIn("check_in", af["2027-01-01"])
        self.assertTrue(all(len(x["reason"]) <= 250 for c in pl["datas"].values() for x in c["overrides"]))

    def test_noite_sem_regra_leva_a_estadia_padrao(self):
        regras = regras_hoje()
        regras["Q7"].pop("2027-01-01")
        regras["Q7"].pop("2026-12-30")
        it = por(R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras, HOJE, []))
        self.assertEqual(it[("Q7", "2027-01-01")]["min_stay"], 3)
        self.assertEqual(it[("Q7", "2026-12-30")]["min_stay"], 2)

    def test_afrodite_sem_regra_leva_a_mascara_do_pacote(self):
        # revisão de 16/11: sem a máscara, a regra criada ficava na do anúncio (1111101) e abria a sexta 01/01 e a 31/12
        regras = regras_hoje()
        for n in ("2026-12-31", "2027-01-01", "2027-01-02"):
            regras["Afrodite"].pop(n)
        res = R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras, HOJE, [])
        it = por(res)
        self.assertEqual((it[("Afrodite", "2026-12-31")]["check_in"], it[("Afrodite", "2026-12-31")]["check_out"]),
                         ("1110111", "1110111"))
        self.assertEqual((it[("Afrodite", "2027-01-01")]["check_in"], it[("Afrodite", "2027-01-01")]["check_out"]),
                         ("1111011", "1111111"))
        self.assertEqual((it[("Afrodite", "2027-01-02")]["check_in"], it[("Afrodite", "2027-01-02")]["check_out"]),
                         ("1111101", "1111101"))
        self.assertNotIn("check_in", it[("Afrodite", "2026-12-30")])
        self.assertEqual(validar(res["itens"]).erros, [])
        # com o portão aberto na config, a sexta vai aberta
        cfg = copy.deepcopy(CFG)
        cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"] = {"2027-01-01": {"Afrodite": "none"}}
        self.assertEqual(R.mascara_padrao_afrodite(cfg, "2027-01-01"), ("1111111", "1111111"))

    def test_conferencia_da_gravacao_com_a_estadia_e_a_mascara_da_regra(self):
        pl = R.payloads(CFG, {"hoje": "2026-11-16", "itens": self.res["itens"]}, HOJE, ORDENS,
                        estados={R.ORDEM_ID: estado_hoje()})
        af = {x["date"]: x for x in pl["conferencia"]["Afrodite"]}
        self.assertEqual((af["2027-01-01"]["min_stay"], af["2027-01-01"]["check_in"], af["2027-01-01"]["check_out"]),
                         ("3", "1111011", "1111111"))
        self.assertEqual(af["2026-12-30"]["min_price"], "235")
        self.assertNotIn("check_in", af["2026-12-30"])
        self.assertEqual({x["date"]: x["min_stay"] for x in pl["conferencia"]["Q7"]},
                         {"2026-12-30": "2", "2026-12-31": "2", "2027-01-01": "3", "2027-01-02": "3"})


class TestPacotes(unittest.TestCase):
    def test_contagem_30_01_01_03_30_03(self):
        corte = datetime(2026, 11, 16, 14, 39, 12, tzinfo=timezone.utc)
        rs = [reserva("Q7", "2026-12-30", "2027-01-01"),                         # Réveillon +1
              reserva("Double", "2027-01-01", "2027-01-03"),                     # fim de semana +1
              reserva("Q2", "2026-12-30", "2027-01-03"),                         # os dois +1
              reserva("VK7", "2026-12-30", "2027-01-03", qty=2),                 # Villa, os dois +2
              reserva("Q7", "2026-12-30", "2027-01-01", quando="2026-11-10T10:00:00Z"),       # linha de base
              reserva("Double", "2026-12-30", "2027-01-01", status="cancelled"),               # nova cancelada: 0
              reserva("Balcony", "2026-12-30", "2027-01-01", quando="2026-09-09T16:05:00Z", status="cancelled",
                      cancelada="2026-11-25T10:00:00Z"),                                          # antiga cancelada: −1
              reserva("Balcony", "2026-12-29", "2026-12-31"),                    # 29→31 (exceção 1): não é pacote
              reserva("Q7", "2027-01-02", "2027-01-04")]                         # 02→04 (exceção 2): não é pacote
        c = R.contar_pacotes(CFG, rs, corte)
        self.assertEqual(c["Recanto"], {"reveillon": 2, "fds": 2})
        self.assertEqual(c["Villa"], {"reveillon": 1, "fds": 2})
        self.assertEqual(c["total"], {"reveillon": 3, "fds": 4})

    def test_por_diferenca_trata_extensao_encurtamento_e_troca(self):
        corte = datetime(2026, 11, 16, 14, 39, 12, tzinfo=timezone.utc)
        base_rs = [reserva("Q7", "2026-12-30", "2027-01-01", "2026-11-01T10:00:00Z", rid=1),
                   reserva("Double", "2026-12-30", "2027-01-03", "2026-11-01T10:00:00Z", rid=2),
                   reserva("VK7", "2026-12-30", "2027-01-01", "2026-11-01T10:00:00Z", rid=3),
                   reserva("Q2", "2026-12-28", "2026-12-30", "2026-11-01T10:00:00Z", rid=4),
                   reserva("VK2", "2026-12-30", "2027-01-01", "2026-11-01T10:00:00Z", rid=5)]
        base = R.cobertura_reservas(CFG, base_rs)
        self.assertEqual(len(base), 4)                       # 28→30 não cobre bloco
        self.assertTrue(all(len(k) == 16 and k not in ("1", "2", "3", "5") for k in base))   # hash, sem o id
        # mesma leitura: nada
        c0 = R.contar_pacotes(CFG, base_rs, corte, base)
        self.assertEqual(c0["total"], {"reveillon": 0, "fds": 0})
        agora = [reserva("Q7", "2026-12-30", "2027-01-03", "2026-11-01T10:00:00Z", rid=1),     # estendida: +1 fds
                 reserva("Double", "2026-12-30", "2027-01-01", "2026-11-01T10:00:00Z", rid=2),  # encurtada: −1 fds
                 reserva("Balcony", "2026-12-30", "2027-01-01", "2026-11-01T10:00:00Z", rid=3),  # trocou na Villa: 0
                 reserva("Q2", "2026-12-28", "2027-01-01", "2026-11-01T10:00:00Z", rid=4),       # estendida: +1 Rév
                 reserva("VK2", "2026-12-30", "2027-01-01", "2026-11-01T10:00:00Z", status="cancelled", rid=5),  # −1
                 reserva("VK7", "2027-01-01", "2027-01-03", rid=6)]                               # nova: +1 fds
        c = R.contar_pacotes(CFG, agora, corte, base)
        self.assertEqual(c["Recanto"], {"reveillon": 1, "fds": 0})
        self.assertEqual(c["Villa"], {"reveillon": -1, "fds": 1})
        # reserva do Recanto que foi para a Villa: −1 numa, +1 na outra
        troca = [dict(r) for r in base_rs]
        troca[0] = reserva("VK7", "2026-12-30", "2027-01-01", "2026-11-01T10:00:00Z", rid=1)
        c2 = R.contar_pacotes(CFG, troca, corte, base)
        self.assertEqual((c2["Recanto"]["reveillon"], c2["Villa"]["reveillon"]), (-1, 1))
        # leitura incompleta (as reservas da base sumiram): recusa
        with self.assertRaises(ValueError):
            R.contar_pacotes(CFG, [], corte, dict(base, **{f"x{i}": {"quarto": "Q7", "reveillon": 1, "fds": 0}
                                                           for i in range(10)}))

    def test_sem_leitura_de_reservas_nao_conta(self):
        est = estado_hoje()
        est["linha_base"]["cobertura_reservas"] = {"abc": {"quarto": "Q7", "reveillon": 1, "fds": 0}}
        ctx = R.contexto(CFG, ORDEM, est, inv_hoje(), None, HOJE)
        self.assertEqual(ctx["pacotes"]["total"], {"reveillon": 0, "fds": 0})

    def test_pacotes_livres_da_linha_de_base(self):
        self.assertEqual(R.pacotes_livres(CFG, inv_hoje()),
                         {"Recanto": {"reveillon": 7, "fds": 11}, "Villa": {"reveillon": 5, "fds": 12}})


class TestEscada(unittest.TestCase):
    def plano(self, rs, hoje=HOJE, estado=None, inv=None, receita=None, cfg=None):
        return R.montar_plano(cfg or CFG6, ORDEM, estado or estado_hoje(), inv or inv_hoje(), regras_hoje(), hoje, rs,
                              receita)

    def test_reveillon_mais_3_por_pacote_multiplicativo(self):
        um = self.plano([reserva("Afrodite", "2026-12-30", "2027-01-01")])
        dois = self.plano([reserva("Afrodite", "2026-12-30", "2027-01-01"), reserva("Q7", "2026-12-30", "2027-01-03")])
        d1 = valor_final(um, "Double", "2026-12-30")
        d2 = valor_final(dois, "Double", "2026-12-30")
        tol = 0.01 * 1480 + 1
        self.assertAlmostEqual(d1, 4988 * 1.03, delta=tol)
        self.assertAlmostEqual(d2, 4988 * 1.03 ** 2, delta=tol)
        # a Villa não sobe com pacote do Recanto
        self.assertEqual(valor_final(dois, "VK7", "2026-12-30"), 4987.6)
        # o 30→03 também conta no fim de semana do Recanto: +2%
        self.assertAlmostEqual(valor_final(dois, "Double", "2027-01-01"), 4543.9 * 1.02, delta=tol)
        self.assertAlmostEqual(valor_final(um, "Double", "2027-01-01"), 4543.6, delta=0.01)

    def test_teto_reveillon_e_fim_de_semana(self):
        rs = [reserva("Q7", "2026-12-30", "2027-01-03", quando=f"2026-11-{17 + i:02d}T10:00:00Z") for i in range(12)]
        res = self.plano(rs)
        for q in ("Q7", "Q2", "Double"):
            for n in ("2026-12-30", "2026-12-31"):
                self.assertLessEqual(valor_final(res, q, n), 6000)
                self.assertGreater(valor_final(res, q, n), 5900)
            for n in ("2027-01-01", "2027-01-02"):
                self.assertLessEqual(valor_final(res, q, n), 2871 / 0.5832 + 0.01)   # concorrente mais caro
        self.assertLessEqual(valor_final(res, "Afrodite", "2026-12-30"), 8000)
        self.assertLessEqual(valor_final(res, "Afrodite", "2027-01-01"), 6600)
        self.assertLessEqual(valor_final(res, "Afrodite", "2027-01-02"), 6520)
        self.assertEqual(validar(res["itens"], cfg=CFG6).erros, [])

    def test_villa_reveillon_sobe_e_balcony_pela_regra_g(self):
        res = self.plano([reserva("VK7", "2026-12-30", "2027-01-01")])
        vk7 = valor_final(res, "VK7", "2026-12-30")
        self.assertAlmostEqual(vk7, 4988 * 1.03, delta=16)
        b = valor_final(res, "Balcony", "2026-12-30")
        ref = min(vk7, valor_final(res, "VK2", "2026-12-30"))
        self.assertLessEqual(b, 0.9 * ref + 0.01)
        self.assertGreaterEqual(b, 0.855 * ref)
        self.assertEqual(validar(res["itens"], cfg=CFG6).erros, [])

    def test_pausa_abaixo_do_alarme(self):
        est = estado_hoje()
        est["escada"]["Recanto"]["reveillon"] = 1
        rs = [reserva("Q7", "2026-12-30", "2027-01-01", quando="2026-11-20T10:00:00Z"),
              reserva("Double", "2026-12-30", "2027-01-01", quando="2026-11-21T10:00:00Z")]
        # 30/11: linha de alarme 46 quarto-noites; o inventário de 16/11 tem 40 vendidas (abaixo): a escada fica em 1
        res = self.plano(rs, hoje=date(2026, 11, 30), estado=est)
        self.assertTrue(res["contexto"]["pausa"])
        self.assertEqual(res["contexto"]["escada"]["Recanto"]["reveillon"], 1)
        # acima do alarme (receita e quarto-noites acima da linha de 30/11): a escada anda com os 2 pacotes. Revisão de
        # 16/11 (R3-08): sem a receita, as reservas do teste (sem valor) dariam R$ 0 e a escada pararia
        inv = inv_hoje()
        for q in ("Q7", "Double", "VK7", "Balcony"):
            for n in R.NOITES:
                inv[q][n]["numAvail"] = max(0, inv[q][n]["numAvail"] - 1)
        res2 = self.plano(rs, hoje=date(2026, 11, 30), estado=est, inv=inv, receita=60000.0)
        self.assertFalse(res2["contexto"]["pausa"])
        self.assertEqual(res2["contexto"]["escada"]["Recanto"]["reveillon"], 2)

    def test_d0_nunca_sobe(self):
        hoje = date(2026, 12, 31)
        rs = [reserva("Q7", "2026-12-30", "2027-01-03", quando="2026-11-20T10:00:00Z")]
        res = self.plano(rs, hoje=hoje)
        it = por(res)
        self.assertNotIn(("Q7", "2026-12-31"), it)          # a noite de hoje não sobe
        self.assertIn(("Q7", "2027-01-01"), it)             # o fim de semana (futuro) sobe
        lin = [x for x in res["linhas"] if x["quarto"] == "Q7" and x["noite"] == "2026-12-31"][0]
        self.assertIn("D0", lin["acao"])

    def test_d0_sem_regra_vigente_nao_grava(self):
        regras = regras_hoje()
        regras["Q7"].pop("2026-12-31")
        res = R.montar_plano(CFG6, ORDEM, estado_hoje(), inv_hoje(), regras, date(2026, 12, 31),
                             [reserva("Q7", "2026-12-30", "2027-01-03", quando="2026-11-20T10:00:00Z")])
        self.assertNotIn(("Q7", "2026-12-31"), por(res))
        lin = [x for x in res["linhas"] if x["quarto"] == "Q7" and x["noite"] == "2026-12-31"][0]
        self.assertIn("sem regra", lin["acao"])

    def test_noites_passadas_nao_geram_item_e_o_plano_valida(self):
        # 31/12 com venda 30→03 em 30/12 (a escada do Réveillon e do fim de semana sobe) e sem a regra de 30/12
        rs = [reserva("Double", "2026-12-30", "2027-01-03", quando="2026-12-30T10:00:00Z")]
        for hoje in (date(2026, 12, 31), date(2027, 1, 1), date(2027, 1, 2)):
            regras = regras_hoje()
            regras["Q7"].pop("2026-12-30")
            res = R.montar_plano(CFG6, ORDEM, estado_hoje(), inv_hoje(), regras, hoje, rs)
            self.assertFalse([k for k in por(res) if date.fromisoformat(k[1]) < hoje], hoje)
            self.assertEqual(validar(res["itens"], hoje, CFG6).erros, [], hoje)
            lin = [x for x in res["linhas"] if x["noite"] == "2026-12-30"]
            self.assertTrue(all("passada" in x["acao"] for x in lin))


class TestMaximoAdiado(unittest.TestCase):
    """O máximo de R$ 6.000 sobe só quando a escada precisar e não antes de 23/11 (noites de 19 a 22/11 com teto manual
    do dono em percent_max); até o update_listing_data ser conferido, quartos.json fica em 5.000 e o preço segura nele."""
    LST = {q: {"max": 5000} for q in ("Q7", "Double", "Q2", "VK7", "VK2", "Balcony")}

    def test_segura_no_maximo_e_avisa_antes_de_23_11(self):
        rs = [reserva("Q7", "2026-12-30", "2027-01-01", quando="2026-11-18T10:00:00Z")]
        res = R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), date(2026, 11, 20), rs, None, self.LST)
        self.assertFalse([i for i in res["itens"] if i["tipo"] == "anuncio"])
        for q in ("Q7", "Q2", "Double"):
            self.assertLessEqual(valor_final(res, q, "2026-12-30"), 5000)
        self.assertTrue(any("23/11" in m for m in res["avisos"]), res["avisos"])
        self.assertEqual(validar(res["itens"], date(2026, 11, 20)).erros, [])

    def test_item_de_anuncio_so_da_pousada_que_precisa(self):
        rs = [reserva("Q7", "2026-12-30", "2027-01-01", quando="2026-11-18T10:00:00Z")]
        est = estado_hoje()
        est["escada"]["Recanto"]["reveillon"] = 1      # registrado em 20/11 (em 23/11 a escada para abaixo do alarme)
        res = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 11, 23), rs, None, self.LST)
        an = [i for i in res["itens"] if i["tipo"] == "anuncio"]
        self.assertEqual({i["quarto"] for i in an}, {"Q7", "Double", "Q2"})
        self.assertTrue(all(i["valor"] == 6000 for i in an))
        self.assertEqual(validar(res["itens"], date(2026, 11, 23)).erros, [])
        # sem --listings: aviso, sem item
        res2 = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 11, 23), rs)
        self.assertFalse([i for i in res2["itens"] if i["tipo"] == "anuncio"])
        self.assertTrue(any("--listings" in m for m in res2["avisos"]))

    def test_registrar_passa_o_max_de_quartos_json(self):
        bruto = {"quartos": [dict(q) for q in CFG["quartos"]]}
        it = {"tipo": "anuncio", "quarto": "Q7", "campo": "max", "valor": 6000, "ordem_id": R.ORDEM_ID}
        self.assertEqual(R.aplicar_anuncio(bruto, [it]), ["Q7: max 5000 → 6000"])
        self.assertEqual([q["max"] for q in bruto["quartos"] if q["curto"] == "Q7"], [6000])
        self.assertEqual(R.aplicar_anuncio(bruto, [it]), [])

    def test_6000_so_no_reveillon_depois_de_gravado(self):
        import pace
        self.assertEqual(maximo_da_noite(CFG, "Q7", "2026-12-30"), 5000)     # ainda não gravado
        self.assertEqual(maximo_da_noite(CFG6, "Q7", "2026-12-30"), 6000)
        self.assertEqual(maximo_da_noite(CFG6, "Q7", date(2027, 1, 2)), 6000)
        self.assertEqual(maximo_da_noite(CFG6, "Q7", "2026-12-24"), 5000)
        self.assertEqual(maximo_da_noite(CFG6, "VK7", "2027-01-03"), 5000)
        self.assertEqual(maximo_da_noite(CFG6, "Afrodite", "2026-12-24"), 10000)
        self.assertLessEqual(pace.teto_anuncio(CFG6, "Double", date(2026, 12, 24)), 5000)
        self.assertGreater(pace.teto_anuncio(CFG6, "Double", date(2026, 12, 30)), 5900)

    def test_validador_segura_5000_fora_do_reveillon(self):
        it = {"tipo": "data", "quarto": "Double", "min_price": 5500, "max_price": 5500, "motivo": "teste",
              "excecao": "dono", "ordem_id": R.ORDEM_ID}
        self.assertEqual(validar([dict(it, data="2026-12-30")], cfg=CFG6).erros, [])
        self.assertTrue(validar([dict(it, data="2026-12-30")]).erros)          # quartos.json ainda em 5.000
        v = validar([dict(it, data="2026-12-24", ordem_id="dono-2026-11-04-faixa-recanto-20261224")], cfg=CFG6)
        self.assertTrue(any("máximo do anúncio" in e for e in v.erros), v.erros)

    def test_ajustar_tetos_mantem_5000_explicito_fora_do_reveillon(self):
        import pace
        reg = {"VK7": {"2026-12-24": {"date": "2026-12-24", "min_price": 237, "min_price_type": "percent_base",
                                      "max_price": 237, "max_price_type": "percent_base", "min_stay": 2}}}
        it = {"tipo": "data", "quarto": "VK7", "data": "2026-12-24", "min_price": 4987.6, "max_price": 5000,
              "motivo": "teste"}
        out, apagar = pace.ajustar_tetos(CFG6, [it], reg)
        self.assertEqual((out[0]["max_price"], apagar), (5000.0, []))
        # noite sem teto por data: o item de piso ganha o teto da noite
        out, apagar = pace.ajustar_tetos(CFG6, [dict(it, max_price=None, data="2026-12-26")], {})
        self.assertEqual(out[0]["max_price"], 5000.0)
        # no Réveillon (máximo da noite = do anúncio) o teto solta como antes
        it30 = dict(it, data="2026-12-30", max_price=6000)
        out, _ = pace.ajustar_tetos(CFG6, [it30], {})
        self.assertNotIn("max_price", out[0])
        # com o anúncio em 5.000 (hoje), nada muda: o teto no máximo solta
        out, apagar = pace.ajustar_tetos(CFG, [it], reg)
        self.assertNotIn("max_price", out[0])
        self.assertEqual(len(apagar), 1)

    def test_vigia_do_maximo(self):
        coleta = {"rooms": {"Q7": {"days": [{"date": "2026-12-24", "price": 5600}, {"date": "2026-12-30", "price": 5900},
                                            {"date": "2026-12-26", "price": 4800, "uncustomized_price": 5100},
                                            {"date": "2026-11-20", "price": 5200}]},
                            "Afrodite": {"days": [{"date": "2026-12-24", "price": 7000}]}}}
        r = R.vigia_maximo(CFG6, coleta, HOJE)
        self.assertEqual([(i["quarto"], i["data"], i["max_price"]) for i in r["itens"]], [("Q7", "2026-12-24", 5000.0)])
        self.assertTrue(any("2026-11-20" in b for b in r["bloqueios"]), r["bloqueios"])   # noite congelada pelo dono
        self.assertEqual(validar(r["itens"], cfg=CFG6).erros, [])
        # antes de gravar: a noite com o algoritmo acima de 5.000 e sem teto por data também; percent_max bloqueia
        precos = {"VK7": {"data": [{"id": "x", "data": [{"date": "2027-02-06", "price": 4995},
                                                        {"date": "2027-07-10", "price": 3000}]}]}}
        regras = {"Q7": {"2027-03-01": {"date": "2027-03-01", "max_price": 10, "max_price_type": "percent_max"}}}
        r = R.vigia_maximo(CFG6, coleta, HOJE, precos, regras, antes=True)
        self.assertIn(("Q7", "2026-12-26"), {(i["quarto"], i["data"]) for i in r["itens"]})
        self.assertIn(("VK7", "2027-02-06"), {(i["quarto"], i["data"]) for i in r["itens"]})
        self.assertNotIn(("VK7", "2027-07-10"), {(i["quarto"], i["data"]) for i in r["itens"]})
        self.assertTrue(any("percent_max" in b for b in r["bloqueios"]))

    def test_motor_nao_mexe_nas_4_noites(self):
        from ordens import ordens_por_data
        for q in R.QUARTOS:
            for n in R.NOITES:
                self.assertTrue(ordens_por_data(ORDENS, q, date.fromisoformat(n)), (q, n))
            self.assertFalse(ordens_por_data(ORDENS, q, date(2026, 12, 29)))
            self.assertFalse(ordens_por_data(ORDENS, q, date(2027, 1, 3)))


class TestFimDeSemanaNoPedido(unittest.TestCase):
    def test_fator_baixo_nao_passa_do_pedido(self):
        est = estado_hoje()
        est["fatores"]["Recanto"]["fds"] = {"valor": 0.50, "medido_em": "2026-11-20"}
        res = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 11, 20), [])
        for q in ("Q7", "Q2", "Double", "Afrodite"):
            for n in ("2027-01-01", "2027-01-02"):
                self.assertLessEqual(valor_final(res, q, n), R.pedido(ORDEM, q, n) + 0.01, (q, n))
        self.assertTrue(any("pedido de 09/11" in m for m in res["avisos"]))
        self.assertEqual(validar(res["itens"], date(2026, 11, 20), CFG6, est).erros, [])
        # Villa sem a promoção e fator 0,50: King Spa no pedido, Balcony pela Regra G
        est["promocao_villa"] = {"ativa": False, "retirada_em": "2026-11-20"}
        est["fatores"]["Villa"]["fds"] = {"valor": 0.50, "medido_em": "2026-11-21"}
        res = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 11, 21), [])
        for q in ("VK7", "VK2"):
            self.assertLessEqual(valor_final(res, q, "2027-01-01"), R.pedido(ORDEM, q, "2027-01-01") + 0.01)
        self.assertEqual(validar(res["itens"], date(2026, 11, 21), CFG6, est).erros, [])

    def test_piso_do_periodo_em_publico_com_o_alvo_cortado(self):
        # revisão de 16/11: fator 0,45 (promoção + Genius) e 4 degraus em 31/12; o piso é o alvo PÚBLICO × 0,95^4 ÷ fator,
        # nunca acima do pedido (antes: pedido × 0,95^4, e o público ficava abaixo do piso aprovado)
        est = estado_hoje()
        est["fatores"]["Recanto"]["fds"] = {"valor": 0.45, "medido_em": "2026-12-20"}
        est["degraus"]["Recanto"]["fds"] = {"valor": 4, "em": "2026-12-31", "marcos": [d for d, _ in
                                                                                     ORDEM["descida"]["linhas"]["fds"]]}
        res = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 31), [])
        f4 = ORDEM["pisos_periodo"]["fator"]
        for q in ("Q7", "Q2", "Double", "Afrodite"):
            for n in ("2027-01-01", "2027-01-02"):
                pub = valor_final(res, q, n) * 0.45
                piso_pub = min(ORDEM["alvos"]["fds_publico"][q] * f4, R.pedido(ORDEM, q, n) * 0.45)
                self.assertGreaterEqual(pub + 0.01, piso_pub - 0.01, (q, n))
                self.assertLess(pub, piso_pub + 0.01 * CFG["por_curto"][q]["base"] * 0.45 + 1, (q, n))
        self.assertAlmostEqual(valor_final(res, "Q7", "2027-01-01"), 4621.62, delta=0.01)   # 2.076,99 ÷ 0,45 → +294
        self.assertEqual(validar(res["itens"], date(2026, 12, 31), CFG6, est).erros, [])
        # um item à mão abaixo do piso (o pedido × 0,95^4) é barrado
        it = {"tipo": "data", "quarto": "Q7", "data": "2027-01-01", "min_price": 4058.58, "max_price": 4058.58,
              "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID}
        self.assertTrue(any("piso do período" in e for e in validar([it], date(2026, 12, 31), CFG6, est).erros))

    def test_validador_barra_fim_de_semana_acima_do_pedido(self):
        it = {"tipo": "data", "quarto": "Q7", "data": "2027-01-01", "min_price": 5090.82, "max_price": 5090.82,
              "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID}
        v = validar([it], cfg=CFG6)
        self.assertTrue(any("acima do teto da ordem" in e for e in v.erros), v.erros)
        self.assertEqual(validar([dict(it, data="2026-12-30")], cfg=CFG6).erros, [])   # Réveillon: a escada passa

    def test_validador_piso_e_teto_do_reveillon(self):
        base = {"tipo": "data", "data": "2026-12-30", "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID}
        # abaixo do piso do período (Q7 4.051,35; Q2 4.070 em cadeia)
        v = validar([dict(base, quarto="Q7", min_price=3500, max_price=3500)], cfg=CFG6)
        self.assertTrue(any("piso do período" in e for e in v.erros), v.erros)
        v = validar([dict(base, quarto="Q2", min_price=4054.5, max_price=4054.5)], cfg=CFG6)   # +155: 1 ponto abaixo
        self.assertTrue(any("piso do período" in e for e in v.erros), v.erros)
        v = validar([dict(base, quarto="Q2", min_price=4038.6, max_price=4038.6)], cfg=CFG6)   # +154: 2 pontos abaixo
        self.assertTrue(any("piso do período" in e for e in v.erros), v.erros)
        self.assertEqual(validar([dict(base, quarto="Q2", min_price=4070.4, max_price=4070.4)], cfg=CFG6).erros, [])
        # Afrodite: teto de R$ 8.000 no Réveillon (o máximo do anúncio dela é 10.000)
        v = validar([dict(base, quarto="Afrodite", min_price=8200, max_price=8200)])
        self.assertTrue(any("acima do teto da ordem" in e for e in v.erros), v.erros)
        # Balcony abaixo do piso: revisão de 16/11 (3ª verificação, R3-02): ERRO quando os 90% da suíte com banheira cabem
        # no piso (no Réveillon, sempre: a King Spa não fica abaixo do piso dela, R$ 4.062,76); o aviso é só na faixa em
        # que a Regra G vence (testes em TestPisoDaBalcony)
        v = validar([dict(base, quarto="Balcony", min_price=3500, max_price=3500)], cfg=CFG6)
        self.assertTrue(any("piso do período" in e for e in v.erros), v.erros)

    def test_villa_com_promocao_mantem_o_fim_de_semana(self):
        it = {"tipo": "data", "quarto": "VK7", "data": "2027-01-01", "min_price": 4113, "max_price": 4113,
              "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID}
        self.assertTrue(any("promoção ativa" in e for e in validar([it]).erros))
        est = estado_hoje()
        est["promocao_villa"] = {"ativa": False, "retirada_em": "2026-11-20"}
        est["fatores"]["Villa"]["fds"] = {"valor": 0.62, "medido_em": "2026-11-21"}
        self.assertEqual(validar([it], estado=est).erros, [])
        # a flag da rodada (promoção retirada e fator novo no plano, antes do `registrar`) vale no validador do plano
        en = copy.deepcopy(est)
        v = Validador(CFG, HOJE, {}, ordens=ORDENS,
                      estado_plano={"estado_novo": en, "estado_flags": [["promocao_villa"], ["fatores", "Villa", "fds"]]})
        v.validar([dict(it)])
        self.assertEqual(v.erros, [])


class TestDescida(unittest.TestCase):
    PARTE_R = 7 / 12          # Recanto no Réveillon: 7 dos 12 pacotes livres da linha de base
    MARCOS_R = [d for d, _ in ORDEM["descida"]["linhas"]["reveillon"]]
    MARCOS_F = [d for d, _ in ORDEM["descida"]["linhas"]["fds"]]

    def seguir(self, bloco: str, dias: list, parte: float) -> tuple[list[int], dict]:
        """degraus_do_dia dia a dia ((data, total do bloco, pacotes da pousada)), com o estado guardado entre as rodadas."""
        ant, vals = None, []
        for ds, tot, n in dias:
            ant = R.degraus_do_dia(ORDEM, bloco, date.fromisoformat(ds), tot, n, parte, ant)
            vals.append(ant["valor"])
        return vals, ant

    def test_antes_do_d7_nada(self):
        self.assertEqual(R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 22), 0, 0, self.PARTE_R, None)["valor"], 0)
        self.assertEqual(R.degraus_do_dia(ORDEM, "fds", date(2026, 12, 24), 0, 0, 0.5, None)["valor"], 0)

    def test_venda_parcial_um_degrau_por_marco_abaixo_da_linha(self):
        # revisão de 16/11 (cen_descida.py): bloco abaixo da linha de cada marco, mas acima da do marco anterior; o
        # Recanto abaixo da sua parte em todos. Sem histórico, o script ficava em 1 degrau e subia em 27/12
        dias = [("2026-12-23", 5, 2), ("2026-12-24", 5, 2), ("2026-12-25", 4, 2), ("2026-12-26", 6, 3),
                ("2026-12-27", 7, 3), ("2026-12-28", 7, 3), ("2026-12-29", 8, 4), ("2026-12-30", 8, 4)]
        vals, fim = self.seguir("reveillon", dias, self.PARTE_R)
        self.assertEqual(vals, [1, 1, 2, 2, 3, 3, 4, 4])
        self.assertEqual(fim["marcos"], self.MARCOS_R)
        # fim de semana (cen_descida_fds.py): abaixo da linha nos 4 marcos → 4 degraus em 31/12
        dias = [("2026-12-25", 7, 3), ("2026-12-26", 8, 3), ("2026-12-27", 9, 4), ("2026-12-29", 11, 5),
                ("2026-12-31", 13, 6)]
        vals, _ = self.seguir("fds", dias, 11 / 23)
        self.assertEqual(vals, [1, 1, 2, 3, 4])

    def test_volta_um_degrau_por_dia_so_fora_da_linha_vigente(self):
        ant = {"valor": 2, "em": "2026-12-25", "marcos": self.MARCOS_R[:2]}
        # 26/12 com o bloco ainda abaixo da linha vigente (a de 25/12, 7): segura
        self.assertEqual(R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 26), 6, 3, self.PARTE_R, ant)["valor"], 2)
        # de volta à linha: sobe um degrau, um por dia
        d1 = R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 26), 7, 4, self.PARTE_R, ant)
        self.assertEqual((d1["valor"], d1["em"]), (1, "2026-12-26"))
        self.assertEqual(R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 26), 7, 4, self.PARTE_R, d1)["valor"], 1)
        d2 = R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 27), 8, 4, self.PARTE_R, d1)   # 27/12: linha 8
        self.assertEqual((d2["valor"], d2["em"]), (0, "2026-12-27"))
        # a pousada que passou da sua parte (4 > 7 × 7/12) sobe mesmo com o bloco abaixo
        self.assertEqual(R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 26), 6, 5, self.PARTE_R, ant)["valor"], 1)

    def test_pousada_acima_da_sua_parte_nao_desce(self):
        # bloco abaixo (5 < 6), mas o Recanto já vendeu 4 (> 6 × 7/12 = 3,5): só a Villa desce
        self.assertEqual(R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 23), 5, 4, self.PARTE_R, None)["valor"], 0)
        self.assertEqual(R.degraus_do_dia(ORDEM, "reveillon", date(2026, 12, 23), 5, 1, 5 / 12, None)["valor"], 1)

    def test_rotina_perdida_avalia_os_marcos_que_passaram(self):
        d = R.degraus_do_dia(ORDEM, "fds", date(2027, 1, 1), 0, 0, 0.5, None)
        self.assertEqual((d["valor"], d["marcos"]), (4, self.MARCOS_F))
        # marco já avaliado não conta de novo
        self.assertEqual(R.degraus_do_dia(ORDEM, "fds", date(2026, 12, 25), 0, 0, 0.5,
                                          {"valor": 1, "em": "2026-12-25", "marcos": ["2026-12-25"]})["valor"], 1)

    def test_preco_desce_5_por_cento_e_para_no_piso(self):
        est = estado_hoje()
        res = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 23), [])
        self.assertEqual(res["contexto"]["degraus"]["Recanto"]["reveillon"], 1)
        self.assertEqual(res["contexto"]["degraus"]["Recanto"]["fds"], 0)          # o D7 do fim de semana é 25/12
        self.assertEqual(res["estado_novo"]["degraus"]["Recanto"]["reveillon"],
                         {"valor": 1, "em": "2026-12-23", "marcos": ["2026-12-23"]})
        tol = 0.01 * 1480 + 1
        self.assertAlmostEqual(valor_final(res, "Double", "2026-12-30"), 4988 * 0.95, delta=tol)
        # quatro degraus: piso do período = alvo × 0,95^4 (nunca abaixo)
        est["degraus"]["Recanto"]["reveillon"] = {"valor": 4, "em": "2026-12-29", "marcos": self.MARCOS_R}
        res4 = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 29), [])
        piso = 4988 * 0.95 ** 4
        self.assertGreaterEqual(valor_final(res4, "Double", "2026-12-30") + 0.01, piso)
        self.assertLess(valor_final(res4, "Double", "2026-12-30"), piso + tol)
        self.assertEqual(validar(res4["itens"], date(2026, 12, 29)).erros, [])

    def test_degrau_sobre_o_alvo_sem_a_escada(self):
        # revisão de 16/11 (cen_misc.py, cenário 1): escada 2 no Réveillon do Recanto, alarme parado, 1 degrau em 23/12:
        # alvo × 0,95 (o alvo do degrau, proposta), não alvo × 1,03² × 0,95 (6% acima)
        est = estado_hoje()
        est["escada"]["Recanto"]["reveillon"] = 2
        rs = [reserva("Q7", "2026-12-30", "2027-01-01", quando=f"2026-11-{20 + i}T10:00:00Z", rid=100 + i)
              for i in range(2)]
        res = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 23), rs)
        self.assertEqual(res["contexto"]["escada"]["Recanto"]["reveillon"], 2)
        self.assertEqual(res["contexto"]["degraus"]["Recanto"]["reveillon"], 1)
        for q, alvo in (("Q7", 4974), ("Q2", 4977), ("Double", 4988)):
            tol = 0.01 * CFG["por_curto"][q]["base"] + 1
            self.assertAlmostEqual(valor_final(res, q, "2026-12-30"), alvo * 0.95, delta=tol, msg=q)
        self.assertAlmostEqual(valor_final(res, "Afrodite", "2026-12-30"), 6700 * 0.95, delta=21)
        # sem degrau, a escada vale: 2 pacotes = alvo × 1,03²
        res0 = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 22), rs)
        self.assertAlmostEqual(valor_final(res0, "Double", "2026-12-30"), 4988 * 1.03 ** 2, delta=15.8)
        self.assertEqual(validar(res["itens"], date(2026, 12, 23), CFG6).erros, [])

    def test_quatro_degraus_cada_quarto_no_proprio_piso_e_na_ordem(self):
        est = estado_hoje()
        est["degraus"]["Recanto"]["reveillon"] = {"valor": 4, "em": "2026-12-29", "marcos": self.MARCOS_R}
        res = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 29), [])
        pisos = ORDEM["pisos_periodo"]["reveillon_enviado"]
        for n in ("2026-12-30", "2026-12-31"):
            q7, q2, db = (valor_final(res, q, n) for q in ("Q7", "Q2", "Double"))
            self.assertGreaterEqual(q7 + 0.01, 4974 * 0.95 ** 4, n)                 # Moinhos nunca abaixo do piso
            self.assertGreaterEqual(q2 + 0.01, pisos["Q2"], n)                      # Q2 no piso da proposta (4.070)
            self.assertGreaterEqual(db + 0.01, 4988 * 0.95 ** 4, n)
            self.assertLessEqual(q7, q2 + R.TOL_ORDEM, n)
            self.assertLessEqual(q2, db + R.TOL_ORDEM, n)
            self.assertAlmostEqual(q2, 4070.4, delta=0.01)                        # +156, como na proposta
        self.assertEqual(validar(res["itens"], date(2026, 12, 29)).erros, [])

    def test_bloco_volta_a_linha_sobe_um_degrau(self):
        est = estado_hoje()
        est["degraus"]["Recanto"]["reveillon"] = {"valor": 2, "em": "2026-12-25", "marcos": self.MARCOS_R[:2]}
        rs = [reserva("VK7", "2026-12-30", "2027-01-01", quando=f"2026-12-{10 + i}T10:00:00Z") for i in range(7)]
        res = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 26), rs)
        self.assertEqual(res["contexto"]["degraus"]["Recanto"]["reveillon"], 1)
        self.assertEqual(res["estado_novo"]["degraus"]["Recanto"]["reveillon"],
                         {"valor": 1, "em": "2026-12-26", "marcos": self.MARCOS_R[:2]})


class TestMarco2112(unittest.TestCase):
    def test_bonus_de_5_por_cento_uma_vez(self):
        rs = [reserva("VK7" if i % 2 else "Q7", "2026-12-30", "2027-01-01", quando=f"2026-12-{1 + i:02d}T10:00:00Z")
              for i in range(10)]
        antes = R.montar_plano(CFG6, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), date(2026, 12, 20), rs)
        self.assertFalse(antes["contexto"]["bonus_marco"]["ativo"])
        res = R.montar_plano(CFG6, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), date(2026, 12, 21), rs)
        self.assertTrue(res["contexto"]["bonus_marco"]["ativo"])
        a, d = valor_final(antes, "Afrodite", "2026-12-30"), valor_final(res, "Afrodite", "2026-12-30")
        self.assertAlmostEqual(d / a, 1.05, delta=0.01)
        self.assertLessEqual(valor_final(res, "Q7", "2026-12-30"), 6000)            # dentro do teto
        self.assertEqual(valor_final(res, "Q7", "2027-01-01"), valor_final(antes, "Q7", "2027-01-01"))  # só Réveillon
        # uma vez só: ligado no estado, segue ligado (mesmo com cancelamento depois)
        est = estado_hoje()
        est["bonus_marco"] = res["estado_novo"]["bonus_marco"]
        depois = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 22), rs[:8])
        self.assertTrue(depois["contexto"]["bonus_marco"]["ativo"])
        self.assertEqual(validar(res["itens"], date(2026, 12, 21), CFG6).erros, [])


class TestCoerencia(unittest.TestCase):
    def test_ordem_do_recanto_e_afrodite_em_toda_escada(self):
        for k in range(0, 9):
            rs = [reserva("Afrodite", "2026-12-30", "2027-01-03", quando=f"2026-11-{17 + i:02d}T10:00:00Z")
                  for i in range(k)]
            for cfg in (CFG, CFG6):
                res = R.montar_plano(cfg, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE, rs)
                for n in R.NOITES:
                    q7, q2, db, af = (valor_final(res, q, n) for q in ("Q7", "Q2", "Double", "Afrodite"))
                    self.assertLessEqual(q7, q2 + R.TOL_ORDEM, (k, n))
                    self.assertLessEqual(q2, db + R.TOL_ORDEM, (k, n))
                    self.assertGreaterEqual(af + 0.01, 1.15 * db, (k, n))
                    self.assertGreater(af, max(q7, q2, db))
                self.assertEqual(validar(res["itens"], cfg=cfg).erros, [], k)

    def test_regra_g_com_king_spa_2_esgotada_e_regra_dos_80(self):
        inv = inv_hoje()
        inv["VK2"]["2026-12-30"]["numAvail"] = 0
        res = R.montar_plano(CFG6, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE,
                             [reserva("VK7", "2026-12-30", "2027-01-01")])
        vk7 = valor_final(res, "VK7", "2026-12-30")
        self.assertLessEqual(valor_final(res, "Balcony", "2026-12-30"), 0.9 * vk7 + 0.01)
        # 8 das 9 suítes com banheira vendidas: Balcony no mercado do quarto sem banheira, nunca acima da Regra G nem
        # abaixo do piso do período
        inv["VK7"]["2026-12-31"]["numAvail"] = 1
        inv["VK2"]["2026-12-31"]["numAvail"] = 0
        res = R.montar_plano(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE, [])
        b = valor_final(res, "Balcony", "2026-12-31")
        self.assertLessEqual(b, 0.9 * 4987.6 + 0.01)
        self.assertGreaterEqual(b + 13.7, 4483 * 0.95 ** 4)
        self.assertLess(b, 4483 - 100)
        self.assertEqual(validar(res["itens"]).erros, [])

    def test_balcony_no_piso_do_periodo_com_4_degraus(self):
        est = estado_hoje()
        est["promocao_villa"] = {"ativa": False, "retirada_em": "2026-11-20"}
        est["fatores"]["Villa"]["fds"] = {"valor": 0.62, "medido_em": "2026-11-21"}
        est["degraus"]["Villa"]["fds"] = {"valor": 4, "em": "2026-12-29"}
        res = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 12, 30), [])
        piso = 2295 / 0.62 * 0.95 ** 4
        b = valor_final(res, "Balcony", "2027-01-01")
        self.assertGreaterEqual(b + 0.01, piso)
        self.assertLessEqual(b, min(valor_final(res, "VK7", "2027-01-01"), valor_final(res, "VK2", "2027-01-01")))
        self.assertEqual(validar(res["itens"], date(2026, 12, 30), estado=est).erros, [])


class TestVillaFimDeSemana(unittest.TestCase):
    def test_promocao_ativa_mantem_e_depois_vai_ao_alvo(self):
        est = estado_hoje()
        self.assertFalse(R.villa_fds_liberada(est))
        est["promocao_villa"] = {"ativa": False, "retirada_em": "2026-11-20"}
        self.assertFalse(R.villa_fds_liberada(est))           # falta medir o fator depois
        est["fatores"]["Villa"]["fds"] = {"valor": 0.62, "medido_em": "2026-11-21"}
        self.assertTrue(R.villa_fds_liberada(est))
        res = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 11, 21), [])
        vk7 = valor_final(res, "VK7", "2027-01-01")
        self.assertAlmostEqual(vk7 * 0.62, 2550, delta=0.01 * 2550)
        self.assertLessEqual(valor_final(res, "Balcony", "2027-01-01"), 0.9 * min(vk7, valor_final(res, "VK2", "2027-01-01")) + 0.01)
        self.assertEqual(validar(res["itens"], date(2026, 11, 21), estado=est).erros, [])

    def test_fator_so_muda_fora_de_5_por_cento(self):
        f = {"valor": 0.5832, "medido_em": "2026-11-16"}
        self.assertEqual(R.adotar_fator(f, 0.60, date(2026, 11, 17), 0.05)[0], f)
        self.assertEqual(R.adotar_fator(f, 0.62, date(2026, 11, 17), 0.05)[0]["valor"], 0.62)


def preparar_portao(fator_fds: float | None = None):
    """Estado, regras e inventário de 18/11 com os preços do fim de semana gravados em 17/11 e enviados (price1 novo)."""
    est = estado_hoje()
    est["gravado_fds"] = {"Recanto": "2026-11-17T10:00:00Z", "Villa": "2026-11-17T10:00:00Z"}
    if fator_fds is not None:
        est["fatores"]["Recanto"]["fds"] = {"valor": fator_fds, "medido_em": "2026-11-17"}
    regras = regras_hoje()
    inv = inv_hoje()
    inv["_lido_em"] = "2026-11-18T12:00:00Z"
    plano = R.montar_plano(CFG, ORDEM, est, inv, regras, date(2026, 11, 17), [])
    for it in plano["itens"]:      # o PriceLabs gravou e enviou: regras e price1 com o preço novo
        p = piso_pct(CFG["por_curto"][it["quarto"]], it["min_price"])
        regras[it["quarto"]][it["data"]].update(min_price=p, max_price=p)
        inv[it["quarto"]][it["data"]]["price1"] = em_reais(CFG["por_curto"][it["quarto"]], p)
    return est, regras, inv


class TestPortao(unittest.TestCase):
    ENVIO = {"Recanto": "2026-11-18T11:47:00Z", "Villa": "2026-11-18T11:47:00Z"}

    def preparar(self, fator_fds: float | None = None):
        return preparar_portao(fator_fds)

    def test_condicao_2_contra_o_alvo_publico_da_ordem(self):
        # revisão de 16/11 (sonda_portao.py): fator 0,45, fim de semana cortado no pedido (Q7 4.974 × 0,45 = 2.238 contra
        # o alvo de 2.550, −12%). Antes o portão abria (o esperado caía junto); agora fica fechado e avisa
        est, regras, inv = self.preparar(0.45)
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, self.ENVIO, {"Recanto": 2238}, hoje=date(2026, 11, 18))
        self.assertFalse(av["Recanto"]["pode"])
        self.assertTrue(any("abaixo do alvo da ordem" in f for f in av["Recanto"]["faltam"]), av["Recanto"]["faltam"])
        self.assertEqual((av["Recanto"]["prova"]["quarto_ref"], av["Recanto"]["prova"]["alvo_publico"]), ("Q7", 2550))
        # o validador também: a prova com o público abaixo do alvo não abre a sexta
        prova = dict(av["Recanto"]["prova"])
        it = {"tipo": "data", "quarto": "Q7", "data": "2027-01-01", "min_stay": 2, "manter_reason": True,
              "excecao": "dono", "ordem_id": R.ORDEM_ID, "motivo": "teste", "portao_0103": prova}
        self.assertTrue(any("abaixo do alvo da ordem" in e
                            for e in validar([it], date(2026, 11, 18), ordens=ORDENS_OK).erros))
        sem_alvo = dict(prova, publico=2560, esperado=2552)
        sem_alvo.pop("alvo_publico")
        self.assertTrue(any("sem alvo_publico" in e for e in validar([dict(it, portao_0103=sem_alvo)],
                                                                      date(2026, 11, 18), ordens=ORDENS_OK).erros))
        forjado = dict(prova, publico=2238, esperado=2238, alvo_publico=1500)
        self.assertTrue(any("menor alvo da pousada" in e for e in validar([dict(it, portao_0103=forjado)],
                                                                           date(2026, 11, 18), ordens=ORDENS_OK).erros))

    def test_price1_so_nos_quartos_com_vaga(self):
        est, regras, inv = self.preparar()
        inv["Afrodite"]["2027-01-01"].update(numAvail=0, price1=4120)          # vendida e com o price1 velho
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, self.ENVIO, {"Recanto": 2560}, hoje=date(2026, 11, 18))
        self.assertTrue(av["Recanto"]["pode"], av["Recanto"]["faltam"])
        inv["Afrodite"]["2027-01-01"]["numAvail"] = 1                           # com vaga: trava
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, self.ENVIO, {"Recanto": 2560}, hoje=date(2026, 11, 18))
        self.assertFalse(av["Recanto"]["pode"])

    def test_preco_do_fim_de_semana_na_rotina_segura_o_portao(self):
        est, regras, inv = self.preparar()
        item = {"tipo": "data", "quarto": "Double", "data": "2027-01-02", "min_price": 4635, "max_price": 4635}
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, self.ENVIO, {"Recanto": 2560}, hoje=date(2026, 11, 18),
                              itens_plano=[item])
        self.assertFalse(av["Recanto"]["pode"])
        self.assertTrue(any("muda nesta rotina" in f for f in av["Recanto"]["faltam"]))
        # item do Réveillon não segura
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, self.ENVIO, {"Recanto": 2560}, hoje=date(2026, 11, 18),
                              itens_plano=[dict(item, data="2026-12-30")])
        self.assertTrue(av["Recanto"]["pode"], av["Recanto"]["faltam"])

    def test_mostrar_com_o_portao_fechado_nao_grava_lote_nem_itens(self):
        import tempfile
        from pathlib import Path
        with tempfile.TemporaryDirectory() as d:
            dd = Path(d)
            (dd / "inv.json").write_text(__import__("json").dumps(inv_hoje()), encoding="utf-8")
            regs = []
            for q, ns in regras_hoje().items():
                (dd / f"ov_{q}.json").write_text(__import__("json").dumps({"overrides": list(ns.values())}),
                                                 encoding="utf-8")
                regs.append(f"{q}={dd / f'ov_{q}.json'}")
            (dd / "plano.json").write_text('{"hoje": "2026-11-16", "itens": []}', encoding="utf-8")
            (dd / "lote.json").write_text('[{"velho": true}]', encoding="utf-8")
            args = ["portao", "--hoje", "2026-11-16", "--replay", "--inventario", str(dd / "inv.json"), "--regras", *regs,
                    "--plano", str(dd / "plano.json"), "--saida", str(dd / "portao.json"), "--lote-beds24",
                    str(dd / "lote.json"), "--mostrar"]
            self.assertEqual(R.main(args), 0)
            self.assertEqual(__import__("json").loads((dd / "lote.json").read_text(encoding="utf-8")), [])
            saida = __import__("json").loads((dd / "portao.json").read_text(encoding="utf-8"))
            self.assertEqual((saida["itens"], saida["patch_quartos"], saida["lote_beds24"]), ([], {}, []))
            # o --aplicar-config do portão saiu (reavaliava depois do `registrar`)
            self.assertEqual(R.main(args[:-1] + ["--aplicar-config"]), 1)
            # sem --plano: recusa
            i = args.index("--plano")
            self.assertEqual(R.main(args[:i] + args[i + 2:]), 1)

    def test_aplicar_config_pela_decisao_gravada(self):
        # revisão de 16/11 (reavaliacao_portao.py): decisão do item 4 (portão aberto) → gravação → `registrar` → a config
        # sai da decisão gravada, sem reavaliar (antes, o --aplicar-config reavaliava e, com gravado_fds novo, não fazia
        # nada: a auditoria do Beds24 fechava o 01→03 de novo)
        import calendario_12m as c12
        est, regras, inv = self.preparar()
        hoje = date(2026, 11, 18)
        saida, _ = R.decidir_portao(CFG, ORDEM_OK, est, inv, regras, self.ENVIO, {"Recanto": 2560}, hoje, [])
        self.assertTrue(saida["portao"]["Recanto"]["pode"])
        self.assertIn("patch_quartos", saida["portao"]["Recanto"])
        self.assertNotIn("patch_quartos", saida["portao"]["Villa"])
        self.assertTrue(all(i["quarto"] in R.POUSADAS["Recanto"] for i in saida["lote_beds24"]))
        disco = R.registrar({"itens": [{"tipo": "data", "quarto": "Q7", "data": "2027-01-01", "min_price": 4400}],
                             "estado_novo": est}, datetime(2026, 11, 18, 13, 5, tzinfo=timezone.utc), copy.deepcopy(est))
        self.assertEqual(disco["gravado_fds"]["Recanto"], "2026-11-18T13:05:00Z")    # o envio de 11:47 ficou antes
        bruto = copy.deepcopy({k: v for k, v in CFG.items() if k not in ("por_curto", "por_id")})
        gravado = dict(resumo_beds24=resumo_ok(saida["lote_beds24"]), gravado_pl=gravado_de(saida["itens"]))
        feitas, recusas = R.aplicar_config(bruto, disco, hoje, portao=saida, **gravado)
        self.assertEqual((feitas, recusas), (["portão do 01→03 aberto: Recanto"], []))
        self.assertEqual(disco["portao"]["Recanto"], {"aberto": True, "em": "2026-11-18"})
        self.assertFalse(disco["portao"]["Villa"]["aberto"])
        ov = bruto["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"]["2027-01-01"]
        self.assertEqual(ov, {"Q7": "none", "Q2": "none", "Double": "none", "Afrodite": "none"})
        # a auditoria do Beds24 com o 01→03 gravado não volta a noCheckIn
        cfg2 = dict(bruto, por_curto={q["curto"]: q for q in bruto["quartos"]}, por_id={q["id"]: q for q in bruto["quartos"]})
        inv_a = {q: copy.deepcopy(v) for q, v in inv.items() if q != "_lido_em"}
        for q in R.POUSADAS["Recanto"]:
            inv_a[q]["2027-01-01"].update(override="none", minStay=2)
            inv_a[q]["2027-01-02"]["minStay"] = 2
        cl = {x["quarto"]: x for x in c12.classificar_beds24(cfg2, inv_a, date(2026, 12, 29), date(2027, 1, 3))}
        for q in R.POUSADAS["Recanto"]:
            self.assertFalse([x for x in cl[q]["sabados_fora_da_regra"] if x["data"] == "2027-01-01"], q)
        # decisão de outro dia: recusada
        f2, r2 = R.aplicar_config(copy.deepcopy(bruto), copy.deepcopy(disco), date(2026, 11, 19), portao=saida, **gravado)
        self.assertEqual(f2, [])
        self.assertTrue(r2)
        # portão fechado na decisão: nada a aplicar
        fechado, _ = R.decidir_portao(CFG, ORDEM_OK, est, inv, regras, self.ENVIO, {"Recanto": 2300}, hoje, [])
        f3, _ = R.aplicar_config(copy.deepcopy({k: v for k, v in CFG.items() if k not in ("por_curto", "por_id")}),
                                 copy.deepcopy(est), hoje, portao=fechado)
        self.assertEqual(f3, [])

    def test_portao_fechado(self):
        est = estado_hoje()
        av = R.avaliar_portao(CFG, ORDEM, est, inv_hoje(), regras_hoje(), {}, {})
        self.assertFalse(av["Recanto"]["pode"])
        self.assertFalse(av["Villa"]["pode"])
        est, regras, inv = self.preparar()
        # envio parado (antes da gravação): fechado
        av = R.avaliar_portao(CFG, ORDEM, est, inv, regras, {"Recanto": "2026-11-15T12:21:00Z"}, {"Recanto": 2550})
        self.assertFalse(av["Recanto"]["pode"])
        # público fora de ±5%: fechado
        av = R.avaliar_portao(CFG, ORDEM, est, inv, regras, {"Recanto": "2026-11-18T11:47:00Z"}, {"Recanto": 2300})
        self.assertFalse(av["Recanto"]["pode"])
        # price1 do Beds24 ainda velho: fechado
        inv2 = copy.deepcopy(inv)
        inv2["Q7"]["2027-01-01"]["price1"] = 2979
        av = R.avaliar_portao(CFG, ORDEM, est, inv2, regras, {"Recanto": "2026-11-18T11:47:00Z"}, {"Recanto": 2560})
        self.assertFalse(av["Recanto"]["pode"])

    def test_envio_por_quarto_vale_o_mais_antigo(self):
        est, regras, inv = self.preparar()
        envio = {"Q7": "2026-11-18T11:47:00Z", "Q2": "2026-11-18T11:48:00Z", "Double": "2026-11-18T11:49:00Z",
                 "Afrodite": "2026-11-17T09:00:00Z"}          # a Afrodite ainda não enviou depois da gravação
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, envio, {"Recanto": 2560}, hoje=date(2026, 11, 18))
        self.assertFalse(av["Recanto"]["pode"])
        envio["Afrodite"] = "2026-11-18T11:50:00Z"
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, envio, {"Recanto": 2560}, hoje=date(2026, 11, 18))
        self.assertTrue(av["Recanto"]["pode"], av["Recanto"]["faltam"])
        self.assertEqual(av["Recanto"]["prova"]["envio"], "2026-11-18T11:47:00Z")
        del envio["Q2"]
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, envio, {"Recanto": 2560}, hoje=date(2026, 11, 18))
        self.assertTrue(any("Q2" in f for f in av["Recanto"]["faltam"]))

    def test_portao_aberto_por_pousada(self):
        est, regras, inv = self.preparar()
        hoje = date(2026, 11, 18)
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, {"Recanto": "2026-11-18T11:47:00Z",
                                                             "Villa": "2026-11-18T11:47:00Z"},
                              {"Recanto": 2560, "Villa": 1700}, hoje=hoje)
        self.assertTrue(av["Recanto"]["pode"], av["Recanto"]["faltam"])
        self.assertFalse(av["Villa"]["pode"])            # promoção da Villa
        ac = R.acoes_portao(CFG, ORDEM, inv, regras, "Recanto", av["Recanto"]["prova"], hoje)
        self.assertEqual(len(ac["comandos_beds24"]), 8)
        self.assertTrue(all("--aplicar" not in c for c in ac["comandos_beds24"]))
        self.assertIn("--override none --min-stay 2", ac["comandos_beds24"][0])
        af = [i for i in ac["itens_pricelabs"] if i["quarto"] == "Afrodite" and i["data"] == "2027-01-01"][0]
        self.assertEqual((af["check_in"], af["check_out"], af["min_stay"]), ("1111111", "1111111", 2))
        self.assertEqual(validar(ac["itens_pricelabs"], hoje, ordens=ORDENS_OK).erros, [])
        # sem a prova (e com a config fechada) o validador barra a estadia 2 e a sexta aberta
        sem = R.acoes_portao(CFG, ORDEM, inv, regras, "Recanto", None, hoje)["itens_pricelabs"]
        self.assertTrue(validar(sem, hoje).erros)
        p = ac["patch_quartos"]
        self.assertEqual(p["override_por_data_por_quarto"]["2027-01-01"],
                         {"Q7": "none", "Q2": "none", "Double": "none", "Afrodite": "none"})
        self.assertEqual(p["estadia_minima_por_data_por_quarto"]["2027-01-02"]["Afrodite"], 2)
        # com a config já aberta (depois do portão), a estadia 2 passa sem a prova
        cfg2 = copy.deepcopy(CFG)
        R.aplicar_patch(cfg2, p)
        v = Validador(cfg2, hoje, {}, ordens=ORDENS)
        v.validar(copy.deepcopy(sem))
        self.assertEqual(v.erros, [])
        # noite passada fica de fora (02/01: só 02/01)
        ac2 = R.acoes_portao(CFG, ORDEM, inv, regras, "Recanto", av["Recanto"]["prova"], date(2027, 1, 2))
        self.assertEqual({i["data"] for i in ac2["itens_pricelabs"]}, {"2027-01-02"})

    def test_auditoria_do_beds24_segue_o_patch_por_quarto(self):
        import calendario_12m as c12
        est, regras, inv = self.preparar()
        bruto = copy.deepcopy({k: v for k, v in CFG.items() if k not in ("por_curto", "por_id")})
        R.aplicar_patch(bruto, R.acoes_portao(CFG, ORDEM, inv, regras, "Recanto")["patch_quartos"])
        cfg2 = dict(bruto, por_curto={q["curto"]: q for q in bruto["quartos"]}, por_id={q["id"]: q for q in bruto["quartos"]})
        inv_a = {q: v for q, v in inv.items() if q != "_lido_em"}
        cl = {x["quarto"]: x for x in c12.classificar_beds24(cfg2, inv_a, date(2026, 12, 29), date(2027, 1, 3))}
        # Recanto: a auditoria pede a abertura (none em 01/01, mínimo 2 em 01 e 02/01)
        q7 = cl["Q7"]
        self.assertIn(("2027-01-01", "none"), {(s["data"], s["troca"]) for s in q7["sabados_fora_da_regra"]})
        self.assertEqual({s["data"] for s in q7["estadia_por_data"] if s.get("min_alvo") == 2}, {"2027-01-01", "2027-01-02"})
        # Villa: nada muda (01/01 segue noCheckIn)
        self.assertFalse([s for s in cl["VK7"]["sabados_fora_da_regra"] if s["data"] == "2027-01-01"])
        # depois de gravado no Beds24, a auditoria não desfaz
        for q in ("Q7", "Q2", "Double", "Afrodite"):
            inv_a[q]["2027-01-01"].update(override="none", minStay=2)
            inv_a[q]["2027-01-02"]["minStay"] = 2
        cl = {x["quarto"]: x for x in c12.classificar_beds24(cfg2, inv_a, date(2026, 12, 29), date(2027, 1, 3))}
        for q in ("Q7", "Q2", "Double", "Afrodite"):
            self.assertFalse([s for s in cl[q]["sabados_fora_da_regra"] if s["data"] in ("2027-01-01", "2027-01-02")], q)
            self.assertFalse([s for s in cl[q]["estadia_por_data"] if s["data"] in ("2027-01-01", "2027-01-02")], q)


class TestRestricoesDaOrdem(unittest.TestCase):
    BASE = {"tipo": "data", "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID, "manter_reason": True}

    def test_estadia_1_no_reveillon_barrada(self):
        for n in ("2026-12-30", "2026-12-31"):
            v = validar([dict(self.BASE, quarto="Q7", data=n, min_stay=1)])
            self.assertTrue(v.erros, n)

    def test_estadia_2_no_fim_de_semana_so_com_portao_ou_excecao_2(self):
        for n in ("2027-01-01", "2027-01-02"):
            self.assertTrue(validar([dict(self.BASE, quarto="Q7", data=n, min_stay=2)]).erros, n)
            self.assertEqual(validar([dict(self.BASE, quarto="Q7", data=n, min_stay=3)]).erros, [], n)

    def test_sabado_02_01_saida_sempre_fechada_e_entrada_so_com_prova(self):
        it = dict(self.BASE, quarto="Afrodite", data="2027-01-02", check_in="1111101", check_out="1111111")
        self.assertTrue(any("check_out" in e for e in validar([it]).erros))
        it = dict(self.BASE, quarto="Afrodite", data="2027-01-02", check_in="1111111", check_out="1111101")
        self.assertTrue(validar([it]).erros)
        prova = prova_orfa("Afrodite", 2)
        it.update(excecao_noite_solta=2, orfa_prova=prova)
        self.assertEqual(validar([it]).erros, [])
        it["orfa_prova"] = dict(prova, anterior_numAvail=1)          # 01/01 não esgotada: barrada
        self.assertTrue(validar([it]).erros)
        # revisão de 16/11: a prova é do quarto do item e das noites da exceção
        it["orfa_prova"] = prova_orfa("Q7", 2)
        self.assertTrue(any("prova do quarto" in e for e in validar([it]).erros))
        it["orfa_prova"] = prova_orfa("Afrodite", 2, noite="2027-01-03", vizinha_noite="2027-01-02")
        self.assertTrue(any("a exceção 2 pede" in e for e in validar([it]).erros))

    def test_sexta_01_01_e_31_12(self):
        it = dict(self.BASE, quarto="Afrodite", data="2027-01-01", check_in="1111111", check_out="1111111")
        self.assertTrue(validar([it]).erros)                         # sexta aberta sem o portão
        it = dict(self.BASE, quarto="Afrodite", data="2026-12-31", check_in="1111111", check_out="1110111")
        self.assertTrue(validar([it]).erros)                         # entrada em 31/12: nunca
        it = dict(self.BASE, quarto="Afrodite", data="2026-12-31", check_in="1110111", check_out="1111111")
        self.assertTrue(validar([it]).erros)                         # saída em 31/12 sem a exceção 1
        it.update(excecao_noite_solta=1, orfa_prova=prova_orfa("Afrodite", 1))
        self.assertEqual(validar([it]).erros, [])
        it["orfa_prova"] = prova_orfa("Afrodite", 1, noite="2026-12-31", vizinha_noite="2027-01-01")
        self.assertTrue(validar([it]).erros)

    def test_preco_fixo_barrado(self):
        it = {"tipo": "data", "quarto": "Q7", "data": "2026-12-30", "preco": 4974, "price_type": "fixed",
              "min_price": 4974, "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID}
        self.assertTrue(any("preço fixo" in e for e in validar([it]).erros))

    def test_travas_valem_para_item_que_nao_cita_a_ordem(self):
        # revisão de 16/11 (sonda_val.py): item de outro script ou à mão que abre a sexta 01/01 da Afrodite passava
        it = {"tipo": "data", "quarto": "Afrodite", "data": "2027-01-01", "check_in": "1111111", "check_out": "1111111",
              "motivo": "teste", "manter_reason": True}
        self.assertTrue(any("check_in abre" in e for e in validar([it]).erros))
        self.assertTrue(validar([{"tipo": "data", "quarto": "Q7", "data": "2027-01-02", "min_stay": 2, "motivo": "t",
                                  "manter_reason": True}]).erros)
        # a estadia de antes do portão (3) passa sem citar a ordem
        self.assertEqual(validar([{"tipo": "data", "quarto": "Q7", "data": "2027-01-02", "min_stay": 3, "motivo": "t",
                                   "manter_reason": True}]).erros, [])

    def test_preco_nessas_noites_so_pela_ordem(self):
        # revisão de 16/11: preço (ou apagar) numa noite preco_por_data sem citar a ordem é ERRO (o concorrencia_hidro_vista
        # e o ordem_recanto não consultavam a ordem)
        it = {"tipo": "data", "quarto": "VK7", "data": "2026-12-30", "max_price": 4500, "preco_antes": 4988,
              "motivo": "Concorrência hidro+vista: teste"}
        self.assertTrue(any("ordem do dono por data" in e for e in validar([it]).erros))
        ap = {"tipo": "apagar", "quarto": "Q7", "data": "2027-01-01", "motivo": "teste"}
        self.assertTrue(any("ordem do dono por data" in e for e in validar([ap]).erros))
        # fora das 4 noites, nada muda
        self.assertFalse([e for e in validar([dict(it, data="2026-12-29")]).erros if "ordem do dono por data" in e])

    def test_portao_e_preco_do_fim_de_semana_no_mesmo_plano(self):
        prova = {"pousada": "Recanto", "avaliado_em": "2026-11-16", "envio": "2026-11-16T18:00:00Z",
                 "gravado_fds": "2026-11-16T17:00:00Z", "publico": 2552, "esperado": 2552, "alvo_publico": 2550,
                 "quarto_ref": "Q7", "promocao_retirada": None}
        portao = {"tipo": "data", "quarto": "Q7", "data": "2027-01-02", "min_stay": 2, "manter_reason": True,
                  "excecao": "dono", "ordem_id": R.ORDEM_ID, "motivo": "teste", "portao_0103": prova}
        preco = {"tipo": "data", "quarto": "Double", "data": "2027-01-01", "min_price": 4543.6, "max_price": 4543.6,
                 "excecao": "dono", "ordem_id": R.ORDEM_ID, "motivo": "teste"}
        self.assertEqual(validar([portao], ordens=ORDENS_OK).erros, [])
        self.assertTrue(any("não abre na rotina" in e for e in validar([portao, preco], ordens=ORDENS_OK).erros))
        # preço da Villa não segura o portão do Recanto
        vk = dict(preco, quarto="VK7", min_price=4987.6, max_price=4987.6)
        self.assertEqual(validar([portao, vk], ordens=ORDENS_OK).erros, [])


class TestNoitesSoltas(unittest.TestCase):
    def test_hoje_nenhuma_excecao_abre_e_cotas_diretas(self):
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE)
        self.assertFalse(r["comandos_beds24"])
        self.assertEqual(sum(x["venda_direta_29_31"] for x in r["quartos"].values()), 10)
        self.assertEqual(sum(x["venda_direta_02_04"] for x in r["quartos"].values()), 4)
        self.assertEqual(r["quartos"]["Balcony"]["venda_direta_29_31"], 4)
        self.assertEqual(r["quartos"]["Q7"]["venda_direta_02_04"], 2)

    def test_excecao_1_so_com_31_12_esgotada(self):
        inv = inv_hoje()
        inv["Balcony"]["2026-12-31"]["numAvail"] = 0
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Balcony"]["excecao_1_ota"], "abre")
        self.assertEqual(r["quartos"]["Q7"]["excecao_1_ota"], "fechada")
        self.assertEqual(r["patch_quartos"]["override_por_data_por_quarto"]["2026-12-31"], {"Balcony": "noCheckIn"})
        self.assertTrue(any("--quarto Balcony --de 2026-12-31" in c and "--override noCheckIn " in c
                            for c in r["comandos_beds24"]))
        # sem vaga em 29/12: não abre
        inv["Balcony"]["2026-12-29"]["numAvail"] = 0
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Balcony"]["excecao_1_ota"], "fechada")
        # depois de 29/12 (a chegada do 29→31 passou): não abre
        inv["Balcony"]["2026-12-29"]["numAvail"] = 2
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), date(2026, 12, 30))
        self.assertEqual(r["quartos"]["Balcony"]["excecao_1_ota"], "fechada")

    def test_excecao_1_desfaz_quando_31_12_reabre(self):
        cfg = copy.deepcopy(CFG)
        cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"] = {"2026-12-31": {"Balcony": "noCheckIn"}}
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Balcony"]["acao_1"], "fechar")
        self.assertIn("--override noCheckInOrCheckOut", r["comandos_beds24"][0])
        self.assertEqual(r["patch_quartos"]["override_por_data_por_quarto"]["2026-12-31"], {"Balcony": None})

    def test_excecao_1_fecha_quando_a_vizinha_reabre_ou_o_29_31_nao_cabe(self):
        cfg = copy.deepcopy(CFG)
        cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"] = {"2026-12-31": {"Balcony": "noCheckIn"}}
        inv = inv_hoje()
        inv["Balcony"]["2026-12-31"]["numAvail"] = 0
        # aplicada, 31/12 esgotada e 29/12 com vaga: fica
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertNotIn("acao_1", r["quartos"]["Balcony"])
        self.assertFalse(r["comandos_beds24"])
        # revisão de 16/11: 29/12 esgotou: fecha (a 30/12 isolada viraria 30→31 pela regra de dia órfão do PriceLabs)
        inv2 = copy.deepcopy(inv)
        inv2["Balcony"]["2026-12-29"]["numAvail"] = 0
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv2, regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Balcony"]["acao_1"], "fechar")
        self.assertIn("--override noCheckInOrCheckOut", r["comandos_beds24"][0])
        # 30/12: a chegada do 29→31 passou: fecha, mesmo com 31/12 esgotada
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), date(2026, 12, 30))
        self.assertEqual(r["quartos"]["Balcony"]["acao_1"], "fechar")
        # 31/12 reabriu: fecha
        inv["Balcony"]["2026-12-31"]["numAvail"] = 1
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Balcony"]["acao_1"], "fechar")
        # 01/01: a noite de 31/12 passou, nada a gravar
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), date(2027, 1, 1))
        self.assertFalse(r["comandos_beds24"])

    def test_excecao_2_fecha_quando_03_01_esgota(self):
        cfg = copy.deepcopy(CFG)
        cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"] = {"2027-01-02": {"Q7": "noCheckOut"}}
        cfg["parametros"]["regra_h_beds24"]["estadia_minima_por_data_por_quarto"] = {"2027-01-02": {"Q7": 2}}
        inv = inv_hoje()
        inv["Q7"]["2027-01-01"]["numAvail"] = 0
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertNotIn("acao_2", r["quartos"]["Q7"])
        inv["Q7"]["2027-01-03"]["numAvail"] = 0          # 03/01 esgotou: a 02/01 isolada viraria 02→03
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Q7"]["acao_2"], "fechar")
        it = [i for i in r["itens_pricelabs"] if i["quarto"] == "Q7"][0]
        self.assertEqual((it["data"], it["min_stay"]), ("2027-01-02", 3))       # volta à estadia de antes do portão
        self.assertEqual(r["patch_quartos"]["override_por_data_por_quarto"]["2027-01-02"], {"Q7": None})
        self.assertEqual(validar(r["itens_pricelabs"]).erros, [])

    def test_excecao_2_so_com_01_01_esgotada(self):
        inv = inv_hoje()
        inv["Q7"]["2027-01-01"]["numAvail"] = 0
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Q7"]["excecao_2_ota"], "abre")
        self.assertEqual(r["patch_quartos"]["override_por_data_por_quarto"]["2027-01-02"], {"Q7": "noCheckOut"})
        self.assertEqual(r["patch_quartos"]["estadia_minima_por_data_por_quarto"]["2027-01-02"], {"Q7": 2})
        it = [i for i in r["itens_pricelabs"] if i["quarto"] == "Q7"][0]
        self.assertEqual((it["data"], it["min_stay"], it["excecao_noite_solta"]), ("2027-01-02", 2, 2))
        self.assertEqual(validar(r["itens_pricelabs"]).erros, [])
        # 01/01 livre: não abre (a noite solta comeria pacote)
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Q7"]["excecao_2_ota"], "fechada")

    def test_excecoes_na_afrodite_passam_no_validador(self):
        inv = inv_hoje()
        inv["Afrodite"]["2027-01-01"]["numAvail"] = 0
        inv["Afrodite"]["2027-01-03"]["numAvail"] = 1
        inv["Afrodite"]["2026-12-31"]["numAvail"] = 0
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        its = {i["data"]: i for i in r["itens_pricelabs"] if i["quarto"] == "Afrodite"}
        self.assertEqual((its["2027-01-02"]["check_in"], its["2027-01-02"]["check_out"]), ("1111111", "1111101"))
        self.assertEqual((its["2026-12-31"]["check_in"], its["2026-12-31"]["check_out"]), ("1110111", "1111111"))
        self.assertEqual(validar(list(its.values())).erros, [])
        # a prova é do inventário: lido há mais de um dia, o validador barra
        velho = copy.deepcopy(list(its.values()))
        for i in velho:
            i["orfa_prova"]["lido_em"] = "2026-11-10T10:00:00Z"
        self.assertTrue(validar(velho).erros)


class TestJuntarERegistrar(unittest.TestCase):
    def test_juntar_plano_orfas_e_portao(self):
        plano = R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE, [])
        prova = {"pousada": "Recanto", "avaliado_em": "2026-11-16", "envio": "2026-11-16T18:00:00Z",
                 "gravado_fds": "2026-11-16T17:00:00Z", "publico": 2552, "esperado": 2552, "alvo_publico": 2550,
                 "quarto_ref": "Q7", "promocao_retirada": None}
        ac = R.acoes_portao(CFG, ORDEM, inv_hoje(), regras_hoje(), "Recanto", prova, HOJE)
        # revisão de 16/11: o portão do Recanto não abre na rotina em que o preço do fim de semana do Recanto muda
        with self.assertRaises(ValueError):
            R.juntar_planos([{"hoje": "2026-11-16", "itens": plano["itens"], "estado_novo": plano["estado_novo"]},
                             {"hoje": "2026-11-16", "itens": ac["itens_pricelabs"]}])
        so_rev = [i for i in plano["itens"] if i["data"] < "2027"]
        un = R.juntar_planos([{"hoje": "2026-11-16", "itens": so_rev, "estado_novo": plano["estado_novo"]},
                              {"hoje": "2026-11-16", "itens": ac["itens_pricelabs"]}])
        it = {(i["quarto"], i["data"]): i for i in un["itens"]}
        self.assertEqual(it[("Q7", "2027-01-01")]["min_stay"], 2)
        self.assertEqual(it[("Afrodite", "2027-01-01")]["check_in"], "1111111")
        self.assertEqual(len(un["itens"]), 16)
        self.assertEqual(validar(un["itens"], ordens=ORDENS_OK).erros, [])
        # órfã e preço na mesma noite: um item só (o preço do plano, a máscara da exceção 1)
        inv = inv_hoje()
        inv["Afrodite"]["2026-12-31"]["numAvail"] = 0
        orf = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        un = R.juntar_planos([{"hoje": "2026-11-16", "itens": plano["itens"]},
                              {"hoje": "2026-11-16", "itens": orf["itens_pricelabs"]}])
        af = [i for i in un["itens"] if (i["quarto"], i["data"]) == ("Afrodite", "2026-12-31")]
        self.assertEqual(len(af), 1)
        self.assertEqual((piso_pct(CFG["por_curto"]["Afrodite"], af[0]["min_price"]), af[0]["check_out"]), (235, "1111111"))
        self.assertNotIn("manter_reason", af[0])
        self.assertEqual(validar(un["itens"]).erros, [])
        with self.assertRaises(ValueError):
            R.juntar_planos([{"hoje": "2026-11-16", "itens": []}, {"hoje": "2026-11-17", "itens": []}])

    def test_payloads_nao_sobrescrevem(self):
        import tempfile
        from pathlib import Path
        plano = R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE, [])
        r = R.payloads(CFG, {"hoje": "2026-11-16", "itens": plano["itens"]}, HOJE, ORDENS)
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(len(R._salvar_payloads(r, Path(d), False)), 4)
            with self.assertRaises(FileExistsError):
                R._salvar_payloads(r, Path(d), False)
            self.assertEqual(len(R._salvar_payloads(r, Path(d), True)), 4)

    def test_gravado_fds_so_da_pousada_com_item(self):
        res = R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE, [])
        novo = R.registrar({"itens": res["itens"], "estado_novo": res["estado_novo"]},
                           datetime(2026, 11, 16, 19, 0, tzinfo=timezone.utc))
        self.assertEqual(novo["gravado_fds"], {"Recanto": "2026-11-16T19:00:00Z", "Villa": None})

    def test_registrar_nao_desfaz_o_portao_aplicado(self):
        est = estado_hoje()
        plano = R.montar_plano(CFG, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 11, 17),
                               [reserva("Q7", "2026-12-30", "2027-01-01")])
        disco = copy.deepcopy(est)
        disco["portao"]["Recanto"] = {"aberto": True, "em": "2026-11-17"}       # `aplicar-config --portao` no meio
        disco["fatores"]["Villa"]["fds"] = {"valor": 0.61, "medido_em": "2026-11-17"}
        novo = R.registrar({"itens": plano["itens"], "estado_novo": plano["estado_novo"], "estado_flags": []},
                           datetime(2026, 11, 17, 19, 0, tzinfo=timezone.utc), disco)
        self.assertEqual(novo["portao"]["Recanto"], {"aberto": True, "em": "2026-11-17"})
        self.assertEqual(novo["fatores"]["Villa"]["fds"]["valor"], 0.61)          # fator não mexido pelo plano fica
        self.assertEqual(novo["escada"]["Recanto"]["reveillon"], 1)               # o que o plano decide entra
        # fator medido na rodada do plano (estado_flags) entra
        plano["estado_novo"]["fatores"]["Recanto"]["fds"] = {"valor": 0.55, "medido_em": "2026-11-17"}
        novo = R.registrar({"itens": [], "estado_novo": plano["estado_novo"],
                            "estado_flags": [["fatores", "Recanto", "fds"]]},
                           datetime(2026, 11, 17, 19, 0, tzinfo=timezone.utc), disco)
        self.assertEqual(novo["fatores"]["Recanto"]["fds"]["valor"], 0.55)
        self.assertEqual(novo["portao"]["Recanto"]["aberto"], True)


class TestOutrosScriptsForaDasNoites(unittest.TestCase):
    """Revisão de 16/11: o concorrencia_hidro_vista e o ordem_recanto não consultavam as ordens por data e podiam mexer
    no preço das 4 noites (o validador agora também barra)."""
    ORD = {R.ORDEM_ID: ORDEM}

    def test_concorrencia_hidro_vista_pula_as_noites_da_ordem(self):
        import concorrencia_hidro_vista as c
        dia = lambda d, p, mu: {"date": d, "price": p, "min_stay": 2, "multi_unit_occupancy": mu, "booking_status": ""}
        coleta = {"rooms": {"VK2": {"days": [dia("2026-12-30", 4991, "0/2"), dia("2026-12-29", 3000, "0/2")]},
                            "VK7": {"days": [dia("2026-12-30", 4988, "0/7"), dia("2026-12-29", 3000, "0/7")]}}}
        comp = {"periodos": [{"rotulo": "t", "datas": ["2026-12-29", "2026-12-30"], "mediana_a": 600, "recanto": 1100}]}
        sem, _ = c.montar(CFG, comp, coleta, HOJE, None, ordens={})
        self.assertIn(("VK7", "2026-12-30"), {(i["quarto"], i["data"]) for i in sem})     # sem a ordem, cortaria
        com, notas = c.montar(CFG, comp, coleta, HOJE, None, ordens=self.ORD)
        self.assertEqual({i["data"] for i in com}, {"2026-12-29"})
        self.assertTrue(any("ordem do dono por data" in n for n in notas))

    def test_ordem_recanto_pula_as_noites_da_ordem(self):
        import ordem_recanto as orc
        r = lambda p, mu: {"price": p, "multi_unit_occupancy": mu, "booking_status": ""}
        for n, esperado in (("2026-12-29", True), ("2026-12-30", False)):
            d = date.fromisoformat(n)
            out = orc.comparar(CFG, {n: r(3000, "0/7")}, {n: r(3200, "0/6")}, {n: r(1000, "0/2")}, d, d,
                               {n: r(1500, "")}, hoje=HOJE, ordens=self.ORD)
            self.assertEqual(bool(out), esperado, n)



# ---------------------------------------------------------------------------------------------------------------------
# 3ª verificação de 16/11 (achados R3-01 a R3-15, rev33/achados_r3.json): cada teste falhava com o código de antes
ENVIO_18 = {"Recanto": "2026-11-18T11:47:00Z", "Villa": "2026-11-18T11:47:00Z"}
D18 = date(2026, 11, 18)


def _arquivos(dd, inv: dict) -> list[str]:
    """inv.json e ov_<Q>.json na pasta do teste; devolve os --regras."""
    import json
    (dd / "inv.json").write_text(json.dumps(inv), encoding="utf-8")
    regs = []
    for q, ns in regras_hoje().items():
        (dd / f"ov_{q}.json").write_text(json.dumps({"overrides": list(ns.values())}), encoding="utf-8")
        regs.append(f"{q}={dd / f'ov_{q}.json'}")
    return regs


class TestR3Validador(unittest.TestCase):
    BASE = {"tipo": "data", "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID}

    def test_r3_01_mascara_de_um_lado_so(self):
        # sonda_r3 casos A a D: o payload manda o lado ausente como 1111111 e abria a sexta 01/01, a saída do sábado
        # 02/01 e a da quinta 31/12, com ou sem citar a ordem
        semo = {"tipo": "data", "motivo": "teste", "manter_reason": True}
        com = dict(self.BASE, manter_reason=True)
        casos = {"A": dict(com, quarto="Afrodite", data="2027-01-01", check_out="1111111"),
                 "B": dict(semo, quarto="Afrodite", data="2027-01-01", check_out="1111111"),
                 "C": dict(com, quarto="Afrodite", data="2027-01-02", check_in="1111101"),
                 "D": dict(com, quarto="Afrodite", data="2026-12-31", check_in="1110111")}
        for k, it in casos.items():
            v = validar([it])
            self.assertTrue(any("só um lado da máscara" in e for e in v.erros), (k, v.erros))
            self.assertTrue(any(" abre " in e for e in v.erros), (k, v.erros))     # avaliado como o payload manda
        # os dois lados, como o reveillon_250k.py manda: passa
        self.assertEqual(validar([dict(com, quarto="Afrodite", data="2027-01-01", check_in="1111011",
                                       check_out="1111111")]).erros, [])
        # fora das noites com máscara na ordem, um lado só não é trava da ordem
        self.assertFalse([e for e in validar([dict(semo, quarto="Afrodite", data="2026-12-29", check_out="1111111")]).erros
                          if "só um lado" in e])

    def test_r3_02_piso_da_balcony_nas_4_noites(self):
        base = dict(self.BASE, data="2026-12-30")
        # sonda_balcony: 1.500, 2.600 e 3.300 passavam com 0 erro (só aviso)
        for val in (1500, 2600, 3300):
            v = validar([dict(base, quarto="Balcony", min_price=val, max_price=val)], cfg=CFG6)
            self.assertTrue(any("piso do período" in e for e in v.erros), (val, v.erros))
        self.assertEqual(validar([dict(base, quarto="Balcony", min_price=3657.6, max_price=3657.6)], cfg=CFG6).erros, [])
        # a faixa do aviso: a suíte com banheira livre mais barata (calendário de --precos) a R$ 3.900, 90% = 3.510 abaixo
        # do piso (3.651,43): a Regra G vence entre 85,5% e 90%; abaixo de 85,5% é ERRO
        precos = {("VK7", "2026-12-30"): {"preco": 3900, "livres": 2, "vendidas": 5, "total": 7},
                  ("VK2", "2026-12-30"): {"preco": 4100, "livres": 1, "vendidas": 1, "total": 2}}
        v = validar([dict(base, quarto="Balcony", min_price=3500, max_price=3500)], cfg=CFG6, precos=precos)
        self.assertFalse([e for e in v.erros if "piso do período" in e], v.erros)
        self.assertTrue(any("a Regra G vence" in a for a in v.avisos), v.avisos)
        v = validar([dict(base, quarto="Balcony", min_price=3000, max_price=3000)], cfg=CFG6, precos=precos)
        self.assertTrue(any("85,5%" in e for e in v.erros), v.erros)
        # a King Spa 7 esgotada no calendário: vale a King Spa 2 (4.100 × 0,9 = 3.690 cabe no piso): ERRO
        precos[("VK7", "2026-12-30")]["livres"] = 0
        v = validar([dict(base, quarto="Balcony", min_price=3500, max_price=3500)], cfg=CFG6, precos=precos)
        self.assertTrue(any("a Regra G cabe no piso" in e for e in v.erros), v.erros)
        # o plano de hoje e a Regra G do script seguem passando (King Spa no plano)
        res = R.montar_plano(CFG6, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE,
                             [reserva("VK7", "2026-12-30", "2027-01-01")])
        self.assertEqual(validar(res["itens"], cfg=CFG6).erros, [])

    def test_r3_04_anuncio_so_com_a_ordem_no_quarto_e_valor_aprovados(self):
        an = {"tipo": "anuncio", "campo": "max", "excecao": "dono", "ordem_id": R.ORDEM_ID, "motivo": "teste"}
        d23 = date(2026, 11, 23)
        self.assertEqual(validar([dict(an, quarto="Q7", valor=6000)], d23).erros, [])
        # sonda_anuncio: Q7 9.000, Afrodite 15.000 e ordem inexistente passavam
        self.assertTrue(any("aprova o máximo" in e for e in validar([dict(an, quarto="Q7", valor=9000)], d23).erros))
        self.assertTrue(any("só libera o máximo" in e
                            for e in validar([dict(an, quarto="Afrodite", valor=15000)], d23).erros))
        self.assertTrue(any("sem ordem registrada" in e
                            for e in validar([dict(an, quarto="Q7", valor=6000, ordem_id="inexistente-xyz")], d23).erros))
        self.assertTrue(validar([dict(an, quarto="Q7", campo="min", valor=1058)], d23).erros)
        # antes de 23/11 (teto manual do dono de 19 a 22/11 em percent_max sobre o máximo): barrado
        self.assertTrue(any("a partir de 2026-11-23" in e for e in validar([dict(an, quarto="Q7", valor=6000)]).erros))
        # a volta a R$ 5.000 depois de 02/01 passa (a ordem venceu, mas a volta é dela)
        self.assertEqual(validar([dict(an, quarto="Q7", valor=5000)], date(2027, 1, 3)).erros, [])
        self.assertTrue(validar([dict(an, quarto="Q7", valor=6000)], date(2027, 1, 3)).erros)

    def test_r3_05_apagar_nessas_noites_e_erro(self):
        # sonda_r3 casos E e F: apagar citando a ordem passava só com avisos
        for q, n in (("Afrodite", "2027-01-01"), ("Q7", "2026-12-31"), ("VK7", "2027-01-02")):
            ap = {"tipo": "apagar", "quarto": q, "data": n, "motivo": "teste", "excecao": "dono", "ordem_id": R.ORDEM_ID}
            self.assertTrue(any("apagar numa noite com ordem do dono por data" in e for e in validar([ap]).erros), (q, n))
        fora = {"tipo": "apagar", "quarto": "Q7", "data": "2026-12-29", "motivo": "teste"}
        self.assertFalse([e for e in validar([fora]).erros if "ordem do dono por data" in e])

    def test_r3_09_piso_no_percentual_inteiro(self):
        # val_folga: um ponto inteiro abaixo do piso aprovado passava (folga de 1% da base + R$ 1)
        base = dict(self.BASE, data="2026-12-30")
        aprovado = {"Q7": 246, "Q2": 156, "Double": 175, "Afrodite": 173, "VK7": 175, "VK2": 221}
        for q, p in aprovado.items():
            b = CFG["por_curto"][q]["base"]
            ok, baixo = round(b * (1 + p / 100), 2), round(b * (1 + (p - 1) / 100), 2)
            v = validar([dict(base, quarto=q, min_price=ok, max_price=ok)], cfg=CFG6)
            self.assertFalse([e for e in v.erros if "piso" in e], (q, v.erros))
            v = validar([dict(base, quarto=q, min_price=baixo, max_price=baixo)], cfg=CFG6)
            self.assertTrue(any("piso do período" in e for e in v.erros), (q, p - 1))
        # fim de semana (fator 0,5832): Moinhos +203 (público ~R$ 2.073, abaixo do piso público de R$ 2.077) barrado
        fds = dict(self.BASE, data="2027-01-01", quarto="Q7")
        self.assertTrue(any("piso do período" in e for e in validar([dict(fds, min_price=3554.19, max_price=3554.19)]).erros))
        self.assertEqual(validar([dict(fds, min_price=3565.92, max_price=3565.92)]).erros, [])

    def test_r3_09_piso_no_pedido_com_fator_muito_baixo(self):
        # com o fator do fim de semana abaixo de ~0,42 o piso do período fica no pedido de 09/11 (Moinhos 4.974): o ceil
        # dele (+325) passava do pedido; o plano fica no +324 (dentro do teto) e o validador aceita
        est = estado_hoje()
        est["fatores"]["Recanto"]["fds"] = {"valor": 0.40, "medido_em": "2026-11-20"}
        res = R.montar_plano(CFG6, ORDEM, est, inv_hoje(), regras_hoje(), date(2026, 11, 20), [])
        for q in ("Q7", "Q2", "Double", "Afrodite"):
            self.assertLessEqual(valor_final(res, q, "2027-01-01"), R.pedido(ORDEM, q, "2027-01-01") + 0.01, q)
        self.assertEqual(validar(res["itens"], date(2026, 11, 20), CFG6, est).erros, [])
        it = dict(self.BASE, data="2027-01-01", quarto="Q7", min_price=4961.79, max_price=4961.79)   # +323: barrado
        self.assertTrue(any("piso do período" in e for e in validar([it], date(2026, 11, 20), CFG6, est).erros))

    def test_r3_10_ordem_do_recanto_no_validador(self):
        base = dict(self.BASE, data="2026-12-30")
        # val_ordem: Moinhos acima da Panorâmica I passava com 0 erro
        its = [dict(base, quarto="Q7", min_price=4997, max_price=4997),
               dict(base, quarto="Double", min_price=4987.6, max_price=4987.6)]
        self.assertTrue(any("ordem do Recanto" in e and "Q7" in e for e in validar(its, cfg=CFG6).erros))
        its = [dict(base, quarto="Q7", min_price=4058.58, max_price=4058.58),
               dict(base, quarto="Q2", min_price=4070.4, max_price=4070.4),
               dict(base, quarto="Double", min_price=4055.2, max_price=4055.2)]
        self.assertTrue(any("ordem do Recanto" in e and "Q2 R$" in e for e in validar(its, cfg=CFG6).erros))
        # Afrodite abaixo de 1,15 × Panorâmica I
        its = [dict(base, quarto="Double", min_price=4987.6, max_price=4987.6),
               dict(base, quarto="Afrodite", min_price=5460, max_price=5460)]
        self.assertTrue(any("Afrodite R$" in e for e in validar(its, cfg=CFG6).erros))
        # sem item da Double: o valor do calendário de --precos (a regra vigente) entra
        precos = {("Double", "2026-12-30"): {"preco": 4987.6, "livres": 6, "vendidas": 0, "total": 6}}
        v = validar([dict(base, quarto="Q7", min_price=4997, max_price=4997)], cfg=CFG6, precos=precos)
        self.assertTrue(any("ordem do Recanto" in e for e in v.erros), v.erros)
        # a proposta aprovada (Q2 4.070,40 "igual" à Double 4.070,00) passa na tolerância de R$ 1
        its = [dict(base, quarto="Q7", min_price=4058.58, max_price=4058.58),
               dict(base, quarto="Q2", min_price=4070.4, max_price=4070.4),
               dict(base, quarto="Double", min_price=4070, max_price=4070),
               dict(base, quarto="Afrodite", min_price=5460, max_price=5460)]
        self.assertFalse([e for e in validar(its, cfg=CFG6).erros if "ordem do Recanto" in e and "Afrodite" not in e])


class TestR3Plano(unittest.TestCase):
    def test_r3_08_linha_de_alarme_com_a_receita(self):
        # cen_receita: em 24/11 com 44 quarto-noites e um pacote, sem --receita a escada subia sem aviso
        inv = inv_hoje()
        for n in R.NOITES:
            inv["Double"][n]["numAvail"] -= 1                    # 44 quarto-noites (≥ 43 da linha de 23/11)
        rs = [reserva("Q7", "2026-12-30", "2027-01-01", quando="2026-11-20T10:00:00Z", rid=1)]
        d24 = date(2026, 11, 24)
        res = R.montar_plano(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), d24, rs)
        self.assertTrue(res["contexto"]["pausa"])                 # a reserva sem valor dá R$ 0 < 53.100
        self.assertEqual(res["contexto"]["escada"]["Recanto"]["reveillon"], 0)
        self.assertEqual(res["contexto"]["receita"], 0.0)
        # a receita sai das reservas lidas (receita de hospedagem rateada por noite, como o beds24.py livro)
        rs2 = [dict(rs[0], receita_hospedagem=60000.0), reserva("VK7", "2026-12-29", "2026-12-31", rid=2)]
        rs2[1]["invoiceItems"] = [{"type": "charge", "lineTotal": 2000.0}]     # 29→31: só 30/12 conta (R$ 1.000)
        self.assertEqual(R.receita_das_noites(CFG, rs2), 61000.0)
        res2 = R.montar_plano(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), d24, rs2[:1])
        self.assertFalse(res2["contexto"]["pausa"], res2["contexto"]["alarme"])
        self.assertEqual(res2["contexto"]["escada"]["Recanto"]["reveillon"], 1)
        self.assertIn("reservas lidas", res2["contexto"]["receita_origem"])
        # --receita explícita vale
        res3 = R.montar_plano(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), d24, rs2[:1], 40000.0)
        self.assertTrue(res3["contexto"]["pausa"])
        # sem reservas e sem receita, a partir de 23/11: parada, com aviso
        res4 = R.montar_plano(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), d24, None)
        self.assertTrue(res4["contexto"]["pausa"])
        self.assertTrue(any("sem a receita" in m for m in res4["avisos"]), res4["avisos"])
        # antes de 23/11 nada muda (o plano de hoje)
        self.assertFalse(R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE, None)
                         ["contexto"]["pausa"])

    def test_r3_14_regra_sem_estadia_e_regra_com_validade(self):
        regras = regras_hoje()
        for n in ("2026-12-30", "2027-01-01"):
            regras["Q7"][n].pop("min_stay")
        af = regras["Afrodite"]["2027-01-01"]
        for k in ("min_stay", "check_in_check_out_enabled", "check_in", "check_out"):
            af.pop(k)
        res = R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras, HOJE, [])
        it = por(res)
        self.assertEqual((it[("Q7", "2026-12-30")]["min_stay"], it[("Q7", "2027-01-01")]["min_stay"]), (2, 3))
        self.assertEqual((it[("Afrodite", "2027-01-01")]["check_in"], it[("Afrodite", "2027-01-01")]["check_out"]),
                         ("1111011", "1111111"))
        self.assertEqual(validar(res["itens"]).erros, [])
        # a regra com a máscara e sem a estadia: o item leva a estadia; a máscara fica a da regra
        regras2 = regras_hoje()
        regras2["Afrodite"]["2027-01-02"].pop("min_stay")
        it2 = por(R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras2, HOJE, []))[("Afrodite", "2027-01-02")]
        self.assertEqual(it2["min_stay"], 3)
        self.assertNotIn("check_in", it2)
        # exceção 2 aplicada na config: a estadia que o item põe é a da config (2), não a de antes do portão
        cfg = copy.deepcopy(CFG)
        cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"] = {"2027-01-02": {"Q7": "noCheckOut"}}
        cfg["parametros"]["regra_h_beds24"]["estadia_minima_por_data_por_quarto"] = {"2027-01-02": {"Q7": 2}}
        regras3 = regras_hoje()
        regras3["Q7"]["2027-01-02"].pop("min_stay")
        res3 = R.montar_plano(cfg, ORDEM, estado_hoje(), inv_hoje(), regras3, HOJE, [])
        self.assertEqual(por(res3)[("Q7", "2027-01-02")]["min_stay"], 2)
        v = Validador(cfg, HOJE, {}, ordens=ORDENS, estados={R.ORDEM_ID: estado_hoje()})
        v.validar(copy.deepcopy(res3["itens"]))
        self.assertEqual(v.erros, [])
        # regra com validade (lead_time_expiry): ERRO no plano (apagar é barrado nessas noites)
        regras4 = regras_hoje()
        regras4["VK7"]["2026-12-31"]["lead_time_expiry"] = 3
        with self.assertRaises(ValueError) as cm:
            R.montar_plano(CFG, ORDEM, estado_hoje(), inv_hoje(), regras4, HOJE, [])
        self.assertIn("lead_time_expiry", str(cm.exception))


class TestR3PortaoEExcecoes(unittest.TestCase):
    def test_r3_03_portao_espera_o_ok_do_dono(self):
        ok = ORDEM_PENDENTE["execucao_skill"]["ok_01_04_05"]
        self.assertEqual((ok["resposta"], ok["texto_dono"], ok["respondido_em"]), (None, None, None))   # pendente
        # a ordem real tem a resposta literal do dono (16/11): "aceita"
        real = ORDEM["execucao_skill"]["ok_01_04_05"]
        self.assertEqual(real["resposta"], "aceita")
        self.assertEqual(real["texto_dono"], "Pode de 1 a 4 e de 1 a 5, sem problema.")
        est, regras, inv = preparar_portao()
        av = R.avaliar_portao(CFG, ORDEM_PENDENTE, est, inv, regras, ENVIO_18, {"Recanto": 2560}, hoje=D18)
        self.assertFalse(av["Recanto"]["pode"])
        self.assertEqual(len(av["Recanto"]["faltam"]), 1, av["Recanto"]["faltam"])      # as 3 da ordem passaram
        self.assertTrue(av["Recanto"]["faltam"][0].startswith("ok do dono para 01→04/01→05"))
        saida, linhas = R.decidir_portao(CFG, ORDEM_PENDENTE, est, inv, regras, ENVIO_18, {"Recanto": 2560}, D18, [])
        self.assertTrue(any(x.startswith("  falta: ok do dono para 01→04/01→05") for x in linhas), linhas)
        self.assertEqual((saida["itens"], saida["lote_beds24"], saida["patch_quartos"]), ([], [], {}))
        # com o ok registrado (cópia de teste), abre; a prova leva a resposta
        av2 = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2560}, hoje=D18)
        self.assertTrue(av2["Recanto"]["pode"], av2["Recanto"]["faltam"])
        self.assertEqual(av2["Recanto"]["prova"]["ok_01_04_05"]["resposta"], "aceita")
        # o validador barra a prova do portão enquanto a ordem não tem o ok
        ac = R.acoes_portao(CFG, ORDEM_PENDENTE, inv, regras, "Recanto", av2["Recanto"]["prova"], D18)
        self.assertTrue(any("ok do dono para 01→04/01→05" in e
                            for e in validar(ac["itens_pricelabs"], D18, ordens=ORDENS_PENDENTE).erros))
        self.assertEqual(validar(ac["itens_pricelabs"], D18, ordens=ORDENS_OK).erros, [])
        # recusa registrada, ou resposta sem o texto literal: fechado
        rec = copy.deepcopy(ORDEM_PENDENTE)
        rec["execucao_skill"]["ok_01_04_05"].update(resposta="recusa", texto_dono="(teste)", respondido_em="2026-11-17")
        av3 = R.avaliar_portao(CFG, rec, est, inv, regras, ENVIO_18, {"Recanto": 2560}, hoje=D18)
        self.assertTrue(any("recusou" in f for f in av3["Recanto"]["faltam"]))
        sem_texto = copy.deepcopy(ORDEM_OK)
        sem_texto["execucao_skill"]["ok_01_04_05"]["texto_dono"] = None
        self.assertFalse(R.avaliar_portao(CFG, sem_texto, est, inv, regras, ENVIO_18, {"Recanto": 2560},
                                          hoje=D18)["Recanto"]["pode"])

    def test_r3_06_orfas_sem_aplicar_config(self):
        import json
        import tempfile
        from pathlib import Path
        from unittest import mock
        inv = inv_hoje()
        inv["Balcony"]["2026-12-31"]["numAvail"] = 0              # exceção 1 abre: há patch
        with tempfile.TemporaryDirectory() as d:
            dd = Path(d)
            regs = _arquivos(dd, inv)
            quartos = dd / "quartos.json"
            quartos.write_text("{}", encoding="utf-8")
            args = ["orfas", "--hoje", "2026-11-16", "--replay", "--inventario", str(dd / "inv.json"), "--regras", *regs,
                    "--saida", str(dd / "orfas.json"), "--lote-beds24", str(dd / "lote.json")]
            with mock.patch.object(R, "ARQ_QUARTOS", quartos):
                self.assertEqual(R.main(args + ["--aplicar-config"]), 1)
                self.assertEqual(quartos.read_text(encoding="utf-8"), "{}")    # nada gravado na config
                self.assertEqual(R.main(args), 0)
                self.assertEqual(quartos.read_text(encoding="utf-8"), "{}")
            saida = json.loads((dd / "orfas.json").read_text(encoding="utf-8"))
            self.assertTrue(saida["patch_quartos"])
            self.assertEqual(saida["lote_beds24"], json.loads((dd / "lote.json").read_text(encoding="utf-8")))

    def test_r3_07_trava_de_inventario_no_lote_das_excecoes(self):
        inv = inv_hoje()
        inv["Balcony"]["2026-12-31"]["numAvail"] = 0
        inv["Q7"]["2027-01-01"]["numAvail"] = 0
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        abre1 = [i for i in r["lote_beds24"] if i["quarto"] == "Balcony"]
        self.assertEqual([i["vizinha_esgotada"] for i in abre1], [{"data": "2026-12-31", "numAvail": 0}])
        abre2 = [i for i in r["lote_beds24"] if i["quarto"] == "Q7"]
        self.assertEqual(len(abre2), 2)
        self.assertTrue(all(i["vizinha_esgotada"] == {"data": "2027-01-01", "numAvail": 0} for i in abre2))
        # o fechamento (volta ao pacote) não leva trava: fechar é sempre seguro
        cfg = copy.deepcopy(CFG)
        cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"] = {"2026-12-31": {"Balcony": "noCheckIn"}}
        fech = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv_hoje(), regras_hoje(), HOJE)
        self.assertFalse([i for i in fech["lote_beds24"] if i.get("vizinha_esgotada")])

    def test_r3_11_orfas_e_portao_na_mesma_rotina(self):
        import beds24
        est, regras, inv = preparar_portao()
        cfg = copy.deepcopy(CFG)
        rh = cfg["parametros"]["regra_h_beds24"]
        rh["override_por_data_por_quarto"] = {"2027-01-02": {"Q7": "noCheckOut"}}     # exceção 2 aplicada no Q7
        rh["estadia_minima_por_data_por_quarto"] = {"2027-01-02": {"Q7": 2}}
        # 01/01 do Q7 com vaga (reabriu): a exceção 2 fecha; o portão do Recanto abre na mesma rotina
        portao, _ = R.decidir_portao(cfg, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2560}, D18, [])
        self.assertTrue(portao["portao"]["Recanto"]["pode"])
        sem = R.avaliar_orfas(cfg, ORDEM, est, inv, regras, D18)
        self.assertEqual(sem["quartos"]["Q7"]["acao_2"], "fechar")
        with self.assertRaises(beds24.ErroBeds24):           # sem o portão: 3 contra 2, o editar-lote recusa os dois
            beds24.juntar_planos([("lote_orfas", sem["lote_beds24"]), ("lote_portao", portao["lote_beds24"])])
        com = R.avaliar_orfas(cfg, ORDEM, est, inv, regras, D18, portao)
        self.assertEqual([i["min_stay"] for i in com["lote_beds24"] if i.get("min_stay") is not None], [2])
        lote, _ = beds24.juntar_planos([("lote_orfas", com["lote_beds24"]), ("lote_portao", portao["lote_beds24"])])
        self.assertEqual([i["min_stay"] for i in com["itens_pricelabs"]], [2])
        # aplicar-config: as exceções antes do portão; a estadia 2 de 02/01 fica nos 4 quartos do Recanto
        orfas = {"hoje": D18.isoformat(), "itens": com["itens_pricelabs"], "lote_beds24": com["lote_beds24"],
                 "patch_quartos": com["patch_quartos"]}
        bruto = copy.deepcopy({k: v for k, v in cfg.items() if k not in ("por_curto", "por_id")})
        feitas, recusas = R.aplicar_config(bruto, copy.deepcopy(est), D18, portao=portao, orfas=orfas,
                                           resumo_beds24=resumo_ok(lote),
                                           gravado_pl=gravado_de(com["itens_pricelabs"] + portao["itens"]))
        self.assertEqual(recusas, [])
        self.assertEqual(len(feitas), 2)
        rh2 = bruto["parametros"]["regra_h_beds24"]
        self.assertEqual(rh2["estadia_minima_por_data_por_quarto"]["2027-01-02"],
                         {"Q7": 2, "Q2": 2, "Double": 2, "Afrodite": 2})
        self.assertNotIn("2027-01-02", rh2["override_por_data_por_quarto"])
        # --portao de outro dia: recusado
        with self.assertRaises(ValueError):
            R.avaliar_orfas(cfg, ORDEM, est, inv, regras, date(2026, 11, 19), portao)

    def test_r3_12_aplicar_config_so_com_o_beds24_e_o_pricelabs_conferidos(self):
        est, regras, inv = preparar_portao()
        saida, _ = R.decidir_portao(CFG, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2560}, D18, [])
        lote = saida["lote_beds24"]
        bruto = copy.deepcopy({k: v for k, v in CFG.items() if k not in ("por_curto", "por_id")})

        def aplicar(**kw):
            b, e = copy.deepcopy(bruto), copy.deepcopy(est)
            f, r = R.aplicar_config(b, e, D18, portao=saida, **kw)
            return f, r, b
        f, r, _ = aplicar()
        self.assertEqual(f, [])
        self.assertTrue(any("resumo do editar-lote" in x for x in r), r)
        for ruim, txt in ((dict(resumo_ok(lote), erros=["Q7 2027-01-01: override noCheckIn (esperado none)"]),
                           "voltou com erro"), (dict(resumo_ok(lote), aplicar=False), "simulação"),
                          (dict(resumo_ok(lote), parou_no_bloco=1, resposta_ok=False), "voltou com erro"),
                          (dict(resumo_ok(lote), itens=2), "outro lote"),
                          (dict(resumo_ok(lote), conferidos=3), "conferiu")):
            f, r, _ = aplicar(resumo_beds24=ruim, gravado_pl=gravado_de(saida["itens"]))
            self.assertEqual(f, [], txt)
            self.assertTrue(any(txt in x for x in r), (txt, r))
        # item do lote pulado (guarda): o portão do Recanto não vai à config
        pul = dict(resumo_ok(lote), pulados=[{"quarto": "Q2", "de": "2027-01-02", "ate": "2027-01-02",
                                              "por_que": "minStay mudou desde o plano: refaça com o inventário novo"}])
        f, r, b = aplicar(resumo_beds24=pul, gravado_pl=gravado_de(saida["itens"]))
        self.assertEqual(f, [])
        self.assertTrue(any("portão Recanto: não aplicado" in x for x in r), r)
        self.assertNotIn("override_por_data_por_quarto", b["parametros"]["regra_h_beds24"])
        # PriceLabs sem a resposta da gravação, ou com a máscara da sexta não gravada
        f, r, _ = aplicar(resumo_beds24=resumo_ok(lote))
        self.assertEqual(f, [])
        self.assertTrue(any("PriceLabs" in x for x in r), r)
        g = gravado_de(saida["itens"])
        g["Afrodite"]["2027-01-01"]["check_in"] = "1111011"
        f, r, _ = aplicar(resumo_beds24=resumo_ok(lote), gravado_pl=g)
        self.assertEqual(f, [])
        self.assertTrue(any("Afrodite" in x and "check_in" in x for x in r), r)
        # "já no alvo" conta como gravado; tudo conferido: aplica
        alvo = dict(resumo_ok(lote), pulados=[{"quarto": "Q2", "de": "2027-01-02", "ate": "2027-01-02",
                                               "por_que": "já no alvo"}])
        f, r, _ = aplicar(resumo_beds24=alvo, gravado_pl=gravado_de(saida["itens"]))
        self.assertEqual((f, r), (["portão do 01→03 aberto: Recanto"], []))
        # exceções: o quarto com o item pulado (a vizinha reabriu) fica fora do patch; o outro entra
        inv2 = inv_hoje()
        inv2["Balcony"]["2026-12-31"]["numAvail"] = 0
        inv2["VK7"]["2026-12-31"]["numAvail"] = 0
        orf = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv2, regras_hoje(), HOJE)
        so = {"hoje": "2026-11-16", "itens": orf["itens_pricelabs"], "lote_beds24": orf["lote_beds24"],
              "patch_quartos": orf["patch_quartos"]}
        resumo = dict(resumo_ok(orf["lote_beds24"]), a_gravar=1, conferidos=1,
                      pulados=[{"quarto": "VK7", "de": "2026-12-31", "ate": "2026-12-31",
                                "por_que": "a noite vizinha 2026-12-31 não está mais esgotada (numAvail 1)"}])
        b2 = copy.deepcopy(bruto)
        f, r = R.aplicar_config(b2, estado_hoje(), HOJE, orfas=so, resumo_beds24=resumo, gravado_pl={})
        self.assertEqual(f, ["exceções das noites soltas: Balcony"])
        self.assertTrue(any(x.startswith("VK7:") for x in r), r)
        self.assertEqual(b2["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"]["2026-12-31"],
                         {"Balcony": "noCheckIn"})

    def test_r3_12_aplicar_config_pela_linha_de_comando(self):
        import json
        import tempfile
        from pathlib import Path
        from unittest import mock
        est, regras, inv = preparar_portao()
        saida, _ = R.decidir_portao(CFG, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2560}, D18, [])
        bruto = {k: v for k, v in CFG.items() if k not in ("por_curto", "por_id")}
        with tempfile.TemporaryDirectory() as d:
            dd = Path(d)
            (dd / "quartos.json").write_text(json.dumps(bruto, ensure_ascii=False), encoding="utf-8")
            (dd / "estado.json").write_text(json.dumps(est, ensure_ascii=False), encoding="utf-8")
            (dd / "portao.json").write_text(json.dumps(saida, ensure_ascii=False), encoding="utf-8")
            (dd / "resumo.json").write_text(json.dumps(resumo_ok(saida["lote_beds24"])), encoding="utf-8")
            pls = []
            for q, regs in gravado_de(saida["itens"]).items():    # a resposta da gravação, como o PriceLabs devolve
                (dd / f"grav_{q}.json").write_text(json.dumps({"data": {"overrides": list(regs.values())}}),
                                                   encoding="utf-8")
                pls.append(f"{q}={dd / f'grav_{q}.json'}")
            base = ["aplicar-config", "--hoje", "2026-11-18", "--portao", str(dd / "portao.json"), "--estado",
                    str(dd / "estado.json")]
            with mock.patch.object(R, "ARQ_QUARTOS", dd / "quartos.json"):
                self.assertEqual(R.main(base), 1)                                  # sem o resumo do Beds24
                self.assertEqual(json.loads((dd / "quartos.json").read_text(encoding="utf-8")), bruto)
                self.assertEqual(R.main(base + ["--resumo-beds24", str(dd / "resumo.json"), "--pricelabs", *pls]), 0)
            cfg = json.loads((dd / "quartos.json").read_text(encoding="utf-8"))
            self.assertEqual(cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"]["2027-01-01"],
                             {"Q7": "none", "Q2": "none", "Double": "none", "Afrodite": "none"})
            self.assertTrue(json.loads((dd / "estado.json").read_text(encoding="utf-8"))["portao"]["Recanto"]["aberto"])

    def test_r3_13_excecao_so_com_a_vespera_para_todas_as_noites_soltas(self):
        inv = inv_hoje()
        inv["Q7"]["2026-12-31"]["numAvail"] = 0          # Q7: 3 noites soltas em 30/12
        inv["Q7"]["2026-12-29"]["numAvail"] = 2          # 29/12 com 2: a 3ª venda 29→31 isolaria 30/12
        r = R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        x = r["quartos"]["Q7"]
        self.assertEqual(x["excecao_1_ota"], "fechada")
        self.assertIn("isolariam", x["excecao_1_por_que"])
        self.assertEqual(x["venda_direta_29_31"], 2)                    # a venda direta segue
        inv["Q7"]["2026-12-29"]["numAvail"] = 3
        self.assertEqual(R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)["quartos"]["Q7"]
                         ["excecao_1_ota"], "abre")
        # aplicada: 29/12 cai abaixo das noites soltas, fecha; 29/12 esgota, fecha com URGENTE (janela do dia órfão)
        cfg = copy.deepcopy(CFG)
        cfg["parametros"]["regra_h_beds24"]["override_por_data_por_quarto"] = {"2026-12-31": {"Q7": "noCheckIn"}}
        inv["Q7"]["2026-12-29"]["numAvail"] = 2
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertEqual((r["quartos"]["Q7"]["acao_1"], r["quartos"]["Q7"].get("urgente")), ("fechar", None))
        inv["Q7"]["2026-12-29"]["numAvail"] = 0
        r = R.avaliar_orfas(cfg, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)
        self.assertEqual(r["quartos"]["Q7"]["acao_1"], "fechar")
        self.assertTrue(r["quartos"]["Q7"]["urgente"])
        self.assertTrue(any(m.startswith("URGENTE Q7") for m in r["avisos"]), r["avisos"])
        # exceção 2: 02/01 com 6 soltas e 03/01 com 5: fechada; com 6, abre
        inv = inv_hoje()
        inv["Q7"]["2027-01-01"]["numAvail"] = 0
        inv["Q7"]["2027-01-03"]["numAvail"] = 5
        self.assertEqual(R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)["quartos"]["Q7"]
                         ["excecao_2_ota"], "fechada")
        inv["Q7"]["2027-01-03"]["numAvail"] = 6
        self.assertEqual(R.avaliar_orfas(CFG, ORDEM, estado_hoje(), inv, regras_hoje(), HOJE)["quartos"]["Q7"]
                         ["excecao_2_ota"], "abre")

    def test_r3_15_portao_nao_avaliado_e_quarto_de_referencia(self):
        import json
        import tempfile
        from pathlib import Path
        # (a) rotina sem o envio e o público do dia: a saída e o lote do portão saem vazios e os passos seguintes iguais
        with tempfile.TemporaryDirectory() as d:
            dd = Path(d)
            regs = _arquivos(dd, inv_hoje())
            (dd / "lote_portao.json").write_text('[{"velho": true}]', encoding="utf-8")
            args = ["portao", "--hoje", "2026-11-16", "--replay", "--inventario", str(dd / "inv.json"), "--regras", *regs,
                    "--nao-avaliar", "--saida", str(dd / "portao.json"), "--lote-beds24", str(dd / "lote_portao.json")]
            self.assertEqual(R.main(args), 0)
            self.assertEqual(json.loads((dd / "lote_portao.json").read_text(encoding="utf-8")), [])
            pt = json.loads((dd / "portao.json").read_text(encoding="utf-8"))
            self.assertEqual((pt["itens"], pt["patch_quartos"], pt["nao_avaliado"]), ([], {}, True))
            (dd / "plano.json").write_text('{"hoje": "2026-11-16", "itens": []}', encoding="utf-8")
            orf = ["orfas", "--hoje", "2026-11-16", "--replay", "--inventario", str(dd / "inv.json"), "--regras", *regs,
                   "--portao", str(dd / "portao.json"), "--saida", str(dd / "orfas.json")]
            self.assertEqual(R.main(orf), 0)
            self.assertEqual(R.main(["juntar", "--plano", str(dd / "plano.json"), str(dd / "orfas.json"),
                                     str(dd / "portao.json"), "--saida", str(dd / "unico.json")]), 0)
        # (b) o público vem do 30→03: o quarto de referência é o mais barato livre nas 4 noites
        est, regras, inv = preparar_portao()
        inv["Q7"]["2026-12-30"]["numAvail"] = 0         # a Moinhos (a mais barata) sem vaga em 30/12
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2596}, hoje=D18)
        self.assertEqual(av["Recanto"]["prova"]["quarto_ref"], "Q2")
        self.assertTrue(av["Recanto"]["pode"], av["Recanto"]["faltam"])
        # com o quarto que a Booking mostrou (--publico Recanto=<R$>@Q7)
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2552}, hoje=D18,
                              quarto_publico={"Recanto": "Q7"})
        self.assertEqual((av["Recanto"]["prova"]["quarto_ref"], av["Recanto"]["pode"]), ("Q7", True))
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2552}, hoje=D18,
                              quarto_publico={"Recanto": "VK7"})
        self.assertTrue(any("quarto do público medido" in f for f in av["Recanto"]["faltam"]))
        # nenhum quarto livre nas 4 noites: pede o quarto que a Booking mostrou
        for q in R.POUSADAS["Recanto"]:
            inv[q]["2026-12-31"]["numAvail"] = 0
        av = R.avaliar_portao(CFG, ORDEM_OK, est, inv, regras, ENVIO_18, {"Recanto": 2552}, hoje=D18)
        self.assertTrue(any("@<quarto>" in f for f in av["Recanto"]["faltam"]), av["Recanto"]["faltam"])


if __name__ == "__main__":
    unittest.main()

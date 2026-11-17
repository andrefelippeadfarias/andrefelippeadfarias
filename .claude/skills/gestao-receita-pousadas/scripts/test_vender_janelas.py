"""Testes da regra "vender as janelas" (dono 16/11/2026 ~20:05 BRT: "Na verdade só venda onde tem uma janela. A
preocupação é onde temos janelas, temos que vender as janelas."; ordem dono-2026-11-16-vender-janelas). Rodar na pasta
scripts/: python3 -m unittest test_vender_janelas

Tudo sintético e offline: inventários do Beds24 e recálculos do PriceLabs inventados (sem rede, sem nomes de hóspedes),
de 29/11 a 22/12/2026 (fora de 19-23/11, que o config_de_teste recongela, e antes dos pacotes do Natal), com hoje =
segunda 30/11. A config é a de config_de_teste (sem a operação do Réveillon) com `parametros.vender_janelas` posto aqui;
as ordens dos testes puros são só a ordem das janelas (mais a de cada caso de exclusão).

Cenários (o quarto e as livres de cada noite; o resto do quarto com livres iguais em todas as noites, que nunca prendem):
  A  Q7  segunda 07/12 6 livres, terça 5, domingo 06/12 esgotado: 1 presa (a estadia 2 obriga a passar pela terça);
     abre com estadia 1 na segunda (a), sem trava (domingo a quinta);
  B  VK2 sábado 12/12 2 livres, sexta 1, domingo e quinta esgotados: 1 presa; (b1) não vende (sáb→seg passa pelo
     domingo esgotado), (b2) entrada no sábado e estadia 1; trava: sexta 1 <= presas 1;
  C  VK2 sábado 12/12 2 livres, sexta 1, domingo 2, quinta esgotada: (b1) entrada no sábado com estadia 2 basta;
  D  VK7 sexta 11/12 5 livres, sábado 1, quinta esgotada: 4 presas; (c) sábado noCheckIn e estadia 1 na sexta;
  E  VK7 sexta 11/12 6 livres, sábado 5, quinta esgotada: 1 presa < 5 livres do sábado: "a observar"."""

from __future__ import annotations

import contextlib
import copy
import datetime as dt
import io
import json
import os
import random
import sys
import tempfile
import unittest

AQUI = os.path.dirname(os.path.abspath(__file__))
if AQUI not in sys.path:
    sys.path.insert(0, AQUI)

import beds24  # noqa: E402
import calendario_12m as c12  # noqa: E402
import config_de_teste as T  # noqa: E402
import pace  # noqa: E402
import vender_janelas as VJ  # noqa: E402
from validar_plano import Validador, montar_payload  # noqa: E402

D = dt.date
UM = dt.timedelta(days=1)
HOJE = D(2026, 11, 30)                     # segunda
INI, FIM = D(2026, 11, 29), D(2026, 12, 22)
LIDO = "2026-11-30T10:40:00Z"              # 07:40 BRT, a leitura da rotina
F = "noCheckInOrCheckOut"
MS = {0: 2, 1: 1, 2: 2, 3: 1, 4: 2, 5: 2, 6: 1}   # estadia pelo dia de chegada (dono 04/11)
ORDEM_ID = "dono-2026-11-16-vender-janelas"
ORDEM = {"id": ORDEM_ID, "data": "2026-11-16", "criada_em": "2026-11-16T23:05:00Z", "tipo": "regra",
         "quartos": ["Q7", "Double", "Afrodite", "Q2", "VK7", "Balcony", "VK2"], "de": "2026-11-16",
         "ate": "2027-11-16", "libera": ["janela"], "texto": "Na verdade só venda onde tem uma janela."}
PAR = {"ativa": True, "ordem_id": ORDEM_ID, "desde": "2026-11-16", "horizonte_dias": 60, "estadia_maxima_simulada": 7,
       "trava_fragmentacao": True, "quartos_so_estadia": ["Afrodite"], "sabado_entrada": "noCheckOut",
       "sabado_saida": "noCheckIn", "noites_fora": {}}


def cfg(**par) -> dict:
    c = copy.deepcopy(T.carregar())
    c["parametros"]["vender_janelas"] = dict(PAR, **par)
    return c


def ordens(*extra) -> dict:
    out = {ORDEM_ID: copy.deepcopy(ORDEM)}
    for o in extra:
        out[o["id"]] = o
    return out


def ordens_reais() -> dict:
    """As ordens do arquivo (com a das janelas), com a janela de 19-23/11 fixa (config_de_teste)."""
    return T.carregar_ordens()


def ds(d: D) -> str:
    return d.isoformat()


def quarto(livres: int, muda: dict | None = None, de: D = INI, ate: D = FIM) -> dict:
    """Inventário de um quarto: `livres` em todas as noites, estadia pelo dia de chegada, sábado noCheckInOrCheckOut
    (a regra), máxima 7; `muda` {data: {campo: valor}} por cima."""
    out, d = {}, de
    while d <= ate:
        out[ds(d)] = {"numAvail": livres, "minStay": MS[d.weekday()], "maxStay": 7,
                      "override": F if d.weekday() == 5 else "none"}
        d += UM
    for k, v in (muda or {}).items():
        out[k] = dict(out.get(k) or {"numAvail": livres, "minStay": 1, "maxStay": 7, "override": "none"}, **v)
    return out


# os cenários do cabeçalho
def cen_a(**mais):
    return quarto(3, dict({"2026-12-06": {"numAvail": 0}, "2026-12-07": {"numAvail": 6}, "2026-12-08": {"numAvail": 5}},
                          **mais))


def cen_b(**mais):
    return quarto(2, dict({"2026-12-10": {"numAvail": 0}, "2026-12-11": {"numAvail": 1}, "2026-12-12": {"numAvail": 2},
                           "2026-12-13": {"numAvail": 0}}, **mais))


def cen_c(**mais):
    return quarto(2, dict({"2026-12-10": {"numAvail": 0}, "2026-12-11": {"numAvail": 1}, "2026-12-12": {"numAvail": 2},
                           "2026-12-13": {"numAvail": 2}}, **mais))


def cen_d(**mais):
    return quarto(3, dict({"2026-12-10": {"numAvail": 0}, "2026-12-11": {"numAvail": 5}, "2026-12-12": {"numAvail": 1}},
                          **mais))


def cen_e(**mais):
    return quarto(6, dict({"2026-12-10": {"numAvail": 0}, "2026-12-11": {"numAvail": 6}, "2026-12-12": {"numAvail": 5}},
                          **mais))


def janelas(inv: dict, c=None, ords=None, hoje=HOJE) -> dict:
    return c12.calcular_janelas(c or cfg(), inv, hoje, LIDO, ords if ords is not None else ordens(), FIM)


def classif(inv: dict, jan: dict | None, c=None, hoje=HOJE) -> dict:
    return {x["quarto"]: x for x in c12.classificar_beds24(c or cfg(), inv, hoje, FIM, hoje=hoje, lido_em=LIDO,
                                                            janelas=(jan or {}).get("alvos") if jan is not None else None)}


def recalculo(inv_q: dict, ms_ef: dict | None = None) -> dict:
    """Recálculo do PriceLabs (preço e piso altos: o plano completo não mexe no piso) com a estadia da regra do dia
    (ou `ms_ef`) e a ocupação do inventário."""
    out = {}
    for k in inv_q:
        d = D.fromisoformat(k)
        out[k] = {"min_price": "5000", "price": "5000", "min_stay": str((ms_ef or {}).get(k, MS[d.weekday()])),
                  "mu_occupancy": "1/7"}
    return out


def erros(itens, c=None, hoje=HOJE, precos=None, ords=None):
    v = Validador(c or cfg(), hoje, dict(precos or {}), ordens=ords if ords is not None else ordens_reais())
    v.validar(itens)
    return v.erros


def item_janela(inv_q: dict, q: str, noite: str, c=None, so_estadia=True, ords=None) -> dict:
    """O item do PriceLabs que o comparar dá para a noite da janela (recálculo com a estadia da regra)."""
    c = c or cfg()
    jan = janelas({q: inv_q}, c, ords)
    dif = c12.comparar(c, q, recalculo(inv_q), D.fromisoformat(noite), D.fromisoformat(noite), ords or ordens(),
                       so_estadia=so_estadia, inv=inv_q, lido_em=LIDO, hoje=HOJE, janelas=jan["alvos"].get(q))
    it = next(x for x in dif if x.get("item"))
    return dict(it["item"], tipo="data", quarto=q, data=noite, motivo="Janela (teste)")


# ---------------------------------------------------------------------------------------------------------------------
class TestEstadiaValidaEVendavel(unittest.TestCase):
    """1 e 2: a estadia válida no Beds24 (stayThrough) e o vendável da noite."""

    def test_stay_through_e_restricoes(self):
        r = quarto(2)
        seg, ter, sex, sab, dom = D(2026, 12, 7), D(2026, 12, 8), D(2026, 12, 11), D(2026, 12, 12), D(2026, 12, 13)
        self.assertFalse(VJ.estadia_valida(r, seg, ter, HOJE))                 # segunda pede 2
        self.assertTrue(VJ.estadia_valida(r, seg, seg + 2 * UM, HOJE))
        self.assertTrue(VJ.estadia_valida(r, ter, ter + UM, HOJE))             # terça 1
        # stayThrough: a estadia 2 da quarta vale para quem chega na terça e passa por ela
        r2 = quarto(2, {"2026-12-09": {"minStay": 3}})
        self.assertFalse(VJ.estadia_valida(r2, ter, ter + 2 * UM, HOJE))
        self.assertTrue(VJ.estadia_valida(r2, ter, ter + 3 * UM, HOJE))
        # máxima de qualquer noite coberta
        r3 = quarto(2, {"2026-12-09": {"maxStay": 1}})
        self.assertFalse(VJ.estadia_valida(r3, ter, ter + 2 * UM, HOJE))
        # chegada fechada (noCheckIn, noCheckInOrCheckOut, blackout) e saída fechada (noCheckOut, noCheckInOrCheckOut)
        for ov in ("noCheckIn", F, "blackout"):
            self.assertFalse(VJ.estadia_valida(quarto(2, {ds(ter): {"override": ov}}), ter, ter + UM, HOJE), ov)
        self.assertTrue(VJ.estadia_valida(quarto(2, {ds(ter): {"override": "noCheckOut"}}), ter, ter + UM, HOJE))
        self.assertFalse(VJ.estadia_valida(r, sex, sab, HOJE))                 # saída no sábado fechada (regra)
        self.assertTrue(VJ.estadia_valida(quarto(2, {ds(sab): {"override": "noCheckIn"}}), sex, sab,
                                          HOJE) is False)                       # sexta pede 2
        self.assertTrue(VJ.estadia_valida(quarto(2, {ds(sab): {"override": "noCheckIn"}, ds(sex): {"minStay": 1}}),
                                          sex, sab, HOJE))
        self.assertTrue(VJ.estadia_valida(quarto(2, {ds(dom): {"override": "noCheckIn"}}), sex, dom, HOJE))
        self.assertFalse(VJ.estadia_valida(quarto(2, {ds(dom): {"override": "noCheckOut"}}), sex, dom, HOJE))
        # noite esgotada ou em blackout no meio
        self.assertFalse(VJ.estadia_valida(quarto(2, {ds(sab): {"numAvail": 0}}), sex, dom, HOJE))
        self.assertFalse(VJ.estadia_valida(quarto(2, {ds(sab): {"override": "blackout"}}), sex, dom, HOJE))
        # chegada antes de hoje e mais de 7 noites
        self.assertFalse(VJ.estadia_valida(r, HOJE - UM, HOJE + UM, HOJE))
        self.assertFalse(VJ.estadia_valida(r, ter, ter + 8 * UM, HOJE))
        self.assertTrue(VJ.estadia_valida(r, ter, ter + 7 * UM, HOJE))

    def test_vendavel_e_presas(self):
        a = VJ.presas_da_noite(cen_a(), D(2026, 12, 7), HOJE)
        self.assertEqual((a["livres"], a["vendavel"], a["presas"]), (6, 5, 1))
        self.assertEqual(a["exemplo"], ["07/12->09/12 x5"])
        d = VJ.presas_da_noite(cen_d(), D(2026, 12, 11), HOJE)
        self.assertEqual((d["livres"], d["vendavel"], d["presas"]), (5, 1, 4))
        # intervalos mínimos: só os que não contêm outro válido que cubra a noite
        mins = VJ.estadias_minimas(cen_a(), D(2026, 12, 7), HOJE)
        self.assertEqual(mins, [(D(2026, 12, 7), D(2026, 12, 9))])
        # capacidade dividida: duas noites de chegada disputando a mesma noite de 1 livre
        r = quarto(2, {"2026-12-14": {"numAvail": 2, "minStay": 1}, "2026-12-15": {"numAvail": 1, "minStay": 2},
                       "2026-12-16": {"numAvail": 2, "minStay": 1}, "2026-12-13": {"numAvail": 0},
                       "2026-12-17": {"numAvail": 0}})
        # segunda 14: chegada 14→16 (passa pela terça de 1) ou 14→15 (1 noite); terça pede 2 por quem passa nela
        self.assertEqual(VJ.presas_da_noite(r, D(2026, 12, 14), HOJE)["presas"], 0)
        self.assertEqual(VJ.presas_da_noite(r, D(2026, 12, 15), HOJE)["vendavel"], 1)
        # noite esgotada ou em blackout: nada preso
        self.assertEqual(VJ.presas_da_noite(cen_a(), D(2026, 12, 6), HOJE)["presas"], 0)
        self.assertEqual(VJ.presas_da_noite(quarto(2, {"2026-12-08": {"override": "blackout"}}), D(2026, 12, 8),
                                            HOJE)["presas"], 0)

    def test_cobertura(self):
        r = quarto(2, de=INI, ate=D(2026, 12, 8))         # rotina leve: até hoje + 8
        self.assertTrue(VJ.coberta(r, HOJE, HOJE))
        self.assertTrue(VJ.coberta(r, HOJE + UM, HOJE))
        self.assertFalse(VJ.coberta(r, HOJE + 2 * UM, HOJE))
        esparsa = {k: v for k, v in quarto(2).items() if D.fromisoformat(k).day % 2}
        self.assertFalse(any(VJ.coberta(esparsa, D(2026, 12, d), HOJE) for d in range(1, 16)))


class TestCandidatos(unittest.TestCase):
    """3: as aberturas mínimas, na ordem de menor desvio, sem no-op."""

    def test_ordem_e_formas(self):
        c = cfg()
        r = quarto(2)
        self.assertEqual([x["codigo"] for x in VJ.candidatos(c, "VK7", D(2026, 12, 7), r)], ["a"])      # segunda
        self.assertEqual([x["codigo"] for x in VJ.candidatos(c, "VK7", D(2026, 12, 11), r)], ["a", "c"])  # sexta
        self.assertEqual([x["codigo"] for x in VJ.candidatos(c, "VK7", D(2026, 12, 12), r)], ["b1", "b2"])  # sábado
        cc = VJ.candidatos(c, "VK7", D(2026, 12, 11), r)[1]
        self.assertEqual(cc["mudancas"], {"2026-12-11": {"minStay": [2, 1]}, "2026-12-12": {"override": [F, "noCheckIn"]}})
        b2 = VJ.candidatos(c, "VK7", D(2026, 12, 12), r)[1]
        self.assertEqual(b2["mudancas"], {"2026-12-12": {"override": [F, "noCheckOut"], "minStay": [2, 1]}})
        # sem no-op: terça já é 1; sábado com estadia 1 não tem b2; sábado que não está fechado na regra não tem (b)
        self.assertEqual(VJ.candidatos(c, "VK7", D(2026, 12, 8), r), [])
        self.assertEqual([x["codigo"] for x in VJ.candidatos(c, "VK7", D(2026, 12, 12),
                                                             quarto(2, {"2026-12-12": {"minStay": 1}}))], ["b1"])
        self.assertEqual(VJ.candidatos(c, "VK7", D(2026, 12, 12), quarto(2, {"2026-12-12": {"override": "noCheckIn"}})),
                         [])
        # sexta com o sábado da regra já aberto para a saída: não há (c)
        self.assertEqual([x["codigo"] for x in VJ.candidatos(c, "VK7", D(2026, 12, 11),
                                                             quarto(2, {"2026-12-12": {"override": "noCheckIn"}}))],
                         ["a"])

    def test_afrodite_so_estadia(self):
        c = cfg()
        r = quarto(1)
        self.assertEqual([x["codigo"] for x in VJ.candidatos(c, "Afrodite", D(2026, 12, 11), r)], ["a"])
        self.assertEqual(VJ.candidatos(c, "Afrodite", D(2026, 12, 12), r), [])
        self.assertEqual([x["codigo"] for x in VJ.candidatos(c, "Afrodite", D(2026, 12, 7), r)], ["a"])


class TestPropor(unittest.TestCase):
    """4: escolha, trava e estado (abrir/manter), pelo inventário (calcular_janelas: regra + propor)."""

    def alvo(self, jan, q, noite):
        return (jan["alvos"].get(q) or {}).get(noite)

    def test_a_noite_de_semana_estadia_1(self):
        jan = janelas({"Q7": cen_a()})
        self.assertEqual([(x["quarto"], x["noite"], x["codigo"]) for x in jan["abrir"]], [("Q7", "2026-12-07", "a")])
        al = self.alvo(jan, "Q7", "2026-12-07")
        self.assertEqual((al["min_stay"], al["tipo"], al["estado"]), (1, "noite", "abrir"))
        self.assertNotIn("override", al)
        self.assertIsNone(jan["abrir"][0]["vizinha_risco"])          # domingo a quinta: sem trava
        self.assertEqual(set(jan["alvos"]["Q7"]), {"2026-12-07"})

    def test_b2_sabado_com_domingo_esgotado(self):
        jan = janelas({"VK2": cen_b()})
        self.assertEqual([(x["noite"], x["codigo"]) for x in jan["abrir"]], [("2026-12-12", "b2")])
        al = self.alvo(jan, "VK2", "2026-12-12")
        self.assertEqual((al["override"], al["min_stay"]), ("noCheckOut", 1))
        self.assertEqual(jan["abrir"][0]["vizinha_risco"], {"data": "2026-12-11", "livres": 1})

    def test_b1_sabado_com_domingo_livre(self):
        jan = janelas({"VK2": cen_c()})
        self.assertEqual([(x["noite"], x["codigo"]) for x in jan["abrir"]], [("2026-12-12", "b1")])
        al = self.alvo(jan, "VK2", "2026-12-12")
        self.assertEqual(al["override"], "noCheckOut")
        self.assertNotIn("min_stay", al)                              # a estadia 2 do sábado fica

    def test_c_sexta_com_sabado_quase_esgotado(self):
        jan = janelas({"VK7": cen_d()})
        self.assertEqual([(x["noite"], x["codigo"], x["presas"]) for x in jan["abrir"]], [("2026-12-11", "c", 4)])
        self.assertEqual(self.alvo(jan, "VK7", "2026-12-11")["min_stay"], 1)
        sab = self.alvo(jan, "VK7", "2026-12-12")
        self.assertEqual((sab["override"], sab["tipo"], sab["noite_janela"]), ("noCheckIn", "saída no sábado", "2026-12-11"))
        self.assertNotIn("min_stay", sab)

    def test_trava_sexta_com_sabado_cheio_de_livres_fica_a_observar(self):
        jan = janelas({"VK7": cen_e()})
        self.assertEqual(jan["abrir"] + jan["mantidas"], [])
        self.assertEqual([(x["noite"], x["presas"], x["vizinha_risco"]) for x in jan["observar"]],
                         [("2026-12-11", 1, {"data": "2026-12-12", "livres": 5})])
        self.assertIn("trava: presas 1 < livres da vizinha 12/12 (5)", jan["observar"][0]["motivo"])
        self.assertEqual(jan["alvos"], {})
        # sem a trava, abre
        jan2 = janelas({"VK7": cen_e()}, cfg(trava_fragmentacao=False))
        self.assertEqual([x["noite"] for x in jan2["abrir"]], ["2026-12-11"])

    def test_trava_sabado_pela_sexta(self):
        # sábado preso com a sexta de 2 livres e 1 presa: 1 < 2, a observar
        inv = cen_b(**{"2026-12-11": {"numAvail": 2}, "2026-12-12": {"numAvail": 2}, "2026-12-10": {"numAvail": 0}})
        # quinta esgotada, sexta 2, sábado 2, domingo 0: as duas da sexta vendem sex→dom: nada preso
        self.assertEqual(janelas({"VK2": inv})["observar"], [])
        inv = quarto(3, {"2026-12-10": {"numAvail": 0}, "2026-12-11": {"numAvail": 2}, "2026-12-12": {"numAvail": 3},
                         "2026-12-13": {"numAvail": 0}})
        jan = janelas({"Q7": inv})
        self.assertEqual([(x["noite"], x["presas"], x["vizinha_risco"]["livres"]) for x in jan["observar"]],
                         [("2026-12-12", 1, 2)])

    def test_estado_manter_e_abrir_parcial(self):
        # já aberto no Beds24 (estado de agora): manter; as presas se calculam na regra
        inv = cen_d(**{"2026-12-11": {"numAvail": 5, "minStay": 1}, "2026-12-12": {"numAvail": 1, "override": "noCheckIn"}})
        jan = janelas({"VK7": inv})
        self.assertEqual([(x["noite"], x["estado"]) for x in jan["mantidas"]], [("2026-12-11", "manter")])
        self.assertEqual(jan["abrir"], [])
        # só a estadia aberta, o sábado ainda fechado: abrir (a parte que falta)
        inv2 = cen_d(**{"2026-12-11": {"numAvail": 5, "minStay": 1}})
        jan2 = janelas({"VK7": inv2})
        self.assertEqual([(x["noite"], x["estado"]) for x in jan2["abrir"]], [("2026-12-11", "abrir")])

    def test_duas_janelas_na_mesma_semana_sem_conflito(self):
        # segunda presa (como A) e sexta presa (como D) no mesmo quarto (VK7)
        inv = quarto(3, {"2026-12-06": {"numAvail": 0}, "2026-12-07": {"numAvail": 6}, "2026-12-08": {"numAvail": 5},
                         "2026-12-10": {"numAvail": 0}, "2026-12-11": {"numAvail": 5}, "2026-12-12": {"numAvail": 1}})
        jan = janelas({"VK7": inv})
        self.assertEqual([(x["noite"], x["codigo"]) for x in jan["abrir"]], [("2026-12-07", "a"), ("2026-12-11", "c")])
        self.assertEqual(sorted(jan["alvos"]["VK7"]), ["2026-12-07", "2026-12-11", "2026-12-12"])
        # o sábado 12/12 não abre outra janela por cima da (c): a cópia de trabalho já tem o noCheckIn
        self.assertEqual(jan["alvos"]["VK7"]["2026-12-12"]["override"], "noCheckIn")

    def test_regra_desligada_ou_sem_inventario(self):
        self.assertIsNone(janelas({"Q7": cen_a()}, cfg(ativa=False)))
        self.assertIsNone(c12.calcular_janelas(cfg(), {}, HOJE, LIDO, ordens(), FIM))
        # sem a ordem nas ordens: nada (nem relatório)
        jan = janelas({"Q7": cen_a()}, ords={})
        self.assertEqual((jan["abrir"], jan["observar"], jan["excluidas"]), ([], [], []))
        # antes de `desde`
        jan = janelas({"Q7": cen_a()}, cfg(desde="2026-12-08"))
        self.assertEqual(jan["abrir"], [])

    def test_inventario_curto_nao_abre(self):
        # rotina leve com o inventário até hoje + 8: só hoje e amanhã têm cobertura; a segunda 07/12 não se avalia
        curto = {k: v for k, v in cen_a().items() if D.fromisoformat(k) <= HOJE + 8 * UM}
        jan = janelas({"Q7": curto})
        self.assertEqual(jan["abrir"], [])
        self.assertIn({"quarto": "Q7", "noite": "2026-12-07"}, jan["sem_cobertura"])
        esparso = {k: v for k, v in cen_a().items() if D.fromisoformat(k).day % 2}
        jan = janelas({"Q7": esparso})
        self.assertEqual((jan["abrir"], jan["observar"]), ([], []))

    def test_afrodite_sabado_fica_a_observar(self):
        # propor direto sobre um inventário da regra sintético: sexta com a chegada fechada pelo dono, sábado preso
        r = quarto(1, {"2026-12-10": {"numAvail": 0}, "2026-12-11": {"override": "noCheckIn"}})
        res = VJ.propor(cfg(), ordens(), {"Afrodite": r}, {"Afrodite": r}, HOJE, FIM, LIDO)
        # a sexta (chegada fechada, quinta esgotada) e o sábado ficam presos; (b) e (c) não existem na Afrodite (v1)
        self.assertEqual([(x["noite"], x["motivo"]) for x in res["observar"]],
                         [("2026-12-11", VJ.MOTIVO_AFRODITE), ("2026-12-12", VJ.MOTIVO_AFRODITE)])
        self.assertEqual(res["alvos"], {})
        # a segunda presa da Afrodite abre com estadia 1 (a)
        r2 = quarto(1, {"2026-12-08": {"numAvail": 0, "override": "blackout"}, "2026-12-06": {"numAvail": 0}})
        r2["2026-12-07"]["maxStay"] = 1          # só 1 noite cabe na segunda e a estadia pede 2
        res2 = VJ.propor(cfg(), ordens(), {"Afrodite": r2}, {"Afrodite": r2}, HOJE, FIM, LIDO)
        self.assertEqual([(x["noite"], x["codigo"]) for x in res2["abrir"]], [("2026-12-07", "a")])
        self.assertNotIn("override", res2["alvos"]["Afrodite"]["2026-12-07"])

    def test_feriado_permitido(self):
        c = cfg()
        c["feriados"] = c["feriados"] + [{"nome": "Feriado de teste", "inicio": "2026-12-07", "fim": "2026-12-07",
                                          "estadia_minima": 2, "piso_real": None}]
        jan = janelas({"Q7": cen_a()}, c)
        self.assertEqual([(x["noite"], x["codigo"]) for x in jan["abrir"]], [("2026-12-07", "a")])

    def test_invariante_abertura_nunca_aumenta_presas(self):
        """8: fuzz pequeno com semente fixa: aplicar um candidato nunca aumenta as presas de noite nenhuma."""
        rnd = random.Random(1611)
        c = cfg()
        violacoes = 0
        for _ in range(60):
            r = quarto(3)
            for k in list(r):
                r[k]["numAvail"] = rnd.choice([0, 1, 2, 3, 3])
                r[k]["minStay"] = rnd.choice([1, 1, 2, 2, 3])
                if D.fromisoformat(k).weekday() == 5:
                    r[k]["override"] = rnd.choice([F, F, "noCheckIn", "noCheckOut"])
            for n in (D(2026, 12, 4), D(2026, 12, 5), D(2026, 12, 7), D(2026, 12, 11), D(2026, 12, 12)):
                for cand in VJ.candidatos(c, "Q7", n, r):
                    r2 = VJ.com_mudancas(r, cand["mudancas"])
                    for k in range(-7, 8):
                        x = n + k * UM
                        if VJ.coberta(r, x, HOJE) and VJ.presas_da_noite(r2, x, HOJE)["presas"] > \
                                VJ.presas_da_noite(r, x, HOJE)["presas"]:
                            violacoes += 1
        self.assertEqual(violacoes, 0)


class TestExclusoes(unittest.TestCase):
    """5: onde a janela nunca abre."""

    def test_motivos(self):
        c = cfg()
        o = ordens()
        hoje = D(2026, 12, 14)
        inv = quarto(2, de=D(2026, 12, 13), ate=D(2027, 1, 12))
        self.assertIn("pacote", VJ.motivo_exclusao(c, o, "VK2", D(2026, 12, 23), hoje, inv))
        self.assertIn("pacote", VJ.motivo_exclusao(c, o, "VK2", D(2026, 12, 30), hoje, inv))
        self.assertIn("pacote", VJ.motivo_exclusao(c, o, "VK2", D(2027, 1, 3), hoje, inv))
        self.assertIn("sábado de festa", VJ.motivo_exclusao(c, o, "VK2", D(2026, 12, 26), hoje, inv))
        self.assertIsNone(VJ.motivo_exclusao(c, o, "VK2", D(2026, 12, 21), hoje, inv))
        self.assertIn("horizonte", VJ.motivo_exclusao(c, o, "VK2", hoje + 61 * UM, hoje, inv))
        self.assertIsNone(VJ.motivo_exclusao(c, o, "VK2", hoje + 60 * UM, hoje, inv))
        self.assertIn("horizonte", VJ.motivo_exclusao(c, o, "VK2", hoje - UM, hoje, inv))
        # ordem do dono com restrições próprias (Réveillon), fora de pacote (config sem os pacotes)
        c2 = cfg()
        rh = c2["parametros"]["regra_h_beds24"]
        for k in ("estadia_minima_por_data", "estadia_maxima_por_data", "override_por_data"):
            rh[k] = {}
        rev = {"id": "rev-teste", "tipo": "preco_por_data", "quartos": ["VK2"], "de": "2026-12-30", "ate": "2027-01-02",
               "restricoes": {"portao": {}}, "libera": []}
        self.assertIsNone(VJ.motivo_exclusao(c2, o, "VK2", D(2026, 12, 30), hoje, inv))
        self.assertIn("rev-teste", VJ.motivo_exclusao(c2, ordens(rev), "VK2", D(2026, 12, 30), hoje, inv))
        self.assertIsNone(VJ.motivo_exclusao(c2, ordens(rev), "Q7", D(2026, 12, 30), hoje, inv))
        # ordem de tipo restricao sem `restricoes` (sábados fechados até 2099) NÃO exclui
        sab = {"id": "sab-teste", "tipo": "restricao", "quartos": ["VK2"], "de": "2026-11-02", "ate": "2099-12-31",
               "libera": []}
        self.assertIsNone(VJ.motivo_exclusao(c, ordens(sab), "VK2", D(2026, 12, 19), hoje, inv))
        # noite congelada por mudança manual
        man = {"id": "manual-teste", "tipo": "manual", "quartos": ["VK2"], "de": "2026-12-15", "ate": "2026-12-16",
               "libera": []}
        self.assertIn("congelada", VJ.motivo_exclusao(c, ordens(man), "VK2", D(2026, 12, 15), hoje, inv))
        # blackout (noite fechada pelo dono) e esgotada no inventário atual
        self.assertIn("blackout", VJ.motivo_exclusao(c, o, "VK2", D(2026, 12, 16), hoje,
                                                     dict(inv, **{"2026-12-16": {"numAvail": 0, "override": "blackout"}})))
        self.assertIn("esgotada", VJ.motivo_exclusao(c, o, "VK2", D(2026, 12, 16), hoje,
                                                     dict(inv, **{"2026-12-16": {"numAvail": 0, "override": "none"}})))
        # noites_fora (exclusão manual), aguardando o dono, fora da ordem, antes de `desde`
        self.assertIn("noites_fora", VJ.motivo_exclusao(cfg(noites_fora={"VK2": ["2026-12-17"]}), o, "VK2",
                                                        D(2026, 12, 17), hoje, inv))
        c3 = cfg()
        c3["parametros"]["estadia_por_dia_chegada"]["aguardando_dono"] = {"VK2": ["sex"], "motivo": "teste"}
        self.assertIn("aguardando", VJ.motivo_exclusao(c3, o, "VK2", D(2026, 12, 18), hoje, inv))
        self.assertIn("fora da ordem", VJ.motivo_exclusao(c, {}, "VK2", D(2026, 12, 18), hoje, inv))
        self.assertIn("antes de", VJ.motivo_exclusao(cfg(desde="2026-12-20"), o, "VK2", D(2026, 12, 18), hoje, inv))
        # feriado não exclui
        c4 = cfg()
        c4["feriados"] = c4["feriados"] + [{"nome": "F", "inicio": "2026-12-18", "fim": "2026-12-18"}]
        self.assertIsNone(VJ.motivo_exclusao(c4, o, "VK2", D(2026, 12, 18), hoje, inv))

    def test_noite_presa_excluida_vai_ao_relatorio(self):
        jan = janelas({"Q7": cen_a()}, cfg(noites_fora={"Q7": ["2026-12-07"]}))
        self.assertEqual(jan["abrir"], [])
        self.assertEqual([(x["noite"], x["presas"], x["motivo"]) for x in jan["excluidas"]],
                         [("2026-12-07", 1, "noites_fora (exclusão manual)")])
        man = {"id": "manual-teste", "tipo": "manual", "quartos": ["Q7"], "de": "2026-12-07", "ate": "2026-12-07",
               "libera": []}
        jan = janelas({"Q7": cen_a()}, ords=ordens(man))
        self.assertIn("congelada", jan["excluidas"][0]["motivo"])

    def test_sabado_vizinho_da_c_excluido_nao_abre_a_c(self):
        # o sábado 12/12 em noites_fora: a (c) não serve; a (a) sozinha não vende (saída no sábado fechada)
        jan = janelas({"VK7": cen_d()}, cfg(noites_fora={"VK7": ["2026-12-12"]}))
        self.assertEqual(jan["abrir"], [])
        self.assertEqual([(x["noite"], x["motivo"]) for x in jan["observar"]],
                         [("2026-12-11", "nenhuma abertura mínima vende")])

    def test_pacote_e_reveillon_reais(self):
        # o inventário real perto do Natal: noite presa no pacote vai a "excluidas", nunca a "alvos"
        hoje = D(2026, 12, 14)
        inv = quarto(3, {"2026-12-22": {"numAvail": 0}, "2026-12-23": {"numAvail": 3}, "2026-12-24": {"numAvail": 1}},
                     de=D(2026, 12, 13), ate=D(2027, 1, 12))
        jan = c12.calcular_janelas(cfg(), {"VK7": inv}, hoje, "2026-12-14T10:00:00Z", ordens_reais(), D(2027, 1, 12))
        self.assertTrue(all(D(2026, 12, 23) > D.fromisoformat(n) or D.fromisoformat(n) > D(2027, 1, 3)
                            for n in (jan["alvos"].get("VK7") or {})))
        self.assertTrue(any("pacote" in x["motivo"] for x in jan["excluidas"]))


class TestInventarioDaRegra(unittest.TestCase):
    """9: o inventário da regra devolve a janela aberta à regra; órfã, sábado órfão e blackout ficam."""

    def test_volta_a_regra_e_coerencia_com_o_plano_completo(self):
        c = cfg()
        c["feriados"] = c["feriados"] + [{"nome": "Feriado de teste", "inicio": "2026-12-15", "fim": "2026-12-15",
                                          "estadia_minima": 2}]
        inv = quarto(3, {"2026-12-07": {"minStay": 1},                                   # segunda aberta (janela)
                         "2026-12-12": {"minStay": 1, "override": "noCheckOut"},         # sábado aberto (janela b2)
                         "2026-12-15": {"minStay": 1},                                   # feriado com 1
                         "2026-12-03": {"minStay": 2}, "2026-12-04": {"numAvail": 1, "minStay": 2},
                         "2026-12-05": {"numAvail": 0},                                  # sexta órfã (sábado esgotado)
                         "2026-12-18": {"numAvail": 0}, "2026-12-19": {"numAvail": 2, "minStay": 1},  # sábado órfão
                         "2026-12-09": {"override": "blackout", "numAvail": 0}})
        cl0 = {x["quarto"]: x for x in c12.classificar_beds24(c, {"VK7": inv}, HOJE, FIM, hoje=HOJE, lido_em=LIDO)}
        reg = c12.inventario_da_regra(c, {"VK7": inv}, list(cl0.values()), HOJE, LIDO)["VK7"]
        self.assertEqual(reg["2026-12-07"]["minStay"], 2)
        self.assertEqual((reg["2026-12-12"]["minStay"], reg["2026-12-12"]["override"]), (2, F))
        self.assertEqual(reg["2026-12-15"]["minStay"], 2)
        self.assertEqual(reg["2026-12-03"]["minStay"], 1)                  # quinta volta a 1
        self.assertEqual((reg["2026-12-04"]["minStay"], reg["2026-12-05"]["override"]), (1, "noCheckIn"))
        self.assertEqual((reg["2026-12-19"]["minStay"], reg["2026-12-19"]["override"]), (1, "noCheckOut"))
        self.assertEqual(reg["2026-12-09"]["override"], "blackout")
        self.assertEqual(inv["2026-12-07"]["minStay"], 1)                  # o inventário lido não muda
        # coerência: a estadia do inventário da regra = a que o plano completo (sem janelas) deixa na noite
        efetiva = {k: int(v["minStay"]) for k, v in inv.items()}
        dif = c12.comparar(c, "VK7", recalculo(inv, efetiva), HOJE, FIM - UM, ordens_reais(), efetiva=efetiva,
                           inv=inv, lido_em=LIDO, hoje=HOJE)
        por_data = {x["data"]: x for x in dif}
        for k in sorted(inv):
            d = D.fromisoformat(k)
            if not HOJE <= d <= FIM - UM or not VJ.vende(inv[k]):
                continue
            x = por_data.get(k) or {}
            deixa = (x.get("item") or {}).get("min_stay", efetiva[k])
            self.assertEqual(reg[k]["minStay"], deixa, k)

    def test_blackout_fica_no_sabado_e_no_pacote(self):
        """12: regressão do achado de 16/11: a auditoria trocava o blackout do sábado e do pacote."""
        c = cfg()
        inv = {"VK2": quarto(2, {"2026-12-12": {"numAvail": 0, "override": "blackout"}})}
        cl = classif(inv, None, c)["VK2"]
        self.assertNotIn("2026-12-12", [s["data"] for s in cl["sabados_fora_da_regra"]])
        self.assertIn("2026-12-12 blackout", cl["outras_noites_bloqueadas"])
        inv2 = {"VK2": quarto(2, {"2026-12-24": {"numAvail": 0, "override": "blackout"}}, de=D(2026, 12, 20),
                              ate=D(2026, 12, 26))}
        cl2 = c12.classificar_beds24(c, inv2, D(2026, 12, 20), D(2026, 12, 26))[0]
        self.assertNotIn("2026-12-24", [s["data"] for s in cl2["sabados_fora_da_regra"]])
        self.assertIn("2026-12-24 blackout", cl2["outras_noites_bloqueadas"])
        # sem blackout o pacote segue trocado como antes
        inv3 = {"VK2": quarto(2, {"2026-12-24": {"override": "none"}}, de=D(2026, 12, 20), ate=D(2026, 12, 26))}
        cl3 = c12.classificar_beds24(c, inv3, D(2026, 12, 20), D(2026, 12, 26))[0]
        self.assertIn(("2026-12-24", F), [(s["data"], s["troca"]) for s in cl3["sabados_fora_da_regra"]])


class TestBeds24ComJanelas(unittest.TestCase):
    """11, 15 e a volta: a auditoria e o lote do Beds24 não fecham a abertura enquanto a prova vale."""

    def aberto_d(self):
        return cen_d(**{"2026-12-11": {"numAvail": 5, "minStay": 1}, "2026-12-12": {"numAvail": 1, "override": "noCheckIn"}})

    def test_janela_aberta_nao_vira_fechamento(self):
        inv = {"VK7": self.aberto_d(), "Q7": cen_a(**{"2026-12-07": {"numAvail": 6, "minStay": 1}}),
               "VK2": cen_b(**{"2026-12-12": {"numAvail": 2, "minStay": 1, "override": "noCheckOut"}})}
        jan = janelas(inv)
        self.assertEqual(sorted((x["quarto"], x["noite"]) for x in jan["mantidas"]),
                         [("Q7", "2026-12-07"), ("VK2", "2026-12-12"), ("VK7", "2026-12-11")])
        cl = classif(inv, jan)
        noites = {("VK7", "2026-12-11"), ("VK7", "2026-12-12"), ("Q7", "2026-12-07"), ("VK2", "2026-12-12")}
        for q, x in cl.items():
            self.assertFalse({(q, s["data"]) for s in x["sabados_fora_da_regra"]} & noites)
            self.assertFalse({(q, s["data"]) for s in x["estadia_dia_chegada"]} & noites)
            self.assertNotIn("2026-12-12", x["sabados_estadia_abaixo"] if q == "VK2" else [])
            self.assertEqual(x["janelas_abrir"], [])
        self.assertEqual(sorted(cl["VK7"]["janelas_mantidas"]), ["2026-12-11", "2026-12-12"])
        lote = c12.lote_unico(cfg(), list(cl.values()), HOJE)
        self.assertFalse({(i["quarto"], i["de"]) for i in lote} & noites, lote)
        # sem as janelas (--sem-janelas), a regra fecha tudo: é o que o lote de 16/11 fazia
        cl0 = classif(inv, None)
        lote0 = c12.lote_unico(cfg(), list(cl0.values()), HOJE)
        self.assertTrue(noites <= {(i["quarto"], i["de"]) for i in lote0})

    def test_janela_a_abrir_em_duas_partes(self):
        inv = {"VK7": cen_d()}
        jan = janelas(inv)
        cl = classif(inv, jan)["VK7"]
        ja = {s["data"]: s for s in cl["janelas_abrir"]}
        self.assertEqual((ja["2026-12-11"]["min_stay"], ja["2026-12-11"]["alvo_min"]), (2, 1))
        self.assertEqual((ja["2026-12-12"]["override"], ja["2026-12-12"]["troca"]), (F, "noCheckIn"))
        na = {"2026-12-10": 0, "2026-12-11": 5, "2026-12-12": 1, "2026-12-13": 3}
        self.assertEqual(ja["2026-12-11"]["num_avail_antes"], na)
        est = c12.lote_janelas([cl], "estadia")
        ov = c12.lote_janelas([cl], "override")
        self.assertEqual([(i["de"], i["min_stay"], i["min_stay_antes"], i["pricelabs_tambem"]) for i in est],
                         [("2026-12-11", 1, 2, True)])
        self.assertEqual([(i["de"], i["override"], i["override_antes"]) for i in ov], [("2026-12-12", "noCheckIn", F)])
        self.assertTrue(all(i["num_avail_antes"] == na and i["janela_prova"]["noite"] == "2026-12-11" for i in est + ov))
        self.assertIn("Janela (dono 16/11", est[0]["motivo"])
        # a estadia vai no lote só de estadia (urgente primeiro: 11/12 está a 11 dias); o override só no completo
        self.assertEqual([i["de"] for i in c12.lote_estadia_dia_chegada([cl]) if i.get("janela")], ["2026-12-11"])
        self.assertEqual([i["de"] for i in c12.lote_beds24([cl], so_estadia=True) if i.get("janela")], ["2026-12-11"])
        self.assertEqual(sorted(i["de"] for i in c12.lote_beds24([cl]) if i.get("janela")), ["2026-12-11", "2026-12-12"])
        urg = c12.lotes_estadia(cfg(), [cl], HOJE)
        self.assertIn("2026-12-11", [i["de"] for i in urg["villa_urgente"]])
        unico = c12.lote_unico(cfg(), [cl], HOJE)
        self.assertEqual(sorted((i["de"], "min_stay" in i) for i in unico if i.get("janela")),
                         [("2026-12-11", True), ("2026-12-12", False)])
        self.assertEqual(len(unico), len({beds24.chave_item_lote(i) for i in unico}))   # sem repetir

    def test_volta_a_regra_quando_a_janela_some(self):
        # segunda aberta (estadia 1); uma reserva na segunda tira a unidade presa (6 -> 5 livres): volta a 2
        aberto = cen_a(**{"2026-12-07": {"numAvail": 6, "minStay": 1}})
        jan = janelas({"Q7": aberto})
        self.assertEqual(classif({"Q7": aberto}, jan)["Q7"]["janelas_mantidas"], ["2026-12-07"])
        vendida = cen_a(**{"2026-12-07": {"numAvail": 5, "minStay": 1}})
        jan2 = janelas({"Q7": vendida})
        self.assertEqual((jan2["alvos"], jan2["mantidas"]), ({}, []))
        cl = classif({"Q7": vendida}, jan2)["Q7"]
        self.assertIn(("2026-12-07", 1, 2), [(s["data"], s["min_stay"], s["alvo"]) for s in cl["estadia_dia_chegada"]])
        # sábado b2 aberto; o domingo reabre e (b1) basta: a estadia volta a 2 pela regra, a entrada no sábado fica
        sab = cen_b(**{"2026-12-12": {"numAvail": 2, "minStay": 1, "override": "noCheckOut"}, "2026-12-13": {"numAvail": 2}})
        jan3 = janelas({"VK2": sab})
        self.assertEqual(jan3["alvos"]["VK2"]["2026-12-12"].get("min_stay"), None)
        cl3 = classif({"VK2": sab}, jan3)["VK2"]
        self.assertIn("2026-12-12", cl3["sabados_estadia_abaixo"])          # o plano completo sobe a 2
        self.assertEqual(cl3["sabados_fora_da_regra"], [])
        # e com a sexta esgotando de vez a presa some: o sábado volta a fechar (lado órfão: sexta esgotada -> noCheckOut)
        sab2 = cen_c(**{"2026-12-12": {"numAvail": 1, "override": "noCheckOut"}, "2026-12-11": {"numAvail": 1}})
        jan4 = janelas({"VK2": sab2})
        cl4 = classif({"VK2": sab2}, jan4)["VK2"]
        self.assertIn(("2026-12-12", F), [(s["data"], s["troca"]) for s in cl4["sabados_fora_da_regra"]])

    def test_orfa_e_sabado_orfao_na_frente(self):
        """Não conflita com a órfã (04/11) nem com o sábado órfão (02/11): a noite tem UM alvo, o da regra."""
        inv = {"Double": quarto(2, {"2026-12-04": {"numAvail": 1, "minStay": 2}, "2026-12-05": {"numAvail": 0}}),
               "VK2": quarto(2, {"2026-12-18": {"numAvail": 0}, "2026-12-19": {"numAvail": 2}})}
        jan = janelas(inv)
        self.assertEqual(jan["alvos"], {})
        self.assertEqual(jan["abrir"] + jan["observar"], [])
        cl = classif(inv, jan)
        self.assertIn(("2026-12-04", 1), [(s["data"], s["alvo"]) for s in cl["Double"]["estadia_dia_chegada"]])
        self.assertIn(("2026-12-05", "noCheckIn"), [(s["data"], s["troca"]) for s in cl["Double"]["sabados_fora_da_regra"]])
        self.assertIn(("2026-12-19", "noCheckOut"), [(s["data"], s["troca"]) for s in cl["VK2"]["sabados_fora_da_regra"]])
        # e o plano do PriceLabs dá a órfã (sem janela)
        dif = c12.comparar(cfg(), "Double", recalculo(inv["Double"]), D(2026, 12, 4), D(2026, 12, 4), ordens(),
                           so_estadia=True, inv=inv["Double"], lido_em=LIDO, hoje=HOJE, janelas={})
        self.assertEqual(dif[0]["item"].get("orfa"), "seguinte")
        self.assertNotIn("janela", dif[0]["item"])


class TestComparar(unittest.TestCase):
    """13 e 14: o item do PriceLabs da janela e o motivo do plano."""

    def test_item_da_janela_completo_e_so_estadia(self):
        for so in (True, False):
            it = item_janela(cen_a(), "Q7", "2026-12-07", so_estadia=so)
            self.assertEqual((it["min_stay"], it["janela"]), (1, "noite"))
            self.assertEqual(it["janela_prova"]["noite"], "2026-12-07")
            self.assertNotIn("min_price", it)

    def test_so_info_com_efetiva_1_e_sem_explicita(self):
        inv = cen_a()
        jan = janelas({"Q7": inv})
        rec = recalculo(inv, {"2026-12-07": 1})
        dif = c12.comparar(cfg(), "Q7", rec, D(2026, 12, 7), D(2026, 12, 7), ordens(), so_estadia=True,
                           regras={"2026-12-07": {"date": "2026-12-07", "min_stay": "1"}}, inv=inv, lido_em=LIDO,
                           hoje=HOJE, janelas=jan["alvos"]["Q7"])
        self.assertEqual(len(dif), 1)
        self.assertNotIn("item", dif[0])
        self.assertIn("janela aberta", dif[0]["info"][0])
        # sem a janela, a mesma noite ganharia a "explícita 2" (regra por data com 1 numa segunda)
        dif0 = c12.comparar(cfg(), "Q7", rec, D(2026, 12, 7), D(2026, 12, 7), ordens(), so_estadia=True,
                            regras={"2026-12-07": {"date": "2026-12-07", "min_stay": "1"}}, inv=inv, lido_em=LIDO,
                            hoje=HOJE, janelas={})
        self.assertEqual(dif0[0]["item"]["min_stay"], 2)

    def test_sabado_b2_e_plano_completo_nao_sobe(self):
        inv = cen_b()
        for so in (True, False):
            it = item_janela(inv, "VK2", "2026-12-12", so_estadia=so)
            self.assertEqual((it["min_stay"], it["janela"]), (1, "sábado"))
        # com o sábado já em 1 no PriceLabs, o plano completo não sobe a 2 (só informação)
        jan = janelas({"VK2": inv})
        dif = c12.comparar(cfg(), "VK2", recalculo(inv, {"2026-12-12": 1}), D(2026, 12, 12), D(2026, 12, 12), ordens(),
                           inv=inv, lido_em=LIDO, hoje=HOJE, janelas=jan["alvos"]["VK2"])
        self.assertFalse([x for x in dif if x.get("item")])

    def test_item_de_piso_nao_regrava_a_exigida_e_manter_reason(self):
        inv = cen_a()
        jan = janelas({"Q7": inv})
        rec = recalculo(inv)
        rec["2026-12-07"]["min_price"] = "100"                  # piso abaixo da regra: o item leva o piso
        dif = c12.comparar(cfg(), "Q7", rec, D(2026, 12, 7), D(2026, 12, 7), ordens(), inv=inv, lido_em=LIDO,
                           hoje=HOJE, janelas=jan["alvos"]["Q7"])
        self.assertEqual(dif[0]["item"]["min_stay"], 1)
        self.assertIn("min_price", dif[0]["item"])
        # só estadia numa data cuja regra tem preço: manter_reason
        dif2 = c12.comparar(cfg(), "Q7", recalculo(inv), D(2026, 12, 7), D(2026, 12, 7), ordens(), so_estadia=True,
                            regras={"2026-12-07": {"date": "2026-12-07", "min_price": "-10", "min_stay": "2"}}, inv=inv,
                            lido_em=LIDO, hoje=HOJE, janelas=jan["alvos"]["Q7"])
        self.assertTrue(dif2[0]["item"]["manter_reason"])

    def test_montar_plano_motivo_e_payload(self):
        inv = cen_d()
        jan = janelas({"VK7": inv})
        dif = c12.comparar(cfg(), "VK7", recalculo(inv), D(2026, 12, 11), D(2026, 12, 12), ordens(), so_estadia=True,
                           inv=inv, lido_em=LIDO, hoje=HOJE, janelas=jan["alvos"]["VK7"])
        plano = c12.montar_plano([x for x in dif if x["falta"]], HOJE)
        self.assertEqual(len(plano["itens"]), 1)
        it = plano["itens"][0]
        self.assertTrue(it["motivo"].startswith("Janela (dono 16/11"), it["motivo"])
        self.assertIn("saída no sábado 12/12 aberta no Beds24", it["motivo"])
        pay = montar_payload(cfg(), [dict(it, manter_reason=True)])["update_listing_date_overrides"][0]["overrides"][0]
        self.assertEqual(pay["min_stay"], "1")
        self.assertNotIn("reason", pay)
        self.assertNotIn("janela", pay)


class TestValidador(unittest.TestCase):
    """7 e 17-18: o validador aceita a janela com a prova boa e barra o resto."""

    def test_aceita_segunda_sabado_sexta_e_feriado(self):
        self.assertEqual(erros([item_janela(cen_a(), "Q7", "2026-12-07")]), [])
        self.assertEqual(erros([item_janela(cen_b(), "VK2", "2026-12-12")]), [])
        self.assertEqual(erros([item_janela(cen_d(), "VK7", "2026-12-11")]), [])
        c = cfg()
        c["feriados"] = c["feriados"] + [{"nome": "Feriado de teste", "inicio": "2026-12-07", "fim": "2026-12-07",
                                          "estadia_minima": 2, "piso_real": None}]
        self.assertEqual(erros([item_janela(cen_a(), "Q7", "2026-12-07", c)], c), [])
        # a prova de ontem (lido_em e hoje da prova) ainda vale hoje
        it = item_janela(cen_a(), "Q7", "2026-12-07")
        self.assertEqual(erros([it], hoje=HOJE + UM), [])

    def test_recusas(self):
        boa = item_janela(cen_a(), "Q7", "2026-12-07")

        def com(**k):
            it = copy.deepcopy(boa)
            it.update(k)
            return [it]

        def prova(**k):
            it = copy.deepcopy(boa)
            it["janela_prova"].update(k)
            return [it]

        self.assertTrue(any("janela sem prova (sem janela_prova)" in e for e in erros(com(janela_prova=None))))
        self.assertTrue(any("lida em" in e for e in erros(prova(lido_em="2026-11-27T10:00:00Z"))))
        self.assertTrue(any("prova de 2026-11-28" in e for e in erros(prova(hoje="2026-11-28"))))
        self.assertTrue(any("ordem da prova" in e for e in erros(prova(ordem_id="dono-2026-11-04-estadia-dia-chegada"))))
        self.assertTrue(any("prova do quarto" in e for e in erros(com(quarto="VK7"))))
        self.assertTrue(any("não muda a noite" in e for e in erros(com(data="2026-12-08"))))
        regra = copy.deepcopy(boa["janela_prova"]["regra"])
        regra["2026-12-08"]["numAvail"] = 6                     # adulterada: a terça com 6 vende tudo
        self.assertTrue(any("não bate" in e for e in erros(prova(regra=regra))))
        self.assertTrue(any("não são uma abertura mínima" in e for e in erros(prova(mudancas={
            "2026-12-07": {"minStay": [2, 1]}, "2026-12-09": {"minStay": [2, 1]}}))))       # terceira noite
        self.assertTrue(any("não são uma abertura mínima" in e for e in erros(prova(mudancas={
            "2026-12-07": {"minStay": [2, 3]}}))))                                         # aperto
        self.assertTrue(any("min_stay do item 3 diferente do da janela (1)" in e for e in erros(com(min_stay=3))))
        self.assertTrue(any("órfã vence" in e for e in erros(com(orfa="seguinte", orfa_prova={}))))
        self.assertTrue(any("chegada/saída por data" in e for e in erros(com(check_in="1111111", check_out="1111111"))))
        self.assertTrue(any("sem min_stay" in e for e in erros([{k: v for k, v in boa.items() if k != "min_stay"}])))
        # regra desligada
        self.assertTrue(any("desligada" in e for e in erros([copy.deepcopy(boa)], cfg(ativa=False))))
        # --precos com a noite esgotada desmente
        precos = {("Q7", "2026-12-07"): {"livres": 0, "vendidas": 7, "total": 7, "preco": 900}}
        self.assertTrue(any("desmente a janela" in e for e in erros([copy.deepcopy(boa)], precos=precos)))
        # regressão: sem janela, a segunda com 1 continua ERRO
        sem = {k: v for k, v in boa.items() if k not in ("janela", "janela_prova")}
        self.assertTrue(any("exige estadia mínima 2" in e for e in erros([sem])))

    def test_sabado_com_1_sem_janela_segue_erro(self):
        it = item_janela(cen_b(), "VK2", "2026-12-12")
        self.assertEqual(erros([it]), [])
        sem = {k: v for k, v in it.items() if k not in ("janela", "janela_prova")}
        self.assertTrue(any("sábado com 1 noite" in e for e in erros([sem])))

    def test_trava_e_pacote_na_prova(self):
        c = cfg()
        # E: a (c) existe, mas presas 1 < 5 livres do sábado; prova montada à mão é recusada pela trava
        inv = cen_e()
        cl0 = c12.classificar_beds24(c, {"VK7": inv}, HOJE, FIM, hoje=HOJE, lido_em=LIDO)
        reg = c12.inventario_da_regra(c, {"VK7": inv}, cl0, HOJE, LIDO)["VK7"]
        n = D(2026, 12, 11)
        cand = VJ.candidatos(c, "VK7", n, reg)[1]
        antes = VJ.presas_da_noite(reg, n, HOJE)
        dep = VJ.presas_da_noite(VJ.com_mudancas(reg, cand["mudancas"]), n, HOJE)
        p = VJ.montar_prova(c, "VK7", n, reg, cand, antes, dep, VJ._risco(reg, n, HOJE), HOJE, LIDO)
        it = {"tipo": "data", "quarto": "VK7", "data": "2026-12-11", "min_stay": 1, "janela": "sexta", "janela_prova": p,
              "motivo": "t"}
        self.assertIn("trava", VJ.checar_prova_janela(c, ordens(), it, "VK7", n, HOJE))
        # pacote: a prova feita sem os pacotes (config sem as datas) é recusada com a config real
        c2 = cfg()
        for k in ("estadia_minima_por_data", "estadia_maxima_por_data", "override_por_data"):
            c2["parametros"]["regra_h_beds24"][k] = {}
        hoje = D(2026, 12, 14)
        inv = quarto(3, {"2026-12-20": {"numAvail": 0}, "2026-12-21": {"numAvail": 6}, "2026-12-22": {"numAvail": 5},
                         "2026-12-23": {"numAvail": 3, "minStay": 2}}, de=D(2026, 12, 13), ate=D(2027, 1, 5))
        jan = c12.calcular_janelas(c2, {"Q7": inv}, hoje, "2026-12-14T10:00:00Z", ordens(), D(2027, 1, 5))
        al = jan["alvos"]["Q7"]["2026-12-21"]
        it = {"tipo": "data", "quarto": "Q7", "data": "2026-12-21", "min_stay": 1, "janela": "noite",
              "janela_prova": al["prova"], "motivo": "t"}
        self.assertIsNone(VJ.checar_prova_janela(c2, ordens(), it, "Q7", D(2026, 12, 21), hoje))
        prova_pac = copy.deepcopy(al["prova"])
        # a mesma prova movida para uma noite de pacote (23/12, quarta) com as noites certas na regra
        cl0 = c12.classificar_beds24(c2, {"Q7": inv}, hoje, D(2027, 1, 5), hoje=hoje, lido_em="2026-12-14T10:00:00Z")
        inv_p = quarto(3, {"2026-12-22": {"numAvail": 0}, "2026-12-23": {"numAvail": 6}, "2026-12-24": {"numAvail": 5}},
                       de=D(2026, 12, 13), ate=D(2027, 1, 5))
        cl0 = c12.classificar_beds24(c2, {"Q7": inv_p}, hoje, D(2027, 1, 5), hoje=hoje, lido_em="2026-12-14T10:00:00Z")
        reg_p = c12.inventario_da_regra(c2, {"Q7": inv_p}, cl0, hoje)["Q7"]
        n = D(2026, 12, 23)
        cand = VJ.candidatos(c2, "Q7", n, reg_p)[0]
        prova_pac = VJ.montar_prova(c2, "Q7", n, reg_p, cand, VJ.presas_da_noite(reg_p, n, hoje),
                                    VJ.presas_da_noite(VJ.com_mudancas(reg_p, cand["mudancas"]), n, hoje), None, hoje,
                                    "2026-12-14T10:00:00Z")
        it_p = {"tipo": "data", "quarto": "Q7", "data": "2026-12-23", "min_stay": 1, "janela": "noite",
                "janela_prova": prova_pac, "motivo": "t"}
        self.assertIsNone(VJ.checar_prova_janela(c2, ordens(), it_p, "Q7", n, hoje))     # sem pacote passa
        self.assertIn("pacote", VJ.checar_prova_janela(cfg(), ordens(), it_p, "Q7", n, hoje))
        self.assertTrue(any("pacote" in e for e in erros([it_p], cfg(), hoje=hoje)))

    def test_ordem_das_janelas_nao_e_excecao_do_dono(self):
        it = item_janela(cen_a(), "Q7", "2026-12-07")
        it.update(excecao="dono", ordem_id=ORDEM_ID, min_price=2000)
        self.assertTrue(any("a regra das janelas (dono 16/11)" in e for e in erros([it])))


class TestAchadosDaVerificacao(unittest.TestCase):
    """Revisão de 16/11: os achados da verificação da regra (libera, abertura mínima, max_noites, inventário curto,
    fechadas)."""

    def sem_janela_em_libera(self, base: dict, libera=()) -> dict:
        o = copy.deepcopy(base)
        o[ORDEM_ID]["libera"] = list(libera)
        return o

    def test_ordem_sem_janela_em_libera_nao_abre_nem_valida(self):
        for lib in ((), ("estadia_1_orfa",)):
            o = self.sem_janela_em_libera(ordens(), lib)
            jan = janelas({"Q7": cen_a()}, ords=o)
            self.assertEqual((jan["abrir"], jan["alvos"]), ([], {}), lib)
            self.assertIn("não libera 'janela'", VJ.motivo_exclusao(cfg(), o, "Q7", D(2026, 12, 7), HOJE, cen_a()))
            boa = item_janela(cen_a(), "Q7", "2026-12-07")
            self.assertIn("não libera 'janela'", VJ.checar_prova_janela(cfg(), o, boa, "Q7", D(2026, 12, 7), HOJE))
            self.assertTrue(any("não libera 'janela'" in e
                                for e in erros([boa], ords=self.sem_janela_em_libera(ordens_reais(), lib))), lib)
        # com a liberação, a mesma prova passa
        self.assertEqual(erros([item_janela(cen_a(), "Q7", "2026-12-07")]), [])

    def prova_de(self, c, q, inv_q, n, codigo, mx=7):
        cl0 = c12.classificar_beds24(c, {q: inv_q}, HOJE, FIM, hoje=HOJE, lido_em=LIDO)
        reg = c12.inventario_da_regra(c, {q: inv_q}, cl0, HOJE, LIDO)[q]
        cand = next(x for x in VJ.candidatos(c, q, n, reg) if x["codigo"] == codigo)
        antes = VJ.presas_da_noite(reg, n, HOJE, mx)
        dep = VJ.presas_da_noite(VJ.com_mudancas(reg, cand["mudancas"]), n, HOJE, mx)
        return VJ.montar_prova(c, q, n, reg, cand, antes, dep, VJ._risco(reg, n, HOJE), HOJE, LIDO)

    def test_validador_exige_a_abertura_que_a_regra_escolhe(self):
        # cenário C: entrada no sábado com estadia 2 (b1) já zera as presas; a (b2) também vende mais, mas não é a mínima
        c = cfg()
        n = D(2026, 12, 12)
        p = self.prova_de(c, "VK2", cen_c(), n, "b2")
        self.assertEqual((p["presas"], p["vendavel_depois"] - p["vendavel_antes"]), (1, 1))
        it = {"tipo": "data", "quarto": "VK2", "data": "2026-12-12", "min_stay": 1, "janela": "sábado",
              "janela_prova": p, "motivo": "t"}
        self.assertIn("não é a que a regra escolhe na noite (b1", VJ.checar_prova_janela(c, ordens(), it, "VK2", n, HOJE))
        self.assertTrue(any("não é a que a regra escolhe" in e for e in erros([it])))
        # no cenário B (domingo esgotado) a (b1) não vende: a (b2) é a escolhida e passa
        self.assertIsNone(VJ.checar_prova_janela(c, ordens(), item_janela(cen_b(), "VK2", "2026-12-12"), "VK2", n, HOJE))
        # a (c) do cenário D é a escolhida (a (a) sozinha não vende: a saída no sábado segue fechada)
        self.assertIsNone(VJ.checar_prova_janela(c, ordens(), item_janela(cen_d(), "VK7", "2026-12-11"), "VK7",
                                                 D(2026, 12, 11), HOJE))

    def test_max_noites_e_da_config(self):
        boa = item_janela(cen_a(), "Q7", "2026-12-07")
        ruim = copy.deepcopy(boa)
        ruim["janela_prova"]["max_noites"] = 1
        self.assertIn("prova com max_noites 1; a regra usa 7",
                      VJ.checar_prova_janela(cfg(), ordens(), ruim, "Q7", D(2026, 12, 7), HOJE))
        # Q7 com 2 livres em toda noite: não há janela (7 noites); com 1 noite a prova "provaria" 2 presas
        n = D(2026, 12, 7)
        self.assertEqual(janelas({"Q7": quarto(2)})["abrir"], [])
        p = self.prova_de(cfg(estadia_maxima_simulada=1), "Q7", quarto(2), n, "a", mx=1)
        self.assertEqual((p["max_noites"], p["presas"]), (1, 2))
        it = {"tipo": "data", "quarto": "Q7", "data": "2026-12-07", "min_stay": 1, "janela": "noite", "janela_prova": p,
              "motivo": "t"}
        self.assertIn("max_noites", VJ.checar_prova_janela(cfg(), ordens(), it, "Q7", n, HOJE))
        self.assertTrue(any("max_noites" in e for e in erros([it])))

    def test_inventario_curto_nao_fecha_a_janela_aberta(self):
        """Rotina leve (inventário até hoje + 15): a (c) aberta em 11/12 e 12/12 (D11, D12) fica sem cobertura; o lote do
        Beds24 e o plano do PriceLabs não a fecham (antes: sexta 1 -> 2 e sábado noCheckIn -> noCheckInOrCheckOut)."""
        aberto = cen_d(**{"2026-12-11": {"numAvail": 5, "minStay": 1}, "2026-12-12": {"numAvail": 1, "override": "noCheckIn"}})
        curto = {k: v for k, v in aberto.items() if D.fromisoformat(k) <= HOJE + 15 * UM}
        inv = {"VK7": curto}
        jan = janelas(inv)
        self.assertIn({"quarto": "VK7", "noite": "2026-12-11"}, jan["sem_cobertura"])
        na = VJ.noites_nao_avaliadas(jan)
        self.assertTrue({"2026-12-11", "2026-12-12"} <= na["VK7"])
        cl = c12.classificar_beds24(cfg(), inv, HOJE, FIM, hoje=HOJE, lido_em=LIDO, janelas=jan["alvos"],
                                    nao_avaliadas=na)[0]
        self.assertEqual(sorted((s["data"], s["campo"], s["atual"], s["regra"]) for s in cl["janelas_nao_avaliadas"]),
                         [("2026-12-11", "min_stay", 1, 2), ("2026-12-12", "override", "noCheckIn", F)])
        for c_ in ("estadia_dia_chegada", "sabados_fora_da_regra"):
            self.assertFalse({"2026-12-11", "2026-12-12"} & {s["data"] for s in cl[c_]}, c_)
        self.assertFalse([i for i in c12.lote_unico(cfg(), [cl], HOJE) if i["de"] in ("2026-12-11", "2026-12-12")])
        # sem a proteção (o auditar de antes): o lote fechava as duas partes
        cl_v = c12.classificar_beds24(cfg(), inv, HOJE, FIM, hoje=HOJE, lido_em=LIDO, janelas=jan["alvos"])[0]
        self.assertTrue({"2026-12-11", "2026-12-12"} <= {i["de"] for i in c12.lote_unico(cfg(), [cl_v], HOJE)})
        # o plano do PriceLabs também não sobe a estadia 1 (efetiva 1) na noite sem cobertura
        rec = recalculo(curto, {"2026-12-11": 1})
        dif = c12.comparar(cfg(), "VK7", rec, D(2026, 12, 11), D(2026, 12, 11), ordens(), inv=curto, lido_em=LIDO,
                           hoje=HOJE, janelas=jan["alvos"].get("VK7"), nao_avaliadas=na["VK7"])
        self.assertFalse([x for x in dif if x.get("item")])
        self.assertIn("sem cobertura do inventário", dif[0]["info"][0])
        dif0 = c12.comparar(cfg(), "VK7", rec, D(2026, 12, 11), D(2026, 12, 11), ordens(), inv=curto, lido_em=LIDO,
                            hoje=HOJE, janelas=jan["alvos"].get("VK7"))
        self.assertEqual(dif0[0]["item"]["min_stay"], 2)
        # a noite sem cobertura que a regra ABRE (órfã: a seguinte esgotou) segue abrindo
        orfa = dict(curto, **{"2026-12-14": {"numAvail": 3, "minStay": 2, "maxStay": 7, "override": "none"},
                              "2026-12-15": {"numAvail": 0, "minStay": 1, "maxStay": 7, "override": "none"}})
        jan_o = janelas({"VK7": orfa})
        cl_o = c12.classificar_beds24(cfg(), {"VK7": orfa}, HOJE, FIM, hoje=HOJE, lido_em=LIDO, janelas=jan_o["alvos"],
                                      nao_avaliadas=VJ.noites_nao_avaliadas(jan_o))[0]
        self.assertIn(("2026-12-14", 1), [(s["data"], s["alvo"]) for s in cl_o["estadia_dia_chegada"]])

    def test_sem_cobertura_de_pacote_nao_e_protegida(self):
        hoje = D(2026, 12, 14)
        inv = {"VK7": quarto(3, de=D(2026, 12, 13), ate=D(2026, 12, 26))}
        jan = c12.calcular_janelas(cfg(), inv, hoje, "2026-12-14T10:00:00Z", ordens(), D(2026, 12, 26))
        sc = {x["noite"]: x for x in jan["sem_cobertura"]}
        self.assertIn("pacote", sc["2026-12-23"]["motivo"])
        self.assertNotIn("motivo", sc["2026-12-20"])
        na = VJ.noites_nao_avaliadas(jan)["VK7"]
        self.assertIn("2026-12-20", na)
        self.assertNotIn("2026-12-23", na)

    def test_fechadas_e_nao_reavaliadas(self):
        atual = {"hoje": "2026-11-17", "horizonte": ["2026-11-17", "2027-01-08"], "quartos": ["Q2", "Q7", "VK2"],
                 "abrir": [], "mantidas": [{"quarto": "Q7", "noite": "2026-12-01"}],
                 "sem_cobertura": [{"quarto": "VK2", "noite": "2027-01-05"}]}
        ant = {"abrir": [{"quarto": "VK2", "noite": "2027-01-09"}],          # fora do horizonte de hoje
               "mantidas": [{"quarto": "Q7", "noite": "2026-11-16"},           # já passou
                            {"quarto": "Q7", "noite": "2026-12-01"},           # segue mantida
                            {"quarto": "Q2", "noite": "2026-12-02"},           # avaliada e sumiu: fechada
                            {"quarto": "VK2", "noite": "2027-01-05"},          # sem cobertura agora
                            {"quarto": "VK7", "noite": "2026-12-11"}]}         # quarto fora da leitura
        self.assertEqual([(x["quarto"], x["noite"]) for x in VJ.fechadas(ant, atual)], [("Q2", "2026-12-02")])
        self.assertEqual(sorted((x["quarto"], x["noite"]) for x in VJ.nao_reavaliadas(ant, atual)),
                         [("VK2", "2027-01-05"), ("VK2", "2027-01-09"), ("VK7", "2026-12-11")])
        self.assertEqual(VJ.fechadas(ant, {"avaliadas": False, "alvos": {}}), [])
        self.assertEqual(VJ.fechadas(None, atual), [])


class TestEditarLote(unittest.TestCase):
    """19: as travas novas do beds24.editar_lote (Beds24 falso do test_beds24_rodada)."""

    def setUp(self):
        import test_beds24_rodada as TB
        self.TB = TB
        self.tmp = tempfile.mkdtemp(prefix="b24jan_")
        self.addCleanup(__import__("shutil").rmtree, self.tmp, True)
        self.falso = TB.Beds24Falso()
        # VK7 (722813): sexta 11/12 5 livres e estadia 2, sábado 12/12 1 livre e fechado
        for d_, na in (("2026-12-10", 0), ("2026-12-11", 5), ("2026-12-12", 1), ("2026-12-13", 3)):
            v = self.falso.dia(722813, d_)
            v["numAvail"] = na
        self.falso.dia(722813, "2026-12-12")["override"] = F
        na = {"2026-12-10": 0, "2026-12-11": 5, "2026-12-12": 1, "2026-12-13": 3}
        pv = {"noite": "2026-12-11", "codigo": "c"}           # o lote_janelas leva a prova inteira; aqui só a noite
        self.lote = [{"quarto": "VK7", "de": "2026-12-11", "ate": "2026-12-11", "min_stay": 1, "min_stay_antes": 2,
                      "num_avail_antes": na, "pricelabs_tambem": True, "janela": "sexta", "janela_prova": pv,
                      "motivo": "t"},
                     {"quarto": "VK7", "de": "2026-12-12", "ate": "2026-12-12", "override": "noCheckIn",
                      "override_antes": F, "num_avail_antes": na, "janela": "saída no sábado", "janela_prova": pv,
                      "motivo": "t"}]

    def rodar(self, lote, aplicar=True):
        p = os.path.join(self.tmp, "lote.json")
        with open(p, "w", encoding="utf-8") as f:
            json.dump(lote, f)
        with self.TB.ambiente(self.falso, self.tmp):
            rc, out, err = self.TB.rodar(beds24.main, ["editar-lote", "--plano", p] + (["--aplicar"] if aplicar else []))
        self.assertEqual(rc, 0, err)
        return json.loads(out)

    def test_grava_as_duas_partes_e_le_as_noites_da_trava(self):
        r = self.rodar(self.lote)
        self.assertEqual((r["a_gravar"], r["conferidos"], r["pulados"], r["erros"]), (2, 2, [], []))
        self.assertEqual((self.falso.dia(722813, "2026-12-11")["minStay"], self.falso.dia(722813, "2026-12-12")["override"]),
                         (1, "noCheckIn"))
        primeira = self.falso.chamadas[0]
        self.assertEqual((primeira[2]["startDate"], primeira[2]["endDate"]), (["2026-12-10"], ["2026-12-13"]))
        self.assertEqual([x["estado"] for x in r["lote"]], ["conferido", "conferido"])

    def test_num_avail_mudou_pula_as_duas_partes(self):
        self.falso.dia(722813, "2026-12-12")["numAvail"] = 0        # o sábado vendeu entre o plano e o lote
        r = self.rodar(self.lote)
        self.assertEqual((r["a_gravar"], len(r["pulados"])), (0, 2))
        self.assertTrue(all("numAvail mudou" in p["por_que"] for p in r["pulados"]))
        self.assertEqual((self.falso.dia(722813, "2026-12-11")["minStay"], self.falso.dia(722813, "2026-12-12")["override"]),
                         (2, F))

    def test_override_antes_pula_ou_ja_no_alvo(self):
        self.falso.dia(722813, "2026-12-12")["override"] = "noCheckIn"
        r = self.rodar(self.lote[1:])
        self.assertEqual([p["por_que"] for p in r["pulados"]], ["já no alvo"])
        self.falso.dia(722813, "2026-12-12")["override"] = "blackout"   # o dono fechou o sábado: não reabre
        r = self.rodar(self.lote[1:])
        self.assertEqual([p["por_que"] for p in r["pulados"]], ["override mudou desde o plano: refaça com o inventário novo"])
        self.assertEqual(self.falso.dia(722813, "2026-12-12")["override"], "blackout")

    def test_item_antigo_igual_e_chave(self):
        antigo = {"quarto": "Q7", "de": "2026-11-16", "ate": "2026-11-16", "min_stay": 1, "min_stay_antes": 2}
        self.assertEqual(beds24.chave_item_lote(antigo),
                         '{"ate": "2026-11-16", "canais_max": null, "de": "2026-11-16", "max_stay": null, "min_stay": 1, '
                         '"min_stay_antes": 2, "num_avail": null, "num_avail_esperado": null, "override": null, '
                         '"quarto": "Q7", "vizinha_esgotada": null}')
        self.assertIn('"num_avail_antes"', beds24.chave_item_lote(self.lote[0]))
        self.assertEqual(beds24.CAMPOS_ITEM_LOTE_OPCIONAIS, c12.CAMPOS_ITEM_LOTE_OPCIONAIS)
        self.assertEqual(beds24.CAMPOS_ITEM_LOTE_UNICO, c12.CAMPOS_ITEM_LOTE)
        # dois itens iguais salvo a trava nova não somem no juntar
        b = [dict(self.lote[0], num_avail_antes={"2026-12-11": 4})]
        itens, _ = beds24.juntar_planos([("a", [dict(self.lote[0])]), ("b", b)])
        self.assertEqual(len(itens), 2)

    def test_partes_da_janela_pulam_juntas_por_qualquer_trava(self):
        """Achado da verificação (revisão de 16/11): só a num_avail_antes pulava as duas partes; com a min_stay_antes
        da sexta divergente, a saída no sábado abria sozinha (estadia terminando no sábado fora da janela)."""
        self.falso.dia(722813, "2026-12-11")["minStay"] = 3            # a sexta mudou depois do plano
        r = self.rodar(self.lote)
        self.assertEqual(r["a_gravar"], 0)
        por = {p["de"]: p["por_que"] for p in r["pulados"]}
        self.assertEqual(por["2026-12-11"], "minStay mudou desde o plano: refaça com o inventário novo")
        self.assertIn("a outra parte da mesma janela (VK7 2026-12-11) foi pulada", por["2026-12-12"])
        self.assertEqual(self.falso.dia(722813, "2026-12-12")["override"], F)
        self.assertEqual({x["de"]: x["estado"] for x in r["lote"]}, {"2026-12-11": "pulado", "2026-12-12": "pulado"})
        # o sábado com o override mudado (o dono fechou) segura a sexta também
        self.falso.dia(722813, "2026-12-11")["minStay"] = 2
        self.falso.dia(722813, "2026-12-12")["override"] = "blackout"
        r = self.rodar(self.lote)
        self.assertEqual((r["a_gravar"], len(r["pulados"])), (0, 2))
        self.assertEqual(self.falso.dia(722813, "2026-12-11")["minStay"], 2)
        # "já no alvo" numa parte não segura a outra (aquela parte já está gravada)
        self.falso.dia(722813, "2026-12-12")["override"] = F
        self.falso.dia(722813, "2026-12-11")["minStay"] = 1
        r = self.rodar(self.lote)
        self.assertEqual((r["a_gravar"], [p["por_que"] for p in r["pulados"]]), (1, ["já no alvo"]))
        self.assertEqual(self.falso.dia(722813, "2026-12-12")["override"], "noCheckIn")

    def test_juntar_acusa_janela_e_regra_na_mesma_noite(self):
        regra = [{"quarto": "VK7", "de": "2026-12-11", "ate": "2026-12-11", "min_stay": 2, "min_stay_antes": 1}]
        with self.assertRaises(beds24.ErroBeds24):
            beds24.juntar_planos([("janela", [self.lote[0]]), ("regra", regra)])


class TestPace(unittest.TestCase):
    """20: o pace.ajustar_tetos mantém a estadia da janela ao apagar a regra da noite."""

    def test_mantem_a_estadia_1_da_janela(self):
        c = cfg()
        inv = cen_a()
        jan = janelas({"Q7": inv})
        reg = {"date": "2026-12-07", "min_stay": "1", "max_price": "10", "min_price": "-20"}
        cal = {"2026-12-07": {"min_stay": "1", "mu_occupancy": "1/7", "price": "1200", "min_price": "1000"},
               "2026-12-08": {"min_stay": "1", "mu_occupancy": "2/7", "price": "1200", "min_price": "1000"}}
        it = {"tipo": "data", "quarto": "Q7", "data": "2026-12-07", "min_price": 2000,
              "max_price": float(c["por_curto"]["Q7"]["max"]), "motivo": "pace: descida do dia"}
        sem, apagar = pace.ajustar_tetos(c, [dict(it)], {"Q7": {"2026-12-07": reg}}, {"Q7": cal}, hoje=HOJE)
        self.assertEqual(len(apagar), 1)
        self.assertEqual(sem[0]["min_stay"], 2)                        # sem janelas: a regra do dia (como antes)
        com, _ = pace.ajustar_tetos(c, [dict(it)], {"Q7": {"2026-12-07": reg}}, {"Q7": cal}, hoje=HOJE,
                                    janelas=jan["alvos"])
        self.assertEqual((com[0]["min_stay"], com[0]["janela"]), (1, "noite"))
        self.assertEqual([e for e in erros([dict(com[0], tipo="data")]) if "estadia" in e or "janela" in e], [])
        # prova velha (lida há 3 dias): fica a regra
        velho = copy.deepcopy(jan["alvos"])
        velho["Q7"]["2026-12-07"]["prova"]["lido_em"] = "2026-11-27T10:00:00Z"
        com2, _ = pace.ajustar_tetos(c, [dict(it)], {"Q7": {"2026-12-07": reg}}, {"Q7": cal}, hoje=HOJE, janelas=velho)
        self.assertEqual(com2[0]["min_stay"], 2)
        self.assertNotIn("janela", com2[0])

    def test_aviso_sem_janelas(self):
        """Achado da verificação (revisão de 16/11): a rotina leve rodava o plano sem --janelas; a regra apagada com
        estadia 1 (janela) voltava a 2 em silêncio. Agora o plano avisa (riscos_sem_janelas)."""
        c = cfg()
        jan = janelas({"Q7": cen_a()})
        regras = {"Q7": {"2026-12-07": {"date": "2026-12-07", "min_stay": "1", "max_price": "10", "min_price": "-20"}}}
        cal = {"2026-12-07": {"min_stay": "1", "mu_occupancy": "1/7", "price": "1200", "min_price": "1000"},
               "2026-12-08": {"min_stay": "1", "mu_occupancy": "2/7", "price": "1200", "min_price": "1000"}}
        it = {"tipo": "data", "quarto": "Q7", "data": "2026-12-07", "min_price": 2000,
              "max_price": float(c["por_curto"]["Q7"]["max"]), "motivo": "pace: descida do dia"}
        sem, apagar = pace.ajustar_tetos(c, [dict(it)], regras, {"Q7": cal}, hoje=HOJE)
        self.assertEqual(pace.riscos_sem_janelas(c, sem, apagar, regras, None),
                         [{"quarto": "Q7", "data": "2026-12-07", "regra": 1, "item": 2}])
        com, apagar2 = pace.ajustar_tetos(c, [dict(it)], regras, {"Q7": cal}, hoje=HOJE, janelas=jan["alvos"])
        self.assertEqual(pace.riscos_sem_janelas(c, com, apagar2, regras, jan["alvos"]), [])
        self.assertEqual(pace.riscos_sem_janelas(cfg(ativa=False), sem, apagar, regras, None), [])
        # regra apagada com a estadia da regra (2): nada a avisar
        regras2 = {"Q7": {"2026-12-07": dict(regras["Q7"]["2026-12-07"], min_stay="2")}}
        sem2, ap2 = pace.ajustar_tetos(c, [dict(it)], regras2, {"Q7": cal}, hoje=HOJE)
        self.assertEqual(pace.riscos_sem_janelas(c, sem2, ap2, regras2, None), [])

    def test_sabado_b2(self):
        c = cfg()
        inv = cen_b()
        jan = janelas({"VK2": inv})
        reg = {"date": "2026-12-12", "min_stay": "2", "max_price": "10", "min_price": "-20"}
        cal = {"2026-12-12": {"min_stay": "2", "mu_occupancy": "0/2", "price": "1500", "min_price": "1000"},
               "2026-12-11": {"min_stay": "2", "mu_occupancy": "1/2", "price": "1500", "min_price": "1000"}}
        it = {"tipo": "data", "quarto": "VK2", "data": "2026-12-12", "min_price": 2000,
              "max_price": float(c["por_curto"]["VK2"]["max"]), "motivo": "pace: descida do dia"}
        sem, _ = pace.ajustar_tetos(c, [dict(it)], {"VK2": {"2026-12-12": reg}}, {"VK2": cal}, hoje=HOJE)
        self.assertEqual(sem[0]["min_stay"], 2)
        com, _ = pace.ajustar_tetos(c, [dict(it)], {"VK2": {"2026-12-12": reg}}, {"VK2": cal}, hoje=HOJE,
                                    janelas=jan["alvos"])
        self.assertEqual((com[0]["min_stay"], com[0]["janela"]), (1, "sábado"))


class TestMain(unittest.TestCase):
    """16: a linha de comando com --inventario e --janelas-json; --sem-janelas e `ativa` falso voltam à regra."""

    def gravar(self, tmp, inv, quartos):
        arq_inv = os.path.join(tmp, "inv.json")
        with open(arq_inv, "w", encoding="utf-8") as f:
            json.dump(dict(inv, _lido_em=LIDO), f)
        recs = []
        for q in quartos:
            arq = os.path.join(tmp, f"rec_{q}.json")
            with open(arq, "w", encoding="utf-8") as f:
                json.dump({"data": {"pricing_array": [{"date": k, **v} for k, v in recalculo(inv[q]).items()]}}, f)
            recs.append(f"{q}={arq}")
        return arq_inv, recs

    def rodar(self, tmp, args, cfg_bruta=None):
        with T.quartos_em_arquivo(cfg_bruta), contextlib.redirect_stdout(io.StringIO()) as out:
            rc = c12.main(args)
        self.assertEqual(rc, 0, out.getvalue())
        return out.getvalue()

    def ler(self, p):
        with open(p, encoding="utf-8") as f:
            return json.load(f)

    def test_auditar_e_so_estadia_com_janelas(self):
        inv = {"Q7": cen_a(), "VK7": cen_d(), "VK2": cen_c()}
        with tempfile.TemporaryDirectory() as tmp:
            arq_inv, recs = self.gravar(tmp, inv, inv)
            p = lambda n: os.path.join(tmp, n)  # noqa: E731
            base = ["--hoje", ds(HOJE), "--inventario", arq_inv] + recs
            bruta = T.bruta()
            bruta["parametros"]["vender_janelas"] = dict(PAR)
            out = self.rodar(tmp, ["auditar", "--ate", ds(FIM - UM), "--janelas-json", p("jan.json"), "--lote-unico",
                                   p("unico.json"), "--json", p("aud.json")] + base, bruta)
            self.assertIn("Janelas (dono 16/11): 3 a abrir", out)
            jan = self.ler(p("jan.json"))
            self.assertEqual(sorted((x["quarto"], x["noite"], x["codigo"]) for x in jan["abrir"]),
                             [("Q7", "2026-12-07", "a"), ("VK2", "2026-12-12", "b1"), ("VK7", "2026-12-11", "c")])
            self.assertIn("vender_janelas", self.ler(p("aud.json")))
            unico = self.ler(p("unico.json"))
            jan_lote = sorted((i["quarto"], i["de"], i.get("min_stay"), i.get("override")) for i in unico if i.get("janela"))
            self.assertEqual(jan_lote, [("Q7", "2026-12-07", 1, None), ("VK2", "2026-12-12", None, "noCheckOut"),
                                        ("VK7", "2026-12-11", 1, None), ("VK7", "2026-12-12", None, "noCheckIn")])
            out = self.rodar(tmp, ["plano", "--so-estadia", "--de", ds(HOJE), "--ate", ds(FIM - UM), "--saida",
                                   p("plano.json"), "--janelas-json", p("jan2.json")] + base, bruta)
            plano = self.ler(p("plano.json"))
            ji = sorted((i["quarto"], i["data"], i["min_stay"]) for i in plano["itens"] if i.get("janela"))
            self.assertEqual(ji, [("Q7", "2026-12-07", 1), ("VK7", "2026-12-11", 1)])
            self.assertEqual(erros([i for i in plano["itens"] if i.get("janela")]), [])
            # --sem-janelas: nenhuma janela; a regra normal
            out = self.rodar(tmp, ["auditar", "--ate", ds(FIM - UM), "--sem-janelas", "--janelas-json", p("jan3.json"),
                                   "--lote-unico", p("unico3.json")] + base, bruta)
            self.assertIn("desligadas nesta linha", out)
            self.assertEqual(self.ler(p("jan3.json")), {"avaliadas": False, "alvos": {}})
            self.assertFalse([i for i in self.ler(p("unico3.json")) if i.get("janela")])
            # ativa falso = --sem-janelas
            bruta2 = T.bruta()
            bruta2["parametros"]["vender_janelas"] = dict(PAR, ativa=False)
            self.rodar(tmp, ["auditar", "--ate", ds(FIM - UM), "--lote-unico", p("unico4.json")] + base, bruta2)
            self.assertEqual(self.ler(p("unico4.json")), self.ler(p("unico3.json")))

    def test_auditar_com_inventario_curto_nao_fecha_janela(self):
        """Achado da verificação (revisão de 16/11, gravidade alta): a rotina leve roda o auditar com o inventário até
        hoje + 15; a (c) aberta em D11/D12 ficava sem cobertura e o lote a fechava (sexta 1 -> 2, sábado noCheckIn ->
        noCheckInOrCheckOut, também no PriceLabs). Agora fica, com AVISO; com --ate hoje + 8 nada fica sem cobertura."""
        aberto = cen_d(**{"2026-12-11": {"numAvail": 5, "minStay": 1}, "2026-12-12": {"numAvail": 1, "override": "noCheckIn"}})
        inv = {"VK7": {k: v for k, v in aberto.items() if D.fromisoformat(k) <= HOJE + 15 * UM}}
        with tempfile.TemporaryDirectory() as tmp:
            arq_inv, recs = self.gravar(tmp, inv, inv)
            p = lambda n: os.path.join(tmp, n)  # noqa: E731
            bruta = T.bruta()
            bruta["parametros"]["vender_janelas"] = dict(PAR)
            out = self.rodar(tmp, ["auditar", "--hoje", ds(HOJE), "--inventario", arq_inv, "--janelas-json",
                                   p("jan.json"), "--lote-unico", p("unico.json"), "--plano-beds24", p("lote.json")]
                             + recs, bruta)
            self.assertIn("noite(s) de quarto sem cobertura do inventário (de 2026-12-09 a 2026-12-15)", out)
            self.assertIn("sem cobertura (VK7, janela não avaliada): fica como está", out)
            for arq in ("unico.json", "lote.json"):
                self.assertFalse([i for i in self.ler(p(arq)) if i["de"] in ("2026-12-11", "2026-12-12")], arq)
            # a linha da rotina leve com --ate hoje + 8: tudo coberto, nenhum aviso
            out = self.rodar(tmp, ["auditar", "--hoje", ds(HOJE), "--ate", ds(HOJE + 8 * UM), "--inventario", arq_inv,
                                   "--lote-unico", p("unico8.json")] + recs, bruta)
            self.assertNotIn("sem cobertura do inventário (de", out)
            self.assertFalse([i for i in self.ler(p("unico8.json")) if i["de"] in ("2026-12-11", "2026-12-12")])

    def test_aviso_sem_inventario(self):
        inv = {"Q7": cen_a()}
        with tempfile.TemporaryDirectory() as tmp:
            _, recs = self.gravar(tmp, inv, inv)
            bruta = T.bruta()
            bruta["parametros"]["vender_janelas"] = dict(PAR)
            out = self.rodar(tmp, ["auditar", "--hoje", ds(HOJE), "--ate", ds(D(2026, 12, 8)), "--sem-beds24"] + recs,
                             bruta)
            self.assertIn("AVISO: sem inventário do Beds24, as janelas (dono 16/11) não se avaliam", out)

    def test_relatorio(self):
        inv = {"VK7": cen_e(), "Q7": cen_a()}
        with tempfile.TemporaryDirectory() as tmp:
            arq_inv, _ = self.gravar(tmp, inv, [])
            bruta = T.bruta()
            bruta["parametros"]["vender_janelas"] = dict(PAR)
            ant = os.path.join(tmp, "ant.json")
            # revisão de 16/11: VK2 fora desta leitura (não reavaliada), Q7 08/12 avaliada e sem janela (fechada),
            # Q7 29/11 já passou (não entra em nenhuma das duas)
            with open(ant, "w", encoding="utf-8") as f:
                json.dump({"abrir": [], "mantidas": [{"quarto": "VK2", "noite": "2026-12-12"},
                                                     {"quarto": "Q7", "noite": "2026-12-08"},
                                                     {"quarto": "Q7", "noite": "2026-11-29"}]}, f)
            with T.quartos_em_arquivo(bruta), contextlib.redirect_stdout(io.StringIO()) as out:
                rc = VJ.main(["relatorio", "--hoje", ds(HOJE), "--inventario", arq_inv, "--json",
                              os.path.join(tmp, "r.json"), "--anterior", ant])
            self.assertEqual(rc, 0)
            txt = out.getvalue()
            self.assertIn("A abrir:", txt)
            self.assertIn("A observar:", txt)
            self.assertIn("Fechadas nesta rodada (voltam à regra): 1: Q7 2026-12-08\n", txt)
            self.assertIn("Não reavaliadas nesta rodada (sem cobertura do inventário ou fora do horizonte; a regra não "
                          "as fecha): 1: VK2 2026-12-12\n", txt)
            self.assertNotIn("2026-11-29", txt)


if __name__ == "__main__":
    unittest.main()

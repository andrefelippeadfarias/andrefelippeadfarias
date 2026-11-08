"""Testes da correção de 07/11 no calendario_12m (sábado de hoje com a sexta já passada) e do lote único do Beds24 (plano
de redução de chamadas de 06/11, passo 2(d)). Rodar na pasta scripts/: python3 -m unittest test_calendario_sabado

Dados sintéticos dentro do teste: inventário do Beds24 e recálculo do PriceLabs inventados, sem rede e sem arquivo
externo (o teste do main grava os seus num diretório temporário próprio). Sem nomes de hóspedes: nada aqui lê reserva.

Regra do dono (regras.md, Regra H; exceções de 02/11, "Pode fazer"): sábado sempre fechado para entrada e saída, salvo o
lado órfão; com a sexta esgotada e o sábado livre, o sábado fica noCheckOut e leva estadia 1. Em 07/11 a Panorâmica II
(Q2), a Panorâmica I (Double) e a Balcony tinham o sábado livre e seguiam noCheckInOrCheckOut: o inventário do Beds24
não traz noite passada e a auditoria não via a sexta 06/11. A sexta anterior a hoje não vende mais: conta como
esgotada para esse fim (na prática, só quando o sábado é hoje)."""

from __future__ import annotations

import contextlib
import copy
import datetime as dt
import io
import json
import os
import sys
import tempfile
import unittest

AQUI = os.path.dirname(os.path.abspath(__file__))
if AQUI not in sys.path:
    sys.path.insert(0, AQUI)

import calendario_12m as c12  # noqa: E402
from comum import carregar_quartos  # noqa: E402
from ordens import carregar_ordens  # noqa: E402
from validar_plano import Validador, checar_prova_orfa  # noqa: E402

D = dt.date
H = D(2026, 11, 7)                       # sábado de hoje (a sexta 06/11 já passou)
SEXTA = "2026-11-06"
SAB = "2026-11-07"
LIDO = "2026-11-07T10:40:00+00:00"       # 07:40 BRT, a leitura do inventário da rotina
F = "noCheckInOrCheckOut"
ORDEM_ORFA = "dono-2026-11-02-sabado-orfao"


def cfg():
    return copy.deepcopy(carregar_quartos())


def av(n, ms=2, ov=F):
    """Uma noite do inventário do Beds24 (beds24.inventario)."""
    return {"numAvail": n, "minStay": ms, "maxStay": 365, "override": ov}


def noite(ms, mu, preco="5000"):
    """Uma noite do recálculo do PriceLabs (piso alto: o plano completo não mexe no piso)."""
    return {"min_price": preco, "min_stay": str(ms), "price": preco, "mu_occupancy": mu}


def recalculo(de: dt.date, ate: dt.date, ms_sabado=2, mu="1/6") -> dict:
    cal, d = {}, de
    while d <= ate:
        ms = ms_sabado if d.weekday() == 5 else (2 if d.weekday() in (0, 2, 4) else 1)
        cal[d.isoformat()] = noite(ms, mu)
        d += dt.timedelta(days=1)
    return cal


def erros(itens, hoje=H, precos=None, c=None):
    v = Validador(c or cfg(), hoje, dict(precos or {}), ordens=carregar_ordens())
    v.validar(itens)
    return v.erros


def classif(inv, hoje=H, de=H, ate=None, c=None):
    return {x["quarto"]: x for x in c12.classificar_beds24(c or cfg(), inv, de, ate or de + dt.timedelta(days=30),
                                                            hoje=hoje, lido_em=LIDO)}


def chave(it):
    return json.dumps({k: it.get(k) for k in c12.CAMPOS_ITEM_LOTE}, sort_keys=True, ensure_ascii=False)


def gravar(caminho, obj):
    with open(caminho, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False)


def ler(caminho):
    with open(caminho, encoding="utf-8") as f:
        return json.load(f)


class TestSabadoDeHojeComSextaPassada(unittest.TestCase):
    """Sábado = hoje, sexta passada (fora do inventário) e noite livre: noCheckOut e estadia 1, com prova aceita."""

    INV = {"Q2": {SAB: av(1), "2026-11-08": av(2, 1, "none")},
           "Double": {SAB: av(3)},
           "Balcony": {SAB: av(2)}}

    def test_beds24_noCheckOut_e_estadia_1(self):
        cl = classif(self.INV)
        for q, n in (("Q2", 1), ("Double", 3), ("Balcony", 2)):
            ch = cl[q]["sabados_fora_da_regra"]
            self.assertEqual([(s["data"], s["override"], s["troca"]) for s in ch], [(SAB, F, "noCheckOut")], q)
            self.assertIn("06/11 já passou", ch[0]["motivo"])
            self.assertEqual(ch[0]["orfa"], "sábado")
            prova = ch[0]["orfa_prova"]
            self.assertEqual(prova, {"fonte": "beds24", "unidades": cfg()["por_curto"][q]["unidades"],
                                     "noite_numAvail": n, "anterior_numAvail": None, "lido_em": LIDO,
                                     "anterior_passada": SEXTA})
            oe = cl[q]["orfas_estadia"]
            self.assertEqual(len(oe), 1)
            self.assertEqual({k: oe[0][k] for k in ("data", "min_stay", "alvo", "lado", "orfa")},
                             {"data": SAB, "min_stay": 2, "alvo": 1, "lado": "sábado", "orfa": "sábado"})
            self.assertEqual(oe[0]["orfa_prova"], prova)
            self.assertIn("já passou", oe[0]["motivo"])
            self.assertIn(ORDEM_ORFA, oe[0]["motivo"])
            self.assertEqual(cl[q]["sabados_estadia_abaixo"], [])

    def test_lote_do_beds24_leva_entrada_e_estadia(self):
        cl = classif(self.INV)
        lote = c12.plano_beds24_sabados(list(cl.values()), "base")
        q2 = [i for i in lote if i["quarto"] == "Q2" and i["de"] == SAB]
        self.assertEqual(len(q2), 2)
        ov, est = (next(i for i in q2 if "override" in i), next(i for i in q2 if "min_stay" in i))
        self.assertEqual((ov["override"], ov["orfa"]), ("noCheckOut", "sábado"))
        self.assertEqual(ov["orfa_prova"]["anterior_passada"], SEXTA)
        self.assertIn("já passou", ov["motivo"])
        self.assertEqual((est["min_stay"], est["min_stay_antes"], est["pricelabs_tambem"], est["orfa"]),
                         (1, 2, True, "sábado"))
        self.assertNotIn("override", est)
        self.assertIn("já passou", est["motivo"])
        # o lote só de estadia (--so-estadia, --lotes-estadia) leva a estadia 1 do sábado de hoje, no urgente
        so = c12.lote_estadia_dia_chegada(list(cl.values()))
        self.assertIn((("Q2", SAB, 1)), [(i["quarto"], i["de"], i["min_stay"]) for i in so])
        lotes = c12.lotes_estadia(cfg(), list(cl.values()), H)
        self.assertIn(SAB, [i["de"] for i in lotes["recanto_urgente"] if i["quarto"] == "Q2"])
        self.assertIn(SAB, [i["de"] for i in lotes["villa_urgente"] if i["quarto"] == "Balcony"])

    def test_sem_hoje_como_antes(self):
        cl = {x["quarto"]: x for x in c12.classificar_beds24(cfg(), self.INV, H, H + dt.timedelta(days=30))}
        for q in self.INV:
            self.assertEqual(cl[q]["sabados_fora_da_regra"], [], q)
            self.assertEqual(cl[q]["orfas_estadia"], [], q)

    def test_sabado_ja_aberto_e_com_1(self):
        # o sábado já está noCheckOut e com 1 (gravado mais cedo): nada a fazer, e não é falta
        cl = classif({"Q2": {SAB: av(1, 1, "noCheckOut")}})
        self.assertEqual((cl["Q2"]["sabados_fora_da_regra"], cl["Q2"]["orfas_estadia"], cl["Q2"]["sabados_estadia_abaixo"]),
                         ([], [], []))

    def test_validador_aceita_a_prova_do_beds24(self):
        oe = classif(self.INV)["Q2"]["orfas_estadia"][0]
        it = {"tipo": "data", "quarto": "Q2", "data": SAB, "min_stay": 1, "orfa": oe["orfa"],
              "orfa_prova": oe["orfa_prova"], "motivo": oe["motivo"]}
        self.assertEqual(erros([it]), [])
        # o calendário de --precos com a sexta livre não desmente: a sexta já passou e não vende mais
        precos = {("Q2", SEXTA): {"livres": 1, "vendidas": 1, "total": 2},
                  ("Q2", SAB): {"livres": 1, "vendidas": 1, "total": 2}, "_meta": {"idade_h": {"Q2": 1.0}}}
        self.assertEqual(erros([it], precos=precos), [])
        # mas o sábado esgotado no calendário desmente
        precos[("Q2", SAB)] = {"livres": 0, "vendidas": 2, "total": 2}
        self.assertTrue(any("desmente" in e for e in erros([it], precos=precos)))

    def test_validador_barra_sem_prova_e_prova_errada(self):
        p = classif(self.INV)["Q2"]["orfas_estadia"][0]["orfa_prova"]
        base = {"tipo": "data", "quarto": "Q2", "data": SAB, "min_stay": 1, "orfa": "sábado", "motivo": "teste"}
        # sábado órfão sem prova: continua barrado
        self.assertTrue(erros([base]))
        casos = {
            "sexta ainda não passou": (dict(p), D(2026, 11, 6)),                      # validado na sexta
            "sábado livre": (dict(p, noite_numAvail=0), H),
            "lido_em velho": (dict(p, lido_em="2026-11-04T10:00:00+00:00"), H),
            "sexta de outro sábado": (dict(p, anterior_passada="2026-10-30"), H),
            "sem lido_em": ({k: v for k, v in p.items() if k != "lido_em"}, H),
        }
        for nome, (prova, hoje) in casos.items():
            self.assertTrue(erros([dict(base, orfa_prova=prova)], hoje=hoje), nome)
        # sábado futuro com a "sexta passada": barrado (a sexta 13/11 não passou em 07/11)
        fut = dict(base, data="2026-11-14", orfa_prova=dict(p, anterior_passada="2026-11-13"))
        self.assertTrue(any("ainda não passou" in e for e in erros([fut])))
        # a função direto: a sexta passada só vale com a noite (o sábado do item)
        self.assertIsNotNone(checar_prova_orfa(p, "anterior", H))
        self.assertIsNone(checar_prova_orfa(p, "anterior", H, noite=H))
        # a noite órfã pela seguinte não aceita anterior_passada no lugar da seguinte esgotada
        self.assertIsNotNone(checar_prova_orfa(dict(p, seguinte_numAvail=None), "seguinte", H, noite=H))

    def test_plano_so_estadia_e_completo(self):
        c = cfg()
        cal = recalculo(H, H + dt.timedelta(days=3))
        inv = {SAB: av(1), "2026-11-08": av(2, 1, "none"), "2026-11-09": av(2, 2, "none"), "2026-11-10": av(2, 1, "none"),
               "2026-11-11": av(2, 2, "none")}
        ordens = carregar_ordens()
        for so in (True, False):
            dif = {x["data"]: x for x in c12.comparar(c, "Q2", cal, H, H, ordens, so_estadia=so, inv=inv, lido_em=LIDO,
                                                       hoje=H)}
            x = dif[SAB]
            self.assertEqual((x["item"]["min_stay"], x["item"]["orfa"], x["tipo_estadia"]), (1, "sábado", "sábado órfão"))
            self.assertEqual(x["item"]["orfa_prova"]["anterior_passada"], SEXTA)
            self.assertTrue(any("06/11 já passou" in f for f in x["falta"]))
            plano = c12.montar_plano(list(dif.values()), H)
            (it,) = [i for i in plano["itens"] if i["data"] == SAB]
            self.assertIn("já passou", it["motivo"])
            self.assertNotIn("min_price", it)
            self.assertEqual(erros(plano["itens"]), [], so)
        # sem hoje (como antes): o sábado com 2 fica
        dif = c12.comparar(c, "Q2", cal, H, H, ordens, so_estadia=True, inv=inv, lido_em=LIDO)
        self.assertFalse([x for x in dif if x["data"] == SAB and x.get("item")])
        # já com 1: informação, não falta
        cal1 = dict(cal, **{SAB: noite(1, "1/2")})
        (x,) = [y for y in c12.comparar(c, "Q2", cal1, H, H, ordens, so_estadia=True, inv=inv, lido_em=LIDO, hoje=H)
                if y["data"] == SAB]
        self.assertEqual(x["falta"], [])
        self.assertIn("sábado órfão (a sexta 06/11 já passou)", x["info"][0])

    def test_plano_pelo_recalculo_sem_inventario(self):
        cal = {SAB: noite(2, "1/2"), "2026-11-08": noite(1, "0/2")}
        (x,) = [y for y in c12.comparar(cfg(), "Q2", cal, H, H, carregar_ordens(), so_estadia=True, hoje=H)
                if y["data"] == SAB]
        prova = x["item"]["orfa_prova"]
        self.assertEqual(prova, {"noite_vendidas": 1, "noite_total": 2, "anterior_vendidas": None,
                                 "anterior_total": None, "anterior_passada": SEXTA})
        self.assertEqual(erros(c12.montar_plano([x], H)["itens"]), [])
        # a prova que o pace.ajustar_tetos pede (prova_sabado_orfao): só com hoje; o caminho do pace em TestPaceSabadoDeHoje
        self.assertIsNone(c12.prova_sabado_orfao(cfg(), "Q2", H, cal))
        self.assertEqual(c12.prova_sabado_orfao(cfg(), "Q2", H, cal, hoje=H), prova)


class TestSabadoFuturoESemVenda(unittest.TestCase):
    def test_sabado_futuro_com_sexta_livre_segue_fechado(self):
        inv = {"Q2": {"2026-11-13": av(1, 2, "none"), "2026-11-14": av(1, 2, "none")},
               "Double": {"2026-11-13": av(2, 2, "none"), "2026-11-14": av(3, 2, F)}}
        cl = classif(inv)
        self.assertEqual([(s["data"], s["troca"]) for s in cl["Q2"]["sabados_fora_da_regra"]], [("2026-11-14", F)])
        self.assertNotIn("orfa", cl["Q2"]["sabados_fora_da_regra"][0])
        self.assertEqual(cl["Double"]["sabados_fora_da_regra"], [])
        for q in inv:
            self.assertEqual(cl[q]["orfas_estadia"], [], q)
        d = D(2026, 11, 14)
        self.assertIsNone(c12.prova_sabado_orfao(cfg(), "Q2", d, None, inv["Q2"], LIDO, hoje=H))
        cal = {"2026-11-13": noite(2, "1/2"), "2026-11-14": noite(2, "1/2"), "2026-11-15": noite(1, "0/2")}
        dif = c12.comparar(cfg(), "Q2", cal, d, d, carregar_ordens(), so_estadia=True, inv=inv["Q2"], lido_em=LIDO, hoje=H)
        self.assertFalse([x for x in dif if x.get("item")])
        # o sábado órfão futuro de sempre (sexta esgotada) segue igual: sem prova nem lote, só em orfas_estadia
        inv2 = {"Double": {"2026-11-13": av(0, 2, "none"), "2026-11-14": av(3, 2, F)}}
        cl2 = classif(inv2)
        self.assertEqual(cl2["Double"]["orfas_estadia"], [{"data": "2026-11-14", "min_stay": 2, "alvo": 1, "lado": "sábado"}])
        self.assertNotIn("orfa", cl2["Double"]["sabados_fora_da_regra"][0])
        self.assertEqual(c12.lote_sabado_orfao_hoje(list(cl2.values())), [])

    def test_sabado_de_hoje_sem_noite_livre(self):
        inv = {"Q2": {SAB: av(0)}, "Double": {SAB: av(-1)}}
        cl = classif(inv)
        for q in inv:
            self.assertEqual((cl[q]["sabados_fora_da_regra"], cl[q]["orfas_estadia"], cl[q]["sabados_estadia_abaixo"]),
                             ([], [], []), q)
        cal = {SAB: noite(2, "2/2"), "2026-11-08": noite(1, "0/2")}
        dif = c12.comparar(cfg(), "Q2", cal, H, H, carregar_ordens(), so_estadia=True, inv=inv["Q2"], lido_em=LIDO, hoje=H)
        self.assertFalse([x for x in dif if x.get("item")])
        self.assertEqual(c12.lote_unico(cfg(), list(cl.values()), H), [])

    def test_sabado_de_hoje_em_festa_e_pacote(self):
        ordens = carregar_ordens()
        for hoje, ov in ((D(2026, 12, 26), "none"), (D(2027, 1, 2), F)):   # sábado de festa; pacote do Ano Novo
            ds = hoje.isoformat()
            cl = classif({"VK7": {ds: av(3, 2, ov)}}, hoje=hoje, de=hoje, ate=hoje)
            self.assertEqual((cl["VK7"]["sabados_fora_da_regra"], cl["VK7"]["orfas_estadia"]), ([], []), ds)
            # mesmo com o override fora do alvo, o item é o da festa ou do pacote, sem a órfã
            cl = classif({"VK7": {ds: av(3, 2, "noCheckOut")}}, hoje=hoje, de=hoje, ate=hoje)
            self.assertTrue(all("orfa" not in s for s in cl["VK7"]["sabados_fora_da_regra"]), ds)
            self.assertEqual(cl["VK7"]["orfas_estadia"], [], ds)
            self.assertEqual(c12.lote_sabado_orfao_hoje(list(cl.values())), [], ds)
            cal = {ds: noite(2, "1/7"), (hoje + dt.timedelta(days=1)).isoformat(): noite(1, "0/7")}
            dif = c12.comparar(cfg(), "VK7", cal, hoje, hoje, ordens, so_estadia=True, inv={ds: av(3)}, lido_em=LIDO,
                               hoje=hoje)
            self.assertFalse([x for x in dif if (x.get("item") or {}).get("orfa")], ds)
            self.assertIsNone(c12.prova_sabado_orfao(cfg(), "VK7", hoje, cal, hoje=hoje), ds)
            # o validador barra a órfã do sábado nessas datas, mesmo com a prova da sexta passada
            prova = {"fonte": "beds24", "unidades": 7, "noite_numAvail": 3, "anterior_numAvail": None,
                     "lido_em": f"{ds}T10:40:00+00:00", "anterior_passada": (hoje - dt.timedelta(days=1)).isoformat()}
            it = {"tipo": "data", "quarto": "VK7", "data": ds, "min_stay": 1, "orfa": "sábado", "orfa_prova": prova,
                  "motivo": "teste"}
            self.assertTrue(erros([it], hoje=hoje), ds)


class TestLoteUnico(unittest.TestCase):
    """Plano de 06/11, passo 2(d): um só lote do Beds24 por rodada, urgente (hoje a hoje + 14) primeiro, com os mesmos
    itens do --plano-beds24 e do --lotes-estadia, sem repetir item."""

    INV = {
        "Q2": {SAB: av(1), "2026-11-09": av(2, 1, "none"), "2026-11-10": av(2, 2, "none"),     # seg 1→2, ter 2→1
               "2026-11-14": av(2, 2, "none"), "2026-12-01": av(2, 2, "none"), "2026-12-07": av(2, 1, "none")},
        "Double": {SAB: av(3), "2026-11-12": av(3, 2, "none"), "2026-12-12": av(3, 2, "noCheckIn"),
                   "2026-12-02": av(2, 1, "none")},
        "Balcony": {SAB: av(2), "2026-11-16": av(2, 1, "none"), "2027-02-03": av(2, 1, "none")},
        "VK7": {"2026-11-28": av(3, 2, "none"), "2027-03-01": av(3, 1, "none")},
    }

    def _cl(self):
        return list(classif(self.INV, ate=D(2027, 6, 30)).values())

    def test_mesmos_itens_sem_repetir_urgente_primeiro(self):
        c, cl = cfg(), self._cl()
        lote = c12.lote_unico(c, cl, H)
        separados = c12.lote_beds24(cl) + [i for l in c12.lotes_estadia(c, cl, H).values() for i in l]
        self.assertEqual({chave(i) for i in lote}, {chave(i) for i in separados})
        self.assertEqual(len(lote), len({chave(i) for i in lote}))           # nenhum item duas vezes
        self.assertLess(len(lote), len(separados))                           # a estadia saía nos dois
        limite = H + dt.timedelta(days=14)
        urg = [D.fromisoformat(i["de"]) <= limite for i in lote]
        self.assertTrue(any(urg) and not all(urg))
        self.assertEqual(urg, sorted(urg, reverse=True))                     # todo urgente antes do resto
        datas_urg = [i["de"] for i in lote if D.fromisoformat(i["de"]) <= limite]
        self.assertEqual(datas_urg, sorted(datas_urg))                       # o urgente por data: D0 primeiro
        self.assertEqual(lote[0]["de"], SAB)
        # o sábado de hoje: entrada (override) antes da estadia, no mesmo quarto
        q2 = [i for i in lote if i["quarto"] == "Q2" and i["de"] == SAB]
        self.assertEqual([("override" in i, i.get("min_stay")) for i in q2], [(True, None), (False, 1)])
        # o item que só um lado marca com pricelabs_tambem fica com ele
        self.assertTrue(all(i.get("pricelabs_tambem") for i in lote if "min_stay" in i))

    def test_so_estadia(self):
        c, cl = cfg(), self._cl()
        lote = c12.lote_unico(c, cl, H, so_estadia=True)
        est = c12.lote_estadia_dia_chegada(cl)
        self.assertEqual(sorted(chave(i) for i in lote), sorted(chave(i) for i in est))
        self.assertFalse([i for i in lote if "override" in i])
        self.assertEqual(lote[0]["de"], SAB)

    def test_formato_do_editar_lote(self):
        import beds24
        for it in c12.lote_unico(cfg(), self._cl(), H):
            self.assertTrue({"quarto", "de", "ate", "motivo"} <= set(it), it)
            beds24.corpo_edicao(it["quarto"], it["de"], it["ate"], it.get("min_stay"), it.get("override"),
                                it.get("num_avail"), it.get("max_stay"), it.get("canais_max"))
            self.assertNotIn("price", json.dumps(it).replace("pricelabs_tambem", ""))

    def test_main_lote_unico_igual_aos_lotes_separados(self):
        """O main com --lote-unico grava os mesmos itens dos arquivos de sempre (que seguem iguais), em sombra."""
        quartos = ("Q2", "Double", "Balcony", "VK7")
        ate = D(2026, 12, 15)
        with tempfile.TemporaryDirectory() as tmp:
            inv = {q: {k: v for k, v in self.INV[q].items() if D.fromisoformat(k) <= ate + dt.timedelta(days=1)}
                   for q in quartos}
            arq_inv = os.path.join(tmp, "inv.json")
            gravar(arq_inv, dict(inv, _lido_em=LIDO))
            recs = []
            for q in quartos:
                arq = os.path.join(tmp, f"rec_{q}.json")
                gravar(arq, {"data": {"pricing_array": [{"date": k, **v} for k, v in recalculo(H, ate).items()]}})
                recs.append(f"{q}={arq}")
            p = lambda n: os.path.join(tmp, n)  # noqa: E731
            comum_args = ["--hoje", H.isoformat(), "--de", H.isoformat(), "--ate", ate.isoformat(),
                          "--inventario", arq_inv] + recs
            with contextlib.redirect_stdout(io.StringIO()) as out:
                self.assertEqual(c12.main(["auditar"] + comum_args + [
                    "--plano-beds24", p("lote.json"), "--lotes-estadia", p("lote_b24"), "--lote-unico", p("unico.json")]), 0)
                self.assertEqual(c12.main(["plano", "--so-estadia"] + comum_args + [
                    "--plano-beds24", p("lote_so.json"), "--lotes-estadia", p("so_b24"), "--lote-unico", p("unico_so.json"),
                    "--saida", p("plano.json")]), 0)
            self.assertIn("lote único do Beds24", out.getvalue())
            separados = ler(p("lote.json")) + [i for n in sorted(os.listdir(tmp)) if n.startswith("lote_b24_")
                                               for i in ler(p(n))]
            unico = ler(p("unico.json"))
            self.assertEqual({chave(i) for i in unico}, {chave(i) for i in separados})
            self.assertEqual(len(unico), len({chave(i) for i in unico}))
            self.assertEqual(unico[0]["de"], SAB)
            self.assertTrue(os.path.exists(p("lote_b24_recanto_urgente.json")))   # os arquivos antigos continuam
            so = ler(p("lote_so.json")) + [i for n in os.listdir(tmp) if n.startswith("so_b24_") for i in ler(p(n))]
            self.assertEqual({chave(i) for i in ler(p("unico_so.json"))}, {chave(i) for i in so})
            # o plano do PriceLabs do --so-estadia leva o sábado de hoje com a prova aceita
            plano = ler(p("plano.json"))
            sab = [i for i in plano["itens"] if i["data"] == SAB]
            self.assertEqual(sorted((i["quarto"], i["min_stay"], i["orfa"]) for i in sab),
                             [("Balcony", 1, "sábado"), ("Double", 1, "sábado"), ("Q2", 1, "sábado")])
            self.assertEqual(erros(sab), [])

    def test_main_sem_beds24_avisa(self):
        with tempfile.TemporaryDirectory() as tmp:
            arq = os.path.join(tmp, "rec.json")
            gravar(arq, {"data": {"pricing_array": [{"date": SAB, **noite(2, "1/2")}]}})
            with contextlib.redirect_stdout(io.StringIO()) as out:
                c12.main(["auditar", "--hoje", SAB, "--de", SAB, "--ate", SAB, "--sem-beds24",
                          "--lote-unico", os.path.join(tmp, "u.json"), f"Q2={arq}"])
            self.assertIn("AVISO: --lote-unico com --sem-beds24", out.getvalue())
            self.assertFalse(os.path.exists(os.path.join(tmp, "u.json")))


class TestPaceSabadoDeHoje(unittest.TestCase):
    """Achado da revisão de 07/11: a prova do sábado de hoje com a sexta passada não chegava ao pace (quando a descida
    do dia soltava o teto do sábado de hoje, a estadia 1 voltava a 2 e o envio levava o 2 ao Beds24). Corrigido na
    conferência final de 07/11: o pace.ajustar_tetos recebe `hoje` e o repassa ao prova_sabado_orfao. O teste roda
    sempre: tirar o `hoje` do ajustar_tetos é falha, não teste pulado."""

    # recálculo da manhã de 07/11: o sábado com 1 de 2 vendido; a sexta 06/11 já passou e não vem
    REC = {SAB: noite(1, "1/2", "1200"), "2026-11-08": noite(1, "0/2", "1200")}
    # a regra por data do sábado, gravada pela correção com estadia 1, com teto (da escada)
    REG = {"date": SAB, "min_stay": "1", "max_price": "10", "min_price": "-20"}

    def item(self, c, ds=SAB):
        """A descida de preço do dia no pace: max_price no máximo do anúncio solta o teto da regra."""
        return {"tipo": "data", "quarto": "Q2", "data": ds, "min_price": 2000,
                "max_price": float(c["por_curto"]["Q2"]["max"]), "motivo": "pace: descida do dia"}

    def test_item_do_pace_com_a_prova_passa_no_validador(self):
        c = cfg()
        prova = c12.prova_sabado_orfao(c, "Q2", H, self.REC, hoje=H)
        self.assertEqual(prova, {"noite_vendidas": 1, "noite_total": 2, "anterior_vendidas": None,
                                 "anterior_total": None, "anterior_passada": SEXTA})
        it = {k: v for k, v in self.item(c).items() if k != "max_price"}
        self.assertEqual(erros([dict(it, min_stay=1, orfa="sábado", orfa_prova=prova)]), [])
        # sem a prova, o sábado com 1 continua barrado
        self.assertTrue(erros([dict(it, min_stay=1, orfa="sábado")]))

    def test_ajustar_tetos_mantem_1_no_sabado_de_hoje(self):
        import pace
        c = cfg()
        (x,), apagar = pace.ajustar_tetos(c, [self.item(c)], {"Q2": {SAB: self.REG}}, {"Q2": self.REC}, hoje=H)
        self.assertEqual([a["data"] for a in apagar], [SAB])                 # o teto sai antes de regravar o piso
        self.assertNotIn("max_price", x)
        self.assertEqual((x["min_stay"], x.get("orfa")), (1, "sábado"))
        self.assertEqual(x["orfa_prova"]["anterior_passada"], SEXTA)
        self.assertEqual(erros([x]), [])
        # sem hoje, como antes: volta a 2 (Regra H)
        (y,), _ = pace.ajustar_tetos(c, [self.item(c)], {"Q2": {SAB: self.REG}}, {"Q2": self.REC})
        self.assertEqual((y["min_stay"], y.get("orfa")), (2, None))
        # sábado de hoje esgotado: nada de órfão
        cheio = dict(self.REC, **{SAB: noite(1, "2/2", "1200")})
        (z,), _ = pace.ajustar_tetos(c, [self.item(c)], {"Q2": {SAB: self.REG}}, {"Q2": cheio}, hoje=H)
        self.assertEqual((z["min_stay"], z.get("orfa")), (2, None))
        # sábado futuro com a sexta livre: segue com 2
        fut = {"2026-11-13": noite(2, "1/2"), "2026-11-14": noite(1, "1/2")}
        (f,), _ = pace.ajustar_tetos(c, [self.item(c, "2026-11-14")], {"Q2": {"2026-11-14": dict(self.REG, date="2026-11-14")}},
                                     {"Q2": fut}, hoje=H)
        self.assertEqual((f["min_stay"], f.get("orfa")), (2, None))


if __name__ == "__main__":
    unittest.main()

"""Reservas abaixo do planejado (dono, 21/10): "Faça diariamente esse levantamento para não termos reservas abaixo do que
planejamos" e "Considere o valor de desconto nas plataformas".

Uso:
  python3 reservas_abaixo.py --desde AAAA-MM-DDTHH:MMZ [--nomes] [--json saida.json]
  python3 reservas_abaixo.py --desde AAAA-MM-DDTHH:MMZ --arquivo rodada.json --inventario inv.json   # sem nova leitura

Para cada reserva confirmada ou nova criada ou alterada desde `--desde` (Beds24), compara o valor por noite com o
esperado para o canal: price1 do Beds24 nas mesmas noites × fator do canal (o que de fato chega depois dos descontos
da plataforma: `parametros.fator_canal` em quartos.json). Abaixo de `parametros.alerta_reserva` (70%) do esperado,
vai para o topo do relatório com quem lançou (referer), a descrição da tarifa e, na Booking, o número da reserva.
Nomes de hóspedes só com --nomes, na tela, para o dono agir. Nunca vão para arquivo nem para o repositório.

Menos chamadas (passo 2 do plano de redução, 07/11): na rotina, esta avaliação roda dentro do `beds24.py rodada` (uma
leitura só para o movimento, o vigia e esta lista). --arquivo reaproveita a resposta crua salva do `rodada --json` (só
esse arquivo, com o desde dele até o --desde e o --desde até o lido_em; outro arquivo dá código 2; sem nomes, então
--nomes não mostra nada nesse modo) e --inventario usa o price1 do `beds24.py inventario --precos --json` da rotina: o
GET de preço só acontece para a noite que não estiver no arquivo. Inventário lido há mais de 2 h (de outra rotina) ou
sem _lido_em: aviso no stderr.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import beds24  # noqa: E402
from comum import carregar_quartos  # noqa: E402

PADRAO_FATOR = {"Recanto": {"booking": 0.49, "airbnb": 0.41, "direct": 1.0},
                "Villa": {"booking": 0.46, "airbnb": 0.31, "direct": 1.0}}


def precos(token, quartos, de, ate):
    cal = {}
    for p in beds24.paginas("/inventory/rooms/calendar", {"roomId": quartos, "startDate": de, "endDate": ate,
                                                          "includePrices": "true"}, token):
        for c in p.get("calendar", []):
            d, e = dt.date.fromisoformat(c["from"]), dt.date.fromisoformat(c["to"])
            while d <= e:
                cal[(str(p["roomId"]), d.isoformat())] = c.get("price1")
                d += dt.timedelta(days=1)
    return cal


def precos_do_inventario(inv):
    """{(roomId, data): price1} do `beds24.py inventario --precos --json` (só as noites com price1)."""
    cal = {}
    for curto, dias in (inv or {}).items():
        if curto not in beds24.ID_DO_CURTO or not isinstance(dias, dict):
            continue
        rid = str(beds24.ID_DO_CURTO[curto])
        for d, v in dias.items():
            if isinstance(v, dict) and v.get("price1") is not None:
                cal[(rid, d)] = v["price1"]
    return cal


def noites_das(reservas):
    """{(roomId, data)} das noites das reservas (departure exclusivo, como o avaliar)."""
    out = set()
    for b in reservas:
        chegada, n, _ = beds24.receita_por_noite(b)
        out.update((str(b.get("roomId")), (chegada + dt.timedelta(days=i)).isoformat()) for i in range(n))
    return out


def precos_para(novas, obter_token, inv=None):
    """(cal, info): price1 das noites das reservas. Sem inventário, o GET de sempre (quartos das reservas, da menor
    chegada à maior saída). Com o inventário (`--precos`), o price1 dele (o do início da rotina) e 1 GET só para as
    noites que faltarem nele, nos quartos e datas que faltarem. `obter_token` só é chamado se houver GET."""
    if not novas:
        return {}, {"fonte": "nenhuma reserva", "leituras": 0}
    if inv is None:
        de = min(b["arrival"] for b in novas)
        ate = max(b["departure"] for b in novas)
        return precos(obter_token(), sorted({str(b["roomId"]) for b in novas}), de, ate), {"fonte": "ao vivo",
                                                                                            "leituras": 1}
    cal = precos_do_inventario(inv)
    faltam = sorted(k for k in noites_das(novas) if cal.get(k) is None)
    if faltam:
        lido = precos(obter_token(), sorted({q for q, _ in faltam}), min(d for _, d in faltam), max(d for _, d in faltam))
        for k, v in lido.items():
            cal.setdefault(k, v)
    return cal, {"fonte": "inventário", "noites_fora_do_inventario": len(faltam), "leituras": 1 if faltam else 0}


def avaliar(reservas, cal, fatores, alerta):
    out = []
    for b in reservas:
        if b.get("status") not in ("confirmed", "new"):
            continue
        quarto = beds24.QUARTOS.get(str(b.get("roomId")), ("?",))[0]
        pousada = "Recanto" if str(b.get("propertyId")) == "350362" else "Villa"
        canal = (b.get("channel") or "direct").lower()
        canal = canal if canal in ("booking", "airbnb") else "direct"
        chegada, n_noites, total = beds24.receita_por_noite(b)
        noites = [(chegada + dt.timedelta(days=i)).isoformat() for i in range(n_noites)]
        p1 = [cal.get((str(b.get("roomId")), d)) for d in noites]
        if not total or any(x in (None, 0) for x in p1):
            continue
        esperado = sum(p1) * fatores[pousada][canal]
        razao = total / esperado
        if razao < alerta:
            out.append({"id": b["id"], "booking_id": b.get("apiReference") or None, "quarto": quarto, "canal": canal,
                        "chegada": noites[0], "noites": len(noites), "valor": round(total, 2),
                        "esperado": round(esperado, 2), "recebe_do_esperado": round(razao, 3),
                        "lancado_por": b.get("referer"), "tarifa": (b.get("rateDescription") or "")[:120],
                        "criada_em": b.get("bookingTime"), "_nome": " ".join(x for x in ((b.get("firstName") or "").strip(),
                                                                                         (b.get("lastName") or "").strip()) if x)})
    return sorted(out, key=lambda x: x["recebe_do_esperado"])


def imprimir(lista, alerta, nomes=False):
    """Resumo na tela (o mesmo do comando avulso e da `beds24.py rodada`). Nome do hóspede só com `nomes`."""
    for x in lista:
        nome = f" | {x['_nome']}" if nomes else ""
        print(f"{x['id']} | ref. no canal {x['booking_id'] or '-'}{nome} | {x['quarto']} {x['chegada']} {x['noites']}n | {x['canal']} | "
              f"R$ {x['valor']:.0f} de R$ {x['esperado']:.0f} esperados ({x['recebe_do_esperado']:.0%}) | por {x['lancado_por']}")
    print(f"{len(lista)} reserva(s) abaixo de {alerta:.0%} do esperado (o price1 é o de agora: se o preço subiu depois da"
          " reserva, a razão cai sem erro de lançamento; abaixo de 50% é quase sempre erro)")


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--desde", required=True, help="instante UTC, ex.: 2026-10-20T22:50:00Z")
    ap.add_argument("--nomes", action="store_true", help="mostra o nome do hóspede na tela (nunca em arquivo)")
    ap.add_argument("--json")
    ap.add_argument("--arquivo", help="resposta crua salva do beds24.py rodada --json (desde do arquivo <= --desde <= "
                                      "lido_em): sem nova leitura das reservas; outro arquivo dá código 2")
    ap.add_argument("--inventario", help="beds24.py inventario --precos --json: price1 por noite; GET de preço só da "
                                         "noite fora dele")
    a = ap.parse_args(argv)
    P = carregar_quartos().get("parametros", {})
    fatores, alerta = P.get("fator_canal") or PADRAO_FATOR, float(P.get("alerta_reserva", 0.70))
    tok = []  # token lido só quando precisa (com --arquivo e --inventario completos, nenhuma chamada)

    def obter_token():
        if not tok:
            tok.append(beds24.token_de_acesso())
        return tok[0]
    if a.arquivo:
        try:
            novas = [b for b in beds24.ler_reservas_arquivo(a.arquivo, desde=a.desde)
                     if b.get("status") in ("confirmed", "new") and beds24.alterada_desde(b, a.desde)]
        except beds24.ErroBeds24 as e:
            print(f"ERRO: {e}", file=sys.stderr)
            return 2
    else:
        novas = list(beds24.paginas("/bookings", {"propertyId": list(beds24.PROPRIEDADES), "modifiedFrom": a.desde,
                                                  "status": ["confirmed", "new"], "includeInvoiceItems": "true"},
                                    obter_token()))
    if not novas:
        print("[]")
        return 0
    inv = beds24._ler_json(a.inventario) if a.inventario else None
    aviso = beds24.aviso_inventario(inv, a.inventario) if a.inventario else None
    if aviso:
        print(f"AVISO: {aviso}", file=sys.stderr)
    cal, _ = precos_para(novas, obter_token, inv)
    lista = avaliar(novas, cal, fatores, alerta)
    publico = [{k: v for k, v in x.items() if k != "_nome"} for x in lista]
    if a.json:
        json.dump(publico, open(a.json, "w"), ensure_ascii=False, indent=1)
    imprimir(lista, alerta, a.nomes)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

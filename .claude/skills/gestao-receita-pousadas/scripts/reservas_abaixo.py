"""Reservas abaixo do planejado (dono, 21/10): "Faça diariamente esse levantamento para não termos reservas abaixo do que
planejamos" e "Considere o valor de desconto nas plataformas".

Uso:
  python3 reservas_abaixo.py --desde AAAA-MM-DDTHH:MMZ [--nomes] [--json saida.json]

Para cada reserva confirmada ou nova criada ou alterada desde `--desde` (Beds24), compara o valor por noite com o
esperado para o canal: price1 do Beds24 nas mesmas noites × fator do canal (o que de fato chega depois dos descontos
da plataforma: `parametros.fator_canal` em quartos.json). Abaixo de `parametros.alerta_reserva` (70%) do esperado,
vai para o topo do relatório com quem lançou (referer), a descrição da tarifa e, na Booking, o número da reserva.
Nomes de hóspedes só com --nomes, na tela, para o dono agir. Nunca vão para arquivo nem para o repositório.
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


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--desde", required=True, help="instante UTC, ex.: 2026-10-20T22:50:00Z")
    ap.add_argument("--nomes", action="store_true", help="mostra o nome do hóspede na tela (nunca em arquivo)")
    ap.add_argument("--json")
    a = ap.parse_args(argv)
    P = carregar_quartos().get("parametros", {})
    fatores, alerta = P.get("fator_canal") or PADRAO_FATOR, float(P.get("alerta_reserva", 0.70))
    tok = beds24.token_de_acesso()
    novas = list(beds24.paginas("/bookings", {"propertyId": list(beds24.PROPRIEDADES), "modifiedFrom": a.desde,
                                              "status": ["confirmed", "new"], "includeInvoiceItems": "true"}, tok))
    if not novas:
        print("[]")
        return 0
    de = min(b["arrival"] for b in novas)
    ate = max(b["departure"] for b in novas)
    cal = precos(tok, sorted({str(b["roomId"]) for b in novas}), de, ate)
    lista = avaliar(novas, cal, fatores, alerta)
    publico = [{k: v for k, v in x.items() if k != "_nome"} for x in lista]
    if a.json:
        json.dump(publico, open(a.json, "w"), ensure_ascii=False, indent=1)
    for x in lista:
        nome = f" | {x['_nome']}" if a.nomes else ""
        print(f"{x['id']} | ref. no canal {x['booking_id'] or '-'}{nome} | {x['quarto']} {x['chegada']} {x['noites']}n | {x['canal']} | "
              f"R$ {x['valor']:.0f} de R$ {x['esperado']:.0f} esperados ({x['recebe_do_esperado']:.0%}) | por {x['lancado_por']}")
    print(f"{len(lista)} reserva(s) abaixo de {alerta:.0%} do esperado (o price1 é o de agora: se o preço subiu depois da"
          " reserva, a razão cai sem erro de lançamento; abaixo de 50% é quase sempre erro)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

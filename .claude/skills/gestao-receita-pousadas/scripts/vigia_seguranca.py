"""Vigilância de segurança (dono, 03/11/2026: "A nossa bokking .com foi invadida ." / "Precisamos proteger nossas
propriedades"). Somente leitura no Beds24; roda em toda rotina, leve e completa, e vai para o TOPO do relatório.

Alertas:
  1. origem desconhecida: reserva criada ou alterada por uma origem (`referer` do Beds24) fora de
     `parametros.seguranca.origens_conhecidas` (quartos.json). Ex.: usuário novo da equipe, integração nova.
  2. rajada de cancelamentos: `rajada_cancelamentos` (3) ou mais cancelamentos do mesmo canal em `rajada_horas` (3 h).
     Golpe comum depois de invasão da extranet: o hóspede é convencido a cancelar e pagar por fora.
  3. reserva com valor zero: reserva confirmada ou nova sem valor (preço 0) criada no período.
Saída sem nomes de hóspedes (só id da reserva no Beds24, quarto, datas e origem): pode ir para arquivo e commit.

Uso:
  python3 vigia_seguranca.py --desde AAAA-MM-DDTHH:MM:SSZ [--json saida.json]
  python3 vigia_seguranca.py --desde AAAA-MM-DDTHH:MM:SSZ --arquivo rodada.json   # sem nova leitura no Beds24
Na rotina, o vigia roda dentro do `beds24.py rodada` (passo 2 do plano de redução de chamadas, 07/11: uma leitura só para
o movimento, o vigia e as reservas abaixo). O --arquivo reaproveita a resposta crua salva do `beds24.py rodada --json`
para uso avulso: só esse arquivo, com o desde dele até o --desde pedido e o --desde até o lido_em (revisão de 07/11: o
`reservas --json` é leitura por janela de estadia e não traz o cancelamento de estadia fora dela; o vigia diria
"0 alerta" sem ter olhado). Fora disso, código 2. O que mudou depois do lido_em não entra (aviso no stderr).
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import sys
from pathlib import Path

AQUI = Path(__file__).resolve().parent
sys.path.insert(0, str(AQUI))

PADRAO = {"origens_conhecidas": ["Booking.com", "Airbnb", "Expedia", "Expedia Affiliate Network", "google",
                                 "setBooking JSON", "API", "VillaRecep1", "RecRecep2", "isaacmiguel", "andrefelippe"],
          "rajada_cancelamentos": 3, "rajada_horas": 3}


def parametros() -> dict:
    cfg = json.loads((AQUI.parent / "dados" / "quartos.json").read_text())
    p = dict(PADRAO)
    p.update(((cfg.get("parametros") or {}).get("seguranca")) or {})
    return p


def _t(s: str | None) -> dt.datetime | None:
    if not s:
        return None
    try:
        x = dt.datetime.fromisoformat(s.replace("Z", "+00:00"))
    except ValueError:
        return None
    return x if x.tzinfo else x.replace(tzinfo=dt.timezone.utc)


def classificar(reservas: list[dict], desde: str, par: dict, quartos: dict | None = None) -> list[dict]:
    """Reservas do Beds24 (campos da API: id, roomId, arrival, departure, status, channel, referer, price,
    bookingTime, cancelTime, modifiedTime) → alertas. Função pura (testada)."""
    quartos = quartos or {}
    t0 = _t(desde)
    conhecidas = {o.lower() for o in par["origens_conhecidas"]}
    alertas, cancel = [], {}
    for b in reservas:
        mod = _t(b.get("modifiedTime")) or _t(b.get("bookingTime"))
        criada = _t(b.get("bookingTime"))
        if not mod or (t0 and mod < t0):
            continue
        base = {"id": b.get("id"), "quarto": quartos.get(str(b.get("roomId")), str(b.get("roomId"))),
                "chegada": b.get("arrival"), "saida": b.get("departure"), "status": b.get("status"),
                "canal": b.get("channel"), "origem": b.get("referer")}
        origem = (b.get("referer") or "").strip()
        if origem and origem.lower() not in conhecidas:
            alertas.append(dict(base, alerta="origem desconhecida",
                                detalhe=f"reserva criada ou alterada por '{origem}', fora das origens conhecidas"))
        st = (b.get("status") or "").lower()
        if st == "cancelled":
            tc = _t(b.get("cancelTime"))
            if tc and (not t0 or tc >= t0):
                cancel.setdefault(b.get("channel") or "?", []).append((tc, base))
        elif st in ("confirmed", "new"):
            try:
                preco = float(b.get("price") or 0)
            except (TypeError, ValueError):
                preco = 0.0
            if preco <= 0 and criada and (not t0 or criada >= t0):
                alertas.append(dict(base, alerta="reserva sem valor", detalhe="reserva confirmada ou nova com preço 0"))
            elif preco <= 0:
                # 06/11: reserva cancelada de madrugada voltou confirmada, em outro quarto, com R$ 0 (criada antes da
                # rodada, só alterada nela): antes passava sem alerta
                alertas.append(dict(base, alerta="reserva alterada sem valor",
                                    detalhe="reserva antiga alterada ou reativada na rodada, confirmada com preço 0"))
    janela = dt.timedelta(hours=float(par["rajada_horas"]))
    n_min = int(par["rajada_cancelamentos"])
    for canal, lst in cancel.items():
        lst.sort(key=lambda x: x[0])
        # 06/11: todas as rajadas da rodada, não só a primeira (eram 16 cancelamentos da Booking em 3 ondas e o alerta
        # listava 3). Marca cada cancelamento que cai numa janela com N ou mais e junta os marcados em grupos
        # separados por mais que a janela.
        marc, i = set(), 0
        for j in range(len(lst)):
            while lst[j][0] - lst[i][0] > janela:
                i += 1
            if j - i + 1 >= n_min:
                marc.update(range(i, j + 1))
        grupos, atual = [], []
        for k in sorted(marc):
            if atual and lst[k][0] - lst[atual[-1]][0] > janela:
                grupos.append(atual)
                atual = []
            atual.append(k)
        if atual:
            grupos.append(atual)
        for g in grupos:
            alertas.append({"alerta": "rajada de cancelamentos", "canal": canal,
                            "detalhe": (f"{len(g)} cancelamentos em até {par['rajada_horas']} h entre um e outro "
                                        f"({lst[g[0]][0]:%d/%m %H:%M} a {lst[g[-1]][0]:%d/%m %H:%M} UTC): confirme com "
                                        f"a recepção e com os hóspedes se foram eles"),
                            "reservas": [lst[k][1]["id"] for k in g]})
    return alertas


def imprimir(lidas: int, desde: str, alertas: list[dict]) -> None:
    """Resumo na tela (o mesmo do comando avulso e da `beds24.py rodada`)."""
    print(f"{lidas} reservas criadas ou alteradas desde {desde}; {len(alertas)} alerta(s) de segurança")
    for x in alertas:
        print(f"- {x['alerta']}: {x.get('detalhe')} | reserva {x.get('id', x.get('reservas'))} "
              f"{x.get('quarto', '')} {x.get('chegada', '')} canal {x.get('canal')}")


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--desde", required=True)
    ap.add_argument("--json")
    ap.add_argument("--arquivo", help="resposta crua salva do beds24.py rodada --json (desde do arquivo <= --desde <= "
                                      "lido_em): sem nova leitura no Beds24; outro arquivo dá código 2")
    a = ap.parse_args(argv)
    import beds24
    if a.arquivo:
        try:
            reservas = [b for b in beds24.ler_reservas_arquivo(a.arquivo, desde=a.desde)
                        if beds24.alterada_desde(b, a.desde)]
        except beds24.ErroBeds24 as e:
            print(f"ERRO: {e}", file=sys.stderr)
            return 2
    else:
        tok = beds24.token_de_acesso()
        params = {"propertyId": list(beds24.PROPRIEDADES), "modifiedFrom": a.desde,
                  "status": ["confirmed", "new", "request", "cancelled", "black", "inquiry"]}
        reservas = list(beds24.paginas("/bookings", params, tok))
    quartos = {k: v[0] for k, v in beds24.QUARTOS.items()}
    alertas = classificar(reservas, a.desde, parametros(), quartos)
    if a.json:
        Path(a.json).write_text(json.dumps({"desde": a.desde, "lidas": len(reservas), "alertas": alertas},
                                           ensure_ascii=False, indent=1))
    imprimir(len(reservas), a.desde, alertas)
    return 0


if __name__ == "__main__":
    sys.exit(main())

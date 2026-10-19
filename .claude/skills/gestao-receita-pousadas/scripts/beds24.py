"""Leitura direta do Beds24 (API V2), somente leitura, sem dados de hóspedes.

Por que existe (dono, 19/10/2026): os valores das reservas que a Skill lia vinham do PriceLabs
(get_pms_reservations), que é uma cópia do Beds24 com falhas: reservas do canal "outros" sem valor,
Airbnb com ~60% do valor e status "Novo"/"Inquiry" misturados com confirmadas. O relatório do Beds24
(Daily Financial Summary) traz a receita de hospedagem cadastrada. Este script lê o Beds24 direto.

Credencial (nunca colar no chat; o dono cadastra nos segredos do ambiente):
- BEDS24_TOKEN: "long life token" da API V2, só leitura, gerado direto no painel do Beds24
  (SETTINGS > APPS & INTEGRATIONS > API, ou SETTINGS > MARKETPLACE > API; link direto
  https://beds24.com/control3.php?pagetype=apiv2). É o caminho recomendado.
- ou BEDS24_REFRESH_TOKEN: token de atualização (do código de convite), trocado aqui por um token de 24 h.
- ou o arquivo /root/.beds24/refresh_token (permissão 600, fora do repositório), criado em 19/10 na troca do
  convite; vale só enquanto o contêiner desta sessão existir. Nunca versionar nem imprimir o token.

Uso:
  python3 beds24.py teste                      # confere a credencial e lista as propriedades
  python3 beds24.py reservas --de 2026-10-19 --ate 2026-12-18 [--json saida.json]
  python3 beds24.py janelas --hoje 2026-10-19 [--json saida.json]   # 7/15/30/45/60 noites, como o relatório
  python3 beds24.py livro --de 2026-10-01 --ate 2027-03-01           # livro por mês de estadia e pousada
  python3 beds24.py movimento --desde 2026-10-19T13:00:00Z           # novas, canceladas e alteradas desde
  python3 beds24.py comparar --coleta <saida do workflow> --de 2026-10-19 --ate 2026-12-18

Regras de contagem (iguais às do relatório do Beds24 de 19/10):
- noites de arrival até departure (departure exclusivo); checkout excluído;
- status separados: confirmed, new, request, inquiry/black, cancelled; os indicadores principais usam
  confirmed + new (reserva real que ocupa o quarto) e mostram confirmed sozinho ao lado;
- receita de hospedagem = itens de fatura de diária (invoiceItems type charge sem extras) quando vierem;
  sem itens, usa o campo price da reserva rateado pelas noites.
Endpoints e campos conferidos no apiV2.yaml oficial em 19/10/2026 (GET /authentication/setup com cabeçalho
`code`; GET /authentication/token com `refreshToken`; GET /bookings com arrivalTo, departureFrom, status,
includeInvoiceItems; reserva com roomQty, price, commission, invoiceItems[type, qty, amount, lineTotal]).
"""
import argparse
import datetime as dt
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict

BASE = "https://beds24.com/api/v2"
# Propriedades e quartos do Beds24, pelos IDs que o PriceLabs usa (propriedade___quarto).
PROPRIEDADES = {"350362": "Recanto dos Moinhos", "350364": "Villa Dolce Amore"}
QUARTOS = {"722805": ("Q7", 7), "722806": ("Double", 6), "722807": ("Afrodite", 1), "722808": ("Q2", 2),
           "722813": ("VK7", 7), "722814": ("Balcony", 6), "722815": ("VK2", 2)}
CAPACIDADE = {"350362": 16, "350364": 15}
STATUS_OCUPA = {"confirmed", "new"}


class ErroBeds24(Exception):
    pass


def _get(caminho, params=None, cabecalhos=None):
    url = f"{BASE}{caminho}"
    if params:
        url += "?" + urllib.parse.urlencode(params, doseq=True)
    req = urllib.request.Request(url, headers={"accept": "application/json", **(cabecalhos or {})})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        corpo = e.read().decode("utf-8", "replace")[:300]
        raise ErroBeds24(f"HTTP {e.code} em {caminho}: {corpo}") from None


def token_de_acesso():
    tok = os.environ.get("BEDS24_TOKEN")
    if tok:
        return tok
    ref = os.environ.get("BEDS24_REFRESH_TOKEN")
    arq = os.environ.get("BEDS24_REFRESH_TOKEN_FILE", "/root/.beds24/refresh_token")
    if not ref and os.path.exists(arq):
        ref = open(arq, encoding="utf-8").read().strip()
    if not ref:
        raise ErroBeds24("Sem credencial: falta a variável BEDS24_REFRESH_TOKEN nos segredos do ambiente.")
    r = _get("/authentication/token", cabecalhos={"refreshToken": ref})
    if not r.get("token"):
        raise ErroBeds24(f"O Beds24 não devolveu token de acesso: {str(r)[:200]}")
    return r["token"]


def paginas(caminho, params, token):
    params = dict(params)
    pagina = 1
    while True:
        params["page"] = pagina
        r = _get(caminho, params, {"token": token})
        for item in r.get("data") or []:
            yield item
        if not (r.get("pages") or {}).get("nextPageExists"):
            break
        pagina += 1


def reservas(de, ate, token):
    """Reservas com alguma noite entre `de` (incluído) e `ate` (exclusivo), todos os status."""
    params = {"propertyId": list(PROPRIEDADES), "arrivalTo": ate, "departureFrom": de,
              "includeInvoiceItems": "true",
              "status": ["confirmed", "new", "request", "cancelled", "black", "inquiry"]}
    return list(paginas("/bookings", params, token))


def receita_por_noite(b):
    """Receita de hospedagem da reserva e o número de noites (departure exclusivo)."""
    chegada = dt.date.fromisoformat(b["arrival"])
    saida = dt.date.fromisoformat(b["departure"])
    noites = max((saida - chegada).days, 1)
    itens = b.get("invoiceItems") or []
    diarias = [i for i in itens if (i.get("type") or "").lower() == "charge" and not i.get("invoiceeId")]
    if diarias:
        total = sum(float(i["lineTotal"]) if i.get("lineTotal") is not None
                    else float(i.get("amount") or 0) * float(i.get("qty") or 1) for i in diarias)
    else:
        total = float(b.get("price") or 0)
    return chegada, noites, total


def janelas(lista, hoje, dias=(7, 15, 30, 45, 60)):
    res = []
    for n in dias:
        ini, fim = hoje, hoje + dt.timedelta(days=n)
        un = defaultdict(lambda: defaultdict(int))
        rec = defaultdict(lambda: defaultdict(float))
        for b in lista:
            st = (b.get("status") or "").lower()
            prop = str(b.get("propertyId"))
            chegada, noites, total = receita_por_noite(b)
            qtd = int(b.get("roomQty") or 1)
            for i in range(noites):
                d = chegada + dt.timedelta(days=i)
                if ini <= d < fim:
                    un[prop][st] += qtd
                    rec[prop][st] += total / noites
        for prop, nome in PROPRIEDADES.items():
            cap = CAPACIDADE[prop] * n
            conf = un[prop]["confirmed"]
            ocupa = sum(un[prop][s] for s in STATUS_OCUPA)
            receita = sum(rec[prop][s] for s in STATUS_OCUPA)
            res.append({"noites": n, "periodo": f"{ini:%d/%m} a {fim:%d/%m}", "pousada": nome,
                        "un_confirmadas": conf, "un_novas": un[prop]["new"], "un_ocupadas": ocupa,
                        "capacidade": cap, "ocupacao": round(ocupa / cap, 4), "ocupacao_so_confirmadas": round(conf / cap, 4),
                        "receita": round(receita, 2), "adr": round(receita / ocupa, 2) if ocupa else None,
                        "outros_status": {s: v for s, v in un[prop].items() if s not in STATUS_OCUPA}})
    return res


def noites(lista, de, ate, status=STATUS_OCUPA):
    """Unidades vendidas por quarto e noite: {(codigo, 'AAAA-MM-DD'): unidades}."""
    out = defaultdict(int)
    for b in lista:
        if (b.get("status") or "").lower() not in status:
            continue
        codigo = QUARTOS.get(str(b.get("roomId")), (str(b.get("roomId")), 0))[0]
        chegada, n, _ = receita_por_noite(b)
        for k in range(n):
            d = chegada + dt.timedelta(days=k)
            if de <= d < ate:
                out[(codigo, d.isoformat())] += int(b.get("roomQty") or 1)
    return dict(out)


def livro(lista, de, ate):
    """Livro por mês de estadia e pousada: receita de hospedagem (confirmadas + novas), só confirmadas,
    unidades-noite, ADR e receita por canal."""
    nome = {k: v.split()[0] for k, v in PROPRIEDADES.items()}
    acc = defaultdict(lambda: {"receita": 0.0, "receita_confirmadas": 0.0, "un": 0, "canais": defaultdict(float)})
    for b in lista:
        st = (b.get("status") or "").lower()
        if st not in STATUS_OCUPA:
            continue
        chegada, n, total = receita_por_noite(b)
        pous = nome.get(str(b.get("propertyId")), str(b.get("propertyId")))
        canal = b.get("channel") or b.get("referer") or "outros"
        for k in range(n):
            d = chegada + dt.timedelta(days=k)
            if not (de <= d < ate):
                continue
            x = acc[(d.strftime("%Y-%m"), pous)]
            x["receita"] += total / n
            x["un"] += int(b.get("roomQty") or 1)
            x["canais"][canal] += total / n
            if st == "confirmed":
                x["receita_confirmadas"] += total / n
    res = []
    for (mes, pous), x in sorted(acc.items()):
        res.append({"mes": mes, "pousada": pous, "receita": round(x["receita"], 2),
                    "receita_confirmadas": round(x["receita_confirmadas"], 2), "unidades_noite": x["un"],
                    "adr": round(x["receita"] / x["un"], 2) if x["un"] else None,
                    "por_canal": {c: round(v, 2) for c, v in sorted(x["canais"].items())}})
    return res


def movimento(token, desde):
    """Reservas novas, canceladas e alteradas desde o instante `desde` (ISO), sem dados de hóspedes."""
    params = {"propertyId": list(PROPRIEDADES), "modifiedFrom": desde,
              "status": ["confirmed", "new", "request", "cancelled", "black", "inquiry"]}
    saida = []
    for b in paginas("/bookings", params, token):
        st = (b.get("status") or "").lower()
        if st == "cancelled" and (b.get("cancelTime") or "") >= desde:
            tipo = "cancelada"
        elif (b.get("bookingTime") or "") >= desde:
            tipo = "nova"
        else:
            tipo = "alterada"
        chegada, n, total = receita_por_noite(b)
        saida.append({"tipo": tipo, "id": b.get("id"), "quarto": QUARTOS.get(str(b.get("roomId")), (str(b.get("roomId")),))[0],
                      "status": st, "chegada": b.get("arrival"), "noites": n, "receita": round(total, 2),
                      "canal": b.get("channel") or b.get("referer"), "reservada_em": b.get("bookingTime"),
                      "cancelada_em": b.get("cancelTime")})
    return saida


def comparar(lista, coleta, de, ate):
    """Beds24 (confirmadas + novas) contra o calendário do PriceLabs (unidades vendidas por quarto e noite).
    PriceLabs acima = bloqueio sem reserva ou reserva que o Beds24 não mostra; Beds24 acima = noite vendida
    que o PriceLabs ainda trata como livre (risco de vender a mais ou preço errado)."""
    b24 = noites(lista, de, ate)
    dif = []
    for codigo, r in (coleta.get("rooms") or {}).items():
        for x in r.get("days") or []:
            d = x["date"][:10]
            if not (de.isoformat() <= d < ate.isoformat()):
                continue
            mu = str(x.get("multi_unit_occupancy") or "")
            if "/" in mu:
                pl = int(mu.split("/")[0])
            else:
                pl = 1 if str(x.get("booking_status") or "").strip() else 0
            bb = b24.get((codigo, d), 0)
            if pl != bb:
                dif.append({"quarto": codigo, "data": d, "pricelabs": pl, "beds24": bb, "diferenca": pl - bb})
    return dif


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("teste")
    r = sub.add_parser("reservas"); r.add_argument("--de", required=True); r.add_argument("--ate", required=True); r.add_argument("--json")
    j = sub.add_parser("janelas"); j.add_argument("--hoje", required=True); j.add_argument("--json")
    lv = sub.add_parser("livro"); lv.add_argument("--de", required=True); lv.add_argument("--ate", required=True); lv.add_argument("--json")
    mv = sub.add_parser("movimento"); mv.add_argument("--desde", required=True, help="AAAA-MM-DDTHH:MM:SSZ (UTC)"); mv.add_argument("--json")
    cp = sub.add_parser("comparar"); cp.add_argument("--coleta", required=True); cp.add_argument("--de", required=True)
    cp.add_argument("--ate", required=True); cp.add_argument("--json")
    a = ap.parse_args(argv)
    try:
        tok = token_de_acesso()
        if a.cmd == "teste":
            props = _get("/properties", {"id": list(PROPRIEDADES)}, {"token": tok})
            print(json.dumps([{"id": p.get("id"), "nome": p.get("name")} for p in props.get("data") or []], ensure_ascii=False))
            return 0
        if a.cmd == "reservas":
            lista = reservas(a.de, a.ate, tok)
            saida = [{k: b.get(k) for k in ("id", "propertyId", "roomId", "status", "arrival", "departure", "roomQty",
                                             "price", "commission", "referer", "channel", "bookingTime", "cancelTime")} |
                     {"receita_hospedagem": round(receita_por_noite(b)[2], 2)} for b in lista]
            texto = json.dumps(saida, ensure_ascii=False, indent=1)
        elif a.cmd == "livro":
            de, ate = dt.date.fromisoformat(a.de), dt.date.fromisoformat(a.ate)
            texto = json.dumps(livro(reservas(a.de, a.ate, tok), de, ate), ensure_ascii=False, indent=1)
        elif a.cmd == "movimento":
            texto = json.dumps(movimento(tok, a.desde), ensure_ascii=False, indent=1)
        elif a.cmd == "comparar":
            de, ate = dt.date.fromisoformat(a.de), dt.date.fromisoformat(a.ate)
            coleta = json.load(open(a.coleta, encoding="utf-8"))
            coleta = coleta.get("result", coleta) if isinstance(coleta, dict) else coleta
            if isinstance(coleta, str):
                coleta = json.loads(coleta)
            texto = json.dumps(comparar(reservas(a.de, a.ate, tok), coleta, de, ate), ensure_ascii=False, indent=1)
        else:
            hoje = dt.date.fromisoformat(a.hoje)
            lista = reservas(a.hoje, (hoje + dt.timedelta(days=60)).isoformat(), tok)
            texto = json.dumps(janelas(lista, hoje), ensure_ascii=False, indent=1)
        if a.json:
            open(a.json, "w", encoding="utf-8").write(texto)
            print(f"gravado em {a.json}")
        else:
            print(texto)
        return 0
    except ErroBeds24 as e:
        print(f"ERRO: {e}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

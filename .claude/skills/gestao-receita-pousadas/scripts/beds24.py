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

Uso:
  python3 beds24.py teste                      # confere a credencial e lista as propriedades
  python3 beds24.py reservas --de 2026-10-19 --ate 2026-12-18 [--json saida.json]
  python3 beds24.py janelas --hoje 2026-10-19 [--json saida.json]   # 7/15/30/45/60 noites, como o relatório

Regras de contagem (iguais às do relatório do Beds24 de 19/10):
- noites de arrival até departure (departure exclusivo); checkout excluído;
- status separados: confirmed, new, request, inquiry/black, cancelled; os indicadores principais usam
  confirmed + new (reserva real que ocupa o quarto) e mostram confirmed sozinho ao lado;
- receita de hospedagem = itens de fatura de diária (invoiceItems type charge sem extras) quando vierem;
  sem itens, usa o campo price da reserva rateado pelas noites.
Os endpoints e campos seguem a documentação pública da API V2 (https://beds24.com/api/v2); confira no
primeiro uso com `teste` e ajuste aqui se o Beds24 devolver nomes diferentes.
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
        total = sum(float(i.get("amount") or 0) * float(i.get("qty") or 1) for i in diarias)
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
            qtd = int(b.get("numRooms") or 1)
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


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("teste")
    r = sub.add_parser("reservas"); r.add_argument("--de", required=True); r.add_argument("--ate", required=True); r.add_argument("--json")
    j = sub.add_parser("janelas"); j.add_argument("--hoje", required=True); j.add_argument("--json")
    a = ap.parse_args(argv)
    try:
        tok = token_de_acesso()
        if a.cmd == "teste":
            props = _get("/properties", {"id": list(PROPRIEDADES)}, {"token": tok})
            print(json.dumps([{"id": p.get("id"), "nome": p.get("name")} for p in props.get("data") or []], ensure_ascii=False))
            return 0
        if a.cmd == "reservas":
            lista = reservas(a.de, a.ate, tok)
            saida = [{k: b.get(k) for k in ("id", "propertyId", "roomId", "status", "arrival", "departure", "numRooms",
                                             "price", "commission", "referer", "channel", "bookingTime", "cancelTime")} |
                     {"receita_hospedagem": round(receita_por_noite(b)[2], 2)} for b in lista]
            texto = json.dumps(saida, ensure_ascii=False, indent=1)
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

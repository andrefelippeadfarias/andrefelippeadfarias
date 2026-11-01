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
- ou (27/10) o segredo de rede do ambiente: o dono cadastra o long life token em "Network secrets" com o
  cabeçalho `token` (sem prefixo) para o site beds24.com, e o proxy o injeta em cada chamada. Sem nenhuma das
  variáveis acima, o script chama a API sem cabeçalho de token e conta com essa injeção; um 401 diz que falta o
  segredo. BEDS24_SEM_SEGREDO_DE_REDE=1 desliga esse modo (usado nos testes).

Edição do inventário (dono, 01/11/2026: "verificar sempre o inventário e editar sempre que precisar"):
- ESCRITA só por código de convite (o Beds24 só dá long life token de LEITURA): o dono gera um "invite code" com
  READ+WRITE em inventory e cadastra como BEDS24_INVITE_CODE nos segredos/variáveis do ambiente. Na primeira escrita
  o código é trocado (GET /authentication/setup, cabeçalho `code`) por um refresh token, guardado em
  /root/.beds24/refresh_token (600, fora do repositório) ou lido de BEDS24_REFRESH_TOKEN; cada escrita usa um token
  de 24 h tirado dele. Nunca versionar nem imprimir token ou código.
- Só disponibilidade (numAvail, override blackout/none), estadia mínima (minStay) e chegada/saída (override
  noCheckIn/noCheckOut/noCheckInOrCheckOut/none). NUNCA preço (price1..16: preço só pelo PriceLabs) e NUNCA reservas.
- `editar` é simulação por padrão (mostra antes e depois); com --aplicar grava e relê para conferir.
  Endpoint e campos: POST/GET /inventory/rooms/calendar (apiV2.yaml oficial, conferido em 01/11/2026).

Uso:
  python3 beds24.py teste                      # confere a credencial e lista as propriedades
  python3 beds24.py inventario --de 2026-11-05 --ate 2026-11-08 [--quarto VK7 ...] [--json saida.json]
  python3 beds24.py editar --quarto Double --de 2026-11-06 --ate 2026-11-06 --min-stay 1 --motivo "..." [--aplicar]
  python3 beds24.py editar --quarto VK7 --de 2026-11-05 --ate 2026-11-05 --override none --motivo "..." [--aplicar]
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

BASE = "https://api.beds24.com/v2"  # 01/11: beds24.com/api/v2 deixou de aceitar o cabeçalho `token` a partir deste ambiente
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
        if e.code in (401, 403) and "token" not in (cabecalhos or {}) and "refreshToken" not in (cabecalhos or {}):
            raise ErroBeds24(f"HTTP {e.code} em {caminho}: sem credencial. Cadastre o long life token do Beds24 em "
                             "Network secrets (cabeçalho `token`, sem prefixo, site beds24.com) ou BEDS24_TOKEN.") from None
        raise ErroBeds24(f"HTTP {e.code} em {caminho}: {corpo}") from None


def _post(caminho, corpo, cabecalhos):
    req = urllib.request.Request(f"{BASE}{caminho}", data=json.dumps(corpo).encode("utf-8"), method="POST",
                                 headers={"accept": "application/json", "content-type": "application/json", **cabecalhos})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        raise ErroBeds24(f"HTTP {e.code} em POST {caminho}: {e.read().decode('utf-8', 'replace')[:300]}") from None


ARQ_REFRESH = os.environ.get("BEDS24_REFRESH_TOKEN_FILE", "/root/.beds24/refresh_token")
ID_DO_CURTO = {curto: int(rid) for rid, (curto, _) in QUARTOS.items()}
UNIDADES = {curto: u for _, (curto, u) in QUARTOS.items()}
OVERRIDES = ("none", "blackout", "noCheckIn", "noCheckOut", "noCheckInOrCheckOut")


def refresh_token_escrita():
    """Refresh token com escopo de escrita: variável, arquivo fora do repositório ou troca do código de convite."""
    ref = os.environ.get("BEDS24_REFRESH_TOKEN")
    if ref:
        return ref
    if os.path.exists(ARQ_REFRESH):
        return open(ARQ_REFRESH, encoding="utf-8").read().strip()
    code = os.environ.get("BEDS24_INVITE_CODE")
    if not code:
        raise ErroBeds24("Sem credencial de escrita: o dono gera um invite code (READ+WRITE em inventory) no Beds24 e "
                         "cadastra como BEDS24_INVITE_CODE nas variáveis do ambiente (vale a partir da próxima sessão).")
    r = _get("/authentication/setup", cabecalhos={"code": code})
    ref = r.get("refreshToken")
    if not ref:
        raise ErroBeds24("O Beds24 não trocou o código de convite (já usado ou vencido): gere outro.")
    os.makedirs(os.path.dirname(ARQ_REFRESH), mode=0o700, exist_ok=True)
    antigo = os.umask(0o077)
    try:
        with open(ARQ_REFRESH, "w", encoding="utf-8") as f:
            f.write(ref)
    finally:
        os.umask(antigo)
    return ref


def token_escrita():
    r = _get("/authentication/token", cabecalhos={"refreshToken": refresh_token_escrita()})
    if not r.get("token"):
        raise ErroBeds24("O Beds24 não devolveu token de escrita a partir do refresh token.")
    return r["token"]


def inventario(token, de, ate, quartos=None):
    """{curto: {data: {numAvail, minStay, override}}} do calendário do Beds24 (só os valores postos no calendário;
    minStay cai para o do quarto quando o dia não tem)."""
    ids = [ID_DO_CURTO[q] for q in (quartos or ID_DO_CURTO)]
    params = {"roomId": ids, "startDate": de, "endDate": ate, "includeNumAvail": "true", "includeMinStay": "true",
              "includeOverride": "true"}
    out = {}
    for item in paginas("/inventory/rooms/calendar", params, token):
        curto = QUARTOS.get(str(item.get("roomId")), (str(item.get("roomId")), 0))[0]
        dias = out.setdefault(curto, {})
        for faixa in item.get("calendar") or []:
            d, fim = dt.date.fromisoformat(faixa["from"]), dt.date.fromisoformat(faixa.get("to") or faixa["from"])
            while d <= fim:
                if de <= d.isoformat() <= ate:
                    dias[d.isoformat()] = {k: faixa.get(k) for k in ("numAvail", "minStay", "override")}
                d += dt.timedelta(days=1)
    return out


def corpo_edicao(curto, de, ate, min_stay=None, override=None, num_avail=None):
    """Corpo do POST /inventory/rooms/calendar com as travas da skill (sem preço, valores no intervalo)."""
    if curto not in ID_DO_CURTO:
        raise ErroBeds24(f"quarto desconhecido: {curto}")
    if dt.date.fromisoformat(ate) < dt.date.fromisoformat(de):
        raise ErroBeds24("--ate antes de --de")
    faixa = {"from": de, "to": ate}
    if min_stay is not None:
        if not 1 <= int(min_stay) <= 30:
            raise ErroBeds24("estadia mínima fora de 1 a 30")
        faixa["minStay"] = int(min_stay)
    if override is not None:
        if override not in OVERRIDES:
            raise ErroBeds24(f"override inválido: {override} (use {', '.join(OVERRIDES)})")
        faixa["override"] = override
    if num_avail is not None:
        if not 0 <= int(num_avail) <= UNIDADES[curto]:
            raise ErroBeds24(f"disponibilidade fora de 0 a {UNIDADES[curto]} para {curto}")
        faixa["numAvail"] = int(num_avail)
    if len(faixa) == 2:
        raise ErroBeds24("nada a mudar: informe --min-stay, --override ou --num-avail")
    if any(k.startswith("price") for k in faixa):
        raise ErroBeds24("preço não se muda pelo Beds24 (só pelo PriceLabs)")
    return [{"roomId": ID_DO_CURTO[curto], "calendar": [faixa]}]


ARQ_LEITURA = os.environ.get("BEDS24_TOKEN_FILE", "/root/.beds24/token_leitura")


def token_de_acesso():
    tok = os.environ.get("BEDS24_TOKEN")
    if tok:
        return tok
    if os.path.exists(ARQ_LEITURA):  # long life token de leitura guardado fora do repositório (600), 01/11
        return open(ARQ_LEITURA, encoding="utf-8").read().strip()
    ref = os.environ.get("BEDS24_REFRESH_TOKEN")
    arq = os.environ.get("BEDS24_REFRESH_TOKEN_FILE", "/root/.beds24/refresh_token")
    if not ref and os.path.exists(arq):
        ref = open(arq, encoding="utf-8").read().strip()
    if not ref:
        if not os.environ.get("BEDS24_SEM_SEGREDO_DE_REDE"):
            return None  # segredo de rede: o proxy põe o cabeçalho `token`
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
        r = _get(caminho, params, {"token": token} if token else None)
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


def editar_lote(plano, aplicar=False):
    """Plano = [{quarto, de, ate, min_stay?, override?, num_avail?, motivo}]. Um POST com todas as faixas (agrupadas por
    quarto), depois relê o inventário e confere item a item. Sem --aplicar só mostra antes e mudança."""
    por_quarto, linhas = {}, []
    for it in plano:
        c = corpo_edicao(it["quarto"], it["de"], it["ate"], it.get("min_stay"), it.get("override"), it.get("num_avail"))
        por_quarto.setdefault(c[0]["roomId"], []).extend(c[0]["calendar"])
        linhas.append(it)
    de = min(i["de"] for i in plano)
    ate = max(i["ate"] for i in plano)
    quartos = sorted({i["quarto"] for i in plano})
    antes = inventario(token_de_acesso(), de, ate, quartos)
    resumo = {"itens": len(plano), "quartos": quartos, "aplicar": aplicar}
    if not aplicar:
        resumo["amostra"] = [{"quarto": i["quarto"], "de": i["de"], "antes": antes.get(i["quarto"], {}).get(i["de"]),
                              "mudanca": {k: i[k] for k in ("min_stay", "override", "num_avail") if i.get(k) is not None}}
                             for i in plano[:12]]
        return resumo
    corpo = [{"roomId": rid, "calendar": cal} for rid, cal in por_quarto.items()]
    resp = _post("/inventory/rooms/calendar", corpo, {"token": token_escrita()})
    depois = inventario(token_de_acesso(), de, ate, quartos)
    erros = []
    for i in plano:
        d = dt.date.fromisoformat(i["de"])
        while d <= dt.date.fromisoformat(i["ate"]):
            v = depois.get(i["quarto"], {}).get(d.isoformat(), {})
            for campo, chave in (("min_stay", "minStay"), ("override", "override"), ("num_avail", "numAvail")):
                if i.get(campo) is not None and v.get(chave) != i[campo]:
                    erros.append(f"{i['quarto']} {d}: {chave} {v.get(chave)} (esperado {i[campo]})")
            d += dt.timedelta(days=1)
    resumo.update({"resposta_ok": all((r or {}).get("success", True) for r in (resp if isinstance(resp, list) else [resp])),
                   "conferidos": len(plano) - len({e.split(':')[0] for e in erros}), "erros": erros})
    return resumo


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
    iv = sub.add_parser("inventario"); iv.add_argument("--de", required=True); iv.add_argument("--ate", required=True)
    iv.add_argument("--quarto", nargs="*"); iv.add_argument("--json")
    ed = sub.add_parser("editar"); ed.add_argument("--quarto", required=True); ed.add_argument("--de", required=True)
    ed.add_argument("--ate", required=True); ed.add_argument("--min-stay", type=int); ed.add_argument("--override")
    ed.add_argument("--num-avail", type=int); ed.add_argument("--motivo", required=True)
    ed.add_argument("--aplicar", action="store_true", help="grava no Beds24 (sem isto, só simula)")
    el = sub.add_parser("editar-lote"); el.add_argument("--plano", required=True); el.add_argument("--aplicar", action="store_true")
    a = ap.parse_args(argv)
    if a.cmd == "editar-lote":
        try:
            print(json.dumps(editar_lote(json.load(open(a.plano, encoding="utf-8")), a.aplicar), ensure_ascii=False, indent=1))
            return 0
        except ErroBeds24 as e:
            print(f"ERRO: {e}", file=sys.stderr)
            return 2
    if a.cmd == "editar":
        try:
            corpo = corpo_edicao(a.quarto, a.de, a.ate, a.min_stay, a.override, a.num_avail)
            antes = inventario(token_de_acesso(), a.de, a.ate, [a.quarto]).get(a.quarto, {})
            print(json.dumps({"quarto": a.quarto, "motivo": a.motivo, "antes": antes, "mudanca": corpo[0]["calendar"][0],
                              "aplicar": a.aplicar}, ensure_ascii=False, indent=1))
            if not a.aplicar:
                print("SIMULAÇÃO: nada gravado (use --aplicar)")
                return 0
            r = _post("/inventory/rooms/calendar", corpo, {"token": token_escrita()})
            depois = inventario(token_de_acesso(), a.de, a.ate, [a.quarto]).get(a.quarto, {})
            print(json.dumps({"resposta": r, "depois": depois}, ensure_ascii=False, indent=1))
            return 0
        except ErroBeds24 as e:
            print(f"ERRO: {e}", file=sys.stderr)
            return 2
    try:
        tok = token_de_acesso()
        if a.cmd == "teste":
            props = _get("/properties", {"id": list(PROPRIEDADES)}, {"token": tok} if tok else None)
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
        elif a.cmd == "inventario":
            texto = json.dumps(inventario(tok, a.de, a.ate, a.quarto), ensure_ascii=False, indent=1)
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

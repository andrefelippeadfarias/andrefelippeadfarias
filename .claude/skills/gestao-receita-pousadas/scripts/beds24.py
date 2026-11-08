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
- Só disponibilidade (numAvail, override blackout/none), estadia mínima (minStay), estadia máxima (maxStay; dono 03/11,
  pacotes fechados de Natal e Ano Novo) e chegada/saída (override
  noCheckIn/noCheckOut/noCheckInOrCheckOut/none). NUNCA preço (price1..16: preço só pelo PriceLabs) e NUNCA reservas.
- `editar` é simulação por padrão (mostra antes e depois); com --aplicar grava e relê para conferir.
- `editar-lote` (revisão de 05/11): item com `min_stay_antes` cujo minStay lido na hora diverge é pulado e relatado
  (`pulados`); o resto vai em blocos de até 100 faixas por POST (`--max-faixas`), cada bloco relido e conferido.
  Endpoint e campos: POST/GET /inventory/rooms/calendar (apiV2.yaml oficial, conferido em 01/11/2026).

Menos chamadas (dono 06/11, plano-reducao-chamadas.md, passo 2: uma leitura de cada tipo por rotina e um lote de
gravação por rodada):
- `rodada`: 1 GET /bookings com modifiedFrom (todos os status, includeInvoiceItems) e, na memória, o movimento, o
  vigia_seguranca.classificar e o reservas_abaixo.avaliar (as mesmas classificações dos três comandos separados). O
  JSON sai sem nomes de hóspedes (lista branca de campos, `sem_nomes`); nomes só na tela, com --nomes. A leitura é
  repetida 1 vez; se o Beds24 continuar fora, sai com código 3 e "vigia sem Beds24" (plano B: get_pms_reservations).
- `inventario --precos` (includePrices, apiV2.yaml): grava o price1 de cada noite no JSON, sem mudar as outras chaves;
  o reservas_abaixo (e a rodada) usam esse price1 com --inventario e só leem preço da noite fora do arquivo.
- `reservas --json`: resposta crua sem nomes (com invoiceItems, a janela lida e o lido_em); janelas, comparar e
  concorrencia_hidro_vista.py reaproveitam com --reservas <arquivo>, sem nova leitura. Revisão de 07/11: o arquivo
  precisa ser desse comando, cobrir a janela pedida e ter sido lido há até 3 h (senão código 2); o vigia_seguranca.py e
  o reservas_abaixo.py com --arquivo só aceitam o `rodada --json` (desde ≤ --desde ≤ lido_em). O price1 do --inventario
  lido há mais de 2 h (de outra rotina) vira aviso. Na rodada, o movimento sai sem os invoiceItems (receita pelo price,
  como o `movimento`), e o JSON traz as chamadas de verdade (leituras_bookings, chamadas, espera_credito_s).
- `editar-lote` aceita vários --plano (na ordem dada: o urgente primeiro) e junta tudo nos mesmos blocos de POST.
- Token de escrita guardado em /root/.beds24/token_escrita (600, BEDS24_TOKEN_ESCRITA_FILE) até o expiresIn devolvido
  menos 5 min (sem expiresIn não guarda: nunca supor 24 h). Nunca imprimir.
- Crédito de 5 minutos: cada resposta traz o saldo e o tempo até zerar. Na leitura ao vivo de 07/11 os cabeçalhos
  vieram como X-Five-Min-Limit-Remaining (decimal, ex. 21.2), X-Five-Min-Limit-Resets-In e X-Request-Cost; o
  apiV2.yaml escreve X-FiveMinCreditLimit-Remaining / -ResetsIn / X-RequestCost: os dois nomes valem. Com saldo
  abaixo de BEDS24_CREDITO_MINIMO (5) o _get/_post esperam zerar em vez de tomar 429; com 429, esperam o ResetsIn e
  tentam 1 vez só. Vale para TODOS os comandos: cada chamada pode esperar até 310 s. Rode os comandos do Beds24 com
  timeout de pelo menos 10 min (o padrão de 2 min da ferramenta de shell pode matar o editar-lote entre o POST e a
  releitura) ou baixe o teto de cada espera com BEDS24_ESPERA_MAXIMA_S (0 a 310). O editar-lote soma as esperas no
  resumo (`espera_credito_s`, só quando houve).

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
  python3 beds24.py rodada --desde 2026-11-07T13:48:00Z --json rodada.json [--inventario inv.json] [--nomes]
  python3 beds24.py inventario --de 2026-11-07 --ate 2027-11-09 --precos --json inv.json
  python3 beds24.py reservas --de 2026-11-07 --ate 2027-01-06 --json reservas.json   # crua, sem nomes
  python3 beds24.py janelas --hoje 2026-11-07 --reservas reservas.json               # idem comparar
  python3 beds24.py editar-lote --plano lote_recanto_urgente.json --plano lote_recanto_Q7.json [--aplicar]

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
import http.client
import json
import os
import stat
import sys
import time
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
    def __init__(self, mensagem="", codigo=None):
        super().__init__(mensagem)
        self.codigo = codigo  # código HTTP, quando houver (401, 429...)


# Crédito de 5 minutos da API V2 (passo 2, 07/11). Nomes vistos na resposta real primeiro, os do apiV2.yaml depois.
CAB_RESTANTE = ("X-Five-Min-Limit-Remaining", "X-FiveMinCreditLimit-Remaining")
CAB_ZERA_EM = ("X-Five-Min-Limit-Resets-In", "X-FiveMinCreditLimit-ResetsIn")
CAB_CUSTO = ("X-Request-Cost", "X-RequestCost")
CREDITO_MINIMO = float(os.environ.get("BEDS24_CREDITO_MINIMO", "5"))
ESPERA_MAXIMA_S = 310           # a janela é de 5 min: nunca esperar mais que isso (+ folga)
ESPERA_429_PADRAO_S = 60        # 429 sem ResetsIn
_CREDITO = {}                   # {"restante", "zera_em" (relógio), "custo"} da última resposta
_CHAMADAS = defaultdict(int)    # chamadas HTTP feitas nesta execução, por "MÉTODO /caminho" (cada tentativa conta)
_ESPERAS = []                   # segundos esperados pelo crédito de 5 min (saldo baixo ou 429) nesta execução
_agora = time.time              # injetáveis nos testes (nenhum sleep real)
_dormir = time.sleep


def _espera_maxima():
    """Teto de cada espera pelo crédito: BEDS24_ESPERA_MAXIMA_S (0 a 310 s; padrão 310). Revisão de 07/11: a espera vale
    para todos os comandos (cada chamada pode esperar até 310 s); para não passar do timeout da ferramenta de shell, rode
    os comandos do Beds24 com timeout de 10 min ou baixe o teto (com o teto curto, o 429 volta e sobe como erro)."""
    try:
        v = float(os.environ.get("BEDS24_ESPERA_MAXIMA_S", ESPERA_MAXIMA_S))
    except ValueError:
        return float(ESPERA_MAXIMA_S)
    return max(0.0, min(v, float(ESPERA_MAXIMA_S)))


def _esperar(segundos, mensagem):
    print(mensagem, file=sys.stderr)
    _ESPERAS.append(segundos)
    _dormir(segundos)


def _cabecalho(cab, nomes):
    for n in nomes:
        v = cab.get(n) if cab is not None else None
        if v not in (None, ""):
            try:
                return float(v)
            except (TypeError, ValueError):
                continue
    return None


def _anotar_credito(cab):
    restante, zera, custo = (_cabecalho(cab, n) for n in (CAB_RESTANTE, CAB_ZERA_EM, CAB_CUSTO))
    if restante is None or zera is None:
        return
    _CREDITO.update(restante=restante, zera_em=_agora() + zera, custo=custo if custo is not None else 1.0)


def _esperar_credito():
    """Antes de cada chamada: com o saldo da última resposta abaixo do mínimo (ou do custo da última chamada) e a janela
    ainda aberta, espera a janela zerar em vez de tomar o 429."""
    if _CREDITO.get("restante") is None:
        return
    falta = _CREDITO["zera_em"] - _agora()
    if falta <= 0:
        _CREDITO.clear()
        return
    if _CREDITO["restante"] >= max(CREDITO_MINIMO, _CREDITO.get("custo") or 0):
        return
    espera = min(falta + 1, _espera_maxima())
    if espera > 0:
        _esperar(espera, f"Beds24: crédito de 5 min baixo ({_CREDITO['restante']:g}); aguardando {espera:.0f} s para não "
                         "tomar 429")
    _CREDITO.clear()


_PREFIXO_BASE = urllib.parse.urlsplit(BASE).path


def _abrir(req):
    """JSON da resposta, respeitando o crédito de 5 min. Com 429, espera o ResetsIn (ou 60 s sem ele) e tenta 1 vez só;
    o segundo 429, ou outro erro HTTP, sobe como HTTPError para o _get/_post. Cada tentativa entra em _CHAMADAS."""
    caminho = urllib.parse.urlsplit(req.full_url).path
    rotulo = f"{req.get_method()} {caminho[len(_PREFIXO_BASE):] if caminho.startswith(_PREFIXO_BASE) else caminho}"
    for tentativa in (1, 2):
        _esperar_credito()
        _CHAMADAS[rotulo] += 1
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                _anotar_credito(r.headers)
                return json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            _anotar_credito(e.headers)
            if e.code != 429 or tentativa == 2:
                raise
            zera = _cabecalho(e.headers, CAB_ZERA_EM)
            espera = min((zera if zera is not None else ESPERA_429_PADRAO_S) + 1, _espera_maxima())
            if espera > 0:
                _esperar(espera, f"Beds24: 429 (crédito de 5 min esgotado); aguardando {espera:.0f} s e tentando 1 vez")
            _CREDITO.clear()


def _get(caminho, params=None, cabecalhos=None):
    url = f"{BASE}{caminho}"
    if params:
        url += "?" + urllib.parse.urlencode(params, doseq=True)
    req = urllib.request.Request(url, headers={"accept": "application/json", **(cabecalhos or {})})
    try:
        return _abrir(req)
    except urllib.error.HTTPError as e:
        corpo = e.read().decode("utf-8", "replace")[:300]
        if e.code in (401, 403) and "token" not in (cabecalhos or {}) and "refreshToken" not in (cabecalhos or {}):
            raise ErroBeds24(f"HTTP {e.code} em {caminho}: sem credencial. Cadastre o long life token do Beds24 em "
                             "Network secrets (cabeçalho `token`, sem prefixo, site beds24.com) ou BEDS24_TOKEN.",
                             e.code) from None
        raise ErroBeds24(f"HTTP {e.code} em {caminho}: {corpo}", e.code) from None


def _post(caminho, corpo, cabecalhos):
    req = urllib.request.Request(f"{BASE}{caminho}", data=json.dumps(corpo).encode("utf-8"), method="POST",
                                 headers={"accept": "application/json", "content-type": "application/json", **cabecalhos})
    try:
        return _abrir(req)
    except urllib.error.HTTPError as e:
        raise ErroBeds24(f"HTTP {e.code} em POST {caminho}: {e.read().decode('utf-8', 'replace')[:300]}", e.code) from None


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


ARQ_TOKEN_ESCRITA = os.environ.get("BEDS24_TOKEN_ESCRITA_FILE", "/root/.beds24/token_escrita")
MARGEM_TOKEN_S = 300  # 5 min antes do vencimento o token guardado já não serve


def _token_guardado():
    """Token de escrita guardado e ainda válido (vence_em − 5 min), ou None. Arquivo com permissão além de 600 não é
    usado (é regravado com 600 na próxima troca)."""
    try:
        if stat.S_IMODE(os.stat(ARQ_TOKEN_ESCRITA).st_mode) & 0o077:
            return None
        with open(ARQ_TOKEN_ESCRITA, encoding="utf-8") as f:
            d = json.load(f)
        tok, vence = d["token"], float(d["vence_em"])
    except (OSError, ValueError, KeyError, TypeError):
        return None
    if not tok or _agora() >= vence - MARGEM_TOKEN_S:
        return None
    return tok


def _guardar_token(tok, validade_s):
    vence = _agora() + validade_s
    pasta = os.path.dirname(ARQ_TOKEN_ESCRITA)
    if pasta:
        os.makedirs(pasta, mode=0o700, exist_ok=True)
    dados = json.dumps({"token": tok, "vence_em": vence,
                        "vence_em_utc": dt.datetime.fromtimestamp(vence, dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")})
    tmp = f"{ARQ_TOKEN_ESCRITA}.{os.getpid()}.novo"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    try:
        os.fchmod(fd, 0o600)
        os.write(fd, dados.encode("utf-8"))
    finally:
        os.close(fd)
    os.replace(tmp, ARQ_TOKEN_ESCRITA)


def descartar_token_escrita():
    try:
        os.unlink(ARQ_TOKEN_ESCRITA)
    except OSError:
        pass


def token_escrita():
    """Token de escrita: o guardado em ARQ_TOKEN_ESCRITA (600) enquanto valer, senão um novo tirado do refresh token e
    guardado até o expiresIn devolvido menos 5 min (passo 2, 07/11; o exemplo da spec é 3600 s: nunca supor 24 h, e sem
    expiresIn não guarda). Nunca imprimir."""
    tok = _token_guardado()
    if tok:
        return tok
    r = _get("/authentication/token", cabecalhos={"refreshToken": refresh_token_escrita()})
    if not r.get("token"):
        raise ErroBeds24("O Beds24 não devolveu token de escrita a partir do refresh token.")
    try:
        validade = float(r.get("expiresIn"))
    except (TypeError, ValueError):
        validade = 0.0
    if validade > MARGEM_TOKEN_S:
        try:
            _guardar_token(r["token"], validade)
        except OSError as e:
            print(f"aviso: token de escrita não guardado ({e.strerror or e.__class__.__name__}); segue sem guardar",
                  file=sys.stderr)
    return r["token"]


def _post_escrita(caminho, corpo, sessao):
    """POST com o token de escrita (o guardado enquanto valer). Usado pelo `editar` e pelo `editar-lote` (revisão de
    07/11: o `editar` usava o token guardado sem este tratamento e, com ele revogado, falhava com 401 até o vencimento).
    401 (token guardado revogado ou trocado antes do vencimento): descarta o arquivo, tira outro token e tenta 1 vez por
    execução (`sessao` = {"token", "renovado"}, a mesma para todos os blocos); o 401 não grava nada, então repetir o POST
    não duplica mudança."""
    if not sessao.get("token"):
        sessao["token"] = token_escrita()
    try:
        return _post(caminho, corpo, {"token": sessao["token"]})
    except ErroBeds24 as e:
        if e.codigo != 401 or sessao.get("renovado"):
            raise
    sessao["renovado"] = True
    descartar_token_escrita()
    sessao["token"] = token_escrita()
    return _post(caminho, corpo, {"token": sessao["token"]})


def inventario(token, de, ate, quartos=None, precos=False):
    """{curto: {data: {numAvail, minStay, override}}} do calendário do Beds24 (só os valores postos no calendário;
    minStay cai para o do quarto quando o dia não tem). Com `precos` (includePrices), cada noite traz também o price1
    (só leitura: preço nunca se grava pelo Beds24)."""
    ids = [ID_DO_CURTO[q] for q in (quartos or ID_DO_CURTO)]
    params = {"roomId": ids, "startDate": de, "endDate": ate, "includeNumAvail": "true", "includeMinStay": "true",
              "includeMaxStay": "true", "includeOverride": "true", "includeChannels": "true"}
    if precos:
        params["includePrices"] = "true"
    out = {}
    for item in paginas("/inventory/rooms/calendar", params, token):
        curto = QUARTOS.get(str(item.get("roomId")), (str(item.get("roomId")), 0))[0]
        dias = out.setdefault(curto, {})
        for faixa in item.get("calendar") or []:
            d, fim = dt.date.fromisoformat(faixa["from"]), dt.date.fromisoformat(faixa.get("to") or faixa["from"])
            while d <= fim:
                if de <= d.isoformat() <= ate:
                    dias[d.isoformat()] = {k: faixa.get(k) for k in ("numAvail", "minStay", "maxStay", "override")}
                    canais = {c: (v or {}).get("maxBookings") for c, v in (faixa.get("channels") or {}).items()
                              if isinstance(v, dict) and (v or {}).get("maxBookings") is not None}
                    if canais:
                        dias[d.isoformat()]["canais_max"] = canais
                    if precos:
                        dias[d.isoformat()]["price1"] = faixa.get("price1")
                d += dt.timedelta(days=1)
    return out


CANAIS_LIMITE = ("booking", "expedia", "airbnb")


def corpo_edicao(curto, de, ate, min_stay=None, override=None, num_avail=None, max_stay=None, canais_max=None):
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
    if max_stay is not None:
        # dono 03/11 (pacotes fechados de Natal e Ano Novo): estadia máxima por dia de chegada, para nenhuma reserva
        # entrar nas noites do pacote fora das datas dele
        if not 1 <= int(max_stay) <= 30:
            raise ErroBeds24("estadia máxima fora de 1 a 30")
        if min_stay is not None and int(min_stay) > int(max_stay):
            raise ErroBeds24("estadia mínima maior que a máxima: a noite fica sem venda")
        faixa["maxStay"] = int(max_stay)
    if override is not None:
        if override not in OVERRIDES:
            raise ErroBeds24(f"override inválido: {override} (use {', '.join(OVERRIDES)})")
        faixa["override"] = override
    if num_avail is not None:
        if not 0 <= int(num_avail) <= UNIDADES[curto]:
            raise ErroBeds24(f"disponibilidade fora de 0 a {UNIDADES[curto]} para {curto}")
        faixa["numAvail"] = int(num_avail)
    if canais_max:
        # dono 03/11 ("Vamos fechar as disponibilidades dos 2 hoteis ... na booking apenas"): limite de reservas por canal
        # no calendário (0 fecha só aquele canal; None tira o limite e reabre). Não mexe no numAvail dos outros canais.
        ch = {}
        for canal, v in canais_max.items():
            if canal not in CANAIS_LIMITE:
                raise ErroBeds24(f"canal inválido: {canal} (use {', '.join(CANAIS_LIMITE)})")
            if v is not None and not 0 <= int(v) <= UNIDADES[curto]:
                raise ErroBeds24(f"limite do canal fora de 0 a {UNIDADES[curto]} para {curto}")
            # 03/11: a API ignora maxBookings 0 numérico (responde success sem gravar) e grava o texto "0"; manda texto
            ch[canal] = {"maxBookings": None if v is None else str(int(v))}
        faixa["channels"] = ch
    if len(faixa) == 2:
        raise ErroBeds24("nada a mudar: informe --min-stay, --max-stay, --override, --num-avail ou o limite do canal")
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


STATUS_TODOS = ["confirmed", "new", "request", "cancelled", "black", "inquiry"]
# Lista branca da reserva "crua sem nomes" (passo 2): só o que a operação usa. Fora: nome, contato, endereço, textos
# livres (comments, notes, message, custom1..10, reference, groupNote, flagText), hóspedes, infoItems e tokens de cartão.
CAMPOS_RESERVA = ("id", "masterId", "propertyId", "roomId", "unitId", "roomQty", "status", "subStatus", "statusCode",
                  "arrival", "departure", "numAdult", "numChild", "price", "deposit", "tax", "commission",
                  "rateDescription", "offerId", "referer", "channel", "apiSource", "apiSourceId", "apiReference",
                  "bookingTime", "modifiedTime", "cancelTime")
# itens de fatura sem a descrição (texto livre) e sem quem lançou
CAMPOS_ITEM_FATURA = ("id", "bookingId", "invoiceId", "invoiceDate", "invoiceeId", "type", "subType", "status", "qty",
                      "amount", "lineTotal", "vatRate", "createTime")


def sem_nomes(b):
    """Reserva crua do Beds24 só com os campos da lista branca (com invoiceItems): pode ir para arquivo e commit."""
    out = {k: b[k] for k in CAMPOS_RESERVA if k in b}
    if isinstance(out.get("rateDescription"), str):
        # conferência de 07/11: texto que a equipe pode digitar na reserva manual; só os 120 primeiros caracteres,
        # como a `tarifa` do reservas_abaixo.avaliar
        out["rateDescription"] = out["rateDescription"][:120]
    if isinstance(b.get("invoiceItems"), list):
        out["invoiceItems"] = [{k: i[k] for k in CAMPOS_ITEM_FATURA if k in i}
                               for i in b["invoiceItems"] if isinstance(i, dict)]
    return out


def _instante(s):
    if not s:
        return None
    try:
        x = dt.datetime.fromisoformat(str(s).replace("Z", "+00:00"))
    except ValueError:
        return None
    return x if x.tzinfo else x.replace(tzinfo=dt.timezone.utc)


def alterada_desde(b, desde):
    """A reserva foi criada ou alterada a partir de `desde` (o mesmo corte do modifiedFrom)? Sem data, conta."""
    t, t0 = _instante(b.get("modifiedTime")) or _instante(b.get("bookingTime")), _instante(desde)
    return t is None or t0 is None or t >= t0


def _ler_json(caminho):
    with open(caminho, encoding="utf-8") as f:
        return json.load(f)


# Revisão de 07/11: arquivo reaproveitado só vale na rotina em que foi lido (as rotinas são de 3 em 3 h).
IDADE_MAXIMA_RESERVAS_H = 3.0     # `reservas --json` mais velho que isso é recusado (ocupação e receita velhas)
IDADE_MAXIMA_INVENTARIO_H = 2.0   # price1 do --inventario mais velho que isso é de outra rotina: aviso


def _idade_h(lido_em):
    """Horas desde `lido_em` (ISO) pelo relógio do script, ou None sem data legível."""
    t = _instante(lido_em)
    return None if t is None else (_agora() - t.timestamp()) / 3600


def aviso_inventario(inv, caminho):
    """Texto de aviso quando o price1 do --inventario não é desta rotina (sem `_lido_em` ou lido há mais de 2 h); None
    quando está em dia. Só aviso: a avaliação segue com esse price1 (plano, passo 2: "o price1 é o do início da rotina")."""
    lido = inv.get("_lido_em") if isinstance(inv, dict) else None
    idade = _idade_h(lido)
    if idade is None:
        return f"inventário {caminho} sem _lido_em: não dá para saber de quando é o price1"
    if idade > IDADE_MAXIMA_INVENTARIO_H:
        return (f"price1 do inventário {caminho} lido em {lido}, há {idade:.1f} h (de outra rotina): rode inventario "
                "--precos --json de novo para usar o price1 desta rotina")
    return None


def ler_reservas_arquivo(caminho, de=None, ate=None, desde=None):
    """Reservas de um arquivo já lido (sem nova chamada), com a cobertura conferida (revisão de 07/11: lista solta,
    arquivo de outro tipo ou velho davam ocupação cortada e vigia sem alerta, com código 0):
    - com `de`/`ate` (janelas, comparar, concorrencia_hidro_vista; noites de `de` a `ate`, exclusivo): só o
      `reservas --json` ({de, ate, lido_em, data}) cuja janela contenha a pedida e lido há até 3 h;
    - com `desde` (vigia_seguranca e reservas_abaixo): só o `rodada --json` ({desde, lido_em, reservas}) com o desde do
      arquivo até o pedido e o pedido até o lido_em; avisa no stderr que o que mudou depois do lido_em não entra. O
      `reservas --json` é recusado: é leitura por janela de estadia, sem o cancelamento de estadia fora dela.
    Fora disso, ErroBeds24 (o comando sai com código 2). Sem `de`, `ate` nem `desde`, devolve a lista sem conferir."""
    try:
        d = _ler_json(caminho)
    except (OSError, ValueError) as e:
        raise ErroBeds24(f"não consegui ler as reservas de {caminho}: {e}") from None
    if de is None and ate is None and desde is None:
        lista = d if isinstance(d, list) else next((d.get(k) for k in ("data", "reservas") if isinstance(d, dict) and
                                                    isinstance(d.get(k), list)), None)
        if not isinstance(lista, list):
            raise ErroBeds24(f"{caminho} não traz lista de reservas (chave data ou reservas)")
        return lista
    if not isinstance(d, dict):
        raise ErroBeds24(f"{caminho} é uma lista solta de reservas, sem dizer o que foi lido nem quando: use o arquivo do "
                         + ("`beds24.py reservas --de --ate --json`" if desde is None else "`beds24.py rodada --json`")
                         + " desta rotina, ou rode sem o arquivo (leitura ao vivo)")
    if desde is None:
        return _reservas_da_janela(caminho, d, de, ate)
    return _reservas_desde(caminho, d, desde)


def _reservas_da_janela(caminho, d, de, ate):
    dica = (f"rode beds24.py reservas --de {de} --ate {ate} --json <arquivo> (ou o comando sem --reservas, com a "
            "leitura ao vivo)")
    if d.get("desde") and not d.get("de"):
        raise ErroBeds24(f"{caminho} traz só as reservas alteradas desde {d['desde']}: para janelas, comparar e "
                         "ocupação use beds24.py reservas --de --ate --json")
    lista = d.get("data")
    f_de, f_ate = d.get("de"), d.get("ate")
    if not f_de or not f_ate or not isinstance(lista, list):
        raise ErroBeds24(f"{caminho} não é um `beds24.py reservas --json` (faltam de, ate ou data): sem saber a janela "
                         f"lida, a ocupação pode sair cortada; {dica}")
    if (de is not None and str(de) < str(f_de)) or (ate is not None and str(ate) > str(f_ate)):
        raise ErroBeds24(f"{caminho} cobre {f_de} a {f_ate}; este comando precisa de {de} a {ate}: rode beds24.py "
                         f"reservas --de {de} --ate {ate} --json <arquivo>")
    idade = _idade_h(d.get("lido_em"))
    if idade is None:
        raise ErroBeds24(f"{caminho} não diz quando foi lido (lido_em): {dica}")
    if idade > IDADE_MAXIMA_RESERVAS_H:
        raise ErroBeds24(f"{caminho} foi lido em {d['lido_em']}, há {idade:.1f} h (mais de {IDADE_MAXIMA_RESERVAS_H:g} h: "
                         f"de outra rotina; as reservas e os cancelamentos depois disso não estão nele): {dica}")
    return lista


def _reservas_desde(caminho, d, desde):
    dica = "use o `beds24.py rodada --json` desta rotina, ou rode sem --arquivo (leitura ao vivo)"
    if d.get("de") or d.get("ate"):
        raise ErroBeds24(f"{caminho} é leitura por janela de estadia (reservas --json, {d.get('de')} a {d.get('ate')}): "
                         "cancelamento ou alteração de estadia fora dessa janela não está nele e daria falso negativo; "
                         + dica)
    lista = d.get("reservas")
    if not d.get("desde") or not isinstance(lista, list):
        raise ErroBeds24(f"{caminho} não é um `beds24.py rodada --json` (faltam desde ou reservas): sem saber desde "
                         f"quando as alterações foram lidas, o resultado pode sair sem alerta; {dica}")
    t_arq, t_lido, t_ped = _instante(d["desde"]), _instante(d.get("lido_em")), _instante(desde)
    if t_ped is None:
        raise ErroBeds24(f"--desde inválido: {desde} (use AAAA-MM-DDTHH:MM:SSZ)")
    if t_arq is None or t_lido is None:
        raise ErroBeds24(f"{caminho} sem desde ou lido_em legível: {dica}")
    if t_ped < t_arq:
        raise ErroBeds24(f"{caminho} tem as reservas alteradas desde {d['desde']}, depois do --desde {desde} pedido: "
                         "faltariam as alterações do intervalo")
    if t_ped > t_lido:
        raise ErroBeds24(f"{caminho} foi lido em {d['lido_em']}, antes do --desde {desde} pedido: não há nada lido depois "
                         "dele (daria 0 alerta sem ter olhado); rode sem --arquivo")
    print(f"AVISO: {caminho} cobre as alterações de {d['desde']} até {d['lido_em']} (lido_em); o que mudou depois "
          "disso não entra (para isso, rode sem --arquivo)", file=sys.stderr)
    return lista


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
    return movimento_de(paginas("/bookings", params, token), desde)


def movimento_de(lista, desde):
    """Classificação do `movimento` (nova, cancelada, alterada) sobre reservas já lidas. Função pura."""
    saida = []
    for b in lista:
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


PAUSA_REPETICAO_S = 10


def ler_modificadas(token, desde):
    """A leitura única da rodada: GET /bookings (todas as páginas) com modifiedFrom, todos os status e
    includeInvoiceItems. Falhou, repete 1 vez (depois de 10 s; um 429 já foi esperado e repetido no _get, então não
    repete de novo). Falhou de novo: ErroBeds24 com "vigia sem Beds24"."""
    params = {"propertyId": list(PROPRIEDADES), "modifiedFrom": desde, "status": STATUS_TODOS,
              "includeInvoiceItems": "true"}
    erro = None
    for tentativa in (1, 2):
        try:
            return list(paginas("/bookings", params, token))
        except (ErroBeds24, OSError, ValueError, http.client.HTTPException) as e:
            erro = e
            # 429: o _get já esperou o ResetsIn e repetiu (a repetição já aconteceu)
            if getattr(e, "codigo", None) == 429 or tentativa == 2:
                break
            print(f"Beds24: a leitura das reservas falhou ({str(e)[:160]}); repetindo 1 vez", file=sys.stderr)
            _dormir(PAUSA_REPETICAO_S)
    raise ErroBeds24(f"vigia sem Beds24: a leitura das reservas desde {desde} falhou ({str(erro)[:200]}). Use o "
                     "get_pms_reservations do PriceLabs como plano B e ponha 'vigia sem Beds24' no topo do relatório.",
                     getattr(erro, "codigo", None))


def rodada(lista, desde, cal, fatores, alerta, par_seguranca):
    """Sobre a MESMA leitura, na memória: movimento, vigia_seguranca.classificar e reservas_abaixo.avaliar (as mesmas
    classificações dos três comandos separados). `cal` = {(roomId, data): price1}. Devolve (movimento, alertas de
    segurança, reservas abaixo do planejado com `_nome`, que só vai para a tela).
    A leitura vem com invoiceItems (o reservas_abaixo precisa deles), mas o `movimento` de hoje lê sem eles e tira a
    receita do campo price; o receita_por_noite usaria as diárias da fatura (com extra sem invoiceeId, outra receita).
    Para a mesma saída do comando (revisão de 07/11; a sombra compara as duas), o movimento recebe a reserva sem
    invoiceItems."""
    import reservas_abaixo
    import vigia_seguranca
    quartos = {k: v[0] for k, v in QUARTOS.items()}
    novas = [b for b in lista if b.get("status") in ("confirmed", "new")]
    sem_fatura = [{k: v for k, v in b.items() if k != "invoiceItems"} for b in lista]
    return (movimento_de(sem_fatura, desde), vigia_seguranca.classificar(lista, desde, par_seguranca, quartos),
            reservas_abaixo.avaliar(novas, cal, fatores, alerta))


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


MAX_FAIXAS_POST = 100


def _datas(i):
    d, fim = dt.date.fromisoformat(i["de"]), dt.date.fromisoformat(i["ate"])
    while d <= fim:
        yield d.isoformat()
        d += dt.timedelta(days=1)


def _conferir(itens, depois):
    """(erros, itens com erro) da releitura: cada campo gravado contra o que o inventário devolve."""
    erros, com_erro = [], 0
    for i in itens:
        n0 = len(erros)
        for ds in _datas(i):
            v = depois.get(i["quarto"], {}).get(ds, {})
            for campo, chave in (("min_stay", "minStay"), ("max_stay", "maxStay"), ("override", "override"),
                                 ("num_avail", "numAvail")):
                if i.get(campo) is None:
                    continue
                # numAvail volta LÍQUIDO das reservas (gravar 7 com 1 reserva lê 6, 01/11): confira contra
                # `num_avail_esperado` (unidades − reservas da noite) quando o item trouxer
                alvo = i.get("num_avail_esperado", i[campo]) if campo == "num_avail" else i[campo]
                if v.get(chave) != alvo:
                    erros.append(f"{i['quarto']} {ds}: {chave} {v.get(chave)} (esperado {alvo})")
            for canal, alvo in (i.get("canais_max") or {}).items():
                lido = (v.get("canais_max") or {}).get(canal)
                if lido != alvo:
                    erros.append(f"{i['quarto']} {ds}: limite {canal} {lido} (esperado {alvo})")
        com_erro += len(erros) > n0
    return erros, com_erro


def editar_lote(plano, aplicar=False, max_faixas=MAX_FAIXAS_POST):
    """Plano = [{quarto, de, ate, min_stay?, min_stay_antes?, override?, num_avail?, num_avail_esperado?, motivo}].
    Lê o inventário antes; item com `min_stay_antes` cujo minStay lido diverge (em qualquer noite da faixa) é PULADO e
    relatado (revisão de 05/11: o lote é feito com a leitura de horas antes; reserva, cancelamento, o envio do PriceLabs
    ou a equipe podem ter mudado a noite, e gravar por cima desfaria isso). Os itens que ficam vão em blocos de até
    `max_faixas` faixas por POST (agrupadas por quarto dentro do bloco), e cada bloco é relido e conferido item a item
    (`num_avail_esperado` = o numAvail líquido que deve voltar; sem ele, o gravado) antes do próximo; um bloco com erro de
    gravação para o lote. Sem --aplicar só mostra antes, mudança, pulados e blocos. Se houve espera pelo crédito de 5 min
    (saldo baixo ou 429), o resumo traz `espera_credito_s` (o total; sem espera, a chave não aparece)."""
    if not plano:
        return {"itens": 0, "aplicar": aplicar, "pulados": [], "blocos": [], "erros": [], "conferidos": 0}
    e0 = len(_ESPERAS)
    corpos = []
    for it in plano:
        c = corpo_edicao(it["quarto"], it["de"], it["ate"], it.get("min_stay"), it.get("override"), it.get("num_avail"),
                         it.get("max_stay"), it.get("canais_max"))
        corpos.append((it, c[0]["roomId"], c[0]["calendar"]))
    de = min(i["de"] for i in plano)
    ate = max(i["ate"] for i in plano)
    quartos = sorted({i["quarto"] for i in plano})
    antes = inventario(token_de_acesso(), de, ate, quartos)
    pulados, ficam = [], []
    for it, rid, cal in corpos:
        if it.get("min_stay_antes") is not None:
            lidos = {ds: (antes.get(it["quarto"], {}).get(ds) or {}).get("minStay") for ds in _datas(it)}
            difere = {ds: v for ds, v in lidos.items() if v != it["min_stay_antes"]}
            if difere:
                pulados.append({"quarto": it["quarto"], "de": it["de"], "ate": it["ate"],
                                "min_stay_antes": it["min_stay_antes"], "min_stay": it.get("min_stay"), "lido": difere,
                                "por_que": ("já no alvo" if all(v == it.get("min_stay") for v in difere.values())
                                            else "minStay mudou desde o plano: refaça com o inventário novo")})
                continue
        ficam.append((it, rid, cal))
    blocos = [ficam[k:k + max(1, int(max_faixas))] for k in range(0, len(ficam), max(1, int(max_faixas)))]
    resumo = {"itens": len(plano), "quartos": quartos, "aplicar": aplicar, "pulados": pulados,
              "a_gravar": len(ficam), "max_faixas_por_post": max_faixas, "n_blocos": len(blocos)}
    if not aplicar:
        resumo["amostra"] = [{"quarto": i["quarto"], "de": i["de"], "antes": antes.get(i["quarto"], {}).get(i["de"]),
                              "mudanca": {k: i[k] for k in ("min_stay", "max_stay", "override", "num_avail", "canais_max")
                                          if i.get(k) is not None}}
                             for i, _, _ in ficam[:12]]
        return _com_espera(resumo, e0)
    sessao = {}  # token de escrita tirado na 1ª gravação, o mesmo para todos os blocos (401: troca 1 vez)
    erros, saida_blocos, conferidos = [], [], 0
    for n, bloco in enumerate(blocos, 1):
        por_quarto = {}
        for _, rid, cal in bloco:
            por_quarto.setdefault(rid, []).extend(cal)
        itens = [it for it, _, _ in bloco]
        info = {"bloco": n, "faixas": sum(len(c) for _, _, c in bloco), "de": min(i["de"] for i in itens),
                "ate": max(i["ate"] for i in itens)}
        corpo = [{"roomId": rid, "calendar": cal} for rid, cal in por_quarto.items()]
        try:
            resp = _post_escrita("/inventory/rooms/calendar", corpo, sessao)
        except ErroBeds24 as e:
            info.update(resposta_ok=False, erro=str(e))
            saida_blocos.append(info)
            erros.append(f"bloco {n}: {e}")
            resumo["parou_no_bloco"] = n
            break
        info["resposta_ok"] = all((r or {}).get("success", True) for r in (resp if isinstance(resp, list) else [resp]))
        depois = inventario(token_de_acesso(), info["de"], info["ate"], sorted({i["quarto"] for i in itens}))
        e_bloco, com_erro = _conferir(itens, depois)
        info.update(erros=e_bloco, conferidos=len(itens) - com_erro)
        conferidos += info["conferidos"]
        erros += e_bloco
        saida_blocos.append(info)
        if not info["resposta_ok"]:
            resumo["parou_no_bloco"] = n
            break
    resumo.update({"resposta_ok": all(b.get("resposta_ok") for b in saida_blocos), "blocos": saida_blocos,
                   "conferidos": conferidos, "erros": erros})
    return _com_espera(resumo, e0)


def _com_espera(resumo, e0):
    """Soma as esperas pelo crédito desde o índice `e0` de _ESPERAS e põe no resumo (só quando houve)."""
    total = round(sum(_ESPERAS[e0:]), 1)
    if total:
        resumo["espera_credito_s"] = total
    return resumo


CAMPOS_LOTE = ("min_stay", "max_stay", "override", "num_avail")


def juntar_planos(planos):
    """Junta vários planos do editar-lote numa lista só, na ordem dada (o urgente primeiro), para irem nos mesmos blocos
    de POST. `planos` = [(nome, [itens])]. A mesma noite e o mesmo campo com valores diferentes em planos diferentes é
    ERRO antes de qualquer leitura ou gravação (o último gravado venceria sem ninguém decidir); dentro de um plano só
    nada muda em relação a antes. Devolve (itens, [{arquivo, itens}])."""
    vistos, conflitos, itens, resumo = {}, [], [], []
    for nome, plano in planos:
        if not isinstance(plano, list):
            raise ErroBeds24(f"{nome}: o plano do editar-lote é uma lista de itens")
        repetidos = 0
        for it in plano:
            valores = [(c, it.get(c)) for c in CAMPOS_LOTE if it.get(c) is not None]
            valores += [(f"canal {k}", v) for k, v in sorted((it.get("canais_max") or {}).items())]
            try:
                datas = list(_datas(it))
                it["quarto"]
            except (KeyError, TypeError, ValueError):
                raise ErroBeds24(f"{nome}: item sem quarto, de ou ate válidos: {str(it)[:120]}") from None
            # conferência de 07/11: o mesmo item (mesmas noites, campos e valores) já trazido por um plano anterior não
            # vai de novo ao POST (iria duas vezes e o `conferidos` contaria em dobro), como no calendario_12m.lote_unico
            ja_visto = bool(valores) and all(
                (it["quarto"], ds, campo) in vistos and vistos[(it["quarto"], ds, campo)][0] == v
                and vistos[(it["quarto"], ds, campo)][1] != nome
                for ds in datas for campo, v in valores)
            for ds in datas:
                for campo, v in valores:
                    chave = (it["quarto"], ds, campo)
                    if chave in vistos and vistos[chave][0] != v and vistos[chave][1] != nome:
                        conflitos.append(f"{it['quarto']} {ds} {campo}: {vistos[chave][0]} em {vistos[chave][1]} e "
                                         f"{v} em {nome}")
                    vistos.setdefault(chave, (v, nome))
            if ja_visto:
                repetidos += 1
            else:
                itens.append(it)
        r = {"arquivo": nome, "itens": len(plano)}
        if repetidos:
            r["repetidos_ignorados"] = repetidos
        resumo.append(r)
    if conflitos:
        raise ErroBeds24(f"planos em conflito ({len(conflitos)}): " + "; ".join(conflitos[:5]) +
                         (" ..." if len(conflitos) > 5 else "") + ". Nada foi lido nem gravado.")
    return itens, resumo


def _agora_iso():
    return dt.datetime.fromtimestamp(_agora(), dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def main_rodada(a):
    """Comando `rodada`: 1 leitura das reservas alteradas, as três verificações na memória, JSON sem nomes (nomes só na
    tela, com --nomes). Código 3 = vigia sem Beds24 (a leitura falhou 2 vezes); 2 = reservas abaixo sem o price1."""
    import reservas_abaixo
    import vigia_seguranca
    c0, e0 = dict(_CHAMADAS), len(_ESPERAS)
    try:
        tok = token_de_acesso()
        lido_em = _agora_iso()
        lista = ler_modificadas(tok, a.desde)
    except ErroBeds24 as e:
        msg = str(e) if str(e).startswith("vigia sem Beds24") else f"vigia sem Beds24: {e}"
        print(f"ERRO: {msg}", file=sys.stderr)
        return 3
    P = reservas_abaixo.carregar_quartos().get("parametros", {})
    fatores, alerta = P.get("fator_canal") or reservas_abaixo.PADRAO_FATOR, float(P.get("alerta_reserva", 0.70))
    # leituras de verdade (revisão de 07/11: era 1 fixo): cada GET /bookings, com a repetição e as páginas
    leituras = _CHAMADAS["GET /bookings"] - c0.get("GET /bookings", 0)
    avisos, inv = [], None
    if a.inventario:
        try:
            inv = _ler_json(a.inventario)
        except (OSError, ValueError) as e:
            avisos.append(f"inventário {a.inventario} ilegível ({e.__class__.__name__}): price1 lido ao vivo")
        else:
            velho = aviso_inventario(inv, a.inventario)
            if velho:
                avisos.append(velho)
    novas = [b for b in lista if b.get("status") in ("confirmed", "new")]
    try:
        cal, info_precos = reservas_abaixo.precos_para(novas, lambda: tok, inv)
    except (ErroBeds24, OSError, ValueError, http.client.HTTPException) as e:
        cal, info_precos = None, {"erro": str(e)[:200]}
    mov, alertas, abaixo = rodada(lista, a.desde, cal or {}, fatores, alerta, vigia_seguranca.parametros())
    if cal is None:
        abaixo = None
        avisos.append(f"reservas abaixo do planejado sem o price1 ({info_precos['erro']}): rode reservas_abaixo.py "
                      f"--arquivo {a.json or '<rodada.json>'} --desde {a.desde} depois")
    chamadas = {k: v - c0.get(k, 0) for k, v in sorted(_CHAMADAS.items()) if v - c0.get(k, 0)}
    saida = {"desde": a.desde, "lido_em": lido_em, "lidas": len(lista), "leituras_bookings": leituras,
             "chamadas": chamadas, "espera_credito_s": round(sum(_ESPERAS[e0:]), 1),
             "movimento": mov, "vigia": {"desde": a.desde, "lidas": len(lista), "alertas": alertas},
             "abaixo": None if abaixo is None else [{k: v for k, v in x.items() if k != "_nome"} for x in abaixo],
             "alerta_reserva": alerta, "precos": info_precos, "avisos": avisos,
             "reservas": [sem_nomes(b) for b in lista]}
    print(f"Rodada do Beds24 desde {a.desde}: {leituras} leitura(s), {len(lista)} reserva(s) criada(s) ou alterada(s)")
    print("== Segurança (topo do relatório) ==")
    vigia_seguranca.imprimir(len(lista), a.desde, alertas)
    print("== Reservas abaixo do planejado ==")
    if abaixo is None:
        print("sem price1: não avaliadas nesta rodada")
    else:
        reservas_abaixo.imprimir(abaixo, alerta, a.nomes)
    print("== Movimento ==")
    tipos = [x["tipo"] for x in mov]
    print(f"{tipos.count('nova')} nova(s), {tipos.count('cancelada')} cancelada(s), {tipos.count('alterada')} alterada(s)")
    for x in mov:
        print(f"- {x['tipo']}: {x['id']} {x['quarto']} {x['chegada']} {x['noites']}n R$ {x['receita']:.0f} "
              f"{x['canal'] or '-'} ({x['status']})")
    for x in avisos:
        print(f"AVISO: {x}")
    if a.json:
        with open(a.json, "w", encoding="utf-8") as f:
            json.dump(saida, f, ensure_ascii=False, indent=1)
        print(f"gravado em {a.json} (sem nomes de hóspedes)")
    return 2 if abaixo is None else 0


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("teste")
    r = sub.add_parser("reservas"); r.add_argument("--de", required=True); r.add_argument("--ate", required=True)
    r.add_argument("--json", help="grava a resposta crua SEM nomes (com invoiceItems e a janela de/ate lida), para o "
                                  "--reservas do janelas, do comparar e do concorrencia_hidro_vista.py")
    j = sub.add_parser("janelas"); j.add_argument("--hoje", required=True); j.add_argument("--json")
    j.add_argument("--reservas", help="arquivo do `reservas --json` desta rotina (sem nova leitura; precisa cobrir hoje "
                                      "a hoje+60 e ter sido lido há até 3 h)")
    lv = sub.add_parser("livro"); lv.add_argument("--de", required=True); lv.add_argument("--ate", required=True); lv.add_argument("--json")
    mv = sub.add_parser("movimento"); mv.add_argument("--desde", required=True, help="AAAA-MM-DDTHH:MM:SSZ (UTC)"); mv.add_argument("--json")
    cp = sub.add_parser("comparar"); cp.add_argument("--coleta", required=True); cp.add_argument("--de", required=True)
    cp.add_argument("--ate", required=True); cp.add_argument("--json")
    cp.add_argument("--reservas", help="arquivo do `reservas --json` desta rotina (sem nova leitura; precisa cobrir --de "
                                       "a --ate e ter sido lido há até 3 h)")
    iv = sub.add_parser("inventario"); iv.add_argument("--de", required=True); iv.add_argument("--ate", required=True)
    iv.add_argument("--quarto", nargs="*"); iv.add_argument("--json")
    iv.add_argument("--precos", action="store_true", help="inclui o price1 de cada noite (includePrices; só leitura)")
    rd = sub.add_parser("rodada", help="1 leitura das reservas: movimento + vigia + reservas abaixo")
    rd.add_argument("--desde", required=True, help="início da rodada anterior menos 5 min, AAAA-MM-DDTHH:MM:SSZ (UTC)")
    rd.add_argument("--json", help="saída sem nomes de hóspedes (movimento, vigia, abaixo e as reservas cruas)")
    rd.add_argument("--inventario", help="`inventario --precos --json` da rotina: price1 sem nova leitura")
    rd.add_argument("--nomes", action="store_true", help="mostra o nome do hóspede na tela (nunca em arquivo)")
    ed = sub.add_parser("editar"); ed.add_argument("--quarto", required=True); ed.add_argument("--de", required=True)
    ed.add_argument("--ate", required=True); ed.add_argument("--min-stay", type=int); ed.add_argument("--override")
    ed.add_argument("--max-stay", type=int, help="estadia máxima por dia de chegada (pacotes fechados do dono, 03/11)")
    ed.add_argument("--num-avail", type=int); ed.add_argument("--motivo", required=True)
    ed.add_argument("--aplicar", action="store_true", help="grava no Beds24 (sem isto, só simula)")
    el = sub.add_parser("editar-lote")
    el.add_argument("--plano", required=True, action="append",
                    help="lote JSON; repita para juntar vários na mesma rodada, na ordem dada (o urgente primeiro)")
    el.add_argument("--aplicar", action="store_true")
    el.add_argument("--max-faixas", type=int, default=MAX_FAIXAS_POST, help="faixas por POST (padrão 100); cada bloco é relido")
    a = ap.parse_args(argv)
    if a.cmd == "rodada":
        return main_rodada(a)
    if a.cmd == "editar-lote":
        try:
            if len(a.plano) == 1:
                plano, origem = json.load(open(a.plano[0], encoding="utf-8")), None
            else:
                if len(set(a.plano)) != len(a.plano):
                    raise ErroBeds24("o mesmo --plano foi passado duas vezes")
                plano, origem = juntar_planos([(p, _ler_json(p)) for p in a.plano])
            resumo = editar_lote(plano, a.aplicar, a.max_faixas)
            if origem:
                resumo["planos"] = origem
            print(json.dumps(resumo, ensure_ascii=False, indent=1))
            return 0
        except ErroBeds24 as e:
            print(f"ERRO: {e}", file=sys.stderr)
            return 2
    if a.cmd == "editar":
        try:
            corpo = corpo_edicao(a.quarto, a.de, a.ate, a.min_stay, a.override, a.num_avail, a.max_stay)
            antes = inventario(token_de_acesso(), a.de, a.ate, [a.quarto]).get(a.quarto, {})
            print(json.dumps({"quarto": a.quarto, "motivo": a.motivo, "antes": antes, "mudanca": corpo[0]["calendar"][0],
                              "aplicar": a.aplicar}, ensure_ascii=False, indent=1))
            if not a.aplicar:
                print("SIMULAÇÃO: nada gravado (use --aplicar)")
                return 0
            r = _post_escrita("/inventory/rooms/calendar", corpo, {})  # 401 com o token guardado: troca 1 vez
            depois = inventario(token_de_acesso(), a.de, a.ate, [a.quarto]).get(a.quarto, {})
            print(json.dumps({"resposta": r, "depois": depois}, ensure_ascii=False, indent=1))
            return 0
        except ErroBeds24 as e:
            print(f"ERRO: {e}", file=sys.stderr)
            return 2
    try:
        # com --reservas (janelas, comparar) não há leitura nenhuma no Beds24, nem a troca do token
        tok = None if getattr(a, "reservas", None) else token_de_acesso()
        if a.cmd == "teste":
            props = _get("/properties", {"id": list(PROPRIEDADES)}, {"token": tok} if tok else None)
            print(json.dumps([{"id": p.get("id"), "nome": p.get("name")} for p in props.get("data") or []], ensure_ascii=False))
            return 0
        if a.cmd == "reservas":
            lido_em = _agora_iso()
            lista = reservas(a.de, a.ate, tok)
            if a.json:
                # passo 2: a resposta crua (lista branca, sem nomes) com invoiceItems, para reaproveitar sem nova leitura;
                # as chaves do arquivo antigo continuam em cada reserva (são campos da API) + receita_hospedagem
                texto = json.dumps({"de": a.de, "ate": a.ate, "ate_exclusivo": True, "lido_em": lido_em,
                                    "lidas": len(lista),
                                    "data": [sem_nomes(b) | {"receita_hospedagem": round(receita_por_noite(b)[2], 2)}
                                             for b in lista]}, ensure_ascii=False, indent=1)
            else:
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
            inv = inventario(tok, a.de, a.ate, a.quarto, precos=a.precos)
            inv["_lido_em"] = _agora_iso()  # prova da órfã (05/11)
            texto = json.dumps(inv, ensure_ascii=False, indent=1)
        elif a.cmd == "comparar":
            de, ate = dt.date.fromisoformat(a.de), dt.date.fromisoformat(a.ate)
            coleta = json.load(open(a.coleta, encoding="utf-8"))
            coleta = coleta.get("result", coleta) if isinstance(coleta, dict) else coleta
            if isinstance(coleta, str):
                coleta = json.loads(coleta)
            lista = ler_reservas_arquivo(a.reservas, a.de, a.ate) if a.reservas else reservas(a.de, a.ate, tok)
            texto = json.dumps(comparar(lista, coleta, de, ate), ensure_ascii=False, indent=1)
        else:
            hoje = dt.date.fromisoformat(a.hoje)
            fim = (hoje + dt.timedelta(days=60)).isoformat()
            lista = ler_reservas_arquivo(a.reservas, a.hoje, fim) if a.reservas else reservas(a.hoje, fim, tok)
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
    # roda pelo módulo importado: a rodada usa vigia_seguranca e reservas_abaixo, que importam `beds24`; assim há um só
    # estado (crédito de 5 min, token guardado) em vez de duas cópias do módulo (__main__ e beds24)
    import beds24 as _modulo
    sys.exit(_modulo.main())

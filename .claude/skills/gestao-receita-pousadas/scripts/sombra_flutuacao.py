"""Sombra da flutuação ("semana em paralelo", decisão do dono de 09/11/2026): compara, sem gravar nada no PriceLabs, o
preço que gravamos por data (quase sempre travado: min = max) com o que o PriceLabs cobraria flutuando sozinho dentro da
faixa, com o mínimo de cada suíte no piso de dia de semana do dono.

Período: fotos diárias de 10/11 a 16/11 (rotina das 07:53). Na revisão de segunda 16/11 o resumo decide o teste na King
Spa 7 (VK7): 2 a 3 semanas destravando só os dias de semana (domingo a quinta). Somente leitura: não chama PriceLabs,
Beds24 nem Booking; lê os arquivos que a rotina já salvou: rec_<Q>.json (recálculo do PriceLabs, refresh_listing_pricing /
get_listing_prices), ov_pl_<Q>.json (regras por data do PriceLabs, quando existir), os planos gravados (--gravados) e o
inventário do Beds24 da coleta (inv.json da pasta do rec, base da foto) e das rotinas leves (fechar).

"Preço solto" (ESTIMATIVA) por noite:
  - bruto = max(piso da noite, min(uncustomized_price, teto)). O uncustomized_price é o preço do algoritmo do PriceLabs
    ANTES das personalizações (última hora, ocupação, dia órfão, distância etc.).
  - calibrado = max(piso, min(uncustomized_price × f, teto)), com f do quarto = mediana(preço real ÷ fórmula) nas noites
    que já flutuam sem ajuste nosso (so_piso e sem_regra, à venda, fora de feriado; fórmula = max(min, min(uncustomized,
    max)) com o piso e o teto da própria noite; a noite presa no piso, com o preço real e a fórmula no piso, não mede
    nada e fica de fora). Com 3 noites ou mais, o fator é medido ("calibrado"); com 1 ou 2, o fator é INDICATIVO (a
    mediana do que há; o texto diz "indicativo" no lugar de "calibrado", mostra o erro medido e avisa quando o bruto e
    o indicativo apontam para lados opostos); sem nenhuma, "sem calibração" (só o bruto). Os números principais do
    resumo (e o destaque da VK7) mostram primeiro o calibrado (ou o indicativo), com o bruto ao lado.
  - piso das 6 suítes com banheira (não a Balcony): o maior entre o piso do dono (domingo a quinta
    limites_seguranca.piso_vitrine_semana_suites (R$ 500) ÷ parametros.fator_vitrine_semana[pousada]; sexta e sábado
    limites_seguranca.piso_vitrine_fds_suites (R$ 850) ÷ parametros.fator_vitrine_fds[pousada]; para cima) e o mínimo do
    anúncio (o PriceLabs nunca cobra abaixo dele: Afrodite R$ 1.500). Mínimo do anúncio = o maior entre o "min" do
    --contexto e o do quartos.json, quando há os dois. "origem_piso" diz qual venceu. Balcony: o próprio mínimo do
    anúncio (--min-balcony, ou o "min" do --contexto, ou o "min" do quartos.json).
  - teto: o máximo do anúncio (--contexto; senão o "max" do quartos.json; padrão R$ 5.000, Afrodite R$ 10.000).
  - feriado, festas e pacote ficam fora da comparação (continuam por data): lista "feriados" de quartos.json (como
    pace.py e calendario_12m.py), as festas de faixas_dono.tipo_da_noite (Natal 23-26/12, entre festas 27-29/12,
    Réveillon 30/12-02/01) e as datas de pacote de regra_h_beds24. O holiday_flag do PriceLabs só é anotado.
Estado da regra na noite: "travada" (min_price = max_price), "so_piso" (dso_flag 1 e max_price > min_price: o preço atual
já é flutuação real do PriceLabs acima do piso da regra), "sem_regra" (sem regra por data: preço do próprio PriceLabs) ou
"ajustada" (flutua, mas a regra por data tem o nosso ajuste percentual "price"/"price_type" no ov_pl_<Q>.json ou no plano:
o preço não é flutuação pura do PriceLabs; fica fora da calibração e é contada à parte). Sem o ov_pl_<Q>.json o texto avisa
que a calibração pode incluir nossos ajustes.

Uso:
  python3 sombra_flutuacao.py registrar --rec-dir DIR --hoje AAAA-MM-DD [--dias 60] [--contexto json]
      [--min-balcony N] [--gravados plano.json[@AAAA-MM-DDTHH:MM:SSZ] ...] [--inv inv.json] [--substituir] [--forcar]
      [--arquivo dados/sombra_flutuacao.json]
    Grava a foto do dia: por quarto e noite (hoje a hoje + dias - 1) data, dia da semana, tipo da noite
    (semana/fds/feriado), estado, preço gravado (e "gravado_fonte": "rec" ou "plano"), preço solto bruto, piso, teto e
    vendidas/total. Rode DEPOIS da gravação da rotina (passo 7) com --gravados <cada plano gravado no dia> (formato do
    validar_plano: {"itens": [{"tipo": "data", "quarto", "data", "min_price", "max_price", ...}]} ou a lista; pode
    repetir): o rec_<Q>.json é da coleta, de antes da gravação. Item com min_price = max_price: a noite fica travada;
    só o piso (sem teto ou teto acima): so_piso (ou ajustada, com percentual) com o piso e gravado desconhecido;
    "apagar": sem_regra. Sem --gravados, vale o rec. O valor do plano vira o preço que o PriceLabs cobra: piso e teto em
    percent_base sobre a base do anúncio (a do --contexto; senão a do quartos.json), como validar_plano.montar_payload
    (piso para cima, teto para baixo, nunca abaixo do piso; ex.: Double R$ 1.232 com base R$ 1.480 vira R$ 1.243).
    Cada plano vale a partir da hora em que foi gravado: a do arquivo (mtime) ou a dada em ARQ@AAAA-MM-DDTHH:MM:SSZ
    (planos da mesma hora, na ordem dos argumentos). Na noite, "trocas" guarda o preço do rec (desde a leitura do
    PriceLabs) e o de cada plano (desde a hora dele); a venda usa o preço que valia na hora em que foi vista. Plano de
    antes da leitura do PriceLabs (o rec já reflete), de outro dia ou de depois do registro: ignorado, e o texto diz.
    Plano gravado mais tarde no mesmo dia (rotinas leves): registre de novo a foto do dia, com a mesma --rec-dir e
    todos os planos do dia (--gravados de cada um); sem isso, a troca de preço da rotina leve não entra.
    --inv: a leitura do inventário do Beds24 da coleta (padrão: o inv.json da --rec-dir, quando existe), guardada na
    seção "beds24" como as do fechar. Ela é a BASE da foto: o Beds24 na hora da foto, contra o qual as leituras
    seguintes contam as vendas (Beds24 contra Beds24). Sem "_lido_em" ou de outro dia: fica de fora (o texto diz; com
    --inv explícito é erro).
    --hoje tem de ser a data de hoje em Brasília (America/Sao_Paulo), e o rec_<Q>.json tem de começar em --hoje; senão o
    quarto vai para "faltando" com o motivo "rec de outro dia". --forcar aceita outro --hoje e rec que começa em --hoje
    ou antes (reprocessar coletas antigas). Registrar de novo no mesmo dia junta por quarto (os quartos que não vieram
    agora ficam como estavam); --substituir troca a foto inteira. A hora em que o PriceLabs foi lido ("observado_em" por
    quarto) é a do rec_<Q>.json (a coleta grava o arquivo na hora), nunca depois do registro; fora do dia da foto, vale
    07:53 do dia (estimado). Gravação atômica (temporário + os.replace); arquivo ilegível nunca é apagado nem regravado.
    --contexto aceita {quarto: {min, base, max}} ou a saída da coleta (result.contexto.sync, por listing_id).
  python3 sombra_flutuacao.py fechar --inv inv.json [--lido-em ISO-UTC] [--hoje AAAA-MM-DD] [--dias 60] [--forcar]
      [--arquivo ...]
    Guarda a leitura do inventário do Beds24 das rotinas leves (formato {quarto: {data: {numAvail, ...}}, "_lido_em":
    ...}): por quarto e noite presente no inventário (hoje a hoje + dias - 1), as vendidas vistas no Beds24 =
    unidades (quartos.json) - max(numAvail, 0), com o lido_em, na seção "beds24" ({noite: {quarto: [{lido_em,
    vendidas}]}}). Idempotente pelo lido_em (a mesma leitura de novo substitui a anterior). --lido-em padrão: o
    "_lido_em" do inventário (diferença de mais de 15 minutos entre os dois: erro, salvo --forcar). --hoje padrão: o dia
    da leitura em Brasília. Leitura que não é de hoje em Brasília, ou --hoje diferente de hoje ou do dia da leitura:
    erro, salvo --forcar. Rode depois de cada leitura do inventário nas rotinas leves. Uma leitura do mesmo dia feita
    antes da foto também serve de base dela (o registrar já guarda a da coleta).
  python3 sombra_flutuacao.py resumo --de AAAA-MM-DD --ate AAAA-MM-DD [--arquivo ...] [--json]
    Compara as fotos do período, por quarto, nas noites travadas fora de feriado (o que o teste destravaria):
    - preço: gravado e solto (calibrado ou indicativo, e bruto) médios nas noites de semana e de fim de semana (última
      foto de cada noite, com o preço que vale no fim do dia; a noite esgotada fica de fora, porque o preço dela não
      vende mais), quantas noites o solto ficaria abaixo ou acima do gravado e em quanto (%);
    - vendas novas entre observações seguidas da mesma noite e quarto: entre fotos, PriceLabs contra PriceLabs
      (pulando as fotos que não têm o quarto); depois da última foto que viu a noite, Beds24 contra Beds24 nas leituras
      do período: a primeira contra a base da foto (a última leitura do Beds24 do mesmo dia até a hora da foto, ou a
      primeira até 30 minutos depois dela; se o PriceLabs da foto já via mais vendidas que a base, vale ele, para a
      mesma venda não entrar duas vezes). Sem base, o salto foto → Beds24 não entra nas vendas: vai para "diferença
      PriceLabs × Beds24", como a diferença entre a base e o PriceLabs na hora da foto (ocupação que o PriceLabs não
      vê). O gravado e o solto são os que valiam na hora em que a venda foi vista (os da última foto antes dela, com as
      trocas de preço dos planos até essa hora; troca no meio do intervalo é anotada). Dinheiro deixado na mesa
      (solto > gravado) e venda que o solto faria mais barata (solto < gravado), supondo o mesmo volume de vendas
      (limite otimista);
    - noites que passaram com unidades livres (estado final mais tardio: leitura do Beds24 depois da última foto, ou a
      foto das 07:53 do dia, com a base do Beds24 quando há, "sem leitura do fim do dia") e se tiveram alguma venda no
      período;
    - noites que já flutuam (so_piso, sem_regra): calibração; ajustadas contadas à parte.
    A VK7 vem em destaque (candidata ao teste): vendas e noites livres separadas por tipo; a manchete só com os dias de
    semana (o que o teste destravaria) e o fim de semana numa linha "fora do teste". --json imprime o resumo em JSON.
Sai 0 em sucesso e 2 em erro (data inválida, --hoje fora de hoje sem --forcar, nenhum rec_<Q>.json de hoje, arquivo
corrompido, nenhuma foto no período). Sem nomes de hóspedes: só preços e contagens.
"""

from __future__ import annotations

import argparse
import copy
import json
import math
import os
import statistics
import sys
import tempfile
from contextlib import contextmanager
from datetime import date, datetime, time, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from comum import (DIAS_SEMANA, PASTA_SKILL, carregar_quartos, data_de_pacote, hoje_brt, ler_instante,  # noqa: E402
                   ler_json, periodo_feriado, reais, vendidas_total)
from faixas_dono import TIPOS as TIPOS_FAIXAS, tipo_da_noite as tipo_faixas  # noqa: E402
from validar_plano import em_reais, piso_pct, teto_pct  # noqa: E402

ARQ_SOMBRA = PASTA_SKILL / "dados" / "sombra_flutuacao.json"
DIAS_PADRAO = 60
DESTAQUE = "VK7"
TETO_PADRAO = 5000.0
TETO_PADRAO_QUARTO = {"Afrodite": 10000.0}
TIPOS_COMPARADOS = ("semana", "fds")
ESTADOS = ("travada", "so_piso", "sem_regra", "ajustada")
FLUTUAM = ("so_piso", "sem_regra")
# festas de faixas_dono.tipo_da_noite que ficam fora da comparação como feriado (dono 03/11: pacotes de Natal e Ano Novo)
FESTAS = ("natal", "entre_festas", "reveillon")
# diferença abaixo disso (em reais) conta como "igual"
TOLERANCIA_REAIS = 1.0
MIN_NOITES_CALIBRACAO = 3
HORA_FOTO = time(7, 53)
TOLERANCIA_LIDO_EM = timedelta(minutes=15)
# base do Beds24 de uma foto: a última leitura do mesmo dia até a hora da foto; sem ela, a primeira até 30 minutos depois
JANELA_BASE = timedelta(minutes=30)
# campos do preço da noite que mudam com o plano gravado (cada troca guarda os seus)
CAMPOS_PRECO_NOITE = ("estado", "gravado", "min", "max", "gravado_fonte")
ROTULOS_CAL = {"medida": "calibrado", "indicativa": "indicativo"}
ROTULO_OTIMISTA = "mesmo volume de vendas (limite otimista)"
NOTA_ESTIMATIVA = ("ESTIMATIVA: solto bruto = max(piso da noite, min(uncustomized_price, teto)); solto calibrado = "
                   "max(piso, min(uncustomized_price × f, teto)), com f do quarto = mediana(preço real ÷ fórmula) nas "
                   f"noites que já flutuam sem ajuste nosso (pelo menos {MIN_NOITES_CALIBRACAO} noites; com 1 ou 2, o "
                   "fator é só INDICATIVO e os números dizem \"indicativo\" (\"ind.\" nas tabelas); sem nenhuma, \"sem "
                   "calibração\"). O uncustomized_price é o preço do algoritmo do PriceLabs antes das personalizações "
                   "(última hora, ocupação, dia órfão etc.). Dinheiro na mesa e venda mais barata supõem o "
                   f"{ROTULO_OTIMISTA}: com o preço solto o volume pode mudar. A ordem da Afrodite (acima das outras "
                   "suítes) e a coerência entre suítes também não entram.")
DOC = ("Sombra da flutuação (scripts/sombra_flutuacao.py; dono 09/11/2026, semana em paralelo de 10/11 a 16/11): uma foto "
       "por dia (chave = data da foto) com, por quarto e noite, o preço gravado (com as trocas dos planos e a hora de "
       "cada uma) e o preço solto ESTIMADO (max(piso, min(uncustomized_price, teto))); em 'beds24', as vendidas vistas "
       "no inventário do Beds24 (a leitura da coleta, base da foto, e as das rotinas leves). Nada aqui foi gravado no "
       "PriceLabs nem no Beds24. Sem nomes de hóspedes.")


class ErroSombra(Exception):
    """Erro de uso ou de arquivo: a saída é 2 e nada é gravado."""


# ---------- leitura dos valores do PriceLabs (vêm como texto; None, "" e -1 são "sem valor")
def numero(v) -> float | None:
    if v is None or isinstance(v, bool):
        return None
    s = str(v).strip()
    if not s:
        return None
    try:
        x = float(s)
    except ValueError:
        return None
    return x if math.isfinite(x) else None


def preco(v) -> float | None:
    """Preço positivo, ou None (o PriceLabs usa -1 para "sem valor", ex.: user_price)."""
    x = numero(v)
    return x if x is not None and x > 0 else None


def _n(x: float | None):
    """Número para o JSON: inteiro quando é inteiro, senão 2 casas."""
    if x is None:
        return None
    x = round(float(x), 2)
    return int(x) if x == int(x) else x


def _virgula(x) -> str:
    return str(x).replace(".", ",")


def _data(texto: str, campo: str) -> date:
    s = str(texto).strip()
    try:
        if len(s) == 10:
            return date.fromisoformat(s)
    except ValueError:
        pass
    raise ErroSombra(f"{campo}: data inválida {texto!r} (use AAAA-MM-DD, ex.: 2026-11-10)")


def fuso(cfg: dict | None = None):
    """Fuso das pousadas (quartos.json "fuso", padrão America/Sao_Paulo); sem a base de fusos, UTC-3 fixo (sem horário de
    verão desde 2019)."""
    try:
        from zoneinfo import ZoneInfo
        return ZoneInfo((cfg or {}).get("fuso") or "America/Sao_Paulo")
    except (ImportError, KeyError, ValueError):
        return timezone(timedelta(hours=-3), "BRT")


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _local(iso: str, tz) -> datetime | None:
    dt = ler_instante(iso)
    return dt.astimezone(tz) if dt else None


# ---------- pisos, tetos e tipo da noite
def tipo_da_noite(cfg: dict, d: date) -> tuple[str, str | None]:
    """("feriado", nome) em noite de feriado, festa ou pacote do dono: lista "feriados" de quartos.json (o mesmo critério
    de pace.py e calendario_12m.py), as festas de faixas_dono.tipo_da_noite (Natal, entre festas, Réveillon) e o pacote
    por data de regra_h_beds24; senão ("fds", None) na sexta e no sábado e ("semana", None) de domingo a quinta."""
    fer = periodo_feriado(cfg, d)
    if fer:
        return "feriado", str(fer.get("nome") or "feriado")
    festa = tipo_faixas(d, cfg)
    if festa in FESTAS:
        return "feriado", TIPOS_FAIXAS.get(festa, festa)
    if data_de_pacote(cfg, d):
        return "feriado", "pacote do dono"
    return ("fds" if d.weekday() in (4, 5) else "semana"), None


def anuncios_do_contexto(cfg: dict, dados) -> dict:
    """{curto: {min, base, max}} de um json de contexto: {quarto: {min, base, max}} (curto ou listing_id) ou a saída da
    coleta (result.contexto.sync, lista com listing_id, min, base e max)."""
    if not isinstance(dados, dict):
        raise ErroSombra("--contexto: esperado um objeto JSON")
    r = dados.get("result", dados) if isinstance(dados.get("result"), dict) else dados
    sync = None
    for c in (r.get("contexto"), r):
        if isinstance(c, dict) and isinstance(c.get("sync"), list):
            sync = c["sync"]
            break
    out = {}
    if sync is not None:
        for s in sync:
            q = cfg["por_id"].get(str((s or {}).get("listing_id")))
            if q:
                out[q["curto"]] = {k: s.get(k) for k in ("min", "base", "max") if s.get(k) is not None}
        return out
    for k, v in dados.items():
        q = cfg["por_curto"].get(k) or cfg["por_id"].get(k)
        if q and isinstance(v, dict):
            out[q["curto"]] = {c: v.get(c) for c in ("min", "base", "max") if v.get(c) is not None}
    return out


def minimo_do_anuncio(q: dict, ctx: dict) -> tuple[float | None, str]:
    """(mínimo, origem): o maior entre o "min" do --contexto (o PriceLabs na coleta) e o do quartos.json, quando há os
    dois; senão o que houver."""
    c, j = preco(ctx.get("min")), preco(q.get("min"))
    if c is not None and j is not None:
        if c >= j:
            return c, f"--contexto; quartos.json {reais(j)}"
        return j, f"quartos.json; --contexto {reais(c)}"
    if c is not None:
        return c, "--contexto"
    if j is not None:
        return j, "quartos.json"
    return None, ""


def limites_do_quarto(cfg: dict, curto: str, contexto: dict | None = None, min_balcony: float | None = None) -> dict:
    """{"semana": piso, "fds": piso, "teto": teto, "minimo_anuncio", "origem": {tipo: "dono"|"anuncio"}, "origem_piso":
    texto, "base": base do anúncio} do quarto, fora de feriado. Suíte: max(piso do dono ÷ fator, para cima; mínimo do
    anúncio). A base (--contexto; senão quartos.json) converte o plano gravado no preço que o PriceLabs cobra."""
    q = cfg["por_curto"][curto]
    ctx = (contexto or {}).get(curto) or {}
    teto = preco(ctx.get("max")) or preco(q.get("max")) or TETO_PADRAO_QUARTO.get(curto, TETO_PADRAO)
    base = _n(preco(ctx.get("base")) or preco(q.get("base")))
    if q.get("banheira"):
        lim, par = cfg["limites_seguranca"], cfg["parametros"]
        pousada = q["pousada"]
        minimo, fonte_min = minimo_do_anuncio(q, ctx)
        anuncio = math.ceil(minimo - 1e-9) if minimo is not None else None
        out = {"teto": _n(teto), "minimo_anuncio": anuncio, "origem": {}}
        partes = []
        for tipo, k_piso, k_fator, nome in (("semana", "piso_vitrine_semana_suites", "fator_vitrine_semana", "dom-qui"),
                                            ("fds", "piso_vitrine_fds_suites", "fator_vitrine_fds", "sex-sáb")):
            v, f = lim[k_piso], par[k_fator][pousada]
            # para cima, sem o resíduo do ponto flutuante (850 ÷ 0,425 não pode virar 2001)
            dono = math.ceil(float(v) / float(f) - 1e-9)
            conta = f"piso do dono {reais(v)} ÷ {_virgula(f)} = {reais(dono)} ({pousada})"
            if anuncio is not None and anuncio > dono:
                out[tipo], out["origem"][tipo] = anuncio, "anuncio"
                partes.append(f"{nome}: mínimo do anúncio {reais(anuncio)} ({fonte_min}), acima do {conta}")
            else:
                out[tipo], out["origem"][tipo] = dono, "dono"
                extra = f"; mínimo do anúncio {reais(anuncio)} ({fonte_min})" if anuncio is not None else ""
                partes.append(f"{nome}: {conta}{extra}")
        out["origem_piso"] = "; ".join(partes)
        out["base"] = base
        return {k: out[k] for k in ("semana", "fds", "teto", "minimo_anuncio", "origem", "origem_piso", "base")}
    if min_balcony is not None:
        piso, origem = float(min_balcony), "mínimo do anúncio (--min-balcony)"
    elif preco(ctx.get("min")) is not None:
        piso, origem = preco(ctx.get("min")), "mínimo do anúncio (--contexto)"
    else:
        piso, origem = float(q["min"]), "mínimo do anúncio (quartos.json)"
    piso = math.ceil(piso - 1e-9)
    return {"semana": piso, "fds": piso, "teto": _n(teto), "minimo_anuncio": piso,
            "origem": {"semana": "anuncio", "fds": "anuncio"}, "origem_piso": origem, "base": base}


def estado_da_regra(r: dict) -> str:
    """travada (min = max), so_piso (dso_flag 1 e max > min: o preço já flutua acima do piso da regra) ou sem_regra."""
    mn, mx = preco(r.get("min_price")), preco(r.get("max_price"))
    dso = str(r.get("dso_flag") or "").strip() in ("1", "1.0", "true", "True")
    faixa = mn is not None and mx is not None and mx - mn >= 0.5
    if mn is not None and mx is not None and not faixa:
        return "travada"
    if dso:
        return "so_piso" if faixa else "travada"
    return "sem_regra"


def tem_ajuste(regra: dict | None) -> bool:
    """A regra por data do PriceLabs (ov_pl_<Q>.json) ou o item do plano tem o nosso ajuste percentual (price/preco
    diferente de zero): o preço que flutua não é só do PriceLabs."""
    if not regra:
        return False
    p = numero(regra.get("price") if "price" in regra else regra.get("preco"))
    return p is not None and abs(p) > 1e-9


def preco_solto(piso: float | None, uncust: float | None, teto: float | None, fator: float | None = 1.0) -> float | None:
    """Estimativa do preço solto: max(piso, min(uncustomized_price × fator, teto)) (fator 1 = bruto); None sem
    uncustomized_price, sem piso ou sem fator."""
    if uncust is None or piso is None or fator is None:
        return None
    u = float(uncust) * float(fator)
    return max(float(piso), min(u, float(teto) if teto else u))


def noite_da_foto(cfg: dict, curto: str, r: dict, d: date, lim: dict, regra_ov: dict | None = None) -> dict:
    tipo, feriado = tipo_da_noite(cfg, d)
    piso = lim.get(tipo) if tipo in TIPOS_COMPARADOS else None
    uncust = preco(r.get("uncustomized_price"))
    vendidas, total = vendidas_total(r, int(cfg["por_curto"][curto].get("unidades") or 1))
    estado = estado_da_regra(r)
    if estado in FLUTUAM and tem_ajuste(regra_ov):
        estado = "ajustada"
    e = {"data": d.isoformat(), "dia": DIAS_SEMANA[d.weekday()], "tipo": tipo, "estado": estado,
         "gravado": _n(preco(r.get("price"))), "gravado_fonte": "rec", "solto": _n(preco_solto(piso, uncust, lim["teto"])),
         "piso": piso, "teto": lim["teto"], "uncust": _n(uncust), "min": _n(preco(r.get("min_price"))),
         "max": _n(preco(r.get("max_price"))), "vendidas": vendidas, "total": total}
    if feriado:
        e["feriado"] = feriado
    elif str(r.get("holiday_flag") or "").strip() == "1":
        e["feriado_pl"] = 1  # holiday_flag do PriceLabs fora da lista do dono: anotado, a noite segue como semana/fds
    return e


# ---------- preço gravado pelo plano da rotina (depois da coleta)
def _arquivo_e_hora(arg) -> tuple[Path, datetime | None]:
    """"plano.json" ou "plano.json@AAAA-MM-DDTHH:MM:SSZ" (hora da gravação dada à mão)."""
    s = str(arg)
    if "@" in s:
        cam, _, hora = s.rpartition("@")
        dt = ler_instante(hora)
        if cam and dt is not None:
            return Path(cam), dt
    return Path(s), None


def ler_gravados(caminhos) -> list[dict]:
    """Itens dos planos gravados ({"itens": [...]} do validar_plano ou a lista), na ordem dos arquivos, com o arquivo
    ("_arquivo"), a hora da gravação ("_gravado_em", ISO UTC: a dada em ARQ@hora ou a do arquivo, mtime) e a ordem do
    argumento ("_ordem")."""
    itens = []
    for ordem, c in enumerate(caminhos or []):
        arq, dt = _arquivo_e_hora(c)
        try:
            d = json.loads(arq.read_text(encoding="utf-8"))
            if dt is None:
                dt = datetime.fromtimestamp(arq.stat().st_mtime, timezone.utc)
        except (OSError, UnicodeDecodeError, ValueError) as e:
            raise ErroSombra(f"--gravados {c}: ilegível ({e})") from e
        lst = d.get("itens") if isinstance(d, dict) else d
        if not isinstance(lst, list):
            raise ErroSombra(f"--gravados {c}: esperado {{\"itens\": [...]}} (formato do validar_plano) ou uma lista")
        itens += [dict(it, _arquivo=str(arq), _gravado_em=_iso(dt), _ordem=ordem) for it in lst if isinstance(it, dict)]
    return itens


def indice_gravados(cfg: dict, itens: list[dict]) -> dict[tuple[str, str], dict]:
    """{(quarto, data): regra efetiva do plano}: os campos de valor (min_price, max_price, preco) dos itens "data" se
    somam na ordem, como a API do PriceLabs mescla a regra por data; "apagar" zera. Itens sem valor (só estadia ou
    chegada/saída) não mudam o preço."""
    out: dict[tuple[str, str], dict] = {}
    for it in itens:
        tipo = it.get("tipo")
        q = cfg["por_curto"].get(str(it.get("quarto"))) or cfg["por_id"].get(str(it.get("quarto")))
        ds = str(it.get("data") or "")[:10]
        if tipo not in ("data", "apagar") or not q or len(ds) != 10:
            continue
        k = (q["curto"], ds)
        if tipo == "apagar":
            out[k] = {"apagar": True}
            continue
        valores = {c: it[c] for c in ("min_price", "max_price", "preco") if it.get(c) is not None}
        if not valores:
            continue
        ef = out.get(k) or {}
        ef.pop("apagar", None)
        ef.update(valores)
        out[k] = ef
    return out


def eventos_gravados(cfg: dict, itens: list[dict], padrao_em: str) -> list[dict]:
    """Os planos como trocas de preço, na ordem em que foram gravados: [{"em", "arquivo", "regras": {(quarto, data):
    regra}}], pela hora da gravação (empate: a ordem dos argumentos). Item sem hora: `padrao_em`."""
    grupos: dict[tuple, list[dict]] = {}
    for it in itens:
        k = (str(it.get("_gravado_em") or padrao_em), int(it.get("_ordem") or 0), str(it.get("_arquivo") or ""))
        grupos.setdefault(k, []).append(it)
    return [{"em": em, "arquivo": arq, "regras": indice_gravados(cfg, its)} for (em, _, arq), its in sorted(grupos.items())]


def em_reais_do_plano(base: float | None, mn: float | None, mx: float | None) -> tuple[float | None, float | None]:
    """Piso e teto do plano no valor que o PriceLabs cobra: a gravação manda percent_base sobre a base do anúncio, como
    validar_plano.montar_payload (piso para cima, teto para baixo, teto nunca abaixo do piso), e o PriceLabs arredonda ao
    real. Sem base, o valor do plano."""
    if not base:
        return mn, mx
    q = {"base": base}
    pmin = piso_pct(q, mn) if mn is not None else None
    pmax = teto_pct(q, mx) if mx is not None else None
    if pmin is not None and pmax is not None and pmax < pmin:
        pmax = pmin
    return (float(em_reais(q, pmin)) if pmin is not None else None, float(em_reais(q, pmax)) if pmax is not None else None)


def aplicar_gravado(e: dict, regra: dict, regra_ov: dict | None, base: float | None = None) -> bool:
    """Põe na noite o preço que o plano gravou depois da coleta (convertido como a gravação faz, com a base do anúncio).
    Devolve True se mudou a noite. "gravado_rec" guarda o preço do rec (o de antes do primeiro plano)."""
    antes = e.get("gravado")
    if regra.get("apagar"):
        e.update(estado="sem_regra", gravado=None, min=None, max=None, gravado_fonte="plano")
        e.setdefault("gravado_rec", antes)
        return True
    mn, mx = em_reais_do_plano(base, preco(regra.get("min_price")), preco(regra.get("max_price")))
    ajuste = tem_ajuste(regra) if regra.get("preco") is not None else (tem_ajuste(regra_ov) or e["estado"] == "ajustada")
    if mn is not None and mx is not None and abs(mx - mn) < 0.5:
        e.update(estado="travada", gravado=_n(mn), min=_n(mn), max=_n(mn), gravado_fonte="plano")
        e.setdefault("gravado_rec", antes)
        return True
    if mn is not None:
        teto = mx if mx is not None and mx > mn else (e.get("max") if (e.get("max") or 0) > mn else None)
        e.update(estado="ajustada" if ajuste else "so_piso", gravado=None, min=_n(mn), max=_n(teto),
                 gravado_fonte="plano")
        e.setdefault("gravado_rec", antes)
        return True
    if regra.get("preco") is not None and e["estado"] in FLUTUAM + ("ajustada",):
        if not ajuste and e["estado"] != "ajustada":
            return False  # percentual zero numa noite que já flutuava sem ajuste: nada muda
        # só o percentual numa noite que flutua: o preço muda e não se sabe para quanto
        e.update(estado="ajustada" if ajuste else ("so_piso" if e["estado"] == "ajustada" else e["estado"]),
                 gravado=None, gravado_fonte="plano")
        e.setdefault("gravado_rec", antes)
        return True
    return False


# ---------- arquivo
def ler_rec(caminho: Path) -> dict:
    """{data: noite} do pricing_array de um rec_<Q>.json (mesmo formato de calendario_12m.ler_recalculo)."""
    try:
        d = ler_json(caminho)
    except (OSError, UnicodeDecodeError, ValueError) as e:
        raise ErroSombra(f"{caminho}: ilegível ({e})") from e
    arr = ((d.get("data") if isinstance(d.get("data"), dict) else None) or d).get("pricing_array") or []
    return {str(r["date"])[:10]: r for r in arr if isinstance(r, dict) and r.get("date")}


def ler_ov(caminho: Path) -> dict:
    """{data: regra} das regras por data do PriceLabs (ov_pl_<Q>.json: {"data": {"overrides": [...]}}, {"overrides":
    [...]} ou a lista)."""
    try:
        d = json.loads(Path(caminho).read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, ValueError) as e:
        raise ErroSombra(f"{caminho}: ilegível ({e})") from e
    if isinstance(d, dict):
        d = ((d.get("data") if isinstance(d.get("data"), dict) else None) or d).get("overrides")
    if not isinstance(d, list):
        raise ErroSombra(f"{caminho}: esperada a lista de regras por data (overrides)")
    return {str(o["date"])[:10]: o for o in d if isinstance(o, dict) and o.get("date")}


def _problema_formato(d) -> str:
    if not isinstance(d, dict) or not isinstance(d.get("fotos"), dict):
        return "falta o objeto 'fotos'"
    for k, f in d["fotos"].items():
        if not isinstance(f, dict) or not isinstance(f.get("quartos"), dict):
            return f"foto {k}: sem 'quartos'"
        for q, noites in f["quartos"].items():
            if not isinstance(noites, list) or any(not isinstance(e, dict) or "data" not in e for e in noites):
                return f"foto {k}, {q}: noites fora do formato"
    b = d.get("beds24")
    if b is not None:
        if not isinstance(b, dict):
            return "'beds24' fora do formato"
        for n, por_q in b.items():
            if not isinstance(por_q, dict):
                return f"beds24 {n}: fora do formato"
            for q, lst in por_q.items():
                if not isinstance(lst, list) or any(not isinstance(x, dict) or not isinstance(x.get("lido_em"), str)
                                                    or not isinstance(x.get("vendidas"), int) for x in lst):
                    return f"beds24 {n}, {q}: leituras fora do formato"
    return ""


def carregar(caminho: Path | str | None = None) -> dict:
    """{'_doc', 'fotos': {data: foto}, 'beds24'?}. Arquivo inexistente = nenhuma foto. Ilegível ou fora do formato:
    ErroSombra, sem apagar nem regravar nada."""
    p = Path(caminho or ARQ_SOMBRA)
    if not p.exists():
        return {"_doc": DOC, "fotos": {}}
    aviso = "Nada foi apagado nem gravado: conserte o arquivo à mão (o git tem a última versão boa)."
    try:
        d = json.loads(p.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, ValueError) as e:
        raise ErroSombra(f"{p}: arquivo ilegível ou corrompido ({e}). {aviso}") from e
    problema = _problema_formato(d)
    if problema:
        raise ErroSombra(f"{p}: arquivo corrompido ({problema}). {aviso}")
    return d


def serializar(x, nivel: int = 0) -> str:
    """JSON legível e curto: objetos indentados; cada noite (lista de objetos) e cada objeto só de valores simples numa
    linha só."""
    pad = " " * nivel
    if isinstance(x, dict) and any(isinstance(v, (dict, list)) for v in x.values()):
        itens = [f"{pad} {json.dumps(k, ensure_ascii=False)}: {serializar(v, nivel + 1)}" for k, v in x.items()]
        return "{\n" + ",\n".join(itens) + "\n" + pad + "}"
    if isinstance(x, list) and x and all(isinstance(e, dict) for e in x):
        itens = [f"{pad} {json.dumps(e, ensure_ascii=False)}" for e in x]
        return "[\n" + ",\n".join(itens) + "\n" + pad + "]"
    return json.dumps(x, ensure_ascii=False)


@contextmanager
def _trava(pasta: Path):
    """Uma gravação de cada vez (flock na pasta, como cota_recalculo.py). Sem fcntl (Windows), segue sem trava."""
    try:
        import fcntl
    except ImportError:
        yield
        return
    fd = os.open(pasta, os.O_RDONLY)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)


def gravar(caminho: Path | str, d: dict) -> None:
    """Gravação atômica: temporário na mesma pasta, fsync e os.replace por cima (nunca deixa meio arquivo)."""
    p = Path(caminho)
    fd, tmp = tempfile.mkstemp(prefix=f".{p.name}.", suffix=".tmp", dir=p.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(serializar(d))
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, (p.stat().st_mode & 0o777) if p.exists() else 0o644)
        os.replace(tmp, p)
    except BaseException:
        try:
            os.unlink(tmp)
        except FileNotFoundError:
            pass
        raise


# ---------- registrar
def _observado(arq: Path, registrado: datetime, hoje: date, tz) -> tuple[datetime, bool]:
    """Quando o PriceLabs foi lido: a hora do rec_<Q>.json (a coleta grava o arquivo na hora da leitura), nunca depois do
    registro. Se essa hora não cai no dia da foto (arquivo copiado depois), vale 07:53 do dia, estimado."""
    try:
        m = datetime.fromtimestamp(arq.stat().st_mtime, timezone.utc)
    except OSError:
        m = registrado
    obs = min(m, registrado)
    if obs.astimezone(tz).date() != hoje:
        return datetime.combine(hoje, HORA_FOTO, tzinfo=tz).astimezone(timezone.utc), True
    return obs, False


def _situacao_planos(eventos: list[dict], hoje: date, registrado: str, tz) -> list[dict]:
    """Por plano: {"arquivo", "gravado_em", "noites": 0, "ja_no_rec": 0} e "ignorado" (motivo) quando o plano não vale
    para a foto: gravado em outro dia ou depois do registro."""
    out = []
    for ev in eventos:
        x = {"arquivo": ev["arquivo"], "gravado_em": ev["em"], "noites": 0, "ja_no_rec": 0}
        dia = _local(ev["em"], tz)
        if dia is None or dia.date() != hoje:
            x["ignorado"] = (f"gravado em {dia.strftime('%d/%m %H:%M')}, fora do dia da foto (hora do arquivo; dê a hora "
                             "certa com ARQ@AAAA-MM-DDTHH:MM:SSZ)" if dia else "hora da gravação inválida")
        elif ev["em"] > registrado:
            x["ignorado"] = "gravado depois do registro"
        out.append(x)
    return out


def montar_foto(cfg: dict, rec_dir: Path | str, hoje: date, dias: int = DIAS_PADRAO, contexto: dict | None = None,
                min_balcony: float | None = None, agora: datetime | None = None, gravados: list[dict] | None = None,
                forcar: bool = False) -> dict:
    """A foto do dia, sem gravar: por quarto, as noites de hoje a hoje + dias - 1 que estão no rec_<Q>.json. O rec tem de
    começar em `hoje` (com `forcar`, em `hoje` ou antes); senão o quarto vai para "faltando" ("rec de outro dia"). Os
    planos (`gravados`) entram como trocas de preço, cada um a partir da hora em que foi gravado: o de antes da leitura
    do PriceLabs no quarto (o rec já reflete) fica de fora."""
    if dias < 1:
        raise ErroSombra(f"--dias {dias}: use 1 ou mais")
    pasta = Path(rec_dir)
    if not pasta.is_dir():
        raise ErroSombra(f"--rec-dir {pasta}: pasta não encontrada")
    tz = fuso(cfg)
    registrado = (agora or datetime.now(timezone.utc)).astimezone(timezone.utc)
    reg_iso = _iso(registrado)
    eventos = eventos_gravados(cfg, gravados or [], reg_iso)
    planos = _situacao_planos(eventos, hoje, reg_iso, tz)
    quartos, pisos, faltando, sem_noite, meta = {}, {}, {}, {}, {}
    usados = 0
    for q in cfg["quartos"]:
        c = q["curto"]
        arq = pasta / f"rec_{c}.json"
        if not arq.exists():
            faltando[c] = "sem rec_<Q>.json"
            continue
        rec = ler_rec(arq)
        if not rec:
            faltando[c] = "rec vazio"
            continue
        inicio = min(rec)
        if inicio != hoje.isoformat() and not (forcar and inicio < hoje.isoformat()):
            faltando[c] = f"rec de outro dia (começa em {inicio})"
            continue
        arq_ov = pasta / f"ov_pl_{c}.json"
        ov = ler_ov(arq_ov) if arq_ov.exists() else None
        lim = limites_do_quarto(cfg, c, contexto, min_balcony)
        obs, estimado = _observado(arq, registrado, hoje, tz)
        obs_iso = _iso(obs)
        noites, do_plano = [], 0
        for i in range(dias):
            d = hoje + timedelta(days=i)
            ds = d.isoformat()
            r = rec.get(ds)
            if r is None:
                sem_noite[c] = sem_noite.get(c, 0) + 1
                continue
            e = noite_da_foto(cfg, c, r, d, lim, (ov or {}).get(ds))
            do_rec = {k: e.get(k) for k in CAMPOS_PRECO_NOITE}
            trocas = []
            for ev, sit in zip(eventos, planos):
                regra = ev["regras"].get((c, ds))
                if regra is None or "ignorado" in sit:
                    continue
                if ev["em"] <= obs_iso:
                    sit["ja_no_rec"] += 1  # gravado antes da leitura do PriceLabs: o rec já tem esse preço
                    continue
                if aplicar_gravado(e, regra, (ov or {}).get(ds), lim.get("base")):
                    sit["noites"] += 1
                    trocas.append({"em": ev["em"], **{k: e.get(k) for k in CAMPOS_PRECO_NOITE}})
            if trocas:
                # o preço do rec vale da leitura do PriceLabs até o primeiro plano; cada plano, da hora dele em diante
                # (o arquivo de cada plano, pela hora, está em foto["gravados"]["planos"])
                e["gravado_em"] = trocas[-1]["em"]
                e["trocas"] = [{"em": obs_iso, **do_rec}] + trocas
                do_plano += 1
            noites.append(e)
        m = {"observado_em": obs_iso, "ov_pl": ov is not None, "fonte": str(pasta)}
        if estimado:
            m["observado_em_estimado"] = True
        if do_plano:
            m["gravados_do_plano"] = do_plano
        usados += do_plano
        pisos[c], quartos[c], meta[c] = lim, noites, m
    if not quartos:
        motivos = ", ".join(f"{c} ({m})" for c, m in faltando.items())
        raise ErroSombra(f"nenhum rec_<Q>.json de {hoje} em {pasta}: {motivos} (esperado rec_Q7.json, rec_VK7.json ...)")
    foto = {"hoje": hoje.isoformat(), "registrado_em": reg_iso, "dias": dias, "fonte": str(pasta),
            "pisos": pisos, "por_quarto": meta, "faltando": faltando}
    if sem_noite:
        foto["noites_sem_dado"] = sem_noite
    if gravados:
        foto["gravados"] = {"arquivos": sorted({str(it.get("_arquivo")) for it in gravados if it.get("_arquivo")}),
                            "noites": usados, "planos": planos}
    foto["quartos"] = quartos
    return foto


def juntar_fotos(antiga: dict, nova: dict, ordem: list[str]) -> dict:
    """A foto nova com os quartos da antiga que não vieram agora (mesmo dia, outra pasta ou rec que faltou)."""
    mantidos = [c for c in (antiga.get("quartos") or {}) if c not in nova["quartos"]]
    if not mantidos:
        return nova
    f = copy.deepcopy(nova)
    ordem = list(ordem) + [c for c in list(f["quartos"]) + mantidos if c not in ordem]
    for c in mantidos:
        f["quartos"][c] = antiga["quartos"][c]
        if c in (antiga.get("pisos") or {}):
            f["pisos"][c] = antiga["pisos"][c]
        f["por_quarto"][c] = (antiga.get("por_quarto") or {}).get(c) or {"observado_em": antiga.get("registrado_em")}
        if c in (antiga.get("noites_sem_dado") or {}):
            f.setdefault("noites_sem_dado", {})[c] = antiga["noites_sem_dado"][c]
        if isinstance(f.get("faltando"), dict):
            f["faltando"].pop(c, None)
    for k in ("quartos", "pisos", "por_quarto", "noites_sem_dado"):
        if isinstance(f.get(k), dict):
            f[k] = {c: f[k][c] for c in ordem if c in f[k]}
    f["quartos_mantidos"] = [c for c in ordem if c in mantidos]
    # "quartos" por último, como na foto nova
    f["quartos"] = f.pop("quartos")
    return f


def registrar(rec_dir: Path | str, hoje: date, dias: int = DIAS_PADRAO, contexto: dict | None = None,
              min_balcony: float | None = None, caminho: Path | str | None = None, cfg: dict | None = None,
              agora: datetime | None = None, gravados: list[dict] | None = None, substituir: bool = False,
              forcar: bool = False, inv: dict | Path | str | None = None) -> tuple[dict, bool]:
    """Grava a foto do dia e a base dela (a leitura do Beds24 da coleta: `inv`, um caminho ou o inventário já lido;
    padrão, o inv.json da pasta do rec, quando existe) na seção "beds24". Já havendo foto do mesmo --hoje, junta por
    quarto (salvo `substituir`). Devolve (foto como ficou, já havia foto do dia)."""
    cfg = cfg or carregar_quartos()
    foto = montar_foto(cfg, rec_dir, hoje, dias, contexto, min_balcony, agora, gravados, forcar)
    dados_inv, origem, explicito = _ler_inv(rec_dir, inv)
    leitura, info = leitura_base(cfg, dados_inv, hoje, dias, origem, explicito)
    foto = {**{k: v for k, v in foto.items() if k != "quartos"}, "base_beds24": info, "quartos": foto["quartos"]}
    p = Path(caminho or ARQ_SOMBRA)
    p.parent.mkdir(parents=True, exist_ok=True)
    with _trava(p.parent):
        d = carregar(p)
        existia = foto["hoje"] in d["fotos"]
        if existia and not substituir:
            antiga = d["fotos"][foto["hoje"]]
            if not leitura and (antiga.get("base_beds24") or {}).get("lido_em"):
                foto["base_beds24"] = antiga["base_beds24"]  # a base guardada antes continua valendo
            foto = juntar_fotos(antiga, foto, [q["curto"] for q in cfg["quartos"]])
        d["_doc"] = DOC
        d["fotos"][foto["hoje"]] = foto
        d["fotos"] = dict(sorted(d["fotos"].items()))
        if leitura:
            _guardar_leitura(d, leitura[0], leitura[1], cfg)
        gravar(p, d)
    return foto, existia


# ---------- fechar (leitura do Beds24 depois da foto)
def montar_leitura(cfg: dict, inv: dict, hoje: date, dias: int = DIAS_PADRAO) -> dict[str, dict[str, int]]:
    """{noite: {quarto: vendidas}} do inventário do Beds24: unidades - max(numAvail, 0), de hoje a hoje + dias - 1.
    Bloqueio sem reserva também tira unidade do numAvail: conta como vendida."""
    if not isinstance(inv, dict):
        raise ErroSombra("--inv: esperado {quarto: {data: {numAvail, ...}}}")
    if dias < 1:
        raise ErroSombra(f"--dias {dias}: use 1 ou mais")
    out: dict[str, dict[str, int]] = {}
    for k, noites in inv.items():
        q = cfg["por_curto"].get(str(k)) or cfg["por_id"].get(str(k))
        if str(k).startswith("_") or not q or not isinstance(noites, dict):
            continue
        u = int(q.get("unidades") or 1)
        for i in range(dias):
            ds = (hoje + timedelta(days=i)).isoformat()
            x = noites.get(ds)
            na = numero(x.get("numAvail")) if isinstance(x, dict) else None
            if na is None:
                continue
            out.setdefault(ds, {})[q["curto"]] = max(0, min(u, u - max(int(na), 0)))
    if not out:
        raise ErroSombra(f"--inv: nenhuma noite de {hoje} em diante com numAvail dos quartos do quartos.json")
    return dict(sorted(out.items()))


def _ler_inv(rec_dir: Path | str, inv) -> tuple[dict | None, str, bool]:
    """(inventário, origem, explícito): o `inv` dado (inventário ou caminho) ou o inv.json da pasta do rec."""
    if isinstance(inv, dict):
        return inv, "inventário dado", True
    arq = Path(inv) if inv is not None else Path(rec_dir) / "inv.json"
    if inv is None and not arq.exists():
        return None, str(arq), False
    try:
        return ler_json(arq), str(arq), inv is not None
    except (OSError, UnicodeDecodeError, ValueError) as e:
        if inv is not None:
            raise ErroSombra(f"--inv {arq}: ilegível ({e})") from e
        return {"_ilegivel": str(e)}, str(arq), False


def leitura_base(cfg: dict, inv: dict | None, hoje: date, dias: int = DIAS_PADRAO, origem: str = "inv.json",
                 explicito: bool = False) -> tuple[tuple[dict, str] | None, dict]:
    """((leitura, lido_em) ou None, informação) da leitura do Beds24 da coleta, a base da foto: tem de ter "_lido_em" e
    ser do dia da foto em Brasília. Fora disso, sem base (com `explicito`, --inv dado à mão: erro)."""
    if inv is None:
        return None, {"sem_base": "sem inv.json na pasta do rec (dê a leitura do Beds24 da coleta com --inv)"}
    tz = fuso(cfg)
    lido = ler_instante(inv.get("_lido_em")) if isinstance(inv, dict) else None
    motivo = ""
    if not isinstance(inv, dict):
        motivo = f"{origem}: esperado {{quarto: {{data: {{numAvail, ...}}}}}}"
    elif inv.get("_ilegivel"):
        motivo = f"{origem}: ilegível ({inv['_ilegivel']})"
    elif lido is None:
        motivo = f"{origem}: sem \"_lido_em\" (hora da leitura do Beds24)"
    elif lido.astimezone(tz).date() != hoje:
        motivo = f"{origem}: leitura do Beds24 de {_hm(_iso(lido), tz)}, não do dia da foto ({_dm(hoje.isoformat())})"
    else:
        try:
            leitura = montar_leitura(cfg, inv, hoje, dias)
        except ErroSombra as e:
            motivo = f"{origem}: {str(e).removeprefix('--inv: ')}"
        else:
            iso = _iso(lido)
            return (leitura, iso), {"lido_em": iso, "fonte": origem, "noites": len(leitura)}
    if explicito:
        raise ErroSombra(f"--inv {motivo}")
    return None, {"sem_base": motivo}


def _guardar_leitura(d: dict, leitura: dict[str, dict[str, int]], iso: str, cfg: dict) -> bool:
    """Junta a leitura do Beds24 à seção "beds24" de `d` (idempotente pelo lido_em). Devolve True se ela já estava."""
    b = d.get("beds24") or {}
    repetida = False
    for noite, por_q in leitura.items():
        for c, v in por_q.items():
            antes = b.setdefault(noite, {}).setdefault(c, [])
            lst = [x for x in antes if x.get("lido_em") != iso]
            repetida = repetida or len(lst) != len(antes)
            lst.append({"lido_em": iso, "vendidas": v})
            b[noite][c] = sorted(lst, key=lambda x: x["lido_em"])
    ordem = [q["curto"] for q in cfg["quartos"]]
    d["beds24"] = {n: {c: b[n][c] for c in ordem + sorted(set(b[n]) - set(ordem)) if c in b[n]} for n in sorted(b)}
    return repetida


def fechar(inv: dict, lido_em: datetime, hoje: date | None = None, dias: int = DIAS_PADRAO,
           caminho: Path | str | None = None, cfg: dict | None = None) -> dict:
    """Guarda a leitura do Beds24 na seção "beds24" do arquivo. Idempotente pelo lido_em."""
    cfg = cfg or carregar_quartos()
    hoje = hoje or lido_em.astimezone(fuso(cfg)).date()
    leitura = montar_leitura(cfg, inv, hoje, dias)
    iso = _iso(lido_em)
    p = Path(caminho or ARQ_SOMBRA)
    p.parent.mkdir(parents=True, exist_ok=True)
    with _trava(p.parent):
        d = carregar(p)
        repetida = _guardar_leitura(d, leitura, iso, cfg)
        d["_doc"] = DOC
        gravar(p, d)
    ordem = [q["curto"] for q in cfg["quartos"]]
    return {"lido_em": iso, "hoje": hoje.isoformat(), "noites": len(leitura),
            "de": min(leitura), "ate": max(leitura), "quartos": [c for c in ordem if any(c in x for x in leitura.values())],
            "repetida": repetida}


# ---------- resumo
def _media(xs: list[float]) -> float | None:
    return sum(xs) / len(xs) if xs else None


def _dif_pct(solto: float | None, gravado: float | None) -> float | None:
    if solto is None or not gravado:
        return None
    return (solto / gravado - 1) * 100


def esgotada(e: dict) -> bool:
    """Noite sem unidade livre na foto: o preço dela não vende mais nada e fica fora da comparação."""
    total = int(e.get("total") or 0)
    return total > 0 and int(e.get("vendidas") or 0) >= total


def _comparavel(e: dict) -> bool:
    """Noite travada à venda, de semana ou fds (fora de feriado, festa, pacote e noite esgotada), com gravado e solto."""
    return (e.get("tipo") in TIPOS_COMPARADOS and e.get("estado") == "travada" and e.get("gravado") is not None
            and e.get("solto") is not None and not esgotada(e))


def _comparacao(ns: list[dict], campo: str) -> dict:
    abaixo = [_dif_pct(e[campo], e["gravado"]) for e in ns if e[campo] < e["gravado"] - TOLERANCIA_REAIS]
    acima = [_dif_pct(e[campo], e["gravado"]) for e in ns if e[campo] > e["gravado"] + TOLERANCIA_REAIS]
    g, s = _media([e["gravado"] for e in ns]), _media([e[campo] for e in ns])
    return {"solto_medio": _n(s), "dif_pct": _n(_dif_pct(s, g)),
            "abaixo": {"noites": len(abaixo), "media_pct": _n(_media(abaixo))},
            "acima": {"noites": len(acima), "media_pct": _n(_media(acima))},
            "iguais": len(ns) - len(abaixo) - len(acima)}


def estatistica_precos(noites: list[dict]) -> dict:
    """Por tipo (semana, fds): noites, médias do gravado e do solto bruto, diferença das médias e quantas noites o solto
    fica abaixo, acima ou igual ao gravado (com a diferença média em %); em "calibrado", o mesmo com o solto calibrado
    (None sem calibração)."""
    out = {}
    for tipo in TIPOS_COMPARADOS:
        ns = [e for e in noites if e["tipo"] == tipo]
        cal = _comparacao(ns, "solto_cal") if ns and all(e.get("solto_cal") is not None for e in ns) else None
        out[tipo] = {"noites": len(ns), "gravado_medio": _n(_media([e["gravado"] for e in ns])),
                     **_comparacao(ns, "solto"), "calibrado": cal}
    return out


def _calibracao(noites: list[dict], ajustes_desconhecidos: bool = False) -> dict:
    """Nas noites que já flutuam sem ajuste nosso (so_piso, sem_regra) à venda, fora de feriado: preço real × a fórmula
    do solto com o piso e o teto da própria noite. f = mediana(real ÷ fórmula), com pelo menos MIN_NOITES_CALIBRACAO
    noites ("fator", tipo "medida"); com 1 ou 2, a mesma mediana como "fator_indicativo" (tipo "indicativa"); sem
    nenhuma, sem calibração. "fator_usado" é o que os números calibrados usam. A noite em que o preço real e a fórmula
    ficam os dois no piso da regra não mede nada (erro zero de graça) e fica de fora (presas_no_piso); a esgotada também
    (o preço dela não vende); a "ajustada" (nosso percentual na regra) é contada à parte."""
    razoes, erros, presas, ajustadas = [], [], 0, 0
    for e in noites:
        if e.get("tipo") not in TIPOS_COMPARADOS or esgotada(e):
            continue
        if e.get("estado") == "ajustada":
            ajustadas += 1
            continue
        if e.get("estado") not in FLUTUAM or None in (e.get("gravado"), e.get("uncust"), e.get("min"), e.get("max")):
            continue
        formula = max(e["min"], min(e["uncust"], e["max"]))
        if abs(formula - e["min"]) < TOLERANCIA_REAIS and abs(e["gravado"] - e["min"]) < TOLERANCIA_REAIS:
            presas += 1
            continue
        razoes.append(e["gravado"] / formula)
        erros.append(_dif_pct(e["gravado"], formula))
    mediana = round(statistics.median(razoes), 4) if razoes else None
    fator = mediana if len(razoes) >= MIN_NOITES_CALIBRACAO else None
    indicativo = mediana if fator is None else None
    tipo = "medida" if fator is not None else ("indicativa" if indicativo is not None else None)
    return {"noites": len(erros), "fator": fator, "fator_indicativo": indicativo, "tipo": tipo,
            "fator_usado": fator if fator is not None else indicativo, "erro_medio_pct": _n(_media(erros)),
            "erro_absoluto_medio_pct": _n(_media([abs(x) for x in erros])), "presas_no_piso": presas,
            "ajustadas": ajustadas, "ajustes_desconhecidos": ajustes_desconhecidos}


def _anotar(e: dict, fator: float | None) -> dict:
    x = dict(e)
    x["solto_cal"] = _n(preco_solto(e.get("piso"), e.get("uncust"), e.get("teto"), fator))
    return x


def _estado_em(e: dict, em: str) -> dict:
    """A noite com o preço que valia no instante `em` (ISO UTC): o do rec desde a leitura do PriceLabs e o de cada plano
    gravado desde a hora dele ("trocas"). Sem trocas, a própria noite."""
    trocas = e.get("trocas") or []
    vigente = None
    for t in trocas:
        if str(t.get("em") or "") <= em:
            vigente = t
    if vigente is None or vigente is trocas[-1]:
        return e
    x = dict(e)
    x.update({k: vigente.get(k) for k in CAMPOS_PRECO_NOITE})
    if vigente.get("gravado_fonte") == "plano":
        x["gravado_em"] = vigente["em"]
    else:
        x.pop("gravado_em", None)
    return x


def _obs_em(f: dict, c: str) -> str:
    """Hora (ISO UTC) em que a foto leu o quarto no PriceLabs."""
    m = (f.get("por_quarto") or {}).get(c) or {}
    return m.get("observado_em") or f.get("registrado_em") or f"{f.get('hoje')}T10:53:00Z"


def _observacoes(fotos: list[tuple[str, dict]], c: str, fator: float | None) -> dict[str, list[dict]]:
    """{noite: [observação das fotos, em ordem]}: só as fotos que têm o quarto e a noite (a foto sem o quarto é pulada)."""
    obs: dict[str, list[dict]] = {}
    for k, f in fotos:
        em = _obs_em(f, c)
        for e in (f.get("quartos") or {}).get(c) or []:
            obs.setdefault(e["data"], []).append({"fonte": "foto", "rotulo": k, "em": em, "e": _anotar(e, fator),
                                                  "vendidas": int(e.get("vendidas") or 0)})
    return obs


def _leituras(dados: dict, c: str, obs: dict[str, list[dict]], de_em: str, ate_em: str, tz) -> dict[str, dict]:
    """{noite: {"base": leitura do Beds24 na hora da última foto que viu a noite (ou None), "leituras": [leituras do
    Beds24 do período posteriores a essa foto]}}. Base: a última leitura do mesmo dia (Brasília) até a hora da foto; sem
    ela, a primeira até JANELA_BASE depois da foto (e então ela sai das leituras)."""
    out: dict[str, dict] = {}
    b = dados.get("beds24") or {}
    for noite, lst in obs.items():
        foto = lst[-1]
        todas = [{"fonte": "beds24", "rotulo": x["lido_em"], "em": x["lido_em"], "vendidas": int(x["vendidas"])}
                 for x in sorted((b.get(noite) or {}).get(c) or [], key=lambda x: x["lido_em"])]
        if not todas:
            continue
        try:
            ini = _limite_do_dia(date.fromisoformat(foto["rotulo"]), tz, False)
        except ValueError:
            ini = foto["em"]
        dt = ler_instante(foto["em"])
        ate_base = _iso(dt + JANELA_BASE) if dt else foto["em"]
        antes = [x for x in todas if ini <= x["em"] <= foto["em"]]
        base = antes[-1] if antes else next((x for x in todas if foto["em"] < x["em"] <= ate_base), None)
        ls = [x for x in todas if max(de_em, foto["em"]) < x["em"] <= ate_em and x is not base]
        if base is not None or ls:
            out[noite] = {"base": base, "leituras": ls}
    return out


def _passos(fotos_n: list[dict], ext: dict | None) -> list[dict]:
    """Pares seguidos da cadeia da noite ({"a", "b", "dv", "y", "troca"}): foto → foto, PriceLabs contra PriceLabs;
    depois da última foto, Beds24 contra Beds24 (a primeira leitura contra a base, a leitura do Beds24 na hora da foto;
    se o PriceLabs da foto já via mais vendidas que a base, vale ele: a venda entre a base e a foto não entra duas
    vezes). Sem base, "dv" None: salto entre as duas fontes, fora das vendas. "y" é a noite com o preço que valia quando
    a venda foi vista (hora de b); "troca": houve troca de preço no meio do intervalo."""
    ext = ext or {}
    base = ext.get("base")
    cadeia = fotos_n + list(ext.get("leituras") or [])
    out, foto = [], None
    for a, b in zip(cadeia, cadeia[1:]):
        if a["fonte"] == "foto":
            foto = a
        if a["fonte"] == "foto" and b["fonte"] == "beds24":
            dv = None if base is None else b["vendidas"] - max(base["vendidas"], a["vendidas"])
        else:
            dv = b["vendidas"] - a["vendidas"]
        e = foto["e"]
        troca = any(a["em"] < str(t.get("em") or "") < b["em"] for t in (e.get("trocas") or [])[1:])
        out.append({"a": a, "b": b, "dv": dv, "y": _estado_em(e, b["em"]), "troca": troca})
    return out


def _venda(noite: str, y: dict, dv: int, a: dict, b: dict, troca: bool = False) -> dict:
    v = {"noite": noite, "dia": y.get("dia"), "tipo": y.get("tipo"), "estado": y.get("estado"), "unidades": dv,
         "entre": [a["rotulo"], b["rotulo"]], "fonte": b["fonte"], "gravado": y.get("gravado"),
         "gravado_fonte": y.get("gravado_fonte"), "solto": y.get("solto"), "solto_cal": y.get("solto_cal"),
         "dif_pct": _n(_dif_pct(y.get("solto"), y.get("gravado"))),
         "dif_cal_pct": _n(_dif_pct(y.get("solto_cal"), y.get("gravado")))}
    if y.get("gravado_fonte") == "plano" and y.get("gravado_em"):
        v["gravado_em"] = y["gravado_em"]
    if troca:
        v["troca_no_intervalo"] = True
    if y.get("feriado"):
        v["feriado"] = y["feriado"]
    if _comparavel(y):
        v["na_mesa"] = _n(max(0.0, y["solto"] - y["gravado"]) * dv)
        v["mais_barata"] = _n(max(0.0, y["gravado"] - y["solto"]) * dv)
        if y.get("solto_cal") is not None:
            v["na_mesa_cal"] = _n(max(0.0, y["solto_cal"] - y["gravado"]) * dv)
            v["mais_barata_cal"] = _n(max(0.0, y["gravado"] - y["solto_cal"]) * dv)
    return v


def _vendas(obs: dict[str, list[dict]], ext: dict[str, dict]) -> tuple[list[dict], int, list[dict]]:
    """Vendas novas entre observações seguidas da mesma noite (_passos), com o preço que valia quando cada venda foi
    vista. Devolve (vendas, unidades canceladas, saltos foto → Beds24 sem base, que ficam fora das vendas)."""
    vendas, canceladas, saltos = [], 0, []
    for noite in sorted(obs):
        for p in _passos(obs[noite], ext.get(noite)):
            dv, a, b = p["dv"], p["a"], p["b"]
            if dv is None:
                if b["vendidas"] != a["vendidas"]:
                    saltos.append({"noite": noite, "dia": a["e"].get("dia"), "tipo": a["e"].get("tipo"),
                                   "foto": a["rotulo"], "pricelabs": a["vendidas"], "beds24": b["vendidas"],
                                   "lido_em": b["em"], "base": False})
                continue
            if dv < 0:
                canceladas += -dv
            if dv > 0:
                vendas.append(_venda(noite, p["y"], dv, a, b, p["troca"]))
    return vendas, canceladas, saltos


def _divergencias(obs: dict[str, list[dict]], ext: dict[str, dict], saltos: list[dict]) -> list[dict]:
    """Diferenças entre o PriceLabs e o Beds24 que não são vendas: a base do Beds24 diferente do PriceLabs na hora da
    última foto da noite (ex.: bloqueio ou reserva de R$ 0 que o PriceLabs não vê) e os saltos foto → Beds24 sem base."""
    out = list(saltos)
    for noite in sorted(obs):
        base = (ext.get(noite) or {}).get("base")
        f = obs[noite][-1]
        if base is not None and base["vendidas"] != f["vendidas"]:
            out.append({"noite": noite, "dia": f["e"].get("dia"), "tipo": f["e"].get("tipo"), "foto": f["rotulo"],
                        "pricelabs": f["vendidas"], "beds24": base["vendidas"], "lido_em": base["em"], "base": True})
    return sorted(out, key=lambda x: (x["noite"], not x["base"]))


def _total_divergencias(div: list[dict]) -> dict:
    com = [x for x in div if x["base"]]
    sem = [x for x in div if not x["base"]]
    return {"na_foto": len(com), "beds24_acima": sum(1 for x in com if x["beds24"] > x["pricelabs"]),
            "sem_base": len(sem), "unidades_sem_base": sum(abs(x["beds24"] - x["pricelabs"]) for x in sem)}


def _somar_vendas(vendas: list[dict], calibrado: bool) -> dict:
    cmp_ = [v for v in vendas if "na_mesa" in v]
    mesa = [v for v in cmp_ if v["na_mesa"] > TOLERANCIA_REAIS]
    barata = [v for v in cmp_ if v["mais_barata"] > TOLERANCIA_REAIS]
    out = {"vendas": len(cmp_), "unidades": sum(v["unidades"] for v in cmp_),
           "receita_gravada": _n(sum(v["gravado"] * v["unidades"] for v in cmp_)),
           "receita_solta": _n(sum(v["solto"] * v["unidades"] for v in cmp_)),
           "dinheiro_na_mesa": _n(sum(v["na_mesa"] for v in mesa)), "vendas_com_dinheiro_na_mesa": len(mesa),
           "mais_barata_no_solto": _n(sum(v["mais_barata"] for v in barata)), "vendas_mais_baratas_no_solto": len(barata)}
    if calibrado and all("na_mesa_cal" in v for v in cmp_):
        mesa_c = [v for v in cmp_ if v["na_mesa_cal"] > TOLERANCIA_REAIS]
        barata_c = [v for v in cmp_ if v["mais_barata_cal"] > TOLERANCIA_REAIS]
        out.update(receita_solta_cal=_n(sum(v["solto_cal"] * v["unidades"] for v in cmp_)),
                   dinheiro_na_mesa_cal=_n(sum(v["na_mesa_cal"] for v in mesa_c)),
                   vendas_com_dinheiro_na_mesa_cal=len(mesa_c),
                   mais_barata_no_solto_cal=_n(sum(v["mais_barata_cal"] for v in barata_c)),
                   vendas_mais_baratas_no_solto_cal=len(barata_c))
    else:
        out.update(receita_solta_cal=None, dinheiro_na_mesa_cal=None, vendas_com_dinheiro_na_mesa_cal=None,
                   mais_barata_no_solto_cal=None, vendas_mais_baratas_no_solto_cal=None)
    out["fora_da_comparacao"] = len(vendas) - len(cmp_)
    return out


def _total_vendas(vendas: list[dict], calibrado: bool = False) -> dict:
    return {**_somar_vendas(vendas, calibrado),
            "por_tipo": {t: _somar_vendas([v for v in vendas if v["tipo"] == t], calibrado) for t in TIPOS_COMPARADOS}}


def _encalhadas(obs: dict[str, list[dict]], ext: dict[str, dict], ultima: str) -> list[dict]:
    """Noites que passaram (antes da última foto do período) com unidades livres no estado mais tardio: a leitura do
    Beds24 depois da última foto que viu a noite, ou essa foto (sem leitura do fim do dia; com a base do Beds24 dela,
    quando há, as vendidas são o maior entre a base e o PriceLabs)."""
    out = []
    for noite in sorted(obs):
        if noite >= ultima:
            continue
        fotos_n, x = obs[noite], ext.get(noite) or {}
        f = fotos_n[-1]
        e, ls, base = f["e"], x.get("leituras") or [], x.get("base")
        if ls:
            fim_v, fim_em = ls[-1]["vendidas"], ls[-1]["em"]
        else:
            fim_v, fim_em = (max(base["vendidas"], f["vendidas"]) if base else f["vendidas"]), None
        livres = int(e.get("total") or 0) - fim_v
        if livres <= 0:
            continue
        vendeu = sum(p["dv"] for p in _passos(fotos_n, x) if p["dv"] and p["dv"] > 0)
        out.append({"noite": noite, "dia": e.get("dia"), "tipo": e.get("tipo"), "estado": e.get("estado"),
                    "livres": livres, "total": e.get("total"), "vendas_no_periodo": vendeu, "visto_em_fotos": len(fotos_n),
                    "ultima_foto": f["rotulo"], "leitura_do_fim": fim_em,
                    "gravado": e.get("gravado"), "solto": e.get("solto"), "solto_cal": e.get("solto_cal"),
                    "dif_pct": _n(_dif_pct(e.get("solto"), e.get("gravado"))),
                    "dif_cal_pct": _n(_dif_pct(e.get("solto_cal"), e.get("gravado"))), "comparavel": _comparavel(e)})
    return out


def _somar_encalhadas(enc: list[dict]) -> dict:
    cmp_ = [x for x in enc if x["comparavel"]]
    barato = [x["dif_pct"] for x in cmp_ if x["solto"] < x["gravado"] - TOLERANCIA_REAIS]
    barato_c = [x["dif_cal_pct"] for x in cmp_ if x["solto_cal"] is not None and x["solto_cal"] < x["gravado"] - TOLERANCIA_REAIS]
    tem_cal = bool(cmp_) and all(x["solto_cal"] is not None for x in cmp_)
    return {"noites": len(cmp_), "unidades_livres": sum(x["livres"] for x in cmp_),
            "sem_venda_no_periodo": sum(1 for x in cmp_ if x["vendas_no_periodo"] == 0),
            "sem_leitura_do_fim_do_dia": sum(1 for x in cmp_ if not x["leitura_do_fim"]),
            "solto_mais_barato": len(barato), "solto_mais_barato_media_pct": _n(_media(barato)),
            "solto_mais_barato_cal": len(barato_c) if tem_cal else None,
            "solto_mais_barato_cal_media_pct": _n(_media(barato_c)) if tem_cal else None,
            "fora_da_comparacao": len(enc) - len(cmp_)}


def _total_encalhadas(enc: list[dict]) -> dict:
    return {**_somar_encalhadas(enc),
            "por_tipo": {t: _somar_encalhadas([x for x in enc if x["tipo"] == t]) for t in TIPOS_COMPARADOS}}


def _limite_do_dia(d: date, tz, fim: bool) -> str:
    t = datetime.combine(d + timedelta(days=1) if fim else d, time(0, 0), tzinfo=tz)
    return _iso(t - timedelta(seconds=1) if fim else t)


def resumo(dados: dict, de: date, ate: date, cfg: dict | None = None) -> dict:
    """Compara as fotos de `de` a `ate` (datas das fotos). Os preços usam a última foto de cada noite; as vendas e as
    noites que passaram usam também as leituras do Beds24 do período posteriores à última foto que viu a noite, contra a
    base do Beds24 dessa foto (Beds24 contra Beds24)."""
    if ate < de:
        raise ErroSombra(f"--ate {ate} antes de --de {de}")
    cfg = cfg or carregar_quartos()
    tz = fuso(cfg)
    fotos = [(k, f) for k, f in sorted(dados.get("fotos", {}).items()) if de.isoformat() <= k <= ate.isoformat()]
    if not fotos:
        datas = ", ".join(sorted(dados.get("fotos", {}))) or "nenhuma"
        raise ErroSombra(f"nenhuma foto de {de} a {ate} (fotos no arquivo: {datas})")
    ultima = fotos[-1][0]
    de_em, ate_em = _limite_do_dia(de, tz, False), _limite_do_dia(ate, tz, True)
    nomes = [q["curto"] for q in cfg["quartos"]]
    extras = sorted({q for _, f in fotos for q in f.get("quartos", {})} - set(nomes))
    out = {"de": de.isoformat(), "ate": ate.isoformat(), "fotos": [k for k, _ in fotos], "nota": NOTA_ESTIMATIVA,
           "destaque": DESTAQUE, "pisos": fotos[-1][1].get("pisos", {}), "leituras_beds24": [], "bases_beds24": [],
           "quartos": {}}
    usadas: set[str] = set()
    bases: set[str] = set()
    for c in nomes + extras:
        if not any(c in (f.get("quartos") or {}) for _, f in fotos):
            continue
        sem_ov = any(c in (f.get("quartos") or {}) and not ((f.get("por_quarto") or {}).get(c) or {}).get("ov_pl")
                     for _, f in fotos)
        brutas = _observacoes(fotos, c, None)
        cal = _calibracao([brutas[k][-1]["e"] for k in sorted(brutas)], sem_ov)
        fator = cal["fator_usado"]
        obs = _observacoes(fotos, c, fator)
        noites = [obs[k][-1]["e"] for k in sorted(obs)]
        leituras = _leituras(dados, c, obs, de_em, ate_em, tz)
        usadas |= {x["em"] for v in leituras.values() for x in v["leituras"]}
        bases |= {v["base"]["em"] for v in leituras.values() if v["base"]}
        comparaveis = [e for e in noites if _comparavel(e)]
        estados = {s: sum(1 for e in noites if e.get("tipo") in TIPOS_COMPARADOS and e.get("estado") == s)
                   for s in ESTADOS}
        esgotadas = sum(1 for e in noites if e.get("tipo") in TIPOS_COMPARADOS and esgotada(e))
        vendas, canceladas, saltos = _vendas(obs, leituras)
        div = _divergencias(obs, leituras, saltos)
        enc = _encalhadas(obs, leituras, ultima)
        por_foto = []
        for k, f in fotos:
            ns = [_anotar(e, fator) for e in (f.get("quartos") or {}).get(c, []) if _comparavel(e)]
            if ns:
                est = estatistica_precos(ns)
                por_foto.append({"foto": k, **{t: {"noites": est[t]["noites"], "gravado_medio": est[t]["gravado_medio"],
                                                   "solto_medio": est[t]["solto_medio"], "dif_pct": est[t]["dif_pct"],
                                                   "solto_cal_medio": (est[t]["calibrado"] or {}).get("solto_medio"),
                                                   "dif_cal_pct": (est[t]["calibrado"] or {}).get("dif_pct")}
                                                for t in TIPOS_COMPARADOS}})
        out["quartos"][c] = {
            "noites": len(noites), "estados": estados, "esgotadas": esgotadas,
            "feriado_ou_pacote": sum(1 for e in noites if e.get("tipo") == "feriado"),
            "feriado_pricelabs_fora_da_lista": sorted(e["data"] for e in noites if e.get("feriado_pl")),
            "calibracao": cal, "precos": estatistica_precos(comparaveis), "por_foto": por_foto,
            "vendas": vendas, "vendas_total": _total_vendas(vendas, fator is not None), "canceladas": canceladas,
            "divergencias": div, "divergencias_total": _total_divergencias(div),
            "encalhadas": enc, "encalhadas_total": _total_encalhadas(enc)}
    out["leituras_beds24"] = sorted(usadas)
    out["bases_beds24"] = sorted(bases)
    return out


# ---------- texto
def pct(x) -> str:
    return "—" if x is None else f"{x:+.1f}%".replace(".", ",")


def _dm(texto: str) -> str:
    return f"{texto[8:10]}/{texto[5:7]}"


def _hm(iso: str, tz) -> str:
    """DD/MM HH:MM em Brasília de um instante ISO."""
    dt = _local(iso, tz)
    return dt.strftime("%d/%m %H:%M") if dt else str(iso)


def _rotulo(r: str, tz) -> str:
    return f"a foto de {_dm(r)}" if len(r) == 10 else f"a leitura do Beds24 de {_hm(r, tz)}"


def _rot(cal: dict) -> str | None:
    """"calibrado" (fator medido), "indicativo" (1 ou 2 noites medidas) ou None (sem calibração)."""
    return ROTULOS_CAL.get(cal.get("tipo")) if cal.get("fator_usado") is not None else None


def _cal_bruto(cal, bruto, rot: str | None) -> str:
    """'R$ X calibrado (bruto R$ Y)' ('indicativo' com o fator indicativo) ou, sem calibração, só 'R$ Y' (a linha diz
    uma vez que é o bruto)."""
    if rot and cal is not None:
        return f"{reais(cal)} {rot} (bruto {reais(bruto)})"
    return reais(bruto)


SO_BRUTO = " (sem calibração: valores do solto bruto)"


def _fator_txt(cal: dict) -> str:
    f = cal.get("fator_usado")
    if f is None:
        return "sem calibração"
    t = _virgula(f"{f:.3f}")
    return t if cal.get("tipo") == "medida" else f"{t} (indicativo, {cal['noites']} noite(s))"


def _linha_calibracao(cal: dict) -> str:
    aj = f"; {cal['ajustadas']} noite(s) com ajuste nosso ficam de fora" if cal["ajustadas"] else ""
    pres = f"; {cal['presas_no_piso']} presa(s) no piso ficam de fora" if cal["presas_no_piso"] else ""
    desc = (" Sem o ov_pl_<Q>.json em alguma foto: a calibração pode incluir nossos ajustes percentuais."
            if cal["ajustes_desconhecidos"] else "")
    erro = (f"preço real {pct(cal['erro_medio_pct'])} em média contra a fórmula, erro absoluto médio "
            f"{pct(cal['erro_absoluto_medio_pct'])[1:]}")
    if cal.get("tipo") == "medida":
        return (f"- Calibração: fator {_fator_txt(cal)} (mediana de preço real ÷ fórmula em {cal['noites']} noite(s) à "
                f"venda que já flutuam sem ajuste nosso{aj}{pres}; {erro}). Abaixo de 1: o bruto fica acima do que o "
                f"PriceLabs cobraria.{desc}")
    if cal.get("tipo") == "indicativa":
        return (f"- Calibração INDICATIVA: fator {_virgula(format(cal['fator_usado'], '.3f'))} (mediana de preço real ÷ "
                f"fórmula em só {cal['noites']} noite(s) à venda que já flutuam sem ajuste nosso; o mínimo para "
                f"calibrar é {MIN_NOITES_CALIBRACAO}{aj}{pres}; {erro}). Os números \"indicativos\" usam esse fator: "
                f"indicação, não medida; o bruto vem ao lado.{desc}")
    return (f"- Sem calibração: nenhuma noite à venda que já flutua sem ajuste nosso (o mínimo para calibrar é "
            f"{MIN_NOITES_CALIBRACAO}){aj}{pres}. Os números ficam só no solto bruto.{desc}")


def _sinal(x, tol: float) -> int:
    return 0 if x is None or abs(x) <= tol else (1 if x > 0 else -1)


def _alerta_sinal(p: dict, v: dict, cal: dict, rot: str | None) -> str | None:
    """Alerta quando o bruto e o calibrado (ou o indicativo) levam a conclusões opostas: no preço médio contra o gravado
    ou no saldo das vendas (dinheiro na mesa menos venda mais barata)."""
    if not rot:
        return None
    partes = []
    k = p.get("calibrado") or {}
    if p["noites"] and k and _sinal(p.get("dif_pct"), 0.5) != _sinal(k.get("dif_pct"), 0.5):
        partes.append(f"preço médio {pct(p['dif_pct'])} no bruto e {pct(k['dif_pct'])} no {rot}, contra o gravado")
    if v["vendas"] and v.get("dinheiro_na_mesa_cal") is not None:
        bruto = v["dinheiro_na_mesa"] - v["mais_barata_no_solto"]
        calib = v["dinheiro_na_mesa_cal"] - v["mais_barata_no_solto_cal"]
        if _sinal(bruto, TOLERANCIA_REAIS) != _sinal(calib, TOLERANCIA_REAIS):
            partes.append(f"saldo das vendas (dinheiro na mesa menos venda mais barata) {reais(bruto)} no bruto e "
                          f"{reais(calib)} no {rot}")
    if not partes:
        return None
    if cal.get("tipo") == "indicativa":
        fim = (f"o indicativo vem de só {cal['noites']} noite(s) medida(s) (mínimo {MIN_NOITES_CALIBRACAO} para "
               "calibrar), e é a única medição que há: não decida pelo bruto; falta amostra para decidir por qualquer "
               "um dos dois")
    else:
        fim = "vale o calibrado (medido nas noites que já flutuam)"
    return f"- ATENÇÃO: o bruto e o {rot} apontam para lados opostos ({'; '.join(partes)}): {fim}."


def _lado(x: dict) -> str:
    return (f"{reais(x['solto_medio'])} ({pct(x['dif_pct'])}; abaixo em {x['abaixo']['noites']}, média "
            f"{pct(x['abaixo']['media_pct'])}; acima em {x['acima']['noites']}, média {pct(x['acima']['media_pct'])}; "
            f"igual em {x['iguais']})")


def _linha_tipo(nome: str, p: dict, rot: str | None) -> str:
    if not p["noites"]:
        return f"- {nome}: nenhuma noite travada à venda fora de feriado."
    cal = p.get("calibrado")
    solto = (f"solto {rot} {_lado(cal)}; solto bruto {_lado(p)}" if cal and rot else
             f"solto bruto {_lado(p)} (sem calibração)")
    return f"- {nome}: {p['noites']} noites travadas à venda; gravado médio {reais(p['gravado_medio'])}; {solto}."


def _linha_vendas(nome: str, v: dict, rot: str | None) -> str:
    fora = f" ({v['fora_da_comparacao']} venda(s) fora da comparação)" if v["fora_da_comparacao"] else ""
    if not v["vendas"]:
        return f"- {nome}, {ROTULO_OTIMISTA}: nenhuma venda nova em noite travada{fora}."
    return (f"- {nome}, {ROTULO_OTIMISTA}{'' if rot else SO_BRUTO}: {v['unidades']} unidade(s) em {v['vendas']} "
            f"venda(s); dinheiro na mesa {_cal_bruto(v['dinheiro_na_mesa_cal'], v['dinheiro_na_mesa'], rot)} "
            f"({v['vendas_com_dinheiro_na_mesa_cal'] if rot else v['vendas_com_dinheiro_na_mesa']} venda(s) com "
            f"solto > gravado); o solto venderia mais barato "
            f"{_cal_bruto(v['mais_barata_no_solto_cal'], v['mais_barata_no_solto'], rot)}; receita gravada "
            f"{reais(v['receita_gravada'])}, solta {_cal_bruto(v['receita_solta_cal'], v['receita_solta'], rot)}"
            f"{fora}.")


def _linha_encalhadas(nome: str, et: dict, rot: str | None) -> str:
    if not et["noites"]:
        return f"- {nome}: nenhuma."
    sem_fim = (f"; {et['sem_leitura_do_fim_do_dia']} só com a foto das 07:53 do dia (sem leitura do fim do dia)"
               if et["sem_leitura_do_fim_do_dia"] else "")
    barato = (f"{et['solto_mais_barato_cal']} {rot} (média {pct(et['solto_mais_barato_cal_media_pct'])}), "
              f"{et['solto_mais_barato']} bruto (média {pct(et['solto_mais_barato_media_pct'])})"
              if rot and et["solto_mais_barato_cal"] is not None else
              f"{et['solto_mais_barato']} bruto (média {pct(et['solto_mais_barato_media_pct'])}, sem calibração)")
    return (f"- {nome}: {et['noites']} ({et['unidades_livres']} unidades){sem_fim}; {et['sem_venda_no_periodo']} sem "
            f"nenhuma venda no período; o solto seria mais barato em {barato}.")


def _situacao(e: dict, tz) -> str:
    if e["leitura_do_fim"]:
        return f"passou com {e['livres']} de {e['total']} livres (Beds24 {_hm(e['leitura_do_fim'], tz)})"
    if e["ultima_foto"] == e["noite"]:
        return f"{e['livres']} de {e['total']} livres às 07:53 do dia (sem leitura do fim do dia)"
    return f"{e['livres']} de {e['total']} livres na foto de {_dm(e['ultima_foto'])} (sem leitura do fim do dia)"


def _precos_venda(v: dict, rot: str | None) -> str:
    if rot and v.get("solto_cal") is not None:
        return (f"gravado {reais(v['gravado'])}, solto {rot} {reais(v['solto_cal'])} ({pct(v['dif_cal_pct'])}), "
                f"bruto {reais(v['solto'])} ({pct(v['dif_pct'])})")
    return f"gravado {reais(v['gravado'])}, solto bruto {reais(v['solto'])} ({pct(v['dif_pct'])})"


def _origem_preco(v: dict, tz) -> str:
    """De onde veio o preço da venda: o plano (com a hora em que foi gravado) e a troca de preço no meio do intervalo."""
    partes = []
    if v.get("gravado_em"):
        partes.append(f"preço do plano gravado em {_hm(v['gravado_em'], tz)}")
    if v.get("troca_no_intervalo"):
        partes.append("troca de preço no intervalo: vale o da hora em que a venda foi vista")
    return f" [{'; '.join(partes)}]" if partes else ""


def _lista_div(lst: list[dict], n: int = 6) -> str:
    itens = [f"{_dm(d['noite'])} (PriceLabs {d['pricelabs']}, Beds24 {d['beds24']})" for d in lst[:n]]
    return ", ".join(itens) + (f" e mais {len(lst) - n}" if len(lst) > n else "")


def _linha_divergencias(x: dict) -> str | None:
    """Diferença PriceLabs × Beds24 que fica fora das vendas: na hora da foto (base) e nos saltos sem base."""
    t = x.get("divergencias_total") or {}
    if not (t.get("na_foto") or t.get("sem_base")):
        return None
    partes = []
    if t["na_foto"]:
        com = [d for d in x["divergencias"] if d["base"]]
        partes.append(f"{t['na_foto']} noite(s) com o Beds24 diferente do PriceLabs já na hora da foto ({_lista_div(com)}; "
                      "ocupação que uma das fontes não vê, como reserva de R$ 0 ou bloqueio): não é venda nova")
    if t["sem_base"]:
        sem = [d for d in x["divergencias"] if not d["base"]]
        partes.append(f"{t['sem_base']} salto(s) da foto para o Beds24 sem leitura de base ({_lista_div(sem)}): ficam "
                      "fora das vendas")
    return "- Diferença PriceLabs × Beds24 (fora das vendas): " + "; ".join(partes) + "."


def _texto_destaque(c: str, x: dict, cfg: dict, tz) -> list[str]:
    nome = (cfg["por_curto"].get(c) or {}).get("nome", c)
    cal = x["calibracao"]
    rot = _rot(cal)
    linhas = [f"## {c} ({nome}): candidata ao teste (2 a 3 semanas destravando só os dias de semana, dom-qui)", ""]
    linhas.append(_linha_calibracao(cal))
    ps, vs = x["precos"]["semana"], x["vendas_total"]["por_tipo"]["semana"]
    alerta = _alerta_sinal(ps, vs, cal, rot)
    if alerta:
        linhas.append(alerta)
    linhas.append(_linha_tipo("Dias de semana (dom-qui), o que o teste destravaria", ps, rot))
    linhas.append(_linha_vendas("Vendas novas nos dias de semana", vs, rot))
    for v in x["vendas"]:
        if v["tipo"] != "semana":
            continue
        fora = "" if "na_mesa" in v else f" [fora da comparação: {v['estado']}]"
        linhas.append(f"  - noite {_dm(v['noite'])} {v['dia']} +{v['unidades']} entre {_rotulo(v['entre'][0], tz)} e "
                      f"{_rotulo(v['entre'][1], tz)}: {_precos_venda(v, rot)}{_origem_preco(v, tz)}{fora}")
    linhas.append(_linha_encalhadas("Noites de semana que passaram com unidades livres",
                                    x["encalhadas_total"]["por_tipo"]["semana"], rot))
    for e in x["encalhadas"]:
        if e["tipo"] != "semana":
            continue
        fora = "" if e["comparavel"] else f" [fora da comparação: {e['estado']}]"
        linhas.append(f"  - {_dm(e['noite'])} {e['dia']}: {_situacao(e, tz)}, {e['vendas_no_periodo']} venda(s) no "
                      f"período; {_precos_venda(e, rot)}{fora}")
    div = _linha_divergencias(x)
    if div:
        linhas.append(div)
    pf, vf = x["precos"]["fds"], x["vendas_total"]["por_tipo"]["fds"]
    ef = x["encalhadas_total"]["por_tipo"]["fds"]
    precos_fds = "nenhuma noite travada à venda"
    if pf["noites"]:
        solto = (f"solto {rot} {reais(pf['calibrado']['solto_medio'])} ({pct(pf['calibrado']['dif_pct'])}), bruto "
                 f"{reais(pf['solto_medio'])} ({pct(pf['dif_pct'])})" if pf["calibrado"] and rot else
                 f"solto bruto {reais(pf['solto_medio'])} ({pct(pf['dif_pct'])})")
        precos_fds = f"{pf['noites']} noites travadas, gravado médio {reais(pf['gravado_medio'])}, {solto}"
    vend_fds = (f"{vf['unidades']} unidade(s) vendidas em {vf['vendas']} venda(s), dinheiro na mesa "
                f"{_cal_bruto(vf['dinheiro_na_mesa_cal'], vf['dinheiro_na_mesa'], rot)}, mais barata no solto "
                f"{_cal_bruto(vf['mais_barata_no_solto_cal'], vf['mais_barata_no_solto'], rot)}"
                f"{'' if rot else ' (solto bruto)'}" if vf["vendas"] else "nenhuma venda nova em noite travada")
    linhas.append(f"- Fora do teste (sexta e sábado seguem travados): {precos_fds}; {vend_fds}; {ef['noites']} noite(s) "
                  f"de fim de semana com unidades livres ({ef['unidades_livres']} unidades). Feriado, festas e pacote: "
                  f"{x['feriado_ou_pacote']} noite(s), fora da comparação.")
    if x["por_foto"]:
        linhas.append("- Por foto (horizonte inteiro de cada foto, noites travadas; gravado → solto "
                      + (f"{rot} / bruto):" if rot else "bruto, sem calibração):"))
        for f in x["por_foto"]:
            linhas.append(f"  - {_dm(f['foto'])}: semana {_por_foto(f['semana'])}; fds {_por_foto(f['fds'])}")
    return linhas


def _por_foto(t: dict) -> str:
    cal = f"{reais(t['solto_cal_medio'])} ({pct(t['dif_cal_pct'])}) / " if t["solto_cal_medio"] is not None else ""
    return f"{t['noites']} noites, {reais(t['gravado_medio'])} → {cal}{reais(t['solto_medio'])} ({pct(t['dif_pct'])})"


def _cb(cal, bruto, n_cal=None, n_bruto=None, ind: bool = False) -> str:
    """Célula 'calibrado / bruto' das tabelas (com o número de vendas entre parênteses, quando houver; "ind." quando o
    fator é só indicativo)."""
    c = "—" if cal is None else reais(cal) + (f" ({n_cal})" if n_cal is not None else "") + (" ind." if ind else "")
    return f"{c} / {reais(bruto)}" + (f" ({n_bruto})" if n_bruto is not None else "")


def _piso_txt(p: dict, t: str) -> str:
    """Piso da tabela de pisos; "(anúncio)" quando o mínimo do anúncio venceu o piso do dono numa suíte."""
    suite = str(p.get("origem_piso") or "").startswith("dom-qui")
    return reais(p[t]) + (" (anúncio)" if suite and (p.get("origem") or {}).get(t) == "anuncio" else "")


def texto_resumo(r: dict, cfg: dict | None = None) -> str:
    cfg = cfg or carregar_quartos()
    tz = fuso(cfg)
    fotos = r["fotos"]
    linhas = [f"# Sombra da flutuação: fotos de {_dm(r['de'])} a {_dm(r['ate'])} ({len(fotos)} foto(s): "
              f"{', '.join(_dm(f) for f in fotos)})", "", r["nota"], ""]
    lb, bb = r.get("leituras_beds24") or [], r.get("bases_beds24") or []
    linhas += ["Leituras do Beds24 usadas (depois da última foto de cada noite): "
               + (", ".join(_hm(x, tz) for x in lb) if lb else "nenhuma (as noites ficam com a foto das 07:53)") + ". "
               "Base de cada foto (o Beds24 na hora da foto; as vendas depois dela são Beds24 contra Beds24): "
               + (", ".join(_hm(x, tz) for x in bb) if bb else "nenhuma") + ".", ""]
    pisos = r.get("pisos") or {}
    if pisos:
        linhas.append("Pisos usados (última foto): " + "; ".join(
            f"{c} {_piso_txt(p, 'semana')}" + (f"/{_piso_txt(p, 'fds')}" if p["fds"] != p["semana"] else "")
            for c, p in pisos.items())
            + " (dom-qui/sex-sáb; \"anúncio\" = o mínimo do anúncio venceu o piso do dono). Comparação só nas noites "
            "travadas à venda, fora de feriado, festas, pacote e noite esgotada (o que o teste destravaria).")
        linhas.append("")
    if r["destaque"] in r["quartos"]:
        linhas += _texto_destaque(r["destaque"], r["quartos"][r["destaque"]], cfg, tz)
        linhas.append("")
    linhas += ["## Preço por quarto (última foto de cada noite)", "",
               "| Quarto | Tipo | Travadas à venda | Gravado médio | Solto calibrado (dif.) | Solto bruto (dif.) | "
               "Abaixo cal./bruto | Acima cal./bruto | Igual cal./bruto |", "|---|---|---|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        ind = " ind." if x["calibracao"].get("tipo") == "indicativa" else ""
        for tipo, nome in (("semana", "dom-qui"), ("fds", "sex-sáb")):
            p = x["precos"][tipo]
            if not p["noites"]:
                linhas.append(f"| {c} | {nome} | 0 | — | — | — | — | — | — |")
                continue
            k = p["calibrado"] or {}
            cal = f"{reais(k['solto_medio'])} ({pct(k['dif_pct'])}){ind}" if k else "sem calibração"
            ab = f"{k['abaixo']['noites']} ({pct(k['abaixo']['media_pct'])})" if k else "—"
            ac = f"{k['acima']['noites']} ({pct(k['acima']['media_pct'])})" if k else "—"
            ig = f"{k['iguais']}" if k else "—"
            linhas.append(f"| {c} | {nome} | {p['noites']} | {reais(p['gravado_medio'])} | {cal} | "
                          f"{reais(p['solto_medio'])} ({pct(p['dif_pct'])}) | {ab} / {p['abaixo']['noites']} "
                          f"({pct(p['abaixo']['media_pct'])}) | {ac} / {p['acima']['noites']} "
                          f"({pct(p['acima']['media_pct'])}) | {ig} / {p['iguais']} |")
    linhas += ["", f"## Vendas novas (noites travadas; preço em vigor quando a venda foi vista; {ROTULO_OTIMISTA})", "",
               "| Quarto | Vendas (unid.) | Receita gravada | Receita solta cal. / bruta | Dinheiro na mesa cal. / bruto | "
               "Mais barata no solto cal. / bruto | Fora da comparação | Canceladas |", "|---|---|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        v = x["vendas_total"]
        ind = x["calibracao"].get("tipo") == "indicativa"
        mesa = _cb(v["dinheiro_na_mesa_cal"], v["dinheiro_na_mesa"], v["vendas_com_dinheiro_na_mesa_cal"],
                   v["vendas_com_dinheiro_na_mesa"], ind)
        barata = _cb(v["mais_barata_no_solto_cal"], v["mais_barata_no_solto"], v["vendas_mais_baratas_no_solto_cal"],
                     v["vendas_mais_baratas_no_solto"], ind)
        linhas.append(f"| {c} | {v['vendas']} ({v['unidades']}) | {reais(v['receita_gravada'])} | "
                      f"{_cb(v['receita_solta_cal'], v['receita_solta'], ind=ind)} | {mesa} | {barata} | "
                      f"{v['fora_da_comparacao']} | {x['canceladas']} |")
    com_div = [(c, x) for c, x in r["quartos"].items() if _linha_divergencias(x)]
    if com_div:
        linhas += ["", "## Diferença PriceLabs × Beds24 (fora das vendas)", "",
                   "Na hora da foto, a base do Beds24 e o PriceLabs nem sempre contam as mesmas vendidas (o Beds24 conta "
                   "bloqueio e reserva que o PriceLabs não vê). Essa diferença não é venda nova: as vendas depois da foto "
                   "são Beds24 contra Beds24.", ""]
        for c, x in com_div:
            linhas.append(_linha_divergencias(x).replace("- Diferença PriceLabs × Beds24 (fora das vendas): ", f"- {c}: ", 1))
    linhas += ["", "## Noites que passaram com unidades livres (noites travadas; estado mais tardio: leitura do Beds24 "
               "depois da última foto, ou a foto das 07:53 do dia)", "",
               "| Quarto | Noites | Unidades livres | Sem leitura do fim do dia | Sem venda no período | "
               "Solto mais barato cal. / bruto | Fora da comparação |", "|---|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        e = x["encalhadas_total"]
        ind = " ind." if x["calibracao"].get("tipo") == "indicativa" else ""
        cal = (f"{e['solto_mais_barato_cal']} ({pct(e['solto_mais_barato_cal_media_pct'])}){ind}"
               if e["solto_mais_barato_cal"] is not None else "—")
        linhas.append(f"| {c} | {e['noites']} | {e['unidades_livres']} | {e['sem_leitura_do_fim_do_dia']} | "
                      f"{e['sem_venda_no_periodo']} | {cal} / {e['solto_mais_barato']} "
                      f"({pct(e['solto_mais_barato_media_pct'])}) | {e['fora_da_comparacao']} |")
    linhas += ["", "## Noites que já flutuam e calibração da estimativa", "",
               "| Quarto | Travadas | Só piso | Sem regra | Ajustadas | Esgotadas | Feriado/festas/pacote | Fator | "
               "Calibração (real × fórmula) |", "|---|---|---|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        s, cal = x["estados"], x["calibracao"]
        calt = (f"{pct(cal['erro_medio_pct'])} em {cal['noites']} noite(s) (abs. {pct(cal['erro_absoluto_medio_pct'])[1:]})"
                if cal["noites"] else "—")
        if cal["presas_no_piso"]:
            calt += f"; {cal['presas_no_piso']} presa(s) no piso"
        if cal["ajustes_desconhecidos"]:
            calt += "; pode incluir nossos ajustes (sem ov_pl)"
        linhas.append(f"| {c} | {s['travada']} | {s['so_piso']} | {s['sem_regra']} | {s['ajustada']} | "
                      f"{x['esgotadas']} | {x['feriado_ou_pacote']} | {_fator_txt(cal)} | {calt} |")
    fpl = sorted({d for x in r["quartos"].values() for d in x["feriado_pricelabs_fora_da_lista"]})
    if fpl:
        linhas += ["", "holiday_flag do PriceLabs em noites fora da lista de feriados do dono (seguem como semana/fds, "
                   "critério dos scripts): " + ", ".join(_dm(d) for d in fpl) + "."]
    sem_ov = [c for c, x in r["quartos"].items() if x["calibracao"]["ajustes_desconhecidos"]]
    linhas += ["", "## Limitações", "",
               "- Bloqueio sem reserva conta como vendido no Beds24 (tira unidade do numAvail): a leitura do fim do dia "
               "pode mostrar como venda o que é bloqueio feito depois da foto.",
               f"- Dinheiro na mesa e venda mais barata supõem o {ROTULO_OTIMISTA}; o solto é estimativa (veja a nota).",
               "- Sem leitura do Beds24 depois da foto, a noite fica com o estado das 07:53 do dia (sem leitura do fim "
               "do dia): vendas do resto do dia não entram. Sem a base do Beds24 da foto (inv.json da coleta), o salto "
               "da foto para a primeira leitura também não entra (vai para \"Diferença PriceLabs × Beds24\").",
               "- O preço de cada venda é o que valia quando ela foi vista: o do rec e o de cada plano passado em "
               "--gravados, a partir da hora dele. Venda vista depois de uma troca de preço no meio do intervalo fica "
               "com o preço novo; plano que não foi passado (ex.: o de uma rotina leve sem novo registrar) não entra."]
    if sem_ov:
        linhas.append(f"- Sem ov_pl_<Q>.json em alguma foto ({', '.join(sem_ov)}): a calibração pode incluir nossos "
                      "ajustes percentuais.")
    if len(fotos) < 2:
        linhas += ["", "Só uma foto no período: vendas novas entre fotos precisam de pelo menos duas fotos (ou de leitura "
                   "do Beds24 depois da foto)."]
    return "\n".join(linhas)


def texto_registro(foto: dict, existia: bool, caminho: Path, substituir: bool = False, cfg: dict | None = None) -> str:
    tz = fuso(cfg)
    h = foto["hoje"]
    if not existia:
        situacao = "gravada"
    elif substituir:
        situacao = "substituída (--substituir)"
    elif foto.get("quartos_mantidos"):
        situacao = f"juntada à foto do dia por quarto (mantidos como estavam: {', '.join(foto['quartos_mantidos'])})"
    else:
        situacao = "atualizada (os mesmos quartos)"
    linhas = [f"Foto de {_dm(h)}/{h[:4]} {situacao} em {caminho} ({foto['dias']} noites a partir de {_dm(h)}; nada foi "
              "gravado no PriceLabs).", NOTA_ESTIMATIVA, "",
              "| Quarto | Piso dom-qui/sex-sáb | Teto | Travadas | Só piso | Sem regra | Ajustadas | Esgotadas | Feriado | "
              "Semana (travadas à venda): gravado → solto bruto | Fds (travadas à venda): gravado → solto bruto |",
              "|---|---|---|---|---|---|---|---|---|---|---|"]
    for c, noites in foto["quartos"].items():
        lim = foto["pisos"].get(c) or {}
        cont = {s: sum(1 for e in noites if e["tipo"] in TIPOS_COMPARADOS and e["estado"] == s) for s in ESTADOS}
        est = estatistica_precos([e for e in noites if _comparavel(e)])
        cel = []
        for t in TIPOS_COMPARADOS:
            p = est[t]
            cel.append(f"{reais(p['gravado_medio'])} → {reais(p['solto_medio'])} ({pct(p['dif_pct'])}, {p['noites']} "
                       "noites)" if p["noites"] else "—")
        linhas.append(f"| {c} | {reais(lim.get('semana'))}/{reais(lim.get('fds'))} | {reais(lim.get('teto'))} | "
                      f"{cont['travada']} | {cont['so_piso']} | {cont['sem_regra']} | {cont['ajustada']} | "
                      f"{sum(1 for e in noites if e['tipo'] in TIPOS_COMPARADOS and esgotada(e))} | "
                      f"{sum(1 for e in noites if e['tipo'] == 'feriado')} | {cel[0]} | {cel[1]} |")
    origens = [f"{c}: {p['origem_piso']}" for c, p in foto["pisos"].items()
               if "anuncio" in (p.get("origem") or {}).values() and "dom-qui" in str(p.get("origem_piso"))]
    if origens:
        linhas.append("Mínimo do anúncio acima do piso do dono: " + " | ".join(origens) + ".")
    falt = foto.get("faltando") or {}
    if falt:
        itens = falt.items() if isinstance(falt, dict) else ((c, "sem rec_<Q>.json") for c in falt)
        linhas.append("Fora desta foto: " + ", ".join(f"{c} ({m})" for c, m in itens) + ".")
    sem_ov = [c for c, m in (foto.get("por_quarto") or {}).items() if c in foto["quartos"] and not m.get("ov_pl")]
    if sem_ov:
        linhas.append(f"Sem ov_pl_<Q>.json: {', '.join(sem_ov)} (a calibração pode incluir nossos ajustes).")
    if foto.get("gravados"):
        g = foto["gravados"]
        partes = []
        for x in g.get("planos") or []:
            t = f"{x['arquivo']} (gravado em {_hm(x['gravado_em'], tz)}): "
            if x.get("ignorado"):
                t += f"ignorado, {x['ignorado']}"
            else:
                t += f"{x['noites']} noite(s)"
                if x.get("ja_no_rec"):
                    t += f"; {x['ja_no_rec']} já no rec (gravado antes da leitura do PriceLabs)"
            partes.append(t)
        linhas.append(f"Preço gravado pelo plano (valor que o PriceLabs cobra: percent_base sobre a base do anúncio; cada "
                      f"plano vale da hora dele em diante): {g['noites']} noite(s)"
                      + (": " + "; ".join(partes) if partes else f" (--gravados {', '.join(g['arquivos'])})") + ".")
    b = foto.get("base_beds24") or {}
    if b.get("lido_em"):
        linhas.append(f"Base do Beds24 da foto: leitura de {_hm(b['lido_em'], tz)} ({b.get('fonte')}), {b.get('noites')} "
                      "noites, guardada com as leituras do Beds24 (as vendas depois da foto são Beds24 contra Beds24).")
    elif b.get("sem_base"):
        linhas.append(f"Sem base do Beds24 na foto ({b['sem_base']}): a primeira leitura do Beds24 depois da foto não "
                      "conta vendas, só a diferença PriceLabs × Beds24.")
    if foto.get("noites_sem_dado"):
        linhas.append("Noites fora do recálculo: " + ", ".join(f"{c} {n}" for c, n in foto["noites_sem_dado"].items()) + ".")
    return "\n".join(linhas)


def texto_fechar(info: dict, caminho: Path, cfg: dict) -> str:
    tz = fuso(cfg)
    rep = " (a mesma leitura já estava registrada: substituída)" if info["repetida"] else ""
    return (f"Leitura do Beds24 de {_hm(info['lido_em'], tz)} (Brasília) guardada em {caminho}{rep}: {info['noites']} "
            f"noites de {_dm(info['de'])} a {_dm(info['ate'])}, quartos {', '.join(info['quartos'])}. Nada foi gravado "
            "no Beds24 nem no PriceLabs. Bloqueio sem reserva conta como vendido.")


def _hoje_conferido(texto: str | None, campo: str, cfg: dict, forcar: bool) -> date:
    hoje = _data(texto, campo)
    agora = hoje_brt(cfg)
    if hoje != agora and not forcar:
        raise ErroSombra(f"{campo} {hoje} não é hoje em Brasília ({agora}): a foto e a leitura são do dia. Para "
                         "reprocessar outro dia, use --forcar.")
    return hoje


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    rg = sub.add_parser("registrar", help="grava a foto do dia (junta por quarto com a do mesmo --hoje)")
    rg.add_argument("--rec-dir", required=True, help="pasta com os rec_<Q>.json (e ov_pl_<Q>.json) da coleta do dia")
    rg.add_argument("--hoje", required=True, help="data da foto (AAAA-MM-DD), a de hoje em Brasília")
    rg.add_argument("--dias", type=int, default=DIAS_PADRAO, help=f"noites a partir de hoje (padrão {DIAS_PADRAO})")
    rg.add_argument("--contexto", help="json {quarto: {min, base, max}} ou a saída da coleta (contexto.sync)")
    rg.add_argument("--min-balcony", type=float, help="mínimo do anúncio da Balcony (piso dela no preço solto)")
    rg.add_argument("--gravados", action="append", default=[],
                    help="plano gravado depois da coleta (pode repetir); hora da gravação: a do arquivo ou ARQ@ISO-UTC")
    rg.add_argument("--inv", help="leitura do Beds24 da coleta, base da foto (padrão: o inv.json da --rec-dir)")
    rg.add_argument("--substituir", action="store_true", help="troca a foto inteira do dia em vez de juntar por quarto")
    rg.add_argument("--forcar", action="store_true", help="aceita --hoje fora de hoje e rec que começa antes de --hoje")
    rg.add_argument("--arquivo", help="registro (padrão: dados/sombra_flutuacao.json)")
    fc = sub.add_parser("fechar", help="guarda a leitura do Beds24 das rotinas leves (vendas depois da foto)")
    fc.add_argument("--inv", required=True, help="inventário do Beds24 ({quarto: {data: {numAvail, ...}}, \"_lido_em\"})")
    fc.add_argument("--lido-em", help="instante da leitura (ISO UTC; padrão: o _lido_em do inventário)")
    fc.add_argument("--hoje", help="primeira noite (AAAA-MM-DD; padrão: o dia da leitura em Brasília)")
    fc.add_argument("--dias", type=int, default=DIAS_PADRAO, help=f"noites a partir de hoje (padrão {DIAS_PADRAO})")
    fc.add_argument("--forcar", action="store_true", help="aceita leitura de outro dia (reprocessar)")
    fc.add_argument("--arquivo", help="registro (padrão: dados/sombra_flutuacao.json)")
    rs = sub.add_parser("resumo", help="compara as fotos do período")
    rs.add_argument("--de", required=True, help="primeira foto (AAAA-MM-DD)")
    rs.add_argument("--ate", required=True, help="última foto (AAAA-MM-DD)")
    rs.add_argument("--arquivo", help="registro (padrão: dados/sombra_flutuacao.json)")
    rs.add_argument("--json", action="store_true", help="imprime o resumo em JSON")
    a = ap.parse_args(argv)
    caminho = Path(a.arquivo) if a.arquivo else ARQ_SOMBRA
    try:
        cfg = carregar_quartos()
        if a.cmd == "registrar":
            hoje = _hoje_conferido(a.hoje, "--hoje", cfg, a.forcar)
            contexto = None
            if a.contexto:
                try:
                    contexto = anuncios_do_contexto(cfg, ler_json(a.contexto))
                except (OSError, UnicodeDecodeError, ValueError) as e:
                    raise ErroSombra(f"--contexto {a.contexto}: ilegível ({e})") from e
            if a.min_balcony is not None and not a.min_balcony > 0:
                raise ErroSombra(f"--min-balcony {a.min_balcony}: use um valor positivo")
            foto, existia = registrar(a.rec_dir, hoje, a.dias, contexto, a.min_balcony, caminho, cfg,
                                      gravados=ler_gravados(a.gravados), substituir=a.substituir, forcar=a.forcar,
                                      inv=a.inv)
            print(texto_registro(foto, existia, caminho, a.substituir, cfg))
            return 0
        if a.cmd == "fechar":
            try:
                inv = ler_json(a.inv)
            except (OSError, UnicodeDecodeError, ValueError) as e:
                raise ErroSombra(f"--inv {a.inv}: ilegível ({e})") from e
            do_inv = ler_instante(inv.get("_lido_em")) if isinstance(inv, dict) else None
            lido = ler_instante(a.lido_em) if a.lido_em else do_inv
            if lido is None:
                raise ErroSombra("--lido-em: informe o instante da leitura (ISO UTC, ex.: 2026-11-10T22:54:02Z); o "
                                 "inventário não tem _lido_em" if not a.lido_em else f"--lido-em {a.lido_em}: inválido")
            if do_inv and abs(lido - do_inv) > TOLERANCIA_LIDO_EM and not a.forcar:
                raise ErroSombra(f"--lido-em {_iso(lido)} difere do _lido_em do inventário ({_iso(do_inv)}): confira o "
                                 "arquivo (ou use --forcar)")
            dia_leitura = lido.astimezone(fuso(cfg)).date()
            hoje = _hoje_conferido(a.hoje, "--hoje", cfg, a.forcar) if a.hoje else dia_leitura
            if not a.forcar:
                if hoje != dia_leitura:
                    raise ErroSombra(f"--hoje {hoje} não é o dia da leitura em Brasília ({dia_leitura}); use --forcar")
                if dia_leitura != hoje_brt(cfg):
                    raise ErroSombra(f"a leitura do Beds24 é de {dia_leitura} ({_hm(_iso(lido), fuso(cfg))}), não de hoje "
                                     f"em Brasília ({hoje_brt(cfg)}): para reprocessar outro dia, use --forcar.")
            info = fechar(inv, lido, hoje, a.dias, caminho, cfg)
            print(texto_fechar(info, caminho, cfg))
            return 0
        r = resumo(carregar(caminho), _data(a.de, "--de"), _data(a.ate, "--ate"), cfg)
        print(json.dumps(r, ensure_ascii=False, indent=1) if a.json else texto_resumo(r, cfg))
        return 0
    except ErroSombra as e:
        print(f"ERRO: {e}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

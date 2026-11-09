"""Sombra da flutuação ("semana em paralelo", decisão do dono de 09/11/2026): compara, sem gravar nada no PriceLabs, o
preço que gravamos por data (quase sempre travado: min = max) com o que o PriceLabs cobraria flutuando sozinho dentro da
faixa, com o mínimo de cada suíte no piso de dia de semana do dono.

Período: fotos diárias de 10/11 a 16/11 (coleta das 07:53). Na revisão de segunda 16/11 o resumo decide o teste na King
Spa 7 (VK7): 2 a 3 semanas destravando só os dias de semana. Somente leitura: não chama PriceLabs, Beds24 nem Booking;
lê os arquivos rec_<Q>.json (recálculo do PriceLabs, refresh_listing_pricing / get_listing_prices) que a coleta já salvou.

"Preço solto" (ESTIMATIVA) por noite = max(piso da noite, min(uncustomized_price, teto)):
  - uncustomized_price é o preço do algoritmo do PriceLabs ANTES das personalizações (última hora, ocupação, dia órfão,
    distância etc.); o preço que ele cobraria de fato pode ficar acima ou abaixo. O resumo mede esse erro nas noites que já
    flutuam (calibração: preço real × a mesma fórmula com o piso e o teto da própria noite).
  - piso das 6 suítes com banheira (não a Balcony): domingo a quinta limites_seguranca.piso_vitrine_semana_suites (R$ 500)
    ÷ parametros.fator_vitrine_semana[pousada]; sexta e sábado limites_seguranca.piso_vitrine_fds_suites (R$ 850) ÷
    parametros.fator_vitrine_fds[pousada]; arredondado para cima. Balcony: o próprio mínimo do anúncio (--min-balcony,
    ou o "min" do --contexto, ou o "min" do quartos.json).
  - teto: o máximo do anúncio (--contexto; senão o "max" do quartos.json; padrão R$ 5.000, Afrodite R$ 10.000).
  - feriado e pacote (lista "feriados" de quartos.json, como pace.py e calendario_12m.py, e as datas de pacote de
    regra_h_beds24) ficam fora da comparação: continuam por data. O holiday_flag do PriceLabs só é anotado.
Estado da regra na noite: "travada" (min_price = max_price), "so_piso" (dso_flag 1 e max_price > min_price: o preço atual
já é flutuação real do PriceLabs acima do piso da regra) ou "sem_regra" (sem regra por data: preço do próprio PriceLabs).

Uso:
  python3 sombra_flutuacao.py registrar --rec-dir DIR --hoje AAAA-MM-DD [--dias 60] [--contexto json]
      [--min-balcony N] [--arquivo dados/sombra_flutuacao.json]
    Grava a foto do dia: por quarto e noite (hoje a hoje + dias - 1) data, dia da semana, tipo da noite
    (semana/fds/feriado), estado (travada/so_piso/sem_regra), preço gravado, preço solto estimado, piso, teto e
    vendidas/total. O mesmo --hoje substitui a foto do dia (idempotente). Gravação atômica (temporário + os.replace);
    arquivo ilegível nunca é apagado nem regravado. --contexto aceita {quarto: {min, base, max}} ou a saída da coleta
    (result.contexto.sync, por listing_id).
  python3 sombra_flutuacao.py resumo --de AAAA-MM-DD --ate AAAA-MM-DD [--arquivo ...] [--json]
    Compara as fotos do período, por quarto, nas noites travadas fora de feriado (o que o teste destravaria):
    - preço: gravado e solto médios nas noites de semana e de fim de semana (última foto de cada noite; a noite esgotada
      fica de fora, porque o preço dela não vende mais), quantas noites o solto ficaria abaixo ou acima do gravado e em
      quanto (%);
    - vendas novas entre fotos consecutivas (aumento de vendidas na mesma noite), com o gravado e o solto da foto ANTERIOR
      (o preço em vigor quando a venda entrou): dinheiro deixado na mesa (solto > gravado) e venda que o solto faria mais
      barata (solto < gravado);
    - noites que passaram com unidades livres (última foto antes da data) e se tiveram alguma venda no período;
    - noites que já flutuam (so_piso, sem_regra): calibração da estimativa.
    A VK7 vem em destaque (candidata ao teste). --json imprime o resumo em JSON.
Sai 0 em sucesso e 2 em erro (data inválida, nenhum rec_<Q>.json, arquivo corrompido, nenhuma foto no período).
Sem nomes de hóspedes: só preços e contagens.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import tempfile
from contextlib import contextmanager
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from comum import (DIAS_SEMANA, PASTA_SKILL, carregar_quartos, data_de_pacote, ler_json, periodo_feriado,  # noqa: E402
                   reais, vendidas_total)

ARQ_SOMBRA = PASTA_SKILL / "dados" / "sombra_flutuacao.json"
DIAS_PADRAO = 60
DESTAQUE = "VK7"
TETO_PADRAO = 5000.0
TETO_PADRAO_QUARTO = {"Afrodite": 10000.0}
TIPOS_COMPARADOS = ("semana", "fds")
ESTADOS = ("travada", "so_piso", "sem_regra")
# diferença abaixo disso (em reais) conta como "igual"
TOLERANCIA_REAIS = 1.0
NOTA_ESTIMATIVA = ("ESTIMATIVA: solto = max(piso da noite, min(uncustomized_price, teto)). O uncustomized_price é o preço do "
                   "algoritmo do PriceLabs antes das personalizações (última hora, ocupação, dia órfão etc.); o preço que "
                   "ele cobraria solto pode ficar acima ou abaixo (veja a calibração nas noites que já flutuam). A ordem "
                   "da Afrodite (acima das outras suítes) e a coerência entre suítes também não entram.")
DOC = ("Sombra da flutuação (scripts/sombra_flutuacao.py; dono 09/11/2026, semana em paralelo de 10/11 a 16/11): uma foto "
       "por dia (chave = data da foto) com, por quarto e noite, o preço gravado e o preço solto ESTIMADO (max(piso, "
       "min(uncustomized_price, teto))). Nada aqui foi gravado no PriceLabs. Sem nomes de hóspedes.")


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


def _data(texto: str, campo: str) -> date:
    s = str(texto).strip()
    try:
        if len(s) == 10:
            return date.fromisoformat(s)
    except ValueError:
        pass
    raise ErroSombra(f"{campo}: data inválida {texto!r} (use AAAA-MM-DD, ex.: 2026-11-10)")


# ---------- pisos, tetos e tipo da noite
def tipo_da_noite(cfg: dict, d: date) -> tuple[str, str | None]:
    """("feriado", nome) em noite de feriado ou pacote do dono (o mesmo critério de pace.py e calendario_12m.py: lista
    "feriados" de quartos.json; pacote por data de regra_h_beds24); senão ("fds", None) na sexta e no sábado e ("semana",
    None) de domingo a quinta."""
    fer = periodo_feriado(cfg, d)
    if fer:
        return "feriado", str(fer.get("nome") or "feriado")
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


def limites_do_quarto(cfg: dict, curto: str, contexto: dict | None = None, min_balcony: float | None = None) -> dict:
    """{"semana": piso, "fds": piso, "teto": teto, "origem_piso": texto} do quarto, fora de feriado."""
    q = cfg["por_curto"][curto]
    ctx = (contexto or {}).get(curto) or {}
    teto = preco(ctx.get("max")) or preco(q.get("max")) or TETO_PADRAO_QUARTO.get(curto, TETO_PADRAO)
    if q.get("banheira"):
        lim, par = cfg["limites_seguranca"], cfg["parametros"]
        pousada = q["pousada"]
        semana = float(lim["piso_vitrine_semana_suites"]) / float(par["fator_vitrine_semana"][pousada])
        fds = float(lim["piso_vitrine_fds_suites"]) / float(par["fator_vitrine_fds"][pousada])
        origem = (f"piso do dono R$ {lim['piso_vitrine_semana_suites']} (dom-qui) ÷ {par['fator_vitrine_semana'][pousada]} "
                  f"e R$ {lim['piso_vitrine_fds_suites']} (sex-sáb) ÷ {par['fator_vitrine_fds'][pousada]} ({pousada})")
        # para cima, sem o resíduo do ponto flutuante (850 ÷ 0,425 não pode virar 2001)
        return {"semana": math.ceil(semana - 1e-9), "fds": math.ceil(fds - 1e-9), "teto": _n(teto), "origem_piso": origem}
    if min_balcony is not None:
        piso, origem = float(min_balcony), "mínimo do anúncio (--min-balcony)"
    elif preco(ctx.get("min")) is not None:
        piso, origem = preco(ctx.get("min")), "mínimo do anúncio (--contexto)"
    else:
        piso, origem = float(q["min"]), "mínimo do anúncio (quartos.json)"
    piso = math.ceil(piso - 1e-9)
    return {"semana": piso, "fds": piso, "teto": _n(teto), "origem_piso": origem}


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


def preco_solto(piso: float | None, uncust: float | None, teto: float | None) -> float | None:
    """Estimativa do preço solto: max(piso, min(uncustomized_price, teto)); None sem uncustomized_price ou sem piso."""
    if uncust is None or piso is None:
        return None
    return max(float(piso), min(float(uncust), float(teto) if teto else float(uncust)))


def noite_da_foto(cfg: dict, curto: str, r: dict, d: date, lim: dict) -> dict:
    tipo, feriado = tipo_da_noite(cfg, d)
    piso = lim.get(tipo) if tipo in TIPOS_COMPARADOS else None
    uncust = preco(r.get("uncustomized_price"))
    vendidas, total = vendidas_total(r, int(cfg["por_curto"][curto].get("unidades") or 1))
    e = {"data": d.isoformat(), "dia": DIAS_SEMANA[d.weekday()], "tipo": tipo, "estado": estado_da_regra(r),
         "gravado": _n(preco(r.get("price"))), "solto": _n(preco_solto(piso, uncust, lim["teto"])), "piso": piso,
         "teto": lim["teto"], "uncust": _n(uncust), "min": _n(preco(r.get("min_price"))),
         "max": _n(preco(r.get("max_price"))), "vendidas": vendidas, "total": total}
    if feriado:
        e["feriado"] = feriado
    elif str(r.get("holiday_flag") or "").strip() == "1":
        e["feriado_pl"] = 1  # holiday_flag do PriceLabs fora da lista do dono: anotado, a noite segue como semana/fds
    return e


# ---------- arquivo
def ler_rec(caminho: Path) -> dict:
    """{data: noite} do pricing_array de um rec_<Q>.json (mesmo formato de calendario_12m.ler_recalculo)."""
    try:
        d = ler_json(caminho)
    except (OSError, UnicodeDecodeError, ValueError) as e:
        raise ErroSombra(f"{caminho}: ilegível ({e})") from e
    arr = ((d.get("data") if isinstance(d.get("data"), dict) else None) or d).get("pricing_array") or []
    return {str(r["date"])[:10]: r for r in arr if isinstance(r, dict) and r.get("date")}


def _problema_formato(d) -> str:
    if not isinstance(d, dict) or not isinstance(d.get("fotos"), dict):
        return "falta o objeto 'fotos'"
    for k, f in d["fotos"].items():
        if not isinstance(f, dict) or not isinstance(f.get("quartos"), dict):
            return f"foto {k}: sem 'quartos'"
        for q, noites in f["quartos"].items():
            if not isinstance(noites, list) or any(not isinstance(e, dict) or "data" not in e for e in noites):
                return f"foto {k}, {q}: noites fora do formato"
    return ""


def carregar(caminho: Path | str | None = None) -> dict:
    """{'_doc', 'fotos': {data: foto}}. Arquivo inexistente = nenhuma foto. Ilegível ou fora do formato: ErroSombra,
    sem apagar nem regravar nada."""
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
def montar_foto(cfg: dict, rec_dir: Path | str, hoje: date, dias: int = DIAS_PADRAO, contexto: dict | None = None,
                min_balcony: float | None = None, agora: datetime | None = None) -> dict:
    """A foto do dia, sem gravar: por quarto, as noites de hoje a hoje + dias - 1 que estão no rec_<Q>.json."""
    if dias < 1:
        raise ErroSombra(f"--dias {dias}: use 1 ou mais")
    pasta = Path(rec_dir)
    if not pasta.is_dir():
        raise ErroSombra(f"--rec-dir {pasta}: pasta não encontrada")
    quartos, pisos, faltando, sem_noite = {}, {}, [], {}
    for q in cfg["quartos"]:
        c = q["curto"]
        arq = pasta / f"rec_{c}.json"
        if not arq.exists():
            faltando.append(c)
            continue
        rec = ler_rec(arq)
        lim = limites_do_quarto(cfg, c, contexto, min_balcony)
        pisos[c] = lim
        noites = []
        for i in range(dias):
            d = hoje + timedelta(days=i)
            r = rec.get(d.isoformat())
            if r is None:
                sem_noite[c] = sem_noite.get(c, 0) + 1
                continue
            noites.append(noite_da_foto(cfg, c, r, d, lim))
        quartos[c] = noites
    if not quartos:
        raise ErroSombra(f"nenhum rec_<Q>.json em {pasta} (esperado rec_Q7.json, rec_VK7.json ...)")
    foto = {"hoje": hoje.isoformat(),
            "registrado_em": (agora or datetime.now(timezone.utc)).astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "dias": dias, "fonte": str(pasta), "pisos": pisos, "faltando": faltando}
    if sem_noite:
        foto["noites_sem_dado"] = sem_noite
    foto["quartos"] = quartos
    return foto


def registrar(rec_dir: Path | str, hoje: date, dias: int = DIAS_PADRAO, contexto: dict | None = None,
              min_balcony: float | None = None, caminho: Path | str | None = None, cfg: dict | None = None,
              agora: datetime | None = None) -> tuple[dict, bool]:
    """Grava a foto do dia (substitui a do mesmo --hoje). Devolve (foto, substituiu)."""
    cfg = cfg or carregar_quartos()
    foto = montar_foto(cfg, rec_dir, hoje, dias, contexto, min_balcony, agora)
    p = Path(caminho or ARQ_SOMBRA)
    p.parent.mkdir(parents=True, exist_ok=True)
    with _trava(p.parent):
        d = carregar(p)
        substituiu = foto["hoje"] in d["fotos"]
        d["_doc"] = DOC
        d["fotos"][foto["hoje"]] = foto
        d["fotos"] = dict(sorted(d["fotos"].items()))
        gravar(p, d)
    return foto, substituiu


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
    """Noite travada à venda, de semana ou fds (fora de feriado, pacote e noite esgotada), com gravado e solto."""
    return (e.get("tipo") in TIPOS_COMPARADOS and e.get("estado") == "travada" and e.get("gravado") is not None
            and e.get("solto") is not None and not esgotada(e))


def estatistica_precos(noites: list[dict]) -> dict:
    """Por tipo (semana, fds): noites, médias do gravado e do solto, diferença das médias e quantas noites o solto fica
    abaixo, acima ou igual ao gravado (com a diferença média em %)."""
    out = {}
    for tipo in TIPOS_COMPARADOS:
        ns = [e for e in noites if e["tipo"] == tipo]
        abaixo = [_dif_pct(e["solto"], e["gravado"]) for e in ns if e["solto"] < e["gravado"] - TOLERANCIA_REAIS]
        acima = [_dif_pct(e["solto"], e["gravado"]) for e in ns if e["solto"] > e["gravado"] + TOLERANCIA_REAIS]
        g, s = _media([e["gravado"] for e in ns]), _media([e["solto"] for e in ns])
        out[tipo] = {"noites": len(ns), "gravado_medio": _n(g), "solto_medio": _n(s), "dif_pct": _n(_dif_pct(s, g)),
                     "abaixo": {"noites": len(abaixo), "media_pct": _n(_media(abaixo))},
                     "acima": {"noites": len(acima), "media_pct": _n(_media(acima))},
                     "iguais": len(ns) - len(abaixo) - len(acima)}
    return out


def _vendas(fotos: list[tuple[str, dict]], curto: str) -> tuple[list[dict], int]:
    """Vendas novas entre fotos consecutivas: aumento de vendidas na mesma noite. O preço é o da foto ANTERIOR (em vigor
    quando a venda entrou). Devolve (vendas, unidades canceladas)."""
    vendas, canceladas = [], 0
    for (fa, a), (fb, b) in zip(fotos, fotos[1:]):
        antes = {e["data"]: e for e in (a.get("quartos") or {}).get(curto, [])}
        for e in (b.get("quartos") or {}).get(curto, []):
            y = antes.get(e["data"])
            if not y:
                continue
            dv = int(e.get("vendidas") or 0) - int(y.get("vendidas") or 0)
            if dv < 0:
                canceladas += -dv
            if dv <= 0:
                continue
            v = {"noite": e["data"], "dia": y.get("dia"), "tipo": y.get("tipo"), "estado": y.get("estado"),
                 "unidades": dv, "entre": [fa, fb], "gravado": y.get("gravado"), "solto": y.get("solto"),
                 "dif_pct": _n(_dif_pct(y.get("solto"), y.get("gravado")))}
            if y.get("feriado"):
                v["feriado"] = y["feriado"]
            if _comparavel(y):
                v["na_mesa"] = _n(max(0.0, y["solto"] - y["gravado"]) * dv)
                v["mais_barata"] = _n(max(0.0, y["gravado"] - y["solto"]) * dv)
            vendas.append(v)
    return vendas, canceladas


def _total_vendas(vendas: list[dict]) -> dict:
    cmp_ = [v for v in vendas if "na_mesa" in v]
    mesa = [v for v in cmp_ if v["na_mesa"] > TOLERANCIA_REAIS]
    barata = [v for v in cmp_ if v["mais_barata"] > TOLERANCIA_REAIS]
    return {"vendas": len(cmp_), "unidades": sum(v["unidades"] for v in cmp_),
            "receita_gravada": _n(sum(v["gravado"] * v["unidades"] for v in cmp_)),
            "receita_solta": _n(sum(v["solto"] * v["unidades"] for v in cmp_)),
            "dinheiro_na_mesa": _n(sum(v["na_mesa"] for v in mesa)), "vendas_com_dinheiro_na_mesa": len(mesa),
            "mais_barata_no_solto": _n(sum(v["mais_barata"] for v in barata)), "vendas_mais_baratas_no_solto": len(barata),
            "fora_da_comparacao": len(vendas) - len(cmp_)}


def _encalhadas(fotos: list[tuple[str, dict]], curto: str, ultima: str) -> list[dict]:
    """Noites que passaram (antes da última foto do período) com unidades livres na última foto que as viu."""
    obs: dict[str, list[dict]] = {}
    for _, f in fotos:
        for e in (f.get("quartos") or {}).get(curto, []):
            obs.setdefault(e["data"], []).append(e)
    out = []
    for noite in sorted(obs):
        if noite >= ultima:
            continue
        lst = obs[noite]
        e = lst[-1]
        livres = int(e.get("total") or 0) - int(e.get("vendidas") or 0)
        if livres <= 0:
            continue
        vendeu = sum(max(0, int(b.get("vendidas") or 0) - int(a.get("vendidas") or 0)) for a, b in zip(lst, lst[1:]))
        x = {"noite": noite, "dia": e.get("dia"), "tipo": e.get("tipo"), "estado": e.get("estado"), "livres": livres,
             "total": e.get("total"), "vendas_no_periodo": vendeu, "visto_em_fotos": len(lst),
             "gravado": e.get("gravado"), "solto": e.get("solto"), "dif_pct": _n(_dif_pct(e.get("solto"), e.get("gravado"))),
             "comparavel": _comparavel(e)}
        out.append(x)
    return out


def _total_encalhadas(enc: list[dict]) -> dict:
    cmp_ = [x for x in enc if x["comparavel"]]
    mais_barato = [x["dif_pct"] for x in cmp_ if x["solto"] < x["gravado"] - TOLERANCIA_REAIS]
    return {"noites": len(cmp_), "unidades_livres": sum(x["livres"] for x in cmp_),
            "sem_venda_no_periodo": sum(1 for x in cmp_ if x["vendas_no_periodo"] == 0),
            "solto_mais_barato": len(mais_barato), "solto_mais_barato_media_pct": _n(_media(mais_barato)),
            "fora_da_comparacao": len(enc) - len(cmp_)}


def _calibracao(noites: list[dict]) -> dict:
    """Nas noites que já flutuam (so_piso, sem_regra) à venda, fora de feriado: preço real × a fórmula do solto com o
    piso e o teto da própria noite. Mede o efeito das personalizações que o uncustomized_price não tem. A noite em que o
    preço real e a fórmula ficam os dois no piso da regra não mede nada (erro zero de graça) e fica de fora
    (presas_no_piso); a esgotada também (o preço dela não vende)."""
    erros, presas = [], 0
    for e in noites:
        if e.get("tipo") not in TIPOS_COMPARADOS or e.get("estado") not in ("so_piso", "sem_regra") or esgotada(e):
            continue
        if None in (e.get("gravado"), e.get("uncust"), e.get("min"), e.get("max")):
            continue
        formula = max(e["min"], min(e["uncust"], e["max"]))
        if abs(formula - e["min"]) < TOLERANCIA_REAIS and abs(e["gravado"] - e["min"]) < TOLERANCIA_REAIS:
            presas += 1
            continue
        erros.append(_dif_pct(e["gravado"], formula))
    return {"noites": len(erros), "erro_medio_pct": _n(_media(erros)),
            "erro_absoluto_medio_pct": _n(_media([abs(x) for x in erros])), "presas_no_piso": presas}


def resumo(dados: dict, de: date, ate: date, cfg: dict | None = None) -> dict:
    """Compara as fotos de `de` a `ate` (datas das fotos). Os preços usam a última foto de cada noite."""
    if ate < de:
        raise ErroSombra(f"--ate {ate} antes de --de {de}")
    cfg = cfg or carregar_quartos()
    fotos = [(k, f) for k, f in sorted(dados.get("fotos", {}).items()) if de.isoformat() <= k <= ate.isoformat()]
    if not fotos:
        datas = ", ".join(sorted(dados.get("fotos", {}))) or "nenhuma"
        raise ErroSombra(f"nenhuma foto de {de} a {ate} (fotos no arquivo: {datas})")
    ultima = fotos[-1][0]
    nomes = [q["curto"] for q in cfg["quartos"]]
    extras = sorted({q for _, f in fotos for q in f.get("quartos", {})} - set(nomes))
    out = {"de": de.isoformat(), "ate": ate.isoformat(), "fotos": [k for k, _ in fotos], "nota": NOTA_ESTIMATIVA,
           "destaque": DESTAQUE, "pisos": fotos[-1][1].get("pisos", {}), "quartos": {}}
    for c in nomes + extras:
        if not any(c in (f.get("quartos") or {}) for _, f in fotos):
            continue
        ultima_obs: dict[str, dict] = {}
        for _, f in fotos:
            for e in (f.get("quartos") or {}).get(c, []):
                ultima_obs[e["data"]] = e
        noites = [ultima_obs[k] for k in sorted(ultima_obs)]
        comparaveis = [e for e in noites if _comparavel(e)]
        estados = {s: sum(1 for e in noites if e.get("tipo") in TIPOS_COMPARADOS and e.get("estado") == s)
                   for s in ESTADOS}
        esgotadas = sum(1 for e in noites if e.get("tipo") in TIPOS_COMPARADOS and esgotada(e))
        vendas, canceladas = _vendas(fotos, c)
        enc = _encalhadas(fotos, c, ultima)
        por_foto = []
        for k, f in fotos:
            ns = [e for e in (f.get("quartos") or {}).get(c, []) if _comparavel(e)]
            if ns:
                est = estatistica_precos(ns)
                por_foto.append({"foto": k, **{t: {x: est[t][x] for x in ("noites", "gravado_medio", "solto_medio",
                                                                       "dif_pct")} for t in TIPOS_COMPARADOS}})
        out["quartos"][c] = {
            "noites": len(noites), "estados": estados, "esgotadas": esgotadas,
            "feriado_ou_pacote": sum(1 for e in noites if e.get("tipo") == "feriado"),
            "feriado_pricelabs_fora_da_lista": sorted(e["data"] for e in noites if e.get("feriado_pl")),
            "precos": estatistica_precos(comparaveis), "por_foto": por_foto,
            "vendas": vendas, "vendas_total": _total_vendas(vendas), "canceladas": canceladas,
            "encalhadas": enc, "encalhadas_total": _total_encalhadas(enc),
            "calibracao": _calibracao(noites)}
    return out


# ---------- texto
def pct(x) -> str:
    return "—" if x is None else f"{x:+.1f}%".replace(".", ",")


def _dm(texto: str) -> str:
    return f"{texto[8:10]}/{texto[5:7]}"


def _linha_tipo(nome: str, p: dict) -> str:
    if not p["noites"]:
        return f"- {nome}: nenhuma noite travada à venda fora de feriado."
    return (f"- {nome}: {p['noites']} noites travadas à venda; gravado médio {reais(p['gravado_medio'])}, solto médio "
            f"{reais(p['solto_medio'])} ({pct(p['dif_pct'])}); solto abaixo em {p['abaixo']['noites']} "
            f"(média {pct(p['abaixo']['media_pct'])}), acima em {p['acima']['noites']} (média {pct(p['acima']['media_pct'])}), "
            f"igual em {p['iguais']}.")


def _texto_destaque(c: str, x: dict, cfg: dict) -> list[str]:
    nome = (cfg["por_curto"].get(c) or {}).get("nome", c)
    linhas = [f"## {c} ({nome}): candidata ao teste (2 a 3 semanas destravando só os dias de semana)", ""]
    linhas.append(_linha_tipo("Dias de semana (dom-qui)", x["precos"]["semana"]))
    linhas.append(_linha_tipo("Fim de semana (sex-sáb)", x["precos"]["fds"]))
    vt = x["vendas_total"]
    linhas.append(f"- Vendas novas em noites travadas: {vt['unidades']} unidade(s) em {vt['vendas']} noite(s); gravado "
                  f"{reais(vt['receita_gravada'])}, solto {reais(vt['receita_solta'])}; dinheiro na mesa "
                  f"{reais(vt['dinheiro_na_mesa'])} ({vt['vendas_com_dinheiro_na_mesa']} venda(s) com solto > gravado); "
                  f"o solto venderia mais barato {reais(vt['mais_barata_no_solto'])} "
                  f"({vt['vendas_mais_baratas_no_solto']} venda(s) com solto < gravado).")
    for v in x["vendas"]:
        fora = "" if "na_mesa" in v else f" [fora da comparação: {v.get('feriado') or v['estado']}]"
        linhas.append(f"  - noite {_dm(v['noite'])} {v['dia']} ({v['tipo']}) +{v['unidades']} entre as fotos de "
                      f"{_dm(v['entre'][0])} e {_dm(v['entre'][1])}: gravado {reais(v['gravado'])}, solto "
                      f"{reais(v['solto'])} ({pct(v['dif_pct'])}){fora}")
    et = x["encalhadas_total"]
    linhas.append(f"- Noites travadas que passaram com unidades livres: {et['noites']} ({et['unidades_livres']} unidades); "
                  f"{et['sem_venda_no_periodo']} sem nenhuma venda no período; o solto seria mais barato em "
                  f"{et['solto_mais_barato']} delas (média {pct(et['solto_mais_barato_media_pct'])}).")
    for e in x["encalhadas"]:
        fora = "" if e["comparavel"] else f" [fora da comparação: {e['tipo'] if e['tipo'] == 'feriado' else e['estado']}]"
        linhas.append(f"  - {_dm(e['noite'])} {e['dia']} ({e['tipo']}): {e['livres']} de {e['total']} livres, "
                      f"{e['vendas_no_periodo']} venda(s) no período; gravado {reais(e['gravado'])}, solto "
                      f"{reais(e['solto'])} ({pct(e['dif_pct'])}){fora}")
    if x["por_foto"]:
        linhas.append("- Por foto (horizonte inteiro de cada foto, noites travadas):")
        for f in x["por_foto"]:
            s, w = f["semana"], f["fds"]
            linhas.append(f"  - {_dm(f['foto'])}: semana {s['noites']} noites, {reais(s['gravado_medio'])} → "
                          f"{reais(s['solto_medio'])} ({pct(s['dif_pct'])}); fds {w['noites']} noites, "
                          f"{reais(w['gravado_medio'])} → {reais(w['solto_medio'])} ({pct(w['dif_pct'])})")
    cal = x["calibracao"]
    if cal["noites"]:
        linhas.append(f"- Calibração da estimativa ({cal['noites']} noite(s) à venda que já flutuam fora do piso da regra; "
                      f"{cal['presas_no_piso']} presa(s) no piso ficam de fora): preço real {pct(cal['erro_medio_pct'])} em "
                      f"média contra a fórmula com o piso e o teto da noite (erro absoluto médio "
                      f"{pct(cal['erro_absoluto_medio_pct'])[1:]}). Negativo: o solto estimado tende a ficar acima do que o "
                      "PriceLabs cobraria.")
    return linhas


def texto_resumo(r: dict, cfg: dict | None = None) -> str:
    cfg = cfg or carregar_quartos()
    fotos = r["fotos"]
    linhas = [f"# Sombra da flutuação: fotos de {_dm(r['de'])} a {_dm(r['ate'])} ({len(fotos)} foto(s): "
              f"{', '.join(_dm(f) for f in fotos)})", "", r["nota"], ""]
    pisos = r.get("pisos") or {}
    if pisos:
        linhas.append("Pisos usados (última foto): " + "; ".join(
            f"{c} {reais(p['semana'])}" + (f"/{reais(p['fds'])}" if p["fds"] != p["semana"] else "") for c, p in pisos.items())
            + " (dom-qui/sex-sáb). Comparação só nas noites travadas à venda, fora de feriado, pacote e noite esgotada (o que o "
            "teste destravaria).")
        linhas.append("")
    if r["destaque"] in r["quartos"]:
        linhas += _texto_destaque(r["destaque"], r["quartos"][r["destaque"]], cfg)
        linhas.append("")
    linhas += ["## Preço por quarto (última foto de cada noite)", "",
               "| Quarto | Tipo | Travadas à venda | Gravado médio | Solto médio | Dif. | Solto abaixo | Solto acima | Igual |",
               "|---|---|---|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        for tipo, nome in (("semana", "dom-qui"), ("fds", "sex-sáb")):
            p = x["precos"][tipo]
            if not p["noites"]:
                linhas.append(f"| {c} | {nome} | 0 | — | — | — | — | — | — |")
                continue
            linhas.append(f"| {c} | {nome} | {p['noites']} | {reais(p['gravado_medio'])} | {reais(p['solto_medio'])} | "
                          f"{pct(p['dif_pct'])} | {p['abaixo']['noites']} ({pct(p['abaixo']['media_pct'])}) | "
                          f"{p['acima']['noites']} ({pct(p['acima']['media_pct'])}) | {p['iguais']} |")
    linhas += ["", "## Vendas novas entre fotos consecutivas (noites travadas; preço da foto anterior à venda)", "",
               "| Quarto | Vendas (unid.) | Receita gravada | Receita solta | Dinheiro na mesa | Mais barata no solto | "
               "Fora da comparação | Canceladas |", "|---|---|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        v = x["vendas_total"]
        linhas.append(f"| {c} | {v['vendas']} ({v['unidades']}) | {reais(v['receita_gravada'])} | "
                      f"{reais(v['receita_solta'])} | {reais(v['dinheiro_na_mesa'])} ({v['vendas_com_dinheiro_na_mesa']}) | "
                      f"{reais(v['mais_barata_no_solto'])} ({v['vendas_mais_baratas_no_solto']}) | "
                      f"{v['fora_da_comparacao']} | {x['canceladas']} |")
    linhas += ["", "## Noites que passaram com unidades livres (noites travadas; última foto antes da data)", "",
               "| Quarto | Noites | Unidades livres | Sem venda no período | Solto mais barato | Fora da comparação |",
               "|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        e = x["encalhadas_total"]
        linhas.append(f"| {c} | {e['noites']} | {e['unidades_livres']} | {e['sem_venda_no_periodo']} | "
                      f"{e['solto_mais_barato']} ({pct(e['solto_mais_barato_media_pct'])}) | {e['fora_da_comparacao']} |")
    linhas += ["", "## Noites que já flutuam e calibração da estimativa", "",
               "| Quarto | Travadas | Só piso | Sem regra | Esgotadas | Feriado/pacote | Calibração (real × fórmula) |",
               "|---|---|---|---|---|---|---|"]
    for c, x in r["quartos"].items():
        s, cal = x["estados"], x["calibracao"]
        calt = (f"{pct(cal['erro_medio_pct'])} em {cal['noites']} noite(s) (abs. {pct(cal['erro_absoluto_medio_pct'])[1:]})"
                if cal["noites"] else "—")
        if cal["presas_no_piso"]:
            calt += f"; {cal['presas_no_piso']} presa(s) no piso"
        linhas.append(f"| {c} | {s['travada']} | {s['so_piso']} | {s['sem_regra']} | {x['esgotadas']} | "
                      f"{x['feriado_ou_pacote']} | {calt} |")
    fpl = sorted({d for x in r["quartos"].values() for d in x["feriado_pricelabs_fora_da_lista"]})
    if fpl:
        linhas += ["", "holiday_flag do PriceLabs em noites fora da lista de feriados do dono (seguem como semana/fds, "
                   "critério dos scripts): " + ", ".join(_dm(d) for d in fpl) + "."]
    if len(fotos) < 2:
        linhas += ["", "Só uma foto no período: vendas novas e noites que passaram precisam de pelo menos duas fotos."]
    return "\n".join(linhas)


def texto_registro(foto: dict, substituiu: bool, caminho: Path) -> str:
    h = foto["hoje"]
    linhas = [f"Foto de {_dm(h)}/{h[:4]} {'substituída' if substituiu else 'gravada'} em {caminho} "
              f"({foto['dias']} noites a partir de {_dm(h)}; nada foi gravado no PriceLabs).", NOTA_ESTIMATIVA, "",
              "| Quarto | Piso dom-qui/sex-sáb | Teto | Travadas | Só piso | Sem regra | Esgotadas | Feriado | "
              "Semana (travadas à venda): gravado → solto | Fds (travadas à venda): gravado → solto |",
              "|---|---|---|---|---|---|---|---|---|---|"]
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
                      f"{cont['travada']} | {cont['so_piso']} | {cont['sem_regra']} | "
                      f"{sum(1 for e in noites if e['tipo'] in TIPOS_COMPARADOS and esgotada(e))} | "
                      f"{sum(1 for e in noites if e['tipo'] == 'feriado')} | {cel[0]} | {cel[1]} |")
    if foto.get("faltando"):
        linhas.append(f"Sem rec_<Q>.json: {', '.join(foto['faltando'])} (fora desta foto).")
    if foto.get("noites_sem_dado"):
        linhas.append("Noites fora do recálculo: " + ", ".join(f"{c} {n}" for c, n in foto["noites_sem_dado"].items()) + ".")
    return "\n".join(linhas)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    rg = sub.add_parser("registrar", help="grava a foto do dia (substitui a do mesmo --hoje)")
    rg.add_argument("--rec-dir", required=True, help="pasta com os rec_<Q>.json da coleta das 07:53")
    rg.add_argument("--hoje", required=True, help="data da foto (AAAA-MM-DD)")
    rg.add_argument("--dias", type=int, default=DIAS_PADRAO, help=f"noites a partir de hoje (padrão {DIAS_PADRAO})")
    rg.add_argument("--contexto", help="json {quarto: {min, base, max}} ou a saída da coleta (contexto.sync)")
    rg.add_argument("--min-balcony", type=float, help="mínimo do anúncio da Balcony (piso dela no preço solto)")
    rg.add_argument("--arquivo", help="registro (padrão: dados/sombra_flutuacao.json)")
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
            hoje = _data(a.hoje, "--hoje")
            contexto = None
            if a.contexto:
                try:
                    contexto = anuncios_do_contexto(cfg, ler_json(a.contexto))
                except (OSError, UnicodeDecodeError, ValueError) as e:
                    raise ErroSombra(f"--contexto {a.contexto}: ilegível ({e})") from e
            if a.min_balcony is not None and not a.min_balcony > 0:
                raise ErroSombra(f"--min-balcony {a.min_balcony}: use um valor positivo")
            foto, substituiu = registrar(a.rec_dir, hoje, a.dias, contexto, a.min_balcony, caminho, cfg)
            print(texto_registro(foto, substituiu, caminho))
            return 0
        r = resumo(carregar(caminho), _data(a.de, "--de"), _data(a.ate, "--ate"), cfg)
        print(json.dumps(r, ensure_ascii=False, indent=1) if a.json else texto_resumo(r, cfg))
        return 0
    except ErroSombra as e:
        print(f"ERRO: {e}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

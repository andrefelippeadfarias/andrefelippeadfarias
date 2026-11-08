"""Cota de recálculo do PriceLabs (refresh_listing_pricing): registra cada tentativa e diz se ainda pode recalcular.

Criado em 07/11/2026: travas do plano pricelabs/plano-reducao-chamadas.md (aprovado pelo dono). Limites oficiais
(documentação do PriceLabs conferida em 06/11; aprendizados.md, 14/10, 26/10 e 06/11):
- refresh_listing_pricing: 3 por quarto em janela MÓVEL de 24 h (não é o dia civil; a tentativa que falha também conta:
  Balcony em 06/11, 1 recálculo + 2 falhas "Beds24 temporarily unavailable" e 429 na 4ª) e 10 por conta por minuto;
- Customer API inteira: 60 por minuto e 1.000 por hora (só o recálculo é registrado aqui).
O recálculo que o próprio PriceLabs faz no envio diário (~08:31-08:47 BRT) não gasta a cota: não registre.

Registro em dados/recalculos.json, horário em UTC com segundos. O id do resultado da ferramenta MCP é o epoch em ms da
resposta e serve de horário (--id-ms); duas respostas com ids diferentes são duas tentativas, mesmo no mesmo segundo, e
o mesmo id registrado de novo não conta duas vezes. Toda tentativa conta na cota, inclusive 'falha' e '429'. Gravação
atômica (arquivo temporário + rename); arquivo ilegível nunca é apagado nem regravado.

Uso:
  python3 cota_recalculo.py registrar --quarto Q7 --resultado ok|falha|429 [--erro "texto"] [--em ISO | --id-ms <epoch ms>]
  python3 cota_recalculo.py pode --quarto Q7 [--agora ISO] [--rodada-desde ISO]
  python3 cota_recalculo.py ver [--agora ISO]
  (todos aceitam --arquivo <json> no lugar de dados/recalculos.json). Horário ISO sempre com fuso: Z (UTC) ou -03:00
  (BRT), ex.: 2026-11-08T10:55:12Z ou 2026-11-08T07:55:12-03:00; sem fuso é recusado (saída 2), porque a operação
  pensa em BRT e o registro é em UTC.
Sem --agora vale o relógio do computador, e a tentativa registrada com horário até 5 min à frente dele (relógio do MCP
adiantado) já conta. Com --agora (repetição ou teste), tentativa depois desse horário ainda não existe.

`pode` sai 0 só quando pode recalcular agora e 1 quando não pode:
- o quarto já tem 3 tentativas nas últimas 24 h (diz quando abre a próxima vaga: 24 h depois da mais antiga das 3);
- a conta já tem 10 tentativas no último minuto;
- a conta levou 429 (em qualquer quarto) no último minuto: espere 1 minuto antes de recalcular qualquer quarto, para um
  429 do limite da conta não virar uma cascata que gasta 1 vaga de cada quarto;
- o quarto teve falha ou 429 desde o início da rodada: nunca repita na mesma rodada um recálculo que falhou; com 400
  "Beds24 temporarily unavailable", espere a próxima rodada; com 429, espere e não insista. Sem --rodada-desde, a rodada
  começa na última rotina agendada (07:53, 10:53, 13:53, 16:53 ou 19:53 BRT) antes de agora.
Sai 2 em erro (quarto desconhecido, horário inválido ou sem fuso, arquivo corrompido): também quer dizer "não recalcule".
Aviso (não barra): se esta for a 3ª tentativa do quarto em 24 h e a vaga só reabrir depois da próxima rotina das 07:53
BRT, o recálculo dessa rotina completa fica sem vaga; a decisão é de quem chama.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
from contextlib import contextmanager
from datetime import datetime, time, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from comum import PASTA_SKILL, carregar_quartos, ler_instante  # noqa: E402

ARQ_RECALCULOS = PASTA_SKILL / "dados" / "recalculos.json"
LIMITE_QUARTO = 3
JANELA_QUARTO = timedelta(hours=24)
LIMITE_CONTA = 10
JANELA_CONTA = timedelta(minutes=1)
# depois de um 429 em qualquer quarto, nenhum quarto recalcula por 1 minuto (o 429 pode ser do limite da conta)
ESPERA_429_CONTA = timedelta(minutes=1)
# Customer API inteira (todas as chamadas, não só o recálculo): referência; aqui só o recálculo é registrado
LIMITE_API_MINUTO = 60
LIMITE_API_HORA = 1000
RESULTADOS = ("ok", "falha", "429")
ROTINAS_BRT = (time(7, 53), time(10, 53), time(13, 53), time(16, 53), time(19, 53))
ROTINA_COMPLETA_BRT = time(7, 53)
# --em/--id-ms além disso no futuro é engano de digitação; até isso, é o relógio do MCP adiantado e a tentativa já conta
FOLGA_FUTURO = timedelta(minutes=5)
TAM_ERRO = 300
# epoch em ms entre 2020 e 2100 (13 dígitos); 10 dígitos seria epoch em segundos
ID_MS_MIN, ID_MS_MAX = 1_577_836_800_000, 4_102_444_800_000
DOC = ("Tentativas de refresh_listing_pricing feitas pela rotina (scripts/cota_recalculo.py registrar). 'em' em UTC com "
       "segundos. Toda tentativa conta na cota de 3 por quarto em 24 h (janela móvel), inclusive falha e 429; o "
       "recálculo do envio diário do PriceLabs não entra. Sem nomes de hóspedes.")


class ErroCota(Exception):
    """Erro de uso ou de arquivo: a saída é 2 e nada é gravado."""


def fuso(cfg: dict | None = None):
    try:
        from zoneinfo import ZoneInfo
        return ZoneInfo((cfg or {}).get("fuso") or "America/Sao_Paulo")
    except Exception:  # sem base de fusos: Brasília não tem horário de verão desde 2019
        return timezone(timedelta(hours=-3))


def utc_iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def mostrar(dt: datetime, tz, segundos: bool = False) -> str:
    f = "%H:%M:%S" if segundos else "%H:%M"
    return f"{dt.astimezone(tz):%d/%m} {dt.astimezone(tz).strftime(f)} BRT ({dt.astimezone(timezone.utc).strftime(f)} UTC)"


def relogio() -> datetime:
    """Relógio do computador em UTC (os testes trocam esta função para injetar o horário)."""
    return datetime.now(timezone.utc)


def sem_fuso(texto) -> bool:
    """True quando o texto é um horário ISO válido sem fuso (nem Z nem offset): ambíguo entre BRT e UTC."""
    try:
        return datetime.fromisoformat(str(texto).strip().replace("Z", "+00:00")).tzinfo is None
    except ValueError:
        return False


def instante(texto: str, campo: str) -> datetime:
    """Horário do usuário em UTC. Exige fuso: um horário de Brasília digitado sem fuso viraria UTC, 3 h antes do real,
    e escaparia da trava da rodada."""
    dt = ler_instante(texto)
    if dt is None:
        raise ErroCota(f"{campo}: horário inválido {texto!r} (use ISO com fuso, ex.: 2026-11-08T10:55:12Z ou "
                       "2026-11-08T07:55:12-03:00)")
    if sem_fuso(texto):
        raise ErroCota(f"{campo}: horário sem fuso {texto!r}: escreva Z (UTC) ou -03:00 (BRT), ex.: "
                       "2026-11-08T10:55:12Z ou 2026-11-08T07:55:12-03:00 (sem fuso ficaria 3 h fora se for BRT)")
    return dt.astimezone(timezone.utc)


def de_id_ms(id_ms) -> datetime:
    """Horário (UTC, segundos inteiros) do id do resultado da ferramenta MCP, que é o epoch em milissegundos."""
    try:
        n = int(str(id_ms).strip())
    except ValueError:
        raise ErroCota(f"--id-ms {id_ms!r}: não é número (use o epoch em ms do id do resultado, ex.: 1794135312345)")
    if not ID_MS_MIN <= n < ID_MS_MAX:
        raise ErroCota(f"--id-ms {id_ms}: não parece epoch em milissegundos (13 dígitos, ex.: 1794135312345)")
    return datetime.fromtimestamp(n // 1000, tz=timezone.utc)


def validar_quarto(cfg: dict, quarto: str) -> str:
    if quarto not in cfg["por_curto"]:
        raise ErroCota(f"quarto desconhecido: {quarto!r} (use um destes: {', '.join(q['curto'] for q in cfg['quartos'])})")
    return quarto


def _problema_formato(d) -> str:
    if not isinstance(d, dict) or not isinstance(d.get("tentativas"), list):
        return "falta a lista 'tentativas'"
    for i, t in enumerate(d["tentativas"]):
        if not isinstance(t, dict) or not isinstance(t.get("quarto"), str):
            return f"tentativa {i}: sem quarto"
        if t.get("resultado") not in RESULTADOS:
            return f"tentativa {i}: resultado {t.get('resultado')!r} (esperado ok, falha ou 429)"
        if ler_instante(t.get("em")) is None:
            return f"tentativa {i}: horário 'em' inválido {t.get('em')!r}"
        if sem_fuso(t["em"]):
            return f"tentativa {i}: horário 'em' sem fuso {t['em']!r} (o registro é em UTC: termine com Z)"
        if "id_ms" in t and type(t["id_ms"]) is not int:
            return f"tentativa {i}: id_ms {t['id_ms']!r} não é número inteiro"
    return ""


def carregar(caminho: Path | str | None = None) -> dict:
    """{'_doc', 'tentativas': [...]}. Arquivo inexistente = nenhuma tentativa registrada. Arquivo ilegível ou fora do
    formato: ErroCota, sem apagar nem regravar nada."""
    p = Path(caminho or ARQ_RECALCULOS)
    if not p.exists():
        return {"_doc": DOC, "tentativas": []}
    aviso = "Nada foi apagado nem gravado: conserte o arquivo à mão (o git tem a última versão boa) antes de recalcular."
    try:
        d = json.loads(p.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, ValueError) as e:
        raise ErroCota(f"{p}: arquivo ilegível ou corrompido ({e}). {aviso}") from e
    problema = _problema_formato(d)
    if problema:
        raise ErroCota(f"{p}: arquivo corrompido ({problema}). {aviso}")
    return d


@contextmanager
def _trava(pasta: Path):
    """Uma gravação de cada vez (flock na pasta; sem arquivo extra). Sem fcntl (Windows), segue sem trava."""
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
    """Gravação atômica: escreve num temporário da mesma pasta, fsync e rename por cima (nunca deixa meio arquivo)."""
    p = Path(caminho)
    fd, tmp = tempfile.mkstemp(prefix=f".{p.name}.", suffix=".tmp", dir=p.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(d, f, ensure_ascii=False, indent=1)
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


def registrar(quarto: str, resultado: str, em: str | None = None, erro: str | None = None, id_ms=None,
              caminho: Path | str | None = None, cfg: dict | None = None,
              agora: datetime | None = None) -> tuple[dict, bool]:
    """Grava uma tentativa. Devolve (tentativa, nova). A mesma tentativa registrada de novo não conta duas vezes; com
    resultado diferente é erro. Quando as duas têm id_ms, o id decide (o mesmo id é repetição; um id diferente é
    tentativa nova, mesmo no mesmo segundo); quando falta o id_ms em uma delas, decide o quarto e o segundo."""
    cfg = cfg or carregar_quartos()
    validar_quarto(cfg, quarto)
    if resultado not in RESULTADOS:
        raise ErroCota(f"resultado {resultado!r}: use ok, falha ou 429")
    if em is not None and id_ms is not None:
        raise ErroCota("use --em ou --id-ms, não os dois")
    agora = (agora or relogio()).astimezone(timezone.utc)
    quando = de_id_ms(id_ms) if id_ms is not None else (instante(em, "--em") if em else agora)
    quando = quando.replace(microsecond=0)
    if quando > agora + FOLGA_FUTURO:
        raise ErroCota(f"horário da tentativa {utc_iso(quando)} está no futuro (agora {utc_iso(agora)}): confira a data")
    nova = {"quarto": quarto, "em": utc_iso(quando), "resultado": resultado}
    if erro and str(erro).strip():
        nova["erro"] = " ".join(str(erro).split())[:TAM_ERRO]
    if id_ms is not None:
        nova["id_ms"] = int(str(id_ms).strip())
    nova["registrado_em"] = utc_iso(agora)
    p = Path(caminho or ARQ_RECALCULOS)
    p.parent.mkdir(parents=True, exist_ok=True)
    with _trava(p.parent):
        d = carregar(p)
        for t in d["tentativas"]:
            if "id_ms" in nova and "id_ms" in t:
                mesma = t["id_ms"] == nova["id_ms"]
                if mesma and t["quarto"] != quarto:
                    raise ErroCota(f"o id_ms {nova['id_ms']} já está registrado para o {t['quarto']} em {t['em']}; "
                                   f"não gravei {quarto} (confira o id do resultado)")
            else:
                mesma = t["quarto"] == quarto and ler_instante(t["em"]) == quando
            if mesma:
                if t["resultado"] != resultado:
                    raise ErroCota(f"já há tentativa do {quarto} em {t['em']} com resultado {t['resultado']!r}; "
                                   f"não gravei {resultado!r} (conserte à mão se o registro anterior estiver errado)")
                return t, False
        d["tentativas"].append(nova)
        d["tentativas"].sort(key=lambda t: ler_instante(t["em"]))
        gravar(p, d)
    return nova, True


def ordenadas(tentativas: list[dict], agora: datetime, folga: timedelta = timedelta(0)) -> list[tuple[datetime, dict]]:
    """(instante, tentativa) até agora + folga, da mais antiga para a mais nova. Com --agora (repetição, teste) a folga é
    zero: tentativa depois desse horário não existe ainda. Com o relógio real a folga é FOLGA_FUTURO: a tentativa que o
    registrar aceitou com o relógio do MCP adiantado já aconteceu e conta."""
    lista = [(ler_instante(t["em"]), t) for t in tentativas]
    return sorted(((dt, t) for dt, t in lista if dt <= agora + folga), key=lambda x: x[0])


def abre_em(instantes: list[datetime], limite: int, janela: timedelta) -> datetime | None:
    """Quando a contagem na janela cai abaixo do limite (None se já está abaixo): `janela` depois da tentativa que, ao
    sair, deixa limite - 1 na janela. Com 3 na janela do quarto, 24 h depois da mais antiga das 3."""
    s = sorted(instantes)
    if len(s) < limite:
        return None
    return s[len(s) - limite] + janela


def inicio_rodada(agora: datetime, tz) -> datetime:
    """Última rotina agendada (07:53, 10:53, 13:53, 16:53, 19:53 BRT) até agora."""
    local = agora.astimezone(tz)
    cands = [datetime.combine(local.date() - timedelta(days=k), h, tzinfo=tz) for k in (0, 1) for h in ROTINAS_BRT]
    return max(c for c in cands if c <= local).astimezone(timezone.utc)


def proxima_rotina_completa(agora: datetime, tz) -> datetime:
    """Próxima rotina das 07:53 BRT depois de agora."""
    local = agora.astimezone(tz)
    c = datetime.combine(local.date(), ROTINA_COMPLETA_BRT, tzinfo=tz)
    if c <= local:
        c = datetime.combine(local.date() + timedelta(days=1), ROTINA_COMPLETA_BRT, tzinfo=tz)
    return c.astimezone(timezone.utc)


def _por_resultado(lista: list[tuple[datetime, dict]]) -> dict:
    return {r: sum(1 for _, t in lista if t["resultado"] == r) for r in RESULTADOS}


def situacao(tentativas: list[dict], quarto: str, agora: datetime, rodada_desde: datetime | None = None,
             tz=None, folga: timedelta = timedelta(0)) -> dict:
    """Pode recalcular o quarto agora? Junta a cota do quarto (3 em 24 h), a da conta (10 por minuto), a espera de 1
    minuto depois de um 429 em qualquer quarto e a trava da rodada (falha ou 429 do quarto desde o início da rodada). O
    aviso de manhã não barra. folga: veja ordenadas (FOLGA_FUTURO com o relógio real, zero com --agora)."""
    tz = tz or fuso()
    agora = agora.astimezone(timezone.utc)
    todas = ordenadas(tentativas, agora, folga)
    janela = [(dt, t) for dt, t in todas if t["quarto"] == quarto and dt > agora - JANELA_QUARTO]
    conta = [dt for dt, _ in todas if dt > agora - JANELA_CONTA]
    recentes_429 = [(dt, t) for dt, t in todas if t["resultado"] == "429" and dt > agora - ESPERA_429_CONTA]
    rodada_padrao = rodada_desde is None
    rodada = inicio_rodada(agora, tz) if rodada_padrao else rodada_desde.astimezone(timezone.utc)
    na_rodada = [(dt, t) for dt, t in todas if t["quarto"] == quarto and dt >= rodada]
    falhas = [(dt, t) for dt, t in na_rodada if t["resultado"] != "ok"]
    usados = len(janela)
    vaga_quarto = abre_em([dt for dt, _ in janela], LIMITE_QUARTO, JANELA_QUARTO)
    vaga_conta = abre_em(conta, LIMITE_CONTA, JANELA_CONTA)
    motivos = []
    if vaga_quarto:
        motivos.append(f"cota do quarto esgotada ({usados} tentativas em 24 h; falha e 429 contam): próxima vaga "
                       f"{mostrar(vaga_quarto, tz)}")
    if vaga_conta:
        motivos.append(f"a conta já fez {len(conta)} recálculos no último minuto (limite {LIMITE_CONTA}): espere até "
                       f"{mostrar(vaga_conta, tz, segundos=True)}")
    vaga_429 = None
    for dt, t in recentes_429[-1:]:
        # o 429 deste quarto nesta rodada já barra pela trava da rodada (até a próxima rodada, não só 1 minuto)
        if t["quarto"] != quarto or dt < rodada:
            vaga_429 = dt + ESPERA_429_CONTA
            motivos.append(f"a conta levou 429 às {mostrar(dt, tz, segundos=True)} ({t['quarto']}): pode ser o limite "
                           f"da conta; espere até {mostrar(vaga_429, tz, segundos=True)} antes de recalcular qualquer "
                           "quarto e não insista")
    for dt, t in falhas[-1:]:
        quando = f"às {mostrar(dt, tz, segundos=True)}" + (f" ({t['erro']})" if t.get("erro") else "")
        if t["resultado"] == "429":
            motivos.append(f"429 nesta rodada, {quando}: espere e não insista; tente na próxima rodada")
        elif "temporarily unavailable" in (t.get("erro") or "").lower():
            motivos.append(f"falha nesta rodada, {quando}: Beds24 instável; espere a próxima rodada")
        else:
            motivos.append(f"falha nesta rodada, {quando}: nunca repita na mesma rodada um recálculo que falhou sem "
                           "saber o motivo; espere a próxima rodada")
    pode = not motivos
    aviso = ""
    if pode and usados == LIMITE_QUARTO - 1:
        vaga_depois = abre_em([dt for dt, _ in janela] + [agora], LIMITE_QUARTO, JANELA_QUARTO)
        rotina = proxima_rotina_completa(agora, tz)
        if vaga_depois > rotina:
            dia = "de amanhã" if rotina.astimezone(tz).date() > agora.astimezone(tz).date() else "de hoje"
            aviso = (f"esta será a 3ª tentativa em 24 h ({quarto}) e a vaga só reabre {mostrar(vaga_depois, tz)}, depois "
                     f"da rotina completa {dia}, {mostrar(rotina, tz)}: o recálculo dessa rotina fica sem vaga. "
                     "Se ele for mais importante, não recalcule agora.")
    return {"quarto": quarto, "agora": agora, "pode": pode, "motivos": motivos, "usados": usados,
            "restam": max(0, LIMITE_QUARTO - usados), "por_resultado": _por_resultado(janela),
            "vaga_quarto": vaga_quarto, "sai_da_janela": janela[0][0] + JANELA_QUARTO if janela else None,
            "conta_minuto": len(conta), "vaga_conta": vaga_conta, "vaga_429": vaga_429, "rodada_desde": rodada,
            "rodada_padrao": rodada_padrao, "na_rodada": na_rodada, "aviso": aviso}


def _detalhe(por_resultado: dict) -> str:
    return ", ".join(f"{n} {r}" for r, n in por_resultado.items() if n)


def texto_pode(s: dict, tz) -> str:
    q = s["quarto"]
    linhas = [f"{q}: {'PODE recalcular' if s['pode'] else 'NÃO recalcule'}. Agora: {mostrar(s['agora'], tz)}."]
    linhas += [f"- Barrado: {m}." for m in s["motivos"]]
    det = _detalhe(s["por_resultado"])
    cota = f"- Cota do quarto: {s['usados']} de {LIMITE_QUARTO} em 24 h" + (f" ({det})" if det else "")
    cota += f"; restam {s['restam']}"
    if s["pode"]:
        cota += f" (depois desta, {s['restam'] - 1})"
    if s["vaga_quarto"]:
        cota += f". Próxima vaga: {mostrar(s['vaga_quarto'], tz)}."
    elif s["sai_da_janela"]:
        cota += f". Há vaga na cota; a mais antiga sai da janela {mostrar(s['sai_da_janela'], tz)}."
    else:
        cota += ". Há vaga na cota."
    linhas.append(cota)
    linhas.append(f"- Conta: {s['conta_minuto']} de {LIMITE_CONTA} recálculos no último minuto.")
    origem = ", a última rotina agendada (--rodada-desde muda)" if s["rodada_padrao"] else ""
    if s["na_rodada"]:
        feitas = "; ".join(f"{dt.astimezone(tz):%H:%M:%S} {t['resultado']}" for dt, t in s["na_rodada"])
        linhas.append(f"- Rodada desde {mostrar(s['rodada_desde'], tz)}{origem}: {q} já tentou {feitas}.")
    else:
        linhas.append(f"- Rodada desde {mostrar(s['rodada_desde'], tz)}{origem}: nenhuma tentativa deste quarto.")
    if s["aviso"]:
        linhas.append(f"AVISO: {s['aviso']}")
    return "\n".join(linhas)


def tabela(tentativas: list[dict], cfg: dict, agora: datetime, tz=None, folga: timedelta = timedelta(0)) -> str:
    tz = tz or fuso(cfg)
    agora = agora.astimezone(timezone.utc)
    todas = ordenadas(tentativas, agora, folga)
    linhas = [f"Recálculos registrados (refresh_listing_pricing) em {mostrar(agora, tz)}: limite de {LIMITE_QUARTO} por "
              f"quarto em 24 h (janela móvel; falha e 429 contam) e {LIMITE_CONTA} por conta por minuto.", "",
              "| Quarto | Usados em 24 h | Restam | Próxima vaga | Última tentativa |", "|---|---|---|---|---|"]
    for q in cfg["quartos"]:
        c = q["curto"]
        do_quarto = [(dt, t) for dt, t in todas if t["quarto"] == c]
        janela = [(dt, t) for dt, t in do_quarto if dt > agora - JANELA_QUARTO]
        vaga = abre_em([dt for dt, _ in janela], LIMITE_QUARTO, JANELA_QUARTO)
        det = _detalhe(_por_resultado(janela))
        usados = f"{len(janela)}" + (f" ({det})" if det else "")
        if do_quarto:
            dt, t = do_quarto[-1]
            ultima = f"{mostrar(dt, tz)} {t['resultado']}"
        else:
            ultima = "—"
        linhas.append(f"| {c} | {usados} | {max(0, LIMITE_QUARTO - len(janela))} | "
                      f"{mostrar(vaga, tz) if vaga else 'agora'} | {ultima} |")
    minuto = sum(1 for dt, _ in todas if dt > agora - JANELA_CONTA)
    hora = sum(1 for dt, _ in todas if dt > agora - timedelta(hours=1))
    linhas += ["", f"Conta: {minuto} de {LIMITE_CONTA} recálculos no último minuto; {hora} na última hora (a Customer API "
               f"inteira aceita {LIMITE_API_MINUTO} por minuto e {LIMITE_API_HORA} por hora, contando as outras chamadas, "
               "que não são registradas aqui)."]
    recentes_429 = [(dt, t) for dt, t in todas if t["resultado"] == "429" and dt > agora - ESPERA_429_CONTA]
    for dt, t in recentes_429[-1:]:
        linhas.append(f"429 da conta às {mostrar(dt, tz, segundos=True)} ({t['quarto']}): nenhum quarto recalcula até "
                      f"{mostrar(dt + ESPERA_429_CONTA, tz, segundos=True)}.")
    return "\n".join(linhas)


def main(argv=None) -> int:
    base = argparse.ArgumentParser(add_help=False)
    base.add_argument("--arquivo", help="registro (padrão: dados/recalculos.json)")
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    rg = sub.add_parser("registrar", parents=[base], help="grava uma tentativa de recálculo")
    rg.add_argument("--quarto", required=True, help="nome curto (Q7, Double, Q2, Afrodite, VK7, VK2, Balcony)")
    rg.add_argument("--resultado", required=True, choices=RESULTADOS)
    rg.add_argument("--erro", help="texto do erro (400, 429); sem nomes de hóspedes")
    qd = rg.add_mutually_exclusive_group()
    qd.add_argument("--em", help="horário da tentativa (ISO com fuso: Z ou -03:00). Padrão: agora")
    qd.add_argument("--id-ms", help="id do resultado da ferramenta MCP (epoch em ms)")
    rg.add_argument("--agora", help="relógio (ISO com fuso; para testes e repetição)")
    pd = sub.add_parser("pode", parents=[base], help="sai 0 se pode recalcular o quarto agora, 1 se não")
    pd.add_argument("--quarto", required=True)
    pd.add_argument("--agora", help="relógio (ISO com fuso; padrão: agora)")
    pd.add_argument("--rodada-desde", help="início da rodada (ISO com fuso); padrão: a última rotina agendada")
    vr = sub.add_parser("ver", parents=[base], help="tabela por quarto")
    vr.add_argument("--agora", help="relógio (ISO com fuso; padrão: agora)")
    a = ap.parse_args(argv)
    caminho = Path(a.arquivo) if a.arquivo else ARQ_RECALCULOS
    try:
        cfg = carregar_quartos()
        tz = fuso(cfg)
        agora = instante(a.agora, "--agora") if a.agora else relogio()
        # relógio real: conta também a tentativa registrada com o relógio do MCP um pouco adiantado
        folga = timedelta(0) if a.agora else FOLGA_FUTURO
        if a.cmd == "registrar":
            t, nova = registrar(a.quarto, a.resultado, em=a.em, erro=a.erro, id_ms=a.id_ms, caminho=caminho, cfg=cfg,
                                agora=agora)
            s = situacao(carregar(caminho)["tentativas"], a.quarto, max(agora, ler_instante(t["em"])), tz=tz,
                         folga=folga)
            if nova:
                estado = "registrado"
            elif "id_ms" in t and a.id_ms is not None:
                estado = "já estava registrado (mesmo id_ms; não conta duas vezes)"
            else:
                estado = ("já estava registrado (mesmo quarto e segundo; não conta duas vezes; se foi outra tentativa, "
                          "registre com --id-ms)")
            print(f"{estado}: {a.quarto} {t['resultado']} em {t['em']} "
                  f"({ler_instante(t['em']).astimezone(tz):%d/%m %H:%M:%S} BRT). "
                  f"{a.quarto}: {s['usados']} de {LIMITE_QUARTO} em 24 h; restam {s['restam']}"
                  + (f"; próxima vaga {mostrar(s['vaga_quarto'], tz)}." if s["vaga_quarto"] else "."))
            return 0
        existe = caminho.exists()
        tentativas = carregar(caminho)["tentativas"]
        nota = "" if existe else f"Nenhuma tentativa registrada ainda ({caminho} não existe): o saldo só conta o que foi registrado."
        if a.cmd == "pode":
            validar_quarto(cfg, a.quarto)
            rodada = instante(a.rodada_desde, "--rodada-desde") if a.rodada_desde else None
            s = situacao(tentativas, a.quarto, agora, rodada, tz=tz, folga=folga)
            print(texto_pode(s, tz))
            if nota:
                print(nota)
            return 0 if s["pode"] else 1
        print(tabela(tentativas, cfg, agora, tz, folga=folga))
        if nota:
            print(nota)
        return 0
    except ErroCota as e:
        print(f"ERRO: {e}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

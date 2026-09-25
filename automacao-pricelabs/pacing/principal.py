"""Orquestração de uma execução e dos comandos do dono."""

from __future__ import annotations

import os
import subprocess
import sys
import time
from datetime import timedelta
from pathlib import Path

from . import config, credenciais, jev, metricas, regras, relatorio, tempo
from .acoes import Executor
from .credenciais import ChaveAusente
from .estado import Armazem, OutraExecucao, Trava, pasta_padrao
from .pricelabs import PriceLabs
from .rede import ErroRede, Rede, transporte_urllib

ESCRITAS = {"ativo": frozenset({"POST", "DELETE"}), "contencao": frozenset({"DELETE"})}


class Ambiente:
    """Dependências externas, substituíveis nos testes."""

    def __init__(self, transporte=transporte_urllib, variaveis=None, cofre=None, relogio=None,
                 dormir=time.sleep, espaco_s=1.1, plataforma=sys.platform, comando=subprocess.run):
        self.plataforma = plataforma
        self.comando = comando  # executor de programas externos (schtasks); trocado nos testes
        self.transporte = transporte
        self.variaveis = os.environ if variaveis is None else variaveis
        self.cofre = cofre
        self.relogio = relogio
        self.dormir = dormir
        self.espaco_s = espaco_s

    def agora(self, cfg):
        return self.relogio() if self.relogio else tempo.agora(cfg["fuso_utc"])

    def rede(self, escritas=frozenset()):
        return Rede(self.transporte, escritas, self.espaco_s, self.dormir)

    def chave(self, nome):
        return credenciais.ler(nome, self.variaveis, self.cofre)


def pasta_dados(cfg) -> Path:
    return Path(cfg.get("pasta_dados") or pasta_padrao())


def _novo_resultado(cfg, agora, run_id):
    return {"run_id": run_id, "hora": agora.strftime("%d/%m/%Y %H:%M"), "hora_curta": agora.strftime("%d-%m %Hh%M"),
            "status": "verde", "resumo": "", "modo": cfg["modo"], "proxima_sync": "", "alertas": [],
            "ocupacao": [], "decisoes": [], "acoes": [], "ativas": [], "bloqueios": [], "precisa_atencao": False,
            "saude": {"execucoes_hoje": 0, "jev_chamadas_hoje": 0, "jev_tokens": 0, "observacao": ""}}


def _piorar(r, status):
    ordem = ["verde", "amarelo", "vermelho"]
    if ordem.index(status) > ordem.index(r["status"]):
        r["status"] = status


def executar(caminho_config, amb: Ambiente | None = None) -> dict:
    amb = amb or Ambiente()
    cfg = config.carregar(caminho_config)
    agora = amb.agora(cfg)
    run_id = agora.strftime("%Y%m%dT%H%M")
    pasta = pasta_dados(cfg)
    armazem = Armazem(pasta)
    r = _novo_resultado(cfg, agora, run_id)
    try:
        with Trava(pasta):
            est, origem = armazem.carregar()
            try:
                _executar(cfg, amb, agora, run_id, pasta, armazem, est, origem, r)
            except Exception as e:  # noqa: BLE001 - nenhuma falha pode sair sem relatório vermelho
                r["alertas"].append(f"Erro inesperado ({type(e).__name__}). Nada mais foi alterado nesta execução")
                r["resumo"] = "erro inesperado; veja os alertas"
                _piorar(r, "vermelho")
            finally:
                _fechar(cfg, agora, pasta, armazem, est, r)
    except OutraExecucao:
        r["status"], r["resumo"] = "amarelo", "outra execução ainda estava rodando; esta foi ignorada"
    return r


def _executar(cfg, amb, agora, run_id, pasta, armazem, est, origem, r):
    hoje = agora.date()
    parar = lambda: (pasta / "PARAR").exists()  # noqa: E731
    if origem in ("bak", "diario") and not est["disjuntor"]["ativo"]:
        motivo = f"estado local recuperado ({origem}); conferir e rodar RETOMAR"
        est["disjuntor"] = {"ativo": True, "motivo": motivo, "desde": agora.isoformat()}
        armazem.registrar_escrita({"ts": agora.isoformat(), "run_id": run_id, "evento": "disjuntor", "motivo": motivo})
        armazem.salvar(est)
    if parar():
        modo = "parado"
    elif cfg["modo"] == "observar":
        modo = "observar"
    else:
        modo = "contencao" if est["disjuntor"]["ativo"] else "ativo"
    r["modo"] = modo
    if origem in ("bak", "diario"):
        r["alertas"].append(f"Estado local recuperado ({origem}). A automação fica só removendo até RETOMAR")
        _piorar(r, "amarelo")
    if est["disjuntor"]["ativo"]:
        r["alertas"].append(f"Disjuntor ativo desde {est['disjuntor']['desde']}: {est['disjuntor']['motivo']}. "
                            "Só remoções. Rode RETOMAR depois de conferir")
        _piorar(r, "amarelo")
    if modo == "parado":
        r["resumo"] = "arquivo PARAR presente: nada foi lido nem alterado"
        _piorar(r, "amarelo")
        return
    try:
        chave_pl = amb.chave("pricelabs")
    except ChaveAusente as e:
        r["resumo"] = str(e)
        _piorar(r, "vermelho")
        return
    ids = [x["id"] for x in cfg["listings"]]
    unidades = {x["id"]: x["unidades"] for x in cfg["listings"]}
    pl = PriceLabs(amb.rede(ESCRITAS.get(modo, frozenset())), chave_pl, cfg["pms"], ids)
    try:
        api = {x["id"]: x for x in pl.listings()}
        brutos = pl.calendario(ids, hoje.isoformat(), (hoje + timedelta(days=59)).isoformat())
    except ErroRede as e:
        r["resumo"] = f"falha ao ler o PriceLabs ({e}{' ' + e.codigo if e.codigo else ''})"
        _piorar(r, "vermelho")
        return
    calendarios = {x["id"]: metricas.ler_calendario(x, unidades[x["id"]]) for x in brutos}
    try:
        reservas = pl.reservas(hoje.isoformat(), (hoje + timedelta(days=8)).isoformat())
    except ErroRede as e:
        reservas = None
        r["alertas"].append(f"Reservas ilegíveis ({e}). A chave precisa ser a do dono da conta")
        _piorar(r, "amarelo")
    mercado = _mercado(cfg, pl, est, hoje, r)
    ctx = regras.Contexto(cfg, agora, calendarios, api, reservas, mercado, est,
                          trava_receita=metricas.trava_receita(est["revpar"], hoje))
    r["proxima_sync"] = ctx.proxima_sync.strftime("%d/%m %H:%M")
    for item in cfg["listings"]:
        cal = calendarios.get(item["id"])
        faixas = metricas.ocupacao_faixas(cal, hoje) if cal and not cal.erro else {"0-6": None, "7-14": None, "15-29": None}
        r["ocupacao"].append({"apelido": item["apelido"], "papel": item["papel"], **faixas})
    for texto, grave in regras.alertas_gerais(ctx):
        r["alertas"].append(texto)
        if grave:
            _piorar(r, "amarelo")

    executor = Executor(pl, armazem, est, run_id, agora, parar=parar)
    for msg in executor.conciliar():
        r["acoes"].append(f"Conciliação: {msg}")
    limpeza, alertas_limpeza, disjuntor = regras.plano_limpeza(ctx)
    r["alertas"].extend(alertas_limpeza)
    if alertas_limpeza:
        _piorar(r, "amarelo")
    if disjuntor:
        executor.acionar_disjuntor("preço abaixo do mínimo depois da sincronização")
    for acao in limpeza:
        nome = ctx.item(acao["listing"])["apelido"]
        if modo in ("ativo", "contencao"):
            resultado = executor.apagar(acao)
            r["acoes"].append(f"Remover desconto {nome} {acao['data']} ({acao['motivo']}): {resultado}")
        else:
            r["acoes"].append(f"Faria: remover desconto {nome} {acao['data']} ({acao['motivo']})")
    if est["dsos"] and modo != "ativo":
        r["alertas"].append("Há descontos do programa ativos e o modo atual não os remove. Use DESFAZER")

    if ctx.na_janela:
        _ler_substituicoes(ctx, pl, modo, r)
    blocos, bloqueios = regras.candidatos(ctx, incluir_sombra=(modo == "observar"))
    r["bloqueios"].extend(bloqueios)
    if blocos:
        _decidir(cfg, amb, ctx, pl, executor, blocos, modo, run_id, est, r)
    if executor.disjuntor:
        r["alertas"].append(f"Disjuntor acionado: {executor.disjuntor}. A automação volta a só remover")
        _piorar(r, "vermelho")
    r["alertas"].extend(executor.alertas)
    if executor.alertas:
        _piorar(r, "amarelo")


def _mercado(cfg, pl, est, hoje, r) -> dict:
    cache = est["mercado"]
    if cache.get("dia") == hoje.isoformat() and isinstance(cache.get("ocupacao_7d"), dict):
        return cache["ocupacao_7d"]
    ocupacao, metricas_ok = {}, {}
    for item in cfg["listings"]:
        if item["papel"] == "transbordo":
            continue
        try:
            m = pl.metricas(item["id"])
        except ErroRede:
            ocupacao[item["id"]] = None
            continue
        ocupacao[item["id"]] = metricas.mercado_7d(m)
        metricas_ok[item["id"]] = m
    if any(v is not None for v in ocupacao.values()):
        est["mercado"] = {"dia": hoje.isoformat(), "ocupacao_7d": ocupacao}
    else:
        r["alertas"].append("Ocupação do mercado indisponível hoje: sem descontos novos")
        _piorar(r, "amarelo")
    amostra = metricas.amostra_receita(metricas_ok)
    if amostra:
        est["revpar"] = [a for a in est["revpar"] if a.get("dia") != hoje.isoformat()][-59:]
        est["revpar"].append({"dia": hoje.isoformat(), **amostra})
    return ocupacao


def _ler_substituicoes(ctx, pl, modo, r):
    inicio, fim = ctx.hoje.isoformat(), (ctx.hoje + timedelta(days=7)).isoformat()
    for item in ctx.cfg["listings"]:
        real = item["papel"] == "sem_tabela" and item["desconto_permitido"]
        if item["papel"] == "transbordo" or (not real and modo != "observar"):
            continue
        try:
            lidas = pl.substituicoes(item["id"], inicio, fim)
        except ErroRede as e:
            r["alertas"].append(f"{item['apelido']}: substituições ilegíveis ({e})")
            continue
        datas = [tempo.ler_data(o.get("date")) for o in lidas]
        if any(d is None for d in datas):
            r["alertas"].append(f"{item['apelido']}: substituição com data em formato desconhecido; listing bloqueada")
            continue
        ctx.substituicoes[item["id"]] = {d.isoformat(): o for d, o in zip(datas, lidas)}


def _decidir(cfg, amb, ctx, pl, executor, blocos, modo, run_id, est, r):
    cj = cfg["jev"]
    hoje = ctx.hoje.isoformat()
    ej = est["jev"]
    if ej.get("dia") != hoje:
        ej.update(dia=hoje, chamadas=0, ultimo_hash="", ultima_resposta=None, tokens=0)
    pedido = regras.montar_pedido(blocos, cj["modelo"], cfg["desconto"]["percentual"])
    h = regras.impressao(pedido)
    medir = modo == "observar" and cj["medir_estabilidade"]
    if ej.get("ultimo_hash") == h and ej.get("ultima_resposta") and not medir:
        resposta = jev.RespostaJev(**ej["ultima_resposta"])
        r["acoes"].append("Jev: dados iguais aos da última consulta de hoje; resposta reaproveitada")
    elif ej["chamadas"] >= cj["max_chamadas_dia"]:
        r["alertas"].append("Teto diário de chamadas ao Jev atingido: sem decisão nesta execução")
        _piorar(r, "amarelo")
        return
    else:
        resposta = _chamar_jev(cfg, amb, pedido, ej, r)
        if resposta is None:
            return
        ej.update(ultimo_hash=h, ultima_resposta=resposta.__dict__)
    decisoes = regras.decidir(blocos, resposta.respostas, cj)
    regras.confirmar(decisoes, est, resposta.chamada_id, ctx.agora, cj["confirmar_duas_execucoes"])
    if modo == "observar":
        _medir_estabilidade(est, decisoes, hoje)
    acoes, bloqueios = regras.plano_criacao(decisoes, ctx, run_id, modo)
    r["bloqueios"].extend(bloqueios)
    feitas = {}
    for acao in acoes:
        nome = ctx.item(acao["listing"])["apelido"]
        if acao["tipo"] == "criar":
            res = executor.criar(acao)
            feitas.setdefault(acao["listing"], []).append(res)
            r["acoes"].append(f"Desconto {acao['payload']['price']}% {nome} {acao['data']}: {res}")
        elif acao["tipo"] == "faria_criar":
            r["acoes"].append(f"Faria: desconto {acao['payload']['price']}% {nome} {acao['data']}")
        else:
            r["acoes"].append(f"Sombra (só registro): Jev descontaria {nome} {acao['data']}")
    for dec in decisoes:
        resultado = dec.motivo
        if dec.bloco.sombra:
            resultado = "sombra: " + resultado
        elif dec.agir and dec.bloco.listing["id"] in feitas:
            resultado = ", ".join(feitas[dec.bloco.listing["id"]])
        r["decisoes"].append({"quarto": dec.bloco.listing["apelido"], "bloco": dec.bloco.tipo, "escolha": dec.escolha,
                              "confianca": dec.confianca, "veto": dec.veto, "resultado": resultado})


def _chamar_jev(cfg, amb, pedido, ej, r):
    cj = cfg["jev"]
    tentativas = [(cj["provedor"], cj["modelo"])]
    if cj.get("provedor_reserva"):
        tentativas.append((cj["provedor_reserva"], cj.get("modelo_reserva") or jev.PROVEDORES[cj["provedor_reserva"]]["modelo"]))
    ultimo_erro = ""
    for provedor, modelo in tentativas:
        try:
            chave = amb.chave(jev.PROVEDORES[provedor]["chave"])
            ej["chamadas"] += 1
            corpo = dict(pedido, model=modelo)
            resposta = jev.perguntar(amb.rede(), provedor, chave, modelo, corpo["state"], corpo["questions"],
                                     cj["familia_modelo"])
        except (ChaveAusente, ErroRede, jev.JevInvalido) as e:
            ultimo_erro = f"{provedor}: {e}"
            continue
        ej["falhas_seguidas"] = 0
        ej["ultima_ok_em"] = amb.agora(cfg).isoformat()
        ej["tokens"] = ej.get("tokens", 0) + resposta.tokens_entrada
        return resposta
    ej["falhas_seguidas"] = ej.get("falhas_seguidas", 0) + 1
    r["alertas"].append(f"Jev sem resposta válida ({ultimo_erro}). Nenhum desconto novo")
    _piorar(r, "amarelo")
    return None


def _medir_estabilidade(est, decisoes, hoje):
    m = est.setdefault("estabilidade", {"comparacoes": 0, "mudancas": 0, "decisoes": 0, "dias_com_jev": [], "ultimo": {}})
    if hoje not in m["dias_com_jev"]:
        m["dias_com_jev"].append(hoje)
    for dec in decisoes:
        k = dec.bloco.chave
        h = regras.impressao(dec.bloco.bandas)
        anterior = m["ultimo"].get(k)
        if anterior and anterior["hash"] == h:
            m["comparacoes"] += 1
            m["mudancas"] += int(anterior["escolha"] != dec.escolha)
        m["ultimo"][k] = {"hash": h, "escolha": dec.escolha}
        m["decisoes"] += 1


def texto_observacao(est) -> str:
    m = est.get("estabilidade") or {}
    dias, decis = len(m.get("dias_com_jev", [])), m.get("decisoes", 0)
    comp, mud = m.get("comparacoes", 0), m.get("mudancas", 0)
    variacao = f"{100 * mud / comp:.0f}%" if comp else "sem comparação ainda"
    pronto = dias >= 5 and decis >= 20 and comp > 0 and mud / comp < 0.10
    return (f"{dias} dia(s) com o Jev respondendo (mínimo 5), {decis} decisões (mínimo 20), "
            f"variação com dados iguais {variacao} (máximo 10%). "
            + ("Critérios numéricos atingidos: revise as decisões antes de passar para ativo."
               if pronto else "Ainda não é hora de passar para ativo."))


def _fechar(cfg, agora, pasta, armazem, est, r):
    hoje = agora.date().isoformat()
    saude = est["saude"]
    if saude.get("dia") != hoje:
        saude["dia"], saude["execucoes"] = hoje, 0
    saude["execucoes"] = saude.get("execucoes", 0) + 1
    saude["ultima_execucao"] = agora.isoformat()
    ej = est["jev"]
    if ej.get("falhas_seguidas", 0) >= 7:
        r["alertas"].append("Jev sem resposta há 7 tentativas seguidas")
        _piorar(r, "amarelo")
    nomes = {x["id"]: x["apelido"] for x in cfg["listings"]}
    r["ativas"] = [f"{nomes.get(d['listing'], d['listing'])} {d['data']}: {d['payload']['price']}% ({d.get('status')})"
                   for d in sorted(est["dsos"].values(), key=lambda x: (x["data"], x["listing"]))]
    r["saude"] = {"execucoes_hoje": saude["execucoes"], "jev_chamadas_hoje": ej.get("chamadas", 0) if ej.get("dia") == hoje else 0,
                  "jev_tokens": ej.get("tokens", 0) if ej.get("dia") == hoje else 0, "observacao": texto_observacao(est)}
    saude["amarelas_seguidas"] = saude.get("amarelas_seguidas", 0) + 1 if r["status"] == "amarelo" else 0
    r["precisa_atencao"] = r["status"] == "vermelho" or saude["amarelas_seguidas"] >= 2
    if not r["resumo"]:
        n = sum(1 for a in r["acoes"] if not a.startswith(("Faria", "Sombra", "Jev:")))
        r["resumo"] = {"verde": f"{n} alteração(ões) na conta; {len(r['alertas'])} alerta(s)",
                       "amarelo": "execução concluída com pendências; veja os alertas",
                       "vermelho": "execução com erro; veja os alertas"}[r["status"]]
    armazem.salvar(est)
    armazem.registrar_execucao({k: r[k] for k in ("run_id", "status", "modo", "resumo", "alertas", "acoes",
                                                  "decisoes", "bloqueios")}, agora.strftime("%Y-%m"))
    ocup = {o["apelido"]: o["0-6"] for o in r["ocupacao"]}
    linha = {"data_hora": agora.strftime("%d/%m/%Y %H:%M"), "status": r["status"], "modo": r["modo"]}
    for item in cfg["listings"]:  # colunas fixas, mesmo quando a execução falha cedo
        v = ocup.get(item["apelido"])
        linha[f"ocup_0_6_{item['codigo']}"] = "" if v is None else f"{v:.1f}".replace(".", ",")
    linha.update(descontos_ativos=len(est["dsos"]), chamadas_jev=r["saude"]["jev_chamadas_hoje"],
                 alertas=len(r["alertas"]))
    armazem.anexar_historico(linha)
    relatorio.publicar(r, cfg, pasta)

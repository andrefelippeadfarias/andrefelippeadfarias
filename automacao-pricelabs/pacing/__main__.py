"""Comandos: python -m pacing <comando>."""

from __future__ import annotations

import argparse
import getpass
import json
import os
import sys
import tempfile
import time
import unittest
from datetime import timedelta
from io import StringIO
from pathlib import Path

from . import agenda, config, credenciais, principal, relatorio, tempo
from .acoes import Executor
from .credenciais import ChaveAusente
from .estado import Armazem, Trava
from .pricelabs import PriceLabs
from .rede import ErroRede

PROJETO = Path(__file__).resolve().parent.parent
CONFIG = PROJETO / "config.json"


def _cfg(args):
    return config.carregar(args.config)


def cmd_executar(args, amb):
    r = principal.executar(args.config, amb)
    print(f"[{r['status'].upper()}] {r['resumo']}")
    for a in r["alertas"]:
        print(f"  alerta: {a}")
    return {"verde": 0, "amarelo": 0, "vermelho": 1}[r["status"]]


def _linha(ok, texto, itens):
    itens.append((ok, texto))
    print(f"{'OK   ' if ok else 'FALHA'} {texto}")


def cmd_verificar(args, amb):
    itens = []
    cfg = _cfg(args)
    pasta = principal.pasta_dados(cfg)
    agora = amb.agora(cfg)
    _linha(sys.version_info >= (3, 10), f"Python {sys.version.split()[0]} (mínimo 3.10)", itens)
    deslocamento = round(time.localtime().tm_gmtoff / 3600)
    _linha(deslocamento == cfg["fuso_utc"], f"fuso do computador UTC{deslocamento:+d} (config: UTC{cfg['fuso_utc']:+d})", itens)
    est, _ = Armazem(pasta).carregar()
    ultima = tempo.ler_instante(est["saude"].get("ultima_execucao"))
    atrasado = ultima is None or agora - ultima > timedelta(hours=26)
    _linha(not atrasado, "última execução: " + (ultima.strftime("%d/%m %H:%M") if ultima else "nenhuma"), itens)
    if (pasta / "PARAR").exists():
        _linha(False, "arquivo PARAR presente: a automação está parada (use RETOMAR)", itens)
    mesa = amb.mesa(cfg)
    _linha(mesa is not None, f"Área de Trabalho para avisos: {mesa or 'não encontrada (defina area_de_trabalho no config)'}", itens)
    falhas = [t for ok, t in itens if not ok]
    if atrasado or (args.silencioso and falhas):
        _status_verificacao(mesa, agora, ultima, atrasado, falhas)
    if args.silencioso:
        return 0 if not falhas else 1
    if (PROJETO / "tests").is_dir() and not args.sem_autoteste:
        saida = StringIO()
        suite = unittest.defaultTestLoader.discover(str(PROJETO / "tests"), top_level_dir=str(PROJETO))
        res = unittest.TextTestRunner(stream=saida, verbosity=0).run(suite)
        _linha(res.wasSuccessful(), f"autoteste com dados simulados ({res.testsRun} testes)", itens)
    for nome in ("pricelabs", "openrouter"):
        try:
            amb.chave(nome)
            _linha(True, f"chave {nome} configurada", itens)
        except ChaveAusente as e:
            _linha(False, str(e), itens)
    try:
        ids = [x["id"] for x in cfg["listings"]]
        pl = PriceLabs(amb.rede(), amb.chave("pricelabs"), cfg["pms"], ids)
        api = {x["id"]: x for x in pl.listings()}
        for item in cfg["listings"]:
            x = api.get(item["id"])
            _linha(bool(x) and x.get("push_enabled") is True,
                   f"{item['apelido']}: {'sincronização ligada' if x and x.get('push_enabled') else 'ausente ou sem sincronização'}"
                   + (f" (última: {x.get('last_date_pushed')})" if x else ""), itens)
        hoje = agora.date()
        pl.reservas(hoje.isoformat(), (hoje + timedelta(days=1)).isoformat())
        _linha(True, "leitura de reservas (chave do dono)", itens)
    except (ErroRede, ChaveAusente) as e:
        _linha(False, f"PriceLabs: {e}", itens)
    _linha(_jev_ok(cfg, amb), f"Jev ({cfg['jev']['modelo']}) respondendo", itens)
    if amb.plataforma == "win32":
        for tarefa in (agenda.TAREFA, agenda.TAREFA_LOGON):
            q = amb.comando(["schtasks", "/Query", "/TN", tarefa], capture_output=True)
            _linha(q.returncode == 0, f"tarefa agendada {tarefa}", itens)
    return 0 if all(ok for ok, _ in itens) else 1


def _jev_ok(cfg, amb) -> bool:
    from . import jev
    cj = cfg["jev"]
    perguntas = {"ok": {"type": "noul", "instructions": "Is `status` equal to ready?",
                        "criteria": {"true": "status is ready", "false": "status is not ready"}}}
    try:
        chave = amb.chave(jev.PROVEDORES[cj["provedor"]]["chave"])
        jev.perguntar(amb.rede(), cj["provedor"], chave, cj["modelo"], {"status": "ready"}, perguntas, cj["familia_modelo"])
        return True
    except (ChaveAusente, ErroRede, jev.JevInvalido) as e:
        print(f"      detalhe: {e}")
        return False


def _status_verificacao(mesa, agora, ultima, atrasado, falhas):
    if mesa is None:
        return
    quando = ultima.strftime("%d/%m %H:%M") if ultima else "nunca"
    if atrasado:
        nome = f"PRECOS ATRASADO {agora.strftime('%d-%m %Hh%M')}.txt"
        texto = (f"A automação não roda desde {quando}. Descontos ativos continuam valendo.\n"
                 "Deixe o computador em suspensão (não desligado) e rode VERIFICAR.\n")
    else:
        nome = f"PRECOS ATENCAO {agora.strftime('%d-%m %Hh%M')}.txt"
        texto = "A verificação encontrou problemas. Rode VERIFICAR para ver os detalhes.\n"
    texto += "".join(f"- {f}\n" for f in falhas)
    relatorio.trocar_status(mesa, nome, texto)


def _executor(cfg, amb, escritas, run_id):
    pasta = principal.pasta_dados(cfg)
    armazem = Armazem(pasta)
    est, _ = armazem.carregar()
    pl = PriceLabs(amb.rede(escritas), amb.chave("pricelabs"), cfg["pms"], [x["id"] for x in cfg["listings"]])
    return Executor(pl, armazem, est, run_id, amb.agora(cfg)), est, pasta


def cmd_desfazer(args, amb):
    cfg = _cfg(args)
    pasta = principal.pasta_dados(cfg)
    with Trava(pasta):
        ex, est, _ = _executor(cfg, amb, frozenset({"DELETE"}), "desfazer-" + amb.agora(cfg).strftime("%Y%m%dT%H%M"))
        ex.conciliar()
        alvos = sorted(est["dsos"].values(), key=lambda d: (d["data"], d["listing"]))
        if not alvos:
            print("Nenhum desconto do programa na conta.")
            return 0
        for d in alvos:
            if not args.confirmar:
                print(f"Seria removido: {d['listing']} {d['data']} ({d.get('status')})")
                continue
            print(f"{d['listing']} {d['data']}: {ex.apagar({**d, 'motivo': 'desfazer pedido pelo dono'}, respeitar_parar=False)}")
        if not args.confirmar:
            print("Nada foi alterado. Para remover, rode novamente com --confirmar (DESFAZER.bat já faz isso).")
        for a in ex.alertas:
            print(f"  alerta: {a}")
        print("Para valer na hora nos canais, clique em Sync Now no PriceLabs.")
    return 0


def cmd_parar(args, amb):
    pasta = principal.pasta_dados(_cfg(args))
    pasta.mkdir(parents=True, exist_ok=True)
    (pasta / "PARAR").write_text("Automação parada pelo dono.\n", encoding="utf-8")
    print("Parado. Nenhuma execução vai alterar a conta até RETOMAR. Descontos já gravados continuam: use DESFAZER.")
    return 0


def cmd_retomar(args, amb):
    cfg = _cfg(args)
    pasta = principal.pasta_dados(cfg)
    (pasta / "PARAR").unlink(missing_ok=True)
    with Trava(pasta):
        armazem = Armazem(pasta)
        est, _ = armazem.carregar()
        est["disjuntor"] = {"ativo": False, "motivo": "", "desde": ""}
        est["saude"]["erros_escrita_seguidos"] = 0
        armazem.registrar_escrita({"ts": amb.agora(cfg).isoformat(), "run_id": "retomar", "evento": "retomada"})
        armazem.salvar(est)
    print(f"Retomado. Modo no config: {cfg['modo']}.")
    return 0


def cmd_modo(args, amb):
    dados = json.loads(Path(args.config).read_text(encoding="utf-8"))
    dados["modo"] = args.novo
    if args.teto_dia is not None:
        dados["desconto"]["teto_dia"] = args.teto_dia
    config.validar(dados)
    Path(args.config).write_text(json.dumps(dados, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Modo alterado para {args.novo}" + (f", até {args.teto_dia} desconto(s) por dia." if args.teto_dia is not None else "."))
    return 0


def cmd_configurar_chaves(args, amb):
    for nome, texto in (("pricelabs", "Chave da API do PriceLabs (a do dono da conta)"),
                        ("openrouter", "Chave do OpenRouter (para o Jev gratuito)")):
        valor = getpass.getpass(f"{texto}, ou Enter para manter a atual: ").strip()
        if valor:
            credenciais.salvar(nome, valor, amb.cofre)
            print(f"  {nome}: guardada no Gerenciador de Credenciais do Windows.")
    return 0


def cmd_testar_gravacao(args, amb):
    cfg = _cfg(args)
    listing = args.listing or next((x["id"] for x in cfg["listings"] if x["desconto_permitido"]), None)
    item = next((x for x in cfg["listings"] if x["id"] == listing), None)
    if item is None or not args.confirmar:
        print("Informe --listing (um id do config) e --confirmar. Nada foi alterado.")
        return 2
    if not args.data:
        args.data = input(f"Data livre de {item['apelido']}, sem substituição (AAAA-MM-DD): ").strip()
    if tempo.ler_data(args.data) is None or len(args.data) != 10:
        print("Data inválida. Use o formato AAAA-MM-DD. Nada foi alterado.")
        return 2
    if item["papel"] != "sem_tabela":
        print("O ensaio só é permitido em quarto sem tabela de ocupação (ex.: Afrodite). Nada foi alterado.")
        return 2
    pasta = principal.pasta_dados(cfg)
    if (pasta / "PARAR").exists():
        print("Arquivo PARAR presente. Rode RETOMAR antes do ensaio. Nada foi alterado.")
        return 2
    with Trava(pasta):
        run_id = "teste-" + amb.agora(cfg).strftime("%Y%m%dT%H%M")
        ex, est, _ = _executor(cfg, amb, frozenset({"POST", "DELETE"}), run_id)
        if est["disjuntor"]["ativo"]:
            print(f"Disjuntor ativo ({est['disjuntor']['motivo']}). Rode RETOMAR antes do ensaio. Nada foi alterado.")
            return 2
        contadores = dict(est["contadores"])
        antes = {x["id"]: x for x in ex.pl.listings()}.get(item["id"], {}).get("last_date_pushed")
        acao = {"listing": item["id"], "data": args.data, "payload": {"date": args.data, "price": "0",
                "price_type": "percent", "reason": f"auto-jev {run_id}"}}
        print("Gravar +0%:", ex.criar(acao))
        dso = est["dsos"].get(f"{item['id']}|{args.data}")
        if dso:
            print("  lido de volta:", json.dumps(dso.get("lido"), ensure_ascii=False))
        if args.manter:
            print("Mantido na conta (+0%, preço igual). Agora teste o DESFAZER.bat, que deve removê-lo.")
        else:
            print("Remover:", ex.apagar(acao, respeitar_parar=False))
        est["descontadas"].pop(f"{item['id']}|{args.data}", None)
        est["contadores"] = contadores  # o ensaio não gasta o teto diário
        ex.armazem.salvar(est)
        depois = {x["id"]: x for x in ex.pl.listings()}.get(item["id"], {}).get("last_date_pushed")
        print(f"last_date_pushed antes: {antes} | depois: {depois}")
    return 0


def cmd_agendar(args, amb):
    python = Path(sys.executable)
    if amb.plataforma != "win32":
        print("Fora do Windows, use estas linhas no crontab (horário local do computador):")
        print("\n".join(agenda.linhas_cron(str(PROJETO), str(python))))
        return 0
    pythonw = python.with_name("pythonw.exe")
    pythonw = str(pythonw) if pythonw.exists() else "pyw.exe"
    usuario = "\\".join(filter(None, (os.environ.get("USERDOMAIN"), os.environ.get("USERNAME") or getpass.getuser())))
    pasta = Path(tempfile.mkdtemp())
    falhou = False
    for nome, xml in ((agenda.TAREFA, agenda.xml_execucoes(str(PROJETO), pythonw, usuario=usuario)),
                      (agenda.TAREFA_LOGON, agenda.xml_logon(str(PROJETO), pythonw, usuario=usuario))):
        arq = pasta / f"{nome}.xml"
        arq.write_text(xml, encoding="utf-16")
        r = amb.comando(["schtasks", "/Create", "/TN", nome, "/XML", str(arq), "/F"], capture_output=True, text=True)
        falhou = falhou or r.returncode != 0
        print(f"{nome}: {'criada' if r.returncode == 0 else 'FALHA ' + (r.stderr or r.stdout or '').strip()}")
    return 1 if falhou else 0


def main(argv=None, amb=None) -> int:
    amb = amb or principal.Ambiente()
    p = argparse.ArgumentParser(prog="python -m pacing", description="Automação de preços PriceLabs + Jev")
    p.add_argument("--config", default=str(CONFIG))
    sub = p.add_subparsers(dest="comando", required=True)
    sub.add_parser("executar")
    v = sub.add_parser("verificar")
    v.add_argument("--silencioso", action="store_true")
    v.add_argument("--sem-autoteste", action="store_true", help=argparse.SUPPRESS)
    d = sub.add_parser("desfazer")
    d.add_argument("--confirmar", action="store_true")
    sub.add_parser("parar")
    sub.add_parser("retomar")
    m = sub.add_parser("modo")
    m.add_argument("novo", choices=["observar", "ativo"])
    m.add_argument("--teto-dia", type=int, choices=range(0, 7), metavar="0-6")
    sub.add_parser("configurar-chaves")
    t = sub.add_parser("testar-gravacao")
    t.add_argument("--listing", help="padrão: o primeiro quarto com desconto permitido")
    t.add_argument("--data", help="padrão: pergunta na tela")
    t.add_argument("--confirmar", action="store_true")
    t.add_argument("--manter", action="store_true", help="deixa o +0%% na conta para testar o DESFAZER")
    sub.add_parser("agendar")
    args = p.parse_args(argv)
    comandos = {"executar": cmd_executar, "verificar": cmd_verificar, "desfazer": cmd_desfazer, "parar": cmd_parar,
                "retomar": cmd_retomar, "modo": cmd_modo, "configurar-chaves": cmd_configurar_chaves,
                "testar-gravacao": cmd_testar_gravacao, "agendar": cmd_agendar}
    try:
        return comandos[args.comando](args, amb)
    except (config.ConfigInvalida, ChaveAusente) as e:
        print(f"ERRO: {e}")
        if isinstance(e, config.ConfigInvalida) and args.comando in ("executar", "verificar"):
            _avisar_config_invalida(e, amb)
        return 2


def _avisar_config_invalida(erro, amb):
    """Com pythonw ninguém vê a tela: o erro de config vai para a Área de Trabalho."""
    mesa = amb.mesa({})
    if mesa is None:
        return
    agora = tempo.agora(-3) if amb.relogio is None else amb.relogio()
    relatorio.trocar_status(mesa, f"PRECOS ERRO {agora.strftime('%d-%m %Hh%M')}.txt",
                            f"O config.json tem um erro e nada foi executado:\n{erro}\n"
                            "Desfaça a última edição do config.json ou rode OBSERVAR.bat / ATIVAR.bat.\n")


if __name__ == "__main__":
    sys.exit(main())

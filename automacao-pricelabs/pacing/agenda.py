"""Agendamento: XML do Agendador de Tarefas do Windows e linhas de cron."""

from __future__ import annotations

from xml.sax.saxutils import escape

HORARIOS = ("05:30", "08:30", "11:30", "14:30", "17:30", "20:30", "23:30")
TAREFA = "RecantoPrecos"
TAREFA_LOGON = "RecantoPrecos-Verificar"

_CABECALHO = '<?xml version="1.0" encoding="UTF-16"?>\n<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">\n'
_CONFIG = """  <Principals><Principal id="Author">{usuario}<LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>{rede}</RunOnlyIfNetworkAvailable>
    <WakeToRun>true</WakeToRun>
    <ExecutionTimeLimit>PT20M</ExecutionTimeLimit>
    <Enabled>true</Enabled>
  </Settings>
"""


def _acao(pythonw: str, argumentos: str, pasta: str) -> str:
    return (f'  <Actions Context="Author"><Exec><Command>{escape(pythonw)}</Command>'
            f"<Arguments>{escape(argumentos)}</Arguments><WorkingDirectory>{escape(pasta)}</WorkingDirectory>"
            "</Exec></Actions>\n</Task>\n")


def _usuario(usuario: str) -> str:
    return f"<UserId>{escape(usuario)}</UserId>" if usuario else ""


def xml_execucoes(pasta_projeto: str, pythonw: str, horarios=HORARIOS, inicio: str = "2026-09-25", usuario: str = "") -> str:
    gatilhos = "".join(
        f"    <CalendarTrigger><StartBoundary>{inicio}T{h}:00</StartBoundary><Enabled>true</Enabled>"
        "<ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay></CalendarTrigger>\n" for h in horarios)
    return (_CABECALHO + "  <RegistrationInfo><Description>Automação de preços PriceLabs + Jev, 7 vezes por dia"
            "</Description></RegistrationInfo>\n  <Triggers>\n" + gatilhos + "  </Triggers>\n"
            + _CONFIG.format(rede="true", usuario=_usuario(usuario)) + _acao(pythonw, "-m pacing executar", pasta_projeto))


def xml_logon(pasta_projeto: str, pythonw: str, usuario: str = "") -> str:
    """Gatilho de logon só do usuário atual: registrar para todos exigiria administrador."""
    return (_CABECALHO + "  <RegistrationInfo><Description>Confere execuções perdidas ao entrar no Windows"
            "</Description></RegistrationInfo>\n  <Triggers>\n    <LogonTrigger><Enabled>true</Enabled>"
            + _usuario(usuario) + "<Delay>PT2M</Delay></LogonTrigger>\n  </Triggers>\n"
            + _CONFIG.format(rede="false", usuario=_usuario(usuario))
            + _acao(pythonw, "-m pacing verificar --silencioso", pasta_projeto))


def linhas_cron(pasta_projeto: str, python: str, horarios=HORARIOS) -> list[str]:
    linhas = []
    for h in horarios:
        hh, mm = h.split(":")
        linhas.append(f"{int(mm)} {int(hh)} * * * cd '{pasta_projeto}' && '{python}' -m pacing executar >/dev/null 2>&1")
    return linhas

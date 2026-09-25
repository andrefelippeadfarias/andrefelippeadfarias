"""Relatório local em português, montado por modelo fixo (sem texto de IA).

Gera relatorio.html na pasta de dados, um arquivo de status com a hora no
nome na Área de Trabalho e ATENCAO-PRECOS.txt quando algo precisa do dono.
Nunca inclui chaves nem dados de hóspedes.
"""

from __future__ import annotations

import html
import os
from pathlib import Path

CORES = {"verde": "#1b7f3b", "amarelo": "#b7791f", "vermelho": "#c53030"}
ROTULO = {"verde": "OK", "amarelo": "ATENCAO", "vermelho": "ERRO"}
NOTA_META = ("A automação só pode descontar quartos sem tabela de ocupação e sem pendência. Hoje isso é a "
             "Afrodite, que já costuma bater a meta; o desconto dela será raro. O que move a meta de 70% é a "
             "tabela de ocupação nos quartos de 6 e 7 unidades, o passo 2 do preço base e a solução das "
             "pendências da Queen Spa (2) e da Villa King Spa (2).")


def area_de_trabalho(cfg: dict) -> Path | None:
    if cfg.get("area_de_trabalho"):
        return Path(cfg["area_de_trabalho"])
    for base in (os.environ.get("USERPROFILE"), str(Path.home())):
        if base:
            for nome in ("Desktop", "Área de Trabalho", os.path.join("OneDrive", "Desktop")):
                p = Path(base) / nome
                if p.is_dir():
                    return p
    return None


def _e(v) -> str:
    return html.escape(str(v), quote=True)


def _pct(v) -> str:
    return "sem dado" if v is None else f"{v:.0f}%"


def montar_html(r: dict, cfg: dict) -> str:
    cor = CORES[r["status"]]
    linhas_ocup = "".join(
        f"<tr><td>{_e(o['apelido'])}</td><td>{_e(o['papel'])}</td>"
        + "".join(f"<td class='{ 'ok' if (o[f] is not None and o[f] >= cfg['metas'][f]) else 'baixo'}'>{_pct(o[f])}</td>"
                  for f in ("0-6", "7-14", "15-29"))
        + "</tr>" for o in r["ocupacao"])
    linhas_dec = "".join(
        f"<tr><td>{_e(d['quarto'])}</td><td>{_e(d['bloco'])}</td><td>{_e(d['escolha'])}</td>"
        f"<td>{d['confianca']:.2f}</td><td>{d['veto']:.2f}</td><td>{_e(d['resultado'])}</td></tr>"
        for d in r["decisoes"]) or "<tr><td colspan='6'>Nenhuma pergunta ao Jev nesta execução.</td></tr>"
    linhas_acoes = "".join(f"<li>{_e(a)}</li>" for a in r["acoes"]) or "<li>Nenhuma alteração na conta.</li>"
    linhas_ativas = "".join(f"<li>{_e(a)}</li>" for a in r["ativas"]) or "<li>Nenhum desconto do programa ativo.</li>"
    linhas_alertas = "".join(f"<li>{_e(a)}</li>" for a in r["alertas"]) or "<li>Nenhum alerta.</li>"
    linhas_bloq = "".join(f"<li>{_e(b['alvo'])}: {_e(b['regra'])}</li>" for b in r["bloqueios"]) or "<li>Nenhum.</li>"
    saude = r["saude"]
    return f"""<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preços automáticos</title>
<style>
body{{font-family:Segoe UI,Arial,sans-serif;margin:16px;color:#1a202c;background:#fff;max-width:960px}}
.faixa{{background:{cor};color:#fff;padding:12px 16px;border-radius:6px;font-size:1.2em}}
table{{border-collapse:collapse;width:100%;margin:8px 0}}td,th{{border:1px solid #cbd5e0;padding:4px 8px;text-align:left}}
.ok{{background:#e6ffed}}.baixo{{background:#fff5f5}}h2{{margin-top:24px;font-size:1.05em}}
.nota{{color:#4a5568;font-size:.95em}}
</style></head><body>
<div class="faixa">{_e(ROTULO[r['status']])}: {_e(r['resumo'])}</div>
<p>Execução {_e(r['run_id'])} às {_e(r['hora'])}. Modo: <b>{_e(r['modo'])}</b>. Próxima sincronização com o Beds24: {_e(r['proxima_sync'])}.</p>
<h2>Alertas</h2><ul>{linhas_alertas}</ul>
<h2>Ocupação contra as metas (0 a 6 dias: {cfg['metas']['0-6']}%, 7 a 14: {cfg['metas']['7-14']}%, 15 a 29: {cfg['metas']['15-29']}%)</h2>
<table><tr><th>Quarto</th><th>Papel</th><th>0 a 6 dias</th><th>7 a 14</th><th>15 a 29</th></tr>{linhas_ocup}</table>
<h2>Decisões do Jev</h2>
<table><tr><th>Quarto</th><th>Bloco</th><th>Escolha</th><th>Confiança</th><th>Veto</th><th>Resultado</th></tr>{linhas_dec}</table>
<h2>O que foi feito na conta</h2><ul>{linhas_acoes}</ul>
<h2>Descontos do programa ativos</h2><ul>{linhas_ativas}</ul>
<p class="nota">Descontos ativos continuam valendo mesmo com o computador desligado. Para retirar todos, use DESFAZER.</p>
<h2>Por que não agiu</h2><ul>{linhas_bloq}</ul>
<h2>Saúde</h2>
<ul><li>Execuções hoje: {saude['execucoes_hoje']} de 7</li>
<li>Chamadas ao Jev hoje: {saude['jev_chamadas_hoje']} (tokens de entrada: {saude['jev_tokens']})</li>
<li>Observação: {_e(saude['observacao'])}</li></ul>
<p class="nota">{_e(NOTA_META)}</p>
</body></html>
"""


def publicar(r: dict, cfg: dict, pasta: Path) -> None:
    (Path(pasta) / "relatorio.html").write_text(montar_html(r, cfg), encoding="utf-8")
    mesa = area_de_trabalho(cfg)
    if mesa is None:
        return
    for antigo in mesa.glob("PRECOS * *.txt"):
        antigo.unlink(missing_ok=True)
    rotulo = ROTULO[r["status"]]
    nome = f"PRECOS {rotulo} {r['hora_curta']}.txt"
    texto = [f"{rotulo}: {r['resumo']}", f"Relatório completo: {Path(pasta) / 'relatorio.html'}", ""]
    texto += [f"- {a}" for a in r["alertas"][:15]]
    (mesa / nome).write_text("\n".join(texto) + "\n", encoding="utf-8")
    atencao = mesa / "ATENCAO-PRECOS.txt"
    if r["precisa_atencao"]:
        atencao.write_text("A automação de preços precisa de você.\n" + "\n".join(texto) + "\n", encoding="utf-8")
    elif r["status"] == "verde":
        atencao.unlink(missing_ok=True)

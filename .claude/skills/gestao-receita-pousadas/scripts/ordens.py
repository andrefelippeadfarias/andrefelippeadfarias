"""Ordens literais do dono (dados/ordens_dono.json): quem libera exceção no validador e quem congela uma noite.

Criado em 30/10/2026. Antes, os scripts carimbavam `"excecao": "dono"` sozinhos (escada, Regra J, ordem do Recanto) e a
trava "preço fixo só com ordem expressa do dono" não era verificável. Agora todo item com excecao "dono" leva `ordem_id`;
o validador confere se a ordem existe, cobre o quarto e a noite e ainda vale. Ordem vencida = item barrado.

Uso:
  python3 ordens.py listar [--hoje AAAA-MM-DD]
  python3 ordens.py manual --quarto Q7 --de AAAA-MM-DD --ate AAAA-MM-DD --texto "..." [--dias-congelados 7]
      (registra uma mudança feita pela tela do PriceLabs; a noite fica congelada para os scripts por 7 dias)
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from comum import PASTA_SKILL, data, hoje_brt  # noqa: E402

ARQ_ORDENS = PASTA_SKILL / "dados" / "ordens_dono.json"


def carregar_ordens(caminho: Path | str | None = None) -> dict:
    """{id: ordem}. Sem arquivo, dicionário vazio (o validador então barra toda excecao 'dono')."""
    p = Path(caminho or ARQ_ORDENS)
    if not p.exists():
        return {}
    with open(p, encoding="utf-8") as f:
        d = json.load(f)
    return {o["id"]: o for o in d.get("ordens", [])}


def cobre(ordem: dict, quarto: str, d: date) -> bool:
    quartos = ordem.get("quartos") or []
    if "*" not in quartos and quarto not in quartos:
        return False
    return data(ordem["de"]) <= d <= data(ordem["ate"])


def ordem_valida(ordens: dict, ordem_id: str | None, quarto: str, d: date) -> dict | None:
    """A ordem, se existe e cobre o quarto e a noite; senão None."""
    o = ordens.get(ordem_id or "")
    return o if o and cobre(o, quarto, d) else None


def congelada(ordens: dict, quarto: str, d: date) -> dict | None:
    """Noite com mudança manual do dono pela tela ainda vigente: nenhum script mexe nela (SKILL.md: 'respeite e registre')."""
    for o in ordens.values():
        if o.get("tipo") == "manual" and cobre(o, quarto, d):
            return o
    return None


def ordens_por_data(ordens: dict, quarto: str, d: date) -> list[dict]:
    """Ordens de preço por data do dono (6b) que cobrem a noite: o motor de pace não mexe nessas noites."""
    return [o for o in ordens.values() if o.get("tipo") == "preco_por_data" and cobre(o, quarto, d)]


def registrar_manual(quarto: str, de: str, ate: str, texto: str, dias_congelados: int = 7,
                     caminho: Path | str | None = None, hoje: date | None = None) -> dict:
    p = Path(caminho or ARQ_ORDENS)
    with open(p, encoding="utf-8") as f:
        d = json.load(f)
    hoje = hoje or hoje_brt()
    oid = f"manual-{hoje.isoformat()}-{quarto.lower()}-{de}"
    fim = min(data(ate), hoje + timedelta(days=dias_congelados))
    o = {"id": oid, "data": hoje.isoformat(), "texto": texto, "quartos": [quarto], "de": de,
         "ate": fim.isoformat(), "tipo": "manual", "libera": []}
    d["ordens"] = [x for x in d.get("ordens", []) if x["id"] != oid] + [o]
    with open(p, "w", encoding="utf-8") as f:
        json.dump(d, f, ensure_ascii=False, indent=1)
        f.write("\n")
    return o


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    ls = sub.add_parser("listar")
    ls.add_argument("--hoje")
    mn = sub.add_parser("manual")
    mn.add_argument("--quarto", required=True)
    mn.add_argument("--de", required=True)
    mn.add_argument("--ate", required=True)
    mn.add_argument("--texto", required=True)
    mn.add_argument("--dias-congelados", type=int, default=7)
    a = ap.parse_args(argv)
    if a.cmd == "listar":
        hoje = data(a.hoje) if a.hoje else hoje_brt()
        for o in carregar_ordens().values():
            vig = "vigente" if data(o["de"]) <= hoje <= data(o["ate"]) else ("futura" if data(o["de"]) > hoje else "vencida")
            print(f"{o['id']:45} {o.get('tipo', ''):15} {o['de']}→{o['ate']} {vig:8} {','.join(o.get('quartos') or [])}")
        return 0
    o = registrar_manual(a.quarto, a.de, a.ate, a.texto, a.dias_congelados)
    print(json.dumps(o, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())

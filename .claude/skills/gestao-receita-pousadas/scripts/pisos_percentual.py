#!/usr/bin/env python3
"""Converte pisos e tetos por data gravados em reais ("fixed") para variação % sobre a base ("percent_base").

Regra do dono (14/10): "Trabalhe sempre com percentuais nunca com valores absolutos no pricelabs."
Convenção medida em 14/10: percent_base é VARIAÇÃO sobre a base (-39 = base x 0,61), igual ao price_type "percent".

Uso:
  gerar    <antes.json> <QUARTO> [--saida payload.json] [--lote 40]
           antes.json = resposta do get_listing_date_overrides (ou só a lista "overrides").
           Imprime a tabela (data, piso em reais -> %, valor efetivo) e grava os lotes de overrides para
           update_listing_date_overrides. Só entram as datas com min_price_type ou max_price_type "fixed".
           Manda apenas date + piso/teto (+ currency): a gravação junta campos, então preço, estadia e motivo ficam.
  conferir <antes.json> <depois.json> <QUARTO>
           Compara a leitura de antes com a de depois: nenhum "fixed" sobrou, cada % bate com o piso/teto em reais,
           o valor efetivo nunca fica abaixo do piso antigo (nem mais de 1% da base acima), preço, estadia e motivo
           não mudaram, nenhuma data sumiu. Sai com código 1 se achar erro.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from comum import carregar_quartos  # noqa: E402
from validar_plano import em_reais, piso_pct, teto_pct  # noqa: E402

CAMPOS_FIXOS = ("price", "price_type", "min_stay", "reason", "check_in", "check_out", "check_in_check_out_enabled",
                "lead_time_expiry", "base_price")


def ler_overrides(caminho: str) -> list[dict]:
    d = json.loads(Path(caminho).read_text(encoding="utf-8"))
    if isinstance(d, list):
        return d
    if "overrides" in d:
        return d["overrides"]
    if "data" in d and isinstance(d["data"], dict) and "overrides" in d["data"]:
        return d["data"]["overrides"]
    raise SystemExit(f"{caminho}: não achei a lista de overrides")


def _num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def converter(q: dict, o: dict) -> tuple[dict | None, dict]:
    """Devolve (override para gravar ou None, linha da tabela)."""
    novo = {"date": o["date"]}
    linha = {"data": o["date"]}
    pmin = pmax = None
    vmin = _num(o.get("min_price")) if str(o.get("min_price_type") or "") == "fixed" else None
    vmax = _num(o.get("max_price")) if str(o.get("max_price_type") or "") == "fixed" else None
    if vmin is not None:
        pmin = piso_pct(q, vmin)
        novo.update(min_price=str(pmin), min_price_type="percent_base", currency="BRL")
        linha.update(piso_reais=vmin, piso_pct=pmin, piso_efetivo=em_reais(q, pmin))
    elif str(o.get("min_price_type") or "") == "percent_base" and _num(o.get("min_price")) is not None:
        pmin = int(_num(o.get("min_price")))  # já convertido: serve de piso para o teto
    if vmax is not None:
        pmax = teto_pct(q, vmax)
        if pmin is not None and pmax < pmin:
            pmax = pmin
        novo.update(max_price=str(pmax), max_price_type="percent_base", currency="BRL")
        linha.update(teto_reais=vmax, teto_pct=pmax, teto_efetivo=em_reais(q, pmax))
    if vmin is None and vmax is None:
        return None, linha
    return novo, linha


def gerar(args) -> int:
    cfg = carregar_quartos()
    q = cfg["por_curto"][args.quarto]
    antes = ler_overrides(args.antes)
    overrides, tabela = [], []
    for o in antes:
        novo, linha = converter(q, o)
        if novo:
            overrides.append(novo)
            tabela.append(linha)
    lotes = [overrides[i:i + args.lote] for i in range(0, len(overrides), args.lote)]
    print(f"{args.quarto} ({q['id']}, base R$ {q['base']}): {len(antes)} regras lidas, {len(overrides)} para converter, "
          f"{len(lotes)} lote(s) de até {args.lote}")
    for l in tabela:
        partes = [l["data"]]
        if "piso_pct" in l:
            partes.append(f"piso R$ {l['piso_reais']:.0f} -> {l['piso_pct']:+d}% = R$ {l['piso_efetivo']:.0f}")
        if "teto_pct" in l:
            partes.append(f"teto R$ {l['teto_reais']:.0f} -> {l['teto_pct']:+d}% = R$ {l['teto_efetivo']:.0f}")
        print("  " + " | ".join(partes))
    saida = {"listing_id": q["id"], "pms": cfg["pms"], "lotes": lotes, "tabela": tabela}
    Path(args.saida).write_text(json.dumps(saida, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"payload gravado em {args.saida}")
    return 0


def conferir(args) -> int:
    cfg = carregar_quartos()
    q = cfg["por_curto"][args.quarto]
    antes = {o["date"]: o for o in ler_overrides(args.antes)}
    depois = {o["date"]: o for o in ler_overrides(args.depois)}
    erros, convertidas = [], 0
    tol = max(1.0, 0.01 * float(q["base"]) + 1)
    for d, o in antes.items():
        n = depois.get(d)
        if n is None:
            erros.append(f"{d}: regra sumiu depois da gravação")
            continue
        for c in CAMPOS_FIXOS:
            if str(o.get(c) if o.get(c) is not None else "") != str(n.get(c) if n.get(c) is not None else ""):
                erros.append(f"{d}: campo {c} mudou ({o.get(c)!r} -> {n.get(c)!r})")
        for campo, tipo, f in (("min_price", "min_price_type", piso_pct), ("max_price", "max_price_type", teto_pct)):
            v_antes, t_antes = _num(o.get(campo)), str(o.get(tipo) or "")
            v_dep, t_dep = _num(n.get(campo)), str(n.get(tipo) or "")
            if t_dep == "fixed":
                erros.append(f"{d}: {campo} continua fixed ({v_dep})")
            if t_antes == "fixed" and v_antes is not None:
                if t_dep != "percent_base" or v_dep is None:
                    erros.append(f"{d}: {campo} não virou percent_base ({t_dep} {v_dep})")
                    continue
                esperado = f(q, v_antes)
                if campo == "max_price":
                    pm = _num(n.get("min_price")) if str(n.get("min_price_type") or "") == "percent_base" else None
                    if pm is not None and esperado < pm:
                        esperado = int(pm)
                if int(v_dep) != esperado:
                    erros.append(f"{d}: {campo} {v_dep:+.0f}% difere do esperado {esperado:+d}% (R$ {v_antes:.0f})")
                ef = em_reais(q, v_dep)
                if campo == "min_price" and (ef < v_antes - 0.5 or ef > v_antes + tol):
                    erros.append(f"{d}: piso efetivo R$ {ef:.0f} fora da faixa do piso antigo R$ {v_antes:.0f}")
                if campo == "max_price" and ef > v_antes + 0.5 and int(v_dep) != int(_num(n.get("min_price")) or -999):
                    erros.append(f"{d}: teto efetivo R$ {ef:.0f} acima do teto antigo R$ {v_antes:.0f}")
                convertidas += 1
            elif t_antes == t_dep and str(v_antes) != str(v_dep):
                erros.append(f"{d}: {campo} mudou sem ser conversão ({v_antes} -> {v_dep})")
    for d in depois:
        if d not in antes:
            erros.append(f"{d}: regra nova que não existia antes")
    fixos = sum(1 for n in depois.values() if "fixed" in (str(n.get("min_price_type")), str(n.get("max_price_type"))))
    print(f"{args.quarto}: {len(antes)} antes, {len(depois)} depois, {convertidas} campos convertidos, "
          f"{fixos} regra(s) ainda com fixed, {len(erros)} erro(s)")
    for e in erros:
        print("  ERRO " + e)
    return 1 if erros else 0


def main(argv=None) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    g = sub.add_parser("gerar")
    g.add_argument("antes")
    g.add_argument("quarto")
    g.add_argument("--saida", default="payload_pisos.json")
    g.add_argument("--lote", type=int, default=40)
    g.set_defaults(fn=gerar)
    c = sub.add_parser("conferir")
    c.add_argument("antes")
    c.add_argument("depois")
    c.add_argument("quarto")
    c.set_defaults(fn=conferir)
    a = p.parse_args(argv)
    return a.fn(a)


if __name__ == "__main__":
    sys.exit(main())

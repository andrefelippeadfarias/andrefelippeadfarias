"""Payload do PriceLabs fatiado para a gravação segura (revisão de 05/11, estadia pelo dia de chegada nos 12 meses).

Uso:
  python3 fatiar_payload.py --payload payload.json --hoje AAAA-MM-DD --saida PASTA [--max-datas 70]
  (ou direto: python3 validar_plano.py plano.json --fatias PASTA [--max-datas 70], que valida antes)

O plano de 12 meses gera centenas de datas por quarto; uma chamada só de update_listing_date_overrides com tudo é
arriscada (uma data recusada derruba o lote; o envio da tarde pode pegar o lote pela metade). Aqui cada chamada leva até
`--max-datas` (70) datas de UM quarto, e as datas de D0 e de D1 vão em chamadas próprias (a de D0 pode virar passado se a
gravação passar da meia-noite, e não pode levar o resto junto). Ordem de gravação: apagar (delete_listing_date_overrides)
antes de tudo, como o plano `_apagar` do pace; depois D0 de todos os quartos, D1 de todos os quartos e o resto, quarto a
quarto, em ordem de data; update_listing_data por último. Cada fatia vira um arquivo com os argumentos da chamada
({listing_id, pms, overrides}) e `fatias.json` traz a lista com quarto, rótulo, primeira e última data e quantidade.
Só biblioteca padrão; nada aqui chama rede.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from datetime import date, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from comum import carregar_quartos, data  # noqa: E402

MAX_DATAS = 70


def fatiar(cfg: dict, payload: dict, hoje: date, max_datas: int = MAX_DATAS) -> list[dict]:
    """Lista de fatias na ordem de gravação: {"ferramenta", "quarto", "rotulo", "de", "ate", "datas", "args"}."""
    if max_datas < 1:
        raise ValueError("max_datas precisa ser pelo menos 1")
    d0, d1 = hoje.isoformat(), (hoje + timedelta(days=1)).isoformat()
    por_id = cfg.get("por_id") or {q["id"]: q for q in cfg.get("quartos", [])}

    def nova(ferr, chamada, ovs, rotulo):
        datas = [o["date"] for o in ovs]
        return {"ferramenta": ferr, "quarto": (por_id.get(chamada["listing_id"]) or {}).get("curto", chamada["listing_id"]),
                "rotulo": rotulo, "de": min(datas), "ate": max(datas), "datas": len(ovs),
                "args": {"listing_id": chamada["listing_id"], "pms": chamada["pms"], "overrides": ovs}}

    apagar, urg0, urg1, resto = [], [], [], []
    for ferr in ("delete_listing_date_overrides", "update_listing_date_overrides"):
        for chamada in payload.get(ferr) or []:
            ovs = sorted(chamada.get("overrides") or [], key=lambda o: o["date"])
            if not ovs:
                continue
            datas = [o["date"] for o in ovs]
            if len(set(datas)) != len(datas):
                raise ValueError(f"datas repetidas em {ferr} de {chamada['listing_id']}: a API mescla e o último vence")
            destino = apagar if ferr.startswith("delete") else None
            grupos = [("D0", [o for o in ovs if o["date"] == d0]), ("D1", [o for o in ovs if o["date"] == d1])]
            outros = [o for o in ovs if o["date"] not in (d0, d1)]
            n = (len(outros) + max_datas - 1) // max_datas
            grupos += [(f"{k + 1}/{n}", outros[k * max_datas:(k + 1) * max_datas]) for k in range(n)]
            for rotulo, g in grupos:
                if not g:
                    continue
                for k in range(0, len(g), max_datas):  # D0/D1 nunca passam de 1 data por quarto; o corte é por segurança
                    f = nova(ferr, chamada, g[k:k + max_datas], rotulo)
                    if destino is not None:
                        destino.append(f)
                    elif rotulo == "D0":
                        urg0.append(f)
                    elif rotulo == "D1":
                        urg1.append(f)
                    else:
                        resto.append(f)
    fatias = apagar + urg0 + urg1 + resto
    for chamada in payload.get("update_listing_data") or []:
        fatias.append({"ferramenta": "update_listing_data",
                       "quarto": (por_id.get(chamada.get("listing_id")) or {}).get("curto", chamada.get("listing_id")),
                       "rotulo": "anúncio", "de": None, "ate": None, "datas": 0, "args": dict(chamada)})
    for i, f in enumerate(fatias, 1):
        f["n"] = i
    return fatias


def gravar_fatias(fatias: list[dict], pasta: str) -> list[str]:
    """Grava cada fatia em PASTA/NN_<ferramenta>_<quarto>_<rótulo>.json (os argumentos da chamada) e PASTA/fatias.json
    (a lista). Devolve as linhas do resumo."""
    os.makedirs(pasta, exist_ok=True)
    for velho in os.listdir(pasta):  # verificação de 05/11: fatia de rodada anterior não pode sobrar para ser gravada
        if re.match(r"^\d{3}_.*\.json$", velho) or velho == "fatias.json":
            os.remove(os.path.join(pasta, velho))
    linhas, idx = [], []
    for f in fatias:
        rot = f["rotulo"].replace("/", "de")
        arq = os.path.join(pasta, f"{f['n']:03d}_{f['ferramenta']}_{f['quarto']}_{rot}.json")
        with open(arq, "w", encoding="utf-8") as fh:
            json.dump(f["args"], fh, ensure_ascii=False, indent=1)
        idx.append({k: f[k] for k in ("n", "ferramenta", "quarto", "rotulo", "de", "ate", "datas")} | {"arquivo": arq})
        linhas.append(f"{f['n']:3d} {f['ferramenta']:30} {str(f['quarto']):9} {f['rotulo']:6} "
                      f"{f['de'] or '':10} a {f['ate'] or '':10} {f['datas']:3d} datas  {arq}")
    with open(os.path.join(pasta, "fatias.json"), "w", encoding="utf-8") as fh:
        json.dump(idx, fh, ensure_ascii=False, indent=1)
    linhas.append(f"{len(fatias)} chamadas, {sum(f['datas'] for f in fatias)} datas, em {pasta} (lista em fatias.json)")
    return linhas


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--payload", required=True, help="saída de validar_plano.py --payload (o JSON depois de 'OK para gravar.')")
    ap.add_argument("--hoje", required=True)
    ap.add_argument("--saida", required=True)
    ap.add_argument("--max-datas", type=int, default=MAX_DATAS)
    a = ap.parse_args(argv)
    texto = open(a.payload, encoding="utf-8").read()
    if "OK para gravar." in texto:
        texto = texto[texto.index("OK para gravar.") + len("OK para gravar."):]
    if "{" not in texto:
        print("ERRO: sem payload no arquivo (rode validar_plano.py --payload e guarde a saída)")
        return 1
    payload, _ = json.JSONDecoder().raw_decode(texto[texto.find("{"):])
    for linha in gravar_fatias(fatiar(carregar_quartos(), payload, data(a.hoje), a.max_datas), a.saida):
        print(linha)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

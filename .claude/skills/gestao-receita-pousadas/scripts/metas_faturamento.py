"""Metas e previsão de faturamento (out/2026 a abr/2027) por mês, temporada e feriado, em três cenários.

Faturamento = "revenue" do PriceLabs = valor das diárias da reserva no Beds24 (campo rental_revenue), antes da comissão
da Booking/Airbnb e sem extras. É o número do Report Builder "Revenue On The Books" por mês de estadia.

Método (revisado por 3 revisores independentes em 14/10/2026):
- Mês: RevPAR do mercado (vizinhança Airbnb do PriceLabs) no mesmo mês do ano passado × crescimento × nosso índice
  × unidades × dias, por pousada; nunca abaixo do livro. Índice = nosso RevPAR ÷ RevPAR do mercado no MESMO mês do
  MESMO ano (set/2026: Recanto 1,48, Villa 1,25; portfólio 1 a 14/10: 1,25). Teto de 95% de ocupação: desconto nos
  meses com pico no Recanto. Páscoa: 2027 cai em março (ano passado, abril): março recebe o efeito líquido da Páscoa
  da tabela de feriados e abril perde o pouco que a Páscoa de 2026 pôs no mercado de abril.
- Outubro: livro do mês inteiro (1 a 31/10, lido em 14/10) + última hora (setembro vendeu 6,6 diárias/dia com 0 a 1 dia).
- Feriado: vendido + (ocupação-alvo × diárias − vendidas) × diária real por noite (≥ piso de feriado da skill).
Uso: python3 metas_faturamento.py [--doc caminho.md] [--json caminho.json]
Para recalibrar no dia 1: atualize INSUMOS (realizado do mês fechado, livro por mês de estadia, vendido dos feriados).
"""
import argparse, calendar, json
from pathlib import Path

INSUMOS = {
    "lido_em": "2026-10-14",
    "unidades": {"recanto": 16, "villa": 15},
    # mercado (vizinhança Airbnb do PriceLabs, get_neighbourhood_data): ocupação final e ADR do mesmo mês do ano passado
    "mercado_ano_passado": {
        "2026-10": {"recanto": (28, 571), "villa": (35, 515)}, "2026-11": {"recanto": (35, 641), "villa": (42, 545)},
        "2026-12": {"recanto": (43, 734), "villa": (50, 702)}, "2027-01": {"recanto": (36, 645), "villa": (45, 583)},
        "2027-02": {"recanto": (29, 652), "villa": (34, 572)}, "2027-03": {"recanto": (27, 564), "villa": (34, 511)},
        "2027-04": {"recanto": (36, 695), "villa": (42, 648)}},
    # calibração: setembro/2026 (único mês cheio) e 1 a 14/10
    "realizado": {"2026-09": {"recanto": 182255, "villa": 142356, "total": 324612, "diarias": 511, "disponiveis": 950,
                              "ocupacao": 0.54, "adr": 635, "mercado_mesmo_mes": {"recanto": (37, 697), "villa": (40, 634)},
                              "indice": {"recanto": 1.48, "villa": 1.25}},
                  "2026-10-01a14": {"ocupacao": 0.484, "adr": 559, "revpar": 271, "mercado_revpar": 217, "indice_portfolio": 1.25}},
    # livro por mês de ESTADIA (Report Builder 118 / reservas rateadas por noite), lido em 14/10
    "livro": {"2026-10": 238221, "2026-11": 97762, "2026-12": 58598, "2027-01": 26096, "2027-02": 8239, "2027-03": 6416, "2027-04": 4370},
    "cenarios": {"piso": {"indice": {"recanto": 1.15, "villa": 1.00}, "crescimento": 1.00},
                 "base": {"indice": {"recanto": 1.35, "villa": 1.20}, "crescimento": 1.05},
                 "meta": {"indice": {"recanto": 1.70, "villa": 1.50}, "crescimento": 1.05}},
    "teto_95_recanto": {"2026-11": 0.95, "2026-12": 0.95, "2027-02": 0.94, "2027-04": 0.97},
    "outubro_ultima_hora": {"piso": 0, "base": 27000, "meta": 60000},
    "abril_sem_pascoa": {"piso": -4000, "base": -4000, "meta": -4000},
    # feriados: janela, diárias (noites × 31), vendido em 14/10 (noites, receita rateada), ocupação-alvo e diária real por cenário
    "feriados": {
        "Finados (30/10 a 1/11, 3 noites)": {"vendidas": 45, "receita": 30932, "diarias": 93, "ocupacao": (0.60, 0.72, 0.85), "diaria": (800, 900, 950), "piso_skill": 800},
        "Consciência Negra (19 a 21/11, 3 noites)": {"vendidas": 21, "receita": 18989, "diarias": 93, "ocupacao": (0.65, 0.78, 0.88), "diaria": (800, 900, 1000), "piso_skill": 800},
        "Natal (24 a 26/12, 3 noites)": {"vendidas": 2, "receita": 1614, "diarias": 93, "ocupacao": (0.60, 0.75, 0.85), "diaria": (1000, 1100, 1200), "piso_skill": 1000},
        "Réveillon (29/12 a 2/1, 5 noites)": {"vendidas": 23, "receita": 20959, "diarias": 155, "ocupacao": (0.70, 0.85, 0.92), "diaria": (1100, 1250, 1400), "piso_skill": 1000},
        "Aniversário de SP (22 a 24/1, 3 noites)": {"vendidas": 3, "receita": 2521, "diarias": 93, "ocupacao": (0.45, 0.60, 0.72), "diaria": (800, 850, 900), "piso_skill": 800},
        "Carnaval (5 a 9/2, 5 noites)": {"vendidas": 5, "receita": 5603, "diarias": 155, "ocupacao": (0.65, 0.75, 0.88), "diaria": (1000, 1050, 1100), "piso_skill": 1000},
        "Páscoa (25 a 27/3, 3 noites)": {"vendidas": 0, "receita": 0, "diarias": 93, "ocupacao": (0.60, 0.72, 0.85), "diaria": (1000, 1050, 1100), "piso_skill": 1000},
        "Tiradentes (20 a 21/4, 2 noites)": {"vendidas": 2, "receita": 924, "diarias": 62, "ocupacao": (0.45, 0.55, 0.65), "diaria": (800, 850, 900), "piso_skill": 800}},
    "pascoa_noites_normais_marco": {"piso": 5678, "base": 7130, "meta": 8454},  # 1 noite comum de março pela fórmula, por cenário (31 unidades)
}
# Metas do dono (14/10/2026), lidas como faturamento MENSAL por pousada: alta temporada em dezembro e janeiro (dono,
# 14/10: "Sim janeiro é alta"; no inverno, junho a agosto) e baixa nos outros meses (outubro, novembro, fevereiro, março
# e abril). A temporada do dono define a meta do mês; o nível de demanda de cada noite continua vindo do calendário
# de demanda (sazonalidade.json), que para janeiro mostra semanas de vale e fins de semana médios.
DONO = {"baixa": {"recanto": 220000, "villa": 150000}, "alta": {"recanto": 300000 + 50000, "villa": 300000},
        "meses_alta": ["2026-12", "2027-01"], "indice_maximo": {"recanto": 2.00, "villa": 1.80}}
CEN = ("piso", "base", "meta")
MESES = list(INSUMOS["mercado_ano_passado"])
NOME = {"2026-10": "Outubro (mês inteiro)", "2026-11": "Novembro", "2026-12": "Dezembro", "2027-01": "Janeiro",
        "2027-02": "Fevereiro", "2027-03": "Março", "2027-04": "Abril"}


def feriados():
    out = {}
    for nome, f in INSUMOS["feriados"].items():
        out[nome] = {}
        for i, c in enumerate(CEN):
            assert f["diaria"][i] >= f["piso_skill"], nome
            alvo = f["ocupacao"][i] * f["diarias"]
            faltam = max(0, alvo - f["vendidas"])
            out[nome][c] = round(f["receita"] + faltam * f["diaria"][i])
        out[nome]["vendido"] = {"noites": f["vendidas"], "receita": f["receita"], "diarias": f["diarias"]}
        out[nome]["ocupacao"] = f["ocupacao"]; out[nome]["diaria"] = f["diaria"]
    return out


def meses(fer):
    res = {}
    pascoa = next(v for k, v in fer.items() if k.startswith("Páscoa"))
    for m in MESES:
        y, mo = int(m[:4]), int(m[5:]); dias = calendar.monthrange(y, mo)[1]
        row = {"livro": INSUMOS["livro"][m]}
        for c in CEN:
            cen = INSUMOS["cenarios"][c]; tot = 0
            for p, un in INSUMOS["unidades"].items():
                occ, adr = INSUMOS["mercado_ano_passado"][m][p]
                v = occ / 100 * adr * cen["crescimento"] * cen["indice"][p] * un * dias
                if p == "recanto":
                    v *= INSUMOS["teto_95_recanto"].get(m, 1.0)
                tot += v
            if m == "2026-10":
                tot = INSUMOS["livro"][m] + INSUMOS["outubro_ultima_hora"][c]
            if m == "2027-03":
                tot += pascoa[c] - 3 * INSUMOS["pascoa_noites_normais_marco"][c]
            if m == "2027-04":
                tot += INSUMOS["abril_sem_pascoa"][c]
            row[c] = round(max(tot, row["livro"]))
        res[m] = row
    return res


def por_pousada():
    """Previsão por pousada (só a fórmula) e os três níveis do dono: mínimo (dono), realista (modelo a pleno) e máximo."""
    out = {}
    for m in MESES:
        y, mo = int(m[:4]), int(m[5:]); dias = calendar.monthrange(y, mo)[1]
        temp = "alta" if m in DONO["meses_alta"] else "baixa"
        out[m] = {}
        for p, un in INSUMOS["unidades"].items():
            occ, adr = INSUMOS["mercado_ano_passado"][m][p]
            teto = INSUMOS["teto_95_recanto"].get(m, 1.0) if p == "recanto" else 1.0
            base = occ / 100 * adr * 1.05 * INSUMOS["cenarios"]["base"]["indice"][p] * un * dias * teto
            realista = occ / 100 * adr * 1.05 * INSUMOS["cenarios"]["meta"]["indice"][p] * un * dias * teto
            maximo = occ / 100 * adr * 1.05 * DONO["indice_maximo"][p] * un * dias * teto
            minimo = DONO[temp][p]
            out[m][p] = {"hoje": round(base), "realista": round(realista), "maximo": round(maximo), "minimo_dono": minimo,
                         "revpar_minimo_dono": round(minimo / (un * dias)), "temporada": temp,
                         "minimo_dono_vs_realista": round(minimo / realista - 1, 2)}
    return out


def temporadas(res, fer):
    rev = next(v for k, v in fer.items() if k.startswith("Réveillon"))
    natal = next(v for k, v in fer.items() if k.startswith("Natal"))
    out = {}
    # festas de fim de ano = Natal + Réveillon + 8 noites de ombro (18 a 23 e 27 a 28/12) a 55% × R$ 650 (base)
    ombro = {"piso": round(8 * 31 * 0.45 * 600), "base": round(8 * 31 * 0.55 * 650), "meta": round(8 * 31 * 0.65 * 700)}
    # dono (14/10): dezembro e janeiro são alta temporada; as festas ficam como linha de detalhe (estão dentro da alta)
    out["Alta (sua definição): dezembro e janeiro"] = {c: res["2026-12"][c] + res["2027-01"][c] for c in CEN}
    out["dentro da alta: festas de fim de ano (18/12 a 2/1)"] = {c: natal[c] + rev[c] + ombro[c] for c in CEN}
    out["Baixa: novembro, fevereiro e março"] = {c: res["2026-11"][c] + res["2027-02"][c] + res["2027-03"][c] for c in CEN}
    out["Transição: outubro e abril"] = {c: res["2026-10"][c] + res["2027-04"][c] for c in CEN}
    return out


def R(x): return f"R$ {x:,.0f}".replace(",", ".")


def documento(res, fer, temp):
    tot = {c: sum(res[m][c] for m in MESES) for c in ("livro",) + CEN}
    L = []
    L.append("# Metas e previsão de faturamento: outubro de 2026 a abril de 2027\n")
    L.append("Pedido do dono em 14/10/2026: metas de faturamento da baixa e da alta temporada, com os feriados dos próximos 6 meses, e previsões coerentes com os ajustes que estamos fazendo no PriceLabs. Versão 2 (revisada por três revisores independentes de IA; gerada por `scripts/metas_faturamento.py`).\n")
    L.append("**O que chamamos de faturamento:** o valor das diárias de cada reserva como está no Beds24 (campo \"revenue\" do PriceLabs), **antes** da comissão da Booking e do Airbnb e sem extras. É o número do relatório \"Revenue On The Books\" do PriceLabs, por mês de estadia, e é por ele que a rotina vai acompanhar as metas. Atenção: nas reservas do Airbnb esse campo chega ao PriceLabs com cerca de 40% a menos do que o hóspede paga (mapeamento do Beds24; pendência sua).\n")
    L.append("## 1. De onde vêm os números")
    L.append("- **Só há histórico nosso desde 27/08/2026** (início da conexão Beds24 → PriceLabs). Setembro é o único mês cheio: **R$ 324.612**, 511 diárias de 950 (54%), diária média R$ 635. De 1 a 14/10: 48% de ocupação, diária média R$ 559. Outubro inteiro já tem **R$ 238.221** no livro (realizado 1 a 13/10 R$ 118 mil + reservas de 14 a 31/10 R$ 120 mil).")
    L.append("- **O mercado** é a vizinhança de cada pousada no PriceLabs (anúncios Studio/1 quarto do Airbnb, cerca de 200 por vizinhança): ocupação e diária média realizadas em cada mês do ano passado. O ritmo de reservas do mercado para os próximos meses está no nível do ano passado (outubro e janeiro iguais; novembro e dezembro um pouco atrás).")
    L.append("- **Nosso índice contra o mercado** (nosso RevPAR ÷ RevPAR do mercado no mesmo mês do mesmo ano): setembro Recanto **1,48** e Villa **1,25**; de 1 a 14/10 o portfólio caiu para **1,25**. Ou seja: faturamos por unidade entre 1,25 e 1,5 vez o mercado. O cenário base usa 1,35 (Recanto) e 1,20 (Villa), entre setembro e a queda de outubro; a meta usa 1,70 e 1,50, que é o que a flutuação por faixas e as metas de ritmo de ocupação (70% a 7 dias, 50% a 15, 35% a 30) devem entregar; o piso usa 1,15 e 1,00.")
    L.append("- **Previsão do mês = RevPAR do mercado no mesmo mês do ano passado × crescimento × índice × unidades × dias**, por pousada. Crescimento: 1,05 na base e na meta (o mercado pede hoje de 3% a 28% acima do ano passado; nas reservas já feitas, de −8% a +28% conforme o mês; o RevPAR do mercado oscilou de −37% a +35% contra o ano anterior nos últimos 12 meses), 1,00 no piso. Teto de 95% de ocupação nos meses com pico (novembro, dezembro, fevereiro e abril no Recanto). Nenhum mês fica abaixo do livro.")
    L.append("- **Outubro:** livro do mês inteiro + última hora (setembro vendeu 6,6 diárias por dia com 0 a 1 dia de antecedência): base + R$ 27 mil (metade do ritmo de setembro), meta + R$ 60 mil (ritmo cheio).")
    L.append("- **Páscoa:** em 2027 cai em março (no ano passado, abril): março recebe o efeito líquido da Páscoa da tabela de feriados (meta do feriado menos 3 noites comuns) e abril perde R$ 4 mil (o pouco que a Páscoa de 2026 pôs no mercado de abril).")
    L.append("- **Feriados:** vendido até 14/10 + (ocupação-alvo × diárias − já vendidas) × diária real por noite (o que o hóspede paga), nunca abaixo do piso de feriado da skill (R$ 800 reais; R$ 1.000 no Natal, Réveillon, Carnaval e Páscoa). As metas de feriado fazem parte das metas do mês, não se somam a elas.\n")
    L.append("## 2. Previsão e metas por mês\n")
    L.append("| Mês | Mercado no ano passado (ocupação Recanto/Villa × diária média) | Já no livro em 14/10 | Piso | **Previsão base** | **Meta** |")
    L.append("|---|---|---|---|---|---|")
    for m in MESES:
        k = INSUMOS["mercado_ano_passado"][m]
        mk = f"{k['recanto'][0]}%/{k['villa'][0]}% × R$ {k['recanto'][1]}/{k['villa'][1]}"
        if m == "2027-03": mk += " (+ Páscoa 2027)"
        if m == "2027-04": mk += " (− Páscoa 2026)"
        L.append(f"| {NOME[m]} | {mk} | {R(res[m]['livro'])} | {R(res[m]['piso'])} | **{R(res[m]['base'])}** | **{R(res[m]['meta'])}** |")
    L.append(f"| **Total (1/10/2026 a 30/04/2027)** | | {R(tot['livro'])} | {R(tot['piso'])} | **{R(tot['base'])}** | **{R(tot['meta'])}** |\n")
    L.append("Leitura: setembro fez R$ 324.612. Outubro fecha abaixo de setembro (mercado mais fraco em outubro e primeira quinzena com 48% de ocupação e diária menor); novembro fica uns 15% abaixo de setembro (mercado parecido, mas o índice da base é menor que o de setembro); dezembro sobe forte (Natal e Réveillon); janeiro fica no nível de novembro (verão é vale em Campos: concorre com a praia); fevereiro e março são o fundo do ano, com o Carnaval e a Páscoa segurando; abril volta a subir. Nota sobre novembro: o calendário mostra 72 unidades-noite ocupadas sem reserva no PriceLabs (45 delas são a Villa inteira em 5, 6 e 7/11, marcada como indisponível): se for um grupo ou bloqueio seu, o faturamento real de novembro é maior do que o livro mostra; confirme no Beds24.\n")
    L.append("## 3. Metas por temporada\n")
    L.append("Por decisão sua (14/10: \"Sim janeiro é alta\"), a alta destes 6 meses é **dezembro e janeiro**; novembro, fevereiro e março são baixa, e outubro e abril ficam como transição. A outra alta do ano é o inverno (junho a agosto), fora deste horizonte: a meta dela entra na revisão de abril. O calendário de demanda da skill, que vem dos dados do mercado, continua vendo janeiro como semanas de vale (3 a 21/1 e 25/1 a 4/2) com fins de semana médios: ele decide o preço de cada noite, e a sua temporada decide a meta do mês.\n")
    L.append("| Temporada | Piso | **Previsão base** | **Meta** |")
    L.append("|---|---|---|---|")
    for nome, v in temp.items():
        L.append(f"| **{nome}** | {R(v['piso'])} | **{R(v['base'])}** | **{R(v['meta'])}** |")
    L.append("\nFestas de fim de ano = Natal + Réveillon + 8 noites de ombro (18 a 23 e 27 a 28/12); elas já estão dentro da alta (não somar com a primeira linha).\n")
    L.append("## 4. Metas por feriado\n")
    L.append("| Feriado | Vendido em 14/10 | Piso | **Base** | **Meta** |")
    L.append("|---|---|---|---|---|")
    for nome, v in fer.items():
        vd = v["vendido"]; o = v["ocupacao"]; a = v["diaria"]
        L.append(f"| {nome} | {vd['noites']} de {vd['diarias']} diárias ({vd['noites']/vd['diarias']:.0%}), {R(vd['receita'])} | {R(v['piso'])} ({o[0]:.0%} × R$ {a[0]}) | **{R(v['base'])}** ({o[1]:.0%} × R$ {a[1]}) | **{R(v['meta'])}** ({o[2]:.0%} × R$ {a[2]}) |")
    L.append(f"| **Soma dos 8 feriados** | | {R(sum(v['piso'] for v in fer.values()))} | **{R(sum(v['base'] for v in fer.values()))}** | **{R(sum(v['meta'] for v in fer.values()))}** |\n")
    L.append("- Como está hoje (calendário de 14/10): Oktoberfest (16 e 17/10) já com 90% vendido (R$ 31 mil); Finados 48% (Queen 7 com 2 de 21 e King Spa 2 com 1 de 6; o que falta está carregado a R$ 1.095 reais por noite pelo +20% seu); Consciência Negra 27%; Natal 2%; Réveillon 17% nas 5 noites (31/12 com 42%; Balcony 5 de 6). 15/11 cai no domingo: fim de semana comum, sem meta própria.")
    L.append("- Natal e Réveillon estão carregados hoje acima da diária da base (R$ 1.400 a 1.500 reais nas noites livres): a flutuação só desce um degrau quando a noite estiver atrás da meta de ritmo; a 7 dias, com sobra, pico desce para \"alto\", nunca para \"médio\".")
    L.append("- Aniversário de São Paulo e Tiradentes são feriados fracos (mercado de janeiro igual ao de novembro; Tiradentes isolado na quarta): o piso deles em `quartos.json` passou de R$ 1.000 para R$ 800 reais, dentro do que a regra permite (nunca abaixo de R$ 800).\n")
    L.append("## 5. Como vamos acompanhar")
    L.append("- **Toda segunda (revisão semanal):** livro de cada mês (Report Builder por mês de estadia) contra piso, base e meta, com a leitura \"no ritmo / atrás / à frente\" pelo que o mês costuma ter vendido a 30, 15 e 7 dias (em setembro, 34% das diárias foram vendidas com 0 a 1 dia de antecedência e pelo menos 48% dentro de 7 dias). A revisão só relata; a reação por noite continua a da flutuação por faixas, um degrau por noite por dia.")
    L.append("- **Primeiro dia útil do mês:** mês fechado contra a meta, diária média e ocupação por quarto; o índice contra o mercado é refeito com o mês realizado e as metas dos 6 meses seguintes são recalibradas (basta atualizar os insumos do script).")
    L.append("- **Feriados:** vendido contra a meta a 30, 15 e 7 dias. A 7 dias com menos de 50% vendido, a rotina sugere a você liberar 1 noite (feriadões; o Réveillon não) e o preço desce um degrau, nunca para \"baixo\"; a decisão de liberar é sua.")
    L.append("- Os números ficam em `.claude/skills/gestao-receita-pousadas/dados/metas_faturamento.json`; o relatório da rotina ganha o item \"Faturamento\".\n")
    L.append("## 6. Metas do dono (14/10) e os três níveis de operação\n")
    L.append("Você fixou as metas: **baixa temporada, Villa R$ 150 mil e Recanto R$ 220 mil; alta temporada, Villa no mínimo R$ 300 mil e Recanto R$ 350 mil.** Li como faturamento **mensal por pousada** (confirmado por você em 14/10, inclusive o Recanto R$ 350 mil na alta: \"Esse é o objetivo\"). Por decisão sua (14/10), a alta destes 6 meses é dezembro e janeiro e, no ano, também o inverno (junho a agosto); outubro, novembro, fevereiro, março e abril são baixa.\n")
    L.append("| Pousada e temporada | Mínimo (sua meta) | RevPAR exigido (R$ por unidade-noite) | Realista (estratégia atual a pleno, índice 1,70/1,50) | Máximo (índice 2,0/1,8: topo da faixa alvo e ocupação nas metas de ritmo) | Hoje (ritmo atual, índice 1,35/1,20) |")
    L.append("|---|---|---|---|---|---|")
    pp = por_pousada()
    for m, nome in (("2026-11", "Recanto, baixa (novembro)"), ("2026-11", "Villa, baixa (novembro)"), ("2026-12", "Recanto, alta (dezembro)"), ("2026-12", "Villa, alta (dezembro)"),
                    ("2027-01", "Recanto, alta (janeiro)"), ("2027-01", "Villa, alta (janeiro)")):
        p = "recanto" if nome.startswith("Recanto") else "villa"; v = pp[m][p]
        L.append(f"| {nome} | **{R(v['minimo_dono'])}** | R$ {v['revpar_minimo_dono']} | {R(v['realista'])} | {R(v['maximo'])} | {R(v['hoje'])} |")
    L.append("")
    L.append("Leitura honesta: na **Villa**, sua meta da baixa (R$ 150 mil) cabe no realista (R$ 162 mil em novembro) e a da alta (R$ 300 mil) está entre o realista e o máximo em dezembro. No **Recanto**, a meta da baixa (R$ 220 mil) é 20% acima do realista e igual ao máximo; a da alta (R$ 350 mil) passa do máximo que o mercado do ano passado permite. Setembro, o melhor mês medido, fez R$ 182 mil no Recanto. Para o Recanto chegar lá é preciso subir ocupação **e** diária ao mesmo tempo: por exemplo, 60% de ocupação a R$ 765 reais por noite na baixa (hoje: 57% a R$ 667) e 70% a R$ 1.010 na alta.\n")
    jr, jv = pp["2027-01"]["recanto"], pp["2027-01"]["villa"]
    L.append(f"**Janeiro como alta:** a meta sobe para {R(jr['minimo_dono'])} no Recanto e {R(jv['minimo_dono'])} na Villa. "
             f"Pelo mercado do ano passado (janeiro: {INSUMOS['mercado_ano_passado']['2027-01']['recanto'][0]}% de ocupação no Recanto e "
             f"{INSUMOS['mercado_ano_passado']['2027-01']['villa'][0]}% na Villa), o realista é {R(jr['realista'])} e {R(jv['realista'])} e o máximo "
             f"{R(jr['maximo'])} e {R(jv['maximo'])}: sua meta fica {jr['minimo_dono'] / jr['maximo'] - 1:.0%} acima do máximo no Recanto e "
             f"{jv['minimo_dono'] / jv['maximo'] - 1:.0%} na Villa. Na prática, janeiro só chega lá se vender bem acima do mercado do ano passado "
             f"(RevPAR de R$ {jr['revpar_minimo_dono']} no Recanto e R$ {jv['revpar_minimo_dono']} na Villa por unidade-noite, contra R$ "
             f"{round(jr['realista'] / (16 * 31))} e R$ {round(jv['realista'] / (15 * 31))} no realista). Hoje o livro de janeiro tem "
             f"{R(INSUMOS['livro']['2027-01'])} (as duas pousadas). Em janeiro, o modo por faturamento cuida disso sem mexer nas travas: enquanto o livro do mês estiver abaixo do realista no ritmo esperado "
             "(40% no dia 1, 65% no dia 15, 80% a 7 dias do fim), a pousada fica em modo volume; só passa a modo preço se alcançar a sua meta. Até lá, o modo segue o livro do mês corrente.\n")
    L.append("**O que muda na estratégia (modo por faturamento, regras.md 4c):** toda segunda a revisão compara o livro do mês com estes níveis e grava um modo por pousada em `quartos.json`, que a flutuação por faixas passa a obedecer:")
    L.append("- **Modo volume** (livro atrás do menor limiar, realista ou seu mínimo, no ritmo esperado): todos os quartos da pousada agem como classe baixa: a flutuação pode descer um degrau já na meta (até a faixa alvo −1), corte de última hora pleno em D0 a D3, dias úteis no mínimo do quarto, sábado abaixo de 50% vira sugestão de liberar 1 noite. Objetivo: diárias.")
    L.append("- **Modo padrão** (entre os dois limiares; o menor deles é a linha de baixo e o maior a de cima: na Villa em baixa o seu mínimo fica abaixo do realista, nos outros casos acima): as regras de hoje, classe de cada quarto.")
    L.append("- **Modo preço** (no seu mínimo ou acima): todos os quartos agem como classe alta: +5% já na meta até a faixa alvo +1, +10% à frente até alvo +2, corte de última hora só na última unidade, pisos por data +5% a +10% (em percentual) na segunda. Objetivo: diária.")
    L.append("- **De olho na lotação (sua decisão de 14/10):** o modo preço só vale com a pousada na meta de ocupação em pelo menos 2 das janelas de 7, 15 e 30 dias (70%, 50% e 35%); se não, fica em padrão. Noite atrás da meta de ocupação desce um degrau em qualquer modo.")
    L.append("- As travas não mudam em nenhum modo: padrão de qualidade de R$ 1.000, pisos de feriado, descontos da Booking, sábado sozinho fechado, ±15% da base por semana. O modo nunca é mudado pela rotina leve.")
    L.append("- **Ritmo esperado do livro** (referência inicial, recalibrada todo mês): 40% do realista no primeiro dia do mês, 65% no dia 15, 80% a 7 dias do fim; em setembro, 34% das diárias vieram com 0 a 1 dia de antecedência.\n")
    L.append("## 7. O que pode mudar as previsões")
    L.append("- Primeira temporada com dados: o índice foi medido em um mês e meio; a cada mês fechado ele é refeito e as metas, ajustadas.")
    L.append("- Dezembro depende de vender Natal com 2 noites e Réveillon com 3 noites a R$ 1.000 reais ou mais; hoje estão em 2% e 17%, normal a 70 dias, mas é o ponto a vigiar de 15/11 em diante.")
    L.append("- O mercado perdeu de 11% a 37% de RevPAR em 5 dos 7 meses equivalentes do ano passado e ganhou 35% em setembro: a base assume +5%; se o mercado cair na última hora, a Villa (dias de semana de última hora) sente primeiro.")
    L.append("- O que mais pesa e só você resolve: visibilidade dos anúncios (Google, Booking, canais fixos da Villa) e o mapeamento do Airbnb no Beds24; Queen 7 e Balcony só vendem de última hora.")
    return "\n".join(L) + "\n"


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--doc"); ap.add_argument("--json")
    a = ap.parse_args(argv)
    fer = feriados(); res = meses(fer); temp = temporadas(res, fer)
    doc = documento(res, fer, temp)
    if a.doc: Path(a.doc).write_text(doc, encoding="utf-8")
    if a.json:
        Path(a.json).write_text(json.dumps({"versao": "2026-10-14T21", "lido_em": INSUMOS["lido_em"], "faturamento": "revenue do PriceLabs = rental_revenue do Beds24, antes da comissão da OTA e sem extras",
            "metodo": __doc__.split("Método")[1].split("Uso:")[0].strip(), "insumos": INSUMOS, "meses": res, "temporadas": temp, "feriados": fer, "metas_dono": DONO, "por_pousada": por_pousada(),
            "acompanhamento": "segunda: livro × meta por mês (Report Builder por mês de estadia) e ritmo; dia 1: mês fechado e recalibração do índice; feriados a 30/15/7 dias"},
            ensure_ascii=False, indent=1), encoding="utf-8")
    print(doc)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

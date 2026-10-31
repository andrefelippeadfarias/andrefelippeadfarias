# Estratégia de receita (versão 2.0, 30/10/2026: motor de pace ancorado no mercado com hidro)

> **31/10/2026: motor LIGADO pelo dono** ("Pode ligar o motor de pace. Pode fazer tudo também conforme planejado. Tudo que você verificou está correto. Vamos vender."). A escada por reserva e a Regra J encerraram em 30/10; as noites esgotadas recebem o piso de proteção do motor. Ajustes feitos ao ligar em `regras.md` 4d. D1 e D2 seguem com o dono (seção 13). Esta versão substitui a 1.3 (14/10). Regras detalhadas em `regras.md` (seção 4d para o motor); parâmetros em `dados/quartos.json` → `parametros.pace`, `parametros.motor`, `parametros.fator_publico`; ordens do dono em `dados/ordens_dono.json`. Onde este texto divergir de `regras.md`, vale `regras.md`.

Documento único da estratégia das duas pousadas, escrito para o dono e para a rotina autônoma. Diz **o que queremos, com que régua medimos, em que ordem decidimos e o que só o dono resolve**.

**Em resumo.** O objetivo do dono (30/10) é "o maior valor de estadia, porém sempre com os 2 hotéis lotados". Em 30 dias de operação vendemos mais que o mercado (53% contra 34% de ocupação), mas com diária média menor (R$ 579 contra R$ 691) e quase sempre no piso: o preço de tabela ficou 1,8 a 2,6 vezes a mediana dos hotéis com hidro, e a Booking o derrubou com descontos até o piso do dono. A estratégia nova tem **uma régua só** (os percentis do preço público dos hotéis com hidro na Booking, noite a noite), **uma curva de venda esperada** por tipo de noite (semana, fim de semana, feriado) e **um passo por noite por dia**, proporcional à distância do alvo: noite à frente da curva sobe ou segura (nunca desce); noite atrás desce em direção ao percentil-alvo, até o piso do dono. Os pisos do dono são travas duras; quando o motor quer descer e o piso não deixa, a noite aparece como "presa no piso" e vira decisão do dono, não da skill.

## 1. Objetivo e métricas
- **Objetivo:** as duas pousadas lotadas com a maior diária que o mercado da noite paga. Lotação vem primeiro: noite atrás da curva sempre desce (até o piso); diária alta se busca só onde a venda está à frente da curva.
- **Métrica mestra: ritmo de venda (pace) por noite.** Cada noite tem uma curva de ocupação esperada por antecedência (seção 5). Vendida acima da curva + 20 pontos = **à frente**; abaixo da curva − 20 = **atrás**; − 30 = **muito atrás**; sem venda a 7 dias (semana) ou 14 dias (fim de semana e feriado) = muito atrás.
- **Métricas de resultado:** RevPAR (receita por quarto disponível) por tipo de noite e por pousada, comparado ao RevPAR do mercado do PriceLabs; ocupação de hoje (D0) e dos próximos 7 dias; diária média realizada ÷ diária média do mercado.
- **Métricas de controle:** número de noites "presas no piso" (o motor queria descer e o piso do dono segurou); percentil do nosso preço público na âncora; reservas com antecedência 0 a 1 dia (hoje 39%: quanto mais, mais caro estamos antes); cancelamentos (31% das reservas; 66% no dia ou véspera).
- **Metas de faturamento do dono (14/10)** continuam em `dados/metas_faturamento.json` como métrica de resultado mensal; o modo por faturamento (volume / padrão / preço) deixa de deslocar o preço: o motor usa só pace e mercado.

## 2. O diagnóstico de 30/10 que motivou a mudança
- Ocupação 53% contra 34% do mercado nos 30 dias; diária média R$ 579 contra R$ 691 (16% abaixo). Vendemos volume barato, não valor.
- Preço de tabela 1,8 a 2,6 vezes a mediana dos hotéis com hidro; em fins de semana de dezembro, até 49% acima do **máximo** do mercado. Resultado: 80% a 96% dos dias no piso, com a Booking fazendo o preço real.
- Pilha de descontos da Booking de 46% a 50% sobre a tabela, contra 15% a 19% nos concorrentes: o hóspede vê um "de R$ 1.220 por R$ 500" que desvaloriza a marca e some com a margem.
- 39% das reservas chegam com 0 a 1 dia de antecedência; terça a quinta são as noites mais fracas; a Balcony vende 18% das noites.
- A Panorâmica II (Queen 2) vende na Booking por tarifa fixa (R$ 412 a 495) que ignora o PriceLabs; o Recanto não aparece na Booking para o Finados de 2 noites nem para o Natal (24 a 26/12); a Villa está bloqueada em 05 a 07/11 sem reserva.
- As regras acumuladas até 28/10 (escada por antecedência, flutuação por faixas, escada por reserva, Regra J a 90% × 1,5, padrão de qualidade, pisos do dono) se sobrepunham e, na prática, eram dominadas pelos pisos: o preço só se mexia para cima.

## 3. Os três preços
Toda conversa sobre preço traz os três números:
- **Enviado:** a tabela que mandamos ao PriceLabs (e ao Beds24).
- **Público:** o que a Booking mostra a quem não tem Genius: enviado × `parametros.fator_publico` (Recanto 0,63; Villa 0,65; medido em 29 e 30/10; recalibrado na revisão de segunda pela busca da Booking dos nossos hotéis).
- **Genius 3:** o que o hóspede mais descontado paga: enviado × 0,41 (Recanto, domingo a quinta) / 0,425 (sexta e sábado); Villa 0,53 / 0,575. Os pisos do dono (R$ 500 em dia de semana, R$ 850 em sexta e sábado) são neste preço.
- **No bolso:** depois da comissão, cerca de 0,42 do enviado no Recanto e 0,39 na Villa (só para o piso de feriado de R$ 800 reais).
O mercado se compara **público com público**: nossa posição é o percentil do nosso preço público na âncora da noite.

## 4. A régua única: âncora de mercado por noite
- `dados/ancora_mercado.json` (`scripts/ancora_mercado.py`): para cada noite pesquisada, os percentis **p25, p50, p75, p90 e máximo** do preço público por noite dos hotéis **com hidro** que a Booking devolve para a mesma estadia (2 adultos). Noite sem busca própria usa a mediana dos percentis do mesmo tipo de noite (semana, fds, feriado) no mesmo mês.
- Fontes: a coleta das 07:53 (`concorrentes.searches`) e a análise de 60 dias (`buscas`); a busca diária dos concorrentes do perfil (20/10) alimenta a mesma âncora.
- Qualidade: `n` (hotéis na busca) e `busca_em` ficam gravados; com menos de 6 hotéis ou busca com mais de 10 dias, a âncora é "fraca" e o passo cai pela metade.
- Exemplos de 30/10: sexta 06/11 p50 R$ 653, p90 R$ 1.080, máx. R$ 1.144; terça 10/11 p50 R$ 353, p90 R$ 579; feriado 20/11 p50 R$ 829, p90 R$ 1.355.
- Não há outra régua: as cinco faixas de 13/10 (`mercado_faixas.json`), a "média dos 3 maiores" da escada e o "topo do mercado" da Regra J são substituídos pelos percentis assim que o motor for ligado (D3).

## 5. O motor de pace (`scripts/pace.py`)
1. **Curva esperada** (% vendido por antecedência D; `parametros.pace.curvas`): semana 3% a D60, 8% a D30, 15% a D14, 28% a D7, 42% a D3, 55% a D1, 100% em D0; fim de semana 8 / 18 / 32 / 50 / 68 / 85 / 100; feriado 12 / 30 / 50 / 68 / 82 / 92 / 100. Quartos de 1 e 2 unidades (Afrodite, Queen 2, King Spa 2) usam a ocupação da pousada na noite.
2. **Status** da noite: à frente (+20 pontos ou mais sobre a curva), no ritmo, atrás (−20) ou muito atrás (−30, ou zero vendas perto da data).
3. **Percentil-alvo** por status e por pousada (`parametros.pace.alvo`): Recanto à frente a até 7 dias p90, à frente p75, no ritmo p65, atrás p50, muito atrás p40; Villa um degrau abaixo (p75 / p65 / p50 / p40 / p25). O alvo em reais = valor do percentil na âncora ÷ fator público.
4. **Passo proporcional:** metade da distância relativa até o alvo (`k` 0,5), limitado por antecedência (`pmax`): 5% a 31 dias ou mais, 10% de 8 a 30 dias, 15% de 4 a 7, 20% de 0 a 3. Âncora fraca: metade. Mudança menor que 3% não vira item.
5. **Regras de sinal:** noite à frente nunca desce (segura ou sobe); a noite de hoje (D0) nunca sobe; esgotada e overbooking recebem só piso de proteção (p90 da âncora, teto livre, nunca piso = teto) quando a Regra J for desligada (D3).
6. **Pisos do dono como trava dura** (seção 6): se o passo cai abaixo do piso, o novo preço é o piso e a noite fica "presa no piso".
7. **Mecânica no PriceLabs:** subida pelo piso (`min_price`) com o teto solto no máximo do anúncio; descida pelo piso e, quando o algoritmo está acima, também pelo teto (`max_price` = novo). Sempre em percentual sobre a base (`percent_base`), nunca em reais.
8. **Cadência:** um passo por noite por dia; o passo só conta como medido depois de chegar ao canal (envio diário ou Sync Now; `dados/passos.json` com `chegou_ao_canal`) e de passar uma rodada; até lá a noite não recebe segundo passo na mesma direção.
9. **Fora do motor:** noites com ordem do dono por data, mudança manual recente (7 dias), Queen 2 enquanto a tarifa fixa da Booking existir, noites sem âncora, e a noite esgotada enquanto a Regra J estiver ativa.
10. **Coerência depois do motor:** Panorâmica II entre a Moinhos e a Panorâmica I; Afrodite 20% acima da mais cara das outras de domingo a quinta e 5% em sexta, sábado e feriado; Balcony 10% abaixo da suíte com banheira livre mais barata da Villa.

**Sombra de 30/10** (282 noites de 7 quartos nos próximos 60 dias): 52 à frente, 223 no ritmo, 3 atrás, 4 muito atrás; 74 descidas e **nenhuma subida**; **94 noites presas no piso**; 145 noites fora do motor (Queen 2 61, ordens do dono 56, esgotada/Regra J 24, manual 4). Leitura: o piso de R$ 500 no Genius 3 vale R$ 1.220 enviados e R$ 768 públicos no Recanto, ou seja, **acima do máximo** do mercado de dia de semana (p50 R$ 353 a 388). O motor não consegue levar o Recanto ao percentil-alvo em dia de semana enquanto esse piso existir: é a decisão D1.

## 6. Pisos do dono e "presas no piso"
- Pisos vigentes (sempre no preço Genius 3): R$ 500 de domingo a quinta e R$ 850 em sexta e sábado nas 6 suítes com banheira (20/10, "serve para todo o piso"); feriado com R$ 800 reais no bolso (06/10); mínimo do anúncio de cada quarto; Balcony pelo próprio mínimo (R$ 690). Exceções só em `limites_seguranca.excecoes_dono` e `ordens_dono.json`.
- Em dia de semana os pisos equivalem a R$ 1.220 (Recanto) e R$ 943 (Villa) enviados; em sexta e sábado, R$ 2.000 e R$ 1.478. O relatório diz, por tipo de noite, quantas noites o motor segurou e em que percentil do mercado o piso cai.
- A skill **não** mexe nos pisos: propõe com números (seção 13) e espera a resposta.

## 7. Em que ordem decidimos (lista única)
1. **Limites de segurança e proibições** (`quartos.json` → `limites_seguranca`; `regras.md` seção 1): descontos da Booking e OTAs, Genius acima do 3, sábado sozinho, nada em reais por data, nunca abaixo de 70% do mínimo de referência.
2. **Pisos e ordens do dono com validade** (`dados/ordens_dono.json`): pisos de 14 e 20/10, piso de feriado, ordens por data (6b), regras permanentes (Regra G, ordem do Recanto, Afrodite), mudanças feitas pela tela (congelam a noite por 7 dias). Toda exceção do validador exige `ordem_id` vigente.
3. **Motor de pace** (seção 5), ligado desde 31/10.
4. **Coerência entre quartos** (Panorâmica II, Afrodite, Balcony).
5. **Cadência e validador:** um passo por noite por dia; nada é gravado sem `validar_plano.py`; tudo conferido no recálculo, registrado com antes, depois e como desfazer.

Regras antigas que continuam escritas em `regras.md`, mas **dominadas** pelos pisos do dono (não produzem item enquanto os pisos de 14 e 20/10 valerem): Regra F (escada por antecedência), Regra C (Afrodite −30%), padrão de qualidade de R$ 1.000, flutuação por faixas (4c) e modo por faturamento.

## 8. Posicionamento por pousada e por suíte
- **Recanto dos Moinhos** (16 unidades; 5 estrelas, nota 8,4; hidro em todas): alvo um degrau acima da Villa em todo status. Moinhos (Queen 7) é a porta de entrada e a que mais vende de última hora; Panorâmica I (Double) acompanha; Panorâmica II (Queen 2) fica entre as duas (ordem do dono) e está fora do motor até a tarifa fixa da Booking sumir; Afrodite é a mais cara (20% / 5%) e, em fim de semana fora de feriado, só até 95 dias segue a faixa alta de R$ 1.600 a 1.800 públicos (o teto nunca é copiado para o inverno de 2027).
- **Villa Dolce Amore** (15 unidades; 3 estrelas, nota 8,1; hidro não listada na Booking): alvo um degrau abaixo. King Spa 7 e King Spa 2 pelo motor; Balcony sempre 10% abaixo da suíte com banheira livre mais barata (Regra G) e nunca abaixo do mínimo do anúncio.
- Fato de mercado que molda a Villa: metade da oferta comparável custa menos de R$ 480 por noite em dia de semana. Por isso os dias de semana da Villa só vendem de última hora ou em evento.

## 9. Táticas por tipo de noite
- **Dia de semana (domingo a quinta):** alvo p65 (Recanto) / p50 (Villa) no ritmo; hoje presos no piso do dono. Se a decisão D1 liberar, o motor desce em direção ao alvo com passos de 10% a 20% conforme a antecedência; o corte de última hora deixa de ser regra separada (é o passo de D0 a D3 com teto de 20%).
- **Sexta e sábado fora de feriado:** curva de fim de semana; à frente a até 7 dias mira p90 (Recanto) / p75 (Villa); sábado com 2 noites (Regra H, no Beds24) e liberação para 1 noite na quarta-feira quando a pousada estiver abaixo de 60% nesse sábado (decisão D2).
- **Feriados e picos:** curva de feriado; estadia mínima 2 noites (3 em Finados, Carnaval, Corpus Christi, 9 de Julho e Réveillon) com as pontas liberadas para 1 noite a 7 e a 3 dias quando sobrar (D2); piso de feriado R$ 800 reais; esgotada com piso de proteção p90.
- **Última hora (D0 a D3):** passo até 20%, decidido na rotina das 07:53 (antes do envio diário); hoje nunca sobe; gravação depois do envio pede Sync Now na primeira linha do relatório.
- **Esgotada e overbooking:** piso de proteção p90 com teto livre (um cancelamento não reabre barato); overbooking no topo do relatório, sempre.

## 10. Governança
- **Ordens do dono** (`dados/ordens_dono.json`): cada pedido literal vira uma ordem com id, texto, quartos, período e o que libera no validador; vencida, o validador barra o item. Toda exceção "dono" nos planos exige `ordem_id`.
- **Mudança pela tela do PriceLabs** (log sem `api_`): `python3 scripts/ordens.py manual ...` registra e congela a noite por 7 dias para todos os scripts.
- **Modo sombra** (`parametros.motor.modo`): o motor calcula e publica o plano em `pricelabs/pace-sombra/<data>.md`, sem gravar; "ligado" só com a resposta do dono à D3, registrada em `regras.md` 6a e em `historico-da-skill.md`.
- **Validador e registro:** nada é gravado sem `validar_plano.py`; horizonte de teto por regra limitado (Afrodite: 95 dias); tudo vai para `pricelabs/registro-de-mudancas.md` com antes, depois e como desfazer; commit em toda rodada.

## 11. Frequências
- **07:53 (rotina completa):** coleta, âncora de mercado, pace (sombra ou ligado), ordens do dono, coerência, calendário de 12 meses, decisão das noites D0 a D3, relatório.
- **10:53, 13:53, 16:53 e 19:53 (rotina leve):** reservas e cancelamentos (Beds24 direto; PriceLabs como reserva), mudanças manuais (registrar e congelar), envio aos canais; sem passo novo de pace (a cadência é diária), só travas e reação a venda/cancelamento pelas ordens vigentes.
- **Segunda:** revisão semanal: recalibrar fatores público e Genius pela busca da Booking dos nossos hotéis, RevPAR por tipo de noite contra o mercado, presas no piso por tipo de noite, pesquisa ampla dos concorrentes, faturamento contra a meta, painel semanal.
- **A cada 15 dias:** análise dos 12 meses (dono, 21/10).

## 12. O que acompanhamos no relatório
Semáforo e ocupação dos próximos 7 dias; tabela quarto × janelas; **pace:** quantas noites à frente, no ritmo, atrás e presas no piso (com o percentil do piso); o que mudou, com os três preços (enviado, público, Genius 3) e quando chega aos canais; hoje e as próximas 3 noites; alertas (overbooking, manual, envio, cancelamentos, feriado mais próximo); até 3 itens que só o dono resolve; o que aprendemos.

## 13. O que só você resolve (hoje)
- **D1. Pilha de descontos da Booking e o piso de dia de semana.** Com Genius 3 a 0,41 do enviado e público a 0,63, o piso de R$ 500 vira R$ 768 público, acima do máximo do mercado de dia de semana. Proposta: reduzir a pilha de descontos da Booking para no máximo 25% a 30% (com o mesmo R$ 500 no Genius, o público cairia para ~R$ 617) **ou** autorizar pisos de última hora menores de domingo a quinta. A skill não mexe em Genius nem em promoções: é na extranet.
- **D2. Restrições no Beds24** (a estadia mínima do PriceLabs não chega à Booking): sábado é o único dia com bloqueio de chegada; sexta e domingo a quinta com 1 noite; sábado liberado para 1 noite na quarta-feira anterior se a pousada estiver abaixo de 60%; feriados com 2 noites e as pontas liberadas para 1 noite a 7 e a 3 dias quando sobrar; cadastrar o token só-leitura do Beds24 para a skill medir reservas e estadias.
- **D3. Ligar o motor de pace: DECIDIDA em 31/10** ("Pode ligar o motor de pace"). Motor ligado; escada por reserva e Regra J encerradas; esgotada e overbooking com piso de proteção p90 (nunca abaixo dos pisos padrão do quarto) e teto livre.
- Também: tarifa fixa da Panorâmica II na Booking; o Recanto invisível na Booking para o Natal (24 a 26/12) e para 2 noites no Finados; bloqueio da Villa em 05 a 07/11; mínimo da Balcony de R$ 690 para R$ 600 (proposta); Genius restrito às suítes de entrada; política de cancelamento (66% dos cancelamentos no dia ou véspera); conteúdo do anúncio da Villa (hidro não listada). Lista viva em `dados/pendencias.json`.

## Palavras que usamos
**Enviado:** a tabela que mandamos ao PriceLabs. **Público:** o que a Booking mostra sem Genius (enviado × 0,63 no Recanto, 0,65 na Villa). **Genius 3:** o preço do hóspede mais descontado (× 0,41/0,425 e 0,53/0,575). **No bolso:** depois da comissão. **Âncora:** os percentis (p25 a máximo) do preço público dos hotéis com hidro na Booking para a noite. **Percentil:** a posição do nosso preço público na âncora (p50 = mediana). **Curva:** a ocupação esperada da noite a cada antecedência. **Pace / status:** à frente, no ritmo, atrás, muito atrás. **Passo:** a mudança de um dia (5% a 20%, proporcional). **Presa no piso:** o motor queria descer e um piso do dono segurou. **Sombra:** o motor calcula e relata sem gravar. **Ordem do dono:** pedido literal registrado com id e validade. **Sync Now:** botão do PriceLabs que manda os preços aos canais na hora. **D0 a D3:** hoje e os 3 próximos dias.

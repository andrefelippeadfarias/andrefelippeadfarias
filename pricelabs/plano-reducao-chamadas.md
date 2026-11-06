# Plano para reduzir as chamadas às APIs (PriceLabs, Beds24, Booking)

Pedido do dono, 06/11/2026: "preciso que melhore essa forma de comunicar as coisas lá para diminuirmos a quantidade de chamadas que temos que fazer. Para quando fizermos com o número mínimo de chamadas necessárias. Vamos criar um plano para isso." / "E vamos verificar os limites da API lá, da Prace Labs."

Status: **proposta, aguardando aprovação do dono.** Nada foi mudado na rotina ainda.

## Limites oficiais do PriceLabs (documentação, 06/11)

- Recálculo (`refresh_listing_pricing`): **3 por quarto a cada 24 h** e **10 por conta por minuto**; acima disso, 429. A tentativa que falha também conta (Balcony em 06/11: 1 recálculo de manhã + 2 falhas "Beds24 temporarily unavailable" → 429 nas seguintes).
- Customer API em geral: **60 por minuto e 1.000 por hora** por chave; acima, 429. Limite maior: pedir a support@pricelabs.co (caso a caso).
- "Batch all changes for a listing before refreshing it" (junte as mudanças do quarto antes de recalcular).
- A API aceita preços de vários quartos numa chamada (`Get prices for listings`) e regras por grupo; o conector MCP que usamos faz um quarto por chamada.
- Fontes: developers.pricelabs.co/customer-api/api-reference/enable-the-api ; .../prices/refresh-listing ; .../prices/for-listings

## Conta de 06/11 (mesmos eventos)

| Rotina | PriceLabs antes → depois | Beds24 antes → depois | Booking antes → depois |
|---|---|---|---|
| 07:53 completa | 52 → 24 | 24 → 7 | 24 → 4 |
| 10:53 leve (06/11: venda na Double, cancelamentos, proteção da Q7 em D0 e D1) | 25 → 7 | 7 → 2 | 2 → 2 |
| 13:53 leve (liberação da Q7) | 3 → 2 | 4 → 2 | 0 → 0 |
| 16:53 leve (estimativa, sem venda) | 2 → 0 | 4 → 2 | 0 → 0 |
| 19:53 leve (estimativa, sem venda) | 2 → 0 | 4 → 2 | 0 → 0 |
| Total do dia (06/11): 153 antes, 54 depois | 84 → 33 | 43 → 15 | 26 → 6 |
| Acréscimo por venda ou cancelamento numa leve (ordem de grandeza) | 8 → 3 | 4 → 3 | 1 → 1 |

## Passos (do mais barato e seguro para o mais trabalhoso)

### 1. Só texto, sem código: tirar as leituras repetidas e as chamadas sem uso (economia estimada: 16 chamadas/dia)

(a) As linhas 82 e 83 do SKILL.md passam a rodar o calendario_12m auditar e plano com --inventario <inv.json>, o inventário de 12 meses do passo 4b. Sem isso, o main (calendario_12m.py, ~811-814) lê o Beds24 ao vivo, porque a linha não passa --sem-beds24. Na rotina completa, a conferência de D0 a D60 (SKILL.md:44) sai do mesmo arquivo.
(b) O get_pms_reservations sai da coleta (coleta-pricelabs.js:183). Conferi com grep: nenhum script lê contexto.bookings nem contexto.logs; só o pace.py:327 lê contexto.sync. Ele fica como plano B, para quando o Beds24 não responder.
(c) Uma só leitura de status, o get_listings, às 07:53 e às 10:53, no lugar do get_listing_data (js:185; SKILL.md:40, 73 e 120). Isso atende a instrução do servidor MCP ('get_listings first') sem chamada extra. Se a primeira resposta não trouxer last_date_pushed, push_enabled e last_refreshed_at, use o get_listing_data no lugar; a contagem é a mesma.
(d) Recálculo:
- sempre em série, com 1 tentativa por quarto por rotina;
- com 400 ou 429, parar (aprendizados.md:260, 278 e 446);
- nunca recalcular um quarto só para a coerência (Regra G, ordem do Recanto): vale o calendário de até 6 h ou o --aceitar-velho (SKILL.md:37 e 155 já admitem);
- o 'detalhar' (parse_reasons_json) sai do fluxo do motor.
(e) Rotinas leves:
- as vendas vêm do movimento do Beds24; sai a 'diferença de ocupação do calendário' do PriceLabs (SKILL.md:33), que podia virar 7 get_listing_prices por rotina;
- às 13:53, 16:53 e 19:53, sem reserva, cancelamento ou alerta, nenhuma chamada ao PriceLabs nem à Booking;
- o get_user_logs vem sempre antes de qualquer gravação, com desde = início da rodada anterior menos 15 min.
(f) Às 10:53, a segurança e a vitrine usam as mesmas 2 buscas, na data alterada: o Recanto por coordenadas (pelo nome cai no id 15459723) e a Villa por hotel_names.
(g) O mercado_diario passa para a revisão de segunda, com OK do dono (ordem de 20/10). Isso evita 72 buscas prescritas por dia, que ficam fora da conta porque não rodaram em 06/11.

**Risco e mitigação:** Riscos:
- Uma mudança manual do dono depois das 10:53 só aparece no relatório da próxima rotina que gravar.
- Uma falha de envio depois das 10:53 só aparece de manhã, como já acontece hoje.
- O get_listings pode não trazer algum campo.

Mitigações:
- A mudança manual nunca é sobrescrita, porque os logs vêm antes de toda gravação e ordens.py manual congela a noite.
- Se faltar campo no get_listings, volta o get_listing_data, com a mesma contagem.
- O inventário continua em toda rotina, então a ordem do dono de 01/11 não muda e não precisa de autorização.
- Medir as chamadas por 2 dias antes do passo 2.

**Arquivos:** Base /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/: SKILL.md (33-45, 63-73, 82-84, 116-120, 155); references/ferramentas.md (16-21, 28-34); references/regras.md (239, 328); /home/user/andrefelippeadfarias/.claude/workflows/coleta-pricelabs.js (179-188)

### 2. Beds24: uma leitura de cada tipo por rotina e um lote de gravação por rodada (economia estimada: 25 chamadas/dia)

(a) Novo comando beds24.py rodada --desde <início da rodada anterior menos 5 min>:
- faz 1 GET /bookings?modifiedFrom, com todos os status e includeInvoiceItems;
- roda movimento(), vigia_seguranca.classificar() e reservas_abaixo.avaliar() na memória (conferi que as duas últimas são puras);
- mostra os nomes só na tela, com --nomes; o JSON sai sem nomes.
O vigia_seguranca.py e o reservas_abaixo.py ganham --arquivo, para uso avulso.
(b) inventario --precos (includePrices, que conferi no apiV2.yaml):
- às 07:53, os 12 meses;
- nas leves, de D0 a D60, em toda rotina.
O price1 do reservas_abaixo sai desse arquivo; o GET de preço só acontece para noite que não estiver nele.
(c) Na rotina completa, 1 reservas --json com a resposta crua sem nomes e com invoiceItems, que o janelas usa pelo receita_por_noite. O janelas, o comparar e o concorrencia_hidro_vista.py (165-169) ganham --reservas <arquivo>. Hoje são 3 leituras iguais.
(d) Um lote por rodada:
- o calendario_12m gera um arquivo só, com o urgente (hoje a D14) primeiro, e o editar-lote aceita vários --plano. Em 06/11 foram 5 lotes com 14 itens, que cabem num POST;
- o token de escrita fica guardado em /root/.beds24/token_escrita (permissão 0600), com a validade do expiresIn devolvido e margem de 5 min. A spec dá 3600 s como exemplo, então não se pode supor 24 h;
- o _get e o _post leem X-FiveMinCreditLimit-Remaining e ResetsIn e esperam, em vez de tomar o 429.
(e) Testes offline com l0611_1053/mov.json e mov2.json, l0611_1353/mov.json, r0611/inv.json e r0611/estadia/lote_*.json.

**Risco e mitigação:** Riscos:
- Uma falha na leitura derruba as três verificações juntas; hoje elas são independentes.
- Um erro de bloco para o lote inteiro.
- O price1 é o do início da rotina.

Mitigações:
- A leitura é repetida 1 vez. Se o Beds24 continuar fora, vale o get_pms_reservations como plano B, com 'vigia sem Beds24' no topo do relatório.
- No lote, o urgente vai no primeiro bloco, e o editar-lote pula na nova tentativa o que já está no alvo.
- A diferença do price1 é de minutos.
- A leitura antes da gravação e a releitura de cada bloco continuam, e o corpo_edicao continua barrando preço.

**Arquivos:** Base /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/: scripts/beds24.py (78-107, 126-162, 250-255, 348-366, 425-495, 498-586); scripts/vigia_seguranca.py (115-137); scripts/reservas_abaixo.py (30-39, 70-99); scripts/concorrencia_hidro_vista.py (158-170); scripts/calendario_12m.py (647-667); scripts/test_scripts.py; SKILL.md (33, 42-44, 70, 74, 84-85)

### 3. Coleta enxuta: o recálculo da manhã vira o calendário do dia (economia estimada: 21 chamadas/dia)

(a) O coleta-pricelabs.js perde as 7 tarefas de quarto (169-177) e o contexto. Ficam:
- o mercado, com 1 get_neighbourhood_data com include_occupancy e include_prices; a resposta bruta guardada também serve à revisão quinzenal (SKILL.md:92);
- os concorrentes;
- os eventos.
O get_listings e o get_user_logs vão para a sessão.
(b) Os 7 refresh das 07:53 continuam todo dia, em série. São eles que destravam a gravação de D0 e D1, e são a única fonte com min_price, max_price, check_in, check_out e dso_flag (o get_listing_prices tem 19 chaves e não traz esses campos).
(c) mesclar_recalculo.py, modo novo 'montar': monta rooms.days de D0 a D60 a partir dos 7 refresh e copia também min_price, max_price, check_in, check_out, dso_flag e num_bookings, que o mesclar_quarto (39-50) hoje descarta. Dele leem o ocupacao.py, o validar_plano --precos (o piso vigente das linhas 430 e 941 deixa de vir vazio), o comparar e o concorrencia_hidro_vista.
(d) mesclar_recalculo.py, modo novo 'sobrepor': põe um get_listing_prices sobre o refresh da manhã, no formato pricing_array, para a leve sem saldo de recálculo. Nas noites gravadas depois do last_refreshed_at, ele apaga o min_price e o max_price, para o pace.vigente (365-379) usar a regra, porque hoje ele prefere o piso velho do recálculo.
(e) calendario_12m:
- o --efetiva fica opcional;
- guarda: quarto em que o min_stay do refresh difere do min_stay da regra em alguma noite relê só esse quarto pelo get_listing_prices;
- às segundas, --efetiva dos 7 quartos.
(f) Antes de ligar, repetir 06/11 com as duas fontes (r0611/precos_pl_*.json × rec_*.json). As saídas têm de ser iguais no ocupacao, no comparar, no pace e no calendario_12m.

**Risco e mitigação:** Riscos:
- Um refresh que falha (400 ou 429) deixa o quarto sem calendário novo.
- O caso da Balcony em 04/02/2027 (recálculo com estadia 1, efetiva 3) pode voltar em noite distante por até 6 dias.

Mitigações:
- No quarto sem refresh, vale o get_listing_prices com o 'sobrepor' sobre o calendário anterior, com aviso, e o validador continua barrando decisão por ocupação com calendário de mais de 6 h.
- Para a estadia, valem a guarda pela regra, a leitura completa de segunda e a auditoria diária do inventário de 12 meses do Beds24, que vê o minStay que vai ao canal.
- Em 06/11 o min_stay bateu em 2.562 de 2.562 noites.
- Ganho extra: acaba a cópia dos números feita pelos subagentes. Em 06/11 eles preencheram o min_price em só 2 de 7 quartos, e de forma diferente na Q2 e na VK7.
- Às segundas a economia é de 14, e não de 21.

**Arquivos:** Base /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/: /home/user/andrefelippeadfarias/.claude/workflows/coleta-pricelabs.js (31-64, 169-198, 218-232); scripts/mesclar_recalculo.py (26-109); scripts/calendario_12m.py (76-80, 138-155, 757-830); scripts/validar_plano.py (923-942); SKILL.md (63-73, 84, 91-93, 154); references/ferramentas.md (10, 28-34, 74)

### 4. Booking: buscar só onde a âncora de mercado está vencida (economia estimada: 20 chamadas/dia)

(a) O COMP_SCHEMA (js:109-149) ganha hidro_median, hidro_min, hidro_max e n_hidro. Hoje o ancora_mercado.py (77-80) só marca p50_medido com hidro_median, e o schema não tem esse campo, por isso as buscas da coleta não substituem a âncora medida com hidro.
(b) Novo comando ancora_mercado.py faltam --hoje --dias 60 --max 4. A ordem de prioridade é:
1. o próximo fim de semana, se a busca tiver mais de 3 dias;
2. feriado a até 30 dias sem p50 medido nos últimos 5 dias;
3. a noite com a âncora mais velha.
As noites com régua da ordem do dono ficam de fora.
(c) 1 busca HOT_TUB_JACUZZI por estadia. As 2 buscas dos nossos hotéis saem da coleta diária: a vitrine e a segurança ficam às 10:53 e o fator público na segunda.
(d) Pedido do dono, os eventos de segunda e a falta de número para a exceção de mercado disparam 1 busca extra da data.

**Risco e mitigação:** Riscos:
- A âncora fica mais velha: o rodízio é de cerca de 8 dias, e o motor já dá meio passo depois de 10.
- O p25 e o piso da âncora passam a sair do mínimo com hidro, que é mais alto que o mínimo geral.

Mitigações:
- Antes de ligar, comparar ancora_mercado ver com uma repetição de 06/11.
- Se o p25 subir demais, a busca geral fica só para o próximo fim de semana (+1 busca por dia).
- Meta: nenhuma noite de D0 a D30 com âncora de mais de 6 dias.

**Arquivos:** Base /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/: /home/user/andrefelippeadfarias/.claude/workflows/coleta-pricelabs.js (109-149, 198-209); scripts/ancora_mercado.py (53-123 e comando novo); references/mercado.md (45-55); references/mercado-diario.md; SKILL.md (40, 43, 65, 74, 107)

### 5. Uma gravação por quarto por rodada no PriceLabs, e teto solto sem delete (economia estimada: 9 chamadas/dia)

(a) Novo scripts/juntar_planos.py (ou validar_plano.py --juntar):
- junta por quarto e data os planos do pace, da estadia, da ordem_recanto, da concorrência e os itens pricelabs_tambem;
- campos diferentes se somam;
- o mesmo campo com valores diferentes dá ERRO; quem resolve é a precedência de regras.md, não o script;
- o validador roda uma vez, no plano junto.
(b) fatiar_payload.py (46-71): D0 e D1 vão na mesma chamada do resto quando o quarto tem recálculo de hoje, fora de 23:30 a 00:30. Data apagada na rodada nunca vai na mesma chamada.
(c) Teste único de max_price_type 'percent_max' com max_price '0' numa data distante (D300 da Q7). O tipo existe (as regras feitas pela tela em pricelabs/snapshot-configuracao-2026-09-25.json usam percent_min e percent_max), mas o efeito não foi medido.
- Se o refresh do dia seguinte mostrar o teto igual ao máximo do anúncio, o pace.ajustar_tetos (866-924) troca o item 'apagar' por um update. O validar_plano aceita percent_max só no teto e dá ERRO para teto abaixo do piso.
(d) Em 06/11, as chamadas cairiam assim:
- manhã: de 8 updates e 2 deletes para 7 updates;
- 10:53: de 4 chamadas (Q7 D0, Q7 D1, Double e Afrodite) mais 2 regravações para 3;
- Q7 liberado: de 2 para 1.

**Risco e mitigação:** Riscos:
- Uma data recusada derruba a chamada inteira do quarto.
- O percent_max pode ser lido como R$ 0.

Mitigações:
- Na recusa, voltar às fatias de hoje só naquele quarto.
- Para o percent_max, o teste em data distante é conferido na verification e no recálculo antes de qualquer uso.
- Se o teste falhar, o delete fica e a economia cai para 7.

**Arquivos:** Base /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/: scripts/juntar_planos.py (novo); scripts/validar_plano.py (960-1048); scripts/fatiar_payload.py (32-79); scripts/pace.py (866-924); SKILL.md (84, 109, 114-115); references/ferramentas.md (12-13, 83); scripts/test_scripts.py

### 6. Cache das regras por data dentro do dia (economia estimada: 7 chamadas/dia)

(a) Novo scripts/regras_cache.py, com 4 comandos:
- construir: monta o cache com as 7 leituras de 12 meses das 07:53, que continuam todo dia;
- aplicar --update/--delete <resposta>: corrige o cache pela verification de cada gravação;
- invalidar --quarto: quando o log mostrar ação sem api_;
- exportar --de --ate: gera o arquivo no formato do get_listing_date_overrides, que o pace.ler_regras (933-942) e o calendario_12m.ler_regras_por_data (83-97) já leem sem mudança.
(b) Nas leves, o --regras do pace sai do cache. Resposta de gravação não aplicada ou log manual fazem reler só aquele quarto (1 chamada).
(c) Guardar a primeira resposta de update para fixar o formato da verification: nenhum arquivo traz uma hoje.
(d) 1 semana em sombra: continuar relendo os 7 quartos às 10:53 e comparar com o cache.

**Risco e mitigação:** Risco: o cache ficar velho, por resposta perdida ou mudança pela tela que não apareça no log.

Mitigações:
- O cache é refeito toda manhã.
- O log invalida o quarto, e resposta ausente também.
- O validador barra cache mais velho que a última gravação do ledger.
- Qualquer diferença na sombra que não seja gravação nossa desliga o cache.

Prova com 06/11: a releitura das 10:53 diferiu da manhã em 28 datas, todas explicadas por gravações nossas, e nenhum dos 7 quartos tem regra com validade.

É uma economia de 7 em 06/11, mais 3 a 4 por venda nas leves.

**Arquivos:** Base /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/: scripts/regras_cache.py (novo); scripts/test_scripts.py; SKILL.md (37, 84, 109, 115); references/regras.md (168, 281); references/ferramentas.md (11-13)

### 7. Conferência pela resposta da gravação e pelo calendário que já vai ser lido (economia estimada: 1 chamadas/dia)

(a) Na hora: a verification de cada update e delete confere piso, teto e estadia gravados.
(b) A gravação da manhã termina antes de cerca de 08:40 BRT; o envio diário, por volta das 08:47, recalcula os 7 quartos sem gastar cota.
(c) Noites de D4 em diante: ler_recalculo.py --plano <plano de ontem> no refresh das 07:53 do dia seguinte, que já é lido de qualquer jeito.
(d) Noites de D0 a D3: get_listing_prices (D0 a D3) dos quartos com item nessas noites, às 10:53, sem cota. Para isso, o ler_recalculo (29-32) passa a aceitar esse formato. Os itens do motor não têm price_type (27 de 27 em 06/11), então o regra_inativa (57-58) já não usa o dso_flag: price e min_stay bastam.
(e) Leve que grava D0 ou D1 depois do envio: Sync Now ao dono e conferência na Booking 30 min depois, como hoje.
(f) 1 semana em sombra, conferindo dos dois jeitos.

**Risco e mitigação:** Riscos:
- Uma regra que gravou mas não pegou aparece em até cerca de 20 h nas noites de D4 em diante.
- Nas noites de D0 a D3, pode ficar até 2 h no canal antes de ser vista.

Mitigações:
- A verification na hora.
- Hoje nenhuma das 2.170 regras tem validade.
- O validador não muda.
- Correção com Sync Now.

Em 06/11 a economia real é 1 (a conferência da Afrodite). A conferência prescrita (SKILL.md:117) custaria até 7 recálculos, e de manhã ela não foi feita nos outros quartos. O passo torna a conferência obrigatória barata, em vez de pulada.

**Arquivos:** Base /home/user/andrefelippeadfarias/.claude/skills/gestao-receita-pousadas/: scripts/ler_recalculo.py (29-63); SKILL.md (34, 116-120, 155); references/regras.md (239); references/ferramentas.md (16-17)

## Riscos gerais e conferências

A conta, as checagens e os riscos que não dá para medir offline. Nada no repositório foi editado: só li arquivos e os rascunhos de 06/11 e rodei Python sobre eles, sem chamar API.

**A conta**
- **Antes, 153 chamadas em 06/11:**
  - PriceLabs: 84. São as 80 medidas, distribuídas assim:
    - 07:53: get_listing_prices 14, regras 14, refresh 8, update 8, delete 2, get_listing_data 2, reservas 1, logs 1, vizinhança 1 e get_listings 1;
    - 10:53: regras 7, refresh 6, update 8, mais 4 de status e reservas;
    - 13:53: 3.
    - Mais 2 estimadas em cada leve da tarde.
  - Beds24: cerca de 43 (5 lotes de cerca de 3 chamadas, as leituras repetidas e 4 por leve).
  - Booking: 26.
- **Depois, 54 com os mesmos eventos:** PriceLabs 33, Beds24 15 e Booking 6. A soma dos 7 passos dá 99.
- **Dia sem venda nas leves:** cerca de 45 chamadas.
  - 07:53: 35.
  - 10:53: 6.
  - 13:53, 16:53 e 19:53: 2 cada (rodada e inventário do Beds24).
- **Segunda:** mais 7 da estadia efetiva e a revisão semanal.

**O que conferi no código e nos dados**
- min_stay igual no refresh e no get_listing_prices em 2.562 de 2.562 noites.
- Preço igual em 366 de 366 noites nos 5 quartos lidos depois do refresh. Na VK7 e na VK2, lidas antes, só 98 e 68 noites bateram.
- O get_listing_prices tem 19 chaves e não traz piso, teto, chegada/saída nem dso_flag. Por isso o refresh das 07:53 fica: o calendario_12m.comparar (linha 315) e o ler_recalculo dependem dele.
- O mesclar_quarto não copia min_price nem max_price, e o piso vigente do validar_plano (430 e 941) fica vazio. Os subagentes preencheram o piso em só 2 de 7 quartos, e de jeitos diferentes.
- O auditar e o plano leem o Beds24 ao vivo sem --inventario.
- O janelas, o comparar e o concorrencia_hidro_vista leem as reservas cada um por conta própria.
- O movimento, o vigia e o reservas_abaixo fazem 3 GET iguais, e os dois últimos têm a classificação em funções puras.
- O editar-lote faz por lote: GET antes, token, POST e releitura.
- A âncora só marca p50_medido com hidro_median.
- Nenhum script lê contexto.bookings nem logs.
- A releitura das regras às 10:53 deu 28 diferenças, todas gravações nossas, e nenhuma regra tem validade.
- D0 e D1 vão em chamadas próprias no fatiar_payload.
- Os cabeçalhos de crédito existem no apiV2.yaml.

**O que não deu para provar offline (confirmar na primeira chamada)**
- Se o get_listings traz last_refreshed_at e push_enabled (o aprendizado de 07/10 confirma o last_date_pushed).
- O formato da verification: guardar a primeira resposta.
- O significado do percent_max: o teste em data distante decide.
- O expiresIn real do token do Beds24: o exemplo da spec é 3600 s, então não supor 24 h.
- Se o Real-Time Sync recalcula o quarto depois da venda.
- O recálculo do envio às 11:47 UTC vem da contagem do dia. Nos arquivos, o last_refreshed_at do contexto veio nulo em 5 quartos.

**Achados de passagem (corrigir junto)**
- O pace.vigente prefere o piso do recálculo ao da regra. Com o recálculo da manhã nas leves, o piso fica velho nas noites gravadas depois dele; o modo 'sobrepor' resolve isso.
- A conferência prescrita depois de gravar não foi feita em 6 dos 7 quartos em 06/11; o passo 7 a torna barata.

**Precisa do dono**
- Levar o mercado_diario para a segunda (ordem de 20/10).
- O inventário continua em toda rotina, então a ordem de 01/11 fica como está.
- As mudanças manuais passam a ser relatadas na rotina seguinte que gravar, e não mais em toda leve. Vale avisar o dono.

**Fase 2 (só depois de 2 semanas estáveis)**
- Vendas do Beds24 somadas ao recálculo da manhã no lugar do calendário do quarto vendido, em sombra (77% das noites de D0 a D60 têm piso igual ao teto).
- Detector de mudança nas regras pelo próprio refresh, no lugar da leitura diária: −7 por dia.
- Uma só busca por coordenadas para os dois hotéis: −1 por dia.

**Como implantar**
Cada passo leva um commit próprio, testes em test_scripts.py e uma entrada em historico-da-skill.md. Medir as chamadas por 2 dias antes do passo seguinte. Os passos 6 e 7 só entram depois de 1 semana em sombra com 100% de acordo.

**O que não muda**
- Validar antes de gravar, agora sobre o plano junto.
- Conferir depois: a verification na hora e o recálculo seguinte.
- Nunca preço no Beds24: o corpo_edicao continua barrando.
- Nunca mexer em reservas.
- Vigia de cancelamentos e de reserva com R$ 0 em toda rotina, agora numa leitura só.

## Travas que entram junto (independentes do plano)

- Contador de recálculos por quarto (janela móvel de 24 h, inclui tentativa que falhou) consultado antes de cada `refresh_listing_pricing`.
- Nunca repetir um recálculo que falhou sem saber o motivo; com 400 "Beds24 temporarily unavailable", esperar a próxima rodada.
- Chamadas ao PriceLabs sempre em série; com 429, esperar e não insistir.
- Aproveitar o recálculo que o próprio PriceLabs faz no envio diário (~08:47 BRT), que não gasta a cota.

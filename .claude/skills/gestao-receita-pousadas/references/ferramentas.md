# Ferramentas: como usar sem errar

## Carregar ferramentas
- As ferramentas MCP do PriceLabs, da Booking e do Claude Code Remote aparecem como "deferred". Carregue com ToolSearch, por exemplo `select:mcp__PriceLabs__get_listing_prices,mcp__PriceLabs__update_listing_date_overrides`.
- Os servidores caem com frequência. Quando um servidor desconectar, chame ToolSearch de novo (ele espera reconectar) e repita a chamada. Se cair 3 vezes seguidas, registre a rotina como incompleta e não grave nada pela metade.

## PriceLabs (pms "beds24")
| Ferramenta | Uso | Cuidados |
|---|---|---|
| `get_listing_prices` | preço calculado (`price`), último enviado (`user_price`), `min_stay`, `multi_unit_occupancy` ("vendidas/total"), `booking_status` | 60 dias dão cerca de 27 mil caracteres por quarto. Na Afrodite, `booking_status` preenchido conta como vendido. `user_price` -1 indica indisponível. |
| `get_listing_date_overrides` | substituições por data já existentes | Leia antes de gravar, para não perder política de feriado. |
| `update_listing_date_overrides` | gravar preço e estadia por data | Junta os campos com os que já existem (confirmado em 14/10: mandar só `min_price` preserva `price`, `min_stay` e `reason`). **Sempre `price_type` "percent"** (dono, 07/10) e, desde 14/10, **piso e teto também em percentual**: `min_price`/`max_price` com `min_price_type`/`max_price_type` "percent_base", que é a **variação sobre a base do anúncio** (−31 = base × 0,69; 61 daria base × 1,61: medido na VK2 em 14/10, base 1.270, −39 → R$ 775). Nunca "fixed" (o validador converte; o dono proibiu valores absolutos). Para ficar abaixo do mínimo do anúncio, o `min_price` da data vai no percentual que dá o valor desejado. `currency` "BRL" pode ir junto, a API descarta. A resposta traz "verification": confira. `scripts/pisos_percentual.py gerar/conferir` converte e confere regras antigas. |
| `delete_listing_date_overrides` | apagar substituição (saída da escada) | Apaga a data inteira, inclusive a estadia mínima. Regrave a estadia do dia de chegada (dono 04/11: segunda, quarta, sexta e sábado com min_stay 2; domingo, terça e quinta 1; noite órfã 1) e as políticas de feriado e de pacote. Sem isso, a noite cai na estadia padrão do PriceLabs, que varia. |
| `update_listing_data` | base, mínimo e máximo do anúncio | Limite de ±15% por semana. Atualize `dados/quartos.json` no mesmo commit. |
| `get_customizations` / `update_customizations` | personalizações | Guarde o JSON de antes no registro, para poder desfazer. |
| `refresh_listing_pricing` com `parse_reasons_json: true` | detalhamento do preço por data (cerca de 2 milhões de caracteres) | Traz `reasons_json.listing_info.customized_price`, o recomendado sem a substituição, e os passos: ajustes de ocupação, substituição da data, suavização e piso/teto. Use para calcular o percentual (`scripts/percentual.py`). Conta no limite de 3 por quarto por dia. |
| `refresh_listing_pricing` | recalcular para conferir | Só 3 por quarto a cada 24 h. A saída (cerca de 280 mil caracteres) vai para um arquivo; leia com `scripts/ler_recalculo.py`. Recalcular **não envia** aos canais. Use `exclude_reasons_json: true`. |
| `get_listing_data` | `push_enabled`, `last_date_pushed`, min, base e max | Não use para buscar quarto. Alerta se o último envio tiver mais de 30 h. |
| `get_pms_reservations` | reservas por data de criação (`booked_start_date`) ou por estadia | `check_out` é a última noite (inclusivo). `total_cost` é o valor pago. Comissão 0,0 é normal nos lotes. A lista só recebe as reservas novas por volta das 10:59 BRT (até cerca de um dia depois da venda): ao procurar vendas, use `booked_start_date` de 2 dias atrás e compare por ID com a coleta anterior e pelo calendário; cancelamento de reserva antiga só aparece no calendário. |
| `get_user_logs` | mudanças nas últimas 24 h | Ação `api_*` com device "mcp" é nossa. O resto é manual (pela tela). |
| `get_neighbourhood_data` | ocupação do mercado | Os números ficam aninhados em `data.data.occupancy.daily`. Os percentis por dia não vêm. |

Não existe API para "Sync Now". O envio aos canais é diário (em 06/10, às 08:48) ou pela tela. Recalcular não envia, e o envio extra pelo Real-Time Sync só vem com evento do PMS (reserva, cancelamento ou bloqueio). Por isso, mudança gravada depois do envio diário só chega aos canais no dia seguinte. Quando a mudança mexer em D0 a D3 ou for um corte grande, peça ao dono o "Sync Now" (PriceLabs → Multicalendário → marcar os quartos → Sync Now; chega em até 15 min).

## Booking.com (`mcp__Booking_com__accommodations_search`)
Veja como pesquisar em `mercado.md`. Não serve para mudar nada.

## Workflow de coleta (`.claude/workflows/coleta-pricelabs.js`)
- Chame `Workflow` com `name: "coleta-pricelabs"` e `args`:
  `{"hoje": "AAAA-MM-DD", "fim": "<hoje+59>", "desde": "<hoje − 2 dias>", "modo": "rotina" | "completo", "buscas": [{"label", "checkin", "checkout"}], "eventos": false}` (a lista de reservas atrasa até um dia: compare por ID com a coleta anterior e pelo calendário).
- Se o nome não for encontrado, use `scriptPath: ".claude/workflows/coleta-pricelabs.js"` (a partir da raiz do repositório).
- Roda em segundo plano. Quando terminar, a notificação traz o caminho do arquivo de saída (`.../tasks/<id>.output`). Passe esse arquivo para `scripts/ocupacao.py` e `scripts/validar_plano.py --precos`.
- O modo "completo" acrescenta o mercado e, se `buscas` vier preenchido, os concorrentes; `eventos: true` acrescenta a pesquisa web. É bem mais caro; use 1 vez ao dia (07:53) ou em pedido do dono.
- Se o Workflow não estiver disponível, chame `get_listing_prices` dos 7 quartos direto. A leitura é mais pesada, mas funciona.

## Scripts (rodar da pasta `scripts/`)
- `python3 percentual.py DETALHE.json Q7=<refresh com reasons> ...` grava o detalhe; `python3 percentual.py --alvos alvos.json --detalhe DETALHE.json` calcula o percentual e o piso por bloco de suavização e simula o preço. Avisa quando o percentual "vaza" para datas do bloco sem alvo.
- `python3 ocupacao.py <saida_do_workflow> [--noites 14] [--json]`: tabela de janelas contra as metas e mapa das noites.
- `python3 validar_plano.py plano.json --precos <saida_do_workflow> --payload`: barra erros; com `--payload`, devolve os pedidos prontos por quarto.
- `python3 ler_recalculo.py --plano plano.json Q7=<arq> VK7=<arq> ...`: compara o recalculado com o plano.
- `python3 mesclar_recalculo.py <saida_do_workflow> --velhos [--horas 6]` lista os quartos com recálculo velho; `python3 mesclar_recalculo.py <saida> <mesclado.json> VK7=<arq> ...` junta os recálculos novos (preço, enviado, estadia e ocupação) no arquivo da coleta.
- `python3 beds24.py teste | reservas --de --ate | janelas --hoje`: leitura direta do Beds24 (API V2, só leitura, sem dados de hóspedes), com `BEDS24_TOKEN` (token de longa duração) ou `BEDS24_REFRESH_TOKEN` nos segredos do ambiente. Sem a variável, sai com ERRO e a Skill usa o PriceLabs.
- `python3 concorrencia_hidro_vista.py --coleta <saida mesclada> --hoje <hoje> --saida plano_comp.json [--sem-beds24]`: plano de aproximação da referência hidro+vista (`regras.md` seção 5) a partir de `dados/concorrentes_hidro_vista.json`; ocupação pelo Beds24, noite bloqueada fora, Queen (2) sem corte, Balcony pela Regra G. Sempre validar depois.
- `python3 -I base_booking_pdf.py ARQUIVO.pdf --noites N [--json saida.json] [--rodada plan.json --checkin AAAA-MM-DD --checkout AAAA-MM-DD --captado ISO8601]`: com `--rodada`, acrescenta as capturas ao `captures.csv` da rodada da skill `booking-hotel-research` (uma linha por hotel do cadastro encontrado no PDF).
- (antes) `python3 -I base_booking_pdf.py ARQUIVO.pdf --noites N [--json saida.json]`: lê o PDF da busca filtrada da Booking que o dono manda (`mercado.md`) e devolve a mediana do quarto com hidro dos concorrentes e o percentil do Recanto e da Villa. Precisa de pymupdf.
- `python3 mercado_diario.py planejar|consolidar --hoje AAAA-MM-DD ...`: busca diária dos concorrentes do perfil (`mercado-diario.md`).
- `python3 reservas_abaixo.py --desde AAAA-MM-DDTHH:MMZ [--nomes] [--json saida.json]`: reservas novas ou alteradas abaixo de 70% do esperado (price1 × fator do canal). O JSON não leva nomes.
- `python3 -m unittest test_scripts` (ou `python3 test_scripts.py`): testes dos scripts.
- Rascunhos de plano e cópias de saída vão para a pasta de rascunho (scratchpad) da sessão, nunca para o repositório.

## Git
- Branch: `claude/pricelabs-hotel-occupancy-ku6zn5`, que é a branch padrão do repositório; não há PR a abrir.
- Mensagem de commit "PriceLabs: <resumo>", com as linhas de atribuição pedidas pelo sistema.
- Faça push com `git push -u origin claude/pricelabs-hotel-occupancy-ku6zn5`. Em erro de rede, tente de novo até 4 vezes (2 s, 4 s, 8 s, 16 s).

## Padrão de qualidade e suavização (08/10)
- `validar_plano.py`: o item de sexta, sábado ou feriado de suíte com banheira com preço ou piso abaixo de R$ 1.000 (`limites_seguranca.padrao_qualidade_diaria`, só o dono muda; ausente ou zerado dá ERRO) dá ERRO, a menos que leve o campo `mercado` (texto de pelo menos 20 caracteres com o dado de mercado). Com o campo, sai um aviso para o relatório. O campo não vai para o PriceLabs. O teto (`max_price`) sozinho também conta. A Balcony não entra. O `percentual.py` já usa o padrão como piso de sexta e sábado das suítes com banheira.
- Suavização (Smoothing) não se lê nem se grava por nenhuma ferramenta. Só se enxerga pelos blocos de `price_smoothing` no `refresh_listing_pricing` com `parse_reasons_json`. Mudança só pela tela (ver `regras.md` seção 7).

## O que os scripts leem de `quartos.json` (e o que é só regra de texto)
- Lidos por script: `metas` (ocupacao.py, faixas_de_preco.py), `limites_seguranca` (validar_plano.py), `parametros.regra_i_dias`, `regra_i_desconto_max`, `regra_g_desconto` (validar_plano.py, ler_recalculo.py), `quartos[*].min/base/min_referencia/fator_real/fator_vitrine/fator_vitrine_fds/banheira/regra_i/sem_desconto/demanda/unidades/pousada` (validar_plano.py, faixas_de_preco.py), `feriados` (validar_plano.py, faixas_de_preco.py), `referencia_semanal` (validar_plano.py), `excecoes_mercado` (validar_plano.py avisa vencidas).
- Só texto (regras.md): `regra_f_saida_vendido`, `regra_c_desconto`, `regra_a_janela_dias`, `piso_feriado_real_padrao`. Mudar o JSON não muda o validador nesses casos. **`regra_a_janela_dias` está fora de uso desde 05/11:** a ordem do dono de 04/11 tirou a 1 noite de última hora.
- Estadia pelo dia de chegada (dono 04/11, desde 05/11): `parametros.estadia_por_dia_chegada` (`ativa`, `desde`, `por_dia`, `orfa_noite_seguinte`, `aguardando_dono`, `ordem_id`) é lido por `comum.py`, `validar_plano.py`, `calendario_12m.py` e `pace.py`. Mudar `por_dia` muda o validador e as auditorias.

## Painel semanal (painel "Desempenho Semanal das Pousadas", desde 21/10/2026)
- Link: https://claude.ai/artifact/HzGExqdFhYp3CnTe6pvWBf (tipo Dashboard; privado até o dono compartilhar pelo menu Share).
- Toda segunda, na revisão semanal: `python3 scripts/painel_semanal.py --saida <rascunho>/painel` gera `semanas.json`, `estadias.json`, `metas.json`, `canais.json` e `quartos.json` (sem nomes de hóspedes).
- Para cada arquivo, faça o upload com `Artifact` (`url` do painel, `file_path`, `asset: true`; um arquivo por chamada). Depois, num `ArtifactData` batch, faça `update` em `datasets/<nome>` com `source.url` = a url devolvida, `source.name` e `updated: {at, by}`, sempre com o `if_version` lido antes.
- Apague os uploads antigos (`Artifact` `delete` com `url` e `path` = id do asset) só depois de gravar os novos.
- A página (`files/index.html`) não muda a cada semana; ela lê os arquivos. Mudou a página? Leia `data/users/me/check` (até 5 leituras).
- O Beds24 não é conector do painel: os dados são arquivos, e por isso o painel mostra a data da última atualização (fica "velho" depois de 336 h).

## Calendário de 12 meses (`calendario_12m.py`, 21/10/2026)
- Fonte: o recálculo do PriceLabs (`refresh_listing_pricing`), que traz 541 noites por quarto com `min_price`, `min_stay`, `check_in` e `check_out` efetivos e `dso_flag`. Não precisa ler as regras por data uma a uma.
- `auditar` compara cada noite com a regra; `plano` gera os itens só do que falta, no formato do `validar_plano.py`. A Queen (2) vai com `excecao: "dono"` nos pisos (ordem de 20/10, "Serve para todo o piso"): o piso protege, não é desconto.
- O plano começa em hoje + 4, para não mexer no que a rotina decide de D0 a D3.
- **Chegada e saída no PriceLabs:** a gravação por data (`check_in_check_out_enabled`, `check_in`, `check_out`, 7 caracteres de segunda a domingo) só é aceita com a personalização de check-in/check-out ligada na tela. Sem ela: erro 400 e o lote inteiro é recusado. A API de personalizações não tem essa chave. Por isso a restrição fica só no Beds24 (a opção `--restricoes-pricelabs` saiu em 01/11: com a Regra H nova o sábado não fecha a chegada, e não há o que espelhar no PriceLabs).
- **Beds24:** `/inventory/rooms/calendar` com `includeOverride=true` dá `noCheckIn`, `noCheckOut` e `noCheckInOrCheckOut` por noite. Um quarto pode vir em várias páginas: junte por quarto (`beds24.inventario` já junta).
- **Regra H no Beds24 (01/11):** `classificar_beds24` lista por quarto os sábados com a chegada fechada (com a troca de `parametros.regra_h_beds24.troca_sabado`: noCheckIn → none, noCheckInOrCheckOut → noCheckOut), os sábados com estadia abaixo de 2 e as outras noites com restrição. `--plano-beds24 <arquivo>` grava o lote para `beds24.py editar-lote` (só `override`, nunca estadia, disponibilidade ou preço). **(Estadia substituída em 05/11:** o lote leva também `min_stay`, ver abaixo; disponibilidade e preço continuam fora.)
- **Estadia por dia de chegada no Beds24 e no PriceLabs (dono 04/11, desde 05/11):** `classificar_beds24` devolve por quarto `estadia_dia_chegada` (segunda, quarta e sexta 2; domingo, terça e quinta 1; temporada acima vale; feriado e pacote fora) e o lote leva o `min_stay` com `pricelabs_tambem` (grave também no PriceLabs por data, senão o envio desfaz). Antes, segunda e quarta saíam com 1 (`semana_estadia_acima`) e a sexta não era auditada. O plano do PriceLabs (`plano`) regrava a estadia nos dois sentidos pela `validar_plano.estadia_exigida`, que agora tem a regra por dia.
- **Noite órfã pela noite seguinte (dono 04/11, "libera órfã"):** noite livre no quarto com a noite seguinte esgotada nele, fora de feriado, pacote e sábado, leva 1 (`validar_plano.estadia_alvo`; item com `orfa` "seguinte" e `orfa_prova`, que o validador confere). **Fonte única (revisão 05/11):** com o inventário do Beds24 (`--inventario` ou a leitura ao vivo), `calendario_12m.Ocupacao` decide a noite livre/esgotada no plano do PriceLabs e no lote do Beds24 (`numAvail` > 0 / <= 0) e a prova sai dele (`fonte` "beds24", `lido_em`); o recálculo só sem inventário. A estadia de hoje sai de `--efetiva` (get_listing_prices). A leitura do inventário vai até `--ate` + 1, para ver a noite seguinte do último dia. Na Afrodite (1 unidade) o `mu_occupancy` vem "-1": esgotada se lê por `num_bookings` (`comum.vendidas_total`). O sábado órfão segue em `orfas_estadia` (ordem de 02/11). A sexta órfã saiu de `orfas_estadia` em 05/11: é a noite órfã geral.
- **Ordens do dono e dia órfão (01/11):** piso abaixo do padrão numa noite coberta por ordem `piso_por_data`/`alvo_por_data`, e não abaixo do piso da ordem, sai como `info` (sem item); estadia 1 entre duas noites esgotadas (dia órfão do PriceLabs) também, e desde 05/11 a noite órfã já com 1 (o item não leva estadia: antes levava a exigida e desfazia a órfã quando o piso faltava). Só `falta` gera item no `plano`.
- Gravação grande: lotes de até 70 noites por chamada, por quarto, com D0 e D1 em chamadas próprias: `validar_plano.py <plano> --fatias <pasta>` (ou `fatiar_payload.py --payload <saída do --payload> --hoje <hoje> --saida <pasta>`) grava um arquivo por chamada e `fatias.json` na ordem de gravação. No Beds24, `--lotes-estadia` sai por pousada (urgente de hoje a D14, depois um por quarto) e `beds24.py editar-lote` confere o `min_stay_antes` de cada item (pula e relata o que mudou) e grava em blocos de até 100 faixas (`--max-faixas`), relendo cada bloco. Depois, 1 recálculo por quarto e `calendario_12m.py auditar` deve dar 0 noites.

## Promoções da Booking pelo Beds24 (verificado em 03/11/2026)
Pedido do dono (03/11): integrar as promoções da Booking (painel do Beds24, `pagetype=syncroniserbookingcomxmlpromotions`) aos planos; "Verificar na documentação a api". Pesquisa em 4 fontes públicas, com verificação cruzada:
- **A API do Beds24 não gerencia promoções da Booking** (v2, v1 JSON/XML, wiki e changelogs). A spec v2 (`api.beds24.com/v2/apiV2.yaml`) não cita "promot" em lugar nenhum. `POST /channels/booking` só faz reportInvalidCard, reportNoShow e reportCancel; `/channels/settings` só aceita Airbnb, Vrbo e iCal.
- A Promotions API da Booking existe, mas só para channel manager certificado (o Beds24 a usa por trás do painel). A Booking não aceita conexão direta de propriedade.
- **Só no painel do Beds24 ou na extranet:** criar, ler, alterar e desligar promoções (Basic, Last minute, Early booker, Mobile, Country), Genius, mapeamento de quartos e tarifas, criar rate plans e o multiplicador do canal Booking.
- **Alternativas programáveis (efeito parecido no preço, sem selo de promoção):**
  - preço por data numa linha de Daily Price mapeada a um rate plan da Booking;
  - multiplicador diário do calendário (vale para todos os canais);
  - linha de Daily Price só da Booking ligada à base com `offsetMultiplier`, alterável por `POST /properties` (a regra precisa ser criada antes no painel);
  - regras com `min/maxDaysUntilCheckin` em rate plan separado.
  Todas mexem em preço no Beds24, o que hoje é proibido (preço só pelo PriceLabs): só com ordem do dono.
- **Razões medidas hoje no Recanto, efeito das promoções ativas:** 0,5557 a até 7 dias, 0,6327 de 8 a 14 dias, 0,6156 de 15 dias em diante e cerca de 5% a menos em estadia de 2 noites. Isso sugere Last minute de cerca de 12% (até 7 dias), Early booker de cerca de 3% (15 dias ou mais) e desconto de cerca de 5% para 2 noites ou mais. A confirmar no painel.


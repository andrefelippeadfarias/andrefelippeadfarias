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
| `delete_listing_date_overrides` | apagar substituição (saída da escada) | Apaga a data inteira, inclusive a estadia mínima. Regrave o sábado com min_stay 2 (Regra H) e as políticas de feriado. |
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
- `python3 -m unittest test_scripts`: testes dos scripts.
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
- Só texto (regras.md): `regra_f_saida_vendido`, `regra_c_desconto`, `regra_a_janela_dias`, `piso_feriado_real_padrao`. Mudar o JSON não muda o validador nesses casos.

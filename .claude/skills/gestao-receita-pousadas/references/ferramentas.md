# Ferramentas: como usar sem errar

## Carregar ferramentas
- As ferramentas MCP do PriceLabs, da Booking e do Claude Code Remote aparecem como "deferred". Carregue com ToolSearch, por exemplo `select:mcp__PriceLabs__get_listing_prices,mcp__PriceLabs__update_listing_date_overrides`.
- Os servidores caem com frequência. Quando um servidor desconectar, chame ToolSearch de novo (ele espera reconectar) e repita a chamada. Se cair 3 vezes seguidas, registre a rotina como incompleta e não grave nada pela metade.

## PriceLabs (pms "beds24")
| Ferramenta | Uso | Cuidados |
|---|---|---|
| `get_listing_prices` | preço calculado (`price`), último enviado (`user_price`), `min_stay`, `multi_unit_occupancy` ("vendidas/total"), `booking_status` | 60 dias dão cerca de 27 mil caracteres por quarto. Na Afrodite, `booking_status` preenchido conta como vendido. `user_price` -1 indica indisponível. |
| `get_listing_date_overrides` | substituições por data já existentes | Leia antes de gravar, para não perder política de feriado. |
| `update_listing_date_overrides` | gravar preço e estadia por data | Junta os campos com os que já existem. Preço fixo pede `currency` "BRL". Abaixo do mínimo do anúncio, mande `min_price` igual ao preço e `min_price_type` "fixed". A resposta traz "verification": confira. |
| `delete_listing_date_overrides` | apagar substituição (saída da escada) | Apaga a data inteira, inclusive a estadia mínima. Regrave o sábado com min_stay 2 (Regra H) e as políticas de feriado. |
| `update_listing_data` | base, mínimo e máximo do anúncio | Limite de ±15% por semana. Atualize `dados/quartos.json` no mesmo commit. |
| `get_customizations` / `update_customizations` | personalizações | Guarde o JSON de antes no registro, para poder desfazer. |
| `refresh_listing_pricing` | recalcular para conferir | Só 3 por quarto a cada 24 h. A saída (cerca de 280 mil caracteres) vai para um arquivo; leia com `scripts/ler_recalculo.py`. Recalcular **não envia** aos canais. Use `exclude_reasons_json: true`. |
| `get_listing_data` | `push_enabled`, `last_date_pushed`, min, base e max | Não use para buscar quarto. Alerta se o último envio tiver mais de 30 h. |
| `get_pms_reservations` | reservas por data de criação (`booked_start_date`) ou por estadia | `check_out` é a última noite (inclusivo). `total_cost` é o valor pago. Comissão 0,0 é normal nos lotes. |
| `get_user_logs` | mudanças nas últimas 24 h | Ação `api_*` com device "mcp" é nossa. O resto é manual (pela tela). |
| `get_neighbourhood_data` | ocupação do mercado | Os números ficam aninhados em `data.data.occupancy.daily`. Os percentis por dia não vêm. |

Não existe API para "Sync Now". O envio aos canais é diário (no começo de outubro, por volta das 09:43) ou pela tela.

## Booking.com (`mcp__Booking_com__accommodations_search`)
Veja como pesquisar em `mercado.md`. Não serve para mudar nada.

## Workflow de coleta (`.claude/workflows/coleta-pricelabs.js`)
- Chame `Workflow` com `name: "coleta-pricelabs"` e `args`:
  `{"hoje": "AAAA-MM-DD", "fim": "<hoje+59>", "desde": "<data da última rotina>", "modo": "rotina" | "completo", "buscas": [{"label", "checkin", "checkout"}], "eventos": false}`.
- Se o nome não for encontrado, use `scriptPath: ".claude/workflows/coleta-pricelabs.js"` (a partir da raiz do repositório).
- Roda em segundo plano. Quando terminar, a notificação traz o caminho do arquivo de saída (`.../tasks/<id>.output`). Passe esse arquivo para `scripts/ocupacao.py` e `scripts/validar_plano.py --precos`.
- O modo "completo" acrescenta o mercado e, se `buscas` vier preenchido, os concorrentes; `eventos: true` acrescenta a pesquisa web. É bem mais caro; use 1 vez ao dia (07:53) ou em pedido do dono.
- Se o Workflow não estiver disponível, chame `get_listing_prices` dos 7 quartos direto. A leitura é mais pesada, mas funciona.

## Scripts (rodar da pasta `scripts/`)
- `python3 ocupacao.py <saida_do_workflow> [--noites 14] [--json]`: tabela de janelas contra as metas e mapa das noites.
- `python3 validar_plano.py plano.json --precos <saida_do_workflow> --payload`: barra erros; com `--payload`, devolve os pedidos prontos por quarto.
- `python3 ler_recalculo.py --plano plano.json Q7=<arq> VK7=<arq> ...`: compara o recalculado com o plano.
- `python3 -m unittest test_scripts`: testes dos scripts.
- Rascunhos de plano e cópias de saída vão para a pasta de rascunho (scratchpad) da sessão, nunca para o repositório.

## Git
- Branch: `claude/pricelabs-hotel-occupancy-ku6zn5`, que é a branch padrão do repositório; não há PR a abrir.
- Mensagem de commit "PriceLabs: <resumo>", com as linhas de atribuição pedidas pelo sistema.
- Faça push com `git push -u origin claude/pricelabs-hotel-occupancy-ku6zn5`. Em erro de rede, tente de novo até 4 vezes (2 s, 4 s, 8 s, 16 s).

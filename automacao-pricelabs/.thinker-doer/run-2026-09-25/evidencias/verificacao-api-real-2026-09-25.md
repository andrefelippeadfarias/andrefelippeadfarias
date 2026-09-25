# Verificação com as chaves reais (25/09/2026, só leitura)

As chaves foram passadas pelo dono e usadas numa sessão temporária, fora do Git. Nenhuma chave, e-mail ou dado de hóspede está neste arquivo. Nenhuma escrita foi feita na conta: o cliente rodou com `escritas=frozenset()`, e a lista de escritas da sonda saiu vazia.

## Achado crítico, corrigido na revisão 8 da T1

- **Sintoma:** todo pedido à PriceLabs voltava **HTTP 403** com a mensagem "the site owner has blocked access based on your browser's signature".
- **Causa:** o firewall (Cloudflare) da `api.pricelabs.co` recusa a assinatura padrão do Python, `User-Agent: Python-urllib/3.x`.
- **Impacto se não fosse pego:** a primeira execução no PC do dono falharia em 100% das chamadas. O ensaio por replay não passa pela rede e não tinha como ver isso.
- **Correção:**
  - `pacing/rede.py` passa a enviar `User-Agent: automacao-pricelabs/0.1` em todo pedido.
  - O teste `test_envia_assinatura_propria` em `tests/test_infra.py` impede a regressão.

## Sonda depois da correção (`Rede` + `PriceLabs` do próprio programa)

| Rota | HTTP | O que voltou |
|---|---|---|
| GET `/listings` | 200 | 7 de 7 listings do `config.json`, todas com `push_enabled=true`, moeda BRL, `last_date_pushed` de hoje |
| POST `/listing_prices` | 200 | 7 listings, 60 datas cada. Uma (Queen Spa 7) veio com `LISTING_NO_DATA` durante um recálculo; ver abaixo |
| GET `/listing_metrics` | 200 | formato `{"data": {"listing_level", "market_level"}}`; mercado 7 dias lido = 27,5% |
| GET `/reservation_data` | 200 (5 páginas) | 430 reservas (180 booked, 250 cancelled) na janela hoje−14 a hoje+7; a chave é de dono |
| GET `/listings/{id}/overrides` | 200 | 4 substituições manuais já existentes na Queen Spa 7; nenhuma com o motivo `auto-jev` |

### Formatos confirmados nos dados reais

- `check_out` é a **última noite**, e não o dia da saída: vale em 430 de 430 reservas.
- `cancelled_on` vem como meia-noite UTC (`AAAA-MM-DDT00:00:00.000Z`).
- `booked_date` vem com hora UTC.
- Substituições criadas pela interface espelham o valor em `min_price`/`max_price` (`percent_min`/`percent_max`).

### `LISTING_NO_DATA` passageiro

- **O que aconteceu:** a Queen Spa (7) respondeu `LISTING_NO_DATA` com `last_refreshed_at` nulo às 17:09:33Z. A consulta seguinte mostrou o recálculo concluído às 17:09:34Z, com ocupação por unidade (`multi_unit_occupancy`) normal.
- **Causa provável:** o recálculo que a PriceLabs dispara depois de uma troca de preço base.
- **Comportamento do programa:** `ler_calendario` marca o erro, `alertas_gerais` emite "calendário indisponível" (alerta grave), e o gate de candidatos exclui a listing. Nenhuma ação foi tomada sobre dados incompletos.

## Jev pelo OpenRouter

| Item | Resultado |
|---|---|
| Chave | válida, `is_free_tier=true`, 0 créditos |
| `typesafe/jev-1.13:free` | **0 servidores**: a chamada devolve HTTP 404 |
| `typesafe/jev-1.13` (pago) | 1 servidor (TypeSafe), US$ 0,042 por milhão de tokens |

Com o modelo gratuito fora do ar, o gate do Jev falha fechado: o relatório fica amarelo e nada é criado. Para ligar as decisões, basta trocar `"modelo"` no `config.json` para `typesafe/jev-1.13` e ter crédito no OpenRouter. O custo estimado é de US$ 0,01 por mês, com 7 chamadas por dia.

## `verificar` e `executar` (modo observar) com as chaves reais

| Comando | Resultado |
|---|---|
| `verificar` | falhou só em itens esperados fora do PC do dono: fuso do contêiner (UTC em vez de UTC−3), "nenhuma execução anterior" e Jev gratuito indisponível |
| `executar` | **AMARELO**, com zero escritas e alertas determinísticos (inclui conflito calendário × reservas na Balcony em 25 e 26/09) |

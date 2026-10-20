# Cadastro, planejamento e capturas

Leia ao cadastrar concorrentes, gerar a matriz ou normalizar resultados. Os campos abaixo são um contrato local; não representam parâmetros de uma API.

## Cadastro dos concorrentes

Mantenha uma chave própria estável por propriedade. Registre nome, cidade, endereço ou outro dado de desambiguação, URL canônica, ID da Booking quando observado e características a pesquisar. Guarde a evidência de identidade antes de usar um ID externo.

Mantenha um perfil de comparação com requisitos comuns e particularidades por hotel. Para cada característica, registre:

| Campo | Uso |
|---|---|
| `criterion` | Descrição concreta: piscina aquecida, estacionamento gratuito, suíte com hidro |
| `scope` | `property`, `room` ou `rate` |
| `required` | Obrigatório ou preferência |
| `status` | `confirmed`, `not_met`, `unverified` |
| `evidence_ref`, `source_url`, `checked_at` | Referência local ou caminho do campo retornado, URL e horário com fuso |

Exemplos de condição específica: estacionamento sujeito a reserva; pets apenas em certas categorias; ar-condicionado no quarto; café com valor adicional. Preserve essas condições. Declaração explícita de ausência permite `not_met`; falta de menção significa `unverified`. Não infira quartos acessíveis pela acessibilidade das áreas comuns. Estrelas e nota de hóspedes são métricas diferentes.

## Entrada do planejador

Use [brief-example.json](brief-example.json) como exemplo sintético, substituindo hotéis e datas. O helper aceita Python 3.10+ e usa somente a biblioteca padrão.

- `schema_version`: número inteiro `1`.
- `currency`: código de 3 letras maiúsculas, como `BRL`; valide se é uma moeda aplicável antes da captura.
- `occupancy`: `adults` e `rooms` inteiros positivos; `children_ages` lista de idades de 0 a 17. É a ocupação total da pesquisa, não de cada quarto. A distribuição por quarto, quando necessária, fica no briefing e deve ser verificada na fonte.
- `hotels`: lista não vazia com `hotel_key` único, `name`, `booking_url` HTTPS em `booking.com`/`www.booking.com` com caminho `/hotel/...html`, e `required_features` opcional como lista de textos. URL sintaticamente válida não prova existência nem identidade.
- `stays`: lista de objetos `checkin`/`checkout` em `YYYY-MM-DD`.
- `range`: alternativa ou complemento de `stays`, com `start` e `end` inclusivos para check-ins, `nights` positivo, `step_days` positivo (padrão 1) e `weekdays` opcional (segunda=0 a domingo=6). O filtro de dia da semana se aplica às datas geradas pelo passo. O checkout pode ultrapassar `end`.
- `max_jobs`: inteiro positivo opcional; padrão 500. Não é orçamento de chamadas.

Combine `stays` e `range` quando necessário. Períodos repetidos são deduplicados; chaves de hotel repetidas são rejeitadas. Durações diferentes são estadias diferentes. O helper rejeita passado relativo a `--today`; para usar a data correta do usuário, passe essa opção. Sem ela, usa a data local do processo, que pode divergir do fuso do usuário. Ele admite hoje; o fornecedor pode impor check-in futuro ou outro limite, a ser respeitado no adaptador.

O resultado tem uma job por hotel × estadia e ID estável dos parâmetros. O helper remove query e fragmento das URLs para não carregar datas antigas ou identificadores de sessão. Campos desconhecidos na configuração são rejeitados: mantenha categorias, preferências e condições detalhadas num perfil ao lado do plano. Para retomar uma rodada, leia o plano existente. Para outra rodada, use outra pasta; o helper recusa substituir seus dois arquivos de saída. Seu CSV é um modelo vazio: `pending` não comprova consulta. O helper não aplica filtros nem valida ofertas.

## Observações de preços

Guarde JSON ou CSV, uma linha por oferta e por momento de consulta. Replique o `job_id` em ofertas da mesma célula. Para falhas, mantenha pelo menos uma linha com estado e motivo. O modelo CSV contém os campos centrais; acrescente os complementares abaixo quando necessário.

| Grupo | Campos |
|---|---|
| Identidade e consulta | `run_id`, `job_id`, `hotel_key`, nome/URL, `checkin`, `checkout`, `nights`, `adults`, `rooms`, `children_ages` |
| Fonte e momento | `captured_at` ISO 8601 com offset, `source_url`, `source_type`, `evidence_ref`, eventual ID da requisição sanitizado |
| Oferta | `offer_id` observado ou chave local declarada, `room_name`, `rate_plan`, ocupação confirmada e quantidade total de quartos |
| Preço | `price_raw`, `price_basis`, `currency`, `total_displayed`, `excluded_charges`, `total_all_in`, `tax_status` |
| Condições | `breakfast`, `cancellation`, `payment`, `discount_eligibility`, `comparison_profile`, `criteria_status` |
| Resultado | `status`, `availability_reason`, `notes` |

Valores monetários normalizados são decimais com ponto e moeda separada, calculados com `Decimal` ou equivalente. Preserve o texto original para distinguir formatos `1.234,56` e `1,234.56`. Símbolos ambíguos como `$` não permitem inferir USD. Na dúvida, mantenha valor bruto e peça verificação da moeda/base.

`total_displayed` é o total da estadia para toda a ocupação, somente quando essa base estiver comprovada. Se a fonte exibir apenas uma diária, guarde o bruto e a base; não preencha o total multiplicando noites. Uma captura com preço parcial deve explicitar essa limitação.

`total_all_in` inclui o total exibido e cobranças obrigatórias adicionais conhecidas para toda a ocupação e período. Não some novamente tributos já incluídos. Não inclua extras opcionais, caução reembolsável ou estimativa de taxa desconhecida. Se faltar uma cobrança obrigatória, deixe o total final vazio/null e `tax_status=unknown` ou `partial`. `tax_status=complete` exige evidência da composição; ausência de campo de taxa não equivale a taxa zero.

No JSON, desconhecido é `null`; no CSV, célula vazia. Zero só quando foi realmente observado. `cancellation` deve conter prazo, fuso e penalidade observados; “cancelamento grátis” em um selo genérico não garante a condição da oferta selecionada. Diferencie café disponível de incluído, pagamento antecipado de cobrança na hospedagem, descontos públicos de Genius/login/mobile.

Guarde evidência suficiente para reavaliar os campos (resposta sanitizada, caminho de campo ou trecho/screenshot da interface). Nunca registre chave de API, cabeçalhos de autenticação, cookies ou URL com token de sessão. Não invente `offer_id` do fornecedor; uma chave calculada localmente deve ser rotulada como local.

## Estados por célula

| `status` | Significado |
|---|---|
| `pending` | Planejada, ainda sem consulta |
| `available` | Existe oferta que atende aos critérios e à ocupação; preserve eventuais limites de taxas |
| `unavailable` | A propriedade informa explicitamente ausência de oferta para aqueles parâmetros; não prova hotel totalmente lotado |
| `restricted` | Há restrição explícita de noites mínimas, ocupação ou outra condição; preserve a mensagem |
| `no_matching_offer` | Há ofertas, mas as efetivamente verificadas não atendem à categoria ou condições obrigatórias |
| `property_not_found` | Identidade não resolvida ou propriedade ausente; não conclua fechamento do hotel |
| `unverified` | Há dados parciais, mas faltam comprovação de critério, moeda, base ou parâmetros |
| `access_blocked` | Login, CAPTCHA ou bloqueio de acesso impede verificar |
| `collection_error` | Erro técnico, timeout ou resposta inválida |

Não classifique busca vazia como `unavailable`; use `unverified` com a limitação de busca/paginação ou investigue a propriedade direta. Não chame de `no_matching_offer` se os critérios das ofertas não foram verificados.

## Resumos e histórico

- Cobertura = células com consulta concluída e resultado interpretável / células planejadas. Informe separadamente pendentes, erros, bloqueios e não verificadas; várias ofertas não aumentam o número de células cobertas.
- Diária média = total da estadia / noites, com moeda e base identificadas. Não é garantia de que cada noite custará o mesmo. Não divida por quartos sem configuração equivalente comprovada.
- Menor preço: use somente ofertas elegíveis em grupo comparável. Quando houver taxas parciais, apresente o valor exibido como parcial e separe de totais finais.
- Variação percentual = `(novo - anterior) / anterior × 100`, com anterior positivo e mesmas condições/base/moeda. Sem equivalência, registre mudança de produto ou ausência de comparação.
- Datas com durações distintas podem ser mostradas com total e média; mantenha a duração visível e não atribua diferença exclusivamente ao dia de check-in.
- Uma nova captura é append no histórico. “Acompanhar concorrentes” não configura agendamento automaticamente; se solicitado, use a ferramenta de automação do host, evitando criar jobs duplicados.

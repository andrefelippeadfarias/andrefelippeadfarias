---
name: booking-hotel-research
description: "Pesquisar hotéis e pousadas na Booking.com e comparar preços de uma lista de concorrentes em várias datas, com características e tipos de quarto específicos por hotel, condições tarifárias e evidências de cada captura. Use para pesquisa pontual, acompanhamento de concorrentes e histórico de tarifas; não executa reservas nem altera anúncios."
metadata:
  version: "1.0.0"
  language: "pt-BR"
---

# Pesquisa de hotéis e tarifas na Booking

Transforme uma lista de concorrentes em uma matriz de hotéis × períodos de estadia, com ofertas rastreáveis e comparáveis. O uso principal é acompanhar hotéis indicados pelo usuário. Descubra novos candidatos apenas quando solicitado ou para resolver a identidade de um hotel da lista.

## Preparar a pesquisa

Reutilize o briefing e o cadastro existentes. Pergunte apenas o que faltar para executar uma consulta correta; enquanto aguarda, prepare a lista e a grade de datas.

- Identifique cada concorrente por `hotel_key` estável, nome, cidade/endereço e URL da propriedade na Booking. Confirme homônimos, filiais e redirecionamentos antes de atribuir preços. IDs de fornecedores diferentes não são intercambiáveis.
- Registre características e categoria de quarto por hotel, separando requisitos obrigatórios de preferências. Exemplos: suíte com hidromassagem em um concorrente, apartamento standard em outro. Preserve também um perfil comum quando o usuário quiser comparação direta entre hotéis.
- Fixe adultos, quartos, crianças e idades, moeda, datas e duração. Resolva datas relativas pelo fuso e data atuais do usuário; confirme ambiguidades como feriados sem ano. Não invente datas de estadia.
- Se a ocupação não foi definida, proponha 2 adultos, 1 quarto e nenhuma criança e declare o padrão antes da captura. Use BRL como proposta para pesquisas no Brasil; em outros destinos, confirme a moeda ou mantenha a moeda exibida e separe os resultados.
- Defina o significado do preço desejado: menor oferta elegível de cada hotel, uma categoria específica ou várias categorias. Explicite refeições, cancelamento, pagamento, benefícios e orçamento por diária ou estadia. Não relaxe requisitos silenciosamente para obter um preço.

Mantenha cadastro e capturas na área do projeto, sem embutir concorrentes ou preferências pessoais nesta skill. Use [coleta-e-dados.md](references/coleta-e-dados.md) para os campos, estados e cálculos.

## Montar a matriz de datas

Cada combinação precisa de check-in e check-out explícitos. Check-out é exclusivo: 06–08/11 corresponde a 2 noites. Não estime uma estadia de 3 noites multiplicando uma cotação de 1 noite.

Aceite períodos explícitos, check-ins diários com duração fixa, fins de semana e várias durações. Datas de captura (quando se consulta) são diferentes das datas de estadia (quando se hospeda). Uma nova rodada observa preços atuais para os mesmos períodos; não recupera preços históricos que nunca foram capturados.

Para grades repetitivas, use o helper local, sem rede:

```text
python <pasta-da-skill>/scripts/plan_matrix.py --config <brief.json> --output <pasta-nova-da-rodada> --today YYYY-MM-DD
```

Substitua os caminhos e a data pelos valores reais. Consulte [o briefing de exemplo](references/brief-example.json) e as regras em [coleta-e-dados.md](references/coleta-e-dados.md). O helper gera `plan.json` e `capture-template.csv`; ele não coleta preços. As URLs do exemplo são fictícias e devem ser substituídas antes de qualquer consulta.

Conte hotéis × estadias únicas e as chamadas adicionais para localizar propriedades, paginação e validação. Grades maiores que o limite local de 500 jobs exigem ajustar `max_jobs` conscientemente; isso não autoriza uso pago. Respeite o orçamento e o limite de chamadas já autorizados. Se não houver autorização para usar créditos de um serviço, prepare a grade e resolva esse ponto antes de consumi-los.

## Escolher uma fonte executável

1. Inspecione as ferramentas disponíveis nesta sessão. Prefira um conector de Booking/HasData já conectado com preços por datas e detalhes da propriedade. Leia [hasdata.md](references/hasdata.md) se ele estiver disponível ou se o usuário pedir essa integração. Use somente nomes e parâmetros confirmados pelo schema atual da ferramenta.
2. Uma API oficial da Booking pode ser usada se o usuário já tiver acesso autorizado; não presuma credenciais nem acesso de parceiro.
3. Sem conector utilizável, consulte a Booking no navegador pelas ferramentas disponíveis e pela skill de navegação do ambiente. Preencha e verifique os parâmetros na interface, registre evidências visíveis e trate login, bloqueio e CAPTCHA como impedimentos de acesso. Não tente contorná-los.
4. Se nenhum caminho estiver disponível, entregue o cadastro e a grade preparados, explicando o acesso que falta. Nunca apresente preparação local como coleta ao vivo.

Buscadores gerais ajudam a localizar a propriedade; snippets e preços sem a ocupação e as datas verificadas não servem como cotação. Uma página sem preço também não confirma esgotamento. Não configure serviços, instale conectores ou altere configurações globais como efeito lateral de uma pesquisa.

## Executar a coleta

- Faça a consulta da propriedade exata para cada job. O menor preço de um resultado de busca pode corresponder a dormitório, banheiro compartilhado ou outra ocupação; valide o quarto e o plano tarifário.
- Ao começar por uma fonte ou schema desconhecido, valide uma primeira célula completa antes de ampliar o lote. Aproveite essa captura na matriz, evitando repetições desnecessárias.
- Confirme os parâmetros efetivamente aplicados e a moeda retornada, além dos enviados. Se a ferramenta não representar idades, distribuição de hóspedes entre quartos ou filtros obrigatórios, use outro caminho autorizado ou marque a limitação.
- Preserve o texto original do preço, sua base (por noite/estadia/quarto) e todas as condições. Uma linha por oferta; uma célula pode conter várias ofertas. O status `available` exige oferta que atenda aos critérios daquela captura.
- Características recebem `confirmed`, `not_met` ou `unverified`, com fonte e horário. Comodidades do hotel, características do quarto e benefícios da tarifa são níveis distintos. Piscina não comprova piscina aquecida; café disponível não comprova café incluído.
- Se a categoria-alvo não tiver oferta, mantenha a célula e o motivo. Uma categoria alternativa só aparece identificada como alternativa. Não retire um concorrente da matriz por não atender a um critério.
- Registre falhas por célula e retome somente pendências. Faça no máximo uma nova tentativa para falha transitória nessa rodada, respeitando limites e `Retry-After`; depois entregue resultado parcial com motivo. Bloqueio de acesso ou falta de credenciais exige resolver o acesso antes de tentar de novo.
- Registre cada rodada em pasta nova com horário e fuso, parâmetros, evidências mínimas e capturas. Preserve o histórico; não sobrescreva observações anteriores com o preço atual. Remova credenciais, cookies, IDs de sessão e outros dados pessoais das evidências.

## Comparar e entregar

Apresente uma tabela de hotéis × períodos com total da estadia, moeda, diária média derivada e estado da coleta. Vincule cada célula ou linha ao detalhe da oferta e à fonte. Mostre o horário ou intervalo real das capturas e os critérios adotados.

Calcule diária média como total ÷ noites para toda a ocupação consultada. Só apresente preço por quarto se a divisão for comprovadamente válida. Diferencie total exibido de total final com cobranças obrigatórias; taxas desconhecidas permanecem desconhecidas.

Separe ofertas com refeições, cancelamento, pagamento, ocupação, categorias ou elegibilidade diferentes. A comparação de categorias próprias de cada concorrente deve explicitar essa diferença. Para menor preço, variação ou ranking, use um grupo comparável e informe cobertura e exclusões. Não converta moedas sem taxa, data e fonte, nem preencha ausências com zero.

Se houver histórico equivalente, compare rodadas com a mesma chave de hotel, estadia, ocupação, moeda, categoria e condições. Distingua mudança de preço de mudança da oferta. Registre se o critério é acompanhar o mesmo produto ou o menor preço elegível em cada rodada.

Entregue o resumo em português e os dados em CSV/JSON quando forem úteis ou solicitados. Para XLSX, use a skill de planilhas disponível. Inclua os hotéis com falhas e critérios não verificados, além das melhores datas encontradas entre as efetivamente consultadas. Não prometa menor preço absoluto sem cobertura suficiente.

Antes de concluir, confira: todas as combinações têm estado; ofertas têm datas, ocupação, moeda, fonte e horário; nenhum requisito obrigatório foi presumido; cálculos usam a base correta; o histórico foi preservado. Pesquisa termina na coleta e comparação. Reservas e alterações de anúncios não fazem parte desta skill. Recorrência só deve ser configurada quando o usuário a solicitar, com horário, frequência e destino definidos.

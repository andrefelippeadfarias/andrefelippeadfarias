# Coleta com HasData

Leia quando o MCP/API HasData estiver disponível ou quando o usuário pedir sua conexão. Documentação consultada em 20/10/2026; confirme schema e condições no momento do uso.

## MCP e seleção de ferramenta

A [página oficial do MCP Booking](https://hasdata.com/mcp/booking) publica o endpoint `https://mcp.hasdata.com/mcp?apis=booking`, autenticação OAuth/chave e ferramentas de busca e detalhes. Os nomes documentados são `hasdata_booking_search_getBookingSearchResults` e `hasdata_booking_place_getBookingPlaceDetails`; o host pode acrescentar prefixos.

Para concorrentes com URL conhecida, prefira detalhes da propriedade. Use busca para localizar candidatos ou resolver identidade. Ambas as operações precisam de datas, quartos e hóspedes. A apresentação pública resumida não lista todos os filtros dos exemplos: **o schema exposto nesta sessão decide o que a ferramenta aceita**. Não transplante parâmetros REST para MCP sem verificação.

Não declare conexão só porque há configuração salva ou documentação. Confirme ferramentas e autenticação efetivas. Esta skill não instala nem autentica o serviço. Se a configuração for pedida, siga as instruções atuais do fornecedor e do host, preserve configurações existentes e use o mecanismo de segredos do ambiente.

## Parâmetros e lacunas

A [documentação de busca REST](https://docs.hasdata.com/apis/booking/search) descreve `checkInDate`, `checkOutDate`, `rooms`, `adults`, `children`, `childrenAges`, `currency`, filtros e paginação. `childrenAges` representa uma idade por criança; não descarte crianças porque a interface MCP não expõe esse campo. A mesma documentação descreve filtros múltiplos combinados com OR dentro de um campo: várias opções não comprovam exigência simultânea de todas as características. Revalide os requisitos no resultado.

Se moeda, idade ou filtro obrigatório não forem representáveis na ferramenta atual, use uma alternativa autorizada ou retorne `unverified` com motivo. Não use moeda padrão como se fosse a solicitada. Verifique se parâmetros e preços retornados correspondem ao pedido e se houve fallback de datas.

## Operação e orçamento

Inspecione a resposta real antes de mapear campos. Preserve valores brutos, base de cobrança, impostos, condições, identidade e evidência. Não fixe nesta skill um parser a partir de exemplos de marketing. JSON vazio, HTTP 200 ou propriedade encontrada sem oferta não comprovam disponibilidade.

Confirme custos, saldo, concorrência e limites atuais antes de um lote pago; a documentação de busca informa consumo por requisição. Use o teto já autorizado e conte validação, paginação e tentativas. Não deduza saldo pelo plano contratado. Timeout pode deixar uso incerto; não reinicie o lote inteiro.

Uma grade de H hotéis e D estadias tem H × D células. A execução pode exigir chamadas adicionais, ou uma busca pode produzir dados para vários hotéis: só reutilize os resultados se cada célula tiver identidade, parâmetros e condições verificados. Reutilize cadastro estático quando adequado; preços de outra estadia ou outra rodada não substituem consulta atual.

## Navegador como alternativa

Use as ferramentas de navegador autorizadas do host e suas instruções de computer use. Confirme visualmente hotel, datas, hóspedes, quartos e moeda, depois registre quarto, tarifa e taxas. Resultados de teste ou amostras da documentação não são capturas reais. Login ou CAPTCHA ficam como `access_blocked`; resolva com interação legítima do usuário quando necessária.

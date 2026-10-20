# Busca diária dos concorrentes do perfil (dono, 20/10)

**Perfil:** banheira de hidromassagem no quarto, vista para a montanha, café da manhã incluído e até 3 km do centro do Capivari (-22.7288, -45.5797). O cadastro fica em `pricelabs/pesquisa-booking/concorrentes.json`; a meta do dono é ter pelo menos 50 hotéis. É a mesma base da skill `booking-hotel-research`, que o acompanhamento segue: rodada nova por dia, histórico preservado, uma linha por oferta e status por célula.

## Quando
Toda rotina completa (07:53), depois da coleta do PriceLabs e antes do passo **Decida**.

## Como
1. `python3 scripts/mercado_diario.py planejar --hoje <hoje> --saida <rascunho>/pedidos_mercado.json` escolhe as estadias do dia (próximas 3 sextas a domingo, a próxima quarta e os feriados a até 60 dias) e divide o cadastro em lotes de 10 nomes.
2. Chame um agente (Agent, em segundo plano) com o texto abaixo, trocando os caminhos.
3. `python3 scripts/mercado_diario.py consolidar --hoje <hoje> --respostas <rascunho>/respostas_mercado.json` grava `pricelabs/pesquisa-booking/diaria-<hoje>/` (capturas e resumo), acrescenta o dia em `dados/mercado_diario.json` e atualiza a referência do plano (`dados/concorrentes_hidro_vista.json` → `periodos`).
4. `python3 scripts/concorrencia_hidro_vista.py --coleta <saida mesclada> --hoje <hoje> --saida <rascunho>/plano_comp.json` e siga o passo **Decida** (regras.md, seção 5).

## Texto para o agente
> Pesquisa SOMENTE LEITURA pelo conector oficial `mcp__Booking_com__accommodations_search` (carregue com ToolSearch). Proibido navegador, Playwright, curl ou páginas da Booking. Leia `<rascunho>/pedidos_mercado.json`. Para cada estadia e cada lote, chame a busca com `hotel_names` = o lote, `checkin_date`/`checkout_date` da estadia e os `parametros` do arquivo. Case cada resultado pelo slug da URL (`/hotel/br/<slug>.html`). Grave em `<rascunho>/respostas_mercado.json`: `{"estadias": [{"checkin", "checkout", "hoteis": [{"slug", "id", "preco_total"}]}]}`, com `preco_total` = `price.book` (estadia inteira, com café) ou null se não veio. Hotel que não voltar entra com `preco_total` null. Devolva só a contagem de preços por estadia.

## Leitura
- O conector mostra o **menor quarto com café**, sem login. O preço do quarto com hidro de cada hotel é esse preço × `r_hidro` do cadastro, medido na revisão semanal pela escada de preço mínimo ou pelo PDF do dono. Sem medida, `r_hidro` = 1: o concorrente aparece **mais barato do que é**, e nós parecemos caros. Em 20/10, em Finados, o Recanto ficou no percentil 84 contra o menor quarto dos concorrentes e no percentil 44 no PDF do dono (hidro contra hidro). Por isso, enquanto menos da metade dos concorrentes tiver `r_hidro` medido, a busca diária **não gera corte**, só subida e leitura de tendência (trava em `concorrencia_hidro_vista.py`). A calibração é tarefa da revisão de segunda: escada de preço mínimo no conector (`r_hidro` = degrau do quarto com hidro ÷ primeiro degrau, mediana dos períodos).
- O preço do Recanto e da Villa na busca é o que a Booking mostra **hoje**: muda de preço no PriceLabs só aparece depois do envio da madrugada.
- O Recanto e a Villa entram na mesma busca, então a comparação é na mesma vitrine. O percentil diz quantos concorrentes estão mais baratos que nós.
- A regra de ajuste é a da `regras.md`, seção 5. A trava de lotação e os pisos do dono vêm antes.

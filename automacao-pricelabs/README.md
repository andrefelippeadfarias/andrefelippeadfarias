# Automação de preços: PriceLabs + Jev

Programa que roda no seu computador com Windows, sete vezes por dia, e cuida do pacing dos quartos da Pousada Recanto dos Moinhos e da Villa Dolce Amore. Ele:

- lê o PriceLabs;
- mede a ocupação contra as metas (70% em 7 dias, 50% em 15, 35% em 30, 25% em 45 e 15% em 60);
- pergunta ao Jev se vale dar um desconto temporário;
- só age dentro de limites fixos.

Toda a lógica segue o relatório `pricelabs/relatorio-ocupacao-recanto-dos-moinhos.md` (versão 4).

## O que ele faz e o que ele nunca faz

**Faz:**

- **Mede e avisa:** calcula a ocupação por quarto nas faixas de 0 a 6, 7 a 14 e 15 a 29 dias e gera alertas (visibilidade, piso, sincronização, divergência de unidades, trava de receita).
- **Pergunta ao Jev:** faz uma única chamada por execução, só quando há um caso que passou por todas as regras. Nenhum texto é gerado por IA: o Jev só escolhe entre opções fechadas.
- **Dá o desconto:** cria uma substituição por data de **−10% em percentual**, com o motivo "auto-jev", só em datas livres dos próximos 6 dias e só em quarto sem tabela de ocupação e sem pendência. Hoje isso vale só para a Afrodite.
- **Retira o desconto sozinho:** quando a data vende, depois de 48 horas nos canais ou se o preço cair abaixo do mínimo.

**Nunca faz:**

- mexer nas ofertas da Booking, no Airbnb ou no Beds24;
- mudar preço base, mínimo, máximo ou as personalizações do grupo;
- descontar a Balcony ou os quartos com tabela de ocupação (Queen Spa 7, Double, Villa King Spa 7);
- descontar a Queen Spa (2) e a Villa King Spa (2) enquanto as pendências não forem resolvidas;
- tocar em datas com substituição criada por você, como as vésperas de feriado de 11/10, 01/11 e 19/11;
- descontar feriados ou vésperas. O programa calcula os feriados nacionais sozinho; os locais entram no config;
- agir quando o Jev falha, demora, responde fora do padrão ou tem pouca confiança. Nesses casos ele não faz nada.

**Aviso honesto:** com a configuração atual, só a Afrodite pode receber desconto, e ela costuma bater a meta. O desconto dela será raro. O que mais move a meta de 70% continua sendo:

- a tabela de ocupação (Dia 7);
- o passo 2 do preço base (Dia 10);
- a solução das pendências da Queen Spa (2) e da Villa King Spa (2).

## Instalação (uma vez)

1. **Instale o Python.** Abra o "Prompt de Comando" e rode:
   ```
   winget install -e --id Python.Python.3.12
   ```
   Outra opção é o instalador do python.org, marcando "Add python.exe to PATH".
2. **Copie a pasta** `automacao-pricelabs` para `C:\RecantoPrecos`, fora do OneDrive.
3. **Ligue a API do PriceLabs:** app.pricelabs.co → Account Settings → API Details → Enable → "I Need API Access" → digite `API`. Copie a chave.
   - Precisa ser a conta do **dono**. Com um subusuário, a leitura de reservas falha.
   - Custo: US$ 1 por listing por mês, ou seja, US$ 7 por mês.
4. **Crie a chave do OpenRouter** em openrouter.ai → Keys, com **limite de crédito US$ 0**. O modelo gratuito oficial do Jev é `typesafe/jev-1.13:free`.
   - O nome "jev-1.13-free" não existe; o certo usa dois-pontos.
   - Limite gratuito: 50 chamadas por dia. O programa usa no máximo 7.
5. **Clique duas vezes em `windows\instalar.bat`.** Ele:
   - pede as duas chaves e as guarda no Gerenciador de Credenciais do Windows, nunca em arquivo;
   - cria a tarefa agendada;
   - roda a verificação.
6. **Confira o resultado da verificação.** Tudo deve aparecer como OK. O fuso do Windows precisa ser Brasília (UTC−3).

## Como ele roda

| Item | Detalhe |
|---|---|
| Horários | 05:30, 08:30, 11:30, 14:30, 17:30, 20:30 e 23:30. Há também uma verificação 2 minutos depois de você entrar no Windows |
| Computador | Deixe em **suspensão**, não desligado. A tarefa acorda o PC e roda com o seu usuário conectado (tela bloqueada serve) |
| Duração | Cerca de 30 segundos por execução, e no máximo 20 minutos |
| Quando pode criar desconto | Só nas execuções até 7 horas antes da próxima sincronização com o Beds24. Com a sincronização das 06:00, isso é às 23:30 e às 05:30. Além disso, o desconto só é criado quando o Jev recomenda duas vezes seguidas, em chamadas diferentes |
| Quando chega ao Beds24 | Na sincronização programada do PriceLabs. A das 06:00 é gratuita. Horários extras (ex.: 12:00 e 18:00) custam US$ 1 por listing por mês cada, e são configurados na tela do PriceLabs e em `horarios_sincronizacao` no `config.json` |

## O que você vê

- **Na Área de Trabalho:** um arquivo `PRECOS OK 26-09 05h30.txt`, trocado a cada execução. `ATENCAO` ou `ERRO` no nome pede sua atenção. `ATRASADO` significa que o programa não roda há mais de 26 horas.
- **`ATENCAO-PRECOS.txt`:** aparece quando algo precisa de você.
- **Relatório completo:** `%LOCALAPPDATA%\automacao-pricelabs\relatorio.html`. Mostra ocupação contra as metas, decisões do Jev com confiança, o que foi feito, descontos ativos e alertas.
- **Planilha:** `%LOCALAPPDATA%\automacao-pricelabs\historico.csv`, que abre no Excel com uma linha por execução.

## Modos e controles

| Arquivo | O que faz |
|---|---|
| `windows\executar.bat` | Roda uma vez agora |
| `windows\verificar.bat` | Confere Python, fuso, chaves, PriceLabs, reservas, Jev, tarefa agendada e roda o autoteste |
| `windows\parar.bat` | Para tudo: nenhuma execução lê ou altera a conta. Os descontos já gravados continuam |
| `windows\retomar.bat` | Volta a rodar e rearma o disjuntor |
| `windows\desfazer.bat` | Remove **só** os descontos criados pelo programa, e só se estiverem exatamente como ele gravou. Depois, clique em "Sync Now" no PriceLabs para valer na hora |
| `windows\observar.bat` / `windows\ativar.bat` | Troca o modo |

**Modos:**

- **Observar (padrão):** o programa lê tudo, pergunta ao Jev e mostra no relatório o que **faria**, sem alterar nada. Nesse modo ele também pergunta sobre os quartos que ficam fora da automação ("sombra") para você avaliar o Jev.
- **Ativo:** grava os descontos permitidos.
- **Disjuntor:** se algo sair do esperado (releitura diferente do gravado, dois erros de escrita, preço abaixo do mínimo), o programa passa a só remover descontos até você rodar RETOMAR.

## Quando passar de Observar para Ativo

Só quando tudo abaixo estiver cumprido:

1. `verificar.bat` todo OK.
2. Pelo menos 5 dias com o Jev respondendo e 20 decisões registradas. O relatório mostra essa contagem na seção Saúde.
3. Com dados iguais, o Jev mudou de resposta em menos de 10% das vezes.
4. Você concorda com o que o programa "faria" nos últimos 3 dias.
5. Você fez o ensaio de gravação numa data livre da Afrodite, sem substituição:
   ```
   py -3 -m pacing testar-gravacao --listing 350362___722807 --data AAAA-MM-DD --confirmar
   ```
   Ele grava +0% (preço igual), lê de volta, apaga e mostra o `last_date_pushed` antes e depois.
6. Você testou PARAR e DESFAZER.

Na primeira semana em Ativo, recomendo `"teto_dia": 1` no `config.json`.

## Custos por mês

| Item | Custo |
|---|---|
| API do PriceLabs | US$ 7 (7 listings × US$ 1), mais impostos |
| Sincronização extra no PriceLabs (opcional) | US$ 7 por horário extra |
| Jev gratuito no OpenRouter | US$ 0 |
| Tokens do Jev | No modo Ativo, cerca de 1 mil por chamada (só a Afrodite). No modo Observar, até cerca de 5 mil, porque ele também pergunta sobre os outros quartos. Em geral são 2 chamadas por dia (23:30 e 05:30), e no máximo 7 |

Se o modelo gratuito sair do ar, dá para ligar o TypeSafe pago (`provedor_reserva: "typesafe"`, cerca de US$ 0,03 por mês). Isso fica desligado por padrão.

## Segurança

- **Chaves:** ficam no Gerenciador de Credenciais do Windows, não na pasta. Nunca aparecem em logs, no relatório ou no Git.
- **O que vai ao Jev:** só faixas com nome ("below_target", "weak", "tight"). Nunca nomes de quartos, datas, ids, reservas ou dados de hóspedes.
- **Rede:** o programa só fala com uma lista fechada de endereços do PriceLabs, do OpenRouter e do TypeSafe. Ele recusa redirecionamentos.
- **Diário:** cada gravação é registrada antes e depois em `%LOCALAPPDATA%\automacao-pricelabs\diario-escritas.jsonl`. O DESFAZER usa esse diário.

## Pendências que dependem de você

- Queen Spa (2): descobrir por que o hóspede paga cerca de 34% do preço enviado. Depois disso, `desconto_permitido` pode ser ligado no config.
- Villa King Spa (2): resolver o conflito de ocupação entre o Beds24 e o PriceLabs.
- Double Spa: confirmar se são 6 ou 7 unidades. O programa alerta se o PriceLabs mostrar número diferente do config.
- Confirmar no ensaio que o PriceLabs devolve o campo `reason` e em que formato vem o `last_date_pushed`.

## Para quem mantém o código

- Python 3.10 ou mais novo, só com a biblioteca padrão.
- Rodar os testes:
  ```
  python -m unittest discover -s tests -t .
  ```
- Cobertura, que precisa de `coverage`:
  ```
  python -m coverage run --branch --source=pacing -m unittest discover -s tests -t .
  ```
- Contratos em `schemas/`. APIs verificadas em `docs/apis-verificadas.md`. Decisões do conselho em `.thinker-doer/run-2026-09-25/conselho/`.

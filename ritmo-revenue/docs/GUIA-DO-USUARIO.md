# Ritmo Revenue: guia do usuário

Este guia é para quem cuida dos preços de uma pousada ou de um hotel pequeno. Ele explica, passo a passo, como instalar o plugin, conectar os seus sistemas, ver o que o plugin faria antes de deixá-lo gravar qualquer coisa, aprovar as mudanças, usar a chave de emergência, desfazer uma mudança, trazer os preços dos concorrentes, ler os relatórios, usar o assistente opcional, cuidar da licença e receber atualizações.

O Ritmo Revenue nunca grava nada sozinho no começo. Toda propriedade começa em **modo sombra**: o plugin lê as reservas e o calendário, decide o piso e o teto de cada noite e mostra o porquê, mas não grava nada. Só uma pessoa tira a propriedade do modo sombra.

## 1. O que você precisa antes de começar

- WordPress 6.5 ou mais novo e PHP 8.1 ou mais novo.
- Uma conta no seu **PMS** (sistema de reservas) com acesso pela API: o plugin lê reservas e o calendário e grava só disponibilidade, estadia mínima e máxima, regras de chegada e saída e limites por canal. **Nunca um preço, nunca uma reserva.**
- Uma conta no seu **sistema de preços** com acesso pela API: o plugin lê anúncios, preços e regras de data e grava pisos, tetos e regras de estadia das noites.
- Recomendado: no arquivo `wp-config.php`, uma linha com uma chave própria do plugin, com 32 caracteres ou mais:

  ```php
  define('RITMO_SECRET_KEY', 'uma-frase-longa-e-aleatoria-com-32-caracteres-ou-mais');
  ```

  Com ela, as chaves das suas contas ficam guardadas com uma proteção que não depende só do banco de dados. A tela **Configurações › Diagnóstico** mostra qual proteção está em uso.

## 2. Instalar

O plugin vem num arquivo zip, por exemplo `ritmo-revenue-1.0.0.zip` (cerca de 1,8 MB, abaixo do limite padrão de envio de 2 MB), com uma pasta `ritmo-revenue/` dentro. Junto dele vem o código sha256 do arquivo: se quiser conferir que o arquivo chegou inteiro, compare com o resultado de `sha256sum ritmo-revenue-1.0.0.zip`.

1. Em **Plugins › Adicionar plugin › Enviar plugin** (em versões antigas do WordPress: Adicionar novo), escolha o zip, clique em **Instalar agora** e depois em **Ativar plugin**. Não descompacte o arquivo antes.
   - Se o WordPress recusar o envio por tamanho ("O arquivo enviado excede..."), a hospedagem limita o envio a menos de 2 MB. Peça à hospedagem para aumentar `upload_max_filesize` e `post_max_size` (8 MB bastam) ou envie a pasta `ritmo-revenue/` descompactada para `wp-content/plugins/` pelo gerenciador de arquivos ou por SFTP e ative em **Plugins**.
2. Aparece o menu **Ritmo Revenue** com treze telas: Visão geral, Noites, Aprovações, Alertas, Pendências, Registro de mudanças, Relatórios, Mercado, Ordens, Regras, Operação, Assistente e Configurações.
3. Quem pode fazer o quê:
   - **Administrador**: tudo, inclusive mudar os limites de segurança.
   - **Revenue Manager** (papel criado pelo plugin): vê tudo, muda regras, aprova, cuida das conexões e pode usar o assistente, mas não muda os limites de segurança.
   - **Revenue Viewer** (papel criado pelo plugin): só vê e baixa relatórios. Não usa o assistente (ele gasta créditos da sua conta da Claude).

   A permissão de mudar limites de segurança só vem com o papel de administrador. Para dar essa permissão a outra pessoa, um administrador precisa dá-la à mão.

## 3. O assistente de configuração

Abra **Ritmo Revenue › Configurações › Configuração** (o menu do plugin, não o Configurações do WordPress). O assistente leva você por nove etapas, uma de cada vez, e funciona mesmo sem JavaScript:

1. **Boas-vindas e conferências**: o plugin confere a criptografia das chaves, o agendador do WordPress e o fuso horário do site.
2. **Conectar o PMS**: cole o token do PMS e os ids das propriedades. A chave nunca volta para a tela: depois de salva, aparece só que ela existe.
3. **Conectar o sistema de preços**: cole a chave da API e o nome do seu PMS no sistema de preços (por exemplo, `beds24`). O plugin lê os anúncios para sugerir os quartos.
4. **Propriedade**: nome, fuso horário, moeda e onde seus preços ficam no mercado (o nível padrão serve para a maioria das pousadas). O código da propriedade é feito a partir do nome; para escolher outro, abra **Avançado**.
5. **Quartos**: uma linha por quarto, com o id do quarto no PMS, o número de unidades e os valores do anúncio. **Confira o id e as unidades**: são só sugestões.
6. **Regras e calendário**: comece pelo modelo **Conservador (recomendado)**. As noites de fim de semana, os feriados e as temporadas ficam em **Regras › Calendário**.
7. **Limites de segurança**: o menor preço permitido (uma fração do mínimo de referência de cada quarto), o limite semanal de mudança do anúncio e se o modo autônomo pode existir no site. Para confirmar, digite o id da propriedade. Só um administrador faz esta etapa.
8. **Primeira rodada em sombra**: o plugin lê tudo e decide cada noite, sem gravar nada.
9. **O que o plugin faria**: quantas noites ele mudaria, quantas subiriam e quantas desceriam, e por quê.

Se a etapa 9 disser que muitas noites ficaram sem mudança por falta de preço de mercado, traga os preços dos concorrentes (seção 10) e rode em sombra de novo.

## 4. Primeira semana em sombra

Deixe a propriedade em modo sombra por alguns dias. Todo dia:

- **Visão geral**: hoje e as próximas noites, a ocupação dos próximos 7 e 30 dias contra a meta, a receita reservada, a diária média, o ritmo de vendas dos próximos 60 dias e o que **precisa de você**.
- **Noites**: a grade de noites por quarto. Cada noite mostra o preço agora, as unidades vendidas e o ritmo. Clique numa noite para ver a regra que decidiu e as frases do porquê, com o preço em reais e a porcentagem do preço base escrita por extenso. O filtro **a última rodada mudaria** mostra só as noites que o plugin mudaria.

Nada disso grava nada. Se algo parecer errado, mude as regras em **Regras** (seção 9) e rode em sombra de novo em **Visão geral › Rodar em sombra agora**.

## 5. Aprovar as mudanças

Quando o que o plugin faria fizer sentido, passe a propriedade para o **modo aprovação** em **Operação**:

1. A tela mostra uma lista de condições, por exemplo "2 de 3 condições cumpridas": os limites de segurança confirmados, as rodadas em sombra recentes e as duas conexões testadas nas últimas 24 horas. Cumpra o que faltar.
2. Escolha **Aprovação** e clique em **Trocar o modo**.

Daí em diante, cada rodada completa propõe um plano, e **nada é gravado antes de uma pessoa aprovar**. Na tela **Aprovações**:

- As mudanças vêm agrupadas por quarto e por noites seguidas com o mesmo motivo. Cada noite mostra **antes → depois** em reais e em porcentagem.
- Avisos marcam hoje e amanhã (depois de gravar, aperte **Sync Now** no sistema de preços para o canal receber hoje), feriados, regras apagadas e gravadas de novo (vão juntas) e quartos ligados por uma relação.
- As mudanças de estadia e de chegada/saída no PMS aparecem numa seção própria, com o valor de antes e o de depois.
- Marque o que quer aprovar e clique em **Aprovar os selecionados**, ou **Aprovar tudo**, ou **Recusar o plano**.
- Um plano vale até a meia-noite da propriedade ou até o calendário em que foi montado ficar velho. Depois disso ele expira, e a próxima rodada propõe outro.

Depois de aprovar, o plugin lê os calendários de novo e grava em segundo plano. A tela acompanha a gravação. O plugin grava em série, em partes pequenas, para na primeira falha e relê tudo o que gravou.

Mudanças do mínimo, do preço base ou do máximo do anúncio aparecem à parte e só podem ser aprovadas por quem pode mudar os limites de segurança, digitando o valor novo.

## 6. Ligar o modo autônomo com segurança

No **modo autônomo** o plugin grava sozinho, sempre dentro dos limites de segurança. Antes de ligar:

1. Deixe a propriedade em modo aprovação por alguns dias e aprove alguns planos. A tela **Operação** diz quantos dias e quantos planos aprovados e gravados ainda faltam.
2. Um administrador precisa ter permitido o modo autônomo nos limites de segurança.
3. Em **Operação**, escolha **Autônomo** e digite o id da propriedade para confirmar.

Mesmo no modo autônomo:

- O preço de hoje **nunca sobe**.
- Nenhum preço fica abaixo do piso absoluto de cada quarto.
- Mudanças do mínimo, do preço base e do máximo do anúncio continuam esperando uma pessoa.
- Uma noite mudada à mão por outra pessoa fica **congelada**: o plugin não grava por cima até o congelamento acabar ou alguém liberar.
- Uma onda de cancelamentos segura os cortes de preço até uma pessoa conferir.
- Se uma gravação falhar, a propriedade volta para o modo aprovação.

## 7. A chave de emergência

A **chave de emergência** para tudo. Ela fica em **Operação**:

- **Ligar a chave: parar toda gravação** — nenhuma rodada grava; as rodadas agendadas só leem e vigiam as reservas; os planos aprovados que ainda não foram gravados são encerrados.
- **Desligar a chave** — só quem cuida das conexões pode desligar.

Uma faixa vermelha aparece em todas as telas enquanto a chave estiver ligada. Para ligar a chave mesmo sem acesso às telas, coloque no `wp-config.php`:

```php
define('RITMO_KILL_SWITCH', true);
```

Com a constante, a tela não consegue desligar a chave: só tirando a linha do arquivo.

## 8. Desfazer uma mudança

Em **Registro de mudanças**, cada gravação aparece com antes → depois, quem fez e quando:

1. Marque as mudanças que quer desfazer.
2. Clique em **Desfazer as mudanças marcadas…**. Nada é gravado ainda: a tela mostra, para cada mudança, o valor de agora e o valor para o qual ela volta.
3. Confira e clique em **Desfazer agora**, ou em **Cancelar: manter as mudanças**.

O desfazer grava na hora, em qualquer modo (inclusive em aprovação). Ele passa pelas mesmas conferências de uma gravação comum. Uma noite que outra pessoa mudou depois não é desfeita (ela fica como está), e uma noite que já passou também não.

## 9. Regras, ordens e calendário

- **Regras**: mudar uma regra **nunca muda um preço na hora**. A mudança vai para o seu rascunho; em **Revisar e salvar** você vê cada mudança em palavras e salva uma **versão nova** das regras, que vale a partir da próxima rodada. O histórico guarda todas as versões, e dá para restaurar uma antiga.
- **Limites de segurança** (aba própria): só um administrador muda, digitando o valor novo para confirmar.
- **Relações entre quartos**: escritas em frases, como "O quarto maior nunca custa menos que o quarto menor". Uma relação que formaria um círculo é recusada, com o caminho dos quartos.
- **Calendário**: noites de fim de semana, feriados e temporadas. Em **Importar feriados nacionais**, o plugin propõe os feriados do Brasil ou de Portugal, com as pontes, a partir de listas que vêm com ele (nada é baixado).
- **Ordens**: uma **ordem do dono** vale para algumas noites — um preço mínimo, um alvo de mercado, uma permissão, uma exceção de estadia, "não mexer nestas noites". Uma ordem nunca é editada: para mudar, copie e encerre a original.

## 10. Preços de mercado por CSV

Em **Mercado**, cadastre os concorrentes (marque **Parecido com você (par)** nos mais parecidos com a sua pousada: os preços de mercado dos pares usam só esses) e importe um arquivo CSV com os preços deles. Uma linha por estadia:

| Coluna | Exemplo |
|---|---|
| `competitor` | `pousada-do-lago` (o código do concorrente; `competitor_code` também é aceito) |
| `check_in` | `2027-03-12` |
| `check_out` | `2027-03-14` |
| `price` | `980.00` |
| `price_basis` | `total` (o preço da estadia inteira, o padrão quando a coluna falta) ou `per_night` (o preço de uma noite); `basis` também é aceito |
| `room` | `cheapest` (mais barato) ou `comparable` (comparável ao seu) |
| `currency` | `BRL` |

1. Clique em **Baixar um arquivo de exemplo** para ver o formato completo.
2. Envie o arquivo (até 2 MB por padrão). **Nada é importado nesta etapa**: a prévia mostra quantas linhas são válidas e lista as inválidas, com o número da linha e o motivo.
3. Clique em **Importar** para confirmar.

O plugin lê o arquivo e descarta: o arquivo nunca fica guardado no site. Colunas com dados de hóspedes são ignoradas. O mesmo arquivo não é importado duas vezes.

Quando os preços ficam velhos, aparece um alerta lembrando de importar preços novos. As exportações em CSV abrem em qualquer planilha; um texto que começa como fórmula ganha um apóstrofo na frente, para nunca ser executado.

## 11. Relatórios

Em **Relatórios**:

- **Desempenho semanal**: reservas feitas por semana, cancelamentos, estadias e receita.
- **Mês e metas**: realizado, reservado e o que falta para o mínimo do mês.
- **Canais**: reservas e receita dos últimos 28 dias por canal e por quarto.
- **Próximos 12 meses**: a ocupação vendida de cada mês contra a do mercado (digite a ocupação do mercado na aba de metas). Salve uma revisão para comparar a próxima com ela.
- **Metas**: metas mensais e a previsão de receita em três cenários. As metas mudam só aquilo com que os relatórios comparam, **nunca um preço**.
- **Relatórios das rodadas**: o que cada rodada fez, em frases.

Cada relatório tem uma frase-resumo, tabelas e um botão **Baixar CSV**.

## 12. Alertas e pendências

- **Alertas**: cada alerta diz o que aconteceu, por que importa e o que fazer. Você pode reconhecer (fica na lista) ou resolver. Os alertas críticos podem chegar por e-mail: escolha em **Alertas**.
- **Pendências**: o que precisa de uma pessoa — apertar Sync Now depois de mudar o preço de hoje, testar uma conexão, confirmar os limites de segurança. Você também pode escrever pendências suas. **Não escreva nomes, e-mails, telefones nem documentos de hóspedes**: cite uma reserva pelo número dela.

## 13. Privacidade

O plugin nunca guarda nome, e-mail, telefone nem documento de hóspede. Uma reserva aparece só pelo número e pelo canal, em telas, relatórios, alertas e exportações. O arquivo de suporte (**Configurações › Diagnóstico**) também não leva chaves, reservas, nomes nem e-mails.

## 14. O assistente (opcional)

O **Assistente** usa a sua própria chave da API da Claude (da Anthropic) para três coisas: escrever em palavras o painel da semana e o relatório de uma rodada, explicar um alerta e transformar um pedido escrito por você num **rascunho**. Ele vem **desligado** e o plugin funciona igual sem ele.

**Ligar.** Em **Assistente › Configurações** (só quem cuida das conexões):

1. Cole a chave da API da Claude. Ela é guardada com criptografia e nunca volta para a tela.
2. Escolha o modelo (`claude-opus-5-5` é o padrão; `claude-sonnet-5-5` e `claude-haiku-5-5` custam menos), o esforço (médio é o padrão) e o **teto mensal** em dólares. Ao chegar no teto, o assistente desliga até o mês seguinte ou até você aumentar o teto. O custo é cobrado pela Anthropic na sua conta; a tela **Uso e custo** mostra a estimativa do mês, sem guardar o que foi perguntado nem respondido.
3. Marque **Ligado**, salve e clique em **Testar a conexão**: a tela mostra os modelos que a chave pode usar.

**Pedir um rascunho.** Em **Assistente › Pedir**, escreva o que quer com as suas palavras, por exemplo "subir 10% os sábados de dezembro na suíte" ou "não vender abaixo de 900 no feriado". O assistente prepara um rascunho e ele fica em **Rascunhos aguardando você** (a tela **Aprovações** e o menu também avisam quantos esperam). Abra o rascunho: uma ordem abre no formulário de ordens já preenchido, e uma mudança de regra vai para o seu rascunho de regras (**Regras › Revisar e salvar**). **Nada vale enquanto você não salvar.** O assistente nunca grava preço, nunca dá uma ordem, nunca salva regras, nunca muda o modo, a chave de emergência nem os limites de segurança. Os valores do rascunho precisam estar no que você escreveu; o piso de um "subir 10%" é calculado pelo plugin a partir do preço base de cada quarto. O rascunho também fica dentro do que você pediu: só os quartos que você citou (todos os quartos só se você não citar nenhum ou escrever "todos os quartos"), só as datas, os meses ou o feriado que você citou (sem datas, no máximo 31 noites), e uma chave liga/desliga ou uma escolha das regras só muda se você citar o nome dela. Preço fixo e restrição de estadia você dá direto no formulário de ordens; a gravação no PMS, o validador e os limites de segurança nunca entram num rascunho.

**Explicar.** No detalhe de um alerta, **Explicar em palavras simples**; em **Relatórios › Relatórios das rodadas**, **Pôr este relatório em palavras**. O texto só pode citar números que vieram do plugin; se citar outro número, é descartado.

**Privacidade.** O plugin nunca manda para a Claude dados de hóspedes que ele guarda (as reservas vão só como números). O que você escreve no pedido vai como você escreveu: **não escreva nome, e-mail, telefone nem documento de hóspede**; cite uma reserva pelo número. Se o pedido tiver e-mail, telefone, CPF/CNPJ ou parecer trazer um nome de pessoa (por exemplo "hóspede João", "Hóspede: Maria Souza", "pedido da Maria" ou "Maria Souza pediu"), nada é enviado e a tela explica por quê. O nome da pousada e os nomes dos seus quartos (mesmo um quarto chamado "Suíte Família Luxo", também abreviado, como "Dona Flor" para "Quarto Dona Flor") não contam como nome de pessoa, nem no pedido nem na resposta do assistente; os nomes dos períodos do calendário também não, desde que eles mesmos não tragam nome de hóspede (um período chamado "Casamento da família Souza" faz o assistente recusar o pedido). Um nome conhecido nunca libera o que vem ao lado dele: com um quarto chamado "Família", "a família Souza" continua barrado. A verificação é uma heurística e não pega todo nome: a regra continua sendo não escrever. O texto do pedido não fica guardado: o rascunho guarda só o resumo do que ele faria. Quando o modelo recusa um pedido ou a Claude está sobrecarregada, a tela diz isso com clareza e nada é guardado.

O assistente só funciona com a licença válida (seção 15). Para desligar, desmarque **Ligado** em **Assistente › Configurações**; para apagar a chave, **Remover a chave**.

## 15. Licença

A licença decide se as propriedades podem sair do modo sombra e se o assistente funciona. **O modo sombra funciona sempre, com ou sem licença.**

1. Em **Configurações › Licença** (só quem cuida das conexões), cole a chave da licença e salve. O plugin confere a chave no servidor de licenças e mostra a situação, a validade e a última conferência. A chave nunca volta para a tela.
2. O plugin confere a licença uma vez por dia, sozinho, e quando você clica em **Conferir agora**. Nunca no meio de uma rodada.
3. Situações:
   - **Ativa**: tudo funciona.
   - **Em carência**: o servidor de licenças ficou fora do ar, a licença venceu ou foi cancelada. Tudo continua funcionando por 30 dias e um aviso amarelo aparece nas telas do plugin, com a data do fim da carência.
   - **Vencida** (a carência acabou) ou **sem licença**: as propriedades ficam só em modo sombra. Na **próxima rodada**, uma propriedade em modo aprovação ou autônomo volta para o modo sombra (o registro de mudanças diz que foi a licença), as aprovações que ainda não foram gravadas terminam e nada é gravado. Nada é cortado no meio de uma gravação. O assistente desliga. Um aviso vermelho aparece nas telas.
4. Com a licença válida de novo, a propriedade **não** sobe de modo sozinha: uma pessoa volta a passar para o modo aprovação em **Operação**, como da primeira vez.
5. Para tirar a licença deste site (por exemplo, para usá-la em outro), abra **Remover a chave da licença**, marque a confirmação e remova: a vaga é liberada no servidor.

O endereço do servidor de licenças e a chave que assina as respostas dele vêm dentro do plugin; nenhuma linha do `wp-config.php` muda isso num site de verdade. Só uma resposta assinada pelo servidor de licenças do plugin vale como licença. Enquanto o plugin não tiver servidor de licenças, o site fica sem licença: só modo sombra.

## 16. Atualizações

Quando houver uma versão nova, ela aparece em **Painel › Atualizações** e na tela **Plugins**, como nos outros plugins. O WordPress só instala uma versão **assinada** pelo fornecedor: os dados da versão e o arquivo são conferidos antes da instalação, e um arquivo alterado ou vindo de outro servidor é recusado. Para procurar agora, use **Procurar atualizações** em **Configurações › Licença**. Antes de atualizar, faça uma cópia de segurança do site, como para qualquer plugin. Seus dados, regras e chaves continuam depois da atualização.

O canal de atualizações também é uma linha no `wp-config.php` (`RITMO_UPDATE_URL` e `RITMO_UPDATE_PUBLIC_KEY`). Sem ela, você atualiza enviando o zip novo em **Plugins › Adicionar plugin › Enviar plugin** (o WordPress pergunta se quer substituir a versão instalada).

**Desinstalar.** Desativar mantém tudo. Excluir o plugin sempre libera a licença no servidor e apaga as chaves e tokens salvos (Beds24, PriceLabs, Claude, licença): os dados guardados não levam nenhum segredo, e depois de reinstalar é preciso conectar de novo. Para apagar também os dados, marque antes, em **Configurações › Desinstalar**, a opção **Apagar todas as tabelas, configurações e chaves salvas do plugin** e salve; aí excluir o plugin remove também as tabelas, as configurações, os papéis e as tarefas agendadas do plugin.

**WordPress multisite.** Ative o plugin site a site (na tela **Plugins** de cada site que vai usá-lo). A ativação para a rede inteira é recusada, porque cada site tem as próprias tabelas, regras e agenda.

## 17. Quando algo dá errado

- **Uma conexão falhou**: teste de novo em **Configurações › Conexões**. A mensagem diz o motivo (chave recusada, sem acesso, sistema fora do ar).
- **Nada foi gravado**: confira o modo em **Operação**, se a chave de emergência está ligada e se há um plano aguardando aprovação.
- **As rodadas não acontecem**: o agendador do WordPress depende de visitas ao site. Num site com pouco movimento, configure um cron do servidor que chame `wp-cron.php` a cada 5 minutos.
- **O idioma**: as telas seguem o idioma do seu perfil no WordPress (português do Brasil ou inglês). Os textos do assistente seguem o idioma do site.
- **Não consigo sair do modo sombra**: veja a lista de condições em **Operação**; uma delas pode ser a licença (seção 15).
- **O assistente não responde**: a tela diz o motivo (desligado, sem chave, sem licença, teto do mês, modelo indisponível para a chave, chave recusada, conta sem crédito, limite de uso da conta, recusa do modelo ou Claude sobrecarregada); o texto técnico fica em **Detalhes técnicos**. Teste a conexão em **Assistente › Configurações**.

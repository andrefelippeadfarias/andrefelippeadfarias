# Ritmo Revenue: guia do usuário

Este guia é para quem cuida dos preços de uma pousada ou de um hotel pequeno. Ele explica, passo a passo, como instalar o plugin, conectar os seus sistemas, ver o que o plugin faria antes de deixá-lo gravar qualquer coisa, aprovar as mudanças, usar a chave de emergência, desfazer uma mudança, trazer os preços dos concorrentes e ler os relatórios.

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

1. Em **Plugins › Adicionar novo › Enviar plugin**, envie o arquivo do Ritmo Revenue e ative.
2. Aparece o menu **Ritmo Revenue** com doze telas: Visão geral, Noites, Aprovações, Alertas, Pendências, Registro de mudanças, Relatórios, Mercado, Ordens, Regras, Operação e Configurações.
3. Quem pode fazer o quê:
   - **Administrador**: tudo, inclusive mudar os limites de segurança.
   - **Revenue Manager** (papel criado pelo plugin): vê tudo, muda regras, aprova e cuida das conexões, mas não muda os limites de segurança.
   - **Revenue Viewer** (papel criado pelo plugin): só vê e baixa relatórios.

   A permissão de mudar limites de segurança só vem com o papel de administrador. Para dar essa permissão a outra pessoa, um administrador precisa dá-la à mão.

## 3. O assistente de configuração

Abra **Configurações › Configuração**. O assistente leva você por nove etapas, uma de cada vez, e funciona mesmo sem JavaScript:

1. **Boas-vindas e conferências**: o plugin confere a criptografia das chaves, o agendador do WordPress e o fuso horário do site.
2. **Conectar o PMS**: cole o token do PMS e os ids das propriedades. A chave nunca volta para a tela: depois de salva, aparece só que ela existe.
3. **Conectar o sistema de preços**: cole a chave da API e o nome do seu PMS no sistema de preços (por exemplo, `beds24`). O plugin lê os anúncios para sugerir os quartos.
4. **Propriedade**: nome, id, fuso horário e moeda.
5. **Quartos**: uma linha por quarto, com o id do quarto no PMS, o número de unidades e os valores do anúncio. **Confira o id e as unidades**: são só sugestões.
6. **Regras e calendário**: comece pelo modelo **Conservador (recomendado)**. As noites de fim de semana, os feriados e as temporadas ficam em **Regras › Calendário**.
7. **Limites de segurança**: o menor preço permitido (uma fração do mínimo de referência de cada quarto), o limite semanal de mudança do anúncio e se o modo autônomo pode existir no site. Para confirmar, digite o id da propriedade. Só um administrador faz esta etapa.
8. **Primeira rodada em sombra**: o plugin lê tudo e decide cada noite, sem gravar nada.
9. **O que o plugin faria**: quantas noites ele mudaria, quantas subiriam e quantas desceriam, e por quê.

Se a etapa 9 disser que muitas noites ficaram sem mudança por falta de preço de mercado, traga os preços dos concorrentes (seção 8) e rode em sombra de novo.

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
2. Clique em **Preparar o desfazer das mudanças marcadas**.

O desfazer passa pelas mesmas conferências de uma gravação comum. Uma noite que outra pessoa mudou depois não é desfeita (ela fica como está), e uma noite que já passou também não.

## 9. Regras, ordens e calendário

- **Regras**: mudar uma regra **nunca muda um preço na hora**. A mudança vai para o seu rascunho; em **Revisar e salvar** você vê cada mudança em palavras e salva uma **versão nova** das regras, que vale a partir da próxima rodada. O histórico guarda todas as versões, e dá para restaurar uma antiga.
- **Limites de segurança** (aba própria): só um administrador muda, digitando o valor novo para confirmar.
- **Relações entre quartos**: escritas em frases, como "O quarto maior nunca custa menos que o quarto menor". Uma relação que formaria um círculo é recusada, com o caminho dos quartos.
- **Calendário**: noites de fim de semana, feriados e temporadas. Em **Importar feriados nacionais**, o plugin propõe os feriados do Brasil ou de Portugal, com as pontes, a partir de listas que vêm com ele (nada é baixado).
- **Ordens**: uma **ordem do dono** vale para algumas noites — um preço mínimo, um alvo de mercado, uma permissão, uma exceção de estadia, "não mexer nestas noites". Uma ordem nunca é editada: para mudar, copie e encerre a original.

## 10. Preços de mercado por CSV

Em **Mercado**, cadastre os concorrentes e importe um arquivo CSV com os preços deles. Uma linha por estadia:

| Coluna | Exemplo |
|---|---|
| `competitor` | `pousada-do-lago` (o código do concorrente) |
| `check_in` | `2027-03-12` |
| `check_out` | `2027-03-14` |
| `price` | `980.00` |
| `basis` | `total` ou `per_night` |
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

## 14. Quando algo dá errado

- **Uma conexão falhou**: teste de novo em **Configurações › Conexões**. A mensagem diz o motivo (chave recusada, sem acesso, sistema fora do ar).
- **Nada foi gravado**: confira o modo em **Operação**, se a chave de emergência está ligada e se há um plano aguardando aprovação.
- **As rodadas não acontecem**: o agendador do WordPress depende de visitas ao site. Num site com pouco movimento, configure um cron do servidor que chame `wp-cron.php` a cada 5 minutos.
- **O idioma**: as telas seguem o idioma do seu perfil no WordPress (português do Brasil ou inglês).

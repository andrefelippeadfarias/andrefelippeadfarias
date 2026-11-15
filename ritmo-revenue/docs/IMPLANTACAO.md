# Ritmo Revenue: implantação no seu site e protocolo de teste

Este guia é para você colocar o Ritmo Revenue 1.0.0 no seu site **hoje** e começar os testes com as suas pousadas, com segurança. Ele não substitui o [guia do usuário](GUIA-DO-USUARIO.md), que explica cada tela; aqui está o caminho da implantação, do primeiro dia até a troca.

**A regra que vale do começo ao fim:** a skill de gestão de receita que hoje grava os preços continua trabalhando normalmente, e o plugin fica **só em modo sombra** (ele lê, decide e mostra o que faria, mas não grava nada). **A skill e o plugin nunca gravam ao mesmo tempo.** A troca só acontece quando os critérios da seção 9 forem cumpridos, e sempre desligando a skill antes.

**Importante:** o plugin foi testado só contra simuladores que imitam o formato real do Beds24 e do PriceLabs. As primeiras leituras reais precisam ser conferidas por você, com a lista da seção 7.

## Resumo do dia

- [ ] 1. Conferir a hospedagem (seção 1) e fazer um backup do site.
- [ ] 2. Gerar o arquivo do plugin, o zip (seção 2).
- [ ] 3. Colocar a chave do cofre no `wp-config.php` (seção 3).
- [ ] 4. Instalar e ativar o plugin (seção 4).
- [ ] 5. Pegar o token do Beds24 (só leitura) e a chave da API do PriceLabs (seção 5).
- [ ] 6. Fazer o assistente de configuração até a primeira rodada em sombra (seção 6).
- [ ] 7. Conferir as primeiras leituras reais no Diagnóstico e nas telas (seção 7).
- [ ] 8. Começar o protocolo de teste em sombra, com a skill operando (seção 8).

## 1. Hospedagem: o que o site precisa

| Item | Mínimo | Onde conferir |
|---|---|---|
| PHP | 8.1 ou mais novo | WordPress: **Ferramentas › Diagnóstico › Informação › Servidor** (em versões antigas do WordPress: Saúde do site › Informações) |
| WordPress | 6.5 ou mais novo | **Painel › Atualizações** |
| HTTPS | cadeado no navegador, endereço começando com `https://` | barra de endereço do site |
| Extensões do PHP | `sodium` (criptografia das chaves) e `zip` (instalar pelo envio do zip) | **Ferramentas › Diagnóstico › Informação › Servidor** (em versões antigas do WordPress: Saúde do site › Informações); se faltar, peça à hospedagem |
| Agendador | WP-Cron funcionando ou um cron de verdade (abaixo) | **Ferramentas › Diagnóstico** (antes: Saúde do site): nada crítico sobre "eventos agendados" ou "solicitação de loopback" |
| Fuso horário do site | o da sua cidade | **Configurações › Geral › Fuso horário** |
| Tamanho de envio | maior que o zip (cerca de 1,8 MB; o padrão do PHP, 2 MB, basta) | **Mídia › Adicionar arquivo de mídia** (antes: Adicionar novo) mostra o "tamanho máximo de envio" |

**Agendador.** O plugin roda sozinho (uma rodada completa por dia, às 07:30 no fuso da propriedade, se você não mudar). O agendador do WordPress (WP-Cron) só acorda quando alguém visita o site. Num site com pouca visita, peça à hospedagem (ou configure no painel dela, em "Tarefas cron") um cron que chame o WordPress a cada 5 minutos:

```
*/5 * * * * curl -s https://SEU-SITE/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

Troque `SEU-SITE` pelo endereço do seu site. Com esse cron funcionando, você pode (não é obrigatório) colocar `define('DISABLE_WP_CRON', true);` no `wp-config.php`, para o WordPress não depender das visitas.

**Backup.** Antes de instalar, faça um backup completo (arquivos e banco de dados) pelo painel da hospedagem ou pelo seu plugin de backup.

## 2. Gerar o arquivo do plugin (o zip)

O arquivo que você instala é `ritmo-revenue-1.0.0.zip`. Ele é gerado a partir da pasta do projeto, com um comando:

```
bin/build.sh
```

Se preferir, peça ao Claude: "gere o zip do plugin". O comando:

- atualiza as traduções e confere que todo texto está traduzido para o português;
- confere a versão (1.0.0) no plugin, no `readme.txt` e no histórico de mudanças;
- deixa de fora o que não é do plugin (testes, simuladores, ferramentas, o plano interno);
- confere que nenhum arquivo do zip tem dados das suas pousadas, chaves ou senhas, e que todo o código roda no PHP 8.1;
- gera `dist/ritmo-revenue-1.0.0.zip` e mostra a "impressão digital" do arquivo (`sha256`), gravada também em `dist/ritmo-revenue-1.0.0.zip.sha256`.

Se o comando parar com "BUILD FAILED", ele diz o motivo; não instale um zip de uma tentativa que falhou.

## 3. A chave do cofre (antes de colar qualquer chave)

O plugin guarda o token do Beds24 e a chave do PriceLabs criptografados. Para essa proteção não depender só do banco de dados, coloque uma chave própria do plugin no `wp-config.php` **antes** de fazer o assistente:

1. Abra o `wp-config.php` pelo gerenciador de arquivos da hospedagem (ou FTP).
2. Logo acima da linha `/* That's all, stop editing! */` (ou "Isso é tudo, pare de editar"), acrescente:

   ```php
   define('RITMO_SECRET_KEY', 'COLE-AQUI-UMA-FRASE-ALEATORIA-COM-PELO-MENOS-32-CARACTERES');
   ```

3. Use uma sequência aleatória longa (por exemplo, um dos valores gerados em https://api.wordpress.org/secret-key/1.1/salt/), só com letras, números e símbolos simples, sem aspas.
4. Guarde essa chave junto com as senhas do site. Se ela mudar ou se perder, o plugin não consegue mais ler as chaves salvas e você precisa colar o token e a chave de novo (nada mais se perde).

**Não** coloque no `wp-config.php` do site de verdade as linhas de desenvolvimento `RITMO_DEV` nem `RITMO_LICENCE_DEV`.

## 4. Instalar e ativar

1. **Plugins › Adicionar plugin › Enviar plugin** (em versões antigas do WordPress: Adicionar novo).
2. **Escolher arquivo**, selecione `ritmo-revenue-1.0.0.zip` e clique em **Instalar agora**.
3. Quando aparecer "Plugin instalado com sucesso", clique em **Ativar plugin**.
4. Confira em **Plugins**: "Ritmo Revenue", **Versão 1.0.0**, ativo. O menu **Ritmo Revenue** aparece no painel.

Se o envio disser que o arquivo excede o tamanho máximo, peça à hospedagem para aumentar o limite de envio (`upload_max_filesize` e `post_max_size`, 8 MB bastam) ou instale pelo gerenciador de arquivos: descompacte o zip e envie a pasta `ritmo-revenue` para `wp-content/plugins/`; depois ative em **Plugins**.

Nas telas do plugin vai aparecer um aviso de que não há licença. Isso é esperado: **sem licença o plugin funciona em modo sombra**, que é tudo o que o teste precisa. A licença só será necessária no dia da troca (seção 10).

## 5. As chaves das contas

### Beds24: token só de leitura

No começo o plugin só lê. Um token de leitura basta e é o mais seguro:

1. No Beds24, abra **Settings › Marketplace › API** (Configurações › Marketplace › API).
2. Gere um **token de longa duração** (long life token) marcando só **leitura** (read) em: **bookings**, **bookings-financial**, **inventory** e **properties**.
   - Não marque **bookings-personal** (dados dos hóspedes), **accounts** nem **channels**, e nada de escrita (write).
   - O plugin precisa de **bookings-financial** para ler o valor das reservas (receita e diária média dos relatórios).
3. Copie o token e guarde-o num lugar seguro até o passo 2 do assistente.
4. Anote o **número de cada propriedade** no Beds24 (Property ID, em **Settings › Properties**) e o **número de cada quarto** (Room ID, na lista de quartos da propriedade).

Se a tela do Beds24 oferecer só o **código de convite** (invite code) e não o token de longa duração: gere o convite com as mesmas permissões só de leitura; logo em seguida (o convite vale pouco tempo), abra a documentação da API do Beds24 (`https://api.beds24.com/v2`), seção **Authentication › GET /authentication/setup**, clique em **Try it out**, cole o convite em **code**, dê um nome em **deviceName** (por exemplo, `ritmo-leitura`) e clique em **Execute**. A resposta traz um **refreshToken**: é ele que você cola no plugin, escolhendo o tipo **Token de renovação**. Se tiver dúvida, peça ajuda ao Claude, sem colar o token na conversa.

Gravar a estadia mínima e as regras de chegada e saída no Beds24 (só depois da troca, e só se você ligar essa escrita) vai exigir um token com permissão de escrita no inventário. **Hoje não.**

### PriceLabs: chave da API

1. No PriceLabs (`app.pricelabs.co`), abra **Account Settings › API Details**.
2. Se a API ainda não estiver habilitada, habilite (o PriceLabs cobra por anúncio por mês; confira o valor na tela).
3. Copie a **API key**. Ela precisa ser da conta **dona** (um subusuário não consegue ler as reservas).

A chave do PriceLabs não tem uma versão "só de leitura". Não tem problema: **em modo sombra o plugin não grava nada** (o envio que ele faz para ler o calendário de preços é uma leitura). A skill e o plugin podem usar a mesma chave; o limite de chamadas do PriceLabs (60 por minuto, 1.000 por hora) é dividido entre os dois. O plugin controla as chamadas dele e, se o PriceLabs recusar por excesso, espera e tenta de novo mais tarde; se isso acontecer todo dia, afaste o horário da rodada do plugin do horário das rotinas da skill.

Nunca cole tokens ou chaves numa conversa, num e-mail ou num arquivo do projeto: só nos campos do plugin.

## 6. O assistente de configuração, passo a passo

Abra **Ritmo Revenue › Configurações › Configuração** (é o assistente de configuração; o item **Assistente** do menu é outra coisa: o assistente de IA, opcional). São nove etapas; cada uma só abre quando a anterior termina.

1. **Boas-vindas e conferências.** As cinco conferências precisam estar "OK" (entre elas a criptografia das chaves, o agendador e o fuso horário; se disser que a proteção depende do banco, falta a `RITMO_SECRET_KEY` da seção 3). Quando tudo já está OK, o assistente pula esta etapa e abre direto na etapa 2.
2. **Conectar o PMS (Beds24).** Escolha o tipo de token (**Token de longa duração** para o token só de leitura), cole o token e digite os números das propriedades no Beds24, separados por vírgula se forem mais de uma. **Salvar e testar.** Se for recusado, a tela diz o motivo (token recusado, sem acesso, sistema fora do ar).
3. **Conectar o sistema de preços (PriceLabs).** Cole a chave da API e escreva `beds24` em "Nome do seu PMS no sistema de preços". O plugin lê os anúncios para sugerir os quartos.
4. **Propriedade.** Nome da pousada, fuso horário (o da sua cidade), moeda (BRL) e o nível de mercado (o padrão serve para a maioria). O código da propriedade é feito do nome e **não muda depois**.
5. **Quartos.** Uma linha por anúncio do PriceLabs. **Confira em cada linha** o número do quarto no Beds24 (Room ID) e o número de unidades: são só sugestões. Os valores mínimo, base e máximo vêm do anúncio. Se aparecerem quartos de outra pousada, marque **Não gerenciar este quarto** neles.
6. **Regras e calendário.** Comece pelo modelo **Conservador (recomendado)** e confira os dias de fim de semana. Para a comparação com a skill ficar justa, o ideal é o plugin usar regras parecidas com as dela: você pode pedir ao Claude um arquivo de regras (preset `.json`) feito a partir das regras atuais da skill e enviá-lo nesta etapa. Sem ele, espere mais diferenças e anote-as (seção 8).
7. **Limites de segurança.** O menor preço permitido de cada quarto, o limite semanal de mudança do anúncio e se o modo autônomo pode existir no site: **deixe o modo autônomo desligado**. Para confirmar, digite o id da propriedade (o código que a tela mostra).
8. **Primeira rodada em sombra.** O plugin lê tudo e decide cada noite, sem gravar nada.
9. **O que o plugin faria.** Quantas noites ele mudaria, quantas subiriam e quantas desceriam, e por quê. **Durante o teste, ignore a sugestão desta etapa de passar para aprovação depois de algumas rodadas**: fique em sombra pelo menos 7 dias e enquanto a skill gravar preços (seções 8 e 9). Para sair da sombra, a tela **Operação** pede o id da propriedade e a confirmação de que nenhuma outra ferramenta grava preços nela.

Se a etapa 9 disser que muitas noites ficaram sem mudança **por falta de preço de mercado**, é normal num site novo: o plugin compara com os preços dos concorrentes, que você traz em **Mercado** (arquivo CSV, formato no guia do usuário, seção 10). Peça ao Claude um CSV nesse formato a partir da última pesquisa de concorrentes da skill, importe em **Mercado** e rode em sombra de novo (**Visão geral › Rodar em sombra agora**).

**Duas pousadas.** Faça o assistente de configuração para a primeira pousada até a etapa 9. Depois, no resumo do assistente, volte à etapa **Propriedade** e crie a segunda com o nome dela; nas etapas seguintes, marque **Não gerenciar este quarto** nos quartos da primeira. As conexões (etapas 2 e 3) valem para as duas; na etapa 2, os números das duas propriedades do Beds24 vão separados por vírgula.

Entre a etapa 4 (Propriedade) e a etapa 5 (Quartos), o agendador pode tentar uma rodada da pousada nova, que ainda não tem quartos. Ela aparece como **falhou**, o Diagnóstico mostra no registro "Uma rodada não pôde ser feita: nenhum quarto da propriedade está ligado ao PMS ainda" e pode surgir o alerta "Uma rodada agendada está atrasada". É esperado e não grava nada: salve os quartos (etapa 5) e o alerta some na rodada seguinte. Por isso, faça as etapas 4 e 5 em seguida.

**Memória.** Com o horizonte padrão de 60 noites, uma rodada de 12 quartos usa cerca de 85 MB. Não aumente `plan.horizon_nights` muito acima disso numa hospedagem com 128 MB de memória para o PHP: com 365 noites a rodada passa de 200 MB. Se o servidor parar uma rodada por falta de memória, ela é fechada como falha, a trava é liberada na hora e aparece o alerta "Uma rodada ficou sem memória".

O assistente de IA (Claude) é opcional e fica desligado; não é preciso para o teste.

## 7. Primeira conexão com as contas reais: o que conferir

Faça esta lista logo depois da primeira rodada em sombra e anote o resultado. Se algo não bater, **não siga para o protocolo**: gere o pacote de suporte (seção 8.4) e mande ao Claude.

- [ ] **Conexões.** Em **Configurações › Conexões**, **Testar agora** nas duas: as duas dizem "Conectado ao …" (por exemplo "Conectado ao Beds24: 1 propriedade visível." e "Conectado ao PriceLabs: 3 de 4 anúncios pertencem ao seu PMS.").
- [ ] **Diagnóstico.** Em **Configurações › Diagnóstico**: a proteção das chaves é a chave própria do `wp-config.php`; a última rodada terminou; o registro recente não tem erros de leitura (chave recusada, sem acesso, limite de chamadas, resposta fora do formato). Abra "Detalhes técnicos" se aparecer algo estranho.
- [ ] **Quartos.** Cada quarto do plugin aponta para o anúncio certo do PriceLabs e para o quarto certo do Beds24, com o número certo de unidades.
- [ ] **Valores do anúncio.** Mínimo, base e máximo de cada quarto no plugin iguais aos do PriceLabs.
- [ ] **Ocupação.** Em **Noites**, escolha três noites de cada pousada: uma esgotada, uma com parte vendida e uma vazia. As unidades vendidas batem com o calendário do Beds24.
- [ ] **Preço de agora.** Nessas mesmas noites, o preço que o plugin mostra bate com o preço do PriceLabs, em reais.
- [ ] **Reservas e receita.** **Visão geral**: a ocupação dos próximos 7 e 30 dias e a receita reservada estão perto do último relatório da skill. **Relatórios › Canais**: as reservas dos últimos 28 dias por canal batem com o Beds24 (diferença pequena por reservas do mesmo dia é normal).
- [ ] **Nada gravado.** **Registro de mudanças** vazio, e o **Exportar CSV** dele sai sem linhas. No PriceLabs e no Beds24, nenhuma mudança feita pela API do plugin.
- [ ] **Alertas.** Leia cada alerta. Os de "mudança feita por outra pessoa" são as gravações da skill (seção 8.2). Qualquer outro alerta que você não entenda vai para o pacote de suporte.
- [ ] **Fuso e moeda.** Datas e "hoje" no fuso da sua cidade; valores em R$.

## 8. Protocolo de teste: o plugin em sombra, a skill operando

Durante o teste **a skill continua gravando como hoje** e o plugin **fica em modo sombra**. Em **Operação**, o modo de cada propriedade deve dizer **Sombra** todos os dias. Não troque o modo, não ligue a escrita de estadia, não aprove nada.

### 8.1 O horário das rodadas

O plugin faz uma rodada completa por dia, **às 07:30** no fuso da propriedade (padrão). Para comparar, ela precisa acontecer **antes da primeira rotina do dia da skill**: assim a rodada do plugin mostra o que ele faria com o calendário do jeito que a skill deixou na véspera, e logo depois a skill grava o que ela decidiu. Se a primeira rotina da skill for antes das 07:30, ajuste em **Regras › Rodadas e alertas › Rodada completa às** (por exemplo, meia hora antes da rotina).

Nos dias de comparação, **não use "Rodar em sombra agora" depois que a skill gravou** (veja 8.2). Pode usar à vontade antes da rotina da skill.

### 8.2 Noites congeladas e alertas de "outra pessoa" são esperados

Quando alguém que não é o plugin muda uma noite no PriceLabs ou no Beds24, o plugin percebe na rodada seguinte, avisa ("mudança feita por outra pessoa") e **congela** aquela noite por 7 dias: ele não propõe nada nela. No teste, quem muda é a skill, todos os dias. Por isso:

- os alertas de mudança feita por outra pessoa e as noites congeladas (filtro **congelada** em **Noites**) são, na prática, **a lista do que a skill mexeu**: não libere essas noites. O "O que fazer" do alerta (liberar o congelamento) vale para o uso normal, não durante o teste;
- uma noite que a skill mudou fica fora das propostas do plugin por alguns dias; a comparação dela é feita com a proposta da rodada **anterior** à mudança (é por isso que a rodada do plugin vem antes da rotina da skill).

### 8.3 A comparação de cada dia

Todo dia, depois da primeira rotina da skill (de preferência no mesmo horário):

1. **O que o plugin faria.** Em **Noites**, filtro **a última rodada mudaria**: são as noites em que o plugin mudaria o piso ou o teto, com a seta para cima ou para baixo. Clique numa noite para ver a regra que decidiu e o porquê.
2. **O que a skill gravou.** Abra o relatório da rotina da skill (a lista das mudanças gravadas).
3. **Compare noite por noite**, quarto por quarto:
   - **mesma direção** (os dois sobem ou os dois descem) = acordo, mesmo que o valor seja um pouco diferente;
   - **o plugin mudaria e a skill não mexeu** = diferença;
   - **a skill mexeu e o plugin não mudaria** = diferença;
   - **direções opostas** = diferença importante.
4. **Explique cada diferença** com o porquê do plugin (detalhe da noite) e o motivo da skill. Explicações comuns: regra configurada diferente (ajuste em **Regras** e anote), falta de preço de mercado, ordem do dono que só a skill conhece, noite congelada.
5. **Anote no diário** (modelo abaixo).
6. **Relatório da rodada.** Em **Relatórios › Relatórios das rodadas**, a frase-resumo da rodada do dia (quantas noites leu, quantas mudaria, quantas ficaram sem preço de mercado). Guarde junto do diário.
7. **Exportações CSV.** Uma vez por semana, baixe em **Relatórios** o CSV de **Ocupação**, **Mês e metas** e **Canais** e compare com os números da skill: confirma que o plugin lê as reservas e o calendário do mesmo jeito. O CSV do **Registro de mudanças** continua vazio (nada gravado).

Modelo do diário (uma linha por dia e por pousada):

| Data | Rodada do plugin terminou? | Noites que o plugin mudaria | Mesma direção da skill | Diferenças | Explicadas | Sem explicação | Ajuste feito nas regras |
|---|---|---|---|---|---|---|---|
| dd/mm | sim | 12 | 9 | 3 | 3 | 0 | — |

**O que observar além das diferenças:**

- a rodada das 07:30 aconteceu todo dia (se não aconteceu, o agendador não está rodando: seção 1);
- nenhum erro de leitura no **Diagnóstico** e nenhum alerta de limite de chamadas;
- o plugin **nunca** propõe subir o preço de hoje, nem preço abaixo do piso de um quarto (se aparecer, é um defeito: pacote de suporte na hora);
- noites de fim de semana, feriados e esgotadas: são onde as diferenças mais importam;
- tempo de cada rodada (no Diagnóstico) e se o site ficou lento.

Se mudar uma regra, a mudança vale a partir da rodada seguinte; anote no diário para não confundir a comparação.

### 8.4 O pacote de suporte para o Claude

Quando quiser uma segunda opinião sobre o teste, ou se algo falhar:

1. **Configurações › Diagnóstico › Baixar o arquivo de suporte.** É um arquivo `.json` com as versões, o ambiente, as configurações, as regras, os quartos, as últimas rodadas, os alertas e o registro recente. Ele **nunca** leva chaves, reservas, nomes ou e-mails.
2. Mande ao Claude o arquivo, o diário dos últimos dias e, se ajudar, uma imagem da tela **Noites** com o filtro **a última rodada mudaria**, com a pergunta (por exemplo: "compare a rodada de hoje com a rotina da skill" ou "por que o plugin quer baixar a noite tal").

## 9. Critérios para considerar a troca

Considere a troca só quando **todos** estiverem cumpridos:

- [ ] **Pelo menos 7 dias seguidos** de teste, com pelo menos um fim de semana (melhor ainda: 14 dias, com um feriado ou fim de semana prolongado no meio).
- [ ] A rodada do plugin aconteceu **todos os dias**, sem erro de leitura que não tenha sido resolvido.
- [ ] A lista da seção 7 conferida de novo no fim do período, tudo batendo.
- [ ] **Nos últimos 3 dias, toda diferença explicada** e nenhuma diferença de direção oposta sem explicação.
- [ ] Na média do período, **pelo menos 8 de cada 10 noites** que o plugin mudaria vão na mesma direção da skill (ou a diferença foi explicada e as regras foram ajustadas).
- [ ] Nenhuma proposta fora das travas (preço de hoje subindo, preço abaixo do piso).
- [ ] **Licença ativa** em **Configurações › Licença** (seção 10, passo 1).
- [ ] Você leu a tela **Aprovações** do guia do usuário (seção 5) e sabe usar a **chave de emergência** (seção 11).

## 10. A troca, passo a passo

Escolha um dia calmo, sem feriado na semana, com você disponível para olhar o plugin de manhã e à tarde.

**Na véspera**

1. **Licença.** O modo aprovação exige licença ativa. Como o servidor de licença ainda não existe, combine com o Claude, antes do dia, como fica a licença do seu site, e confira em **Configurações › Licença** que ela aparece como ativa. Sem licença, a tela **Operação** mostra a condição "licença" como não cumprida e não deixa sair da sombra.
2. Gere o pacote de suporte e guarde o diário.

**No dia**

1. **Desligue a skill primeiro.** Pause todas as rotinas agendadas da skill e confirme que nenhuma está rodando nem vai rodar. Espere a última gravação dela aparecer no relatório. **Daqui em diante só o plugin vai gravar.**
2. Em **Configurações › Conexões**, **Testar agora** nas duas conexões (o modo aprovação pede as duas testadas nas últimas 24 horas).
3. Em **Visão geral › Rodar em sombra agora**: uma rodada nova, já sem a skill.
4. Em **Operação**, confira as condições ("x de y condições cumpridas") e cumpra o que faltar.
5. Escolha **Aprovação** e clique em **Trocar o modo**. **Nunca o modo autônomo no primeiro dia** (a tela nem deixa: ele exige dias em aprovação e planos gravados).
6. Na primeira rodada completa, abra **Aprovações**:
   - aprove primeiro **uma parte pequena** (um quarto, poucas noites);
   - confira no PriceLabs que a mudança chegou, e em **Registro de mudanças** que ela aparece com o antes e o depois;
   - se a mudança tocou hoje ou amanhã, aperte **Sync Now** no PriceLabs para o canal receber hoje;
   - estando tudo certo, aprove o resto do plano ou recuse o que não concordar.
7. As noites que a skill mudou nos últimos dias continuam **congeladas** por até 7 dias: o plugin não mexe nelas. Deixe expirar ou, se tiver certeza, libere no detalhe da noite.
8. **Estadia mínima e regras de chegada e saída** ficam como a skill deixou: o plugin só as grava se você ligar a escrita de estadia (desligada por padrão). Ligue só depois de alguns dias em aprovação, com um token do Beds24 com permissão de escrita no inventário (seção 5).

**Nos dias seguintes**

- Aprove todos os dias, leia os alertas e continue o diário.
- O modo **autônomo** só depois de pelo menos 7 dias em aprovação e 5 planos aprovados e gravados (a tela **Operação** conta), e só se você quiser. Ele também precisa ser permitido nos limites de segurança por um administrador.

**Voltar para a skill (se precisar)**

1. **Primeiro o plugin:** em **Operação**, volte a propriedade para **Sombra** (ou ligue a chave de emergência).
2. **Só depois** reative as rotinas da skill.

Nunca deixe os dois gravando, nem por uma rotina.

## 11. Se algo falhar

| Situação | O que fazer |
|---|---|
| O plugin gravou algo que não devia, ou você não tem certeza | **Operação › Ligar a chave: parar toda gravação** (chave de emergência). Nada mais é gravado; as rodadas só leem. |
| As telas não abrem e você precisa parar as gravações | No `wp-config.php`: `define('RITMO_KILL_SWITCH', true);` A tela não consegue desligar essa chave; só tirando a linha. |
| Uma mudança gravada precisa voltar | **Registro de mudanças**: marque, **Desfazer as mudanças marcadas…**, confira e **Desfazer agora**. |
| Uma conexão falhou | **Configurações › Conexões › Testar agora**; a mensagem diz o motivo. Token vencido ou recusado: gere outro e cole em **Trocar o token**. |
| As rodadas não acontecem | Confira o agendador (seção 1) e o **Diagnóstico**. |
| Quer parar o plugin por completo | **Plugins › Desativar** no Ritmo Revenue. Para tudo, e **nenhum dado se perde**. Para voltar, **Ativar**. |
| O site ficou fora do ar depois de instalar | Pelo gerenciador de arquivos ou FTP, renomeie a pasta `wp-content/plugins/ritmo-revenue` para `ritmo-revenue-desligado`. O WordPress desativa o plugin e o site volta. |
| Qualquer outra coisa | Pacote de suporte (seção 8.4) para o Claude. |

**Cuidado com "Excluir".** Excluir o plugin em **Plugins** só apaga os dados dele (tabelas, configurações, chaves salvas, papéis) se a opção **Configurações › Desinstalar › Apagar todas as tabelas, configurações e chaves salvas do plugin** estiver marcada. Durante o teste, deixe essa opção desmarcada.

## 12. Para lembrar

- Sombra é o padrão; só uma pessoa tira a propriedade da sombra.
- O plugin **nunca** sobe o preço de hoje, **nunca** grava preço nem reserva no Beds24, e só muda mínimo, base e máximo do anúncio com aprovação explícita de um administrador.
- A skill e o plugin **nunca** gravam ao mesmo tempo: na troca, desligue a skill antes; na volta, ponha o plugin em sombra antes.

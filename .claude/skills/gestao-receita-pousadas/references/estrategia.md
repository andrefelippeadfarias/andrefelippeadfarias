# Estratégia de receita (versão 1, 14/10/2026)

Documento único da estratégia das duas pousadas. As regras detalhadas continuam em `regras.md`; este arquivo diz **o que queremos, em que ordem decidimos e como medimos**. Toda rotina completa lê este arquivo antes de decidir. Mudança de estratégia só com decisão do dono, registrada na seção 6 de `regras.md` e em `historico-da-skill.md`.

## 1. Objetivo e métrica
- **Objetivo do dono:** lotação e lucratividade sempre máximas, dentro do padrão de qualidade das suítes com banheira.
- **Métrica mestra (pacing):** ocupação acumulada a partir de hoje: 7 dias 70%, 15 dias 50%, 30 dias 35%, 45 dias 25%, 60 dias 15%. Cada noite é julgada pela meta da janela em que cai (0 a 6 dias 70%; 7 a 14 dias 50%; 15 a 29 dias 35%; 30 a 44 dias 25%; 45 dias ou mais 15%).
- **Métricas de apoio:** diária média paga por noite (hoje cerca de 50% do preço enviado no Recanto e 55% na Villa; Queen 2 só 23% a 34%, anomalia em investigação), faixa de preço de mercado de cada noite (seção 3), receita da semana e vendas nas 72 h depois de cada mudança (`aprendizados.md`, tabela "Efeito das mudanças").
- **Padrão de qualidade (dono, 08/10):** sexta, sábado e feriado das suítes com banheira (todas menos a Balcony) a partir de R$ 1.000 enviados; abaixo só com dado de mercado documentado.

## 2. Posicionamento por pousada e por suíte
- **Recanto dos Moinhos** (16 unidades; 5 estrelas, nota 8,4; hidro): opera no **topo da faixa alvo** de cada período. Pares de comparação: pousadas e hotéis com hidro ou spa, 4 a 5 estrelas ou nota 8,0 ou mais.
  - Double e Queen (2): demanda alta; cobram mais (+10% por rodada nas noites com 67% ou mais vendidas a até 14 dias). Queen (2) nunca recebe desconto.
  - Queen 7: demanda média; vende quase só de última hora (26 de 49 reservas com 0 a 1 dia de antecedência). Dias de semana no mínimo (R$ 800) e Regra I em D0 a D3; fins de semana perto do mercado.
  - Afrodite (1 unidade): Regra C (−30% a até 13 dias, piso R$ 1.500); fica acima da Queen (2) nos feriados.
- **Villa Dolce Amore** (15 unidades; 3 estrelas, nota 8,0; sem hidro listada na Booking): opera na **base da faixa alvo**. Pares: até 4 estrelas, nota 7,0 a 9,2.
  - King Spa 7: demanda alta desde 13/10 (reavaliar a cada rodada; volta a média se 15 dias ficar abaixo de 50% em dois recálculos seguidos).
  - King Spa 2: média, candidata a alta.
  - Balcony (sem banheira, demanda baixa): sempre cerca de 10% abaixo da suíte com banheira livre mais barata da Villa (Regra G), nunca acima, nunca abaixo de R$ 630; preço em piso e teto quando precisa travar.
- **Fato de mercado que molda a Villa:** metade da oferta comparável custa menos de R$ 480 por noite em dia de semana. Por isso a Villa só vende dia de semana de última hora ou em evento; a estratégia dela é volume no fim de semana e Regra I em D0 a D3, sem queimar o padrão de qualidade.

## 3. As cinco faixas e a sazonalidade (o "onde queremos estar")
- Cada noite tem uma **faixa alvo** vinda do calendário de sazonalidade (`dados/sazonalidade.json`): muito baixa e baixa demanda → faixa **baixo**; média → **médio**; alta → **alto**; muito alta → **muito alto**. Nunca abaixo de "baixo" fora de D0 a D3.
- A **faixa atual** é onde o nosso preço de vitrine (enviado × fator de vitrine: Recanto 0,61, Villa 0,53) cai entre os quintis dos pares (`dados/mercado_faixas.json`, refeito toda segunda pela varredura por IA).
- Leitura do ano (Campos é contra-sazonal ao estado): **inverno é o pico** (junho a agosto; julho no topo, mercado 62% a 67%; Corpus Christi, 9 de Julho e Dia dos Namorados acima de 90%); **segundo pico** Réveillon (96%) e Natal (70% a 80%); **feriadões** (Finados, Consciência Negra, Carnaval, Páscoa, 7 de Setembro, 12 de Outubro, 15 de Novembro) em faixa alto com 2 noites (3 no Carnaval e no Réveillon); **vales** em fevereiro e março, nas semanas de outubro e novembro e na primeira quinzena de dezembro (faixa baixo em dom a qui, médio em sex e sáb).
- Antecedência de venda: 60 a 90 dias nos picos, 30 a 45 nos feriadões, 7 a 20 nos vales. Preço de pico fica aberto cedo e não se desconta antes de D-10.

## 4. Como a rotina decide (ordem de precedência)
1. **Travas do dono e de segurança** (nunca se mexe): descontos de OTA e ofertas da Booking, Genius até o nível 3, sábado sozinho bloqueado (Regra H), Queen (2) sem desconto, padrão de qualidade de R$ 1.000, feriado nunca abaixo de R$ 800 reais, preço nunca abaixo de 70% do mínimo de referência, base e mínimo do anúncio no máximo ±15% por semana.
2. **Dado fresco**: a decisão usa o calendário recalculado (`mesclar_recalculo.py`), nunca o calendário em cache; vendas e cancelamentos se confirmam pela diferença de ocupação entre rodadas.
3. **Alvo da noite**: faixa alvo da sazonalidade (seção 3) e classificação de demanda da suíte (seção 2).
4. **Passo da rodada** (regra de flutuação, `regras.md` 4c): atrás da meta → desce um degrau (−10%; −15% se já está em alto ou muito alto), sem passar abaixo da faixa alvo menos uma; à frente da meta → sobe um degrau (+10%) até a faixa alvo mais uma; na meta → aproxima do alvo devagar (±5%). Um passo por rodada; a rodada seguinte mede antes do próximo.
5. **Mecânica do passo**: sempre percentual com piso (`min_price`) por bloco de suavização (`percentual.py`); a escada da Regra F dá os percentuais de dia útil e de fim de semana por antecedência; a Regra I dá o corte de última hora (D0 a D3, até 20% abaixo do mínimo, só com unidade livre; em demanda alta só para a última unidade; data com mais de 70% vendida não é cortada); a Regra G realinha a Balcony; a Regra A ajusta a estadia mínima (1 noite a até 14 dias, 2 em sábado e feriado, 3 em Finados, Carnaval e Réveillon).
6. **Validação e conferência**: `validar_plano.py` antes de gravar (nunca grava com erro); recálculo e `ler_recalculo.py` depois (regra viva, piso igual ao gravado); registro, aprendizado, commit.
7. **Envio**: o que é gravado depois do envio diário só chega aos canais no dia seguinte; em D0 a D3 a rotina pede o Sync Now ao dono no relatório.

## 5. Táticas por tipo de noite
- **Dia de semana em vale** (dom a qui, demanda baixa ou muito baixa): mínimo do quarto; Regra I em D0 a D3 com unidade livre; Balcony 10% abaixo da King Spa livre. Não se desce abaixo do mínimo antes de D3.
- **Sexta e sábado fora de pico**: faixa médio; suítes com banheira no padrão de R$ 1.000 ou acima; estadia mínima 2 no sábado (dono); quando a noite está acima da meta, +10% por rodada.
- **Feriadões**: faixa alto, 2 noites (3 nos picos), piso de R$ 800 reais; abertos com 30 a 45 dias; se em D-7 ainda houver sobra, desce para médio, nunca para baixo; noites esgotadas recebem piso de proteção para um cancelamento não reabrir barato.
- **Picos (Réveillon, Corpus Christi, 9 de Julho, Dia dos Namorados, julho)**: faixa muito alto, 3 noites (2 no Dia dos Namorados), abertos com 60 a 90 dias; sem desconto antes de D-10.
- **Eventos de meio de semana** (Oktoberfest, congressos, provas): noites do evento tratadas como alta, mesmo em mês de vale.
- **Última unidade**: nunca é cortada em data com mais de 70% vendida; em demanda alta, é a única que recebe Regra I.

## 6. Pesquisa e aprendizado (feitos por agentes de IA)
- **Toda rodada**: coleta (workflow `coleta-pricelabs`), recálculo dos quartos com calendário velho, classificação das noites (`faixas_de_preco.py quartos`), efeito das mudanças anteriores.
- **Toda segunda (07:53)**: revisão semanal; varredura da concorrência inteira (`varredura-concorrencia`) e novos cortes das faixas; revisão da classificação de demanda; referência semanal da base.
- **Todo mês (primeira segunda)**: pesquisa de sazonalidade (`sazonalidade-campos`) e atualização do calendário; confirmação dos eventos marcados "estimado".
- **Sempre**: decisão nova do dono vira regra no mesmo dia; o que foi medido vai para `aprendizados.md`; tudo com commit.

## 7. O que acompanhamos no relatório
Semáforo da meta de 7 dias; tabela quarto × janelas; faixa atual × faixa alvo das próximas noites; o que mudou e quando chega aos canais; alertas (envio, cancelamentos, estadia mínima, mudanças manuais); itens que só o dono resolve (no máximo 3); o que aprendemos.

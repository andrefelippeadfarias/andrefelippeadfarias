# Briefing do conselho

Status: preenchido pelo coordenador em 25/09/2026. Nenhum agente foi iniciado por este arquivo.

- **Pedido original (usuário, 25/09/2026):** "Vamos criar um projeto para executarmos tudo localmente de forma automática 7 x por dia. Usando o mínimo de tokens por dia e usando a decisão jev (Modelo Gratuito Oficial (jev-1.13-free)), para fazermos todas as tomadas de decisão."
- **Contexto:** "tudo" = a rotina de revenue management do PriceLabs definida em `pricelabs/relatorio-ocupacao-recanto-dos-moinhos.md` (v4) e `pricelabs/registro-de-mudancas.md`: medir ocupação por quarto contra as metas (7d 70%, 15d 50%, 30d 35%, 45d 25%, 60d 15%), ler reservas, comparar valor pago com preço enviado e aplicar os ajustes permitidos do playbook (seção 6 do relatório). Hotéis: Pousada Recanto dos Moinhos e Villa Dolce Amore, 7 tipos de quarto no Beds24, grupo PriceLabs 124405.
- **Resultado esperado e critérios de aceitação:**
  1. Projeto executável na máquina local do usuário (Windows, pelas capturas de tela), sem depender do Claude em tempo de execução.
  2. Execução automática 7 vezes por dia.
  3. Todas as decisões de julgamento tomadas pelo Jev `jev-1.13-free`; código determinístico coleta dados, calcula métricas, aplica limites e executa.
  4. Mínimo de tokens: uma chamada ao Jev por execução, com perguntas tipadas e curtas; nenhuma geração de texto por LLM.
  5. Segurança: nenhuma ação fora da lista permitida; falha, dúvida ou baixa confiança do Jev resulta em "não fazer nada"; registro de cada decisão e efeito; como desfazer.
- **Restrições:**
  - Ofertas da Booking são obrigatórias e fixas: o sistema nunca as altera, nem tem como alterá-las.
  - Não alterar preço base, mínimo, máximo nem personalizações do grupo automaticamente (são decisões estratégicas aprovadas manualmente).
  - Balcony é categoria de transbordo: fora das metas e das ações de desconto.
  - Queen Spa (2): sem mudança de preço base até resolver a anomalia de 66%.
  - Credenciais em variáveis de ambiente/arquivo local fora do Git; nunca no repositório.
- **Orçamento:** Jev `jev-1.13-free` (gratuito, conforme o usuário). PriceLabs Customer API: custo conforme a conta do usuário (a confirmar). Nenhum outro modelo pago em tempo de execução.
- **Fora do escopo:** alterar Beds24, OTAs, ofertas, preço base/mín/máx, personalizações do grupo, tabela de ocupação (só na tela), "virada" (Fase 2).
- **Stack, contratos e decisões existentes:** repositório sem código prévio; Python é a linguagem dos utilitários da skill e está disponível no Windows. PriceLabs acessível por API (Customer API) e pelo conector MCP (este só dentro do Claude).
- **Especialidades relevantes:** produto/domínio (revenue management), arquitetura, integrações (PriceLabs, Jev), segurança (efeitos financeiros automáticos), QA, operação (agendamento Windows, logs, rollback).
- **Perfil:** crítico (altera preços automaticamente = dinheiro). Revisão independente obrigatória, cenários negativos e plano de recuperação.
- **Responsável pela síntese e entrega:** coordenador (Claude Code nesta sessão).

# Histórico da Skill

Cada mudança feita na própria Skill (regras, parâmetros, scripts, fluxo) entra aqui: data, o que mudou, por quê (evidência) e como desfazer. A entrada mais nova fica em cima.

## 06/10/2026 14:05: esclarecimento da Regra F
- **O quê:** a saída da escada (50% vendido) não vale para preços definidos por decisão do dono. Nesses preços, a Skill segura até 70% de ocupação e depois sobe no máximo 10% por rodada.
- **Por quê:** a Double em 16, 17 e 18/10 chegou a 50% horas depois do corte pedido pelo dono para lotar. Voltar ao algoritmo levaria a Double de R$ 1.400 para cerca de R$ 2.600 e desfaria a decisão dele.
- **Como desfazer:** remover o item novo da Regra F em `regras.md`.

## 06/10/2026: v1, criação
- **O quê:** a Skill nasceu a partir do registro de 25/09 a 06/10 e desta conversa. As regras foram consolidadas em `regras.md`; os dados de máquina, em `dados/quartos.json`. Também foram criados os scripts (ocupação, validação e conferência do recálculo) e o workflow de coleta. A ampliação de `.claude/settings.json` (liberar gravação no PriceLabs, Booking, git e scripts) foi bloqueada pela proteção da sessão: fica para o dono fazer. A rotina `trig_01BMLPUb6e9LDw1Xx1oYy3iT` passou a chamar a Skill.
- **Decisões do dono:** autonomia total, aprendizado automático e rotinas nesta conversa.
- **Limites de segurança:** preço nunca abaixo de 70% do mínimo de referência; base e mínimo com no máximo ±15% por semana; feriado nunca abaixo de R$ 800 de valor real.
- **Como desfazer:** reverter o commit da criação. A rotina volta ao texto antigo, salvo em `historico-da-skill.md` (seção "Texto antigo da rotina").

### Texto antigo da rotina (até 06/10)
Rotina "Agenda PriceLabs (5x ao dia)", `trig_01BMLPUb6e9LDw1Xx1oYy3iT`, cron `CRON_TZ=America/Sao_Paulo 53 7,10,13,16,19 * * *`. Início do texto antigo, para restaurar se preciso:

```text
Hora da ANÁLISE E AGENDA DIÁRIA do PriceLabs (rotina "Agenda diária PriceLabs", pedida pelo usuário). Responda em português do Brasil, cordial e direta, como gestora de receita e growth hacker da Pousada Recanto dos Moinhos e da Villa Dolce Amore.

## Regra de ouro: só leitura
- Use só ferramentas de LEITURA do conector PriceLabs (get_*). Não chame update_*, delete_*, accept_nudge, refresh_listing_pricing, map/unmap_listings, set_neighborhood_data_source nem update_customizations.
- Não faça commit nem push nesta análise.
- Só aplique uma mudança se o usuário responder aprovando aquele item (ex.: "aprovo 1 e 3"). Aí aplique só o aprovado, registre em pricelabs/registro-de-mudancas.md (antes, depois e como desfazer), faça commit e push na branch claude/pricelabs-hotel-occupancy-ku6zn5.
- Economize tokens: poucas chamadas, sem reler arquivos grandes.
- Se as ferramentas do PriceLabs não estiverem disponíveis (procure com ToolSearch "PriceLabs"), diga isso em uma linha e peça para reconectar o conector em claude.ai. Não invente números.

(Seguiam: tabela dos 7 quartos, metas, regras de negócio, passos 1 a 6 e o formato da resposta em 6 itens; o formato foi mantido no SKILL.md.)
```

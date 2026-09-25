# Conselho: Implementação

Status: decidido em 25/09/2026. A estratégia foi discutida na mesma rodada da decomposição (02-decomposicao.md).

## Estratégia

- **Ordem:** T1 → T2 → T3 → T4 → T5, com um único editor (coordenador). Cada incremento roda os testes do simulador.
- **Testes:**
  - com `unittest` da biblioteca padrão;
  - o simulador HTTP (`tests/simulador.py`) troca o transporte e registra cada chamada, o que prova zero POST ou DELETE em `observar` e com o arquivo `PARAR`;
  - os cenários negativos das rodadas de arquitetura viram testes: resposta do Jev fora do contrato, confiança baixa, 429/422, substituição humana, divergência, PARAR, segunda instância, chave-canário, relógio e fuso.
- **Dados de teste:** calendário sintético derivado do formato real de 25/09, sem ids de reserva nem dados de hóspedes. A ocupação real por faixa é conferida contra os números do relatório v4.
- **Reaproveitamento:** nenhum código de terceiros. Só a biblioteca padrão em produção; `jsonschema` e `coverage` apenas no desenvolvimento.

## Participantes

Os mesmos de 02-decomposicao.md. Não houve nova delegação de código: o coordenador implementa e um auditor independente revisa em 04.

## Decisão

- **Status:** decidido.
- **Limite de tentativas:** 3 por tarefa antes de voltar à arquitetura.

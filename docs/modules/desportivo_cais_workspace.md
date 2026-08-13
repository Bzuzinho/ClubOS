# Desportivo — Cais funcional

Data: 2026-08-13

## Responsabilidade

O Cais é a workspace de operação à beira da piscina para uma ocorrência concreta de `trainings`.

Fronteira canónica:

`Planeamento → Biblioteca → Treinos → Cais → Monitorização/Live`

- Planeamento decide quando, para quem, onde e com que conteúdo a sessão acontece.
- Biblioteca mantém conteúdo reutilizável e versionado.
- Treinos controla a ocorrência concreta e o respetivo snapshot técnico.
- Cais regista presença, exceções e observações operacionais.
- Monitorização/Live controla cronómetros e performance temporal.

O Cais não contém construtor de treino nem cronómetros.

## Sessão ativa

- apenas uma sessão fica ativa de cada vez;
- as sessões do dia são apresentadas numa faixa horizontal para troca rápida;
- `cancelled` e `completed` não são sessões executáveis no Cais;
- outro dia pode ser escolhido sem manter um menu lateral permanente.

## Layout

O contexto da sessão fica à esquerda e os atletas à direita.

A lista é o modo principal. Em desktop, cada atleta ocupa uma única linha funcional:

`Nome → P/A/D/T → ações rápidas / métricas / + Registo → detalhe`

O nome possui largura suficiente para permanecer numa linha. A pista e grupo aparecem como contexto secundário dentro da mesma célula de identidade. Os botões de presença seguem imediatamente a identidade e as restantes ações alinham à direita.

Existe modo alternativo em cards, mas não é o default.

## Presença por exceção

Os atletas preparados para novas sessões entram como:

- `presente = true`
- `estado = presente`

O treinador regista apenas exceções.

Estados rápidos:

- `presente` — conta como presente;
- `ausente` — não presente;
- `dispensado` — não presente;
- `atrasado` — conta como presente.

A migração apenas normaliza ocorrências futuras comprovadamente ainda não intervencionadas; histórico existente não é reinterpretado.

## Registos rápidos e + Registo

`Comportamento` e `Material` são ações rápidas próprias. Não são aliases de `+ Registo`.

Ambas escrevem na mesma fonte de verdade usada pelo popup completo: `training_metrics`, identificada por sessão, atleta e código técnico da métrica.

Consequência obrigatória:

1. guardar `Comportamento = Atenção` no popup rápido;
2. abrir `+ Registo`;
3. o campo Comportamento já aparece como `Atenção`.

O inverso também é verdadeiro: alterar Comportamento ou Material em `+ Registo` atualiza o valor mostrado na linha e no respetivo popup rápido.

`+ Registo` inclui:

- presença;
- comportamento;
- material;
- métricas configuráveis;
- nota técnica;
- aconselhamento.

## Métricas configuráveis

`sports_cais_metric_definitions` é o catálogo tenant-scoped de métricas do Cais.

Campos principais:

- `codigo` — identidade técnica estável;
- `nome`;
- `input_type`: `text`, `number` ou `choice`;
- `unit`;
- `options_json`;
- `quick_action`;
- `ativo` / `ordem` / `archived_at`.

Defaults:

- `behavior` — Comportamento, choice, quick action;
- `material` — Material, choice, quick action;
- `heart_rate` — Frequência cardíaca, number, bpm;
- `rpe` — RPE, number.

Os códigos já utilizados em `training_metrics.metrica` ficam bloqueados e são arquivados em vez de destruídos.

## Snapshot técnico

O painel esquerdo lê `training_series` da ocorrência concreta e preserva:

- bloco;
- rondas;
- repetições;
- distância unitária;
- zona;
- estilo;
- intervalo;
- saída;
- `timing_mode`.

`8×50` continua a ser representado como oito repetições de 50 m. O Cais nunca reduz esta semântica a um único total de 400 m.

## Ocorrências

Alterações operacionais de pista, grupo, local ou hora continuam a usar `training_schedule_exceptions`, preservando antes/depois, motivo, autor e momento.

## Compatibilidade

- `training_metrics` continua a ser a tabela canónica dos registos de atleta no Cais;
- `training_athlete_cais_metrics` é apenas legado e não entra no novo runtime;
- os componentes legacy `CaisTab` / cards antigos deixam de ser a fonte do `/desportivo/cais` sem necessidade de apagar histórico ou contratos ainda referenciados.

# Desportivo — Registos

## Objetivo

`Registos` é a camada histórica read-only do módulo Desportivo. Não cria uma segunda fonte de verdade e não replica dados para uma tabela de arquivo.

A workspace responde a três perguntas:

- **Por treino** — o que ficou registado numa sessão concreta;
- **Por atleta** — que factos desportivos foram registados ao longo dos treinos do atleta;
- **Por tipo de registo** — consulta transversal de tempos/splits, métricas e registos operacionais.

## Fontes canónicas

### Live

- `sports_live_monitorings`
- `sports_live_measurements`
- `sports_live_measurement_athletes`
- `sports_live_measurement_events`
- `sports_live_free_classifications`
- `sports_live_metric_records`

Medições livres só entram como resultado histórico consolidado depois de classificadas. Registos `voided` de métricas Live não entram nas consultas normais.

### Cais

- `training_athletes`
- `training_metrics`
- `training_schedule_exceptions`

O Cais mantém `training_metrics` como estado operacional final por código/atleta. Registos apresenta esses factos como existem; não inventa uma timeline de alterações que não está persistida. `training_schedule_exceptions` mantém timestamp/auditoria e pode ser apresentado cronologicamente.

## Read model

`SportsRecordsReadModelService` agrega os domínios existentes e aplica tenant scope através de `SportsClubContext`.

Não existem migrations específicas de Registos e não existe uma tabela `sports_records`.

## Rotas

Todas as rotas são GET:

- `/desportivo/registos`
- `/desportivo/registos/export`
- `/desportivo/registos/treinos/{training}`
- `/desportivo/registos/atletas/{athlete}`

Não existem endpoints de create/update/delete.

## Filtros

O read model aceita período, atleta, grupo, estilo, distância, zona, métrica e tipo de medição. O estado dos filtros permanece na query string.

## Paginação

- treinos: 25 por página;
- atletas: 40 por página;
- registos transversais: 50 por página;
- timeline de atleta: 25 treinos por página.

As contagens do índice de treinos são agregadas por página para evitar N+1.

## Exportação

A exportação CSV reutiliza os mesmos filtros do read model e está limitada a 10 000 linhas por pedido. O ficheiro usa UTF-8 com BOM e separador `;` para compatibilidade com Excel em pt-PT.

## Fronteira com Análise

Registos mostra factos históricos. Cálculos de tendência, evolução, comparação e interpretação pertencem ao módulo `Análise`.

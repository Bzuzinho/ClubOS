# Desportivo — Contract ponta a ponta H3

Data: 2026-09-01
Status: H3c — Treino → Cais → Presenças concluído e deployado

## Objetivo

Fechar o módulo Desportivo como um único fluxo operacional coerente, sem regressar a domínios paralelos ou fontes legacy:

`Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → Análise/Reporting`

A H3a estabeleceu o baseline canónico. A H3b provou Planeamento → Treino com snapshot imutável de uma versão de plano. A H3c fixa agora `training_athletes` como a única espinha operacional entre a preparação da sessão, o Cais e o estado de presença.

## Workspaces canónicas

- `desportivo.index` — dashboard operacional
- `desportivo.planeamento` — planeamento
- `desportivo.cais` — operação de cais
- `desportivo.live` — cronometragem/execução live
- `desportivo.competicoes` — competições
- `desportivo.resultados` — resultados
- `desportivo.relatorios` — análise transversal

Todas permanecem autenticadas, verificadas, protegidas por `module.access:desportivo` e pela permission específica da workspace.

## Data spine canónica

Planeamento e treino reutilizável:
- `seasons`, `macrocycles`, `mesocycles`, `microcycles`
- `training_plans`, `training_plan_versions`, `training_plan_series`

Sessão operacional:
- `trainings`
- `training_series`
- `training_athletes`
- `training_metrics`

Competição e resultados:
- `competitions`
- `provas`
- `competition_registrations`
- `results`
- `team_results`

## H3b — contrato Planeamento → Treino

Ao criar uma sessão no Planeamento com `training_plan_version_id`:
1. a cadeia Época → Macro → Meso → Micro é normalizada para a sessão;
2. `trainings.training_plan_version_id` fica preso à versão selecionada;
3. as linhas da versão são copiadas para `training_series` com `source=plan_version` e referência a `training_plan_series_id`;
4. `plan_applied_at` e `plan_applied_by` registam a aplicação;
5. uma nova revisão do plano não altera retroativamente a sessão nem as suas séries;
6. uma atualização para outra versão exige uma operação explícita sobre sessões futuras selecionadas.

## H3c — contrato Treino → Cais → Presenças

1. preparar/selecionar um atleta para uma sessão cria ou reutiliza exatamente um registo em `training_athletes`;
2. o Cais lê esse mesmo registo e expõe o respetivo `training_athlete_id`;
3. alterações de presença no Cais atualizam o mesmo registo, sem criar uma segunda fonte de attendance;
4. `atrasado` continua semanticamente presente (`presente=true`), preservando o estado detalhado em `estado`;
5. métricas logísticas/técnicas continuam em `training_metrics`, ligadas ao mesmo `treino_id` + `user_id`;
6. a tabela legacy `presences` permanece proibida como fonte de verdade ativa.

## Fontes proibidas no negócio Desportivo ativo

Continuam proibidas como fontes de verdade ativas:
- `training_sessions`
- `presences`
- `event_results`
- `event_attendances`

A existência física de tabelas legacy não autoriza novos consumidores. O `LegacySportsGuard` permanece o guard rail.

## Regra para H3+

Cada lote funcional deve:
1. preservar o data spine canónico;
2. não criar dual writes;
3. provar a transição entre a etapa anterior e a seguinte;
4. acrescentar cobertura backend e, quando aplicável, Playwright para desktop/mobile;
5. atualizar este contract e o estado vivo com evidência real da CI/deploy.

## Ordem recomendada

- H3b: Planeamento → sessão de treino — concluído e integrado.
- H3c: Treino → Cais → Presenças — concluído e integrado.
- H3d: Live → métricas/splits — concluído e integrado, com contagens concorrentes, distância unitária e progressão automática das séries validadas.
- H3e: Competições → Resultados — fechar inscrições, provas e resultados canónicos.
- H3f: Portal — projetar agenda, presenças e resultados sem novas fontes de verdade.
- H3g: Análise/reporting + cleanup legacy final.

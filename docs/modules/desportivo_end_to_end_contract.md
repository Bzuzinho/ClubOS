# Desportivo — Contract ponta a ponta H3

Data: 2026-08-31
Status: H3b — Planeamento → sessão de treino em validação

## Objetivo

Fechar o módulo Desportivo como um único fluxo operacional coerente, sem regressar a domínios paralelos ou fontes legacy:

`Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → Análise/Reporting`

A H3a estabeleceu o baseline canónico. A H3b prova o primeiro elo funcional real: uma sessão criada no Planeamento a partir de uma versão de plano materializa um snapshot operacional em `trainings` + `training_series`, e revisões posteriores do plano não reescrevem a sessão já criada.

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

A regressão H3b cobre este comportamento pela entrada canónica `SportsPlanningWorkspaceService::createSession()`.

## Fontes proibidas no negócio Desportivo ativo

Continuam proibidas como fontes de verdade ativas:
- `training_sessions`
- `presences`
- `event_results`
- `event_attendances`

A existência física de tabelas legacy não autoriza novos consumidores. O `LegacySportsGuard` permanece o guard rail.

## Regra para H3b+

Cada lote funcional deve:
1. preservar o data spine canónico;
2. não criar dual writes;
3. provar a transição entre a etapa anterior e a seguinte;
4. acrescentar cobertura backend e, quando aplicável, Playwright para desktop/mobile;
5. atualizar este contract e o estado vivo com evidência real da CI/deploy.

## Ordem recomendada

- H3b: Planeamento → sessão de treino — em validação nesta PR.
- H3c: Treino → Cais → Presenças — consolidar seleção, atletas, métricas e attendance no mesmo `training_athletes`.
- H3d: Live → métricas/splits — validar contagens concorrentes e progressão das séries.
- H3e: Competições → Resultados — fechar inscrições, provas e resultados canónicos.
- H3f: Portal — projetar agenda, presenças e resultados sem novas fontes de verdade.
- H3g: Análise/reporting + cleanup legacy final.

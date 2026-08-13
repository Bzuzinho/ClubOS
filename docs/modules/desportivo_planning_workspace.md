# Desportivo — Planeamento funcional

Data: 2026-08-12
Status: workspace funcional após F0–F7 e Estrutura funcional

## Responsabilidade

Planeamento decide **quando, para quem, onde e com que conteúdo** uma sessão vai acontecer.

A hierarquia operacional é:

`Época → Macrociclo → Mesociclo → Microciclo → Sessão → Séries`

Época é contexto estrutural e é gerida em **Estrutura**. Planeamento não cria, apaga ou reabre épocas.

## Periodização

- Macrociclos, Mesociclos e Microciclos são tenant-scoped por `club_id`.
- Um filho tem de ficar integralmente dentro das datas do respetivo pai.
- Microciclos possuem datas, volume previsto, objetivos e flag explícita de semana de descarga.
- Ciclos ainda não utilizados podem ser removidos; ciclos com histórico são arquivados/inativados.
- Épocas fechadas/arquivadas ficam em modo de consulta até serem reabertas através do lifecycle de Estrutura.

## Sessões

`trainings` continua a ser a única sessão agendada canónica.

Uma sessão de Planeamento pertence obrigatoriamente a um Microciclo. O sistema deriva deste contexto o Mesociclo, Macrociclo e Época correspondentes.

Uma sessão pode combinar:

- plano versionado da Biblioteca;
- instrução global;
- um ou vários grupos de treino;
- plano/instrução específica por grupo;
- atletas individuais adicionais;
- treinador responsável;
- Local → Piscina/Área → Pista;
- estado `draft` ou `published`.

Os atletas de grupos são derivados das memberships válidas na data da sessão. Atletas individuais usam a mesma tabela `training_athletes`; não existe roster paralelo.

Planeamento não contém um segundo construtor de séries. Conteúdo reutilizável continua em `training_plans` + `training_plan_versions`.

## Recorrências

`training_recurrences` define regras diárias/semanais com intervalo, dias, horário, contexto de periodização, local/piscina, grupos, pistas e conteúdo.

A expansão cria sessões reais através de `CreateTrainingAction` e mantém a identidade idempotente `(training_recurrence_id, recurrence_occurrence_key)`.

Editar uma recorrência nunca reescreve sessões já geradas.

## Pistas e cutover

O runtime de Planeamento usa exclusivamente:

`Sports Location → SportsPool/Area → SportsPoolLane`

FK canónico nos pivots:

- `training_session_group_lanes.sports_pool_lane_id`
- `training_recurrence_group_lanes.sports_pool_lane_id`
- `sports_venue_closures.sports_pool_lane_id`

O antigo `sports_venue_lane_id` permanece nullable apenas para histórico/rollback. O backfill é feito exclusivamente através de `sports_pool_lanes.legacy_sports_venue_lane_id`; não existe matching por nome ou número de pista.

O `SportsArchitectureBoundaryGuard` impede que serviços ativos do Desportivo voltem a importar `SportsVenueLane` ou a usar `sports_venue_lane_id`.

## Conflitos

O motor existente `TrainingScheduleConflictService` continua a ser a única fonte de conflito de agenda:

- sobreposição de pista;
- atleta em sessões simultâneas;
- capacidade planeada/física;
- encerramento de local, piscina/área ou pista.

As políticas `allow`, `warn` e `block` permanecem configuráveis. Encerramentos são `decision_required` e não provocam cancelamento automático.

## Objetivos

Objetivos de época/escalão reutilizam `SportsObjectiveService` e `SportsObjectiveVersion`.

Uma revisão cria nova versão; nunca sobrescreve silenciosamente a versão anterior.

## Compatibilidade

- antigos endpoints mutáveis `/desportivo/epocas`, `/desportivo/macrociclos` e `/desportivo/mesociclos` são encerrados pelo middleware F7;
- `/desportivo/planeamento` passa a servir a workspace canónica;
- tabelas/colunas legacy mantidas pela estratégia expand-first não voltam a ser fonte de verdade no runtime.

## Fora de âmbito

- builder final da Biblioteca;
- execução no Cais;
- timers/Monitorização;
- alterações operacionais durante a sessão, que continuam a ser registadas em `training_schedule_exceptions`.

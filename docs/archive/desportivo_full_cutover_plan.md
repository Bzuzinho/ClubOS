# Desportivo Full Cutover Plan

Date: 2026-03-12
Scope: Full cutover to canonical sports data model (no legacy business flow)

## 1. Current Usage Audit

### Active legacy usage found in backend
- `presences` is still actively queried for dashboards, reports, and volume metrics in `app/Http/Controllers/DesportivoController.php`.
- `presences` is still dual-written in `app/Http/Controllers/DesportivoController.php` (`storeTraining`, `updatePresencas`, `markAllPresent`, `clearAllPresences`, `deleteTraining`).
- `event_results` is still active for sports results and athlete operational summaries in `app/Http/Controllers/DesportivoController.php`.
- `event_attendances` is still used as mirrored training attendance through `app/Services/Desportivo/SyncTrainingToEventAction.php` and `app/Observers/TrainingAthleteObserver.php`.
- `training_sessions` is present and referenced by audit/legacy code and new API additions, but business target is to stop using it.
- `users` still carries sports-domain fields (e.g. `escalao`, federation/medical sports profile fields) that should be canonical in `athlete_sports_data`.

### Frontend references requiring cutover
- `resources/js/Pages/Desportivo2/Index.tsx` comments and payload assumptions still mention legacy `presences`/`event_results`.
- Competition and results tabs still have legacy semantics in doc/comments and data mapping from event-layer assumptions (`Desportivo2CompeticoesTab.tsx`, `Desportivo2ResultadosTab.tsx`).
- Cais still consumes `presences` payload shape from web controller flow instead of pure training domain API shape.

### Canonical tables already available
- Identity: `users`
- Sports profile: `athlete_sports_data`
- Category: `age_groups`
- Planning: `seasons`, `macrocycles`, `mesocycles`, `microcycles`
- Training: `trainings`, `training_series`, `training_athletes`
- Competitions: `competitions`, `provas`, `competition_registrations`, `results`

### Required new canonical tables
- `training_metrics` (replace ad hoc cais metric storage naming)
- `team_results`
- `training_age_group` pivot (replace `trainings.escaloes` JSON for business flow)
- optional: `macrocycle_age_group` (replace `macrocycles.escalao` text for business flow)

## 2. Canonical Mapping by Business Concept

- Athlete sports profile:
  - from: `users` sports fields (`escalao`, federation, medical sports fields)
  - to: `athlete_sports_data` + FK `escalao_id -> age_groups.id`
- Training attendance:
  - from: `presences` and event mirrored attendance
  - to: `training_athletes`
- Training metrics:
  - from: `training_athlete_cais_metrics` / ad hoc legacy conventions
  - to: `training_metrics`
- Competition results:
  - from: `event_results`
  - to: `results` (+ `provas`, `competitions`, and `team_results`)
- Training category assignment:
  - from: `trainings.escaloes` JSON
  - to: `training_age_group` pivot

## 3. Exact Files That Must Change

### Backend core
- `app/Http/Controllers/DesportivoController.php`
- `app/Services/Desportivo/CreateTrainingAction.php`
- `app/Services/Desportivo/UpdateTrainingAthleteAction.php`
- `app/Services/Desportivo/SyncTrainingToEventAction.php` (remove business use)
- `app/Observers/TrainingAthleteObserver.php` (remove sync behavior)
- `app/Models/Training.php`
- `app/Models/User.php`
- `app/Models/Competition.php`
- `app/Models/Prova.php` (verify/update)
- `app/Models/Result.php`

### API layer
- `routes/api.php`
- `app/Http/Controllers/Api/AthleteController.php`
- `app/Http/Controllers/Api/TrainingController.php`
- `app/Http/Controllers/Api/TrainingAttendanceController.php`
- `app/Http/Controllers/Api/CompetitionController.php`
- `app/Http/Controllers/Api/CompetitionResultController.php`

### Frontend data layer
- `resources/js/services/sports/*`
- `resources/js/hooks/sports/*`
- `resources/js/Pages/Desportivo2/Index.tsx`
- `resources/js/Components/Desportivo2/components/cais/*`
- `resources/js/Components/Desportivo2/Desportivo2CompeticoesTab.tsx`
- `resources/js/Components/Desportivo2/Desportivo2ResultadosTab.tsx`
- `resources/js/types/sports.ts`

## 4. Data Migrations Required (explicit)

1. Users sports profile -> athlete_sports_data
- Backfill `athlete_sports_data` for all athlete users missing row.
- Map `users.escalao` to `athlete_sports_data.escalao_id` (best match by age_groups name/code).
- Move sports profile fields to `athlete_sports_data` when still populated in users.

2. Presences -> training_athletes
- Upsert by `(treino_id, user_id)`.
- Map status/presence fields to `training_athletes.estado` and `presente`.
- Map `distancia_realizada_m` to `volume_real_m` and notes to `observacoes_tecnicas`.

3. Event results -> competitions/provas/results
- Create canonical competition row per event-based competition when needed.
- Create or reuse `provas` rows based on event_result distance/style metadata.
- Create `results` rows with `tempo_oficial`, `posicao`, and athlete relation.

4. Cais metrics -> training_metrics
- Migrate existing `training_athlete_cais_metrics` into `training_metrics` schema.

5. JSON/text category refs -> relational
- `trainings.escaloes` -> `training_age_group` pivot.
- `macrocycles.escalao` -> relational mapping table if used in business operations.

## 5. Cutover Execution Order

1. Backup + rollback instructions.
2. Create missing canonical tables and pivots.
3. Run data backfill migrations.
4. Refactor backend reads/writes to canonical only.
5. Refactor frontend hooks/services to canonical API only.
6. Remove legacy business flow usage:
   - `presences`
   - `training_sessions`
   - `event_results`
   - mirrored sync to `event_attendances` as training source
7. Validate and produce final report.

## 6. Rollback Strategy (high-level)

- Keep physical legacy tables untouched during cutover migration.
- Record migration markers and per-table row counts before/after.
- If rollback needed:
  - disable canonical routes/services,
  - restore database from backup,
  - redeploy previous app revision.

## 7. Acceptance Criteria

- No controller/service/query in sports business flow reads from `presences`, `training_sessions`, `event_results`.
- No dual-write to legacy tables.
- Cais reads/writes only `training_athletes` + `training_metrics`.
- Competitions tabs read/write only `competitions`/`provas`/`results`/`team_results`.
- Athlete sports profile served from `athlete_sports_data`.
- Build and syntax checks pass.

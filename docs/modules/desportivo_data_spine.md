# Desportivo Data Spine

Date: 2026-08-10
Status: implemented and evolving through the Desportivo refactor

## Purpose

This document defines the canonical backend data spine for the Sports module. The goal is to keep all active sports business flows on relational, indexed, guarded sources and prevent drift back into legacy tables.

## Canonical Sources

### Athlete profile
- `users`: identity and shared member fields
- `athlete_sports_data`: sports profile, federation data, medical data, active sports status and official/calculated age group
- `age_groups`: canonical age-group catalog

### Planning
- `seasons`
- `macrocycles`
- `mesocycles`
- `microcycles`
- `training_groups`: technical training groups independent from official age groups
- `training_group_memberships`: dated athlete membership history

### Training plan domain
- `training_plans`: stable reusable plan/template identity
- `training_plan_versions`: immutable revisions of a reusable plan
- `training_plan_series`: structured series belonging to one plan version

### Training session domain
- `trainings`: scheduled training session; never a reusable template
- `training_age_group`: session-to-age-group compatibility pivot
- `training_series`: operational snapshot of the structure actually applied to the session
- `training_athletes`: canonical attendance and execution record per athlete
- `training_metrics`: per-session or per-athlete execution metrics used by Cais/pool-deck flows

A session may remain fully manual or reference one `training_plan_version`. Applying a version copies its series into `training_series`, preserving a historical snapshot. Revising a reusable plan never mutates completed or already-scheduled sessions implicitly.

### Objectives and indicators
- `sports_objectives`: stable objective identity/scoping
- `sports_objective_versions`: immutable objective revisions
- `athlete_indicator_definitions`: configurable indicator catalog
- `athlete_indicator_records`: historical measurements with semantic snapshots

### Competition domain
- `competitions`: competition master record
- `provas`: race definition inside competition
- `competition_registrations`: athlete registration per race
- `results`: individual competition result
- `team_results`: collective/team classification

## Forbidden Active Tables

The following tables may still exist physically but are forbidden in active sports business logic:

- `training_sessions`
- `presences`
- `event_results`
- `event_attendances`

Enforcement lives in `app/Support/LegacySportsGuard.php`.

## Core Relations

### Athlete spine
- `AthleteSportsData::user()` -> `users.id`
- `AthleteSportsData::escalao()` -> `age_groups.id`
- `User::competitionResults()` -> `results.user_id`

### Training plan spine
- `TrainingPlan::versions()` -> `training_plan_versions.training_plan_id`
- `TrainingPlanVersion::series()` -> `training_plan_series.training_plan_version_id`
- `TrainingPlanVersion::sessions()` -> `trainings.training_plan_version_id`
- `TrainingPlanSeries::sessionSeries()` -> `training_series.training_plan_series_id`

### Training session spine
- `Training::planVersion()` -> `training_plan_versions.id`
- `Training::season()` -> `seasons.id`
- `Training::macrocycle()` -> `macrocycles.id` or compatibility alias
- `Training::mesocycle()` -> `mesocycles.id` or compatibility alias
- `Training::microcycle()` -> `microcycles.id`
- `Training::athleteRecords()` -> `training_athletes.treino_id`
- `Training::ageGroups()` -> `training_age_group`
- `Training::series()` -> `training_series.treino_id`
- `Training::metrics()` -> `training_metrics.treino_id`
- `TrainingAthlete::athlete()` -> `users.id`
- `TrainingAthlete::metrics()` -> `training_metrics.training_athlete_id`
- `TrainingMetric::trainingAthlete()` -> `training_athletes.id`

### Competition spine
- `Competition::provas()` -> `provas.competicao_id`
- `Competition::results()` -> `results` through `provas`
- `Competition::teamResults()` -> `team_results.competicao_id`
- `Prova::competition()` -> `competitions.id`
- `Prova::registrations()` -> `competition_registrations.prova_id`
- `Prova::results()` -> `results.prova_id`
- `CompetitionRegistration::athlete()` -> `users.id`

## Write Services

Sports writes that affect reusable plans/sessions are centralized in:

- `TrainingPlanService`: create/revise/archive reusable plans; revisions append immutable versions.
- `TrainingSessionPlanService`: apply one plan version to a scheduled session and explicitly update selected future sessions.
- `CreateTrainingAction`: canonical session creation; supports either manual series or one plan version snapshot.

## Query Services

Heavy reads are centralized in `app/Services/Desportivo/Queries`:

- `GetTrainingPoolDeckView`: detailed pool-deck view for one training, including athlete records and metrics
- `GetTrainingDashboardSummary`: grouped attendance/volume summary for one training
- `GetCompetitionListSummary`: competition list with race, result, and registration counts
- `GetCompetitionResultsView`: one competition with provas, registrations, results, and team results
- `GetAthletePerformanceHistory`: one athlete performance history from canonical competition results

Each service is expected to remain legacy-free and is covered by the legacy guard test.

## Validation Layer

Dedicated FormRequests exist for canonical write flows. Domain services additionally enforce version, tenant and lifecycle invariants that cannot safely live only in HTTP validation.

## Database Hardening

The original spine hardening migration remains:

- `database/migrations/2026_03_13_120000_harden_sports_data_spine.php`

The Desportivo refactor adds subsequent additive migrations for canonical athlete profile source metadata, training groups/objectives/indicators and versioned training plans. New relevant sports structures carry `club_id`.

## Extension Rules

- New sports business logic must use canonical tables only.
- Do not add dual-write paths to legacy tables.
- Do not create parallel models for the same business concept.
- `trainings` means scheduled session; do not create another active session table.
- Reusable workout content belongs to `training_plans` + immutable versions; do not add a second builder in Planning.
- A plan revision must not silently alter completed or previously scheduled sessions.
- Prefer adding query/domain services over embedding large business logic in controllers.
- Preserve Portuguese physical schema compatibility when needed, but expose canonical relation names in code.
- If a new query touches multiple sports tables, add or update a legacy-guard-backed test.

## Operational Notes

- Frontend mock fallback remains environment-controlled and does not change canonical backend ownership.
- Existing legacy tables are retained for compatibility and historical data, not for active sports flows.
- The approved Desportivo mockup remains the UI contract; data-domain PRs must not redesign it.

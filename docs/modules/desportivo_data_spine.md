# Desportivo Data Spine

Date: 2026-03-13
Status: implemented

## Purpose

This document defines the canonical backend data spine for the Sports module. The goal is to keep all active sports business flows on relational, indexed, guarded sources and prevent drift back into legacy tables.

## Canonical Sources

### Athlete profile
- `users`: identity and shared member fields
- `athlete_sports_data`: sports profile, federation data, medical data, active sports status
- `age_groups`: canonical age-group catalog

### Planning
- `seasons`
- `macrocycles`
- `mesocycles`
- `microcycles`

### Training domain
- `trainings`: training master record
- `training_age_group`: training-to-age-group pivot
- `training_series`: planned training structure
- `training_athletes`: canonical attendance and execution record per athlete
- `training_metrics`: per-training or per-athlete execution metrics used by Cais/pool-deck flows

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

### Training spine
- `Training::season()` -> `seasons.id`
- `Training::macrocycle()` -> `macrocycles.id` or compatibility alias
- `Training::mesocycle()` -> `mesocycles.id` or compatibility alias
- `Training::microcycle()` -> `microcycles.id`
- `Training::athleteRecords()` -> `training_athletes.treino_id`
- `Training::ageGroups()` -> `training_age_group`
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

## Query Services

Heavy reads are centralized in `app/Services/Desportivo/Queries`:

- `GetTrainingPoolDeckView`: detailed pool-deck view for one training, including athlete records and metrics
- `GetTrainingDashboardSummary`: grouped attendance/volume summary for one training
- `GetCompetitionListSummary`: competition list with race, result, and registration counts
- `GetCompetitionResultsView`: one competition with provas, registrations, results, and team results
- `GetAthletePerformanceHistory`: one athlete performance history from canonical competition results

Each service is expected to remain legacy-free and is covered by the legacy guard test.

## Validation Layer

Dedicated FormRequests were introduced for canonical write flows:

- `StoreTrainingRequest`
- `UpdateTrainingRequest`
- `UpdateTrainingAttendanceRequest`
- `StoreTrainingMetricRequest`
- `StoreCompetitionRegistrationRequest`
- `StoreResultRequest`
- `StoreTeamResultRequest`

These requests enforce FK existence, field types, state enums, time formats, and uniqueness rules where appropriate.

## Database Hardening

The hardening migration is:

- `database/migrations/2026_03_13_120000_harden_sports_data_spine.php`

It adds or completes:

- missing FKs on sports spine tables
- uniqueness for canonical pairings such as training-athlete, registration, and athlete sports profile
- coverage indexes for training, competition, registration, result, and metrics queries
- `training_metrics.training_athlete_id`
- `training_metrics.recorded_at`

The migration is written to be defensive across environments and skips unsupported FK operations on SQLite.

## Controller Responsibilities

### Web controller
- `DesportivoController` now reads canonical training, competition, result, and athlete sources for Desportivo views
- Cais metrics are served and persisted through canonical `training_metrics`

### API controllers
- `TrainingController`: canonical CRUD for trainings
- `TrainingAttendanceController`: canonical attendance updates on `training_athletes`
- `AthleteController`: active athlete listing/details from `users` + `athlete_sports_data`
- `CompetitionController`: canonical competition CRUD and detail view
- `CompetitionResultController`: canonical result CRUD on `results`
- `CompetitionRegistrationController`: canonical registration CRUD on `competition_registrations`
- `TeamResultController`: canonical team result CRUD on `team_results`

## Extension Rules

- New sports business logic must use canonical tables only.
- Do not add dual-write paths to legacy tables.
- Do not create parallel models for the same business concept.
- Prefer adding query services over embedding large read queries in controllers.
- Preserve Portuguese physical schema compatibility when needed, but expose canonical relation names in code.
- If a new query touches multiple sports tables, add or update a legacy-guard-backed test.

## Operational Notes

- Frontend mock fallback remains environment-controlled and does not change canonical backend ownership.
- Existing legacy tables are retained for compatibility and historical data, not for active sports flows.
- Validation evidence and command results are recorded in `docs/desportivo_data_spine_validation.md`.
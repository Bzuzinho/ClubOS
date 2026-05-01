# Final Canonical Validation - Sports Module

## Final Classification

VALIDATED

## Scope

This validation confirms the Sports module is now aligned with the canonical architecture using:

- users
- athlete_sports_data
- age_groups
- seasons
- macrocycles
- mesocycles
- microcycles
- trainings
- training_series
- training_athletes
- training_metrics
- competitions
- provas
- competition_registrations
- results
- team_results
- training_age_group

Legacy tables that must not be used by active Sports business logic:

- training_sessions
- presences
- event_results
- event_attendances

## 1) Training no longer depends on Event

Status: Confirmed

Evidence:

- app/Services/Desportivo/CreateTrainingAction.php no longer creates Event records.
- app/Services/Desportivo/CreateTrainingAction.php now creates Training and syncs age groups directly to Training.
- app/Http/Controllers/DesportivoController.php no longer updates or deletes Event as part of training update/delete flows.

Result:

Training is the single source of truth for session lifecycle in Sports flows.

## 2) SyncTrainingToEventAction disabled

Status: Confirmed

Evidence:

- app/Services/Desportivo/SyncTrainingToEventAction.php is marked deprecated.
- The class contains the comment: "Legacy sync disabled after Sports canonical cutover".
- Methods were converted to no-op behavior (log + return), with no writes to event_attendances.
- No references found in controllers, observers, or providers:
  - app/Http/**
  - app/Observers/**
  - app/Providers/**

Result:

Legacy sync no longer participates in active Sports business execution.

## 3) Competitions no longer route through events

Status: Confirmed

Evidence:

- resources/js/Components/Desportivo2/Desportivo2CompeticoesTab.tsx no longer routes to eventos.show.
- resources/js/Components/Desportivo/DesportivoCompeticoes.tsx no longer links to eventos.show or eventos.index for competition views.

Result:

Competition navigation now stays inside Sports routes/views and canonical competition data.

## 4) Frontend mock fallback removal

Status: Confirmed

Evidence:

- resources/js/Components/Desportivo2/Desportivo2ResultadosTab.tsx no longer uses mockTeamResults fallback.
- resources/js/services/sports/athletesService.ts fallback switched from sportsMock to empty array.
- resources/js/services/sports/trainingsService.ts fallback switched from sportsMock to empty array.
- resources/js/services/sports/competitionsService.ts fallback switched from sportsMock to empty array.
- resources/js/services/sports/competitionResultsService.ts fallback switched from sportsMock to empty array.
- resources/js/services/sports/performanceService.ts fallback switched from sportsMock payload to empty canonical payload.
- resources/js/services/sports/sportsApiClient.ts mock mode and fallback-on-error defaults changed to false.
- resources/js/Pages/Desportivo2/Index.tsx no longer defaults seasons/macrocycles from sportsMock.

Result:

Production Sports paths no longer depend on mock data fallback.

## 5) Legacy relations in User model

Status: Confirmed

Evidence:

- app/Models/User.php legacy relations are explicitly marked with comments:
  - eventAttendances()
  - eventResults()
  - resultProvas()
  - presences()
- Canonical relations remain intact:
  - athleteSportsData()
  - trainingAthletes()
  - competitionRegistrations()
  - results()

Result:

Legacy relations are clearly segregated and documented as non-canonical.

## 6) Frontend local storage usage in Performance/Planeamento

Status: Confirmed

Evidence:

- resources/js/Components/Desportivo2/Desportivo2PerformanceTab.tsx uses useKV with backend API persistence; comments updated to reflect backend persistence.
- resources/js/Components/Desportivo2/Desportivo2PlaneamentoTab.tsx explicitly documents backend /api/kv persistence and no browser localStorage.
- No localStorage usage found in Desportivo2 components.

Result:

Sports performance/planning persistence in these tabs is backend-based and not browser localStorage-based.

## 7) Schema consistency and naming

Status: Confirmed (documented consistency layer)

Evidence:

- Migrations preserve existing physical Portuguese naming in several tables (ex: treino_id, competicao_id, epoca_id, data).
- Hardening migration provides compatibility and canonical mapping support where available:
  - database/migrations/2026_03_13_120000_harden_sports_data_spine.php

Mapping note:

- treino_id -> training_id (code-level canonical meaning)
- competicao_id -> competition_id (code-level canonical meaning)
- epoca_id -> season_id (code-level canonical meaning)
- data -> date (code-level canonical meaning)

Result:

Schema remains migration-safe while canonical semantics are maintained at code level.

## 8) Verification checks executed

- Type/compile diagnostics run on updated backend/frontend files: no reported errors in final state.
- Search checks confirmed:
  - no remaining eventos.show references in Sports frontend paths
  - no remaining mockTeamResults references
  - no SyncTrainingToEventAction wiring in app/Http, app/Observers, app/Providers

## Final Decision

VALIDATED

The residual coupling and legacy inconsistencies identified in the previous audit were removed or neutralized without redesigning UI, altering business behavior, or restructuring unrelated modules.

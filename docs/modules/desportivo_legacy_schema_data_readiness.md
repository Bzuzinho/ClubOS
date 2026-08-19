# Desportivo — Legacy Schema/Data Readiness

Date: 2026-08-18
Status: production readiness confirmed; guarded physical cleanup completed for three Desportivo-owned legacy tables

## Goal

Maintain an executable, repeatable inventory around the physical retirement of legacy Sports schema while preserving historical and cross-module ownership boundaries.

Command:

`php artisan desportivo:audit-legacy-schema-data --json=/tmp/desportivo-legacy.json`

Optional CI/operational gate:

`php artisan desportivo:audit-legacy-schema-data --fail-on-unreconciled`

## Production readiness evidence

The production audit collected by CI workflow #574 confirmed the three Desportivo-owned removal candidates as ready for physical retirement:

- `presences`: 0 rows, 0 operational runtime references;
- `training_sessions`: 0 rows, 0 operational runtime references;
- `call_ups`: 0 rows, 0 operational runtime references;
- `candidate_rows_requiring_review`: 0;
- `presence_unreconciled_count`: 0.

The physical cleanup is implemented by `2026_08_17_141500_drop_retired_desportivo_legacy_tables.php` and remains fail-closed: before any schema mutation it verifies that all three targets and the preserved `training_session_attendance` / `training_session_metrics` dependents are empty. If any row is present, the migration aborts without dropping a table.

`training_session_attendance` and `training_session_metrics` are deliberately preserved. Their incoming foreign keys to `training_sessions` are detached only where required by the database engine; the child tables themselves are not removed.

## Decision policy

A table being forbidden in active Sports business logic does **not** mean it is globally removable. Ownership outside Sports must be preserved.

| Table | Sports status | Ownership / action |
|---|---|---|
| `presences` | retired legacy | physically retired after the confirmed zero-row / zero-runtime-reference production gate |
| `training_sessions` | retired legacy parallel session domain | physically retired after the confirmed zero-row / zero-runtime-reference production gate |
| `call_ups` | retired legacy convocation domain | physically retired after the confirmed zero-row / zero-runtime-reference production gate |
| `event_results` | forbidden as Sports source | preserve until Eventos/history ownership is audited |
| `event_attendances` | forbidden as Sports source | owned by Eventos; not removable by this Sports cleanup |
| `teams` | forbidden as Sports source | owned outside the canonical Sports spine; not removable by this Sports cleanup |
| `team_members` | forbidden as Sports source | owned outside the canonical Sports spine; not removable by this Sports cleanup |

The same cleanup does not remove or normalize legacy alias columns such as `competitions.evento_id` or `competition_registrations.fatura_id`.

## Audit output

The report is read-only and includes:

- physical table existence and row count;
- explicit owner/classification;
- whether the table is a Sports removal candidate;
- active runtime references under `app/Http`, `app/Services` and `app/Actions`;
- reconciliation counts from legacy `presences` to canonical `training_athletes` using exact `(treino_id, user_id)` keys only when the legacy table still exists;
- presence of known legacy alias columns and mismatch counts where direct equivalence is meaningful;
- `removal_ready`, for an authorized candidate that still exists, is empty and has zero runtime references;
- `removal_complete`, for an authorized candidate that is physically absent and has zero runtime references;
- `retirement_state`, with one of `removed`, `ready`, `blocked` or `not_applicable`.

The summary separates the lifecycle explicitly:

- `removal_candidate_count`: authorized Sports-owned candidates tracked by policy;
- `removal_complete_count`: candidates already physically absent with zero runtime references;
- `removal_ready_count`: candidates still present but safe to retire;
- `removal_blocked_count`: candidates that still fail a safety precondition.

This preserves the existing `sports-legacy-schema-data-readiness-v1` JSON version while extending it backwards-compatibly. Post-cleanup production audits should therefore report the three retired Sports candidates as `removed`, rather than incorrectly making the zero `removal_ready_count` look like a readiness regression.

## Safety rule

A candidate is only complete when the physical table is absent **and** operational runtime references remain zero. A still-present candidate may not be removed while it has rows, unresolved exact-key reconciliation, or active runtime references. Cross-module tables require an owner-specific audit and remain outside this operation even when Sports itself no longer reads them.

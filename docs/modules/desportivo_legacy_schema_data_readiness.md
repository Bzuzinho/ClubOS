# Desportivo — Legacy Schema/Data Readiness

Date: 2026-08-17
Status: production readiness confirmed; guarded physical cleanup authorized for three Desportivo-owned legacy tables

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
| `presences` | retired legacy | physically removable after the confirmed zero-row / zero-runtime-reference production gate |
| `training_sessions` | retired legacy parallel session domain | physically removable after the confirmed zero-row / zero-runtime-reference production gate |
| `call_ups` | retired legacy convocation domain | physically removable after the confirmed zero-row / zero-runtime-reference production gate |
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
- reconciliation counts from legacy `presences` to canonical `training_athletes` using exact `(treino_id, user_id)` keys only;
- presence of known legacy alias columns and mismatch counts where direct equivalence is meaningful;
- a `removal_ready` flag which is intentionally conservative.

After a candidate is physically absent, `removal_ready` is no longer the operative state for that table: the schema itself is already retired. The audit remains useful for the non-removal candidates and alias inventory.

## Safety rule

No destructive migration may proceed while any authorized target has rows, unresolved exact-key reconciliation, or active runtime references. The physical cleanup also validates preserved child-table rows before touching foreign keys. Cross-module tables require an owner-specific audit and remain outside this operation even when Sports itself no longer reads them.

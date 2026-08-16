# Desportivo — Legacy Schema/Data Readiness

Date: 2026-08-15
Status: read-only audit; no destructive migration authorized or included

## Goal

Establish an executable, repeatable inventory before any future physical removal of legacy Sports schema or historical data.

Command:

`php artisan desportivo:audit-legacy-schema-data --json=/tmp/desportivo-legacy.json`

Optional CI/operational gate:

`php artisan desportivo:audit-legacy-schema-data --fail-on-unreconciled`

## Decision policy

A table being forbidden in active Sports business logic does **not** mean it is globally removable. Ownership outside Sports must be preserved.

| Table | Sports status | Ownership / action |
|---|---|---|
| `presences` | legacy | candidate only after every training-linked row is reconciled/classified against `training_athletes` |
| `training_sessions` | legacy parallel session domain | candidate only when rows and runtime references are zero or explicitly migrated/classified |
| `call_ups` | legacy convocation domain | candidate only after historical rows are classified |
| `event_results` | forbidden as Sports source | preserve until Eventos/history ownership is audited |
| `event_attendances` | forbidden as Sports source | owned by Eventos; not removable by a Sports cleanup |
| `teams` | forbidden as Sports source | potentially owned by Equipas/Formação; not removable by a Sports cleanup |
| `team_members` | forbidden as Sports source | potentially owned by Equipas/Formação; not removable by a Sports cleanup |

## Audit output

The report is read-only and includes:

- physical table existence and row count;
- explicit owner/classification;
- whether the table is a Sports removal candidate;
- active runtime references under `app/Http`, `app/Services` and `app/Actions`;
- reconciliation counts from legacy `presences` to canonical `training_athletes` using exact `(treino_id, user_id)` keys only;
- presence of known legacy alias columns and mismatch counts where direct equivalence is meaningful;
- a `removal_ready` flag which is intentionally conservative.

## Safety rule

No destructive migration should be authored while any removal candidate has unclassified rows, unresolved exact-key reconciliation, or active runtime references. Cross-module tables require an owner-specific audit even when Sports itself no longer reads them.

# Desportivo — F7 Foundation Cutover, Migration Audit and Final Guard Rails

Date: 2026-08-12
Status: implementation foundation

## Objective

F7 closes the foundation sequence F0–F7 without destroying historical data. The phase cuts legacy write paths, makes the canonical cross-module contracts authoritative at runtime, classifies legacy rows conservatively, audits physical aliases and adds executable final guard rails.

A physical legacy table/column may remain after F7 when historical retention or a later contract/drop migration is safer. Its mere existence is not an ownership violation. It must be read-only or explicitly classified, and any duplicated physical relation must be equivalence-audited before removal.

## Legacy web cutover

The old CRUD prefixes are intercepted globally by `EnforceSportsLegacyCutover`:

- `/equipas` → `/desportivo/estrutura`
- `/membros-equipa` → `/desportivo/estrutura`
- `/sessoes-formacao` → `/desportivo/treinos`
- `/convocatorias` → `/desportivo/competicoes`

GET/HEAD requests are redirected so old bookmarks remain useful. Mutating requests return HTTP 410 and cannot write `teams`, `team_members`, `training_sessions` or `call_ups` through those legacy controllers.

The old routes/controllers may remain physically present while the migration ledger is reviewed; they are no longer an active write surface.

## Conservative migration ledger

`sports_legacy_cutover_ledger` records the F7 classification of legacy sports rows. Every source row is keyed by club + source type + source id.

Current source types:

- `team`
- `team_member`
- `training_session`
- `call_up`
- `communication_segment_team`

Automatic classification is deliberately narrow:

- same stable identifier may establish equivalence where the canonical entity exists in the active club;
- a team member is accepted only when an exact canonical group/user/date membership is unique;
- a call-up is accepted only by same identifier or one exact event + athlete-set match;
- communication segments are canonical only when they explicitly carry a valid `training_group_id`.

Names are never fuzzy-matched. A legacy `team_id`, similar group name, similar training date or partial athlete overlap is not enough to migrate automatically. Ambiguity becomes `manual_review` with a concrete reason.

Rows manually classified as `resolved` or `ignored` are preserved by later audit refreshes.

## Canonical communication audiences

`SportsAudienceProvider` is the neutral read contract consumed by Comunicação for sports audiences.

Canonical audience sources:

- athletes → current `sports_athlete_participations`;
- coaches → dated `training_group_coaches`;
- training group members → dated `training_group_memberships`;
- official age groups → `sports_athlete_season_profiles` in the requested/current season context.

The communication engine no longer uses `TeamMember`, `users.age_group_id` or `users.escalao` as sports audience sources. A legacy segment that contains only `team_id` is not guessed; it resolves to no recipients and is recorded for manual migration to `training_group_id`.

Non-sports Membros roles, such as guardian targeting, remain owned by Membros and may continue to use the existing member-role representation until the Membros domain changes it.

`EventAttendance` remains valid for general Eventos participants. It is not a training-attendance source; active training attendance remains exclusively `training_athletes`.

## Competition → Event final runtime cut

`competition_event_projections.event_id` is the authoritative runtime relation.

After F7:

- new/updated projections do not mirror the event id into `competitions.evento_id`;
- the projection service does not resolve the relationship from `competitions.evento_id`;
- a missing canonical projected Event is classified for review rather than reconstructed from the legacy pointer;
- historical `competitions.evento_id` values remain physically untouched and are equivalence-audited.

Existing presentation code may still expose a legacy alias while the Competições functional UI is redesigned. Such access is classified as read-only compatibility and is not allowed to create, delete or demote Competition masters.

A narrowly scoped model adapter remains for historical importers/fixtures that explicitly create a Competition with a legacy Event id: it immediately classifies the safe 1:1 relation into `competition_event_projections` (and, where possible, the F5 finance policy). This is not used by application lifecycle services and does not restore Event-owned Competition creation.

## Competition finance final runtime cut

`competition_financial_obligations.invoice_id` is the authoritative runtime relation between a competition athlete obligation and Financeiro.

After F7:

- `CompetitionFinanceRequest` has no Event-fee/cost-center/title fallback and no legacy invoice ids;
- Financeiro guarantees a canonical competition finance policy before synchronization;
- invoice origin metadata remains `competition_registration` for reporting compatibility, while the canonical obligation id is preserved in the calculation snapshot and ownership lives in `competition_financial_obligations.invoice_id`;
- Financeiro stops writing `competition_registrations.fatura_id`;
- Desportivo stops loading or returning the legacy `fatura` relation as its finance source;
- closed financial/fiscal lifecycles remain protected by `InvoiceFinancialGuardService`.

Historical registration invoice pointers stay in the database and are equivalence-audited against the canonical obligation. They are not dropped in F7.

## Physical FK aliases

F7 fixes the runtime convention without destructive database contraction:

- `training_athletes.treino_id` is the canonical TrainingAthlete → Training FK;
- `trainings.macrocycle_id` is the canonical Training → Macrocycle FK;
- `trainings.mesociclo_id` is the canonical Training → Mesocycle FK.

If legacy aliases (`training_id`, `macrociclo_id`, `mesocycle_id`) also exist physically, the final auditor counts non-null values and mismatches. A mismatching alias blocks the green foundation result. Equivalent historical values may remain until a later contract/drop migration.

## Removed disabled bridge

`SyncTrainingToEventAction` is removed in F7. Training attendance is never mirrored into `event_attendances`.

## Classified read-only presentation compatibility

The final audit distinguishes an unsafe runtime source from an explicitly classified read-only presentation adapter. Existing `DesportivoPagePayloadBuilder` compatibility reads are reported separately where present, including legacy athlete-type filtering and legacy competition/event presentation aliases.

They do not authorize any write, do not establish ownership, and are not a prerequisite for physical legacy removal. They are expected to disappear progressively when the corresponding functional UI modules are rebuilt after the foundation.

## Executable final audit

Run:

```bash
php artisan desportivo:audit-foundation-cutover
```

For CI-style failure on blockers:

```bash
php artisan desportivo:audit-foundation-cutover --fail-on-blockers
```

Optional JSON evidence:

```bash
php artisan desportivo:audit-foundation-cutover --json=storage/app/audits/desportivo-f7.json
```

The report includes:

- architecture boundary violations;
- unsafe runtime-source violations;
- legacy write-route protection;
- migration ledger totals/statuses;
- Competition → Event projection statuses;
- finance obligations in manual review;
- physical alias equivalence/mismatches;
- explicitly classified read-only compatibility adapters.

`foundation_green=true` requires zero:

- architecture boundary violations;
- unsafe runtime-source violations;
- active legacy write endpoints;
- physical alias mismatches;
- unclassified legacy rows.

`manual_review` rows are not unclassified. They are a deliberate, auditable outcome when automatic migration cannot be proven safe.

## What F7 does not do

F7 does not:

- drop legacy historical tables or columns;
- fuzzy-match legacy teams/groups/convocations;
- rewrite financial/fiscal history;
- redesign the final Competições, Convocatórias or Treinos interfaces;
- implement post-foundation Biblioteca/Cais/Monitorização functionality.

Physical deletion belongs to a later contract/drop migration after production evidence confirms zero required legacy reads/writes and validated equivalence.

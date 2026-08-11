# Desportivo — F3 Membros ↔ Desportivo Cutover

Date: 2026-08-11
Status: implementation foundation

## Objective

F3 removes the circular ownership between Membros and Desportivo and makes the Sports module the canonical owner of sporting participation while keeping Membros/Pessoas as the single owner of person identity.

This phase is expand-first. It does not delete `athlete_sports_data`, `users.ativo_desportivo` or `users.escalao`; those remain compatibility projections until the final foundation migration/audit phase.

## Ownership after F3

Membros/Pessoas owns:
- person/user identity;
- birth date and sex;
- contacts and address;
- family relations;
- personal/medical document files;
- RGPD and consents;
- platform access and member type.

Desportivo owns:
- sports participation/activity by club + modality;
- participation history;
- season-scoped calculated and official age group;
- age-group override history;
- group memberships;
- federation identity and athlete federation affiliations;
- operational sports limitations and fitness instructions.

The Membros UI may display and edit those sporting concepts, but it does so through the explicit Desportivo member-profile contract. It no longer owns or recalculates them.

## Cross-domain identity contract

`App\Contracts\Members\MemberSportsIdentityProvider` is the only input Desportivo needs from the member domain for this phase. It exposes only:
- `user_id`;
- birth date;
- sex;
- whether the person currently has Athlete type;
- member state.

Desportivo services must not import `MemberDataReadService` or `MemberTypeResolver`. `SportsArchitectureBoundaryGuard` has no F3 allowlist after this cutover.

## Canonical tables

### `sports_athlete_participations`

Dated participation of one person in one modality. A person can be active in multiple modalities simultaneously.

`current_slot = current` is populated only for the open/current period. A database unique constraint across club + athlete + modality + slot guarantees that two concurrent current periods cannot coexist. Historical rows clear `current_slot` rather than being deleted.

### `sports_athlete_season_profiles`

Materialized season context for an athlete/modality:
- calculated age group;
- official age group;
- placement source (`rule` or `override`);
- source rule/override ids;
- reference date;
- evaluation timestamp/actor.

This is a derived/auditable snapshot. The authority for calculation remains `SeasonAgeGroupRule`; manual official changes remain `AthleteAgeGroupOverride`.

### `sports_federations`

Canonical federation identity. A federation is not a field on a member.

### `sports_athlete_federation_affiliations`

Dated athlete affiliation linked to modality/participation and federation. Legacy federation numbers with no unambiguous federation/modality are preserved for review instead of guessed.

### `sports_athlete_limitations`

Operational restriction/fitness instructions only. It snapshots the relevant training/competition behavior from `SportsLimitationType` and can be modality-specific or global to the athlete.

Clinical diagnosis and medical documents are not copied here.

## Age-group cutover

F3 removes the current-age calculation from member provisioning.

For each applicable athlete + modality + season:
1. resolve the deterministic F2 season rule using birth date/sex;
2. keep that result as `calculated_age_group_id`;
3. if an active `AthleteAgeGroupOverride` exists, it becomes the official group;
4. preserve the calculated group for comparison;
5. persist source, rule/override and reference date in the season profile.

A manual override cannot be created from the Membros surface without a reason and the structural Sports permission required by F2.

## Member UI contract

Routes:
- `GET /desportivo/contratos/membros/{member}/perfil`
- `PUT /desportivo/contratos/membros/{member}/perfil`

The Sports tab in Membros reads this contract and displays:
- participation per modality;
- current/relevant seasons;
- calculated vs official age group;
- rule vs override origin;
- dated group memberships and primary/complementary status;
- federation affiliations;
- operational limitations.

The old `Inf. Médicas` editor is no longer mounted inside the Sports tab. Legacy clinical JSON remains stored but is not exposed as a coach-facing operational record and is never automatically converted into a limitation.

## Compatibility projection

While legacy readers still exist, F3 projects a safe subset back into the old structures:
- any current canonical participation → `ativo_desportivo = true`;
- a representative current/latest official age group → legacy `escalao` and `athlete_sports_data.escalao_id`;
- calculated/override flags are projected for old readers;
- PMB and legacy registration date remain supported.

This projection is not canonical and must not be used for new Sports logic.

Legacy federation/clinical fields are preserved, not overwritten by guesses.

## Conservative migration

`athlete_sports_data` has no modality. Therefore an active legacy profile is automatically backfilled into a canonical participation only when exactly one active modality exists for the club. If several modalities exist, F3 leaves the row untouched for audit/manual classification.

No historical participation, age-group override, group membership, federation or clinical meaning is invented.

## F3 guard rails

Acceptance criteria:
- one person identity only;
- multiple simultaneous active modalities supported;
- at most one current participation per athlete + modality enforced at DB level;
- no current-age age-group calculation in member provisioning;
- season rule is the calculated source;
- manual override retains calculated comparison and history;
- removing Athlete type closes participation instead of deleting history;
- Finance can still read Sports activity through the transitional resolver, without writing Sports data;
- Membros UI writes Sports data only through the Desportivo contract;
- clinical legacy data is not presented as an operational limitation;
- direct Desportivo imports of concrete Members read/type services fail the architecture guard.

## Out of scope

F3 does not implement:
- Competition → Event projection (F4);
- Financeiro idempotent obligation contract (F5);
- Comunicação/Logística contracts (F6);
- physical removal of legacy sports/member columns and tables (F7);
- functional redesign of Treinos, Cais, Monitorização or Competições.

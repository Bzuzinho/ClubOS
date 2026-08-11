# Desportivo — Foundation Architecture Contract (F0)

Date: 2026-08-11
Status: approved functional contract; implementation foundation for F1-F7

## Purpose

This document is the normative foundation for the ClubOS Sports module refactor. It freezes ownership, canonical data rules, cross-module boundaries, history/audit rules, tenant strategy, idempotency and the implementation sequence before further Sports UI or functional modules are built.

The approved Sports navigation remains:

Estrutura → Planeamento → Biblioteca → Treinos → Cais → Monitorização → Registos → Avaliações → Competições → Convocatórias → Resultados → Análise

Configuração Desportiva is transversal and must live inside Desportivo, accessed discretely from the module header rather than as an operational tab.

The approved visual rules for Estrutura Desportiva and Planeamento Desportivo are preserved. Structural changes may add fields/relations, but must not redesign the approved visual language.

## 1. Club and tenant strategy

- The data model must be prepared for multiple clubs.
- Operationally, the first implementation supports one active club per installation.
- New canonical Sports structures must carry a coherent `club_id` when they are club-owned.
- A future user may belong to more than one club, but that operational workflow is not implemented in the foundation phase.
- `SportsClubContext` is a transitional active-club resolver, not proof that the application is already fully multi-tenant.

## 2. Modalities

`modality` must become a canonical entity rather than a free string.

A person may be an active athlete in multiple modalities simultaneously. Sports activity state, official age group and training groups are modality-scoped. Coaches may also be assigned to multiple modalities.

Federation affiliation must support both shared and modality/federation-specific identifiers. Federation identity and athlete federation affiliation must therefore be modelled separately from generic member identity.

## 3. Seasons and programmes

- Multiple active seasons may coexist across modalities.
- A modality may support multiple programmes within the same broad season without forcing this complexity on clubs that do not need it.
- Programmes are stable, permanent configurable identities inside a club + modality; they are not recreated every season.
- New programmes may be created and existing programmes may be deactivated/archived; historically referenced programmes are never hard-deleted.
- A season activates/configures the relevant permanent programmes through a temporal relation instead of cloning programme identity.
- Groups may be associated with a programme in the applicable season configuration; athlete programme context is primarily derived from dated group membership so duplicated programme memberships are avoided where derivation is safe.
- `Master` is an official competition age group, not a separate programme. A Master athlete may compete regularly, occasionally or have no competition objective; competitive intention is athlete/context-specific and is not inferred from the age group or by creating a `Masters` programme.
- Seasons require start and end dates.
- Closed seasons may only be reopened by Technical Director/Admin with mandatory reason and audit trail.
- Season scope must be relational; do not store operational age groups, target competitions or pools as opaque JSON lists when canonical relations exist.

## 4. Age groups

Official age group is determined by the applicable season rule, not by the athlete's current age on the day of access.

Rules must support, as applicable:
- birth year;
- age;
- sex;
- modality;
- season/programme scope.

Manual override remains supported, but requires reason, actor, timestamp and history. The calculated/proposed group must remain available for comparison.

Season transition should propose age-group changes for technical validation instead of silently rewriting official age groups.

Official age group and training group are independent concepts. An athlete may train in one group and compete under another official age-group classification.

## 5. Training groups and teams

- Training group identity is relatively stable across seasons.
- Season/programme participation/configuration is temporal.
- An athlete may have one primary group plus multiple complementary groups in the same modality/season context.
- Primary-group exclusivity is scoped so the athlete can have a different primary group in another modality.
- Groups may contain multiple official age groups.
- Memberships are dated and historical; changing group never destroys the previous assignment.
- Membership history supports start, end, entry/exit reason and notes.
- Critical overlap invariants must be transaction/database protected, not only UI validated.

## 6. Coaches and people

Desportivo never creates a second person identity.

Every athlete, coach, doctor/physio or other sports participant is backed by the canonical person/member identity owned by Membros/Pessoas, even when that person is not a club member or athlete.

Coach-to-group assignments are dated and historical. Coaching roles are configurable (e.g. Technical Director, Head Coach, Assistant Coach, Physical Preparation role).

Base roles for Sports authorization:
- Administrador;
- Diretor Técnico;
- Treinador Principal;
- Treinador/Adjunto;
- Médico/Fisio;
- Atleta;
- Encarregado de Educação.

Structural creation is Admin/Technical Director by default. Coaches use assigned structure unless an explicit permission grants more.

## 7. Locations, pools and lanes

Canonical hierarchy:

Sports Location → Pool/Area → Lane

- One location may contain several pools.
- Open-water locations may exist without lanes.
- Pool metadata may include length/course, indoor/outdoor, lane count and optional operational capacity.
- Lane capacity is an operational reference and may drive warn/block policy; it is not inherently a hard rule.
- Temporary pool/lane closures and unavailability are preserved as a valid concept from the current scheduling implementation.

The existing `sports_venues` / `sports_venue_lanes` implementation is a migration source, not the final naming/shape guarantee.

## 8. Ownership: Membros ↔ Desportivo

Membros/Pessoas owns:
- identity;
- birth date and sex;
- contacts and address;
- family relations;
- personal documents;
- RGPD/consents;
- platform access.

Desportivo owns:
- sports activity status;
- modalities;
- federation affiliations and identifiers;
- official/calculated age group;
- training group memberships;
- coach/group relationships;
- sports limitations/operational fitness;
- technical profile/history.

The Membros UI may display/edit sports data, but must do so through a Desportivo contract/service. It must not own a second copy.

Medical document files/consents remain in Membros/document management. Desportivo owns the operational eligibility/validity state used to decide whether the athlete may train or compete.

Removing Athlete type never deletes sports history; it closes/deactivates current participation.

## 9. Medical privacy and operational limitations

Clinical information and operational sports restrictions are separate domains.

Clinical access: Admin, Technical Director, Doctor/Physio and explicitly granted administrative permissions.

Coaches see operational restrictions required to run training safely, not diagnosis by default. Restrictions may include date ranges and examples such as reduced volume, no block start, avoid a stroke or other operational instruction.

The old InjuryReason catalogue must not automatically become a public coach-facing diagnosis catalogue. F1 replaces/repositions it around operational limitations while preserving required historical compatibility.

## 10. Sports configuration

Sports configuration moves out of the global Configurações module and into Desportivo.

`AgeGroup` is structural and therefore belongs to Estrutura Desportiva, not Configuração Desportiva.

Configuration catalogues include, after validation/migration:
- athlete/attendance statuses;
- training types;
- training zones/intensities;
- absence reasons;
- pool types;
- race/event technical types;
- sports limitation types;
- later: strokes, technical materials, series/block types, competition types, assessment types/protocols and other nomenclature as each functional module is designed.

Configuration behaviour must be data-driven. Do not infer semantics solely from hard-coded codes such as `presente`, `limitado`, `velocidade` or numeric threshold assumptions.

Technical identifiers become immutable once referenced historically. Unused definitions may be deleted; used definitions are archived/deactivated, never hard-deleted.

## 11. Ownership: Eventos ↔ Desportivo

Competition is a Sports master record.

Canonical direction:

Competition (Desportivo master) → Event projection (Eventos)

Never the inverse.

- Publishing the same competition repeatedly must reuse the same Event projection.
- Deleting/changing the Event projection must never delete or demote the Sports Competition master.
- Desportivo owns technical competition identity, technical dates/pool/programme and sports state.
- Eventos owns transport, food, logistics, meeting/concentration data, accommodation and public/logistical presentation.
- Logistical changes may be surfaced in Desportivo but do not rewrite technical master data.
- Competition projects to Eventos obligatorily when published; training projects only when useful for calendar/portal; assessments may project optionally.
- Training attendance belongs exclusively to Desportivo. `event_attendances` is forbidden for active training attendance flows.

Current `EventLifecycleService` Competition creation/deletion and `Event::syncAttendances()` are explicit technical debt scheduled for F4.

## 12. Ownership: Financeiro ↔ Desportivo

Financeiro may read canonical Sports eligibility/status through an explicit query/resolver contract. It must never write the sports profile.

Competition finance separates two concepts:
- club cost: financial expense/movement;
- athlete charge: receivable/invoice, only when explicitly configured.

Default assumption: the club pays competition registration.

If the athlete is charged:
- charging policy is competition-level and configurable;
- policies may support fixed, per-race, mixed, manual or age-group-based calculation;
- output is one aggregated obligation/invoice per athlete + competition;
- relay costs may be statistically allocated but never create automatic individual debt;
- the obligation is created at final/confirmed sports entry, not at call-up;
- withdrawal before financial lifecycle closure may request recalculation/cancellation through Financeiro.

Absolute boundary: Desportivo must not directly call `Invoice::create()`, `Movement::create()`, `FinancialEntry::create()` or equivalent financial persistence. It requests an idempotent Financeiro operation instead.

Current `CreateCompetitionRegistrationAction` direct Invoice/InvoiceItem write is explicit debt scheduled for F5.

## 13. Communication

All Sports email, push and in-app alerts pass through Comunicação and its configuration.

A call-up may be prepared without sending anything. Communication occurs on explicit publication. Re-publication/version changes are idempotent and must not duplicate the original delivery.

Sports emits/requests domain events or communication intents; Comunicação decides channels, templates, preferences and delivery.

The existing CommunicationAutomation/Campaign pattern is the preferred integration direction.

## 14. Inventory and technical equipment

Three canonical concepts are separate:

1. Technical training material catalogue (Desportivo/Biblioteca), e.g. prancha, pull buoy, palas, barbatanas, snorkel.
2. Athlete-owned equipment profile (Desportivo), recording whether an athlete has/uses a personal technical material and optional operational attributes such as size/model/notes where useful.
3. Club-owned equipment (Inventário/Logística), covering physical assets/stock owned or managed by the club, including equipment loaned or assigned to athletes.

Rules:
- technical material required by a workout never depends on club stock;
- an athlete may use personal material even when club inventory is zero;
- a future workout/Cais flow may warn about missing athlete material or insufficient club stock, but it is not a structural block by default;
- when club-owned material is issued/loaned to an athlete, stock/ownership lifecycle remains in Inventário/Logística;
- technical material may optionally map to an Inventory/Logistics product or asset, but neither becomes the other's canonical source;
- historical sports records preserve the material meaning even after the catalogue item is archived.

The concrete athlete-equipment UI and persistence are deferred to the Biblioteca/Logística functional design, but this ownership boundary is fixed in F0.

## 15. Unified performance concept

Sports must converge on a transverse performance record so that the same sporting measurement can be compared across training, competition and assessment while preserving context.

Target concept:
- athlete;
- modality/discipline/stroke/distance as applicable;
- canonical value (time internally in milliseconds);
- date/time;
- source type (`training`, `competition`, `assessment`, `manual`);
- source id;
- status (`provisional`, `official`, `corrected`, etc.);
- structured child splits rather than opaque JSON for canonical performance splits.

Competition classification, points, disqualification and entry context remain competition-specific context around the performance.

Manual corrections never silently overwrite the original value. Official competition corrections require higher permission than ordinary training-performance corrections.

PB/club record/official ranking only use eligible performance contexts/statuses; analytics may compare official and non-official performances.

## 16. Idempotency

Mandatory principles:
- external imports use `source + external_id` or an equivalent deterministic identity;
- re-import reconciles/updates instead of duplicating;
- repeated Competition→Event publication reuses the projection;
- recurring training generation reuses occurrence identity and never duplicates generated sessions;
- call-up publication/version delivery is idempotent;
- race entry is unique for the relevant athlete/race context;
- competition financial request is idempotent per athlete/competition/policy lifecycle;
- mobile/offline retries use `client_event_id` or equivalent for operations where offline retry is allowed.

## 17. Concurrency

Critical structural invariants are protected in transactions and, where possible, database constraints/locks.

Structural conflicts reject the second conflicting operation rather than silently using last-write-wins.

LWW is allowed only for deliberately selected simple operational fields and must not be generalized to structure, finance, entries, official results or historical relationships.

## 18. History, archive and audit

Historical Sports data is preserved.

Used groups, seasons, age groups, configuration definitions and competitions are archived/cancelled rather than physically deleted.

Athletes leaving the club retain sporting history. Legal anonymization/erasure processes are separate from normal Sports deletion.

Important structural changes record actor, timestamp, previous/new state and mandatory reason where required. Mandatory-reason examples include official age-group override, primary-group change where policy requires it, operational fitness override, official result correction and season reopen.

A consolidated athlete audit/history UI is planned but not required in the first foundation implementation.

## 19. Physical database naming

Every relation has one canonical physical FK.

Do not keep competing aliases such as `treino_id` + `training_id`, `competicao_id` + `competition_id` for the same relation.

The refactor does not mass-translate the physical database solely for language consistency. Existing Portuguese physical names may remain where safe and dominant; code may expose canonical English relation names. Duplicate physical aliases are removed progressively after data validation.

## 20. Migration/cutover policy

- No structural phase may destroy historical data.
- Every cutover produces an auditable report: before → migrated → inconsistent/manual-review → after.
- Unresolvable inconsistencies go to a manual review queue/report rather than being guessed.
- Legacy is physically removed only after zero active reads, zero active writes and validated data equivalence.
- Migrations should be additive/expand-first, then read cutover, write cutover, validation, and only later contract/drop.

## 21. Authorization

- Admin/Technical Director manage Sports structure and configuration by default.
- Coaches do not create structural entities by default.
- Official age-group override: Admin/Technical Director.
- Training performance correction: authorized coach or higher.
- Official competition-result correction: elevated permission.
- Medical/clinical access follows the privacy boundary in section 9.

## 22. Architecture sequence

Implementation order is frozen as:

- F0 — Contract + architecture guard rails
- F1 — Configuração Desportiva
- F2 — Canonical Estrutura Desportiva
- F3 — Membros ↔ Desportivo cutover
- F4 — Eventos projection contract
- F5 — Financeiro integration contract
- F6 — Comunicação / Logística contracts
- F7 — migration, audit and final foundation guard rails

One pull request per phase, validated before merge.

Only after the foundation is green do functional modules continue in this order:

Estrutura → Planeamento → Biblioteca/Construtor → Treinos → Cais → Monitorização → Registos → Avaliações → Competições → Convocatórias → Resultados → Análise

For each non-foundation mini-module: market benchmark → functional analysis → ClubOS proposal → mockup/UX validation → implementation.

## 23. Current implementation reuse

Keep/adapt:
- canonical athlete profile direction;
- dated training-group membership history;
- group coach history;
- objectives/indicator history model (reposition later as required);
- training plan immutable versions and session snapshots;
- recurrence idempotency patterns;
- venue closure and scheduling conflict concepts;
- CommunicationAutomation/Campaign integration pattern.

Known debt to remove in later foundation phases:
- partial tenant model;
- free-string modality;
- current-age age-group calculation;
- global/duplicated Sports configuration ownership;
- direct Membros↔Desportivo circular service dependency;
- Event-owned Competition lifecycle;
- active training `event_attendances` synchronization;
- direct Desportivo financial model writes;
- duplicate physical FK aliases;
- fragmented training vs competition performance representations.

## 24. PR #138

PR #138 is not part of the foundation and must not be merged as-is. It combines responsibilities that are now explicitly separated between Cais and Monitorização/Live Training. Later phases may selectively reuse isolated timer, split, offline or metric ideas after those modules are functionally re-designed.

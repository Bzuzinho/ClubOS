# Desportivo — Configuração Desportiva (F1)

Date: 2026-08-11
Status: F1 implementation contract

## Ownership

Configuração Desportiva belongs to Desportivo. The old global Configurações screen is no longer an authoritative write surface and only points users to the Sports-owned workspace.

Canonical UI route: `/desportivo/configuracao`.

Age groups are intentionally excluded from this workspace because they are structural and will be handled by F2 Estrutura Desportiva.

## Active catalogues

- athlete/attendance statuses;
- training types;
- training intensity zones;
- absence reasons;
- pool/water-environment types;
- race technical types currently stored in `prova_tipos`;
- operational limitation types.

`injury_reason_configs` is retained as read-only legacy clinical data. It is not the active coach-facing limitation catalogue.

## Behaviour is data-driven

F1 removes semantic dependence on magic codes where the configuration already claims to be editable.

Examples:

- athlete status carries `counts_as_present`, `requires_reason`, `allows_training`, `allows_competition`;
- training type carries explicit recovery/high-intensity semantics;
- training zone carries explicit recovery/high-intensity semantics independently from numeric ranges;
- absence reason carries `health_related`;
- pool type carries `is_open_water`.

Existing known codes are backfilled to preserve current behaviour, but runtime model helpers read the semantic columns after F1.

## Lifecycle

Each active Sports configuration record is club-scoped and carries archive/audit metadata.

Rules:

1. technical code may be edited while the definition is unused;
2. once historical references exist, the code is immutable;
3. deleting an unused definition physically deletes it;
4. deleting a used definition archives/deactivates it instead;
5. archived definitions remain available for historical interpretation;
6. F1 is expand-first and does not destroy legacy Sports data.

## Tenant strategy

The new configuration lifecycle is scoped through `SportsClubContext`. Existing installations continue with the active club configured for the installation while the data shape remains ready for future multi-club operation.

## Permission

F1 introduces the `desportivo.configuracao` permission node and protects the canonical route with the Desportivo module plus view/edit/delete capabilities.

## Deferred to F2 and later

- `AgeGroup` structural redesign and season-scoped rules;
- canonical modalities and programme entities;
- location → pool → lane normalization;
- replacement of free-text modality in `prova_tipos` by canonical modality relations;
- athlete-specific operational limitations and their medical/privacy workflow;
- technical materials and athlete-owned equipment profile;
- final removal of now-unused global Configurações Sports payload reads.

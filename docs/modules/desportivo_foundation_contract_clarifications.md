# Desportivo — F0 Contract Clarifications

Date: 2026-08-11
Status: normative clarification of `desportivo_foundation_contract.md`

This document resolves the two residual F0 decisions left open after approval of the foundation contract. Where wording in the main F0 contract is ambiguous on these points, this clarification is authoritative until the documents are consolidated in a later foundation phase.

## 1. Programmes are permanent configurable sports structures

A Sports Programme is a stable, configurable identity inside a club + modality. Programmes are not recreated every season and are not synonymous with age groups.

Examples of age groups such as `Master` must never be modelled as programmes merely because their athletes can have a different competitive profile. In the current swimming context, Master remains an official competition age group. A Master athlete may compete intensively, occasionally, or have no competition objective; that intention does not change the athlete's age-group classification and must not be inferred from the programme name.

Programmes therefore follow these rules:

- programme identity is permanent/stable across seasons;
- programmes are configurable by the club;
- new programmes may be created;
- programmes may be deactivated/archived when no longer used;
- programmes already referenced historically are never hard-deleted;
- a programme belongs to a club and modality;
- a season activates/configures the relevant permanent programmes through a temporal/season relation instead of cloning programme identity;
- groups may be associated with a programme in the applicable season configuration;
- athlete participation is primarily derived from dated group membership; the model must not require duplicating programme membership when it can be derived safely;
- complementary group memberships may consequently expose an athlete to more than one programme context if this becomes operationally relevant;
- competition intention/availability is athlete/context-specific and is not encoded by turning `Master` into a separate programme.

For the current BSCN competition structure, `Competição` is not to be split into a separate `Masters` programme merely because some Master athletes do not intend to compete. Masters remain within the competitive sporting structure as an age group.

F2 may define the exact table names and season-programme association, but must preserve these semantics.

## 2. Athlete-owned technical equipment is separate from club inventory

Three different concepts must remain separate:

1. **Technical material catalogue (Desportivo/Biblioteca)** — e.g. prancha, pull buoy, palas, barbatanas, snorkel. This defines what an exercise or workout may require.
2. **Athlete-owned equipment profile (Desportivo)** — records whether an athlete has/uses a type of personal technical material and may store operational attributes such as size, model or notes where useful. It is not club stock and does not carry financial/inventory quantities.
3. **Club-owned equipment (Inventário/Logística)** — physical assets or stock owned/managed by the club, including equipment loaned or assigned to athletes.

Rules:

- creating a workout that requires technical material never depends on club inventory availability;
- an athlete may use their own material even when club stock is zero;
- the future workout/Cais flow may warn that an athlete lacks a required personal material or that club stock appears insufficient, but this is an operational warning, not a structural block by default;
- if club-owned material is issued/loaned to an athlete, the ownership and stock lifecycle remain in Inventário/Logística;
- optional mapping between a technical material definition and an inventory product/asset is allowed, but neither side becomes the other's canonical source;
- historical sports records should preserve the technical-material meaning even if a catalogue item is later archived.

The concrete athlete-equipment UI and persistence are deferred to the Biblioteca/Logística design, but the ownership boundary above is fixed in F0.

# Desportivo F2 — Estrutura Desportiva canónica

## Objetivo

A F2 estabelece a fundação estrutural do Desportivo sem reescrever Treinos, Cais, Competições ou integrações externas. A migração é expand-first e preserva as estruturas legacy necessárias ao cutover gradual.

## Modelo canónico

- Clube → Modalidade (`sports_modalities`).
- Modalidade → Programas permanentes (`sports_programs`).
- Época (`seasons`) pertence a clube + modalidade e ativa programas através de `season_programs`.
- Masters permanece um **escalão**, nunca é criado como programa.
- Escalões (`age_groups`) recebem tenant/code estáveis e as regras efetivas de cada época vivem em `season_age_group_rules`.
- Overrides técnicos de escalão vivem em `athlete_age_group_overrides`, com motivo e histórico.
- Grupo é uma identidade estável; configuração por época/programa vive em `training_group_seasons`.
- Associação de atleta mantém datas e pode apontar para um contexto grupo+época; um principal não pode sobrepor outro principal da mesma modalidade/época.
- Funções de treinador tornam-se catálogo em `sports_coach_roles`; as associações históricas continuam em `training_group_coaches`.
- `sports_venues` passa a representar o Local; piscinas/áreas vivem em `sports_pools`, pistas em `sports_pool_lanes`.

## Compatibilidade

As pistas legacy `sports_venue_lanes` continuam intactas porque Treinos/recorrências da PR5 ainda as referenciam. Para cada local legacy com pistas, o backfill cria uma piscina principal e copia as pistas para `sports_pool_lanes`, gravando `legacy_sports_venue_lane_id`. O cutover operacional ocorrerá numa fase posterior.

A coluna textual `training_groups.modality` também é preservada. F2 adiciona `sports_modality_id` e faz backfill; novos serviços devem preferir a entidade canónica.

## Regras

1. Programas são permanentes, configuráveis e arquiváveis; não são recriados a cada época.
2. Escalão oficial e grupo de treino são conceitos independentes.
3. Escalão é calculado pelas regras da época; o aniversário durante a época não muda automaticamente o enquadramento.
4. Override manual prevalece sobre regra automática e exige motivo.
5. Histórico estrutural utilizado não deve ser destruído.
6. Local → Piscina/Área → Pista é a hierarquia canónica; águas abertas podem existir sem pistas.
7. Todas as novas entidades são `club_id` scoped.
8. Não existe programa Masters implícito ou seedado.

## Fora de âmbito

- Cutover final da ficha desportiva Membros ↔ Desportivo (F3).
- Competition → Event projection (F4).
- Contrato financeiro (F5).
- Treinos, Cais, Live, Performance e Competições funcionais.
- Remoção física de aliases/tabelas legacy.

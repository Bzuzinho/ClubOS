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
- Fecho e reabertura de épocas são transacionais e cada transição gera um registo imutável em `sports_season_lifecycle_events`.

## Compatibilidade

A F2 não remove o contrato legacy de `Season`. Campos ainda usados pelo Planeamento (`ano_temporada`, `tipo`, `estado`, volumes, objetivos e restantes atributos históricos) e a relação `trainings()` permanecem disponíveis até ao cutover explícito de uma fase posterior.

O backfill de `seasons.status` respeita o `estado` histórico (`Planeada`, `Em curso`, `Concluída`, `Arquivada`) em vez de transformar silenciosamente todas as épocas em `planned`.

As pistas legacy `sports_venue_lanes` continuam intactas porque Treinos/recorrências da PR5 ainda as referenciam. Para cada local legacy com pistas, o backfill cria uma piscina principal e copia as pistas para `sports_pool_lanes`, gravando `legacy_sports_venue_lane_id`. O cutover operacional ocorrerá numa fase posterior.

A coluna textual `training_groups.modality` também é preservada. F2 adiciona `sports_modality_id` e faz backfill; novos serviços devem preferir a entidade canónica.

## Regras

1. Programas são permanentes, configuráveis e arquiváveis; não são recriados a cada época.
2. Escalão oficial e grupo de treino são conceitos independentes.
3. Escalão é calculado pelas regras da época; quando a regra não define `reference_date`, a data final da época é usada como referência determinística. A data atual nunca decide silenciosamente o escalão.
4. Override manual prevalece sobre regra automática, exige motivo e mantém o override anterior como histórico terminado.
5. Fechar/reabrir uma época preserva ator, instante, estado anterior/seguinte e motivo de reabertura num histórico append-only.
6. Histórico estrutural utilizado não deve ser destruído.
7. Local → Piscina/Área → Pista é a hierarquia canónica; águas abertas podem existir sem pistas.
8. Todas as novas entidades são `club_id` scoped e relações estruturais são validadas no mesmo tenant.
9. Grupo, época e programa só podem ser associados quando pertencem à mesma modalidade; um programa tem de estar ativo na época antes de ser usado por um grupo.
10. Uma associação de atleta a um contexto grupo+época fica dentro das datas da época e não pode criar dois grupos principais sobrepostos na mesma modalidade/época.
11. Não existe programa Masters implícito ou seedado.

## Workspace

`/desportivo/estrutura` expõe a estrutura canónica com gestão de:

- modalidades e programas permanentes;
- ativação de programas por época;
- lifecycle de épocas;
- escalões e regras sazonais;
- configuração de grupos por época/programa;
- funções técnicas;
- locais, piscinas/áreas e pistas.

Os endpoints de override técnico de escalão e memberships com contexto sazonal já existem na fundação. A experiência final centrada na ficha do atleta fica deliberadamente para o cutover Membros ↔ Desportivo da F3, evitando duplicar UI ou ownership.

## Fora de âmbito

- Cutover final da ficha desportiva Membros ↔ Desportivo (F3).
- Competition → Event projection (F4).
- Contrato financeiro (F5).
- Treinos, Cais, Live, Performance e Competições funcionais.
- Remoção física de aliases/tabelas legacy.

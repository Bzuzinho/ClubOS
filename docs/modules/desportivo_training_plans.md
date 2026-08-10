# Desportivo — Planos de treino e sessões versionadas

Data: 2026-08-10
Estado: PR4 da refatoração funcional

## Princípio

O ClubOS passa a separar formalmente dois conceitos que antes estavam misturados:

- **Plano de treino**: conteúdo reutilizável criado no construtor de Treinos.
- **Sessão de treino**: ocorrência concreta, com data/hora/local/atletas, armazenada em `trainings`.

Não existe um segundo builder em Planeamento. O conteúdo técnico reutilizável continua a pertencer a Treinos; Planeamento organiza quando e para quem as sessões acontecem.

## Fonte canónica

### Plano reutilizável

- `training_plans`: identidade estável do plano/template.
- `training_plan_versions`: revisões imutáveis do conteúdo.
- `training_plan_series`: linhas/séries pertencentes a uma versão.

Cada alteração de conteúdo cria uma nova versão. Uma versão antiga não é sobrescrita.

### Sessão

- `trainings`: sessão agendada.
- `training_series`: snapshot operacional das séries efetivamente aplicadas à sessão.
- `training_athletes`: atletas atribuídos/assiduidade.
- `training_metrics`: métricas de execução.

Quando uma versão é aplicada, as linhas são copiadas para `training_series` com `source=plan_version`, `training_plan_version_id` e `training_plan_series_id`. Isso permite saber exatamente o que estava planeado naquela sessão mesmo que o plano tenha versões posteriores.

## Regras de versionamento

1. Editar conteúdo de um plano significa criar `training_plan_versions.version + 1`.
2. Criar uma nova versão não altera nenhuma sessão já agendada.
3. O utilizador pode selecionar explicitamente sessões futuras que ainda usam a versão anterior e aplicar a nova versão.
4. Atualização em lote é atómica: se uma sessão selecionada já não existir, for passada, estiver concluída ou usar outra versão de origem, nenhuma das selecionadas é alterada.
5. Sessões concluídas são congeladas e não podem receber outra versão.
6. Sessões passadas não entram no mecanismo de atualização de futuras sessões.

## Compatibilidade

A migração é aditiva:

- sessões antigas continuam em `trainings`;
- séries antigas continuam em `training_series` com origem manual/legacy;
- sessões existentes são classificadas como `published` na migração para não desaparecerem de fluxos atuais;
- `training_plan_version_id` é opcional, logo treinos manuais continuam suportados;
- `CreateTrainingAction` continua a ser o ponto canónico de criação de sessões e aceita **ou** `series_linhas` manuais **ou** `training_plan_version_id`, nunca ambos.

## Tenant

As novas tabelas e as sessões usam `club_id`, resolvido por `SportsClubContext`/`SPORTS_CLUB_ID`.

## Fora desta PR

Esta etapa não implementa:

- calendário/recorrência e conflitos de horários;
- lanes/capacidade/grupos por sessão;
- adaptações individuais por atleta;
- novo Cais/offline/timers;
- UI final do mockup aprovado.

Esses pontos usam esta camada como base nas etapas seguintes.

# Desportivo — Calendário, recorrência, pistas e conflitos

Data: 2026-08-10
Status: PR5 da refatoração canónica do Desportivo

## Objetivo

Esta etapa acrescenta planeamento operacional às sessões canónicas já existentes. `trainings` continua a ser a única entidade de sessão agendada; não é criada nem reativada `training_sessions`.

## Modelo

### Locais e pistas

- `sports_venues`: local desportivo do clube (piscina, ginásio, seco/outro).
- `sports_venue_lanes`: pista/recurso físico de um local, com capacidade configurável.
- `sports_venue_closures`: encerramento total do local ou apenas de uma pista num intervalo temporal.

Os encerramentos nunca apagam automaticamente um treino. Uma ocorrência afetada é mantida e marcada para decisão manual.

### Grupos numa sessão

- `training_session_groups`: liga uma sessão a um ou vários `training_groups`.
- cada grupo pode ter a sua `training_plan_version` ou uma instrução própria;
- `training_session_group_lanes`: atribui uma ou várias pistas ao grupo e permite definir capacidade planeada;
- grupos complementares podem coexistir na mesma sessão;
- os atletas são preparados a partir das memberships ativas na data da sessão, e não de uma lista JSON ou de uma cópia paralela.

Uma sessão publicada tem de possuir conteúdo técnico: plano/séries/instrução global ou, quando aplicável, plano/instrução para cada grupo.

### Recorrência

- `training_recurrences`: regra diária/semanal, intervalo, dias da semana, horário, local, responsável e conteúdo global opcional;
- `training_recurrence_groups`: grupos e conteúdo técnico por grupo;
- `training_recurrence_group_lanes`: pistas planeadas para cada grupo da recorrência.

A expansão de uma recorrência cria sempre registos reais em `trainings` através de `CreateTrainingAction`. A chave `(training_recurrence_id, recurrence_occurrence_key)` torna a geração idempotente. Uma ocorrência sem plano/instrução suficiente é criada em `draft`; a recorrência não inventa conteúdo técnico.

## Conflitos

As políticas são configuráveis no clube e aceitam `allow`, `warn` ou `block`:

- `sports_lane_overlap_policy`: partilha da mesma pista em sessões/grupos simultâneos;
- `sports_athlete_overlap_policy`: atleta planeado em sessões simultâneas;
- `sports_capacity_policy`: atletas/capacidade distribuída acima da capacidade planeada ou física.

`warn` permite gravar a sessão e guarda o diagnóstico em `schedule_conflicts_snapshot`, ativando `schedule_review_required`. `block` rejeita a operação transacionalmente. `allow` não gera ocorrência de conflito desse tipo.

O treinador responsável pode coordenar vários grupos; não existe bloqueio automático por sobreposição de treinador.

Encerramentos têm severidade própria `decision_required`: o sistema não decide sozinho cancelar, mudar horário ou mudar pista.

## Exceções operacionais para o Cais

`training_schedule_exceptions` regista alterações operacionais posteriores ao planeamento (`lane_change`, `group_change`, `venue_change`, `time_change`) com estado anterior, estado posterior, autor, momento e justificação obrigatória.

Esta tabela prepara a PR6/Cais sem transformar uma alteração operacional numa reescrita silenciosa do planeamento histórico.

## API e leitura

- `CreateTrainingAction` continua a ser a porta canónica de criação.
- `UpdateTrainingScheduleAction` centraliza alterações de planeamento e impede reescrita de sessões concluídas.
- `TrainingController` fica tenant-scoped por `club_id` e devolve local, grupos, pistas e conflitos.
- `GetTrainingCalendarView` fornece leitura temporal tenant-scoped, limitada a 366 dias por consulta.

## Compatibilidade

A migração é aditiva. Treinos antigos continuam válidos sem local estruturado, recorrência ou grupos. O campo textual `local` é preservado como snapshot/compatibilidade. Nenhuma tabela legacy volta a entrar em lógica ativa.

## Fora de âmbito

Esta PR não implementa o runtime do Cais, timers, funcionamento offline, presença operacional, métricas de execução nem a integração visual final com o mockup aprovado. Esses pontos pertencem à PR6 e às etapas seguintes.

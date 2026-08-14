# Dashboard Desportivo — workspace funcional

## Objetivo

O Dashboard é uma superfície read-only de síntese operacional. Não cria um domínio paralelo e não reimplementa regras de Atletas, Treinos ou Competições.

## Fontes canónicas

- Atletas e alertas individuais: `SportsAthletesWorkspaceService`, assente em `sports_athlete_participations`, memberships, perfis sazonais e contrato documental de Membros.
- Treinos: `trainings`, sempre filtrados por `club_id`; sessões `cancelled` são excluídas.
- Assiduidade e volume executado: `training_athletes` efetivamente atribuídos às sessões do clube.
- Competições: `competitions` canónicas; número de atletas obtido através de `competition_registrations → provas → competitions`.

## Regras

- `users.tipo_membro` não determina a população desportiva do Dashboard.
- Campos médicos legacy de `users` não são lidos diretamente.
- Assiduidade não é comparada com o número global de treinos do clube: usa apenas atribuições reais em `training_athletes`.
- Não existe matching Competition/Event por título ou data.
- Sessões canceladas não contam em treinos, volume, assiduidade nem agenda.
- `schedule_review_required` gera alerta operacional e continua a ser resolvido em Treinos/Planeamento.

## Alertas

O Dashboard apresenta apenas alertas acionáveis e encaminha para a workspace proprietária:

- baixa assiduidade;
- atleta ativo sem grupo;
- documentação médica pendente;
- sessões com revisão de agendamento pendente.

## Cutover

`/desportivo` e `/desportivo/dashboard` passam a renderizar `Desportivo/DashboardWorkspace`. O route name `desportivo.index` é preservado para compatibilidade de navegação.

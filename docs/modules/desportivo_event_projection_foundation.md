# F4 — Desportivo ↔ Eventos: projeção de competições

## Decisão de ownership

`Competition` é o master desportivo. `Event` é uma projeção para calendário, divulgação, convocatórias e logística.

Eventos deixa de criar, atualizar ou apagar `Competition`. Um evento com categoria `competicao` ou `prova` criado no módulo Eventos continua a ser apenas um evento. Recorrência de eventos nunca gera competições master.

## Contrato canónico

- `competitions.club_id` define o tenant desportivo.
- `competition_event_projections` é o vínculo canónico 1:1 entre competição e evento.
- `competitions.evento_id` permanece temporariamente como ponte de compatibilidade e é sincronizado pela projeção.
- uma alteração no master atualiza a mesma projeção; não cria duplicados.
- campos projetados (`titulo`, datas, local, tipo e recorrência) pertencem ao master e não podem ser alterados pela edição do Evento.
- campos logísticos e editoriais do Evento permanecem sob Eventos.
- apagar um Evento projetado apenas destaca a projeção; não remove competição, provas, inscrições ou resultados.
- eliminar uma competição pela superfície existente passa a arquivá-la, preservando histórico.
- competição cancelada/arquivada projeta `estado=cancelado` no Evento.

## Migração expand-first

A migration F4 preserva todos os IDs e cria uma linha de projeção para cada competição existente:

- relação legacy inequívoca e Evento existente → `linked`;
- sem Evento legacy → `pending_projection`;
- Evento inexistente ou partilhado por várias competições → `manual_review`.

Não existe escolha automática em relações ambíguas. `legacy_event_id` é mantido na linha de projeção para revisão e reconciliação posterior.

## Guard rails

A regra `events_competition_master_boundary` deixa de ter exceções: nenhum serviço em `app/Services/Eventos` pode importar `App\\Models\\Competition`.

A integração financeira das inscrições não é alterada nesta fase; continua explicitamente reservada para F5.

# Desportivo — Grupos, objetivos e indicadores

Date: 2026-08-10
Status: PR3 implementation

## Scope

Esta etapa adiciona estruturas canónicas e aditivas para três conceitos que antes não tinham uma fonte de dados própria no módulo Desportivo:

- grupos de treino independentes dos escalões oficiais;
- objetivos desportivos com versões históricas;
- indicadores configuráveis do atleta com registos históricos.

Não substitui `trainings`, `training_athletes`, `training_metrics`, `age_groups` ou `athlete_sports_data`.

## Tenant sports context

As novas tabelas de domínio incluem `club_id`. O clube ativo é resolvido por `SportsClubContext` e configurado com `SPORTS_CLUB_ID`, com fallback `bscn` durante a fase atual de clube único.

Esta PR não tenta tornar Membros, Financeiro ou Eventos multi-tenant.

## Training groups

Canonical tables:

- `training_groups`
- `training_group_memberships`
- `training_group_coaches`
- `training_group_age_groups`

Regras:

- um grupo pode conter vários escalões oficiais;
- um atleta pode ter um grupo principal e vários grupos complementares;
- memberships têm `starts_at` e `ends_at`, logo mudanças não apagam histórico;
- não é permitido sobrepor dois grupos principais para o mesmo atleta no mesmo período;
- grupos complementares podem sobrepor-se;
- o escalão oficial do atleta continua em `athlete_sports_data` e não é alterado por pertencer a um grupo de treino diferente.

## Sports objectives

Canonical tables:

- `sports_objectives`
- `sports_objective_versions`

Targets suportados:

- clube;
- modalidade;
- época;
- escalão;
- grupo de treino;
- atleta;
- competição;
- prova.

O registo principal mantém o alvo e a versão corrente. Cada revisão cria uma nova linha em `sports_objective_versions`; versões anteriores nunca são sobrescritas.

Objetivos podem ser textuais ou mensuráveis e guardar indicador, valor-alvo, unidade, data e visibilidade configurável.

## Athlete indicators

Canonical tables:

- `athlete_indicator_definitions`
- `athlete_indicator_records`

Tipos de dados suportados na base desta PR:

- número;
- texto;
- booleano;
- data;
- tempo em milissegundos;
- JSON estruturado.

Ao registar um valor são guardados snapshots da versão, nome, unidade e tipo do indicador. Assim, uma alteração posterior da definição não muda o significado histórico dos registos anteriores.

Apagar um indicador significa arquivar a definição por soft delete. Os registos históricos permanecem disponíveis para estatística e auditoria.

## Out of scope

- UI final do mockup aprovado;
- templates/versionamento do construtor de treino;
- planeamento por grupo dentro de sessões;
- novo Cais;
- competição/convocatórias;
- permissões CRUD novas, porque esta PR ainda não expõe endpoints HTTP para estes conceitos.

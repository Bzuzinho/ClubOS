# Desportivo — Workspace Atletas

## Objetivo

A workspace `/desportivo/atletas` é a vista operacional canónica dos atletas no módulo Desportivo. Não cria uma segunda ficha de Membros e não usa campos legacy de `users` para decidir quem é atleta.

## Fonte de verdade

- atleta desportivo: `sports_athlete_participations`;
- modalidades: `sports_modalities` + participação ativa;
- grupos: `training_group_memberships` ativos na data atual;
- escalão oficial: `sports_athlete_season_profiles.official_age_group_id` no contexto sazonal atual;
- assiduidade: atribuições reais em `training_athletes`, com `presente` e `atrasado` a contar como presença;
- volume e RPE: `training_athletes` das sessões canónicas do clube;
- resultados: `results` ligados a `provas -> competitions` do clube;
- avaliações: avaliações concluídas do domínio canónico;
- estado documental do atestado: contrato de documentos de Membros via `MemberDocumentDataResolver`.

## Guard rails

- `users.tipo_membro` não determina a população desta workspace;
- `users.informacoes_medicas` / JSON médico legacy não é interpretado;
- volume nunca é usado como proxy de assiduidade;
- não são reconstruídas relações por nome, título ou data;
- dados clínicos não são apresentados nem inferidos;
- a ficha operacional 360º compõe o perfil desportivo canónico com a Análise read-only já existente.

## UX

- lista operacional por defeito;
- opção de Cards;
- pesquisa por nome/número de sócio;
- filtros por estado desportivo, modalidade, grupo e escalão;
- indicadores de assiduidade, volume, RPE, pódios, avaliação e estado documental;
- detalhe lateral com modalidades, grupos, escalão oficial, documento médico e resumo analítico.

## Compatibilidade

O acesso legacy `/desportivo?tab=atletas` é redirecionado para `/desportivo/atletas`. A aba Atletas no shell antigo navega diretamente para a workspace dedicada.

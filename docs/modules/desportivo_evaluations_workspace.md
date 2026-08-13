# Desportivo · Avaliações

## Objetivo

Avaliações formaliza a apreciação estruturada do treinador sobre a evolução do atleta. Não substitui Registos nem Análise: Registos preserva factos, Avaliações preserva apreciações estruturadas, sínteses e objetivos, e Análise interpreta quantitativamente ambos.

## Domínio

Tabelas canónicas: `sports_evaluation_models`, `sports_evaluation_model_versions`, `sports_evaluation_sections`, `sports_evaluation_criteria`, `sports_evaluation_campaigns`, `sports_evaluation_campaign_groups`, `sports_evaluation_campaign_athletes`, `sports_evaluations` e `sports_evaluation_answers`.

O domínio não reutiliza `sports-v2-performance-metrics` nem KeyValue.

## Versionamento

Um modelo nasce com versão `draft`. Em rascunho, secções e critérios podem ser criados, editados, reordenados e apagados. Ao publicar, a versão fica imutável. Alterações futuras exigem `fork`, criando uma nova versão `draft` que copia a estrutura anterior.

Versões publicadas anteriores podem ficar `archived`, mas continuam referenciáveis por campanhas e histórico existentes.

A publicação exige pelo menos uma secção e um critério ativos. Se houver pesos diferentes de zero, as secções devem totalizar 100% e os critérios ativos de cada secção também.

## Critérios

Tipos suportados: `scale`, `number`, `choice`, `text` e `boolean`.

Cada critério suporta nome, descrição/instrução, limites de escala, opções, peso, obrigatório/opcional, comentário, ordem e estado.

Avaliações concluídas persistem snapshots de nome do critério, secção, tipo de resposta, peso, limites e opções. Assim alterações futuras nunca reescrevem avaliações históricas.

## Campanhas

Uma campanha liga uma versão publicada a um período e a um ou mais grupos. Na publicação da campanha, os atletas são materializados a partir de `training_group_memberships` ativos na data da campanha. Alterações posteriores no grupo não alteram retroativamente o universo da campanha.

Estados previstos da campanha: `draft`, `planned`, `active`, `closed`, `cancelled`. Estados do atleta: `pending`, `draft`, `completed`, `excluded`.

## Avaliação individual

A avaliação começa em `draft` e pode ser guardada incrementalmente. A conclusão valida todos os critérios obrigatórios. Depois de concluída fica read-only; uma correção exige reabertura explícita com motivo e auditoria (`reopened_at`, `reopened_by`, `reopen_reason`).

## Histórico

A vista Por atleta apresenta apenas avaliações concluídas do clube: campanha, modelo, data, síntese, objetivos e médias numéricas por secção quando aplicável.

## Permissões

Nesta fase usa-se o gate compatível `desportivo.treinos.cais` (`view`/`edit`) para não bloquear perfis já provisionados. Uma permissão dedicada fica para o cutover de ACL do módulo.

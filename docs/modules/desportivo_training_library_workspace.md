# Desportivo — Biblioteca / Construtor funcional

Data: 2026-08-13

## Objetivo

Separar definitivamente **plano reutilizável** de **sessão agendada** e transformar a fundação `training_plans → training_plan_versions → training_plan_series` numa workspace operacional de treinador.

A Biblioteca é um módulo próprio entre Planeamento e Treinos. `trainings` deixa de ser usado como pseudo-biblioteca através de registos sem data; passa a representar apenas sessões concretas.

## Contrato canónico

- `training_plans`: identidade estável do plano.
- `training_plan_versions`: versões imutáveis do conteúdo.
- `training_plan_blocks`: blocos ordenados e repetíveis por rondas.
- `training_plan_series`: linhas técnicas dentro de um bloco.
- `training_plan_series_materials`: material técnico necessário por linha.
- `sports_training_materials`: catálogo técnico do Desportivo; não representa stock.
- `sports_strokes`: catálogo técnico de estilos.
- `training_zone_configs`: zona/intensidade canónica já existente.
- `sports_modalities`: modalidade canónica já existente.
- `trainings` + `training_series`: sessão concreta e snapshot operacional da versão aplicada.

## Blocos, rondas e volume

Um bloco pode ter `rounds > 1`. O volume da versão é `Σ(volume das linhas do bloco × rondas do bloco)`.

Uma linha `8×50` mantém simultaneamente `repeticoes = 8`, `distancia_m = 50` e `distancia_total_m = 400`. O total nunca substitui a unidade de execução. Isto é obrigatório para o futuro Live.

## Saída e descanso

A semântica fica congelada para evitar ambiguidade futura:

- `saida`: send-off/partida da repetição, por exemplo `@1:30`;
- `intervalo`: descanso entre execuções, por exemplo `c/15"`.

O parser de escrita rápida aplica esta mesma regra. Os snapshots da sessão preservam ambos os valores separadamente.

## Timing / futuro Live

Cada linha define `timing_mode`: `none`, `each_rep` ou `whole_series`.

Ao aplicar uma versão a uma sessão, o snapshot em `training_series` preserva bloco, ordem e rondas; repetições e distância unitária; zona e estilo canónicos + snapshots textuais; saída e descanso; modo de cronometragem; e material técnico.

## Versionamento

- Editar conteúdo cria sempre uma versão nova.
- Versões anteriores nunca são reescritas.
- Criar vN+1 não altera sessões já agendadas.
- Atualizar sessões futuras continua explícito através de `TrainingSessionPlanService`.
- Planos arquivados usam soft delete e continuam disponíveis para histórico.
- Duplicar cria uma nova identidade de plano em `draft`, com conteúdo copiado da versão corrente.
- Planos legacy sem `training_plan_blocks` também podem ser duplicados: as séries são agrupadas apenas pelo valor exato de `bloco`, sem inferência por nomes.

## Compatibilidade legacy

Planos antigos com `series_linhas` continuam aceites pelo serviço. As linhas são adaptadas deterministicamente para blocos a partir do valor exato de `bloco`; não há aproximação de IDs.

Os campos históricos `modalidade`, `zona_intensidade`, `estilo` e `material` permanecem como snapshots/compatibilidade. Novos writes da Biblioteca usam os FKs canónicos quando fornecidos.

Não é executado backfill por nome para `sports_modality_id`, `training_zone_config_id` ou `sports_stroke_id`. Relações antigas não inequívocas permanecem sem FK em vez de serem adivinhadas.

## Material técnico vs Logística

A Biblioteca responde à pergunta: **“que material técnico é necessário para executar este treino?”**

Inventário/Logística responde à pergunta: **“que stock/ativos do clube existem e onde estão?”**

A Biblioteca não reduz stock, não cria movimentos de inventário e não bloqueia um plano porque não existe stock. Uma integração futura pode apenas avisar disponibilidade através do gateway de Logística definido na F6.

## UX

A Biblioteca inclui lista por defeito e opção de cards; pesquisa por nome, modalidade, tipo, tag, zona, estilo e material; estados publicado/rascunho/arquivado; histórico de versões e número de sessões por versão; duplicação e arquivo; construtor quase full-screen; modo estruturado por blocos/séries; e modo de escrita rápida que interpreta notação corrente antes da confirmação estruturada.

## Treinos

A tab Treinos mantém calendário, agendamento, atletas e operação das sessões. O card legacy “Biblioteca de treinos” fica ocultado do fluxo ativo para impedir novos pseudo-planos em `trainings`.

Uma refatoração funcional posterior de Treinos poderá remover fisicamente esse código UI legacy, sem antecipar essa fase neste PR.

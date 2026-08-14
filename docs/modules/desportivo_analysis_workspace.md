# Desportivo · Análise

## Objetivo

Análise é a camada final de interpretação transversal do módulo Desportivo. É read-only: não cria uma segunda fonte de métricas nem substitui os domínios que recolhem factos.

## Fontes canónicas

- Treino/Cais: `trainings` + `training_athletes` para presença, estado, volume real e RPE.
- Live: `sports_live_metric_records`, excluindo registos anulados (`voided_at`).
- Avaliações: `sports_evaluations` concluídas, sempre através de campanhas do clube.
- Resultados: `results → provas → competitions`, incluindo `result_splits`.
- Grupos: `training_group_memberships` ativos e scoped por `club_id`.

O KeyValue legacy `sports-v2-performance-metrics` não é uma fonte ativa da workspace.

## Vistas

### Por atleta

Agrega uma janela temporal configurável e apresenta:
- assiduidade;
- volume realizado;
- RPE médio e cobertura de RPE;
- métricas Live configuradas e registadas;
- evolução das avaliações formais;
- resultados e melhores tempos por prova;
- pódios e splits;
- cobertura/qualidade dos dados;
- pares exploratórios treino semanal vs. resultado competitivo.

### Por grupo

Agrega apenas membros ativos do grupo no clube corrente e resume assiduidade, volume, RPE, avaliações, pódios e cobertura por atleta.

### Competição

Lê apenas o domínio competitivo canónico e resume resultados, atletas, pódios, estados DSQ/DNS/DNF, pontos, resultados por prova e classificação coletiva.

### Indicadores

Expõe a proveniência e a natureza de cada indicador (`factual`, `derived`, `measured`, `coach_appraisal`) para tornar a interpretação auditável.

## Guard rails

1. Análise não escreve métricas.
2. Análise não cria competições, provas, avaliações ou registos de treino.
3. Nenhuma associação exploratória é apresentada como causalidade, diagnóstico ou previsão de lesão.
4. Não existem labels automáticos de “risco” derivados de ACWR.
5. Dados anulados no Live são excluídos.
6. O acesso a atleta, grupo, competição e avaliações respeita `SportsClubContext` e os vínculos canónicos.
7. Nenhum matching por nome/título/data é usado para reconstruir relações.

## Rotas

- `GET /desportivo/analise`
- `GET /desportivo/analise/atletas/{athlete}?weeks=12`
- `GET /desportivo/analise/grupos/{group}?weeks=12`
- `GET /desportivo/analise/competicoes/{competition}`

Não é necessária migration nesta fase: a workspace deriva exclusivamente de dados canónicos existentes.

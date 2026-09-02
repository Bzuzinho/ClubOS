# Desportivo · Análise

## Objetivo

Análise é a camada final de interpretação transversal do módulo Desportivo. É **read-only**: não cria uma segunda fonte de métricas, não substitui os domínios que recolhem factos e não permite registar manualmente uma pseudo-métrica de performance.

A regra funcional é:

`Registos guarda factos → Avaliações formaliza a apreciação do treinador → Resultados regista o que aconteceu em competição → Análise interpreta transversalmente estas fontes`.

## Fontes canónicas

- Treino/Cais base: `trainings` + `training_athletes` para presença, estado, volume real e RPE.
- Métricas Cais configuráveis: `training_athlete_cais_metrics`, enriquecidas pelo catálogo `sports_cais_metric_definitions` quando existe correspondência exata por código/nome.
- Live: `sports_live_metric_records`, excluindo registos anulados (`voided_at`).
- Avaliações: `sports_evaluations` concluídas, sempre através de campanhas do clube.
- Resultados: `results → provas → competitions`, incluindo `result_splits`.
- Grupos: `training_group_memberships` ativos e scoped por `club_id`.
- Atletas: audiência desportiva canónica ativa, nunca inferida por nome ou por atributos legacy.

O KeyValue legacy `sports-v2-performance-metrics` **não é uma fonte ativa** da workspace. Os antigos endpoints `/api/desportivo/performance` e `/api/desportivo/performance-metrics` também deixam de fazer parte do routing runtime; os ficheiros legacy remanescentes ficam apenas para cleanup posterior controlado.

## Vistas

### Por atleta

Agrega uma janela temporal configurável e apresenta:

- pesquisa por nome ou número de sócio e filtro por grupo;
- assiduidade;
- volume realizado;
- RPE médio e cobertura de RPE;
- métricas configuráveis do Cais, com último valor, unidade, média numérica quando aplicável e tamanho da amostra;
- métricas Live, separadas das métricas Cais e com a mesma proveniência explícita;
- evolução das avaliações formais;
- resultados, melhores tempos e pódios por prova;
- consulta de cada resultado com splits expansíveis;
- cobertura/qualidade dos dados;
- pares exploratórios treino semanal vs. resultado competitivo;
- exportação CSV read-only construída a partir do mesmo read model da workspace.

### Por grupo

Agrega apenas membros ativos do grupo que continuam na audiência desportiva canónica do clube corrente.

O agregado é calculado em batch para evitar repetir a análise completa N vezes e resume:

- assiduidade;
- volume;
- RPE;
- avaliações;
- pódios;
- cobertura por atleta;
- cobertura de métricas Cais/Live como sinal de qualidade dos dados.

### Competição

Lê apenas o domínio competitivo canónico e resume:

- resultados;
- atletas;
- pódios;
- estados DSQ/DNS/DNF;
- pontos;
- resultados e melhor tempo por prova;
- classificação coletiva.

Não cria, corrige nem reparenta resultados: isso pertence ao módulo Resultados.

### Indicadores

Expõe a proveniência e a natureza de cada indicador (`factual`, `derived`, `measured`, `coach_appraisal`) para tornar a interpretação auditável.

Exemplos:

- Assiduidade → `training_athletes` → factual;
- Volume realizado → treino + presença → derived;
- RPE → `training_athletes` → measured;
- Métricas Cais → `training_athlete_cais_metrics` → measured;
- Métricas Live → `sports_live_metric_records` → measured;
- Avaliação formal → Avaliações → coach_appraisal;
- Tempo/splits competitivos → Resultados → factual.

## Guard rails

1. Análise não escreve métricas.
2. Análise não cria competições, provas, avaliações ou registos de treino.
3. Nenhuma associação exploratória é apresentada como causalidade, diagnóstico ou previsão de lesão.
4. Não existem labels automáticos de “risco” derivados de ACWR.
5. Dados anulados no Live são excluídos.
6. O acesso a atleta, grupo, competição e avaliações respeita `SportsClubContext` e os vínculos canónicos.
7. Nenhum matching por nome/título/data é usado para reconstruir relações.
8. Métricas Cais e Live são mantidas como proveniências distintas, mesmo quando têm nomes semelhantes.
9. A exportação não recalcula indicadores por outra via: reutiliza o mesmo read model de Análise.
10. Os endpoints legacy de Performance não fazem parte do runtime ativo.

## Rotas

- `GET /desportivo/analise`
- `GET /desportivo/analise/atletas/{athlete}?weeks=12`
- `GET /desportivo/analise/atletas/{athlete}/export.csv?weeks=12`
- `GET /desportivo/analise/grupos/{group}?weeks=12`
- `GET /desportivo/analise/competicoes/{competition}`

## Dados e migrations

Não é necessária migration nesta fase. A workspace deriva exclusivamente de dados canónicos existentes e não introduz tabelas de “performance” paralelas.

Na H3g foi removida fisicamente a cadeia Performance órfã: controller placeholder, componente KeyValue/ACWR, hook, service e mocks sem consumidores, juntamente com os respetivos exports/tipo exclusivos. Esta remoção não altera dados: o rollback é a reversão do commit.

## QA funcional

`SportsAnalysisWorkspaceFunctionalTest` cobre, entre outros:

- isolamento por clube e audiência ativa;
- presença, volume e RPE derivados do treino;
- métricas Cais no read model;
- splits competitivos;
- agregação por grupo;
- declaração read-only;
- rota de exportação;
- guard rail que impede o regresso dos endpoints legacy Performance ao routing ativo;
- ausência física dos artefactos KeyValue retirados e ausência de fontes proibidas no serviço de reporting.

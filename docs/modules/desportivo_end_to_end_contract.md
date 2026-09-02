# Desportivo — Contract ponta a ponta H3

Data: 2026-09-02
Status: H3f — Portal operacional concluído e integrado

## Objetivo

Fechar o módulo Desportivo como um único fluxo operacional coerente, sem regressar a domínios paralelos ou fontes legacy:

`Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → Análise/Reporting`

A H3a estabeleceu o baseline canónico. A H3b provou Planeamento → Treino com snapshot imutável de uma versão de plano. A H3c fixou `training_athletes` como a única espinha operacional entre preparação, Cais e presença. A H3d fechou a execução Live com métricas/splits concorrentes, distância unitária e progressão automática. A H3e fechou a continuidade entre competição, programa, inscrição e resultado sem criar fontes paralelas. A H3f fechou agenda, presença e resultados no Portal autenticado como projeções canónicas, sem materializar novos factos durante a leitura.

## Workspaces canónicas

- `desportivo.index` — dashboard operacional
- `desportivo.planeamento` — planeamento
- `desportivo.cais` — operação de cais
- `desportivo.live` — cronometragem/execução live
- `desportivo.competicoes` — competições
- `desportivo.resultados` — resultados
- `desportivo.relatorios` — análise transversal

Todas permanecem autenticadas, verificadas, protegidas por `module.access:desportivo` e pela permission específica da workspace.

## Data spine canónica

Planeamento e treino reutilizável:
- `seasons`, `macrocycles`, `mesocycles`, `microcycles`
- `training_plans`, `training_plan_versions`, `training_plan_series`

Sessão operacional:
- `trainings`
- `training_series`
- `training_athletes`
- `training_metrics`

Competição e resultados:
- `competitions`
- `provas`
- `competition_registrations`
- `results`
- `team_results`

## H3b — contrato Planeamento → Treino

Ao criar uma sessão no Planeamento com `training_plan_version_id`:
1. a cadeia Época → Macro → Meso → Micro é normalizada para a sessão;
2. `trainings.training_plan_version_id` fica preso à versão selecionada;
3. as linhas da versão são copiadas para `training_series` com `source=plan_version` e referência a `training_plan_series_id`;
4. `plan_applied_at` e `plan_applied_by` registam a aplicação;
5. uma nova revisão do plano não altera retroativamente a sessão nem as suas séries;
6. uma atualização para outra versão exige uma operação explícita sobre sessões futuras selecionadas.

## H3c — contrato Treino → Cais → Presenças

1. preparar/selecionar um atleta para uma sessão cria ou reutiliza exatamente um registo em `training_athletes`;
2. o Cais lê esse mesmo registo e expõe o respetivo `training_athlete_id`;
3. alterações de presença no Cais atualizam o mesmo registo, sem criar uma segunda fonte de attendance;
4. `atrasado` continua semanticamente presente (`presente=true`), preservando o estado detalhado em `estado`;
5. métricas logísticas/técnicas continuam em `training_metrics`, ligadas ao mesmo `treino_id` + `user_id`;
6. a tabela legacy `presences` permanece proibida como fonte de verdade ativa.

## H3d — contrato Live → métricas/splits

1. a execução Live reutiliza a identidade canónica da sessão e dos atletas preparados;
2. a seleção operacional segue atleta(s) → linha de treino → cronómetro;
3. cada repetição `each_rep` regista a distância unitária da linha, sem multiplicar pela quantidade total de repetições;
4. completar as repetições de uma linha progride automaticamente para a linha seguinte;
5. grupos/atletas distintos podem manter cronómetros concorrentes independentes;
6. STOP/progressão é serializado e os splits rejeitam regressões temporais.

## H3e — contrato Competições → Resultados

1. `competitions` é a raiz da competição e `provas.competicao_id` define o programa canónico;
2. uma inscrição é exatamente o par `competition_registrations.prova_id + user_id` para uma prova já existente;
3. um resultado individual reutiliza exatamente o mesmo par `results.prova_id + user_id`; uma nova gravação atualiza o resultado existente em vez de criar um segundo facto competitivo;
4. o workspace de Resultados só aceita resultados para atletas previamente inscritos nessa prova;
5. `team_results.competicao_id` permanece ligado diretamente à competição canónica;
6. leituras, criação, atualização e eliminação nas APIs de inscrições/resultados são sempre restringidas ao `SportsClubContext`, incluindo model bindings recebidos por ID;
7. provas/resultados de outro clube não podem ser usados como atalho para atravessar o boundary de tenancy.

## H3f — contrato Portal operacional

1. as páginas GET do Portal são projeções de leitura e não criam registos operacionais;
2. a área de Treinos lê apenas `training_athletes` já preparados pelo fluxo Desportivo e pertencentes ao clube ativo; confirmações e justificações continuam writes explícitos sobre esse mesmo registo;
3. a agenda lê Eventos como projeção de calendário e transporta `competition_event_projections.competition_id` quando o item nasceu de uma competição;
4. uma projeção de competição pertencente a outro `SportsClubContext` não é exposta nem pode ser respondida no Portal;
5. Resultados lê apenas `results → prova → competition` do atleta autenticado e do clube ativo, sem recorrer a `event_results`, títulos ou datas para reconstruir relações;
6. o Portal pessoal não expõe resultados de outros atletas; a consulta de educandos permanece separada no módulo Família e não transforma resultados privados em publicação pública.

## Fontes proibidas no negócio Desportivo ativo

Continuam proibidas como fontes de verdade ativas:
- `training_sessions`
- `presences`
- `event_results`
- `event_attendances`

A existência física de tabelas legacy não autoriza novos consumidores. O `LegacySportsGuard` permanece o guard rail.

## Regra para H3+

Cada lote funcional deve:
1. preservar o data spine canónico;
2. não criar dual writes;
3. provar a transição entre a etapa anterior e a seguinte;
4. acrescentar cobertura backend e, quando aplicável, Playwright para desktop/mobile;
5. atualizar este contract e o estado vivo com evidência real da CI/deploy.

## Ordem recomendada

- H3b: Planeamento → sessão de treino — concluído e integrado.
- H3c: Treino → Cais → Presenças — concluído e integrado.
- H3d: Live → métricas/splits — concluído e integrado, com contagens concorrentes, distância unitária e progressão automática das séries validadas.
- H3e: Competições → Resultados — concluído e integrado, com inscrições, provas, resultados e isolamento por clube validados.
- H3f: Portal — concluído e integrado; agenda, presenças e resultados são projetados sem novas fontes de verdade.
- H3g: Análise/reporting + cleanup legacy final — próximo passo ativo.

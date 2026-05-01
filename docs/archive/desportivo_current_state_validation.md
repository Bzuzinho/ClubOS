# Validação Atual do Módulo Desportivo

## Resultado Final

**PARTIALLY VALIDATED**

O módulo Desportivo atual está **maioritariamente migrado para a espinha dorsal canónica** baseada em `trainings`, `training_athletes`, `training_metrics`, `competitions`, `provas`, `competition_registrations`, `results` e `team_results`.

No entanto, **não pode ser classificado como VALIDATED em sentido estrito** porque ainda existem pontos de acoplamento ativo ou residual com a camada de `Event`, inconsistências entre o modelo canónico alvo e o schema físico atual, e algumas áreas do frontend que ainda não fecham totalmente o ciclo sobre os dados canónicos reais.

---

## 1. Auditoria de Uso de Tabelas Legacy

### Tabelas proibidas auditadas

- `training_sessions`
- `presences`
- `event_results`
- `event_attendances`

### Resultado

**Parcialmente conforme.**

### Evidência observada

#### `training_sessions`
- Referências encontradas sobretudo em proteção/auditoria/migração:
  - `app/Support/LegacySportsGuard.php`
  - `app/Console/Commands/AuditarTrainingSessions.php`
- Não foi encontrada evidência de uso desta tabela no fluxo principal atual de treinos, cais, competições ou resultados do módulo Desportivo.

#### `presences`
- Há muitas ocorrências nominais de "presences/presencas", mas a maioria não representa uso ativo da tabela legacy.
- No fluxo atual do módulo Desportivo, a presença está a ser operada sobre `training_athletes` em:
  - `app/Http/Controllers/DesportivoController.php`
  - `app/Services/Desportivo/UpdateTrainingAthleteAction.php`
- Ainda existem artefactos legacy em:
  - `app/Models/Presence.php`
  - `app/Services/Desportivo/MigrateLegacyPresencesAction.php`
  - `app/Console/Commands/MigrarPresencasLegacy.php`
  - `app/Models/User.php` via relação `presences()`

#### `event_results`
- Referências encontradas essencialmente em:
  - `app/Support/LegacySportsGuard.php`
  - `app/Models/EventResult.php`
  - `app/Models/User.php` via `eventResults()`
- Não foi observada evidência de que `event_results` seja a base ativa dos endpoints e queries canónicos atuais de resultados desportivos.

#### `event_attendances`
- Referências encontradas em:
  - `app/Support/LegacySportsGuard.php`
  - `app/Models/EventAttendance.php`
  - `app/Services/Desportivo/SyncTrainingToEventAction.php`
- `SyncTrainingToEventAction` ainda escreve para `event_attendances`.
- `TrainingAthleteObserver` foi neutralizado e não está a espelhar presença para a tabela legacy.
- Não foi encontrada evidência de registo ativo do `TrainingAthleteObserver` em `AppServiceProvider`, o que reduz risco operacional imediato.

### Conclusão desta secção

O uso das tabelas legacy proibidas **não domina o fluxo ativo principal** do módulo Desportivo atual. Porém, a existência de serviços ainda capazes de escrever para `event_attendances`, somada à permanência de relações/modelos legacy no domínio, significa que o sistema **não está totalmente limpo nem totalmente desacoplado** da camada anterior.

---

## 2. Cobertura das Tabelas Canónicas

### Resultado

**Largamente conforme.**

### Cobertura observada

- `users`: base de atletas, inscrições e resultados.
- `athlete_sports_data`: perfil desportivo canónico do atleta.
- `age_groups`: usado via `escalao_id` e pivot de treinos.
- `seasons`, `macrocycles`, `mesocycles`, `microcycles`: presentes no backend, mas com nomenclatura física mista (`epoca_id`, `microciclo_id`, etc.).
- `trainings`: raiz canónica dos treinos.
- `training_series`: modelado no backend.
- `training_athletes`: base ativa de presença/execução por atleta.
- `training_metrics`: base ativa de métricas de cais.
- `competitions`: raiz canónica das competições.
- `provas`: provas por competição.
- `competition_registrations`: inscrições por prova/atleta.
- `results`: resultados individuais.
- `team_results`: resultados coletivos/equipa.
- `training_age_group`: pivot canónico treino-escalão.

### Observações relevantes

- O backend e os endpoints `/api/desportivo/*` estão maioritariamente alinhados com este modelo.
- A cobertura canónica existe, mas **nem toda a superfície frontend expõe integralmente todas estas entidades**.
- `training_series` existe ao nível de modelo/schema/query, mas a sua exposição funcional no separador de treinos não está claramente fechada no estado atual.

---

## 3. Validação das Relações

### Resultado

**Maioritariamente correta, com resíduo legacy e compatibilidade mista.**

### Relações canónicas confirmadas

- `User -> athleteSportsData`
- `User -> trainingAthletes`
- `User -> competitionRegistrations`
- `User -> results`
- `AthleteSportsData -> user`
- `AthleteSportsData -> escalao`
- `Training -> season`
- `Training -> macrocycle`
- `Training -> mesocycle`
- `Training -> microcycle`
- `Training -> series`
- `Training -> athleteRecords`
- `Training -> ageGroups`
- `Training -> metrics`
- `TrainingSeries -> training`
- `TrainingAthlete -> training`
- `TrainingAthlete -> athlete`
- `TrainingAthlete -> metrics`
- `TrainingMetric -> training`
- `TrainingMetric -> athlete`
- `TrainingMetric -> trainingAthlete`
- `Competition -> provas`
- `Competition -> results`
- `Competition -> teamResults`
- `Prova -> competition`
- `Prova -> registrations`
- `Prova -> results`
- `CompetitionRegistration -> prova`
- `CompetitionRegistration -> athlete`
- `Result -> prova`
- `Result -> athlete`
- `TeamResult -> competition`

### Problemas observados

- `app/Models/User.php` ainda mantém relações legacy dentro do mesmo modelo de domínio:
  - `eventAttendances()`
  - `eventResults()`
  - `resultProvas()`
  - `presences()`
- O domínio atual funciona, mas o agregado `User` ainda expõe simultaneamente relações canónicas e legacy.
- Isto não quebra a execução atual, mas **enfraquece a separação arquitetural** esperada para um estado plenamente validado.

---

## 4. Validação do Fluxo Frontend por Separador

### Resultado

**Parcialmente validado.**

### Atletas

- Consome estrutura baseada em `users` + `athlete_sports_data`.
- Alinhamento global considerado bom.

### Treinos

- Usa `trainings`, `ageGroups`, `competitions` e ações web de treino.
- A modelação canónica do treino existe.
- **Lacuna:** não ficou evidenciado um uso funcional completo de `training_series` na experiência atual do separador.

### Cais

- O fluxo principal de presenças está suportado por `training_athletes`.
- As métricas são carregadas/guardadas por endpoints dedicados baseados em `training_metrics`.
- Esta é uma das áreas mais alinhadas com o modelo canónico atual.

### Competições

- Usa dados canónicos de competições e resultados.
- **Problema relevante:** a ação de visualização ainda aponta para rota de eventos (`eventos.show`), o que indica dependência residual da camada de eventos.

### Resultados

- Consome `results` canónicos.
- **Problema relevante:** `teamResults` tem fallback para dados mock quando não é fornecido pelo backend.
- Isto impede considerar o fluxo como totalmente validado de ponta a ponta para resultados coletivos.

### Performance

- O histórico/volume baseado em treino está alinhado com dados canónicos.
- **Problema relevante:** parte das métricas científicas/performance ainda depende de armazenamento local no frontend, e não do backend canónico.

### Dashboard

- Usa forma de dados coerente com treinos, competições, atletas e agregados canónicos.
- Alinhamento considerado bom.

### Planeamento

- Usa `seasons` e `macrocycles` do backend.
- **Problema relevante:** parte do planeamento avançado/microciclos V2 está mantida em armazenamento local, não totalmente na espinha dorsal canónica.

### Conclusão desta secção

O frontend está **maioritariamente orientado ao modelo canónico**, mas ainda contém pontos que impedem validação integral:

- dependência residual de rotas/eventos legacy
- fallback mock em resultados coletivos
- armazenamento local em áreas de performance/planeamento
- cobertura funcional incompleta de `training_series`

---

## 5. Validação do Fluxo Backend

### Resultado

**Parcialmente validado.**

### Áreas bem alinhadas

- Endpoints API de desportivo estão assentes em modelos canónicos:
  - `AthleteController`
  - `TrainingController`
  - `TrainingAttendanceController`
  - `CompetitionController`
  - `CompetitionResultController`
  - `CompetitionRegistrationController`
  - `TeamResultController`
- Queries dedicadas em `app/Services/Desportivo/Queries` usam a espinha dorsal canónica e proteção com `LegacySportsGuard`.
- Atualização de presença e métricas no módulo principal opera sobre `training_athletes` e `training_metrics`.

### Problemas relevantes

#### Acoplamento treino -> evento
- `app/Services/Desportivo/CreateTrainingAction.php` ainda cria `Event` associado quando cria um `Training`.
- O treino pode existir como entidade canónica, mas o fluxo não está completamente isolado da camada `Event`.

#### Controlador web híbrido
- `app/Http/Controllers/DesportivoController.php` está hoje mais canónico, mas continua a misturar naming e payloads herdados da camada anterior.
- O resultado funcional é aceitável, porém arquiteturalmente híbrido.

#### Serviço residual de sincronização legacy
- `app/Services/Desportivo/SyncTrainingToEventAction.php` ainda mantém lógica de escrita em `event_attendances`.
- Mesmo que não esteja claramente ligado ao fluxo principal atual, a presença deste serviço indica que o corte completo não terminou.

### Conclusão desta secção

O backend atual está **funcionalmente muito próximo do alvo canónico**, mas **não está totalmente desacoplado** do domínio `Event`. Por esse motivo, esta secção não atinge validação total.

---

## 6. Validação de Foreign Keys e Índices

### Resultado

**Parcialmente validado.**

### Estado geral

O schema mostra duas camadas:

1. migrações base com naming físico maioritariamente em português
2. migração de hardening para compatibilizar FKs/índices adicionais do modelo canónico

### Pontos confirmados

- `athlete_sports_data.user_id`: FK presente; unicidade reforçada.
- `athlete_sports_data.escalao_id`: FK adicionada no hardening quando possível.
- `training_series.treino_id`: FK presente na base.
- `training_athletes.treino_id` e `training_athletes.user_id`: FKs presentes na base; unicidade por treino+utilizador presente.
- `training_metrics.treino_id` e `training_metrics.user_id`: FKs presentes na base.
- `training_metrics.training_athlete_id`: coluna/FK adicionada pelo hardening.
- `training_age_group.treino_id` e `training_age_group.age_group_id`: FKs presentes; unicidade do pivot presente.
- `provas.competicao_id`: FK presente na base.
- `competition_registrations.prova_id` e `competition_registrations.user_id`: FKs presentes na base.
- `results.prova_id` e `results.user_id`: FKs presentes na base.
- `team_results.competicao_id`: FK presente na base.
- Índice composto `results(user_id, prova_id)`: adicionado no hardening.
- Unicidade `competition_registrations(prova_id, user_id)`: adicionada no hardening.

### Não conformidades ou conformidade apenas por compatibilidade

#### Naming físico não corresponde estritamente ao alvo pedido
- O alvo de validação menciona colunas como:
  - `training_id`
  - `competition_id`
  - `season_id`
  - `date`
- O schema físico observado usa sobretudo:
  - `treino_id`
  - `competicao_id`
  - `epoca_id`
  - `data`

Isto significa que a conformidade é muitas vezes **por camada de compatibilidade/hardening**, e não por implementação física estritamente alinhada ao alvo pedido.

#### Índices compostos do alvo não estão todos materializados exatamente como pedidos
- O alvo pediu `trainings(season_id, date)`.
- O hardening tenta criar `idx_trainings_season_date` apenas se `season_id` e `date` existirem, mas o schema observado continua centrado em `epoca_id` e `data`.
- O alvo pediu `training_athletes(training_id, user_id)`.
- A garantia forte observada na base está em `training_athletes(treino_id, user_id)`; a variante inglesa depende da existência da coluna compatível.
- O alvo pediu `training_metrics(training_id, training_athlete_id)`.
- O hardening observado cria índices individuais em `training_id` e `training_athlete_id`, mas não ficou materializado de forma inequívoca o índice composto exatamente nessa combinação.

### Conclusão desta secção

O schema está **operacionalmente próximo do necessário**, mas **não corresponde de forma estrita e uniforme ao checklist canónico-alvo**. A validação é parcial, não total.

---

## 7. Validação da Qualidade das Queries

### Resultado

**Aceitável a boa, com alguns pontos frágeis.**

### Áreas boas

- `GetTrainingPoolDeckView.php`
  - fluxo canónico para treino/cais
- `GetTrainingDashboardSummary.php`
  - agregação orientada ao modelo canónico
- `GetCompetitionListSummary.php`
  - utiliza `withCount(...)` sem perder seleção base após correção anterior
- `GetCompetitionResultsView.php`
  - fluxo canónico de resultados
- `GetAthletePerformanceHistory.php`
  - alinhado com histórico de atleta e dados do modelo atual

### Áreas frágeis

- `app/Http/Controllers/DesportivoController.php` ainda concentra parte relevante de montagem de payloads e agregações web.
- O módulo beneficia claramente dos query services canónicos, mas o controlador principal continua a carregar peso arquitetural excessivo.
- O naming herdado (`presences`, etc.) torna a leitura e a manutenção menos claras do que o desejável.

### Conclusão desta secção

As queries principais do novo eixo canónico estão em estado **bom/aceitável**. O risco maior está na **hibridização do controlador web** e não em falhas graves das query services canónicas.

---

## 8. Falhas Críticas para Impedir Estado VALIDATED

Os seguintes pontos impedem classificar o módulo como **VALIDATED**:

1. `CreateTrainingAction` ainda cria `Event` associado ao criar `Training`.
2. `SyncTrainingToEventAction` continua a existir com escrita em `event_attendances`.
3. O frontend de competições ainda aponta visualização para `eventos.show`.
4. O separador de resultados mantém fallback para `mockTeamResults`.
5. Partes de performance/planeamento continuam dependentes de armazenamento local no frontend.
6. O schema físico ainda não é uniformemente estrito ao naming canónico pedido; muito do alinhamento depende de compatibilidade adicional.
7. `User` continua a expor relações legacy no mesmo agregado principal.

---

## 9. Conclusão Executiva

### Veredito

**PARTIALLY VALIDATED**

### Justificação resumida

O módulo Desportivo atual já usa a espinha dorsal canónica na maior parte dos fluxos importantes:

- atletas
- presenças de treino
- métricas de cais
- competições
- inscrições
- resultados individuais
- resultados coletivos no backend

Porém, **ainda não existe corte completo e estrito** em relação à camada de eventos/legacy, nem uniformidade total entre frontend, backend e schema físico. O estado atual é suficientemente maduro para ser considerado **quase consolidado**, mas **não ainda totalmente validado** segundo o critério rígido solicitado.

---

## 10. Próximas Ações Recomendadas

### Prioridade alta

1. Remover o acoplamento `Training -> Event` do fluxo de criação/edição.
2. Eliminar ou isolar definitivamente `SyncTrainingToEventAction` do domínio ativo.
3. Fechar o frontend de resultados coletivos para depender apenas de dados reais do backend.
4. Remover dependência de `eventos.show` no fluxo de competições.

### Prioridade média

1. Consolidar performance/planeamento sobre persistência canónica backend.
2. Uniformizar naming físico e índices para o alvo canónico sem depender de compatibilidade condicional.
3. Limpar relações legacy restantes em `User` e outros modelos de domínio principal.

---

## Classificação Final

**PARTIALLY VALIDATED**
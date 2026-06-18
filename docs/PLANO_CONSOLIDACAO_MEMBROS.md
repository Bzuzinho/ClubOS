# Plano Técnico de Consolidação Estrutural dos Dados do Membro (Sprint M2.0)

## Atualização M2.2.1 (2026-06-18)

Backfill real desbloqueado de forma controlada, mantendo `users` como fonte operacional:
- comando `php artisan members:backfill-data-structure` atualizado para dry-run por defeito e escrita real apenas com as 3 guardas obrigatórias: `--commit`, `--unlock-write` e `--confirm=BACKFILL_MEMBER_DATA`;
- opção `--allow-updates` adicionada e bloqueada nesta sprint com mensagem explícita: "Atualização de registos existentes ainda não está permitida nesta sprint.";
- opções `--chunk=100` e `--report-path=` adicionadas para processamento por lotes e geração de relatório JSON sem dados sensíveis completos;
- serviço `MemberDataMigrationService` passou a suportar commit real controlado (apenas criação de registos em falta), gravação de `migrated_from_users_at` e `migration_source_hash`, tratamento de erros por utilizador e resumo estruturado final;
- testes de feature expandidos para cobrir guardas, idempotência, não sobrescrita, `--user-id`, `--limit`, `--json`, `--report-path`, metadados de migração e auditoria pós-backfill.

Validação local/desenvolvimento executada nesta sprint:
- auditoria antes do commit real: `missing_dados_pessoais=83`, `missing_dados_configuracao=83`;
- commit real local executado com guardas explícitas e relatório em `storage/app/member-data-backfill-report.json`;
- auditoria após commit real: `users_with_dados_pessoais=83`, `users_with_dados_configuracao=83`, `missing_dados_pessoais=0`, `missing_dados_configuracao=0`, `conflicts_dados_pessoais=0`, `conflicts_dados_configuracao=0`.

Mantido por decisão da sprint:
- sem cutover de leitura;
- sem cutover de escrita de runtime;
- `users` continua fonte operacional;
- sem alterações em UI, controllers funcionais, imports, relações familiares, Financeiro e Desportivo;
- sem atualização de produção/servidor.

## Atualização M2.2 (2026-06-18)

Auditoria e simulação de backfill concluídas sem escrita real:
- criado serviço dedicado `app/Services/Members/MemberDataMigrationService.php` para mapear payloads, normalizar dados, calcular assinatura (`migration_source_hash`) e detetar conflitos/paridade;
- criado comando `php artisan members:audit-data-structure` com opções `--user-id`, `--limit` e `--json`;
- criado comando `php artisan members:backfill-data-structure` em dry-run por defeito com `--user-id`, `--limit` e `--json`;
- opção `--commit` permanece bloqueada nesta sprint e termina com código operacional de bloqueio sem escrever dados;
- adicionados testes dedicados para comandos e serviço em `tests/Feature/Membros/MemberDataBackfillCommandTest.php`.

Mantido por decisão da sprint:
- `users` continua fonte operacional (sem switch de leitura/escrita);
- sem backfill real em `dados_pessoais`/`dados_configuracao`;
- sem alterações de UI, controllers funcionais, imports de membros, relações familiares, Financeiro e Desportivo.

## Atualização M2.1 (2026-06-18)

Fundação estrutural concluída sem alteração funcional do runtime:
- criadas as tabelas `dados_pessoais` e `dados_configuracao` em relação `hasOne` com `users`;
- criados os models `DadosPessoais` e `DadosConfiguracao` com casts e relação `belongsTo(User)`;
- adicionadas relações `dadosPessoais()` e `dadosConfiguracao()` em `User`;
- adicionados testes de estrutura/relações (unique por `user_id`, cascade delete, casts e garantia de manutenção dos campos em `users`).

Mantido por decisão da sprint:
- `users` continua fonte operacional (sem switch de leitura/escrita);
- sem backfill;
- sem alterações em controllers, frontend, importação de membros e portal família;
- sem alterações em relações familiares (`user_guardian`, `user_relationships`, `familias`/`familia_user`);
- sem alterações em Financeiro e Desportivo.

## 1. Contexto

A Sprint M1 fechou o hardening operacional do módulo Membros/Famílias/EE (UX, tab Família, estados vazios, permissões visuais e clarificação funcional entre tipo de utilizador e perfil/permissões), mas manteve dívida estrutural de dados.

Esta Sprint M2.0 é exclusivamente de análise, desenho técnico e documentação.

Escopo explícito desta sprint:
- sem migrations;
- sem novos models;
- sem novos controllers;
- sem alteração de persistência;
- sem remoção de campos legacy;
- sem alteração da fonte de verdade em runtime;
- sem alteração funcional nos módulos Financeiro e Desportivo.

## 2. Estado Atual

### 2.1 Esquema atual de users (origem dos campos)

Migrations identificadas para users:
- database/migrations/0001_01_01_000000_create_users_table.php
- database/migrations/2026_01_29_163055_add_spark_fields_to_users_table.php
- database/migrations/2026_01_30_150000_extend_users_table_complete.php

### 2.2 Configuração atual no modelo User

No modelo app/Models/User.php existem:
- fillable extensivo (identidade, auth, pessoais, configuração, desportivo, financeiro legacy, relações legacy, ficheiros);
- casts com mistura de domínios (auth, pessoais, consentimentos, desportivo, financeiro);
- relações para módulos especializados já existentes (dadosFinanceiros, athleteSportsData, centrosCusto, userTypes, ageGroup, etc.);
- relações familiares em paralelo:
  - encarregados()/educandos() via user_guardian;
  - families()/familyMemberships() via familias/familia_user;
  - arrays legacy em users: encarregado_educacao e educandos.

### 2.3 Relações familiares e estruturas coexistentes

Tabelas/migrations existentes:
- database/migrations/2026_02_04_120000_create_user_guardian_pivot_table.php
- database/migrations/2026_02_01_024147_create_user_relationships_table.php
- database/migrations/2026_04_24_120000_create_familias_tables.php

## 3. Problemas Identificados

1. Sobrecarga estrutural em users:
- mistura de dados de autenticação, dados pessoais, dados de consentimento/configuração, dados desportivos, dados financeiros legacy e metadados de relações familiares.

2. Ausência de separação por bounded context para dados pessoais/configuração:
- inexistência de tabelas dedicadas dados_pessoais e dados_configuracao.

3. Múltiplas fontes para relações familiares:
- user_guardian (pivot);
- user_relationships (genérica);
- arrays legacy em users (encarregado_educacao/educandos);
- familias/familia_user (modelo mais rico para portal família).

4. Risco de inconsistência e acoplamento:
- controllers e frontend executam normalizações e reconciliações para manter superfícies consistentes entre fontes paralelas.

5. user_relationships com baixa tração operacional:
- CRUD existe em RelacoesMembroController, mas o fluxo principal de Membros/Família está centrado em user_guardian + familias/familia_user + fallback legacy.

## 4. Inventário de Campos users

Inventário consolidado de users (migrations + fillable/casts + uso observado).

### 4.1 Núcleo auth/identidade técnica
- id (uuid)
- name
- email
- email_verified_at
- password
- remember_token
- created_at
- updated_at

### 4.2 Identificação e perfil base do membro
- numero_socio
- nome_completo
- perfil
- estado
- tipo_membro (json)
- menor

### 4.3 Dados pessoais/contacto
- data_nascimento
- sexo
- morada
- codigo_postal
- localidade
- telefone
- telemovel
- contacto
- contacto_telefonico
- nif
- cc (e também numero_cartao_cidadao legado de migration)
- data_validade_cc (e também validade_cartao_cidadao legado de migration)
- numero_utente
- contacto_emergencia_nome
- contacto_emergencia_telefone
- contacto_emergencia_relacao
- foto_perfil
- nacionalidade
- estado_civil
- ocupacao
- empresa
- escola
- numero_irmaos
- email_secundario

### 4.4 Configuração/consentimentos/documentação
- rgpd
- consentimento
- afiliacao
- declaracao_de_transporte
- data_rgpd
- arquivo_rgpd
- data_consentimento
- arquivo_consentimento
- data_afiliacao
- arquivo_afiliacao
- declaracao_transporte (ficheiro/path)

### 4.5 Desportivo em users
- ativo_desportivo
- escalao (json)
- num_federacao
- cartao_federacao
- numero_pmb
- data_inscricao
- inscricao
- data_atestado_medico
- arquivo_atestado_medico
- informacoes_medicas

### 4.6 Financeiro legacy em users
- tipo_mensalidade
- conta_corrente
- centro_custo (json)

### 4.7 Relações familiares legacy em users
- encarregado_educacao (json)
- educandos (json)

### 4.8 Campos de autenticação paralela/legacy
- email_utilizador
- senha

## 5. Classificação Campo a Campo (Destino Futuro)

### A. Manter em users
- id, name, email, password, remember_token, email_verified_at, timestamps.
- estado (ativo/inativo/suspenso) como estado técnico-operacional de acesso.
- perfil mínimo de autorização (até convergência total com userTypes/policies).
- numero_socio como identificador funcional transversal (manter em users por efeito de lookup/login administrativo e referências cruzadas).
- nome_completo: manter temporariamente também em users por compatibilidade de superfícies; no alvo estrutural, fonte canónica em dados_pessoais com espelho controlado.

### B. Migrar para dados_pessoais
- nome_completo (fonte canónica alvo).
- data_nascimento, sexo.
- nif, cc, data_validade_cc, numero_utente.
- morada, codigo_postal, localidade.
- telefone, telemovel, contacto, contacto_telefonico.
- contacto_emergencia_nome, contacto_emergencia_telefone, contacto_emergencia_relacao.
- nacionalidade, estado_civil, ocupacao, empresa, escola, numero_irmaos.
- email_secundario.
- foto_perfil (ou ponte para tabela de media, manter path no curto prazo).
- tipo_membro se continuar a representar classificação funcional/pessoal e não apenas permissão.

### C. Migrar para dados_configuracao
- rgpd, consentimento, afiliacao, declaracao_de_transporte.
- data_rgpd, arquivo_rgpd.
- data_consentimento, arquivo_consentimento.
- data_afiliacao, arquivo_afiliacao.
- declaracao_transporte (path).
- email_utilizador e senha: classificar como legacy/auth paralela; recomendação é descontinuar quando estratégia de login estiver estabilizada para email canónico.

### D. Migrar para dados_desportivos / manter no módulo desportivo
- ativo_desportivo.
- escalao (referência deve convergir para athlete_sports_data/age_group_id e não array em users).
- num_federacao, cartao_federacao, numero_pmb.
- data_inscricao, inscricao.
- data_atestado_medico, arquivo_atestado_medico, informacoes_medicas.

### E. Migrar para dados_financeiros / manter no módulo financeiro
- tipo_mensalidade (já existe integração com dadosFinanceiros.mensalidade_id).
- conta_corrente (não usar em users como fonte operacional; ajuste por movimentos financeiros).
- centro_custo (pivot centro_custo_user deve ser principal; JSON em users como legado).

### F. Legacy / deprecado
- encarregado_educacao (array em users).
- educandos (array em users).
- campos duplicados migration/model (telefone/contacto_telefonico; cc/numero_cartao_cidadao; data_validade_cc/validade_cartao_cidadao).
- user_relationships como camada secundária até decisão formal.

## 6. Proposta dados_pessoais

### 6.1 Estrutura proposta (futura)
Tabela: dados_pessoais

Campos base:
- id (uuid, pk)
- user_id (uuid, unique, fk users.id, cascadeOnDelete)
- nome_completo (string 255, nullable no arranque, target non-null)
- data_nascimento (date, nullable)
- sexo (string 20, nullable)
- nif (string 30, nullable)
- cc (string 50, nullable)
- data_validade_cc (date, nullable)
- numero_utente (string 50, nullable)
- morada (text, nullable)
- codigo_postal (string 20, nullable)
- localidade (string 255, nullable)
- telefone (string 30, nullable)
- telemovel (string 30, nullable)
- contacto_preferencial (string 30, nullable)
- contacto_emergencia_nome (string 255, nullable)
- contacto_emergencia_telefone (string 30, nullable)
- contacto_emergencia_relacao (string 100, nullable)
- nacionalidade (string 120, nullable)
- estado_civil (string 80, nullable)
- ocupacao (string 255, nullable)
- empresa (string 255, nullable)
- escola (string 255, nullable)
- numero_irmaos (unsigned smallint, nullable)
- email_secundario (string 255, nullable)
- foto_perfil_path (string 500, nullable)
- created_at, updated_at

### 6.2 Índices recomendados
- unique(user_id)
- index(nif)
- index(nome_completo)
- index(telemovel)
- index(email_secundario)

### 6.3 Relação Eloquent alvo
- User hasOne DadosPessoais
- DadosPessoais belongsTo User
- Estratégia: hasOne por member profile.

## 7. Proposta dados_configuracao

### 7.1 Estrutura proposta (futura)
Tabela: dados_configuracao

Campos base:
- id (uuid, pk)
- user_id (uuid, unique, fk users.id, cascadeOnDelete)
- rgpd (boolean, default false)
- consentimento (boolean, default false)
- afiliacao (boolean, default false)
- declaracao_de_transporte (boolean, default false)
- data_rgpd (date, nullable)
- arquivo_rgpd_path (string 500, nullable)
- data_consentimento (date, nullable)
- arquivo_consentimento_path (string 500, nullable)
- data_afiliacao (date, nullable)
- arquivo_afiliacao_path (string 500, nullable)
- declaracao_transporte_path (string 500, nullable)
- flags_configuracao (json, nullable)
- origem_migracao (string 50, nullable)
- migrated_at (timestamp, nullable)
- created_at, updated_at

### 7.2 Índices recomendados
- unique(user_id)
- index(rgpd)
- index(consentimento)
- index(afiliacao)
- index(declaracao_de_transporte)
- index(migrated_at)

### 7.3 Relação Eloquent alvo
- User hasOne DadosConfiguracao
- DadosConfiguracao belongsTo User
- Estratégia: hasOne por member configuration profile.

## 8. Proposta Relações Familiares

### 8.1 Mapeamento operacional atual (criar/ler/atualizar/apagar)

#### user_guardian
- Criada/atualizada em:
  - MembrosController (syncGuardianRelations/syncEducandoRelations e replace*PivotRows)
  - FamilyPortalController::storeMember via syncLegacyGuardianLinks (updateOrInsert)
- Lida em:
  - relações User::encarregados()/educandos()
  - FamilyService fallback legacy
  - DashboardController, PortalProfileController, MembrosController
- Apagada/replace em:
  - MembrosController (replace*PivotRows + destroy detach)
- UI dependente:
  - ficha Membros (PersonalTab/Show)
  - dashboard atleta/família
  - portal profile/family
- Testes:
  - múltiplos testes Feature (Membros, Portal, Dashboard, Integration).

#### user_relationships
- Criada/atualizada em:
  - RelacoesMembroController::store
- Lida em:
  - RelacoesMembroController::index
  - MembrosController load relationships.relatedUser (superfície secundária)
- Apagada em:
  - RelacoesMembroController::destroy
- UI dependente:
  - sem evidência de UI principal a usar como fonte canónica de família.
- Testes:
  - cobertura específica baixa no fluxo principal.

#### familias + familia_user
- Criada/atualizada em:
  - FamilyService::ensureFamilyForManager
  - FamilyPortalController::storeMember (attach com papel/permissões)
- Lida em:
  - FamilyService::actualFamiliesForUser/familiesForPortal/familySummary
  - FamilyPortalController::show
  - dependências de leitura financeira por family_id
- Apagada:
  - por cascade de membership/família ou operações explícitas futuras (não predominante na sprint atual)
- UI dependente:
  - Portal Family (principal)
  - contexto família na ficha Membros (FamilyTab)
- Testes:
  - cobertura em tests/Feature/Portal e tests/Feature/Dashboard e tests/Feature/Membros.

#### arrays legacy em users (encarregado_educacao, educandos)
- Criada/atualizada em:
  - MembrosController::syncGuardianRelations/syncEducandoRelations (persistRelationAttribute)
- Lida em:
  - MembrosController::show (fallback + reconcile)
  - Show.tsx / PersonalTab no frontend
  - FamilyService fallback legacy
- Apagada:
  - indiretamente por sync para array vazio
- UI dependente:
  - superfícies de edição de relações na ficha de membro.

### 8.2 Recomendação de fonte de verdade futura

Recomendação M2.x:
- Primária: familias/familia_user
  - já contém papel/permissões e adapta-se ao portal família e políticas de visibilidade.
- Secundária de transição (read-only progressivo): user_guardian
  - manter leitura durante transição para não quebrar dashboard/portal/ficha.
- Legacy a descontinuar:
  - arrays em users (encarregado_educacao/educandos).
- user_relationships:
  - manter apenas se houver caso de uso transversal não familiar; para família, não deve competir com familias/familia_user.

### 8.3 Plano para evitar duplicados
- Introduzir chave canónica relacional em familia_user (familia_id + user_id já única).
- Durante transição, jobs/comandos de auditoria para detetar divergência entre:
  - familia_user,
  - user_guardian,
  - arrays legacy.
- Definir write order única (primeiro canónico, depois sincronização temporária para legados).

## 9. Plano de Migração Faseado

### Sprint M2.1
- criar tabelas dados_pessoais e dados_configuracao;
- criar models e relações hasOne em User;
- sem mudança de UI.

Estado M2.1 nesta entrega: concluída (fundação estrutural criada, sem mudança funcional).

### Sprint M2.2
- comandos de auditoria de estrutura e simulação de backfill (dry-run);
- deteção de conflito/paridade com `migration_source_hash`;
- `--commit` bloqueado por segurança nesta sprint (sem escrita real).
- comandos artisan:
  - auditoria de consistência;
  - plano de migração em dry-run;
  - execução bloqueada sem --commit por default.

### Sprint M2.3
- mudar leituras para novas tabelas com fallback users legacy.

### Sprint M2.4
- mudar escritas para novas tabelas (users recebe apenas campos mínimos/transitórios).

### Sprint M2.5
- consolidação das relações familiares na fonte canónica definida (familias/familia_user), mantendo compatibilidade controlada.

### Sprint M2.6
- remover dependências legacy (arrays users e eventual camada secundária) apenas após validação e auditoria sem divergências.

## 10. Estratégia de Compatibilidade

1. Accessors temporários em User:
- expor interface estável para frontend/controllers enquanto dados migram para hasOne.

2. Fallback de leitura:
- ordem sugerida durante transição:
  - dados_pessoais/dados_configuracao -> users legacy.

3. Write-through temporário (se necessário):
- escritas canónicas no novo destino;
- sincronização controlada para legado durante janela de coexistência.

4. Flag de migração:
- usar campo técnico (ex.: migrated_at/origem_migracao) para distinguir estado por membro.

5. Observabilidade:
- logs e comandos de auditoria para divergência campo a campo.

## 11. Estratégia de Rollback

1. Rollback funcional por feature flag:
- reativar leitura principal em users se divergência crítica for detetada.

2. Rollback de dados:
- manter users intacto até fim de M2.6;
- sem drop de colunas legacy antes de validação final.

3. Rollback de relações familiares:
- manter capacidade de leitura user_guardian durante transição;
- não eliminar arrays legacy antes de 2 ciclos de validação sem drift.

4. Critérios de rollback:
- quebra de login;
- divergência de contexto familiar no portal;
- inconsistência de permissões por perfil;
- regressão em importação de membros.

## 12. Riscos

1. Login/autenticação:
- risco ao mover campos usados em auth (email/password). Estes devem permanecer em users.

2. Portal família:
- alto risco por coexistência de múltiplas fontes relacionais.

3. Financeiro (dependências de leitura):
- risco indireto em agregações por utilizador/família, principalmente em serviços de conta corrente e reconciliação.

4. Desportivo (dependências de leitura):
- risco em campos de escalão/estado desportivo ainda presentes em users.

5. Importação de membros:
- risco de drift se mapeamento continuar a escrever apenas users após criação de novas tabelas.

6. Permissões:
- risco se tipo_membro/perfil forem movidos sem contrato claro de autorização.

## 13. Testes Necessários

1. Regressão da ficha de membro:
- create/show/update com tabs Personal/Configuration/Family.

2. Portal família:
- visibilidade de educandos, associação de membro, resumo financeiro agregado.

3. Financeiro dependente de user:
- leituras de CurrentAccountService por user_id/family_id.

4. Desportivo dependente de user:
- leitura de escalão/estado desportivo em dashboards/eventos.

5. Importação de membros:
- preview/import com mapeamento de campos migrados.

6. Permissões:
- matriz por perfil/tipo em modo admin e portal.

7. Auditoria de consistência (novos testes M2.2+):
- paridade users vs dados_pessoais/dados_configuracao;
- paridade familia_user vs user_guardian vs arrays legacy.

## 14. Sprints Futuras Propostas

- M2.1: fundação estrutural (tabelas/models/relações).
- M2.2: backfill + comandos de auditoria/migração (dry-run por defeito).
- M2.3: switch de leitura com fallback.
- M2.4: switch de escrita.
- M2.5: consolidação relacional familiar.
- M2.6: remoção controlada de legado após validação.

## 15. Decisões Pendentes

1. Aprovação da fonte canónica final de relações familiares:
- confirmar familias/familia_user como primária.

2. Papel de user_relationships:
- descontinuar para família ou manter apenas para relações genéricas não familiares.

3. Papel definitivo de tipo_membro/perfil:
- separar claramente classificação funcional vs autorização.

4. Estratégia para nome_completo:
- manter espelho em users por compatibilidade e prazo de remoção.

5. Sequência de migração de campos desportivos em users:
- convergência para athleteSportsData sem quebra de UI.

6. Critérios objetivos de corte de legado:
- janela mínima sem divergência e checklist de testes obrigatória antes de M2.6.

---

## Anexo A — Mapeamento de Leitura/Escrita Crítica (Resumo)

Backend analisado (principal):
- app/Http/Controllers/MembrosController.php
- app/Http/Controllers/FamilyPortalController.php
- app/Http/Controllers/PortalProfileController.php
- app/Http/Controllers/PortalPageController.php
- app/Http/Controllers/DashboardController.php
- app/Http/Controllers/MembrosImportController.php
- app/Services/Members/MemberImportService.php
- app/Services/Family/FamilyService.php
- app/Http/Controllers/RelacoesMembroController.php

Frontend analisado (principal):
- resources/js/Pages/Membros/Show.tsx
- resources/js/Pages/Membros/Create.tsx
- resources/js/Components/Members/Tabs/PersonalTab.tsx
- resources/js/Components/Members/Tabs/ConfigurationTab.tsx
- resources/js/Components/Members/Tabs/FamilyTab.tsx
- resources/js/Pages/Portal/Family.tsx
- resources/js/Pages/Portal/Profile.tsx
- resources/js/Pages/Dashboard/Atleta.tsx

Testes analisados (principal):
- tests/Feature/Membros/MembrosFamilyContextTabPayloadTest.php
- tests/Feature/Integration/MembrosUpdateEducandosTest.php
- tests/Feature/Portal/PortalFamilyCurrentAccountTest.php
- tests/Feature/Portal/PortalProfileFamilyAccessTest.php
- tests/Feature/Dashboard/DashboardEntryRoutingTest.php
- tests/Feature/Membros/MembrosCurrentAccountSurfaceTest.php

Exportações/importações:
- Importação de membros ativa: preview/store/template CSV em MembrosImportController + MemberImportService.
- Exportação específica de membros não identificada como fluxo dedicado nesta sprint.

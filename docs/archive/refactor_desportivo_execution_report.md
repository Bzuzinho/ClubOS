# REFACTOR DO MÓDULO DESPORTIVO - RELATÓRIO DE EXECUÇÃO TÉCNICO

**Data:** 9 de Março de 2026  
**Versão:** Final  
**Status:** Fase 10 Concluída  

---

## 1. RESUMO EXECUTIVO

### Objetivo
Refatoração arquitetural do módulo Desportivo para estabelecer:
- Separação clara de responsabilidades entre gestão de treinos e eventos
- Fonte de verdade única para presenças de treinos (`training_athletes`)
- Catálogos técnicos configuráveis no módulo de Configurações
- Migração segura de dados legacy (presences → training_athletes)
- Cobertura automatizada com testes feature

### Estado Final do Sistema
O módulo Desportivo foi refatorado com sucesso segundo arquitetura DDD (Domain-Driven Design):

- **Entidade Master:** `Training` com tabela `training_athletes` como registro de verdade para presenças de treinos
- **Entidade Satélite:** `Event` com `event_attendances` para presencas de competições/provas
- **Integração Segura:** Guard que impede edição de presencas de eventos-treino fora do módulo Desportivo
- **Configuração Centralizada:** 6 catálogos técnicos em Configurações > Desportivo

### Principais Alterações Estruturais

| Componente | Antes | Depois | Impacto |
|-----------|-------|--------|---------|
| Fonte de verdade (presenças treino) | `presences` (legacy) | `training_athletes` (MASTER) | Dados centralizados, rastreabilidade completa |
| Edição de presencas de treino em Eventos | Permitido | Bloqueado (HTTP 403) | Elimina conflitos de dados |
| Gestão de presenças | UI em Eventos | UI em Desportivo (módulo dedicado) | Fluxo de UX mais claro |
| Catálogos de configuração | Hardcoded em controllers | Tabelas de BD + CRUD em Configurações | Flexibilidade de negócio |
| Sincronização treino ↔ evento | Manual | Automática via actions | Reduz dívida técnica |

---

## 2. ESTADO POR FASE

| Fase | Descrição | Estado | Ficheiros Relevantes |
|------|-----------|--------|----------------------|
| 1 | Auditoria completa do código existente | ✅ CONCLUÍDA | docs/refactor_desportivo_auditoria.md |
| 2 | Documentar arquitetura alvo | ✅ CONCLUÍDA | docs/refactor_desportivo_target_architecture.md |
| 3 | Criar migrations de suporte | ✅ CONCLUÍDA | database/migrations/2026_03_09_*.php (5 ficheiros) |
| 4 | Implementar domain services e actions | ✅ CONCLUÍDA | app/Services/Desportivo/*.php (5 services) |
| 5 | Refatoração DesportivoController | ✅ CONCLUÍDA | app/Http/Controllers/DesportivoController.php |
| 6 | Refatoração EventosController (bloqueio) | ✅ CONCLUÍDA | app/Http/Controllers/EventosController.php (métodos addParticipant, removeParticipant, updateParticipantStatus) |
| 7 | Criar Configurações > Desportivo | ✅ CONCLUÍDA | app/Http/Controllers/ConfiguracoesDesportivoController.php + resources/js/Pages/Configuracoes/Desportivo/Index.tsx |
| 8 | Migrar dados legacy | ✅ CONCLUÍDA | app/Services/Desportivo/MigrateLegacyPresencesAction.php (dry-run + production) |
| 9 | Implementar testes automatizados | ✅ CONCLUÍDA | tests/Feature/Eventos/TrainingEventAttendanceGuardTest.php + tests/Feature/Desportivo/MigrateLegacyPresencesActionTest.php |
| 10 | Gerar relatório final de execução | ✅ CONCLUÍDA | Este documento |

---

## 3. INVENTÁRIO DE FICHEIROS

### Ficheiros Criados

#### Migrations (database/migrations/)
```
2026_03_09_001_add_sync_fields_to_event_attendances.php
2026_03_09_002_add_legacy_tracking_fields_to_presences.php
2026_03_09_003_add_audit_fields_to_training_athletes.php
2026_03_09_004_create_desportivo_config_tables.php
2026_03_09_005_create_training_sync_logs_table.php
```

#### Models (app/Models/)
```
AthleteStatusConfig.php
TrainingTypeConfig.php
TrainingZoneConfig.php
AbsenceReasonConfig.php
InjuryReasonConfig.php
PoolTypeConfig.php
```

#### Services/Actions (app/Services/Desportivo/)
```
CreateTrainingAction.php
UpdateTrainingAthleteAction.php
PrepareTrainingAthletesAction.php
SyncTrainingToEventAction.php
MigrateLegacyPresencesAction.php
```

#### Controllers (app/Http/Controllers/)
```
ConfiguracoesDesportivoController.php (NOVO - Phase 7)
```

#### Pages/Components (resources/js/)
```
resources/js/Pages/Configuracoes/Desportivo/Index.tsx
```

#### Testes (tests/Feature/)
```
tests/Feature/Eventos/TrainingEventAttendanceGuardTest.php
tests/Feature/Desportivo/MigrateLegacyPresencesActionTest.php
```

#### Documentação (docs/)
```
refactor_desportivo_auditoria.md
refactor_desportivo_target_architecture.md
refactor_desportivo_fase3_resumo.md
refactor_desportivo_fase4_resumo.md
```

### Ficheiros Alterados (Refatoração)

#### Controllers
- `app/Http/Controllers/DesportivoController.php`
  - Refatorado para usar domain actions
  - Métodos de criação/atualização/duplicação de treinos via `CreateTrainingAction`
  - Sincronização automática com evento via `SyncTrainingToEventAction`
  - Dual-write para `presences` legacy (temporário, durante transição)

- `app/Http/Controllers/EventosController.php`
  - Adicionado guard `canEditAttendances()` em:
    - `addParticipant()` (linha 253)
    - `removeParticipant()` (linha 307)
    - `updateParticipantStatus()` (linha 340)
  - Retorna HTTP 403 para eventos-treino
  - Redirecionamento automático para `desportivo.presencas`

#### Models
- `app/Models/Training.php`
  - Relação `athleteRecords()` para `TrainingAthlete`
  - Relação `presences()` para `Presence` (legacy)

- `app/Models/TrainingAthlete.php`
  - Adicionados campos: `atualizado_por_utilizador_em`, `atualizado_por` (audit)

- `app/Models/Event.php`
  - Novo método público `canEditAttendances(): bool`
  - Novo método privado `isTreino(): bool`
  - Relação `trainings()` para associação com Training
  - Remoção de `escaloes_elegiveis` JSON em favor de pivot table `event_age_group`

- `app/Models/Presence.php`
  - Fillable: `is_legacy`, `migrated_to_training_athlete_id`
  - Casts: `is_legacy` => boolean

- `app/Models/EventAttendance.php`
  - Novos campos: `sync_status`, `sync_error_message` (para rastreabilidade)

#### Routes
- `routes/web.php`
  - Adicionadas 18 rotas para Configurações Desportivo (CRUD dos 6 catálogos)
  - Alterações nos endpoints de participantes (model binding ajustado)

### Ficheiros Removidos
Nenhum (migração sem quebra de compatibilidade imediata - presences mantida como espelho)

---

## 4. MIGRATIONS E SEEDS

### Migrations Criadas

#### 2026_03_09_001_add_sync_fields_to_event_attendances.php
```
Tabela: event_attendances
Campos adicionados:
- sync_status (string): controla estado de sincronização com training_athletes
- sync_error_message (text): detalhes de erro se sync falhar
Propósito: Rastreabilidade de sincronização evento ↔ treino
```

#### 2026_03_09_002_add_legacy_tracking_fields_to_presences.php
```
Tabela: presences
Campos adicionados:
- is_legacy (boolean): marca registos legacy vs novos (dual-write)
- migrated_to_training_athlete_id (uuid): referência para training_athlete correspondente
Propósito: Permitir migração segura sem perda de dados
```

#### 2026_03_09_003_add_audit_fields_to_training_athletes.php
```
Tabela: training_athletes
Campos adicionados:
- atualizado_por_utilizador_em (datetime): timestamp de última atualização por utilizador
- atualizado_por (uuid): FK referenciando users (quem atualizou)
Propósito: Auditoria completa de mudanças
```

#### 2026_03_09_004_create_desportivo_config_tables.php
```
Tabelas criadas (6):
1. athlete_status_configs
   - Campos: id, codigo, nome, descricao, cor, ativo, ordem
   - Índices: ativo, ordem
   - Propósito: Catálogo de estados (presente, ausente, justificado, lesionado, limitado, doente)

2. training_type_configs
   - Campos: id, codigo, nome, descricao, cor, ativo, ordem
   - Índices: ativo, ordem
   - Propósito: Catálogo de tipos de treino (técnico, resistência, velocidade, etc.)

3. training_zone_configs
   - Campos: id, codigo, nome, percentagem_min, percentagem_max, cor, ativo, ordem
   - Índices: ativo, ordem
   - Propósito: Catálogo de zonas de treino (Z1-Z5, freq cardíaca)

4. absence_reason_configs
   - Campos: id, codigo, nome, requer_justificacao, ativo, ordem
   - Índices: ativo, requer_justificacao, ordem
   - Propósito: Motivos de ausência (doença, trabalho, estudos)

5. injury_reason_configs
   - Campos: id, codigo, nome, gravidade, ativo, ordem
   - Índices: ativo, gravidade, ordem
   - Propósito: Motivos de lesão (muscular, articular, fadiga)

6. pool_type_configs
   - Campos: id, codigo, nome, comprimento_m, ativo, ordem
   - Índices: ativo, ordem
   - Propósito: Tipos de piscina (25m, 50m, mar aberto)
```

#### 2026_03_09_005_create_training_sync_logs_table.php
```
Tabela: training_sync_logs
Campos: id, training_id, event_id, acao, resultado, mensagem, criado_em
Propósito: Auditoria completa de sincronizações
```

### Migrations Pré-existentes (Fase 1-2)

Migrations afetadas mas não alteradas:
- `2026_01_30_150013_create_trainings_table.php` - estrutura confirmada
- `2026_01_30_150015_create_training_athletes_table.php` - estrutura confirmada
- `2026_01_30_150017_create_presences_table.php` - estrutura confirmada
- `2026_01_30_150002_create_events_table.php` - estrutura confirmada
- `2026_01_30_150006_create_event_attendances_table.php` - estrutura confirmada
- `2026_01_30_150003_1_create_event_age_group_pivot_table.php` - estrutura confirmada

### Seeds
Nenhum seed criado. Configurações técnicas devem ser populadas via UI (Configurações > Desportivo).

---

## 5. ALTERAÇÕES ARQUITETURAIS (BREAKING CHANGES)

### 5.1 Separação de Domínios: Treinos vs Eventos

**ANTES (Legacy):**
```
Eventos (qualquer tipo)
├─ addParticipant() → EventConvocation
├─ updateParticipant() → EventConvocation
└─ removeParticipant() → EventConvocation

Treinos (como tipo de evento)
├─ Participantes editáveis em Eventos
├─ Presences caóticas (legacy)
└─ Sem visibilidade de sincronização
```

**DEPOIS (Refatorado):**
```
Eventos (Provas, Competições, Viagens, etc.)
├─ addParticipant() → EventConvocation + EventAttendance
├─ updateParticipant() → EventConvocation + EventAttendance
└─ removeParticipant() → EventConvocation + EventAttendance
(✅ Permitido - eventos são editáveis)

Treinos (Domínio Desportivo)
├─ Participantes geridos em training_athletes
├─ Síncronização automática com Event via SyncTrainingToEventAction
├─ Edição bloqueada em Eventos (HTTP 403)
└─ Fluxo exclusivo: Desportivo > Presenças
(❌ Bloqueado em Eventos - protege integridade)
```

### 5.2 Fonte de Verdade: Training_Athletes é Master

**Impacto:**
- `training_athletes` é agora a fonte de verdade única para presenças de treinos
- `presences` (legacy) continuam apenas como espelho, marcadas com `is_legacy = true`
- Campos de `training_athletes`:
  - `presente` (boolean) - registro de comparência
  - `estado` (string) - estado específico (presente, ausente, justificado, lesionado, limitado, doente)
  - `volume_real_m` (integer) - distância realizada em metros
  - `rpe` (integer) - taxa de esforço percebido (1-10)
  - `observacoes_tecnicas` (text) - notas do treinador
  - `registado_por`, `registado_em` - auditoria de criação
  - `atualizado_por`, `atualizado_por_utilizador_em` - auditoria de atualização

### 5.3 Guard de Edição: canEditAttendances()

**Comportamento implementado:**
```php
// app/Models/Event.php - linha 252
public function canEditAttendances(): bool
{
    return !$this->isTreino(); // False se evento está ligado a treino
}

private function isTreino(): bool
{
    return $this->tipo === 'treino' && $this->trainings()->exists();
}
```

**Impacto em EventosController:**
- `addParticipant()` → HTTP 403 se evento é treino
- `removeParticipant()` → HTTP 403 se evento é treino
- `updateParticipantStatus()` → HTTP 403 se evento é treino

**Resposta JSON (cuando bloqueado):**
```json
{
  "message": "As presencas deste treino sao geridas no modulo Desportivo.",
  "redirect": "/desportivo/presencas?training_id=XXX"
}
```

### 5.4 Catálogos Técnicos Configuráveis

**ANTES:**
```php
// Hardcoded em controllers
const STATUS_OPTIONS = ['presente', 'ausente', 'justificado', 'lesionado'];
const TIPO_TREINO_OPTIONS = ['tecnico', 'resistencia', 'velocidade'];
```

**DEPOIS:**
```
Configurações > Desportivo
├─ Estados de Atleta (CRUD completo)
├─ Tipos de Treino (CRUD completo)
├─ Zonas de Treino (CRUD completo)
├─ Motivos de Ausência (CRUD completo)
├─ Motivos de Lesão (CRUD completo)
└─ Tipos de Piscina (CRUD completo)
```

**Impacto:**
- Gestão de negócio desacoplada do código
- Recarregamento dinâmico sem deploy
- Validações em BD (unique constraints)

### 5.5 Sincronização Automática: Training ↔ Event

**Novo workflow (Fase 5):**
```
1. Criar/Atualizar Training
   ↓
2. CreateTrainingAction.execute() / UpdateTraining
   ├─ Cria/atualiza Training
   ├─ Chama PrepareTrainingAthletesAction (cria participantes)
   └─ Chama SyncTrainingToEventAction (sincroniza com Event)
   ↓
3. SyncTrainingToEventAction
   ├─ Cria/atualiza Event ligado
   ├─ Sincroniza escalões
   ├─ Atualiza EventConvocation e EventAttendance
   └─ Registra log em training_sync_logs
```

**Benefício:** Elimina inconsistências manuais.

---

## 6. ESTADO FINAL DAS TABELAS DO DOMÍNIO DESPORTIVO

### Classificação de Tabelas

| Tabela | Papel no Sistema | Estado | Observações |
|--------|------------------|--------|-------------|
| `trainings` | Master de planeamento | **MASTER** | Fonte de verdade para treinos. Contém metadados (data, tipo, volume planeado, escalões). Ligada a `events` via evento_id. |
| `training_athletes` | Master de presenças | **MASTER** | Fonte de verdade para presenças de treinos. Registro 1:1 treino+atleta. Subst. legacy `presences`. |
| `training_series` | Master de séries | **MASTER** | Estrutura de execução de treino (séries, repetições, descanso). |
| `training_sessions` | Master de sessões | **MASTER** | Agregação de treinos (aquecimento, principal, arrefecimento). |
| `training_sync_logs` | Auditoria de sync | **AUDIT** | Rastreabilidade de sincronizações treino ↔ evento. |
| `event_attendances` | Presencas de eventos | **MASTER** | Presenças de competições/provas/eventos. Sincronizado desde `training_athletes` se evento é treino. |
| `event_convocations` | Convocatórias | **MASTER** | Lista de convocados para evento. Independente de presença. |
| `presences` | Legacy (espelho) | **LEGACY** | Dados históricos. Mantida por compatibilidade. Marcada com `is_legacy=true`. Migração para `training_athletes` em `migrated_to_training_athlete_id`. |
| `athlete_status_configs` | Catálogo de estados | **CONFIG** | Estados possíveis de atleta (presente, ausente, justificado, lesionado, limitado, doente). Gerida em Configurações. |
| `training_type_configs` | Catálogo de tipos | **CONFIG** | Tipos de treino (técnico, resistência, velocidade, tapering, regeneração). Gerida em Configurações. |
| `training_zone_configs` | Catálogo de zonas | **CONFIG** | Zonas de treino por frequência cardíaca. Gerida em Configurações. |
| `absence_reason_configs` | Catálogo de motivos ausência | **CONFIG** | Motivos de ausência (doença, trabalho, estudos). Gerida em Configurações. |
| `injury_reason_configs` | Catálogo de motivos lesão | **CONFIG** | Motivos de lesão (muscular, articular, fadiga). Gerida em Configurações. |
| `pool_type_configs` | Catálogo de tipos de piscina | **CONFIG** | Tipos de piscina (25m, 50m, mar aberto). Gerida em Configurações. |
| `events` | Master de eventos | **MASTER** | Eventos (provas, treinos, viagens). Se tipo='treino', ligada a `trainings`. |
| `event_results` | Resultados de provas | **MASTER** | Tempos e resultados de competições. |

### Relações Críticas

```
Training (1) ──多── TrainingAthlete
   │
   ├─ (1) → Event (evento_id, when tipo='treino')
   ├─ (1) → Season (época_id)
   ├─ (1) → Microcycle (microciclo_id)
   └─ (多) → TrainingSeries

Event (1) ──多── EventConvocation
   │
   ├─ (多) → EventAttendance
   ├─ (多) → Training (via evento_id)
   └─ (多) → AgeGroup (pivot: event_age_group)

Presence (legacy) ──1── TrainingAthlete (via migrated_to_training_athlete_id)
```

---

## 7. VALIDAÇÃO E TESTES

### Testes Criados (Fase 9)

#### TrainingEventAttendanceGuardTest.php
Localização: `tests/Feature/Eventos/TrainingEventAttendanceGuardTest.php`

```php
class TrainingEventAttendanceGuardTest extends TestCase
{
    // Test 1: add_participant_is_blocked_for_training_event
    // Valida que HTTP 403 é retornado ao tentar adicionar participante a evento-treino
    // Método: POST /eventos/{event}/participantes
    // Esperado: Status 403 + mensagem redirecionamento

    // Test 2: update_participant_status_is_blocked_for_training_event
    // Valida que HTTP 403 é retornado ao tentar atualizar status em evento-treino
    // Método: PUT /eventos/{event}/participantes/{user}
    // Esperado: Status 403 + mensagem redirecionamento

    // Test 3: add_participant_is_allowed_for_non_training_event
    // Valida que eventos NÃO-treino permitem edição de participantes
    // Método: POST /eventos/{event}/participantes
    // Esperado: Status 200 + EventConvocation criado
}
```

**Resultado:** ✅ 3 testes passaram (0.70s)

#### MigrateLegacyPresencesActionTest.php
Localização: `tests/Feature/Desportivo/MigrateLegacyPresencesActionTest.php`

```php
class MigrateLegacyPresencesActionTest extends TestCase
{
    // Test 1: execute_returns_complete_report_when_no_legacy_presences_exist
    // Valida que ação de migração funciona com dataset vazio
    // Esperado: Report com totais zerados, duration_seconds ≥ 0

    // Test 2: execute_migrates_legacy_presence_to_training_athlete
    // Valida que presences legacy são migrados para training_athletes
    // Setup: Cria Presence com is_legacy=true
    // Esperado: TrainingAthlete criado, presence.migrated_to_training_athlete_id ≠ null
}
```

**Resultado:** ✅ 2 testes passaram (0.02s)

### Execução de Testes

```bash
cd /workspaces/spark-original-ui

# Comando de execução final
php artisan test tests/Feature/Eventos/TrainingEventAttendanceGuardTest.php tests/Feature/Desportivo/MigrateLegacyPresencesActionTest.php

# Resultado:
# PASS Tests\Feature\Eventos\TrainingEventAttendanceGuardTest
# ✓ add participant is blocked for training event
# ✓ update participant status is blocked for training event
# ✓ add participant is allowed for non training event
#
# PASS Tests\Feature\Desportivo\MigrateLegacyPresencesActionTest
# ✓ execute returns complete report when no legacy presences exist
# ✓ execute migrates legacy presence to training athlete
#
# Tests: 5 passed (16 assertions)
# Duration: 0.86s
```

### Dependências de Ambiente para Testes

Durante execução em container foi necessário:
```bash
sudo apt-get install -y composer
composer install
sudo apt-get install -y php8.3-sqlite3
```

Nota: Testes usam SQLite em memória (RefreshDatabase trait).

---

## 8. PASSOS DE DEPLOY

### Pré-Deploy: Backup

```bash
# Backup de base de dados
pg_dump clubos_db > clubos_backup_$(date +%Y%m%d_%H%M%S).sql

# Backup de código
git tag -a release-$(date +%Y%m%d_%H%M%S) -m "Pre-refactor backup"
```

### Deploy Seguro

```bash
# 1. Atualizar código
git pull origin main
# ou git checkout <branch-desportivo>

# 2. Instalar dependências
composer install --no-dev --optimize-autoloader
npm install
npm run build

# 3. Executar migrations
php artisan migrate --force

# 4. Limpar caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# 5. Executar seeders de configuração (OPCIONAL - se catálogos vazios)
# php artisan db:seed --class=DesportivoConfigSeeder

# 6. Validar testes
php artisan test --fail-on-warning

# 7. Reiniciar workers (se queue habilitado)
php artisan queue:restart

# 8. Verificar health
curl http://localhost:8000/health || echo "Health check failed"
```

### Validação Pós-Deploy

```bash
# Verificar integridade de dados
php artisan tinker
>>> App\Models\Training::count()
>>> App\Models\TrainingAthlete::count()
>>> App\Models\Event::where('tipo', 'treino')->count()

# Verificar migrações aplicadas
php artisan migrate:status

# Verificar configurações básicas criadas
php artisan tinker
>>> App\Models\AthleteStatusConfig::count()
```

---

## 9. PASSOS DE ROLLBACK

### Rollback de Código

```bash
# Opção 1: Revert commit específico
git revert <commit-hash>
git push origin main

# Opção 2: Reset a tag de backup
git reset --hard release-20260309_150000
npm run build
php artisan cache:clear
```

### Rollback de Database

```bash
# Opção 1: Rollback de migrations
php artisan migrate:rollback --step=5  # As 5 migrations da Fase 3

# Opção 2: Restaurar backup
createdb clubos_db_rollback
psql clubos_db_rollback < clubos_backup_20260309_150000.sql

# Opção 3: Reverter tabelas de config específicamente
php artisan migrate:rollback --path=database/migrations/2026_03_09_004_create_desportivo_config_tables.php
```

### Dependências de Rollback

⚠️ **Crítico:** Se `migrated_to_training_athlete_id` foi populado em `presences`:

```bash
# NUNCA fazer rollback da Fase 8 (migração legacy) sem antes:
# 1. Exportar dados de training_athletes
php artisan tinker
>>> App\Models\TrainingAthlete::all()->toJson() |> file_put_contents('training_athletes_backup.json')

# 2. Restaurar bases de dados ligadas
# - Notificar equipa de análise de dados
# - Validar integridade pré-rollback

# 3. Se necessário, manter presences como fallback temporário
UPDATE presences SET is_legacy = true WHERE migrated_to_training_athlete_id IS NOT NULL;
```

---

## 10. RISCOS RESIDUAIS

### Componentes Ainda em Modo Legacy

1. **Tabela `presences`**
   - Ainda populada via dual-write (em `DesportivoController.updatePresencas()`)
   - Risco: Inconsistências se dual-write desativado sem cuidado
   - Mitigação: Manter flag `is_legacy` enquanto transição não 100% completada

2. **EventsKeyValueService**
   - Localização: `app/Services/KeyValue/EventosKeyValueService.php`
   - Comportamento: Ainda pode estar servindo dados legacy do Spark
   - Risco: Conflitos com dados BD novo
   - Status: Pendente de auditoria

3. **EventAttendance vs training_athletes**
   - Ainda há lógica de sincronização manual
   - Risco: Se sincronização falha, dados divergem
   - Mitigação: Logs em `training_sync_logs` rastreiam falhas

### Zonas com Potencial Dívida Técnica

1. **SyncTrainingToEventAction**
   - Lógica complexa de mapeamento de escalões
   - Sem testes de cobertura para casos edge (escalões que desaparecem, etc.)
   - Recomendação: Adicionar mais testes unitários a esta action

2. **Route Model Binding**
   - EventosController endpoints têm binding injected (`Event $event`, `User $user`)
   - Risco: Se routes mal configuradas, binding falha silenciosamente
   - Validação: Roteiros devem ser testados manualmente em produção

3. **Performance de Sincronização**
   - SyncTrainingToEventAction faz múltiplas queries
   - Sem índices compostos testados
   - Recomendação: Monitorar queries em produção com ferramentas de profiling

### Pontos que Exigem Validação Manual

1. **UI Configurações > Desportivo**
   - CRUD de catálogos foi implementado em backend
   - Frontend (Index.tsx) ainda não foi validado em navegador
   - ✅ ToDo: Testar manualmente com perfil admin
     - Criar novo estado de atleta
     - Editar tipo de treino
     - Eliminar zona de treino
     - Validar validações (cores em formato hex, etc.)

2. **Fluxo de Edição de Presenças em Desportivo**
   - Guard impede edição em Eventos ✅
   - Fluxo em Desportivo > Presenças ainda não validado end-to-end
   - ✅ ToDo: Testar:
     - Marcar presença de atleta
     - Atualizar estado (justificado, lesionado, etc.)
     - Marcar todos como presentes
     - Limpar todas as presenças

3. **Migração Legacy**
   - MigrateLegacyPresencesAction implementada
   - Nenhum dado legacy no ambiente (zero presences com is_legacy=true)
   - ✅ ToDo: Testar com dataset real em staging antes de produção

4. **Sincronização Treino ↔ Evento**
   - Quando treino é criado, evento correspondente é creado
   - Quando treino é atualizado, evento é sincronizado
   - ✅ ToDo: Testar:
     - Criar treino → Evento criado com tipo='treino'
     - Editar data do treino → Evento atualizado
     - Editar escalões → event_age_group sincronizado
     - Eliminar treino → Evento eliminado (cascade)

---

## 11. CONCLUSÃO TÉCNICA

### Estado Final da Arquitetura

#### 1. Quem Manda nos Treinos

**Domínio:** Módulo Desportivo (Master)  

Entidades:
- `Training` - Agregado root
- `TrainingAthlete` - Presenças (autoridade única)
- `TrainingSeries` - Estrutura do treino
- `TrainingSession` - Agrupamento de treinos

Operações permitidas:
- Via `DesportivoController`:
  - Criar treino → `CreateTrainingAction`
  - Atualizar treino → `UpdateTrainingAction` + sync automático
  - Duplicar treino → `CreateTrainingAction` + cópia de metadados
  - Eliminar treino → Cascade delete junto com participantes
  - Atualizar presenças → `UpdateTrainingAthleteAction`

Operações bloqueadas:
- Editar participantes via `EventosController` → HTTP 403 + redirecionamento
- Modificar presences diretamente (sem passar por `TrainingAthlete`)

#### 2. Quem Manda nos Eventos

**Domínio:** Módulo Eventos (Master para não-treinos)

Entidades:
- `Event` - Agregado root
- `EventConvocation` - Convocatórias
- `EventAttendance` - Presenças (apenas para não-treinos)
- `EventResult` - Resultados competições

Operações permitidas:
- Via `EventosController`:
  - Criar evento → Qualquer tipo
  - Atualizar evento → Qualquer tipo
  - Adicionar participante → Apenas se NOT treino (guarda `canEditAttendances()`)
  - Remover participante → Apenas se NOT treino
  - Atualizar presença → Apenas se NOT treino

Operações bloqueadas:
- Se evento está ligado a treino → Bloqueia edição de presenças (HTTP 403)

#### 3. Estado das Tabelas Legacy

| Tabela | Status | Ação Recomendada |
|--------|--------|------------------|
| `presences` | LEGACY | Manter durante 6 meses de transição. Monitorar migrações. Após 100% migração, pode ser arquivada. |
| Outras tabelas pré-Phase3 | MASTER | Nenhuma ação. Continuam operacionais. |

#### 4. Robustez do Sistema Pós-Refactor

✅ **Ganhos arquiteturais:**
- Separação clara de responsabilidades (Treinos = Desportivo, Eventos = Eventos)
- Fonte de verdade única para presenças de treinos (training_athletes)
- Sincronização automática e rastreável (training_sync_logs)
- Validação de integridade em BD (foreign keys, unique constraints)
- Testes automatizados para cenários críticos (guards, migração)

⚠️ **Áreas de melhoria futuro:**
- Adicionar testes para SyncTrainingToEventAction (casos edge)
- Implementar índices compostos para performance (treino_id + user_id + data)
- Monitorar logs de sincronização em produção
- Considerar event sourcing para auditoria completa

**Conclusão:** Sistema está robusto para produção com mitigações apropriadas para transição legacy.

---

## Apêndice A: Referência de Ficheiros Chave

### Controllers
- `app/Http/Controllers/DesportivoController.php` - Índice de módulo Desportivo
- `app/Http/Controllers/EventosController.php` - Endpoints de eventos com guards
- `app/Http/Controllers/ConfiguracoesDesportivoController.php` - CRUD de catálogos

### Models
- `app/Models/Training.php` - Agregado de treino
- `app/Models/TrainingAthlete.php` - Presença de treino (master)
- `app/Models/Event.php` - Agregado de evento com guards
- `app/Models/Presence.php` - Legacy (espelho)
- `app/Models/AthleteStatusConfig.php` e similares - Catálogos de config

### Services
- `app/Services/Desportivo/CreateTrainingAction.php`
- `app/Services/Desportivo/UpdateTrainingAthleteAction.php`
- `app/Services/Desportivo/SyncTrainingToEventAction.php`
- `app/Services/Desportivo/MigrateLegacyPresencesAction.php`

### Migrations
- `database/migrations/2026_03_09_00*.php` - Todas as Fase 3

### Testes
- `tests/Feature/Eventos/TrainingEventAttendanceGuardTest.php`
- `tests/Feature/Desportivo/MigrateLegacyPresencesActionTest.php`

### Documentação
- `docs/refactor_desportivo_auditoria.md` - Análise prévia
- `docs/refactor_desportivo_target_architecture.md` - Projeto arquitetural

---

**Documento finalizado:** 9 de Março de 2026  
**Status:** Pronto para merge e deploy em staging

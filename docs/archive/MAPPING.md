# Spark → Laravel 11 Mapping

## 📊 OVERVIEW
- **Spark Original**: React SPA com useKV (key-value persistence)
- **Laravel Target**: Laravel 11 + Inertia React + PostgreSQL
- **Strategy**: Preservar UI/UX, migrar lógica para backend

---

## 🗂️ PÁGINAS/VIEWS

| Spark View | Spark Route | Laravel Route | Inertia Page | Controller | Status |
|------------|-------------|---------------|--------------|------------|--------|
| LoginView | / (not auth) | /login | Auth/Login.tsx | Breeze | ✅ Done |
| - | /register | /register | Auth/Register.tsx | Breeze | ✅ Done |
| HomeView | /home (auth) | /dashboard | Dashboard.tsx | DashboardController | ⏳ Pending |
| MembersView | /members | /members | Members/Index.tsx | MembersController | ⏳ Pending |
| SportsView | /sports | /sports | Sports/Index.tsx | SportsController | ⏳ Pending |
| EventsView | /events | /events | Events/Index.tsx | EventsController | ⏳ Pending |
| FinancialView | /financial | /financial | Financial/Index.tsx | FinancialController | ⏳ Pending |
| InventoryView | /inventory | /inventory | Inventory/Index.tsx | InventoryController | ⏳ Pending |
| SponsorsView | /sponsors | /sponsors | Sponsors/Index.tsx | SponsorsController | ⏳ Pending |
| MarketingView | /marketing | /marketing | Marketing/Index.tsx | MarketingController | ⏳ Pending |
| CommunicationView | /communication | /communication | Communication/Index.tsx | CommunicationController | ⏳ Pending |
| SettingsView | /settings | /settings | Settings/Index.tsx | SettingsController | ⏳ Pending |

**Navigation**: Client-side state management → Inertia `<Link>` components

---

## 🗄️ PERSISTENCE (useKV → PostgreSQL)

### Core Entities

| useKV Key | Dados Guardados | Laravel Table | Model | API Endpoint | Status |
|-----------|-----------------|---------------|-------|--------------|--------|
| `authenticated-user` | User atual (session) | - | Auth::user() | GET /api/user | ✅ Done (Breeze) |
| `club-users` | Array de Users | `users` | User | /api/users | ⏳ Pending |
| `settings-club-info` | Info do clube (único) | `club_settings` | ClubSetting | GET /api/club-settings | ⏳ Pending |
| `settings-user-types` | Tipos de membro | `user_types` | UserType | /api/user-types | ⏳ Pending |
| `settings-age-groups` | Escalões | `age_groups` | AgeGroup | /api/age-groups | ⏳ Pending |
| `settings-permissions` | Permissões | `permissions` | Permission | /api/permissions | ⏳ Pending |
| `settings-notification-prefs` | Preferências notif | `notification_preferences` | NotificationPreference | /api/notification-preferences | ⏳ Pending |
| `settings-monthly-fees` | Mensalidades config | `monthly_fee_settings` | MonthlyFeeSetting | /api/monthly-fee-settings | ⏳ Pending |
| `settings-provas` | Tipos de provas | `event_types` (provas) | EventType | /api/event-types | ⏳ Pending |
| `settings-articles` | Artigos produtos | `articles` | Article | /api/articles | ⏳ Pending |

### Sports/Training

| useKV Key | Dados Guardados | Laravel Table | Model | API Endpoint | Status |
|-----------|-----------------|---------------|-------|--------------|--------|
| `treinos` | Sessões de treino | `training_sessions` | TrainingSession | /api/training-sessions | ⏳ Pending |
| `treinos-atleta` | Treinos por atleta | (relation) | - | /api/users/{id}/trainings | ⏳ Pending |
| `treino-atletas` | Atletas em treino | `training_session_user` (pivot) | - | /api/training-sessions/{id}/athletes | ⏳ Pending |
| `dados-desportivos` | Dados desportivos atleta | `athlete_sports_data` | AthleteSportsData | /api/users/{id}/sports-data | ⏳ Pending |
| `competicoes` | Competições | `competitions` | Competition | /api/competitions | ⏳ Pending |
| `club-resultados` | Resultados gerais | `results` | Result | /api/results | ⏳ Pending |
| `club-resultados-provas` | Resultados por prova | `result_events` | ResultEvent | /api/result-events | ⏳ Pending |
| `evento-resultados` | Resultados de eventos | (relation) | - | /api/events/{id}/results | ⏳ Pending |
| `macrociclos` | Macrociclos treino | `training_macrocycles` | TrainingMacrocycle | /api/training-macrocycles | ⏳ Pending |
| `club-mesociclos` | Mesociclos | `training_mesocycles` | TrainingMesocycle | /api/training-mesocycles | ⏳ Pending |
| `club-microciclos` | Microciclos | `training_microcycles` | TrainingMicrocycle | /api/training-microcycles | ⏳ Pending |
| `epocas` | Épocas desportivas | `seasons` | Season | /api/seasons | ⏳ Pending |

### Events/Convocations

| useKV Key | Dados Guardados | Laravel Table | Model | API Endpoint | Status |
|-----------|-----------------|---------------|-------|--------------|--------|
| `club-events` | Eventos do clube | `events` | Event | /api/events | ⏳ Pending |
| `club-eventos-tipos` | Tipos de eventos | `event_types` | EventType | /api/event-types | ⏳ Pending |
| `club-convocatorias` | Convocatórias | `convocations` | Convocation | /api/convocations | ⏳ Pending |
| `club-convocatorias-atleta` | Convocatórias por atleta | (relation) | - | /api/users/{id}/convocations | ⏳ Pending |
| `club-convocatorias-grupo` | Convocatórias grupo | `convocation_groups` | ConvocationGroup | /api/convocation-groups | ⏳ Pending |
| `convocatorias-atletas` | Atletas convocados | `convocation_user` (pivot) | - | /api/convocations/{id}/athletes | ⏳ Pending |
| `movimentos-convocatoria` | Movimentos convocatória | `convocation_movements` | ConvocationMovement | /api/convocation-movements | ⏳ Pending |
| `club-presencas` | Presenças | `attendances` | Attendance | /api/attendances | ⏳ Pending |

### Financial

| useKV Key | Dados Guardados | Laravel Table | Model | API Endpoint | Status |
|-----------|-----------------|---------------|-------|--------------|--------|
| `club-faturas` | Faturas | `invoices` | Invoice | /api/invoices | ⏳ Pending |
| `club-fatura-itens` | Itens de fatura | `invoice_items` | InvoiceItem | /api/invoice-items | ⏳ Pending |
| `club-mensalidades` | Mensalidades | `monthly_fees` | MonthlyFee | /api/monthly-fees | ⏳ Pending |
| `club-movimentos` | Movimentos financeiros | `financial_movements` | FinancialMovement | /api/financial-movements | ⏳ Pending |
| `club-movimento-items` | (typo?) Itens movimento | `financial_movement_items` | FinancialMovementItem | /api/financial-movement-items | ⏳ Pending |
| `club-movimento-itens` | Itens movimento | `financial_movement_items` | FinancialMovementItem | /api/financial-movement-items | ⏳ Pending |
| `club-lancamentos` | Lançamentos | `ledger_entries` | LedgerEntry | /api/ledger-entries | ⏳ Pending |
| `club-extratos-bancarios` | Extratos bancários | `bank_statements` | BankStatement | /api/bank-statements | ⏳ Pending |
| `club-centros-custo` | Centros de custo | `cost_centers` | CostCenter | /api/cost-centers | ⏳ Pending |

### Communication/Marketing

| useKV Key | Dados Guardados | Laravel Table | Model | API Endpoint | Status |
|-----------|-----------------|---------------|-------|--------------|--------|
| `club-comunicacoes` | Comunicações | `communications` | Communication | /api/communications | ⏳ Pending |
| `club-comunicacoes-automaticas` | Comunicações auto | `automated_communications` | AutomatedCommunication | /api/automated-communications | ⏳ Pending |
| `club-sponsors` | Patrocinadores | `sponsors` | Sponsor | /api/sponsors | ⏳ Pending |

### Inventory

| useKV Key | Dados Guardados | Laravel Table | Model | API Endpoint | Status |
|-----------|-----------------|---------------|-------|--------------|--------|
| `club-products` | Produtos | `products` | Product | /api/products | ⏳ Pending |
| `club-sales` | Vendas | `sales` | Sale | /api/sales | ⏳ Pending |

---

## 🧩 COMPONENTES REACT

### UI Puros (Copiar Direto)

| Spark Component | Destino Laravel | Alterações | Status |
|-----------------|-----------------|------------|--------|
| `components/ui/*` | `Components/UI/*` | Nenhuma (shadcn/ui) | ✅ Pode copiar |
| `components/UserList.tsx` | `Components/UserList.tsx` | useKV → React Query | ⏳ Pending |
| `components/UserProfile.tsx` | `Components/UserProfile.tsx` | useKV → React Query | ⏳ Pending |
| `components/FileUpload.tsx` | `Components/FileUpload.tsx` | Upload logic | ⏳ Pending |
| `components/EmailConfig.tsx` | `Components/EmailConfig.tsx` | API calls | ⏳ Pending |
| `components/DiagnosticOverlay.tsx` | (Remove - debug only) | - | ❌ Skip |

### Tabs (Com Lógica)

| Spark Component | Destino Laravel | Alterações | Status |
|-----------------|-----------------|------------|--------|
| `tabs/PersonalTab.tsx` | `Components/Tabs/PersonalTab.tsx` | useKV → API | ⏳ Pending |
| `tabs/SportsTab.tsx` | `Components/Tabs/SportsTab.tsx` | useKV → API | ⏳ Pending |
| `tabs/FinancialTab.tsx` | `Components/Tabs/FinancialTab.tsx` | useKV → API | ⏳ Pending |
| `tabs/ConfigurationTab.tsx` | `Components/Tabs/ConfigurationTab.tsx` | useKV → API | ⏳ Pending |
| `tabs/sports/*.tsx` | `Components/Tabs/Sports/*.tsx` | useKV → API | ⏳ Pending |
| `tabs/sports-member/*.tsx` | `Components/Tabs/SportsMember/*.tsx` | useKV → API | ⏳ Pending |

### Financial Modules

| Spark Component | Destino Laravel | Alterações | Status |
|-----------------|-----------------|------------|--------|
| `financial/DashboardTab.tsx` | `Components/Financial/DashboardTab.tsx` | useKV → API | ⏳ Pending |
| `financial/FaturasTab.tsx` | `Components/Financial/FaturasTab.tsx` | useKV → API | ⏳ Pending |
| `financial/MovimentosTab.tsx` | `Components/Financial/MovimentosTab.tsx` | useKV → API | ⏳ Pending |
| `financial/BancoTab.tsx` | `Components/Financial/BancoTab.tsx` | useKV → API | ⏳ Pending |
| `financial/RelatoriosTab.tsx` | `Components/Financial/RelatoriosTab.tsx` | useKV → API | ⏳ Pending |

### Events Modules

| Spark Component | Destino Laravel | Alterações | Status |
|-----------------|-----------------|------------|--------|
| `eventos/EventsList.tsx` | `Components/Events/EventsList.tsx` | useKV → API | ⏳ Pending |
| `eventos/EventosCalendar.tsx` | `Components/Events/EventosCalendar.tsx` | useKV → API | ⏳ Pending |
| `eventos/EventosTipos.tsx` | `Components/Events/EventosTipos.tsx` | useKV → API | ⏳ Pending |
| `eventos/EventosResultados.tsx` | `Components/Events/EventosResultados.tsx` | useKV → API | ⏳ Pending |
| `eventos/EventosRelatorios.tsx` | `Components/Events/EventosRelatorios.tsx` | useKV → API | ⏳ Pending |
| `eventos/ConvocatoriasList.tsx` | `Components/Events/ConvocatoriasList.tsx` | useKV → API | ⏳ Pending |
| `eventos/CreateConvocatoriaDialog.tsx` | `Components/Events/CreateConvocatoriaDialog.tsx` | useKV → API | ⏳ Pending |
| `eventos/PresencasList.tsx` | `Components/Events/PresencasList.tsx` | useKV → API | ⏳ Pending |

---

## 🔐 LÓGICA DE NEGÓCIO

### Autenticação
- **Spark**: `lib/auth.ts` → função `authenticateUser()`
- **Laravel**: Breeze Auth + Sanctum
- **Migration**: Já feito ✅

### Validações
- **Spark**: (procurar validações inline nos componentes)
- **Laravel**: Form Requests + Validation Rules
- **Status**: ⏳ Pending (após análise componentes)

### User Management
- **Spark**: CRUD inline em `App.tsx` e `MembersView.tsx`
- **Laravel**: `UserController` com policies
- **Fields**:
  ```php
  - id (uuid)
  - numero_socio (string, unique)
  - nome_completo (string)
  - email_utilizador (string, unique)
  - senha (hashed password)
  - perfil (enum: admin, user, atleta)
  - tipo_membro (jsonb array)
  - estado (enum: ativo, inativo, suspenso)
  - data_nascimento (date)
  - menor (boolean)
  - sexo (enum)
  - escalao (jsonb array - FK to age_groups)
  - rgpd, consentimento, afiliacao, declaracao_de_transporte (booleans)
  - ativo_desportivo (boolean)
  ```

### Financial Sync
- **Spark**: `lib/financial-sync.ts` - sincroniza dados financeiros
- **Laravel**: Jobs + Queue system
- **Status**: ⏳ Pending

### Email Service
- **Spark**: `lib/email-service.ts`
- **Laravel**: Laravel Mail + Queues
- **Status**: ⏳ Pending

---

## 📋 PRIORIZAÇÃO (Fases Seguintes)

### FASE 3.2-3.3: Core Auth & Users (PRIORITY 1)
1. ✅ Users table (já existe do Breeze)
2. ⏳ Adicionar campos extras à migration Users
3. ⏳ UserController API
4. ⏳ UserPolicy
5. ⏳ UserResource (API serialization)

### FASE 3.4: Settings (PRIORITY 2)
- user_types
- age_groups
- permissions
- club_settings
- event_types
- monthly_fee_settings

### FASE 3.5: Sports Module (PRIORITY 3)
- training_sessions
- athlete_sports_data
- competitions
- results
- training cycles (macro/meso/micro)
- seasons

### FASE 3.6: Events Module (PRIORITY 4)
- events
- convocations
- attendances

### FASE 3.7: Financial Module (PRIORITY 5)
- invoices + items
- monthly_fees
- financial_movements + items
- ledger_entries
- bank_statements
- cost_centers

### FASE 3.8: Communication & Others (PRIORITY 6)
- communications
- sponsors
- products
- sales

---

## 🔄 MIGRATION NOTES

### useKV → React Query Pattern
```typescript
// ANTES (Spark)
const [users, setUsers] = useKV<User[]>('club-users', []);

// DEPOIS (Laravel + React Query)
const { data: users = [], isLoading } = useQuery({
  queryKey: ['users'],
  queryFn: () => axios.get('/api/users').then(r => r.data)
});
```

### Navigation Pattern
```typescript
// ANTES (Spark)
setCurrentView('members');

// DEPOIS (Inertia)
import { Link } from '@inertiajs/react';
<Link href="/members">Members</Link>
```

### Form Submission Pattern
```typescript
// ANTES (Spark)
await spark.kv.set('club-users', [...users, newUser]);

// DEPOIS (Inertia)
import { useForm } from '@inertiajs/react';
const { post } = useForm();
post('/api/users', newUser);
```

---

## ✅ VALIDATION RULES

### User Creation
```php
// Laravel Form Request
'nome_completo' => 'required|string|max:255',
'email_utilizador' => 'required|email|unique:users',
'numero_socio' => 'required|string|unique:users',
'data_nascimento' => 'required|date',
'perfil' => 'required|in:admin,user,atleta',
'tipo_membro' => 'required|array',
'sexo' => 'required|in:masculino,feminino',
```

---

## 🚀 NEXT STEPS (Auto-execution)

1. **Extend Users Migration** (campos extras do Spark)
2. **Create Settings Tables** (user_types, age_groups, etc.)
3. **Create Sports Tables** (treinos, competições, etc.)
4. **Create Events Tables** (eventos, convocatórias)
5. **Create Financial Tables** (faturas, movimentos)
6. **API Controllers** (todos os endpoints)
7. **React Query Setup** (substituir useKV)
8. **Migrate Components** (copiar + ajustar)
9. **Migrate Views** (Inertia pages)
10. **E2E Testing** (validar cada módulo)

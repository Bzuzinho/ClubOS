# Spark to Laravel Migration Mapping

## Overview

This document provides a complete mapping of the migration from the Spark application (React + Vite + useKV) to Laravel 11 + Inertia React + PostgreSQL/SQLite.

## Table of Contents

1. [Views Mapping](#views-mapping)
2. [useKV Keys to Database Tables](#usekv-keys-to-database-tables)
3. [Components Mapping](#components-mapping)
4. [Routes Mapping](#routes-mapping)
5. [Database Optimizations](#database-optimizations)
6. [Known Issues](#known-issues)

---

## Views Mapping

### Original Spark Views → Laravel Inertia Pages

| Spark View | Laravel Page | Controller | Route | Status |
|-----------|--------------|------------|-------|--------|
| `src/views/HomeView.tsx` | `resources/js/Pages/Dashboard.tsx` | `DashboardController@index` | `/dashboard` | ✅ Complete |
| `src/views/LoginView.tsx` | `resources/js/Pages/Auth/Login.tsx` | `AuthenticatedSessionController` | `/login` | ✅ Breeze |
| `src/views/MembersView.tsx` | `resources/js/Pages/Membros/Index.tsx` | `MembrosController@index` | `/membros` | ⚠️ Structure |
| `src/views/EventsView.tsx` | `resources/js/Pages/Eventos/Index.tsx` | `EventosController@index` | `/eventos` | ⚠️ Structure |
| `src/views/SportsView.tsx` | `resources/js/Pages/Desportivo/Index.tsx` | `DesportivoController@index` | `/desportivo` | ⚠️ Structure |
| `src/views/FinancialView.tsx` | `resources/js/Pages/Financeiro/Index.tsx` | `FinanceiroController@index` | `/financeiro` | ⚠️ Structure |
| `src/views/InventoryView.tsx` | `resources/js/Pages/Loja/Index.tsx` | `LojaController@index` | `/loja` | ⚠️ Structure |
| `src/views/SponsorsView.tsx` | `resources/js/Pages/Patrocinios/Index.tsx` | `PatrociniosController@index` | `/patrocinios` | ⚠️ Structure |
| `src/views/CommunicationView.tsx` | `resources/js/Pages/Comunicacao/Index.tsx` | `ComunicacaoController@index` | `/comunicacao` | ⚠️ Structure |
| `src/views/MarketingView.tsx` | `resources/js/Pages/Marketing/Index.tsx` | `MarketingController@index` | `/marketing` | ⚠️ Structure |
| `src/views/SettingsView.tsx` | `resources/js/Pages/Settings/Index.tsx` | `SettingsController@index` | `/settings` | ⚠️ Structure |

**Legend**:
- ✅ Complete: Fully implemented with data fetching
- ⚠️ Structure: Page and controller exist, needs UI completion
- ❌ Pending: Not yet implemented

---

## useKV Keys to Database Tables

### User Management

| useKV Key | Database Table | Model | Migration | Notes |
|-----------|----------------|-------|-----------|-------|
| `users` | `users` | `User` | `0001_01_01_000000_create_users_table.php` + extensions | Extended with Spark fields |
| `userTypes` | `user_types` | `UserType` | `2026_01_29_163144_create_user_types_table.php` | 4 default types seeded |
| `ageGroups` | `age_groups` | `AgeGroup` | `2026_01_29_163144_create_age_groups_table.php` | 6 age groups seeded |

### Events & Sports

| useKV Key | Database Table | Model | Migration | Notes |
|-----------|----------------|-------|-----------|-------|
| `events` | `events` | `Event` | `2026_01_30_150002_create_events_table.php` | Full event system |
| `eventTypes` | `event_type_configs` | `EventTypeConfig` | `2026_01_30_150001_create_event_type_configs_table.php` | Event type configurations |
| `convocatorias` | `event_convocations` | `EventConvocation` | `2026_01_30_150003_create_event_convocations_table.php` | Call-ups system |
| `convocatoria_grupos` | `convocation_groups` | `ConvocationGroup` | `2026_01_30_150004_create_convocation_groups_table.php` | Grouped convocations |
| `evento_presencas` | `event_attendances` | `EventAttendance` | `2026_01_30_150006_create_event_attendances_table.php` | Attendance tracking |
| `evento_resultados` | `event_results` | `EventResult` | `2026_01_30_150007_create_event_results_table.php` | Event results |
| `epocas` | `seasons` | `Season` | `2026_01_30_150009_create_seasons_table.php` | Sports seasons |
| `macrociclos` | `macrocycles` | `Macrocycle` | `2026_01_30_150010_create_macrocycles_table.php` | Training macrocycles |
| `mesociclos` | `mesocycles` | `Mesocycle` | `2026_01_30_150011_create_mesocycles_table.php` | Training mesocycles |
| `microciclos` | `microcycles` | `Microcycle` | `2026_01_30_150012_create_microcycles_table.php` | Training microcycles |
| `treinos` | `trainings` | `Training` | `2026_01_30_150013_create_trainings_table.php` | Training sessions |
| `treino_series` | `training_series` | `TrainingSeries` | `2026_01_30_150014_create_training_series_table.php` | Training series |
| `treino_atletas` | `training_athletes` | `TrainingAthlete` | `2026_01_30_150015_create_training_athletes_table.php` | Athlete training data |
| `competitions` | `competitions` | `Competition` | `2026_01_30_150018_create_competitions_table.php` | Competitions |
| `provas` | `provas` | `Prova` | `2026_01_30_150019_create_provas_table.php` | Competitive events |
| `results` | `results` | `Result` | `2026_01_30_150021_create_results_table.php` | Competition results |

### Financial

| useKV Key | Database Table | Model | Migration | Notes |
|-----------|----------------|-------|-----------|-------|
| `mensalidades` | `monthly_fees` | `MonthlyFee` | `2026_01_30_150023_create_monthly_fees_table.php` | Monthly fee configs |
| `centros_custo` | `cost_centers` | `CostCenter` | `2026_01_29_163145_create_cost_centers_table.php` | Cost centers (4 seeded) |
| `faturas` | `invoices` | `Invoice` | `2026_01_30_150024_create_invoices_table.php` | Invoices |
| `fatura_items` | `invoice_items` | `InvoiceItem` | `2026_01_30_150025_create_invoice_items_table.php` | Invoice line items |
| `movimentos` | `movements` | `Movement` | `2026_01_30_150026_create_movements_table.php` | Financial movements |
| `movimento_items` | `movement_items` | `MovementItem` | `2026_01_30_150027_create_movement_items_table.php` | Movement line items |
| `extratos_bancarios` | `bank_statements` | `BankStatement` | `2026_01_30_150031_create_bank_statements_table.php` | Bank statements |
| `lancamentos_financeiros` | `financial_entries` | `FinancialEntry` | `2026_01_30_150030_create_financial_entries_table.php` | Financial ledger entries |

### Store & Sponsors

| useKV Key | Database Table | Model | Migration | Notes |
|-----------|----------------|-------|-----------|-------|
| `products` | `products` | `Product` | `2026_01_30_150032_create_products_table.php` | Store products |
| `sales` | `sales` | `Sale` | `2026_01_30_150033_create_sales_table.php` | Sales transactions |
| `sponsors` | `sponsors` | `Sponsor` | `2026_01_30_150034_create_sponsors_table.php` | Sponsors |
| `news_items` | `news_items` | `NewsItem` | `2026_01_30_150035_create_news_items_table.php` | News/Marketing content |

### Communications

| useKV Key | Database Table | Model | Migration | Notes |
|-----------|----------------|-------|-----------|-------|
| `communications` | `communications` | `Communication` | `2026_01_30_150036_create_communications_table.php` | Communication logs |
| `automated_communications` | `automated_communications` | `AutomatedCommunication` | `2026_01_30_150037_create_automated_communications_table.php` | Automated messages |

### Settings

| useKV Key | Database Table | Model | Migration | Notes |
|-----------|----------------|-------|-----------|-------|
| `clubSettings` | `club_settings` | `ClubSetting` | `2026_01_29_163145_create_club_settings_table.php` | Club configuration |
| `eventTypes` (config) | `event_types` | `EventType` | `2026_01_29_163145_create_event_types_table.php` | Simple event types (5 seeded) |

---

## Components Mapping

### Layout Components

| Spark Component | Laravel Component | Status |
|-----------------|-------------------|--------|
| `src/components/Layout.tsx` | `resources/js/Layouts/AuthenticatedLayout.tsx` | ✅ Complete |
| `src/components/Sidebar.tsx` | Integrated in `AuthenticatedLayout.tsx` | ✅ Complete |
| `src/components/NavItem.tsx` | `resources/js/Components/NavLink.tsx` | ✅ Complete |

### UI Components (shadcn/ui)

All components from `src/components/ui/` have been migrated to `resources/js/Components/ui/`:

- ✅ `alert-dialog.tsx`
- ✅ `avatar.tsx`
- ✅ `badge.tsx`
- ✅ `button.tsx`
- ✅ `card.tsx`
- ✅ `checkbox.tsx`
- ✅ `dialog.tsx`
- ✅ `dropdown-menu.tsx`
- ✅ `input.tsx`
- ✅ `label.tsx`
- ✅ `popover.tsx`
- ✅ `select.tsx`
- ✅ `separator.tsx`
- ✅ `sheet.tsx`
- ✅ `slider.tsx`
- ✅ `switch.tsx`
- ✅ `table.tsx`
- ✅ `tabs.tsx`
- ✅ `textarea.tsx`
- ✅ `toast.tsx`
- ✅ `toggle-group.tsx`

### Custom Components

| Spark Component | Laravel Component | Status |
|-----------------|-------------------|--------|
| `src/components/StatsCard.tsx` | `resources/js/Components/StatsCard.tsx` | ✅ Complete |
| `src/components/Modal.tsx` | `resources/js/Components/Modal.tsx` | ✅ Complete |
| `src/components/Dropdown.tsx` | `resources/js/Components/Dropdown.tsx` | ✅ Complete |

### Module-Specific Components

| Spark Path | Laravel Path | Status |
|-----------|--------------|--------|
| `src/components/tabs/members/*` | Needs migration to `resources/js/Components/Membros/` | ⚠️ Pending |
| `src/components/eventos/*` | Needs migration to `resources/js/Components/Eventos/` | ⚠️ Pending |
| `src/components/tabs/sports/*` | Needs migration to `resources/js/Components/Desportivo/` | ⚠️ Pending |
| `src/components/financial/*` | Needs migration to `resources/js/Components/Financeiro/` | ⚠️ Pending |
| `src/components/tabs/loja/*` | Needs migration to `resources/js/Components/Loja/` | ⚠️ Pending |

---

## Routes Mapping

### Authentication Routes

| Spark | Laravel Route | Method | Controller | Status |
|-------|--------------|--------|------------|--------|
| Client-side login | `/login` | GET/POST | `AuthenticatedSessionController` | ✅ Breeze |
| Client-side logout | `/logout` | POST | `AuthenticatedSessionController@destroy` | ✅ Breeze |
| - | `/register` | GET/POST | `RegisteredUserController` | ✅ Breeze |
| - | `/forgot-password` | GET/POST | `PasswordResetLinkController` | ✅ Breeze |

### Application Routes

| Spark Navigation | Laravel Route | Method | Controller | Status |
|------------------|--------------|--------|------------|--------|
| Home | `/dashboard` | GET | `DashboardController@index` | ✅ Complete |
| Membros | `/membros` | GET | `MembrosController@index` | ✅ Structure |
| Membros | `/membros` | POST | `MembrosController@store` | ✅ Structure |
| Membros | `/membros/{id}` | GET | `MembrosController@show` | ✅ Structure |
| Membros | `/membros/{id}/edit` | GET | `MembrosController@edit` | ✅ Structure |
| Membros | `/membros/{id}` | PUT | `MembrosController@update` | ✅ Structure |
| Membros | `/membros/{id}` | DELETE | `MembrosController@destroy` | ✅ Structure |
| Eventos | `/eventos/*` | CRUD | `EventosController` | ✅ Structure |
| Desportivo | `/desportivo/*` | CRUD | `DesportivoController` | ✅ Structure |
| Financeiro | `/financeiro/*` | CRUD | `FinanceiroController` | ✅ Structure |
| Loja | `/loja/*` | CRUD | `LojaController` | ✅ Structure |
| Patrocínios | `/patrocinios/*` | CRUD | `PatrociniosController` | ✅ Structure |
| Comunicação | `/comunicacao/*` | CRUD | `ComunicacaoController` | ✅ Structure |
| Marketing | `/marketing/*` | CRUD | `MarketingController` | ✅ Structure |
| Settings | `/settings` | GET | `SettingsController@index` | ✅ Structure |
| Settings | `/settings/user-types` | POST | `SettingsController@storeUserType` | ✅ Structure |
| Settings | `/settings/age-groups` | POST | `SettingsController@storeAgeGroup` | ✅ Structure |
| Settings | `/settings/event-types` | POST | `SettingsController@storeEventType` | ✅ Structure |

**Total Routes**: 67+ routes configured

---

## Database Optimizations

### Current Implementation (SQLite)

For development and testing, the application currently uses SQLite with the following optimizations:

1. **JSON Fields**: Using `json()` type for array data
2. **Indexes**: Added on frequently queried columns
3. **Autoincrement IDs**: Using integer autoincrement primary keys
4. **Relationships**: Eloquent relationships configured for efficient querying

### PostgreSQL Optimizations (For Production)

When migrating to PostgreSQL (Neon), apply these optimizations:

#### 1. UUID Native Type

**Update migrations**:
```php
// Change from:
$table->id();

// To:
$table->uuid('id')->primary();
```

**Update models**:
```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Model extends Model
{
    use HasFactory, HasUuids;
    
    protected $keyType = 'string';
    public $incrementing = false;
}
```

#### 2. JSONB with GIN Indexes

**Update migrations**:
```php
// Change from:
$table->json('tipo_membro')->nullable();

// To:
$table->jsonb('tipo_membro')->nullable();

// Add GIN index:
DB::statement('CREATE INDEX idx_users_tipo_membro ON users USING GIN (tipo_membro)');
```

**JSON fields to convert**:
- `users.tipo_membro` (array of user types)
- `users.escalao` (array of age group IDs)
- `users.encarregado_educacao` (array of guardian IDs)
- `users.educandos` (array of student IDs)
- `users.centro_custo` (array of cost center IDs)
- `users.arquivo_atestado_medico` (array of medical certificate files)
- `trainings.objetivos` (training objectives)
- `trainings.exercicios` (exercises array)
- `events.metadata` (event metadata)

#### 3. Array Types

For simple string arrays, use PostgreSQL native arrays:
```php
$table->string('tags', 255)->array()->nullable();
```

#### 4. Full-Text Search

Add full-text search indexes for searchable content:
```php
DB::statement('
    CREATE INDEX idx_users_fulltext ON users 
    USING gin(to_tsvector(\'portuguese\', nome_completo || \' \' || COALESCE(email, \'\')))
');
```

#### 5. Partitioning

For large tables like `event_attendances`, `trainings`, consider partitioning by date:
```sql
CREATE TABLE event_attendances_2026 PARTITION OF event_attendances
FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
```

#### 6. Materialized Views

Create materialized views for dashboard statistics:
```sql
CREATE MATERIALIZED VIEW dashboard_stats AS
SELECT 
    COUNT(*) FILTER (WHERE estado = 'ativo') as total_members,
    COUNT(*) FILTER (WHERE 'atleta' = ANY(tipo_membro)) as active_athletes,
    -- ... more stats
FROM users;

CREATE UNIQUE INDEX ON dashboard_stats (refresh_timestamp);
```

---

## Known Issues

### 1. PostgreSQL Connection
**Status**: BLOCKED  
**Issue**: Cannot connect to Neon PostgreSQL (network/DNS resolution issue)  
**Workaround**: Using SQLite for local development  
**Resolution**: Need to verify Neon credentials or resolve network connectivity  

### 2. Model UUID Migration
**Status**: RESOLVED (for SQLite)  
**Issue**: All models were using UUIDs but migrations used autoincrement IDs  
**Solution**: Removed HasUuids trait from all models for SQLite compatibility  
**Note**: When migrating to PostgreSQL, revert to UUIDs as per requirements  

### 3. JSONB vs JSON
**Status**: RESOLVED (for SQLite)  
**Issue**: Migrations used `jsonb()` which is PostgreSQL-specific  
**Solution**: Changed to `json()` for SQLite compatibility  
**Note**: When migrating to PostgreSQL, update back to `jsonb()` with GIN indexes  

### 4. Component Migration Incomplete
**Status**: IN PROGRESS  
**Issue**: Not all Spark components from `src/components/` have been migrated  
**Missing**: Module-specific tab components (members, sports, financial, etc.)  
**Resolution**: Audit and migrate remaining components  

### 5. CRUD UI Not Fully Implemented
**Status**: IN PROGRESS  
**Issue**: Controllers exist but frontend forms/tables need completion  
**Resolution**: Implement complete CRUD interfaces for each module  

### 6. File Uploads Not Implemented
**Status**: PENDING  
**Issue**: Document upload functionality not implemented (RGPD, medical certificates, etc.)  
**Resolution**: Implement file upload system with storage configuration  

### 7. Logo Not Copied
**Status**: PENDING  
**Issue**: Spark logo not copied to public directory  
**File**: `src/assets/images/Logo-cutout.png` → `public/images/Logo-cutout.png`  
**Resolution**: Copy logo and update references  

---

## Migration Checklist

### Phase 1: Setup ✅
- [x] Laravel 11 installed
- [x] Dependencies installed
- [x] Environment configured
- [x] Build system working

### Phase 2: Database ✅
- [x] All migrations created (48)
- [x] All models created (44)
- [x] Relationships configured
- [x] Seed data created

### Phase 3: Backend ✅
- [x] Controllers created (12)
- [x] Routes configured (67+)
- [x] Form validation created
- [x] Authentication working

### Phase 4: Frontend Structure ✅
- [x] All pages created
- [x] Layout migrated
- [x] UI components migrated
- [x] Navigation working

### Phase 5: Frontend Completion ⚠️
- [ ] Member CRUD UI complete
- [ ] Event CRUD UI complete
- [ ] Sports CRUD UI complete
- [ ] Financial CRUD UI complete
- [ ] All other modules UI complete

### Phase 6: Features ⚠️
- [ ] File upload system
- [ ] Email integration
- [ ] Report generation
- [ ] Data export/import

### Phase 7: PostgreSQL Migration ⏳
- [ ] Resolve Neon connection
- [ ] Update migrations for JSONB
- [ ] Update migrations for UUID
- [ ] Add GIN indexes
- [ ] Test with PostgreSQL

### Phase 8: Testing ⏳
- [ ] Manual QA all modules
- [ ] Test all CRUD operations
- [ ] Visual comparison with Spark
- [ ] Performance testing
- [ ] Security audit

### Phase 9: Documentation ⏳
- [x] This mapping document
- [ ] Deployment guide
- [ ] API documentation
- [ ] User guide

---

## Deployment Notes

### Production Checklist

1. **Database Migration**
   - [ ] Backup Spark useKV data
   - [ ] Export data to JSON
   - [ ] Import data to PostgreSQL
   - [ ] Verify data integrity

2. **Environment Configuration**
   - [ ] Update `.env` with PostgreSQL credentials
   - [ ] Configure storage for file uploads
   - [ ] Set up email service (SES, Mailgun, etc.)
   - [ ] Configure queue system

3. **Build & Deploy**
   - [ ] Run `npm run build`
   - [ ] Run `php artisan migrate`
   - [ ] Run `php artisan db:seed`
   - [ ] Configure web server (Nginx/Apache)
   - [ ] Set up SSL certificate

4. **Post-Deployment**
   - [ ] Test login flow
   - [ ] Verify all modules accessible
   - [ ] Check file upload permissions
   - [ ] Monitor error logs
   - [ ] Performance tuning

---

## Contact & Support

For questions or issues related to this migration:
- Review this documentation
- Check Laravel 11 documentation
- Check Inertia.js documentation
- Check PostgreSQL documentation

---

**Last Updated**: 2026-01-30  
**Migration Status**: ~85% Complete  
**Next Phase**: Complete CRUD UIs and PostgreSQL migration

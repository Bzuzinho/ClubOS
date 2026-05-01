# Members Module Implementation Guide

## 📋 Overview

This document describes the complete migration of the Members Module from Spark (React) to Laravel 11 + Inertia.js, preserving 100% of the original UI/UX.

## 🎯 Completed Tasks

### 1. Shared Components

#### FileUpload Component
**Location:** `resources/js/Components/FileUpload.tsx`

**Features:**
- Drag & drop support
- Base64 encoding for file storage
- File type validation (configurable)
- Size validation (default 10MB, configurable)
- Multiple files support
- Preview thumbnails
- Remove file functionality

**Usage:**
```tsx
<FileUpload
  value={user.arquivo_rgpd}
  onChange={(v) => onChange('arquivo_rgpd', v)}
  accept=".pdf,.doc,.docx,image/*"
  maxSizeMB={5}
/>
```

### 2. Tab Components

#### PersonalTab (`resources/js/Components/Members/Tabs/PersonalTab.tsx`)

**Sections:**
1. **Photo Upload** - Avatar with image upload (5MB max)
2. **Basic Info** - Name, member number (auto), birth date with age
3. **Identification** - NIF, CC, nationality, marital status
4. **Address** - Street, postal code, city
5. **Gender & Status** - Radio buttons for sex and member status
6. **Contacts** - Phone, mobile, secondary email, emergency contact
7. **Professional** - Occupation, company, school, siblings
8. **Member Types** - Multi-select checkboxes (atleta, socio, etc.)
9. **Family Relations** - Guardian selector, dependents list

**Key Features:**
- Age auto-calculation from birth date
- Guardian filtering (only users with tipo_membro = 'encarregado_educacao')
- Readonly educandos field (auto-managed on backend)
- Photo upload with validation and base64 conversion

#### FinancialTab (`resources/js/Components/Members/Tabs/FinancialTab.tsx`)

**Sections:**
1. **Financial Config** - Monthly fee type, cost center (editable)
2. **Account Balance** - Current account balance (readonly)
3. **Invoices Table** - User's invoices with status badges
4. **Financial Movements** - Convocation charges

**Features:**
- Color-coded status badges (green=paid, yellow=pending, red=overdue)
- Summary totals (total paid, total pending)
- EUR currency formatting
- ScrollArea for tables (240px height)

#### SportsTab (`resources/js/Components/Members/Tabs/SportsTab.tsx`)

**Nested Tabs:**
1. **Dados Desportivos** - Federation data, age group, medical certificate
2. **Convocatórias** - Event convocations (readonly)
3. **Registo Presenças** - Attendance records (readonly)
4. **Resultados** - Competition results (readonly)
5. **Treinos** - Training sessions (placeholder)
6. **Planeamento** - Planning (placeholder)

**Condition:** Only shown when `user.member_type.includes('atleta')`

##### DadosDesportivosTab
- Federation number, PMB number, federation card upload
- Age group multi-select
- Medical certificate date, files (multi-upload), notes
- Active sports status toggle

##### ConvocatoriasTab
- Readonly table of event convocations
- Columns: Event, Date, Status, Club Transport
- Status badges: pending, confirmed, rejected

##### RegistoPresencasTab
- Readonly attendance records
- Stats display: Total events, Present (%), Absent (%)
- Presence badges: presente (green), falta (red), justificada (yellow)

##### ResultadosTab
- Competition results table
- Columns: Event, Race, Date, Location, Final Time
- View details functionality

#### ConfigurationTab (`resources/js/Components/Members/Tabs/ConfigurationTab.tsx`)

**Sections:**
1. **Platform Access**
   - Email for authentication (email_utilizador)
   - Profile/permissions (admin, encarregado, atleta, staff)
   - Password field (create mode only)
   - Password reset button (edit mode only)

2. **RGPD Consent**
   - Switch to enable/disable
   - Date picker (when enabled)
   - File upload (when enabled)

3. **Consentimento**
   - Switch, date picker, file upload
   - Same pattern as RGPD

4. **Afiliação**
   - Switch, date picker, file upload

5. **Declaração de Transporte**
   - Switch and file upload

### 3. Page Components

#### Show.tsx (`resources/js/Pages/Members/Show.tsx`)

**Purpose:** Edit existing member

**Features:**
- Header with member name and number
- Back button with unsaved changes warning
- Save/Cancel buttons
- All 4 tabs integrated
- Toast notifications (success/error)
- Changes tracking

**Props:**
```tsx
interface Props {
  member: User;
  allUsers: User[];
  userTypes: any[];
  ageGroups: any[];
}
```

#### Create.tsx (`resources/js/Pages/Members/Create.tsx`)

**Purpose:** Create new member

**Features:**
- Empty user object with defaults
- Member number shows "(Auto)"
- Password field required (in Configuration tab)
- POST to `members.store` route
- All validation rules enforced

**Differences from Show:**
- Title: "Novo Membro"
- Submit method: POST (not PUT)
- Password field visible and required
- No password reset button

### 4. Backend Components

#### StoreMemberRequest (`app/Http/Requests/StoreMemberRequest.php`)

**Validation Rules:**
- Required: full_name, data_nascimento, email_utilizador, password, member_type, sexo, perfil, estado
- Optional: All other 50+ fields
- Unique: email_utilizador, nif, numero_socio
- File fields: Accept base64 strings
- Arrays: member_type, encarregado_educacao, escalao, centro_custo

**Portuguese Error Messages:**
- All validation messages in Portuguese
- User-friendly descriptions

#### UpdateMemberRequest (`app/Http/Requests/UpdateMemberRequest.php`)

**Differences from Store:**
- Password optional
- Unique rules exclude current user
- Same validation logic otherwise

#### MembersController (`app/Http/Controllers/MembersController.php`)

**Helper Methods:**
1. **`isBase64(string $data): bool`**
   - Validates data URI base64 format

2. **`storeBase64Image(string $base64, string $path): string`**
   - Extracts base64 data
   - Validates image type (jpg, png, gif)
   - Generates UUID filename
   - Stores in storage/app/public/$path
   - Returns relative path

3. **`storeFile(string $base64OrPath, string $path): string`**
   - Generic file storage
   - Validates file type (whitelist)
   - 10MB max size
   - UUID filenames
   - Returns path

4. **`deleteFile(?string $path): void`**
   - Safely deletes file if exists

5. **`generateMemberNumber(): string`**
   - Auto-generates sequential member numbers
   - Format: 0001, 0002, etc. (4 digits)
   - Queries last member number
   - Increments by 1

**CRUD Methods:**

**store():**
- Handles file uploads (foto_perfil, cartao_federacao, all documents)
- Auto-generates member_number
- Auto-calculates menor (age < 18)
- Hashes password
- Syncs encarregado_educacao bidirectionally
- Exception handling with Portuguese messages

**update():**
- Same file handling with old file deletion
- Optional password hashing
- Updates menor if data_nascimento changes
- Bidirectional guardian sync (removes from old, adds to new)

**destroy():**
- Detaches all guardian/educando relationships
- Deletes all associated files
- Soft delete (if using SoftDeletes)

#### User Model (`app/Models/User.php`)

**Fillable Attributes:**
- All English field names (original)
- All Portuguese field names (added)
- Supports both naming conventions

**Relationships:**
- `userTypes()` - BelongsToMany
- `ageGroup()` - BelongsTo
- `encarregados()` - BelongsToMany (guardians)
- `educandos()` - BelongsToMany (dependents)
- `eventsCreated()` - HasMany

## 🗄️ Database Schema

### Users Table Fields (Portuguese)

**Basic Info:**
- numero_socio (unique)
- nome_completo
- data_nascimento
- menor (boolean)
- sexo (masculino/feminino)
- perfil (admin/encarregado/atleta/staff)
- tipo_membro (JSON array)
- estado (ativo/inativo/suspenso)

**Identification:**
- nif (9 digits, unique)
- cc
- numero_utente
- nacionalidade
- estado_civil

**Address:**
- morada
- codigo_postal
- localidade

**Contacts:**
- contacto
- contacto_telefonico
- telemovel
- email_secundario
- contacto_emergencia_nome
- contacto_emergencia_telefone
- contacto_emergencia_relacao

**Professional:**
- ocupacao
- empresa
- escola
- numero_irmaos

**Family:**
- encarregado_educacao (JSON array)
- educandos (JSON array)

**Financial:**
- tipo_mensalidade
- conta_corrente (decimal)
- centro_custo (JSON array)

**Sports:**
- num_federacao
- cartao_federacao (file path)
- numero_pmb
- data_inscricao
- inscricao (file path)
- escalao (JSON array)
- data_atestado_medico
- arquivo_atestado_medico (JSON array of files)
- informacoes_medicas (text)
- ativo_desportivo (boolean)

**Authentication:**
- email_utilizador (unique)
- senha (hashed)

**Consents:**
- rgpd (boolean)
- data_rgpd
- arquivo_rgpd (file path)
- consentimento (boolean)
- data_consentimento
- arquivo_consentimento (file path)
- afiliacao (boolean)
- data_afiliacao
- arquivo_afiliacao (file path)
- declaracao_de_transporte (boolean)
- declaracao_transporte (file path)

**Files:**
- foto_perfil (file path)

## 🎨 UI/UX Specifications

### Tailwind Classes (Preserved from Spark)

**Container:**
```tsx
<div className="space-y-2">
```

**Section:**
```tsx
<div className="space-y-1.5 p-1.5 border rounded-lg bg-card">
```

**Section Title:**
```tsx
<h3 className="text-xs font-semibold">
```

**Grid Layout:**
```tsx
<div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
```

**Input Field:**
```tsx
<Input className="h-7 text-xs" />
```

**Label:**
```tsx
<Label className="text-xs">
```

**Button:**
```tsx
<Button size="sm" className="h-7 text-xs">
```

### Responsive Breakpoints

- **Mobile-first:** All layouts work on mobile
- **sm:** 640px - 2 columns for grids
- **lg:** 1024px - 4 columns for some grids

### Color Scheme

**Status Badges:**
- Pending: `bg-yellow-100 text-yellow-800`
- Paid/Success: `bg-green-100 text-green-800`
- Overdue/Error: `bg-red-100 text-red-800`
- Partial: `bg-blue-100 text-blue-800`
- Canceled: `bg-gray-100 text-gray-800`

**Form Elements:**
- Disabled: `bg-muted`
- Focus: Default shadcn/ui focus rings
- Error: Default validation styling

## 🚀 Testing Guide

### Prerequisites

1. Install dependencies:
```bash
composer install
npm install
```

2. Set up environment:
```bash
cp .env.example .env
php artisan key:generate
```

3. Configure database in `.env`

4. Run migrations:
```bash
php artisan migrate
```

5. Build assets:
```bash
npm run dev
# or for production
npm run build
```

### Test Scenarios

#### 1. Create New Member

1. Navigate to `/members`
2. Click "Novo Membro" button
3. Fill in **PersonalTab:**
   - Upload photo (test validation: >5MB should fail)
   - Enter nome_completo (required)
   - Set data_nascimento (verify age calculation)
   - Select at least one tipo_membro (required)
   - If menor, test guardian selector
4. Fill in **FinancialTab:**
   - Select tipo_mensalidade
   - Select centro_custo
5. If atleta, fill **SportsTab > Dados Desportivos:**
   - Enter federation numbers
   - Upload federation card
   - Select age group
   - Set medical certificate date
   - Upload medical files
6. Fill in **ConfigurationTab:**
   - Enter email_utilizador (required, unique)
   - Set perfil (required)
   - Enter password (required, min 8 chars)
   - Enable RGPD (required)
   - Upload RGPD file
   - Same for other consents
7. Click "Guardar"
8. Verify success toast
9. Verify redirect to members.index
10. Verify member appears in list

#### 2. Edit Existing Member

1. Click on a member from list
2. Modify fields in each tab
3. Verify "Alterações não guardadas" indicator
4. Click "Cancelar" - verify warning dialog
5. Make changes again
6. Click "Guardar"
7. Verify success toast
8. Verify changes persist (reload page)

#### 3. Field Validations

Test each validation rule:
- Required fields (nome_completo, data_nascimento, email_utilizador, etc.)
- Email format
- NIF format (9 digits)
- Date validations (data_nascimento must be in past)
- File size limits
- File type restrictions
- Unique constraints (email_utilizador, nif)

#### 4. Guardian/Educando Relationships

1. Create guardian user (tipo_membro = encarregado_educacao)
2. Create minor athlete (menor = true)
3. Assign guardian to athlete
4. Verify bidirectional sync:
   - Guardian appears in athlete's encarregado_educacao
   - Athlete appears in guardian's educandos
5. Remove guardian from athlete
6. Verify removal from both sides

#### 5. File Uploads

1. **Photo upload:**
   - Upload valid image (<5MB)
   - Verify preview
   - Verify base64 encoding
   - Test rejection of non-images
   - Test rejection of >5MB files

2. **Document uploads:**
   - Upload PDF
   - Upload Word document
   - Upload image
   - Test multi-file upload (medical certificates)
   - Verify download links

3. **File deletion:**
   - Upload file
   - Remove file
   - Upload new file
   - Verify old file deleted

#### 6. Sports Tab Visibility

1. Create member without 'atleta' type
2. Verify Sports tab is hidden
3. Edit member, add 'atleta' type
4. Verify Sports tab appears
5. Remove 'atleta' type
6. Verify Sports tab disappears

#### 7. Mobile Responsiveness

Test on different screen sizes:
- Mobile (320px - 640px)
- Tablet (640px - 1024px)
- Desktop (>1024px)

Verify:
- Grids collapse to single column on mobile
- Tabs are scrollable on mobile
- Buttons are touch-friendly
- Forms are usable without horizontal scroll

#### 8. Browser Compatibility

Test in:
- Chrome
- Firefox
- Safari
- Edge

#### 9. Performance

- Test with 100+ members in database
- Verify list pagination
- Check load times
- Monitor file upload performance

#### 10. Error Handling

- Test network errors
- Test validation errors
- Verify Portuguese error messages
- Test file upload errors

## 🐛 Known Issues / Future Enhancements

### Placeholders
- TreinosTab - Currently placeholder
- PlaneamentoTab - Currently placeholder

### Potential Improvements
1. Add image cropping for profile photos
2. Implement drag-and-drop file upload zones
3. Add bulk member import
4. Add export to CSV/Excel
5. Add member search/filter
6. Add member activity log
7. Implement soft deletes with restore
8. Add member merge functionality
9. Add duplicate detection

## 📚 Dependencies

### Laravel Packages
- inertiajs/inertia-laravel
- laravel/sanctum
- intervention/image (optional for image processing)

### NPM Packages
- @inertiajs/react
- react
- react-dom
- tailwindcss
- date-fns
- sonner (toast notifications)
- All shadcn/ui components

## 🔒 Security Considerations

1. **File Uploads:**
   - Validate file types (whitelist only)
   - Limit file sizes
   - Store outside public directory
   - Use UUID filenames (prevent path traversal)
   - Sanitize filenames

2. **Authentication:**
   - Hash passwords with bcrypt
   - Validate email uniqueness
   - Implement rate limiting
   - Use CSRF protection (built-in)

3. **Authorization:**
   - Check isAdmin for sensitive operations
   - Validate ownership before updates
   - Implement proper policies

4. **Data Validation:**
   - Server-side validation always
   - Client-side for UX only
   - Sanitize all inputs
   - Validate file content, not just extension

## 📞 Support

For questions or issues:
1. Check this documentation
2. Review Spark source code for reference
3. Check Laravel/Inertia documentation
4. Review database migrations for field types
5. Test in isolation (create minimal test case)

## ✅ Checklist for Deployment

- [ ] Run `composer install --optimize-autoloader --no-dev`
- [ ] Run `npm run build`
- [ ] Set `APP_ENV=production` in .env
- [ ] Set `APP_DEBUG=false` in .env
- [ ] Configure proper database credentials
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Link storage: `php artisan storage:link`
- [ ] Set proper file permissions (storage, bootstrap/cache)
- [ ] Configure mail settings for password reset
- [ ] Test file uploads in production environment
- [ ] Verify all routes are accessible
- [ ] Test with real user data
- [ ] Monitor error logs
- [ ] Set up backups

---

**Migration Completed:** February 2026  
**Version:** Laravel 11 + Inertia.js React  
**Status:** Ready for Testing

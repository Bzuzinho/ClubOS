# 🏗️ Architecture Diagram - Spark to Laravel Migration

## Complete Backend Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         ROUTES (routes/web.php)                     │
│                                                                     │
│  Dashboard → Membros → Eventos → Desportivo → Financeiro → Loja   │
│  Patrocinios → Comunicacao → Marketing → Settings                  │
│                                                                     │
│  Total: 70+ routes (RESTful + Settings CRUD)                       │
└────────────────────┬────────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     CONTROLLERS (9 Resource + 1 Dashboard)          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │
│  │ Dashboard       │  │ Membros         │  │ Eventos         │   │
│  │ Controller      │  │ Controller      │  │ Controller      │   │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │
│           │                    │                     │             │
│  ┌────────▼────────┐  ┌────────▼────────┐  ┌────────▼────────┐   │
│  │ Desportivo      │  │ Financeiro      │  │ Loja            │   │
│  │ Controller      │  │ Controller      │  │ Controller      │   │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │
│           │                    │                     │             │
│  ┌────────▼────────┐  ┌────────▼────────┐  ┌────────▼────────┐   │
│  │ Patrocinios     │  │ Comunicacao     │  │ Marketing       │   │
│  │ Controller      │  │ Controller      │  │ Controller      │   │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘   │
│           │                    │                     │             │
│           └────────────────────┴─────────────────────┘             │
│                              │                                     │
└──────────────────────────────┼─────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│              FORM REQUEST VALIDATORS (12 Classes)                   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Store/Update MemberRequest     │  Store/Update EventRequest      │
│  Store/Update TrainingRequest   │  Store/Update InvoiceRequest    │
│  Store/Update ProductRequest    │  Store/Update SponsorRequest    │
│                                                                     │
│  ✅ Validation Rules  ✅ Authorization  ✅ Error Messages          │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   ELOQUENT MODELS (43 Models)                       │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  👤 User                  📅 Event                🏃 Training       │
│  💰 Invoice               📦 Product             🤝 Sponsor        │
│  📢 Communication         📰 News                ⚙️ UserType       │
│  👶 AgeGroup              🎯 EventType           📊 EventResult    │
│  ✅ EventAttendance       📞 Convocation         ... (30+ more)    │
│                                                                     │
│  ✅ Relationships  ✅ Fillable  ✅ Casts  ✅ Accessors             │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      DATABASE (MySQL/PostgreSQL)                    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  🗄️ 43 Tables Created via Migrations                               │
│                                                                     │
│  Core Tables:                                                       │
│  ├─ users                    (Members/Athletes/Guardians)          │
│  ├─ events                   (Events management)                   │
│  ├─ trainings                (Training sessions)                   │
│  ├─ invoices                 (Financial records)                   │
│  ├─ products                 (Inventory)                           │
│  └─ sponsors                 (Sponsorships)                        │
│                                                                     │
│  Lookup Tables:                                                     │
│  ├─ user_types               (Member types)                        │
│  ├─ age_groups               (Age classifications)                 │
│  ├─ event_types              (Event categories)                    │
│  └─ ... (15+ lookup tables)                                        │
│                                                                     │
│  Relationship Tables:                                               │
│  ├─ user_user_type           (Many-to-many: users ↔ types)        │
│  ├─ user_guardian            (Self-referential: guardians)         │
│  ├─ training_athlete         (Many-to-many: trainings ↔ athletes) │
│  └─ ... (10+ pivot tables)                                         │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Request Flow Diagram

```
┌──────────────┐
│   Browser    │
│  (Frontend)  │
└──────┬───────┘
       │
       │ HTTP Request (GET/POST/PUT/DELETE)
       │
       ▼
┌─────────────────────────────────────────────────┐
│           LARAVEL APPLICATION                    │
│                                                  │
│  1. Route Matching (routes/web.php)             │
│     └─> Middleware (auth, verified)             │
│                                                  │
│  2. Controller Action                            │
│     └─> Route Model Binding (automatic)         │
│                                                  │
│  3. Form Request Validation                      │
│     └─> Rules check                             │
│     └─> Authorization check                     │
│                                                  │
│  4. Business Logic                               │
│     ├─> Query database (Eloquent)               │
│     ├─> Load relationships (eager loading)      │
│     ├─> Calculate stats                         │
│     └─> Process data                            │
│                                                  │
│  5. Response (Inertia)                           │
│     └─> Return Inertia::render()                │
│         └─> Pass data to frontend               │
│                                                  │
└──────────────────┬──────────────────────────────┘
                   │
                   │ JSON Response
                   │
                   ▼
┌─────────────────────────────────────────────────┐
│         INERTIA.JS (Frontend Bridge)            │
│                                                  │
│  • Receives controller data                     │
│  • Hydrates React components                    │
│  • Maintains SPA navigation                     │
│                                                  │
└──────────────────┬──────────────────────────────┘
                   │
                   │ Component Props
                   │
                   ▼
┌─────────────────────────────────────────────────┐
│      REACT/TYPESCRIPT COMPONENTS                │
│           (Frontend Views)                       │
│                                                  │
│  • Membros/Index.tsx                            │
│  • Eventos/Create.tsx                           │
│  • Financeiro/Show.tsx                          │
│  • etc.                                         │
│                                                  │
└─────────────────────────────────────────────────┘
```

---

## Module Architecture (Example: Membros)

```
GET /membros
     │
     ▼
┌─────────────────────────────────────┐
│  MembrosController@index()          │
│                                     │
│  1. Query database                  │
│     User::with(['userTypes',        │
│                 'ageGroup',          │
│                 'encarregados',      │
│                 'educandos'])        │
│         ->latest()                  │
│         ->paginate(15)               │
│                                     │
│  2. Load related data               │
│     UserType::where('active', true) │
│     AgeGroup::all()                 │
│                                     │
│  3. Return Inertia response         │
│     return Inertia::render(         │
│         'Membros/Index',            │
│         [                           │
│             'members' => $members,  │
│             'userTypes' => ...,     │
│             'ageGroups' => ...      │
│         ]                           │
│     );                              │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│  Membros/Index.tsx                  │
│                                     │
│  • Display members table            │
│  • Pagination controls              │
│  • Filter by user type              │
│  • Search functionality             │
│  • Action buttons (edit, delete)    │
└─────────────────────────────────────┘


POST /membros
     │
     ▼
┌─────────────────────────────────────┐
│  StoreMemberRequest                 │
│                                     │
│  1. Validate input                  │
│     - name: required|string         │
│     - email: required|email|unique  │
│     - tipo_membro: json             │
│     - estado: in:ativo,inativo...   │
│     - etc.                          │
│                                     │
│  2. Check authorization             │
│     return true; // or policy       │
└─────────────┬───────────────────────┘
              │
              ▼
┌─────────────────────────────────────┐
│  MembrosController@store()          │
│                                     │
│  1. Get validated data              │
│     $data = $request->validated()   │
│                                     │
│  2. Hash password                   │
│     $data['password'] = Hash::make()│
│                                     │
│  3. Create member                   │
│     $member = User::create($data)   │
│                                     │
│  4. Sync relationships              │
│     $member->userTypes()->sync()    │
│     $member->encarregados()->sync() │
│                                     │
│  5. Redirect with message           │
│     return redirect()               │
│         ->route('membros.index')    │
│         ->with('success', '...')    │
└─────────────────────────────────────┘
```

---

## Data Flow Summary

```
┌─────────┐    ┌──────────┐    ┌─────────┐    ┌──────────┐    ┌──────────┐
│ Routes  │───▶│Controllers│───▶│ Form    │───▶│ Models   │───▶│ Database │
│         │    │          │    │Requests │    │          │    │          │
└─────────┘    └──────────┘    └─────────┘    └──────────┘    └──────────┘
     │              │                │              │               │
     │              │                │              │               │
     └──────────────┴────────────────┴──────────────┴───────────────┘
                                     │
                                     │
                                     ▼
                            ┌─────────────────┐
                            │  Inertia.js     │
                            │  (JSON Bridge)  │
                            └────────┬────────┘
                                     │
                                     │
                                     ▼
                            ┌─────────────────┐
                            │ React/TypeScript│
                            │  Components     │
                            └─────────────────┘
```

---

## Statistics & Calculations Flow

```
DashboardController::index()
         │
         ├─> User::count()                              → totalMembers
         ├─> User::whereJsonContains()->count()         → activeAthletes
         ├─> Event::where()->count()                    → upcomingEvents
         ├─> Invoice::whereMonth()->sum()               → monthlyRevenue
         └─> getRecentActivity()                        → recentActivity
              │
              ├─> User::latest()->take(3)               → Recent users
              ├─> Event::latest()->take(3)              → Recent events
              └─> Merge & sort by date                  → Activity feed
```

---

## File Organization

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php       ✅ Stats + recent activity
│   │   ├── MembrosController.php         ✅ Full CRUD + relationships
│   │   ├── EventosController.php         ✅ Full CRUD + convocations
│   │   ├── DesportivoController.php      ✅ Full CRUD + athletes
│   │   ├── FinanceiroController.php      ✅ Full CRUD + invoice gen
│   │   ├── LojaController.php            ✅ Full CRUD + stock tracking
│   │   ├── PatrociniosController.php     ✅ Full CRUD + sponsorships
│   │   ├── ComunicacaoController.php     ✅ Full CRUD + communications
│   │   ├── MarketingController.php       ✅ Full CRUD + news
│   │   └── SettingsController.php        ✅ Settings CRUD
│   │
│   └── Requests/
│       ├── StoreMemberRequest.php        ✅ Member validation
│       ├── UpdateMemberRequest.php       ✅ Member validation
│       ├── StoreEventRequest.php         ✅ Event validation
│       ├── UpdateEventRequest.php        ✅ Event validation
│       ├── StoreTrainingRequest.php      ✅ Training validation
│       ├── UpdateTrainingRequest.php     ✅ Training validation
│       ├── StoreInvoiceRequest.php       ✅ Invoice validation
│       ├── UpdateInvoiceRequest.php      ✅ Invoice validation
│       ├── StoreProductRequest.php       ✅ Product validation
│       ├── UpdateProductRequest.php      ✅ Product validation
│       ├── StoreSponsorRequest.php       ✅ Sponsor validation
│       └── UpdateSponsorRequest.php      ✅ Sponsor validation
│
└── Models/
    ├── User.php                          ✅ 43 total models
    ├── Event.php                         ✅ All with relationships
    ├── Training.php                      ✅ All with fillable
    └── ... (40+ more models)             ✅ All with casts

routes/
└── web.php                               ✅ 70+ routes configured
```

---

## 🎯 Summary

✅ **Complete Backend Architecture**
- Routes → Controllers → Validation → Models → Database
- RESTful API design
- Inertia.js integration ready
- Comprehensive validation
- Relationship management
- Stats calculations
- Error handling

✅ **Ready For Frontend Development**
- All backend endpoints available
- Data properly structured
- Validation in place
- Relationships loaded
- Pagination configured

🎉 **Migration Complete!**

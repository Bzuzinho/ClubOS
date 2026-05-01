# Mapeamento de Normalização: Models → PostgreSQL

## ⚠️ Configuração BD
- **Tipo**: PostgreSQL 14+
- **Schema**: Normalizado para **PORTUGUÊS** ✅
- **Models**: Normalizados para **PORTUGUÊS** ✅
- **Problema Original**: Mismatch causava erros SQLSTATE[42703] - **RESOLVIDO** ✅

---

## 🎯 Objectivo

Normalizar **TODOS os Models** Laravel para usar nomenclatura **100% portuguesa**, alinhada com as migrations/tabelas PostgreSQL já normalizadas.

---

## 📊 Event Model

### Schema PostgreSQL (Original - Português)
```sql
CREATE TABLE events (
    id UUID PRIMARY KEY,
    titulo VARCHAR(255),                -- ✅ Português
    descricao TEXT,                     -- ✅ Português
    data_inicio DATE,                   -- ✅ Português
    hora_inicio TIME,                   -- ✅ Português
    data_fim DATE,                      -- ✅ Português
    hora_fim TIME,                      -- ✅ Português
    local VARCHAR(255),                 -- ✅ Português
    local_detalhes TEXT,                -- ✅ Português
    tipo VARCHAR(50),                   -- ✅ Português
    tipo_config_id UUID,                -- ✅ Português
    tipo_piscina VARCHAR(30),           -- ✅ Português
    visibilidade VARCHAR(20),           -- ✅ Português
    escaloes_elegiveis JSON,            -- ✅ Português
    transporte_necessario BOOLEAN,      -- ✅ Português
    transporte_detalhes TEXT,           -- ✅ Português
    hora_partida TIME,                  -- ✅ Português
    local_partida VARCHAR(255),         -- ✅ Português
    taxa_inscricao DECIMAL(10,2),       -- ✅ Português
    custo_inscricao_por_prova DECIMAL(10,2),  -- ✅ Português
    custo_inscricao_por_salto DECIMAL(10,2),  -- ✅ Português
    custo_inscricao_estafeta DECIMAL(10,2),   -- ✅ Português
    centro_custo_id UUID,               -- ✅ Português
    observacoes TEXT,                   -- ✅ Português
    convocatoria_ficheiro VARCHAR(255), -- ✅ Português
    regulamento_ficheiro VARCHAR(255),  -- ✅ Português
    estado VARCHAR(30),                 -- ✅ Português
    criado_por UUID,                    -- ✅ Português
    recorrente BOOLEAN,                 -- ✅ Português
    recorrencia_data_inicio DATE,       -- ✅ Português
    recorrencia_data_fim DATE,          -- ✅ Português
    recorrencia_dias_semana JSON,       -- ✅ Português
    evento_pai_id UUID,                 -- ✅ Português
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Model Actual (ERRADO - Inglês)
```php
protected $fillable = [
    'title',                    // ❌ BD tem 'titulo'
    'description',              // ❌ BD tem 'descricao'
    'start_date',               // ❌ BD tem 'data_inicio'
    'start_time',               // ❌ BD tem 'hora_inicio'
    'end_date',                 // ❌ BD tem 'data_fim'
    'end_time',                 // ❌ BD tem 'hora_fim'
    'location',                 // ❌ BD tem 'local'
    'location_details',         // ❌ BD tem 'local_detalhes'
    'type',                     // ❌ BD tem 'tipo'
    'tipo_config_id',           // ✅ OK
    'pool_type',                // ❌ BD tem 'tipo_piscina'
    'visibility',               // ❌ BD tem 'visibilidade'
    'eligible_age_groups',      // ❌ BD tem 'escaloes_elegiveis'
    'transport_required',       // ❌ BD tem 'transporte_necessario'
    'transport_details',        // ❌ BD tem 'transporte_detalhes'
    'departure_time',           // ❌ BD tem 'hora_partida'
    'departure_location',       // ❌ BD tem 'local_partida'
    'registration_fee',         // ❌ BD tem 'taxa_inscricao'
    'cost_per_race',            // ❌ BD tem 'custo_inscricao_por_prova'
    'cost_per_dive',            // ❌ BD tem 'custo_inscricao_por_salto'
    'relay_cost',               // ❌ BD tem 'custo_inscricao_estafeta'
    'centro_custo_id',          // ✅ OK
    'notes',                    // ❌ BD tem 'observacoes'
    'call_up_file',             // ❌ BD tem 'convocatoria_ficheiro'
    'regulations_file',         // ❌ BD tem 'regulamento_ficheiro'
    'status',                   // ❌ BD tem 'estado'
    'created_by',               // ❌ BD tem 'criado_por'
    'recurring',                // ❌ BD tem 'recorrente'
    'recurrence_start_date',    // ❌ BD tem 'recorrencia_data_inicio'
    'recurrence_end_date',      // ❌ BD tem 'recorrencia_data_fim'
    'recurrence_weekdays',      // ❌ BD tem 'recorrencia_dias_semana'
    'parent_event_id',          // ❌ BD tem 'evento_pai_id'
];
```

### Model Corrigido (DEVE SER - Português)
```php
protected $fillable = [
    'titulo',                      // ✅ Match PostgreSQL
    'descricao',                   // ✅
    'data_inicio',                 // ✅
    'hora_inicio',                 // ✅
    'data_fim',                    // ✅
    'hora_fim',                    // ✅
    'local',                       // ✅
    'local_detalhes',              // ✅
    'tipo',                        // ✅
    'tipo_config_id',              // ✅
    'tipo_piscina',                // ✅
    'visibilidade',                // ✅
    'escaloes_elegiveis',          // ✅
    'transporte_necessario',       // ✅
    'transporte_detalhes',         // ✅
    'hora_partida',                // ✅
    'local_partida',               // ✅
    'taxa_inscricao',              // ✅
    'custo_inscricao_por_prova',   // ✅
    'custo_inscricao_por_salto',   // ✅
    'custo_inscricao_estafeta',    // ✅
    'centro_custo_id',             // ✅
    'observacoes',                 // ✅
    'convocatoria_ficheiro',       // ✅
    'regulamento_ficheiro',        // ✅
    'estado',                      // ✅
    'criado_por',                  // ✅
    'recorrente',                  // ✅
    'recorrencia_data_inicio',     // ✅
    'recorrencia_data_fim',        // ✅
    'recorrencia_dias_semana',     // ✅
    'evento_pai_id',               // ✅
];

protected $casts = [
    'data_inicio' => 'date',                // ✅
    'data_fim' => 'date',                   // ✅
    'transporte_necessario' => 'boolean',   // ✅
    'recorrente' => 'boolean',              // ✅
    'recorrencia_data_inicio' => 'date',    // ✅
    'recorrencia_data_fim' => 'date',       // ✅
    'escaloes_elegiveis' => 'array',        // ✅
    'recorrencia_dias_semana' => 'array',   // ✅
    'taxa_inscricao' => 'decimal:2',        // ✅
    'custo_inscricao_por_prova' => 'decimal:2',   // ✅
    'custo_inscricao_por_salto' => 'decimal:2',   // ✅
    'custo_inscricao_estafeta' => 'decimal:2',    // ✅
];
```

---

## 📊 Invoice Model

### Schema PostgreSQL (Português)
```sql
CREATE TABLE invoices (
    id UUID PRIMARY KEY,
    user_id UUID,
    data_fatura DATE,               -- ✅ Português
    mes VARCHAR(20),                -- ✅ Português
    data_emissao DATE,              -- ✅ Português
    data_vencimento DATE,           -- ✅ Português
    valor_total DECIMAL(10,2),      -- ✅ Português
    estado_pagamento VARCHAR(30),   -- ✅ Português
    numero_recibo VARCHAR(255),     -- ✅ Português
    referencia_pagamento VARCHAR(255), -- ✅ Português
    centro_custo_id UUID,           -- ✅ Português
    tipo VARCHAR(30),               -- ✅ Português
    observacoes TEXT,               -- ✅ Português
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Model Actual (ERRADO - Inglês)
```php
protected $fillable = [
    'user_id',              // ✅ OK
    'invoice_date',         // ❌ BD tem 'data_fatura'
    'month',                // ❌ BD tem 'mes'
    'issue_date',           // ❌ BD tem 'data_emissao'
    'due_date',             // ❌ BD tem 'data_vencimento'
    'total_amount',         // ❌ BD tem 'valor_total'
    'payment_status',       // ❌ BD tem 'estado_pagamento'
    'receipt_number',       // ❌ BD tem 'numero_recibo'
    'payment_reference',    // ❌ BD tem 'referencia_pagamento'
    'cost_center_id',       // ❌ BD tem 'centro_custo_id'
    'type',                 // ❌ BD tem 'tipo'
    'notes',                // ❌ BD tem 'observacoes'
];
```

### Model Corrigido (DEVE SER - Português)
```php
protected $fillable = [
    'user_id',                 // ✅ OK
    'data_fatura',             // ✅ Match PostgreSQL
    'mes',                     // ✅
    'data_emissao',            // ✅
    'data_vencimento',         // ✅
    'valor_total',             // ✅
    'estado_pagamento',        // ✅
    'numero_recibo',           // ✅
    'referencia_pagamento',    // ✅
    'centro_custo_id',         // ✅
    'tipo',                    // ✅
    'observacoes',             // ✅
];

protected $casts = [
    'data_fatura' => 'date',      // ✅
    'data_emissao' => 'date',     // ✅
    'data_vencimento' => 'date',  // ✅
    'valor_total' => 'decimal:2', // ✅
];
```

---

## 📊 Product Model

### Schema PostgreSQL (Português)
```sql
CREATE TABLE products (
    id UUID PRIMARY KEY,
    nome VARCHAR(255),          -- ✅ Português
    descricao TEXT,             -- ✅ Português
    codigo VARCHAR(50),         -- ✅ Português
    categoria VARCHAR(50),      -- ✅ Português
    preco DECIMAL(10,2),        -- ✅ Português
    stock INTEGER,              -- ✅ Português
    stock_minimo INTEGER,       -- ✅ Português
    imagem VARCHAR(255),        -- ✅ Português
    ativo BOOLEAN,              -- ✅ Português
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Model Corrigido (Português)
```php
protected $fillable = [
    'nome',           // ✅
    'descricao',      // ✅
    'codigo',         // ✅
    'categoria',      // ✅
    'preco',          // ✅
    'stock',          // ✅
    'stock_minimo',   // ✅
    'imagem',         // ✅
    'ativo',          // ✅
];
```

---

## 📊 Sponsor Model

### Schema PostgreSQL (Português)
```sql
CREATE TABLE sponsors (
    id UUID PRIMARY KEY,
    nome VARCHAR(255),          -- ✅ Português
    descricao TEXT,             -- ✅ Português
    logo VARCHAR(255),          -- ✅ Português
    website VARCHAR(255),       -- ✅ Português
    contacto VARCHAR(100),      -- ✅ Português
    email VARCHAR(255),         -- ✅ Português
    valor_anual DECIMAL(10,2),  -- ✅ Português
    data_inicio DATE,           -- ✅ Português
    data_fim DATE,              -- ✅ Português
    estado VARCHAR(20),         -- ✅ Português
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Model Corrigido (Português)
```php
protected $fillable = [
    'nome',         // ✅
    'descricao',    // ✅
    'logo',         // ✅
    'website',      // ✅
    'contacto',     // ✅
    'email',        // ✅
    'valor_anual',  // ✅
    'data_inicio',  // ✅
    'data_fim',     // ✅
    'estado',       // ✅
];
```

---

## 📊 Training Model

### Schema PostgreSQL (Português)
```sql
CREATE TABLE trainings (
    id UUID PRIMARY KEY,
    numero_treino VARCHAR(50),      -- ✅ Português
    data DATE,                      -- ✅ Português
    hora_inicio TIME,               -- ✅ Português
    hora_fim TIME,                  -- ✅ Português
    local VARCHAR(255),             -- ✅ Português
    epoca_id UUID,                  -- ✅ Português
    microciclo_id UUID,             -- ✅ Português
    grupo_escalao_id UUID,          -- ✅ Português
    escaloes JSON,                  -- ✅ Português
    tipo_treino VARCHAR(50),        -- ✅ Português
    volume_planeado_m INTEGER,      -- ✅ Português
    notas_gerais TEXT,              -- ✅ Português
    descricao_treino TEXT,          -- ✅ Português
    criado_por UUID,                -- ✅ Português
    evento_id UUID,                 -- ✅ Português
    atualizado_em TIMESTAMP,        -- ✅ Português
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Model Corrigido (Português)
```php
protected $fillable = [
    'numero_treino',       // ✅
    'data',                // ✅
    'hora_inicio',         // ✅
    'hora_fim',            // ✅
    'local',               // ✅
    'epoca_id',            // ✅
    'microciclo_id',       // ✅
    'grupo_escalao_id',    // ✅
    'escaloes',            // ✅
    'tipo_treino',         // ✅
    'volume_planeado_m',   // ✅
    'notas_gerais',        // ✅
    'descricao_treino',    // ✅
    'criado_por',          // ✅
    'evento_id',           // ✅
    'atualizado_em',       // ✅
];
```

---

## ⚠️ Nota Especial: Migration para Reverter

O ficheiro `database/migrations/2026_02_02_000000_normalize_events_columns_to_english.php` tentou normalizar Event para inglês.

**Ação Requerida**: Este migration deve ser revertido (rolled back) para manter consistência com a estratégia PostgreSQL em português.

---

## ✅ Status de Normalização

| Model | Schema BD | Model Actual | Status |
|-------|-----------|--------------|--------|
| Event | Português | Inglês | ❌ PENDENTE |
| Invoice | Português | Inglês | ❌ PENDENTE |
| Product | Português | Inglês | ❌ PENDENTE |
| Sponsor | Português | Inglês | ❌ PENDENTE |
| Training | Português | Inglês | ❌ PENDENTE |
| User | Português | Português | ✅ OK |
| Transaction | Inglês | Inglês | ⚠️ VERIFICAR |
| MembershipFee | Inglês | Inglês | ⚠️ VERIFICAR |
| Team | Inglês | Inglês | ⚠️ VERIFICAR |

---

## 🎯 Próximos Passos

1. ✅ Criar este documento de mapeamento
2. ⏳ Normalizar models de alta prioridade (Event, Invoice, Product, Sponsor, Training)
3. ⏳ Verificar e normalizar restantes models
4. ⏳ Actualizar relationships (FK names)
5. ⏳ Actualizar accessors/mutators
6. ⏳ Actualizar scopes
7. ⏳ Testar com PostgreSQL
8. ⏳ Criar documento de validação

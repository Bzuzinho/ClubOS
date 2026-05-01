# Validação de Campos: User Model

## 🚨 Problema Crítico Identificado

O Model `User` tem **DUPLICAÇÃO MASSIVA** de campos em Inglês e Português, causando:
- Confusão sobre qual campo usar
- Bugs de dados não gravados (ex: `member_type` vs `tipo_membro`)
- Desperdício de espaço na tabela
- Inconsistência de código

## 📊 Campos Duplicados

### Informação Básica do Membro

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `member_number` | `numero_socio` | string | ID único do sócio |
| `full_name` | `nome_completo` | string | Nome completo |
| `profile` | `perfil` | string | Perfil (ex: Atleta, Dirigente) |
| **`member_type`** | **`tipo_membro`** | **JSON array** | **⚠️ CRÍTICO: Cast como array na linha 184** |
| `status` | `estado` | string | Estado ativo/inativo |

### Dados Pessoais

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `birth_date` | `data_nascimento` | date | Data de nascimento |
| `is_minor` | `menor` | boolean | Menor de idade |
| `gender` | `sexo` | string | Sexo/Género |
| `age_groups` | `escalao` | JSON array | Escalões |
| `nationality` | `nacionalidade` | string | Nacionalidade |
| `marital_status` | `estado_civil` | string | Estado civil |
| `occupation` | `ocupacao` | string | Profissão |
| `company` | `empresa` | string | Empresa onde trabalha |
| `school` | `escola` | string | Escola |
| `siblings_count` | `numero_irmaos` | integer | Número de irmãos |

### Contactos

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `address` | `morada` | string | Morada |
| `postal_code` | `codigo_postal` | string | Código postal |
| `city` | `localidade` | string | Cidade/Localidade |
| `phone` | `contacto` | string | Telefone |
| `mobile` | `telemovel` | string | Telemóvel |
| `contact` | `contacto_telefonico` | string | Contacto telefónico |
| `secondary_email` | `email_secundario` | string | Email secundário |

### Saúde

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `health_number` | `numero_utente` | string | Número de utente |
| `emergency_contact_name` | `contacto_emergencia_nome` | string | Nome contato emergência |
| `emergency_contact_phone` | `contacto_emergencia_telefone` | string | Telefone emergência |
| `emergency_contact_relationship` | `contacto_emergencia_relacao` | string | Relação com emergência |
| `medical_certificate_date` | `data_atestado_medico` | date | Data atestado médico |
| `medical_certificate_files` | `arquivo_atestado_medico` | JSON array | Ficheiros atestado |
| `medical_information` | `informacoes_medicas` | text | Informações médicas |

### Documentos e Consentimentos

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `profile_photo` | `foto_perfil` | string | Foto de perfil |
| `id_card_number` / `cc` | `cc` | string | Cartão cidadão (manter português) |
| `gdpr_consent` | `rgpd` | boolean | Consentimento RGPD |
| `consent` | `consentimento` | boolean | Consentimento geral |
| `affiliation` | `afiliacao` | boolean | Afiliação |
| `transport_declaration` | `declaracao_de_transporte` | boolean | Declaração transporte |
| `gdpr_date` | `data_rgpd` | date | Data RGPD |
| `gdpr_file` | `arquivo_rgpd` | string | Ficheiro RGPD |
| `consent_date` | `data_consentimento` | date | Data consentimento |
| `consent_file` | `arquivo_consentimento` | string | Ficheiro consentimento |
| `affiliation_date` | `data_afiliacao` | date | Data afiliação |
| `affiliation_file` | `arquivo_afiliacao` | string | Ficheiro afiliação |
| `transport_declaration_file` | `declaracao_transporte` | string | Ficheiro declaração |

### Desporto

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `sports_active` | `ativo_desportivo` | boolean | Ativo desportivamente |
| `federation_number` | `num_federacao` | string | Número federação |
| `federation_card` | `cartao_federacao` | string | Cartão federação |
| `pmb_number` | `numero_pmb` | string | Número PMB |

### Financeiro

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `membership_fee_type` | `tipo_mensalidade` | string | Tipo de mensalidade |
| `current_account` | `conta_corrente` | decimal | Conta corrente |
| `cost_centers` | `centro_custo` | JSON array | Centros de custo |

### Inscrição

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `registration_date` | `data_inscricao` | date | Data inscrição |
| `registration_file` | `inscricao` | string | Ficheiro inscrição |

### Família

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `guardians` | `encarregado_educacao` | JSON array | Encarregados educação |
| `dependents` | `educandos` | JSON array | Educandos |

### Autenticação (campos especiais)

| Campo Inglês (REMOVER) | Campo Português (MANTER) | Tipo | Notas |
|------------------------|--------------------------|------|-------|
| `user_email` | `email_utilizador` | string | Email do utilizador |
| `user_password` | `senha` | string | Password (manter ambos hidden) |

## 🎯 Plano de Ação

### 1. Atualizar Model User
```php
protected $fillable = [
    // Core Laravel fields (manter em inglês)
    'name',
    'email',
    'password',
    
    // TODOS os campos em PORTUGUÊS apenas
    'numero_socio',
    'nome_completo',
    'perfil',
    'tipo_membro',          // ⚠️ CRÍTICO
    'estado',
    'data_nascimento',
    'menor',
    'sexo',
    'escalao',
    'rgpd',
    'consentimento',
    'afiliacao',
    'declaracao_de_transporte',
    'ativo_desportivo',
    'morada',
    'codigo_postal',
    'localidade',
    'contacto',
    'telemovel',
    'nif',
    'cc',
    'numero_utente',
    'contacto_emergencia_nome',
    'contacto_emergencia_telefone',
    'contacto_emergencia_relacao',
    'foto_perfil',
    'nacionalidade',
    'estado_civil',
    'ocupacao',
    'empresa',
    'escola',
    'numero_irmaos',
    'email_secundario',
    'encarregado_educacao',
    'educandos',
    'contacto_telefonico',
    'tipo_mensalidade',
    'conta_corrente',
    'centro_custo',
    'num_federacao',
    'cartao_federacao',
    'numero_pmb',
    'data_inscricao',
    'inscricao',
    'data_atestado_medico',
    'arquivo_atestado_medico',
    'informacoes_medicas',
    'data_rgpd',
    'arquivo_rgpd',
    'data_consentimento',
    'arquivo_consentimento',
    'data_afiliacao',
    'arquivo_afiliacao',
    'declaracao_transporte',
    'email_utilizador',
    'senha',
];
```

### 2. Atualizar Casts
```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        // Dates
        'data_nascimento' => 'date',
        'data_inscricao' => 'date',
        'data_atestado_medico' => 'date',
        'data_rgpd' => 'date',
        'data_consentimento' => 'date',
        'data_afiliacao' => 'date',
        // Booleans
        'menor' => 'boolean',
        'rgpd' => 'boolean',
        'consentimento' => 'boolean',
        'afiliacao' => 'boolean',
        'declaracao_de_transporte' => 'boolean',
        'ativo_desportivo' => 'boolean',
        // JSON
        'tipo_membro' => 'array',          // ⚠️ CRÍTICO
        'escalao' => 'array',
        'encarregado_educacao' => 'array',
        'educandos' => 'array',
        'centro_custo' => 'array',
        'arquivo_atestado_medico' => 'array',
        // Decimals
        'conta_corrente' => 'decimal:2',
        // Integers
        'numero_irmaos' => 'integer',
    ];
}
```

### 3. Atualizar Controllers
Procurar e substituir em **TODOS** os controllers:
- `member_type` → `tipo_membro`
- `member_number` → `numero_socio`
- `full_name` → `nome_completo`
- etc...

### 4. Atualizar Frontend (TSX)
Procurar e substituir em **resources/js/**:
- `member_type` → `tipo_membro`
- `memberType` → `tipoMembro`
- etc...

### 5. Verificar Migrações
Garantir que as colunas na base de dados usam nomes **portugueses**.

## ⚠️ Campo Crítico: tipo_membro

**Problema atual**:
- Cast: `'member_type' => 'array'` (linha 184 - INGLÊS)
- Mas campo correto é: `tipo_membro`

**Solução**:
```php
// ANTES (ERRADO)
'member_type' => 'array',

// DEPOIS (CORRETO)
'tipo_membro' => 'array',
```

## ✅ Validação

Após mudanças:
```bash
# 1. Verificar que não há campos ingleses no fillable
grep -E "(member_type|member_number|full_name|birth_date)" app/Models/User.php
# Resultado esperado: NADA

# 2. Verificar controllers
grep -rn "member_type" app/Http/Controllers/
# Resultado esperado: NADA

# 3. Verificar frontend
grep -rn "member_type" resources/js/
# Resultado esperado: NADA

# 4. Testar criação de membro
php artisan tinker
>>> $user = User::create([
      'tipo_membro' => ['atleta'],
      'nome_completo' => 'Teste',
      // ...
    ]);
>>> $user->tipo_membro; // Deve retornar array ['atleta']
```

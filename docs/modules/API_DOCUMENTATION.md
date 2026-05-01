# API Documentation

## Visão Geral

Esta documentação descreve todos os endpoints da API REST do sistema de gestão do clube BSCN.

**Base URL**: `http://localhost:8000` (desenvolvimento) ou `https://yourdomain.com` (produção)

**Autenticação**: Todas as rotas (exceto login/register) requerem autenticação via Laravel Sanctum.

## Autenticação

### Login
```http
POST /login
Content-Type: application/json

{
  "email": "admin@test.com",
  "password": "password",
  "remember": true
}
```

**Response (200 OK)**:
```json
{
  "user": {
    "id": "uuid",
    "name": "Admin User",
    "email": "admin@test.com",
    "perfil": "admin"
  }
}
```

### Logout
```http
POST /logout
```

**Response (204 No Content)**

### Register
```http
POST /register
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password",
  "password_confirmation": "password"
}
```

## Dashboard

### Get Dashboard Data
```http
GET /dashboard
```

**Response (200 OK)**:
```json
{
  "totalMembers": 150,
  "activeAthletes": 75,
  "upcomingEvents": 5,
  "pendingPayments": 12,
  "recentActivities": [...]
}
```

## Membros (Members)

### List Members
```http
GET /membros
```

**Query Parameters**:
- `search` (string): Pesquisar por nome ou email
- `perfil` (string): Filtrar por perfil (admin, user, atleta)
- `estado` (string): Filtrar por estado (ativo, inativo, suspenso)
- `page` (int): Número da página (paginação)

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": "uuid",
      "numero_socio": "001",
      "nome_completo": "João Silva",
      "email": "joao@example.com",
      "perfil": "atleta",
      "estado": "ativo",
      "data_nascimento": "2005-03-15",
      "sexo": "masculino",
      "created_at": "2024-01-15T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 150,
    "per_page": 15
  }
}
```

### Get Member
```http
GET /membros/{id}
```

**Response (200 OK)**:
```json
{
  "id": "uuid",
  "numero_socio": "001",
  "nome_completo": "João Silva",
  "email": "joao@example.com",
  "perfil": "atleta",
  "estado": "ativo",
  "data_nascimento": "2005-03-15",
  "menor": true,
  "sexo": "masculino",
  "tipo_membro": ["Atleta"],
  "rgpd": true,
  "consentimento": true,
  "afiliacao": true
}
```

### Create Member
```http
POST /membros
Content-Type: application/json

{
  "numero_socio": "150",
  "nome_completo": "Maria Santos",
  "email": "maria@example.com",
  "password": "password123",
  "perfil": "atleta",
  "estado": "ativo",
  "data_nascimento": "2006-07-20",
  "sexo": "feminino",
  "tipo_membro": ["Atleta"],
  "rgpd": true,
  "consentimento": true,
  "afiliacao": true
}
```

**Response (201 Created)**:
```json
{
  "id": "uuid",
  "numero_socio": "150",
  "nome_completo": "Maria Santos",
  ...
}
```

**Validation Errors (422)**:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."],
    "numero_socio": ["The numero socio has already been taken."]
  }
}
```

### Update Member
```http
PUT /membros/{id}
Content-Type: application/json

{
  "nome_completo": "Maria Santos Silva",
  "estado": "ativo"
}
```

**Response (200 OK)**: Member object

### Delete Member
```http
DELETE /membros/{id}
```

**Response (204 No Content)**

## Eventos (Events)

### List Events
```http
GET /eventos
```

**Query Parameters**:
- `tipo` (string): Filtrar por tipo de evento
- `data_inicio` (date): Data início (YYYY-MM-DD)
- `data_fim` (date): Data fim (YYYY-MM-DD)

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": "uuid",
      "nome": "Treino Semanal",
      "tipo": "treino",
      "data_inicio": "2024-02-10T18:00:00Z",
      "data_fim": "2024-02-10T20:00:00Z",
      "local": "Pavilhão Municipal",
      "descricao": "Treino regular da equipa"
    }
  ]
}
```

### Get Event
```http
GET /eventos/{id}
```

### Create Event
```http
POST /eventos
Content-Type: application/json

{
  "nome": "Competição Regional",
  "tipo": "competicao",
  "data_inicio": "2024-03-15T09:00:00Z",
  "data_fim": "2024-03-15T18:00:00Z",
  "local": "Centro Desportivo",
  "descricao": "Competição regional de natação",
  "escalao": ["Juvenis", "Juniores"]
}
```

**Response (201 Created)**

### Update Event
```http
PUT /eventos/{id}
```

### Delete Event
```http
DELETE /eventos/{id}
```

## Desportivo (Sports)

### List Trainings
```http
GET /desportivo/trainings
```

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": "uuid",
      "data": "2024-02-10",
      "hora_inicio": "18:00:00",
      "hora_fim": "20:00:00",
      "tipo": "tecnico",
      "escalao": "Juvenis",
      "local": "Piscina",
      "atletas_count": 15
    }
  ]
}
```

### Create Training
```http
POST /desportivo/trainings
Content-Type: application/json

{
  "data": "2024-02-15",
  "hora_inicio": "18:00:00",
  "hora_fim": "20:00:00",
  "tipo": "tecnico",
  "escalao": "Juvenis",
  "local": "Piscina",
  "descricao": "Treino técnico de crawl"
}
```

## Financeiro (Financial)

### List Invoices
```http
GET /financeiro/invoices
```

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": "uuid",
      "numero": "INV-2024-001",
      "user_id": "uuid",
      "user_name": "João Silva",
      "data_emissao": "2024-02-01",
      "data_vencimento": "2024-02-15",
      "valor_total": 50.00,
      "estado": "pendente"
    }
  ]
}
```

### Create Invoice
```http
POST /financeiro/invoices
Content-Type: application/json

{
  "user_id": "uuid",
  "data_emissao": "2024-02-01",
  "data_vencimento": "2024-02-15",
  "items": [
    {
      "descricao": "Mensalidade Fevereiro",
      "quantidade": 1,
      "preco_unitario": 50.00
    }
  ]
}
```

### Update Invoice Status
```http
PATCH /financeiro/invoices/{id}/status
Content-Type: application/json

{
  "estado": "pago",
  "data_pagamento": "2024-02-05"
}
```

### List Monthly Fees
```http
GET /financeiro/monthly-fees
```

### Generate Monthly Fees
```http
POST /financeiro/monthly-fees/generate
Content-Type: application/json

{
  "mes": 2,
  "ano": 2024,
  "valor": 50.00
}
```

## Loja (Shop/Inventory)

### List Products
```http
GET /loja/products
```

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": "uuid",
      "nome": "T-shirt do Clube",
      "descricao": "T-shirt oficial do clube",
      "preco": 15.00,
      "stock": 50,
      "ativo": true
    }
  ]
}
```

### Create Product
```http
POST /loja/products
Content-Type: application/json

{
  "nome": "Calças de Treino",
  "descricao": "Calças oficiais de treino",
  "preco": 25.00,
  "stock": 30,
  "ativo": true
}
```

### Create Sale
```http
POST /loja/sales
Content-Type: application/json

{
  "user_id": "uuid",
  "items": [
    {
      "product_id": "uuid",
      "quantidade": 2,
      "preco_unitario": 15.00
    }
  ],
  "metodo_pagamento": "dinheiro"
}
```

## Patrocínios (Sponsors)

### List Sponsors
```http
GET /patrocinios
```

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": "uuid",
      "nome": "Empresa ABC",
      "tipo": "principal",
      "valor_anual": 5000.00,
      "data_inicio": "2024-01-01",
      "data_fim": "2024-12-31",
      "ativo": true
    }
  ]
}
```

### Create Sponsor
```http
POST /patrocinios
Content-Type: application/json

{
  "nome": "Empresa XYZ",
  "tipo": "secundario",
  "valor_anual": 2000.00,
  "data_inicio": "2024-02-01",
  "data_fim": "2025-01-31",
  "contacto": "contacto@empresa.com",
  "ativo": true
}
```

## Comunicação (Communication)

### List Communications
```http
GET /comunicacao
```

### Create Communication
```http
POST /comunicacao
Content-Type: application/json

{
  "titulo": "Aviso Importante",
  "mensagem": "Reunião geral no dia 15 de fevereiro",
  "destinatarios": ["todos"],
  "tipo": "email",
  "agendado": false
}
```

## Marketing

### List Campaigns
```http
GET /marketing
```

### Create Campaign
```http
POST /marketing
Content-Type: application/json

{
  "nome": "Campanha Inscrições 2024",
  "descricao": "Campanha de captação de novos atletas",
  "data_inicio": "2024-02-01",
  "data_fim": "2024-02-28",
  "budget": 500.00,
  "tipo": "digital"
}
```

## Configurações (Settings)

### Get Settings
```http
GET /settings
```

**Response (200 OK)**:
```json
{
  "userTypes": [...],
  "ageGroups": [...],
  "eventTypes": [...],
  "costCenters": [...]
}
```

### Create User Type
```http
POST /settings/user-types
Content-Type: application/json

{
  "name": "Técnico",
  "description": "Staff técnico do clube",
  "active": true
}
```

### Update User Type
```http
PUT /settings/user-types/{id}
```

### Delete User Type
```http
DELETE /settings/user-types/{id}
```

### Create Age Group
```http
POST /settings/age-groups
Content-Type: application/json

{
  "name": "Benjamins",
  "description": "8-9 anos",
  "min_age": 8,
  "max_age": 9,
  "active": true
}
```

### Create Event Type
```http
POST /settings/event-types
Content-Type: application/json

{
  "name": "Workshop",
  "description": "Workshop técnico",
  "active": true
}
```

## Códigos de Resposta HTTP

| Código | Descrição |
|--------|-----------|
| 200 | OK - Pedido bem sucedido |
| 201 | Created - Recurso criado com sucesso |
| 204 | No Content - Recurso eliminado com sucesso |
| 400 | Bad Request - Pedido inválido |
| 401 | Unauthorized - Não autenticado |
| 403 | Forbidden - Sem permissões |
| 404 | Not Found - Recurso não encontrado |
| 422 | Unprocessable Entity - Erros de validação |
| 500 | Internal Server Error - Erro no servidor |

## Tratamento de Erros

### Erro de Validação (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

### Erro de Autenticação (401)
```json
{
  "message": "Unauthenticated."
}
```

### Erro de Autorização (403)
```json
{
  "message": "This action is unauthorized."
}
```

### Erro Not Found (404)
```json
{
  "message": "Resource not found."
}
```

## Rate Limiting

A API aplica rate limiting para prevenir abuso:
- **Autenticados**: 60 pedidos por minuto
- **Não autenticados**: 10 pedidos por minuto

Headers de resposta incluem:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1612345678
```

## Paginação

Endpoints que retornam listas suportam paginação:

**Query Parameters**:
- `page` (int): Número da página (default: 1)
- `per_page` (int): Items por página (default: 15, max: 100)

**Response Meta**:
```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 10,
    "per_page": 15,
    "to": 15,
    "total": 150
  },
  "links": {
    "first": "http://api.example.com/resource?page=1",
    "last": "http://api.example.com/resource?page=10",
    "prev": null,
    "next": "http://api.example.com/resource?page=2"
  }
}
```

## Ordenação e Filtragem

Muitos endpoints suportam ordenação e filtragem:

**Query Parameters**:
- `sort` (string): Campo para ordenar (ex: `created_at`, `-created_at` para desc)
- `filter[campo]` (string): Filtrar por campo específico

**Exemplo**:
```http
GET /membros?sort=-created_at&filter[estado]=ativo&filter[perfil]=atleta
```

## Webhooks

(Funcionalidade futura - não implementada ainda)

## Versionamento

Versão atual da API: **v1**

Futuras versões serão disponibilizadas via:
```http
GET /api/v2/resource
```

## Support

Para questões sobre a API:
- Email: support@bscn.com
- Documentação: https://docs.bscn.com
- GitHub Issues: https://github.com/Bzuzinho/spark-original-ui/issues

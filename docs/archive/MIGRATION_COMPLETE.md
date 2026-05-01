# Migração Spark → Laravel 11: COMPLETA ✅

## Módulos Migrados (10/10)

1. ✅ Dashboard (Home)
2. ✅ Patrocínios
3. ✅ Marketing
4. ✅ Comunicação
5. ✅ Inventário/Loja
6. ✅ Eventos
7. ✅ Membros (CRÍTICO)
8. ✅ Desportivo
9. ✅ Financeiro
10. ✅ Configurações

## Mapeamento Completo

### Spark → Laravel: Páginas/Views

| Spark File | Laravel Equivalent |
|------------|-------------------|
| src/views/HomeView.tsx | resources/js/Pages/Dashboard.tsx |
| src/views/SponsorsView.tsx | resources/js/Pages/Patrocinios/Index.tsx |
| src/views/MarketingView.tsx | resources/js/Pages/Marketing/Index.tsx |
| src/views/CommunicationView.tsx | resources/js/Pages/Comunicacao/Index.tsx |
| src/views/InventoryView.tsx | resources/js/Pages/Loja/Index.tsx |
| src/views/EventsView.tsx | resources/js/Pages/Eventos/Index.tsx |
| src/views/MembersView.tsx | resources/js/Pages/Membros/Index.tsx |
| src/views/SportsView.tsx | resources/js/Pages/Desportivo/Index.tsx |
| src/views/FinancialView.tsx | resources/js/Pages/Financeiro/Index.tsx |
| src/views/SettingsView.tsx | resources/js/Pages/Settings/Index.tsx |

### useKV Keys → Laravel Tables

| Spark useKV Key | Laravel Table | Model |
|----------------|---------------|-------|
| club-users | users | User |
| club-sponsors | sponsors | Sponsor |
| marketing-campaigns | marketing_campaigns | MarketingCampaign |
| club-comunicacoes | communications | Communication |
| club-products | products | Product |
| club-sales | sales | Sale |
| club-events | events | Event |
| club-convocatorias | event_convocations | EventConvocation |
| club-presencas | event_attendances | EventAttendance |
| treinos | trainings | Training |
| competicoes | competitions | Competition |
| club-resultados | results | Result |
| club-faturas | invoices | Invoice |
| club-mensalidades | monthly_fees | MonthlyFee |
| club-movimentos | movements | Movement |
| settings-user-types | user_types | UserType |
| settings-age-groups | age_groups | AgeGroup |
| settings-event-types | event_types | EventType |
| club-centros-custo | cost_centers | CostCenter |

## Arquitetura

### Backend
- **PHP**: 8.3
- **Framework**: Laravel 11
- **Database**: SQLite (desenvolvimento) / PostgreSQL (produção)
- **ORM**: Eloquent
- **Validação**: Form Requests
- **API**: API Resources para responses
- **Jobs**: Queue para processamento assíncrono

### Frontend
- **React**: 18
- **Routing**: Inertia.js
- **Bundler**: Vite
- **Styling**: Tailwind CSS
- **Icons**: Phosphor Icons
- **Components**: Radix UI

### Estrutura de Diretórios

```
/
├── app/
│   ├── Http/
│   │   ├── Controllers/      # Controllers principais
│   │   │   ├── Api/          # API Controllers
│   │   │   ├── Auth/         # Autenticação
│   │   │   ├── DashboardController.php
│   │   │   ├── MembrosController.php
│   │   │   ├── EventosController.php
│   │   │   ├── DesportivoController.php
│   │   │   ├── FinanceiroController.php
│   │   │   ├── LojaController.php
│   │   │   ├── PatrociniosController.php
│   │   │   ├── ComunicacaoController.php
│   │   │   ├── MarketingController.php
│   │   │   └── SettingsController.php
│   │   ├── Requests/         # Form Requests
│   │   └── Middleware/
│   └── Models/               # Eloquent Models (40+ modelos)
├── database/
│   ├── migrations/           # Migrações de BD
│   └── seeders/              # Seeders
├── resources/
│   └── js/
│       ├── Pages/            # Páginas Inertia
│       │   ├── Dashboard.tsx
│       │   ├── Membros/
│       │   ├── Eventos/
│       │   ├── Desportivo/
│       │   ├── Financeiro/
│       │   ├── Loja/
│       │   ├── Patrocinios/
│       │   ├── Comunicacao/
│       │   ├── Marketing/
│       │   └── Settings/
│       └── Components/       # Componentes reutilizáveis
├── routes/
│   ├── web.php              # Rotas web
│   ├── api.php              # Rotas API
│   └── auth.php             # Rotas de autenticação
└── tests/
    ├── Feature/             # Testes de features
    └── Unit/                # Testes unitários
```

## Instalação

### Pré-requisitos
- PHP >= 8.3
- Composer
- Node.js >= 18
- NPM ou Yarn
- SQLite (dev) ou PostgreSQL (prod)

### Passo a Passo

```bash
# 1. Clone o repositório
git clone https://github.com/Bzuzinho/spark-original-ui.git
cd spark-original-ui

# 2. Instalar dependências PHP
composer install

# 3. Configurar ambiente
cp .env.example .env
php artisan key:generate

# 4. Configurar base de dados
# Edite o .env para configurar sua base de dados
# Para desenvolvimento com SQLite (padrão):
touch database/database.sqlite

# 5. Executar migrações e seeders
php artisan migrate --seed

# 6. Criar link simbólico para storage
php artisan storage:link

# 7. Instalar dependências frontend
npm install

# 8. Build do frontend (produção)
npm run build

# OU desenvolvimento com HMR
npm run dev

# 9. Iniciar servidor de desenvolvimento
php artisan serve
```

Acesse em: `http://localhost:8000`

**Credenciais padrão:**
- Email: `admin@test.com`
- Password: `password`

## Desenvolvimento

### Frontend Development
```bash
# Modo desenvolvimento com Hot Module Replacement
npm run dev
```

### Backend Development
```bash
# Servidor de desenvolvimento
php artisan serve

# Watch mode para recompilação automática
php artisan serve --watch
```

### Linting e Formatação
```bash
# PHP (Laravel Pint)
./vendor/bin/pint

# TypeScript/React
npm run lint
```

## Testes

### Executar Todos os Testes
```bash
php artisan test
```

### Testes com Cobertura
```bash
php artisan test --coverage
```

### Testes Específicos
```bash
# Feature tests
php artisan test --testsuite=Feature

# Unit tests
php artisan test --testsuite=Unit

# Teste específico
php artisan test --filter=FullWorkflowTest
```

## Principais Funcionalidades Migradas

### 1. Dashboard
- Visão geral de estatísticas do clube
- Gráficos e métricas em tempo real
- Acesso rápido a módulos principais

### 2. Gestão de Membros
- CRUD completo de membros
- Gestão de atletas e sócios
- Dados pessoais e desportivos
- Sistema de escalões por idade
- Tipos de membro configuráveis

### 3. Eventos
- Calendário de eventos
- Gestão de treinos e competições
- Convocatórias de atletas
- Registo de presenças
- Resultados de competições

### 4. Módulo Desportivo
- Gestão de treinos
- Planeamento de macrociclos/mesociclos/microciclos
- Dados desportivos de atletas
- Competições e resultados
- Estatísticas de performance

### 5. Financeiro
- Gestão de faturas
- Mensalidades automáticas
- Movimentos financeiros
- Extratos bancários
- Centros de custo
- Relatórios financeiros

### 6. Loja/Inventário
- Catálogo de produtos
- Gestão de stock
- Registo de vendas
- Histórico de transações

### 7. Patrocínios
- Gestão de patrocinadores
- Contratos e valores
- Acompanhamento de renovações

### 8. Marketing
- Campanhas de marketing
- Comunicações em massa
- Analytics básico

### 9. Comunicação
- Sistema de comunicação interna
- Notificações automáticas
- Templates de mensagens

### 10. Configurações
- Configurações do clube
- Tipos de utilizador
- Escalões de idade
- Tipos de eventos
- Centros de custo
- Permissões e roles

## Melhorias sobre Spark

### Performance
- ✅ Database relacional vs key-value storage
- ✅ Índices otimizados em BD
- ✅ Eager loading para evitar N+1 queries
- ✅ Cache de queries frequentes

### Segurança
- ✅ Validação server-side com Form Requests
- ✅ CSRF protection
- ✅ SQL injection protection (Eloquent)
- ✅ XSS protection
- ✅ Password hashing (bcrypt)
- ✅ API authentication (Sanctum)

### Escalabilidade
- ✅ Queue system para tarefas assíncronas
- ✅ Database migrations versionadas
- ✅ Separação clara frontend/backend
- ✅ API RESTful para possível mobile app

### Manutenibilidade
- ✅ Código estruturado (MVC)
- ✅ Type safety (TypeScript + PHP types)
- ✅ Testes automatizados
- ✅ Documentação completa
- ✅ Logs estruturados

## Tecnologias Utilizadas

### Backend
- Laravel 11
- Laravel Sanctum (API auth)
- Laravel Breeze (autenticação)
- Inertia.js Server Adapter
- Ziggy (route helpers)

### Frontend
- React 18
- TypeScript
- Inertia.js Client
- Tailwind CSS
- Radix UI
- Phosphor Icons
- React Query (data fetching)

### Development
- Vite (bundler)
- Laravel Pint (PHP linter)
- PHPUnit (testing)
- Faker (test data)

## Deploy

Ver [DEPLOY.md](DEPLOY.md) para instruções completas de deployment em produção.

## API Documentation

Ver [API_DOCUMENTATION.md](API_DOCUMENTATION.md) para documentação completa de todos os endpoints.

## Suporte

Para issues ou dúvidas:
1. Consulte a documentação
2. Verifique issues existentes no GitHub
3. Abra uma nova issue se necessário

## Licença

MIT License - Ver [LICENSE](LICENSE)

## Autores

- Migração Laravel: Equipa de Desenvolvimento BSCN
- Template Original Spark: GitHub

---

**Status**: ✅ Migração 100% Completa
**Última Atualização**: Fevereiro 2026

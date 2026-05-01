# ClubOS

Aplicação de gestão de clube construída com Laravel 11, Inertia React, Vite e Tailwind.

## Stack atual

- Backend: Laravel 11, PHP 8.3, Composer
- Frontend: React + Inertia + Vite + Tailwind
- Base de dados: PostgreSQL/Neon quando aplicável; SQLite apenas para cenários locais controlados
- Cache e filas: Redis em ambientes configurados, com fallback local conforme `.env`
- Deploy: Oracle VM via SSH

## Módulos principais

- Membros
- Portal
- Família
- Desportivo
- Financeiro
- Eventos
- Comunicação
- Loja
- Logística
- Patrocínios
- Configurações

## Requisitos

- PHP 8.3
- Composer
- Node.js 20
- npm
- PostgreSQL

## Instalação

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan storage:link
php artisan migrate
```

Ajuste o `.env` antes de migrar, em especial as credenciais de base de dados, cache, filas e mail.

## Desenvolvimento

```bash
php artisan serve
npm run dev
```

O frontend principal vive em `resources/js`, com páginas Inertia em `resources/js/Pages` e componentes reutilizáveis em `resources/js/Components`.

## Build e testes

```bash
php artisan test
npm run build
```

## Deploy

O deploy para a Oracle VM é manual e pressupõe código já revisto, commitado e enviado para `main`.

```bash
npm run deploy:vm
```

Consulte `docs/deploy/DEPLOY.md` e `docs/deploy/DEPLOY_WORKFLOW.md` antes de executar deploy.

## Estrutura do projeto

```text
app/                  Código Laravel
bootstrap/            Bootstrap da aplicação
config/               Configuração
database/             Migrations, seeders e factories
docs/                 Documentação ativa e arquivo histórico
public/               Assets públicos e build Vite
resources/js/         Frontend Inertia React
routes/               Rotas web e API
storage/              Ficheiros gerados pela aplicação
tests/                Testes automatizados
```

## Documentação

- `docs/architecture/` para setup e notas técnicas de arquitetura
- `docs/deploy/` para procedimentos de deploy
- `docs/modules/` para documentação funcional e técnica de módulos ainda ativos
- `docs/archive/` para histórico de migração, relatórios de fase e documentação obsoleta

## 🚢 Deploy em Produção

Ver **[DEPLOY.md](DEPLOY.md)** para instruções completas de deployment incluindo:
- Configuração de servidor (Ubuntu/Nginx)
- PostgreSQL setup
- SSL/HTTPS com Let's Encrypt
- Queue workers com Supervisor
- Backups automáticos
- Estratégia de deploy

## 📊 Testes

O projeto inclui:
- ✅ Testes unitários
- ✅ Testes de features
- ✅ Testes de integração end-to-end
- ✅ Testes de performance

```bash
# Executar todos os testes
php artisan test

# Testes de integração
php artisan test --testsuite=Feature

# Testes de performance
php artisan test --filter=PerformanceTest
```

## 🤝 Contribuir

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## 📄 Licença

Este projeto está licenciado sob a licença MIT - veja o arquivo [LICENSE](LICENSE) para detalhes.

## ✨ Migração de Spark

Este projeto foi migrado de um template Spark original para Laravel 11. Para detalhes completos sobre a migração:

- **[MIGRATION_COMPLETE.md](MIGRATION_COMPLETE.md)** - Documentação da migração
- **[MAPPING.md](MAPPING.md)** - Mapeamento de componentes Spark → Laravel

**Spark Template Resources** © GitHub, Inc. - Licenciado sob MIT

# DATABASE SAFETY AND BACKUP RUNBOOK

## Objetivo

Este runbook define guardrails para impedir destruicao acidental da base de dados e descreve os procedimentos de migracao e backup/restore.

## O que e proibido

- Nunca executar comandos destrutivos em ambiente remoto/protegido:
  - `php artisan migrate:fresh`
  - `php artisan migrate:refresh`
  - `php artisan migrate:reset`
  - `php artisan db:wipe`
- Nunca executar comandos destrutivos com `APP_ENV=production`.
- Nunca executar comandos destrutivos quando `DATABASE_URL` aponta para Neon (`neon.tech`).
- Nunca contornar guardrails alterando flags sem aprovacao operacional.

## Guardrails ativos

- `DB_PROTECT_DESTRUCTIVE_COMMANDS=true` bloqueia comandos destrutivos.
- `DB_ALLOW_DESTRUCTIVE_COMMANDS=false` impede bypass por omissao.
- `DB_DESTRUCTIVE_CONFIRMATION=DESTROY_LOCAL_DATABASE` define token explicito quando o bypass for autorizado em local/testing.
- Em producao, bloqueio e permanente.

## Como correr migrations seguras

1. Aplicar migrations normais (nao destrutivas):

```bash
php artisan migrate --force
```

2. Validar estado antes/depois:

```bash
php artisan migrate:status
php artisan test --filter=DatabaseSafety
```

3. Para reset de ambiente local de desenvolvimento, usar apenas:

```bash
php artisan dev:reset-database --confirm=RESET_LOCAL_DEV
```

Notas:
- O comando `dev:reset-database` recusa execucao se `DATABASE_URL` contiver `neon.tech`.
- Evitar comandos destrutivos nativos para workflows de desenvolvimento.

## Restore via Neon

1. Identificar o backup/snapshot correto no Neon Console.
2. Restaurar para um branch/endpoint de validacao primeiro.
3. Validar integridade de dados essenciais (utilizadores, faturas, pagamentos, eventos).
4. Promover endpoint restaurado para o ambiente alvo apenas apos validacao.
5. Registar incidente e janela de restauracao no historico operacional.

## Backup com pg_dump

Exemplo de backup logico PostgreSQL:

```bash
pg_dump "$DATABASE_URL" \
  --format=custom \
  --no-owner \
  --no-privileges \
  --file=backup-$(date +%Y%m%d-%H%M%S).dump
```

Exemplo de restore local de validacao:

```bash
pg_restore \
  --clean \
  --if-exists \
  --no-owner \
  --no-privileges \
  --dbname="$DATABASE_URL" \
  backup-YYYYMMDD-HHMMSS.dump
```

## Checklist antes de deploy

- [ ] Confirmar `APP_ENV` correto do alvo.
- [ ] Confirmar `DATABASE_URL` do alvo (nao executar destrutivos em Neon/producao).
- [ ] Garantir guardrails ativos no ambiente (`DB_PROTECT_DESTRUCTIVE_COMMANDS=true`).
- [ ] Executar apenas migrations nao destrutivas (`php artisan migrate --force`).
- [ ] Ter backup recente validado (Neon snapshot e/ou `pg_dump`).
- [ ] Definir plano de rollback e responsavel pela execucao.
- [ ] Validar smoke tests apos deploy.

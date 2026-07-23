# Operação DB1 — Migração Neon para PostgreSQL local na Oracle VM

## Objetivo

Migrar a base de dados de produção do ClubOS do Neon remoto para PostgreSQL local na Oracle VM, reduzindo timeouts de ligação e latência em requests críticos como Dashboard, Membros, ficha de membro, gravação e Financeiro.

Esta preparação não apaga a BD Neon, não faz alterações destrutivas e não troca produção automaticamente. A troca final só deve acontecer depois de dump, restore, validações e backup de `.env` confirmados.

## Estado Atual

Produção usa PostgreSQL remoto Neon, identificado nos logs por host `*.neon.tech` com endpoint pooler. Os sintomas observados foram:

- `SQLSTATE[08006] timeout expired`;
- falha IPv4 por timeout;
- falha IPv6 `Network is unreachable`;
- query simples `select * from "club_settings" limit 1` a falhar;
- stack em `ClubSettingsService` dentro de `HandleInertiaRequests`;
- lentidão transversal ao abrir Dashboard, Membros, ficha e gravação.

Desde P1.1, `ClubSettingsService` já tem cache/fallback seguro e existe `system:database-health` para medir a ligação sem expor credenciais.

## Estado Alvo

PostgreSQL local na Oracle VM:

- database: `clubmanager_prod`;
- user: `clubmanager_app`;
- password forte definida só no servidor, nunca commitada;
- acesso local apenas por `127.0.0.1`/`localhost`;
- porta `5432` não exposta publicamente;
- Neon preservado para rollback imediato enquanto a migração é validada.

## Variáveis `.env`

### Antes, Neon

```env
DB_CONNECTION=pgsql
DB_HOST=***.neon.tech
DB_PORT=5432 ou 6543
DB_DATABASE=********
DB_USERNAME=********
DB_PASSWORD=********
DB_SSLMODE=require ou prefer
DB_CONNECT_TIMEOUT=5
```

Se produção usa `DB_URL`, guardar o valor apenas no backup privado de `.env`, nunca em Git.

### Depois, PostgreSQL local

```env
DB_CONNECTION=pgsql
DB_URL=
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=clubmanager_prod
DB_USERNAME=clubmanager_app
DB_PASSWORD=********
DB_SSLMODE=prefer
DB_CONNECT_TIMEOUT=5
```

## Scripts Operacionais

Os scripts vivem em `scripts/ops/database/` e assumem execução na Oracle VM Ubuntu, a partir da raiz do repositório:

- `migrate-neon-to-local-postgres.sh`;
- `install-local-postgres.sh`;
- `backup-neon-production.sh`;
- `restore-local-postgres.sh`;
- `validate-local-postgres.sh`;
- `switch-production-db-to-local.sh`;
- `rollback-production-db-to-neon.sh`;
- `backup-local-postgres.sh`.

Todos usam `set -euo pipefail`, validam variáveis obrigatórias e não imprimem passwords.

O script `migrate-neon-to-local-postgres.sh` é o orquestrador recomendado para a fase final. Ele lê a ligação Neon do `.env` atual da aplicação, valida Neon e PostgreSQL local, compara tabelas/contagens, verifica drift conhecido (`dados_pessoais.telemovel`, `contacto`, `contacto_telefonico`, `estado_civil` e `dados_configuracao.platform_access_enabled`), gera log/relatório em `/var/backups/clubmanager` e bloqueia o switch se houver divergências. Por desenho, não altera `.env` automaticamente.

## Instalação PostgreSQL Local

Definir password local fora do Git:

```bash
export LOCAL_DB_NAME=clubmanager_prod
export LOCAL_DB_USER=clubmanager_app
export LOCAL_DB_PASSWORD='********'
```

Executar:

```bash
bash scripts/ops/database/install-local-postgres.sh
```

O script instala `postgresql` e `postgresql-contrib`, cria role/database se necessário, valida serviço ativo e confirma que o acesso local funciona. Não abre porta externa.

Confirmar manualmente:

```bash
sudo systemctl status postgresql --no-pager
sudo ss -ltnp | grep 5432
```

A configuração esperada é escutar apenas localmente. Não abrir `5432` no firewall/cloud security list.

## Dump Neon

Definir URL de produção Neon apenas no shell da VM:

```bash
export NEON_DATABASE_URL='postgresql://********'
export BACKUP_DIR=/var/backups/clubmanager
```

Executar:

```bash
bash scripts/ops/database/backup-neon-production.sh
```

O script cria:

- dump custom `pg_dump -Fc --no-owner --no-acl`;
- dump schema-only;
- checksum `sha256`;
- permissões restritas via `umask 077`.

Guardar o caminho do ficheiro `.dump` gerado para o restore.

Dump produtivo já criado para a operação DB1 final:

```txt
/var/backups/clubmanager/neon-prod-20260723-163106.dump
sha256: 7837a9cc61c1b4639de204a3cd3dc64b7da14eb6c7eb66095193a970f5861b46
```

Antes de usar este dump, confirmar checksum na VM:

```bash
sha256sum /var/backups/clubmanager/neon-prod-20260723-163106.dump
```

## Restore Local

Com PostgreSQL local criado:

```bash
export LOCAL_DB_NAME=clubmanager_prod
export LOCAL_DB_USER=clubmanager_app
export LOCAL_DB_PASSWORD='********'
export DUMP_PATH=/var/backups/clubmanager/neon-prod-YYYYMMDD-HHMMSS.dump
```

Para restore em BD local recém-criada/vazia:

```bash
export DB1_ALLOW_CLEAN_RESTORE=true
bash scripts/ops/database/restore-local-postgres.sh
```

O script bloqueia hosts que não sejam `127.0.0.1` ou `localhost`, usa `pg_restore --no-owner --no-acl`, executa `ANALYZE` e valida extensões instaladas. Usar `DB1_ALLOW_CLEAN_RESTORE=true` apenas quando a BD local é descartável/recém-preparada.

## Validações Antes do Switch

Comparar Neon vs local quando `NEON_DATABASE_URL` estiver disponível:

```bash
export NEON_DATABASE_URL='postgresql://********'
export LOCAL_DB_NAME=clubmanager_prod
export LOCAL_DB_USER=clubmanager_app
export LOCAL_DB_PASSWORD='********'
bash scripts/ops/database/validate-local-postgres.sh
```

O script compara, sempre que possível:

- total de tabelas;
- `migrations`;
- `users`;
- `dados_pessoais`;
- `invoices`;
- `payments`;
- `payment_allocations`;
- `movements`;
- tabelas de stock/inventário detetadas;
- eventos;
- `club_settings`.

Também executa contra a BD local:

```bash
php artisan migrate:status
php artisan migrate --pretend
php artisan system:database-health --json
php artisan people:audit-member-model --json
php artisan finance:audit-integrations --json
php artisan inventory:audit-store-logistics-stock --json
php artisan test --filter=Auth
php artisan test --filter=Member
php artisan test --filter=Financeiro
php artisan test --filter=DatabaseHealth
```

Não continuar se houver erro de schema, contagens inesperadas ou falhas de login/gravação.

Para a validação final Neon vs local, preferir o orquestrador:

```bash
cd /var/www/clubmanager
export LOCAL_DB_PASSWORD='********'
export DUMP_PATH=/var/backups/clubmanager/neon-prod-20260723-163106.dump
bash scripts/ops/database/migrate-neon-to-local-postgres.sh
```

Se o relatório mostrar divergências entre Neon e local e for necessário refazer o restore da BD local descartável:

```bash
export LOCAL_DB_PASSWORD='********'
export DUMP_PATH=/var/backups/clubmanager/neon-prod-20260723-163106.dump
export DB1_ALLOW_RESTORE_ON_DIVERGENCE=true
export DB1_CONFIRM_RECREATE_LOCAL_DB=RECREATE_LOCAL_DB
bash scripts/ops/database/migrate-neon-to-local-postgres.sh
```

Este modo dropa apenas a base local `clubmanager_prod`, recria-a, restaura o dump indicado e volta a comparar. A BD Neon nunca é alterada.

As contagens suspeitas observadas no primeiro restore local foram:

```txt
users = 79
migrations = 181
club_settings = 1
dados_pessoais = 0
dados_configuracao = 0
dados_financeiros = 78
dados_desportivos = tabela ausente
```

Estas contagens não devem ser tratadas como erro até comparar com Neon real. Se Neon também tiver `dados_pessoais=0` e `dados_configuracao=0`, documentar como estado produtivo atual. Se Neon tiver dados nessas tabelas e local não, o restore local está incorreto e o switch fica bloqueado.

## Troca do `.env` de Produção

Pré-condições obrigatórias:

- dump Neon feito;
- checksum gerado;
- restore local concluído;
- contagens validadas;
- `php artisan migrate --pretend` sem erro;
- auditorias principais sem erro de schema;
- rollback documentado;
- backup `.env` pronto;
- confirmação humana explícita.

Executar:

```bash
export LOCAL_DB_NAME=clubmanager_prod
export LOCAL_DB_USER=clubmanager_app
export LOCAL_DB_PASSWORD='********'
export DB1_CONFIRM_SWITCH_TO_LOCAL_POSTGRES=SWITCH_TO_LOCAL_POSTGRES
bash scripts/ops/database/switch-production-db-to-local.sh
```

O script cria:

```bash
.env.backup-before-local-postgres-YYYYMMDD-HHMMSS
```

Depois altera:

```env
DB_CONNECTION=pgsql
DB_URL=
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=clubmanager_prod
DB_USERNAME=clubmanager_app
DB_PASSWORD=********
DB_SSLMODE=prefer
DB_CONNECT_TIMEOUT=5
```

`DB_URL` deve ficar vazio ou removido, porque um URL Neon antigo pode sobrepor `DB_HOST`/`DB_DATABASE` na configuração Laravel.

E executa:

```bash
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
```

## Validação Pós-Switch

Já com BD local:

```bash
sudo -u www-data php artisan system:database-health --json --report-path=storage/app/audits/db1-local-postgres-health-production.json
sudo -u www-data php artisan system:audit-performance --json --report-path=storage/app/audits/db1-local-postgres-performance-production.json
sudo -u www-data php artisan people:audit-member-model --json --report-path=storage/app/audits/db1-local-postgres-member-model-production.json
```

Validação manual:

1. Entrar na aplicação.
2. Dashboard abre rápido.
3. Clicar em Membros imediatamente.
4. Lista de Membros abre rápido.
5. Abrir ficha de membro.
6. Alterar campo simples.
7. Gravar.
8. Confirmar `estado_civil`.
9. Abrir Financeiro.
10. Confirmar que não há erro `telemovel` no log.
11. Voltar a Membros.

Consultar:

```bash
tail -n 200 storage/logs/laravel.log
```

## Rollback

Rollback imediato se:

- app não arranca;
- migrations/schema falham;
- dados críticos divergem;
- login falha;
- gravação falha;
- latência local fica pior ou instável.

Executar com o backup de `.env` criado no switch:

```bash
export ENV_BACKUP_PATH=.env.backup-before-local-postgres-YYYYMMDD-HHMMSS
export DB1_CONFIRM_ROLLBACK_TO_NEON=ROLLBACK_TO_NEON
bash scripts/ops/database/rollback-production-db-to-neon.sh
```

Rollback restaura `.env`, limpa caches e recarrega PHP-FPM/nginx. Não mexe nos dados locais e não apaga Neon.

## Backups Pós-Migração

Backup local diário:

```bash
export LOCAL_DB_NAME=clubmanager_prod
export LOCAL_DB_USER=clubmanager_app
export LOCAL_DB_PASSWORD='********'
export BACKUP_DIR=/var/backups/clubmanager/local
bash scripts/ops/database/backup-local-postgres.sh
```

Política recomendada:

- dump custom diário;
- retenção local 14 dias;
- checksum SHA256;
- permissões restritas;
- log simples;
- cópia externa encriptada para Oracle Object Storage, S3 compatível, Google Drive/rclone ou destino equivalente;
- teste mensal de restore.

Exemplo cron:

```cron
15 2 * * * cd /var/www/clubos && /usr/bin/env bash scripts/ops/database/backup-local-postgres.sh >> storage/logs/db-backup.log 2>&1
```

Usar ficheiro de ambiente root-only para secrets do cron, nunca Git.

## Checklist Final

- [ ] PostgreSQL instalado localmente.
- [ ] Role `clubmanager_app` criada.
- [ ] Database `clubmanager_prod` criada.
- [ ] Porta 5432 não exposta publicamente.
- [ ] Dump Neon custom criado.
- [ ] Checksum do dump produtivo validado.
- [ ] Schema-only dump criado.
- [ ] Checksum criado.
- [ ] Restore local concluído.
- [ ] `migrate-neon-to-local-postgres.sh` executado na VM.
- [ ] Log `migration-YYYYMMDD-HHMMSS.log` guardado em `/var/backups/clubmanager`.
- [ ] Relatório `migration-YYYYMMDD-HHMMSS.report.tsv` revisto.
- [ ] Contagens Neon vs local comparadas.
- [ ] `dados_pessoais`/`dados_configuracao` a zero justificado por comparação real contra Neon ou restore refeito.
- [ ] `migrate:status` OK.
- [ ] `migrate --pretend` OK.
- [ ] `system:database-health` OK contra local.
- [ ] Auditorias principais sem erro de schema.
- [ ] Testes focados OK.
- [ ] `.env` backup criado.
- [ ] Switch executado com confirmação explícita.
- [ ] Validação manual pós-switch OK.
- [ ] Logs sem erro `telemovel` e sem timeout Neon.
- [ ] Backup local diário configurado.
- [ ] Cópia externa encriptada definida.
- [ ] Rollback testado ou ensaiado documentalmente.

# Operação DB1 — Migração Neon para PostgreSQL local na Oracle VM

## Objetivo

Migrar a base de dados de produção do ClubOS do Neon remoto para PostgreSQL local na Oracle VM, reduzindo timeouts de ligação e latência em requests críticos como Dashboard, Membros, ficha de membro, gravação e Financeiro.

Esta preparação não apaga a BD Neon, não faz alterações destrutivas e não troca produção automaticamente. A troca final só deve acontecer depois de dump, restore, validações e backup de `.env` confirmados.

## Estado Atual

A produção foi migrada e validada em PostgreSQL 17 local na Oracle VM:

- host `127.0.0.1`;
- porta `5433`;
- database `clubmanager_prod`;
- user `clubmanager_app`;
- aplicação funcional e rápida.

O Neon já não é a base de dados ativa. Mantém-se temporariamente como fallback durante o período inicial pós-migração, sem receber os backups diários locais.

Antes da migração, a produção usava PostgreSQL remoto Neon, identificado nos logs por host `*.neon.tech` com endpoint pooler. Os sintomas observados foram:

- `SQLSTATE[08006] timeout expired`;
- falha IPv4 por timeout;
- falha IPv6 `Network is unreachable`;
- query simples `select * from "club_settings" limit 1` a falhar;
- stack em `ClubSettingsService` dentro de `HandleInertiaRequests`;
- lentidão transversal ao abrir Dashboard, Membros, ficha e gravação.

Desde P1.1, `ClubSettingsService` já tem cache/fallback seguro e existe `system:database-health` para medir a ligação sem expor credenciais.

## Estado Alvo

PostgreSQL local na Oracle VM, estado já atingido:

- database: `clubmanager_prod`;
- user: `clubmanager_app`;
- password forte definida só no servidor, nunca commitada;
- acesso local apenas por `127.0.0.1`/`localhost`;
- porta `5433` não exposta publicamente;
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
DB_PORT=5433
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
- `backup-local-postgres.sh`;
- `install-local-postgres-backup-cron.sh`.

Todos usam `set -euo pipefail`, validam variáveis obrigatórias e não imprimem passwords.

O script `migrate-neon-to-local-postgres.sh` é o orquestrador recomendado para a fase final. Ele lê a ligação Neon do `.env` atual da aplicação, valida Neon e PostgreSQL local, compara tabelas/contagens, verifica drift conhecido (`dados_pessoais.telemovel`, `contacto`, `contacto_telefonico`, `estado_civil` e `dados_configuracao.platform_access_enabled`), gera log/relatório em `/var/backups/clubmanager` e bloqueia o switch se houver divergências. Por desenho, não altera `.env` automaticamente.

Desde DB1.1, este script exige PostgreSQL 17 efetivo para a validação/restauro produtivo:

- usa por defeito `/usr/lib/postgresql/17/bin/psql`;
- usa por defeito `/usr/lib/postgresql/17/bin/pg_restore`;
- usa por defeito `/usr/lib/postgresql/17/bin/pg_dump`;
- regista no log os caminhos e versões efetivas dos binários;
- regista no log a versão do servidor Neon e do servidor local;
- bloqueia se o servidor local tiver major inferior ao servidor Neon;
- bloqueia se `pg_restore` tiver major inferior ao servidor Neon.

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

### DB1.1 — PostgreSQL 17 server obrigatório

A primeira execução real do orquestrador bloqueou corretamente o switch porque a BD local apontada estava em PostgreSQL 14:

```txt
Neon PostgreSQL: 17.10
Local PostgreSQL usado pelo script: 14.23
Local target: 127.0.0.1:5432/clubmanager_prod
Client psql: 17.10
pg_restore usado: 14.23
Neon total tables: 140
Local total tables: 139
Divergent table count: 8
```

Divergências críticas observadas antes da correção do ambiente local:

```txt
athlete_sports_data: Neon 19 / Local 0
centro_custo_user: Neon 5 / Local 78
dados_configuracao: Neon 79 / Local 0
dados_financeiros: Neon 37 / Local 78
dados_pessoais: Neon 79 / Local 0
migrations: Neon 183 / Local 181
stock_movements: Neon 28 / Local 25
user_guardian: Neon 8 / Local 7
```

Isto significa que a BD local `clubmanager_prod` anterior não é válida para switch. Não trocar `.env`, não apagar Neon e não apagar dumps.

Ver clusters locais:

```bash
pg_lsclusters
```

Se só existir PostgreSQL 14 em `5432`, instalar/ativar PostgreSQL 17 e usar a porta do cluster 17, por exemplo `5433` se `5432` continuar ocupada pelo 14. A porta real deve ser passada em `LOCAL_DB_PORT` e depois usada também no script de switch.

Trocar a password local exposta por uma password forte nova, definida apenas no shell da VM:

```bash
export LOCAL_DB_PASSWORD='********'
```

Recriar apenas a BD local no cluster PostgreSQL 17 e restaurar o dump válido:

```bash
cd /var/www/clubmanager
export LOCAL_DB_PORT=5433
export LOCAL_DB_PASSWORD='********'
export DUMP_PATH=/var/backups/clubmanager/neon-prod-20260723-163106.dump
export DB1_PREPARE_LOCAL_PG17=true
export DB1_CONFIRM_RECREATE_LOCAL_DB=RECREATE_LOCAL_DB
bash scripts/ops/database/migrate-neon-to-local-postgres.sh
```

O modo `DB1_PREPARE_LOCAL_PG17=true`:

- atualiza/cria apenas a role local `clubmanager_app` com a password fornecida;
- dropa apenas a BD local `clubmanager_prod` no cluster/porta indicada;
- recria `clubmanager_prod` com owner `clubmanager_app`;
- garante ownership/privileges do schema `public`;
- restaura o dump com `pg_restore` 17;
- executa `ANALYZE`;
- compara Neon/local;
- não altera `.env`;
- não mexe na BD Neon.

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
export LOCAL_DB_PORT=5433
export LOCAL_DB_PASSWORD='********'
export DUMP_PATH=/var/backups/clubmanager/neon-prod-20260723-163106.dump
bash scripts/ops/database/migrate-neon-to-local-postgres.sh
```

Se o relatório mostrar divergências entre Neon e local e for necessário refazer o restore da BD local descartável no PostgreSQL 17:

```bash
export LOCAL_DB_PORT=5433
export LOCAL_DB_PASSWORD='********'
export DUMP_PATH=/var/backups/clubmanager/neon-prod-20260723-163106.dump
export DB1_PREPARE_LOCAL_PG17=true
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

Após DB1.1, o critério esperado para a comparação restaurada em PostgreSQL 17 é:

```txt
Neon total tables = Local total tables
migrations: Neon 183 / Local 183
users: Neon 79 / Local 79
dados_pessoais: Neon 79 / Local 79
dados_configuracao: Neon 79 / Local 79
dados_financeiros: Neon 37 / Local 37
athlete_sports_data: Neon 19 / Local 19
stock_movements: Neon 28 / Local 28
user_guardian: Neon 8 / Local 8
```

Também confirmar no log:

- `platform_access_enabled` existe localmente;
- `estado_civil` existe localmente;
- `telemovel` continua inexistente localmente quando também inexistente no Neon;
- divergências zero, ou apenas divergências justificadas e não críticas.

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
export LOCAL_DB_PORT=<porta PostgreSQL 17 validada>
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
DB_PORT=5433
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

O backup diário local usa as credenciais do `.env` produtivo, cria dumps custom PostgreSQL em
`/var/backups/clubmanager/postgres-local` e mantém estritamente os 7 dumps mais recentes.
Cada dump `clubmanager-prod-YYYYMMDD-HHMMSS.dump` tem um checksum
`.dump.sha256` correspondente. O script usa `flock` para impedir execuções sobrepostas,
prefere `/usr/lib/postgresql/17/bin/pg_dump`, não imprime a password e não altera a BD.

Preparar permissões na VM:

```bash
cd /var/www/clubmanager
chmod +x scripts/ops/database/backup-local-postgres.sh
chmod +x scripts/ops/database/install-local-postgres-backup-cron.sh
```

Executar e validar manualmente:

```bash
cd /var/www/clubmanager
sudo scripts/ops/database/backup-local-postgres.sh
ls -lh /var/backups/clubmanager/postgres-local
find /var/backups/clubmanager/postgres-local -name 'clubmanager-prod-*.dump' | wc -l
sha256sum -c /var/backups/clubmanager/postgres-local/*.sha256
```

O número devolvido pelo `find` deve ser no máximo 7.

Instalar ou atualizar o cron root, de forma idempotente:

```bash
cd /var/www/clubmanager
sudo scripts/ops/database/install-local-postgres-backup-cron.sh
sudo crontab -l
```

O cron instalado corre diariamente às `02:15 UTC`:

```cron
15 2 * * * /var/www/clubmanager/scripts/ops/database/backup-local-postgres.sh >> /var/log/clubmanager-postgres-backup.log 2>&1
```

### Teste seguro de restore

Testar periodicamente o dump mais recente numa base temporária. Estes comandos nunca
devem apontar para `clubmanager_prod`:

```bash
cd /var/www/clubmanager
LATEST_DUMP="$(find /var/backups/clubmanager/postgres-local -maxdepth 1 -type f -name 'clubmanager-prod-*.dump' -printf '%T@ %p\n' | sort -rn | head -n 1 | cut -d' ' -f2-)"
sudo -u postgres /usr/lib/postgresql/17/bin/createdb --port=5433 clubmanager_restore_test
sudo -u postgres /usr/lib/postgresql/17/bin/pg_restore --port=5433 --dbname=clubmanager_restore_test --no-owner --no-acl "${LATEST_DUMP}"
sudo -u postgres /usr/lib/postgresql/17/bin/psql --port=5433 --dbname=clubmanager_restore_test --tuples-only --command="select count(*) from information_schema.tables where table_schema = 'public';"
sudo -u postgres /usr/lib/postgresql/17/bin/dropdb --port=5433 clubmanager_restore_test
```

Antes do teste, confirmar que `LATEST_DUMP` não está vazio. Se o restore falhar, apagar
apenas `clubmanager_restore_test` e investigar; nunca limpar ou restaurar sobre produção.
Não existe envio para serviços externos nesta fase.

## Checklist Final

- [ ] PostgreSQL instalado localmente.
- [ ] Role `clubmanager_app` criada.
- [ ] Database `clubmanager_prod` criada.
- [x] Produção em PostgreSQL 17 local, porta 5433.
- [ ] Porta 5433 não exposta publicamente.
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
- [ ] Backup local diário instalado e execução manual validada na VM.
- [ ] Retenção confirmada com no máximo 7 dumps.
- [ ] Checksums validados com `sha256sum -c`.
- [ ] Restore temporário `clubmanager_restore_test` validado.
- [ ] Rollback testado ou ensaiado documentalmente.

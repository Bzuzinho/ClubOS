# Operações de Servidor — ClubOS

Este documento resume as operações produtivas do ClubOS. O detalhe do deployment está em `docs/deploy/DEPLOY_WORKFLOW.md`.

## 1. Regra de deployment

O deployment normal é automatizado pela CI após integração em `main`.

Não executar como rotina produtiva:

```bash
git pull
git reset --hard
composer install
npm run build
php artisan migrate --force
```

diretamente em `/var/www/clubmanager`.

Após H0.1b esse path é um symlink para uma release imutável e deixa de ser uma working tree Git.

## 2. Estado da release

```bash
readlink -f /var/www/clubmanager
cat /var/www/clubmanager/.clubos-release
readlink -f /var/www/clubmanager.deploy/current
readlink -f /var/www/clubmanager.deploy/previous
ls -lah /var/www/clubmanager.deploy/releases
```

## 3. Healthcheck

```bash
sudo /usr/local/bin/clubmanager-healthcheck.sh /var/www/clubmanager
```

O healthcheck exige `GET /up = HTTP 200` através do Nginx e PHP-FPM reais. Um redirect HTTP não é aceite como sucesso.

## 4. Rollback de código

```bash
sudo /usr/local/bin/clubmanager-rollback-release.sh \
  /var/www/clubmanager \
  www-data \
  www-data
```

O rollback troca a release atual pela `previous`, recompõe caches, recarrega PHP-FPM e valida `/up`.

O rollback de código não reverte migrations. Alterações de schema produtivas devem usar expand/contract e manter compatibilidade com a release anterior.

## 5. Estado persistente

A configuração e dados de filesystem que sobrevivem a releases estão em:

```txt
/var/www/clubmanager.deploy/shared/.env
/var/www/clubmanager.deploy/shared/storage
```

Na release atual:

```txt
.env     -> shared/.env
storage  -> shared/storage
public/storage -> shared/storage/app/public
```

Nunca guardar uploads ou configuração persistente apenas dentro de `releases/<id>`.

## 6. Scheduler e backups

Os crons existentes continuam a usar o path de compatibilidade `/var/www/clubmanager`, pelo que seguem automaticamente a release atual.

Verificar:

```bash
sudo crontab -u www-data -l
sudo crontab -u ubuntu -l
sudo crontab -u root -l
```

O backup PostgreSQL local continua em:

```txt
/var/backups/clubmanager/postgres-local
```

A H0.2 trata a cópia off-site, retenção alargada, restore test e alertas.

## 7. Logs

Laravel:

```bash
tail -n 100 /var/www/clubmanager/storage/logs/laravel.log
```

Nginx:

```bash
sudo tail -n 100 /var/log/nginx/error.log
```

PHP-FPM:

```bash
sudo journalctl -u php8.3-fpm -n 100 --no-pager
```

## 8. Aplicação no servidor em futuras sprints

No resumo de qualquer desenvolvimento indicar sempre:

- se existem migrations;
- se as migrations são backward-compatible;
- impacto em `.env` ou storage partilhado;
- necessidade de reiniciar workers/queues;
- validações pós-deploy específicas;
- se o rollback de código é seguro perante o schema novo.

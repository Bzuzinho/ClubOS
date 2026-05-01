# Desportivo Cutover - Backup and Rollback

Date: 2026-03-12

## Mandatory pre-cutover backup

Use a full SQL dump before running cutover migrations:

```bash
pg_dump --dbname="${DATABASE_URL}" --format=p --no-owner --no-privileges --file=backup_desportivo_cutover_$(date +%Y%m%d_%H%M%S).sql
```

If using local Laravel env vars:

```bash
PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE" \
  --format=p --no-owner --no-privileges \
  --file="backup_desportivo_cutover_$(date +%Y%m%d_%H%M%S).sql"
```

## Rollback plan

1. Put app in maintenance mode.
2. Restore backup into target DB.
3. Checkout previous app revision.
4. Clear Laravel caches.

```bash
php artisan down

# Restore (example)
psql "$DATABASE_URL" < backup_desportivo_cutover_YYYYMMDD_HHMMSS.sql

git checkout <previous_commit_or_tag>
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan up
```

## Rollback SQL helper

A helper rollback SQL script is available at:
- `database/sql/desportivo_cutover_rollback_helper.sql`

This helper is non-destructive and intended only for quick emergency toggles; full restore from backup remains the authoritative rollback method.

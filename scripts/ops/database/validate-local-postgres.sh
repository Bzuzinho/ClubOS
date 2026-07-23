#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
require_var LOCAL_DB_PASSWORD

[[ "${LOCAL_DB_HOST}" == "127.0.0.1" || "${LOCAL_DB_HOST}" == "localhost" ]] || die "Refusing to validate non-local target: ${LOCAL_DB_HOST}"
command -v psql >/dev/null 2>&1 || die "psql is required"
command -v php >/dev/null 2>&1 || die "php is required"

LOCAL_PSQL=(psql -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 -At)

count_table_local() {
    local table="$1"
    PGPASSWORD="${LOCAL_DB_PASSWORD}" "${LOCAL_PSQL[@]}" -c "select case when to_regclass('${table}') is null then 'missing' else (select count(*)::text from ${table}) end;"
}

count_table_neon() {
    local table="$1"
    [[ -n "${NEON_DATABASE_URL:-}" ]] || { printf 'not_configured'; return; }
    psql "${NEON_DATABASE_URL}" -v ON_ERROR_STOP=1 -At -c "select case when to_regclass('${table}') is null then 'missing' else (select count(*)::text from ${table}) end;"
}

log "Comparing key table counts"
printf '%-32s %-16s %-16s\n' "table" "local" "neon"
for table in migrations users dados_pessoais invoices payments payment_allocations movements stock_movements products events club_settings; do
    printf '%-32s %-16s %-16s\n' "${table}" "$(count_table_local "${table}")" "$(count_table_neon "${table}")"
done

log "Counting local tables"
PGPASSWORD="${LOCAL_DB_PASSWORD}" "${LOCAL_PSQL[@]}" -c "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE';"

log "Running Laravel checks against local PostgreSQL"
export DB_CONNECTION=pgsql
export DB_URL=
export DB_HOST="${LOCAL_DB_HOST}"
export DB_PORT="${LOCAL_DB_PORT}"
export DB_DATABASE="${LOCAL_DB_NAME}"
export DB_USERNAME="${LOCAL_DB_USER}"
export DB_PASSWORD="${LOCAL_DB_PASSWORD}"
export DB_SSLMODE="${DB_SSLMODE:-prefer}"
export DB_CONNECT_TIMEOUT="${DB_CONNECT_TIMEOUT:-5}"

php artisan migrate:status
php artisan migrate --pretend
php artisan system:database-health --json
php artisan people:audit-member-model --json >/dev/null

if php artisan list --raw | awk '{print $1}' | grep -qx 'finance:audit-integrations'; then
    php artisan finance:audit-integrations --json >/dev/null
fi

if php artisan list --raw | awk '{print $1}' | grep -qx 'inventory:audit-store-logistics-stock'; then
    php artisan inventory:audit-store-logistics-stock --json >/dev/null
fi

php artisan test --filter=Auth
php artisan test --filter=Member
php artisan test --filter=Financeiro
php artisan test --filter=DatabaseHealth

log "Local PostgreSQL validation completed"

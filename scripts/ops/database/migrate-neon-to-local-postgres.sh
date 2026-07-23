#!/usr/bin/env bash
set -euo pipefail

umask 077

APP_DIR="${APP_DIR:-/var/www/clubmanager}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/clubmanager}"
LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
LOG_PATH="${BACKUP_DIR}/migration-${TIMESTAMP}.log"
REPORT_PATH="${BACKUP_DIR}/migration-${TIMESTAMP}.report.tsv"

mkdir -p "${BACKUP_DIR}"
touch "${LOG_PATH}" "${REPORT_PATH}"
chmod 600 "${LOG_PATH}" "${REPORT_PATH}"
exec > >(tee -a "${LOG_PATH}") 2>&1

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }
require_command() { command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"; }

mask_value() {
    local value="${1:-}"
    [[ -n "${value}" ]] || { printf ''; return; }
    if [[ "${value}" == *neon.tech* ]]; then
        printf '%s' "${value}" | sed -E 's#//[^:@/]+(:[^@/]+)?@#//***:***@#; s#ep-[^./@]+#ep-***#'
        return
    fi
    printf '%s' "${value}"
}

safe_table_name() {
    [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || die "Unsafe table name detected: $1"
}

psql_neon() {
    PGPASSWORD="${NEON_DB_PASSWORD}" PGSSLMODE="${NEON_DB_SSLMODE:-require}" \
        psql -h "${NEON_DB_HOST}" -p "${NEON_DB_PORT}" -U "${NEON_DB_USERNAME}" -d "${NEON_DB_DATABASE}" -v ON_ERROR_STOP=1 "$@"
}

psql_local() {
    PGPASSWORD="${LOCAL_DB_PASSWORD}" \
        psql -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 "$@"
}

table_exists() {
    local target="$1"
    local table="$2"
    safe_table_name "${table}"
    if [[ "${target}" == "neon" ]]; then
        psql_neon -At -c "select to_regclass('public.${table}') is not null;"
    else
        psql_local -At -c "select to_regclass('public.${table}') is not null;"
    fi
}

count_table() {
    local target="$1"
    local table="$2"
    safe_table_name "${table}"
    if [[ "$(table_exists "${target}" "${table}")" != "t" ]]; then
        printf 'missing'
        return
    fi

    if [[ "${target}" == "neon" ]]; then
        psql_neon -At -c "select count(*)::text from \"${table}\";"
    else
        psql_local -At -c "select count(*)::text from \"${table}\";"
    fi
}

list_tables() {
    local target="$1"
    local sql="select table_name from information_schema.tables where table_schema='public' and table_type='BASE TABLE' order by table_name;"
    if [[ "${target}" == "neon" ]]; then
        psql_neon -At -c "${sql}"
    else
        psql_local -At -c "${sql}"
    fi
}

list_interesting_tables() {
    local target="$1"
    local sql="select table_name from information_schema.tables where table_schema='public' and table_type='BASE TABLE' and table_name ~* '(dados|user|member|pessoa|config|sport|desport|stock|inventory|store|logistics|bank|event)' order by table_name;"
    if [[ "${target}" == "neon" ]]; then
        psql_neon -At -c "${sql}"
    else
        psql_local -At -c "${sql}"
    fi
}

column_exists() {
    local target="$1"
    local table="$2"
    local column="$3"
    safe_table_name "${table}"
    safe_table_name "${column}"
    local sql="select exists(select 1 from information_schema.columns where table_schema='public' and table_name='${table}' and column_name='${column}');"
    if [[ "${target}" == "neon" ]]; then
        psql_neon -At -c "${sql}"
    else
        psql_local -At -c "${sql}"
    fi
}

database_fingerprint() {
    local target="$1"
    local label="$2"
    local version tables
    if [[ "${target}" == "neon" ]]; then
        version="$(psql_neon -At -c 'select version();')"
        tables="$(psql_neon -At -c "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE';")"
    else
        version="$(psql_local -At -c 'select version();')"
        tables="$(psql_local -At -c "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE';")"
    fi
    log "${label} PostgreSQL: ${version}"
    log "${label} total tables: ${tables}"
}

compare_tables() {
    local divergence_count=0
    local tmp_dir neon_tables local_tables compare_tables
    tmp_dir="$(mktemp -d)"
    neon_tables="${tmp_dir}/neon_tables"
    local_tables="${tmp_dir}/local_tables"
    compare_tables="${tmp_dir}/compare_tables"

    list_tables neon | sort > "${neon_tables}"
    list_tables local | sort > "${local_tables}"

    {
        printf '%s\n' \
            migrations users dados_pessoais dados_configuracao dados_financeiros invoices payments payment_allocations \
            financial_entries movements club_settings events bank_movements bank_statements stock_movements products \
            product_variants supplier_purchases supplier_purchase_items logistics_requests logistics_request_items \
            equipment_loans loja_encomendas loja_encomenda_items
        list_interesting_tables neon
        list_interesting_tables local
    } | sort -u > "${compare_tables}"

    log "Tables present in Neon and absent locally"
    {
        printf '# tables_present_in_neon_absent_locally\n'
        comm -23 "${neon_tables}" "${local_tables}" || true
        printf '# tables_present_locally_absent_in_neon\n'
        comm -13 "${neon_tables}" "${local_tables}" || true
        printf '# table_counts\n'
        printf 'table\tneon\tlocal\tstatus\n'
    } > "${REPORT_PATH}"

    log "Tables present locally and absent in Neon"
    while IFS= read -r table; do
        [[ -n "${table}" ]] || continue
        safe_table_name "${table}"
        local neon_count local_count status
        neon_count="$(count_table neon "${table}")"
        local_count="$(count_table local "${table}")"
        status="ok"
        if [[ "${neon_count}" != "${local_count}" ]]; then
            status="divergent"
            divergence_count=$((divergence_count + 1))
        fi
        printf '%s\t%s\t%s\t%s\n' "${table}" "${neon_count}" "${local_count}" "${status}" | tee -a "${REPORT_PATH}"
    done < "${compare_tables}"

    rm -rf "${tmp_dir}"
    printf '%s' "${divergence_count}" > "${BACKUP_DIR}/migration-${TIMESTAMP}.divergence-count"
}

recreate_local_database() {
    [[ "${DB1_ALLOW_RESTORE_ON_DIVERGENCE:-false}" == "true" ]] || die "Local DB diverges. Set DB1_ALLOW_RESTORE_ON_DIVERGENCE=true to allow a clean local restore."
    [[ "${DB1_CONFIRM_RECREATE_LOCAL_DB:-}" == "RECREATE_LOCAL_DB" ]] || die "Set DB1_CONFIRM_RECREATE_LOCAL_DB=RECREATE_LOCAL_DB before dropping local ${LOCAL_DB_NAME}."
    require_var DUMP_PATH
    [[ -f "${DUMP_PATH}" ]] || die "Dump file not found: ${DUMP_PATH}"

    log "Recreating local database ${LOCAL_DB_NAME}; Neon remains untouched"
    sudo -u postgres dropdb --if-exists "${LOCAL_DB_NAME}"
    sudo -u postgres createdb -O "${LOCAL_DB_USER}" "${LOCAL_DB_NAME}"

    log "Restoring ${DUMP_PATH} with pg_restore"
    PGPASSWORD="${LOCAL_DB_PASSWORD}" pg_restore --no-owner --no-acl --role="${LOCAL_DB_USER}" \
        --dbname="postgresql://${LOCAL_DB_USER}@${LOCAL_DB_HOST}:${LOCAL_DB_PORT}/${LOCAL_DB_NAME}" \
        "${DUMP_PATH}"
    psql_local -c 'ANALYZE;' >/dev/null
}

run_local_laravel_validation() {
    log "Running Laravel validation against local PostgreSQL"
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
    php artisan system:database-health --json --report-path=storage/app/audits/db1-local-postgres-health.json
    php artisan system:audit-performance --json --report-path=storage/app/audits/db1-local-postgres-performance.json
    php artisan people:audit-member-model --json --report-path=storage/app/audits/db1-local-postgres-member-model.json

    if [[ "${DB1_RUN_APP_TESTS:-false}" == "true" ]]; then
        php artisan test --filter=Auth
        php artisan test --filter=Member
        php artisan test --filter=Financeiro
    else
        log "Focused app tests skipped. Set DB1_RUN_APP_TESTS=true to run Auth, Member and Financeiro filters."
    fi
}

log "DB1 Neon to local PostgreSQL validation started"
log "Application directory: ${APP_DIR}"
log "Log: ${LOG_PATH}"
log "Report: ${REPORT_PATH}"

cd "${APP_DIR}"
[[ -f ".env" ]] || die ".env not found in ${APP_DIR}"

for cmd in php python3 psql pg_restore pg_dump sha256sum awk sort comm grep sed mktemp tee; do
    require_command "${cmd}"
done

[[ "${LOCAL_DB_HOST}" == "127.0.0.1" || "${LOCAL_DB_HOST}" == "localhost" ]] || die "Refusing non-local PostgreSQL host: ${LOCAL_DB_HOST}"
require_var LOCAL_DB_PASSWORD

eval "$(python3 - <<'PY'
from pathlib import Path
from urllib.parse import urlparse, parse_qs, unquote
import shlex

values = {}
for raw in Path(".env").read_text(encoding="utf-8").splitlines():
    line = raw.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, value = line.split("=", 1)
    value = value.strip().strip('"').strip("'")
    values[key.strip()] = value

url = values.get("DB_URL", "")
if url:
    parsed = urlparse(url)
    query = parse_qs(parsed.query)
    derived = {
        "NEON_DB_HOST": parsed.hostname or "",
        "NEON_DB_PORT": str(parsed.port or values.get("DB_PORT") or "5432"),
        "NEON_DB_DATABASE": unquote((parsed.path or "").lstrip("/")),
        "NEON_DB_USERNAME": unquote(parsed.username or ""),
        "NEON_DB_PASSWORD": unquote(parsed.password or ""),
        "NEON_DB_SSLMODE": (query.get("sslmode") or [values.get("DB_SSLMODE") or "require"])[0],
    }
else:
    derived = {
        "NEON_DB_HOST": values.get("DB_HOST", ""),
        "NEON_DB_PORT": values.get("DB_PORT", "5432"),
        "NEON_DB_DATABASE": values.get("DB_DATABASE", ""),
        "NEON_DB_USERNAME": values.get("DB_USERNAME", ""),
        "NEON_DB_PASSWORD": values.get("DB_PASSWORD", ""),
        "NEON_DB_SSLMODE": values.get("DB_SSLMODE", "require"),
    }

for key, value in derived.items():
    print(f"{key}={shlex.quote(value)}")
PY
)"

require_var NEON_DB_HOST
require_var NEON_DB_PORT
require_var NEON_DB_DATABASE
require_var NEON_DB_USERNAME
require_var NEON_DB_PASSWORD

log "Neon target: $(mask_value "${NEON_DB_HOST}"):${NEON_DB_PORT}/${NEON_DB_DATABASE}"
log "Local target: ${LOCAL_DB_HOST}:${LOCAL_DB_PORT}/${LOCAL_DB_NAME} as ${LOCAL_DB_USER}"
log "Client versions: $(psql --version); $(pg_restore --version)"

log "Validating Neon connectivity"
psql_neon -At -c 'select 1;' >/dev/null
database_fingerprint neon "Neon"

log "Validating local PostgreSQL connectivity"
psql_local -At -c 'select 1;' >/dev/null
database_fingerprint local "Local"

log "Checking known schema drift columns"
for target in neon local; do
    log "${target}: dados_pessoais.telemovel=$(column_exists "${target}" dados_pessoais telemovel), contacto=$(column_exists "${target}" dados_pessoais contacto), contacto_telefonico=$(column_exists "${target}" dados_pessoais contacto_telefonico), estado_civil=$(column_exists "${target}" dados_pessoais estado_civil)"
    log "${target}: dados_configuracao.platform_access_enabled=$(column_exists "${target}" dados_configuracao platform_access_enabled)"
done

log "Comparing Neon and local tables/counts"
compare_tables
divergences="$(cat "${BACKUP_DIR}/migration-${TIMESTAMP}.divergence-count")"
log "Divergent table count: ${divergences}"

if [[ "${divergences}" != "0" ]]; then
    if [[ "${DB1_ALLOW_RESTORE_ON_DIVERGENCE:-false}" == "true" ]]; then
        recreate_local_database
        log "Re-running comparison after clean restore"
        compare_tables
        divergences="$(cat "${BACKUP_DIR}/migration-${TIMESTAMP}.divergence-count")"
        log "Divergent table count after restore: ${divergences}"
    fi
fi

if [[ "${divergences}" != "0" ]]; then
    die "Neon/local divergences remain. Do not switch .env. Review ${REPORT_PATH} and ${LOG_PATH}."
fi

run_local_laravel_validation

log "DB1 validation passed. No automatic .env switch was performed by this script."
log "To switch, use scripts/ops/database/switch-production-db-to-local.sh with DB1_CONFIRM_SWITCH_TO_LOCAL_POSTGRES=SWITCH_TO_LOCAL_POSTGRES after the final human go/no-go."

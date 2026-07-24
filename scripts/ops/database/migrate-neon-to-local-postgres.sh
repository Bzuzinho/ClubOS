#!/usr/bin/env bash
set -euo pipefail

umask 077

APP_DIR="${APP_DIR:-/var/www/clubmanager}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/clubmanager}"
LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
PG_MAJOR_REQUIRED="${PG_MAJOR_REQUIRED:-17}"
PG_BIN_DIR="${PG_BIN_DIR:-/usr/lib/postgresql/${PG_MAJOR_REQUIRED}/bin}"
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

prefer_pg17_binary() {
    local name="$1"
    local explicit_var="$2"
    local explicit_value="${!explicit_var:-}"

    if [[ -n "${explicit_value}" ]]; then
        [[ -x "${explicit_value}" ]] || die "${explicit_var} is not executable: ${explicit_value}"
        printf '%s' "${explicit_value}"
        return
    fi

    if [[ -x "${PG_BIN_DIR}/${name}" ]]; then
        printf '%s' "${PG_BIN_DIR}/${name}"
        return
    fi

    die "PostgreSQL ${PG_MAJOR_REQUIRED} binary not found at ${PG_BIN_DIR}/${name}. Install/activate PostgreSQL ${PG_MAJOR_REQUIRED} before running DB1.1."
}

PSQL_BIN="$(prefer_pg17_binary psql PSQL_BIN)"
PG_RESTORE_BIN="$(prefer_pg17_binary pg_restore PG_RESTORE_BIN)"
PG_DUMP_BIN="$(prefer_pg17_binary pg_dump PG_DUMP_BIN)"
DROPDB_BIN="$(prefer_pg17_binary dropdb DROPDB_BIN)"
CREATEDB_BIN="$(prefer_pg17_binary createdb CREATEDB_BIN)"

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

pg_major_from_version_num() {
    local version_num="$1"
    printf '%s' "$((version_num / 10000))"
}

psql_neon() {
    PGPASSWORD="${NEON_DB_PASSWORD}" PGSSLMODE="${NEON_DB_SSLMODE:-require}" \
        "${PSQL_BIN}" -h "${NEON_DB_HOST}" -p "${NEON_DB_PORT}" -U "${NEON_DB_USERNAME}" -d "${NEON_DB_DATABASE}" -v ON_ERROR_STOP=1 "$@"
}

psql_local() {
    PGPASSWORD="${LOCAL_DB_PASSWORD}" \
        "${PSQL_BIN}" -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 "$@"
}

psql_local_postgres_as_superuser() {
    sudo -u postgres "${PSQL_BIN}" -p "${LOCAL_DB_PORT}" -d postgres -v ON_ERROR_STOP=1 "$@"
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

server_version_num() {
    local target="$1"
    if [[ "${target}" == "neon" ]]; then
        psql_neon -At -c "select current_setting('server_version_num')::int;"
    else
        psql_local -At -c "select current_setting('server_version_num')::int;"
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

log_pg_clusters() {
    if command -v pg_lsclusters >/dev/null 2>&1; then
        log "pg_lsclusters output follows"
        pg_lsclusters || true
    else
        log "pg_lsclusters not available"
    fi
}

assert_supported_versions() {
    local neon_version_num local_version_num neon_major local_major restore_major psql_major dump_major
    neon_version_num="$(server_version_num neon)"
    local_version_num="$(server_version_num local)"
    neon_major="$(pg_major_from_version_num "${neon_version_num}")"
    local_major="$(pg_major_from_version_num "${local_version_num}")"
    restore_major="$("${PG_RESTORE_BIN}" --version | sed -E 's/.* ([0-9]+)(\.[0-9]+)?.*/\1/')"
    psql_major="$("${PSQL_BIN}" --version | sed -E 's/.* ([0-9]+)(\.[0-9]+)?.*/\1/')"
    dump_major="$("${PG_DUMP_BIN}" --version | sed -E 's/.* ([0-9]+)(\.[0-9]+)?.*/\1/')"

    log "Effective psql: ${PSQL_BIN} ($("${PSQL_BIN}" --version))"
    log "Effective pg_restore: ${PG_RESTORE_BIN} ($("${PG_RESTORE_BIN}" --version))"
    log "Effective pg_dump: ${PG_DUMP_BIN} ($("${PG_DUMP_BIN}" --version))"
    log "Effective local server: ${LOCAL_DB_HOST}:${LOCAL_DB_PORT}, server_version_num=${local_version_num}, major=${local_major}"
    log "Effective Neon server: ${NEON_DB_HOST}:${NEON_DB_PORT}, server_version_num=${neon_version_num}, major=${neon_major}"

    (( local_major >= neon_major )) || die "Local PostgreSQL server major ${local_major} is lower than Neon major ${neon_major}. Do not restore/switch; point LOCAL_DB_PORT to PostgreSQL ${neon_major}+."
    (( restore_major >= neon_major )) || die "pg_restore major ${restore_major} is lower than Neon major ${neon_major}. Refusing to restore PostgreSQL ${neon_major} dump with older pg_restore."
    (( psql_major >= neon_major )) || die "psql major ${psql_major} is lower than Neon major ${neon_major}."
    (( dump_major >= neon_major )) || die "pg_dump major ${dump_major} is lower than Neon major ${neon_major}."
}

prepare_postgres17_local_database() {
    [[ "${DB1_PREPARE_LOCAL_PG17:-false}" == "true" ]] || return 0
    [[ "${DB1_CONFIRM_RECREATE_LOCAL_DB:-}" == "RECREATE_LOCAL_DB" ]] || die "Set DB1_CONFIRM_RECREATE_LOCAL_DB=RECREATE_LOCAL_DB before recreating local ${LOCAL_DB_NAME}."
    require_var LOCAL_DB_PASSWORD
    require_var DUMP_PATH
    [[ -f "${DUMP_PATH}" ]] || die "Dump file not found: ${DUMP_PATH}"
    require_command sudo
    require_command python3

    log "Preparing PostgreSQL ${PG_MAJOR_REQUIRED} local database on port ${LOCAL_DB_PORT}; Neon remains untouched"
    log_pg_clusters

    local prepare_version_num prepare_major
    prepare_version_num="$(psql_local_postgres_as_superuser -At -c "select current_setting('server_version_num')::int;")"
    prepare_major="$(pg_major_from_version_num "${prepare_version_num}")"
    log "Prepare target local server_version_num=${prepare_version_num}, major=${prepare_major}"
    (( prepare_major >= PG_MAJOR_REQUIRED )) || die "Refusing to prepare local database on PostgreSQL major ${prepare_major}. Set LOCAL_DB_PORT to the PostgreSQL ${PG_MAJOR_REQUIRED} cluster port."

    LOCAL_DB_USER="${LOCAL_DB_USER}" LOCAL_DB_PASSWORD="${LOCAL_DB_PASSWORD}" python3 - <<'PY' | psql_local_postgres_as_superuser
import os

user_ident = os.environ["LOCAL_DB_USER"].replace('"', '""')
user_literal = os.environ["LOCAL_DB_USER"].replace("'", "''")
password_literal = os.environ["LOCAL_DB_PASSWORD"].replace("'", "''")

print(f"""
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{user_literal}') THEN
        CREATE ROLE "{user_ident}" LOGIN PASSWORD '{password_literal}';
    ELSE
        ALTER ROLE "{user_ident}" WITH LOGIN PASSWORD '{password_literal}';
    END IF;
END
$$;
""")
PY

    log "Dropping and recreating only local database ${LOCAL_DB_NAME} on PostgreSQL ${PG_MAJOR_REQUIRED}"
    sudo -u postgres "${DROPDB_BIN}" -p "${LOCAL_DB_PORT}" --if-exists "${LOCAL_DB_NAME}"
    sudo -u postgres "${CREATEDB_BIN}" -p "${LOCAL_DB_PORT}" -O "${LOCAL_DB_USER}" "${LOCAL_DB_NAME}"

    log "Ensuring public schema ownership and privileges"
    sudo -u postgres "${PSQL_BIN}" -p "${LOCAL_DB_PORT}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 <<SQL
ALTER SCHEMA public OWNER TO "${LOCAL_DB_USER}";
REVOKE ALL ON DATABASE "${LOCAL_DB_NAME}" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE "${LOCAL_DB_NAME}" TO "${LOCAL_DB_USER}";
GRANT ALL PRIVILEGES ON SCHEMA public TO "${LOCAL_DB_USER}";
SQL

    log "Restoring ${DUMP_PATH} with PostgreSQL ${PG_MAJOR_REQUIRED} pg_restore"
    PGPASSWORD="${LOCAL_DB_PASSWORD}" "${PG_RESTORE_BIN}" --no-owner --no-acl --role="${LOCAL_DB_USER}" \
        --dbname="postgresql://${LOCAL_DB_USER}@${LOCAL_DB_HOST}:${LOCAL_DB_PORT}/${LOCAL_DB_NAME}" \
        "${DUMP_PATH}"

    log "Running ANALYZE after clean restore"
    psql_local -c 'ANALYZE;' >/dev/null
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
            migrations users athlete_sports_data centro_custo_user dados_pessoais dados_configuracao dados_financeiros \
            invoices payments payment_allocations financial_entries movements club_settings events bank_movements \
            bank_statements stock_movements products product_variants supplier_purchases supplier_purchase_items \
            logistics_requests logistics_request_items equipment_loans loja_encomendas loja_encomenda_items user_guardian
        list_interesting_tables neon
        list_interesting_tables local
    } | sort -u > "${compare_tables}"

    {
        printf '# tables_present_in_neon_absent_locally\n'
        comm -23 "${neon_tables}" "${local_tables}" || true
        printf '# tables_present_locally_absent_in_neon\n'
        comm -13 "${neon_tables}" "${local_tables}" || true
        printf '# table_counts\n'
        printf 'table\tneon\tlocal\tstatus\n'
    } > "${REPORT_PATH}"

    log "Comparing table counts; report: ${REPORT_PATH}"
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

log "DB1.1 Neon to PostgreSQL ${PG_MAJOR_REQUIRED} local validation started"
log "Application directory: ${APP_DIR}"
log "Log: ${LOG_PATH}"
log "Report: ${REPORT_PATH}"

cd "${APP_DIR}"
[[ -f ".env" ]] || die ".env not found in ${APP_DIR}"

for cmd in php python3 sha256sum awk sort comm grep sed mktemp tee; do
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
log_pg_clusters

log "Validating Neon connectivity with PostgreSQL ${PG_MAJOR_REQUIRED} psql"
psql_neon -At -c 'select 1;' >/dev/null
database_fingerprint neon "Neon"

prepare_postgres17_local_database

log "Validating local PostgreSQL connectivity"
psql_local -At -c 'select 1;' >/dev/null
database_fingerprint local "Local"
assert_supported_versions

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
    die "Neon/local divergences remain. Do not switch .env. Review ${REPORT_PATH} and ${LOG_PATH}."
fi

run_local_laravel_validation

log "DB1.1 validation passed. No automatic .env switch was performed by this script."
log "To switch, use scripts/ops/database/switch-production-db-to-local.sh with LOCAL_DB_PORT=${LOCAL_DB_PORT} and DB1_CONFIRM_SWITCH_TO_LOCAL_POSTGRES=SWITCH_TO_LOCAL_POSTGRES after final approval."

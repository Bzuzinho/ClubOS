#!/usr/bin/env bash
set -Eeuo pipefail

DR_APP_DIR="${DR_APP_DIR:-/var/www/clubmanager}"
DR_LOCAL_BACKUP_DIR="${DR_LOCAL_BACKUP_DIR:-/var/backups/clubmanager/postgres-local}"
DR_CONFIG_FILE="${DR_CONFIG_FILE:-/etc/clubos-dr/dr.env}"
DR_STATE_DIR="${DR_STATE_DIR:-/var/lib/clubos-dr}"
DR_ENABLED_MARKER="${DR_ENABLED_MARKER:-${DR_STATE_DIR}/enabled}"
DR_PG_BIN_DIR="${DR_PG_BIN_DIR:-/usr/lib/postgresql/17/bin}"
DR_PG_RESTORE="${DR_PG_RESTORE:-${DR_PG_BIN_DIR}/pg_restore}"
DR_PSQL="${DR_PSQL:-${DR_PG_BIN_DIR}/psql}"
DR_CREATEDB="${DR_CREATEDB:-${DR_PG_BIN_DIR}/createdb}"
DR_DROPDB="${DR_DROPDB:-${DR_PG_BIN_DIR}/dropdb}"
DR_MAX_LOCAL_AGE_SECONDS="${DR_MAX_LOCAL_AGE_SECONDS:-93600}"
DR_MAX_OFFSITE_AGE_SECONDS="${DR_MAX_OFFSITE_AGE_SECONDS:-108000}"
DR_MAX_RESTORE_TEST_AGE_SECONDS="${DR_MAX_RESTORE_TEST_AGE_SECONDS:-691200}"

DR_RETENTION_DAILY="${DR_RETENTION_DAILY:-7}"
DR_RETENTION_WEEKLY="${DR_RETENTION_WEEKLY:-4}"
DR_RETENTION_MONTHLY="${DR_RETENTION_MONTHLY:-12}"

_dr_timestamp() { date -u +%Y-%m-%dT%H:%M:%SZ; }
dr_log() { printf '[dr][%s] %s\n' "$(_dr_timestamp)" "$*"; }
dr_warn() { printf '[dr][%s] WARN: %s\n' "$(_dr_timestamp)" "$*" >&2; }
dr_die() { printf '[dr][%s] ERROR: %s\n' "$(_dr_timestamp)" "$*" >&2; exit 1; }

dr_require_root() {
    [[ "${EUID}" -eq 0 ]] || dr_die 'este script tem de ser executado como root'
}

dr_require_file() {
    local path="$1"
    [[ -f "${path}" ]] || dr_die "ficheiro obrigatório inexistente: ${path}"
}

dr_require_command() {
    local command_name="$1"
    command -v "${command_name}" >/dev/null 2>&1 || dr_die "comando obrigatório não encontrado: ${command_name}"
}

dr_require_pg17_tools() {
    local bin
    for bin in "${DR_PG_RESTORE}" "${DR_PSQL}" "${DR_CREATEDB}" "${DR_DROPDB}"; do
        [[ -x "${bin}" ]] || dr_die "binário PostgreSQL 17 inexistente: ${bin}"
    done

    local major
    major="$("${DR_PG_RESTORE}" --version | grep -oE '[0-9]+(\.[0-9]+)?' | head -n1 | cut -d. -f1)"
    [[ "${major}" == '17' ]] || dr_die "pg_restore PostgreSQL 17 obrigatório; encontrado: $("${DR_PG_RESTORE}" --version)"
}

dr_read_app_env() {
    local key="$1"
    local env_file="${DR_APP_DIR}/.env"
    local line value

    [[ -f "${env_file}" ]] || return 0
    line="$(grep -m1 -E "^${key}=" "${env_file}" 2>/dev/null || true)"
    [[ -n "${line}" ]] || return 0
    value="${line#*=}"
    value="${value%$'\r'}"

    if [[ "${value}" == \"*\" && "${value}" == *\" ]]; then
        value="${value:1:${#value}-2}"
    elif [[ "${value}" == \'*\' && "${value}" == *\' ]]; then
        value="${value:1:${#value}-2}"
    fi

    printf '%s' "${value}"
}

dr_latest_local_dump() {
    find "${DR_LOCAL_BACKUP_DIR}" -maxdepth 1 -type f -name 'clubmanager-prod-*.dump' -printf '%T@ %p\n' \
        | sort -rn \
        | head -n1 \
        | cut -d' ' -f2-
}

dr_file_age_seconds() {
    local path="$1"
    printf '%s' "$(( $(date +%s) - $(stat -c %Y "${path}") ))"
}

dr_verify_dump() {
    local dump="$1"
    local checksum="${dump}.sha256"

    dr_require_pg17_tools
    [[ -s "${dump}" ]] || dr_die "dump inexistente ou vazio: ${dump}"
    [[ -s "${checksum}" ]] || dr_die "checksum inexistente ou vazio: ${checksum}"

    (cd "$(dirname "${dump}")" && sha256sum -c "$(basename "${checksum}")") >/dev/null \
        || dr_die "checksum inválido: ${dump}"
    "${DR_PG_RESTORE}" --list "${dump}" >/dev/null \
        || dr_die "pg_restore --list falhou: ${dump}"
}

dr_require_fresh_dump() {
    local dump age
    dump="$(dr_latest_local_dump)"
    [[ -n "${dump}" ]] || dr_die "nenhum dump encontrado em ${DR_LOCAL_BACKUP_DIR}"
    age="$(dr_file_age_seconds "${dump}")"
    (( age <= DR_MAX_LOCAL_AGE_SECONDS )) || dr_die "backup local demasiado antigo: ${age}s"
    dr_verify_dump "${dump}"
    printf '%s' "${dump}"
}

dr_load_config() {
    dr_require_file "${DR_CONFIG_FILE}"
    set -a
    # shellcheck disable=SC1090
    source "${DR_CONFIG_FILE}"
    set +a

    [[ -n "${DR_REMOTE_BASE:-}" ]] || dr_die 'DR_REMOTE_BASE não definido'
    [[ -n "${DR_GPG_PASSPHRASE_FILE:-}" ]] || dr_die 'DR_GPG_PASSPHRASE_FILE não definido'
    [[ -s "${DR_GPG_PASSPHRASE_FILE}" ]] || dr_die 'ficheiro de passphrase GPG ausente ou vazio'
}

dr_state_write() {
    local name="$1"
    shift
    install -d -o root -g root -m 700 "${DR_STATE_DIR}"
    umask 077
    {
        printf 'timestamp=%s\n' "$(_dr_timestamp)"
        printf '%s\n' "$@"
    } > "${DR_STATE_DIR}/${name}"
}

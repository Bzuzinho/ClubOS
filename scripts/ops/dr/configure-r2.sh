#!/usr/bin/env bash
set -Eeuo pipefail

log() { printf '[dr-config][%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
die() { printf '[dr-config][%s] ERROR: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "variável obrigatória em falta: ${name}"; }

[[ "${EUID}" -eq 0 ]] || die 'executar como root'

require_var DR_R2_ENDPOINT
require_var DR_R2_BUCKET
require_var DR_R2_ACCESS_KEY_ID
require_var DR_R2_SECRET_ACCESS_KEY
require_var DR_BACKUP_PASSPHRASE

DR_R2_PREFIX="${DR_R2_PREFIX:-clubos-prod}"
DR_CONFIG_DIR="${DR_CONFIG_DIR:-/etc/clubos-dr}"
DR_STATE_DIR="${DR_STATE_DIR:-/var/lib/clubos-dr}"
CONFIG_FILE="${DR_CONFIG_DIR}/dr.env"
PASSPHRASE_FILE="${DR_CONFIG_DIR}/backup.passphrase"

[[ "${DR_R2_ENDPOINT}" == https://* ]] || die 'DR_R2_ENDPOINT tem de usar HTTPS'
[[ "${DR_R2_BUCKET}" =~ ^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$ ]] || die 'DR_R2_BUCKET inválido'
[[ "${DR_R2_PREFIX}" != /* && "${DR_R2_PREFIX}" != *'..'* ]] || die 'DR_R2_PREFIX inválido'

install -d -o root -g root -m 700 "${DR_CONFIG_DIR}" "${DR_STATE_DIR}"
umask 077

printf '%s\n' "${DR_BACKUP_PASSPHRASE}" > "${PASSPHRASE_FILE}"
chmod 600 "${PASSPHRASE_FILE}"

write_env() {
    local key="$1" value="$2"
    printf '%s=%q\n' "${key}" "${value}"
}

{
    write_env DR_REMOTE_BASE "r2:${DR_R2_BUCKET}/${DR_R2_PREFIX}"
    write_env DR_GPG_PASSPHRASE_FILE "${PASSPHRASE_FILE}"
    write_env DR_RETENTION_DAILY '7'
    write_env DR_RETENTION_WEEKLY '4'
    write_env DR_RETENTION_MONTHLY '12'
    write_env RCLONE_CONFIG_R2_TYPE 's3'
    write_env RCLONE_CONFIG_R2_PROVIDER 'Cloudflare'
    write_env RCLONE_CONFIG_R2_ACCESS_KEY_ID "${DR_R2_ACCESS_KEY_ID}"
    write_env RCLONE_CONFIG_R2_SECRET_ACCESS_KEY "${DR_R2_SECRET_ACCESS_KEY}"
    write_env RCLONE_CONFIG_R2_ENDPOINT "${DR_R2_ENDPOINT%/}"
    write_env RCLONE_CONFIG_R2_REGION 'auto'
    write_env RCLONE_CONFIG_R2_ACL 'private'
} > "${CONFIG_FILE}"
chmod 600 "${CONFIG_FILE}"

unset DR_BACKUP_PASSPHRASE DR_R2_SECRET_ACCESS_KEY
log "configuração DR gravada em ${CONFIG_FILE}; segredos não foram impressos"

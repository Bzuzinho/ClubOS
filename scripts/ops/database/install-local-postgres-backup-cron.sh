#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }

APP_DIR="${APP_DIR:-/var/www/clubmanager}"
BACKUP_SCRIPT="${APP_DIR}/scripts/ops/database/backup-local-postgres.sh"
LOG_FILE="${LOG_FILE:-/var/log/clubmanager-postgres-backup.log}"
CRON_MARKER="# ClubOS local PostgreSQL daily backup"
CRON_ENTRY="15 2 * * * ${BACKUP_SCRIPT} >> ${LOG_FILE} 2>&1"

[[ "${EUID}" -eq 0 ]] || die "Run this installer as root (sudo)"
[[ -x "${BACKUP_SCRIPT}" ]] || die "Backup script is missing or not executable: ${BACKUP_SCRIPT}"
command -v crontab >/dev/null 2>&1 || die "crontab is required"

touch "${LOG_FILE}"
chmod 600 "${LOG_FILE}"

CURRENT_CRONTAB="$(crontab -l 2>/dev/null || true)"
FILTERED_CRONTAB="$(
    printf '%s\n' "${CURRENT_CRONTAB}" \
        | grep -Fv "${CRON_MARKER}" \
        | grep -Fv "/scripts/ops/database/backup-local-postgres.sh" \
        || true
)"

{
    if [[ -n "${FILTERED_CRONTAB}" ]]; then
        printf '%s\n' "${FILTERED_CRONTAB}"
    fi
    printf '%s\n%s\n' "${CRON_MARKER}" "${CRON_ENTRY}"
} | crontab -

log "Daily PostgreSQL backup cron installed for 02:15 UTC"
crontab -l | grep -A 1 -F "${CRON_MARKER}"

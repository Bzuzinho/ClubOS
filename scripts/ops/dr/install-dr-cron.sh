#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/common.sh"

dr_require_root
dr_load_config
dr_require_command crontab
dr_require_command gpg
dr_require_command curl

if ! command -v rclone >/dev/null 2>&1; then
    dr_log 'rclone não encontrado; instalar via apt'
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y rclone
fi
dr_require_command rclone

install -d -o root -g root -m 700 "${DR_STATE_DIR}"
for log_file in \
    /var/log/clubos-dr-offsite.log \
    /var/log/clubos-dr-restore-test.log \
    /var/log/clubos-dr-health.log; do
    touch "${log_file}"
    chmod 600 "${log_file}"
done

# Verifica autenticação e acesso ao bucket antes de ativar o agendamento.
rclone lsf "${DR_REMOTE_BASE}" --max-depth 1 >/dev/null 2>&1 \
    || dr_die "não foi possível aceder ao remote ${DR_REMOTE_BASE}"

CURRENT_CRONTAB="$(crontab -l 2>/dev/null || true)"
FILTERED_CRONTAB="$(
    printf '%s\n' "${CURRENT_CRONTAB}" \
        | grep -Fv '# ClubOS DR offsite backup' \
        | grep -Fv '# ClubOS DR weekly restore test' \
        | grep -Fv '# ClubOS DR health check' \
        | grep -Fv '/scripts/ops/dr/backup-offsite.sh' \
        | grep -Fv '/scripts/ops/dr/restore-test-offsite.sh' \
        | grep -Fv '/scripts/ops/dr/check-dr-health.sh' \
        || true
)"

{
    if [[ -n "${FILTERED_CRONTAB}" ]]; then
        printf '%s\n' "${FILTERED_CRONTAB}"
    fi
    printf '%s\n' '# ClubOS DR offsite backup'
    printf '%s\n' "35 2 * * * bash ${DR_APP_DIR}/scripts/ops/dr/backup-offsite.sh >> /var/log/clubos-dr-offsite.log 2>&1"
    printf '%s\n' '# ClubOS DR weekly restore test'
    printf '%s\n' "15 4 * * 0 bash ${DR_APP_DIR}/scripts/ops/dr/restore-test-offsite.sh >> /var/log/clubos-dr-restore-test.log 2>&1"
    printf '%s\n' '# ClubOS DR health check'
    printf '%s\n' "30 6 * * * bash ${DR_APP_DIR}/scripts/ops/dr/check-dr-health.sh >> /var/log/clubos-dr-health.log 2>&1"
} | crontab -

printf 'enabled_at=%s\n' "$(_dr_timestamp)" > "${DR_ENABLED_MARKER}"
chmod 600 "${DR_ENABLED_MARKER}"
dr_log 'agendamento DR instalado: offsite 02:35 UTC, restore test domingo 04:15 UTC, health 06:30 UTC'

#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/common.sh"

dr_require_root
install -d -o root -g root -m 700 "${DR_STATE_DIR}"

DUMP="$(dr_require_fresh_dump)"
LOCAL_AGE="$(dr_file_age_seconds "${DUMP}")"
printf 'local_backup=%s\n' "$(basename "${DUMP}")"
printf 'local_age_seconds=%s\n' "${LOCAL_AGE}"
printf 'local_integrity=ok\n'

if [[ ! -f "${DR_ENABLED_MARKER}" ]]; then
    printf 'offsite_status=not_enabled\n'
    printf 'restore_test_status=not_enabled\n'
    dr_log 'backup local saudável; off-site ainda não ativado'
    exit 0
fi

dr_load_config
dr_require_command rclone

OFFSITE_MARKER="${DR_STATE_DIR}/last-offsite-success"
RESTORE_MARKER="${DR_STATE_DIR}/last-restore-test-success"
[[ -s "${OFFSITE_MARKER}" ]] || dr_die 'marker de backup off-site inexistente'
[[ -s "${RESTORE_MARKER}" ]] || dr_die 'marker de restore test inexistente'

OFFSITE_AGE="$(dr_file_age_seconds "${OFFSITE_MARKER}")"
RESTORE_AGE="$(dr_file_age_seconds "${RESTORE_MARKER}")"
(( OFFSITE_AGE <= DR_MAX_OFFSITE_AGE_SECONDS )) || dr_die "último off-site demasiado antigo: ${OFFSITE_AGE}s"
(( RESTORE_AGE <= DR_MAX_RESTORE_TEST_AGE_SECONDS )) || dr_die "último restore test demasiado antigo: ${RESTORE_AGE}s"

REMOTE_LATEST="$(
    rclone lsf "${DR_REMOTE_BASE}/daily" --recursive --files-only 2>/dev/null \
        | grep -E '\.tar\.gz\.gpg$' \
        | sort -r \
        | head -n1 \
        || true
)"
[[ -n "${REMOTE_LATEST}" ]] || dr_die 'off-site ativo mas sem arquivo diário remoto'

printf 'offsite_status=ok\n'
printf 'offsite_age_seconds=%s\n' "${OFFSITE_AGE}"
printf 'remote_latest=%s\n' "${REMOTE_LATEST}"
printf 'restore_test_status=ok\n'
printf 'restore_test_age_seconds=%s\n' "${RESTORE_AGE}"
dr_log 'DR health check OK'

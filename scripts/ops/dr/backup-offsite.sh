#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/common.sh"

dr_require_root
dr_load_config
dr_require_command flock
dr_require_command tar
dr_require_command gpg
dr_require_command rclone
dr_require_command rsync
dr_require_command sha256sum

LOCK_FILE="${DR_OFFSITE_LOCK_FILE:-/run/lock/clubos-dr-offsite.lock}"
exec 9>"${LOCK_FILE}"
flock -n 9 || dr_die 'já existe um backup off-site em execução'

STAGE_ROOT="$(mktemp -d /tmp/clubos-dr-stage.XXXXXX)"
ARCHIVE_BASE="$(date -u +clubos-prod-%Y%m%dT%H%M%SZ)"
TAR_PATH="/tmp/${ARCHIVE_BASE}.tar.gz"
ENCRYPTED_PATH="${TAR_PATH}.gpg"
cleanup() {
    rm -rf -- "${STAGE_ROOT}" "${TAR_PATH}" "${ENCRYPTED_PATH}" >/dev/null 2>&1 || true
}
trap cleanup EXIT
umask 077

DUMP="$(dr_require_fresh_dump)"
ORIGINAL_DUMP="$(basename "${DUMP}")"
RELEASE_SHA="$(sed -n 's/^commit_sha=//p' "${DR_APP_DIR}/.clubos-release" 2>/dev/null || true)"
[[ -n "${RELEASE_SHA}" ]] || RELEASE_SHA="$(git -C "${DR_APP_DIR}" rev-parse HEAD 2>/dev/null || echo unknown)"

mkdir -p "${STAGE_ROOT}/database" "${STAGE_ROOT}/application/storage-public"
install -m 600 "${DUMP}" "${STAGE_ROOT}/database/database.dump"
(
    cd "${STAGE_ROOT}/database"
    sha256sum database.dump > database.dump.sha256
)
install -m 600 "${DR_APP_DIR}/.env" "${STAGE_ROOT}/application/.env"
rsync -a --delete "${DR_APP_DIR}/storage/app/public/" "${STAGE_ROOT}/application/storage-public/"

STORAGE_BYTES="$(du -sb "${DR_APP_DIR}/storage/app/public" 2>/dev/null | awk '{print $1}' || echo 0)"
cat > "${STAGE_ROOT}/manifest.txt" <<MANIFEST
contract=clubos-dr-archive-v1
created_at=$(_dr_timestamp)
host=$(hostname -f 2>/dev/null || hostname)
release_sha=${RELEASE_SHA}
source_dump=${ORIGINAL_DUMP}
storage_public_bytes=${STORAGE_BYTES}
retention_daily=${DR_RETENTION_DAILY}
retention_weekly=${DR_RETENTION_WEEKLY}
retention_monthly=${DR_RETENTION_MONTHLY}
MANIFEST
chmod 600 "${STAGE_ROOT}/manifest.txt"

dr_log "criar arquivo DR local ${ARCHIVE_BASE}"
tar -C "${STAGE_ROOT}" -czf "${TAR_PATH}" .

dr_log 'cifrar arquivo com GPG AES-256 antes do transporte'
gpg \
    --batch \
    --yes \
    --pinentry-mode loopback \
    --passphrase-file "${DR_GPG_PASSPHRASE_FILE}" \
    --symmetric \
    --cipher-algo AES256 \
    --compress-algo none \
    --output "${ENCRYPTED_PATH}" \
    "${TAR_PATH}"
[[ -s "${ENCRYPTED_PATH}" ]] || dr_die 'arquivo cifrado não foi criado'

DATE_YEAR="$(date -u +%Y)"
DATE_MONTH="$(date -u +%m)"
DATE_DAY="$(date -u +%d)"
DATE_WEEK="$(date -u +%V)"
DATE_WEEKDAY="$(date -u +%u)"
OBJECT_NAME="$(basename "${ENCRYPTED_PATH}")"
DAILY_OBJECT="${DR_REMOTE_BASE}/daily/${DATE_YEAR}/${DATE_MONTH}/${DATE_DAY}/${OBJECT_NAME}"

upload_and_verify() {
    local destination="$1"
    local local_sha remote_sha

    dr_log "upload ${destination}"
    rclone copyto "${ENCRYPTED_PATH}" "${destination}" --no-traverse --retries 3 --low-level-retries 10
    local_sha="$(sha256sum "${ENCRYPTED_PATH}" | awk '{print $1}')"
    remote_sha="$(rclone cat "${destination}" | sha256sum | awk '{print $1}')"
    [[ "${local_sha}" == "${remote_sha}" ]] || dr_die "verificação pós-upload falhou: ${destination}"
}

upload_and_verify "${DAILY_OBJECT}"
UPLOADED_OBJECTS=("daily/${DATE_YEAR}/${DATE_MONTH}/${DATE_DAY}/${OBJECT_NAME}")

if [[ "${DATE_WEEKDAY}" == '7' ]]; then
    WEEKLY_OBJECT="${DR_REMOTE_BASE}/weekly/${DATE_YEAR}/W${DATE_WEEK}/${OBJECT_NAME}"
    upload_and_verify "${WEEKLY_OBJECT}"
    UPLOADED_OBJECTS+=("weekly/${DATE_YEAR}/W${DATE_WEEK}/${OBJECT_NAME}")
fi

if [[ "${DATE_DAY}" == '01' ]]; then
    MONTHLY_OBJECT="${DR_REMOTE_BASE}/monthly/${DATE_YEAR}/${DATE_MONTH}/${OBJECT_NAME}"
    upload_and_verify "${MONTHLY_OBJECT}"
    UPLOADED_OBJECTS+=("monthly/${DATE_YEAR}/${DATE_MONTH}/${OBJECT_NAME}")
fi

apply_retention() {
    local tier="$1"
    local keep="$2"
    local remote_path="${DR_REMOTE_BASE}/${tier}"
    local -a files=()
    local candidate

    mapfile -t files < <(
        rclone lsf "${remote_path}" --recursive --files-only 2>/dev/null \
            | grep -E '\.tar\.gz\.gpg$' \
            | sort -r \
            || true
    )

    if (( ${#files[@]} <= keep )); then
        dr_log "retenção ${tier}: ${#files[@]}/${keep}; sem remoções"
        return 0
    fi

    for candidate in "${files[@]:keep}"; do
        if rclone deletefile "${remote_path}/${candidate}"; then
            dr_log "retenção ${tier}: removido ${candidate}"
        else
            dr_warn "retenção ${tier}: ${candidate} não pôde ser removido; pode estar protegido por bucket lock"
        fi
    done
}

apply_retention daily "${DR_RETENTION_DAILY}"
apply_retention weekly "${DR_RETENTION_WEEKLY}"
apply_retention monthly "${DR_RETENTION_MONTHLY}"

dr_state_write last-offsite-success \
    "archive=${OBJECT_NAME}" \
    "source_dump=${ORIGINAL_DUMP}" \
    "encrypted_bytes=$(stat -c %s "${ENCRYPTED_PATH}")" \
    "objects=${UPLOADED_OBJECTS[*]}"

dr_log "backup off-site concluído e verificado: ${DAILY_OBJECT}"

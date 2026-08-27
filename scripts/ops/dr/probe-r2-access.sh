#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/common.sh"

dr_require_root
dr_load_config
dr_require_command rclone
dr_require_command sha256sum
dr_require_command cmp

umask 077

PROBE_ROOT="${DR_REMOTE_BASE}/.access-probe"
PROBE_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$-${RANDOM}"
REMOTE_OBJECT="${PROBE_ROOT}/${PROBE_ID}.txt"
LOCAL_OBJECT="$(mktemp /tmp/clubos-dr-r2-probe.XXXXXX)"
REMOTE_CREATED=0

cleanup() {
    rm -f -- "${LOCAL_OBJECT}" >/dev/null 2>&1 || true
    if [[ "${REMOTE_CREATED}" -eq 1 ]]; then
        rclone deletefile "${REMOTE_OBJECT}" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

printf 'clubos-r2-access-probe=%s\n' "${PROBE_ID}" > "${LOCAL_OBJECT}"
chmod 600 "${LOCAL_OBJECT}"
LOCAL_SHA="$(sha256sum "${LOCAL_OBJECT}" | awk '{print $1}')"

if rclone lsf "${DR_REMOTE_BASE}" --max-depth 1 >/dev/null; then
    echo 'list_access=ok'
else
    echo 'list_access=failed' >&2
    exit 1
fi

if rclone copyto "${LOCAL_OBJECT}" "${REMOTE_OBJECT}" --no-traverse --retries 2 --low-level-retries 3; then
    REMOTE_CREATED=1
    echo 'write_access=ok'
else
    echo 'write_access=failed' >&2
    exit 1
fi

REMOTE_SHA="$(rclone cat "${REMOTE_OBJECT}" | sha256sum | awk '{print $1}')"
if [[ "${REMOTE_SHA}" == "${LOCAL_SHA}" ]]; then
    echo 'read_access=ok'
else
    echo 'read_access=failed' >&2
    exit 1
fi

if rclone deletefile "${REMOTE_OBJECT}"; then
    REMOTE_CREATED=0
    echo 'delete_access=ok'
else
    echo 'delete_access=failed' >&2
    exit 1
fi

if rclone lsf "${PROBE_ROOT}" --recursive --files-only 2>/dev/null | grep -Fqx "${PROBE_ID}.txt"; then
    echo 'delete_verification=failed' >&2
    exit 1
fi

echo 'delete_verification=ok'
echo 'r2_access_probe=ok'

#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$#" -ne 1 ]]; then
  printf '[healthcheck] ERROR: uso: %s <app-dir>\n' "$0" >&2
  exit 1
fi

APP_DIR="$1"
TARGET_HOST="${HEALTHCHECK_HOST:-}"
APP_URL=""
SCHEME=""

[[ "${APP_DIR}" == /* ]] || { printf '[healthcheck] ERROR: app-dir tem de ser absoluto\n' >&2; exit 1; }
[[ -d "${APP_DIR}" ]] || { printf '[healthcheck] ERROR: app-dir inexistente: %s\n' "${APP_DIR}" >&2; exit 1; }

if [[ -f "${APP_DIR}/.env" ]]; then
  APP_URL="$(sed -n 's/^APP_URL=//p' "${APP_DIR}/.env" | tail -n 1 | tr -d '\r"')"
fi

if [[ -z "${TARGET_HOST}" && -n "${APP_URL}" ]]; then
  TARGET_HOST="$(printf '%s' "${APP_URL}" | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*://([^/:]+).*#\1#')"
fi

if [[ -n "${APP_URL}" && "${APP_URL}" == *://* ]]; then
  SCHEME="${APP_URL%%://*}"
fi

TARGET_HOST="${TARGET_HOST:-localhost}"
SCHEME="${SCHEME:-http}"

if [[ "${SCHEME}" == "https" && "${TARGET_HOST}" != "localhost" && "${TARGET_HOST}" != "127.0.0.1" ]]; then
  URL="https://${TARGET_HOST}/up"
  printf '[healthcheck] GET %s via 127.0.0.1:443 ...\n' "${URL}"
  STATUS="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --max-time 10 \
    --resolve "${TARGET_HOST}:443:127.0.0.1" \
    "${URL}")"
else
  URL="http://127.0.0.1/up"
  printf '[healthcheck] GET %s (Host: %s) ...\n' "${URL}" "${TARGET_HOST}"
  STATUS="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --max-time 10 \
    -H "Host: ${TARGET_HOST}" \
    "${URL}")"
fi

printf '[healthcheck] HTTP %s\n' "${STATUS}"
if [[ "${STATUS}" != "200" ]]; then
  printf '[healthcheck] ERROR: /up devolveu HTTP %s\n' "${STATUS}" >&2
  exit 1
fi

printf '[healthcheck] OK\n'

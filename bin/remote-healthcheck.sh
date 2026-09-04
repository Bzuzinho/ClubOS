#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$#" -ne 1 ]]; then
  printf '[healthcheck] ERROR: uso: %s <app-dir>\n' "$0" >&2
  exit 1
fi

APP_DIR="$1"
TARGET_HOST="${HEALTHCHECK_HOST:-}"
ALIAS_HOSTS="${HEALTHCHECK_ALIAS_HOSTS:-}"
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

if [[ "${TARGET_HOST}" == "www.bscn.pt" ]]; then
  printf '[healthcheck] ERROR: APP_URL não pode usar o alias www; esperado https://bscn.pt\n' >&2
  exit 1
fi

request_local_https() {
  local host="$1"
  local path="$2"
  local format="$3"

  curl --silent --show-error --output /dev/null --write-out "${format}" \
    --max-time 10 \
    --resolve "${host}:443:127.0.0.1" \
    "https://${host}${path}"
}

if [[ "${SCHEME}" == "https" && "${TARGET_HOST}" != "localhost" && "${TARGET_HOST}" != "127.0.0.1" ]]; then
  for canonical_path in / /login /up; do
    URL="https://${TARGET_HOST}${canonical_path}"
    printf '[healthcheck] canonical GET %s via 127.0.0.1:443 ...\n' "${URL}"
    STATUS="$(request_local_https "${TARGET_HOST}" "${canonical_path}" '%{http_code}')"
    printf '[healthcheck] canonical %s HTTP %s\n' "${canonical_path}" "${STATUS}"

    if [[ "${STATUS}" != "200" ]]; then
      printf '[healthcheck] ERROR: canonical %s devolveu HTTP %s\n' "${canonical_path}" "${STATUS}" >&2
      exit 1
    fi
  done
else
  URL="http://127.0.0.1/up"
  printf '[healthcheck] canonical GET %s (Host: %s) ...\n' "${URL}" "${TARGET_HOST}"
  STATUS="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --max-time 10 \
    -H "Host: ${TARGET_HOST}" \
    "${URL}")"
  printf '[healthcheck] canonical /up HTTP %s\n' "${STATUS}"
  if [[ "${STATUS}" != "200" ]]; then
    printf '[healthcheck] ERROR: canonical /up devolveu HTTP %s\n' "${STATUS}" >&2
    exit 1
  fi
fi

if [[ -z "${ALIAS_HOSTS}" && "${SCHEME}" == "https" && "${TARGET_HOST}" == *.* && "${TARGET_HOST}" != www.* ]]; then
  ALIAS_HOSTS="www.${TARGET_HOST}"
fi

if [[ -n "${ALIAS_HOSTS}" ]]; then
  [[ "${SCHEME}" == "https" ]] || {
    printf '[healthcheck] ERROR: aliases canónicos exigem APP_URL HTTPS\n' >&2
    exit 1
  }

  for alias_host in ${ALIAS_HOSTS//,/ }; do
    [[ -n "${alias_host}" && "${alias_host}" != "${TARGET_HOST}" ]] || continue

    for alias_path in / /login /up; do
      URL="https://${alias_host}${alias_path}"
      EXPECTED_LOCATION="https://${TARGET_HOST}${alias_path}"
      printf '[healthcheck] alias GET %s via 127.0.0.1:443 ...\n' "${URL}"
      RESPONSE="$(request_local_https "${alias_host}" "${alias_path}" '%{http_code}|%{redirect_url}')"
      STATUS="${RESPONSE%%|*}"
      LOCATION="${RESPONSE#*|}"
      printf '[healthcheck] alias %s%s HTTP %s -> %s\n' "${alias_host}" "${alias_path}" "${STATUS}" "${LOCATION:-sem Location}"

      if [[ "${STATUS}" != "301" || "${LOCATION}" != "${EXPECTED_LOCATION}" ]]; then
        printf '[healthcheck] ERROR: %s%s deve devolver 301 para %s (recebido HTTP %s -> %s)\n' \
          "${alias_host}" "${alias_path}" "${EXPECTED_LOCATION}" "${STATUS}" "${LOCATION:-sem Location}" >&2
        exit 1
      fi
    done
  done
fi

printf '[healthcheck] OK\n'

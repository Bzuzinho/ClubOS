#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$#" -ne 3 ]]; then
  printf '[rollback] ERROR: uso: %s <app-dir> <runtime-user> <runtime-group>\n' "$0" >&2
  exit 1
fi

APP_DIR="$1"
RUNTIME_USER="$2"
RUNTIME_GROUP="$3"
DEPLOY_ROOT="${APP_DIR}.deploy"
CURRENT_LINK="${DEPLOY_ROOT}/current"
PREVIOUS_LINK="${DEPLOY_ROOT}/previous"
HEALTHCHECK_SCRIPT="/usr/local/bin/clubmanager-healthcheck.sh"

log() { printf '[rollback] %s\n' "$*"; }
fail() { printf '[rollback] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'o script tem de ser executado como root'
[[ "${APP_DIR}" == /* ]] || fail 'app-dir tem de ser absoluto'
id "${RUNTIME_USER}" >/dev/null 2>&1 || fail "utilizador de runtime inexistente: ${RUNTIME_USER}"
getent group "${RUNTIME_GROUP}" >/dev/null 2>&1 || fail "grupo de runtime inexistente: ${RUNTIME_GROUP}"
[[ -L "${APP_DIR}" ]] || fail "app-dir ainda não usa layout atómico: ${APP_DIR}"
[[ -L "${CURRENT_LINK}" ]] || fail "current não existe: ${CURRENT_LINK}"
[[ -L "${PREVIOUS_LINK}" ]] || fail "previous não existe: ${PREVIOUS_LINK}"
[[ -x "${HEALTHCHECK_SCRIPT}" ]] || fail "healthcheck não encontrado: ${HEALTHCHECK_SCRIPT}"

CURRENT_TARGET="$(readlink -f "${CURRENT_LINK}")"
PREVIOUS_TARGET="$(readlink -f "${PREVIOUS_LINK}")"
[[ -d "${CURRENT_TARGET}" ]] || fail "release atual inválida: ${CURRENT_TARGET}"
[[ -d "${PREVIOUS_TARGET}" ]] || fail "release anterior inválida: ${PREVIOUS_TARGET}"
[[ "${CURRENT_TARGET}" != "${PREVIOUS_TARGET}" ]] || fail 'current e previous apontam para a mesma release'
[[ -f "${PREVIOUS_TARGET}/artisan" ]] || fail "release anterior sem artisan: ${PREVIOUS_TARGET}"

switch_link() {
  local link_path="$1"
  local target="$2"
  local next="${link_path}.next.$$"
  rm -f "${next}"
  ln -s "${target}" "${next}"
  mv -Tf "${next}" "${link_path}"
}

run_as_runtime() {
  sudo -u "${RUNTIME_USER}" -H -- "$@"
}

prepare_runtime_caches() {
  local release="$1"
  run_as_runtime php "${release}/artisan" optimize:clear
  run_as_runtime php "${release}/artisan" config:cache
  run_as_runtime php "${release}/artisan" route:cache
  run_as_runtime php "${release}/artisan" view:cache
  run_as_runtime php "${release}/artisan" cache:warm-modules
  run_as_runtime php "${release}/artisan" queue:restart
}

restore_current() {
  log "restaurar release original ${CURRENT_TARGET}"
  switch_link "${CURRENT_LINK}" "${CURRENT_TARGET}"
  systemctl reload php8.3-fpm || service php8.3-fpm reload
  prepare_runtime_caches "${CURRENT_TARGET}" || true
  "${HEALTHCHECK_SCRIPT}" "${APP_DIR}" || true
}

log "rollback ${CURRENT_TARGET} -> ${PREVIOUS_TARGET}"
switch_link "${CURRENT_LINK}" "${PREVIOUS_TARGET}"

if ! prepare_runtime_caches "${PREVIOUS_TARGET}"; then
  restore_current
  fail 'falha ao preparar caches da release anterior; current restaurado'
fi

systemctl reload php8.3-fpm || service php8.3-fpm reload

if ! "${HEALTHCHECK_SCRIPT}" "${APP_DIR}"; then
  restore_current
  fail 'healthcheck falhou após rollback; release original restaurada'
fi

switch_link "${PREVIOUS_LINK}" "${CURRENT_TARGET}"
log "rollback concluído; current=${PREVIOUS_TARGET}; previous=${CURRENT_TARGET}"

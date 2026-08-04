#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${1:-/var/www/clubmanager}"
DEPLOY_USER="${2:-ubuntu}"
RUNTIME_USER="${3:-www-data}"
RUNTIME_GROUP="${4:-www-data}"

log() {
  printf '[backend] %s\n' "$*"
}

fail() {
  printf '[backend] ERROR: %s\n' "$*" >&2
  exit 1
}

if [[ "${EUID}" -ne 0 ]]; then
  fail 'o script tem de ser executado como root'
fi

id "${DEPLOY_USER}" >/dev/null 2>&1 || fail "utilizador de deploy inexistente: ${DEPLOY_USER}"
id "${RUNTIME_USER}" >/dev/null 2>&1 || fail "utilizador de runtime inexistente: ${RUNTIME_USER}"
getent group "${RUNTIME_GROUP}" >/dev/null 2>&1 || fail "grupo de runtime inexistente: ${RUNTIME_GROUP}"

APP_DIR="$(readlink -f "${APP_DIR}")"
[[ -d "${APP_DIR}/.git" ]] || fail "diretório inválido ou sem .git: ${APP_DIR}"

WORK_TREE="$(git -C "${APP_DIR}" rev-parse --show-toplevel)"
GIT_DIR="$(git -C "${APP_DIR}" rev-parse --absolute-git-dir)"
WORK_TREE="$(readlink -f "${WORK_TREE}")"
GIT_DIR="$(readlink -f "${GIT_DIR}")"
DEPLOY_GROUP="$(id -gn "${DEPLOY_USER}")"

[[ "${WORK_TREE}" == "${APP_DIR}" ]] || fail "working tree inesperada: ${WORK_TREE}"
[[ -d "${GIT_DIR}" ]] || fail "git-dir inexistente: ${GIT_DIR}"

run_as_deploy() {
  sudo -u "${DEPLOY_USER}" -H -- "$@"
}

run_git() {
  sudo -u "${DEPLOY_USER}" -H env GIT_TERMINAL_PROMPT=0 git -C "${WORK_TREE}" "$@"
}

run_as_runtime() {
  sudo -u "${RUNTIME_USER}" -H -- "$@"
}

log "normalizar ownership para deploy=${DEPLOY_USER} e runtime=${RUNTIME_USER}"
find "${WORK_TREE}" -xdev ! -path "${WORK_TREE}/.env" -exec chown "${DEPLOY_USER}:${DEPLOY_GROUP}" {} +
find "${WORK_TREE}" -xdev ! -path "${WORK_TREE}/.env" -exec chmod u+rwX,go+rX {} +
chown -R "${DEPLOY_USER}:${DEPLOY_GROUP}" "${GIT_DIR}"
find "${GIT_DIR}" -type d -exec chmod 700 {} +
find "${GIT_DIR}" -type f -exec chmod 600 {} +
rm -f "${GIT_DIR}/index.lock" "${GIT_DIR}/FETCH_HEAD.lock" "${GIT_DIR}/shallow.lock"
install -o "${DEPLOY_USER}" -g "${DEPLOY_GROUP}" -m 600 /dev/null "${GIT_DIR}/FETCH_HEAD"
run_as_deploy test -w "${GIT_DIR}/FETCH_HEAD" || fail "${DEPLOY_USER} não consegue escrever em ${GIT_DIR}/FETCH_HEAD"

log "sync git como ${DEPLOY_USER}"
run_git fetch --prune origin main
run_git checkout -f main
run_git reset --hard origin/main
run_git clean -fd

LOCAL_HEAD="$(run_git rev-parse HEAD)"
REMOTE_HEAD="$(run_git rev-parse origin/main)"
[[ "${LOCAL_HEAD}" == "${REMOTE_HEAD}" ]] || fail "HEAD local não corresponde a origin/main"
log "git sincronizado em ${LOCAL_HEAD}"

[[ -f "${WORK_TREE}/.env" ]] || fail "ficheiro .env não encontrado"
usermod -a -G "${RUNTIME_GROUP}" "${DEPLOY_USER}"
chgrp "${RUNTIME_GROUP}" "${WORK_TREE}/.env"
chmod 640 "${WORK_TREE}/.env"
run_as_deploy test -r "${WORK_TREE}/.env" || fail "${DEPLOY_USER} não consegue ler o .env"
run_as_runtime test -r "${WORK_TREE}/.env" || fail "${RUNTIME_USER} não consegue ler o .env"

log "composer install como ${DEPLOY_USER}"
run_as_deploy composer --working-dir="${WORK_TREE}" install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader

log "preparar diretórios graváveis do Laravel"
install -d -o "${RUNTIME_USER}" -g "${RUNTIME_GROUP}" -m 775 \
  "${WORK_TREE}/storage" \
  "${WORK_TREE}/storage/app" \
  "${WORK_TREE}/storage/app/public" \
  "${WORK_TREE}/storage/framework" \
  "${WORK_TREE}/storage/framework/cache" \
  "${WORK_TREE}/storage/framework/cache/data" \
  "${WORK_TREE}/storage/framework/sessions" \
  "${WORK_TREE}/storage/framework/views" \
  "${WORK_TREE}/storage/logs" \
  "${WORK_TREE}/bootstrap/cache"
chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" "${WORK_TREE}/storage" "${WORK_TREE}/bootstrap/cache"
find "${WORK_TREE}/storage" "${WORK_TREE}/bootstrap/cache" -type d -exec chmod 775 {} +
find "${WORK_TREE}/storage" "${WORK_TREE}/bootstrap/cache" -type f -exec chmod 664 {} +

log "Laravel migrations e caches como ${RUNTIME_USER}"
run_as_deploy php "${WORK_TREE}/artisan" storage:link || true
run_as_runtime php "${WORK_TREE}/artisan" optimize:clear
run_as_runtime php "${WORK_TREE}/artisan" migrate --force
run_as_runtime php "${WORK_TREE}/artisan" access-control:sync-permission-nodes
run_as_runtime php "${WORK_TREE}/artisan" config:cache
run_as_runtime php "${WORK_TREE}/artisan" route:cache
run_as_runtime php "${WORK_TREE}/artisan" view:cache
run_as_runtime php "${WORK_TREE}/artisan" cache:warm-modules
run_as_runtime php "${WORK_TREE}/artisan" queue:restart

chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" "${WORK_TREE}/storage" "${WORK_TREE}/bootstrap/cache"
find "${WORK_TREE}/storage" "${WORK_TREE}/bootstrap/cache" -type d -exec chmod 775 {} +
find "${WORK_TREE}/storage" "${WORK_TREE}/bootstrap/cache" -type f -exec chmod 664 {} +

log "reload php-fpm"
systemctl reload php8.3-fpm || service php8.3-fpm reload

log "done"

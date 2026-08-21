#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$#" -ne 7 ]]; then
  printf '[deploy] ERROR: uso: %s <app-dir> <deploy-user> <runtime-user> <runtime-group> <expected-sha> <frontend-build-dir> <repo-url>\n' "$0" >&2
  exit 1
fi

APP_DIR="$1"
DEPLOY_USER="$2"
RUNTIME_USER="$3"
RUNTIME_GROUP="$4"
EXPECTED_SHA="$5"
FRONTEND_BUILD_DIR="$6"
REPO_URL="$7"

DEPLOY_ROOT="${APP_DIR}.deploy"
REPOSITORY_DIR="${DEPLOY_ROOT}/repository.git"
RELEASES_DIR="${DEPLOY_ROOT}/releases"
SHARED_DIR="${DEPLOY_ROOT}/shared"
SHARED_ENV="${SHARED_DIR}/.env"
SHARED_STORAGE="${SHARED_DIR}/storage"
CURRENT_LINK="${DEPLOY_ROOT}/current"
PREVIOUS_LINK="${DEPLOY_ROOT}/previous"
LEGACY_DIR_ROOT="${DEPLOY_ROOT}/legacy"
LEGACY_PERSISTENCE_ROOT="${DEPLOY_ROOT}/legacy-persistence"
HEALTHCHECK_SCRIPT="/usr/local/bin/clubmanager-healthcheck.sh"
ROLLBACK_SCRIPT="/usr/local/bin/clubmanager-rollback-release.sh"
RELEASE_RETENTION="${RELEASE_RETENTION:-5}"
DEPLOY_GROUP=""
INITIAL_LAYOUT=false
RELEASE_DIR=""
RELEASE_ID=""
ROLLBACK_TARGET=""
APP_SWAP_PATH=""
MAINTENANCE_ENGAGED=false
SWITCHED=false
ROLLBACK_DONE=false
DEPLOY_SUCCEEDED=false

log() { printf '[deploy] %s\n' "$*"; }
warn() { printf '[deploy] WARN: %s\n' "$*" >&2; }
fail() { printf '[deploy] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'o script tem de ser executado como root'
[[ -n "${APP_DIR}" && "${APP_DIR}" == /* && "${APP_DIR}" != "/" ]] || fail 'app-dir inválido'
[[ -n "${DEPLOY_USER}" ]] || fail 'deploy-user vazio'
[[ -n "${RUNTIME_USER}" ]] || fail 'runtime-user vazio'
[[ -n "${RUNTIME_GROUP}" ]] || fail 'runtime-group vazio'
[[ "${EXPECTED_SHA}" =~ ^[0-9a-fA-F]{40}$ ]] || fail 'expected-sha tem de ser um SHA Git de 40 caracteres'
[[ -n "${REPO_URL}" ]] || fail 'repo-url vazio'
[[ "${FRONTEND_BUILD_DIR}" == /* ]] || fail 'frontend-build-dir tem de ser absoluto'
[[ -f "${FRONTEND_BUILD_DIR}/manifest.json" ]] || fail "frontend build sem manifest.json: ${FRONTEND_BUILD_DIR}"
[[ "${RELEASE_RETENTION}" =~ ^[1-9][0-9]*$ ]] || fail 'RELEASE_RETENTION tem de ser inteiro positivo'

for command in git composer php curl rsync tar python3 sudo find readlink; do
  command -v "${command}" >/dev/null 2>&1 || fail "comando obrigatório não encontrado: ${command}"
done

id "${DEPLOY_USER}" >/dev/null 2>&1 || fail "utilizador de deploy inexistente: ${DEPLOY_USER}"
id "${RUNTIME_USER}" >/dev/null 2>&1 || fail "utilizador de runtime inexistente: ${RUNTIME_USER}"
getent group "${RUNTIME_GROUP}" >/dev/null 2>&1 || fail "grupo de runtime inexistente: ${RUNTIME_GROUP}"
DEPLOY_GROUP="$(id -gn "${DEPLOY_USER}")"
usermod -a -G "${RUNTIME_GROUP}" "${DEPLOY_USER}"

run_as_deploy() {
  sudo -u "${DEPLOY_USER}" -H -- "$@"
}

run_as_runtime() {
  sudo -u "${RUNTIME_USER}" -H -- "$@"
}

switch_link() {
  local link_path="$1"
  local target="$2"
  local next="${link_path}.next.$$"

  [[ -e "${target}" || -L "${target}" ]] || fail "target de symlink inexistente: ${target}"
  rm -f "${next}"
  ln -s "${target}" "${next}"
  mv -Tf "${next}" "${link_path}"
}

atomic_exchange() {
  local first="$1"
  local second="$2"

  python3 - "${first}" "${second}" <<'PY'
import ctypes
import os
import sys

first = os.fsencode(sys.argv[1])
second = os.fsencode(sys.argv[2])
libc = ctypes.CDLL(None, use_errno=True)
renameat2 = getattr(libc, "renameat2", None)
if renameat2 is None:
    raise SystemExit("renameat2 indisponível; cutover inicial recusado")
renameat2.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
renameat2.restype = ctypes.c_int
AT_FDCWD = -100
RENAME_EXCHANGE = 2
if renameat2(AT_FDCWD, first, AT_FDCWD, second, RENAME_EXCHANGE) != 0:
    error = ctypes.get_errno()
    raise OSError(error, os.strerror(error), sys.argv[1], sys.argv[2])
PY
}

test_atomic_exchange_support() {
  local test_root="${DEPLOY_ROOT}/.exchange-test.$$"
  local dir_path="${test_root}.dir"
  local link_path="${test_root}.link"
  rm -rf "${dir_path}" "${link_path}"
  mkdir -p "${dir_path}"
  ln -s "${dir_path}" "${link_path}"
  atomic_exchange "${dir_path}" "${link_path}"
  atomic_exchange "${dir_path}" "${link_path}"
  rm -rf "${dir_path}" "${link_path}"
}

release_internal_healthcheck() {
  local release="$1"
  local port log_file pid status=""

  port="$(python3 - <<'PY'
import socket
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    print(sock.getsockname()[1])
PY
)"
  log_file="/tmp/clubos-release-health-${RELEASE_ID}-$$.log"

  pid="$(sudo -u "${RUNTIME_USER}" -H bash -c '
    cd "$1"
    php -S "127.0.0.1:$2" -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >"$3" 2>&1 &
    echo $!
  ' _ "${release}" "${port}" "${log_file}")"

  for _ in $(seq 1 30); do
    status="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 2 "http://127.0.0.1:${port}/up" 2>/dev/null || true)"
    if [[ "${status}" == "200" ]]; then
      kill "${pid}" >/dev/null 2>&1 || true
      rm -f "${log_file}"
      log "pre-cutover /up OK na release ${RELEASE_ID}"
      return 0
    fi
    if ! kill -0 "${pid}" >/dev/null 2>&1; then
      break
    fi
    sleep 0.2
  done

  kill "${pid}" >/dev/null 2>&1 || true
  warn "pre-cutover /up falhou (HTTP ${status:-sem resposta})"
  sed -n '1,120p' "${log_file}" >&2 2>/dev/null || true
  rm -f "${log_file}"
  return 1
}

prepare_release_runtime_after_switch() {
  local release="$1"
  run_as_runtime php "${release}/artisan" up || true
  run_as_runtime php "${release}/artisan" view:clear
  run_as_runtime php "${release}/artisan" view:cache
  run_as_runtime php "${release}/artisan" cache:warm-modules
  run_as_runtime php "${release}/artisan" queue:restart
}

rollback_after_failed_switch() {
  local target="$1"

  [[ -n "${target}" && -d "${target}" && -f "${target}/artisan" ]] || {
    warn "rollback automático impossível: target inválido (${target:-vazio})"
    return 1
  }

  warn "rollback automático para ${target}; migrations de BD não são revertidas"
  switch_link "${CURRENT_LINK}" "${target}"
  run_as_runtime php "${target}/artisan" up || true
  run_as_runtime php "${target}/artisan" optimize:clear || true
  run_as_runtime php "${target}/artisan" config:cache || true
  run_as_runtime php "${target}/artisan" route:cache || true
  run_as_runtime php "${target}/artisan" view:cache || true
  run_as_runtime php "${target}/artisan" cache:warm-modules || true
  run_as_runtime php "${target}/artisan" queue:restart || true
  systemctl reload php8.3-fpm || service php8.3-fpm reload || true

  if "${HEALTHCHECK_SCRIPT}" "${APP_DIR}"; then
    ROLLBACK_DONE=true
    SWITCHED=false
    MAINTENANCE_ENGAGED=false
    warn "rollback concluído com healthcheck OK"
    return 0
  fi

  warn 'rollback efetuado, mas o healthcheck da release anterior também falhou'
  return 1
}

cleanup_old_releases() {
  local current_target previous_target path index=0
  current_target="$(readlink -f "${CURRENT_LINK}" 2>/dev/null || true)"
  previous_target="$(readlink -f "${PREVIOUS_LINK}" 2>/dev/null || true)"

  while IFS= read -r path; do
    index=$((index + 1))
    if (( index <= RELEASE_RETENTION )); then
      continue
    fi
    if [[ "${path}" == "${current_target}" || "${path}" == "${previous_target}" ]]; then
      continue
    fi
    log "remover release antiga ${path}"
    rm -rf --one-file-system "${path}"
  done < <(find "${RELEASES_DIR}" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | cut -d' ' -f2-)
}

on_exit() {
  local status=$?
  set +e

  if (( status != 0 )) && [[ "${DEPLOY_SUCCEEDED}" != "true" ]]; then
    if [[ "${SWITCHED}" == "true" && "${ROLLBACK_DONE}" != "true" && -n "${ROLLBACK_TARGET}" ]]; then
      rollback_after_failed_switch "${ROLLBACK_TARGET}" || true
    elif [[ "${MAINTENANCE_ENGAGED}" == "true" && -f "${APP_DIR}/artisan" ]]; then
      warn 'falha antes do cutover; retirar modo de manutenção da aplicação antiga'
      run_as_runtime php "${APP_DIR}/artisan" up || true
      MAINTENANCE_ENGAGED=false
    fi

    if [[ -n "${RELEASE_DIR}" && -d "${RELEASE_DIR}" ]]; then
      local current_target
      current_target="$(readlink -f "${CURRENT_LINK}" 2>/dev/null || true)"
      if [[ "${current_target}" != "${RELEASE_DIR}" ]]; then
        rm -rf --one-file-system "${RELEASE_DIR}" || true
      fi
    fi

    if [[ "${INITIAL_LAYOUT}" == "true" && "${SWITCHED}" != "true" ]]; then
      local current_target
      current_target="$(readlink -f "${CURRENT_LINK}" 2>/dev/null || true)"
      if [[ -n "${RELEASE_DIR}" && "${current_target}" == "${RELEASE_DIR}" ]]; then
        rm -f "${CURRENT_LINK}" || true
      fi
    fi
  fi

  if [[ -n "${APP_SWAP_PATH}" && -L "${APP_SWAP_PATH}" ]]; then
    rm -f "${APP_SWAP_PATH}" || true
  fi

  exit "${status}"
}
trap on_exit EXIT

log "target=${APP_DIR}; commit=${EXPECTED_SHA}"

if [[ -L "${APP_DIR}" ]]; then
  INITIAL_LAYOUT=false
  [[ -L "${CURRENT_LINK}" ]] || fail "app-dir é symlink mas current não existe: ${CURRENT_LINK}"
  [[ "$(readlink -f "${APP_DIR}")" == "$(readlink -f "${CURRENT_LINK}")" ]] \
    || fail 'app-dir e current não apontam para a mesma release'
elif [[ -d "${APP_DIR}" && -d "${APP_DIR}/.git" ]]; then
  INITIAL_LAYOUT=true
  [[ -f "${APP_DIR}/.env" ]] || fail "layout legado sem .env: ${APP_DIR}"
  [[ -d "${APP_DIR}/storage" ]] || fail "layout legado sem storage: ${APP_DIR}"
  if [[ -n "$(sudo -u "${DEPLOY_USER}" -H git -C "${APP_DIR}" status --porcelain)" ]]; then
    fail 'working tree produtiva contém alterações locais; migração atómica recusada'
  fi
else
  fail "layout produtivo desconhecido em ${APP_DIR}"
fi

install -d -o "${DEPLOY_USER}" -g "${DEPLOY_GROUP}" -m 755 \
  "${DEPLOY_ROOT}" "${RELEASES_DIR}" "${LEGACY_DIR_ROOT}"
install -d -o root -g root -m 700 "${LEGACY_PERSISTENCE_ROOT}"
install -d -o "${RUNTIME_USER}" -g "${RUNTIME_GROUP}" -m 2770 "${SHARED_DIR}"

if [[ "${INITIAL_LAYOUT}" == "true" ]]; then
  log 'preparar estado partilhado a partir do layout legado'
  install -o "${RUNTIME_USER}" -g "${RUNTIME_GROUP}" -m 640 "${APP_DIR}/.env" "${SHARED_ENV}"
  install -d -o "${RUNTIME_USER}" -g "${RUNTIME_GROUP}" -m 2775 "${SHARED_STORAGE}"
  rsync -a --chown="${RUNTIME_USER}:${RUNTIME_GROUP}" "${APP_DIR}/storage/" "${SHARED_STORAGE}/"
else
  [[ -f "${SHARED_ENV}" ]] || fail "shared .env inexistente: ${SHARED_ENV}"
  [[ -d "${SHARED_STORAGE}" ]] || fail "shared storage inexistente: ${SHARED_STORAGE}"
fi

install -d -o "${RUNTIME_USER}" -g "${RUNTIME_GROUP}" -m 2775 \
  "${SHARED_STORAGE}/app" \
  "${SHARED_STORAGE}/app/public" \
  "${SHARED_STORAGE}/framework" \
  "${SHARED_STORAGE}/framework/cache" \
  "${SHARED_STORAGE}/framework/cache/data" \
  "${SHARED_STORAGE}/framework/sessions" \
  "${SHARED_STORAGE}/framework/views" \
  "${SHARED_STORAGE}/logs"

if [[ ! -d "${REPOSITORY_DIR}/objects" ]]; then
  log 'inicializar mirror Git de deployment'
  if [[ "${INITIAL_LAYOUT}" == "true" ]]; then
    run_as_deploy git clone --mirror "${APP_DIR}" "${REPOSITORY_DIR}"
  else
    run_as_deploy env GIT_TERMINAL_PROMPT=0 git clone --mirror "${REPO_URL}" "${REPOSITORY_DIR}"
  fi
fi
chown -R "${DEPLOY_USER}:${DEPLOY_GROUP}" "${REPOSITORY_DIR}"
run_as_deploy git --git-dir="${REPOSITORY_DIR}" remote set-url origin "${REPO_URL}"
run_as_deploy env GIT_TERMINAL_PROMPT=0 git --git-dir="${REPOSITORY_DIR}" fetch --prune origin \
  '+refs/heads/main:refs/remotes/origin/main'
REMOTE_HEAD="$(run_as_deploy git --git-dir="${REPOSITORY_DIR}" rev-parse refs/remotes/origin/main)"
[[ "${REMOTE_HEAD}" == "${EXPECTED_SHA}" ]] || fail "origin/main=${REMOTE_HEAD}, esperado=${EXPECTED_SHA}"
run_as_deploy git --git-dir="${REPOSITORY_DIR}" cat-file -e "${EXPECTED_SHA}^{commit}"

RELEASE_ID="$(date -u +%Y%m%d%H%M%S)-${EXPECTED_SHA:0:12}"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_ID}"
[[ ! -e "${RELEASE_DIR}" ]] || fail "release já existe: ${RELEASE_DIR}"
install -d -o "${DEPLOY_USER}" -g "${DEPLOY_GROUP}" -m 755 "${RELEASE_DIR}"

log "extrair release ${RELEASE_ID}"
run_as_deploy bash -c 'set -euo pipefail; git --git-dir="$1" archive "$2" | tar -x -C "$3"' \
  _ "${REPOSITORY_DIR}" "${EXPECTED_SHA}" "${RELEASE_DIR}"

rm -f "${RELEASE_DIR}/.env"
ln -s "${SHARED_ENV}" "${RELEASE_DIR}/.env"
rm -rf "${RELEASE_DIR}/storage"
ln -s "${SHARED_STORAGE}" "${RELEASE_DIR}/storage"
install -d -o "${RUNTIME_USER}" -g "${RUNTIME_GROUP}" -m 2775 "${RELEASE_DIR}/bootstrap/cache"
rm -rf "${RELEASE_DIR}/public/storage" "${RELEASE_DIR}/public/build"
ln -s "${SHARED_STORAGE}/app/public" "${RELEASE_DIR}/public/storage"
install -d -o "${DEPLOY_USER}" -g "${DEPLOY_GROUP}" -m 755 "${RELEASE_DIR}/public/build"
cp -a "${FRONTEND_BUILD_DIR}/." "${RELEASE_DIR}/public/build/"
chown -R "${DEPLOY_USER}:${DEPLOY_GROUP}" "${RELEASE_DIR}/public/build"
find "${RELEASE_DIR}/public/build" -type d -exec chmod 755 {} +
find "${RELEASE_DIR}/public/build" -type f -exec chmod 644 {} +
[[ -f "${RELEASE_DIR}/public/build/manifest.json" ]] || fail 'manifest frontend ausente na release'

cat > "${RELEASE_DIR}/.clubos-release" <<META
commit_sha=${EXPECTED_SHA}
release_id=${RELEASE_ID}
deployed_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
layout=atomic-v1
META
chown "${DEPLOY_USER}:${DEPLOY_GROUP}" "${RELEASE_DIR}/.clubos-release"
chmod 644 "${RELEASE_DIR}/.clubos-release"

log 'composer install na release isolada'
run_as_deploy composer --working-dir="${RELEASE_DIR}" install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader

log 'instalar helpers operacionais versionados'
[[ -f "${RELEASE_DIR}/bin/remote-healthcheck.sh" ]] || fail 'remote-healthcheck.sh ausente na release'
[[ -f "${RELEASE_DIR}/bin/remote-release-rollback.sh" ]] || fail 'remote-release-rollback.sh ausente na release'
install -o root -g root -m 755 "${RELEASE_DIR}/bin/remote-healthcheck.sh" "${HEALTHCHECK_SCRIPT}"
install -o root -g root -m 755 "${RELEASE_DIR}/bin/remote-release-rollback.sh" "${ROLLBACK_SCRIPT}"
cat > /usr/local/bin/clubmanager-deploy-backend.sh <<'DISABLED_HELPER'
#!/usr/bin/env bash
printf '[deploy] Este helper legado foi desativado. O deploy produtivo usa GitHub Actions + releases atómicas.\n' >&2
exit 64
DISABLED_HELPER
chmod 755 /usr/local/bin/clubmanager-deploy-backend.sh

log 'alinhar limites de upload e validar Nginx antes do cutover'
install -d -m 755 /etc/php/8.3/fpm/conf.d /etc/nginx/conf.d
cat > /etc/php/8.3/fpm/conf.d/99-clubmanager-uploads.ini <<'PHP_UPLOADS'
upload_max_filesize = 10M
post_max_size = 12M
max_file_uploads = 20
PHP_UPLOADS
cat > /etc/nginx/conf.d/clubmanager-uploads.conf <<'NGINX_UPLOADS'
# ClubOS Website accepts images up to 8 MiB. Keep transport limits above the
# application validation limit so Laravel can return a useful validation error.
client_max_body_size 12M;
NGINX_UPLOADS
nginx -t

log 'preparar caches release-local e validar base de dados'
run_as_runtime php "${RELEASE_DIR}/artisan" config:clear
run_as_runtime php "${RELEASE_DIR}/artisan" route:clear
run_as_runtime php "${RELEASE_DIR}/artisan" config:cache
run_as_runtime php "${RELEASE_DIR}/artisan" route:cache
run_as_runtime php "${RELEASE_DIR}/artisan" migrate:status --no-ansi >/dev/null

log 'migration preflight'
run_as_runtime php "${RELEASE_DIR}/artisan" migrate --pretend --force --no-ansi

log 'aplicar migrations antes do cutover; migrations devem ser backward-compatible'
run_as_runtime php "${RELEASE_DIR}/artisan" migrate --force --no-ansi
run_as_runtime php "${RELEASE_DIR}/artisan" access-control:sync-permission-nodes --no-ansi
run_as_runtime php "${RELEASE_DIR}/artisan" config:cache
run_as_runtime php "${RELEASE_DIR}/artisan" route:cache

release_internal_healthcheck "${RELEASE_DIR}" || fail 'healthcheck interno pre-cutover falhou'

if [[ "${INITIAL_LAYOUT}" == "true" ]]; then
  log 'validar suporte do filesystem a cutover inicial atómico'
  test_atomic_exchange_support

  LEGACY_TIMESTAMP="$(date -u +%Y%m%d%H%M%S)"
  LIVE_HEAD="$(sudo -u "${DEPLOY_USER}" -H git -C "${APP_DIR}" rev-parse HEAD)"
  LEGACY_DIR="${LEGACY_DIR_ROOT}/pre-releases-${LEGACY_TIMESTAMP}-${LIVE_HEAD:0:12}"
  PERSISTENCE_BACKUP="${LEGACY_PERSISTENCE_ROOT}/${LEGACY_TIMESTAMP}"
  install -d -o root -g root -m 700 "${PERSISTENCE_BACKUP}"

  log 'primeiro cutover: breve maintenance window para sincronizar storage sem perda de escrita'
  run_as_runtime php "${APP_DIR}/artisan" down --retry=5 --no-ansi
  MAINTENANCE_ENGAGED=true
  rsync -a --chown="${RUNTIME_USER}:${RUNTIME_GROUP}" "${APP_DIR}/storage/" "${SHARED_STORAGE}/"

  mv "${APP_DIR}/.env" "${PERSISTENCE_BACKUP}/.env"
  chmod 600 "${PERSISTENCE_BACKUP}/.env"
  ln -s "${SHARED_ENV}" "${APP_DIR}/.env"
  mv "${APP_DIR}/storage" "${PERSISTENCE_BACKUP}/storage"
  ln -s "${SHARED_STORAGE}" "${APP_DIR}/storage"

  switch_link "${CURRENT_LINK}" "${RELEASE_DIR}"
  APP_SWAP_PATH="${APP_DIR}.atomic-next.$$"
  rm -rf "${APP_SWAP_PATH}"
  ln -s "${CURRENT_LINK}" "${APP_SWAP_PATH}"

  log 'trocar path compatível /var/www/... de diretório para symlink sem janela intermédia'
  ROLLBACK_TARGET="${APP_SWAP_PATH}"
  atomic_exchange "${APP_DIR}" "${APP_SWAP_PATH}"
  SWITCHED=true

  mv "${APP_SWAP_PATH}" "${LEGACY_DIR}"
  APP_SWAP_PATH=""
  ROLLBACK_TARGET="${LEGACY_DIR}"
  switch_link "${PREVIOUS_LINK}" "${ROLLBACK_TARGET}"
else
  ROLLBACK_TARGET="$(readlink -f "${CURRENT_LINK}")"
  [[ -d "${ROLLBACK_TARGET}" && -f "${ROLLBACK_TARGET}/artisan" ]] || fail "release anterior inválida: ${ROLLBACK_TARGET}"
  switch_link "${PREVIOUS_LINK}" "${ROLLBACK_TARGET}"
  log "cutover atómico current: ${ROLLBACK_TARGET} -> ${RELEASE_DIR}"
  switch_link "${CURRENT_LINK}" "${RELEASE_DIR}"
  SWITCHED=true
fi

if ! prepare_release_runtime_after_switch "${RELEASE_DIR}"; then
  rollback_after_failed_switch "${ROLLBACK_TARGET}" || true
  fail 'falha ao preparar runtime depois do cutover; rollback tentado'
fi
MAINTENANCE_ENGAGED=false

log 'reload php-fpm para limpar OPcache após troca do symlink'
if ! (systemctl reload php8.3-fpm || service php8.3-fpm reload); then
  rollback_after_failed_switch "${ROLLBACK_TARGET}" || true
  fail 'reload php-fpm falhou; rollback tentado'
fi

if ! "${HEALTHCHECK_SCRIPT}" "${APP_DIR}"; then
  rollback_after_failed_switch "${ROLLBACK_TARGET}" || true
  fail 'healthcheck pós-cutover falhou; rollback tentado'
fi

[[ -L "${APP_DIR}" ]] || fail 'app-dir não ficou como symlink após cutover'
[[ "$(readlink -f "${APP_DIR}")" == "${RELEASE_DIR}" ]] || fail 'app-dir não aponta para a release esperada'
[[ "$(readlink -f "${CURRENT_LINK}")" == "${RELEASE_DIR}" ]] || fail 'current não aponta para a release esperada'
[[ "$(sed -n 's/^commit_sha=//p' "${APP_DIR}/.clubos-release")" == "${EXPECTED_SHA}" ]] || fail 'metadata da release não corresponde ao commit esperado'

DEPLOY_SUCCEEDED=true
if ! cleanup_old_releases; then
  warn 'cleanup de releases antigas falhou; deploy mantém-se ativo'
fi

log "deploy atómico concluído: release=${RELEASE_ID}; current=${RELEASE_DIR}; previous=${ROLLBACK_TARGET}"
log 'nota: rollback automático nunca reverte migrations; migrations produtivas têm de ser backward-compatible'

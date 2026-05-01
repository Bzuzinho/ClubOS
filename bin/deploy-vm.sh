#!/usr/bin/env bash
# deploy-vm.sh — Deploy Codespace → VM Oracle Ubuntu 22.04
# Usage: npm run deploy:vm
set -euo pipefail

# ===== Config =====
VM_USER="${VM_USER:-ubuntu}"
VM_HOST="${VM_HOST:-clubmanager-vm}"
VM_APP_DIR="${VM_APP_DIR:-/var/www/clubmanager}"
SSH_KEY="${SSH_KEY:-${HOME}/.ssh/id_ed25519}"
SSH_ALIAS="clubmanager-vm"
SSH_CONFIG="${HOME}/.ssh/config"
KNOWN_HOSTS="${HOME}/.ssh/known_hosts"
REMOTE_BACKEND_SCRIPT="/usr/local/bin/clubmanager-deploy-backend.sh"
REMOTE_FRONTEND_SCRIPT="/usr/local/bin/clubmanager-frontend-reload.sh"
REMOTE_HEALTHCHECK_SCRIPT="/usr/local/bin/clubmanager-healthcheck.sh"
SYNC_MAIL_ENV="${SYNC_MAIL_ENV:-0}"
APP_NAME="${APP_NAME:-ClubOS}"
MAIL_MAILER="${MAIL_MAILER:-}"
MAIL_HOST="${MAIL_HOST:-}"
MAIL_PORT="${MAIL_PORT:-}"
MAIL_USERNAME="${MAIL_USERNAME:-}"
MAIL_PASSWORD="${MAIL_PASSWORD:-}"
MAIL_ENCRYPTION="${MAIL_ENCRYPTION:-}"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-}"
MAIL_FROM_NAME="${MAIL_FROM_NAME:-}"
APP_URL="${APP_URL:-}"
ALLOW_SHARED_DB="${ALLOW_SHARED_DB:-0}"
SHARED_DB_TARGET=0

echo "==> Repo: $(pwd)"
echo "==> VM:   ${VM_USER}@${VM_HOST}:${VM_APP_DIR}"

# ===== Helper: run ssh via alias (BatchMode, 15s timeout) =====
vm_ssh() {
  ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_ALIAS}" "$@"
}

read_local_env_value() {
  local key="$1"
  local env_file=".env"

  if [[ ! -f "${env_file}" ]]; then
    return 0
  fi

  grep -E "^${key}=" "${env_file}" | tail -n 1 | cut -d'=' -f2-
}

read_remote_env_value() {
  local key="$1"

  vm_ssh "grep -E '^${key}=' '${VM_APP_DIR}/.env' 2>/dev/null | tail -n 1 | cut -d'=' -f2-" | tr -d '\r'
}

guard_shared_database_target() {
  local local_app_env local_db_host local_db_database local_db_username
  local remote_db_host remote_db_database remote_db_username

  local_app_env="$(read_local_env_value APP_ENV | tr -d '"')"
  local_db_host="$(read_local_env_value DB_HOST | tr -d '"')"
  local_db_database="$(read_local_env_value DB_DATABASE | tr -d '"')"
  local_db_username="$(read_local_env_value DB_USERNAME | tr -d '"')"

  remote_db_host="$(read_remote_env_value DB_HOST | tr -d '"')"
  remote_db_database="$(read_remote_env_value DB_DATABASE | tr -d '"')"
  remote_db_username="$(read_remote_env_value DB_USERNAME | tr -d '"')"

  if [[ -z "${local_db_host}" || -z "${local_db_database}" || -z "${remote_db_host}" || -z "${remote_db_database}" ]]; then
    echo "   ⚠ Não foi possível validar se o Codespace e a VM usam a mesma base de dados."
    return 0
  fi

  if [[ "${local_db_host}" == "${remote_db_host}" && "${local_db_database}" == "${remote_db_database}" && "${local_db_username}" == "${remote_db_username}" ]]; then
    SHARED_DB_TARGET=1
    echo ""
    echo "❌ Guardrail: o ambiente local e a VM apontam para a mesma base de dados (${local_db_host}/${local_db_database})."
    echo "   Isto é perigoso: comandos locais (benchmarks, tinker, scripts) podem alterar dados da VM."

    if [[ "${local_app_env}" != "production" && "${ALLOW_SHARED_DB}" != "1" ]]; then
      echo "   Abortar deploy."
      echo "   Corrige o .env local para usar uma base separada ou usa ALLOW_SHARED_DB=1 se for intencional."
      exit 1
    fi

    echo "   ⚠ Continuação forçada porque APP_ENV=${local_app_env:-unknown} ou ALLOW_SHARED_DB=1."
  else
    echo "   ✔ Guardrail DB OK — Codespace e VM usam bases distintas"
  fi
}

sync_shared_db_club_logo() {
  local logo_url relative_path relative_dir local_file

  if [[ "${SHARED_DB_TARGET}" != "1" ]]; then
    return 0
  fi

  logo_url="$(php artisan tinker --execute="print(App\\Models\\ClubSetting::query()->value('logo_url') ?? '');" 2>/dev/null | tr -d '\r')"

  if [[ -z "${logo_url}" || "${logo_url}" != /storage/* ]]; then
    echo "    Sem logotipo do clube em /storage para sincronizar"
    return 0
  fi

  relative_path="${logo_url#/storage/}"
  relative_dir="$(dirname "${relative_path}")"
  local_file="storage/app/public/${relative_path}"

  if [[ ! -f "${local_file}" ]]; then
    echo "   ⚠ Logotipo referenciado na base de dados não existe localmente: ${local_file}"
    return 0
  fi

  echo "    Sincronizar logotipo do clube (${relative_path}) ..."
  tar -C storage/app/public -cf - "${relative_path}" | vm_ssh "sudo mkdir -p '${VM_APP_DIR}/storage/app/public/${relative_dir}' && sudo tar -xf - -C '${VM_APP_DIR}/storage/app/public' && sudo chown www-data:www-data '${VM_APP_DIR}/storage/app/public/${relative_path}'"
  echo "   ✔ Logotipo do clube sincronizado"
}

quote_env_value() {
  local value="${1:-}"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  printf '"%s"' "${value}"
}

configure_vm_mail_env() {
  local remote_env_file="${VM_APP_DIR}/.env"

  if [[ "${SYNC_MAIL_ENV}" != "1" ]]; then
    echo "    SMTP sync desligado (usa SYNC_MAIL_ENV=1 para actualizar MAIL_* na VM)"
    return 0
  fi

  if [[ -z "${MAIL_MAILER}" ]]; then
    echo "❌ SYNC_MAIL_ENV=1 mas MAIL_MAILER não foi definido."
    exit 1
  fi

  if [[ "${MAIL_MAILER}" == "smtp" ]]; then
    local required_vars=(MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS MAIL_FROM_NAME)
    local missing=()
    local var_name

    for var_name in "${required_vars[@]}"; do
      if [[ -z "${!var_name:-}" ]]; then
        missing+=("${var_name}")
      fi
    done

    if (( ${#missing[@]} > 0 )); then
      echo "❌ Faltam variáveis SMTP obrigatórias: ${missing[*]}"
      exit 1
    fi
  fi

  echo ""
  echo "==> [3.5/6] Atualizar configuração de mail na VM"

  vm_ssh "bash -s" <<EOF
set -euo pipefail
ENV_FILE="${remote_env_file}"

if [[ ! -f "\${ENV_FILE}" ]]; then
  echo "[mail] ficheiro .env não encontrado: \${ENV_FILE}"
  exit 1
fi

upsert_env() {
  local key="\$1"
  local value="\$2"
  if grep -q "^\${key}=" "\${ENV_FILE}"; then
    local escaped_value="\${value//\\/\\\\}"
    escaped_value="\${escaped_value//&/\\&}"
    escaped_value="\${escaped_value//|/\\|}"
    sed -i "s|^\${key}=.*|\${key}=\${escaped_value}|" "\${ENV_FILE}"
  else
    printf '\n%s=%s\n' "\${key}" "\${value}" >> "\${ENV_FILE}"
  fi
}

upsert_env APP_NAME $(quote_env_value "${APP_NAME}")
upsert_env MAIL_MAILER $(quote_env_value "${MAIL_MAILER}")
upsert_env MAIL_HOST $(quote_env_value "${MAIL_HOST}")
upsert_env MAIL_PORT $(quote_env_value "${MAIL_PORT}")
upsert_env MAIL_USERNAME $(quote_env_value "${MAIL_USERNAME}")
upsert_env MAIL_PASSWORD $(quote_env_value "${MAIL_PASSWORD}")
upsert_env MAIL_ENCRYPTION $(quote_env_value "${MAIL_ENCRYPTION}")
upsert_env MAIL_FROM_ADDRESS $(quote_env_value "${MAIL_FROM_ADDRESS}")
upsert_env MAIL_FROM_NAME $(quote_env_value "${MAIL_FROM_NAME}")

if [[ -n "${APP_URL}" ]]; then
  upsert_env APP_URL $(quote_env_value "${APP_URL}")
fi

echo "[mail] configuração MAIL_* actualizada"
EOF

  echo "   ✔ Configuração MAIL_* sincronizada para a VM"
}

# ===== Helper: show failure logs from VM =====
show_failure_logs() {
  echo ""
  echo "--- Laravel log (last 80 lines) ---"
  vm_ssh "tail -n 80 ${VM_APP_DIR}/storage/logs/laravel.log 2>/dev/null || true"
  echo ""
  echo "--- Nginx error log (last 40 lines) ---"
  vm_ssh "tail -n 40 /var/log/nginx/error.log 2>/dev/null || true"
}

# ===== [0/6] Pre-flight: repo state =====
echo ""
echo "==> [0/6] Verificar estado do repo"

if [[ "$(git rev-parse --abbrev-ref HEAD 2>/dev/null)" != "main" ]]; then
  echo "❌ Tens de estar na branch main (actual: $(git rev-parse --abbrev-ref HEAD))."
  exit 1
fi

if [[ -n "$(git status --porcelain 2>/dev/null)" ]]; then
  echo "❌ Existem alterações locais não commitadas. Faça commit/push manualmente antes de executar o deploy."
  exit 1
fi

echo "   ✔ branch=main, working tree limpo"

# ===== [1/6] Build frontend no Codespace =====
echo ""
echo "==> [1/6] Build frontend (Codespace)"
echo "    npm ci ..."
npm ci

echo "    npm run build ..."
NODE_OPTIONS="--max-old-space-size=4096" npm run build

if [[ ! -f "public/build/manifest.json" ]]; then
  echo "❌ Build não gerou public/build/manifest.json — abortar."
  exit 1
fi
echo "   ✔ Build OK (public/build/manifest.json presente)"

# ===== [2/6] Validar sincronização Git =====
echo ""
echo "==> [2/6] Confirmar que main já foi enviada conscientemente"

echo "    fetch origin/main ..."
git fetch origin main

LOCAL_HEAD="$(git rev-parse HEAD)"
REMOTE_HEAD="$(git rev-parse origin/main)"

if [[ "${LOCAL_HEAD}" != "${REMOTE_HEAD}" ]]; then
  echo "❌ O commit local atual ainda não corresponde a origin/main."
  echo "   Faça commit/push manualmente antes de executar o deploy."
  exit 1
fi

echo "   ✔ main local sincronizada com origin/main"

# ===== [3/6] Garantir SSH funcional =====
echo ""
echo "==> [3/6] Configurar SSH para a VM"

mkdir -p "${HOME}/.ssh"
chmod 700 "${HOME}/.ssh"

# 3a) Criar chave ed25519 se não existir
if [[ ! -f "${SSH_KEY}" ]]; then
  echo "    Gerar chave SSH ed25519 (sem passphrase) ..."
  ssh-keygen -t ed25519 -C "codespace-deploy" -N "" -f "${SSH_KEY}"
  echo "   ✔ Chave criada: ${SSH_KEY}"
else
  echo "   ✔ Chave já existe: ${SSH_KEY}"
fi

# 3b) Adicionar host key ao known_hosts (idempotente)
echo "    Actualizar known_hosts via ssh-keyscan ..."
ssh-keyscan -H "${VM_HOST}" >> "${KNOWN_HOSTS}" 2>/dev/null || true
# Deduplicate
sort -u "${KNOWN_HOSTS}" -o "${KNOWN_HOSTS}" 2>/dev/null || true
echo "   ✔ known_hosts actualizado"

# 3c) Criar/actualizar ~/.ssh/config com alias clubmanager-vm
if ! grep -q "Host ${SSH_ALIAS}" "${SSH_CONFIG}" 2>/dev/null; then
  echo "    Adicionar alias '${SSH_ALIAS}' ao ${SSH_CONFIG} ..."
  cat >> "${SSH_CONFIG}" <<SSHCONFIG

# --- Adicionado por deploy-vm.sh ---
Host ${SSH_ALIAS}
  HostName ${VM_HOST}
  User ${VM_USER}
  IdentityFile ${SSH_KEY}
  IdentitiesOnly yes
  StrictHostKeyChecking accept-new
  ServerAliveInterval 60
SSHCONFIG
  chmod 600 "${SSH_CONFIG}"
  echo "   ✔ Alias '${SSH_ALIAS}' adicionado ao SSH config"
else
  echo "   ✔ Alias '${SSH_ALIAS}' já existe no SSH config"
fi

# 3d) Instalar chave pública na VM (tenta via ssh; se falhar, mostra instrução manual)
PUB_KEY_CONTENT="$(cat "${SSH_KEY}.pub")"

echo "    Tentar instalar chave pública na VM ..."
if ssh -o BatchMode=yes -o ConnectTimeout=15 \
     -o StrictHostKeyChecking=accept-new \
     -i "${SSH_KEY}" "${VM_USER}@${VM_HOST}" \
     "mkdir -p ~/.ssh && chmod 700 ~/.ssh && \
      grep -qxF '${PUB_KEY_CONTENT}' ~/.ssh/authorized_keys 2>/dev/null || \
      echo '${PUB_KEY_CONTENT}' >> ~/.ssh/authorized_keys && \
      chmod 600 ~/.ssh/authorized_keys && \
      chown -R ${VM_USER}:${VM_USER} ~/.ssh && \
      echo PUBKEY_OK" 2>/dev/null | grep -q "PUBKEY_OK"; then
  echo "   ✔ Chave pública instalada/verificada na VM"
else
  echo "   ⚠ Não foi possível instalar a chave automaticamente."
  echo "     Instala manualmente na VM:"
  echo "       echo '${PUB_KEY_CONTENT}' >> /home/${VM_USER}/.ssh/authorized_keys"
  echo "       chmod 700 /home/${VM_USER}/.ssh && chmod 600 /home/${VM_USER}/.ssh/authorized_keys"
  echo "     Depois corre novamente: npm run deploy:vm"
  exit 1
fi

# 3e) Validar SSH via alias
echo "    Validar SSH com alias '${SSH_ALIAS}' ..."
SSH_TEST="$(vm_ssh 'echo SSH_OK && hostname' 2>&1)" || {
  echo "❌ SSH falhou com alias '${SSH_ALIAS}'."
  echo "   Output: ${SSH_TEST}"
  echo "   Verifica ~/.ssh/config e authorized_keys na VM."
  exit 1
}
echo "   ✔ SSH OK — ${SSH_TEST}"

echo "    Validar isolamento da base de dados ..."
guard_shared_database_target

configure_vm_mail_env

# ===== [4/6] Deploy backend na VM =====
echo ""
echo "==> [4/6] Deploy backend na VM"

# Garantir que os scripts remotos existem; criá-los via heredoc se necessário
vm_ssh "bash -s" <<'ENSURE_SCRIPTS'
set -e

# --- clubmanager-deploy-backend.sh ---
echo "  Atualizar /usr/local/bin/clubmanager-deploy-backend.sh ..."
sudo tee /usr/local/bin/clubmanager-deploy-backend.sh > /dev/null <<'BACKEND'
#!/usr/bin/env bash
set -euo pipefail
APP_DIR="${1:-/var/www/clubmanager}"
APP_USER="${APP_USER:-www-data}"

if ! command -v git >/dev/null 2>&1; then
  echo "[backend] git não encontrado"
  exit 1
fi

if [[ ! -d "${APP_DIR}/.git" ]]; then
  echo "[backend] diretório inválido ou sem .git: ${APP_DIR}"
  exit 1
fi

if id -u "${APP_USER}" >/dev/null 2>&1; then
  RUN_AS=(sudo -u "${APP_USER}" -H)
else
  RUN_AS=()
fi

echo "[backend] sync git (origin/main)"
"${RUN_AS[@]}" git -C "${APP_DIR}" fetch --prune origin main

if [[ -n "$("${RUN_AS[@]}" git -C "${APP_DIR}" status --porcelain)" ]]; then
  TS="$(date +%Y%m%d-%H%M%S)"
  echo "[backend] alterações locais detectadas; criar stash auto-deploy-${TS}"
  "${RUN_AS[@]}" git -C "${APP_DIR}" stash push -u -m "auto-deploy-${TS}" || true
fi

"${RUN_AS[@]}" git -C "${APP_DIR}" checkout main
"${RUN_AS[@]}" git -C "${APP_DIR}" reset --hard origin/main
"${RUN_AS[@]}" git -C "${APP_DIR}" clean -fd

echo "[backend] composer install"
"${RUN_AS[@]}" composer --working-dir="${APP_DIR}" install --no-dev --optimize-autoloader
echo "[backend] ensure storage symlink"
"${RUN_AS[@]}" mkdir -p "${APP_DIR}/storage/app/public"
"${RUN_AS[@]}" php "${APP_DIR}/artisan" storage:link || true
echo "[backend] migrate"
"${RUN_AS[@]}" php "${APP_DIR}/artisan" migrate --force
echo "[backend] sync permission nodes"
"${RUN_AS[@]}" php "${APP_DIR}/artisan" access-control:sync-permission-nodes
echo "[backend] cache"
"${RUN_AS[@]}" php "${APP_DIR}/artisan" config:cache
"${RUN_AS[@]}" php "${APP_DIR}/artisan" route:cache
"${RUN_AS[@]}" php "${APP_DIR}/artisan" view:cache
echo "[backend] warm authenticated module caches"
"${RUN_AS[@]}" php "${APP_DIR}/artisan" cache:warm-modules
echo "[backend] fix writable permissions"
sudo chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
sudo find "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" -type d -exec chmod 775 {} +
sudo find "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" -type f -exec chmod 664 {} +
echo "[backend] reload php-fpm"
sudo systemctl reload php8.3-fpm || sudo service php8.3-fpm reload
echo "[backend] done"
BACKEND
sudo chmod +x /usr/local/bin/clubmanager-deploy-backend.sh

# --- clubmanager-frontend-reload.sh ---
echo "  Atualizar /usr/local/bin/clubmanager-frontend-reload.sh ..."
sudo tee /usr/local/bin/clubmanager-frontend-reload.sh > /dev/null <<'FRONTEND'
#!/usr/bin/env bash
set -euo pipefail
SITE_CONFIG="/etc/nginx/sites-enabled/clubmanager"
ASSET_SNIPPET="/etc/nginx/snippets/clubmanager-static-assets.conf"

if [[ ! -f "${SITE_CONFIG}" ]]; then
  echo "[frontend] nginx site config não encontrado: ${SITE_CONFIG}"
  exit 1
fi

echo "[frontend] garantir snippet de performance para /build/assets"
sudo tee "${ASSET_SNIPPET}" > /dev/null <<'SNIPPET'
location ^~ /build/assets/ {
    access_log off;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript text/javascript application/xml text/xml image/svg+xml;
    try_files $uri =404;
}
SNIPPET

if ! sudo grep -qF 'include /etc/nginx/snippets/clubmanager-static-assets.conf;' "${SITE_CONFIG}"; then
  echo "[frontend] inserir include de assets no server block"
  sudo python3 - <<'PY'
from pathlib import Path

site_config = Path('/etc/nginx/sites-enabled/clubmanager')
content = site_config.read_text()
needle = '    location / {\n'
replacement = '    include /etc/nginx/snippets/clubmanager-static-assets.conf;\n\n    location / {\n'

if 'include /etc/nginx/snippets/clubmanager-static-assets.conf;' not in content:
    if needle not in content:
        raise SystemExit('location / block not found in nginx site config')
    content = content.replace(needle, replacement, 1)
    site_config.write_text(content)
PY
fi

echo "[frontend] validar nginx config"
sudo nginx -t
echo "[frontend] reload nginx"
sudo systemctl reload nginx || sudo service nginx reload
echo "[frontend] done"
FRONTEND
sudo chmod +x /usr/local/bin/clubmanager-frontend-reload.sh

# --- clubmanager-healthcheck.sh ---
echo "  Atualizar /usr/local/bin/clubmanager-healthcheck.sh ..."
sudo tee /usr/local/bin/clubmanager-healthcheck.sh > /dev/null <<'HEALTH'
#!/usr/bin/env bash
set -euo pipefail
APP_DIR="${1:-/var/www/clubmanager}"
TARGET_HOST="${HEALTHCHECK_HOST:-}"

if [[ -z "${TARGET_HOST}" && -f "${APP_DIR}/.env" ]]; then
  TARGET_HOST="$(sed -n 's/^APP_URL=//p' "${APP_DIR}/.env" | tail -n 1 | tr -d '"' | sed -E 's#^[a-zA-Z]+://([^/:]+).*#\1#')"
fi

if [[ -z "${TARGET_HOST}" ]]; then
  TARGET_HOST="localhost"
fi

echo "[healthcheck] curl -Is http://127.0.0.1/ (Host: ${TARGET_HOST}) ..."
RESPONSE="$(curl -Is --max-time 10 -H "Host: ${TARGET_HOST}" "http://127.0.0.1/" | head -1)"
echo "[healthcheck] ${RESPONSE}"
if echo "${RESPONSE}" | grep -qE "^HTTP/[0-9.]+ (200|301|302|303)"; then
  echo "[healthcheck] OK"
else
  echo "[healthcheck] WARN: resposta inesperada: ${RESPONSE}"
  exit 1
fi
HEALTH
sudo chmod +x /usr/local/bin/clubmanager-healthcheck.sh

echo "Scripts remotos OK"
ENSURE_SCRIPTS

echo "   ✔ Scripts remotos verificados/criados"

# Correr deploy backend
if ! vm_ssh "/usr/local/bin/clubmanager-deploy-backend.sh '${VM_APP_DIR}'"; then
  echo "❌ Deploy backend falhou."
  show_failure_logs
  exit 1
fi
echo "   ✔ Backend deploy OK"

# ===== [5/6] Copiar public/build para a VM =====
echo ""
echo "==> [5/6] Upload public/build → VM"
tar -C public -cf - build | vm_ssh "sudo mkdir -p '${VM_APP_DIR}/public' && sudo rm -rf '${VM_APP_DIR}/public/build' && sudo tar -xf - -C '${VM_APP_DIR}/public' && sudo chown -R www-data:www-data '${VM_APP_DIR}/public/build'"
echo "   ✔ public/build copiado"

sync_shared_db_club_logo

# ===== [6/6] Reload frontend + healthcheck =====
echo ""
echo "==> [6/6] Reload frontend + healthcheck"

if ! vm_ssh "/usr/local/bin/clubmanager-frontend-reload.sh"; then
  echo "❌ Frontend reload falhou."
  show_failure_logs
  exit 1
fi

if ! vm_ssh "/usr/local/bin/clubmanager-healthcheck.sh '${VM_APP_DIR}'"; then
  echo "❌ Healthcheck falhou."
  show_failure_logs
  exit 1
fi

echo ""
echo "✅ Deploy completo OK."
echo "🌍 Abre: http://${VM_HOST}"

#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

[[ "${DB1_CONFIRM_SWITCH_TO_LOCAL_POSTGRES:-}" == "SWITCH_TO_LOCAL_POSTGRES" ]] || die "Set DB1_CONFIRM_SWITCH_TO_LOCAL_POSTGRES=SWITCH_TO_LOCAL_POSTGRES to switch production"

LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
require_var LOCAL_DB_PASSWORD

[[ "${LOCAL_DB_HOST}" == "127.0.0.1" || "${LOCAL_DB_HOST}" == "localhost" ]] || die "Refusing to switch production to non-local DB host: ${LOCAL_DB_HOST}"
[[ -f .env ]] || die ".env not found in current directory"
command -v python3 >/dev/null 2>&1 || die "python3 is required"

BACKUP_PATH=".env.backup-before-local-postgres-$(date +%Y%m%d-%H%M%S)"
log "Backing up .env to ${BACKUP_PATH}"
cp .env "${BACKUP_PATH}"
chmod 600 "${BACKUP_PATH}"

log "Updating .env database variables for local PostgreSQL"
DB_HOST_VALUE="${LOCAL_DB_HOST}" \
DB_PORT_VALUE="${LOCAL_DB_PORT}" \
DB_DATABASE_VALUE="${LOCAL_DB_NAME}" \
DB_USERNAME_VALUE="${LOCAL_DB_USER}" \
DB_PASSWORD_VALUE="${LOCAL_DB_PASSWORD}" \
python3 - <<'PY'
from pathlib import Path
import os

path = Path(".env")
lines = path.read_text().splitlines()
updates = {
    "DB_CONNECTION": "pgsql",
    "DB_URL": "",
    "DB_HOST": os.environ["DB_HOST_VALUE"],
    "DB_PORT": os.environ["DB_PORT_VALUE"],
    "DB_DATABASE": os.environ["DB_DATABASE_VALUE"],
    "DB_USERNAME": os.environ["DB_USERNAME_VALUE"],
    "DB_PASSWORD": os.environ["DB_PASSWORD_VALUE"],
    "DB_SSLMODE": "prefer",
    "DB_CONNECT_TIMEOUT": "5",
}

seen = set()
out = []
for line in lines:
    if "=" in line and not line.lstrip().startswith("#"):
        key = line.split("=", 1)[0]
        if key in updates:
            out.append(f"{key}={updates[key]}")
            seen.add(key)
            continue
    out.append(line)

for key, value in updates.items():
    if key not in seen:
        out.append(f"{key}={value}")

path.write_text("\n".join(out) + "\n")
PY
chmod 600 .env

log "Clearing Laravel caches and reloading services"
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx

log "Production now points to local PostgreSQL. Backup: ${BACKUP_PATH}"

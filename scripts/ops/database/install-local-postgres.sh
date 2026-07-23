#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
require_var LOCAL_DB_PASSWORD

command -v sudo >/dev/null 2>&1 || die "sudo is required"
command -v python3 >/dev/null 2>&1 || die "python3 is required"

log "Installing PostgreSQL packages"
sudo apt update
sudo apt install -y postgresql postgresql-contrib

log "Ensuring PostgreSQL service is enabled and running"
sudo systemctl enable postgresql
sudo systemctl start postgresql

log "Creating local database role if missing"
LOCAL_DB_USER="${LOCAL_DB_USER}" LOCAL_DB_PASSWORD="${LOCAL_DB_PASSWORD}" python3 - <<'PY' | sudo -u postgres psql -v ON_ERROR_STOP=1
import os

user = os.environ["LOCAL_DB_USER"].replace('"', '""')
password = os.environ["LOCAL_DB_PASSWORD"].replace("'", "''")

print(f"""
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{os.environ["LOCAL_DB_USER"].replace("'", "''")}') THEN
        CREATE ROLE "{user}" LOGIN PASSWORD '{password}';
    ELSE
        ALTER ROLE "{user}" WITH LOGIN PASSWORD '{password}';
    END IF;
END
$$;
""")
PY

log "Creating local database if missing"
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname = '${LOCAL_DB_NAME}'" | grep -q 1; then
    sudo -u postgres createdb -O "${LOCAL_DB_USER}" "${LOCAL_DB_NAME}"
fi

log "Restricting database privileges to local application role"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "${LOCAL_DB_NAME}" <<SQL
REVOKE ALL ON DATABASE "${LOCAL_DB_NAME}" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE "${LOCAL_DB_NAME}" TO "${LOCAL_DB_USER}";
GRANT ALL PRIVILEGES ON SCHEMA public TO "${LOCAL_DB_USER}";
SQL

log "Validating local connection"
PGPASSWORD="${LOCAL_DB_PASSWORD}" psql -h 127.0.0.1 -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 -c 'select 1;' >/dev/null

log "PostgreSQL local database is ready. Do not expose port 5432 publicly."

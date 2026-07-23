#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

[[ "${DB1_CONFIRM_ROLLBACK_TO_NEON:-}" == "ROLLBACK_TO_NEON" ]] || die "Set DB1_CONFIRM_ROLLBACK_TO_NEON=ROLLBACK_TO_NEON to rollback"
require_var ENV_BACKUP_PATH
[[ -f "${ENV_BACKUP_PATH}" ]] || die "ENV_BACKUP_PATH not found: ${ENV_BACKUP_PATH}"

log "Restoring .env from ${ENV_BACKUP_PATH}"
cp "${ENV_BACKUP_PATH}" .env
chmod 600 .env

log "Clearing Laravel caches and reloading services"
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx

log "Rollback completed. Production .env restored from backup."

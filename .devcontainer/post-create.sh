#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "${REPO_ROOT}"

echo "[post-create] composer install"
composer install --no-interaction --prefer-dist

echo "[post-create] npm ci"
npm ci

if [[ ! -f .env && -f .env.example ]]; then
  echo "[post-create] creating .env from .env.example"
  cp .env.example .env
fi

if [[ -f .env ]]; then
  app_key="$(grep -E '^APP_KEY=' .env | tail -n 1 | cut -d'=' -f2- || true)"

  if [[ -z "${app_key}" ]]; then
    echo "[post-create] generating APP_KEY"
    php artisan key:generate --force --no-interaction
  else
    echo "[post-create] APP_KEY already defined"
  fi
fi

echo "[post-create] complete"
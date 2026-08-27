#!/usr/bin/env bash
set -Eeuo pipefail

die() { printf '[dr-r2-lock] ERROR: %s\n' "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "missing required variable: ${name}"; }

require_var CF_ACCOUNT_ID
require_var CF_R2_BUCKET
require_var CF_API_TOKEN

DR_R2_PREFIX="${DR_R2_PREFIX:-clubos-prod}"
CF_R2_JURISDICTION="${CF_R2_JURISDICTION:-default}"
CF_API_BASE_URL="${CF_API_BASE_URL:-https://api.cloudflare.com/client/v4}"

[[ "${CF_ACCOUNT_ID}" =~ ^[A-Fa-f0-9]{32}$ ]] || die 'CF_ACCOUNT_ID must be a 32-character hexadecimal Cloudflare account id'
[[ "${CF_R2_BUCKET}" =~ ^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$ ]] || die 'CF_R2_BUCKET is invalid'
[[ "${DR_R2_PREFIX}" != /* && "${DR_R2_PREFIX}" != *'..'* ]] || die 'DR_R2_PREFIX is invalid'
[[ "${CF_R2_JURISDICTION}" =~ ^(default|eu|us|fedramp)$ ]] || die 'CF_R2_JURISDICTION must be default, eu, us or fedramp'
[[ "${CF_API_BASE_URL}" == https://* || "${CF_API_BASE_URL}" == http://127.0.0.1:* || "${CF_API_BASE_URL}" == http://localhost:* ]] || die 'CF_API_BASE_URL must use HTTPS outside local tests'

command -v curl >/dev/null 2>&1 || die 'curl is required'
command -v python3 >/dev/null 2>&1 || die 'python3 is required'

RESPONSE_FILE="$(mktemp /tmp/clubos-r2-lock.XXXXXX.json)"
cleanup() {
    rm -f -- "${RESPONSE_FILE}" >/dev/null 2>&1 || true
}
trap cleanup EXIT
umask 077

curl \
    --fail \
    --silent \
    --show-error \
    --retry 2 \
    --header "Authorization: Bearer ${CF_API_TOKEN}" \
    --header 'Content-Type: application/json' \
    --header "cf-r2-jurisdiction: ${CF_R2_JURISDICTION}" \
    "${CF_API_BASE_URL%/}/accounts/${CF_ACCOUNT_ID}/r2/buckets/${CF_R2_BUCKET}/lock" \
    > "${RESPONSE_FILE}"

python3 - "${RESPONSE_FILE}" "${DR_R2_PREFIX}" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

response_path = Path(sys.argv[1])
prefix = sys.argv[2].strip('/')
response = json.loads(response_path.read_text(encoding='utf-8'))

if response.get('success') is not True:
    errors = response.get('errors') or []
    raise SystemExit(f'Cloudflare API returned success=false: {errors!r}')

rules = (response.get('result') or {}).get('rules') or []
requirements = {
    'daily': 604800,
    'weekly': 2419200,
    'monthly': 31968000,
}

for tier, minimum_seconds in requirements.items():
    expected_prefix = f'{prefix}/{tier}/'
    candidates = [
        rule for rule in rules
        if rule.get('enabled') is True and rule.get('prefix') == expected_prefix
    ]
    if not candidates:
        raise SystemExit(f'missing enabled Bucket Lock rule for prefix {expected_prefix}')

    valid = []
    for rule in candidates:
        condition = rule.get('condition') or {}
        if condition.get('type') != 'Age':
            continue
        try:
            configured = int(condition.get('maxAgeSeconds'))
        except (TypeError, ValueError):
            continue
        if configured >= minimum_seconds:
            valid.append((rule.get('id') or 'unknown', configured))

    if not valid:
        raise SystemExit(
            f'Bucket Lock rule for {expected_prefix} must be Age >= {minimum_seconds} seconds'
        )

    rule_id, configured = max(valid, key=lambda item: item[1])
    print(
        f'bucket_lock_{tier}=ok prefix={expected_prefix} '
        f'rule={rule_id} age_seconds={configured}'
    )

print('r2_bucket_lock=ok')
PY

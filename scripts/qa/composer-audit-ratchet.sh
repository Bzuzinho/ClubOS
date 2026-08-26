#!/usr/bin/env bash
set -Eeuo pipefail

report="${1:-${RUNNER_TEMP:-/tmp}/clubos-composer-audit.json}"
audit_status=0
composer audit --locked --no-interaction --format=json > "${report}" || audit_status=$?

jq -e '.advisories | (type == "object" or type == "array")' "${report}" >/dev/null

advisory_count="$(jq '[.advisories[]?[]?] | length' "${report}")"
critical_count="$(jq '[.advisories[]?[]? | select(((.severity // "") | ascii_downcase) == "critical")] | length' "${report}")"
high_count="$(jq '[.advisories[]?[]? | select(((.severity // "") | ascii_downcase) == "high")] | length' "${report}")"

printf 'Composer security ratchet: %s advisory/advisories; critical=%s; high=%s.\n' \
  "${advisory_count}" "${critical_count}" "${high_count}"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  {
    echo '## Composer dependency security ratchet'
    echo ''
    echo "- Advisories: \`${advisory_count}\`"
    echo "- Critical: \`${critical_count}\`"
    echo "- High: \`${high_count}\`"
    echo "- Composer audit exit code: \`${audit_status}\`"
  } >> "${GITHUB_STEP_SUMMARY}"
fi

if (( audit_status > 3 )); then
  echo "::error::composer audit failed unexpectedly with exit code ${audit_status}"
  exit "${audit_status}"
fi

if (( advisory_count > 0 )); then
  echo "::error::Composer dependency security baseline regressed: zero advisories are permitted after H1.14"
  jq -r 'if (.advisories | type) == "object" then .advisories | to_entries[]? | .key as $pkg | .value[]? | "- \($pkg): \(.advisoryId // .cve // .title // "advisory") [\(.severity // "unknown")]" else empty end' "${report}" || true
  exit 1
fi

printf 'Composer security ratchet passed at zero advisories.\n'

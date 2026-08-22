#!/usr/bin/env bash
set -Eeuo pipefail

report="${1:-${RUNNER_TEMP:-/tmp}/clubos-composer-audit.json}"
audit_status=0
composer audit --locked --no-interaction --format=json > "${report}" || audit_status=$?

jq -e '.advisories | type == "object"' "${report}" >/dev/null

advisory_count="$(jq '[.advisories[]?[]?] | length' "${report}")"
critical_count="$(jq '[.advisories[]?[]? | select(((.severity // "") | ascii_downcase) == "critical")] | length' "${report}")"
high_count="$(jq '[.advisories[]?[]? | select(((.severity // "") | ascii_downcase) == "high")] | length' "${report}")"
laravel_count="$(jq '[.advisories["laravel/framework"][]?] | length' "${report}")"
unexpected_packages="$(jq -r '.advisories | keys[]? | select(. != "laravel/framework")' "${report}")"

printf 'Composer security ratchet: %s advisory/advisories; critical=%s; high=%s; laravel/framework=%s.\n' \
  "${advisory_count}" "${critical_count}" "${high_count}" "${laravel_count}"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  {
    echo '## Composer dependency security ratchet'
    echo ''
    echo "- Advisories: \`${advisory_count}\`"
    echo "- Critical: \`${critical_count}\`"
    echo "- High: \`${high_count}\`"
    echo "- Residual Laravel 11 advisories: \`${laravel_count}\`"
    echo "- Composer audit exit code: \`${audit_status}\`"
  } >> "${GITHUB_STEP_SUMMARY}"
fi

if (( audit_status > 3 )); then
  echo "::error::composer audit failed unexpectedly with exit code ${audit_status}"
  exit "${audit_status}"
fi

if (( critical_count > 0 )); then
  echo "::error::Composer audit reported ${critical_count} critical advisory/advisories"
  exit 1
fi

if [[ -n "${unexpected_packages}" ]]; then
  echo '::error::Composer security debt regressed outside the accepted Laravel 11 residual baseline'
  printf 'Unexpected vulnerable package(s):\n%s\n' "${unexpected_packages}"
  exit 1
fi

if (( advisory_count > 3 || laravel_count > 3 || high_count > 1 )); then
  echo "::error::Composer advisory baseline regressed (max total=3, Laravel=3, high=1)"
  exit 1
fi

if (( advisory_count > 0 )); then
  echo '::warning::Residual Laravel 11 advisories remain accepted temporarily; full removal requires the planned Laravel major upgrade.'
fi

printf 'Composer security ratchet passed.\n'

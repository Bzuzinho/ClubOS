#!/usr/bin/env bash
set -Eeuo pipefail

report="${1:-${RUNNER_TEMP:-/tmp}/clubos-composer-audit.json}"
stderr_report="${report}.stderr"
max_attempts="${COMPOSER_AUDIT_ATTEMPTS:-3}"
audit_status=0
valid_report=false
attempt=1

while (( attempt <= max_attempts )); do
  : > "${report}"
  : > "${stderr_report}"
  audit_status=0

  composer audit --locked --no-interaction --format=json > "${report}" 2> "${stderr_report}" || audit_status=$?

  if jq -e 'type == "object" and (.advisories | (type == "object" or type == "array"))' "${report}" >/dev/null 2>&1; then
    valid_report=true
  else
    valid_report=false
  fi

  # Composer audit uses low exit codes for valid findings (for example
  # vulnerable/abandoned packages). Those are security results, not transport
  # failures, and must never be hidden by a retry.
  if [[ "${valid_report}" == true ]] && (( audit_status <= 3 )); then
    break
  fi

  if (( attempt < max_attempts )); then
    echo "::warning::composer audit attempt ${attempt}/${max_attempts} failed technically (exit=${audit_status}, valid_json=${valid_report}); retrying"
    if [[ -s "${stderr_report}" ]]; then
      tail -n 20 "${stderr_report}" >&2
    fi
    sleep $(( attempt * 2 ))
  fi

  attempt=$(( attempt + 1 ))
done

if [[ "${valid_report}" != true ]]; then
  echo "::error::composer audit did not produce a valid JSON security report after ${max_attempts} attempt(s)"
  if [[ -s "${stderr_report}" ]]; then
    cat "${stderr_report}" >&2
  fi
  exit 70
fi

if (( audit_status > 3 )); then
  echo "::error::composer audit failed unexpectedly with exit code ${audit_status} after ${attempt} attempt(s)"
  if [[ -s "${stderr_report}" ]]; then
    cat "${stderr_report}" >&2
  fi
  exit "${audit_status}"
fi

advisory_count="$(jq '[.advisories[]?[]?] | length' "${report}")"
critical_count="$(jq '[.advisories[]?[]? | select(((.severity // "") | ascii_downcase) == "critical")] | length' "${report}")"
high_count="$(jq '[.advisories[]?[]? | select(((.severity // "") | ascii_downcase) == "high")] | length' "${report}")"

printf 'Composer security ratchet: %s advisory/advisories; critical=%s; high=%s; attempts=%s.\n' \
  "${advisory_count}" "${critical_count}" "${high_count}" "${attempt}"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  {
    echo '## Composer dependency security ratchet'
    echo ''
    echo "- Advisories: \`${advisory_count}\`"
    echo "- Critical: \`${critical_count}\`"
    echo "- High: \`${high_count}\`"
    echo "- Composer audit exit code: \`${audit_status}\`"
    echo "- Attempts: \`${attempt}\`"
  } >> "${GITHUB_STEP_SUMMARY}"
fi

if (( advisory_count > 0 )); then
  echo "::error::Composer dependency security baseline regressed: zero advisories are permitted after H1.14"
  jq -r 'if (.advisories | type) == "object" then .advisories | to_entries[]? | .key as $pkg | .value[]? | "- \($pkg): \(.advisoryId // .cve // .title // "advisory") [\(.severity // "unknown")]" else empty end' "${report}" || true
  exit 1
fi

printf 'Composer security ratchet passed at zero advisories.\n'

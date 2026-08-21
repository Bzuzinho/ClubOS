#!/usr/bin/env bash
set -Eeuo pipefail

printf '%s\n' 'bin/deploy-vm.sh é apenas um wrapper de compatibilidade.' >&2
printf '%s\n' 'O deploy canónico usa npm run deploy:vm -> bin/deploy-vm.mjs -> releases atómicas.' >&2

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"
exec npm run deploy:vm

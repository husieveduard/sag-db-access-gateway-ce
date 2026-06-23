#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAILED=0

while IFS= read -r file; do
  echo "Forbidden file: ${file#$ROOT/}"
  FAILED=1
done < <(
  find "$ROOT" \
    -path "$ROOT/.git" -prune -o \
    -path "$ROOT/.work" -prune -o \
    -type f \
    \( -name '.env' -o -name '.env.*' -o -name '*.bak.*' -o -name '*.sql' -o -name '*.dump' \) \
    ! -name '.env.example' \
    -print
)

if [ "$FAILED" -ne 0 ]; then
  exit 1
fi

echo "Public repository preflight: PASSED"

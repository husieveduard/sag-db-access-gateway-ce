#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

python3 - "$ROOT" <<'PY'
from pathlib import Path
import re
import subprocess
import sys

root = Path(sys.argv[1])

tracked = subprocess.check_output(
    [
        "git",
        "-C",
        str(root),
        "ls-files",
        "--cached",
        "--others",
        "--exclude-standard",
        "-z",
    ],
    text=False,
).decode(errors="replace").split("\0")

secret_keys = {
    "APP_KEY",
    "SAG_INTERNAL_TOKEN",
    "SAG_CE_APP_KEY",
    "SAG_CE_POSTGRES_PASSWORD",
    "SAG_CE_REDIS_PASSWORD",
    "DB_PASSWORD",
    "REDIS_PASSWORD",
}

placeholder_markers = (
    "CHANGE_ME",
    "EXAMPLE",
    "YOUR_",
)

safe_literals = {
    "NULL",
    "NONE",
    "FALSE",
}

failed = False

for relative in filter(None, tracked):
    path = Path(relative)
    lower_name = path.name.lower()

    if (
        lower_name == ".env"
        or lower_name.endswith((".pem", ".key", ".p12", ".pfx"))
        or lower_name in {"id_rsa", "id_ed25519"}
    ):
        print(f"Forbidden tracked file: {relative}")
        failed = True
        continue

    absolute = root / path

    try:
        text = absolute.read_text(errors="ignore")
    except OSError:
        continue

    if re.search(
        r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
        text,
    ):
        print(f"Private key material detected in: {relative}")
        failed = True

    for line_no, raw in enumerate(text.splitlines(), start=1):
        match = re.match(
            r"^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*?)\s*$",
            raw,
        )

        if not match:
            continue

        key, value = match.groups()

        if key not in secret_keys:
            continue

        value = value.strip().strip('"').strip("'")
        normalized = value.upper()

        if (
            not value
            or normalized in safe_literals
            or any(
                marker in normalized
                for marker in placeholder_markers
            )
        ):
            continue

        print(
            f"Possible tracked secret: {relative}:{line_no} ({key})"
        )
        failed = True

if failed:
    raise SystemExit(1)
PY

if [ -f "$ROOT/deploy/compose.yaml" ] \
  && [ -f "$ROOT/deploy/.env.example" ]; then
    docker compose \
      --project-directory "$ROOT" \
      --env-file "$ROOT/deploy/.env.example" \
      -f "$ROOT/deploy/compose.yaml" \
      config -q
fi

echo "Public repository preflight: PASSED"

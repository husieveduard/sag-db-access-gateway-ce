#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT/deploy/compose.yaml"
ENV_TEMPLATE="$ROOT/deploy/.env.example"
ENV_FILE="$ROOT/deploy/.env"

ENV_CREATED_BY_INSTALLER=0
DEPLOYMENT_STARTED=0
INSTALL_COMPLETED=0

cleanup() {
  status=$?

  trap - EXIT INT TERM HUP

  if [ "$ENV_CREATED_BY_INSTALLER" -eq 1 ]     && [ "$DEPLOYMENT_STARTED" -eq 0 ]     && [ "$INSTALL_COMPLETED" -eq 0 ]     && [ -f "$ENV_FILE" ]; then
    rm -f "$ENV_FILE"
    echo "Removed incomplete deployment config: $ENV_FILE" >&2
  fi

  exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

compose() {
  docker compose \
    --project-directory "$ROOT" \
    --env-file "$ENV_FILE" \
    -f "$COMPOSE_FILE" \
    "$@"
}

set_env() {
  local key="$1"
  local value="$2"

  case "$value" in
    *"|"*|*$'\n'*)
      echo "Unsupported value for $key" >&2
      exit 1
      ;;
  esac

  sed -i "s|^${key}=.*$|${key}=${value}|" "$ENV_FILE"
}

random_hex() {
  openssl rand -hex "$1"
}

random_app_key() {
  printf 'base64:'
  openssl rand -base64 32 | tr -d '\n'
}

for command in docker openssl sed; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "Required command not found: $command" >&2
    exit 1
  }
done

docker compose version >/dev/null 2>&1 || {
  echo "Docker Compose v2 is required." >&2
  exit 1
}

if [ -e "$ENV_FILE" ]; then
  echo "Refusing to overwrite existing deployment configuration: $ENV_FILE" >&2
  echo "Use the existing file or remove it only when intentionally rebuilding the deployment." >&2
  exit 1
fi

cp "$ENV_TEMPLATE" "$ENV_FILE"
chmod 600 "$ENV_FILE"
ENV_CREATED_BY_INSTALLER=1

default_url="https://$(hostname -f 2>/dev/null || hostname)"

read -r -p "Public console URL [$default_url]: " public_url
public_url="${public_url:-$default_url}"

case "$public_url" in
  https://*)
    ;;
  *)
    echo "Public console URL must begin with https://" >&2
    exit 1
    ;;
esac

read -r -p "Gateway public DNS/IP [$(hostname -f 2>/dev/null || hostname)]: " gateway_host
gateway_host="${gateway_host:-$(hostname -f 2>/dev/null || hostname)}"

set_env SAG_CE_PUBLIC_URL "$public_url"
set_env SAG_CE_GATEWAY_PUBLIC_HOST "$gateway_host"
set_env SAG_CE_APP_KEY "$(random_app_key)"
set_env SAG_CE_INTERNAL_TOKEN "$(random_hex 48)"
set_env SAG_CE_POSTGRES_PASSWORD "$(random_hex 32)"
set_env SAG_CE_REDIS_PASSWORD "$(random_hex 32)"

compose config -q

echo
echo "Building and starting SAG DB Access Gateway CE..."
DEPLOYMENT_STARTED=1
compose up -d --build

echo
echo "Applying database migrations..."
compose exec -T app php artisan migrate --force

echo
echo "Create the initial CE Operator now."
echo "The password is entered interactively and is never written to deploy/.env."
compose exec app php artisan sag:ce-create-admin

echo
echo "Deployment status:"
compose ps

echo
echo "Local health endpoint:"
echo "  http://127.0.0.1:$(grep '^SAG_CE_HTTP_PORT=' "$ENV_FILE" | cut -d= -f2)/api/health"
echo
echo "Configure HTTPS reverse proxy before public use."

INSTALL_COMPLETED=1

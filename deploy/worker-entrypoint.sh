#!/bin/sh
set -eu

cd /var/www/html

echo "Waiting for required database schema..."
until php -r '
require "vendor/autoload.php";

$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

exit(
    Illuminate\Support\Facades\Schema::hasTable("db_access_operations")
        ? 0
        : 1
);
' >/dev/null 2>&1; do
  sleep 2
done
echo "Database schema is ready."

IPC_PID=''
case "${SAG_MANAGED_RUNTIME_ENABLED:-false}" in
    true|TRUE|1|yes|YES|on|ON)
        socket="${SAG_MANAGED_VAULT_SOCKET:-/run/sag-db-gateway/ipc/worker.sock}"
        if [ ! -d "$(dirname "$socket")" ]; then
            echo "Managed IPC directory is unavailable." >&2
            exit 1
        fi
        /usr/local/bin/sag-vault-ipc-worker \
            --socket="$socket" \
            --trusted-peer-uid="$(id -u)" \
            --php=/usr/local/bin/php \
            --artisan=/var/www/html/artisan &
        IPC_PID=$!
        ready=0
        for attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
            if [ -S "$socket" ] && kill -0 "$IPC_PID" 2>/dev/null; then
                ready=1
                break
            fi
            sleep 0.1
        done
        if [ "$ready" != 1 ]; then
            echo "Managed IPC worker failed to become ready." >&2
            kill -TERM "$IPC_PID" 2>/dev/null || true
            wait "$IPC_PID" 2>/dev/null || true
            exit 1
        fi
        ;;
esac

scheduler_loop() {
    while :; do
        php artisan schedule:run --no-interaction || true

        sleep 60 &
        wait "$!"
    done
}

shutdown() {
    code="${1:-0}"

    trap - INT TERM

    kill -TERM "$WORKER_PID" "$SCHEDULER_PID" 2>/dev/null || true
    if [ -n "$IPC_PID" ]; then
        kill -TERM "$IPC_PID" 2>/dev/null || true
    fi

    wait "$WORKER_PID" 2>/dev/null || true
    wait "$SCHEDULER_PID" 2>/dev/null || true
    if [ -n "$IPC_PID" ]; then
        wait "$IPC_PID" 2>/dev/null || true
    fi

    exit "$code"
}

scheduler_loop &
SCHEDULER_PID=$!

php artisan sag:db-process-operations \
    --loop \
    --sleep-ms=1000 \
    --limit=5 \
    --no-interaction &
WORKER_PID=$!

trap 'shutdown 0' INT TERM

while kill -0 "$WORKER_PID" 2>/dev/null \
    && kill -0 "$SCHEDULER_PID" 2>/dev/null \
    && { [ -z "$IPC_PID" ] || { kill -0 "$IPC_PID" 2>/dev/null \
        && ! grep -q '^State:[[:space:]]*Z' "/proc/$IPC_PID/status"; }; }
do
    sleep 2 &
    wait "$!"
done

status=1

if ! kill -0 "$WORKER_PID" 2>/dev/null; then
    wait "$WORKER_PID" || status=$?
fi

shutdown "$status"

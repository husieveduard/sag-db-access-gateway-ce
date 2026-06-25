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

    wait "$WORKER_PID" 2>/dev/null || true
    wait "$SCHEDULER_PID" 2>/dev/null || true

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
    && kill -0 "$SCHEDULER_PID" 2>/dev/null
do
    sleep 2 &
    wait "$!"
done

status=1

if ! kill -0 "$WORKER_PID" 2>/dev/null; then
    wait "$WORKER_PID" || status=$?
fi

shutdown "$status"

<?php

return [
    'edition' => env('SAG_EDITION', 'ce'),

    'supported_locales' => [
        'uk' => 'Українська',
        'en' => 'English',
    ],

    'db_gateway' => [
        'binary' => env('SAG_DB_GATEWAY_BINARY', '/usr/local/bin/sag-db-gateway'),
        'control_plane_url' => env('SAG_GATEWAY_CONTROL_PLANE_URL', 'http://nginx'),
        'log_dir' => env('SAG_DB_GATEWAY_LOG_DIR', '/var/lib/sag/db-gateway/logs'),

        'listen_host' => env('SAG_DB_GATEWAY_LISTEN_HOST', '0.0.0.0'),
        'public_host' => env('SAG_DB_GATEWAY_PUBLIC_HOST'),

        'port_min' => (int) env('SAG_DB_GATEWAY_PORT_MIN', 16000),
        'port_max' => (int) env('SAG_DB_GATEWAY_PORT_MAX', 17999),

        'termination_driver' => env('SAG_DB_TERMINATION_DRIVER', 'artisan'),

        'maintenance_schedule_enabled' => filter_var(
            env('SAG_DB_MAINTENANCE_SCHEDULE_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
];

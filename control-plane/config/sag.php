<?php

return [
    'edition' => env('SAG_EDITION', 'ce'),

    'internal_token' => env('SAG_INTERNAL_TOKEN'),

    // AD configuration is persisted in ad_settings, not runtime environment.
    'alerts' => [
        'webhook_url' => env('SAG_CE_ALERT_WEBHOOK_URL', ''),
        'allowed_hosts' => env('SAG_CE_ALERT_ALLOWED_HOSTS', ''),
        'telegram_bot_token' => env('SAG_CE_ALERT_TELEGRAM_BOT_TOKEN', ''),
        'telegram_chat_id' => env('SAG_CE_ALERT_TELEGRAM_CHAT_ID', ''),
        'slack_webhook_url' => env('SAG_CE_ALERT_SLACK_WEBHOOK_URL', ''),
        'teams_webhook_url' => env('SAG_CE_ALERT_TEAMS_WEBHOOK_URL', ''),
        'nextcloud_url' => env('SAG_CE_ALERT_NEXTCLOUD_URL', ''),
        'nextcloud_user' => env('SAG_CE_ALERT_NEXTCLOUD_USER', ''),
        'nextcloud_app_password' => env('SAG_CE_ALERT_NEXTCLOUD_APP_PASSWORD', ''),
        'nextcloud_room_token' => env('SAG_CE_ALERT_NEXTCLOUD_ROOM_TOKEN', ''),
        'email_to' => env('SAG_CE_ALERT_EMAIL_TO', ''),
    ],

    'supported_locales' => [
        'uk' => 'Українська',
        'en' => 'English',
    ],

    'db_gateway' => [
        // Explicit development/test opt-in. Production always refuses this path.
        'managed_lab' => [
            'enabled' => filter_var(env('SAG_LAB_MANAGED_BROKER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'mssql_binary' => env('SAG_LAB_MSSQL_BROKER_BINARY', ''),
            'mssql_ca' => env('SAG_LAB_MSSQL_BACKEND_CA', ''),
            'mysql_binary' => env('SAG_LAB_MYSQL_BROKER_BINARY', ''),
            'mysql_ca' => env('SAG_LAB_MYSQL_BACKEND_CA', ''),
            'postgresql_binary' => env('SAG_LAB_POSTGRES_BROKER_BINARY', ''),
            'postgresql_ca' => env('SAG_LAB_POSTGRES_BACKEND_CA', ''),
            'socket' => env('SAG_LAB_VAULT_SOCKET', ''),
            'worker_uid' => env('SAG_LAB_WORKER_UID'),
            'cert' => env('SAG_LAB_BROKER_CERT', ''),
            'key' => env('SAG_LAB_BROKER_KEY', ''),
        ],
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

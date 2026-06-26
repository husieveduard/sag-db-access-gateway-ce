<?php

return [
    'title' => 'Database resources',
    'heading' => 'Database Resources',
    'subtitle' => 'Target databases available for creating gateway sessions.',
    'create' => 'Create resource',

    'columns' => [
        'resource' => 'Resource',
        'engine' => 'Engine',
        'target' => 'Target',
        'tls_auth' => 'TLS / authentication',
        'sql_audit' => 'SQL audit',
        'status' => 'Status',
        'sessions' => 'Sessions',
        'active_gateways' => 'Active gateways',
        'open_connections' => 'Open connections',
        'created_by' => 'Created by',
    ],

    'engine' => [
        'mysql' => 'MySQL / MariaDB',
        'postgresql' => 'PostgreSQL',
        'mssql' => 'Microsoft SQL Server (experimental)',
    ],

    'audit' => [
        'enabled' => 'enabled',
        'disabled' => 'disabled',
    ],

    'status' => [
        'active' => 'active',
        'inactive' => 'inactive',
    ],

    'tls' => [
        'prefer' => 'prefer',
        'require' => 'required',
        'disable' => 'disabled',
        'verify_ca' => 'verify CA',
        'verify_full' => 'full verification',
    ],

    'auth' => [
        'client_passthrough' => 'user credentials',
        'managed' => 'managed credentials',
    ],

    'empty' => 'No database resources have been created yet.',

    'create_page' => [
        'title' => 'Create resource',
        'back' => '← Back to resources',
        'heading' => 'Create database resource',
        'subtitle' => 'A resource describes the target database. Database credentials are not stored here.',

        'name' => 'Resource name',
        'name_placeholder' => 'Unique resource name',
        'engine' => 'Database engine',
        'engine_hint' => 'Microsoft SQL Server support has experimental status.',

        'target_host' => 'Target host',
        'target_host_placeholder' => 'Database server IP address or DNS name',
        'target_port' => 'Target port',

        'database' => 'Database / catalog',
        'target_database_placeholder' => 'Database name',
        'database_hint' => 'Optional field. Used to identify the target database in audit records.',

        'description' => 'Description',
        'description_placeholder' => 'Resource purpose, environment, and access restrictions',

        'defaults_before' => 'After creation, the resource will be active and SQL auditing will be enabled. Authentication mode:',
        'defaults_after' => 'Connection settings are checked when a gateway session is started.',

        'submit' => 'Create resource',
        'cancel' => 'Cancel',
    ],

    'edit_page' => [
        'title' => 'Edit resource',
        'back' => '← Back to resource',
        'heading' => 'Edit database resource',

        'active_sessions_warning' => 'Active gateway sessions: :count.',
        'active_sessions_notice' => 'While they are active, changing the database engine, target host, port, or database will be blocked.',

        'name' => 'Resource name',
        'engine' => 'Database engine',
        'target_host' => 'Target host',
        'target_port' => 'Target port',
        'database' => 'Database / catalog',
        'description' => 'Description',

        'security_note_before' => 'Database passwords are not stored. Current authentication mode:',
        'security_note_after' => 'SQL auditing is enabled for this resource.',

        'submit' => 'Save changes',
        'cancel' => 'Cancel',
    ],

    'show' => [
        'title' => 'Resource',
        'back' => '← Back to resources',

        'summary' => [
            'target' => 'Target',
            'database_not_specified' => 'database not specified',
            'access_policy' => 'Access policy',
            'tls' => 'TLS',
            'sql_audit' => 'SQL audit',
            'sessions' => 'Sessions',
            'active_gateways' => 'Active gateways',
            'open_db_connections' => 'Open database connections',
        ],

        'edit' => [
            'heading' => 'Edit resource',
            'action' => 'Edit',
            'notice' => 'Changing the database engine or target is blocked while an active gateway session exists.',
        ],

        'deactivate' => [
            'heading' => 'Deactivate resource',
            'placeholder' => 'Deactivation reason',
            'action' => 'Deactivate',
            'confirm' => 'Deactivate this resource? New sessions and gateway starts for created sessions will be blocked.',
            'notice' => 'Existing gateway sessions are not terminated automatically.',
        ],

        'activate' => [
            'heading' => 'Activate resource',
            'placeholder' => 'Activation reason',
            'action' => 'Activate',
            'confirm' => 'Activate this resource and allow new sessions?',
        ],

        'sessions' => [
            'heading' => 'Latest gateway sessions',
            'columns' => [
                'session' => 'Session',
                'owner' => 'Owner',
                'mode_status' => 'Mode / status',
                'gateway' => 'Gateway',
                'source_cidr' => 'Allowed IP / CIDR',
                'connections' => 'Connections',
                'created' => 'Created',
            ],
            'released' => 'port released',
            'empty' => 'No sessions are available for this resource.',
        ],

        'audit_events' => [
            'heading' => 'Audit events',
            'columns' => [
                'time' => 'Time',
                'event' => 'Event',
                'severity' => 'Severity',
                'actor' => 'Actor',
                'ip' => 'IP address',
                'data' => 'Data',
            ],
            'empty' => 'No audit events are available.',
            'system' => 'system',
            'severity' => [
                'info' => 'informational',
                'medium' => 'medium',
                'high' => 'high',
                'critical' => 'critical',
            ],
        ],
    ],
];

<?php

return [
    'title' => 'Sessions',
    'heading' => 'Gateway Sessions',
    'subtitle' => 'Created temporary and persistent database access sessions.',
    'create' => 'Create session',

    'filters' => [
        'status' => 'Status',
        'mode' => 'Mode',
        'all' => 'All',
        'apply' => 'Filter',
        'reset' => 'Reset',
    ],

    'columns' => [
        'session' => 'Session',
        'resource' => 'Resource',
        'owner' => 'Owner',
        'mode_status' => 'Mode / status',
        'gateway' => 'Gateway',
        'source_cidr' => 'Allowed IP / CIDR',
        'connections' => 'Connections',
        'operations' => 'Operations',
        'lifecycle' => 'TTL / completion',
    ],

    'status' => [
        'created' => 'created',
        'starting' => 'starting',
        'started' => 'active',
        'ended' => 'ended',
        'terminated' => 'revoked',
        'expired' => 'expired',
        'failed' => 'failed',
    ],

    'mode' => [
        'temporary' => 'temporary',
        'persistent' => 'persistent',
    ],

    'gateway' => [
        'released' => 'port released',
    ],

    'operations' => [
        'pending' => 'pending: :count',
        'failed' => 'failed',
        'failed_without_text' => 'Operation failed without an error message.',
    ],

    'lifecycle' => [
        'manual' => 'manual completion',
    ],

    'empty' => 'No database sessions have been created yet.',

    'pagination' => [
        'showing' => 'Showing :from–:to of :total',
        'previous' => '← Previous',
        'next' => 'Next →',
    ],

    'create_page' => [
        'title' => 'Create session',
        'back' => '← Back to sessions',
        'heading' => 'Create database access session',
        'subtitle' => 'Create access to the selected database resource. Start the gateway as a separate action after the session is created.',

        'no_resources' => 'No active database resources are available. Create a resource first.',

        'resource' => 'Database resource',
        'resource_placeholder' => 'Select a resource',

        'connection_mode' => 'Connection mode',
        'temporary_option' => 'Temporary — with TTL',
        'persistent_option' => 'Persistent — until manually completed',

        'ttl' => 'TTL, seconds',
        'ttl_hint' => 'From 60 seconds to 7 days. When the TTL expires, the gateway and active database connections will be forcibly closed.',

        'source_cidr' => 'Source IP address / CIDR',
        'source_cidr_hint' => 'IP address or CIDR that is allowed to connect to the gateway.',

        'db_username' => 'Database username',
        'db_username_hint' => 'Used for auditing only. The database password is not stored.',

        'created_note' => 'The new session is created with the status',
        'created_action' => 'To open a gateway endpoint and allocate a port, select',
        'start_session' => 'Start session',

        'submit' => 'Create session',
        'cancel' => 'Cancel',

        'mode_notes' => [
            'temporary' => 'A temporary session ends automatically after its TTL. The gateway, active database connections, and queries will be forcibly closed.',
            'persistent' => 'A persistent session has no TTL. It remains active until manually terminated or revoked.',
        ],
    ],
];

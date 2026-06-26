<?php

return [
    'title' => 'Dashboard',
    'heading' => 'Database Access Dashboard',
    'subtitle' => 'Gateway session, database connection, and SQL audit status. Time is displayed in the application timezone: :timezone.',

    'stats' => [
        'active_sessions' => 'Active gateway sessions',
        'active_resources' => 'Active database resources',
        'open_connections' => 'Open database connections',
        'high_risk_24h' => 'High-risk SQL queries in the last 24 hours',
    ],

    'sessions' => [
        'heading' => 'Latest database sessions',
        'columns' => [
            'session' => 'Session',
            'resource' => 'Resource',
            'owner' => 'Owner',
            'mode_status' => 'Mode / status',
            'gateway' => 'Gateway endpoint',
            'source_cidr' => 'Allowed IP / CIDR',
            'open_connections' => 'Open connections',
            'lifecycle' => 'TTL / completion',
        ],
        'persistent_manual' => 'active / manual completion',
        'empty' => 'No database sessions have been created yet.',
    ],

    'sql' => [
        'heading' => 'Latest SQL audit events',
        'columns' => [
            'time' => 'Time',
            'resource_session' => 'Resource / session',
            'type' => 'Type',
            'risk' => 'Risk',
            'status' => 'Status',
            'duration' => 'Duration',
            'sql' => 'SQL',
        ],
        'empty' => 'No SQL audit events are available.',

        'risk' => [
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ],

        'status' => [
            'queued' => 'queued',
            'running' => 'running',
            'started' => 'started',
            'completed' => 'completed',
            'succeeded' => 'succeeded',
            'failed' => 'failed',
            'denied' => 'denied',
            'closed' => 'closed',
        ],
    ],
];

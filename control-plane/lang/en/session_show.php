<?php

return [
    'title' => 'Session',
    'back' => '← Back to sessions',
    'pending_operation' => 'operation pending',

    'summary' => [
        'resource' => 'Resource',
        'owner_db_user' => 'Owner / database user',
        'gateway_endpoint' => 'Gateway endpoint',
        'source_cidr' => 'Allowed IP / CIDR',
        'ttl_expiry' => 'TTL / expiry',
        'lifecycle' => 'Lifecycle',
        'not_started' => 'not started',
        'released' => 'port released',
        'historical_endpoint' => 'historical endpoint',
        'persistent_service' => 'persistent / service',
        'started' => 'Started',
        'ended' => 'Ended',
    ],

    'actions' => [
        'start_gateway' => 'Start gateway',
        'start_session' => 'Start session',
        'confirm_start' => 'Queue the gateway start operation?',
        'terminate_revoke' => 'Terminate / revoke',
        'reason_placeholder' => 'Termination reason',
        'terminate_session' => 'Terminate session',
        'confirm_terminate' => 'Terminate the database session and forcibly close active connections?',
        'unavailable' => 'Lifecycle actions are unavailable for this status.',
        'pending' => 'An operation is already queued. The root worker will execute it automatically.',
    ],

    'operations' => [
        'heading' => 'Lifecycle operations',
        'columns' => [
            'operation' => 'Operation',
            'type' => 'Type',
            'status' => 'Status',
            'requested_by' => 'Requested by',
            'reason' => 'Reason',
            'requested' => 'Requested',
            'completed' => 'Completed',
            'error' => 'Error',
        ],
        'empty' => 'No lifecycle operations are available.',
        'type' => [
            'start_session' => 'start session',
            'terminate_session' => 'terminate session',
        ],
        'status' => [
            'queued' => 'queued',
            'running' => 'running',
            'succeeded' => 'succeeded',
            'failed' => 'failed',
        ],
    ],

    'connections' => [
        'heading' => 'Database connections',
        'columns' => [
            'connection' => 'Connection',
            'client' => 'Client',
            'db_user' => 'Database user',
            'status' => 'Status',
            'opened' => 'Opened',
            'closed' => 'Closed',
            'reason' => 'Reason',
        ],
        'empty' => 'No database connections are available.',
        'status' => [
            'opened' => 'opened',
            'closed' => 'closed',
            'denied' => 'denied',
            'failed' => 'failed',
        ],
    ],

    'sql_audit' => [
        'heading' => 'SQL audit',
        'sorting' => 'Sort order: critical → high → medium → low; newer queries are shown first within each level. Up to 100 records are shown.',
        'columns' => [
            'query' => 'Query',
            'type' => 'Type',
            'risk' => 'Risk',
            'status' => 'Status',
            'duration' => 'Duration',
            'ended' => 'Ended',
            'sql' => 'SQL',
        ],
        'empty' => 'No SQL audit events are available for the selected risk level.',
        'risk' => [
            'all' => 'All',
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

    'audit' => [
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
    ],

    'common' => [
        'system' => 'system',
        'info' => 'informational',
        'medium' => 'medium',
        'high' => 'high',
        'critical' => 'critical',
    ],
];

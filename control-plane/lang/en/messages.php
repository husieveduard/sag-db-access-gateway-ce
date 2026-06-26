<?php

return [
    'auth' => [
        'login' => [
            'too_many_attempts' => 'Too many sign-in attempts. Try again in :seconds seconds.',
            'invalid_credentials' => 'Incorrect email address or password.',
        ],

        'mfa' => [
            'setup_session_refreshed' => 'The MFA setup session was refreshed. Scan the new QR code.',
            'too_many_invalid_codes' => 'Too many incorrect codes. Try again in :seconds seconds.',
            'invalid_totp' => 'Incorrect code. Check the time on your phone and try again.',
            'invalid_mfa_or_recovery' => 'Incorrect MFA or recovery code.',
            'recovery_codes_unavailable' => 'Recovery codes have already been acknowledged or are unavailable.',
            'setup_completed' => 'MFA has been configured. Recovery codes were acknowledged.',
            'pending_expired' => 'The MFA session has ended. Sign in again.',
            'pending_user_unavailable' => 'The user is unavailable for MFA verification.',
        ],
    ],

    'resources' => [
        'created' => 'Database resource “:name” was created.',
        'updated' => 'Database resource was updated.',
        'deactivated' => 'Resource was deactivated.',
        'activated' => 'Resource was activated.',
        'target_change_blocked' => 'The database engine or target cannot be changed while active gateway sessions exist.',
        'already_deactivated' => 'The resource is already deactivated.',
        'already_active' => 'The resource is already active.',
        'invalid_host_format' => 'Enter an IP address or DNS name without a protocol, port, or path.',
        'invalid_host' => 'Enter a valid DNS name or IP address.',
    ],

    'sessions' => [
        'invalid_source_cidr' => 'Enter a valid IP address or CIDR.',
        'db_username_required' => 'Enter a database username for auditing.',
        'resource_unavailable' => 'The selected database resource does not exist or is inactive.',
        'created' => 'Session :public_id was created. To open the gateway, select “Start session”.',
    ],

    'operations' => [
        'already_pending' => 'A lifecycle operation is already running for this session.',
        'start_unavailable' => 'Start is available only for sessions in the created or failed state.',
        'resource_inactive' => 'Unable to start the session: the database resource is inactive.',
        'already_finished' => 'The session is already in a completed state.',
        'queued' => 'Operation :operation_uid was queued.',
    ],
];

<?php

return [
    'login' => [
        'title' => 'Sign in',
        'description' => 'Local administrative access to database resources, gateway sessions, and SQL auditing.',
        'email' => 'Email',
        'password' => 'Password',
        'submit' => 'Sign in to console',
        'note_before' => 'Access is allowed only for active users with the role',
    ],

    'mfa_setup' => [
        'title' => 'Set up MFA',
        'heading' => 'Set up MFA',
        'intro' => 'Scan the QR code with Microsoft Authenticator, Google Authenticator, 1Password, or another TOTP-compatible application.',
        'qr_alt' => 'MFA setup QR code',
        'manual_intro' => 'Unable to scan the QR code? Add the key manually:',
        'account' => 'Account: :email',
        'code' => 'Authenticator code',
        'submit' => 'Confirm and enable MFA',
    ],

    'mfa_challenge' => [
        'title' => 'MFA verification',
        'heading' => 'MFA verification',
        'intro' => 'Enter a six-digit TOTP code or one of your recovery codes.',
        'code' => 'MFA or recovery code',
        'submit' => 'Confirm sign-in',
        'operator' => 'Operator',
    ],

    'mfa_recovery' => [
        'title' => 'Recovery codes',
        'heading' => 'Recovery codes',
        'warning' => 'Store these codes in a secure location. They are displayed only once, and each code can be used only once.',
        'submit' => 'I saved the recovery codes',
    ],
];

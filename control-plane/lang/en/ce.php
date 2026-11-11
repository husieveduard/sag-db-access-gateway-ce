<?php

return [
    'app_title' => 'SAG DB Access Gateway CE',
    'product_name' => 'SAG DB Access Gateway',
    'edition' => 'CE',

    'sidebar' => [
        'collapse' => 'Collapse sidebar',
        'expand' => 'Expand sidebar',
        'copy_id' => 'Copy ID',
        'copied' => 'Copied',
        'copy_failed' => 'Copy failed',
    ],
    'timezone' => [
        'title' => 'Interface time zone',
        'description' => 'Used to display timestamps across the administration console.',
        'label' => 'Time zone',
        'save' => 'Save time zone',
        'saved' => 'Time zone updated.',
        'current' => 'Current',
        'utc_note' => 'Database timestamps and audit records remain in UTC.',
    ],
    'navigation' => 'Menu',
    'groups' => ['workspace' => 'Workspace', 'monitoring' => 'Monitoring', 'administration' => 'Administration'],
    'nav' => [
        'dashboard' => 'Dashboard',
        'resources' => 'Database resources',
        'sessions' => 'Sessions',
        'administrators' => 'Administrators',
        'audit' => 'Audit log',
        'alert_health' => 'Alert health',
        'alert_channels' => 'Notification channels',
        'settings' => 'Settings',
        'settings_overview' => 'Settings overview',
    ],


    'settings' => [
        'title' => 'System settings',
        'description' => 'Manage access, directories, and notification channels for SAG CE.',
        'access' => 'Users and access',
        'alerts' => 'Notifications and rules',
        'admins_desc' => 'Local administrators, Superadmin permissions, and account management.',
        'ad_desc' => 'Connect Active Directory, LDAP/LDAPS, and test directory access.',
        'channels_desc' => 'Telegram, SMTP email, Slack, Teams, Nextcloud Talk, and Webhook.',
        'rules_desc' => 'Security rule toggles, severity, and deduplication intervals.',
        'future_title' => 'Planned extensions',
        'future_desc' => 'Future: access and MFA policies, audit retention, general settings, and SIEM integrations. These options are not yet available.',
    ],

    'role' => [
        'operator' => 'Operator',
        'admin' => 'Administrator',
        'superadmin' => 'Super administrator',
    ],

    'language' => 'Language',
    'logout' => 'Log out',

    'common' => [
        'all' => 'All',
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'info' => 'Informational',
        'low' => 'Low',
        'not_available' => '—',
    ],
];

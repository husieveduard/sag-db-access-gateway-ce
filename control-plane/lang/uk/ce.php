<?php

return [
    'app_title' => 'SAG DB Access Gateway CE',
    'product_name' => 'SAG DB Access Gateway',
    'edition' => 'CE',

    'sidebar' => [
        'collapse' => 'Згорнути меню',
        'expand' => 'Розгорнути меню',
        'copy_id' => 'Скопіювати ID',
        'copied' => 'Скопійовано',
        'copy_failed' => 'Не вдалося скопіювати',
    ],
    'navigation' => 'Меню',
    'groups' => ['workspace' => 'Основне', 'monitoring' => 'Моніторинг', 'administration' => 'Адміністрування'],
    'nav' => [
        'dashboard' => 'Панель керування',
        'resources' => 'Ресурси БД',
        'sessions' => 'Сесії',
        'administrators' => 'Адміністратори',
        'audit' => 'Журнал дій',
        'alert_health' => 'Стан сповіщень',
        'alert_channels' => 'Канали сповіщень',
        'settings' => 'Налаштування',
        'settings_overview' => 'Огляд налаштувань',
    ],


    'settings' => [
        'title' => 'Налаштування системи',
        'description' => 'Керування доступом, каталогами та каналами сповіщень SAG CE.',
        'access' => 'Користувачі та доступ',
        'alerts' => 'Сповіщення та правила',
        'admins_desc' => 'Локальні адміністратори, права Superadmin, блокування облікових записів.',
        'ad_desc' => 'Підключення Active Directory, LDAP/LDAPS та перевірка з’єднання.',
        'channels_desc' => 'Telegram, SMTP-пошта, Slack, Teams, Nextcloud Talk та Webhook.',
        'rules_desc' => 'Увімкнення правил безпеки, рівні ризику та дедуплікація.',
        'future_title' => 'Подальший розвиток',
        'future_desc' => 'У перспективі: політики доступу та MFA, терміни зберігання журналів, системні параметри й SIEM-інтеграції. Зараз ці параметри ще не реалізовані.',
    ],

    'role' => [
        'operator' => 'Оператор',
        'admin' => 'Адміністратор',
        'superadmin' => 'Суперадміністратор',
    ],

    'language' => 'Мова',
    'logout' => 'Вийти',

    'common' => [
        'all' => 'Усі',
        'critical' => 'Критичний',
        'high' => 'Високий',
        'medium' => 'Середній',
        'low' => 'Низький',
        'not_available' => '—',
    ],
];

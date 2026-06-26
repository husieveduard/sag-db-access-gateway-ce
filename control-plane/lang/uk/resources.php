<?php

return [
    'title' => 'Ресурси БД',
    'heading' => 'Ресурси баз даних',
    'subtitle' => 'Цільові бази даних, доступні для створення gateway-сесій.',
    'create' => 'Створити ресурс',

    'columns' => [
        'resource' => 'Ресурс',
        'engine' => 'СУБД',
        'target' => 'Ціль',
        'tls_auth' => 'TLS / автентифікація',
        'sql_audit' => 'SQL-аудит',
        'status' => 'Стан',
        'sessions' => 'Сесії',
        'active_gateways' => 'Активні шлюзи',
        'open_connections' => 'Відкриті підключення',
        'created_by' => 'Створив',
    ],

    'engine' => [
        'mysql' => 'MySQL / MariaDB',
        'postgresql' => 'PostgreSQL',
        'mssql' => 'Microsoft SQL Server (експериментально)',
    ],

    'audit' => [
        'enabled' => 'увімкнено',
        'disabled' => 'вимкнено',
    ],

    'status' => [
        'active' => 'активний',
        'inactive' => 'неактивний',
    ],

    'tls' => [
        'prefer' => 'бажано',
        'require' => 'обов’язково',
        'disable' => 'вимкнено',
        'verify_ca' => 'перевірка CA',
        'verify_full' => 'повна перевірка',
    ],

    'auth' => [
        'client_passthrough' => 'облікові дані користувача',
        'managed' => 'керовані облікові дані',
    ],

    'empty' => 'Ресурси БД ще не створювались.',

    'create_page' => [
        'title' => 'Створити ресурс',
        'back' => '← До списку ресурсів',
        'heading' => 'Створити ресурс БД',
        'subtitle' => 'Ресурс описує цільову базу даних. Облікові дані БД тут не зберігаються.',

        'name' => 'Назва ресурсу',
        'name_placeholder' => 'Унікальна назва ресурсу',
        'engine' => 'Тип СУБД',
        'engine_hint' => 'Підтримка Microsoft SQL Server має експериментальний статус.',

        'target_host' => 'Цільовий хост',
        'target_host_placeholder' => 'IP-адреса або DNS-ім’я сервера БД',
        'target_port' => 'Цільовий порт',

        'database' => 'База даних / каталог',
        'target_database_placeholder' => 'Назва бази даних',
        'database_hint' => 'Необов’язкове поле. Використовується для ідентифікації цільової БД у журналі аудиту.',

        'description' => 'Опис',
        'description_placeholder' => 'Призначення ресурсу, середовище та обмеження доступу',

        'defaults_before' => 'Після створення ресурс буде активним, а SQL-аудит — увімкненим. Тип автентифікації:',
        'defaults_after' => 'Параметри підключення перевіряються під час запуску gateway-сесії.',

        'submit' => 'Створити ресурс',
        'cancel' => 'Скасувати',
    ],

    'edit_page' => [
        'title' => 'Редагувати ресурс',
        'back' => '← До картки ресурсу',
        'heading' => 'Редагувати ресурс БД',

        'active_sessions_warning' => 'Активних gateway-сесій: :count.',
        'active_sessions_notice' => 'Поки вони активні, зміну СУБД, цільового хоста, порту або бази даних буде заблоковано.',

        'name' => 'Назва ресурсу',
        'engine' => 'Тип СУБД',
        'target_host' => 'Цільовий хост',
        'target_port' => 'Цільовий порт',
        'database' => 'База даних / каталог',
        'description' => 'Опис',

        'security_note_before' => 'Паролі БД не зберігаються. Поточний тип автентифікації:',
        'security_note_after' => 'SQL-аудит увімкнено для ресурсу.',

        'submit' => 'Зберегти зміни',
        'cancel' => 'Скасувати',
    ],

    'show' => [
        'title' => 'Ресурс',
        'back' => '← До списку ресурсів',

        'summary' => [
            'target' => 'Ціль',
            'database_not_specified' => 'базу даних не вказано',
            'access_policy' => 'Політика доступу',
            'tls' => 'TLS',
            'sql_audit' => 'SQL-аудит',
            'sessions' => 'Сесії',
            'active_gateways' => 'Активні шлюзи',
            'open_db_connections' => 'Відкриті підключення до БД',
        ],

        'edit' => [
            'heading' => 'Редагування ресурсу',
            'action' => 'Редагувати',
            'notice' => 'Зміну СУБД або цільового вузла заблоковано, поки існує активна gateway-сесія.',
        ],

        'deactivate' => [
            'heading' => 'Деактивація ресурсу',
            'placeholder' => 'Причина деактивації',
            'action' => 'Деактивувати',
            'confirm' => 'Деактивувати ресурс? Нові сесії та запуск gateway для створених сесій буде заблоковано.',
            'notice' => 'Уже запущені gateway-сесії не обриваються автоматично.',
        ],

        'activate' => [
            'heading' => 'Активація ресурсу',
            'placeholder' => 'Причина активації',
            'action' => 'Активувати',
            'confirm' => 'Активувати ресурс і дозволити створення нових сесій?',
        ],

        'sessions' => [
            'heading' => 'Останні gateway-сесії',
            'columns' => [
                'session' => 'Сесія',
                'owner' => 'Власник',
                'mode_status' => 'Режим / статус',
                'gateway' => 'Шлюз',
                'source_cidr' => 'Дозволений IP / CIDR',
                'connections' => 'Підключення',
                'created' => 'Створено',
            ],
            'released' => 'порт звільнено',
            'empty' => 'Для цього ресурсу сесії відсутні.',
        ],

        'audit_events' => [
            'heading' => 'Події аудиту',
            'columns' => [
                'time' => 'Час',
                'event' => 'Подія',
                'severity' => 'Критичність',
                'actor' => 'Ініціатор',
                'ip' => 'IP-адреса',
                'data' => 'Дані',
            ],
            'empty' => 'Події аудиту відсутні.',
            'system' => 'система',
            'severity' => [
                'info' => 'інформаційний',
                'medium' => 'середній',
                'high' => 'високий',
                'critical' => 'критичний',
            ],
        ],
    ],
];

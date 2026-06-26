<?php

return [
    'title' => 'Панель керування',
    'heading' => 'Панель керування доступом до БД',
    'subtitle' => 'Стан gateway-сесій, підключень до БД та SQL-аудиту. Час відображається в часовій зоні застосунку: :timezone.',

    'stats' => [
        'active_sessions' => 'Активні gateway-сесії',
        'active_resources' => 'Активні ресурси БД',
        'open_connections' => 'Відкриті підключення до БД',
        'high_risk_24h' => 'SQL-запити високого ризику за 24 години',
    ],

    'sessions' => [
        'heading' => 'Останні сесії БД',
        'columns' => [
            'session' => 'Сесія',
            'resource' => 'Ресурс',
            'owner' => 'Власник',
            'mode_status' => 'Режим / статус',
            'gateway' => 'Gateway endpoint',
            'source_cidr' => 'Дозволений IP / CIDR',
            'open_connections' => 'Відкриті підключення',
            'lifecycle' => 'TTL / завершення',
        ],
        'persistent_manual' => 'активна / ручне завершення',
        'empty' => 'Сесії БД ще не створювались.',
    ],

    'sql' => [
        'heading' => 'Останні події SQL-аудиту',
        'columns' => [
            'time' => 'Час',
            'resource_session' => 'Ресурс / сесія',
            'type' => 'Тип',
            'risk' => 'Ризик',
            'status' => 'Статус',
            'duration' => 'Тривалість',
            'sql' => 'SQL',
        ],
        'empty' => 'Події SQL-аудиту ще відсутні.',

        'risk' => [
            'critical' => 'Критичний',
            'high' => 'Високий',
            'medium' => 'Середній',
            'low' => 'Низький',
        ],

        'status' => [
            'queued' => 'у черзі',
            'running' => 'виконується',
            'started' => 'запущено',
            'completed' => 'виконано',
            'succeeded' => 'успішно',
            'failed' => 'помилка',
            'denied' => 'відхилено',
            'closed' => 'закрито',
        ],
    ],
];

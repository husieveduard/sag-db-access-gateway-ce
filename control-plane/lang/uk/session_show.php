<?php

return [
    'title' => 'Сесія',
    'back' => '← До списку сесій',
    'pending_operation' => 'операція у черзі',

    'summary' => [
        'resource' => 'Ресурс',
        'owner_db_user' => 'Власник / користувач БД',
        'gateway_endpoint' => 'Gateway endpoint',
        'source_cidr' => 'Дозволений IP / CIDR',
        'ttl_expiry' => 'TTL / завершення строку дії',
        'lifecycle' => 'Життєвий цикл',
        'not_started' => 'ще не запущено',
        'released' => 'порт звільнено',
        'historical_endpoint' => 'історична адреса',
        'persistent_service' => 'постійна / сервісна',
        'started' => 'Запуск',
        'ended' => 'Завершення',
    ],

    'actions' => [
        'start_gateway' => 'Запуск gateway',
        'start_session' => 'Запустити сесію',
        'confirm_start' => 'Поставити запуск gateway у чергу?',
        'terminate_revoke' => 'Завершення / відкликання',
        'reason_placeholder' => 'Причина завершення',
        'terminate_session' => 'Завершити сесію',
        'confirm_terminate' => 'Завершити сесію БД та примусово розірвати активні підключення?',
        'unavailable' => 'Для цього статусу lifecycle-дії недоступні.',
        'pending' => 'Операцію вже поставлено в чергу. Root-worker виконає її автоматично.',
    ],

    'operations' => [
        'heading' => 'Lifecycle-операції',
        'columns' => [
            'operation' => 'Операція',
            'type' => 'Тип',
            'status' => 'Статус',
            'requested_by' => 'Ініціатор',
            'reason' => 'Причина',
            'requested' => 'Запитано',
            'completed' => 'Виконано',
            'error' => 'Помилка',
        ],
        'empty' => 'Lifecycle-операції відсутні.',
        'type' => [
            'start_session' => 'запуск сесії',
            'terminate_session' => 'завершення сесії',
        ],
        'status' => [
            'queued' => 'у черзі',
            'running' => 'виконується',
            'succeeded' => 'успішно',
            'failed' => 'помилка',
        ],
    ],

    'connections' => [
        'heading' => 'Підключення до БД',
        'columns' => [
            'connection' => 'Підключення',
            'client' => 'Клієнт',
            'db_user' => 'Користувач БД',
            'status' => 'Статус',
            'opened' => 'Відкрито',
            'closed' => 'Закрито',
            'reason' => 'Причина',
        ],
        'empty' => 'Підключення до БД відсутні.',
        'status' => [
            'opened' => 'відкрито',
            'closed' => 'закрито',
            'denied' => 'відхилено',
            'failed' => 'помилка',
        ],
    ],

    'sql_audit' => [
        'heading' => 'SQL-аудит',
        'sorting' => 'Сортування: critical → high → medium → low; у межах рівня новіші запити зверху. Показано до 100 записів.',
        'columns' => [
            'query' => 'Запит',
            'type' => 'Тип',
            'risk' => 'Ризик',
            'status' => 'Статус',
            'duration' => 'Тривалість',
            'ended' => 'Завершено',
            'sql' => 'SQL',
        ],
        'empty' => 'Події SQL-аудиту для обраного рівня ризику відсутні.',
        'risk' => [
            'all' => 'Усі',
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

    'audit' => [
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
    ],

    'common' => [
        'system' => 'система',
        'info' => 'інформаційний',
        'medium' => 'середній',
        'high' => 'високий',
        'critical' => 'критичний',
    ],
];

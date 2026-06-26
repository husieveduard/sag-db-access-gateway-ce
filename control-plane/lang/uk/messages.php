<?php

return [
    'auth' => [
        'login' => [
            'too_many_attempts' => 'Забагато спроб входу. Повторіть через :seconds с.',
            'invalid_credentials' => 'Невірна email-адреса або пароль.',
        ],

        'mfa' => [
            'setup_session_refreshed' => 'Сесію налаштування MFA оновлено. Відскануйте новий QR-код.',
            'too_many_invalid_codes' => 'Забагато неправильних кодів. Повторіть через :seconds с.',
            'invalid_totp' => 'Невірний код. Перевірте час на телефоні та спробуйте ще раз.',
            'invalid_mfa_or_recovery' => 'Невірний MFA або резервний код.',
            'recovery_codes_unavailable' => 'Резервні коди вже підтверджені або недоступні.',
            'setup_completed' => 'MFA налаштовано. Резервні коди підтверджено.',
            'pending_expired' => 'Сесію MFA завершено. Увійдіть повторно.',
            'pending_user_unavailable' => 'Користувач недоступний для MFA-підтвердження.',
        ],
    ],

    'resources' => [
        'created' => 'Ресурс БД «:name» створено.',
        'updated' => 'Ресурс БД оновлено.',
        'deactivated' => 'Ресурс деактивовано.',
        'activated' => 'Ресурс активовано.',
        'target_change_blocked' => 'Неможливо змінити СУБД або цільовий вузол, поки існують активні gateway-сесії.',
        'already_deactivated' => 'Ресурс уже деактивований.',
        'already_active' => 'Ресурс уже активний.',
        'invalid_host_format' => 'Вкажіть IP-адресу або DNS-ім’я без протоколу, порту чи шляху.',
        'invalid_host' => 'Вкажіть коректне DNS-ім’я або IP-адресу.',
    ],

    'sessions' => [
        'invalid_source_cidr' => 'Вкажіть коректну IP-адресу або CIDR.',
        'db_username_required' => 'Вкажіть ім’я користувача БД для аудиту.',
        'resource_unavailable' => 'Вибраний ресурс БД не існує або неактивний.',
        'created' => 'Сесію :public_id створено. Щоб відкрити gateway, натисніть «Запустити сесію».',
    ],

    'operations' => [
        'already_pending' => 'Для цієї сесії вже виконується lifecycle-операція.',
        'start_unavailable' => 'Запуск доступний лише для сесій у стані «створено» або «помилка».',
        'resource_inactive' => 'Неможливо запустити сесію: ресурс БД неактивний.',
        'already_finished' => 'Сесія вже перебуває у завершеному стані.',
        'queued' => 'Операцію :operation_uid поставлено в чергу.',
    ],
];

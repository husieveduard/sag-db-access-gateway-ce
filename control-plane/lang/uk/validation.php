<?php

return [
    'required' => 'Поле :attribute є обов’язковим.',
    'required_if' => 'Поле :attribute є обов’язковим за вибраних умов.',
    'string' => 'Поле :attribute має бути текстом.',
    'email' => 'Поле :attribute має бути коректною email-адресою.',
    'integer' => 'Поле :attribute має бути цілим числом.',
    'regex' => 'Поле :attribute має некоректний формат.',
    'in' => 'Поле :attribute містить неприпустиме значення.',
    'unique' => 'Значення поля :attribute вже використовується.',
    'min' => [
        'numeric' => 'Поле :attribute має бути не менше :min.',
        'string' => 'Поле :attribute має містити щонайменше :min символи.',
    ],
    'max' => [
        'numeric' => 'Поле :attribute не може бути більше :max.',
        'string' => 'Поле :attribute не може містити більше :max символів.',
    ],
    'between' => [
        'numeric' => 'Поле :attribute має бути в межах від :min до :max.',
    ],
    'attributes' => [
        'email' => 'email-адреса',
        'password' => 'пароль',
        'code' => 'код',
        'name' => 'назва ресурсу',
        'description' => 'опис',
        'engine' => 'тип СУБД',
        'target_host' => 'цільовий хост',
        'target_port' => 'цільовий порт',
        'target_database' => 'база даних',
        'reason' => 'причина',
        'resource_id' => 'ресурс БД',
        'mode' => 'режим',
        'ttl_seconds' => 'TTL',
        'source_cidr' => 'вихідна IP-адреса / CIDR',
        'db_username' => 'ім’я користувача БД',
    ],
];

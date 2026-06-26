<?php

return [
    'required' => 'The :attribute field is required.',
    'required_if' => 'The :attribute field is required for the selected conditions.',
    'string' => 'The :attribute field must be a string.',
    'email' => 'The :attribute field must be a valid email address.',
    'integer' => 'The :attribute field must be an integer.',
    'regex' => 'The :attribute field format is invalid.',
    'in' => 'The selected :attribute is invalid.',
    'unique' => 'The :attribute has already been taken.',
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'max' => [
        'numeric' => 'The :attribute may not be greater than :max.',
        'string' => 'The :attribute may not be greater than :max characters.',
    ],
    'between' => [
        'numeric' => 'The :attribute must be between :min and :max.',
    ],
    'attributes' => [
        'email' => 'email address',
        'password' => 'password',
        'code' => 'code',
        'name' => 'resource name',
        'description' => 'description',
        'engine' => 'database engine',
        'target_host' => 'target host',
        'target_port' => 'target port',
        'target_database' => 'database',
        'reason' => 'reason',
        'resource_id' => 'database resource',
        'mode' => 'mode',
        'ttl_seconds' => 'TTL',
        'source_cidr' => 'source IP address / CIDR',
        'db_username' => 'database username',
    ],
];

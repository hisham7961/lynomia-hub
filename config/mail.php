<?php

return [
    'default' => env('MAIL_MAILER', 'log'),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
        ],
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
    ],
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'hub@lynomia.com'), 'name' => env('MAIL_FROM_NAME', 'Lynomia Hub')],
];

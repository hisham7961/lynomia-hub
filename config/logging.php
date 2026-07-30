<?php

use Monolog\Handler\StreamHandler;

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => ['channel' => 'null', 'trace' => false],
    'channels' => [
        'stack' => ['driver' => 'stack', 'channels' => ['daily'], 'ignore_exceptions' => false],
        'daily' => ['driver' => 'daily', 'path' => storage_path('logs/laravel.log'),
                    'level' => env('LOG_LEVEL', 'warning'), 'days' => 14, 'replace_placeholders' => true],
        'stderr' => ['driver' => 'monolog', 'handler' => StreamHandler::class,
                     'with' => ['stream' => 'php://stderr'], 'level' => env('LOG_LEVEL', 'debug')],
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
        'emergency' => ['path' => storage_path('logs/laravel.log')],
    ],
];

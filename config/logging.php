<?php

use Monolog\Handler\StreamHandler;

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => ['channel' => 'null', 'trace' => false],
    'channels' => [
        'stack' => ['driver' => 'stack', 'channels' => ['daily'], 'ignore_exceptions' => false],
        'daily' => ['driver' => 'daily', 'path' => storage_path('logs/laravel.log'),
                    'level' => env('LOG_LEVEL', 'warning'), 'days' => 14, 'replace_placeholders' => true],
        // **سجلٌّ مهيكل JSON** (v2.399): يُفعَّل بـLOG_CHANNEL=json — كل سطرٍ كائنٌ
        // بحقوله (المستوى، الرسالة، السياق: request_id/user_id/path) تقرؤه
        // Loki/ELK/Datadog مباشرةً. القناةُ النصّية الافتراضية تبقى كما هي.
        'json' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.json.log'),
            'level' => env('LOG_LEVEL', 'warning'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'replace_placeholders' => true,
        ],

        'stderr' => ['driver' => 'monolog', 'handler' => StreamHandler::class,
                     'with' => ['stream' => 'php://stderr'], 'level' => env('LOG_LEVEL', 'debug')],
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
        'emergency' => ['path' => storage_path('logs/laravel.log')],
    ],
];

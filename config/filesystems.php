<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app'), 'throw' => false],

        'public' => [
            'driver' => 'local', 'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage', 'visibility' => 'public', 'throw' => false,
        ],

        // التخزين الكائني (S3 / MinIO / R2) — للمرفقات والروابط الموقّعة
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET', 'lynomia'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', true),
            'throw' => false,
        ],

        'backups' => ['driver' => 'local', 'root' => storage_path('backups'), 'throw' => false],
    ],

    'links' => [public_path('storage') => storage_path('app/public')],
];

<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 300],
        'redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => env('REDIS_QUEUE', 'default'),
                    'retry_after' => 300, 'block_for' => null, 'after_commit' => false],
    ],
    'failed' => ['driver' => 'database-uuids', 'database' => env('DB_CONNECTION', 'pgsql'), 'table' => 'failed_jobs'],
];

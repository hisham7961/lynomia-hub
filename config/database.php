<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'lynomia'),
            'username' => env('DB_USERNAME', 'lynomia'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => [PDO::ATTR_PERSISTENT => false],
        ],
        // اتّصالُ العقل الثاني المستقلّ — لا يُستعمل إلّا حين BRAIN_DB_CONNECTION=brain.
        // SQLite ملفٌّ واحدٌ بلا خادم (BRAIN_DB_DRIVER=sqlite)، أو MariaDB/MySQL منفصل (الافتراض).
        'brain' => [
            'driver' => env('BRAIN_DB_DRIVER', 'mysql'),
            'url' => env('BRAIN_DB_URL'),
            'host' => env('BRAIN_DB_HOST', '127.0.0.1'),
            'port' => env('BRAIN_DB_PORT', '3306'),
            'database' => env('BRAIN_DB_DATABASE', env('BRAIN_DB_DRIVER', 'mysql') === 'sqlite' ? database_path('brain.sqlite') : 'lynomia_brain'),
            'username' => env('BRAIN_DB_USERNAME', 'root'),
            'password' => env('BRAIN_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'foreign_key_constraints' => true,
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],

    // **قاعدةُ العقل الثاني المستقلّة** (اختياريّة): متّجهاتُ البحث بالمعنى في قاعدةٍ وحدَها فلا تمسّ القاعدةَ
    // الرئيسة أبداً — ويُرقّى محرّكُها وحدَه متى شئت. فارغٌ = الجدولُ في القاعدة الرئيسة (الافتراض).
    // التفعيل: BRAIN_DB_CONNECTION=brain ثمّ `php artisan hub:brain --setup` (يُنشئ الجدولَ هناك).
    'brain_connection' => env('BRAIN_DB_CONNECTION') ?: null,

    // MariaDB ≥ 11: إبقاءُ `innodb_snapshot_isolation` الافتراضيّ الجديد (true) أو دلالةِ 10.11 التي
    // بُنيت عليها حرّاسُ التزامن (false · الافتراض) — App\Support\Ops\SnapshotIsolation
    'snapshot_isolation' => (bool) env('DB_SNAPSHOT_ISOLATION', false),

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => ['cluster' => 'redis', 'prefix' => env('REDIS_PREFIX', 'lynomia_')],
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 0,
        ],
        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 1,
        ],
    ],
];

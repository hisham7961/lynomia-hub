<?php

return [
    'default' => env('CACHE_STORE', 'file'),
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        // lock_path مفصول (v2.399): `optimize:clear` يمسح مجلد الكاش كلَّه — وكان يحمل أقفالَ withoutOverlapping
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data'), 'lock_path' => storage_path('framework/cache/locks')],
        'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
        'database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
    ],
    'prefix' => env('CACHE_PREFIX', 'lynomia_cache_'),
];

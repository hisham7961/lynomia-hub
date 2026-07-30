<?php

return [
    'name' => env('APP_NAME', 'Lynomia Business Hub'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Kuwait'),
    'locale' => env('APP_LOCALE', 'ar'),
    'fallback_locale' => 'en',
    'faker_locale' => 'ar_SA',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [],
    'maintenance' => ['driver' => 'file'],
];

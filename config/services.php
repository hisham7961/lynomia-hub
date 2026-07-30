<?php

return [
    'clamav' => [
        // على الخوادم الصغيرة أطفئه: AV_ENABLED=false (ClamAV يحتاج ~1.5GB ذاكرة)
        'enabled' => filter_var(env('AV_ENABLED', false), FILTER_VALIDATE_BOOL),
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => env('CLAMAV_PORT', 3310),
    ],

    'updates' => [
        // مفتاح التحقق من توقيع حزم التحديث (اختياري لكنه موصى به)
        'public_key' => env('UPDATES_PUBLIC_KEY'),
        // قناة تحديثات اختيارية: رابط JSON يحوي {version, url, notes}
        'channel'    => env('UPDATES_CHANNEL'),
    ],

    'telegram' => ['token' => env('TELEGRAM_TOKEN'), 'chat' => env('TELEGRAM_CHAT')],
];

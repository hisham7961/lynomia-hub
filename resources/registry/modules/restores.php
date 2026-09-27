<?php

/** سجلُّ الوحدات — «restores» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'restores',
    'table' => 'restore_tests',
    'model' => 'RestoreTest',
    'label' => 'اختبار استعادة النسخ',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'testDate', 'backupName', 'durationMin', 'status', 'offsite'],
    'fields' => [
        ['key' => 'dbId', 'col' => 'db_id', 'label' => 'قاعدة البيانات المختبَرة', 'type' => 'ref', 'ref' => 'dbs'],
        ['key' => 'nextTest', 'col' => 'next_test', 'label' => 'الاختبار الدوري القادم', 'type' => 'date', 'expiry' => true],
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان الاختبار', 'type' => 'text', 'required' => true],
        ['key' => 'testDate', 'col' => 'test_date', 'label' => 'تاريخ الاختبار', 'type' => 'date'],
        ['key' => 'byId', 'col' => 'by_id', 'label' => 'من نفّذ الاختبار', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'serverId', 'col' => 'server_id', 'label' => 'السيرفر', 'type' => 'ref', 'ref' => 'servers'],
        ['key' => 'backupName', 'col' => 'backup_name', 'label' => 'اسم النسخة المختبَرة', 'type' => 'text'],
        ['key' => 'backupDate', 'col' => 'backup_date', 'label' => 'تاريخ أخذ النسخة', 'type' => 'date'],
        ['key' => 'sizeMb', 'col' => 'size_mb', 'label' => 'حجم النسخة (ميجابايت)', 'type' => 'num'],
        ['key' => 'durationMin', 'col' => 'duration_min', 'label' => 'مدة الاستعادة (دقيقة)', 'type' => 'num'],
        ['key' => 'status', 'col' => 'status', 'label' => 'النتيجة', 'type' => 'sel',
         'options' => ['نجحت كاملة', 'نجحت بنواقص', 'فشلت']],
        ['key' => 'missing', 'col' => 'missing', 'label' => 'الملفات أو الجداول المفقودة', 'type' => 'ta'],
        ['key' => 'encrypted', 'col' => 'encrypted', 'label' => 'النسخة مشفّرة', 'type' => 'bool'],
        ['key' => 'offsite', 'col' => 'offsite', 'label' => 'توجد نسخة خارج الخادم', 'type' => 'bool'],
        ['key' => 'offsiteLoc', 'col' => 'offsite_loc', 'label' => 'مكان النسخة الخارجية', 'type' => 'text'],
        ['key' => 'fix', 'col' => 'fix', 'label' => 'الإجراء التصحيحي', 'type' => 'ta'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق (سجل الاستعادة)', 'type' => 'file'],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'backup_name', 'offsite_loc', 'notes'],
];

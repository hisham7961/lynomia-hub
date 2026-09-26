<?php

/** سجلُّ الوحدات — «dbs» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'dbs',
    'table' => 'databases_reg',
    'model' => 'DatabaseReg',
    'label' => 'قواعد البيانات',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'engine',
        'projectId',
        'env',
        'lastBk',
        'status',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم قاعدة البيانات',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'engine',
            'col' => 'engine',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'PostgreSQL',
                'MySQL / MariaDB',
                'MongoDB',
                'Redis',
                'SQLite',
                'Firebase',
                'SQL Server',
                'أخرى',
            ],
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع',
            'type' => 'ref',
            'ref' => 'projects',
        ],
        [
            'key' => 'serverId',
            'col' => 'server_id',
            'label' => 'السيرفر',
            'type' => 'ref',
            'ref' => 'servers',
        ],
        [
            'key' => 'host',
            'col' => 'host',
            'label' => 'المضيف / العنوان',
            'type' => 'text',
        ],
        [
            'key' => 'port',
            'col' => 'port',
            'label' => 'المنفذ',
            'type' => 'text',
        ],
        [
            'key' => 'dbUser',
            'col' => 'db_user',
            'label' => 'المستخدم',
            'type' => 'text',
        ],
        [
            'key' => 'vaultId',
            'col' => 'vault_id',
            'label' => 'بيانات الدخول (الخزنة)',
            'type' => 'ref',
            'ref' => 'vault',
        ],
        [
            'key' => 'size',
            'col' => 'size',
            'label' => 'الحجم التقريبي',
            'type' => 'text',
        ],
        [
            'key' => 'backup',
            'col' => 'backup',
            'label' => 'سياسة النسخ الاحتياطي',
            'type' => 'text',
        ],
        [
            'key' => 'lastBk',
            'col' => 'last_bk',
            'label' => 'آخر نسخة احتياطية',
            'type' => 'date',
            // تاريخ ماضٍ بدلالة معكوسة (القديم هو الخطر لا القريب) — مكانه قاعدة
            // تنبيه «بلا نسخة منذ N يوم» لا رادار المواعيد
            'expiry' => false,
        ],
        [
            'key' => 'env',
            'col' => 'env',
            'label' => 'البيئة',
            'type' => 'sel',
            'options' => [
                'إنتاج',
                'اختبار',
                'تطوير',
            ],
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'تعمل',
                'متوقفة',
                'قيد الترحيل',
                'مؤرشفة',
            ],
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'ملاحظات',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'name',
        'host',
        'port',
        'db_user',
        'size',
    ],
];

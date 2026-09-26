<?php

/** سجلُّ الوحدات — «code» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'code',
    'table' => 'code_releases',
    'model' => 'CodeRelease',
    'label' => 'الكود المصدري',
    'display' => 'ver',
    'status' => 'status',
    'columns' => [
        'ver',
        'projectId',
        'appId',
        'type',
        'file',
        'date',
        'status',
    ],
    'fields' => [
        [
            'key' => 'ver',
            'col' => 'ver',
            'label' => 'رقم النسخة',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع',
            'type' => 'ref',
            'required' => true,
            'ref' => 'projects',
        ],
        [
            'key' => 'appId',
            'col' => 'app_id',
            'label' => 'التطبيق',
            'type' => 'ref',
            'ref' => 'apps',
        ],
        [
            'key' => 'type',
            'col' => 'type',
            'label' => 'نوع الإصدار',
            'type' => 'sel',
            'options' => [
                'نسخة كاملة',
                'تحديث',
                'إصلاح عاجل',
                'تجريبية',
            ],
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مسودة',
                'مستقرة',
                'منشورة',
                'مؤرشفة',
            ],
        ],
        [
            'key' => 'date',
            'col' => 'date',
            'label' => 'تاريخ الإصدار',
            'type' => 'date',
        ],
        [
            'key' => 'file',
            'col' => 'file_id',
            'label' => 'ملف الكود (zip حتى 2.5MB)',
            // كان 'big' (رقم) على عمود uuid مرفقات — فالعرضُ يرسم مدخلاً
            // رقمياً والتحققُ يفرض numeric والتخزينُ يكتب رقماً في uuid:
            // ميزةُ إرفاق الحزمة كانت معطلةً عبر الطبقات الثلاث
            'type' => 'file',
        ],
        [
            'key' => 'repo',
            'col' => 'repo',
            'label' => 'رابط المستودع (Git)',
            'type' => 'url',
        ],
        [
            'key' => 'branch',
            'col' => 'branch',
            'label' => 'الفرع (Branch)',
            'type' => 'text',
        ],
        [
            'key' => 'commit',
            'col' => 'commit',
            'label' => 'Commit',
            'type' => 'text',
        ],
        [
            'key' => 'tags',
            'col' => 'tags',
            'label' => 'وسوم',
            'type' => 'tags',
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'سجل التغييرات / ملاحظات',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'ver',
        'branch',
        'commit',
    ],
];

<?php

/** سجلُّ الوحدات — «kb» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'kb',
    'table' => 'kb_articles',
    'model' => 'KbArticle',
    'label' => 'قاعدة المعرفة والسياسات',
    'display' => 'title',
    'status' => 'status',
    'columns' => [
        'title',
        'cat',
        'ver',
        'status',
        'mustRead',
    ],
    'fields' => [
        [
            'key' => 'title',
            'col' => 'title',
            'label' => 'العنوان',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'cat',
            'col' => 'cat',
            'label' => 'التصنيف',
            'type' => 'sel',
            'options' => [
                'سياسة شركة',
                'دليل تشغيل SOP',
                'خطوات تنفيذ خدمة',
                'معلومات منتج',
                'حل مشكلة متكررة',
                'تعليمات موظفين',
                'Onboarding',
                'Offboarding',
                'أسئلة شائعة',
                'وثيقة تقنية',
            ],
        ],
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة',
            'type' => 'ref',
            'ref' => 'companies',
        ],
        [
            'key' => 'ownerId',
            'col' => 'owner_id',
            'label' => 'المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'ver',
            'col' => 'ver',
            'label' => 'الإصدار',
            'type' => 'text',
        ],
        [
            'key' => 'mustRead',
            'col' => 'must_read',
            'label' => 'قراءة إلزامية',
            'type' => 'bool',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مسودة',
                'قيد المراجعة',
                'منشور',
                'مؤرشف',
            ],
        ],
        [
            'key' => 'body',
            'col' => 'body',
            'label' => 'المحتوى',
            'type' => 'ta',
        ],
        [
            'key' => 'att',
            'col' => 'att_id',
            'label' => 'مرفق',
            'type' => 'file',
        ],
        [
            'key' => 'tags',
            'col' => 'tags',
            'label' => 'وسوم',
            'type' => 'tags',
        ],
    ],
    'search' => [
        'title',
        'ver',
        'body',
    ],
];

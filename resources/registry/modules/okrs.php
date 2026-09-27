<?php

/** سجلُّ الوحدات — «okrs» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'okrs',
    'table' => 'objectives',
    'model' => 'Objective',
    'label' => 'الأهداف والنتائج (OKR)',
    'display' => 'title',
    'status' => 'status',
    'columns' => [
        'title',
        'level',
        'ownerId',
        'period',
        'progress',
        'status',
    ],
    'fields' => [
        [
            'key' => 'title',
            'col' => 'title',
            'label' => 'الهدف',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'level',
            'col' => 'level',
            'label' => 'المستوى',
            'type' => 'sel',
            'options' => [
                'الشركة',
                'قسم',
                'مشروع',
                'موظف',
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
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع',
            'type' => 'ref',
            'ref' => 'projects',
        ],
        [
            'key' => 'ownerId',
            'col' => 'owner_id',
            'label' => 'المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'period',
            'col' => 'period',
            'label' => 'الفترة',
            'type' => 'sel',
            'options' => [
                'الربع الأول',
                'الربع الثاني',
                'الربع الثالث',
                'الربع الرابع',
                'سنوي',
                'شهري',
            ],
        ],
        [
            'key' => 'start',
            'col' => 'date_start',
            'label' => 'البداية',
            'type' => 'date',
        ],
        [
            'key' => 'due',
            'col' => 'due',
            'label' => 'الاستحقاق',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            // تُحسب من النتائج الرئيسية وتُكتب تلقائياً — الكتابة اليدوية
            // تنقض OKR من أصله. تبقى مفيدةً لهدفٍ لا نتائج له بعد.
            'key' => 'progress',
            'col' => 'progress',
            'label' => 'نسبة الإنجاز % (تُحسب من النتائج الرئيسية)',
            'type' => 'num',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مخطط',
                'قيد التنفيذ',
                'على المسار',
                'متعثر',
                'مكتمل',
                'ملغى',
            ],
        ],
        [
            'key' => 'desc',
            'col' => 'description',
            'label' => 'الوصف',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'title',
        'description',
    ],
];

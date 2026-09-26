<?php

/** سجلُّ الوحدات — «stations» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'stations',
    'table' => 'stations',
    'model' => 'Station',
    'label' => 'المحطات',
    'display' => 'code',
    'status' => 'status',
    'columns' => [
        'code',
        'facility',
        'zone',
        'desk',
        'type',
        'dept',
        'currentEmployeeId',
        'status',
    ],
    'fields' => [
        [
            'key' => 'code',
            'col' => 'code',
            'label' => 'كود المحطة',
            'type' => 'text',
            // يولّده النظام (Station::nextCode) ويُطبَع على الملصق ويُمسَح
            // بـs/{code} — فلا يُكتب يدوياً من CRUD ولا API (نمطُ assets.code).
            'locked' => true,
        ],
        [
            'key' => 'facility',
            'col' => 'facility',
            'label' => 'المبنى / المنشأة',
            'type' => 'text',
        ],
        [
            'key' => 'floor',
            'col' => 'floor',
            'label' => 'الطابق',
            'type' => 'text',
        ],
        [
            'key' => 'zone',
            'col' => 'zone',
            'label' => 'المنطقة',
            'type' => 'text',
        ],
        [
            'key' => 'room',
            'col' => 'room',
            'label' => 'الغرفة',
            'type' => 'text',
        ],
        [
            'key' => 'desk',
            'col' => 'desk',
            'label' => 'المكتب / الرقم',
            'type' => 'text',
        ],
        [
            'key' => 'type',
            'col' => 'type',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'مكتب',
                'استقبال',
                'مختبر',
                'قاعة اجتماعات',
                'مستودع',
                'ميداني',
                'أخرى',
            ],
        ],
        [
            'key' => 'dept',
            'col' => 'dept',
            'label' => 'القسم',
            'type' => 'text',
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
            'key' => 'currentEmployeeId',
            'col' => 'current_employee_id',
            'label' => 'المُسنَد إليه الآن',
            'type' => 'ref',
            'ref' => 'users',
            // يُكتب من مسار الإسناد/الإخلاء المقفل وحدَه (StationController)،
            // لا من النموذج العامّ بلا قيدٍ — فكلُّ تغييرِ مقعدٍ مُدقَّق (نمطُ assets.holderId).
            'locked' => true,
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'متاحة',
                'مشغولة',
                'محجوزة',
                'صيانة',
                'معطّلة',
            ],
        ],
    ],
    'search' => [
        'code',
        'facility',
        'zone',
        'room',
        'desk',
        'dept',
    ],
];

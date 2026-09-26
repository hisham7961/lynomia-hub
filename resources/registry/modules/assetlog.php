<?php

/** سجلُّ الوحدات — «assetlog» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'assetlog',
    'table' => 'asset_maintenance',
    'model' => 'AssetMaintenance',
    'label' => 'سجل الصيانة',
    'display' => 'title',
    'status' => 'status',
    'columns' => [
        'assetId',
        'title',
        'kind',
        'date',
        'cost',
        'status',
    ],
    'fields' => [
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        [
            'key' => 'assetId',
            'col' => 'asset_id',
            'label' => 'الأصل',
            'type' => 'ref',
            'required' => true,
            'ref' => 'assets',
        ],
        [
            'key' => 'title',
            'col' => 'title',
            'label' => 'العملية',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'kind',
            'col' => 'kind',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'صيانة دورية',
                'إصلاح عطل',
                'تغيير قطعة',
                'ترقية',
                'فحص',
            ],
        ],
        [
            'key' => 'date',
            'col' => 'date',
            'label' => 'التاريخ',
            'type' => 'date',
            'required' => true,
        ],
        [
            'key' => 'byId',
            'col' => 'by_id',
            'label' => 'نفّذها',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'vendor',
            'col' => 'vendor',
            'label' => 'الجهة المنفذة',
            'type' => 'text',
        ],
        [
            'key' => 'cost',
            'col' => 'cost',
            'label' => 'التكلفة',
            'type' => 'num',
        ],
        [
            'key' => 'part',
            'col' => 'part',
            'label' => 'القطعة المستبدلة',
            'type' => 'text',
        ],
        [
            'key' => 'next',
            'col' => 'next',
            'label' => 'الصيانة القادمة',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مكتملة',
                'قيد التنفيذ',
                'مجدولة',
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
        'title',
        'vendor',
        'part',
    ],
];

<?php

/** سجلُّ الوحدات — «costc» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'costc',
    'table' => 'cost_centers',
    'model' => 'CostCenter',
    'label' => 'مراكز التكلفة',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'code',
        'name',
        'kind',
        'managerId',
        'status',
    ],
    'fields' => [
        [
            'key' => 'code',
            'col' => 'code',
            'label' => 'الرمز',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم المركز',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'kind',
            'col' => 'kind',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'قسم',
                'مشروع',
                'منتج',
                'فرع',
                'نشاط',
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
            'key' => 'managerId',
            'col' => 'manager_id',
            'label' => 'المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'نشط',
                'مغلق',
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
        'code',
        'name',
    ],
];

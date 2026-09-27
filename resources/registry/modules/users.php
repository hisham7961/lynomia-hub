<?php

/** سجلُّ الوحدات — «users» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'users',
    'table' => 'users',
    'model' => 'User',
    'label' => 'المستخدمون',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'title',
        'roleId',
        'status',
        'expiry',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'الاسم',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'email',
            'col' => 'email',
            'label' => 'البريد',
            'type' => 'text',
        ],
        [
            'key' => 'phone',
            'col' => 'phone',
            'label' => 'الهاتف',
            'type' => 'text',
        ],
        [
            'key' => 'title',
            'col' => 'title',
            'label' => 'المسمى الوظيفي',
            'type' => 'text',
        ],
        [
            'key' => 'roleId',
            'col' => 'role_id',
            'label' => 'الدور',
            'type' => 'ref',
            'required' => true,
            'ref' => 'roles',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'نشط',
                'موقوف',
            ],
        ],
        [
            'key' => 'expiry',
            'col' => 'expiry',
            'label' => 'انتهاء الحساب (مستخدم مؤقت)',
            'type' => 'date',
        ],
        [
            'key' => 'allowedIp',
            'col' => 'allowed_ip',
            'label' => 'قيود IP المسموح (تُطبَّق بالنسخة الخادمية)',
            'type' => 'text',
        ],
    ],
    'search' => [
        'name',
        'email',
        'phone',
        'title',
        'allowed_ip',
    ],
];

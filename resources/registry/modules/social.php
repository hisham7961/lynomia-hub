<?php

/** سجلُّ الوحدات — «social» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'social',
    'table' => 'social_accounts',
    'model' => 'SocialAccount',
    'label' => 'السوشال ميديا',
    'display' => 'handle',
    'status' => 'status',
    'columns' => [
        'platform',
        'handle',
        'projectId',
        'followers',
        'status',
    ],
    'fields' => [
        [
            'key' => 'platform',
            'col' => 'platform',
            'label' => 'المنصة',
            'type' => 'sel',
            'required' => true,
            'options' => [
                'Instagram',
                'TikTok',
                'X (Twitter)',
                'Snapchat',
                'YouTube',
                'Facebook',
                'LinkedIn',
                'Telegram',
                'قناة WhatsApp',
                'أخرى',
            ],
        ],
        [
            'key' => 'handle',
            'col' => 'handle',
            'label' => 'المعرّف / الحساب',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'url',
            'col' => 'url',
            'label' => 'رابط الحساب',
            'type' => 'url',
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
            'label' => 'المسؤول عن المحتوى',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'followers',
            'col' => 'followers',
            'label' => 'المتابعون حالياً',
            'type' => 'num',
        ],
        [
            'key' => 'goal',
            'col' => 'goal',
            'label' => 'الهدف',
            'type' => 'num',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'نشط',
                'موقوف',
                'معلّق',
            ],
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
            'label' => 'ملاحظات',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'handle',
    ],
];

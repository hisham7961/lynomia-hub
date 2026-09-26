<?php

/** سجلُّ الوحدات — «decisions» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'decisions',
    'table' => 'decisions',
    'model' => 'Decision',
    'label' => 'سجل القرارات',
    'display' => 'title',
    'status' => 'status',
    'columns' => [
        'title',
        'projectId',
        'ownerId',
        'date',
    ],
    'fields' => [
        [
            'key' => 'title',
            'col' => 'title',
            'label' => 'القرار',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع المتأثر',
            'type' => 'ref',
            'ref' => 'projects',
        ],
        [
            'key' => 'clientId',
            'col' => 'client_id',
            'label' => 'العميل',
            'type' => 'ref',
            'ref' => 'clients',
        ],
        [
            'key' => 'execId',
            'col' => 'exec_id',
            'label' => 'المسؤول عن التنفيذ',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'due',
            'col' => 'due',
            'label' => 'الموعد النهائي',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'حالة التنفيذ',
            'type' => 'sel',
            'options' => [
                'لم يبدأ',
                'قيد التنفيذ',
                'منفَّذ',
                'متعثر',
                'ملغى',
            ],
        ],
        [
            'key' => 'meetingId',
            'col' => 'meeting_id',
            'label' => 'الاجتماع المرتبط',
            'type' => 'ref',
            'ref' => 'meetings',
        ],
        [
            'key' => 'ownerId',
            'col' => 'owner_id',
            'label' => 'صاحب القرار',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'date',
            'col' => 'date',
            'label' => 'تاريخ القرار',
            'type' => 'date',
        ],
        [
            'key' => 'reason',
            'col' => 'reason',
            'label' => 'سبب القرار',
            'type' => 'ta',
        ],
        [
            'key' => 'parts',
            'col' => 'parts',
            'label' => 'المشاركون',
            'type' => 'ref',
            'ref' => 'users',
            'multi' => true,
        ],
        [
            'key' => 'att',
            'col' => 'att_id',
            'label' => 'مرفق',
            'type' => 'file',
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'التفاصيل والمهام الناتجة',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'title',
        'reason',
    ],
];

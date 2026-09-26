<?php

/** سجلُّ الوحدات — «meetings» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'meetings',
    'table' => 'meetings',
    'model' => 'Meeting',
    'label' => 'الاجتماعات',
    'display' => 'title',
    'status' => 'status',
    'columns' => [
        'title',
        'dt',
        'projectId',
    ],
    'fields' => [
        ['key' => 'status', 'col' => 'status', 'label' => 'حالة الاجتماع', 'type' => 'sel',
         'options' => ['مجدول', 'انعقد', 'بانتظار المحضر', 'مُلغى']],
        [
            'key' => 'title',
            'col' => 'title',
            'label' => 'عنوان الاجتماع',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'dt',
            'col' => 'dt',
            'label' => 'التاريخ والوقت',
            'type' => 'dt',
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع',
            'type' => 'ref',
            'ref' => 'projects',
        ],
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة',
            'type' => 'ref',
            'ref' => 'companies',
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
            'key' => 'link',
            'col' => 'link',
            'label' => 'رابط الاجتماع',
            'type' => 'url',
        ],
        [
            'key' => 'clientId',
            'col' => 'client_id',
            'label' => 'العميل',
            'type' => 'ref',
            'ref' => 'clients',
        ],
        [
            'key' => 'repeat',
            'col' => 'repeat',
            'label' => 'التكرار',
            'type' => 'sel',
            'options' => [
                'مرة واحدة',
                'أسبوعي',
                'كل أسبوعين',
                'شهري',
            ],
        ],
        [
            'key' => 'agenda',
            'col' => 'agenda',
            'label' => 'جدول الأعمال',
            'type' => 'ta',
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'محضر الاجتماع',
            'type' => 'ta',
        ],
        [
            'key' => 'decs',
            'col' => 'decs',
            'label' => 'القرارات',
            'type' => 'ta',
        ],
        [
            'key' => 'att',
            'col' => 'att_id',
            'label' => 'مرفق',
            'type' => 'file',
        ],
    ],
    'search' => [
        'title',
        'agenda',
        'decs',
    ],
];

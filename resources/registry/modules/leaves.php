<?php

/** سجلُّ الوحدات — «leaves» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'leaves',
    'table' => 'leave_requests',
    'model' => 'LeaveRequest',
    'label' => 'الإجازات والطلبات',
    'display' => 'type',
    'status' => 'status',
    'columns' => [
        'empId',
        'type',
        'from',
        'to',
        'days',
        'status',
    ],
    'fields' => [
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        [
            'key' => 'empId',
            'col' => 'emp_id',
            'label' => 'الموظف',
            'type' => 'ref',
            'required' => true,
            'ref' => 'hr',
        ],
        [
            'key' => 'type',
            'col' => 'type',
            'label' => 'نوع الطلب',
            'type' => 'sel',
            'required' => true,
            'options' => [
                'إجازة سنوية',
                'إجازة مرضية',
                'إجازة طارئة',
                'إذن خروج',
                'عمل عن بعد',
                'سلفة',
                'شهادة راتب',
                'أخرى',
            ],
        ],
        [
            'key' => 'from',
            'col' => 'date_from',
            'label' => 'من',
            'type' => 'date',
            'required' => true,
        ],
        [
            'key' => 'to',
            'col' => 'date_to',
            'label' => 'إلى',
            'type' => 'date',
        ],
        [
            'key' => 'days',
            'col' => 'days',
            'label' => 'عدد الأيام',
            'type' => 'num',
        ],
        [
            'key' => 'reason',
            'col' => 'reason',
            'label' => 'السبب',
            'type' => 'ta',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مقدّم',
                'موافقة المدير',
                'موافقة الموارد البشرية',
                'معتمد',
                'مرفوض',
                'ملغى',
            ],
        ],
        [
            'key' => 'mgrId',
            'col' => 'mgr_id',
            'label' => 'المدير',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'note',
            'col' => 'note',
            'label' => 'ملاحظة القرار',
            'type' => 'text',
        ],
    ],
    'search' => [
        'reason',
        'note',
    ],
];

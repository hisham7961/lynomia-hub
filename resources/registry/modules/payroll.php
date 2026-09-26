<?php

/** سجلُّ الوحدات — «payroll» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'payroll',
    'table' => 'payroll_runs',
    'model' => 'PayrollRun',
    'label' => 'مسيّرات الرواتب',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'month',
        'total',
        'payDate',
        'status',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم المسيّر',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'month',
            'col' => 'month',
            'label' => 'الشهر',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة',
            'type' => 'ref',
            'ref' => 'companies',
        ],
        [
            'key' => 'total',
            'col' => 'total',
            'label' => 'الإجمالي',
            'type' => 'num',
            'money' => true,
            // مشتقٌّ من مجموع سطور المسيّر (generate/adjust يكتبانه على النموذج
            // مباشرةً) — لا يُحرَّر من النموذج العام، وإلا بنى عليه قيدُ اليومية
            // المُرحَّل (المقفول أبداً) رقماً مختلَقاً لا يُصحَّح بعد الترحيل.
            'locked' => true,
        ],
        [
            'key' => 'currency',
            'col' => 'currency',
            'label' => 'العملة',
            'type' => 'sel',
            'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو'],
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مسودة',
                'معتمد',
                'مدفوع',
            ],
        ],
        [
            'key' => 'payDate',
            'col' => 'pay_date',
            'label' => 'تاريخ الصرف',
            'type' => 'date',
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'ملاحظات',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'name',
        'month',
    ],
];

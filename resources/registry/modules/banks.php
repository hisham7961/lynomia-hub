<?php

/** سجلُّ الوحدات — «banks» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'banks',
    'table' => 'bank_accounts',
    'model' => 'BankAccount',
    'label' => 'البنوك والصناديق',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'kind',
        'bank',
        'currency',
        'balance',
        'status',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم الحساب',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'kind',
            'col' => 'kind',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'حساب بنكي',
                'صندوق نقدي',
                'محفظة إلكترونية',
                'بطاقة',
            ],
        ],
        [
            'key' => 'bank',
            'col' => 'bank',
            'label' => 'البنك',
            'type' => 'text',
        ],
        [
            'key' => 'iban',
            'col' => 'iban',
            'label' => 'IBAN / رقم الحساب',
            'type' => 'text',
        ],
        [
            'key' => 'currency',
            'col' => 'currency',
            'label' => 'العملة',
            'type' => 'sel',
            'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو'],
        ],
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة',
            'type' => 'ref',
            'ref' => 'companies',
        ],
        [
            'key' => 'balance',
            'col' => 'balance',
            'label' => 'الرصيد الحالي',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'minBal',
            'col' => 'min_bal',
            'label' => 'حد التنبيه',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'accId',
            'col' => 'acc_id',
            'label' => 'الحساب المحاسبي',
            'type' => 'ref',
            'ref' => 'accounts2',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'نشط',
                'مجمّد',
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
        'name',
        'bank',
        'iban',
    ],
];

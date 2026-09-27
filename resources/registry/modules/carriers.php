<?php

/** سجلُّ الوحدات — «carriers» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'carriers',
    'table' => 'carriers',
    'model' => 'Carrier',
    'label' => 'مزوّدو الاتصالات',
    'display' => 'name',
    'status' => null,
    'columns' => [
        'name',
        'companyId',
        'portalUrl',
        'supportPhone',
        'accountNo',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم المزوّد',
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
            'key' => 'portalUrl',
            'col' => 'portal_url',
            'label' => 'رابط بوّابة المزوّد',
            'type' => 'url',
        ],
        [
            'key' => 'portalUsername',
            'col' => 'portal_username',
            'label' => 'اسم المستخدم للبوّابة',
            'type' => 'text',
        ],
        // سرٌّ (VaultSecret): كلمةُ مرور البوّابة — تُقنَّع •••• ولا تُطبع،
        // تُكشف عبر revealSecret وحدَه بأثرِ «عرض حساس».
        [
            'key' => 'portalPassword',
            'col' => 'portal_password',
            'label' => 'كلمة مرور البوّابة',
            'type' => 'sec',
        ],
        [
            'key' => 'supportPhone',
            'col' => 'support_phone',
            'label' => 'هاتف الدعم',
            'type' => 'text',
        ],
        [
            'key' => 'accountNo',
            'col' => 'account_no',
            'label' => 'رقم الحساب لدى المزوّد',
            'type' => 'text',
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
        'portal_username',
        'account_no',
        'support_phone',
    ],
];

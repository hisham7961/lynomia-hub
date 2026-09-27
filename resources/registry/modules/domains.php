<?php

/** سجلُّ الوحدات — «domains» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'domains',
    'table' => 'domains',
    'model' => 'Domain',
    'label' => 'الدومينات',
    'display' => 'name',
    'status' => 'ssl',
    /*
     * **وحالةُ الشهادة لا تُقصي الدومين من رادار الانتهاءات** (v2.340):
     * `hub_expiry` يُقصي كلَّ سجلٍ حالتُه من `hub_closed_states()` —
     * وفيها **«منتهي»**، وهو أحدُ خيارات `ssl` حرفياً. فدومينٌ شهادتُه
     * منتهية كان يسقط من الرادار كليّاً: السجلُّ الذي يحتاج الإنذارَ
     * أشدَّ من غيره وحدَه لا يُنذَر عنه.
     *
     * والإقصاءُ صحيحٌ حيث تكون «الحالة» مرحلةً في مسار (مهمةٌ منجزة،
     * فاتورةٌ مدفوعة) — و`ssl` وصفُ شهادةٍ لا مرحلةُ سجل. الرايةُ تُقرأ
     * في `hub_expiry` وحدَها، وتبقى الحالةُ تقود الكانبانَ ومسارات
     * العمل («شهادة SSL انتهت» منها).
     */
    'expiryIgnoresStatus' => true,
    'columns' => [
        'name',
        'projectId',
        'registrar',
        'expiry',
        'ssl',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم الدومين',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة المالكة',
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
            'key' => 'registrar',
            'col' => 'registrar',
            'label' => 'شركة التسجيل',
            'type' => 'text',
        ],
        [
            'key' => 'regDate',
            'col' => 'reg_date',
            'label' => 'تاريخ التسجيل',
            'type' => 'date',
        ],
        [
            'key' => 'expiry',
            'col' => 'expiry',
            'label' => 'تاريخ الانتهاء',
            'type' => 'date',
            'required' => true,
            'expiry' => true,
        ],
        [
            'key' => 'autoRenew',
            'col' => 'auto_renew',
            'label' => 'تجديد تلقائي',
            'type' => 'bool',
        ],
        [
            'key' => 'cost',
            'col' => 'cost',
            'label' => 'تكلفة التجديد',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'currency',
            'col' => 'currency',
            'label' => 'العملة',
            'type' => 'sel',
            'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو'],
        ],
        [
            'key' => 'ns',
            'col' => 'ns',
            'label' => 'Name Servers',
            'type' => 'text',
        ],
        [
            'key' => 'dns',
            'col' => 'dns',
            'label' => 'مزود DNS',
            'type' => 'text',
        ],
        [
            'key' => 'ssl',
            'col' => 'ssl',
            'label' => 'حالة SSL',
            'type' => 'sel',
            'options' => [
                'فعال',
                'منتهي',
                'لا يوجد',
            ],
        ],
        [
            'key' => 'sslExp',
            'col' => 'ssl_exp',
            'label' => 'انتهاء SSL',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            'key' => 'ownerId',
            'col' => 'owner_id',
            'label' => 'المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'alert',
            'col' => 'alert',
            'label' => 'تنبيه قبل (يوم)',
            'type' => 'num',
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
        'registrar',
        'ns',
        'dns',
    ],
];

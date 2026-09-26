<?php

/** سجلُّ الوحدات — «phones» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'phones',
    'table' => 'phone_numbers',
    'model' => 'PhoneNumber',
    'label' => 'أرقام الهواتف',
    'display' => 'number',
    'status' => 'status',
    'columns' => [
        'number',
        'lineType',
        'country',
        'usage',
        'wa',
        'status',
    ],
    'fields' => [
        [
            'key' => 'number',
            'col' => 'number',
            'label' => 'الرقم',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'country',
            'col' => 'country',
            'label' => 'الدولة',
            'type' => 'sel',
            'options' => ['الكويت', 'السعودية', 'الإمارات', 'قطر', 'البحرين', 'عُمان', 'مصر', 'الأردن', 'أخرى'],
        ],
        [
            'key' => 'carrier',
            'col' => 'carrier',
            'label' => 'شركة الاتصالات',
            'type' => 'text',
        ],
        // ── هويّةُ الشريحة (Work OS · الطور G · WP-G.1 · §22) ──
        [
            'key' => 'lineType',
            'col' => 'line_type',
            'label' => 'نوع الخط',
            'type' => 'sel',
            'options' => ['SIM', 'eSIM', 'رقم ثابت', 'رقم مجاني', 'بيانات (M2M)'],
        ],
        [
            'key' => 'iccid',
            'col' => 'iccid',
            'label' => 'ICCID (رقم الشريحة)',
            'type' => 'text',
        ],
        [
            'key' => 'imsi',
            'col' => 'imsi',
            'label' => 'IMSI',
            'type' => 'text',
        ],
        [
            'key' => 'msisdn',
            'col' => 'msisdn',
            'label' => 'MSISDN (رقم الخدمة)',
            'type' => 'text',
        ],
        [
            'key' => 'apn',
            'col' => 'apn',
            'label' => 'APN',
            'type' => 'text',
        ],
        // ── الأسرار: PIN/PUK مراجعُ خزنة (VaultSecret) — تُقنَّع •••• ولا تُطبع ──
        // كشفُ PUK يتطلب تصعيدَ المصادقة (stepup) لأنه يفكّ قفلَ الشريحة نهائياً.
        [
            'key' => 'pin',
            'col' => 'pin',
            'label' => 'PIN',
            'type' => 'sec',
        ],
        [
            'key' => 'puk',
            'col' => 'puk',
            'label' => 'PUK',
            'type' => 'sec',
            'stepup' => true,
        ],
        // ── الباقة ──
        [
            'key' => 'planName',
            'col' => 'plan_name',
            'label' => 'اسم الباقة',
            'type' => 'text',
        ],
        [
            'key' => 'planData',
            'col' => 'plan_data',
            'label' => 'بيانات الباقة',
            'type' => 'text',
        ],
        [
            'key' => 'planVoice',
            'col' => 'plan_voice',
            'label' => 'دقائق الباقة',
            'type' => 'text',
        ],
        [
            'key' => 'planSms',
            'col' => 'plan_sms',
            'label' => 'رسائل الباقة',
            'type' => 'text',
        ],
        [
            'key' => 'roaming',
            'col' => 'roaming',
            'label' => 'التجوال مُفعَّل',
            'type' => 'bool',
        ],
        [
            'key' => 'billingCycle',
            'col' => 'billing_cycle',
            'label' => 'دورة الفوترة',
            'type' => 'sel',
            'options' => ['شهري', 'ربع سنوي', 'نصف سنوي', 'سنوي', 'مسبق الدفع', 'لاحق الدفع'],
        ],
        // ── روابطُ الأصل (Work OS · الطور G · WP-G.2 · §22/§23/§28) ──
        // مراجعُ تُضيء علاقاتِ hub_children/hub_related تلقائياً: مزوّدٌ (خياراتُه
        // مُنطَّقةٌ بالشركة عبر hub_ref_options_scoped)، وموظفٌ (يُضيء تبويبَ
        // الاتصالات في الموظف 360)، وجهازٌ، ومحطة — والعزلُ الأدقّ يبقى في hub_scope.
        [
            'key' => 'carrierId',
            'col' => 'carrier_id',
            'label' => 'المزوّد (السجل)',
            'type' => 'ref',
            'ref' => 'carriers',
        ],
        [
            'key' => 'employeeId',
            'col' => 'employee_id',
            'label' => 'الموظف المُخصَّص له',
            'type' => 'ref',
            'ref' => 'hr',
        ],
        [
            'key' => 'deviceId',
            'col' => 'device_id',
            'label' => 'الجهاز',
            'type' => 'ref',
            'ref' => 'assets',
        ],
        [
            'key' => 'stationId',
            'col' => 'station_id',
            'label' => 'المحطة',
            'type' => 'ref',
            'ref' => 'stations',
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
            'key' => 'usage',
            'col' => 'usage',
            'label' => 'الاستخدام',
            'type' => 'text',
        ],
        [
            'key' => 'ownerId',
            'col' => 'owner_id',
            'label' => 'المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'sms',
            'col' => 'sms',
            'label' => 'يدعم SMS',
            'type' => 'bool',
        ],
        [
            'key' => 'wa',
            'col' => 'wa',
            'label' => 'مرتبط بـ WhatsApp',
            'type' => 'bool',
        ],
        [
            'key' => 'expiry',
            'col' => 'expiry',
            'label' => 'انتهاء الخط',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            'key' => 'cost',
            'col' => 'cost',
            'label' => 'تكلفة الخط',
            'type' => 'num',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            // الحالاتُ الثلاثُ القديمةُ تبقى (توافقٌ رجعيّ — aliases)، ثم دورةُ حياةٍ
            // أغنى للشريحة. **صدقُ التأجيل (النقد C15):** الحالةُ سجلٌّ يحرّره
            // مسؤولٌ يدوياً — لا توفيرٌ حيٌّ عبر APIs المشغّلين (تفعيل/تعليق
            // حقيقيّ) في هذه الدفعة؛ ذلك يتطلب اتفاقيةَ مشغّلٍ ولم يُبنَ.
            'options' => [
                'نشط',
                'موقوف',
                'منتهي',
                'مخزون',
                'قيد التفعيل',
                'مُعلَّق',
                'منتهي الاشتراك',
                'مُلغى',
                'مفقود',
                'بدل فاقد',
            ],
        ],
        [
            'key' => 'activatedAt',
            'col' => 'activated_at',
            'label' => 'تاريخ التفعيل',
            'type' => 'date',
        ],
        [
            'key' => 'deactivatedAt',
            'col' => 'deactivated_at',
            'label' => 'تاريخ الإيقاف',
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
        'number',
        'carrier',
        'usage',
        'iccid',
        'msisdn',
        'plan_name',
    ],
];

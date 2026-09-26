<?php

/** سجلُّ الوحدات — «servers» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'servers',
    'table' => 'servers',
    'model' => 'Server',
    'label' => 'السيرفرات',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'provider',
        'ip',
        'projectId',
        'status',
        'expiry',
    ],
    'fields' => [
        ['key' => 'vaultId', 'col' => 'vault_id', 'label' => 'اعتماد الدخول (من الخزنة)', 'type' => 'ref', 'ref' => 'vault'],
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم السيرفر',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'provider',
            'col' => 'provider',
            'label' => 'المزود',
            'type' => 'text',
        ],
        [
            'key' => 'ip',
            'col' => 'ip',
            'label' => 'IP',
            'type' => 'text',
        ],
        [
            'key' => 'type',
            'col' => 'type',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'Cloud VPS',
                'Dedicated',
                'مشترك',
                'داخلي',
            ],
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

        // ── (الطور H · WP-H.1 · §37) حوافُّ البنية نحو المحطة/الأصل/الموظف ──
        // المرجعُ هو الحافّة: يلتقطها hub_build_children_map تلقائياً فتُضيء
        // «سيرفراتُ هذه المحطة/الأصل/الموظف» عبر hub_related بصلاحيّةٍ ونطاقٍ
        // مفروضَين بالبناء — لا جدولَ حوافٍّ ثانٍ ولا سكّةَ صلاحيّاتٍ ثانية.
        // راية `edge`: حافّةُ بنيةٍ لا يُحَلُّ اسمُ طرفها الآخر إلا لقارئٍ يملك
        // وحدتَه (قاعدةُ «الحافّةُ لمن يملك طرفَيها» — ModuleController يقنّعها «—»).
        ['key' => 'stationId', 'col' => 'station_id', 'label' => 'المحطة (المقعد الفعلي)',
            'type' => 'ref', 'ref' => 'stations', 'edge' => true],
        ['key' => 'assetId', 'col' => 'asset_id', 'label' => 'أصل العهدة العتاديّ',
            'type' => 'ref', 'ref' => 'assets', 'edge' => true],
        ['key' => 'hrId', 'col' => 'hr_id', 'label' => 'ملفُّ الموظف المسؤول',
            'type' => 'ref', 'ref' => 'hr', 'edge' => true],

        [
            'key' => 'os',
            'col' => 'os',
            'label' => 'نظام التشغيل',
            'type' => 'text',
        ],
        [
            'key' => 'specs',
            'col' => 'specs',
            'label' => 'المواصفات',
            'type' => 'text',
        ],

        // ── المراقبة الحيّة: كان الجدول يخبرك بالمزوّد ولا يخبرك أَحيٌّ هو الآن ──
        ['key' => 'monitorOn', 'col' => 'monitor_on', 'label' => 'فعّل المراقبة الحيّة', 'type' => 'bool'],
        ['key' => 'monitorUrl', 'col' => 'monitor_url', 'label' => 'رابط الفحص (health endpoint)', 'type' => 'url'],
        ['key' => 'statusUrl', 'col' => 'status_url', 'label' => 'صفحة حالة المزوّد', 'type' => 'url'],
        ['key' => 'cfZone', 'col' => 'cf_zone', 'label' => 'منطقة Cloudflare', 'type' => 'text'],

        // ── المواصفات المفصّلة وكلفتها ──
        ['key' => 'region', 'col' => 'region', 'label' => 'الموقع الجغرافي', 'type' => 'text'],
        ['key' => 'cores', 'col' => 'cores', 'label' => 'أنوية المعالج', 'type' => 'num'],
        ['key' => 'ramGb', 'col' => 'ram_gb', 'label' => 'الذاكرة (GB)', 'type' => 'num'],
        ['key' => 'diskGb', 'col' => 'disk_gb', 'label' => 'القرص (GB)', 'type' => 'num'],
        ['key' => 'costMonth', 'col' => 'cost_month', 'label' => 'الكلفة الشهرية', 'type' => 'num', 'money' => true,],
        ['key' => 'backupPolicy', 'col' => 'backup_policy', 'label' => 'سياسة النسخ الاحتياطي', 'type' => 'text'],
        ['key' => 'lastPatch', 'col' => 'last_patch', 'label' => 'آخر ترقيع أمني', 'type' => 'date'],

        [
            'key' => 'panel',
            'col' => 'panel',
            'label' => 'لوحة التحكم',
            'type' => 'url',
        ],
        [
            'key' => 'expiry',
            'col' => 'expiry',
            'label' => 'تاريخ الانتهاء',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            'key' => 'cost',
            'col' => 'cost',
            'label' => 'تكلفة الدورة',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'cycle',
            'col' => 'cycle',
            'label' => 'دورة التكلفة',
            'type' => 'sel',
            'options' => ['شهري', 'ربع سنوي', 'نصف سنوي', 'سنوي', 'مرة واحدة'],
        ],
        [
            'key' => 'currency',
            'col' => 'currency',
            'label' => 'العملة',
            'type' => 'sel',
            'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو'],
        ],
        [
            'key' => 'ownerId',
            'col' => 'owner_id',
            'label' => 'المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'يعمل',
                'صيانة',
                'متوقف',
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
        'provider',
        'ip',
        'os',
        'specs',
    ],
];

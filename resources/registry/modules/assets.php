<?php

/** سجلُّ الوحدات — «assets» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'assets',
    'table' => 'assets',
    'model' => 'Asset',
    'label' => 'الأصول والعهد',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'code',
        'name',
        'type',
        'holderId',
        'status',
        'warranty',
    ],
    'fields' => [
        [
            'key' => 'code',
            'col' => 'code',
            'label' => 'كود العهدة',
            'type' => 'text',
            // يولّده النظام من صنف الأصل وسنته وتسلسله (Asset::nextCode)
            // ويُطبَع على ملصق العهدة — فلا يُكتب يدوياً من CRUD ولا API:
            // كودٌ مُعدَّلٌ بيدٍ يفصل الملصقَ الملصوق على الجهاز عن سجلّه.
            'locked' => true,
        ],
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'الأصل',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'tag',
            'col' => 'tag',
            'label' => 'Asset Tag',
            'type' => 'text',
        ],
        [
            'key' => 'serial',
            'col' => 'serial',
            'label' => 'الرقم التسلسلي',
            'type' => 'text',
        ],
        [
            'key' => 'type',
            'col' => 'type',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'لابتوب',
                'هاتف',
                'سيرفر',
                'شاشة',
                'سويتش',
                'UPS',
                'طابعة',
                'أثاث',
                'سيارة',
                'رخصة برمجية',
                'أخرى',
            ],
        ],
        [
            // «تُدار لدينا» ≠ «ملكُنا»: سيرفرُ العميل الذي نديره يُسجَّل
            // ويُلصَق ويُصان ويُسند عهدةً — لكنه لا يدخل قيمةَ ممتلكاتنا
            'key' => 'owner',
            'col' => 'owner_scope',
            'label' => 'الملكية',
            'type' => 'sel',
            // «شخصي — BYOD» (التصحيح §2): جهازُ موظّفٍ شخصيٌّ لا تُسجَّل عليه
            // نقطةٌ طرفيّة (fail-closed). الباقي مُدارٌ للشركة ومؤهّلٌ للتسجيل.
            'options' => ['لينوميا', 'عميل — يُدار لدينا', 'مشترك', 'شخصي — BYOD'],
            'hint' => 'فارغةً تعني «لينوميا». أصلُ العميل يبقى بكل وظائف العهدة والصيانة والملصقات — ويخرج من تقارير ممتلكاتنا. «شخصي — BYOD» لا يُسجَّل نقطةً طرفيّة.',
        ],
        [
            'key' => 'clientId',
            'col' => 'client_id',
            'label' => 'العميل المالك',
            'type' => 'ref',
            'ref' => 'clients',
        ],
        [
            // القطعةُ تشير لطرازها: عشرون لابتوباً متطابقاً منتجٌ واحدٌ
            // في سجل المنتجات — لا عشرون اسماً يُكتب باليد
            'key' => 'productId',
            'col' => 'product_id',
            'label' => 'المنتج (الطراز)',
            'type' => 'ref',
            'ref' => 'products',
            'hint' => 'اربط القطعة بطرازها في سجل المنتجات — فيرث اسمَه ومواصفاتِه وباركودَه العالمي.',
        ],
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة',
            'type' => 'ref',
            'ref' => 'companies',
        ],
        [
            'key' => 'holderId',
            'col' => 'holder_id',
            'label' => 'المستلم',
            'type' => 'ref',
            'ref' => 'users',
            'locked' => true,   // يُكتب من دفتر العهدة (تسليم/استرداد) وحده — لا من النموذج العامّ بلا قيدٍ (ARCH-01, v2.399)
        ],
        [
            // (Work OS · الطور F · WP-F.2 · §30) المقعدُ الذي يعيش عليه الأصل —
            // **منفصلٌ عن holder_id**: أصلٌ يُسنَد لموظفٍ أو لمحطةٍ أو لكليهما (لا
            // إجبار). **مقفلٌ** يُكتَب عبر `Custody::assignStation` المقفلة المُدقَّقة
            // وحدَها لا من النموذج العامّ (نظيرُ holder_id · قاعدةُ الطور F الأمنيّة).
            'key' => 'stationId',
            'col' => 'station_id',
            'label' => 'المحطة',
            'type' => 'ref',
            'ref' => 'stations',
            'locked' => true,
        ],
        [
            'key' => 'loc',
            'col' => 'loc',
            'label' => 'الموقع / الراك',
            'type' => 'text',
        ],
        [
            'key' => 'vendor',
            'col' => 'vendor',
            'label' => 'المورد',
            'type' => 'text',
        ],
        [
            'key' => 'buyDate',
            'col' => 'buy_date',
            'label' => 'تاريخ الشراء',
            'type' => 'date',
        ],
        [
            'key' => 'price',
            'col' => 'price',
            'label' => 'السعر',
            'type' => 'num',
        ],
        [
            'key' => 'warranty',
            'col' => 'warranty',
            'label' => 'انتهاء الضمان',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            // (Work OS · الطور F · WP-F.2 · §29–31 · C11) دورةُ حياةٍ أغنى:
            // إحدى عشرة حالةً مصدرُها الوحيد `Custody::STATUSES` (الخمسُ القديمةُ
            // تبقى حرفاً — توافقٌ رجعيّ §86). و**مقفلةٌ**: تُكتَب عبر `Custody`
            // وحدَها (transition/move/permit) بانتقالٍ شرعيٍّ مُدقَّق — لا من هذا
            // النموذج ولا من سحب الكانبان (locked ⟵ hub_field_mode='ro'). العمود
            // string(80) واسعٌ أصلاً (لا ALTER · درسُ C10). NOTE: openapi يُعاد توليدُه.
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => \App\Support\Assets\Custody::STATUSES,
            'locked' => true,
        ],
        [
            'key' => 'maint',
            'col' => 'maint',
            'label' => 'آخر صيانة',
            'type' => 'date',
        ],
        [
            'key' => 'life',
            'col' => 'life',
            'label' => 'العمر المتوقع (سنة)',
            'type' => 'num',
        ],
        [
            'key' => 'parts',
            'col' => 'parts',
            'label' => 'قطع الغيار المتوفرة',
            'type' => 'ta',
        ],
        [
            'key' => 'disposal',
            'col' => 'disposal',
            'label' => 'تاريخ الاستبعاد',
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
        'code',
        'name',
        'tag',
        'serial',
        'loc',
        'vendor',
    ],
];

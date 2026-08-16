<?php

/**
 * **سجلُّ أصناف العهد** — الكود الأساسي لكل صنف، وقالبُ مواصفاته الداخلية.
 *
 * صنفٌ واحدٌ لا يشبه غيره فيما يُسأل عنه: السيرفرُ يُسأل عن معالجه وذاكرته
 * ورافه ومنافذه، والهاتفُ عن IMEI وخطّه، والسيارةُ عن لوحتها وشاصيها. وحشرُ
 * ذلك كلِّه أعمدةً في جدول الأصول يعني أربعين عموداً فارغاً في كل صف — فبقيت
 * هذه المواصفات تُكتب في «ملاحظات» نصّاً حرّاً لا يُقرأ ولا يُطبَع ولا يُبحث فيه.
 *
 * فهنا **قالبٌ لكل صنف**: مفاتيحُه تُخزَّن في `assets.specs` (JSON)، وتُعرض في
 * بطاقة العهدة، وتُطبع في ورقة المواصفات A5. وإضافةُ صنفٍ أو حقلٍ لاحقاً سطرٌ
 * هنا — لا هجرةَ ولا عمود.
 *
 * `code` بادئةُ الصنف في كود العهدة (`LYN-SV-2026-0001`) — **لا تُغيَّر بعد
 * الاستعمال**: أكوادُ الأصول القديمة مطبوعةٌ على ملصقاتٍ ملصوقة، وتغييرُ
 * البادئة يفصل المطبوعَ عن المسجَّل.
 * `ltr` حقلٌ قيمتُه لاتينيّةٌ دائماً (رقمُ IMEI، عنوانُ IP) فتُعرض من اليسار.
 */
return [

    // صيغةُ كود العهدة — تتجاوزها إعدادةُ `assets.code_format` من شاشة الإعدادات
    'code_format' => 'LYN-{CAT}-{YEAR}-{SEQ}',

    // بادئةُ الصنف حين لا يُختار صنفٌ أصلاً
    'fallback' => 'GN',

    'cats' => [
        'لابتوب' => [
            'code' => 'LT', 'icon' => '💻',
            'specs' => [
                ['key' => 'cpu', 'label' => 'المعالج', 'ltr' => true],
                ['key' => 'ram', 'label' => 'الذاكرة (RAM)', 'ltr' => true],
                ['key' => 'disk', 'label' => 'التخزين', 'ltr' => true],
                ['key' => 'gpu', 'label' => 'كرت الشاشة', 'ltr' => true],
                ['key' => 'screen', 'label' => 'الشاشة'],
                ['key' => 'os', 'label' => 'نظام التشغيل', 'ltr' => true],
                ['key' => 'mac', 'label' => 'عنوان MAC', 'ltr' => true],
                ['key' => 'hostname', 'label' => 'اسم الجهاز على الشبكة', 'ltr' => true],
            ],
        ],
        'هاتف' => [
            'code' => 'PH', 'icon' => '📱',
            'specs' => [
                ['key' => 'model', 'label' => 'الطراز', 'ltr' => true],
                ['key' => 'imei', 'label' => 'IMEI', 'ltr' => true],
                ['key' => 'storage', 'label' => 'سعة التخزين', 'ltr' => true],
                ['key' => 'ram', 'label' => 'الذاكرة (RAM)', 'ltr' => true],
                ['key' => 'line', 'label' => 'رقم الخط', 'ltr' => true],
                ['key' => 'carrier', 'label' => 'المشغّل'],
                ['key' => 'os', 'label' => 'نظام التشغيل', 'ltr' => true],
            ],
        ],
        'سيرفر' => [
            'code' => 'SV', 'icon' => '🖥️',
            'specs' => [
                ['key' => 'cpu', 'label' => 'المعالج', 'ltr' => true],
                ['key' => 'cores', 'label' => 'عدد الأنوية', 'ltr' => true],
                ['key' => 'ram', 'label' => 'الذاكرة (RAM)', 'ltr' => true],
                ['key' => 'disk', 'label' => 'الأقراص و RAID', 'ltr' => true],
                ['key' => 'nic', 'label' => 'منافذ الشبكة', 'ltr' => true],
                ['key' => 'os', 'label' => 'نظام التشغيل', 'ltr' => true],
                ['key' => 'ip', 'label' => 'عنوان IP', 'ltr' => true],
                ['key' => 'mgmt', 'label' => 'منفذ الإدارة (iDRAC/iLO)', 'ltr' => true],
                ['key' => 'rack', 'label' => 'الراك والوحدة', 'ltr' => true],
                ['key' => 'psu', 'label' => 'مزوّدات الطاقة'],
            ],
        ],
        'شاشة' => [
            'code' => 'SC', 'icon' => '🖵',
            'specs' => [
                ['key' => 'size', 'label' => 'المقاس', 'ltr' => true],
                ['key' => 'res', 'label' => 'الدقّة', 'ltr' => true],
                ['key' => 'ports', 'label' => 'المنافذ', 'ltr' => true],
            ],
        ],
        'سويتش' => [
            'code' => 'SW', 'icon' => '🔀',
            'specs' => [
                ['key' => 'ports', 'label' => 'عدد المنافذ', 'ltr' => true],
                ['key' => 'speed', 'label' => 'سرعة المنفذ', 'ltr' => true],
                ['key' => 'poe', 'label' => 'يدعم PoE'],
                ['key' => 'mgmt_ip', 'label' => 'عنوان الإدارة', 'ltr' => true],
                ['key' => 'firmware', 'label' => 'البرنامج الثابت', 'ltr' => true],
            ],
        ],
        'UPS' => [
            'code' => 'UPS', 'icon' => '🔋',
            'specs' => [
                ['key' => 'va', 'label' => 'القدرة (VA)', 'ltr' => true],
                ['key' => 'batteries', 'label' => 'عدد البطاريات', 'ltr' => true],
                ['key' => 'runtime', 'label' => 'زمن التحمّل'],
                ['key' => 'bat_change', 'label' => 'آخر تغيير بطارية'],
            ],
        ],
        'طابعة' => [
            'code' => 'PR', 'icon' => '🖨️',
            'specs' => [
                ['key' => 'model', 'label' => 'الطراز', 'ltr' => true],
                ['key' => 'tech', 'label' => 'نوع الطباعة'],
                ['key' => 'toner', 'label' => 'الحبر / التونر', 'ltr' => true],
                ['key' => 'ip', 'label' => 'عنوان IP', 'ltr' => true],
            ],
        ],
        'أثاث' => [
            'code' => 'FN', 'icon' => '🪑',
            'specs' => [
                ['key' => 'material', 'label' => 'المادة'],
                ['key' => 'size', 'label' => 'المقاس', 'ltr' => true],
                ['key' => 'color', 'label' => 'اللون'],
            ],
        ],
        'سيارة' => [
            'code' => 'CR', 'icon' => '🚗',
            'specs' => [
                ['key' => 'model', 'label' => 'الطراز'],
                ['key' => 'year', 'label' => 'سنة الصنع', 'ltr' => true],
                ['key' => 'plate', 'label' => 'رقم اللوحة', 'ltr' => true],
                ['key' => 'chassis', 'label' => 'رقم الشاصي', 'ltr' => true],
                ['key' => 'fuel', 'label' => 'نوع الوقود'],
                ['key' => 'insurance', 'label' => 'انتهاء التأمين'],
            ],
        ],
        'رخصة برمجية' => [
            'code' => 'LC', 'icon' => '🧾',
            'specs' => [
                ['key' => 'product', 'label' => 'المنتج'],
                ['key' => 'edition', 'label' => 'نوع الرخصة'],
                ['key' => 'seats', 'label' => 'عدد المقاعد', 'ltr' => true],
                ['key' => 'renew', 'label' => 'موعد التجديد'],
            ],
        ],
        'أخرى' => [
            'code' => 'GN', 'icon' => '📦',
            'specs' => [
                ['key' => 'model', 'label' => 'الطراز'],
                ['key' => 'detail', 'label' => 'وصفٌ فنّي'],
            ],
        ],
    ],
];

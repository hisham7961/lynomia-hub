<?php

/** سجلُّ الوحدات — «rules» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'rules',
    'table' => 'alert_rules',
    'model' => 'AlertRule',
    'label' => 'قواعد التنبيه',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'mod',
        'field',
        'op',
        'val',
        'status',
        
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم القاعدة',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'mod',
            'col' => 'mod',
            'label' => 'القسم',
            'type' => 'sel',
            'required' => true,
            // كل الوحدات: كانت القائمة مبتورة على ١٣ فلا قاعدة على المشاكل
            // ولا القرارات ولا الامتثال ولا الموافقات — والمحرك عام يقبلها كلها
            'options' => [
                'companies', 'projects', 'apps', 'code', 'websites', 'domains', 'servers',
                'accounts', 'emails', 'phones', 'vault', 'tasks', 'updates', 'issues',
                'files', 'subs', 'meetings', 'decisions', 'approvals', 'social', 'posts',
                'fin', 'accounts2', 'entries', 'clients', 'services', 'contracts', 'assets',
                'assetlog', 'stock', 'hr', 'leaves', 'banks', 'okrs', 'kb', 'autos', 'dbs',
                'apis', 'quotes', 'budgets', 'costc', 'recur', 'stockmv', 'attend', 'payroll',
                'recruit', 'hrlog', 'rules', 'feats', 'designs', 'tickets', 'suppliers',
                'purchases', 'changes', 'skills', 'policies', 'obligations', 'compliance',
                'ideas', 'policyacks', 'incidents', 'deploys', 'restores', 'requests', 'deps',
                'competitors', 'brands', 'media', 'ip', 'events', 'plans',
                // `krs` سقطت من القائمة فلم تُنشأ قاعدةُ تنبيهٍ واحدة على
                // النتائج الرئيسية — وهي أكثرُ ما يستحقّ إنذاراً بالتأخّر
                'krs', 'products', 'engagements',
                // العمليات الميدانية: قاعدةُ «أيام متبقية» على نهاية
                // الإسناد تنبّه قبل أن تبقى منطقةٌ بلا مندوب
                'hcps', 'facilities', 'territories', 'terrassigns', 'cycles', 'visits',
                // الأمن: قاعدةُ تنبيهٍ مجدولةٌ على المستخدمين (خاملٌ طويلاً،
                // بلا تحقّقٍ بخطوتين، موقوفٌ) — رصدٌ دوريّ لا يحتاج فتحَ المركز
                'users',
                // أوامرُ التغيير: تنبيهٌ على المعلَّق «قيد الاعتماد» طويلاً
                'changeorders',
                // (Work OS · الطور F · WP-F.1) المحطات: قاعدةُ تنبيهٍ على
                // الحالة/الإشغال (مقعدٌ في «صيانة» طويلاً مثلاً)
                'stations',
                // (Work OS · الطور G · WP-G.2) مزوّدو الاتصالات
                'carriers',
                // (Work OS · الطور J · WP-J.3) النقاط الطرفية: قاعدةُ
                // «أيام مضت أكثر من» على آخرِ نبضة تنبّه على الجهاز الصامت
                'endpoints',
            ],
        ],
        [
            'key' => 'field',
            'col' => 'field',
            'label' => 'الحقل',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'op',
            'col' => 'op',
            'label' => 'الشرط',
            'type' => 'sel',
            'required' => true,
            'options' => [
                'أكبر من',
                'أصغر من',
                'أكبر من عمود',
                'أصغر من عمود',
                'يساوي',
                'يحتوي',
                'أيام متبقية أقل من',
                'أيام مضت أكثر من',
                'فارغ',
            ],
        ],
        [
            'key' => 'val',
            'col' => 'val',
            'label' => 'القيمة',
            'type' => 'text',
        ],
        [
            'key' => 'msg',
            'col' => 'msg',
            'label' => 'نص التنبيه',
            'type' => 'text',
        ],
        [
            'key' => 'toId',
            'col' => 'to_id',
            'label' => 'يُرسل إلى',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'chan',
            'col' => 'chan',
            'label' => 'القنوات',
            'type' => 'sel',
            'options' => [
                'داخل النظام',
                'داخل النظام + تلجرام',
                'داخل النظام + بريد',
                'الكل',
            ],
        ],
        [
            'key' => 'every',
            'col' => 'every',
            'label' => 'تكرار التنبيه كل (يوم)',
            'type' => 'num',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مفعّلة',
                'متوقفة',
            ],
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'ملاحظات',
            'type' => 'ta',
        ],
        // ── Control Plane: Phase 6 (WP-6.3) — قواعدُ النافذة/الشدّة/التبريد ──
        // إضافةٌ لا كسر: قاعدةٌ قديمة بلا هذه الحقول تعمل حرفياً كما كانت.
        // قاعدةٌ لها «مصدرٌ مسمّى» تقيّمها سكّةُ hub:alerts-evaluate كلَّ ٥
        // دقائق (AlertEngine::evaluate) لا الدورةُ اليومية، وذاكرتُها
        // alert_instances. (بعد الدمج: يُعاد توليد docs/openapi.json)
        [
            'key' => 'severity',
            'col' => 'severity',
            'label' => 'الشدّة',
            'type' => 'sel',
            'options' => ['حرج', 'عالي', 'متوسط', 'منخفض'],
        ],
        [
            'key' => 'domain',
            'col' => 'domain',
            'label' => 'المجال',
            'type' => 'sel',
            'options' => ['module', 'security', 'system', 'error', 'quality', 'execution'],
        ],
        [
            'key' => 'source',
            'col' => 'source',
            'label' => 'المصدر المسمّى (للقواعد النافذية)',
            'type' => 'sel',
            'options' => ['security.failed_logins', 'security.denials', 'security.role_change',
                          'security.lockdown', 'errors.critical_count', 'health.scheduler'],
        ],
        [
            'key' => 'windowMin',
            'col' => 'window_min',
            'label' => 'نافذة التقييم (دقيقة)',
            'type' => 'num',
        ],
        [
            'key' => 'cooldownMin',
            'col' => 'cooldown_min',
            'label' => 'تبريد الإشعار (دقيقة)',
            'type' => 'num',
        ],
        [
            'key' => 'autoIncident',
            'col' => 'auto_incident',
            'label' => 'فتح حادثة آلياً عند الإطلاق',
            'type' => 'bool',
        ],
    ],
    'search' => [
        'name',
        'field',
        'val',
        'msg',
    ],
];

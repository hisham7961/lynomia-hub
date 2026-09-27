<?php

/** سجلُّ الوحدات — «quotes» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'quotes',
    'table' => 'quotes',
    'model' => 'Quote',
    'label' => 'عروض الأسعار',
    'display' => 'no',
    'status' => 'status',
    'columns' => [
        'no',
        'clientId',
        'total',
        'valid',
        'status',
    ],
    'fields' => [
        ['key' => 'serviceId', 'col' => 'service_id', 'label' => 'الخدمة المعروضة', 'type' => 'ref', 'ref' => 'services'],
        [
            'key' => 'no',
            'col' => 'doc_no',
            'label' => 'رقم العرض',
            'type' => 'text',
            'unique' => true,   // رقمُ عرضٍ مكرّر يُرفض بالرسالة (لا فهرسَ يسقط على بياناتٍ قائمة) — v2.399
            // يولّده النظام (QT-{سنة}-{تسلسل}) إن تُرك فارغاً — كنمط رقم العقد
            'hint' => 'يُترك فارغاً فيولّده النظام تلقائياً برقمٍ فريد.',
        ],
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان العرض/المشروع', 'type' => 'text',
         'hint' => 'مثل «تطوير متجر إلكتروني — الربع الثالث».'],
        // نوعُ العرض التجاريّ — يوجّه القالبَ والملخّصَ (لا يفرض كلَّ الحقول)
        ['key' => 'qtype', 'col' => 'qtype', 'label' => 'نوع العرض', 'type' => 'sel',
         'options' => ['بسيط', 'مشروع', 'خدمة مُدارة', 'احتفاظ', 'اشتراك', 'استشارة', 'مختلط'],
         'hint' => 'يصف طبيعةَ العرض التجاريّة — بسيطٌ (منتجات/خدمات) أو مشروعٌ بمراحل أو خدمةٌ دوريّة…'],
        [
            'key' => 'clientId',
            'col' => 'client_id',
            'label' => 'العميل',
            'type' => 'ref',
            'required' => true,
            'ref' => 'clients',
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
        [
            'key' => 'date',
            'col' => 'date',
            'label' => 'التاريخ',
            'type' => 'date',
        ],
        [
            'key' => 'valid',
            'col' => 'valid',
            'label' => 'صالح حتى',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            'key' => 'items',
            'col' => 'items',
            'label' => 'البنود (سطر لكل بند: وصف | كمية | سعر | وحدات الكرتونة اختياري)',
            'type' => 'ta',
            'hint' => 'أضف عموداً رابعاً «وحدات الكرتونة» لأي بند لحساب كراتينه، أو اضبط تعبئة المنتج مرةً في المخزون فتُحسب تلقائياً بمطابقة الاسم.',
        ],
        [
            'key' => 'amount',
            'col' => 'amount',
            'label' => 'المبلغ قبل الضريبة',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'tax',
            'col' => 'tax',
            'label' => 'الضريبة',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'total',
            'col' => 'total',
            'label' => 'الإجمالي',
            'type' => 'num',
            'money' => true,
            // يُحسَب خادمياً من البنود المهيكلة (recalc) — ويبقى قابلاً
            // للإدخال اليدويّ للعروض البسيطة القائمة على النصّ الحر.
            'hint' => 'يُحسَب تلقائياً من بنود العرض المهيكلة إن وُجدت.',
        ],
        [
            'key' => 'currency',
            'col' => 'currency',
            'label' => 'العملة',
            'type' => 'sel',
            'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو', 'KWD'],
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
                'مسودة',
                'مراجعة داخلية',
                'معتمد',
                'مُرسل',
                'قيد التفاوض',
                'اطُّلع عليه',
                'طُلب تعديل',
                'مقبول',
                'مرفوض',
                'منتهي',
                'محوّل',
                'ملغى',
            ],
        ],
        // نموذج الفوترة يُنقل للارتباط عند التحويل
        ['key' => 'billing', 'col' => 'billing', 'label' => 'نموذج الفوترة', 'type' => 'sel',
         'options' => ['سعر ثابت', 'بالساعة', 'عقد شهري', 'دفعات مراحل', 'اشتراك', 'تكلفة + هامش', 'حسب الاستخدام', 'أخرى']],
        ['key' => 'amId', 'col' => 'am_id', 'label' => 'مدير الحساب', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'pmId', 'col' => 'pm_id', 'label' => 'مدير التنفيذ', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'engagementId', 'col' => 'engagement_id', 'label' => 'الارتباط (يُملأ عند التحويل)', 'type' => 'ref', 'ref' => 'engagements'],
        ['key' => 'discount', 'col' => 'discount', 'label' => 'خصمٌ على مستوى العرض', 'type' => 'num', 'money' => true,],
        // **التكلفة التقديرية الداخلية — تُخفى عن العميل**: حقلٌ يُقيَّد بقواعد
        // الدور (hide) فلا يصل PDF العميل ولا عرضَه الخارجي. الهامشُ يُحسب منها.
        ['key' => 'cost', 'col' => 'cost', 'label' => 'التكلفة التقديرية (داخليّ)', 'type' => 'num', 'money' => true,
         'hint' => 'داخليٌّ بحت — لا يظهر للعميل. يُحسَب منه الهامشُ المتوقّع.'],
        // قالبٌ قابلٌ للاستنساخ: عرضٌ مُعلَّمٌ قالباً يُستنسخ عروضاً جديدة بلا إعادة إدخال
        ['key' => 'isTemplate', 'col' => 'is_template', 'label' => 'قالبٌ قابلٌ للاستنساخ', 'type' => 'bool',
         'hint' => 'العروضُ المُعلَّمةُ قوالبَ تُستنسَخ للمشاريع الجديدة بنقرة «استنساخ».'],
        ['key' => 'execSummary', 'col' => 'exec_summary', 'label' => 'الملخّص التنفيذي', 'type' => 'ta'],
        ['key' => 'objective', 'col' => 'objective', 'label' => 'هدف المشروع (للعميل)', 'type' => 'ta'],
        ['key' => 'scope', 'col' => 'scope', 'label' => 'نطاق العمل (للعميل)', 'type' => 'ta'],
        ['key' => 'assumptions', 'col' => 'assumptions', 'label' => 'الافتراضات', 'type' => 'ta'],
        ['key' => 'exclusions', 'col' => 'exclusions', 'label' => 'خارج النطاق', 'type' => 'ta'],
        [
            'key' => 'terms',
            'col' => 'terms',
            'label' => 'الشروط',
            'type' => 'ta',
        ],
        [
            'key' => 'att',
            'col' => 'att_id',
            'label' => 'مرفق',
            'type' => 'file',
        ],
    ],
    'search' => [
        'doc_no',
        'title',
        'items',
        'terms',
    ],
];

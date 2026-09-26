<?php

/** سجلُّ الوحدات — «services» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'services',
    'table' => 'services',
    'model' => 'Service',
    'label' => 'الخدمات والمنتجات',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'kind',
        'price',
        'cycle',
        'stage',
        'ownerId',
        'status',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم الخدمة',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'kind',
            'col' => 'kind',
            'label' => 'النوع',
            'type' => 'sel',
            'options' => [
                'اشتراك SaaS',
                'خدمة مرة واحدة',
                'دعم وصيانة',
                'منتج',
                'استشارة',
            ],
        ],
        [
            'key' => 'price',
            'col' => 'price',
            'label' => 'السعر',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'cost',
            'col' => 'cost',
            'label' => 'تكلفة التشغيل الشهرية',
            'type' => 'num',
            'money' => true,
        ],
        [
            'key' => 'cycle',
            'col' => 'cycle',
            'label' => 'دورة الفوترة',
            'type' => 'sel',
            'options' => [
                'شهري',
                'ربع سنوي',
                'سنوي',
                'مرة واحدة',
            ],
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع المرتبط',
            'type' => 'ref',
            'ref' => 'projects',
        ],
        [
            'key' => 'teamId',
            'col' => 'team_id',
            'label' => 'الفريق المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'serverId',
            'col' => 'server_id',
            'label' => 'السيرفر',
            'type' => 'ref',
            'ref' => 'servers',
        ],
        [
            'key' => 'domainId',
            'col' => 'domain_id',
            'label' => 'الدومين',
            'type' => 'ref',
            'ref' => 'domains',
        ],
        [
            'key' => 'sla',
            'col' => 'sla',
            'label' => 'SLA (ساعة استجابة)',
            'type' => 'num',
        ],
        [
            'key' => 'ver',
            'col' => 'ver',
            'label' => 'الإصدار الحالي',
            'type' => 'text',
        ],

        // ── مالكها وعلامتها ──
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'مالك الخدمة (المسؤول عنها)', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'brandId', 'col' => 'brand_id', 'label' => 'العلامة التجارية', 'type' => 'ref', 'ref' => 'brands'],

        // ── من ينافسها وما يطوّرها: كان الابتكار في وادٍ والخدمة في وادٍ ──
        ['key' => 'competitorIds', 'col' => 'competitor_ids', 'label' => 'من ينافسها', 'type' => 'ref', 'ref' => 'competitors', 'multi' => true],
        ['key' => 'ideaIds', 'col' => 'idea_ids', 'label' => 'أفكار تطوّرها', 'type' => 'ref', 'ref' => 'ideas', 'multi' => true],

        // ── تسعيرٌ يُدافَع عنه لا رقمٌ وحيد ──
        ['key' => 'unit', 'col' => 'unit', 'label' => 'وحدة التسعير', 'type' => 'sel',
         'options' => ['ثابت', 'لكل مستخدم', 'لكل جهاز', 'لكل ساعة', 'لكل مشروع', 'لكل وحدة', 'حسب الاستخدام']],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'text'],
        ['key' => 'minPrice', 'col' => 'min_price', 'label' => 'أدنى سعر يُقبل', 'type' => 'num', 'money' => true,],
        ['key' => 'setupFee', 'col' => 'setup_fee', 'label' => 'رسوم التهيئة (مرة واحدة)', 'type' => 'num', 'money' => true,],
        ['key' => 'priceReview', 'col' => 'price_review', 'label' => 'موعد مراجعة السعر', 'type' => 'date', 'expiry' => true],

        // ── ما الذي يُباع فعلاً ──
        ['key' => 'valueProp', 'col' => 'value_prop', 'label' => 'عرض القيمة — لماذا يشتريها العميل', 'type' => 'ta'],
        ['key' => 'audience', 'col' => 'audience', 'label' => 'الفئة المستهدفة', 'type' => 'ta'],
        ['key' => 'features', 'col' => 'features', 'label' => 'ما تشمله الخدمة', 'type' => 'ta'],
        ['key' => 'deliverables', 'col' => 'deliverables', 'label' => 'المخرجات التي يستلمها العميل', 'type' => 'ta'],
        ['key' => 'requirements', 'col' => 'requirements', 'label' => 'ما نحتاجه من العميل قبل البدء', 'type' => 'ta'],
        ['key' => 'leadTime', 'col' => 'lead_time', 'label' => 'مدة التسليم', 'type' => 'text'],
        ['key' => 'warranty', 'col' => 'warranty', 'label' => 'الضمان وما بعد البيع', 'type' => 'text'],
        ['key' => 'docsUrl', 'col' => 'docs_url', 'label' => 'رابط التوثيق', 'type' => 'url'],

        // ── دورة حياة الخدمة ──
        ['key' => 'stage', 'col' => 'stage', 'label' => 'مرحلة الخدمة', 'type' => 'sel',
         'options' => ['فكرة', 'قيد التطوير', 'تجريبية محدودة', 'مُطلقة', 'ناضجة', 'قيد الإيقاف', 'موقوفة']],
        ['key' => 'launchAt', 'col' => 'launch_at', 'label' => 'تاريخ الإطلاق', 'type' => 'date'],
        ['key' => 'retireAt', 'col' => 'retire_at', 'label' => 'تاريخ الإيقاف المخطَّط', 'type' => 'date', 'expiry' => true],

        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'نشطة',
                'تجريبية',
                'متوقفة',
                'قيد التطوير',
            ],
        ],
        [
            'key' => 'desc',
            'col' => 'description',
            'label' => 'الوصف',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'name',
        'ver',
        'description',
        'value_prop',
        'deliverables',
    ],
];

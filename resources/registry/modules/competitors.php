<?php

/** سجلُّ الوحدات — «competitors» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'competitors',
    'table' => 'competitors',
    'model' => 'Competitor',
    'label' => 'المنافسون',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'market', 'serviceId', 'threat', 'positioning', 'nextReview', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم المنافس', 'type' => 'text', 'required' => true],
        ['key' => 'website', 'col' => 'website', 'label' => 'الموقع الإلكتروني', 'type' => 'url'],
        ['key' => 'market', 'col' => 'market', 'label' => 'الدولة أو السوق', 'type' => 'text'],
        ['key' => 'products', 'col' => 'products', 'label' => 'منتجاته وخدماته', 'type' => 'ta'],
        ['key' => 'pricing', 'col' => 'pricing', 'label' => 'نموذج التسعير لديه', 'type' => 'ta'],
        ['key' => 'highlights', 'col' => 'highlights', 'label' => 'أبرز المميزات التي يسوّق بها', 'type' => 'ta'],
        ['key' => 'strengths', 'col' => 'strengths', 'label' => 'نقاط القوة', 'type' => 'ta'],
        ['key' => 'weaknesses', 'col' => 'weaknesses', 'label' => 'نقاط الضعف', 'type' => 'ta'],
        ['key' => 'comparison', 'col' => 'comparison', 'label' => 'مقارنة بمنتجاتنا', 'type' => 'ta'],
        ['key' => 'serviceId', 'col' => 'service_id', 'label' => 'الخدمة التي ينافسها لدينا', 'type' => 'ref', 'ref' => 'services'],
        ['key' => 'threat', 'col' => 'threat', 'label' => 'مستوى التهديد', 'type' => 'sel',
         'options' => ['مرتفع', 'متوسط', 'منخفض']],
        ['key' => 'lastSeen', 'col' => 'last_seen', 'label' => 'آخر رصد لنشاطه', 'type' => 'date'],
        ['key' => 'sources', 'col' => 'sources', 'label' => 'مصادر المتابعة والرصد', 'type' => 'ta'],
        ['key' => 'social', 'col' => 'social', 'label' => 'حسابات أخرى (نص حر)', 'type' => 'text'],

        // قناةٌ لكل منصة: خانةٌ واحدة كانت تُحشر فيها الروابط كلها فلا تُفتح ولا تُقارن
        ['key' => 'instagram', 'col' => 'instagram', 'label' => 'إنستغرام', 'type' => 'url'],
        ['key' => 'tiktok', 'col' => 'tiktok', 'label' => 'تيك توك', 'type' => 'url'],
        ['key' => 'x', 'col' => 'x_url', 'label' => 'إكس (تويتر)', 'type' => 'url'],
        ['key' => 'linkedin', 'col' => 'linkedin', 'label' => 'لينكدإن', 'type' => 'url'],
        ['key' => 'youtube', 'col' => 'youtube', 'label' => 'يوتيوب', 'type' => 'url'],
        ['key' => 'facebook', 'col' => 'facebook', 'label' => 'فيسبوك', 'type' => 'url'],
        ['key' => 'snapchat', 'col' => 'snapchat', 'label' => 'سناب شات', 'type' => 'url'],
        ['key' => 'followers', 'col' => 'followers', 'label' => 'إجمالي متابعيهم', 'type' => 'num'],

        // التسعير المُقارَن: «نموذج التسعير» نصاً لا يُقارَن برقمنا
        ['key' => 'priceFrom', 'col' => 'price_from', 'label' => 'أرخص باقة معلنة', 'type' => 'num', 'money' => true,],
        ['key' => 'priceTo', 'col' => 'price_to', 'label' => 'أغلى باقة معلنة', 'type' => 'num', 'money' => true,],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'text'],
        ['key' => 'billing', 'col' => 'billing', 'label' => 'دورة فوترتهم', 'type' => 'sel',
         'options' => ['شهري', 'سنوي', 'مرة واحدة', 'حسب الاستخدام', 'مختلط']],
        ['key' => 'freeTier', 'col' => 'free_tier', 'label' => 'لديهم باقة مجانية؟', 'type' => 'bool'],
        ['key' => 'trialDays', 'col' => 'trial_days', 'label' => 'أيام التجربة المجانية', 'type' => 'num'],

        // تطبيقاتهم: رقمٌ عام يُقارَن بأرقامنا في مركز التطبيق
        ['key' => 'play', 'col' => 'play', 'label' => 'تطبيقهم — Google Play', 'type' => 'url'],
        ['key' => 'appstore', 'col' => 'appstore', 'label' => 'تطبيقهم — App Store', 'type' => 'url'],
        ['key' => 'appDownloads', 'col' => 'app_downloads', 'label' => 'تحميلات تطبيقهم', 'type' => 'num'],
        ['key' => 'appRating', 'col' => 'app_rating', 'label' => 'تقييم تطبيقهم', 'type' => 'num'],

        // الشركة خلف المنتج
        ['key' => 'hq', 'col' => 'hq', 'label' => 'المقر الرئيسي', 'type' => 'text'],
        ['key' => 'founded', 'col' => 'founded', 'label' => 'سنة التأسيس', 'type' => 'text'],
        ['key' => 'size', 'col' => 'size', 'label' => 'حجم الفريق التقريبي', 'type' => 'sel',
         'options' => ['1–10', '11–50', '51–200', '201–1000', 'أكثر من 1000', 'غير معروف']],
        ['key' => 'funding', 'col' => 'funding', 'label' => 'التمويل المعلن', 'type' => 'text'],
        ['key' => 'positioning', 'col' => 'positioning', 'label' => 'موقعهم في السوق', 'type' => 'sel',
         'options' => ['الأرخص', 'الأفضل قيمةً', 'الأفخم', 'الأسرع', 'الأشمل', 'الأكثر تخصصاً']],
        ['key' => 'ourEdge', 'col' => 'our_edge', 'label' => 'ما نتفوّق به عليهم', 'type' => 'ta'],
        ['key' => 'theirEdge', 'col' => 'their_edge', 'label' => 'ما يتفوّقون به علينا', 'type' => 'ta'],

        // المراجعة الدورية: بلا موعدٍ يُنسى المنافس سنةً كاملة
        ['key' => 'nextReview', 'col' => 'next_review', 'label' => 'موعد المراجعة القادمة', 'type' => 'date', 'expiry' => true],
        ['key' => 'reviewCycle', 'col' => 'review_cycle', 'label' => 'دورية المراجعة', 'type' => 'sel',
         'options' => ['شهرية', 'ربع سنوية', 'نصف سنوية', 'سنوية']],

        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['نشط', 'يُراقب', 'خرج من السوق']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات وتحليل', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'market', 'products', 'comparison'],
];

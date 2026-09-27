<?php

/** سجلُّ الوحدات — «media» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'media',
    'table' => 'media_items',
    'model' => 'MediaItem',
    'label' => 'مركز الإعلام',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'type', 'outlet', 'date', 'impact', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان المادة الإعلامية', 'type' => 'text', 'required' => true],
        ['key' => 'type', 'col' => 'type', 'label' => 'نوع الظهور', 'type' => 'sel',
         'options' => ['خبر', 'مقابلة', 'مؤتمر', 'إعلان', 'فيديو', 'بيان صحفي', 'بودكاست', 'مقال']],
        ['key' => 'outlet', 'col' => 'outlet', 'label' => 'الجهة الناشرة أو المنصة', 'type' => 'text'],
        ['key' => 'date', 'col' => 'date', 'label' => 'تاريخ النشر', 'type' => 'date'],
        ['key' => 'url', 'col' => 'url', 'label' => 'رابط المادة', 'type' => 'url'],
        ['key' => 'brandId', 'col' => 'brand_id', 'label' => 'العلامة التجارية', 'type' => 'ref', 'ref' => 'brands'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'speakerId', 'col' => 'speaker_id', 'label' => 'المتحدث باسمنا', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'summary', 'col' => 'summary', 'label' => 'ملخص ما نُشر', 'type' => 'ta'],
        ['key' => 'lang', 'col' => 'lang', 'label' => 'اللغة', 'type' => 'sel',
         'options' => ['عربي', 'إنجليزي', 'ثنائي']],
        ['key' => 'reach', 'col' => 'reach', 'label' => 'الوصول التقديري (مشاهدات/قراء)', 'type' => 'num'],
        ['key' => 'impact', 'col' => 'impact', 'label' => 'أثر الظهور علينا', 'type' => 'sel',
         'options' => ['إيجابي', 'محايد', 'سلبي']],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق أو تسجيل', 'type' => 'file'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مخطط', 'منشور', 'مؤرشف']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات ومتابعة', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'outlet', 'summary', 'url'],
];

<?php

/** سجلُّ الوحدات — «events» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'events',
    'table' => 'events',
    'model' => 'Event',
    'label' => 'الأحداث والمعارض',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'type', 'dateStart', 'venue', 'ownerId', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'اسم الحدث', 'type' => 'text', 'required' => true],
        ['key' => 'type', 'col' => 'type', 'label' => 'نوع الحدث', 'type' => 'sel',
         'options' => ['معرض', 'مؤتمر', 'ندوة', 'رعاية', 'ورشة', 'إطلاق منتج']],
        ['key' => 'venue', 'col' => 'venue', 'label' => 'المكان', 'type' => 'text'],
        ['key' => 'dateStart', 'col' => 'date_start', 'label' => 'تاريخ البداية', 'type' => 'date'],
        ['key' => 'dateEnd', 'col' => 'date_end', 'label' => 'تاريخ النهاية', 'type' => 'date', 'expiry' => false],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'brandId', 'col' => 'brand_id', 'label' => 'العلامة التجارية', 'type' => 'ref', 'ref' => 'brands'],
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'المسؤول عن الحدث', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'team', 'col' => 'team', 'label' => 'فريق المشاركة', 'type' => 'ref', 'ref' => 'users', 'multi' => true],
        ['key' => 'budget', 'col' => 'budget', 'label' => 'الميزانية المعتمدة', 'type' => 'num', 'money' => true,],
        ['key' => 'actualCost', 'col' => 'actual_cost', 'label' => 'التكلفة الفعلية', 'type' => 'num', 'money' => true,],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'sel',
         'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو']],
        ['key' => 'goals', 'col' => 'goals', 'label' => 'أهداف المشاركة', 'type' => 'ta'],
        ['key' => 'results', 'col' => 'results', 'label' => 'النتائج المحققة', 'type' => 'ta'],
        ['key' => 'leads', 'col' => 'leads', 'label' => 'عدد العملاء المحتملين', 'type' => 'num'],
        ['key' => 'roi', 'col' => 'roi', 'label' => 'العائد التقديري', 'type' => 'num'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفقات (العقد، الصور، التقرير)', 'type' => 'file'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مخطط', 'مؤكد', 'جارٍ', 'منتهٍ', 'ملغى']],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'venue', 'goals', 'results'],
];

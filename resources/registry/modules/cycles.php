<?php

/** سجلُّ الوحدات — «cycles» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'cycles',
    'table' => 'cycles',
    'model' => 'Cycle',
    'label' => 'الدورات والحملات',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'type', 'territoryId', 'dateStart', 'dateEnd', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم الدورة', 'type' => 'text', 'required' => true,
         'hint' => 'مثل «حملة الربع الثالث — أدوية القلب» أو «دورة تغطية أبريل».'],
        ['key' => 'code', 'col' => 'code', 'label' => 'الرمز', 'type' => 'text'],
        ['key' => 'type', 'col' => 'type', 'label' => 'النوع', 'type' => 'sel',
         'options' => ['دورة تغطية', 'حملة منتج', 'إطلاق جديد', 'موسمية', 'أخرى']],
        ['key' => 'territoryId', 'col' => 'territory_id', 'label' => 'المنطقة', 'type' => 'ref', 'ref' => 'territories'],
        ['key' => 'productIds', 'col' => 'product_ids', 'label' => 'منتجات الحملة', 'type' => 'ref', 'ref' => 'products', 'multi' => true,
         'hint' => 'المنتجات التي تُعرَض في زيارات هذه الدورة — من سجل المنتجات القائم.'],
        ['key' => 'dateStart', 'col' => 'date_start', 'label' => 'من تاريخ', 'type' => 'date'],
        ['key' => 'dateEnd', 'col' => 'date_end', 'label' => 'إلى تاريخ', 'type' => 'date'],
        ['key' => 'targetVisits', 'col' => 'target_visits', 'label' => 'هدف عدد الزيارات', 'type' => 'num',
         'hint' => 'التغطيةُ الفعلية تُقاس من الزيارات التي تمّت — لا تُملأ يدوياً.'],
        ['key' => 'frequency', 'col' => 'frequency', 'label' => 'تكرار الزيارة لكل طبيب', 'type' => 'num'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مخطط', 'نشط', 'منتهٍ', 'ملغى']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'code', 'notes'],
];

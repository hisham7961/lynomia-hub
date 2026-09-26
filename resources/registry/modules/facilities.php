<?php

/** سجلُّ الوحدات — «facilities» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'facilities',
    'table' => 'facilities',
    'model' => 'Facility',
    'label' => 'المنشآت الصحية',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'type', 'city', 'territoryId', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم المنشأة', 'type' => 'text', 'required' => true],
        ['key' => 'type', 'col' => 'type', 'label' => 'النوع', 'type' => 'sel',
         'options' => ['مستشفى', 'مركز صحي', 'عيادة', 'مجمع عيادات', 'صيدلية', 'مستوصف', 'مختبر', 'أخرى']],
        ['key' => 'territoryId', 'col' => 'territory_id', 'label' => 'المنطقة', 'type' => 'ref', 'ref' => 'territories'],
        ['key' => 'city', 'col' => 'city', 'label' => 'المدينة', 'type' => 'text'],
        ['key' => 'area', 'col' => 'area', 'label' => 'المنطقة السكنية', 'type' => 'text'],
        ['key' => 'address', 'col' => 'address', 'label' => 'العنوان', 'type' => 'text'],
        ['key' => 'phone', 'col' => 'phone', 'label' => 'الهاتف', 'type' => 'text'],
        ['key' => 'lat', 'col' => 'lat', 'label' => 'خط العرض (Lat)', 'type' => 'num',
         'hint' => 'من خرائط الهاتف: ضغطة مطوّلة على المنشأة تعطي الإحداثيتين.'],
        ['key' => 'lng', 'col' => 'lng', 'label' => 'خط الطول (Lng)', 'type' => 'num'],
        ['key' => 'radiusM', 'col' => 'radius_m', 'label' => 'نطاق الوصول (متر)', 'type' => 'num',
         'hint' => 'زيارةٌ داخل هذا النطاق تُحسب «في المنشأة». مستشفى كبير ٣٠٠م، عيادة ١٠٠م.'],
        ['key' => 'clientId', 'col' => 'client_id', 'label' => 'العميل (إن كانت المنشأة عميلاً)', 'type' => 'ref', 'ref' => 'clients'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['نشطة', 'مغلقة مؤقتاً', 'مغلقة نهائياً']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'city', 'area', 'address'],
];

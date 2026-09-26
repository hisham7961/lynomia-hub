<?php

/** سجلُّ الوحدات — «territories» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'territories',
    'table' => 'territories',
    'model' => 'Territory',
    'label' => 'المناطق الميدانية',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'kind', 'parentId', 'managerId', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم المنطقة', 'type' => 'text', 'required' => true],
        ['key' => 'code', 'col' => 'code', 'label' => 'الرمز', 'type' => 'text'],
        // أول مرجعٍ ذاتي في السجل — النموذج يقطع الدور (cycle) عند الحفظ
        ['key' => 'parentId', 'col' => 'parent_id', 'label' => 'المنطقة الأم', 'type' => 'ref', 'ref' => 'territories',
         'hint' => 'تُبنى الهرمية من الأعلى: بلد ← محافظة ← منطقة ← قطاع. المناطق التابعة تظهر في «السجلات المرتبطة».'],
        ['key' => 'kind', 'col' => 'kind', 'label' => 'المستوى', 'type' => 'sel',
         'options' => ['بلد', 'محافظة', 'منطقة', 'قطاع']],
        ['key' => 'managerId', 'col' => 'manager_id', 'label' => 'مشرف المنطقة', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['نشطة', 'موقوفة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'code'],
];

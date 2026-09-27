<?php

/** سجلُّ الوحدات — «skills» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'skills',
    'table' => 'skills',
    'model' => 'Skill',
    'label' => 'المهارات والشهادات',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'empId', 'level', 'gap', 'certExp', 'status'],
    'fields' => [
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'name', 'col' => 'name', 'label' => 'المهارة', 'type' => 'text', 'required' => true],
        ['key' => 'empId', 'col' => 'emp_id', 'label' => 'الموظف', 'type' => 'ref', 'required' => true, 'ref' => 'hr'],
        ['key' => 'cat', 'col' => 'cat', 'label' => 'تصنيف المهارة', 'type' => 'sel',
         'options' => ['تقنية', 'إدارية', 'لغة', 'تصميم', 'تسويق', 'مالية']],
        ['key' => 'level', 'col' => 'level', 'label' => 'المستوى الحالي', 'type' => 'sel',
         'options' => ['مبتدئ', 'متوسط', 'متقدم', 'خبير']],
        ['key' => 'reqLevel', 'col' => 'req_level', 'label' => 'المستوى المطلوب للدور', 'type' => 'sel',
         'options' => ['مبتدئ', 'متوسط', 'متقدم', 'خبير']],
        ['key' => 'gap', 'col' => 'gap', 'label' => 'الفجوة عن المطلوب', 'type' => 'sel',
         'options' => ['لا فجوة', 'بسيطة', 'كبيرة']],
        ['key' => 'cert', 'col' => 'cert', 'label' => 'اسم الشهادة', 'type' => 'text'],
        ['key' => 'certBody', 'col' => 'cert_body', 'label' => 'الجهة المانحة للشهادة', 'type' => 'text'],
        ['key' => 'certDate', 'col' => 'cert_date', 'label' => 'تاريخ إصدار الشهادة', 'type' => 'date'],
        ['key' => 'certExp', 'col' => 'cert_exp', 'label' => 'تاريخ انتهاء الشهادة', 'type' => 'date'],
        ['key' => 'trainPlan', 'col' => 'train_plan', 'label' => 'خطة التدريب لسد الفجوة', 'type' => 'ta'],
        ['key' => 'reviewAt', 'col' => 'review_at', 'label' => 'موعد إعادة التقييم', 'type' => 'date'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق الشهادة', 'type' => 'file'],
        ['key' => 'status', 'col' => 'status', 'label' => 'حالة المهارة', 'type' => 'sel',
         'options' => ['مؤكدة', 'قيد التطوير', 'مطلوبة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات التقييم', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'cert', 'cert_body', 'train_plan', 'notes'],
];

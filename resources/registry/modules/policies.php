<?php

/** سجلُّ الوحدات — «policies» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'policies',
    'table' => 'policies',
    'model' => 'Policy',
    'label' => 'السياسات والإقرارات',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'cat', 'ver', 'effDate', 'reviewDate', 'status'],
    'fields' => [
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان السياسة', 'type' => 'text', 'required' => true],
        ['key' => 'cat', 'col' => 'cat', 'label' => 'تصنيف السياسة', 'type' => 'sel',
         'options' => ['أمن معلومات', 'استخدام الأجهزة', 'إجازات', 'مصروفات', 'كلمات المرور', 'بيانات العملاء', 'سلوك مهني', 'أخرى']],
        ['key' => 'ver', 'col' => 'ver', 'label' => 'رقم النسخة (الإقرارات مرتبطة بها)', 'type' => 'text', 'required' => true],
        ['key' => 'body', 'col' => 'body', 'label' => 'نص السياسة', 'type' => 'ta', 'required' => true],
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'مالك السياسة', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'effDate', 'col' => 'eff_date', 'label' => 'تاريخ السريان', 'type' => 'date'],
        ['key' => 'reviewDate', 'col' => 'review_date', 'label' => 'تاريخ المراجعة القادمة', 'type' => 'date'],
        ['key' => 'ackRequired', 'col' => 'ack_required', 'label' => 'إقرار الموظفين إلزامي', 'type' => 'bool'],
        ['key' => 'roles', 'col' => 'roles', 'label' => 'الأدوار/الأقسام المشمولة', 'type' => 'text'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق السياسة', 'type' => 'file'],
        ['key' => 'status', 'col' => 'status', 'label' => 'حالة السياسة', 'type' => 'sel',
         'options' => ['مسودة', 'قيد الاعتماد', 'سارية', 'مؤرشفة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات ومبررات التعديل', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'body', 'roles', 'notes'],
];

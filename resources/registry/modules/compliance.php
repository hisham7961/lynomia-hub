<?php

/** سجلُّ الوحدات — «compliance» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'compliance',
    'table' => 'compliance_items',
    'model' => 'ComplianceItem',
    'label' => 'سجل الامتثال',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'kind', 'authority', 'due', 'status', 'ownerId'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'الالتزام', 'type' => 'text', 'required' => true],
        ['key' => 'kind', 'col' => 'kind', 'label' => 'النوع', 'type' => 'sel',
         'options' => ['ترخيص تجاري', 'ضريبة', 'تأمينات اجتماعية', 'حماية بيانات', 'اشتراطات بلدية', 'عقد التزام', 'شهادة مهنية', 'أخرى']],
        ['key' => 'authority', 'col' => 'authority', 'label' => 'الجهة المُلزِمة', 'type' => 'text'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة المعنية', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'refNo', 'col' => 'ref_no', 'label' => 'رقم الترخيص / المرجع', 'type' => 'text'],
        ['key' => 'due', 'col' => 'due', 'label' => 'الاستحقاق / التجديد القادم', 'type' => 'date'],
        ['key' => 'renewedAt', 'col' => 'renewed_at', 'label' => 'آخر تجديد', 'type' => 'date'],
        ['key' => 'fee', 'col' => 'fee', 'label' => 'رسوم التجديد', 'type' => 'num'],
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'المسؤول عن الالتزام', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'req', 'col' => 'req', 'label' => 'المتطلبات والمستندات اللازمة', 'type' => 'ta'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'دليل الامتثال (شهادة/إيصال)', 'type' => 'file'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['ملتزم', 'مطلوب إجراء', 'متأخر', 'قيد التجديد', 'معفى']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'authority', 'ref_no', 'req', 'notes'],
];

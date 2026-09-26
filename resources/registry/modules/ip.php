<?php

/** سجلُّ الوحدات — «ip» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'ip',
    'table' => 'ip_assets',
    'model' => 'IpAsset',
    'label' => 'الملكية الفكرية',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'type', 'companyId', 'regNo', 'expiry', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان الأصل الفكري', 'type' => 'text', 'required' => true],
        ['key' => 'type', 'col' => 'type', 'label' => 'نوع الأصل', 'type' => 'sel',
         'options' => ['براءة اختراع', 'علامة تجارية', 'حق نشر', 'كود مصدري', 'تصميم', 'فكرة مسجلة', 'ترخيص', 'سر تجاري']],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة المالكة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'regNo', 'col' => 'reg_no', 'label' => 'رقم التسجيل', 'type' => 'text'],
        ['key' => 'authority', 'col' => 'authority', 'label' => 'جهة التسجيل', 'type' => 'text'],
        ['key' => 'country', 'col' => 'country', 'label' => 'دولة التسجيل', 'type' => 'text'],
        ['key' => 'regDate', 'col' => 'reg_date', 'label' => 'تاريخ التسجيل', 'type' => 'date'],
        ['key' => 'expiry', 'col' => 'expiry', 'label' => 'تاريخ الانتهاء (تنبيه التجديد)', 'type' => 'date'],
        ['key' => 'autoRenew', 'col' => 'auto_renew', 'label' => 'التجديد التلقائي', 'type' => 'bool'],
        ['key' => 'authorId', 'col' => 'author_id', 'label' => 'المخترع أو المؤلف', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع المرتبط', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'descr', 'col' => 'descr', 'label' => 'الوصف ونطاق الحماية', 'type' => 'ta'],
        ['key' => 'cost', 'col' => 'cost', 'label' => 'تكلفة التسجيل', 'type' => 'num', 'money' => true,],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'sel',
         'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو']],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'المستندات (شهادة التسجيل)', 'type' => 'file'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مسجلة', 'قيد التسجيل', 'منتهية', 'متنازع عليها', 'مرفوضة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
    ],
    'search' => ['title', 'reg_no', 'authority', 'descr', 'notes'],
];

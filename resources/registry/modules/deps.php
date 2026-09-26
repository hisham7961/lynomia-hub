<?php

/** سجلُّ الوحدات — «deps» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'deps',
    'table' => 'dependencies',
    'model' => 'Dependency',
    'label' => 'سجل الاعتماديات',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'srcType', 'depType', 'depName', 'criticality', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'وصف الاعتمادية', 'type' => 'text', 'required' => true],
        ['key' => 'srcType', 'col' => 'src_type', 'label' => 'العنصر المعتمِد — نوعه', 'type' => 'sel',
         'options' => ['مشروع', 'تطبيق', 'موقع', 'سيرفر', 'خدمة']],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'appId', 'col' => 'app_id', 'label' => 'التطبيق', 'type' => 'ref', 'ref' => 'apps'],
        ['key' => 'serverId', 'col' => 'server_id', 'label' => 'السيرفر', 'type' => 'ref', 'ref' => 'servers'],
        ['key' => 'depType', 'col' => 'dep_type', 'label' => 'يعتمد على — النوع', 'type' => 'sel',
         'options' => ['API خارجي', 'سيرفر', 'قاعدة بيانات', 'خدمة سحابية', 'مورد خارجي', 'موظف', 'ترخيص', 'دومين']],
        ['key' => 'depName', 'col' => 'dep_name', 'label' => 'اسم العنصر المعتمَد عليه', 'type' => 'text', 'required' => true],
        ['key' => 'supplierId', 'col' => 'supplier_id', 'label' => 'المورد المسؤول', 'type' => 'ref', 'ref' => 'suppliers'],
        ['key' => 'apiId', 'col' => 'api_id', 'label' => 'الـ API المرتبط', 'type' => 'ref', 'ref' => 'apis'],
        ['key' => 'empId', 'col' => 'emp_id', 'label' => 'الموظف المعني', 'type' => 'ref', 'ref' => 'hr'],
        ['key' => 'criticality', 'col' => 'criticality', 'label' => 'درجة الحرجية', 'type' => 'sel',
         'options' => ['حرجة', 'عالية', 'متوسطة', 'منخفضة']],
        ['key' => 'impact', 'col' => 'impact', 'label' => 'ما الذي يتعطل عند فشله', 'type' => 'ta', 'required' => true],
        ['key' => 'fallback', 'col' => 'fallback', 'label' => 'البديل أو خطة الطوارئ', 'type' => 'ta'],
        ['key' => 'rtoHours', 'col' => 'rto_hours', 'label' => 'وقت التعافي المقبول (ساعات)', 'type' => 'num'],
        ['key' => 'reviewedAt', 'col' => 'reviewed_at', 'label' => 'آخر مراجعة', 'type' => 'date'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['نشطة', 'تحت المراجعة', 'مُستبدَلة', 'ملغاة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'dep_name', 'impact', 'notes'],
];

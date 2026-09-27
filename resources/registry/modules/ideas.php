<?php

/** سجلُّ الوحدات — «ideas» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'ideas',
    'table' => 'ideas',
    'model' => 'Idea',
    'label' => 'مركز الابتكار',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'cat', 'impact', 'confidence', 'ease', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان الفكرة', 'type' => 'text', 'required' => true],
        ['key' => 'cat', 'col' => 'cat', 'label' => 'المجال', 'type' => 'sel',
         'options' => ['منتج', 'عملية داخلية', 'تسويق', 'خدمة عملاء', 'تقنية', 'توفير تكلفة', 'أخرى']],
        ['key' => 'problem', 'col' => 'problem', 'label' => 'المشكلة التي تحلها', 'type' => 'ta'],
        ['key' => 'idea', 'col' => 'idea', 'label' => 'وصف الفكرة', 'type' => 'ta'],
        ['key' => 'impact', 'col' => 'impact', 'label' => 'الأثر (١-١٠)', 'type' => 'num'],
        ['key' => 'confidence', 'col' => 'confidence', 'label' => 'الثقة (١-١٠)', 'type' => 'num'],
        ['key' => 'ease', 'col' => 'ease', 'label' => 'سهولة التنفيذ (١-١٠)', 'type' => 'num'],
        ['key' => 'byId', 'col' => 'by_id', 'label' => 'صاحب الفكرة', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع الناتج', 'type' => 'ref', 'ref' => 'projects'],
        // الفكرة تخدم خدمةً بعينها غالباً — بلا هذا الربط يبقى الابتكار معزولاً
        ['key' => 'serviceId', 'col' => 'service_id', 'label' => 'الخدمة التي تطوّرها', 'type' => 'ref', 'ref' => 'services'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['جديدة', 'قيد التقييم', 'معتمدة', 'قيد التنفيذ', 'مؤجلة', 'مرفوضة', 'مُنجزة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات التقييم', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'problem', 'idea', 'notes'],
];

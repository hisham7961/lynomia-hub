<?php

/** سجلُّ الوحدات — «changes» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'changes',
    'table' => 'changes',
    'model' => 'Change',
    'label' => 'التغييرات التقنية',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'target', 'risk', 'window', 'ownerId', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'وصف التغيير', 'type' => 'text', 'required' => true],
        ['key' => 'target', 'col' => 'target', 'label' => 'نوع الهدف', 'type' => 'sel',
         'options' => ['سيرفر', 'تطبيق', 'قاعدة بيانات', 'دومين', 'DNS', 'API', 'صلاحيات وحسابات', 'بيئة الإنتاج', 'شبكة', 'أخرى']],
        ['key' => 'serverId', 'col' => 'server_id', 'label' => 'السيرفر المعني', 'type' => 'ref', 'ref' => 'servers'],
        ['key' => 'appId', 'col' => 'app_id', 'label' => 'التطبيق المعني', 'type' => 'ref', 'ref' => 'apps'],
        ['key' => 'domainId', 'col' => 'domain_id', 'label' => 'الدومين المعني', 'type' => 'ref', 'ref' => 'domains'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'taskId', 'col' => 'task_id', 'label' => 'المهمة المنفَّذة (خيط التتبع)', 'type' => 'ref', 'ref' => 'tasks'],
        ['key' => 'risk', 'col' => 'risk', 'label' => 'درجة المخاطرة', 'type' => 'sel',
         'options' => ['منخفضة', 'متوسطة', 'عالية', 'حرجة']],
        ['key' => 'plan', 'col' => 'plan', 'label' => 'خطة التنفيذ (خطوات مرقمة)', 'type' => 'ta'],
        ['key' => 'rollback', 'col' => 'rollback', 'label' => 'خطة التراجع إن فشل', 'type' => 'ta'],
        ['key' => 'window', 'col' => 'window', 'label' => 'موعد التنفيذ المخطط', 'type' => 'dt'],
        ['key' => 'doneAt', 'col' => 'done_at', 'label' => 'وقت التنفيذ الفعلي', 'type' => 'dt'],
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'المنفذ المسؤول', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مقترح', 'بانتظار الاعتماد', 'معتمد', 'مجدول', 'قيد التنفيذ', 'منفّذ', 'متراجع عنه', 'ملغى']],
        ['key' => 'result', 'col' => 'result', 'label' => 'النتيجة', 'type' => 'sel',
         'options' => ['ناجح', 'ناجح مع ملاحظات', 'فشل وتراجعنا']],
        ['key' => 'affected', 'col' => 'affected', 'label' => 'الأنظمة/الخدمات المتأثرة', 'type' => 'text'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق', 'type' => 'file'],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات وما تعلمناه', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'affected', 'plan', 'notes'],
];

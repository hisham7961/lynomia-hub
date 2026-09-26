<?php

/** سجلُّ الوحدات — «deploys» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'deploys',
    'table' => 'deployments',
    'model' => 'Deployment',
    'label' => 'سجل النشر والإصدارات',
    'display' => 'ver',
    'status' => 'status',
    'columns' => ['ver', 'appId', 'env', 'deployedAt', 'status', 'byId'],
    'fields' => [
        ['key' => 'ver', 'col' => 'ver', 'label' => 'رقم النسخة', 'type' => 'text', 'required' => true],
        ['key' => 'appId', 'col' => 'app_id', 'label' => 'التطبيق', 'type' => 'ref', 'ref' => 'apps'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'env', 'col' => 'env', 'label' => 'البيئة', 'type' => 'sel',
         'options' => ['إنتاج', 'تجريبي', 'تطوير']],
        ['key' => 'deployedAt', 'col' => 'deployed_at', 'label' => 'وقت النشر', 'type' => 'dt'],
        ['key' => 'byId', 'col' => 'by_id', 'label' => 'من نفّذ النشر', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'changesLog', 'col' => 'changes_log', 'label' => 'التغييرات في هذا الإصدار', 'type' => 'ta'],
        ['key' => 'commit', 'col' => 'commit', 'label' => 'Git commit', 'type' => 'text'],
        ['key' => 'migrations', 'col' => 'migrations', 'label' => 'حالة هجرات قاعدة البيانات', 'type' => 'sel',
         'options' => ['لا تحتاج', 'نُفذت', 'فشلت']],
        ['key' => 'tests', 'col' => 'tests', 'label' => 'نتيجة الاختبارات', 'type' => 'sel',
         'options' => ['نجحت', 'فشلت جزئياً', 'لم تُشغّل']],
        ['key' => 'status', 'col' => 'status', 'label' => 'النتيجة', 'type' => 'sel',
         'options' => ['ناجح', 'ناجح بملاحظات', 'فشل', 'متراجع عنه']],
        ['key' => 'rollbackable', 'col' => 'rollbackable', 'label' => 'يمكن التراجع عنه', 'type' => 'bool'],
        ['key' => 'durationMin', 'col' => 'duration_min', 'label' => 'مدة النشر (دقيقة)', 'type' => 'num'],
        ['key' => 'changeId', 'col' => 'change_id', 'label' => 'التغيير التقني المرتبط', 'type' => 'ref', 'ref' => 'changes'],
        ['key' => 'releaseId', 'col' => 'release_id', 'label' => 'الإصدار المنشور (خيط التتبع)', 'type' => 'ref', 'ref' => 'code'],
        ['key' => 'incidentId', 'col' => 'incident_id', 'label' => 'الحادث المرتبط', 'type' => 'ref', 'ref' => 'incidents'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق (سجل النشر)', 'type' => 'file'],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['ver', 'commit', 'changes_log', 'notes'],
];

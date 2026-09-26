<?php

/** سجلُّ الوحدات — «incidents» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'incidents',
    'table' => 'incidents',
    'model' => 'Incident',
    'label' => 'إدارة الحوادث التقنية',
    'display' => 'title',
    'status' => 'status',
    // ── Control Plane: Phase 6 (WP-6.1) — بوّابة الإغلاق (§8.5) ──
    // حالةٌ تشترط حقولاً قبل بلوغها (بجوار نمط status_via_action): «مغلق
    // بتقرير» لحادثةٍ حرجة/عالية تتطلب ثلاثيّةَ التقرير. يقرؤها المحرّك
    // الواحد (ModuleController::guardStatusRequires) في السحب والتحديث
    // والـAPI والجماعي — لا فرعَ لكل وحدةٍ في متحكّم.
    'requires' => [
        'مغلق بتقرير' => [
            'when'   => ['severity' => ['حرج', 'عالي']],
            'fields' => ['rootCause', 'steps', 'prevention'],
            'why'    => 'حادثةٌ حرجة/عالية لا تُغلق بلا تقرير: السبب الجذري وخطوات المعالجة والإجراءات الوقائية أولاً',
        ],
    ],
    'columns' => ['title', 'severity', 'startedAt', 'downtimeMin', 'leadId', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان الحادث', 'type' => 'text', 'required' => true],
        ['key' => 'severity', 'col' => 'severity', 'label' => 'مستوى الحادث', 'type' => 'sel',
         'options' => ['حرج', 'عالي', 'متوسط', 'منخفض']],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مفتوح', 'قيد المعالجة', 'مُحتوى', 'مُستعاد', 'مغلق بتقرير']],
        ['key' => 'startedAt', 'col' => 'started_at', 'label' => 'وقت بداية الحادث', 'type' => 'dt'],
        // (WP-6.1) متى عُلم بالحادثة — فجوةُ الكشف (كُشفت - بدأت) تُقرأ في الرأس
        ['key' => 'detectedAt', 'col' => 'detected_at', 'label' => 'وقت اكتشاف الحادث', 'type' => 'dt'],
        ['key' => 'resolvedAt', 'col' => 'resolved_at', 'label' => 'وقت استعادة الخدمة', 'type' => 'dt'],
        ['key' => 'downtimeMin', 'col' => 'downtime_min', 'label' => 'مدة التعطل (دقيقة)', 'type' => 'num'],
        ['key' => 'affected', 'col' => 'affected', 'label' => 'الأنظمة والخدمات المتأثرة', 'type' => 'text'],
        ['key' => 'appId', 'col' => 'app_id', 'label' => 'التطبيق المتأثر', 'type' => 'ref', 'ref' => 'apps'],
        ['key' => 'serverId', 'col' => 'server_id', 'label' => 'السيرفر المتأثر', 'type' => 'ref', 'ref' => 'servers'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'clientsHit', 'col' => 'clients_hit', 'label' => 'العملاء المتأثرون وحجم الأثر', 'type' => 'ta'],
        ['key' => 'leadId', 'col' => 'lead_id', 'label' => 'قائد الحادث', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'parts', 'col' => 'parts', 'label' => 'المشاركون في المعالجة', 'type' => 'ref', 'ref' => 'users', 'multi' => true],
        ['key' => 'steps', 'col' => 'steps', 'label' => 'خطوات المعالجة (بالترتيب الزمني)', 'type' => 'ta'],
        ['key' => 'rootCause', 'col' => 'root_cause', 'label' => 'السبب الجذري', 'type' => 'ta'],
        ['key' => 'lessons', 'col' => 'lessons', 'label' => 'الدروس المستفادة', 'type' => 'ta'],
        ['key' => 'prevention', 'col' => 'prevention', 'label' => 'الإجراءات الوقائية لمنع التكرار', 'type' => 'ta'],
        ['key' => 'postmortem', 'col' => 'postmortem', 'label' => 'كُتب تقرير Postmortem', 'type' => 'bool'],
        ['key' => 'reviewDate', 'col' => 'review_date', 'label' => 'تاريخ مراجعة تنفيذ الوقاية', 'type' => 'date', 'expiry' => true],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق (لقطات/سجلات/تقرير)', 'type' => 'file'],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'affected', 'root_cause', 'steps', 'notes'],
];

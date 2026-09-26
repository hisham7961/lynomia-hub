<?php

/** سجلُّ الوحدات — «policyacks» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'policyacks',
    'table' => 'policy_acks',
    'model' => 'PolicyAck',
    'label' => 'إقرارات السياسات',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'policyId', 'userId', 'ver', 'ackAt', 'status'],
    'fields' => [
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'title', 'col' => 'title', 'label' => 'وصف مختصر (السياسة — الموظف)', 'type' => 'text'],
        ['key' => 'policyId', 'col' => 'policy_id', 'label' => 'السياسة', 'type' => 'ref', 'required' => true, 'ref' => 'policies'],
        ['key' => 'userId', 'col' => 'user_id', 'label' => 'الموظف المُقِر', 'type' => 'ref', 'required' => true, 'ref' => 'users'],
        ['key' => 'ver', 'col' => 'ver', 'label' => 'النسخة المُقرّة', 'type' => 'text'],
        ['key' => 'ackAt', 'col' => 'ack_at', 'label' => 'وقت الإقرار', 'type' => 'dt'],
        ['key' => 'ip', 'col' => 'ip', 'label' => 'عنوان IP وقت الإقرار', 'type' => 'text'],
        ['key' => 'device', 'col' => 'device', 'label' => 'الجهاز/المتصفح', 'type' => 'text'],
        ['key' => 'status', 'col' => 'status', 'label' => 'حالة الإقرار', 'type' => 'sel',
         'options' => ['بانتظار الإقرار', 'مُقرّة', 'منتهية بتحديث النسخة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
    ],
    'search' => ['title', 'ver', 'ip', 'device', 'notes'],
];

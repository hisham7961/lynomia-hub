<?php

/** سجلُّ الوحدات — «krs» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'krs',
    'table' => 'key_results',
    'model' => 'KeyResult',
    'label' => 'النتائج الرئيسية (KR)',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'objectiveId', 'currentValue', 'targetValue', 'source', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'النتيجة الرئيسية', 'type' => 'text', 'required' => true],
        ['key' => 'objectiveId', 'col' => 'objective_id', 'label' => 'الهدف', 'type' => 'ref', 'required' => true, 'ref' => 'okrs'],
        // ── من أين تأتي القيمة: مصدرٌ يُختار لا معرّفٌ يُلصق ──
        ['key' => 'source', 'col' => 'source', 'label' => 'مصدر القياس', 'type' => 'sel',
         'options' => ['manual', 'count', 'sum', 'avg', 'kpi', 'metric']],
        ['key' => 'srcModule', 'col' => 'src_module', 'label' => 'الوحدة المقيسة (مفتاحها)', 'type' => 'text'],
        ['key' => 'srcCol', 'col' => 'src_col', 'label' => 'العمود (للمجموع/المتوسط)', 'type' => 'text'],
        ['key' => 'srcStatus', 'col' => 'src_status', 'label' => 'تضييق بحالة السجل', 'type' => 'text'],
        ['key' => 'srcRecord', 'col' => 'src_record', 'label' => 'السجل (للمقياس الزمني)', 'type' => 'text'],
        ['key' => 'srcMetric', 'col' => 'src_metric', 'label' => 'اسم المقياس الزمني', 'type' => 'text'],
        ['key' => 'kpiId', 'col' => 'kpi_id', 'label' => 'مؤشر KPI المرتبط', 'type' => 'text'],
        ['key' => 'startValue', 'col' => 'start_value', 'label' => 'قيمة البداية', 'type' => 'num'],
        ['key' => 'targetValue', 'col' => 'target_value', 'label' => 'القيمة المستهدفة', 'type' => 'num'],
        ['key' => 'currentValue', 'col' => 'current_value', 'label' => 'القيمة الحالية (تُقرأ آلياً إن اختير مصدر)', 'type' => 'num'],
        ['key' => 'unit', 'col' => 'unit', 'label' => 'الوحدة', 'type' => 'text'],
        ['key' => 'weight', 'col' => 'weight', 'label' => 'الوزن داخل الهدف', 'type' => 'num'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['على المسار', 'متعثرة', 'مكتملة', 'ملغاة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
    ],
    'search' => ['title', 'notes'],
];

<?php

/** سجلُّ الوحدات — «changeorders» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'changeorders',
    'table' => 'change_orders',
    'model' => 'ChangeOrder',
    'label' => 'أوامر التغيير',
    'display' => 'doc_no',
    'status' => 'status',
    'columns' => ['no', 'projectId', 'valueDelta', 'timelineDays', 'status'],
    'fields' => [
        ['key' => 'no', 'col' => 'doc_no', 'label' => 'رقم الأمر', 'type' => 'text',
         'hint' => 'يُترك فارغاً فيولّده النظام (CO-{سنة}-{تسلسل}).'],
        ['key' => 'title', 'col' => 'title', 'label' => 'عنوان التغيير', 'type' => 'text', 'required' => true],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects', 'required' => true],
        ['key' => 'clientId', 'col' => 'client_id', 'label' => 'العميل', 'type' => 'ref', 'ref' => 'clients'],
        ['key' => 'quoteId', 'col' => 'quote_id', 'label' => 'العرض المصدر', 'type' => 'ref', 'ref' => 'quotes'],
        ['key' => 'engagementId', 'col' => 'engagement_id', 'label' => 'الارتباط', 'type' => 'ref', 'ref' => 'engagements'],
        ['key' => 'description', 'col' => 'description', 'label' => 'النطاق المُضاف', 'type' => 'ta'],
        ['key' => 'reason', 'col' => 'reason', 'label' => 'سبب التغيير', 'type' => 'text'],
        ['key' => 'valueDelta', 'col' => 'value_delta', 'label' => 'تغيّر القيمة التعاقدية (±)', 'type' => 'num', 'money' => true,
         'hint' => 'موجبٌ للزيادة وسالبٌ للنقص. يُضاف لخطّ الأساس عند التطبيق.'],
        // التكلفةُ الداخلية تُخفى عن العميل بقواعد الدور (كحقل cost في العروض)
        ['key' => 'costDelta', 'col' => 'cost_delta', 'label' => 'أثر التكلفة (داخليّ)', 'type' => 'num', 'money' => true,
         'hint' => 'داخليٌّ بحت — لا يظهر للعميل.'],
        ['key' => 'timelineDays', 'col' => 'timeline_days', 'label' => 'أثر الجدول (أيام ±)', 'type' => 'num'],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'sel',
         'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو', 'KWD']],
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'المسؤول', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مسودة', 'قيد الاعتماد', 'معتمد', 'مرفوض', 'مطبَّق', 'ملغى']],
    ],
    'search' => ['doc_no', 'title', 'reason'],
];

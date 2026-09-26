<?php

/** سجلُّ الوحدات — «obligations» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'obligations',
    'table' => 'contract_obligations',
    'model' => 'ContractObligation',
    'label' => 'التزامات العقود',
    'display' => 'title',
    'status' => 'status',
    'columns' => ['title', 'contractId', 'due', 'amount', 'ownerId', 'status'],
    'fields' => [
        ['key' => 'title', 'col' => 'title', 'label' => 'الالتزام', 'type' => 'text', 'required' => true],
        ['key' => 'contractId', 'col' => 'contract_id', 'label' => 'العقد', 'type' => 'ref', 'ref' => 'contracts'],
        ['key' => 'due', 'col' => 'due', 'label' => 'تاريخ الاستحقاق', 'type' => 'date', 'expiry' => true],
        ['key' => 'amount', 'col' => 'amount', 'label' => 'المبلغ', 'type' => 'num', 'money' => true,],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'sel',
         'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو', 'KWD']],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'المسؤول عن الوفاء', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['قائم', 'مكتمل', 'متأخر', 'ملغي']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['title', 'notes'],
];

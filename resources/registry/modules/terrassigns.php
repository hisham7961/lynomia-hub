<?php

/** سجلُّ الوحدات — «terrassigns» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'terrassigns',
    'table' => 'terrassigns',
    'model' => 'TerritoryAssignment',
    'label' => 'إسناد المناطق',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'territoryId', 'empId', 'role', 'dateStart', 'dateEnd', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'الإسناد', 'type' => 'text',
         'hint' => 'يُولَّد آلياً من المنطقة والمندوب إن تُرك فارغاً.'],
        ['key' => 'territoryId', 'col' => 'territory_id', 'label' => 'المنطقة', 'type' => 'ref', 'ref' => 'territories', 'required' => true],
        ['key' => 'empId', 'col' => 'emp_id', 'label' => 'المندوب', 'type' => 'ref', 'ref' => 'hr', 'required' => true],
        ['key' => 'role', 'col' => 'role', 'label' => 'الصفة', 'type' => 'sel',
         'options' => ['أساسي', 'مساند', 'مشرف']],
        ['key' => 'dateStart', 'col' => 'date_start', 'label' => 'من تاريخ', 'type' => 'date'],
        ['key' => 'dateEnd', 'col' => 'date_end', 'label' => 'إلى تاريخ', 'type' => 'date'],
        // النقل لا يمحو التاريخ: يُنهى الإسناد بحالته وتاريخه ويُفتح غيرُه
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['ساري', 'منتهٍ']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
    ],
    'search' => ['name', 'notes'],
];

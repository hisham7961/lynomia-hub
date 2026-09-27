<?php

/** سجلُّ الوحدات — «entries» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'entries',
    'table' => 'journal_entries',
    'model' => 'JournalEntry',
    'label' => 'قيود اليومية',
    'display' => 'no',
    'status' => 'state',
    'columns' => [
        'no',
        'date',
        'projectId',
        'desc',
        
        'state',
    ],
    'fields' => [
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        [
            'key' => 'no',
            'col' => 'doc_no',
            'label' => 'رقم القيد',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'date',
            'col' => 'date',
            'label' => 'التاريخ',
            'type' => 'date',
            'required' => true,
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع',
            'type' => 'ref',
            'required' => true,
            'ref' => 'projects',
        ],
        [
            'key' => 'desc',
            'col' => 'description',
            'label' => 'البيان',
            'type' => 'ta',
        ],
        [
            'key' => 'ref',
            'col' => 'reference',
            'label' => 'المرجع',
            'type' => 'text',
        ],
        [
            'key' => 'state',
            'col' => 'state',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مسودة',
                'مرحّل',
                'ملغى',
            ],
        ],
        [
            'key' => 'finId',
            'col' => 'fin_id',
            'label' => 'المستند المرتبط',
            'type' => 'ref',
            'ref' => 'fin',
        ],
        [
            'key' => 'odooId',
            'col' => 'odoo_id',
            'label' => 'معرّف أودو',
            'type' => 'num',
        ],
    ],
    'search' => [
        'doc_no',
        'description',
        'reference',
    ],
];

<?php

/** سجلُّ الوحدات — «attend» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    // موظفٌ ويومٌ واحد = صفٌّ واحد (v2.399): التكرارُ كان يُقبل ولا ترى الخدمةُ الذاتية إلا الأول
    'unique_together' => [['empId', 'date']],
    'key' => 'attend',
    'table' => 'attendance',
    'model' => 'Attendance',
    'label' => 'الحضور والانصراف',
    'display' => 'date',
    'status' => 'status',
    'columns' => [
        'empId',
        'date',
        'in',
        'out',
        'hours',
        'status',
    ],
    'fields' => [
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        [
            'key' => 'empId',
            'col' => 'emp_id',
            'label' => 'الموظف',
            'type' => 'ref',
            'required' => true,
            'ref' => 'hr',
        ],
        [
            // الحضورُ لا يعيش منفصلاً عن العمل: أين حضر ولمن — لا متى فقط
            'key' => 'mode',
            'col' => 'mode',
            'label' => 'وضع العمل',
            'type' => 'sel',
            'options' => ['مكتب', 'عن بعد', 'موقع عميل', 'عمل ميداني', 'مهمة خارجية'],
        ],
        [
            'key' => 'clientId',
            'col' => 'client_id',
            'label' => 'العميل (لموقع عميل)',
            'type' => 'ref',
            'ref' => 'clients',
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع',
            'type' => 'ref',
            'ref' => 'projects',
        ],
        [
            'key' => 'date',
            'col' => 'date',
            'label' => 'التاريخ',
            'type' => 'date',
            'required' => true,
        ],
        [
            'key' => 'in',
            'col' => 'time_in',
            'label' => 'وقت الحضور',
            'type' => 'text',
            'format' => 'time',   // HH:MM — نصٌّ حرّ كان يُحسب ساعاتٍ فلكية (v2.399)
        ],
        [
            'key' => 'out',
            'col' => 'time_out',
            'label' => 'وقت الانصراف',
            'type' => 'text',
            'format' => 'time',
        ],
        [
            // **النوبةُ الليليّة** (مجلس الخبراء · DB-02): «٢٢:٠٠ ← ٠٦:٠٠»
            // كانت تُرفض بوصفِها يوماً سالباً. والرايةُ صريحةٌ لا مستنتَجة
            // كي لا يُبتلع الخطأُ المطبعيُّ في ورديةٍ نهاريّة.
            'key' => 'overnight',
            'col' => 'overnight',
            'label' => 'وردية ليلية (تعبر منتصف الليل)',
            'type' => 'bool',
        ],
        [
            'key' => 'hours',
            'col' => 'hours',
            'label' => 'الساعات',
            'type' => 'num',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'حاضر',
                'متأخر',
                'غائب',
                'إجازة',
                'عمل عن بعد',
                'إجازة رسمية',
                'عمل ميداني',
                // حضورٌ صحيحٌ بلا تقريرٍ يومي — **ليس غياباً**: الغياب
                // لمن لم يحضر أصلاً، لا لمن حضر ونسي الكتابة
                'حاضر — بلا تقرير',
            ],
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'ملاحظات',
            'type' => 'text',
        ],
    ],
    'search' => [
        'time_in',
        'time_out',
    ],
];

<?php

/** سجلُّ الوحدات — «autos» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'autos',
    'table' => 'automations',
    'model' => 'Automation',
    'label' => 'الأتمتة (مؤرشفة — انظر مسارات العمل)',
    'display' => 'name',
    'status' => 'status',
    'columns' => [
        'name',
        'trigger',
        'act1',
        'status',
        
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم القاعدة',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'trigger',
            'col' => 'trigger',
            'label' => 'الحدث المُشغِّل',
            'type' => 'sel',
            'required' => true,
            'options' => [
                'فوز صفقة عميل',
                'اعتماد موافقة',
                'تذكرة حرجة',
                'فشل نسخة احتياطية',
                'قرب انتهاء دومين',
                'قرب انتهاء عقد',
                'تجاوز ميزانية المشروع',
                'فاتورة متأخرة',
                'مخزون تحت الحد',
                'موظف جديد',
            ],
        ],
        [
            'key' => 'act1',
            'col' => 'act1',
            'label' => 'الإجراء الأول',
            'type' => 'sel',
            'required' => true,
            'options' => [
                'إنشاء مشروع',
                'إنشاء فاتورة',
                'إنشاء مهمة',
                'إنشاء مشكلة حرجة',
                'إشعار الفريق',
                'إرسال تلجرام',
                'إنشاء طلب موافقة',
                'إنشاء تذكرة',
            ],
        ],
        [
            'key' => 'act2',
            'col' => 'act2',
            'label' => 'الإجراء الثاني',
            'type' => 'sel',
            'options' => [
                '—',
                'إنشاء مشروع',
                'إنشاء فاتورة',
                'إنشاء مهمة',
                'إشعار الفريق',
                'إرسال تلجرام',
                'إنشاء طلب موافقة',
            ],
        ],
        [
            'key' => 'act3',
            'col' => 'act3',
            'label' => 'الإجراء الثالث',
            'type' => 'sel',
            'options' => [
                '—',
                'إنشاء مهمة',
                'إشعار الفريق',
                'إرسال تلجرام',
            ],
        ],
        [
            'key' => 'assigneeId',
            'col' => 'assignee_id',
            'label' => 'يُسند إلى',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'status',
            'col' => 'status',
            'label' => 'الحالة',
            'type' => 'sel',
            'options' => [
                'مفعّلة',
                'متوقفة',
            ],
        ],
        [
            'key' => 'notes',
            'col' => 'notes',
            'label' => 'ملاحظات',
            'type' => 'ta',
        ],
    ],
    'search' => [
        'name',
    ],
];

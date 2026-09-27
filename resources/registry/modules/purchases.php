<?php

/** سجلُّ الوحدات — «purchases» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'purchases',
    'table' => 'purchases',
    'model' => 'Purchase',
    'label' => 'المشتريات',
    'display' => 'no',
    'status' => 'status',
    'columns' => ['no', 'supplierId', 'status', 'amount', 'due', 'payState'],
    'fields' => [
        ['key' => 'receivedAt', 'col' => 'received_at', 'label' => 'تاريخ الاستلام الفعلي', 'type' => 'date'],
        ['key' => 'no', 'col' => 'doc_no', 'label' => 'رقم المستند', 'type' => 'text', 'required' => true],
        ['key' => 'supplierId', 'col' => 'supplier_id', 'label' => 'المورد', 'type' => 'ref', 'ref' => 'suppliers', 'required' => true],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'date', 'col' => 'date', 'label' => 'تاريخ الطلب', 'type' => 'date'],
        ['key' => 'due', 'col' => 'due', 'label' => 'التسليم المتوقع', 'type' => 'date'],
        ['key' => 'items', 'col' => 'items', 'label' => 'البنود (سطر لكل بند: وصف | كمية | سعر | وحدات الكرتونة اختياري)', 'type' => 'ta',
         'hint' => 'أضف عموداً رابعاً «وحدات الكرتونة» لأي بند لحساب كراتينه، أو اضبط تعبئة المنتج مرةً في المخزون فتُحسب تلقائياً بمطابقة الاسم.'],
        ['key' => 'amount', 'col' => 'amount', 'label' => 'الإجمالي', 'type' => 'num', 'money' => true,],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'sel',
         'options' => ['KWD', 'USD', 'EUR', 'SAR', 'AED']],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['مسودة', 'بانتظار الاعتماد', 'معتمد', 'أُرسل للمورد', 'مستلم', 'مرتجع', 'ملغى']],
        ['key' => 'payState', 'col' => 'pay_state', 'label' => 'حالة الدفع', 'type' => 'sel',
         'options' => ['غير مدفوع', 'مدفوع جزئياً', 'مدفوع']],
        // شراءٌ لصالح عميل: يُفوتر له بهامشٍ — استضافةٌ بألفٍ تُفوتر بألفٍ ومئتين
        ['key' => 'clientId', 'col' => 'client_id', 'label' => 'لصالح العميل', 'type' => 'ref', 'ref' => 'clients',
         'hint' => 'اتركه فارغاً للشراء الداخلي. مع عميلٍ وراية «يُفوتر»، يُحسب مبلغُ الفوترة من الهامش.'],
        ['key' => 'billable', 'col' => 'billable', 'label' => 'يُفوتر للعميل', 'type' => 'bool'],
        ['key' => 'markup', 'col' => 'markup', 'label' => 'الهامش ٪', 'type' => 'num'],
        ['key' => 'charge', 'col' => 'charge', 'label' => 'مبلغ الفوترة للعميل', 'type' => 'num', 'money' => true,
         'hint' => 'يُحسب تلقائياً من الإجمالي والهامش إن تُرك فارغاً — وأدخِله يدوياً ليغلب.'],
        ['key' => 'invoiceNo', 'col' => 'invoice_no', 'label' => 'رقم فاتورة المورد', 'type' => 'text'],
        ['key' => 'att', 'col' => 'att_id', 'label' => 'مرفق (عرض المورد/الفاتورة)', 'type' => 'file'],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['doc_no', 'invoice_no', 'notes'],
];

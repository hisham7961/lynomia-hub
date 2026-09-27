<?php

/** سجلُّ الوحدات — «products» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'products',
    'table' => 'products',
    'model' => 'Product',
    'label' => 'سجل المنتجات',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['code', 'name', 'brand', 'model', 'type', 'status'],
    'fields' => [
        // كودُ الهوية الدائم — يولّده النظام ولا يُكتب، كما كود العهدة
        ['key' => 'code', 'col' => 'code', 'label' => 'كود المنتج', 'type' => 'text', 'locked' => true,
         'hint' => 'LYN-PRD — هويةٌ دائمة يولّدها النظام، تُطبع على الملصق وتبقى بعد الدمج اسماً بديلاً.'],
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم المنتج', 'type' => 'text', 'required' => true],
        ['key' => 'brand', 'col' => 'brand', 'label' => 'العلامة التجارية', 'type' => 'text'],
        ['key' => 'manufacturer', 'col' => 'manufacturer', 'label' => 'المصنّع', 'type' => 'text'],
        ['key' => 'model', 'col' => 'model', 'label' => 'الطراز (Model)', 'type' => 'text'],
        ['key' => 'type', 'col' => 'type', 'label' => 'النوع', 'type' => 'sel',
         'options' => ['لابتوب', 'هاتف', 'سيرفر', 'شاشة', 'سويتش', 'UPS', 'طابعة', 'أثاث', 'سيارة', 'رخصة برمجية', 'أخرى']],
        ['key' => 'barcode', 'col' => 'barcode', 'label' => 'الباركود العالمي (GTIN/EAN/UPC)', 'type' => 'text',
         'hint' => 'باركود الطراز كما يطبعه المصنّع — يعرّف المنتجَ لا القطعة. معرفاتٌ إضافية من بطاقة الهوية في صفحة المنتج.'],
        ['key' => 'mpn', 'col' => 'mpn', 'label' => 'رقم قطعة المصنع (MPN)', 'type' => 'text'],
        ['key' => 'origin', 'col' => 'origin', 'label' => 'بلد المنشأ', 'type' => 'text'],
        ['key' => 'supplierId', 'col' => 'supplier_id', 'label' => 'المورد المعتاد', 'type' => 'ref', 'ref' => 'suppliers'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'descr', 'col' => 'descr', 'label' => 'الوصف', 'type' => 'ta'],
        ['key' => 'status', 'col' => 'status', 'label' => 'التوثيق', 'type' => 'sel',
         'options' => ['غير موثّق', 'موثّق', 'بحاجة مراجعة', 'مؤرشف بدمج']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['code', 'name', 'brand', 'model', 'barcode', 'mpn'],
];

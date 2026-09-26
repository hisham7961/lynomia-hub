<?php

/** سجلُّ الوحدات — «suppliers» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'suppliers',
    'table' => 'suppliers',
    'model' => 'Supplier',
    'label' => 'الموردون',
    'display' => 'name',
    /*
     * **بلا `status`** (v2.340): كان `'status' => 'rating'`، فحالةُ المورد
     * هي تقييمُه بالنجوم — وسحبُ بطاقةٍ في الكانبان وإجراءُ «تغيير الحالة»
     * الجماعيّ وإطلاقُ مسارات العمل كلُّها **تُعيد كتابة التقييم**.
     * تغييرُ تصنيفٍ إداريّ كان يمحو حكماً على جودة مورد.
     */
    'columns' => ['name', 'cat', 'rating', 'phone', 'terms'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم المورد', 'type' => 'text', 'required' => true],
        ['key' => 'contact', 'col' => 'contact', 'label' => 'الشخص المسؤول', 'type' => 'text'],
        ['key' => 'email', 'col' => 'email', 'label' => 'البريد', 'type' => 'text'],
        ['key' => 'phone', 'col' => 'phone', 'label' => 'الهاتف', 'type' => 'text'],
        ['key' => 'country', 'col' => 'country', 'label' => 'الدولة', 'type' => 'text'],
        ['key' => 'cat', 'col' => 'cat', 'label' => 'التصنيف', 'type' => 'sel',
         'options' => ['خدمات', 'برمجيات', 'استضافة وسيرفرات', 'معدات وأجهزة', 'تسويق', 'شحن وتوصيل', 'أخرى']],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'شركتنا المرتبطة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'rating', 'col' => 'rating', 'label' => 'التقييم', 'type' => 'sel',
         'options' => ['⭐⭐⭐⭐⭐ ممتاز', '⭐⭐⭐⭐ جيد جداً', '⭐⭐⭐ جيد', '⭐⭐ مقبول', '⭐ ضعيف']],
        ['key' => 'terms', 'col' => 'terms', 'label' => 'شروط الدفع المتفق عليها', 'type' => 'text'],
        ['key' => 'iban', 'col' => 'iban', 'label' => 'IBAN / الحساب البنكي', 'type' => 'text'],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'contact', 'email', 'phone', 'iban'],
];

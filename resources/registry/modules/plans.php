<?php

/** سجلُّ الوحدات — «plans» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'plans',
    'table' => 'pricing_plans',
    'model' => 'PricingPlan',
    'label' => 'الباقات والتسعير',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'serviceId', 'price', 'cycle', 'effectiveFrom', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم الباقة', 'type' => 'text', 'required' => true],
        ['key' => 'serviceId', 'col' => 'service_id', 'label' => 'الخدمة أو المنتج', 'type' => 'ref', 'ref' => 'services', 'required' => true],
        ['key' => 'price', 'col' => 'price', 'label' => 'السعر', 'type' => 'num', 'money' => true, 'required' => true],
        ['key' => 'currency', 'col' => 'currency', 'label' => 'العملة', 'type' => 'sel',
         'options' => ['د.ك', 'دولار', 'ريال', 'درهم', 'يورو']],
        ['key' => 'cycle', 'col' => 'cycle', 'label' => 'دورة الفوترة', 'type' => 'sel',
         'options' => ['شهري', 'ربع سنوي', 'سنوي', 'مرة واحدة', 'حسب الاستخدام']],
        ['key' => 'market', 'col' => 'market', 'label' => 'السوق المستهدف', 'type' => 'text'],
        ['key' => 'limits', 'col' => 'limits', 'label' => 'الحدود والكميات (عدد الرسائل، عدد المستخدمين…)', 'type' => 'ta'],
        ['key' => 'features', 'col' => 'features', 'label' => 'المزايا المشمولة', 'type' => 'ta'],
        ['key' => 'freeTier', 'col' => 'free_tier', 'label' => 'باقة مجانية', 'type' => 'bool'],
        ['key' => 'addons', 'col' => 'addons', 'label' => 'الإضافات المتاحة', 'type' => 'ta'],
        ['key' => 'discounts', 'col' => 'discounts', 'label' => 'الخصومات وشروطها', 'type' => 'ta'],
        ['key' => 'effectiveFrom', 'col' => 'effective_from', 'label' => 'تاريخ سريان السعر', 'type' => 'date'],
        ['key' => 'prevPrice', 'col' => 'prev_price', 'label' => 'السعر السابق', 'type' => 'num', 'money' => true,],
        ['key' => 'priceChangedAt', 'col' => 'price_changed_at', 'label' => 'تاريخ آخر تغيير للسعر', 'type' => 'date'],
        ['key' => 'unitCost', 'col' => 'unit_cost', 'label' => 'التكلفة التقديرية لتقديم الباقة', 'type' => 'num', 'money' => true,],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['نشطة', 'قيد الإعداد', 'متوقفة عن البيع', 'ملغاة']],
    ],
    'search' => ['name', 'market', 'features', 'limits'],
];

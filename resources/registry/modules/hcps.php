<?php

/** سجلُّ الوحدات — «hcps» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'hcps',
    'table' => 'hcps',
    'model' => 'Hcp',
    'label' => 'مقدمو الرعاية الصحية',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'specialty', 'class', 'territoryId', 'city', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'الاسم', 'type' => 'text', 'required' => true],
        ['key' => 'title', 'col' => 'title', 'label' => 'اللقب المهني', 'type' => 'sel',
         'options' => ['د.', 'أ.د.', 'صيدلي', 'ممرض', 'فني', 'إداري', 'أخرى']],
        ['key' => 'kind', 'col' => 'kind', 'label' => 'الصفة', 'type' => 'sel',
         'options' => ['طبيب', 'صيدلي', 'ممرض', 'مشتريات', 'إداري', 'أخرى']],
        ['key' => 'specialty', 'col' => 'specialty', 'label' => 'التخصص', 'type' => 'sel',
         'options' => ['باطنية', 'أطفال', 'نساء وولادة', 'جلدية', 'عظام', 'قلب', 'أعصاب', 'نفسية', 'أسنان', 'عيون', 'أنف وأذن', 'جراحة عامة', 'مسالك', 'صدرية', 'غدد وسكري', 'طب عام', 'طوارئ', 'تخدير', 'أشعة', 'مختبر', 'صيدلة', 'أخرى']],
        ['key' => 'class', 'col' => 'class', 'label' => 'التصنيف', 'type' => 'sel',
         'options' => ['أ — تأثير عالٍ', 'ب — تأثير متوسط', 'ج — تأثير محدود', 'غير مصنّف'],
         'hint' => 'تصنيفُ أهمية الزيارة لا قيمةً مالية — يحدد تكرار الزيارات المستهدف في الدورة.'],
        ['key' => 'facilityIds', 'col' => 'facility_ids', 'label' => 'منشآت العمل', 'type' => 'ref', 'ref' => 'facilities', 'multi' => true,
         'hint' => 'الطبيب يعمل في أكثر من مكان — عيادته الخاصة صباحاً ومستشفى مساءً.'],
        ['key' => 'territoryId', 'col' => 'territory_id', 'label' => 'المنطقة', 'type' => 'ref', 'ref' => 'territories'],
        ['key' => 'phone', 'col' => 'phone', 'label' => 'الهاتف', 'type' => 'text'],
        ['key' => 'email', 'col' => 'email', 'label' => 'البريد', 'type' => 'text'],
        ['key' => 'city', 'col' => 'city', 'label' => 'المدينة', 'type' => 'text'],
        ['key' => 'area', 'col' => 'area', 'label' => 'المنطقة السكنية', 'type' => 'text'],
        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['نشط', 'غير نشط', 'منتقل', 'متقاعد']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات مهنية', 'type' => 'ta',
         'hint' => 'اهتماماته العلمية وأسلوب الزيارة الأنسب — لا بيانات شخصية لا تخص العمل.'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'specialty', 'city', 'area'],
];

<?php

/** سجلُّ الوحدات — «brands» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'brands',
    'table' => 'brands',
    'model' => 'Brand',
    'label' => 'العلامات التجارية',
    'display' => 'name',
    'status' => 'status',
    'columns' => ['name', 'companyId', 'projectId', 'tmStatus', 'tmExpiry', 'status'],
    'fields' => [
        ['key' => 'name', 'col' => 'name', 'label' => 'اسم العلامة التجارية', 'type' => 'text', 'required' => true],
        ['key' => 'nameEn', 'col' => 'name_en', 'label' => 'الاسم بالإنجليزية', 'type' => 'text'],
        ['key' => 'slogan', 'col' => 'slogan', 'label' => 'الشعار اللفظي (Slogan)', 'type' => 'text'],
        ['key' => 'companyId', 'col' => 'company_id', 'label' => 'الشركة المالكة', 'type' => 'ref', 'ref' => 'companies'],
        ['key' => 'projectId', 'col' => 'project_id', 'label' => 'المشروع المرتبط', 'type' => 'ref', 'ref' => 'projects'],
        ['key' => 'desc', 'col' => 'description', 'label' => 'وصف العلامة وما تمثله', 'type' => 'ta'],
        ['key' => 'logo', 'col' => 'logo_id', 'label' => 'الشعار', 'type' => 'img'],
        ['key' => 'colors', 'col' => 'colors', 'label' => 'ألوان الهوية (أكواد HEX)', 'type' => 'text'],
        ['key' => 'fonts', 'col' => 'fonts', 'label' => 'خطوط الهوية (عربي وإنجليزي)', 'type' => 'text'],
        ['key' => 'tone', 'col' => 'tone', 'label' => 'نبرة الصوت وأسلوب المخاطبة', 'type' => 'ta'],
        ['key' => 'ownerId', 'col' => 'owner_id', 'label' => 'المسؤول عن العلامة', 'type' => 'ref', 'ref' => 'users'],
        ['key' => 'website', 'col' => 'website', 'label' => 'الموقع الإلكتروني', 'type' => 'url'],
        ['key' => 'social', 'col' => 'social', 'label' => 'الحسابات على منصات التواصل', 'type' => 'ta'],
        ['key' => 'services', 'col' => 'services', 'label' => 'المنتجات والخدمات تحت العلامة', 'type' => 'ref', 'ref' => 'services', 'multi' => true],
        ['key' => 'kit', 'col' => 'kit_id', 'label' => 'ملفات الهوية (Brand Kit)', 'type' => 'file'],
        ['key' => 'guidelines', 'col' => 'guidelines', 'label' => 'إرشادات الاستخدام والممنوعات', 'type' => 'ta'],

        // الملكية الفكرية: علامةٌ بلا تسجيلٍ ملكٌ لمن يسجّلها أولاً،
        // ومسجَّلةٌ بلا تجديدٍ تسقط بانقضاء مدتها صمتاً
        ['key' => 'tmStatus', 'col' => 'tm_status', 'label' => 'حالة التسجيل بالملكية الفكرية', 'type' => 'sel',
         'options' => ['غير مسجّلة', 'قيد الإعداد', 'مُودعة (قيد الفحص)', 'منشورة للاعتراض', 'مسجّلة', 'مرفوضة', 'منتهية']],
        ['key' => 'tmNo', 'col' => 'tm_no', 'label' => 'رقم التسجيل/الإيداع', 'type' => 'text'],
        ['key' => 'tmClasses', 'col' => 'tm_classes', 'label' => 'الفئات المسجَّلة (نيس)', 'type' => 'text'],
        ['key' => 'tmCountries', 'col' => 'tm_countries', 'label' => 'البلدان المحمية فيها', 'type' => 'text'],
        ['key' => 'tmOffice', 'col' => 'tm_office', 'label' => 'جهة التسجيل', 'type' => 'text'],
        ['key' => 'tmAgent', 'col' => 'tm_agent', 'label' => 'وكيل الملكية الفكرية', 'type' => 'text'],
        ['key' => 'tmFiledAt', 'col' => 'tm_filed_at', 'label' => 'تاريخ الإيداع', 'type' => 'date'],
        ['key' => 'tmRegAt', 'col' => 'tm_reg_at', 'label' => 'تاريخ التسجيل', 'type' => 'date'],
        ['key' => 'tmExpiry', 'col' => 'tm_expiry', 'label' => 'تاريخ التجديد', 'type' => 'date', 'expiry' => true],

        // أصولها الرقمية: العلامة تعيش في نطاقاتٍ وحساباتٍ وتطبيقات
        ['key' => 'domains', 'col' => 'domain_ids', 'label' => 'النطاقات المطابقة', 'type' => 'ref', 'ref' => 'domains', 'multi' => true],
        ['key' => 'socialIds', 'col' => 'social_ids', 'label' => 'حسابات التواصل التابعة', 'type' => 'ref', 'ref' => 'social', 'multi' => true],
        ['key' => 'appIds', 'col' => 'app_ids', 'label' => 'التطبيقات التي تحملها', 'type' => 'ref', 'ref' => 'apps', 'multi' => true],

        ['key' => 'status', 'col' => 'status', 'label' => 'الحالة', 'type' => 'sel',
         'options' => ['نشطة', 'قيد الإطلاق', 'متوقفة']],
        ['key' => 'notes', 'col' => 'notes', 'label' => 'ملاحظات', 'type' => 'ta'],
        ['key' => 'tags', 'col' => 'tags', 'label' => 'وسوم', 'type' => 'tags'],
    ],
    'search' => ['name', 'description', 'tone', 'guidelines'],
];

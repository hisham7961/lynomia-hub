<?php

/**
 * ملف الكيان — الوثائق **المتعارف عليها** لكل وحدة.
 *
 * كانت المرفقات كومةً بلا نوعٍ ولا صلاحيةٍ ولا نقص: تُرفع الملفات فلا يُعرف ما
 * الذي ينقص الشركة، ولا ينبّه انتهاءُ سجلٍ تجاري أحداً. هنا تُعلَن الوثائق
 * المتوقّعة لكل وحدة فيصير للملف **اكتمالٌ** يُقاس، و**نواقص** تُسمّى،
 * و**انتهاءٌ** يصل رادار «ينتهي قريباً».
 *
 * لكل وثيقة:
 *   key    مفتاحها (يُخزَّن في attachments.kind)
 *   label  اسمها العربي
 *   req    إلزامية؟ (تدخل حساب الاكتمال والنواقص)
 *   expiry لها تاريخ انتهاء يُتابَع
 *   multi  تُقبل نسخٌ متعددة (هويات شركاء، وكالات، لقطات)
 *   hint   سطرٌ يشرح ما المطلوب بالضبط
 */
return [

    // ── الشركات: ملف الكيان القانوني كاملاً ──
    'companies' => [
        ['key' => 'cr', 'label' => 'السجل التجاري', 'req' => true, 'expiry' => true,
         'hint' => 'الصورة السارية من السجل التجاري برقمه وتاريخ انتهائه'],
        ['key' => 'cr_ext', 'label' => 'مستخرج السجل التجاري', 'expiry' => true,
         'hint' => 'المستخرج الحديث المطلوب للجهات والبنوك'],
        ['key' => 'aoa', 'label' => 'عقد التأسيس والنظام الأساسي', 'req' => true,
         'hint' => 'عقد التأسيس وتعديلاته إن وُجدت'],
        ['key' => 'amend', 'label' => 'ملاحق تعديل عقد التأسيس', 'multi' => true,
         'hint' => 'كل تعديلٍ لاحقٍ على العقد بملحقٍ مستقل'],
        ['key' => 'founders', 'label' => 'هويات المؤسسين والشركاء', 'req' => true, 'multi' => true,
         'expiry' => true, 'hint' => 'هوية/جواز كل شريكٍ بنسبته'],
        ['key' => 'tax', 'label' => 'الشهادة الضريبية', 'req' => true, 'expiry' => true,
         'hint' => 'شهادة التسجيل الضريبي / رقم المكلّف'],
        ['key' => 'vat', 'label' => 'شهادة القيمة المضافة', 'expiry' => true],
        ['key' => 'license', 'label' => 'تراخيص مزاولة النشاط', 'multi' => true, 'expiry' => true,
         'hint' => 'ترخيص البلدية والجهات القطاعية'],
        ['key' => 'chamber', 'label' => 'عضوية الغرفة التجارية', 'expiry' => true],
        ['key' => 'social_ins', 'label' => 'شهادة التأمينات الاجتماعية', 'expiry' => true],
        ['key' => 'poa', 'label' => 'الوكالات المرتبطة بالشركة', 'multi' => true, 'expiry' => true,
         'hint' => 'وكالة قانونية، وكالة بنكية، تفويض توقيع — كلٌّ بمدّته'],
        ['key' => 'signatories', 'label' => 'قرار المفوّضين بالتوقيع', 'expiry' => true,
         'hint' => 'من يُلزِم الشركة بتوقيعه وحدوده'],
        ['key' => 'lease', 'label' => 'عقد المقر / سند الملكية', 'expiry' => true],
        ['key' => 'bank', 'label' => 'بيانات الحساب البنكي', 'hint' => 'خطاب فتح الحساب أو الآيبان الرسمي'],
        ['key' => 'stamp', 'label' => 'الختم والتوقيع المعتمد'],
        ['key' => 'profile', 'label' => 'الملف التعريفي للشركة', 'hint' => 'Company Profile للعروض والمناقصات'],
        ['key' => 'insurance', 'label' => 'وثائق التأمين', 'multi' => true, 'expiry' => true],
    ],

    // ── المشاريع: ما يجب أن يُسلَّم ويُحفَظ مع المشروع ──
    'projects' => [
        ['key' => 'logo', 'label' => 'الشعار (ملف مصدري)', 'req' => true,
         'hint' => 'SVG/AI بجودة الطباعة لا لقطة شاشة'],
        ['key' => 'brandbook', 'label' => 'دليل الهوية البصرية',
         'hint' => 'الألوان والخطوط والاستخدامات الممنوعة'],
        ['key' => 'sow', 'label' => 'نطاق العمل / كراسة الشروط', 'req' => true,
         'hint' => 'ما اتُّفق على تسليمه بالضبط — مرجع أي خلاف لاحق'],
        ['key' => 'contract', 'label' => 'عقد المشروع', 'req' => true, 'expiry' => true],
        ['key' => 'plan', 'label' => 'الخطة الزمنية المعتمدة'],
        ['key' => 'design', 'label' => 'التصاميم النهائية', 'multi' => true],
        ['key' => 'assets', 'label' => 'ملفات المصدر والأصول', 'multi' => true,
         'hint' => 'خطوط، صور، فيديو، ملفات تصميم مفتوحة'],
        ['key' => 'handover', 'label' => 'محاضر التسليم والاستلام', 'multi' => true],
        ['key' => 'accept', 'label' => 'شهادة القبول النهائي'],
        ['key' => 'report', 'label' => 'تقارير المشروع', 'multi' => true],
    ],

    // ── العلامات التجارية: الملكية الفكرية أولاً ──
    'brands' => [
        ['key' => 'tm_cert', 'label' => 'شهادة تسجيل العلامة', 'req' => true, 'expiry' => true,
         'hint' => 'شهادة التسجيل بالملكية الفكرية برقمها وفئتها وتاريخ تجديدها'],
        ['key' => 'tm_filing', 'label' => 'طلب/إيداع التسجيل',
         'hint' => 'إيصال الإيداع ورقم الطلب قبل صدور الشهادة'],
        ['key' => 'tm_classes', 'label' => 'الفئات المسجَّلة والبلدان', 'multi' => true,
         'hint' => 'كل فئة/بلد بشهادتها — الحماية إقليمية لا عالمية'],
        ['key' => 'logo_vector', 'label' => 'ملف الشعار المتجهي', 'req' => true],
        ['key' => 'guide', 'label' => 'دليل استخدام العلامة'],
        ['key' => 'license', 'label' => 'عقود ترخيص/امتياز العلامة', 'multi' => true, 'expiry' => true],
        ['key' => 'oppose', 'label' => 'اعتراضات ونزاعات', 'multi' => true,
         'hint' => 'أي اعتراضٍ على العلامة أو منها — سجلٌّ يحمي الحق'],
        ['key' => 'domains', 'label' => 'إثبات ملكية النطاقات المطابقة', 'multi' => true],
    ],

    // ── الخدمات والمنتجات ──
    'services' => [
        ['key' => 'brochure', 'label' => 'الكتيّب التعريفي / البروشور'],
        ['key' => 'pricelist', 'label' => 'قائمة الأسعار المعتمدة', 'req' => true, 'expiry' => true,
         'hint' => 'السعر المعتمد وتاريخ مراجعته القادم'],
        ['key' => 'sla', 'label' => 'اتفاقية مستوى الخدمة (SLA)', 'expiry' => true],
        ['key' => 'terms', 'label' => 'الشروط والأحكام', 'req' => true],
        ['key' => 'costing', 'label' => 'دراسة التكلفة والهامش',
         'hint' => 'ما يُبنى عليه السعر — بلا هذا التسعير تخمين'],
        ['key' => 'deck', 'label' => 'العروض التقديمية', 'multi' => true],
        ['key' => 'demo', 'label' => 'عرض تجريبي / نماذج أعمال', 'multi' => true],
        ['key' => 'cert', 'label' => 'شهادات واعتمادات الخدمة', 'multi' => true, 'expiry' => true],
    ],

    // ── المنافسون: الدليل لا الانطباع ──
    'competitors' => [
        ['key' => 'screens', 'label' => 'لقطات الموقع والتطبيق', 'multi' => true,
         'hint' => 'دليلٌ مؤرَّخ يُقارَن به لاحقاً — الذاكرة تخون'],
        ['key' => 'pricing', 'label' => 'قوائم أسعارهم وباقاتهم', 'multi' => true,
         'hint' => 'صورة الصفحة أو الملف بتاريخه'],
        ['key' => 'marketing', 'label' => 'موادّهم التسويقية والإعلانات', 'multi' => true],
        ['key' => 'analysis', 'label' => 'تقارير تحليل ومقارنة', 'multi' => true],
        ['key' => 'news', 'label' => 'أخبار وتغطية إعلامية', 'multi' => true],
    ],

    // ── العملاء ──
    'clients' => [
        ['key' => 'cr', 'label' => 'السجل التجاري للعميل', 'expiry' => true],
        ['key' => 'tax', 'label' => 'الشهادة الضريبية', 'expiry' => true],
        ['key' => 'auth_id', 'label' => 'هوية المفوّض بالتوقيع'],
        ['key' => 'nda', 'label' => 'اتفاقية عدم الإفصاح', 'expiry' => true],
        ['key' => 'msa', 'label' => 'الاتفاقية الإطارية', 'expiry' => true],
        ['key' => 'po', 'label' => 'أوامر الشراء', 'multi' => true],
    ],

    // ── الموردون ──
    'suppliers' => [
        ['key' => 'cr', 'label' => 'السجل التجاري', 'req' => true, 'expiry' => true],
        ['key' => 'tax', 'label' => 'الشهادة الضريبية', 'req' => true, 'expiry' => true],
        ['key' => 'bank', 'label' => 'بيانات الحساب البنكي', 'req' => true,
         'hint' => 'مختوماً من البنك — الحوالات بلا إثبات مخاطرة'],
        ['key' => 'cert', 'label' => 'شهادات الجودة والاعتماد', 'multi' => true, 'expiry' => true],
        ['key' => 'agreement', 'label' => 'اتفاقية التوريد', 'expiry' => true],
        ['key' => 'catalog', 'label' => 'الكتالوج وقائمة الأسعار', 'expiry' => true],
    ],

    // ── الموظفون ──
    'hr' => [
        // الصورة الشخصية: وجهُ الموظف في الدليل — كانت الوحدة بلا حقلٍ لها إطلاقاً
        ['key' => 'photo', 'label' => 'الصورة الشخصية'],
        ['key' => 'id', 'label' => 'الهوية / الإقامة', 'req' => true, 'expiry' => true],
        ['key' => 'passport', 'label' => 'جواز السفر', 'expiry' => true],
        ['key' => 'contract', 'label' => 'عقد العمل', 'req' => true, 'expiry' => true],
        ['key' => 'degree', 'label' => 'الشهادات العلمية', 'multi' => true],
        ['key' => 'cv', 'label' => 'السيرة الذاتية'],
        ['key' => 'health', 'label' => 'الشهادة الصحية / التأمين', 'expiry' => true],
        ['key' => 'nda', 'label' => 'إقرار السرية وعدم المنافسة'],
        ['key' => 'bank', 'label' => 'بيانات الحساب البنكي', 'req' => true],
    ],

    // ── التطبيقات ──
    'apps' => [
        ['key' => 'icon', 'label' => 'الأيقونة عالية الدقة', 'req' => true],
        ['key' => 'screens', 'label' => 'لقطات المتاجر', 'multi' => true, 'req' => true],
        ['key' => 'privacy', 'label' => 'سياسة الخصوصية المنشورة', 'req' => true],
        ['key' => 'signing', 'label' => 'شهادات ومفاتيح التوقيع', 'expiry' => true,
         'hint' => 'ضياع مفتاح التوقيع يعني تطبيقاً لا يُحدَّث أبداً'],
        ['key' => 'store_listing', 'label' => 'نصوص ووصف المتجر'],
        ['key' => 'release_notes', 'label' => 'ملاحظات الإصدارات', 'multi' => true],
    ],

    // ── العقود ──
    'contracts' => [
        ['key' => 'signed', 'label' => 'النسخة الموقّعة', 'req' => true],
        ['key' => 'annex', 'label' => 'الملاحق والتعديلات', 'multi' => true],
        ['key' => 'guarantee', 'label' => 'الضمانات وخطابات الضمان', 'expiry' => true],
        ['key' => 'insurance', 'label' => 'وثيقة التأمين المرتبطة', 'expiry' => true],
    ],

    // ── الأصول ──
    'assets' => [
        ['key' => 'invoice', 'label' => 'فاتورة الشراء', 'req' => true],
        ['key' => 'warranty', 'label' => 'الضمان', 'expiry' => true],
        ['key' => 'manual', 'label' => 'دليل التشغيل'],
        ['key' => 'custody', 'label' => 'إقرارات العهدة الموقّعة', 'multi' => true],
    ],

    // ── المواقع الإلكترونية ──
    'websites' => [
        ['key' => 'design', 'label' => 'ملفات التصميم المصدرية', 'multi' => true],
        ['key' => 'content', 'label' => 'نصوص وصور الموقع', 'multi' => true],
        ['key' => 'ssl', 'label' => 'شهادة SSL', 'expiry' => true],
        ['key' => 'legal', 'label' => 'سياسة الخصوصية والشروط', 'req' => true],
    ],
];

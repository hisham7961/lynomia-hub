<?php

/** سجلُّ الوحدات — «files» (docs/REORG_PLAN.md §R4) — يُحمَّل بترتيبه من قائمة config/hub.php */

return [
    'key' => 'files',
    'table' => 'documents',
    'model' => 'Document',
    'label' => 'الملفات والمستندات',
    'display' => 'name',
    'status' => 'docStatus',
    'columns' => [
        'name',
        'cat',
        'projectId',
        'secrecy',
        // (الجولة 2 · G8) «ما الذي يراه العميل؟» سؤالٌ يُجاب من القائمة نفسِها
        'audience',
        'expiry',
        'docStatus',
    ],
    'fields' => [
        [
            'key' => 'name',
            'col' => 'name',
            'label' => 'اسم الملف / المستند',
            'type' => 'text',
            'required' => true,
        ],
        [
            'key' => 'cat',
            'col' => 'cat',
            'label' => 'التصنيف',
            'type' => 'sel',
            'options' => [
                'ملفات الشركة',
                'ملفات المشروع',
                'ملفات التطبيقات',
                'تصميم وهوية',
                'قانوني',
                'مالية',
                'عقود',
                'فواتير',
                'عروض أسعار',
                'تقارير',
                'تقني',
                'صور وفيديو',
                'هويات بصرية',
                'شعارات',
                'ملفات المتاجر',
                'أخرى',
            ],
        ],
        [
            'key' => 'legalCat',
            'col' => 'legal_cat',
            'label' => 'التصنيف القانوني (لملفات الشركة)',
            'type' => 'sel',
            'options' => ['رخصة تجارية', 'تسجيل حكومي', 'عقد', 'تأمين', 'قضية', 'أخرى'],
        ],
        [
            'key' => 'brandKind',
            'col' => 'brand_kind',
            'label' => 'نوع أصل الهوية (للتصنيف: تصميم وهوية)',
            'type' => 'sel',
            'options' => ['شعار', 'دليل هوية', 'خطوط', 'ألوان', 'قوالب', 'أخرى'],
        ],
        [
            'key' => 'folder',
            'col' => 'folder',
            'label' => 'المجلد',
            'type' => 'text',
        ],
        [
            'key' => 'taskId',
            'col' => 'task_id',
            'label' => 'المهمة المرتبطة',
            'type' => 'ref',
            'ref' => 'tasks',
        ],
        [
            'key' => 'companyId',
            'col' => 'company_id',
            'label' => 'الشركة',
            'type' => 'ref',
            'ref' => 'companies',
        ],
        [
            'key' => 'projectId',
            'col' => 'project_id',
            'label' => 'المشروع',
            'type' => 'ref',
            'ref' => 'projects',
        ],
        [
            'key' => 'ver',
            'col' => 'ver',
            'label' => 'الإصدار',
            'type' => 'text',
        ],
        [
            'key' => 'secrecy',
            'col' => 'secrecy',
            'label' => 'مستوى السرية',
            'type' => 'sel',
            'options' => [
                'عام',
                'داخلي',
                'سري',
            ],
            // (الجولة 2 · G8) السرّيةُ ليست مشاركةً — كانت مريم ترفعها إلى «عام»
            // ظنّاً أنّها تُشارك، فلا يظهر شيءٌ في البوّابة ولا كلمةَ تقول لماذا.
            'hint' => 'تصنيفٌ داخليٌّ لا يفتح شيئاً للعميل — المشاركةُ من حقل «الجمهور» أدناه.'
                . ' و«سري» يبقى محجوباً عن بوّابة العميل مهما كان جمهورُه.',
        ],
        /*
         * **سطحُ «شارك مع العميل»** (الجولة 2 · G8) — بوّابةُ العميل تقرأ
         * `documents.audience` منذ WP-B.5، والمحرّكُ يكتبه في
         * `ModuleController::applyDocumentAudience`، لكنّ الحقلَ لم يكن في
         * السجلّ قطّ: فنموذجا الوثيقةِ المشارَكةِ وغيرِها متطابقان بايتاً
         * ببايت، ولا مالكٌ ولا مديرةُ مشروعٍ تشارك وثيقةً من أيّ شاشة
         * (أُثبت صندوقاً أسود في المحاكاة). الحقلان هنا هما ذلك السطح:
         *
         *  • القيمُ **إنجليزيّةٌ عمداً** — هي `Document::AUDIENCES` حرفاً
         *    (allowlist التطبيق · C10): قيمةٌ عربيّةٌ تعبر `Rule::in` ثم
         *    يرميها حارسُ النموذج بخمسمئة. والوسمُ والإرشادُ عربيّان.
         *  • **الافتراضُ داخليّ**: حقلٌ فارغٌ ⇒ `internal` (حارسُ النموذج)،
         *    فوثيقةٌ بلا جمهورٍ صريحٍ لا تُرى في البوّابة أبداً.
         *  • **خلف كتابةِ الوحدة**: لا يبلغ النموذجَ إلا حاملُ `files:a/e`
         *    (resolve)، ويُحترَم `hub_field_mode` (دورٌ حُجب عنه لا يكتبه
         *    ولو حُقن في الطلب) — فالمشاركةُ سلطةٌ لا خانةٌ مفتوحة.
         *  • و«سري» يعلو الجمهورَ في قارئ البوّابة نفسِه (ClientPortalData).
         */
        [
            'key' => 'audience',
            'col' => 'audience',
            'label' => 'الجمهور (المشاركة مع العميل)',
            'type' => 'sel',
            'options' => ['internal', 'client', 'both'],
            'hint' => 'internal: داخليّةٌ فقط (الافتراض) · client: يراها العميلُ المحدَّد أدناه في بوّابته'
                . ' · both: الطرفان. اختر عميلاً مع «client»/«both» وإلا لم تُشارَك مع أحد.',
        ],
        [
            'key' => 'clientId',
            'col' => 'client_id',
            'label' => 'العميل المشارَك معه',
            'type' => 'ref',
            'ref' => 'clients',
            'hint' => 'هدفُ المشاركة: حساباتُ هذا العميل وحدَها ترى الوثيقةَ في البوّابة.',
        ],
        [
            'key' => 'link',
            'col' => 'link',
            'label' => 'رابط خارجي (Drive...)',
            'type' => 'url',
        ],
        [
            'key' => 'att',
            'col' => 'att_id',
            'label' => 'مرفق (حتى 300KB)',
            'type' => 'file',
        ],
        [
            'key' => 'issueDate',
            'col' => 'issue_date',
            'label' => 'تاريخ الإصدار',
            'type' => 'date',
        ],
        [
            'key' => 'expiry',
            'col' => 'expiry',
            'label' => 'تاريخ الانتهاء',
            'type' => 'date',
            'expiry' => true,
        ],
        [
            'key' => 'issuer',
            'col' => 'issuer',
            'label' => 'الجهة المصدرة',
            'type' => 'text',
        ],
        [
            'key' => 'docNo',
            'col' => 'doc_no',
            'label' => 'رقم المستند',
            'type' => 'text',
        ],
        [
            'key' => 'docStatus',
            'col' => 'doc_status',
            'label' => 'حالة المستند',
            'type' => 'sel',
            'options' => [
                'فعال',
                'قيد التجديد',
                'منتهي',
                'ملغى',
            ],
        ],
        [
            'key' => 'ownerId',
            'col' => 'owner_id',
            'label' => 'المسؤول',
            'type' => 'ref',
            'ref' => 'users',
        ],
        [
            'key' => 'alert',
            'col' => 'alert',
            'label' => 'تنبيه قبل (يوم)',
            'type' => 'num',
        ],
        [
            'key' => 'desc',
            'col' => 'description',
            'label' => 'وصف الملف',
            'type' => 'ta',
        ],
        [
            'key' => 'tags',
            'col' => 'tags',
            'label' => 'وسوم',
            'type' => 'tags',
        ],
    ],
    'search' => [
        'name',
        'folder',
        'ver',
        'issuer',
        'doc_no',
    ],
];

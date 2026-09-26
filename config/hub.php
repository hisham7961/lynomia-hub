<?php
/**
 * سجل وحدات النظام — مصدر الحقيقة الوحيد (مطابق لـ MODS في الواجهة).
 * كل شيء مبني عليه: التحقق، الصلاحيات، الفهرسة، التصدير، البحث، والـAPI.
 */
return [
    'version' => trim(@file_get_contents(base_path('VERSION')) ?: '1.0.0'),

    /*
     * **إذنُ الأوامر الهادمة.** افتراضُه `false`: `migrate:fresh` و`migrate:reset`
     * و`db:wipe` و`migrate:rollback` مرفوضةٌ كلُّها — فحرفان يفرقان بين تحديثٍ
     * ومحوٍ كامل، والسهوُ لا يُصلَح بعد وقوعه. ولمن أرادها عمداً وبنسخةٍ في يده:
     * HUB_ALLOW_DESTRUCTIVE=1 في .env
     */
    'allow_destructive' => (bool) env('HUB_ALLOW_DESTRUCTIVE', false),
    // HUB_OUTBOUND=off يُطفئ كلَّ نداءٍ خارجيّ (تلجرام، بريد، ويبهوك، مراقبة، استكشاف، أودو) — لنسخة تجريبية أو تحليلٍ محليّ (v2.399)
    'outbound' => env('HUB_OUTBOUND', 'on'),

    /*
     * **بذرُ المالكِ الأوّل (AUDIT-9 · v2.474.1).** لا كلمةَ مرورٍ متوقّعةً في المصدر:
     * `CoreSeeder` يقرأ بيانةَ الاعتماد من هنا (اصطلاحُ config لا env متناثرٌ في الكود).
     * في الإنتاج يجب أن تُهيَّأ `LYNOMIA_INITIAL_ADMIN_PASSWORD` صراحةً وإلّا يفشل البذرُ
     * بوضوحٍ بدلاً من إنشاء حسابٍ مميّزٍ بكلمةٍ معروفة. البريدُ والاسمُ اختياريّان.
     */
    'bootstrap' => [
        'owner_email'    => env('LYNOMIA_INITIAL_ADMIN_EMAIL', 'owner@lynomia.com'),
        'owner_name'     => env('LYNOMIA_INITIAL_ADMIN_NAME', 'غيث'),
        'owner_password' => env('LYNOMIA_INITIAL_ADMIN_PASSWORD'),
    ],

    /*
     * ── سجلُّ الوحدات (docs/REORG_PLAN.md §R4) ──
     *
     * كلُّ وحدةٍ في ملفٍّ تحت resources/registry/modules/ — **خارجَ config/** عمداً: Laravel يحمّل
     * كلَّ ملفّ PHP تحت config/ مفتاحاً منقّطاً، فأجزاءٌ هناك كانت ستُحمَّل مرّتين. **والقائمةُ صريحةٌ
     * مرتّبةٌ لا اكتشافٌ آليّ:** ترتيبُ الوحدات دلاليٌّ (OpenAPI والتنقّل يقرآنه بترتيب الإعلان)،
     * ولقطةُ السجلّ (tests/Fixtures/structure/registry.json) تُسقط أيَّ تغييرٍ في الترتيب أو التعريف.
     * وحدةٌ جديدة: ملفٌّ جديد + سطرٌ في موضعه من القائمة.
     */
    'modules' => (static function (): array {
        $dir = dirname(__DIR__) . '/resources/registry/modules/';
        $out = [];
        foreach ([
            'companies',
            'projects',
            'apps',
            'code',
            'websites',
            'domains',
            'servers',
            'accounts',
            'emails',
            'phones',
            // ── سجلُّ مزوّدي الاتصالات (Carrier Registry) — Work OS · الطور G · WP-G.2 · §22/§23 ──
            // كيانٌ داخليٌّ فوق ModuleController (لا CarrierController). عزلُ الشركات عبر
            // companyId ref→companies (فخياراتُ carrier_id في الخطّ تُنطَّق بالشركة تلقائياً).
            // **داخليٌّ فقط:** بلا client_id — ممنوعٌ على حساب العميل عبر PortalGuard.
            // **صدقُ التأجيل (النقد C15):** السجلُّ config-only يحرّره مسؤولٌ يدوياً — لا
            // توفيرٌ حيٌّ عبر APIs المشغّلين (تفعيل/تعليق SIM حقيقيّ)؛ ذلك يتطلب اتفاقيةَ
            // مشغّلٍ وواجهتَه ولم يُبنَ. لا زرَّ «تفعيل» زائفٌ ولا حالةَ تكاملٍ مزعومة.
            'carriers',
            'vault',
            'tasks',
            /*
             * **تحديثات العمل = بنودُ التقرير اليومي**: بندٌ لكل عملٍ في اليوم —
             * موظفٌ عمل على ثلاثة مشاريع كتب ثلاثة بنود، لكلٍّ مهمتُه وساعاتُه
             * وتاريخُه. والساعاتُ تغذّي «الوقت الفعلي» على المهمة تلقائياً فتعمل
             * الربحيةُ والقدراتُ بلا إدخالٍ مزدوج (انظر WorkUpdate::booted).
             */
            'updates',
            'issues',
            'files',
            'subs',
            'meetings',
            'decisions',
            'approvals',
            'social',
            'posts',
            'fin',
            'accounts2',
            'entries',
            /*
             * **الارتباطات (Engagements)** — الطبقةُ المنظِّمة بين العميل ومشاريعه:
             * عميلٌ واحد قد نديرُ له IT ونبني متجرَه ونشغّل تسويقَه — ثلاثةُ
             * ارتباطاتٍ لكلٍّ عقودُه ومشاريعُه وفوترتُه وتجديدُه. والقاعدة:
             * «تُدار لدينا» ≠ «ملكُنا» — الارتباطُ سياقُ إدارةٍ لا تملّك.
             */
            'engagements',

            /*
             * ─── العمليات الميدانية (المندوب الطبي) ─────────────────────────
             * دليلُ من نزور وأين، وهرميةُ من يغطي ماذا. **بلا أي حقلٍ بيعيّ
             * على الطبيب** — لا باركود ولا إحالة ولا عمولة: قاعدةُ منتجٍ
             * يفرضها FieldForceGuardrailsTest نصاً.
             */
            'hcps',

            'facilities',

            'territories',

            'terrassigns',

            'cycles',

            'visits',

            'clients',
            'services',
            'contracts',
            /*
             * **سجل المنتجات (Product Master)** — الطرازُ لا القطعة: «Dell Latitude
             * 5550» صفٌّ واحدٌ هنا بكوده الدائم LYN-PRD، وكلُّ قطعةٍ مملوكةٍ منه
             * صفٌّ في `assets` يشير إليه بحقل «المنتج». الباركود العالمي (GTIN)
             * يعرّف هذا الصفَّ — لا القطعةَ — وبقيةُ المعرفات في سجل الهوية.
             */
            'products',

            'assets',
            // ── المحطات (Work OS · الطور F · WP-F.1 · §25–27) — المقعدُ الدائم ──
            // وحدةٌ مُدارةٌ بالبيانات فوق ModuleController (CRUD/scope مجّاناً)، **داخليّةٌ
            // فقط**: بلا حقلِ عميلٍ عمداً — فلا يعزلها hub_scope بعميل، والعزلُ الصلبُ عن
            // حساب العميل في PortalGuard (ليست في قائمته البيضاء → ٤٠٤). العزلُ بين
            // الشركات عبر حقل companyId (رصيفُ hub_company_col). الكودُ وcurrent_employee
            // مقفلان: الأولُ يُولَّد (Station::nextCode)، والثاني يُكتَب عبر مسار الإسناد
            // المقفل وحدَه (StationController على نمط Custody::move) — لا CRUD عامٌّ عليه.
            'stations',
            /*
             * (Work OS · الطور J · WP-J.1 · §43) **النقاط الطرفية** — سجلُّ أجهزة
             * الشركة المسجَّلة بالتسجيل اللاتماثليّ (عقدُ Es256). وحدةٌ **داخليّةٌ
             * تميل للقراءة**: لا `client_id` (العميلُ ٤٠٤ عبر PortalGuard — قائمةٌ
             * بيضاءُ لا تضمّها)، والحقولُ الآليّة (الهويّة/المفتاح/الحالة/النبض)
             * **مقفولة**: يكتبها مسارُ التسجيل (WP-J.1) وheartbeat/الأوامرُ خلف
             * step-up (WP-J.2) لا نموذجُ CRUD العامّ؛ شاشاتُ المركز في WP-J.3.
             * NOTE openapi: وحدةٌ جديدة تُغيّر المواصفة — تُولَّد بـhub:openapi
             * في خطوة التحقّق المركزية لا تُحرَّر يدوياً.
             */
            'endpoints',
            'assetlog',
            'stock',
            'hr',
            'leaves',
            'banks',
            'okrs',
            'krs',
            'kb',
            'autos',
            'dbs',
            'apis',
            'quotes',
            // أوامرُ التغيير (CPQ ج): تغييرٌ تجاريٌّ يمدّد خطَّ أساس المشروع بعد اعتماده
            'changeorders',
            'budgets',
            'costc',
            'recur',
            'stockmv',
            'attend',
            'payroll',
            'recruit',
            'hrlog',
            'rules',
            'feats',
            'designs',
            'tickets',
            'users',

            'suppliers',
            'purchases',

            'changes',
            'skills',

            'policies',

            // v2.123: التزامات العقود — قيدُها في السجل يشتري القوائم والتقويم
            // ورادار الانتهاء وقواعد التنبيه وواجهة API العامة بلا سطرٍ إضافي
            'obligations',

            'compliance',

            'ideas',

            'policyacks',

            'incidents',

            'deploys',

            'restores',

            'requests',

            'deps',

            'competitors',

            'brands',

            'media',

            'ip',

            'events',

            'plans',

        ] as $key) {
            $out[$key] = require $dir . $key . '.php';
        }

        return $out;
    })(),

    /*
     * تصنيف المستندات المالية — كان معرَّفاً أربع مرات متطابقة في CeoController
     * وPerformanceController وReportController وhelpers. تعريفٌ واحد هنا يمنع
     * انزلاق تقريرين إلى رقمين مختلفين للربح نفسه.
     */
    'fin' => [
        'income'  => ['فاتورة مبيعات', 'دفعة واردة'],
        'expense' => ['مصروف', 'فاتورة مشتريات', 'دفعة صادرة'],
        'dead'    => ['ملغاة', 'مسودة'],
    ],

    /*
     * محرك الإجازات: أنواع الطلبات التي تُعدّ **غياباً** — وحدها تُخصم من رصيد
     * الإجازات عند الاعتماد، ووحدها تحجز التواريخ في حارس التداخل. الباقي
     * (إذن خروج، عمل عن بعد، سلفة، شهادة راتب، أخرى) طلباتٌ لا تُنقص الرصيد ولا
     * تمنع إجازةً حقيقية. القيم مأخوذة من `options` المصرَّح بها في وحدة leaves.
     */
    'leave' => [
        'deduct_types' => ['إجازة سنوية', 'إجازة مرضية', 'إجازة طارئة'],
    ],

    /*
     * خريطة الأحداث الدلالية.
     *
     * الحدث الخام (`created`/`updated`/`status`) يبقى يُبثّ كما هو دائماً؛ هذه الخريطة
     * تُضيف فوقه اسماً يفهمه صاحب العمل: بدل أن يكتب كل مشترك مطابقةَ نصٍّ لـ«مدفوعة»
     * بمفرداته، يشترك على `invoice.paid` مرة واحدة.
     *
     * `on`   الحدث الخام المشتقّ منه.
     * `to`   قيمة الحالة المطلوبة (مقارنة مُطبَّعة تتجاهل التشكيل) — بلا `to` يُطابق أي تحوّل.
     * `emit` الاسم الدلالي المبثوث.
     *
     * كل قيمة أدناه مأخوذة من `options` المُصرَّح بها في الوحدة نفسها — لا اسم مخترع
     * لحدثٍ لا يقع.
     */
    'events' => [
        'fin' => [
            ['on' => 'status', 'to' => ['مدفوعة'], 'emit' => 'invoice.paid', 'label' => 'سُدّدت فاتورة'],
            ['on' => 'status', 'to' => ['متأخرة'], 'emit' => 'invoice.overdue', 'label' => 'تأخّرت فاتورة'],
        ],
        // (Work OS · الطور E · WP-E.2 · §21a/b) أحداثُ العهدة المالية — تُطلقها
        // خدمةُ الترحيل المشترَكة `CustodyPostingService` عبر FlowRunner::fire لا
        // تحوّلَ حالةٍ في سجلٍّ، فبلا `to` (حاويةُ حركاتٍ لا وحدةَ CRUD بعد — مسارُها
        // وتسجيلُها كوحدةٍ في WP-E.3). NOTE openapi: أسماءٌ دلاليّةٌ جديدة.
        'custody' => [
            ['on' => 'charged',  'emit' => 'custody.charged',  'label' => 'شُحنت عهدةُ موظف'],
            ['on' => 'approved', 'emit' => 'custody.approved', 'label' => 'اعتُمد مصروفُ عهدة'],
            ['on' => 'reversed', 'emit' => 'custody.reversed', 'label' => 'عُكست حركةُ عهدة'],
        ],
        // (Work OS · الطور F · WP-F.1 · §26) أحداثُ المحطة — يُطلقها
        // StationController::assign/vacate عبر FlowRunner::fire داخلَ معاملةِ الإسنادِ
        // المقفلة (نمطُ custody أعلاه: بلا `to` — حركةُ مقعدٍ لا تحوّلَ حالةٍ في CRUD).
        // NOTE openapi: أسماءٌ دلاليّةٌ جديدة (station.assigned/station.vacated).
        'stations' => [
            ['on' => 'assigned', 'emit' => 'station.assigned', 'label' => 'أُسنِدت محطةٌ لموظف'],
            ['on' => 'vacated',  'emit' => 'station.vacated',  'label' => 'أُخليت محطة'],
        ],
        // (Work OS · الطور F · WP-F.3 · §32) حدثُ الجرد — يُطلقه InventoryController::close
        // عبر FlowRunner::fire (بلا `to` — إغلاقُ جلسةٍ لا تحوّلَ حالةٍ في CRUD؛ الجلسةُ ليست
        // وحدةَ hub.modules). NOTE openapi: اسمٌ دلاليٌّ جديد (inventory.session_closed).
        'inventory' => [
            ['on' => 'session_closed', 'emit' => 'inventory.session_closed', 'label' => 'أُغلقت جلسةُ جرد'],
        ],
        // (Work OS · الطور I · WP-I.2 · §41/§68) حدثُ الحظر الآليّ — يُطلقه
        // AlertEngine::autoBlock عبر FlowRunner::fire عند **إنشاء** قاعدةِ حظرٍ
        // آليّة (origin=auto) لا مع كل تقييم (نمطُ custody: بلا `to` — إنشاءُ
        // قاعدةٍ لا تحوّلَ حالةٍ في CRUD؛ `ip_rules` ليست وحدةَ hub.modules).
        // الاسمُ الدلاليّ كما تسمّيه المواصفة (§68): `ip_auto_blocked` حرفياً.
        // NOTE openapi: اسمٌ دلاليٌّ جديد (ip_auto_blocked).
        'ip_rules' => [
            ['on' => 'auto_blocked', 'emit' => 'ip_auto_blocked', 'label' => 'حُظر عنوانُ IP آلياً (تصعيدٌ متدرّج)'],
        ],
        // (Work OS · الطور J · WP-J.1 · §43) حدثُ تسجيل جهازٍ طرفيّ — يُطلقه
        // EndpointEnrollController::enroll عبر FlowRunner::fire داخلَ معاملةِ
        // الاستهلاك الذرّيّ نفسِها (نمطُ custody: بلا `to` — تسجيلٌ لا تحوّلَ
        // حالةٍ في CRUD). NOTE openapi: اسمٌ دلاليٌّ جديد (endpoint.enrolled).
        'endpoints' => [
            ['on' => 'enrolled', 'emit' => 'endpoint.enrolled', 'label' => 'سُجّل جهازٌ طرفيّ'],
            // (WP-J.2) يُطلقهما ابتلاعُ الأحداث الموقَّع (EndpointProtocolController::event):
            // `usb` مع كل حدثِ USB مبلَّغ، و`posture_alert` حين تعلو شدّةُ حدثِ
            // الوضعيّة (warning/high) — العتباتُ الأغنى وأكوادُ SecurityEvents
            // ENDPOINT_* في WP-J.3 (بعتباتٍ لا لكل حدث). NOTE openapi: اسمان
            // دلاليّان جديدان (endpoint.usb_event / endpoint.posture_alert).
            ['on' => 'usb_event', 'emit' => 'endpoint.usb_event', 'label' => 'حدثُ USB على جهازٍ طرفيّ'],
            ['on' => 'posture_alert', 'emit' => 'endpoint.posture_alert', 'label' => 'إنذارُ وضعيّةِ جهازٍ طرفيّ'],
        ],
        'contracts' => [
            ['on' => 'status', 'to' => ['ساري'], 'emit' => 'contract.signed', 'label' => 'سرى عقد'],
            ['on' => 'status', 'to' => ['منتهي'], 'emit' => 'contract.expired', 'label' => 'انتهى عقد'],
            // v2.121: يطلقهما متحكم الموافقات المرحلية — اكتمال سلسلة الاعتماد أو رفض مرحلة
            ['on' => 'approved', 'emit' => 'contract.approved', 'label' => 'اكتملت موافقات عقد'],
            ['on' => 'approval_rejected', 'emit' => 'contract.approval_rejected', 'label' => 'رُفضت موافقة عقد'],
            // v2.123: يطلقه إجراء التجديد — العقد الأصلي دخل «قيد التجديد» بمسودة تجديد
            ['on' => 'renewed', 'emit' => 'contract.renewed', 'label' => 'بدأ تجديد عقد'],
        ],
        'tasks' => [
            ['on' => 'status', 'to' => ['مكتملة', 'منجزة'], 'emit' => 'task.completed', 'label' => 'أُنجزت مهمة'],
        ],
        'projects' => [
            ['on' => 'status', 'to' => ['مكتمل'], 'emit' => 'project.completed', 'label' => 'اكتمل مشروع'],
            ['on' => 'status', 'emit' => 'project.status_changed', 'label' => 'تغيّرت حالة مشروع'],
            // (Work OS · الطور D · WP-D.4 · §63–73) توفيرُ المشروع: يُطلقه
            // QuoteController::toProject **داخلَ** معاملةِ التحويلِ المقفلةِ نفسِها (لا مسارَ
            // توفيرٍ ثانٍ) مرّةً واحدةً بجانبِ quote.converted — تحت حارسِ meta.project_id
            // (قبولٌ مكرَّرٌ يعود قبله)، فتعمل عليه حِزمُ onboarding والتدفّقاتُ كأيّ حدث.
            // الخامُّ `provisioned` على وحدة projects يشتقّ الدلاليَّ المُصرَّحَ هنا.
            ['on' => 'provisioned', 'emit' => 'project.provisioned', 'label' => 'وُفِّر مشروعٌ من عرضٍ محوّل'],
        ],
        'quotes' => [
            ['on' => 'status', 'to' => ['مُرسل'], 'emit' => 'quote.sent', 'label' => 'أُرسل عرض سعر للعميل'],
            ['on' => 'status', 'to' => ['مقبول'], 'emit' => 'quote.accepted', 'label' => 'قُبل عرض سعر'],
            ['on' => 'status', 'to' => ['مرفوض'], 'emit' => 'quote.rejected', 'label' => 'رُفض عرض سعر'],
            ['on' => 'status', 'to' => ['محوّل'], 'emit' => 'quote.converted', 'label' => 'حُوّل عرضٌ إلى مشروع'],
        ],
        // أوامر التغيير (CPQ ج): اعتمادٌ ثم تطبيقٌ يمدّد خطَّ أساس المشروع
        'changeorders' => [
            ['on' => 'status', 'to' => ['معتمد'], 'emit' => 'changeorder.approved', 'label' => 'اعتُمد أمرُ تغيير'],
            ['on' => 'status', 'to' => ['مطبَّق'], 'emit' => 'changeorder.applied', 'label' => 'طُبّق أمرُ تغيير على المشروع'],
        ],
        'engagements' => [
            ['on' => 'status', 'to' => ['نشط'], 'emit' => 'engagement.started', 'label' => 'بدأ ارتباطُ عميل'],
            ['on' => 'status', 'to' => ['قيد التجديد'], 'emit' => 'engagement.renewing', 'label' => 'ارتباطٌ قيد التجديد'],
            ['on' => 'status', 'to' => ['منتهٍ', 'ملغى'], 'emit' => 'engagement.ended', 'label' => 'انتهى ارتباطُ عميل'],
        ],
        // العمليات الميدانية: أحداثٌ دلالية تُطلق حِزم الاستجابة والتنبيهات
        'cycles' => [
            ['on' => 'status', 'to' => ['نشط'], 'emit' => 'cycle.started', 'label' => 'بدأت دورةٌ ميدانية'],
            ['on' => 'status', 'to' => ['منتهٍ', 'ملغى'], 'emit' => 'cycle.closed', 'label' => 'أُغلقت دورةٌ ميدانية'],
        ],
        'visits' => [
            ['on' => 'status', 'to' => ['تمت'], 'emit' => 'visit.completed', 'label' => 'تمّت زيارةٌ ميدانية'],
            ['on' => 'status', 'to' => ['فائتة'], 'emit' => 'visit.missed', 'label' => 'فُوّتت زيارةٌ مخطّطة'],
        ],
        'purchases' => [
            ['on' => 'status', 'to' => ['معتمد'], 'emit' => 'purchase.approved', 'label' => 'اعتُمد أمر شراء'],
            ['on' => 'status', 'to' => ['مستلم'], 'emit' => 'purchase.received', 'label' => 'استُلم أمر شراء'],
        ],
        'leaves' => [
            ['on' => 'status', 'to' => ['معتمد'], 'emit' => 'leave.approved', 'label' => 'اعتُمدت إجازة'],
            ['on' => 'status', 'to' => ['مرفوض'], 'emit' => 'leave.rejected', 'label' => 'رُفضت إجازة'],
        ],
        'issues' => [
            ['on' => 'status', 'to' => ['محلولة', 'مغلقة'], 'emit' => 'issue.resolved', 'label' => 'حُلّت مشكلة'],
        ],
        'deploys' => [
            ['on' => 'status', 'to' => ['فشل'], 'emit' => 'deploy.failed', 'label' => 'فشل نشر'],
            ['on' => 'status', 'to' => ['متراجع عنه'], 'emit' => 'deploy.rolled_back', 'label' => 'تراجعٌ عن نشر'],
        ],
        // الحوادث كانت بلا حدثٍ دلاليّ واحد بينما لنظيراتها أحداثها — فيضطر
        // المشترك الخارجي (n8n) لمطابقة نصّ الحالة بنفسه
        'incidents' => [
            ['on' => 'created', 'emit' => 'incident.opened', 'label' => 'فُتحت حادثة'],
            ['on' => 'status', 'to' => ['مُحتوى'], 'emit' => 'incident.contained', 'label' => 'احتُويت حادثة'],
            ['on' => 'status', 'to' => ['مُستعاد'], 'emit' => 'incident.resolved', 'label' => 'استُعيدت الخدمة'],
            ['on' => 'status', 'to' => ['مغلق بتقرير'], 'emit' => 'incident.closed', 'label' => 'أُغلقت حادثة بتقرير'],
        ],
        // **حِزم الاستجابة الأمنية**: أفعالُ الأمن الحرجة تُطلق أحداثاً دلالية
        // فتعمل عليها التدفقاتُ (تنبيه/تليجرام/مهمة) كأي حدثٍ آخر — لا محرك ثانٍ.
        'users' => [
            ['on' => 'sessions_revoked', 'emit' => 'user.sessions_revoked', 'label' => 'أُنهيت جلساتُ مستخدم'],
            // (Work OS · الطور B · WP-B.1 · §12) فعّل عميلٌ حسابَه ووضع كلمتَه بنفسه —
            // يُطلقه ActivationController::set داخلَ معاملة التفعيل، فتعمل عليه التدفّقاتُ كأيّ حدث.
            ['on' => 'account_activated', 'emit' => 'client.account_activated', 'label' => 'فعّل عميلٌ حسابَه'],
        ],
        // (Work OS · الطور B · WP-B.3 · §13/§98) عضويّةُ العميل: يُطلقهما
        // ClientMemberController (منح/سحب) فيدخلان سلسلةَ التدقيق وتعمل عليهما
        // التدفّقاتُ كأيّ حدثٍ آخر — لا محرّكَ أحداثٍ ثانٍ. (client_memberships ليست
        // وحدةَ سجلٍّ فلا يطالها تدفّقُ hub_mod؛ يكفي الاشتقاقُ الدلاليّ + hub_audit.)
        'client_memberships' => [
            ['on' => 'granted', 'emit' => 'client_membership_granted', 'label' => 'مُنِح عضوٌ عميلٌ دوراً'],
            ['on' => 'revoked', 'emit' => 'client_membership_revoked', 'label' => 'سُحب وصولُ عضوِ عميل'],
        ],
        // (Work OS · الطور B · WP-B.4 · §63/§98) توفيرُ مساحةِ العميل الآليّ: يُطلقه
        // QuoteController::toProject **داخلَ** معاملةِ التحويلِ المقفلة (لا مسارَ ثانٍ)
        // عند صيرورةِ عرضٍ مشروعاً، فتعمل عليه حِزمُ onboarding والتدفّقاتُ كأيّ حدث.
        // الخامُّ `workspace_created` على وحدة clients يشتقّ الدلاليَّ المُصرَّحَ هنا.
        'clients' => [
            ['on' => 'workspace_created', 'emit' => 'client_workspace_created', 'label' => 'أُنشئت مساحةُ عميلٍ آليّاً عند قبولِ عرض'],
        ],
        'vault' => [
            ['on' => 'revealed', 'emit' => 'vault.revealed', 'label' => 'كُشف سرٌّ من الخزنة'],
        ],
        // ملحوظة: تصعيدُ الأدوار ليس هنا — «roles» يديره RoleController لا سجلُّ
        // الوحدات، فلا تدفّقَ يطاله (hub_mod('roles') = null). وله حراسُه القويّة
        // أصلاً: Step-Up قبل التصعيد + أثرُ تدقيقٍ «قبل/بعد» + إشعارُ مديري المستخدمين.
        'changes' => [
            ['on' => 'status', 'to' => ['معتمد'], 'emit' => 'change.approved', 'label' => 'اعتُمد تغيير تقني'],
            ['on' => 'status', 'to' => ['منفّذ'], 'emit' => 'change.executed', 'label' => 'نُفّذ تغيير تقني'],
            ['on' => 'status', 'to' => ['متراجع عنه'], 'emit' => 'change.rolled_back', 'label' => 'تراجعٌ عن تغيير'],
        ],
        'restores' => [
            ['on' => 'status', 'to' => ['فشلت'], 'emit' => 'backup.restore_failed', 'label' => 'فشلت استعادة نسخة'],
        ],
        'ideas' => [
            ['on' => 'status', 'to' => ['معتمدة'], 'emit' => 'idea.approved', 'label' => 'اعتُمدت فكرة'],
        ],
        // ── Work OS: الطور A (WP-A.4 · SF-3) ── حاويةُ المحادثة تكسب حدثَها
        // الدلاليّ الآن ليُبنى عليه في الطور C — يُطلَق هناك عند إنشاء محادثة عبر
        // FlowRunner::fire('created','conversations',$m)، لا هنا. حاويةُ أحداثٍ لا
        // وحدةُ سجلٍّ (لا شاشةَ، لا مسار)، فبلا `to`: لا مجموعةَ حالاتٍ لغيرِ وحدة.
        'conversations' => [
            ['on' => 'created', 'emit' => 'conversation.created', 'label' => 'أُنشئت محادثةٌ أو قناة'],
        ],
    ],

    /*
     * ── تصنيفُ مزامنةِ الجوال لكلِّ وحدة (Mobile Readiness · الطور C · SF-5 · §109) ──
     *
     * خريطةٌ إضافيّةٌ (لا تمسّ سجلَّ الوحدات ولا عقدَ `/api/v1`) تُعلن **هل** — وكيف —
     * تُخبَّأ بياناتُ كلِّ وحدةٍ على جهاز الجوال. تقودُ مخطّطَ الطور C.4 ومحرّكَ
     * المزامنة في الطور G. الأصنافُ الخمسة (INVENTORY §9):
     *
     *  • `CACHEABLE_INCREMENTAL` — الشكلُ القياسيّ (HasVersions + SoftDeletes +
     *    الطوابعُ الزمنية): مزامنةٌ تراكميّةٌ بـ`updated_since`، ترتيبٌ حتميٌّ
     *    `updated_at,id`, صفوفُ `deleted_at` = شواهدُ حذفٍ (tombstones)، و`version`
     *    رمزُ التعارض. ٨١ من ٨٥ وحدةً على هذا الشكل بالضبط.
     *  • `CACHEABLE_READ_ONLY` — بلا `version` (لا رمزَ تعارض) أو دليلٌ مرجعيٌّ
     *    يُقرأ لا يُكتب: مؤشّرٌ بـ`created_at`/`updated_at`+`id`، لا كتابةَ جوالٍ
     *    عليه. (`users`: بلا HasVersions — دليلٌ يُقرأ فقط من الجوال.)
     *  • `ONLINE_ONLY` — يُقرأ مباشرةً من الخادم ولا يُخبَّأ سجلّاً (تدفّقاتٌ
     *    ملحقةٌ فقط، أو ما لم يُصنَّف بعدُ — **الافتراضُ الآمن**).
     *  • `SENSITIVE_NO_PERSIST` — لا يُكتَب على خبيئة الجهاز أبداً: وحداتٌ حاملةُ
     *    أسرار (`sec`): `vault` (خزنةُ الأسرار)، `phones`/`carriers` (اعتمادُ
     *    شرائحَ/بوّاباتِ مشغّلين). الحمولةُ لا تُخبَّأ ولا يحمل الدفعُ محتواها.
     *  • `NOT_APPLICABLE` — لا مزامنةَ لها أصلاً (يُحجَز للأطوار اللاحقة عند الحاجة).
     *
     * **قاعدةُ الأمان:** ما ليس في هذه الخريطة صراحةً يسقط إلى `default`
     * (`ONLINE_ONLY`) — **لا يُفترَض قابلاً للتخبئة أبداً**. فوحدةٌ جديدةٌ تُضاف
     * لسجلِّ الوحدات دون تصنيفٍ هنا لا تُخبَّأ على الأجهزة حتى تُصنَّف عمداً.
     * تُقرأ عبر المساعِد `hub_sync_class($module)` (helpers.php).
     */
    'mobile_sync' => [
        'default' => 'ONLINE_ONLY',   // الافتراضُ الآمن لكلِّ ما لم يُصنَّف — لا تخبئةَ بلا قصد
        'classes' => [
            'CACHEABLE_INCREMENTAL', 'CACHEABLE_READ_ONLY',
            'ONLINE_ONLY', 'SENSITIVE_NO_PERSIST', 'NOT_APPLICABLE',
        ],
        'modules' => [
            'companies'     => 'CACHEABLE_INCREMENTAL',
            'projects'      => 'CACHEABLE_INCREMENTAL',
            'apps'          => 'CACHEABLE_INCREMENTAL',
            'code'          => 'CACHEABLE_INCREMENTAL',
            'websites'      => 'CACHEABLE_INCREMENTAL',
            'domains'       => 'CACHEABLE_INCREMENTAL',
            'servers'       => 'CACHEABLE_INCREMENTAL',
            'accounts'      => 'CACHEABLE_INCREMENTAL',
            'emails'        => 'CACHEABLE_INCREMENTAL',
            'phones'        => 'SENSITIVE_NO_PERSIST',
            'carriers'      => 'SENSITIVE_NO_PERSIST',
            'vault'         => 'SENSITIVE_NO_PERSIST',
            'tasks'         => 'CACHEABLE_INCREMENTAL',
            'updates'       => 'CACHEABLE_INCREMENTAL',
            'issues'        => 'CACHEABLE_INCREMENTAL',
            'files'         => 'CACHEABLE_INCREMENTAL',
            'subs'          => 'CACHEABLE_INCREMENTAL',
            'meetings'      => 'CACHEABLE_INCREMENTAL',
            'decisions'     => 'CACHEABLE_INCREMENTAL',
            'approvals'     => 'CACHEABLE_INCREMENTAL',
            'social'        => 'CACHEABLE_INCREMENTAL',
            'posts'         => 'CACHEABLE_INCREMENTAL',
            'fin'           => 'CACHEABLE_INCREMENTAL',
            'accounts2'     => 'CACHEABLE_INCREMENTAL',
            'entries'       => 'CACHEABLE_INCREMENTAL',
            'engagements'   => 'CACHEABLE_INCREMENTAL',
            'hcps'          => 'CACHEABLE_INCREMENTAL',
            'facilities'    => 'CACHEABLE_INCREMENTAL',
            'territories'   => 'CACHEABLE_INCREMENTAL',
            'terrassigns'   => 'CACHEABLE_INCREMENTAL',
            'cycles'        => 'CACHEABLE_INCREMENTAL',
            'visits'        => 'CACHEABLE_INCREMENTAL',
            'clients'       => 'CACHEABLE_INCREMENTAL',
            'services'      => 'CACHEABLE_INCREMENTAL',
            'contracts'     => 'CACHEABLE_INCREMENTAL',
            'products'      => 'CACHEABLE_INCREMENTAL',
            'assets'        => 'CACHEABLE_INCREMENTAL',
            'stations'      => 'CACHEABLE_INCREMENTAL',
            'endpoints'     => 'CACHEABLE_INCREMENTAL',
            'assetlog'      => 'CACHEABLE_INCREMENTAL',
            'stock'         => 'CACHEABLE_INCREMENTAL',
            // Permissions 360 · 09.3 — سجلّاتُ الأشخاصِ والرواتبِ والحضور لا تُخبَّأ
            // على أجهزةِ الجوال: تُقرأ حيّةً فقط (جهازٌ مفقودٌ لا يحمل ملفَّ موظفين)
            'hr'            => 'ONLINE_ONLY',
            'leaves'        => 'CACHEABLE_INCREMENTAL',
            'banks'         => 'CACHEABLE_INCREMENTAL',
            'okrs'          => 'CACHEABLE_INCREMENTAL',
            'krs'           => 'CACHEABLE_INCREMENTAL',
            'kb'            => 'CACHEABLE_INCREMENTAL',
            'autos'         => 'CACHEABLE_INCREMENTAL',
            'dbs'           => 'CACHEABLE_INCREMENTAL',
            'apis'          => 'CACHEABLE_INCREMENTAL',
            'quotes'        => 'CACHEABLE_INCREMENTAL',
            'changeorders'  => 'CACHEABLE_INCREMENTAL',
            'budgets'       => 'CACHEABLE_INCREMENTAL',
            'costc'         => 'CACHEABLE_INCREMENTAL',
            'recur'         => 'CACHEABLE_INCREMENTAL',
            'stockmv'       => 'CACHEABLE_INCREMENTAL',
            'attend'        => 'ONLINE_ONLY',   // 09.3 — كسجلّاتِ hr أعلاه
            'payroll'       => 'ONLINE_ONLY',
            'recruit'       => 'CACHEABLE_INCREMENTAL',
            'hrlog'         => 'ONLINE_ONLY',
            'rules'         => 'CACHEABLE_INCREMENTAL',
            'feats'         => 'CACHEABLE_INCREMENTAL',
            'designs'       => 'CACHEABLE_INCREMENTAL',
            'tickets'       => 'CACHEABLE_INCREMENTAL',
            // «users» غيرُ قابلٍ للمزامنة صدقاً (Hardener G · Finding#1): عقدُ v1 المجمّد
            // يرفضه في resolveApi (RESOURCE_NOT_FOUND) لأنّ مُشكّلَ الأعمال لم يُصمَّم
            // لقناعِ أعمدته الداخليّة (allowed_ips/prefs/totp_secret_cipher). فتصنيفُه
            // NOT_APPLICABLE كي يتّفقَ المخطّطُ (schema) والمزامنةُ: كلاهما يعلن «غيرُ
            // قابلٍ للتخبئة» بلا سجلّ — دليلُ المستخدمين يأتي من context/bootstrap (C.1/C.2).
            'users'         => 'NOT_APPLICABLE',
            'suppliers'     => 'CACHEABLE_INCREMENTAL',
            'purchases'     => 'CACHEABLE_INCREMENTAL',
            'changes'       => 'CACHEABLE_INCREMENTAL',
            'skills'        => 'CACHEABLE_INCREMENTAL',
            'policies'      => 'CACHEABLE_INCREMENTAL',
            'obligations'   => 'CACHEABLE_INCREMENTAL',
            'compliance'    => 'CACHEABLE_INCREMENTAL',
            'ideas'         => 'CACHEABLE_INCREMENTAL',
            'policyacks'    => 'CACHEABLE_INCREMENTAL',
            'incidents'     => 'CACHEABLE_INCREMENTAL',
            'deploys'       => 'CACHEABLE_INCREMENTAL',
            'restores'      => 'CACHEABLE_INCREMENTAL',
            'requests'      => 'CACHEABLE_INCREMENTAL',
            'deps'          => 'CACHEABLE_INCREMENTAL',
            'competitors'   => 'CACHEABLE_INCREMENTAL',
            'brands'        => 'CACHEABLE_INCREMENTAL',
            'media'         => 'CACHEABLE_INCREMENTAL',
            'ip'            => 'CACHEABLE_INCREMENTAL',
            'events'        => 'CACHEABLE_INCREMENTAL',
            'plans'         => 'CACHEABLE_INCREMENTAL',
        ],
    ],

    /*
     * ── إعداداتُ تطبيق الجوال + بوّابةُ الإصدار (Mobile Readiness · الطور C · SF-5) ──
     *
     * **الشكلُ وافتراضيّاتُه الآمنة** لِما تعرضه `GET app-config` (قبل الدخول، بلا
     * سرّ). القيمُ التشغيليّةُ الحيّةُ (الحدُّ الأدنى/الأحدثُ للإصدار، الإجبار،
     * روابطُ المتجر) تُقرأ عبر `setting('mobile.*', <الافتراضُ هنا>)` — فالإعدادُ
     * يغلب الافتراض، وهذا الملفُّ يوثّق العقدَ وافتراضَه المشحون.
     *
     * **بوّابةُ الإصدار فارغةٌ عمداً:** فراغُ الحدِّ الأدنى ⇒ **لا حجبَ أبداً** —
     * نسخُ التطوير لا تُحجَب حتى يُضبط حدٌّ صراحةً (spec §Version gate). ولا سرَّ
     * هنا؛ والقيمةُ الخارجيّةُ الغائبة (رابطُ متجرٍ/دعمٍ) تُعاد `null` صادقةً
     * (NOT_CONFIGURED) لا مُختلَقة.
     */
    'mobile' => [
        'api_version' => '1',   // = App\Support\Platform\Api::VERSION
        'version_gate' => [
            // فارغٌ = لا حدَّ = لا حجب. يُقرأ الحيُّ من setting('mobile.min_version_ios') ...
            'ios'          => ['min' => '', 'latest' => ''],
            'android'      => ['min' => '', 'latest' => ''],
            'force_update' => false,   // setting('mobile.force_update')
        ],
        'store_urls' => [
            'ios'     => '',   // setting('mobile.store_url_ios')
            'android' => '',   // setting('mobile.store_url_android')
        ],
        // رابطُ الدعم يُقرأ من setting('mobile.support_url') (مفتاحٌ قائمٌ منذ الطور B)

        // إجراءاتٌ تتطلّب تصعيدَ مصادقةٍ (Step-Up) في الجوال — الطور D · D.3. الأنماط:
        // "module:action" | "module:*" | "*:action" (action ∈ status|restore|restore-version|ack).
        // **فارغٌ افتراضاً = لا تصعيد لإجراءٍ عامّ** (نظيرُ الويب الذي لا يُصعّد إجراءَ الحالة) —
        // فلا يُفرَض تأكيدُ هويّةٍ حيث لا يفرضه الويبُ (لا سطحَ جوالٍ أشدَّ ولا أضعف بلا سبب).
        'stepup_actions' => [],

        // ── دفعُ الجوال (Mobile Readiness · الطور E · spec §Push) ──
        // السائقُ فارغٌ افتراضاً ⇒ `NullPushProvider` ⇒ **NOT_CONFIGURED صدقاً** (لا
        // نجاحٌ مُزيَّف). الاعتماداتُ الحقيقيّة (`project_id`/رمزُ الوصول) **إعدادٌ خارجيٌّ**
        // يُقرأ الحيُّ من `setting('mobile.push_driver'|'mobile.push_fcm_project_id'|
        // 'mobile.push_fcm_access_token')` — يُوثَّق ولا يُختلَق، ولا مفتاحَ خاصٌّ هنا.
        'push' => [
            'driver' => '',                    // '' | 'fcm' — setting('mobile.push_driver')
            'fcm'    => [
                'project_id' => '',            // setting('mobile.push_fcm_project_id') — حضورٌ لا سرّ
            ],
        ],

        // ── المزامنةُ التزايُديّة (Mobile Readiness · الطور G · G.1) ──
        // حجمُ صفحةِ `GET sync/{module}`: الافتراضُ حين لا يطلب العميلُ `?limit=`،
        // والحدُّ الأقصى الذي لا يتجاوزه مهما طلب (تدفّقٌ عالي الحجم لا يُغرِق الخادمَ
        // بصفحةٍ ضخمة). التصنيفُ نفسُه في `hub.mobile_sync` (أعلاه) — هذا حجمُ الصفحةِ فقط.
        'sync' => [
            'default_limit' => 100,            // صفحةٌ افتراضيّةٌ معقولة
            'max_limit'     => 500,            // سقفٌ صلبٌ للصفحة الواحدة
        ],

        // ── الروابطُ العالميّة (Universal Links / App Links) — سقالةٌ مُهيّأةٌ لا مُختلَقة (الطور H · H.3) ──
        // **كلُّ المعرّفاتِ الخارجيّة NOT_CONFIGURED** حتى يُصدرها فريقُ التطبيق: معرّفُ الفريق
        // (Apple Team ID)، ومُعرّفُ الحزمة (bundle id)، واسمُ حزمةِ Android، وبصماتُ شهادةِ
        // التوقيع (SHA-256). تُخدَم /.well-known/apple-app-site-association و/.well-known/
        // assetlinks.json صادقةً **تربط صفرَ تطبيق** حتى تُضبط (spec §Deep links: «documented,
        // not fabricated»). القيمُ الحيّةُ تغلب عبر setting('mobile.dl_*'). المسارُ `/m/*` هو
        // ترميزُ الرابطِ العميق القانونيّ {module,id,action} (App\Support\Collaboration\NotificationLink).
        'deep_links' => [
            'serve'   => true,                 // هل تُخدَم /.well-known/* (تبقى صادقةً NOT_CONFIGURED دون معرّفات)
            'host'    => '',                   // النطاقُ المُصرَّح — فارغٌ ⇒ config('app.url')
            'paths'   => ['/m/*', '/app/*'],   // أنماطُ المسار التي يلتقطها التطبيق (وجهةُ الرابط العميق)
            'apple'   => [
                'team_id'   => '',             // NOT_CONFIGURED — Apple Developer Team ID (10 حروف) · setting('mobile.dl_apple_team_id')
                'bundle_id' => '',             // NOT_CONFIGURED — مُعرّفُ حزمة iOS (com.example.app) · setting('mobile.dl_apple_bundle_id')
            ],
            'android' => [
                'package_name'             => '',   // NOT_CONFIGURED — اسمُ حزمة Android · setting('mobile.dl_android_package')
                'sha256_cert_fingerprints' => [],   // NOT_CONFIGURED — بصماتُ التوقيع SHA-256 · setting('mobile.dl_android_fingerprints') (مفصولة بفاصلة)
            ],
        ],
    ],
];

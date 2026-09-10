<?php

/**
 * سجلّ الهندسة المعلوماتية (Information Architecture) — المصدرُ الواحد لتنظيم
 * الوجهات في مجالاتٍ وأقسام. **يشيرُ ولا يكرّر** (spec §8): مفاتيحُ الوحدات من
 * `config/hub.php`، ومفاتيحُ المراكز من كتالوج `hub_top_links`، ومفاتيحُ الإدارة
 * من `hub_admin_links`. لا جدولَ ولا نموذجَ ولا حقلَ ولا صلاحيةَ تُنسخ هنا.
 *
 * ⚠️ لا يمنحُ IA صلاحيةً قطّ (Critic C1). كلُّ وجهةٍ **تُصرّح كيف تُحرَس**، وخدمة
 * `App\Support\InformationArchitecture` تفوّض الرؤيةَ لنفس المُسنِد القائم:
 *   · type=module            → hub_can($u, module, 'v')
 *   · type=center (كتالوج)   → مفتاحُ `center` فقط؛ الرابط/التسمية/الحارس من
 *                              hub_top_links[$key] (C6 — لا نُعيد ذكر route/label)
 *   · type=center (مستقلّ)   → route + `guard` مُسمّى يُطابق بوّابةَ المتحكّم حرفياً
 *   · type=admin             → مفتاحُ `admin`؛ الحارس من hub_admin_links[$key]['ok']
 *   · type=entity            → route + `guard` (سياقُ كيان)
 *   · type=personal          → route + guard `authed` (سطحُ المستخدم/الرئيسية)
 *   · type=utility|public|system → لا تظهر في المجالات؛ تُصنَّف في دلاءِ `buckets`
 *
 * خريطةُ الحرّاس المُسمّاة (الـpredicates) تعيش في الخدمة — **موضعٌ واحد** — لأن
 * ملفَّ الإعداد يُخبَّأ (config:cache) فلا closures فيه؛ هنا نُخزّن **اسمَ** الحارس فقط.
 *
 * ترتيبُ الإخراج حتميّ: `order` على المجالات/الأقسام + ترتيبُ الإدراج داخل
 * `destinations` (مصدرُه هذا الملفّ لا قاعدةُ بيانات، فلا قرعةَ محرّك) وتِكسارُ
 * التعادل بمفتاحٍ مركّب في الخدمة.
 *
 * @see docs/information-architecture/03-target-map.md — التصميم المُشفَّر هنا حرفياً
 */

return [

    /* ─────────────────────── سطوحٌ عامّة فوق المجالات ─────────────────────── */
    'surfaces' => [

        // 🏠 الرئيسية — GLOBAL: متاحةٌ لكل مستخدمٍ داخليّ
        'home' => [
            'label' => 'الرئيسية', 'icon' => '🏠', 'order' => 0, 'kind' => 'global',
            'synonyms' => ['home', 'الرئيسية', 'البداية', 'dashboard', 'لوحة'],
            'sections' => [
                'dashboard' => ['label' => 'لوحة التحكم', 'order' => 1, 'destinations' => [
                    ['type' => 'personal', 'route' => 'dashboard', 'guard' => 'authed', 'importance' => 'primary',
                        'label' => 'لوحة التحكم', 'icon' => '📊', 'synonyms' => ['dashboard', 'widgets', 'ودجات', 'لوحة']],
                ]],
                'discovery' => ['label' => 'البحث وخريطة النظام', 'order' => 2, 'destinations' => [
                    ['type' => 'personal', 'route' => 'search', 'guard' => 'authed', 'importance' => 'primary',
                        'label' => 'البحث الموحّد', 'icon' => '🔎', 'synonyms' => ['search', 'بحث', 'find', 'ابحث']],
                    ['type' => 'personal', 'route' => 'search.mini', 'guard' => 'authed', 'importance' => 'advanced',
                        'label' => 'بحث سريع', 'synonyms' => ['search', 'palette', 'command']],
                    // خريطةُ النظام — مسارٌ حيٌّ (الطور 6): SystemMapController@index، بيتُها هنا (سطحُ الرئيسية)
                    ['type' => 'system', 'route' => 'system-map', 'guard' => 'authed', 'importance' => 'primary',
                        'key' => 'system_map',
                        'label' => 'خريطة النظام', 'icon' => '🗺️',
                        'synonyms' => ['system map', 'خريطة', 'map', 'الخريطة']],
                ]],
                'executive' => ['label' => 'لوحات تنفيذية', 'order' => 3, 'destinations' => [
                    ['type' => 'center', 'center' => 'ceo', 'importance' => 'advanced'],
                    ['type' => 'center', 'center' => 'kpis', 'importance' => 'advanced'],
                    ['type' => 'center', 'center' => 'recs', 'importance' => 'advanced'],
                ]],
            ],
        ],

        // ✅ مهامّي — USER_PERSONAL: يجمع دوالّ المستخدم بإعادة استعمال النقاط القائمة (C7)
        'mywork' => [
            'label' => 'مهامّي', 'icon' => '✅', 'order' => 1, 'kind' => 'personal',
            'synonyms' => ['my work', 'مهامي', 'مهماتي', 'tasks', 'inbox', 'شخصي'],
            'sections' => [
                'daily' => ['label' => 'يومي', 'order' => 1, 'destinations' => [
                    ['type' => 'center', 'center' => 'morning', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'alerts', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'calendar', 'importance' => 'primary'],
                ]],
                // مهامّي وموافقاتي: منظورٌ «لي» يرتبط بفهرس الوحدة القائم (C7) — بيتُها الأساسيّ «العمل»
                'tasks' => ['label' => 'مهامّي وموافقاتي', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'tasks', 'importance' => 'secondary', 'perspective' => 'mine'],
                    ['type' => 'module', 'module' => 'approvals', 'importance' => 'secondary', 'perspective' => 'mine'],
                    ['type' => 'module', 'module' => 'issues', 'importance' => 'secondary', 'perspective' => 'mine'],
                    ['type' => 'module', 'module' => 'requests', 'importance' => 'secondary', 'perspective' => 'mine'],
                    // boards — بيتُه الأساسيّ هنا (C2): حارسُه لكل سجلّ editableBy، والفهرسُ يبلغه أيُّ مستخدمٍ داخليّ
                    ['type' => 'center', 'route' => 'boards.index', 'guard' => 'boards', 'importance' => 'primary',
                        'label' => 'اللوحات', 'icon' => '📋',
                        'key' => 'boards',  'routes' => ['boards.index', 'boards.edit'],
                        'route_prefix' => 'boards', 'center_relation' => ['work'],
                        'synonyms' => ['boards', 'لوحات', 'dashboards', 'ودجات']],
                ]],
                'messages' => ['label' => 'رسائلي', 'order' => 3, 'destinations' => [
                    // مركزُ التواصل — بيتُه الكتالوجيُّ الواحد hub_top_links['collab'] (C6): المسارُ/التسمية/الحارس
                    // من الكتالوج، فيرسمه الشريطُ الجانبيّ تلقائيّاً (DEFECT A) ويبقى مصدرُ الحقيقةِ واحداً.
                    ['type' => 'center', 'center' => 'collab', 'importance' => 'primary',
                        'route_prefix' => 'collab', 'routes' => ['collab.center', 'collab.attention'],
                        'synonyms' => ['collaboration', 'مركز التواصل', 'unified', 'الموحّد', 'workspace', 'communication hub']],
                    ['type' => 'center', 'center' => 'dm', 'importance' => 'primary', 'route_prefix' => 'dm',
                        'routes' => ['dm.inbox', 'dm.thread', 'dm.since']],
                    ['type' => 'personal', 'route' => 'groups.index', 'guard' => 'authed', 'importance' => 'secondary',
                        'label' => 'المجموعات', 'icon' => '👥', 'route_prefix' => 'groups', 'routes' => ['groups.index'],
                        'synonyms' => ['group dm', 'مجموعات', 'محادثة جماعية']],
                    ['type' => 'personal', 'route' => 'conversations.index', 'guard' => 'authed', 'importance' => 'primary',
                        'label' => 'المحادثات', 'route_prefix' => 'conversations', 'routes' => ['conversations.index', 'conversations.show', 'conversations.directory', 'conversations.since'],
                        'synonyms' => ['conversations', 'محادثات', 'chat', 'قنوات', 'دليل القنوات']],
                    ['type' => 'center', 'center' => 'feed', 'importance' => 'primary'],
                    ['type' => 'personal', 'route' => 'saved.index', 'guard' => 'authed', 'importance' => 'secondary',
                        'label' => 'المحفوظات', 'icon' => '🔖', 'route_prefix' => 'saved', 'routes' => ['saved.index'],
                        'synonyms' => ['saved', 'محفوظات', 'bookmarks', 'احفظ لاحقاً']],
                    ['type' => 'personal', 'route' => 'search.messages', 'guard' => 'authed', 'importance' => 'secondary',
                        'label' => 'بحثُ الرسائل', 'icon' => '🔎', 'routes' => ['search.messages'],
                        'synonyms' => ['message search', 'بحث الرسائل', 'ابحث في المحادثات']],
                ]],
                'inbox' => ['label' => 'صندوقي', 'order' => 4, 'destinations' => [
                    ['type' => 'center', 'center' => 'inboxdocs', 'importance' => 'primary', 'center_relation' => ['knowledge']],
                    ['type' => 'personal', 'route' => 'notifications.index', 'guard' => 'authed', 'importance' => 'primary',
                        'label' => 'الإشعارات', 'icon' => '🔔', 'route_prefix' => 'notifications',
                        'routes' => ['notifications.index', 'notifications.count', 'notifications.mini', 'notifications.go'],
                        'synonyms' => ['notifications', 'إشعارات', 'تنبيهاتي']],
                ]],
                'account' => ['label' => 'حسابي', 'order' => 5, 'destinations' => [
                    ['type' => 'center', 'center' => 'me', 'importance' => 'secondary'],
                    ['type' => 'personal', 'route' => 'profile.edit', 'guard' => 'authed', 'importance' => 'secondary',
                        'label' => 'ملفّي الشخصي', 'synonyms' => ['profile', 'الملف الشخصي', 'حسابي']],
                    ['type' => 'personal', 'route' => 'prefs.edit', 'guard' => 'authed', 'importance' => 'secondary',
                        'label' => 'التخصيص', 'route_prefix' => 'prefs', 'synonyms' => ['preferences', 'التخصيص', 'تفضيلاتي', 'settings']],
                    ['type' => 'personal', 'route' => 'mysec.index', 'guard' => 'authed', 'importance' => 'secondary',
                        'label' => 'أماني وجلساتي', 'route_prefix' => 'mysec', 'synonyms' => ['security', 'أماني', 'جلساتي', '2fa']],
                    ['type' => 'personal', 'route' => 'stepup.show', 'guard' => 'authed', 'importance' => 'advanced',
                        'label' => 'تأكيد الهوية', 'route_prefix' => 'stepup', 'synonyms' => ['step up', 'تأكيد', 'otp']],
                ]],
            ],
        ],
    ],

    /* ────────────────────────────── المجالات التسعة ────────────────────────────── */
    // مفاتيحُ المجالات = مفاتيحُ hub_workspaces حرفياً (٨ عمل) + administration (نظام).
    'domains' => [

        // 1) 🏢 الكيانات والعلاقات
        'entities' => [
            'label' => 'الكيانات والعلاقات', 'icon' => '🏢', 'order' => 10, 'plane' => 'work',
            'workspace' => 'entities', 'nav_group' => 'الكيانات',
            'synonyms' => ['companies', 'clients', 'crm', 'عملاء', 'شركات', 'مشاريع', 'entities'],
            'sections' => [
                'companies' => ['label' => 'الشركات والمشاريع', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'companies', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'projects', 'importance' => 'primary'],
                ]],
                'crm' => ['label' => 'العملاء والـCRM', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'clients', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'engagements', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'sales', 'importance' => 'secondary'],
                    ['type' => 'entity', 'route' => 'journey', 'guard' => 'journey', 'importance' => 'advanced',
                        'key' => 'journey', 
                        'contextual' => true, 'label' => 'رحلة العميل', 'route_prefix' => 'journey',
                        'synonyms' => ['journey', 'رحلة', 'العميل 360']],
                ]],
                'offerings' => ['label' => 'العروض والعلامات', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'services', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'brands', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'competitors', 'importance' => 'secondary'],
                ]],
                'relationships' => ['label' => 'الأثر والعلاقات', 'order' => 4, 'destinations' => [
                    ['type' => 'center', 'center' => 'impact', 'importance' => 'advanced', 'center_relation' => ['digital']],
                    ['type' => 'center', 'route' => 'graph.explore', 'guard' => 'graph', 'importance' => 'advanced',
                        'key' => 'graph', 
                        'label' => 'مستكشف العلاقات', 'route_prefix' => 'graph', 'routes' => ['graph.explore', 'graph.expand'],
                        'synonyms' => ['graph', 'العلاقات', 'الشبكة', 'relationships']],
                ]],
            ],
        ],

        // 2) 🗂️ العمل والتسليم
        'work' => [
            'label' => 'العمل والتسليم', 'icon' => '🗂️', 'order' => 20, 'plane' => 'work',
            'workspace' => 'work', 'nav_group' => 'العمل',
            'synonyms' => ['work', 'delivery', 'tasks', 'مهام', 'تذاكر', 'أهداف', 'العمل'],
            'sections' => [
                'exec' => ['label' => 'المهام والتنفيذ', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'tasks', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'updates', 'importance' => 'primary'],
                    // تقاريرُ العملِ اليوميّة (§121): مراكزُ كتالوجٍ من `hub_top_links` (مصدرُ
                    // الحقيقة الواحد للمراكز · P4)، فتظهر روابطَ مباشرةً في «الأدوات واللوحات»
                    // كـ«فريقي اليوم» تماماً — لا مدفونةً في صفحةِ مساحة. IA يرتّب لا يَحرُس.
                    ['type' => 'center', 'center' => 'myreport', 'importance' => 'primary',
                        'synonyms' => ['my report', 'تقريري', 'تقرير اليوم', 'daily report', 'today report']],
                    ['type' => 'center', 'center' => 'reportsc', 'importance' => 'primary',
                        'route_prefix' => 'reports', 'routes' => ['reports.index', 'reports.day'],
                        'synonyms' => ['reports', 'التقارير', 'تقارير العمل', 'daily reports', 'compliance', 'الامتثال']],
                    ['type' => 'center', 'center' => 'reportsr', 'importance' => 'secondary',
                        'synonyms' => ['review', 'المراجعة', 'reports review', 'مراجعة التقارير']],
                    ['type' => 'center', 'center' => 'attmonth', 'importance' => 'secondary',
                        'route_prefix' => 'reports.monthly', 'routes' => ['reports.monthly', 'reports.monthly.employee'],
                        'synonyms' => ['monthly', 'الحضور الشهري', 'شهري', 'كشف الحضور', 'المحاسب', 'attendance sheet', 'payroll attendance']],
                    ['type' => 'module', 'module' => 'designs', 'importance' => 'primary'],
                    // issues بيتُه الأساسيّ هنا (03 لم يُدرجه صراحةً في أقسام «العمل» — أُسند لِـexec)
                    ['type' => 'module', 'module' => 'issues', 'importance' => 'primary'],
                    // boards ظهورٌ ثانويّ (بيتُه الأساسيّ «مهامّي» — C2)
                    ['type' => 'center', 'route' => 'boards.index', 'guard' => 'boards', 'importance' => 'secondary',
                        'label' => 'اللوحات', 'center_relation' => ['mywork'], 'primary_at' => 'mywork'],
                ]],
                'support' => ['label' => 'التذاكر والدعم', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'tickets', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'support', 'importance' => 'primary'],
                ]],
                'meetings' => ['label' => 'الاجتماعات والقرارات', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'meetings', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'decisions', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'approvals', 'importance' => 'secondary'],
                ]],
                'goals' => ['label' => 'الأهداف والخطة', 'order' => 4, 'destinations' => [
                    ['type' => 'module', 'module' => 'okrs', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'krs', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'okrb', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'feats', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'delivery', 'importance' => 'secondary',
                        'route_prefix' => 'delivery', 'routes' => ['delivery', 'delivery.psa']],
                ]],
                'requests' => ['label' => 'الطلبات والإشراف', 'order' => 5, 'destinations' => [
                    ['type' => 'module', 'module' => 'requests', 'importance' => 'secondary'],
                    ['type' => 'center', 'route' => 'oversight.index', 'guard' => 'oversight', 'importance' => 'advanced',
                        'key' => 'oversight', 
                        'label' => 'رقابة الاتصالات', 'route_prefix' => 'oversight', 'routes' => ['oversight.index', 'oversight.show'],
                        'synonyms' => ['oversight', 'الرقابة', 'الإشراف']],
                ]],
            ],
        ],

        // 3) 💰 المالية والمشتريات
        'finance' => [
            'label' => 'المالية والمشتريات', 'icon' => '💰', 'order' => 30, 'plane' => 'work',
            'workspace' => 'finance', 'nav_group' => 'المالية والمشتريات',
            'synonyms' => ['finance', 'money', 'مالية', 'فواتير', 'موردون', 'مشتريات', 'ميزانية'],
            'sections' => [
                'invoices' => ['label' => 'الفواتير والمحاسبة', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'fin', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'entries', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'accounts2', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'banks', 'importance' => 'secondary'],
                ]],
                'quotes' => ['label' => 'العروض وأوامر التغيير', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'quotes', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'changeorders', 'importance' => 'primary'],
                ]],
                'budgets' => ['label' => 'الميزانيات والتكاليف', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'budgets', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'costc', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'recur', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'subs', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'costs', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'svccosts', 'importance' => 'secondary'],
                ]],
                'procurement' => ['label' => 'المشتريات والموردون', 'order' => 4, 'destinations' => [
                    ['type' => 'module', 'module' => 'suppliers', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'purchases', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'supscores', 'importance' => 'secondary'],
                ]],
                'reports' => ['label' => 'التقارير والتسعير', 'order' => 5, 'destinations' => [
                    ['type' => 'center', 'center' => 'finrep', 'importance' => 'secondary'],
                    // pricing مركزٌ بيتُه Finance (تجاريّ)؛ وحدةُ plans (بياناته) بيتُها Knowledge — كائنان (03 §3 ملاحظة)
                    ['type' => 'center', 'center' => 'pricing', 'importance' => 'secondary'],
                ]],
            ],
        ],

        // 4) 👥 الموظفون والموارد البشرية — مساحة hr
        'hr' => [
            'label' => 'الموظفون والموارد البشرية', 'icon' => '👥', 'order' => 40, 'plane' => 'work',
            'workspace' => 'hr', 'nav_group' => 'الموارد البشرية',
            'synonyms' => ['hr', 'people', 'موظفون', 'موارد بشرية', 'رواتب', 'حضور', 'إجازات'],
            'sections' => [
                'files' => ['label' => 'ملفات الموظفين', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'hr', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'hrlog', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'skills', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'teamdir', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'staff', 'importance' => 'secondary'],
                    ['type' => 'entity', 'route' => 'portal.employee', 'guard' => 'portal_employee', 'importance' => 'advanced',
                        'key' => 'portal_employee', 
                        'contextual' => true, 'label' => 'ملفّ موظّف', 'synonyms' => ['employee', 'ملف موظف', 'employee 360']],
                ]],
                'attendance' => ['label' => 'الحضور والإجازات', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'attend', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'leaves', 'importance' => 'primary'],
                ]],
                'payroll' => ['label' => 'الرواتب والتوظيف', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'payroll', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'recruit', 'importance' => 'secondary'],
                ]],
                'workforce' => ['label' => 'القوى والأداء', 'order' => 4, 'destinations' => [
                    ['type' => 'center', 'center' => 'workteam', 'importance' => 'secondary'],
                    ['type' => 'center', 'route' => 'workforce.overview', 'guard' => 'workforce_overview', 'importance' => 'advanced',
                        'key' => 'workforce_overview', 
                        'label' => 'نظرة القوى العاملة', 'route_prefix' => 'workforce', 'synonyms' => ['workforce', 'القوى العاملة']],
                    ['type' => 'center', 'center' => 'capacity', 'importance' => 'advanced'],
                    ['type' => 'center', 'center' => 'perf', 'importance' => 'advanced'],
                    ['type' => 'center', 'route' => 'custody.wallet.center', 'guard' => 'custody_wallet', 'importance' => 'secondary',
                        'key' => 'custody_wallet', 
                        'label' => 'محفظة العهدة', 'route_prefix' => 'custody.wallet',
                        'routes' => ['custody.wallet.center', 'custody.wallet.employee'],
                        'synonyms' => ['custody wallet', 'عهدة مالية', 'محفظة الموظف']],
                ]],
            ],
        ],

        // 5) 💠 التقنية والبنية الرقمية — مساحة digital (+ يتيمان: endpoints, stations)
        'digital' => [
            'label' => 'التقنية والبنية الرقمية', 'icon' => '💠', 'order' => 50, 'plane' => 'work',
            'workspace' => 'digital', 'nav_group' => 'الأصول الرقمية',
            'synonyms' => ['technology', 'digital', 'تقنية', 'سيرفرات', 'تطبيقات', 'كود', 'بنية'],
            'sections' => [
                'apps' => ['label' => 'التطبيقات والكود', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'apps', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'code', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'deploys', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'deps', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'changes', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'codehub', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'appsproj', 'importance' => 'secondary', 'center_relation' => ['entities']],
                    ['type' => 'center', 'center' => 'appq', 'importance' => 'secondary'],
                    ['type' => 'entity', 'route' => 'apps.center', 'guard' => 'apps_center', 'importance' => 'advanced',
                        'key' => 'apps_center', 
                        'contextual' => true, 'label' => 'مركز التطبيق', 'synonyms' => ['app center', 'مركز التطبيق']],
                    ['type' => 'entity', 'route' => 'odoo.project', 'guard' => 'odoo_project', 'importance' => 'advanced',
                        'key' => 'odoo_project', 
                        'contextual' => true, 'label' => 'أودو للمشروع', 'route_prefix' => 'odoo',
                        'synonyms' => ['odoo', 'أودو']],
                ]],
                'infra' => ['label' => 'البنية التحتية', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'servers', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'domains', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'websites', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'dbs', 'importance' => 'secondary'],
                    // endpoints — يتيمٌ أُسند هنا (مركزٌ مستقلّ /endpoints يُمثّل الوحدةَ ذاتها)
                    ['type' => 'center', 'route' => 'endpoints.index', 'guard' => 'endpoints', 'importance' => 'primary',
                        'key' => 'endpoints_center', 
                        'module' => 'endpoints', 'label' => 'النقاط الطرفية', 'route_prefix' => 'endpoints',
                        'routes' => ['endpoints.index', 'endpoints.show'], 'synonyms' => ['endpoints', 'النقاط الطرفية', 'الأجهزة']],
                    ['type' => 'center', 'route' => 'endpoints.releases', 'guard' => 'endpoints_releases', 'importance' => 'advanced',
                        'key' => 'endpoints_releases', 
                        'label' => 'إصدارات الوكيل', 'routes' => ['endpoints.releases', 'endpoints.releases.download'],
                        'synonyms' => ['agent releases', 'إصدارات الوكيل']],
                    // stations — يتيمٌ أُسند هنا؛ وحدةٌ عاديّة (m.index) بحارس hub_can
                    ['type' => 'module', 'module' => 'stations', 'importance' => 'secondary', 'label' => 'الأجهزة والمحطات'],
                ]],
                'accounts' => ['label' => 'الحسابات والاتصالات', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'accounts', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'emails', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'phones', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'carriers', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'vault', 'importance' => 'secondary'],
                ]],
                'integrations' => ['label' => 'التكاملات والحوادث', 'order' => 4, 'destinations' => [
                    ['type' => 'module', 'module' => 'apis', 'importance' => 'secondary'],
                    // incidents بيتُه الأساسيّ هنا؛ يظهر منظوراً ثانويّاً في كتالوج الإدارة (center_relation)
                    ['type' => 'module', 'module' => 'incidents', 'importance' => 'secondary', 'center_relation' => ['administration']],
                ]],
                'social' => ['label' => 'السوشال والرقمنة', 'order' => 5, 'destinations' => [
                    ['type' => 'module', 'module' => 'social', 'importance' => 'secondary'],
                    ['type' => 'module', 'module' => 'posts', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'social', 'importance' => 'secondary', 'center_relation' => ['knowledge']],
                    ['type' => 'center', 'center' => 'dassets', 'importance' => 'secondary'],
                ]],
            ],
        ],

        // 6) 📜 الأصول والعقود والامتثال — مساحة legalws
        'legalws' => [
            'label' => 'الأصول والعقود والامتثال', 'icon' => '📜', 'order' => 60, 'plane' => 'work',
            'workspace' => 'legalws', 'nav_group' => 'الأصول والعقود',
            'synonyms' => ['legal', 'assets', 'contracts', 'compliance', 'أصول', 'عقود', 'امتثال', 'عهد', 'مخزون'],
            'sections' => [
                'assets' => ['label' => 'الأصول والعهد', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'assets', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'assetlog', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'custody', 'importance' => 'primary',
                        'routes' => ['custody.catalog', 'custody.category', 'custody.label', 'custody.spec', 'custody.permit.doc']],
                    ['type' => 'center', 'center' => 'assetlife', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'identity', 'importance' => 'secondary', 'route_prefix' => 'identity',
                        'routes' => ['identity.center', 'identity.labels', 'identity.resolve', 'identity.product.label']],
                ]],
                'inventory' => ['label' => 'المخزون والمنتجات', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'products', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'stock', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'stockmv', 'importance' => 'secondary'],
                    ['type' => 'center', 'route' => 'inventory.center', 'guard' => 'inventory', 'importance' => 'primary',
                        'key' => 'inventory', 
                        'label' => 'مركز الجرد', 'route_prefix' => 'inventory', 'routes' => ['inventory.center', 'inventory.show'],
                        'synonyms' => ['inventory', 'الجرد', 'جرد المخزون']],
                ]],
                'contracts' => ['label' => 'العقود والتوقيع', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'contracts', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'obligations', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'legal', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'esign', 'importance' => 'primary', 'route_prefix' => 'esign',
                        'routes' => ['esign.index', 'esign.edit', 'esign.tpl.edit', 'esign.doc', 'esign.pdf', 'esign.cert'],
                        'mobile' => 'web-only'],
                ]],
                'compliance' => ['label' => 'الملكية والامتثال', 'order' => 4, 'destinations' => [
                    ['type' => 'module', 'module' => 'ip', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'compliance', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'compb', 'importance' => 'primary'],
                ]],
            ],
        ],

        // 7) 🧭 العمليات الميدانية — مساحة fieldops
        'fieldops' => [
            'label' => 'العمليات الميدانية', 'icon' => '🧭', 'order' => 70, 'plane' => 'work',
            'workspace' => 'fieldops', 'nav_group' => 'العمليات الميدانية',
            'synonyms' => ['field', 'ميدان', 'زيارات', 'مناطق', 'مندوبون', 'hcp'],
            'sections' => [
                'network' => ['label' => 'الشبكة الميدانية', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'hcps', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'facilities', 'importance' => 'primary'],
                ]],
                'territories' => ['label' => 'المناطق والتغطية', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'territories', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'terrassigns', 'importance' => 'primary'],
                ]],
                'cycles' => ['label' => 'الدورات والزيارات', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'cycles', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'visits', 'importance' => 'primary'],
                    ['type' => 'center', 'route' => 'field.dashboard', 'guard' => 'field', 'importance' => 'primary',
                        'key' => 'field', 
                        'label' => 'لوحة المشرف الميدانيّ', 'route_prefix' => 'field',
                        'routes' => ['field.dashboard', 'field.route', 'field.sessions'],
                        'synonyms' => ['field', 'المشرف الميداني', 'الميدان']],
                ]],
            ],
        ],

        // 8) 📚 المعرفة والمستندات — مساحة knowledge
        'knowledge' => [
            'label' => 'المعرفة والمستندات', 'icon' => '📚', 'order' => 80, 'plane' => 'work',
            'workspace' => 'knowledge', 'nav_group' => 'المعرفة والملفات',
            'synonyms' => ['knowledge', 'docs', 'معرفة', 'مستندات', 'ملفات', 'سياسات', 'أفكار', 'إعلام'],
            'sections' => [
                'kb' => ['label' => 'قاعدة المعرفة', 'order' => 1, 'destinations' => [
                    ['type' => 'module', 'module' => 'kb', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'ideas', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'innov', 'importance' => 'primary', 'center_relation' => ['work']],
                ]],
                'files' => ['label' => 'الملفات والمستندات', 'order' => 2, 'destinations' => [
                    ['type' => 'module', 'module' => 'files', 'importance' => 'primary'],
                ]],
                'policies' => ['label' => 'السياسات والإقرارات', 'order' => 3, 'destinations' => [
                    ['type' => 'module', 'module' => 'policies', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'policyacks', 'importance' => 'secondary'],
                    ['type' => 'center', 'center' => 'polb', 'importance' => 'primary'],
                ]],
                'media' => ['label' => 'الإعلام والفعاليات', 'order' => 4, 'destinations' => [
                    ['type' => 'module', 'module' => 'media', 'importance' => 'primary'],
                    ['type' => 'module', 'module' => 'events', 'importance' => 'primary'],
                    ['type' => 'center', 'center' => 'mediac', 'importance' => 'primary'],
                ]],
                'alerts' => ['label' => 'التنبيهات والباقات', 'order' => 5, 'destinations' => [
                    ['type' => 'module', 'module' => 'rules', 'importance' => 'secondary'],
                    // plans (pricing_plans) بيتُه هنا؛ مركزُ pricing التجاريّ بيتُه Finance — كائنان
                    ['type' => 'module', 'module' => 'plans', 'importance' => 'secondary'],
                ]],
            ],
        ],

        // 9) ⚙️ الإدارة والنظام — سطحُ النظام (شريط الترس). يظهر فقط لمن يملكه (حارسُ المجال admin_bar).
        'administration' => [
            'label' => 'الإدارة والنظام', 'icon' => '⚙️', 'order' => 90, 'plane' => 'system',
            'guard' => 'admin_bar',                       // شرطُ ظهورِ شريط الإدارة (layouts/app.blade.php:142)
            'synonyms' => ['admin', 'system', 'إدارة', 'نظام', 'إعدادات', 'أمان', 'مستخدمون'],
            'sections' => [
                'security' => ['label' => 'الأمن والرقابة', 'order' => 1, 'destinations' => [
                    ['type' => 'admin', 'admin' => 'audit', 'importance' => 'primary', 'route_prefix' => 'audit',
                        'routes' => ['audit.index', 'audit.coverage', 'audit.show']],
                    ['type' => 'admin', 'admin' => 'security', 'importance' => 'primary', 'route_prefix' => 'security'],
                    ['type' => 'admin', 'admin' => 'activity', 'importance' => 'secondary', 'route_prefix' => 'activity',
                        'routes' => ['activity.index', 'activity.show']],
                    ['type' => 'admin', 'admin' => 'dataroom', 'importance' => 'secondary', 'route_prefix' => 'dataroom'],
                ]],
                'ops' => ['label' => 'التشغيل والمراقبة', 'order' => 2, 'destinations' => [
                    ['type' => 'admin', 'admin' => 'control', 'importance' => 'primary', 'route_prefix' => 'control'],
                    ['type' => 'admin', 'admin' => 'ops', 'importance' => 'primary', 'route_prefix' => 'ops',
                        'routes' => ['ops.index', 'ops.health', 'ops.runbooks']],
                    ['type' => 'admin', 'admin' => 'errors', 'importance' => 'secondary', 'route_prefix' => 'errors',
                        'routes' => ['errors.index', 'errors.logs', 'errors.show']],
                    ['type' => 'admin', 'admin' => 'alerts', 'importance' => 'secondary'],   // route alerts.center
                    // مركزُ منصّة تطبيق الهاتف (يلفُّ كتالوجَ الجوال — يشارك في الشريط/البحث/الخريطة)
                    ['type' => 'admin', 'admin' => 'mobileplatform', 'importance' => 'primary',
                        'route_prefix' => 'mobileplatform', 'routes' => ['mobileplatform.index'],
                        'synonyms' => ['mobile', 'الجوال', 'الهاتف', 'push', 'app-config', 'deep links', 'الروابط العميقة', 'الجلسات']],
                    // incidents منظورٌ تشغيليّ ثانويّ (بيتُه الأساسيّ Technology — primary_at)
                    ['type' => 'admin', 'admin' => 'incidents', 'importance' => 'advanced', 'primary_at' => 'digital'],
                    // restores — يتيمٌ أُسند هنا؛ وحدةٌ (m.index) SYSTEM_ONLY بحارس hub_can
                    ['type' => 'module', 'module' => 'restores', 'importance' => 'advanced', 'label' => 'اختبارات الاستعادة',
                        'tag' => 'SYSTEM_ONLY'],
                    ['type' => 'system', 'route' => 'system.trace', 'guard' => 'owner', 'importance' => 'advanced',
                        'key' => 'system_trace', 
                        'label' => 'تتبّع النظام', 'route_prefix' => 'system'],
                ]],
                'quality' => ['label' => 'الجودة والحوكمة', 'order' => 3, 'destinations' => [
                    ['type' => 'admin', 'admin' => 'quality', 'importance' => 'primary', 'route_prefix' => 'quality'],
                    ['type' => 'admin', 'admin' => 'fields', 'importance' => 'primary', 'route_prefix' => 'fields'],
                    ['type' => 'admin', 'admin' => 'flows', 'importance' => 'primary', 'route_prefix' => 'flows',
                        'routes' => ['flows.index', 'flows.edit', 'flows.sandbox']],
                ]],
                'settings' => ['label' => 'الإعدادات والتكاملات', 'order' => 4, 'destinations' => [
                    ['type' => 'admin', 'admin' => 'settings', 'importance' => 'primary', 'route_prefix' => 'settings',
                        'routes' => ['settings.edit', 'settings.export']],
                    // سجلُّ القدرات — الإدارة ← التهيئة ← القدرات (guard من hub_admin_links['features'] = المالك)
                    ['type' => 'admin', 'admin' => 'features', 'importance' => 'primary', 'route_prefix' => 'features',
                        'routes' => ['features.index', 'features.show'],
                        'synonyms' => ['features', 'capabilities', 'القدرات', 'المزايا', 'feature registry', 'flags', 'سجل القدرات']],
                    ['type' => 'admin', 'admin' => 'integrations', 'importance' => 'primary', 'route_prefix' => 'integrations',
                        'routes' => ['integrations.index', 'integrations.guide', 'hooks.index', 'integrations.messaging',
                            'integrations.n8n', 'integrations.odoo', 'webhooks.index', 'webhooks.log']],
                    ['type' => 'admin', 'admin' => 'quoteflow', 'importance' => 'secondary', 'route_prefix' => 'quoteflow'],
                ]],
                'users' => ['label' => 'المستخدمون والوصول', 'order' => 5, 'destinations' => [
                    // users — يتيمٌ ADMIN_ONLY؛ الحارسُ من hub_admin_links['users']['ok'] = hub_flag(users)
                    ['type' => 'admin', 'admin' => 'users', 'importance' => 'primary', 'module' => 'users',
                        'route_prefix' => 'users', 'routes' => ['users.index', 'users.create', 'users.edit'], 'tag' => 'ADMIN_ONLY'],
                    ['type' => 'admin', 'admin' => 'roles', 'importance' => 'primary', 'route_prefix' => 'roles',
                        'routes' => ['roles.index', 'roles.create', 'roles.edit']],
                    // تشخيصُ الوصول — شرحُ الصلاحيّة الفعّالة (§22/§59)؛ الحارسُ owner عبر hub_admin_links['access']
                    ['type' => 'admin', 'admin' => 'access', 'importance' => 'secondary', 'route_prefix' => 'access',
                        'routes' => ['access.index', 'access.role']],
                ]],
            ],
        ],
    ],

    /* ─────── وحداتٌ خارج التنقّل: مُصنَّفةٌ صراحةً (لا يتيمَ بلا حسم) ─────── */
    'deprecated' => [
        // autos — «الأتمتة (مؤرشفة)» خليفتُها flows؛ لا بيتَ تنقّل، والمسارُ m.index[autos] يبقى يعمل (صفر فقدان)
        'autos' => ['status' => 'DEPRECATED_CONFIRMED', 'successor' => 'flows',
            'route' => 'm.index', 'args' => ['autos'], 'label' => 'الأتمتة (مؤرشفة)'],
    ],

    /* ─────── أسطحٌ خارج مجالات العمل التسعة (جمهورٌ/طبيعةٌ مختلفة — تُصنَّف لا تُدمج) ─────── */
    // مصدرُ دلاءِ التصنيف لِـrouteLocation: كلُّ مسارٍ غيرِ مُنتمٍ لمجالٍ يقع في دلوٍ مُسمّى (لا يرمي أبداً).
    'buckets' => [
        'portal' => ['label' => 'بوّابة العميل', 'audience' => 'client', 'prefixes' => ['portal'],
            'routes' => ['portal.home', 'portal.conversations', 'portal.conversation', 'portal.documents',
                'portal.document', 'portal.engagements', 'portal.invoices', 'portal.invoice',
                'portal.projects', 'portal.project']],
        'public' => ['label' => 'مداخل عامّة', 'prefixes' => ['sign', 'share'],
            'routes' => ['login', 'login.otp', 'activate.show', 'sign.verify', 'sign.verify.doc',
                'custody.code', 'stations.code', 'products.code']],
        'utility' => ['label' => 'مولّدات ووثائق', 'prefixes' => ['att', 'quotes'],
            'routes' => ['file.show', 'changeorders.pdf', 'purchases.doc']],
        'system' => ['label' => 'بنية تحتية', 'prefixes' => ['pwa'],
            'routes' => ['up', 'healthz', 'mobile.aasa', 'mobile.assetlinks', 'trace']],
        'api' => ['label' => 'واجهة الموبايل والـAPI', 'prefixes' => ['mobile', 'endpoint', 'api'],
            'routes' => []],
    ],

    // أسطحٌ متخصّصةٌ على المساحات: تُحلّ في routeLocation لمجالها (C5 — tech.workspace = Technology)
    'workspace_views' => [
        'tech.workspace' => ['domain' => 'digital', 'section' => 'apps', 'label' => 'مساحة التقنية'],
    ],
];

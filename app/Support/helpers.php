<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

if (! function_exists('setting')) {
    /**
     * إعداد مخزَّن في القاعدة مع كاش — المفتاح حرفي (النقطة جزء من الاسم لا تداخل).
     * القيم السرية تُخزن مشفرة ببادئة enc: وتُفك هنا بشفافية.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('settings:all', 600, fn () => Setting::pluck('value', 'key')->all());
        $v = array_key_exists($key, $all) ? $all[$key] : null;
        if ($v === null || $v === '') return $default;

        if (is_string($v) && str_starts_with($v, 'enc:')) {
            try { return \Illuminate\Support\Facades\Crypt::decryptString(substr($v, 4)); }
            catch (\Throwable $e) { return $default; }
        }

        return $v;
    }
}

if (! function_exists('ip_allowed')) {
    function ip_allowed(string $ip, string $list): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $list))) as $rule) {
            // البدلُ الصريح (*) وحده يطابق بالبادئة — قاعدةٌ بلا نجمة لا تُطابِق
            // بالبادئة (كان «203.0.113.7» يقبل «203.0.113.70»، و«10.0.0.1» يفتح مدىً).
            if (str_ends_with($rule, '*')) {
                if (str_starts_with($ip, rtrim($rule, '*'))) return true;
            } elseif (str_contains($rule, '/')) {
                // CIDR ببايتات inet_pton — يدعم IPv4 وIPv6 بلا إزاحةٍ سالبة تسقط بـ500
                // ولا سماحٍ ضمنيّ بكل IPv6 (نظير ApiToken::ipAllowed المُصلَّب).
                [$net, $bits] = array_pad(explode('/', $rule, 2), 2, '');
                $bits = (int) $bits;
                $ipBin = @inet_pton($ip); $netBin = @inet_pton($net);
                if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) continue;
                $bytes = intdiv($bits, 8); $rem = $bits % 8;
                if ($bytes && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) continue;
                if ($rem && ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) >> (8 - $rem)) !== 0) continue;
                return true;
            } elseif (hash_equals($rule, $ip)) {
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('password_rules')) {
    function password_rules(): \Illuminate\Validation\Rules\Password
    {
        $rule = \Illuminate\Validation\Rules\Password::min((int) setting('auth.pw_min', 10))
            ->letters()->numbers()->mixedCase();
        // فحص HIBP يتطلب اتصالاً خارجياً — اختياري عبر الإعداد auth.pw_hibp
        if (setting('auth.pw_hibp', false)) $rule->uncompromised();
        return $rule;
    }
}

if (! function_exists('hub_scoped')) {
    /** هل حساب المستخدم محدود النطاق بمشاريعه؟ (scope = proj وليس مالكاً) */
    function hub_scoped($user = null): bool
    {
        $user = $user ?? auth()->user();
        $role = $user?->role;
        return $role && ! $role->is_owner && ($role->scope ?? 'all') === 'proj';
    }
}

if (! function_exists('hub_project_field')) {
    /** حقل المرجع المفرد للمشاريع في وحدة ما (إن وُجد) — مخبأ في الذاكرة */
    function hub_project_field(string $module): ?array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (hub_modules() as $mk => $md) {
                foreach ($md['fields'] as $f) {
                    if (($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'projects' && empty($f['multi'])) {
                        $map[$mk] = $f;
                        break;
                    }
                }
            }
        }
        return $map[$module] ?? null;
    }
}

if (! function_exists('hub_project_col')) {
    /**
     * عمود المشروع للانحسار: من تعريف الوحدة، أو العمود الفعلي project_id في الجدول
     * إن وُجد ولو لم يُعرَّف في السجل — حتى لا يتسرب ما تربطه القاعدة بالمشاريع.
     */
    function hub_project_col(string $module): ?string
    {
        static $cols = [];
        if (array_key_exists($module, $cols)) return $cols[$module];

        if ($pf = hub_project_field($module)) return $cols[$module] = $pf['col'];

        $table = hub_mod($module)['table'] ?? null;
        try {
            $has = $table && \Illuminate\Support\Facades\Schema::hasColumn($table, 'project_id');
        } catch (\Throwable $e) {
            $has = false;
        }

        return $cols[$module] = $has ? 'project_id' : null;
    }
}

if (! function_exists('hub_company_ids')) {
    /**
     * الشركات المسموحة للمستخدم في العزل الصارم — null = غير مقيد
     * (مالك دائماً، أو مستخدم بلا قائمة شركات محددة).
     */
    function hub_company_ids($user = null): ?array
    {
        $user = $user ?? auth()->user();
        if (! $user || $user->role?->is_owner) return null;
        $ids = is_array($user->companies) ? $user->companies : (json_decode($user->companies ?? '[]', true) ?: []);
        $ids = array_values(array_filter(array_map('strval', $ids)));

        return $ids ?: null;
    }
}

if (! function_exists('hub_scope')) {
    /**
     * فرض النطاق الكامل على أي استعلام (Eloquent أو Query Builder):
     *  1) نطاق المشاريع — المستخدم المحدود يرى مشاريعه وسجلاتها فقط.
     *  2) عزل الشركات الصارم — من له قائمة شركات مسموحة لا يرى إلا سجلاتها،
     *     والوصول المباشر بالرابط لسجل خارجها = 404 (findOrFail بعد النطاق).
     * الوحدات بلا عمود مشروع/شركة تبقى محكومة بمصفوفة الصلاحيات وحدها.
     */
    function hub_scope($q, string $module, $user = null)
    {
        $user = $user ?? auth()->user();

        if (hub_scoped($user)) {
            $ids = $user->visibleProjectIds();
            if ($module === 'projects') $q->whereIn('id', $ids);
            elseif ($col = hub_project_col($module)) $q->whereIn($col, $ids);
        }

        if (($cids = hub_company_ids($user)) !== null && ($ccol = hub_company_col($module))) {
            $q->whereIn($ccol, $cids);
        }

        // عزلُ العملاء الصارم — نظيرُ عزل الشركات حرفياً: من له قائمةُ عملاء
        // مسموحين لا يرى سجلات غيرهم في أي وحدةٍ لها عمودُ عميل. (وحدةٌ بلا
        // عمودِ عميلٍ تبقى محكومةً بمصفوفة الصلاحيات — فدورُ «مستخدم عميل»
        // يُمنح الوحداتِ السياقيةَ وحدها.)
        if (($kids = hub_client_ids($user)) !== null && ($kcol = hub_client_col($module))) {
            $q->whereIn($kcol, $kids);
        }

        return $q;
    }
}

if (! function_exists('hub_client_ids')) {
    /**
     * العملاء المسموحون للمستخدم — null = بلا قيد (الداخليون جميعاً)، ومصفوفةٌ
     * غير فارغة = معزولٌ عليهم (حسابُ عميلٍ خارجي أو موظفٌ مخصَّص لعملاء بأعيانهم).
     */
    function hub_client_ids($user = null): ?array
    {
        $user = $user ?? auth()->user();
        if (! $user || $user->role?->is_owner) return null;
        $ids = is_array($user->clients) ? $user->clients : (json_decode($user->clients ?? '[]', true) ?: []);
        $ids = array_values(array_filter(array_map('strval', $ids)));

        return $ids ?: null;
    }
}

if (! function_exists('hub_client_col')) {
    /** عمودُ العميل للوحدة: من حقل ref→clients المفرد، أو العمود client_id الفعلي */
    function hub_client_col(string $module): ?string
    {
        if ($module === 'clients') return 'id';
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (hub_modules() as $mk => $md) {
                foreach ($md['fields'] as $f) {
                    if (($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'clients' && empty($f['multi'])) {
                        $map[$mk] = $f['col'];
                        break;
                    }
                }
            }
        }
        if (array_key_exists($module, $map)) return $map[$module];

        $table = hub_mod($module)['table'] ?? null;
        try {
            $has = $table && \Illuminate\Support\Facades\Schema::hasColumn($table, 'client_id');
        } catch (\Throwable $e) {
            $has = false;
        }

        return $map[$module] = $has ? 'client_id' : null;
    }
}

if (! function_exists('hub_client_scope')) {
    /**
     * مساحةُ عمل العميل النشطة (المبدّل العلوي) — تركيزُ عرضٍ لا أمن، كنظيرتها
     * hub_company_scope تماماً: الأمنُ الصارم في hub_scope أعلاه.
     */
    function hub_client_scope($q, string $module)
    {
        $kid = (string) session('hub.client', '');
        if ($kid === '') return $q;
        $allowed = hub_client_ids();
        if ($allowed !== null && ! in_array($kid, $allowed, true)) return $q;
        $col = hub_client_col($module);

        return $col ? $q->where($col, $kid) : $q;
    }
}

if (! function_exists('hub_modules')) {
    /** سجل الوحدات كاملاً */
    function hub_modules(): array
    {
        return config('hub.modules', []);
    }
}

if (! function_exists('hub_mod')) {
    /** تعريف وحدة واحدة أو null — يقبل null بأمان (سجلات تدقيق نظامية بلا وحدة) */
    function hub_mod(?string $key): ?array
    {
        return $key === null || $key === '' ? null : config("hub.modules.$key");
    }
}

if (! function_exists('hub_can')) {
    /** صلاحية المستخدم على وحدة: v=عرض a=إضافة e=تعديل d=حذف */
    function hub_can($user, string $module, string $op = 'v'): bool
    {
        if (! $user) return false;
        $role = $user->role;
        if (! $role) return false;
        if ($role->is_owner) return true;
        $matrix = is_array($role->matrix) ? $role->matrix : (json_decode($role->matrix ?? '[]', true) ?: []);
        return (bool) (($matrix[$module][$op] ?? 0));
    }
}

if (! function_exists('hub_nav')) {
    /** مجموعات التنقل الجانبي — تُخفى الوحدات التي لا يملك المستخدم عرضها */
    /**
     * مجموعات التنقل بعد الصلاحيات **وتخصيص المستخدم**: وحدة مخفية تسقط،
     * وتسمية بديلة تُطبق، وترتيب المجموعات يتبع اختياره — كله عرضٌ فقط:
     * الإخفاء لا يمس الصلاحية والرابط المباشر يعمل كما هو.
     */
    function hub_nav($user): array
    {
        $hidden = (array) hub_pref('nav.hidden', [], $user);
        $names  = (array) hub_pref('nav.names', [], $user);
        $order  = (array) hub_pref('nav.order', [], $user);

        $out = [];
        foreach (config('hub_nav', []) as $g) {
            $items = [];
            foreach ($g['items'] as $k) {
                if (! hub_mod($k) || ! hub_can($user, $k, 'v')) continue;
                if (in_array($k, $hidden, true)) continue;
                $alias = trim((string) ($names[$k] ?? ''));
                $items[] = ['key' => $k, 'label' => $alias !== '' ? $alias : hub_mod($k)['label']];
            }
            if ($items) $out[] = ['g' => $g['g'], 'icon' => $g['icon'], 'items' => $items];
        }

        if ($order) {
            usort($out, function ($a, $b) use ($order) {
                $ia = array_search($a['g'], $order, true); $ib = array_search($b['g'], $order, true);
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            });
        }

        return $out;
    }
}

if (! function_exists('hub_pref')) {
    /** تفضيل شخصي للمستخدم — نقطي: hub_pref('nav.hidden', []) */
    function hub_pref(string $key, $default = null, $user = null)
    {
        $u = $user ?? auth()->user();

        return data_get($u?->prefs, $key, $default);
    }
}

if (! function_exists('hub_top_links')) {
    /**
     * كتالوج روابط القائمة العلوية بصلاحيات المستخدم — مصدر واحد للشريط الجانبي
     * ولصفحة التخصيص ولاختيارات شاشة البداية.
     */
    function hub_top_links($user): array
    {
        $owner = (bool) $user?->role?->is_owner;
        $mon = $owner || hub_flag($user, 'monitor');

        // كل رابط مُصنَّف في قسم (group): daily/analytics/centers — تستعمله hub_top_groups
        $all = [
            ['key' => 'morning',   'label' => '☀️ تشغيل اليوم',      'route' => 'morning',         'group' => 'daily',     'ok' => true],
            ['key' => 'me',        'label' => '👤 بوابتي',           'route' => 'portal.me',       'group' => 'daily',     'ok' => true],
            ['key' => 'alerts',    'label' => '🔔 ينتهي قريباً',     'route' => 'alerts',          'group' => 'daily',     'ok' => true],
            ['key' => 'calendar',  'label' => '📅 التقويم',          'route' => 'calendar',        'group' => 'daily',     'ok' => true],
            ['key' => 'feed',      'label' => '📣 قناة الفريق',      'route' => 'feed',            'group' => 'daily',     'ok' => true],
            ['key' => 'dm',        'label' => '💬 الرسائل',          'route' => 'dm.inbox',        'group' => 'daily',     'ok' => true],
            ['key' => 'inboxdocs', 'label' => '📥 صندوق الوثائق',    'route' => 'inboxdocs.index', 'group' => 'daily',     'ok' => hub_can($user, 'inboxdocs', 'v') || hub_can($user, 'files', 'v')],

            ['key' => 'ceo',       'label' => '👑 لوحة CEO',         'route' => 'ceo',             'group' => 'analytics', 'ok' => $owner],
            ['key' => 'perf',      'label' => '📈 لوحة الأداء',      'route' => 'performance',     'group' => 'analytics', 'ok' => $mon],
            ['key' => 'sales',     'label' => '💼 لوحة المبيعات',    'route' => 'sales.dashboard', 'group' => 'analytics', 'ok' => $mon],
            ['key' => 'finrep',    'label' => '📊 التقارير المالية', 'route' => 'reports.finance', 'group' => 'analytics', 'ok' => hub_can($user, 'fin', 'v')],
            ['key' => 'costs',     'label' => '💰 التكاليف والربحية', 'route' => 'costs.index',    'group' => 'analytics', 'ok' => $mon],
            ['key' => 'svccosts',  'label' => '🧮 تكلفة الخدمات',    'route' => 'servicecosts',    'group' => 'analytics', 'ok' => $mon],
            ['key' => 'kpis',      'label' => '📈 مؤشرات KPI',       'route' => 'kpis.index',      'group' => 'analytics', 'ok' => $mon],
            ['key' => 'capacity',  'label' => '📊 القدرات والموارد', 'route' => 'capacity',        'group' => 'analytics', 'ok' => $mon],
            ['key' => 'recs',      'label' => '💡 مركز التوصيات',    'route' => 'recs',            'group' => 'analytics', 'ok' => $mon],
            ['key' => 'impact',    'label' => '🕸️ خريطة الأثر',      'route' => 'impact',          'group' => 'analytics', 'ok' => $mon],
            ['key' => 'appq',      'label' => '🧪 جودة البرمجيات',   'route' => 'appquality',      'group' => 'analytics', 'ok' => $mon],
            ['key' => 'delivery',  'label' => '🛤️ مسار التسليم',     'route' => 'delivery',        'group' => 'analytics', 'ok' => hub_can($user, 'feats', 'v') || hub_can($user, 'deploys', 'v') || hub_can($user, 'requests', 'v') || hub_can($user, 'designs', 'v')],
            ['key' => 'custody',   'label' => '🏷️ كتالوج العهد',      'route' => 'custody.catalog', 'group' => 'centers',   'ok' => hub_can($user, 'assets', 'v')],
            ['key' => 'identity',  'label' => '📷 مركز الهوية والمسح', 'route' => 'identity.center', 'group' => 'centers',   'ok' => hub_can($user, 'assets', 'v')],
            ['key' => 'workteam',  'label' => '🕗 فريقي اليوم',        'route' => 'workforce.team',  'group' => 'centers',   'ok' => hub_can($user, 'hr', 'v')],
            ['key' => 'codehub',   'label' => '🌿 مركز الكود',        'route' => 'code.center',     'group' => 'centers',   'ok' => hub_can($user, 'code', 'v')],
            ['key' => 'assetlife', 'label' => '💼 العهدة ودورة الحياة', 'route' => 'assets.life',  'group' => 'centers',   'ok' => hub_can($user, 'assets', 'v')],
            ['key' => 'compb',     'label' => '⚖️ الامتثال وأثره',   'route' => 'compliance.board', 'group' => 'centers', 'ok' => hub_can($user, 'compliance', 'v')],
            ['key' => 'appsproj',  'label' => '🔗 التطبيقات والمشاريع', 'route' => 'appsprojects', 'group' => 'centers', 'ok' => hub_can($user, 'apps', 'v') || hub_can($user, 'projects', 'v')],
            ['key' => 'dassets',   'label' => '🔐 الأصول الرقمية',   'route' => 'digital.assets',  'group' => 'analytics', 'ok' => $mon],
            ['key' => 'pricing',   'label' => '💳 الباقات والتسعير', 'route' => 'pricing',         'group' => 'centers',   'ok' => hub_can($user, 'plans', 'v')],
            ['key' => 'mediac',    'label' => '📣 مركز الإعلام',      'route' => 'media.center',    'group' => 'centers',   'ok' => hub_can($user, 'media', 'v') || hub_can($user, 'events', 'v')],
            ['key' => 'teamdir',   'label' => '👥 دليل الفريق',       'route' => 'team',            'group' => 'centers',   'ok' => hub_can($user, 'hr', 'v')],
            ['key' => 'staff',     'label' => '🪪 الموظفون وحساباتهم', 'route' => 'staff.index',    'group' => 'centers',   'ok' => hub_can($user, 'hr', 'v')],
            ['key' => 'okrb',      'label' => '🎯 لوحة الأهداف',     'route' => 'okrs.board',      'group' => 'analytics', 'ok' => hub_can($user, 'okrs', 'v')],
            ['key' => 'polb',      'label' => '📜 السياسات والإقرارات', 'route' => 'policies.board', 'group' => 'centers',   'ok' => hub_can($user, 'policies', 'v')],
            ['key' => 'social',    'label' => '📣 مركز السوشال',     'route' => 'social.index',    'group' => 'analytics', 'ok' => hub_can($user, 'social', 'v')],

            ['key' => 'legal',     'label' => '⚖️ القانوني',         'route' => 'legal',           'group' => 'centers',   'ok' => hub_can($user, 'contracts', 'v')],
            ['key' => 'esign',     'label' => '✍️ توقيع العقود',     'route' => 'esign.index',     'group' => 'centers',   'ok' => hub_can($user, 'contracts', 'v')],
            ['key' => 'support',   'label' => '🎫 لوحة الدعم',       'route' => 'support',         'group' => 'centers',   'ok' => hub_can($user, 'tickets', 'v')],
            ['key' => 'innov',     'label' => '💡 مركز الابتكار',    'route' => 'innovation',      'group' => 'centers',   'ok' => hub_can($user, 'ideas', 'v')],
            ['key' => 'supscores', 'label' => '🏅 تقييم الموردين',   'route' => 'supplierscores',  'group' => 'centers',   'ok' => hub_can($user, 'suppliers', 'v')],
        ];

        return array_values(array_filter($all, fn ($l) => $l['ok']));
    }
}

if (! function_exists('hub_pin_targets')) {
    /**
     * **مثبّتاتي** — رصيفٌ شخصيّ لأكثر وجهاتٍ يستعملها المستخدم يومياً، نقرةٌ
     * واحدة فوق كل شيء. لنظامٍ من ٧١ وحدةً ومساحاتٍ ومراكز، هذا هو المكسبُ:
     * لا تنقيبَ في مجموعة ولا فتحَ مساحةٍ ولا ⌘K لوجهتك المتكررة.
     *
     * الرمز نصّ: `m:{مفتاح وحدة}` أو مفتاحُ رابطٍ علوي — نفسُ ترميز `home`.
     * كلُّ وجهةٍ يجوز تثبيتُها لهذا المستخدم (وحدةٌ يراها أو رابطٌ يُسمح له به).
     */
    function hub_pin_targets($user): array
    {
        $out = [];
        foreach (hub_top_links($user) as $l) {
            $out[$l['key']] = ['token' => $l['key'], 'label' => $l['label'], 'route' => $l['route'], 'args' => []];
        }
        foreach (hub_modules() as $mk => $md) {
            if (! hub_can($user, $mk, 'v') || $mk === 'users') continue;
            $look = hub_mod_look($mk);
            $out['m:' . $mk] = ['token' => 'm:' . $mk,
                'label' => ($look['icon'] ?? '📄') . ' ' . ($md['label'] ?? $mk),
                'route' => 'm.index', 'args' => [$mk]];
        }

        return $out;
    }
}

if (! function_exists('hub_pins')) {
    /** المثبّتات المحلولةُ للعرض — رموزٌ غير صالحةٍ تُسقَط بصمتٍ كسائر التفضيلات */
    function hub_pins($user = null): array
    {
        $user = $user ?? auth()->user();
        $tokens = (array) hub_pref('nav.pins', [], $user);
        if (! $tokens) return [];

        $targets = hub_pin_targets($user);
        $out = [];
        foreach ($tokens as $t) {
            if (isset($targets[$t])) $out[] = $targets[$t];
        }

        return $out;
    }
}

if (! function_exists('hub_is_pinned')) {
    function hub_is_pinned(string $token, $user = null): bool
    {
        return in_array($token, (array) hub_pref('nav.pins', [], $user), true);
    }
}

if (! function_exists('hub_top_groups')) {
    /**
     * أدوات ولوحات الشريط الجانبي **مجموعةً في أقسام** بدل ٢١ رابطاً مسطّحاً.
     * يحترم صلاحية كل رابط (من hub_top_links) وإخفاء المستخدم (nav.hidden_top).
     * «مساحتي اليومية» مفتوحة افتراضياً؛ الباقي مطويّ ليبقى الشريط نظيفاً.
     */
    function hub_top_groups($user): array
    {
        $hidden = (array) hub_pref('nav.hidden_top', [], $user);
        $links  = collect(hub_top_links($user))
            ->reject(fn ($l) => in_array($l['key'], $hidden, true))
            ->groupBy('group');

        // مجموعتان لا ثلاث: «المراكز المتخصّصة» ضُمّت للتحليلات — تخفيفاً للعجقة
        $defs = [
            ['key' => ['daily'],                'label' => 'مساحتي اليومية',    'icon' => '🧭', 'open' => true],
            ['key' => ['analytics', 'centers'], 'label' => 'اللوحات والمراكز',  'icon' => '📊', 'open' => false],
        ];

        $out = [];
        foreach ($defs as $d) {
            $items = collect($d['key'])->flatMap(fn ($k) => $links->get($k, collect()))->values()->all();
            if ($items) $out[] = $d + ['items' => $items];
        }

        return $out;
    }
}

if (! function_exists('hub_home_url')) {
    /**
     * شاشة البداية بعد الدخول: من تفضيل المستخدم `home` —
     * صفحة من الكتالوج أو وحدة بصيغة m:tasks. الاختيار غير الصالح يسقط للوحة التحكم.
     */
    function hub_home_url($user = null): string
    {
        $u = $user ?? auth()->user();
        $home = (string) hub_pref('home', '', $u);

        if (str_starts_with($home, 'm:')) {
            $mk = substr($home, 2);
            if (hub_mod($mk) && hub_can($u, $mk, 'v')) return route('m.index', $mk);
        } elseif ($home !== '' && $home !== 'dashboard') {
            foreach (hub_top_links($u) as $l) {
                if ($l['key'] === $home) return route($l['route']);
            }
        }

        return route('dashboard');
    }
}

if (! function_exists('hub_ref_table')) {
    /** جدول الوجهة لحقل مرجعي */
    function hub_ref_table(string $ref): ?string
    {
        if ($ref === 'roles') return 'roles';
        return hub_mod($ref)['table'] ?? null;
    }
}

if (! function_exists('hub_display_col')) {
    /**
     * عمود القاعدة الفعلي لعرض وحدة — السجل قد يذكر مفتاح الحقل لا العمود
     * (companies: display=nameAr والعمود name_ar) — الاستعلام بالمفتاح يكسر MySQL.
     */
    function hub_display_col(string $module): string
    {
        static $map = [];
        if (isset($map[$module])) return $map[$module];

        $def  = hub_mod($module);
        $disp = $def['display'] ?? 'name';
        $f    = collect($def['fields'] ?? [])->firstWhere('key', $disp);

        return $map[$module] = $f['col'] ?? $disp;
    }
}

if (! function_exists('hub_ref_display')) {
    /** عمود العرض لجدول مرجعي */
    function hub_ref_display(string $ref): string
    {
        if ($ref === 'roles') return 'name';
        return hub_display_col($ref);
    }
}

if (! function_exists('hub_ref_labels')) {
    /** أسماء عرض لمجموعة معرّفات مرجعية: [id => label] */
    function hub_ref_labels(string $ref, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $table = hub_ref_table($ref);
        if (! $ids || ! $table) return [];
        return \Illuminate\Support\Facades\DB::table($table)
            ->whereIn('id', $ids)
            ->pluck(hub_ref_display($ref), 'id')
            ->all();
    }
}

if (! function_exists('hub_ref_options')) {
    /** خيارات قائمة مرجعية (حد 500) */
    /**
     * @param mixed $ensure معرّف (أو معرّفات) لا بد أن تظهر في القائمة ولو تجاوزت الحد.
     *
     * الحد ٥٠٠ يبقي القوائم خفيفة، لكن سجلاً يشير لمرجع خارج الحد كان يُفتح بنموذج
     * لا يحوي خياره — فيُرسل الفراغ عند الحفظ **ويُمحى الرابط بصمت**. لذا تُضاف
     * القيم المختارة حالياً دائماً.
     */
    function hub_ref_options(string $ref, $ensure = null): array
    {
        $table = hub_ref_table($ref);
        if (! $table) return [];

        $disp = hub_ref_display($ref);
        $rows = \Illuminate\Support\Facades\DB::table($table)
            ->whereNull('deleted_at')
            ->orderBy($disp)
            ->limit(500)
            ->pluck($disp, 'id')
            ->all();

        $need = array_filter(array_diff(array_map('strval', array_filter((array) $ensure)), array_map('strval', array_keys($rows))));
        if ($need) {
            $rows += \Illuminate\Support\Facades\DB::table($table)
                ->whereIn('id', $need)->pluck($disp, 'id')->all();
        }

        return $rows;
    }
}

if (! function_exists('hub_security_incident')) {
    /**
     * يفتح **حادثةً أمنيّة** على وحدة `incidents` القائمة (لا محرك حوادث ثانٍ):
     * حدثُ المستوى الحرج (قفلُ حساب، سفرٌ مستحيلٌ لاحقاً، تصعيدُ صلاحية) يصير
     * حالةً تُحقَّق لا إشعاراً يضيع. يمنع الإغراق: حادثةٌ مفتوحةٌ بالعنوان نفسه
     * خلال النافذة تُعاد بدل فتح ثانية. الأدلّةُ في `meta` لا في متن يُفهرَس.
     *
     * @param array $meta أدلّةٌ وسياق (user_id, ip, evidence…)
     */
    function hub_security_incident(string $title, string $severity = 'عالي', array $meta = [], int $dedupHours = 6)
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('incidents')) return null;
        try {
            $open = \App\Models\Incident::whereNull('deleted_at')
                ->where('title', $title)
                ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])
                ->where('created_at', '>=', now()->subHours(max(1, $dedupHours)))
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($open) {
                // حادثةٌ قائمة — تُثرى بدليلٍ جديد لا تُكرَّر
                $open->meta = array_merge((array) $open->meta, [
                    'events' => array_merge((array) (($open->meta['events'] ?? [])), [[
                        'at' => now()->toIso8601String(), 'evidence' => $meta,
                    ]]),
                ]);
                $open->saveQuietly();

                return $open;
            }

            return \App\Models\Incident::create([
                'title' => \Illuminate\Support\Str::limit($title, 190, ''),
                'severity' => in_array($severity, ['حرج', 'عالي', 'متوسط', 'منخفض'], true) ? $severity : 'عالي',
                'status' => 'مفتوح',
                'started_at' => now(),
                'affected' => 'حادثةٌ أمنيّة مُولَّدة آلياً — للتحقيق البشريّ لا للعقاب الآليّ',
                'meta' => ['kind' => 'security', 'auto' => true, 'events' => [[
                    'at' => now()->toIso8601String(), 'evidence' => $meta,
                ]]],
            ]);
        } catch (\Throwable $e) {
            \App\Support\ErrorLog::capture('php', 'hub_security_incident: ' . $e->getMessage(), __FILE__, __LINE__);

            return null;
        }
    }
}

if (! function_exists('hub_stepup_ok')) {
    /** هل تصعيدُ المصادقة ساري المفعول الآن؟ (نافذةٌ قصيرة بعد إعادة التحقّق) */
    function hub_stepup_ok(): bool
    {
        return \App\Support\StepUp::fresh();
    }
}

if (! function_exists('hub_require_stepup')) {
    /**
     * حارسُ فعلٍ حسّاس: إن لم يكن التصعيدُ سارياً يُعيد استجابةَ توجيهٍ (ويب)
     * أو 428 بحمولةٍ (JSON) توجّه العميلَ لشاشة التأكيد. يُرجع `null` إن كان
     * التصعيدُ سارياً فيمضي المتحكّمُ في فعله. المالكُ لا يُستثنى: القوّةُ
     * أدعى للتأكيد لا أدعى للتجاوز.
     */
    function hub_require_stepup(?string $next = null)
    {
        if (\App\Support\StepUp::fresh()) return null;

        $next = $next ?: request()->fullUrl();
        // للويب: وجهةٌ داخلية فقط (المسار والاستعلام) لا رابطٌ مطلق
        $path = request()->getRequestUri();
        $url = route('stepup.show', ['next' => is_string($next) && str_starts_with($next, '/') ? $next : $path]);

        if (request()->expectsJson() || request()->is('api/*')) {
            // الغلافُ الموحَّد: المفاتيحُ القديمة (error/stepup/url) كما هي + code + request_id
            return \App\Support\Api::error(\App\Support\Api::STEP_UP_REQUIRED, 428,
                'يتطلب تأكيدَ الهوية', null, ['stepup' => true, 'url' => $url]);
        }

        return redirect($url);
    }
}

if (! function_exists('hub_schedule_failed')) {
    /**
     * فشلُ مهمةٍ مجدولة **لا يبقى صامتاً** (v2.399): كانت الأوامرُ تعود برمزٍ غيرِ صفريّ
     * ونصٍّ يذهب إلى /dev/null في cron — فيفشل فحصُ سلسلة التدقيق والنسخُ الاحتياطيّ
     * أسابيعَ بلا إشعار. يُلتقط في مركز الأخطاء (فيُشعَر المالكون والمراقبون)، ويُكتب
     * في نبضة المجدولة نتيجةً، وكسرُ السلسلة يفتح حادثةً أمنية.
     */
    function hub_schedule_failed(string $command, string $category = 'QUEUE', string $severity = 'ERROR'): void
    {
        $job = str_replace(['hub:', 'metrics-snapshot', 'quality-snapshot', 'uptime-check', 'audit-verify'], ['', 'metrics', 'quality', 'uptime', 'audit'], $command);
        \App\Support\ErrorLog::capture('php', 'فشل مهمة مجدولة: ' . $command . ' — راجع مركز التشغيل وكتيّبات التشغيل',
            'routes/console.php', null, null, ['category' => $category, 'severity' => $severity]);
        try {
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.' . $job . '.meta'],
                ['value' => ['ms' => null, 'result' => 'fail', 'note' => 'فشل التشغيل المجدول', 'at' => now()->toIso8601String()]]);
            \Illuminate\Support\Facades\Cache::forget('settings:all');
        } catch (\Throwable $e) {
        }
        if ($command === 'hub:audit-verify') {
            hub_security_incident('فشل فحص سلسلة التدقيق — عبثٌ محتمل أو ختمٌ مثقوب', 'حرج', ['command' => $command]);
        }
    }
}

if (! function_exists('hub_require_credential_stepup')) {
    /**
     * تصعيدُ المصادقة قبل سكّ اعتمادٍ طويل الأمد (رمز API، مفتاح مرور) — v2.399.
     * مفعّلٌ افتراضاً (`security.stepup_credentials`=1): جلسةٌ مختطفة كانت تزرع مفتاحاً
     * أو تسكّ رمزاً يبقى بعد تغيير الكلمة وإنهاء الجلسات. يُطفأ بالإعداد لمن يريد.
     */
    function hub_require_credential_stepup(?string $next = null)
    {
        if ((string) setting('security.stepup_credentials', '1') !== '1') return null;

        return hub_require_stepup($next);
    }
}

if (! function_exists('hub_ref_options_scoped')) {
    /**
     * خيارات قائمة مرجعية **منطَّقة** — نظير `hub_ref_options` لكنه يحترم عزل
     * المستخدم: مشاريعه المرئية، وشركاته المسموحة، وعملاءه المسموحين. يُستعمل
     * في كل نموذجٍ خارج المتحكم العام (الذي يعوّض بنفسه في `refOptions`) —
     * فقائمةٌ خامٌّ كانت تسرّب أسماء مشاريع وعملاء لا يراهم صاحب الحساب.
     */
    function hub_ref_options_scoped(string $ref, $ensure = null, $user = null): array
    {
        $opts = hub_ref_options($ref, $ensure);
        $user = $user ?? auth()->user();
        if (! $user) return $opts;

        if ($ref === 'projects' && hub_scoped($user)) {
            $opts = array_intersect_key($opts, array_flip(array_map('strval', $user->visibleProjectIds())));
        }
        if (($cids = hub_company_ids($user)) !== null && ($ccol = hub_company_col($ref))) {
            $allowed = \Illuminate\Support\Facades\DB::table(hub_ref_table($ref))
                ->whereIn($ccol, $cids)->pluck('id')->map(fn ($v) => (string) $v)->all();
            $opts = array_intersect_key($opts, array_flip($allowed));
        }
        if (($kids = hub_client_ids($user)) !== null && ($kcol = hub_client_col($ref))) {
            $allowed = \Illuminate\Support\Facades\DB::table(hub_ref_table($ref))
                ->whereIn($kcol, $kids)->pluck('id')->map(fn ($v) => (string) $v)->all();
            $opts = array_intersect_key($opts, array_flip($allowed));
        }
        // وحدة العملاء نفسها معزولةٌ بمعرّفها المباشر لا بعمود عميلٍ فيها
        if ($ref === 'clients' && ($kids = hub_client_ids($user)) !== null) {
            $opts = array_intersect_key($opts, array_flip(array_map('strval', $kids)));
        }

        return $opts;
    }
}

if (! function_exists('hub_guard_scope_input')) {
    /**
     * حارسُ عزلٍ لمدخلاتِ نموذجٍ **خارج** محرّك الوحدات (v2.399): لكل مفتاحٍ في المصفوفة
     * يُفحص أن قيمتَه (إن أُرسلت) داخل نطاق المستخدم — شركاته أو عملائه أو مشاريعه
     * المرئية. يرمي `ValidationException` بالرسالة نفسِها التي يرميها المحرّك، فلا يختلف
     * بابٌ مخصّص عن الباب العامّ. المالكُ يمرّ دائماً.
     *
     * @param array<string,string> $map  مفتاحُ المدخل ⟵ 'companies' | 'clients' | 'projects'
     */
    function hub_guard_scope_input(array $data, array $map, $user = null): void
    {
        $user = $user ?? auth()->user();
        if (! $user || hub_is_owner($user)) return;
        $errors = [];
        foreach ($map as $key => $ref) {
            $v = hub_str($data[$key] ?? null);
            if ($v === '') continue;
            $allowed = match ($ref) {
                'companies' => hub_company_ids($user),
                'clients' => hub_client_ids($user),
                'projects' => hub_scoped($user) ? $user->visibleProjectIds() : null,
                default => null,
            };
            if ($allowed === null || in_array($v, array_map('strval', $allowed), true)) continue;
            $errors[$key] = match ($ref) {
                'companies' => 'حسابك معزول على شركات محددة — اختر شركة من شركاتك',
                'clients' => 'حسابك معزول على عملاء محددين — اختر عميلاً من عملائك',
                default => 'حسابك محدود النطاق — اختر مشروعاً من مشاريعك',
            };
        }
        if ($errors) throw \Illuminate\Validation\ValidationException::withMessages($errors);
    }
}

if (! function_exists('hub_flag')) {
    /** أعلام الدور الإدارية: users · audit · approve · secrets … — المالك يملكها كلها */
    function hub_flag($user, string $flag): bool
    {
        $role = $user?->role;
        if (! $role) return false;
        if ($role->is_owner) return true;
        $flags = is_array($role->flags) ? $role->flags : (json_decode($role->flags ?? '[]', true) ?: []);
        return (bool) ($flags[$flag] ?? 0);
    }
}

/*
 * السياسات المسمّاة — الطبقة الرقيقة التي تُسمّي «من يرى هذه الشاشة».
 *
 * كانت هذه السياسات السبع منسوخة في ~٤٠ تعبيراً مضمّناً، فتفرّقت صياغتها:
 * بعضها يكتب `auth()->user()->role?->is_owner` وبعضها `auth()->user()?->role?->is_owner`،
 * وكلها تُضيف `is_owner ||` قبل `hub_flag(...)` رغم أن `hub_flag` تُرجع true للمالك أصلاً.
 * تسميتها هنا تجعل السياسة قابلة للقراءة والتغيير في موضع واحد، وتُؤمّن المستخدم المعدوم
 * (أوامر الطرفية والمهام المجدولة) بلا اعتماد على وسيط auth.
 */

if (! function_exists('hub_is_owner')) {
    /** مالك النظام — أعلى سلطة، يتجاوز المصفوفة والأعلام. تُفوِّض لـUser::isOwner فتبقى التعريفة واحدة */
    function hub_is_owner($user = null): bool
    {
        $user = $user ?? auth()->user();
        return $user instanceof \App\Models\User
            ? $user->isOwner()
            : (bool) ($user?->role?->is_owner);
    }
}

if (! function_exists('hub_monitor')) {
    /** المراقبة: لوحات الأداء والتكلفة والطاقة — بيانات أداء الأفراد حساسة */
    function hub_monitor($user = null): bool
    {
        return hub_flag($user ?? auth()->user(), 'monitor');
    }
}

if (! function_exists('hub_approver')) {
    /** اعتماد الطلبات وأوامر الشراء */
    function hub_approver($user = null): bool
    {
        return hub_flag($user ?? auth()->user(), 'approve');
    }
}

if (! function_exists('hub_secrets')) {
    /** الاطلاع على الأسرار المخزّنة (الخزنة، غرفة البيانات) */
    function hub_secrets($user = null): bool
    {
        return hub_flag($user ?? auth()->user(), 'secrets');
    }
}

if (! function_exists('hub_user_admins')) {
    /** من يدير المستخدمين: المالكون وحاملو راية «إدارة المستخدمين» — لإشعارات مراجعة الصلاحيات */
    function hub_user_admins(): array
    {
        return \App\Models\User::with('role')->whereNull('deleted_at')->where('status', 'نشط')->get()
            ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'users'))
            ->pluck('id')->all();
    }
}

if (! function_exists('hub_has_col')) {
    /**
     * **هل هذا العمود موجودٌ فعلاً؟** — مخبّأً، للمسارات التي تعمل في كل صفحة.
     *
     * نشرُ شيفرةٍ قبل تشغيل هجرتها أمرٌ يقع: يُرفع الكود وتُنسى `php artisan
     * migrate` دقائق أو ساعة. وما دام القارئ شاشةً واحدة فالأثر شاشة؛ أما إن
     * كان يعمل في **كل صفحة** — كشارة الشريط الجانبي — فالنظام كلُّه لا يفتح،
     * و`Unknown column` في كل طلب. (وSQLite تتساهل حيث ترمي MySQL، فالحزمةُ
     * خضراء والخادمُ واقف.)
     *
     * القاعدة: **الهجرةُ المتأخرة تُنقص ميزةً ولا تُطفئ نظاماً.**
     */
    function hub_has_col(string $table, string $col): bool
    {
        return (bool) \Illuminate\Support\Facades\Cache::remember(
            'hub:hascol:' . $table . '.' . $col, 300,
            fn () => \Illuminate\Support\Facades\Schema::hasTable($table)
                  && \Illuminate\Support\Facades\Schema::hasColumn($table, $col)
        );
    }
}

if (! function_exists('hub_assignable_roles')) {
    /**
     * الأدوار القابلة للمنح من هذا المستخدم — **غير المالك لا يمنح ملكيةً فلا
     * تُعرض له**. الحارس مفروضٌ في الخادم كذلك (Staff::makeAccount)، وهذا
     * إخفاءُ ما لا يُمنح حتى لا يُعرض خيارٌ يُرفض عند الحفظ.
     */
    function hub_assignable_roles($user = null)
    {
        $user = $user ?? auth()->user();

        return \App\Models\Role::when(! hub_is_owner($user), fn ($q) => $q->where('is_owner', false))
            ->orderByDesc('is_owner')->orderBy('name')->get(['id', 'name', 'is_owner']);
    }
}

if (! function_exists('hub_copy_secrets')) {
    /** نسخ سرّ إلى الحافظة — علم منفصل عن مجرد الاطلاع */
    function hub_copy_secrets($user = null): bool
    {
        $user = $user ?? auth()->user();
        return hub_flag($user, 'secrets') || hub_flag($user, 'copySec');
    }
}

if (! function_exists('hub_exporter')) {
    /** التصدير إلى CSV — مسرّب بيانات محتمل فله علمه */
    function hub_exporter($user = null): bool
    {
        return hub_flag($user ?? auth()->user(), 'exp');
    }
}

if (! function_exists('hub_safe_url')) {
    /**
     * رابط آمن للعرض: يسمح فقط بمخططات غير قابلة للتنفيذ (http/https/mailto/tel)
     * أو الروابط النسبية — ويحيّد javascript: وdata: وغيرها إلى # كي لا تنفَّذ
     * عند النقر. يُغلّف كل قيم حقول url القادمة من المستخدم قبل وضعها في href.
     */
    function hub_safe_url(?string $v): string
    {
        $v = trim((string) $v);
        if ($v === '') return '#';
        // المتصفحات تُزيل التبويب والأسطر من الروابط قبل تفسيرها، فـ«java[tab]script:»
        // ينفَّذ — نُحاكي ذلك: نجرّد كل محارف التحكم قبل فحص المخطط لا بعده.
        $probe = preg_replace('/[\x00-\x1F\x7F]+/', '', $v);
        if (preg_match('/^([a-z][a-z0-9+.\-]*)\s*:/i', $probe, $m)) {
            return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true) ? $v : '#';
        }
        // بلا مخطط: رابط نسبي أو //host — نمنع // المفتوح لتفادي الغموض، ونقبل الباقي
        return str_starts_with($probe, '//') ? '#' : $v;
    }
}

if (! function_exists('hub_tone')) {
    /** لون شارة الحالة من دلالة الكلمة العربية */
    function hub_tone(?string $v): string
    {
        if (! $v) return 'g';
        foreach (['مكتمل', 'منجز', 'نشط', 'مدفوع', 'مقبول', 'موافق', 'ناجح', 'مغلق', 'تم', 'ساري', 'معتمد', 'فوز'] as $w) if (str_contains($v, $w)) return 'ok';
        foreach (['متأخر', 'ملغ', 'مرفوض', 'موقوف', 'فشل', 'حرج', 'منته', 'خسار'] as $w) if (str_contains($v, $w)) return 'bad';
        foreach (['قيد', 'انتظار', 'جديد', 'مسود', 'معلق', 'مراجعة', 'تجريب'] as $w) if (str_contains($v, $w)) return 'wn';
        return 'g';
    }
}

if (! function_exists('hub_mod_look')) {
    /**
     * هوية بصرية للوحدة: أيقونة ولون — من مجموعتها في التنقل، مع تخصيصٍ للأشهر.
     * تُغذي ترويسات صفحات الوحدات فيعرف المستخدم أين هو من نظرة.
     */
    function hub_mod_look(string $module): array
    {
        static $groupColor = [
            'الكيانات' => '#4C6FA5', 'الأصول الرقمية' => '#2FB79A', 'العمل' => '#0E7C66',
            'المالية والمشتريات' => '#B0568E', 'الموارد البشرية' => '#C08A3E',
            'الأصول والعقود' => '#7C6FB0', 'المعرفة والملفات' => '#6B9080',
            'العمليات الميدانية' => '#3E8FB0',
        ];
        static $icons = [
            'projects' => '🚀', 'clients' => '🤝', 'tasks' => '✅', 'tickets' => '🎫',
            'fin' => '💵', 'contracts' => '📜', 'companies' => '🏢', 'hr' => '👥',
            'quotes' => '📝', 'changeorders' => '📋', 'suppliers' => '🚚', 'purchases' => '🛒', 'ideas' => '💡',
            'leaves' => '🗓️', 'apps' => '📱', 'domains' => '🌐', 'servers' => '🖥️',
            'vault' => '🔐', 'kb' => '📚', 'meetings' => '🪑', 'assets' => '📦',
            'hcps' => '🩺', 'facilities' => '🏥', 'territories' => '🗺️',
            'cycles' => '🔄', 'visits' => '📋',
        ];

        $icon = $icons[$module] ?? '📁';
        $color = 'var(--p)';
        foreach (config('hub_nav', []) as $g) {
            if (in_array($module, $g['items'], true)) {
                $color = $groupColor[$g['g']] ?? $color;
                if (! isset($icons[$module])) $icon = $g['icon'];
                break;
            }
        }

        return ['icon' => $icon, 'color' => $color];
    }
}

if (! function_exists('hub_children')) {
    /** الوحدات التي تشير إلى هذه الوحدة بحقل مرجعي: [[moduleKey, field], …] */
    function hub_children(string $module): array
    {
        static $map = null;

        if ($map === null) {
            // بناء الخريطة يستجوب مخطط القاعدة عن عمودَي الربط في ٧١ جدولاً. لو تُرك
            // بلا تخبئة لأضاف ~١٤٠ استعلام استجواب إلى أول صفحة سجل في كل طلب.
            // الخريطة تتبع الإعداد والمخطط معاً، وكلاهما لا يتغيّر إلا بإصدار — فمفتاحها الإصدار.
            $key = 'hub:children:' . config('hub.version', '0');
            try {
                $map = \Illuminate\Support\Facades\Cache::rememberForever($key, fn () => hub_build_children_map());
            } catch (\Throwable $e) {
                $map = hub_build_children_map();     // تخبئة معطّلة لا تُعطّل العلاقات
            }
        }

        return $map[$module] ?? [];
    }
}

if (! function_exists('hub_build_children_map')) {
    /** بناء خريطة العلاقات العكسية: المراجع المفردة والمتعددة وأعمدة الربط الضمنية */
    function hub_build_children_map(): array
    {
        $map = [];

        // أعمدة ربط موجودة في القاعدة ولا يُصرّح بها السجل كمراجع.
        // قائمة بيضاء بعمودين لا غير: هما محورا النظام، وتوسيعها يفتح ترشيحاً
        // على أعمدة عشوائية من الرابط.
        $implicit = ['company_id' => 'companies', 'project_id' => 'projects'];

        foreach (hub_modules() as $ck => $cd) {
            $declared = [];
            foreach ($cd['fields'] as $f) {
                if (($f['type'] ?? '') !== 'ref' || ($f['ref'] ?? '') === '') continue;
                $declared[] = $f['col'];
                // المتعدد يُدرَج الآن: كان الشرط `empty($f['multi'])` يُسقط ١١ علاقة عكسية،
                // فلا جواب لسؤال «أي موظف يحمل هذا الجهاز؟» ولا «أي عميل يشتري هذه الخدمة؟»
                $map[$f['ref']][] = [$ck, $f];
            }

            // قراءة أعمدة الجدول مرة واحدة بدل استجواب لكل عمود
            $table = $cd['table'] ?? null;
            try {
                $cols = $table ? \Illuminate\Support\Facades\Schema::getColumnListing($table) : [];
            } catch (\Throwable $e) {
                $cols = [];
            }

            foreach ($implicit as $col => $ref) {
                if ($ck === $ref || in_array($col, $declared, true)) continue;
                if (! in_array($col, $cols, true)) continue;

                $map[$ref][] = [$ck, [
                    'key' => $col, 'col' => $col, 'ref' => $ref, 'type' => 'ref',
                    'label' => hub_mod($ref)['label'] ?? $ref,
                    'implicit' => true,
                ]];
            }
        }

        return $map;
    }
}

if (! function_exists('hub_expiry_fields')) {
    /**
     * حقول التواريخ المصيرية عبر كل الوحدات (انتهاء/تجديد/استحقاق).
     * راية 'expiry' المعلنة في السجل تُحترم أولاً وتحسم الاتجاهين: true تُدخل
     * الحقل ولو لم يطابق الأنماط (الصيانة القادمة، متابعة العميل، صلاحية العرض)،
     * وfalse تُخرجه ولو طابقها («تاريخ النهاية» لمعرضٍ ليس انتهاء رخصة).
     * كانت الراية تُكتب في التعريفات ولا يقرؤها أي سطر.
     */
    function hub_expiry_fields(): array
    {
        static $out = null;
        if ($out !== null) return $out;
        $out = [];
        foreach (hub_modules() as $mk => $md) {
            foreach ($md['fields'] as $f) {
                if (! in_array($f['type'], ['date', 'dt'], true)) continue;
                if (array_key_exists('expiry', $f)) {
                    if ($f['expiry']) $out[] = [$mk, $f];
                    continue;
                }
                $byKey  = in_array($f['key'], ['end', 'due', 'expiry', 'expires', 'renew', 'renewal', 'warranty'], true)
                          || preg_match('/exp$|Exp$/', $f['key']);
                $byLbl  = preg_match('/انتها|تجديد|استحقاق|نهاية|ضمان/u', $f['label']);
                if ($byKey || $byLbl) $out[] = [$mk, $f];
            }
        }
        return $out;
    }
}

if (! function_exists('hub_expiry')) {
    /** رادار الانتهاءات: كل ما ينتهي خلال 30 يوماً أو انتهى فعلاً — مخبأ، ومحدود بنطاق المستخدم */
    function hub_expiry(bool $fresh = false, $user = null): array
    {
        $user   = $user ?? auth()->user();
        // مقيد = نطاق مشاريع أو عزل شركات **أو عزل عملاء** — مخبأ خاص به كي لا تتسرب
        // أسماء أجنبية عبر المخبأ المشترك. كان عزلُ العميل غائباً عن هذا الشرط: مستخدمٌ
        // محصورٌ بعميلٍ (hub_client_ids) وحده كان يُرى `$scoped=false` فلا يُطبَّق
        // hub_scope أصلاً (السطر أدناه)، فيسرد رادارُ الانتهاءات سجلاتِ كلِّ العملاء —
        // وفوقها يتقاسم مستخدمو الدور الواحد مخبأ `r:` فيسرّب أحدُهم للآخر.
        $scoped = hub_scoped($user) || hub_company_ids($user) !== null || hub_client_ids($user) !== null;
        // المخبأ يفصل بالدور أيضاً: وثائق الملفات تُرشَّح بصلاحية رؤية وحدتها،
        // فمخبأٌ مشترك بين دورين مختلفين كان سيسرّب ما لا يُرى لأحدهما.
        // و«الجيل» يُبطل المخبأ فور رفع وثيقةٍ مؤرَّخة — لا انتظارَ عشر دقائق.
        $gen    = (int) Cache::get('hub:expiry:gen', 0);
        // **الختم يسبق المهلة**: المفتاح يحمل ختم الجداول المؤرَّخة التي يقرؤها +
        // ختم roles — فتجديدُ عقدٍ أو سحبُ صلاحية عرضٍ يُبطله فوراً لا بعد 10 دقائق.
        $tables = array_values(array_unique(array_filter(array_map(
            fn ($x) => (string) (hub_mod($x[0])['table'] ?? ''), hub_expiry_fields()))));
        $tables[] = 'roles';
        $key    = ($scoped ? 'hub:expiry:u:' . $user->id : 'hub:expiry:r:' . ($user->role_id ?? '0'))
                . ':g' . $gen . hub_data_stamp($tables);
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, $scoped ? 300 : 600, function () use ($scoped, $user) {
            $today = now()->toDateString();
            $limit = now()->addDays(30)->toDateString();
            // عتبة التنبيه لكل سجل: حقول «تنبيه قبل (يوم)» كانت تُعرض ولا تُقرأ —
            // وحدةٌ لها عمود عتبة تُجلب بنافذة موسّعة ثم يُرشَّح كل سجل بعتبته هو
            $alertCols = ['domains' => 'alert', 'files' => 'alert', 'subs' => 'alerts'];
            $wide = now()->addDays(120)->toDateString();
            $items = [];
            foreach (hub_expiry_fields() as [$mk, $f]) {
                // **صلاحية الوحدة قبل نطاقها**: الرادار كان يُرشَّح بالنطاق وحده،
                // فيسرد أسماء سجلاتٍ من وحداتٍ لا يملك المستخدم رؤيتها أصلاً —
                // اسمُ العقد وتاريخُه إفشاءٌ ولو لم تُفتح الوحدة.
                // وبلا مستخدم (طرفيةٌ أو مهمةٌ مجدولة) لا ترشيح: السياق نظاميّ،
                // والمستقبِلون يُرشَّحون في موضعهم لا هنا.
                if ($user && ! hub_can($user, $mk, 'v')) continue;
                // **وقناعُ الحقل بعد صلاحية الوحدة** (v2.337): الرادارُ كان
                // يُرشّح بالوحدة وحدها، فمن حُجب عنه «انتهاء الإقامة» في ملفّات
                // الموظفين يقرؤه هنا باسم صاحبه وتاريخِه — في «ينتهي قريباً»
                // وفي مركز التنبيهات. والقناعُ إن سرى في بابٍ وسقط في آخر
                // فليس قناعاً بل ظنُّ ساتر.
                if ($user && hub_field_mode($user, $mk, (string) ($f['key'] ?? '')) === 'hide') continue;
                $md = hub_mod($mk);
                $disp = hub_display_col($mk);
                $acol = $alertCols[$mk] ?? null;
                try {
                    if ($acol && ! \Illuminate\Support\Facades\Schema::hasColumn($md['table'], $acol)) $acol = null;
                    $q = \Illuminate\Support\Facades\DB::table($md['table'])
                        ->whereNull('deleted_at')
                        ->whereNotNull($f['col'])
                        ->whereBetween(\Illuminate\Support\Facades\DB::raw("DATE(`{$f['col']}`)"), [now()->subDays(60)->toDateString(), $acol ? $wide : $limit]);
                    if ($scoped) $q = hub_scope($q, $mk, $user);

                    // لا تنبيه على ما أُغلق: مهمة منجزة أو فاتورة مدفوعة أو عقد منتهٍ
                    // كانت تظل تنبّه إلى الأبد فتفقد الصفحة مصداقيتها.
                    // `expiryIgnoresStatus`: وحدةٌ «حالتُها» وصفٌ لا مرحلةٌ في
                    // مسار (حالةُ شهادة الدومين مثلاً) — الإقصاءُ بها يُسقط من
                    // الرادار أشدَّ السجلات حاجةً إليه
                    if (empty($md['expiryIgnoresStatus'])
                        && ($sc = hub_status_col($mk)) && \Illuminate\Support\Facades\Schema::hasColumn($md['table'], $sc)) {
                        $q->where(fn ($w) => $w->whereNull($sc)->orWhereNotIn($sc, hub_closed_states()));
                    }

                    // مستند مالي سُدّد بالكامل لا يستحق تنبيه استحقاق ولو لم تُحدَّث حالته:
                    // السداد المسجَّل هو الحقيقة، لا التسمية.
                    if ($mk === 'fin' && \Illuminate\Support\Facades\Schema::hasColumn($md['table'], 'paid')) {
                        $q->whereRaw('COALESCE(paid,0) < COALESCE(total,0)');
                    }

                    // الترتيب تصاعدياً بالتاريخ قبل الحد: نُبقي الأربعين الأقرب/الأكثر تأخراً
                    // لا أربعين عشوائية بترتيب القاعدة (كان يُسقط أعجل السجلات صمتاً).
                    $cols = ['id', $disp . ' as _n', $f['col'] . ' as _d'];
                    if ($acol) $cols[] = $acol . ' as _a';
                    $rows = $q->orderBy(\Illuminate\Support\Facades\DB::raw("DATE(`{$f['col']}`)"))
                        ->limit(40)->get($cols);
                } catch (\Throwable $e) { continue; }
                foreach ($rows as $row) {
                    $d = substr((string) $row->_d, 0, 10);
                    $days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($d)->startOfDay(), false);
                    if ($acol) {
                        // عتبة السجل نفسه: رقم مفرد أو قائمة «90,60,30» تؤخذ أقصاها — والفارغ = 30
                        $nums = array_filter(array_map('intval',
                            preg_split('/[\s,،]+/u', (string) ($row->_a ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
                        if ($days > ($nums ? max($nums) : 30)) continue;
                    }
                    // `fkey` مميّزٌ ثابتٌ للحقل: سجلٌّ بحقلَي تاريخٍ (نهايةٌ وتجديد)
                    // يُنتج إشارتين تتقاسمان module+id — فبلا هذا المميّز تنهار حالتُهما
                    // على مفتاحٍ واحدٍ في مركز الفعل (تأجيلُ إحداهما يُخفي الأخرى).
                    $items[] = ['module' => $mk, 'mlabel' => $md['label'], 'flabel' => $f['label'],
                                'fkey' => (string) ($f['key'] ?? $f['col'] ?? ''),
                                'id' => $row->id, 'name' => (string) $row->_n, 'date' => $d, 'days' => $days];
                }
            }
            // وثائق ملفات الكيانات: شهادةٌ منتهية أخطر من حقلٍ منتهٍ — بها
            // يتوقف تعاملٌ أو يسقط ترخيص. تُضَمّ للرادار نفسه بنطاقها.
            foreach (hub_doc_expiry($user) as $doc) $items[] = $doc;

            usort($items, fn ($a, $b) => $a['days'] <=> $b['days']);
            return array_slice($items, 0, 200);
        });
    }
}

if (! function_exists('hub_sla_rules')) {
    /**
     * قواعد SLA من الإعداد sla.rules بصيغة «أولوية:ساعات_استجابة:ساعات_حل» مفصولة بمسافات —
     * مثال: عاجلة:1:8 عالية:4:24 متوسطة:8:72 افتراضي:8:72
     */
    function hub_sla_rules(): array
    {
        $raw = (string) setting('sla.rules', 'عاجلة:1:8 عالية:4:24 متوسطة:8:72 منخفضة:24:120 افتراضي:8:72');
        $out = [];
        foreach (preg_split('/[\s,،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $bits = explode(':', $part);
            if (count($bits) === 3 && is_numeric($bits[1]) && is_numeric($bits[2])) {
                $out[trim($bits[0])] = [(float) $bits[1], (float) $bits[2]];
            }
        }

        return $out ?: ['افتراضي' => [8, 72]];
    }
}

if (! function_exists('hub_sla')) {
    /**
     * حالة SLA لتذكرة: مواعيد الاستجابة والحل مقابل أول رد (تعليق) ووقت الإغلاق.
     * $firstReply يُمرر مسبقاً في القوائم لتفادي استعلام لكل تذكرة.
     */
    function hub_sla(object $t, $firstReply = 'auto'): array
    {
        $rules = hub_sla_rules();
        $prio = trim((string) ($t->priority ?? ''));
        $policy = 'افتراضي';
        foreach ($rules as $name => $r) {
            if ($name !== 'افتراضي' && $prio !== '' && mb_stripos($prio, $name) !== false) { $policy = $name; break; }
        }
        [$respH, $resH] = $rules[$policy] ?? $rules['افتراضي'] ?? [8, 72];

        $created = \Illuminate\Support\Carbon::parse($t->created_at);
        $respDue = $created->copy()->addHours($respH);
        $resDue  = $created->copy()->addHours($resH);

        if ($firstReply === 'auto') {
            // أول رد **غير داخلي**: الملاحظة بين موظفَين لا تصل العميل فلا توقف عدّاده
            $firstReply = \App\Models\Comment::where('module', 'tickets')->where('record_id', $t->id)
                ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
                ->orderBy('created_at')->value('created_at');
        }
        $respAt = $firstReply ? \Illuminate\Support\Carbon::parse($firstReply) : null;

        $meta = is_array($t->meta ?? null) ? $t->meta : (json_decode((string) ($t->meta ?? ''), true) ?: []);
        $closed = in_array((string) ($t->status ?? ''), ['تم الحل', 'مغلقة'], true);
        $resAt = isset($meta['resolved_at']) ? \Illuminate\Support\Carbon::parse($meta['resolved_at'])
               : ($closed ? \Illuminate\Support\Carbon::parse($t->updated_at) : null);

        return [
            'policy'  => $policy . ' (' . rtrim(rtrim(number_format($respH, 1), '0'), '.') . 'س / ' . rtrim(rtrim(number_format($resH, 1), '0'), '.') . 'س)',
            'respDue' => $respDue, 'respAt' => $respAt,
            'respLate' => $respAt ? $respAt->gt($respDue) : now()->gt($respDue),
            'respPending' => ! $respAt,
            'resDue' => $resDue, 'resAt' => $resAt,
            'resLate' => $resAt ? $resAt->gt($resDue) : now()->gt($resDue),
            'resPending' => ! $resAt,
        ];
    }
}

if (! function_exists('hub_custom_fields')) {
    /**
     * الحقول المخصصة لوحدة — يعرّفها المالك من «باني الحقول» وتُخزن في الإعداد custom.fields
     * وقيمها في عمود custom الموجود بكل جدول. كل حقل: key(cf_*) label type [options] [ref] [required]
     */
    function hub_custom_fields(?string $module): array
    {
        if (! $module) return [];
        $all = setting('custom.fields');
        $all = is_array($all) ? $all : (json_decode((string) $all, true) ?: []);

        return array_values(array_filter((array) ($all[$module] ?? []), fn ($f) => ! empty($f['key'])));
    }
}

if (! function_exists('hub_shade')) {
    /** مزج لون hex مع الأبيض (نسبة موجبة) أو الأسود (سالبة) — لاشتقاق درجات الهوية */
    function hub_shade(string $hex, float $pct): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#' . $hex;
        $to = $pct >= 0 ? 255 : 0;
        $p = min(1, abs($pct));
        $out = '#';
        foreach (str_split($hex, 2) as $c) {
            $v = (int) round(hexdec($c) + ($to - hexdec($c)) * $p);
            $out .= str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}

if (! function_exists('hub_brand_css')) {
    /** متغيرات CSS من اللون الأساسي المُعد — فارغ إن لم يُخصص لون */
    function hub_brand_css(): string
    {
        $c = (string) setting('app.color', '');
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $c) || strtoupper($c) === '#0E7C66') return '';

        return ':root{--p:' . $c
            . ';--pd:' . hub_shade($c, -0.22)
            . ';--pdd:' . hub_shade($c, -0.42)
            . ';--ps:' . hub_shade($c, 0.86)
            . ';--pss:' . hub_shade($c, 0.94)
            . ';--ac:' . hub_shade($c, 0.35) . '}';
    }
}

if (! function_exists('hub_health')) {
    /**
     * تقرير صحة الشركة: نتيجة 0-100 لكل قسم بمعادلة شفافة تُذكر في note.
     * مخبأ 30 دقيقة — كل قسم محصّن بـ try/catch حتى لا يُسقط قسمٌ التقريرَ كله.
     */
    function hub_health(bool $fresh = false): array
    {
        if ($fresh) \Illuminate\Support\Facades\Cache::forget('hub:health');

        return \Illuminate\Support\Facades\Cache::remember('hub:health', 1800, function () {
            $db = \Illuminate\Support\Facades\DB::getFacadeRoot();
            $today = now()->toDateString();
            $soon  = now()->addDays(30)->toDateString();
            $out = [];
            $clamp = fn ($v) => (int) round(min(100, max(0, $v)));

            // المالية: خصم بنسبة المستحقات المتأخرة، وخصم إن كان صافي الشهر سالباً
            try {
                $open = $db->table('fin_documents')->whereNull('deleted_at')
                    ->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة']);
                $openN = (clone $open)->count();
                $late  = $openN ? (clone $open)->whereNotNull('due')->where('due', '<', $today)->count() : 0;
                $m0  = now()->startOfMonth()->toDateString();
                $inc = hub_fin_sum(config('hub.fin.income'), $m0);
                $exp = hub_fin_sum(config('hub.fin.expense'), $m0);
                $score = 100 - ($openN ? ($late / $openN) * 60 : 0) - ($inc - $exp < 0 ? 20 : 0);
                $out['المالية'] = ['score' => $clamp($score), 'note' => "{$late}/{$openN} مستحق متأخر · صافي الشهر " . ($inc - $exp >= 0 ? 'موجب' : 'سالب')];
            } catch (\Throwable $e) {}

            // المشاريع: متوسط نسبة الإنجاز للمشاريع غير المغلقة
            try {
                $projects = $db->table('projects')->whereNull('deleted_at')
                    ->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مكتمل%')->where('status', 'NOT LIKE', '%ملغ%')))
                    ->limit(30)->pluck('id');
                $ps = collect($projects)->map(fn ($id) => hub_progress($id)['pct'])->filter(fn ($p) => $p !== null);
                if ($ps->count()) $out['المشاريع'] = ['score' => $clamp($ps->avg()), 'note' => 'متوسط إنجاز ' . $ps->count() . ' مشروع جارٍ'];
            } catch (\Throwable $e) {}

            // الأمن: خصم للمستخدمين الخاملين >60 يوماً وللأسرار التي لم تُحدَّث >180 يوماً
            try {
                $users = $db->table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف');
                $un = (clone $users)->count();
                $idle = $un ? (clone $users)->where(fn ($w) => $w->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(60)))->count() : 0;
                $sec = $db->table('vault_secrets')->whereNull('deleted_at');
                $sn = (clone $sec)->count();
                $stale = $sn ? (clone $sec)->where('updated_at', '<', now()->subDays(180))->count() : 0;
                $score = 100 - ($un ? ($idle / $un) * 35 : 0) - ($sn ? ($stale / $sn) * 45 : 0);
                $out['الأمن'] = ['score' => $clamp($score), 'note' => "{$idle}/{$un} مستخدم خامل · {$stale}/{$sn} سر لم يُغيَّر منذ ٦ أشهر"];
            } catch (\Throwable $e) {}

            // الموارد البشرية: خصم لوثائق الموظفين المنتهية والقريبة من الانتهاء
            try {
                $emp = $db->table('employees')->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%منتهي%'));
                $en = (clone $emp)->count();
                $expired = $en ? (clone $emp)->where(fn ($w) => $w->where('iqama_exp', '<', $today)->orWhere('pass_exp', '<', $today))->count() : 0;
                $soonN = $en ? (clone $emp)->where(fn ($w) => $w->whereBetween('iqama_exp', [$today, $soon])->orWhereBetween('pass_exp', [$today, $soon]))->count() : 0;
                $score = 100 - ($en ? ($expired / $en) * 55 + ($soonN / $en) * 20 : 0);
                $out['الموارد البشرية'] = ['score' => $clamp($score), 'note' => "{$expired} وثيقة منتهية · {$soonN} تنتهي خلال شهر (من {$en} موظف)"];
            } catch (\Throwable $e) {}

            // الامتثال: العقود والدومينات المنتهية أو القريبة
            try {
                $c = $db->table('contracts')->whereNull('deleted_at'); $cn = (clone $c)->count();
                // العمود date_end لا end — الاسم الخاطئ كان يرمي «unknown column»
                // فيبتلعه catch وتختفي بطاقة الامتثال صامتةً من صحة الشركة
                $cLate = (clone $c)->whereNotNull('date_end')->where('date_end', '<', $today)->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%منته%'))->count();
                $d = $db->table('domains')->whereNull('deleted_at'); $dn = (clone $d)->count();
                $dLate = (clone $d)->whereNotNull('expiry')->where('expiry', '<', $today)->count();
                $tot = $cn + $dn;
                $score = 100 - ($tot ? (($cLate + $dLate) / $tot) * 70 : 0);
                $out['الامتثال'] = ['score' => $clamp($score), 'note' => "{$cLate} عقد و{$dLate} دومين متجاوز للنهاية (من {$tot})"];
            } catch (\Throwable $e) {}

            // البنية التحتية: سيرفرات/شهادات SSL منتهية + أعطال حرجة مفتوحة
            try {
                $s = $db->table('servers')->whereNull('deleted_at'); $sn2 = (clone $s)->count();
                $sLate = (clone $s)->whereNotNull('expiry')->where('expiry', '<', $today)->count();
                $ssl = $db->table('domains')->whereNull('deleted_at')->whereNotNull('ssl_exp')->where('ssl_exp', '<', $today)->count();
                $crit = $db->table('issues')->whereNull('deleted_at')->where('severity', 'LIKE', '%حرج%')
                    ->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مغلق%')->where('status', 'NOT LIKE', '%محلول%')))->count();
                // الحوادث المفتوحة تدخل الدرجة أخيراً — كانت وحدة إدارة الحوادث
                // كاملةً خارج تقييم البنية التحتية، والدرجة تعدّ issues بدلاً منها
                $inc = \Illuminate\Support\Facades\Schema::hasTable('incidents')
                    ? $db->table('incidents')->whereNull('deleted_at')
                        ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])->count() : 0;
                $score = 100 - ($sn2 ? ($sLate / $sn2) * 30 : 0) - min(30, $ssl * 10)
                       - min(40, $crit * 10) - min(30, $inc * 12);
                $out['البنية التحتية'] = ['score' => $clamp($score),
                    'note' => "{$sLate} سيرفر منتهٍ · {$ssl} شهادة SSL منتهية · {$crit} عطل حرج مفتوح · {$inc} حادثة مفتوحة"];
            } catch (\Throwable $e) {}

            return $out;
        });
    }
}

if (! function_exists('hub_needs_approval')) {
    /**
     * هل تتطلب هذه العملية موافقة قبل التنفيذ؟ — من الإعداد approval.rules
     * بصيغة «وحدة:أحرف» مفصولة بمسافات/فواصل، الأحرف: e=تعديل d=حذف (مثال: vault:ed fin:d)
     * المالك وحاملو علم «approve» يمرّون مباشرة.
     */
    function hub_needs_approval($user, string $module, string $op): bool
    {
        if (! $user || $user->role?->is_owner || hub_flag($user, 'approve')) return false;

        foreach (preg_split('/[\s,،]+/u', (string) setting('approval.rules', ''), -1, PREG_SPLIT_NO_EMPTY) as $rule) {
            [$mk, $ops] = array_pad(explode(':', $rule, 2), 2, 'ed');
            if ($mk === $module && str_contains($ops, $op)) return true;
        }

        return false;
    }
}

if (! function_exists('hub_block_if_queued')) {
    /**
     * **أزرارُ المسار لا تلتفّ على طابور الموافقات.**
     *
     * تعديلُ أمر الشراء من نموذج السجل يُصفّ طلب موافقة، وزرُّ «أرسل للمورد» في
     * الشاشة نفسها كان يكتب الحالة مباشرة. والقاعدةُ التي يُلتَفُّ عليها بزرٍّ
     * مجاورٍ ليست قاعدة.
     *
     * تعيد رسالةَ ردٍّ تُوجّه للمسار الموثَّق، أو `null` إن لم تكن الوحدة تحت قاعدة.
     */
    function hub_block_if_queued(string $module, string $op = 'e'): ?string
    {
        return hub_needs_approval(auth()->user(), $module, $op)
            ? 'تعديلاتُك على هذه الوحدة تمر بالموافقات — عدّل السجل من نموذجه ليُصفّ الطلب ويُوثَّق، '
              . 'فأزرارُ المسار لا تلتفّ على الطابور'
            : null;
    }
}

if (! function_exists('hub_approvers')) {
    /**
     * المعتمدون: المالكون + حاملو علم approve — بذاكرة طلبٍ واحد:
     * إجراء notify في مسارٍ يُطلق على ٢٠٠ سجل جماعياً كان يحمّل جدول
     * المستخدمين بأدواره ٢٠٠ مرة في الطلب الواحد.
     */
    function hub_approvers(): array
    {
        // ذاكرة الحاوية لا static: الحاوية تُنسف بين الطلبات (وOctane والاختبارات)
        // بينما static تعمّر عمر العملية فتخدم قائمة معتمدين قديمة
        if (app()->bound('hub.approvers')) return app('hub.approvers');

        $ids = \App\Models\User::whereNull('deleted_at')->with('role')->get()
            ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'approve'))
            ->pluck('id')->values()->all();
        app()->instance('hub.approvers', $ids);

        return $ids;
    }
}

if (! function_exists('hub_approvers_for')) {
    /**
     * المعتمِدون الذين **يرون هذا السجل** — نطاقٌ لكلّ مستلمٍ كنمط
     * `HubAutomation::notifyMonitors`: فلا يُشعَر معتمِدٌ معزولٌ باسمِ/مبلغِ سجلٍّ
     * خارج حدّه (تسريبٌ عبر حدّ العزل من مسار الطلب). المالكُ يمرّ دوماً، وحين لا
     * وحدةَ/معرّف يُرجَع الكلُّ كما هو (لا سجلَّ يُقاس عليه) — فالتوافقُ محفوظ.
     */
    function hub_approvers_for(?string $module = null, ?string $recordId = null): array
    {
        $all = hub_approvers();
        if (! $module || ! $recordId) return $all;
        $md = hub_mod($module);
        if (! $md || empty($md['table'])) return $all;

        return array_values(array_filter($all, function ($uid) use ($md, $module, $recordId) {
            $u = \App\Models\User::find($uid);
            if (! $u) return false;
            if ($u->role?->is_owner) return true;   // المالكُ يرى الكلّ

            return hub_scope(
                \Illuminate\Support\Facades\DB::table($md['table'])->whereNull('deleted_at')->where('id', $recordId),
                $module, $u
            )->exists();
        }));
    }
}

if (! function_exists('hub_progress')) {
    /**
     * محرك نسبة الإنجاز لمشروع: خطة العمل (وزن×تقدم) 50٪ + المهام 30٪ + الاختبارات 20٪
     * — يُعاد توزيع الأوزان عند غياب مكوّن. تُخبأ 10 دقائق وتُنسف عند حفظ مهمة/بند.
     * يعيد: pct + تفصيل كل مكوّن (شفافية النسبة أمام الفريق).
     */
    function hub_progress(string $projectId, bool $fresh = false): array
    {
        $key = 'hub:progress:' . $projectId;
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 600, function () use ($projectId) {
            $db = \Illuminate\Support\Facades\DB::getFacadeRoot();

            // ١) بنود الخطة: وزن × تقدم (الملغى لا يُحسب، المكتمل بلا نسبة = 100)
            $feats = $db->table('plan_items')->whereNull('deleted_at')
                ->where('project_id', $projectId)
                ->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%ملغ%'))
                ->get(['weight', 'progress', 'status', 'test']);
            $fw = 0.0; $fs = 0.0;
            foreach ($feats as $f) {
                $w = max(0.0, (float) ($f->weight ?: 1));
                if ($w == 0.0) $w = 1.0;
                $p = $f->progress !== null ? min(100, max(0, (float) $f->progress))
                    : (\Illuminate\Support\Str::contains((string) $f->status, ['مكتمل', 'منجز']) ? 100.0 : 0.0);
                $fw += $w; $fs += $w * $p;
            }
            $featsPct = $fw > 0 ? $fs / $fw : null;

            // ٢) المهام: المغلقة = 100، المفتوحة بنسبتها (بحد 99) — الملغاة خارج الحساب
            $tasks = $db->table('tasks')->whereNull('deleted_at')
                ->where('project_id', $projectId)
                ->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%ملغ%'))
                ->get(['status', 'progress']);
            $tn = count($tasks); $ts = 0.0; $done = 0;
            foreach ($tasks as $t) {
                $closed = \Illuminate\Support\Str::contains((string) $t->status, ['مكتمل', 'منجز']);
                if ($closed) { $done++; $ts += 100; }
                else $ts += min(99, max(0, (float) ($t->progress ?? 0)));
            }
            $tasksPct = $tn > 0 ? $ts / $tn : null;

            // ٣) الاختبارات: نسبة الناجح من البنود التي دخلت الاختبار أصلاً
            $tested = collect($feats)->filter(fn ($f) => trim((string) $f->test) !== '' && $f->test !== '—');
            $pass = $tested->filter(fn ($f) => \Illuminate\Support\Str::contains((string) $f->test, ['ناجح', 'مقبول', 'اجتاز']))->count();
            $testsPct = $tested->count() ? $pass * 100.0 / $tested->count() : null;

            // المزج بأوزان 50/30/20 مع إعادة التوزيع عند الغياب
            $parts = [[$featsPct, .5], [$tasksPct, .3], [$testsPct, .2]];
            $wsum = 0.0; $acc = 0.0;
            foreach ($parts as [$p, $w]) if ($p !== null) { $wsum += $w; $acc += $p * $w; }

            return [
                'pct'   => $wsum > 0 ? (int) round($acc / $wsum) : null,
                'feats' => ['pct' => $featsPct === null ? null : (int) round($featsPct), 'n' => count($feats)],
                'tasks' => ['pct' => $tasksPct === null ? null : (int) round($tasksPct), 'done' => $done, 'total' => $tn],
                'tests' => ['pct' => $testsPct === null ? null : (int) round($testsPct), 'pass' => $pass, 'n' => $tested->count()],
            ];
        });
    }
}

if (! function_exists('hub_expiry_count')) {
    /** عدد التنبيهات (متأخر + خلال ٧ أيام) لجرس الشريط العلوي — بنطاق المستخدم */
    function hub_expiry_count($user = null): int
    {
        return count(array_filter(hub_expiry(false, $user), fn ($i) => $i['days'] <= 7));
    }
}

if (! function_exists('hub_field_mode')) {
    /**
     * صلاحيات مستوى الحقل: '' كامل · 'ro' قراءة فقط · 'hide' مخفي.
     * تُضبط لكل دور من شاشة الأدوار (field_rules) — المالك يرى كل شيء دائماً.
     */
    function hub_field_mode($user, string $module, string $fieldKey): string
    {
        // الحقول المقفولة سجلّياً (`locked` في تعريف الحقل) قراءةٌ فقط للجميع —
        // حتى المالك: حالةُ الموافقة مثلاً تُكتب من مسار الحسم وحده (حيث يُختم
        // decided_by/decided_at معها)، وإلا زُوِّر اعتمادٌ أو نُقض رفضٌ من نموذج
        // CRUD أو بسحب بطاقة كانبان. البوابة هنا تحرس كل المسارات دفعةً واحدة:
        // fill والتحقق وsetStatus والإجراء الجماعي والنموذج — كلها تستشير هذه الدالة.
        static $locked = null;
        if ($locked === null) {
            $locked = [];
            foreach (hub_modules() as $mk => $d) {
                foreach (($d['fields'] ?? []) as $f) {
                    if (! empty($f['locked'])) $locked[$mk][(string) $f['key']] = true;
                }
            }
        }
        if (isset($locked[$module][$fieldKey])) return 'ro';

        $user = $user ?? auth()->user();
        if (! $user || $user->role?->is_owner) return '';

        // بلا تخبئة ساكنة عمداً: الدور محمّل أصلاً والقيمة مُكاستة، والتخبئة كانت
        // تُبقي قيوداً قديمة سارية داخل العملية الواحدة (عمّال الطوابير وOctane).
        $fr = $user->role?->field_rules;
        $rules = is_array($fr) ? $fr : (json_decode((string) $fr, true) ?: []);
        $mode = $rules[$module][$fieldKey] ?? '';

        return in_array($mode, ['ro', 'hide'], true) ? $mode : '';
    }
}

if (! function_exists('hub_visible_fields')) {
    /** حقول الوحدة بعد إخفاء الممنوع عن دور المستخدم */
    function hub_visible_fields($user, string $module, array $def): array
    {
        return array_values(array_filter($def['fields'],
            fn ($f) => hub_field_mode($user, $module, $f['key']) !== 'hide'));
    }
}

if (! function_exists('hub_company_col')) {
    /**
     * عمود الشركة للعزل: من تعريف الوحدة، أو العمود الفعلي company_id في الجدول
     * إن وُجد ولو لم يُعرَّف مرجعاً — كي لا يتسرب ما تربطه القاعدة بالشركة (مثل
     * المهام والتذاكر والقيود التي تحمل company_id دون تعريف مرجع). يماثل
     * hub_project_col تماماً — وإلا انطفأ العزل صامتاً على عشرات الوحدات.
     */
    function hub_company_col(string $module): ?string
    {
        if ($module === 'companies') return 'id';
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (hub_modules() as $mk => $md) {
                foreach ($md['fields'] as $f) {
                    if (($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'companies' && empty($f['multi'])) {
                        $map[$mk] = $f['col'];
                        break;
                    }
                }
            }
        }
        if (array_key_exists($module, $map)) return $map[$module];

        $table = hub_mod($module)['table'] ?? null;
        try {
            $has = $table && \Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id');
        } catch (\Throwable $e) {
            $has = false;
        }

        return $map[$module] = $has ? 'company_id' : null;
    }
}

if (! function_exists('hub_org_analytics_guard')) {
    /**
     * لوحات التحليلات على مستوى المنشأة (تقييم الموردين، تكلفة الخدمات،
     * التوصيات، القدرات…) تجمع عبر كل الشركات ومحرّكاتها مخبّأة بمفاتيح عامة —
     * فالمستخدم المعزول شركاتياً يُمنع منها (403) كي لا تتسرب أرقام شركات أجنبية.
     */
    function hub_org_analytics_guard(): void
    {
        // لوحاتُ المنشأة كلّها (تكلفةٌ، قدراتٌ، صحّةُ مشاريعَ…) غيرُ منطَّقةٍ بعميل —
        // فتُمنَع عن **كلّ حسابٍ معزول**: على شركاتٍ محددة **أو على عملاءَ محددين**.
        // كان عزلُ العميل ثغرةً: حاملُ راية المراقبة المحصورُ بعميلٍ كان يرى أرقامَ
        // المنشأة كلّها. (نظيرُ سدِّ تسريب الخبيئة في `hub_recommendations`.)
        abort_if(hub_company_ids() !== null, 403, 'هذه اللوحة على مستوى المنشأة كلها — غير متاحة لحسابٍ معزول على شركات محددة');
        abort_if(hub_client_ids() !== null, 403, 'هذه اللوحة على مستوى المنشأة كلها — غير متاحة لحسابٍ معزول على عملاء محددين');
    }
}

if (! function_exists('hub_company_scope')) {
    /**
     * محوّل الشركة النشطة: تصفية عرضٍ للتركيز فوق العزل الصارم (الذي يفرضه
     * hub_scope) — واختيارٌ خارج شركات المستخدم المسموحة يُتجاهل دفاعاً إضافياً.
     */
    function hub_company_scope($q, string $module)
    {
        $cid = (string) session('hub.company', '');
        if ($cid === '') return $q;
        $allowed = hub_company_ids();
        if ($allowed !== null && ! in_array($cid, $allowed, true)) return $q;
        $col = hub_company_col($module);

        return $col ? $q->where($col, $cid) : $q;
    }
}

if (! function_exists('hub_risk_score')) {
    /**
     * درجة المخاطرة = احتمال × أثر (١-٤ لكلٍّ، فالمدى ١-١٦) — كان سجل المخاطر
     * بلا احتمال إطلاقاً، و`severity` وحدها لا تصنع درجةً ولا خريطة حرارية،
     * وكل الأخطار متساوية في حساب صحة المشروع (عدٌّ لا وزن).
     */
    function hub_risk_score(?string $likelihood, ?string $severity): ?array
    {
        $L = ['نادر' => 1, 'محتمل' => 2, 'مرجّح' => 3, 'شبه مؤكد' => 4];
        $S = ['منخفضة' => 1, 'متوسطة' => 2, 'عالية' => 3, 'حرجة' => 4, 'حرج' => 4];

        $l = $L[trim((string) $likelihood)] ?? null;
        $s = $S[trim((string) $severity)] ?? null;
        if ($l === null || $s === null) return null;

        $score = $l * $s;
        $band = $score >= 12 ? 'حرجة' : ($score >= 6 ? 'عالية' : ($score >= 3 ? 'متوسطة' : 'منخفضة'));

        return ['l' => $l, 's' => $s, 'score' => $score, 'band' => $band,
                'tone' => $score >= 12 ? 'bad' : ($score >= 6 ? 'wn' : 'ok')];
    }
}

if (! function_exists('hub_okr_progress')) {
    /**
     * تقدّم الهدف محسوباً من نتائجه الرئيسية (متوسط موزون) — كان `progress`
     * رقماً يدوياً منفصلاً عن الواقع. النتيجة المربوطة بمؤشر تُحدَّث قيمتها
     * الحالية من `hub_kpi_value` (المحرك مكتوبٌ وجاهز منذ إصدارات بلا مستهلك
     * في الأهداف)، وهدفٌ بلا نتائج يبقى على رقمه اليدوي — لا نكذب بصفر.
     */
    function hub_okr_progress(string $objectiveId, bool $refresh = false, bool $withPace = false,
                              bool $persist = false): ?array
    {
        /*
         * **قراءةٌ لا تكتب.**
         *
         * القيمةُ الحالية تُقرأ من مصدرها بنطاق **المُشاهِد** (وهذا صحيح: لا
         * يُعرض لأحدٍ رقمٌ من خارج نطاقه)، لكنّها كانت تُثبَّت في
         * `key_results.current_value` و`objectives.progress` — وهما عمودان
         * **مشتركان يقرؤهما الجميع**. فموظفٌ معزولٌ على شركةٍ واحدة يفتح اللوحة
         * فيدهس رقمَ المؤسسة برقمِه الجزئيّ، ويبقى مدهوساً حتى يفتحها غيره.
         *
         * التثبيتُ الآن صريحٌ (`$persist`) **ولا يقع إلا من قارئٍ غير مقيَّد** —
         * سياقُ النظام (الطرفية والمجدول، بلا مستخدم) أو مالكٌ يرى الكلّ.
         */
        $persist = $persist && ! hub_scoped() && hub_company_ids() === null;
        // الملغاة تُستثنى — وبلا حالة تبقى (whereNotIn وحده يُقصي NULL صامتاً)
        $krs = \App\Models\KeyResult::whereNull('deleted_at')
            ->where('objective_id', $objectiveId)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'ملغاة'))
            ->get();
        if ($krs->isEmpty()) return null;

        $sum = 0.0; $weights = 0.0; $rows = [];
        foreach ($krs as $kr) {
            // القيمة الحالية من مصدرها — والمصدر الآلي **يغلب** أي كتابة يدوية،
            // وإلا فما فائدة الأتمتة إن نقضتها آخر لمسةِ يد.
            $auto = hub_kr_source($kr) !== 'manual';
            if ($auto && $refresh) {
                $val = hub_kr_read($kr);
                if ($val !== null && (float) ($kr->current_value ?? PHP_FLOAT_MIN) !== $val) {
                    // القيمةُ المعروضة تتحدّث دائماً؛ والعمودُ المشترك لا يُكتب
                    // إلا بتثبيتٍ مأذون (انظر أعلى الدالة)
                    $kr->current_value = $val;
                    $kr->read_at = now();
                    if ($persist) $kr->saveQuietly();
                } elseif ($val !== null && $persist) {
                    $kr->read_at = now();
                    $kr->saveQuietly();
                }
            }

            $pct = $kr->pct();
            $w = (float) ($kr->weight ?? 0) ?: 1.0;
            if ($pct !== null) { $sum += $pct * $w; $weights += $w; }
            $rows[] = ['id' => $kr->id, 'title' => $kr->title, 'pct' => $pct,
                       'current' => $kr->current_value, 'target' => $kr->target_value,
                       'start' => $kr->start_value, 'weight' => $w,
                       'unit' => $kr->unit, 'status' => $kr->status, 'auto' => $auto,
                       'source' => hub_kr_source($kr), 'readAt' => $kr->read_at];
        }

        $pct = $weights > 0 ? (int) round($sum / $weights) : null;

        $out = [
            'pct' => $pct,
            'krs' => $rows,
            'count' => $krs->count(),
            'measured' => count(array_filter($rows, fn ($r) => $r['pct'] !== null)),
            'autoCount' => count(array_filter($rows, fn ($r) => $r['auto'])),
        ];

        $o = \App\Models\Objective::find($objectiveId);
        if ($o) {
            $out += hub_okr_pace($o, $pct);
            // النسبة تُكتب على الهدف **دائماً** لا عند التحديث فقط: القوائم
            // والتصدير والودجات تقرأ العمود، فيبقى صادقاً بلا أن يلمسه أحد.
            if ($persist && $pct !== null && (int) ($o->progress ?? -1) !== $pct) {
                $o->forceFill(['progress' => $pct, 'computed_at' => now(),
                               'progress_at_risk' => $out['gap'] ?? null])->saveQuietly();
            }
        }

        return $out;
    }
}

if (! function_exists('hub_pipeline')) {
    /**
     * مسار المبيعات رقماً: قيمة كل مرحلة ومرجّحها باحتمال الإغلاق، وتشريح
     * الخسائر بالسبب وبالمنافس — كانت `value` و`prob` تُملآن ولا تُجمعان،
     * ووحدة المنافسين لا تُقرأ من أي شاشة إطلاقاً.
     */
    function hub_pipeline($user = null): array
    {
        $user = $user ?? auth()->user();
        $q = hub_scope(\Illuminate\Support\Facades\DB::table('clients')->whereNull('deleted_at'), 'clients', $user);
        $rows = hub_company_scope($q, 'clients')
            ->get(['id', 'name', 'stage', 'value', 'prob', 'lost_reason', 'competitor_id']);

        $open = ['عميل محتمل', 'تم التواصل', 'عرض سعر', 'تفاوض'];
        $stages = []; $lost = []; $byCompetitor = [];
        $pipeline = 0.0; $weighted = 0.0; $won = 0.0; $lostValue = 0.0;

        foreach ($rows as $r) {
            $v = (float) ($r->value ?? 0);
            $w = $v * ((float) ($r->prob ?? 0) / 100);
            $st = (string) $r->stage;

            $stages[$st] ??= ['stage' => $st, 'count' => 0, 'value' => 0.0, 'weighted' => 0.0];
            $stages[$st]['count']++;
            $stages[$st]['value'] += $v;
            $stages[$st]['weighted'] += $w;

            if (in_array($st, $open, true)) { $pipeline += $v; $weighted += $w; }
            if ($st === 'فوز') $won += $v;
            if ($st === 'خسارة') {
                $lostValue += $v;
                $reason = (string) ($r->lost_reason ?: 'غير مسجَّل');
                $lost[$reason] ??= ['reason' => $reason, 'count' => 0, 'value' => 0.0];
                $lost[$reason]['count']++;
                $lost[$reason]['value'] += $v;
                if ($r->competitor_id) {
                    $byCompetitor[$r->competitor_id] ??= ['id' => $r->competitor_id, 'count' => 0, 'value' => 0.0];
                    $byCompetitor[$r->competitor_id]['count']++;
                    $byCompetitor[$r->competitor_id]['value'] += $v;
                }
            }
        }

        if ($byCompetitor) {
            $names = \Illuminate\Support\Facades\DB::table('competitors')
                ->whereIn('id', array_keys($byCompetitor))->pluck('name', 'id');
            foreach ($byCompetitor as $cid => &$c) $c['name'] = $names[$cid] ?? '؟';
            unset($c);
        }

        usort($lost, fn ($a, $b) => $b['value'] <=> $a['value']);
        usort($byCompetitor, fn ($a, $b) => $b['value'] <=> $a['value']);
        $decided = $won + $lostValue;

        return [
            'stages' => array_values($stages),
            'pipeline' => round($pipeline, 2),
            'weighted' => round($weighted, 2),
            'won' => round($won, 2),
            'lostValue' => round($lostValue, 2),
            'winRate' => $decided > 0 ? (int) round($won / $decided * 100) : null,
            'lostReasons' => $lost,
            'lostToCompetitors' => $byCompetitor,
        ];
    }
}

if (! function_exists('hub_cur_label')) {
    /**
     * صدقُ اللصيقة — مساعدٌ واحدٌ لكل الشاشات.
     *
     * لا محرّكَ صرفٍ في النظام (`app.currency` **تسميةٌ لا تحويل**)، فبطاقةٌ
     * تجمع مبالغَ صفوفٍ مختلفةِ العملات إمّا تُعنون بعملتها الحقيقية حين تتّحد،
     * وإمّا تُوسَم `mixed` كي يُقرأ الرقمُ مؤشّراً لا رقماً دقيقاً. وعنونةُ كلِّ
     * شيءٍ بعملة النظام زوراً هي ما نتجنّبه هنا.
     *
     * **والفارغُ عملةٌ أيضاً**: عمودُ `currency` يُترك فارغاً في مساراتٍ آليّة
     * (نسخُ عرضٍ، مشترياتٌ، أتمتة)، فترشيحُه من مجموعة التمييز يجعل «فارغ +
     * دولار» يبدو متجانساً وهو مخلوط. يُنسَب للعملة الافتراضية لا يُسقَط.
     *
     * @return array{cur: string, mixed: bool, set: array<int, string>}
     */
    function hub_cur_label($currencies, ?string $default = null): array
    {
        $default = $default ?? (string) setting('app.currency', 'د.ك');
        $set = collect($currencies)->map(fn ($c) => filled($c) ? (string) $c : $default)
            ->unique()->values();

        return ['cur' => $set->count() === 1 ? (string) $set->first() : $default,
                'mixed' => $set->count() > 1, 'set' => $set->all()];
    }
}

if (! function_exists('hub_mrr')) {
    /**
     * الإيراد الشهري المتكرر (MRR) من العقود السارية — أثمن رقمٍ تجاري لم يكن
     * قابلاً للقياس أصلاً: `hub_service_costs` كان يقيس هامشاً نظرياً من السعر
     * المُعلن لا من عقدٍ مُوقَّع. قيمة كل عقد تُطبَّع شهرياً حسب دورة باقته أو
     * خدمته (سنوي ÷ ١٢، ربع سنوي ÷ ٣)، و«مرة واحدة» لا تدخل التكرار.
     * التوزيع بالخدمة يجعل «أي خدمة تحمل إيرادنا؟» سؤالاً له جواب.
     */
    function hub_mrr(bool $fresh = false): array
    {
        // **مفتاحٌ يحمل بصمةَ القارئ لا مفتاحٌ عامّ** (v2.317): `rev:mrr` كان
        // واحداً للجميع، فقارئان مختلفا النطاق يتشاركان الرقمَ نفسَه — وأولُ من
        // يسخّن الخبيئة يفرض رقمَه على من لا يرى نصفَ عقوده. والقراءةُ كانت
        // خاماً بلا `hub_can` ولا `hub_scope` أصلاً.
        $key = hub_scope_key('rev:mrr');
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () {
            $DB = \Illuminate\Support\Facades\DB::class;
            $divisor = ['شهري' => 1, 'ربع سنوي' => 3, 'نصف سنوي' => 6, 'سنوي' => 12];

            // العودةُ المبكرة تحمل **مفاتيح الصدق نفسَها**: العرضُ يقرأ
            // `mixed`/`byCurrency` بلا شرط، فإسقاطُهما هنا فخُّ
            // `Undefined array key` ينتظر أوّلَ قاعدةٍ بلا عقدٍ سارٍ.
            $empty = ['mrr' => 0.0, 'arr' => 0.0, 'contracts' => 0, 'byService' => [],
                      'byCurrency' => [], 'mixed' => false, 'unmapped' => 0, 'oneTime' => 0.0,
                      'oneTimeByCurrency' => [], 'oneTimeMixed' => false];

            $q = hub_read('contracts');
            if (! $q) return $empty;
            $contracts = $q->where('status', 'ساري')->where('type', 'عقد عميل')
                ->get(['id', 'title', 'value', 'currency', 'service_id', 'plan_id', 'client_id', 'date_end']);
            if ($contracts->isEmpty()) return $empty;

            $plans = \Illuminate\Support\Facades\DB::table('pricing_plans')->whereNull('deleted_at')
                ->get(['id', 'cycle', 'service_id'])->keyBy('id');
            $services = \Illuminate\Support\Facades\DB::table('services')->whereNull('deleted_at')
                ->get(['id', 'name', 'cycle'])->keyBy('id');

            $default = (string) setting('app.currency', 'د.ك');
            $mrr = 0.0; $oneTime = 0.0; $unmapped = 0; $byService = []; $byCur = []; $otCur = [];
            foreach ($contracts as $c) {
                $value = (float) ($c->value ?? 0);
                if ($value <= 0) continue;

                $plan = $c->plan_id ? ($plans[$c->plan_id] ?? null) : null;
                $sid = $c->service_id ?: ($plan->service_id ?? null);
                $svc = $sid ? ($services[$sid] ?? null) : null;
                $cycle = (string) ($plan->cycle ?? $svc->cycle ?? 'سنوي');   // بلا ربطٍ: سنويٌّ افتراضاً
                $cur = (string) ($c->currency ?: $default);

                if (! $sid) $unmapped++;
                // **و«مرة واحدة» تُفصل بالعملة كأختها المتكرّرة** (v2.335): كانت
                // تُجمع خاماً قبل أيّ فصل، وعلمُ `mixed` مشتقٌّ من `byCur`
                // المتكرّرة وحدها — فقاعدةٌ متكرّرُها بعملةٍ واحدة تُعلن
                // «لا اختلاط» وتعرض `oneTime` جامعاً ديناراً ودولاراً.
                if ($cycle === 'مرة واحدة') {
                    $oneTime += $value;
                    $otCur[$cur] ??= ['currency' => $cur, 'total' => 0.0, 'contracts' => 0];
                    $otCur[$cur]['total'] += $value;
                    $otCur[$cur]['contracts']++;
                    continue;
                }

                $monthly = $value / ($divisor[$cycle] ?? 12);
                $mrr += $monthly;

                // **الفصل بالعملة**: كان `currency` يُقرأ ولا يُستعمل فتُجمع عقودٌ
                // بعملاتٍ شتّى في رقمٍ واحد تحت لصيقةٍ واحدة — كذبةٌ رقمية. لا
                // محرّكَ أسعار في النظام (app.currency تسميةٌ لا تحويل)، فالصادق
                // الفصلُ ورفعُ علم mixed لا جمعٌ مُخترَع.
                $byCur[$cur] ??= ['currency' => $cur, 'mrr' => 0.0, 'arr' => 0.0, 'contracts' => 0];
                $byCur[$cur]['mrr'] += $monthly;
                $byCur[$cur]['contracts']++;

                $key = $sid ?: '_none';
                $byService[$key] ??= ['id' => $sid, 'name' => $svc->name ?? 'بلا خدمة مربوطة',
                                      'mrr' => 0.0, 'contracts' => 0];
                $byService[$key]['mrr'] += $monthly;
                $byService[$key]['contracts']++;
            }

            usort($byService, fn ($a, $b) => $b['mrr'] <=> $a['mrr']);
            foreach ($byCur as &$bc) { $bc['mrr'] = round($bc['mrr'], 2); $bc['arr'] = round($bc['mrr'] * 12, 2); }
            unset($bc);
            usort($byCur, fn ($a, $b) => $b['mrr'] <=> $a['mrr']);
            foreach ($otCur as &$oc) { $oc['total'] = round($oc['total'], 2); }
            unset($oc);
            usort($otCur, fn ($a, $b) => $b['total'] <=> $a['total']);

            return [
                'mrr' => round($mrr, 2),
                'arr' => round($mrr * 12, 2),
                'contracts' => $contracts->count(),
                'byService' => $byService,
                'byCurrency' => $byCur,
                // الرقم الموحّد أعلاه أمينٌ فقط بعملةٍ واحدة — mixed يخبر الواجهة
                // أن تعرض التفصيل لا رقماً واحداً كاذباً
                'mixed' => count($byCur) > 1,
                'unmapped' => $unmapped,
                'oneTime' => round($oneTime, 2),
                'oneTimeByCurrency' => $otCur,
                'oneTimeMixed' => count($otCur) > 1,
            ];
        });
    }
}

if (! function_exists('hub_stock_sync')) {
    /**
     * اشتقاق حالة الصنف من كميته وحدّه: نفد ⟵ صفر، منخفض ⟵ بلغ حد إعادة
     * الطلب، متاح فيما سوى ذلك. الحالات اليدوية (تالف/محجوز) لا تُدهس.
     * كانت مسارات «مخزون نفد/منخفض» الجاهزة معطلةً لأن لا شيء يضبط الحالة.
     */
    function hub_stock_sync(\App\Models\StockItem $item): void
    {
        $auto = ['متاح', 'منخفض', 'نفد'];
        $cur = (string) $item->status;
        if ($cur !== '' && ! in_array($cur, $auto, true)) return;

        $qty = (float) ($item->qty ?? 0);
        $reorder = (float) ($item->reorder ?? 0);
        $new = $qty <= 0 ? 'نفد' : (($reorder > 0 && $qty <= $reorder) ? 'منخفض' : 'متاح');
        if ($new === $cur) return;

        $item->status = $new;
        $item->saveQuietly();
        \App\Support\FlowRunner::fire('status', 'stock', $item, $new);
    }
}

if (! function_exists('hub_budget_actual')) {
    /**
     * المصروف الفعلي مقابل ميزانية: يجمع مستندات المصروف غير الملغاة المطابقة
     * لأبعاد الميزانية (شركة، مشروع، مركز تكلفة، بند، فترة) — الأبعاد الفارغة
     * لا تُقيّد، وبند «الكل» يشمل كل البنود. كانت الوحدة سجلَّ نوايا: كل
     * الأبعاد جاهزة ومطابقة لبنود المالية حرفياً ولا استعلامَ واحد يقارنها.
     */
    function hub_budget_actual($b): array
    {
        $q = hub_fin_not_dead(\Illuminate\Support\Facades\DB::table('fin_documents')->whereNull('deleted_at')
            ->whereIn('kind', config('hub.fin.expense')));
        foreach (['company_id', 'project_id', 'cc_id'] as $col) {
            if (! empty($b->{$col})) $q->where($col, $b->{$col});
        }
        if (! empty($b->cat) && $b->cat !== 'الكل') $q->where('cat', $b->cat);
        if (! empty($b->date_from)) $q->whereDate('date', '>=', substr((string) $b->date_from, 0, 10));
        if (! empty($b->date_to)) $q->whereDate('date', '<=', substr((string) $b->date_to, 0, 10));

        $spent  = (float) $q->sum('total');
        $amount = (float) ($b->amount ?? 0);

        return [
            'spent'  => $spent,
            'amount' => $amount,
            'remain' => $amount - $spent,
            'pct'    => $amount > 0 ? (int) round($spent / $amount * 100) : null,
        ];
    }
}

if (! function_exists('hub_hourly_rates')) {
    /**
     * أجر الساعة لكل مستخدم — مشتقاً من راتب ملفه الوظيفي وبدلاته.
     * الافتراض معلن وقابل للضبط: أيام العمل بالشهر وساعات اليوم من الإعدادات.
     * من لا ملف له (أو بلا راتب) يُحسب بمتوسط الفريق حتى لا تُبخس التكلفة صفراً.
     */
    function hub_hourly_rates(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('cost:rates', 600, function () {
            $days  = max(1, (int) setting('cost.work_days', 22));
            $hours = max(1, (int) setting('cost.work_hours', 8));

            $rows = \Illuminate\Support\Facades\DB::table('employees')
                ->whereNull('deleted_at')->whereNotNull('user_id')
                ->get(['user_id', 'salary', 'allow']);

            $rates = [];
            foreach ($rows as $r) {
                $monthly = (float) ($r->salary ?? 0) + (float) ($r->allow ?? 0);
                if ($monthly > 0) $rates[$r->user_id] = round($monthly / ($days * $hours), 3);
            }
            $avg = $rates ? round(array_sum($rates) / count($rates), 3) : 0.0;

            return ['rates' => $rates, 'avg' => $avg, 'days' => $days, 'hours' => $hours];
        });
    }
}

if (! function_exists('hub_project_pl')) {
    /**
     * التكلفة الفعلية وربحية مشروع واحد — بأربع دلاء تكلفة صريحة:
     *   ساعات الفريق · السيرفرات · الأدوات والاشتراكات · الخدمات الخارجية
     * مقابل الإيراد المفوتر والمحصّل، ثم الربح والهامش وتكلفة التأخير.
     *
     * كل رقم قابل للتفسير: الدوال تعيد مصادرها (ساعات، عدد مستندات، أشهر التشغيل)
     * حتى لا يواجه المالك رقماً لا يعرف من أين جاء.
     */
    function hub_project_pl(string $projectId, bool $fresh = false): array
    {
        $key = "pl:$projectId";
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($projectId) {
            $DB = \Illuminate\Support\Facades\DB::class;
            $p = \Illuminate\Support\Facades\DB::table('projects')->where('id', $projectId)->first();
            if (! $p) return [];

            // ── مدة التشغيل بالأشهر (لتطبيع التكاليف الدورية) ──
            $start = $p->start_date ? \Illuminate\Support\Carbon::parse($p->start_date) : null;
            $end   = $p->launch_act ? \Illuminate\Support\Carbon::parse($p->launch_act) : now();
            $days  = $start ? max(1, $start->diffInDays($end)) : 30;
            $months = max(1, round($days / 30, 2));

            // ── ١) ساعات الفريق ──
            $rt = hub_hourly_rates();
            $byUser = \Illuminate\Support\Facades\DB::table('tasks')
                ->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNotNull('act_h')->where('act_h', '>', 0)
                ->selectRaw('assignee_id, SUM(act_h) h')->groupBy('assignee_id')->get();

            $hoursCost = 0.0; $hoursTotal = 0.0;
            foreach ($byUser as $r) {
                $h = (float) $r->h;
                $hoursTotal += $h;
                $hoursCost += $h * ($rt['rates'][$r->assignee_id] ?? $rt['avg']);
            }

            // ── ٢) السيرفرات (تكلفة الدورة مطبّعة شهرياً × أشهر التشغيل) ──
            $norm = fn ($amount, $cycle) => match ((string) $cycle) {
                'سنوي' => (float) $amount / 12,
                'ربع سنوي' => (float) $amount / 3,
                'نصف سنوي' => (float) $amount / 6,
                'مرة واحدة' => 0.0,          // تُحتسب كاملةً خارج الدورية أدناه
                default => (float) $amount,   // شهري
            };
            $oneOff = fn ($amount, $cycle) => (string) $cycle === 'مرة واحدة' ? (float) $amount : 0.0;

            $servers = \Illuminate\Support\Facades\DB::table('servers')
                ->whereNull('deleted_at')->where('project_id', $projectId)->get(['cost', 'cycle']);
            $serverCost = 0.0;
            foreach ($servers as $s) $serverCost += $norm($s->cost, $s->cycle) * $months + $oneOff($s->cost, $s->cycle);

            // ── ٣) الأدوات والاشتراكات ──
            // الملغى/المنتهي لا يُحمَّل على كامل عمر المشروع — كما hub_service_costs
            $subs = \Illuminate\Support\Facades\DB::table('subscriptions')
                ->whereNull('deleted_at')->where('project_id', $projectId)
                ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['ملغي', 'منتهي']))
                ->get(['amount', 'cycle']);
            $toolCost = 0.0;
            foreach ($subs as $s) $toolCost += $norm($s->amount, $s->cycle) * $months + $oneOff($s->amount, $s->cycle);

            // ── ٤) الخدمات الخارجية: مشتريات + مصروفات مالية مرتبطة بالمشروع ──
            // المصروف بتعريفه المعتمد config('hub.fin.expense') لا نوع «مصروف» وحده،
            // والحالات الميتة (ملغاة/مسودة) خارج الحساب — كسائر التقارير
            $finDoc = fn () => hub_fin_not_dead(\Illuminate\Support\Facades\DB::table('fin_documents')
                ->whereNull('deleted_at')->where('project_id', $projectId));
            // العدّ المزدوج: زرُّ «فاتورة المورد» يولّد من أمر الشراء مستندَ مالية
            // نوعُه «فاتورة مشتريات» (أحدُ أنواع المصروف)، فيُضاف `meta.bill_id` للأمر.
            // فبلا استثنائه يُحتسب المبلغُ مرّتين: من صفّ الشراء ومن فاتورته. والحالاتُ
            // الميتة (مسودة/ملغى/مرتجع) ليست تكلفةً كسائر التقارير. `whereNull('meta->bill_id')`
            // تعمل على المحرّكين (json_extract يُعيد NULL حين لا مفتاح).
            $purch = (float) \Illuminate\Support\Facades\DB::table('purchases')
                ->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNull('meta->bill_id')
                ->whereNotIn('status', (array) config('hub.purchases.dead', ['مسودة', 'ملغى', 'مرتجع']))
                ->sum('amount');
            $expense = (float) $finDoc()
                ->whereIn('kind', (array) config('hub.fin.expense', ['مصروف']))->sum('total');
            $externalCost = $purch + $expense;

            // ── الإيراد: مفوتر ومحصّل ──
            // كان الشرط kind='فاتورة' — نوعٌ لا يكتبه النظام أصلاً (الحقيقي «فاتورة
            // مبيعات» من QuoteController وخيارات الوحدة) فإيراد كل مشروع حقيقي = صفر.
            // COALESCE(paid,0) داخل الجمع: فاتورة لم يُدفع منها شيء paid=NULL
            // كانت تُفسد المجموع لا تُصفَّر.
            $incKinds = (array) config('hub.fin.income', ['فاتورة مبيعات', 'دفعة واردة']);
            $inv = $finDoc()->whereIn('kind', $incKinds)
                ->selectRaw('COALESCE(SUM(total),0) t, COALESCE(SUM(COALESCE(paid,0)),0) p, COUNT(*) n')->first();

            // **صدقُ العملة داخل المشروع الواحد**: `fin_documents.currency` حقلٌ
            // مكشوفٌ بستّة خيارات، فمشروعٌ واحدٌ قد تحمل فواتيرُه عملتين — والربحُ
            // والهامشُ يُبنيان على هذا الإيراد. لا محرّكَ تحويلٍ في النظام، فيُفصَّل
            // بالعملة ويُرفع علمُ الاختلاط بدل رقمٍ واحدٍ يبدو دقيقاً.
            $incByCur = $finDoc()->whereIn('kind', $incKinds)
                ->selectRaw('currency, COALESCE(SUM(total),0) t, COUNT(*) n')
                ->groupBy('currency')->get();
            $plLabel = hub_cur_label($incByCur->pluck('currency'), (string) ($p->currency ?: setting('app.currency', 'د.ك')));
            $byCurrency = $incByCur
                ->map(fn ($r) => ['currency' => filled($r->currency) ? (string) $r->currency : $plLabel['cur'],
                                  'revenue' => round((float) $r->t, 2), 'docs' => (int) $r->n])
                ->groupBy('currency')
                ->map(fn ($g, $c) => ['currency' => $c, 'revenue' => round($g->sum('revenue'), 2),
                                      'docs' => (int) $g->sum('docs')])
                ->sortByDesc('revenue')->values()->all();
            // «دفعة واردة» محصَّلة بطبيعتها: total هو المبلغ الواصل وإن لم يُملأ paid
            $payExtra = (float) $finDoc()->where('kind', 'دفعة واردة')->whereNull('paid')->sum('total');

            $revenue   = (float) ($inv->t ?? 0);
            $collected = (float) ($inv->p ?? 0) + $payExtra;
            $totalCost = $hoursCost + $serverCost + $toolCost + $externalCost;
            $profit    = $revenue - $totalCost;

            // ── تكلفة التأخير: أيام التأخر × متوسط الحرق اليومي ──
            $delayDays = 0;
            if ($p->launch_exp) {
                $exp = \Illuminate\Support\Carbon::parse($p->launch_exp);
                $act = $p->launch_act ? \Illuminate\Support\Carbon::parse($p->launch_act) : now();
                if ($act->gt($exp)) $delayDays = (int) $exp->diffInDays($act);
            }
            $burn = $totalCost / max(1, $days);

            return [
                'project'  => $p->name,
                // اللصيقةُ الحقيقية حين تتّحد عملاتُ الفواتير، والافتراضيةُ عند
                // الاختلاط مع رفع `mixed` — لا عنونةُ كلِّ شيءٍ بعملة النظام زوراً
                'currency' => $plLabel['cur'],
                'mixed'    => $plLabel['mixed'],
                'byCurrency' => $byCurrency,
                'months'   => $months, 'days' => $days,
                'revenue'  => ['invoiced' => round($revenue, 2), 'collected' => round($collected, 2),
                               'docs' => (int) ($inv->n ?? 0),
                               'uncollected' => round($revenue - $collected, 2)],
                'cost'     => ['hours' => round($hoursCost, 2), 'servers' => round($serverCost, 2),
                               'tools' => round($toolCost, 2), 'external' => round($externalCost, 2),
                               'total' => round($totalCost, 2)],
                'hours'    => ['logged' => round($hoursTotal, 1), 'people' => $byUser->count(),
                               'avg_rate' => $rt['avg']],
                'profit'   => round($profit, 2),
                'margin'   => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'burn_day' => round($burn, 2),
                'delay'    => ['days' => $delayDays, 'cost' => round($delayDays * $burn, 2)],
                'budget'   => $p->budget !== null ? (float) $p->budget : null,
                'over'     => $p->budget ? round($totalCost - (float) $p->budget, 2) : null,
            ];
        });
    }
}

if (! function_exists('hub_project_health')) {
    /**
     * صحة المشروع: ستة عوامل من بيانات حقيقية، كل عامل ٠–١٠٠ بوزن معلن.
     * لا نخترع «رضا العميل» ولا «استقرار الفريق» لأن لا مصدر لهما في النظام —
     * ذكر عامل بلا بيانات يعطي رقماً كاذباً، والصراحة أنفع من لوحة جميلة.
     */
    function hub_project_health(string $projectId, bool $fresh = false): array
    {
        $key = "health:$projectId";
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($projectId) {
            $DB = \Illuminate\Support\Facades\DB::class;
            $p = \Illuminate\Support\Facades\DB::table('projects')->where('id', $projectId)->first();
            if (! $p) return [];

            $pl = hub_project_pl($projectId);
            $f = [];

            // ١) الالتزام بالموعد
            $delay = $pl['delay']['days'] ?? 0;
            $f[] = ['k' => 'الالتزام بالموعد', 'w' => 25,
                    's' => $delay <= 0 ? 100 : max(0, 100 - $delay * 2),
                    'note' => $delay > 0 ? "متأخر {$delay} يوماً" : 'ضمن الموعد'];

            // ٢) الالتزام بالميزانية
            $bs = 100; $bn = 'لا ميزانية معتمدة';
            if (! empty($pl['budget']) && $pl['budget'] > 0) {
                $used = $pl['cost']['total'] / $pl['budget'] * 100;
                $bs = $used <= 100 ? 100 : max(0, (int) (100 - ($used - 100) * 2));
                $bn = round($used) . '٪ من الميزانية مستهلك';
            }
            $f[] = ['k' => 'الالتزام بالميزانية', 'w' => 20, 's' => $bs, 'note' => $bn];

            // ٣) المهام المتأخرة
            $tAll = \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')->where('project_id', $projectId)->count();
            $tLate = \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNotNull('due')->whereDate('due', '<', today())
                ->whereNotIn('status', ['منجزة', 'مكتملة', 'ملغاة'])->count();
            $f[] = ['k' => 'انضباط المهام', 'w' => 20,
                    's' => $tAll ? max(0, (int) (100 - $tLate / $tAll * 200)) : 100,
                    'note' => $tAll ? "{$tLate} متأخرة من {$tAll}" : 'لا مهام مسجَّلة'];

            // ٤) المخاطر المفتوحة — موزونةً بالخطورة: كان العدّ يساوي بين خطرٍ
            // حرج وآخر منخفض، فمشروعٌ بخمسة أخطار تافهة يبدو أسوأ من واحدٍ قاتل
            $risks = \Illuminate\Support\Facades\DB::table('issues')->whereNull('deleted_at')
                ->where('project_id', $projectId)
                ->whereNotIn('status', ['مغلقة', 'محلولة', 'ملغاة'])->pluck('severity');
            $weights = ['حرجة' => 30, 'حرج' => 30, 'عالية' => 15, 'متوسطة' => 8, 'منخفضة' => 3];
            $penalty = 0;
            foreach ($risks as $sev) $penalty += $weights[trim((string) $sev)] ?? 10;
            $iss = $risks->count();
            $crit = $risks->filter(fn ($s) => in_array(trim((string) $s), ['حرجة', 'حرج'], true))->count();
            $f[] = ['k' => 'المخاطر المفتوحة', 'w' => 15,
                    's' => max(0, 100 - $penalty),
                    'note' => $iss ? "{$iss} مخاطرة مفتوحة" . ($crit ? " (منها {$crit} حرجة)" : '') : 'لا مخاطر مفتوحة'];

            // ٥) الأعطال التقنية (٩٠ يوماً)
            $inc = \Illuminate\Support\Facades\Schema::hasTable('incidents')
                ? \Illuminate\Support\Facades\DB::table('incidents')->whereNull('deleted_at')->where('project_id', $projectId)
                    ->where('created_at', '>=', now()->subDays(90))->count() : 0;
            $f[] = ['k' => 'استقرار التشغيل', 'w' => 10,
                    's' => max(0, 100 - $inc * 20), 'note' => $inc ? "{$inc} حادث خلال ٩٠ يوماً" : 'بلا أعطال مسجَّلة'];

            // ٦) عبء الدعم المفتوح
            $tk = \Illuminate\Support\Facades\DB::table('tickets')->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNotIn('status', ['تم الحل', 'مغلقة'])->count();
            $f[] = ['k' => 'عبء الدعم', 'w' => 10,
                    's' => max(0, 100 - $tk * 10), 'note' => $tk ? "{$tk} تذكرة مفتوحة" : 'لا تذاكر مفتوحة'];

            $score = (int) round(array_sum(array_map(fn ($x) => $x['s'] * $x['w'], $f)) / 100);

            return ['score' => $score, 'factors' => $f,
                    'tone' => $score >= 80 ? 'ok' : ($score >= 55 ? 'wn' : 'bad'),
                    'label' => $score >= 80 ? 'سليم' : ($score >= 55 ? 'يحتاج انتباهاً' : 'متعثر')];
        });
    }
}

if (! function_exists('hub_ar_norm')) {
    /**
     * تطبيع عربي للمقارنة: يجرّد التشكيل والتطويل ويوحّد صور الألف والياء والتاء المربوطة.
     *
     * لا يُستخدم للعرض — للمقارنة فقط. سببه أن السجل يُصرّح بالكلمة نفسها بصور مختلفة
     * (`مُنجزة` مقابل `منجزة`، `منفّذ` مقابل `منفذ`)، فكانت المقارنة الحرفية تعتبر
     * السجل المنتهي مفتوحاً لمجرد شدّة أو ضمّة.
     */
    function hub_ar_norm(?string $s): string
    {
        $s = trim((string) $s);
        if ($s === '') return '';
        // التشكيل والتطويل: َ ً ُ ٌ ِ ٍ ّ ْ ٰ ـ
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return preg_replace('/\s+/u', ' ', $s);
    }
}

if (! function_exists('hub_closed_states')) {
    /**
     * حالات تعني «انتهى الأمر» — تُستثنى من التنبيهات وقوائم ما يحتاج تدخلاً.
     *
     * القائمة الحرفية أدناه هي المرجع، لكنها تُوسَّع تلقائياً بكل حالة **مُصرَّح بها في
     * السجل** تطابق إحداها بعد التطبيع. فـ`مُنجزة` (الأفكار) و`منفّذ` (التغييرات) و`مدفوع`
     * (الرواتب) كانت تُحسب مفتوحة لأنها تكتب الكلمة نفسها بصورة أخرى — لا لأن معناها مختلف.
     */
    function hub_closed_states(): array
    {
        static $out = null;
        if ($out !== null) return $out;

        $base = ['منجزة', 'منجز', 'مكتملة', 'مكتمل', 'مغلقة', 'مغلق', 'ملغاة', 'ملغى', 'ملغي',
                 'محلولة', 'تم الحل', 'مدفوعة', 'مسددة', 'منتهية', 'منتهي', 'مؤرشفة', 'مؤرشف',
                 'منفَّذ', 'منفذ', 'مرفوض', 'مرفوضة', 'مغلق بتقرير', 'مُستعاد', 'متراجع عنه',
                 // صور مذكّرة لكلمات موجودة أصلاً، لا يلتقطها التطبيع لأن الفرق في آخر
                 // الكلمة لا في تشكيلها: «مدفوع» (مسيّرات الرواتب) و«منتهٍ» (الأحداث).
                 'مدفوع', 'منتهٍ',

                 /*
                  * حالاتٌ نهائية بمفردات خاصة بوحدتها. المعيار هنا ليس «هل انتهى الحدث؟»
                  * بل «هل بقي عملٌ على أحد؟» — لأن هذه القائمة تُغذّي قوائم ما يحتاج تدخلاً.
                  */
                 'خسارة',                    // عميل: فُقدت الفرصة، لا متابعة بعدها
                 'تم التعيين',               // توظيف: اكتمل المسار
                 'منتهية خدمته',             // موظف: انتهت العلاقة
                 'مستبعد',                   // أصل: خرج من الخدمة نهائياً
                 'خرج من السوق',             // منافس: لم يعد يُرصد
                 'مُستبدَلة',                // اعتمادية: حلّ محلها غيرها
                 'مرتجع',                    // أمر شراء: أُغلق بالإرجاع
                 'متوقفة عن البيع',          // باقة: انتهى بيعها
                 'مُقرّة',                   // إقرار سياسة: وقّعه صاحبه
                 'منتهية بتحديث النسخة'];    // إقرار سياسة: نسخته تجاوزتها

    /*
     * وحالاتٌ **لم** تُدرج عمداً رغم أن حدثها انتهى:
     *   «فشل» و«فشلت» (النشر والاستعادة) — الفشل أحوجُ ما يكون لتدخل، وإخفاؤه
     *       من قوائم المتابعة أسوأ من إظهاره.
     *   «فوز» (عميل) — نقيض «خسارة»: الفوز يفتح عمل التأهيل لا يُغلقه.
     *   «نفد» و«تالف» (مخزون وأصول) — كلاهما يطلب إعادة طلبٍ أو إصلاحاً.
     */

        $norm = array_map('hub_ar_norm', $base);
        $out  = $base;
        foreach (hub_declared_states() as $s) {
            if (! in_array($s, $out, true) && in_array(hub_ar_norm($s), $norm, true)) $out[] = $s;
        }

        return $out = array_values(array_unique($out));
    }
}

if (! function_exists('hub_declared_states')) {
    /** كل قيم الحالة المُصرَّح بها في سجل الوحدات — مصدر مفردات النظام الفعلية */
    function hub_declared_states(): array
    {
        static $all = null;
        if ($all !== null) return $all;

        $all = [];
        foreach (hub_modules() as $mk => $md) {
            // بالمفتاح لا بالعمود: المطابقة كانت `col === $md['status']` و
            // `status` مفتاحٌ — فخيارات وحدة الوثائق كانت تسقط من مفردات النظام
            $f = hub_status_field($mk);
            if ($f && ! empty($f['options'])) {
                foreach ($f['options'] as $o) $all[] = $o;
            }
        }

        return $all = array_values(array_unique($all));
    }
}

if (! function_exists('hub_is_closed')) {
    /** هل هذه الحالة تعني «انتهى الأمر»؟ — مقارنة مُطبَّعة */
    function hub_is_closed(?string $status, array $alsoClosed = []): bool
    {
        $s = hub_ar_norm($status);
        if ($s === '') return false;
        $closed = array_map('hub_ar_norm', array_merge(hub_closed_states(), $alsoClosed));

        return in_array($s, $closed, true);
    }
}

if (! function_exists('hub_open_scope')) {
    /**
     * تقييد الاستعلام على السجلات **المفتوحة**: حالة معدومة، أو حالة ليست من المنتهية.
     *
     * كان هذا التعريف مكتوباً يدوياً في تسعة متحكمات بمفردات متضاربة (`%مغلق%` هنا،
     * `%مكتمل%` هناك، `%منته%` ثالثاً) فكان السجل الواحد «مفتوحاً» في صفحة و«مغلقاً»
     * في أخرى. صار مصدره واحداً هنا.
     *
     * `$alsoClosed` لما تنفرد به الوحدة: «موقوف» للمشاريع، و«موافق/معتمد» للموافقات —
     * دلالاتٌ محلية صحيحة لا تعمّم على بقية النظام.
     */
    function hub_open_scope($q, string $col = 'status', array $alsoClosed = [])
    {
        $closed = array_values(array_unique(array_merge(hub_closed_states(), $alsoClosed)));

        return $q->where(fn ($w) => $w->whereNull($col)->orWhereNotIn($col, $closed));
    }
}

if (! function_exists('hub_timeline')) {
    /**
     * الخط الزمني الموحَّد لسجل — بلا جدول جديد.
     *
     * التاريخ كان موجوداً كاملاً موزّعاً على أربعة جداول تتشارك `(module, record_id)`
     * ولا أحد يدمجها، فكان على المستخدم أن يفتح أربع بطاقات ليعرف ما جرى. هذا قارئٌ
     * يوحّدها ويُطبّعها لشكل رحلة العميل — فتحصل الوحدات الـ٧١ على خط زمني دفعةً واحدة.
     *
     * لا يُخرج `before/after` من التدقيق: الخط الزمني يقول **من فعل ماذا ومتى**،
     * أما القيم القديمة فلها سجل الإصدارات بصلاحيته.
     */
    function hub_timeline(string $module, string $recordId, int $limit = 60): array
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $ev = [];
        $add = function ($at, string $ico, string $label, string $title,
                         ?string $url = null, ?string $who = null) use (&$ev) {
            if (! $at) return;
            $ev[] = ['at' => (string) $at, 'ico' => $ico, 'label' => $label,
                     'title' => $title, 'url' => $url, 'status' => null, 'who' => $who];
        };

        $users = [];
        $name  = function ($id) use (&$users) {
            if ($id === null) return null;
            if (! array_key_exists($id, $users)) {
                $users[$id] = \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $id)->value('name');
            }

            return $users[$id];
        };

        $icons = ['إضافة' => '🌱', 'تعديل' => '✏️', 'حذف' => '🗑️', 'استعادة' => '♻️',
                  'تصدير' => '📤', 'استيراد' => '📥', 'عرض حساس' => '👁️'];

        // ١) التدقيق — من فعل ماذا
        foreach (\Illuminate\Support\Facades\DB::table('audits')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->limit($limit)
                    ->get(['action', 'reason', 'user_id', 'created_at']) as $a) {
            $add($a->created_at, $icons[$a->action] ?? '📌', (string) $a->action,
                (string) ($a->reason ?: ''), null, $name($a->user_id));
        }

        // ٢) التعليقات
        foreach (\Illuminate\Support\Facades\DB::table('comments')->whereNull('deleted_at')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->limit($limit)
                    ->get(['id', 'body', 'user_id', 'created_at']) as $c) {
            $add($c->created_at, '💬', 'تعليق',
                \Illuminate\Support\Str::limit((string) $c->body, 90),
                '#c-' . $c->id, $name($c->user_id));
        }

        // ٣) المرفقات
        foreach (\Illuminate\Support\Facades\DB::table('attachments')->whereNull('deleted_at')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->limit($limit)
                    ->get(['original_name', 'uploaded_by', 'created_at']) as $t) {
            $add($t->created_at, '📎', 'مرفق',
                \Illuminate\Support\Str::limit((string) $t->original_name, 60),
                null, $name($t->uploaded_by));
        }

        // ٤) الإصدارات المحفوظة
        foreach (\Illuminate\Support\Facades\DB::table('record_versions')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->limit($limit)
                    ->get(['version', 'changed_by', 'created_at']) as $v) {
            $add($v->created_at, '🕐', 'نسخة', 'الإصدار ' . $v->version, null, $name($v->changed_by));
        }

        // ٥) سجل أدلة التوقيع (v2.118) — دورة التوقيع كاملة على صفحة العقد نفسها
        if ($module === 'contracts') {
            try {
                $signIcons = ['created' => '📨', 'sent' => '📤', 'opened' => '👀',
                    'otp_sent' => '🔑', 'otp_ok' => '🔓', 'signed' => '✍️', 'declined' => '🚫',
                    'voided' => '❌', 'reminded' => '⏰', 'downloaded' => '⬇️'];
                $signLabels = ['created' => 'أُنشئ طلب توقيع', 'sent' => 'أُرسل للتوقيع',
                    'opened' => 'فُتحت الوثيقة', 'otp_sent' => 'أُرسل رمز تحقق', 'otp_ok' => 'تحقق ناجح',
                    'signed' => 'وُقّعت الوثيقة', 'declined' => 'رُفض التوقيع', 'voided' => 'أُبطل الرابط',
                    'reminded' => 'تذكير بالتوقيع', 'downloaded' => 'نُزّلت الوثيقة'];
                foreach (\Illuminate\Support\Facades\DB::table('contract_events')
                            ->where('contract_id', $recordId)
                            ->orderByDesc('created_at')->limit($limit)
                            ->get(['event', 'ip', 'actor_id', 'meta', 'created_at']) as $s) {
                    $meta = json_decode((string) $s->meta, true) ?: [];
                    $add($s->created_at, $signIcons[$s->event] ?? '🖊️',
                        $signLabels[$s->event] ?? (string) $s->event,
                        trim(($meta['name'] ?? $meta['reason'] ?? '') . ($s->ip ? ' — ' . $s->ip : '')),
                        null, $name($s->actor_id));
                }
            } catch (\Throwable $e) {
                // كودٌ وصل قبل هجرته — الخط الزمني لا ينفجر
            }
        }

        // الأحدث أولاً — تاريخُ سجلٍ يُقرأ من آخره، بخلاف رحلة العميل
        usort($ev, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($ev, 0, $limit);
    }
}

if (! function_exists('hub_notify')) {
    /**
     * إنشاء إشعار — نفس المصفوفة كانت منسوخة في ستة مواضع، وسقط `Str::limit`
     * من إحداها (حسم الموافقات) فكان النص الطويل يُبتر في القاعدة لا في التطبيق.
     * الكتم مفروض في طبقة الموديل فلا يُعاد هنا.
     */
    function hub_notify($userId, string $kind, string $text,
                        ?string $module = null, ?string $recordId = null)
    {
        if (! $userId) return null;

        return \App\Models\HubNotification::create([
            'user_id'   => $userId,
            'kind'      => $kind,
            'text'      => \Illuminate\Support\Str::limit($text, 590),
            'module'    => $module,
            'record_id' => $recordId,
            'read'      => false,
            'created_at' => now(),
        ]);
    }
}

if (! function_exists('hub_audit')) {
    /**
     * قيد تدقيق يدوي — ثلاثي `device/ip/created_at` كان منسوخاً حرفياً في ستة مواضع.
     * (سمة `Auditable` تتكفّل بقيود CRUD تلقائياً؛ هذه لما لا يمر بالموديل.)
     */
    function hub_audit(string $action, ?string $module = null, ?string $recordId = null,
                       ?string $name = null, array $extra = [])
    {
        $companyId = (string) session('hub.company', '') ?: null;
        $allowed = hub_company_ids();
        if ($companyId !== null && $allowed !== null && ! in_array($companyId, $allowed, true)) {
            $companyId = null;
        }

        return \App\Models\AuditEntry::create($extra + [
            'user_id'   => auth()->id(),
            // الشركة النشطة من الشريط العلوي، إن كانت ضمن المسموح لهذا المستخدم
            'company_id' => $companyId,
            'action'    => $action,
            'module'    => $module,
            'record_id' => $recordId,
            // بعرض العمود نفسِه (كالسمة) لا ٦٠ حرفاً: قائمةُ مفاتيح الإعدادات كانت تُقصّ بعد مفتاحين
            'name'      => $name === null ? null : hub_fit($name, hub_col_max('audits', 'name') ?? 300),
            // hub_fit لا substr: القصُّ بالبايتات يقطع الحرف العربي نصفين
            'device'    => hub_fit((string) request()->userAgent(), 200),
            'ip'        => request()->ip(),
            'request_id' => \App\Support\Api::requestId(),
            'created_at' => now(),
        ]);
    }
}

if (! function_exists('hub_fin_not_dead')) {
    /**
     * استثناء الحالات الميتة (ملغاة/مسودة) مع إبقاء «بلا حالة»:
     * `whereNotIn('state', $dead)` وحدها تُسقط صفوف state=NULL صامتاً
     * (‏NULL NOT IN (...) تُقيَّم NULL) — فمستندٌ أُدخل بلا حالة كان يختفي
     * من كل التقارير واللوحات. حقل الحالة اختياري في الوحدة، فالغياب حياة لا موت.
     */
    function hub_fin_not_dead($q, ?array $dead = null)
    {
        $dead = $dead ?? (array) config('hub.fin.dead', []);

        return $q->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $dead));
    }
}

if (! function_exists('hub_fin_sum')) {
    /** مجموع مستندات مالية من أنواع بعينها منذ تاريخ — يستثني الملغاة والمسودات دائماً */
    function hub_fin_sum(array $kinds, ?string $from = null, string $col = 'total'): float
    {
        $q = hub_fin_not_dead(\Illuminate\Support\Facades\DB::table('fin_documents')->whereNull('deleted_at'))
            ->whereIn('kind', $kinds);
        if ($from) $q->where('date', '>=', $from);

        return (float) $q->sum($col);
    }
}

if (! function_exists('hub_cached')) {
    /**
     * غلاف التخبئة: `if ($fresh) forget; return remember(...)` كان منسوخاً ١٢ مرة،
     * وكل نسخة فرصةٌ لنسيان `forget` فيبقى المحرّك يقرأ رقماً قديماً بعد التحديث.
     */
    function hub_cached(string $key, int $ttl, bool $fresh, \Closure $fn)
    {
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, $ttl, $fn);
    }
}

if (! function_exists('hub_scope_key')) {
    /**
     * بصمة نطاق القارئ لمفاتيح التخبئة.
     *
     * كل قارئٍ جديد يمرّ بـ`hub_scope` و`hub_can` و`hub_field_mode` — فنتيجته
     * **تختلف باختلاف القارئ**. مفتاحٌ عامّ واحد يعني أن أول من يفتح الشاشة
     * يخبّئ نسخته، ويقرؤها بعده من لا يملك رؤيتها. البصمة تحمل الدور والمستخدم
     * والشركة النشطة وعدسة المشروع — وهي أربعة ما يغيّر النتيجة.
     */
    function hub_scope_key(string $prefix): string
    {
        $u = auth()->user();

        // ختمُ `roles` و`users` جزءٌ من المفتاح: المفتاح كان يحمل **معرّف** الدور
        // لا **صلاحياته**، فسحبُ صلاحيةٍ من دورٍ لا يغيّر المفتاح — ويبقى الموظف
        // المسحوبة صلاحيتُه يقرأ الشاشة المخبّأة حتى تنتهي مهلتها. والعزل بالشركة
        // مخزَّنٌ على المستخدم، فتغييره يجب أن يُبطل كذلك.
        // مساحةُ عمل العميل جزءٌ من المفتاح كالشركة سواء — وإلا قُدّمت شاشةُ
        // عميلٍ مخبّأةٌ لمن بدّل إلى عميلٍ آخر
        return $prefix . ':' . ($u?->role_id ?? '0') . ':' . ($u?->id ?? '0')
            . ':' . (string) session('hub.company', '-')
            . ':' . (string) session('hub.client', '-') . hub_lens_key(hub_lens()['id'] ?? null)
            . hub_data_stamp(['roles', 'users']);
    }
}

if (! function_exists('hub_status_col')) {
    /**
     * **عمود** الحالة الفيزيائي لوحدة — لا مفتاحه.
     *
     * `$def['status']` مفتاحُ حقلٍ لا اسمُ عمود، وهما متطابقان في اثنتين
     * وسبعين وحدة و**مختلفان في واحدة**: وحدة الوثائق مفتاحُها `docStatus`
     * وعمودها `doc_status`. فكل قارئٍ يستعمل المفتاح مباشرةً يبني
     * `where "docStatus" = ?` — يُعيده SQLite صفراً صامتاً، ويرفضه **MySQL**
     * بـ`Unknown column` وخطأ ٥٠٠.
     *
     * وأثرُه أوسع من الترشيح: قائمةُ الحالات تُبنى بـ`firstWhere('col', ...)`
     * فتعود فارغة، والحدث لا يُطلَق عند تغيّر الحالة، ووثيقةٌ ملغاة تبقى
     * تُنبّه «تنتهي قريباً» لأن مرشّح المفتوح يُتخطّى.
     *
     * نظيرتُه `hub_display_col()` موجودةٌ منذ زمن — وهذه كانت ناقصة.
     */
    function hub_status_col(string $module): ?string
    {
        static $map = [];
        if (array_key_exists($module, $map)) return $map[$module];

        $def = hub_mod($module);
        $key = $def['status'] ?? null;
        if (! $key) return $map[$module] = null;

        $f = collect($def['fields'] ?? [])->firstWhere('key', $key);

        return $map[$module] = $f['col'] ?? $key;
    }
}

if (! function_exists('hub_status_field')) {
    /** تعريفُ حقل الحالة (بخياراته) — للقوائم المنسدلة */
    function hub_status_field(string $module): ?array
    {
        $def = hub_mod($module);
        $key = $def['status'] ?? null;

        return $key ? (collect($def['fields'] ?? [])->firstWhere('key', $key) ?: null) : null;
    }
}

if (! function_exists('hub_str')) {
    /**
     * نصٌّ من مدخلٍ لا يُوثَق به.
     *
     * `(string) $request->query('k')` يبدو بريئاً حتى يصل `?k[]=x`: تحويل
     * المصفوفة إلى نصّ يرمي `Array to string conversion`، فتسقط الشاشة بخمسمئة
     * برابطٍ يُلصق — بلا صلاحيةٍ ولا مهارة. وكلُّ موضعٍ يقرأ معامِلاً نصّياً
     * يمرّ من هنا.
     */
    function hub_str($v, string $default = ''): string
    {
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v) || is_bool($v)) return (string) $v;

        return $default;      // مصفوفةٌ أو كائنٌ أو null — لا نصّ فيه
    }
}

if (! function_exists('hub_num')) {
    /**
     * عددٌ منتهٍ من مدخلٍ لا يُوثَق به.
     * `1e400` يمرّ من قاعدة `numeric` ويصير `INF`، فيُكتب في السجل ثم يفشل
     * ترميز قيد التدقيق — فيقع خطأٌ **بعد** أن يكون التغيير قد وقع: تغييرٌ
     * بلا أثر، وهو نقضُ «إثبات لا ادّعاء».
     */
    function hub_num($v): ?float
    {
        if ($v === null || $v === '' || is_array($v) || ! is_numeric($v)) return null;

        $f = (float) $v;

        return is_finite($f) ? $f : null;
    }
}

if (! function_exists('hub_col_max')) {
    /**
     * أقصى طولٍ يقبله العمود فعلاً — من القاعدة لا من التخمين.
     *
     * هنا جذرُ عائلةٍ كاملة من الأعطال: `ModuleController::rules()` كان يُخرج
     * `string` بلا `max` لكل حقل نصّي، و**SQLite لا يفرض طول varchar** فتمرّ
     * الحزمة خضراء، ثم يرفضها MySQL في الإنتاج بـSQLSTATE 22001. هكذا وقع عطل
     * `flows.event`، ونفس الشكل كامنٌ في عشرات الأعمدة.
     *
     * القياس بالمحارف لا بالبايتات: MySQL يعدّ `VARCHAR(N)` محارفَ، والعربية
     * متعددةُ البايتات — فلو قِيس بالبايت لضاق الحدّ إلى ثلثه بلا سبب.
     */
    function hub_col_max(string $table, string $col): ?int
    {
        return hub_col_widths()[$table][$col] ?? null;
    }
}

if (! function_exists('hub_col_widths')) {
    /**
     * خريطةُ أطوال الأعمدة النصّية، مقروءةً من **مصدر الهجرات**.
     *
     * ولمَ لا تُقرأ من القاعدة؟ لأن SQLite — محرّك الاختبارات — لا يصرّح بطول
     * `varchar` أصلاً: هو يقبل أي طول ولا يذكره. فلو قِيس من القاعدة لعادت
     * الحزمةُ عمياءَ عن العطل نفسه الذي جاءت تحرسه. والمصدر واحدٌ للمحرّكين.
     *
     * الأخيرُ يفوز: هجرةُ توسعةٍ لاحقة (`change()`) تنسخ الطول القديم.
     */
    function hub_col_widths(): array
    {
        static $map = null;
        if ($map !== null) return $map;

        $map = \Illuminate\Support\Facades\Cache::remember('hub:colwidths:' . config('hub.version'), 86400, function () {
            $out = [];
            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                $src = (string) @file_get_contents($file);
                // كل كتلة Schema::create/table ثم كل string/char فيها بطولها
                preg_match_all("/Schema::(?:create|table)\\(\\s*'([a-z0-9_]+)'(.*?)(?=Schema::(?:create|table)\\(|\\z)/s",
                    $src, $blocks, PREG_SET_ORDER);
                foreach ($blocks as $b) {
                    preg_match_all("/->(?:string|char)\\(\\s*'([a-z0-9_]+)'\\s*,\\s*(\\d+)/", $b[2], $cols, PREG_SET_ORDER);
                    foreach ($cols as $c) $out[$b[1]][$c[1]] = (int) $c[2];
                    // uuid() و char(36) الضمنيان — معرّفاتٌ بطول ٣٦
                    preg_match_all("/->uuid\\(\\s*'([a-z0-9_]+)'/", $b[2], $uu, PREG_SET_ORDER);
                    foreach ($uu as $c) $out[$b[1]][$c[1]] ??= 36;
                }
            }

            return $out;
        });

        return $map;
    }
}

if (! function_exists('hub_col_num_max')) {
    /** أقصى قيمةٍ مطلقةٍ يسعها عمودٌ عشريّ — نصّاً دقيقاً (لا float يُقرِّب فيتسرّب الفيض) */
    function hub_col_num_max(string $table, string $col): ?string
    {
        return hub_col_nums()[$table][$col] ?? null;
    }
}

if (! function_exists('hub_col_nums')) {
    /**
     * خريطةُ حدود الأعمدة العشرية (`decimal(M, D)`) من **مصدر الهجرات**.
     *
     * كحدّ الطول النصّيّ (`hub_col_widths`): SQLite لا يفرض دقّةَ decimal فتمرّ
     * القيمةُ الفائضة في الاختبار، ثم يرفضها MySQL في الإنتاج بـ22003 (خطأ ٥٠٠،
     * ورسالتُه تُسرّب القيمة إلى مركز الأخطاء). الحدُّ من العمود يرفضها برسالةٍ
     * للمستخدم قبل القاعدة. أقصى مطلق = 10^(M−D) − 10^(−D).
     */
    function hub_col_nums(): array
    {
        static $map = null;
        if ($map !== null) return $map;

        $map = \Illuminate\Support\Facades\Cache::remember('hub:colnums:' . config('hub.version'), 86400, function () {
            $out = [];
            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                $src = (string) @file_get_contents($file);
                preg_match_all("/Schema::(?:create|table)\\(\\s*'([a-z0-9_]+)'(.*?)(?=Schema::(?:create|table)\\(|\\z)/s",
                    $src, $blocks, PREG_SET_ORDER);
                foreach ($blocks as $b) {
                    preg_match_all("/->decimal\\(\\s*'([a-z0-9_]+)'\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)/", $b[2], $cols, PREG_SET_ORDER);
                    foreach ($cols as $c) {
                        // الحدُّ نصّاً دقيقاً (٩ لكل خانة) لا floatًا: `10**13 - 0.001`
                        // لا يُمثَّل تماماً في double فيتسرّب فيضٌ يرفضه MySQL بـ22003.
                        $intDigits = max(0, (int) $c[2] - (int) $c[3]);
                        $dec = (int) $c[3];
                        $out[$b[1]][$c[1]] = (str_repeat('9', $intDigits) ?: '0')
                            . ($dec > 0 ? '.' . str_repeat('9', $dec) : '');
                    }
                }
            }

            return $out;
        });

        return $map;
    }
}

if (! function_exists('hub_fit')) {
    /**
     * قصُّ نصٍّ ليسع عموداً — **بالمحارف** لا بالبايتات.
     * `substr` تقطع الحرف العربي نصفين فتُنتج UTF-8 فاسدةً يرفضها MySQL
     * بـ«Incorrect string value» ويبتلعها SQLite صامتاً.
     *
     * والتطهيرُ قبل القصّ (v2.312): المصدرُ نفسه قد يصل فاسداً — ترويسة
     * `User-Agent` يملكها الطالب فيرسل فيها ما شاء من بايتات. وبايتةٌ يتيمة
     * واحدة تُسقط ثلاثة أشياء دفعةً: `json_encode` في سمة `Auditable`
     * (فينهار مسارُ الكتابة كلّه بـJsonEncodingException)، وMySQL الصارمة،
     * والنسخةَ الاحتياطية (`json_encode` تعيد false فتُكتب نسخةٌ صفريّة).
     */
    function hub_fit(?string $v, int $max): ?string
    {
        if ($v === null) return null;

        // إسقاطُ ما ليس UTF-8 صالحاً بدل تمريره — الصمتُ هنا يُفسد ما بعده
        if (! mb_check_encoding($v, 'UTF-8')) {
            $v = (string) mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        }
        // ‏NUL يقطع النصّ في بعض المحرّكات ويُفسد الفهارس
        $v = str_replace("\0", '', $v);

        return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
    }
}

if (! function_exists('hub_read')) {
    /**
     * قارئُ وحدةٍ محروس — سكّةٌ واحدة لكل شاشةٍ تجمع من عدّة وحدات.
     *
     * جولةُ تدقيقٍ كشفت نمطاً يتكرّر: شاشةٌ تُقاس بصلاحية وحدةٍ **واحدة** ثم
     * تقرأ خمس وحداتٍ أخرى بـ`DB::table(...)` خاماً — فتعرض لمن يملك «الأحداث»
     * كتالوجَ الإعلام، ولمن يملك «الباقات» تكلفةَ الخدمات، ولمقاولٍ على مشروعٍ
     * واحد أسماءَ المشاريع كلها.
     *
     * هذا القارئ يجمع الحرّاس الثلاثة في مكانٍ واحد: وجودُ الجدول، و`hub_can`
     * للوحدة، و`hub_scope` لنطاق القارئ، وتصفيةُ المحذوف. ويعيد `null` عند
     * المنع — فالمستدعي يعرف أن لا شيء له هنا ولا يقرأ خاماً «للاحتياط».
     */
    function hub_read(string $module, $user = null)
    {
        $user = $user ?? auth()->user();
        $def = hub_mod($module);
        if (! $def) return null;

        $table = $def['table'] ?? null;
        if (! $table || ! \Illuminate\Support\Facades\Schema::hasTable($table)) return null;
        if (! hub_can($user, $module, 'v')) return null;

        $q = \Illuminate\Support\Facades\DB::table($table);
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at')) $q->whereNull('deleted_at');

        return hub_scope($q, $module, $user);
    }
}

if (! function_exists('hub_masked')) {
    /** هل هذا الحقل محجوبٌ أو للقراءة فقط عن هذا القارئ؟ */
    function hub_masked(string $module, string $field, $user = null): bool
    {
        return hub_field_mode($user ?? auth()->user(), $module, $field) !== '';
    }
}

if (! function_exists('hub_data_bump')) {
    /**
     * ختمُ تغيّر جدول — عدّادٌ يزيد مع كل كتابةٍ على الجدول.
     *
     * التخبئة بمهلةٍ وحدها تكذب: تُعدِّل سجلاً فتبقى الشاشة تعرض الرقم القديم
     * خمس دقائق، فيظنّ المستخدم أن تعديله لم يُحفظ فيعيده. الختم يجعل المفتاح
     * نفسه يتغيّر ساعةَ تتغيّر البيانات — فلا نصّ يُبطَل يدوياً ولا رقمٌ يتأخّر.
     */
    function hub_data_bump(?string $table = null): void
    {
        $k = 'hub:stamp:' . ($table ?: '*');
        try {
            \Illuminate\Support\Facades\Cache::put($k,
                ((int) \Illuminate\Support\Facades\Cache::get($k, 0)) + 1, 86400);
        } catch (\Throwable $e) {
            // خبيئةٌ معطّلة لا تُسقط عملية حفظ — أسوأ ما يقع أن تتأخر شاشةٌ محسوبة
        }
    }
}

if (! function_exists('hub_data_stamp')) {
    /** ختم الجداول المطلوبة مجموعاً — جزءٌ من مفتاح الشاشة */
    function hub_data_stamp(array $tables): string
    {
        if (! $tables) return '';

        $c = \Illuminate\Support\Facades\Cache::class;

        return ':' . implode('.', array_map(
            fn ($t) => (string) (int) \Illuminate\Support\Facades\Cache::get('hub:stamp:' . $t, 0), $tables));
    }
}

if (! function_exists('hub_screen')) {
    /**
     * غلاف شاشةٍ محسوبة: بصمة النطاق + ختم الجداول + مهلة + `?fresh=1`.
     *
     * الشاشات المحسوبة تقرأ عشرات الاستعلامات وقيمتُها لا تتغير كل ثانية —
     * لكنها **تتغيّر ساعةَ تتغيّر بياناتها**، فالختم يسبق المهلة.
     */
    function hub_screen(string $prefix, int $ttl, \Closure $fn, array $tables = [])
    {
        return hub_cached(hub_scope_key($prefix) . hub_data_stamp($tables), $ttl,
            (bool) request()->query('fresh'), $fn);
    }
}

if (! function_exists('hub_related')) {
    /**
     * السجلات المرتبطة بسجل: كل وحدة تشير إليه بحقل مرجعي، مع صفوفها وعدّها.
     *
     * مستخرَجة من `ModuleController::show` لأن أربعة متحكمات (الرحلة، مركز التطبيق،
     * السلسلة، البوابة) أعادت اختراعها بـ`DB::table` خام — وهناك تسرّب النطاق.
     * هنا النطاق والصلاحية مفروضان **بالبناء**: `hub_can` قبل الوحدة و`hub_scope` على استعلامها.
     */
    function hub_related(string $module, string $recordId, int $limit = 8): array
    {
        $out = [];
        foreach (hub_children($module) as [$ck, $cf]) {
            if (! hub_can(auth()->user(), $ck, 'v')) continue;
            $cd = hub_mod($ck);
            $cc = '\\App\\Models\\' . ($cd['model'] ?? '');
            if (! class_exists($cc)) continue;

            // المرجع المتعدد يُخزَّن مصفوفةَ معرِّفات — فعكسه احتواءٌ لا مساواة
            $base = hub_scope(
                empty($cf['multi'])
                    ? $cc::where($cf['col'], $recordId)
                    : $cc::whereJsonContains($cf['col'], $recordId),
                $ck
            );
            // الصفوف أولاً والعدّ عند الحاجة فقط — كان count(*) لكل وحدة بنت
            // (٤٠+ استعلاماً على صفحة مشروع فارغ) قبل أي جلب
            $rows = (clone $base)->orderByDesc('created_at')->limit($limit + 1)->get();
            if ($rows->isEmpty()) continue;

            $out[] = [
                'module'  => $ck,
                'label'   => $cd['label'],
                'field'   => $cf,
                'count'   => $rows->count() <= $limit ? $rows->count() : (clone $base)->count(),
                'rows'    => $rows->take($limit),
                'display' => hub_display_col($ck),
            ];
        }

        return $out;
    }
}

if (! function_exists('hub_closed_scope')) {
    /** نقيض `hub_open_scope` — المنتهية وحدها. مصدرهما واحد فلا ينزلق تعريفٌ عن نقيضه */
    function hub_closed_scope($q, string $col = 'status', array $alsoClosed = [])
    {
        $closed = array_values(array_unique(array_merge(hub_closed_states(), $alsoClosed)));

        return $q->whereIn($col, $closed);
    }
}

if (! function_exists('hub_workdays')) {
    /** أيام العمل بين تاريخين — الجمعة والسبت عطلة (قابلة للضبط بـ cost.weekend) */
    function hub_workdays(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): int
    {
        $off = array_filter(array_map('intval', preg_split('/[،,\s]+/u',
            (string) setting('cost.weekend', '5,6'), -1, PREG_SPLIT_NO_EMPTY)));
        $off = $off ?: [5, 6];                       // ٥=الجمعة ٦=السبت بترقيم ISO
        $n = 0;
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            if (! in_array($d->dayOfWeekIso, $off, true)) $n++;
        }

        return $n;
    }
}

if (! function_exists('hub_capacity')) {
    /**
     * القدرات والاستغلال لكل موظف خلال فترة:
     *   المتاح = (أيام العمل − أيام الإجازة المعتمدة) × ساعات اليوم
     *   المحجوز = مجموع الساعات المقدَّرة لمهامه المفتوحة المستحقة في الفترة
     *   المسجَّل = ساعاته الفعلية (من المهام والحضور)
     * الحمل = المحجوز ÷ المتاح · الاستغلال = المسجَّل ÷ المتاح · والاختناق حمل > ١٠٠٪.
     */
    function hub_capacity(?string $from = null, ?string $to = null, ?string $projectId = null): array
    {
        // **تاريخٌ حرٌّ من الرابط لا يُسقط الشاشة** (v2.325): `Carbon::parse` على
        // نصٍّ لا يُفهَم ترمي `InvalidFormatException` غيرَ ملتقطة — ٥٠٠ على
        // مسارٍ مصادَق بمعاملٍ يتحكّم به الطالب. ما لا يُفهَم يرتدّ للافتراضي.
        $safe = function ($v, \Closure $default) {
            $v = hub_str($v);
            if ($v === '') return $default();
            try {
                return \Illuminate\Support\Carbon::parse($v);
            } catch (\Throwable $e) {
                return $default();
            }
        };
        $f = $safe($from, fn () => now()->startOfMonth())->startOfDay();
        $t = $safe($to, fn () => now()->endOfMonth())->endOfDay();
        // ومدىً معكوسٌ أو مفرطُ الطول يُقوَّم: حلقةُ الأيام أدناه تُحسب يوماً بيوم
        if ($t->lt($f)) [$f, $t] = [$t->copy()->startOfDay(), $f->copy()->endOfDay()];
        if ($f->diffInDays($t) > 732) $t = $f->copy()->addDays(732)->endOfDay();

        $hoursDay = max(1, (int) setting('cost.work_hours', 8));
        $workDays = hub_workdays($f, $t);
        $DB = \Illuminate\Support\Facades\DB::class;

        $emps = \Illuminate\Support\Facades\DB::table('employees')->whereNull('deleted_at')
            ->whereNotIn('status', ['منتهية خدمته', 'مستقيل', 'موقوف'])
            ->orderBy('name')->limit(300)->get(['id', 'name', 'dept', 'user_id']);
        // تحت العدسة: الموظف ينتمي للمشروع **بعمله فيه** لا بعمود project_id على
        // ملفه (وهو شبه فارغ دائماً — الموظف ليس ملكاً لمشروع). ترشيحه بالعمود
        // كان سيُفرغ اللوحة كلها ويعرض أصفاراً تبدو حقيقةً لا فراغَ بيانات.
        if ($projectId) {
            $onProject = \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
                ->where('project_id', $projectId)->whereNotNull('assignee_id')
                ->distinct()->pluck('assignee_id')->all();
            $emps = $emps->filter(fn ($e) => in_array($e->user_id, $onProject, true))->values();
        }

        if ($emps->isEmpty()) return ['rows' => [], 'from' => $f->toDateString(), 'to' => $t->toDateString(),
                                      'workDays' => $workDays, 'hoursDay' => $hoursDay,
                                      'lensed' => (bool) $projectId, 'totals' => []];

        $userIds = $emps->pluck('user_id')->filter()->all();
        $empIds  = $emps->pluck('id')->all();

        // إجازات معتمدة متقاطعة مع الفترة — «معتمد» هي قيمة السجل المعلنة
        // (كانت «معتمدة» فلا تُخصم إجازة واحدة من الطاقة أبداً)
        $leaves = \Illuminate\Support\Facades\DB::table('leave_requests')->whereNull('deleted_at')
            ->where('status', 'معتمد')->whereIn('emp_id', $empIds)
            ->whereDate('date_from', '<=', $t->toDateString())
            ->whereDate('date_to', '>=', $f->toDateString())
            ->get(['emp_id', 'date_from', 'date_to']);
        $leaveDays = [];
        foreach ($leaves as $l) {
            $a = \Illuminate\Support\Carbon::parse($l->date_from)->max($f);
            $b = \Illuminate\Support\Carbon::parse($l->date_to)->min($t);
            $leaveDays[$l->emp_id] = ($leaveDays[$l->emp_id] ?? 0) + hub_workdays($a, $b);
        }

        $open = ['منجزة', 'مكتملة', 'ملغاة'];

        // المحجوز: مهام مفتوحة مستحقة في الفترة (أو بلا موعد — تُحتسب على الفترة الحالية)
        $booked = $userIds ? \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('assignee_id', $userIds)->whereNotIn('status', $open)
            ->where(fn ($q) => $q->whereNull('due')->orWhereBetween('due', [$f->toDateString(), $t->toDateString()]))
            ->selectRaw('assignee_id, COALESCE(SUM(est_h),0) h, COUNT(*) n')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        // المسجَّل: ساعات فعلية على مهام حُدِّثت داخل الفترة
        $logged = $userIds ? \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('assignee_id', $userIds)->whereNotNull('act_h')
            ->whereBetween('updated_at', [$f, $t])
            ->selectRaw('assignee_id, COALESCE(SUM(act_h),0) h')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        // حضور مسجَّل داخل الفترة (مصدر أدق حين يُستخدم)
        $att = \Illuminate\Support\Facades\DB::table('attendance')->whereNull('deleted_at')
            ->whereIn('emp_id', $empIds)->whereBetween('date', [$f->toDateString(), $t->toDateString()])
            ->selectRaw('emp_id, COALESCE(SUM(hours),0) h')->groupBy('emp_id')->get()->keyBy('emp_id');

        // مشاريع مُسندة
        $projects = $userIds ? \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('assignee_id', $userIds)->whereNotIn('status', $open)->whereNotNull('project_id')
            ->selectRaw('assignee_id, COUNT(DISTINCT project_id) n')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        $rows = [];
        foreach ($emps as $e) {
            $lv  = (int) ($leaveDays[$e->id] ?? 0);
            $avail = max(0, ($workDays - $lv) * $hoursDay);
            $bk  = (float) ($booked[$e->user_id]->h ?? 0);
            // بلا عدسة: أدقّ المصدرين. وتحتها: ساعات المهام وحدها — بصمة الحضور
            // لا تُنسب لمشروع، ونسبتها إليه تعطي رقماً خاطئاً يبدو معقولاً.
            $lg  = $projectId ? (float) ($logged[$e->user_id]->h ?? 0)
                              : max((float) ($logged[$e->user_id]->h ?? 0), (float) ($att[$e->id]->h ?? 0));

            $rows[] = [
                'id' => $e->id, 'name' => $e->name, 'dept' => $e->dept,
                'leaveDays' => $lv, 'available' => $avail,
                'booked' => round($bk, 1), 'logged' => round($lg, 1),
                'tasks' => (int) ($booked[$e->user_id]->n ?? 0),
                'projects' => (int) ($projects[$e->user_id]->n ?? 0),
                'load' => $avail > 0 ? (int) round($bk / $avail * 100) : null,
                'util' => $avail > 0 ? (int) round($lg / $avail * 100) : null,
                'linked' => (bool) $e->user_id,
            ];
        }

        usort($rows, fn ($a, $b) => ($b['load'] ?? -1) <=> ($a['load'] ?? -1));

        return [
            'rows' => $rows, 'from' => $f->toDateString(), 'to' => $t->toDateString(),
            'workDays' => $workDays, 'hoursDay' => $hoursDay,
            'lensed' => (bool) $projectId,
            'totals' => [
                'available' => array_sum(array_column($rows, 'available')),
                'booked'    => round(array_sum(array_column($rows, 'booked')), 1),
                'logged'    => round(array_sum(array_column($rows, 'logged')), 1),
                'over'      => count(array_filter($rows, fn ($r) => ($r['load'] ?? 0) > 100)),
                // «بلا شغل» يُقاس بطاقة الموظف الكاملة، وتحت العدسة ينكمش المحجوز
                // وحده — فيُقال «الفريق عاطل» وهو محترقٌ على مشاريع أخرى. إنذارٌ
                // كاذب يقود لقرار توظيفٍ خاطئ، فيُسكَت تحت العدسة عمداً.
                'idle'      => $projectId ? 0
                    : count(array_filter($rows, fn ($r) => $r['available'] > 0 && ($r['load'] ?? 0) < 50)),
                'unlinked'  => count(array_filter($rows, fn ($r) => ! $r['linked'])),
            ],
        ];
    }
}

if (! function_exists('hub_app_quality')) {
    /**
     * جودة البرمجيات لكل تطبيق — تجميع فوق ما يُسجَّل أصلاً:
     * الأخطاء المفتوحة والحرجة وزمن حلها، نجاح الاختبارات من خطة العمل،
     * الأعطال بعد النشر من سجل الحوادث، ومعدل التراجع من سجل النشر.
     */
    function hub_app_quality(bool $fresh = false, ?string $projectId = null): array
    {
        // صلاحية الوحدة أولاً: hub_scope يُنطّق بالمشاريع ولا يقرأ مصفوفة
        // الصلاحيات، فمن يحمل عَلَم «المتابعة» بلا صلاحية رؤية التطبيقات كان
        // يرى أسماءها وأرقامها كاملةً في هذه اللوحة.
        $u = auth()->user();
        if ($u && ! hub_can($u, 'apps', 'v')) return [];

        // ومفتاحٌ عامّ فوق استعلامٍ مُنطَّق بـhub_scope كان يُقدّم أرقام مستخدمٍ
        // لآخر. المفتاح يفصل بالدور، وبالمستخدم نفسه حين يكون نطاقه مقيّداً.
        $key = 'quality:apps:' . (hub_scoped($u) || hub_company_ids($u) !== null
            ? 'u:' . ($u?->id ?? '0') : 'r:' . ($u?->role_id ?? '0')) . hub_lens_key($projectId);
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($projectId) {
            $DB = \Illuminate\Support\Facades\DB::class;
            $closed = hub_closed_states();
            // الترشيح **داخل** الاستعلام قبل القصّ: ترشيحٌ بعده يُخفي تطبيقات
            // المشروع الواقعة خارج أول ٨٠ اسماً أبجدياً بلا أثر
            $apps = hub_scope(\Illuminate\Support\Facades\DB::table('applications')->whereNull('deleted_at'), 'apps')
                ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                ->orderBy('name')->limit(80)->get(['id', 'name', 'ver', 'status', 'project_id',
                    'downloads', 'rating', 'reviews', 'auto_store']);
            if ($apps->isEmpty()) return [];

            $ids = $apps->pluck('id')->all();
            $pids = $apps->pluck('project_id')->filter()->all();

            $iss = \Illuminate\Support\Facades\DB::table('issues')->whereNull('deleted_at')
                ->whereIn('app_id', $ids)->get(['app_id', 'severity', 'status', 'found', 'closed']);

            // الحوادث ٩٠ يوماً: عدّها ومتوسط زمن تعافيها (MTTR) — كان زمن التعطل
            // المسجَّل لا يُحوَّل إلى مؤشرٍ واحد رغم توفره في كل حادثة
            $inc = \Illuminate\Support\Facades\Schema::hasTable('incidents')
                ? \Illuminate\Support\Facades\DB::table('incidents')->whereNull('deleted_at')
                    ->whereIn('app_id', $ids)->where('created_at', '>=', now()->subDays(90))
                    ->selectRaw('app_id, COUNT(*) n, AVG(downtime_min) mttr')
                    ->groupBy('app_id')->get()->keyBy('app_id')
                : collect();

            $dep = \Illuminate\Support\Facades\Schema::hasTable('deployments')
                ? \Illuminate\Support\Facades\DB::table('deployments')->whereNull('deleted_at')
                    ->whereIn('app_id', $ids)->get(['app_id', 'status', 'deployed_at'])
                : collect();

            $feats = $pids ? \Illuminate\Support\Facades\DB::table('plan_items')->whereNull('deleted_at')
                ->whereIn('project_id', $pids)->whereNotNull('test')->where('test', '!=', '')
                ->get(['project_id', 'test']) : collect();

            // كل سلاسل المتاجر باستعلامٍ واحد بدل ~١٠ لكل تطبيق
            $preMetrics = hub_metric_bulk_series('apps', $ids, ['downloads', 'rating', 'reviews'], 30);

            $out = [];
            foreach ($apps as $a) {
                $mine = $iss->where('app_id', $a->id);
                $open = $mine->filter(fn ($i) => ! in_array((string) $i->status, $closed, true));

                // متوسط زمن الحل بالأيام للأخطاء المغلقة بتاريخين
                $days = $mine->filter(fn ($i) => $i->found && $i->closed)
                    ->map(fn ($i) => \Illuminate\Support\Carbon::parse($i->found)
                        ->diffInDays(\Illuminate\Support\Carbon::parse($i->closed)));

                $ft = $a->project_id ? $feats->where('project_id', $a->project_id) : collect();
                $pass = $ft->filter(fn ($f) => in_array((string) $f->test, ['نجح', 'ناجح', 'مرّ'], true))->count();

                $dp = $dep->where('app_id', $a->id);
                $rolled = $dp->filter(fn ($d) => in_array((string) $d->status, ['متراجع عنه', 'فشل'], true))->count();

                $out[] = [
                    'id' => $a->id, 'name' => $a->name, 'ver' => $a->ver, 'status' => $a->status,
                    'openBugs' => $open->count(),
                    'critBugs' => $open->filter(fn ($i) => in_array((string) $i->severity, ['حرجة', 'حرج', 'عالية'], true))->count(),
                    'fixDays'  => $days->count() ? round($days->avg(), 1) : null,
                    'tested'   => $ft->count(),
                    'passRate' => $ft->count() ? (int) round($pass / $ft->count() * 100) : null,
                    'incidents' => (int) ($inc[$a->id]->n ?? 0),
                    'mttr' => isset($inc[$a->id]->mttr) && $inc[$a->id]->mttr !== null
                        ? (int) round((float) $inc[$a->id]->mttr) : null,
                    'deploys'  => $dp->count(),
                    'rollback' => $dp->count() ? (int) round($rolled / $dp->count() * 100) : null,
                    'lastDeploy' => $dp->max('deployed_at'),
                    // واقع المتاجر: جودةٌ داخلية بلا تحميلٍ ولا تقييمٍ نصفُ صورة.
                    // السلاسل مُجهَّزة دفعةً واحدة أعلاه — لا استعلام لكل صف.
                    'store' => hub_app_store($a, 30, $preMetrics[$a->id] ?? []),
                ];
            }

            return $out;
        });
    }
}

if (! function_exists('hub_size_kb')) {
    /**
     * حجمٌ مكتوبٌ بلاحقة php.ini («512M») بالكيلوبايت — و«0» أو «-1» بلا حدّ.
     * منفصلةٌ عن قارئ ini عمداً كي تُختبَر بقيمٍ لا يملك الاختبارُ ضبطَها في ini.
     */
    function hub_size_kb(string $value): int
    {
        $v = trim($value);
        if ($v === '' || $v === '0' || $v === '-1') return 0;

        $unit = mb_strtolower(substr($v, -1));
        $n = (float) $v;

        return (int) match ($unit) {
            'g' => $n * 1024 * 1024,
            'm' => $n * 1024,
            'k' => $n,
            default => $n / 1024,      // بايتاتٌ مجرّدة
        };
    }
}

if (! function_exists('hub_ini_kb')) {
    /** قيمةُ إعدادٍ في php.ini بالكيلوبايت («512M» → 524288) — صفرٌ يعني بلا حدّ */
    function hub_ini_kb(string $key): int
    {
        return hub_size_kb((string) ini_get($key));
    }
}

if (! function_exists('hub_upload_cap')) {
    /**
     * **السقفُ الفعليّ للرفع — لا السقف المُعلَن.**
     *
     * كان `setting('files.max_kb')` يُحقن في قواعد التحقق وحده، ويُعرض للمستخدم
     * كأنه الحقيقة. وهو **نصفُ الحقيقة**: الخادم يقطع الطلب قبل أن يصل إلى
     * Laravel أصلاً حين يتجاوز `upload_max_filesize` أو `post_max_size` — فيرى
     * المستخدم صفحةً فارغةً أو خطأً غامضاً بعد انتظار رفعِ نصفِ غيغابايت،
     * والنظامُ كان يَعِده بحدٍّ لا يملكه.
     *
     * فالسقفُ هنا **أصغرُ الثلاثة**، ومعه من أين جاء الحدّ — كي تُقال الحقيقة
     * في الواجهة: «الحد ١ غيغابايت» أو «الحد ٢ م.ب — سقفُ الخادم، ارفعه من
     * php.ini». `post_max_size` يُحسب بهامشٍ صغير لحقول النموذج الأخرى.
     */
    function hub_upload_cap(): array
    {
        $app = (int) setting('files.max_kb', 1048576);          // ١ غيغابايت افتراضاً
        $up = hub_ini_kb('upload_max_filesize');
        $post = hub_ini_kb('post_max_size');
        // النموذجُ يحمل حقولاً غير الملف — فبضعةُ كيلوباياتٍ تُترك للبقية
        $post = $post > 64 ? $post - 64 : $post;

        $php = min(array_filter([$up ?: PHP_INT_MAX, $post ?: PHP_INT_MAX]));
        $php = $php === PHP_INT_MAX ? 0 : (int) $php;           // بلا حدٍّ في الخادم

        /*
         * **والتقطيعُ يرفع سقفَ الطلب الواحد عن الطريق.**
         *
         * سقفُ PHP قيدٌ على **الطلب** لا على الملف: فإذا وصل الملفُّ قطعاً صغيرة
         * وجُمِّع على القرص (`ChunkedUpload`) فلا شأنَ له به — والحدُّ الباقي حدُّ
         * النظام وحده. فالطلبُ الذي حُقنت فيه ملفاتٌ مقطَّعة يُقاس بحدّ النظام،
         * وإلا رُفض ملفٌ وصل كاملاً سليماً بحجّة سقفٍ لم يمرّ منه أصلاً.
         */
        $chunked = (bool) request()?->attributes?->get(\App\Http\Middleware\ResolveChunkedUploads::FLAG);

        $kb = ($php > 0 && ! $chunked) ? min($app, $php) : $app;
        $kb = max(1, $kb);

        // عتبةُ التقطيع: أقلُّ من سقف الطلب بهامشٍ لحقول النموذج وحدود multipart
        $chunkAt = $php > 0 ? max(512, (int) ($php * 0.7)) : max(4096, (int) ($app * 0.7));

        return [
            'kb'      => $kb,
            'bytes'   => $kb * 1024,
            'appKb'   => $app,
            'phpKb'   => $php,
            'byPhp'   => $php > 0 && $php < $app && ! $chunked,  // الخادمُ هو القاطع لا الإعداد
            'chunked' => $chunked,
            'chunkAt' => $chunkAt,                               // ما فوقها يُرفع مقطَّعاً
            'label'   => hub_bytes($kb * 1024),
        ];
    }
}

if (! function_exists('hub_bytes')) {
    /** حجم ملف مقروء: 1536 → «1.5 ك.ب» */
    function hub_bytes($bytes): string
    {
        $b = (float) $bytes;
        foreach (['ب', 'ك.ب', 'م.ب', 'ج.ب'] as $unit) {
            if ($b < 1024 || $unit === 'ج.ب') {
                return ($b >= 100 || $unit === 'ب' ? number_format($b) : number_format($b, 1)) . ' ' . $unit;
            }
            $b /= 1024;
        }

        return number_format($b) . ' ب';
    }
}

if (! function_exists('hub_cycle_monthly')) {
    /** تطبيع مبلغ دورةٍ ما إلى شهري — «مرة واحدة» و«حسب الاستخدام» لا شهرية لهما فتعيد null */
    function hub_cycle_monthly($amount, $cycle): ?float
    {
        if ($amount === null || $amount === '') return null;

        return match ((string) $cycle) {
            'سنوي' => (float) $amount / 12,
            'نصف سنوي' => (float) $amount / 6,
            'ربع سنوي' => (float) $amount / 3,
            'مرة واحدة', 'حسب الاستخدام' => null,
            default => (float) $amount,      // شهري أو غير محدد
        };
    }
}

if (! function_exists('hub_service_costs')) {
    /**
     * تحليل تكلفة الخدمات: لكل خدمة نشطة سعرها الشهري المكافئ مقابل كلفتها
     * الشهرية الحقيقية = المعلنة + حصتها من سيرفرها (مقسومة على الخدمات
     * المتشاركة فيه) + دومينها (سنوي ÷ ١٢) + اشتراكات الأدوات المطابقة بالاسم.
     * ولكل باقة: هامشها من تكلفتها التقديرية وعمر آخر مراجعة سعر.
     */
    function hub_service_costs(bool $fresh = false): array
    {
        if ($fresh) \Illuminate\Support\Facades\Cache::forget('svc:costs');

        return \Illuminate\Support\Facades\Cache::remember('svc:costs', 300, function () {
            $db = \Illuminate\Support\Facades\DB::class;
            $norm = fn (?string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));

            $services = $db::table('services')->whereNull('deleted_at')
                ->whereNotIn('status', hub_closed_states())->get();
            $servers = $db::table('servers')->whereNull('deleted_at')->get(['id', 'cost', 'cycle'])->keyBy('id');
            $domains = $db::table('domains')->whereNull('deleted_at')->get(['id', 'cost'])->keyBy('id');
            $subs = $db::table('subscriptions')->whereNull('deleted_at')
                ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['ملغي', 'منتهي']))
                ->get(['service', 'amount', 'cycle']);

            // كم خدمة تتشارك السيرفر نفسه؟ حصة كلٍّ = كلفته ÷ عددها
            $sharing = $services->whereNotNull('server_id')->countBy('server_id');

            $rows = [];
            foreach ($services as $s) {
                $priceM = hub_cycle_monthly($s->price, $s->cycle);
                $declared = $s->cost !== null ? (float) $s->cost : null;   // «تكلفة التشغيل الشهرية» شهرية أصلاً

                $serverM = null; $serverShared = 0;
                if ($s->server_id && ($sv = $servers[$s->server_id] ?? null) && $sv->cost !== null) {
                    $serverShared = (int) ($sharing[$s->server_id] ?? 1);
                    $whole = hub_cycle_monthly($sv->cost, $sv->cycle);
                    $serverM = $whole !== null ? $whole / max(1, $serverShared) : null;
                }

                // الدومين: كلفة التسجيل سنوية بطبيعتها → ÷ ١٢
                $domainM = ($s->domain_id && ($d = $domains[$s->domain_id] ?? null) && $d->cost !== null)
                    ? (float) $d->cost / 12 : null;

                // أدوات مطابقة بالاسم (يحوي أو يُحوى)
                $tools = 0.0; $toolNames = [];
                $sn = $norm($s->name);
                if ($sn !== '') {
                    foreach ($subs as $sub) {
                        $bn = $norm($sub->service);
                        if ($bn === '' || ($bn !== $sn && ! str_contains($bn, $sn) && ! str_contains($sn, $bn))) continue;
                        $m = hub_cycle_monthly($sub->amount, $sub->cycle);
                        if ($m !== null) { $tools += $m; $toolNames[] = trim((string) $sub->service); }
                    }
                }

                $parts = array_filter([$declared, $serverM, $domainM, $tools ?: null], fn ($v) => $v !== null);
                $costM = $parts ? round(array_sum($parts), 3) : null;
                $margin = ($priceM !== null && $costM !== null) ? round($priceM - $costM, 3) : null;

                $rows[] = [
                    'id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'status' => $s->status,
                    'cycle' => $s->cycle, 'price' => $s->price !== null ? (float) $s->price : null,
                    'priceM' => $priceM !== null ? round($priceM, 3) : null,
                    'declared' => $declared,
                    'serverM' => $serverM !== null ? round($serverM, 3) : null, 'serverShared' => $serverShared,
                    'domainM' => $domainM !== null ? round($domainM, 3) : null,
                    'toolsM' => $tools ? round($tools, 3) : null, 'toolNames' => array_values(array_unique($toolNames)),
                    'costM' => $costM, 'margin' => $margin,
                    'marginPct' => ($margin !== null && $priceM > 0) ? (int) round($margin / $priceM * 100) : null,
                ];
            }

            // الباقات: الهامش من التكلفة التقديرية وعمر السعر
            $plans = $db::table('pricing_plans')->whereNull('deleted_at')
                ->whereNotIn('status', hub_closed_states())->get();
            $svcNames = $services->pluck('name', 'id');
            $planRows = [];
            foreach ($plans as $p) {
                $margin = ($p->price !== null && $p->unit_cost !== null) ? (float) $p->price - (float) $p->unit_cost : null;
                $ageFrom = $p->price_changed_at ?: $p->effective_from;
                $planRows[] = [
                    'id' => $p->id, 'name' => $p->name, 'service' => $svcNames[$p->service_id] ?? null,
                    'price' => $p->price !== null ? (float) $p->price : null, 'cycle' => $p->cycle,
                    'currency' => $p->currency, 'unitCost' => $p->unit_cost !== null ? (float) $p->unit_cost : null,
                    'free' => (bool) $p->free_tier, 'margin' => $margin,
                    'marginPct' => ($margin !== null && (float) $p->price > 0) ? (int) round($margin / (float) $p->price * 100) : null,
                    'priceAgeDays' => $ageFrom ? (int) \Illuminate\Support\Carbon::parse($ageFrom)->diffInDays(now()) : null,
                ];
            }

            $withM = collect($rows)->filter(fn ($r) => $r['margin'] !== null);

            return [
                'rows' => collect($rows)->sortBy([['margin', 'asc']])->values()->all(),
                'plans' => collect($planRows)->sortBy([['margin', 'asc']])->values()->all(),
                'totals' => [
                    'revenueM' => round((float) collect($rows)->sum(fn ($r) => $r['priceM'] ?? 0), 2),
                    'costM' => round((float) collect($rows)->sum(fn ($r) => $r['costM'] ?? 0), 2),
                    'underwater' => $withM->where('margin', '<', 0)->count(),
                    'unpriced' => collect($rows)->whereNull('priceM')->count(),
                    'uncosted' => collect($rows)->whereNull('costM')->count(),
                    'plansUnder' => collect($planRows)->filter(fn ($p) => $p['margin'] !== null && $p['margin'] < 0 && ! $p['free'])->count(),
                ],
            ];
        });
    }
}

if (! function_exists('hub_fin_outstanding')) {
    /**
     * **التعريفُ الواحدُ للمستحقّ المتأخّر** — كان مُكرَّراً في خمسةِ مواضعَ بقاعدتين
     * متضاربتين (بعضُها يعتمد عمودَ `state` الذي لا محرّكَ يحوّله إلى «متأخرة»، وبعضُها
     * يستعمل `kind = 'فاتورة'` المجرّدة التي لا تطابق أنواعَ الدخل الحقيقية «فاتورة
     * مبيعات»/«دفعة واردة» — عيبٌ يحرسه `LyingMetricsRound7Test`). هنا يُوحَّد على
     * الأساس **الموثوق**: نوعٌ من الدخل، وحالةٌ ليست مسدَّدةً/ملغاةً/مسودة، ومتبقٍّ
     * موجبٌ حسابياً (`total − COALESCE(paid,0) > 0`)، واستحقاقٌ فات (اختياريّاً بعتبةِ أيام).
     *
     * يأخذ استعلامَ `fin_documents` (منطَّقاً مسبقاً بالمُنادي إن لزم) ويُطبّق المُرشِّح،
     * فلا يفرض نطاقاً بنفسه — التنطيقُ مسؤوليةُ المُنادي كبقيّة الاستعلامات.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $q
     */
    function hub_fin_outstanding($q, int $minDays = 0)
    {
        $income = (array) (config('hub.fin.income', []) ?: ['فاتورة مبيعات']);
        $cut = now()->subDays(max(0, $minDays))->toDateString();

        return $q->whereIn('kind', $income)
            ->whereNotIn('state', ['مدفوعة', 'ملغاة', 'مسودة'])
            ->whereNotNull('due')->whereDate('due', '<', $cut)
            ->whereRaw('COALESCE(total,0) - COALESCE(paid,0) > 0');
    }
}

if (! function_exists('hub_recommendations')) {
    /**
     * مركز التوصيات: يجمع إشاراتٍ قابلة للتنفيذ من محرّكات النظام القائمة —
     * خدمات تحت الماء، فريق فوق طاقته، مشاريع متعثرة، تطبيقات كثيرة الأعطال،
     * انتهاءات وشيكة، مستحقات غير محصّلة. كلها من بياناتك المسجَّلة لا من تقدير،
     * وكل توصية تحمل سببها بالأرقام ورابط إجرائها. مرتّبة بالأولوية.
     */
    function hub_recommendations(bool $fresh = false, ?string $projectId = null): array
    {
        // المفتاح كان سلسلةً مسطّحة «recs»: بلا مستخدمٍ ولا دورٍ ولا مشروع —
        // فوق استعلاماتٍ مُنطَّقة بـhub_scope. أي أن أول من يفتح اللوحة يُخبّئ
        // نتائجه لكل من بعده خمس دقائق. يحمل المفتاح الثلاثة الآن.
        // المفتاحُ يفرّق **كلَّ أنماط العزل الثلاثة**: مشروعٌ وشركةٌ **وعميل**. كان
        // عزلُ العميل غائباً — فمستخدمان محصوران بعميلين مختلفين يتقاسمان دورَهما
        // كانا يتقاسمان مفتاحَ الدور (`r:`) فتُخبَّأ إشاراتُ عميلٍ وتُقدَّم لمستخدمِ
        // آخر: تسريبُ عزلٍ عبر الخبيئة. من له أيُّ حصرٍ يأخذ مفتاحاً خاصّاً به.
        $u = auth()->user();
        $scopedKey = hub_scoped($u) || hub_company_ids($u) !== null || hub_client_ids($u) !== null;
        // **مبدّلُ الشركة/العميل جزءٌ من المفتاح** كما في `hub_scope_key`: بعضُ الكُتل
        // (تقاريرُ اليوم عبر `hub_company_scope`) تضيق بالمبدّل النشط، فمستخدمان يريان
        // «كلَّ الشركات» بمبدّلين مختلفين كانا يتقاسمان مفتاحاً فيُقدَّم عدُّ شركةٍ لأخرى.
        // ختمُ roles/users كما في `hub_scope_key` و`hub_expiry`: تغيّرُ صلاحيةٍ أو دورٍ
        // يُبطل الخبيئةَ فوراً لا بعد انقضاء المهلة (نافذةُ صلاحيةٍ متقادمةٍ للمستخدم غيرِ المحصور).
        $key = 'recs:' . ($scopedKey ? 'u:' . ($u?->id ?? '0') : 'r:' . ($u?->role_id ?? '0'))
            . ':' . (string) session('hub.company', '-') . ':' . (string) session('hub.client', '-')
            . hub_lens_key($projectId) . hub_data_stamp(['roles', 'users']);
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($projectId) {
            $rank = ['حرج' => 3, 'مهم' => 2, 'اطّلاع' => 1];
            $out = [];
            // `key`/`module`/`record_id` إضافةٌ متوافقةٌ خلفياً (القرّاء القدامى يقرؤون
            // الستّةَ الأولى ويتجاهلون الباقي): تجعل كلَّ إشارةٍ **قابلةً للتصرّف**
            // (إقرار/تأجيل/رفض) في مركز الفعل بمفتاحٍ ثابتٍ يُدمَج لا يتكرّر.
            $add = function ($sev, $ico, $title, $why, $url, $action, $key = null, $module = null, $recordId = null) use (&$out) {
                $out[] = compact('sev', 'ico', 'title', 'why', 'url', 'action', 'key', 'module', 'recordId');
            };

            // ١) خدمات تبيع بأقل من كلفتها
            try {
                $svc = hub_service_costs();
                // ترتيبٌ حتميّ قبل القصّ: الأسوأ هامشاً أولاً ثم `id` فاصلاً — كان القصُّ
                // يأخذ أوائلَ الصفوف بترتيب القاعدة (قرعةٌ بين المحرّكين عند تساوي الهامش).
                $losers = array_filter($svc['rows'], fn ($r) => $r['margin'] !== null && $r['margin'] < 0);
                usort($losers, fn ($a, $b) => [$a['margin'], $a['id'] ?? 0] <=> [$b['margin'], $b['id'] ?? 0]);
                foreach (array_slice($losers, 0, 5) as $s) {
                    $add('حرج', '🌊', 'خدمة تبيع بخسارة: ' . $s['name'],
                        'سعرها الشهري ' . number_format((float) $s['priceM'], 1) . ' وكلفتها ' . number_format((float) $s['costM'], 1)
                        . ' — هامش ' . number_format((float) $s['margin'], 1) . ' شهرياً. راجع السعر أو الكلفة.',
                        route('m.show', ['services', $s['id']]), 'راجع الخدمة',
                        'svc.loss:' . $s['id'], 'services', $s['id']);
                }
                if (($svc['totals']['unpriced'] ?? 0) > 0) {
                    $add('اطّلاع', '🏷️', ($svc['totals']['unpriced']) . ' خدمة بلا سعر شهري',
                        'لا يمكن قياس ربحيتها حتى تُسعّرها. سجّل أسعارها لتظهر في تحليل التكلفة.',
                        route('servicecosts'), 'افتح تحليل التكلفة');
                }
            } catch (\Throwable $e) {}

            // ٢) فريق فوق طاقته هذا الأسبوع
            try {
                $cap = hub_capacity(null, null, $projectId);
                $over = array_filter($cap['rows'], fn ($r) => ($r['load'] ?? 0) > 100);
                usort($over, fn ($a, $b) => $b['load'] <=> $a['load']);
                foreach (array_slice($over, 0, 4) as $r) {
                    $add('مهم', '🔥', 'فوق طاقته: ' . $r['name'],
                        'حمله ' . $r['load'] . '٪ — محجوز ' . $r['booked'] . ' ساعة على متاح ' . $r['available']
                        . '. أجّل أو وزّع أو وظّف.',
                        route('capacity'), 'افتح لوحة القدرات',
                        'cap.over:' . ($r['id'] ?? $r['name']), 'employees', $r['id'] ?? null);
                }
            } catch (\Throwable $e) {}

            // ٣) مشاريع متعثرة الصحة — **منطَّقٌ بـhub_scope كأختيه (٩ و١١)**: كان
            // يَسرد كلَّ مشاريع المنشأة (اسماً ودرجةَ صحّة) لمستخدمٍ محصورٍ بمشاريعه،
            // بينما كتلتا الركود والحواجب على الجدول نفسِه تحصرانه — تسريبٌ ثابتُ التناقض.
            try {
                $projects = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                    ->when($projectId, fn ($q) => $q->where('id', $projectId))
                    ->orderBy('id')->limit(40)->get(['id', 'name']);   // حتميّةُ اختيار الأربعين بين المحرّكين
                $sick = [];
                foreach ($projects as $p) {
                    $h = hub_project_health($p->id);
                    if (($h['score'] ?? 100) < 55) $sick[] = ['p' => $p, 'h' => $h];
                }
                usort($sick, fn ($a, $b) => $a['h']['score'] <=> $b['h']['score']);
                foreach (array_slice($sick, 0, 5) as $s) {
                    $add($s['h']['score'] < 40 ? 'حرج' : 'مهم', '🩺', 'مشروع متعثر: ' . $s['p']->name,
                        'صحته ' . $s['h']['score'] . '/١٠٠ (' . ($s['h']['label'] ?? '') . '). راجع عوامل التعثر في صفحته.',
                        route('m.show', ['projects', $s['p']->id]), 'افتح المشروع',
                        'proj.health:' . $s['p']->id, 'projects', $s['p']->id);
                }
            } catch (\Throwable $e) {}

            // ٤) تطبيقات كثيرة الأخطاء الحرجة أو التراجع عن النشر
            try {
                foreach (hub_app_quality() as $a) {
                    if (($a['critBugs'] ?? 0) >= 1) {
                        $add('حرج', '🐞', 'أخطاء حرجة مفتوحة: ' . $a['name'],
                            $a['critBugs'] . ' خطأ حرج مفتوح' . (($a['openBugs'] ?? 0) ? ' من ' . $a['openBugs'] . ' مفتوح' : '') . '. عالجها قبل النشر القادم.',
                            route('appquality'), 'افتح جودة البرمجيات');
                    } elseif (($a['rollback'] ?? null) !== null && $a['rollback'] > 20) {
                        $add('مهم', '↩️', 'نشر غير مستقر: ' . $a['name'],
                            'معدل التراجع ' . $a['rollback'] . '٪ من ' . ($a['deploys'] ?? 0) . ' نشرة. راجع جودة الإصدارات قبل الدفع.',
                            route('appquality'), 'افتح جودة البرمجيات');
                    }
                }
            } catch (\Throwable $e) {}

            // ٥) مستحقات غير محصّلة قديمة
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('fin_documents')) {
                    // التعريفُ الموحَّد `hub_fin_outstanding` (أنواعُ الدخل الحقيقية لا
                    // «فاتورة» المجرّدة)، منطَّقٌ بـhub_scope كبقيّة كُتل المركز.
                    $base = hub_scope(\Illuminate\Support\Facades\DB::table('fin_documents')->whereNull('deleted_at'), 'fin')
                        ->when($projectId, fn ($q) => $q->where('project_id', $projectId));
                    $overdue = hub_fin_outstanding($base)
                        ->orderBy('due')->orderBy('id')->limit(6)->get(['id', 'doc_no', 'partner', 'total', 'paid', 'due']);
                    foreach ($overdue as $d) {
                        $rem = (float) ($d->total ?? 0) - (float) ($d->paid ?? 0);
                        $days = (int) \Illuminate\Support\Carbon::parse($d->due)->diffInDays(now());
                        $add($days > 60 ? 'حرج' : 'مهم', '💸', 'مستحق متأخر: ' . ($d->partner ?: ($d->doc_no ?: 'فاتورة')),
                            'باقٍ ' . number_format($rem, 1) . ' متأخر ' . $days . ' يوماً. تابع التحصيل.',
                            route('m.show', ['fin', $d->id]), 'افتح الفاتورة',
                            'fin.overdue:' . $d->id, 'fin', $d->id);
                    }
                }
            } catch (\Throwable $e) {}

            // ٦) انتهاءات وشيكة (٧ أيام)
            try {
                $soon = collect(hub_expiry())->filter(fn ($i) => ($i['days'] ?? 99) <= 7)->take(6);
                foreach ($soon as $i) {
                    $add($i['days'] < 0 ? 'حرج' : 'مهم', '⏳', 'ينتهي قريباً: ' . $i['name'],
                        $i['mlabel'] . ' · ' . $i['flabel'] . ' — ' . ($i['days'] < 0 ? 'متأخر' : ($i['days'] === 0 ? 'اليوم' : 'خلال ' . $i['days'] . ' يوم')) . '.',
                        route('m.show', [$i['module'], $i['id']]), 'افتح السجل',
                        // المفتاح يحمل مميّزَ الحقل/الوثيقة (fkey) فلا تتصادم إشارتا انتهاءٍ
                        // على السجل نفسِه على حالةٍ واحدة (كان module:id وحدهما يُدمجانهما).
                        'expiry:' . $i['module'] . ':' . $i['id'] . ':' . ($i['fkey'] ?? ($i['flabel'] ?? '')),
                        $i['module'], $i['id']);
                }
            } catch (\Throwable $e) {}

            // ٧) عرضٌ مقبولٌ لم يُحوَّل إلى تسليم (فجوةُ CPQ→تنفيذ) — منطَّقٌ بـhub_scope
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('quotes')) {
                    $stuck = hub_scope(\App\Models\Quote::query(), 'quotes')
                        ->where('status', 'مقبول')->whereNotNull('accepted_at')
                        ->where('accepted_at', '<', now()->subDays(2))
                        ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                        // فاصلٌ حتميّ: عروضٌ قُبلت في الثانية نفسها لا تُقترَع بين المحرّكين
                        ->orderByDesc('accepted_at')->orderByDesc('id')->limit(8)->get(['id', 'doc_no', 'title', 'accepted_at', 'meta']);
                    foreach ($stuck as $q) {
                        $meta = (array) (is_array($q->meta) ? $q->meta : (json_decode((string) $q->meta, true) ?: []));
                        // حُوِّل فعلاً (مشروعٌ أو ارتباط) → لا إشارة (حلٌّ تلقائيّ عند التحويل)
                        if (! empty($meta['project_id']) || ! empty($meta['engagement_id'])) continue;
                        $days = (int) \Illuminate\Support\Carbon::parse($q->accepted_at)->diffInDays(now());
                        $add($days > 7 ? 'حرج' : 'مهم', '🔗', 'عرضٌ مقبولٌ لم يُحوَّل: ' . ($q->title ?: $q->doc_no),
                            'قُبل منذ ' . $days . ' يوماً ولا مشروعَ ولا ارتباط. حوّله لتبدأ التسليمَ والتحصيل.',
                            route('m.show', ['quotes', $q->id]), 'حوّله لمشروع',
                            'quote.unconverted:' . $q->id, 'quotes', $q->id);
                    }
                }
            } catch (\Throwable $e) {}

            // ٨) عهدةٌ متأخرةُ الاسترداد — من منتِج القائم `Custody::overdue` (منطَّقٌ سلفاً)
            try {
                foreach (\App\Support\Custody::overdue(8) as $c) {
                    $add(($c['late'] ?? 0) > 14 ? 'حرج' : 'مهم', '📦', 'عهدةٌ متأخرةُ الاسترداد: ' . $c['asset'],
                        'تصريحُ «' . $c['action'] . '» استحقّ رجوعُه ' . $c['due'] . ' — متأخرٌ ' . $c['late'] . ' يوماً. تابع الاسترداد.',
                        route('m.show', ['assets', $c['assetId']]), 'افتح الأصل',
                        'custody.overdue:' . $c['id'], 'assets', $c['assetId']);
                }
            } catch (\Throwable $e) {}

            // ٩) مشاريع راكدة: نشطةٌ (غيرُ مغلقةٍ ولا متوقّفة) بلا حِراكٍ منذ مدّة —
            // **لا محرّكَ صحّةٍ ثانٍ**: إشارةٌ مستقلّةٌ تُشتقّ من آخرِ أثرٍ فعليّ
            // (تدقيقُ المشروع + آخرُ تحديثِ مهمّة)، منطَّقةٌ بـhub_scope كالبقية.
            try {
                $paused = ['متوقف', 'موقوف', 'معلّق', 'مُعلّق', 'مؤجل', 'مجمّد'];
                $projs = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                    ->where(fn ($w) => $w->whereNull('status')
                        ->orWhere(fn ($q) => $q->whereNotIn('status', hub_closed_states())->whereNotIn('status', $paused)))
                    ->when($projectId, fn ($q) => $q->where('id', $projectId))
                    // ترتيبٌ حتميّ قبل الحدّ: بلا `orderBy` يختلف الستّون المُختارون
                    // بين المحرّكين فيسقط راكدٌ خلف الصفّ ٦٠ بلا رصد. الأقدمُ تحديثاً
                    // أولاً — أرجحُ للركود، والمعرّفُ فاصلٌ ثابت.
                    ->orderBy('updated_at')->orderBy('id')
                    ->limit(60)->get(['id', 'name', 'updated_at']);
                if ($projs->isNotEmpty()) {
                    $ids = $projs->pluck('id')->all();
                    $auditMax = \Illuminate\Support\Facades\DB::table('audits')->where('module', 'projects')
                        ->whereIn('record_id', $ids)->select('record_id', \Illuminate\Support\Facades\DB::raw('MAX(created_at) as m'))
                        ->groupBy('record_id')->pluck('m', 'record_id');
                    $taskMax = \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
                        ->whereIn('project_id', $ids)->select('project_id', \Illuminate\Support\Facades\DB::raw('MAX(updated_at) as m'))
                        ->groupBy('project_id')->pluck('m', 'project_id');
                    $threshold = (string) now()->subDays(7);
                    $stalled = [];
                    foreach ($projs as $p) {
                        $last = collect([$p->updated_at, $auditMax[$p->id] ?? null, $taskMax[$p->id] ?? null])
                            ->filter()->map(fn ($t) => (string) $t)->max();
                        if (! $last || $last >= $threshold) continue;
                        $stalled[] = ['p' => $p, 'last' => substr($last, 0, 10),
                            'days' => (int) \Illuminate\Support\Carbon::parse($last)->diffInDays(now())];
                    }
                    usort($stalled, fn ($a, $b) => [$b['days'], $a['p']->id] <=> [$a['days'], $b['p']->id]);
                    foreach (array_slice($stalled, 0, 6) as $s) {
                        $add($s['days'] > 21 ? 'حرج' : 'مهم', '🕸️', 'مشروعٌ راكد: ' . $s['p']->name,
                            'لا حِراكَ (مهامٌ أو تدقيق) منذ ' . $s['days'] . ' يوماً — آخرُ نشاطٍ ' . $s['last'] . '. راجعه أو اطلب تحديثاً.',
                            route('m.show', ['projects', $s['p']->id]), 'افتح المشروع',
                            'proj.stalled:' . $s['p']->id, 'projects', $s['p']->id);
                    }
                }
            } catch (\Throwable $e) {}

            // ١٠) تقاريرُ يوميّةٌ ناقصةٌ اليوم — من **محرّك يوم العمل** (لا محرّكَ حضورٍ
            // ثانٍ): `Workday::teamToday` منطَّقٌ بمفتاح `hub_screen` (دورٌ/مستخدمٌ/شركةٌ/
            // عميل) ومحروسٌ بصلاحية `hr` داخل `teamCalc` — فلا تسريبٌ ولا تكرار. القاعدةُ
            // الذهبية محفوظة: تقريرٌ ناقصٌ ليس غياباً. (إشارةٌ للعدسة العامّة لا لمشروع.)
            try {
                if (! $projectId && hub_can(auth()->user(), 'hr', 'v')
                    && \Illuminate\Support\Facades\Schema::hasTable('attendance')) {
                    $team = \App\Support\Workday::teamToday();
                    $missing = (int) ($team['n']['noreport'] ?? 0);
                    if ($missing > 0) {
                        $add($missing >= 5 ? 'مهم' : 'اطّلاع', '📝', $missing . ' تقريرٌ يوميٌّ ناقصٌ اليوم',
                            'موظفون حاضرون اليوم بلا تقريرِ عمل (تقريرٌ ناقصٌ ليس غياباً). تابع مع فريقك.',
                            route('workforce.team'), 'افتح فريقي اليوم',
                            // **تصرّفٌ لكلِّ مستخدمٍ على حِدة**: هذه إشارةٌ تجميعيّةٌ (لا سجلٌّ
                            // واحد) يراها كلُّ مديري HR؛ فبمفتاحٍ مشترَكٍ كان تأجيلُ أحدهم
                            // يُخفيها عن البقية. المستخدمُ جزءٌ من المفتاح فيستقلّ تصرّفُه.
                            'report.missing:' . now()->toDateString() . ':u' . (auth()->id() ?? '0'), 'attend', null);
                    }
                }
            } catch (\Throwable $e) {}

            // ١١) حواجبُ مبلَّغة: مشاريعُ فيها تقاريرُ عملٍ حديثةٌ ذاتُ «مشكلات» — **لا
            // كيانَ حاجبٍ جديد**: يُقرأ من `work_updates.problems` (نفسُ ما يعدّه
            // `teamCalc`)، منطَّقٌ بالمشروع عبر hub_scope، وعمرُ الحاجب من أقدمِ بلاغ.
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('work_updates')) {
                    $projIds = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                        ->when($projectId, fn ($q) => $q->where('id', $projectId))->pluck('id');
                    if ($projIds->isNotEmpty()) {
                        $rows = \Illuminate\Support\Facades\DB::table('work_updates')->whereNull('deleted_at')
                            ->whereIn('project_id', $projIds->all())
                            ->whereNotNull('problems')->whereRaw("TRIM(problems) <> ''")
                            ->where('work_date', '>=', now()->subDays(14)->toDateString())
                            ->select('project_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as c'),
                                \Illuminate\Support\Facades\DB::raw('MIN(work_date) as firstd'))
                            ->groupBy('project_id')->orderByDesc('c')->orderBy('project_id')->limit(6)->get();
                        $names = hub_ref_labels('projects', $rows->pluck('project_id')->all());
                        foreach ($rows as $r) {
                            $age = (int) \Illuminate\Support\Carbon::parse($r->firstd)->diffInDays(now());
                            $add($r->c >= 3 ? 'حرج' : 'مهم', '🚧', 'حواجبُ مبلَّغة: ' . ($names[$r->project_id] ?? '—'),
                                $r->c . ' تقريرُ عملٍ يذكر مشكلةً/حاجباً، أقدمُها منذ ' . $age . ' يوماً. راجع المعوّقات مع الفريق.',
                                route('m.show', ['projects', $r->project_id]), 'افتح المشروع',
                                'proj.blockers:' . $r->project_id, 'projects', $r->project_id);
                        }
                    }
                }
            } catch (\Throwable $e) {}

            // ١٢) خرقُ SLA: تذاكرُ دعمٍ مفتوحةٌ تجاوزت موعدَ حلّها وفق قواعد `hub_sla`
            // القائمة — **لا محرّكَ SLA ثانٍ**: نفسُ الحاسبة (created_at + الأولوية + أوّلُ
            // ردٍّ غيرِ داخليّ + حالةُ الإغلاق)، منطَّقةٌ بـhub_scope ومحروسةٌ بصلاحية الرؤية.
            // تُحَلّ تلقائياً بحلِّ التذكرة (لا تعود resLate). لا مؤقّتاتٍ جديدة: تُشتقّ من القائم.
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('tickets') && hub_can(auth()->user(), 'tickets', 'v')) {
                    $open = hub_scope(\Illuminate\Support\Facades\DB::table('tickets')->whereNull('deleted_at'), 'tickets')
                        ->whereNotIn('status', ['تم الحل', 'مغلقة'])
                        ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                        // الأقدمُ إنشاءً أولاً (أرجحُ للخرق)، والمعرّفُ فاصلٌ حتميّ
                        ->orderBy('created_at')->orderBy('id')->limit(80)
                        ->get(['id', 'subject', 'priority', 'status', 'meta', 'created_at', 'updated_at']);
                    if ($open->isNotEmpty()) {
                        // أوّلُ ردٍّ غيرِ داخليٍّ لكلِّ تذكرةٍ دفعةً واحدة — لا N+1 داخل hub_sla
                        $firsts = \App\Models\Comment::where('module', 'tickets')
                            ->whereIn('record_id', $open->pluck('id')->all())
                            ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
                            ->select('record_id', \Illuminate\Support\Facades\DB::raw('MIN(created_at) as m'))
                            ->groupBy('record_id')->pluck('m', 'record_id');
                        $breached = [];
                        foreach ($open as $t) {
                            $s = hub_sla($t, $firsts[$t->id] ?? null);
                            if (! ($s['resLate'] ?? false)) continue;   // لم يتجاوز موعدَ الحلّ بعد
                            $over = (int) \Illuminate\Support\Carbon::parse($s['resDue'])->diffInDays(now());
                            $breached[] = ['t' => $t, 'over' => $over, 'noresp' => (bool) ($s['respPending'] ?? false)];
                        }
                        usort($breached, fn ($a, $b) => [$b['over'], $a['t']->id] <=> [$a['over'], $b['t']->id]);
                        foreach (array_slice($breached, 0, 6) as $b) {
                            $add($b['over'] > 3 ? 'حرج' : 'مهم', '⏱️', 'خرقُ SLA: ' . ($b['t']->subject ?: 'تذكرة'),
                                'تجاوزت موعدَ الحلّ بـ' . $b['over'] . ' يوماً' . ($b['noresp'] ? ' وبلا ردٍّ أوّلَ بعد' : '') . '. عالِجها أو صعّدها.',
                                route('m.show', ['tickets', $b['t']->id]), 'افتح التذكرة',
                                'sla.breach:' . $b['t']->id, 'tickets', $b['t']->id);
                        }
                    }
                }
            } catch (\Throwable $e) {}

            // ١٣) انحرافُ النطاق: مشروعٌ وُلد من عرضٍ مقبول (خطُّ أساسٍ محفوظ) تجاوزت
            // أوامرُ التغيير المطبَّقةُ عليه نسبةً جوهريّةً من قيمته الأصلية. يُقرأ من
            // `meta.baseline` القائم (الأصل + value_delta لكلِّ أمرٍ مطبَّق، يُحدَّث
            // معاملاتيّاً عند التطبيق في ChangeOrderController) — منطَّقٌ بـhub_scope.
            // نسبةٌ لا مبلغَ كلفةٍ داخليّ؛ القيمةُ التعاقدية إيرادٌ تراه لوحاتُ الرصد.
            try {
                $rows = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                    ->when($projectId, fn ($q) => $q->where('id', $projectId))
                    ->orderBy('id')->limit(80)->get(['id', 'name', 'meta']);
                $drift = [];
                foreach ($rows as $p) {
                    $meta = (array) (is_array($p->meta) ? $p->meta : (json_decode((string) $p->meta, true) ?: []));
                    $bl = $meta['baseline'] ?? null;
                    $orig = (float) ($bl['amount'] ?? 0);
                    if (! $bl || $orig <= 0) continue;   // لا خطَّ أساسٍ → لا مرجعَ للانحراف
                    $cos = (array) ($bl['change_orders'] ?? []);
                    $sum = 0.0;
                    foreach ($cos as $co) $sum += (float) ($co['value_delta'] ?? 0);
                    if ($sum == 0.0) continue;
                    $pct = abs($sum) / $orig * 100;
                    if ($pct < 25) continue;   // عتبةُ الانحراف الجوهريّ
                    $drift[] = ['p' => $p, 'pct' => $pct, 'n' => count($cos)];
                }
                usort($drift, fn ($a, $b) => [$b['pct'], $a['p']->id] <=> [$a['pct'], $b['p']->id]);
                foreach (array_slice($drift, 0, 6) as $d) {
                    $add($d['pct'] >= 50 ? 'حرج' : 'مهم', '📐', 'انحرافُ نطاق: ' . $d['p']->name,
                        'أوامرُ التغيير المطبَّقة (' . $d['n'] . ') غيّرت القيمةَ التعاقدية بنسبة '
                        . number_format($d['pct'], 0) . '٪ عن خطِّ الأساس. راجع النطاقَ والتسعير.',
                        route('m.show', ['projects', $d['p']->id]), 'افتح المشروع',
                        'scope.drift:' . $d['p']->id, 'projects', $d['p']->id);
                }
            } catch (\Throwable $e) {}

            // ١٤) تدهورُ الهامش زمنيّاً: هامشُ المشروع اليومَ مقابلَ أوّلِ لقطةٍ له في
            // آخر ٣٠ يوماً — من سلسلة `metric_points` (projects/pl_margin) التي يكتبها
            // `hub:automation` يوميّاً. نقطتان على يومين مختلفين على الأقلّ، وإلا فلا
            // إشارة — لا تُختلق سلسلةٌ من نقطةٍ واحدة. العتبةُ: هبوطٌ ≥ ١٠ نقاطٍ مئوية
            // «مهم»، و≥ ٢٠ نقطةً أو انقلابُ الهامش إلى الخسارة «حرج». هامشٌ داخليٌّ
            // بحت: يبقى خلف `hub_org_analytics_guard` وصلاحيةِ المالية كسائر الكلفة.
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('metric_points') && hub_can(auth()->user(), 'fin', 'v')) {
                    $rows = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                        ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                        ->when($projectId, fn ($q) => $q->where('id', $projectId))
                        ->orderBy('id')->limit(200)->get(['id', 'name'])->keyBy('id');
                    $pts = $rows->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('metric_points')
                        ->where('module', 'projects')->where('metric', 'pl_margin')
                        ->whereIn('record_id', $rows->keys()->all())
                        ->where('at', '>=', now()->subDays(30)->startOfDay()->toDateTimeString())
                        ->orderBy('at')->orderBy('id')->get(['record_id', 'value', 'at']);
                    $series = [];
                    foreach ($pts as $pt) $series[(string) $pt->record_id][] = $pt;
                    $decl = [];
                    foreach ($series as $pid => $s) {
                        if (count($s) < 2) continue;
                        $first = $s[0]; $last = end($s);
                        if (\Illuminate\Support\Carbon::parse($first->at)->isSameDay(\Illuminate\Support\Carbon::parse($last->at))) continue;
                        $from = (float) $first->value; $to = (float) $last->value;
                        $drop = $from - $to;
                        $flip = $from >= 0 && $to < 0;   // انقلابٌ إلى الخسارة: حرجٌ مهما صغُر الهبوط
                        if ($drop < 10 && ! $flip) continue;
                        $decl[] = ['p' => $rows[$pid], 'from' => $from, 'to' => $to, 'drop' => $drop, 'flip' => $flip,
                                   'days' => (int) \Illuminate\Support\Carbon::parse($first->at)->diffInDays(\Illuminate\Support\Carbon::parse($last->at))];
                    }
                    usort($decl, fn ($a, $b) => [$b['drop'], $a['p']->id] <=> [$a['drop'], $b['p']->id]);
                    foreach (array_slice($decl, 0, 6) as $d) {
                        $add(($d['drop'] >= 20 || $d['flip']) ? 'حرج' : 'مهم', '📉',
                            'تدهورُ هامش: ' . $d['p']->name,
                            'هبط الهامشُ من ' . number_format($d['from'], 1) . '٪ إلى ' . number_format($d['to'], 1)
                            . '٪ خلال ' . $d['days'] . ' يوماً (−' . number_format($d['drop'], 1) . ' نقطة). راجع الكلفةَ والفوترة.',
                            route('m.show', ['projects', $d['p']->id]), 'افتح المشروع',
                            'margin.decline:' . $d['p']->id, 'projects', $d['p']->id);
                    }
                }
            } catch (\Throwable $e) {}

            // الترتيب: الأشد أولاً، وضمن الدرجة يبقى ترتيب الاكتشاف
            usort($out, fn ($a, $b) => ($rank[$b['sev']] ?? 0) <=> ($rank[$a['sev']] ?? 0));

            return [
                'items'  => $out,
                'counts' => [
                    'حرج'    => count(array_filter($out, fn ($r) => $r['sev'] === 'حرج')),
                    'مهم'    => count(array_filter($out, fn ($r) => $r['sev'] === 'مهم')),
                    'اطّلاع' => count(array_filter($out, fn ($r) => $r['sev'] === 'اطّلاع')),
                ],
            ];
        });
    }
}

if (! function_exists('hub_supplier_scores')) {
    /**
     * بطاقة أداء الموردين من سجل المشتريات الفعلي: عدد الأوامر، الإنفاق،
     * نسبة الالتزام بالمواعيد (الأوامر المستلمة التي بلغت حالتها في موعدها أو قبله)،
     * المرتجعات والإلغاءات، الأوامر المفتوحة، وغير المسدَّد — مع التقييم اليدوي للجودة.
     *
     * ملاحظة صدق: «التسليم في الموعد» يقارن تاريخ آخر تحديث للأمر المستلم بموعد
     * تسليمه المتوقع (لا يوجد عمود تاريخ استلام صريح) — فهو تقدير معقول لا قياس دقيق.
     */
    function hub_supplier_scores(bool $fresh = false): array
    {
        if ($fresh) \Illuminate\Support\Facades\Cache::forget('sup:scores');

        return \Illuminate\Support\Facades\Cache::remember('sup:scores', 300, function () {
            $db = \Illuminate\Support\Facades\DB::class;
            $suppliers = $db::table('suppliers')->whereNull('deleted_at')->get();
            $rows = [];

            foreach ($suppliers as $s) {
                $po = $db::table('purchases')->whereNull('deleted_at')->where('supplier_id', $s->id)->get();

                $orders = $po->count();
                $spend = (float) $po->sum('amount');
                $received = $po->where('status', 'مستلم');
                // المرتجعات مسؤولية المورد؛ الإلغاء غالباً قرارنا فلا يُحتسب ضده
                $returned = $po->where('status', 'مرتجع')->count();
                $cancelled = $po->where('status', 'ملغى')->count();
                $open = $po->whereIn('status', ['معتمد', 'أُرسل للمورد', 'بانتظار الاعتماد'])->count();
                $unpaid = $po->filter(fn ($p) => ! in_array($p->pay_state, ['مدفوع', 'مسدد'], true)
                    && in_array($p->status, ['مستلم', 'أُرسل للمورد', 'معتمد'], true))->count();

                // الالتزام بالموعد: تاريخ الاستلام الصريح (يُختم عند إجراء الاستلام) هو
                // القياس — وآخر التحديث بديلٌ تقديري للأوامر السابقة لوجود العمود
                $withDue = $received->filter(fn ($p) => filled($p->due));
                $onTime = $withDue->filter(function ($p) {
                    $recvAt = \Illuminate\Support\Carbon::parse($p->received_at ?? $p->updated_at)->startOfDay();
                    return $recvAt->lte(\Illuminate\Support\Carbon::parse($p->due)->startOfDay());
                })->count();
                $onTimeRate = $withDue->count() ? (int) round($onTime / $withDue->count() * 100) : null;

                // نجمات التقييم اليدوي: عدّ ⭐ في نص التقييم
                $stars = substr_count((string) $s->rating, '⭐') ?: null;

                // قاعدة نسبة المرتجعات: الأوامر **المسلَّمة فعلاً** (مستلمة + مرتجعة) لا كل
                // الأوامر بما فيها المسودّات — ولا درجة لمن لم يُسلَّم منه شيء بعد
                $delivered = $received->count() + $returned;
                $rows[] = [
                    'id' => $s->id, 'name' => $s->name, 'cat' => $s->cat, 'stars' => $stars,
                    'ratingLabel' => (string) $s->rating,
                    'orders' => $orders, 'spend' => $spend,
                    'received' => $received->count(), 'returned' => $returned, 'cancelled' => $cancelled,
                    'open' => $open, 'unpaid' => $unpaid, 'delivered' => $delivered,
                    'onTimeRate' => $onTimeRate, 'onTimeBase' => $withDue->count(),
                    'score' => hub_supplier_score_calc($onTimeRate, $delivered, $returned),
                ];
            }

            // الأكثر إنفاقاً أولاً (حيث القرار أهم)
            usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

            return [
                'rows' => $rows,
                'totals' => [
                    'suppliers' => count($rows),
                    'spend' => round((float) array_sum(array_column($rows, 'spend')), 2),
                    'atRisk' => count(array_filter($rows, fn ($r) => $r['score'] !== null && $r['score'] < 50)),
                    // بلا درجة = لا تسليم بعد (سواء بلا أوامر أو أوامر مفتوحة/مسودّات فقط)
                    'noHistory' => count(array_filter($rows, fn ($r) => $r['score'] === null)),
                ],
            ];
        });
    }
}

if (! function_exists('hub_supplier_score_calc')) {
    /** درجة مورد ٠-١٠٠: التزام بالموعد وازناً الأكبر، مطروحاً منه أثر المرتجعات */
    function hub_supplier_score_calc(?int $onTimeRate, int $delivered, int $returned): ?int
    {
        if ($delivered === 0) return null;                    // لم يُسلَّم منه شيء بعد — لا درجة نخترعها
        $ret = $returned / $delivered;                        // نسبة المرتجع من المُسلَّم فعلاً
        $ontime = $onTimeRate ?? 60;                          // بلا مواعيد مسجّلة: محايد ٦٠
        $score = $ontime * 0.6 + (1 - $ret) * 100 * 0.4;

        return max(0, min(100, (int) round($score)));
    }
}

if (! function_exists('hub_kpi_metric')) {
    /**
     * قيمة مقياس واحد بأمان: count/sum/avg فوق جدول وحدة مسجّلة مع فلتر حالة
     * اختياري — كل المدخلات محقّقة ضد سجل الوحدة، لا نص حر يُقيَّم.
     */
    function hub_kpi_metric(array $m, $user = null): ?float
    {
        $mk = hub_str($m['module'] ?? '');
        $def = hub_mod($mk);
        if (! $def) return null;
        if ($user && ! hub_can($user, $mk, 'v')) return null;   // احترام الصلاحية

        $aggIn = hub_str($m['agg'] ?? '');
        $agg = in_array($aggIn, ['count', 'sum', 'avg'], true) ? $aggIn : 'count';
        $q = \Illuminate\Support\Facades\DB::table($def['table'])->whereNull('deleted_at');
        if ($user) $q = hub_scope($q, $mk, $user);

        // فلتر الحالة: عمود الحالة **الفيزيائي** لا مفتاحه — في وحدة الوثائق
        // المفتاح docStatus والعمود doc_status، فالفحص بالمفتاح كان يُسقط الفلتر بصمت
        if (($st = trim(hub_str($m['st'] ?? ''))) !== '' && ($skey = $def['status'] ?? null)) {
            $sfield = collect($def['fields'])->firstWhere('key', $skey);
            $scol = $sfield['col'] ?? $skey;
            if (\Illuminate\Support\Facades\Schema::hasColumn($def['table'], $scol)) $q->where($scol, $st);
        }

        if ($agg === 'count') return (float) $q->count();

        // sum/avg يتطلبان عموداً رقمياً من حقول الوحدة
        $col = collect($def['fields'])->firstWhere('key', hub_str($m['col'] ?? ''));
        if (! $col || ! in_array($col['type'], ['num', 'big'], true)) return null;

        // متوسط بلا صفوف = لا بيانات (null)، لا صفر مضلِّل
        if ($agg === 'avg') {
            $avg = $q->avg($col['col']);
            return $avg === null ? null : round((float) $avg, 4);
        }

        return (float) $q->sum($col['col']);
    }
}

if (! function_exists('hub_kpi_value')) {
    /** قيمة مؤشر مركّب من معادلته المُهيكَلة — يعيد null إن تعذّر الحساب */
    function hub_kpi_value(array $formula, $user = null): ?float
    {
        $a = isset($formula['a']) && is_array($formula['a']) ? hub_kpi_metric($formula['a'], $user) : null;
        if ($a === null) return null;

        $combine = $formula['combine'] ?? 'none';
        if ($combine === 'none') return round($a, 2);

        // المقياس الثاني تعذّر (عمود غير رقمي مثلاً): لا نُظهر المقياس الأول
        // خام تحت وحدة العملية — «—» أصدق من رقمٍ ليس ما وعدت به المعادلة
        $b = isset($formula['b']) && is_array($formula['b']) ? hub_kpi_metric($formula['b'], $user) : null;
        if ($b === null) return null;

        return match ($combine) {
            'ratio_pct' => $b != 0.0 ? round($a / $b * 100, 1) : null,
            'ratio'     => $b != 0.0 ? round($a / $b, 2) : null,
            'diff'      => round($a - $b, 2),
            'sum'       => round($a + $b, 2),
            default     => round($a, 2),
        };
    }
}

if (! function_exists('hub_kpi_explain')) {
    /**
     * المعادلة بالعربية. بطاقةٌ تقول «٤٥٪» ولا تقول **مِمَّ** حُسبت لا تُراجَع
     * ولا تُصحَّح: من يحذفها لا يدري ما يحذف، ومن يشكّ فيها لا يدري أين يشكّ.
     */
    function hub_kpi_explain(array $formula): string
    {
        // كل عضوٍ في المعادلة قد يصل **مصفوفةً** من استيرادٍ أو صفٍّ قديم:
        // `$arr['x']` على مصفوفة، و`(string) $arr` — كلاهما يرمي، وصفٌّ واحد
        // مشوّه كان يُعطّل `/kpis` واللوحة الرئيسية **لكل المستخدمين**.
        $one = function ($m) {
            if (! is_array($m)) return '—';
            $def = hub_mod(hub_str($m['module'] ?? ''));
            if (! $def) return 'وحدة غير معروفة';
            $aggKey = hub_str($m['agg'] ?? 'count');
            $agg = ['count' => 'عدد سجلات', 'sum' => 'مجموع', 'avg' => 'متوسط'][$aggKey] ?? 'عدد سجلات';
            $txt = $agg . ' ' . $def['label'];
            if ($aggKey !== 'count' && ($ck = hub_str($m['col'] ?? '')) !== '') {
                $col = collect($def['fields'])->firstWhere('key', $ck);
                $txt = $agg . ' «' . ($col['label'] ?? $ck) . '» في ' . $def['label'];
            }
            if (($st = trim(hub_str($m['st'] ?? ''))) !== '') $txt .= ' بحالة «' . $st . '»';

            return $txt;
        };

        $a = $one($formula['a'] ?? null);
        $combine = $formula['combine'] ?? 'none';
        if ($combine === 'none') return $a;

        $b = $one($formula['b'] ?? null);

        return match ($combine) {
            'ratio_pct' => "({$a}) ÷ ({$b}) × ١٠٠",
            'ratio'     => "({$a}) ÷ ({$b})",
            'diff'      => "({$a}) − ({$b})",
            'sum'       => "({$a}) + ({$b})",
            default     => $a,
        };
    }
}

if (! function_exists('hub_kpis')) {
    /**
     * كل المؤشرات المخصصة محسوبةً — لصفحة العرض.
     * `$withHidden` للباني وحده: اللوحات والملخّص لا ترى الموقوف.
     */
    function hub_kpis($user = null, bool $withHidden = false): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('kpi_defs')) return [];
        $user = $user ?? auth()->user();
        $hasActive = \Illuminate\Support\Facades\Schema::hasColumn('kpi_defs', 'active');

        return \App\Models\KpiDef::when($hasActive && ! $withHidden, fn ($q) => $q->where('active', true))
            ->orderBy('sort')->orderBy('created_at')->get()->map(function ($k) use ($user, $hasActive) {
                $val = hub_kpi_value((array) $k->formula, $user);
                $tone = '';
                if ($val !== null && $k->target !== null) {
                    $hit = ($k->good ?? 'up') === 'up' ? $val >= $k->target : $val <= $k->target;
                    $near = ($k->good ?? 'up') === 'up' ? $val >= $k->target * 0.8 : $val <= $k->target * 1.2;
                    $tone = $hit ? 'ok' : ($near ? 'wn' : 'bad');
                }

                return ['id' => $k->id, 'name' => $k->name, 'unit' => $k->unit,
                        'value' => $val, 'target' => $k->target, 'tone' => $tone,
                        'good' => $k->good, 'formula' => (array) $k->formula,
                        'active' => $hasActive ? (bool) $k->active : true,
                        'explain' => hub_kpi_explain((array) $k->formula)];
            })->all();
    }
}

if (! function_exists('hub_pending_migrations')) {
    /**
     * عدد ترحيلات القاعدة المعلقة (ملفات في الكود لم تُطبَّق بعد) — مخبّأ دقيقتين
     * كي لا يفحص كل طلب، ويُنسى فور تشغيل الترحيلات من مركز التشغيل.
     */
    function hub_pending_migrations(): int
    {
        return (int) Cache::remember('hub.pending_migrations', 120, function () {
            try {
                $ran = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();

                return collect(glob(database_path('migrations/*.php')))
                    ->map(fn ($f) => basename($f, '.php'))
                    ->reject(fn ($m) => in_array($m, $ran, true))
                    ->count();
            } catch (\Throwable $e) {
                return 0;   // تعذّر الفحص — لا نُقلق الواجهة، مركز التشغيل يكشف التفصيل
            }
        });
    }
}

// ─────────────────────────────────────────────────────────────────────────
// نواة المقاييس الزمنية — سلسلةٌ عامة لأي وحدةٍ وأي مقياس
// كل رقمٍ متحرّك كان لقطةً تُدهس بالتي بعدها: «المتابعون ١٢٠٠٠» بلا ذاكرةٍ
// لما كانوا أمس، فلا نمو ولا اتجاه ولا رسم. هذه المساعِدات هي السكة الوحيدة
// للكتابة والقراءة كي لا يتفرّق الحساب في الشاشات.
// ─────────────────────────────────────────────────────────────────────────

if (! function_exists('hub_metric_put')) {
    /**
     * تسجيل نقطة قياس. النقطة تُعرَّف بـ(وحدة، سجل، مقياس، لحظة) — فإعادة
     * الدفع بالقيمة نفسها أو بقيمةٍ مصحّحة **تُحدِّث** ولا تكرّر.
     */
    function hub_metric_put(string $module, string $recordId, string $metric, float $value,
                            $at = null, string $source = 'manual', array $meta = []): \App\Models\MetricPoint
    {
        // تطبيعُ المنطقة الزمنية لتوقيت النظام (Asia/Kuwait): النقطةُ تُعرَّف بلحظتها،
        // ف«2026-07-31T22:00:00Z» و«2026-08-01T01:00:00+03:00» لحظةٌ واحدة — بلا
        // التطبيع تُخزَّن بجدارِ ساعتها فتصير نقطتين، فتُكرَّر بدل أن تُحدَّث.
        $at = $at
            ? \Illuminate\Support\Carbon::parse($at)->setTimezone(config('app.timezone', 'Asia/Kuwait'))
            : now();

        // حزامُ أمانٍ للمسار الويبيّ أيضاً (Metrics::capture): العمود decimal(18,4)
        // يفيض على قيمةٍ ≥ 10¹⁴ أو غير منتهية — نُقصّها للمدى الآمن فلا 500 صامت
        if (! is_finite($value)) $value = 0.0;
        $value = max(-9999999999999.9999, min(9999999999999.9999, $value));

        return \App\Models\MetricPoint::updateOrCreate(
            ['module' => $module, 'record_id' => $recordId, 'metric' => $metric, 'at' => $at],
            ['value' => $value, 'source' => $source, 'meta' => $meta ?: null],
        );
    }
}

if (! function_exists('hub_metric_series')) {
    /** السلسلة الزمنية لمقياسٍ على سجل — الأقدم أولاً، جاهزةً للرسم */
    function hub_metric_series(string $module, string $recordId, string $metric, int $days = 90): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return [];

        return \App\Models\MetricPoint::where('module', $module)->where('record_id', $recordId)
            ->where('metric', $metric)->where('at', '>=', now()->subDays($days))
            ->orderBy('at')->get(['at', 'value', 'source'])
            ->map(fn ($p) => ['at' => $p->at, 'value' => (float) $p->value, 'source' => $p->source])->all();
    }
}

if (! function_exists('hub_metric_latest')) {
    /** آخر قيمة مسجّلة — والافتراضي `null` لا صفر: «لا قياس» ليس «صفراً» */
    function hub_metric_latest(string $module, string $recordId, string $metric): ?float
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return null;

        $p = \App\Models\MetricPoint::where('module', $module)->where('record_id', $recordId)
            ->where('metric', $metric)->orderByDesc('at')->first(['value']);

        return $p ? (float) $p->value : null;
    }
}

if (! function_exists('hub_metric_growth')) {
    /**
     * النمو خلال مدة: من أول نقطةٍ داخل المدة إلى آخرها.
     * `pct` تكون `null` إن كانت البداية صفراً — القسمة على صفر ليست «∞٪».
     */
    function hub_metric_growth(string $module, string $recordId, string $metric, int $days = 30): array
    {
        $s = hub_metric_series($module, $recordId, $metric, $days);
        $out = ['from' => null, 'to' => null, 'delta' => null, 'pct' => null,
                'points' => count($s), 'days' => $days, 'tone' => ''];
        if (! $s) return $out;

        $from = (float) $s[0]['value'];
        $to = (float) end($s)['value'];
        $delta = round($to - $from, 4);
        $out = array_merge($out, [
            'from' => $from, 'to' => $to, 'delta' => $delta,
            'pct' => $from != 0.0 ? round($delta * 100 / abs($from), 2) : null,
            'tone' => $delta > 0 ? 'ok' : ($delta < 0 ? 'bad' : ''),
        ]);

        return $out;
    }
}

if (! function_exists('hub_metric_spark')) {
    /** نِسبٌ مئوية جاهزة لرسم شريطٍ صغير — أعلى قيمةٍ = ١٠٠٪ */
    function hub_metric_spark(array $series, int $max = 30): array
    {
        $series = array_slice($series, -$max);
        $peak = max(1.0, max(array_map(fn ($p) => (float) $p['value'], $series ?: [['value' => 1]])));

        return array_map(fn ($p) => [
            'at' => $p['at'], 'value' => (float) $p['value'],
            'pct' => (int) round((float) $p['value'] * 100 / $peak),
        ], $series);
    }
}

if (! function_exists('hub_metric_bulk_series')) {
    /**
     * سلاسل مقياسٍ واحد لعدة سجلات **باستعلامٍ واحد**.
     * بدونها كانت كل بطاقةٍ في جدولٍ من ٨٠ صفاً تُطلق ~١٠ استعلامات (سلسلة
     * وآخر قيمة ونموّ لكل مقياس) — أي مئات الاستعلامات للصفحة الواحدة.
     */
    function hub_metric_bulk_series(string $module, array $ids, array $metrics, int $days = 30): array
    {
        $out = [];
        if (! $ids || ! $metrics || ! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return $out;

        $rows = \Illuminate\Support\Facades\DB::table('metric_points')
            ->where('module', $module)->whereIn('metric', $metrics)->whereIn('record_id', $ids)
            ->where('at', '>=', now()->subDays($days))
            ->orderBy('record_id')->orderBy('metric')->orderBy('at')
            ->get(['record_id', 'metric', 'value', 'at', 'source']);

        foreach ($rows as $r) {
            $out[$r->record_id][$r->metric][] = [
                'at' => \Illuminate\Support\Carbon::parse($r->at),
                'value' => (float) $r->value, 'source' => $r->source,
            ];
        }

        return $out;
    }
}

if (! function_exists('hub_metric_bulk_latest')) {
    /** آخر قيمة لمقياسٍ لعدة سجلات دفعةً — يمنع استعلاماً لكل صف في القوائم */
    function hub_metric_bulk_latest(string $module, array $ids, string $metric): array
    {
        if (! $ids || ! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return [];

        $rows = \Illuminate\Support\Facades\DB::table('metric_points')
            ->where('module', $module)->where('metric', $metric)->whereIn('record_id', $ids)
            ->orderBy('record_id')->orderBy('at')->get(['record_id', 'value']);

        $out = [];
        foreach ($rows as $r) $out[$r->record_id] = (float) $r->value;   // الأخير يغلب

        return $out;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// السوشال ميديا: مراقبةٌ وتحليل — لا دفتر جرد
// كانت الوحدة تخزّن أرقاماً خاماً بلا معدّل تفاعل ولا اتجاه ولا مقارنة.
// ─────────────────────────────────────────────────────────────────────────

if (! function_exists('hub_social_engagement')) {
    /**
     * معدّل التفاعل **محسوباً لا مُدخَلاً**: (إعجاب+تعليق+مشاركة+حفظ) ÷ قاعدة.
     * القاعدة أوّل متاحٍ من الوصول ثم الظهور ثم المشاهدات ثم متابعي الحساب —
     * فمنشورٌ بلا وصولٍ مسجَّل لا يُحرَم من نسبةٍ تقريبية، ويُصرَّح بأي قاعدةٍ حُسب.
     */
    function hub_social_engagement($post): array
    {
        $n = fn ($v) => (float) ($v ?? 0);
        $inter = $n($post->likes) + $n($post->comments2) + $n($post->shares) + $n($post->saves);

        $base = null;
        $label = '';
        foreach ([['reach', 'الوصول'], ['impr', 'الظهور'], ['views', 'المشاهدات']] as [$c, $l]) {
            if ($n($post->{$c}) > 0) { $base = $n($post->{$c}); $label = $l; break; }
        }
        if ($base === null && ($post->social_id ?? null)) {
            $f = \Illuminate\Support\Facades\DB::table('social_accounts')
                ->where('id', $post->social_id)->value('followers');
            if ((float) $f > 0) { $base = (float) $f; $label = 'المتابعون'; }
        }

        return [
            'interactions' => $inter,
            'base' => $base,
            'baseLabel' => $label,
            'rate' => $base ? round($inter * 100 / $base, 2) : null,
            'clicks' => $n($post->clicks),
            'spend' => $n($post->spend),
            'cpe' => ($n($post->spend) > 0 && $inter > 0) ? round($n($post->spend) / $inter, 3) : null,
        ];
    }
}

if (! function_exists('hub_social_feed_state')) {
    /**
     * حال التغذية لحسابٍ أو منشور: أهي آلية (n8n/API) أم لقطةٌ داخلية أم لا شيء.
     * «كله أوتو» يحتاج أولاً معرفة **ما ليس أوتو** — وإلا ظنّ المستخدم الرقم حيّاً وهو ميت.
     */
    function hub_social_feed_state(string $module, string $recordId, string $metric = 'followers'): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) {
            return ['mode' => 'none', 'label' => 'غير مربوط', 'tone' => 'wn', 'at' => null, 'source' => null];
        }

        $p = \App\Models\MetricPoint::where('module', $module)->where('record_id', $recordId)
            ->where('metric', $metric)->orderByDesc('at')->first(['at', 'source']);

        if (! $p) return ['mode' => 'none', 'label' => 'غير مربوط', 'tone' => 'wn', 'at' => null, 'source' => null];

        $auto = in_array($p->source, ['n8n', 'api', 'webhook'], true);
        $stale = $p->at->lt(now()->subDays(3));

        return [
            'mode' => $auto ? ($stale ? 'stale' : 'auto') : 'internal',
            'label' => $auto ? ($stale ? 'آلي لكنه متوقف' : 'آلي') : 'لقطة داخلية',
            'tone' => $auto ? ($stale ? 'bad' : 'ok') : 'wn',
            'at' => $p->at, 'source' => $p->source,
        ];
    }
}

if (! function_exists('hub_social_stats')) {
    /**
     * تحليل السوشال كاملاً — منطّقٌ بصلاحية المستخدم ونطاقه.
     * كل رقمٍ هنا مشتقٌّ من السلسلة الزمنية أو محسوب، لا حقلٌ يُملأ يدوياً.
     */
    function hub_social_stats(int $days = 30): array
    {
        $accounts = hub_scope(\App\Models\SocialAccount::query(), 'social')
            ->whereNull('deleted_at')->get();

        $posts = hub_can(auth()->user(), 'posts', 'v')
            ? hub_scope(\App\Models\SocialPost::query(), 'posts')->whereNull('deleted_at')->get()
            : collect();

        // ── الحسابات: المتابعون الآن ونموّهم من السلسلة ──
        $rows = $accounts->map(function ($a) use ($days, $posts) {
            $g = hub_metric_growth('social', $a->id, 'followers', $days);
            $series = hub_metric_series('social', $a->id, 'followers', $days);
            $mine = $posts->where('social_id', $a->id);
            $rates = $mine->map(fn ($p) => hub_social_engagement($p)['rate'])->filter(fn ($r) => $r !== null);

            return [
                'id' => $a->id, 'platform' => $a->platform, 'handle' => $a->handle,
                'url' => $a->url, 'status' => $a->status,
                'followers' => hub_metric_latest('social', $a->id, 'followers') ?? (float) ($a->followers ?? 0),
                'goal' => (float) ($a->goal ?? 0),
                'growth' => $g, 'spark' => hub_metric_spark($series),
                'posts' => $mine->count(),
                'published' => $mine->where('status', 'منشور')->count(),
                'rate' => $rates->count() ? round($rates->avg(), 2) : null,
                'feed' => hub_social_feed_state('social', $a->id),
            ];
        })->sortByDesc('followers')->values()->all();

        // ── المنشورات: التفاعل محسوباً ──
        $pub = $posts->where('status', 'منشور');
        $scored = $pub->map(function ($p) use ($accounts) {
            $e = hub_social_engagement($p);

            return ['id' => $p->id, 'title' => $p->title, 'type' => $p->type, 'pub_at' => $p->pub_at,
                    'account' => $accounts->firstWhere('id', $p->social_id)?->handle,
                    'reach' => (float) ($p->reach ?? 0)] + $e;
        })->sortByDesc('interactions')->values();

        // ── أداء أنواع المحتوى: أين يستحق الجهد ──
        $byType = $scored->filter(fn ($p) => $p['type'])->groupBy('type')
            ->map(fn ($g, $t) => [
                'type' => $t, 'n' => $g->count(),
                'rate' => $g->pluck('rate')->filter(fn ($r) => $r !== null)->avg(),
                'inter' => $g->sum('interactions'),
            ])->sortByDesc('rate')->values()->all();

        // ── أفضل وقت للنشر: متوسط التفاعل بحسب ساعة النشر ──
        $byHour = $scored->filter(fn ($p) => $p['pub_at'])->groupBy(fn ($p) => (int) $p['pub_at']->format('G'))
            ->map(fn ($g, $h) => ['hour' => (int) $h, 'n' => $g->count(), 'inter' => round($g->avg('interactions'))])
            ->sortByDesc('inter')->values()->all();

        $recent = $pub->filter(fn ($p) => $p->pub_at && $p->pub_at->gte(now()->subDays($days)));
        $rates = $scored->pluck('rate')->filter(fn ($r) => $r !== null);
        $totalNow = collect($rows)->sum('followers');
        $delta = collect($rows)->sum(fn ($r) => $r['growth']['delta'] ?? 0);

        return [
            'days' => $days,
            'accounts' => $rows,
            'top' => $scored->take(8)->all(),
            'byType' => $byType,
            'byHour' => array_slice($byHour, 0, 6),
            'kpi' => [
                'accounts' => count($rows),
                'followers' => $totalNow,
                'delta' => $delta,
                'pct' => ($totalNow - $delta) > 0 ? round($delta * 100 / ($totalNow - $delta), 1) : null,
                'posts' => $recent->count(),
                'reach' => (float) $recent->sum('reach'),
                'rate' => $rates->count() ? round($rates->avg(), 2) : null,
                'spend' => (float) $pub->sum('spend'),
                'unlinked' => collect($rows)->where('feed.mode', 'none')->count(),
                'stalled' => collect($rows)->where('feed.mode', 'stale')->count(),
            ],
        ];
    }
}

if (! function_exists('hub_app_store')) {
    /**
     * أداء التطبيق في المتاجر: الأرقام الحالية ونموّها من السلسلة الزمنية،
     * وحالُ تغذيتها. كانت الوحدة تخبرك برقم Build ولا تخبرك أَحيٌّ هو أم ميت.
     */
    function hub_app_store($app, int $days = 30, ?array $pre = null): array
    {
        // $pre: سلاسل مُجهَّزة من hub_metric_bulk_series — في القوائم يُمرَّر
        // فتُحسب البطاقة **بلا استعلامٍ واحد** بدل ~١٠ لكل صف.
        $ser = fn (string $m) => $pre !== null
            ? ($pre[$m] ?? [])
            : hub_metric_series('apps', $app->id, $m, $days);

        $last = fn (string $m) => ($x = $ser($m)) ? (float) end($x)['value'] : null;
        $now = fn (string $m, $col) => $last($m) ?? (($app->{$col} ?? null) !== null ? (float) $app->{$col} : null);

        $dl = $now('downloads', 'downloads');
        $rt = $now('rating', 'rating');
        $rv = $now('reviews', 'reviews');

        $gDl = hub_metric_growth_of($ser('downloads'), $days);
        $gRt = hub_metric_growth_of($ser('rating'), $days);
        $feed = hub_feed_state_of($ser('downloads')) ;
        if ($feed['mode'] === 'none') $feed = hub_feed_state_of($ser('rating'));

        return [
            'downloads' => $dl, 'rating' => $rt, 'reviews' => $rv,
            'growth' => $gDl, 'ratingGrowth' => $gRt,
            'spark' => hub_metric_spark($ser('downloads'), 40),
            'ratingSpark' => hub_metric_spark($ser('rating'), 40),
            'feed' => $feed,
            'auto' => (bool) ($app->auto_store ?? false),
            'days' => $days,
            // تقييمٌ دون ٣٫٥ في المتاجر يخنق التحميل — يُلوَّن لا يُدفَن في خانة
            'ratingTone' => $rt === null ? '' : ($rt >= 4.3 ? 'ok' : ($rt >= 3.5 ? 'wn' : 'bad')),
            'has' => $dl !== null || $rt !== null || $rv !== null,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// ملف الكيان: الوثائق المتعارف عليها لكل وحدة
// كانت المرفقات كومةً بلا نوع: تُرفع فلا يُعرف ما نقص، ولا ينبّه انتهاءُ
// سجلٍ تجاري أحداً. الآن للملف اكتمالٌ يُقاس ونواقصُ تُسمّى وانتهاءٌ يُرصد.
// ─────────────────────────────────────────────────────────────────────────

if (! function_exists('hub_doc_spec')) {
    /** قائمة الوثائق المتوقّعة لوحدة — فارغةٌ لمن لا ملفَّ لها */
    function hub_doc_spec(string $module): array
    {
        return (array) (config('hub_docs.' . $module) ?? []);
    }
}

if (! function_exists('hub_doc_label')) {
    /** اسم وثيقةٍ بمفتاحها — للعرض في الرادار والسجلات */
    function hub_doc_label(string $module, ?string $kind): ?string
    {
        if (! $kind) return null;

        return collect(hub_doc_spec($module))->firstWhere('key', $kind)['label'] ?? $kind;
    }
}

if (! function_exists('hub_dossier')) {
    /**
     * حال ملف سجلٍ واحد: لكل وثيقةٍ متوقّعة حالتها ونسخها وتاريخ انتهائها.
     * الحالات: مفقودة | سارية | تنتهي قريباً | منتهية | مرفوعة (بلا تاريخ).
     * الاكتمال يُحسب على **الإلزامية** وحدها — وإلا بدا الملف ناقصاً أبداً.
     */
    function hub_dossier(string $module, string $recordId): array
    {
        $spec = hub_doc_spec($module);
        $out = ['rows' => [], 'have' => 0, 'need' => 0, 'requiredMissing' => 0, 'pct' => 0,
                'expiring' => 0, 'expired' => 0, 'extra' => 0];
        if (! $spec) return $out;

        $files = collect();
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('attachments')) {
                $files = \App\Models\Attachment::whereNull('deleted_at')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->get();
            }
        } catch (\Throwable $e) {
        }

        $today = now()->startOfDay();
        foreach ($spec as $d) {
            $mine = $files->where('kind', $d['key']);
            $latest = $mine->sortByDesc(fn ($a) => $a->expires_at ?: $a->created_at)->first();
            $exp = $latest?->expires_at;
            $days = $exp ? (int) $today->diffInDays($exp->copy()->startOfDay(), false) : null;

            $state = 'مفقودة';
            $tone = ! empty($d['req']) ? 'bad' : 'wn';
            if ($mine->count()) {
                $out['have']++;
                if ($days === null)        { $state = 'مرفوعة'; $tone = 'ok'; }
                elseif ($days < 0)         { $state = 'منتهية'; $tone = 'bad'; $out['expired']++; }
                elseif ($days <= 30)       { $state = 'تنتهي قريباً'; $tone = 'wn'; $out['expiring']++; }
                else                       { $state = 'سارية'; $tone = 'ok'; }
            } elseif (! empty($d['req'])) {
                $out['requiredMissing']++;
            }
            if (! empty($d['req'])) $out['need']++;

            $out['rows'][] = [
                'key' => $d['key'], 'label' => $d['label'],
                'req' => (bool) ($d['req'] ?? false), 'multi' => (bool) ($d['multi'] ?? false),
                'expiry' => (bool) ($d['expiry'] ?? false), 'hint' => $d['hint'] ?? null,
                'n' => $mine->count(), 'files' => $mine->values()->all(),
                'latest' => $latest, 'expires' => $exp, 'days' => $days,
                'state' => $state, 'tone' => $tone,
            ];
        }

        // ملفاتٌ مرفوعة بلا نوعٍ معلن — تُحصى كي لا تُنسى في الكومة القديمة
        $out['extra'] = $files->filter(fn ($f) => ! $f->kind
            || ! collect($spec)->contains(fn ($d) => $d['key'] === $f->kind))->count();

        $haveReq = collect($out['rows'])->where('req', true)->filter(fn ($r) => $r['n'] > 0)->count();
        $out['pct'] = $out['need'] ? (int) round($haveReq * 100 / $out['need']) : ($out['have'] ? 100 : 0);
        $out['tone'] = $out['pct'] >= 100 ? 'ok' : ($out['pct'] >= 60 ? 'wn' : 'bad');

        return $out;
    }
}

if (! function_exists('hub_doc_expiry')) {
    /**
     * وثائق على وشك الانتهاء عبر كل الوحدات — تُضَمّ لرادار «ينتهي قريباً».
     * شهادةٌ منتهية أخطر من حقلٍ منتهٍ: بها يتوقف تعاملٌ أو يسقط ترخيص.
     */
    function hub_doc_expiry($user = null): array
    {
        $user = $user ?? auth()->user();
        if (! \Illuminate\Support\Facades\Schema::hasTable('attachments')) return [];
        if (! \Illuminate\Support\Facades\Schema::hasColumn('attachments', 'expires_at')) return [];

        // القصّ يقع **بعد** الترشيح بالنطاق لا قبله: سقفٌ من ٣٠٠ صفٍّ كان
        // يُستهلك بوثائق سجلاتٍ خارج نطاق القارئ فتُقصى وثائقه هو. السقف هنا
        // حارس ذاكرةٍ واسع، والحدّ الحقيقي (٢٠٠) يقع في hub_expiry بعد الترتيب.
        $rows = \App\Models\Attachment::whereNull('deleted_at')->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->subDays(60)->toDateString(), now()->addDays(60)->toDateString()])
            ->orderBy('expires_at')->limit(5000)->get(['module', 'record_id', 'kind', 'expires_at', 'doc_no']);

        $out = [];
        foreach ($rows->groupBy('module') as $mk => $group) {
            $md = hub_mod($mk);
            if (! $md || ! hub_can($user, $mk, 'v')) continue;   // لا يُسرّب الرادار ما لا يُرى
            try {
                $ids = hub_scope(\Illuminate\Support\Facades\DB::table($md['table'])->whereNull('deleted_at'), $mk, $user)
                    ->whereIn('id', $group->pluck('record_id')->unique()->all())
                    ->pluck(hub_display_col($mk), 'id');
            } catch (\Throwable $e) { continue; }

            foreach ($group as $a) {
                if (! isset($ids[$a->record_id])) continue;      // خارج نطاقه
                $d = $a->expires_at->toDateString();
                $out[] = [
                    'module' => $mk, 'mlabel' => $md['label'],
                    'flabel' => hub_doc_label($mk, $a->kind) ?? 'وثيقة',
                    // مميّزٌ ثابتٌ يفصل الوثيقةَ عن حقلِ السجل نفسِه (وعن وثيقةٍ بنوعٍ آخر):
                    // شهادةٌ منتهيةٌ وحقلُ نهايةٍ على العقد نفسِه لا يتقاسمان مفتاحَ إشارة.
                    'fkey' => 'doc:' . (string) $a->kind,
                    'id' => $a->record_id, 'name' => (string) $ids[$a->record_id],
                    'date' => $d, 'doc' => true,
                    'days' => (int) now()->startOfDay()->diffInDays($a->expires_at->copy()->startOfDay(), false),
                ];
            }
        }
        usort($out, fn ($x, $y) => $x['days'] <=> $y['days']);

        return $out;
    }
}

if (! function_exists('hub_expiry_bust')) {
    /** إبطال رادار «ينتهي قريباً» فوراً — يُستدعى عند تغيّر وثيقةٍ مؤرَّخة */
    function hub_expiry_bust(): void
    {
        Cache::forever('hub:expiry:gen', (int) Cache::get('hub:expiry:gen', 0) + 1);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// المراقبة الحيّة: هدفٌ خارجيّ يُفحَص، وتوافرٌ يُشتق من سلسلةٍ لا من انطباع
// ─────────────────────────────────────────────────────────────────────────

if (! function_exists('hub_outbound_ok')) {
    /**
     * حارس الطلبات الصادرة (SSRF): لا يصير النظام مِجَسّاً على شبكته الداخلية.
     * يُرفض غير http/https، والمضيف الذي يُحَلّ إلى عنوانٍ خاص أو محلي أو
     * link-local (169.254.169.254 بوابة بيانات السحابة) أو محجوز.
     * الإعداد `monitor.allow_private` يفتحها عمداً لتنصيبٍ داخلي مغلق.
     */
    function hub_outbound_ok(string $url): array
    {
        $no = fn (string $why) => ['ok' => false, 'why' => $why, 'ip' => null];

        $url = trim($url);
        $p = @parse_url($url);
        if (! $p || empty($p['scheme']) || empty($p['host'])) return $no('رابط غير صالح');
        if (! in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return $no('يُسمح بـ http/https فقط — لا ' . $p['scheme']);
        }

        $host = $p['host'];
        if (setting('monitor.allow_private')) return ['ok' => true, 'why' => '', 'ip' => null];

        // أسماءٌ محلية تُرفض قبل أي استعلام DNS
        if (preg_match('/^(localhost|.*\.localhost|.*\.internal|.*\.local)$/i', $host)) {
            return $no('مضيف داخلي: ' . $host);
        }

        // المُحلِّل قابلٌ للاستبدال (app('hub.dns')) كي تكون الاختبارات قاطعةً
        // بلا اعتمادٍ على DNS حيّ — والسلوك في الإنتاج كما هو.
        $resolve = app()->bound('hub.dns') ? app('hub.dns') : fn ($h) => @gethostbynamel($h);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($resolve($host) ?: []);
        if (! $ips) return $no('تعذّر تحليل اسم المضيف: ' . $host);

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) return $no('عنوان داخلي أو محجوز: ' . $ip);
            // 169.254.0.0/16 بوابة بيانات السحابة — يمر أحياناً من فلتر PHP
            if (str_starts_with($ip, '169.254.')) return $no('عنوان link-local: ' . $ip);
        }

        return ['ok' => true, 'why' => '', 'ip' => $ips[0]];
    }
}

if (! function_exists('hub_resolve_pin')) {
    /**
     * تثبيتُ اتصال curl على العنوان الذي أجازه `hub_outbound_ok`.
     *
     * الحارسُ يحلّ الاسمَ ويجيز عنوانه، لكنّ curl يُعيد التحليلَ وقت الاتصال —
     * فـDNS متقلّبٌ (rebinding) يردّ عنواناً عامّاً للفحص ثم داخليّاً للاتصال،
     * فيلتفّ حول الحارس (TOCTOU). `CURLOPT_RESOLVE` يربط `host:port` بالعنوان
     * المُجاز فلا يُعيد curl التحليل ولا يُبدّله DNS بين الفحص والاتصال.
     *
     * يُمرَّر عبر `Http::withOptions(['curl' => hub_resolve_pin($url, $ip)])`.
     * فارغٌ إن لا عنوان (السماح بالخاص، أو رابطٌ بلا مضيف) — فيُترك curl طبيعيّاً.
     */
    function hub_resolve_pin(string $url, ?string $ip): array
    {
        if (! $ip || ! defined('CURLOPT_RESOLVE')) return [];

        $p = @parse_url(trim($url));
        $host = $p['host'] ?? null;
        if (! $host) return [];

        $port = $p['port'] ?? (strtolower($p['scheme'] ?? 'http') === 'https' ? 443 : 80);

        return [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]];
    }
}

if (! function_exists('hub_uptime')) {
    /**
     * التوافر الحقيقي لهدفٍ من سلسلته: نسبة الفحوص الناجحة، ومتوسط زمن
     * الاستجابة، وآخر فحص. عتبات ٩٩٪ / ٩٥٪ — لا «يعمل/لا يعمل» عارية.
     */
    function hub_uptime(string $module, string $recordId, int $days = 30): array
    {
        $ups = hub_metric_series($module, $recordId, 'up', $days);
        $lat = hub_metric_series($module, $recordId, 'latency', $days);

        $n = count($ups);
        $good = count(array_filter($ups, fn ($p) => (float) $p['value'] > 0));
        $pct = $n ? round($good * 100 / $n, 2) : null;

        return [
            'checks' => $n, 'up' => $good, 'down' => $n - $good, 'pct' => $pct,
            'last' => $n ? end($ups) : null,
            'live' => $n ? ((float) end($ups)['value'] > 0) : null,
            'ms' => $lat ? (int) round(array_sum(array_column($lat, 'value')) / count($lat)) : null,
            'lastMs' => $lat ? (int) end($lat)['value'] : null,
            'spark' => hub_metric_spark($lat, 40),
            'days' => $days,
            'tone' => $pct === null ? '' : ($pct >= 99 ? 'ok' : ($pct >= 95 ? 'wn' : 'bad')),
            'label' => $pct === null ? 'غير مراقب' : ($pct >= 99 ? 'مستقر' : ($pct >= 95 ? 'متذبذب' : 'غير مستقر')),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// OKR: نسبةٌ تُحسب لا تُكتب
// كانت «نسبة الإنجاز» حقلاً يُملأ باليد على الهدف — وهذا ينقض OKR من أصله.
// ─────────────────────────────────────────────────────────────────────────

if (! function_exists('hub_kr_sources')) {
    /** مصادر قياس النتيجة الرئيسية — معلَنةً في مكانٍ واحد تقرؤه الواجهة والحساب */
    function hub_kr_sources(): array
    {
        return [
            'manual' => 'يدوي — أسجّل القيمة بنفسي',
            'count'  => 'عدّ سجلات وحدة',
            'sum'    => 'مجموع عمود في وحدة',
            'avg'    => 'متوسط عمود في وحدة',
            'kpi'    => 'مؤشر KPI محفوظ',
            'metric' => 'مقياس زمني على سجل',
        ];
    }
}

if (! function_exists('hub_kr_source')) {
    /**
     * مصدر النتيجة فعلياً. نتيجةٌ قديمة عليها `kpi_id` وحده (قبل وجود عمود
     * المصدر) تبقى آليةً كما كانت — الإضافة لا الكسر.
     */
    function hub_kr_source($kr): string
    {
        $src = trim((string) ($kr->source ?? ''));
        if ($src !== '') return $src;

        return $kr->kpi_id ? 'kpi' : 'manual';
    }
}

if (! function_exists('hub_kr_read')) {
    /**
     * قراءة القيمة الحالية لنتيجةٍ رئيسية من مصدرها.
     * تُعيد `null` للمصدر اليدوي أو لتعذّر القراءة — و«لا قراءة» ليست صفراً.
     * القراءة تمر بنطاق المستخدم وصلاحيته عبر hub_kpi_metric.
     */
    function hub_kr_read($kr, $user = null): ?float
    {
        // بلا مستخدم كانت hub_kpi_metric تتخطّى hub_scope و hub_can معاً،
        // فتُحسب القيمة فوق **كل** سجلات الوحدة وتُعرض لمن لا يراها.
        // (في سياق الطرفية والمجدول لا مستخدم — وذلك سياق نظامٍ مقصود.)
        $user = $user ?? auth()->user();
        $src = hub_kr_source($kr);
        if ($src === 'manual') return null;

        try {
            if ($src === 'kpi') {
                if (! $kr->kpi_id || ! \Illuminate\Support\Facades\Schema::hasTable('kpi_defs')) return null;
                $def = \App\Models\KpiDef::find($kr->kpi_id);
                if (! $def) return null;
                $f = is_array($def->formula) ? $def->formula : (json_decode((string) $def->formula, true) ?: []);

                return hub_kpi_value($f, $user);
            }

            if ($src === 'metric') {
                if (! $kr->src_module || ! $kr->src_record || ! $kr->src_metric) return null;

                return hub_metric_latest($kr->src_module, $kr->src_record, $kr->src_metric);
            }

            if (in_array($src, ['count', 'sum', 'avg'], true)) {
                if (! $kr->src_module) return null;

                return hub_kpi_metric([
                    'agg' => $src, 'module' => $kr->src_module,
                    'col' => $kr->src_col, 'st' => $kr->src_status,
                ], $user);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
}

if (! function_exists('hub_okr_pace')) {
    /**
     * الإيقاع: أين **يُفترض** أن نكون الآن من الفترة، مقابل أين نحن فعلاً.
     * روح OKR كلها هنا — «٣٠٪» وحدها لا تقول أمتقدّمون نحن أم متأخرون.
     */
    function hub_okr_pace($objective, ?int $pct): array
    {
        $out = ['expected' => null, 'gap' => null, 'pace' => '', 'tone' => '', 'daysLeft' => null];

        $start = $objective->date_start ? \Illuminate\Support\Carbon::parse($objective->date_start)->startOfDay() : null;
        $due = $objective->due ? \Illuminate\Support\Carbon::parse($objective->due)->startOfDay() : null;
        if (! $start || ! $due || $due->lte($start)) return $out;

        $total = max(1, $start->diffInDays($due));
        $gone = max(0, min($total, $start->diffInDays(now()->startOfDay())));
        $expected = (int) round($gone * 100 / $total);
        $out['expected'] = $expected;
        $out['daysLeft'] = (int) now()->startOfDay()->diffInDays($due, false);
        if ($pct === null) return $out;

        $gap = $pct - $expected;
        $out['gap'] = $gap;
        // هامش ٥٪ حول الخط: التقلّب اليومي ليس تعثّراً
        $out['pace'] = $gap >= 10 ? 'متقدّم' : ($gap >= -5 ? 'على المسار' : 'متأخر');
        $out['tone'] = $gap >= 10 ? 'ok' : ($gap >= -5 ? 'ok' : ($gap >= -20 ? 'wn' : 'bad'));
        if ($out['pace'] === 'متأخر' && $out['tone'] === 'ok') $out['tone'] = 'wn';

        return $out;
    }
}

if (! function_exists('hub_okr_board')) {
    /** لوحة OKR كاملةً — منطّقةً بصلاحية المستخدم ونطاقه */
    function hub_okr_board($user = null): array
    {
        $user = $user ?? auth()->user();
        $objs = hub_scope(\App\Models\Objective::query(), 'okrs')->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN status IN ('مكتمل','ملغى') THEN 1 ELSE 0 END")
            ->orderBy('due')->limit(120)->get();

        $rows = [];
        foreach ($objs as $o) {
            $p = hub_okr_progress($o->id, true, true);
            $rows[] = ['o' => $o, 'p' => $p];
        }

        $measured = array_filter($rows, fn ($r) => ($r['p']['pct'] ?? null) !== null);

        return [
            'rows' => $rows,
            'n' => count($rows),
            'measured' => count($measured),
            'unmeasured' => count($rows) - count($measured),
            'avg' => $measured ? (int) round(array_sum(array_map(fn ($r) => $r['p']['pct'], $measured)) / count($measured)) : null,
            'behind' => count(array_filter($measured, fn ($r) => ($r['p']['pace'] ?? '') === 'متأخر')),
            'ahead' => count(array_filter($measured, fn ($r) => ($r['p']['pace'] ?? '') === 'متقدّم')),
            'auto' => count(array_filter($rows, fn ($r) => collect($r['p']['krs'] ?? [])->contains('auto', true))),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// السياسات وقاعدة المعرفة: نصٌّ يُبلَّغ ويُقَرّ ويسقط إقراره بتحديث نسخته
// كانت تُحفظ ثم تُنسى: لا إبلاغ، ولا معرفةَ من قرأ، ولا أثرَ لتحديث النسخة.
// ─────────────────────────────────────────────────────────────────────────

/*
 * مفردات حالة الإقرار **واحدة** لا اثنتان: «بانتظار الإقرار» و«مُقرّة»
 * و«منتهية بتحديث النسخة» — وهي خيارات وحدة policyacks في سجل الوحدات نفسه،
 * وما يكتبه EsignController عند التوقيع. أي مفردةٍ موازية تجعل من وقّع
 * إلكترونياً يظهر في اللوحة تحت «لم يُقِر».
 */
if (! function_exists('hub_ack_modules')) {
    /** الوحدات التي تُقَرّ: مفتاحها ⟵ [عمود الإلزام، عمود النسخة، المسار] */
    function hub_ack_modules(): array
    {
        return [
            'policies' => ['col' => 'ack_required', 'ver' => 'ver', 'label' => 'سياسة'],
            'kb'       => ['col' => 'must_read',    'ver' => 'ver', 'label' => 'مقال معرفة'],
        ];
    }
}

if (! function_exists('hub_ack_targets')) {
    /**
     * من يلزمه الإقرار بهذا السجل: الجميع، أو أعضاء مشروعه، أو منسوبو شركته.
     * المستخدمون النشطون وحدهم — إقرارٌ من حسابٍ موقوف ليس إقراراً.
     */
    function hub_ack_targets(string $module, $row): array
    {
        $q = \App\Models\User::where('status', 'نشط');

        $pid = $row->project_id ?? null;
        $cid = $row->company_id ?? null;

        if ($pid && ($p = \App\Models\Project::find($pid))) {
            $members = array_values(array_filter(array_merge(
                (array) ($p->members ?? []), [$p->manager_id ?? null])));
            if ($members) $q->whereIn('id', $members);
        } elseif ($cid) {
            /*
             * **العضويةُ من حيث تُكتب لا من عمودٍ ميت** (v2.338): كان المُرشِّح
             * `users.company_id` — **ولا كاتبَ لهذا العمود في المستودع كلِّه**؛
             * عضويةُ الشركات تُخزَّن في `users.companies` (مصفوفة)، وهي التي
             * يقرؤها `hub_company_ids` وعليها يقوم العزلُ كلُّه.
             *
             * فسياسةٌ تُعلَن لشركةٍ كانت **لا تبلغ أحداً**، والشاشةُ تقول «أُعلنت
             * لِـ٠ شخص» فيُقرأ صفرُها «لا أحد معنيّ» لا «العمودُ ميت» — وإعلانُ
             * التزامٍ لا يصل مسؤوليةٌ لم تُنقَل وهي تبدو منقولة.
             *
             * والترشيحُ في PHP لا في SQL: المصفوفةُ JSON، ودوالُّها تختلف بين
             * MySQL وSQLite — والقائمةُ محدودةٌ بالمستخدمين النشطين فالكلفةُ لا
             * تُذكر. ومن لا قائمةَ شركاتٍ له (مالكٌ أو غيرُ معزول) معنيٌّ بالكل.
             */
            return $q->get(['id', 'companies', 'role_id'])
                ->filter(function ($u) use ($cid) {
                    $ids = hub_company_ids($u);

                    return $ids === null || in_array((string) $cid, $ids, true);
                })->pluck('id')->values()->all();
        }

        return $q->pluck('id')->all();
    }
}

if (! function_exists('hub_ack_announce')) {
    /**
     * إعلان السجل: يُفتح لكل مُخاطَبٍ **إقرارٌ معلّق** ويصله إشعار.
     * فتح الإقرار سلفاً هو الفرق بين «من لم يُقِر؟» المُجاب عنها وبين التخمين.
     */
    function hub_ack_announce(string $module, string $recordId): int
    {
        $spec = hub_ack_modules()[$module] ?? null;
        if (! $spec) return 0;

        $def = hub_mod($module);
        $class = '\\App\\Models\\' . $def['model'];
        // بالنطاق لا خاماً: الإعلانُ كتابةٌ وإشعاراتٌ على السجل — سجلُّ شركةٍ
        // أخرى ليس للمعزول أن يعلنه (كما يفرض AckController::target تماماً)
        $row = hub_scope($class::query(), $module)->find($recordId);
        if (! $row) return 0;

        $ver = (string) ($row->{$spec['ver']} ?? '') ?: '1.0';
        $title = (string) ($row->title ?? '');
        $n = 0;

        foreach (hub_ack_targets($module, $row) as $uid) {
            $ack = \App\Models\PolicyAck::firstOrNew([
                'src_module' => $module, 'record_id' => $recordId, 'user_id' => $uid, 'ver' => $ver,
            ]);
            if (! $ack->exists) {
                $ack->fill([
                    'title' => \Illuminate\Support\Str::limit($title, 200),
                    'policy_id' => $module === 'policies' ? $recordId : null,
                    'status' => 'بانتظار الإقرار',
                ])->save();
            }
            hub_notify($uid, 'policy',
                ($module === 'kb' ? 'قراءة إلزامية: ' : 'سياسة تحتاج إقرارك: ') . $title . ' (نسخة ' . $ver . ')',
                $module, $recordId);
            $n++;
        }

        try {
            $row->forceFill(['announced_at' => now()])->saveQuietly();
        } catch (\Throwable $e) {
        }

        return $n;
    }
}

if (! function_exists('hub_ack_state')) {
    /** حال الإقرار على سجل: من أقرّ ومن لم يُقِر — بالأسماء لا بالعدد وحده */
    function hub_ack_state(string $module, string $recordId): array
    {
        $spec = hub_ack_modules()[$module] ?? null;
        $def = hub_mod($module);
        $out = ['ver' => null, 'done' => 0, 'pending' => 0, 'total' => 0, 'pct' => 0,
                'doneRows' => [], 'pendingRows' => [], 'required' => false];
        if (! $spec || ! $def) return $out;

        $class = '\\App\\Models\\' . $def['model'];
        $row = hub_scope($class::query(), $module)->find($recordId);   // بالنطاق لا خاماً
        if (! $row) return $out;

        $ver = (string) ($row->{$spec['ver']} ?? '') ?: '1.0';
        $out['ver'] = $ver;
        $out['required'] = (bool) ($row->{$spec['col']} ?? false);

        $acks = \App\Models\PolicyAck::whereNull('deleted_at')
            ->where(fn ($q) => $q->where('record_id', $recordId)
                ->orWhere(fn ($w) => $w->whereNull('record_id')->where('policy_id', $recordId)))
            ->where('ver', $ver)->get();

        $names = \App\Models\User::whereIn('id', $acks->pluck('user_id')->filter()->all())
            ->pluck('name', 'id');

        foreach ($acks as $a) {
            $entry = ['id' => $a->id, 'user' => $names[$a->user_id] ?? '—',
                      'at' => $a->ack_at, 'signed' => (bool) $a->sign_request_id];
            if ((string) $a->status === 'مُقرّة') $out['doneRows'][] = $entry;
            elseif ((string) $a->status !== 'منتهية بتحديث النسخة') $out['pendingRows'][] = $entry;
        }

        $out['done'] = count($out['doneRows']);
        $out['pending'] = count($out['pendingRows']);
        $out['total'] = $out['done'] + $out['pending'];
        $out['pct'] = $out['total'] ? (int) round($out['done'] * 100 / $out['total']) : 0;
        $out['tone'] = $out['pct'] >= 100 ? 'ok' : ($out['pct'] >= 60 ? 'wn' : 'bad');

        return $out;
    }
}

if (! function_exists('hub_ack_do')) {
    /** تسجيل إقرار المستخدم الحالي بنسخة السجل — مع دليله: وقتٌ وعنوانٌ وجهاز */
    function hub_ack_do(string $module, string $recordId, ?string $signRequestId = null): ?\App\Models\PolicyAck
    {
        $spec = hub_ack_modules()[$module] ?? null;
        $def = hub_mod($module);
        if (! $spec || ! $def) return null;

        $class = '\\App\\Models\\' . $def['model'];
        $row = hub_scope($class::query(), $module)->find($recordId);   // بالنطاق لا خاماً
        if (! $row) return null;

        $ver = (string) ($row->{$spec['ver']} ?? '') ?: '1.0';
        $ack = \App\Models\PolicyAck::firstOrNew([
            'src_module' => $module, 'record_id' => $recordId,
            'user_id' => auth()->id(), 'ver' => $ver,
        ]);
        $ack->fill([
            'title' => \Illuminate\Support\Str::limit((string) ($row->title ?? ''), 200),
            'policy_id' => $module === 'policies' ? $recordId : ($ack->policy_id ?? null),
            'status' => 'مُقرّة', 'ack_at' => now(),
            'ip' => hub_fit((string) request()->ip(), 60),
            'device' => hub_fit((string) request()->userAgent(), 200),
            'sign_request_id' => $signRequestId ?: $ack->sign_request_id,
        ])->save();

        hub_audit('إقرار ' . $spec['label'], $module, $recordId, 'نسخة ' . $ver);

        return $ack;
    }
}

if (! function_exists('hub_ack_reset')) {
    /**
     * تحديث النسخة يُسقط الإقرارات السابقة ويُعيد الإعلان.
     * بلا هذا يبقى الجميع «مُقِرّين» بنسخةٍ ماتت — وهو أسوأ من ألّا يُقِرّ أحد،
     * لأنه امتثالٌ ورقيٌّ كاذب.
     */
    function hub_ack_reset(string $module, string $recordId, string $currentVer): int
    {
        // نُسقط **كل ما ليس النسخة الحالية** لا نسخةً بعينها: بذلك يعمل الإسقاط
        // أياً كان مصدر التغيير (واجهة، API، استيراد، أمر طرفية) ولا يعتمد على
        // التقاط القيمة القديمة قبل الحفظ — وهو ما كان يفلت صامتاً.
        $n = \App\Models\PolicyAck::whereNull('deleted_at')
            ->where(fn ($q) => $q->where('record_id', $recordId)
                ->orWhere(fn ($w) => $w->whereNull('record_id')->where('policy_id', $recordId)))
            ->where(fn ($q) => $q->where('ver', '!=', $currentVer)->orWhereNull('ver'))
            ->whereIn('status', ['بانتظار الإقرار', 'مُقرّة'])
            ->update(['status' => 'منتهية بتحديث النسخة']);

        hub_ack_announce($module, $recordId);

        return (int) $n;
    }
}

if (! function_exists('hub_ack_board')) {
    /** لوحة الإقرارات: كل سجلٍ يلزمه إقرار بحاله — منطّقاً بصلاحية الرؤية */
    function hub_ack_board($user = null): array
    {
        $user = $user ?? auth()->user();
        $out = [];
        foreach (hub_ack_modules() as $mk => $spec) {
            if (! hub_can($user, $mk, 'v')) continue;
            $def = hub_mod($mk);
            $class = '\\App\\Models\\' . $def['model'];
            $rows = hub_scope($class::query(), $mk, $user)->whereNull('deleted_at')
                ->where($spec['col'], true)->orderByDesc('created_at')->limit(60)->get();
            foreach ($rows as $r) {
                $out[] = ['module' => $mk, 'label' => $spec['label'], 'row' => $r,
                          'state' => hub_ack_state($mk, $r->id)];
            }
        }
        usort($out, fn ($a, $b) => $a['state']['pct'] <=> $b['state']['pct']);

        return $out;
    }
}

if (! function_exists('hub_metric_growth_of')) {
    /** النمو محسوباً من سلسلةٍ **بين يديك** — بلا استعلامٍ جديد */
    function hub_metric_growth_of(array $series, int $days = 30): array
    {
        $out = ['from' => null, 'to' => null, 'delta' => null, 'pct' => null,
                'points' => count($series), 'days' => $days, 'tone' => ''];
        if (! $series) return $out;

        $from = (float) $series[0]['value'];
        $to = (float) end($series)['value'];
        $delta = round($to - $from, 4);

        return array_merge($out, [
            'from' => $from, 'to' => $to, 'delta' => $delta,
            'pct' => $from != 0.0 ? round($delta * 100 / abs($from), 2) : null,
            'tone' => $delta > 0 ? 'ok' : ($delta < 0 ? 'bad' : ''),
        ]);
    }
}

if (! function_exists('hub_feed_state_of')) {
    /** حال التغذية من سلسلةٍ بين يديك — نظير hub_social_feed_state بلا استعلام */
    function hub_feed_state_of(array $series): array
    {
        if (! $series) return ['mode' => 'none', 'label' => 'غير مربوط', 'tone' => 'wn', 'at' => null, 'source' => null];

        $last = end($series);
        $auto = in_array($last['source'] ?? '', ['n8n', 'api', 'webhook'], true);
        $stale = $last['at']->lt(now()->subDays(3));

        return [
            'mode' => $auto ? ($stale ? 'stale' : 'auto') : 'internal',
            'label' => $auto ? ($stale ? 'آلي لكنه متوقف' : 'آلي') : 'لقطة داخلية',
            'tone' => $auto ? ($stale ? 'bad' : 'ok') : 'wn',
            'at' => $last['at'], 'source' => $last['source'] ?? null,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// عدسة المشروع: مُرشِّحٌ واحد يمرّ بكل اللوحات التحليلية
// كان كل مركزٍ يعرض المنشأة كلها مجموعةً — فلا يُعرف نصيب مشروعٍ بعينه من
// القدرات ولا من التوصيات ولا من الأثر. عدسةٌ واحدة تخدمها كلها، وأي مركزٍ
// جديد يرثها مجاناً.
// ─────────────────────────────────────────────────────────────────────────

if (! function_exists('hub_lens_path')) {
    /**
     * كيف يُوصَل من وحدةٍ إلى مشروعها: مباشرةً بعمود، أو بقفزةٍ واحدة، أو لا طريق.
     * ٦٤ وحدة من ٧٣ مباشرة، واثنتان بقفزة، وسبعٌ لا علاقة لها بمشروع أصلاً —
     * وهذه الأخيرة **تُقال صراحةً** بدل أن تعرض كل شيء وكأن المُرشِّح طُبّق.
     */
    function hub_lens_path(string $module): array
    {
        static $memo = [];
        if (isset($memo[$module])) return $memo[$module];

        $def = hub_mod($module);
        $t = $def['table'] ?? null;
        if (! $t || ! \Illuminate\Support\Facades\Schema::hasTable($t)) {
            return $memo[$module] = ['mode' => 'none'];
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn($t, 'project_id')) {
            return $memo[$module] = ['mode' => 'direct', 'col' => 'project_id'];
        }

        // قفزةٌ واحدة عبر مرجعٍ يحمل المشروع
        foreach (['app_id' => 'applications', 'emp_id' => 'employees', 'client_id' => 'clients',
                  'contract_id' => 'contracts', 'asset_id' => 'assets', 'objective_id' => 'objectives',
                  'social_id' => 'social_accounts', 'policy_id' => 'policies'] as $c => $tt) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($t, $c)
                && \Illuminate\Support\Facades\Schema::hasTable($tt)
                && \Illuminate\Support\Facades\Schema::hasColumn($tt, 'project_id')) {
                return $memo[$module] = ['mode' => 'via', 'col' => $c, 'table' => $tt];
            }
        }

        return $memo[$module] = ['mode' => 'none'];
    }
}

if (! function_exists('hub_lens')) {
    /**
     * العدسة النشطة من الرابط (`?p=`) — **مُتحقَّقاً من نطاقها**: لا يفتح
     * المستخدم عدسةً على مشروعٍ لا يراه، فالمُرشِّح ليس باباً خلفياً.
     */
    function hub_lens($user = null): array
    {
        $user = $user ?? auth()->user();
        $out = ['id' => null, 'name' => null, 'on' => false];

        $pid = trim(hub_str(request()->query('p', '')));
        if ($pid === '' || ! $user) return $out;

        $p = hub_scope(\App\Models\Project::query(), 'projects', $user)
            ->whereNull('deleted_at')->whereKey($pid)->first(['id', 'name']);
        if (! $p) return $out;      // خارج نطاقه — العدسة لا تُفتح ولا يُنبَّه على وجوده

        return ['id' => $p->id, 'name' => $p->name, 'on' => true];
    }
}

if (! function_exists('hub_lens_apply')) {
    /** تطبيق العدسة على استعلام وحدةٍ ما — بلا عدسةٍ يعود الاستعلام كما هو */
    function hub_lens_apply($q, string $module, ?string $projectId)
    {
        if (! $projectId) return $q;
        $path = hub_lens_path($module);

        if ($path['mode'] === 'direct') return $q->where($path['col'], $projectId);
        if ($path['mode'] === 'via') {
            return $q->whereIn($path['col'], function ($sub) use ($path, $projectId) {
                $sub->select('id')->from($path['table'])->where('project_id', $projectId);
            });
        }

        return $q;   // لا طريق — المسؤولية على الشاشة أن تقول ذلك لا أن تُخفي
    }
}

if (! function_exists('hub_lens_projects')) {
    /** مشاريع المستخدم لقائمة اختيار العدسة — المفتوحة أولاً */
    function hub_lens_projects($user = null): array
    {
        $user = $user ?? auth()->user();

        return hub_scope(\App\Models\Project::query(), 'projects', $user)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN status IN ('مكتمل','ملغى') THEN 1 ELSE 0 END")
            ->orderBy('name')->limit(300)->pluck('name', 'id')->all();
    }
}

if (! function_exists('hub_lens_key')) {
    /**
     * لاحقة مفتاح التخبئة للعدسة. مفتاحٌ لا يحمل المشروع يُقدّم نتيجة مشروعٍ
     * لآخر — وهو عيبٌ وقع في هذا المستودع من قبل، فلا يتكرر.
     */
    function hub_lens_key(?string $projectId): string
    {
        return $projectId ? ':p:' . $projectId : '';
    }
}

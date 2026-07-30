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
            if (str_contains($rule, '/')) {
                [$net, $bits] = explode('/', $rule);
                $mask = -1 << (32 - (int) $bits);
                if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) return true;
            } elseif ($ip === $rule || str_starts_with($ip, rtrim($rule, '*'))) {
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

        return $q;
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

        $all = [
            ['key' => 'morning',   'label' => '☀️ تشغيل اليوم',      'route' => 'morning',         'ok' => true],
            ['key' => 'me',        'label' => '👤 بوابتي',           'route' => 'portal.me',       'ok' => true],
            ['key' => 'feed',      'label' => '📣 قناة الفريق',      'route' => 'feed',            'ok' => true],
            ['key' => 'dm',        'label' => '💬 الرسائل',          'route' => 'dm.inbox',        'ok' => true],
            ['key' => 'inboxdocs', 'label' => '📥 صندوق الوثائق',    'route' => 'inboxdocs.index', 'ok' => true],
            ['key' => 'alerts',    'label' => '🔔 ينتهي قريباً',     'route' => 'alerts',          'ok' => true],
            ['key' => 'calendar',  'label' => '📅 التقويم',          'route' => 'calendar',        'ok' => true],
            ['key' => 'finrep',    'label' => '📊 التقارير المالية', 'route' => 'reports.finance', 'ok' => hub_can($user, 'fin', 'v')],
            ['key' => 'costs',     'label' => '💰 التكاليف والربحية', 'route' => 'costs.index',    'ok' => $mon],
            ['key' => 'svccosts',  'label' => '🧮 تكلفة الخدمات',    'route' => 'servicecosts',    'ok' => $mon],
            ['key' => 'recs',      'label' => '💡 مركز التوصيات',    'route' => 'recs',            'ok' => $mon],
            ['key' => 'kpis',      'label' => '📈 مؤشرات KPI',       'route' => 'kpis.index',      'ok' => $mon],
            ['key' => 'innov',     'label' => '💡 مركز الابتكار',    'route' => 'innovation',      'ok' => hub_can($user, 'ideas', 'v')],
            ['key' => 'supscores', 'label' => '🏅 تقييم الموردين',   'route' => 'supplierscores',  'ok' => hub_can($user, 'suppliers', 'v')],
            ['key' => 'capacity',  'label' => '📊 القدرات والموارد', 'route' => 'capacity',        'ok' => $mon],
            ['key' => 'impact',    'label' => '🕸️ خريطة الأثر',      'route' => 'impact',          'ok' => $mon],
            ['key' => 'appq',      'label' => '🧪 جودة البرمجيات',   'route' => 'appquality',      'ok' => $mon],
            ['key' => 'legal',     'label' => '⚖️ القانوني',         'route' => 'legal',           'ok' => hub_can($user, 'contracts', 'v')],
            ['key' => 'support',   'label' => '🎫 لوحة الدعم',       'route' => 'support',         'ok' => hub_can($user, 'tickets', 'v')],
            ['key' => 'ceo',       'label' => '👑 لوحة CEO',         'route' => 'ceo',             'ok' => $owner],
            ['key' => 'perf',      'label' => '📈 لوحة الأداء',      'route' => 'performance',     'ok' => $mon],
        ];

        return array_values(array_filter($all, fn ($l) => $l['ok']));
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
        foreach (['مكتمل', 'منجز', 'نشط', 'مدفوع', 'مقبول', 'موافق', 'ناجح', 'مغلق', 'تم'] as $w) if (str_contains($v, $w)) return 'ok';
        foreach (['متأخر', 'ملغ', 'مرفوض', 'موقوف', 'فشل', 'حرج', 'منته'] as $w) if (str_contains($v, $w)) return 'bad';
        foreach (['قيد', 'انتظار', 'جديد', 'مسود', 'معلق', 'مراجعة', 'تجريب'] as $w) if (str_contains($v, $w)) return 'wn';
        return 'g';
    }
}

if (! function_exists('hub_children')) {
    /** الوحدات التي تشير إلى هذه الوحدة بحقل مرجعي: [[moduleKey, field], …] */
    function hub_children(string $module): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (hub_modules() as $ck => $cd) {
                foreach ($cd['fields'] as $f) {
                    if (($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') !== '' && empty($f['multi'])) {
                        $map[$f['ref']][] = [$ck, $f];
                    }
                }
            }
        }
        return $map[$module] ?? [];
    }
}

if (! function_exists('hub_expiry_fields')) {
    /** حقول التواريخ المصيرية عبر كل الوحدات (انتهاء/تجديد/استحقاق) */
    function hub_expiry_fields(): array
    {
        static $out = null;
        if ($out !== null) return $out;
        $out = [];
        foreach (hub_modules() as $mk => $md) {
            foreach ($md['fields'] as $f) {
                $isDate = in_array($f['type'], ['date', 'dt'], true);
                $byKey  = in_array($f['key'], ['end', 'due', 'expiry', 'expires', 'renew', 'renewal', 'warranty'], true)
                          || preg_match('/exp$|Exp$/', $f['key']);
                $byLbl  = preg_match('/انتها|تجديد|استحقاق|نهاية|ضمان/u', $f['label']);
                if ($isDate && ($byKey || $byLbl)) $out[] = [$mk, $f];
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
        // مقيد = نطاق مشاريع أو عزل شركات — مخبأ خاص به كي لا تتسرب أسماء أجنبية عبر المخبأ المشترك
        $scoped = hub_scoped($user) || hub_company_ids($user) !== null;
        $key    = $scoped ? 'hub:expiry:u:' . $user->id : 'hub:expiry';
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, $scoped ? 300 : 600, function () use ($scoped, $user) {
            $today = now()->toDateString();
            $limit = now()->addDays(30)->toDateString();
            $items = [];
            foreach (hub_expiry_fields() as [$mk, $f]) {
                $md = hub_mod($mk);
                $disp = hub_display_col($mk);
                try {
                    $q = \Illuminate\Support\Facades\DB::table($md['table'])
                        ->whereNull('deleted_at')
                        ->whereNotNull($f['col'])
                        ->whereBetween(\Illuminate\Support\Facades\DB::raw("DATE(`{$f['col']}`)"), [now()->subDays(60)->toDateString(), $limit]);
                    if ($scoped) $q = hub_scope($q, $mk, $user);

                    // لا تنبيه على ما أُغلق: مهمة منجزة أو فاتورة مدفوعة أو عقد منتهٍ
                    // كانت تظل تنبّه إلى الأبد فتفقد الصفحة مصداقيتها.
                    if (($sc = $md['status'] ?? null) && \Illuminate\Support\Facades\Schema::hasColumn($md['table'], $sc)) {
                        $q->where(fn ($w) => $w->whereNull($sc)->orWhereNotIn($sc, hub_closed_states()));
                    }

                    // مستند مالي سُدّد بالكامل لا يستحق تنبيه استحقاق ولو لم تُحدَّث حالته:
                    // السداد المسجَّل هو الحقيقة، لا التسمية.
                    if ($mk === 'fin' && \Illuminate\Support\Facades\Schema::hasColumn($md['table'], 'paid')) {
                        $q->whereRaw('COALESCE(paid,0) < COALESCE(total,0)');
                    }

                    // الترتيب تصاعدياً بالتاريخ قبل الحد: نُبقي الأربعين الأقرب/الأكثر تأخراً
                    // لا أربعين عشوائية بترتيب القاعدة (كان يُسقط أعجل السجلات صمتاً).
                    $rows = $q->orderBy(\Illuminate\Support\Facades\DB::raw("DATE(`{$f['col']}`)"))
                        ->limit(40)->get(['id', $disp . ' as _n', $f['col'] . ' as _d']);
                } catch (\Throwable $e) { continue; }
                foreach ($rows as $row) {
                    $d = substr((string) $row->_d, 0, 10);
                    $days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($d)->startOfDay(), false);
                    $items[] = ['module' => $mk, 'mlabel' => $md['label'], 'flabel' => $f['label'],
                                'id' => $row->id, 'name' => (string) $row->_n, 'date' => $d, 'days' => $days];
                }
            }
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
            $firstReply = \App\Models\Comment::where('module', 'tickets')->where('record_id', $t->id)
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
                $cLate = (clone $c)->whereNotNull('end')->where('end', '<', $today)->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%منته%'))->count();
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
                $score = 100 - ($sn2 ? ($sLate / $sn2) * 30 : 0) - min(30, $ssl * 10) - min(40, $crit * 10);
                $out['البنية التحتية'] = ['score' => $clamp($score), 'note' => "{$sLate} سيرفر منتهٍ · {$ssl} شهادة SSL منتهية · {$crit} عطل حرج مفتوح"];
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

if (! function_exists('hub_approvers')) {
    /** المعتمدون: المالكون + حاملو علم approve */
    function hub_approvers(): array
    {
        return \App\Models\User::whereNull('deleted_at')->with('role')->get()
            ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'approve'))
            ->pluck('id')->values()->all();
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
        abort_if(hub_company_ids() !== null, 403, 'هذه اللوحة على مستوى المنشأة كلها — غير متاحة لحسابٍ معزول على شركات محددة');
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
            $subs = \Illuminate\Support\Facades\DB::table('subscriptions')
                ->whereNull('deleted_at')->where('project_id', $projectId)->get(['amount', 'cycle']);
            $toolCost = 0.0;
            foreach ($subs as $s) $toolCost += $norm($s->amount, $s->cycle) * $months + $oneOff($s->amount, $s->cycle);

            // ── ٤) الخدمات الخارجية: مشتريات + مصروفات مالية مرتبطة بالمشروع ──
            $purch = (float) \Illuminate\Support\Facades\DB::table('purchases')
                ->whereNull('deleted_at')->where('project_id', $projectId)->sum('amount');
            $expense = (float) \Illuminate\Support\Facades\DB::table('fin_documents')
                ->whereNull('deleted_at')->where('project_id', $projectId)
                ->where('kind', 'مصروف')->sum('total');
            $externalCost = $purch + $expense;

            // ── الإيراد: مفوتر ومحصّل ──
            $inv = \Illuminate\Support\Facades\DB::table('fin_documents')
                ->whereNull('deleted_at')->where('project_id', $projectId)->where('kind', 'فاتورة')
                ->selectRaw('COALESCE(SUM(total),0) t, COALESCE(SUM(paid),0) p, COUNT(*) n')->first();

            $revenue   = (float) ($inv->t ?? 0);
            $collected = (float) ($inv->p ?? 0);
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
                'currency' => $p->currency ?: (string) setting('fin.currency', 'د.ك'),
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

            // ٤) المخاطر المفتوحة
            $iss = \Illuminate\Support\Facades\DB::table('issues')->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNotIn('status', ['مغلقة', 'محلولة', 'ملغاة'])->count();
            $f[] = ['k' => 'المخاطر المفتوحة', 'w' => 15,
                    's' => max(0, 100 - $iss * 15), 'note' => $iss ? "{$iss} مخاطرة مفتوحة" : 'لا مخاطر مفتوحة'];

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
                 'مدفوع', 'منتهٍ'];

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
        foreach (hub_modules() as $md) {
            $sc = $md['status'] ?? null;
            if (! $sc) continue;
            foreach ($md['fields'] as $f) {
                if (($f['col'] ?? '') === $sc && ! empty($f['options'])) {
                    foreach ($f['options'] as $o) $all[] = $o;
                }
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

if (! function_exists('hub_fin_sum')) {
    /** مجموع مستندات مالية من أنواع بعينها منذ تاريخ — يستثني الملغاة والمسودات دائماً */
    function hub_fin_sum(array $kinds, ?string $from = null, string $col = 'total'): float
    {
        $q = \Illuminate\Support\Facades\DB::table('fin_documents')->whereNull('deleted_at')
            ->whereNotIn('state', config('hub.fin.dead'))
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

            $base = hub_scope($cc::where($cf['col'], $recordId), $ck);
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
    function hub_capacity(?string $from = null, ?string $to = null): array
    {
        $f = \Illuminate\Support\Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay();
        $t = \Illuminate\Support\Carbon::parse($to ?: now()->endOfMonth()->toDateString())->endOfDay();

        $hoursDay = max(1, (int) setting('cost.work_hours', 8));
        $workDays = hub_workdays($f, $t);
        $DB = \Illuminate\Support\Facades\DB::class;

        $emps = \Illuminate\Support\Facades\DB::table('employees')->whereNull('deleted_at')
            ->whereNotIn('status', ['منتهية خدمته', 'مستقيل', 'موقوف'])
            ->orderBy('name')->limit(300)->get(['id', 'name', 'dept', 'user_id']);
        if ($emps->isEmpty()) return ['rows' => [], 'from' => $f->toDateString(), 'to' => $t->toDateString(),
                                      'workDays' => $workDays, 'hoursDay' => $hoursDay, 'totals' => []];

        $userIds = $emps->pluck('user_id')->filter()->all();
        $empIds  = $emps->pluck('id')->all();

        // إجازات معتمدة متقاطعة مع الفترة
        $leaves = \Illuminate\Support\Facades\DB::table('leave_requests')->whereNull('deleted_at')
            ->where('status', 'معتمدة')->whereIn('emp_id', $empIds)
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
            ->whereIn('assignee_id', $userIds)->whereNotIn('status', $open)
            ->where(fn ($q) => $q->whereNull('due')->orWhereBetween('due', [$f->toDateString(), $t->toDateString()]))
            ->selectRaw('assignee_id, COALESCE(SUM(est_h),0) h, COUNT(*) n')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        // المسجَّل: ساعات فعلية على مهام حُدِّثت داخل الفترة
        $logged = $userIds ? \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
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
            ->whereIn('assignee_id', $userIds)->whereNotIn('status', $open)->whereNotNull('project_id')
            ->selectRaw('assignee_id, COUNT(DISTINCT project_id) n')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        $rows = [];
        foreach ($emps as $e) {
            $lv  = (int) ($leaveDays[$e->id] ?? 0);
            $avail = max(0, ($workDays - $lv) * $hoursDay);
            $bk  = (float) ($booked[$e->user_id]->h ?? 0);
            $lg  = max((float) ($logged[$e->user_id]->h ?? 0), (float) ($att[$e->id]->h ?? 0));

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
            'totals' => [
                'available' => array_sum(array_column($rows, 'available')),
                'booked'    => round(array_sum(array_column($rows, 'booked')), 1),
                'logged'    => round(array_sum(array_column($rows, 'logged')), 1),
                'over'      => count(array_filter($rows, fn ($r) => ($r['load'] ?? 0) > 100)),
                'idle'      => count(array_filter($rows, fn ($r) => $r['available'] > 0 && ($r['load'] ?? 0) < 50)),
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
    function hub_app_quality(bool $fresh = false): array
    {
        if ($fresh) \Illuminate\Support\Facades\Cache::forget('quality:apps');

        return \Illuminate\Support\Facades\Cache::remember('quality:apps', 300, function () {
            $DB = \Illuminate\Support\Facades\DB::class;
            $closed = hub_closed_states();
            $apps = hub_scope(\Illuminate\Support\Facades\DB::table('applications')->whereNull('deleted_at'), 'apps')
                ->orderBy('name')->limit(80)->get(['id', 'name', 'ver', 'status', 'project_id']);
            if ($apps->isEmpty()) return [];

            $ids = $apps->pluck('id')->all();
            $pids = $apps->pluck('project_id')->filter()->all();

            $iss = \Illuminate\Support\Facades\DB::table('issues')->whereNull('deleted_at')
                ->whereIn('app_id', $ids)->get(['app_id', 'severity', 'status', 'found', 'closed']);

            $inc = \Illuminate\Support\Facades\Schema::hasTable('incidents')
                ? \Illuminate\Support\Facades\DB::table('incidents')->whereNull('deleted_at')
                    ->whereIn('app_id', $ids)->where('created_at', '>=', now()->subDays(90))
                    ->selectRaw('app_id, COUNT(*) n')->groupBy('app_id')->get()->keyBy('app_id')
                : collect();

            $dep = \Illuminate\Support\Facades\Schema::hasTable('deployments')
                ? \Illuminate\Support\Facades\DB::table('deployments')->whereNull('deleted_at')
                    ->whereIn('app_id', $ids)->get(['app_id', 'status', 'deployed_at'])
                : collect();

            $feats = $pids ? \Illuminate\Support\Facades\DB::table('plan_items')->whereNull('deleted_at')
                ->whereIn('project_id', $pids)->whereNotNull('test')->where('test', '!=', '')
                ->get(['project_id', 'test']) : collect();

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
                    'deploys'  => $dp->count(),
                    'rollback' => $dp->count() ? (int) round($rolled / $dp->count() * 100) : null,
                    'lastDeploy' => $dp->max('deployed_at'),
                ];
            }

            return $out;
        });
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

if (! function_exists('hub_recommendations')) {
    /**
     * مركز التوصيات: يجمع إشاراتٍ قابلة للتنفيذ من محرّكات النظام القائمة —
     * خدمات تحت الماء، فريق فوق طاقته، مشاريع متعثرة، تطبيقات كثيرة الأعطال،
     * انتهاءات وشيكة، مستحقات غير محصّلة. كلها من بياناتك المسجَّلة لا من تقدير،
     * وكل توصية تحمل سببها بالأرقام ورابط إجرائها. مرتّبة بالأولوية.
     */
    function hub_recommendations(bool $fresh = false): array
    {
        if ($fresh) \Illuminate\Support\Facades\Cache::forget('recs');

        return \Illuminate\Support\Facades\Cache::remember('recs', 300, function () {
            $rank = ['حرج' => 3, 'مهم' => 2, 'اطّلاع' => 1];
            $out = [];
            $add = function ($sev, $ico, $title, $why, $url, $action) use (&$out) {
                $out[] = compact('sev', 'ico', 'title', 'why', 'url', 'action');
            };

            // ١) خدمات تبيع بأقل من كلفتها
            try {
                $svc = hub_service_costs();
                foreach (array_slice(array_filter($svc['rows'], fn ($r) => $r['margin'] !== null && $r['margin'] < 0), 0, 5) as $s) {
                    $add('حرج', '🌊', 'خدمة تبيع بخسارة: ' . $s['name'],
                        'سعرها الشهري ' . number_format((float) $s['priceM'], 1) . ' وكلفتها ' . number_format((float) $s['costM'], 1)
                        . ' — هامش ' . number_format((float) $s['margin'], 1) . ' شهرياً. راجع السعر أو الكلفة.',
                        route('m.show', ['services', $s['id']]), 'راجع الخدمة');
                }
                if (($svc['totals']['unpriced'] ?? 0) > 0) {
                    $add('اطّلاع', '🏷️', ($svc['totals']['unpriced']) . ' خدمة بلا سعر شهري',
                        'لا يمكن قياس ربحيتها حتى تُسعّرها. سجّل أسعارها لتظهر في تحليل التكلفة.',
                        route('servicecosts'), 'افتح تحليل التكلفة');
                }
            } catch (\Throwable $e) {}

            // ٢) فريق فوق طاقته هذا الأسبوع
            try {
                $cap = hub_capacity();
                $over = array_filter($cap['rows'], fn ($r) => ($r['load'] ?? 0) > 100);
                usort($over, fn ($a, $b) => $b['load'] <=> $a['load']);
                foreach (array_slice($over, 0, 4) as $r) {
                    $add('مهم', '🔥', 'فوق طاقته: ' . $r['name'],
                        'حمله ' . $r['load'] . '٪ — محجوز ' . $r['booked'] . ' ساعة على متاح ' . $r['available']
                        . '. أجّل أو وزّع أو وظّف.',
                        route('capacity'), 'افتح لوحة القدرات');
                }
            } catch (\Throwable $e) {}

            // ٣) مشاريع متعثرة الصحة
            try {
                $projects = \Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at')
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                    ->limit(40)->get(['id', 'name']);
                $sick = [];
                foreach ($projects as $p) {
                    $h = hub_project_health($p->id);
                    if (($h['score'] ?? 100) < 55) $sick[] = ['p' => $p, 'h' => $h];
                }
                usort($sick, fn ($a, $b) => $a['h']['score'] <=> $b['h']['score']);
                foreach (array_slice($sick, 0, 5) as $s) {
                    $add($s['h']['score'] < 40 ? 'حرج' : 'مهم', '🩺', 'مشروع متعثر: ' . $s['p']->name,
                        'صحته ' . $s['h']['score'] . '/١٠٠ (' . ($s['h']['label'] ?? '') . '). راجع عوامل التعثر في صفحته.',
                        route('m.show', ['projects', $s['p']->id]), 'افتح المشروع');
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
                    $overdue = \Illuminate\Support\Facades\DB::table('fin_documents')->whereNull('deleted_at')
                        ->where('kind', 'فاتورة')
                        ->whereRaw('COALESCE(paid,0) < COALESCE(total,0)')
                        ->whereNotNull('due')->whereDate('due', '<', now()->toDateString())
                        ->orderBy('due')->limit(6)->get(['id', 'doc_no', 'partner', 'total', 'paid', 'due']);
                    foreach ($overdue as $d) {
                        $rem = (float) ($d->total ?? 0) - (float) ($d->paid ?? 0);
                        $days = (int) \Illuminate\Support\Carbon::parse($d->due)->diffInDays(now());
                        $add($days > 60 ? 'حرج' : 'مهم', '💸', 'مستحق متأخر: ' . ($d->partner ?: ($d->doc_no ?: 'فاتورة')),
                            'باقٍ ' . number_format($rem, 1) . ' متأخر ' . $days . ' يوماً. تابع التحصيل.',
                            route('m.show', ['fin', $d->id]), 'افتح الفاتورة');
                    }
                }
            } catch (\Throwable $e) {}

            // ٦) انتهاءات وشيكة (٧ أيام)
            try {
                $soon = collect(hub_expiry())->filter(fn ($i) => ($i['days'] ?? 99) <= 7)->take(6);
                foreach ($soon as $i) {
                    $add($i['days'] < 0 ? 'حرج' : 'مهم', '⏳', 'ينتهي قريباً: ' . $i['name'],
                        $i['mlabel'] . ' · ' . $i['flabel'] . ' — ' . ($i['days'] < 0 ? 'متأخر' : ($i['days'] === 0 ? 'اليوم' : 'خلال ' . $i['days'] . ' يوم')) . '.',
                        route('m.show', [$i['module'], $i['id']]), 'افتح السجل');
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

                // الالتزام بالموعد: من الأوامر المستلمة التي لها موعد تسليم متوقع
                $withDue = $received->filter(fn ($p) => filled($p->due));
                $onTime = $withDue->filter(function ($p) {
                    $recvAt = \Illuminate\Support\Carbon::parse($p->updated_at)->startOfDay();
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
        $mk = (string) ($m['module'] ?? '');
        $def = hub_mod($mk);
        if (! $def) return null;
        if ($user && ! hub_can($user, $mk, 'v')) return null;   // احترام الصلاحية

        $agg = in_array($m['agg'] ?? '', ['count', 'sum', 'avg'], true) ? $m['agg'] : 'count';
        $q = \Illuminate\Support\Facades\DB::table($def['table'])->whereNull('deleted_at');
        if ($user) $q = hub_scope($q, $mk, $user);

        // فلتر الحالة: عمود الحالة **الفيزيائي** لا مفتاحه — في وحدة الوثائق
        // المفتاح docStatus والعمود doc_status، فالفحص بالمفتاح كان يُسقط الفلتر بصمت
        if (($st = trim((string) ($m['st'] ?? ''))) !== '' && ($skey = $def['status'] ?? null)) {
            $sfield = collect($def['fields'])->firstWhere('key', $skey);
            $scol = $sfield['col'] ?? $skey;
            if (\Illuminate\Support\Facades\Schema::hasColumn($def['table'], $scol)) $q->where($scol, $st);
        }

        if ($agg === 'count') return (float) $q->count();

        // sum/avg يتطلبان عموداً رقمياً من حقول الوحدة
        $col = collect($def['fields'])->firstWhere('key', $m['col'] ?? '');
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

if (! function_exists('hub_kpis')) {
    /** كل المؤشرات المخصصة محسوبةً — لصفحة العرض */
    function hub_kpis($user = null): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('kpi_defs')) return [];
        $user = $user ?? auth()->user();

        return \App\Models\KpiDef::orderBy('sort')->orderBy('created_at')->get()->map(function ($k) use ($user) {
            $val = hub_kpi_value((array) $k->formula, $user);
            $tone = '';
            if ($val !== null && $k->target !== null) {
                $hit = ($k->good ?? 'up') === 'up' ? $val >= $k->target : $val <= $k->target;
                $near = ($k->good ?? 'up') === 'up' ? $val >= $k->target * 0.8 : $val <= $k->target * 1.2;
                $tone = $hit ? 'ok' : ($near ? 'wn' : 'bad');
            }

            return ['id' => $k->id, 'name' => $k->name, 'unit' => $k->unit,
                    'value' => $val, 'target' => $k->target, 'tone' => $tone,
                    'good' => $k->good, 'formula' => (array) $k->formula];
        })->all();
    }
}

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

if (! function_exists('hub_scope')) {
    /**
     * فرض نطاق المشاريع على أي استعلام (Eloquent أو Query Builder):
     * المستخدم المحدود يرى مشاريعه فقط، وسجلات الوحدات المرتبطة بمشاريعه فقط.
     * الوحدات بلا عمود مشروع تبقى محكومة بمصفوفة الصلاحيات وحدها.
     */
    function hub_scope($q, string $module, $user = null)
    {
        $user = $user ?? auth()->user();
        if (! hub_scoped($user)) return $q;
        $ids = $user->visibleProjectIds();
        if ($module === 'projects') return $q->whereIn('id', $ids);
        if ($col = hub_project_col($module)) return $q->whereIn($col, $ids);
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
    function hub_nav($user): array
    {
        $out = [];
        foreach (config('hub_nav', []) as $g) {
            $items = array_values(array_filter($g['items'], fn ($k) => hub_mod($k) && hub_can($user, $k, 'v')));
            if ($items) $out[] = ['g' => $g['g'], 'icon' => $g['icon'], 'items' => $items];
        }
        return $out;
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
    function hub_ref_options(string $ref): array
    {
        $table = hub_ref_table($ref);
        if (! $table) return [];
        return \Illuminate\Support\Facades\DB::table($table)
            ->whereNull('deleted_at')
            ->orderBy(hub_ref_display($ref))
            ->limit(500)
            ->pluck(hub_ref_display($ref), 'id')
            ->all();
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
        $scoped = hub_scoped($user);
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
                    $rows = $q->limit(40)->get(['id', $disp . ' as _n', $f['col'] . ' as _d']);
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
                $inc = (float) $db->table('fin_documents')->whereNull('deleted_at')->whereNotIn('state', ['ملغاة', 'مسودة'])
                    ->whereIn('kind', ['فاتورة مبيعات', 'دفعة واردة'])->where('date', '>=', now()->startOfMonth()->toDateString())->sum('total');
                $exp = (float) $db->table('fin_documents')->whereNull('deleted_at')->whereNotIn('state', ['ملغاة', 'مسودة'])
                    ->whereIn('kind', ['مصروف', 'فاتورة مشتريات', 'دفعة صادرة'])->where('date', '>=', now()->startOfMonth()->toDateString())->sum('total');
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

        static $cache = [];
        $rid = $user->role_id ?? 'x';
        if (! isset($cache[$rid])) {
            $fr = $user->role?->field_rules;
            $cache[$rid] = is_array($fr) ? $fr : (json_decode((string) $fr, true) ?: []);
        }
        $mode = $cache[$rid][$module][$fieldKey] ?? '';

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
    /** عمود الشركة في وحدة ما إن وُجد (companies نفسها تُطابق على id) */
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

        return $map[$module] ?? null;
    }
}

if (! function_exists('hub_company_scope')) {
    /**
     * محوّل الشركة النشطة: عند اختيار شركة من الشريط العلوي تُصفّى قوائم
     * الوحدات عليها — تصفية عرض للتركيز، لا عزلاً صارماً (ذاك طور لاحق موثق).
     */
    function hub_company_scope($q, string $module)
    {
        $cid = (string) session('hub.company', '');
        if ($cid === '') return $q;
        $col = hub_company_col($module);

        return $col ? $q->where($col, $cid) : $q;
    }
}

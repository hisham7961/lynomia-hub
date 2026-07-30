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

if (! function_exists('hub_closed_states')) {
    /** حالات تعني «انتهى الأمر» — تُستثنى من تنبيهات الانتهاء وقوائم ما يحتاج تدخلاً */
    function hub_closed_states(): array
    {
        return ['منجزة', 'منجز', 'مكتملة', 'مكتمل', 'مغلقة', 'مغلق', 'ملغاة', 'ملغى', 'ملغي',
                'محلولة', 'تم الحل', 'مدفوعة', 'مسددة', 'منتهية', 'منتهي', 'مؤرشفة', 'مؤرشف',
                'منفَّذ', 'منفذ', 'مرفوض', 'مرفوضة', 'مغلق بتقرير', 'مُستعاد', 'متراجع عنه'];
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

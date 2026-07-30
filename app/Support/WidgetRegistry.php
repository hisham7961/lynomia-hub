<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجل ودجات اللوحة.
 *
 * كانت اللوحة مكتوبة بالكود في موضعين لا يعرف أحدهما الآخر: `DashboardController`
 * يحمل الاستعلامات، و`PrefController::DASH_CARDS` يحمل الأسماء — فإضافة ودجة تعني
 * تعديل ملفين، ونسيان أحدهما يعطي بطاقةً بلا اسم أو اسماً بلا بطاقة.
 *
 * هنا تعريفٌ واحد لكل ودجة: مفتاحها واسمها وشرط رؤيتها ومُحضِّر بياناتها وحجمها
 * الافتراضي. اللوحة الحالية تُبنى منه بسلوك مطابق، وباني اللوحات (المرحلة ٦) يُبنى
 * عليه بلا تغيير في الودجات نفسها.
 *
 * `gate`     من يرى الودجة — يُفحص قبل تشغيل أي استعلام.
 * `resolver` يُعيد بيانات الودجة؛ لا يُنادى إلا بعد اجتياز البوابة.
 * `size`     الحجم الافتراضي بوحدات الشبكة — يستعمله باني اللوحات.
 */
class WidgetRegistry
{
    /** ودجات يسجّلها الملحقون وقت التشغيل */
    protected static array $extra = [];

    /** تسجيل ودجة إضافية أو استبدال قائمة */
    public static function register(string $key, array $def): void
    {
        static::$extra[$key] = $def;
    }

    /** إفراغ المسجَّل يدوياً (للاختبارات) */
    public static function forgetRegistered(): void
    {
        static::$extra = [];
    }

    /** كل التعريفات: المدمجة ثم المسجَّلة */
    public static function definitions(): array
    {
        return array_merge(static::builtin(), static::$extra);
    }

    /** `مفتاح => اسم` — مصدر قائمة الإخفاء في التخصيص */
    public static function labels(): array
    {
        return array_map(fn ($d) => $d['label'], static::definitions());
    }

    public static function get(string $key): ?array
    {
        return static::definitions()[$key] ?? null;
    }

    /** الودجات التي أخفاها المستخدم */
    public static function hiddenFor($user = null): array
    {
        return (array) hub_pref('dash.hidden', [], $user ?? auth()->user());
    }

    /** هل يرى هذا المستخدم هذه الودجة؟ الإخفاء أولاً فلا يُشغَّل استعلامٌ لمخفيّة */
    public static function isVisible(string $key, $user = null): bool
    {
        $user = $user ?? auth()->user();
        $def  = static::get($key);
        if (! $def) return false;
        if (in_array($key, static::hiddenFor($user), true)) return false;

        $gate = $def['gate'] ?? null;

        return $gate ? (bool) $gate($user) : true;
    }

    /** بيانات ودجة، أو `null` إن كانت مخفيّة أو ممنوعة */
    public static function resolve(string $key, $user = null)
    {
        $user = $user ?? auth()->user();
        if (! static::isVisible($key, $user)) return null;

        $def = static::get($key);

        return isset($def['resolver']) ? ($def['resolver'])($user) : null;
    }

    /** الودجات المرئية لهذا المستخدم: `مفتاح => تعريف` */
    public static function visibleFor($user = null): array
    {
        $user = $user ?? auth()->user();

        return array_filter(static::definitions(),
            fn ($d, $k) => static::isVisible($k, $user), ARRAY_FILTER_USE_BOTH);
    }

    /* ── الودجات المدمجة: نُقلت من DashboardController بلا تغيير في سلوكها ── */

    protected static function builtin(): array
    {
        return [
            'counts' => [
                'label' => 'بطاقات العدّ العلوية',
                'size'  => ['w' => 12, 'h' => 1],
                'gate'  => fn ($u) => true,
                'resolver' => function ($u) {
                    $out = [];
                    foreach (['projects', 'clients', 'tasks', 'tickets', 'fin', 'contracts'] as $key) {
                        $def = hub_mod($key);
                        if (! $def || ! hub_can($u, $key, 'v')) continue;
                        $out[] = [
                            'key'   => $key,
                            'label' => $def['label'],
                            'count' => hub_scope(DB::table($def['table'])->whereNull('deleted_at'), $key)->count(),
                        ];
                    }

                    return $out;
                },
            ],

            'kpis' => [
                'label' => '📈 ودجات مؤشرات KPI',
                'size'  => ['w' => 12, 'h' => 1],
                // مؤشرات الأداء حساسة — لمن يبنيها وحده
                'gate'  => fn ($u) => hub_monitor($u) && Schema::hasTable('kpi_defs'),
                'resolver' => fn ($u) => hub_kpis($u),
            ],

            'expiry' => [
                'label' => '🔔 ينتهي قريباً',
                'size'  => ['w' => 6, 'h' => 2],
                'gate'  => fn ($u) => true,
                'resolver' => fn ($u) => collect(hub_expiry())
                    ->filter(fn ($i) => hub_can($u, $i['module'], 'v'))->take(5)->values(),
            ],

            'apps' => [
                'label' => '📱 تقدم التطبيقات',
                'size'  => ['w' => 6, 'h' => 2],
                'gate'  => fn ($u) => hub_can($u, 'apps', 'v'),
                'resolver' => fn ($u) => hub_scope(DB::table('applications')->whereNull('deleted_at'), 'apps')
                    ->whereNotNull('project_id')
                    ->tap(fn ($q) => hub_open_scope($q, 'status', ['موقوف']))   // «موقوف» دلالة التطبيقات وحدها
                    ->orderByDesc('created_at')->limit(6)
                    ->get(['id', 'name', 'ver', 'status', 'project_id'])
                    ->map(function ($a) {
                        $a->progress = hub_progress($a->project_id)['pct'];

                        return $a;
                    }),
            ],

            'donut' => [
                'label' => '✅ المهام بالحالة',
                'size'  => ['w' => 4, 'h' => 2],
                'gate'  => fn ($u) => hub_can($u, 'tasks', 'v'),
                'resolver' => fn ($u) => hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                    ->select('status', DB::raw('COUNT(*) c'))->groupBy('status')
                    ->orderByDesc('c')->limit(6)->get()
                    ->map(fn ($r) => ['label' => $r->status ?: 'بلا حالة', 'value' => (int) $r->c])->all(),
            ],

            'due' => [
                'label' => '⏰ مهام تقترب مواعيدها',
                'size'  => ['w' => 6, 'h' => 2],
                'gate'  => fn ($u) => hub_can($u, 'tasks', 'v') && hub_mod('tasks'),
                'resolver' => function ($u) {
                    $tdef = hub_mod('tasks');
                    $dueF = collect($tdef['fields'])->firstWhere('key', 'due');
                    $st   = $tdef['status'] ?? null;
                    $disp = hub_display_col('tasks');
                    if (! $dueF) return ['rows' => collect(), 'dueCol' => null, 'stCol' => $st, 'disp' => $disp];

                    $col = $dueF['col'];

                    return [
                        'rows' => hub_scope(DB::table($tdef['table'])->whereNull('deleted_at'), 'tasks')
                            ->whereNotNull($col)->whereDate($col, '>=', now()->toDateString())
                            ->orderBy($col)->limit(6)
                            ->get(array_values(array_unique(array_filter(['id', $disp, $col, $st])))),
                        'dueCol' => $col, 'stCol' => $st, 'disp' => $disp,
                    ];
                },
            ],

            'audits' => [
                'label' => '🕘 آخر النشاطات',
                'size'  => ['w' => 6, 'h' => 2],
                'gate'  => fn ($u) => true,
                'resolver' => function ($u) {
                    $q = DB::table('audits')
                        ->leftJoin('users', 'users.id', '=', 'audits.user_id')
                        ->select('audits.*', 'users.name as user_name');

                    // وحدة لا يراها المستخدم لا يظهر نشاطها: اسم السجل وحده تسريب
                    $visible = array_values(array_filter(array_keys(hub_modules()),
                        fn ($m) => hub_can($u, $m, 'v')));
                    $q->where(fn ($w) => $w->whereNull('audits.module')
                                            ->orWhereIn('audits.module', $visible));

                    // عزل الشركات: صار `company_id` على القيد فيُفلتر مباشرة.
                    // القيد المعدوم الشركة (سجلٌ محذوف لم يُرحَّل) يُحجب عن المحدود.
                    // درعُ النشر-قبل-الترحيل: إن غاب العمود بعدُ لا نفلتر به فينفجر —
                    // بل نتحفّظ فنقصرها على نشاط المستخدم نفسه (لا تسريبَ ولا انهيار).
                    if (($cids = hub_company_ids($u)) !== null) {
                        if (Schema::hasColumn('audits', 'company_id')) {
                            $q->whereIn('audits.company_id', $cids);
                        } else {
                            $q->where('audits.user_id', $u->id);
                        }
                    }

                    // النطاق المشاريعي: فعلُه هو، أو ما جرى في مشاريعه
                    if (hub_scoped($u)) {
                        $ids = $u->visibleProjectIds();
                        $q->where(fn ($w) => $w->where('audits.user_id', $u->id)
                                                ->orWhereIn('audits.project_id', $ids));
                    }

                    return $q->orderByDesc('audits.created_at')->limit(10)->get();
                },
            ],

            // تُعرض في المتصفح من تخزينه المحلي — بلا مُحضِّر، لكنها تبقى في السجل
            // كي تظهر في قائمة الإخفاء وفي باني اللوحات مثل أخواتها
            'recent' => [
                'label' => '📌 آخر ما فتحت',
                'size'  => ['w' => 4, 'h' => 2],
                'gate'  => fn ($u) => true,
                'resolver' => fn ($u) => null,
                'client' => true,
            ],
        ];
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مسار التسليم — من الطلب إلى الإصدار.
 *
 * الحلقات كانت كلها موجودة ومربوطةً بالحقول: الطلب الداخلي يشير إلى الميزة،
 * والميزة إلى مشروعها، والنشر إلى إصداره وتغييره. وكلٌّ منها **شاشةُ جدول**
 * لا ترى ما قبلها ولا ما بعدها — فلا أحد يعرف: كم يستغرق الطلب حتى يصل
 * المستخدم؟ وأين ينقطع الخيط؟ وهل ما قيل إنه «نُشر» نُشر فعلاً؟
 *
 * هذا القارئ يصل الحلقات ويسأل عنها أسئلةً تُجاب برقم:
 *   — **زمن التسليم**: من تاريخ الطلب إلى تاريخ نشره.
 *   — **أين ينكسر الخيط**: طلبٌ اعتُمد ونُسي، وميزةٌ «منشورة» بلا نشرٍ يوثّقها،
 *     وميزةٌ فشل اختبارها وهي منشورة، وإصدارٌ إنتاجيّ بلا سجل تغييرات.
 *   — **إيقاع الإصدار**: كم نشرةً في الشهر، ونسبة الفشل، والفجوة بين إصدارين.
 */
class Delivery
{
    /** كل مصدرٍ خلف صلاحيته — الشاشة تجمع ولا تفتح باباً مغلقاً */
    protected static function may(string $module): bool
    {
        return hub_can(auth()->user(), $module, 'v');
    }

    /* ────────── زمن التسليم ────────── */

    /**
     * من الطلب إلى النشر: لكل ميزةٍ منشورة نشراً موثَّقاً، كم يوماً استغرقت
     * منذ رُفع طلبها. المتوسط وحده يخدع — فيُحسب **الوسيط** معه.
     */
    public static function leadTime(): array
    {
        if (! self::may('feats') || ! Schema::hasTable('plan_items')) return [];

        $feats = DB::table('plan_items')->whereNull('deleted_at')
            ->where('status', 'منشورة')->whereNotNull('request_id')
            ->limit(300)->get(['id', 'title', 'request_id', 'due', 'updated_at']);
        if ($feats->isEmpty()) return [];

        $reqs = Schema::hasTable('internal_requests')
            ? DB::table('internal_requests')->whereIn('id', $feats->pluck('request_id'))
                ->pluck('req_date', 'id')
            : collect();

        $days = [];
        $slow = [];
        foreach ($feats as $f) {
            $from = $reqs[$f->request_id] ?? null;
            if (! $from) continue;
            $d = (int) Carbon::parse($from)->startOfDay()->diffInDays(
                Carbon::parse($f->updated_at)->startOfDay(), false);
            if ($d < 0) continue;                       // بياناتٌ متناقضة تُتجاهَل ولا تُشوّه المتوسط
            $days[] = $d;
            $slow[] = ['title' => $f->title, 'id' => $f->id, 'days' => $d];
        }
        if (! $days) return [];

        sort($days);
        $n = count($days);
        $median = $n % 2 ? $days[intdiv($n, 2)] : (int) round(($days[$n / 2 - 1] + $days[$n / 2]) / 2);
        usort($slow, fn ($a, $b) => $b['days'] <=> $a['days']);

        return ['n' => $n, 'avg' => (int) round(array_sum($days) / $n), 'median' => $median,
                'best' => $days[0], 'worst' => $days[$n - 1],
                'slowest' => array_slice($slow, 0, 5)];
    }

    /* ────────── أين ينكسر الخيط ────────── */

    public static function breaks(): array
    {
        $out = [];

        // طلبٌ اعتُمد ثم لم يُترجَم إلى شيء — أسوأ من الرفض: وعدٌ بلا أثر
        if (self::may('requests') && Schema::hasTable('internal_requests')) {
            $rows = DB::table('internal_requests')->whereNull('deleted_at')
                ->where('decision', 'مقبول')
                ->whereNull('project_id')->whereNull('task_id')
                ->when(Schema::hasTable('plan_items'), fn ($q) => $q->whereNotIn('id',
                    DB::table('plan_items')->whereNull('deleted_at')->whereNotNull('request_id')->pluck('request_id')))
                ->orderBy('req_date')->limit(10)->get(['id', 'title', 'req_date']);
            foreach ($rows as $r) {
                $out[] = ['icon' => '📨', 'tone' => 'bad', 'kind' => 'طلبٌ اعتُمد ونُسي',
                    'title' => $r->title, 'module' => 'requests', 'id' => $r->id,
                    'why' => 'قُبل الطلب ولم تُنشأ له ميزةٌ ولا مهمةٌ ولا مشروع'
                        . ($r->req_date ? ' — رُفع ' . Carbon::parse($r->req_date)->diffForHumans() : '')
                        . '. الوعد الذي لا يُترجَم أسوأ من الرفض الصريح.'];
            }
        }

        if (self::may('feats') && Schema::hasTable('plan_items')) {
            $deployed = Schema::hasTable('deployments')
                ? DB::table('deployments')->whereNull('deleted_at')->where('env', 'إنتاج')
                    ->whereNotNull('project_id')->pluck('project_id')->unique()
                : collect();

            // ميزةٌ «منشورة» ولا نشرَ إنتاجيّ في مشروعها يوثّقها
            foreach (DB::table('plan_items')->whereNull('deleted_at')->where('status', 'منشورة')
                        ->whereNotNull('project_id')->whereNotIn('project_id', $deployed->all() ?: ['-'])
                        ->limit(10)->get(['id', 'title']) as $f) {
                $out[] = ['icon' => '🚀', 'tone' => 'wn', 'kind' => 'منشورةٌ بلا نشرٍ موثَّق',
                    'title' => $f->title, 'module' => 'feats', 'id' => $f->id,
                    'why' => 'الحالة «منشورة» ولا سجلَّ نشرٍ إنتاجيّ لمشروعها — نُشرت أم قيل إنها نُشرت؟'];
            }

            // تناقضٌ خطر: اختبارُها فشل وهي في الإنتاج
            foreach (DB::table('plan_items')->whereNull('deleted_at')
                        ->where('status', 'منشورة')->where('test', 'فشل')
                        ->limit(10)->get(['id', 'title', 'test_note']) as $f) {
                $out[] = ['icon' => '🧪', 'tone' => 'bad', 'kind' => 'منشورةٌ واختبارها فاشل',
                    'title' => $f->title, 'module' => 'feats', 'id' => $f->id,
                    'why' => 'خرجت للمستخدمين وسجلُّ اختبارها «فشل»'
                        . ($f->test_note ? ' — ' . \Illuminate\Support\Str::limit($f->test_note, 60) : '') . '.'];
            }

            // متأخرةٌ عن موعدها وما زالت قيد التطوير
            foreach (DB::table('plan_items')->whereNull('deleted_at')
                        ->whereIn('status', ['قيد التطوير', 'قيد الاختبار'])
                        ->whereNotNull('due')->where('due', '<', now()->toDateString())
                        ->orderBy('due')->limit(10)->get(['id', 'title', 'due']) as $f) {
                $late = (int) Carbon::parse($f->due)->diffInDays(now());
                $out[] = ['icon' => '⏰', 'tone' => 'wn', 'kind' => 'ميزة تجاوزت موعدها',
                    'title' => $f->title, 'module' => 'feats', 'id' => $f->id,
                    'why' => "متأخرة {$late} يوماً — موعدٌ فات بلا إعادة جدولةٍ معلنة يفقد معناه."];
            }
        }

        if (self::may('deploys') && Schema::hasTable('deployments')) {
            // إصدارٌ إنتاجيّ بلا سجل تغييرات — لا يُعرف ما فيه إن وجب التراجع
            foreach (DB::table('deployments')->whereNull('deleted_at')->where('env', 'إنتاج')
                        ->where(fn ($w) => $w->whereNull('changes_log')->orWhere('changes_log', ''))
                        ->orderByDesc('deployed_at')->limit(8)->get(['id', 'ver', 'deployed_at']) as $d) {
                $out[] = ['icon' => '📋', 'tone' => 'wn', 'kind' => 'إصدارٌ بلا سجل تغييرات',
                    'title' => 'الإصدار ' . ($d->ver ?: '—'), 'module' => 'deploys', 'id' => $d->id,
                    'why' => 'نشرٌ إنتاجيّ لا يُعرف ما فيه — فإذا ظهر عطبٌ لم يُعرف من أين جاء ولا إلى أين يُتراجَع.'];
            }

            // هجرةٌ فشلت: قاعدةٌ في حالةٍ وسط
            foreach (DB::table('deployments')->whereNull('deleted_at')->where('migrations', 'فشلت')
                        ->orderByDesc('deployed_at')->limit(5)->get(['id', 'ver', 'env']) as $d) {
                $out[] = ['icon' => '🗄️', 'tone' => 'bad', 'kind' => 'هجرة قاعدة فشلت',
                    'title' => 'الإصدار ' . ($d->ver ?: '—') . ' · ' . ($d->env ?: ''),
                    'module' => 'deploys', 'id' => $d->id,
                    'why' => 'القاعدة بين حالتين — لا القديمة ولا الجديدة، وكل قراءةٍ بعدها مشكوكٌ فيها.'];
            }
        }

        // اعتماديةٌ حرجة بلا بديل: نقطة انهيارٍ واحدة تُسقط الخدمة
        if (self::may('deps') && Schema::hasTable('dependencies')) {
            foreach (DB::table('dependencies')->whereNull('deleted_at')
                        ->where('criticality', 'حرجة')->where('status', 'نشطة')
                        ->where(fn ($w) => $w->whereNull('fallback')->orWhere('fallback', ''))
                        ->limit(8)->get(['id', 'title', 'dep_name', 'dep_type']) as $d) {
                $out[] = ['icon' => '🔗', 'tone' => 'bad', 'kind' => 'اعتمادية حرجة بلا بديل',
                    'title' => $d->title ?: $d->dep_name, 'module' => 'deps', 'id' => $d->id,
                    'why' => 'حرجةٌ ونشطة ولا خطةَ بديلٍ مكتوبة — نقطة انهيارٍ واحدة'
                        . ($d->dep_type ? ' (' . $d->dep_type . ')' : '') . '.'];
            }
        }

        // مشروعٌ جارٍ صامت: لا تحديث عمل منذ أسبوعين
        if (self::may('updates') && Schema::hasTable('work_updates') && Schema::hasTable('projects')) {
            $last = DB::table('work_updates')->whereNull('deleted_at')
                ->select('project_id', DB::raw('MAX(created_at) as last'))
                ->groupBy('project_id')->pluck('last', 'project_id');
            foreach (hub_open_scope(DB::table('projects')->whereNull('deleted_at'))
                        ->limit(30)->get(['id', 'name']) as $p) {
                $l = $last[$p->id] ?? null;
                if ($l && Carbon::parse($l)->gt(now()->subDays(14))) continue;
                $out[] = ['icon' => '🤫', 'tone' => 'wn', 'kind' => 'مشروعٌ جارٍ صامت',
                    'title' => $p->name, 'module' => 'projects', 'id' => $p->id,
                    'why' => $l ? 'آخر تحديث عملٍ ' . Carbon::parse($l)->diffForHumans()
                                : 'لا تحديث عملٍ مسجَّل إطلاقاً — مشروعٌ يعمل ولا أحد يعرف أين وصل.'];
            }
        }

        usort($out, fn ($a, $b) => ['bad' => 0, 'wn' => 1][$a['tone']] <=> ['bad' => 0, 'wn' => 1][$b['tone']]);

        return $out;
    }

    /* ────────── إيقاع الإصدار ────────── */

    /** كم نشرةً إنتاجية في الشهر، وكم فشلت، وكم متوسط مدّتها */
    public static function cadence(): array
    {
        if (! self::may('deploys') || ! Schema::hasTable('deployments')) return [];

        $rows = DB::table('deployments')->whereNull('deleted_at')
            ->where('deployed_at', '>=', now()->subDays(90))
            ->get(['env', 'status', 'deployed_at', 'duration_min']);
        if ($rows->isEmpty()) return [];

        $prod = $rows->where('env', 'إنتاج');
        $bad = $prod->whereIn('status', ['فشل', 'متراجع عنه']);
        $m30 = $prod->filter(fn ($r) => Carbon::parse($r->deployed_at)->gt(now()->subDays(30)));
        $dur = $prod->whereNotNull('duration_min')->pluck('duration_min');

        return [
            'n90' => $prod->count(), 'n30' => $m30->count(),
            'failPct' => $prod->count() ? (int) round($bad->count() / $prod->count() * 100) : 0,
            'failN' => $bad->count(),
            'avgMin' => $dur->count() ? (int) round($dur->avg()) : null,
            // الفشل فوق عُشر النشرات ليس حظاً — هو خللٌ في المسار
            'tone' => $prod->count() && $bad->count() / $prod->count() > 0.1 ? 'bad'
                    : ($m30->isEmpty() ? 'wn' : 'ok'),
            'verdict' => $m30->isEmpty()
                ? 'لا نشرة إنتاجية منذ شهر — إما لا يُصدَر شيء، أو يُصدَر ولا يُسجَّل.'
                : ($bad->count() / max(1, $prod->count()) > 0.1
                    ? 'أكثر من عُشر النشرات فشل أو تُرووجع عنه — الخلل في المسار لا في الحظ.'
                    : 'إيقاعٌ منتظم ونسبة فشلٍ ضمن المعقول.'),
        ];
    }

    /**
     * خطّ الإصدارات: كل إصدارٍ إنتاجيّ وما قبله، والفجوة بينهما، وما فيه.
     * «فرقُ الإصدارات» الذي كان يُبحث عنه في رسائل الفريق.
     */
    public static function releases(int $limit = 12): array
    {
        if (! self::may('deploys') || ! Schema::hasTable('deployments')) return [];

        $rows = DB::table('deployments')->whereNull('deleted_at')->where('env', 'إنتاج')
            ->orderByDesc('deployed_at')->limit($limit + 1)
            ->get(['id', 'ver', 'app_id', 'project_id', 'deployed_at', 'changes_log', 'status', 'by_id']);
        if ($rows->isEmpty()) return [];

        $apps = Schema::hasTable('applications')
            ? DB::table('applications')->whereIn('id', $rows->pluck('app_id')->filter())->pluck('name', 'id')
            : collect();
        $users = DB::table('users')->whereIn('id', $rows->pluck('by_id')->filter())->pluck('name', 'id');

        $list = $rows->values();
        $out = [];
        foreach ($list->take($limit) as $i => $r) {
            $prev = $list[$i + 1] ?? null;
            $gap = $prev && $r->deployed_at && $prev->deployed_at
                ? (int) Carbon::parse($prev->deployed_at)->diffInDays(Carbon::parse($r->deployed_at))
                : null;

            $out[] = [
                'id' => $r->id, 'ver' => $r->ver ?: '—', 'prev' => $prev?->ver,
                'at' => $r->deployed_at, 'gap' => $gap, 'status' => $r->status,
                'app' => $apps[$r->app_id] ?? null, 'by' => $users[$r->by_id] ?? null,
                // أسطر سجل التغييرات كما كُتبت — هذا هو «ما الجديد» فعلاً
                'lines' => collect(preg_split('/\r?\n/', (string) $r->changes_log))
                    ->map(fn ($l) => trim(ltrim($l, "-•* \t")))->filter()->take(8)->values()->all(),
            ];
        }

        return $out;
    }

    /** التصاميم: ما علق منها وما تأخر */
    public static function designs(): array
    {
        if (! self::may('designs') || ! Schema::hasTable('design_tasks')) return [];

        $q = fn () => DB::table('design_tasks')->whereNull('deleted_at');
        $open = hub_open_scope($q(), 'status', ['جاهز', 'ملغي']);

        return [
            'open' => (clone $open)->count(),
            'late' => (clone $open)->whereNotNull('due')->where('due', '<', now()->toDateString())->count(),
            'revising' => $q()->where('status', 'تعديلات')->count(),
            'waiting' => $q()->where('status', 'بانتظار مراجعة')->count(),
            'unassigned' => (clone $open)->whereNull('assignee_id')->count(),
        ];
    }
}

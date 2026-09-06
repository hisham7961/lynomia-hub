<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use App\Support\Series;
use App\Support\SysMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** مركز مراقبة وتشغيل النظام — عين المالك التقنية */
class OpsController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز التشغيل للمالكين فقط');
    }

    public function index(Request $r)
    {
        $this->gate();

        // (WP-2.4) أداء المسارات والانحدار — قارئٌ واحد، تجميعُه في القاعدة
        $rp = $this->routesPerf($r);

        // (WP-2.5) أهدافُ مستوى الخدمة وميزانيةُ الخطأ — مطفأةٌ كلّياً ما لم تُضبط مفاتيح slo.*
        $slo = $this->slo();

        // قاعدة البيانات
        $db = ['ok' => false, 'driver' => config('database.default'), 'size' => null, 'ms' => null];
        try {
            $t0 = microtime(true);
            DB::select('select 1');
            $db['ms'] = round((microtime(true) - $t0) * 1000, 1);
            $db['ok'] = true;
            $db['size'] = $db['driver'] === 'sqlite'
                ? @filesize(config('database.connections.sqlite.database'))
                : (DB::selectOne('SELECT SUM(data_length + index_length) s FROM information_schema.tables WHERE table_schema = DATABASE()')->s ?? null);
        } catch (\Throwable $e) {
            $db['error'] = $e->getMessage();
        }

        // التخزين والذاكرة والحمل والتشغيل
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $sys = [
            'disk_free' => $free, 'disk_total' => $total,
            'disk_pct'  => ($free && $total) ? (int) round(($total - $free) * 100 / $total) : null,
            'load'      => function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? null) : null,
            'php'       => PHP_VERSION,
            'uptime'    => is_readable('/proc/uptime') ? (int) floatval(file_get_contents('/proc/uptime')) : null,
        ];

        // الاستهلاك الحقيقي و**من يستهلك** — الرقم بلا فاعلٍ لا يُعالَج:
        // «الحمل ٢٫٤» بلا عدد أنوية لا يقول أهو ٦٠٪ أم ٢٤٠٪، و«القرص ٩١٪»
        // بلا تفصيلٍ لا يُخلي بايتاً واحداً.
        $cpu = SysMonitor::cpu();
        $mem = SysMonitor::memory();
        $consumers = [
            'disk'   => SysMonitor::diskConsumers(),
            'tables' => SysMonitor::tableConsumers(),
            'slow'   => SysMonitor::slowRoutes(),
            'busy'   => SysMonitor::busyRoutes(),
        ];
        $pulse = SysMonitor::pulse();

        // (WP-2.7 · §25) طوابير الرسائل — قارئٌ ملفوف: ثبت بالتشغيل أنّ إسقاط جدول
        // outbox كان يُسقط الشاشةَ كلَّها بخطأ ٥٠٠ من هذا السطر تحديداً. الآن يعرض
        // القسمُ «غير متاح» وتبقى حالةُ المكوّن الحرجة ظاهرةً في ترويسة الصحّة.
        $outbox = null;
        try {
            $outbox = DB::table('outbox')->select('state', DB::raw('COUNT(*) c'))->groupBy('state')->pluck('c', 'state');
        } catch (\Throwable $e) {
        }

        // **نموذجُ الصحّة الواحد** (v2.399): الحالةُ لكل مكوّنٍ حرج بخمس درجات — هي نفسُها
        // التي يقرؤها /healthz، فلا يقول المركزُ «سليم» وتقول المراقبةُ غيرَه. النبضاتُ
        // تُشتقّ منه (المتغيّر نفسُه للقالب) بمدّة آخر تشغيلٍ ونتيجته لا الموعدِ وحده.
        $health = \App\Support\Health::check();
        $deps = \App\Support\Health::dependencies();
        $beats = [];
        foreach (($health['components']['scheduler']['data']['jobs'] ?? []) as $k => $j) {
            $beats[] = ['key' => $k, 'label' => $j['label'], 'at' => $j['at'], 'late' => $j['late'],
                        'status' => $j['status'], 'ms' => $j['ms'], 'result' => $j['result'], 'every' => $j['every_min']];
        }

        // (WP-2.7 · §3.13) لوحةُ النسخ: ملفّاتٌ + تاريخُ تشغيلٍ بنجاحه وفشله — مخبّأةٌ
        // ٦٠ ثانية خلف hub_screen المختوم، وسطرُ الطزاجة يقولها (cc/freshness)
        $bkW = hub_screen('ops.backups', 60, fn () => $this->backupsPanel(), ['backups'], true);

        // أخطاء وبطء (٧ أيام) — ملفوفةٌ (§25: جدولٌ ساقط يعرض «غير متاح» لا أصفاراً
        // كاذبة) ومخبّأةٌ ٦٠ ثانية: أرقامُ أسبوعٍ كاملٍ لا تتغيّر بين ضغطتين
        $errsW = hub_screen('ops.errs', 60, fn () => rescue(fn () => [
            'new'  => ErrorEvent::where('status', 'جديد')->count(),
            'week' => ErrorEvent::where('last_seen', '>=', now()->subDays(7))->sum('count'),
            // **وقائعُ لا بصمات** (v2.338): `count()` كان يعدّ صفوفَ الجدول
            // المجمَّع — أي عددَ المسارات المتميّزة — بينما `week` فوقه يجمع
            // `count`. فألفُ بطءٍ على مسارٍ واحد كانت تُعرض «١»، والبطاقةُ
            // المخصَّصةُ لقياس الحمل تُطمئن حيث ينبغي أن تُنذر.
            'slow' => (int) ErrorEvent::where('kind', 'slow')->where('last_seen', '>=', now()->subDays(7))->sum('count'),
            'api'  => (int) ErrorEvent::where('kind', 'api')->where('last_seen', '>=', now()->subDays(7))->sum('count'),
        ], null, false), [], true);

        // (WP-2.7 · §3.14) الترحيلات بعدّادٍ واحدٍ متّفق (hub_pending_migrations) —
        // النسخةُ الخاصة القديمة (pendingMigrations) حُذفت فلا يقول المركزُ قولاً
        // والمراقبةُ (/healthz) قولاً آخر
        $migW = hub_screen('ops.mig', 60, fn () => $this->migrationsPanel(), ['migrations'], true);

        // (WP-2.7 · §3.16) ارتباطُ الإصدار: النشراتُ مقابل الأخطاء قبلها وبعدها
        $relW = hub_screen('ops.releases', 60, fn () => $this->releasesPanel(), [], true);

        // (WP-2.7 · §3.1) «منذ متى والحالة هكذا» — من سلسلة ops/health/rank
        $hdrW = $this->headerSince($health);

        // من على النظام الآن — نشاط فعلي خلال آخر ٥ دقائق، ودخول اليوم
        $live = ['now' => 0, 'today' => 0];
        try {
            $live['now'] = DB::table('page_visits')->where('at', '>=', now()->subMinutes(5))
                ->distinct()->count('user_id');
            $live['today'] = DB::table('sessions_log')->where('started_at', '>=', now()->startOfDay())->count();
        } catch (\Throwable $e) {
        }

        // آخر أسطر الأخطاء من ملف اللوغ — بلا SSH: آخر ٦٤ك.ب فقط ثم سطور ERROR الأخيرة
        $logLines = [];
        try {
            $lf = storage_path('logs/laravel.log');
            if (is_file($lf)) {
                $fh = fopen($lf, 'r');
                fseek($fh, max(0, filesize($lf) - 65536));
                $chunk = (string) stream_get_contents($fh);
                fclose($fh);
                $logLines = array_slice(array_values(array_filter(explode("\n", $chunk),
                    fn ($l) => str_contains($l, '.ERROR') || str_contains($l, '.CRITICAL'))), -25);
            }
        } catch (\Throwable $e) {
        }

        // بيئة التشغيل — ما يحدد سلوك النظام فعلياً على هذا الخادم
        $env = [
            'env'    => (string) config('app.env'),
            'debug'  => (bool) config('app.debug'),
            'cache'  => (string) config('cache.default'),
            'queue'  => (string) config('queue.default'),
            'session' => (string) config('session.driver'),
            'opcache' => function_exists('opcache_get_status') && @opcache_get_status(false) ? 'مفعّل' : 'متوقف',
            'maint'  => (bool) setting('maintenance.on', false),
        ];

        // الأقسامُ المختومة تُفكّ هنا: البياناتُ باسمها وطابعُ «آخر حساب» بجانبها —
        // فيرسم كلُّ قسمٍ سطرَ الطزاجة (cc/freshness) لخبيئته البالغة ٦٠ ثانية
        return view('ops.index', array_merge(
            compact('db', 'sys', 'cpu', 'mem', 'consumers', 'pulse', 'outbox',
                'beats', 'live', 'logLines', 'env', 'health', 'deps', 'rp', 'slo'),
            ['errs' => $errsW['data'], 'errsAt' => $errsW['at'],
             'bk' => $bkW['data'], 'bkAt' => $bkW['at'],
             'mig' => $migW['data'], 'migAt' => $migW['at'],
             'rel' => $relW['data'], 'relAt' => $relW['at'],
             'hdr' => $hdrW['data'], 'hdrAt' => $hdrW['at']]
        ));
    }

    /**
     * (WP-2.7 · spec §3.1) «منذ متى والحالة هكذا؟» من سلسلة ('ops','health','rank')
     * التي تكتبها لقطةُ hub:ops-snapshot كلَّ ٥ دقائق: آخرُ نقطةٍ برتبةٍ مغايرة
     * للرتبة الحالية، ثم **أولُ** نقطةٍ بالرتبة الحالية بعدها — تلك بدايةُ الثبات.
     * بلا سلسلةٍ بعدُ لا مدّةَ مُختلَقة («سيبدأ القياس من الآن»)، وحالةٌ لم تلحقها
     * لقطةٌ بعدُ تُقال كذلك. مخبّأةٌ ٦٠ ثانية والرتبةُ جزءٌ من المفتاح — فتغيّرُ
     * الحالة يقلب المفتاحَ ويُعيد الحساب فوراً لا بعد انقضاء المهلة.
     */
    protected function headerSince(array $health): array
    {
        $rank = (float) \App\Support\Health::rank($health['status']);

        return hub_screen('ops.hdr:' . (int) $rank, 60, function () use ($rank) {
            $out = ['since' => null, 'tracked' => false];
            try {
                $base = fn () => DB::table('metric_points')->where('module', 'ops')
                    ->where('record_id', 'health')->where('metric', 'rank');
                $out['tracked'] = (bool) $base()->limit(1)->exists();
                if (! $out['tracked']) return $out;

                $lastDiff = $base()->where('value', '!=', $rank)->max('at');
                $q = $base()->where('value', $rank);
                if ($lastDiff !== null) $q->where('at', '>', $lastDiff);
                $out['since'] = $q->min('at');
            } catch (\Throwable $e) {
                // §25: جدولٌ غائب ⇒ ترويسةٌ صادقة بلا مدّة، لا شاشةٌ ساقطة
            }

            return $out;
        }, ['metric_points'], true);
    }

    /**
     * (WP-2.7 · spec §3.13) لوحةُ النسخ الاحتياطي: الملفّاتُ الفعلية على القرص
     * (الأحدثُ زمنياً أولاً، والتشفيرُ من الامتداد .enc) + تاريخُ التشغيل بنجاحه
     * **وفشله** من سلسلة ('ops','backup','run') في metric_points — الملفّاتُ وحدها
     * لا تحكي الفشل: نسخةٌ فاشلة لا تترك ملفاً فتختفي من كل سردٍ ملفّيّ.
     * **ولا استعادةَ من الويب عمداً**: زرٌّ يدهس القاعدةَ كاملةً لا مكانَ له بجوار
     * أزرار الفحص — الشاشةُ تقولها وتُحيل إلى كتيّب التشغيل.
     */
    protected function backupsPanel(): array
    {
        $out = ['files' => [], 'latest' => null, 'total' => 0, 'runs' => [], 'failed_runs' => 0];
        try {
            $files = [];
            foreach (array_merge(glob(storage_path('app/backups/hub-*.json')) ?: [],
                                 glob(storage_path('app/backups/hub-*.json.enc')) ?: []) as $f) {
                $files[] = ['name' => basename($f), 'size' => (int) @filesize($f),
                            'enc' => str_ends_with($f, '.enc'), 'mtime' => (int) @filemtime($f)];
            }
            // الأحدثُ زمنياً أولاً والاسمُ فاصلَ تعادل — لا قرعةَ ترتيبٍ بين ملفين بلحظةٍ واحدة
            usort($files, fn ($a, $b) => [$b['mtime'], $b['name']] <=> [$a['mtime'], $a['name']]);
            $out['total'] = count($files);
            $out['latest'] = $files[0] ?? null;
            $out['files'] = array_slice($files, 0, 10);   // «أحدث ١٠» صراحةً — والباقي على القرص
        } catch (\Throwable $e) {
            // §25: تخزينٌ متعثّر لا يُسقط الشاشة
        }
        try {
            $out['runs'] = DB::table('metric_points')->where('module', 'ops')
                ->where('record_id', 'backup')->where('metric', 'run')
                ->orderByDesc('at')->orderByDesc('id')->limit(15)->get(['value', 'at', 'meta'])
                ->map(function ($r) {
                    $m = json_decode((string) $r->meta, true) ?: [];
                    // مدّةٌ غائبة تُخزَّن -1 (critic #30) — تُعرض «—» لا صفراً كاذباً
                    return ['at' => $r->at, 'ms' => (float) $r->value >= 0 ? (int) $r->value : null,
                            'result' => (string) ($m['result'] ?? 'ok'), 'note' => (string) ($m['note'] ?? '')];
                })->all();
            $out['failed_runs'] = count(array_filter($out['runs'], fn ($r) => $r['result'] !== 'ok'));
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /**
     * (WP-2.7 · spec §3.14) حالةُ الترحيلات بعدّادٍ **واحد**: hub_pending_migrations()
     * — المساعِدُ نفسُه الذي يقرؤه /healthz ومكوّنُ «مخطّط القاعدة»، فلا عدّادَ ثانياً
     * قد يخالفه (كانت هنا نسخةٌ خاصّة حُذفت في هذه الحزمة). القائمةُ الاسمية تُستخرج
     * فقط حين يقول العدّادُ إنّ ثمّة معلّقاً، والدفعةُ وآخرُ تطبيقٍ من جدول migrations
     * نفسِه، وآخرُ تشغيلٍ من المركز من قيدِ تدقيق «تشغيل الترحيلات».
     */
    protected function migrationsPanel(): array
    {
        $out = ['ok' => false, 'pending_n' => 0, 'pending' => [], 'batch' => null,
                'batch_n' => 0, 'last_applied' => null, 'last_run_at' => null];
        try {
            $out['pending_n'] = (int) hub_pending_migrations();
            if ($out['pending_n'] > 0) {
                $ran = DB::table('migrations')->pluck('migration')->all();
                $out['pending'] = collect(glob(database_path('migrations/*.php')))
                    ->map(fn ($f) => basename($f, '.php'))
                    ->reject(fn ($m) => in_array($m, $ran, true))
                    ->sort()->values()->all();
            }
            $out['batch'] = DB::table('migrations')->max('batch');
            if ($out['batch'] !== null) {
                $out['batch_n'] = (int) DB::table('migrations')->where('batch', $out['batch'])->count();
                $out['last_applied'] = (string) DB::table('migrations')
                    ->orderByDesc('batch')->orderByDesc('id')->value('migration');
            }
            $out['ok'] = true;
        } catch (\Throwable $e) {
            // §25: جدول migrations نفسُه غائب (قاعدةٌ عذراء؟) — القسم يقول «غير متاح»
        }
        try {
            $out['last_run_at'] = DB::table('audits')->where('action', 'تشغيل الترحيلات')
                ->orderByDesc('created_at')->orderByDesc('id')->value('created_at');
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /**
     * (WP-2.7 · spec §3.16) ارتباطُ الإصدار: لكل نشرٍ حديث (أحدثُ ٥ من
     * deployments.deployed_at) نافذتان متساويتان حول لحظة النشر (٢٤ ساعة) —
     * تكراراتُ error_events ونسبةُ أخطاء دلاء HTTP قبل/بعد، **بحدٍّ أدنى للعيّنة**
     * (ops.regression_min_n نفسُه الذي يحرس انحدارَ المسارات): عيّنةٌ هزيلة ⇒
     * «لا توجد بيانات تاريخية كافية» لا حكمٌ مُختلَق (spec §26).
     */
    protected function releasesPanel(): array
    {
        $out = ['ok' => false, 'hours' => 24,
                'min_n' => max(1, (int) setting('ops.regression_min_n', 100)), 'rows' => []];
        try {
            $deps = DB::table('deployments')->whereNull('deleted_at')->whereNotNull('deployed_at')
                ->orderByDesc('deployed_at')->orderByDesc('id')
                ->limit(5)->get(['id', 'ver', 'env', 'deployed_at', 'migrations']);
            $h = $out['hours'];
            $win = function ($from, $to) {
                $errs = null;
                try {
                    $errs = (int) DB::table('error_events')
                        ->where('last_seen', '>=', $from)->where('last_seen', '<', $to)->sum('count');
                } catch (\Throwable $e) {
                    // §25: سقوطُ قارئ الدليل لا يُسقط الحكمَ كلَّه — يُعرض «—»
                }
                $b = null;
                try {
                    $b = DB::table('http_metric_buckets')
                        ->where('bucket_at', '>=', $from)->where('bucket_at', '<', $to)
                        ->selectRaw('SUM(count) n, SUM(err4) + SUM(err5) e')->first();
                } catch (\Throwable $e) {
                }
                $n = (int) ($b->n ?? 0);

                return ['errs' => $errs, 'n' => $n,
                        'rate' => $n > 0 ? round((int) ($b->e ?? 0) * 100.0 / $n, 2) : null];
            };
            foreach ($deps as $d) {
                $at = \Illuminate\Support\Carbon::parse($d->deployed_at);
                $before = $win($at->copy()->subHours($h), $at);
                $after = $win($at, $at->copy()->addHours($h));
                $enough = $before['n'] >= $out['min_n'] && $after['n'] >= $out['min_n'];
                $out['rows'][] = [
                    'ver' => $d->ver, 'env' => $d->env, 'at' => $d->deployed_at,
                    'migrations' => $d->migrations, 'before' => $before, 'after' => $after,
                    'enough' => $enough,
                    // نسبةٌ سابقة صفر: hub_compare يعيد pct=null بصدق — الارتفاعُ من الصفر يُوسَم بقيمتيه
                    'cmp' => $enough && $after['rate'] !== null && $before['rate'] !== null
                        ? hub_compare((float) $after['rate'], (float) $before['rate']) : null,
                ];
            }
            $out['ok'] = true;
        } catch (\Throwable $e) {
            // §25: سجلُّ النشر متعثّر — القسم يقول «غير متاح»
        }

        return $out;
    }

    /**
     * (WP-2.5 · spec §3.10/§26) أهدافُ مستوى الخدمة (SLO) وميزانيةُ الخطأ.
     *
     * **مطفأةٌ كلّياً ما لم تُضبط المفاتيح** (كلُّها فارغةٌ افتراضياً): بلا
     * `slo.window_days` وهدفٍ واحدٍ مكتملٍ على الأقل لا بطاقةَ ولا استعلامَ واحداً.
     * ثلاثةُ أهدافٍ اختيارية، كلٌّ من مصدره الحقيقيّ:
     *   · **التوافر** (slo.availability_pct): نسبةُ فحوص المراقبة الناجحة عبر كل
     *     الأهداف المراقَبة — من سلسلة `metric_points` (metric=up) التي يكتبها
     *     `Uptime::check`.
     *   · **زمنُ الاستجابة** (slo.latency_ms + slo.latency_pct): نسبةُ الطلبات
     *     الأسرع من العتبة — من مدرَّجات `http_metric_buckets.hist` اللوغاريتمية،
     *     فهي **تقريبيةٌ بحدّ خطأ الحاوية ±7.5٪** (القرار ق٥) وتُوسَم كذلك.
     *     الدلوُ يُحسب «سريعاً» إذا كان وسطُه الهندسيّ ≤ العتبة — أعدلُ قسمةٍ
     *     للدلو الحاوي للعتبة نفسِها.
     *   · **نسبةُ الأخطاء** (slo.error_rate_pct): SUM(err5)/SUM(count) — أخطاءُ
     *     الخادم وحدها عمداً: 4xx ذنبُ الطالب لا الخدمة (انحدارُ WP-2.4 يرصد
     *     4xx+5xx تغيّراً؛ أمّا ميزانيةُ الخطأ فذنبُ الخدمة وحده).
     *
     * **تاريخٌ ناقصٌ لا يُحسَب**: أولُ قياسٍ داخل النافذة يجب ألا يتأخّر عن
     * بدايتها بأكثر من ١٠٪ من طولها (تغطية ≥ ٩٠٪) — وإلا «لا توجد بيانات
     * تاريخية كافية» بلا SLI ولا حكمِ امتثال: امتثالُ ثلاثين يوماً من بيانات
     * ساعةٍ «99.9» زائفة (spec §26: لا أرقامَ مُختلَقة).
     *
     * النتيجةُ تُخبّأ ٥ دقائق والمفتاحُ يشمل قيمَ الإعدادات (تغييرُها يسري
     * فوراً بمفتاحٍ جديد) — قراءةُ مدرَّجاتِ نافذةِ أسابيعَ ليست كلفةَ كلِّ
     * فتحِ صفحة، والمدرَّجاتُ تُطوى عدّادين (سريع/كلّي) عبر cursor لا تحميلاً.
     */
    protected function slo(): array
    {
        // القراءاتُ حرفيةٌ عمداً — SettingsCenterTest يربط كلَّ مدخلٍ معروضٍ بقارئٍ حيّ
        $conf = [
            'availability' => trim((string) setting('slo.availability_pct', '')),
            'latency_ms'   => trim((string) setting('slo.latency_ms', '')),
            'latency_pct'  => trim((string) setting('slo.latency_pct', '')),
            'error_rate'   => trim((string) setting('slo.error_rate_pct', '')),
            'window'       => trim((string) setting('slo.window_days', '')),
        ];
        $days = is_numeric($conf['window']) ? (int) $conf['window'] : 0;
        $objAv = is_numeric($conf['availability']);
        $objLat = is_numeric($conf['latency_ms']) && is_numeric($conf['latency_pct']);
        $objErr = is_numeric($conf['error_rate']);
        if ($days < 1 || (! $objAv && ! $objLat && ! $objErr)) return ['on' => false];

        return Cache::remember('ops.slo:' . md5(implode('|', $conf)), 300,
            function () use ($conf, $days, $objAv, $objLat, $objErr) {
                $from = now()->subDays($days);
                $to = now();
                // حدُّ التغطية: أولُ قياسٍ بعد from + ١٠٪ من النافذة = تاريخٌ ناقص
                $covLimit = $from->copy()->addMinutes((int) round($days * 144));
                $short = fn ($firstAt) => 'القياس المتاح يبدأ '
                    . \Illuminate\Support\Carbon::parse($firstAt)->diffForHumans()
                    . ' ولا يغطّي نافذة ' . $days . ' يوماً';

                // ميزانيةُ الخطأ الواحدة: المسموح/المستهلَك/المتبقّي من العدّ الفعلي.
                // هدفٌ ١٠٠٪ ميزانيتُه صفر — عندها consumed_pct=null تعني «نفدت» لا «٠٪»
                $budget = function (float $allowed, int $bad) {
                    $pct = $allowed > 0 ? round($bad * 100 / $allowed, 1) : ($bad > 0 ? null : 0.0);

                    return [
                        'allowed' => round($allowed, 2), 'consumed' => $bad,
                        'consumed_pct' => $pct,
                        'remaining' => round(max(0.0, $allowed - $bad), 2),
                        'remaining_pct' => $pct === null ? 0.0 : max(0.0, round(100 - $pct, 1)),
                    ];
                };
                $mk = fn (string $key, string $icon, string $name, string $op, float $target,
                          bool $approx, string $unit, string $what) => [
                    'key' => $key, 'icon' => $icon, 'name' => $name, 'op' => $op,
                    'target' => $target, 'approx' => $approx, 'unit' => $unit, 'what' => $what,
                    'sli' => null, 'ok' => null, 'enough' => false, 'total' => 0, 'bad' => 0,
                    'budget' => null, 'why' => 'لا قياسَ مسجَّلاً في النافذة',
                ];
                $objectives = [];

                // ١) التوافر — عدٌّ وجمعٌ في القاعدة (فهرس metric, at)؛ صفٌّ واحد يعود
                if ($objAv) {
                    $o = $mk('availability', '🟢', 'التوافر', '≥', (float) $conf['availability'], false,
                        'فحصاً', 'نسبة فحوص المراقبة الناجحة عبر كل الأهداف المراقَبة');
                    try {
                        $r = DB::table('metric_points')->where('metric', 'up')
                            ->where('at', '>=', $from)->where('at', '<', $to)
                            ->selectRaw('COUNT(*) n, SUM(CASE WHEN value > 0 THEN 1 ELSE 0 END) good, MIN(at) first_at')
                            ->first();
                        $n = (int) ($r->n ?? 0);
                        if ($n > 0 && \Illuminate\Support\Carbon::parse($r->first_at)->gt($covLimit)) {
                            $o['why'] = $short($r->first_at);
                        } elseif ($n > 0) {
                            $bad = $n - (int) $r->good;
                            $o['enough'] = true;
                            $o['sli'] = round((int) $r->good * 100 / $n, 2);
                            $o['ok'] = $o['sli'] >= $o['target'];
                            $o['total'] = $n;
                            $o['bad'] = $bad;
                            $o['budget'] = $budget($n * (100 - $o['target']) / 100, $bad);
                            $o['why'] = '';
                        }
                    } catch (\Throwable $e) {
                        // تحمّلُ العطل (§25): قارئُ بطاقةٍ لا يُسقط الشاشة
                    }
                    $objectives[] = $o;
                }

                // مجاميعُ الحاويات المشتركة لهدفَي الزمن والأخطاء — استعلامٌ واحد
                $bAgg = null;
                if ($objLat || $objErr) {
                    try {
                        $bAgg = DB::table('http_metric_buckets')
                            ->where('bucket_at', '>=', $from)->where('bucket_at', '<', $to)
                            ->selectRaw('SUM(count) n, SUM(err5) e5, MIN(bucket_at) first_at')->first();
                    } catch (\Throwable $e) {
                        $bAgg = null;   // جدولٌ لم يُرحَّل بعد ⇒ حالةُ «لا قياس» الصادقة
                    }
                }
                $bN = (int) ($bAgg->n ?? 0);
                $bCovered = $bN > 0 && ! \Illuminate\Support\Carbon::parse($bAgg->first_at)->gt($covLimit);

                // ٢) زمنُ الاستجابة — طيُّ المدرَّجات عدّادين (سريع/كلّي) عبر cursor
                if ($objLat) {
                    $thr = (float) $conf['latency_ms'];
                    $o = $mk('latency', '⏱️', 'زمن الاستجابة', '≥', (float) $conf['latency_pct'], true,
                        'طلباً', 'نسبة طلبات HTTP الأسرع من ' . number_format($thr) . 'ms — من المدرَّج اللوغاريتمي');
                    if ($bN > 0 && ! $bCovered) {
                        $o['why'] = $short($bAgg->first_at);
                    } elseif ($bCovered && hub_has_col('http_metric_buckets', 'hist')) {
                        try {
                            $good = 0;
                            $tot = 0;
                            $rows = DB::table('http_metric_buckets')->select('hist')
                                ->where('bucket_at', '>=', $from)->where('bucket_at', '<', $to)
                                ->whereNotNull('hist')->orderBy('id')->cursor();
                            foreach ($rows as $hRow) {
                                $dec = json_decode((string) $hRow->hist, true);
                                if (! is_array($dec)) continue;
                                foreach ($dec as $i => $c) {
                                    $c = (int) $c;
                                    if ($c <= 0) continue;
                                    $tot += $c;
                                    if (Series::mid((int) $i) <= $thr) $good += $c;
                                }
                            }
                            if ($tot > 0) {
                                $o['enough'] = true;
                                $o['sli'] = round($good * 100 / $tot, 2);
                                $o['ok'] = $o['sli'] >= $o['target'];
                                $o['total'] = $tot;
                                $o['bad'] = $tot - $good;
                                $o['budget'] = $budget($tot * (100 - $o['target']) / 100, $tot - $good);
                                $o['why'] = '';
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                    $objectives[] = $o;
                }

                // ٣) نسبةُ أخطاء الخادم — من المجاميع نفسِها، بلا استعلامٍ ثانٍ
                if ($objErr) {
                    $o = $mk('errors', '🚫', 'نسبة أخطاء الخادم', '≤', (float) $conf['error_rate'], false,
                        'طلباً', 'نسبة ردود 5xx من إجمالي طلبات HTTP — أخطاء 4xx ذنبُ الطالب فلا تُحسب');
                    if ($bN > 0 && ! $bCovered) {
                        $o['why'] = $short($bAgg->first_at);
                    } elseif ($bCovered) {
                        $e5 = (int) ($bAgg->e5 ?? 0);
                        $o['enough'] = true;
                        $o['sli'] = round($e5 * 100 / $bN, 2);
                        $o['ok'] = $o['sli'] <= $o['target'];
                        $o['total'] = $bN;
                        $o['bad'] = $e5;
                        $o['budget'] = $budget($bN * $o['target'] / 100, $e5);
                        $o['why'] = '';
                    }
                    $objectives[] = $o;
                }

                return ['on' => true, 'days' => $days, 'objectives' => $objectives];
            });
    }

    /**
     * (WP-2.4 · spec §3.4–§3.6/§36 · critic #27) قارئُ أداء المسارات والانحدار.
     *
     * **التجميعُ في القاعدة لا في PHP**: خطوتان لا ثالثة لهما —
     * (١) `GROUP BY route, method` داخل TimeRange مع `SUM(count)/SUM(sum_ms)/
     * MAX(max_ms)/SUM(err4)/SUM(err5)` وترتيبٍ بقائمةٍ بيضاء و`LIMIT` (صفحةُ
     * الجدول)؛ (٢) ثم قراءةُ `hist` **لصفوف الصفحة المعروضة وحدها** — دمجُ
     * المدرَّجات لكل النافذة في الذاكرة كان يعني عشراتِ آلاف صفوف JSON لكل
     * فتحةِ صفحة (٧ أيام × ٢٨٨ حاوية × مئات المسارات).
     *
     * النسبُ المئوية من المدرَّج اللوغاريتمي (Series::percentiles) **تقريبيةٌ
     * بحدّ خطأٍ مُعلَن** (±7.5٪ — القرار ق٥)، والشاشةُ توسمها كذلك.
     *
     * الانحدار (spec §36): نافذتان متساويتان متجاورتان (hub_window_pair) بطول
     * المدى المختار، والمقارنةُ بـhub_compare — **بحارس حدٍّ أدنى من الملاحظات**
     * (ops.regression_min_n) قبل أي وسم: عيّنةٌ هزيلة ⇒ «لا توجد بيانات تاريخية
     * كافية» لا نسبةٌ مُختلَقة. وانحدارُ الأخطاء من err4/err5 للحاويات ومعه
     * دليلُ error_events (SUM(count) حسب kind في النافذتين).
     */
    protected function routesPerf(Request $r): array
    {
        $range = hub_range($r);
        $out = ['range' => $range, 'rows' => null, 'sort' => 'hits', 'dir' => 'desc', 'reg' => null];
        // حارسُ مخططٍ مخبّأ — قاعدةٌ لم تُرحَّل بعدُ تعرض حالةً فارغة لا شاشةً ساقطة
        if (! hub_has_col('http_metric_buckets', 'hist')) return $out;

        // ١) القائمة البيضاء للفرز (نمط ErrorCenterController): المفتاح ⇒ تعبيرُ الترتيب.
        // المجهولُ يرتدّ للافتراضي بصمت — معاملُ رابطٍ لا يصل SQL ولا يُسقط شاشة.
        $aggs = [
            'hits' => 'SUM(count)',
            'avg'  => 'SUM(sum_ms) * 1.0 / SUM(count)',   // ‏*1.0: قسمةُ الأعداد الصحيحة في SQLite تُبتر
            'max'  => 'MAX(max_ms)',
            'err4' => 'SUM(err4)',
            'err5' => 'SUM(err5)',
            'slow' => 'SUM(slow)',
            'route' => 'route',
        ];
        $sort = hub_str($r->query('sort'));
        if (! array_key_exists($sort, $aggs)) $sort = 'hits';
        $dir = strtolower(hub_str($r->query('dir'))) === 'asc' ? 'asc' : 'desc';
        $out['sort'] = $sort;
        $out['dir'] = $dir;

        // ٢) صفحةُ الجدول مجمَّعةً في القاعدة — والترتيبُ حاسمٌ دائماً: مفتاحُ
        // المجموعة (route, method) فاصلُ تعادلٍ فريد، فلا قرعةَ ترتيبٍ بين المحرّكين.
        $rows = $range->apply(DB::table('http_metric_buckets'), 'bucket_at')
            ->selectRaw('route, method, SUM(count) hits, SUM(sum_ms) sum_ms, MAX(max_ms) max_ms,'
                . ' SUM(err4) err4, SUM(err5) err5, SUM(slow) slow')
            ->groupBy('route', 'method')
            ->orderByRaw($aggs[$sort] . ($dir === 'asc' ? ' ASC' : ' DESC'))
            ->orderBy('route')->orderBy('method')
            ->paginate(15)->withQueryString();

        // ٣) hist لصفوف الصفحة المعروضة **وحدها** — استعلامٌ واحد لا حلقة
        $hists = [];
        if ($rows->count()) {
            $q = $range->apply(DB::table('http_metric_buckets'), 'bucket_at')
                ->select('route', 'method', 'hist')->whereNotNull('hist')
                ->where(function ($w) use ($rows) {
                    foreach ($rows as $row) {
                        $w->orWhere(fn ($x) => $x->where('route', $row->route)->where('method', $row->method));
                    }
                });
            foreach ($q->get() as $h) {   // الدمجُ تبادليّ فلا يلزم ترتيبُ صفوفه
                $dec = json_decode((string) $h->hist, true);
                $k = $h->method . ' ' . $h->route;
                $hists[$k] = Series::mergeHist($hists[$k] ?? [], is_array($dec) ? $dec : []);
            }
        }
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($hists) {
            $p = Series::percentiles($hists[$row->method . ' ' . $row->route] ?? []);
            $row->p50 = $p['p50'];
            $row->p95 = $p['p95'];
            $row->p99 = $p['p99'];
            $row->avg = (int) $row->hits > 0 ? (int) round((int) $row->sum_ms / (int) $row->hits) : null;
            return $row;
        }));
        $out['rows'] = $rows;

        // ٤) الانحدار: نافذتان متجاورتان بطول المدى المختار تنتهيان الآن —
        // (WP-2.7) مخبّأٌ ٦٠ ثانية (٤ استعلاماتِ نوافذَ لا تتغيّر بين ضغطتين)
        // وقارئاه ملفوفان (§25): جدولٌ ساقط يُرجع «لا بيانات كافية» الصادقة لا ٥٠٠
        $hours = max(1, (int) round($range->minutes() / 60));
        $out['reg'] = hub_screen('ops.reg:' . $hours, 60, function () use ($hours) {
            $minN = max(1, (int) setting('ops.regression_min_n', 100));
            $thr = max(1, (int) setting('ops.regression_pct', 30));
            $pair = hub_window_pair($hours);
            $win = function (array $w) {
                try {
                    $t = DB::table('http_metric_buckets')
                        ->selectRaw('SUM(count) n, SUM(sum_ms) s, SUM(err4) e4, SUM(err5) e5')
                        ->where('bucket_at', '>=', $w[0])->where('bucket_at', '<', $w[1])->first();
                } catch (\Throwable $e) {
                    return ['n' => 0, 'avg' => null, 'rate' => null];   // §25 ⇒ «عيّنة صفر» الصادقة
                }
                $n = (int) ($t->n ?? 0);
                return [
                    'n' => $n,
                    'avg' => $n > 0 ? (int) round((int) $t->s / $n) : null,
                    'rate' => $n > 0 ? round(((int) $t->e4 + (int) $t->e5) * 100.0 / $n, 1) : null,
                ];
            };
            $cur = $win($pair['cur']);
            $prev = $win($pair['prev']);
            $enough = $cur['n'] >= $minN && $prev['n'] >= $minN;   // حارسُ العيّنة قبل أي حكم
            $perf = $enough ? hub_compare((float) $cur['avg'], (float) $prev['avg']) : null;
            $err = $enough ? hub_compare((float) $cur['rate'], (float) $prev['rate']) : null;

            // دليلُ الأنواع: SUM(count) حسب kind في كل نافذة — الترتيبُ بمفتاح المجموعة؛
            // ملفوفٌ (§25): إسقاطُ error_events كان يُسقط الشاشةَ كلَّها من هذه الحلقة
            $kinds = [];
            try {
                foreach (['cur', 'prev'] as $k) {
                    $byKind = DB::table('error_events')->selectRaw('kind, SUM(count) c')
                        ->where('last_seen', '>=', $pair[$k][0])->where('last_seen', '<', $pair[$k][1])
                        ->groupBy('kind')->orderBy('kind')->get();
                    foreach ($byKind as $row) $kinds[$row->kind][$k] = (int) $row->c;
                }
            } catch (\Throwable $e) {
            }
            ksort($kinds);

            return [
                'hours' => $hours, 'min_n' => $minN, 'thr' => $thr, 'enough' => $enough,
                'cur' => $cur, 'prev' => $prev, 'perf' => $perf, 'err' => $err, 'kinds' => $kinds,
                'perf_flag' => $enough && $perf['pct'] !== null && $perf['pct'] >= $thr,
                // نسبةُ أخطاءٍ سابقة صفر: hub_compare يردّ pct=null بصدق — والارتفاعُ من
                // الصفر مع عيّنةٍ كافية انحدارٌ يُوسَم بقيمتَيه لا بنسبةٍ مُختلَقة
                'err_flag' => $enough && ($err['pct'] !== null
                    ? $err['pct'] >= $thr
                    : ((float) $prev['rate'] === 0.0 && (float) $cur['rate'] > 0.0)),
            ];
        });

        return $out;
    }

    /** نسخة احتياطية فورية بضغطة — دورة «النشر بلا طرفية» تكتمل بها */
    public function backupNow()
    {
        $this->gate();
        @set_time_limit(300);

        try {
            // رمزُ الخروج يُقرأ (v2.399): كان الفشلُ يُعرض بصيغة «أُخذت نسخة …» ثم نصُّ الخطأ
            $code = \Illuminate\Support\Facades\Artisan::call('hub:backup');
            if ($code !== 0) {
                return redirect()->route('ops.index')->with('err', 'فشل النسخ: ' . mb_substr(trim(\Illuminate\Support\Facades\Artisan::output()), 0, 300));
            }
            hub_audit('نسخة احتياطية يدوية', null, null, 'من مركز التشغيل');
            hub_data_bump('backups');   // ختمُ لوحة النسخ (WP-2.7) — النسخةُ الجديدة تظهر فوراً لا بعد ٦٠ ثانية

            return redirect()->route('ops.index')
                ->with('ok', 'أُخذت نسخة احتياطية الآن: ' . mb_substr(trim(\Illuminate\Support\Facades\Artisan::output()), 0, 300));
        } catch (\Throwable $e) {
            return redirect()->route('ops.index')->with('err', 'فشل النسخ: ' . mb_substr($e->getMessage(), 0, 300));
        }
    }

    /** تبديل وضع الصيانة بضغطة — يقفل النظام على غير المالكين برسالة مهذبة */
    public function toggleMaintenance()
    {
        $this->gate();
        if ($resp = hub_require_ops_stepup()) return $resp;   // فعلٌ عالي الأثر: تأكيدُ هوية (v2.399)
        $on = ! (bool) setting('maintenance.on', false);
        \App\Models\Setting::updateOrCreate(['key' => 'maintenance.on'], ['value' => $on ? '1' : '']);
        Cache::forget('settings:all');
        hub_audit($on ? 'تفعيل وضع الصيانة' : 'إنهاء وضع الصيانة', null, null, 'من مركز التشغيل');

        return redirect()->route('ops.index')
            ->with('ok', $on ? 'فُعّل وضع الصيانة — الموظفون يرون رسالة الصيانة الآن' : 'أُنهي وضع الصيانة — عاد النظام للجميع');
    }

    /**
     * تشغيل الترحيلات المعلقة من المتصفح — للمالك فقط، بلا طرفية.
     * وُلد من حادثة حقيقية: كودٌ نُشر قبل هجرته فانكسر كل قيد تدقيق حتى الخروج.
     */
    public function migrate()
    {
        $this->gate();
        if ($resp = hub_require_ops_stepup()) return $resp;   // فعلٌ عالي الأثر: تأكيدُ هوية (v2.399)
        @set_time_limit(300);

        /*
         * **نسخةٌ قبل الترحيل**: الترحيلُ يُشغَّل على القاعدة الحيّة، وشبكةٌ تحته
         * أرخصُ من ندمٍ فوقه. وفشلُ النسخ لا يمنع الترحيل — لكنه يُقال صراحةً
         * في النتيجة، فلا يظنّ أحدٌ أن له نسخةً وليست له.
         */
        $backup = '';
        // تُطفأ بإعداد `ops.backup_before_migrate=0` لقاعدةٍ ضخمة تُثقلها النسخة،
        // وتُتخطّى داخل الحزمة (الاختبارات تُرحّل عشرات المرات ولا بياناتٍ تُفقد) —
        // إلا حين يُفعّلها اختبارٌ صراحةً بـ`hub.testing.allow_backup_before_migrate`
        // ليحرس صدقَ الرسالة على مسارها الحقيقيّ.
        $skipInTests = app()->runningUnitTests() && ! config('hub.testing.allow_backup_before_migrate', false);
        if (! $skipInTests && setting('ops.backup_before_migrate', '1') !== '0') {
            try {
                // **صدقُ الرسالة**: `Artisan::call` يعيد رمزَ الخروج ولا يرمي عند
                // فشلٍ داخليّ (نسخةٌ لم تُكتب تعيد FAILURE بلا استثناء) — فكانت
                // «✅ أُخذت نسخة» تُطبع مهما كان العائد، كذبٌ في اللحظة الوحيدة التي
                // تُهمّ فيها الحقيقة. الآن يُقرأ الرمز.
                $code = \Illuminate\Support\Facades\Artisan::call('hub:backup');
                $backup = $code === 0
                    ? '✅ أُخذت نسخةٌ احتياطية قبل الترحيل.'
                    : '⚠️ فشلت النسخة الاحتياطية قبل الترحيل (رمز ' . $code . ': '
                        . mb_substr(trim(\Illuminate\Support\Facades\Artisan::output()), 0, 160)
                        . ') — الترحيلُ مضى بلا شبكةٍ تحته.';
            } catch (\Throwable $e) {
                $backup = '⚠️ تعذّرت النسخة الاحتياطية قبل الترحيل ('
                    . mb_substr($e->getMessage(), 0, 120) . ') — الترحيلُ مضى بلا شبكةٍ تحته.';
            }
        }

        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            $out = trim(\Illuminate\Support\Facades\Artisan::output());
            hub_audit('تشغيل الترحيلات', null, null, 'من مركز التشغيل');
            // مخطط الجدول ربما تغيّر للتو — تُنسى خبيئة الأعمدة فيُلتقط الجديد فوراً
            \App\Models\AuditEntry::forgetColumnCache();
            Cache::forget('hub.pending_migrations');   // شارة التحذير تختفي فوراً

            // وفروقاتُ ما بعد الترحيل تُقال: عمودٌ يقرؤه الكود ولم تُنشئه هجرة
            $gaps = \App\Support\SchemaGuard::gaps();

            hub_data_bump('migrations');   // ختمُ لوحة الترحيلات (WP-2.7) — العدّادُ الجديد يظهر فوراً

            return redirect()->route('ops.index')
                ->with('ok', 'اكتمل الترحيل بنجاح' . ($gaps
                    ? ' — لكن بقي ' . count($gaps) . ' فرقاً بين ما يقرؤه الكود وما تملكه القاعدة؛ '
                      . 'شغّل hub:schema-check لتفصيلها'
                    : ' ولا فروقات بين الكود والقاعدة'))
                ->with('migrate_out', trim($backup . "\n\n" . mb_substr($out, 0, 4000)));
        } catch (\Throwable $e) {
            return redirect()->route('ops.index')
                ->with('err', 'فشل الترحيل: ' . mb_substr($e->getMessage(), 0, 300));
        }
    }

    /**
     * فحصٌ صحيّ عامّ — JSON لمراقبات خارجية، على نموذج الصحّة الواحد.
     *
     *   GET /healthz             الصحّةُ الكاملة (حالاتٌ بلا تفاصيل للمجهول — لا أرقامَ ولا رسائل)
     *   GET /healthz?probe=live  حياة: العمليةُ تُجيب (٢٠٠ دائماً إن أقلع التطبيق)
     *   GET /healthz?probe=ready جاهزية: قاعدة/خبيئة/تخزين/مخطّط/إعداد — ٥٠٣ إن غاب ما لا يُخدَم بدونه
     *
     * الشكلُ القديم (`status: ok|degraded`، `checks.{db,cache,storage}: ok|fail`) محفوظٌ
     * حرفياً لمراقباتٍ مضبوطة عليه، ويُضاف `health` (الحالة القياسية) و`components`.
     * ٥٠٣ عند «متعطّل» فقط؛ «متدهور» يخدم الطلبات فيُعاد ٢٠٠ بحالته الصريحة.
     */
    public function health(\Illuminate\Http\Request $r)
    {
        $probe = (string) $r->query('probe', '');
        if ($probe === 'live') {
            return response()->json(['status' => 'ok', 'probe' => 'live'] + \App\Support\Health::live());
        }

        $full = $probe === 'ready' ? \App\Support\Health::ready() : \App\Support\Health::check();
        $pub = \App\Support\Health::publicView($full);
        $c = $full['components'];
        $legacy = fn (string $k) => in_array($c[$k]['status'] ?? \App\Support\Health::UNKNOWN,
            [\App\Support\Health::HEALTHY, \App\Support\Health::DEGRADED, \App\Support\Health::MAINTENANCE], true) ? 'ok' : 'fail';
        $checks = ['db' => $legacy('db'), 'cache' => $legacy('cache'), 'storage' => $legacy('storage')];
        // **رمزُ HTTP من الجاهزية وحدها**: ٥٠٣ حين لا يُخدَم طلبٌ صحيح (قاعدة/خبيئة/تخزين/إعداد).
        // أمّا المجدولاتُ والطوابيرُ والتكاملات فتُقال «متدهورة» في `health`/`components` —
        // مراقبةُ Uptime تُنبّه على «الموقع لا يخدم» لا على «cron لم يُضبط بعد»، وذاك شأنُ مركز التشغيل.
        $readiness = \App\Support\Health::readinessOf($full);
        $down = $readiness === \App\Support\Health::UNAVAILABLE;

        return response()->json([
            // `status` بدلالته القديمة حرفياً (ok ما لم يفشل فحصٌ من الثلاثة) — العقدُ القديم لا يتبدّل؛
            // والدلالةُ الأغنى في `health`/`ready`/`components`
            'status' => in_array('fail', $checks, true) ? 'degraded' : 'ok',
            'health' => $pub['status'], 'ready' => ! $down, 'probe' => $probe ?: 'full',
            'checks' => $checks, 'components' => $pub['components'],
            'version' => $pub['version'], 'at' => $pub['at'],
        ], $down ? 503 : 200);
    }

    /** كتيّباتُ التشغيل (docs/RUNBOOKS.md) مُصيَّرةً — الملفُّ نفسُه هو المصدر فلا نسخةٌ ثانية تتقادم */
    public function runbooks()
    {
        $this->gate();
        $md = (string) @file_get_contents(base_path('docs/RUNBOOKS.md'));
        // محتوىً ثابت من المستودع لا مدخلاتُ مستخدم — تصييرٌ آمن بلا HTML خام
        $html = $md !== '' ? (string) \Illuminate\Support\Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : '<p>لا كتيّبات بعد.</p>';

        return view('ops.runbooks', ['html' => $html]);
    }

    /** الصحّةُ بتفاصيلها (رسائلُ وأرقامٌ وآخرُ أخطاء) — للمالك، JSON للأتمتة والشاشة */
    public function healthDetail()
    {
        $this->gate();

        return response()->json(\App\Support\Health::check() + ['dependencies' => \App\Support\Health::dependencies()],
            200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * مسح كاش النظام من المتصفح — شقيق زر الترحيلات في دورة «النشر بلا طرفية».
     * optimize:clear يمسح المهيّأ كله: الإعدادات والمسارات والقوالب المجمّعة وكاش البيانات.
     */
    public function clearCache()
    {
        $this->gate();
        if ($resp = hub_require_ops_stepup()) return $resp;   // فعلٌ عالي الأثر: تأكيدُ هوية (v2.399)

        try {
            \Illuminate\Support\Facades\Artisan::call('optimize:clear');
            hub_audit('مسح الكاش', null, null, 'من مركز التشغيل');

            return redirect()->route('ops.index')
                ->with('ok', 'مُسح الكاش كله: الإعدادات والمسارات والقوالب وكاش البيانات');
        } catch (\Throwable $e) {
            return redirect()->route('ops.index')
                ->with('err', 'فشل مسح الكاش: ' . mb_substr($e->getMessage(), 0, 300));
        }
    }

    /**
     * **فاحصان كانا يُرشَد إليهما بطرفيةٍ لا يملكها صاحبُ النظام.**
     *
     * هذا النظامُ يُرفع ملفّاتٍ على استضافةٍ مشتركة بلا shell — وكانت شاشةُ
     * التدقيق تقول «شغّل `php artisan hub:audit-verify`» ومركزُ التشغيل يقول
     * «شغّل `hub:schema-check`». فالنتيجةُ أنّ سلسلةَ التدقيق لا تُفحص أبداً
     * وانحرافَ المخطّط لا يُكشف أبداً، بينما الشاشتان تُوهمان بوجود فاحص.
     * وضمانٌ معلَنٌ بلا فاحصٍ يُسكِت السؤالَ الذي كان سيكشف الخلل.
     *
     * والمخرَجُ يُعرض كما هو — الفاحصُ يقول ما وجد، ولا يُعاد صوغُ حكمه.
     */
    public function verifyAudit()
    {
        $this->gate();

        $code = \Illuminate\Support\Facades\Artisan::call('hub:audit-verify');
        $out = trim(\Illuminate\Support\Facades\Artisan::output());
        hub_audit('فحص سلسلة التدقيق', null, null, 'من مركز التشغيل');

        return redirect()->route('ops.index')
            ->with($code === 0 ? 'ok' : 'err', $out !== '' ? $out : 'الفاحصُ لم يُخرج شيئاً');
    }

    /** فحصُ انحراف المخطّط — الشقيقُ نفسُه: زرٌّ بدل سطرِ طرفية */
    public function schemaCheck()
    {
        $this->gate();

        $code = \Illuminate\Support\Facades\Artisan::call('hub:schema-check');
        $out = trim(\Illuminate\Support\Facades\Artisan::output());

        return redirect()->route('ops.index')
            ->with($code === 0 ? 'ok' : 'err', $out !== '' ? $out : 'لا انحرافَ في المخطّط');
    }

    /** توليد عدّة الانطلاق بضغطة: مسارات العمل + قواعد التنبيه — بلا طرفية */
    public function starters()
    {
        $this->gate();

        \Illuminate\Support\Facades\Artisan::call('hub:flows-starter');
        $out = trim(\Illuminate\Support\Facades\Artisan::output());
        \Illuminate\Support\Facades\Artisan::call('hub:alerts-starter');
        $out .= "\n" . trim(\Illuminate\Support\Facades\Artisan::output());
        \Illuminate\Support\Facades\Artisan::call('hub:kpis-starter', ['--all' => true]);
        $out .= "\n" . trim(\Illuminate\Support\Facades\Artisan::output());
        hub_audit('توليد عدة الانطلاق', null, null, 'من مركز التشغيل');

        return redirect()->route('ops.index')->with('ok', $out);
    }

    /* ── Control Plane: Phase 2 (WP-2.6) — قارئا الاعتماديات والصادر + فعلُ الإعادة ── */

    /**
     * بطاقاتُ الاعتماديات من **الإعداد الفعليّ** وحده: البنيةُ (قاعدة/كاش/تخزين/طابور)
     * من مكوّنات نموذج الصحّة الممرَّر (لا نداءَ Health::check ثانياً — ٥٥ استعلاماً)،
     * والتكاملاتُ من `Integrations::installed()` مع إسقاط ما لم يُضبط بعد
     * (CONFIGURATION_REQUIRED ليس اعتماديةً بل إمكانية)، والبريدُ فقط إن كان
     * المُرسِلُ حقيقياً — `log/array` نجاحُه كاذبٌ فلا بطاقةَ تطمئن به.
     * كلُّ بطاقة: الاسم/النوع/الصحّة/زمن آخر نداء/آخر نجاح/آخر فشل.
     */
    public static function dependencyCards(array $health): array
    {
        $H = \App\Support\Health::class;
        $cards = [];

        // البنية من مكوّنات الصحّة — المكوّن outbox هو «الطابور» بلسان الاعتماديات
        $core = ['db' => ['🗄️', 'قاعدة البيانات'], 'cache' => ['⚡', 'الكاش'],
                 'storage' => ['💽', 'التخزين'], 'outbox' => ['📨', 'طابور الرسائل']];
        foreach ($core as $key => [$icon, $name]) {
            $c = $health['components'][$key] ?? null;
            if (! $c) continue;
            $cards[] = [
                'id' => 'dep-core-' . ($key === 'outbox' ? 'queue' : $key),
                'icon' => $icon, 'name' => $name, 'type' => 'بنية',
                'health' => $H::LABELS[$c['status']] ?? $c['status'], 'tone' => $c['tone'] ?? 'g',
                'ms' => isset($c['data']['ms']) ? (int) $c['data']['ms'] : null,
                'ok_at' => null, 'fail_at' => null, 'error' => null, 'why' => $c['why'] ?? '',
            ];
        }

        // التكاملات المضبوطة فعلاً — من السجل الواحد لا من قائمةٍ ثانية
        try {
            $dirs = [\App\Support\Integrations::IN => 'وارد', \App\Support\Integrations::OUT => 'صادر',
                     \App\Support\Integrations::BOTH => 'الاتجاهان'];
            foreach (\App\Support\Integrations::installed() as $key => $i) {
                if (($i['health'] ?? '') === \App\Support\Integrations::CONFIGURATION_REQUIRED) continue;
                $cards[] = [
                    'id' => 'dep-' . $key, 'icon' => $i['icon'], 'name' => $i['name'],
                    'type' => 'تكامل ' . ($dirs[$i['dir']] ?? $i['dir']),
                    'health' => \App\Support\Integrations::HEALTH_LABELS[$i['health']] ?? $i['health'],
                    'tone' => \App\Support\Integrations::HEALTH_TONE[$i['health']] ?? 'g',
                    'ms' => \App\Support\Integrations::lastMs($key),
                    'ok_at' => $i['last_ok_at'], 'fail_at' => $i['last_fail_at'],
                    'error' => $i['last_error'], 'why' => $i['state'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            // تحمّلُ العطل (§25): سقوطُ قارئٍ لا يُسقط الشاشة
        }

        // البريدُ الصادر — فقط حين لا يكون log/array، وحكمُه من طوابع outbox الحقيقية
        try {
            $mailer = (string) config('mail.default');
            if (! in_array($mailer, ['log', 'array'], true)) {
                $ok = DB::table('outbox')->where('channel', 'mail')->where('state', 'sent')->max('delivered_at');
                $fail = DB::table('outbox')->where('channel', 'mail')->where('state', 'failed')->max('created_at');
                $err = $fail ? DB::table('outbox')->where('channel', 'mail')->where('state', 'failed')
                    ->orderByDesc('created_at')->orderByDesc('id')->value('error') : null;
                $j = \App\Support\Integrations::judge($ok ? (string) $ok : null, $fail ? (string) $fail : null);
                $cards[] = [
                    'id' => 'dep-mail', 'icon' => '📧', 'name' => 'البريد الصادر (' . $mailer . ')',
                    'type' => 'صادر',
                    'health' => \App\Support\Integrations::HEALTH_LABELS[$j] ?? $j,
                    'tone' => \App\Support\Integrations::HEALTH_TONE[$j] ?? 'g',
                    'ms' => null, 'ok_at' => $ok, 'fail_at' => $fail,
                    'error' => $err ? mb_substr(\App\Support\Redactor::text((string) $err), 0, 180) : null,
                    'why' => 'مُرسِلٌ حقيقيّ — يغادر الخادمَ فعلاً',
                ];
            }
        } catch (\Throwable $e) {
        }

        return $cards;
    }

    /**
     * أرقامُ الصندوق الصادر للتشغيل: أقدمُ/أحدثُ منتظرة، الإنتاجية (المُسلَّم/ساعة من
     * `delivered_at` — نافذتا hub_window_pair للمقارنة الصادقة عبر hub_compare)،
     * الفشلُ الحديث، والمحاولات. كلُّه عدٌّ في القاعدة — لا صفوفَ تُجلب للعدّ.
     */
    public static function outboxOps(): array
    {
        // (WP-2.7 · §25) 'ok' راية الصدق: قارئٌ ساقطٌ يعيدها false فيقول القسم
        // «غير متاح» — لا أصفاراً كاذبة توهم أن الطابور فارغ وجدولُه غائب
        $out = ['ok' => false, 'pending' => 0, 'oldest' => null, 'newest' => null,
                'delivered' => ['cur' => 0, 'prev' => null, 'pct' => null], 'per_hour' => null,
                'failed_recent' => 0, 'fail_per_hour' => null,
                'attempts_avg' => null, 'attempts_max' => null, 'failed' => collect()];
        try {
            $q = DB::table('outbox')->where('state', 'queued');
            $out['pending'] = (clone $q)->count();
            $out['oldest'] = (clone $q)->min('created_at');
            $out['newest'] = (clone $q)->max('created_at');

            // نافذتان متساويتان (WP-1.6): ٢٤ ساعةً حاليّة ومثلُها قبلها — للمقارنة لا للزينة
            ['cur' => [$f, $t], 'prev' => [$pf, $pt]] = hub_window_pair(24);
            $cur = (int) DB::table('outbox')->where('state', 'sent')
                ->where('delivered_at', '>=', $f)->where('delivered_at', '<', $t)->count();
            $prev = (int) DB::table('outbox')->where('state', 'sent')
                ->where('delivered_at', '>=', $pf)->where('delivered_at', '<', $pt)->count();
            $out['delivered'] = hub_compare((float) $cur, (float) $prev);
            $out['per_hour'] = round($cur / 24, 1);

            // لا طابعَ فشلٍ في المخطّط (delivered_at للمُسلَّم وحده) — فالصادقُ عدُّ
            // الفاشلات المُنشأةِ خلال النافذة، ويُقال ذلك في الشاشة نصاً
            $out['failed_recent'] = (int) DB::table('outbox')->where('state', 'failed')
                ->where('created_at', '>=', $f)->where('created_at', '<', $t)->count();
            $out['fail_per_hour'] = round($out['failed_recent'] / 24, 1);

            if (hub_has_col('outbox', 'attempts')) {
                $a = DB::table('outbox')->where('state', 'failed')
                    ->selectRaw('AVG(attempts) AS a, MAX(attempts) AS m')->first();
                $out['attempts_avg'] = $a && $a->a !== null ? round((float) $a->a, 1) : null;
                $out['attempts_max'] = $a && $a->m !== null ? (int) $a->m : null;
            }

            // معاينةُ الفشل: أحدثُ ٨ — النصُّ والوجهةُ يمرّان بمقنِّع WP-2.6 في العرض
            $out['failed'] = \App\Models\OutboxMessage::where('state', 'failed')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get();
            $out['ok'] = true;
        } catch (\Throwable $e) {
            // تحمّلُ العطل: جدولٌ غائب/قاعدةٌ ساقطة لا تُسقط مركزَ التشغيل
        }

        return $out;
    }

    /**
     * إعادةُ رسالةٍ صادرةٍ **واحدة** من المتصفّح: تُعاد إلى الطابور ثم يُسلِّمها
     * العاملُ القائم نفسُه (`hub:outbox --only=<id>`) — لا مسارَ إرسالٍ ثانٍ.
     * فعلٌ عالي الأثر: مالكٌ + تأكيدُ هوية + قيدُ تدقيق + حدُّ معدل (على المسار).
     */
    public function outboxRetry(string $id)
    {
        $this->gate();
        if ($resp = hub_require_ops_stepup()) return $resp;

        $msg = \App\Models\OutboxMessage::find($id);
        abort_unless($msg, 404);
        if ($msg->state !== 'failed') {
            return redirect()->route('ops.index')->with('err', 'تُعاد الرسائلُ الفاشلة وحدها — هذه حالتها: ' . $msg->state);
        }

        // الوجهةُ لا تُكتب في التدقيق إلا مقنَّعة — القيدُ يُقرأ بصلاحية «تدقيق» أوسعَ من «مالك»
        hub_audit('إعادة إرسال رسالة صادرة', null, null,
            'قناة ' . $msg->channel . ' · نوع ' . $msg->kind . ' · الوجهة ' . \App\Support\Integrations::maskDestination($msg->target));

        $reset = ['state' => 'queued', 'error' => null];
        if (hub_has_col('outbox', 'attempts')) $reset += ['attempts' => 0, 'next_at' => null];
        $msg->forceFill($reset)->save();

        try {
            \Illuminate\Support\Facades\Artisan::call('hub:outbox', ['--only' => $msg->id]);
        } catch (\Throwable $e) {
            return redirect()->route('ops.index')->with('err', 'تعذّر تشغيل العامل: ' . mb_substr(\App\Support\Redactor::text($e->getMessage()), 0, 200));
        }

        $fresh = $msg->fresh();
        hub_data_bump('outbox');   // ختمُ أرقام الصادر (WP-2.7) — نتيجةُ الإعادة تُحكى فوراً لا بعد ٦٠ ثانية

        return redirect()->route('ops.index')->with($fresh->state === 'sent' ? 'ok' : 'err',
            $fresh->state === 'sent'
                ? 'أُعيد الإرسال وسُلِّمت الرسالة'
                : 'أُعيدت المحاولة وفشلت: ' . mb_substr((string) $fresh->error, 0, 200));
    }

    /** توليد خطأ تجريبي للتحقق من مركز الأخطاء (عبر مسار الإبلاغ نفسه، دون صفحة 500) */
    public function testError()
    {
        $this->gate();
        report(new \RuntimeException('خطأ تجريبي من مركز التشغيل — إن رأيته في مركز الأخطاء فالالتقاط يعمل'));

        return redirect()->route('errors.index')->with('ok', 'وُلّد خطأ تجريبي — يظهر أدناه إن كان الالتقاط يعمل');
    }
}

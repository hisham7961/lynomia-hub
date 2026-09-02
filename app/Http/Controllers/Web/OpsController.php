<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use App\Support\SysMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** مركز مراقبة وتشغيل النظام — عين المالك التقنية */
class OpsController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز التشغيل للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

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

        // طوابير الرسائل
        $outbox = DB::table('outbox')->select('state', DB::raw('COUNT(*) c'))->groupBy('state')->pluck('c', 'state');

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

        // آخر نسخة احتياطية
        // بالأحدث زمنياً لا بترتيب الاسم: ملف بتسمية يدوية كان يتصدر الترتيب
        // الأبجدي فيُعرض كآخر نسخة ويوهم أن النسخ تعمل وهي متوقفة.
        $bk = collect(glob(storage_path('app/backups/hub-*.json')))
            ->sortBy(fn ($f) => filemtime($f))->last();
        $backup = $bk ? ['name' => basename($bk), 'size' => filesize($bk),
                         'age' => now()->diffForHumans(\Illuminate\Support\Carbon::createFromTimestamp(filemtime($bk)), true)] : null;

        // أخطاء وبطء (٧ أيام)
        $errs = [
            'new'  => ErrorEvent::where('status', 'جديد')->count(),
            'week' => ErrorEvent::where('last_seen', '>=', now()->subDays(7))->sum('count'),
            // **وقائعُ لا بصمات** (v2.338): `count()` كان يعدّ صفوفَ الجدول
            // المجمَّع — أي عددَ المسارات المتميّزة — بينما `week` فوقه يجمع
            // `count`. فألفُ بطءٍ على مسارٍ واحد كانت تُعرض «١»، والبطاقةُ
            // المخصَّصةُ لقياس الحمل تُطمئن حيث ينبغي أن تُنذر.
            'slow' => (int) ErrorEvent::where('kind', 'slow')->where('last_seen', '>=', now()->subDays(7))->sum('count'),
            'api'  => (int) ErrorEvent::where('kind', 'api')->where('last_seen', '>=', now()->subDays(7))->sum('count'),
        ];

        $pending = $this->pendingMigrations();

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

        return view('ops.index', compact('db', 'sys', 'cpu', 'mem', 'consumers', 'pulse', 'outbox',
            'beats', 'backup', 'errs', 'pending', 'live', 'logLines', 'env', 'health', 'deps'));
    }

    /** نسخة احتياطية فورية بضغطة — دورة «النشر بلا طرفية» تكتمل بها */
    public function backupNow()
    {
        $this->gate();
        @set_time_limit(300);

        try {
            \Illuminate\Support\Facades\Artisan::call('hub:backup');
            hub_audit('نسخة احتياطية يدوية', null, null, 'من مركز التشغيل');

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
        $on = ! (bool) setting('maintenance.on', false);
        \App\Models\Setting::updateOrCreate(['key' => 'maintenance.on'], ['value' => $on ? '1' : '']);
        Cache::forget('settings:all');
        hub_audit($on ? 'تفعيل وضع الصيانة' : 'إنهاء وضع الصيانة', null, null, 'من مركز التشغيل');

        return redirect()->route('ops.index')
            ->with('ok', $on ? 'فُعّل وضع الصيانة — الموظفون يرون رسالة الصيانة الآن' : 'أُنهي وضع الصيانة — عاد النظام للجميع');
    }

    /** الهجرات الموجودة في الكود ولم تُطبَّق على القاعدة بعد — الفجوة التي تكسر النشر */
    protected function pendingMigrations(): array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();

            return collect(glob(database_path('migrations/*.php')))
                ->map(fn ($f) => basename($f, '.php'))
                ->reject(fn ($m) => in_array($m, $ran, true))
                ->values()->all();
        } catch (\Throwable $e) {
            return [];   // تعذّر الفحص (جدول الهجرات نفسه غائب؟) — زر التشغيل يعالجه
        }
    }

    /**
     * تشغيل الترحيلات المعلقة من المتصفح — للمالك فقط، بلا طرفية.
     * وُلد من حادثة حقيقية: كودٌ نُشر قبل هجرته فانكسر كل قيد تدقيق حتى الخروج.
     */
    public function migrate()
    {
        $this->gate();
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

    /** توليد خطأ تجريبي للتحقق من مركز الأخطاء (عبر مسار الإبلاغ نفسه، دون صفحة 500) */
    public function testError()
    {
        $this->gate();
        report(new \RuntimeException('خطأ تجريبي من مركز التشغيل — إن رأيته في مركز الأخطاء فالالتقاط يعمل'));

        return redirect()->route('errors.index')->with('ok', 'وُلّد خطأ تجريبي — يظهر أدناه إن كان الالتقاط يعمل');
    }
}

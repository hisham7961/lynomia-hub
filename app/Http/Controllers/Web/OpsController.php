<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
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
            'mem'       => memory_get_peak_usage(true),
            'load'      => function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? null) : null,
            'php'       => PHP_VERSION,
            'uptime'    => is_readable('/proc/uptime') ? (int) floatval(file_get_contents('/proc/uptime')) : null,
        ];

        // طوابير الرسائل
        $outbox = DB::table('outbox')->select('state', DB::raw('COUNT(*) c'))->groupBy('state')->pluck('c', 'state');

        // نبضات المجدولات (متأخرة إن تجاوزت ضعف دورتها)
        $beats = [];
        foreach ([['outbox', 'عامل التسليم (كل ٥ دقائق)', 15],
                  ['automation', 'الأتمتة اليومية', 26 * 60],
                  ['backup', 'النسخ الاحتياطي اليومي', 26 * 60]] as [$k, $label, $maxMin]) {
            $at = setting('heartbeat.' . $k);
            $late = ! $at || \Illuminate\Support\Carbon::parse($at)->diffInMinutes(now()) > $maxMin;
            $beats[] = ['label' => $label, 'at' => $at, 'late' => $late];
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
            'slow' => ErrorEvent::where('kind', 'slow')->where('last_seen', '>=', now()->subDays(7))->count(),
            'api'  => ErrorEvent::where('kind', 'api')->where('last_seen', '>=', now()->subDays(7))->count(),
        ];

        $pending = $this->pendingMigrations();

        return view('ops.index', compact('db', 'sys', 'outbox', 'beats', 'backup', 'errs', 'pending'));
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

        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            $out = trim(\Illuminate\Support\Facades\Artisan::output());
            hub_audit('تشغيل الترحيلات', null, null, 'من مركز التشغيل');
            // مخطط الجدول ربما تغيّر للتو — تُنسى خبيئة الأعمدة فيُلتقط الجديد فوراً
            \App\Models\AuditEntry::forgetColumnCache();

            return redirect()->route('ops.index')
                ->with('ok', 'اكتمل الترحيل بنجاح')
                ->with('migrate_out', mb_substr($out, 0, 4000));
        } catch (\Throwable $e) {
            return redirect()->route('ops.index')
                ->with('err', 'فشل الترحيل: ' . mb_substr($e->getMessage(), 0, 300));
        }
    }

    /** فحص صحي داخلي عميق — JSON لمراقبات خارجية */
    public function health()
    {
        $checks = [];
        try { DB::select('select 1'); $checks['db'] = 'ok'; } catch (\Throwable $e) { $checks['db'] = 'fail'; }
        try { Cache::put('healthz', 1, 5); $checks['cache'] = Cache::get('healthz') ? 'ok' : 'fail'; } catch (\Throwable $e) { $checks['cache'] = 'fail'; }
        $checks['storage'] = is_writable(storage_path('app')) ? 'ok' : 'fail';
        $ok = ! in_array('fail', $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks,
                                 'version' => trim((string) @file_get_contents(base_path('VERSION')))], $ok ? 200 : 503);
    }

    /** توليد خطأ تجريبي للتحقق من مركز الأخطاء (عبر مسار الإبلاغ نفسه، دون صفحة 500) */
    public function testError()
    {
        $this->gate();
        report(new \RuntimeException('خطأ تجريبي من مركز التشغيل — إن رأيته في مركز الأخطاء فالالتقاط يعمل'));

        return redirect()->route('errors.index')->with('ok', 'وُلّد خطأ تجريبي — يظهر أدناه إن كان الالتقاط يعمل');
    }
}

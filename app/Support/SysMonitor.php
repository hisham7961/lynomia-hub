<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مراقبة حقيقية: استهلاكٌ بأرقامٍ لها معنى، ثم **من يستهلك**.
 * كانت الشاشة تعرض `memory_get_peak_usage` — ذاكرة *هذا الطلب* لا النظام —
 * و«الحمل» رقماً خاماً بلا عدد الأنوية فلا يُعرف أهو ٥٪ أم ٥٠٠٪.
 */
class SysMonitor
{
    /** عدد أنوية المعالج — بلا معرفتها يبقى «الحمل» رقماً بلا دلالة */
    public static function cores(): int
    {
        static $n = null;
        if ($n !== null) return $n;

        $n = 1;
        if (is_readable('/proc/cpuinfo')) {
            $n = max(1, substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor'));
        }

        return $n;
    }

    /**
     * المعالج: متوسط الحمل منسوباً لعدد الأنوية — ١٠٠٪ تعني أن كل نواةٍ مشغولة.
     * (الحمل يقيس الطوابير لا النسبة المئوية بدقة، والنسبة تقريبٌ صادق لا ادّعاء.)
     */
    public static function cpu(): array
    {
        $cores = self::cores();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        if (! $load) return ['ok' => false, 'cores' => $cores];

        $pct = (int) round(($load[0] / $cores) * 100);
        // عتبتا المعالج قابلتان للضبط (WP-2.1) — والقراءةُ داخل rescue فلا يسقط
        // القياسُ إن سقطت قاعدةُ الإعدادات (نمط Observability::handle نفسه)
        $warn = max(1, (int) rescue(fn () => setting('ops.cpu_warn', 60), 60, false));
        $crit = max($warn, (int) rescue(fn () => setting('ops.cpu_crit', 90), 90, false));

        return [
            'ok' => true, 'cores' => $cores,
            'load1' => round($load[0], 2), 'load5' => round($load[1], 2), 'load15' => round($load[2], 2),
            'pct' => $pct,
            'band' => $pct >= $crit ? 'مرتفع' : ($pct >= $warn ? 'متوسط' : 'مرتاح'),
            'tone' => $pct >= $crit ? 'bad' : ($pct >= $warn ? 'wn' : 'ok'),
        ];
    }

    /** الذاكرة: ذاكرة **النظام** من /proc/meminfo — لا ذروة طلب PHP الواحد */
    public static function memory(): array
    {
        $out = ['ok' => false, 'php_peak' => memory_get_peak_usage(true)];
        if (! is_readable('/proc/meminfo')) return $out;

        $raw = (string) @file_get_contents('/proc/meminfo');
        $get = function (string $key) use ($raw): ?int {
            return preg_match('/^' . $key . ':\s+(\d+) kB/m', $raw, $m) ? ((int) $m[1]) * 1024 : null;
        };
        $total = $get('MemTotal');
        $avail = $get('MemAvailable') ?? $get('MemFree');
        if (! $total || $avail === null) return $out;

        $used = $total - $avail;
        $pct = (int) round($used * 100 / $total);
        // عتبتا الذاكرة قابلتان للضبط (WP-2.1) — بافتراضيّاتٍ تساوي ثوابتَ الأمس حرفياً
        $warn = max(1, (int) rescue(fn () => setting('ops.mem_warn', 75), 75, false));
        $crit = max($warn, (int) rescue(fn () => setting('ops.mem_crit', 90), 90, false));

        // **array_replace لا `+`** (v2.324): عاملُ الاتحاد يُبقي مفتاحَ الطرف
        // الأيسر، و`$out` يحمل `'ok' => false` — فبطاقةُ الذاكرة كانت تُعلن
        // الفشلَ دائماً مهما نجحت القراءة، ويُقرأ ذلك «تعذّر القياس».
        return array_replace($out, [
            'ok' => true, 'total' => $total, 'avail' => $avail, 'used' => $used, 'pct' => $pct,
            'tone' => $pct >= $crit ? 'bad' : ($pct >= $warn ? 'wn' : 'ok'),
        ]);
    }

    /** من يستهلك القرص: أكبر مجلدات التخزين — «امتلأ القرص» بلا سببٍ لا يُعالَج */
    public static function diskConsumers(): array
    {
        // مشيُ المجلدات في كل تحميلٍ لصفحة التشغيل (OPS-09): يُخبَّأ دقيقةً — الأرقامُ لا تتغيّر بين ضغطتين (v2.399)
        return \Illuminate\Support\Facades\Cache::remember('sysmon:disk', 60, fn () => self::diskConsumersLive());
    }

    protected static function diskConsumersLive(): array
    {
        $out = [];
        foreach (['app/backups' => 'النسخ الاحتياطية', 'app/hub' => 'المرفقات والملفات',
                  'logs' => 'سجلات النظام', 'framework' => 'مخبأ الإطار'] as $rel => $label) {
            $path = storage_path($rel);
            if (! is_dir($path)) continue;
            $size = 0; $files = 0;
            try {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile()) { $size += $f->getSize(); $files++; }
                    if ($files > 20000) break;          // حارس: لا نمسح ملايين الملفات في طلبٍ واحد
                }
            } catch (\Throwable $e) { continue; }
            $out[] = ['label' => $label, 'path' => 'storage/' . $rel, 'size' => $size, 'files' => $files];
        }
        usort($out, fn ($a, $b) => $b['size'] <=> $a['size']);

        return $out;
    }

    /** من يستهلك القاعدة: أثقل الجداول صفوفاً — يكشف ما ينمو بلا حدّ */
    public static function tableConsumers(int $limit = 12): array
    {
        // عدُّ صفوفِ ٩٠ جدولاً في كل تحميل (OPS-09): يُخبَّأ دقيقةً (v2.399)
        return \Illuminate\Support\Facades\Cache::remember('sysmon:tables:' . $limit, 60, fn () => self::tableConsumersLive($limit));
    }

    protected static function tableConsumersLive(int $limit = 12): array
    {
        $rows = [];
        // أحجام البايت متاحة على MySQL وحدها — وعلى غيرها يبقى عدّ الصفوف دليلاً
        $sizes = [];
        try {
            if (config('database.default') === 'mysql') {
                foreach (DB::select('SELECT table_name t, (data_length + index_length) s
                    FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
                    $sizes[$r->t] = (int) $r->s;
                }
            }
        } catch (\Throwable $e) {
        }

        foreach (hub_modules() as $mk => $md) {
            $t = $md['table'] ?? null;
            if (! $t || isset($rows[$t])) continue;
            try {
                if (! Schema::hasTable($t)) continue;
                $rows[$t] = ['table' => $t, 'label' => $md['label'], 'n' => DB::table($t)->count()];
            } catch (\Throwable $e) { continue; }
        }
        // جداول المنصة التي تنمو بلا سقف — أخطر ما يملأ القاعدة صامتاً
        foreach (['audits' => 'سجل التدقيق', 'record_versions' => 'إصدارات السجلات',
                  'notifications_hub' => 'الإشعارات', 'page_visits' => 'زيارات الصفحات',
                  'webhook_deliveries' => 'محاولات الويبهوك', 'outbox' => 'الصندوق الصادر',
                  'error_events' => 'الأخطاء', 'sessions_log' => 'سجل الجلسات'] as $t => $label) {
            try {
                if (! Schema::hasTable($t)) continue;
                $rows[$t] = ['table' => $t, 'label' => $label, 'n' => DB::table($t)->count(), 'platform' => true];
            } catch (\Throwable $e) { continue; }
        }
        foreach ($rows as $t => $r) {
            $rows[$t]['size'] = $sizes[$t] ?? null;
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => $b['n'] <=> $a['n']);

        return array_slice($rows, 0, $limit);
    }

    /** من يستهلك الوقت: أبطأ المسارات من سجل الأخطاء (نوع slow) مجمّعةً بالرابط */
    public static function slowRoutes(int $limit = 10): array
    {
        // (WP-2.7) تجميعُ أسبوعٍ لا يتغيّر بين ضغطتين — دقيقةٌ كأخوَيه disk/tables
        return \Illuminate\Support\Facades\Cache::remember('sysmon:slow:' . $limit, 60,
            fn () => self::slowRoutesLive($limit));
    }

    protected static function slowRoutesLive(int $limit = 10): array
    {
        try {
            if (! Schema::hasTable('error_events')) return [];

            return DB::table('error_events')->where('kind', 'slow')
                ->where('last_seen', '>=', now()->subDays(7))
                ->selectRaw('url, SUM(count) hits, MAX(last_seen) last_seen, MAX(message) sample')
                ->groupBy('url')->orderByDesc('hits')->limit($limit)->get()
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** أكثر الصفحات استدعاءً — أين يذهب الحمل فعلاً */
    public static function busyRoutes(int $limit = 10): array
    {
        // (WP-2.7) تجميعُ أسبوعٍ من الزيارات — يُخبَّأ دقيقةً كأخوَيه disk/tables
        return \Illuminate\Support\Facades\Cache::remember('sysmon:busy:' . $limit, 60,
            fn () => self::busyRoutesLive($limit));
    }

    protected static function busyRoutesLive(int $limit = 10): array
    {
        try {
            if (! Schema::hasTable('page_visits')) return [];

            return DB::table('page_visits')->where('at', '>=', now()->subDays(7))
                ->selectRaw('path, COUNT(*) hits, COUNT(DISTINCT user_id) users')
                ->groupBy('path')->orderByDesc('hits')->limit($limit)->get()
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * نبض ٢٤ ساعة: طلبات وأخطاء لكل ساعة — الرقم اللحظي وحده لا يقول
     * أهذا الحملُ عادةٌ أم قفزة. الشكل الزمني هو ما يُقرأ منه العطل.
     *
     * (WP-2.7) **المصدرُ دلاءُ HTTP لا صفوفُ الزيارات**: كان النبضُ يجلب كلَّ صفوف
     * `page_visits` في النافذة (صفٌّ لكل زيارة — بلا سقف) ليعدّها في PHP؛ الآن
     * يقرأ دلاءَ `http_metric_buckets` المجمَّعةَ سلفاً (≤ ٢٨٨ صفاً لليوم مهما بلغ
     * الحمل) ويطويها ساعات. والأخطاء تبقى من `error_events` المجمَّع أصلاً
     * (صفٌّ لكل بصمة بعدّاد تكراراتها — **وقائعُ لا بصمات**، v2.338).
     */
    public static function pulse(int $hours = 24): array
    {
        $from = now()->subHours($hours - 1)->startOfHour();
        $slots = [];
        for ($i = 0; $i < $hours; $i++) {
            $slots[$from->copy()->addHours($i)->format('Y-m-d H')] = ['hits' => 0, 'errs' => 0];
        }

        try {
            if (Schema::hasTable('http_metric_buckets')) {
                // تجميعٌ في القاعدة على مفتاح الدلو (فريدٌ زمنياً) — طيُّه ساعاتٍ هنا
                // طيُّ ≤ ٢٨٨ صفاً مجمَّعاً، لا عدُّ الزيارات واحدةً واحدة
                $rows = DB::table('http_metric_buckets')->where('bucket_at', '>=', $from)
                    ->selectRaw('bucket_at, SUM(count) c')
                    ->groupBy('bucket_at')->orderBy('bucket_at')->get();
                foreach ($rows as $b) {
                    $k = \Illuminate\Support\Carbon::parse($b->bucket_at)->format('Y-m-d H');
                    if (isset($slots[$k])) $slots[$k]['hits'] += (int) $b->c;
                }
            }
            if (Schema::hasTable('error_events')) {
                // **الوقائعُ لا البصمات**: `error_events` جدولٌ **مجمَّع** — صفٌّ
                // واحدٌ لكل بصمةٍ بعمود `count` يحمل عددَ مرات الوقوع. وعدُّ
                // الصفوف كان يرسم عطلاً وقع خمسين مرة شرطةً واحدة بجوار عطلٍ
                // وقع مرة، فلا تُقرأ العاصفةُ عاصفةً في نبض اليوم.
                foreach (DB::table('error_events')->where('last_seen', '>=', $from)
                            ->get(['last_seen', 'count']) as $e) {
                    $k = \Illuminate\Support\Carbon::parse($e->last_seen)->format('Y-m-d H');
                    if (isset($slots[$k])) $slots[$k]['errs'] += max(1, (int) $e->count);
                }
            }
        } catch (\Throwable $e) {
        }

        $max = max(1, max(array_column($slots, 'hits')));
        $out = [];
        foreach ($slots as $k => $v) {
            $out[] = ['hour' => (int) substr($k, -2), 'hits' => $v['hits'], 'errs' => $v['errs'],
                      'pct' => (int) round($v['hits'] * 100 / $max)];
        }

        return $out;
    }

    /** حجمٌ مقروء — التكرار في القوالب كان يُنتج صيغاً متضاربة */
    public static function bytes(?int $b): string
    {
        if ($b === null) return '—';
        if ($b >= 1073741824) return number_format($b / 1073741824, 1) . ' GB';
        if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB';
        if ($b >= 1024) return number_format($b / 1024, 1) . ' KB';

        return $b . ' B';
    }
}

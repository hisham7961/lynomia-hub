<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **نموذجُ صحّة الخدمة الواحد** — لا «٢٠٠ = سليم».
 *
 * `/healthz` كان يفحص ثلاثة أشياء (قاعدة/خبيئة/تخزين) ويقول `ok`؛ ومركزُ التشغيل
 * يحسب نبضاتِ المجدولات بيده؛ ومركزُ التكاملات يحسب جاهزيتَها بيده. ثلاثةُ
 * تعريفاتٍ للصحّة لا يلتقي منها اثنان.
 *
 * هنا تعريفٌ واحد لكل مكوّنٍ حرج بخمس حالاتٍ لها معنى:
 *   HEALTHY · DEGRADED (يعمل بنقص) · UNAVAILABLE (لا يخدم) · MAINTENANCE · UNKNOWN (لا مصدر للقياس)
 * ويُفرَّق بين **الحياة** (العمليةُ تُجيب) و**الجاهزية** (تستطيع خدمةَ الطلبات صحيحاً)
 * و**صحّة الاعتماديات** (المجدولات والطوابير والتكاملات). يقرؤه `/healthz` ومركزُ
 * التشغيل معاً — فلا ينفصلان.
 */
final class Health
{
    public const HEALTHY = 'HEALTHY';
    public const DEGRADED = 'DEGRADED';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const MAINTENANCE = 'MAINTENANCE';
    public const UNKNOWN = 'UNKNOWN';

    public const LABELS = [
        self::HEALTHY => 'سليم', self::DEGRADED => 'متدهور', self::UNAVAILABLE => 'متعطّل',
        self::MAINTENANCE => 'صيانة', self::UNKNOWN => 'غير معلوم',
    ];

    public const TONE = [
        self::HEALTHY => 'ok', self::DEGRADED => 'wn', self::UNAVAILABLE => 'bad',
        self::MAINTENANCE => 'wn', self::UNKNOWN => 'g',
    ];

    /** ترتيبُ السوء — الأسوأ يحكم المجموع */
    protected const RANK = [self::HEALTHY => 0, self::UNKNOWN => 1, self::MAINTENANCE => 2, self::DEGRADED => 3, self::UNAVAILABLE => 4];

    /**
     * رتبةُ حالةٍ رقماً (WP-2.3): المصدرُ الواحد لسُلَّم السوء — تقرؤه لقطةُ
     * hub:ops-snapshot فتُخزَّن سلسلةُ ('ops','health','rank') بأرقامٍ قابلةٍ
     * للرسم بدل أن يخترع كلُّ قارئٍ سُلَّمَه الخاص.
     */
    public static function rank(string $status): int
    {
        return self::RANK[$status] ?? self::RANK[self::UNKNOWN];
    }

    /**
     * المجدولاتُ ودورتُها بالدقائق: [التسمية، الدورة، متأخرة بعد، متعطّلة بعد].
     * النبضةُ تُكتب في `heartbeat.<key>` (قائمٌ منذ v2.26) ولا تُغيَّر صيغتُها.
     */
    public const JOBS = [
        'outbox'     => ['عامل التسليم (كل ٥ دقائق)', 5, 15, 60],
        'uptime'     => ['الفحص الحيّ (كل ٥ دقائق)', 5, 15, 60],
        'ops'        => ['لقطةُ التشغيل (كل ٥ دقائق)', 5, 15, 60],   // ── Control Plane: Phase 2 (WP-2.3) ──
        'alerts'     => ['تقييمُ التنبيهات (كل ٥ دقائق)', 5, 15, 60],   // ── Control Plane: Phase 6 (WP-6.3) ──
        'automation' => ['الأتمتة اليومية', 1440, 26 * 60, 50 * 60],
        'backup'     => ['النسخ الاحتياطي اليومي', 1440, 26 * 60, 50 * 60],
        'metrics'    => ['لقطة المقاييس اليومية', 1440, 26 * 60, 50 * 60],
        'quality'    => ['لقطة الجودة اليومية', 1440, 26 * 60, 50 * 60],
        'security'   => ['لقطة الأمن اليومية', 1440, 26 * 60, 50 * 60],   // ── Control Plane: Phase 4 (WP-4.2) ──
        'digest'     => ['التقرير الأسبوعي', 7 * 1440, 8 * 1440, 15 * 1440],
        'audit'      => ['فاحص سلسلة التدقيق (أسبوعي)', 7 * 1440, 8 * 1440, 15 * 1440],
    ];

    /**
     * نافذتا «متأخرة/متعطّلة» لمجدولةٍ بالدقائق بعد تطبيق معامل التأخّر القابل للضبط
     * (WP-2.1: ops.scheduler_late_factor، افتراضيُّه ١ فلا يتغيّر شيءٌ بلا ضبط).
     * المصدرُ الواحد لكل قارئ: scheduler() هنا وفحصُ حداثة النسخة في مركز الأمان —
     * كانا عتبتين مختلفتين تقولان قولين في المجدولة الواحدة.
     */
    public static function jobWindows(string $job): array
    {
        [, , $late, $dead] = self::JOBS[$job] ?? [null, 0, 0, 0];
        $factor = max(0.1, (float) rescue(fn () => setting('ops.scheduler_late_factor', 1), 1, false));

        return [(int) round($late * $factor), (int) round($dead * $factor)];
    }

    /* ────────── الأسطح الثلاثة ────────── */

    /** حياة: العمليةُ تُجيب والتطبيقُ أقلع — لا اعتماديات */
    public static function live(): array
    {
        return ['status' => self::HEALTHY, 'version' => self::version(), 'at' => now()->toIso8601String()];
    }

    /** جاهزية: ما لا يُخدَم طلبٌ صحيحٌ بدونه — القاعدة والخبيئة والتخزين والمخطّط والإعداد */
    public static function ready(): array
    {
        $c = [
            'db' => self::db(), 'cache' => self::cache(), 'storage' => self::storage(),
            'migrations' => self::migrations(), 'config' => self::config(),
        ];

        return self::wrap($c);
    }

    /** الصحّةُ الكاملة: الجاهزية + الاعتماديات (المجدولات، الطوابير، التكاملات، الأخطاء، الأمن، الموارد) */
    public static function check(): array
    {
        $c = [
            'db' => self::db(), 'cache' => self::cache(), 'storage' => self::storage(),
            'migrations' => self::migrations(), 'config' => self::config(),
            'scheduler' => self::scheduler(), 'outbox' => self::outbox(), 'webhooks' => self::webhooks(),
            'integrations' => self::integrations(), 'errors' => self::errors(), 'security' => self::security(),
            'system' => self::system(),
        ];

        return self::wrap($c);
    }

    /**
     * خريطةُ الاعتماديات: أيُّ قدرةٍ تموت بموت أيّ مكوّن. تُقرأ في مركز التشغيل
     * لتُقال «انقطاعُ البريد يعطّل تذكيرَ التوقيع لا الفواتير» بدل «شيءٌ ما معطّل».
     */
    public static function dependencies(): array
    {
        return [
            'تسجيل الدخول والجلسات' => ['db', 'cache', 'security'],
            'الوحدات (قراءة/كتابة) وAPI' => ['db', 'cache', 'migrations', 'config'],
            'المرفقات والملفات وPDF' => ['db', 'storage'],
            'التنبيهات وتلجرام والبريد' => ['scheduler', 'outbox', 'integrations'],
            'الويبهوك الصادر (n8n وغيره)' => ['scheduler', 'webhooks'],
            'التوقيع الإلكتروني' => ['db', 'storage', 'outbox'],
            'الأتمتة اليومية والمتكررات' => ['scheduler', 'db'],
            'النسخ الاحتياطي' => ['scheduler', 'storage'],
            'أرقام أودو في السجلات' => ['integrations', 'cache'],
        ];
    }

    /* ────────── الفحوص ────────── */

    protected static function db(): array
    {
        // عتبة بطء القاعدة قابلة للضبط (WP-2.1) — والقراءة داخل rescue فلا يعتمد
        // فحصُ القاعدة على القاعدة نفسِها (نمط Observability::handle)
        $warnMs = max(1, (int) rescue(fn () => setting('ops.db_ms_warn', 500), 500, false));
        try {
            $t0 = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return self::c($ms > $warnMs ? self::DEGRADED : self::HEALTHY, 'قاعدة البيانات',
                $ms > $warnMs ? "بطيئة: {$ms}ms" : "{$ms}ms", ['ms' => $ms, 'driver' => config('database.default')]);
        } catch (\Throwable $e) {
            return self::c(self::UNAVAILABLE, 'قاعدة البيانات', 'لا تُجيب', ['error' => self::safe($e->getMessage())]);
        }
    }

    protected static function cache(): array
    {
        try {
            $k = 'health:' . bin2hex(random_bytes(4));
            Cache::put($k, 1, 5);
            $ok = Cache::get($k) === 1;
            Cache::forget($k);

            return self::c($ok ? self::HEALTHY : self::UNAVAILABLE, 'الخبيئة', $ok ? (string) config('cache.default') : 'لا تحفظ ولا تقرأ',
                ['driver' => config('cache.default')]);
        } catch (\Throwable $e) {
            return self::c(self::UNAVAILABLE, 'الخبيئة', 'تعذّرت', ['error' => self::safe($e->getMessage())]);
        }
    }

    protected static function storage(): array
    {
        $writable = is_writable(storage_path('app')) && is_writable(storage_path('logs'));
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $pct = ($free !== false && $total) ? (int) round(($total - $free) * 100 / $total) : null;
        $status = ! $writable ? self::UNAVAILABLE : self::diskStatus($pct);
        $why = ! $writable ? 'مجلد التخزين غير قابل للكتابة' : ($pct === null ? 'قابل للكتابة' : "القرص مستخدم {$pct}٪");

        return self::c($status, 'التخزين', $why, ['writable' => $writable, 'disk_pct' => $pct]);
    }

    /**
     * حالةُ امتلاء القرص من نسبته وحدها — العتبتان قابلتان للضبط (WP-2.1) بافتراضيّاتِ
     * الأمس (85/97)، والقراءةُ داخل rescue فلا يعتمد فحصُ الجاهزية على قاعدة الإعدادات.
     * عامّةٌ ليختبرها الاختبارُ بنسبةٍ معلومة، وليقرأها عرضُ مركز التشغيل نفسُه.
     */
    public static function diskStatus(?int $pct): string
    {
        if ($pct === null) return self::HEALTHY;
        $warn = max(1, (int) rescue(fn () => setting('ops.disk_warn', 85), 85, false));
        $crit = max($warn, (int) rescue(fn () => setting('ops.disk_crit', 97), 97, false));

        return $pct >= $crit ? self::UNAVAILABLE : ($pct >= $warn ? self::DEGRADED : self::HEALTHY);
    }

    protected static function migrations(): array
    {
        try {
            $n = (int) hub_pending_migrations();

            return self::c($n ? self::DEGRADED : self::HEALTHY, 'مخطّط القاعدة',
                $n ? "{$n} ترحيلاً معلّقاً — الكود يسبق القاعدة" : 'مطابق للكود', ['pending' => $n]);
        } catch (\Throwable $e) {
            return self::c(self::UNKNOWN, 'مخطّط القاعدة', 'تعذّر الفحص', []);
        }
    }

    protected static function config(): array
    {
        $issues = [];
        if ((string) config('app.key') === '') $issues[] = 'APP_KEY غائب';
        if (config('app.env') === 'production' && config('app.debug')) $issues[] = 'APP_DEBUG مفعّل في الإنتاج';
        if (config('app.env') === 'production' && ! str_starts_with((string) config('app.url'), 'https://')) $issues[] = 'APP_URL ليس https';
        $maint = false;
        try { $maint = (bool) setting('maintenance.on', false); } catch (\Throwable $e) {}
        $lock = false;
        try { $lock = (bool) setting('security.lockdown', false); } catch (\Throwable $e) {}
        if ($lock) return self::c(self::MAINTENANCE, 'الإعداد', 'قفل طوارئ مفعّل', ['issues' => $issues, 'lockdown' => true]);
        if ($maint) return self::c(self::MAINTENANCE, 'الإعداد', 'وضع الصيانة مفعّل', ['issues' => $issues]);
        if (in_array('APP_KEY غائب', $issues, true)) return self::c(self::UNAVAILABLE, 'الإعداد', 'APP_KEY غائب', ['issues' => $issues]);

        return self::c($issues ? self::DEGRADED : self::HEALTHY, 'الإعداد', $issues ? implode('؛ ', $issues) : 'سليم', ['issues' => $issues, 'env' => config('app.env')]);
    }

    /**
     * **مفتاحُ الرجل الميّت** (v2.399): إن لم تنبض المجدولاتُ (cron غيرُ مفعّل أو واقف) لم يعلم أحد —
     * فالمنبّهُ نفسُه كان يعمل بالـcron. يُستدعى من طلبات الويب (مخنوقاً بالكاش كل نصف ساعة)
     * ويُشعر المالكين والمراقبين مرّةً في اليوم حتى تعود النبضات. لا يُشعر في أول ساعاتِ تنصيب.
     */
    public static function watchdog(): void
    {
        try {
            if ((string) setting('ops.watchdog', '1') !== '1') return;
            if (! Cache::add('health:watchdog', 1, 1800)) return;
            $s = self::scheduler();
            if (($s['status'] ?? '') !== self::UNAVAILABLE) return;
            $first = \App\Models\User::orderBy('created_at')->value('created_at');
            if (! $first || Carbon::parse($first)->gt(now()->subHours(6))) return;   // تنصيبٌ حديث — لا صراخ
            $last = setting('heartbeat.watchdog_notified');
            if ($last && Carbon::parse($last)->gt(now()->subDay())) return;
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.watchdog_notified'], ['value' => now()->toIso8601String()]);
            Cache::forget('settings:all');
            $text = '⏰ المجدولاتُ لا تنبض: ' . ($s['why'] ?? '') . ' — لا تسليمَ رسائل ولا نسخَ احتياطي ولا تنبيهات حتى يُصلَح سطر cron. راجع مركز التشغيل ← كتيّبات التشغيل.';
            \App\Models\User::with('role')->whereNull('deleted_at')->get()
                ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'monitor'))
                ->each(fn ($u) => hub_notify($u->id, 'error', $text));
        } catch (\Throwable $e) {
        }
    }

    /** المجدولات: كلُّ نبضةٍ بدورتها — متأخرةٌ ثم متعطّلة، والغائبةُ كلياً = cron غير مفعّل */
    public static function scheduler(): array
    {
        $rows = [];
        $worst = self::HEALTHY;
        $never = 0;
        foreach (self::JOBS as $key => [$label, $every]) {
            [$late, $dead] = self::jobWindows($key);
            $at = null; $meta = [];
            try {
                $at = setting('heartbeat.' . $key);
                $m = setting('heartbeat.' . $key . '.meta');
                $meta = is_array($m) ? $m : (is_string($m) ? (json_decode($m, true) ?: []) : []);
            } catch (\Throwable $e) {}
            $age = $at ? Carbon::parse($at)->diffInMinutes(now()) : null;
            if ($age === null) { $st = self::UNKNOWN; $never++; }
            elseif ($age > $dead) $st = self::UNAVAILABLE;
            elseif ($age > $late) $st = self::DEGRADED;
            else $st = self::HEALTHY;
            // نتيجةُ آخر تشغيل: فشلٌ صريح يجعل النبضة متدهورةً ولو كانت في موعدها
            if ($st === self::HEALTHY && (($meta['result'] ?? 'ok') !== 'ok')) $st = self::DEGRADED;
            $rows[$key] = ['label' => $label, 'status' => $st, 'at' => $at, 'age_min' => $age, 'every_min' => $every,
                           'ms' => $meta['ms'] ?? null, 'result' => $meta['result'] ?? null, 'late' => $st !== self::HEALTHY];
            if (self::RANK[$st] > self::RANK[$worst]) $worst = $st;
        }
        // لم تنبض أيُّ مجدولة قطّ: cron غير مفعّل — وهذا انقطاعُ التسليم كلِّه لا مجهول
        if ($never === count(self::JOBS)) $worst = self::UNAVAILABLE;
        $lateNames = implode('، ', array_map(fn ($r) => $r['label'], array_filter($rows, fn ($r) => $r['late'] && $r['status'] !== self::UNKNOWN)));
        if ($worst === self::HEALTHY) $why = 'كل المجدولات في موعدها';
        elseif ($never === count(self::JOBS)) $why = 'لم تنبض أيُّ مجدولة — سطر cron غير مفعّل على الخادم';
        else $why = $lateNames !== '' ? $lateNames : 'مجدولاتٌ لم تعمل بعد';

        return self::c($worst, 'المجدولات', $why, ['jobs' => $rows]);
    }

    protected static function outbox(): array
    {
        if (! Schema::hasTable('outbox')) return self::c(self::UNKNOWN, 'الصندوق الصادر', 'الجدول غائب', []);
        try {
            $queued = (int) DB::table('outbox')->where('state', 'queued')->count();
            $failed24 = (int) DB::table('outbox')->where('state', 'failed')->where('created_at', '>=', now()->subDay())->count();
            $oldest = DB::table('outbox')->where('state', 'queued')->min('created_at');
            $stuckMin = $oldest ? Carbon::parse($oldest)->diffInMinutes(now()) : 0;
            $lastError = DB::table('outbox')->where('state', 'failed')->orderByDesc('created_at')->value('error');

            // عتبتا عمر الطابور قابلتان للضبط (WP-2.1) — بافتراضيّي الأمس (٢٠/٦٠ دقيقة)
            $warnAge = max(1, (int) rescue(fn () => setting('ops.queue_age_warn', 20), 20, false));
            $critAge = max($warnAge, (int) rescue(fn () => setting('ops.queue_age_crit', 60), 60, false));
            $st = self::HEALTHY; $why = "{$queued} في الطابور";
            if ($stuckMin > $critAge) { $st = self::UNAVAILABLE; $why = "رسالةٌ تنتظر منذ {$stuckMin} دقيقة — العامل لا يُفرغ الطابور"; }
            elseif ($stuckMin > $warnAge || $failed24 > 0) { $st = self::DEGRADED; $why = $failed24 ? "{$failed24} فشلت خلال ٢٤ ساعة" : "الطابور يتأخّر ({$stuckMin} دقيقة)"; }

            return self::c($st, 'الصندوق الصادر', $why, ['queued' => $queued, 'failed_24h' => $failed24, 'oldest_min' => $stuckMin, 'last_error' => $lastError ? mb_substr((string) $lastError, 0, 160) : null]);
        } catch (\Throwable $e) {
            return self::c(self::UNKNOWN, 'الصندوق الصادر', 'تعذّر الفحص', []);
        }
    }

    protected static function webhooks(): array
    {
        if (! Schema::hasTable('webhooks')) return self::c(self::UNKNOWN, 'الويبهوك الصادر', 'الجدول غائب', []);
        try {
            $active = (int) DB::table('webhooks')->where('active', true)->count();
            if ($active === 0) return self::c(self::HEALTHY, 'الويبهوك الصادر', 'لا اشتراكات مفعّلة', ['active' => 0]);
            $paused = (int) DB::table('webhooks')->where('active', true)->where('paused_until', '>', now())->count();
            $failed24 = (int) DB::table('webhook_deliveries')->where('state', 'failed')->where('created_at', '>=', now()->subDay())->count();
            $overdue = (int) DB::table('webhook_deliveries')->where('state', 'queued')->where('created_at', '<', now()->subMinutes(30))
                ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<', now()->subMinutes(30)))->count();
            $st = self::HEALTHY; $why = "{$active} اشتراكاً مفعّلاً";
            if ($paused === $active) { $st = self::UNAVAILABLE; $why = 'كل الاشتراكات موقوفة مؤقتاً بعد إخفاقات متتالية'; }
            elseif ($paused || $failed24 || $overdue) { $st = self::DEGRADED; $why = trim(($paused ? "{$paused} موقوف مؤقتاً " : '') . ($failed24 ? "· {$failed24} فشل ٢٤س " : '') . ($overdue ? "· {$overdue} متأخر" : ''), ' ·'); }

            return self::c($st, 'الويبهوك الصادر', $why, ['active' => $active, 'paused' => $paused, 'failed_24h' => $failed24, 'overdue' => $overdue]);
        } catch (\Throwable $e) {
            return self::c(self::UNKNOWN, 'الويبهوك الصادر', 'تعذّر الفحص', []);
        }
    }

    /** التكاملات: من سجل التكاملات (المصدر الواحد) — الأسوأ يحكم */
    protected static function integrations(): array
    {
        try {
            $rows = [];
            $worst = self::HEALTHY;
            foreach (Integrations::installed() as $key => $i) {
                $h = (string) ($i['health'] ?? Integrations::UNKNOWN);
                $st = match ($h) {
                    Integrations::CONNECTED, Integrations::CONFIGURATION_REQUIRED, Integrations::DISABLED => self::HEALTHY,
                    Integrations::DEGRADED => self::DEGRADED,
                    Integrations::FAILED => self::UNAVAILABLE,
                    default => self::UNKNOWN,
                };
                // «يحتاج إعداداً» و«معطّل» ليسا عطلاً — قرارُ إعداد لا انقطاع
                $rows[$key] = ['name' => $i['name'], 'health' => $h, 'status' => $st, 'last_ok_at' => $i['last_ok_at'] ?? null,
                               'last_fail_at' => $i['last_fail_at'] ?? null, 'last_error' => $i['last_error'] ?? null];
                if (self::RANK[$st] > self::RANK[$worst]) $worst = $st;
            }
            $bad = array_filter($rows, fn ($r) => $r['status'] !== self::HEALTHY);

            return self::c($worst, 'التكاملات', $bad ? implode('، ', array_map(fn ($r) => $r['name'], $bad)) : 'كل المربوط يعمل', ['items' => $rows]);
        } catch (\Throwable $e) {
            return self::c(self::UNKNOWN, 'التكاملات', 'تعذّر الفحص', []);
        }
    }

    /** الأخطاء: عطلٌ حرجٌ/عالٍ مفتوح ظهر خلال ساعة = تدهورٌ فعليّ */
    protected static function errors(): array
    {
        if (! Schema::hasTable('error_events')) return self::c(self::UNKNOWN, 'الأخطاء', 'الجدول غائب', []);
        try {
            // (WP-3.4) الأعدادُ من القارئ الواحد — الدلالةُ نفسُها التي تعرضها بطاقةُ
            // «حرجة» في لوحة الأخطاء، فلا يقول النموذجُ قولاً واللوحةُ غيرَه.
            // المتجاهَلُ يبقى محسوباً عمداً: إخفاءُ الحرج قرارُ عرضٍ لا شفاء.
            $w = ErrorStats::healthWindow();
            $crit = $w['critical_1h'];
            $high = $w['high_1h'];
            $hits = $w['hits_1h'];
            $st = $crit ? self::UNAVAILABLE : ($high || $hits >= 50 ? self::DEGRADED : self::HEALTHY);

            return self::c($st, 'الأخطاء', $crit ? "{$crit} حرج خلال ساعة" : ($high ? "{$high} عالٍ خلال ساعة" : ($hits ? "{$hits} تكراراً خلال ساعة" : 'لا أخطاء جديدة')),
                ['critical_1h' => $crit, 'high_1h' => $high, 'hits_1h' => $hits]);
        } catch (\Throwable $e) {
            return self::c(self::UNKNOWN, 'الأخطاء', 'تعذّر الفحص', []);
        }
    }

    protected static function security(): array
    {
        try {
            $lock = (bool) setting('security.lockdown', false);
            $frozen = array_keys(array_filter(['exports' => (string) setting('security.freeze_exports', '0') === '1', 'tokens' => (string) setting('security.freeze_tokens', '0') === '1']));
            // (WP-6.1) عمودُ `kind` المفهرسُ بدل مسح meta بـLIKE — فحصُ الصحة يعمل في
            // كل نبضة؛ والصفوفُ القديمة (العمودُ فارغ) تُلتقط بسقوطٍ إلى meta
            $incidents = Schema::hasTable('incidents')
                ? (int) DB::table('incidents')->whereNull('deleted_at')->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])
                    ->when(hub_has_col('incidents', 'kind'),
                        fn ($q) => $q->where(fn ($w) => $w->where('kind', 'security')
                            ->orWhere(fn ($o) => $o->whereNull('kind')->where('meta', 'like', '%"kind"%security%'))),
                        fn ($q) => $q->where('meta', 'like', '%"kind"%security%'))
                    ->count() : 0;
            $chain = Audit::verifyTail(30);
            if ($lock) return self::c(self::MAINTENANCE, 'الأمن', 'قفل طوارئ مفعّل', ['lockdown' => true, 'frozen' => $frozen]);
            if (! $chain['ok']) return self::c(self::UNAVAILABLE, 'الأمن', 'سلسلة التدقيق مكسورة — ' . $chain['why'], ['chain' => $chain]);
            $st = ($incidents || $frozen) ? self::DEGRADED : self::HEALTHY;

            return self::c($st, 'الأمن', $incidents ? "{$incidents} حادثة أمنية مفتوحة" : ($frozen ? 'مفاتيح طوارئ مفعّلة: ' . implode('، ', $frozen) : 'لا حوادث مفتوحة والسلسلة سليمة'),
                ['open_security_incidents' => $incidents, 'frozen' => $frozen, 'chain_ok' => true]);
        } catch (\Throwable $e) {
            return self::c(self::UNKNOWN, 'الأمن', 'تعذّر الفحص', []);
        }
    }

    protected static function system(): array
    {
        $cpu = SysMonitor::cpu();
        $mem = SysMonitor::memory();
        $bad = ($cpu['ok'] && ($cpu['tone'] ?? '') === 'bad') || ($mem['ok'] && ($mem['tone'] ?? '') === 'bad');
        $warn = ($cpu['ok'] && ($cpu['tone'] ?? '') === 'wn') || ($mem['ok'] && ($mem['tone'] ?? '') === 'wn');
        $st = ! $cpu['ok'] && ! $mem['ok'] ? self::UNKNOWN : ($bad ? self::DEGRADED : self::HEALTHY);
        if ($warn && $st === self::HEALTHY) $st = self::HEALTHY;   // «متوسط» ليس تدهوراً

        return self::c($st, 'موارد الخادم', $cpu['ok'] ? "المعالج {$cpu['pct']}٪" . ($mem['ok'] ? " · الذاكرة {$mem['pct']}٪" : '') : 'لا قياس',
            ['cpu_pct' => $cpu['pct'] ?? null, 'mem_pct' => $mem['pct'] ?? null]);
    }

    /* ────────── النبضات (كتابة) ────────── */

    /**
     * نبضةُ مجدولةٍ بمدّتها ونتيجتها — الصيغةُ القديمة `heartbeat.<key>` (ISO) تبقى
     * كما هي لكل قارئٍ قائم، ويُضاف بجانبها `heartbeat.<key>.meta` (المدّة والنتيجة).
     */
    public static function beat(string $job, ?int $ms = null, string $result = 'ok', ?string $note = null): void
    {
        try {
            $iso = now()->toIso8601String();
            $meta = ['ms' => $ms, 'result' => $result, 'note' => $note ? mb_substr($note, 0, 180) : null, 'at' => $iso];
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.' . $job], ['value' => $iso]);
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.' . $job . '.meta'], ['value' => $meta]);

            // **لا `Cache::forget('settings:all')` هنا** (WP-2.3 · critic #29): أربعُ مجدولاتٍ
            // كلَّ ٥ دقائق تعني إبطالاً كلَّ ~دقيقة وربع، فيقرأ كلُّ طلبِ ويبٍ تقريباً جدولَ
            // الإعدادات كاملاً. النبضةُ تُرقَّع في الكاش **موضعياً** بمفتاحَيها وحدهما —
            // فيبقى كلُّ قارئِ `setting('heartbeat.*')` طازجاً بلا نسفِ الكاش كلِّه.
            try {
                $all = Cache::get('settings:all');
                if (is_array($all)) {
                    $all['heartbeat.' . $job] = $iso;
                    $all['heartbeat.' . $job . '.meta'] = $meta;
                    Cache::put('settings:all', $all, 600);
                }
            } catch (\Throwable $e) {
            }

            // **تاريخُ التشغيل** (WP-2.3): صفٌّ لكل نبضةٍ في metric_points — فتاريخُ كل
            // مجدولٍ (المدّةُ والنتيجة) يوجد بلا جدولٍ جديد، ويرسمه جدولُ المجدولات في
            // مركز التشغيل. مدّةٌ غائبة تُخزَّن -1 لا null (critic #30: التوقيع float
            // يرمي TypeError) ولا صفراً كاذباً — القارئُ يميّز «بلا قياس» عن «فوريّ».
            hub_metric_put('ops', $job, 'run', $ms === null ? -1.0 : (float) $ms, now(), 'auto',
                ['result' => $result, 'note' => $note ? mb_substr($note, 0, 180) : null]);
        } catch (\Throwable $e) {
            // النبضةُ إثراءٌ لا شرطٌ لإتمام المهمّة
        }
    }

    /* ────────── أدوات ────────── */

    protected static function c(string $status, string $label, string $why, array $data): array
    {
        return ['status' => $status, 'label' => $label, 'why' => $why, 'tone' => self::TONE[$status], 'data' => $data];
    }

    protected static function wrap(array $components): array
    {
        $worst = self::HEALTHY;
        foreach ($components as $c) if (self::RANK[$c['status']] > self::RANK[$worst]) $worst = $c['status'];

        return ['status' => $worst, 'label' => self::LABELS[$worst], 'components' => $components,
                'version' => self::version(), 'at' => now()->toIso8601String()];
    }

    /** مكوّناتُ الجاهزية — ما لا يُخدَم طلبٌ صحيحٌ بدونه */
    public const READINESS = ['db', 'cache', 'storage', 'migrations', 'config'];

    /** أسوأُ حالةٍ بين مكوّنات الجاهزية في نتيجةٍ كاملة أو جزئية */
    public static function readinessOf(array $full): string
    {
        $worst = self::HEALTHY;
        foreach (self::READINESS as $k) {
            $st = $full['components'][$k]['status'] ?? self::HEALTHY;
            if (self::RANK[$st] > self::RANK[$worst]) $worst = $st;
        }

        return $worst;
    }

    public static function version(): string
    {
        return trim((string) config('hub.version', @file_get_contents(base_path('VERSION')) ?: ''));
    }

    /** رسالةُ عطلٍ آمنةٌ للعرض: بلا كلمات مرور DSN — (WP-1.3) تفويضٌ للمُطهِّر الواحد */
    protected static function safe(string $m): string
    {
        return mb_substr(Redactor::text($m), 0, 200);
    }

    /** ملخّصٌ عامٌّ آمن لمراقبات Uptime المجهولة: الحالاتُ وحدها بلا أرقامٍ أو رسائل */
    public static function publicView(array $full): array
    {
        return [
            'status' => $full['status'],
            'components' => array_map(fn ($c) => $c['status'], $full['components']),
            'version' => $full['version'], 'at' => $full['at'],
        ];
    }
}

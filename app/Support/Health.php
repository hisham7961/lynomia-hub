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
     * المجدولاتُ ودورتُها بالدقائق: [التسمية، الدورة، متأخرة بعد، متعطّلة بعد].
     * النبضةُ تُكتب في `heartbeat.<key>` (قائمٌ منذ v2.26) ولا تُغيَّر صيغتُها.
     */
    public const JOBS = [
        'outbox'     => ['عامل التسليم (كل ٥ دقائق)', 5, 15, 60],
        'uptime'     => ['الفحص الحيّ (كل ٥ دقائق)', 5, 15, 60],
        'automation' => ['الأتمتة اليومية', 1440, 26 * 60, 50 * 60],
        'backup'     => ['النسخ الاحتياطي اليومي', 1440, 26 * 60, 50 * 60],
        'metrics'    => ['لقطة المقاييس اليومية', 1440, 26 * 60, 50 * 60],
        'quality'    => ['لقطة الجودة اليومية', 1440, 26 * 60, 50 * 60],
        'digest'     => ['التقرير الأسبوعي', 7 * 1440, 8 * 1440, 15 * 1440],
        'audit'      => ['فاحص سلسلة التدقيق (أسبوعي)', 7 * 1440, 8 * 1440, 15 * 1440],
    ];

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
        try {
            $t0 = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return self::c($ms > 500 ? self::DEGRADED : self::HEALTHY, 'قاعدة البيانات',
                $ms > 500 ? "بطيئة: {$ms}ms" : "{$ms}ms", ['ms' => $ms, 'driver' => config('database.default')]);
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
        $status = ! $writable ? self::UNAVAILABLE : ($pct === null ? self::HEALTHY : ($pct >= 97 ? self::UNAVAILABLE : ($pct >= 85 ? self::DEGRADED : self::HEALTHY)));
        $why = ! $writable ? 'مجلد التخزين غير قابل للكتابة' : ($pct === null ? 'قابل للكتابة' : "القرص مستخدم {$pct}٪");

        return self::c($status, 'التخزين', $why, ['writable' => $writable, 'disk_pct' => $pct]);
    }

    protected static function migrations(): array
    {
        try {
            $pending = hub_pending_migrations();
            $n = count($pending);

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
        if ($maint) return self::c(self::MAINTENANCE, 'الإعداد', 'وضع الصيانة مفعّل', ['issues' => $issues]);
        if (in_array('APP_KEY غائب', $issues, true)) return self::c(self::UNAVAILABLE, 'الإعداد', 'APP_KEY غائب', ['issues' => $issues]);

        return self::c($issues ? self::DEGRADED : self::HEALTHY, 'الإعداد', $issues ? implode('؛ ', $issues) : 'سليم', ['issues' => $issues, 'env' => config('app.env')]);
    }

    /** المجدولات: كلُّ نبضةٍ بدورتها — متأخرةٌ ثم متعطّلة، والغائبةُ كلياً = cron غير مفعّل */
    public static function scheduler(): array
    {
        $rows = [];
        $worst = self::HEALTHY;
        $never = 0;
        foreach (self::JOBS as $key => [$label, $every, $late, $dead]) {
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

            $st = self::HEALTHY; $why = "{$queued} في الطابور";
            if ($stuckMin > 60) { $st = self::UNAVAILABLE; $why = "رسالةٌ تنتظر منذ {$stuckMin} دقيقة — العامل لا يُفرغ الطابور"; }
            elseif ($stuckMin > 20 || $failed24 > 0) { $st = self::DEGRADED; $why = $failed24 ? "{$failed24} فشلت خلال ٢٤ ساعة" : "الطابور يتأخّر ({$stuckMin} دقيقة)"; }

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
            $q = DB::table('error_events')->where('status', '!=', 'محلول')->where('last_seen', '>=', now()->subHour());
            $crit = hub_has_col('error_events', 'severity') ? (int) (clone $q)->where('severity', 'CRITICAL')->count() : 0;
            $high = hub_has_col('error_events', 'severity') ? (int) (clone $q)->where('severity', 'HIGH')->count() : 0;
            $hits = (int) (clone $q)->sum('count');
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
            $incidents = Schema::hasTable('incidents')
                ? (int) DB::table('incidents')->whereNull('deleted_at')->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])->where('meta', 'like', '%"kind":"security"%')->count() : 0;
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
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.' . $job], ['value' => now()->toIso8601String()]);
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.' . $job . '.meta'], ['value' => [
                'ms' => $ms, 'result' => $result, 'note' => $note ? mb_substr($note, 0, 180) : null, 'at' => now()->toIso8601String(),
            ]]);
            Cache::forget('settings:all');
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

    /** رسالةُ عطلٍ آمنةٌ للعرض: بلا كلمات مرور DSN */
    protected static function safe(string $m): string
    {
        return mb_substr((string) preg_replace('/(password|pwd|passwd)=\S+/i', '$1=***', $m), 0, 200);
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

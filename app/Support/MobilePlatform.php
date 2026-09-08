<?php

namespace App\Support;

use App\Models\MobileInstallation;
use App\Models\MobileSession;
use App\Models\PushDelivery;
use App\Models\PushToken;
use App\Support\Api;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * **مركزُ منصّة الجوال — خدمةُ القراءةِ المُجمِّعة** (Mobile Platform Center · §64).
 *
 * طبقةُ عرضٍ **فوق** الأنظمة القائمة لا بديلٌ عنها: تستدعي `PushService`،
 * `MobileOpenApi`، `MobileSessionService`، نماذجَ الجوال، الإعدادات، و`Health`
 * — ولا تُنشئ مخزناً ولا سجلاً ولا مزوّداً ثانياً (**تكرارُ الخلفيّة = 0**).
 *
 * **صدقٌ لا تزيين:** الحالاتُ الخارجيّة (دفع/روابط عميقة/إصدارات) تقول
 * `NOT_CONFIGURED` صراحةً حين لا تُضبط — لا نجاحٌ أخضرُ مزيّف. ولا سرَّ يُعرَض:
 * لا تجزئةَ جلسةٍ ولا رمزَ دفعٍ ولا مفتاحَ FCM — حضورٌ لا قيمة.
 */
class MobilePlatform
{
    /* حالاتٌ دلاليّة (spec §57) — تُلوَّن بأصنافِ الشارات القائمة */
    public const READY = 'READY';
    public const HEALTHY = 'HEALTHY';
    public const DEGRADED = 'DEGRADED';
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';
    public const ERROR = 'ERROR';
    public const IMPLEMENTED = 'IMPLEMENTED';

    /** نبرةُ الشارة لكلِّ حالة (ok/wn/bad/g — أصنافٌ قائمة) */
    public const TONE = [
        self::READY => 'ok', self::HEALTHY => 'ok', self::IMPLEMENTED => 'ok',
        self::DEGRADED => 'wn', self::NOT_CONFIGURED => 'g', self::ERROR => 'bad',
    ];

    public const LABEL = [
        self::READY => 'جاهز', self::HEALTHY => 'سليم', self::IMPLEMENTED => 'مُنفَّذ',
        self::DEGRADED => 'منقوص', self::NOT_CONFIGURED => 'غير مُهيّأ', self::ERROR => 'عُطل',
    ];

    /* ════════════════════════ الدفع ════════════════════════ */

    /** حالةُ الدفعِ الصادقة — من PushService (لا سرّ) + عدُّ الرموز الحيّة */
    public static function push(): array
    {
        $s = PushService::status();   // driver/configured/requested/has_project_id/has_access_token
        $tokens = self::tableReady('push_tokens', 'token')
            ? PushToken::query()
            : null;

        $s['tokens_active']  = $tokens ? (clone $tokens)->whereNull('revoked_at')->count() : 0;
        $s['tokens_total']   = $tokens ? (clone $tokens)->count() : 0;
        $s['tokens_ios']     = $tokens ? (clone $tokens)->whereNull('revoked_at')->where('platform', 'ios')->count() : 0;
        $s['tokens_android'] = $tokens ? (clone $tokens)->whereNull('revoked_at')->where('platform', 'android')->count() : 0;
        $s['state'] = $s['configured'] ? self::READY : self::NOT_CONFIGURED;

        return $s;
    }

    /** إحصاءُ التسليم آخرَ N يوم (نجاح/فشل) — لا أسرار */
    public static function pushDeliveryStats(int $days = 7): array
    {
        if (! self::tableReady('push_deliveries', 'status')) {
            return ['recent_ok' => 0, 'recent_failed' => 0, 'recent_total' => 0];
        }
        $since = now()->subDays(max(1, $days));
        $base = PushDelivery::where('queued_at', '>=', $since);
        $ok = (clone $base)->whereIn('status', ['delivered', 'attempted'])->count();
        $failed = (clone $base)->where('status', 'failed')->count();

        return ['recent_ok' => $ok, 'recent_failed' => $failed, 'recent_total' => (clone $base)->count()];
    }

    /* ════════════════════════ الجلسات والأجهزة ════════════════════════ */

    /** إحصاءُ الجلسات — نشطة/مُبطَلة/مستعملة حديثاً (لا تجزئةَ رمزٍ قط) */
    public static function sessionStats(): array
    {
        if (! self::tableReady('mobile_sessions', 'family_id')) {
            return ['active' => 0, 'revoked' => 0, 'recent' => 0, 'total' => 0];
        }
        $q = MobileSession::query();

        return [
            'total'   => (clone $q)->count(),
            'active'  => (clone $q)->whereNull('revoked_at')->where('access_expires_at', '>', now())->count(),
            'revoked' => (clone $q)->whereNotNull('revoked_at')->count(),
            'recent'  => (clone $q)->where('last_used_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /** إحصاءُ التنصيبات — كلّية/iOS/Android/آخر ظهور */
    public static function installStats(): array
    {
        if (! self::tableReady('mobile_installations', 'platform')) {
            return ['total' => 0, 'ios' => 0, 'android' => 0, 'last_seen' => null];
        }
        $q = MobileInstallation::query();
        $last = (clone $q)->whereNotNull('last_seen_at')->max('last_seen_at');

        return [
            'total'     => (clone $q)->count(),
            'ios'       => (clone $q)->where('platform', 'ios')->count(),
            'android'   => (clone $q)->where('platform', 'android')->count(),
            'last_seen' => $last,
        ];
    }

    /* ════════════════════════ الإصدارات وبوّابة التحديث ════════════════════════ */

    /** إعداداتُ إصدارِ التطبيق + صحّةُ الضبط (min ≤ latest حين يُدعَم semver) */
    public static function versions(): array
    {
        $get = fn (string $k) => trim((string) setting($k, ''));
        $ios = ['latest' => $get('mobile.latest_version_ios'), 'min' => $get('mobile.min_version_ios'),
            'store' => $get('mobile.store_url_ios')];
        $and = ['latest' => $get('mobile.latest_version_android'), 'min' => $get('mobile.min_version_android'),
            'store' => $get('mobile.store_url_android')];

        $force = (bool) setting('mobile.force_update', config('hub.mobile.version_gate.force_update', false));

        return [
            'ios'          => $ios + ['invalid' => self::versionInvalid($ios['min'], $ios['latest'])],
            'android'      => $and + ['invalid' => self::versionInvalid($and['min'], $and['latest'])],
            'force_update' => $force,
            // بوّابةٌ فعّالة = ثمّة حدٌّ أدنى مضبوطٌ لأيِّ منصّة (وإلّا لا حجب)
            'gate_active'  => $ios['min'] !== '' || $and['min'] !== '',
            'configured'   => $ios['latest'] !== '' || $and['latest'] !== '' || $ios['min'] !== '' || $and['min'] !== '',
        ];
    }

    /** min > latest = ضبطٌ باطل (حين يُدعَم semver على الطرفين) */
    public static function versionInvalid(string $min, string $latest): bool
    {
        if ($min === '' || $latest === '') return false;
        if (! preg_match('/^\d+(\.\d+)*$/', $min) || ! preg_match('/^\d+(\.\d+)*$/', $latest)) return false;

        return version_compare($min, $latest, '>');
    }

    /* ════════════════════════ الروابط العميقة ════════════════════════ */

    /**
     * حالةُ الروابط العميقة لكلِّ منصّة (CONFIGURED/NOT_CONFIGURED) — تُقرأ من نفس
     * مفاتيح `MobileWellKnownController` (setting فوق config)، ويحرسُ اختبارٌ عدمَ
     * انحرافِها عن ترويسة `/.well-known/*` الحيّة (لا مصدرٌ ثانٍ يتباعد).
     */
    public static function deepLinks(): array
    {
        $dl = (array) config('hub.mobile.deep_links', []);
        $team = trim((string) setting('mobile.dl_apple_team_id', $dl['apple']['team_id'] ?? ''));
        $bundle = trim((string) setting('mobile.dl_apple_bundle_id', $dl['apple']['bundle_id'] ?? ''));
        $pkg = trim((string) setting('mobile.dl_android_package', $dl['android']['package_name'] ?? ''));
        $fpRaw = setting('mobile.dl_android_fingerprints', null);
        $fp = $fpRaw !== null && trim((string) $fpRaw) !== ''
            ? array_values(array_filter(preg_split('/[\s,]+/', trim((string) $fpRaw))))
            : (array) ($dl['android']['sha256_cert_fingerprints'] ?? []);

        $appleOk = $team !== '' && $bundle !== '';
        $andOk = $pkg !== '' && ! empty($fp);

        return [
            'serve'   => (bool) ($dl['serve'] ?? true),
            'apple'   => ['configured' => $appleOk, 'has_team_id' => $team !== '', 'has_bundle_id' => $bundle !== '',
                'state' => $appleOk ? self::READY : self::NOT_CONFIGURED],
            'android' => ['configured' => $andOk, 'has_package' => $pkg !== '', 'has_fingerprints' => ! empty($fp),
                'state' => $andOk ? self::READY : self::NOT_CONFIGURED],
        ];
    }

    /* ════════════════════════ الـAPI والمزامنة ════════════════════════ */

    /** معلوماتُ الـMobile API — الفضاءُ/النسخة/عددُ المسارات/OpenAPI (لا أسرار) */
    public static function apiInfo(): array
    {
        return [
            'namespace'    => '/' . MobileOpenApi::PREFIX,
            'version'      => (string) config('hub.mobile.api_version', Api::VERSION),
            'route_count'  => self::routeCount(),
            'auth'         => 'Bearer (جلسةُ جوالٍ أصيلة — رمزُ وصولٍ قصيرُ الأجل)',
            'openapi_url'  => '/' . MobileOpenApi::PREFIX . '/openapi.json',
            'request_id'   => 'كلُّ ردٍّ يحمل X-Request-Id وX-API-Version',
        ];
    }

    /** عددُ مسارات الجوال المُسجَّلة (method+path فريد تحت البادئة) — حيٌّ لا قائمةٌ يدويّة */
    public static function routeCount(): int
    {
        $seen = [];
        foreach (RouteFacade::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), MobileOpenApi::PREFIX)) continue;
            foreach ($r->methods() as $m) {
                if (in_array($m, ['HEAD', 'OPTIONS'], true)) continue;
                $seen[$m . ' ' . $r->uri()] = true;
            }
        }

        return count($seen);
    }

    /** تصنيفُ المزامنة + التغطية — من سجلِّ القدرات المُولَّد (لا سجلَّ ثانٍ) */
    public static function sync(): array
    {
        $caps = MobileOpenApi::capabilities();

        return $caps['sync'] ?? ['coverage' => [], 'classes' => [], 'modules' => []];
    }

    /* ════════════════════════ نظرةٌ عامّة + بطاقة الجاهزية ════════════════════════ */

    /**
     * بطاقاتُ النظرة العامّة (spec §7/§56) — قيمٌ حقيقيّةٌ فقط، وحالاتٌ صادقة تكشف
     * ما يحتاج ضبطاً (رابطُ «هيّئ →» حيث NOT_CONFIGURED).
     */
    public static function overview(): array
    {
        $push = self::push();
        $sess = self::sessionStats();
        $inst = self::installStats();
        $ver = self::versions();
        $dl = self::deepLinks();
        $api = self::apiInfo();

        return compact('push', 'sess', 'inst', 'ver', 'dl', 'api');
    }

    /**
     * بطاقةُ الجاهزية (spec §8): حالةُ كلِّ قدرةٍ من فحصٍ حقيقيّ حيث أمكن، و`IMPLEMENTED`
     * حين لا يُوثَق بالفحص الحيّ (لا يُزيَّف فحصُ صحّة). صفّان: قدراتٌ مُنفَّذةٌ في الكود
     * (حاضرةٌ ما دام مسارُها مُسجَّلاً)، وقدراتٌ تعتمد ضبطاً خارجيّاً (READY/NOT_CONFIGURED).
     *
     * @return array<int,array{key:string,label:string,state:string}>
     */
    public static function scorecard(): array
    {
        $has = fn (string $name) => RouteFacade::getRoutes()->getByName($name) !== null;
        $impl = fn (bool $ok) => $ok ? self::IMPLEMENTED : self::ERROR;
        $push = self::push();
        $ver = self::versions();
        $dl = self::deepLinks();

        $rows = [
            ['auth', 'المصادقة', $impl($has('mobile.auth.login'))],
            ['mfa', 'التحقّق بخطوتين (MFA)', $impl($has('mobile.auth.mfa_verify'))],
            ['stepup', 'تصعيدُ الهوية (Step-Up)', $impl($has('mobile.auth.step_up'))],
            ['permissions', 'الصلاحيات والتنطيق', self::IMPLEMENTED],
            ['context', 'سياقُ الشركة/العميل', $impl($has('mobile.context'))],
            ['bootstrap', 'الإقلاع', $impl($has('mobile.bootstrap'))],
            ['appconfig', 'app-config', $impl($has('mobile.app_config'))],
            ['schema', 'المخطّط', $impl($has('mobile.schema'))],
            ['crud', 'CRUD العامّ', $impl($has('mobile.resource.index'))],
            ['actions', 'الإجراءاتُ المخصّصة', $impl($has('mobile.resource.actions'))],
            ['approvals', 'الموافقات', $impl($has('mobile.approvals.index'))],
            ['search', 'البحث', $impl($has('mobile.search'))],
            ['notifications', 'الإشعارات', $impl($has('mobile.notifications.index'))],
            ['push', 'الدفع', $push['configured'] ? self::READY : self::NOT_CONFIGURED],
            ['comments', 'التعليقات', $impl($has('mobile.comments.index'))],
            ['dms', 'الرسائل المباشرة', $impl($has('mobile.dm.threads'))],
            ['files', 'الملفات', $impl($has('mobile.files.upload_session'))],
            ['scanner', 'الماسح', $impl($has('mobile.identity.resolve'))],
            ['tracking', 'تتبّعُ الموقع', $impl($has('mobile.tracking.start'))],
            ['sync', 'المزامنةُ دون اتصال', $impl($has('mobile.sync'))],
            ['concurrency', 'التزامن (If-Match)', self::IMPLEMENTED],
            ['idempotency', 'عدمُ التكرار (Idempotency-Key)', self::IMPLEMENTED],
            ['deeplinks', 'الروابط العميقة', ($dl['apple']['configured'] || $dl['android']['configured']) ? self::READY : self::NOT_CONFIGURED],
            ['versiongate', 'بوّابةُ الإصدار', $ver['gate_active'] ? self::READY : self::NOT_CONFIGURED],
            ['openapi', 'OpenAPI', $impl($has('mobile.openapi'))],
        ];

        return array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'state' => $r[2]], $rows);
    }

    /* ════════════════════════ الصحّة (§37) ════════════════════════ */

    /**
     * صحّةُ منصّة الجوال — مكوّناتٌ بنمطِ `Health::c` (status/label/why/tone). التبعيّاتُ
     * الخارجيّة (دفع/روابط/إصدار) تُصنَّف `NOT_CONFIGURED` لا `ERROR` (§37: لا يُحوَّل
     * شرطُ إطلاقٍ إلى عُطلِ نظام). الإجماليُّ **أسوأُ حالةٍ من المكوّنات الجوهرية فقط**.
     */
    public static function health(): array
    {
        $has = fn (string $name) => RouteFacade::getRoutes()->getByName($name) !== null;
        $push = self::push();
        $dl = self::deepLinks();
        $ver = self::versions();

        $core = [
            self::c('routes', 'مسارات الجوال', self::routeCount() > 0 ? self::HEALTHY : self::ERROR,
                self::routeCount() . ' مسارٌ مُسجَّل', ['count' => self::routeCount()]),
            self::c('openapi', 'مواصفةُ OpenAPI', $has('mobile.openapi') ? self::HEALTHY : self::ERROR,
                $has('mobile.openapi') ? 'متاحةٌ ومُولَّدة' : 'مسارُ المواصفة غائب'),
            self::c('auth', 'بنيةُ المصادقة', $has('mobile.auth.login') && $has('mobile.bootstrap') ? self::HEALTHY : self::ERROR,
                'جلساتٌ أصيلةٌ + تدويرٌ + MFA/Step-Up'),
            self::c('tables', 'جداولُ الجوال', self::tablesHealthy() ? self::HEALTHY : self::ERROR,
                self::tablesHealthy() ? 'الجلسات/التنصيبات/رموز الدفع/التسليم موجودة' : 'جدولٌ مفقودٌ — ترحيلٌ معلّق؟'),
            self::c('sync', 'سجلُّ المزامنة', $has('mobile.sync') ? self::HEALTHY : self::ERROR,
                'تصنيفُ المزامنة مُشتقٌّ من سجلِّ القدرات'),
        ];
        $external = [
            self::c('push', 'مزوّدُ الدفع', $push['configured'] ? self::HEALTHY : self::NOT_CONFIGURED,
                $push['configured'] ? 'المزوّد: ' . $push['driver'] : 'لا مزوّد — NullPushProvider (لا نجاحٌ مزيّف)'),
            self::c('deeplinks', 'الروابط العميقة', ($dl['apple']['configured'] || $dl['android']['configured']) ? self::HEALTHY : self::NOT_CONFIGURED,
                'معرّفاتُ Apple/Android قبل الإطلاق'),
            self::c('versions', 'بوّابةُ الإصدار', $ver['configured'] ? self::HEALTHY : self::NOT_CONFIGURED,
                $ver['ios']['invalid'] || $ver['android']['invalid'] ? 'ضبطٌ باطل: الحدُّ الأدنى أعلى من الأحدث' : 'إصداراتٌ ومتاجرُ التطبيق'),
        ];
        // ضبطٌ باطلٌ للإصدار = DEGRADED (خطأُ ضبطٍ حقيقيّ لا مجرّد غياب)
        if ($ver['ios']['invalid'] || $ver['android']['invalid']) {
            $external[2]['status'] = self::DEGRADED;
            $external[2]['tone'] = self::TONE[self::DEGRADED];
        }

        // الإجماليُّ: أسوأُ الجوهرية (external لا يُهبِط النظامَ — §37)
        $ranks = [self::HEALTHY => 0, self::DEGRADED => 1, self::ERROR => 2];
        $worst = self::HEALTHY;
        foreach ($core as $c) {
            if (($ranks[$c['status']] ?? 0) > ($ranks[$worst] ?? 0)) $worst = $c['status'];
        }

        return ['status' => $worst, 'label' => self::LABEL[$worst] ?? $worst,
            'core' => $core, 'external' => $external, 'at' => now()->toIso8601String()];
    }

    /* ════════════════════════ داخليّ ════════════════════════ */

    /** مكوّنُ صحّةٍ بنمطِ Health::c */
    protected static function c(string $key, string $label, string $status, string $why, array $data = []): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status,
            'why' => $why, 'tone' => self::TONE[$status] ?? 'g', 'data' => $data];
    }

    /** جداولُ الجوال الأربعةُ حاضرةٌ (سلامةُ ما قبل الهجرة) */
    protected static function tablesHealthy(): bool
    {
        return self::tableReady('mobile_sessions', 'family_id')
            && self::tableReady('mobile_installations', 'platform')
            && self::tableReady('push_tokens', 'token')
            && self::tableReady('push_deliveries', 'status');
    }

    protected static function tableReady(string $table, string $col): bool
    {
        return hub_has_col($table, $col);
    }
}

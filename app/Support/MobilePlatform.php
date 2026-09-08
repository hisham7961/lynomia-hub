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

    /**
     * سجلُّ محاولاتِ التسليم (§17) — مُرشَّحٌ مُصفَّحٌ مرتّبٌ حتميّاً. صفوفٌ **آمنةٌ
     * بالبناء**: `push_deliveries` لا يحمل رمزاً ولا نصَّ إشعارٍ أصلاً (هويّاتٌ وحالةٌ
     * وصنفُ خطأٍ فقط) — فلا سرَّ يُعرَض. الترشيحُ بـallowlist (لا حقنَ عمود).
     */
    public static function deliveries(array $f = [], int $per = 25)
    {
        if (! self::tableReady('push_deliveries', 'status')) {
            return new \Illuminate\Pagination\Paginator([], $per);
        }
        $q = PushDelivery::query();
        if (($s = $f['status'] ?? '') !== '' && in_array($s, PushDelivery::STATUSES, true)) $q->where('status', $s);
        if (($p = $f['provider'] ?? '') !== '' && in_array($p, PushToken::PROVIDERS, true)) $q->where('provider', $p);

        return $q->orderByDesc('queued_at')->orderByDesc('id')->simplePaginate($per)->withQueryString();
    }

    /**
     * تفصيلُ التسليم آخرَ N يوم (§18): عددٌ لكلِّ حالةٍ + لكلِّ صنفِ خطأٍ تقنيّ — كي
     * يرى المسؤولُ **لماذا** فشل الدفعُ (مزوّد/رمزٌ باطل/خنق) لا مجرّدَ رقمٍ إجماليّ.
     */
    public static function deliveryBreakdown(int $days = 7): array
    {
        if (! self::tableReady('push_deliveries', 'status')) {
            return ['statuses' => [], 'errors' => [], 'total' => 0, 'days' => $days];
        }
        $since = now()->subDays(max(1, $days));
        $base = PushDelivery::where('queued_at', '>=', $since);

        $statuses = (clone $base)->selectRaw('status, COUNT(*) c')->groupBy('status')
            ->orderBy('status')->pluck('c', 'status')->all();
        $errors = (clone $base)->whereNotNull('error_category')
            ->selectRaw('error_category, COUNT(*) c')->groupBy('error_category')
            ->orderBy('error_category')->pluck('c', 'error_category')->all();

        return ['statuses' => $statuses, 'errors' => $errors, 'total' => (clone $base)->count(), 'days' => $days];
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

    /* ════════════════════════ قوائمُ الجلسات والأجهزة (§12–15) ════════════════════════ */

    /** الأعمدةُ الآمنةُ للجلسة — **بلا تجزئةِ رمزٍ قط** (access/refresh/prev_refresh مُستبعَدة) */
    private const SESSION_COLS = ['id', 'user_id', 'installation_id', 'family_id', 'platform',
        'app_version', 'last_ip', 'last_used_at', 'access_expires_at', 'refresh_expires_at',
        'revoked_at', 'revoked_reason', 'created_at'];

    /** جلساتٌ مُرشَّحةٌ مُصفَّحة (paginator) — أعمدةٌ آمنةٌ فقط، مرتّبةٌ حتميّاً */
    public static function sessions(array $f = [], int $per = 25)
    {
        if (! self::tableReady('mobile_sessions', 'family_id')) {
            return new \Illuminate\Pagination\Paginator([], $per);
        }
        $q = MobileSession::query()->select(self::SESSION_COLS)->with('user:id,name,email');

        if (($p = $f['platform'] ?? '') !== '' && in_array($p, ['ios', 'android'], true)) $q->where('platform', $p);
        if (($s = $f['status'] ?? '') === 'active') $q->whereNull('revoked_at')->where('refresh_expires_at', '>', now());
        elseif ($s === 'revoked') $q->whereNotNull('revoked_at');
        self::applyUserFilter($q, $f['q'] ?? '');

        return $q->orderByDesc('last_used_at')->orderByDesc('id')->simplePaginate($per)->withQueryString();
    }

    /** تنصيباتٌ مُرشَّحةٌ مُصفَّحة (paginator) */
    public static function installations(array $f = [], int $per = 25)
    {
        if (! self::tableReady('mobile_installations', 'platform')) {
            return new \Illuminate\Pagination\Paginator([], $per);
        }
        $q = MobileInstallation::query()->with('user:id,name,email');
        if (($p = $f['platform'] ?? '') !== '' && in_array($p, ['ios', 'android'], true)) $q->where('platform', $p);
        self::applyUserFilter($q, $f['q'] ?? '');

        return $q->orderByDesc('last_seen_at')->orderByDesc('id')->simplePaginate($per)->withQueryString();
    }

    /** ترشيحٌ باسم/بريدِ المستخدم (LIKE مهرَّبٌ في المحرّكين) */
    protected static function applyUserFilter($q, string $term): void
    {
        $term = trim($term);
        if ($term === '') return;
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        $q->whereHas('user', fn ($u) => $u->where(fn ($w) => $w
            ->whereRaw('name LIKE ? ESCAPE \'!\'', [$like])
            ->orWhereRaw('email LIKE ? ESCAPE \'!\'', [$like])));
    }

    /** حالةُ الجلسة الدلاليّة: revoked | active | expired (+ نبرة) */
    public static function sessionStatus(MobileSession $s): array
    {
        if ($s->revoked_at) return ['key' => 'revoked', 'label' => 'مُبطَلة', 'tone' => 'bad'];
        if ($s->refresh_expires_at && now()->lte($s->refresh_expires_at)) return ['key' => 'active', 'label' => 'نشطة', 'tone' => 'ok'];

        return ['key' => 'expired', 'label' => 'منتهية', 'tone' => 'g'];
    }

    /** جلسةٌ واحدةٌ بأعمدتها الآمنة (للتفتيش) أو null */
    public static function session(string $id): ?MobileSession
    {
        if (! self::tableReady('mobile_sessions', 'family_id')) return null;

        return MobileSession::query()->select(self::SESSION_COLS)->with('user:id,name,email')->find($id);
    }

    /**
     * جهاز 360 (§15): بياناتُ التنصيب + جلساتُه + حالةُ رموز دفعِه + آخرُ تسليماته.
     * **لا رمزَ دفعٍ ولا تجزئةَ جلسةٍ** — حضورٌ وحالةٌ لا قيمة.
     */
    public static function deviceDetail(string $installId): ?array
    {
        if (! self::tableReady('mobile_installations', 'platform')) return null;
        $inst = MobileInstallation::with('user:id,name,email')->find($installId);
        if (! $inst) return null;

        $sessions = self::tableReady('mobile_sessions', 'family_id')
            ? MobileSession::query()->select(self::SESSION_COLS)
                ->where('installation_id', $inst->id)->orderByDesc('last_used_at')->orderByDesc('id')->limit(20)->get()
            : collect();

        $tokens = self::tableReady('push_tokens', 'token')
            ? PushToken::where('installation_id', $inst->id)
                ->orderByDesc('created_at')->orderBy('id')
                ->get(['id', 'platform', 'provider', 'last_confirmed_at', 'revoked_at'])   // لا عمودَ token
            : collect();

        $deliveries = self::tableReady('push_deliveries', 'status')
            ? PushDelivery::where('installation_id', $inst->id)
                ->orderByDesc('queued_at')->orderByDesc('id')->limit(10)->get()
            : collect();

        return ['inst' => $inst, 'sessions' => $sessions, 'tokens' => $tokens, 'deliveries' => $deliveries];
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

    /**
     * **معاينةُ app-config** (§21/§23) — تستدعي نقطةَ `MobileAuthController@appConfig`
     * **الحيّةَ نفسَها** بترويسات المنصّة/الإصدار، فتُظهر **ما يتلقّاه العميلُ فعلاً**
     * (بوّابةُ الإصدارِ وفعّاليّتها `update_required` لإصدارٍ مُفترَض، روابطُ المتجر،
     * الصيانة). لا إعادةَ بناءِ منطقٍ (تكرارُ الخلفيّة = 0) ولا سرّ (النقطةُ عامّةٌ
     * مُنقّاةٌ سلفاً). فشلٌ ⇒ مصفوفةٌ فارغةٌ صادقةٌ لا اختلاق.
     */
    public static function appConfigPreview(string $platform = '', string $appVersion = ''): array
    {
        $req = \Illuminate\Http\Request::create('/' . MobileOpenApi::PREFIX . '/app-config', 'GET');
        $platform = strtolower(trim($platform));
        if (in_array($platform, ['ios', 'android'], true)) $req->headers->set('X-Lynomia-App-Platform', $platform);
        if (($v = trim($appVersion)) !== '') $req->headers->set('X-Lynomia-App-Version', $v);

        try {
            $resp = app(\App\Http\Controllers\Api\MobileAuthController::class)->appConfig($req);
            $data = json_decode($resp->getContent(), true);

            return is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * **مُختبِرُ الرابطِ العميقِ الدلاليّ** (§24) — يتحقّق من الوجهةِ القانونيّة
     * `{module,id,action}` على **سجلِّ الوحدات الحقيقيّ** (`hub_mod`) ويُظهر الرابطَ
     * العالميَّ المُقابِل (`route('m.show')` — نفسُ ما يُنتجه `NotificationLink::webUrl`،
     * لا خريطةَ ثانية). **قراءةٌ صرفة:** لا يجلب السجلَّ ولا يكشف بياناته (لا IDOR)،
     * و`linkable` يقول صدقاً هل ثمّة رابطٌ عالميٌّ فعّالٌ يفتح التطبيق (روابطُ مضبوطة).
     */
    public static function deepLinkResolve(string $module, string $id, string $action = 'show'): array
    {
        $module = trim($module);
        $id = trim($id);
        $action = trim($action) !== '' ? trim($action) : 'show';
        $registered = $module !== '' && hub_mod($module) !== null;
        $valid = $registered && $id !== '';
        $dl = self::deepLinks();
        $url = null;
        if ($valid) {
            try { $url = route('m.show', [$module, $id]); } catch (\Throwable $e) { $url = null; }
        }

        return [
            'module'     => $module, 'id' => $id, 'action' => $action,
            'registered' => $registered, 'valid' => $valid,
            'canonical'  => $valid ? ['module' => $module, 'id' => $id, 'action' => $action] : null,
            'path'       => $valid ? '/m/' . rawurlencode($module) . '/' . rawurlencode($id) : null,
            'url'        => $url,
            'linkable'   => $dl['apple']['configured'] || $dl['android']['configured'],
        ];
    }

    /**
     * **حالةُ وثيقتَي الربطِ العالميّة** (§25/§39) — تستدعي `MobileWellKnownController`
     * الحيَّ نفسَه فتقرأ ترويسةَ `X-Deep-Links-Status` (CONFIGURED/NOT_CONFIGURED) —
     * لا إعادةَ بناء. `serve=false` ⇒ الوثيقتان معطّلتان (تُخدَمان 404 عمداً).
     */
    public static function wellKnown(): array
    {
        $serve = (bool) data_get((array) config('hub.mobile.deep_links', []), 'serve', true);
        $out = [
            'serve'          => $serve,
            'aasa_url'       => url('/.well-known/apple-app-site-association'),
            'assetlinks_url' => url('/.well-known/assetlinks.json'),
            'aasa'           => ['status' => 'DISABLED', 'configured' => false],
            'assetlinks'     => ['status' => 'DISABLED', 'configured' => false],
        ];
        if (! $serve) return $out;

        $c = app(\App\Http\Controllers\Web\MobileWellKnownController::class);
        $read = fn ($resp) => [
            'status'     => (string) $resp->headers->get('X-Deep-Links-Status', 'NOT_CONFIGURED'),
            'configured' => $resp->headers->get('X-Deep-Links-Status') === 'CONFIGURED',
        ];
        try { $out['aasa'] = $read($c->appleAppSiteAssociation()); } catch (\Throwable $e) { report($e); }
        try { $out['assetlinks'] = $read($c->assetLinks()); } catch (\Throwable $e) { report($e); }

        return $out;
    }

    /**
     * **قائمةُ فحصِ الإطلاق** (§38/§39) — ما يحتاجه فريقُ التطبيق قبل النشر، بحالاتٍ
     * حقيقيّة (READY/NOT_CONFIGURED) من الإعدادات القائمة. لكلِّ بندٍ مِرساةُ ضبطٍ في
     * صفحةِ الإعدادات (لا نظامَ إعداداتٍ ثانٍ). صدقٌ: `NOT_CONFIGURED` شرطُ إطلاقٍ لا عُطل.
     *
     * @return array<int,array{label:string,state:string,hint:string,anchor:string}>
     */
    public static function launchChecklist(): array
    {
        $ver = self::versions();
        $dl = self::deepLinks();
        $push = self::push();
        $row = fn (string $label, bool $ok, string $hint, string $anchor) => [
            'label' => $label, 'state' => $ok ? self::READY : self::NOT_CONFIGURED, 'hint' => $hint, 'anchor' => $anchor];

        return [
            $row('روابطُ iOS العميقة (Team ID + Bundle ID)', $dl['apple']['configured'], 'Universal Links', 'mobile.dl_apple_team_id'),
            $row('روابطُ Android العميقة (Package + بصمات SHA-256)', $dl['android']['configured'], 'App Links', 'mobile.dl_android_fingerprints'),
            $row('رابطُ متجرِ iOS', $ver['ios']['store'] !== '', 'App Store', 'mobile.store_url_ios'),
            $row('رابطُ متجرِ Android', $ver['android']['store'] !== '', 'Google Play', 'mobile.store_url_android'),
            $row('إصدارُ iOS (الأحدث + الأدنى)', $ver['ios']['latest'] !== '' && $ver['ios']['min'] !== '', 'بوّابةُ التحديث', 'mobile.min_version_ios'),
            $row('إصدارُ Android (الأحدث + الأدنى)', $ver['android']['latest'] !== '' && $ver['android']['min'] !== '', 'بوّابةُ التحديث', 'mobile.min_version_android'),
            $row('مزوّدُ الدفع (FCM)', $push['configured'], 'الإشعارات', 'mobile.push_driver'),
        ];
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

    /**
     * **سجلُّ القدرات الحيّ** (§26–31) — تفويضٌ صرفٌ لـ`MobileOpenApi::capabilities()`
     * المُشتقِّ من المسارات الحيّة (لا سجلَّ ثانٍ يُحرَّر). يحمل: المجالاتِ ومساراتِها
     * (مُستكشِفُ المسارات)، وعقدَ المصادقة، وأكوادَ الخطأ، والتزامنَ، وعدمَ التكرار،
     * وتصنيفَ المزامنة وتغطيتَه، وحالاتِ «غير مُهيّأ» — كلُّه صادقٌ من الكود لا مُختلَق.
     */
    public static function capabilities(): array
    {
        return MobileOpenApi::capabilities();
    }

    /* ════════════════════════ الأمن والتليمتري (§32–36) ════════════════════════ */

    /** أكوادُ الأحداثِ الأمنيّة — من `SecurityEvents::CODES` (لا تصنيفَ ثانٍ) */
    private static function securityCategories(): array
    {
        return array_keys(\App\Support\SecurityEvents::CODES);
    }

    /**
     * **موقفُ أمنِ مصادقةِ الجوال** (§32/§33) — من عقدِ المصادقة الحيّ (`capabilities`)
     * وبطاقةِ الجاهزية (`scorecard`) معاً؛ لا حقائقَ مُختلَقة ولا تكرار.
     */
    public static function securityPosture(): array
    {
        $caps = self::capabilities();

        return [
            'auth' => $caps['auth'] ?? [],
            'rows' => collect(self::scorecard())->whereIn('key', ['auth', 'mfa', 'stepup', 'permissions'])->values()->all(),
        ];
    }

    /**
     * **تدقيقُ الجوال** (§35) — قيودُ التدقيقِ ذاتُ `source='mobile'` (السكّةُ القائمة،
     * لا سجلَّ ثانٍ)، مُرشَّحةٌ مُصفَّحةٌ مرتّبةٌ حتميّاً، بأعمدةٍ **آمنة** (لا `before`/`after`
     * كي لا يبلغ ما زُرع فيهما الشاشة). فئةُ «security» = المجموعةُ الأمنيّةُ كلُّها.
     */
    public static function mobileAudit(array $f = [], int $per = 25)
    {
        if (! hub_has_col('audits', 'source')) {
            return new \Illuminate\Pagination\Paginator([], $per);
        }
        $q = \App\Models\AuditEntry::query()
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->where('audits.source', 'mobile')
            ->select('audits.id', 'audits.user_id', 'audits.action', 'audits.module', 'audits.category',
                'audits.severity', 'audits.outcome', 'audits.ip', 'audits.created_at', 'users.name as user_name');

        $cat = $f['category'] ?? '';
        if ($cat === 'security') $q->whereIn('audits.category', self::securityCategories());
        elseif ($cat !== '' && in_array($cat, self::securityCategories(), true)) $q->where('audits.category', $cat);
        if (in_array($out = $f['outcome'] ?? '', ['success', 'failed', 'denied'], true)) $q->where('audits.outcome', $out);

        return $q->orderByDesc('audits.created_at')->orderByDesc('audits.id')->simplePaginate($per)->withQueryString();
    }

    /** ملخّصُ تدقيقِ الجوال آخرَ N يوم: مآلٌ + أعلى الفئات + عددُ الأمنيّة (لا أسرار) */
    public static function mobileAuditStats(int $days = 30): array
    {
        if (! hub_has_col('audits', 'source')) {
            return ['total' => 0, 'outcomes' => [], 'top_categories' => [], 'security' => 0, 'days' => $days];
        }
        $since = now()->subDays(max(1, $days));
        $base = \App\Models\AuditEntry::query()->where('source', 'mobile')->where('created_at', '>=', $since);

        $outcomes = (clone $base)->whereNotNull('outcome')->selectRaw('outcome, COUNT(*) c')
            ->groupBy('outcome')->orderBy('outcome')->pluck('c', 'outcome')->all();
        $cats = (clone $base)->whereNotNull('category')->selectRaw('category, COUNT(*) c')
            ->groupBy('category')->orderByDesc('c')->orderBy('category')->limit(8)->pluck('c', 'category')->all();
        $security = (clone $base)->whereIn('category', self::securityCategories())->count();

        return ['total' => (clone $base)->count(), 'outcomes' => $outcomes, 'top_categories' => $cats,
            'security' => $security, 'days' => $days];
    }

    /**
     * **تبنّي الإصدارات والمنصّات** (§36) — تليمتري **حقيقيّ** من `mobile_installations`
     * (توزيعُ الإصدار/المنصّة/النشاطِ الحديث). **صادقٌ فارغٌ قبل الإطلاق** — لا أجهزةَ
     * ولا تبنٍّ مُختلَق. الترتيبُ حتميّ (عددٌ ثم مفتاح).
     */
    public static function adoption(): array
    {
        if (! self::tableReady('mobile_installations', 'platform')) {
            return ['total' => 0, 'platforms' => [], 'versions' => [], 'active_7d' => 0];
        }
        $q = MobileInstallation::query();
        $platforms = (clone $q)->selectRaw('platform, COUNT(*) c')->groupBy('platform')->orderBy('platform')->pluck('c', 'platform')->all();
        $versions = (clone $q)->whereNotNull('app_version')->where('app_version', '!=', '')
            ->selectRaw('app_version, COUNT(*) c')->groupBy('app_version')->orderByDesc('c')->orderBy('app_version')->limit(10)->pluck('c', 'app_version')->all();

        return [
            'total'     => (clone $q)->count(),
            'platforms' => $platforms,
            'versions'  => $versions,
            'active_7d' => (clone $q)->where('last_seen_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /** تسميةُ فئةِ الحدثِ الأمنيّ بالعربية (من SecurityEvents — أو الرمزُ خاماً) */
    public static function categoryLabel(?string $code): string
    {
        if ($code === null || $code === '') return '—';

        return \App\Support\SecurityEvents::CODES[$code][0] ?? $code;
    }

    /* ════════════════════════ الملفات والماسح والتتبّع (§40–42) ════════════════════════ */

    /**
     * **حالةُ الملفات** (§40) — القدرةُ (مسارات) + موقفُ فحصِ الفيروسات **تجميعاً**
     * (عددٌ لكلِّ حالةِ av_status) لا أسماءَ ملفاتٍ ولا محتوى. الوصولُ المصابُ محجوبٌ
     * ٤٢٣ عبر `AttachmentService` القائم (لا سكّةَ تنزيلٍ ثانية). لا مراقبة.
     */
    public static function filesStatus(): array
    {
        $has = fn (string $n) => RouteFacade::getRoutes()->getByName($n) !== null;
        $av = ['clean' => 0, 'infected' => 0, 'pending' => 0, 'error' => 0, 'total' => 0];
        if (self::tableReady('attachments', 'av_status')) {
            $rows = \Illuminate\Support\Facades\DB::table('attachments')
                ->selectRaw('av_status, COUNT(*) c')->groupBy('av_status')->orderBy('av_status')->pluck('c', 'av_status');
            foreach ($rows as $k => $v) $av[$k] = (int) $v;
            $av['total'] = array_sum(array_map('intval', $rows->all()));
        }

        return [
            'implemented' => $has('mobile.files.upload_session') && $has('mobile.files.upload_complete') && $has('mobile.files.download'),
            'av'          => $av,
            'infected_blocked' => true,   // AttachmentService يحجب المصابَ ٤٢٣ (نفسُ سكّة الويب)
            'private_only'     => true,    // قرصٌ خاصّ + توقيعٌ — لا رابطٌ عامّ (F.2)
        ];
    }

    /**
     * **حالةُ الماسح** (§41) — قدرةُ `identity/resolve` فقط (يحلّ الهويّةَ من مسحٍ عبر
     * `App\Support\Identity` القائم). **لا يخزّن صوراً ولا يراقب** — حالةٌ لا مراقبة.
     */
    public static function scannerStatus(): array
    {
        $has = fn (string $n) => RouteFacade::getRoutes()->getByName($n) !== null;

        return [
            'implemented' => $has('mobile.identity.resolve'),
            'reuses'      => 'App\\Support\\Identity (حلُّ الهويّة الموحَّد — لا مخزنَ ثانٍ)',
        ];
    }

    /**
     * **حالةُ التتبّع** (§42) — **تجميعٌ وموافقةٌ فقط، لا نقاطَ ولا مساراتٍ قطّ**
     * (`track_points`/`simplified` لا تُقرأ أبداً — لا مراقبة). يكشف عددَ الجلسات
     * وحالتَها وتغطيةَ الموافقة (شذوذُ «تتبّعٌ بلا إقرار» يظهر صادقاً إن وُجد).
     */
    public static function trackingStatus(): array
    {
        $has = fn (string $n) => RouteFacade::getRoutes()->getByName($n) !== null;
        $out = [
            'implemented'      => $has('mobile.tracking.start') && $has('mobile.tracking.points') && $has('mobile.tracking.end'),
            'consent_required' => true,
            'sessions'         => ['active' => 0, 'ended' => 0, 'total' => 0],
            'consent'          => ['with' => 0, 'without' => 0],
        ];
        if (self::tableReady('track_sessions', 'consent_at')) {
            $q = \Illuminate\Support\Facades\DB::table('track_sessions');
            $out['sessions']['total']  = (clone $q)->count();
            $out['sessions']['active'] = (clone $q)->where('status', 'نشطة')->count();
            $out['sessions']['ended']  = (clone $q)->where('status', 'منتهية')->count();
            $out['consent']['with']    = (clone $q)->whereNotNull('consent_at')->count();
            $out['consent']['without'] = (clone $q)->whereNull('consent_at')->count();
        }

        return $out;
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

    /* ════════════════════════ الوثائق (§43/§88) ════════════════════════ */

    /** مجلّداتُ الوثائق المسموحة (allowlist — لا مسارَ عشوائيّ) */
    private const DOC_DIRS = ['readiness' => 'mobile-readiness', 'center' => 'mobile-platform-center'];

    /**
     * **فهرسُ الوثائق** (§88) — يُبنى من نظامِ الملفات الحيّ (لا قائمةٌ يدويّةٌ تتباعد):
     * وثائقُ جاهزيّة الجوال ووثائقُ المركز، مع مراجعَ حيّةٍ مخدومة (OpenAPI/well-known).
     */
    public static function documentation(): array
    {
        $set = function (string $key): array {
            $dir = base_path('docs/' . self::DOC_DIRS[$key]);
            if (! is_dir($dir)) return [];
            $files = glob($dir . '/*.md') ?: [];
            sort($files);

            return array_map(fn ($f) => [
                'set' => $key, 'name' => basename($f),
                'path' => 'docs/' . self::DOC_DIRS[$key] . '/' . basename($f),
                'title' => self::docTitle($f),
            ], $files);
        };

        return [
            'readiness' => $set('readiness'),
            'center'    => $set('center'),
            'live'      => [
                'openapi'    => '/' . MobileOpenApi::PREFIX . '/openapi.json',
                'aasa'       => '/.well-known/apple-app-site-association',
                'assetlinks' => '/.well-known/assetlinks.json',
            ],
        ];
    }

    /** عنوانٌ ودّيٌّ لوثيقةٍ — أوّلُ عنوانِ Markdown (`# …`) أو اسمُ الملف */
    private static function docTitle(string $path): string
    {
        if (($fh = @fopen($path, 'r')) !== false) {
            for ($i = 0; $i < 15 && ($line = fgets($fh)) !== false; $i++) {
                $line = trim($line);
                if (str_starts_with($line, '# ')) { fclose($fh); return trim(substr($line, 2)); }
            }
            fclose($fh);
        }

        return basename($path, '.md');
    }

    /**
     * **قراءةٌ آمنةٌ لوثيقةٍ** (§88 · عرضٌ آمن) — تُتحقَّق المجموعةُ من allowlist والاسمُ
     * من قائمةِ الملفات الحقيقيّة عبر `realpath` (لا اجتيازَ مسار `../`). تعيد النصَّ
     * الخام (يُطمَس بـBlade عند العرض — لا حقنَ HTML) أو null. مقصوصةٌ بحدٍّ أقصى.
     */
    public static function docContent(string $set, string $name): ?string
    {
        if (! isset(self::DOC_DIRS[$set])) return null;
        $name = basename($name);
        if (! preg_match('/^[A-Za-z0-9._-]+\.md$/', $name)) return null;

        $real = realpath(base_path('docs/' . self::DOC_DIRS[$set] . '/' . $name));
        $allowed = array_map('realpath', glob(base_path('docs/' . self::DOC_DIRS[$set] . '/*.md')) ?: []);
        if ($real === false || ! in_array($real, $allowed, true)) return null;

        $raw = @file_get_contents($real);

        return $raw === false ? null : mb_substr($raw, 0, 100000);
    }

    /* ════════════════════════ مصفوفةُ التغطية (§86/§87) ════════════════════════ */

    /**
     * **خريطةُ مجالِ القدرة ⇐ تبويبِ المركز** (§86) — أين يفهم المسؤولُ كلَّ مجالِ
     * قدرةٍ ويشخّصه. الغايةُ **صفرُ قدرةٍ غيرِ مُغطّاة**: أيُّ مجالٍ جديدٍ في الـAPI
     * الجوال بلا مدخلٍ هنا يُسقِط اختبارَ التغطية — فلا تتباعد المنصّةُ عن مركزها.
     */
    private const AREA_TAB = [
        'auth' => 'security', 'meta' => 'docs', 'health' => 'operations',
        'context' => 'api', 'schema' => 'api', 'crud' => 'api', 'actions' => 'api',
        'approvals' => 'api', 'home' => 'api', 'search' => 'api', 'prefs' => 'api',
        'notifications' => 'push', 'comments' => 'api', 'dm' => 'api', 'push' => 'push',
        'files' => 'field', 'scanner' => 'field', 'tracking' => 'field', 'sync' => 'api',
    ];

    /**
     * **مصفوفةُ التغطية** (§86/§87) — لكلِّ مجالِ قدرةٍ حيٍّ (من `capabilities`) تبويبُ
     * المركزِ الذي يُغطّيه، أو `null` (غيرُ مُغطّى). صفٌّ لكلِّ مجال، مرتّبٌ حتميّاً.
     *
     * @return array<int,array{area:string,tab:?string,mapped:bool}>
     */
    public static function coverageMatrix(): array
    {
        $rows = [];
        foreach (array_keys(self::capabilities()['areas'] ?? []) as $area) {
            $rows[] = ['area' => $area, 'tab' => self::AREA_TAB[$area] ?? null, 'mapped' => array_key_exists($area, self::AREA_TAB)];
        }
        usort($rows, fn ($a, $b) => $a['area'] <=> $b['area']);

        return $rows;
    }

    /** المجالاتُ غيرُ المُغطّاة (§87 · الغاية: صفر) */
    public static function unmappedAreas(): array
    {
        return array_values(array_map(fn ($r) => $r['area'], array_filter(self::coverageMatrix(), fn ($r) => ! $r['mapped'])));
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileInstallation;
use App\Models\MobileSession;
use App\Models\MobileStepupGrant;
use App\Models\User;
use App\Support\AccountLockout;
use App\Support\Api;
use App\Support\LoginSentry;
use App\Support\MobileSessionService;
use App\Support\SecurityRadar;
use App\Support\StepUp;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * **مصادقةُ الجوال الأصيل** — Mobile Readiness · الطور B · §109.
 *
 * السطحُ الأوّلُ الطرفيُّ (first-party) للتطبيق الأصيل: زوجُ رمزَين (وصولٌ قصيرٌ
 * + تحديثٌ متجدّدٌ لمرّة)، مفهومٌ **مستقلٌّ** عن مفتاح التكامل (`ApiToken`) الذي لا
 * يُمَسّ. يُعيد استعمالَ السكك القائمة لا يفرّعها: مزوّدُ الإطار للاعتماد، ومحرّكُ
 * قفلِ الحساب `AccountLockout`، وTOTP الواحد، وتصعيدُ `StepUp`، ورادارُ الأمن
 * والتدقيق — «لا دخولَ موازٍ أضعف» (spec §Auth).
 *
 * **الترتيبُ الأمنيّ الحرج (Critic F12/F13):** الدخولُ يتحقّق من الاعتماد **أولاً**
 * عبر مزوّد الإطار (`Auth::getProvider()`), ولا يُفصح عن حالة الحساب
 * (`ACCOUNT_RESTRICTED`) إلا للمالكِ المُثبَت — فمجهولُ البريد وخاطئُ الكلمة ردٌّ
 * واحدٌ لا يميّز (لا أوراكل تعداد). الحراسُ الخمسة نظيرُ الويب/`ApiAuth`.
 *
 * **بلا session()/finishLogin/Devices (Critic F11):** المصادقةُ عديمةُ الحالة —
 * الجلسةُ في `mobile_sessions`، والتصعيدُ في `mobile_stepup_grants`، لا في جلسة
 * الويب ولا في `user_devices`. **MFA صادقة (F10):** لا يُعلَن إلا `totp` (ما
 * يستطيع الخادمُ التحقّقَ منه) — لا `webauthn` (لم يُبنَ دورانُه للجوال بعد).
 *
 * كلُّ ردٍّ يمرّ بغلاف `Api::*` (`data`/`error` + `code` + `request_id`)، وكلُّ
 * تدقيقٍ يحمل `source=mobile` ومعرّفَ الجلسة/التنصيب — **ولا رمزَ ولا كلمةَ مرورٍ
 * ولا سرَّ MFA في تدقيقٍ أو سجلٍّ أبداً** (spec §Security · نمطُ `ApiToken`).
 */
class MobileAuthController extends Controller
{
    /** بادئةُ مفتاحِ تحدّي MFA في الكاش — challenge_id مربوطٌ بمستخدم+تنصيب، قصيرُ الأجل */
    private const MFA_PREFIX = 'mobile:mfa:';

    // ══════════════════════════ المجموعةُ العامّة (بلا mobile.session) ══════════════════════════

    /**
     * **B.1 · POST auth/login** (عامّ · throttle:10,1).
     *
     * التسلسل (Critic F12/F13): تحقّقٌ من الاعتماد أولاً عبر مزوّد الإطار →
     * فشلٌ ⇒ قفلٌ + تدقيقٌ + ردٌّ عامٌّ واحد (لا تعداد) → نجاحٌ ⇒ الحراسُ الخمسة
     * (يُفصَح للمالك المُثبَت وحدَه) → تسجيلُ/إيجادُ التنصيب → totp مفعّل ⇒
     * `MFA_REQUIRED` + تحدٍّ (لا رموزَ بعد) → وإلا سكُّ جلسة.
     */
    public function login(Request $r)
    {
        $this->tagMobile($r);

        $data = $r->validate([
            'email'             => ['required', 'email'],
            'password'          => ['required', 'string'],
            'installation_uuid' => ['required', 'string', 'min:8', 'max:36'],
            'platform'          => ['required', Rule::in(MobileInstallation::PLATFORMS)],
            'device_model'      => ['nullable', 'string', 'max:120'],
            'os_version'        => ['nullable', 'string', 'max:40'],
            'app_version'       => ['nullable', 'string', 'max:40'],
            'app_build'         => ['nullable', 'string', 'max:40'],
            'locale'            => ['nullable', 'string', 'max:20'],
            'tz'                => ['nullable', 'string', 'max:60'],
            'push_capable'      => ['nullable', 'boolean'],
        ]);

        // ── F13: تحقّقٌ عبر مزوّد الإطار (stateless — لا Auth::login ولا جلسة) ──
        $provider = Auth::getProvider();
        $creds    = ['email' => $data['email'], 'password' => $data['password']];
        $user     = $provider->retrieveByCredentials($creds);

        // ── F12: الاعتمادُ **أولاً** — مجهولُ البريد وخاطئُ الكلمة ردٌّ واحدٌ لا يميّز ──
        if (! $user || ! $provider->validateCredentials($user, $creds)) {
            if ($user) AccountLockout::bump($user);   // محرّكُ القفلِ نفسُه (F4) — لكلِّ حساب
            hub_audit('دخول فاشل', null, null, null,
                ['user_id' => $user?->id, 'name' => substr($data['email'], 0, 290)]);

            return $this->badCredentials();
        }

        // الاعتمادُ ثبت — الآن فقط تُفحص الحراسُ الخمسة، ويُفصَح للمالك المُثبَت وحدَه (F12)
        if ($restricted = $this->accountGate($user, $r)) {
            hub_audit('دخول محجوب', null, null, $user->name, ['user_id' => $user->id]);

            return $restricted;
        }

        // هويّةُ الطلب صارت معلومةً (بلا جلسة) — للتدقيق ولنسبة الشركة
        Auth::setUser($user);

        $installation = $this->registerInstallation($user, $data, $r);

        // ── MFA صادقة (F10): يُعلَن `totp` حصراً — ما يستطيع الخادمُ التحقّقَ منه ──
        if ($user->totp_enabled) {
            $ttl         = max(1, (int) setting('mobile.mfa_challenge_min', 5));
            $challengeId = (string) Str::uuid();
            Cache::put(self::MFA_PREFIX . $challengeId, [
                'user_id'         => $user->id,
                'installation_id' => $installation->id,
                'app_version'     => $data['app_version'] ?? null,
                'platform'        => $data['platform'],
            ], now()->addMinutes($ttl));

            hub_audit('تحدّي التحقق بخطوتين', null, null, $user->name,
                ['user_id' => $user->id, 'after' => ['installation' => $installation->id]]);

            return Api::error(Api::MFA_REQUIRED, 401,
                'تسجيلُ الدخول يتطلّب رمزَ التحقق بخطوتين', [
                    'challenge_id' => $challengeId,
                    'methods'      => ['totp'],   // لا webauthn (F10: دورانُه للجوال غيرُ مبنيٍّ بعد)
                    'expires_at'   => now()->addMinutes($ttl)->toIso8601String(),
                ]);
        }

        // لا MFA ⇒ سكُّ جلسةٍ مباشرةً + خطواتُ إتمام الدخول المنفصلة
        return $this->issueSession($user, $installation, [
            'app_version' => $data['app_version'] ?? null,
            'platform'    => $data['platform'],
        ], $r);
    }

    /**
     * **B.2 · POST auth/mfa/verify** (عامّ · throttle:6,1).
     *
     * يستهلك `challenge_id`، يعيد فحصَ قفلِ الحساب، ثم `Totp::verifyOnce(...,'login:'.$id)`.
     * الفشلُ ⇒ قفلٌ + تدقيقٌ + `MFA_REQUIRED` (التحدّي يبقى لإعادة المحاولة ضمن مهلته
     * وخنقِه). النجاحُ ⇒ استهلاكُ التحدّي مرّةً واحدة + سكُّ جلسة.
     */
    public function mfaVerify(Request $r)
    {
        $this->tagMobile($r);

        $data = $r->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'code'         => ['required', 'string', 'max:12'],
            'app_version'  => ['nullable', 'string', 'max:40'],
        ]);

        $key     = self::MFA_PREFIX . $data['challenge_id'];
        $payload = Cache::get($key);
        if (! is_array($payload)) {
            // تحدٍّ مجهولٌ أو منتهٍ — لا نميّز السبب (لا أوراكل)
            return Api::error(Api::MFA_REQUIRED, 401,
                'انتهى تحدّي التحقق أو أنه غير صالح — أعد تسجيل الدخول', ['methods' => ['totp']]);
        }

        $user = User::whereNull('deleted_at')->find($payload['user_id'] ?? null);
        if (! $user || ! $user->totp_enabled) {
            Cache::forget($key);

            return $this->badCredentials();
        }

        // قفلُ الحساب يسري على خطوة الرمز أيضاً (نمطُ otpVerify:113) — بلا هذا تخمينٌ غير محدود
        if ($user->locked_until && now()->lt($user->locked_until)) {
            return $this->lockedResponse($user);
        }

        if (! Totp::verifyOnce((string) $user->totp_secret_cipher, hub_str($data['code']), 'login:' . $user->id)) {
            AccountLockout::bump($user);
            Auth::setUser($user);
            hub_audit('فشل رمز التحقق', null, null, $user->name, ['user_id' => $user->id]);

            // التحدّي يبقى صالحاً لإعادة المحاولة (خنق 6,1 + قفلُ الحساب يحدّان التخمين)
            return Api::error(Api::MFA_REQUIRED, 401,
                'الرمز غير صحيح أو انتهى — جرّب الرمز الحالي في التطبيق', ['methods' => ['totp']]);
        }

        // نجاحٌ ⇒ التحدّي يُستهلك لمرّةٍ واحدة (لا يُعاد سكُّ جلسةٍ ثانيةٍ منه)
        Cache::forget($key);

        $installation = MobileInstallation::where('id', $payload['installation_id'] ?? null)->orderBy('id')->first();
        if (! $installation || $installation->user_id !== $user->id) {
            return $this->badCredentials();
        }

        Auth::setUser($user);

        // ── الحراسُ الخمسة يُعادون هنا كما في `login` (F12/ApiAuth:43-63) ──
        // الدخولُ فحصها قبل التحدّي، لكنّ الحالةَ قد تتغيّر **بين** الخطوتين
        // (إيقافٌ/انتهاءٌ/قفلٌ/حصرُ عنوانٍ/قفلُ طوارئ). فبلا إعادةِ الفحص هنا يُسَكّ
        // وصولٌ لحسابٍ صار محجوباً، ويُصفَّر عدّادُه، ويُختم «دخول ناجح» زوراً (وإن
        // ردّته `MobileSessionAuth` لاحقاً). يُفصَح للمالك المُثبَت (كلمةٌ + رمز) وحدَه.
        if ($restricted = $this->accountGate($user, $r)) {
            hub_audit('دخول محجوب', null, null, $user->name, ['user_id' => $user->id]);

            return $restricted;
        }

        $installation->forceFill(['last_seen_at' => now()])->save();

        return $this->issueSession($user, $installation, [
            'app_version' => $payload['app_version'] ?? ($data['app_version'] ?? null),
            'platform'    => $payload['platform'] ?? null,
        ], $r);
    }

    /**
     * **B.4 · POST auth/refresh** (عامّ · throttle:20,1 · معالجٌ خاصٌّ لا mobile.session · F5).
     *
     * يصادِق بـ**رمز التحديث** (من الجسم أو Bearer) لا برمز الوصول. التدويرُ لمرّةٍ
     * واحدة عبر `MobileSessionService::rotate`. إعادةُ استعمالِ رمزٍ مُدوَّرٍ ⇒
     * الخدمةُ أبطلت العائلةَ، وهنا الرصدُ (`SecurityRadar`) والتنبيهُ (`hub_notify`)
     * والتدقيق، ثم `REFRESH_TOKEN_INVALID`. رمزٌ قديمٌ بعد تدويرٍ ناجحٍ = إعادةٌ.
     */
    public function refresh(Request $r)
    {
        $this->tagMobile($r);

        // الرمزُ من الجسم أو من ترويسة Bearer (F5)
        $plain = trim((string) ($r->input('refresh_token') ?: $r->bearerToken() ?: ''));
        if ($plain === '') {
            return Api::error(Api::REFRESH_TOKEN_INVALID, 401, 'أرسل رمزَ التحديث (refresh_token)');
        }

        $res = MobileSessionService::rotate(
            $plain, $r->ip(), $r->input('app_version'), $r->input('platform')
        );

        if (($res['result'] ?? null) === 'reuse') {
            // العائلةُ أُبطلت في الخدمة — هنا الرصدُ والتنبيه والتدقيق (B.4)
            $fam = $res['family_id'] ?? null;
            $uid = $fam ? MobileSession::where('family_id', $fam)->orderBy('id')->value('user_id') : null;

            SecurityRadar::record($r, 'إعادةُ استخدامِ رمزِ تحديث',
                'رمزُ تحديثٍ مُدوَّرٌ أُعيد استعمالُه — أُبطلت العائلةُ كاملةً' . ($fam ? " (family={$fam})" : ''));
            if ($uid) {
                hub_notify($uid, 'sec',
                    'رُصدت محاولةُ إعادةِ استخدامِ رمزِ تحديثٍ على حسابك، فأُبطلت جميعُ جلسات تلك السلسلة احتياطاً. '
                    . 'إن لم تكن أنت من حاول، غيّر كلمةَ المرور فوراً.');
                hub_audit('إعادةُ استخدامِ رمزِ تحديث', null, null, null,
                    ['user_id' => $uid, 'after' => ['family_id' => $fam]]);
            }

            return Api::error(Api::REFRESH_TOKEN_INVALID, 401,
                'رمزُ التحديث أُعيد استعمالُه — أُبطلت الجلسةُ لأمانك، سجّل الدخول من جديد');
        }

        if (($res['result'] ?? null) !== 'rotated') {
            return Api::error(Api::REFRESH_TOKEN_INVALID, 401,
                'رمزُ التحديث غير صالحٍ أو منتهٍ — سجّل الدخول من جديد');
        }

        $session = $res['session'];

        // ── لا تجديدَ اعتمادٍ لحسابٍ صار محجوباً/محذوفاً بين جلستَي التحديث
        //    («لا دخولَ موازٍ أضعف»): الجلسةُ الجديدةُ (والقديمةُ دُوِّرت وأُبطِلت)
        //    تُبطَل فوراً فلا يبقى وصولٌ ولا يُمدَّد رمزُ تحديثٍ لحسابٍ لا يُسمح له.
        //    نظيرُ ما تفرضه `MobileSessionAuth` على رمز الوصول — لكن هنا على المصدر. ──
        $user = User::whereNull('deleted_at')->find($session->user_id);
        if (! $user) {
            MobileSessionService::revokeSession($session, 'حسابٌ محذوفٌ عند التحديث');

            return Api::error(Api::SESSION_REVOKED, 401, 'الحساب لم يعد موجوداً — سجّل الدخول من جديد');
        }
        if ($restricted = $this->accountGate($user, $r)) {
            MobileSessionService::revokeSession($session, 'حسابٌ محجوبٌ عند التحديث');

            return $restricted;
        }

        return $this->ok($this->sessionPayload($session, $res['access'], $res['refresh']));
    }

    /**
     * **C.3 · GET app-config** (عامّ · ما قبل الدخول · throttle:60,1) — صادقٌ بلا أسرار.
     *
     * يجمع من `setting()` فوق افتراضاتِ `config('hub.mobile')`: الوقتُ والمنطقةُ،
     * إصدارُ عقد الجوال، حالةُ الصيانة/القفل وتوفّرُ الدخول، **بوّابةُ الإصدار**
     * (حدٌّ أدنى/أحدثُ لكلِّ منصّة + الإجبار)، وروابطُ الدعم/المتجر. القيمةُ
     * الخارجيّةُ الغائبة تُعاد `null` صادقةً (NOT_CONFIGURED) لا مُختلَقة، والبوّابةُ
     * الفارغةُ **لا تحجب نسخَ التطوير**. يبقى عامّاً وصولاً دائماً كي يقرأ التطبيقُ
     * منه رابطَ التحديث حتى حين يكون التحديثُ إلزاميّاً (لا حجبَ لهذه النقطة نفسِها).
     */
    public function appConfig(Request $r)
    {
        $this->tagMobile($r);
        $maintenance = (bool) setting('maintenance.on', false);
        $lockdown    = (bool) setting('security.lockdown', false);
        $gate        = $this->mobileVersionGate();
        $updateReq   = $this->clientUpdateRequired($r, $gate);

        return $this->ok([
            'mobile_api_version'  => (string) config('hub.mobile.api_version', Api::VERSION),   // '1'
            'server_time'         => now()->toIso8601String(),
            'timezone'            => (string) config('app.timezone', 'UTC'),
            'maintenance'         => $maintenance,
            // رسالةُ الصيانةُ تُعرَض فقط حين تكون الصيانةُ قائمةً (وإلا null — لا نصَّ بائت)
            'maintenance_message' => $maintenance ? $this->normStr(setting('maintenance.msg', '')) : null,
            'lockdown'            => $lockdown,
            'login_available'     => ! $maintenance && ! $lockdown,
            'version_gate'        => $gate,             // ios/android {min,latest} + force_update — فارغٌ = لا حجب
            'update_required'     => $updateReq,        // مُحسَبٌ لإصدار هذا العميل (من ترويسات المنصّة/الإصدار)
            'support_url'         => $this->normStr(setting('mobile.support_url', '')),
            'store_urls'          => [
                'ios'     => $this->normStr(setting('mobile.store_url_ios', data_get(config('hub.mobile.store_urls'), 'ios', ''))),
                'android' => $this->normStr(setting('mobile.store_url_android', data_get(config('hub.mobile.store_urls'), 'android', ''))),
            ],
        ]);
    }

    /**
     * **C.3 · GET health** (عامّ · throttle:60,1) — حالةٌ صادقة بلا تليمتري بنيةٍ ولا أسرار.
     * الحالةُ بأسبقيّة: قفلٌ ← صيانةٌ ← تحديثٌ مطلوبٌ (لهذا العميل) ← سليم.
     */
    public function health(Request $r)
    {
        $this->tagMobile($r);
        $maintenance = (bool) setting('maintenance.on', false);
        $lockdown    = (bool) setting('security.lockdown', false);
        $gate        = $this->mobileVersionGate();
        $updateReq   = $this->clientUpdateRequired($r, $gate);

        return $this->ok([
            'status'          => $lockdown ? 'lockdown'
                : ($maintenance ? 'maintenance' : ($updateReq ? 'update_required' : 'ok')),
            'maintenance'     => $maintenance,
            'lockdown'        => $lockdown,
            'update_required' => $updateReq,
            'server_time'     => now()->toIso8601String(),
        ]);
    }

    // ══════════════════════════ المجموعةُ المُصادَقة (خلف mobile.session) ══════════════════════════

    /** **B.5 · POST auth/logout** — إبطالُ الجلسة الحالية + تعطيلُ رموزِ الدفع لتنصيبها (إن وُجِد جدولُها). */
    public function logout(Request $r)
    {
        $this->tagMobile($r);
        $session = $r->attributes->get('mobile_session');
        if ($session instanceof MobileSession) {
            MobileSessionService::revokeSession($session, 'خروجٌ من التطبيق');
            $this->revokePushTokensForInstallation($session->installation_id);
            hub_audit('خروج', null, null, auth()->user()?->name, ['after' => ['mobile_session' => $session->id]]);
        }

        return $this->ok(['revoked' => true]);
    }

    /** **B.5 · POST auth/logout-all** — إبطالُ كلِّ جلسات المستخدم الحيّة + تعطيلُ رموزِ دفعه. */
    public function logoutAll(Request $r)
    {
        $this->tagMobile($r);
        $user  = auth()->user();
        $count = MobileSession::where('user_id', $user->id)->whereNull('revoked_at')->count();

        MobileSessionService::revokeAllForUser($user, 'خروجٌ شاملٌ من كل الأجهزة');
        $this->revokePushTokensForUser($user->id);
        hub_audit('خروجٌ شامل', null, null, $user->name, ['after' => ['revoked' => $count]]);

        return $this->ok(['revoked' => $count]);
    }

    /**
     * **B.6 · GET auth/sessions** — جلساتُ المُنادي وحدَه (لا جلسةَ غيرِه أبداً).
     * الترتيب: آخرُ استخدامٍ تنازليّاً ثم `id` تنازليّاً (حتميّةٌ · C13).
     */
    public function sessions(Request $r)
    {
        $this->tagMobile($r);
        $user      = auth()->user();
        $current   = $r->attributes->get('mobile_session');
        $currentId = $current instanceof MobileSession ? $current->id : null;

        $rows = MobileSession::where('user_id', $user->id)
            ->with('installation')
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get()
            ->map(fn (MobileSession $s) => [
                'id'           => $s->id,
                'platform'     => $s->platform ?: ($s->installation->platform ?? null),
                'app_version'  => $s->app_version,
                'device_model' => $s->installation->device_model ?? null,
                'os_version'   => $s->installation->os_version ?? null,
                'last_used_at' => optional($s->last_used_at)->toIso8601String(),
                'last_ip'      => $s->last_ip,
                'created_at'   => optional($s->created_at)->toIso8601String(),
                'revoked_at'   => optional($s->revoked_at)->toIso8601String(),
                'active'       => $s->isActive(),
                'current'      => $s->id === $currentId,
            ])->values();

        return $this->ok(['sessions' => $rows]);
    }

    /**
     * **B.6 · DELETE auth/sessions/{id}** — إبطالُ جلسةٍ يملكها المُنادي (٤٠٤ إن لم تكن له · لا IDOR).
     * الجلسةُ المُبطَلة ترفضها `MobileSessionAuth` في طلبها التالي بـ`SESSION_REVOKED`.
     */
    public function destroySession(Request $r, string $id)
    {
        $this->tagMobile($r);
        $user    = auth()->user();
        $session = MobileSession::where('id', $id)->where('user_id', $user->id)->first();
        if (! $session) {
            // ٤٠٤ لا ٤٠٣: لا نكشف وجودَ جلسةٍ ليست له (لا IDOR ولا تعداد)
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'الجلسة غير موجودة أو ليست لك');
        }

        MobileSessionService::revokeSession($session, 'إلغاءٌ من «جلساتي»');
        hub_audit('إبطالُ جلسة', null, null, $user->name, ['after' => ['mobile_session' => $session->id]]);

        return $this->ok(['revoked' => true, 'id' => $session->id]);
    }

    /**
     * **B.7 · POST auth/step-up** — تصعيدٌ (password/TOTP) ⇒ مِنحةٌ مربوطةٌ بـ(الجلسة+الغرض+الانتهاء).
     *
     * يُعاد استعمالُ **فحصِ الاعتماد وحدَه** (`StepUp::checkCredential` — بلا `session()` · F11)،
     * ثم تُثبَت المِنحةُ في `mobile_stepup_grants`. الفشلُ ⇒ `STEP_UP_REQUIRED` (٤٢٨).
     * تتحقّق الأطوارُ اللاحقة من المِنحة عبر `MobileSessionService::mobileStepUpFresh`.
     */
    public function stepUp(Request $r)
    {
        $this->tagMobile($r);
        $user    = auth()->user();
        $session = $r->attributes->get('mobile_session');

        $data = $r->validate([
            'purpose'    => ['required', 'string', 'max:80'],
            'credential' => ['required', 'string', 'max:200'],
        ]);

        if (! $session instanceof MobileSession) {
            return Api::error(Api::UNAUTHENTICATED, 401, 'جلسةٌ غير صالحة');
        }

        $method = StepUp::method($user);   // totp لمن فعّله وإلا password

        // ── F11: فحصُ الاعتماد المجرّدُ من الحالة (لا session()) — السكّةُ نفسُها لا فرعٌ ثانٍ ──
        if (! StepUp::checkCredential($user, hub_str($data['credential']))) {
            hub_audit('فشلُ تصعيد المصادقة', null, null, $user->name,
                ['user_id' => $user->id, 'after' => ['purpose' => $data['purpose'], 'method' => $method]]);

            return Api::error(Api::STEP_UP_REQUIRED, 428,
                'تعذّر تأكيدُ الهوية — ' . ($method === 'totp' ? 'أدخل رمزَ التحقق الحالي' : 'أدخل كلمةَ المرور'),
                ['purpose' => $data['purpose'], 'method' => $method]);
        }

        $minutes = max(1, (int) setting('security.stepup_minutes', 10));
        $now     = now();
        $grant   = MobileStepupGrant::create([
            'mobile_session_id' => $session->id,
            'user_id'           => $user->id,
            'purpose'           => hub_fit($data['purpose'], 80),
            'method'            => $method,
            'granted_at'        => $now,
            'expires_at'        => $now->copy()->addMinutes($minutes),
        ]);

        hub_audit('تصعيدُ المصادقة', null, null, $user->name,
            ['user_id' => $user->id, 'after' => ['purpose' => $grant->purpose, 'method' => $method, 'mobile_session' => $session->id]]);

        return $this->ok([
            'granted'        => true,
            'purpose'        => $grant->purpose,
            'method'         => $grant->method,
            'granted_at'     => optional($grant->granted_at)->toIso8601String(),
            'expires_at'     => optional($grant->expires_at)->toIso8601String(),
            'stepup_minutes' => $minutes,
        ]);
    }

    // ══════════════════════════ مساعِداتٌ داخلية ══════════════════════════

    /** كلُّ تدقيقٍ من هذا السطح يحمل `source=mobile` — وسمٌ لا تخويل (يقرؤه hub_audit عبر Api::requestSource) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }

    /** نصٌّ مُقلَّم أو null للفارغ — القيمةُ الغائبة null صادقةٌ لا نصٌّ فارغٌ مُختلَق (C.3) */
    private function normStr($v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /**
     * **بوّابةُ إصدارِ التطبيق** (C.3 · SF-5): `setting('mobile.*')` فوق افتراضاتِ
     * `config('hub.mobile.version_gate')`. الفارغُ ⇒ `null` (لا حدَّ = **لا حجبَ**
     * لنسخ التطوير). `force_update` رايةُ الإلزام حين يكون العميلُ دون الحدِّ الأدنى.
     */
    private function mobileVersionGate(): array
    {
        $cfg = (array) config('hub.mobile.version_gate', []);

        return [
            'ios' => [
                'min'    => $this->normStr(setting('mobile.min_version_ios',    data_get($cfg, 'ios.min', ''))),
                'latest' => $this->normStr(setting('mobile.latest_version_ios', data_get($cfg, 'ios.latest', ''))),
            ],
            'android' => [
                'min'    => $this->normStr(setting('mobile.min_version_android',    data_get($cfg, 'android.min', ''))),
                'latest' => $this->normStr(setting('mobile.latest_version_android', data_get($cfg, 'android.latest', ''))),
            ],
            'force_update' => (bool) setting('mobile.force_update', (bool) data_get($cfg, 'force_update', false)),
        ];
    }

    /**
     * هل يلزم إصدارَ **هذا العميل** تحديثٌ؟ — منصّةُ العميل وإصدارُه من ترويسات
     * التليمتري (`X-Lynomia-App-Platform`/`-Version`، أو معلمتَي استعلامٍ بديلتين).
     * منصّةٌ مجهولةٌ أو حدٌّ فارغٌ أو إصدارُ عميلٍ مجهولٌ ⇒ **لا حجب** (نسخُ التطوير
     * تمرّ). المقارنةُ `version_compare` (semver). لا يُخوّل شيئاً — إشارةُ عرضٍ فقط.
     */
    private function clientUpdateRequired(Request $r, array $gate): bool
    {
        $platform = strtolower(trim((string) ($r->header('X-Lynomia-App-Platform') ?: $r->query('platform', ''))));
        if (! in_array($platform, ['ios', 'android'], true)) return false;

        $min = $gate[$platform]['min'] ?? null;
        if ($min === null) return false;   // بوّابةٌ فارغةٌ لهذه المنصّة ⇒ لا حجب

        $appVer = $this->normStr($r->header('X-Lynomia-App-Version') ?: $r->query('app_version', ''));
        if ($appVer === null) return false;   // إصدارُ العميل مجهولٌ ⇒ لا نحجب على شكّ

        return version_compare($appVer, $min, '<');
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` (نمطُ ردود `/api`) */
    private function ok(array $data, int $status = 200)
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], $status);
    }

    /** الردُّ العامّ الموحَّد على فشل الاعتماد — واحدٌ لمجهولِ البريد ولخاطئِ الكلمة (F12) */
    private function badCredentials()
    {
        return Api::error(Api::UNAUTHENTICATED, 401, 'بيانات الدخول غير صحيحة');
    }

    /** ردُّ «الحساب مقفول مؤقتاً» بمهلته المتبقّية (نمطُ الويب) */
    private function lockedResponse(User $user)
    {
        $m = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));

        return Api::error(Api::ACCOUNT_RESTRICTED, 403,
            "الحساب مقفل مؤقتاً بعد محاولات فاشلة — أعد المحاولة بعد {$m} دقيقة",
            ['reason' => 'account_locked', 'retry_after_min' => $m]);
    }

    /**
     * الحراسُ الخمسة (نظيرُ `AuthController::login` gates و`ApiAuth:43-63`): يُنادَون
     * **بعد** إثبات الاعتماد وحدَه (F12). يُعيدون ردَّ `ACCOUNT_RESTRICTED`/`LOCKDOWN`
     * حين يُحجَب، وإلا `null`.
     */
    private function accountGate(User $user, Request $r)
    {
        if ($user->status === 'موقوف') {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403, 'الحساب موقوف — راجع مالك النظام',
                ['reason' => 'account_suspended']);
        }
        if ($user->expires_at && now()->toDateString() > substr((string) $user->expires_at, 0, 10)) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403, 'انتهت صلاحية الحساب — راجع مالك النظام',
                ['reason' => 'account_expired']);
        }
        if ($user->locked_until && now()->lt($user->locked_until)) {
            return $this->lockedResponse($user);
        }
        if ($user->allowed_ips && ! ip_allowed((string) $r->ip(), (string) $user->allowed_ips)) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403, 'الدخول غير مسموح من عنوان الشبكة الحالي',
                ['reason' => 'account_ip_allowlist']);
        }
        if (setting('security.lockdown') && ! hub_is_owner($user)) {
            return Api::error(Api::LOCKDOWN, 503, 'النظام في قفل طوارئ مؤقت — الدخول للمالكين فقط');
        }

        return null;
    }

    /**
     * تسجيلُ/إيجادُ تنصيبٍ بمُعرّفِ التطبيق (`installation_uuid`). المُعرّفُ فريدٌ
     * عالميّاً؛ فإن كان لمستخدمٍ آخر فهذا **تسليمُ جهاز**: يُعادُ ربطُه بالمستخدم
     * المُصادَقِ سلفاً، وتُبطَل جلساتُ المالكِ السابقِ الحيّةُ على هذا التنصيب كي لا
     * يبقى وصولُه حيّاً على جهازٍ صار لغيره (نظيرُ تسليمِ رمزِ الدفع · Critic F7).
     */
    private function registerInstallation(User $user, array $data, Request $r): MobileInstallation
    {
        $meta = [
            'platform'     => $data['platform'],
            'device_model' => hub_fit($data['device_model'] ?? null, 120),
            'os_version'   => hub_fit($data['os_version'] ?? null, 40),
            'app_version'  => hub_fit($data['app_version'] ?? null, 40),
            'app_build'    => hub_fit($data['app_build'] ?? null, 40),
            'locale'       => hub_fit($data['locale'] ?? null, 20),
            'tz'           => hub_fit($data['tz'] ?? null, 60),
            'push_capable' => (bool) ($data['push_capable'] ?? false),
        ];

        $installation = MobileInstallation::where('installation_uuid', $data['installation_uuid'])
            ->orderBy('id')->first();

        if ($installation) {
            $handoff = $installation->user_id !== $user->id;
            $installation->forceFill($meta + [
                'user_id'        => $user->id,   // إعادةُ ربطٍ عند التسليم — والمستخدمُ مُصادَقٌ سلفاً
                'last_seen_at'   => now(),
                'revoked_at'     => null,        // إعادةُ التسجيل تُحيي تنصيباً مُبطَلاً
                'revoked_reason' => null,
            ])->save();

            if ($handoff) {
                // الجهازُ انتقل ليدٍ أخرى — تُبطَل جلساتُ المالكِ السابقِ الحيّةُ عليه
                MobileSession::where('installation_id', $installation->id)
                    ->where('user_id', '!=', $user->id)
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at'     => now(),
                        'revoked_reason' => hub_fit('انتقلَ التنصيبُ لمستخدمٍ آخر', 160),
                        'updated_at'     => now(),
                    ]);
            }

            return $installation;
        }

        return MobileInstallation::create($meta + [
            'user_id'           => $user->id,
            'installation_uuid' => $data['installation_uuid'],
            'registered_at'     => now(),
            'last_seen_at'      => now(),
        ]);
    }

    /**
     * سكُّ الجلسة + **خطواتُ إتمام الدخول المنفصلة** (F11): تصفيرُ العدّادات،
     * `LoginSentry::inspect`, وأثرُ الدخول الناجح (`source=mobile`) — دونَ
     * `finishLogin`/`Devices::bindOnLogin`/`session()`. الرمزان يُعادان **مرّةً**
     * هنا ولا يُخزَّنان صريحَين ولا يُدقَّقان.
     */
    private function issueSession(User $user, MobileInstallation $installation, array $data, Request $r)
    {
        [$session, $access, $refresh] = MobileSessionService::mint(
            $user, $installation, $r->ip(), $data['app_version'] ?? null, $data['platform'] ?? null
        );

        // (١) تصفيرُ العدّادات وختمُ آخرِ دخول — saveQuietly كي لا يوقظ تدقيقَ الموديل
        $user->forceFill([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => now(),
            'last_login_ip'   => $r->ip(),
        ])->saveQuietly();

        // (٢) حارسُ الدخول: يتعلّم العناوينَ ويرصد الغريب — التنصيبُ الجديدُ إشارةُ «جهازٌ جديد»
        LoginSentry::inspect($user, (string) $r->ip(), (bool) $installation->wasRecentlyCreated);

        // (٣) أثرُ الدخول الناجح في التدقيق — الجلسةُ والتنصيبُ فقط، **لا أيّ رمز**
        hub_audit('دخول ناجح', null, null, $user->name, [
            'user_id' => $user->id,
            'after'   => [
                'mobile_session' => $session->id,
                'installation'   => $installation->id,
                'via'            => $user->totp_enabled ? '2FA' : 'كلمة مرور',
            ],
        ]);

        return $this->ok($this->sessionPayload($session, $access, $refresh) + [
            'user' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'role'     => $user->role?->name,
                'is_owner' => hub_is_owner($user),
            ],
        ]);
    }

    /** حمولةُ الجلسة المُعادة للعميل (النصُّ الصريحُ يُعاد مرّةً — لا يُخزَّن ولا يُسجَّل) */
    private function sessionPayload(MobileSession $session, string $access, string $refresh): array
    {
        $accessExp = $session->access_expires_at;

        return [
            'access_token'       => $access,
            'refresh_token'      => $refresh,
            'token_type'         => 'Bearer',
            'session_id'         => $session->id,
            'installation_id'    => $session->installation_id,
            'access_expires_at'  => optional($accessExp)->toIso8601String(),
            'refresh_expires_at' => optional($session->refresh_expires_at)->toIso8601String(),
            'access_expires_in'  => $accessExp ? max(0, $accessExp->timestamp - now()->timestamp) : null,
        ];
    }

    /** تعطيلُ رموزِ الدفع لتنصيبٍ — محروسٌ: الجدولُ يهبط في الطور E (لا يُنشَأ هنا) */
    private function revokePushTokensForInstallation(string $installationId): void
    {
        if (! Schema::hasTable('push_tokens')) return;
        DB::table('push_tokens')->where('installation_id', $installationId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /** تعطيلُ كلِّ رموزِ دفعِ المستخدم — محروسٌ: الجدولُ يهبط في الطور E */
    private function revokePushTokensForUser(string $userId): void
    {
        if (! Schema::hasTable('push_tokens')) return;
        DB::table('push_tokens')->where('user_id', $userId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}

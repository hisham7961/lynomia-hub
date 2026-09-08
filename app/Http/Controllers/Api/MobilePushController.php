<?php

namespace App\Http\Controllers\Api;

use App\Models\MobileSession;
use App\Models\PushToken;
use App\Support\Api;
use App\Support\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * **تسجيلُ دفعِ الجوال + إدارتُه** — Mobile Readiness · الطور E · E.5/E.7.
 * مبنيٌّ على `App\Support\PushService` — لا محرّكٌ ثانٍ:
 *
 *  • **التسجيل (E.5 · `POST push/register`):** `PushService::register(...)` — إزالةُ
 *    التكرار على `(provider, token)` **مع إعادةِ التوجيه/الإبطالِ عند تصادمِ مالكَين**
 *    (Critic F7): يُبطِل ربطَ المالكِ السابقِ فيتوقّف عن تلقّي إشعاراتِ الحاليّ (تسريبُ
 *    دفعٍ عابرٌ للمستخدمين). **التنصيبُ من الجلسة لا من العميل** (`$session->installation_id`)
 *    — لا يُسجَّل رمزٌ لتنصيبِ غيري ولا لمستخدمٍ سواي (هويّةٌ خاصّةٌ صارمة).
 *  • **إلغاءُ التسجيل (E.5 · `POST push/unregister`):** `PushService::revoke(...)` —
 *    **مقصورٌ على رموزِ المُنادي** (لا يُبطِل أحدٌ رمزَ غيره — لا IDOR). والخروجُ/الخروجُ
 *    الشامل/إلغاءُ الجلسة يُبطلان رموزَ الدفعِ سلفاً (`MobileAuthController` منذ الطور B،
 *    فعّلها هبوطُ جدولِ `push_tokens` في هذا الطور).
 *  • **الإدارة (E.7 · `GET push/admin/status` · `POST push/admin/test`):** للمالكِ
 *    وحدَه (`hub_is_owner`). حالةٌ **صادقةٌ بلا سرّ** (السائقُ/مُهيّأٌ/حضورُ المشروعِ
 *    والرمز — **لا قيمةَ مفتاحٍ خاصّ أبداً**)، واختبارٌ آمنٌ يقول `NOT_CONFIGURED` حين
 *    لا اعتمادات (`NullPushProvider` — لا نجاحٌ مُزيَّف)، ويُجرَّب على رموزِ المالكِ
 *    نفسِه لا رموزِ سواه.
 *
 * **صدقُ NOT_CONFIGURED:** التسجيلُ يقبل الرمزَ دوماً (قد تُضبَط اعتماداتُ FCM لاحقاً)؛
 * والتسليمُ/الاختبارُ وحدَهما يقولان الحقيقةَ عن حالة المزوّد.
 *
 * يرث `V1Controller` لِيَرِثَ آلةَ الـIdempotency على **مالكِ الجوال** (`Idempotency::owner`
 * ⇒ `mobile_session->id` · F1) — يستعملها التسجيل. كلُّ ردٍّ بغلاف `Api::*`؛ الهويّةُ من
 * الجلسة وحدَها (`auth()->user()` الذي أرسته `MobileSessionAuth`) — لا يُوثَق بأيِّ
 * هويّةٍ يرسلها العميل. لا يُسجَّل نصُّ الرمزِ ولا اعتمادُ المزوّدِ قط (spec §Security).
 */
class MobilePushController extends V1Controller
{
    // ═══════════════════ E.5 · التسجيلُ والإبطال (هويّةٌ خاصّةٌ · dedupe F7) ═══════════════════

    /**
     * `POST push/register` — تسجيلُ/تأكيدُ رمزِ دفعٍ (dedupe عابرُ المستخدمين · F7) · E.5.
     *
     * **التنصيبُ من الجلسة لا العميل:** `$session->installation_id` (أرسته
     * `MobileSessionAuth`) — العميلُ لا يختار تنصيباً ولا مستخدماً. الرمزُ يُنسَب إلى
     * (المستخدم الحاليّ + تنصيبِ جلسته). `PushService::register` يزيل التكرار على
     * `(provider, token)`: إن كان الرمزُ لمالكٍ آخر أبطلَ ربطَه وأعاد توجيهَه للحاليّ
     * (فيتوقّف الأوّلُ عن تلقّي إشعاراتِ الثاني · F7). التسجيلُ **جانبٌ قابلٌ لإعادة
     * المحاولة** ⇒ `Idempotency-Key` (مالكُ الجوال · F1) — والعمليّةُ idempotent بذاتها
     * (upsert) فالمفتاحُ حزامُ أمانٍ إضافيّ لا شرط.
     */
    public function register(Request $r): Response
    {
        $this->tagMobile($r);
        $u       = auth()->user();
        $session = $r->attributes->get('mobile_session');

        // مزوّدٌ فارغٌ '' ⇒ null (رمزٌ بلا مزوّدٍ مُعلَن) قبل التحقّق من قائمة السماح
        if (is_string($r->input('provider')) && trim((string) $r->input('provider')) === '') {
            $r->merge(['provider' => null]);
        }

        $data = $r->validate([
            'platform' => ['required', 'string', Rule::in(PushToken::PLATFORMS)],
            'provider' => ['nullable', 'string', Rule::in(PushToken::PROVIDERS)],
            'token'    => ['required', 'string', 'max:512'],
        ], [], [
            'platform' => 'المنصّة',
            'provider' => 'مزوّد الدفع',
            'token'    => 'رمز الدفع',
        ]);

        // التنصيبُ **من الجلسة حصراً** — لا من العميل (هويّةٌ خاصّةٌ صارمة · لا انتحال)
        $installationId = $session instanceof MobileSession ? (string) $session->installation_id : '';
        if ($installationId === '') {
            return Api::error(Api::SERVICE_UNAVAILABLE, 503, 'تعذّر تحديدُ تنصيبِ الجلسة — أعد الدخول');
        }

        // Idempotency (مالكُ الجوال · F1): يُحجَز قبل الكتابة، فإعادةُ المحاولة بالمفتاح
        // نفسِه تعيد الردَّ المحفوظَ ولا تكرّر أثراً (والتسجيلُ upsert idempotent أصلاً).
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            $tok = PushService::register($u, $installationId, $data['platform'], $data['provider'] ?? null, $data['token']);

            // أثرٌ أمنيٌّ (source=mobile) — **بلا نصِّ الرمز** (تنصيبٌ/منصّةٌ/مزوّدٌ فقط · spec §Security)
            hub_audit('تسجيلُ رمزِ دفع', null, null, $u->name, ['after' => [
                'installation_id' => $installationId,
                'platform'        => (string) $tok->platform,
                'provider'        => $tok->provider !== null ? (string) $tok->provider : null,
                'push_token'      => (string) $tok->id,   // معرّفُ الصفِّ لا الرمزُ نفسُه
            ]]);

            $resp = $this->ok([
                'registered' => true,
                'token'      => $this->tokenShape($tok),   // معرّفٌ وبياناتٌ — **لا الرمزُ نفسُه**
                'delivery'   => $this->deliveryHint(),     // صدقُ NOT_CONFIGURED (بلا سرّ)
            ]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /**
     * `POST push/unregister` — إبطالُ رمزِ دفعٍ لي وحدي (لا IDOR) · E.5.
     *
     * `PushService::revoke` مقصورٌ على `user_id = auth()->id()`: لا يُبطِل المُنادي رمزَ
     * غيره أبداً (يعيد ٠ حين لا رمزَ لي بهذه القيمة — لا كشفَ وجودِ رمزِ سواي). يوقف
     * التسليمَ (`revoked_at`) دون فقدِ الأثر. `provider` اختياريٌّ (فارغٌ ⇒ كلُّ المزوّدين).
     */
    public function unregister(Request $r): Response
    {
        $this->tagMobile($r);
        $u = auth()->user();

        if (is_string($r->input('provider')) && trim((string) $r->input('provider')) === '') {
            $r->merge(['provider' => null]);
        }

        $data = $r->validate([
            'provider' => ['nullable', 'string', Rule::in(PushToken::PROVIDERS)],
            'token'    => ['required', 'string', 'max:512'],
        ], [], [
            'provider' => 'مزوّد الدفع',
            'token'    => 'رمز الدفع',
        ]);

        $revoked = PushService::revoke($u, $data['provider'] ?? null, $data['token']);

        if ($revoked > 0) {
            hub_audit('إبطالُ رمزِ دفع', null, null, $u->name, ['after' => ['revoked' => (int) $revoked]]);
        }

        return $this->ok(['revoked' => (int) $revoked]);   // عددُ المُبطَل (٠ = لا رمزَ لي بهذا — لا تسريب)
    }

    // ═══════════════════ E.7 · إدارةُ الدفع (للمالكِ وحدَه · بلا سرّ) ═══════════════════

    /**
     * `GET push/admin/status` — حالةُ الدفعِ للمالكِ وحدَه · E.7.
     *
     * **صادقةٌ بلا سرّ (spec §Push «never show private key»):** السائقُ، وهل مُهيّأٌ
     * (`configured` — صدقُ NOT_CONFIGURED)، وحضورُ المشروعِ ورمزِ الوصولِ (**حضورٌ لا
     * قيمة** — لا يُعرَض مفتاحٌ خاصٌّ أبداً). للمالكِ حصراً (`hub_is_owner`).
     */
    public function adminStatus(Request $r): Response
    {
        $this->tagMobile($r);
        if (! hub_is_owner()) return Api::error(Api::FORBIDDEN, 403, 'إدارةُ الدفعِ للمالكين فقط');

        $status = PushService::status();

        return $this->ok([
            'push' => [
                'driver'           => $status['driver'],            // null|fcm — لا سرّ
                'configured'       => (bool) $status['configured'],  // صدقُ NOT_CONFIGURED
                'requested'        => $status['requested'],          // ما طُلب في الإعدادات
                'has_project_id'   => (bool) $status['has_project_id'],    // حضورٌ لا قيمة
                'has_access_token' => (bool) $status['has_access_token'],  // حضورٌ لا قيمة — لا يُعرَض المفتاح
            ],
        ]);
    }

    /**
     * `POST push/admin/test` — اختبارٌ آمنٌ للدفعِ (للمالكِ وحدَه) · E.7.
     *
     * **يقول الحقيقةَ لا يُزيّف نجاحاً (spec §Push):** بلا اعتماداتِ FCM المزوّدُ هو
     * `NullPushProvider` فتعود كلُّ محاولةٍ `not_configured` — والردُّ يعلن `not_configured`
     * صراحةً. يُجرَّب على **رموزِ المالكِ نفسِه** لا رموزِ سواه (هويّةٌ خاصّة)، بحمولةٍ
     * تجريبيّةٍ عامّةٍ آمنة (**لا نصَّ حسّاس**). لا يُنشئ إشعاراً داخليّاً ولا يلوّث سجلَّ
     * التسليم — سبرٌ مباشرٌ للمزوّدِ يعيد نتيجةَ كلِّ رمزٍ فوراً. لا يُعرَض مفتاحٌ ولا رمزُ جهاز.
     */
    public function adminTest(Request $r): Response
    {
        $this->tagMobile($r);
        if (! hub_is_owner()) return Api::error(Api::FORBIDDEN, 403, 'اختبارُ الدفعِ للمالكين فقط');

        $u        = auth()->user();
        $status   = PushService::status();
        $provider = PushService::provider();

        // رموزُ المالكِ الحيّة وحدَها — لا رمزَ سواه (سبرٌ على جهازِ المالكِ نفسِه)
        $tokens = PushToken::active()->where('user_id', $u->id)
            ->orderBy('created_at')->orderBy('id')->get();

        // حمولةٌ تجريبيّةٌ آمنةٌ بالبناء — عنوانٌ عامٌّ بلا نصٍّ حسّاس (نظيرُ حمولةِ الإنتاج)
        $payload = [
            'title'    => PushService::KIND_TITLES['test'] ?? PushService::KIND_TITLE_DEFAULT,
            'body'     => PushService::GENERIC_BODY,
            'category' => 'test',
            'data'     => ['category' => 'test'],
        ];

        $results = [];
        foreach ($tokens as $tok) {
            try {
                $res = $provider->send((string) $tok->token, (string) $tok->platform, $payload);
                $results[] = [
                    'installation_id' => (string) $tok->installation_id,
                    'platform'        => (string) $tok->platform,
                    'status'          => $res->status,           // not_configured بلا اعتماد — الحقيقة
                    'error_category'  => $res->errorCategory,
                ];
            } catch (\Throwable $e) {
                // استثناءُ المزوّدِ ملتقَطٌ — لا يُسرَّب نصُّه، الاختبارُ يمضي
                report($e);
                $results[] = [
                    'installation_id' => (string) $tok->installation_id,
                    'platform'        => (string) $tok->platform,
                    'status'          => 'failed',
                    'error_category'  => \App\Models\PushDelivery::ERR_EXCEPTION,
                ];
            }
        }

        // الحكمُ الإجماليُّ **صادق**: بلا اعتمادٍ ⇒ not_configured صراحةً (لا نجاحٌ مُزيَّف)
        $overall = ! $status['configured']
            ? 'not_configured'
            : ($tokens->isEmpty() ? 'no_tokens' : 'attempted');

        return $this->ok([
            'overall'       => $overall,
            'configured'    => (bool) $status['configured'],   // صدقُ NOT_CONFIGURED
            'driver'        => $status['driver'],
            'tokens_tested' => $tokens->count(),
            'results'       => $results,   // حالةُ كلِّ رمزٍ (not_configured بلا اعتماد)
        ]);
    }

    // ═══════════════════════════ مساعِداتٌ داخلية ═══════════════════════════

    /**
     * بطاقةُ رمزِ دفعٍ آمنة — معرّفُ الصفِّ وبياناتُه (منصّة/مزوّد/تنصيب/تأكيد)
     * **لا الرمزُ نفسُه** (لا يُعاد نصُّه — spec §Security «never audit/log tokens»).
     */
    private function tokenShape(PushToken $t): array
    {
        return [
            'id'              => (string) $t->id,
            'platform'        => (string) $t->platform,
            'provider'        => $t->provider !== null ? (string) $t->provider : null,
            'installation_id' => (string) $t->installation_id,
            'confirmed_at'    => optional($t->last_confirmed_at)->toIso8601String(),
            'revoked'         => $t->revoked_at !== null,
        ];
    }

    /**
     * تلميحُ التسليم في ردِّ التسجيل — **صادق، بلا سرّ**: هل الدفعُ مُهيّأٌ الآن؟
     * فيعرف العميلُ أن التسليمَ قد لا يقع بعد (لا اعتمادات) رغم قبولِ الرمز.
     */
    private function deliveryHint(): array
    {
        $status = PushService::status();

        return [
            'configured' => (bool) $status['configured'],   // false ⇒ سيُسجَّل not_configured عند الإشعار
            'driver'     => $status['driver'],              // null|fcm — لا سرّ
        ];
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` (X-API-Version من `MobileSessionAuth`) */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` — يقرؤه `hub_audit` عبر `Api::requestSource` (وسمٌ لا تخويل) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}

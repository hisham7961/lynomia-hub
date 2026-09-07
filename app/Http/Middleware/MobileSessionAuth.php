<?php

namespace App\Http\Middleware;

use App\Models\MobileSession;
use App\Support\Api;
use App\Support\SecurityRadar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * **بوّابةُ جلسة الجوال** — `Authorization: Bearer <access>` — Mobile Readiness ·
 * الطور B · SF-2.
 *
 * تُطابِق **ترتيبَ حراس `ApiAuth` حرفيّاً** (ApiAuth.php:14-94) كي لا تكون جلسةُ
 * الجوال بوّابةً أضعفَ من مفتاح التكامل (spec §Auth: «NO weaker parallel login»).
 * الفرقان الوحيدان: (١) تُطابِق `access_hash` في `mobile_sessions` لا `token_hash`
 * في `api_tokens`؛ (٢) الجلسةُ المُبطَلة تردّ `SESSION_REVOKED` (الكودُ المخصَّص)
 * لا `UNAUTHENTICATED` — كي يعرف العميلُ أن يعيد الدخول لا أن يعيد المحاولة.
 *
 * **الرمزُ ACCESS لا refresh:** التدويرُ (`auth/refresh`) يصادِق بـ`refresh_hash`
 * في معالجه الخاصّ خارجَ هذه البوّابة (Critic F5) — هذه البوّابةُ للوصول وحده.
 *
 * لا تثق بأيّ هويّةٍ يرسلها العميل (user_id/دور/مالك): الهويّةُ تُشتقّ من الجلسة
 * المُطابَقة وحدَها ثم `Auth::setUser`. ولا تلمس جلسةَ الويب ولا `user_devices`.
 */
class MobileSessionAuth
{
    public function handle(Request $request, Closure $next)
    {
        // ردودُ الجوال دائماً JSON (نمطُ ApiAuth:17)
        $request->headers->set('Accept', 'application/json');

        $plain = (string) $request->bearerToken();
        if ($plain === '') {
            SecurityRadar::record($request, 'وصول مرفوض', 'جلسةُ جوالٍ بلا رمز وصول');

            return Api::error(Api::UNAUTHENTICATED, 401, 'أرسل رمزَ الوصول في ترويسة Authorization: Bearer <token>');
        }

        // مطابقةُ التجزئة (نمطُ ApiAuth:25) — sha256 hex حصراً، لا نصَّ صريحاً في القاعدة
        $session = MobileSession::where('access_hash', hash('sha256', $plain))->first();
        if (! $session || ! $session->access_expires_at || now()->gt($session->access_expires_at)) {
            SecurityRadar::record($request, 'وصول مرفوض', $session ? 'رمزُ وصولِ جوالٍ منتهٍ' : 'رمزُ وصولِ جوالٍ غير صالح');

            return Api::error(Api::UNAUTHENTICATED, 401, 'رمزُ وصولٍ غير صالح أو منتهٍ');
        }

        // المُبطَلُ ميتٌ فوراً — الإبطالُ الناعمُ يُبقي الشاهد، وهذا الرفضُ هو ما يجعله إبطالاً (نمطُ ApiAuth:33)
        if ($session->revoked_at) {
            SecurityRadar::record($request, 'وصول مرفوض', 'جلسةُ جوالٍ مُبطَلة');

            return Api::error(Api::SESSION_REVOKED, 401, 'أُبطلت هذه الجلسة — سجّل الدخول من جديد');
        }

        // **حراسُ الحساب الخمسة يسريان على الجوال كما على API/الويب** (ApiAuth:43-63) —
        // البوّابةُ الخامسةُ التي تفرضها، فلا تسريبَ لحسابٍ موقوفٍ/منتهٍ/مقفولٍ/محصورٍ عبر الجوال
        $user = $session->user()->whereNull('deleted_at')->first();
        if (! $user || $user->status === 'موقوف' || ($user->locked_until && now()->lt($user->locked_until))) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403, 'الحساب موقوف أو مقفل', ['reason' => 'account_suspended_or_locked']);
        }
        if ($user->expires_at && now()->toDateString() > substr((string) $user->expires_at, 0, 10)) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403, 'انتهت صلاحية هذا الحساب', ['reason' => 'account_expired']);
        }
        if ($user->allowed_ips && ! ip_allowed((string) $request->ip(), (string) $user->allowed_ips)) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403,
                'هذا الحساب مقيد بعناوين شبكة محددة وعنوانك ليس منها', ['reason' => 'account_ip_allowlist']);
        }
        if (setting('security.lockdown') && ! $user->role?->is_owner) {
            return Api::error(Api::LOCKDOWN, 503, 'النظام في قفل طوارئ — الوصول للمالكين فقط');
        }

        Auth::setUser($user);
        $request->attributes->set('mobile_session', $session);   // للنطاق وIdempotency (مالكُ المفتاح = الجلسة) لاحقاً
        try {
            \Illuminate\Support\Facades\Log::withContext(['user_id' => $user->id, 'mobile_session' => $session->id]);
        } catch (\Throwable $e) {
        }

        // آخر استخدام + عنوان — كتابةٌ واحدةٌ بالدقيقة كحد أقصى (نمطُ ApiAuth:74)
        if (! $session->last_used_at || $session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill([
                'last_used_at' => now(),
                'last_ip'      => hub_fit((string) $request->ip(), 60),
            ])->saveQuietly();
        }

        $response = $next($request);

        // إصدارُ العقد على كل ردّ (نمطُ ApiAuth:86)
        if (method_exists($response, 'header')) $response->header('X-API-Version', Api::VERSION);

        return $response;
    }
}

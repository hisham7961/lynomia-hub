<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Api;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** مصادقة API: Authorization: Bearer <token> — مطابقة sha256، انتهاء، آخر استخدام */
class ApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        // ردود الـ API دائماً JSON — حتى بلا ترويسة Accept (أخطاء التحقق 422 لا تحويلات)
        $request->headers->set('Accept', 'application/json');

        $plain = (string) $request->bearerToken();
        if ($plain === '') {
            return Api::error(Api::UNAUTHENTICATED, 401, 'أرسل المفتاح في ترويسة Authorization: Bearer <token>');
        }

        $token = ApiToken::where('token_hash', hash('sha256', $plain))->first();
        if (! $token || ($token->expires_at && now()->gt($token->expires_at))) {
            return Api::error(Api::UNAUTHENTICATED, 401, 'مفتاح غير صالح أو منتهٍ');
        }

        if (! $token->ipAllowed($request->ip())) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403,
                'هذا المفتاح مقيد بعناوين IP محددة وعنوانك ليس منها', ['reason' => 'token_ip_allowlist']);
        }

        $user = $token->user()->whereNull('deleted_at')->first();
        if (! $user || $user->status === 'موقوف' || ($user->locked_until && now()->lt($user->locked_until))) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403, 'الحساب موقوف أو مقفل', ['reason' => 'account_suspended_or_locked']);
        }

        // **حارسا الحساب يسريان على API كما على الويب**: انتهاء الحساب وقائمة
        // العناوين المسموحة كانا يُفحصان عند تسجيل الدخول فقط — فحسابُ متعاقدٍ
        // انتهى عقده، أو حسابٌ محصورٌ بشبكة المكتب، يحتفظ بوصولٍ كاملٍ عبر
        // مفتاحه من أي مكان في العالم إلى الأبد.
        if ($user->expires_at && now()->toDateString() > substr((string) $user->expires_at, 0, 10)) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403, 'انتهت صلاحية هذا الحساب', ['reason' => 'account_expired']);
        }
        if ($user->allowed_ips && ! ip_allowed((string) $request->ip(), (string) $user->allowed_ips)) {
            return Api::error(Api::ACCOUNT_RESTRICTED, 403,
                'هذا الحساب مقيد بعناوين شبكة محددة وعنوانك ليس منها', ['reason' => 'account_ip_allowlist']);
        }

        // قفل الطوارئ يسري على API أيضاً
        if (setting('security.lockdown') && ! $user->role?->is_owner) {
            return Api::error(Api::LOCKDOWN, 503, 'النظام في قفل طوارئ — الوصول للمالكين فقط');
        }

        Auth::setUser($user);
        $request->attributes->set('api_token', $token);   // للنطاقات وIdempotency لاحقاً
        try {
            \Illuminate\Support\Facades\Log::withContext(['user_id' => $user->id, 'api_token' => $token->id]);
        } catch (\Throwable $e) {
        }

        // آخر استخدام — كتابة واحدة بالدقيقة كحد أقصى
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $t0 = microtime(true);
        $response = $next($request);

        // إصدارُ العقد على كل ردّ — به يعرف العميلُ أيَّ عقدٍ يخاطب، وبه تُعلَن الإهمالات لاحقاً
        if (method_exists($response, 'header')) $response->header('X-API-Version', Api::VERSION);

        // **تحليلاتُ الاستخدام** (v2.399): طلباتٌ وأخطاءٌ ومللي ثانية لكل مفتاحٍ في اليوم —
        // في `metric_points` القائم (وحدة api_tokens) لا في جدولٍ جديد؛ زياداتٌ ذرّية رخيصة.
        Api::countUsage($token->id, method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
            (int) round((microtime(true) - $t0) * 1000));

        return $response;
    }
}

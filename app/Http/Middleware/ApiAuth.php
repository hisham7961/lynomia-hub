<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
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
            return response()->json(['error' => 'أرسل المفتاح في ترويسة Authorization: Bearer <token>'], 401);
        }

        $token = ApiToken::where('token_hash', hash('sha256', $plain))->first();
        if (! $token || ($token->expires_at && now()->gt($token->expires_at))) {
            return response()->json(['error' => 'مفتاح غير صالح أو منتهٍ'], 401);
        }

        $user = $token->user()->whereNull('deleted_at')->first();
        if (! $user || $user->status === 'موقوف' || ($user->locked_until && now()->lt($user->locked_until))) {
            return response()->json(['error' => 'الحساب موقوف أو مقفل'], 403);
        }

        // قفل الطوارئ يسري على API أيضاً
        if (setting('security.lockdown') && ! $user->role?->is_owner) {
            return response()->json(['error' => 'النظام في قفل طوارئ — الوصول للمالكين فقط'], 503);
        }

        Auth::setUser($user);

        // آخر استخدام — كتابة واحدة بالدقيقة كحد أقصى
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Risk;
use Closure;
use Illuminate\Http\Request;

/**
 * مصادقةٌ تكيفية (المرحلة ج): إن فعّلت المنشأةُ السياسة، لا يعمل صاحبُ صلاحيةٍ
 * حسّاسة (users/secrets/exp/audit/copySec أو مالك) **دون تفعيل التحقّق بخطوتين**
 * — يُوجَّه لملفه لتفعيله. مطفأةٌ افتراضاً (`auth.2fa_required_priv`) كي لا
 * تُفاجئ تثبيتاً قائماً؛ وتُستثنى مساراتُ التفعيل والخروج كي لا يُحبَس المستخدم.
 *
 * لا حجبٌ للبيانات ولا عقاب — توجيهٌ لإكمال شرطٍ أمنيّ، والقرارُ سياسةٌ لا شيفرة.
 */
class Require2faForPrivileged
{
    /** مساراتٌ يجب أن تبقى مفتوحةً وإلا حُبس المستخدم بلا مخرج */
    protected const ALLOW = [
        'profile.edit', 'profile.2fa.start', 'profile.2fa.confirm', 'profile.2fa.disable',
        'profile.update', 'profile.password', 'logout', 'jslog',
    ];

    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u || (string) setting('auth.2fa_required_priv', '0') !== '1') return $next($request);
        if ($u->totp_enabled || ! Risk::privileged($u)) return $next($request);

        $name = (string) $request->route()?->getName();
        if (in_array($name, self::ALLOW, true)) return $next($request);
        // لا نعترض نداءات API ولا الأصول — فقط تنقّل الويب العاديّ
        if ($request->expectsJson() || $request->is('api/*')) return $next($request);

        return redirect()->route('profile.edit')->with('warn',
            '🔐 حسابُك صاحبُ صلاحياتٍ حسّاسة — فعّل التحقّقَ بخطوتين للمتابعة (سياسةُ المنشأة).');
    }
}

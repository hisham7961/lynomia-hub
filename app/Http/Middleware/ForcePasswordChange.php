<?php

namespace App\Http\Middleware;

use App\Support\Staff;
use Closure;
use Illuminate\Http\Request;

/**
 * **كلمةُ المرورِ المؤقّتة مُلزِمة** (محاكاة الجولة 2 · G16).
 *
 * شاشةُ فتحِ حسابِ الموظّف تعرض كلمةً مؤقّتةً مرّةً واحدة وتقول لمن يسلّمها:
 * «سيُطلب منه تبديلُها عند أوّل دخول». ولم يكن يُطلب: دخل الموظّفُ وعمل بها،
 * فبقيت كلمةٌ يعرفها شخصان في محادثةٍ أو ورقة، وسجلُّ الأفعالِ باسمه وحدَه.
 *
 * الحبسُ هنا **على علمٍ صريح** (`Staff::mustChangePassword`) لا على تخمين، فلا
 * يمسّ حساباً قائماً ولا عضوَ بوّابةِ عميلٍ لم يُفعَّل ولا مساراتِ المصادقة.
 * ومساراتُ التبديلِ والخروجِ مفتوحةٌ وإلا حُبس صاحبُها بلا مخرج — نظيرُ
 * `Require2faForPrivileged` حرفياً في شكلِه وفي استثناءاتِه.
 *
 * لا حجبَ للبيانات عقاباً: توجيهٌ لإتمامِ شرطٍ أمنيٍّ وُعد به المستخدمُ أصلاً.
 */
class ForcePasswordChange
{
    /** مساراتٌ يجب أن تبقى مفتوحةً وإلا حُبس المستخدم بلا مخرج */
    protected const ALLOW = [
        'profile.edit', 'profile.update', 'profile.password',
        'login', 'login.attempt', 'login.otp', 'login.otp.verify', 'logout', 'jslog', 'healthz',
    ];

    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u || ! Staff::mustChangePassword($u)) return $next($request);

        if (in_array((string) $request->route()?->getName(), self::ALLOW, true)) return $next($request);

        // سطحُ API له مصادقتُه (ApiAuth) ولا يُعترض هنا — كما في حارس التحقّق بخطوتين
        if ($request->is('api/*')) return $next($request);
        if ($request->expectsJson()) {
            return \App\Support\Api::error(\App\Support\Api::STEP_UP_REQUIRED, 428,
                'دخلتَ بكلمةِ مرورٍ مؤقّتة — بدّلها قبل أيِّ عملٍ آخر',
                ['policy' => 'must_change_password'], ['stepup' => true, 'url' => route('profile.edit')]);
        }

        return redirect()->route('profile.edit')->with('warn',
            '🔑 دخلتَ بكلمةِ مرورٍ مؤقّتة سلّمها لك غيرُك — بدّلها الآن قبل أيِّ عملٍ آخر،'
            . ' فكلُّ ما يقع بعدها يُنسب إليك وحدك.');
    }
}

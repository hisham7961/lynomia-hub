<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\StepUp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * شاشة تصعيد المصادقة (المرحلة ج): يُعيد المستخدمُ تحقّقَه قبل فعلٍ حسّاس،
 * فتُختم نافذةٌ قصيرةٌ يعمل ضمنها. الرابطُ يحمل `next` — الوجهةَ بعد النجاح.
 */
class StepUpController extends Controller
{
    public function show(Request $r)
    {
        $u = auth()->user();
        if (StepUp::fresh()) {
            return redirect(self::safeNext($r->query('next')));
        }

        return view('security.stepup', [
            'method' => StepUp::method($u),
            'next' => self::safeNext($r->query('next')),
            'minutes' => StepUp::windowMinutes(),
        ]);
    }

    public function verify(Request $r)
    {
        $u = auth()->user();
        // ٥ محاولات بالدقيقة — تصعيدٌ لا بابٌ للتخمين
        $key = 'stepup:' . $u->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('err', 'محاولات كثيرة — انتظر دقيقة ثم أعد المحاولة');
        }

        $r->validate(['answer' => ['required', 'string', 'max:200']]);

        if (! StepUp::verify($u, hub_str($r->input('answer')))) {
            RateLimiter::hit($key, 60);
            hub_audit('فشل تصعيد المصادقة', null, null, $u->name);

            return back()->with('err', StepUp::method($u) === 'totp'
                ? 'الرمز غير صحيح أو انتهى'
                : 'كلمة المرور غير صحيحة')->with('next', self::safeNext($r->input('next')));
        }

        RateLimiter::clear($key);
        hub_audit('تصعيد مصادقة ناجح', null, null, $u->name);

        return redirect(self::safeNext($r->input('next')))->with('ok', '✅ أُكّدت هويتك — أعد الفعل الآن');
    }

    /** الوجهة الآمنة: مسارٌ داخليٌّ فقط — لا إعادةَ توجيهٍ خارجية */
    protected static function safeNext($next): string
    {
        $next = (string) $next;
        if ($next === '' || ! str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return route('dashboard', absolute: false);
        }

        return $next;
    }
}

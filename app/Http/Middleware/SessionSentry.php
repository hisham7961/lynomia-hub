<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * الجلسة الحيّة: نبضةُ حضورٍ وإنهاءٌ عن بُعد.
 *
 * عمود `sessions_log.revoked` كان قائماً منذ الهجرة الأولى **لا يكتبه أحد ولا
 * يقرؤه أحد**، و`last_seen_at` يُكتب مرةً عند الدخول ولا يُحدَّث أبداً — فقائمة
 * «الجلسات» كانت سجلَّ دخولٍ لا جلساتٍ نشطة، ولم يكن في النظام أي طريقةٍ
 * لإخراج جهازٍ مسروق: يبقى داخلاً حتى يخرج بنفسه.
 *
 * النبضة مخنوقة: كتابةٌ واحدة كل دقيقة لا مع كل طلب.
 */
class SessionSentry
{
    protected const SKIP = ['login', 'login.attempt', 'login.otp', 'login.otp.verify', 'logout', 'healthz'];

    public function handle(Request $r, Closure $next)
    {
        $id = (string) session('hub.sl', '');

        // الحسابُ نفسه يُعاد فحصه مع كل طلب: المنعُ كان عند تسجيل الدخول وحده،
        // فموظفٌ أُنهيت خدمتُه وجهازُه مفتوح يظلّ يقرأ ويكتب — ويُوقّع إقرار
        // عهدةٍ يُحتجّ به. الإيقافُ والحذف يجب أن يسريا **الآن** لا عند خروجه.
        if (auth()->check() && ! $r->routeIs(...self::SKIP)) {
            $u = auth()->user();
            if ($u->deleted_at !== null || ($u->status ?? 'نشط') !== 'نشط') {
                return $this->kick($r, $u->deleted_at !== null
                    ? 'حُذف هذا الحساب — راجع من يدير المستخدمين'
                    : 'أُوقف هذا الحساب — راجع من يدير المستخدمين');
            }
            // انتهاءُ الحساب وحصرُ العناوين كانا يُفحصان في login() فقط — فيتجاوزهما
            // الجلسةُ الحيّة و«تذكّرني». يسريان الآن مع كل طلب كما على الـAPI (ApiAuth):
            // إلغاءُ تزويد متعاقدٍ وحصرُ الشبكة يسريان **الآن** لا عند خروجه.
            if ($u->expires_at && now()->toDateString() > substr((string) $u->expires_at, 0, 10)) {
                return $this->kick($r, 'انتهت صلاحية هذا الحساب — راجع من يدير المستخدمين');
            }
            if ($u->allowed_ips && ! ip_allowed((string) $r->ip(), (string) $u->allowed_ips)) {
                return $this->kick($r, 'الدخول غير مسموح من عنوان الشبكة الحالي');
            }
        }

        if ($id !== '' && auth()->check() && ! $r->routeIs(...self::SKIP)) {
            try {
                $row = DB::table('sessions_log')->where('id', $id)->first(['revoked', 'user_id']);

                // صفٌّ غائب ليس إنهاءً (قد يكون تشذيباً) — الإنهاء وسمٌ صريح
                if ($row && $row->revoked) {
                    return $this->kick($r, 'أُنهيت هذه الجلسة من مركز الأمان — سجّل الدخول من جديد');
                }

                if ($row && now()->timestamp - (int) session('hub.slp', 0) > 60) {
                    session(['hub.slp' => now()->timestamp]);
                    DB::table('sessions_log')->where('id', $id)->update(['last_seen_at' => now()]);
                }
            } catch (\Throwable $e) {
                // حارسُ جلسةٍ معطوب لا يُسقط النظام — يفشل مفتوحاً كما كان قبله
            }
        }

        return $next($r);
    }

    /** إخراجٌ نظيف: `Auth::logout` يدوّر رمز «تذكّرني» فلا تُبعث الجلسة من الكعكة */
    protected function kick(Request $r, string $why)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $why]);
    }
}

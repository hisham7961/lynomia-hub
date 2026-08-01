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

        if ($id !== '' && auth()->check() && ! $r->routeIs(...self::SKIP)) {
            try {
                $row = DB::table('sessions_log')->where('id', $id)->first(['revoked', 'user_id']);

                // صفٌّ غائب ليس إنهاءً (قد يكون تشذيباً) — الإنهاء وسمٌ صريح
                if ($row && $row->revoked) {
                    // Auth::logout يدوّر رمز «تذكّرني» أيضاً، فلا تُبعث الجلسة من الكعكة
                    Auth::logout();
                    $r->session()->invalidate();
                    $r->session()->regenerateToken();

                    return redirect()->route('login')
                        ->withErrors(['email' => 'أُنهيت هذه الجلسة من مركز الأمان — سجّل الدخول من جديد']);
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
}

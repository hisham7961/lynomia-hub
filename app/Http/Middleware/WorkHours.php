<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * نظام ساعات العمل — للموظفين (غير المالكين) فقط:
 *  - داخل الدوام (٨:٠٠ → ١٧:٠٠ افتراضاً): جلسات حرة لا تُقفل بتكرار.
 *  - خارجه (بعد strict_from أو قبل بداية الدوام): الجلسة تُجدَّد كل ١٠ دقائق
 *    (strict_minutes)، ونقل الملفات — تنزيلاً ورفعاً وتصديراً — ممنوع.
 * كل الأوقات والمدد تُخصَّص من الإعدادات (مفاتيح sec.*).
 */
class WorkHours
{
    /**
     * مسارات نقل الملفات الممنوعة خارج الدوام.
     *
     * القائمةُ كانت خمسةَ مساراتٍ تغفل **كلَّ الرفع** ووثائقَ صندوق الوارد
     * وغرفةِ البيانات ونسخةَ esign — أي أن «حظرَ نقل الملفات» كان يحرس بابَ
     * التنزيل ويترك بابَ الرفع مفتوحاً. وقائمةٌ يدويةٌ يدنو منها النسيان،
     * فأُضيف معها حارسٌ **وصفيّ**: أيُّ طلبٍ يحمل ملفاً محجوبٌ مهما كان مساره.
     */
    protected const FILE_ROUTES = ['file.show', 'att.dl', 'att.view', 'att.zip', 'att.store', 'm.export',
                                   'esign.pdf', 'dataroom.store', 'inboxdocs.store', 'm.import.run',
                                   // أوراقُ العهدة تُطبع وتُحفظ PDF — نقلُ ملفاتٍ بكل معنى
                                   'custody.label', 'custody.spec', 'custody.permit.doc'];

    public function handle(Request $r, Closure $next)
    {
        // مفتاح التشغيل الرئيسي — النظام كله يُدار من الإعدادات
        if ((string) setting('sec.hours_on', '1') !== '1') return $next($r);

        $u = auth()->user();
        if (! $u || hub_is_owner($u)) return $next($r);
        if ($r->routeIs('login', 'login.*', 'logout')) return $next($r);

        $t = now()->format('H:i');
        $strict = $t >= (string) setting('sec.strict_from', '17:00')
               || $t < (string) setting('sec.hours_start', '08:00');

        if (! $strict) {
            session(['wh.last' => now()->timestamp]);

            return $next($r);
        }

        // خارج الدوام: خمول يتجاوز المدة = خروج إجباري وإعادة تسجيل
        $mins = max(1, (int) setting('sec.strict_minutes', 10));
        $last = (int) session('wh.last', 0);
        if ($last && now()->timestamp - $last > $mins * 60) {
            auth()->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => "خارج وقت العمل تُجدَّد الجلسة كل {$mins} دقائق — سجّل دخولك من جديد"]);
        }
        session(['wh.last' => now()->timestamp]);

        // منع نقل الملفات خارج الدوام (قابل للإيقاف من الإعدادات).
        // القائمةُ لِما هو معروفٌ اليوم، و`allFiles()` لِما يُضاف غداً: طلبٌ
        // يحمل ملفاً هو نقلُ ملفٍ مهما كان اسمُ مساره.
        if ((string) setting('sec.strict_files', '1') === '1'
            && ($r->routeIs(...self::FILE_ROUTES) || $r->allFiles())) {
            abort(403, 'نقل الملفات ممنوع خارج وقت العمل — يعود متاحاً مع بداية الدوام');
        }

        return $next($r);
    }
}

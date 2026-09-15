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
                                   'custody.label', 'custody.spec', 'custody.permit.doc',
                                   /*
                                    * **وبالتعليلِ نفسِه: مستنداتُ الأعمالِ الثنائيّة** (التحقّقُ الثامن · N-4).
                                    * كان العرضُ التجاريُّ بأسعارِه يخرج PDF الساعةَ الثالثةَ فجراً
                                    * (‏`application/pdf` · `Content-Disposition` · ٨٤ كيلوبايت) بينما
                                    * يُردّ جدولُ المشتريات CSV ٤٠٣ في الدقيقةِ نفسِها.
                                    *
                                    * **وحُدَّ النطاقُ بعد فحصِ ما تُعيده الأبوابُ فعلاً، لا بعدِّها:**
                                    * بلاغُ التحقّقِ سمّى خمسةَ أبواب، و**ثلاثةٌ منها شاشات** لا ملفّات —
                                    * `quotes.doc` و`purchases.doc` و`esign.doc` تُعيد `view(...)` أي
                                    * صفحةَ طباعةٍ بـ`text/html`. وحجبُ شاشةٍ باسمِ «حظرِ نقلِ الملفات»
                                    * نزعُ قدرةٍ لا إصلاحٌ — وأشدُّها `esign.doc` إذ لا وحدةَ `esign`
                                    * في السجلّ فلا مفتاحَ استثناءٍ يقابله. فالمحروسُ هنا **الثنائيّةُ
                                    * وحدَها**.
                                    */
                                   'quotes.pdf', 'changeorders.pdf'];

    /**
     * **خريطةُ الاستثناء: بابٌ ← وحدتُه.** منعٌ بلا استثناءٍ يقابله نزعُ قدرة، فحاملُ
     * مفتاحِ «تصدير خارج الدوام» (`exportNight`) على وحدةِ البابِ يمرّ كما يمرّ على
     * `m.export`. وكان الاستثناءُ مقصوراً على مسارٍ واحدٍ يقرأ وحدتَه من مُعامِلِ
     * المسار — فلمّا اتّسع الحظرُ اتّسع معه الاستثناء، لا الحظرُ وحدَه.
     *
     * (`esign` ليست وحدةً في السجلّ، فبابُها يبقى على المنعِ كما كان `esign.pdf`
     *  من قبل — لا استثناءَ يُخترع لوحدةٍ لا وجودَ لها.)
     */
    protected const NIGHT_EXEMPT = [
        'quotes.pdf'       => 'quotes',
        'changeorders.pdf' => 'changeorders',
    ];

    public function handle(Request $r, Closure $next)
    {
        // مفتاح التشغيل الرئيسي — النظام كله يُدار من الإعدادات
        if ((string) setting('sec.hours_on', '1') !== '1') return $next($r);

        $u = auth()->user();
        if (! $u || hub_is_owner($u)) return $next($r);
        if ($r->routeIs('login', 'login.*', 'logout')) return $next($r);

        // نافذةُ «خارجِ الدوام» من تعريفِها الوحيد — لا نسخةَ ثانيةً هنا
        $strict = hub_after_hours();

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
            /*
             * استثناءُ `exportNight` (الجولة 1 · F17): إقفالُ الشهر ليلاً كان
             * مستحيلاً — المحاسبُ يُردّ 403 بلا أيّ سبيل. حاملُ المفتاح الدقيق
             * على وحدةِ التصدير يمرّ هنا، والتوسيمُ في `exportBelt` يقيّده
             * تدقيقاً («تصدير خارج الدوام»). البقيّةُ على المنع كما كانت.
             */
            $mExp = $r->routeIs('m.export') ? (string) $r->route('module') : null;
            foreach (self::NIGHT_EXEMPT as $routeName => $mod) {
                if ($r->routeIs($routeName)) { $mExp = $mod; break; }
            }
            if (! ($mExp && auth()->check() && hub_can(auth()->user(), $mExp, 'exportNight'))) {
                /*
                 * **المنعُ لا يبتلع ما كُتب** (الجولة 2 · G20): كان أيُّ إرسالِ
                 * نموذجٍ يحمل مرفقاً يُردّ ٤٠٣ عارياً، فتضيع كلُّ البيانات المُدخلة
                 * (اسمُ الوثيقة ورقمُها وتصنيفُها ووصفُها) بلا سبيلِ عودة — وقد
                 * فقدت مسؤولةُ الموارد البشريّة نموذجَها مرّتين في المحاكاة. الملفُّ
                 * يبقى ممنوعاً كما كان، لكنّ المُدخلاتِ تعود مع سببٍ مفهوم.
                 * (التنزيلُ والتصديرُ — طلباتُ GET — تبقى على الردِّ الصريح.)
                 */
                $msg = 'نقل الملفات ممنوع خارج وقت العمل — يعود متاحاً مع بداية الدوام';
                if (! $r->isMethod('GET') && ! $r->expectsJson() && $r->allFiles()) {
                    return back()->withInput($r->except($r->allFiles() ? array_keys($r->allFiles()) : []))
                        ->with('err', $msg . '. حُفظ ما كتبتَه في النموذج — أعِد الإرسال بلا مرفقٍ الآن، أو أرفِق الملفَّ مع بداية الدوام.');
                }
                abort(403, $msg);
            }
        }

        return $next($r);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * وضع الصيانة بدون مبرمج (الإعداد maintenance.on):
 * يمرّ المالكون ومساراتُ الدخول (حتى يستطيع المالك الدخول أصلاً)، والبقية يرون صفحة الصيانة.
 */
class HubMaintenance
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $on   = (bool) setting('maintenance.on', false);
            $lock = (bool) setting('security.lockdown', false);
        } catch (\Throwable $e) {
            $on = $lock = false;
        }

        $isOwner   = (bool) $request->user()?->role?->is_owner;
        $authRoute = $request->routeIs('login', 'login.attempt', 'login.otp', 'login.otp.verify', 'logout');

        // قفل الطوارئ أشد من الصيانة: يطرد حتى الجلسات القائمة لغير المالكين
        if ($lock && ! $isOwner && ! $authRoute) {
            /*
             * **ويردّ بلغة طالبه** (v2.337): كان يُرجع صفحةَ HTML لكل طلب —
             * فالتكاملُ عبر API يتلقّى ٥٠٣ بجسمٍ لا يفهمه ولا يعرف السبب، بينما
             * فرعُ الصيانة أسفلَه يفاوض النوعَ منذ v2.324.
             *
             * والقفلُ على API **يشمل المالك**، وهذا مقصود: هويةُ حامل الرمز لا
             * تُحلّ قبل `ApiAuth` الذي يلي هذا الوسيط، فلا يُعرَف المالكُ هنا —
             * وقفلُ الطوارئ يُغلق السطحَ الآليَّ كلَّه ويُبقي بابَ المتصفّح
             * للمالك. الرسالةُ تقول ذلك صراحةً بدل أن تُترك للاستنتاج.
             */
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => 'قفلُ طوارئ — سطحُ API معلَّقٌ بالكامل. يرفعه مالكُ النظام من المتصفح.',
                ], 503);
            }

            return response()->view('maintenance', [
                'icon' => '🔒', 'title' => 'قفل طوارئ',
                'msg'  => 'عُلّق الوصول مؤقتاً لأسباب أمنية — تواصل مع مالك النظام',
            ], 503);
        }

        if ($on && ! $isOwner && ! $authRoute) {
            /*
             * **وسطحُ API كذلك** (v2.324): كان الوسيطُ على مجموعة `web` وحدها،
             * فالكتابةُ تستمرّ من الباب الخلفيّ أثناء الترحيل — والصيانةُ تُعلَن
             * لتتوقّف الكتابةُ كلُّها. وعلى API يُحصر المنعُ بالطرق **غير
             * الآمنة**: هويةُ حامل الرمز لا تُحلّ قبل `ApiAuth` فلا يُعرَف
             * المالكُ هنا، ومنعُ القراءة كان سيُسقط كلَّ تكاملٍ بلا داعٍ.
             */
            if ($request->expectsJson() || $request->is('api/*')) {
                if ($request->isMethodSafe()) return $next($request);

                return response()->json(['error' => 'النظام في وضع الصيانة — الكتابة متوقفة مؤقتاً'], 503);
            }

            return response()->view('maintenance', [
                'msg' => (string) setting('maintenance.msg', ''),
            ], 503);
        }

        return $next($request);
    }
}

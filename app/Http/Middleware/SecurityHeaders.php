<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** ترويسات أمنية موحدة لكل الاستجابات */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (method_exists($response, 'header')) {
            $response->header('X-Frame-Options', 'SAMEORIGIN');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

            // **لا يظهر في جوجل**: نظامٌ خاصٌّ خلف تسجيل دخول لا مكان له في فهرس
            // بحثٍ عامّ. الترويسة أقوى من robots.txt وحدها: تُطبَّق على **كل** استجابة
            // (حتى المرفقات وروابط التوقيع المشارَكة خارجاً)، وتقول للزاحف «لا تفهرس
            // ولا تتبع الروابط ولا تحتفظ بنسخة» — فحتى لو زحف رغم المنع، لا يفهرس.
            // تُكتب هنا بعد المتحكّمات فتَغلب أيَّ noindex أضيق كتبه متحكّمٌ سابق.
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

            // **وسمُ صفحات الجلسة** (v2.323): عاملُ الخدمة كان يخبّئ كلَّ تنقّلٍ
            // ناجح، فصفحةُ مستخدمٍ بمحتواها تُقدَّم بعد خروجه أو لمستخدمٍ آخر على
            // الجهاز نفسه. الوسمُ يُخبره أيَّ استجابةٍ لا يُخبّئ — و`no-store`
            // تمنع خبيئةَ المتصفح والوسطاء عنها كذلك.
            if ($request->user()) {
                $response->header('X-Lyn-Auth', '1');
                $response->header('Cache-Control', 'no-store, private');
            }
        }

        return $response;
    }
}

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
            // الكاميرا والميكروفون محظوران؛ والموقع **مسموحٌ لأصلنا وحده** `(self)`
            // لا مفتوحٌ للجميع ولا محظورٌ كلياً: التقاطُ موقع الحضور والتنفيذ الميدانيّ
            // يحتاجه (بموافقة المتصفح لحظياً)، وكان `geolocation=()` يحجبه بصمتٍ حتى
            // عن صفحاتنا. `(self)` يقصره على مستنداتنا ويمنع أيَّ إطارٍ أجنبيّ من طلبه.
            $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

            // **HSTS — يُثبّت HTTPS ويمنع هبوطَ الاتصال** (v2.357): النظامُ خلف تسجيل
            // دخولٍ وكعكةُ جلسته `secure`، فلا معنى لقبول HTTP. تُرسَل فقط على اتصالٍ
            // آمن (أو خلف بروكسي ينهي TLS) كي لا تُثبَّت على http محليّ؛ والمتصفّح لا
            // يعبأ بها إلا إذا وصلته عبر HTTPS، فإرسالُها بلا ضرر.
            if ($request->isSecure() || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https') {
                $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            }

            // **CSP دفاعيّة لا تكسر الواجهة** (v2.357): الواجهةُ تعتمد أنماطاً ونصوصاً
            // مضمّنة، فحصرُ `script-src`/`style-src` يكسرها — لكنّ هذه الثلاثة أمانٌ
            // خالصٌ بلا كسر: تمنع اختطافَ `<base>`، وحقنَ الإضافات (`object`)، وتأطيرَ
            // الموقع من غيره. لا تُكتب إن كان متحكّمٌ قد ضبط سياسةً أخصّ (خدمةُ
            // المرفقات تقفل `default-src 'none'`) كي لا تُضعَّف بالأوسع.
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->header('Content-Security-Policy',
                    "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
            }

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

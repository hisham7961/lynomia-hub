<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * **متى بدأ التنزيل؟** — الخادمُ يقول، لا الصفحةُ تُخمّن.
 *
 * تنزيلُ حزمةِ مرفقاتٍ أو تصديرِ جدولٍ كبير يمرّ بلحظةِ **تحضيرٍ صامتة**:
 * الضغطةُ وقعت، ولا شيء يتحرّك في الشاشة، ولا شريطُ تنزيلٍ في المتصفح بعد —
 * لأن أول بايتٍ لم يُبَثّ. فيُضغط الزرُّ ثانيةً وثالثة، ويُبنى الملفُّ ثلاثاً.
 *
 * الحيلةُ المعروفة: الطلبُ يحمل `dlt` (رمزاً يولّده المتصفح)، والردُّ يحمل
 * كعكةً — والكعكةُ لا تصل إلا مع الترويسات، أي **لحظةَ بدء البثّ**. فتُخفي
 * الصفحةُ لوحةَ «يُحضَّر» عند وصولها. اسمُ الكعكة ثابتٌ (`hub_dl`) كي تمرّ
 * بتشفير الكعكات المعتاد بلا استثناء، والصفحةُ تحذفها فور رؤيتها.
 *
 * ولا أثرَ لها على ردٍّ ليس تنزيلاً: بلا `dlt` لا كعكةَ أصلاً.
 */
class DownloadPing
{
    public const COOKIE = 'hub_dl';

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $token = hub_str($request->query('dlt'));
        // رمزٌ من صنع الصفحة: حروفٌ وأرقامٌ قصيرة — وما عداه يُتجاهَل بلا ضجّة
        if ($token === '' || ! preg_match('/^[A-Za-z0-9]{4,40}$/', $token)) return $response;

        // `headers` حقلٌ لا دالّة — و`method_exists` عليه تردّ خطأً دائماً فلا
        // تُختم كعكةٌ أبداً. الشرطُ على النوع نفسِه (ردُّ الملف الثنائيّ منه).
        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->headers->setCookie(cookie(
                self::COOKIE, $token, 1, '/', null, $request->secure(), false
            ));
        }

        return $response;
    }
}

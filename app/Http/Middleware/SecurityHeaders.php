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

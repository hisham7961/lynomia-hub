<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تسجيل التنقل داخل النظام لمركز نشاط الموظفين.
 * صفحات GET الكاملة فقط (لا أجزاء htmx ولا أصول)، ومخنوق: الصفحة نفسها لا
 * تُسجَّل مرتين خلال دقيقتين — فالسجل مسارُ تنقلٍ لا ضجيج نقرات.
 */
class TrackVisits
{
    protected const SKIP = ['search.mini', 'notifications.mini', 'jslog', 'healthz', 'pwa.*'];

    public function handle(Request $r, Closure $next)
    {
        $res = $next($r);

        try {
            $u = auth()->user();
            // **صفحاتُ GET الكاملة فقط** — كما يقول توثيق الوسيط. شرطُ htmx
            // وحده كان يترك كلَّ استفتاءٍ خلفيّ يُسجَّل «زيارةَ صفحة» (نبضةُ عدّ
            // التنبيهات كل دقيقة مثلاً)، فيُلوَّث ملفُّ الشك بنشاطٍ ليليّ لم يقع
            // ويُطلق تنبيهاً أمنيّاً كاذباً. الوصفُ الصحيح يُسقط الحاليَّ
            // والمستقبليَّ معاً بلا قائمةٍ يدنو منها النسيان.
            if ($u && $r->isMethod('GET')
                && ! $r->header('HX-Request') && ! $r->ajax() && ! $r->expectsJson()
                && ! $r->routeIs(...self::SKIP) && ! str_starts_with($r->path(), 'files/')) {
                $path = '/' . ltrim($r->path(), '/');
                $key = 'pv:' . $u->id . ':' . $path;
                if (! session()->has($key) || now()->timestamp - (int) session($key) > 120) {
                    session([$key => now()->timestamp]);
                    DB::table('page_visits')->insert([
                        'id' => (string) Str::uuid(), 'user_id' => $u->id,
                        'path' => substr($path, 0, 190),
                        'route' => substr((string) $r->route()?->getName(), 0, 120),
                        'at' => now(),
                    ]);
                    // التشذيبُ نُقل إلى hub:automation (v2.350): حذفٌ على عمودٍ
                    // في أثناء تحميل صفحةٍ يُبطئ الطلبَ ويقفل الجدول — لا مكانَ له
                    // في مسار المستخدم.
                }
            }
        } catch (\Throwable $e) {
            // التتبع لا يُعطل التصفح أبداً
        }

        return $res;
    }
}

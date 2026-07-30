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
            if ($u && $r->isMethod('GET') && ! $r->header('HX-Request')
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
                    // تشذيب عرضي: ما جاوز ٩٠ يوماً يُحذف (فرصة ~١٪ لكل تسجيلة)
                    if (random_int(1, 100) === 1) {
                        DB::table('page_visits')->where('at', '<', now()->subDays(90))->delete();
                    }
                }
            }
        } catch (\Throwable $e) {
            // التتبع لا يُعطل التصفح أبداً
        }

        return $res;
    }
}

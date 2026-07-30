<?php

namespace App\Http\Middleware;

use App\Support\ErrorLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** معرّف طلب لكل نداء (X-Request-Id) + التقاط الطلبات البطيئة (> ثانية) */
class Observability
{
    public function handle(Request $request, Closure $next)
    {
        $rid = (string) Str::uuid();
        $request->attributes->set('request_id', $rid);
        $start = microtime(true);

        $response = $next($request);

        if (method_exists($response, 'header')) $response->header('X-Request-Id', $rid);

        $ms = (int) ((microtime(true) - $start) * 1000);
        $limit = max(50, (int) setting('ops.slow_ms', 1000));   // عتبة البطء قابلة للضبط من الإعدادات
        if ($ms > $limit && ! $request->is('files/*', 'storage/*')) {
            // المدة تُقرَّب لمرتبة: لو دخلت الرسالةَ بالمللي ثانية لصار كل طلب بطيء
            // صفاً فريداً — فيغرق مركز الأخطاء بدل أن يعدّ تكرار البطء نفسه.
            $tier = $ms >= 30000 ? '>30ث' : ($ms >= 10000 ? '>10ث' : ($ms >= 3000 ? '>3ث' : '>ثانية'));
            // تعميم المعرّفات في المسار: بدونه صار كل سجل بطيء صفَّ خطأ مستقلاً بلا تجميع
            $path = preg_replace([
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
                '/\/\d+(?=\/|$)/',
            ], ['{id}', '/{n}'], $request->path());
            ErrorLog::capture('slow', 'طلب بطيء (' . $tier . '): ' . $request->method() . ' ' . $path);
        }

        return $response;
    }
}

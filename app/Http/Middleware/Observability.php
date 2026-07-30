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
        if ($ms > 1000 && ! $request->is('files/*', 'storage/*')) {
            ErrorLog::capture('slow', 'طلب بطيء (' . $ms . 'ms): ' . $request->method() . ' ' . $request->path());
        }

        return $response;
    }
}

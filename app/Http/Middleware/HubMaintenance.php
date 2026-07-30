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
            $on = (bool) setting('maintenance.on', false);
        } catch (\Throwable $e) {
            $on = false;
        }

        if ($on
            && ! $request->routeIs('login', 'login.attempt', 'login.otp', 'login.otp.verify', 'logout')
            && ! $request->user()?->role?->is_owner) {
            return response()->view('maintenance', [
                'msg' => (string) setting('maintenance.msg', ''),
            ], 503);
        }

        return $next($request);
    }
}

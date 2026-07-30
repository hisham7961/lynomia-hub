<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // مدة الجلسة من إعدادات النظام (بدون مبرمج) — محصّن قبل تجهيز القاعدة
        try {
            if ($min = (int) setting('auth.session_min', 0)) {
                config(['session.lifetime' => max(5, $min)]);
            }
        } catch (\Throwable $e) {
        }

        // حد معدل طلبات API: ١٢٠ بالدقيقة لكل مفتاح/عنوان
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by(
            $request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip()
        ));

        /** صلاحية وحدة: Gate::allows('mod', [$moduleKey, 'v|a|e|d']) — المالك مسموح له كل شيء */
        Gate::define('mod', function (User $user, string $module, string $op = 'v') {
            return hub_can($user, $module, $op);
        });
    }
}

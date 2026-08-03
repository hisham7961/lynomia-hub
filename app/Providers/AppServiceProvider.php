<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        /*
         * **حارسُ القاعدة**: الأمرُ الهادم يُحجَب بأمرٍ يحمل اسمَه نفسه فيحلّ
         * محلَّه — فالشيفرةُ الهادمة **لا تُحمَّل أصلاً**. وهذا أوثق من اعتراض
         * حدثٍ قد لا يُطلَق في كل مسار. والإذنُ الصريح يرفع الحجب فيعود الأصل.
         */
        // تُستثنى الحزمةُ نفسها: أداةُ الاختبار تُعيد بناء قاعدةٍ مؤقتة بحقّ،
        // ولا بياناتٍ فيها تُفقد. والاختبارُ الذي يفحص الحاجز يستدعيه صراحةً.
        if (! $this->app->runningUnitTests()) \App\Support\SchemaGuard::shield();

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

        // بريد SMTP من حقول مركز المراسلة — تغلب .env إن مُلئت، محصّنة مثلها
        \App\Support\MailSettings::apply();

        // حد معدل طلبات API: ١٢٠ بالدقيقة لكل مفتاح/عنوان
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by(
            $request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip()
        ));

        /**
         * ختمُ تغيّر الجداول: كل كتابةٍ ترفع عدّاد جدولها، ومفاتيح الشاشات
         * المحسوبة تحمل الختم — فتُبطَل خبيئتُها لحظةَ تتغيّر بياناتها لا بعد
         * مهلتها. بلا هذا تعرض الشاشة رقماً قديماً فيُعاد التعديل ظنّاً أنه ضاع.
         */
        foreach (['eloquent.saved: *', 'eloquent.deleted: *', 'eloquent.restored: *'] as $ev) {
            Event::listen($ev, function ($event, $payload) {
                $m = is_array($payload) ? ($payload[0] ?? null) : $payload;
                if ($m instanceof \Illuminate\Database\Eloquent\Model) hub_data_bump($m->getTable());
            });
        }

        /** صلاحية وحدة: Gate::allows('mod', [$moduleKey, 'v|a|e|d']) — المالك مسموح له كل شيء */
        Gate::define('mod', function (User $user, string $module, string $op = 'v') {
            return hub_can($user, $module, $op);
        });
    }
}

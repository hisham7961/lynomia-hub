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

        /*
         * حدُّ معدّل API: ١٢٠ بالدقيقة لكل مفتاح — **وسقفٌ للعنوان لا يُفلَت منه**.
         *
         * الفرزُ بالرمز وحده كان يُلغي الحدَّ عمليّاً: الرمزُ نصٌّ يرسله الطالب،
         * فيُدوّره كل ١٢٠ طلباً ويبدأ حصّةً جديدة بلا نهاية. والرمزُ الباطل يُردّ
         * بـ401 لكن بعد إقلاعِ الإطار واستعلامِ قاعدةٍ للتحقق منه — فالطوفانُ
         * يبلغ القاعدةَ بلا سقف. السقفُ الثاني مفروزٌ بما **لا** يملكه الطالب.
         */
        RateLimiter::for('api', function ($request) {
            $tok = (string) $request->bearerToken();
            $limits = [Limit::perMinute(300)->by('api-ip:' . $request->ip())];
            if ($tok !== '') $limits[] = Limit::perMinute(120)->by('api-key:' . hash('sha256', $tok));

            return $limits;
        });

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

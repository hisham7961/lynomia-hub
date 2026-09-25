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
    public function register(): void
    {
        /*
         * **منفذُ التوليدِ يُختار بحالةِ النظامِ لا بسطرٍ يُحرَّر** (جاهزيّةُ الإنتاج).
         *
         * كان هنا ربطٌ ثابتٌ بمولِّدٍ فارغ، وكان «التشغيلُ الحقيقيُّ» يعني
         * **تعديلَ هذا السطرِ ودفعةً ونشراً**. والمطلوبُ أن يُشغّله المالكُ من
         * مركزِ الذكاء وحدَه — فصار القرارُ إلى `AskGeneratorFactory` يقرأ
         * جاهزيّةَ البوّابةِ والغرضِ والقدرةِ حيّةً عند كلِّ طلب.
         *
         * **وربطٌ جديدٌ لكلِّ استدعاءٍ لا مفردةٌ مشتركة**: المولِّدُ الحيُّ يحمل
         * حالةَ طلبٍ واحد (رحلةُ التوجيهِ وعدُّ النداءاتِ وأزواجُ الرسائل)،
         * ومشاركتُها بين طلبين تُسرّب حوارَ سائلٍ إلى آخر.
         *
         * **وفي الاختبارِ يبقى الفارغُ دائماً** ما لم يُحقَن مولِّدٌ صراحةً:
         * فحزمةٌ تُهيّئ البوّابةَ لغرضٍ آخرَ لا تطرق منفذاً من حيث لا تدري.
         * وقرارُ المصنعِ نفسُه مقيسٌ مباشرةً، فلا فرعَ يفلت من القياس.
         */
        $this->app->bind(\App\Contracts\AskGenerator::class, static function ($app) {
            return $app->runningUnitTests()
                ? new \App\Support\Ai\Ask\NullAskGenerator()
                : \App\Support\Ai\Ask\AskGeneratorFactory::make();
        });
    }

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

        /*
         * (الجولة 1 · F35) **خانقُ الدخول بالبريد والعنوان معاً.**
         *
         * كان `throttle:10,1` بالعنوان وحدَه — فمكتبٌ كاملٌ خلف NAT واحدٍ
         * (محاكاةُ البشر: وكيلان اصطدما بـ٤٢٩ بعد دخولَين) يتقاسم حصّةً واحدة،
         * ودورةُ فريقٍ صباحيّةٌ تستنفدها قبل ثالثِ زميل. الفرزُ الآن على
         * (بريد، عنوان): عشرُ محاولاتٍ بالدقيقة لكلِّ حسابٍ من كلِّ عنوان —
         * كبحُ التخمين على الحسابِ الواحدِ باقٍ بكامله، وقفلُ الحساب
         * (`AccountLockout`) فوقَه. وسقفٌ ثانٍ أوسعُ على العنوان وحدَه يصدّ
         * إغراقاً يدوّر البريدَ في كل طلب — الحمايةُ تُعاد فرزاً لا تُطفأ.
         */
        RateLimiter::for('login', function ($request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('login:' . sha1($email) . '|' . $request->ip()),
                Limit::perMinute(60)->by('login-ip:' . $request->ip()),
            ];
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

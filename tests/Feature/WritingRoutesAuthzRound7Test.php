<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السابعة — **مسحٌ شاملٌ لتغطية الصلاحية على كل فعلٍ كاتب.**
 *
 * ستُّ موجاتٍ صُلّبت أبواباً بعينها، وهذا الحارسُ يسأل السؤالَ **الشامل**:
 * هل يوجد في المشروع مسارٌ كاتب (‏POST/PUT/PATCH/DELETE) يقبل من حسابٍ
 * «عرضٌ فقط» أن يكتب به؟
 *
 * والمنهج **إثباتٌ لا استنتاج**: لا تُقرأ الشيفرةُ بحثاً عن `hub_can` (فحارسٌ
 * في دالةٍ مساعدة لا يراه القارئ، وحارسٌ مكتوبٌ قد لا يُنفَّذ) — بل يُطلَب
 * المسارُ فعلاً بحساب المشاهد، ويُطالَب بالرفض.
 *
 * والمسارات المستثناة **مُعلَّلةٌ واحداً واحداً** في `SELF_SCOPED`: أفعالٌ
 * يملكها كلُّ حسابٍ على بياناته هو (تفضيلاته، كلمته، إشعاراته) فلا معنى
 * لصلاحيةٍ فوقها. وكلُّ ما عداها يجب أن يُردّ.
 */
class WritingRoutesAuthzRound7Test extends TestCase
{
    /**
     * أفعالٌ يملكها كلُّ حسابٍ على نفسه — لا صلاحيةَ فوق «أن تكون أنت».
     * كلُّ مدخلٍ هنا قرارٌ صريح، لا سهوٌ ولا تهرّب.
     */
    protected const SELF_SCOPED = [
        'profile.update', 'profile.password', 'profile.notify',        // ملفُّه هو
        'profile.token.store', 'profile.token.revoke', 'profile.token.rotate',
        'profile.2fa.start', 'profile.2fa.enable', 'profile.2fa.disable',
        'prefs.reset', 'prefs.pin', 'prefs.theme', 'prefs.nav',        // تفضيلاتُ العرض
        'views.default', 'views.destroy', 'views.store',               // عروضُه المحفوظة
        'notifications.read', 'notifications.readAll', 'notifications.read-all',
        'dm.start', 'dm.send', 'dm.read',                              // مراسلاتُه
        'logout', 'jslog',                                             // خروجُه وبلاغُ خطأ متصفّحه
        'acks.ack',                                                    // إقرارُه بسياسة
        'search.recent',
        'prefs.update',                                                // تخصيصُ شاشته هو
        // أمنُه الذاتي: جلساتُه وأجهزتُه هو — يبطلها على صفوفه حصراً (mysec
        // يفحص user_id === auth في كل فعل)، فلا صلاحيةَ فوق «أن تكون أنت»
        'mysec.session.revoke', 'mysec.session.others',
        'mysec.device.trust', 'mysec.device.revoke',
        // تبديلُ الشركة النشطة **تصفيةُ عرضٍ** لا كتابةَ بيانات: يُخزَّن في جلسته،
        // والتبديلُ إلى شركةٍ بعينها محروسٌ بـ`companies.v` + وجودِها + كونِها
        // داخل نطاقه (routes/web.php:129) — والقراءةُ تبقى منطَّقةً بعدها.
        'company.switch',
        // ومبدّلُ مساحة العميل نظيرُه حرفياً: جلسةُ تركيزٍ محروسة بـ`clients.v`
        // والعزلِ الصارم — والفارغُ (العودةُ للداخلية) لا يحتاج إذناً كالشركة
        'client.switch',
        // معاينةُ نصّ وثيقةٍ عرضٌ لا كتابة: محروسةٌ بـ`contracts.v` ولا تُنشئ صفّاً
        'esign.preview',
        // «أصلِح النواقص» يمرّ بـ`gate()` ثم يفحص `hub_can(…, 'e')` لكلِّ سجل،
        // ويُبلّغ المرفوضَ بـ`err` لا بـ`ok` (v2.336). وعلى قاعدةٍ فارغة يردّ
        // «لا شيءَ ناقص» بصدق — وهي حصيلةٌ لا كتابة. حراستُه مُثبَتةٌ حيّاً في
        // `HonestOutcomeRound7Test::test_a_permission_refusal_is_not_painted_green`.
        'appsprojects.fix',
    ];

    /** مساراتٌ تُستثنى لسببٍ تقنيّ لا أمنيّ (تحتاج توقيعاً أو رمزاً خارج الجلسة) */
    protected const OUT_OF_SESSION = [
        'sign.', 'dataroom.', 'quoteflow.', 'hook.', 'api.', 'portal.',
    ];

    public function test_no_writing_route_accepts_a_view_only_account(): void
    {
        $this->seedCore();

        $allowed = [];     // قُبل — عيبٌ محتمل
        $unproven = [];    // لم يُثبت (404 لعدم وجود سجل) — يُعرض للشفافية

        foreach (Route::getRoutes() as $r) {
            $methods = array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            if (! $methods) continue;

            $name = (string) $r->getName();
            if ($name === '' || in_array($name, self::SELF_SCOPED, true)) continue;
            foreach (self::OUT_OF_SESSION as $p) if (str_starts_with($name, $p)) continue 2;

            // مسارٌ خارج حزام الجلسة (بلا auth) ليس محلَّ هذا الحارس
            $mw = array_map('strval', $r->gatherMiddleware());
            if (! in_array('auth', $mw, true)) continue;

            $uri = '/' . preg_replace_callback('/\{([^}?]+)\??\}/', function ($m) {
                return match (trim($m[1], '?')) {
                    'module' => 'clients',
                    'version' => '1',
                    default => (string) Str::uuid(),
                };
            }, $r->uri());

            $verb = strtolower(reset($methods));
            $res = $this->actingAs($this->viewer)->{$verb}($uri, []);
            $code = $res->getStatusCode();

            if (in_array($code, [403, 419, 429], true)) continue;                 // رُفض صراحةً
            if ($code === 404) { $unproven[] = $name . ' (' . $uri . ')'; continue; }
            if ($code === 422 || $code === 400) continue;                          // مدخلاتٌ ناقصة — لم يكتب
            if ($code === 302) {
                // إعادةُ توجيهٍ برسالة خطأ = رفضٌ مقبول؛ وبرسالة نجاح = كتابةٌ وقعت
                $ok = session('ok');
                if ($ok) { $allowed[] = $name . '  → 302 «' . Str::limit((string) $ok, 60) . '»'; }
                session()->forget(['ok', 'err', 'warn']);
                continue;
            }
            if ($code >= 500) { $allowed[] = $name . '  → ' . $code . ' (عطلٌ لا رفض)'; continue; }

            $allowed[] = $name . '  → ' . $code;
        }

        $this->assertSame([], $allowed,
            'مساراتٌ كاتبةٌ قبلت من حسابٍ «عرضٌ فقط»: ' . implode(' · ', $allowed));

        // الشفافيةُ شرط: ما لم يُثبَت لا يُدّعى أنه محروس
        $this->assertLessThan(60, count($unproven),
            'أكثرُ ممّا ينبغي من المسارات لم يُثبَت حراسُه (رُدّ 404 لغياب السجل): '
            . implode(' · ', array_slice($unproven, 0, 20)));
    }
}

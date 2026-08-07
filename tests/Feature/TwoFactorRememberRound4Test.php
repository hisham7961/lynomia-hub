<?php

namespace Tests\Feature;

use App\Models\SessionLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * عنقود المصادقة الثنائية و«تذكّرني» — وقد شُدّد في v2.354.
 *
 * **لا دخول إلا بالبريد وكلمة المرور** (طلبُ المالك، v2.354): النظامُ لم يعد يُصدر
 * كعكةَ «تذكّرني» (remember: false)، وأيُّ كعكةٍ قديمة باقيةٍ تُردّ إلى بوابة الدخول
 * فلا عودةَ إلا بالبريد وكلمة المرور. فالاختباران أدناه صارا يؤكّدان **الردَّ الكامل**
 * لا مجرّد «لا تجاوز للرمز» — الكعكةُ لا تدخل أصلاً، بـ2FA أو بدونها.
 *
 * (٨٨) خطوةُ رمز TOTP بلا قفل حساب — تخمينٌ غير محدودٍ لرمز حسابٍ بعينه.
 *
 * harness «تذكّرني»: نبني كعكة recaller يدويّاً (id|remember_token|password) مشفّرةً
 * كما يتوقّعها EncryptCookies، وننسى الحارس المُخبَّأ — فيصادَق الطلبُ عبر الكعكة
 * وحدها (viaRemember)، تماماً كمتصفّحٍ عائدٍ بلا جلسة.
 */
class TwoFactorRememberRound4Test extends TestCase
{
    protected function enable2fa(User $u): string
    {
        $secret = Totp::secret();
        $u->forceFill(['totp_secret_cipher' => $secret, 'totp_enabled' => true,
            'failed_attempts' => 0, 'locked_until' => null])->save();

        return $secret;
    }

    /** زيارةٌ مُصادَقةٌ عبر كعكة «تذكّرني» وحدها (viaRemember) */
    protected function rememberVisit(User $u, string $uri)
    {
        if (! $u->getRememberToken()) {
            $u->setRememberToken(Str::random(60));
            $u->save();
        }
        // القيمة الخام: عميلُ الاختبار يشفّرها بنفسه (CookieValuePrefix + encrypt)
        // فـEncryptCookies يفكّها إلى الـ recaller — فلا تشفيرَ يدويّاً هنا.
        $name = Auth::guard('web')->getRecallerName();
        $recaller = $u->id . '|' . $u->getRememberToken() . '|' . $u->getAuthPassword();

        Auth::forgetGuards();

        return $this->withCookie($name, $recaller)->get($uri);
    }

    /** كعكة «تذكّرني» وحدها (بلا 2FA) لا تدخل — تُردّ إلى بوابة الدخول بالبريد وكلمة المرور */
    public function test_remember_cookie_without_2fa_is_refused_and_forces_full_login(): void
    {
        $this->seedCore();   // المالك بلا 2FA

        $this->rememberVisit($this->owner, '/m/tasks')->assertRedirect(route('login'));

        // ولا جلسةَ حضورٍ تُسجَّل لدخولٍ لم يقع — الكعكةُ رُدّت لا بُعثت
        $this->assertSame(0, SessionLog::where('user_id', $this->owner->id)->count(),
            'كعكةُ «تذكّرني» بعثت جلسةً بلا كلمة مرور — يجب ألّا تدخل أصلاً');
    }

    /** كعكة «تذكّرني» مع 2FA كذلك لا تدخل — الردُّ الكامل أشدُّ من إعادة التحدّي بالرمز */
    public function test_remember_cookie_with_2fa_is_also_refused(): void
    {
        $this->seedCore();
        $this->enable2fa($this->owner);

        // لا تجاوزَ للرمز، ولا حتى إعادةَ تحدٍّ به: عودةٌ كاملةٌ لبوابة الدخول
        $this->rememberVisit($this->owner, '/m/tasks')->assertRedirect(route('login'));
    }

    /** (٨٨) خطوةُ رمز TOTP تقفل الحساب بعد محاولاتٍ فاشلة */
    public function test_totp_step_locks_account_after_max_failures(): void
    {
        $this->seedCore();
        $this->hubSetting('auth.max_fail', '3');
        $secret = $this->enable2fa($this->owner);
        $cur = Totp::code($secret);
        $wrong = $cur === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 3; $i++) {
            $this->withSession(['2fa:uid' => $this->owner->id])
                ->post('/login/otp', ['code' => $wrong]);
        }

        $locked = $this->owner->fresh()->locked_until;
        $this->assertNotNull($locked, 'خطوةُ رمز TOTP بلا قفل حساب بعد محاولاتٍ فاشلة');
        $this->assertTrue(now()->lt($locked), 'القفل ليس في المستقبل');
    }
}

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
 * الموجة الرابعة (مؤجّل) — عنقود المصادقة الثنائية و«تذكّرني»:
 * (٩٨) المصادقة الثنائية (TOTP) تُتجاوَز كلياً عند إعادة الدخول عبر كعكة «تذكّرني»:
 *      الجلسةُ تُبعَث من الكعكة (viaRemember) دون المرور ببوابة الرمز — فكعكةٌ
 *      مسروقةٌ أو عودةٌ بعد انتهاء الجلسة تدخل بلا رمز.
 * (١١٨) الجلساتُ المُعاد بعثها من الكعكة لا تُسجَّل في sessions_log فلا تُنهى من
 *      مركز الأمان — تبقى أشباحاً لا يراها المالك ولا يقدر على إخراجها.
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

    /** (١١٨) جلسةٌ مُعادةٌ من كعكة «تذكّرني» تُسجَّل فتُنهى من مركز الأمان */
    public function test_remember_rebuilt_session_is_logged(): void
    {
        $this->seedCore();   // المالك بلا 2FA

        $this->rememberVisit($this->owner, '/m/tasks')->assertOk();

        $this->assertGreaterThanOrEqual(1, SessionLog::where('user_id', $this->owner->id)->count(),
            'الجلسة المُعادة من الكعكة لم تُسجَّل — لا تُنهى من مركز الأمان');
    }

    /** (٩٨) كعكة «تذكّرني» لا تتجاوز بوابة المصادقة الثنائية */
    public function test_remember_cookie_does_not_bypass_2fa(): void
    {
        $this->seedCore();
        $this->enable2fa($this->owner);

        // عودةٌ بالكعكة وحدها: يجب أن يُعاد التحدّي بالرمز لا أن يُمنَح الوصول
        $this->rememberVisit($this->owner, '/m/tasks')->assertRedirect(route('login.otp'));
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

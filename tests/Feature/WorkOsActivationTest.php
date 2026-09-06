<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **تفعيلُ حساب العميل الآمن: بريد ← رمز ← كلمةُ سرٍّ يضعها العميلُ بنفسه**
 * (Work OS · الطور B · WP-B.1 · §12).
 *
 * يمتدّ سابقةَ اختبارات OTP في التوقيع الإلكتروني (EsignController: hash+expire+
 * OutboxMessage+single-use + Totp::verifyOnce) لا يستنسخها: النمطُ الأمنيُّ نفسُه
 * — رمزٌ مُجزَّأ، ينتهي، يُستهلَك مرّةً، مقيَّدُ المحاولات — لكن للتفعيل لا للتوقيع.
 *
 * القاعدةُ الحاكمةُ التي يحرسها هذا الملف (§12 · قواعدُ أمن الطور B):
 *  1) **لا كلمةَ سرٍّ تُولَّد وتُرسَل أو تُخزَّن صريحة أبداً** — العميلُ يضعها بنفسه.
 *     يُنشأ الحسابُ بلا كلمةِ سرٍّ صالحةٍ للدخول (تجزيءُ عشوائيٍّ لا يعرفه أحد)،
 *     ولا نصَّ في أيّ OutboxMessage يحمل كلمةَ سرّ — نمسح صندوقَ الصادر ونتأكّد.
 *  2) الرمزُ يُستهلَك مرّةً واحدة، والمنتهي مرفوض، ويُقيَّد بعدد المحاولات.
 *  3) كلمةُ السرّ النهائيةُ تحترم `password_rules()` (لا كلمةَ ضعيفة).
 *  4) الرمزُ المُستهلَك (بعد إتمام التفعيل) لا يُعاد استعماله.
 */
class WorkOsActivationTest extends TestCase
{
    /** كلمةُ سرٍّ يضعها العميلُ بنفسه — تحترم password_rules، ومعلومةٌ للاختبار كي نتحرّى تسرّبها */
    private const CLIENT_PW = 'Cli3nt!Secret2026';

    private function clientUser(): User
    {
        $this->seedCore();

        // يُنشأ عبر السكّة الوحيدة (لا مسارَ ثانٍ): بلا كلمةِ سرٍّ صالحة، account_type=client
        [$user] = AccountActivation::provisionClient([
            'name' => 'عميلُ التفعيل',
            'email' => 'activate.me@client.test',
            'role_id' => $this->viewer->role_id,
        ]);

        return $user;
    }

    /** آخرُ رسالةِ تفعيلٍ في الصادر — نصُّها هو قناةُ العميل الوحيدة للرابط والرمز */
    private function lastActivationText(): string
    {
        $m = OutboxMessage::where('kind', 'account_activation')->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($m, 'لم تُصفَّ رسالةُ تفعيلٍ في الصادر');

        return (string) $m->text;
    }

    /** استخراجُ الرمز السداسي من نصّ رسالة التفعيل */
    private function otpFromOutbox(): string
    {
        $this->assertMatchesRegularExpression('/\b(\d{6})\b/u', $this->lastActivationText(), 'لا رمزَ سداسيّ في الرسالة');
        preg_match('/\b(\d{6})\b/u', $this->lastActivationText(), $m);

        return $m[1];
    }

    /** استخراجُ الرمز الخام (token) من رابط التفعيل في نصّ الرسالة */
    private function tokenFromOutbox(): string
    {
        $this->assertMatchesRegularExpression('#/activate/([A-Za-z0-9]+)#', $this->lastActivationText(), 'لا رابطَ تفعيلٍ في الرسالة');
        preg_match('#/activate/([A-Za-z0-9]+)#', $this->lastActivationText(), $m);

        return $m[1];
    }

    /* ───────── ١) لا كلمةَ سرٍّ تُولَّد/تُرسَل/تُخزَّن — العميلُ يضعها بنفسه ───────── */

    public function test_account_is_created_with_no_usable_password_and_none_is_ever_emailed(): void
    {
        $user = $this->clientUser();

        // (أ) الحسابُ عميلٌ صلبٌ بلا «تفعيل» بعد (password_changed_at فارغ = لم يضع كلمتَه)
        $this->assertTrue($user->isClientAccount(), 'الحسابُ يجب أن يكون account_type=client');
        $this->assertNull($user->fresh()->password_changed_at, 'قبل التفعيل: لا كلمةَ سرٍّ وضعها العميل بعد');

        // (ب) لا يُمكن الدخولُ بأيّ كلمةٍ — الحسابُ بلا كلمةِ سرٍّ صالحة
        $this->post('/login', ['email' => $user->email, 'password' => self::CLIENT_PW])
            ->assertSessionHasErrors('email');
        $this->assertGuest();   // حسابٌ بلا كلمةِ سرٍّ صالحة لا يدخل قبل التفعيل

        // (ج) رسالةُ التفعيلِ تحمل الرابطَ ولا تحمل كلمةَ سرٍّ
        $text = $this->lastActivationText();
        $this->assertStringContainsString('/activate/', $text, 'الرسالةُ يجب أن تحمل رابطَ التفعيل');

        // نُتمّ التفعيلَ بكلمةٍ نعرفها، ثم نمسح **كلَّ** الصادر: لا رسالةَ تحملها
        $this->runFullActivation();
        foreach (OutboxMessage::all() as $m) {
            $this->assertStringNotContainsString(self::CLIENT_PW, (string) $m->text,
                "كلمةُ السرّ التي وضعها العميلُ تسرّبت في رسالةِ صادرٍ (kind={$m->kind}) — يُحظر حظراً باتّاً");
        }
    }

    /** يُشغّل المسارَ كاملاً (رمز ← كلمةُ سرّ) ويعيد المستخدم */
    private function runFullActivation(): User
    {
        $token = $this->tokenFromOutbox();
        $otp = $this->otpFromOutbox();

        $this->post("/activate/{$token}/otp", ['otp' => $otp])->assertRedirect();
        $this->post("/activate/{$token}/set", ['password' => self::CLIENT_PW, 'password_confirmation' => self::CLIENT_PW]);

        return User::where('email', 'activate.me@client.test')->firstOrFail();
    }

    /* ───────── ٢) الرمزُ يُستهلَك مرّةً — والمنتهي مرفوض — والمحاولاتُ مقيَّدة ───────── */

    public function test_otp_is_single_use(): void
    {
        $this->clientUser();
        $token = $this->tokenFromOutbox();
        $otp = $this->otpFromOutbox();

        // أوّلُ تحقّقٍ ناجح — يُحوَّل لخطوةِ كلمة السرّ
        $this->post("/activate/{$token}/otp", ['otp' => $otp])->assertRedirect();

        // الرمزُ نفسُه مرّةً ثانية (جلسةٌ جديدة) — مرفوضٌ (استُهلك مرّةً)
        $this->flushSession();
        $this->post("/activate/{$token}/otp", ['otp' => $otp])->assertSessionHasErrors('otp');
    }

    public function test_expired_otp_is_rejected(): void
    {
        $this->clientUser();
        $token = $this->tokenFromOutbox();
        $otp = $this->otpFromOutbox();

        // تُدفَع لحظةُ الانتهاء إلى الماضي — الرمزُ الصحيحُ نفسُه يُرفض
        AccountActivation::query()->update(['otp_expires_at' => now()->subMinute()]);

        $this->post("/activate/{$token}/otp", ['otp' => $otp])->assertSessionHasErrors('otp');
    }

    public function test_otp_is_rate_limited_after_repeated_wrong_attempts(): void
    {
        $this->clientUser();
        $token = $this->tokenFromOutbox();
        $otp = $this->otpFromOutbox();

        // محاولاتٌ خاطئةٌ حتى حرقِ الصفّ — كلٌّ في جلسةٍ نظيفةٍ كي لا يُخلط بالحدّ لكلّ IP
        for ($i = 0; $i < AccountActivation::MAX_OTP_ATTEMPTS; $i++) {
            $this->flushSession();
            $this->post("/activate/{$token}/otp", ['otp' => '000000'])->assertSessionHasErrors('otp');
        }

        $act = AccountActivation::firstOrFail();
        $this->assertGreaterThanOrEqual(AccountActivation::MAX_OTP_ATTEMPTS, (int) $act->attempts,
            'عدّادُ المحاولات يجب أن يبلغ السقف');

        // وبعد السقف حتى الرمزُ الصحيحُ يُرفض — الصفُّ محروق
        $this->flushSession();
        $this->post("/activate/{$token}/otp", ['otp' => $otp])->assertSessionHasErrors('otp');
        $this->assertGuest();   // لا دخولَ بعد حرقِ محاولات الرمز
    }

    /* ───────── ٣) كلمةُ السرّ النهائيةُ تحترم password_rules() ───────── */

    public function test_final_password_must_honor_password_rules(): void
    {
        $user = $this->clientUser();
        $token = $this->tokenFromOutbox();
        $otp = $this->otpFromOutbox();

        $this->post("/activate/{$token}/otp", ['otp' => $otp])->assertRedirect();

        // كلمةٌ ضعيفة (قصيرةٌ بلا أرقام/حالة مختلطة) — مرفوضةٌ بقاعدة password_rules
        $this->post("/activate/{$token}/set", ['password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->password_changed_at, 'كلمةٌ ضعيفةٌ يجب ألّا تُثبَّت');
        $this->assertNull(AccountActivation::firstOrFail()->consumed_at, 'صفُّ التفعيل لا يُستهلَك على كلمةٍ مرفوضة');

        // كلمةٌ قوية — تُثبَّت، ويُصبح الحسابُ قابلاً للدخول بها، ويُختم صفُّ التفعيل
        $this->post("/activate/{$token}/set", ['password' => self::CLIENT_PW, 'password_confirmation' => self::CLIENT_PW]);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->password_changed_at, 'كلمةٌ قويةٌ يجب أن تُثبَّت');
        $this->assertTrue(Hash::check(self::CLIENT_PW, $fresh->password), 'الكلمةُ التي وضعها العميلُ هي المخزَّنة');
        $this->assertNotNull(AccountActivation::firstOrFail()->consumed_at, 'صفُّ التفعيل يُستهلَك بعد نجاح الوضع');
    }

    /* ───────── ٤) الرمزُ المُستهلَك لا يُعاد استعماله ───────── */

    public function test_consumed_token_cannot_be_reused(): void
    {
        $this->clientUser();
        $token = $this->tokenFromOutbox();

        $user = $this->runFullActivation();
        $this->assertNotNull($user->fresh()->password_changed_at, 'التفعيلُ اكتمل');

        // بعد الاستهلاك: الصفحةُ والرمزُ والوضعُ كلُّها مرفوضة على الرمزِ نفسِه
        $this->flushSession();
        $this->get("/activate/{$token}")->assertNotFound();
        $this->post("/activate/{$token}/otp", ['otp' => $this->otpFromOutbox()])->assertNotFound();
        $this->post("/activate/{$token}/set", ['password' => 'An0ther!Pass2026', 'password_confirmation' => 'An0ther!Pass2026'])
            ->assertNotFound();
    }

    /* ───────── ٥) عزلٌ: رمزٌ مجهولٌ لا يفتح شيئاً ───────── */

    public function test_unknown_token_is_not_found(): void
    {
        $this->seedCore();
        $this->get('/activate/' . str_repeat('z', 48))->assertNotFound();
        $this->post('/activate/' . str_repeat('z', 48) . '/otp', ['otp' => '123456'])->assertNotFound();
    }
}

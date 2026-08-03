<?php

namespace Tests\Feature;

use App\Models\SessionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة الرابعة — إنفاذ حارسَي الحساب والجلسة على الويب مع كل طلب:
 * (٢) expires_at وallowed_ips كانا يُفحصان في login() فقط؛ الحارس الوحيد الذي يعمل
 *     مع كل طلب (SessionSentry) يفحص deleted_at/status لا غير. فحساب متعاقدٍ انتهى
 *     عقده أو محصورٍ بشبكة المكتب يحتفظ بوصولٍ كامل خلال جلسته الحيّة. أُصلح على
 *     الـAPI (ApiAuth) وبقي الويب دونه — الآن SessionSentry يفرضهما مع كل طلب.
 * (٤) تغيير كلمة المرور كان يدوّر معرّف الجلسة الحالية فقط: لا يدوّر remember_token
 *     ولا يُنهي جلسات الأجهزة الأخرى — فمن غيّر كلمته عند الاشتباه بتسريبها لا يطرد
 *     المهاجم. الآن يُدوَّر الرمز وتُوسم جلسات الأجهزة الأخرى منتهية.
 */
class AuthSessionEnforcementRound4Test extends TestCase
{
    /** (٢) حسابٌ انتهت صلاحيته يُطرد مع أي طلبٍ لا عند الدخول فقط */
    public function test_expired_account_is_kicked_on_every_request(): void
    {
        $this->seedCore();
        $this->owner->forceFill(['expires_at' => now()->subDay()->toDateString()])->saveQuietly();

        $this->actingAs($this->owner->fresh())->get('/service-costs')
            ->assertRedirect(route('login'));
    }

    /** (٢) حسابٌ محصورٌ بعناوين شبكة يُطرد من عنوانٍ خارجها مع أي طلب */
    public function test_ip_restricted_account_is_kicked_from_a_disallowed_ip(): void
    {
        $this->seedCore();
        // عنوان الاختبار 127.0.0.1 ليس ضمن 10.0.0.0/8
        $this->owner->forceFill(['allowed_ips' => '10.0.0.0/8'])->saveQuietly();

        $this->actingAs($this->owner->fresh())->get('/service-costs')
            ->assertRedirect(route('login'));
    }

    /** ولا يُطرد الحساب السليم — لا إفراط في الطرد */
    public function test_a_healthy_account_is_not_kicked(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/service-costs')->assertOk();
    }

    /** (٤) تغيير كلمة المرور يُنهي جلسات الأجهزة الأخرى ويدوّر رمز «تذكّرني» */
    public function test_changing_password_revokes_other_sessions_and_cycles_remember(): void
    {
        $this->seedCore();
        $u = $this->owner;
        $u->forceFill(['remember_token' => str_repeat('X', 60)])->saveQuietly();

        $cur = SessionLog::create(['user_id' => $u->id, 'device' => 'الحالي', 'ip' => '1.1.1.1',
            'started_at' => now(), 'last_seen_at' => now()]);
        $other = SessionLog::create(['user_id' => $u->id, 'device' => 'جهاز آخر', 'ip' => '2.2.2.2',
            'started_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($u)->withSession(['hub.sl' => $cur->id])->put('/profile/password', [
            'current' => 'Secret!2026x',
            'password' => 'NewSecret!2026y',
            'password_confirmation' => 'NewSecret!2026y',
        ])->assertRedirect();

        $this->assertTrue((bool) DB::table('sessions_log')->where('id', $other->id)->value('revoked'),
            'جلسة جهازٍ آخر لم تُنهَ بعد تغيير كلمة المرور');
        $this->assertFalse((bool) DB::table('sessions_log')->where('id', $cur->id)->value('revoked'),
            'الجلسة الحالية أُنهيت خطأً');
        $this->assertNotSame(str_repeat('X', 60), $u->fresh()->remember_token,
            'رمز «تذكّرني» لم يُدوَّر — كعكات الأجهزة الأخرى تبقى صالحة ٤٠٠ يوم');
    }
}

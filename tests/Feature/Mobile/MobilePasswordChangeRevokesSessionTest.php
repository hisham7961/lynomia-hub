<?php

namespace Tests\Feature\Mobile;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **AUTH-1 (تدقيق أمنيّ v2.600) — تغييرُ كلمة المرور يُبطل جلساتِ الجوال.**
 *
 * كان إبطالُ الجلسة عند تغيير/إعادة تعيين كلمة المرور يمرّ بـ`Sessions::revokeAll`
 * وحدَه — وهو يمسّ جدولَ الويب `sessions_log` ويدوّر «تذكّرني»، **ولا يلمس
 * `mobile_sessions` إطلاقاً**. فرمزُ تحديثِ الجوال (٣٠ يوماً) يظلّ يُدوَّر بلا
 * نهايةٍ بعد تغيير الكلمة، ورمزُ الوصولِ يبقى صالحاً حتى انتهائه. فالإجراءُ
 * التصحيحيُّ الوحيدُ الذي يوصي به تطبيقُ الجوال نفسُه («غيّر كلمةَ المرور فوراً»)
 * وإعادةُ التعيين الإداريّة لطردِ مقتحمٍ — **كلاهما يفشل صامتاً على قناة الجوال**.
 *
 * الحارس: أيُّ جلسةِ جوالٍ سُكّت قبل آخرِ تغييرٍ لكلمة المرور
 * (`created_at < users.password_changed_at`) تُرفَض — على مسارِ الوصول
 * (`MobileSessionAuth`) وعلى مسارِ التحديث (`refresh`) معاً. دفاعٌ في العمق
 * يمسك كلَّ مسارات تغييرِ الكلمة لا مسارَي المتحكّمين فحسب. CWE-613.
 */
class MobilePasswordChangeRevokesSessionTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function member(): User
    {
        $role = Role::create(['name' => 'عضو-'.uniqid(), 'scope' => 'proj', 'flags' => [], 'matrix' => []]);

        return User::create([
            'name' => 'مستخدم جوال', 'email' => 'm'.uniqid().'@t.test',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()->subDays(3),
        ]);
    }

    public function test_access_token_is_rejected_after_password_change(): void
    {
        $u = $this->member();
        $data = $this->mobileLogin($u);

        // نجاحٌ قبل التغيير — الجلسةُ حيّة
        $this->getJson('/api/mobile/v1/context', $this->bearer($data['access_token']))->assertOk();

        // تغييرُ كلمة المرور (أيّ مسار) يقدّم password_changed_at بعد سكِّ الجلسة
        $u->forceFill(['password_changed_at' => now()->addSeconds(2)])->save();

        // رمزُ الوصولِ القديمُ صار ميتاً — يُطلَب الدخولُ من جديد
        $this->getJson('/api/mobile/v1/context', $this->bearer($data['access_token']))
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');
    }

    public function test_refresh_token_is_rejected_after_password_change(): void
    {
        $u = $this->member();
        $data = $this->mobileLogin($u);

        $u->forceFill(['password_changed_at' => now()->addSeconds(2)])->save();

        $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => $data['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');
    }
}

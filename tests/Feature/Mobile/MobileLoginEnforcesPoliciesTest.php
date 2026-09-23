<?php

namespace Tests\Feature\Mobile;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **AUTH-2 (تدقيق أمنيّ v2.600) — سطحُ الجوال لا يكون بابَ دخولٍ أضعفَ من الويب.**
 *
 * حارسا `ForcePasswordChange` و`Require2faForPrivileged` وسيطان على الويب
 * يستثنيان `api/*` عمداً؛ فكان دخولُ الجوال يمنح جلسةً كاملةً لمن دخل بكلمةِ
 * مرورٍ مؤقّتةٍ يجب تبديلُها — دخولٌ موازٍ أضعف. الآن يُفرَض على جلسةِ الجوال ما
 * يُفرَض على الويب: 428 بـ policy=must_change_password، فلا جلسةَ حتّى تُبدَّل الكلمة.
 */
class MobileLoginEnforcesPoliciesTest extends TestCase
{
    use InteractsWithMobileAuth;

    public function test_temp_password_user_cannot_get_a_mobile_session(): void
    {
        $role = Role::create(['name' => 'عضو', 'scope' => 'proj', 'flags' => [], 'matrix' => []]);
        // كلمةٌ مؤقّتةٌ يجب تبديلُها: must_change_password + password_changed_at=null
        $u = User::create(['name' => 'جديد', 'email' => 'temp'.uniqid().'@t.test',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'must_change_password' => true, 'password_changed_at' => null]);

        $this->mobileLoginRequest($u->email)
            ->assertStatus(428)
            ->assertJsonPath('code', 'STEP_UP_REQUIRED')
            ->assertJsonPath('details.policy', 'must_change_password');
    }
}

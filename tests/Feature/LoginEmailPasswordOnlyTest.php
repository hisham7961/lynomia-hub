<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * **لا يُدخَل النظامُ إلا بالبريد وكلمة المرور** (طلبُ المالك، v2.354).
 *
 * الدخولُ الصحيح يبقى يعمل، والخاطئُ يُرفض — والأهمّ: **لا تُصدَر كعكةُ «تذكّرني»**
 * التي كانت تُعيد بعثَ الجلسة دون كلمة مرور. فالجلسةُ الوحيدةُ التي تُمنَح مرورُها
 * تسجيلُ دخولٍ كاملٌ بالبريد وكلمة المرور، لا كعكةٌ محفوظة.
 */
class LoginEmailPasswordOnlyTest extends TestCase
{
    public function test_correct_email_and_password_logs_in(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => $this->owner->email, 'password' => 'Secret!2026x'])
            ->assertRedirect();
        $this->assertTrue(Auth::check(), 'الدخول بالبريد وكلمة المرور الصحيحين لم ينجح');
        $this->assertSame($this->owner->id, Auth::id());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => $this->owner->email, 'password' => 'خطأ-تماماً'])
            ->assertSessionHasErrors('email');
        $this->assertFalse(Auth::check(), 'كلمةُ مرورٍ خاطئة سمحت بالدخول');
    }

    /** الإثباتُ الأساسيّ: الدخول لا يُصدر كعكةَ «تذكّرني» — لا عودةَ بلا كلمة مرور */
    public function test_login_issues_no_remember_me_cookie(): void
    {
        $this->seedCore();

        $resp = $this->post('/login', ['email' => $this->owner->email, 'password' => 'Secret!2026x']);

        $remember = collect($resp->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_'));

        $this->assertNull($remember,
            'الدخولُ يُصدر كعكةَ «تذكّرني» — تُتيح العودةَ للنظام دون كلمة مرور، '
            . 'خلافاً لـ«لا يُدخَل إلا بالبريد وكلمة المرور»');
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_guest_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('login', false);
    }

    public function test_wrong_password_rejected_and_logged(): void
    {
        $this->seedCore();
        $this->from('/login')->post('/login', ['email' => 'owner@test.local', 'password' => 'خطأ تماماً'])
            ->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_correct_login_reaches_dashboard(): void
    {
        $this->seedCore();
        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'Secret!2026x'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($this->owner);
        $this->get('/')->assertOk();
    }

    public function test_suspended_user_cannot_login(): void
    {
        $this->seedCore();
        $this->employee->forceFill(['status' => 'موقوف'])->save();
        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x']);
        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/logout')->assertRedirect();
        $this->assertGuest();
    }
}

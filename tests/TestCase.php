<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * قاعدة الاختبارات: قاعدة sqlite بالذاكرة تُبنى من الهجرات لكل اختبار،
 * مع ثلاثة حسابات جاهزة: مالك، موظف (بلا حذف)، مشاهد (عرض فقط).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $employee;
    protected User $viewer;

    protected function seedCore(): void
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $edit = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $view = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $ownerRole = Role::create(['name' => 'مالك', 'is_owner' => true, 'scope' => 'all',
            'flags' => ['secrets' => 1, 'approve' => 1, 'users' => 1, 'audit' => 1, 'exp' => 1, 'monitor' => 1],
            'matrix' => $full]);
        $empRole = Role::create(['name' => 'موظف', 'scope' => 'all', 'flags' => [], 'matrix' => $edit]);
        $viewRole = Role::create(['name' => 'مشاهد', 'scope' => 'all', 'flags' => [], 'matrix' => $view]);

        $this->owner = User::create(['name' => 'المالك', 'email' => 'owner@test.local',
            'password' => 'Secret!2026x', 'role_id' => $ownerRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $this->employee = User::create(['name' => 'موظفة', 'email' => 'emp@test.local',
            'password' => 'Secret!2026x', 'role_id' => $empRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $this->viewer = User::create(['name' => 'مشاهد', 'email' => 'view@test.local',
            'password' => 'Secret!2026x', 'role_id' => $viewRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** مفتاح API لمستخدم — يعيد النص الصريح */
    protected function apiToken(User $u, ?string $scopes = null, ?string $ips = null, $expires = null): string
    {
        $plain = 'lyn_' . \Illuminate\Support\Str::random(44);
        \App\Models\ApiToken::create([
            'user_id' => $u->id, 'name' => 'اختبار', 'token_hash' => hash('sha256', $plain),
            'scopes' => $scopes, 'allowed_ips' => $ips, 'expires_at' => $expires, 'created_at' => now(),
        ]);

        return $plain;
    }
}

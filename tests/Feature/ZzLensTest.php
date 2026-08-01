<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class ZzLensTest extends TestCase
{
    protected function admin(): User
    {
        $role = Role::create(['name' => 'مسؤول مستخدمين', 'scope' => 'all',
            'flags' => ['users' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'ua@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    public function test_escalation_guards_now(): void
    {
        $this->seedCore();
        $u = $this->admin();
        $ownerRole = Role::where('is_owner', true)->first();

        $r1 = $this->actingAs($u)->put(route('users.update', $u), [
            'name' => 'مسؤول', 'email' => 'ua@test.local',
            'role_id' => $ownerRole->id, 'status' => 'نشط']);
        dump('منح الملكية لنفسه: ' . $r1->status(), 'owner? ' . var_export((bool) $u->fresh()->load('role')->role->is_owner, true));

        $r2 = $this->actingAs($u)->put(route('users.update', $this->owner), [
            'name' => 'المالك', 'email' => 'x@evil.local',
            'role_id' => $this->owner->role_id, 'status' => 'نشط', 'password' => 'Pwned!2026xyz']);
        dump('الاستيلاء على حساب المالك: ' . $r2->status(), 'email: ' . $this->owner->fresh()->email);
    }

    public function test_isolated_admin_removes_own_isolation(): void
    {
        $this->seedCore();
        $u = $this->admin();
        $co = \App\Models\Company::create(['name_ar' => 'شركة', 'name_en' => 'Co']);
        $u->forceFill(['companies' => [$co->id]])->save();
        dump('قبل: ', hub_company_ids($u->fresh()));

        $res = $this->actingAs($u->fresh())->put(route('users.update', $u), [
            'name' => 'مسؤول', 'email' => 'ua@test.local',
            'role_id' => $u->role_id, 'status' => 'نشط']);
        dump('code ' . $res->status(), 'بعد: ', hub_company_ids($u->fresh()));
    }

    public function test_last_owner_demotes_self_by_role_change(): void
    {
        $this->seedCore();
        $empRole = Role::where('name', 'موظف')->first();
        dump('عدد المالكين النشطين: ' . \App\Http\Controllers\Web\UserController::activeOwners());

        $res = $this->actingAs($this->owner)->put(route('users.update', $this->owner), [
            'name' => 'المالك', 'email' => $this->owner->email,
            'role_id' => $empRole->id, 'status' => 'نشط']);
        dump('code ' . $res->status(),
            'owner? ' . var_export(hub_is_owner($this->owner->fresh()->load('role')), true),
            'مالكون نشطون الآن: ' . \App\Http\Controllers\Web\UserController::activeOwners());

        // هل بقي منفذٌ لإعادة الملكية؟
        $r2 = $this->actingAs($this->owner->fresh())->get(route('roles.index'));
        dump('/admin/roles => ' . $r2->status());
        $r3 = $this->actingAs($this->owner->fresh())->put(route('users.update', $this->owner), [
            'name' => 'المالك', 'email' => $this->owner->email,
            'role_id' => Role::where('is_owner', true)->first()->id, 'status' => 'نشط']);
        dump('محاولة استرجاع الملكية => ' . $r3->status());
    }

    public function test_api_ignores_account_expiry_and_ip_allowlist(): void
    {
        $this->seedCore();
        $this->employee->forceFill([
            'expires_at' => now()->subDays(30)->toDateString(),
            'allowed_ips' => '10.0.0.1',
        ])->save();

        // الويب يمنع
        $web = $this->post(route('login'), ['email' => 'emp@test.local', 'password' => 'Secret!2026x']);
        dump('login errors: ', session('errors')?->first('email'));

        $tok = $this->apiToken($this->employee->fresh());
        $res = $this->withHeader('Authorization', 'Bearer ' . $tok)->getJson('/api/v1/me');
        dump('API /me => ' . $res->status(), $res->json());
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $tok)->getJson('/api/v1/clients');
        dump('API /clients => ' . $res2->status());
    }
}

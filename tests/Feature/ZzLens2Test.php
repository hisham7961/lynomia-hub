<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

class ZzLens2Test extends TestCase
{
    /** المالك يختار قالب «تقني» ثم يُزيل تأشير رايتي الأسرار عمداً */
    public function test_template_flags_applied_even_when_admin_unticks_them(): void
    {
        $this->seedCore();

        $res = $this->actingAs($this->owner)->post(route('roles.store'), [
            'name' => 'تقني بلا أسرار',
            'scope' => 'all',
            'template' => 'tech',
            'flags_submitted' => '1',          // القسم أُرسل — ولا رايةَ مؤشَّرة
            'matrix' => ['tasks' => ['v' => '1']],
        ]);
        $res->assertRedirect();

        $role = Role::where('name', 'تقني بلا أسرار')->first();
        dump('الرايات المحفوظة: ', $role->flags);
        dump('hub_flag secrets = ' . var_export((bool) ($role->flags['secrets'] ?? 0), true));
        dump('عدد الوحدات في المصفوفة: ' . count($role->matrix));
    }

    /** نفس الشيء عند مسح المصفوفة كلها */
    public function test_template_matrix_applied_when_admin_clears_every_box(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('roles.store'), [
            'name' => 'فارغ عمداً',
            'scope' => 'all',
            'template' => 'tech',
            'flags_submitted' => '1',
            // لا matrix إطلاقاً — «✕ امسح الكل» ثم حفظ
        ])->assertRedirect();

        $role = Role::where('name', 'فارغ عمداً')->first();
        dump('وحدات المصفوفة: ' . count($role->matrix), 'الرايات: ', $role->flags);
        $u = \App\Models\User::create(['name' => 'ت', 'email' => 't@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط']);
        dump('hub_flag(secrets) = ' . var_export(hub_flag($u->fresh()->load('role'), 'secrets'), true));
        dump('hub_can(vault,d) = ' . var_export(hub_can($u->fresh()->load('role'), 'vault', 'd'), true));
    }
}

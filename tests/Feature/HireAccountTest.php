<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * التعيين كان يُنشئ **الملف الوظيفي وحده**: يبقى الموظف الجديد بلا حسابٍ في
 * النظام حتى يتذكّر أحدهم، ولا أحد يراجع صلاحياته لأن لا شيء يُنبّه — فيبدأ
 * أول يومٍ له بانتظار، أو يُعطى حساب زميلٍ «مؤقتاً».
 *
 * الآن: دورٌ يُختار لحظة التعيين، وكلمةُ مرورٍ مؤقتة تُعرض مرةً واحدة، وإشعارٌ
 * لكل من يدير المستخدمين لمراجعة الصلاحيات — وحارس التصعيد نفسه يسري.
 */
class HireAccountTest extends TestCase
{
    protected function candidate(): Candidate
    {
        return Candidate::create(['name' => 'مرشحة جديدة', 'job' => 'مصممة',
            'email' => 'new.hire@test.local', 'phone' => '99000000',
            'expect' => 900, 'stage' => 'عرض وظيفي']);
    }

    public function test_hiring_without_a_role_creates_only_the_employee_file(): void
    {
        $this->seedCore();
        $c = $this->candidate();

        $this->actingAs($this->owner)->post("/candidates/{$c->id}/hire")->assertRedirect();

        $emp = Employee::where('name', 'مرشحة جديدة')->firstOrFail();
        $this->assertNull($emp->user_id);
        $this->assertNull(User::where('email', 'new.hire@test.local')->first());
    }

    public function test_hiring_with_a_role_creates_a_linked_account(): void
    {
        $this->seedCore();
        $c = $this->candidate();
        $role = Role::where('is_owner', false)->firstOrFail();

        $res = $this->actingAs($this->owner)->post("/candidates/{$c->id}/hire", ['role_id' => $role->id]);
        $res->assertRedirect();

        $u = User::where('email', 'new.hire@test.local')->first();
        $this->assertNotNull($u, 'التعيين لم يُنشئ حساباً — فيبقى الموظف بلا وصولٍ يومَ مباشرته');
        $this->assertSame($role->id, $u->role_id);
        $this->assertNull($u->password_changed_at, 'الحساب يبدأ بكلمةٍ مؤقتة يجب تبديلها');

        $emp = Employee::where('name', 'مرشحة جديدة')->firstOrFail();
        $this->assertSame($u->id, $emp->user_id, 'الحساب لم يُربط بالملف الوظيفي');

        // كلمةٌ مؤقتة تُعرض مرةً واحدة
        $this->assertStringContainsString('كلمة المرور المؤقتة', (string) session('ok'));
    }

    /** ولا يمضي الأمر صامتاً: من يدير المستخدمين يُشعَر ليراجع الصلاحيات */
    public function test_the_user_admins_are_notified_to_review_permissions(): void
    {
        $this->seedCore();
        $c = $this->candidate();
        $role = Role::where('is_owner', false)->firstOrFail();

        $this->actingAs($this->owner)->post("/candidates/{$c->id}/hire", ['role_id' => $role->id]);

        $this->assertTrue(
            \App\Models\HubNotification::where('user_id', $this->owner->id)
                ->where('text', 'LIKE', '%راجع صلاحياته%')->exists(),
            'أُنشئ حسابٌ ولم يُنبَّه أحدٌ لمراجعة صلاحياته'
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('audits')
                ->where('action', 'إنشاء حساب لموظف جديد')->exists(),
            'إنشاء حسابٍ بلا أثرِ تدقيق'
        );
    }

    /** حارس التصعيد يسري هنا كما في شاشة المستخدمين */
    public function test_a_non_owner_cannot_hand_out_the_owner_role_while_hiring(): void
    {
        $this->seedCore();
        $c = $this->candidate();
        $ownerRole = Role::where('is_owner', true)->firstOrFail();

        $admin = User::create(['name' => 'إداري', 'email' => 'ua@test.local', 'password' => 'Secret!2026x',
            'role_id' => Role::create(['name' => 'إداري توظيف', 'scope' => 'all',
                'flags' => ['users' => 1],
                'matrix' => ['recruit' => ['v' => 1, 'e' => 1], 'hr' => ['v' => 1, 'a' => 1]]])->id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($admin)->post("/candidates/{$c->id}/hire", ['role_id' => $ownerRole->id])
            ->assertForbidden();

        $this->assertNull(User::where('email', 'new.hire@test.local')->first());
    }

    public function test_an_existing_email_is_never_overwritten(): void
    {
        $this->seedCore();
        $c = $this->candidate();
        $existing = User::create(['name' => 'حسابٌ قائم', 'email' => 'new.hire@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($this->owner)->post("/candidates/{$c->id}/hire",
            ['role_id' => Role::where('is_owner', false)->first()->id])->assertRedirect();

        $this->assertSame('حسابٌ قائم', User::find($existing->id)->name,
            'حسابٌ قائم بالبريد نفسه كاد يُستبدَل');
        $this->assertSame(1, User::where('email', 'new.hire@test.local')->count());
    }
}

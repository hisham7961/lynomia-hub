<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\TeamDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ملفات الموظفين كانت جدولاً: اسمٌ ومسمّى وقسم. فلا يُعرف من في أي قسم بلمحة،
 * ولا من ينتهي جوازه الشهر القادم، ولا من ملفُّه ناقص، ولا مهاراتُ من — وكلها
 * مسجَّلةٌ أصلاً. والصورة الشخصية لم يكن لها موضعٌ إطلاقاً.
 */
class TeamDirectoryTest extends TestCase
{
    protected function scene(): Employee
    {
        $this->seedCore();

        $e = Employee::create(['name' => 'مهندسة أولى', 'title' => 'مهندسة برمجيات',
            'dept' => 'التقنية', 'status' => 'نشط', 'salary' => 1200,
            'user_id' => $this->employee->id,
            'iqama_exp' => now()->addDays(20)->toDateString()]);

        DB::table('skills')->insert(['id' => (string) Str::uuid(), 'emp_id' => $e->id,
            'name' => 'Laravel', 'level' => 'متقدم', 'cert' => 'شهادة PHP',
            'cert_exp' => now()->addDays(30)->toDateString(),
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Employee::create(['name' => 'محاسب', 'title' => 'محاسب', 'dept' => 'المالية', 'status' => 'نشط']);
        Cache::flush();

        return $e;
    }

    public function test_cards_are_grouped_by_department_with_skills_and_docs(): void
    {
        $e = $this->scene();
        $this->actingAs($this->owner);
        $d = TeamDirectory::all(true);

        $this->assertArrayHasKey('التقنية', $d['depts']);
        $this->assertArrayHasKey('المالية', $d['depts']);

        $card = collect($d['depts']['التقنية'])->firstWhere('id', $e->id);
        $this->assertSame(1, $card['skillsN'], 'المهارات لا تصل بطاقة الموظف');
        $this->assertSame(1, $card['certsN'], 'الشهادات لا تُحصى');
        $this->assertTrue($card['alert'], 'إقامةٌ تنتهي بعد عشرين يوماً ولا تُوسَم');
    }

    /** الراتب لا يظهر لمن حجبه دورُه — الحقلُ نفسه لا الشاشة */
    public function test_salary_respects_field_permissions(): void
    {
        $e = $this->scene();

        $this->actingAs($this->owner);
        $this->assertSame(1200.0, TeamDirectory::all(true)['depts']['التقنية'][0]['salary']);

        $role = Role::create(['name' => 'بلا رواتب', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1]], 'field_rules' => ['hr' => ['salary' => 'hide']]]);
        $u = User::create(['name' => 'مقيّد', 'email' => 'nosal@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u);
        Cache::flush();
        $this->assertNull(TeamDirectory::all(true)['depts']['التقنية'][0]['salary'],
            'راتبٌ محجوبٌ بدور القارئ ظهر في الدليل');
    }

    public function test_alerts_name_the_expiring_and_the_accountless(): void
    {
        $this->scene();
        $this->actingAs($this->owner);
        $a = collect(TeamDirectory::all(true)['alerts']);

        $this->assertNotNull($a->firstWhere('title', 'إقامة تنتهي قريباً'));
        $this->assertNotNull($a->firstWhere('title', 'شهادة تنتهي'));
        $this->assertNotNull($a->firstWhere('title', 'موظفٌ بلا حساب'),
            'موظفٌ نشطٌ بلا حساب — إمّا يعمل بحساب غيره أو لا يستعمل النظام');
    }

    public function test_the_screen_renders_cards_and_the_table_links_to_it(): void
    {
        $this->scene();

        $this->actingAs($this->owner)->get('/team')->assertOk()
            ->assertSee('دليل الفريق')->assertSee('التقنية')->assertSee('مهندسة أولى');

        $this->actingAs($this->owner)->get('/m/hr')->assertOk()->assertSee('افتح الدليل');
    }

    public function test_the_directory_needs_permission_on_hr(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'بلا موظفين', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'ضيّق', 'email' => 'nohr@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/team')->assertForbidden();
    }
}

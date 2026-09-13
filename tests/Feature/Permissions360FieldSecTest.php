<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **بوّابةُ الحقولِ الحسّاسة** (Permissions 360 · 09.2 · 07.2 · النمطُ الآمن).
 *
 * إثباتٌ يفشل أولاً: قبلَ الدفعةِ كان الراتبُ والهويّاتُ وماليّةُ المشروعِ مرئيّةً لكلِّ
 * من يرى الوحدة؛ الآن خلفَ مفتاحِ `fieldsec` (والهجرةُ منحته لكلِّ دورٍ كان يرى —
 * فلا فقدَ فوريّاً، وقواعدُ الحقولِ تبقى تعمل فوقَه لحامليه).
 */
class Permissions360FieldSecTest extends TestCase
{
    private function user(string $email, array $matrix, array $fieldRules = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'field_rules' => $fieldRules ?: null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ البوّابة: بلا مفتاحٍ تُحجب، وبه تُرى ═══════════ */

    public function test_salary_hidden_without_fieldsec_and_visible_with_it(): void
    {
        $this->seedCore();

        // يرى الموارد لكن بلا مفتاحِ الحقولِ الحسّاسة ⇒ الراتبُ والهويّةُ محجوبان
        $viewer = $this->user('fsv@test.local', ['hr' => ['v' => 1]]);
        $this->assertSame('hide', hub_field_mode($viewer, 'hr', 'salary'), 'الراتبُ محجوبٌ بلا fieldsec');
        $this->assertSame('hide', hub_field_mode($viewer, 'hr', 'civilId'), 'الرقمُ المدنيُّ محجوب');
        $this->assertSame('hide', hub_field_mode($viewer, 'hr', 'passport'), 'الجوازُ محجوب');

        // حاملُ المفتاح ⇒ يرى
        $granted = $this->user('fsg@test.local', ['hr' => ['v' => 1, 'fieldsec' => 1]]);
        $this->assertSame('', hub_field_mode($granted, 'hr', 'salary'), 'حاملُ fieldsec يرى الراتب');

        // الحقولُ غيرُ الحسّاسة لا تتأثّر
        $this->assertSame('', hub_field_mode($viewer, 'hr', 'dept'), 'القسمُ غيرُ حسّاسٍ — يبقى مرئيّاً');
    }

    public function test_project_budget_and_cost_behind_fieldsec(): void
    {
        $this->seedCore();
        $viewer = $this->user('fpv@test.local', ['projects' => ['v' => 1]]);
        $this->assertSame('hide', hub_field_mode($viewer, 'projects', 'budget'), 'الميزانيّةُ محجوبة');
        $this->assertSame('hide', hub_field_mode($viewer, 'projects', 'cost'), 'التكلفةُ محجوبة');

        // 07.4 (v2.495.0) — fieldsec يمنح **الرؤية**، والكتابةُ صارت لمجموعةِ projFin:
        // بلا مفتاحِها الحقلُ مرئيٌّ قراءةً فقط (ro لا hide)، وبها يعود قابلاً للكتابة
        $granted = $this->user('fpg@test.local', ['projects' => ['v' => 1, 'fieldsec' => 1]]);
        $this->assertSame('ro', hub_field_mode($granted, 'projects', 'budget'), 'حاملُ fieldsec يرى الميزانيّةَ (قراءةً فقط بلا projFin)');
        $full = $this->user('fpg2@test.local', ['projects' => ['v' => 1, 'fieldsec' => 1, 'projFin' => 1]]);
        $this->assertSame('', hub_field_mode($full, 'projects', 'budget'), 'وبمفتاحِ projFin تعود الكتابة');
    }

    /* ═══════════ الأسبقيّات: المالكُ يرى، وقواعدُ الدورِ تعمل فوقَ المفتاح ═══════════ */

    public function test_owner_sees_and_role_rules_still_apply_over_the_grant(): void
    {
        $this->seedCore();
        $this->assertSame('', hub_field_mode($this->owner, 'hr', 'salary'), 'المالكُ يرى دوماً');

        // حاملُ المفتاحِ مع قاعدةِ ro ⇒ قراءةٌ فقط (القواعدُ أدقُّ من المفتاح)
        $ro = $this->user('fro@test.local', ['hr' => ['v' => 1, 'fieldsec' => 1]],
            ['hr' => ['salary' => 'ro']]);
        $this->assertSame('ro', hub_field_mode($ro, 'hr', 'salary'), 'قاعدةُ ro تبقى فوقَ المفتاح');

        // ودورٌ يُخفي الراتبَ صراحةً يبقى مُخفياً ولو حملَ المفتاح
        $hide = $this->user('fhd@test.local', ['hr' => ['v' => 1, 'fieldsec' => 1]],
            ['hr' => ['salary' => 'hide']]);
        $this->assertSame('hide', hub_field_mode($hide, 'hr', 'salary'), 'قاعدةُ hide تبقى');
    }

    /* ═══════════ السطحُ الحيّ: صفحةُ الموظّفِ لا تُظهر الراتبَ لغيرِ الحامل ═══════════ */

    public function test_employee_page_hides_salary_value_without_fieldsec(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظّفُ سرّية', 'status' => 'نشط', 'salary' => 737373]);

        $viewer = $this->user('fpage@test.local', ['hr' => ['v' => 1]]);
        $this->actingAs($viewer)->get(route('m.show', ['hr', $emp->id]))
            ->assertOk()->assertDontSee('737373');

        $granted = $this->user('fpage2@test.local', ['hr' => ['v' => 1, 'fieldsec' => 1]]);
        $this->actingAs($granted)->get(route('m.show', ['hr', $emp->id]))
            ->assertOk()->assertSee('737');
    }

    /* ═══════════ الهجرةُ عديمةُ الخسارة: من كان يرى مُنِح ═══════════ */

    public function test_migration_grants_fieldsec_to_existing_viewer_roles(): void
    {
        $this->seedCore();
        // دورٌ قائمٌ «قبل الترقية»: يرى الموارد بلا fieldsec (كما كانت الأدوارُ يومَها)
        $role = Role::create(['name' => 'دورٌ قديم', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'e' => 1], 'projects' => ['v' => 1], 'clients' => ['v' => 1]]]);

        // تُعاد الهجرةُ عليه (idempotent) — فيُمنَح على وحدتَيه الحسّاستَين دون سواهما
        $mig = include database_path('migrations/2026_09_28_000001_grant_fieldsec_to_existing_roles.php');
        $mig->up();

        $mx = Role::find($role->id)->matrix;
        $this->assertSame(1, $mx['hr']['fieldsec'] ?? null, 'دورُ الموارد القديمُ مُنح المفتاح');
        $this->assertSame(1, $mx['projects']['fieldsec'] ?? null, 'ودورُ المشاريع كذلك');
        $this->assertArrayNotHasKey('fieldsec', $mx['clients'] ?? [], 'العملاءُ ليست ذاتَ حقولٍ حسّاسة');
    }
}

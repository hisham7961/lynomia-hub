<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Employee;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **ميزانيةُ استعلامات شاشتَي الطور ٧** (خطّة §٠.٢ الخطوة ٥، على نمط
 * `ScreenPerformanceTest`) — لا لأنّ رقماً سحرياً مطلوب، بل لأنّ العيبَ الذي
 * أُصلح في WP-7.3 **صنفٌ يعود بصمت**: قارئٌ يستعلم صفّاً لكل موظفٍ يبدو سليماً
 * على ثلاثة حسابات ويُسقط الشاشةَ على ثلاثمئة. فالحكمُ هنا حكمان:
 *
 *  ١) **سقفٌ للنظرة الشاملة** بعد إحماء الخبيئة (صحةُ المشاريع والقدرات
 *     وإشاراتُ مركز الفعل تُخبَّأ) — الشاشةُ ثمانيةُ عدّاداتٍ ولوحُ أقسامٍ
 *     وميزانُ حملٍ وستُّ قراءاتِ اختناق، وكلُّها تجميعاتٌ في القاعدة.
 *  ٢) **ثباتُ بطاقة الموظف**: عددُ استعلاماتها لا ينمو بعدد حسابات النظام —
 *     `ExecutionStats::person` قشرةٌ على القراءة الجَماعية، فالحسابُ الواحد
 *     كلفتُه كلفتُه سواءٌ أكان في النظام ثلاثةٌ أم ثلاثةٌ وثلاثون.
 */
class Phase7BudgetTest extends TestCase
{
    /** سقفُ النظرة الشاملة بعد الإحماء — أوسعُ من المقيس بهامشٍ يكشف عودةَ الصفّ-لكل-سجل */
    private const OVERVIEW_BUDGET = 45;

    /** @return array{0:mixed,1:int} النتيجةُ وعددُ الاستعلامات */
    protected function counted(\Closure $fn): array
    {
        $count = false;
        $n = 0;
        DB::listen(function () use (&$n, &$count) { if ($count) $n++; });
        $count = true;
        $out = $fn();
        $count = false;

        return [$out, $n];
    }

    /** حسابٌ وملفُّ موظفٍ ومهمّةٌ وتذكرةٌ واعتماد — وحدةُ بذرٍ تُكرَّر */
    protected function seedPerson(int $i): User
    {
        $u = User::create(['name' => 'ع' . $i, 'email' => "budget$i@test.local",
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        Employee::create(['name' => 'ع' . $i, 'user_id' => $u->id,
            'dept' => 'قسم ' . ($i % 3), 'status' => 'نشط']);
        Task::create(['title' => 'مفتوحة ' . $i, 'assignee_id' => $u->id,
            'status' => 'قيد التنفيذ', 'due' => now()->subDay()->toDateString()]);
        Task::create(['title' => 'منجزة ' . $i, 'assignee_id' => $u->id, 'status' => 'منجزة',
            'due' => now()->toDateString(), 'completed_at' => now()->subHours(3)]);
        Ticket::create(['subject' => 'تذكرة ' . $i, 'status' => 'قيد المعالجة',
            'assignee_id' => $u->id, 'created_at' => now()->subDay()]);
        Approval::create(['title' => 'اعتماد ' . $i, 'type' => 'شراء', 'approver_id' => $u->id]);
        DB::table('sessions_log')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id,
            'started_at' => now()->subHour(), 'last_seen_at' => now()]);

        return $u;
    }

    /* ── ١) النظرةُ الشاملة: سقفٌ بعد الإحماء ── */

    public function test_workforce_overview_stays_inside_its_warm_query_budget(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 10; $i++) $this->seedPerson($i);

        $this->actingAs($this->owner);
        $this->get('/workforce/overview')->assertOk();      // إحماءُ الخبيئة

        [$res, $n] = $this->counted(fn () => $this->get('/workforce/overview'));
        $res->assertOk();

        $this->assertLessThanOrEqual(self::OVERVIEW_BUDGET, $n,
            'نظرةُ القوى العاملة تجاوزت ميزانيتَها: ' . $n . ' استعلاماً');
    }

    /** والكلفةُ لا تنمو بعدد الموظفين — هذا هو الحكمُ لا الرقمُ وحده */
    public function test_workforce_overview_cost_does_not_grow_with_headcount(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 3; $i++) $this->seedPerson($i);

        $this->actingAs($this->owner);
        $this->get('/workforce/overview')->assertOk();
        [, $few] = $this->counted(fn () => $this->get('/workforce/overview')->assertOk());

        for ($i = 3; $i < 21; $i++) $this->seedPerson($i);
        $this->get('/workforce/overview')->assertOk();
        [, $many] = $this->counted(fn () => $this->get('/workforce/overview')->assertOk());

        // ستةَ عشرَ موظفاً إضافياً: بالقراءة صفّاً لكل موظفٍ كان الفرقُ بالعشرات
        $this->assertLessThanOrEqual($few + 4, $many,
            'كلفةُ النظرة نمت بعدد الموظفين: ' . $few . ' ← ' . $many);
    }

    /* ── ٢) بطاقةُ الموظف: ثابتةٌ مع عدد الحسابات ── */

    public function test_employee_work_profile_is_flat_with_user_count(): void
    {
        $this->seedCore();
        $target = $this->seedPerson(0);
        $emp = Employee::where('user_id', $target->id)->firstOrFail();

        $this->actingAs($this->owner);
        $url = route('portal.employee', $emp->id);
        $this->get($url)->assertOk();                       // إحماء

        [, $few] = $this->counted(fn () => $this->get($url)->assertOk());

        // ثلاثون حساباً إضافياً بمهامّها وتذاكرها — البطاقةُ لواحدٍ منها
        for ($i = 1; $i < 31; $i++) $this->seedPerson($i);
        $this->get($url)->assertOk();
        [, $many] = $this->counted(fn () => $this->get($url)->assertOk());

        $this->assertLessThanOrEqual($few + 2, $many,
            'بطاقةُ الموظف نمت بعدد حسابات النظام: ' . $few . ' ← ' . $many);
    }
}

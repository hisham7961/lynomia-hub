<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * مجلسُ الخبراء · N-13 — **«أهذا الموظّفُ على رأسِ عملِه الآن؟» بتعريفَين**.
 *
 * بطاقةُ الموظّفِ تسأل `openRow()` فتعرض «انصراف»، وشاشةُ مديرِه تسأل يومَها
 * وحدَه فتقول «لم يسجّل بعد» وتضعه في سلّةِ الغياب — **في الدقيقةِ نفسِها**.
 * وقبل v2.514.0 كان الجوابان متّفقَين وخاطئَين؛ الإصلاحُ أصلح أحدَهما فكشف
 * التناقض. والجوابُ الآن واحدٌ للجماعةِ كما هو للفرد: `openCrossingByEmp()`.
 */
class CouncilManagerNightShiftTest extends TestCase
{
    private function member(string $email): array
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['attend' => ['v' => 1, 'a' => 1, 'e' => 1]], 'companies' => null]);
        $u = User::create(['name' => 'موظّف', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'موظّفُ ' . $email, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_roll_call_counts_an_open_crossing_shift_as_present_not_absent(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('mgr-night@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        Carbon::setTestNow(Carbon::parse('2026-09-15 09:30:00'));

        // أوّلاً: الجوابُ الجماعيُّ نفسُه يرى الورديّة
        $this->assertArrayHasKey((string) $e->id, Workday::openCrossingByEmp([$e->id]),
            'الجوابُ الجماعيُّ لا يرى الورديّةَ المفتوحة أصلاً');

        $roll = DailyWorkCompliance::rollCall(Employee::whereKey($e->id)->get(), '2026-09-15');

        $b = $roll['buckets'];
        $this->assertCount(0, $b['absent'],
            'نداءُ اليومِ يعدّه غائباً وبطاقتُه تعرض «انصراف» — سؤالٌ واحدٌ بتعريفَين');
        $this->assertCount(0, $b['not_yet']);
        $this->assertCount(1, $b['present'], 'الموظّفُ على رأسِ عملِه فليُعدّ حاضراً');

        // وبطاقتُه تقول الشيءَ نفسَه في اللحظةِ نفسِها
        $this->assertNotNull(Workday::mine($u)['att'] ?? null);
    }

    public function test_a_genuinely_absent_employee_is_still_absent(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('mgr-absent@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 09:30:00'));
        $roll = DailyWorkCompliance::rollCall(Employee::whereKey($e->id)->get(), '2026-09-15');

        $b = $roll['buckets'];
        $this->assertCount(0, $b['present'], 'غائبٌ حقيقيٌّ عُدّ حاضراً — نزعُ قدرةٍ من نداءِ اليوم');
        $this->assertSame(1, count($b['absent']) + count($b['not_yet']),
            'الغائبُ الحقيقيُّ اختفى من النداء');
    }

    public function test_a_forgotten_yesterday_row_is_not_treated_as_an_open_shift(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('mgr-forgot@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        // ١٧:٠٠ من الغد: خارجَ سقفِ الورديّة ⇒ ليس «على رأسِ عملِه»
        Carbon::setTestNow(Carbon::parse('2026-09-15 17:00:00'));
        $this->assertSame([], Workday::openCrossingByEmp([$e->id]));
    }

    public function test_the_manager_screen_shows_the_open_shift_instead_of_not_checked_in(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('mgr-screen@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        Carbon::setTestNow(Carbon::parse('2026-09-15 09:30:00'));
        $html = $this->actingAs($this->owner)->get(route('workforce.team'))->assertOk()->getContent();

        $this->assertStringContainsString('على رأس العمل منذ 23:00:00 (أمس)', $html,
            'شاشةُ المدير لا تعرض الورديّةَ المفتوحة');
        $this->assertStringNotContainsString('لم يسجّل بعد', $html,
            'ما زالت تقول «لم يسجّل بعد» عن موظّفٍ على رأسِ عملِه');
    }
}

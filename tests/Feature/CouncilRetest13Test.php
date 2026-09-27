<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Workforce\DailyWorkCompliance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التحقّقُ المستقلّ الثالث عشر — **الشاشةُ الواحدةُ تجيب مرّتَين**.
 *
 * هذا هو البلاغُ ع‑٦ من التحقّقِ الثاني عشر، غيرُ المُغلَقِ بعد: مَن ورديّتُه
 * مفتوحةٌ منذ أمسِ الثانيةِ والعشرين يظهر في **صفحةِ «فريقي اليوم» نفسِها**:
 *   · في «نداء اليوم» ضمن **حاضر** (أُصلح في v2.516)،
 *   · وفي جدولِ «اليوم موظفاً موظفاً» تحت «الحالة المحتسَبة»: **غائب**.
 *
 * وهو نصُّ الاكتشافِ الحاكمِ لهذا المجلس: «سؤالٌ واحدٌ · تعريفان».
 */
class CouncilRetest13Test extends TestCase
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

    /** ورديّةٌ بدأت أمسِ ٢٢:٠٠ وما تزال مفتوحة — ولا صفَّ اليوم إطلاقاً. */
    private function openNightShift(string $empId, string $yesterday): void
    {
        DB::table('attendance')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $empId, 'date' => $yesterday,
            'time_in' => '22:00:00', 'time_out' => null, 'overnight' => false, 'status' => 'حاضر',
            'in_at' => $yesterday . ' 22:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_screen_does_not_call_him_present_and_absent_at_once(): void
    {
        $this->seedCore();
        [, $e] = $this->member('night13@test.local');

        // الثالثةُ فجراً — الورديّةُ في ساعتِها الخامسة
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', config('app.timezone')));
        $this->openNightShift((string) $e->id, '2026-09-14');

        $today = '2026-09-15';
        $c = DailyWorkCompliance::resolve($e, $today);

        $this->assertNotNull($c['open_shift'], 'الشاشةُ تعرض «🌙 على رأس العمل»');

        $roll = DailyWorkCompliance::rollCall(collect([$e]), $today);
        $this->assertContains($e->name, collect($roll['buckets']['present'])->pluck('name')->all(),
            'النداءُ يعدّه حاضراً');

        $this->assertNotSame('absent', $c['effective'],
            'الصفحةُ الواحدةُ لا تقول «حاضر» في نداءِها و«غائب» في جدولِها للشخصِ نفسِه');
        $this->assertSame('present', $c['effective']);
        $this->assertSame('حاضر', $c['labels']['effective']);
        $this->assertFalse($c['verdict_pending'],
            'مَن على رأسِ عملِه حكمُه واقعٌ لا مؤجَّل');

        // و`state` عقدٌ منشورٌ لا يُقلَب — يومُه لم يبدأ فعلاً
        $this->assertSame('absent', $c['state'],
            '`state` جوابُ «ماذا وقع اليوم» ويبقى كما هو (N-19)');
    }

    /** الفجرُ للجميع: العيبُ ليس في الورديّةِ الليليّةِ وحدَها. */
    public function test_an_ordinary_employee_is_not_judged_before_the_workday_starts(): void
    {
        $this->seedCore();
        [, $e] = $this->member('dawn13@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', config('app.timezone')));
        $c = DailyWorkCompliance::resolve($e, '2026-09-15');

        $this->assertTrue($c['verdict_pending'],
            'قبلَ بدءِ الدوامِ الحكمُ مؤجَّلٌ — النداءُ يقولها والجدولُ كان يخالفه');
        $this->assertTrue(DailyWorkCompliance::beforeWorkdayStart('2026-09-15'));
    }

    /** وبعدَ أن يبدأ الدوامُ يعود الحكمُ واقعاً — لا تأجيلَ دائم. */
    public function test_the_verdict_stops_being_deferred_once_the_day_has_begun(): void
    {
        $this->seedCore();
        [, $e] = $this->member('noon13@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        $c = DailyWorkCompliance::resolve($e, '2026-09-15');

        $this->assertFalse($c['verdict_pending'], 'الظهرُ ليس فجراً');
        $this->assertFalse(DailyWorkCompliance::beforeWorkdayStart('2026-09-15'));
        $this->assertSame('absent', $c['effective'], 'ومن لم يحضر حتى الظهرِ غائبٌ فعلاً');
    }

    /** واليومُ الماضي انقضى — حكمُه واقعٌ لا ينتظر ساعةَ اليوم. */
    public function test_a_past_day_is_never_deferred(): void
    {
        $this->seedCore();
        [, $e] = $this->member('past13@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', config('app.timezone')));
        $c = DailyWorkCompliance::resolve($e, '2026-09-10');

        $this->assertFalse($c['verdict_pending'], 'أمسِ انقضى فلا يُؤجَّل حكمُه');
        $this->assertFalse(DailyWorkCompliance::beforeWorkdayStart('2026-09-10'));
    }

    /** وختمُ الموارد المُدقَّق يعلو على الورديّةِ المفتوحة — لا يُطمَس صامتاً. */
    public function test_a_human_seal_outranks_the_open_shift(): void
    {
        $this->seedCore();
        [, $e] = $this->member('seal13@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', config('app.timezone')));
        $this->openNightShift((string) $e->id, '2026-09-14');

        // ختمٌ إنسانيٌّ على اليومِ الجاري: «معذور»
        DB::table('attendance')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $e->id, 'date' => '2026-09-15',
            'time_in' => null, 'time_out' => null, 'status' => 'غائب',
            'compliance_finalized_at' => '2026-09-15 02:00:00',
            'compliance_outcome' => 'excused',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $c = DailyWorkCompliance::resolve($e, '2026-09-15');
        $this->assertSame('excused', $c['effective'],
            'قرارُ الموارد لا يُعاد كتابتُه صامتاً (§41/§90)');
        $this->assertFalse($c['verdict_pending'], 'الختمُ حكمٌ قائمٌ لا مؤجَّل');
    }

    /** ومن في إجازةٍ حكمُه قائمٌ ولو في الفجر. */
    public function test_leave_is_a_standing_verdict_not_a_deferred_one(): void
    {
        $this->seedCore();
        [, $e] = $this->member('leave13@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', config('app.timezone')));
        DB::table('attendance')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $e->id, 'date' => '2026-09-15',
            'time_in' => null, 'time_out' => null, 'status' => 'إجازة',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $c = DailyWorkCompliance::resolve($e, '2026-09-15');
        $this->assertFalse($c['verdict_pending'], 'الإجازةُ حكمٌ لا انتظار');
    }

    /** ع‑٥ · صفحةُ تفصيلِ اليومِ كانت وحدَها لا تقرأ الورديّةَ المفتوحة. */
    public function test_the_day_page_reads_the_open_shift(): void
    {
        $this->seedCore();
        [, $e] = $this->member('dayview13@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', config('app.timezone')));
        $this->openNightShift((string) $e->id, '2026-09-14');

        $res = $this->actingAs($this->owner)
            ->get(route('reports.day', ['emp' => $e->id, 'date' => '2026-09-15']));

        $res->assertOk();
        $res->assertSee('على رأس العمل', false);
        $res->assertDontSee('لم يسجّل بعد', false);
    }

    /** وعميلُ الـAPI يقرأ الحقيقتَين لا يشتقّهما. */
    public function test_the_api_shape_carries_both_facts(): void
    {
        $this->seedCore();
        [, $e] = $this->member('api13@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', config('app.timezone')));
        $shape = DailyWorkCompliance::apiShape(DailyWorkCompliance::resolve($e, '2026-09-15'));

        $this->assertArrayHasKey('verdict_pending', $shape, 'مفتاحٌ موجودٌ دائماً لا يبتلعُه `??`');
        $this->assertTrue($shape['verdict_pending']);
        $this->assertArrayHasKey('open_shift', $shape);
    }
}

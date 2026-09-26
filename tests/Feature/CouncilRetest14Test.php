<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Workforce\DailyWorkCompliance;
use App\Support\Workforce\MonthlyAttendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التحقّقُ المستقلّ الرابع عشر — **كنسُ عائلةِ N-27**.
 *
 * النمطُ الذي أُغلق في v2.520.0 ليس حادثةً مفردة: قاعدةٌ يشتقّها كلُّ قارئٍ
 * لنفسِه بدل أن يسألها الكاتبَ. فبُحث عن إخوتِه — وهذا أوّلُ مرشَّح.
 *
 * «أهو متأخّر؟» له تعريفان:
 *   · `rollCall` :482          — الوسمُ المختوم **+ اشتقاقٌ من `time_in`**،
 *                                 وترويستُه تقول لماذا: «فلا يفلت صفٌّ يدويٌّ بلا وسم».
 *   · `MonthlyAttendance::accumulate` :201 — الوسمُ **وحدَه**.
 *
 * والثاني هو ما يُصدَّر في الكشفِ الشهريّ.
 */
class CouncilRetest14Test extends TestCase
{
    private function member(string $email): Employee
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['attend' => ['v' => 1, 'a' => 1, 'e' => 1]], 'companies' => null]);
        $u = User::create(['name' => 'موظّف', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        return Employee::create(['name' => 'موظّفُ ' . $email, 'status' => 'نشط', 'user_id' => $u->id]);
    }

    /**
     * صفٌّ **يدويٌّ** من نموذجِ الوحدات: المواردُ تكتب الوقتَ ولا تختار الحالة،
     * فتبقى «حاضر» بينما `time_in` بعدَ بدايةِ الدوامِ بساعتَين وربع.
     */
    public function test_the_daily_roll_call_and_the_monthly_sheet_agree_on_lateness(): void
    {
        $this->seedCore();
        $e = $this->member('late14@test.local');

        // الدوامُ ٠٨:٠٠ وسماحيةُ ١٥ دقيقة ⇒ ٠٨:١٥ حدُّ التأخّر
        \App\Support\Platform\Settings::put('sec.hours_start', '08:00', 'test');
        \App\Support\Platform\Settings::put('work.late_grace', '15', 'test');

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', config('app.timezone')));

        // الاثنين ٢٠٢٦-٠٩-١٤ — يومُ عملٍ (العطلةُ ٥,٦)
        DB::table('attendance')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => '10:30:00', 'time_out' => '17:00:00', 'hours' => 6.5,
            'status' => 'حاضر',              // ← لم تُوسَم «متأخر»: الوسمُ يدويٌّ لا آليّ
            'in_at' => '2026-09-14 10:30:00', 'out_at' => '2026-09-14 17:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $roll = DailyWorkCompliance::rollCall(collect([$e]), '2026-09-14');
        $inLateBucket = in_array($e->name,
            collect($roll['buckets']['late'])->pluck('name')->all(), true);

        $sheet = MonthlyAttendance::sheet($e, '2026-09');
        $monthlyLate = (int) $sheet['totals']['late'];


        $this->assertTrue($inLateBucket, 'النداءُ يشتقّ التأخّرَ من وقتِ الختم');
        $this->assertSame(1, $monthlyLate,
            'والكشفُ الشهريُّ — وهو ما يُصدَّر — يجب أن يقول ما يقوله النداء');
    }

    /**
     * وكنسُ المدى: الحدُّ نفسُه، وما قبلَه، وما بعدَه بدقيقة، والوسمُ المختوم،
     * والعملُ عن بُعدٍ الذي **لا يُوسَم متأخّراً** أصلاً في `Workday::checkIn`.
     */
    public static function lateCases(): array
    {
        return [
            'في الوقت ٠٨:٠٠'        => ['08:00:00', 'حاضر', false],
            'على الحدِّ ٠٨:١٥'       => ['08:15:00', 'حاضر', false],
            'بعدَ الحدِّ بدقيقة'      => ['08:16:00', 'حاضر', true],
            'متأخّرٌ موسومٌ سلفاً'   => ['09:30:00', 'متأخر', true],
            'عن بُعدٍ لا يُوسَم'      => ['10:30:00', 'عمل عن بعد', false],
            'ميدانيٌّ لا يُوسَم'      => ['10:30:00', 'عمل ميداني', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lateCases')]
    public function test_both_readers_agree_across_the_whole_range(
        string $timeIn, string $status, bool $expectLate): void
    {
        $this->seedCore();
        $e = $this->member('range14-' . md5($timeIn . $status) . '@test.local');
        \App\Support\Platform\Settings::put('sec.hours_start', '08:00', 'test');
        \App\Support\Platform\Settings::put('work.late_grace', '15', 'test');

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', config('app.timezone')));
        DB::table('attendance')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => $timeIn, 'time_out' => '17:00:00', 'hours' => 8, 'status' => $status,
            'in_at' => '2026-09-14 ' . $timeIn, 'out_at' => '2026-09-14 17:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $roll = DailyWorkCompliance::rollCall(collect([$e]), '2026-09-14');
        $inLate = in_array($e->name, collect($roll['buckets']['late'])->pluck('name')->all(), true);
        $monthly = (int) MonthlyAttendance::sheet($e, '2026-09')['totals']['late'];

        $this->assertSame($expectLate, $inLate, 'النداءُ اليوميّ');
        $this->assertSame($expectLate ? 1 : 0, $monthly, 'الكشفُ الشهريّ — الجوابُ نفسُه');
    }

    /**
     * N-29 · نغمةُ الامتثال: كانت «فريقي اليوم» تطلي ذراعَ `default` حمراءَ
     * و«مركز التقارير» تتركها محايدة — والقيمةُ `reported` تسميتُها **مُقدَّم**.
     */
    public function test_the_two_screens_paint_the_same_datum_the_same_colour(): void
    {
        $this->seedCore();
        $e = $this->member('tone14@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', config('app.timezone')));
        $c = DailyWorkCompliance::resolve($e, '2026-09-14');   // لا حضورَ ولا تقرير ⇒ compliance='none'

        $this->assertSame('none', $c['compliance'], 'الحالةُ المقصودة');
        $this->assertArrayHasKey('tones', $c, 'الكاتبُ يجيب بالنغمة');

        // نغمةٌ واحدةٌ يقرؤها الجميع — لا ذراعَ `default` تملؤها كلُّ شاشةٍ بهواها
        $html = $this->actingAs($this->owner)
            ->get(route('reports.index', ['date' => '2026-09-14']))->assertOk()->getContent();
        $team = $this->actingAs($this->owner)
            ->get(route('workforce.team', ['date' => '2026-09-14']))->assertOk()->getContent();

        foreach ([['مركز التقارير', $html], ['فريقي اليوم', $team]] as [$name, $page]) {
            $this->assertStringContainsString('غيرُ مقدَّم', $page, $name . ': النصُّ معروض');
            $this->assertStringNotContainsString('<span class="bdg bad">غيرُ مقدَّم</span>', $page,
                $name . ': «غيرُ مقدَّم» لا تُنذَر حمراءَ — الغيابُ يرويه عمودُ الحالة');
        }

        // والقيمةُ التي تسميتُها «مُقدَّم» ليست حمراءَ في أيٍّ منهما
        $this->assertSame('', $c['tones']['compliance']);
    }
}

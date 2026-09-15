<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * التحقّقُ الخامس عشر — **إغلاقُ N-20 · N-21 · N-22**.
 *
 * ثلاثةُ بنودٍ بقيت مفتوحةً بقرارٍ مكتوبٍ لا سهواً، وكلٌّ منها من عائلةٍ مختلفة:
 * أداءٌ يخالف ترويستَه، وحقلٌ يكتبه بابٌ من أربعة، وشاشةٌ نصفُها مخبوءٌ ونصفُها حيّ.
 */
class CouncilRetest15Test extends TestCase
{
    private function member(string $email): Employee
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['attend' => ['v' => 1, 'a' => 1, 'e' => 1], 'hr' => ['v' => 1]],
            'companies' => null]);
        $u = User::create(['name' => 'موظّف', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        return Employee::create(['name' => 'موظّفُ ' . $email, 'status' => 'نشط', 'user_id' => $u->id]);
    }

    /** N-20 · الترويسةُ تقول «استعلامان»، فكم هي فعلاً؟ */
    public function test_resolve_many_costs_a_bounded_number_of_queries(): void
    {
        $this->seedCore();
        $emps = collect();
        for ($i = 0; $i < 10; $i++) $emps->push($this->member("perf20-$i@test.local"));

        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', config('app.timezone')));

        DB::flushQueryLog();
        DB::enableQueryLog();
        DailyWorkCompliance::resolveMany($emps, '2026-09-14');
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        // مقيَّدٌ بالمجموعة لا بعددِ الموظّفين (كان ٤٤ قبل الإصلاح)
        $this->assertLessThanOrEqual(10, $n,
            'العددُ يجب أن يكون مقيَّداً بالمجموعةِ لا متزايداً بعددِ الموظّفين');
    }

    /** N-20 · والبرهانُ الحقيقيّ: العددُ **لا يتغيّر** بتضاعفِ الموظّفين. */
    public function test_the_query_count_does_not_grow_with_the_number_of_employees(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', config('app.timezone')));

        $count = function (int $k): int {
            $emps = collect();
            for ($i = 0; $i < $k; $i++) $emps->push($this->member("scale20-$k-$i@test.local"));
            DB::flushQueryLog(); DB::enableQueryLog();
            DailyWorkCompliance::resolveMany($emps, '2026-09-14');
            $n = count(DB::getQueryLog()); DB::disableQueryLog();
            return $n;
        };

        $five = $count(5);
        $twenty = $count(20);

        // أربعةُ أضعافِ الموظّفين بكلفةٍ ثابتة — لا تناسبيّة. (قبلَ الإصلاح: ٢٤ ⇒ ٨٣)
        $this->assertLessThanOrEqual($five + 2, $twenty,
            'أربعةُ أضعافِ الموظّفين يجب ألّا تزيد الكلفةَ إلّا ثابتاً — وإلّا فهو N+1 مقنَّع');
    }

    /** N-21 · صفٌّ أدخلته المواردُ من نموذجِ الوحدات — لا من زرِّ الانصراف. */
    public function test_every_door_stamps_the_report_deadline_not_only_check_out(): void
    {
        $this->seedCore();
        $e = $this->member('door21@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 18:00:00', config('app.timezone')));

        // البابُ الثاني: كتابةٌ مباشرةٌ عبر النموذج (نموذجُ الوحدات · الاستيراد · API)
        $row = Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => '08:00', 'time_out' => '17:00', 'status' => 'حاضر']);

        $this->assertNotNull($row->fresh()->report_deadline_at,
            'أمرُ المصالحةِ يرشِّح بـ`whereNotNull(report_deadline_at)` — فبلا ختمٍ لا يراه أبداً');
    }

    /** N-21 · وزرُّ الانصرافِ يبقى كما كان — لا انحدارَ في البابِ الذي كان يعمل. */
    public function test_check_out_still_stamps_the_same_deadline(): void
    {
        $this->seedCore();
        $e = $this->member('door21b@test.local');
        $u = User::find($e->user_id);

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00', config('app.timezone')));
        Workday::checkIn($u, ['mode' => 'مكتب']);
        Carbon::setTestNow(Carbon::parse('2026-09-14 17:00:00', config('app.timezone')));
        $res = Workday::checkOut($u);

        $this->assertTrue($res['ok']);
        $this->assertNotNull($res['row']->fresh()->report_deadline_at, 'البابُ الأوّلُ لم ينكسر');
    }

    /** N-22 · نصفُ الشاشةِ مخبوءٌ ونصفُها حيّ — فمن لقطةٍ واحدةٍ الآن. */
    public function test_the_team_screen_comes_from_one_snapshot(): void
    {
        $this->seedCore();
        $this->member('snap22@test.local');
        $this->actingAs($this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', config('app.timezone')));
        $payload = Workday::teamToday();

        $this->assertArrayHasKey('roll', $payload,
            'النداءُ من الحِزمةِ المخبوءةِ نفسِها — لا حيّاً بجوارِ جدولٍ عمرُه ١٢٠ ثانية');
        $this->assertArrayHasKey('rows', $payload);
        $this->assertSame($payload['n']['emps'], count($payload['roll']['buckets']['present'])
            + count($payload['roll']['buckets']['late']) + count($payload['roll']['buckets']['leave'])
            + count($payload['roll']['buckets']['excused']) + count($payload['roll']['buckets']['absent'])
            + count($payload['roll']['buckets']['pending']) + count($payload['roll']['buckets']['not_yet']),
            'المجموعُ لا يتجاوز عددَ الموظّفين ولا يقصر عنه — لقطةٌ واحدة');
    }

    /** وحارسٌ دائم: **لا شكلَ استعلامٍ** يتكرّر بعددِ الموظّفين. */
    public function test_no_query_shape_repeats_per_employee(): void
    {
        $this->seedCore();
        $emps = collect();
        for ($i = 0; $i < 20; $i++) $emps->push($this->member("shape-$i@test.local"));
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', config('app.timezone')));

        DB::flushQueryLog(); DB::enableQueryLog();
        DailyWorkCompliance::resolveMany($emps, '2026-09-14');
        $log = DB::getQueryLog(); DB::disableQueryLog();

        $tally = [];
        foreach ($log as $q) {
            $sql = preg_replace('/\s+/', ' ', $q['query']);
            $sql = preg_replace("/'[^']*'/", '?', $sql);
            $sql = preg_replace('/\(\?(, ?\?)+\)/', '(...)', $sql);
            $key = substr($sql, 0, 110);
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        foreach ($tally as $sql => $n) {
            $this->assertLessThanOrEqual(3, $n,
                "شكلُ استعلامٍ تكرّر {$n} مرّةً لعشرين موظّفاً — رائحةُ N+1: {$sql}");
        }
    }
}

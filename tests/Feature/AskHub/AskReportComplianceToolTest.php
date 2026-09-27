<?php

namespace Tests\Feature\AskHub;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Ai\Ask\AskTools;
use App\Support\Workforce\Workday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **«كم تقريراً لم يُسلَّم أمس؟» سؤالٌ عن غائبٍ لا عن موجود** (بلاغ المالك).
 *
 * أدواتُ القراءة تعدّ ما هو موجود (`hub_count` على التقارير)، و«لم يُسلَّم» حقيقةٌ عن موظّفٍ كان
 * عليه تقريرٌ ولم يكتبه — لا صفَّ لها أصلاً. فالأداةُ تقرأ **المُحلِّلَ المركزيَّ نفسَه**
 * (`DailyWorkCompliance`) الذي تبني عليه شاشةُ التقارير وتقريرُ الفريق على الجوال، بنطاقِ `hr`.
 */
class AskReportComplianceToolTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function staff(string $name): array
    {
        $role = Role::firstOrCreate(['name' => 'موظّفٌ منفّذ'],
            ['scope' => 'all', 'flags' => [], 'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(10) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    public function test_counts_missing_reports_for_yesterday_from_the_central_resolver(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '0');
        $this->hubSetting('work.report_cutoff_time', '');
        $day = '2026-09-07';
        [$ua, $ea] = $this->staff('سلمى');
        [, $eb] = $this->staff('خالد');
        foreach ([$ea, $eb] as $e) {
            Attendance::create(['emp_id' => $e->id, 'date' => $day, 'time_in' => '08:00', 'time_out' => '16:00',
                'status' => Workday::PRESENT, 'hours' => 8]);
        }
        $this->actingAs($ua);
        WorkUpdate::create(['done' => 'أنجزتُ عملَ اليوم كاملاً', 'hours' => 8, 'work_date' => $day]);

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', config('app.timezone')));
        $r = AskTools::run('hub_report_compliance', ['date' => 'yesterday'], $this->owner);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame('hr', $r['module']);
        $summary = $r['rows'][0];
        $this->assertSame($day, $summary['date']);
        $this->assertSame(1, $summary['submitted']);
        $this->assertSame(1, $summary['missing']);
        $missing = array_values(array_filter($r['rows'], fn ($x) => ($x['status'] ?? null) === 'لم يسلّم'));
        $this->assertSame([(string) $eb->id], array_column($missing, 'id'), 'مَن لم يسلّم يُسمّى بمعرّفه — لا يُخمَّن');
    }

    public function test_tool_is_offered_and_runs_only_for_hr_viewers(): void
    {
        $this->seedCore();
        [$u] = $this->staff('بلا موارد بشرية');
        $role = $u->role;
        $role->flags = [...(array) $role->flags, \App\Support\Ai\Ask\AskPolicy::FLAG => 1];
        $role->save();
        $u->refresh();

        $names = fn (array $defs) => array_map(fn ($d) => $d['function']['name'], $defs);
        $this->assertNotContains('hub_report_compliance', $names(AskTools::schema(AskTools::catalog($u))));
        $this->assertContains('hub_report_compliance', $names(AskTools::schema(AskTools::catalog($this->owner))));
        $this->assertFalse(AskTools::run('hub_report_compliance', [], $u)['ok'], 'مَن لا يرى الموارد البشرية لا يقرأ الامتثال');
    }
}

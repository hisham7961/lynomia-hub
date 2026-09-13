<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Workday;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **وقتُ الحضورِ والانصرافِ هو لحظةُ الضغطِ الفعليّةُ بالثانية** (طلبُ المالك).
 *
 * إثباتٌ يفشل أولاً: قبلَ الإصلاح كان الوقتُ يُقَصُّ للدقيقة (`H:i`) — ضغطةُ
 * 08:37:42 تُسجَّل 08:37، ولا أثرَ للّحظةِ الكاملة. الآن:
 *   · `time_in`/`time_out` بالثانية — ما ضغطه الموظّفُ هو ما يُسجَّل حرفيّاً.
 *   · ختمٌ كاملٌ (تاريخ/وقت/منطقة زمنيّة) في `meta.checkin.at`/`meta.checkout.at`
 *     يبقى أثراً حتى لو عُدِّل الحقلُ لاحقاً من نموذجِ الموارد.
 *   · الساعاتُ تُحسَب على اللحظتَين الفعليّتَين لا على دقائقَ مقصوصة.
 */
class WorkdayActualTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();   // لا تجميدَ يتسرّب لبقيّة الحزمة
        parent::tearDown();
    }

    private function employeeUser(string $email): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1]]]);
        $u = User::create(['name' => 'موظّف', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        Employee::create(['name' => 'موظّفُ الوقت', 'status' => 'نشط', 'user_id' => $u->id]);

        return $u;
    }

    public function test_checkin_records_the_exact_press_moment_to_the_second(): void
    {
        $this->seedCore();
        $u = $this->employeeUser('rt1@test.local');

        // لحظةُ الضغط: 08:37:42 بتوقيتِ التطبيق — بثوانٍ عمداً لكشفِ أيِّ قصّ
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:37:42', config('app.timezone')));
        $res = Workday::checkIn($u, ['mode' => 'مكتب']);

        $this->assertTrue($res['ok'], 'الحضورُ سُجّل');
        $row = Attendance::where('emp_id', $res['row']->emp_id)->first();
        $this->assertSame('08:37:42', $row->time_in, 'لحظةُ الضغطِ حرفيّاً — لا قصَّ للدقيقة');
        $this->assertSame(Carbon::parse('2026-09-14 08:37:42', config('app.timezone'))->toIso8601String(),
            $row->meta['checkin']['at'] ?? null, 'ختمُ الحقيقةِ الكاملُ في meta');
    }

    public function test_checkout_records_exact_moment_and_hours_from_real_instants(): void
    {
        $this->seedCore();
        $u = $this->employeeUser('rt2@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:30:00', config('app.timezone')));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        // الانصرافُ 17:03:29 — الساعاتُ من اللحظتَين الفعليّتَين (8س 33د 29ث ≈ 8.56)
        Carbon::setTestNow(Carbon::parse('2026-09-14 17:03:29', config('app.timezone')));
        $res = Workday::checkOut($u);

        $this->assertTrue($res['ok'], 'الانصرافُ سُجّل');
        $row = $res['row'];
        $this->assertSame('17:03:29', $row->time_out, 'لحظةُ ضغطِ الانصرافِ حرفيّاً');
        $this->assertEqualsWithDelta(8.56, (float) $row->hours, 0.02,
            'الساعاتُ محسوبةٌ من اللحظتَين الفعليّتَين');
        $this->assertNotEmpty($row->meta['checkout']['at'] ?? null, 'ختمُ الانصرافِ الكامل');
    }

    public function test_press_time_stamp_survives_a_later_manual_edit(): void
    {
        $this->seedCore();
        $u = $this->employeeUser('rt3@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:12:05', config('app.timezone')));
        $res = Workday::checkIn($u, ['mode' => 'مكتب']);
        $row = $res['row'];

        // تعديلٌ يدويٌّ لاحقٌ للحقل (نموذجُ الموارد) — الختمُ في meta لا يُطمَس
        $row->time_in = '08:00';
        $row->save();

        $fresh = Attendance::find($row->id);
        $this->assertSame('08:00', $fresh->time_in, 'الحقلُ تعدَّل كما أرادت الموارد');
        $this->assertStringContainsString('09:12:05', (string) ($fresh->meta['checkin']['at'] ?? ''),
            'لحظةُ الضغطِ الحقيقيّةُ باقيةٌ أثراً في meta');
    }
}

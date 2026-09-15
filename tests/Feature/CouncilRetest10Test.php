<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التحقّقُ المستقلّ العاشر — **`openRow()` كُتبت «جواباً واحداً» ثمّ سُئلت من
 * قارئَين من خمسة**.
 *
 * ع‑أ · `checkIn` (الكاتب) ما زال على `today()`: من له ورديّةٌ ليليّةٌ مفتوحةٌ
 *   يضغط «تسجيل الحضور» فيُفتَح صفٌّ ثانٍ، ثمّ يُغلق الانصرافُ صفَّ اليومِ
 *   وتُهجَر الورديّةُ بلا انصرافٍ أبداً — **عينُ الضررِ الذي زعمت v2.514.0 إغلاقَه**.
 * ع‑ب · `close()` (الكانس) يختم موظّفَ الليلِ **غائباً** في يومِ انصرافِه —
 *   و`effective` هو العمودُ الذي تُبنى عليه المحاسبةُ الشهريّة.
 * ع‑ج · شاشةُ المدير (`rollCall`) تقول «غائب» بينما بطاقتُه تعرض «انصراف».
 * ع‑د · **قرعةُ UUID**: `compose()` تأخذ `time_out` من كلِّ الصفوفِ وتأخذ رايةَ
 *   العبورِ من **صفٍّ آخر** مرتّبٍ بـ`orderBy('id')` — و`attendance.id` هو
 *   `char(36)` عشوائيّ. فارقُ أربعٍ وعشرين ساعةً في المهلةِ على البيانات نفسِها،
 *   وهي القرعةُ التي تمنعها `CLAUDE.md` بالاسم.
 */
class CouncilRetest10Test extends TestCase
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

    /** صفوفٌ بمعرّفاتٍ مضبوطةِ الترتيب — النموذجُ يسكُّ معرّفَه فلا يصلح هنا */
    private function rawRows(string $empId, array $rows): void
    {
        foreach ($rows as [$p, $in, $out, $ov]) {
            \Illuminate\Support\Facades\DB::table('attendance')->insert([
                'id' => $p . substr((string) Str::uuid(), 4),
                'emp_id' => $empId, 'date' => '2026-09-14',
                'time_in' => $in, 'time_out' => $out, 'overnight' => $ov,
                'in_at' => '2026-09-14 ' . $in,
                'out_at' => ($ov ? '2026-09-15 ' : '2026-09-14 ') . $out,
                'status' => 'حاضر', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ ع‑أ — الكاتبُ يعرف ما تعرفه الشاشة ═══════════ */

    public function test_check_in_refuses_to_open_a_second_row_while_a_night_shift_is_open(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('ci-night@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:48:00'));
        $in = Workday::checkIn($u, ['mode' => 'مكتب']);

        $this->assertFalse($in['ok'] ?? true,
            'فُتح صفٌّ ثانٍ وورديّةُ الليلِ مفتوحة — فتُهجَر بلا انصرافٍ أبداً');
        $this->assertSame(1, Attendance::where('emp_id', $e->id)->count(), 'صفٌّ ثانٍ في القاعدة');

        // وبعدها ينصرف من ورديّتِه هو، لا من صفٍّ مُختلَق
        $out = Workday::checkOut($u);
        $this->assertTrue($out['ok'] ?? false);
        $this->assertSame('2026-09-14', Attendance::where('emp_id', $e->id)
            ->orderBy('date')->first()->date?->toDateString());
    }

    public function test_check_in_still_works_for_a_forgotten_yesterday(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('ci-forgot@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        // ١٧:٠٠ من الغد: صفُّ أمسٍ منسيٌّ خارجَ النافذة ⇒ يومٌ جديدٌ يُفتَح كالمعتاد
        Carbon::setTestNow(Carbon::parse('2026-09-15 17:00:00'));
        $in = Workday::checkIn($u, ['mode' => 'مكتب']);

        $this->assertTrue($in['ok'] ?? false, 'مُنع موظّفٌ من بدءِ يومِه بسببِ صفٍّ منسيٍّ قديم');
        $this->assertSame(2, Attendance::where('emp_id', $e->id)->count());
    }

    /* ═══════════ ع‑ب — الكانسُ لا يختم من هو على رأسِ عملِه ═══════════ */

    public function test_the_nightly_sweep_does_not_stamp_a_night_worker_absent(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('sweep-night@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));
        Workday::checkOut($u);                       // ١٠ ساعاتٍ تنتهي التاسعةَ صباحاً

        Workday::close('2026-09-15');

        $stamped = Attendance::where('emp_id', $e->id)->whereDate('date', '2026-09-15')->first();
        $this->assertNull($stamped,
            'مَن عمل عشرَ ساعاتٍ تنتهي التاسعةَ صباحاً خُتم «غائباً» في اليومِ نفسِه');
    }

    public function test_the_nightly_sweep_still_stamps_a_real_absence(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('sweep-absent@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-15 20:00:00'));
        Workday::close('2026-09-15');

        $this->assertNotNull(
            Attendance::where('emp_id', $e->id)->whereDate('date', '2026-09-15')->first(),
            'غيابٌ حقيقيٌّ لم يُختم — نزعُ قدرةٍ لا إصلاح'
        );
    }

    /* ═══════════ ع‑ج — شاشةُ المدير تقول ما تقوله بطاقتُه ═══════════ */

    public function test_the_manager_roll_call_does_not_count_a_night_worker_as_absent(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('roll-night@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        Carbon::setTestNow(Carbon::parse('2026-09-15 09:30:00'));
        $roll = DailyWorkCompliance::rollCall(Employee::whereKey($e->id)->get(), '2026-09-15');

        $absent = (int) ($roll['absent'] ?? $roll['absent_count'] ?? count((array) ($roll['absent_rows'] ?? [])));
        $this->assertSame(0, $absent,
            'الموظّفُ على رأسِ عملِه وبطاقتُه تعرض «انصراف»، وشاشةُ مديرِه تعدّه غائباً');
    }

    /* ═══════════ ع‑د — لا قرعةَ UUID في اشتقاقِ العبور ═══════════ */

    public function test_the_crossing_flag_comes_from_the_row_that_carries_the_check_out(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('lottery@test.local');
        $this->hubSetting('work.report_grace_minutes', '120');
        $this->hubSetting('work.report_cutoff_time', '');

        // يومٌ بصفَّين: نهاريّةٌ ٠٨:٠٠→١٢:٠٠ وعابرةٌ ٢٣:٠٠→٠٣:٤٨
        // ومعرّفاهما يُختاران ليقلبا ترتيبَ `orderBy('id')` — وهو قرعةٌ على UUID
        // **كتابةٌ خامّةٌ عمداً:** النموذجُ يسكّ معرّفاً جديداً فيُهدر التركيب —
        // والمقصودُ هنا ترتيبُ المعرّفاتِ نفسُه، وهو ما يقرؤه `orderBy('id')`.
        $this->rawRows($e->id, [['aaaa', '08:00:00', '12:00:00', 0], ['zzzz', '23:00:00', '03:48:00', 1]]);

        $a = DailyWorkCompliance::resolve($e, '2026-09-14')['deadline_at'] ?? null;

        // وبالترتيبِ المعكوسِ تماماً — البياناتُ نفسُها والمعرّفاتُ وحدَها انقلبت
        \Illuminate\Support\Facades\DB::table('attendance')->where('emp_id', $e->id)->delete();
        $this->rawRows($e->id, [['zzzz', '08:00:00', '12:00:00', 0], ['aaaa', '23:00:00', '03:48:00', 1]]);

        $b = DailyWorkCompliance::resolve($e, '2026-09-14')['deadline_at'] ?? null;

        $this->assertSame(
            $a ? Carbon::parse((string) $a)->toDateTimeString() : null,
            $b ? Carbon::parse((string) $b)->toDateTimeString() : null,
            'المهلةُ تتغيّر بتغيّرِ بادئةِ UUID وحدَها — قرعةٌ تمنعها CLAUDE.md بالاسم'
        );
    }

    /* ═══════════ ع‑و — المفتاحُ يعمل حيث يَعِد الكتالوج ═══════════ */

    public function test_exportnight_opens_every_guarded_door_of_a_catalogued_module(): void
    {
        $ref = new \ReflectionClass(\App\Http\Middleware\WorkHours::class);
        $guarded = (array) $ref->getConstant('FILE_ROUTES');
        $exempt = (array) $ref->getConstant('NIGHT_EXEMPT');
        $catalog = (array) (config('hub_permissions.exportNight.modules') ?? []);

        // أوراقُ العهدةِ محروسةٌ و`custody` في الكتالوج — فلا بدّ من خريطةٍ لها
        foreach (['custody.label', 'custody.spec', 'custody.permit.doc'] as $name) {
            $this->assertContains($name, $guarded);
            $this->assertArrayHasKey($name, $exempt,
                "«{$name}» محروسٌ ووحدتُه في كتالوجِ exportNight — والمفتاحُ لا يفتحه: نصٌّ يَعِد بما لا يقع");
            $this->assertContains($exempt[$name], $catalog);
        }
    }
}

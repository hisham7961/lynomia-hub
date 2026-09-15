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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التحقّقُ المستقلّ الثاني عشر — **جذرٌ واحدٌ تحت ثلاثةِ عيوبٍ حمراء**.
 *
 * `attendance.time_in/time_out` عمودا **نصّ** يُقارَنان **كنصّ** في خمسةِ مواضع،
 * ونموذجُ الإدخالِ يقبل الساعةَ بخانةٍ واحدة (`9:00`). فـ`'9:00' > '23:00:00'`
 * معجميّاً — ومن هنا:
 *
 * ١. `pickRow` تختار ورديّةَ الصباحِ المنسيّةَ على ورديّةِ الليلِ المفتوحة، فيخرج
 *    `openRow` بـ`NULL` ويفتح `checkIn` صفّاً ثالثاً وتُهجَر الورديّة — **وهو نصُّ
 *    الضررِ الذي أعلنت v2.517.0 إغلاقَه**، وقد صار الآن **حتميّاً** بعد أن كان قرعة.
 * ٢. `$crossed` تعيد مهلةَ التقريرِ إلى **٢٢ ساعةً قبل الانصراف** — انبعاثُ عيبِ
 *    v2.514.0 بحرفِه.
 *
 * **والمستودعُ يعرف القاعدة**: `config/hub_settings.php` يرفض `8:00` في ساعاتِ
 * العمل برسالةٍ صريحة «‏«8:00» تقلب المقارنة صمتاً» — ولا يفرضها على الحقلَين
 * اللذين تُبنى عليهما الرواتبُ والامتثال.
 *
 * ويُضاف إليها عيبان من عائلةِ «شرطان لسؤالٍ واحد»:
 * ٣. ختمُ الموارد البشريّةِ المُدقَّق يتبخّر حين يُغلَق الصفُّ الذي حمله.
 * ٤. `withOpenShift` تشترط **غيابَ صفٍّ أصلاً** بينما `openRow` تشترط **غيابَ
 *    دخولٍ** — فحالةٌ كاملةٌ تسقط بين الشرطَين.
 */
class CouncilRetest12Test extends TestCase
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

    private function rawRow(string $empId, string $date, ?string $in, ?string $out,
                            bool $ov = false, string $status = 'حاضر', ?string $prefix = null): string
    {
        $id = ($prefix ?? '') . substr((string) Str::uuid(), strlen($prefix ?? ''));
        DB::table('attendance')->insert([
            'id' => $id, 'emp_id' => $empId, 'date' => $date,
            'time_in' => $in, 'time_out' => $out, 'overnight' => $ov, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ ١ — الوقتُ يُخزَّن مُصفَّراً فلا تنقلب المقارنة ═══════════ */

    public function test_the_model_normalises_a_single_digit_hour_on_write(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('norm@test.local');

        $a = Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => '9:00', 'time_out' => '7:00', 'overnight' => true, 'status' => 'حاضر']);

        $this->assertSame('09:00:00', (string) $a->fresh()->time_in,
            'الوقتُ يُخزَّن بخانةٍ واحدة — و«9:00» تقلب كلَّ مقارنةٍ نصّيّةٍ صمتاً');
        $this->assertSame('07:00:00', (string) $a->fresh()->time_out);
    }

    /* ═══════════ ٢ — الورديّةُ المفتوحةُ تفوز مهما كانت صيغةُ الوقت ═══════════ */

    public function test_the_open_night_shift_wins_over_a_forgotten_morning_row(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('pick-night@test.local');

        // صفُّ صباحٍ منسيٌّ بساعةٍ ذاتِ خانةٍ واحدة، وورديّةُ ليلٍ مفتوحة
        $this->rawRow($e->id, '2026-09-14', '9:00', null);
        $this->rawRow($e->id, '2026-09-14', '23:00:00', null);

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:48:00'));

        $single = Workday::openRow($e->id);
        $batch = Workday::openCrossingByEmp([$e->id]);

        $this->assertNotNull($single, 'الجوابُ الفرديُّ NULL بينما الجماعيُّ يرى الورديّة');
        $this->assertSame('23:00:00', (string) $single->time_in);
        $this->assertArrayHasKey((string) $e->id, $batch);
        $this->assertSame((string) $single->id, (string) $batch[(string) $e->id]->id,
            'الفرديُّ والجماعيُّ يشيران إلى صفَّين — والتعليقُ يَعِد بمطابقةٍ «حرفاً بحرف»');

        // ولا يُفتَح صفٌّ ثالثٌ والورديّةُ مفتوحة
        $in = Workday::checkIn($u, ['mode' => 'مكتب']);
        $this->assertFalse($in['ok'] ?? true, 'فُتح صفٌّ ثالثٌ فهُجرت الورديّة');
        $this->assertSame(2, Attendance::where('emp_id', $e->id)->count());
    }

    /* ═══════════ ٣ — المهلةُ بعد الانصرافِ ولو كُتب الوقتُ بخانةٍ واحدة ═══════════ */

    public function test_the_deadline_follows_the_real_instant_for_a_sloppy_time(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '120');
        $this->hubSetting('work.report_cutoff_time', '');
        [$u, $e] = $this->member('deadline-sloppy@test.local');

        Attendance::create(['emp_id' => $e->id, 'date' => '2026-08-12',
            'time_in' => '23:00:00', 'time_out' => '7:00', 'overnight' => true, 'status' => 'حاضر']);

        $d = DailyWorkCompliance::resolve($e, '2026-08-12')['deadline_at'];
        $this->assertNotNull($d);
        $this->assertSame('2026-08-13 09:00:00', Carbon::parse((string) $d)->toDateTimeString(),
            'المهلةُ تسبق الانصرافَ الحقيقيَّ — انبعاثُ عيبِ v2.514.0 من مقارنةِ نصّ');
    }

    /* ═══════════ ٤ — ختمُ الموارد لا يتبخّر بإغلاقِ صفّ ═══════════ */

    public function test_the_manual_compliance_seal_survives_closing_the_row_that_carries_it(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('seal@test.local');

        $openId = $this->rawRow($e->id, '2026-09-14', '08:00:00', null);
        $this->rawRow($e->id, '2026-09-14', '13:00:00', '17:00:00');

        // ختمُ الموارد على الصفِّ الذي كان «الأحقَّ» لحظتَئذٍ
        DB::table('attendance')->where('id', $openId)->update([
            'compliance_outcome' => 'excused',
            'compliance_finalized_at' => '2026-09-14 18:00:00',
        ]);
        $this->assertSame('excused', DailyWorkCompliance::resolve($e, '2026-09-14')['effective']);

        // ثمّ تُكمل الموارد انصرافَ ذلك الصفِّ — من الرابطِ الذي تعرضه الشاشةُ نفسُها
        DB::table('attendance')->where('id', $openId)->update(['time_out' => '12:00:00']);

        $this->assertSame('excused', DailyWorkCompliance::resolve($e, '2026-09-14')['effective'],
            'ختمُ الموارد المُدقَّقُ تبخّر بإغلاقِ صفّ — قرارُ إنسانٍ نُقض بلا سجلٍّ ولا إشعار');
    }

    /* ═══════════ ٥ — شرطٌ واحدٌ لسؤالٍ واحد ═══════════ */

    public function test_a_today_row_without_a_check_in_does_not_hide_the_open_shift(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('cond@test.local');

        $this->rawRow($e->id, '2026-09-14', '23:00:00', null);
        $this->rawRow($e->id, '2026-09-15', null, null, false, 'غائب');   // صفُّ اليومِ بلا دخول

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:48:00'));

        $this->assertNotNull(Workday::openRow($e->id), 'الحارسُ لا يرى الورديّة');
        $this->assertNotNull(DailyWorkCompliance::resolve($e, '2026-09-15')['open_shift'],
            '`withOpenShift` تشترط غيابَ صفٍّ أصلاً و`openRow` تشترط غيابَ دخول — '
            . 'شرطان لسؤالٍ واحدٍ فتسقط حالةٌ كاملةٌ بينهما');
    }

    /* ═══════════ ٦ — الكنسُ لا يختم من ورديّتُه ما تزال مفتوحة ═══════════ */

    public function test_the_sweep_does_not_stamp_an_employee_whose_shift_is_still_open(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('sweep-open@test.local');

        $this->rawRow($e->id, '2026-09-14', '23:00:00', null);
        Carbon::setTestNow(Carbon::parse('2026-09-15 06:00:00'));

        Workday::close('2026-09-15');

        $this->assertNull(
            Attendance::where('emp_id', $e->id)->whereDate('date', '2026-09-15')->first(),
            'من ورديّتُه ما تزال مفتوحةً خُتم «غائباً» في السجلِّ الدائم'
        );
    }
}

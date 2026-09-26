<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Workforce\Workday;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * التحقّقُ المستقلّ التاسع — **ثلاثةٌ من صنعِ إصلاحِ الوردية الليليّة (v2.513.0)**.
 *
 * ع‑١ · **نافذةُ تلفيقِ أربعٍ وعشرين ساعةً كلَّ صباح.** حدُّ التبنّي الذي كتبتُه
 *   كان «أن تكون لحظةُ الضغطِ **قبل** وقتِ الدخول» — وهو اختبارُ **ترتيبِ عقربَين**
 *   لا اختبارُ مدّة. فعند دوامٍ يبدأ ٠٨:٠٠ تبقى النافذةُ مفتوحةً من ٠٠:٠٠ حتى
 *   ٠٧:٥٩: ضغطةٌ واحدةٌ تكتب **٢٣٫٧٨ ساعة** على صفِّ الأمس، وترفع `overnight`
 *   فتُسقط وسمَ «مدّةٌ غير صالحة»، وتملأ الانصرافَ فيسقط «انصرافٌ مفقود» —
 *   **فيدخل الرقمُ كشفَ الرواتبِ بلا شذوذٍ ولا وسم**. واختباري السابق فحص
 *   ١٧:٠٠ وحدَها، فكانت الحزمةُ خضراءَ وهي عمياءُ عن النافذة.
 *
 *   **والحدُّ الصحيحُ مدّةٌ لا ترتيب:** للورديةِ سقفٌ (`Workday::MAX_SHIFT_HOURS`).
 *
 * ع‑٢ · **الحارسُ يعرف صفَّ الأمسِ والشاشةُ لا تعرفه.** `checkOut` صارت تُفتّشُ
 *   صفَّ الأمسِ المفتوح، و`mine()` — التي تُغذّي بطاقةَ يومِ العمل — بقيت على
 *   `today()` وحدَها. فمن بدأ ورديّتَه ٢٣:٠٠ يفتح الصفحةَ ٠٣:٤٨ فلا يجد زرَّ
 *   «انصراف» أصلاً، والفعلُ الوحيدُ المعروضُ «تسجيل الحضور» — فيُنشئ صفّاً ثانياً
 *   وتُهجَر ورديّتُه إلى الأبد. **سؤالٌ واحدٌ · تعريفان**، في الإصلاحِ نفسِه.
 *
 * ع‑٣ · **مهلةُ التقريرِ تُحسَب على اليومِ الخطأ.** `computeDeadline` تبني لحظةَ
 *   الانصرافِ من **تاريخِ الصفِّ** وساعةِ الانصراف، فتقع المهلةُ **قبل** الانصرافِ
 *   الحقيقيِّ باثنتين وعشرين ساعة — فيُدان موظّفُ الليلِ بتأخيرِ تقريرٍ قدّمه في وقتِه.
 */
class CouncilRetest9Test extends TestCase
{
    private function member(string $email): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['attend' => ['v' => 1, 'a' => 1, 'e' => 1]], 'companies' => null]);

        $u = User::create(['name' => 'موظّف', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        Employee::create(['name' => 'موظّفُ ' . $email, 'status' => 'نشط', 'user_id' => $u->id]);

        return $u;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ ع‑١ — النافذةُ الصباحيّة ═══════════ */

    /**
     * كلُّ دقيقةٍ من ٠٠:٠١ حتى ٠٧:٥٩ كانت تفتح البابَ. نفحصُ عيّنةً منها لا واحدة —
     * فالعيبُ الأصليُّ نجا لأنّ الاختبارَ فحص لحظةً واحدةً بعدَ الظهر.
     */
    public function test_the_whole_morning_window_refuses_to_close_yesterdays_forgotten_row(): void
    {
        $this->seedCore();

        foreach (['00:01:00', '03:00:00', '06:30:00', '07:47:00', '07:59:59'] as $at) {
            $u = $this->member('win-' . str_replace(':', '', $at) . '@test.local');
            $empId = Employee::where('user_id', $u->id)->value('id');

            Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
            Workday::checkIn($u, ['mode' => 'مكتب']);

            Carbon::setTestNow(Carbon::parse('2026-09-15 ' . $at));
            $out = Workday::checkOut($u);

            $this->assertFalse($out['ok'] ?? true,
                "الساعةَ {$at} أُغلق يومُ الناسي — مدّةٌ ملفَّقةٌ تدخل كشفَ الرواتب");

            $row = Attendance::query()->where('emp_id', $empId)->orderBy('id')->first();
            $this->assertNull($row->time_out, "الساعةَ {$at} كُتب انصرافٌ لم يقع");
            $this->assertNull($row->hours, "الساعةَ {$at} كُتبت ساعاتٌ لم تُعمَل");
            $this->assertFalse((bool) $row->overnight, "الساعةَ {$at} رُفعت رايةُ عبورٍ لم يقع");
        }
    }

    /* ═══════════ ع‑١ — ولا تُنتزع الورديّةُ المشروعة ═══════════ */

    public function test_a_real_night_shift_still_closes(): void
    {
        $this->seedCore();
        $u = $this->member('real-night@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:48:00'));
        $out = Workday::checkOut($u);

        $this->assertTrue($out['ok'] ?? false, (string) ($out['msg'] ?? ''));
        $row = Attendance::query()->orderBy('id')->first();
        $this->assertEqualsWithDelta(4.8, (float) $row->hours, 0.05);
        $this->assertTrue((bool) $row->overnight);
    }

    public function test_a_long_but_legitimate_shift_still_closes(): void
    {
        $this->seedCore();
        $u = $this->member('long-night@test.local');

        // ورديّةٌ من ٢٠:٠٠ إلى ٠٨:٠٠ — اثنتا عشرةَ ساعةً، دون السقف
        Carbon::setTestNow(Carbon::parse('2026-09-14 20:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);
        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00:00'));
        $out = Workday::checkOut($u);

        $this->assertTrue($out['ok'] ?? false, (string) ($out['msg'] ?? ''));
        $this->assertEqualsWithDelta(12.0, (float) Attendance::query()->orderBy('id')->first()->hours, 0.05);
    }

    /* ═══════════ ع‑٢ — الشاشةُ تعرف ما يعرفه الحارس ═══════════ */

    public function test_the_workday_card_offers_check_out_for_a_shift_that_crossed_midnight(): void
    {
        $this->seedCore();
        $u = $this->member('card-night@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:48:00'));
        $mine = Workday::mine($u);

        $this->assertNotNull($mine['att'] ?? null,
            'بطاقةُ يومِ العمل لا ترى الورديّةَ المفتوحة — فلا زرَّ «انصراف»، والفعلُ '
            . 'الوحيدُ «تسجيل الحضور» يُنشئ صفّاً ثانياً ويهجر الورديّة');
        $this->assertSame('2026-09-14', $mine['att']->date?->toDateString());
        $this->assertNull($mine['att']->time_out);
    }

    public function test_the_workday_card_does_not_offer_check_out_for_a_forgotten_row(): void
    {
        $this->seedCore();
        $u = $this->member('card-forgot@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);

        Carbon::setTestNow(Carbon::parse('2026-09-15 07:47:00'));
        $mine = Workday::mine($u);

        $this->assertNull($mine['att'] ?? null,
            'صفُّ أمسٍ منسيٌّ يُعرَض كأنّه ورديّةٌ جارية — والضغطُ عليه يُلفّق ٢٣ ساعة');
    }

    /* ═══════════ ع‑٣ — المهلةُ بعد الانصرافِ الحقيقيّ ═══════════ */

    public function test_report_deadline_follows_the_real_check_out_instant(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '120');
        $this->hubSetting('work.report_cutoff_time', '');
        $u = $this->member('deadline-night@test.local');

        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00'));
        Workday::checkOut($u);

        $row = Attendance::query()->orderBy('id')->first();
        $this->assertNotNull($row->report_deadline_at, 'لا مهلةَ كُتبت');
        $deadline = Carbon::parse((string) $row->report_deadline_at);
        $this->assertTrue(
            $deadline->greaterThan(Carbon::parse('2026-09-15 03:00:00')),
            'المهلةُ (' . $deadline->toDateTimeString() . ') تسبق الانصرافَ نفسَه '
            . '— فيُدان موظّفُ الليلِ بتأخيرِ تقريرٍ قدّمه في وقتِه'
        );
        $this->assertSame('2026-09-15 05:00:00', $deadline->toDateTimeString());
    }
}

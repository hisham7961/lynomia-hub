<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · DB-02 — النوبةُ الليليّةُ غيرُ قابلةٍ للتسجيل.**
 *
 * حارسُ محطّةٍ يدخل **٢٢:٠٠** وينصرف **٠٦:٠٠** — يرفض النظام:
 * «الانصراف (06:00) قبلَ الدخول (22:00) — يومٌ مدّتُه سالبةٌ لا يُحفظ».
 *
 * **والسببُ نمذجةُ اليومِ لا الفترة:** المخطّطُ `date` + `time_in/time_out`
 * نصّان، أي **تاريخٌ وساعتان من ساعاتِ اليوم** — تمثيلٌ لا يسع العبورَ فوق
 * منتصفِ الليل. والحقيقةُ التجاريّةُ **فترةٌ** لها بدايةٌ ونهايةٌ في الزمنِ
 * المطلق.
 *
 * **والنظامُ يحمل وحدةَ محطّاتٍ و«عمليّاتٍ ميدانيّة» وأدوارَ «مشرف ميداني»** —
 * فالمناوبةُ الليليّةُ واقعُ هذه الأدوارِ لا استثناؤها. وخروجُ الحضورِ إلى
 * واتساب يُخرج معه الرواتبَ والغيابَ والامتثال.
 *
 * **والعلاجُ إضافةٌ بحتة:** عمودان اختياريّان يحملان **اللحظةَ الكاملة**، مع
 * إبقاءِ `date`/`time_in`/`time_out` كما هي — ويُرفض فقط ما كانت نهايتُه قبل
 * بدايتِه **باللحظة**، فتصحّ الليليّةُ ويبقى منعُ اليومِ السالب.
 */
class CouncilNightShiftTest extends TestCase
{
    protected function employee(): Employee
    {
        return Employee::create(['name' => 'حارسُ محطّة', 'status' => 'نشط']);
    }

    /** البابُ الحقيقيّ: نموذجُ الإنشاءِ العامّ */
    protected function punch(Employee $e, string $date, string $in, string $out, bool $overnight = false)
    {
        return $this->actingAs($this->owner)->post(route('m.store', 'attend'), [
            'empId' => $e->id, 'date' => $date, 'in' => $in, 'out' => $out,
        ] + ($overnight ? ['overnight' => '1'] : []));
    }

    public function test_a_night_shift_can_be_recorded(): void
    {
        $this->seedCore();
        $e = $this->employee();

        $this->punch($e, '2026-06-17', '22:00', '06:00', true);

        $row = Attendance::where('emp_id', $e->id)->first();
        $this->assertNotNull($row,
            '**النوبةُ الليليّةُ لا تُسجَّل**: دخولٌ ٢٢:٠٠ وانصرافٌ ٠٦:٠٠ مرفوضٌ '
            . 'بوصفِه «يوماً مدّتُه سالبة» — ونمذجةُ اليومِ لا الفترةِ هي السبب. '
            . 'والنظامُ يحمل محطّاتٍ وعمليّاتٍ ميدانيّةً: الليليّةُ واقعُها لا استثناؤها.');
        $this->assertEqualsWithDelta(8.0, (float) $row->hours, 0.01,
            'الساعاتُ لم تُشتقّ من الفترةِ الحقيقيّة (٨ ساعات عبرَ منتصفِ الليل)');
    }

    public function test_a_same_day_shift_still_works_exactly_as_before(): void
    {
        $this->seedCore();
        $e = $this->employee();

        $this->punch($e, '2026-06-18', '08:00', '17:00');

        $row = Attendance::where('emp_id', $e->id)->first();
        $this->assertNotNull($row, '**قدرةٌ نُزعت**: الوردية النهاريّةُ لم تُحفظ');
        $this->assertEqualsWithDelta(9.0, (float) $row->hours, 0.01,
            'ساعاتُ الوردية النهاريّةِ تغيّرت');
    }

    /**
     * **والحارسُ يبقى حارساً لمن لم يُعلن.** خطأٌ مطبعيٌّ في ورديةٍ نهاريّة
     * (٢٢:٠٠ بدل ٠٨:٠٠) يجب أن يُردَّ كما كان — ولو رُوّل العبورُ تلقائيّاً
     * لابتُلع الخطأُ وصار ثماني ساعاتٍ صحيحة.
     */
    public function test_a_mistyped_day_shift_is_still_refused(): void
    {
        $this->seedCore();
        $e = $this->employee();

        $this->punch($e, '2026-06-21', '22:00', '06:00');   // بلا راية

        $this->assertSame(0, Attendance::where('emp_id', $e->id)->count(),
            '**ابتلاعُ خطأ**: زوجٌ مقلوبٌ بلا رايةِ «وردية ليلية» حُفظ — '
            . 'والحارسُ وُضع ليردّ اليومَ السالب، لا ليُعيد تفسيرَه.');
    }

    /** والتقريرُ الشهريُّ لا يسمّي الليليّةَ المُعلَنةَ «مدة غير صالحة» */
    public function test_the_monthly_report_does_not_call_a_night_shift_invalid(): void
    {
        $this->seedCore();
        $e = $this->employee();
        $this->punch($e, '2026-06-17', '22:00', '06:00', true);

        $row = Attendance::where('emp_id', $e->id)->firstOrFail();
        $this->assertTrue((bool) $row->overnight, 'تهيئةٌ خاطئة: الرايةُ لم تُحفظ');

        $sheet = \App\Support\MonthlyAttendance::sheet($e->fresh(), '2026-06');
        $day = collect($sheet['days'] ?? $sheet)->firstWhere('date', '2026-06-17');
        $this->assertNotNull($day, 'اليومُ غائبٌ عن الكشفِ الشهريّ');

        $this->assertFalse((bool) ($day['invalid_span'] ?? false),
            'الكشفُ الشهريُّ يسمّي الوردية الليليّةَ المُعلَنةَ «مدة غير صالحة» — '
            . 'فالحارسُ والتقريرُ يقولان قولين في سؤالٍ واحد.');
    }

    /** DB-03 · وفهرسان يخدمان ما يُستعلَم فعلاً */
    public function test_the_attendance_date_queries_have_indexes(): void
    {
        $idx = collect(\Illuminate\Support\Facades\Schema::getIndexes('attendance'))
            ->pluck('name')->all();

        $this->assertContains('attendance_emp_date_idx', $idx,
            'نطاقُ الموظّفِ ويومُه بلا فهرسٍ مركّب — و`EXPLAIN` يقول `type: ALL`');
        $this->assertContains('attendance_date_idx', $idx,
            'تقاريرُ الشركةِ على `date` بلا فهرس — في جدولٍ عليه أربعةَ عشرَ فهرساً');
    }

    public function test_a_missing_checkout_still_invents_no_hours(): void
    {
        $this->seedCore();
        $e = $this->employee();

        $this->punch($e, '2026-06-19', '08:00', '');

        $row = Attendance::where('emp_id', $e->id)->first();
        $this->assertNotNull($row, 'صفُّ «انصرافٌ مفقود» لم يُحفظ');
        $this->assertNull($row->hours,
            '**اختلاقُ ساعات**: زوجٌ ناقصٌ يجب ألّا يُقدَّر — الشذوذُ يُصحَّح لا يُخمَّن');
    }

    public function test_the_identical_time_pair_is_still_zero_not_a_full_day(): void
    {
        $this->seedCore();
        $e = $this->employee();

        $this->punch($e, '2026-06-20', '09:00', '09:00');

        $row = Attendance::where('emp_id', $e->id)->first();
        $this->assertNotNull($row, 'زوجٌ متساوٍ لم يُحفظ');
        $this->assertEqualsWithDelta(0.0, (float) $row->hours, 0.01,
            '**خطرٌ**: دخولٌ وانصرافٌ في اللحظةِ نفسِها صار يوماً كاملاً بدل صفر');
    }
}

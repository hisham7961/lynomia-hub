<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Workforce\DailyWorkCompliance;
use App\Support\Workforce\MonthlyAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **محاكاةُ الجولة الثانية — إقفالُ شهرِ الحضور للرواتب (G9…G12).**
 *
 * رحلةُ مريم (HR) وسالم (العمليات) ويوسف (المحاسب) في إقفالِ سبتمبر كشفت أربعةَ
 * عيوبٍ تُخرج الشهرَ من النظامِ إلى إكسل:
 *
 *  - **G9 — تصحيحُ الانصرافِ لا يعيد حسابَ الساعات:** انصرافٌ صُحِّح إلى 17:00
 *    والساعاتُ تبقى «—»، فالموظّفُ عالقٌ على ٥٠ ساعةً بدل ٧٥٫٥ في الشبكةِ
 *    والمجاميعِ والتصدير. الدواء **في النموذج نفسِه** (خطّاف `saving`): كلُّ
 *    كتابةٍ تمسّ الدخول/الانصراف تُعيد اشتقاقَ الساعاتِ بقاعدةِ `Workday::checkOut`
 *    نفسِها — فتغطّي الويبَ والـAPI والجوالَ والاستيرادَ بابواباً واحداً.
 *  - **G10 — انصرافٌ قبل الدخول يُقبل صامتاً:** دخولُ 16:00 وانصرافُ 09:00 حُفظ،
 *    و**محا شارةَ الشذوذ** فصار اليومُ «حاضراً» نظيفاً. حارسُ النموذج يرفضه
 *    برسالةٍ تسمّي القيمتين، والصفُّ القديمُ الفاسدُ يُوسَم «مدة غير صالحة» لا يُجمَّل.
 *  - **G11 — التصديرُ الشهريّ لا يصلح للرواتب:** صفوفُ الأختامِ وحدَها، بلا غيابٍ
 *    ولا غائبين ولا معرّفٍ ولا مجاميع. الدواء: **وضعُ كشفِ الرواتب** — صفٌّ لكلِّ
 *    موظّفٍ في النطاق بأيّامِه وساعاتِه وشذوذاتِه، والراتبُ خلفَ `fieldsec`،
 *    والتصديرُ اليوميُّ القائمُ كما هو (الإضافةُ لا الكسر).
 *  - **G12 — نداءُ اليومِ يكذب قبل بدءِ الدوام:** الساعةُ 04:20 و«غائبٌ بلا عذر: 32».
 *    قبلَ `sec.hours_start` + السماحيةِ لا يُعلَن أحدٌ غائباً، والطلبُ المعلَّق
 *    («إذن خروج» بانتظارِ قرار) حالةٌ وسيطةٌ لا غياب.
 */
class DogfoodR2AttendanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** موظفٌ نشطٌ مربوطٌ بمستخدمٍ منفّذ (نمطُ DogfoodR1AttendanceTest) */
    protected function linkedEmployee(string $name, array $extra = []): array
    {
        $role = Role::firstOrCreate(['name' => 'موظّفٌ منفّذ'],
            ['scope' => 'all', 'flags' => [],
             'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'tasks' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(array_merge(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id], $extra));

        return [$u, $e];
    }

    /** محاسبٌ بصلاحيّةِ الحضور وحدَها — لا `hr` ولا `fieldsec` */
    protected function accountant(): User
    {
        $role = Role::create(['name' => 'محاسب' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['attend' => ['v' => 1]]]);

        return User::create(['name' => 'يوسف المحاسب', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** صفُّ حضورٍ مباشر */
    protected function attendance(Employee $e, string $date, ?string $in = '08:00', ?string $out = '16:00', array $extra = []): Attendance
    {
        return Attendance::create(array_merge([
            'emp_id' => $e->id, 'date' => $date, 'time_in' => $in, 'time_out' => $out,
            'status' => \App\Support\Workforce\Workday::PRESENT,
        ], $extra));
    }

    /** إجازةٌ معتمدةٌ من أنواع الخصم */
    protected function leave(Employee $e, string $from, string $to): void
    {
        Employee::whereKey($e->id)->update(['leave_bal' => 30]);
        LeaveRequest::create(['emp_id' => $e->id, 'type' => 'إجازة سنوية',
            'date_from' => $from, 'date_to' => $to, 'days' => 1, 'status' => 'معتمد']);
    }

    /** حمولةُ نموذجِ تعديلِ الحضور العامّ (`ModuleController`) — كما ترسلها الشاشة */
    protected function editPayload(Attendance $row, array $over = []): array
    {
        return array_merge([
            'empId' => (string) $row->emp_id,
            'date' => (string) ($row->date?->toDateString() ?? $row->date),
            'in' => (string) $row->time_in,
            'out' => (string) $row->time_out,
            'hours' => '',                               // الحقلُ اليدويُّ يُترك فارغاً كما فعلت مريم
            'status' => (string) $row->status,
            'notes' => 'تصحيح بعد مراجعة كاميرات المبنى',
        ], $over);
    }

    /* ═══════════ G9 — تصحيحُ الانصرافِ يعيد اشتقاقَ الساعات ═══════════ */

    public function test_correcting_checkout_recomputes_hours_at_the_model_gate(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('أحمد قاسم');
        $row = $this->attendance($e, '2026-09-03', '08:00', null);   // انصرافٌ مفقود
        $this->assertNull($row->hours, 'قبل التصحيح: لا ساعاتٍ تُختلق');

        $row->time_out = '17:00';
        $row->save();

        $this->assertSame(9.0, (float) $row->fresh()->hours,
            'الساعاتُ تُشتقُّ من الفرقِ لحظةَ الحفظ — لا تبقى «—» بعد التصحيح');
    }

    public function test_correcting_checkout_through_the_generic_module_form_recomputes_everywhere(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('أحمد قاسم');
        $this->attendance($e, '2026-09-01', '08:00', '16:00', ['hours' => 8]);
        $row = $this->attendance($e, '2026-09-03', '08:00', null);   // انصرافٌ مفقود

        // مسارُ التصحيحِ الذي سلكته الموارد البشرية: نموذجُ الوحدات العامّ
        $this->actingAs($this->owner)
            ->put(route('m.update', ['attend', $row->id]), $this->editPayload($row, ['out' => '17:00']))
            ->assertSessionHasNoErrors();

        $this->assertSame(9.0, (float) $row->fresh()->hours, 'التصحيحُ من الشاشةِ يحتسب الساعات');

        // الشبكةُ الشهرية والمجاميعُ والتصديرُ — كلُّها من المصدرِ نفسِه
        $sheet = MonthlyAttendance::sheet($e, '2026-09');
        $day = collect($sheet['days'])->keyBy('date')['2026-09-03'];
        $this->assertSame(9.0, (float) $day['hours'], 'الشبكةُ الشهرية ترى الساعاتِ المصحَّحة');
        $this->assertSame(17.0, (float) $sheet['totals']['attendance_hours'],
            'مجموعُ الشهرِ يتحرّك بالتصحيح (8 + 9) — لا يتجمّد على رقمِه القديم');

        $csv = $this->actingAs($this->owner)
            ->get(route('reports.monthly.export', ['month' => '2026-09', 'emp' => $e->id]))->streamedContent();
        $this->assertStringContainsString('17:00', $csv, 'الانصرافُ المصحَّحُ في الملفّ');
        $this->assertStringContainsString('9.00', $csv, 'وساعاتُه محتسبةٌ فيه — لا خليّةٌ فارغة');
    }

    public function test_manual_hours_entry_stays_an_explicit_override(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('موظّفُ التجاوزِ اليدويّ');

        // ساعاتٌ يدويّةٌ صريحةٌ تخالف الفرق (خصمُ استراحةٍ مثلاً) — تبقى كما أُدخلت
        $row = $this->attendance($e, '2026-09-02', '08:00', '17:00', ['hours' => 7.5]);
        $this->assertSame(7.5, (float) $row->fresh()->hours, 'الإدخالُ اليدويُّ الصريحُ لا يُدهَس');

        // ولاحقاً: تعديلُ الانصرافِ وحدَه يعيد الاشتقاق (الكتابةُ تمسّ الزوج)
        $row->time_out = '16:00';
        $row->save();
        $this->assertSame(8.0, (float) $row->fresh()->hours,
            'تعديلُ الانصرافِ يعيد الاشتقاق — التجاوزُ اليدويُّ لتلك الكتابةِ وحدَها');
    }

    public function test_clearing_the_checkout_clears_the_derived_hours(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('ماحي الانصراف');
        $row = $this->attendance($e, '2026-09-02', '08:00', '16:00');
        $this->assertSame(8.0, (float) $row->fresh()->hours);

        $row->time_out = null;
        $row->save();
        $this->assertNull($row->fresh()->hours,
            'مسحُ الانصرافِ يمحو الساعاتِ المشتقّة — لا ساعاتٍ بلا انصرافٍ يبرّرها');
    }

    /* ═══════════ G10 — انصرافٌ قبل الدخول: رفضٌ لا تجميل ═══════════ */

    public function test_checkout_before_checkin_is_refused_by_the_model(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('يومٌ مستحيل');
        $row = $this->attendance($e, '2026-09-13', '08:28:58', null);

        $row->time_in = '16:00';
        $row->time_out = '09:00';
        try {
            $row->save();
            $this->fail('يومٌ مدّتُه سالبةٌ لا يُحفظ');
        } catch (\Illuminate\Validation\ValidationException $ex) {
            $msg = implode(' ', \Illuminate\Support\Arr::flatten($ex->errors()));
            $this->assertStringContainsString('09:00', $msg, 'الرسالةُ تسمّي الانصراف');
            $this->assertStringContainsString('16:00', $msg, 'والرسالةُ تسمّي الدخول');
        }

        $fresh = $row->fresh();
        $this->assertSame('08:28:58', $fresh->time_in, 'لم يُكتب شيءٌ من الصفِّ المرفوض');
        $this->assertNull($fresh->time_out);
    }

    public function test_impossible_day_from_the_form_is_refused_and_the_anomaly_badge_survives(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('أحمد قاسم');
        $row = $this->attendance($e, '2026-09-13', '08:28:58', null);   // شذوذُ «انصراف مفقود»

        $this->actingAs($this->owner)
            ->put(route('m.update', ['attend', $row->id]),
                $this->editPayload($row, ['in' => '16:00', 'out' => '09:00']))
            ->assertSessionHasErrors();

        $this->assertNull($row->fresh()->time_out, 'الصفُّ المستحيلُ لم يُحفظ');

        // والشذوذُ باقٍ: القمامةُ لا تُطفئ الإنذار
        $sheet = MonthlyAttendance::sheet($e, '2026-09');
        $this->assertSame(1, $sheet['totals']['missing_out'],
            'شارةُ «انصراف مفقود» لم تُمحَ بحشوِ وقتٍ مستحيل');
    }

    public function test_legacy_impossible_row_is_flagged_not_shown_as_a_clean_present_day(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('صفٌّ فاسدٌ قديم');

        // صفٌّ كُتب قبلَ الحارس (أو من خارجِ النموذج) — يُقرأ لا يُجمَّل
        DB::table('attendance')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $e->id, 'date' => '2026-09-09',
            'time_in' => '16:00', 'time_out' => '09:00', 'status' => \App\Support\Workforce\Workday::PRESENT,
            'version' => 1, 'archived' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sheet = MonthlyAttendance::sheet($e, '2026-09');
        $day = collect($sheet['days'])->keyBy('date')['2026-09-09'];
        $this->assertTrue((bool) ($day['invalid_span'] ?? false), 'اليومُ موسومٌ «مدة غير صالحة»');
        $this->assertSame(1, $sheet['totals']['invalid_span'] ?? 0, 'وشذوذاتُ الشهرِ تعدّه');

        $html = $this->actingAs($this->owner)
            ->get(route('reports.monthly.employee', ['emp' => $e->id, 'month' => '2026-09']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('مدة غير صالحة', $html,
            'الشاشةُ تقول الحقيقةَ بدل «حاضر» نظيف');
    }

    /* ═══════════ G11 — تصديرٌ بمستوى الرواتب ═══════════ */

    public function test_payroll_export_has_a_row_for_every_employee_even_without_a_single_stamp(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u1, $stamped] = $this->linkedEmployee('أحمد قاسم', ['dept' => 'التشغيل']);
        [$u2, $never] = $this->linkedEmployee('تركي السبيعي', ['dept' => 'المبيعات']);
        $this->attendance($stamped, '2026-09-01', '08:00', '16:00');
        $this->attendance($stamped, '2026-09-02', '08:00', null);     // شذوذ: انصرافٌ مفقود
        $this->leave($stamped, '2026-09-03', '2026-09-03');

        $csv = $this->actingAs($this->owner)
            ->get(route('reports.monthly.export', ['month' => '2026-09', 'mode' => 'payroll']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('أحمد قاسم', $csv);
        $this->assertStringContainsString('تركي السبيعي', $csv,
            'من لا ختمَ له إطلاقاً حاضرٌ في كشفِ الرواتب — لا يسقط من الملفّ');
        $this->assertSame(3, count(array_filter(explode("\n", trim($csv)))),
            'صفُّ عنوانٍ + صفٌّ لكلِّ موظّفٍ في النطاق (لا صفٌّ لكلِّ ختم)');
    }

    public function test_payroll_export_carries_identity_days_hours_and_anomalies(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('أحمد قاسم', ['dept' => 'التشغيل', 'salary' => 1405]);
        $this->attendance($e, '2026-09-01', '08:00', '16:00');
        $this->attendance($e, '2026-09-02', '08:00', null);            // انصرافٌ مفقود
        $this->leave($e, '2026-09-03', '2026-09-03');

        $csv = $this->actingAs($this->owner)
            ->get(route('reports.monthly.export', ['month' => '2026-09', 'mode' => 'payroll']))
            ->assertOk()->streamedContent();

        foreach (['الموظف', 'معرّف الموظف', 'القسم', 'أيام العمل', 'أيام الحضور',
            'غياب بلا عذر', 'أيام الإجازة', 'مجموع الساعات', 'شذوذات', 'الراتب الأساسي'] as $h) {
            $this->assertStringContainsString($h, $csv, 'عمودٌ لازمٌ لكشفِ الرواتب: ' . $h);
        }
        $this->assertStringContainsString((string) $e->id, $csv, 'معرّفُ الموظّفِ يُطابَق به — لا بالاسمِ العربيّ وحدَه');
        $this->assertStringContainsString('التشغيل', $csv);
        $this->assertStringContainsString('1405', $csv, 'المالكُ يرى الراتبَ الأساسيّ');

        // صفُّ الموظّف: أيامُ حضورٍ ٢، إجازةٌ ١، غيابٌ بلا عذرٍ لبقيّةِ أيامِ العملِ الماضية
        $line = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'أحمد قاسم'));
        $cells = str_getcsv(trim((string) $line), ',', '"', '');
        $this->assertSame('2', $cells[4] ?? null, 'أيامُ الحضورِ = الأختامُ الفعليّة');
        $this->assertSame('1', $cells[7] ?? null, 'أيامُ الإجازةِ المعتمدة');
        $this->assertSame('8.00', $cells[8] ?? null, 'مجموعُ الساعاتِ — الانصرافُ المفقودُ لا يختلق ساعات');
        $this->assertGreaterThan(0, (int) ($cells[5] ?? 0), 'أيامُ الغيابِ بلا عذرٍ معدودةٌ في الملفّ');
        $this->assertSame('1', $cells[11] ?? null, 'عدّادُ الشذوذات (انصرافٌ مفقود)');
    }

    public function test_payroll_export_hides_the_salary_from_a_role_without_fieldsec(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('أحمد قاسم', ['salary' => 1405]);
        $this->attendance($e, '2026-09-01', '08:00', '16:00');

        $csv = $this->actingAs($this->accountant())
            ->get(route('reports.monthly.export', ['month' => '2026-09', 'mode' => 'payroll']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('أحمد قاسم', $csv, 'المحاسبُ يرى الأيامَ والساعات');
        $this->assertStringContainsString('محجوب', $csv, 'والراتبُ خلفَ fieldsec — «محجوب» لا رقم');
        $this->assertMaskedValueAbsent($csv, '1405', 'لا يتسرّبُ الراتبُ لمن لا يملك مفتاحَه');
    }

    public function test_the_existing_daily_export_contract_is_untouched(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('أحمد قاسم');
        $this->attendance($e, '2026-09-01', '08:00', '16:00');

        $csv = $this->actingAs($this->owner)
            ->get(route('reports.monthly.export', ['month' => '2026-09']))->assertOk()->streamedContent();

        $head = str_getcsv(trim(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))[0]), ',', '"', '');
        $this->assertSame(['الموظف', 'القسم', 'اليوم', 'الحضور', 'الانصراف', 'الساعات',
            'الحالة الفعلية', 'التقرير', 'الحالة المحتسَبة'], $head,
            'عقدُ الملفِّ اليوميِّ القائمِ كما هو — الوضعُ الجديدُ إضافةٌ لا بديل');
        $this->assertStringContainsString('2026-09-01', $csv, 'وصفُّ اليومِ المختومِ باقٍ');
    }

    /* ═══════════ G12 — نداءُ اليوم: لا غيابَ قبلَ بدءِ الدوام ═══════════ */

    public function test_roll_call_declares_nobody_absent_before_the_workday_starts(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '08:00');
        $this->hubSetting('work.late_grace', '15');
        // اثنين 2026-09-14 الساعة 04:20 فجراً — الدوامُ لم يبدأ بعد
        Carbon::setTestNow(Carbon::parse('2026-09-14 04:20:00', config('app.timezone')));

        [$u1, $e1] = $this->linkedEmployee('سالم العمليات');
        [$u2, $e2] = $this->linkedEmployee('لطيفة الجديدة');

        $roll = DailyWorkCompliance::rollCall(
            Employee::whereNull('deleted_at')->where('status', 'نشط')->orderBy('id')->get(['id', 'name', 'dept', 'user_id'])
        );

        $this->assertTrue($roll['not_started'] ?? false, 'الحالةُ: لم يبدأ الدوامُ بعد');
        $this->assertSame(0, $roll['n']['absent'],
            'لا يُعلَن أحدٌ «غائباً بلا عذر» قبلَ بدايةِ الدوامِ + السماحية');
        $this->assertSame(2, $roll['n']['not_yet'] ?? 0, 'الجميعُ «لم يصل بعد» — حالةٌ صادقة');

        // وبعد بدايةِ الدوام: النداءُ يعمل كما كان (الغيابُ بالفرق)
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00', config('app.timezone')));
        $later = DailyWorkCompliance::rollCall(
            Employee::whereNull('deleted_at')->where('status', 'نشط')->orderBy('id')->get(['id', 'name', 'dept', 'user_id'])
        );
        $this->assertFalse($later['not_started'] ?? false);
        $this->assertSame(2, $later['n']['absent'], 'بعد بدءِ الدوامِ يعود الغيابُ يُعلَن');
    }

    public function test_a_pending_request_is_awaiting_a_decision_not_an_unexcused_absence(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '08:00');
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:30:00', config('app.timezone')));

        [$u1, $huda] = $this->linkedEmployee('هدى صاحبةُ الإذن');
        [$u2, $plain] = $this->linkedEmployee('غائبٌ بلا طلب');

        // إذنُ خروجٍ وافق عليه المدير وينتظر الموارد البشرية — قيدَ القرار لا غياب
        LeaveRequest::create(['emp_id' => $huda->id, 'type' => 'إذن خروج',
            'date_from' => '2026-09-14', 'date_to' => '2026-09-14', 'days' => 1,
            'status' => 'موافقة المدير']);

        $roll = DailyWorkCompliance::rollCall(
            Employee::whereNull('deleted_at')->where('status', 'نشط')->orderBy('id')->get(['id', 'name', 'dept', 'user_id'])
        );

        $names = fn (string $k) => array_column($roll['buckets'][$k] ?? [], 'name');
        $this->assertContains('هدى صاحبةُ الإذن', $names('pending'),
            'طلبٌ قيدَ القرارِ حالتُه «بانتظار قرار»');
        $this->assertNotContains('هدى صاحبةُ الإذن', $names('absent'),
            'لا يُراسَل موظّفٌ معذورٌ عمليّاً كأنّه غائبٌ بلا عذر');
        $this->assertContains('غائبٌ بلا طلب', $names('absent'), 'ومن لا طلبَ له يبقى غائباً');
        $this->assertSame(1, $roll['n']['absent']);
    }
}

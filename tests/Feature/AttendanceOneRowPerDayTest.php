<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * **موظّفٌ ويومٌ = صفٌّ واحد — على كلِّ الأبواب** (مجلس الخبراء · N-15).
 *
 * `unique_together` في سجلِّ الوحدة يُفرَض في `ModuleController::rulesFor` من
 * `request()->input()` — أي **في بابِ النموذجِ وحدَه**. والاستيرادُ يحفظ بلا
 * قواعدِ الوحدة، والـAPI كذلك، ولا قيدَ في القاعدة. بابٌ يحرس وبابٌ لا يحرس.
 *
 * والضررُ ليس نظريّاً: صفّان لليومِ نفسِه هما المُغذّي المباشرُ لعائلةِ عيوبِ
 * «أيُّ صفٍّ هو الصحيح؟» التي طاردها هذا المجلسُ اثنتي عشرةَ جولة.
 *
 * والعلاجُ نظيرُ ما فعله المنتجُ بـ`payroll_runs.month_key` حرفاً: مفتاحٌ
 * **مشتقٌّ** يُفرَّغ عند الحذف، وفهرسٌ فريدٌ عليه، وحارسٌ في النموذج يسبقه
 * برسالةٍ مفهومة.
 */
class AttendanceOneRowPerDayTest extends TestCase
{
    private function emp(): Employee
    {
        return Employee::create(['name' => 'موظّفُ التفرّد', 'status' => 'نشط']);
    }

    public function test_a_second_row_for_the_same_day_is_refused_on_every_door(): void
    {
        $this->seedCore();
        $e = $this->emp();

        Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => '08:00', 'time_out' => '17:00', 'status' => 'حاضر']);

        // البابُ الثاني: كتابةٌ مباشرةٌ عبر النموذج (استيرادٌ · API · سكربت)
        try {
            Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14',
                'time_in' => '09:00', 'status' => 'حاضر']);
            $this->fail('صفٌّ ثانٍ لليومِ نفسِه مرّ — والقيدُ يُفرَض في بابٍ واحدٍ فقط');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('صفٌّ', implode(' ', $ex->errors()['date'] ?? ['']));
        }

        $this->assertSame(1, Attendance::where('emp_id', $e->id)
            ->whereDate('date', '2026-09-14')->count());
    }

    /** ويومٌ آخرُ للموظّفِ نفسِه يمرّ — الحارسُ ضيّقٌ لا جارف. */
    public function test_another_day_for_the_same_employee_passes(): void
    {
        $this->seedCore();
        $e = $this->emp();

        Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14', 'time_in' => '08:00', 'status' => 'حاضر']);
        Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-15', 'time_in' => '08:00', 'status' => 'حاضر']);

        $this->assertSame(2, Attendance::where('emp_id', $e->id)->count());
    }

    /** والصفُّ المحذوفُ لا يحجز اليوم — يُعاد إدخالُه بعد الحذف. */
    public function test_a_deleted_row_does_not_hold_the_day_hostage(): void
    {
        $this->seedCore();
        $e = $this->emp();

        $first = Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => '08:00', 'status' => 'حاضر']);
        $first->delete();

        $second = Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => '09:00', 'status' => 'حاضر']);

        $this->assertNotNull($second->id, 'المحذوفُ لا يمنع إدخالاً جديداً لليومِ نفسِه');
        $this->assertSame(1, Attendance::where('emp_id', $e->id)->count());
    }

    /** وتحديثُ الصفِّ نفسِه لا يصطدم بذاته. */
    public function test_updating_the_same_row_does_not_collide_with_itself(): void
    {
        $this->seedCore();
        $e = $this->emp();

        $row = Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-14',
            'time_in' => '08:00', 'status' => 'حاضر']);
        $row->time_out = '17:00';
        $row->save();

        $this->assertSame('17:00:00', (string) $row->fresh()->time_out);
    }
}

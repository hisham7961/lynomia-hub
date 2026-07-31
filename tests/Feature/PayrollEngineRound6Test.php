<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Tests\TestCase;

/**
 * المرحلة ٤ — مسيّر رواتب حقيقي: كانت الوحدة عنواناً وإجمالياً يُكتب بالإصبع
 * وصفر منطق في المشروع كله. التوليد من الرواتب والبدلات، الاعتماد يقفل
 * ويولّد قيداً، والصرف يختم تاريخه.
 */
class PayrollEngineRound6Test extends TestCase
{
    public function test_generation_builds_line_per_active_employee(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'موظف أول', 'status' => 'نشط', 'salary' => 800, 'allow' => 200]);
        Employee::create(['name' => 'موظف ثانٍ', 'status' => 'نشط', 'salary' => 500, 'allow' => 0]);
        Employee::create(['name' => 'مغادر', 'status' => 'منتهية خدمته', 'salary' => 999]);
        $run = PayrollRun::create(['name' => 'مسيّر يوليو', 'month' => '2026-07', 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'generate']);

        $this->assertSame(2, PayrollLine::where('run_id', $run->id)->count(), 'سطر لكل نشط — والمغادر مستبعد');
        $this->assertEquals(1500.0, (float) $run->fresh()->total, 'الإجمالي يُحسب لا يُكتب بالإصبع');

        // إعادة التوليد لا تكرر السطور
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'generate']);
        $this->assertSame(2, PayrollLine::where('run_id', $run->id)->count());
    }

    public function test_approval_locks_generation_and_creates_journal_when_enabled(): void
    {
        $this->seedCore();
        foreach ([['1020', 'البنك', 'أصول'], ['5200', 'مصروفات تشغيلية', 'مصروفات']] as [$c, $n, $t]) {
            \App\Models\LedgerAccount::create(['code' => $c, 'name' => $n, 'type' => $t]);
        }
        $this->hubSetting('finance.accounts', json_encode(['bank' => '1020', 'exp' => '5200'], JSON_UNESCAPED_UNICODE));
        $this->hubSetting('finance.auto_journal', '1');

        Employee::create(['name' => 'موظف قيد', 'status' => 'نشط', 'salary' => 1000]);
        $run = PayrollRun::create(['name' => 'مسيّر القيد', 'month' => '2026-07', 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'generate']);
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'approve']);
        $this->assertSame('معتمد', $run->fresh()->status);

        // قيد رواتب موزون مُرحَّل
        $entry = JournalEntry::where('description', 'LIKE', '%مسيّر القيد%')->first();
        $this->assertNotNull($entry, 'الاعتماد يولّد قيد الرواتب — كان مصروف الرواتب غائباً عن كل قائمة مالية');
        $lines = \App\Models\JournalLine::where('entry_id', $entry->id)->get();
        $this->assertEquals(1000.0, (float) $lines->sum('debit'));
        $this->assertEquals((float) $lines->sum('debit'), (float) $lines->sum('credit'));

        // بعد الاعتماد لا توليد
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'generate'])->assertStatus(422);

        // والصرف يختم تاريخه
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'pay']);
        $run->refresh();
        $this->assertSame('مدفوع', $run->status);
        $this->assertNotNull($run->pay_date);
    }

    public function test_empty_run_cannot_be_approved(): void
    {
        $this->seedCore();
        $run = PayrollRun::create(['name' => 'مسيّر فارغ', 'month' => '2026-07', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'approve'])->assertStatus(422);
        $this->assertSame('مسودة', $run->fresh()->status);
    }

    public function test_run_page_shows_lines_and_actions(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'موظفة العرض', 'status' => 'نشط', 'salary' => 700, 'allow' => 100]);
        $run = PayrollRun::create(['name' => 'مسيّر العرض', 'month' => '2026-07', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'generate']);

        $this->actingAs($this->owner)->get('/m/payroll/' . $run->id)
            ->assertOk()->assertSee('بنود المسيّر')->assertSee('موظفة العرض')->assertSee('800.00');
    }
}

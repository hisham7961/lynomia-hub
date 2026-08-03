<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * تعديلات الرواتب — النقص نصف المبنيّ: أعمدة «إضافي/خصم/سلفة» معروضةٌ في شاشة
 * المسيّر ومخزَّنةٌ في `payroll_lines`، لكن **لا مسارَ لإدخالها إطلاقاً**، و`net`
 * يحسب `base + allow` فقط فيتجاهلها. فالمحاسب لا يستطيع إدخال إضافيٍّ ولا خصمٍ
 * ولا سلفة، ولو أمكنه لما دخلت الصافي ولا الإجمالي ولا قيد اليومية.
 *
 * الآن: مسارُ إدخالٍ محروس (`do=adjust`)، والصافي = الأساسي + البدلات + الإضافي
 * − الخصم − السلفة، والإجمالي يُعاد حسابه من السطور.
 */
class PayrollAdjustmentsTest extends TestCase
{
    /** @return array{0: PayrollRun, 1: PayrollLine} */
    protected function genRun(): array
    {
        $this->seedCore();
        Employee::create(['name' => 'موظفة', 'salary' => 1000, 'allow' => 200, 'status' => 'على رأس العمل']);
        $run = PayrollRun::create(['name' => 'مسيّر يوليو', 'month' => '2026-07', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate']);

        return [$run->fresh(), PayrollLine::where('run_id', $run->id)->firstOrFail()];
    }

    /** الصافي يشمل الإضافي والخصم والسلفة، والإجمالي يُعاد حسابه من السطور */
    public function test_adjust_recomputes_net_and_total(): void
    {
        [$run, $line] = $this->genRun();
        $this->assertSame(1200.0, (float) $line->net, 'التوليد: الصافي = الأساسي + البدلات');

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", [
            'do' => 'adjust',
            'ot' => [$line->id => 100],
            'de' => [$line->id => 50],
            'ad' => [$line->id => 150],
        ]);

        $fresh = PayrollLine::find($line->id);
        $this->assertSame(100.0, (float) $fresh->overtime);
        $this->assertSame(50.0, (float) $fresh->deduct);
        $this->assertSame(150.0, (float) $fresh->advance);
        // 1000 + 200 + 100 − 50 − 150 = 1100
        $this->assertSame(1100.0, (float) $fresh->net, 'الصافي لم يشمل الإضافي/الخصم/السلفة');
        $this->assertSame(1100.0, (float) $run->fresh()->total, 'الإجمالي لم يُعَد حسابه من السطور');
    }

    /** لا تعديل على مسيّرٍ غير مسودة — القيم مجمّدة بعد الاعتماد */
    public function test_adjust_blocked_on_non_draft(): void
    {
        [$run, $line] = $this->genRun();
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve']);
        $this->assertSame('معتمد', $run->fresh()->status);

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", [
            'do' => 'adjust', 'de' => [$line->id => 500],
        ])->assertStatus(422);

        $this->assertSame(1200.0, (float) PayrollLine::find($line->id)->net, 'عُدّل مسيّرٌ معتمد');
    }

    /** خصمٌ أو سلفةٌ تتجاوز المستحق: يُرفض — لا صافي سالب يشوّه القيد */
    public function test_adjust_rejects_negative_net(): void
    {
        [$run, $line] = $this->genRun();

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", [
            'do' => 'adjust', 'de' => [$line->id => 2000],
        ])->assertStatus(422);

        $this->assertSame(1200.0, (float) PayrollLine::find($line->id)->net, 'صافٍ سالبٌ مُرِّر');
    }

    /** التعديل يتطلّب صلاحية تعديل الرواتب — القارئ يُرفض */
    public function test_adjust_requires_edit_permission(): void
    {
        [$run, $line] = $this->genRun();

        $role = Role::create(['name' => 'قارئ رواتب', 'scope' => 'all',
            'matrix' => ['payroll' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]]);
        $viewer = User::create(['name' => 'قارئ', 'email' => 'viewer@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($viewer)->post("/payroll/{$run->id}/act", [
            'do' => 'adjust', 'ot' => [$line->id => 100],
        ])->assertStatus(403);

        $this->assertSame(1200.0, (float) PayrollLine::find($line->id)->net, 'كتب القارئ تعديلاً');
    }
}

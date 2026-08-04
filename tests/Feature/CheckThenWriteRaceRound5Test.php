<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الموجة الخامسة — دفعة v2.281.0: تسلسل مسارات «افحص ثم غيّر».
 *
 * ثلاثةُ مساراتٍ تفحص حالةً ثم تكتب بلا قفلٍ صفّيّ:
 * (١) `LeaveRequest::applyBalance` — سقفُ الرصيد يُفحَص في `saving` بقراءةٍ غير
 *     مقفولة، فاعتمادان متزامنان لنفس الموظف يخصمان تحت الصفر (رصيد سالب).
 * (٢) `ContractActionsController::spawnRenewal` — يفحص «مسودة تجديدٍ قائمة» بلا
 *     قفل، فزرٌّ+أتمتةٌ متزامنان يُنشئان مسودتَي تجديدٍ ويقلبان الأصلَ مرتين.
 * (٣) `EsignController::approve` — يفحص المرحلة المعلقة بلا قفل، فنقرتان على
 *     المرحلة الأخيرة تُسلّمان الطلبَ وتُطلقان حدثَ approved مرتين.
 *
 * الرصيد قابلٌ للاختبار سلوكيّاً (الخصم نفسه يرفض السالب تحت القفل)؛ والاثنان
 * الآخران فحصُهما استعلامُ قاعدةٍ لا نسخةٌ في الذاكرة، فسباقُهما لا يُحاكى بمنفذٍ
 * واحد — يُثبَت وجودُ الحارس الصفّيّ في المصدر (كـ PayrollJournalTest للمعاملة).
 */
class CheckThenWriteRaceRound5Test extends TestCase
{
    /** جسمُ دالةٍ بعينها: من تصريحها إلى تصريح الدالة التالية */
    protected function methodSrc(string $relPath, string $marker): string
    {
        $src = file_get_contents(app_path($relPath));
        $start = strpos($src, $marker);
        $this->assertNotFalse($start, "لم يُعثَر على {$marker}");
        $after = substr($src, (int) $start + strlen($marker));
        $next = PHP_INT_MAX;
        foreach (["\n    public function ", "\n    protected function ", "\n    private function ", "\n    public static function ", "\n    protected static function "] as $b) {
            $p = strpos($after, $b);
            if ($p !== false) $next = min($next, $p);
        }
        return $marker . ($next === PHP_INT_MAX ? $after : substr($after, 0, $next));
    }

    /** (١) الخصم يرفض الهبوط تحت الصفر تحت القفل — لا رصيد سالب */
    public function test_apply_balance_refuses_to_go_negative(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'leave_bal' => 5, 'status' => 'على رأس العمل']);

        $ref = new \ReflectionMethod(LeaveRequest::class, 'applyBalance');
        $ref->setAccessible(true);

        try {
            $ref->invoke(null, $emp, -10.0);   // خصمُ ١٠ من رصيد ٥
            $this->fail('خصمٌ يتجاوز الرصيد مرّ — رصيدٌ سالب');
        } catch (ValidationException $e) {
            // متوقّع
        }
        $this->assertSame(5.0, (float) $emp->fresh()->leave_bal, 'الرصيد هبط تحت المتاح');
    }

    /** الخصمُ الصحيح والاستعادةُ لا يُحجبان — لا إفراط في التقييد */
    public function test_apply_balance_allows_valid_deduction_and_restore(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'leave_bal' => 5, 'status' => 'على رأس العمل']);

        $ref = new \ReflectionMethod(LeaveRequest::class, 'applyBalance');
        $ref->setAccessible(true);

        $ref->invoke(null, $emp, -3.0);                               // ٥ ← ٢
        $this->assertSame(2.0, (float) $emp->fresh()->leave_bal);
        $ref->invoke(null, $emp, 3.0);                                // استعادة ← ٥
        $this->assertSame(5.0, (float) $emp->fresh()->leave_bal);
    }

    /** (٢) تجديد العقد يُنشَأ داخل معاملةٍ على صفٍّ مقفول */
    public function test_contract_renewal_spawn_is_row_locked(): void
    {
        $body = $this->methodSrc('Http/Controllers/Web/ContractActionsController.php', 'function spawnRenewal(');
        $this->assertStringContainsString('lockForUpdate', $body,
            'spawnRenewal بلا قفلٍ صفّيّ — زرٌّ+أتمتةٌ متزامنان يُنشئان مسودتَي تجديد');
        $this->assertStringContainsString('DB::transaction', $body);
    }

    /** (٣) اعتماد مرحلة التوقيع داخل معاملةٍ على صفٍّ مقفول */
    public function test_esign_stage_approve_is_row_locked(): void
    {
        $body = $this->methodSrc('Http/Controllers/Web/EsignController.php', 'public function approve(Request');
        $this->assertStringContainsString('lockForUpdate', $body,
            'اعتماد مرحلة التوقيع بلا قفل — تسليمٌ/حدثٌ مزدوج على المرحلة الأخيرة');
        $this->assertStringContainsString('DB::transaction', $body);
    }
}

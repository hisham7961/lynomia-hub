<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Http\Request;

/**
 * مسيّر الرواتب الحقيقي: كانت الوحدة عنواناً وإجمالياً يُكتب بالإصبع بلا
 * سطر منطقٍ واحد في المشروع كله. التوليد يبني سطراً لكل موظفٍ نشط من راتبه
 * وبدلاته، والاعتماد يقفل المسيّر ويولّد قيد يومية (خلف إعداد الترحيل الآلي)،
 * والصرف يختم تاريخه.
 */
class PayrollController extends Controller
{
    public function act(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'payroll', 'e'), 403, 'إجراءات المسيّر تتطلب صلاحية تعديل الرواتب');
        $run = hub_scope(PayrollRun::query(), 'payroll')->findOrFail($id);

        return match ((string) $r->input('do')) {
            'generate' => $this->generate($run),
            'approve'  => $this->approve($run),
            'pay'      => $this->pay($run),
            default    => abort(422),
        };
    }

    /** التوليد على المسودة فقط — يعاد بأمان: يمسح السطور ويبنيها من جديد */
    protected function generate(PayrollRun $run)
    {
        abort_unless($run->status === 'مسودة' || blank($run->status), 422,
            'المسيّر ' . $run->status . ' — التوليد على المسودة فقط');

        $emps = Employee::whereNull('deleted_at')
            ->whereNotIn('status', ['منتهية خدمته', 'مستقيل', 'موقوف'])
            ->when($run->company_id, fn ($q) => $q->where('company_id', $run->company_id))
            ->orderBy('name')->get();
        abort_if($emps->isEmpty(), 422, 'لا موظفين نشطين لهذا النطاق — أضف ملفاتهم الوظيفية أولاً');

        PayrollLine::where('run_id', $run->id)->delete();
        $total = 0.0;
        foreach ($emps as $e) {
            $base = (float) ($e->salary ?? 0);
            $allow = (float) ($e->allow ?? 0);
            $net = $base + $allow;
            $total += $net;
            PayrollLine::create(['run_id' => $run->id, 'emp_id' => $e->id,
                'base' => $base, 'allow' => $allow, 'net' => $net]);
        }

        $run->total = $total;
        $run->save();
        hub_audit('توليد مسيّر', 'payroll', $run->id, $run->name . ' — ' . $emps->count() . ' موظفاً');

        return back()->with('ok', '🧾 وُلّد المسيّر: ' . $emps->count() . ' موظفاً بإجمالي ' . number_format($total, 2)
            . ' — عدّل السطور إن لزم ثم اعتمده');
    }

    /** الاعتماد يقفل التوليد ويولّد قيد الرواتب (خلف إعداد الترحيل الآلي) */
    protected function approve(PayrollRun $run)
    {
        abort_unless($run->status === 'مسودة' || blank($run->status), 422, 'المسيّر ' . $run->status . ' أصلاً');
        abort_unless(hub_approver(), 403, 'اعتماد المسيّرات للمالكين وحاملي صلاحية الاعتماد');
        abort_if((float) $run->total <= 0 || ! PayrollLine::where('run_id', $run->id)->exists(), 422,
            'ولّد سطور المسيّر أولاً — لا يُعتمد مسيّر فارغ');

        $run->status = 'معتمد';
        $run->save();
        $this->autoJournal($run);
        hub_audit('اعتماد مسيّر', 'payroll', $run->id, (string) $run->name);

        return back()->with('ok', '✅ اعتُمد المسيّر — سجّل الصرف عند التحويل الفعلي');
    }

    protected function pay(PayrollRun $run)
    {
        abort_unless($run->status === 'معتمد', 422, 'اعتمد المسيّر أولاً ثم سجّل صرفه');
        $run->status = 'مدفوع';
        $run->pay_date = now()->toDateString();
        $run->save();

        return back()->with('ok', '💸 سُجّل صرف المسيّر بتاريخ اليوم');
    }

    /** قيد رواتب موزون: مدين مصروفات، دائن صندوق/بنك — من خريطة finance.accounts */
    protected function autoJournal(PayrollRun $run): void
    {
        if (setting('finance.auto_journal') !== '1') return;
        try {
            $map = setting('finance.accounts');
            $map = is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
            $accId = fn (?string $code) => blank($code) ? null
                : \App\Models\LedgerAccount::whereNull('deleted_at')->where('code', $code)->value('id');
            $exp = $accId($map['exp'] ?? null);
            $cash = $accId($map['bank'] ?? null) ?: $accId($map['cash'] ?? null);
            if (! $exp || ! $cash) return;

            $entry = \App\Models\JournalEntry::create([
                'doc_no' => 'JE-PAYROLL-' . now()->format('ymHis'),
                'date' => now()->toDateString(),
                'description' => 'قيد رواتب: ' . $run->name . ($run->month ? ' — ' . $run->month : ''),
                'reference' => (string) $run->name, 'state' => 'مرحّل',
                'company_id' => $run->company_id,
                'meta' => ['posted_at' => now()->toIso8601String(), 'auto' => 'payroll', 'payroll_id' => $run->id],
            ]);
            \App\Models\JournalLine::create(['entry_id' => $entry->id, 'acc_id' => $exp,
                'debit' => (float) $run->total, 'credit' => 0, 'memo' => 'مصروف رواتب']);
            \App\Models\JournalLine::create(['entry_id' => $entry->id, 'acc_id' => $cash,
                'debit' => 0, 'credit' => (float) $run->total, 'memo' => 'صرف الرواتب']);
        } catch (\Throwable $e) {
            report($e);   // القيد الآلي لا يُفشل الاعتماد نفسه
        }
    }
}

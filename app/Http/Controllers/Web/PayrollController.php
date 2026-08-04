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

        return match (hub_str($r->input('do'))) {
            'generate' => $this->generate($run),
            'adjust'   => $this->adjust($r, $run),
            'approve'  => $this->approve($run),
            'pay'      => $this->pay($run),
            default    => abort(422),
        };
    }

    /**
     * تعديلات المسودة: إضافي/خصم/سلفة لكل سطر. كانت الأعمدة معروضةً في الشاشة
     * ومخزَّنةً في الجدول **بلا مسار إدخالٍ إطلاقاً**، و`net` يتجاهلها — فالمحاسب
     * لا يستطيع إدخال خصمٍ ولا سلفة، ولو أمكنه لما دخلت الصافي ولا القيد.
     * الصافي = الأساسي + البدلات + الإضافي − الخصم − السلفة، والإجمالي من السطور.
     */
    protected function adjust(Request $r, PayrollRun $run)
    {
        abort_unless($run->status === 'مسودة' || blank($run->status), 422,
            'المسيّر ' . $run->status . ' — التعديل على المسودة فقط');

        $lines = PayrollLine::where('run_id', $run->id)->orderBy('id')->get();
        abort_if($lines->isEmpty(), 422, 'ولّد سطور المسيّر أولاً');

        // التحقق على الكل قبل كتابة أي سطر — لا كتابة نصفية عند رفض سطرٍ لاحق
        $dirty = [];
        foreach ($lines as $l) {
            $val = function (string $k) use ($r, $l) {
                $raw = data_get($r->input($k), $l->id);
                if ($raw === null || $raw === '') return null;                 // لم يُرسَل — يبقى كما هو
                abort_unless(is_numeric($raw), 422, 'قيمة تعديلٍ ليست رقماً');
                abort_if((float) $raw < 0, 422, 'قيمة التعديل لا تكون سالبة — الخصم يُدخل موجباً في خانته');
                return round((float) $raw, 3);
            };
            $ot = $val('ot') ?? (float) $l->overtime;
            $de = $val('de') ?? (float) $l->deduct;
            $ad = $val('ad') ?? (float) $l->advance;
            $net = round((float) $l->base + (float) $l->allow + $ot - $de - $ad, 3);
            abort_if($net < 0, 422,
                'الخصومات والسلف تتجاوز مستحق السطر (' . number_format($net, 2) . ') — لا صافي سالب في مسيّر');
            $dirty[$l->id] = ['overtime' => $ot, 'deduct' => $de, 'advance' => $ad, 'net' => $net];
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($lines, $dirty, $run) {
            foreach ($lines as $l) $l->update($dirty[$l->id]);
            $run->total = array_sum(array_column($dirty, 'net'));
            $run->save();
        });
        hub_audit('تعديل مسيّر', 'payroll', $run->id,
            $run->name . ' — إجمالي ' . number_format((float) $run->total, 2));

        return back()->with('ok', '💾 حُفظت التعديلات — الإجمالي الجديد ' . number_format((float) $run->total, 2));
    }

    /** التوليد على المسودة فقط — يعاد بأمان: يمسح السطور ويبنيها من جديد */
    protected function generate(PayrollRun $run)
    {
        abort_unless($run->status === 'مسودة' || blank($run->status), 422,
            'المسيّر ' . $run->status . ' — التوليد على المسودة فقط');

        // hub_scope على استعلام الموظفين: كان خاماً، فتشغيلةٌ بلا company_id
        // تسحب موظفي كل الشركات — ومنشئٌ معزولٌ بشركةٍ يسحب من لا يراهم ويخلط
        // عملاتهم في مجموعٍ وقيدٍ واحد. النطاق يحصر السحب بما يراه المنشئ.
        $emps = hub_scope(Employee::query(), 'hr')
            ->whereNull('deleted_at')
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
        abort_unless(hub_approver(), 403, 'اعتماد المسيّرات للمالكين وحاملي صلاحية الاعتماد');

        // معاملةٌ على صفٍّ مقفول: فحصُ الحالة + القلب + القيد يقرأ الحالة المُثبَتة
        // لا نسخةً قديمة في الذاكرة — فنقرتان متزامنتان (نقر مزدوج/تبويبان/معتمدان)
        // تتسلسلان فلا يُرحَّل قيدُ الرواتب مرتين (مصروفٌ مضاعف في الدفتر)، على نمط
        // FinController::pay و ApprovalDecisionController المقفولَين. القيدُ نفسه بلا
        // حاجزِ فرادةٍ في القاعدة (doc_no غير فريد)، فالقفلُ هو الحاجز.
        \Illuminate\Support\Facades\DB::transaction(function () use (&$run) {
            $run = PayrollRun::whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'مسودة' || blank($run->status), 422, 'المسيّر ' . $run->status . ' أصلاً');
            abort_if((float) $run->total <= 0 || ! PayrollLine::where('run_id', $run->id)->exists(), 422,
                'ولّد سطور المسيّر أولاً — لا يُعتمد مسيّر فارغ');

            $run->status = 'معتمد';
            $run->save();
            $this->autoJournal($run);
        });
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
        // بالسلسلة لا بالنوع — hub:set يخزّن العدد فتفشل === النوعية، فكان قيد
        // الرواتب لا يُرحَّل أبداً حين يُفعَّل الإعداد بالطريقة الموثّقة (مصروف
        // الرواتب لا يبلغ الدفتر → ربحٌ مُبالَغ). FinController أصلحها؛ هذا لحق به.
        if ((string) setting('finance.auto_journal') !== '1') return;
        try {
            $map = setting('finance.accounts');
            $map = is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
            // بترتيب id: code غير فريد في الدليل — قيدٌ يتوزّع على حسابين بالقرعة
            $accId = fn (?string $code) => blank($code) ? null
                : \App\Models\LedgerAccount::whereNull('deleted_at')->where('code', $code)
                    ->orderBy('id')->value('id');
            $exp = $accId($map['exp'] ?? null);
            $cash = $accId($map['bank'] ?? null) ?: $accId($map['cash'] ?? null);
            if (! $exp || ! $cash) return;

            // معاملةٌ تلفّ القيد وسطريه: فشلُ السطر الثاني كان يترك قيداً مرحّلاً
            // بسطرٍ واحد، وJournalEntry::booted يمنع تصحيحه أبداً → دفترٌ مختلٌّ للأبد
            \Illuminate\Support\Facades\DB::transaction(function () use ($run, $exp, $cash) {
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
            });
        } catch (\Throwable $e) {
            report($e);   // القيد الآلي لا يُفشل الاعتماد نفسه
        }
    }
}

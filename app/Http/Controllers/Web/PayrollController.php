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
            // **سقفٌ عدديّ من العمود نفسه** (v2.318): كان `round((float)…)` وحده،
            // فقيمةٌ تسع العمود (`decimal(16,3)`) تُفيض `net` أو مجموعَ المسيّر
            // فترمي MySQL `22003` غيرَ ملتقطة — ٥٠٠ على مسارٍ مصادَق، ونصُّ
            // الاستعلام وقيمُ الربط يُخزَّنان في مركز الأخطاء. والحدُّ يُطبَّق قبل
            // أيّ كتابة (الحلقةُ كلُّها تحقُّقٌ ثمّ كتابة).
            $cap = (float) (hub_col_num_max('payroll_lines', 'net') ?? 9999999999999.999);
            $val = function (string $k) use ($r, $l, $cap) {
                $raw = data_get($r->input($k), $l->id);
                if ($raw === null || $raw === '') return null;                 // لم يُرسَل — يبقى كما هو
                $n = hub_num($raw);
                abort_unless($n !== null, 422, 'قيمة تعديلٍ ليست رقماً');
                abort_if($n < 0, 422, 'قيمة التعديل لا تكون سالبة — الخصم يُدخل موجباً في خانته');
                abort_if($n > $cap, 422, 'قيمة التعديل تتجاوز ما يسعه الحقل — راجع الرقم');
                return round($n, 3);
            };
            $ot = $val('ot') ?? (float) $l->overtime;
            $de = $val('de') ?? (float) $l->deduct;
            $ad = $val('ad') ?? (float) $l->advance;
            $net = round((float) $l->base + (float) $l->allow + $ot - $de - $ad, 3);
            abort_if($net < 0, 422,
                'الخصومات والسلف تتجاوز مستحق السطر (' . number_format($net, 2) . ') — لا صافي سالب في مسيّر');
            abort_if($net > $cap, 422, 'صافي السطر يتجاوز ما يسعه الحقل — راجع التعديلات');
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
            // **NULL NOT IN (…) يُقيَّم «مجهولاً» لا «صحيحاً»** على المحرّكين معاً،
            // فموظفٌ بحالةٍ فارغة كان يسقط من كلّ مسيّرٍ صامتاً: راتبٌ لا يُصرَف
            // ولا رسالةَ تقول لماذا. «بلا حالة» ليست «منتهيةَ خدمته».
            ->where(fn ($q) => $q->whereNull('status')
                ->orWhereNotIn('status', ['منتهية خدمته', 'مستقيل', 'موقوف']))
            ->when($run->company_id, fn ($q) => $q->where('company_id', $run->company_id))
            ->orderBy('name')->get();
        abort_if($emps->isEmpty(), 422, 'لا موظفين نشطين لهذا النطاق — أضف ملفاتهم الوظيفية أولاً');

        // عملةٌ واحدة للتشغيلة: تشغيلةٌ بلا شركةٍ محدَّدة قد تضمّ موظفي شركاتٍ
        // بعملاتٍ مختلفة، فيُجمع الإجمالي والقيد على عملاتٍ غير متجانسة — كذبةُ
        // رقمٍ واحد. نرفضها ونطلب تحديد شركة التشغيلة كي تتوحّد العملة. (تشغيلةٌ
        // بشركةٍ محدَّدة موحّدةُ العملة سلفاً فلا يمسّها الحارس.)
        if (blank($run->company_id)) {
            $def = trim((string) setting('app.currency', ''));
            $curs = \App\Models\Company::whereIn('id', $emps->pluck('company_id')->filter()->unique()->all())
                ->pluck('currency')->map(fn ($c) => trim((string) $c) ?: $def);
            if ($emps->whereNull('company_id')->isNotEmpty()) $curs = $curs->push($def);
            $distinct = $curs->filter()->unique()->values();
            abort_if($distinct->count() > 1, 422,
                'تشغيلةٌ بلا شركة تجمع موظفين بعملاتٍ مختلفة (' . $distinct->implode('، ')
                . ') في مجموعٍ واحد — حدّد شركةَ التشغيلة كي تتوحّد العملة');
        }

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

            // الإجمالي مشتقٌّ من مجموع السطور: إن خالفه الحقلُ المخزّن (تلاعبٌ مباشر
            // في القاعدة أو تعديلٌ التفَّ على القفل) لا يُرحَّل قيدٌ يبني على رقمٍ
            // مختلَق — أعِد التوليد. والقيدُ يبني على المجموع لا على الحقل.
            $sum = (float) PayrollLine::where('run_id', $run->id)->sum('net');
            abort_if(abs($sum - (float) $run->total) > 0.005, 422,
                'إجمالي المسيّر (' . number_format((float) $run->total, 3) . ') يخالف مجموع سطوره ('
                . number_format($sum, 3) . ') — أعِد توليد المسيّر قبل اعتماده');
            $run->total = $sum;

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
            // تحصيرٌ بالشركة ثم بترتيب id: code غير فريد و company_id قد يتكرّر
            // عبر الشركات، فبلا التحصير يلتصق سطرُ قيدِ مسيّرٍ بحساب شركةٍ أخرى.
            // نفضّل حساب شركة المسيّر، فحساباً عامّاً احتياطاً، وid يحسم التعادل.
            $accId = function (?string $code) use ($run) {
                if (blank($code)) return null;

                return \App\Models\LedgerAccount::whereNull('deleted_at')->where('code', $code)
                    ->where(function ($w) use ($run) {
                        $w->whereNull('company_id');
                        if (filled($run->company_id)) $w->orWhere('company_id', $run->company_id);
                    })
                    ->orderByRaw('company_id IS NULL')   // الخاصّ بالشركة أولاً، العامّ احتياطاً
                    ->orderBy('id')->value('id');
            };
            $exp = $accId($map['exp'] ?? null);
            $cash = $accId($map['bank'] ?? null) ?: $accId($map['cash'] ?? null);
            if (! $exp || ! $cash) return;

            // معاملةٌ تلفّ القيد وسطريه: فشلُ السطر الثاني كان يترك قيداً مرحّلاً
            // بسطرٍ واحد، وJournalEntry::booted يمنع تصحيحه أبداً → دفترٌ مختلٌّ للأبد
            // المولّد الداخلي يبني القيدَ مرحَّلاً ثمّ سطريه — الرايةُ حول كتلته
            // ليمرّ حارسُ التوازن في النموذج (v2.314)
            \App\Models\JournalEntry::$posting = true;
            try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($run, $exp, $cash) {
                $entry = \App\Models\JournalEntry::create([
                    'doc_no' => hub_fit('JE-PAYROLL-' . now()->format('ymHis'),
                        hub_col_max('journal_entries', 'doc_no') ?? 300),
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
            } finally {
                \App\Models\JournalEntry::$posting = false;
            }
        } catch (\Throwable $e) {
            report($e);   // القيد الآلي لا يُفشل الاعتماد نفسه
        }
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;

/**
 * سطور القيود المزدوجة: كان القيد رأساً بلا جسم. السطور تُضاف وتُحذف على
 * المسودة فقط، والترحيل يرفض ما لا يوازن (المدين = الدائن > صفر) ويقفل
 * القيد نهائياً — القفل مفروض في النموذج نفسه فلا تلتف عليه واجهة ولا API.
 */
class EntryController extends Controller
{
    protected function entry(string $id, string $op = 'e'): JournalEntry
    {
        abort_unless(hub_can(auth()->user(), 'entries', $op), 403, 'سطور القيود تتطلب صلاحية تعديل القيود');

        return hub_scope(JournalEntry::query(), 'entries')->findOrFail($id);
    }

    public function line(Request $r, string $id)
    {
        $e = $this->entry($id);
        abort_if($e->state === 'مرحّل', 422, 'القيد المُرحَّل مقفل');

        $d = $r->validate([
            'accId'  => 'required|string|exists:ledger_accounts,id',
            'ccId'   => 'nullable|string|exists:cost_centers,id',
            'debit'  => 'nullable|numeric|min:0',
            'credit' => 'nullable|numeric|min:0',
            'memo'   => 'nullable|string|max:300',
        ], [], ['accId' => 'الحساب', 'debit' => 'مدين', 'credit' => 'دائن', 'memo' => 'البيان']);

        // **تنطيقٌ لا `exists` مجرّدة** (v2.314): القاعدةُ تُثبت وجودَ الحساب لا
        // حقَّ القارئ فيه، فسطرُ قيدٍ لشركةٍ كان يُعلَّق على حساب دفترِ شركةٍ
        // أخرى — تلوّثٌ مرجعيّ عابرٌ للمستأجرين على مسار كتابة، وهو بعينه ما
        // يحرسه FinalAuditLedgerCompanyTest على المسار الآليّ. على نمط pay().
        abort_unless(hub_scope(\App\Models\LedgerAccount::query(), 'accounts2')
            ->whereKey($d['accId'])->whereNull('deleted_at')->exists(), 422,
            'هذا الحساب خارج نطاقك — اختر حساباً من دليل شركتك');
        if (! empty($d['ccId'])) {
            abort_unless(hub_scope(\App\Models\CostCenter::query(), 'costc')
                ->whereKey($d['ccId'])->whereNull('deleted_at')->exists(), 422,
                'مركز التكلفة خارج نطاقك');
        }

        $debit = (float) ($d['debit'] ?? 0);
        $credit = (float) ($d['credit'] ?? 0);
        abort_if($debit <= 0 && $credit <= 0, 422, 'أدخل مبلغاً مديناً أو دائناً');
        abort_if($debit > 0 && $credit > 0, 422, 'السطر الواحد إما مدين أو دائن لا كلاهما');

        $this->addLineTo($e, [
            'acc_id' => $d['accId'], 'cc_id' => $d['ccId'] ?? null,
            'debit' => $debit, 'credit' => $credit, 'memo' => $d['memo'] ?? null,
        ]);

        return back()->with('ok', 'أُضيف السطر');
    }

    /**
     * إضافة سطرٍ ذرّيّة: تُعيد قراءة حالة القيد من صفٍّ مقفول داخل معاملة قبل
     * الكتابة. فحصُ الحالة في line() يقرأ نسخةً في الذاكرة، وبين قراءته وكتابة
     * السطر قد يُرحّل القيدُ معالجٌ آخر (post) فيهبط السطر على قيدٍ مقفول غير
     * موزون لا مسار لإصلاحه. القفل الصفّيّ هنا يسلسل الإضافة مع الترحيل: من
     * يمسك القفل أولاً يفوز، والثاني يرى الحالة المُحدَّثة فيُرفض.
     */
    protected function addLineTo(JournalEntry $e, array $attrs): JournalLine
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($e, $attrs) {
            $fresh = JournalEntry::whereKey($e->id)->lockForUpdate()->firstOrFail();
            abort_if($fresh->state === 'مرحّل', 422, 'القيد المُرحَّل مقفل — رُحِّل قبل إضافة السطر');

            return JournalLine::create(['entry_id' => $fresh->id] + $attrs);
        });
    }

    public function dropLine(string $id, string $lineId)
    {
        $e = $this->entry($id);
        // معاملةٌ بقفلٍ صفّيّ كنمط addLineTo/post: القراءةُ الطازجة تمنع الحذفَ عن
        // قيدٍ مُرحَّلٍ سلفاً، لكن بلا القفل يبقى شقٌّ بين الفحص والحذف يهبط فيه
        // السطرُ على قيدٍ رُحِّل بيننا (ترحيلٌ متزامن) — ميزانٌ مختلٌّ مقفولٌ أبداً.
        \Illuminate\Support\Facades\DB::transaction(function () use ($e, $lineId) {
            $fresh = JournalEntry::whereKey($e->id)->lockForUpdate()->firstOrFail();
            abort_if($fresh->state === 'مرحّل', 422, 'القيد المُرحَّل مقفل — رُحِّل قبل حذف السطر');
            JournalLine::where('entry_id', $fresh->id)->where('id', $lineId)->delete();
        });

        return back()->with('ok', 'حُذف السطر');
    }

    public function post(string $id)
    {
        $e = $this->entry($id);
        abort_if($e->state === 'مرحّل', 422, 'القيد مُرحَّل أصلاً');

        // معاملةٌ بقفلٍ صفّيّ: الترحيل يعيد قراءة الحالة والسطور من صفٍّ مقفول،
        // فيسلسل نفسه مع إضافة السطر (addLineTo). سطرٌ يُضاف بينما نمسك القفل
        // ينتظر حتى ننتهي فيرى الحالة «مرحّل» ويُرفض؛ وإن سبقنا هو دخل مجموعَ
        // التوازن. والفحص المُعاد يمنع ترحيلاً مزدوجاً من نسختين قديمتين.
        \Illuminate\Support\Facades\DB::transaction(function () use ($e) {
            $fresh = JournalEntry::whereKey($e->id)->lockForUpdate()->firstOrFail();
            abort_if($fresh->state === 'مرحّل', 422, 'القيد مُرحَّل أصلاً');

            $debit = (float) JournalLine::where('entry_id', $fresh->id)->sum('debit');
            $credit = (float) JournalLine::where('entry_id', $fresh->id)->sum('credit');
            abort_if($debit <= 0, 422, 'لا يُرحَّل قيدٌ بلا سطور');
            abort_if(round($debit, 3) !== round($credit, 3), 422,
                'القيد لا يوازن: مدين ' . number_format($debit, 3) . ' ≠ دائن ' . number_format($credit, 3));

            $fresh->state = 'مرحّل';
            $fresh->meta = (array) $fresh->meta + ['posted_at' => now()->toIso8601String(), 'posted_by' => auth()->id()];
            $fresh->save();
            hub_audit('ترحيل قيد', 'entries', $fresh->id, (string) $fresh->doc_no);
        });

        return back()->with('ok', '🔏 رُحّل القيد وقُفل — يُعكس بقيدٍ جديد لا بالتعديل');
    }
}

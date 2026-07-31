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

        $debit = (float) ($d['debit'] ?? 0);
        $credit = (float) ($d['credit'] ?? 0);
        abort_if($debit <= 0 && $credit <= 0, 422, 'أدخل مبلغاً مديناً أو دائناً');
        abort_if($debit > 0 && $credit > 0, 422, 'السطر الواحد إما مدين أو دائن لا كلاهما');

        JournalLine::create([
            'entry_id' => $e->id, 'acc_id' => $d['accId'], 'cc_id' => $d['ccId'] ?? null,
            'debit' => $debit, 'credit' => $credit, 'memo' => $d['memo'] ?? null,
        ]);

        return back()->with('ok', 'أُضيف السطر');
    }

    public function dropLine(string $id, string $lineId)
    {
        $e = $this->entry($id);
        abort_if($e->state === 'مرحّل', 422, 'القيد المُرحَّل مقفل');
        JournalLine::where('entry_id', $e->id)->where('id', $lineId)->delete();

        return back()->with('ok', 'حُذف السطر');
    }

    public function post(string $id)
    {
        $e = $this->entry($id);
        abort_if($e->state === 'مرحّل', 422, 'القيد مُرحَّل أصلاً');

        $debit = (float) JournalLine::where('entry_id', $e->id)->sum('debit');
        $credit = (float) JournalLine::where('entry_id', $e->id)->sum('credit');
        abort_if($debit <= 0, 422, 'لا يُرحَّل قيدٌ بلا سطور');
        abort_if(round($debit, 3) !== round($credit, 3), 422,
            'القيد لا يوازن: مدين ' . number_format($debit, 3) . ' ≠ دائن ' . number_format($credit, 3));

        $e->state = 'مرحّل';
        $e->meta = (array) $e->meta + ['posted_at' => now()->toIso8601String(), 'posted_by' => auth()->id()];
        $e->save();
        hub_audit('ترحيل قيد', 'entries', $e->id, (string) $e->doc_no);

        return back()->with('ok', '🔏 رُحّل القيد وقُفل — يُعكس بقيدٍ جديد لا بالتعديل');
    }
}

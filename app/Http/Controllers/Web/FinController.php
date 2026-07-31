<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\FinDocument;
use Illuminate\Http\Request;

/**
 * إجراءات المستند المالي — «سجّل دفعة» أولها: كان حقل «المدفوع» يدوياً محضاً
 * والحالات الثلاث (مدفوعة جزئياً/مدفوعة/متأخرة) معرّفةً بلا قائدٍ يحركها،
 * ورصيد البنوك جامداً لا تمسّه دفعة. الدفعة الآن: تزيد المدفوع، تنقل الحالة
 * آلياً، تحرّك رصيد البنك باتجاه نوع المستند، وتُطلق invoice.paid عند الاكتمال.
 */
class FinController extends Controller
{
    public function act(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'fin', 'e'), 403, 'تسجيل الدفعات يتطلب صلاحية تعديل المالية');
        $doc = hub_scope(FinDocument::query(), 'fin')->findOrFail($id);

        return match ((string) $r->input('do')) {
            'pay'   => $this->pay($r, $doc),
            default => abort(422),
        };
    }

    protected function pay(Request $r, FinDocument $doc)
    {
        abort_if(in_array((string) $doc->state, config('hub.fin.dead'), true), 422,
            'لا دفعات على مستند ملغى أو مسودة — فعّله أولاً');

        $d = $r->validate([
            'amount' => 'required|numeric|min:0.001',
            'bankId' => 'nullable|string|exists:bank_accounts,id',
        ], [], ['amount' => 'مبلغ الدفعة', 'bankId' => 'الحساب البنكي']);

        $paid = (float) ($doc->paid ?? 0);
        $total = (float) ($doc->total ?? 0);
        $remain = max(0, $total - $paid);
        abort_if($remain <= 0, 422, 'المستند مسدّد بالكامل أصلاً');

        // لا دفعة تتجاوز المتبقي — الفائض خطأ إدخال لا إيراد
        $amount = min((float) $d['amount'], $remain);

        if (! empty($d['bankId'])) $doc->bank_id = $d['bankId'];
        $doc->paid = $paid + $amount;
        $prev = (string) $doc->state;
        $doc->state = $doc->paid >= $total ? 'مدفوعة' : 'مدفوعة جزئياً';
        $doc->save();

        // رصيد البنك يتحرك باتجاه المستند: قبضٌ للدخل وصرفٌ للمصروف
        if ($doc->bank_id && ($bank = BankAccount::find($doc->bank_id))) {
            $sign = in_array((string) $doc->kind, config('hub.fin.income'), true) ? 1 : -1;
            $bank->balance = (float) ($bank->balance ?? 0) + $sign * $amount;
            $bank->saveQuietly();   // حركة مشتقة من الدفعة الموثقة — لا ضجيج تدقيق مزدوج
        }

        if ($doc->state !== $prev) {
            \App\Support\FlowRunner::fire('status', 'fin', $doc, $doc->state);
        }
        hub_audit('دفعة', 'fin', $doc->id,
            ($doc->doc_no ?: $doc->id) . ' — ' . number_format($amount, 2) . ' ' . ($doc->currency ?: ''));

        $this->autoJournal($doc, $amount);

        return back()->with('ok', $doc->state === 'مدفوعة'
            ? '💰 سُدّد المستند بالكامل'
            : '💰 سُجّلت الدفعة — المتبقي ' . number_format($total - (float) $doc->paid, 2));
    }

    /**
     * قيد يومية آلي للدفعة — أول قارئ لخريطة finance.accounts المبذورة منذ
     * البداية بلا مستهلك. خلف إعداد finance.auto_journal (معطل افتراضياً —
     * قرار الترحيل الآلي للمنشأة لا لنا): قبضُ دخلٍ يدين البنك/الصندوق ويُدين
     * المبيعات، وصرفُ مصروفٍ يعكس. القيد يولد مُرحَّلاً مقفلاً بسطرين موزونين.
     */
    protected function autoJournal(FinDocument $doc, float $amount): void
    {
        if (setting('finance.auto_journal') !== '1') return;

        try {
            $map = setting('finance.accounts');
            $map = is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
            $income = in_array((string) $doc->kind, config('hub.fin.income'), true);
            $moneyCode = (string) ($doc->bank_id ? ($map['bank'] ?? '') : ($map['cash'] ?? ''));
            $otherCode = (string) ($income ? ($map['sales'] ?? '') : ($map['exp'] ?? ''));

            $accId = fn (string $code) => $code === '' ? null
                : \App\Models\LedgerAccount::whereNull('deleted_at')->where('code', $code)->value('id');
            $money = $accId($moneyCode);
            $other = $accId($otherCode);
            if (! $money || ! $other) return;   // خريطة غير مكتملة — لا قيد أعرج

            $entry = \App\Models\JournalEntry::create([
                'doc_no' => 'JE-' . ($doc->doc_no ?: substr($doc->id, 0, 8)) . '-' . now()->format('His'),
                'date' => now()->toDateString(),
                'description' => ($income ? 'قبض' : 'صرف') . ' دفعة على ' . ($doc->doc_no ?: $doc->id),
                'reference' => (string) $doc->doc_no,
                'state' => 'مرحّل',
                'fin_id' => $doc->id,
                'project_id' => $doc->project_id,
                'company_id' => $doc->company_id,
                'meta' => ['posted_at' => now()->toIso8601String(), 'auto' => 'payment'],
            ]);
            \App\Models\JournalLine::create(['entry_id' => $entry->id, 'cc_id' => $doc->cc_id,
                'acc_id' => $income ? $money : $other, 'debit' => $amount, 'credit' => 0,
                'memo' => $income ? 'قبض الدفعة' : 'المصروف']);
            \App\Models\JournalLine::create(['entry_id' => $entry->id, 'cc_id' => $doc->cc_id,
                'acc_id' => $income ? $other : $money, 'debit' => 0, 'credit' => $amount,
                'memo' => $income ? 'الإيراد' : 'سداد الدفعة']);
        } catch (\Throwable $e) {
            report($e);   // القيد الآلي لا يُفشل تسجيل الدفعة نفسها
        }
    }
}

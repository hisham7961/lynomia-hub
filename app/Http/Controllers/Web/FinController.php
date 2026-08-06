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

        return match (hub_str($r->input('do'))) {
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

        /*
         * **تحريكُ بنكٍ كتابةٌ في وحدة البنوك** — الحرّاسُ الثلاثة (صلاحية/نطاق/عملة)
         * على **البنك الفعليّ** الذي يتحرّك رصيده، داخل المعاملة قبل التحريك: كانت
         * مشروطةً بوسيط `bankId`، فمستندٌ ضُبط بنكُه عبر النموذج (exists وحدها) ثم دُفع
         * بلا bankId يحرّك رصيدَ بنكٍ خارج الصلاحية/النطاق/العملة عبر البابِ الجانبيّ.
         *
         * **معاملةٌ وقفل**: كانت الدفعة ثلاث كتاباتٍ متفرقة (مستند، بنك، قيد)
         * بلا معاملة — تداخلُ نقرتين يكرّر القيدَ ويشوّه الرصيد. القفلُ الصفّي
         * يسلسل الدفعات على المستند الواحد، والمعاملةُ تجعلها كلَّها أو لا شيء.
         */
        $prev = (string) $doc->state;
        $amount = \Illuminate\Support\Facades\DB::transaction(function () use ($d, $doc) {
            $fresh = FinDocument::lockForUpdate()->findOrFail($doc->id);

            $paid = (float) ($fresh->paid ?? 0);
            $total = (float) ($fresh->total ?? 0);
            $remain = max(0, $total - $paid);
            abort_if($remain <= 0, 422, 'المستند مسدّد بالكامل أصلاً');

            // لا دفعة تتجاوز المتبقي — الفائض خطأ إدخال لا إيراد
            $amount = min((float) $d['amount'], $remain);

            if (! empty($d['bankId'])) $fresh->bank_id = $d['bankId'];

            // الحرّاسُ الثلاثة على البنك الفعليّ الذي يتحرّك رصيده — لا على وسيط
            // الطلب. abort داخل المعاملة يُرجِعها كاملةً فلا تُسجَّل دفعةٌ بلا بنكٍ مأذون.
            if ($fresh->bank_id) {
                abort_unless(hub_can(auth()->user(), 'banks', 'e'), 422,
                    'توجيه الدفعة لحساب بنكي يحرّك رصيده — ويتطلب صلاحية تعديل البنوك');
                abort_unless(hub_scope(BankAccount::query(), 'banks')->whereKey($fresh->bank_id)->exists(), 422,
                    'الحساب البنكي خارج نطاقك — اختر حساباً من شركاتك');
                $bcur = (string) BankAccount::whereKey($fresh->bank_id)->value('currency');
                $dcur = (string) ($fresh->currency ?? '');
                abort_if($bcur !== '' && $dcur !== '' && $bcur !== $dcur, 422,
                    "عملة الحساب ({$bcur}) تخالف عملة المستند ({$dcur}) — لا تحويلَ أسعارٍ في النظام، اختر حساباً بعملة المستند");
            }

            $fresh->paid = $paid + $amount;
            $fresh->state = $fresh->paid >= $total ? 'مدفوعة' : 'مدفوعة جزئياً';
            $fresh->save();

            // رصيد البنك يتحرك باتجاه المستند: قبضٌ للدخل وصرفٌ للمصروف
            if ($fresh->bank_id && ($bank = BankAccount::lockForUpdate()->find($fresh->bank_id))) {
                $sign = in_array((string) $fresh->kind, config('hub.fin.income'), true) ? 1 : -1;
                $bank->balance = (float) ($bank->balance ?? 0) + $sign * $amount;
                $bank->saveQuietly();   // حركة مشتقة من الدفعة الموثقة — لا ضجيج تدقيق مزدوج
            }

            // تُنسخ الحصيلة للنموذج الخارجي كي يكمل ما بعد المعاملة عليها
            $doc->setRawAttributes($fresh->getAttributes(), true);

            return $amount;
        });
        $total = (float) ($doc->total ?? 0);

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
        // بالسلسلة لا بالنوع — hub:set يخزّن العدد فتفشل === النوعية (داء contracts.auto_expire نفسه)
        if ((string) setting('finance.auto_journal') !== '1') return;

        try {
            $map = setting('finance.accounts');
            $map = is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
            $income = in_array((string) $doc->kind, config('hub.fin.income'), true);
            $moneyCode = (string) ($doc->bank_id ? ($map['bank'] ?? '') : ($map['cash'] ?? ''));
            $otherCode = (string) ($income ? ($map['sales'] ?? '') : ($map['exp'] ?? ''));

            // تحصيرٌ بالشركة ثم بترتيب id: code غير فريد و company_id قد يتكرّر
            // عبر الشركات، فبلا التحصير يلتصق سطرُ قيدِ شركةٍ بحساب شركةٍ أخرى.
            // نفضّل حساب شركة المستند، فحساباً عامّاً (company_id فارغ) احتياطاً،
            // وترتيبُ id يحسم التعادل حتميّاً على المحرّكين — لا قرعة.
            $accId = function (string $code) use ($doc) {
                if ($code === '') return null;

                return \App\Models\LedgerAccount::whereNull('deleted_at')->where('code', $code)
                    ->where(function ($w) use ($doc) {
                        $w->whereNull('company_id');
                        if (filled($doc->company_id)) $w->orWhere('company_id', $doc->company_id);
                    })
                    ->orderByRaw('company_id IS NULL')   // الخاصّ بالشركة أولاً، العامّ احتياطاً
                    ->orderBy('id')->value('id');
            };
            $money = $accId($moneyCode);
            $other = $accId($otherCode);
            if (! $money || ! $other) return;   // خريطة غير مكتملة — لا قيد أعرج

            // معاملةٌ تلفّ القيد وسطريه: فشلُ السطر الثاني كان يترك قيداً مرحّلاً
            // بسطرٍ واحد، وJournalEntry::booted يمنع تصحيحه أبداً → دفترٌ مختلٌّ للأبد
            // المولّد الداخلي يبني القيدَ مرحَّلاً ثمّ سطريه، فيرفع الرايةَ حول
            // كتلته ليمرّ حارسُ التوازن في النموذج (v2.314) — والمعاملةُ تضمن
            // أنّ القيد لا يبقى بسطرٍ واحد إن تعثّر الثاني.
            \App\Models\JournalEntry::$posting = true;
            try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($doc, $amount, $income, $money, $other) {
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
            });
            } finally {
                \App\Models\JournalEntry::$posting = false;
            }
        } catch (\Throwable $e) {
            report($e);   // القيد الآلي لا يُفشل تسجيل الدفعة نفسها
        }
    }
}

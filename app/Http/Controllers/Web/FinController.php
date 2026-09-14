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
            'pay'     => $this->pay($r, $doc),
            'reverse' => $this->reverse($r, $doc),   // (الجولة 1 · F15) عكسُ دفعةٍ موثَّقٌ بسبب
            default   => abort(422),
        };
    }

    /**
     * (الجولة 1 · F16) **حرّاسُ البنك الثلاثة** — مُستخرَجةٌ لتُشارَك بين الدفعة وعكسِها:
     * الصلاحيةُ (banks:e الكاملة **أو** المفتاحُ الدقيق `bankPost` — قيدُ قبضٍ/صرفٍ يحرّك
     * الرصيدَ دون تحريرِ الحسابات، فلا يبقى المحاسبُ مردوداً ٤٢٢ والأرصدةُ جامدة)،
     * والنطاقُ، والعملةُ. تُستدعى داخل المعاملة على البنك الفعليّ الذي يتحرّك رصيده.
     */
    protected function guardBankPosting(FinDocument $fresh): void
    {
        $u = auth()->user();
        abort_unless(hub_can($u, 'banks', 'e') || hub_can($u, 'banks', 'bankPost'), 422,
            'توجيه الدفعة لحساب بنكي يحرّك رصيده — ويتطلب صلاحية تعديل البنوك أو مفتاح «قيد قبضٍ/صرفٍ بنكيّ»');
        abort_unless(hub_scope(BankAccount::query(), 'banks')->whereKey($fresh->bank_id)->exists(), 422,
            'الحساب البنكي خارج نطاقك — اختر حساباً من شركاتك');
        $bcur = (string) BankAccount::whereKey($fresh->bank_id)->value('currency');
        $dcur = (string) ($fresh->currency ?? '');
        abort_if($bcur !== '' && $dcur !== '' && $bcur !== $dcur, 422,
            "عملة الحساب ({$bcur}) تخالف عملة المستند ({$dcur}) — لا تحويلَ أسعارٍ في النظام، اختر حساباً بعملة المستند");
    }

    /**
     * (الجولة 1 · F15) **الدفعةُ سجلٌّ محترم**: تاريخُها ومرجعُها وملاحظتُها وكاتبُها
     * تُلحَق قيداً في `meta.payments` على المستند نفسِه (السكّةُ القائمة — لا جدولَ
     * ولا محرّكَ ثانٍ)، فيبقى لكلّ حركةٍ أثرٌ يُقرأ ويُعرَض ويُعكَس عن بيّنة.
     */
    protected function appendPaymentRecord(FinDocument $fresh, float $amount, array $extra = []): void
    {
        $meta = (array) ($fresh->meta ?? []);
        $meta['payments'][] = $extra + [
            'amount' => round($amount, 3),
            'bank'   => (string) ($fresh->bank_id ?? ''),
            'by'     => (string) auth()->id(),
            'ts'     => now()->toIso8601String(),
        ];
        $fresh->meta = $meta;
    }

    protected function pay(Request $r, FinDocument $doc)
    {
        abort_if(in_array((string) $doc->state, config('hub.fin.dead'), true), 422,
            'لا دفعات على مستند ملغى أو مسودة — فعّله أولاً');

        // (الجولة 1 · F15) المبلغُ بفواصلَ («2,50.00») لا يُفسَّر بصمت (كان يُقرأ 250.00) —
        // يُردّ برسالةٍ تسمّي الصيغةَ الصريحة، والواجهةُ تعرض معاينةَ المبلغ المفسَّر قبل الحفظ.
        // وتاريخُ الدفعة ومرجعُها وملاحظتُها تُقبل اختيارياً فتُوثَّق سجلاً (meta.payments).
        $d = $r->validate([
            'amount'  => 'required|numeric|min:0.001',
            'bankId'  => 'nullable|string|exists:bank_accounts,id',
            'payDate' => 'nullable|date',
            'payRef'  => 'nullable|string|max:200',
            'payNote' => 'nullable|string|max:500',
        ], [
            'amount.numeric' => 'مبلغ الدفعة غير مقروء — اكتبه رقماً عشرياً صريحاً بلا فواصل آلاف (مثل 2.500 أو 2500.000)',
        ], ['amount' => 'مبلغ الدفعة', 'bankId' => 'الحساب البنكي',
            'payDate' => 'تاريخ الدفعة', 'payRef' => 'مرجع الدفعة', 'payNote' => 'ملاحظة الدفعة']);

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

            // **والحارسُ يُعاد داخل القفل**: فحصُ الحالة الميتة أعلاه يقع على
            // النموذج المحمَّل قبل المعاملة، فبين الفحص والكتابة نافذةٌ يُلغى
            // فيها المستندُ من طلبٍ متزامن — فتُسجَّل دفعةٌ ويتحرّك رصيدُ بنكٍ
            // ويُرحَّل قيدٌ على مستندٍ ملغى، والقيدُ المرحَّل لا يُصحَّح.
            abort_if(in_array((string) $fresh->state, (array) config('hub.fin.dead'), true), 422,
                'لا دفعات على مستند ملغى أو مسودة — فعّله أولاً');

            $paid = (float) ($fresh->paid ?? 0);
            $total = (float) ($fresh->total ?? 0);
            $remain = max(0, $total - $paid);
            abort_if($remain <= 0, 422, 'المستند مسدّد بالكامل أصلاً');

            // لا دفعة تتجاوز المتبقي — الفائض خطأ إدخال لا إيراد
            $amount = min((float) $d['amount'], $remain);

            if (! empty($d['bankId'])) $fresh->bank_id = $d['bankId'];

            // الحرّاسُ الثلاثة على البنك الفعليّ الذي يتحرّك رصيده — لا على وسيط
            // الطلب. abort داخل المعاملة يُرجِعها كاملةً فلا تُسجَّل دفعةٌ بلا بنكٍ مأذون.
            // (F16) banks:e **أو** المفتاحُ الدقيق bankPost — داخل guardBankPosting.
            if ($fresh->bank_id) {
                $this->guardBankPosting($fresh);
            }

            $fresh->paid = $paid + $amount;
            $fresh->state = $fresh->paid >= $total ? 'مدفوعة' : 'مدفوعة جزئياً';
            // (F15) سجلُّ الدفعة: المبلغُ المفسَّر وتاريخُها ومرجعُها وملاحظتُها وكاتبُها
            $this->appendPaymentRecord($fresh, $amount, [
                'at'   => (string) ($d['payDate'] ?? now()->toDateString()),
                'ref'  => (string) ($d['payRef'] ?? ''),
                'note' => (string) ($d['payNote'] ?? ''),
            ]);
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
            ($doc->doc_no ?: $doc->id) . ' — ' . number_format($amount, 2) . ' ' . ($doc->currency ?: '')
            . (! empty($d['payDate']) ? ' — بتاريخ ' . $d['payDate'] : '')
            . (! empty($d['payRef']) ? ' — مرجع: ' . $d['payRef'] : ''));

        $this->autoJournal($doc, $amount);

        return back()->with('ok', $doc->state === 'مدفوعة'
            ? '💰 سُدّد المستند بالكامل'
            : '💰 سُجّلت الدفعة — المتبقي ' . number_format($total - (float) $doc->paid, 2));
    }

    /**
     * (الجولة 1 · F15) **عكسُ دفعة** — خطأُ الإدخال (2,50.00 فُسّرت 250.00) كان يُسجَّل
     * بصمتٍ بلا سبيلِ تراجع. على السكّة القائمة لا محرّكَ ثانٍ: نفسُ معاملةِ الدفعة
     * وقفلِها، ينقص المدفوعَ ويعيد اشتقاقَ الحالة ويحرّك رصيدَ البنك عكسيّاً ويولّد
     * قيدَ يوميةٍ معاكساً — بسببٍ **إلزاميّ** يُقيَّد في التدقيق وسجلِّ `meta.payments`.
     */
    protected function reverse(Request $r, FinDocument $doc)
    {
        $d = $r->validate([
            'amount' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:500',
        ], [
            'amount.numeric'  => 'مبلغ العكس غير مقروء — اكتبه رقماً عشرياً صريحاً بلا فواصل آلاف',
            'reason.required' => 'سببُ العكس إلزاميّ — يُوثَّق في سجل التدقيق فلا يقع تراجعٌ ماليّ بلا أثر',
        ], ['amount' => 'مبلغ العكس', 'reason' => 'سبب العكس']);

        $prev = (string) $doc->state;
        $amount = \Illuminate\Support\Facades\DB::transaction(function () use ($d, $doc) {
            $fresh = FinDocument::lockForUpdate()->findOrFail($doc->id);

            $paid = round((float) ($fresh->paid ?? 0), 3);
            abort_if($paid <= 0, 422, 'لا مدفوعَ على هذا المستند ليُعكس');
            $amount = round((float) $d['amount'], 3);
            abort_if($amount > $paid, 422,
                'مبلغ العكس (' . number_format($amount, 3) . ') أكبر من المدفوع (' . number_format($paid, 3) . ') — لا يُعكس ما لم يُدفع');

            // العكسُ يحرّك رصيدَ البنك كما حرّكته الدفعة — نفسُ الحرّاس الثلاثة (F16)
            if ($fresh->bank_id) {
                $this->guardBankPosting($fresh);
            }

            $fresh->paid = round($paid - $amount, 3);
            // إعادةُ اشتقاق الحالة من المدفوع المتبقي — الحالاتُ الميتة (ملغاة/مسودة) لا تُمسّ
            if (in_array((string) $fresh->state, ['مدفوعة', 'مدفوعة جزئياً'], true)) {
                $total = round((float) ($fresh->total ?? 0), 3);
                $fresh->state = $fresh->paid <= 0 ? 'معتمدة'
                    : ($fresh->paid >= $total ? 'مدفوعة' : 'مدفوعة جزئياً');
            }
            $this->appendPaymentRecord($fresh, -$amount, ['reason' => (string) $d['reason']]);
            $fresh->save();

            // رصيدُ البنك يعود عكسَ اتجاه المستند: قبضٌ يُنقَص وصرفٌ يُعاد
            if ($fresh->bank_id && ($bank = BankAccount::lockForUpdate()->find($fresh->bank_id))) {
                $sign = in_array((string) $fresh->kind, config('hub.fin.income'), true) ? -1 : 1;
                $bank->balance = (float) ($bank->balance ?? 0) + $sign * $amount;
                $bank->saveQuietly();   // حركة مشتقة من العكس الموثق — لا ضجيج تدقيق مزدوج
            }

            $doc->setRawAttributes($fresh->getAttributes(), true);

            return $amount;
        });

        if ($doc->state !== $prev) {
            \App\Support\FlowRunner::fire('status', 'fin', $doc, $doc->state);
        }
        hub_audit('عكس دفعة', 'fin', $doc->id,
            ($doc->doc_no ?: $doc->id) . ' — ' . number_format($amount, 2) . ' ' . ($doc->currency ?: '')
            . ' — السبب: ' . $d['reason']);

        $this->autoJournal($doc, $amount, reverse: true);

        return back()->with('ok', '↩︎ عُكست الدفعة (' . number_format($amount, 2) . ') وأُعيد اشتقاق الحالة — المدفوع الآن '
            . number_format((float) $doc->paid, 2));
    }

    /**
     * قيد يومية آلي للدفعة — أول قارئ لخريطة finance.accounts المبذورة منذ
     * البداية بلا مستهلك. خلف إعداد finance.auto_journal (معطل افتراضياً —
     * قرار الترحيل الآلي للمنشأة لا لنا): قبضُ دخلٍ يدين البنك/الصندوق ويُدين
     * المبيعات، وصرفُ مصروفٍ يعكس. القيد يولد مُرحَّلاً مقفلاً بسطرين موزونين.
     *
     * (Work OS · الطور E · WP-E.2) كان منطقُ الترحيل مكرَّراً حرفاً بحرفٍ هنا وفي
     * `PayrollController::autoJournal`؛ استُخرِج إلى `JournalPostingService` المشترَكة
     * — البوابةُ والخريطةُ وحلُّ الرمز الحتميّ وكتلةُ الترحيل الموزونة كلُّها هناك
     * مرّةً واحدة، تستهلكها العهدةُ كذلك. السلوكُ محفوظٌ حرفيّاً: نفسُ القيد وسطريه.
     */
    protected function autoJournal(FinDocument $doc, float $amount, bool $reverse = false): void
    {
        $svc = new \App\Support\JournalPostingService();
        if (! $svc->enabled()) return;

        try {
            $map = $svc->accountsMap();
            $income = in_array((string) $doc->kind, config('hub.fin.income'), true);
            $moneyCode = (string) ($doc->bank_id ? ($map['bank'] ?? '') : ($map['cash'] ?? ''));
            $otherCode = (string) ($income ? ($map['sales'] ?? '') : ($map['exp'] ?? ''));

            // حلٌّ مُحصَّرٌ بالشركة ثم بترتيب id (لا قرعة) — عبر الخدمة المشترَكة
            $money = $svc->resolveAccount($moneyCode, $doc->company_id);
            $other = $svc->resolveAccount($otherCode, $doc->company_id);
            if (! $money || ! $other) return;   // خريطة غير مكتملة — لا قيد أعرج

            // معاملةٌ تلفّ القيد وسطريه ورايةُ التوازن — كلُّها في postBalanced، فلا
            // يبقى القيدُ بسطرٍ واحد إن تعثّر الثاني، ولا نسخةَ ثالثةً من المحرّك.
            $svc->postBalanced([
                // مشتقٌّ من عمودٍ بعرض ٣٠٠ — يُقصّ لعرض العمود (درسُ notifications_hub / hub_fit)
                'doc_no' => hub_fit('JE-' . ($doc->doc_no ?: substr($doc->id, 0, 8)) . '-' . now()->format('His'),
                    hub_col_max('journal_entries', 'doc_no') ?? 300),
                'date' => now()->toDateString(),
                'description' => ($reverse ? 'عكس ' : '') . ($income ? 'قبض' : 'صرف') . ' دفعة على ' . ($doc->doc_no ?: $doc->id),
                'reference' => (string) $doc->doc_no,
                'state' => 'مرحّل',
                'fin_id' => $doc->id,
                'project_id' => $doc->project_id,
                'company_id' => $doc->company_id,
                'meta' => ['posted_at' => now()->toIso8601String(), 'auto' => $reverse ? 'payment_reversal' : 'payment'],
            ], $reverse
                // (F15) قيدُ العكس مرآةُ قيدِ الدفعة: نفسُ الحسابين بمدينٍ ودائنٍ متبادلَين —
                // لا تعديلَ على قيدٍ مرحَّلٍ مقفول، بل قيدٌ معاكسٌ يوازنه
                ? [
                    ['cc_id' => $doc->cc_id, 'acc_id' => $income ? $other : $money, 'debit' => $amount, 'credit' => 0,
                     'memo' => $income ? 'عكس الإيراد' : 'عكس سداد الدفعة'],
                    ['cc_id' => $doc->cc_id, 'acc_id' => $income ? $money : $other, 'debit' => 0, 'credit' => $amount,
                     'memo' => $income ? 'عكس قبض الدفعة' : 'عكس المصروف'],
                ]
                : [
                    ['cc_id' => $doc->cc_id, 'acc_id' => $income ? $money : $other, 'debit' => $amount, 'credit' => 0,
                     'memo' => $income ? 'قبض الدفعة' : 'المصروف'],
                    ['cc_id' => $doc->cc_id, 'acc_id' => $income ? $other : $money, 'debit' => 0, 'credit' => $amount,
                     'memo' => $income ? 'الإيراد' : 'سداد الدفعة'],
                ]);
        } catch (\Throwable $e) {
            report($e);   // القيد الآلي لا يُفشل تسجيل الدفعة نفسها
        }
    }
}

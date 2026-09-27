<?php

namespace App\Support\Finance;

use App\Models\BankAccount;
use App\Models\FinDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * **الدفعةُ على المستند الماليّ وعكسُها — المحرّكُ الواحد للسطحَين** (الجولة 1 · F15/F16 ·
 * TECH_DEBT #29 · خطّة التطبيق 4.6). مُستخرَجٌ حرفاً من `FinController` كي يستدعيه الويبُ
 * (`fin/{id}/act`) والجوالُ (`fin/{id}/pay`) معاً — لا بابَ ثانٍ ينحرف:
 *
 *  • **البوّابة** `fin:e` + المستندُ ضمن `hub_scope` (يحرسها المُنادي قبل النداء كالويب).
 *  • **قفلُ الحالة** داخل المعاملة وعلى الصفّ المقفول: لا دفعةَ على ملغى/مسودة
 *    (`hub.fin.dead`)، ولا على المسدَّد، ولا تتجاوز المتبقّي.
 *  • **حرّاسُ البنك الثلاثة** على البنك الفعليّ الذي يتحرّك رصيدُه: `banks:e` أو
 *    `banks:bankPost`، والنطاق، والعملة.
 *  • **سجلُّ الحركة** في `meta.payments` + حدثُ `status` + أثرُ تدقيقٍ + قيدُ يوميةٍ آليّ
 *    عبر `JournalPosting::postBalanced` (رابطُ المصدر `(fin, id, payment:ن)` يمنع الازدواج).
 */
final class FinPayment
{
    /** قواعدُ الدفعة (المبلغُ بفواصلَ لا يُفسَّر بصمت) */
    public static function payRules(): array
    {
        return [
            'amount'  => 'required|numeric|min:0.001',
            'bankId'  => 'nullable|string|exists:bank_accounts,id',
            'payDate' => 'nullable|date',
            'payRef'  => 'nullable|string|max:200',
            'payNote' => 'nullable|string|max:500',
        ];
    }

    public static function payMessages(): array
    {
        return ['amount.numeric' => 'مبلغ الدفعة غير مقروء — اكتبه رقماً عشرياً صريحاً بلا فواصل آلاف (مثل 2.500 أو 2500.000)'];
    }

    public static function payAttributes(): array
    {
        return ['amount' => 'مبلغ الدفعة', 'bankId' => 'الحساب البنكي',
            'payDate' => 'تاريخ الدفعة', 'payRef' => 'مرجع الدفعة', 'payNote' => 'ملاحظة الدفعة'];
    }

    public static function reverseRules(): array
    {
        return ['amount' => 'required|numeric|min:0.001', 'reason' => 'required|string|max:500'];
    }

    public static function reverseMessages(): array
    {
        return [
            'amount.numeric'  => 'مبلغ العكس غير مقروء — اكتبه رقماً عشرياً صريحاً بلا فواصل آلاف',
            'reason.required' => 'سببُ العكس إلزاميّ — يُوثَّق في سجل التدقيق فلا يقع تراجعٌ ماليّ بلا أثر',
        ];
    }

    /** بوّابةُ الدفعة: `fin:e` ثمّ المستندُ ضمن النطاق (٤٠٤ خارجه) — نظيرُ `FinController@act` */
    public static function authorize(User $actor, string $id): FinDocument
    {
        abort_unless(hub_can($actor, 'fin', 'e'), 403, 'تسجيل الدفعات يتطلب صلاحية تعديل المالية');

        return hub_scope(FinDocument::query(), 'fin', $actor)->findOrFail($id);
    }

    /**
     * **حرّاسُ البنك الثلاثة** — الصلاحيةُ (banks:e **أو** المفتاحُ الدقيق `bankPost`)،
     * والنطاقُ، والعملة — على البنك الفعليّ الذي يتحرّك رصيده، داخل المعاملة.
     */
    public static function guardBankPosting(User $actor, FinDocument $fresh): void
    {
        abort_unless(hub_can($actor, 'banks', 'e') || hub_can($actor, 'banks', 'bankPost'), 422,
            'توجيه الدفعة لحساب بنكي يحرّك رصيده — ويتطلب صلاحية تعديل البنوك أو مفتاح «قيد قبضٍ/صرفٍ بنكيّ»');
        abort_unless(hub_scope(BankAccount::query(), 'banks', $actor)->whereKey($fresh->bank_id)->exists(), 422,
            'الحساب البنكي خارج نطاقك — اختر حساباً من شركاتك');
        $bcur = (string) BankAccount::whereKey($fresh->bank_id)->value('currency');
        $dcur = (string) ($fresh->currency ?? '');
        abort_if($bcur !== '' && $dcur !== '' && $bcur !== $dcur, 422,
            "عملة الحساب ({$bcur}) تخالف عملة المستند ({$dcur}) — لا تحويلَ أسعارٍ في النظام، اختر حساباً بعملة المستند");
    }

    /** سجلُّ الحركة في `meta.payments` على المستند نفسِه (السكّةُ القائمة) */
    public static function appendPaymentRecord(User $actor, FinDocument $fresh, float $amount, array $extra = []): void
    {
        $meta = (array) ($fresh->meta ?? []);
        $meta['payments'][] = $extra + [
            'amount' => round($amount, 3),
            'bank'   => (string) ($fresh->bank_id ?? ''),
            'by'     => (string) $actor->getKey(),
            'ts'     => now()->toIso8601String(),
        ];
        $fresh->meta = $meta;
    }

    /**
     * **تسجيلُ دفعة** — يعيد المبلغَ المسجَّل فعلاً (مقصوصاً إلى المتبقّي)، و`$doc` يحمل
     * الحصيلةَ بعد المعاملة (الحالة/المدفوع). `$d` مُتحقَّقٌ بـ`payRules()`.
     */
    public static function pay(User $actor, FinDocument $doc, array $d): float
    {
        abort_if(in_array((string) $doc->state, (array) config('hub.fin.dead'), true), 422,
            'لا دفعات على مستند ملغى أو مسودة — فعّله أولاً');

        $prev = (string) $doc->state;
        $amount = DB::transaction(function () use ($actor, $d, $doc) {
            $fresh = FinDocument::lockForUpdate()->findOrFail($doc->id);

            // الحارسُ يُعاد داخل القفل — بين الفحص والكتابة نافذةٌ يُلغى فيها المستند
            abort_if(in_array((string) $fresh->state, (array) config('hub.fin.dead'), true), 422,
                'لا دفعات على مستند ملغى أو مسودة — فعّله أولاً');

            $paid = (float) ($fresh->paid ?? 0);
            $total = (float) ($fresh->total ?? 0);
            $remain = max(0, $total - $paid);
            abort_if($remain <= 0, 422, 'المستند مسدّد بالكامل أصلاً');

            // لا دفعة تتجاوز المتبقي — الفائض خطأ إدخال لا إيراد
            $amount = min((float) $d['amount'], $remain);

            if (! empty($d['bankId'])) $fresh->bank_id = $d['bankId'];

            if ($fresh->bank_id) {
                self::guardBankPosting($actor, $fresh);
            }

            $fresh->paid = $paid + $amount;
            $fresh->state = $fresh->paid >= $total ? 'مدفوعة' : 'مدفوعة جزئياً';
            self::appendPaymentRecord($actor, $fresh, $amount, [
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

            $doc->setRawAttributes($fresh->getAttributes(), true);

            return $amount;
        });

        if ($doc->state !== $prev) {
            \App\Support\Platform\FlowRunner::fire('status', 'fin', $doc, $doc->state);
        }
        hub_audit('دفعة', 'fin', $doc->id,
            ($doc->doc_no ?: $doc->id) . ' — ' . number_format($amount, 2) . ' ' . ($doc->currency ?: '')
            . (! empty($d['payDate']) ? ' — بتاريخ ' . $d['payDate'] : '')
            . (! empty($d['payRef']) ? ' — مرجع: ' . $d['payRef'] : ''));

        self::autoJournal($doc, $amount);

        return $amount;
    }

    /** **عكسُ دفعة** بسببٍ إلزاميّ — نفسُ المعاملة والقفل والحرّاس، وقيدٌ معاكس */
    public static function reverse(User $actor, FinDocument $doc, array $d): float
    {
        $prev = (string) $doc->state;
        $amount = DB::transaction(function () use ($actor, $d, $doc) {
            $fresh = FinDocument::lockForUpdate()->findOrFail($doc->id);

            $paid = round((float) ($fresh->paid ?? 0), 3);
            abort_if($paid <= 0, 422, 'لا مدفوعَ على هذا المستند ليُعكس');
            $amount = round((float) $d['amount'], 3);
            abort_if($amount > $paid, 422,
                'مبلغ العكس (' . number_format($amount, 3) . ') أكبر من المدفوع (' . number_format($paid, 3) . ') — لا يُعكس ما لم يُدفع');

            if ($fresh->bank_id) {
                self::guardBankPosting($actor, $fresh);
            }

            $fresh->paid = round($paid - $amount, 3);
            if (in_array((string) $fresh->state, ['مدفوعة', 'مدفوعة جزئياً'], true)) {
                $total = round((float) ($fresh->total ?? 0), 3);
                $fresh->state = $fresh->paid <= 0 ? 'معتمدة'
                    : ($fresh->paid >= $total ? 'مدفوعة' : 'مدفوعة جزئياً');
            }
            self::appendPaymentRecord($actor, $fresh, -$amount, ['reason' => (string) $d['reason']]);
            $fresh->save();

            if ($fresh->bank_id && ($bank = BankAccount::lockForUpdate()->find($fresh->bank_id))) {
                $sign = in_array((string) $fresh->kind, config('hub.fin.income'), true) ? -1 : 1;
                $bank->balance = (float) ($bank->balance ?? 0) + $sign * $amount;
                $bank->saveQuietly();
            }

            $doc->setRawAttributes($fresh->getAttributes(), true);

            return $amount;
        });

        if ($doc->state !== $prev) {
            \App\Support\Platform\FlowRunner::fire('status', 'fin', $doc, $doc->state);
        }
        hub_audit('عكس دفعة', 'fin', $doc->id,
            ($doc->doc_no ?: $doc->id) . ' — ' . number_format($amount, 2) . ' ' . ($doc->currency ?: '')
            . ' — السبب: ' . $d['reason']);

        self::autoJournal($doc, $amount, reverse: true);

        return $amount;
    }

    /**
     * قيد يومية آلي للدفعة عبر المحرّك الواحد `JournalPosting::postBalanced` — خلف
     * `finance.auto_journal`؛ رابطُ المصدر `(fin, id, payment:ن|reversal:ن)` يمنع الازدواج.
     */
    public static function autoJournal(FinDocument $doc, float $amount, bool $reverse = false): void
    {
        if (! JournalPosting::enabled()) return;

        try {
            $map = JournalPosting::accountsMap();
            $income = in_array((string) $doc->kind, config('hub.fin.income'), true);
            $moneyCode = (string) ($doc->bank_id ? ($map['bank'] ?? '') : ($map['cash'] ?? ''));
            $otherCode = (string) ($income ? ($map['sales'] ?? '') : ($map['exp'] ?? ''));

            $money = JournalPosting::resolveAccount($moneyCode, $doc->company_id);
            $other = JournalPosting::resolveAccount($otherCode, $doc->company_id);
            if (! $money || ! $other) return;   // خريطة غير مكتملة — لا قيد أعرج

            $seq = count((array) (((array) $doc->meta)['payments'] ?? []));

            JournalPosting::postBalanced([
                'doc_no' => 'JE-' . ($doc->doc_no ?: substr($doc->id, 0, 8)) . '-' . now()->format('His'),
                'date' => now()->toDateString(),
                'description' => ($reverse ? 'عكس ' : '') . ($income ? 'قبض' : 'صرف') . ' دفعة على ' . ($doc->doc_no ?: $doc->id),
                'reference' => (string) $doc->doc_no,
                'state' => 'مرحّل',
                'fin_id' => $doc->id,
                'project_id' => $doc->project_id,
                'company_id' => $doc->company_id,
                'meta' => ['posted_at' => now()->toIso8601String(), 'auto' => $reverse ? 'payment_reversal' : 'payment'],
            ], $reverse
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
                ],
                ['module' => FinDocument::MODULE, 'id' => $doc->id, 'key' => ($reverse ? 'reversal:' : 'payment:') . $seq]);
        } catch (\Throwable $e) {
            report($e);   // القيد الآلي لا يُفشل تسجيل الدفعة نفسها
        }
    }
}

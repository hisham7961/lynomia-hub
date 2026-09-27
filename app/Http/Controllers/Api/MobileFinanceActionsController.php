<?php

namespace App\Http\Controllers\Api;

use App\Models\FinDocument;
use App\Models\Purchase;
use App\Models\Quote;
use App\Support\Assets\PurchaseFlow;
use App\Support\Finance\FinPayment;
use App\Support\Finance\QuoteAcceptance;
use App\Support\Finance\QuoteActions;
use App\Support\Platform\Api;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **أفعالٌ ماليّةٌ مختارةٌ على الجوال** (خطّة التطبيق · 4.6) — **عبر المحرّكات الموحّدة
 * وحدها**، وكلٌّ بحرّاسِ فعلِ الويب نفسِه:
 *  • `fin/{id}/pay` ⇐ `FinPayment` (بابُ `fin/{id}/act do=pay`): `fin:e` + النطاق، قفلُ
 *    الحالة داخل المعاملة، حرّاسُ البنك الثلاثة، سجلُّ الحركة، التدقيق، والقيدُ الآليّ عبر
 *    `JournalPosting`. **وخلف تصعيد الجوال** (`action:fin:pay`) — تحريكُ مالٍ من جهازٍ محمول.
 *  • `quotes/{id}/send|accept` ⇐ `QuoteActions` + `QuoteAcceptance` (بابُ `quote/{id}/act`):
 *    `quotes:e` + النطاق + طابورُ الموافقات + قفلُ حقل الحالة + عتبةُ الاعتماد.
 *  • `purchases/{id}/receive` ⇐ `PurchaseFlow` (بابُ `purchase/{id}/act do=receive`):
 *    `purchases:e` + النطاق + الطابور + آلةُ الحالة + الاستلامُ المقفول.
 *
 * كلُّها بـ`Idempotency-Key` (مالكُ الجوال)، ومساراتُها حرفيّةٌ قبل الـcatch-all
 * `{module}/{id}/…`، وأسماؤها `mobile.{fin|quotes|purchases}.*` خارجَ `mobile.resource.*`
 * وخارجَ قائمة `MobilePortalGuard` — فحسابُ العميل ٤٠٤ ولو كانت `fin` ضمن وحداته.
 * الأرقامُ الماليّةُ تُعاد نصوصاً عشريّة (لا `double`)، والمحجوبُ عن الدور `null`.
 */
class MobileFinanceActionsController extends MobileWorkflowController
{
    /** غرضُ تصعيد الجوال لتسجيل الدفعة — مربوطٌ بالفعل لا مِنحةٌ عامة (§41) */
    public const STEPUP_PAY = 'action:fin:pay';

    /** `POST fin/{id}/pay` — `{amount, bankId?, payDate?, payRef?, payNote?}` + تصعيد + Idempotency */
    public function pay(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $doc = FinPayment::authorize($r->user(), $id);
        $d = $r->validate(FinPayment::payRules(), FinPayment::payMessages(), FinPayment::payAttributes());

        if ($resp = $this->requireStepUp($r, self::STEPUP_PAY)) return $resp;

        return $this->idempotent($r, function () use ($r, $doc, $d) {
            $amount = FinPayment::pay($r->user(), $doc, $d);
            $payments = (array) (((array) $doc->meta)['payments'] ?? []);
            $last = end($payments) ?: [];

            return $this->ok([
                'amount' => $this->money($amount),
                'document' => $this->finShape($doc),
                'payment' => [
                    'amount' => $this->money((float) ($last['amount'] ?? $amount)),
                    'at' => $last['at'] ?? null,
                    'ref' => ($last['ref'] ?? '') !== '' ? (string) $last['ref'] : null,
                    'bank_id' => ($last['bank'] ?? '') !== '' ? (string) $last['bank'] : null,
                    'seq' => count($payments),
                ],
            ]);
        });
    }

    /** `POST quotes/{id}/send` — إرسالٌ بعتبة اعتماد (`sent` أو `escalated` للمراجعة الداخليّة) */
    public function quoteSend(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $q = $this->quote($r, $id);
        if ($q instanceof Response) return $q;

        return $this->idempotent($r, function () use ($r, $q) {
            $outcome = QuoteActions::send($r->user(), $q);

            return $this->ok(['outcome' => $outcome, 'quote' => $this->quoteShape($q->fresh() ?? $q)]);
        });
    }

    /** `POST quotes/{id}/accept` — قبولٌ بمحرّكه الواحد (متكرّرٌ بلا أثر: `accepted=false`) */
    public function quoteAccept(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $q = $this->quote($r, $id);
        if ($q instanceof Response) return $q;

        return $this->idempotent($r, function () use ($r, $q) {
            $accepted = QuoteActions::accept($r->user(), $q, QuoteAcceptance::VIA_MOBILE);

            return $this->ok(['accepted' => $accepted, 'quote' => $this->quoteShape($q->fresh() ?? $q)]);
        });
    }

    /** `POST purchases/{id}/receive` — استلامُ أمر الشراء (حركاتُ مخزونٍ مؤكّدة · idempotent) */
    public function purchaseReceive(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $p = PurchaseFlow::authorize($r->user(), $id);
        if ($why = hub_block_if_queued('purchases')) return Api::error(Api::APPROVAL_REQUIRED, 409, $why);

        // آلةُ الحالة **داخل** التنفيذ العديم الأثر: إعادةُ المحاولة بالمفتاح نفسِه تعيد الردَّ
        // المحفوظ قبل أن يُسأل «من أيّ حالة؟» (فالأمرُ صار مستلَماً بالمحاولة الأولى)
        return $this->idempotent($r, function () use ($r, $p) {
            PurchaseFlow::guardTransition($p, 'receive');
            [$made, $skipped, $already] = PurchaseFlow::receive($r->user(), $p);
            $fresh = Purchase::whereKey($p->id)->firstOrFail();

            return $this->ok([
                'moves' => $made, 'skipped' => $skipped, 'already' => $already,
                'purchase' => [
                    'id' => (string) $fresh->id,
                    'doc_no' => $this->field('purchases', 'no', $fresh->doc_no),
                    'status' => $this->field('purchases', 'status', $fresh->status),
                    'received_at' => $this->field('purchases', 'receivedAt',
                        $fresh->received_at ? substr((string) $fresh->received_at, 0, 10) : null),
                ],
            ]);
        });
    }

    /* ────────── مساعِدات ────────── */

    /** بوّابةُ العرض: `quotes:e` + النطاق + طابورُ الموافقات (٤٠٩ APPROVAL_REQUIRED) */
    private function quote(Request $r, string $id): Quote|Response
    {
        $q = QuoteActions::authorize($r->user(), $id);
        if ($why = hub_block_if_queued('quotes')) return Api::error(Api::APPROVAL_REQUIRED, 409, $why);

        return $q;
    }

    private function finShape(FinDocument $doc): array
    {
        $total = (float) ($doc->total ?? 0);
        $paid = (float) ($doc->paid ?? 0);
        $hideTotal = hub_field_mode(auth()->user(), 'fin', 'total') === 'hide';
        $hidePaid = hub_field_mode(auth()->user(), 'fin', 'paid') === 'hide';

        return [
            'id' => (string) $doc->id,
            'doc_no' => $this->field('fin', 'no', $doc->doc_no),
            'kind' => $this->field('fin', 'kind', $doc->kind),
            'state' => $this->field('fin', 'state', $doc->state),
            'currency' => $this->field('fin', 'currency', $doc->currency),
            'total' => $hideTotal ? null : $this->money($total),
            'paid' => $hidePaid ? null : $this->money($paid),
            'remaining' => ($hideTotal || $hidePaid) ? null : $this->money(max(0, $total - $paid)),
        ];
    }

    private function quoteShape(Quote $q): array
    {
        return [
            'id' => (string) $q->id,
            'doc_no' => $this->field('quotes', 'no', $q->doc_no),
            'title' => $this->field('quotes', 'title', $q->title),
            'status' => $this->field('quotes', 'status', $q->status),
            'sent_at' => self::iso($q->sent_at),
            'accepted_at' => self::iso($q->accepted_at),
        ];
    }

    /** قيمةُ حقلٍ بحكم `hub_field_mode` — المحجوبُ null */
    private function field(string $module, string $key, $value)
    {
        return hub_field_mode(auth()->user(), $module, $key) === 'hide' ? null : $value;
    }

    /** مبلغٌ عشريٌّ آمنٌ نصّاً (ثلاثُ خاناتٍ — لا `double` على السلك) */
    private function money(float $v): string
    {
        return number_format($v, 3, '.', '');
    }
}

<?php

namespace App\Support\Finance;

use App\Models\Quote;
use App\Models\User;

/**
 * **إرسالُ عرضِ السعر وقبولُه — القاعدةُ الواحدة للسطحَين** (CPQ · TECH_DEBT #29 ·
 * خطّة التطبيق 4.6). مُستخرَجةٌ من `QuoteController@act` (`send`/`accept`) كي يستدعيها
 * الويبُ والجوالُ حرفاً:
 *
 *  • **البوّابة** `quotes:e` (٤٠٣) + العرضُ ضمن `hub_scope` (٤٠٤)؛ وطابورُ الموافقات
 *    (`hub_needs_approval`) لا تلتفّ عليه أزرارُ المسار.
 *  • **الإرسال بعتبة اعتماد**: مبلغٌ/خصمٌ/هامشٌ يتجاوز العتبة ⇒ «مراجعة داخلية» وإشعارُ
 *    المعتمدين المنطَّقين على العرض — إلّا لحامل `approve`/`quotes:approve`/المالك.
 *    وإلّا يُختم `sent_at` وتُؤرشف النسخةُ المُصدَرة ويُقلب «مُرسل» (حقلُ الحالة غيرُ مقفول).
 *  • **القبول** بمحرّكه الواحد `QuoteAcceptance` (القفلُ + المعاملة + الأرشيف + التدقيق)،
 *    متكرّرٌ بلا أثر.
 */
final class QuoteActions
{
    public const SENT = 'sent';

    public const ESCALATED = 'escalated';

    /** بوّابةُ أفعال العرض: `quotes:e` ثمّ النطاق */
    public static function authorize(User $actor, string $id): Quote
    {
        abort_unless(hub_can($actor, 'quotes', 'e'), 403, 'إجراءات العرض تتطلب صلاحية تعديل');

        return hub_scope(Quote::query(), 'quotes', $actor)->findOrFail($id);
    }

    /** **إرسالٌ للعميل بعتبةِ اعتماد** — يعيد `sent` أو `escalated` (أُحيل للمراجعة الداخليّة) */
    public static function send(User $actor, Quote $q): string
    {
        $amountAt = (float) setting('quotes.approve_amount', 0);
        $discAt = (float) setting('quotes.approve_discount', 0);
        $discPct = ((float) $q->total + (float) $q->discount) > 0
            ? (float) $q->discount / ((float) $q->total + (float) $q->discount) * 100 : 0;
        // **حاجزُ الهامش** (CPQ): هامشٌ دون الحدّ المضبوط يستوجب اعتماداً كالمبلغ والخصم (٠ = مطفأ)
        $floorAt = (float) setting('quotes.margin_floor', 0);
        $margin = $q->margin();
        $needs = ($amountAt > 0 && (float) $q->total >= $amountAt)
            || ($discAt > 0 && $discPct >= $discAt)
            || ($floorAt > 0 && $margin !== null && $margin < $floorAt);

        if ($needs && ! hub_flag($actor, 'approve')
            && ! hub_can($actor, 'quotes', 'approve') && ! hub_is_owner($actor)) {
            // **نطاقٌ لكلّ مستلم**: لا يُسرَّب عنوانُ العرض ومبلغُه لمعتمِدٍ معزولٍ عن شركته/عميله
            foreach (array_unique(hub_approvers_for('quotes', $q->id)) as $oid) {
                if ($oid && $oid !== $actor->getKey()) {
                    hub_notify($oid, 'approval', 'عرضٌ ينتظر اعتمادَ الإرسال: ' . ($q->title ?: $q->doc_no)
                        . ' — ' . number_format((float) $q->total, 3) . ' ' . $q->currency, 'quotes', $q->id);
                }
            }
            $q->status = 'مراجعة داخلية';
            $q->save();

            return self::ESCALATED;
        }

        if (! $q->sent_at) $q->sent_at = now();

        // **أرشفةُ العرض المُصدَر** (CPQ هـ): لقطةٌ ثابتةٌ لحظةَ الإرسال — سلامةٌ تاريخيّة
        QuoteAcceptance::archive($q, 'إرسال', $actor->getKey());

        self::setStatus($actor, $q, 'مُرسل');

        return self::SENT;
    }

    /**
     * **القبول** بمحرّكه الواحد — يعيد true إن قُبل بهذا النداء، وfalse إن كان مقبولاً
     * من قبل (بلا أثر). حقلُ الحالة المقفولُ لدوره ٤٠٣ (قفلُ الحقل يسري على أزرار المسار).
     */
    public static function accept(User $actor, Quote $q, string $via = QuoteAcceptance::VIA_WEB): bool
    {
        self::guardStatusField($actor);

        return QuoteAcceptance::byUser($q, $actor, $via);
    }

    /** قلبُ الحالة من أزرار المسار (غير القبول) + إطلاقُ حدثها الدلاليّ */
    public static function setStatus(User $actor, Quote $q, string $status): void
    {
        self::guardStatusField($actor);

        $q->status = $status;
        $q->save();

        \App\Support\Platform\FlowRunner::fire('status', 'quotes', $q, $status);
    }

    /** حقلُ الحالة «قراءة فقط» لدوره ⇒ لا يُقلب من أزرار المسار */
    public static function guardStatusField(User $actor): void
    {
        abort_if(hub_field_mode($actor, 'quotes', 'status') !== '', 403,
            'حقل الحالة مقفولٌ لدورك (قراءة فقط) — لا يُغيَّر من أزرار المسار');
    }
}

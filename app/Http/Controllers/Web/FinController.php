<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FinDocument;
use App\Support\Finance\FinPayment;
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
        // البوّابةُ والمحرّكُ في `FinPayment` (يشترك فيهما الجوال · خطّة التطبيق 4.6)
        $doc = FinPayment::authorize(auth()->user(), $id);

        return match (hub_str($r->input('do'))) {
            'pay'     => $this->pay($r, $doc),
            'reverse' => $this->reverse($r, $doc),   // (الجولة 1 · F15) عكسُ دفعةٍ موثَّقٌ بسبب
            default   => abort(422),
        };
    }

    /**
     * (الجولة 1 · F15) **الدفعةُ سجلٌّ محترم**: تاريخُها ومرجعُها وملاحظتُها وكاتبُها
     * تُلحَق قيداً في `meta.payments`، والحرّاسُ الثلاثة للبنك داخل المعاملة على البنك
     * الفعليّ، والقفلُ يسلسل الدفعات، والقيدُ الآليّ عبر `JournalPosting` — كلُّه في
     * `FinPayment::pay`، وهنا التحقّقُ والعرض.
     */
    protected function pay(Request $r, FinDocument $doc)
    {
        abort_if(in_array((string) $doc->state, config('hub.fin.dead'), true), 422,
            'لا دفعات على مستند ملغى أو مسودة — فعّله أولاً');

        $d = $r->validate(FinPayment::payRules(), FinPayment::payMessages(), FinPayment::payAttributes());
        FinPayment::pay(auth()->user(), $doc, $d);
        $total = (float) ($doc->total ?? 0);

        return back()->with('ok', $doc->state === 'مدفوعة'
            ? '💰 سُدّد المستند بالكامل'
            : '💰 سُجّلت الدفعة — المتبقي ' . number_format($total - (float) $doc->paid, 2));
    }

    /**
     * (الجولة 1 · F15) **عكسُ دفعة** بسببٍ إلزاميّ — نفسُ معاملةِ الدفعة وقفلِها وحرّاسِها،
     * وقيدٌ معاكسٌ يوازنها (`FinPayment::reverse`).
     */
    protected function reverse(Request $r, FinDocument $doc)
    {
        $d = $r->validate(FinPayment::reverseRules(), FinPayment::reverseMessages(),
            ['amount' => 'مبلغ العكس', 'reason' => 'سبب العكس']);
        $amount = FinPayment::reverse(auth()->user(), $doc, $d);

        return back()->with('ok', '↩︎ عُكست الدفعة (' . number_format($amount, 2) . ') وأُعيد اشتقاق الحالة — المدفوع الآن '
            . number_format((float) $doc->paid, 2));
    }
}

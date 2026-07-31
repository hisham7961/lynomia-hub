<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * مسار عرض السعر: مستند طباعة أنيق ← إرسال ← قبول/رفض ← تحويل بنقرة إلى عقد وفاتورة.
 * التحويلات تُسجَّل في meta العرض فلا تتكرر، وكل خطوة موثقة في التدقيق.
 */
class QuoteController extends Controller
{
    /** المستند القابل للطباعة (A4) */
    public function doc(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'quotes', 'v'), 403);
        $q = hub_scope(Quote::query(), 'quotes')->findOrFail($id);
        $client = $q->client_id ? Client::find($q->client_id) : null;

        // إثراء البنود بحساب الكراتين (تعبئة المنتجات) بعزل شركة المستند
        $items = \App\Support\Items::cartons(
            \App\Support\Items::parse((string) $q->items), $q->company_id);

        return view('quotes.doc', [
            'q' => $q, 'client' => $client,
            'items' => $items,
            'showCartons' => \App\Support\Items::anyCartons($items),
            'totalCartons' => \App\Support\Items::totalCartons($items),
            'logo' => setting('app.logo'),
        ]);
    }

    /** إجراءات المسار */
    public function act(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'quotes', 'e'), 403, 'إجراءات العرض تتطلب صلاحية تعديل');
        $q = hub_scope(Quote::query(), 'quotes')->findOrFail($id);
        $action = (string) $r->input('do');

        return match ($action) {
            'send'     => $this->setStatus($q, 'مُرسل', '📨 حُدّد العرض كمُرسل للعميل'),
            'accept'   => $this->setStatus($q, 'مقبول', '🎉 قُبل العرض — حوّله لعقد أو فاتورة من الأزرار'),
            'reject'   => $this->setStatus($q, 'مرفوض', 'حُدّد العرض كمرفوض'),
            'contract' => $this->toContract($q),
            'invoice'  => $this->toInvoice($q),
            default    => abort(422),
        };
    }

    /* ────────── داخلي ────────── */

    protected function setStatus(Quote $q, string $status, string $msg)
    {
        $q->status = $status;
        $q->save();

        return back()->with('ok', $msg);
    }

    protected function toContract(Quote $q)
    {
        abort_unless($q->status === 'مقبول', 422, 'حوّل العرض بعد قبوله أولاً');
        $meta = (array) $q->meta;
        if (! empty($meta['contract_id']) && Contract::find($meta['contract_id'])) {
            return redirect()->route('m.show', ['contracts', $meta['contract_id']])->with('ok', 'حُوّل من قبل — هذا عقده');
        }

        $c = Contract::create([
            'title'      => 'عقد بموجب عرض السعر ' . $q->doc_no,
            'type'       => 'عقد عميل',
            'client_id'  => $q->client_id,
            'company_id' => $q->company_id,
            'project_id' => $q->project_id,
            'party'      => $q->client_id ? Client::find($q->client_id)?->name : null,
            'value'      => $q->total,
            'currency'   => $q->currency,
            // v2.117: كان يكتب عمود start الوهمي (الصحيح date_start → انفجار 500 على
            // MySQL) وحالة «نشط» الخارجة عن خيارات الوحدة فلا كانبان ولا حدث يلتقطها
            'date_start' => now()->toDateString(),
            'owner_id'   => $q->owner_id ?: auth()->id(),
            'status'     => 'ساري',
            'notes'      => 'أُنشئ تلقائياً من عرض السعر ' . $q->doc_no
                          . ($q->terms ? "\n\nالشروط المتفق عليها:\n" . Str::limit($q->terms, 1500) : ''),
        ]);
        $q->meta = $meta + ['contract_id' => $c->id];
        $q->save();

        return redirect()->route('m.show', ['contracts', $c->id])
            ->with('ok', '📜 أُنشئ العقد من العرض — راجع بنوده وتاريخ نهايته');
    }

    protected function toInvoice(Quote $q)
    {
        abort_unless($q->status === 'مقبول', 422, 'حوّل العرض بعد قبوله أولاً');
        $meta = (array) $q->meta;
        if (! empty($meta['invoice_id']) && FinDocument::find($meta['invoice_id'])) {
            return redirect()->route('m.show', ['fin', $meta['invoice_id']])->with('ok', 'حُوّل من قبل — هذه فاتورته');
        }

        $inv = FinDocument::create([
            'doc_no'      => 'INV-' . $q->doc_no,
            'kind'        => 'فاتورة مبيعات',
            'partner'     => $q->client_id ? (Client::find($q->client_id)?->name ?? '') : '',
            'date'        => now()->toDateString(),
            'due'         => now()->addDays(14)->toDateString(),
            'amount'      => $q->amount ?? $q->total,
            'tax'         => $q->tax ?? 0,
            'total'       => $q->total,
            'paid'        => 0,
            'currency'    => $q->currency,
            'state'       => 'مرسلة',
            'project_id'  => $q->project_id,
            'company_id'  => $q->company_id,
            'description' => 'فاتورة بموجب عرض السعر ' . $q->doc_no,
        ]);
        $q->meta = $meta + ['invoice_id' => $inv->id];
        $q->save();

        return redirect()->route('m.show', ['fin', $inv->id])
            ->with('ok', '🧾 أُنشئت الفاتورة من العرض — استحقاقها بعد ١٤ يوماً');
    }

}

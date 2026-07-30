<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FinDocument;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * مسار الشراء: طلب ← اعتماد ← إرسال للمورد ← استلام ← فاتورة مورد (تدخل المالية) / مرتجع.
 * الاعتماد للمالكين وحاملي علم approve، والفاتورة لا تتكرر (تُسجل في meta).
 */
class PurchaseController extends Controller
{
    /** بطاقات أداء الموردين من سجل المشتريات */
    public function scores()
    {
        abort_unless(hub_can(auth()->user(), 'suppliers', 'v'), 403, 'تقييم الموردين يتطلب صلاحية رؤية الموردين');

        return view('supplier_scores', ['d' => hub_supplier_scores((bool) request()->query('fresh'))]);
    }

    /** أمر شراء قابل للطباعة (A4) */
    public function doc(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'purchases', 'v'), 403);
        $p = hub_scope(Purchase::query(), 'purchases')->findOrFail($id);

        return view('purchases.doc', [
            'p' => $p,
            'supplier' => $p->supplier_id ? Supplier::find($p->supplier_id) : null,
            'items' => \App\Support\Items::parse((string) $p->items),
            'logo' => setting('app.logo'),
        ]);
    }

    public function act(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'purchases', 'e'), 403, 'إجراءات الشراء تتطلب صلاحية تعديل');
        $p = hub_scope(Purchase::query(), 'purchases')->findOrFail($id);

        return match ((string) $r->input('do')) {
            'submit'  => $this->setStatus($p, 'بانتظار الاعتماد', '📨 أُرسل الطلب للاعتماد'),
            'approve' => $this->approve($p),
            'send'    => $this->setStatus($p, 'أُرسل للمورد', '📤 حُدّد كمُرسل للمورد — اطبع أمر الشراء وأرسله'),
            'receive' => $this->setStatus($p, 'مستلم', '📦 سُجّل الاستلام — أنشئ فاتورة المورد من الزر'),
            'return'  => $this->setStatus($p, 'مرتجع', 'سُجّل كمرتجع'),
            'bill'    => $this->toBill($p),
            default   => abort(422),
        };
    }

    /* ────────── داخلي ────────── */

    protected function setStatus(Purchase $p, string $status, string $msg)
    {
        $p->status = $status;
        $p->save();

        return back()->with('ok', $msg);
    }

    /** الاعتماد للمالكين وحاملي علم approve فقط */
    protected function approve(Purchase $p)
    {
        abort_unless(auth()->user()->role?->is_owner || hub_flag(auth()->user(), 'approve'), 403,
            'اعتماد المشتريات للمالكين وحاملي صلاحية الاعتماد');

        return $this->setStatus($p, 'معتمد', '✅ اعتُمد أمر الشراء');
    }

    /** فاتورة المورد → المالية (فاتورة مشتريات) — بلا تكرار */
    protected function toBill(Purchase $p)
    {
        abort_unless($p->status === 'مستلم', 422, 'سجّل الاستلام أولاً ثم أنشئ الفاتورة');
        $meta = (array) $p->meta;
        if (! empty($meta['bill_id']) && FinDocument::find($meta['bill_id'])) {
            return redirect()->route('m.show', ['fin', $meta['bill_id']])->with('ok', 'أُنشئت من قبل — هذه فاتورتها');
        }

        $supplier = $p->supplier_id ? Supplier::find($p->supplier_id) : null;
        $bill = FinDocument::create([
            'doc_no'      => $p->invoice_no ?: 'BILL-' . $p->doc_no,
            'kind'        => 'فاتورة مشتريات',
            'partner'     => $supplier?->name ?? '',
            'date'        => now()->toDateString(),
            'due'         => now()->addDays(30)->toDateString(),
            'amount'      => $p->amount,
            'total'       => $p->amount,
            'paid'        => $p->pay_state === 'مدفوع' ? $p->amount : 0,
            'currency'    => $p->currency,
            'state'       => $p->pay_state === 'مدفوع' ? 'مدفوعة' : 'مرسلة',
            'project_id'  => $p->project_id,
            'company_id'  => $p->company_id,
            'description' => 'فاتورة مورد بموجب مستند الشراء ' . $p->doc_no,
        ]);
        $p->meta = $meta + ['bill_id' => $bill->id];
        $p->save();

        return redirect()->route('m.show', ['fin', $bill->id])
            ->with('ok', '🧾 أُنشئت فاتورة المورد — تظهر الآن في المالية والتقارير');
    }

}

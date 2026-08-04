<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FinDocument;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        hub_org_analytics_guard();

        return view('supplier_scores', ['d' => hub_supplier_scores((bool) request()->query('fresh'))]);
    }

    /** أمر شراء قابل للطباعة (A4) */
    public function doc(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'purchases', 'v'), 403);
        $p = hub_scope(Purchase::query(), 'purchases')->findOrFail($id);

        // إثراء البنود بحساب الكراتين (تعبئة المنتجات) بعزل شركة المستند —
        // فيعرف المُستلِم كم كرتونة يتوقّع من كل صنف
        $items = \App\Support\Items::cartons(
            \App\Support\Items::parse((string) $p->items), $p->company_id);

        // قفل الحقل يسري على مستند الطباعة كما على صفحة العرض (modules/show:90):
        // دورٌ محجوبٌ عليه «الإجمالي» أو «البنود» لا يراهما عبر مسار الطباعة.
        $u = auth()->user();

        return view('purchases.doc', [
            'p' => $p,
            'supplier' => $p->supplier_id ? Supplier::find($p->supplier_id) : null,
            'items' => $items,
            'showCartons' => \App\Support\Items::anyCartons($items),
            'totalCartons' => \App\Support\Items::totalCartons($items),
            'logo' => setting('app.logo'),
            'hideAmount' => hub_field_mode($u, 'purchases', 'amount') === 'hide',
            'hideItems'  => hub_field_mode($u, 'purchases', 'items') === 'hide',
        ]);
    }

    /**
     * آلة الحالة المفروضة على الخادم: كانت الأزرار تُخفى في القالب فقط بينما
     * act يقبل أي انتقالٍ من أي حالة — استلام مسودة يمرّ وبعث الملغى حياً يمرّ.
     * [الإجراء => [الحالات المسموح منها، الحالة الهدف]]
     */
    protected const FLOW = [
        'submit'  => [['مسودة'], 'بانتظار الاعتماد'],
        'approve' => [['بانتظار الاعتماد'], 'معتمد'],
        'send'    => [['معتمد'], 'أُرسل للمورد'],
        'receive' => [['أُرسل للمورد', 'معتمد'], 'مستلم'],
        'return'  => [['مستلم'], 'مرتجع'],
    ];

    public function act(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'purchases', 'e'), 403, 'إجراءات الشراء تتطلب صلاحية تعديل');
        if ($why = hub_block_if_queued('purchases')) return back()->with('err', $why);
        $p = hub_scope(Purchase::query(), 'purchases')->findOrFail($id);
        $do = hub_str($r->input('do'));

        // الملغى والمرتجع نهايتان — لا إجراء يبعثهما
        abort_if(in_array((string) $p->status, ['ملغى', 'مرتجع'], true) && $do !== 'bill', 422,
            'المستند ' . $p->status . ' — أنشئ أمر شراء جديداً بدل إحيائه');

        if (isset(self::FLOW[$do])) {
            [$from, $to] = self::FLOW[$do];
            abort_unless(in_array((string) $p->status, $from, true), 422,
                'لا يصح هذا الإجراء من حالة «' . $p->status . '» — المسموح منه: ' . implode('، ', $from));
        }

        return match ($do) {
            'submit'  => $this->setStatus($p, 'بانتظار الاعتماد', '📨 أُرسل الطلب للاعتماد'),
            'approve' => $this->approve($p),
            'send'    => $this->setStatus($p, 'أُرسل للمورد', '📤 حُدّد كمُرسل للمورد — اطبع أمر الشراء وأرسله'),
            'receive' => $this->receive($p),
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
        abort_unless(hub_approver(), 403,
            'اعتماد المشتريات للمالكين وحاملي صلاحية الاعتماد');

        return $this->setStatus($p, 'معتمد', '✅ اعتُمد أمر الشراء');
    }

    /**
     * الاستلام: يختم تاريخه الصريح (قياس الالتزام لا تقديره) ويولّد حركات
     * مخزون مؤكدة من بنود الأمر بمطابقة الاسم الحرفي لأصناف المخزون —
     * فيتحرك الرصيد لحظة الاستلام. البنود بلا صنف مطابق تُترك بلا حركة
     * (لا تخمين في الأرصدة) ويُقال ذلك صراحةً. idempotent عبر meta.
     */
    protected function receive(Purchase $p)
    {
        // الاستلام داخل معاملة على صفٍّ مقفول: فحصُ «استُلم من قبل» يقرأ الحالة
        // المُثبَتة لا نسخةً قديمة في الذاكرة — فنقرتان متزامنتان (نقر مزدوج/إعادة
        // إرسال) تتسلسلان فلا تُضاعف حركات المخزون ولا يُزاد الرصيد مرتين.
        [$made, $skipped] = DB::transaction(function () use ($p) {
            $p = Purchase::whereKey($p->getKey())->lockForUpdate()->firstOrFail();
            $meta = (array) $p->meta;
            $made = 0; $skipped = 0;

            // سبقتنا نقرةٌ متزامنة داخل القفل — لا تكرار للحركات (idempotent)
            if ($p->status === 'مستلم' || ! empty($meta['stock_moves'])) {
                return [$made, $skipped];
            }

            $lines = \App\Support\Items::parse((string) $p->items);
            $supplierName = $p->supplier_id ? (Supplier::find($p->supplier_id)?->name ?? '') : '';
            $moveIds = [];
            foreach ($lines as $line) {
                $qty = (float) ($line['qty'] ?? 0);
                $name = trim((string) ($line['desc'] ?? ''));
                if ($qty <= 0 || $name === '') { $skipped++; continue; }

                // مطابقةٌ منطَّقةٌ بالشركة وحاسمةُ الترتيب: أمرٌ بشركةٍ يطابق أصنافها
                // أو المشتركة (بلا شركة)، وأمرٌ **بلا** شركة يطابق المشتركة وحدها —
                // فلا يحرّك مخزون شركةٍ أخرى. والمخصَّصُ للشركة قبل المشترك ثم id ثابت.
                $item = \App\Models\StockItem::whereNull('deleted_at')->where('name', $name)
                    ->when($p->company_id,
                        fn ($q) => $q->where(fn ($w) => $w->where('company_id', $p->company_id)->orWhereNull('company_id')),
                        fn ($q) => $q->whereNull('company_id'))
                    ->orderByRaw('company_id IS NULL')->orderBy('id')
                    ->first();
                if (! $item) { $skipped++; continue; }

                \App\Models\StockMove::$posting = true;
                try {
                    $mv = \App\Models\StockMove::create([
                        'doc_no' => 'IN-' . $p->doc_no . '-' . ($made + 1),
                        'kind' => 'استلام', 'item_id' => $item->id, 'qty' => $qty,
                        'to_wh' => $item->wh, 'date' => now()->toDateString(),
                        'reference' => (string) $p->doc_no, 'partner' => $supplierName,
                        'company_id' => $p->company_id, 'status' => 'مؤكدة',
                        'meta' => ['posted_at' => now()->toIso8601String(),
                                   'posted_by' => auth()->id(), 'delta' => $qty, 'purchase_id' => $p->id],
                    ]);
                } finally {
                    \App\Models\StockMove::$posting = false;
                }
                $item->qty = (float) $item->qty + $qty;
                $item->saveQuietly();
                hub_stock_sync($item);
                $moveIds[] = $mv->id;
                $made++;
            }
            $meta['stock_moves'] = $moveIds;

            $p->received_at = now()->toDateString();
            $p->meta = $meta;
            $p->status = 'مستلم';
            $p->save();

            return [$made, $skipped];
        });

        return back()->with('ok', '📦 سُجّل الاستلام'
            . ($made ? " وتحرّك المخزون ({$made} حركة)" : '')
            . ($skipped ? " — {$skipped} بند بلا صنف مطابق في المخزون لم يُحرَّك" : '')
            . ' — أنشئ فاتورة المورد من الزر');
    }

    /** فاتورة المورد → المالية (فاتورة مشتريات) — بلا تكرار */
    protected function toBill(Purchase $p)
    {
        // معاملةٌ على صفٍّ مقفول كنمط receive(): فحصُ bill_id يقرأ الحالة المُثبَتة
        // لا نسخةً قديمة في الذاكرة — فنقرتان متزامنتان (نقر مزدوج/تبويبان) تتسلسلان
        // فلا تُنشئان فاتورتَي موردٍ مكرّرتين (التزامٌ مضاعف)؛ doc_no غير فريدٍ في القاعدة.
        return DB::transaction(function () use ($p) {
            $p = Purchase::whereKey($p->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($p->status === 'مستلم', 422, 'سجّل الاستلام أولاً ثم أنشئ الفاتورة');
            $meta = (array) $p->meta;
            if (! empty($meta['bill_id']) && ($prev = FinDocument::withTrashed()->find($meta['bill_id']))) {
                // withTrashed: فاتورةٌ سابقةٌ نُقلت للسلة كانت تُستبعَد بـfind فيُعاد
                // إنشاؤها — فتتكرّر فاتورة المورد. المحذوفة تُوقف التكرار وتُوجَّه للاستعادة.
                if ($prev->trashed()) {
                    return back()->with('err', 'فاتورة هذا الشراء محذوفةٌ في السلة — استعِدها من المالية بدل إنشاء نسخةٍ مكرّرة');
                }

                return redirect()->route('m.show', ['fin', $prev->id])->with('ok', 'أُنشئت من قبل — هذه فاتورتها');
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
        });
    }

}

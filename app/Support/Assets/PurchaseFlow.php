<?php

namespace App\Support\Assets;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * **مسارُ الشراء — آلةُ الحالة والاستلامُ للسطحَين** (خطّة التطبيق 4.6). مُستخرَجةٌ من
 * `PurchaseController` كي يستدعيها الويبُ (`purchase/{id}/act`) والجوالُ
 * (`purchases/{id}/receive`) بالقاعدة نفسِها:
 *
 *  • **البوّابة** `purchases:e` + الأمرُ ضمن `hub_scope`.
 *  • **آلةُ الحالة خادميّة**: الملغى/المرتجع نهايتان، وكلُّ إجراءٍ من حالاتٍ مسموحةٍ فقط.
 *  • **الاستلام** داخل معاملةٍ على صفٍّ مقفول (idempotent عبر `meta.stock_moves`)،
 *    يولّد حركاتِ مخزونٍ مؤكّدة بمطابقةٍ منطَّقةٍ بالشركة وقفلٍ صفّيّ على الصنف.
 */
final class PurchaseFlow
{
    /** [الإجراء => [الحالات المسموح منها، الحالة الهدف]] */
    public const FLOW = [
        'submit'  => [['مسودة'], 'بانتظار الاعتماد'],
        'approve' => [['بانتظار الاعتماد'], 'معتمد'],
        'send'    => [['معتمد'], 'أُرسل للمورد'],
        'receive' => [['أُرسل للمورد', 'معتمد'], 'مستلم'],
        'return'  => [['مستلم'], 'مرتجع'],
    ];

    /** الحالتان النهائيّتان — لا إجراءَ يبعثهما (عدا الفوترة) */
    public const TERMINAL = ['ملغى', 'مرتجع'];

    /** بوّابةُ إجراءات الشراء: `purchases:e` ثمّ النطاق (٤٠٤ خارجه) */
    public static function authorize(User $actor, string $id): Purchase
    {
        abort_unless(hub_can($actor, 'purchases', 'e'), 403, 'إجراءات الشراء تتطلب صلاحية تعديل');

        return hub_scope(Purchase::query(), 'purchases', $actor)->findOrFail($id);
    }

    /** آلةُ الحالة المفروضة على الخادم — الإجراءُ من حالةٍ غير مسموحة ٤٢٢ */
    public static function guardTransition(Purchase $p, string $do): void
    {
        abort_if(in_array((string) $p->status, self::TERMINAL, true) && $do !== 'bill', 422,
            'المستند ' . $p->status . ' — أنشئ أمر شراء جديداً بدل إحيائه');

        if (isset(self::FLOW[$do])) {
            [$from] = self::FLOW[$do];
            abort_unless(in_array((string) $p->status, $from, true), 422,
                'لا يصح هذا الإجراء من حالة «' . $p->status . '» — المسموح منه: ' . implode('، ', $from));
        }
    }

    /**
     * **الاستلام**: يختم تاريخه ويولّد حركات مخزونٍ مؤكّدة من بنود الأمر بمطابقة الاسم
     * الحرفيّ لأصناف المخزون — البنودُ بلا صنفٍ مطابقٍ تُترك بلا حركة (لا تخمين في الأرصدة).
     * يعيد `[made, skipped, already]` — `already=true` حين سبقه استلامٌ متزامن (لا تكرار).
     *
     * @return array{0:int, 1:int, 2:bool}
     */
    public static function receive(User $actor, Purchase $p): array
    {
        return DB::transaction(function () use ($actor, $p) {
            $p = Purchase::whereKey($p->getKey())->lockForUpdate()->firstOrFail();
            $meta = (array) $p->meta;
            $made = 0; $skipped = 0;

            // سبقتنا نقرةٌ متزامنة داخل القفل — لا تكرار للحركات (idempotent)
            if ($p->status === 'مستلم' || ! empty($meta['stock_moves'])) {
                return [$made, $skipped, true];
            }

            $lines = Items::parse((string) $p->items);
            $supplierName = $p->supplier_id ? (string) (Supplier::whereKey($p->supplier_id)->value('name') ?? '') : '';
            $moveIds = [];
            foreach ($lines as $line) {
                $qty = (float) ($line['qty'] ?? 0);
                $name = trim((string) ($line['desc'] ?? ''));
                if ($qty <= 0 || $name === '') { $skipped++; continue; }

                // مطابقةٌ منطَّقةٌ بالشركة وحاسمةُ الترتيب، وقفلٌ صفّيّ على الصنف (لا تحديثَ ضائع)
                $item = \App\Models\StockItem::whereNull('deleted_at')->where('name', $name)
                    ->when($p->company_id,
                        fn ($q) => $q->where(fn ($w) => $w->where('company_id', $p->company_id)->orWhereNull('company_id')),
                        fn ($q) => $q->whereNull('company_id'))
                    ->orderByRaw('company_id IS NULL')->orderBy('id')
                    ->lockForUpdate()
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
                                   'posted_by' => $actor->getKey(), 'delta' => $qty, 'purchase_id' => $p->id],
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

            return [$made, $skipped, false];
        });
    }
}

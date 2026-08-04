<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockMove;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل حركات المخزون: كان الدفتران متوازيين لا يلتقيان — الحركة تُسجَّل
 * و«مؤكدة» لا تفعل شيئاً ورصيد الصنف جامد. التأكيد الآن يحرّك الرصيد
 * بإشارة النوع داخل معاملة، بمنع ترحيلٍ مزدوج، والإلغاء يعكس الأثر،
 * وحالة الصنف (نفد/منخفض/متاح) تُشتق فوراً فتحيا مسارات التنبيه الجاهزة.
 */
class StockController extends Controller
{
    /** أثر كل نوع على كمية الصنف: موجب دخول، سالب خروج، صفر تحويل/جرد (يُسوّى) */
    protected const SIGNS = [
        'استلام' => 1, 'مرتجع وارد' => 1,
        'صرف' => -1, 'تالف' => -1, 'مرتجع صادر' => -1,
        'تحويل بين المستودعات' => 0, 'جرد' => 0,
    ];

    public function act(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'stockmv', 'e'), 403, 'ترحيل الحركات يتطلب صلاحية تعديلها');
        $mv = hub_scope(StockMove::query(), 'stockmv')->findOrFail($id);

        return match (hub_str($r->input('do'))) {
            'confirm' => $this->confirm($mv),
            'cancel'  => $this->cancel($mv),
            default   => abort(422),
        };
    }

    protected function confirm(StockMove $mv)
    {
        // كامل الترحيل داخل معاملة على صفٍّ مقفول: الحارس (posted_at/ملغاة) وفحص
        // كفاية الرصيد والخصم يقرأون الحالة المُثبَتة لا نسخةً قديمة في الذاكرة —
        // فتأكيدان متزامنان يتسلسلان بلا خصمٍ مزدوج ولا ضياع تحديث ولا رصيدٍ سالب.
        $mv = DB::transaction(function () use ($mv) {
            $mv = StockMove::whereKey($mv->getKey())->lockForUpdate()->firstOrFail();
            $meta = (array) ($mv->meta ?? []);
            abort_if(! empty($meta['posted_at']), 422, 'الحركة مُرحَّلة أصلاً — لا ترحيل مزدوج');
            abort_if($mv->status === 'ملغاة', 422, 'حركة ملغاة لا تُرحَّل — أنشئ غيرها');
            abort_unless(array_key_exists((string) $mv->kind, self::SIGNS), 422, 'نوع حركة غير معروف');

            $item = $mv->item_id ? StockItem::whereKey($mv->item_id)->lockForUpdate()->first() : null;
            abort_unless($item, 422, 'اربط الحركة بصنفٍ من المخزون أولاً');

            // تحقق بنيوي حسب النوع قبل أي أثر
            if ($mv->kind === 'تحويل بين المستودعات') {
                abort_if(blank($mv->from_wh) || blank($mv->to_wh), 422, 'التحويل يلزمه المستودعان: من وإلى');
            }
            $qty = (float) $mv->qty;
            abort_if($qty <= 0 && $mv->kind !== 'جرد', 422, 'كمية الحركة يجب أن تكون أكبر من صفر');

            $sign = self::SIGNS[$mv->kind];
            $delta = $mv->kind === 'جرد'
                ? $qty - (float) $item->qty          // الجرد تسوية إلى العدد المعدود
                : $sign * $qty;

            if ($delta < 0 && (float) $item->qty + $delta < 0) {
                abort(422, 'الرصيد لا يكفي: المتاح ' . rtrim(rtrim(number_format((float) $item->qty, 3), '0'), '.')
                    . ' والمطلوب صرف ' . rtrim(rtrim(number_format(abs($delta), 3), '0'), '.'));
            }

            $item->qty = (float) $item->qty + $delta;
            $item->saveQuietly();
            hub_stock_sync($item);

            StockMove::$posting = true;
            try {
                $mv->status = 'مؤكدة';
                $mv->meta = $meta + ['posted_at' => now()->toIso8601String(),
                                     'posted_by' => auth()->id(), 'delta' => $delta];
                $mv->save();
            } finally {
                StockMove::$posting = false;
            }

            return $mv;
        });
        hub_audit('ترحيل حركة مخزون', 'stockmv', $mv->id, (string) $mv->doc_no);

        return back()->with('ok', '📦 رُحّلت الحركة وتحرّك رصيد الصنف — حالته الآن: ' . $mv->item?->fresh()?->status);
    }

    protected function cancel(StockMove $mv)
    {
        // نفس التصليب: الصفّ يُقفل ويُعاد قراءة الحالة داخل المعاملة قبل عكس الأثر —
        // فإلغاءان متزامنان لا يعكسان الدلتا مرتين.
        $mv = DB::transaction(function () use ($mv) {
            $mv = StockMove::whereKey($mv->getKey())->lockForUpdate()->firstOrFail();
            $meta = (array) ($mv->meta ?? []);
            abort_if($mv->status === 'ملغاة', 422, 'الحركة ملغاة أصلاً');

            // إن كانت مُرحَّلة يُعكس أثرها أولاً — الإلغاء الصادق يعيد الرصيد
            if (! empty($meta['posted_at']) && ($item = StockItem::whereKey($mv->item_id)->lockForUpdate()->first())) {
                $delta = (float) ($meta['delta'] ?? 0);
                // حارس النقص نفسُه الذي يحرس الترحيل (confirm): عكسُ استلامٍ استُهلك
                // رصيدُه بحركاتٍ لاحقة يُنزل qty تحت الصفر. يُرفض بوضوح لا بصمت.
                if ((float) $item->qty - $delta < 0) {
                    abort(422, 'لا يمكن إلغاء الحركة: عكسُ أثرها يُنزل الرصيد تحت الصفر — المتاح '
                        . rtrim(rtrim(number_format((float) $item->qty, 3), '0'), '.')
                        . ' وعكسُها يخصم ' . rtrim(rtrim(number_format($delta, 3), '0'), '.')
                        . '. عالِج الحركات اللاحقة على الصنف أولاً.');
                }
                $item->qty = (float) $item->qty - $delta;
                $item->saveQuietly();
                hub_stock_sync($item);
            }
            StockMove::$posting = true;
            try {
                $mv->status = 'ملغاة';
                $mv->meta = array_merge($meta, ['reversed_at' => now()->toIso8601String()]);
                $mv->save();
            } finally {
                StockMove::$posting = false;
            }

            return $mv;
        });
        hub_audit('إلغاء حركة مخزون', 'stockmv', $mv->id, (string) $mv->doc_no);
        $meta = (array) ($mv->meta ?? []);

        return back()->with('ok', 'أُلغيت الحركة' . (! empty($meta['posted_at']) ? ' وعُكس أثرها على الرصيد' : ''));
    }
}

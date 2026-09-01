<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * **محرّكُ الفعل الأفضل التالي** — لا يجبر المستخدمَ على حفظ دورة الحياة.
 *
 * توصيةٌ بحتةٌ بلا مخزن: يقرأ حالةَ الكيان القائمةَ ويُرجع الخطواتِ المنطقيةَ
 * التالية مرتَّبةً، بروابطَ لوظائفِ النظام الفعلية (نفسِ مسارات التحويل/التجديد
 * القائمة). يعتمد التبعيّات: «عرضٌ مقبولٌ يحتاج عقداً» ← أكمِل العقدَ لا ابدأ المشروعَ.
 *
 * @return array<int, array{label:string, why:string, url:string, primary?:bool}>
 */
class NextAction
{
    public static function for(string $module, Model $m): array
    {
        return match ($module) {
            'quotes'   => self::quote($m),
            'contracts' => self::contract($m),
            'projects' => self::project($m),
            default    => [],
        };
    }

    protected static function step(string $label, string $why, string $url, bool $primary = false): array
    {
        return compact('label', 'why', 'url', 'primary');
    }

    protected static function quote(Model $q): array
    {
        $status = (string) $q->status;
        $meta = (array) (is_array($q->meta) ? $q->meta : (json_decode((string) $q->meta, true) ?: []));
        $show = route('m.show', ['quotes', $q->id]);
        $out = [];

        if (in_array($status, ['مسودة', ''], true)) {
            $out[] = self::step('أرسِل العرض للعميل', 'العرضُ مسودة — أرسله ليبدأ سباقُ القبول.', $show, true);

            return $out;
        }
        if ($status === 'مرسل') {
            $out[] = self::step('تابِع ردَّ العميل', 'أُرسل ولم يُقبَل بعد — تابع القرار أو حدّد مهلةً.', $show, true);

            return $out;
        }
        if ($status === 'مقبول') {
            // التبعيّة: مشروعٌ أولاً إن لم يوجد؛ ثم العقدُ والدفعةُ المقدّمة
            if (empty($meta['project_id']) && empty($meta['engagement_id'])) {
                $out[] = self::step('حوّله لمشروعٍ وارتباط', 'قُبل ولم يُحوَّل — التحويلُ يبدأ التسليمَ ويربط الربحية.', $show, true);
            } else {
                $out[] = self::step('افتح المشروعَ وابدأ الإقلاع', 'حُوِّل — تابع إقلاعَ التنفيذ.', route('m.show', ['projects', $meta['project_id'] ?? '']), true);
            }
            if (empty($meta['contract_id'])) {
                $out[] = self::step('أصدِر عقداً إن لزم', 'لا عقدَ موصولٌ بعد — أنشئه إن كان العرفُ يقتضيه.', $show);
            }
            if (empty($meta['invoice_id'])) {
                $out[] = self::step('أصدِر دفعةً مقدّمة إن وُجدت', 'لا فاتورةَ بعد — أصدِر الدفعةَ المقدّمة حسب جدول الدفع.', $show);
            }

            return $out;
        }
        if ($status === 'محوّل' && ! empty($meta['project_id'])) {
            $out[] = self::step('افتح المشروع', 'حُوِّل لمشروع — تابع التنفيذ.', route('m.show', ['projects', $meta['project_id']]), true);
        }

        return $out;
    }

    protected static function contract(Model $c): array
    {
        $show = route('m.show', ['contracts', $c->id]);
        $out = [];
        $end = $c->date_end ?? $c->end ?? null;
        $active = ! in_array((string) $c->status, ['منتهٍ', 'ملغى', 'مجدَّد', 'مسودة'], true);
        if ($end && $active) {
            try {
                // موجَبٌ = أيامٌ تبقّت للانتهاء؛ سالبٌ = مرّ الانتهاءُ (متأخرٌ عن التجديد)
                $days = (int) \Illuminate\Support\Carbon::parse($end)->startOfDay()->diffInDays(now()->startOfDay(), false) * -1;
                if ($days >= 0 && $days <= 30) {
                    $out[] = self::step('ابدأ التجديد', 'العقدُ ينتهي خلال ' . $days . ' يوماً — ابدأ التجديدَ الآن.', $show, true);
                } elseif ($days < 0) {
                    $out[] = self::step('عالِج التجديدَ المتأخر', 'انتهى العقدُ منذ ' . abs($days) . ' يوماً — جدّده أو أغلِقه.', $show, true);
                }
            } catch (\Throwable $e) {}
        }
        if (in_array((string) $c->status, ['بانتظار التوقيع', 'مُرسل للتوقيع'], true)) {
            $out[] = self::step('ذكّر بالتوقيع', 'ينتظر توقيعَ العميل — أرسِل تذكيراً.', $show);
        }

        return $out;
    }

    protected static function project(Model $p): array
    {
        $show = route('m.show', ['projects', $p->id]);
        $out = [];
        try {
            $h = hub_project_health($p->id);
            if (($h['score'] ?? 100) < 55) {
                $out[] = self::step('راجِع عواملَ التعثّر', 'صحةُ المشروع ' . ($h['score'] ?? '?') . '/١٠٠ — عالِج العواملَ المعلنة.', $show, true);
            }
        } catch (\Throwable $e) {}

        return $out;
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** محلل بنود المستندات المشترك (عروض الأسعار وأوامر الشراء): «وصف | كمية | سعر | وحدات الكرتونة؟» لكل سطر */
class Items
{
    public static function parse(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cols = array_map('trim', explode('|', $line));
            $qty = isset($cols[1]) && is_numeric($cols[1]) ? (float) $cols[1] : null;
            $prc = isset($cols[2]) && is_numeric($cols[2]) ? (float) $cols[2] : null;
            // عمودٌ رابع اختياري: «وحدات الكرتونة» لهذا البند — تجاوزٌ يدوي لتعبئة المنتج
            $per = isset($cols[3]) && is_numeric($cols[3]) && (int) $cols[3] > 0 ? (int) $cols[3] : null;
            $rows[] = ['desc' => $cols[0], 'qty' => $qty, 'price' => $prc, 'per' => $per,
                       'sum' => ($qty !== null && $prc !== null) ? $qty * $prc : null];
        }

        return $rows;
    }

    /**
     * إثراء البنود بحساب الكراتين (تعبئة المنتجات):
     * وحدات الكرتونة لكل بند تأتي من العمود الرابع الصريح، وإلا من مطابقة اسم البند
     * بمنتج مخزون له `carton_qty` (مُنطَّق بالشركة إن مُرِّرت). الكمية الأقل من
     * الكرتونة **لا تعيق** شيئاً — تُعرض ككسر كرتونة (وحدات سائبة).
     *
     * يُضيف لكل صف: per (المصدر النهائي)، cartons (الكراتين الكاملة)، loose
     * (الوحدات السائبة)، cartonText (وصفٌ عربي جاهز للعرض أو '' حين لا تعبئة).
     */
    public static function cartons(array $rows, ?string $companyId = null): array
    {
        // خريطة اسم المنتج → وحدات الكرتونة، للبنود بلا تجاوز صريح
        $needLookup = collect($rows)->contains(fn ($r) => ($r['per'] ?? null) === null
            && trim((string) ($r['desc'] ?? '')) !== '');
        $byName = $needLookup ? static::stockCartonMap($companyId) : [];

        foreach ($rows as &$r) {
            $per = $r['per'] ?? null;
            if ($per === null) {
                $key = mb_strtolower(trim((string) ($r['desc'] ?? '')));
                $per = $byName[$key] ?? null;
            }
            $qty = $r['qty'] ?? null;
            $r['per']        = $per;
            $r['cartons']    = null;
            $r['loose']      = null;
            $r['cartonText'] = '';
            if ($per !== null && $per > 0 && $qty !== null) {
                $full  = intdiv((int) floor($qty), $per);
                $loose = (float) $qty - ($full * $per);
                // قد تكون الكمية كسرية (وزن مثلاً) — نُبقي السائب بمنزلتين ونقلّم الأصفار
                $looseTxt = rtrim(rtrim(number_format($loose, 2), '0'), '.');
                $r['cartons']    = $full;
                $r['loose']      = $loose;
                $r['cartonText'] = static::cartonText($full, $loose, $looseTxt);
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * تفكيك كميةٍ واحدة إلى كراتين ووحدات سائبة (لحركة مخزون أو صنف مفرد).
     * يُرجع ['cartons','loose','text'] أو null حين لا تعبئة صالحة.
     */
    public static function pack(?float $qty, ?int $per): ?array
    {
        if ($qty === null || $per === null || $per <= 0) return null;
        $full  = intdiv((int) floor($qty), $per);
        $loose = (float) $qty - ($full * $per);
        $looseTxt = rtrim(rtrim(number_format($loose, 2), '0'), '.');

        return ['cartons' => $full, 'loose' => $loose,
                'text' => static::cartonText($full, $loose, $looseTxt)];
    }

    /** إجمالي الكراتين الكاملة عبر بنود مُثراة بـcartons() — للملخص أسفل المستند */
    public static function totalCartons(array $enriched): int
    {
        return collect($enriched)->sum(fn ($r) => (int) ($r['cartons'] ?? 0));
    }

    /** هل لأيٍّ من البنود تعبئةٌ معرّفة؟ — يقرّر إظهار عمود الكراتين أصلاً */
    public static function anyCartons(array $enriched): bool
    {
        return collect($enriched)->contains(fn ($r) => ($r['per'] ?? null) !== null);
    }

    /** وصفٌ عربي موجز: «٢ كرتونة + ٦ وحدة» / «٣ كرتونة» / «٦ وحدة» */
    protected static function cartonText(int $full, float $loose, string $looseTxt): string
    {
        $parts = [];
        if ($full > 0) $parts[] = $full . ' كرتونة';
        if ($loose > 0) $parts[] = $looseTxt . ' وحدة';
        if (! $parts) return '0';

        return implode(' + ', $parts);
    }

    /** اسم المنتج (lowercase) → carton_qty، من جدول المخزون بعزل الشركة إن وُجدت */
    protected static function stockCartonMap(?string $companyId): array
    {
        if (! Schema::hasTable('stock_items') || ! Schema::hasColumn('stock_items', 'carton_qty')) {
            return [];
        }
        $q = DB::table('stock_items')->whereNull('deleted_at')
            ->whereNotNull('carton_qty')->where('carton_qty', '>', 0)
            ->whereNotNull('name')->where('name', '!=', '');
        if ($companyId !== null && $companyId !== '' && Schema::hasColumn('stock_items', 'company_id')) {
            // يشمل الصنف بلا شركة (مشترك) + صنف الشركة النشطة — لا يفضح صنف شركةٍ أخرى
            $q->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'));
        }

        // **صنفُ الشركة يفوز، لا آخرُ صفٍّ يُرجعه المحرّك.** كانت الخريطة تُبنى
        // بلا ترتيب و`$map[$key] = ...` يدهس ما سبقه — فحين يحمل صنفٌ مشترك
        // وصنفُ شركةٍ الاسمَ نفسَه صار الفائزُ قرعةً تختلف بين المحرّكين، وهذه
        // التعبئةُ **تُطبع على مستند العميل**. الترتيب: المخصَّص أوّلاً (‏NULL
        // أخيراً) ثم `id` فاصلاً، ولا يُدهَس مفتاحٌ كُتب.
        $q->orderByRaw('company_id is null')->orderBy('id');

        $map = [];
        foreach ($q->get(['id', 'name', 'company_id', 'carton_qty']) as $row) {
            $key = mb_strtolower(trim($row->name));
            if (! array_key_exists($key, $map)) $map[$key] = (int) $row->carton_qty;
        }

        return $map;
    }
}

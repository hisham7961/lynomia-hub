<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجل الحقول المقيسة: أي حقلٍ رقمي **يتغيّر على السجل** بدل أن يُنشئ سجلاً
 * جديداً — المتابعون، الإعجابات، التحميلات، التقييم. الحقل وحده يحفظ آخر قيمة
 * فقط ويدهس ما قبلها؛ فنُسجّل مع كل حفظٍ نقطةً في السلسلة الزمنية، ويلتقط
 * `hub:metrics-snapshot` القيم يومياً — فتُبنى الذاكرة حتى بلا n8n.
 */
class Metrics
{
    /** module => [metric => column] */
    public const MEASURED = [
        'social' => [
            'followers' => 'followers',
        ],
        'posts' => [
            'views' => 'views', 'reach' => 'reach', 'impr' => 'impr',
            'likes' => 'likes', 'comments' => 'comments2', 'shares' => 'shares',
            'saves' => 'saves', 'clicks' => 'clicks',
        ],
        'apps' => [
            'downloads' => 'downloads', 'rating' => 'rating', 'reviews' => 'reviews',
        ],
        // المنافس أيضاً رقمٌ متحرّك: متابعوه وتحميلات تطبيقه وتقييمه —
        // الرقم وحده لا يقول أينمو أم ينكمش
        // المواقع: زوّار وجلسات وتحويلات تصل من GA عبر n8n إلى /api/v1/metrics
        'websites' => [
            'lighthouse' => 'lighthouse',
        ],
        'competitors' => [
            'followers' => 'followers', 'downloads' => 'app_downloads', 'rating' => 'app_rating',
        ],
    ];

    /** المقاييس المعلنة لوحدة (أسماؤها فقط) */
    public static function of(string $module): array
    {
        return array_keys(self::MEASURED[$module] ?? []);
    }

    /**
     * التقاط قيم سجلٍ واحد. الحبيبة ساعة: حفظان في الساعة نفسها يُحدِّثان
     * النقطة ذاتها فلا تتضخّم السلسلة بضغطات الحفظ.
     */
    public static function capture(string $module, Model $m, string $source = 'manual', $at = null): int
    {
        $map = self::MEASURED[$module] ?? [];
        if (! $map || ! Schema::hasTable('metric_points')) return 0;

        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now()->startOfHour();
        $n = 0;
        foreach ($map as $metric => $col) {
            $v = $m->{$col} ?? null;
            if ($v === null || $v === '') continue;      // «لا قياس» ليس صفراً
            hub_metric_put($module, $m->id, $metric, (float) $v, $at, $source);
            $n++;
        }

        return $n;
    }

    /**
     * لقطةٌ يومية لكل السجلات المقيسة — تُشغَّل من المجدول.
     * تُكتب عند بداية اليوم كي تكون نقطةً واحدة لليوم مهما تكرّر التشغيل.
     */
    public static function snapshotAll(string $source = 'sync'): array
    {
        $out = [];
        $at = now()->startOfDay();

        foreach (self::MEASURED as $module => $map) {
            $def = hub_mod($module);
            if (! $def || ! Schema::hasTable($def['table'])) continue;

            $cols = array_values(array_filter($map, fn ($c) => Schema::hasColumn($def['table'], $c)));
            if (! $cols) continue;

            $q = DB::table($def['table']);
            if (Schema::hasColumn($def['table'], 'deleted_at')) $q->whereNull('deleted_at');

            $n = 0;
            foreach ($q->get(array_merge(['id'], $cols)) as $row) {
                foreach ($map as $metric => $col) {
                    if (! in_array($col, $cols, true)) continue;
                    $v = $row->{$col} ?? null;
                    if ($v === null || $v === '') continue;
                    hub_metric_put($module, $row->id, $metric, (float) $v, $at, $source);
                    $n++;
                }
            }
            if ($n) $out[$module] = $n;
        }

        return $out;
    }
}

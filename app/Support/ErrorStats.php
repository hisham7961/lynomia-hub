<?php

namespace App\Support;

use App\Models\ErrorEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * (WP-3.4) **قارئُ إحصاءات الأخطاء الواحد** — خمسُ نسخٍ متباعدة كانت تعدّ
 * «وضعَ الأخطاء» كلٌّ بدلالتها: مركزُ الأخطاء، ومركزُ التشغيل (OpsController)،
 * والموجزُ الصباحي (MorningController)، ونموذجُ الصحّة (Health::errors)،
 * ونبضُ SysMonitor. من اليوم الدلالةُ تُقرأ من هنا والمستهلكون يعرضون ولا
 * يعيدون العدّ — والمخرجاتُ الموروثة ثُبِّتت حرفياً في `ErrorDashboardTest`
 * قبل التوحيد: إصلاحُ بنيةٍ لا تغييرُ دلالة.
 *
 * قاعدتا الدلالة:
 *  — «غيرُ المحلول» (نافذةُ الصحّة وبطاقةُ «حرجة») **يشمل المتجاهَل**: إخفاءُ
 *    عطلٍ حرج قرارُ عرضٍ لا شفاء، فلا تقول اللوحةُ «صفر» والمراقبةُ غيرَه.
 *  — «المفتوح» (بطاقاتُ العمل والأنواع) يستثني المحلولَ **والمتجاهَلَ** معاً:
 *    ما ينتظر عملاً فعلاً.
 */
class ErrorStats
{
    /** الحالتان المحسومتان — ما عداهما «مفتوح» ينتظر عملاً */
    public const SETTLED = ['محلول', 'متجاهَل'];

    /**
     * نافذةُ الصحّة (Health::errors): آخرُ ساعة، غيرُ المحلول فقط — الأعدادُ
     * حرفياً كما كانت داخل Health قبل التوحيد (حارسا العمود والاستثناء يبقيان
     * عند المستهلك كما كانا). حرجٌ/عالٍ بالبصمات، والتكرار بمجموع `count`.
     */
    public static function healthWindow(): array
    {
        $q = DB::table('error_events')->where('status', '!=', 'محلول')
            ->where('last_seen', '>=', now()->subHour());
        $sev = hub_has_col('error_events', 'severity');

        return [
            'critical_1h' => $sev ? (int) (clone $q)->where('severity', 'CRITICAL')->count() : 0,
            'high_1h'     => $sev ? (int) (clone $q)->where('severity', 'HIGH')->count() : 0,
            'hits_1h'     => (int) (clone $q)->sum('count'),
        ];
    }

    /**
     * موجزُ مركز التشغيل (٧ أيام) — كما كان في OpsController حرفياً.
     * **وقائعُ لا بصمات** (v2.338): البطيءُ وAPI بمجموع `count` لا بعدّ الصفوف
     * المجمَّعة — ألفُ بطءٍ على مسارٍ واحد ألفٌ لا واحد.
     * يرمي عند غياب الجدول — فيبقى «غير متاح» الصادق عند المستهلك (§25).
     */
    public static function opsSummary(): array
    {
        return [
            'new'  => ErrorEvent::where('status', 'جديد')->count(),
            'week' => ErrorEvent::where('last_seen', '>=', now()->subDays(7))->sum('count'),
            'slow' => (int) ErrorEvent::where('kind', 'slow')->where('last_seen', '>=', now()->subDays(7))->sum('count'),
            'api'  => (int) ErrorEvent::where('kind', 'api')->where('last_seen', '>=', now()->subDays(7))->sum('count'),
        ];
    }

    /**
     * أبرزُ الجديد بأسمائه (الموجزُ الصباحي): الأعلى تكراراً أولاً — والتعادل
     * يُحسم بآخر ظهورٍ ثم بالمعرّف (لا قرعةَ ترتيبٍ بين المحرّكين).
     */
    public static function topNew(int $limit = 4)
    {
        return DB::table('error_events')->where('status', 'جديد')
            ->orderByDesc('count')->orderByDesc('last_seen')->orderByDesc('id')
            ->limit(max(1, $limit))->get(['id', 'message', 'file', 'line', 'kind', 'count']);
    }

    /**
     * بطاقاتُ اللوحة التنفيذية العشر (§4.1): مفتوحة، حرجة (دلالةُ الصحّة)،
     * جديدةُ اليوم، وقوعاتُ ٢٤س ومستخدمو ٢٤س **من جدول العيّنات** (لا من
     * `count` الذي ينسب تاريخَ البصمة كلَّه لليوم)، انحدارات، والأنواعُ الأربعة.
     * `null` = المصدرُ غيرُ متاح (عمودٌ/جدولٌ قبل ترحيله) — تُعرض «—» لا صفراً كاذباً.
     */
    public static function cards(): array
    {
        $taxonomy = hub_has_col('error_events', 'severity');
        $lifecycle = hub_has_col('error_events', 'regressed_at');
        $samples = hub_has_col('error_occurrences', 'occurred_at');

        $out = [
            'open'       => (int) ErrorEvent::whereNotIn('status', self::SETTLED)->count(),
            'new_status' => (int) ErrorEvent::where('status', 'جديد')->count(),
            'critical'   => $taxonomy
                ? (int) ErrorEvent::where('status', '!=', 'محلول')->where('severity', 'CRITICAL')->count() : null,
            'new24'      => (int) ErrorEvent::where('first_seen', '>=', now()->subDay())->count(),
            'regressions' => $lifecycle
                ? (int) ErrorEvent::whereNotNull('regressed_at')->where('status', '!=', 'محلول')->count() : null,
            'hits24' => null, 'users24' => null,
            'kinds' => ['php' => 0, 'api' => 0, 'js' => 0, 'slow' => 0],
            'by_severity' => [],
        ];

        if ($samples) {
            try {
                $out['hits24'] = (int) DB::table('error_occurrences')
                    ->where('occurred_at', '>=', now()->subDay())->count();
                $out['users24'] = (int) DB::table('error_occurrences')
                    ->where('occurred_at', '>=', now()->subDay())
                    ->whereNotNull('user_id')->distinct()->count('user_id');
            } catch (\Throwable $e) {
                // جدولٌ أُسقط تحت كاشٍ دافئ — «—» أصدقُ من صفر
            }
        }

        foreach (ErrorEvent::whereNotIn('status', self::SETTLED)
                     ->selectRaw('kind, COUNT(*) n')->groupBy('kind')->pluck('n', 'kind') as $k => $n) {
            $out['kinds'][$k] = (int) $n;
        }
        if ($taxonomy) {
            // الشدّةُ المفتوحة لمرشِّح القائمة (كانت داخل المتحكّم) — الدلالةُ نفسُها
            $out['by_severity'] = ErrorEvent::whereNotIn('status', self::SETTLED)
                ->selectRaw('severity, COUNT(*) n')->groupBy('severity')->pluck('n', 'severity')->all();
        }

        return $out;
    }

    /**
     * «الأخطاء عبر الزمن» **من العيّنات** (§4.4): كلُّ وقوعٍ في دلو ساعته
     * الحقيقية — لا إسنادُ عدِّ البصمة كلِّه لساعة `last_seen` كما يفعل
     * `SysMonitor::pulse` فيُرسم خطأُ الخمسين عموداً واحداً. الدلو ساعةٌ حتى
     * يومين، ويومٌ فوق ذلك. `ok=false` = الجدولُ غيرُ متاح (صراحةٌ لا أصفار).
     */
    public static function overTime(TimeRange $range): array
    {
        $out = ['ok' => false, 'unit' => $range->minutes() <= 2880 ? 'hour' : 'day',
                'buckets' => [], 'total' => 0, 'max' => 1];
        if (! hub_has_col('error_occurrences', 'occurred_at')) return $out;

        try {
            $hourly = $out['unit'] === 'hour';
            $fmt = $hourly ? 'Y-m-d H' : 'Y-m-d';
            $slots = [];
            $cur = $hourly ? $range->from->copy()->startOfHour() : $range->from->copy()->startOfDay();
            // سقفُ ٨٠٠ دلو: مدى ٧٣٢ يوماً (سقفُ TimeRange المخصّص) يبقى مرسوماً يوماً بيوم
            for ($i = 0; $cur->lt($range->to) && $i < 800; $i++) {
                $slots[$cur->format($fmt)] = ['at' => $cur->copy(), 'n' => 0];
                $hourly ? $cur->addHour() : $cur->addDay();
            }

            // عمودُ الوقت وحدَه (فهرس occurred_at) والطيُّ هنا — نمطُ pulse نفسُه،
            // والحجمُ مسقوفٌ بالبناء: keep لكل بصمة + تقليمُ العمر
            foreach (DB::table('error_occurrences')
                         ->where('occurred_at', '>=', $range->from)->where('occurred_at', '<', $range->to)
                         ->orderBy('occurred_at')->orderBy('id')->pluck('occurred_at') as $t) {
                $k = Carbon::parse($t)->format($fmt);
                if (isset($slots[$k])) { $slots[$k]['n']++; $out['total']++; }
            }

            $out['buckets'] = array_values($slots);
            $out['max'] = max(1, $slots ? max(array_column($slots, 'n')) : 0);
            $out['ok'] = true;
        } catch (\Throwable $e) {
            return ['ok' => false, 'unit' => $out['unit'], 'buckets' => [], 'total' => 0, 'max' => 1];
        }

        return $out;
    }

    /**
     * شرائحُ الدونات للمفتوح (بلا محلولٍ ومتجاهَل): حسب الصنف وحسب الشدّة —
     * استعلامٌ مجمَّعٌ واحد، والتسمياتُ عربيةٌ من ErrorTaxonomy، والترتيبُ
     * بالقيمة نزولاً ثم بالمفتاح (لا قرعةَ بين المحرّكين).
     */
    public static function donuts(): array
    {
        $out = ['category' => [], 'severity' => []];
        if (! hub_has_col('error_events', 'severity')) return $out;

        try {
            $rows = ErrorEvent::whereNotIn('status', self::SETTLED)
                ->selectRaw('category, severity, COUNT(*) n')
                ->groupBy('category', 'severity')->orderBy('category')->orderBy('severity')->get();
        } catch (\Throwable $e) {
            return $out;
        }

        $cat = [];
        $sev = [];
        foreach ($rows as $r) {
            $ck = (string) ($r->category ?: 'UNKNOWN');
            $sk = (string) ($r->severity ?: 'UNKNOWN');
            $cat[$ck] = ($cat[$ck] ?? 0) + (int) $r->n;
            $sev[$sk] = ($sev[$sk] ?? 0) + (int) $r->n;
        }
        foreach (['category' => $cat, 'severity' => $sev] as $key => $map) {
            uksort($map, fn ($a, $b) => [$map[$b], $a] <=> [$map[$a], $b]);
            $out[$key] = array_map(
                fn ($k) => ['label' => ErrorTaxonomy::LABELS[$k] ?? $k, 'value' => $map[$k]],
                array_keys($map));
        }

        return $out;
    }
}

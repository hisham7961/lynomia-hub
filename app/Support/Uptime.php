<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

/**
 * فحصٌ حيّ للأهداف الخارجية (سيرفر أو موقع) يُخزَّن **سلسلةً زمنية**:
 * `up` صفر أو واحد، و`latency` بالمللي ثانية. من السلسلة يُشتق التوافر
 * الحقيقي — لا انطباعاً ولا صفحةَ حالةٍ عند المزوّد.
 *
 * الأمان أولاً: الهدف يمر بـ`hub_outbound_ok` فلا يُستعمل النظام مِجَسّاً
 * على شبكته الداخلية (SSRF) — لا 127.0.0.1 ولا 169.254.169.254 ولا خاصّ.
 */
class Uptime
{
    /** الوحدات القابلة للمراقبة: مفتاح ⟵ [عمود الرابط، عمود التفعيل] */
    public const TARGETS = [
        'servers' => ['monitor_url', 'monitor_on'],
        'websites' => ['monitor_url', 'monitor_on'],
    ];

    /** رابط الفحص لسجل: العمود المخصص، وإلا رابط الموقع نفسه */
    public static function urlOf(string $module, Model $m): ?string
    {
        $col = self::TARGETS[$module][0] ?? null;
        $url = $col ? ($m->{$col} ?? null) : null;
        if (! $url && $module === 'websites') $url = $m->url ?? null;

        return $url ? trim((string) $url) : null;
    }

    /**
     * فحصةٌ واحدة. تُعيد ['up','code','ms','error'] وتُسجّل نقطتين في السلسلة.
     * العطل يُسجَّل صفراً لا يُتخطّى — تخطّيه يجعل التوافر ١٠٠٪ كذباً.
     */
    public static function check(string $module, Model $m, string $source = 'monitor'): array
    {
        $url = self::urlOf($module, $m);
        if (! $url) return ['up' => null, 'code' => null, 'ms' => null, 'error' => 'لا رابط فحص'];

        $gate = hub_outbound_ok($url);
        if (! $gate['ok']) return ['up' => null, 'code' => null, 'ms' => null, 'error' => $gate['why']];

        $t0 = microtime(true);
        $code = null;
        $err = null;
        try {
            // **لا اتّباع لإعادة التوجيه**: حارس hub_outbound_ok يفحص الهدف قبل
            // الطلب، وهدفٌ عامّ يردّ 302 نحو 169.254.169.254 كان يلتفّ حوله
            // فيصير النظام مِجَسّاً على شبكته. و3xx دليلُ حياةٍ كافٍ بذاته.
            // **وتثبيتُ العنوان**: curl كان يُعيد تحليلَ الاسم وقت الاتصال، فـDNS
            // متقلّبٌ يجيز عامّاً في الفحص ثم يتصل بداخليّ (TOCTOU). نثبّته على
            // العنوان الذي أجازه الحارس عبر CURLOPT_RESOLVE فلا يُعاد التحليل.
            $res = Http::withOptions([
                'allow_redirects' => false,
                'curl'            => hub_resolve_pin($url, $gate['ip']),
            ])
                ->timeout(max(1, min(30, (int) setting('monitor.timeout', 8))))   // محصورةٌ [١، ٣٠] ثانية — إعدادٌ شاذّ لا يعلّق العامل (v2.399)
                ->withHeaders(['User-Agent' => 'LynomiaHub-Monitor/1.0'])
                ->get($url);
            $code = $res->status();
            $up = $code >= 200 && $code < 400;
        } catch (\Throwable $e) {
            $up = false;
            $err = mb_substr($e->getMessage(), 0, 180);
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $at = now();
        hub_metric_put($module, $m->id, 'up', $up ? 1 : 0, $at, $source,
            array_filter(['code' => $code, 'error' => $err]));
        if ($up) hub_metric_put($module, $m->id, 'latency', $ms, $at, $source);

        return ['up' => $up, 'code' => $code, 'ms' => $ms, 'error' => $err];
    }

    /**
     * (WP-2.6) تاريخُ التوافر **مجمَّعاً في قاعدة البيانات** داخل TimeRange —
     * لا كما تفعل `hub_uptime` التي تجلب سلسلتَي ٣٠ يوماً كاملتين إلى PHP ثم
     * تعدّ (spec §14). الأرقامُ نفسُها حرفياً (يثبتها اختبارُ مساواة)، وزيادةً:
     * **فتراتُ الانقطاع** = كلُّ تتابعِ فحوصٍ `up=0` (gaps-and-islands بنافذتين —
     * فرقُ ترقيمين ثابتٌ داخل التتابع الواحد؛ يعمل على SQLite 3.25+ وMySQL 8).
     *
     * يعيد: checks/up/down/pct/ms/last/live/outages[{from,to,checks,open}] —
     * وبلا نقاطٍ يعيد null لا أصفاراً مُختلَقة: «لا قياس» ليس «صفراً».
     */
    public static function history(string $module, string $recordId, TimeRange $range): array
    {
        $empty = ['checks' => 0, 'up' => 0, 'down' => 0, 'pct' => null, 'ms' => null,
                  'last' => null, 'live' => null, 'outages' => []];
        if (! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return $empty;

        try {
            $db = \Illuminate\Support\Facades\DB::table('metric_points')
                ->where('module', $module)->where('record_id', $recordId);
            $win = fn ($q) => $range->apply($q, 'at');   // >= from و< to — سارغابل، لا whereDate

            // التوافر: عدٌّ وجمعٌ في القاعدة — صفّان يعودان لا آلاف النقاط
            $up = $win((clone $db)->where('metric', 'up'))
                ->selectRaw('COUNT(*) AS n, SUM(CASE WHEN value > 0 THEN 1 ELSE 0 END) AS good')->first();
            $n = (int) ($up->n ?? 0);
            $good = (int) ($up->good ?? 0);

            // زمنُ الاستجابة: متوسّطٌ في القاعدة (يطابق array_sum/count القديمة)
            $lat = $win((clone $db)->where('metric', 'latency'))
                ->selectRaw('COUNT(*) AS n, AVG(value) AS avg_ms')->first();

            // آخرُ فحصٍ — ترتيبٌ حتميّ: at ثم id (نقطتان في اللحظة نفسِها قرعةٌ بدونه)
            $last = $win((clone $db)->where('metric', 'up'))
                ->orderByDesc('at')->orderByDesc('id')->first(['at', 'value']);

            // فتراتُ الانقطاع: تجميعُ تتابعات up=0 داخل القاعدة — لا تحميلَ للسلسلة
            $fmt = fn ($c) => $c->format('Y-m-d H:i:s');
            $outages = \Illuminate\Support\Facades\DB::select(
                'SELECT MIN(at) AS from_at, MAX(at) AS to_at, COUNT(*) AS checks FROM ('
                . ' SELECT at, (CASE WHEN value > 0 THEN 1 ELSE 0 END) AS ok,'
                . '        ROW_NUMBER() OVER (ORDER BY at, id)'
                . '      - ROW_NUMBER() OVER (PARTITION BY (CASE WHEN value > 0 THEN 1 ELSE 0 END) ORDER BY at, id) AS grp'
                . ' FROM metric_points'
                . ' WHERE module = ? AND record_id = ? AND metric = ? AND at >= ? AND at < ?'
                . ') t WHERE ok = 0 GROUP BY grp ORDER BY MIN(at)',
                [$module, $recordId, 'up', $fmt($range->from), $fmt($range->to)]);

            $lastAt = $last ? \Illuminate\Support\Carbon::parse($last->at) : null;
            $live = $last ? ((float) $last->value > 0) : null;
            $out = [];
            foreach ($outages as $o) {
                $to = \Illuminate\Support\Carbon::parse($o->to_at);
                $out[] = [
                    'from' => \Illuminate\Support\Carbon::parse($o->from_at),
                    'to' => $to, 'checks' => (int) $o->checks,
                    // مفتوحٌ = آخرُ فحصٍ في النافذة فاشلٌ وهذا الانقطاعُ ذيلُها — «تعافى» لا تُقال قبل نجاح
                    'open' => $live === false && $lastAt !== null && $to->equalTo($lastAt),
                ];
            }

            return [
                'checks' => $n, 'up' => $good, 'down' => $n - $good,
                'pct' => $n ? round($good * 100 / $n, 2) : null,
                'ms' => ((int) ($lat->n ?? 0)) ? (int) round((float) $lat->avg_ms) : null,
                'last' => $lastAt, 'live' => $live, 'outages' => $out,
            ];
        } catch (\Throwable $e) {
            return $empty;   // قارئُ شاشةٍ لا يُسقطها — «غير متاح» أصدقُ من ٥٠٠
        }
    }

    /** كل الأهداف المفعّلة عبر الوحدات القابلة للمراقبة */
    public static function enabled(): array
    {
        $out = [];
        foreach (self::TARGETS as $mk => [$urlCol, $onCol]) {
            $def = hub_mod($mk);
            if (! $def) continue;
            try {
                if (! \Illuminate\Support\Facades\Schema::hasColumn($def['table'], $onCol)) continue;
                $class = '\\App\\Models\\' . $def['model'];
                foreach ($class::whereNull('deleted_at')->where($onCol, true)->get() as $row) {
                    if (self::urlOf($mk, $row)) $out[] = [$mk, $row];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $out;
    }
}

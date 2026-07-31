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
            $res = Http::withOptions(['allow_redirects' => ['max' => 3]])
                ->timeout((int) setting('monitor.timeout', 8))
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

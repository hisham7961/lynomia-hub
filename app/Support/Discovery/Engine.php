<?php

namespace App\Support\Discovery;

use App\Models\IdentityLookup;
use App\Support\Identity;
use Illuminate\Support\Facades\Http;

/**
 * **محرك الاستكشاف الخارجي — يسأل المزوّدين ويعود برأيٍ واحدٍ مُسمّى المصادر.**
 *
 * باركود عالميّ مجهولٌ داخلياً يُسأل عنه المزوّدون المفعَّلون بالترتيب، ثم
 * تُجمَع الإجابات: لكل حقلٍ قيمةٌ مرجَّحة، وثقةٌ محسوبة من الاتفاق (مزوّدان
 * قالا «Dell» أوثق من واحد)، ومصدرٌ محفوظ (من قال ماذا). لا اختلاقَ: حقلٌ لم
 * يقله أحدٌ يبقى غائباً — والمجهولُ يبقى مجهولاً.
 *
 * السلامة:
 *  — كلُّ رابطٍ يمرّ بحارس SSRF (`hub_outbound_ok`) وبتثبيت الـIP وبلا
 *    إعادة توجيه — نمط Uptime المحصَّن حرفياً.
 *  — مهلةٌ لكل مزوّد، وفشلُ أحدهم لا يُسقط البقية ولا أي شاشة عهدة —
 *    الاستكشافُ إثراءٌ لا اعتماد.
 *  — الكاش: باركود سُئل عنه خلال مدة الصلاحية لا يُسأل عنه ثانية.
 */
class Engine
{
    /** المزوّدون المعروفون — الترتيب هنا هو ترتيب الأولوية الافتراضي */
    public static function providers(): array
    {
        return [new UpcItemDb, new OpenFoodFacts, new OpenLibrary];
    }

    /** المفعَّلون بترتيب إعداد identity.providers (قائمةٌ بفواصل) */
    public static function enabled(): array
    {
        $keys = array_values(array_filter(array_map('trim',
            explode(',', (string) setting('identity.providers', 'upcitemdb,openfoodfacts,openlibrary')))));
        $all = collect(self::providers())->keyBy(fn ($p) => $p->key());

        return collect($keys)->map(fn ($k) => $all->get($k))->filter()->values()->all();
    }

    /**
     * الاستكشاف: كاشٌ أولاً ثم المزوّدون. تعيد
     * ['status' => found|notfound, 'suggestion' => [...حقول + confidence + sources], 'providers' => [...]]
     */
    public static function lookup(string $barcode): array
    {
        $norm = Identity::norm('gtin', $barcode);
        if ($norm === '' || ! Identity::looksGtin($norm)) {
            return ['status' => 'notfound', 'suggestion' => null, 'providers' => [], 'cached' => false];
        }

        // ── الكاش: إجابةٌ حديثة تُعاد كما هي — والعدّاد يشهد كم وفّرت ──
        $days = max(1, (int) setting('identity.cache_days', 30));
        $hit = IdentityLookup::where('norm', $norm)->first();
        if ($hit && $hit->checked_at && $hit->checked_at->gt(now()->subDays($days))) {
            $hit->increment('hits');

            return ['status' => $hit->status, 'suggestion' => $hit->result,
                'providers' => (array) $hit->providers, 'cached' => true];
        }

        // ── سؤال المزوّدين ──
        $timeout = max(500, (int) setting('identity.timeout_ms', 3500));
        $answers = [];
        $log = [];
        foreach (self::enabled() as $p) {
            if (! $p->handles($norm)) {
                continue;
            }
            $ans = self::ask($p, $norm, $timeout);
            $log[] = ['key' => $p->key(), 'label' => $p->label(),
                'ok' => is_array($ans) && isset($ans['fields']), 'why' => $ans['why'] ?? ''];
            if (is_array($ans) && isset($ans['fields'])) $answers[$p->key()] = $ans['fields'];
        }

        $suggestion = $answers ? self::aggregate($answers, $norm) : null;
        $status = $suggestion ? 'found' : 'notfound';

        // ── حفظ الكاش (updateOrCreate: مسحتان متزامنتان لا تفسدان شيئاً) ──
        IdentityLookup::updateOrCreate(['norm' => $norm], [
            'status' => $status, 'result' => $suggestion, 'providers' => $log,
            'checked_at' => now(),
        ]);

        hub_audit('استكشاف خارجي', 'products', null, $norm,
            ['after' => ['status' => $status, 'providers' => collect($log)->map(fn ($l) => $l['key'] . ($l['ok'] ? '✓' : '✗'))->implode(' ')]]);

        return ['status' => $status, 'suggestion' => $suggestion, 'providers' => $log, 'cached' => false];
    }

    /** سؤالُ مزوّدٍ واحد — محصَّنٌ ومعزولُ الفشل */
    protected static function ask(Provider $p, string $gtin, int $timeoutMs): ?array
    {
        $url = $p->url($gtin);
        $gate = hub_outbound_ok($url);
        if (! $gate['ok']) return ['why' => $gate['why']];

        try {
            $res = Http::withOptions([
                'allow_redirects' => false,
                'curl' => hub_resolve_pin($url, $gate['ip']),
            ])->timeout(max(1, (int) ceil($timeoutMs / 1000)))
              ->withHeaders(['User-Agent' => 'LynomiaHub-Identity/1.0'])
              ->get($url);

            if (! $res->ok()) return ['why' => 'HTTP ' . $res->status()];
            $fields = $p->parse((array) $res->json());

            return $fields ? ['fields' => $fields] : ['why' => 'لا يعرفه'];
        } catch (\Throwable $e) {
            return ['why' => 'تعذّر الاتصال'];
        }
    }

    /**
     * التجميع: لكل حقلٍ القيمةُ الأكثر اتفاقاً (وبأولوية الترتيب عند التعادل)،
     * وثقةٌ من نسبة الاتفاق بين من أجابوا، ومصدرٌ يسمّي القائلين.
     */
    public static function aggregate(array $answers, string $gtin): array
    {
        $fields = ['name', 'brand', 'manufacturer', 'model', 'category', 'origin', 'image'];
        $out = ['barcode' => $gtin];
        $conf = [];
        $sources = [];

        foreach ($fields as $f) {
            $votes = [];                                    // norm ⇒ [قيمة العرض, [المزوّدون]]
            foreach ($answers as $key => $ans) {
                $v = trim((string) ($ans[$f] ?? ''));
                if ($v === '') continue;
                $n = mb_strtolower(preg_replace('/\s+/u', ' ', $v));
                $votes[$n] ??= ['value' => $v, 'by' => []];
                $votes[$n]['by'][] = $key;
            }
            if (! $votes) continue;

            // الأكثر أصواتاً؛ والتعادل يحسمه ترتيبُ الأولوية (أول من أجاب)
            uasort($votes, fn ($a, $b) => count($b['by']) <=> count($a['by']));
            $win = reset($votes);
            $answered = collect($answers)->filter(fn ($a) => trim((string) ($a[$f] ?? '')) !== '')->count();

            $out[$f] = $win['value'];
            $sources[$f] = $win['by'];
            // اتفاقٌ كامل بين مزوّدين فأكثر ≈ 95، وواحدٌ منفرد ≈ 70، وخلافٌ يهبط بالنسبة
            $agree = count($win['by']) / max(1, $answered);
            $conf[$f] = (int) round(min(97, max(40, 45 + 25 * count($win['by']) + 27 * $agree - 27)));
        }

        // نوعُ المنتج بمفردات النظام — من كلمات التصنيف، والمجهول «أخرى» لا اختلاق
        $out['type'] = self::mapType(($out['category'] ?? '') . ' ' . ($out['name'] ?? ''));
        $out['confidence'] = $conf;
        $out['sources'] = $sources;
        // متوسط الثقة الإجمالي — للعرض «ثقة 91٪»
        $out['score'] = $conf ? (int) round(array_sum($conf) / count($conf)) : 0;

        return $out;
    }

    /** تصنيفٌ نصيّ خارجي ← نوعٌ من مفردات أصناف العهد (والمجهول «أخرى») */
    public static function mapType(string $text): string
    {
        $t = mb_strtolower($text);
        foreach ([
            'لابتوب' => ['laptop', 'notebook', 'لابتوب', 'محمول'],
            'هاتف' => ['phone', 'smartphone', 'iphone', 'هاتف', 'جوال'],
            'سيرفر' => ['server', 'rack', 'proliant', 'poweredge', 'سيرفر', 'خادم'],
            'شاشة' => ['monitor', 'display', 'screen', 'شاشة'],
            'سويتش' => ['switch', 'router', 'network', 'سويتش', 'راوتر'],
            'UPS' => ['ups', 'uninterruptible'],
            'طابعة' => ['printer', 'طابعة'],
            'أثاث' => ['furniture', 'desk', 'chair', 'أثاث', 'كرسي', 'مكتب'],
            'سيارة' => ['vehicle', 'car', 'سيارة', 'مركبة'],
        ] as $type => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) return $type;
            }
        }

        return 'أخرى';
    }

    /** كنسُ الكاش البائت — يستدعيه hub:automation اليومي */
    public static function prune(): int
    {
        $days = max(1, (int) setting('identity.cache_days', 30));

        return IdentityLookup::where('checked_at', '<', now()->subDays($days * 6))->delete();
    }
}

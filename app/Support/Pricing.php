<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الباقات والتسعير — من جدولٍ مسطّح إلى **قرارِ تسعير**.
 *
 * الوحدة تحمل منذ البداية كل ما يلزم قراراً حقيقياً: سعرٌ وتكلفةُ وحدةٍ وسعرٌ
 * سابق وتاريخُ تغييرٍ وحدودٌ ومزايا وسوقٌ مستهدف — وبجانبها المنافسون بأسعارهم
 * ومددِ تجاربهم. ثم تُعرض كلها **صفوفاً في جدول** فلا يظهر أن باقةً تُباع تحت
 * تكلفتها، ولا أن سعراً لم يُمسّ منذ سنتين، ولا أين نقف من السوق.
 *
 * هنا تُقرأ معاً: بطاقاتٌ لكل خدمة، وهامشٌ لكل باقة، ومقارنةٌ بالمنافس على
 * الخدمة نفسها، وتاريخُ آخر تغييرٍ واتجاهُه — ثم **ما يستحق قراراً اليوم**.
 */
class Pricing
{
    public const TTL = 600;

    protected static function live(string $t)
    {
        return DB::table($t)->when(Schema::hasColumn($t, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'));
    }

    /** الكتالوج: خدمةٌ ← باقاتها ← منافسوها، مع الشعار والهامش */
    public static function catalog(): array
    {
        if (! Schema::hasTable('pricing_plans')) return [];

        $plans = self::live('pricing_plans')->get();
        if ($plans->isEmpty()) return [];

        $services = Schema::hasTable('services')
            ? self::live('services')->get(['id', 'name', 'kind', 'price', 'cost', 'cycle', 'project_id', 'status'])->keyBy('id')
            : collect();

        // شعار المنتج: تطبيقُ المشروع نفسه — فالباقة تُعرض بوجهٍ يعرفه العميل
        $logos = [];
        if (Schema::hasTable('applications')) {
            foreach (self::live('applications')->whereNotNull('logo_id')
                ->get(['project_id', 'logo_id', 'name']) as $a) {
                if ($a->project_id && ! isset($logos[$a->project_id])) {
                    $logos[$a->project_id] = ['logo' => $a->logo_id, 'app' => $a->name];
                }
            }
        }

        $rivals = Schema::hasTable('competitors')
            ? self::live('competitors')->whereNotNull('service_id')
                ->get(['id', 'name', 'service_id', 'price_from', 'price_to', 'currency', 'billing',
                       'free_tier', 'trial_days', 'our_edge', 'their_edge', 'positioning'])
                ->groupBy('service_id')
            : collect();

        $out = [];
        foreach ($plans->groupBy('service_id') as $sid => $group) {
            $svc = $services[$sid] ?? null;
            $pid = $svc->project_id ?? null;

            $rows = $group->map(fn ($p) => self::plan($p))
                ->sortBy(fn ($p) => $p['free'] ? -1 : ($p['price'] ?? INF))->values()->all();

            $comp = collect($rivals[$sid] ?? [])->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name,
                'from' => $c->price_from !== null ? (float) $c->price_from : null,
                'to' => $c->price_to !== null ? (float) $c->price_to : null,
                'currency' => $c->currency, 'billing' => $c->billing,
                'free' => (bool) $c->free_tier, 'trial' => $c->trial_days,
                'ourEdge' => $c->our_edge, 'theirEdge' => $c->their_edge, 'pos' => $c->positioning,
            ])->values()->all();

            $paid = collect($rows)->filter(fn ($r) => ! $r['free'] && $r['price'] !== null);

            $out[] = [
                'serviceId' => $sid,
                'service'   => $svc->name ?? 'باقاتٌ بلا خدمة',
                'kind'      => $svc->kind ?? null,
                'status'    => $svc->status ?? null,
                'opex'      => $svc->cost !== null ? (float) $svc->cost : null,
                'projectId' => $pid,
                'logo'      => $logos[$pid]['logo'] ?? null,
                'appName'   => $logos[$pid]['app'] ?? null,
                'plans'     => $rows,
                'rivals'    => $comp,
                'entry'     => $paid->min('price'),
                'top'       => $paid->max('price'),
                'market'    => self::marketBand($comp),
            ];
        }

        usort($out, fn ($a, $b) => count($b['plans']) <=> count($a['plans']));

        return $out;
    }

    /** باقةٌ واحدة بهامشها وتاريخ سعرها */
    protected static function plan($p): array
    {
        $price = $p->price !== null ? (float) $p->price : null;
        $cost  = $p->unit_cost !== null ? (float) $p->unit_cost : null;
        $free  = (bool) ($p->free_tier ?? false);

        $margin = ($price !== null && $cost !== null && $price > 0)
            ? (int) round(($price - $cost) / $price * 100) : null;

        $prev = $p->prev_price !== null ? (float) $p->prev_price : null;
        $changed = $p->price_changed_at ?: null;
        $dir = ($prev !== null && $price !== null)
            ? ($price > $prev ? 'up' : ($price < $prev ? 'down' : 'same')) : null;

        return [
            'id' => $p->id, 'name' => $p->name, 'price' => $price, 'currency' => $p->currency,
            'cycle' => $p->cycle, 'market' => $p->market, 'free' => $free,
            'limits' => self::lines($p->limits), 'features' => self::lines($p->features),
            'addons' => $p->addons, 'discounts' => $p->discounts,
            'cost' => $cost, 'margin' => $margin,
            'belowCost' => $price !== null && $cost !== null && ! $free && $price < $cost,
            'prev' => $prev, 'dir' => $dir, 'changedAt' => $changed,
            'staleDays' => $changed ? (int) \Illuminate\Support\Carbon::parse($changed)->diffInDays(now()) : null,
            'from' => $p->effective_from, 'status' => $p->status,
        ];
    }

    /** الحدود والمزايا نصٌّ حرّ — تُقسَّم أسطراً لتُعرض قائمةً لا فقرةً */
    protected static function lines(?string $v): array
    {
        $v = trim((string) $v);
        if ($v === '') return [];

        return collect(preg_split('/[\r\n·;،]+/u', $v))
            ->map(fn ($x) => trim((string) $x, " \t-•*"))->filter()->take(12)->values()->all();
    }

    /** نطاق السوق من أسعار المنافسين — أدنى وأعلى ووسيط */
    protected static function marketBand(array $rivals): array
    {
        $vals = collect($rivals)->flatMap(fn ($r) => array_filter([$r['from'], $r['to']], fn ($x) => $x !== null))->sort()->values();
        if ($vals->isEmpty()) return ['min' => null, 'max' => null, 'mid' => null, 'n' => 0];

        return ['min' => (float) $vals->first(), 'max' => (float) $vals->last(),
                'mid' => (float) $vals->median(), 'n' => count($rivals)];
    }

    /**
     * ما يستحق قراراً اليوم — لا سرداً بل عملاً.
     *
     * كلٌّ منها سؤالٌ لا يجيبه جدولٌ مسطّح: أي باقةٍ تُباع تحت تكلفتها، وأي
     * سعرٍ لم يُمسّ منذ سنتين بينما تتحرّك التكاليف، وأين نقف من السوق.
     */
    public static function insights(?array $catalog = null): array
    {
        $cat = $catalog ?? self::catalog();
        $out = [];

        foreach ($cat as $s) {
            foreach ($s['plans'] as $p) {
                if ($p['belowCost']) {
                    $out[] = ['tone' => 'bad', 'icon' => '🩸', 'title' => 'تُباع تحت تكلفتها',
                        'what' => "{$s['service']} — {$p['name']}",
                        'why' => 'السعر ' . self::money($p['price'], $p['currency'])
                               . ' والتكلفة ' . self::money($p['cost'], $p['currency'])
                               . ' — كل اشتراكٍ جديدٍ يزيد الخسارة لا الإيراد.',
                        'id' => $p['id']];
                } elseif ($p['margin'] !== null && $p['margin'] < 20 && ! $p['free']) {
                    $out[] = ['tone' => 'wn', 'icon' => '📉', 'title' => 'هامشٌ رقيق',
                        'what' => "{$s['service']} — {$p['name']}",
                        'why' => "الهامش {$p['margin']}٪ فقط: أي ارتفاعٍ في التكلفة يقلبها خسارة.",
                        'id' => $p['id']];
                }

                if ($p['staleDays'] !== null && $p['staleDays'] > 730 && ! $p['free']) {
                    $out[] = ['tone' => 'wn', 'icon' => '🕰️', 'title' => 'سعرٌ لم يُمسّ منذ سنتين',
                        'what' => "{$s['service']} — {$p['name']}",
                        'why' => 'آخر تغييرٍ قبل ' . (int) round($p['staleDays'] / 365) . ' سنوات — والتكاليف لا تقف.',
                        'id' => $p['id']];
                }

                if ($p['cost'] === null && ! $p['free']) {
                    $out[] = ['tone' => 'wn', 'icon' => '❔', 'title' => 'باقةٌ بلا تكلفة وحدة',
                        'what' => "{$s['service']} — {$p['name']}",
                        'why' => 'بلا تكلفةٍ لا هامش، وبلا هامشٍ فالسعر تخمين.',
                        'id' => $p['id']];
                }
            }

            $band = $s['market'];
            if ($band['n'] && $s['entry'] !== null) {
                if ($band['min'] !== null && $s['entry'] < $band['min']) {
                    $out[] = ['tone' => 'wn', 'icon' => '🏷️', 'title' => 'أرخص من السوق كلّه',
                        'what' => $s['service'],
                        'why' => 'باقتنا الأدنى ' . self::money($s['entry'], null)
                               . ' وأدنى منافسٍ ' . self::money($band['min'], null)
                               . ' — إمّا ميزةٌ مقصودة أو مالٌ متروك على الطاولة.',
                        'id' => $s['serviceId']];
                }
                if ($band['max'] !== null && $s['entry'] > $band['max']) {
                    $out[] = ['tone' => 'wn', 'icon' => '🏔️', 'title' => 'أغلى من السوق كلّه',
                        'what' => $s['service'],
                        'why' => 'حتى باقتنا الأدنى فوق أعلى منافس — يلزمها مبرّرٌ ظاهرٌ للعميل.',
                        'id' => $s['serviceId']];
                }
            }

            $anyFree = collect($s['plans'])->contains(fn ($p) => $p['free']);
            $rivalsFree = collect($s['rivals'])->filter(fn ($r) => $r['free'] || $r['trial'])->count();
            if (! $anyFree && $rivalsFree && $rivalsFree === count($s['rivals'])) {
                $out[] = ['tone' => 'wn', 'icon' => '🎁', 'title' => 'كل المنافسين يجرّبون ونحن لا',
                    'what' => $s['service'],
                    'why' => "{$rivalsFree} منافسين لديهم باقةٌ مجانية أو تجربة — والعميل يقارن قبل أن يدفع.",
                    'id' => $s['serviceId']];
            }

            if (! count($s['plans'])) {
                $out[] = ['tone' => 'wn', 'icon' => '📭', 'title' => 'خدمةٌ بلا باقات',
                    'what' => $s['service'], 'why' => 'تُباع بسعرٍ واحدٍ أو بالتفاوض — والتفاوض يُضيّع الهامش.',
                    'id' => $s['serviceId']];
            }
        }

        // خدماتٌ نشطة لا باقة لها إطلاقاً — لا تظهر في الكتالوج أصلاً
        if (Schema::hasTable('services') && Schema::hasTable('pricing_plans')) {
            $withPlans = self::live('pricing_plans')->whereNotNull('service_id')->pluck('service_id')->unique()->all();
            $naked = self::live('services')->whereNotIn('id', $withPlans ?: ['-'])
                ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['موقوفة', 'ملغاة', 'مؤرشفة']))
                ->limit(12)->get(['id', 'name']);
            foreach ($naked as $s) {
                $out[] = ['tone' => 'wn', 'icon' => '📭', 'title' => 'خدمةٌ نشطة بلا باقة',
                    'what' => $s->name, 'why' => 'لا سعرَ معلن، فكل بيعةٍ تفاوضٌ من الصفر.', 'id' => $s->id];
            }
        }

        usort($out, fn ($a, $b) => ['bad' => 0, 'wn' => 1][$a['tone']] <=> ['bad' => 0, 'wn' => 1][$b['tone']]);

        return $out;
    }

    protected static function money(?float $v, ?string $cur): string
    {
        if ($v === null) return '—';

        return number_format($v, $v == (int) $v ? 0 : 2) . ' ' . ($cur ?: (string) setting('app.currency', 'د.ك'));
    }

    public static function all(bool $fresh = false): array
    {
        if ($fresh) Cache::forget('pricing:all');

        return Cache::remember('pricing:all', self::TTL, function () {
            $cat = self::catalog();

            return ['catalog' => $cat, 'insights' => self::insights($cat), 'at' => now()->toDateTimeString()];
        });
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مركز الإعلام والفعاليات — الظهورُ أثرٌ لا أرشيف.
 *
 * الوحدتان تسجّلان كل ما يلزم: وصولُ كل ظهورٍ وأثرُه ولغتُه والمتحدث باسمنا،
 * وميزانيةُ كل حدثٍ وتكلفتُه الفعلية وعملاؤه المحتملون وعائده — ثم تُعرضان
 * صفوفاً. فلا يُرى **نموّ الوصول** شهراً بعد شهر، ولا أن ظهوراً سلبياً لم
 * يُردّ عليه، ولا أن معرضاً تجاوز ميزانيته ولم تُسجَّل نتائجه، ولا أن صوتاً
 * واحداً يتحدث باسم المنشأة كلها.
 *
 * وكل ما هنا مقروءٌ من تلك الحقول — لا تكامل ولا تخمين.
 */
class MediaCenter
{
    public const TTL = 600;

    protected static function live(string $t)
    {
        return DB::table($t)->when(Schema::hasColumn($t, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'));
    }

    /** خطّ الزمن: وصولٌ وعددُ ظهوراتٍ لكل شهر — النموّ يُرى لا يُخمَّن */
    public static function timeline(int $months = 12): array
    {
        if (! Schema::hasTable('media_items')) return [];

        $from = now()->startOfMonth()->subMonths($months - 1);
        $rows = self::live('media_items')->whereNotNull('date')
            ->where('date', '>=', $from->toDateString())
            ->get(['date', 'reach', 'impact']);

        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $from->copy()->addMonths($i);
            $out[$m->format('Y-m')] = ['label' => $m->format('Y-m'), 'n' => 0, 'reach' => 0, 'neg' => 0];
        }
        foreach ($rows as $r) {
            $k = substr((string) $r->date, 0, 7);
            if (! isset($out[$k])) continue;
            $out[$k]['n']++;
            $out[$k]['reach'] += (int) ($r->reach ?? 0);
            if (self::isNegative($r->impact)) $out[$k]['neg']++;
        }

        $max = max(1, max(array_column($out, 'reach')));
        foreach ($out as &$m) $m['pct'] = (int) round($m['reach'] / $max * 100);
        unset($m);

        return array_values($out);
    }

    protected static function isNegative(?string $impact): bool
    {
        return in_array(trim((string) $impact), ['سلبي', 'سلبية', 'negative'], true);
    }

    /** كتالوج الظهورات — بطاقاتٌ بصورتها ووصولها وأثرها */
    public static function catalogue(int $limit = 60): array
    {
        if (! Schema::hasTable('media_items')) return [];

        $rows = self::live('media_items')->orderByDesc('date')->limit($limit)
            ->get(['id', 'title', 'type', 'outlet', 'date', 'url', 'brand_id', 'speaker_id',
                   'lang', 'reach', 'impact', 'att_id', 'summary', 'status']);

        $brands = hub_ref_labels('brands', $rows->pluck('brand_id')->all());
        $people = DB::table('users')->whereNull('deleted_at')->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'id' => $r->id, 'title' => $r->title, 'type' => $r->type, 'outlet' => $r->outlet,
            'date' => $r->date, 'url' => $r->url, 'lang' => $r->lang,
            'brand' => $brands[$r->brand_id] ?? null,
            'speaker' => $people[$r->speaker_id] ?? null,
            'reach' => $r->reach !== null ? (int) $r->reach : null,
            'impact' => $r->impact, 'negative' => self::isNegative($r->impact),
            'photo' => $r->att_id, 'summary' => $r->summary, 'status' => $r->status,
        ])->all();
    }

    /** المنابر التي تنشر عنّا، وأصواتُنا التي تتحدث */
    public static function outletsAndVoices(): array
    {
        if (! Schema::hasTable('media_items')) return ['outlets' => [], 'voices' => []];

        $rows = self::live('media_items')->get(['outlet', 'speaker_id', 'reach', 'impact']);
        $people = DB::table('users')->whereNull('deleted_at')->pluck('name', 'id');

        $outlets = $rows->filter(fn ($r) => trim((string) $r->outlet) !== '')
            ->groupBy(fn ($r) => trim((string) $r->outlet))
            ->map(fn ($g, $k) => ['name' => $k, 'n' => $g->count(),
                                  'reach' => (int) $g->sum(fn ($x) => (int) ($x->reach ?? 0)),
                                  'neg' => $g->filter(fn ($x) => self::isNegative($x->impact))->count()])
            ->sortByDesc('reach')->values()->take(12)->all();

        $voices = $rows->filter(fn ($r) => (string) $r->speaker_id !== '')
            ->groupBy('speaker_id')
            ->map(fn ($g, $k) => ['name' => $people[$k] ?? '—', 'n' => $g->count(),
                                  'reach' => (int) $g->sum(fn ($x) => (int) ($x->reach ?? 0))])
            ->sortByDesc('n')->values()->take(10)->all();

        return ['outlets' => $outlets, 'voices' => $voices];
    }

    /** الفعاليات: ما أنفقناه وما عاد — وكم كلّف العميل المحتمل الواحد */
    public static function events(): array
    {
        if (! Schema::hasTable('events')) return [];

        $rows = self::live('events')->orderByDesc('date_start')->limit(40)
            ->get(['id', 'title', 'type', 'venue', 'date_start', 'date_end', 'brand_id', 'owner_id',
                   'budget', 'actual_cost', 'currency', 'goals', 'results', 'leads', 'roi', 'att_id', 'status']);

        $brands = hub_ref_labels('brands', $rows->pluck('brand_id')->all());
        $people = DB::table('users')->whereNull('deleted_at')->pluck('name', 'id');

        return $rows->map(function ($e) use ($brands, $people) {
            $budget = $e->budget !== null ? (float) $e->budget : null;
            $spent  = $e->actual_cost !== null ? (float) $e->actual_cost : null;
            $leads  = $e->leads !== null ? (int) $e->leads : null;
            $ended  = $e->date_end && Carbon::parse($e->date_end)->isPast();

            return [
                'id' => $e->id, 'title' => $e->title, 'type' => $e->type, 'venue' => $e->venue,
                'start' => $e->date_start, 'end' => $e->date_end, 'ended' => $ended,
                'brand' => $brands[$e->brand_id] ?? null, 'owner' => $people[$e->owner_id] ?? null,
                'budget' => $budget, 'spent' => $spent, 'currency' => $e->currency,
                'over' => $budget !== null && $spent !== null && $spent > $budget,
                'overPct' => ($budget && $spent) ? (int) round(($spent - $budget) / $budget * 100) : null,
                'leads' => $leads, 'roi' => $e->roi !== null ? (float) $e->roi : null,
                'perLead' => ($leads && $spent) ? round($spent / $leads, 2) : null,
                'goals' => $e->goals, 'results' => $e->results, 'photo' => $e->att_id, 'status' => $e->status,
            ];
        })->all();
    }

    /** النبض: أرقامٌ تُقرأ في ثانية */
    public static function pulse(array $tl, array $ev): array
    {
        $year = collect($tl)->sum('reach');
        $items = collect($tl)->sum('n');
        $neg = collect($tl)->sum('neg');
        $spend = collect($ev)->sum(fn ($e) => (float) ($e['spent'] ?? 0));
        $leads = collect($ev)->sum(fn ($e) => (int) ($e['leads'] ?? 0));

        // النموّ: نصف السنة الأخير مقابل الذي قبله
        $half = (int) floor(count($tl) / 2);
        $recent = collect($tl)->slice($half)->sum('reach');
        $before = collect($tl)->slice(0, $half)->sum('reach');
        $growth = $before > 0 ? (int) round(($recent - $before) / $before * 100) : null;

        return ['reach' => $year, 'items' => $items, 'neg' => $neg, 'growth' => $growth,
                'events' => count($ev), 'spend' => $spend, 'leads' => $leads,
                'perLead' => $leads ? round($spend / $leads, 2) : null];
    }

    /** ما يستحق التفاتاً */
    public static function insights(array $tl, array $cat, array $ev, array $ov): array
    {
        $out = [];

        foreach ($cat as $m) {
            if ($m['negative']) {
                $out[] = ['tone' => 'bad', 'icon' => '📉', 'title' => 'ظهورٌ سلبي',
                    'what' => $m['title'], 'why' => 'من ' . ($m['outlet'] ?: 'منبرٍ غير مسمّى')
                        . ' بتاريخ ' . substr((string) $m['date'], 0, 10) . ' — الردّ المتأخّر لا يُقرأ.'];
            }
        }

        $silent = collect($tl)->slice(-3)->filter(fn ($m) => $m['n'] === 0)->count();
        if ($silent >= 2) {
            $out[] = ['tone' => 'wn', 'icon' => '🔇', 'title' => 'صمتٌ إعلامي',
                'what' => "{$silent} من آخر ثلاثة أشهر بلا ظهورٍ واحد",
                'why' => 'الحضور الإعلامي يتآكل بالانقطاع لا بالخطأ.'];
        }

        $noReach = collect($cat)->filter(fn ($m) => $m['reach'] === null)->count();
        if ($noReach) {
            $out[] = ['tone' => 'wn', 'icon' => '❔', 'title' => 'ظهوراتٌ بلا وصولٍ مسجَّل',
                'what' => "{$noReach} مادة", 'why' => 'بلا رقمٍ للوصول لا يُقاس أثرٌ ولا نموّ — والتقدير خيرٌ من الفراغ.'];
        }

        $voices = collect($ov['voices'] ?? []);
        if ($voices->count() === 1 && $voices->first()['n'] >= 3) {
            $out[] = ['tone' => 'wn', 'icon' => '🎙️', 'title' => 'صوتٌ واحد يتحدث باسمنا',
                'what' => $voices->first()['name'],
                'why' => 'كل الظهورات بمتحدثٍ واحد — خطرُ اعتمادٍ على شخص، وفرصةٌ ضائعة لبقية القيادات.'];
        }

        foreach ($ev as $e) {
            if ($e['over']) {
                $out[] = ['tone' => 'bad', 'icon' => '💸', 'title' => 'حدثٌ تجاوز ميزانيته',
                    'what' => $e['title'], 'why' => "أُنفق أكثر من المعتمد بـ{$e['overPct']}٪ — والتجاوز يتكرّر إن لم يُراجَع."];
            }
            if ($e['ended'] && blank($e['results'])) {
                $out[] = ['tone' => 'wn', 'icon' => '📝', 'title' => 'حدثٌ انتهى بلا نتائج مسجَّلة',
                    'what' => $e['title'], 'why' => 'بلا نتيجةٍ مكتوبة لا يُعرف إن كان يستحق التكرار — والذاكرة تخون بعد شهر.'];
            }
            if ($e['ended'] && ($e['leads'] === null || $e['leads'] === 0) && $e['spent']) {
                $out[] = ['tone' => 'wn', 'icon' => '🎯', 'title' => 'إنفاقٌ بلا عميلٍ محتمل',
                    'what' => $e['title'], 'why' => 'أُنفق ولم يُسجَّل عميلٌ محتمل واحد — إمّا لم يُقَس أو لم يُجدِ.'];
            }
        }

        usort($out, fn ($a, $b) => ['bad' => 0, 'wn' => 1][$a['tone']] <=> ['bad' => 0, 'wn' => 1][$b['tone']]);

        return $out;
    }

    public static function all(bool $fresh = false): array
    {
        if ($fresh) Cache::forget('media:all');

        return Cache::remember('media:all', self::TTL, function () {
            $tl = self::timeline();
            $cat = self::catalogue();
            $ev = self::events();
            $ov = self::outletsAndVoices();

            return ['timeline' => $tl, 'catalogue' => $cat, 'events' => $ev,
                    'outlets' => $ov['outlets'], 'voices' => $ov['voices'],
                    'pulse' => self::pulse($tl, $ev),
                    'insights' => self::insights($tl, $cat, $ev, $ov),
                    'at' => now()->toDateTimeString()];
        });
    }
}

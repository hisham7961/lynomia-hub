<?php

namespace App\Support\Assets;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **حقائقُ صنفِ العهدة — حسابٌ حتميٌّ بلا ذكاء** (قسمُ «تحليل الذكاء الاصطناعي» في صفحة الصنف).
 *
 * الأرقامُ أوّلاً ثم الرأي: كم عهدةً في الصنف وبأيِّ حالة، ومن يحملها وأين، وكم عمرُها، وأيُّ ضمانٍ
 * انتهى أو يوشك، وما في الإصلاح، وآخرُ الحركات، وأيُّ مواصفاتٍ لم تُسجَّل. كلُّها تُحسب هنا بلا نموذج
 * — **والنموذجُ لا يرى إلا ما حُسب هنا** (`CustodyInsights`)، فلا يخترع رقماً.
 *
 * **والحسابُ بعين قارئٍ بعينه** (`$viewer`):
 *  · الاستعلامُ يأتي مُنطَّقاً من المُنادي (`Custody::scoped()` للمشاهد، أو `hub_scope` بهويّة الخدمة).
 *  · وكلُّ حقلٍ محجوبٍ (`hub_field_mode` = hide) عن القارئ **يُسقط الحقيقةَ المبنيّةَ عليه كلَّها**:
 *    من حُجب عنه الحائزُ لا يرى التوزيعَ على الحائزين ولا الحركات (تكشف الحائز)، ومن حُجب عنه
 *    الضمانُ لا يرى ما ينتهي منه. لا عدَّ يكشف حقلاً لا يُعرض صفُّه.
 *  · وأسماءُ المشاريع لمن يرى المشاريع وحدَه.
 *
 * `$anon` (للنموذج): الحائزون والمحطّاتُ والمشاريعُ **تسمياتٌ مجهّلة** (حائز ١ · محطة ١) — التركّزُ
 * يُقرأ من العدد لا من الاسم، فلا يغادر اسمُ موظّفٍ الخادم.
 */
final class CustodyFacts
{
    /** حالاتٌ خرج صاحبُها من الخدمة نهائيّاً — لا تُحسب في مخاطر الضمان والعمر والتوزيع */
    public const FINAL = ['مستبعد', 'مباع', 'مُعاد للمورد'];

    /** «في الإصلاح أو معطوب» */
    public const REPAIR = ['صيانة', 'تالف'];

    /** نافذةُ «ضمانٌ يوشك أن ينتهي» بالأيام */
    public const WARRANTY_SOON_DAYS = 90;

    /** أطولُ قائمةٍ تفصيليّة (ضمانٌ قريب · في الإصلاح · حركات) */
    public const LIST_MAX = 8;

    /** أكثرُ الحائزين/المحطّات/المشاريع عدداً */
    public const TOP = 5;

    /** أقصى صفوفٍ تُقرأ للحساب — صنفٌ بعشرة آلاف عهدةٍ يُحسب على أوّلها بالكود ويُقال ذلك */
    public const ROW_CAP = 5000;

    /**
     * **ترشيحُ الصنف بكوده** — القاعدةُ نفسُها في صفحة الصنف وفي التحليل: كودٌ مسجَّل ← أنواعُه،
     * وكودُ الاحتياط يجمع «أخرى» وما لا صنفَ له وما كُتب صنفاً خارج السجل. `false` = كودٌ مجهول.
     */
    public static function filterType(Builder $q, string $code): bool
    {
        $known = array_keys(Custody::cats());
        $types = array_values(array_filter($known, fn ($t) => Custody::catCode($t) === $code));
        $isFallback = $code === (string) config('hub_assets.fallback', 'GN');
        if (! $types && ! $isFallback) return false;

        if ($isFallback) {
            $q->where(fn ($w) => $w->whereIn('type', $types)->orWhereNull('type')
                ->orWhere('type', '')->orWhereNotIn('type', $known));
        } else {
            $q->whereIn('type', $types);
        }

        return true;
    }

    /** أنواعُ الصنف المسجَّلة بكوده (أوّلُها اسمُ العرض) */
    public static function typesOf(string $code): array
    {
        return array_values(array_filter(array_keys(Custody::cats()), fn ($t) => Custody::catCode($t) === $code));
    }

    /**
     * @param  Builder  $q  استعلامُ الأصول **مُنطَّقاً ومُرشَّحاً بالصنف** من المُنادي
     * @param  mixed  $viewer  القارئُ الذي تُطبَّق عليه أنماطُ الحقول (مستخدمٌ أو هويّةُ الخدمة)
     */
    public static function compute(Builder $q, mixed $viewer, string $code, bool $anon = false): array
    {
        $see = fn (string $f) => hub_field_mode($viewer, 'assets', $f) !== 'hide';
        $can = [
            'holder'   => $see('holderId'),
            'station'  => $see('stationId'),
            'buy'      => $see('buyDate'),
            'warranty' => $see('warranty'),
            'status'   => $see('status'),
            'projects' => ! $anon && hub_can($viewer, 'projects', 'v'),
        ];

        $cols = ['id', 'code', 'name', 'type', 'status', 'holder_id', 'station_id', 'project_id', 'buy_date', 'warranty', 'specs'];
        $total = (clone $q)->count();
        $rows = (clone $q)->orderBy('code')->orderBy('id')->limit(self::ROW_CAP)->toBase()->get($cols);

        $today = now()->startOfDay();
        $soon = $today->copy()->addDays(self::WARRANTY_SOON_DAYS);
        $active = $rows->filter(fn ($r) => ! in_array((string) $r->status, self::FINAL, true))->values();

        $out = [
            'code'      => $code,
            'total'     => $total,
            'truncated' => $total > $rows->count(),
            'active'    => $active->count(),
            'can'       => $can,
        ];

        // ── الحالات ──
        if ($can['status']) {
            $st = [];
            foreach ($rows as $r) {
                $k = trim((string) $r->status) !== '' ? (string) $r->status : 'بلا حالة';
                $st[$k] = ($st[$k] ?? 0) + 1;
            }
            $out['status'] = self::sortCounts($st);
            $repair = $rows->filter(fn ($r) => in_array((string) $r->status, self::REPAIR, true))->values();
            $out['repair'] = ['n' => $repair->count(), 'items' => self::itemList($repair, fn ($r) => (string) $r->status)];
        }

        // ── الحائزون ──
        if ($can['holder']) {
            $unheld = $active->filter(fn ($r) => $r->holder_id === null || $r->holder_id === '');
            $by = self::countBy($active, 'holder_id');
            $out['holders'] = [
                'held'     => $active->count() - $unheld->count(),
                'unheld'   => $unheld->count(),
                'distinct' => count($by),
                'top'      => self::labelTop($by, 'users', $anon ? 'حائز' : null),
            ];
        }

        // ── المحطّات ──
        if ($can['station']) {
            $by = self::countBy($active, 'station_id');
            $out['stations'] = [
                'none' => $active->filter(fn ($r) => ! $r->station_id)->count(),
                'top'  => self::labelTop($by, 'stations', $anon ? 'محطة' : null),
            ];
        }

        // ── المشاريع (للعرض فقط — أسماءٌ لمن يرى المشاريع) ──
        if ($can['projects']) {
            $by = self::countBy($active, 'project_id');
            $out['projects'] = [
                'none' => $active->filter(fn ($r) => ! $r->project_id)->count(),
                'top'  => self::labelTop($by, 'projects', null),
            ];
        }

        // ── العمر من تاريخ الشراء ──
        if ($can['buy']) {
            $b = ['أقل من سنة' => 0, '١–٣ سنوات' => 0, '٣–٥ سنوات' => 0, 'أكثر من ٥ سنوات' => 0, 'غير معروف' => 0];
            $oldest = null;
            foreach ($active as $r) {
                $d = self::date($r->buy_date);
                if ($d === null) { $b['غير معروف']++; continue; }
                $y = $d->diffInDays($today) / 365.25;
                $b[$y < 1 ? 'أقل من سنة' : ($y < 3 ? '١–٣ سنوات' : ($y < 5 ? '٣–٥ سنوات' : 'أكثر من ٥ سنوات'))]++;
                if ($oldest === null || $d->lt($oldest)) $oldest = $d;
            }
            $out['age'] = ['buckets' => $b, 'oldest' => $oldest?->toDateString()];
        }

        // ── الضمان ──
        if ($can['warranty']) {
            $expired = $expiring = [];
            $unknown = 0;
            foreach ($active as $r) {
                $d = self::date($r->warranty);
                if ($d === null) { $unknown++; continue; }
                if ($d->lt($today)) $expired[] = $r;
                elseif ($d->lte($soon)) $expiring[] = $r;
            }
            usort($expiring, fn ($a, $b) => [(string) $a->warranty, (string) $a->id] <=> [(string) $b->warranty, (string) $b->id]);
            $out['warranty'] = [
                'expired'  => count($expired),
                'expiring' => count($expiring),
                'unknown'  => $unknown,
                'days'     => self::WARRANTY_SOON_DAYS,
                'items'    => self::itemList(collect($expiring), fn ($r) => substr((string) $r->warranty, 0, 10)),
            ];
        }

        // ── المواصفاتُ الناقصة (قالبُ الصنف) ──
        $tpl = Custody::specTemplate(self::typesOf($code)[0] ?? 'أخرى');
        $missing = [];
        $empty = 0;
        foreach ($active as $r) {
            $s = is_array($r->specs) ? $r->specs : (json_decode((string) $r->specs, true) ?: []);
            if ($s === []) $empty++;
            foreach ($tpl as $f) {
                if (trim((string) ($s[$f['key']] ?? '')) === '') $missing[(string) $f['label']] = ($missing[(string) $f['label']] ?? 0) + 1;
            }
        }
        $out['specs'] = ['empty' => $empty, 'fields' => count($tpl), 'missing' => self::sortCounts($missing)];

        // ── آخرُ الحركات (تكشف الحائز — فخلف حقله) ──
        if ($can['holder'] && ! $anon) {
            $out['moves'] = self::moves($rows->pluck('id')->all());
        }

        return $out;
    }

    /** آخرُ حركات الحيازة على هذه الأصول — الأحدثُ أولاً بفاصل تعادلٍ حاسم */
    private static function moves(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('asset_custody')) return [];

        $moves = collect();
        foreach (array_chunk($ids, 900) as $chunk) {
            $moves = $moves->merge(DB::table('asset_custody')->whereIn('asset_id', $chunk)
                ->orderByDesc('at')->orderByDesc('created_at')->orderByDesc('id')
                ->limit(self::LIST_MAX)->get(['id', 'asset_id', 'user_id', 'action', 'at', 'created_at']));
        }
        $moves = $moves->sortBy([['at', 'desc'], ['created_at', 'desc'], ['id', 'desc']])->take(self::LIST_MAX)->values();

        $assets = DB::table('assets')->whereIn('id', $moves->pluck('asset_id')->unique()->all())->pluck('code', 'id');
        $names = hub_ref_labels('users', $moves->pluck('user_id')->filter()->unique()->all());

        return $moves->map(fn ($m) => [
            'at'     => substr((string) $m->at, 0, 10),
            'action' => (string) $m->action,
            'asset'  => (string) ($assets[$m->asset_id] ?? '—'),
            'assetId' => (string) $m->asset_id,
            'who'    => $m->user_id ? (string) ($names[$m->user_id] ?? 'حسابٌ محذوف') : 'المخزن',
        ])->all();
    }

    /** @return array<string,int> */
    private static function countBy($rows, string $col): array
    {
        $by = [];
        foreach ($rows as $r) {
            $k = (string) ($r->{$col} ?? '');
            if ($k === '') continue;
            $by[$k] = ($by[$k] ?? 0) + 1;
        }

        return $by;
    }

    /**
     * الأكثرُ عدداً بتسمياتهم — أو مجهّلين (`حائز ١`) للنموذج. الترتيبُ: العددُ ثم المعرّف (ثابتٌ على المحرّكين).
     *
     * @return list<array{id: ?string, name: string, n: int}>
     */
    private static function labelTop(array $by, string $ref, ?string $anon): array
    {
        $keys = array_keys($by);
        usort($keys, fn ($a, $b) => [$by[$b], (string) $a] <=> [$by[$a], (string) $b]);
        $keys = array_slice($keys, 0, self::TOP);
        $names = $anon === null ? hub_ref_labels($ref, $keys) : [];

        $out = [];
        foreach ($keys as $i => $k) {
            $out[] = $anon !== null
                ? ['id' => null, 'name' => $anon . ' ' . ($i + 1), 'n' => $by[$k]]
                : ['id' => (string) $k, 'name' => (string) ($names[$k] ?? '—'), 'n' => $by[$k]];
        }

        return $out;
    }

    /** @return list<array{id: string, code: string, name: string, note: string}> */
    private static function itemList($rows, \Closure $note): array
    {
        return collect($rows)->take(self::LIST_MAX)->map(fn ($r) => [
            'id' => (string) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'note' => $note($r),
        ])->values()->all();
    }

    /** الأكثرُ أولاً وعند التساوي بالاسم @return array<string,int> */
    private static function sortCounts(array $c): array
    {
        uksort($c, fn ($a, $b) => [$c[$b], (string) $a] <=> [$c[$a], (string) $b]);

        return $c;
    }

    private static function date(mixed $v): ?Carbon
    {
        $s = substr(trim((string) $v), 0, 10);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        try {
            return Carbon::parse($s)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** أنواعُ الأصول الموجودة فعلاً بكودها — لجولة التحليل @return list<string> */
    public static function codesPresent(Builder $q): array
    {
        $codes = [];
        foreach ((clone $q)->toBase()->distinct()->pluck('type') as $t) $codes[Custody::catCode($t === null ? null : (string) $t)] = true;
        $codes = array_keys($codes);
        sort($codes);

        return $codes;
    }
}

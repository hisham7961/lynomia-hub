<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **التصنيفُ الواحد لرموز API** (WP-4.5 · spec §2.9) — لا عتبتان لسؤالٍ واحد.
 *
 * كان خطرُ الرمز يُحسب في موضعين: فحصُ `SecurityPosture::apiStale` بعتبته،
 * وكلُّ شاشةٍ تعيد الحسابَ بيدها. هنا **مصدرٌ واحد**: `classify()` يحكم على
 * الرمز الواحد حكماً واحداً (مُبطَل/منتهٍ/خامل/بلا انتهاء/قديم/كامل الامتياز/
 * سليم)، و`staleParts()` يعطي معرّفاتِ الخطر الحيّ بالاستعلام نفسِه — يستهلكه
 * فحصُ الوضعية (تفويضاً) وذيلُ نتائج الكيانات ومركزُ الرموز جميعاً.
 *
 * العتبةُ من `security.token_unused_days` (مفتاح WP-4.1) — تُقرأ حيّةً لا تُنسخ.
 * **المُبطَل والمنتهي خارجَ الخطر الحيّ**: ميّتان يُعرضان للمساءلة لا للإنذار.
 */
final class ApiTokens
{
    /** «قديم»: مرّت سنةٌ على سكّه (أو آخرِ تدويره — التدويرُ يجدّد created_at) */
    public const OLD_DAYS = 365;

    /**
     * الحالةُ الواحدة ← [التسمية، النبرة]. ترتيبُ المصفوفة هو ترتيبُ الحسم:
     * أولُ شرطٍ يصدق يحكم — فلا رمزَ بحالتين.
     */
    public const STATUSES = [
        'revoked'         => ['مُبطَل', 'g'],
        'expired'         => ['منتهٍ', 'g'],
        'unused'          => ['خامل', 'wn'],
        'never-expires'   => ['بلا انتهاء', 'bad'],
        'old'             => ['قديم', 'wn'],
        'over-privileged' => ['كامل الامتياز لمميَّز', 'bad'],
        'ok'              => ['سليم', 'ok'],
    ];

    /** عتبةُ الخمول بالأيام — مفتاحُ WP-4.1 الواحد (افتراضاً ٩٠) */
    public static function unusedDays(): int
    {
        return max(1, (int) setting('security.token_unused_days', 90));
    }

    /** نطاقٌ كاملُ الامتياز؟ — null أو «*» يعني كلَّ صلاحيات صاحب الرمز */
    public static function fullScope($scopes): bool
    {
        $s = trim((string) $scopes);

        return $s === '' || $s === '*';
    }

    /**
     * الحكمُ الواحد على رمزٍ واحد. يتوقع صفّاً فيه:
     * `revoked_at/expires_at/last_used_at/created_at/scopes` وخانةً اختيارية
     * `privileged` (صاحبُه مالكٌ أو حاملُ رايةٍ خطرة — يحسبها القارئ دفعةً).
     */
    public static function classify(object $t): string
    {
        $ts = fn ($v) => $v ? Carbon::parse($v) : null;

        if (! empty($t->revoked_at ?? null)) return 'revoked';
        $exp = $ts($t->expires_at ?? null);
        if ($exp && $exp->lte(now())) return 'expired';
        $used = $ts($t->last_used_at ?? null);
        if (! $used || $used->lt(now()->subDays(self::unusedDays()))) return 'unused';
        if (! $exp) return 'never-expires';
        $born = $ts($t->created_at ?? null);
        if ($born && $born->lt(now()->subDays(self::OLD_DAYS))) return 'old';
        if (self::fullScope($t->scopes ?? null) && ! empty($t->privileged ?? false)) return 'over-privileged';

        return 'ok';
    }

    /**
     * معرّفاتُ الخطر الحيّ بقسمَيه — **الاستعلامُ الواحد** الذي يفوّضه
     * `SecurityPosture::apiStale`: `idle` لم يُستعمل منذ العتبة، و`noexp` بلا
     * تاريخ انتهاء — كلاهما بين الساري (لا منتهٍ ولا مُبطَل).
     *
     * @return array{idle: array, noexp: array}
     */
    public static function staleParts(): array
    {
        if (! Schema::hasTable('api_tokens')) return ['idle' => [], 'noexp' => []];
        $q = DB::table('api_tokens')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        // المُبطَل ميتٌ لا خامل — يسقط من الخطر الحيّ (الحارسُ للنشر قبل الهجرة)
        if (hub_has_col('api_tokens', 'revoked_at')) $q->whereNull('revoked_at');

        return [
            'idle'  => (clone $q)->where(fn ($w) => $w->whereNull('last_used_at')
                ->orWhere('last_used_at', '<', now()->subDays(self::unusedDays())))
                ->orderBy('id')->pluck('id')->all(),
            'noexp' => (clone $q)->whereNull('expires_at')->orderBy('id')->pluck('id')->all(),
        ];
    }

    /** معرّفاتُ الأدوار المميّزة (مالكٌ أو رايةٌ خطرة) — يقرؤها فحصُ MFA ومركزُ الرموز معاً */
    public static function privilegedRoleIds(): array
    {
        return DB::table('roles')->orderBy('id')->get(['id', 'is_owner', 'flags'])
            ->filter(function ($r) {
                if ($r->is_owner) return true;
                $f = json_decode($r->flags ?? '[]', true) ?: [];

                return (bool) array_intersect(array_keys(array_filter($f)), ['users', 'secrets', 'exp', 'audit']);
            })->pluck('id')->all();
    }

    /**
     * ملخّصُ اللوحة: كم رمزاً كلّياً، وكم سارياً، وكم خطراً حيّاً، وكم مُبطَلاً —
     * عدّاتُ SQL لا تحميلَ صفوف.
     *
     * @return array{total:int, live:int, risky:int, revoked:int}
     */
    public static function summary(?array $parts = null): array
    {
        if (! Schema::hasTable('api_tokens')) return ['total' => 0, 'live' => 0, 'risky' => 0, 'revoked' => 0];
        $hasRev = hub_has_col('api_tokens', 'revoked_at');
        $live = DB::table('api_tokens')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        if ($hasRev) $live->whereNull('revoked_at');
        // أجزاءُ staleParts الممرَّرةُ من نداءٍ حسَبها لتوّه — وإلا فالمصدرُ نفسُه
        $p = $parts ?? self::staleParts();

        return [
            'total'   => (int) DB::table('api_tokens')->count(),
            'live'    => (int) $live->count(),
            'risky'   => count(array_unique(array_merge($p['idle'], $p['noexp']))),
            'revoked' => $hasRev ? (int) DB::table('api_tokens')->whereNotNull('revoked_at')->count() : 0,
        ];
    }
}

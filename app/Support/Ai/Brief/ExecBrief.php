<?php

namespace App\Support\Ai\Brief;

use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\FollowUp\FollowUp;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Kpi\KpiInsights;
use App\Support\Ai\Proposals\ProposalService;
use App\Support\Ai\Reports\ProjectReportDigest;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Insights\ActionCenter;
use App\Support\Insights\KpiCentre;
use App\Support\Platform\SchemaCache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **الموجزُ التنفيذيّ الأسبوعيّ — «خمسةُ أمورٍ تحتاج قرارَك هذا الأسبوع»** (docs/ai-hub/47 §العمود و — المرحلة ٧).
 *
 * لا يُعيد جمعَ ما يُجمَع: يقرأ **إشاراتِ مركز الفعل** (`ActionCenter::signals`) بعين المالك نفسِه — فالنطاقُ
 * موروثٌ لا مخترَق — ومعها حصادَ خطّة الذكاء: اقتراحاتٌ تنتظر، والتزاماتٌ مُصعَّدة، ومؤشّراتٌ خارج الهدف،
 * ومخاطرُ ملفّات الفهم. ونداءٌ واحدٌ يرتّب ويختار خمسةً بسببها و«القرارِ المطلوب»، ورابطُ كلِّ بندٍ من إشارته
 * (رقمُ البند يُحال على الخادم — لا رابطَ من النموذج). صباحَ الأحد إشعارٌ للمالك، والصفحة `/ai/brief`.
 */
final class ExecBrief
{
    public const FEATURE = 'exec_brief';

    public const KIND = 'exec_brief';

    public const MAX_SIGNALS = 60;

    public const SYSTEM = 'أنت مساعدٌ تنفيذيّ لمالك الشركة. تتسلّم "signals" (تنبيهاتُ النظام، كلٌّ برقمه "n" وشدّته وعنوانه وسببه) و"ai" '
        . '(أعدادُ ما ينتظر من اقتراحات الذكاء والالتزامات المصعَّدة، والمؤشّراتُ خارج الهدف، ومخاطرُ المشاريع). '
        . 'اختر **خمسةَ أمورٍ على الأكثر تحتاج قرارَ المالك هذا الأسبوع** — الأعلى أثراً لا الأكثر عدداً، ولا تكرّر أمراً. '
        . 'الشكل: {"items": [{"n": رقمُ الإشارة التي يستند إليها أو null، "title": عنوانٌ قصير، "why": لماذا الآن في جملة، '
        . '"decision": القرارُ المطلوب من المالك في جملة}]}.';

    public static function ready(): bool
    {
        return SchemaCache::hasTable('exec_briefs');
    }

    public static function enabled(): bool
    {
        return self::ready() && (string) setting('ai.exec_brief', '0') === '1';
    }

    public static function week(): string
    {
        return now()->format('o-\WW');
    }

    /** المالكون الفعّالون — مستلمو الموجز */
    public static function owners()
    {
        $roles = Role::query()->where('is_owner', true)->pluck('id')->all();

        return User::query()->whereIn('role_id', $roles)->where('status', 'نشط')->orderBy('id')->get();
    }

    /** @return array{built: int, notified: int, code: ?string} */
    public static function run(bool $dry = false, bool $force = false, ?User $only = null): array
    {
        $out = ['built' => 0, 'notified' => 0, 'code' => null];
        if (! self::ready()) return $out;

        foreach ($only ? [$only] : self::owners() as $u) {
            if (! $u instanceof User) continue;
            $exists = DB::table('exec_briefs')->where('user_id', $u->id)->where('week', self::week())->where('status', 'ok')->exists();
            if ($exists && ! $force) continue;
            if ($dry) { $out['built']++; continue; }

            $r = self::build($u);
            if (! $r['ok']) { $out['code'] = $r['code']; continue; }
            $out['built']++;
            if (! $only && ! $exists) {
                hub_notify((string) $u->id, self::KIND, '🗓️ موجزُ الأسبوع جاهز: ' . $r['count'] . ' أمورٍ تحتاج قرارَك — افتحه من «موجز الأسبوع».');
                $out['notified']++;
            }
        }

        return $out;
    }

    /** @return array{ok: bool, code?: string, count?: int} */
    public static function build(User $u): array
    {
        [$signals, $ai] = self::gather($u);
        $items = [];
        foreach (array_slice($signals, 0, self::MAX_SIGNALS) as $i => $s) {
            $items[] = array_filter(['n' => $i + 1, 'sev' => (string) ($s['sev'] ?? ''), 'title' => FollowUp::clip((string) ($s['title'] ?? ''), 180),
                'why' => FollowUp::clip((string) ($s['why'] ?? ''), 200)], fn ($v) => $v !== '');
        }

        $profile = ProjectReportDigest::profile();
        if ($profile === null) return self::fail($u, AskFailures::MODEL_UNAVAILABLE);
        $auth = GovernedCompletion::authorize(null, $profile, self::FEATURE, 'brief:' . Str::uuid());
        if (! $auth['ok']) return self::fail($u, (string) ($auth['code'] ?: AskFailures::POLICY_DENIED));
        $gov = $auth['gov'];
        $gov['user'] = $gov['user_id'] = $gov['role_id'] = $gov['company_id'] = null;
        $gc = GovernedCompletion::open($profile, $gov, ['feature' => AiPurposes::DIGEST, 'max_calls' => 1, 'max_output' => 900, 'in_tokens' => 4000]);

        $res = FollowUp::call($gc, self::SYSTEM, ['signals' => $items, 'ai' => $ai]);
        if (! $res['ok']) return self::fail($u, (string) $res['code']);

        $picked = [];
        foreach (array_slice(is_array($res['json']['items'] ?? null) ? array_values($res['json']['items']) : [], 0, 5) as $it) {
            if (! is_array($it) || ! is_scalar($it['title'] ?? null)) continue;
            $sig = is_numeric($it['n'] ?? null) ? ($signals[(int) $it['n'] - 1] ?? null) : null;
            $title = AuditorAi::str($it['title'], 160);
            if ($title === '') continue;
            $picked[] = ['title' => $title, 'why' => AuditorAi::str($it['why'] ?? '', 300), 'decision' => AuditorAi::str($it['decision'] ?? '', 300),
                'sev' => (string) ($sig['sev'] ?? ''), 'url' => self::url($sig)];
        }
        if ($picked === []) return self::fail($u, AskFailures::MALFORMED_MODEL_RESPONSE);

        DB::table('exec_briefs')->updateOrInsert(['user_id' => (string) $u->id, 'week' => self::week()], [
            'id' => DB::table('exec_briefs')->where('user_id', $u->id)->where('week', self::week())->value('id') ?? (string) Str::uuid(),
            'items' => json_encode($picked, JSON_UNESCAPED_UNICODE), 'signals' => count($signals), 'status' => 'ok',
            'error_code' => null, 'generated_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return ['ok' => true, 'count' => count($picked)];
    }

    /**
     * الإشاراتُ بعين المالك — يُنصَّب مستخدماً للحساب ثم يُعاد السياقُ كما كان (المجدولُ بلا مستخدم).
     *
     * @return array{0: list<array>, 1: array}
     */
    private static function gather(User $u): array
    {
        $prev = Auth::user();
        Auth::setUser($u);
        try {
            $s = ActionCenter::signals(true);
            $signals = array_values((array) ($s['visible'] ?? []));
            usort($signals, fn ($a, $b) => (ActionCenter::RANK[$b['sev'] ?? ''] ?? 0) <=> (ActionCenter::RANK[$a['sev'] ?? ''] ?? 0));

            $ai = array_filter([
                'open_proposals' => ProposalService::ready() ? ProposalService::countFor($u) : null,
                'escalated_commitments' => FollowUp::ready() ? DB::table('ai_commitments')->where('status', 'escalated')->count() : null,
                'kpis_off_target' => KpiInsights::ready() ? array_values(array_map(fn ($r) => $r['name'],
                    array_slice(KpiCentre::offTarget(KpiCentre::rows($u, false)), 0, 10))) : null,
                'project_risks' => SchemaCache::hasTable('project_understanding') ? DB::table('project_understanding')
                    ->join('projects', 'projects.id', '=', 'project_understanding.project_id')->whereNull('projects.deleted_at')
                    ->where('project_understanding.status', 'ok')->orderBy('project_understanding.id')->limit(10)
                    ->get(['projects.name', 'project_understanding.sections'])
                    ->map(fn ($r) => ['project' => FollowUp::clip($r->name, 120),
                        'risks' => array_slice((array) (json_decode((string) $r->sections, true)['risks'] ?? []), 0, 3)])->all() : null,
            ], fn ($v) => $v !== null && $v !== [] && $v !== 0);
        } finally {
            $prev ? Auth::setUser($prev) : Auth::forgetUser();
        }

        return [$signals, $ai];
    }

    /** رابطُ البند من إشارته — لا من النموذج */
    private static function url(?array $sig): ?string
    {
        if ($sig === null) return null;
        if (! empty($sig['url'])) return (string) $sig['url'];
        if (! empty($sig['module']) && ! empty($sig['record_id']) && hub_mod((string) $sig['module'])) {
            return route('m.show', [$sig['module'], $sig['record_id']]);
        }

        return null;
    }

    private static function fail(User $u, string $code): array
    {
        DB::table('exec_briefs')->updateOrInsert(['user_id' => (string) $u->id, 'week' => self::week()], [
            'id' => DB::table('exec_briefs')->where('user_id', $u->id)->where('week', self::week())->value('id') ?? (string) Str::uuid(),
            'status' => 'failed', 'error_code' => Str::limit($code, 60, ''), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return ['ok' => false, 'code' => $code];
    }

    public static function latest(User $u): ?object
    {
        if (! self::ready()) return null;

        return DB::table('exec_briefs')->where('user_id', $u->id)->orderByDesc('week')->orderByDesc('id')->first();
    }
}

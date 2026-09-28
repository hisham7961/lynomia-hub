<?php

namespace App\Support\Ai\Understanding;

use App\Models\ReportDigest;
use App\Models\User;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\FollowUp\FollowUp;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Reports\DigestAccess;
use App\Support\Ai\Reports\ProjectReportDigest;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Ai\Sources\ProjectSources;
use App\Support\Platform\SchemaCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **ملفُّ فهم المشروع — «فهمٌ عميق لا تقريرٌ سطحيّ»** (docs/ai-hub/47 §العمود ج — المرحلة ٥).
 *
 * نداءٌ واحدٌ لكلِّ مشروع يجمع ما يعرفه النظامُ عنه: حقولَه (بلا الميزانيّة والتكلفة والإيراد)، وتقدّمَه المسجَّلَ
 * والمحسوب، وملخّصَ تقاريره، ومهامَّه (أعدادٌ ومتأخّرٌ ومفتوح)، ومشكلاتِه ومخاطرَه، وقراراتِه، والتزاماتِه
 * المفتوحة، ونصَّ ملفّاته (بروفايل الشركة…) ونصَّ موقعه — ويُعيد:
 *  - **أقسامَ الفهم:** ما هو ولمن · الوعدُ مقابل الواقع · المرحلةُ والحالة · المخاطر · القرارات · الفجوات.
 *  - **اقتراحاتٍ عمليّة** (٣–٧) تحت الملخّص، كلٌّ بنوعه وأساسه، تتحوّل مهمّةً بنقرة أو تُتجاهَل بسبب.
 *
 * يُعاد البناءُ حين تتغيّر بصمةُ المصادر (ومضى يومٌ على آخره) أو يمضي أسبوع. ونصوصُ الملفّات والموقع
 * **بياناتٌ مسيَّجة** لا تعليمات. وما بُني من وثائق وحدة الملفات لا يُعرض إلا لمن يرى تلك الوحدة.
 */
final class ProjectUnderstanding
{
    public const FEATURE = 'understanding';

    public const AUDIT_REFRESH = 'تحديث ملفّ فهم المشروع';

    public const SUGGESTION_TYPES = ['risk' => 'مخاطرة', 'action' => 'إجراء', 'improvement' => 'تحسين', 'question' => 'سؤالٌ للمدير'];

    public const BASES = ['site' => 'الموقع', 'files' => 'الملفّات', 'tasks' => 'المهام', 'issues' => 'المشكلات',
        'digest' => 'التقارير', 'decisions' => 'القرارات', 'project' => 'بيانات المشروع'];

    /** أقسامُ الفهم — نصٌّ (what · audience · stage) وقوائم (الباقي) */
    public const TEXTS = ['what' => 'ما هو المشروع', 'audience' => 'لمن', 'stage' => 'المرحلة والحالة'];

    public const LISTS = ['promise_vs_reality' => 'الوعدُ مقابل الواقع', 'risks' => 'المخاطر', 'decisions' => 'القرارات والالتزامات',
        'gaps' => 'فجوات'];

    public const PROJECT_FIELDS = ['name', 'type', 'status', 'priority', 'start_date', 'launch_exp', 'launch_act', 'market', 'countries', 'url', 'description', 'notes'];

    public const MAX_OUTPUT = 1800;

    public const SITE_CHARS = 12000;

    public const SYSTEM = 'أنت محلّلُ مشاريع داخليّ. تتسلّم كلَّ ما يعرفه النظامُ عن مشروعٍ واحد: بياناتِه، وتقدّمَه (المسجَّل والمحسوب من الخطّة والمهام)، '
        . 'وملخّصَ تقاريره اليوميّة، ومهامَّه ومشكلاتِه وقراراتِه، ونصَّ ملفّاته (بروفايل، عروض، عقود) ونصَّ موقعه. ابنِ **فهماً عميقاً** لا تلخيصاً سطحيّاً: '
        . 'ما المشروعُ ولمن، وماذا يَعِد به (الموقعُ والملفّات) مقابل ما يحدث فعلاً (المهامُ والتقارير)، وأين يقف، وما المخاطرُ والفجواتُ الحقيقيّة. '
        . 'ثم اقترح من ٣ إلى ٧ اقتراحاتٍ عمليّةٍ محدّدة (لا نصائحَ عامّة). لا تخترع ما ليس في البيانات. '
        . 'الشكل: {"what": جملتان، "audience": جملة، "stage": جملتان، "promise_vs_reality": [..]، "risks": [..]، "decisions": [..]، "gaps": [..] '
        . '(قوائمُ نصوصٍ قصيرة، ستّةٌ على الأكثر)، "suggestions": [{"type": "risk"|"action"|"improvement"|"question"، "text": الاقتراح في جملةٍ أو اثنتين، '
        . '"basis": قائمةٌ من "site"، "files"، "tasks"، "issues"، "digest"، "decisions"، "project"}]}.';

    public static function ready(): bool
    {
        return SchemaCache::hasTable('project_understanding');
    }

    public static function enabled(): bool
    {
        return self::ready() && (string) setting('ai.understanding', '0') === '1';
    }

    public static function maxProjects(): int
    {
        return max(1, min(100, (int) setting('ai.understanding_max_projects', 20)));
    }

    // ── البناء ─────────────────────────────────────────────────────────────

    /** @return array{built: int, skipped: int, failed: int, code: ?string} */
    public static function run(bool $dry = false, ?string $projectId = null, ?User $actor = null, bool $force = false): array
    {
        $out = ['built' => 0, 'skipped' => 0, 'failed' => 0, 'code' => null];
        if (! self::ready()) return $out;

        $rows = DB::table('projects')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('id', $projectId))
            ->when(! $projectId, fn ($q) => $q->whereNotIn('status', ['مكتمل', 'ملغى']))
            ->orderBy('id')->limit(1000)->pluck('id');
        $existing = DB::table('project_understanding')->whereIn('project_id', $rows->all())->get()->keyBy('project_id');
        // الأقدمُ بناءً أوّلاً — ومن لم يُبنَ قطّ قبل الجميع
        $rows = $rows->sortBy(fn ($id) => (string) ($existing[$id]->generated_at ?? ''))->values();

        $gc = null;
        foreach ($rows as $pid) {
            if ($out['built'] + $out['failed'] >= self::maxProjects()) break;
            $facts = self::facts((string) $pid);
            if ($facts === null) { $out['skipped']++; continue; }
            $hash = hash('sha256', (string) json_encode($facts['stable'], JSON_UNESCAPED_UNICODE));
            $prev = $existing[$pid] ?? null;
            if (! $force && $prev && ! self::due($prev, $hash)) { $out['skipped']++; continue; }
            if ($dry) { $out['built']++; continue; }

            if ($gc === null) {
                $opened = self::session($actor);
                if (is_string($opened)) return ['code' => $opened] + $out;
                $gc = $opened;
            }
            $res = FollowUp::call($gc, self::SYSTEM, $facts['payload']);
            $parsed = $res['ok'] ? self::parse($res['json']) : null;
            $now = now();
            $row = ['attempted_at' => $now, 'updated_at' => $now];
            if ($parsed === null) {
                $row += ['status' => 'failed', 'error_code' => Str::limit($res['ok'] ? AskFailures::MALFORMED_MODEL_RESPONSE : (string) $res['code'], 60, '')];
                $out['failed']++;
            } else {
                $row += ['status' => 'ok', 'error_code' => null, 'sections' => json_encode($parsed['sections'], JSON_UNESCAPED_UNICODE),
                    'suggestions' => json_encode($parsed['suggestions'], JSON_UNESCAPED_UNICODE), 'uses_docs' => $facts['uses_docs'],
                    'sources_hash' => $hash, 'generated_at' => $now, 'usage_event_id' => $gc->lastEventId()];
                $out['built']++;
            }
            $prev
                ? DB::table('project_understanding')->where('id', $prev->id)->update($row)
                : DB::table('project_understanding')->insert($row + ['id' => (string) Str::uuid(), 'project_id' => (string) $pid, 'created_at' => $now]);

            if (! $res['ok'] && ! in_array($res['code'], AuditorAi::ITEM_FAILURES, true)) return ['code' => (string) $res['code']] + $out;
        }

        return $out;
    }

    /** أيُعاد البناء؟ تغيّرت المصادرُ ومضى يوم، أو مضى أسبوعٌ على كلِّ حال، أو فشل آخرُه */
    private static function due(object $prev, string $hash): bool
    {
        if ($prev->status !== 'ok' || ! $prev->generated_at) return true;
        $age = now()->diffInHours(\Illuminate\Support\Carbon::parse((string) $prev->generated_at), true);

        return ($prev->sources_hash !== $hash && $age >= 24) || $age >= 24 * 7;
    }

    private static function session(?User $actor): GovernedCompletion|string
    {
        $profile = ProjectReportDigest::profile();
        if ($profile === null) return AskFailures::MODEL_UNAVAILABLE;
        $auth = GovernedCompletion::authorize($actor, $profile, self::FEATURE, 'understanding:' . Str::uuid());
        if (! $auth['ok']) return (string) ($auth['code'] ?: AskFailures::POLICY_DENIED);
        $gov = $auth['gov'];
        if ($actor === null) $gov['user'] = $gov['user_id'] = $gov['role_id'] = $gov['company_id'] = null;

        return GovernedCompletion::open($profile, $gov, [
            'feature' => AiPurposes::DIGEST, 'max_calls' => self::maxProjects(), 'max_output' => self::MAX_OUTPUT, 'in_tokens' => 14000,
        ]);
    }

    /**
     * ما يُرسل للنموذج (`payload`) وما تُحسب منه البصمة (`stable` — بلا الأرقام المتقلّبة كلَّ ساعة).
     *
     * @return array{payload: array, stable: array, uses_docs: bool}|null
     */
    public static function facts(string $projectId): ?array
    {
        $p = DB::table('projects')->where('id', $projectId)->first(self::PROJECT_FIELDS);
        if ($p === null) return null;
        $clip = fn ($v, int $n = 400) => FollowUp::clip(is_scalar($v) ? (string) $v : '', $n);

        $project = [];
        foreach (self::PROJECT_FIELDS as $f) {
            $v = $clip($p->{$f} ?? '', in_array($f, ['description', 'notes'], true) ? 1500 : 200);
            if ($v !== '') $project[$f] = $v;
        }

        $digest = ReportDigest::query()->where('project_id', $projectId)->orderBy('id')->first();
        $sec = (array) ($digest->sections ?? []);
        $digestFacts = array_filter(['overview' => $clip($sec['overview'] ?? '', 700),
            'achievements' => array_slice((array) ($sec['achievements'] ?? []), 0, 6), 'blockers' => array_slice((array) ($sec['blockers'] ?? []), 0, 6),
            'risks' => array_slice((array) ($sec['risks'] ?? []), 0, 6), 'next' => array_slice((array) ($sec['next'] ?? []), 0, 6)]);

        $tasks = DB::table('tasks')->whereNull('deleted_at')->where('project_id', $projectId);
        $byStatus = (clone $tasks)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $open = (clone $tasks)->whereNotIn('status', hub_closed_states());
        $overdue = (clone $open)->whereNotNull('due')->where('due', '<', today()->toDateString())
            ->orderBy('due')->orderBy('id')->limit(15)->pluck('title')->map(fn ($t) => $clip($t, 150))->all();
        $openTitles = (clone $open)->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->pluck('title')->map(fn ($t) => $clip($t, 150))->all();

        $issues = SchemaCache::hasTable('issues') ? DB::table('issues')->whereNull('deleted_at')->where('project_id', $projectId)
            ->whereNotIn('status', hub_closed_states())->orderBy('id')->limit(10)->get(['title', 'kind', 'severity'])
            ->map(fn ($i) => array_filter(['title' => $clip($i->title, 150), 'kind' => $i->kind, 'severity' => $i->severity]))->all() : [];
        $decisions = SchemaCache::hasTable('decisions') ? DB::table('decisions')->whereNull('deleted_at')->where('project_id', $projectId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['title', 'status', 'due'])
            ->map(fn ($d) => array_filter(['title' => $clip($d->title, 150), 'status' => $d->status, 'due' => $d->due ? substr((string) $d->due, 0, 10) : null]))->all() : [];
        $commitments = FollowUp::ready() ? DB::table('ai_commitments')->where('project_id', $projectId)->whereIn('status', ['open', 'escalated'])->count() : 0;

        $src = ProjectSources::ready() ? ProjectSources::summary($projectId) : ['files' => [], 'files_text' => '', 'site' => null];
        $usesDocs = collect($src['files'])->contains(fn ($f) => $f['module'] === 'files' && $f['status'] === 'ok');
        $site = $src['site'] && $src['site']['status'] === 'ok' ? array_filter([
            'url' => $src['site']['url'], 'title' => $src['site']['meta']['title'] ?? null, 'description' => $src['site']['meta']['description'] ?? null,
            'broken_pages' => array_column((array) ($src['site']['meta']['broken'] ?? []), 'url'),
            'text' => FollowUp::clip($src['site']['text'], self::SITE_CHARS),
        ]) : null;

        $progress = ['recorded' => DB::table('projects')->where('id', $projectId)->value('progress')];
        try {
            $progress['computed'] = hub_progress($projectId)['pct'] ?? null;
        } catch (\Throwable) {}

        $payload = array_filter([
            'project' => $project, 'progress' => $progress, 'reports_summary' => $digestFacts,
            'tasks' => ['by_status' => $byStatus, 'overdue' => $overdue, 'open' => $openTitles],
            'issues' => $issues, 'decisions' => $decisions, 'open_commitments' => $commitments,
            'files_text' => FollowUp::clip($src['files_text'], ProjectSources::FILES_CHARS), 'site' => $site,
        ], fn ($v) => $v !== [] && $v !== '' && $v !== null);

        // لا شيءَ يُفهم منه غيرُ اسمه وحالته ⇒ لا نداء
        $meaningful = ($project['description'] ?? '') !== '' || ($project['notes'] ?? '') !== '' || $digestFacts !== []
            || $byStatus !== [] || $issues !== [] || $decisions !== [] || $src['files_text'] !== '' || $site !== null;
        if (! $meaningful) return null;

        $stable = $payload;
        unset($stable['progress'], $stable['tasks']['open']);
        $stable['digest_at'] = (string) ($digest->generated_at ?? '');

        return ['payload' => $payload, 'stable' => $stable, 'uses_docs' => $usesDocs];
    }

    /**
     * الردُّ يُقرأ ولا يُصدَّق: نصوصٌ مقصوصة، قوائمُ محدودة، واقتراحاتٌ بنوعٍ وأساسٍ معروفين — أو `null`.
     *
     * @return array{sections: array, suggestions: list<array>}|null
     */
    public static function parse(array $json): ?array
    {
        $sections = [];
        foreach (array_keys(self::TEXTS) as $k) $sections[$k] = is_scalar($json[$k] ?? null) ? AuditorAi::str($json[$k], 700) : '';
        foreach (array_keys(self::LISTS) as $k) {
            $list = is_array($json[$k] ?? null) ? array_values($json[$k]) : [];
            $sections[$k] = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? AuditorAi::str($v, 300) : '', array_slice($list, 0, 6))));
        }

        $suggestions = [];
        foreach (array_slice(is_array($json['suggestions'] ?? null) ? array_values($json['suggestions']) : [], 0, 7) as $s) {
            if (! is_array($s) || ! isset(self::SUGGESTION_TYPES[$s['type'] ?? '']) || ! is_scalar($s['text'] ?? null)) continue;
            $text = AuditorAi::str($s['text'], 400);
            if ($text === '') continue;
            $basis = array_values(array_intersect(array_map('strval', is_array($s['basis'] ?? null) ? $s['basis'] : []), array_keys(self::BASES)));
            $suggestions[] = ['id' => substr(sha1($text), 0, 12), 'type' => (string) $s['type'], 'text' => $text, 'basis' => $basis];
        }

        if ($sections['what'] === '' && $suggestions === []) return null;

        return ['sections' => $sections, 'suggestions' => $suggestions];
    }

    // ── العرض والقرار ──────────────────────────────────────────────────────

    /** الصفُّ كما يُعرض لهذا القارئ — أو `null` (لا يراه أو لم يُبنَ) */
    public static function forViewer(User $u, string $projectId): ?object
    {
        if (! self::ready() || ! DigestAccess::canUse($u) || DigestAccess::masked($u)) return null;
        if (DigestAccess::project($u, $projectId) === null) return null;
        $row = DB::table('project_understanding')->where('project_id', $projectId)->orderBy('id')->first();
        if ($row === null) return null;
        // بُني من وثائق وحدة الملفات ⇒ لمن يرى تلك الوحدة وحدَه
        if ((bool) $row->uses_docs && ! hub_can($u, 'files', 'v')) return null;

        return $row;
    }

    /** اقتراحاتٌ لم تُتجاهل ولم تُحوَّل */
    public static function openSuggestions(object $row): array
    {
        $done = (array) json_decode((string) $row->dismissed, true) + (array) json_decode((string) $row->converted, true);

        return array_values(array_filter((array) json_decode((string) $row->suggestions, true), fn ($s) => ! isset($done[$s['id'] ?? ''])));
    }

    /** يُسجَّل قرارُ القارئ في اقتراح: `task` (يُحوَّل) أو `dismiss` (بسبب) — ويُعاد الاقتراح */
    public static function decide(User $u, string $projectId, string $sid, string $action, ?string $reason = null): ?array
    {
        $row = self::forViewer($u, $projectId);
        if ($row === null) return null;
        $s = collect((array) json_decode((string) $row->suggestions, true))->firstWhere('id', $sid);
        if (! is_array($s)) return null;

        $col = $action === 'task' ? 'converted' : 'dismissed';
        $map = (array) json_decode((string) $row->{$col}, true);
        $map[$sid] = array_filter(['by' => (string) $u->id, 'at' => now()->toDateTimeString(),
            'reason' => $reason !== null && trim($reason) !== '' ? Str::limit(trim($reason), 200, '…') : null]);
        DB::table('project_understanding')->where('id', $row->id)->update([$col => json_encode($map, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return $s;
    }
}

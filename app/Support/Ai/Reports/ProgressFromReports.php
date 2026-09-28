<?php

namespace App\Support\Ai\Reports;

use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\Proposals\ProposalService;
use Illuminate\Support\Facades\DB;

/**
 * **التقدّمُ من التقارير** (docs/ai-hub/47 §العمود د — المرحلة ٢): «حسب التقرير من الموظف يعدّل تقدّم المشروع».
 *
 * لا نداءَ إضافيّ: يركب **نداءَ ملخّص المشروع نفسَه** (`ProjectReportDigest::ask`) — النموذجُ يقرأ التقاريرَ
 * أصلاً، فيُعيد مع الملخّص:
 *  - `progress`: تقديرُه لنسبة إنجاز المشروع كلِّه، وسببُه، واقتباساتٌ من التقارير تشهد له.
 *  - `task_progress`: تقاريرُ يناقض نصُّها رقمَها («لم أبدأ» بـ٨٠٪) — بتقديرٍ صحيحٍ واقتباس.
 *
 * ولا يكتب شيئاً في المشروع ولا المهمّة: كلُّ تقديرٍ يبتعد عن المسجَّل بالعتبة (`reports.progress_threshold`)
 * يصير **اقتراحاً** في `ProposalService` بدليلٍ يتحقّق الخادمُ من وجوده حرفياً في التقرير — والإنسانُ يعتمده.
 */
final class ProgressFromReports
{
    public const SOURCE = 'report_digest';

    public const MAX_TASKS = 5;

    public const WHY_CLIP = 500;

    public const QUOTE_CLIP = 300;

    /** ملحقُ التعليمات حين يُطلب التقدّم — يُضاف إلى `ProjectReportDigest::SYSTEM` */
    public const PROMPT = 'وأضِف مفتاحين: "progress" كائنٌ {"estimate": رقمٌ من 0 إلى 100 يقدّر نسبةَ إنجاز المشروع كلِّه ممّا في الملخّص والتقارير '
        . 'و«project» (التقدّمُ المسجَّل والمحسوب)، "why": جملتان تشرحان التقدير، "quotes": [{"n": رقمُ التقرير، "quote": نصٌّ منسوخٌ حرفياً من أحد حقول ذلك التقرير}]} '
        . '— أو null إن لم تكفِ البيانات. و"task_progress": قائمةٌ (قد تكون []) بالتقارير التي يناقض نصُّها رقمَ "progress" فيها تناقضاً واضحاً، '
        . 'كلُّ بندٍ {"n": رقمُ التقرير، "estimate": النسبةُ التي يدلّ عليها النصّ، "why": جملة، "quote": نصٌّ منسوخٌ حرفياً من التقرير}. '
        . 'الاقتباسُ نسخٌ حرفيٌّ لا صياغة — اقتباسٌ لا يوجد في التقرير يُسقِط التقدير.';

    public static function enabled(): bool
    {
        return ProposalService::enabled() && (string) setting('reports.progress_proposals', '1') === '1';
    }

    public static function threshold(): float
    {
        return (float) max(1, min(50, (int) setting('reports.progress_threshold', 10)));
    }

    /** سياقُ المشروع للنموذج: المسجَّلُ والمحسوب — أرقامٌ لا أسماء */
    public static function context(string $projectId): array
    {
        $recorded = DB::table('projects')->where('id', $projectId)->value('progress');
        $computed = null;
        try {
            $computed = hub_progress($projectId)['pct'] ?? null;
        } catch (\Throwable) {}

        return ['recorded_progress' => $recorded === null ? null : round((float) $recorded, 1),
            'computed_progress' => $computed === null ? null : round((float) $computed, 1)];
    }

    /**
     * **الردُّ يُقرأ ولا يُصدَّق:** رقمٌ في المدى، ونصوصٌ مقصوصة، واقتباساتٌ بأرقام تقاريرَ صحيحة — وما عداها يُسقَط.
     *
     * @return array{project: ?array{estimate: float, why: string, quotes: list<array{n:int, quote:string}>}, tasks: list<array{n:int, estimate: float, why: string, quote: string}>}
     */
    public static function parse(array $json): array
    {
        $out = ['project' => null, 'tasks' => []];

        $p = $json['progress'] ?? null;
        if (is_array($p) && is_numeric($p['estimate'] ?? null) && (float) $p['estimate'] >= 0 && (float) $p['estimate'] <= 100) {
            $quotes = [];
            foreach (array_slice(is_array($p['quotes'] ?? null) ? array_values($p['quotes']) : [], 0, ProposalService::MAX_EVIDENCE) as $q) {
                if (is_array($q) && is_numeric($q['n'] ?? null) && is_string($q['quote'] ?? null)) {
                    $quotes[] = ['n' => (int) $q['n'], 'quote' => AuditorAi::str($q['quote'], self::QUOTE_CLIP)];
                }
            }
            $out['project'] = ['estimate' => round((float) $p['estimate']), 'quotes' => $quotes,
                'why' => is_scalar($p['why'] ?? null) ? AuditorAi::str($p['why'], self::WHY_CLIP) : ''];
        }

        foreach (array_slice(is_array($json['task_progress'] ?? null) ? array_values($json['task_progress']) : [], 0, self::MAX_TASKS) as $t) {
            if (! is_array($t) || ! is_numeric($t['n'] ?? null) || ! is_numeric($t['estimate'] ?? null) || ! is_string($t['quote'] ?? null)) continue;
            if ((float) $t['estimate'] < 0 || (float) $t['estimate'] > 100) continue;
            $out['tasks'][] = ['n' => (int) $t['n'], 'estimate' => round((float) $t['estimate']),
                'why' => is_scalar($t['why'] ?? null) ? AuditorAi::str($t['why'], self::WHY_CLIP) : '',
                'quote' => AuditorAi::str($t['quote'], self::QUOTE_CLIP)];
        }

        return $out;
    }

    /**
     * **من التقدير إلى الاقتراح.** `$rounds` نتائجُ دفعات الجولة بالترتيب، لكلٍّ `parsed` و`refs` (رقمُ البند ⇒
     * معرّفُ التقرير ومهمّتُه). تقديرُ المشروع من **آخر دفعةٍ** (رأت أحدثَ ما كُتب)، وتناقضاتُ المهامّ من كلِّها.
     *
     * @param  list<array{parsed: ?array, refs: array<int, array{id: string, task: string}>}>  $rounds
     * @return int عددُ الاقتراحات المحفوظة
     */
    public static function propose(string $projectId, array $rounds): int
    {
        if (! self::enabled() || $rounds === []) return 0;
        $made = 0;
        // سجلُّ «لماذا لم يُقترح؟» — ثلاثُ حالاتٍ مشروعةٍ تبدو من الخارج واحدة (بلا تقدير · ضمن العتبة · دليلٌ لم يثبت)
        $why = ['project' => 'no_estimate', 'tasks' => 0, 'tasks_made' => 0];

        $last = $rounds[count($rounds) - 1];
        $project = $last['parsed']['project'] ?? null;
        if (is_array($project)) {
            $recorded = (float) (DB::table('projects')->where('id', $projectId)->value('progress') ?? 0);
            $why += ['estimate' => $project['estimate'], 'recorded' => $recorded];
            if (abs((float) $project['estimate'] - $recorded) < self::threshold()) {
                $why['project'] = 'within_threshold';
            } else {
                $ev = self::verified(self::evidence($project['quotes'], $last['refs']));
                if ($ev === []) {
                    $why['project'] = 'no_verified_quote';
                } else {
                    $r = ProposalService::propose('project_progress', $projectId, (int) $project['estimate'], $project['why'], $ev, null, self::SOURCE);
                    $why['project'] = $r['ok'] ? 'proposed' : 'refused: ' . ($r['why'] ?? '');
                    if ($r['ok']) $made++;
                }
            }
        }

        foreach ($rounds as $r) {
            foreach ((array) ($r['parsed']['tasks'] ?? []) as $t) {
                $why['tasks']++;
                $ref = $r['refs'][$t['n']] ?? null;
                if ($ref === null || $ref['task'] === '') continue;
                $now = (float) (DB::table('tasks')->where('id', $ref['task'])->value('progress') ?? 0);
                if (abs((float) $t['estimate'] - $now) < self::threshold()) continue;
                $ev = [['module' => 'updates', 'record_id' => $ref['id'], 'quote' => $t['quote']]];
                if (ProposalService::propose('task_progress', $ref['task'], (int) $t['estimate'], $t['why'], $ev, null, self::SOURCE)['ok']) {
                    $made++;
                    $why['tasks_made']++;
                }
            }
        }

        \Illuminate\Support\Facades\Log::info('ai.progress', ['project' => $projectId] + $why);

        return $made;
    }

    /**
     * الاقتباساتُ الصادقةُ وحدَها — كلُّ اقتباسٍ يُتحقَّق منه منفرداً، فاقتباسٌ واحدٌ غيرُ حرفيٍّ بين عدّةٍ صادقة
     * لا يُسقط التقديرَ كلَّه؛ وما لم يثبت يُحذف ولا يُعرض دليلاً. (بلا اقتباسٍ صادقٍ واحد ⇒ لا اقتراح.)
     *
     * @param  list<array{module:string, record_id:string, quote:string}>  $ev
     * @return list<array{module:string, record_id:string, quote:string}>
     */
    private static function verified(array $ev): array
    {
        return array_values(array_filter($ev, fn ($e) => ProposalService::verifyEvidence([$e]) !== null));
    }

    /** @return list<array{module:string, record_id:string, quote:string}> */
    private static function evidence(array $quotes, array $refs): array
    {
        $ev = [];
        foreach ($quotes as $q) {
            if (isset($refs[$q['n']])) $ev[] = ['module' => 'updates', 'record_id' => $refs[$q['n']]['id'], 'quote' => $q['quote']];
        }

        return $ev;
    }
}

<?php

namespace App\Support\Ai\Auditor\Detectors;

use App\Models\User;
use App\Support\Ai\Auditor\Detector;
use App\Support\Ai\Auditor\Reports;
use Illuminate\Support\Facades\DB;

/**
 * **ساعاتٌ بلا تقدّم:** تقاريرُ متتالية لموظّفٍ على مهمّةٍ واحدة، بساعاتٍ كثيرة، ونسبةُ
 * الإنجاز التي يكتبها هو نفسُه لا تتحرّك. إمّا عائقٌ لم يُكتَب، أو تقديرٌ فاسد، أو
 * ساعاتٌ لا تذهب حيث يُقال — وكلُّها تستحقّ سؤالاً من المدير لا حكماً من الآلة.
 *
 * والعدُّ بالأيّامِ لا بالبنود — ثلاثةُ بنودٍ في يومٍ واحدٍ ليست «ثباتاً عبر الزمن».
 *
 * **ولا يُحكَم بلا قياس:** تقاريرُ لا تحمل نسبةَ إنجازٍ أصلاً لا تُنتج نتيجة — «لم يُقَس»
 * ليس «لم يتقدّم». والمهمّةُ المغلقةُ أو المكتملةُ خارجَ النظر.
 */
final class HoursWithoutProgress implements Detector
{
    /** أقلُّ عددِ أيّامٍ مختلفةٍ بتقاريرَ على المهمّة */
    public const MIN_DAYS = 3;

    public const MIN_HOURS = 12.0;

    /** أقصى حركةٍ في النسبة تُعَدّ ثباتاً (نقاطٌ مئويّة) */
    public const MAX_DELTA = 5.0;

    public function key(): string
    {
        return 'hours_no_progress';
    }

    public function source(): string
    {
        return 'rule';
    }

    public function label(): string
    {
        return 'ساعاتٌ بلا تقدّم';
    }

    public function complete(): bool
    {
        return true;
    }

    public function detect(User $auditor): array
    {
        $groups = [];
        foreach (Reports::recent($auditor) as $r) {
            if (! $r->task_id) continue;
            $groups[$r->created_by . '|' . $r->task_id][] = $r;
        }

        $days = fn (array $rows) => count(array_unique(array_map(fn ($r) => Reports::day($r), $rows)));
        $candidates = array_filter($groups, fn ($rows) => $days($rows) >= self::MIN_DAYS);
        if ($candidates === []) return [];

        $taskIds = array_values(array_unique(array_map(fn ($rows) => (string) $rows[0]->task_id, $candidates)));
        $tasks = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks', $auditor)
            ->whereIn('id', $taskIds)
            ->get(['id', 'title', 'status', 'progress'])
            ->keyBy('id');
        $closed = hub_closed_states();

        $out = [];
        foreach ($candidates as $rows) {
            $task = $tasks[(string) $rows[0]->task_id] ?? null;
            if ($task === null) continue;                                   // لا يراها المدقّق ⇒ لا حكم
            if (in_array((string) $task->status, $closed, true)) continue;
            if ($task->progress !== null && (float) $task->progress >= 100) continue;

            $hours = array_sum(array_map(fn ($r) => (float) $r->hours, $rows));
            $measured = array_values(array_filter(array_map(fn ($r) => $r->progress, $rows), fn ($p) => $p !== null));
            if ($hours < self::MIN_HOURS || count($measured) < self::MIN_DAYS) continue;

            $measured = array_map('floatval', $measured);
            if (max($measured) - min($measured) > self::MAX_DELTA) continue;

            $last = end($rows);
            $evidence = [['module' => 'tasks', 'id' => (string) $task->id]];
            foreach ($rows as $r) $evidence[] = ['module' => 'updates', 'id' => (string) $r->id];

            $out[] = [
                // **الهويّةُ: هذه المهمّةُ عند هذا الموظّف** — لا أحدثُ تقريرٍ عنها
                'identity' => [(string) $last->created_by, (string) $task->id],
                'severity' => $hours >= 2 * self::MIN_HOURS ? 'high' : 'medium',
                'subject_module' => 'updates',
                'subject_id' => (string) $last->id,
                'subject_user_id' => (string) $last->created_by,
                'company_id' => $last->company_id,
                'evidence' => $evidence,
                'fields' => ['updates' => ['hours', 'progress', 'taskId', 'workDate'],
                             'tasks' => ['title', 'status', 'progress']],
                'summary' => $days($rows) . ' أيّامٍ من التقارير بمجموع ' . rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.')
                    . ' ساعة على المهمّة «' . mb_substr((string) $task->title, 0, 80) . '» منذ '
                    . Reports::day($rows[0]) . ' — والإنجازُ المُبلَّغ ثابتٌ عند '
                    . rtrim(rtrim(number_format(max($measured), 1, '.', ''), '0'), '.') . '٪.',
                'suggestion' => 'اسأل عن العائق أو أعِد تقديرَ المهمّة أو قسِّمها.',
                'draft' => ['note' => 'مضت عدّةُ أيّامٍ على المهمّة «' . mb_substr((string) $task->title, 0, 60)
                    . '» والإنجازُ ثابت — ما الذي يعيقها؟ اذكر العائقَ أو تقديراً جديداً للمدّة لنعالجه معاً.'],
                'input' => [array_map(fn ($r) => [$r->id, $r->hours, $r->progress], $rows), $task->progress, $task->status],
            ];
        }

        return $out;
    }
}

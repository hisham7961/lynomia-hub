<?php

namespace App\Support\Ai\FollowUp;

use App\Models\AiCommitment;
use App\Support\Ai\GovernedCompletion;
use Illuminate\Support\Facades\DB;

/**
 * **الإغلاقُ بالدليل قبل أيِّ سؤال** — لا يُسأل موظّفٌ عمّا أثبت إنجازَه.
 *
 *  ١. **رخيصٌ أوّلاً:** مهمّةٌ مرتبطةٌ صارت في حالةٍ مغلقة ⇒ أُنجز (دليلُه المهمّة).
 *  ٢. **ثم الدلاليّ:** تقاريرُ صاحبه اللاحقة (منذ يوم الالتزام) تُعرض على النموذج مع الالتزامات المستحقّة؛
 *     «done» باقتباسٍ موجودٍ حرفياً في ذلك التقرير ⇒ أُنجز. «partial» ⇒ يُمهَل. وما عداه يبقى لسؤاله.
 */
final class CommitmentResolver
{
    public const PER_USER = 10;

    public const USERS = 20;

    public const LATER_REPORTS = 15;

    public const SYSTEM = 'تتسلّم "commitments" (التزاماتٌ قالها موظّفٌ في تقاريره، كلٌّ برقمه "k") و"reports" (تقاريرُه اللاحقة، كلٌّ برقمه "n"). '
        . 'لكلِّ التزامٍ قرّر: "done" إن ذكر تقريرٌ لاحقٌ إنجازَه صراحةً، و"partial" إن ذكر تقدّماً فيه دون إنجاز، و"none" إن لم يُذكر. '
        . 'الشكل: {"results": [{"k": رقمُ الالتزام، "verdict": "done"|"partial"|"none"، "n": رقمُ التقرير الشاهد أو null، '
        . '"quote": نصٌّ منسوخٌ حرفياً من ذلك التقرير أو null}]}. لا تحكم بـ"done" بلا اقتباسٍ حرفيّ.';

    /** @return array{closed: int, deferred: int, calls: int, code: ?string} */
    public static function run(?GovernedCompletion &$gc, bool $dry = false): array
    {
        $out = ['closed' => 0, 'deferred' => 0, 'calls' => 0, 'code' => null];
        $due = FollowUp::models(AiCommitment::query()->where('status', 'open')
            ->where('due_on', '<=', today()->toDateString())
            ->orderBy('due_on')->orderBy('id')->limit(self::PER_USER * self::USERS * 2)->get());
        if ($due->isEmpty()) return $out;

        // ١) المهمّةُ المرتبطة أُغلقت
        $taskIds = $due->pluck('task_id')->filter()->unique()->values()->all();
        $closedTasks = $taskIds === [] ? [] : DB::table('tasks')->whereIn('id', $taskIds)
            ->whereIn('status', hub_closed_states())->pluck('id')->map(fn ($x) => (string) $x)->all();
        foreach ($due as $c) {
            if ($c->task_id && in_array((string) $c->task_id, $closedTasks, true)) {
                if (! $dry) $c->forceFill(['status' => 'done', 'closed_at' => now(), 'closed_by' => 'ai',
                    'evidence_module' => 'tasks', 'evidence_id' => (string) $c->task_id])->save();
                $out['closed']++;
            }
        }
        if ($dry) return $out;

        // ٢) تقاريرُ لاحقة — نداءٌ لكلِّ موظّف
        $open = $due->filter(fn ($c) => $c->status === 'open')->groupBy('user_id')->take(self::USERS);
        foreach ($open as $uid => $list) {
            $list = $list->take(self::PER_USER)->values();
            $since = $list->min(fn ($c) => $c->said_on->toDateString());
            $sources = $list->pluck('source_id')->map(fn ($x) => (string) $x)->all();
            $reports = DB::table('work_updates')->whereNull('deleted_at')->where('created_by', (string) $uid)
                ->where('work_date', '>=', $since)->whereNotIn('id', $sources)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::LATER_REPORTS)
                ->get(['id', 'work_date', 'done', 'doing'])->values();
            if ($reports->isEmpty()) continue;

            if ($gc === null) {
                $opened = FollowUp::session(40);
                if (is_string($opened)) return ['code' => $opened] + $out;
                $gc = $opened;
            }
            $res = FollowUp::call($gc, self::SYSTEM, [
                'commitments' => $list->map(fn ($c, $i) => ['k' => $i + 1, 'what' => FollowUp::clip($c->what, 200),
                    'said_on' => $c->said_on->toDateString()])->all(),
                'reports' => $reports->map(fn ($r, $i) => array_filter(['n' => $i + 1, 'date' => substr((string) $r->work_date, 0, 10),
                    'done' => FollowUp::clip($r->done), 'doing' => FollowUp::clip($r->doing)], fn ($v) => $v !== ''))->all(),
            ]);
            $out['calls']++;
            if (! $res['ok']) return ['code' => $res['code']] + $out;

            foreach ((array) ($res['json']['results'] ?? []) as $v) {
                if (! is_array($v) || ! is_numeric($v['k'] ?? null)) continue;
                $c = $list[(int) $v['k'] - 1] ?? null;
                if ($c === null) continue;
                $verdict = (string) ($v['verdict'] ?? 'none');
                $r = is_numeric($v['n'] ?? null) ? ($reports[(int) $v['n'] - 1] ?? null) : null;
                $quote = is_string($v['quote'] ?? null) ? $v['quote'] : '';

                if ($verdict === 'done' && $r !== null && FollowUp::quoted($quote, (string) $r->done, (string) $r->doing)) {
                    $c->forceFill(['status' => 'done', 'closed_at' => now(), 'closed_by' => 'ai', 'evidence_module' => 'updates',
                        'evidence_id' => (string) $r->id, 'evidence_quote' => mb_substr($quote, 0, 400)])->save();
                    $out['closed']++;
                } elseif ($verdict === 'partial' && $r !== null) {
                    $c->forceFill(['due_on' => today()->addDays(max(1, FollowUp::graceDays()))->toDateString()])->save();
                    $out['deferred']++;
                }
            }
        }

        return $out;
    }
}

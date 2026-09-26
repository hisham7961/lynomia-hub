<?php

namespace App\Support\Ai\Auditor\Detectors;

use App\Models\User;
use App\Support\Ai\Auditor\Detector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **قرارٌ بلا مهمّةٍ تنفّذه:** قرارٌ مفتوحٌ منذ أسبوعٍ فأكثر — أو تجاوز موعدَه — ولا مهمّةَ
 * مربوطةً به (`tasks.decision_id`). القرارُ الذي لا يصير عملاً أرشيفٌ لا قرار؛ وهذه
 * الوصلةُ نفسُها أُضيفت لقياس ذلك («القرار كان أرشيفاً — لا يُقاس بإنجاز مهامه»).
 *
 * لا يكرّر إشاراتِ التأخّر القائمة (موعدٌ فات) — يسأل سؤالاً آخر: **هل بدأ التنفيذُ أصلاً؟**
 */
final class DecisionWithoutTask implements Detector
{
    /** عمرُ القرار قبل أن يُسأل عن تنفيذه */
    public const MIN_AGE_DAYS = 7;

    /** سقفُ ما يُنظَر فيه في الجولة — وما فوقه يجعل الجولةَ ناقصةَ التغطية فلا تحلّ شيئاً */
    public const MAX_ROWS = 200;

    private bool $complete = true;

    public function key(): string
    {
        return 'decision_without_task';
    }

    public function source(): string
    {
        return 'rule';
    }

    public function label(): string
    {
        return 'قرارٌ بلا تنفيذ';
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function detect(User $auditor): array
    {
        $this->complete = true;
        if (! Schema::hasColumn('tasks', 'decision_id')) return [];

        $today = now()->toDateString();
        $cutoff = now()->subDays(self::MIN_AGE_DAYS);
        $closed = hub_closed_states();

        $rows = hub_scope(DB::table('decisions')->whereNull('deleted_at'), 'decisions', $auditor)
            ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', $closed))
            ->where(fn ($w) => $w->where('created_at', '<=', $cutoff)
                ->orWhere(fn ($d) => $d->whereNotNull('due')->where('due', '<', $today)))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tasks')
                ->whereColumn('tasks.decision_id', 'decisions.id')->whereNull('tasks.deleted_at'))
            ->orderBy('created_at')->orderBy('id')
            ->limit(self::MAX_ROWS + 1)
            ->get(['id', 'title', 'status', 'due', 'owner_id', 'exec_id', 'company_id', 'created_at']);

        // أكثرُ من السقف ⇒ ما لم يُنظَر فيه لا يُحَلّ (Auditor::run يقرأ complete())
        if ($rows->count() > self::MAX_ROWS) {
            $this->complete = false;
            $rows = $rows->take(self::MAX_ROWS);
        }

        $out = [];
        foreach ($rows as $d) {
            $late = $d->due !== null && substr((string) $d->due, 0, 10) < $today;
            $out[] = [
                'severity' => $late ? 'medium' : 'info',
                'subject_module' => 'decisions',
                'subject_id' => (string) $d->id,
                'subject_user_id' => $d->exec_id ?: $d->owner_id,
                'company_id' => $d->company_id,
                'evidence' => [['module' => 'decisions', 'id' => (string) $d->id]],
                'fields' => ['decisions' => ['title', 'status', 'due']],
                'summary' => 'القرارُ «' . mb_substr((string) $d->title, 0, 80) . '» مفتوحٌ منذ '
                    . substr((string) $d->created_at, 0, 10) . ' ولا مهمّةَ تنفّذه'
                    . ($late ? ' — وتجاوز موعدَه ' . substr((string) $d->due, 0, 10) : '') . '.',
                'suggestion' => 'أنشئ مهمّةً مربوطةً بالقرار وأسنِدها، أو أغلق القرارَ إن لم يعد قائماً.',
                'input' => [$d->id, $d->status, $d->due, $late],
                'identity' => [(string) $d->id],
            ];
        }

        return $out;
    }
}

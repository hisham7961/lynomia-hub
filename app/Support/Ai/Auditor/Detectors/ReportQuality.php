<?php

namespace App\Support\Ai\Auditor\Detectors;

use App\Models\User;
use App\Support\Ai\Auditor\AiDetector;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\Auditor\Reports;
use App\Support\Ai\Auditor\Text;
use Illuminate\Support\Facades\DB;

/**
 * **جودةُ التقرير (بالذكاء):** تقريرٌ **مبهم** لا يذكر ناتجاً يمكن التحقّقُ منه («اشتغلتُ على
 * المهام»)، أو تقريرٌ **لا يتّسق مع مهمّته المُسنَدة**. وهذا ما لا تراه قاعدة: المعنى لا الشكل.
 *
 * ── **الدفعةُ لا تعبر حدودَ الرؤية** ──
 *
 * بنودُ الدفعةِ الواحدةِ من **كاتبٍ واحدٍ على مشروعٍ واحدٍ في شركةٍ واحدة**، و**كلُّ** بندٍ فيها
 * (ومهمّتُه إن أُرسلت) في شاهدِ كلِّ نتيجةٍ منها — فرأيُ النموذجِ الحرُّ، لو اقتبس بنداً مجاوراً
 * خطأً أو بحقنٍ في نصّ، لا يقتبس إلّا ما يراه مشاهدُ النتيجةِ أصلاً. ودفعةٌ مختلطةُ الكتّابِ
 * كانت تُري مراجعاً في شركةٍ نصَّ تقريرٍ في شركةٍ أخرى داخل «رأي النموذج».
 */
final class ReportQuality extends AiDetector
{
    /** نافذةُ هذا الكاشف أقصر — الحكمُ على تقريرٍ قديمٍ لا يُصلح شيئاً اليوم */
    public const WINDOW_DAYS = 3;

    public const BATCH = 8;

    public const VERDICTS = ['ok', 'vague', 'mismatch'];

    public const SYSTEM = <<<'TXT'
        أنت مدقّقُ جودةٍ لتقاريرِ عملٍ يوميّةٍ كتبها موظّف. لكلِّ بندٍ (n) حكمٌ واحد:
        - ok: يذكر ناتجاً محدّداً يمكن التحقّقُ منه (ملفٌّ، شاشةٌ، رقمٌ، عميلٌ، مرحلةٌ اكتملت)، ويتّسق مع مهمّته إن ذُكرت.
        - vague: عباراتٌ عامّةٌ لا تذكر ناتجاً يمكن التحقّقُ منه («عملتُ على المهام»، «متابعة»، «شغل عادي»).
        - mismatch: يذكر عملاً لا صلةَ له بالمهمّةِ المُسنَدة المذكورة في البند نفسِه.
        احكم على النصِّ لا على الشخص، وإن شككتَ فاحكم ok. وسببٌ قصيرٌ بالعربيّة عن البند نفسِه وحدَه — لا تقتبس بنداً آخر.
        الشكل: {"items":[{"n":1,"verdict":"ok","reason":"..."}]}
        TXT;

    public function key(): string
    {
        return 'report_quality';
    }

    public function label(): string
    {
        return 'جودةُ التقرير';
    }

    public function detect(User $auditor): array
    {
        $this->begin();
        $rows = Reports::recent($auditor, self::WINDOW_DAYS)
            ->filter(fn ($r) => ! Text::isEmptyish($r->done))->sortBy('id')->values();
        if ($rows->isEmpty()) return [];

        $taskIds = $rows->pluck('task_id')->filter()->unique()->values()->all();
        $tasks = $taskIds === [] ? collect() : hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks', $auditor)
            ->whereIn('id', $taskIds)->get(['id', 'title'])->keyBy('id');

        $out = [];
        $groups = [];   // (كاتب · شركة · مشروع) ⇒ مرشّحون
        foreach ($rows as $r) {
            $task = $r->task_id ? ($tasks[$r->task_id] ?? null) : null;
            $input = [(string) $r->id, Text::norm($r->done), Text::norm($r->doing), $task?->title];
            $fp = self::fingerprint($input);
            $identity = [(string) $r->id];

            if (($s = $this->stored($identity, $fp)) !== null) { $out[] = $s; continue; }
            if ($this->judgedOk($fp)) continue;
            $groups[$r->created_by . '|' . $r->company_id . '|' . $r->project_id][] =
                ['r' => $r, 'task' => $task, 'fp' => $fp, 'input' => $input, 'identity' => $identity];
        }

        foreach ($groups as $pending) {
            foreach (array_chunk($pending, self::BATCH) as $batch) {
                $gc = $this->session();
                if ($gc === null) return $out;

                $res = AuditorAi::ask($gc, self::SYSTEM, array_map(fn ($c) =>
                    ($c['task'] ? 'المهمّةُ المُسنَدة: «' . $c['task']->title . "»\n" : '')
                    . 'ما تمّ إنجازه: ' . $c['r']->done
                    . ($c['r']->doing ? "\nقيد العمل: " . $c['r']->doing : ''), $batch), 'items');

                if (! $res['ok']) {
                    if ($this->failed($res)) return $out;
                    continue;
                }

                $verdicts = [];
                foreach ($res['json']['items'] as $it) {
                    if (! is_array($it)) continue;
                    $n = AuditorAi::int($it['n'] ?? null);
                    $v = is_string($it['verdict'] ?? null) ? $it['verdict'] : '';
                    if ($n === null || $n < 1 || $n > count($batch) || ! in_array($v, self::VERDICTS, true)) continue;   // بندٌ لم يُرسَل يُسقَط
                    $verdicts[$n] = ['v' => $v, 'why' => AuditorAi::str($it['reason'] ?? '', 160)];
                }

                // **الشاهدُ كلُّ ما أُرسل في الدفعة** — تقاريرُها ومهامُّها — فحدُّ رؤيةِ النتيجةِ حدُّ ما رآه النموذج
                $evidence = [];
                foreach ($batch as $c) {
                    $evidence[] = ['module' => 'updates', 'id' => (string) $c['r']->id];
                    if ($c['task']) $evidence[] = ['module' => 'tasks', 'id' => (string) $c['task']->id];
                }
                $evidence = array_values(array_unique($evidence, SORT_REGULAR));
                $withTasks = (bool) array_filter($batch, fn ($c) => $c['task'] !== null);

                foreach ($batch as $i => $c) {
                    $got = $verdicts[$i + 1] ?? null;
                    if ($got === null) { $this->complete = false; continue; }   // سُكت عنه ⇒ لم يُحكَم عليه
                    // «لا يتّسق مع مهمّته» عن بندٍ بلا مهمّةٍ مُرسَلة تناقضٌ في الردّ — لا يُحوَّل «مبهماً»
                    if ($got['v'] === 'ok' || ($got['v'] === 'mismatch' && $c['task'] === null)) {
                        $this->rememberOk($c['fp']);
                        $this->clear($c['identity']);
                        continue;
                    }
                    $out[] = $this->finding($c, $got['v'], $got['why'], $evidence, $withTasks)
                        + ['usage_event_id' => $res['event'] ?? null];
                }
            }
        }

        return $out;
    }

    private function finding(array $c, string $verdict, string $why, array $evidence, bool $withTasks): array
    {
        $r = $c['r'];
        $mismatch = $verdict === 'mismatch';
        $fields = ['updates' => ['done', 'doing', 'workDate']];
        if ($withTasks) $fields['tasks'] = ['title'];

        return [
            'severity' => $mismatch ? 'medium' : 'info',
            'subject_module' => 'updates',
            'subject_id' => (string) $r->id,
            'subject_user_id' => (string) $r->created_by,
            'company_id' => $r->company_id,
            'evidence' => $evidence,
            'fields' => $fields,
            'summary' => 'تقريرُ ' . Reports::day($r) . ' '
                . ($mismatch ? 'لا يبدو متّسقاً مع المهمّة «' . mb_substr((string) $c['task']->title, 0, 60) . '»'
                             : 'مبهمٌ لا يذكر ناتجاً يمكن التحقّقُ منه')
                . ($why !== '' ? ' — رأيُ النموذج: «' . $why . '»' : '') . ' (حكمُ النموذج — تحقّق).',
            'suggestion' => $mismatch ? 'تحقّق من المهمّة المُسنَدة أو اطلب توضيحاً.'
                : 'اطلب ذكرَ ما أُنجز فعلاً: ملفٌّ، شاشةٌ، رقمٌ، مرحلة.',
            'draft' => ['note' => $mismatch
                ? 'تقريرُك ليوم ' . Reports::day($r) . ' لا يبدو متعلّقاً بالمهمّة المُسنَدة إليك — وضّح ما أنجزته فيها أو نبّهني إن تغيّرت مهمّتك.'
                : 'تقريرُك ليوم ' . Reports::day($r) . ' عامٌّ لا يذكر ما أُنجز تحديداً — اذكر الناتج (ملفٌّ، شاشةٌ، رقمٌ، مرحلة) لنستطيع متابعته.'],
            'input' => $c['input'],
            'identity' => $c['identity'],
        ];
    }
}

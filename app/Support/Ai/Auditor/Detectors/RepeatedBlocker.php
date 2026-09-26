<?php

namespace App\Support\Ai\Auditor\Detectors;

use App\Models\User;
use App\Support\Ai\Auditor\Detector;
use App\Support\Ai\Auditor\Reports;
use App\Support\Ai\Auditor\Text;

/**
 * **عائقٌ يتكرّر ولا يُعالَج:** الموظّفُ نفسُه يكتب المشكلةَ نفسَها في **ثلاثةِ أيّامٍ**
 * فأكثر خلال النافذة — **وما زال يكتبها في آخرِ يومٍ قدّم فيه تقريراً**. تقريرٌ واحدٌ
 * بعائقٍ خبر؛ ثلاثةُ أيّامٍ بالعائقِ نفسِه **طلبُ مساعدةٍ لم يُسمَع**.
 *
 * والعدُّ بالأيّامِ لا بالبنود: يومٌ واحدٌ ببنودٍ ثلاثة (مشروعٌ لكلٍّ) يكرّر فيها العائقَ
 * نفسَه **ليس تكراراً عبر الزمن**. وعائقٌ غاب عن آخرِ يومٍ لا يُقال عنه «لم يُعالَج» —
 * فلعلّه عولج.
 *
 * هذا الكاشفُ يرى التكرارَ **الحرفيّ** (بعد التطبيع). والعائقُ نفسُه بصيغٍ مختلفة —
 * «السيرفر بطيء» ثمّ «بطء الخادم» — يراه كاشفُ الذكاء لا هذا.
 */
final class RepeatedBlocker implements Detector
{
    public const MIN_DAYS = 3;

    public const MIN_CHARS = 6;

    public function key(): string
    {
        return 'repeated_blocker';
    }

    public function source(): string
    {
        return 'rule';
    }

    public function label(): string
    {
        return 'عائقٌ متكرّر';
    }

    public function complete(): bool
    {
        return true;
    }

    public function detect(User $auditor): array
    {
        $out = [];
        foreach (Reports::recent($auditor)->groupBy('created_by') as $author => $rows) {
            $lastDay = $rows->map(fn ($r) => Reports::day($r))->max();

            $byText = [];
            foreach ($rows as $r) {
                if (Text::isEmptyish($r->problems)) continue;
                $n = Text::norm($r->problems);
                if (mb_strlen($n) < self::MIN_CHARS) continue;
                $byText[$n][] = $r;
            }

            foreach ($byText as $n => $hits) {
                $days = array_values(array_unique(array_map(fn ($r) => Reports::day($r), $hits)));
                sort($days);
                if (count($days) < self::MIN_DAYS || end($days) !== $lastDay) continue;

                usort($hits, fn ($a, $b) => [Reports::day($a), (string) $a->id] <=> [Reports::day($b), (string) $b->id]);
                $last = end($hits);
                // **الشاهدُ كلُّ البنود لا آخرُها** — فالعددُ في الملخّص يُحسب منها،
                // ومن لا يرى أحدَها لا يرى النتيجة (النافذةُ أسبوعان فلا يطول)
                $evidence = array_map(fn ($r) => ['module' => 'updates', 'id' => (string) $r->id], $hits);

                $out[] = [
                    'severity' => count($days) >= 5 ? 'high' : 'medium',
                    'subject_module' => 'updates',
                    'subject_id' => (string) $last->id,
                    'subject_user_id' => (string) $last->created_by,
                    'company_id' => $last->company_id,
                    'evidence' => $evidence,
                    'fields' => ['updates' => ['problems', 'workDate']],
                    'summary' => 'العائقُ نفسُه «' . Text::clip($last->problems) . '» يتكرّر في ' . count($days)
                        . ' أيّامٍ منذ ' . $days[0] . ' — وما زال يُذكر حتى آخرِ تقريرٍ في ' . $lastDay . '.',
                    'suggestion' => 'أزِل العائقَ أو صعِّده، وأخبِر صاحبَ التقرير بما تقرّر.',
                    'input' => [array_map(fn ($r) => (string) $r->id, $hits), (string) $n],
                    // **الهويّةُ: هذا العائقُ عند هذا الموظّف** — لا أحدثُ تقريرٍ ذكره
                    'identity' => [(string) $author, (string) $n],
                ];
            }
        }

        return $out;
    }
}

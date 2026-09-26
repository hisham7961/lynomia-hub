<?php

namespace App\Support\Ai\Auditor\Detectors;

use App\Models\User;
use App\Support\Ai\Auditor\Detector;
use App\Support\Ai\Auditor\Reports;
use App\Support\Ai\Auditor\Text;

/**
 * **تقريرٌ منسوخ:** «ما تمّ إنجازه» في بندِ اليوم هو نصُّ أحدِ بنودِ **آخرِ يومٍ سابقٍ**
 * لصاحبه حرفيّاً (بعد التطبيع).
 *
 * ولماذا «أحدُ بنودِ اليومِ السابق» لا «البندُ السابق»؟ لأنّ اليومَ الواحدَ قد يحمل بنوداً
 * عدّة (مشروعٌ لكلٍّ)، وترتيبُها داخلَ اليومِ لا معنى له — والمقارنةُ بـ«آخرِ بندٍ» كانت
 * ستتعلّق بترتيبِ معرّفاتٍ عشوائيّة (CLAUDE.md: القرعة). فاليومُ يُقارَن باليومِ بمجموعه.
 *
 * يومان بإنجازٍ واحدٍ بالحرف لا يعني بالضرورة كذباً — قد يكون عملاً ممتدّاً كُتب بكسل —
 * لكنّه تقريرٌ لا يخبر المديرَ بشيءٍ جديد، وهذا ما يُنبَّه عليه. والحدُّ الأدنى للطول
 * يستبعد ما يتكرّر طبيعيّاً لقِصَره («اجتماعات»، «دعم»).
 */
final class CopiedReport implements Detector
{
    /** أقصرُ نصٍّ يُعَدّ تكرارُه نسخاً لا مصادفة */
    public const MIN_CHARS = 15;

    public function key(): string
    {
        return 'copied_report';
    }

    public function source(): string
    {
        return 'rule';
    }

    public function label(): string
    {
        return 'تقريرٌ منسوخ';
    }

    public function complete(): bool
    {
        return true;
    }

    public function detect(User $auditor): array
    {
        $out = [];
        foreach (Reports::recent($auditor)->groupBy('created_by') as $rows) {
            $days = $rows->groupBy(fn ($r) => Reports::day($r))->sortKeys();
            $prevDay = null;
            $prevTexts = [];   // نصٌّ مطبَّع ⇒ البندُ الذي حمله في اليومِ السابق
            foreach ($days as $day => $items) {
                $texts = [];
                foreach ($items->sortBy('id') as $r) {
                    $n = Text::norm($r->done);
                    $texts[$n] ??= $r;
                    if ($prevDay === null || mb_strlen($n) < self::MIN_CHARS || ! isset($prevTexts[$n])) continue;

                    $prev = $prevTexts[$n];
                    $out[] = [
                        'severity' => 'medium',
                        'subject_module' => 'updates',
                        'subject_id' => (string) $r->id,
                        'subject_user_id' => (string) $r->created_by,
                        'company_id' => $r->company_id,
                        'evidence' => [['module' => 'updates', 'id' => (string) $prev->id],
                                       ['module' => 'updates', 'id' => (string) $r->id]],
                        'fields' => ['updates' => ['done', 'workDate']],
                        'summary' => 'تقريرُ ' . $day . ' يكرّر «ما تمّ إنجازه» من تقرير '
                            . $prevDay . ' حرفيّاً: «' . Text::clip($r->done) . '».',
                        'suggestion' => 'اطلب من صاحبِ التقرير وصفَ ما أُنجز فعلاً في هذا اليوم.',
                        // مسودةُ ملاحظةٍ للموظّف — لا تصله إلّا إن حرّرها المديرُ وأرسلها (§٣.٤ · §٣.٦)
                        'draft' => ['note' => 'تقريرُك ليوم ' . $day . ' يكرّر ما كتبتَه ليوم ' . $prevDay
                            . ' حرفيّاً — صِف ما أنجزتَه فعلاً في هذا اليوم.'],
                        'input' => [(string) $prev->id, (string) $r->id, $n],
                        'identity' => [(string) $r->id],
                    ];
                }
                $prevDay = (string) $day;
                $prevTexts = $texts;
            }
        }

        return $out;
    }
}

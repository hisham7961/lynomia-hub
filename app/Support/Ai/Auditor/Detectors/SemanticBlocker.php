<?php

namespace App\Support\Ai\Auditor\Detectors;

use App\Models\AiFinding;
use App\Models\User;
use App\Support\Ai\Auditor\AiDetector;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\Auditor\Reports;
use App\Support\Ai\Auditor\Text;

/**
 * **العائقُ نفسُه بصيغٍ مختلفة (بالذكاء):** «السيرفر بطيء» ثمّ «بطء الخادم» ثمّ «التطبيقُ يتأخّر
 * في الاستجابة» — ثلاثةُ أيّامٍ وعائقٌ واحدٌ لا تراه القاعدةُ الحرفيّة (`RepeatedBlocker`).
 *
 * النموذجُ يرى أحدثَ عوائقِ موظّفٍ واحدٍ مرقّمةً ويجمع ما يصف الشيءَ نفسَه. **والخادمُ لا يثق
 * بالتجميع:** كلُّ رقمٍ يُتحقَّق أنّه أُرسل، والحكمُ — ثلاثةُ أيّامٍ فأكثر وما زال في آخرِ يوم —
 * يُعاد حسابُه من التواريخ. **ولا نصَّ من النموذج في الملخّص:** يُقتبس نصُّ أحدثِ بندٍ في المجموعة
 * (وهو في الشاهد) — فوصفٌ حرٌّ من النموذجِ قد يقتبس بنداً خارجَ الشاهد.
 *
 * **ومجموعةٌ يغطّيها الكاشفُ الحسابيّ لا تُرصد مرّتين:** إن كان في المجموعة نصٌّ واحدٌ يتكرّر
 * حرفيّاً في ثلاثةِ أيّامٍ حتى آخرِ يوم، فالكاشفُ الحسابيُّ رصده.
 *
 * **والهويّةُ ثابتةٌ مع انزلاقِ النافذة:** مجموعةٌ تشارك نتيجةً مفتوحةً لهذا الموظّف في بندٍ واحدٍ
 * على الأقلّ هي الشرطُ نفسُه — فتأخذ مفتاحَها، ويبقى تصرّفُ المدير بها.
 */
final class SemanticBlocker extends AiDetector
{
    public const MIN_DAYS = 3;

    public const MAX_ITEMS = 20;

    public const SYSTEM = <<<'TXT'
        هذه عوائقُ كتبها موظّفٌ واحدٌ في تقاريره اليوميّة، كلٌّ برقمه (n).
        اجمع البنودَ التي تصف **العائقَ نفسَه** وإن اختلفت صياغتُها. لا تُدرج بنداً في مجموعتين، ولا تُعِد مجموعةً ببندٍ واحد،
        ولا تجمع عائقين مختلفين لتشابهِ ألفاظهما.
        الشكل: {"groups":[{"items":[1,4,7]}]} — وإن لم يتكرّر شيءٌ: {"groups":[]}
        TXT;

    public function key(): string
    {
        return 'semantic_blocker';
    }

    public function label(): string
    {
        return 'عائقٌ متكرّرٌ بصيغٍ مختلفة';
    }

    public function detect(User $auditor): array
    {
        $this->begin();
        $out = [];

        foreach (Reports::recent($auditor)->groupBy('created_by') as $author => $rows) {
            $author = (string) $author;
            $lastDay = $rows->map(fn ($r) => Reports::day($r))->max();
            // **الأحدثُ لا الأقدم** — كاتبٌ غزيرُ التقارير لا يُعمى عن عوائقه الجارية
            $items = $rows->filter(fn ($r) => ! Text::isEmptyish($r->problems) && mb_strlen(Text::norm($r->problems)) >= 6)
                ->sortBy(fn ($r) => Reports::day($r) . '|' . $r->id)->values()->slice(-self::MAX_ITEMS)->values()->all();
            if (count($items) < self::MIN_DAYS) continue;
            if (count(array_unique(array_map(fn ($r) => Reports::day($r), $items))) < self::MIN_DAYS) continue;
            if (count(array_unique(array_map(fn ($r) => Text::norm($r->problems), $items))) < 2) continue;

            $input = array_map(fn ($r) => [(string) $r->id, Text::norm($r->problems)], $items);
            $fp = self::fingerprint([$author, $input]);
            $open = AiFinding::query()->where('detector', $this->key())->where('subject_user_id', $author)
                ->where('status', 'open')->orderBy('id')->get();

            $stored = $open->filter(fn ($row) => $row->fingerprint === $fp);
            if ($stored->isNotEmpty()) {
                foreach ($stored as $row) $out[] = $this->reemit($row, $fp);
                continue;
            }
            if ($this->judgedOk($fp)) continue;

            $gc = $this->session();
            if ($gc === null) break;
            $res = AuditorAi::ask($gc, self::SYSTEM, array_map(fn ($r) => (string) $r->problems, $items), 'groups');
            if (! $res['ok']) {
                if ($this->failed($res)) break;
                continue;
            }

            $emitted = [];
            $used = [];
            foreach ($res['json']['groups'] as $g) {
                if (! is_array($g) || ! is_array($g['items'] ?? null)) continue;
                $idx = [];
                foreach ($g['items'] as $n) {
                    $n = AuditorAi::int($n);
                    if ($n !== null && $n >= 1 && $n <= count($items) && ! isset($used[$n])) $idx[$n] = true;
                }
                $group = array_map(fn ($n) => $items[$n - 1], array_keys($idx));
                usort($group, fn ($a, $b) => [Reports::day($a), (string) $a->id] <=> [Reports::day($b), (string) $b->id]);
                $gDays = array_values(array_unique(array_map(fn ($r) => Reports::day($r), $group)));
                sort($gDays);

                // **الحكمُ يُعاد حسابُه من البيانات** — لا من دعوى النموذج
                if (count($gDays) < self::MIN_DAYS || end($gDays) !== $lastDay) continue;
                if (count(array_unique(array_map(fn ($r) => Text::norm($r->problems), $group))) < 2) continue;
                if ($this->literalCovers($group, $lastDay)) continue;
                foreach (array_keys($idx) as $n) $used[$n] = true;

                $ids = array_map(fn ($r) => (string) $r->id, $group);
                $prior = $open->first(fn ($row) => array_intersect($ids, array_column((array) $row->evidence, 'id')) !== []);
                $last = end($group);
                $f = [
                    'severity' => count($gDays) >= 5 ? 'high' : 'medium',
                    'subject_module' => 'updates',
                    'subject_id' => (string) $last->id,
                    'subject_user_id' => $author,
                    'company_id' => $last->company_id,
                    'evidence' => array_map(fn ($id) => ['module' => 'updates', 'id' => $id], $ids),
                    'fields' => ['updates' => ['problems', 'workDate']],
                    'summary' => 'عائقٌ واحدٌ بصيغٍ مختلفة — آخرُها «' . Text::clip($last->problems) . '» — في '
                        . count($gDays) . ' أيّامٍ منذ ' . $gDays[0] . '، وما زال يُذكر حتى ' . $lastDay . ' (تجميعُ النموذج — تحقّق).',
                    'suggestion' => 'أزِل العائقَ أو صعِّده، وأخبِر صاحبَ التقرير بما تقرّر.',
                    'fingerprint' => $fp,
                    'usage_event_id' => $res['event'] ?? null,
                ];
                // الشرطُ نفسُه إن شارك نتيجةً مفتوحةً في بند — فيبقى مفتاحُها وتصرّفُ المدير بها
                $f += $prior !== null ? ['dedup_key' => $prior->dedup_key] : ['identity' => [$author, Text::norm($group[0]->problems)]];
                $out[] = $f;
                $emitted[] = $f['dedup_key'] ?? \App\Support\Ai\Auditor\Auditor::dedupKey($f);
            }

            // **فُحص هذا الموظّفُ فعلاً** — فنتائجُه المفتوحةُ التي لم تُعَد رُئيت سليمة
            foreach ($open as $row) {
                if (! in_array($row->dedup_key, $emitted, true)) $this->cleared[$row->dedup_key] = true;
            }
            if ($emitted === []) $this->rememberOk($fp);   // فُحص ولم يتكرّر عنده شيء
        }

        return $out;
    }

    /** هل يغطّي الكاشفُ الحسابيُّ هذه المجموعة؟ — نصٌّ واحدٌ حرفيٌّ في ثلاثةِ أيّامٍ حتى آخرِ يوم */
    private function literalCovers(array $group, string $lastDay): bool
    {
        $byText = [];
        foreach ($group as $r) $byText[Text::norm($r->problems)][Reports::day($r)] = true;
        foreach ($byText as $days) {
            if (count($days) >= RepeatedBlocker::MIN_DAYS && isset($days[$lastDay])) return true;
        }

        return false;
    }

    private function reemit(AiFinding $row, string $fp): array
    {
        return [
            'severity' => $row->severity, 'subject_module' => $row->subject_module, 'subject_id' => $row->subject_id,
            'subject_user_id' => $row->subject_user_id, 'company_id' => $row->company_id,
            'evidence' => (array) $row->evidence, 'fields' => (array) $row->fields,
            'summary' => $row->summary, 'suggestion' => $row->suggestion, 'draft' => $row->draft,
            'fingerprint' => $fp, 'dedup_key' => $row->dedup_key, 'usage_event_id' => $row->usage_event_id,
        ];
    }
}

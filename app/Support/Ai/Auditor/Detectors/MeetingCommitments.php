<?php

namespace App\Support\Ai\Auditor\Detectors;

use App\Models\User;
use App\Support\Ai\Auditor\AiDetector;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\Auditor\Text;
use Illuminate\Support\Facades\DB;

/**
 * **التزاماتُ محضرٍ لم تُسجَّل قرارات (بالذكاء):** محضرُ اجتماعٍ يقول «يتولّى فلانٌ تجهيزَ العرض
 * قبل الخميس» ولا قرارَ مربوطٌ بالاجتماع يحمل ذلك. الالتزامُ الذي لا يُسجَّل لا يُتابَع.
 *
 * النموذجُ يرى نصَّ **محضرٍ واحد** (كاملاً حتى `CLIP` — لا مقتطفاً يُسقط آخرَ الالتزامات) وعناوينَ
 * القراراتِ المسجَّلةِ له — لا غير — فكلُّ ما يعود منه في شاهدِ النتيجة (الاجتماعُ وقراراتُه).
 * **والنتيجةُ اقتراحٌ لا تسجيل:** رابطُها نموذجُ «قرارٍ جديد» معبّأٌ بأوّلِها ومربوطٌ بالاجتماع،
 * والحفظُ فعلُ إنسانٍ بصلاحيّاته (§٣.٤).
 */
final class MeetingCommitments extends AiDetector
{
    public const WINDOW_DAYS = 14;

    /** أقصرُ محضرٍ يُسأل عنه — ما دونه لا يحمل التزاماتٍ تُستخرج */
    public const MIN_CHARS = 80;

    /** سقفُ ما يُنظَر فيه — أسبوعان من الاجتماعات؛ وما فوقه يجعل الجولةَ ناقصةً لا مجمَّدة */
    public const MAX_MEETINGS = 200;

    /** طولُ المحضرِ المُرسَل — كافٍ لمحضرٍ كامل، والتزامٌ في آخره لا يُسقَط */
    public const CLIP = 6000;

    public const SYSTEM = <<<'TXT'
        البندُ ١ محضرُ اجتماع، وما بعده (إن وُجد) عناوينُ قراراتٍ سُجّلت له فعلاً.
        استخرج من المحضر **الالتزاماتِ القابلةَ للتنفيذ** (عملٌ محدّدٌ على أحدٍ أن يفعله) التي **لا يغطّيها** أيٌّ من القراراتِ المسجَّلة.
        لا تخترع التزاماً غيرَ مكتوب، ولا تُعِد ما سُجّل، ولا تتجاوز خمسة. لكلٍّ: ماذا (جملةٌ قصيرة)، ومن ومتى إن ذُكرا (نصّاً).
        الشكل: {"commitments":[{"what":"...","who":"...","when":"..."}]} — وإن لم يبقَ شيء: {"commitments":[]}
        TXT;

    public function key(): string
    {
        return 'meeting_commitments';
    }

    public function label(): string
    {
        return 'التزاماتٌ لم تُسجَّل';
    }

    public function detect(User $auditor): array
    {
        $this->begin();
        $since = now()->subDays(self::WINDOW_DAYS);

        $meetings = hub_scope(DB::table('meetings')->whereNull('deleted_at'), 'meetings', $auditor)
            ->where(fn ($w) => $w->where('dt', '>=', $since)->orWhere(fn ($x) => $x->whereNull('dt')->where('created_at', '>=', $since)))
            ->whereNotNull('notes')
            ->orderByDesc('dt')->orderBy('id')
            ->limit(self::MAX_MEETINGS + 1)
            ->get(['id', 'title', 'dt', 'notes', 'company_id', 'created_by', 'created_at']);
        if ($meetings->count() > self::MAX_MEETINGS) {
            $this->complete = false;
            $this->stopped ??= 'أكثرُ من ' . self::MAX_MEETINGS . ' اجتماعاً في النافذة';
            $meetings = $meetings->take(self::MAX_MEETINGS);
        }

        $out = [];
        foreach ($meetings as $m) {
            if (mb_strlen(trim((string) $m->notes)) < self::MIN_CHARS) continue;

            $decisions = hub_scope(DB::table('decisions')->whereNull('deleted_at'), 'decisions', $auditor)
                ->where('meeting_id', $m->id)->orderBy('id')->get(['id', 'title']);
            $input = [(string) $m->id, Text::norm($m->notes), $decisions->map(fn ($d) => [(string) $d->id, Text::norm($d->title)])->all()];
            $fp = self::fingerprint($input);
            $identity = [(string) $m->id];

            if (($s = $this->stored($identity, $fp)) !== null) { $out[] = $s; continue; }
            if ($this->judgedOk($fp)) { $this->clear($identity); continue; }

            $gc = $this->session();
            if ($gc === null) break;
            $res = AuditorAi::ask($gc, self::SYSTEM,
                array_merge([(string) $m->notes], $decisions->map(fn ($d) => 'قرارٌ مسجَّل: ' . $d->title)->all()),
                'commitments', self::CLIP);
            if (! $res['ok']) {
                if ($this->failed($res)) break;
                continue;
            }

            $list = [];
            foreach (array_slice($res['json']['commitments'], 0, 5) as $c) {
                if (! is_array($c)) continue;
                $what = AuditorAi::str($c['what'] ?? '', 120);
                if ($what === '') continue;
                $list[] = ['what' => $what, 'who' => AuditorAi::str($c['who'] ?? '', 60), 'when' => AuditorAi::str($c['when'] ?? '', 40)];
            }
            if ($list === []) {
                $this->rememberOk($fp);
                $this->clear($identity);   // أُعيد فحصُه ولم يبقَ فيه التزام ⇒ تُحَلّ نتيجتُه وإن نقصت الجولة
                continue;
            }

            $evidence = [['module' => 'meetings', 'id' => (string) $m->id]];
            foreach ($decisions as $d) $evidence[] = ['module' => 'decisions', 'id' => (string) $d->id];

            $out[] = [
                'severity' => count($list) >= 3 ? 'medium' : 'info',
                'subject_module' => 'meetings',
                'subject_id' => (string) $m->id,
                'subject_user_id' => $m->created_by,
                'company_id' => $m->company_id,
                'evidence' => $evidence,
                'fields' => ['meetings' => ['title', 'notes', 'dt']] + ($decisions->isNotEmpty() ? ['decisions' => ['title']] : []),
                'summary' => self::summary((string) $m->title, $list),
                'suggestion' => 'سجّل كلّاً منها قراراً بمالكٍ وموعد — أو تجاهلها إن لم تكن التزامات.',
                'draft' => ['module' => 'decisions', 'fields' => ['title' => $list[0]['what'], 'meetingId' => (string) $m->id]],
                'input' => $input,
                'identity' => $identity,
                'usage_event_id' => $res['event'] ?? null,
            ];
        }

        return $out;
    }

    /** ملخّصٌ يتّسع لعمودِه (٦٠٠) **ويحفظ وسمَ «استخراجُ النموذج — تحقّق»** مهما طالت القائمة */
    private static function summary(string $title, array $list): string
    {
        $tail = ' (استخراجُ النموذج — تحقّق).';
        $head = 'محضرُ «' . mb_substr($title, 0, 60) . '» فيه ' . count($list) . ' التزامٍ لم يُسجَّل قراراً: ';
        $items = array_map(fn ($c) => '«' . Text::clip($c['what'], 80) . '»', array_slice($list, 0, 3));
        if (count($list) > 3) $items[] = 'و' . (count($list) - 3) . ' غيرُها';

        return mb_substr($head . implode(' · ', $items), 0, 590 - mb_strlen($tail)) . $tail;
    }
}

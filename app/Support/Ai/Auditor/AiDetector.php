<?php

namespace App\Support\Ai\Auditor;

use App\Models\AiFinding;
use App\Support\Ai\GovernedCompletion;
use Illuminate\Support\Facades\Cache;

/**
 * **أساسُ كواشفِ الذكاء** — ما تشترك فيه: الكلفةُ لا تُدفع مرّتين، والجولةُ الناقصةُ لا تحلّ.
 *
 * لكلِّ موضوعٍ مرشَّحٍ **بصمةُ مدخلاته**. وقبل أيِّ نداء:
 *  · نتيجةٌ مفتوحةٌ محفوظةٌ **بالبصمةِ نفسِها** ⇒ تُعاد كما هي بلا نداء (فلا تُحَلّ ظلماً لأنّ
 *    الكاشفَ «لم يرصدها» في جولةٍ لم يسأل فيها).
 *  · حكمٌ سابقٌ بالسلامة بالبصمةِ نفسِها ⇒ يُتخطّى (ذاكرةٌ قصيرةٌ في الخبيئة — فقدُها يكلّف
 *    نداءً ولا يُفسد حكماً).
 *  · وما بقي يُسأل عنه — دفعاتٍ صغيرة.
 *
 * وإن توقّفت الجولةُ قبل أن تسأل عن كلِّ ما بقي (سقفٌ أو ميزانيّةٌ أو بوّابة) صار
 * `complete() === false` فلا يحلّ `Auditor::run` من نتائجِ هذا الكاشف **إلّا ما أُعيد فحصُه
 * فعلاً ووُجد سليماً** (`cleared()`) — فجولةٌ ناقصةٌ لا تُجمّد نتيجةً زال شرطُها أمامها.
 */
abstract class AiDetector implements Detector
{
    /** مدّةُ تذكّرِ حكمِ السلامة — أطولُ قليلاً من أطولِ نافذةِ نظر */
    public const OK_TTL_DAYS = 16;

    protected bool $complete = true;

    /** مفاتيحُ شروطٍ أُعيد فحصُها في هذه الجولة ووُجدت سليمة @var array<string, true> */
    protected array $cleared = [];

    /** لماذا وقفت الجولةُ ناقصة — يُقال في `hub:auditor` لا يُبتلَع */
    protected ?string $stopped = null;

    public function cleared(): array
    {
        return array_keys($this->cleared);
    }

    public function stopReason(): ?string
    {
        return $this->stopped;
    }

    /** يبدأ كلُّ كاشفٍ جولتَه بحالةٍ نظيفة */
    protected function begin(): void
    {
        $this->complete = true;
        $this->cleared = [];
        $this->stopped = null;
    }

    protected function clear(array $identity): void
    {
        $this->cleared[Auditor::dedupKey(['identity' => $identity])] = true;
    }

    /** إخفاقُ نداء: ناقصةٌ دائماً، ووقفٌ إن لم يكن الإخفاقُ خاصّاً بالبند */
    protected function failed(array $res): bool
    {
        $this->complete = false;
        if ($res['stop']) $this->stopped ??= (string) $res['code'];

        return (bool) $res['stop'];
    }

    public function source(): string
    {
        return 'ai';
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    /** بصمةُ مدخلاتِ موضوع — هي نفسُها ما يحفظه `Auditor::store` */
    public static function fingerprint(mixed $input): string
    {
        return hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE));
    }

    /** نتيجةٌ مفتوحةٌ محفوظةٌ لهذا الشرطِ بهذه البصمة — تُعاد بلا نداء */
    protected function stored(array $identity, string $fp): ?array
    {
        $row = AiFinding::query()->where('detector', $this->key())
            ->where('dedup_key', Auditor::dedupKey(['identity' => $identity]))
            ->where('status', 'open')->where('fingerprint', $fp)
            ->orderBy('id')->first();
        if ($row === null) return null;

        return [
            'severity' => $row->severity, 'subject_module' => $row->subject_module, 'subject_id' => $row->subject_id,
            'subject_user_id' => $row->subject_user_id, 'company_id' => $row->company_id,
            'evidence' => (array) $row->evidence, 'fields' => (array) $row->fields,
            'summary' => $row->summary, 'suggestion' => $row->suggestion, 'draft' => $row->draft,
            'identity' => $identity, 'fingerprint' => $fp, 'usage_event_id' => $row->usage_event_id,
        ];
    }

    protected function judgedOk(string $fp): bool
    {
        return (bool) Cache::get($this->okKey($fp));
    }

    protected function rememberOk(string $fp): void
    {
        Cache::put($this->okKey($fp), 1, now()->addDays(self::OK_TTL_DAYS));
    }

    private function okKey(string $fp): string
    {
        return 'auditor:ok:' . $this->key() . ':' . $fp;
    }

    /** جلسةُ الجولةِ الحاليّة — أو `null` (غيرُ جاهزة) فتُعَدّ الجولةُ ناقصة */
    protected function session(): ?GovernedCompletion
    {
        $gc = AuditorAi::session(Auditor::runId());
        if ($gc === null) {
            $this->complete = false;
            $this->stopped ??= 'UNAVAILABLE';
        }

        return $gc;
    }
}

<?php

namespace App\Support\Ai\Auditor;

use App\Models\AiFinding;
use App\Support\Ai\Auditor\Detectors\CopiedReport;
use App\Support\Ai\Auditor\Detectors\DecisionWithoutTask;
use App\Support\Ai\Auditor\Detectors\HoursWithoutProgress;
use App\Support\Ai\Auditor\Detectors\MeetingCommitments;
use App\Support\Ai\Auditor\Detectors\RepeatedBlocker;
use App\Support\Ai\Auditor\Detectors\ReportQuality;
use App\Support\Ai\Auditor\Detectors\SemanticBlocker;
use App\Support\Redactor;
use Illuminate\Support\Facades\Schema;

/**
 * **المدقّقُ العامّ لعمل الفريق** (`docs/ai-hub/46-ai-roadmap.md` §٣).
 *
 * يقرأ عملَ الفريق دوريّاً بهويّةِ خدمةٍ ضيّقة، ويكتشف ما لا يلتقطه منتِجٌ قائم،
 * ويقترح — **ولا يكتب في أيِّ سجلّ**: ما يكتبه نتائجُه وحدها (`ai_findings`)، والعرضُ
 * إشاراتٌ في مركز الفعل القائم، والتصرّفُ بها في `signal_states` القائم.
 *
 * **دورةُ النتيجة:** كاشفٌ يرصد ⇒ `open`؛ يعود فلا يرصدها ⇒ `resolved` (حلٌّ تلقائيّ)؛
 * يرصدها ثانيةً ⇒ `open` من جديد. والنتيجةُ تُعرَف **بهويّةِ شرطِها** (`identity` ⇒
 * `dedup_key`) لا بالسجلّ الذي تُعرَض عليه — فعائقٌ يتكرّر يبقى نتيجةً واحدةً بمفتاحٍ
 * واحدٍ وإن كُتب في تقريرٍ جديدٍ كلَّ يوم.
 *
 * و**كاشفٌ فشل لا يحلّ شيئاً** — الفشلُ ليس «زال الشرط»؛ ولا كاشفٌ لم يُغطِّ كلَّ
 * موضوعاته (`Detector::complete() === false`).
 */
final class Auditor
{
    /** @return list<Detector> */
    public static function detectors(): array
    {
        return [
            new CopiedReport(),
            new RepeatedBlocker(),
            new HoursWithoutProgress(),
            new DecisionWithoutTask(),
            // كواشفُ الذكاء (A2) — تعمل فقط حين `AuditorAi::whyNot() === null`
            new ReportQuality(),
            new SemanticBlocker(),
            new MeetingCommitments(),
        ];
    }

    private static ?string $runId = null;

    /** معرّفُ الجولةِ الجارية — تُفتح به جلسةُ الذكاءِ الواحدة وسقفُ نداءاتها */
    public static function runId(): string
    {
        return self::$runId ??= (string) \Illuminate\Support\Str::uuid();
    }

    /** المفتاحُ العامّ للمدقّق — يُطفئه كلَّه دون أن يمسح نتيجة */
    public static function enabled(): bool
    {
        return (string) setting('auditor.enabled', '1') === '1';
    }

    public static function detector(string $key): ?Detector
    {
        foreach (self::detectors() as $d) {
            if ($d->key() === $key) return $d;
        }

        return null;
    }

    /** هويّةُ الشرط ⇒ مفتاحٌ ثابت (ونتيجةٌ أُعيدت من الحفظ تحمل مفتاحَها كما هو) */
    public static function dedupKey(array $f): string
    {
        if (isset($f['dedup_key']) && is_string($f['dedup_key']) && strlen($f['dedup_key']) === 40) return $f['dedup_key'];

        return sha1(json_encode($f['identity'] ?? [$f['subject_module'], $f['subject_id']], JSON_UNESCAPED_UNICODE));
    }

    /**
     * **جولةٌ واحدة.** لكلِّ كاشف: رصدٌ ⇒ حفظٌ أو تحديث ⇒ حلُّ ما زال شرطُه.
     * والمعاينةُ (`$dry`) تحسب الأعدادَ نفسَها — جديدٌ وزائل — **ولا تكتب**.
     *
     * @return array<string, array{found: int, opened: int, resolved: int, error: ?string, skipped: ?string}>
     */
    public static function run(bool $dry = false): array
    {
        $stats = [];
        if (! self::enabled() || ! Schema::hasTable('ai_findings')) return $stats;

        $auditor = AuditorIdentity::user();
        self::$runId = (string) \Illuminate\Support\Str::uuid();
        AuditorAi::reset();
        $aiWhyNot = AuditorAi::whyNot();

        foreach (self::detectors() as $d) {
            $key = $d->key();
            $stats[$key] = ['found' => 0, 'opened' => 0, 'resolved' => 0, 'error' => null, 'skipped' => null, 'incomplete' => null];

            // **كاشفٌ مطفأٌ لكثرة الرفض لا يُسأل ولا يحلّ** — ونتائجُه تُخفى معه (AuditorSignals)
            if (AuditorAccuracy::isDisabled($key)) {
                $stats[$key]['skipped'] = 'مطفأ — راجع دقّتَه في مركز الذكاء ← المدقّق';

                continue;
            }
            // **كاشفُ ذكاءٍ غيرُ جاهزٍ لا يُسأل ولا يحلّ شيئاً** — «مطفأ» ليس «زال الشرط»؛
            // **ولا يُسأل في المعاينة**: المعاينةُ لا تكتب، ونداءٌ مدفوعٌ تُرمى نتيجتُه يُدفَع ثانيةً غداً
            if ($d->source() === 'ai' && ($aiWhyNot !== null || $dry)) {
                $stats[$key]['skipped'] = $aiWhyNot ?? 'المعاينةُ لا تسأل النموذج — نداءاتُه مدفوعة';

                continue;
            }

            // **العزلُ لكلِّ كاشفٍ بكامل دورته** — الرصدُ والحفظُ والحلّ: سباقُ مفتاحٍ فريدٍ
            // أو خطأُ حفظٍ في كاشفٍ لا يُسقط الكواشفَ التي بعده
            try {
                $found = $d->detect($auditor);
                $stats[$key]['found'] = count($found);

                $seen = [];
                foreach ($found as $f) {
                    $dk = self::dedupKey($f);
                    $seen[$dk] = true;
                    if (self::store($d, $f, $dk, $dry)) $stats[$key]['opened']++;
                }

                // تغطيةٌ ناقصة ⇒ لا حلَّ إلّا لما أُعيد فحصُه فعلاً ووُجد سليماً
                $complete = $d->complete();
                $cleared = $complete ? [] : array_flip($d instanceof AiDetector ? $d->cleared() : []);
                if (! $complete) $stats[$key]['incomplete'] = $d instanceof AiDetector ? ($d->stopReason() ?? 'بنودٌ لم يُحكَم عليها') : 'ناقصة';

                $stale = AiFinding::query()->where('detector', $key)->where('status', 'open')
                    ->orderBy('id')->get(['id', 'dedup_key'])
                    ->filter(fn ($row) => ! isset($seen[$row->dedup_key])
                        && ($complete || isset($cleared[$row->dedup_key])));
                $stats[$key]['resolved'] = $stale->count();
                if (! $dry && $stale->isNotEmpty()) {
                    AiFinding::query()->whereIn('id', $stale->pluck('id')->all())
                        ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
                }
            } catch (\Throwable $e) {
                report($e);
                $stats[$key]['error'] = class_basename($e);
            }
        }

        AuditorAi::reset();
        self::$runId = null;
        if (! $dry) {
            hub_data_bump('ai_findings');
            try { AuditorAccuracy::review(); } catch (\Throwable $e) { report($e); }
        }

        return $stats;
    }

    /** حفظُ نتيجة — يُعيد `true` إن فُتحت الآن (جديدةً أو بعد حلّ)، وفي المعاينةِ إن كانت ستُفتح */
    private static function store(Detector $d, array $f, string $dk, bool $dry): bool
    {
        $row = AiFinding::query()->where('detector', $d->key())->where('dedup_key', $dk)
            ->orderBy('id')->first();   // المفتاحُ فريدٌ — والترتيبُ صريحٌ مع ذلك (CLAUDE.md)
        $opening = $row === null || $row->status !== 'open';
        if ($dry) return $opening;

        $now = now();
        $attrs = [
            'source' => $d->source(),
            'severity' => in_array($f['severity'] ?? '', ['high', 'medium', 'info'], true) ? $f['severity'] : 'info',
            'subject_module' => $f['subject_module'],
            'subject_id' => $f['subject_id'],
            'subject_user_id' => $f['subject_user_id'] ?? null,
            'company_id' => $f['company_id'] ?? null,
            'evidence' => array_values($f['evidence']),
            'fields' => $f['fields'],
            // **الملخّصُ يمرّ بالمنقّح** — نصُّ التقرير قد يحمل سرّاً لصقه صاحبُه
            'summary' => mb_substr(Redactor::text((string) $f['summary']), 0, 600),
            'suggestion' => isset($f['suggestion']) ? mb_substr(Redactor::text((string) $f['suggestion']), 0, 600) : null,
            'fingerprint' => $f['fingerprint'] ?? hash('sha256', json_encode($f['input'] ?? $f['evidence'], JSON_UNESCAPED_UNICODE)),
            // مسودةُ النموذج تمرّ بالمنقّح كالملخّص — تُعرَض رابطاً وتُعبّأ في نموذج
            'draft' => isset($f['draft']) ? self::redactDraft((array) $f['draft']) : null,
            'status' => 'open',
            'last_seen_at' => $now,
            'resolved_at' => null,
            'usage_event_id' => $f['usage_event_id'] ?? null,
        ];

        if ($row === null) {
            AiFinding::create($attrs + ['detector' => $d->key(), 'dedup_key' => $dk, 'detected_at' => $now]);

            return true;
        }

        if ($opening) $attrs['detected_at'] = $now;
        $row->fill($attrs)->save();

        return $opening;
    }

    private static function redactDraft(array $d): array
    {
        array_walk_recursive($d, function (&$v) {
            if (is_string($v)) $v = mb_substr(Redactor::text($v), 0, 300);
        });

        return $d;
    }

    /** بصمةُ النتيجة المحفوظة لشرطٍ — ليقرّر الكاشفُ الذكيُّ ألّا يدفع لتحليلٍ مكرَّر */
    public static function fingerprintOf(string $detector, array $identity): ?string
    {
        return AiFinding::query()->where('detector', $detector)
            ->where('dedup_key', self::dedupKey(['identity' => $identity]))
            ->orderBy('id')->value('fingerprint');
    }
}

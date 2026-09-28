<?php

namespace App\Support\Ai\FollowUp;

use App\Models\AiCommitment;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\GovernedCompletion;
use App\Support\Platform\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * **استخراجُ الالتزامات من التقارير اليوميّة** — حقلا «جارٍ» و«التالي» وحدَهما (ما يَعِد به الكاتب).
 *
 * تزايديّ بمؤشّرٍ مركّب (`followup.cursor` = created_at|id): كلُّ تقريرٍ يُقرأ مرّةً واحدة، ولا يعود
 * المؤشّرُ أبعدَ من `followup.lookback_days`. النموذجُ يرى رقمَ البند والتاريخَ والنصَّ المنقّح — لا اسمَ
 * ولا معرّفاً — ويُعيد {n, what, due, quote}. والاقتباسُ يجب أن يوجد حرفياً في التقرير، وإلا يُسقَط.
 */
final class CommitmentExtractor
{
    public const CURSOR = 'followup.cursor';

    /** تقاريرُ النداء الواحد */
    public const BATCH = 20;

    /** أقصى تقاريرَ في الجولة — والباقي للجولة التالية */
    public const FETCH = 200;

    /** أقصى التزاماتٍ من التقرير الواحد */
    public const PER_REPORT = 3;

    /** أبعدُ موعدٍ مقبولٍ من النموذج بعد يوم التقرير */
    public const MAX_DUE_DAYS = 14;

    public const SYSTEM = 'أنت تقرأ تقاريرَ عملٍ يوميّة كتبها موظّفون، كلُّ بندٍ برقمه "n" وتاريخه وحقلَي "doing" (ما يعمل عليه) و"next" (ما سيفعله). '
        . 'استخرج **الالتزاماتِ الصريحة** وحدَها: عملٌ محدّدٌ قال الكاتبُ إنّه سيُنجزه («غداً سأنهي ربط بوابة الدفع»، «سأسلّم التصميم الخميس»). '
        . 'تجاهل العامَّ والمبهم («سأكمل العمل»، «متابعة»). ثلاثةُ التزاماتٍ على الأكثر لكلِّ تقرير. '
        . 'الشكل: {"commitments": [{"n": رقمُ التقرير، "what": وصفٌ قصيرٌ للعمل (أقلّ من ١٢٠ حرفاً)، '
        . '"due": "YYYY-MM-DD" إن ذكر الكاتبُ موعداً أو دلّ عليه (غداً = اليوم التالي لتاريخ التقرير) وإلا null، '
        . '"quote": نصٌّ منسوخٌ حرفياً من التقرير يحمل الالتزام}]}. لا التزاماتِ ⇒ {"commitments": []}. الاقتباسُ نسخٌ حرفيٌّ لا صياغة.';

    /**
     * @return array{reports: int, found: int, calls: int, code: ?string}
     */
    public static function run(?GovernedCompletion &$gc, bool $dry = false): array
    {
        $out = ['reports' => 0, 'found' => 0, 'calls' => 0, 'code' => null];
        if (FollowUp::accuracy()['disabled']) return ['code' => 'ACCURACY_DISABLED'] + $out;

        $rows = self::pending();
        $out['reports'] = $rows->count();
        if ($rows->isEmpty() || $dry) return $out;

        foreach ($rows->chunk(self::BATCH) as $chunk) {
            $chunk = $chunk->values();
            $items = [];
            foreach ($chunk as $i => $r) {
                $items[] = array_filter(['n' => $i + 1, 'date' => substr((string) $r->work_date, 0, 10),
                    'doing' => FollowUp::clip($r->doing), 'next' => FollowUp::clip($r->next)], fn ($v) => $v !== '');
            }

            if ($gc === null) {
                $opened = FollowUp::session(40);
                if (is_string($opened)) return ['code' => $opened] + $out;
                $gc = $opened;
            }
            $res = FollowUp::call($gc, self::SYSTEM, ['reports' => $items]);
            $out['calls']++;
            if (! $res['ok']) return ['code' => $res['code']] + $out;   // المؤشّرُ لا يتقدّم فوق ما لم يُقرأ

            $out['found'] += self::store($chunk->all(), (array) ($res['json']['commitments'] ?? []));
            $last = $chunk[$chunk->count() - 1];
            Settings::put(self::CURSOR, $last->created_at . '|' . $last->id, 'followup');
        }

        return $out;
    }

    /** التقاريرُ بعد المؤشّر (ولا أبعدَ من نافذة الرجوع) التي فيها «جارٍ» أو «التالي» */
    public static function pending()
    {
        $floor = now()->subDays(FollowUp::lookbackDays())->toDateTimeString();
        [$at, $id] = array_pad(explode('|', (string) setting(self::CURSOR, ''), 2), 2, '');
        $q = DB::table('work_updates')->whereNull('deleted_at')->whereNotNull('created_by')
            ->where('created_at', '<', now()->toDateTimeString())
            ->where(fn ($w) => $w->whereRaw("TRIM(COALESCE(next, '')) <> ''")->orWhereRaw("TRIM(COALESCE(doing, '')) <> ''"));

        if ($at !== '' && $at > $floor) {
            $q->where(fn ($w) => $w->where('created_at', '>', $at)->orWhere(fn ($x) => $x->where('created_at', $at)->where('id', '>', $id)));
        } else {
            $q->where('created_at', '>=', $floor);
        }

        return $q->orderBy('created_at')->orderBy('id')->limit(self::FETCH)
            ->get(['id', 'created_by', 'project_id', 'task_id', 'work_date', 'doing', 'next', 'created_at']);
    }

    /** @param  list<object>  $rows */
    private static function store(array $rows, array $found): int
    {
        $made = 0;
        $per = [];
        foreach ($found as $c) {
            if (! is_array($c) || ! is_numeric($c['n'] ?? null) || ! is_string($c['quote'] ?? null)) continue;
            $r = $rows[(int) $c['n'] - 1] ?? null;
            if ($r === null) continue;
            if (($per[$r->id] = ($per[$r->id] ?? 0) + 1) > self::PER_REPORT) continue;
            if (! FollowUp::quoted($c['quote'], (string) $r->doing, (string) $r->next)) continue;

            $what = AuditorAi::str($c['what'] ?? $c['quote'], 190);
            if ($what === '') continue;
            $said = Carbon::parse((string) $r->work_date)->startOfDay();
            $due = self::due($c['due'] ?? null, $said);

            $key = sha1($r->created_by . '|' . $r->id . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $what)));
            if (AiCommitment::query()->where('dedupe', $key)->exists()) continue;

            AiCommitment::create([
                'user_id' => (string) $r->created_by, 'what' => $what, 'quote' => AuditorAi::str($c['quote'], 400),
                'source_module' => 'updates', 'source_id' => (string) $r->id,
                'project_id' => $r->project_id ? (string) $r->project_id : null, 'task_id' => $r->task_id ? (string) $r->task_id : null,
                'said_on' => $said->toDateString(), 'due_on' => $due, 'status' => 'open', 'dedupe' => $key,
            ]);
            $made++;
        }

        return $made;
    }

    /** موعدُ النموذج إن صلح (من يوم التقرير إلى أسبوعين) — وإلا اليومُ التالي للتقرير */
    private static function due(mixed $v, Carbon $said): string
    {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            try {
                $d = Carbon::parse($v)->startOfDay();
                if ($d->gte($said) && $d->lte($said->copy()->addDays(self::MAX_DUE_DAYS))) return $d->toDateString();
            } catch (\Throwable) {}
        }

        return $said->copy()->addDay()->toDateString();
    }
}

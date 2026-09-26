<?php

namespace App\Support\Ai\Auditor;

use App\Models\AiFinding;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **دقّةُ المدقّق — ومفتاحُ إطفائه الذاتيّ** (§٣.٥ · A4).
 *
 * مدقّقٌ يُكثر الإنذارَ الكاذب يُعلّم المديرين تجاهلَه — ثمّ يتجاهلون صادقَه. فالدقّةُ تُقاس
 * بما يفعله المديرون بإشاراته فعلاً (`signal_states` القائم — لا مخزنَ ثانٍ): **رفضٌ** يعني
 * «ليس صحيحاً أو لا يعنيني»، و**إقرارٌ** أو **تأجيلٌ** يعني «صحيحٌ وأتابعه».
 *
 * وكاشفٌ نسبةُ رفضِ إشاراته `MAX_DISMISS_RATE` فأكثر على `MIN_DISPOSITIONS` تصرّفاً فأكثر خلال
 * النافذة **يُطفأ آليّاً** ويُبلَّغ المالك — ولا يُحذف شيء: نتائجُه القائمة تُخفى معه وتعود إن
 * أُعيد تشغيلُه من شاشة المدقّق.
 */
final class AuditorAccuracy
{
    public const WINDOW_DAYS = 60;

    public const MIN_DISPOSITIONS = 30;

    public const MAX_DISMISS_RATE = 0.6;

    /** @return list<string> مفاتيحُ الكواشف المطفأة */
    public static function disabled(): array
    {
        $raw = (string) setting('auditor.disabled', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($k) => $k !== ''));
    }

    public static function isDisabled(string $key): bool
    {
        return in_array($key, self::disabled(), true);
    }

    public static function setDisabled(string $key, bool $off, string $source, ?string $reason = null): void
    {
        if (Auditor::detector($key) === null) return;
        $list = self::disabled();
        $list = $off ? array_values(array_unique([...$list, $key])) : array_values(array_diff($list, [$key]));
        sort($list);
        Settings::put('auditor.disabled', implode(',', $list), $source, $reason);
    }

    /**
     * **لوحةُ الدقّة** — أعدادٌ لا محتوى: لا ملخّصَ نتيجةٍ ولا اسمَ موظّف.
     *
     * @return list<array{key: string, label: string, source: string, disabled: bool, open: int,
     *                    resolved: int, ack: int, snoozed: int, dismissed: int, rate: ?float}>
     */
    public static function stats(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $has = Schema::hasTable('ai_findings');
        $out = [];

        foreach (Auditor::detectors() as $d) {
            $key = $d->key();
            $row = ['key' => $key, 'label' => $d->label(), 'source' => $d->source(),
                    'disabled' => self::isDisabled($key), 'open' => 0, 'resolved' => 0,
                    'ack' => 0, 'snoozed' => 0, 'dismissed' => 0, 'rate' => null];
            if ($has) {
                $row['open'] = AiFinding::query()->where('detector', $key)->where('status', 'open')->count();
                $row['resolved'] = AiFinding::query()->where('detector', $key)->where('status', 'resolved')
                    ->where('resolved_at', '>=', $since)->count();
            }
            if (Schema::hasTable('signal_states')) {
                $states = DB::table('signal_states')->where('skey', 'like', 'audit:' . $key . ':%')
                    ->where('at', '>=', $since)
                    ->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
                $row['ack'] = (int) ($states['ack'] ?? 0);
                $row['snoozed'] = (int) ($states['snoozed'] ?? 0);
                $row['dismissed'] = (int) ($states['dismissed'] ?? 0);
            }
            $total = $row['ack'] + $row['snoozed'] + $row['dismissed'];
            $row['rate'] = $total > 0 ? round($row['dismissed'] / $total, 3) : null;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * **مراجعةُ الدقّة بعد كلِّ جولة** — يُطفئ ما تجاوز العتبة ويُبلّغ المالكين مرّةً واحدة.
     *
     * @return list<string> ما أُطفئ الآن
     */
    public static function review(bool $dry = false): array
    {
        $off = [];
        foreach (self::stats() as $s) {
            if ($s['disabled']) continue;
            $total = $s['ack'] + $s['snoozed'] + $s['dismissed'];
            if ($total < self::MIN_DISPOSITIONS || ($s['rate'] ?? 0) < self::MAX_DISMISS_RATE) continue;

            $off[] = $s['key'];
            if ($dry) continue;

            // `Settings::put` يدقّق التغييرَ بسببه — فلا أثرَ ثانٍ ولا فعلَ تدقيقٍ جديد
            self::setDisabled($s['key'], true, 'auditor', 'نسبةُ الرفض ' . round($s['rate'] * 100) . '٪ على ' . $total . ' تصرّفاً');
            $owners = User::query()->whereNull('deleted_at')->where('status', 'نشط')
                ->whereHas('role', fn ($q) => $q->where('is_owner', true))->orderBy('id')->pluck('id');
            foreach ($owners as $uid) {
                hub_notify($uid, 'auditor-off:' . $s['key'],
                    '🔎 أُطفئ كاشفُ «' . $s['label'] . '» في المدقّق: رفض المديرون ' . round($s['rate'] * 100)
                    . '٪ من إشاراته (' . $total . ' تصرّفاً في ' . self::WINDOW_DAYS . ' يوماً). راجعه في مركز الذكاء ← المدقّق.');
            }
        }

        return $off;
    }
}

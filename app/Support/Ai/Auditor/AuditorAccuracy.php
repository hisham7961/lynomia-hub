<?php

namespace App\Support\Ai\Auditor;

use App\Models\AiFinding;
use App\Models\User;
use App\Support\Platform\Settings;
use Illuminate\Support\Carbon;
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

    /** أقلُّ عددِ رافضين مختلفين قبل الإطفاء الآليّ */
    public const MIN_ACTORS = 2;

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

        // **إعادةُ التشغيل تبدأ عدّاً جديداً** — وإلّا أطفأته الجولةُ التالية بالرفضِ القديمِ نفسِه
        // ولم يستطع المالكُ نقضَ الإطفاءِ الآليّ ستّين يوماً
        if (! $off) {
            $map = self::reenabled();
            $map[$key] = now()->toIso8601String();
            ksort($map);
            Settings::put('auditor.reenabled', json_encode($map, JSON_UNESCAPED_SLASHES), $source, $reason);
        }
    }

    /** متى أُعيد تشغيلُ كلِّ كاشفٍ آخرَ مرّة @return array<string, string> */
    public static function reenabled(): array
    {
        $v = json_decode((string) setting('auditor.reenabled', ''), true);

        return is_array($v) ? array_filter($v, 'is_string') : [];
    }

    /**
     * **لوحةُ الدقّة** — أعدادٌ لا محتوى: لا ملخّصَ نتيجةٍ ولا اسمَ موظّف.
     *
     * بلا مشاهدٍ ⇒ على المنشأة كلِّها (للإطفاء الآليّ — العتبةُ عامّة). وبمشاهدٍ له قائمةُ شركاتٍ ⇒
     * أعدادُ شركاته وحدَها (نشاطُ غيرِه ليس له — نمطُ شاشةِ الحوكمة).
     *
     * @return list<array{key: string, label: string, source: string, disabled: bool, open: int,
     *                    resolved: int, ack: int, snoozed: int, dismissed: int, actors: int, rate: ?float}>
     */
    public static function stats(?User $viewer = null): array
    {
        $floor = now()->subDays(self::WINDOW_DAYS);
        $cids = $viewer !== null ? hub_company_ids($viewer) : null;
        $has = Schema::hasTable('ai_findings');
        $reenabled = self::reenabled();

        // التصرّفاتُ مرّةً واحدة — والبادئةُ تُطابَق في PHP: «_» في LIKE حرفٌ بديلٌ لا شرطةٌ سفليّة
        $states = collect();
        if (Schema::hasTable('signal_states')) {
            $q = DB::table('signal_states')->where('skey', 'like', 'audit:%')->where('at', '>=', $floor);
            if ($cids !== null) $q->whereIn('company_id', $cids);
            $states = $q->orderBy('id')->get(['skey', 'state', 'by', 'at']);
        }

        $out = [];
        foreach (Auditor::detectors() as $d) {
            $key = $d->key();
            // بعد إعادة التشغيل: ما وقع **بعدها** حصراً — التصرّفُ في لحظتها نفسِها سابقٌ لها
            $reset = isset($reenabled[$key]) && Carbon::parse($reenabled[$key])->gt($floor) ? Carbon::parse($reenabled[$key]) : null;
            $row = ['key' => $key, 'label' => $d->label(), 'source' => $d->source(),
                    'disabled' => self::isDisabled($key), 'open' => 0, 'resolved' => 0,
                    'ack' => 0, 'snoozed' => 0, 'dismissed' => 0, 'actors' => 0, 'rate' => null];
            if ($has) {
                $open = AiFinding::query()->where('detector', $key)->where('status', 'open');
                $resolved = AiFinding::query()->where('detector', $key)->where('status', 'resolved')->where('resolved_at', '>=', $floor);
                if ($cids !== null) {
                    $open->whereIn('company_id', $cids);
                    $resolved->whereIn('company_id', $cids);
                }
                $row['open'] = $open->count();
                $row['resolved'] = $resolved->count();
            }
            $mine = $states->filter(fn ($st) => str_starts_with((string) $st->skey, 'audit:' . $key . ':')
                && ($reset === null || Carbon::parse($st->at)->gt($reset)));
            foreach (['ack', 'snoozed', 'dismissed'] as $state) $row[$state] = $mine->where('state', $state)->count();
            $row['actors'] = $mine->where('state', 'dismissed')->pluck('by')->filter()->unique()->count();
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
            // **ولا يُطفئه رأيُ شخصٍ واحد** — الرفضُ من اثنين مختلفين على الأقلّ
            if ($s['actors'] < self::MIN_ACTORS) continue;

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

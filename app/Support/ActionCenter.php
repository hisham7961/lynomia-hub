<?php

namespace App\Support;

use App\Models\SignalState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **مركزُ الفعل الموحّد** — الجسرُ المجدولُ من الإشارة إلى الفعل الذي سمّاه التدقيقُ
 * الفجوةَ الوحيدة. **لا محرّكٌ ثانٍ**: يُركّب منتِجاتِ الإشارات القائمة —
 * `hub_recommendations()` (إشاراتٌ محسوبةٌ عبر الوحدات، منطَّقةٌ ومخبّأة) و`Inbox`
 * (ما ينتظر تصرّفي، ١٨ مصدراً بنطاقٍ وشارة) — في صفٍّ واحدٍ مرتَّبٍ بالأولوية،
 * ثم يُسقِط عليه **تصرّفَ المستخدم** (إقرار/تأجيل/رفض) من `signal_states`.
 *
 * العزلُ موروثٌ لا مخترَق: كلُّ منتِجٍ يقرأ بـ`hub_scope`/`hub_can` أصلاً، فمن لا
 * يرى سجلاً لا تصله إشارتُه. الحلُّ تلقائيٌّ: إشارةٌ زال شرطُها لا تُبثّ فتختفي.
 */
class ActionCenter
{
    /** ترتيبُ الشدّة — الأشدّ أولاً */
    public const RANK = ['حرج' => 3, 'مهم' => 2, 'اطّلاع' => 1];

    /**
     * صفُّ الفعل الكامل للمستخدم الحاليّ: الإشاراتُ المحسوبة (قابلةُ التصرّف) مجمَّعةً
     * بالكيان ومرتَّبةً، وعدّادات، وعددُ المؤجَّل، وملخّصُ «ما ينتظرني» من الصندوق.
     */
    public static function feed(bool $fresh = false, ?string $projectId = null): array
    {
        $signals = self::signals($fresh, $projectId);

        // تجميعٌ بالكيان (§٣٧): بدل ١٢ إشعاراً لمشروعٍ واحد → مجموعةٌ واحدة تُفصَّل
        $groups = [];
        foreach ($signals['visible'] as $s) {
            $gk = ($s['module'] ?? '') . ':' . ($s['record_id'] ?? $s['key'] ?? $s['title']);
            $groups[$gk] ??= ['module' => $s['module'] ?? null, 'record_id' => $s['record_id'] ?? null,
                'top' => $s['sev'], 'items' => []];
            $groups[$gk]['items'][] = $s;
            if ((self::RANK[$s['sev']] ?? 0) > (self::RANK[$groups[$gk]['top']] ?? 0)) $groups[$gk]['top'] = $s['sev'];
        }
        usort($groups, fn ($a, $b) => (self::RANK[$b['top']] ?? 0) <=> (self::RANK[$a['top']] ?? 0));

        return [
            'signals'  => $signals['visible'],
            'hidden'   => $signals['hidden'],
            'groups'   => array_values($groups),
            'counts'   => $signals['counts'],
            'snoozed'  => $signals['snoozed'],
            'dismissed' => $signals['dismissed'],
            // «ما ينتظرني» يبقى من الصندوق القائم — لا يُعاد تجميعُه
            'awaiting' => self::awaiting(),
        ];
    }

    /**
     * الإشاراتُ المحسوبةُ بعد إسقاط التصرّف. تُرجع المرئيَّ + المخفيَّ (مؤجَّل/مرفوض)
     * منفصلين، والعدّادات. تُحدَّث `last_seen_at` للمفاتيح الحيّة فقط (لتشذيب اليتيم).
     */
    public static function signals(bool $fresh = false, ?string $projectId = null): array
    {
        $items = [];
        try {
            $items = hub_recommendations($fresh, $projectId)['items'] ?? [];
        } catch (\Throwable $e) {
            report($e);
        }

        // مفاتيحُ حيّة: تُبنى لكل إشارةٍ تحمل مفتاحاً (القابلةُ للتصرّف)
        $keys = array_values(array_filter(array_map(fn ($s) => $s['key'] ?? null, $items)));
        $states = (! empty($keys) && Schema::hasTable('signal_states'))
            ? SignalState::whereIn('skey', $keys)->get()->keyBy('skey')
            : collect();

        // ختمُ الرؤية للمفاتيح الحيّة — دفعةً واحدة، على الصفوف القائمة فقط
        if (! empty($keys) && Schema::hasTable('signal_states')) {
            try { SignalState::whereIn('skey', $keys)->update(['last_seen_at' => now()]); } catch (\Throwable $e) {}
        }

        $visible = []; $hidden = []; $snoozed = 0; $dismissed = 0;
        foreach ($items as $s) {
            $k = $s['key'] ?? null;
            $st = $k ? ($states[$k] ?? null) : null;
            $s['state'] = $st?->state ?? 'open';
            $s['snoozed_until'] = $st?->snoozed_until ? substr((string) $st->snoozed_until, 0, 10) : null;
            $s['can_act'] = (bool) $k;   // بلا مفتاحٍ لا تصرّف (توصيةٌ عامّة)
            // الحرجُ لا يُرفَض رفضاً دائماً — يبقى الزرُّ للتأجيل/الإقرار لا الإخفاء
            $s['can_dismiss'] = $s['can_act'] && ($s['sev'] ?? '') !== 'حرج';
            if ($st && $st->state === 'dismissed') { $dismissed++; $hidden[] = $s; continue; }
            if ($st && $st->state === 'snoozed' && $st->snoozed_until && $st->snoozed_until->isFuture()) { $snoozed++; $hidden[] = $s; continue; }
            $visible[] = $s;
        }

        return [
            'visible' => $visible,
            'hidden'  => $hidden,   // المؤجَّلُ والمرفوض — لعرضِ «أظهرها» (إعادةُ فتح)
            'counts'  => [
                'حرج'    => count(array_filter($visible, fn ($r) => $r['sev'] === 'حرج')),
                'مهم'    => count(array_filter($visible, fn ($r) => $r['sev'] === 'مهم')),
                'اطّلاع' => count(array_filter($visible, fn ($r) => $r['sev'] === 'اطّلاع')),
            ],
            'snoozed' => $snoozed,
            'dismissed' => $dismissed,
        ];
    }

    /**
     * الإشاراتُ الحيّةُ للمستخدم مفهرسةً بمفتاحها — يُحرَس بها التصرّف (لا تصرّفَ على
     * إشارةٍ لا يراها)، ومنها يُؤخَذ module/record_id الموثوقان (لا تخمينٌ من المفتاح).
     */
    public static function liveByKey(bool $fresh = false, ?string $projectId = null): array
    {
        $items = [];
        try { $items = hub_recommendations($fresh, $projectId)['items'] ?? []; } catch (\Throwable $e) {}

        $out = [];
        foreach ($items as $s) {
            if (! empty($s['key'])) $out[$s['key']] = $s;
        }

        return $out;
    }

    /** مجموعةُ المفاتيح الحيّة فقط */
    public static function liveKeys(bool $fresh = false): array
    {
        return array_keys(self::liveByKey($fresh));
    }

    /** ملخّصُ «ما ينتظرني» من الصندوق القائم — عددٌ ومقدّمة، بلا إعادة تجميع */
    protected static function awaiting(): array
    {
        try {
            $items = Inbox::items();

            return ['count' => Inbox::count(), 'items' => array_slice($items, 0, 12)];
        } catch (\Throwable $e) {
            return ['count' => 0, 'items' => []];
        }
    }

    /**
     * تسجيلُ تصرّفٍ على إشارة — بعد التحقّق أنّها **حيّةٌ في صفّ المستخدم نفسِه**
     * (فلا يُتصرَّف على إشارةِ عميلٍ خارج النطاق). يُدمَج بمفتاح `skey` فلا يتكرّر.
     *
     * @param  string  $do  ack | snooze | dismiss | reopen
     * @param  ?string  $projectId  عدسةُ المشروع النشطة — **لازمةٌ** ليُعاد بناءُ الصفّ
     *   نفسِه الذي رآه المستخدم: تحت `?p=PID` يعرض الصفُّ إشاراتٍ منطَّقةً بالمشروع قد
     *   تغيب عن الصفّ العامّ (سادسُ أقدمِ مستحقٍّ للمشروع ليس سادسَ أقدمِ المنشأة). بلا
     *   تمريرها كان الحارسُ يبني الصفَّ العامّ فيرفض إشارةً ظاهرةً في صفّ العدسة.
     */
    public static function disposition(string $skey, string $do, ?string $until = null, ?string $note = null, ?string $projectId = null): bool
    {
        // **خبيئةُ الصفّ نفسِه** (لا `fresh=true`): يكفي التحقّقُ من أنّ المفتاح في صفِّ
        // المستخدم كما رآه — لا إعادةُ بناءٍ قسريّةٌ لكلّ المحرّكات عند كلّ نقرة.
        // والعدسةُ تُمرَّر فيُطابَق الصفُّ المعروض تماماً (لا الصفَّ العامّ).
        $live = self::liveByKey(false, $projectId);
        if (! isset($live[$skey])) return false;   // ليست في صفّه → تُرفَض بصمت

        // module/record_id/sev من الإشارة نفسها (موثوقٌ) لا من تخمينِ المفتاح
        $sig = $live[$skey];
        $module = $sig['module'] ?? null;
        $recordId = $sig['record_id'] ?? null;
        $u = auth()->user();

        if ($do === 'reopen') {
            SignalState::where('skey', $skey)->delete();
            hub_audit('إعادةُ فتح إشارة', 'signals', $recordId, $skey);

            return true;
        }

        $state = match ($do) { 'ack' => 'ack', 'snooze' => 'snoozed', 'dismiss' => 'dismissed', default => null };
        if ($state === null) return false;

        // **لا إخفاءَ دائمٌ لإشارةٍ حرجة**: تُقَرّ أو تُؤجَّل تأجيلاً مؤقتاً فقط — فلا
        // يُسكَت خطرٌ حرجٌ متكرّرٌ إلى الأبد (كان الرفضُ الدائمُ يُخفي حرجاً عائداً).
        if ($state === 'dismissed' && ($sig['sev'] ?? '') === 'حرج') return false;

        $data = [
            'module' => $module, 'record_id' => $recordId,
            'state' => $state, 'by' => $u?->id, 'at' => now(), 'last_seen_at' => now(),
            'company_id' => $u?->company_id,
            'note' => $note ? mb_substr($note, 0, 300) : null,
            'snoozed_until' => $state === 'snoozed'
                ? \Illuminate\Support\Carbon::parse($until ?: now()->addDay()->toDateString())->endOfDay()
                : null,
        ];

        try {
            SignalState::updateOrCreate(['skey' => $skey], $data);
        } catch (\Illuminate\Database\QueryException $e) {
            // سباقُ نقرتين على المفتاح الفريد نفسه: الأولى كتبت الحالةَ المقصودة،
            // فالثانيةُ «نجاحٌ» لا ٥٠٠ (updateOrCreate ليست ذرّيّةً على unique).
            if ((string) $e->getCode() !== '23000') throw $e;
            SignalState::where('skey', $skey)->update($data);
        }
        hub_audit('تصرّفٌ بإشارة: ' . $do, 'signals', $recordId, $skey);

        return true;
    }

    /**
     * تشذيبُ التصرّفات اليتيمة: `last_seen_at` يُختَم كلّما رُئيت الإشارةُ حيّةً (عند
     * عرضِ الصفّ أو التصرّف). فصفٌّ لم يُرَ حيّاً منذ مدّةٍ طويلة = إشارتُه حُلَّت
     * وزالت فلا معنى لحالته. النافذةُ **طويلةٌ عمداً** (تشذيبُ اليتيم لا إسقاطُ
     * الحيّ): تأجيلٌ أو رفضٌ لا يُنظَر إليه أشهراً يبقى محترَماً حتى تتجاوز النافذة.
     */
    public static function prune(int $days = 120): int
    {
        if (! Schema::hasTable('signal_states')) return 0;

        return SignalState::where('last_seen_at', '<', now()->subDays($days))->delete();
    }
}

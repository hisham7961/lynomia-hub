<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **قفلُ التحريرِ اللّيّن** (بندُ الدَّين #5 · DI-09): «شخصٌ آخر يحرّر هذا السجلَّ الآن».
 *
 * صفٌّ واحدٌ لكلِّ `(module, record_id)` (مفتاحٌ فريدٌ في المخطَّط): فتحُ نموذجِ التعديل
 * يأخذ القفلَ أو يجدّده لعشرِ دقائق؛ ومحرِّرٌ ثانٍ يرى شريطَ تنبيهٍ باسمِ الحامل —
 * **تنبيهٌ لا منع**: القفلُ التفاؤليّ (`_version` في `ModuleController::update`) هو ما
 * يمنع ضياعَ التعديل، وهذا يُخبر قبل أن يبدأ الثاني. يُحرَّر عند الحفظ أو الإلغاء أو الحذف،
 * والمنتهي كأنّه غيرُ موجود — يأخذه أوّلُ فاتح.
 */
class RecordLock extends Model
{
    use HasUuid;

    /** عمرُ القفل بالدقائق — يتجدّد مع كلِّ فتحٍ لنموذجِ التعديل */
    public const TTL_MINUTES = 10;

    protected $table = 'record_locks';
    protected $guarded = [];
    public $timestamps = true;
    protected $casts = ['before' => 'array', 'after' => 'array', 'snapshot' => 'array', 'value' => 'array', 'flags' => 'array',
        'expires_at' => 'datetime'];

    /**
     * يأخذ القفلَ لـ`$user` أو يجدّده. يُعيد **قفلَ غيرِه** إن كان سارياً (فلا يُنتزَع —
     * الأوّلُ يبقى صاحبَه والثاني يُنبَّه)، وإلّا `null` بعد أخذِه.
     */
    public static function acquire(string $module, string $recordId, User $user, ?string $device = null): ?self
    {
        $now = now();
        $held = static::query()->where('module', $module)->where('record_id', $recordId)->orderBy('id')->first();

        if ($held && (string) $held->user_id !== (string) $user->id && $held->expires_at?->isAfter($now)) {
            return $held;
        }

        $vals = ['user_id' => (string) $user->id, 'device' => $device === null ? null : mb_substr($device, 0, 200),
            'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES)];

        // تجديدُ قفلِك الساري يمدّ عمرَه ولا يمسّ «منذ» (`created_at` = بدءُ جلسةِ التحرير)
        if ($held && (string) $held->user_id === (string) $user->id && $held->expires_at?->isAfter($now)) {
            $held->forceFill($vals)->save();

            return null;
        }

        if ($held) {
            // أخذُ المنتهي مشروطٌ بحالِه المقروءة — فاتحان معاً على قفلٍ منتهٍ: يفوز أحدُهما فقط
            $won = static::query()->whereKey($held->id)->where('user_id', $held->user_id)
                ->where('expires_at', $held->getRawOriginal('expires_at'))->update($vals + ['created_at' => $now, 'updated_at' => $now]);

            return $won ? null : static::activeOther($module, $recordId, $user);
        }

        try {
            static::create(['module' => $module, 'record_id' => $recordId] + $vals);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return static::activeOther($module, $recordId, $user);    // سبقه غيرُه في اللحظةِ نفسِها
        }

        return null;
    }

    /** قفلُ **غيرِ** `$user` الساري على السجلّ، أو `null` */
    public static function activeOther(string $module, string $recordId, User $user): ?self
    {
        return static::query()->where('module', $module)->where('record_id', $recordId)
            ->where('user_id', '!=', (string) $user->id)->where('expires_at', '>', now())
            ->orderBy('id')->first();
    }

    /** يُحرِّر قفلَ `$user` وحدَه — لا يمسّ قفلَ غيرِه */
    public static function release(string $module, string $recordId, User $user): void
    {
        static::query()->where('module', $module)->where('record_id', $recordId)
            ->where('user_id', (string) $user->id)->delete();
    }

    /**
     * نصُّ الشريط للناظر: «يحرّره الآن فلان منذ …». الاسمُ لمن يقع الحاملُ في نطاقِه
     * (`hub_scope` على `users`) **ويشاركه شركةً** إن كان كلاهما معزولاً على شركات؛
     * وإلّا «مستخدمٌ آخر» — فلا يتسرّب اسمٌ من شركةٍ لا يراها الناظر. وحسابُ العميل
     * لا يرى أسماءَ الفريق هنا أصلاً.
     */
    public function warningFor(User $viewer): string
    {
        $name = null;
        $holder = hub_is_client($viewer) ? null
            : hub_scope(User::query(), 'users', $viewer)->whereKey((string) $this->user_id)->first();
        if ($holder) {
            $vc = hub_company_ids($viewer);
            $hc = hub_company_ids($holder);
            if ($vc === null || $hc === null || array_intersect($vc, $hc)) $name = trim((string) $holder->name);
        }
        $who = ($name !== null && $name !== '') ? $name : 'مستخدمٌ آخر';
        $n = $this->created_at ? (int) floor($this->created_at->diffInMinutes(now(), true)) : 0;
        $since = match (true) {
            $n < 1 => 'لحظات', $n === 1 => 'دقيقة', $n === 2 => 'دقيقتين',
            $n <= 10 => $n . ' دقائق', default => $n . ' دقيقة',
        };

        return 'يحرّره الآن ' . $who . ' منذ ' . $since
            . ' — يمكنك المتابعة، لكن إن حفظ قبلك فسيُطلب منك إعادةُ فتحِ السجلّ ومراجعةُ تغييرك.';
    }
}

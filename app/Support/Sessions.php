<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * إنهاءُ الجلسات — **سكّةٌ واحدة** بدل أربع نسخٍ من الاستعلام نفسِه (مركز الأمان،
 * أمني الذاتي، تغيير الكلمة، وإعادة ضبطها من الإدارة). تُوسَم صفوفُ `sessions_log`
 * منتهيةً فيطردها `SessionSentry` مع الطلب التالي، ويُدوَّر رمزُ «تذكّرني» فتموت
 * كعكاتُه على كل الأجهزة.
 *
 * (WP-4.4) كلُّ إبطالٍ يختم أثرَه: `revoked_at/by/reason` — فالمحقّق يفرّق
 * الخروجَ الطوعيّ (وسمُ `revoked` وحدَه من logout) عن الإنهاء الإداريّ/الذاتيّ.
 * الأعمدةُ محروسةٌ بـ`hub_has_col` فنشرُ الكود قبل الهجرة يُنقص الختمَ لا الفعل.
 */
final class Sessions
{
    /**
     * بعد هذه الدقائق تُعدّ الجلسةُ منتهيةً لا نشطة — الثابتُ الواحد (WP-4.4):
     * كان مبثوثاً بأربع نسخٍ (مركز الأمان، أمني الذاتي، خريطة الانكشاف، شاشة
     * المستخدمين) فتغييرُ عتبةٍ واحدة كان يترك ثلاثاً على القديم.
     */
    public const LIVE_MIN = 30;

    /**
     * إنهاءُ كل جلسات مستخدم (عدا جلسةٍ بعينها إن أُعطيت) + تدويرُ «تذكّرني».
     *
     * @return int عددُ الجلسات التي أُنهيت
     */
    public static function revokeAll(User $user, ?string $exceptSessionId = null, ?string $reason = null): int
    {
        $n = 0;
        try {
            $q = DB::table('sessions_log')->where('user_id', $user->id)->where('revoked', false);
            if ($exceptSessionId) $q->where('id', '!=', $exceptSessionId);
            $n = (int) $q->update(self::revocationStamp($reason));
        } catch (\Throwable $e) {
            // جدولٌ غائب قبل الهجرة — لا نكسر الفعل الأصليّ
        }
        self::cycleRemember($user);

        return $n;
    }

    /**
     * إنهاءُ جلسةٍ واحدة بعينها لمستخدمها + تدويرُ «تذكّرني» — نفسُ السكّة
     * (الكعكةُ عمرُها ٤٠٠ يوم: بلا تدويرٍ تُبعث الجلسةُ «المنتهية» من جديد).
     */
    public static function revokeOne(User $user, string $sessionId, ?string $reason = null): bool
    {
        $ok = false;
        try {
            $ok = (bool) DB::table('sessions_log')->where('user_id', $user->id)
                ->where('id', $sessionId)->where('revoked', false)
                ->update(self::revocationStamp($reason));
        } catch (\Throwable $e) {
        }
        self::cycleRemember($user);

        return $ok;
    }

    /**
     * ختمُ الإبطال الموحَّد: `revoked` دائماً، والأثرُ (`revoked_at/by/reason`)
     * حين تكون أعمدتُه مهاجَرة. عامٌّ ليستعمله مسارُ إبطال الجهاز (جلساتُ جهازٍ
     * بعينه) بنفس الختم — لا نسخةَ ثالثة من الحقول.
     */
    public static function revocationStamp(?string $reason = null): array
    {
        $row = ['revoked' => true];
        if (hub_has_col('sessions_log', 'revoked_at')) {
            $row['revoked_at'] = now();
            $row['revoked_by'] = auth()->id();
            // بعرض العمود الصريح (120) — mb_substr عند الكاتب لا قصٌّ صامت في القاعدة
            $row['revoke_reason'] = $reason !== null ? mb_substr($reason, 0, 120) : null;
        }

        return $row;
    }

    /** تدويرُ «تذكّرني» — فشلُه لا يُفشل الإبطالَ نفسَه */
    protected static function cycleRemember(User $user): void
    {
        try {
            $user->setRememberToken(Str::random(60));
            $user->saveQuietly();
        } catch (\Throwable $e) {
        }
    }
}

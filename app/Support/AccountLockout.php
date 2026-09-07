<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * **قفلُ الحساب بعد المحاولات الفاشلة** — محرّكٌ واحدٌ يتقاسمه كلُّ سطحِ مصادقة.
 *
 * كان منطقُ العدّ والقفل يعيش في `AuthController::bumpFailedAttempts` (الويب
 * وحده). سطحُ الجوال الأصيل (Mobile Readiness · الطور B) يفرض القفلَ نفسَه —
 * «لا دخولَ موازٍ أضعف» (spec §Auth · Critic F4): فبدلَ نسخِ المنطق (محرّكٌ ثانٍ
 * يتباعد) استُخرج هنا، ويستدعيه الويبُ والجوالُ معاً. سلوكُه byte-for-byte كما
 * كان — واختبارُ الانحدار `CheckThenWriteRound8Test` يُثبّته عبر
 * `AuthController::bumpFailedAttempts` الذي صار يفوّض إلى هنا.
 *
 * الزيادةُ **ذرّيّةٌ** على مستوى القاعدة (`increment` لا قراءةٌ-ثم-كتابة) فلا
 * تُدهَس زيادةٌ تحت التزامن، والقفلُ يُكتب بشرطِ `>= max` فلا يُصفَّر العدّادُ
 * مرّتين متسابقتين، وقفلُ الحساب يفتح حادثةً أمنيّةً للتحقيق البشريّ.
 */
class AccountLockout
{
    /**
     * يزيد عدّادَ المحاولات الفاشلة ذرّيّاً ثم يقفل الحساب عند بلوغ السقف.
     *
     * المنطقُ منقولٌ حرفيّاً من `AuthController::bumpFailedAttempts` (v2.x) — إعادةُ
     * استعمالٍ لا فرعٌ ثانٍ: القفلُ على الجوال هو القفلُ على الويب نفسُه.
     */
    public static function bump(User $u): void
    {
        $max = max(1, (int) setting('auth.max_fail', 5));
        $min = max(1, (int) setting('auth.lock_min', 15));
        $t   = DB::table('users')->where('id', $u->id);

        $t->increment('failed_attempts');
        $n = (int) DB::table('users')->where('id', $u->id)->value('failed_attempts');
        if ($n >= $max) {
            // شرطُ `>= max` يمنع صفرَ العدّاد مرّتين متسابقتين: أوّلُ من يبلغ السقف
            // يقفل ويُصفّر، والثاني لا يجد ما يصفّره فلا يُمدّد القفلَ بلا داعٍ.
            $locked = $t->where('failed_attempts', '>=', $max)
                ->update(['locked_until' => now()->addMinutes($min), 'failed_attempts' => 0]);
            // قفلُ حسابٍ حدثٌ أمنيّ يستحق **حالةً تُحقَّق** لا سطرَ تدقيقٍ يمرّ:
            // يُفتح (أو يُثرى) حادثةٌ أمنيّة — للتحقيق البشريّ لا للعقاب الآليّ.
            if ($locked) {
                hub_security_incident('قفلُ حسابٍ بعد محاولاتٍ فاشلة: ' . $u->name, 'عالي', [
                    'user_id' => $u->id, 'ip' => request()?->ip(), 'threshold' => $max,
                ]);
            }
        }
    }
}

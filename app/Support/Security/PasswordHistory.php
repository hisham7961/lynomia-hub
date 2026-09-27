<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * **سجلُّ كلمات المرور السابقة** (بندُ الدَّين #15 · AUTH-08).
 *
 * كان المستخدمُ يغيّر كلمتَه إلى الكلمة نفسِها — أو يعود إلى كلمةٍ مسرَّبةٍ قبل
 * تغييرين — فيبدو التغييرُ حدثاً أمنيّاً ولم يتغيّر شيء. الآن:
 *
 *  · **يُدوَّن** تجزيءُ كلِّ كلمةٍ تُكتب على `users.password` — من أيِّ مسار (ملفّي،
 *    التغييرُ الإجباريّ، إعادةُ الضبط من الإدارة، التفعيل، الاستعادةُ الذاتيّة) — لأنّ
 *    التدوينَ في حدثِ النموذج (`User::saved`) لا في كلِّ متحكّمٍ على حدة.
 *    **التجزيءُ وحدَه يُخزَّن**: هو نفسُه ما في `users.password`، لا نصَّ صريحاً أبداً.
 *  · **يُرفَض** إعادةُ استعمال إحدى آخر N كلمات (بما فيها الحاليّة) حيث يختار
 *    المستخدمُ كلمتَه بنفسه: `rule()` قاعدةُ تحقّقٍ تُضاف بجوار `password_rules()`.
 *  · **يُقَصّ** ما زاد عن N لكلِّ مستخدم عند كلِّ تدوين — فلا يتراكم الجدول.
 *
 * N من الإعداد `auth.pw_history` (افتراضُه 5، و0 يُطفئ الفحصَ والتدوين معاً).
 * سقفٌ صلبٌ ٢٤: كلُّ فحصٍ يكلّف مقارنةَ bcrypt لكلِّ صفّ، فلا يُترك بلا حدّ.
 */
final class PasswordHistory
{
    public const TABLE = 'password_histories';

    /** سقفُ العمق — كلفةُ الفحص خطّيّةٌ فيه (مقارنةُ تجزيءٍ لكلِّ صفّ) */
    public const MAX_DEPTH = 24;

    /** كم كلمةً سابقةً تُحفظ وتُمنع إعادتُها — 0 = مُطفأ */
    public static function depth(): int
    {
        return max(0, min(self::MAX_DEPTH, (int) setting('auth.pw_history', 5)));
    }

    /**
     * قاعدةُ تحقّقٍ لـ`$r->validate()`: تُسقط كلمةً سبق للمستخدم استعمالُها ضمن
     * آخر N. مستخدمٌ غائبٌ (`null`) لا يُفحص — القاعدةُ لا تُخمّن صاحبَ الكلمة.
     */
    public static function rule(?User $user): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
            if ($user !== null && is_string($value) && self::reused($user, $value)) {
                $fail(self::message());
            }
        };
    }

    /** رسالةُ الرفض — واحدةٌ لكلِّ المسارات */
    public static function message(): string
    {
        return 'لا يجوز إعادةُ استعمال إحدى آخر ' . self::depth()
            . ' كلمات مرور — اختر كلمةً لم تستعملها من قبل';
    }

    /** هل هذه الكلمةُ الصريحةُ إحدى آخر N كلماتٍ للمستخدم (الحاليّةُ منها)؟ */
    public static function reused(User $user, string $plain): bool
    {
        $n = self::depth();
        if ($n === 0 || $plain === '') return false;

        // الحاليّةُ من العمود نفسِه — حسابٌ قديمٌ لم يُدوَّن له سجلٌّ بعدُ يُحمى منها أيضاً
        $hashes = [];
        $current = (string) ($user->getRawOriginal('password') ?? '');
        if ($current !== '') $hashes[] = $current;

        if ($user->getKey() !== null) {
            $rows = DB::table(self::TABLE)->where('user_id', $user->getKey())
                ->orderByDesc('id')->limit($n)->pluck('password_hash')->all();
            // الحاليّةُ عادةً أحدثُ صفٍّ في السجلّ — فلا تُعَدّ مرّتين ولا تُقارن مرّتين
            $hashes = array_values(array_unique(array_merge($hashes, array_map('strval', $rows))));
        }

        foreach ($hashes as $hash) {
            try {
                if ($hash !== '' && Hash::check($plain, $hash)) return true;
            } catch (\RuntimeException) {
                // تجزيءٌ بخوارزميّةٍ غيرِ السارية (ترحيلٌ قديم) — لا يُقارَن، ولا يُسقط الطلب
            }
        }

        return false;
    }

    /**
     * يُدوِّن التجزيءَ الحاليَّ للمستخدم ويقصّ ما زاد عن N — يستدعيه `User::saved`
     * متى تغيّر `password`. لا يُلقي أبداً: فشلُ السجلِّ لا يُسقط تغييرَ كلمةٍ حقيقيّاً.
     */
    public static function record(User $user): void
    {
        $n = self::depth();
        // في `saved` لم يُزامَن الأصلُ بعدُ — فالقيمةُ الجديدةُ (مُجزَّأةً بالـcast) في السمات لا في الأصل
        $hash = (string) ($user->getAttributes()['password'] ?? '');
        if ($n === 0 || $hash === '' || $user->getKey() === null) return;

        try {
            DB::table(self::TABLE)->insert([
                'user_id' => $user->getKey(),
                'password_hash' => $hash,
                'created_at' => now(),
            ]);

            // القصُّ بالمعرّف التزايديّ لا بالزمن — `created_at` بدقّة الثانية يتساوى
            $stale = DB::table(self::TABLE)->where('user_id', $user->getKey())
                ->orderByDesc('id')->skip($n)->limit(1000)->pluck('id')->all();
            if ($stale) DB::table(self::TABLE)->whereIn('id', $stale)->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

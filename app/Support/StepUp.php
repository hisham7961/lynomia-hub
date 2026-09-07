<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * تصعيد المصادقة (Step-Up) — إعادةُ تحقّقٍ قبل الأفعال الحسّاسة (المرحلة ج).
 *
 * جلسةٌ مفتوحةٌ لا تكفي لكشف سرٍّ أو تفعيل قفل طوارئ أو تعديل دور: يُعاد
 * التحقّق (رمز TOTP لمن فعّله، وإلا كلمة المرور)، وتُختم النافذةُ في الجلسة
 * لمدةٍ قصيرة قابلة للضبط. فسرقةُ الجلسة وحدها لا تفتح هذه الأبواب.
 *
 * لا نظامَ ثانٍ: يُبنى على TOTP القائم وكلمة المرور القائمة — تصعيدٌ لا بديل.
 */
class StepUp
{
    /** مفتاح ختم النافذة في الجلسة */
    protected const KEY = 'stepup.ok_until';

    /** نافذة الصلاحية بالدقائق — من الإعدادات (افتراضاً ١٠) */
    public static function windowMinutes(): int
    {
        return max(1, (int) setting('security.stepup_minutes', 10));
    }

    /** هل التصعيد ساري المفعول الآن؟ */
    public static function fresh(): bool
    {
        $until = (int) session(self::KEY, 0);

        return $until > 0 && $until >= now()->timestamp;
    }

    /** يختم نافذةً جديدة بعد نجاح التحقّق */
    public static function stamp(): void
    {
        session([self::KEY => now()->addMinutes(self::windowMinutes())->timestamp]);
    }

    /** يُبطل النافذة (خروج، تغيير كلمة، إلخ) */
    public static function clear(): void
    {
        session()->forget(self::KEY);
    }

    /** طريقةُ التصعيد المطلوبة لهذا المستخدم: totp لمن فعّله وإلا password */
    public static function method($user): string
    {
        return $user && $user->totp_enabled ? 'totp' : 'password';
    }

    /**
     * **فحصُ الاعتماد وحدَه** — دون أيّ حالة (لا `session()`): TOTP لمن فعّله وإلا
     * كلمةُ المرور. استُخرج كي يُعاد استعمالُه من سطحٍ عديمِ الحالة (الجوال · Mobile
     * Readiness · الطور B · Critic F11): الجوالُ يُثبِت المِنحةَ في
     * `mobile_stepup_grants` لا في الجلسة، فيحتاج الفحصَ مجرّداً من الختم.
     */
    public static function checkCredential($user, string $input): bool
    {
        if (! $user) return false;

        return $user->totp_enabled
            ? \App\Support\Totp::verifyOnce((string) $user->totp_secret_cipher, $input, 'stepup:' . $user->id)
            : ($user->password && Hash::check($input, $user->password));
    }

    /** يتحقّق من المُدخَل ويختم النافذة (في الجلسة) عند النجاح — سطحُ الويب */
    public static function verify($user, string $input): bool
    {
        $ok = self::checkCredential($user, $input);
        if ($ok) self::stamp();

        return $ok;
    }
}

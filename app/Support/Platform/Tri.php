<?php

namespace App\Support\Platform;

/**
 * **الحقيقةُ الثلاثيّة** — `نعم` · `لا` · **`لا نعرف`**.
 *
 * ليست اختراعاً: `ConnectionProbe::SHAPE` جعل `up` ثلاثيّاً منذ المرحلة ١
 * (`null` = لم يُجرَّب · `true` = نجح · `false` = فشل)، لأنّ خلطَ «لم نفحص»
 * بـ«فحصنا ففشل» يجعل الشاشةَ تكذب. وهذا الصنفُ يُسمّي المبدأَ ليُعادَ
 * استعمالُه بدل نسخِه.
 *
 * **والقاعدةُ التي يحرسها — وهي قاعدتان تبدوان متناقضتين وليستا:**
 *
 *  ① **عند التنفيذ: `unknown` يُعامَل معاملةَ `false`.** لا يُرسَل وسيطٌ
 *     مجهولُ الدعم، ولا يُرقَّى نموذجٌ إلى مسارٍ يشترط قدرةً لم تُثبَت.
 *     الحذرُ افتراضٌ آمن.
 *
 *  ② **عند العرض: `unknown` يبقى `unknown`.** «غيرُ معروف» لا «غيرُ مدعوم».
 *
 * **ولمَ الفرقُ؟** لأنّ خلطَهما يُنتج عيبين متقابلين: معاملةُ `unknown`
 * معاملةَ `true` تُرسِل ما لا يُدعَم فيفشل الطلبُ — عطلٌ يُرى ويُصلَح؛
 * **وعرضُ `unknown` بوصفِه `false` يُغلق باباً مفتوحاً** فيظنّ المديرُ أنّ
 * النموذجَ لا يقدر وهو يقدر — خسارةٌ لا تُرى ولا تُصلَح.
 *
 * **والكتالوجُ (W1) لا يستعمله بعد** — حقولُه مكتوبةٌ بأيدينا فهي معلومةٌ
 * بالبناء. مستهلِكُه المرحلةُ التالية: قدراتُ النماذجِ وحدودُها وأسعارُها،
 * حيث «لا نعرف» هي الحالةُ الشائعةُ لا الشاذّة.
 */
final class Tri
{
    public const YES     = true;
    public const NO      = false;
    public const UNKNOWN = null;

    /** الدرجاتُ الأربعُ لمصدرِ المعرفة — مرتّبةً بالثقة */
    public const SOURCES = ['unknown', 'litellm', 'provider', 'verified', 'hub_override'];

    /** يُطبِّع أيَّ مدخلٍ إلى ثلاثيّةٍ صريحة — وما لا يُعرَف يبقى غيرَ معروف */
    public static function of(mixed $v): ?bool
    {
        if ($v === null || $v === '' || $v === 'unknown') return self::UNKNOWN;
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v !== 0;
        if (is_string($v)) {
            $s = mb_strtolower(trim($v));
            if (in_array($s, ['1', 'true', 'yes', 'نعم'], true))  return true;
            if (in_array($s, ['0', 'false', 'no', 'لا'], true))   return false;
        }

        return self::UNKNOWN;
    }

    public static function isUnknown(mixed $v): bool
    {
        return self::of($v) === self::UNKNOWN;
    }

    /** **قاعدةُ التنفيذ:** لا يمرّ إلّا المُثبَتُ — والمجهولُ لا يمرّ */
    public static function allowsExecution(mixed $v): bool
    {
        return self::of($v) === true;
    }

    /** **قاعدةُ العرض:** ثلاثُ كلماتٍ لا كلمتان */
    public static function label(mixed $v): string
    {
        return match (self::of($v)) {
            true    => 'مدعومة',
            false   => 'غيرُ مدعومة',
            default => 'غيرُ معروفة',
        };
    }

    /** صفٌّ موسومٌ بمصدرِه — فقيمةٌ بلا مصدرٍ تصير أسطورةً بعد شهر */
    public static function fact(mixed $value, string $source = 'unknown'): array
    {
        return [
            'v'   => self::of($value),
            'src' => in_array($source, self::SOURCES, true) ? $source : 'unknown',
        ];
    }
}

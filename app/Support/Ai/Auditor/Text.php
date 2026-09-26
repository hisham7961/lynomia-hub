<?php

namespace App\Support\Ai\Auditor;

/**
 * تطبيعُ نصوصِ التقارير للمقارنة — **لا للعرض**.
 *
 * «تمّ إنجاز الواجهة.» و«تم إنجاز الواجهة» نصٌّ واحدٌ لمن يقرأ: التشكيلُ والتطويلُ
 * وعلاماتُ الترقيم والمسافاتُ لا تغيّر المعنى، ونسخُ تقريرِ الأمسِ مع حذفِ نقطةٍ ليس
 * تقريراً جديداً. وتوحيدُ الألفِ والياءِ والتاءِ المربوطة يجمع ما يكتبه الناسُ بأشكالٍ شتّى.
 */
final class Text
{
    /** ما لا يُعَدّ عائقاً ولا إنجازاً: الفراغُ بصيغٍ مختلفة */
    public const EMPTYISH = ['', '-', '—', '.', 'لا', 'لا يوجد', 'لايوجد', 'لا شيء', 'لا شي',
        'لا شئ', 'لا مشاكل', 'لا مشكلة', 'لا توجد', 'لا يوجد مشاكل', 'لا توجد مشاكل', 'none', 'n/a', 'na', 'no'];

    public static function norm(?string $s): string
    {
        $s = (string) $s;
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);       // تشكيلٌ وتطويل
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);                              // ترقيمٌ ورموز
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));

        return $s;
    }

    public static function isEmptyish(?string $s): bool
    {
        return in_array(self::norm($s), array_map([self::class, 'norm'], self::EMPTYISH), true);
    }

    /** مقتطفٌ للعرض — لا يقطع وسطَ حرف */
    public static function clip(?string $s, int $len = 80): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));

        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }
}

<?php

namespace App\Support\Ai\Governance;

use App\Support\Ai\Routing\AiRouting;

/**
 * **دلالاتُ الكلفة — ومن أين جاء الرقم** (المرحلة ٤ · P4-W3).
 *
 * ── **القاعدةُ الحاكمةُ في هذا الملفّ كلِّه** ──
 *
 * > **مجهولٌ ≠ صفر.**
 *
 * وهي ليست شعاراً: رقمٌ مجهولٌ حُسِب صفراً **يُبطل حارسَ الميزانيّةِ في صمت**،
 * ويجعل لوحةً تقول «أنفقتَ ٣ دولارات» عن شهرٍ أُنفقت فيه ثلاثون — لأنّ سبعةً
 * وعشرين منها جاءت من نماذجَ بلا خريطةِ أسعار. **فلا دالّةَ هنا تُعيد `0`
 * عن غيابٍ**، و`null` تسري إلى الشاشةِ فتُطبَع «—» لا «٠٫٠٠».
 *
 * ── **أربعُ درجاتٍ لمصدرِ الرقم — والفرقُ بينها عمليّ** ──
 *
 *  · **`reported`** — من البوّابةِ مع الردِّ نفسِه (ترويسة `x-litellm-response-cost`).
 *    **وهذه ملكُ البوّابةِ لا ملكُنا**: تُسجَّل كما وصلت ولا تُصحَّح ولا تُعاد
 *    حسابُها. وهي الأصدقُ لأنّها تعرف تسعيرَ الحسابِ الفعليَّ وخصوماتِه.
 *  · **`calculated`** — رموزٌ **حقيقيّةٌ** عادت من الردّ × سعرٍ مخزَّنٍ عندنا.
 *    أدقُّ من التقدير لأنّ العدَّ حقيقيّ، وأضعفُ من المُبلَّغِ لأنّ السعرَ نسخةٌ
 *    قد تكون قديمة.
 *  · **`estimated`** — رموزٌ **مُقدَّرةٌ** قبل النداء × سعرٍ مخزَّن. وهذا ما
 *    يُحجَز به، ولا يُقدَّم على ما بعدَه أبداً.
 *  · **`unknown`** — لا رقمَ بحال. **وتُعَدُّ عدّاً مستقلّاً** فلا تختفي.
 *
 * ── **والمالُ عددٌ صحيحٌ بالميكرو** ──
 *
 * عدّادُ ميزانيّةٍ يُقارَن في شرطِ `UPDATE` متسابقٍ لا يحتمل خطأَ تقريبٍ
 * ثنائيّاً: `0.1 + 0.2 !== 0.3` في كلِّ لغةٍ تستعمل IEEE-754، وجمعُ ألفِ كسرٍ
 * ينحرف عن مجموعِه الصحيح انحرافاً يُرى. فالتحويلُ إلى عددٍ صحيحٍ **عند
 * الحدِّ** والحسابُ صحيحٌ بعدَه.
 */
final class AiCost
{
    public const REPORTED   = 'reported';
    public const CALCULATED = 'calculated';
    public const ESTIMATED  = 'estimated';
    public const UNKNOWN    = 'unknown';

    /** الدرجاتُ من الأقوى إلى الأضعف — **والترتيبُ يُقرَأ ولا يُنسَخ** */
    public const SOURCES = [self::REPORTED, self::CALCULATED, self::ESTIMATED, self::UNKNOWN];

    /** ١٫٠٠ من العملةِ = مليونُ ميكرو */
    public const SCALE = 1_000_000;

    /** العملةُ الافتراضيّةُ لخريطةِ الأسعارِ المرفقةِ بالبوّابة */
    public const CURRENCY = 'USD';

    /**
     * **من كسرٍ عائمٍ إلى ميكرو** — و`null` تبقى `null` ولا تصير صفراً.
     *
     * والتقريبُ `round` لا `intval`: `(int) (0.29 * 1e6)` يُعطي ‎`289999`‎ على
     * بعضِ المنصّاتِ لأنّ `0.29` لا تُمثَّل تماماً. وقرشٌ يضيع في كلِّ عمليّةٍ
     * يصير ديناراً في الشهر.
     */
    public static function toMicro(?float $amount): ?int
    {
        return $amount === null ? null : (int) round($amount * self::SCALE);
    }

    /** **من ميكرو إلى كسرٍ للعرضِ وحدَه** — ولا يُعاد إلى حسابٍ بعد ذلك */
    public static function toAmount(?int $micro): ?float
    {
        return $micro === null ? null : $micro / self::SCALE;
    }

    /**
     * **كلفةُ ردٍّ ناجحٍ ومصدرُها** — بالترتيبِ الذي يُصدَّق.
     *
     * @param  array  $usage    ما أعادته `AiChat::usage()`
     * @param  array  $pricing  عمودُ `pricing` للنموذجِ كما هو
     * @return array{micro: ?int, source: string, tokens: array{in: ?int, out: ?int, total: ?int, cached: ?int}}
     */
    public static function settle(array $usage, array $pricing): array
    {
        $in     = isset($usage['prompt']) ? (int) $usage['prompt'] : null;
        $out    = isset($usage['completion']) ? (int) $usage['completion'] : null;
        $total  = isset($usage['tokens']) ? (int) $usage['tokens'] : null;
        $cached = isset($usage['cached']) ? (int) $usage['cached'] : null;

        $tokens = ['in' => $in, 'out' => $out, 'total' => $total, 'cached' => $cached];

        // ① المُبلَّغُ يسبق كلَّ حساب — **ولا يُصحَّح** ولو خالف تقديرَنا
        if (isset($usage['cost']) && is_numeric($usage['cost'])) {
            return ['micro' => self::toMicro((float) $usage['cost']),
                    'source' => self::REPORTED, 'tokens' => $tokens];
        }

        // ② حسابٌ من رموزٍ **حقيقيّة** — ولا يُحسَب من نصفِ عددٍ
        if (($in !== null || $out !== null) && ($p = self::priceOf($pricing)) !== null) {
            $micro = self::toMicro(
                ($p['in'] ?? 0.0) * (($in ?? 0) / 1000) + ($p['out'] ?? 0.0) * (($out ?? 0) / 1000));

            return ['micro' => $micro, 'source' => self::CALCULATED, 'tokens' => $tokens];
        }

        // ③ **ولا صفرَ يُخترَع** — لا سعرَ أو لا عدَّ ⇒ مجهول
        return ['micro' => null, 'source' => self::UNKNOWN, 'tokens' => $tokens];
    }

    /**
     * **تقديرٌ قبل النداء** — لِما يُحجَز به. و`null` تعني «لا يُقدَّر».
     *
     * ويُعاد استعمالُ `AiRouting::estimateHop` نفسِها لا نسخةٍ منها: تقديرانِ
     * مختلفانِ لسعرٍ واحدٍ يجعلان الحجزَ يخالف حارسَ سقفِ الطلبِ في الرقم، ثمّ
     * يُصدَّق أحدُهما عشوائيّاً حين يختلفان.
     */
    public static function estimate(array $pricing, int $inTokens, int $outTokens): ?int
    {
        return self::toMicro(AiRouting::estimateHop($pricing, $inTokens, $outTokens));
    }

    /**
     * **سعرُ الألفِ رمزٍ من عمودِ التسعير** — أو `null` إن جُهل الطرفان.
     *
     * وسعرٌ **مجهولُ المصدرِ لا يُقاس عليه**: عمودُ `pricing` يحمل قيمةً
     * ومصدرَها (`{'v': …, 'src': …}`)، و`src === 'unknown'` رقمٌ لا سندَ له.
     *
     * @return array{in: ?float, out: ?float}|null
     */
    public static function priceOf(array $pricing): ?array
    {
        $in  = self::factOf($pricing['input_per_1k']  ?? null);
        $out = self::factOf($pricing['output_per_1k'] ?? null);

        return ($in === null && $out === null) ? null : ['in' => $in, 'out' => $out];
    }

    /** **الدرجةُ الأضعفُ تغلب** عند دمجِ مصدرين — فلا يُرفَع وصفُ رقمٍ فوقَ حقيقته */
    public static function weakest(string ...$sources): string
    {
        $worst = self::REPORTED;
        foreach ($sources as $s) {
            if (! in_array($s, self::SOURCES, true)) return self::UNKNOWN;
            if (array_search($s, self::SOURCES, true) > array_search($worst, self::SOURCES, true)) {
                $worst = $s;
            }
        }

        return $worst;
    }

    /** أهي كلفةٌ حقيقيّةٌ وقعت، أم رقمٌ قبل الوقوع؟ */
    public static function isActual(string $source): bool
    {
        return $source === self::REPORTED || $source === self::CALCULATED;
    }

    /** اسمُ الدرجةِ للعرضِ — بالعربيّةِ كما تُقرَأ على الشاشة */
    public static function label(string $source): string
    {
        return match ($source) {
            self::REPORTED   => 'مُبلَّغةٌ من البوّابة',
            self::CALCULATED => 'محسوبةٌ من رموزٍ حقيقيّة',
            self::ESTIMATED  => 'مُقدَّرةٌ قبل النداء',
            default          => 'غيرُ معروفة',
        };
    }

    private static function factOf(mixed $fact): ?float
    {
        if (! is_array($fact)) return null;
        if ((string) ($fact['src'] ?? 'unknown') === 'unknown') return null;

        return is_numeric($fact['v'] ?? null) ? (float) $fact['v'] : null;
    }
}

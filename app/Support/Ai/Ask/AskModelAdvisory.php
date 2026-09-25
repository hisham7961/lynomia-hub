<?php

namespace App\Support\Ai\Ask;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Support\Ai\Routing\AiProfiles;

/**
 * **أيصلح النموذجُ المُوجَّهُ إليه لسؤالٍ قصير؟ — جوابٌ من التهيئةِ وحدَها.**
 * (قبولُ الإنتاج · `92dbd557`)
 *
 * ── **المشكلةُ التي وُلد منها هذا الصنف** ──
 *
 * سؤالُ «كم مشروعاً لدينا» أخفق بـ`OUTPUT_LIMIT` **وسقفُه ٧٠٠ رمزاً معروضٌ
 * على الشاشةِ قبل الإرسال**. وسبعُمئةٍ تكفي جملةً عربيّةً عشرَ مرّات. فما
 * الذي أكلها؟ **نموذجٌ يُفكّر**: رموزُ تفكيرِه تُخصَم من `max_tokens` نفسِه،
 * فيبلغ السقفَ **قبل أن يكتب حرفاً يُرى**. والشاشةُ تقول «بلغ الجوابُ سقفَ
 * طولِه» — والجوابُ لم يبدأ.
 *
 * ── **ولماذا تهيئةٌ لا نداء؟** ──
 *
 * لأنّ الحقيقةَ موجودةٌ عندنا أصلاً: البوّابةُ تُعلن `supports_reasoning`
 * لكلِّ نشرٍ، و`AiModelFacts` تحفظها قدرةً موسومةَ المصدرِ منذ المرحلة ٢.
 * **فالسؤالُ يُجاب بقراءةِ صفٍّ، لا بإنفاقِ نداءِ توليدٍ لنعرف.**
 *
 * ── **ولا يُبدّل هذا الصنفُ نموذجاً ولا يرفع سقفاً** ──
 *
 * يقول ما يعرف **ويترك القرارَ لصاحبِه**. وتبديلُ النموذجِ خلفَ ظهرِ المالكِ
 * أسوأُ من الإخفاقِ نفسِه: فاتورةٌ تتغيّر وجودةٌ تتغيّر **ولا سطرَ يقول
 * لماذا**. فما هنا **تشخيصٌ يُعرَض لمن يملك الإصلاح**، لا إصلاحٌ صامت.
 *
 * ── **والمجهولُ يُقال مجهولاً** ──
 *
 * نشرٌ لم تُعلن البوّابةُ عَلَمَه يبقى `unknown`، **ولا يُرقّى إلى «لا يُفكّر»**
 * لأنّ الترقيةَ بالصمتِ هي بعينِها ما جعل التشخيصَ السابقَ يُخطئ. والمجهولُ
 * يُحسم بعد أوّلِ دورةٍ: `ai_usage_events.reasoning_tokens` تقول ما أنفق
 * فعلاً (المهاجرة `2026_10_06_000001`).
 *
 * **ولا اسمَ مزوّدٍ ولا نموذجٍ في هذا الملفّ** — كلُّ ما يُقال يُقرأ من الصفّ.
 */
final class AskModelAdvisory
{
    /** النموذجُ أثبتت البوّابةُ أنّه لا يُفكّر — فالسقفُ كلُّه للجوابِ المرئيّ */
    public const FIT = 'FIT';

    /** يُفكّر: رموزُ التفكيرِ والجوابُ **يتقاسمان السقفَ نفسَه** */
    public const SHARES_CAP = 'SHARES_CAP';

    /** لا عَلَمَ من البوّابة — ولا يُرقّى الصمتُ إلى نفي */
    public const UNKNOWN = 'UNKNOWN';

    /**
     * **وسمٌ قصيرٌ للشاشة** — كلمتان لا فقرة (قبولُ الإنتاج · `21b7633f`).
     *
     * كانت الفقرةُ الكاملةُ تُطبَع تحت مربّعِ السؤالِ فتُزاحم الشاشةَ وتُقرأ
     * عطلاً. **فالسطرُ يُوسَم، والتفصيلُ يُقرأ عند الحاجة.**
     */
    public const TAG = [
        self::FIT        => '✓ لا يُفكّر',
        self::SHARES_CAP => '🧠 يُفكّر',
        self::UNKNOWN    => '؟ تفكيرٌ غيرُ معلوم',
    ];

    /** ما يُقال لمن يملك الإصلاح — بلا رمزٍ يُفكّ */
    public const SAY = [
        self::FIT => 'النموذجُ المُوجَّهُ إليه لا يُنتج رموزَ تفكيرٍ حسب البوّابة — فسقفُ المخرَجِ كلُّه للجوابِ المرئيّ.',
        self::SHARES_CAP => 'النموذجُ المُوجَّهُ إليه **يُفكّر**، ورموزُ تفكيرِه تُخصَم من سقفِ المخرَجِ نفسِه. '
            . 'فقد يبلغ السقفَ قبل أن يكتب حرفاً يُرى — وهذا يُقرَأ «بلغ الجوابُ سقفَ طولِه» وهو لم يبدأ.',
        self::UNKNOWN => 'البوّابةُ لم تقل أيُفكّر هذا النموذجُ أم لا — و«لا نعرف» ليست «لا يُفكّر». '
            . 'وعمودُ «تفكير» في سجلِّ الاستهلاك يحسمها بعد أوّلِ دورة.',
    ];

    /**
     * **حقائقُ النموذجِ الأوّلِ في سلسلةِ الغرض** — أو `null` إن لا سلسلة.
     *
     * والأوّلُ وحدَه لأنّه من يُنادى ما لم يُخفق؛ والاحتياطُ يُقرأ حين يُستعمَل.
     *
     * @return array{alias: string, upstream: string, reasoning: ?bool, src: string,
     *               effort: ?bool, priced: bool, cap: int, code: string, say: string}|null
     */
    public static function read(?AiProfile $profile = null, ?int $cap = null): ?array
    {
        $p = $profile ?? AskPolicy::profile();
        if ($p === null) return null;

        $model = AiProfiles::chain($p)->first();
        if (! $model instanceof AiModel) return null;

        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $prm  = is_array($model->params) ? $model->params : [];
        $prc  = is_array($model->pricing) ? $model->pricing : [];

        // **ثلاثيّةٌ لا ثنائيّة**: `true` · `false` · `null`
        $think = $caps['reasoning']['v'] ?? null;
        $think = is_bool($think) ? $think : null;

        $code = $think === true ? self::SHARES_CAP
              : ($think === false ? self::FIT : self::UNKNOWN);

        $eff = $prm['reasoning_effort']['supported'] ?? null;

        return [
            'alias'     => (string) $model->litellm_model_name,
            'upstream'  => (string) $model->upstream_model,
            'reasoning' => $think,
            'src'       => (string) ($caps['reasoning']['src'] ?? 'unknown'),
            'effort'    => is_bool($eff) ? $eff : null,
            'priced'    => ($prc['reasoning_per_1k']['v'] ?? null) !== null,
            'cap'       => $cap ?? AskPolicy::maxOutputTokens(),
            'code'      => $code,
            'say'       => self::SAY[$code],
        ];
    }

    /**
     * **أيستحقُّ هذا تحذيراً على الشاشة؟**
     *
     * والمجهولُ يستحقّ: صمتُ البوّابةِ ليس شهادةَ صلاحيّة.
     */
    public static function warns(?array $advisory): bool
    {
        return $advisory !== null
            && in_array($advisory['code'] ?? '', [self::SHARES_CAP, self::UNKNOWN], true);
    }
}

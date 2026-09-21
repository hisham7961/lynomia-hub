<?php

namespace App\Support;

/**
 * **أثرُ «اسأل Hub» — ما يُسجَّل وما لا يُسجَّل** (المرحلة ٣ · P3-W2).
 *
 * ── **والثاني أهمُّ من الأوّل** ──
 *
 * الخطّةُ تنصّ حرفاً: «**ولا تخزن reasoning/chain-of-thought داخلي للنموذج**».
 * وأزيد عليها بحكمِ ما تعلّمته المرحلةُ ٢: **ولا السؤالَ ولا الإجابةَ ولا صفّاً
 * من البياناتِ المسترجَعة**.
 *
 * **ولمَ لا يُسجَّل السؤال؟** لأنّ سجلَّ التدقيقِ يُقرَأ بصلاحيّةٍ **غيرِ**
 * صلاحيّةِ السائل. فسؤالُ مديرٍ «كم راتبُ فلان؟» يصير — بتسجيلِه — **تسريبَ
 * الحقلِ الحسّاسِ نفسِه إلى كلِّ من يقرأ التدقيق**، ولو كانت الإجابةُ قد
 * حُجبت. والسجلُّ يُنشئ بابَ التسريبِ الذي أغلقه المسارُ كلُّه.
 *
 * فيُسجَّل **شكلُ الطلبِ لا مضمونُه**: كم محرفاً · أيُّ أدوات · أيُّ وحدات ·
 * سماحٌ أم رفض · أيُّ غرضٍ ونموذج · كم استغرق.
 *
 * ── **وما يكفي للمساءلةِ لاحقاً** ──
 *
 * «من سأل، ومتى، وأيَّ بياناتٍ لمس، وهل سُمح له» — تُجاب كلُّها من هذا الشكل
 * **بلا محرفٍ واحدٍ من محتوى**.
 */
final class AskAudit
{
    public const ACTION_ASKED   = 'سؤالُ مساعدِ Hub';
    public const ACTION_DENIED  = 'رفضُ سؤالِ مساعدِ Hub';

    /** ما لا يبلغ الأثرَ أبداً — يُعَدُّ ويُختبَر */
    public const NEVER_LOGGED = ['question', 'answer', 'reasoning', 'rows', 'content', 'prompt'];

    /**
     * **يسجّل سؤالاً نُفِّذ.**
     *
     * @param  array{tools: list<string>, modules: list<string>, rows: int,
     *               profile: ?string, model: ?string, depth: int, ms: ?int,
     *               chars: int, truncated: bool, outcome: string}  $shape
     */
    public static function asked(array $shape): void
    {
        hub_audit(self::ACTION_ASKED, null, null, null,
            ['after' => Redactor::arr(self::normalize($shape))]);
    }

    /** **ورفضاً** — والرفضُ يُسجَّل كما يُسجَّل السماح، فالمنعُ خبرٌ أيضاً */
    public static function denied(string $why, array $shape = []): void
    {
        hub_audit(self::ACTION_DENIED, null, null, null,
            ['after' => Redactor::arr(self::normalize($shape + [
                'outcome' => 'denied', 'why' => $why,
            ]))]);
    }

    /**
     * **يُسقِط كلَّ مفتاحٍ يحمل محتوًى — حتّى لو مرّره المستدعي سهواً.**
     *
     * وهذا حزامٌ ثانٍ مقصود: الحارسُ الذي يعتمد على انضباطِ كلِّ مستدعٍ ينكسر
     * عند أوّلِ مستدعٍ جديد. **فالإسقاطُ هنا عند الكاتبِ الواحد.**
     */
    private static function normalize(array $shape): array
    {
        foreach (self::NEVER_LOGGED as $k) unset($shape[$k]);

        $list = static fn ($v) => is_array($v) ? array_values(array_unique(array_map('strval', $v))) : [];

        return [
            'tools'     => $list($shape['tools'] ?? []),
            'modules'   => $list($shape['modules'] ?? []),
            'rows'      => (int) ($shape['rows'] ?? 0),
            'chars'     => (int) ($shape['chars'] ?? 0),
            'truncated' => (bool) ($shape['truncated'] ?? false),
            'profile'   => isset($shape['profile']) ? (string) $shape['profile'] : null,
            'model'     => isset($shape['model']) ? (string) $shape['model'] : null,
            'depth'     => (int) ($shape['depth'] ?? 0),
            'ms'        => isset($shape['ms']) ? (int) $shape['ms'] : null,
            // ── زيدت مع المنسّق (P3-W5/W6): يكفي للتحقيقِ ولا يحمل سرّاً ──
            'correlation' => isset($shape['correlation']) ? (string) $shape['correlation'] : null,
            'offered'     => (int) ($shape['offered'] ?? 0),
            'requested'   => $list($shape['requested'] ?? []),
            'denied'      => $list($shape['denied'] ?? []),
            'sources'     => (int) ($shape['sources'] ?? 0),
            'failure'     => isset($shape['failure']) ? (string) $shape['failure'] : null,
            'generator'   => isset($shape['generator']) ? (string) $shape['generator'] : null,
            'tokens'      => isset($shape['tokens']) ? (int) $shape['tokens'] : null,
            'cost'        => isset($shape['cost']) ? (float) $shape['cost'] : null,
            /*
             * ── **رموزُ التفكيرِ رقمٌ لا محتوى** (قبولُ الإنتاج · `92dbd557`) ──
             *
             * `NEVER_LOGGED` تُسقِط مفتاحَ `reasoning` لأنّه كان يعني **متنَ**
             * التفكير، وذاك لا يُكتَب أبداً. وهذا مفتاحٌ آخرُ باسمٍ آخر: **عددُ
             * رموزٍ أُنفقت**. وإغفالُه يجعل طلباً التهم سقفَه تفكيراً يُقرأ
             * كأنّه لم يُنفق مخرَجاً البتّة — **وهو ما أخّر تشخيصَ ثلاثِ
             * محاولاتٍ مدفوعة**.
             */
            'reasoning_tokens' => isset($shape['reasoning_tokens'])
                ? max(0, (int) $shape['reasoning_tokens']) : null,
            /*
             * ── زيدت مع المولِّدِ الإنتاجيّ: **عدّادا المساءلةِ الماليّة** ──
             *
             * `executed` عددُ قراءاتِ القاعدةِ التي **سُمح بها ونُفِّذت** — وهو
             * غيرُ `requested` وغيرُ `denied`، وبه يُقرأ أثرُ الطلبِ على
             * البيانات. و`calls` عددُ نداءاتِ التوليدِ **بالإعاداتِ والاحتياطِ
             * معاً** — وهو ما يُنفق فعلاً.
             *
             * ولمَ `calls` وقد سُجّل `tokens`؟ لأنّ عددَ الرموزِ **لا يعود من
             * نداءٍ أخفق**، فطلبٌ استهلك خمسةَ نداءاتٍ فاشلةٍ يُسجَّل بلا رموزٍ
             * ولا كلفة — **فيبدو مجّانيّاً وهو ليس كذلك**. وعدُّ النداءاتِ
             * يُسجَّل في كلِّ حال.
             */
            'executed'    => (int) ($shape['executed'] ?? 0),
            'calls'       => isset($shape['calls']) ? (int) $shape['calls'] : null,
            'outcome'   => (string) ($shape['outcome'] ?? 'ok'),
            'why'       => isset($shape['why']) ? mb_substr((string) $shape['why'], 0, 180) : null,
        ];
    }
}

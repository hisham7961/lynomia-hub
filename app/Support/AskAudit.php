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
            'outcome'   => (string) ($shape['outcome'] ?? 'ok'),
            'why'       => isset($shape['why']) ? mb_substr((string) $shape['why'], 0, 180) : null,
        ];
    }
}

<?php

namespace App\Support;

/**
 * **جدولُ القرار — أهمُّ جدولٍ في وثيقةِ المعماريّة** (المرحلة ٢ · W7 · §٨).
 *
 * السلسلة: `ميزةُ Hub → ملفُّ سياسة → أساسيّ → احتياطيّ ١ → احتياطيّ ٢ → ✋`
 *
 * **والجدولُ هنا بياناتٌ لا فروعٌ مبعثرة.** أحدَ عشرَ صفّاً مكتوبةً صفّاً صفّاً
 * في `TABLE`، يقرؤها `decide()` ويقرأها الاختبارُ **من المصدرِ نفسِه**. وفرعٌ
 * `if` لكلِّ حالةٍ كان سينحرف عن الجدولِ بعد شهرٍ ولا يُلاحَظ.
 *
 * ── **ثلاثةُ صفوفٍ تمنع الاحتياطَ حيث يبدو مغرياً — وهي لبُّ الجدول** ──
 *
 *  ① **‏401/403 ⇒ لا إعادةَ ولا احتياط.** اعتمادٌ خاطئ: التكرارُ لا يصلحه،
 *     **والاحتياطُ يُخفي العطل** فيبقى المفتاحُ الخطأُ سنةً بلا من يُصلحه.
 *
 *  ② **تجاوزُ الميزانيّة ⇒ لا احتياط.** «الاحتياطُ يُضاعف الإنفاقَ الممنوع» —
 *     وهو أخطرُ سطرٍ هنا: حارسُ كلفةٍ يُفعَّل ثمّ يُلتَفُّ حوله يصير **ضِعفَ
 *     الكلفةِ لا نصفَها**.
 *
 *  ③ **بدأ البثُّ ثمّ انقطع ⇒ لا إعادةَ ولا احتياط.** «قد يُكرِّر أثراً
 *     جانبيّاً» — وهذا ليس عن المالِ بل عن الصواب: طلبٌ نفّذ نصفَ فعلٍ ثمّ
 *     أُعيد يُنفّذه مرّتين.
 *
 * **ولا نداءَ في هذا الصنفِ ولا كلفة** — قرارٌ خالصٌ من حالةٍ مُعطاة.
 */
final class AiRouting
{
    /** أسبابُ الإخفاقِ المعروفة — مفتاحُ الصفِّ في الجدول */
    public const CAUSES = [
        'auth', 'bad_request', 'context_overflow', 'unsupported_capability',
        'budget_exceeded', 'content_policy', 'rate_limited', 'provider_credits',
        'policy_denied', 'transient', 'model_gone', 'provider_cooldown', 'stream_interrupted',
    ];

    /**
     * **الجدولُ حرفاً بحرفٍ من §٨.**
     *
     * `retry` عددُ المحاولاتِ المسموحةِ بعد الأولى · `fallback` أيُسمَح بالقفزِ
     * إلى نموذجٍ آخر · `conditional` احتياطٌ **بشرط** يُفحَص عند التخطيط.
     */
    public const TABLE = [
        'auth' => [
            'retry' => 0, 'fallback' => false,
            'why'   => 'اعتمادٌ خاطئ — التكرارُ لا يصلحه، والاحتياطُ يُخفي العطل',
        ],
        'bad_request' => [
            'retry' => 0, 'fallback' => false,
            'why'   => 'عيبٌ عندنا — لا يُصلَح بنموذجٍ آخر',
        ],
        'context_overflow' => [
            'retry' => 0, 'fallback' => true, 'conditional' => 'wider_context',
            'why'   => 'احتياطٌ فقط إلى نموذجٍ نافذتُه أوسعُ **مُثبَتة**',
        ],
        'unsupported_capability' => [
            'retry' => 0, 'fallback' => false,
            'why'   => 'يُمنَع عند الاختيارِ لا عند الفشل',
        ],
        'budget_exceeded' => [
            'retry' => 0, 'fallback' => false,
            'why'   => 'الاحتياطُ يُضاعف الإنفاقَ الممنوع',
        ],
        'content_policy' => [
            'retry' => 0, 'fallback' => false,
            'why'   => 'نتيجةٌ لا عطل',
        ],
        'rate_limited' => [
            'retry' => 1, 'fallback' => true, 'use_retry_after' => true,
            'why'   => 'عابرٌ ومُحدَّدُ المدّة — مرّةٌ بالمهلةِ المُعلَنة ثمّ احتياط',
        ],
        /*
         * **رصيدُ الحسابِ نفد — لا إعادةَ، واحتياطٌ إلى مزوّدٍ آخرَ وحدَه.**
         *
         * وهذا الصفُّ وُلد من قبولِ إنتاجٍ حقيقيّ: الطلبُ بلغ المزوّدَ وعاد
         * `429` بنصِّ «لا رصيدَ متبقٍّ»، فقُرئ حدَّ معدّلٍ فانتُظرت مهلتُه
         * وأُعيد النداءُ ثمّ احتيط إلى نموذجٍ ثانٍ **على الاعتمادِ الميّتِ
         * نفسِه**. ثلاثةُ نداءاتٍ ضائعةٍ عن مالٍ لا يعود بانتظار.
         *
         * **والشرطُ `other_provider` هو لبُّ الصفّ:** الرصيدُ رصيدُ حسابٍ عند
         * مزوّدٍ بعينِه، فكلُّ نموذجٍ على اعتمادِه يسقط سقوطَه. والاحتياطُ إلى
         * مزوّدٍ آخرَ — إن وُجد — هو الاحتياطُ الوحيدُ الذي يُنتج شيئاً.
         */
        'provider_credits' => [
            'retry' => 0, 'fallback' => true, 'conditional' => 'other_provider',
            'why'   => 'نفد رصيدُ الحساب — لا يُصلحه انتظارٌ ولا نموذجٌ آخرُ على الاعتمادِ نفسِه',
        ],
        /**
         * منعته سياسةُ الحوكمة — **قرارٌ لا عطل**، فلا إعادةَ ولا احتياط.
         * والاحتياطُ هنا **التفافٌ على السياسة** لا إنقاذٌ من عطل.
         */
        'policy_denied' => [
            'retry' => 0, 'fallback' => false,
            'why'   => 'السياسةُ منعت — والاحتياطُ التفافٌ عليها لا إنقاذٌ من عطل',
        ],
        'transient' => [
            'retry' => 2, 'fallback' => true, 'backoff' => 'exponential',
            'why'   => 'عابر — حتّى مرّتين بتراجعٍ أُسّيّ ثمّ احتياط',
        ],
        'model_gone' => [
            'retry' => 0, 'fallback' => true, 'mark' => 'unavailable',
            'why'   => 'حقيقةٌ دائمة ⇒ يُوسَم `unavailable` ولا يُعاد إليه',
        ],
        'provider_cooldown' => [
            'retry' => 0, 'fallback' => true, 'immediate' => true,
            'why'   => 'لا تُهدَر مهلةٌ على مزوّدٍ مُستبعَدٍ سلفاً',
        ],
        'stream_interrupted' => [
            'retry' => 0, 'fallback' => false,
            'why'   => '**قد يُكرِّر أثراً جانبيّاً** — والصوابُ أهمُّ من الإتمام',
        ],
    ];

    /** **عمقٌ أقصاه ٢** — ثلاثةُ نماذجَ لا أكثر (الحارس ①) */
    public const MAX_DEPTH = 2;

    /** تهدئةُ المزوّد: بعد هذا العددِ من الإخفاقاتِ المتتاليةِ يُستبعَد (الحارس ③) */
    public const COOLDOWN_AFTER = 3;
    public const COOLDOWN_MINUTES = 10;

    /**
     * **ما يُحسَب على المزوّدِ وحدَه** — فالتهدئةُ عقوبةٌ لا تُوقَّع بلا ذنب.
     *
     * ‏`bad_request` عيبٌ عندنا، و`content_policy` نتيجةٌ لا عطل، و
     * `context_overflow` حجمُ طلبِنا، و`unsupported_capability` سوءُ اختيارٍ
     * منّا. وعدُّ هذه على المزوّدِ يُهدّئ مزوّداً سليماً لعشرِ دقائقَ بسببِ ثلاثةِ
     * طلباتٍ مشوّهةٍ كتبناها نحن.
     */
    public const FAULTS = ['auth', 'rate_limited', 'provider_credits', 'transient', 'model_gone'];

    // ── التصنيف ────────────────────────────────────────────────────────

    /**
     * **من ردِّ البوّابةِ إلى سببٍ معروف** — ولا يُخمَّن ما لا دليلَ عليه.
     *
     * والسببُ الافتراضيُّ `transient` **عن قصد**: عطلٌ مجهولُ الصنفِ يُعامَل
     * عابراً فيُعاد مرّتين، وهو أرحمُ من رفضٍ قاطعٍ لخللٍ لحظيّ. أمّا ما نعرفه
     * فلا يُترَك للافتراض.
     *
     * @param  array{code?: ?int, body?: ?string, streamed?: bool, exception?: bool}  $signal
     */
    public static function classify(array $signal): string
    {
        // ① البثُّ المنقطعُ يسبق كلَّ شيء — أُنتج مخرجٌ جزئيٌّ فلا إعادةَ بحال
        if (! empty($signal['streamed'])) return 'stream_interrupted';

        $code = $signal['code'] ?? null;
        $body = mb_strtolower((string) ($signal['body'] ?? ''));

        if ($code === 401 || $code === 403) return 'auth';
        if ($code === 404)                  return 'model_gone';

        /*
         * **‏٤٢٩ ليست دائماً حدَّ معدّل** — كشفه قراءةُ عقدِ الإصدارِ المثبَّت.
         *
         * `proxy/_types.py:3855` يفرض الرمزَ صراحةً على رسالتين:
         * «‏No healthy deployment available» و«‏No deployments available». أي
         * أنّ **نفادَ النماذجِ الصالحةِ — وهو انقطاعُ خدمةٍ — يُعاد بالرمزِ
         * الذي يعني «أبطئ»**.
         *
         * والفرقُ في القرارِ لا في التسميةِ وحدَها: `rate_limited` يُعيد
         * المحاولةَ **بالمهلةِ المُعلَنةِ** انتظاراً لحدٍّ ينقضي، وهنا لا حدَّ
         * ينقضي أصلاً — فالصوابُ تراجعٌ أُسّيٌّ ثمّ احتياطٌ إلى نموذجٍ آخر.
         * ولأنّ الطلبَ **لم يبلغ مزوّداً** فلا رمزَ أُنفق في إعادتِه.
         */
        /*
         * **ورصيدٌ نفد ليس حدَّ معدّلٍ أيضاً** (المرحلة ٤ · P4-W8).
         *
         * فـ٤٢٩ صارت ثلاثةَ معانٍ: انقطاعُ خدمةٍ خلفَ البوّابة، ومالٌ نفد،
         * وحدُّ معدّلٍ حقيقيّ. والفرقُ في القرارِ كلِّه لا في التسمية:
         * الأوّلُ يُعاد ويُحتاط، والثاني **لا يُعاد ولا يُحتاط إلّا إلى مزوّدٍ
         * آخر**، والثالثُ ينتظر مهلتَه المُعلَنة.
         *
         * **والتصنيفُ مركزُه `AiChat::classify`** لا يُنسَخ هنا: نسختان من
         * دلالاتِ المتنِ تفترقان بعد شهرٍ فيُقرَأ الردُّ الواحدُ صنفين.
         */
        if ($code === 429) {
            if (str_contains($body, 'no healthy deployment')
                || str_contains($body, 'no deployments available')) return 'transient';

            return AiChat::classify(429, $body) === AskFailures::PROVIDER_CREDITS
                ? 'provider_credits'
                : 'rate_limited';
        }

        // **الدفعُ المطلوبُ رمزٌ صريحٌ للمال** — ولا يحتمل قراءةً ثانية
        if ($code === 402) return 'provider_credits';

        if (in_array($code, [500, 502, 503, 504], true)) return 'transient';

        if ($code === 400) {
            // ‏٤٠٠ ليست صنفاً واحداً: الرسالةُ تفرّق السياقَ من السياسةِ من الشكل
            if (str_contains($body, 'context') || str_contains($body, 'too long')
                || str_contains($body, 'maximum context')) return 'context_overflow';
            if (str_contains($body, 'content') && str_contains($body, 'policy')) return 'content_policy';
            if (str_contains($body, 'filter')) return 'content_policy';

            return 'bad_request';
        }

        if ($code === 422 && str_contains($body, 'content')) return 'content_policy';

        return 'transient';
    }

    /**
     * **القرارُ من الجدول** — ولا قرارَ من خارجِه.
     *
     * @return array{cause: string, retry: int, fallback: bool, why: string,
     *               use_retry_after: bool, backoff: ?string, conditional: ?string,
     *               mark: ?string, immediate: bool}
     */
    public static function decide(string $cause): array
    {
        $row = self::TABLE[$cause] ?? self::TABLE['transient'];
        if (! isset(self::TABLE[$cause])) $cause = 'transient';

        return [
            'cause'           => $cause,
            'retry'           => (int) $row['retry'],
            'fallback'        => (bool) $row['fallback'],
            'why'             => (string) $row['why'],
            'use_retry_after' => (bool) ($row['use_retry_after'] ?? false),
            'backoff'         => $row['backoff'] ?? null,
            'conditional'     => $row['conditional'] ?? null,
            'mark'            => $row['mark'] ?? null,
            'immediate'       => (bool) ($row['immediate'] ?? false),
        ];
    }

    /** القرارُ مباشرةً من إشارةِ الإخفاق — تصنيفٌ ثمّ جدول */
    public static function decideFrom(array $signal): array
    {
        return self::decide(self::classify($signal));
    }

    /**
     * **مهلةُ إعادةِ المحاولة** بالثواني — أو `null` إن لا إعادة.
     *
     * ‏`Retry-After` المُعلَنةُ تُحترَم كما هي (بسقفٍ عاقل)، وما سواها تراجعٌ
     * أُسّيّ. **ولا تُخترَع مهلةٌ حيث لا إعادة.**
     */
    public static function delayFor(array $decision, int $attempt, ?int $retryAfter = null): ?int
    {
        if ($decision['retry'] < $attempt) return null;

        if ($decision['use_retry_after'] && $retryAfter !== null) {
            return max(1, min(60, $retryAfter));
        }

        return $decision['backoff'] === 'exponential' ? min(30, 2 ** max(1, $attempt)) : 1;
    }

    // ── الحارسُ الثاني: ميزانيّةُ الطلب ─────────────────────────────────

    /**
     * **مجموعُ محاولاتٍ مُقدَّرٍ يتجاوز السقفَ ⇒ توقّفٌ لا احتياط.**
     *
     * والتقديرُ **تقديرٌ ويُقال كذلك**: من خريطةِ الأسعارِ لا من فاتورةِ المزوّد
     * (§١٣). وإخفاءُ هذا القيدِ يجعل الشاشةَ تكذب.
     *
     * **وكلفةٌ مجهولةٌ مع سقفٍ مفروضٍ لا تمرّ** — وهذه قاعدةُ `Tri` ① نفسُها في
     * موضعِ المال: `null` ليست صفراً بل **«لا نعرف كم يكلّف»**، وتمريرُ ما لا
     * يُقاس تحت سقفٍ وُضع عمداً يُبطل السقفَ في صمت. أمّا بلا سقفٍ (`0`) فلا
     * شيءَ يُقاس أصلاً، والمجهولُ يمرّ.
     *
     * @return array{ok: bool, spent: float, ceiling: float, why: ?string}
     */
    public static function withinBudget(float $estimatedSoFar, ?float $nextHopEstimate, float $ceiling): array
    {
        if ($ceiling > 0 && $nextHopEstimate === null) {
            return [
                'ok'      => false,
                'spent'   => $estimatedSoFar,
                'ceiling' => $ceiling,
                'why'     => 'كلفةُ القفزةِ التالية مجهولةٌ ومع سقفٍ مفروضٍ لا تُقاس — توقّفٌ لا احتياط',
            ];
        }

        $total = $estimatedSoFar + (float) ($nextHopEstimate ?? 0.0);
        $ok    = $ceiling <= 0 || $total <= $ceiling;

        return [
            'ok'      => $ok,
            'spent'   => $total,
            'ceiling' => $ceiling,
            'why'     => $ok ? null
                : 'الكلفةُ المقدَّرةُ للقفزةِ التالية تتجاوز سقفَ الطلب — توقّفٌ لا احتياط',
        ];
    }

    /**
     * **كلفةٌ مقدَّرةٌ لقفزةٍ** من أسعارِ النموذجِ المخزّنة — أو `null` إن جُهلت.
     *
     * و`null` ليست صفراً: سعرٌ مجهولٌ يعني **أنّنا لا نعرف كم تكلّف**، وحسابُه
     * صفراً يجعل حارسَ الميزانيّةِ يمرّر ما لا يُقاس.
     */
    public static function estimateHop(array $pricing, int $inTokens, int $outTokens): ?float
    {
        $in  = $pricing['input_per_1k']['v']  ?? null;
        $out = $pricing['output_per_1k']['v'] ?? null;
        if (! is_numeric($in) && ! is_numeric($out)) return null;

        return (float) (($in ?? 0) * ($inTokens / 1000) + ($out ?? 0) * ($outTokens / 1000));
    }

    // ── الحارسُ الثالث: تهدئةُ المزوّد ──────────────────────────────────

    /** أَيُستبعَد هذا المزوّدُ الآن؟ — حالةٌ محلّيّةٌ تُقرأ قبل أيِّ نداء */
    public static function inCooldown(string $providerId): bool
    {
        return \Illuminate\Support\Facades\Cache::get(self::cooldownKey($providerId)) !== null;
    }

    /** إخفاقٌ متتالٍ — وعند البلوغِ يُستبعَد المزوّدُ مؤقّتاً */
    public static function noteFailure(string $providerId): int
    {
        $key = self::streakKey($providerId);
        $n   = (int) \Illuminate\Support\Facades\Cache::get($key, 0) + 1;
        \Illuminate\Support\Facades\Cache::put($key, $n, now()->addMinutes(self::COOLDOWN_MINUTES));

        if ($n >= self::COOLDOWN_AFTER) {
            \Illuminate\Support\Facades\Cache::put(self::cooldownKey($providerId), true,
                now()->addMinutes(self::COOLDOWN_MINUTES));
        }

        return $n;
    }

    /** نجاحٌ يمسح السلسلةَ والتهدئةَ معاً — فلا يُعاقَب مزوّدٌ تعافى */
    public static function noteSuccess(string $providerId): void
    {
        \Illuminate\Support\Facades\Cache::forget(self::streakKey($providerId));
        \Illuminate\Support\Facades\Cache::forget(self::cooldownKey($providerId));
    }

    private static function streakKey(string $id): string   { return 'ai.fail.' . $id; }
    private static function cooldownKey(string $id): string { return 'ai.cool.' . $id; }
}

<?php

namespace App\Support;

/**
 * **تطبيعُ معرفةِ البوّابةِ إلى حقائقَ موسومةِ المصدر** (المرحلة ٢ · W5).
 *
 * دالّةٌ خالصة: تأخذ حمولةَ `model_info` كما تعيدها البوّابةُ وتُعيد الأعمدةَ
 * الأربعةَ (`capabilities` · `limits` · `params` · `pricing`) بالشكلِ الذي
 * تنصّ عليه وثيقةُ المعماريّة (§٤–§٧). **لا تقرأ قاعدةً ولا تكتب فيها ولا
 * تتّصل بشبكة.**
 *
 * ── **القاعدةُ التي يقوم عليها الملفُّ كلُّه** ──
 *
 * > **الغيابُ `unknown` لا `false`.**
 *
 * عَلَمٌ لم تذكره البوّابةُ يعني **«لا دليل»**، لا «غيرُ مدعوم». وخلطُهما يُنتج
 * عيبَين متقابلَين شرحهما `Tri`: معاملةُ المجهولِ معاملةَ المدعومِ تُرسِل ما لا
 * يُدعَم فيفشل الطلبُ — **عطلٌ يُرى ويُصلَح**؛ وعرضُ المجهولِ بوصفِه غيرَ مدعومٍ
 * يُغلق باباً مفتوحاً فيظنّ المديرُ أنّ النموذجَ لا يقدر وهو يقدر — **خسارةٌ لا
 * تُرى ولا تُصلَح**.
 *
 * ── **ولا كتالوجَ نماذجَ هنا** ──
 *
 * لا اسمَ نموذجٍ واحدٍ في هذا الملفّ ولا سعرٌ ولا حدٌّ. المفاتيحُ المذكورةُ
 * أسماءُ **حقولِ عقدِ البوّابة** (‏`ModelInfoBase` كما أثبتها W0)، لا معرفةَ
 * مزوّد. ونموذجٌ جديدٌ يظهر غداً يمرّ من هنا بلا سطرٍ يُضاف.
 *
 * ── **ولمَ لا تُستعمَل `Tri::fact` للأرقام؟** ──
 *
 * لأنّها تُطبّع القيمةَ إلى ثلاثيّةٍ منطقيّة: `Tri::fact(128000)` تُعيد
 * `v = true`. فنافذةُ السياقِ تصير «نعم». الأرقامُ والقوائمُ تمرّ بـ`val()`
 * التي تحفظ القيمةَ كما هي وتضمّ إليها مصدرَها.
 */
final class AiModelFacts
{
    /** القدراتُ السبعَ عشرةَ — قائمةُ §٤ بحرفها */
    public const CAPABILITIES = [
        'chat', 'text', 'reasoning', 'vision', 'image_generation', 'audio_in', 'audio_out',
        'speech_to_text', 'text_to_speech', 'video', 'embeddings', 'reranking', 'tools',
        'structured_output', 'streaming', 'batch', 'fine_tuning',
    ];

    /**
     * **عَلَمٌ في عقدِ البوّابة ⟵ قدرةٌ عندنا.**
     *
     * القدرةُ الغائبةُ عن هذه الخريطةِ **وعن خريطةِ الوضع** تبقى `unknown`
     * أبداً — لا يُخترَع لها دليل.
     */
    private const FLAG_MAP = [
        'vision'            => 'supports_vision',
        'tools'             => 'supports_function_calling',
        'reasoning'         => 'supports_reasoning',
        'structured_output' => 'supports_response_schema',
        'streaming'         => 'supports_native_streaming',
        'audio_in'          => 'supports_audio_input',
        'audio_out'         => 'supports_audio_output',
    ];

    /**
     * **وضعُ النشرِ ⟵ القدرةُ الحصريّة.**
     *
     * ‏`mode` قيمةٌ واحدةٌ للنشرِ الواحد، فهي تقول ما **يفعله هذا النشرُ**. ولذلك
     * وحدَها تُنتج `false` صادقاً: نشرٌ وضعُه `chat` لا يخدم `embeddings` —
     * وهذا دليلٌ من البوّابةِ لا استنتاجٌ من اسمِ نموذج.
     *
     * **ولا تُشتقّ منها إلّا القدراتُ المذكورةُ هنا.** `tools` و`vision` وأخواتُها
     * تبقى على أعلامِها أو `unknown`، فالوضعُ لا يقول عنها شيئاً.
     */
    private const MODE_MAP = [
        'chat'                => 'chat',
        'completion'          => 'text',
        'embedding'           => 'embeddings',
        'image_generation'    => 'image_generation',
        'audio_transcription' => 'speech_to_text',
        'audio_speech'        => 'text_to_speech',
        'rerank'              => 'reranking',
    ];

    /**
     * **مفرداتُ الوسائطِ المعروضة** — لا كتالوجُ نماذج.
     *
     * أسماءُ الوسائطِ في واجهةِ البوّابةِ المتوافقةِ التي تعرضها الشاشة. والبوّابةُ هي
     * من يقول أيُّها مدعومٌ لهذا النشرِ بعينِه (`supported_openai_params`).
     */
    public const PARAM_VOCABULARY = [
        'temperature', 'top_p', 'max_tokens', 'stop', 'seed',
        'response_format', 'tools', 'tool_choice', 'reasoning_effort',
    ];

    /** حقلُ التسعيرِ عند البوّابة (للرمز الواحد) ⟵ حقلُنا (لكلِّ ألف) */
    private const PRICE_PER_1K = [
        'input_per_1k'        => 'input_cost_per_token',
        'output_per_1k'       => 'output_cost_per_token',
        'cached_input_per_1k' => 'cache_read_input_token_cost',
        'reasoning_per_1k'    => 'output_cost_per_reasoning_token',
        'embedding_per_1k'    => 'input_cost_per_token_batches',
    ];

    /** حقلُ تسعيرٍ بالوحدةِ لا بالرمز — لا يُضرَب في ألف */
    private const PRICE_PER_UNIT = [
        'image_per_unit'   => 'input_cost_per_image',
        'audio_per_minute' => 'input_cost_per_audio_per_second',
    ];

    // ── القدرات ────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $info      حمولةُ `model_info` من البوّابة
     * @param  array<string,mixed>  $existing  القدراتُ المخزّنةُ (لحفظِ ما يفوق رتبةً)
     * @return array<string,array{v: ?bool, src: string}>
     */
    public static function capabilities(array $info, array $existing = []): array
    {
        $mode     = is_string($info['mode'] ?? null) ? mb_strtolower(trim($info['mode'])) : null;
        $modeCap  = self::MODE_MAP[$mode] ?? null;
        $modeKnown = $modeCap !== null;

        $out = [];
        foreach (self::CAPABILITIES as $cap) {
            if (self::outranks($existing[$cap] ?? null, 'litellm')) {
                $out[$cap] = $existing[$cap];
                continue;
            }

            // ① الوضعُ الحصريُّ — والوضعُ المجهولُ لا يُنتج `false`
            if (in_array($cap, self::MODE_MAP, true)) {
                $out[$cap] = $modeKnown
                    ? Tri::fact($cap === $modeCap, 'litellm')
                    : Tri::fact(null, 'unknown');
                continue;
            }

            // ② عَلَمٌ صريح — والغائبُ يبقى مجهولاً
            $flag = self::FLAG_MAP[$cap] ?? null;
            $out[$cap] = ($flag !== null && array_key_exists($flag, $info))
                ? Tri::fact($info[$flag], 'litellm')
                : Tri::fact(null, 'unknown');
        }

        return $out;
    }

    // ── الحدود ─────────────────────────────────────────────────────────

    /** @return array<string,array{v: mixed, src: string}> */
    public static function limits(array $info, array $existing = []): array
    {
        $num = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        $ctx = $num($info['max_input_tokens'] ?? null) ?? $num($info['max_tokens'] ?? null);

        $raw = [
            'context_window'    => $ctx,
            'max_input_tokens'  => $num($info['max_input_tokens'] ?? null),
            'max_output_tokens' => $num($info['max_output_tokens'] ?? null),
            'max_images'        => null,
            'max_file_mb'       => null,
            'modalities_in'     => self::list($info['supported_modalities'] ?? null),
            'modalities_out'    => self::list($info['supported_output_modalities'] ?? null),
            'mime_types_in'     => null,
            // **تبقى مجهولةً عن قصد** (§٧): حدودُ المعدّلِ خاصّةٌ بالحسابِ والفئة،
            // ولا تُقرأ بموثوقيّةٍ من واجهةٍ عامّة. ورقمٌ مخمَّنٌ هنا أسوأُ من
            // «غيرُ معلوم» — المديرُ يخطّط على كذبة.
            'rate_limits'       => null,
        ];

        $out = [];
        foreach ($raw as $k => $v) {
            if (self::outranks($existing[$k] ?? null, 'litellm')) { $out[$k] = $existing[$k]; continue; }
            $out[$k] = self::val($v, $v === null ? 'unknown' : 'litellm');
        }

        return $out;
    }

    // ── الوسائط ────────────────────────────────────────────────────────

    /**
     * **قائمةٌ حاضرةٌ تُنتج `false` صادقاً؛ وقائمةٌ غائبةٌ تُبقي الكلَّ مجهولاً.**
     *
     * ‏`supported_openai_params` تعدادٌ صريح: وسيطٌ خارجَها **غيرُ مدعومٍ لهذا
     * النشر**، لا مجهولُ الحال. أمّا غيابُ الحقلِ كلِّه فلا يقول شيئاً.
     *
     * @return array<string,array{supported: ?bool, src: string}>
     */
    public static function params(array $info, array $existing = []): array
    {
        $listed  = self::list($info['supported_openai_params'] ?? null);
        $present = $listed !== null;
        $set     = array_flip(array_map('strval', $listed ?? []));

        $out = [];
        foreach (self::PARAM_VOCABULARY as $p) {
            if (self::outranks($existing[$p] ?? null, 'litellm')) { $out[$p] = $existing[$p]; continue; }
            $out[$p] = $present
                ? ['supported' => isset($set[$p]), 'src' => 'litellm']
                : ['supported' => null, 'src' => 'unknown'];
        }

        return $out;
    }

    // ── الأسعار ────────────────────────────────────────────────────────

    /**
     * **ولا رقمَ بلا وحدة.** العملةُ والوحدةُ ووقتُ الجلبِ تُخزَّن دائماً —
     * ورقمٌ بلا وحدةٍ كذبةٌ تنتظر.
     *
     * **والعملةُ `USD` ليست افتراضاً منّا:** خريطةُ التكلفةِ المرفقةُ بالحزمة
     * (`model_prices_and_context_window_backup.json`) بالدولار، وهي مصدرُ
     * `/model/info`. فالوسمُ يقول من أين جاء الرقمُ لا ما نتمنّاه.
     */
    public static function pricing(array $info, array $existing = [], ?string $fetchedAt = null): array
    {
        $out = [];

        foreach (self::PRICE_PER_1K as $k => $src) {
            if (self::outranks($existing[$k] ?? null, 'litellm')) { $out[$k] = $existing[$k]; continue; }
            $v = is_numeric($info[$src] ?? null) ? (float) $info[$src] * 1000 : null;
            $out[$k] = self::val($v, $v === null ? 'unknown' : 'litellm');
        }

        foreach (self::PRICE_PER_UNIT as $k => $src) {
            if (self::outranks($existing[$k] ?? null, 'litellm')) { $out[$k] = $existing[$k]; continue; }
            $v = is_numeric($info[$src] ?? null) ? (float) $info[$src] : null;
            $out[$k] = self::val($v, $v === null ? 'unknown' : 'litellm');
        }

        if (self::outranks($existing['batch_discount'] ?? null, 'litellm')) {
            $out['batch_discount'] = $existing['batch_discount'];
        } else {
            $out['batch_discount'] = self::val(null, 'unknown');
        }

        $out['currency']   = 'USD';
        $out['unit']       = 'per_1k_tokens';
        $out['fetched_at'] = $fetchedAt ?? now()->toIso8601String();

        return $out;
    }

    // ── التجاوزُ اليدويّ ────────────────────────────────────────────────

    /**
     * **قرارُ المديرِ يعلو الكلَّ ولا يُدهَس بتحديث** — ويُوسَم بمن ومتى.
     *
     * @return array{v: mixed, src: string, by: ?string, at: string}
     */
    public static function override(mixed $value, ?string $by = null): array
    {
        return [
            'v'   => $value,
            'src' => 'hub_override',
            'by'  => $by,
            'at'  => now()->toIso8601String(),
        ];
    }

    /** أَيفوق مصدرُ الحقيقةِ المخزّنةِ رتبةَ القادم؟ — فيُحفَظ ولا يُدهَس */
    public static function outranks(mixed $existingFact, string $incomingSource): bool
    {
        if (! is_array($existingFact)) return false;

        $old = array_search((string) ($existingFact['src'] ?? 'unknown'), Tri::SOURCES, true);
        $new = array_search($incomingSource, Tri::SOURCES, true);

        return $old !== false && $new !== false && $old > $new;
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /** حقيقةٌ بقيمةٍ غيرِ منطقيّة — `Tri::fact` تُحوّل الرقمَ إلى `true` فلا تصلح */
    private static function val(mixed $value, string $source): array
    {
        return ['v' => $value, 'src' => in_array($source, Tri::SOURCES, true) ? $source : 'unknown'];
    }

    /** قائمةٌ أو `null` — وما ليس قائمةً ليس قائمةً فارغة */
    private static function list(mixed $v): ?array
    {
        return is_array($v) && $v !== [] ? array_values($v) : null;
    }
}

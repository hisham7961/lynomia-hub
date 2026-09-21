<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * **طبقةُ نقلِ التوليدِ الواحدة** — `POST /v1/chat/completions`. (المرحلة ٣ · جاهزيّةُ الإنتاج)
 *
 * **ولا اسمَ مزوّدٍ في هذا الملفِّ ولا في الطلبِ الذي يبنيه.** العقدُ الذي
 * تنفّذه هذه الطبقةُ عقدُ **البوّابةِ** لا عقدُ مزوّدٍ بعينِه: اسمُ
 * النموذجِ سلسلةٌ سجّلها المالكُ في البوّابة، والبوّابةُ وحدَها تعرف من
 * وراءَه. وثمرةُ ذلك أنّ مزوّداً يُضاف غداً يعمل **بلا سطرٍ يُكتَب هنا**.
 *
 * ── **ولمَ طبقةٌ ثانيةٌ و`AiProbes::generate()` قائمة؟** ──
 *
 * الفاحصُ يُثبِت **أنّ النموذجَ يُنتج**: مُحفِّزٌ ثابتٌ من الشيفرةِ وستّةَ عشرَ
 * رمزاً وردٌّ لا يُقرَأ منه إلّا طولُه. وهذه الطبقةُ **تُجري محادثةً**: أدواتٌ
 * وتسلسلُ رسائلَ ومعرّفاتُ نداءٍ وسببُ انتهاءٍ يُقرَأ ويُبنى عليه. فدمجُهما
 * كان سيحشو مسارَ الفحصِ الرخيصِ بتحليلٍ لا يحتاجه، **ويجعل سقفَ الستّةَ عشرَ
 * رمزاً — وهو حارسُ كلفةِ الفحص — يحكم المحادثةَ فيبتر كلَّ جواب**.
 *
 * ── **ثلاثةُ حرّاسٍ يمرّ بها كلُّ نداء** ──
 *
 *  ① **حارسُ الصادرِ الخماسيُّ** `AiGateway::outboundGate()` ثمّ تثبيتُ
 *     العنوانِ **وبلا اتّباعِ أيِّ تحويل** — فردُّ `302` لا يقود الطلبَ إلى
 *     هدفٍ لم يُفحَص.
 *
 *  ② **سقفُ المخرَجِ يُفرَض هنا ولا يُستقبَل.** الحمولةُ تُبنى في مكانٍ أعلى،
 *     **والسقفُ يُعاد فرضُه بعد الدمج**: فلا حمولةٌ تمرّ سقفاً أعلى ممّا أقرّته
 *     السياسة، ولو كان مصدرُها خطأً في شيفرتِنا.
 *
 *  ③ **`stream` مُطفأٌ قسراً.** البثُّ يبني طلبَ الأداةِ من `delta` بفهارسَ
 *     متتابعة — سطحُ تحليلٍ إضافيٌّ في مسارٍ **مدخلُه غيرُ موثوق** — ولا يشتري
 *     شيئاً: الجوابُ يُعرَض دفعةً واحدةً بعد المصادقةِ على مراجعِه.
 *
 * ── **والتصنيفُ من العقدِ المقروءِ لا من الرمزِ وحدَه** ──
 *
 * قُرئ عقدُ الإصدارِ المثبَّتِ حرفاً (`docs/ai-hub/24-litellm-chat-contract.md`)
 * فكشف مصيدتين لا يراهما من بنى على «الواجهةِ القياسيّةِ المتوافقة» بلا قراءة:
 *
 *  · **تجاوزُ نافذةِ السياقِ يعود `400`** — `ContextWindowExceededError` يرث
 *    `BadRequestError` (`exceptions.py:504`). فالرمزُ وحدَه يقول «طلبٌ فاسد»
 *    عن سؤالٍ كلُّ عيبِه أنّه احتاج سياقاً أوسع.
 *
 *  · **`429` ليست دائماً حدَّ معدّل** — `_types.py:3855` يفرض الرمزَ نفسَه على
 *    «‏`No healthy deployment available`»، أي على **انقطاعِ خدمةٍ**. ولو
 *    صُنِّف حدَّ معدّلٍ لقيل للمستخدمِ «انتظر قليلاً» عن عطلٍ لا يُصلحه
 *    الانتظار — **وهو بعينِه العيبُ الذي تفصله `AskFailures` أصلاً**.
 */
final class AiChat
{
    public const PATH = '/v1/chat/completions';

    /** الشكلُ الموحَّدُ للردّ — لا شكلَ يُخترَع في مُستدعٍ */
    public const SHAPE = ['ok', 'status', 'failure', 'cause', 'data',
                          'error', 'retry_after', 'usage', 'ms', 'sent'];

    /** ما يُحتفَظ به من متنِ الخطأِ — بعد الطمسِ وللتصنيفِ لا للعرض */
    public const MAX_ERROR_CHARS = 300;

    /** **علاماتُ المتنِ** — من `exceptions.py` و`_types.py` حرفاً */
    public const MARK_CONTEXT   = ['contextwindowexceeded', 'context window', 'maximum context'];
    public const MARK_NO_DEPLOY = ['no healthy deployment', 'no deployments available'];
    public const MARK_TIMEOUT   = ['litellm.timeout'];
    public const MARK_POLICY    = ['contentpolicyviolation'];

    /**
     * **دلالاتُ نفادِ الرصيد** — نصُّ المزوّدِ الأصليُّ كما يصل.
     *
     * والدليلُ أنّه يصل: `exception_mapping_utils.py` يبني الرسالةَ
     * `"RateLimitError: {provider} - {message}"` — **فالمتنُ الأصليُّ ينجو
     * حرفيّاً**. والدلالاتُ عامّةٌ على المال لا على مزوّدٍ بعينِه.
     */
    public const MARK_CREDITS = ['no credits', 'credit balance', 'insufficient_quota',
                                 'insufficient credit', 'exceeded your current quota',
                                 'billing', 'add credits', 'payment required'];

    /** بادئةُ كلِّ استثناءٍ تولّده LiteLLM — ودلالتُها أنّ العطلَ **خلفَ** البوّابة */
    public const MARK_BEYOND_GATEWAY = 'litellm.';

    /**
     * **نداءٌ واحدٌ غيرُ مُبثوث** — ويعود بشكلٍ واحدٍ نجح أم أخفق.
     *
     * ── **و`sent` تقول: أغادر الطلبُ الخادمَ أصلاً؟** (تدقيقُ ما قبل المرحلة ٥) ──
     *
     * وهذه الطبقةُ **وحدَها** تعرف الجواب، فلا يُستنتَج في مُستدعٍ: رفضانِ
     * يقعان **قبل فتحِ أيِّ مقبس** — بوّابةٌ غيرُ جاهزةٍ ووجهةٌ يرفضها حارسُ
     * الصادر — **لا يبلغان أحداً ولا يكلّفان فلساً**، فحاملُهما `sent = false`
     * ويُفرَج عن حجزِ ميزانيّتِه ولا يُحسَب على حصّة.
     *
     * **وما عداهما `true`**، ومنه انقطاعُ النقلِ قصداً: مهلةٌ انقضت تعني أنّ
     * الطلبَ ربّما وصل وعُولج، **فالإفراجُ عنه يكذب**. و`cause = bad_request`
     * لا تكفي للتفريق — فردُّ ٤٠٠ من البوّابةِ سببُه `bad_request` أيضاً وقد
     * وقع وأُنفق.
     *
     * @param  array  $body              حمولةُ الطلبِ كما بُنيت أعلى
     * @param  int    $maxOutputTokens   **السقفُ المُقرّ** — يُفرَض هنا لا يُقترَح
     * @return array{ok:bool, status:?int, failure:?string, cause:?string, data:?array,
     *               error:?string, retry_after:?int, usage:array, ms:int, sent:bool}
     */
    public static function complete(array $body, int $maxOutputTokens): array
    {
        $t0 = microtime(true);

        if (! AiGateway::enabled()) {
            return self::fail(AskFailures::UNAVAILABLE, 'bad_request', null,
                AiGateway::whyNotReady() ?? 'بوّابةُ النماذجِ غيرُ جاهزة', $t0, false);
        }

        $url  = AiGateway::url(self::PATH);
        $gate = AiGateway::outboundGate($url);
        if (! ($gate['ok'] ?? false)) {
            return self::fail(AskFailures::GATEWAY_FAILURE, 'bad_request', null,
                (string) ($gate['why'] ?? 'وجهةٌ مرفوضة'), $t0, false);
        }

        // ② السقفُ يُعاد فرضُه بعد الدمج · ③ البثُّ مُطفأٌ قسراً
        $payload = $body;
        $payload['max_tokens'] = max(1, min((int) ($payload['max_tokens'] ?? $maxOutputTokens),
            max(1, $maxOutputTokens)));
        $payload['stream'] = false;

        $to = AiGateway::timeouts();

        try {
            $res = Http::withOptions(AiGateway::requestOptions($gate['ip'], $url))
                ->connectTimeout($to['connect'])
                ->timeout($to['read'])
                ->withHeaders([
                    'User-Agent'    => 'LynomiaHub-Ask/1.0',
                    'Authorization' => 'Bearer ' . AiGateway::key(),
                    'Accept'        => 'application/json',
                ])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            /*
             * **انقطاعُ النقلِ ليس صنفاً واحداً.** مهلةٌ انقضت تعني أنّ الطلبَ
             * ربّما وصل وعُولج — فإعادتُه تُنفق مرّةً ثانية. ووصلةٌ مرفوضةٌ
             * تعني أنّ شيئاً لم يُنفَق. والرسالةُ تُطمَس قبل أن تُقرَأ: متنُ
             * استثناءِ نقلٍ قد يحمل عنواناً أو ترويسة.
             */
            $why = Redactor::text($e->getMessage());

            // والسببُ `transient` في الحالتين: كلاهما عطلٌ عابرٌ يحتمل الإعادةَ
            // بقرارِ `AiRouting`، والفرقُ في **ما يُقال للمستخدمِ** لا في التوجيه
            return self::fail(
                self::looksTimeout($why) ? AskFailures::TIMEOUT : AskFailures::GATEWAY_FAILURE,
                'transient', null, mb_substr($why, 0, self::MAX_ERROR_CHARS), $t0);
        }

        $status = $res->status();
        $ms     = (int) round((microtime(true) - $t0) * 1000);

        if ($status >= 200 && $status < 300) {
            $json = is_array($res->json()) ? $res->json() : [];

            return [
                'ok'          => true,
                'status'      => $status,
                'failure'     => null,
                'cause'       => null,
                'data'        => $json,
                'error'       => null,
                'retry_after' => null,
                'usage'       => self::usage($json, $res->header('x-litellm-response-cost')),
                'ms'          => $ms,
                'sent'        => true,
            ];
        }

        // **المتنُ يُطمَس ثمّ يُقصّ قبل أن يُقرَأ** — لا لأنّه يُعرَض، بل لأنّه
        // يُسجَّل في الأثرِ ويُصنَّف، وكلاهما بابٌ لتسريبٍ لو مرّ خاماً
        $raw   = Redactor::text((string) $res->body());
        $clip  = mb_substr($raw, 0, self::MAX_ERROR_CHARS);
        $after = $res->header('retry-after');

        return [
            'ok'          => false,
            'status'      => $status,
            'failure'     => self::classify($status, $raw),
            'cause'       => AiRouting::classify(['code' => $status, 'body' => $raw]),
            'data'        => null,
            'error'       => 'HTTP ' . $status . ($clip === '' ? '' : ' · ' . $clip),
            'retry_after' => is_numeric($after) ? max(1, (int) $after) : null,
            'usage'       => [],
            'ms'          => $ms,
            'sent'        => true,
        ];
    }

    /**
     * **من (الرمزِ · المتنِ) إلى تصنيفِ الإخفاق** — والقاعدةُ واحدةٌ مقروءةٌ
     * من العقد، والاستثناءاتُ فوقَها معدودةٌ ومُبرَّرة.
     *
     * **القاعدة:** متنٌ يحمل بادئةَ `litellm.` معناه أنّ العطلَ وقع **خلفَ**
     * البوّابةِ — عند المزوّدِ أو النموذج — فهو `PROVIDER_FAILURE`. وما لا
     * يحملها ردَّته البوّابةُ من عندِها فهو `GATEWAY_FAILURE`. وهذا يفرّق
     * **مفتاحَ إدارةٍ مرفوضاً عندنا** من **اعتمادِ مزوّدٍ مرفوضٍ عنده**، وكلاهما
     * يعود `401`.
     *
     * والافتراضُ عند الشكِّ `GATEWAY_FAILURE`: أقربُ إلى «عطلٌ في خدمةٍ»،
     * **ولا يُلمِّح البتّةَ إلى صلاحيّةٍ ناقصة**.
     */
    public static function classify(int $status, string $body): string
    {
        $b = mb_strtolower($body);

        // ① المهلةُ أوّلاً — رمزُها ٤٠٨ وقد تصل بمتنِها على رمزٍ آخر
        if ($status === 408 || self::has($b, self::MARK_TIMEOUT)) return AskFailures::TIMEOUT;

        // ⓪ **الدفعُ المطلوبُ رمزٌ صريحٌ للمال** — ولا لبسَ فيه
        if ($status === 402) return AskFailures::PROVIDER_CREDITS;

        /*
         * ② **‏٤٢٩ ثلاثةُ معانٍ لا معنىً واحد** — ويفرّقها المتنُ لا الرمز:
         *
         *  · «لا نشرَ صالحاً» ⇒ انقطاعُ خدمةٍ خلفَ البوّابة.
         *  · «لا رصيدَ / فوترة» ⇒ **مالٌ نفد، ولا ينقضي بانتظار**.
         *  · وما عداهما ⇒ حدُّ معدّلٍ حقيقيٌّ ينقضي بمهلتِه.
         *
         * **والترتيبُ مقصود:** «لا نشرَ صالحاً» أوّلاً لأنّها جملةُ البوّابةِ
         * نفسِها ولا تحمل مالاً، ثمّ الرصيدُ، ثمّ الافتراضُ الأوسع.
         */
        if ($status === 429) {
            if (self::has($b, self::MARK_NO_DEPLOY))  return AskFailures::PROVIDER_FAILURE;
            if (self::has($b, self::MARK_CREDITS))    return AskFailures::PROVIDER_CREDITS;

            return AskFailures::RATE_LIMITED;
        }

        // ③ تجاوزُ السياقِ يصل ٤٠٠ — فالمتنُ هو ما يفرّقه عن طلبٍ فاسدِ الشكل
        if ($status === 400 && self::has($b, self::MARK_CONTEXT)) return AskFailures::CONTEXT_LIMIT;

        // ④ حجبُ المحتوى نتيجةٌ لا عطل — ولا يُقال للمستخدمِ «ردٌّ غيرُ مفهوم»
        if (self::has($b, self::MARK_POLICY)) return AskFailures::CONTENT_FILTERED;

        return str_contains($b, self::MARK_BEYOND_GATEWAY)
            ? AskFailures::PROVIDER_FAILURE
            : AskFailures::GATEWAY_FAILURE;
    }

    /**
     * **ما يُقرأ من الاستهلاك** — ثلاثةُ أعدادٍ واسمُ نموذجٍ وكلفةٌ من الترويسة.
     *
     * والكلفةُ تعود **مع الردِّ نفسِه** في `x-litellm-response-cost`
     * (`common_request_processing.py:1621`) فلا تُقرَأ من جدولِ إنفاقٍ منفصلٍ
     * قد يتأخّر أو يُصدَّق خطأً.
     *
     * **ولا مفتاحَ يُفترَض وجودُه**: البوّابةُ تُسلسِل بـ`exclude_unset=True`،
     * فالحقلُ غيرُ المضبوطِ **يغيب من JSON** ولا يصل `null`.
     */
    public static function usage(array $json, mixed $costHeader = null): array
    {
        $u    = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $cost = is_numeric($costHeader) ? (float) $costHeader
            : (is_numeric($u['cost'] ?? null) ? (float) $u['cost'] : null);

        $out = [
            'model'      => is_string($json['model'] ?? null) ? $json['model'] : null,
            'prompt'     => isset($u['prompt_tokens']) ? (int) $u['prompt_tokens'] : null,
            'completion' => isset($u['completion_tokens']) ? (int) $u['completion_tokens'] : null,
            'tokens'     => isset($u['total_tokens']) ? (int) $u['total_tokens'] : null,
        ];
        if ($cost !== null) $out['cost'] = $cost;

        return array_filter($out, static fn ($v) => $v !== null);
    }

    // ── الداخل ────────────────────────────────────────────────────────

    private static function has(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) return true;
        }

        return false;
    }

    private static function looksTimeout(string $why): bool
    {
        $w = mb_strtolower($why);

        return str_contains($w, 'timed out') || str_contains($w, 'timeout');
    }

    /**
     * @param  bool  $sent  **أغادر الطلبُ الخادمَ؟** — والافتراضُ `true` هو
     *   الجانبُ المُحافظ: ما قد يكون وصل يُحاسَب، وما ثبت أنّه لم يُرسَل وحدَه
     *   يُفرَج عنه.
     */
    private static function fail(string $failure, string $cause, ?int $status,
                                 string $error, float $t0, bool $sent = true): array
    {
        return [
            'ok'          => false,
            'status'      => $status,
            'failure'     => $failure,
            'cause'       => $cause,
            'data'        => null,
            'error'       => $error,
            'retry_after' => null,
            'usage'       => [],
            'ms'          => (int) round((microtime(true) - $t0) * 1000),
            'sent'        => $sent,
        ];
    }
}

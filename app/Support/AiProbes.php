<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * **الفواحصُ الخمسة A–E** (المرحلة ٢ · W6).
 *
 * يرث `ConnectionProbe` عمداً لا نسخاً: الشكلُ `SHAPE` نفسُه، و`row()` هي
 * **نقطةُ الاختناقِ الوحيدةُ** التي تصنع `error` (فتطمس وتقصّ)، و`line()`
 * تقرأ النتيجةَ بحرفِها. فلا شكلَ ثانٍ لفحصٍ ولا مسارَ يلتفّ حول المُطهِّر.
 *
 * ── **ما يُثبِته كلُّ مستوى — وكلفتُه** ──
 *
 * | | يُثبِت | الكلفة | إقرار |
 * |---|---|---|---|
 * | **A** | البوّابةُ حيّةٌ وتقبل مفتاحَ الإدارة | **صفر** | لا |
 * | **B** | **المزوّدُ يقبل مفتاحَنا على نموذجٍ بعينِه** | **يُنفق** | **نعم** |
 * | **C** | النموذجُ مُسجَّلٌ وقابلٌ للبلوغ | **صفر** | لا |
 * | **D** | النموذجُ **يُولّد فعلاً** | يُنفق | **نعم** |
 * | **E** | قدرةٌ بعينها تعمل | أعلى | **نعم** |
 *
 * **وB كانت تُحسَب مجّانيّةً في أوّلِ الخطّة (الفجوة G3) — وأغلقها W0 بالعكس.**
 * تتبُّعُ `POST /health/test_connection` انتهى إلى `litellm.ahealth_check` ثمّ
 * إلى مُشغّلاتِ الأوضاع: `chat` ⇒ `acompletion` · `embedding` ⇒ `aembedding` ·
 * `image_generation` ⇒ **صورةٌ حقيقيّة**؛ وقبلها `cache = {"no-cache": True}`
 * **عمداً** كي لا يُجاب من مخزَّن. والنتيجةُ العمليّة تُقال صريحةً:
 * **لا توجد طريقةٌ مجّانيّةٌ للتحقّقِ من أنّ مفتاحَ مزوّدٍ صحيح.**
 *
 * ── **ثوابتُ D وE (§١٢ من الخطّة)** ──
 *
 *  ① **سقفٌ صلبٌ على `max_tokens`** لا يُتجاوَز ولا يُؤخَذ من مُدخَل.
 *  ② **مُحفِّزٌ ثابتٌ محايدٌ من الشيفرة** — **لا مُدخلَ مستخدمٍ يُرسَل لنموذج**.
 *  ③ **المخرجُ لا يُخزَّن**: الطولُ والبصمةُ وأوّلُ ٦٤ محرفاً بعد `Redactor`.
 *  ④ مهلةٌ من `AiGateway::timeouts()`، و`throttle` على المسار.
 *
 * ── **والفشلُ لا يُثبِت النفي** ──
 *
 * فحصُ قدرةٍ ناجحٌ يكتب `verified` — وهو **المصدرُ الوحيدُ** الذي يكتبها.
 * أمّا الفاشلُ فلا يكتب `false`: الطلبُ قد يسقط لمهلةٍ أو حدِّ معدّلٍ أو عطلٍ
 * عابر، فجعلُ ذلك «لا يدعم» يُغلق باباً مفتوحاً بدليلٍ لا يخصّ القدرة.
 */
final class AiProbes extends ConnectionProbe
{
    public const LEVELS = ['A', 'B', 'C', 'D', 'E'];

    /** المستوياتُ التي تُنفق رصيداً — تُعلَن ولا تُخفى */
    public const PAID = ['B', 'D', 'E'];

    /** **سقفٌ صلب** — لا يُؤخَذ من طلبٍ ولا من إعداد */
    public const MAX_OUTPUT_TOKENS = 16;

    /** ما يُحتفَظ به من المخرَج — عيّنةٌ للتشخيصِ لا نصٌّ يُخزَّن */
    public const SAMPLE_CHARS = 64;

    /** **مُحفِّزٌ ثابتٌ من الشيفرة** — لا مُدخلَ مستخدمٍ يبلغ نموذجاً */
    public const PROMPT = 'Reply with the single word: ok';

    /** القدراتُ التي يستطيع المستوى E إثباتَها بطلبٍ صغير */
    public const PROBABLE = ['tools', 'structured_output', 'vision', 'reasoning'];

    /** أَيُنفق هذا المستوى؟ — تقرؤه الشاشةُ فتطلب الإقرارَ قبل الزرّ */
    public static function isPaid(string $level): bool
    {
        return in_array(mb_strtoupper($level), self::PAID, true);
    }

    // ═══ A — البوّابة (كلفةٌ صفر) ═══

    /**
     * **لا يُبنى ما بُني.** فاحصُ المرحلةِ الأولى يفعل المستوى A حرفاً بحرف:
     * `GET /v1/models` يُثبِت الحياةَ **وقبولَ مفتاحِ الإدارة** معاً.
     */
    public static function a(): array
    {
        return self::litellm();
    }

    // ═══ C — النموذج (كلفةٌ صفر) ═══

    /** **مُسجَّلٌ عند البوّابةِ وقابلٌ للبلوغ؟** — قراءةٌ من `/model/info` */
    public static function c(AiModel $model): array
    {
        $t0  = microtime(true);
        $res = LiteLlmAdmin::modelInfo();

        if (! $res['ok']) {
            return self::row($res['code'] === null ? null : false, $res['code'],
                self::since($t0), (string) $res['error']);
        }

        $name  = (string) $model->litellm_model_name;
        $found = false;
        foreach ((array) (($res['data']['data'] ?? $res['data']) ?: []) as $entry) {
            if (is_array($entry) && (string) ($entry['model_name'] ?? '') === $name) { $found = true; break; }
        }

        return self::row($found, $res['code'], self::since($t0),
            $found ? null : 'البوّابةُ لا تُعلن هذا النموذج — سُجِّل خارجَ Hub أو حُذف منها',
            $found ? 'النموذجُ مُسجَّلٌ وقابلٌ للبلوغ' : null,
            ['model' => $name]);
    }

    // ═══ B — الاعتماد (يُنفق) ═══

    /**
     * **يُنفق — ولا يُنفَّذ بلا إقرارٍ صريح.**
     *
     * والوضعُ يُمرَّر ولا يُستنتَج: الاستنتاجُ قد يقع على وضعٍ أغلى بكثير
     * (توليدُ صورةٍ بدل إكمالِ نصّ).
     */
    public static function b(AiModel $model, string $mode, bool $costAcknowledged = false): array
    {
        if (! $costAcknowledged) {
            return self::row(null, null, null,
                'فحصُ الاعتمادِ يُنفق رصيداً — يلزم إقرارٌ صريحٌ بالكلفة قبل تنفيذِه');
        }

        $provider = $model->provider;
        if ($provider === null) {
            return self::row(null, null, null, 'النموذجُ بلا مزوّد');
        }
        if ((string) $provider->credential_state === 'missing') {
            return self::row(null, null, null, 'لا اعتمادَ لهذا المزوّد — اضبطه أوّلاً');
        }

        /*
         * **ولا يُرسَل اسمُ Hub الداخليُّ مكانَ اسمِ المزوّد.**
         *
         * `litellm_model_name` اسمٌ نُسمّي به النموذجَ عندنا (`hub-general`)،
         * و`upstream_model` اسمُه عند المزوّد (`gpt-4o-mini`). والمزوّدُ لا
         * يعرف أسماءَنا: إرسالُ الأوّلِ يُنتج فشلاً **يبدو مفتاحاً خاطئاً وهو
         * خطأُ تسمية** — فيُطارَد اعتمادٌ سليمٌ يوماً كاملاً.
         *
         * فالنقصُ يُقال بصراحةٍ ولا يُسَدّ بتخمين.
         */
        $upstream = trim((string) $model->upstream_model);
        if ($upstream === '') {
            return self::row(null, null, null,
                'لا اسمَ النموذجِ عند المزوّد لهذا السجلّ — والفحصُ يختبر '
                . '(اعتماداً × نموذجاً) لا اعتماداً وحدَه. اضبط الاسمَ ثمّ أعِد الفحص');
        }

        $t0  = microtime(true);
        $res = LiteLlmAdmin::testConnection([
            'model'                   => $upstream,
            'litellm_credential_name' => (string) $provider->credential_name,
        ], $mode, true);

        /*
         * **ونجاحٌ مدفوعٌ يترك أثراً.**
         *
         * كانت حالةُ الاعتمادِ تبقى `configured` مهما نجح B — فالفحصُ يُنفق
         * ثمّ **لا يُذكَر أنّه جرى**، فيُعاد غداً بلا داعٍ ويُنفَق مرّتين،
         * وسلّمُ القبولِ لا يتقدّم درجةً واحدة.
         *
         * **والفشلُ لا يُنزل الحالةَ**: قد يكون اسمَ نموذجٍ خاطئاً لا مفتاحاً،
         * وإنزالُها يُرسل المالكَ يُدوّر اعتماداً سليماً.
         */
        if ($res['ok']) {
            $provider->forceFill(['credential_state' => AiCatalog::secretState(true, true)])->save();
        }

        return self::row($res['ok'], $res['code'], self::since($t0),
            $res['ok'] ? null : (string) $res['error'],
            $res['ok'] ? 'المزوّدُ قبل اعتمادَنا على هذا النموذج' : null,
            ['mode' => $mode, 'model' => $upstream]);
    }

    // ═══ D — توليدٌ أدنى (يُنفق) ═══

    /**
     * **أصغرُ توليدٍ يُثبِت أنّ النموذجَ يُنتج فعلاً.**
     *
     * وهو ما يكتب `ai.generation_ok` و`ai.generation_fp` — **مفتاحانِ موجودان
     * في كتالوجِ الإعدادات منذ المرحلة ١ ولم يُكتبا قطّ**. وبهما تصير الدرجةُ
     * الرابعةُ في شاشةِ المركز صادقةً: «توليدٌ تحقّق» لا تُقال إلّا هنا.
     *
     * والبصمةُ `AiGateway::fingerprint()` نفسُها — فتُبطَل تلقائيّاً بأيِّ تغييرٍ
     * في عنوانِ البوّابةِ أو مفتاحِها، ولا تبقى شهادةً على إعدادٍ زال.
     */
    public static function d(AiModel $model, bool $costAcknowledged = false): array
    {
        if (! $costAcknowledged) {
            return self::row(null, null, null,
                'التوليدُ يُنفق رصيداً — يلزم إقرارٌ صريحٌ بالكلفة قبل تنفيذِه');
        }
        if ($why = self::notReady($model)) return self::row(null, null, null, $why);

        $r = self::generate($model, [
            'messages'   => [['role' => 'user', 'content' => self::PROMPT]],
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
        ]);

        if ($r['up'] === true) {
            // **الشهادةُ تُكتب هنا وحدَها** — وبالبصمةِ التي تُبطلها أيُّ تغيير
            Settings::batch('ai', function () {
                Settings::put('ai.generation_ok', true, 'ai');
                Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'ai');
            }, ['name' => 'ai.generation_* — أثبتَه فحصُ التوليدِ الأدنى (D)']);

            hub_audit('فحص توليد ناجح', AiModel::MODULE, (string) $model->id,
                (string) $model->display_name, ['after' => Redactor::arr($r['detail'])]);
        }

        return $r;
    }

    // ═══ E — قدرةٌ بعينها (يُنفق · أعلى) ═══

    /**
     * **الناجحُ وحدَه يكتب `verified`** — والفاشلُ لا يكتب `false`.
     *
     * لأنّ الطلبَ قد يسقط لمهلةٍ أو حدِّ معدّلٍ أو عطلٍ عابر، فجعلُ ذلك «لا
     * يدعم» يُغلق باباً مفتوحاً **بدليلٍ لا يخصّ القدرةَ أصلاً**.
     */
    public static function e(AiModel $model, string $capability, bool $costAcknowledged = false): array
    {
        $capability = mb_strtolower(trim($capability));

        if (! in_array($capability, self::PROBABLE, true)) {
            return self::row(null, null, null, 'لا فحصَ مباشرٌ لهذه القدرة — تُثبَت بالاستعمالِ لا بطلبٍ صغير');
        }
        if (! $costAcknowledged) {
            return self::row(null, null, null,
                'فحصُ القدرةِ يُنفق رصيداً — يلزم إقرارٌ صريحٌ بالكلفة قبل تنفيذِه');
        }
        if ($why = self::notReady($model)) return self::row(null, null, null, $why);

        $r = self::generate($model, self::payloadFor($capability), $capability);

        if ($r['up'] === true) {
            AiModels::recordVerified($model, $capability);
        }

        return $r;
    }

    /** حمولةُ الطلبِ لكلِّ قدرة — بشكلِ واجهةِ البوّابةِ لا بمعرفةِ مزوّد */
    private static function payloadFor(string $capability): array
    {
        $base = ['max_tokens' => self::MAX_OUTPUT_TOKENS];

        return match ($capability) {
            'tools' => $base + [
                'messages' => [['role' => 'user', 'content' => 'Call the ping tool.']],
                'tools'    => [[
                    'type'     => 'function',
                    'function' => ['name' => 'ping', 'description' => 'health probe',
                                   'parameters' => ['type' => 'object', 'properties' => new \stdClass()]],
                ]],
                'tool_choice' => 'required',
            ],
            'structured_output' => $base + [
                'messages'        => [['role' => 'user', 'content' => 'Reply with {"ok":true} as JSON.']],
                'response_format' => ['type' => 'json_object'],
            ],
            'vision' => $base + [
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Reply with the single word: ok'],
                    // بكسلٌ واحدٌ شفّافٌ **من الشيفرة** — لا ملفَّ مستخدمٍ يُرفَع لنموذج
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'
                        . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==']],
                ]]],
            ],
            'reasoning' => $base + [
                'messages'         => [['role' => 'user', 'content' => self::PROMPT]],
                'reasoning_effort' => 'low',
            ],
            default => $base + ['messages' => [['role' => 'user', 'content' => self::PROMPT]]],
        };
    }

    // ── المحرّك ────────────────────────────────────────────────────────

    /** أَجاهزٌ للتوليد؟ — ولا يُنفَّذ على نموذجٍ لا يقبل التشغيلَ أصلاً */
    private static function notReady(AiModel $model): ?string
    {
        if (! AiGateway::configured()) return AiGateway::whyNotReady() ?? 'البوّابةُ غيرُ مهيّأة';

        $p = $model->provider;
        if ($p === null) return 'النموذجُ بلا مزوّد';
        if ((string) $p->credential_state === 'missing') return 'مزوّدُ النموذجِ بلا اعتماد';

        return null;
    }

    /**
     * **النداءُ المُنفِق الوحيدُ في المرحلة ٢** — ويمرّ بما يمرّ به كلُّ نداء:
     * حارسُ الصادرِ الخماسيّ، وتثبيتُ العنوان، ولا اتّباعَ تحويل، ومهلةٌ من
     * الإعدادات. **والسقفُ يُفرَض هنا لا يُستقبَل.**
     */
    private static function generate(AiModel $model, array $payload, ?string $capability = null): array
    {
        $url  = AiGateway::url('/v1/chat/completions');
        $gate = AiGateway::outboundGate($url);
        if (! $gate['ok']) return self::row(null, null, null, $gate['why']);

        $to = AiGateway::timeouts();

        // **السقفُ يُعاد فرضُه بعد الدمجِ** — فلا حمولةٌ تُمرّر سقفاً أعلى
        $body = $payload + ['model' => (string) $model->litellm_model_name];
        $body['max_tokens'] = min((int) ($body['max_tokens'] ?? self::MAX_OUTPUT_TOKENS), self::MAX_OUTPUT_TOKENS);

        $t0 = microtime(true);
        try {
            $res = Http::withOptions(AiGateway::requestOptions($gate['ip'], $url))
                ->connectTimeout($to['connect'])->timeout($to['read'])
                ->withHeaders([
                    'User-Agent'    => 'LynomiaHub-Probe/1.0',
                    'Authorization' => 'Bearer ' . AiGateway::key(),
                    'Accept'        => 'application/json',
                ])
                ->post($url, $body);
        } catch (\Throwable $e) {
            return self::row(false, null, self::since($t0), $e->getMessage());
        }

        $ms   = self::since($t0);
        $code = $res->status();
        $ok   = $code >= 200 && $code < 300;

        if (! $ok) {
            return self::row(false, $code, $ms, 'ردَّت البوّابةُ HTTP ' . $code . ' · '
                . mb_substr((string) $res->body(), 0, 180));
        }

        $json    = is_array($res->json()) ? $res->json() : [];
        $choice  = (array) (($json['choices'][0] ?? []) ?: []);
        $message = (array) (($choice['message'] ?? []) ?: []);
        $text    = is_string($message['content'] ?? null) ? $message['content'] : '';
        $calls   = (array) ($message['tool_calls'] ?? []);

        // القدرةُ لا تُعلَن مُثبَتةً بمجرّدِ ٢٠٠ — يُقرَأ **ما يُثبِتها** في الردّ
        $proved = match ($capability) {
            'tools'             => $calls !== [],
            'structured_output' => $text !== '' && json_decode($text, true) !== null,
            default             => $text !== '' || $calls !== [],
        };

        // **لا مخرجَ يُخزَّن** — طولٌ وبصمةٌ وعيّنةٌ مطموسةٌ لا غير
        $detail = [
            'len'    => mb_strlen($text),
            'fp'     => $text === '' ? null : Redactor::fingerprint($text),
            'sample' => mb_substr(Redactor::text($text), 0, self::SAMPLE_CHARS),
            'usage'  => array_intersect_key((array) ($json['usage'] ?? []),
                array_flip(['prompt_tokens', 'completion_tokens', 'total_tokens'])),
        ];
        if ($capability !== null) $detail['capability'] = $capability;

        return self::row($proved, $code, $ms,
            $proved ? null : 'ردَّت البوّابةُ بنجاحٍ ولم يحمل الردُّ ما يُثبِت المطلوب',
            $proved ? ($capability === null ? 'وُلِّدت إجابةٌ فعليّة' : 'القدرةُ مُثبَتةٌ باختبارٍ حقيقيّ') : null,
            $detail);
    }
}

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

    /**
     * **القدراتُ التي يستطيع المستوى E إثباتَها بطلبٍ صغير** — ولا رابعةَ لها.
     *
     * ── **ولماذا خرجت الرؤيةُ من القائمة؟** ──
     *
     * لأنّ **لا حقلَ في عقدِ الردِّ المقيسِ يفرّق «رأى الصورة» من «لم يرها»**.
     * الردُّ نصٌّ كأيِّ نصّ، وبكسلٌ شفّافٌ واحدٌ لا يحمل ما يُسأل عنه. فكان
     * الزرُّ يَعِد بإثباتٍ **لا يستطيعه**، ويُنفق ثمنَه، ثمّ يُسقط `default`
     * على دليلِ D نفسِه — **فصار E تكرارَ D باسمٍ آخر**.
     *
     * والرؤيةُ تبقى في سجلِّ القدراتِ تُقرأ من البوّابةِ وتُثبَت **بالاستعمال**،
     * كسائرِ ما لا يُثبِته طلبٌ صغير.
     */
    public const PROBABLE = ['tools', 'structured_output', 'reasoning'];

    /**
     * **أسبابُ الانتهاءِ المعروفةُ — من التعدادِ المقيسِ حرفاً.**
     *
     * مقيسةٌ من تعدادِ أسبابِ الانتهاءِ في `types/llms/` بالحزمةِ المثبَّتة
     * (السطر ٢٣٣٦) — **والموضعُ كاملاً في `docs/ai-hub/33-probe-evidence-contract.md`**
     * لا هنا: حارسُ `AiCatalogFoundationTest` يمنع اسمَ مزوّدٍ في `app/` **حتّى
     * في تعليق**، وهو محقّ — فمسارُ ملفٍّ يحمل اسمَ مزوّدٍ داخلَ طبقةِ التحكّمِ
     * بدايةُ تسرّبٍ لا استشهادٌ بريء. وسببٌ خارجَ التعدادِ يعني
     * أنّ الجسمَ ليس إكمالَ محادثةٍ يفهمه هذا العقد.
     */
    public const FINISH_REASONS = [
        'stop', 'content_filter', 'function_call', 'tool_calls', 'length',
        'guardrail_intervened', 'eos', 'finish_reason_unspecified', 'malformed_function_call',
    ];

    /** هويّةُ جسمِ الردّ — `ModelResponse.__init__` يفرضها فرضاً */
    public const OBJECT = 'chat.completion';

    /** أحكامُ الدليلِ — وكلٌّ منها يُترجَم إلى `up` مختلفة */
    public const PROVEN     = 'proven';      // → up = true
    public const NOT_PROVEN = 'not_proven';  // → up = null  (**جرى ولم يُثبَت**)
    public const FILTERED   = 'filtered';    // → up = null  (نتيجةٌ لها اسمُها)
    public const MALFORMED  = 'malformed';   // → up = false (ليس إكمالَ محادثةٍ أصلاً)

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

        /*
         * ── **الفاحصُ المُنفِقُ يدخل سجلَّ الحوكمة** (المرحلة ٤) ──
         *
         * **وهذه فجوةٌ كشفها قبولُ الإنتاجِ نفسُه:** جرى D وE على المزوّد،
         * **وأُنفق ثمنُهما**، ولم يبقَ في Hub رقمٌ واحدٌ يقول ذلك — لا صفَّ
         * استهلاكٍ ولا أثرَ تدقيق (الأثرُ كان يُكتَب عند النجاحِ وحدَه، وهما
         * لم يُسجَّلا نجاحاً). فنداءٌ مدفوعٌ خارجَ السجلِّ **إنفاقٌ لا يُرى**.
         *
         * والميزانيّةُ تسري عليه كما تسري على أيِّ توليد: فاحصٌ يتجاوز السقفَ
         * **لا يُنفَّذ**، ويُقال سببُه — ولا نداءَ يُنفَق قبل ذلك.
         */
        $gov   = AiGovernance::context(auth()->user(), 'probe', 'probe');
        $gov['hub_allowed'] = true;
        $inTok = (int) ceil(mb_strlen(json_encode($body, JSON_UNESCAPED_UNICODE) ?: '') / 4);
        $est   = AiCost::estimate((array) ($model->pricing ?? []), $inTok, (int) $body['max_tokens']);

        // **ولا يُشترَط التفعيلُ هنا** — الفاحصُ يُجرِّب ما لم يُوثَق به بعد
        $admit = AiGovernance::admit($gov, $model, $est, $inTok + (int) $body['max_tokens'], [
            'attempt' => 1, 'relation' => 'initial',
        ], false);

        if (! $admit['ok']) {
            // **لا نداءَ ولا كلفة** — والسببُ يُقال كما هو
            return self::row(null, null, null, (string) $admit['why']);
        }

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
            $ms = self::since($t0);
            self::settle($admit, null, $ms, AskFailures::GATEWAY_FAILURE, null, $model);

            return self::row(false, null, $ms, $e->getMessage());
        }

        $ms   = self::since($t0);
        $code = $res->status();
        $ok   = $code >= 200 && $code < 300;

        if (! $ok) {
            self::settle($admit, null, $ms,
                AiChat::classify($code, (string) $res->body()),
                $code, $model);

            return self::row(false, $code, $ms, 'ردَّت البوّابةُ HTTP ' . $code . ' · '
                . mb_substr((string) $res->body(), 0, 180));
        }

        $json = is_array($res->json()) ? $res->json() : [];
        $v    = self::verdict($json, $capability, (string) $model->litellm_model_name,
            (string) $model->upstream_model);

        /*
         * **ثلاثُ حالاتٍ لا اثنتان** — وهي لبُّ إصلاحِ هذا العيب:
         *
         *  · `true`  — الدليلُ قائم.
         *  · `false` — **الجسمُ ليس إكمالَ محادثةٍ أصلاً**: عيبٌ في الطرفِ
         *    الآخرِ يُقال صراحةً.
         *  · `null`  — **جرى النداءُ وأجاب ولم يحمل دليلاً**. وهذه ليست فشلاً:
         *    البنيةُ سليمةٌ والبوّابةُ أجابت، وما نقص هو الدليلُ وحدَه.
         *    **والرمزُ يبقى مع `null`** فتُفرّق الشاشةُ «لم يُجرَّب» — ولا كلفةَ
         *    فيها — من «جرى ولم يُثبَت» وقد أُنفقت كلفتُه.
         */
        $up = match ($v['verdict']) {
            self::PROVEN    => true,
            self::MALFORMED => false,
            default         => null,
        };

        /*
         * **والكلفةُ تُلتزَم مهما كان الحكم** — فالنداءُ وقع وأُنفق.
         *
         * وربطُ التسجيلِ بالنجاحِ وحدَه هو ما جعل محاولتَي القبولِ الحقيقيّتين
         * تختفيان من دفاترِنا: أُنفقتا وقُرئتا «فشلاً» فلم يُكتَب لهما شيء.
         */
        self::settle($admit, $json, $ms, $up === true ? null : (string) $v['verdict'], $code, $model);

        return self::row($up, $code, $ms, $v['why'], $v['info'], $v['detail']);
    }

    /**
     * **يُغلق صفَّ السجلِّ بما جرى فعلاً** — ولا يخترع رقماً لا دليلَ عليه.
     *
     * فما أبلغته البوّابةُ من كلفةٍ يُسجَّل بمصدرِه، وما لم تُبلِغه يبقى
     * **مجهولاً لا صفراً**.
     */
    private static function settle(array $admit, ?array $json, int $ms,
                                   ?string $failure, ?int $code, AiModel $model): void
    {
        if (($admit['event'] ?? null) === null) return;

        if ($failure === null && is_array($json)) {
            AiGovernance::settleOk($admit['event'], (array) $admit['holds'],
                AiChat::usage($json), (array) ($model->pricing ?? []), $ms, $code);

            return;
        }

        AiGovernance::settleFailed($admit['event'], (array) $admit['holds'],
            $failure, null, $code, $ms,
            is_array($json) ? AiChat::usage($json) : [], (array) ($model->pricing ?? []));
    }

    /**
     * **حكمُ الدليلِ على ردٍّ بـ٢٠٠** — من العقدِ المقيسِ لا من شكلٍ اخترعناه.
     *
     * ── **ما يضمنه العقدُ حضورَه في كلِّ ردٍّ غيرِ مُبثوث** ──
     *
     * `ModelResponse.__init__` يفرض `object = "chat.completion"` فرضاً، ويجعل
     * `choices` **غيرَ فارغةٍ أبداً** (تصير `[Choices()]` إن غابت)، و`Choices`
     * تُعطي `finish_reason = "stop"` و`index = 0` افتراضاً. فغيابُ أيٍّ من هذه
     * **ليس نقصَ دليلٍ بل جسمٌ آخرُ تماماً**.
     *
     * ── **وما لا يضمنه** ──
     *
     * `content: str | None` — **`null` مشروعةٌ في نجاحٍ حقيقيّ**، و`tool_calls`
     * تصير `None` حين تفرُغ، و`reasoning_content` **تُحذَف** حين لا تُستعمَل،
     * و`usage` قد تغيب كلّها. فبناءُ الدليلِ على النصِّ الظاهرِ وحدَه كان
     * يُسمّي نجاحاً حقيقيّاً فشلاً — وهو العيبُ الذي كشفه أوّلُ قبولِ إنتاج.
     *
     * @return array{verdict:string, why:?string, info:?string, detail:array}
     */
    private static function verdict(array $json, ?string $capability,
                                    string $alias, string $upstream): array
    {
        $choices = $json['choices'] ?? null;
        $choice  = is_array($choices) ? (array) (($choices[0] ?? []) ?: []) : [];
        $message = (array) (($choice['message'] ?? []) ?: []);
        $finish  = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;

        $text   = is_string($message['content'] ?? null) ? $message['content'] : '';
        $calls  = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        $think  = is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : '';
        $usage  = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $outTok = is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : null;
        $rTok   = is_numeric(($usage['completion_tokens_details']['reasoning_tokens'] ?? null))
            ? (int) $usage['completion_tokens_details']['reasoning_tokens'] : null;

        $echo = is_string($json['model'] ?? null) ? $json['model'] : null;

        // **لا مخرجَ يُخزَّن** — طولٌ وبصمةٌ وعيّنةٌ مطموسةٌ وأرقامٌ لا غير
        $detail = [
            'object'        => is_string($json['object'] ?? null) ? $json['object'] : null,
            'finish_reason' => $finish,
            'len'           => mb_strlen($text),
            'fp'            => $text === '' ? null : Redactor::fingerprint($text),
            'sample'        => mb_substr(Redactor::text($text), 0, self::SAMPLE_CHARS),
            'tool_calls'    => count($calls),
            'reasoning'     => $think !== '' || ($rTok !== null && $rTok > 0),
            'usage'         => array_intersect_key($usage,
                array_flip(['prompt_tokens', 'completion_tokens', 'total_tokens'])),
            // **واسمُ النموذجِ في الردِّ يُعلَن ولا يُبتلَع**: بوّابةٌ وجّهت الطلبَ
            // إلى نشرٍ آخرَ تُنتج دليلاً على نموذجٍ لم نسأل عنه
            'model_echo'    => $echo,
            'model_match'   => $echo === null || $echo === $alias || $echo === $upstream,
        ];
        if ($capability !== null) $detail['capability'] = $capability;

        // ── ① البنية: أهذا إكمالُ محادثةٍ أصلاً؟ ──
        if (($detail['object'] ?? null) !== self::OBJECT || ! is_array($choices) || $choices === []) {
            return ['verdict' => self::MALFORMED, 'detail' => $detail, 'info' => null,
                    'why' => 'ردَّت البوّابةُ ٢٠٠ بجسمٍ ليس **إكمالَ محادثة** — لا هويّةَ `'
                             . self::OBJECT . '` ولا قائمةَ خيارات'];
        }
        if ($finish === null || ! in_array($finish, self::FINISH_REASONS, true) || $message === []) {
            return ['verdict' => self::MALFORMED, 'detail' => $detail, 'info' => null,
                    'why' => 'خيارُ الردِّ ناقصٌ — بلا رسالةٍ أو بسببِ انتهاءٍ لا يعرفه العقد'];
        }

        // ── ② حجبُ المحتوى: نتيجةٌ لها اسمُها لا «ردٌّ لا يُثبِت» ──
        if ($finish === 'content_filter') {
            return ['verdict' => self::FILTERED, 'detail' => $detail, 'info' => null,
                    'why' => 'جرى التوليدُ وحُجب مخرَجُه بمرشِّحِ محتوى عند المزوّد — '
                             . 'أعِد الفحصَ بمحفِّزٍ آخرَ إن شئت'];
        }

        // ── ③ دليلُ القدرةِ إن طُلبت، وإلّا دليلُ التوليد ──
        [$proved, $need] = match ($capability) {
            // نداءُ أداةٍ **وسببُ انتهاءٍ يوافقه** — فالعقدُ يضع `tool_calls` كليهما
            'tools' => [$calls !== [] && in_array($finish, ['tool_calls', 'function_call'], true),
                        'نداءَ أداةٍ في الرسالةِ مع سببِ انتهاءٍ يوافقه'],
            // **كائنُ JSON لا قيمةٌ عارية**: `json_decode("2")` ليست `null`
            'structured_output' => [$text !== '' && is_array(json_decode($text, true)),
                                    'كائنَ JSON صالحاً في المخرَج'],
            // حقلُ التفكيرِ أو رموزُه — **وليس نصّاً عاديّاً يعود كأيِّ نصّ**
            'reasoning' => [$think !== '' || ($rTok !== null && $rTok > 0),
                            'حقلَ تفكيرٍ في الرسالةِ أو رموزَ تفكيرٍ في الاستهلاك'],
            /*
             * **دليلُ التوليدِ (D): أيٌّ من أربعة** — بالترتيبِ من الأقوى.
             *
             *  · `completion_tokens > 0` — **رموزٌ أُنفقت فعلاً**، وهي أصدقُ
             *    دليلٍ على أنّ النموذجَ أنتج.
             *  · نصٌّ ظاهرٌ · نداءُ أداةٍ · حقلُ تفكير.
             *  · `finish_reason = length` — **قطعَه سقفُنا نحن**
             *    (`max_tokens = 16`)، وهو إقرارٌ بأنّه كان يُنتج.
             */
            default => [($outTok !== null && $outTok > 0) || $text !== '' || $calls !== []
                        || $think !== '' || $finish === 'length',
                        'رموزَ مخرَجٍ أو نصّاً أو نداءَ أداةٍ أو قطعاً بالسقف'],
        };

        if ($proved) {
            return ['verdict' => self::PROVEN, 'detail' => $detail, 'why' => null,
                    'info' => $capability === null
                        ? 'وُلِّدت إجابةٌ فعليّة' : 'القدرةُ مُثبَتةٌ باختبارٍ حقيقيّ'];
        }

        /*
         * **«لم يُثبَت» ≠ «غيرُ مدعوم»** — والفرقُ يُقال حرفيّاً.
         *
         * نموذجٌ اختار أن يردّ نصّاً بدل نداءِ الأداةِ لا يُثبِت أنّه لا يملكها،
         * وسقوطُ الدليلِ مرّةً لا يُغلق باباً قد يكون مفتوحاً. ولذلك لا يُكتَب
         * `verified = false` أبداً — ولا هنا ولا في `e()`.
         */
        return ['verdict' => self::NOT_PROVEN, 'detail' => $detail, 'info' => null,
                'why' => 'أجابت البوّابةُ ٢٠٠ ولم يحمل الردُّ ' . $need
                         . ' — **لم يُثبَت، ولا يعني ذلك أنّه غيرُ مدعوم**'];
    }
}

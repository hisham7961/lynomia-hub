<?php

namespace Tests\Feature\AskHub;

/**
 * **لقطاتُ ردٍّ مطابقةٌ لعقدِ الإصدارِ المثبَّت** (LiteLLM v1.101.0).
 *
 * كلُّ لقطةٍ هنا مبنيّةٌ على `docs/ai-hub/24-litellm-chat-contract.md` — أي على
 * **مصدرِ الإصدارِ المثبَّتِ لدينا**، لا على وثيقةِ OpenAI العامّة.
 *
 * **ولمَ لقطاتٌ بدل ردٍّ حقيقيّ؟** لأنّ التوليدَ الحقيقيَّ يُنفق، وقرارُ
 * الإنفاقِ ليس قرارَ اختبار. واللقطةُ تُثبِت **ما نفعله نحن بالردّ** — وهو
 * كلُّ ما يخصّ هذه الدفعة. أمّا **أنّ نموذجاً حقيقيّاً يلتزم بالعقد** فلا
 * تُثبِته لقطةٌ ولا يُدَّعى هنا أنّها تُثبِته.
 *
 * ── **مصيدةٌ مقصودةٌ في كلِّ لقطة** ──
 *
 * اللقطاتُ **تحذف المفاتيحَ غيرَ المضبوطة** كما تفعل البوّابةُ حرفيّاً
 * (`model_dump_with_preserved_fields(..., exclude_unset=True)`): فلا `usage`
 * في لقطةِ طلبِ الأداة، ولا `tool_calls` في لقطةِ الجواب. وشيفرةٌ تفترض
 * وجودَ مفتاحٍ تسقط هنا — لا في الإنتاج.
 */
final class LiteLlmFixtures
{
    public const MODEL = 'hub-general';

    /** جوابٌ نهائيٌّ ناجح — `finish_reason: stop` */
    public static function answer(string $text = 'لديك ثلاثةُ مشاريعَ نشطة.', ?array $usage = null): array
    {
        $body = [
            'id'      => 'chatcmpl-' . str_repeat('a', 24),
            'object'  => 'chat.completion',
            'created' => 1758000000,
            'model'   => self::MODEL,
            'choices' => [[
                'index'         => 0,
                'finish_reason' => 'stop',
                'message'       => ['role' => 'assistant', 'content' => $text],
            ]],
        ];

        // **`usage` يغيب حين لا يُضبَط** — وهذا سلوكُ البوّابةِ لا إهمالُ اللقطة
        if ($usage !== null) $body['usage'] = $usage;

        return $body;
    }

    /** الاستهلاكُ المعتاد — ثلاثةُ أعدادٍ لا غير */
    public static function usage(int $in = 420, int $out = 65): array
    {
        return ['prompt_tokens' => $in, 'completion_tokens' => $out,
                'total_tokens' => $in + $out];
    }

    /**
     * **طلبُ أداةٍ واحدةٍ أو أكثر** — `finish_reason: tool_calls`.
     *
     * و`arguments` **سلسلةُ نصٍّ تحمل JSON** لا كائناً — كما في العقدِ تماماً.
     *
     * @param  list<array{id?:string, name:string, args:mixed}>  $calls
     */
    public static function toolCall(array $calls): array
    {
        $out = [];
        foreach (array_values($calls) as $i => $c) {
            $out[] = [
                'id'       => (string) ($c['id'] ?? ('call_' . ($i + 1))),
                'type'     => 'function',
                'function' => [
                    'name'      => (string) $c['name'],
                    'arguments' => is_string($c['args']) ? $c['args']
                        : (string) json_encode($c['args'] ?? [], JSON_UNESCAPED_UNICODE),
                ],
            ];
        }

        return [
            'id'      => 'chatcmpl-' . str_repeat('b', 24),
            'object'  => 'chat.completion',
            'created' => 1758000001,
            'model'   => self::MODEL,
            'choices' => [[
                'index'         => 0,
                'finish_reason' => 'tool_calls',
                'message'       => ['role' => 'assistant', 'content' => null, 'tool_calls' => $out],
            ]],
        ];
    }

    /** ردٌّ بلغ سقفَ المخرَج — جوابٌ **مبتورٌ** لا ناجح */
    public static function truncated(string $text = 'الجوابُ بدأ ثمّ'): array
    {
        $b = self::answer($text, self::usage());
        $b['choices'][0]['finish_reason'] = 'length';

        return $b;
    }

    /** حجبٌ بمرشِّحِ محتوى **على ردٍّ ناجحٍ بـ٢٠٠** */
    public static function filtered(): array
    {
        $b = self::answer('', self::usage(120, 0));
        $b['choices'][0]['finish_reason'] = 'content_filter';

        return $b;
    }

    /** ردُّ خطأٍ بشكلِ `openai_exception_handler` حرفاً */
    public static function error(string $message, string $type, int $code): array
    {
        return ['error' => ['message' => $message, 'type' => $type,
                            'param' => null, 'code' => (string) $code]];
    }

    // ── أصنافُ الإخفاقِ كما تنطقها البوّابةُ فعلاً ──────────────────────

    public static function rateLimited(): array
    {
        return self::error('litellm.RateLimitError: RateLimitError: Rate limit reached',
            'rate_limit_error', 429);
    }

    /** **٤٢٩ وهي انقطاعُ خدمةٍ لا حدُّ معدّل** — `_types.py:3855` */
    public static function noDeployment(): array
    {
        return self::error('No healthy deployment available, passed model=hub-general',
            'rate_limit_error', 429);
    }

    /** **تجاوزُ السياقِ يعود ٤٠٠** — `exceptions.py:504` */
    public static function contextExceeded(): array
    {
        return self::error('litellm.ContextWindowExceededError: This model\'s maximum '
            . 'context length is 8192 tokens', 'invalid_request_error', 400);
    }

    public static function providerAuth(): array
    {
        return self::error('litellm.AuthenticationError: Incorrect API key provided',
            'authentication_error', 401);
    }

    /** مفتاحُ **إدارةِ البوّابة** مرفوض — ولا بادئةَ `litellm.` فيه */
    public static function gatewayAuth(): array
    {
        return self::error('Authentication Error, Invalid proxy server token passed',
            'authentication_error', 401);
    }

    public static function providerDown(): array
    {
        return self::error('litellm.ServiceUnavailableError: upstream unavailable',
            'internal_server_error', 503);
    }

    public static function contentPolicy(): array
    {
        return self::error('litellm.ContentPolicyViolationError: blocked by policy',
            'invalid_request_error', 400);
    }

    public static function timedOut(): array
    {
        return self::error('litellm.Timeout: Connection timed out after 30s',
            'invalid_request_error', 408);
    }
}

<?php

namespace Tests\Feature\AskHub;

use App\Support\Ai\Gateway\AiChat;
use App\Support\Ai\Routing\AiRouting;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **عقدُ البوّابةِ — مقيساً لا مفترَضاً** (جاهزيّةُ الإنتاج · PR-1/PR-2).
 *
 * كلُّ تأكيدٍ هنا يقابل سطراً في `docs/ai-hub/24-litellm-chat-contract.md`،
 * وكلُّ لقطةٍ مبنيّةٌ على مصدرِ الإصدارِ المثبَّتِ حرفاً.
 *
 * **ولا طلبَ حقيقيٌّ يخرج من هذا الصنف** — `Http::fake()` يعترض كلَّ شيء،
 * و`assertNothingSent` يُثبِت الامتناعَ حيث يجب الامتناع.
 */
class AiChatContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        Settings::put('ai.enabled', true, 'test');
    }

    private function body(): array
    {
        return ['model' => LiteLlmFixtures::MODEL,
                'messages' => [['role' => 'user', 'content' => 'كم مشروعاً لديّ؟']]];
    }

    // ═══ ① شكلُ الطلب ═══

    public function test_الطلبُ_غيرُ_مبثوثٍ_قسراً_ومسارُه_من_العقد(): void
    {
        Http::fake(['*' => Http::response(LiteLlmFixtures::answer(), 200)]);

        AiChat::complete($this->body() + ['stream' => true], 700);

        Http::assertSent(function ($req) {
            $d = (array) $req->data();

            $this->assertStringContainsString(AiChat::PATH, $req->url(),
                'المسارُ ليس مسارَ العقد');
            $this->assertFalse($d['stream'],
                '**البثُّ لم يُطفأ قسراً** — فحمولةٌ ترفعه تُغيّر شكلَ الردِّ كلَّه');

            return true;
        });
    }

    /** **السقفُ يُفرَض هنا ولا يُستقبَل** — ولو رفعته الحمولةُ خطأً */
    public function test_سقفُ_المخرَجِ_يُعاد_فرضُه_بعد_الدمج(): void
    {
        Http::fake(['*' => Http::response(LiteLlmFixtures::answer(), 200)]);

        AiChat::complete($this->body() + ['max_tokens' => 999999], 700);

        Http::assertSent(function ($req) {
            $this->assertSame(700, (int) ((array) $req->data())['max_tokens'],
                '**حمولةٌ مرّرت سقفاً أعلى من المُقرّ** — والسقفُ حارسُ كلفةٍ لا اقتراح');

            return true;
        });
    }

    public function test_لا_نداءَ_والبوّابةُ_مطفأة(): void
    {
        Settings::put('ai.enabled', false, 'test');
        Http::fake();

        $r = AiChat::complete($this->body(), 700);

        Http::assertNothingSent();
        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::UNAVAILABLE, $r['failure'],
            'بوّابةٌ مطفأةٌ ليست عطلاً — وخلطُهما يُطارِد عطلاً لا وجودَ له');
    }

    /** **حارسُ الصادر** — عنوانٌ خارجَ الوجهةِ المعتمَدةِ لا يُطلَب أصلاً */
    public function test_وجهةٌ_خارجَ_الحارسِ_لا_تُطلَب(): void
    {
        Settings::put('ai.gateway_url', 'http://169.254.169.254', 'test');
        Http::fake();

        $r = AiChat::complete($this->body(), 700);

        Http::assertNothingSent();
        $this->assertSame(AskFailures::GATEWAY_FAILURE, $r['failure']);
    }

    // ═══ ② قراءةُ الردِّ الناجح ═══

    public function test_غيابُ_usage_لا_يُسقط_القراءة(): void
    {
        // `exclude_unset=True` — المفتاحُ غيرُ المضبوطِ **يغيب** ولا يصل `null`
        Http::fake(['*' => Http::response(LiteLlmFixtures::answer(), 200)]);

        $r = AiChat::complete($this->body(), 700);

        $this->assertTrue($r['ok']);
        $this->assertSame([], array_diff_key($r['usage'], ['model' => 1]),
            'قُرئ استهلاكٌ لم يُرسَل — والاختلاقُ أسوأُ من الفراغ');
    }

    public function test_الكلفةُ_تُقرأ_من_ترويسةِ_البوّابة(): void
    {
        Http::fake(['*' => Http::response(
            LiteLlmFixtures::answer('نعم', LiteLlmFixtures::usage(400, 50)),
            200,
            ['x-litellm-response-cost' => '0.00123'],
        )]);

        $r = AiChat::complete($this->body(), 700);

        $this->assertSame(450, $r['usage']['tokens']);
        $this->assertSame(0.00123, $r['usage']['cost'],
            'الكلفةُ تعود مع الردِّ نفسِه — ولا تُنتظَر من جدولِ إنفاقٍ منفصل');
    }

    // ═══ ③ التصنيف — **وهنا المصيدتان** ═══

    /**
     * **المصيدةُ ①: تجاوزُ السياقِ يعود `400`.**
     *
     * `ContextWindowExceededError` يرث `BadRequestError` فيحمل رمزَه نفسَه.
     * ومن صنَّف بالرمزِ وحدَه قال «طلبٌ غيرُ صالح» لسؤالٍ كلُّ عيبِه سعةُ سياق.
     */
    public function test_تجاوزُ_السياقِ_يُصنَّف_سياقاً_لا_طلباً_فاسداً(): void
    {
        $body = json_encode(LiteLlmFixtures::contextExceeded(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(AskFailures::CONTEXT_LIMIT, AiChat::classify(400, (string) $body));
        $this->assertSame('context_overflow', AiRouting::classify(['code' => 400, 'body' => $body]));
    }

    /**
     * **المصيدةُ ②: `429` قد تكون انقطاعَ خدمةٍ لا حدَّ معدّل.**
     *
     * `_types.py:3855` يفرض `429` على «‏No healthy deployment available».
     * ولو صُنِّف حدَّ معدّلٍ لقيل للمستخدمِ «انتظر قليلاً» عن عطلٍ لا يُصلحه
     * الانتظار — **وهو صنفُ العيبِ الذي بُني `AskFailures` كلُّه لمنعِه**.
     */
    public function test_٤٢٩_بلا_نموذجٍ_صالحٍ_انقطاعٌ_لا_حدُّ_معدّل(): void
    {
        $out  = (string) json_encode(LiteLlmFixtures::noDeployment(), JSON_UNESCAPED_UNICODE);
        $rate = (string) json_encode(LiteLlmFixtures::rateLimited(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(AskFailures::PROVIDER_FAILURE, AiChat::classify(429, $out),
            '**انقطاعٌ ظهر حدَّ معدّل** — والرسالةُ تقول للمستخدمِ أن ينتظر بلا جدوى');
        $this->assertSame(AskFailures::RATE_LIMITED, AiChat::classify(429, $rate));

        // وقرارُ التوجيهِ يختلف كذلك: الانقطاعُ عابرٌ يُعاد بتراجعٍ أُسّيّ،
        // وحدُّ المعدّلِ يُعاد **بالمهلةِ المُعلَنة**
        $this->assertSame('transient', AiRouting::classify(['code' => 429, 'body' => $out]));
        $this->assertSame('rate_limited', AiRouting::classify(['code' => 429, 'body' => $rate]));
    }

    /** **٤٠١ ليست صنفاً واحداً**: مفتاحُنا عند البوّابة ≠ اعتمادُنا عند المزوّد */
    public function test_٤٠١_تفرّق_مفتاحَ_البوّابةِ_من_اعتمادِ_المزوّد(): void
    {
        $gw = (string) json_encode(LiteLlmFixtures::gatewayAuth(), JSON_UNESCAPED_UNICODE);
        $pv = (string) json_encode(LiteLlmFixtures::providerAuth(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(AskFailures::GATEWAY_FAILURE, AiChat::classify(401, $gw));
        $this->assertSame(AskFailures::PROVIDER_FAILURE, AiChat::classify(401, $pv),
            'بادئةُ `litellm.` تعني أنّ العطلَ **خلفَ** البوّابةِ لا عندها');
    }

    public function test_بقيّةُ_التصنيف(): void
    {
        $cases = [
            [408, LiteLlmFixtures::timedOut(),     AskFailures::TIMEOUT],
            [400, LiteLlmFixtures::contentPolicy(), AskFailures::CONTENT_FILTERED],
            [503, LiteLlmFixtures::providerDown(),  AskFailures::PROVIDER_FAILURE],
        ];

        foreach ($cases as [$code, $fixture, $expected]) {
            $this->assertSame($expected,
                AiChat::classify($code, (string) json_encode($fixture, JSON_UNESCAPED_UNICODE)),
                "التصنيفُ انحرف عند HTTP {$code}");
        }

        // وعطلٌ بلا بصمةِ البوّابةِ يُنسَب إليها — **والافتراضُ عند الشكِّ
        // «عطلُ خدمةٍ» لا شيءَ يُلمِّح إلى صلاحيّةٍ ناقصة**
        $this->assertSame(AskFailures::GATEWAY_FAILURE, AiChat::classify(500, '{"detail":"boom"}'));
    }

    public function test_مهلةُ_النقلِ_تُصنَّف_مهلةً_لا_عطلاً_عامّاً(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        });

        $r = AiChat::complete($this->body(), 700);

        $this->assertSame(AskFailures::TIMEOUT, $r['failure']);
        $this->assertSame('transient', $r['cause']);
    }

    public function test_مهلةُ_إعادةِ_المحاولةِ_تُقرأ_من_الترويسة(): void
    {
        Http::fake(['*' => Http::response(LiteLlmFixtures::rateLimited(), 429, ['retry-after' => '7'])]);

        $r = AiChat::complete($this->body(), 700);

        $this->assertSame(7, $r['retry_after']);
        $this->assertSame(AskFailures::RATE_LIMITED, $r['failure']);
    }

    /** **ولا سرَّ يعود في متنِ الخطأ** — المتنُ يمرّ بالمُطهِّرِ قبل أن يُقرَأ */
    public function test_متنُ_الخطأِ_مطموسٌ_ومقصوص(): void
    {
        Http::fake(['*' => Http::response(LiteLlmFixtures::error(
            'litellm.AuthenticationError: key sk-proj-ABCDEF0123456789ABCDEF0123456789 rejected',
            'authentication_error', 401), 401)]);

        $r = AiChat::complete($this->body(), 700);

        $this->assertStringNotContainsString('sk-proj-ABCDEF0123456789ABCDEF0123456789',
            (string) $r['error'], '**سرٌّ عاد في متنِ خطأ** — وهو يُكتَب في الأثر');
        $this->assertLessThanOrEqual(AiChat::MAX_ERROR_CHARS + 32, mb_strlen((string) $r['error']));
    }
}

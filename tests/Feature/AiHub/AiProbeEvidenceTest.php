<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\AiProbes;
use App\Support\AiProviders;
use App\Support\ConnectionProbe;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **دليلُ الفاحصَين D وE — من العقدِ المقيسِ لا من لقطةٍ اخترعناها.**
 *
 * ── **العيبُ الذي وُلدت منه هذه الحزمة (قبولُ إنتاجٍ حقيقيّ)** ──
 *
 * بعد شحنِ الرصيد عاد الفاحصان بـ**HTTP 200** ومع ذلك قال Hub «فشل»:
 *
 * ```
 * ❌ فشل الاتصال (HTTP 200) · 561ms  — ردّت البوابة بنجاح ولم يحمل الرد ما يثبت المطلوب
 * ```
 *
 * والسببُ أنّ المُتحقِّقَ كان يطلب **نصّاً ظاهراً** في `choices[0].message.content`،
 * بينما العقدُ المقيسُ للإصدارِ المثبَّتِ يقول صراحةً إنّ ذلك الحقلَ
 * **`str | None`** — أي أنّ **نجاحاً حقيقيّاً قد يحمل `null`**:
 *
 *  · `types/utils.py:1289` — `content: str | None` (وهو **دائمُ الحضور**، قد يكون `null`).
 *  · `types/utils.py:1549` — `finish_reason` من تعدادٍ من تسعِ قيمٍ، منها `length`.
 *  · `Message.__init__` — `tool_calls` تصير **`None`** حين تكون القائمةُ فارغة.
 *  · `Message.__init__` — `reasoning_content` **تُحذَف** حين لا تُستعمَل.
 *  · `ModelResponse.__init__` — `object` تُفرَض `"chat.completion"` دائماً،
 *    و`choices` لا تكون فارغةً أبداً (تصير `[Choices()]`).
 *
 * **وسقفُنا `max_tokens = 16` يجعل تلك الحالةَ متوقّعةً لا نادرة:** نموذجٌ
 * يُنفق السقفَ قبل أن يُخرج حرفاً يعود بـ`finish_reason: "length"`
 * و`content: null` — **وقد وُلِّد فعلاً وأُنفق عليه**.
 *
 * ── **ولا يُصلَح هذا بجعلِ ٢٠٠ نجاحاً** ──
 *
 * الدليلُ يبقى مطلوباً، لكنّه **دليلُ العقدِ الحقيقيّ**: هويّةُ الردّ، وبنيةُ
 * الخيار، وسببُ الانتهاء، **ورموزُ المخرَجِ التي أُنفقت**.
 */
class AiProbeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-EVID7c2f9a4e1188bbcc33';

    /** ما تردّه المحاكاةُ على `/v1/chat/completions` */
    private array $completion = [];

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        $this->completion = self::plainSuccess();

        Http::fake(['*' => function ($req) {
            if (str_contains($req->url(), '/chat/completions')) {
                return Http::response($this->completion, $this->status);
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    // ── لقطاتٌ مطابقةٌ للعقدِ المقيس ───────────────────────────────────

    /**
     * **هيكلُ ردٍّ ناجحٍ كما يبنيه `ModelResponse`** — بكلِّ ما يضمنه العقد.
     *
     * والحقولُ الأربعةُ الأولى **مضمونةُ الحضور** في كلِّ ردٍّ غيرِ مُبثوث:
     * `id` و`object` و`created` و`choices` غيرُ الفارغة. وقد كانت اللقطةُ
     * القديمةُ تُسقطها كلَّها — **فكانت أسهلَ من الواقعِ وأفقرَ منه معاً**.
     */
    private static function envelope(array $choice, ?array $usage = null): array
    {
        $out = [
            'id'      => 'chatcmpl-9fA2c7',
            'object'  => 'chat.completion',
            'created' => 1758400000,
            'model'   => 'hub-evid',
            'choices' => [$choice + ['index' => 0]],
        ];
        if ($usage !== null) $out['usage'] = $usage;

        return $out;
    }

    private static function plainSuccess(): array
    {
        return self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']],
            ['prompt_tokens' => 12, 'completion_tokens' => 2, 'total_tokens' => 14]);
    }

    /** **الحالةُ الإنتاجيّةُ بعينِها**: السقفُ نفد قبل أن يخرج حرف */
    private static function truncatedByOurCap(): array
    {
        return self::envelope(
            ['finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => null]],
            ['prompt_tokens' => 12, 'completion_tokens' => 16, 'total_tokens' => 28]);
    }

    private function provider(): AiProvider
    {
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    private function model(): AiModel
    {
        return AiModel::create([
            'provider_id' => $this->provider()->id, 'litellm_model_name' => 'hub-evid',
            'upstream_model' => 'fam/evid', 'display_name' => 'نموذجُ الدليل',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => [], 'limits' => [], 'params' => [], 'pricing' => [],
        ]);
    }

    // ═══ ① D — التوليدُ الأدنى ═══

    public function test_D_ينجح_على_ردٍّ_طبيعيّ(): void
    {
        $r = AiProbes::d($this->model(), true);

        $this->assertTrue($r['up']);
        $this->assertSame(200, $r['code']);
        $this->assertTrue((bool) setting('ai.generation_ok', false));
    }

    /**
     * **الحالةُ الإنتاجيّةُ التي سقطت** — و`content` فيها `null` بحقّ.
     *
     * والدليلُ قائمٌ رغم ذلك: `completion_tokens = 16` **رموزٌ أُنفقت فعلاً**،
     * و`finish_reason = length` يقول إنّ القطعَ من **سقفِنا نحن** لا من عجزِ
     * النموذج. فتسميةُ هذا «فشلاً» تكذب على من دفع ثمنَه.
     */
    public function test_D_ينجح_حين_يقطع_سقفُنا_المخرَجَ_قبل_أن_يخرج_حرف(): void
    {
        $this->completion = self::truncatedByOurCap();

        $r = AiProbes::d($this->model(), true);

        $this->assertTrue($r['up'],
            '**‏٢٠٠ برموزِ مخرَجٍ مُنفَقةٍ وقطعٍ بسقفِنا = توليدٌ جرى** — وتسميتُه فشلاً تكذب');
        $this->assertSame('length', $r['detail']['finish_reason'] ?? null);
        $this->assertSame(16, $r['detail']['usage']['completion_tokens'] ?? null);
    }

    /** ونموذجٌ تفكيريٌّ أنفق السقفَ كلَّه في التفكير — توليدٌ جرى كذلك */
    public function test_D_ينجح_على_نموذجٍ_أنفق_السقفَ_في_التفكير(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => null]],
            ['prompt_tokens' => 10, 'completion_tokens' => 16, 'total_tokens' => 26,
             'completion_tokens_details' => ['reasoning_tokens' => 16]]);

        $this->assertTrue(AiProbes::d($this->model(), true)['up']);
    }

    /** **و٢٠٠ بجسمٍ ليس إكمالَ محادثةٍ أصلاً يُرَدّ صراحةً** — لا يُقال «لم يُثبِت» */
    public function test_D_يردّ_جسماً_ليس_إكمالَ_محادثة(): void
    {
        $this->completion = ['detail' => 'something else entirely'];

        $r = AiProbes::d($this->model(), true);

        $this->assertFalse($r['up']);
        $this->assertStringContainsString('إكمالَ محادثة', (string) $r['error']);
        $this->assertFalse((bool) setting('ai.generation_ok', false));
    }

    public function test_D_يردّ_جسماً_فارغاً(): void
    {
        $this->completion = [];

        $r = AiProbes::d($this->model(), true);
        $this->assertFalse($r['up']);
    }

    public function test_D_يردّ_خياراً_بلا_رسالةٍ_ولا_سببِ_انتهاء(): void
    {
        $this->completion = ['object' => 'chat.completion', 'id' => 'x', 'created' => 1,
                             'choices' => [['index' => 0]]];

        $this->assertFalse(AiProbes::d($this->model(), true)['up']);
    }

    /**
     * **٢٠٠ سليمُ البنيةِ وبلا دليلِ مخرَجٍ البتّة ⇒ «لم يُثبَت» لا «فشل».**
     *
     * فالبنيةُ صحيحةٌ والبوّابةُ أجابت — وما نقص هو الدليلُ وحدَه. وتسميتُه
     * فشلاً تُرسل المالكَ يُطارد عطلاً، **وتسميتُه نجاحاً تكذب**.
     */
    public function test_D_بلا_دليلِ_مخرَجٍ_يُعلَن_غيرَ_مُثبَتٍ_لا_فاشلاً(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => null]]);

        $r = AiProbes::d($this->model(), true);

        $this->assertNull($r['up'], 'مجهولٌ ≠ فشل');
        $this->assertSame(200, $r['code'], '**والرمزُ يبقى** — فيُفرَّق «لم يُجرَّب» من «جرى ولم يُثبَت»');
        $this->assertFalse((bool) setting('ai.generation_ok', false),
            'شهادةُ التوليدِ لا تُكتَب بلا دليل');
    }

    /** **وحجبُ المحتوى نتيجةٌ لها اسمُها** — لا «ردٌّ لا يُثبِت» */
    public function test_D_يُسمّي_حجبَ_المحتوى_باسمِه(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'content_filter', 'message' => ['role' => 'assistant', 'content' => null]],
            ['prompt_tokens' => 9, 'completion_tokens' => 0, 'total_tokens' => 9]);

        $r = AiProbes::d($this->model(), true);

        $this->assertNull($r['up']);
        $this->assertStringContainsString('مرشِّح', (string) $r['error']);
        $this->assertFalse((bool) setting('ai.generation_ok', false));
    }

    public function test_D_يحمل_هويّةَ_الردِّ_في_التفصيل(): void
    {
        $d = AiProbes::d($this->model(), true)['detail'];

        $this->assertSame('chat.completion', $d['object'] ?? null);
        $this->assertSame('stop', $d['finish_reason'] ?? null);
        $this->assertTrue((bool) ($d['model_match'] ?? false));
    }

    /** **واسمُ نموذجٍ مغايرٌ في الردّ يُعلَن ولا يُبتلَع** */
    public function test_D_يُعلن_اختلافَ_اسمِ_النموذجِ_في_الردّ(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']],
            ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]);
        $this->completion['model'] = 'some-other-deployment';

        $d = AiProbes::d($this->model(), true)['detail'];

        $this->assertFalse((bool) ($d['model_match'] ?? true));
        $this->assertSame('some-other-deployment', $d['model_echo'] ?? null);
    }

    public function test_D_يُبقي_رمزَ_HTTP_الفاشلَ_فشلاً(): void
    {
        $this->status = 500;
        $this->completion = ['detail' => 'boom'];

        $r = AiProbes::d($this->model(), true);
        $this->assertFalse($r['up']);
        $this->assertSame(500, $r['code']);
    }

    // ═══ ② E — قدرةٌ بعينها ═══

    public function test_E_الأدواتُ_تُثبَت_بنداءِ_أداةٍ_وسببِ_انتهاءٍ_مطابق(): void
    {
        $this->completion = self::envelope([
            'finish_reason' => 'tool_calls',
            'message' => ['role' => 'assistant', 'content' => null,
                          'tool_calls' => [['id' => 'call_1', 'type' => 'function',
                                            'function' => ['name' => 'ping', 'arguments' => '{}']]]],
        ], ['prompt_tokens' => 20, 'completion_tokens' => 6, 'total_tokens' => 26]);

        $r = AiProbes::e($this->model(), 'tools', true);
        $this->assertTrue($r['up']);
    }

    /** **ونموذجٌ ردّ بنصٍّ بدل نداءِ الأداة: غيرُ مُثبَتٍ لا غيرُ مدعوم** */
    public function test_E_الأدواتُ_غيرُ_مُثبَتةٍ_ليست_غيرَ_مدعومة(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'I cannot']],
            ['prompt_tokens' => 20, 'completion_tokens' => 3, 'total_tokens' => 23]);

        $r = AiProbes::e($this->model(), 'tools', true);

        $this->assertNull($r['up'], '**مجهولٌ ≠ غيرُ مدعوم** — والفشلُ لا يُثبِت النفي');
        $this->assertSame(200, $r['code']);
    }

    public function test_E_المخرَجُ_المُهيكَلُ_يلزمه_كائنُ_JSON_لا_رقمٌ_عارٍ(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '2']],
            ['prompt_tokens' => 9, 'completion_tokens' => 1, 'total_tokens' => 10]);

        $this->assertNull(AiProbes::e($this->model(), 'structured_output', true)['up'],
            '**`json_decode("2")` ليس `null`** — فالفحصُ القديمُ كان يقبل رقماً عارياً كائناً مُهيكَلاً');
    }

    public function test_E_المخرَجُ_المُهيكَلُ_يُثبَت_بكائنٍ_صحيح(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"ok":true}']],
            ['prompt_tokens' => 9, 'completion_tokens' => 5, 'total_tokens' => 14]);

        $this->assertTrue(AiProbes::e($this->model(), 'structured_output', true)['up']);
    }

    /** **والتفكيرُ يُثبَت بحقلِه أو برموزِه** — لا بنصٍّ يعود كأيِّ نصّ */
    public function test_E_التفكيرُ_يُثبَت_بحقلِ_التفكير(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok',
                                                      'reasoning_content' => 'step one…']],
            ['prompt_tokens' => 9, 'completion_tokens' => 4, 'total_tokens' => 13]);

        $this->assertTrue(AiProbes::e($this->model(), 'reasoning', true)['up']);
    }

    public function test_E_التفكيرُ_يُثبَت_برموزِ_التفكيرِ_في_الاستهلاك(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']],
            ['prompt_tokens' => 9, 'completion_tokens' => 12, 'total_tokens' => 21,
             'completion_tokens_details' => ['reasoning_tokens' => 8]]);

        $this->assertTrue(AiProbes::e($this->model(), 'reasoning', true)['up']);
    }

    /** **ونصٌّ عاديٌّ لا يُثبِت تفكيراً** — وإلّا صار E تكرارَ D باسمٍ آخر */
    public function test_E_التفكيرُ_لا_يُثبَت_بنصٍّ_عاديّ(): void
    {
        $this->assertNull(AiProbes::e($this->model(), 'reasoning', true)['up'],
            '**E ليس D باسمٍ آخر** — لكلِّ قدرةٍ دليلُها');
    }

    /**
     * **والرؤيةُ لا تُثبَت بطلبٍ صغيرٍ أصلاً.**
     *
     * لا حقلَ في عقدِ الردِّ المقيسِ يفرّق «رأى الصورة» من «لم يرها» — الردُّ
     * نصٌّ كأيِّ نصّ. فعرضُ زرٍّ يَعِد بإثباتِها **وعدٌ لا يُوفى**، وكلفتُه
     * حقيقيّة.
     */
    public function test_E_الرؤيةُ_ليست_من_القدراتِ_القابلةِ_للفحص(): void
    {
        $this->assertNotContains('vision', AiProbes::PROBABLE);

        $r = AiProbes::e($this->model(), 'vision', true);

        $this->assertNull($r['up']);
        $this->assertNull($r['code'], 'لا نداءَ يُنفَق على فحصٍ لا يُثبِت شيئاً');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    // ═══ ③ الرسالةُ تفرّق «لم يُجرَّب» من «جرى ولم يُثبَت» ═══

    public function test_السطرُ_يفرّق_ما_لم_يُجرَّب_ممّا_جرى_ولم_يُثبَت(): void
    {
        $notTried = ConnectionProbe::line(['up' => null, 'code' => null, 'ms' => null,
                                           'error' => 'لا هدفَ مضبوط']);
        $tried    = ConnectionProbe::line(['up' => null, 'code' => 200, 'ms' => 561,
                                           'error' => 'لم يحمل الردُّ ما يُثبِت المطلوب']);

        $this->assertStringContainsString('لم يُجرَّب', $notTried);
        $this->assertStringNotContainsString('لم يُجرَّب', $tried,
            '**«جرى ولم يُثبَت» ليس «لم يُجرَّب»** — والخلطُ يُخفي كلفةً أُنفقت');
        $this->assertStringNotContainsString('فشل الاتصال', $tried);
    }

    // ═══ ④ ولا سرَّ في نتيجةٍ ولا تفصيل ═══

    public function test_لا_سرَّ_في_نتيجةِ_الفاحص(): void
    {
        $blob = json_encode(AiProbes::d($this->model(), true), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRET, (string) $blob);
        $this->assertStringNotContainsString('sk-admin-test-key-000111222333', (string) $blob);
    }

    // ═══ ④٫٥ الفاحصُ المُنفِقُ يدخل سجلَّ الحوكمة ═══

    /**
     * **نداءٌ مدفوعٌ خارجَ السجلِّ إنفاقٌ لا يُرى.**
     *
     * وهذه الفجوةُ بعينُها هي ما جعل محاولتَي القبولِ الحقيقيّتين تختفيان من
     * دفاترِنا: جرتا وأُنفق ثمنُهما، وقُرئتا «فشلاً» فلم يُكتَب لهما شيء.
     */
    public function test_الفاحصُ_الناجحُ_يفتح_صفّاً_في_السجلِّ_ويُغلقه(): void
    {
        $this->assertTrue(AiProbes::d($this->model(), true)['up']);

        $e = \App\Models\AiUsageEvent::query()->orderBy('created_at')->orderBy('id')->first();

        $this->assertNotNull($e, '**فاحصٌ مدفوعٌ بلا صفٍّ في السجلّ**');
        $this->assertSame('ok', (string) $e->status);
        $this->assertSame('probe', (string) $e->feature);
        $this->assertSame(2, (int) $e->output_tokens);
    }

    /** **والفاشلُ يُسجَّل أيضاً** — فالمحاولةُ وقعت وأُنفق ثمنُها */
    public function test_الفاحصُ_غيرُ_المُثبَتِ_يُسجَّل_لأنّ_النداءَ_وقع(): void
    {
        $this->completion = self::envelope(
            ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => null]],
            ['prompt_tokens' => 12, 'completion_tokens' => 0, 'total_tokens' => 12]);

        $this->assertNull(AiProbes::d($this->model(), true)['up']);

        $e = \App\Models\AiUsageEvent::query()->orderBy('created_at')->orderBy('id')->first();
        $this->assertNotNull($e);
        $this->assertSame('failed', (string) $e->status);
        $this->assertSame(AiProbes::NOT_PROVEN, (string) $e->failure);
    }

    /** **وميزانيّةٌ مستنفَدةٌ تمنع الفاحصَ قبل أن يُنفِق** */
    public function test_ميزانيّةٌ_مستنفَدةٌ_تمنع_الفاحصَ_ولا_تُنفِق(): void
    {
        \App\Models\AiBudget::create(['key' => 'tight', 'label' => 'سقفٌ ضيّق',
            'scope_type' => 'global', 'period' => 'monthly',
            'limit_requests' => 0, 'enforce' => true, 'enabled' => true]);

        $r = AiProbes::d($this->model(), true);

        $this->assertNull($r['up']);
        $this->assertNull($r['code'], 'لم يقع نداءٌ — فلا رمزَ HTTP');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    /** **وفاحصُ نموذجٍ لم يُفعَّل بعدُ يعمل** — وذاك غرضُه الأوّل */
    public function test_الفاحصُ_يعمل_على_نموذجٍ_لم_يُفعَّل_بعد(): void
    {
        $m = $this->model();
        $m->forceFill(['enabled' => false])->save();

        $this->assertTrue(AiProbes::d($m->fresh(), true)['up'],
            '**اشتراطُ التفعيلِ يدفع المالكَ إلى تفعيلِ مجهولٍ ليفحصَه**');
    }

    // ═══ ⑤ حارسٌ: لقطاتُ الاختبارِ تطابق العقدَ المقيس ═══

    /**
     * **لقطةٌ أسهلُ من الواقعِ تُنتج حزمةً خضراءَ وشاشةً تكذب.**
     *
     * وقعت هذه مرّتين في هذا المشروع: مرّةً في شكلِ حمولةِ الكتابة (422)،
     * ومرّةً هنا. فالحارسُ يمسح ملفّاتِ الاختبارِ نفسَها: كلُّ لقطةِ إكمالِ
     * محادثةٍ يجب أن تحمل **ما يضمنه العقدُ حضورَه** — وإلّا فهي تختبر عالماً
     * لا وجودَ له.
     */
    public function test_كلُّ_لقطةِ_إكمالٍ_في_الحزمةِ_تحمل_ما_يضمنه_العقد(): void
    {
        $files = glob(__DIR__ . '/../Ai*/*.php') ?: [];
        $files = array_merge($files, glob(__DIR__ . '/*.php') ?: [],
                             glob(__DIR__ . '/../AskHub/*.php') ?: []);

        $offenders = [];
        foreach (array_unique($files) as $f) {
            $src = (string) file_get_contents($f);

            // لقطةٌ تُعلن `choices` ⇒ تدّعي أنّها إكمالُ محادثة
            if (! str_contains($src, "'choices'")) continue;

            // **التعبيرُ النمطيُّ لا المطابقةُ الحرفيّة**: المحاذاةُ بمسافاتٍ
            // متعدّدةٍ اصطلاحٌ في هذا المستودع، ومطابقةٌ حرفيّةٌ تُنذر كاذبةً
            // على لقطةٍ سليمة — **وإنذارٌ كاذبٌ متكرّرٌ يُدرّب على التجاهل**.
            $musts = [
                "'object'\\s*=>\\s*'chat\\.completion'" => "'object' => 'chat.completion'",
                "'finish_reason'"                          => "'finish_reason'",
            ];

            foreach ($musts as $re => $label) {
                if (! preg_match('~' . $re . '~', $src)) {
                    $offenders[] = basename($f) . ' ← ينقصها ' . $label;
                }
            }
        }

        $this->assertSame([], $offenders,
            '**لقطةٌ أسهلُ من الواقع**: العقدُ المقيسُ يضمن `object` و`finish_reason` في كلِّ ردٍّ ناجح');
    }
}

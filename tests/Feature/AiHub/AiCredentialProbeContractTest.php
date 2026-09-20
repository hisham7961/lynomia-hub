<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Support\AiOverview;
use App\Support\AiProbes;
use App\Support\AiProviders;
use App\Support\LiteLlmAdmin;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **عقدُ `POST /health/test_connection` — كما يفرضه الإصدارُ المثبَّت** (B-FIX).
 *
 * ── **العطلُ الذي كشفه أوّلُ قبولِ إنتاجٍ حقيقيّ** ──
 *
 * شغّل المالكُ الفاحصَ B على اعتمادٍ صحيح، فعاد:
 *
 * ```
 * HTTP 500 · {"detail":{"error":"Failed to test connection: 'model'"}}
 * ```
 *
 * و`'model'` بين علامتَي اقتباسٍ ليست رسالةً كتبها أحد — هي **`KeyError`
 * بايثونيّ** طُبع نصّاً. ومصدرُه سطرٌ واحدٌ في مصدرِ الإصدارِ المثبَّت:
 *
 * ```python
 * # proxy/health_check.py:774
 * if litellm_params["model"].startswith("bedrock/"):
 * ```
 *
 * **قوسانِ لا `.get()`**. فحمولةٌ بلا `model` تُسقط المسارَ قبل أن يبلغ
 * المزوّدَ أصلاً. أي أنّ B **لم يفشل لأنّ المفتاحَ خاطئ — لم يُجرَّب المفتاحُ
 * قطّ**.
 *
 * ── **ولمَ لم تمسكه الحزمةُ قبل الإنتاج؟** ──
 *
 * لأنّ مُرصِدَ الاختبارِ كان يردّ `200` على **أيِّ** حمولةٍ تصل
 * `/health/test_connection`. فكان يُصدّق ما لا تصدّقه البوّابة. ولذلك يحمل
 * هذا الصنفُ **مُرصِداً يُنفّذ العقدَ**: بلا `model` يعود بالخمسمئةِ نفسِها
 * وبالمتنِ نفسِه. **ومُرصِدٌ أسهلُ من الواقعِ يُنتج حزمةً خضراءَ وإنتاجاً أحمر.**
 */
class AiCredentialProbeContractTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-BFIX7d2a4e9c11bb8877ff4422';

    /** آخرُ حمولةِ فحصِ اتصالٍ أُرسلت — تُفحَص لا تُخمَّن */
    private ?array $lastTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
    }

    /**
     * **مُرصِدٌ يُنفّذ العقدَ الحقيقيّ** — لا مُرصِدٌ يقول «نعم» لكلِّ شيء.
     */
    private function contractFake(): void
    {
        Http::fake(['*' => function ($req) {
            $url = $req->url();

            if (str_contains($url, '/health/test_connection')) {
                $this->lastTest = (array) $req->data();
                $params = (array) ($this->lastTest['litellm_params'] ?? []);

                // `proxy/health_check.py:774` — قوسانِ لا `.get()`
                if (! array_key_exists('model', $params)) {
                    return Http::response(
                        ['detail' => ['error' => "Failed to test connection: 'model'"]], 500);
                }

                return Http::response(['status' => 'success', 'result' => []], 200);
            }

            if (str_contains($url, '/model/info')) return Http::response(['data' => []], 200);

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    private function seedModel(string $upstream = 'gpt-4o-mini', string $hub = 'hub-general'): AiModel
    {
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return AiModel::create([
            'provider_id'        => $p->id,
            'litellm_model_name' => $hub,               // **اسمٌ داخليٌّ في Hub**
            'upstream_model'     => $upstream,          // **اسمٌ عند المزوّد**
            'display_name'       => 'نموذجٌ وهميّ',
            'enabled'            => false,
            'capabilities'       => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [], 'pricing' => [],
        ]);
    }

    // ═══ ① الحارسُ في طبقةِ النقل — لا يُلتَفُّ عليه من أيِّ مُستدعٍ ═══

    /**
     * **حمولةٌ بلا `model` لا تخرج أصلاً.**
     *
     * والموضعُ مقصود: لو وُضع الحارسُ في الفاحصِ وحدَه، لَأعاد أيُّ مُستدعٍ
     * جديدٍ العطلَ نفسَه. وطبقةُ النقلِ **نقطةُ اختناقٍ واحدة**.
     */
    public function test_طبقةُ_النقلِ_ترفض_فحصَ_اتصالٍ_بلا_نموذج(): void
    {
        Http::fake();

        $r = LiteLlmAdmin::testConnection(['litellm_credential_name' => 'hub-x'], 'chat', true);

        Http::assertNothingSent();
        $this->assertFalse($r['ok'], '**خرجت حمولةٌ بلا نموذج** — وهي التي ردّت ٥٠٠ في الإنتاج');
        $this->assertStringContainsString('النموذج', (string) $r['error'],
            'رُدَّ الطلبُ بلا أن يُقال للمديرِ ما الناقص');
    }

    /** **ويُرسَل اسمُ النموذجِ عند المزوّدِ حين يُمرَّر** */
    public function test_الحمولةُ_تحمل_النموذجَ_والاعتمادَ_معاً(): void
    {
        $this->contractFake();

        $r = LiteLlmAdmin::testConnection(
            ['model' => 'gpt-4o-mini', 'litellm_credential_name' => 'hub-x'], 'chat', true);

        $this->assertTrue($r['ok'], 'ردّت البوّابةُ خطأً على حمولةٍ مكتملة');
        $this->assertSame('gpt-4o-mini', $this->lastTest['litellm_params']['model']);
        $this->assertSame('hub-x', $this->lastTest['litellm_params']['litellm_credential_name']);
        $this->assertSame('chat', $this->lastTest['mode']);
    }

    // ═══ ② الفاحصُ B — (اعتمادٌ × نموذج) لا اعتمادٌ وحدَه ═══

    public function test_B_ينجح_بنموذجٍ_وينقل_اسمَه_للمزوّد(): void
    {
        $this->contractFake();
        $m = $this->seedModel('gpt-4o-mini');

        $r = AiProbes::b($m, 'chat', true);

        $this->assertTrue($r['up'], 'فشل B رغم اكتمالِ الحمولة: ' . (string) $r['error']);
        $this->assertSame('gpt-4o-mini', $this->lastTest['litellm_params']['model'] ?? null);
    }

    /**
     * **ولا يُرسَل اسمُ Hub الداخليُّ باعتبارِه اسمَ النموذجِ عند المزوّد.**
     *
     * `hub-general` اسمٌ نُسمّي به النموذجَ عندنا — لا يعرفه المزوّدُ ولا
     * يقبله. وإرسالُه يُنتج فشلاً يبدو «مفتاحاً خاطئاً» وهو **خطأُ تسمية**،
     * فيُطارِد المالكُ اعتماداً سليماً.
     */
    public function test_اسمُ_Hub_الداخليُّ_لا_يُرسَل_اسمَ_نموذجٍ_للمزوّد(): void
    {
        $this->contractFake();
        $m = $this->seedModel('gpt-4o-mini');

        AiProbes::b($m, 'chat', true);

        $sent = (string) ($this->lastTest['litellm_params']['model'] ?? '');
        $this->assertNotSame('hub-general', $sent,
            '**أُرسل الاسمُ الداخليُّ للمزوّد** — والفشلُ سيبدو مفتاحاً خاطئاً');
        $this->assertSame((string) $m->upstream_model, $sent);
    }

    /** **ونموذجٌ بلا اسمٍ عند المزوّدِ يُرَدّ برسالةٍ تقول ما الناقص** */
    public function test_نموذجٌ_بلا_اسمٍ_عند_المزوّدِ_يُرَدُّ_قبل_النداء(): void
    {
        $this->contractFake();
        $m = $this->seedModel('');

        $r = AiProbes::b($m, 'chat', true);

        // **ولا يُؤكَّد «لم يُرسَل شيء»**: إنشاءُ المزوّدِ نفسُه يُنشئ اعتماداً
        // عند البوّابة. المقصودُ أنّ **فحصَ الاتصالِ** لم يخرج
        $this->assertNull($this->lastTest, 'خرج فحصُ اتصالٍ لنموذجٍ بلا اسمٍ عند المزوّد');
        $this->assertNull($r['up'], 'نموذجٌ ناقصٌ عُومل فشلاً — والفرقُ بين «لم يُجرَّب» و«جُرّب ففشل» هو كلُّ شيء');
        $this->assertStringContainsString('اسمَ النموذجِ عند المزوّد', (string) $r['error']);
    }

    /** **ولا إقرارَ ⇒ لا نداءَ** — الحارسُ الماليُّ قبل حارسِ الشكل */
    public function test_بلا_إقرارٍ_بالكلفةِ_لا_نداء(): void
    {
        $this->contractFake();
        $m = $this->seedModel();

        $r = AiProbes::b($m, 'chat');

        $this->assertNull($this->lastTest, 'خرج فحصٌ مدفوعٌ بلا إقرارٍ بالكلفة');
        $this->assertNull($r['up']);
    }

    /** **والنجاحُ يُثبَّت في حالةِ الاعتماد** — وإلّا بقي الفحصُ المدفوعُ بلا أثر */
    public function test_نجاحُ_B_يرفع_حالةَ_الاعتمادِ_إلى_مُتحقَّق(): void
    {
        $this->contractFake();
        $m = $this->seedModel();

        $this->assertSame('configured', (string) $m->provider->credential_state);

        AiProbes::b($m, 'chat', true);

        $this->assertSame('verified', (string) $m->provider->fresh()->credential_state,
            '**فحصٌ مدفوعٌ نجح ولم يترك أثراً** — فيُعاد غداً بلا داعٍ ويُنفَق مرّتين');
    }

    /** **والفشلُ لا يُنزل الحالة** — فقد يكون اسمَ نموذجٍ خاطئاً لا مفتاحاً */
    public function test_فشلُ_B_لا_يُنزل_حالةَ_الاعتماد(): void
    {
        Http::fake(['*' => function ($req) {
            if (str_contains($req->url(), '/health/test_connection')) {
                return Http::response(['detail' => ['error' => 'model not found']], 400);
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);

        $m = $this->seedModel('لا-نموذجَ-بهذا-الاسم');
        AiProbes::b($m, 'chat', true);

        $this->assertSame('configured', (string) $m->provider->fresh()->credential_state);
    }

    // ═══ ③ الرحلةُ نفسُها — الترتيبُ والواجهةُ لا الطبقةُ وحدَها ═══

    /**
     * **ولا يُطلَق B من الواجهةِ بلا نموذج.**
     *
     * الحارسُ في طبقةِ النقلِ يمنع الخمسمئة؛ وهذا يمنع أن يصلَ المالكُ إليها
     * أصلاً: الحقلُ اختيارٌ من نماذجَ مسجَّلة، والمسارُ يردّ بلا نموذج.
     */
    public function test_مسارُ_الفحصِ_يردّ_الطلبَ_بلا_نموذج(): void
    {
        $this->contractFake();
        $m = $this->seedModel();

        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('ai.providers.probe', $m->provider), ['mode' => 'chat', 'ack' => 1])
            ->assertRedirect()->assertSessionHasErrors('model_id');

        $this->assertNull($this->lastTest, '**خرج فحصٌ بلا نموذج** من مسارِ الواجهة');
    }

    /** **ولا يُفحَص اعتمادُ مزوّدٍ بنموذجِ مزوّدٍ آخر** */
    public function test_نموذجُ_مزوّدٍ_آخرَ_لا_يُقبَل(): void
    {
        $this->contractFake();
        $mine   = $this->seedModel();
        $theirs = $this->seedModel('other/upstream-x', 'hub-other');

        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('ai.providers.probe', $mine->provider),
                ['mode' => 'chat', 'ack' => 1, 'model_id' => $theirs->id])
            ->assertRedirect()->assertSessionHasErrors('model_id');

        $this->assertNull($this->lastTest, '**فُحِص اعتمادٌ بنموذجِ غيرِ صاحبِه**');
    }

    /** **وشاشةُ المزوّدين تقول صراحةً أنّ B يلزمه نموذجٌ حين لا نموذجَ بعدُ** */
    public function test_بطاقةُ_المزوّدِ_بلا_نماذجَ_تُرشد_لا_تُغري(): void
    {
        $this->contractFake();
        AiProviders::add('openai', 'مزوّدٌ بلا نماذج', ['api_key' => self::SECRET]);

        $html = (string) $this->actingAs($this->owner)->get(route('ai.providers.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('لا نموذجَ مسجَّلاً لهذا المزوّدِ بعدُ', $html,
            '**زرُّ فحصٍ يَعِد بما لا يستطيع**: B معروضٌ بلا نموذجٍ يُفحَص به');
        $this->assertStringNotContainsString('name="model_id"', $html,
            'حقلُ النموذجِ معروضٌ وقائمتُه فارغة');
    }

    /** **وحين توجد نماذجُ يصير الحقلُ اختياراً من مسجَّلٍ لا كتابةً حرّة** */
    public function test_بطاقةُ_المزوّدِ_تعرض_اختيارَ_نموذجٍ_مسجَّل(): void
    {
        $this->contractFake();
        $m = $this->seedModel();

        $html = (string) $this->actingAs($this->owner)->get(route('ai.providers.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="model_id"', $html);
        $this->assertStringContainsString('gpt-4o-mini', $html,
            'اسمُ النموذجِ عند المزوّدِ غيرُ معروضٍ في الاختيار');
    }

    /**
     * **وسلّمُ القبولِ لا يطلب B قبل أن يوجدَ نموذج.**
     *
     * والدرجةُ الثانيةُ صارت «اعتمادٌ مُدخَل» — وهي حالةٌ تتحقّق بإدخالِ
     * الاعتمادِ وحدَه، بلا إنفاقٍ ولا نموذج.
     */
    public function test_سلّمُ_القبولِ_يفصل_إدخالَ_الاعتمادِ_عن_إثباتِه(): void
    {
        $this->contractFake();
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];

        $path = collect(AiOverview::acceptancePath())->keyBy('key');

        $this->assertTrue((bool) $path['Credential']['done'],
            '**درجةٌ لا تتقدّم بما أُنجز**: الاعتمادُ مُدخَلٌ والسلّمُ ينكره');
        $this->assertFalse((bool) $path['B']['done'],
            '**ادّعاءُ إثبات**: B مُنجَزةٌ ولم يقبل المزوّدُ اعتمادَنا بعدُ');

        $this->assertSame('configured', (string) $p->fresh()->credential_state);
    }

    /** **وB تُطلَق من صفِّ النموذجِ أيضاً** — حيث الاسمُ عند المزوّدِ حاضر */
    public function test_مسارُ_النموذجِ_يقبل_المستوى_B(): void
    {
        $this->contractFake();
        $m = $this->seedModel();

        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('ai.models.probe', $m), ['level' => 'B', 'mode' => 'chat', 'ack' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('gpt-4o-mini', $this->lastTest['litellm_params']['model'] ?? null,
            'أُرسل غيرُ اسمِ النموذجِ عند المزوّد');
    }
}

<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Catalog\AiModelSources;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **شاشةُ الاكتشافِ — الرحلةُ من الاعتمادِ إلى الفاحصِ B بلا كتابةِ معرّف.**
 *
 * هذا الصنفُ يقيس **الرحلةَ** لا الوحدة: هل يستطيع مديرٌ أدخل اعتماداً للتوّ
 * أن يبلغ نموذجاً مسجَّلاً وفاحصاً قابلاً للتشغيل **بلا أن يفتح صفحةَ مزوّدٍ
 * في متصفّحٍ آخر ويبحث عن معرّفِ نموذج؟**
 */
class AiModelDiscoveryScreenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-SCRN9a3d5e1f88cc4477bb';

    private array $costMap = [];

    private array $registry = [];

    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            $url = $req->url();
            if (str_contains($url, '/public/litellm_model_cost_map')) return Http::response($this->costMap, 200);
            if (str_contains($url, '/model/info'))  return Http::response(['data' => $this->registry], 200);
            if (str_contains($url, '/model/new')) {
                $this->created[] = (array) json_decode((string) $req->body(), true);

                return Http::response(['model_id' => 'dep-' . count($this->created)], 200);
            }
            if (str_contains($url, '/health/test_connection')) {
                $sent = (array) json_decode((string) $req->body(), true);
                if (! array_key_exists('model', (array) ($sent['litellm_params'] ?? []))) {
                    return Http::response(['detail' => ['error' => "Failed to test connection: 'model'"]], 500);
                }
                $this->lastTest = $sent;

                return Http::response(['status' => 'success', 'result' => []], 200);
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    private ?array $lastTest = null;

    /** فاعلٌ بأدنى الرايات — لقياسِ البابِ لا لقياسِ المالك */
    private function actor(array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ' . \Illuminate\Support\Str::random(6), 'scope' => 'all',
            'flags' => $flags, 'matrix' => []]);

        return User::create(['name' => 'مُختبِر', 'email' => \Illuminate\Support\Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    private function provider(string $key = 'openai'): AiProvider
    {
        $p = AiProviders::add($key, 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    private function entry(string $provider = 'openai', string $mode = 'chat'): array
    {
        return ['litellm_provider' => $provider, 'mode' => $mode,
                'max_input_tokens' => 128000, 'supports_vision' => true];
    }

    // ═══ ① الشاشةُ تعرض ما اكتُشف ═══

    public function test_شاشةُ_الاكتشافِ_تعرض_المرشَّحين_ومصادرَهم(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/alpha' => $this->entry(), 'fam/beta' => $this->entry(mode: 'embedding')];

        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.models.browse', $p))->assertOk()->getContent();

        $this->assertStringContainsString('fam/alpha', $html);
        $this->assertStringContainsString('fam/beta', $html);
        $this->assertStringContainsString(AiModelSources::CATALOG, $html,
            '**مرشَّحٌ بلا مصدر**: القائمةُ تُعرَض ولا يُقال من أين جاءت');
    }

    /** **ولا سرَّ في الصفحةِ** — ولو كان المزوّدُ مضبوطاً باعتمادٍ حيّ */
    public function test_صفحةُ_الاكتشافِ_لا_تحمل_سرّاً(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/alpha' => $this->entry()];

        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.models.browse', $p))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    /** **والقائمةُ الفارغةُ تُرشد ولا تكذب** */
    public function test_لا_مرشَّحَ_فتُقال_الحقيقةُ_ويُوجَّه_إلى_التسجيلِ_اليدويّ(): void
    {
        $p = $this->provider();
        $this->costMap = ['other/x' => $this->entry(provider: 'someone_else')];

        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.models.browse', $p))->assertOk()->getContent();

        $this->assertStringContainsString('لا اكتشافَ تلقائيَّ لهذا المزوّد', $html);
        $this->assertStringContainsString(route('ai.models.index', $p), $html);
    }

    /** **والترشيحُ بالوضعِ يمرّ بقائمةٍ بيضاءَ لا بمُدخَلٍ حرّ** */
    public function test_ترشيحٌ_بوضعٍ_مُختلَقٍ_يُتجاهَل_لا_يُنفَّذ(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/alpha' => $this->entry()];

        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.models.browse', $p) . '?mode=' . urlencode('<script>x</script>'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('fam/alpha', $html, 'وضعٌ مُختلَقٌ أفرغ القائمة');
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    // ═══ ② الباب — العرضُ للقارئ والكتابةُ للمدير ═══

    public function test_حاملُ_الاطّلاعِ_يرى_ولا_يتبنّى(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/alpha' => $this->entry()];

        $u = $this->actor(['aiView' => 1]);

        $this->actingAs($u)->get(route('ai.models.browse', $p))->assertOk();
        $this->actingAs($u)->post(route('ai.models.adopt', $p),
            ['picks' => [AiModelSources::CATALOG . '|fam/alpha']])->assertForbidden();

        $this->assertSame([], $this->created);
    }

    /** **والتبنّي كتابةٌ فيلزمه تصعيد** */
    public function test_التبنّي_يلزمه_تصعيد(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/alpha' => $this->entry()];

        $this->actingAs($this->owner)
            ->post(route('ai.models.adopt', $p), ['picks' => [AiModelSources::CATALOG . '|fam/alpha']])
            ->assertRedirect();

        $this->assertSame([], $this->created, '**كتابةٌ بلا تصعيد**: النموذجُ سُجّل عند البوّابة');
    }

    // ═══ ③ الرحلةُ كاملةً: اعتمادٌ → اكتشافٌ → تبنٍّ → B ═══

    /**
     * **الرحلةُ المطلوبةُ من طرفٍ إلى طرف.**
     *
     * ولا يُكتَب في أيِّ خطوةٍ منها معرّفُ نموذجٍ بيد.
     */
    public function test_من_الاعتمادِ_إلى_الفاحصِ_B_بلا_كتابةِ_معرّف(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/journey-1' => $this->entry()];

        // ① اكتشافٌ — قراءةٌ بكلفةِ صفر
        $this->actingAs($this->owner)->get(route('ai.models.browse', $p))
            ->assertOk()->assertSee('fam/journey-1', false);

        // ② تبنٍّ بضغطة
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.models.adopt', $p), ['picks' => [AiModelSources::CATALOG . '|fam/journey-1']])
            ->assertRedirect(route('ai.models.index', $p))->assertSessionHasNoErrors();

        $m = AiModel::query()->where('upstream_model', 'fam/journey-1')->firstOrFail();
        $this->assertSame(AiModelSources::CATALOG, (string) $m->discovery_source);

        // ③ والفاحصُ B يعمل عليه فوراً — بالمعرّفِ لا بالاسمِ الداخليّ
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.models.probe', $m), ['level' => 'B', 'mode' => 'chat', 'ack' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('fam/journey-1', $this->lastTest['litellm_params']['model'] ?? null,
            '**اسمُ Hub أُرسل إلى المزوّد** — وهو ما يُطارَد يوماً كاملاً على أنّه مفتاحٌ خاطئ');
        $this->assertNotSame($m->litellm_model_name, $this->lastTest['litellm_params']['model']);
    }

    /** **وبطاقةُ المزوّدِ تُصدِّر الرحلةَ من الاكتشافِ لا من الكتابةِ اليدويّة** */
    public function test_بطاقةُ_المزوّدِ_تعرض_بابَ_الاكتشاف(): void
    {
        $p = $this->provider();

        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.providers.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('ai.models.browse', $p), $html,
            '**بابُ الرحلةِ مخفيّ**: المديرُ لا يجد الاكتشافَ فيكتب المعرّفَ بيدِه');
    }

    // ═══ ④ عزلُ الاعتمادات ═══

    /**
     * **ما سُجِّل عند البوّابةِ باعتمادِ غيرِنا لا يُنسَب إلينا.**
     *
     * والخلطُ هنا ليس تجميلاً: نموذجٌ يُستورَد بمرجعِ اعتمادِ مزوّدٍ آخر
     * **يُنفَق عليه من حسابٍ ليس حسابَه**.
     */
    public function test_نماذجُ_اعتمادٍ_آخرَ_لا_تُنسَب_إلينا(): void
    {
        $mine   = $this->provider();
        $theirs = $this->provider('mistral');

        $this->registry = [[
            'model_name'     => 'hub-theirs',
            'litellm_params' => ['model' => 'fam/theirs', 'litellm_credential_name' => $theirs->credential_name],
            'model_info'     => ['mode' => 'chat'],
        ]];

        $found = AiModelSources::discover($mine);
        $ids   = array_column($found['candidates'], 'upstream_model');

        $this->assertNotContains('fam/theirs', $ids,
            '**تسريبُ اعتماد**: نموذجُ مزوّدٍ آخرَ عُرض تحت هذا المزوّد');
        $this->assertGreaterThan(0, $found['unowned'], 'العددُ لم يُذكَر — فالشاشةُ تدّعي أنّ البوّابةَ فارغة');
    }

    // ═══ ⑤ ردٌّ مُشوَّهٌ لا يُسقِط الشاشة ═══

    public function test_ردٌّ_مُشوَّهٌ_يُتجاوَز_ولا_يُسقِط_الاكتشاف(): void
    {
        $p = $this->provider();
        $this->costMap = [
            'fam/good'  => $this->entry(),
            'fam/bad1'  => 'ليس كائناً',
            'fam/bad2'  => ['no_provider_key' => true],
            'fam/bad3'  => ['litellm_provider' => 'openai', 'mode' => ['مصفوفةٌ حيث يُنتظَر نصّ']],
        ];

        $found = AiModelSources::discover($p);

        $this->assertTrue($found['ok']);
        $ids = array_column($found['candidates'], 'upstream_model');
        $this->assertContains('fam/good', $ids);
        $this->assertNotContains('fam/bad1', $ids);
        $this->assertNotContains('fam/bad2', $ids);
    }

    // ═══ ⑥ الحارسُ الشبكيُّ نفسُه ═══

    /**
     * **ومسارُ الكتالوجِ يمرّ بحارسِ الخروجِ كغيرِه** — فلا بابٌ جديدٌ للـSSRF.
     */
    public function test_قراءةُ_الكتالوجِ_تمرّ_بحارسِ_الخروج(): void
    {
        $p = $this->provider();
        Settings::put('ai.gateway_url', 'http://169.254.169.254', 'test');

        $found = AiModelSources::discover($p);

        $this->assertFalse($found['sources'][AiModelSources::CATALOG]['ok'],
            '**ثقبُ SSRF**: مسارُ الكتالوجِ خرج إلى عنوانٍ يمنعه الحارس');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
    }
}

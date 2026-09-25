<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\Ai\Catalog\AiModels;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **شاشةُ سجلِّ النماذج** (المرحلة ٢ · W5) — البابُ والهويّةُ والصدق.
 *
 * وأهمُّ ما تحرسه: أنّ الشاشةَ **تقول «غيرُ معروفة»** ولا تُخفيها بوصفِها
 * «غيرُ مدعومة». الاختيارُ الأعمى يُنتج توجيهاً أعمى.
 */
class AiModelScreenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-W5SCREEN7b3f1d8e22cc6699a4b0';

    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        // **مُرصِدٌ واحدٌ يُنصَب مرّة** — `Http::fake` يُراكِم ولا يستبدل
        Http::fake(['*' => function ($req) {
            return str_contains($req->url(), '/model/info')
                ? Http::response(['data' => $this->entries], 200)
                : Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    private function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    private function seedProvider(): AiProvider
    {
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    private function announce(AiProvider $p, array $info = ['mode' => 'chat']): void
    {
        $this->entries = [[
            'model_name'     => 'hub-alpha',
            'litellm_params' => ['model' => 'fake/upstream-a', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => $info,
        ]];
    }

    // ═══ البابُ والهويّة ═══

    public function test_موظّفٌ_عاديٌّ_يُصَدّ(): void
    {
        $p = $this->seedProvider();
        $this->actingAs($this->employee)->get(route('ai.models.index', $p))->assertForbidden();
    }

    public function test_المالكُ_يفتح_السجلّ(): void
    {
        $p = $this->seedProvider();
        $this->actingAs($this->owner)->get(route('ai.models.index', $p))->assertOk();
    }

    /** الاكتشافُ قراءةٌ محضةٌ — فلا تصعيدَ عليه، ولا يكتب صفّاً */
    public function test_الاكتشافُ_يمرّ_بلا_تصعيدٍ_ولا_يكتب(): void
    {
        $p = $this->seedProvider();
        $this->announce($p);

        $this->actingAs($this->owner)->post(route('ai.models.discover', $p))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, AiModel::count(), 'الاكتشافُ كتب صفّاً وهو قراءةٌ محضة');
    }

    /** أمّا الكتابةُ فخلف الهويّةِ الطازجة */
    public function test_كلُّ_كتابةٍ_تحتاج_هويّةً_طازجة(): void
    {
        $p = $this->seedProvider();
        $this->announce($p);
        AiModels::import($p, ['hub-alpha']);
        $m = AiModel::firstOrFail();

        $writes = [
            [route('ai.models.import', $p),   ['models' => ['hub-alpha']]],
            [route('ai.models.register', $p), ['hub_name' => 'hub-x', 'upstream' => 'fake/x']],
            [route('ai.models.refresh', $p),  []],
            [route('ai.models.configure', $m), ['display_name' => 'س']],
            [route('ai.models.toggle', $m),   ['enabled' => 1]],
            [route('ai.models.override', $m), ['group' => 'capabilities', 'key' => 'vision', 'value' => '1']],
        ];

        foreach ($writes as [$uri, $payload]) {
            $res = $this->actingAs($this->owner)->post($uri, $payload);
            $res->assertRedirect();
            $this->assertStringContainsString('/stepup', (string) $res->headers->get('Location'),
                "الكتابةُ {$uri} مرّت بلا تصعيدِ هويّة");
        }
    }

    // ═══ الدورةُ من الشاشة ═══

    public function test_الدورةُ_الأربعُ_تعمل_من_الشاشة(): void
    {
        $p = $this->seedProvider();
        $this->announce($p);

        // ① اكتشاف → ② مراجعةٌ في الجلسة
        $this->actingAs($this->owner)->post(route('ai.models.discover', $p))
            ->assertSessionHas('ai.candidates.' . $p->id);

        // ③ اختيار — ويولد مُعطَّلاً
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.models.import', $p), ['models' => ['hub-alpha']])
            ->assertRedirect(route('ai.models.index', $p));

        $m = AiModel::firstOrFail();
        $this->assertFalse($m->enabled, '**الاستيرادُ من الشاشةِ فعّل النموذج**');

        // ④ تهيئة
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.models.configure', $m), ['display_name' => 'نموذجُ الجولة', 'priority' => 5]);
        $this->assertSame('نموذجُ الجولة', (string) $m->fresh()->display_name);

        // ثمّ التفعيل — قرارٌ صريحٌ منفصل
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.models.toggle', $m), ['enabled' => 1]);
        $this->assertTrue($m->fresh()->enabled);
    }

    // ═══ الصدقُ في العرض ═══

    /**
     * **الشاشةُ تقول «غيرُ معروفة» ولا تكتمها.**
     *
     * حمولةٌ لا تذكر `supports_vision` — فالقدرةُ مجهولة. وعرضُها «غيرَ مدعومة»
     * يُغلق باباً مفتوحاً، وكتمانُها يجعل المديرَ يختار أعمى.
     */
    public function test_الشاشةُ_تُصرّح_بالمجهولِ_ولا_تُسمّيه_غيرَ_مدعوم(): void
    {
        $p = $this->seedProvider();
        $this->announce($p, ['mode' => 'chat']);       // بلا أعلامٍ إطلاقاً
        AiModels::import($p, ['hub-alpha']);

        $html = $this->actingAs($this->owner)->get(route('ai.models.index', $p))->assertOk()->getContent();

        $this->assertStringContainsString('غيرُ معروفة', (string) $html,
            'الشاشةُ لا تقول «غيرُ معروفة» — والمديرُ يختار أعمى');
        $this->assertStringContainsString('unknown', (string) $html,
            'المصدرُ لا يُعرَض — فقيمةٌ بلا مصدرٍ تصير أسطورةً بعد شهر');
    }

    /** والسعرُ يُقال **تقديريّاً** — الحسابُ من خريطةٍ لا من فاتورة */
    public function test_السعرُ_يُعرَض_تقديريّاً(): void
    {
        $p = $this->seedProvider();
        $this->announce($p, ['mode' => 'chat', 'input_cost_per_token' => 0.0000025]);
        AiModels::import($p, ['hub-alpha']);

        $this->actingAs($this->owner)->get(route('ai.models.index', $p))->assertOk()
            ->assertSee('تقديريّ', false)
            ->assertSee('USD');
    }

    public function test_لا_سرَّ_في_صفحةِ_النماذج(): void
    {
        $p = $this->seedProvider();
        $this->announce($p);
        AiModels::import($p, ['hub-alpha']);

        $html = $this->actingAs($this->owner)->get(route('ai.models.index', $p))->assertOk()->getContent();

        $this->assertMaskedValueAbsent(self::SECRET, (string) $html,
            '**تسريب**: سرُّ المزوّدِ ظهر في صفحةِ النماذج');
    }

    // ═══ الفواحص من الشاشة (W6) ═══

    /** **C مجّانيٌّ** فيمرّ بلا إقرارِ كلفة */
    public function test_المستوى_C_يمرّ_بلا_إقرار(): void
    {
        $p = $this->seedProvider();
        $this->announce($p);
        AiModels::import($p, ['hub-alpha']);
        $m = AiModel::firstOrFail();

        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.models.probe', $m), ['level' => 'C'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('CONNECTED', (string) $m->fresh()->health);
    }

    /** **وD وE يُرَدّان بلا إقرارٍ صريح — ولا يُرسَل طلبُ توليدٍ واحد** */
    public function test_المدفوعةُ_تُرَدّ_من_الشاشةِ_بلا_إقرار(): void
    {
        $p = $this->seedProvider();
        $this->announce($p);
        AiModels::import($p, ['hub-alpha']);
        $m = AiModel::firstOrFail();

        foreach ([['level' => 'D'], ['level' => 'E', 'capability' => 'tools']] as $payload) {
            $this->actingAs($this->owner)->stepped()
                ->post(route('ai.models.probe', $m), $payload)
                ->assertRedirect()->assertSessionHasErrors('ack');
        }

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    /** وفحصُ الاعتمادِ B كذلك — والوضعُ إلزاميّ */
    public function test_فحصُ_الاعتمادِ_يُرَدّ_بلا_إقرار(): void
    {
        $p = $this->seedProvider();

        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.providers.probe', $p), ['mode' => 'chat'])
            ->assertRedirect()->assertSessionHasErrors('ack');

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/health/test_connection'));
    }

    /** والشاشةُ تُعلن أيَّ الفحوصِ يُنفق — فلا يُضغَط زرٌّ مُكلِفٌ بلا علم */
    public function test_الشاشةُ_تُعلن_الفحوصَ_المدفوعة(): void
    {
        $p = $this->seedProvider();
        $this->announce($p);
        AiModels::import($p, ['hub-alpha']);

        $html = (string) $this->actingAs($this->owner)->get(route('ai.models.index', $p))
            ->assertOk()
            ->assertSee('تُنفق رصيداً', false)
            ->assertSee('أُقِرُّ بأنّ هذا الفحصَ يُنفق رصيداً', false)
            ->getContent();

        // **وB على صفِّ النموذجِ** — فهي تختبر (اعتماداً × نموذجاً) لا اعتماداً وحدَه
        $this->assertStringContainsString('value="B"', $html,
            '**فحصُ الاعتمادِ بلا مكانٍ يُطلَق منه**: B غائبٌ عن صفِّ النموذجِ الذي يحمل اسمَه عند المزوّد');
    }

    /** والشاشةُ تقول صراحةً حين لا اكتشافَ آليَّ للمزوّد — بدل أن تتظاهر به */
    public function test_الشاشةُ_تُعلن_غيابَ_الاكتشافِ_الآليّ(): void
    {
        $p = AiProviders::add('azure_openai', 'مركّبٌ وهميّ', [
            'api_key' => self::SECRET, 'api_base' => 'https://fake.invalid',
            'api_version' => '2024-10-21', 'deployment' => 'fake-dep',
        ])['provider'];

        $this->actingAs($this->owner)->get(route('ai.models.index', $p))->assertOk()
            ->assertSee('لا اكتشافَ آليَّ لهذا المزوّد', false);
    }
}

<?php

namespace Tests\Feature\AiHub;

use App\Models\AiBudget;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;
use App\Models\AiProvider;
use App\Support\Ai\Center\AiQuickSetup;
use App\Support\Ai\Routing\AiProfiles;
use Tests\TestCase;

/**
 * **الإعدادُ السريع** — يملأ الفارغَ وحدَه، بالأهليّة نفسِها، بترتيبٍ مُعلَن؛ ميزانيّةٌ إن غابت؛ والبحثُ بالمعنى بطلب.
 */
class AiQuickSetupTest extends TestCase
{
    private AiProvider $prov;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        AiProfiles::seed();
        $this->prov = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-qs-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
    }

    private function model(string $name, array $caps, float $price, string $health = 'HEALTHY', bool $enabled = true): AiModel
    {
        return AiModel::create(['provider_id' => $this->prov->id, 'litellm_model_name' => $name, 'upstream_model' => 'x/' . $name,
            'display_name' => $name, 'enabled' => $enabled, 'health' => $health,
            'capabilities' => collect($caps)->mapWithKeys(fn ($v, $k) => [$k => ['v' => $v, 'src' => 'litellm']])->all(),
            'limits' => [], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => $price, 'src' => 'litellm'], 'output_per_1k' => ['v' => $price, 'src' => 'litellm'],
                'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
    }

    private function chain(string $key): array
    {
        $p = AiProfile::query()->where('key', $key)->firstOrFail();

        return AiProfileModel::query()->where('profile_id', $p->id)->orderBy('rank')->get()
            ->map(fn ($l) => (string) AiModel::find($l->model_id)->litellm_model_name)->all();
    }

    public function test_يملأ_الفارغَ_وحدَه_بالأهليّة_وبترتيبٍ_مُعلَن(): void
    {
        $this->model('strong-chat', ['chat' => true, 'tools' => true], 0.01);
        $this->model('cheap-chat', ['chat' => true, 'tools' => true], 0.0005);
        $this->model('no-tools', ['chat' => true, 'tools' => false], 0.02);
        $this->model('dead-chat', ['chat' => true, 'tools' => true], 0.05, 'UNAVAILABLE');
        $this->model('off-chat', ['chat' => true, 'tools' => true], 0.05, 'HEALTHY', false);
        $this->model('emb-small', ['embeddings' => true], 0.00002);
        $this->model('emb-unknown', [], 0.00001);
        // «سريع» ضبطه المدير — لا يُمسّ
        $manual = $this->model('manual-fast', ['chat' => true], 0.001);
        AiProfiles::attach(AiProfile::query()->where('key', 'fast')->firstOrFail(), $manual);

        $r = AiQuickSetup::apply(50, true);

        $this->assertSame(['strong-chat', 'cheap-chat'], $this->chain('general'), 'الأقوى أوّلاً للعامّ، والمُصدِرُ للأدوات قبل غيره');
        $this->assertSame(['strong-chat', 'cheap-chat'], $this->chain('coding'));
        $this->assertSame(['cheap-chat', 'strong-chat'], $this->chain('cheap'), 'الأرخصُ أوّلاً للاقتصاديّ');
        $this->assertSame(['manual-fast'], $this->chain('fast'), 'المضبوطُ لا يُمسّ');
        $this->assertSame(['emb-small'], $this->chain('embedding'), 'قدرةٌ مجهولةٌ لا تُرقّى بالصمت');
        $this->assertTrue($r['budget']);
        $this->assertSame(50_000_000, (int) AiBudget::query()->where('key', 'global-monthly')->value('limit_micro'));
        // ولكلِّ غرضٍ مُلئ سقفان: ٥٠٪ شهريّاً و١٠٪ يوميّاً
        $this->assertSame(25_000_000, (int) AiBudget::query()->where('key', 'purpose-embedding-monthly')->value('limit_micro'));
        $this->assertSame(5_000_000, (int) AiBudget::query()->where('key', 'purpose-embedding-daily')->value('limit_micro'));
        $this->assertSame('embedding', (string) AiBudget::query()->where('key', 'purpose-embedding-daily')->value('scope_id'));
        $this->assertSame('1', (string) setting('brain.enabled'));

        // والثانيةُ لا تفعل شيئاً: كلُّه مضبوط، والميزانيّةُ موجودة
        $again = AiQuickSetup::apply(50, true);
        $this->assertSame(0, $again['attached']);
        $this->assertFalse($again['budget']);
        $this->assertSame(1 + 2 * 5, AiBudget::query()->count(), 'عامٌّ + سقفان لكلٍّ من الأغراض الخمسة');
    }

    public function test_بلا_نموذج_تضمين_لا_يُفعَّل_البحثُ_بالمعنى_ولا_بلا_طلب(): void
    {
        $this->model('chat-a', ['chat' => true], 0.001);
        $plan = AiQuickSetup::plan();
        $this->assertSame('none', collect($plan['profiles'])->firstWhere('key', 'embedding')['state']);
        AiQuickSetup::apply(0, true);
        $this->assertNotSame('1', (string) setting('brain.enabled', '0'));
        $this->assertSame(0, AiBudget::query()->count(), 'صفرٌ ⇒ لا ميزانيّة');

        $this->model('emb-a', ['embeddings' => true], 0.00002);
        AiQuickSetup::apply(0, false);
        $this->assertNotSame('1', (string) setting('brain.enabled', '0'), 'بلا طلبٍ صريح لا يُفعَّل');
    }

    public function test_الواجهةُ_للمدير_والمعاينةُ_لا_تكتب(): void
    {
        $this->model('chat-b', ['chat' => true, 'tools' => true], 0.001);
        $this->artisan('hub:ai-setup')->expectsOutputToContain('معاينةٌ فقط')->assertSuccessful();
        $this->assertSame([], $this->chain('general'));

        $this->actingAs($this->employee)->post(route('ai.quick_setup'), ['usd' => 50])->assertForbidden();
        $this->actingAs($this->owner)->get(route('ai.index'))->assertOk()->assertSee('data-ai-quick', false);
        $this->actingAs($this->owner)->post(route('ai.quick_setup'), ['usd' => 30])->assertRedirect(route('ai.index'));
        $this->assertSame(['chat-b'], $this->chain('general'));
        $this->assertSame(30_000_000, (int) AiBudget::query()->where('key', 'global-monthly')->value('limit_micro'));
    }

    public function test_الاحتياطُ_من_مزوّدٍ_آخرَ_إن_وُجد(): void
    {
        $this->model('a-1', ['chat' => true, 'tools' => true], 0.03);
        $this->model('a-2', ['chat' => true, 'tools' => true], 0.02);
        $other = AiProvider::create(['catalog_key' => 'anthropic', 'label' => 'مزوّدٌ ثانٍ', 'enabled' => true,
            'credential_name' => 'hub-qs2-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $prov = $this->prov;
        $this->prov = $other;
        $this->model('b-1', ['chat' => true, 'tools' => true], 0.001);
        $this->prov = $prov;

        AiQuickSetup::apply(0);

        $this->assertSame(['a-1', 'b-1'], $this->chain('general'), 'الأقوى أساسيّ، والاحتياطُ من المزوّد الآخر لا من المزوّد نفسِه');
    }
}

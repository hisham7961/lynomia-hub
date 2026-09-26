<?php

namespace Tests\Feature\Mobile;

use App\Contracts\AskGenerator;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AskThread;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Ask\AskMemory;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\Settings;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\ScriptedGenerator;
use Tests\TestCase;

/**
 * **«اسأل Hub» على الجوال** (`/api/mobile/v1/ask*` · المرحلة ٢) — السطحُ نفسُه بحرّاسه نفسِها:
 * البابُ `canAsk`، والعميلُ محجوبٌ بالسياج، والخيوطُ لصاحبها وحدَه (غيرُه ٤٠٤)، والجوابُ المحفوظُ
 * يُعاد تحقّقُه، ولا مظروفَ ولا مفتاحَ ملاحظةٍ في الحمولة.
 */
class MobileAskTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->company = Company::create(['name_ar' => 'شركةُ الجوال']);
        Project::create(['name' => 'مشروعُ الجوال', 'company_id' => $this->company->id]);
    }

    private function asker(bool $flag = true, array $extra = []): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => $flag ? [AskPolicy::FLAG => 1] : [], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => Str::random(9) . '@mob.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$this->company->id]] + $extra);
    }

    private function ready(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        Settings::put('ai.enabled', '1', 'test');
        Settings::put('ai.probe_ok', '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_ok', '1', 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');

        // **ذاكرةُ سجلِّ القدراتِ تُبطَل بعد تغييرِ الإعدادات.**
        // `FeatureRegistry::resolveAll()` يحفظ نتيجتَه في ثابتٍ ساكن — وهو
        // الصوابُ في الإنتاج (طلبٌ واحدٌ لا يُعيد اشتقاقَ مئةِ قدرة)، لكنّه
        // **يعبر بين أصنافِ الاختبارِ في العمليّةِ الواحدة**: صنفٌ سابقٌ حسم
        // «ai.assistant» وبوّابتُه غيرُ مهيّأة، فتبقى مُطفأةً هنا مهما ضبطنا.
        \App\Support\Platform\FeatureRegistry::flush();

        AiProfiles::seed();

        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-fake-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'configured',
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => 'fake-chat',
            'upstream_model' => 'fake/fake-chat', 'display_name' => 'نموذجٌ وهميّ',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => [
                // **و`tools` معلَنةٌ لأنّ مسارَ المساعدِ كلَّه دورةُ أدوات** (المرحلة ٥ · W2)
                'chat'  => ['v' => true, 'src' => 'litellm'],
                'tools' => ['v' => true, 'src' => 'litellm'],
            ],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);
        AiProfiles::attach(AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);
    }

    private function bindAnswer(string $text): ScriptedGenerator
    {
        $gen = new ScriptedGenerator([['kind' => 'tool', 'tool' => 'hub_list', 'args' => ['module' => 'projects']],
            ['kind' => 'answer', 'answer' => $text, 'sources' => [1]]]);
        $this->app->instance(AskGenerator::class, $gen);

        return $gen;
    }

    private function auth(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    public function test_السؤالُ_والمتابعةُ_والخيوطُ_لصاحبها(): void
    {
        $u = $this->asker();
        $this->ready();
        $h = $this->auth($u);

        $this->bindAnswer('لديك مشروعٌ واحد QWXZ-MOB');
        $r = $this->postJson('/api/mobile/v1/ask', ['q' => 'ما مشاريعي؟'], $h)->assertOk();
        $this->assertTrue($r->json('data.ok'));
        $this->assertStringContainsString('QWXZ-MOB', $r->json('data.answer'));
        $this->assertSame('projects', $r->json('data.sources.0.module'));
        $thread = $r->json('data.thread');
        $this->assertNotNull($thread);

        // لا مظروفَ ولا حمولةَ أداةٍ ولا مفتاحَ ملاحظة
        $keys = $this->allKeysDeep($r->json());
        foreach (['envelope', 'fence', 'refs', 'findings', 'scope', 'fields'] as $k) {
            $this->assertNotContains($k, $keys, "مفتاحٌ داخليٌّ «{$k}» في حمولة الجوال");
        }

        $gen = $this->bindAnswer('جوابُ المتابعة');
        $this->postJson('/api/mobile/v1/ask', ['q' => 'وكم عددها؟', 'thread' => $thread], $h)->assertOk()
            ->assertJsonPath('data.thread', $thread);
        $this->assertStringContainsString('ما مشاريعي؟', $gen->askedWith, 'المتابعةُ تحمل السؤالَ السابق');
        $this->assertStringNotContainsString('QWXZ-MOB', $gen->askedWith, 'لا الجوابَ السابق');

        $this->getJson('/api/mobile/v1/ask/threads', $h)->assertOk()->assertJsonPath('data.threads.0.id', $thread);
        $turns = $this->getJson("/api/mobile/v1/ask/threads/{$thread}", $h)->assertOk()->json('data.turns');
        $this->assertCount(2, $turns);
        $this->assertFalse($turns[0]['hidden']);
    }

    public function test_خيطُ_غيري_٤٠٤_قراءةً_وسؤالاً_ومحواً(): void
    {
        $alice = $this->asker();
        $bob = $this->asker();
        $t = AskMemory::record($alice, null, 'سؤالُ أليس', ['ok' => true, 'answer' => 'سرّ', 'sources' => []]);
        $this->ready();
        $h = $this->auth($bob);

        $this->getJson("/api/mobile/v1/ask/threads/{$t->id}", $h)->assertNotFound();
        $this->deleteJson("/api/mobile/v1/ask/threads/{$t->id}", [], $h)->assertNotFound();
        $this->bindAnswer('x');
        $this->postJson('/api/mobile/v1/ask', ['q' => 'تسلّل', 'thread' => $t->id], $h)->assertNotFound();
        $this->deleteJson('/api/mobile/v1/ask/threads', [], $h)->assertOk()->assertJsonPath('data.deleted', 0);
        $this->assertNotNull(AskThread::find($t->id), 'محوُ الكلِّ عند غيرِه لا يمسّ خيطَها');
        $this->getJson('/api/mobile/v1/ask/threads', $h)->assertOk()->assertJsonPath('data.threads', []);
    }

    public function test_بلا_رايةٍ_٤٠٣_وحسابُ_العميل_محجوبٌ_بالسياج(): void
    {
        $this->ready();
        $plain = $this->asker(false);
        $this->postJson('/api/mobile/v1/ask', ['q' => 'سؤال'], $this->auth($plain))->assertForbidden();
        $this->getJson('/api/mobile/v1/ask/threads', $this->auth($plain))->assertForbidden();

        $client = $this->asker(true, ['account_type' => 'client']);
        // السياجُ يردّ ما خارج قائمة العميل البيضاء ٤٠٤ — لا يكشف حتى وجودَ المسار (MobilePortalGuard)
        $this->postJson('/api/mobile/v1/ask', ['q' => 'سؤال'], $this->auth($client))->assertNotFound();
        $this->getJson('/api/mobile/v1/ask/threads', $this->auth($client))->assertNotFound();
    }

    public function test_الجوابُ_المحفوظُ_يُخفى_على_الجوال_حين_يضيق_النطاق(): void
    {
        $u = $this->asker();
        $other = Company::create(['name_ar' => 'أخرى']);
        $p = Project::query()->where('company_id', $this->company->id)->firstOrFail();
        $t = AskMemory::record($u, null, 'حالُ المشروع؟', ['ok' => true, 'answer' => 'QWXZ-STATE',
            'sources' => [['module' => 'projects', 'ids' => [$p->id], 'refs' => []]]]);
        $u->forceFill(['companies' => [$other->id]])->save();

        $turn = $this->getJson("/api/mobile/v1/ask/threads/{$t->id}", $this->auth($u->fresh()))->assertOk()->json('data.turns.0');
        $this->assertTrue($turn['hidden']);
        $this->assertNull($turn['answer']);
    }

    public function test_المسارُ_موثَّقٌ_في_المواصفة_ومصنَّفٌ_في_بيان_القدرات(): void
    {
        $spec = $this->getJson('/api/mobile/v1/openapi.json')->assertOk()->json();
        $this->assertArrayHasKey('/api/mobile/v1/ask', $spec['paths']);
        $this->assertArrayHasKey('/api/mobile/v1/ask/threads/{id}', $spec['paths']);
        $caps = \App\Support\Mobile\MobileOpenApi::capabilities();
        $this->assertSame(5, $caps['areas']['ask']['count']);
    }
}

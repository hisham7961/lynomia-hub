<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Understanding\ProjectUnderstanding;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **ملفُّ فهم المشروع واقتراحاتُه** (docs/ai-hub/47 §العمود ج — المرحلة ٥). بلا دينارٍ: `Http::fake`.
 * يُثبت: ما يُرسل (الوصفُ والمهامُّ ونصُّ الموقع — لا الميزانيّةُ ولا التكلفة)، والردُّ يُنقّى، والبناءُ مرّةً
 * لكلِّ بصمة، والعرضُ لمن يراه (files:v لما بُني من الوثائق)، والاقتراحُ يتحوّل مسودةَ مهمّةٍ أو يُتجاهَل.
 */
class ProjectUnderstandingTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    private array $reply = [];

    private string $pid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Cache::flush();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();
        $provider = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-pu-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);
        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);

            return Http::response(LiteLlmFixtures::answer(json_encode($this->reply, JSON_UNESCAPED_UNICODE), LiteLlmFixtures::usage()), 200);
        });

        $this->hubSetting('ai.understanding', '1');
        $this->hubSetting('reports.project_digest', '1');
        $this->hubSetting('reports.project_digest_profile', 'general');

        $this->pid = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $this->pid, 'name' => 'منصّةُ الحجوزات', 'status' => 'قيد التنفيذ',
            'description' => 'منصّةٌ لحجز الفنادق في الخليج مع دفعٍ إلكترونيّ', 'budget' => 987654, 'cost' => 123456,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('tasks')->insert(['id' => (string) Str::uuid(), 'title' => 'ربطُ بوابة الدفع', 'project_id' => $this->pid,
            'status' => 'قيد التنفيذ', 'due' => now()->subDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_snapshots')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->pid, 'url' => 'https://booking.example.com',
            'status' => 'ok', 'pages' => '[]', 'meta' => json_encode(['title' => 'احجز الآن', 'broken' => []]),
            'text' => 'نقدّم حجزاً فوريّاً مع إلغاءٍ مجانيّ', 'hash' => 'h1', 'changed' => false, 'fetched_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);

        $this->reply = ['what' => 'منصّةُ حجوزاتٍ فندقيّة', 'audience' => 'المسافرون في الخليج', 'stage' => 'قبل الإطلاق',
            'promise_vs_reality' => ['الموقعُ يَعِد بإلغاءٍ مجانيّ ولا مهمّةَ لسياسة الإلغاء'], 'risks' => ['تأخّرُ بوابة الدفع'],
            'decisions' => [], 'gaps' => ['لا مهمّةَ للإلغاء المجانيّ'], 'suggestions' => [
                ['type' => 'action', 'text' => 'أنشئ مهمّةً لسياسة الإلغاء المجانيّ التي يَعِد بها الموقع', 'basis' => ['site', 'tasks', 'hacked']],
                ['type' => 'nonsense', 'text' => 'نوعٌ غيرُ معروف', 'basis' => []],
                ['type' => 'risk', 'text' => 'بوابةُ الدفع متأخّرةٌ ثلاثة أيام — راجع موعدَ الإطلاق', 'basis' => ['tasks']],
            ]];
    }

    public function test_the_understanding_is_built_from_every_source_without_money_fields(): void
    {
        $r = ProjectUnderstanding::run();
        $this->assertSame(1, $r['built']);

        $sent = json_encode($this->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('منصّةٌ لحجز الفنادق', $sent, 'الوصف');
        $this->assertStringContainsString('ربطُ بوابة الدفع', $sent, 'المهامُّ المتأخّرة');
        $this->assertStringContainsString('إلغاءٍ مجانيّ', $sent, 'نصُّ الموقع');
        $this->assertStringNotContainsString('987654', $sent, 'لا ميزانيّة');
        $this->assertStringNotContainsString('123456', $sent, 'لا تكلفة');

        $row = DB::table('project_understanding')->where('project_id', $this->pid)->first();
        $sug = json_decode((string) $row->suggestions, true);
        $this->assertCount(2, $sug, 'النوعُ المجهولُ يُسقَط');
        $this->assertSame(['site', 'tasks'], $sug[0]['basis'], 'الأساسُ المجهولُ يُسقَط');
        $this->assertSame('منصّةُ حجوزاتٍ فندقيّة', json_decode((string) $row->sections, true)['what']);
    }

    public function test_it_is_not_rebuilt_while_its_sources_are_unchanged_but_refresh_forces_it(): void
    {
        ProjectUnderstanding::run();
        $n = count($this->sent);
        $this->travel(2)->days();
        ProjectUnderstanding::run();
        $this->assertCount($n, $this->sent, 'البصمةُ نفسُها ⇒ لا نداء');

        $this->actingAs($this->owner)->post(route('reports.projects.understand', $this->pid))->assertRedirect();
        $this->assertCount($n + 1, $this->sent, '«تحديث الفهم» يُجبر');
    }

    public function test_a_project_with_nothing_to_understand_makes_no_call(): void
    {
        $empty = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $empty, 'name' => 'فارغ', 'status' => 'تخطيط', 'created_at' => now(), 'updated_at' => now()]);
        ProjectUnderstanding::run(false, $empty);
        $this->assertSame([], $this->sent);
    }

    public function test_the_page_shows_three_layers_and_a_suggestion_becomes_a_task_draft_or_is_dismissed(): void
    {
        ProjectUnderstanding::run();
        $html = $this->actingAs($this->owner)->get(route('reports.projects.show', $this->pid))->assertOk()->getContent();
        $this->assertStringContainsString('فهم المشروع', $html);
        $this->assertStringContainsString('المسافرون في الخليج', $html);
        $this->assertStringContainsString('أنشئ مهمّةً لسياسة الإلغاء', $html);

        $sug = json_decode((string) DB::table('project_understanding')->value('suggestions'), true);
        $res = $this->actingAs($this->owner)->post(route('reports.projects.suggestion', [$this->pid, $sug[0]['id']]), ['action' => 'task']);
        $loc = urldecode((string) $res->headers->get('Location'));
        $this->assertStringContainsString('/m/tasks/create', $loc);
        $this->assertStringContainsString('projectId=' . $this->pid, $loc);
        $this->assertStringContainsString('سياسة الإلغاء', $loc);

        $this->actingAs($this->owner)->post(route('reports.projects.suggestion', [$this->pid, $sug[1]['id']]),
            ['action' => 'dismiss', 'reason' => 'معروفٌ سلفاً'])->assertRedirect();
        $after = $this->actingAs($this->owner)->get(route('reports.projects.show', $this->pid))->getContent();
        $this->assertStringNotContainsString('أنشئ مهمّةً لسياسة الإلغاء', $after, 'المحوَّلُ لا يعود');
        $this->assertStringNotContainsString('بوابةُ الدفع متأخّرةٌ ثلاثة أيام', $after, 'المتجاهَلُ لا يعود');
    }

    public function test_an_understanding_built_from_documents_is_hidden_from_who_cannot_see_documents(): void
    {
        ProjectUnderstanding::run();
        DB::table('project_understanding')->update(['uses_docs' => true]);

        $matrix = $this->employee->role->matrix;
        $matrix['files'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'بلا ملفّات', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'قارئ', 'email' => Str::random(8) . '@pu.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->assertNull(ProjectUnderstanding::forViewer($u, $this->pid));
        $this->assertNotNull(ProjectUnderstanding::forViewer($this->employee, $this->pid));
        $sug = json_decode((string) DB::table('project_understanding')->value('suggestions'), true);
        $this->actingAs($u)->post(route('reports.projects.suggestion', [$this->pid, $sug[0]['id']]), ['action' => 'dismiss'])->assertNotFound();
    }

    public function test_off_by_default(): void
    {
        $this->hubSetting('ai.understanding', '0');
        $this->assertFalse(ProjectUnderstanding::enabled());
        $this->artisan('hub:understanding')->assertSuccessful();
        $this->assertSame([], $this->sent);
    }
}

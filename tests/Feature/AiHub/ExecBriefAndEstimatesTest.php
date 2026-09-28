<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProposal;
use App\Models\AiProvider;
use App\Models\HubNotification;
use App\Support\Ai\Brief\ExecBrief;
use App\Support\Ai\Brief\RequestEstimator;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **المرحلة ٧** (docs/ai-hub/47 §العمود و): موجزُ الأسبوع للمالك، وتقديرُ الطلبات الواردة. بلا دينارٍ: `Http::fake`.
 */
class ExecBriefAndEstimatesTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    private array $reply = [];

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
            'credential_name' => 'hub-eb-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
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
        $this->hubSetting('reports.project_digest_profile', 'general');
    }

    // ═══ موجزُ الأسبوع ═══

    public function test_the_owner_gets_a_weekly_brief_with_a_notification_and_the_context_is_restored(): void
    {
        $this->hubSetting('ai.exec_brief', '1');
        $this->reply = ['items' => [
            ['n' => null, 'title' => 'اعتمد ميزانيّة الربع القادم', 'why' => 'تنتهي الدورةُ الخميس', 'decision' => 'وافق أو عدّل'],
            ['n' => 999, 'title' => 'رقمُ إشارةٍ غيرُ موجود', 'why' => 'x', 'decision' => 'y'],
            ['title' => ''],
        ]];

        $this->assertNull(Auth::user());
        $r = ExecBrief::run();
        $this->assertNull(Auth::user(), 'سياقُ المستخدم يُعاد كما كان');
        $this->assertSame(1, $r['built']);
        $this->assertSame(1, $r['notified']);

        $b = ExecBrief::latest($this->owner);
        $items = json_decode((string) $b->items, true);
        $this->assertCount(2, $items, 'العنوانُ الفارغُ يُسقَط');
        $this->assertNull($items[1]['url'], 'لا رابطَ من النموذج — والرقمُ المجهولُ بلا رابط');

        $n = HubNotification::query()->where('kind', ExecBrief::KIND)->where('user_id', $this->owner->id)->firstOrFail();
        $this->actingAs($this->owner)->get(route('notifications.go', $n->id))->assertRedirect(route('ai.brief'));
        $this->assertStringContainsString('اعتمد ميزانيّة الربع القادم',
            $this->actingAs($this->owner)->get(route('ai.brief'))->assertOk()->getContent());

        $calls = count($this->sent);
        ExecBrief::run();
        $this->assertCount($calls, $this->sent, 'مرّةً في الأسبوع');
    }

    public function test_the_brief_is_for_owners_only(): void
    {
        $this->actingAs($this->employee)->get(route('ai.brief'))->assertNotFound();
        $this->actingAs($this->employee)->post(route('ai.brief.refresh'))->assertNotFound();
        $this->hubSetting('ai.exec_brief', '0');
        $this->artisan('hub:exec-brief')->assertSuccessful();
        $this->assertSame([], $this->sent, 'مطفأٌ افتراضاً');
    }

    // ═══ تقديرُ الطلبات ═══

    private function request(array $cols): string
    {
        $id = (string) Str::uuid();
        DB::table('internal_requests')->insert(array_merge(['id' => $id, 'req_type' => 'شراء', 'status' => 'جديد',
            'created_at' => now(), 'updated_at' => now()], $cols));

        return $id;
    }

    public function test_a_new_request_gets_estimate_proposals_from_its_history_with_a_verified_quote(): void
    {
        $this->hubSetting('ai.request_estimates', '1');
        $this->request(['title' => 'شراء أجهزة محمولة للفريق', 'status' => 'منفَّذ', 'est_days' => 10, 'est_cost' => 2500, 'prio_final' => 'متوسطة',
            'created_at' => now()->subMonths(3)]);
        $new = $this->request(['title' => 'شراء خوادم إضافية للاستضافة', 'description' => 'نحتاج خادمين للموقع']);
        $human = $this->request(['title' => 'شراء شاشات عرض للاجتماعات', 'est_days' => 4]);
        $this->reply = ['days' => 12, 'cost' => 3000, 'priority' => 'عالية', 'why' => 'مشترياتٌ مشابهةٌ استغرقت ١٠ أيام',
            'quote' => 'شراء خوادم إضافية'];

        $r = RequestEstimator::run();

        $this->assertSame(2, $r['requests']);
        $kinds = AiProposal::query()->where('record_id', $new)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['request_cost', 'request_days', 'request_priority'], $kinds);
        $this->assertSame([], AiProposal::query()->where('record_id', $human)->where('kind', 'request_days')->pluck('id')->all(),
            'ما قدّره إنسانٌ لا يُقترح فوقه');

        $calls = count($this->sent);
        RequestEstimator::run();
        $this->assertCount($calls, $this->sent, 'طلبٌ له اقتراحٌ لا يُعاد');
    }

    public function test_no_history_or_an_invented_quote_proposes_nothing(): void
    {
        $this->hubSetting('ai.request_estimates', '1');
        $this->request(['title' => 'توظيف مطوّر واجهات', 'req_type' => 'توظيف']);
        RequestEstimator::run();
        $this->assertSame([], $this->sent, 'لا تاريخَ من نوعه ⇒ لا نداء');

        $this->request(['title' => 'شراء طابعة', 'status' => 'منفَّذ', 'est_days' => 3, 'created_at' => now()->subMonth()]);
        $id = $this->request(['title' => 'شراء أحبار للطابعات']);
        $this->reply = ['days' => 5, 'why' => 'x', 'quote' => 'نصٌّ غيرُ موجودٍ في الطلب إطلاقاً'];
        RequestEstimator::run();
        $this->assertSame(0, AiProposal::query()->where('record_id', $id)->count());
    }
}

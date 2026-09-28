<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\KpiDef;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Kpi\KpiInsights;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **مؤشّراتٌ ذكيّة** (docs/ai-hub/47 §العمود هـ — المرحلة ٦). بلا دينارٍ: `Http::fake`.
 * يُثبت: التفسيرُ والإجراءاتُ والهدفُ المقترح يُحفظون، والهدفُ الشاذُّ أو القريبُ يُسقَط، والقيمةُ الفعليّة لا تُمسّ،
 * واعتمادُ الهدف يكتبه بأثرٍ وملاحظة وبباب الباني وحدَه، والمؤشّراتُ الناقصةُ بوحدةٍ حقيقيّة، والإطفاءُ الافتراضيّ.
 */
class KpiInsightsTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    /** @var list<array> */
    private array $replies = [];

    private int $at = 0;

    private KpiDef $kpi;

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
            'credential_name' => 'hub-ki-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);
        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);
            $reply = $this->replies[min($this->at, max(0, count($this->replies) - 1))] ?? ['ideas' => []];
            $this->at++;

            return Http::response(LiteLlmFixtures::answer(json_encode($reply, JSON_UNESCAPED_UNICODE), LiteLlmFixtures::usage()), 200);
        });

        $this->hubSetting('ai.kpi_insights', '1');
        $this->hubSetting('reports.project_digest_profile', 'general');

        $this->kpi = KpiDef::create(['name' => 'مشاريعُ قيد التنفيذ', 'unit' => 'مشروع', 'target' => 500, 'good' => 'up',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'projects', 'col' => null, 'st' => 'قيد التنفيذ'], 'combine' => 'none'],
            'sort' => 0]);
        foreach (range(1, 60) as $d) {
            DB::table('metric_points')->insert(['id' => (string) Str::uuid(), 'module' => 'kpis', 'record_id' => $this->kpi->id, 'metric' => 'value',
                'value' => 10 + intdiv($d, 7), 'at' => now()->subDays(61 - $d), 'source' => 'auto']);
        }
        $this->replies = [
            ['explanation' => 'العددُ يرتفع ببطءٍ نحو ١٩ بينما الهدف ٥٠٠ — الهدفُ بعيدٌ عن الواقع', 'actions' => ['راجع خطّةَ المبيعات', 'وزّع الفريق'],
                'target' => ['value' => 25, 'why' => 'متوسّطُ النموّ الأسبوعيّ يقود إلى ٢٥ خلال الدورة']],
            ['ideas' => [['name' => 'زمنُ الردّ على التذاكر', 'why' => 'لا مؤشّرَ لخدمة العملاء', 'module' => 'tickets'],
                ['name' => 'وحدةٌ وهميّة', 'why' => 'x', 'module' => 'not_a_module']]],
        ];
    }

    public function test_an_off_target_kpi_is_explained_with_a_realistic_target_and_its_value_is_untouched(): void
    {
        $r = KpiInsights::run();
        $this->assertSame(1, $r['analysed']);
        $this->assertSame(1, $r['ideas'], 'الوحدةُ الوهميّةُ تُسقَط');

        $sent = json_encode($this->sent[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('مشاريعُ قيد التنفيذ', $sent);
        $this->assertStringContainsString('weekly', $sent);

        $ins = KpiInsights::byKpi()[$this->kpi->id];
        $this->assertStringContainsString('الهدفُ بعيدٌ عن الواقع', $ins->explanation);
        $this->assertSame(25, (int) json_decode((string) $ins->target, true)['value']);
        $this->assertEquals(500, (float) $this->kpi->fresh()->target, 'لا يُكتب شيءٌ قبل الاعتماد');
        $this->assertSame('tickets', KpiInsights::ideas()[0]['module']);
    }

    public function test_an_out_of_range_or_too_close_target_is_dropped(): void
    {
        $row = ['kind' => 'pct', 'target' => 80.0];
        $this->assertNull(KpiInsights::parse(['explanation' => 'x', 'target' => ['value' => 140]], $row)['target'], 'نسبةٌ فوق ١٠٠');
        $this->assertNull(KpiInsights::parse(['explanation' => 'x', 'target' => ['value' => 81]], $row)['target'], 'أقلُّ من ٥٪ فرقاً');
        $this->assertSame(70.0, KpiInsights::parse(['explanation' => 'x', 'target' => ['value' => 70]], $row)['target']['value']);
        $this->assertNull(KpiInsights::parse(['explanation' => ''], $row), 'بلا تفسيرٍ ⇒ ردٌّ فاسد');
    }

    public function test_it_is_not_reanalysed_within_the_week_unless_forced(): void
    {
        KpiInsights::run();
        $n = count($this->sent);
        KpiInsights::run();
        $this->assertCount($n, $this->sent, 'البصمةُ نفسُها خلال الأسبوع ⇒ لا نداء');
        KpiInsights::run(false, true);
        $this->assertGreaterThan($n, count($this->sent));
    }

    public function test_the_builder_applies_or_rejects_the_suggested_target_with_an_audit_trail(): void
    {
        KpiInsights::run();

        $html = $this->actingAs($this->owner)->get(route('kpis.index'))->assertOk()->getContent();
        $this->assertStringContainsString('الهدفُ بعيدٌ عن الواقع', $html);
        $this->assertStringContainsString('اعتمد الهدف', $html);
        $this->assertStringContainsString('زمنُ الردّ على التذاكر', $html);

        $this->actingAs($this->employee)->post(route('kpis.aiTarget', $this->kpi->id), ['action' => 'applied'])->assertForbidden();
        $this->assertEquals(500, (float) $this->kpi->fresh()->target);

        $this->actingAs($this->owner)->post(route('kpis.aiTarget', $this->kpi->id), ['action' => 'applied'])->assertRedirect();
        $k = $this->kpi->fresh();
        $this->assertEquals(25, (float) $k->target);
        $this->assertStringContainsString('مقترح الذكاء', (string) $k->target_note);
        $this->assertTrue(DB::table('audits')->where('action', KpiInsights::AUDIT_TARGET)->where('record_id', $k->id)->exists());

        $this->actingAs($this->owner)->post(route('kpis.aiTarget', $this->kpi->id), ['action' => 'rejected'])
            ->assertSessionHas('err', 'لا هدفَ مقترحاً مفتوحاً');
    }

    public function test_off_by_default(): void
    {
        $this->hubSetting('ai.kpi_insights', '0');
        $this->artisan('hub:kpi-insights')->assertSuccessful();
        $this->assertSame([], $this->sent);
        $this->assertFalse(KpiInsights::enabled());
    }

    public function test_the_ai_refresh_button_is_behind_the_builder_gate(): void
    {
        $this->actingAs($this->employee)->post(route('kpis.aiRefresh'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('kpis.aiRefresh'))->assertRedirect();
        $this->assertNotEmpty($this->sent);
        $this->assertNotNull(DB::table('kpi_insights')->where('kpi_id', $this->kpi->id)->value('id'));
        $this->assertNotNull(Str::uuid());
    }
}

<?php

namespace Tests\Feature\AskHub;

use App\Models\AiUsageEvent;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\AiChat;
use App\Support\AiGateway;
use App\Support\AiProfiles;
use App\Support\AskFailures;
use App\Support\AskPipeline;
use App\Support\AskPolicy;
use App\Support\AskTools;
use App\Support\FeatureRegistry;
use App\Support\LiteLlmAskGenerator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **تفكيرٌ لا يُرى نصّاً — ودورةٌ لا تُقرأ بعد وقوعِها** (قبولُ الإنتاج `92dbd557`).
 *
 * ── **الحالةُ التي أسقطت الفرضيّةَ السابقة** ──
 *
 * الشاشةُ أثبتت قبل الإرسال «وسقفُ الجوابِ ٧٠٠ رمزاً»، ثمّ عاد
 * `OUTPUT_LIMIT`. **فالإعدادُ الصغيرُ لم يكن السبب** في هذه المحاولة.
 *
 * ── **وما يُثبِته المستودعُ عن نفسِه** ──
 *
 * `AiProbes::verdict` تعرف أنّ التفكيرَ يُثبَت **بدليلين**: نصِّ
 * `reasoning_content` **أو** `usage.completion_tokens_details.reasoning_tokens`.
 * و`LiteLlmAskGenerator::read` كانت تقرأ **الأوّلَ وحدَه**.
 *
 * **ومعظمُ النماذجِ التفكيريّةِ لا تُعيد النصَّ أصلاً** — تُبلِغ رموزَها في
 * `usage` وتُخفي متنَها. فالمنتجُ يقول «بلغ الجوابُ سقفَ طولِه» عن نموذجٍ
 * **لم يبدأ الجوابَ** لأنّه أنفق السقفَ كلَّه تفكيراً. وتعريفان مختلفان
 * لـ«هل فكّر؟» في منصّةٍ واحدة: الأدقُّ في الفاحص، والأضعفُ في مسارِ الإنتاج.
 */
class AskTurnTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private User $asker;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        Project::create(['name' => 'مشروعُ ألِف ٧٧١٢٣٤', 'company_id' => $this->alpha->id]);
        Project::create(['name' => 'مشروعُ ألِف ٧٧١٢٣٥', 'company_id' => $this->alpha->id]);

        $this->asker = $this->scopedUser('telemetry@ask.local');
        $this->ready();
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① تعريفٌ واحدٌ لـ«هل فكّر؟» — لا اثنان في منصّةٍ واحدة
    // ═══════════════════════════════════════════════════════════════════

    /** **رموزُ التفكيرِ وحدَها دليلٌ** — ولو لم يُعِد النموذجُ متنَ تفكيرِه */
    public function test_رموزُ_التفكيرِ_وحدَها_تُثبِت_أنّه_فكّر(): void
    {
        $json = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $json['choices'][0]['message']['content'] = null;
        $json['usage']['completion_tokens_details'] = ['reasoning_tokens' => 690];

        $this->assertTrue(AiChat::reasoned($json),
            'نموذجٌ أنفق ٦٩٠ رمزاً تفكيراً لا يُعَدّ مفكّراً لأنّه لم يُعِد المتن');
    }

    /** **ومتنُ التفكيرِ وحدَه دليلٌ أيضاً** — فالدليلان لا أحدُهما */
    public function test_متنُ_التفكيرِ_وحدَه_يُثبِت_أنّه_فكّر(): void
    {
        $json = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $json['choices'][0]['message']['reasoning_content'] = 'أفكّر…';

        $this->assertTrue(AiChat::reasoned($json));
    }

    /** **وردٌّ بلا أيٍّ منهما لم يفكّر** — فلا يُنسَب إليه ما لم يفعل */
    public function test_ردٌّ_بلا_دليلٍ_لا_يُعَدُّ_تفكيراً(): void
    {
        $this->assertFalse(AiChat::reasoned(
            LiteLlmFixtures::answer('جوابٌ [#1].', LiteLlmFixtures::usage())));
    }

    /**
     * **والمساعدُ يستعمل التعريفَ نفسَه الذي يستعمله الفاحص.**
     *
     * وهي الحالةُ الإنتاجيّةُ بعينِها: سقفٌ ٧٠٠، وقطعٌ بـ`length`، ورموزُ
     * تفكيرٍ تلتهم السقفَ، **وبلا متنِ تفكيرٍ في الردّ**.
     */
    public function test_سقفٌ_التهمه_تفكيرٌ_صامتٌ_يُقال_تفكيراً_لا_سقفَ_مخرَج(): void
    {
        $body = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $body['choices'][0]['finish_reason'] = 'length';
        $body['choices'][0]['message']['content'] = null;
        $body['usage']['completion_tokens'] = 700;
        $body['usage']['completion_tokens_details'] = ['reasoning_tokens' => 700];

        $r = $this->askWith([[$body, 200]]);

        $this->assertSame(AskFailures::MODEL_REASONED_ONLY, (string) $r['failure'],
            'أُنفق السقفُ كلُّه تفكيراً صامتاً وقيل «بلغ الجوابُ سقفَ طولِه»');
    }

    /** **وقطعٌ بلا أيِّ تفكيرٍ يبقى سقفَ مخرَج** — فلا يُعمَّم التصنيف */
    public function test_قطعٌ_بلا_تفكيرٍ_يبقى_سقفَ_مخرَج(): void
    {
        $body = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $body['choices'][0]['finish_reason'] = 'length';
        $body['choices'][0]['message']['content'] = null;
        $body['usage']['completion_tokens_details'] = ['reasoning_tokens' => 0];

        $r = $this->askWith([[$body, 200]]);

        $this->assertSame(AskFailures::OUTPUT_LIMIT, (string) $r['failure']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② تليمتري لكلِّ دورةٍ — فلا تُستهلَك محاولةٌ مدفوعةٌ لتُخمَّن بعدَها
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **كلُّ دورةٍ تترك صفّاً يُقرأ** — وإلّا فكلُّ تشخيصٍ بعدها ترجيح.
     *
     * والأعمدةُ هي بعينِها ما احتجناه في ثلاثِ محاولاتٍ مدفوعةٍ ولم نجده.
     */
    public function test_كلُّ_دورةٍ_تُسجّل_سببَ_انتهائِها_ورموزَها_وسقفَها(): void
    {
        Settings::put('ask.max_output_tokens', '700', 'test');

        $bad = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $bad['choices'][0]['finish_reason'] = 'length';
        $bad['choices'][0]['message']['content'] = null;
        $bad['usage']['completion_tokens'] = 700;
        $bad['usage']['completion_tokens_details'] = ['reasoning_tokens' => 700];

        $this->askWith([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [$bad, 200],
        ]);

        $rows = AiUsageEvent::query()->orderBy('attempt')->orderBy('id')->get();
        $this->assertCount(2, $rows, 'دورتان جرتا ولم يُسجَّل لهما صفّان');

        foreach ($rows as $i => $row) {
            $this->assertSame(700, (int) $row->max_output_tokens,
                "الدورةُ {$i}: السقفُ المُرسَلُ غيرُ مسجَّل");
            $this->assertNotNull($row->finish_reason,
                "الدورةُ {$i}: سببُ الانتهاءِ غيرُ مسجَّل — وهو أوّلُ ما يُسأل عنه");
        }

        $last = $rows->last();
        $this->assertSame('length', (string) $last->finish_reason);
        $this->assertSame(700, (int) $last->reasoning_tokens,
            'رموزُ التفكيرِ أُنفقت ولم تُسجَّل — فتبدو الدورةُ أرخصَ ممّا كلّفت');
    }

    /** **وأسماءُ الأدواتِ تُسجَّل — ووسائطُها لا** */
    public function test_أسماءُ_الأدواتِ_تُسجَّل_ولا_تُسجَّل_وسائطُها(): void
    {
        $this->askWith([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديك مشروعان [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        $first = AiUsageEvent::query()->orderBy('attempt')->orderBy('id')->first();

        $this->assertSame('hub_count', (string) $first->tool_requested,
            'اسمُ الأداةِ غيرُ مسجَّل — ولا يُعرَف أينَ انقطع المسار');

        foreach (AiUsageEvent::query()->get() as $row) {
            $blob = (string) json_encode((array) $row->getAttributes(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('projects', $blob,
                'وسائطُ الأداةِ دخلت التليمتري — والعقدُ تصنيفٌ لا محتوى');
        }
    }

    /** **ولا نصَّ سؤالٍ ولا جوابٍ في أيِّ عمود** — الثابتُ I-5 على حالِه */
    public function test_لا_نصَّ_سؤالٍ_ولا_جوابٍ_في_التليمتري(): void
    {
        $this->askWith([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديك مشروعان اثنان [#1].', LiteLlmFixtures::usage()), 200],
        ], 'مرحبا كم مشروع لدينا');

        foreach (AiUsageEvent::query()->get() as $row) {
            $blob = (string) json_encode((array) $row->getAttributes(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('مرحبا', $blob);
            $this->assertStringNotContainsString('مشروعان', $blob);
        }
    }

    /**
     * **وأثرُ الإخفاقِ يحمل ما يحمله أثرُ النجاح** (قبولُ الإنتاج · `92dbd557`).
     *
     * فمعرّفُ الطلبِ هو ما يُفتَح للتحقيق، **والإخفاقُ وحدَه ما يُحقَّق فيه**.
     * وكان صفُّه أفقرَ من صفِّ النجاح: بلا غرضٍ ولا نموذجٍ ولا رموزٍ ولا كلفة.
     */
    public function test_أثرُ_الإخفاقِ_يحمل_الغرضَ_والنموذجَ_والرموزَ(): void
    {
        $bad = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $bad['choices'][0]['finish_reason'] = 'length';
        $bad['choices'][0]['message']['content'] = null;
        $bad['usage']['completion_tokens'] = 700;
        $bad['usage']['total_tokens'] = 1011;
        $bad['usage']['completion_tokens_details'] = ['reasoning_tokens' => 700];

        $r = $this->askWith([[$bad, 200]]);
        $this->assertFalse($r['ok']);

        $rows = \Illuminate\Support\Facades\DB::table('audits')
            ->where('action', \App\Support\AskAudit::ACTION_ASKED)
            ->orderBy('id')->get();

        $this->assertNotEmpty($rows, 'إخفاقٌ بلا أثرٍ البتّة');

        $after = (array) json_decode((string) ($rows->last()->after ?? '{}'), true);
        $after = (array) ($after['after'] ?? $after);

        $this->assertSame('failed', (string) ($after['outcome'] ?? ''));
        $this->assertSame(AskPolicy::PROFILE, (string) ($after['profile'] ?? ''),
            'الغرضُ غيرُ مسجَّلٍ في صفِّ الإخفاق');
        $this->assertNotNull($after['model'] ?? null,
            'النموذجُ غيرُ مسجَّلٍ — ولا يُعرَف بماذا أخفق الطلب');
        $this->assertSame(1011, (int) ($after['tokens'] ?? 0),
            'الرموزُ المُعلَنةُ لم تدخل أثرَ الإخفاق — فيبدو الطلبُ مجّانيّاً');
        $this->assertSame(700, (int) ($after['reasoning_tokens'] ?? 0),
            'رموزُ التفكيرِ لم تدخل الأثر — وهي ما التهم السقف');
    }

    /** **والأثرُ يبقى بلا نصٍّ** — الرقمُ دخل والمتنُ لم يدخل */
    public function test_رقمُ_التفكيرِ_يدخل_الأثرَ_ومتنُه_لا(): void
    {
        $bad = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $bad['choices'][0]['finish_reason'] = 'length';
        $bad['choices'][0]['message']['content'] = null;
        $bad['choices'][0]['message']['reasoning_content'] = 'سأعدُّ المشاريعَ ٩٩٨٨٧٧';
        $bad['usage']['completion_tokens_details'] = ['reasoning_tokens' => 512];

        $this->askWith([[$bad, 200]]);

        $blob = (string) json_encode(\Illuminate\Support\Facades\DB::table('audits')
            ->whereIn('action', [\App\Support\AskAudit::ACTION_ASKED,
                                 \App\Support\AskAudit::ACTION_DENIED])->get(),
            JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('reasoning_tokens', $blob);
        $this->assertStringNotContainsString('٩٩٨٨٧٧', $blob,
            'متنُ التفكيرِ دخل أثرَ التدقيق — والعقدُ يمنعه');
    }

    /** **والعدُّ يبقى مُنطَّقاً بالشركة** — لم يُمَسّ */
    public function test_العدُّ_يبقى_مُنطَّقاً_بشركةِ_السائل(): void
    {
        $beta = Company::create(['name_ar' => 'شركةُ باء']);
        Project::create(['name' => 'مشروعُ باء ٨٨٩٩٧٧', 'company_id' => $beta->id]);

        $this->assertSame(3, Project::query()->count());
        $this->assertSame(2, (int) AskTools::run('hub_count', ['module' => 'projects'],
            $this->asker)['count']);
    }

    // ── أدواتُ التهيئة ────────────────────────────────────────────────

    /** @param list<array{0:array,1:int}> $steps */
    private function askWith(array $steps, string $q = 'مرحبا كم مشروع لدينا'): array
    {
        $at = 0;
        Http::fake(function () use ($steps, &$at) {
            $s = $steps[min($at, count($steps) - 1)] ?? [LiteLlmFixtures::answer('لا شيء.'), 200];
            $at++;

            return Http::response($s[0], $s[1] ?? 200);
        });

        return AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());
    }

    private function scopedUser(string $email): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$this->alpha->id]]);
    }

    private function ready(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) {
            Settings::put($k, '1', 'test');
        }
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');

        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = \App\Models\AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-fake-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'configured',
        ]);

        $model = \App\Models\AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => [
                // **و`tools` معلَنةٌ لأنّ مسارَ المساعدِ كلَّه دورةُ أدوات** (المرحلة ٥ · W2)
                'chat'  => ['v' => true, 'src' => 'litellm'],
                'tools' => ['v' => true, 'src' => 'litellm'],
            ],
            'limits'  => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params'  => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);

        AiProfiles::attach(\App\Models\AiProfile::query()
            ->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);
    }
}

<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Support\AiGateway;
use App\Support\AiProfiles;
use App\Support\AiPurposes;
use App\Support\AiReleaseCheck;
use App\Support\AskPolicy;
use App\Support\FeatureRegistry;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **بوّابةُ الإطلاقِ تُقاس بحالاتٍ مصنوعةٍ لا بتشغيلةٍ واحدة** (المرحلة ٥ · W12).
 *
 * وبوّابةٌ تُشغَّل مرّةً فتقول «كلُّه بخير» ليست بوّابة: **الحارسُ يُقاس بأن
 * يُوقِع**. فكلُّ فحصٍ هنا يُكسَر عمداً ثمّ يُثبَت أنّ الحكمَ انقلب.
 *
 * ── **وثلاثُ درجاتٍ لا اثنتان** ──
 *
 * الأهمُّ أنّ `WARN` **لا تُبتلَع في `PASS`**: الحكمُ يقولها باسمِها، والعدُّ
 * يُظهرها، ورمزُ الخروجِ يُسقِط عليها مع `--strict`.
 */
class AiReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        AiProfiles::seed();

        // **ولا نداءَ يخرج من هذه الحزمةِ البتّة** — الفحصُ يقرأ حالةً مخزّنة
        Http::fake(static fn () => Http::response([], 500));
    }

    // ── العقدُ نفسُه ──────────────────────────────────────────────────

    public function test_النتيجةُ_تحمل_حكماً_وعدّاً_وفحوصاً_موسومة(): void
    {
        $r = AiReleaseCheck::run();

        $this->assertArrayHasKey('verdict', $r);
        $this->assertArrayHasKey('counts', $r);
        $this->assertArrayHasKey('checks', $r);
        $this->assertNotEmpty($r['checks']);

        foreach ($r['checks'] as $c) {
            foreach (['id', 'status', 'title', 'reason', 'fix'] as $k) {
                $this->assertArrayHasKey($k, $c, "فحصٌ بلا المفتاح `{$k}`");
            }

            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/', (string) $c['id'],
                '**معرّفُ الفحصِ ليس مفتاحاً يُقرأ آليّاً**: ' . $c['id']);
            $this->assertContains($c['status'],
                [AiReleaseCheck::PASS, AiReleaseCheck::WARN, AiReleaseCheck::FAIL]);
            $this->assertNotSame('', trim((string) $c['reason']),
                "الفحصُ [{$c['id']}] بلا سبب — ودرجةٌ بلا سببٍ لا تُصلَح");
        }
    }

    /** **ولا معرّفَ يتكرّر** — فنتيجتانِ بالمفتاحِ نفسِه لا تُفرَّقان آليّاً */
    public function test_لا_معرّفَ_فحصٍ_يتكرّر(): void
    {
        $ids = array_column(AiReleaseCheck::run()['checks'], 'id');

        $this->assertSame(count($ids), count(array_unique($ids)),
            '**معرّفُ فحصٍ مكرَّر**: ' . implode(' · ', array_diff_assoc($ids, array_unique($ids))));
    }

    /** **والعدُّ يطابق الفحوصَ عدّاً لا تقريباً** */
    public function test_العدُّ_يطابق_الفحوص(): void
    {
        $r = AiReleaseCheck::run();

        $this->assertSame(count($r['checks']), array_sum($r['counts']));
    }

    // ── الحكمُ يتبع الأسوأ ────────────────────────────────────────────

    /** **فحصٌ واحدٌ `FAIL` يُغلِق البوّابةَ مهما كثر الناجح** */
    public function test_إخفاقٌ_واحدٌ_يُغلِق_البوّابة(): void
    {
        $this->ready();
        $this->assertNotSame(AiReleaseCheck::FAIL, AiReleaseCheck::run()['verdict'],
            'البوّابةُ مُغلَقةٌ قبل الكسرِ فالاختبارُ لا يقيس شيئاً');

        // تُكسَر سلسلةُ المساعدِ وحدَها
        AiProfile::query()->where('key', AskPolicy::profileKey())
            ->update(['enabled' => false]);

        $r = AiReleaseCheck::run();
        $this->assertSame(AiReleaseCheck::FAIL, $r['verdict']);
        $this->assertGreaterThan(0, $r['counts'][AiReleaseCheck::FAIL]);
    }

    /**
     * **و`WARN` تُقال باسمِها ولا تُبتلَع في `PASS`.**
     *
     * وهذا صريحٌ في قرارِ المالك: «لا تعتبر WARN = PASS بصمت».
     */
    public function test_التحفّظُ_يُقال_ولا_يُبتلَع(): void
    {
        $this->ready();

        // نموذجٌ واحدٌ ⟵ سلسلةٌ بلا احتياطٍ ⟵ تحفّظٌ لا إخفاق
        $r = AiReleaseCheck::run();

        $this->assertSame(AiReleaseCheck::WARN, $r['verdict'],
            '**تحفّظٌ حقيقيٌّ قُرئ نجاحاً كاملاً**');
        $this->assertGreaterThan(0, $r['counts'][AiReleaseCheck::WARN]);

        $depth = $this->check($r, 'ASK_FALLBACK_DEPTH');
        $this->assertSame(AiReleaseCheck::WARN, $depth['status']);
        $this->assertStringContainsString('بلا احتياط', $depth['reason']);
    }

    // ── كلُّ فحصٍ يُكسَر ويُثبِت أنّه يعمل ──────────────────────────────

    /** **غرضُ المساعدِ بسلسلةٍ فارغةٍ يُغلِق البوّابة** */
    public function test_سلسلةٌ_فارغةٌ_تُكشَف(): void
    {
        $this->ready();
        AiModel::query()->update(['enabled' => false]);

        $this->assertSame(AiReleaseCheck::FAIL,
            $this->check(AiReleaseCheck::run(), 'ASK_PROFILE_CHAIN')['status']);
    }

    /**
     * **ونماذجُ لا تُصدر أدواتٍ تُكشَف بفحصِها الخاصّ.**
     *
     * وهذا هو العطلُ الإنتاجيُّ الذي لم يكن له فحصٌ قبل اليوم: سلسلةٌ مملوءةٌ
     * تمرّ بكلِّ فحصٍ قديمٍ **ولا تُجيب سؤالاً واحداً**.
     */
    public function test_نماذجٌ_لا_تُلائم_المساعدَ_تُكشَف_بفحصٍ_خاصّ(): void
    {
        $this->ready();
        AiModel::query()->update(['capabilities' => [
            'chat'  => ['v' => true,  'src' => 'litellm'],
            'tools' => ['v' => false, 'src' => 'litellm'],
        ]]);

        $r = AiReleaseCheck::run();
        $fit = $this->check($r, 'ASK_SUITABILITY');

        $this->assertSame(AiReleaseCheck::FAIL, $fit['status']);
        $this->assertStringContainsString('tools', $fit['reason']);

        // **والسلسلةُ نفسُها تبقى «ممتلئة»** — فالفحصان يقولان شيئين مختلفين
        $this->assertSame(AiReleaseCheck::PASS,
            $this->check($r, 'ASK_PROFILE_CHAIN')['status'],
            '**خُلط نقصُ القدرةِ بفراغِ السلسلةِ — وهو الخلطُ الذي وُلد منه الفحص**');
    }

    /** **ومزوّدٌ بلا اعتمادٍ يُكشَف** */
    public function test_مزوّدٌ_بلا_اعتمادٍ_يُكشَف(): void
    {
        $this->ready();
        AiProvider::query()->update(['credential_state' => 'missing']);

        $this->assertSame(AiReleaseCheck::FAIL,
            $this->check(AiReleaseCheck::run(), 'PROVIDER_CREDENTIAL')['status']);
    }

    /** **وبوّابةٌ غيرُ مضبوطةٍ تُكشَف** */
    public function test_بوّابةٌ_غيرُ_مضبوطةٍ_تُكشَف(): void
    {
        $this->assertSame(AiReleaseCheck::FAIL,
            $this->check(AiReleaseCheck::run(), 'GATEWAY_CONFIGURED')['status']);
    }

    /** **وبوّابةٌ على عنوانٍ غيرِ محلّيٍّ تُقال تحفّظاً لا إخفاقاً** */
    public function test_بوّابةٌ_مكشوفةُ_العنوانِ_تحفّظٌ_يُقرَأ(): void
    {
        $this->ready();
        Settings::put('ai.gateway_url', 'https://llm.example.net', 'test');

        $c = $this->check(AiReleaseCheck::run(), 'GATEWAY_LOOPBACK');

        $this->assertSame(AiReleaseCheck::WARN, $c['status']);
        $this->assertStringContainsString('مكشوفة', $c['reason']);
    }

    /** **وتطابقُ النسخةِ ومواصفةُ OpenAPI يُقاسان على المستودعِ الحقيقيّ** */
    public function test_النسخةُ_والمواصفةُ_مطابقتانِ_في_المستودع(): void
    {
        $r = AiReleaseCheck::run();

        $this->assertSame(AiReleaseCheck::PASS, $this->check($r, 'VERSION_SYNC')['status'],
            '**`VERSION` وسطرُ README متفرّقان** — والقاعدةُ إلزاميّة');
        $this->assertSame(AiReleaseCheck::PASS, $this->check($r, 'OPENAPI_FRESH')['status'],
            '**المواصفةُ منحرفةٌ عن النسخة** — `php artisan hub:openapi`');
    }

    /** **وتغطيةُ النسخِ الاحتياطيِّ لجداولِ الذكاءِ الثمانية** */
    public function test_جداولُ_الذكاءِ_في_النسخةِ_الاحتياطيّة(): void
    {
        $this->assertSame(AiReleaseCheck::PASS,
            $this->check(AiReleaseCheck::run(), 'BACKUP_COVERAGE')['status'],
            '**استعادةٌ ستُعيد Hub بلا ذكاءٍ ولا اعتماداتٍ ولا دفتر**');
    }

    /** **وأدواتُ الكتابةِ مغلقةٌ — قرارُ مالكٍ يُحرَس آليّاً** */
    public function test_أدواتُ_الكتابةِ_مغلقةٌ_في_البوّابة(): void
    {
        $this->assertSame(AiReleaseCheck::PASS,
            $this->check(AiReleaseCheck::run(), 'WRITE_TOOLS_CLOSED')['status']);
    }

    // ── الأمرُ نفسُه ──────────────────────────────────────────────────

    /** **والأمرُ لا يكتب شيئاً ولا يُنفق** */
    public function test_الأمرُ_يقرأ_ولا_يكتب_ولا_ينادي(): void
    {
        $this->ready();

        $before = [
            'models'    => AiModel::query()->count(),
            'providers' => AiProvider::query()->count(),
            'usage'     => \App\Models\AiUsageEvent::query()->count(),
            'settings'  => \Illuminate\Support\Facades\DB::table('settings')->count(),
        ];

        $this->artisan('hub:ai-release-check')->run();

        $this->assertSame($before['models'], AiModel::query()->count());
        $this->assertSame($before['providers'], AiProvider::query()->count());
        $this->assertSame($before['usage'], \App\Models\AiUsageEvent::query()->count());
        $this->assertSame($before['settings'],
            \Illuminate\Support\Facades\DB::table('settings')->count(),
            '**بوّابةُ الإطلاقِ كتبت في الإعدادات**');

        Http::assertNothingSent();
    }

    /** **و`--json` يُخرج نتيجةً تُقرَأ آليّاً** */
    public function test_المخرَجُ_الآليُّ_يُقرَأ_بلا_تحليلِ_نصّ(): void
    {
        $this->ready();

        $out = new \Symfony\Component\Console\Output\BufferedOutput();
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->call(
            'hub:ai-release-check', ['--json' => true], $out);

        $json = json_decode($out->fetch(), true);

        $this->assertIsArray($json, '**مخرَجُ `--json` ليس JSON صالحاً**');
        $this->assertArrayHasKey('verdict', $json);
        $this->assertArrayHasKey('checks', $json);
        $this->assertNotEmpty($json['checks']);
    }

    /**
     * **ورمزُ الخروج: متساهلٌ مع التحفّظِ افتراضاً، صارمٌ عند الطلب.**
     *
     * والتساهلُ افتراضاً قرارٌ لا سهو: البوّابةُ تُشغَّل على مستودعٍ بلا
     * مزوّدٍ ولا نموذجٍ مُهيَّأ، فكلُّ فحصِ تشغيلٍ فيها تحفّظٌ بطبيعتِه —
     * **ولو أسقطت لَصارت ضجيجاً يُعطَّل بعد أسبوع**.
     */
    public function test_رمزُ_الخروجِ_يتبع_الحكمَ_ويتشدّد_عند_الطلب(): void
    {
        $this->ready();
        $this->assertSame(AiReleaseCheck::WARN, AiReleaseCheck::run()['verdict']);

        $this->artisan('hub:ai-release-check')->assertExitCode(0);
        $this->artisan('hub:ai-release-check', ['--strict' => true])->assertExitCode(1);

        // وإخفاقٌ حقيقيٌّ يُسقِط في الحالتين
        AiProvider::query()->update(['credential_state' => 'missing']);
        $this->artisan('hub:ai-release-check')->assertExitCode(1);
    }

    // ── أدواتٌ ────────────────────────────────────────────────────────

    private function check(array $r, string $id): array
    {
        foreach ($r['checks'] as $c) {
            if ($c['id'] === $id) return $c;
        }

        $this->fail("لا فحصَ بالمعرّف `{$id}` — وحارسٌ يبحث عن فحصٍ غيرِ موجودٍ يمرّ فراغاً");
    }

    /** **حالةٌ صالحةٌ بالحدِّ الأدنى** — نموذجٌ واحدٌ مُلائمٌ على مزوّدٍ معتمَد */
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

        $p = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-gate-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'verified',
        ]);

        $m = AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => 'gate-head',
            'upstream_model' => 'f/gate-head', 'display_name' => 'رأس',
            'enabled' => true, 'health' => 'OK',
            'capabilities' => ['chat'  => ['v' => true, 'src' => 'litellm'],
                               'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);

        AiProfiles::attach(AiProfile::query()
            ->where('key', AskPolicy::profileKey())->firstOrFail(), $m);

        $this->assertTrue(AiPurposes::suitability($m->fresh(), AiPurposes::ASK)['ok'],
            'حالةُ الأساسِ نفسُها غيرُ مُلائمة — فالاختبارُ يقيس سقالتَه');
    }
}

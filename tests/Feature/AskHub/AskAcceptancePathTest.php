<?php

namespace Tests\Feature\AskHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Center\AiOverview;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Ask\AskGeneratorFactory;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **وضعُ قبولِ الإنتاج: المسارُ كلُّه من الواجهة** (جاهزيّةُ الإنتاج · PR-6).
 *
 * المطلوبُ من المالكِ صريحٌ ومقيسٌ هنا:
 *
 * > **بعد هذه الدفعةِ لا يبقى تعديلُ شيفرةٍ واحدٌ لتشغيلِ «اسأل Hub» الحقيقيّ.**
 *
 * فكلُّ درجةٍ في السلّمِ
 * `A → Credential → Discovery → Import → B → Enable → C → D → Ask`
 * **لها وجهةٌ تُنقَر**، وحالتُها **تُقرأ من النظامِ لا تُؤشَّر يدويّاً**.
 *
 * **وموضعُ B صُحِّح بالمصدر** لا بالذوق: فحصُ الاعتمادِ عند البوّابةِ يختبر
 * (اعتماداً × نموذجاً)، فلا يُطلَب من المالكِ أن يُجريَه قبل أن يملكَ نموذجاً.
 */
class AskAcceptancePathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    private function gatewayReady(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
    }

    private function chain(string $profileKey = AskPolicy::PROFILE): AiProfile
    {
        AiProfiles::seed();

        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-path-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'verified',
        ]);

        $model = AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'نموذجٌ وهميّ',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => [
                // **و`tools` معلَنةٌ لأنّ مسارَ المساعدِ كلَّه دورةُ أدوات** (المرحلة ٥ · W2)
                'chat'  => ['v' => true, 'src' => 'litellm'],
                'tools' => ['v' => true, 'src' => 'litellm'],
            ],
            'limits' => [], 'params' => [], 'pricing' => [],
        ]);

        $profile = AiProfile::query()->where('key', $profileKey)->firstOrFail();
        AiProfiles::attach($profile, $model);

        return $profile;
    }

    // ═══ ① السلّمُ نفسُه ═══

    public function test_السلّمُ_تسعُ_درجاتٍ_بالمفاتيحِ_المتّفَقِ_عليها(): void
    {
        $keys = array_column(AiOverview::acceptancePath(), 'key');

        $this->assertSame(
            ['A', 'Credential', 'Discovery', 'Import', 'B', 'Enable', 'C', 'D', 'Ask'], $keys,
            'انحرف مسارُ القبولِ عمّا اتُّفق عليه');
    }

    /**
     * **وB لا يسبق النموذجَ** — حارسٌ على الترتيبِ نفسِه لا على عدِّ الدرجات.
     *
     * سقطت أوّلُ جلسةِ قبولٍ حقيقيّةٍ على هذا بالضبط: سلّمٌ يضع B ثانياً،
     * فيفحص المالكُ اعتماداً بلا نموذجٍ فتردّ البوّابةُ ٥٠٠ داخليّاً.
     */
    public function test_درجةُ_B_تقع_بعد_الاستيرادِ_لا_قبلَه(): void
    {
        $keys = array_column(AiOverview::acceptancePath(), 'key');

        $this->assertGreaterThan(array_search('Import', $keys, true),
            array_search('B', $keys, true),
            '**ترتيبٌ يُوقع في الخطأ**: B يُطلَب قبل أن يوجدَ نموذجٌ يُفحَص به');
        $this->assertGreaterThan(array_search('Credential', $keys, true),
            array_search('Discovery', $keys, true),
            'الاكتشافُ لا يسبق إدخالَ الاعتماد');
    }

    /** **وكلُّ درجةٍ وجهةٌ تُنقَر** — فلا طرفيّةَ بين خطوتين */
    public function test_كلُّ_درجةٍ_لها_وجهةٌ_قائمة(): void
    {
        foreach (AiOverview::acceptancePath() as $step) {
            $this->assertNotNull($step['route'], "الدرجةُ {$step['key']} بلا وجهة");
            $this->assertTrue(\Illuminate\Support\Facades\Route::has((string) $step['route']),
                "وجهةُ الدرجةِ {$step['key']} لا وجودَ لها: {$step['route']}");
        }
    }

    public function test_على_تنصيبٍ_خامٍّ_لا_درجةَ_مُنجَزة(): void
    {
        foreach (AiOverview::acceptancePath() as $step) {
            $this->assertFalse($step['done'],
                "**ادّعاءُ إنجاز**: الدرجةُ {$step['key']} تُعلَن مُنجَزةً على تنصيبٍ خامّ");
        }
    }

    /** **والخطوةُ التاليةُ تتقدّم مع التهيئةِ ولا تقف عند آخرِ درجةٍ بُنيت** */
    public function test_الخطوةُ_التاليةُ_تمتدُّ_حتّى_أوّلِ_سؤال(): void
    {
        $this->assertStringContainsString('البوّابة', (string) AiOverview::nextStep()['title']);

        $this->gatewayReady();
        $this->chain();
        FeatureRegistry::flush();

        // السلسلةُ جاهزةٌ ولا توليدَ مُثبَت ⇒ الدرجةُ D هي التالية
        $next = AiOverview::nextStep();
        $this->assertStringContainsString('الفاحص D', (string) $next['title']);

        Settings::put('ai.generation_ok', '1', 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();

        $next = AiOverview::nextStep();
        $this->assertSame('ask.index', (string) $next['route'],
            '**السلّمُ وقف قبل منتهاه** — والميزةُ التي بُني لأجلِها لا تُذكَر');

        foreach (AiOverview::acceptancePath() as $step) {
            $this->assertTrue($step['done'], "الدرجةُ {$step['key']} بقيت ناقصةً والتهيئةُ تامّة");
        }
    }

    // ═══ ② الشاشةُ تعرض السلّم ═══

    public function test_شاشةُ_المركزِ_تعرض_مسارَ_التشغيل(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('ai.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('مسارُ التشغيلِ الحقيقيّ', $html);
        $this->assertStringContainsString('Discovery', $html);
        $this->assertStringContainsString('Ask', $html);
    }

    // ═══ ③ غرضُ المساعدِ يُختار من الشاشة ═══

    public function test_الغرضُ_يُضبَط_بنقرةٍ_من_شاشةِ_التوجيه(): void
    {
        $this->gatewayReady();
        AiProfiles::seed();
        $alt = $this->chain('fast');

        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.profiles.ask', $alt))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('fast', AskPolicy::profileKey(),
            'النقرةُ لم تُغيّر الغرضَ — فيبقى التبديلُ تعديلَ شيفرة');
    }

    /** **ولا يُقبَل غرضٌ يُطفئ المساعدَ فور ضبطِه** */
    public function test_غرضٌ_بسلسلةٍ_فارغةٍ_يُرَدّ(): void
    {
        AiProfiles::seed();
        // **غرضٌ بعينِه لا «أوّلُ ما يعود»** — و`first()` بلا ترتيبٍ قرعةٌ بين المحرّكين
        $empty = AiProfile::query()->where('key', 'cheap')->firstOrFail();
        $this->assertTrue(AiProfiles::chain($empty)->isEmpty(), 'الغرضُ المختارُ ليس فارغاً');

        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.profiles.ask', $empty))
            ->assertRedirect()->assertSessionHasErrors('ask');

        $this->assertSame(AskPolicy::PROFILE, AskPolicy::profileKey(),
            '**ضُبط غرضٌ فارغٌ** — والمساعدُ يُطفأ برسالةٍ تبدو عطلاً');
    }

    public function test_موظّفٌ_عاديٌّ_لا_يضبط_غرضَ_المساعد(): void
    {
        AiProfiles::seed();
        $g = AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail();


        $this->actingAs($this->employee)->stepped()
            ->post(route('ai.profiles.ask', $g))->assertForbidden();
    }

    // ═══ ④ وما يبقى للمالكِ خمسُ خطواتٍ لا سادسةَ فيها شيفرة ═══

    /**
     * **برهانٌ أنّ ربطَ المولِّدِ لم يعد تعديلَ شيفرة.**
     *
     * كان `AppServiceProvider` يربط المولِّدَ الفارغَ ربطاً ثابتاً، فكان
     * «التشغيلُ» يعني تحريرَ ملفٍّ ودفعةً ونشراً. وصار القرارُ يُقرأ من حالةِ
     * النظام — **فالاختبارُ يقلب الحالةَ ولا يمسّ سطراً**.
     */
    public function test_لا_يبقى_تعديلُ_شيفرةٍ_لتشغيلِ_المساعد(): void
    {
        $this->assertSame(AskGeneratorFactory::NONE, AskGeneratorFactory::which());

        $this->gatewayReady();
        $this->chain();
        Settings::put('ai.generation_ok', '1', 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();

        $this->assertSame(AskGeneratorFactory::LIVE, AskGeneratorFactory::which(),
            'بقي المساعدُ مُطفأً رغم اكتمالِ كلِّ ما يُضبَط من الواجهة');
    }
}

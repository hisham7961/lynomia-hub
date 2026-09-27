<?php

namespace Tests\Feature\AskHub;

use App\Contracts\AskGenerator;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Ask\AskGeneratorFactory;
use App\Support\Ai\Ask\AskPipeline;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Platform\FeatureRegistry;
use App\Support\Ai\Ask\LiteLlmAskGenerator;
use App\Support\Ai\Ask\NullAskGenerator;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الربطُ: من يُجيب ومتى** (جاهزيّةُ الإنتاج · PR-4).
 *
 * المطلوبُ من المالكِ صريح: **لا تعديلَ شيفرةٍ لتشغيلِ المساعد.** فالقرارُ
 * يُقرأ من حالةِ النظامِ حيّةً، ويجب أن **يفشل مُغلَقاً**: تهيئةٌ ناقصةٌ تُنتج
 * تصنيفاً صريحاً، **لا محاولةَ اتصالٍ برجاء ولا مزوّداً افتراضيّاً**.
 */
class AskWiringTest extends TestCase
{
    use RefreshDatabase;

    private User $asker;
    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        Project::create(['name' => 'مشروعُ ألِف 771234', 'company_id' => $this->alpha->id]);

        $modules = array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role    = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        $this->asker = User::create(['name' => 'سائل', 'email' => 'wire@ask.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$this->alpha->id]]);
    }

    /** **إعداداتُ الجاهزيّةِ وحدَها** — تُعاد كلّما كُسر ركنٌ ثمّ رُمِّم */
    private function settingsReady(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) {
            Settings::put($k, '1', 'test');
        }
        // **البصمةُ بعد المفتاحِ لا قبلَه** — فهي تُحسَب من العنوانِ والمفتاحِ معاً
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ask.profile', AskPolicy::PROFILE, 'test');

        FeatureRegistry::flush();
    }

    /** يُهيّئ الأركانَ الأربعةَ كلَّها — بلا مزوّدٍ حقيقيٍّ ولا نداء */
    private function ready(string $profileKey = AskPolicy::PROFILE): void
    {
        $this->settingsReady();
        AiProfiles::seed();

        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-wire-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'configured',
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
            'limits'  => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params'  => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);

        AiProfiles::attach(AiProfile::query()->where('key', $profileKey)->firstOrFail(), $model);
    }

    // ═══ ① القرارُ الحيّ ═══

    public function test_باكتمالِ_الأركانِ_الأربعةِ_يُختار_المولِّدُ_الحيّ(): void
    {
        $this->ready();

        $this->assertSame(AskGeneratorFactory::LIVE, AskGeneratorFactory::which());
        $this->assertNull(AskGeneratorFactory::why());
        $this->assertInstanceOf(LiteLlmAskGenerator::class, AskGeneratorFactory::make());
        $this->assertTrue(AskGeneratorFactory::make()->isLive());
    }

    /**
     * **وكلُّ ركنٍ ينقص وحدَه يُسقط القرارَ إلى الفارغ** — ولا يكفي اجتماعُ ثلاثة.
     */
    public function test_كلُّ_ركنٍ_ناقصٍ_وحدَه_يُغلق_البابَ(): void
    {
        $breaks = [
            'البوّابةُ مطفأة'        => fn () => Settings::put('ai.enabled', '0', 'test'),
            'لا مفتاحَ للبوّابة'     => fn () => Settings::put('ai.gateway_key', '', 'test'),
            'الفحصُ لم يجرِ'         => fn () => Settings::put('ai.probe_ok', '0', 'test'),
            'بصمةٌ زالت'             => fn () => Settings::put('ai.probe_fp', 'stale-fingerprint', 'test'),
            'لا توليدَ مُثبَت'        => fn () => Settings::put('ai.generation_ok', '0', 'test'),
            'غرضٌ لا وجودَ له'       => fn () => Settings::put('ask.profile', 'لا-غرضَ-بهذا-الاسم', 'test'),
        ];

        $this->ready();

        foreach ($breaks as $why => $break) {
            $break();
            FeatureRegistry::flush();

            $this->assertSame(AskGeneratorFactory::NONE, AskGeneratorFactory::which(),
                "**بابٌ بقي مفتوحاً** رغم: {$why}");
            $this->assertNotNull(AskGeneratorFactory::why(),
                "سقط القرارُ بلا سببٍ يُقال للمدير: {$why}");
            $this->assertInstanceOf(NullAskGenerator::class, AskGeneratorFactory::make());

            // **ويُرمَّم الركنُ فيعود البابُ** — وإلّا لَمرّت الحالاتُ التاليةُ
            // على كسرٍ سابقٍ لا على كسرِها هي، فيخضرّ الاختبارُ بلا أن يقيس شيئاً
            $this->settingsReady();
            $this->assertSame(AskGeneratorFactory::LIVE, AskGeneratorFactory::which(),
                "لم يعد البابُ بعد ترميمِ: {$why}");
        }
    }

    /** **ولا مزوّدَ افتراضيَّ ولا نداءَ رجاءٍ عند نقصِ التهيئة** */
    public function test_تهيئةٌ_ناقصةٌ_لا_تُنتج_نداءً(): void
    {
        Http::fake();

        $this->assertSame(AskGeneratorFactory::NONE, AskGeneratorFactory::which());

        $r = AskPipeline::ask('كم مشروعاً لديّ؟', $this->asker, AskGeneratorFactory::make());

        Http::assertNothingSent();
        $this->assertSame(AskFailures::UNAVAILABLE, $r['failure']);
        $this->assertFalse($r['meta']['live'],
            'الشاشةُ تُعلن حياةً لا وجودَ لها — والصدقُ على الشاشةِ يبدأ من هنا');
    }

    // ═══ ② حارسُ بيئةِ الاختبار ═══

    /**
     * **الحاويةُ لا تُسلّم مولِّداً حيّاً في الاختبارِ ولو اكتملت التهيئة.**
     *
     * حزمةٌ تُهيّئ البوّابةَ لغرضٍ آخرَ (فحصُ جاهزيّةٍ مثلاً) كانت ستطرق
     * `127.0.0.1:4000` من حيث لا تدري، فتُقاس نتيجتُها بردِّ منفذٍ مغلق.
     */
    public function test_الحاويةُ_تُسلّم_الفارغَ_في_الاختبارِ_دائماً(): void
    {
        $this->ready();

        $this->assertSame(AskGeneratorFactory::LIVE, AskGeneratorFactory::which(),
            'القرارُ الحيُّ نفسُه يجب أن يبقى مقيساً — لا أن يُخفيه الحارس');
        $this->assertInstanceOf(NullAskGenerator::class, app(AskGenerator::class));
    }

    /** **ومولِّدٌ جديدٌ لكلِّ استدعاء** — فلا حوارُ سائلٍ يتسرّب إلى آخر */
    public function test_لا_مفردةَ_مشتركةٌ_بين_طلبين(): void
    {
        $this->assertNotSame(app(AskGenerator::class), app(AskGenerator::class));
    }

    // ═══ ③ الغرضُ إعدادٌ لا ثابتٌ في الشيفرة ═══

    public function test_غرضُ_المساعدِ_يُبدَّل_من_الإعداداتِ_لا_من_الشيفرة(): void
    {
        AiProfiles::seed();
        $alt = AiProfile::query()->where('key', '!=', AskPolicy::PROFILE)->firstOrFail();

        Settings::put('ask.profile', (string) $alt->key, 'test');

        $this->assertSame((string) $alt->key, AskPolicy::profileKey(),
            'الغرضُ المضبوطُ لم يُقرأ — فتبديلُ النموذجِ يبقى تعديلَ شيفرة');
    }

    /** وقيمةٌ فارغةٌ تعود إلى الافتراضيِّ ولا تُطفئ الميزةَ صامتةً */
    public function test_غرضٌ_فارغٌ_يعود_إلى_الافتراضيِّ(): void
    {
        Settings::put('ask.profile', '   ', 'test');

        $this->assertSame(AskPolicy::PROFILE, AskPolicy::profileKey());
    }

    // ═══ ④ السقوفُ تُضبَط ولا تُرفَع فوقَ الصلب ═══

    /**
     * **الحرفيُّ والثابتُ والكتالوجُ ثلاثتُها تقول الشيءَ نفسَه.**
     *
     * الافتراضيّاتُ تُكتَب مرّتين عمداً: حرفيّاً في `setting('k', <حرفيّ>)`
     * ليراها مسحُ `SettingsCenterTest`/`SettingsModelTest`، وثابتاً في هذا
     * الصنفِ ليُقرَأ في الشيفرة. **وتكرارٌ بلا حارسٍ ينحرف**: يُرفَع الثابتُ
     * يوماً ويبقى الحرفيُّ، فتقول الشاشةُ رقماً ويفرض الخادمُ غيرَه.
     */
    public function test_الافتراضيّاتُ_لا_تنحرف_بين_الشيفرةِ_والكتالوج(): void
    {
        $catalog = (array) config('hub_settings.groups');
        $flat    = [];
        foreach ($catalog as $items) {
            foreach ((array) $items as $key => $meta) {
                if (is_array($meta) && array_key_exists('default', $meta)) $flat[$key] = $meta['default'];
            }
        }

        $expected = [
            'ask.profile'           => AskPolicy::PROFILE,
            'ask.max_output_tokens' => AskPolicy::MAX_OUTPUT_TOKENS,
            'ask.max_model_steps'   => AskPolicy::MAX_TOOL_CALLS,
            'ask.max_tool_calls'    => AskPolicy::MAX_TOOL_CALLS,
        ];

        foreach ($expected as $key => $const) {
            $this->assertArrayHasKey($key, $flat, "[{$key}] غائبٌ عن كتالوجِ الإعدادات");
            $this->assertSame((string) $const, (string) $flat[$key],
                "[{$key}] الكتالوجُ يقول «{$flat[$key]}» والثابتُ يقول «{$const}»");
        }

        // وبلا ضبطٍ يجب أن تعود الدوالُّ إلى الافتراضيِّ نفسِه — لا إلى غيرِه
        $this->assertSame(AskPolicy::PROFILE, AskPolicy::profileKey());
        $this->assertSame(AskPolicy::MAX_OUTPUT_TOKENS, AskPolicy::maxOutputTokens());
        $this->assertSame(AskPolicy::MAX_TOOL_CALLS, AskPolicy::maxModelSteps());
        $this->assertSame(AskPolicy::MAX_TOOL_CALLS, AskPolicy::maxToolCalls());
        $this->assertSame(0.0, AskPolicy::costCeiling());
    }

    /**
     * **الإعدادُ يضبط داخلَ المدى — ولا يخرج من طرفيه.**
     *
     * وكان هذا الصفُّ يُثبِت أنّ `120` تمرّ كما هي، **وكان ذلك عيباً لا
     * ميزة**: المدى كان `[1, 2000]` فتُقبَل قيمةٌ تقتل كلَّ سؤالٍ
     * بـ`OUTPUT_LIMIT` والتهيئةُ تبدو سليمة (قبولُ إنتاجٍ حقيقيّ). فصار
     * المدى `[256, 2000]`، وما دون الأرضيّةِ يُرفَع إليها.
     */
    public function test_الإعدادُ_يضبط_داخلَ_المدى_ولا_يخرج_من_طرفيه(): void
    {
        Settings::put('ask.max_output_tokens', 120, 'test');
        $this->assertSame(AskPolicy::MIN_OUTPUT_TOKENS, AskPolicy::maxOutputTokens(),
            'قيمةٌ تحت الأرضيّةِ مرّت — وهي تقتل كلَّ سؤالٍ في الإنتاج');

        Settings::put('ask.max_output_tokens', 900, 'test');
        $this->assertSame(900, AskPolicy::maxOutputTokens(), 'قيمةٌ داخلَ المدى لم تُحترَم');

        Settings::put('ask.max_output_tokens', 999999, 'test');
        $this->assertSame(AskPolicy::HARD_OUTPUT_TOKENS, AskPolicy::maxOutputTokens(),
            '**حارسُ الكلفةِ صار بابَ إنفاق**: رقمٌ في شاشةٍ تجاوز السقفَ الصلب');

        Settings::put('ask.max_model_steps', 99, 'test');
        $this->assertSame(AskPolicy::HARD_MODEL_STEPS, AskPolicy::maxModelSteps());

        Settings::put('ask.max_tool_calls', 0, 'test');
        $this->assertSame(1, AskPolicy::maxToolCalls(), 'صفرٌ يُقصّ إلى أدنى المدى لا يُقبَل');

        Settings::put('ask.max_output_tokens', 'ليس رقماً', 'test');
        $this->assertSame(AskPolicy::MAX_OUTPUT_TOKENS, AskPolicy::maxOutputTokens(),
            'قيمةٌ غيرُ عدديّةٍ تعود إلى الافتراضيِّ لا إلى صفر');
    }
}

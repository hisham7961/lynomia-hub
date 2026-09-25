<?php

namespace Tests\Feature\AskHub;

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
use App\Support\Ai\Ask\AskPipeline;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\FeatureRegistry;
use App\Support\Ai\Ask\LiteLlmAskGenerator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **دورةُ «اسأل Hub» الحيّة — بلا دينارٍ واحد** (جاهزيّةُ الإنتاج · PR-3/PR-5).
 *
 * كلُّ ردٍّ هنا لقطةٌ مبنيّةٌ على عقدِ الإصدارِ المثبَّت، و`Http::fake()` يعترض
 * كلَّ شيء. فما يُقاس هنا **ما نفعله نحن بالردّ**: التسلسلُ والحرّاسُ
 * والتصنيفُ والسقوف.
 *
 * **وما لا يُقاس هنا يُقال صراحةً:** أنّ نموذجاً حقيقيّاً يلتزم بالعقدِ
 * ويُنتج جواباً صحيحاً — **لا تُثبِته لقطة**، ويبقى لقبولِ الإنتاجِ بقرارِ
 * المالك.
 */
class LiteLlmAskGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private User $asker;
    private Company $alpha;

    /** @var list<array> */
    private array $sent = [];

    /** @var list<array{0:array,1:int}> */
    private array $steps = [];

    private int $at = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        Project::create(['name' => 'مشروعُ ألِف 771234', 'company_id' => $this->alpha->id]);

        $this->asker = $this->scopedUser('live@ask.local');
        $this->ready();
        $this->intercept();
    }

    private function scopedUser(string $email, ?array $modules = null): User
    {
        $modules = $modules ?? array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$this->alpha->id]]);
    }

    /** جاهزيّةٌ كاملةٌ بلا مزوّدٍ حقيقيٍّ ولا نداءٍ مدفوع */
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

        $this->model('hub-general');
    }

    /** يُلحق نموذجاً بسلسلةِ غرضِ «اسأل Hub» ويعيده */
    private function model(string $name): AiModel
    {
        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدُ ' . $name, 'enabled' => true,
            'credential_name' => 'hub-' . substr(sha1($name . microtime(true)), 0, 12),
            'credential_state' => 'configured',
        ]);

        $model = AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => $name,
            'upstream_model' => 'fake/' . $name, 'display_name' => $name,
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

        AiProfiles::attach(AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);

        return $model;
    }

    /**
     * **يزرع ردوداً بالترتيب** ويحفظ ما أُرسِل — فالمُرسَلُ يُفحَص كما يُفحَص الوارد.
     *
     * ── **ولمَ يُسجَّل الاعتراضُ مرّةً واحدةً في `setUp`؟** ──
     *
     * `Http::fake()` بمُغلَقٍ **يُضيف** إلى المُعترِضاتِ ولا يستبدلها. فنداءٌ
     * ثانٍ داخلَ الاختبارِ نفسِه يترك الأوّلَ قائماً، **ويبقى الأوّلُ هو من
     * يجيب**. وهذا بعينُه ما أسقط أوّلَ تشغيلٍ لهذا الصنف: حالةٌ ثانيةٌ في
     * حلقةٍ واحدةٍ كانت تُقاس بردِّ الحالةِ الأولى، **فبدا التصنيفُ منحرفاً
     * والخللُ في أداةِ القياس**. فالمُعترِضُ واحدٌ يقرأ `$steps` الحيّة.
     *
     * @param  list<array{0:array,1:int}>  $steps  `[الجسم, الرمز]`
     */
    private function script(array $steps): void
    {
        $this->sent  = [];
        $this->steps = $steps;
        $this->at    = 0;
    }

    private function intercept(): void
    {
        Http::fake(function ($req) {
            $this->sent[] = (array) $req->data();
            $step = $this->steps[min($this->at, max(0, count($this->steps) - 1))]
                ?? [LiteLlmFixtures::answer('لا شيء.'), 200];
            $this->at++;

            return Http::response($step[0], $step[1] ?? 200);
        });
    }

    private function ask(?User $u = null): array
    {
        return AskPipeline::ask('كم مشروعاً لديّ؟', $u ?? $this->asker, new LiteLlmAskGenerator());
    }

    // ═══ ① الدورةُ كاملةً ═══

    /**
     * **سؤال → طلبُ أداة → تصريحٌ خادميّ → تنفيذ → مظروف → خطوةٌ ثانية → جواب.**
     */
    public function test_دورةُ_الأدواتِ_كاملةً_تنتهي_بجوابٍ_مُصادَق(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'call_abc123', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديك مشروعٌ واحدٌ نشط [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        $r = $this->ask();

        $this->assertTrue($r['ok'], 'الدورةُ لم تكتمل: ' . (string) $r['failure']);
        $this->assertStringContainsString('مشروعٌ واحد', (string) $r['answer']);
        $this->assertCount(2, $this->sent, 'خطوتانِ لا أكثرَ ولا أقلّ');
        $this->assertNotEmpty($r['sources'], 'الجوابُ بلا مصادرَ قرأها الخادم');
        $this->assertSame('litellm', $r['meta']['generator']);
        $this->assertTrue($r['meta']['live']);
    }

    /** **تسلسلُ `assistant`→`tool` بمعرّفِ النداءِ الذي ولّده النموذج** */
    public function test_تسلسلُ_الرسائلِ_يحمل_معرّفَ_النداءِ_كما_ورد(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'call_abc123', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('واحد [#1].'), 200],
        ]);

        $this->ask();

        $second = $this->sent[1]['messages'];
        $roles  = array_column($second, 'role');

        /*
         * **ورسالتا `user` لا واحدة** (`21b7633f`): الأولى سؤالُ المستخدمِ
         * والثانيةُ المظروف. وكان التسلسلُ أربعةً لأنّ السؤالَ **لم يكن
         * يُرسَل أصلاً** — فحرَس هذا الصفُّ العطبَ بوصفِه عقداً.
         */
        $this->assertSame(['system', 'user', 'user', 'assistant', 'tool'], $roles,
            '**تسلسلٌ فاسد** — ومزوّدٌ صارمٌ يردّه ٤٠٠');

        $assistant = $second[3];
        $tool      = $second[4];

        $this->assertSame('call_abc123', $assistant['tool_calls'][0]['id']);
        $this->assertSame('call_abc123', $tool['tool_call_id'],
            '**معرّفُ النداءِ لم يُطابق** — والعقدُ يوجب تطابقَهما');
        $this->assertSame('hub_count', $assistant['tool_calls'][0]['function']['name']);
    }

    /**
     * **صفوفُ النتيجةِ لا تُكرَّر في رسالةِ الأداة.**
     *
     * نسختان من بياناتٍ غيرِ موثوقةٍ إحداهما **خارجَ السياجِ المُرقَّم** ثقبٌ في
     * الحاجزِ الذي بُني لها — فضلاً عن مضاعفةِ السياقِ والكلفة.
     */
    public function test_رسالةُ_الأداةِ_إشارةٌ_لا_نسخةٌ_من_البيانات(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['name' => 'hub_list', 'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('واحد [#1].'), 200],
        ]);

        $this->ask();

        $toolMsg = $this->sent[1]['messages'][4]['content'];   // +١: السؤالُ رسالةٌ مستقلّة

        $this->assertStringNotContainsString('771234', $toolMsg,
            '**صفٌّ تكرّر خارجَ المظروف** — نسخةٌ ثانيةٌ بلا سياجٍ ولا ترقيم');
        $this->assertStringContainsString('المصدرِ رقم', $toolMsg);
    }

    /** **نداءٌ واحدٌ يُشرَّف** — فلا `assistant` بنداءين مقابلَ نتيجةٍ واحدة */
    public function test_نداءاتٌ_متوازيةٌ_يُشرَّف_أوّلُها_وحدَه(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([
                ['id' => 'c1', 'name' => 'hub_count',  'args' => ['module' => 'projects']],
                ['id' => 'c2', 'name' => 'hub_list',   'args' => ['module' => 'projects']],
            ]), 200],
            [LiteLlmFixtures::answer('واحد [#1].'), 200],
        ]);

        $this->ask();

        $assistant = $this->sent[1]['messages'][3];   // +١: السؤالُ رسالةٌ مستقلّة

        $this->assertCount(1, $assistant['tool_calls'],
            '**تسلسلٌ فاسد**: نداءان مُسجَّلان ونتيجةٌ واحدةٌ مُرسَلة');
        $this->assertSame('c1', $assistant['tool_calls'][0]['id']);
    }

    // ═══ ② ردودٌ فاسدةٌ أو محجوبة ═══

    public function test_وسائطُ_أداةٍ_ليست_JSON_طلبٌ_فاسدٌ_لا_انهيار(): void
    {
        $this->script([[LiteLlmFixtures::toolCall([
            ['name' => 'hub_list', 'args' => '{ليست JSON'],
        ]), 200]]);

        $r = $this->ask();

        $this->assertSame(AskFailures::MALFORMED_TOOL_REQUEST, $r['failure']);
    }

    /**
     * **ويسبقه قراءةٌ حقيقيّة** (`21b7633f`): جوابٌ نهائيٌّ بلا قراءةٍ صار
     * يسقط بـ`NO_SERVER_READ` قبل أن يُنظَر في بتره — فالقياسُ هنا للبترِ
     * وحدَه، فيُعطى الطلبُ قراءتَه.
     */
    public function test_جوابٌ_بلغ_سقفَ_الرموزِ_يُعلَن_جزئيّاً(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::truncated('الجوابُ بدأ ثمّ'), 200],
        ]);

        $r = $this->ask();

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['partial'],
            '**جوابٌ مبتورٌ عُرض تامّاً** — و`finish_reason: length` يقول إنّه ليس كذلك');
        $this->assertSame(AskFailures::PARTIAL_RESULT, $r['failure']);
    }

    public function test_حجبُ_المحتوى_نتيجةٌ_لها_رمزُها(): void
    {
        $this->script([[LiteLlmFixtures::filtered(), 200]]);

        $r = $this->ask();

        $this->assertSame(AskFailures::CONTENT_FILTERED, $r['failure'],
            'حجبُ المحتوى ظهر «ردّاً غيرَ مفهوم» — فيُطارَد عطلٌ لا وجودَ له');
    }

    /** **صار له اسمُه** بعد قبولِ الإنتاج: «لا مخرَجَ» لا «غيرُ مفهوم» */
    public function test_ردٌّ_بلا_نصٍّ_ولا_نداءٍ_عطلُ_نموذج(): void
    {
        $this->script([[LiteLlmFixtures::answer(''), 200]]);

        $this->assertSame(AskFailures::MODEL_NO_OUTPUT, $this->ask()['failure']);
    }

    /** **مرجعٌ مُختلَقٌ يُسقط الجوابَ كلَّه** — والحارسُ يعمل على المسارِ الحيّ */
    public function test_مرجعٌ_لم_يقرأه_الخادمُ_يُسقط_الجواب(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['name' => 'hub_list', 'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('حسب المصدر [#99] لديك ٤٠٠ مشروع.'), 200],
        ]);

        $r = $this->ask();

        $this->assertSame(AskFailures::FORGED_SOURCE, $r['failure']);
        $this->assertNull($r['answer'], '**نصُّ جوابٍ مُختلَقٍ عُرض** رغم سقوطِ مرجعِه');
    }

    // ═══ ③ تصنيفُ الإخفاقِ عبر المسارِ كاملاً ═══

    public function test_خرائطُ_الإخفاقِ_من_البوّابةِ_إلى_المستخدم(): void
    {
        $cases = [
            [LiteLlmFixtures::rateLimited(),  429, AskFailures::RATE_LIMITED],
            [LiteLlmFixtures::noDeployment(), 429, AskFailures::PROVIDER_FAILURE],
            [LiteLlmFixtures::timedOut(),     408, AskFailures::TIMEOUT],
            [LiteLlmFixtures::gatewayAuth(),  401, AskFailures::GATEWAY_FAILURE],
            [LiteLlmFixtures::providerAuth(), 401, AskFailures::PROVIDER_FAILURE],
            [LiteLlmFixtures::contextExceeded(), 400, AskFailures::CONTEXT_LIMIT],
        ];

        foreach ($cases as [$fixture, $code, $expected]) {
            // **تهدئةُ المزوّدِ تتراكم بين الحالات** — وهي سلوكٌ صحيحٌ في
            // الإنتاجِ يفسد القياسَ هنا: ثالثُ إخفاقٍ يُستبعَد المزوّدُ فتُقاس
            // الحالةُ الرابعةُ على «مزوّدٍ مُستبعَد» لا على ردِّها هي
            \Illuminate\Support\Facades\Cache::flush();

            $this->script([[$fixture, $code]]);

            $this->assertSame($expected, $this->ask()['failure'],
                'انحرف التصنيفُ عند HTTP ' . $code . ' — المنتظَر ' . $expected
                . ' · المتنُ: ' . mb_substr((string) ($fixture['error']['message'] ?? ''), 0, 60));
        }
    }

    /**
     * **سلسلةٌ كلُّ مزوّديها في تهدئةٍ عطلٌ لا «إعدادٌ ناقص».**
     *
     * والرسالتان تُرسلان المديرَ إلى مكانين متناقضين: «الإعدادُ لم يكتمل»
     * تجعله يُراجع تهيئةً سليمةً، بينما الحقيقةُ أنّ مزوّداً يتعافى بعد دقائق.
     */
    public function test_تهدئةُ_المزوّدِ_عطلٌ_لا_إعدادٌ_ناقص(): void
    {
        $provider = AiProfiles::chain(
            AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail()
        )->first()->provider_id;

        for ($i = 0; $i < \App\Support\Ai\Routing\AiRouting::COOLDOWN_AFTER; $i++) {
            \App\Support\Ai\Routing\AiRouting::noteFailure((string) $provider);
        }

        $this->script([[LiteLlmFixtures::answer('لن يُستدعى.'), 200]]);
        $r = $this->ask();

        $this->assertSame(AskFailures::PROVIDER_FAILURE, $r['failure'],
            '**عطلُ مزوّدٍ ظهر إعداداً ناقصاً** — والمديرُ يُرسَل يُراجع تهيئةً سليمة');
        $this->assertSame([], $this->sent,
            'نداءٌ خرج إلى مزوّدٍ في تهدئة — والحارسُ ③ وُضع ليمنع هذه المهلةَ بالذات');
    }

    // ═══ ④ حرّاسُ الكلفة ═══

    /** **سقفُ رموزِ المخرَجِ في كلِّ طلبٍ يخرج** — لا في الأوّلِ وحدَه */
    public function test_سقفُ_الرموزِ_مفروضٌ_في_كلِّ_نداء(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['name' => 'hub_list', 'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('واحد [#1].'), 200],
        ]);

        $this->ask();

        foreach ($this->sent as $i => $body) {
            $this->assertSame(AskPolicy::maxOutputTokens(), (int) $body['max_tokens'],
                "السقفُ غاب عن النداءِ رقم {$i}");
            $this->assertFalse($body['stream']);
        }
    }

    /**
     * **الإعادةُ والاحتياطُ لا يضاعفان الكلفةَ بلا حدّ.**
     *
     * كلُّ نداءٍ يسقط بـ٥٠٠ — وهو الصنفُ الذي يسمح بإعادتين ثمّ احتياطٍ
     * لقفزتين. والمقياسُ أنّ مجموعَ ما خرج **لا يتجاوز الحاجزَ الصلب**.
     */
    public function test_الإعادةُ_والاحتياطُ_محدودانِ_بحاجزٍ_صلب(): void
    {
        $this->model('hub-backup-1');
        $this->model('hub-backup-2');

        $this->script([[LiteLlmFixtures::error('litellm.InternalServerError: boom',
            'internal_server_error', 500), 500]]);

        $r = $this->ask();

        $this->assertFalse($r['ok']);
        $this->assertLessThanOrEqual(AskPolicy::MAX_GENERATION_CALLS, count($this->sent),
            '**تجاوزُ حاجزِ الكلفة**: نداءاتُ توليدٍ أكثرُ ممّا تسمح به السياسة');
        $this->assertGreaterThan(1, count($this->sent), 'لم تُبذَل إعادةٌ ولا احتياط');
    }

    /** **والاحتياطُ يقفز إلى النموذجِ التالي في السلسلةِ فعلاً** */
    public function test_الاحتياطُ_يبلغ_النموذجَ_التالي(): void
    {
        $this->model('hub-backup-1');

        $this->script([[LiteLlmFixtures::error('litellm.InternalServerError: boom',
            'internal_server_error', 500), 500]]);

        $this->ask();

        $models = array_values(array_unique(array_column($this->sent, 'model')));

        $this->assertContains('hub-general', $models);
        $this->assertContains('hub-backup-1', $models,
            '**السلسلةُ لم تُستعمَل**: سقط الأوّلُ ولم يُجرَّب ما بعده');
    }

    // ═══ ⑤ الحارسُ الأوّلُ في شكلٍ يفهمه النموذج ═══

    /** **وحدةٌ لا يملكها صاحبُ الجلسةِ لا تصل وصفَ الأدواتِ أصلاً** */
    public function test_وصفُ_الأدواتِ_لا_يحمل_وحدةً_غيرَ_مملوكة(): void
    {
        $narrow = $this->scopedUser('narrow@ask.local', ['projects']);

        $this->script([[LiteLlmFixtures::answer('لا بياناتٍ كافية.'), 200]]);
        $this->ask($narrow);

        $names = json_encode($this->sent[0]['tools'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('projects', (string) $names);
        foreach (['payroll', 'salaries', 'users', 'roles'] as $hidden) {
            $this->assertStringNotContainsString('"' . $hidden . '"', (string) $names,
                "[{$hidden}] ظهر في مفرداتِ الوحداتِ لمن لا يملكه");
        }
    }

    /** **ولا وسيطَ يُعلَن ولا يُنفَّذ** — إعلانُ المتجاهَلِ يُوهم تضييقاً لم يقع */
    public function test_لا_وسيطَ_معلَنٌ_يتجاهله_الخادم(): void
    {
        $this->script([[LiteLlmFixtures::answer('لا شيء.'), 200]]);
        $this->ask();

        $tools = json_encode($this->sent[0]['tools'], JSON_UNESCAPED_UNICODE);

        foreach (['"limit"', '"page"', '"order"', '"sql"'] as $ghost) {
            $this->assertStringNotContainsString($ghost, (string) $tools,
                "وسيطٌ مُعلَنٌ لا يقرؤه الخادم: {$ghost}");
        }
    }
}

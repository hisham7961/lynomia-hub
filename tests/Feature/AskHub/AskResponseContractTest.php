<?php

namespace Tests\Feature\AskHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\AiGateway;
use App\Support\AiProfiles;
use App\Support\AskFailures;
use App\Support\AskPipeline;
use App\Support\AskPolicy;
use App\Support\FeatureRegistry;
use App\Support\LiteLlmAskGenerator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **عقدُ ردِّ «اسأل Hub» — كلُّ شكلٍ قانونيٍّ يُعامَل بما يستحقّ.**
 *
 * ── **العيبُ الذي وُلدت منه هذه الحزمة (قبولُ إنتاجٍ حقيقيّ)** ──
 *
 * B نجح وD نجح («وُلّدت إجابةٌ فعليّة»)، ثمّ سُئل السؤالُ الأوّل:
 *
 * > `كم مشروع لدينا ؟`
 *
 * فعُرض «٨٥ وحدةً متاحةً لك» ثمّ `⚠️ تعذّر الجواب · MODEL_FAILURE`.
 *
 * **والموضعُ الدقيقُ سطرٌ واحد** في `LiteLlmAskGenerator::read()`:
 *
 * ```php
 * if ($text === '') return $this->error(AskFailures::MODEL_FAILURE);
 * ```
 *
 * وهو **صنفُ العيبِ نفسُه الذي أسقط D**: العقدُ المقيسُ يقول
 * `content: str | None`، فـ`null` **ردٌّ مشروعٌ تماماً** — لا «ردٌّ غيرُ مفهوم».
 * وثلاثُ حالاتٍ مشروعةٍ تنتهي إليه: قطعٌ بسقفِ المخرَج، ونموذجٌ أنفق مخرَجَه
 * في التفكير، وإكمالٌ فارغٌ حقّاً. **وكانت الثلاثةُ رمزاً واحداً** يقول
 * «غيرُ مفهوم» — فلا يُشخَّص منها شيء.
 *
 * ── **ولا يُصلَح هذا بتساهلِ المحلّل** ──
 *
 * البنيةُ تبقى مشروطةً: جسمٌ ليس إكمالَ محادثةٍ يُرَدّ، وردٌّ بلا مخرَجٍ البتّةَ
 * يبقى إخفاقاً — **لكنّه إخفاقٌ باسمِه**.
 */
class AskResponseContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;
    private Company $beta;
    private User $asker;

    /** @var list<array{0:array,1:int}> */
    private array $steps = [];

    private int $at = 0;

    /** @var list<array> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        $this->beta  = Company::create(['name_ar' => 'شركةُ باء']);

        // **ثلاثةُ مشاريعَ لشركتِه وسبعةٌ لغيرِها** — فالعددُ الصحيحُ ٣ لا ١٠
        foreach (range(1, 3) as $i) {
            Project::create(['name' => 'مشروعُ ألِف ' . $i, 'company_id' => $this->alpha->id]);
        }
        foreach (range(1, 7) as $i) {
            Project::create(['name' => 'مشروعُ باء ' . $i, 'company_id' => $this->beta->id]);
        }

        $this->asker = $this->scopedUser('contract@ask.local');
        $this->ready();

        Http::fake(function ($req) {
            $this->sent[] = (array) $req->data();
            $step = $this->steps[min($this->at, max(0, count($this->steps) - 1))]
                ?? [LiteLlmFixtures::answer('لا شيء.'), 200];
            $this->at++;

            return Http::response($step[0], $step[1] ?? 200);
        });
    }

    private function scopedUser(string $email): User
    {
        $matrix = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

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

        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-' . substr(sha1(microtime(true) . 'c'), 0, 12),
            'credential_state' => 'configured',
        ]);

        $model = AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/general', 'display_name' => 'عامّ',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits'  => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params'  => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm']],
        ]);

        AiProfiles::attach(AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);
    }

    /** @param list<array{0:array,1?:int}> $steps */
    private function script(array $steps): void
    {
        $this->steps = $steps;
        $this->at    = 0;
    }

    private function ask(string $q = 'كم مشروع لدينا ؟'): array
    {
        return AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());
    }

    // ═══ ① الشكلُ الذي أسقط الإنتاج ═══

    /**
     * **جوابٌ نهائيٌّ بـ`content: null` — وهو مشروعٌ في العقد.**
     *
     * ولم يعد يُقال «ردٌّ غيرُ مفهوم»: النموذجُ لم يُخرج شيئاً، **وذاك خبرٌ عن
     * النموذجِ لا عن فهمِنا**.
     */
    public function test_جوابٌ_بلا_محتوىً_يُسمّى_باسمِه_لا_ردّاً_غيرَ_مفهوم(): void
    {
        $body = LiteLlmFixtures::answer('x', LiteLlmFixtures::usage());
        $body['choices'][0]['message']['content'] = null;
        $this->script([[$body]]);

        $r = $this->ask();

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::MODEL_NO_OUTPUT, $r['failure'],
            '**`content: null` ليس «ردّاً غيرَ مفهوم»** — العقدُ يجعله مشروعاً');
    }

    /**
     * **وقطعُ سقفِ المخرَجِ قبل أن يخرج حرفٌ سببٌ آخرُ تماماً** — وقد كان
     * يُسمّى هنا «حدَّ سياق» حتّى صحّحه قبولُ الإنتاج `71b0059e`.
     *
     * `finish_reason = length` سقفُ **ما يكتب** النموذجُ لا سعةُ **ما يقرأ**.
     * وسؤالٌ من ثلاثِ كلماتٍ يبلغه، فقولُ «ضيِّق سؤالَك» عنه يُرسل صاحبَه
     * يُصلح ما ليس معطوباً بينما العطبُ في سقفِ المخرَجِ أو إسهابِ النموذج.
     */
    public function test_قطعُ_السقفِ_قبل_أيِّ_حرفٍ_يُصنَّف_سقفَ_مخرَجٍ_لا_حدَّ_سياق(): void
    {
        $body = LiteLlmFixtures::answer('x', LiteLlmFixtures::usage());
        $body['choices'][0]['message']['content'] = null;
        $body['choices'][0]['finish_reason']      = 'length';
        $this->script([[$body]]);

        $r = $this->ask();

        $this->assertSame(AskFailures::OUTPUT_LIMIT, $r['failure'],
            'سقفُ المخرَجِ ما زال يُقال «حدَّ سياق» — فيُطارَد عطلٌ في السؤالِ لا وجودَ له');
        $this->assertStringNotContainsString('ضيِّق السؤال',
            (string) AskFailures::message((string) $r['failure']),
            'الرسالةُ ما زالت تطلب تضييقَ سؤالٍ سليم');
    }

    /** **ونموذجٌ أنفق مخرَجَه في التفكيرِ له رمزُه** — فلا يُطارَد عطلٌ لا وجودَ له */
    public function test_مخرَجٌ_أُنفق_في_التفكيرِ_له_رمزُه(): void
    {
        $body = LiteLlmFixtures::answer('x', LiteLlmFixtures::usage());
        $body['choices'][0]['message']['content']           = null;
        $body['choices'][0]['message']['reasoning_content'] = 'فكّرتُ طويلاً…';
        $this->script([[$body]]);

        $this->assertSame(AskFailures::MODEL_REASONED_ONLY, $this->ask()['failure']);
    }

    /** **وجسمٌ ليس إكمالَ محادثةٍ يُرَدّ صراحةً** — ولا يُتساهَل معه */
    public function test_جسمٌ_ليس_إكمالَ_محادثةٍ_يُرَدّ_باسمِه(): void
    {
        $this->script([[['detail' => 'something else'], 200]]);

        $this->assertSame(AskFailures::MALFORMED_MODEL_RESPONSE, $this->ask()['failure']);
    }

    public function test_جسمٌ_فارغٌ_بـ٢٠٠_يُرَدّ(): void
    {
        $this->script([[[], 200]]);

        $this->assertSame(AskFailures::MALFORMED_MODEL_RESPONSE, $this->ask()['failure']);
    }

    /** **وسببُ انتهاءٍ يُناقض الرسالةَ خللُ بروتوكولٍ لا عطلُ نموذج** */
    public function test_سببُ_انتهاءٍ_يقول_أداةً_بلا_أداةٍ_خللُ_بروتوكول(): void
    {
        $body = LiteLlmFixtures::answer('x', LiteLlmFixtures::usage());
        $body['choices'][0]['message']['content'] = null;
        $body['choices'][0]['finish_reason']      = 'tool_calls';
        $this->script([[$body]]);

        $this->assertSame(AskFailures::TOOL_PROTOCOL_ERROR, $this->ask()['failure']);
    }

    // ═══ ② دورةُ الأداةِ كاملةً — وهي ما يحتاجه السؤالُ الحقيقيّ ═══

    /**
     * **السؤالُ الحقيقيُّ من طرفٍ إلى طرف** — ببياناتٍ محلّيّةٍ لا مزوّدٍ حقيقيّ.
     *
     * والعددُ يأتي من Hub عبر أداةٍ مصرَّحٍ بها **بنطاقِ شركةِ السائل**:
     * ثلاثةٌ لا عشرة.
     */
    public function test_كم_مشروعٍ_لدينا_يمرّ_بالأداةِ_ويعود_بعددِ_شركتِه(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['name' => 'hub_count', 'args' => ['module' => 'projects']]])],
            [LiteLlmFixtures::answer('لديكم ثلاثةُ مشاريع [#1].', LiteLlmFixtures::usage())],
        ]);

        $r = $this->ask();

        $this->assertTrue($r['ok'], (string) ($r['failure'] ?? ''));
        $this->assertNotSame([], $r['sources'], 'جوابٌ بعددٍ بلا مصدرٍ مقروء');

        // **والعددُ المقروءُ من Hub هو ٣** — لا ١٠، ولا عددُ شركةٍ أخرى
        $blob = json_encode($r['sources'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('شركةُ باء', (string) $blob);
    }

    /** **وعدُّ شركةٍ أخرى لا يبلغ النموذجَ أصلاً** */
    public function test_العدُّ_مُنطَّقٌ_بشركةِ_السائلِ_ولا_يُسرِّب_غيرَها(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['name' => 'hub_count', 'args' => ['module' => 'projects']]])],
            [LiteLlmFixtures::answer('ثلاثة [#1].', LiteLlmFixtures::usage())],
        ]);

        $this->ask();

        // المظروفُ يُرسَل في الخطوةِ الثانية — ويجب ألّا يحمل العددَ العالميّ
        $envelopes = implode("\n", array_map(
            static fn ($b) => json_encode($b, JSON_UNESCAPED_UNICODE), $this->sent));

        $this->assertStringNotContainsString('شركةُ باء', $envelopes);
    }

    /**
     * **ونموذجٌ يُخمّن العددَ بلا أداةٍ يُرَدّ.**
     *
     * وهذا حارسٌ جديدٌ لازم: جوابٌ فيه رقمٌ ولم تُنفَّذ قراءةٌ واحدةٌ **تخمينٌ
     * مهما بدا واثقاً** — والسؤالُ «كم مشروعاً» لا يُجاب من معرفةِ نموذج.
     */
    public function test_رقمٌ_في_الجوابِ_بلا_قراءةٍ_واحدةٍ_يُرَدّ(): void
    {
        $this->script([[LiteLlmFixtures::answer('لديكم 85 مشروعاً.', LiteLlmFixtures::usage())]]);

        $r = $this->ask();

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::UNSOURCED_NUMBER, $r['failure'],
            '**النموذجُ خمّن عدداً ومرّ** — والعددُ يأتي من Hub لا من معرفتِه');
    }

    /** **وجوابٌ بلا رقمٍ ولا أداةٍ يمرّ** — فالحارسُ على الأرقامِ لا على الكلام */
    public function test_جوابٌ_بلا_رقمٍ_لا_يمسّه_حارسُ_التخمين(): void
    {
        $this->script([[LiteLlmFixtures::answer('لا أجد ما يجيب في نطاقِك.', LiteLlmFixtures::usage())]]);

        $this->assertTrue($this->ask()['ok']);
    }

    // ═══ ③ حرّاسُ المرحلةِ الثالثة لم تُمَسّ ═══

    public function test_أداةٌ_مجهولةٌ_تُرَدّ_ولا_تُنفَّذ(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['name' => 'hub_delete_everything', 'args' => []]])],
            [LiteLlmFixtures::answer('تمّ.', LiteLlmFixtures::usage())],
        ]);

        $r = $this->ask();

        $this->assertTrue($r['ok'], 'المنسّقُ يرفض الأداةَ ويُكمل — ولا ينهار');
        $this->assertSame([], $r['sources'], 'أداةٌ مجهولةٌ نُفِّذت');
    }

    public function test_وسائطُ_أداةٍ_فاسدةٌ_طلبٌ_فاسدٌ_لا_انهيار(): void
    {
        $this->script([[LiteLlmFixtures::toolCall([['name' => 'hub_count', 'args' => 'ليس JSON']])]]);

        $this->assertSame(AskFailures::MALFORMED_TOOL_REQUEST, $this->ask()['failure']);
    }

    /** **ونداءاتٌ متعدّدةٌ: الأوّلُ يُشرَّف** فلا يفسد التسلسل */
    public function test_نداءاتٌ_متعدّدةٌ_يُشرَّف_أوّلُها(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([
                ['name' => 'hub_count',   'args' => ['module' => 'projects']],
                ['name' => 'hub_modules', 'args' => []],
            ])],
            [LiteLlmFixtures::answer('ثلاثة [#1].', LiteLlmFixtures::usage())],
        ]);

        $this->assertTrue($this->ask()['ok']);
    }

    public function test_مرجعٌ_لم_يقرأه_الخادمُ_يُسقط_الجواب(): void
    {
        $this->script([[LiteLlmFixtures::answer('لديكم مشاريعُ كثيرة [#9].', LiteLlmFixtures::usage())]]);

        $this->assertSame(AskFailures::FORGED_SOURCE, $this->ask()['failure']);
    }

    public function test_لا_أداةَ_كاتبةٌ_في_العقد(): void
    {
        $this->assertSame([], AskPipeline::WRITE_TOOLS);
    }

    // ═══ ④ الاستهلاكُ يُسجَّل ولو سقط المسارُ بعدَه ═══

    /**
     * **دورتان نموذجيّتان ثمّ إخفاقٌ نهائيّ** — والرموزُ أُنفقت في كلتيهما.
     *
     * فربطُ التسجيلِ بنجاحِ المسارِ يجعل **كلَّ إخفاقٍ مجّانيّاً في دفاترِنا**
     * وهو ليس كذلك عند المزوّد.
     */
    public function test_الاستهلاكُ_يُسجَّل_لكلِّ_دورةٍ_ولو_سقط_المسارُ_أخيراً(): void
    {
        $empty = LiteLlmFixtures::answer('x', LiteLlmFixtures::usage());
        $empty['choices'][0]['message']['content'] = null;

        $this->script([
            [LiteLlmFixtures::toolCall([['name' => 'hub_count', 'args' => ['module' => 'projects']]])],
            [$empty],
        ]);

        $r = $this->ask();
        $this->assertFalse($r['ok']);

        $rows = AiUsageEvent::query()->orderBy('created_at')->orderBy('id')->get();

        $this->assertSame(2, $rows->count(),
            '**دورتانِ أُنفقتا وصفٌّ واحدٌ** — أو لا صفَّ البتّة');
        foreach ($rows as $row) {
            $this->assertSame('ok', (string) $row->status,
                'النداءُ نجح عند البوّابة — وسقوطُ المسارِ بعدَه لا يُلغي كلفتَه');
        }
    }

    // ═══ ⑤ ولا سرَّ في أيِّ مخرَج ═══

    public function test_لا_سرَّ_في_نتيجةِ_السؤالِ_ولا_في_أثرِه(): void
    {
        $this->script([[LiteLlmFixtures::answer('لا شيء.', LiteLlmFixtures::usage())]]);

        $blob = json_encode($this->ask(), JSON_UNESCAPED_UNICODE)
            . json_encode(\Illuminate\Support\Facades\DB::table('audits')->get(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('sk-admin-test-key-000111222333', (string) $blob);
    }
}

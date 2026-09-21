<?php

namespace Tests\Feature\AskHub;

use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\AiGateway;
use App\Support\AiProbes;
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
 * **ميزانيّةُ المخرَجِ — رقمٌ يُقاس لا دالّةٌ تُقارَن بنفسِها** (قبولُ الإنتاج · `OUTPUT_LIMIT`).
 *
 * ── **لماذا لم تكشف خمسةُ آلافِ اختبارٍ هذا؟** ──
 *
 * الحارسُ القائمُ في `LiteLlmAskGeneratorTest` يقول:
 *
 * ```php
 * $this->assertSame(AskPolicy::maxOutputTokens(), (int) $body['max_tokens']);
 * ```
 *
 * **وهو حشوٌ لا حارس**: يقارن الحمولةَ بالدالّةِ التي بنتها. فلو أعادت
 * `maxOutputTokens()` واحداً لَمرَّ الاختبارُ راضياً، **ومات كلُّ سؤالٍ في
 * الإنتاج**. فما يُقاس هنا **أرقامٌ مطلقة**.
 */
class AskOutputBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $asker;

    private Company $alpha;

    /** @var list<array> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        Project::create(['name' => 'مشروعُ ألِف ٧٧١٢٣٤', 'company_id' => $this->alpha->id]);
        Project::create(['name' => 'مشروعُ ألِف ٧٧١٢٣٥', 'company_id' => $this->alpha->id]);

        $this->asker = $this->scopedUser('budget@ask.local');
        $this->ready();
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① الأرضيّة — إعدادٌ صغيرٌ يُعطّل المنتجَ في صمت
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **سقفٌ لا أرضيّةَ له ليس حارساً بل بابُ تعطيل.**
     *
     * `clamp` كانت `max(1, min($hard, $n))` — **الأرضيّةُ واحد**. فقيمةٌ
     * تُكتَب سهواً في شاشةِ الإعدادات (`16` مثلاً، أو `0`, أو `-5`) تُقبَل،
     * فيُرسَل `max_tokens = 16` ويموت كلُّ سؤالٍ بـ`OUTPUT_LIMIT`
     * **والتهيئةُ تبدو سليمةً على الشاشة**.
     */
    public function test_قيمةٌ_أصغرُ_من_الأرضيّةِ_تُرفَع_إليها_لا_تُقبَل(): void
    {
        foreach (['16', '1', '0', '-5', '255'] as $v) {
            Settings::put('ask.max_output_tokens', $v, 'test');

            $this->assertSame(AskPolicy::MIN_OUTPUT_TOKENS, AskPolicy::maxOutputTokens(),
                "القيمة «{$v}» مرّت دون أرضيّة — فسؤالٌ سليمٌ يموت بـOUTPUT_LIMIT");
        }
    }

    /** **والسقفُ الصلبُ يبقى سقفاً** — فلا يُفتَح بابُ إنفاق */
    public function test_السقفُ_الصلبُ_لا_يُتجاوَز(): void
    {
        Settings::put('ask.max_output_tokens', '999999', 'test');

        $this->assertSame(AskPolicy::HARD_OUTPUT_TOKENS, AskPolicy::maxOutputTokens());
    }

    /** **والافتراضيُّ بينهما** — قيمةٌ عمليّةٌ لا حدٌّ من الحدّين */
    public function test_الافتراضيُّ_داخلَ_المدى(): void
    {
        Settings::forget('ask.max_output_tokens', 'test');

        $d = AskPolicy::maxOutputTokens();

        $this->assertGreaterThan(AskPolicy::MIN_OUTPUT_TOKENS, $d);
        $this->assertLessThan(AskPolicy::HARD_OUTPUT_TOKENS, $d);
        $this->assertSame(AskPolicy::MAX_OUTPUT_TOKENS, $d);
    }

    /**
     * **وخطوةٌ واحدةٌ لا تكفي دورةَ أداةٍ وجواباً** — فأرضيّةُ الخطواتِ اثنتان.
     *
     * بخطوةٍ واحدةٍ يطلب النموذجُ الأداةَ ثمّ **لا يبقى له نداءٌ يقول فيه
     * الجواب** — فيُخفق كلُّ سؤالٍ يحتاج قراءةً، وهي كلُّ الأسئلة.
     */
    public function test_أرضيّةُ_الخطواتِ_تكفي_أداةً_وجواباً(): void
    {
        Settings::put('ask.max_model_steps', '1', 'test');

        $this->assertGreaterThanOrEqual(2, AskPolicy::maxModelSteps(),
            'خطوةٌ واحدةٌ تعني أداةً بلا جواب');
        $this->assertSame(AskPolicy::MIN_MODEL_STEPS, AskPolicy::maxModelSteps());
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② الرقمُ يصل الحمولةَ فعلاً — بقيمةٍ مطلقة
    // ═══════════════════════════════════════════════════════════════════

    /** **ما يُضبَط في الإعداداتِ هو ما يُرسَل** — رقماً مطلقاً لا دالّة */
    public function test_السقفُ_المضبوطُ_يصل_حمولةَ_البوّابةِ_رقماً(): void
    {
        Settings::put('ask.max_output_tokens', '900', 'test');

        $this->ask();

        $this->assertNotEmpty($this->sent, 'لم تُرسَل حمولةٌ أصلاً');
        $this->assertSame(900, (int) ($this->sent[0]['max_tokens'] ?? 0));
    }

    /** **وأرضيّةٌ مضروبةٌ تصل الحمولةَ مرفوعةً** — لا كما كُتبت */
    public function test_قيمةٌ_تحت_الأرضيّةِ_تصل_الحمولةَ_مرفوعةً(): void
    {
        Settings::put('ask.max_output_tokens', '16', 'test');

        $this->ask();

        $this->assertSame(AskPolicy::MIN_OUTPUT_TOKENS, (int) ($this->sent[0]['max_tokens'] ?? 0),
            'وصل ١٦ إلى البوّابةِ — وهو سقفُ الفاحصِ D لا سقفُ منتَج');
    }

    /** **وسقفُ الفاحصِ D يبقى صغيراً عمداً ومستقلّاً** */
    public function test_سقفُ_الفاحصِ_D_صغيرٌ_ومنفصلٌ_عن_المساعد(): void
    {
        $this->assertSame(16, AiProbes::MAX_OUTPUT_TOKENS,
            'سقفُ الفاحصِ تغيّر — وغرضُه إثباتُ توليدٍ أدنى بأرخصِ ثمن');

        $this->assertGreaterThan(AiProbes::MAX_OUTPUT_TOKENS, AskPolicy::MIN_OUTPUT_TOKENS,
            'أرضيّةُ المساعدِ عند سقفِ الفاحصِ أو دونه — فورث المنتجُ ميزانيّةَ فحص');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ③ دلالةُ التفكيرِ لا تختفي خلف «بلغ السقف»
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **نموذجٌ أنفق ميزانيّتَه في التفكيرِ ثمّ قُطع — العلاجُ غيرُ العلاج.**
     *
     * كان `finish_reason = length` يُفحَص **قبل** دليلِ التفكير، فيُقال
     * `OUTPUT_LIMIT` عن نموذجٍ لم يكتب حرفاً لأنّه فكّر حتّى نفدت ميزانيّتُه.
     * والفرقُ عمليّ: الأوّلُ يُعالَج برفعِ السقفِ قليلاً، **والثاني بنموذجٍ
     * غيرِ تفكيريٍّ أو بميزانيّةٍ أوسعَ كثيراً** — ولا يُعرَف أيُّهما بلا تفريق.
     */
    public function test_تفكيرٌ_استنفد_الميزانيّةَ_يُقال_تفكيراً_لا_مجرّدَ_سقف(): void
    {
        $body = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $body['choices'][0]['finish_reason'] = 'length';
        $body['choices'][0]['message']['content'] = null;
        $body['choices'][0]['message']['reasoning_content'] = 'أفكّرُ في كيفيّةِ العدّ…';

        $r = $this->askWith([[$body, 200]]);

        $this->assertSame(AskFailures::MODEL_REASONED_ONLY, (string) $r['failure'],
            'أُنفقت الميزانيّةُ في التفكيرِ وقيل «بلغ الجوابُ سقفَ طولِه»');
    }

    /** **وقطعٌ بلا دليلِ تفكيرٍ يبقى سقفَ مخرَج** */
    public function test_قطعٌ_بلا_تفكيرٍ_يبقى_سقفَ_مخرَج(): void
    {
        $body = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $body['choices'][0]['finish_reason'] = 'length';
        $body['choices'][0]['message']['content'] = null;

        $r = $this->askWith([[$body, 200]]);

        $this->assertSame(AskFailures::OUTPUT_LIMIT, (string) $r['failure']);
    }

    /** **ورموزُ التفكيرِ تُقرأ من العقدِ ولا تُبتلَع** — فالمحاسبةُ تراها */
    public function test_رموزُ_التفكيرِ_تُستخرَج_من_الاستهلاك(): void
    {
        $json = LiteLlmFixtures::answer('جوابٌ [#1].', LiteLlmFixtures::usage());
        $json['usage']['completion_tokens_details'] = ['reasoning_tokens' => 512];

        $u = \App\Support\AiChat::usage($json);

        $this->assertSame(512, (int) ($u['reasoning'] ?? 0),
            'رموزُ التفكيرِ لا تُقرأ — فتُنفَق ولا تُرى في الاستهلاك');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ④ الشاشةُ تقول السقفَ الذي يقتل الطلبَ إن كان صغيراً
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **الرقمُ الذي أسقط الطلبَ لم يكن معروضاً.**
     *
     * الشاشةُ تعرض حدَّ الحروفِ والخطواتِ والصفوف — **ولا تعرض سقفَ المخرَج**،
     * وهو وحدَه ما أنتج `OUTPUT_LIMIT`. فصاحبُ الشاشةِ يقرأ رسالةً تقول
     * «ارفع سقفَ المخرَجِ من الإعدادات» ولا يرى كم هو الآن.
     */
    public function test_شاشةُ_اسأل_تعرض_سقفَ_المخرَجِ_الفعليّ(): void
    {
        Settings::put('ask.max_output_tokens', '900', 'test');

        $html = $this->actingAs($this->asker)->get(route('ask.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('900', (string) $html,
            'السقفُ الفعليُّ غائبٌ عن الشاشةِ — والرسالةُ تُحيل إليه');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑤ دلالاتُ الميزانيّة: لكلِّ خطوةٍ لا للطلبِ كلِّه — والحدُّ الكلّيُّ قائم
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **السقفُ لكلِّ خطوةٍ — والحاجزُ الكلّيُّ عددُ النداءات.**
     *
     * فأسوأُ حالةٍ نظريّةٍ `سقفُ الخطوة × عددُ النداءاتِ المسموح`، وهو رقمٌ
     * محدودٌ بالبناءِ لا بالأمل. وميزانيّاتُ المرحلة ٤ تحجز على كلِّ محاولةٍ
     * بـ`inTokens + outCap`، **فالحدُّ الكلّيُّ مفروضٌ هناك لا هنا**.
     */
    public function test_أسوأُ_حالةٍ_للمخرَجِ_محدودةٌ_بالبناء(): void
    {
        Settings::put('ask.max_output_tokens', (string) AskPolicy::HARD_OUTPUT_TOKENS, 'test');
        Settings::put('ask.max_model_steps', '99', 'test');

        $worst = AskPolicy::maxOutputTokens() * AskPolicy::maxModelSteps();

        $this->assertLessThanOrEqual(
            AskPolicy::HARD_OUTPUT_TOKENS * AskPolicy::HARD_MODEL_STEPS, $worst,
            'أسوأُ حالةٍ تتجاوز حاصلَ السقفين الصلبين — فالحدُّ ليس حدّاً');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑥ الأرضيّةُ ليست رقماً اعتباطيّاً — دورةٌ كاملةٌ تكتمل عندها
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **سؤالُ عدٍّ يكتمل عند الأرضيّةِ نفسِها** — أداةٌ ثمّ جوابٌ بمصدرِه.
     *
     * وهذا ما يجعل `256` رقماً **مشتقّاً لا مُختاراً**: هو أصغرُ سقفٍ تُثبَت
     * عنده الدورةُ كاملةً، ولو كان أصغرَ لَما كان حدَّ ما يعمل.
     */
    public function test_عدٌّ_يكتمل_عند_الأرضيّةِ_نفسِها(): void
    {
        Settings::put('ask.max_output_tokens', (string) AskPolicy::MIN_OUTPUT_TOKENS, 'test');

        $r = $this->ask();

        $this->assertTrue($r['ok'], 'لم تكتمل الدورةُ عند الأرضيّة: ' . (string) $r['failure']);
        $this->assertStringContainsString('مشروعان', (string) $r['answer']);
        $this->assertNotEmpty($r['sources'], 'الجوابُ بلا مصدرٍ قرأه الخادم');
        $this->assertCount(2, $this->sent, 'دورةُ العدِّ نداءان: أداةٌ ثمّ جواب');
    }

    /** **والجوابُ يبقى قصيراً** — فلا يسرد النموذجُ خطواتِه ولا الكتالوج */
    public function test_التوجيهُ_يطلب_الإيجازَ_ولا_يدعو_إلى_سردِ_الخطوات(): void
    {
        $sys = LiteLlmAskGenerator::SYSTEM;

        $this->assertStringContainsString('الإيجاز', $sys);
        $this->assertStringContainsString('لا تسرد خطواتِك', $sys);
        $this->assertStringNotContainsString('فكّر خطوةً خطوة', $sys,
            'التوجيهُ يدعو إلى سلسلةِ تفكيرٍ — وهي تُنفق ميزانيّةَ المخرَج');
    }

    /** **وكلُّ نداءٍ غادر يُسجَّل** — ولو أخفق الطلبُ في النهاية */
    public function test_كلُّ_نداءٍ_غادر_يُسجَّل_في_عدّادِ_النداءات(): void
    {
        $bad = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $bad['choices'][0]['finish_reason'] = 'length';
        $bad['choices'][0]['message']['content'] = null;

        $r = $this->askWith([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [$bad, 200],
        ]);

        $this->assertFalse($r['ok']);
        $this->assertCount(2, $this->sent, 'نداءان غادرا فعلاً');
        $this->assertSame(2, (int) ($r['meta']['calls'] ?? 0),
            'نداءٌ مدفوعٌ غادر ولم يُعَدّ — فيبدو الطلبُ أرخصَ ممّا كلّف');
    }

    // ── أدواتُ التهيئة ────────────────────────────────────────────────

    private function ask(): array
    {
        return $this->askWith([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديك مشروعان [#1].', LiteLlmFixtures::usage()), 200],
        ]);
    }

    /** @param list<array{0:array,1:int}> $steps */
    private function askWith(array $steps): array
    {
        $this->sent = [];
        $at = 0;
        Http::fake(function ($req) use ($steps, &$at) {
            $this->sent[] = self::sentBody($req);
            $s = $steps[min($at, count($steps) - 1)] ?? [LiteLlmFixtures::answer('لا شيء.'), 200];
            $at++;

            return Http::response($s[0], $s[1] ?? 200);
        });

        return AskPipeline::ask('مرحبا كم مشروع لدينا', $this->asker, new LiteLlmAskGenerator());
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
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
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

<?php

namespace Tests\Feature\AskHub;

use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\AiGateway;
use App\Support\AiProfiles;
use App\Support\AskContext;
use App\Support\AskFailures;
use App\Support\AskPipeline;
use App\Support\AskPolicy;
use App\Support\AskTools;
use App\Support\FeatureRegistry;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سؤالُ العدّ — ومسارُ الاكتشافِ الذي يقود إليه** (قبولُ الإنتاج · `71b0059e`).
 *
 * ── **الحالةُ الإنتاجيّةُ المُعاد إنتاجُها هنا** ──
 *
 * سائلٌ يملك **٨٥ وحدة** يسأل «مرحبا كم مشروع لدينا» فيعود `CONTEXT_LIMIT`
 * برسالةٍ تقول «السؤالُ يحتاج بياناتٍ أكثرَ ممّا يتّسع له السياق».
 *
 * **والرسالةُ كانت تُشخّص خطأً.** فالعدُّ لا يحتاج صفّاً واحداً: `hub_count`
 * موجودةٌ منذ المرحلة ٣ وتعدُّ في القاعدةِ ولا تُسلّم صفّاً. والعطلُ في
 * **الطريقِ إليها**: `hub_modules` — وهي الدليلُ الذي يقول للنموذج أين يبحث،
 * ووصفُها نفسُه يدعوه إلى البدءِ بها — كانت تُعيد ٨٥ صفّاً فيقصّها وعاءُ
 * السياقِ إلى **٢٥** بحدِّ صفوفِ **بيانات**. و`projects` ترتيبُها التاسعُ
 * والخمسون أبجديّاً، **فلا تصل النموذجَ أصلاً**.
 */
class AskCountPathTest extends TestCase
{
    use RefreshDatabase;

    private User $asker;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        $this->beta  = Company::create(['name_ar' => 'شركةُ باء']);

        // ثلاثةٌ لألِف واثنان لباء — فالعدُّ الصحيحُ ثلاثةٌ لا خمسة
        foreach (['ألِف ٧٧١٢٣٤', 'ألِف ٧٧١٢٣٥', 'ألِف ٧٧١٢٣٦'] as $n) {
            Project::create(['name' => 'مشروعُ ' . $n, 'company_id' => $this->alpha->id]);
        }
        foreach (['باء ٨٨٩٩٧٧', 'باء ٨٨٩٩٧٨'] as $n) {
            Project::create(['name' => 'مشروعُ ' . $n, 'company_id' => $this->beta->id]);
        }

        $this->asker = $this->scopedUser('count@ask.local', [$this->alpha->id]);
        $this->ready();
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① الدليلُ — وهو موضعُ العطلِ الإنتاجيّ
    // ═══════════════════════════════════════════════════════════════════

    /** **٨٥ وحدةً مصرَّحٌ بها فعلاً** — وهو ما تُعلنه الشاشةُ للسائل */
    public function test_السائلُ_يملك_خمساً_وثمانين_وحدة(): void
    {
        $this->assertCount(85, AskTools::catalog($this->asker));
    }

    /**
     * **دليلُ الوحداتِ يصل كاملاً** — فدليلٌ ناقصٌ يُخفي وحدةً يملكها صاحبُها.
     *
     * وحدُّ الصفوفِ في `AskContext` وُضع لصفوفِ **بياناتٍ** — سجلّاتٍ تحمل
     * معرّفاتٍ وقيماً. وتطبيقُه على **فهرسٍ** يجعل النموذجَ يظنّ أنّ ما رآه
     * هو كلُّ ما يملك، **فيبحث عمّا لا يعرف وجودَه**.
     */
    public function test_دليلُ_الوحداتِ_يصل_كاملاً_لا_مقصوصاً(): void
    {
        $ctx = AskContext::open();
        $res = AskTools::run('hub_modules', [], $this->asker);

        $take = $ctx->addResult($res);

        $this->assertTrue($take['accepted']);
        $this->assertSame(0, (int) $take['dropped'],
            'قُصَّ دليلُ الوحدات — فالنموذجُ يرى بعضَ ما يملك ويظنّه كلَّه');
        $this->assertSame(85, (int) $take['rows']);
    }

    /** **و`projects` من بينها** — وهي التاسعةُ والخمسون أبجديّاً */
    public function test_المشاريعُ_تصل_النموذجَ_في_الدليل(): void
    {
        $ctx = AskContext::open();
        $ctx->addResult(AskTools::run('hub_modules', [], $this->asker));

        $this->assertStringContainsString('projects', $ctx->render(),
            'وحدةُ المشاريعِ غائبةٌ عن الدليلِ الذي يراه النموذج');
    }

    /**
     * **والدليلُ يبقى محدودَ الحجم** — فلا يُفتَح بابٌ بحجّةِ إصلاحِ القصّ.
     *
     * والرقمُ ليس اعتباطاً: `MAX_CONTEXT_CHARS` أربعةٌ وعشرون ألفَ حرف،
     * ودليلٌ يبتلع نصفَها يترك للبياناتِ نصفاً — فيُشترَط ألّا يتجاوز الربع.
     */
    public function test_الدليلُ_محدودُ_الحجمِ_رغم_اكتمالِه(): void
    {
        $ctx = AskContext::open();
        $ctx->addResult(AskTools::run('hub_modules', [], $this->asker));

        $chars = (int) $ctx->budget()['chars'];

        $this->assertGreaterThan(0, $chars);
        $this->assertLessThan((int) (AskContext::MAX_CONTEXT_CHARS / 4), $chars,
            'دليلُ الوحداتِ يبتلع ربعَ ميزانيّةِ السياقِ فأكثر — فلا يبقى للبيانات');
    }

    /** **ووصفُ الأدواتِ نفسُه محدود** — يُقاس ولا يُفترَض */
    public function test_وصفُ_الأدواتِ_يبقى_محدوداً_مع_خمسٍ_وثمانين_وحدة(): void
    {
        $json = json_encode(AskTools::schema(AskTools::catalog($this->asker)),
            JSON_UNESCAPED_UNICODE);

        $this->assertLessThan(8000, mb_strlen((string) $json),
            'وصفُ الأدواتِ تضخّم — يُرسَل في كلِّ خطوةٍ فثمنُه يتضاعف');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② العدُّ يُجمَع ولا يُعدّ صفّاً صفّاً
    // ═══════════════════════════════════════════════════════════════════

    /** **العدُّ في القاعدةِ لا في النموذج** — ولا صفَّ يُسلَّم */
    public function test_العدُّ_يُجمَع_في_القاعدةِ_بلا_تسليمِ_صفّ(): void
    {
        $r = AskTools::run('hub_count', ['module' => 'projects'], $this->asker);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(3, (int) $r['count'], 'العدُّ خرج عن نطاقِ شركةِ السائل');
        $this->assertSame([], $r['rows'], 'أداةُ العدِّ سلّمت صفوفاً — وهي لا تفعل');
    }

    /** **ولا يتسرّب عدُّ شركةٍ أخرى** — خمسةٌ في القاعدةِ وثلاثةٌ في نطاقِه */
    public function test_عدُّ_شركةٍ_أخرى_لا_يتسرّب(): void
    {
        $this->assertSame(5, Project::query()->count(), 'التهيئةُ نفسُها انحرفت');
        $this->assertSame(3, (int) AskTools::run('hub_count', ['module' => 'projects'],
            $this->asker)['count']);
    }

    /** **ونتيجةُ العدِّ لها مصدرٌ يُنسَب إليه** — فالرقمُ لا يُقال بلا سند */
    public function test_نتيجةُ_العدِّ_تُسجَّل_مصدراً(): void
    {
        $ctx = AskContext::open();
        $ctx->addResult(AskTools::run('hub_count', ['module' => 'projects'], $this->asker));

        $sources = $ctx->sources();
        $this->assertCount(1, $sources);
        $this->assertSame('hub_count', (string) $sources[0]['tool']);
        $this->assertSame('projects', (string) $sources[0]['module']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ③ التصنيفُ الجامعُ — ثلاثةُ أسبابٍ تحت اسمٍ واحد
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **سقفُ المخرَجِ ليس حدَّ سياقِ مدخل.**
     *
     * `finish_reason = length` تعني أنّ النموذجَ بلغ `max_tokens` **وهو سقفُ
     * ما يكتب**، لا سعةُ ما يقرأ. وقولُ «السؤالُ يحتاج بياناتٍ أكثرَ ممّا
     * يتّسع له السياق» عنها يُرسل المستخدمَ يضيّق سؤالَه **والسؤالُ سليم**.
     */
    public function test_بلوغُ_سقفِ_المخرَجِ_يُصنَّف_سقفَ_مخرَجٍ_لا_حدَّ_سياق(): void
    {
        $body = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $body['choices'][0]['finish_reason'] = 'length';
        $body['choices'][0]['message']['content'] = null;

        $r = $this->askWith([[$body, 200]]);

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::OUTPUT_LIMIT, (string) $r['failure'],
            'سقفُ المخرَجِ ما زال يُقال «حدَّ سياق»');
    }

    /** **وحدُّ سياقِ المدخلِ يبقى باسمِه** — ويأتي من البوّابةِ بـ٤٠٠ */
    public function test_تجاوزُ_سياقِ_المدخلِ_يبقى_حدَّ_سياق(): void
    {
        $r = $this->askWith([[LiteLlmFixtures::contextExceeded(), 400]]);

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::CONTEXT_LIMIT, (string) $r['failure']);
    }

    /** **والتفكيرُ وحدَه يبقى باسمِه أيضاً** */
    public function test_تفكيرٌ_بلا_جوابٍ_يبقى_تفكيراً_لا_حدَّ_سياق(): void
    {
        $body = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $body['choices'][0]['message']['content'] = null;
        $body['choices'][0]['message']['reasoning_content'] = 'فكّرتُ طويلاً ولم أكتب.';

        $r = $this->askWith([[$body, 200]]);

        $this->assertSame(AskFailures::MODEL_REASONED_ONLY, (string) $r['failure']);
    }

    /**
     * **وفشلُ سياجِ المظروفِ حالةُ أمنٍ لا حالةُ سعة.**
     *
     * `verify()` يعدّ السياجين والرقمَ السرّيّ: ظهورُه **ثالثةً** يعني أنّ
     * بياناتٍ حملته إلى داخلِ الحمولة — أي محاولةَ كسرِ السياج. وكانت تُسمّى
     * `CONTEXT_LIMIT` فتُخفي محاولةَ حقنٍ خلف رسالةِ «ضيِّق سؤالَك».
     *
     * **وما يُقاس هنا الحالةُ والتصنيف**، لا إثارتُها من الخارج: الرقمُ
     * يُولَّد لكلِّ طلبٍ عشوائيّاً فلا يُحقَن من واجهةٍ ولا من صفٍّ — وهذا
     * بعينُه ما يجعل السياجَ سياجاً.
     */
    public function test_تسرّبُ_رقمِ_السياجِ_يُصنَّف_سلامةَ_سياقٍ_لا_ضيقَه(): void
    {
        $ctx = AskContext::open();

        $this->assertTrue($ctx->verify($ctx->render()), 'مظروفٌ سليمٌ رُفض');

        // رقمُ السياجِ داخلَ الحمولةِ — ظهورٌ ثالثٌ يُسقط الفحص
        $ctx->trust('leak', 'حمولةٌ تحمل ' . $ctx->nonce() . ' في متنِها');

        $this->assertFalse($ctx->verify($ctx->render()),
            'رقمُ السياجِ ظهر ثالثةً ومرّ الفحص — فالسياجُ ليس سياجاً');

        $this->assertTrue(AskFailures::known(AskFailures::CONTEXT_INTEGRITY));
        $this->assertStringNotContainsString('ضيِّق السؤال',
            AskFailures::message(AskFailures::CONTEXT_INTEGRITY),
            'حالةُ أمنٍ تُقال للمستخدمِ «ضيِّق سؤالَك»');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ④ دليلُ التشخيصِ يُسجَّل على الإخفاقِ لا على النجاحِ وحدَه
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **إخفاقٌ يدّعي ضيقَ السياقِ ويسجّل سياقاً صفراً لا يُشخَّص.**
     *
     * والصفُّ في الأثرِ هو كلُّ ما يبقى بعد الطلب: إن قال `chars = 0` بينما
     * قُرئت صفوفٌ فعلاً، **فقد ضاع الدليلُ حيث يلزم أكثرَ ما يلزم**.
     */
    public function test_الإخفاقُ_بعد_قراءةٍ_يسجّل_حجمَ_السياقِ_الذي_قُرئ(): void
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
        $this->assertSame(AskFailures::OUTPUT_LIMIT, (string) $r['failure']);

        // **والدليلُ باقٍ بعد الإخفاق**: قراءةٌ وقعت ومصدرٌ سُجّل لها
        $this->assertGreaterThan(0, (int) ($r['budget']['results'] ?? 0),
            'أُخفق بعد قراءةٍ ولم يبقَ في الصفِّ أثرٌ لها — فلا يُشخَّص الإخفاق');
        $this->assertNotEmpty($r['sources'], 'المصادرُ ضاعت مع الإخفاق');
    }

    /**
     * **وصفوفُ البياناتِ تُسجَّل بحجمِها** — فطلبٌ قرأ ثمّ أخفق يقول كم قرأ.
     */
    public function test_الإخفاقُ_بعد_قراءةِ_صفوفٍ_يسجّل_محارفَها(): void
    {
        $bad = LiteLlmFixtures::answer('', LiteLlmFixtures::usage());
        $bad['choices'][0]['finish_reason'] = 'length';
        $bad['choices'][0]['message']['content'] = null;

        $r = $this->askWith([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [$bad, 200],
        ]);

        $this->assertFalse($r['ok']);
        $this->assertGreaterThan(0, (int) ($r['budget']['chars'] ?? 0),
            'قُرئت صفوفٌ ثمّ أُخفق، ولم يُسجَّل حجمُ ما قُرئ');
        $this->assertGreaterThan(0, (int) ($r['budget']['rows'] ?? 0));
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑤ التحيّةُ لا تُفسد التوجيه
    // ═══════════════════════════════════════════════════════════════════

    /** **صياغاتٌ مختلفةٌ لسؤالٍ واحد — والكتالوجُ واحدٌ في كلِّها** */
    public function test_التحيّةُ_وصياغةُ_السؤالِ_لا_تغيّران_ما_يُعرَض_للنموذج(): void
    {
        $seen = [];

        foreach (['كم مشروع لدينا؟', 'مرحبا كم مشروع لدينا',
                  'كم عدد المشاريع؟', 'how many projects do we have?'] as $q) {
            $gen = new ScriptedGenerator([['kind' => 'answer', 'answer' => 'لا شيء.', 'sources' => []]]);
            AskPipeline::ask($q, $this->asker, $gen);
            $seen[] = $gen->seenCatalogs[0] ?? [];
        }

        foreach ($seen as $i => $catalog) {
            $this->assertCount(85, $catalog, "الصياغةُ رقم {$i} غيّرت عددَ الوحداتِ المعروضة");
            $this->assertSame($seen[0], $catalog, "الصياغةُ رقم {$i} غيّرت الكتالوج");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑥ الحرّاسُ لم تُمَسّ
    // ═══════════════════════════════════════════════════════════════════

    /** **وحدةٌ غيرُ مصرَّحٍ بها لا تدخل الدليلَ ولا مفرداتِ الاختيار** */
    public function test_وحدةٌ_غيرُ_مصرَّحٍ_بها_لا_تدخل_الدليلَ_ولا_المفردات(): void
    {
        $narrow = $this->scopedUser('narrow@ask.local', [$this->alpha->id], ['projects']);

        $catalog = AskTools::catalog($narrow);
        $this->assertSame(['projects'], array_keys($catalog));

        $json = (string) json_encode(AskTools::schema($catalog), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('contracts', $json);

        $ctx = AskContext::open();
        $ctx->addResult(AskTools::run('hub_modules', [], $narrow));
        $this->assertStringNotContainsString('contracts', $ctx->render());
    }

    /** **وحارسُ التنفيذِ يبقى مستقلّاً** — يُعاد التحقّقُ ولو عُرضت الأداة */
    public function test_حارسُ_التنفيذِ_يردُّ_وحدةً_خارجَ_الصلاحيّةِ_ولو_طلبها_النموذج(): void
    {
        $narrow = $this->scopedUser('guard@ask.local', [$this->alpha->id], ['tasks']);

        $r = AskTools::run('hub_count', ['module' => 'projects'], $narrow);

        $this->assertFalse($r['ok'], 'نُفِّذ عدٌّ على وحدةٍ خارجَ صلاحيّةِ صاحبِ الجلسة');
        $this->assertNull($r['count']);
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

        return AskPipeline::ask($q, $this->asker, new \App\Support\LiteLlmAskGenerator());
    }

    private function scopedUser(string $email, array $companies, ?array $modules = null): User
    {
        $modules = $modules ?? array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies]);
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

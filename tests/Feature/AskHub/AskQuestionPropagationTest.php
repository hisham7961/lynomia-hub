<?php

namespace Tests\Feature\AskHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **أيصل سؤالُ المستخدمِ إلى النموذجِ أصلاً؟** (قبولُ الإنتاج · `21b7633f`)
 *
 * ── **البلاغُ الذي وُلدت منه هذه الحزمة** ──
 *
 * سُئل: «ما المشاريع المتاخر؟» فأجاب النموذجُ حرفاً:
 *
 * > «لم ترد في المظروف أي بيانات **أو سؤال محدد** لأجيب عنه.»
 *
 * **وكان صادقاً.** `AskPipeline::ask` تُطهّر السؤالَ في متغيّرٍ `$q` ثمّ
 * **لا تستعمله مرّةً أخرى**: مرجعانِ اثنان في الملفّ كلِّه — إسنادٌ وفحصُ
 * فراغ. والمظروفُ (`AskContext::render`) يحمل الحدودَ والوحداتِ والأدواتِ
 * والنتائج — **ولا يحمل السؤال**. ورسالةُ `user` الوحيدةُ المُرسَلةُ هي
 * المظروفُ نفسُه.
 *
 * فالنموذجُ كان يتلقّى تعليماتِ نظامٍ ومظروفَ بياناتٍ فارغاً، **ولا سؤال**.
 *
 * ── **ولمَ لم تكشفه خمسةُ آلافِ اختبار؟** ──
 *
 * `ScriptedGenerator` **يتجاهل المظروفَ كلَّه** ويعيد ما زُرع فيه،
 * و`Http::fake` تعيد لقطةً ثابتةً **مهما كانت الحمولة**. فلا اختبارَ واحدٌ
 * فتح `messages` وبحث فيه عن نصِّ السؤال. **حزمةٌ تقيس ما يعود ولا تقيس ما
 * يُرسَل** — وهذا صنفُ العمى الذي تحرسه هذه الحزمة.
 */
class AskQuestionPropagationTest extends TestCase
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
        Project::create(['name' => 'مشروعُ ألِف ٧٧١٢٣٤', 'company_id' => $this->alpha->id]);

        $this->asker = $this->scopedUser('propagation@ask.local');
        $this->ready();
        $this->intercept();
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① السؤالُ يصل — حرفاً، وفي رسالةٍ يفهمها النموذجُ سؤالاً
    // ═══════════════════════════════════════════════════════════════════

    /** **نصُّ السؤالِ العربيِّ يصل كما كُتب** — لا مطموساً ولا مُعاداً صياغته */
    public function test_السؤالُ_العربيُّ_يصل_إلى_أوّلِ_رسالةِ_مستخدم(): void
    {
        $q = 'ما المشاريع المتاخر؟';
        $this->script([[LiteLlmFixtures::answer('لا بيانات.', LiteLlmFixtures::usage()), 200]]);

        AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());

        $this->assertNotEmpty($this->sent, 'لم يُرسَل شيءٌ البتّة');
        $users = $this->userContents($this->sent[0]);

        $this->assertNotEmpty($users, 'لا رسالةَ `user` في الحمولة');
        $this->assertTrue(
            $this->anyContains($users, $q),
            '**سؤالُ المستخدمِ لا يصل النموذجَ** — وهو ما قاله النموذجُ حرفاً في الإنتاج'
        );
    }

    /** **والإنجليزيُّ كذلك** — فالعطبُ في البنيةِ لا في المحرف */
    public function test_السؤالُ_الإنجليزيُّ_يصل_كما_كُتب(): void
    {
        $q = 'which projects are overdue right now';
        $this->script([[LiteLlmFixtures::answer('لا بيانات.', LiteLlmFixtures::usage()), 200]]);

        AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());

        $this->assertTrue($this->anyContains($this->userContents($this->sent[0]), $q));
    }

    /**
     * **وأوّلُ دورةٍ بلا نتائجِ أدواتٍ — وهذا طبيعيّ.**
     *
     * فخلوُّ المظروفِ من نتائجَ في أوّلِ خطوةٍ **لا يعني خلوَّه من سؤال**،
     * ولا يعني «لا توجد بيانات». النموذجُ لم يطلب شيئاً بعد.
     */
    public function test_أوّلُ_دورةٍ_بلا_نتائجَ_وفيها_السؤالُ_كاملاً(): void
    {
        $q = 'ما المشاريع المتاخر؟';
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديك مشروعٌ واحدٌ [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());

        $first = $this->sent[0];
        $this->assertTrue($this->anyContains($this->userContents($first), $q),
            'أوّلُ دورةٍ بلا سؤال');
        $this->assertNotEmpty($first['tools'] ?? [],
            'أوّلُ دورةٍ بلا أدواتٍ مُعلَنة — فلا يملك النموذجُ ما يطلبه');
    }

    /** **ونتيجةُ الأداةِ تُضاف ولا تمحو السؤال** */
    public function test_الدورةُ_الثانيةُ_تحتفظ_بالسؤالِ_بعد_إضافةِ_النتيجة(): void
    {
        $q = 'ما المشاريع المتاخر؟';
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديك مشروعٌ واحدٌ [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());

        $this->assertCount(2, $this->sent, 'دورتانِ لا أكثر');
        $this->assertTrue($this->anyContains($this->userContents($this->sent[1]), $q),
            '**السؤالُ ضاع في الدورةِ الثانية** — فالنموذجُ يجيب عن لا شيء');

        // ونتيجةُ الأداةِ حاضرةٌ أيضاً — الإضافةُ لا الاستبدال
        $roles = array_column((array) $this->sent[1]['messages'], 'role');
        $this->assertContains('tool', $roles, 'نتيجةُ الأداةِ لم تُضَفّ');
        $this->assertContains('assistant', $roles, 'طلبُ الأداةِ لم يُثبَّت');
    }

    /** **والسؤالُ منفصلٌ عن المظروف** — بيانٌ لا يُخلَط ببيانات */
    public function test_السؤالُ_رسالةٌ_مستقلّةٌ_لا_مدفونٌ_في_المظروف(): void
    {
        $q = 'ما المشاريع المتاخر؟';
        $this->script([[LiteLlmFixtures::answer('لا بيانات.', LiteLlmFixtures::usage()), 200]]);

        AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());

        $carrier = null;
        foreach ((array) $this->sent[0]['messages'] as $m) {
            if (($m['role'] ?? '') === 'user' && str_contains((string) ($m['content'] ?? ''), $q)) {
                $carrier = (string) $m['content'];
                break;
            }
        }

        $this->assertNotNull($carrier, 'لا رسالةَ تحمل السؤال');
        $this->assertStringNotContainsString('HUB-CONTEXT', $carrier,
            '**السؤالُ دُفن داخلَ سياجِ البيانات** — فيُقرَأ معطًى لا سؤالاً');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② ولا جوابَ نهائيٌّ بلا قراءةٍ خادميّة (الدفاعُ في العمق)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **سؤالٌ عن بياناتِ Hub + صفرُ قراءاتٍ + جوابٌ «لا بيانات» ⟵ إخفاق.**
     *
     * «لم أقرأ شيئاً» ليست «لا توجد بيانات». والثانيةُ **حقيقةٌ عن الشركة**
     * لا تُقال إلّا بقراءةٍ نفّذها الخادمُ وعادت فارغة.
     */
    public function test_جوابٌ_بلا_قراءةٍ_واحدةٍ_لا_يمرّ(): void
    {
        $this->script([[LiteLlmFixtures::answer(
            'لا توجد بيانات أعمال متاحة في السياق الحالي.', LiteLlmFixtures::usage()), 200]]);

        $r = AskPipeline::ask('كم تطبيق لدينا ؟', $this->asker, new LiteLlmAskGenerator());

        $this->assertFalse((bool) $r['ok'],
            '**جوابُ «لا بيانات» مرّ بلا قراءةٍ خادميّةٍ واحدة**');
        $this->assertSame(AskFailures::NO_SERVER_READ, (string) $r['failure']);
        $this->assertSame([], $r['sources']);
    }

    /** **وقراءةٌ عادت فارغةً تُنتج «لا بيانات» صحيحة** */
    public function test_قراءةٌ_عادت_فارغةً_تُجيز_جوابَ_اللاشيء(): void
    {
        $empty = Company::create(['name_ar' => 'شركةٌ بلا مشاريع']);
        $lonely = $this->scopedUser('empty@ask.local', companies: [$empty->id]);

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لا مشاريعَ في نطاقِك [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        $r = AskPipeline::ask('كم مشروعاً لدينا؟', $lonely, new LiteLlmAskGenerator());

        $this->assertTrue((bool) $r['ok'], 'قراءةٌ صفريّةٌ مُثبَتةٌ رُفضت: ' . (string) $r['failure']);
        $this->assertNotEmpty($r['sources'], 'الجوابُ الصفريُّ بلا مصدرٍ يُثبِته');
    }

    /** **ومنعُ الصلاحيّةِ ليس «لا بيانات»** — فالتصنيفُ يُميّزهما */
    public function test_منعُ_الصلاحيّةِ_يُصنَّف_منعاً_لا_لاشيئاً(): void
    {
        $narrow = $this->scopedUser('narrow@ask.local', modules: ['tasks']);

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لا توجد مشاريع.', LiteLlmFixtures::usage()), 200],
        ]);

        $r = AskPipeline::ask('كم مشروعاً لدينا؟', $narrow, new LiteLlmAskGenerator());

        $this->assertFalse((bool) $r['ok'],
            'رُفضت القراءةُ صلاحيّةً ثمّ قيل «لا توجد مشاريع» — وهذا ادّعاءٌ لا حقيقة');
        $this->assertSame(AskFailures::NO_SERVER_READ, (string) $r['failure']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ③ ولا نصَّ سؤالٍ في أيِّ أثرٍ أو تليمتري
    // ═══════════════════════════════════════════════════════════════════

    public function test_نصُّ_السؤالِ_لا_يدخل_الأثرَ_ولا_التليمتري(): void
    {
        $needle = 'المتاخر٩٩٨٨٧٧';
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديك مشروعٌ واحدٌ [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask('ما ' . $needle . ' لدينا؟', $this->asker, new LiteLlmAskGenerator());

        /*
         * **وأسماءُ الوحداتِ تُسجَّل عمداً** (`AskAudit::normalize`): تصنيفٌ
         * يقول «أيَّ وحدةٍ مسّ الطلب» ولا يحمل صفّاً ولا قيمةَ حقل. والمحظورُ
         * **نصُّ السؤالِ ووسائطُ الأداة** — وهما ما يُقاس هنا.
         */
        foreach (['audits', 'ai_usage_events'] as $table) {
            $blob = (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($needle, $blob,
                "نصُّ السؤالِ دخل `{$table}`");
        }
    }

    // ── أدواتُ القياس ─────────────────────────────────────────────────

    /** @return list<string> محتوى كلِّ رسائلِ `user` في حمولةٍ واحدة */
    private function userContents(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['messages'] ?? []) as $m) {
            if (($m['role'] ?? '') === 'user') $out[] = (string) ($m['content'] ?? '');
        }

        return $out;
    }

    /** @param list<string> $haystacks */
    private function anyContains(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $h) {
            if (str_contains($h, $needle)) return true;
        }

        return false;
    }

    // ── أدواتُ التهيئة ────────────────────────────────────────────────

    private function script(array $steps): void
    {
        $this->sent  = [];
        $this->steps = $steps;
        $this->at    = 0;
    }

    private function intercept(): void
    {
        Http::fake(function ($req) {
            $this->sent[] = TestCase::sentBody($req);
            $step = $this->steps[min($this->at, max(0, count($this->steps) - 1))]
                ?? [LiteLlmFixtures::answer('لا شيء.'), 200];
            $this->at++;

            return Http::response($step[0], $step[1] ?? 200);
        });
    }

    private function scopedUser(string $email, ?array $modules = null, ?array $companies = null): User
    {
        $modules = $modules ?? array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies ?? [$this->alpha->id]]);
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
            'credential_name' => 'hub-prop-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'configured',
        ]);

        $model = AiModel::create([
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

        AiProfiles::attach(AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);
    }
}

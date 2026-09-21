<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Support\AiProfiles;
use App\Support\AiPurposes;
use App\Support\AiRouteRun;
use App\Support\AskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الملاءمةُ للغرضِ — البوّابةُ السادسة** (المرحلة ٥ · W2).
 *
 * ── **العطلُ الإنتاجيُّ الذي وُلدت منه هذه الحزمة** ──
 *
 * غرضُ `general` يشترط `chat` وحدَها. فنموذجٌ لا يُصدر طلباتِ أدواتٍ أصلاً
 * يجلس **شرعيّاً** على رأسِ سلسلةِ «اسأل Hub» — ومسارُ المساعدِ كلُّه دورةُ
 * أدوات. والمستخدمُ يرى «لا توجد بيانات» بينما التهيئةُ سليمةٌ بكلِّ فحصٍ
 * نملكه: النموذجُ مُفعَّلٌ، والمزوّدُ معتمَد، والسياسةُ تسمح، والرصيدُ يتّسع.
 *
 * **فبين «يقدر على التوليد» و«يصلح لهذا الغرض» فرقٌ لم يكن في المخطَّط.**
 *
 * ── **وما تحرسه هذه الحزمة بالضبط** ──
 *
 *  ① `false` يمنع — إعلانٌ صريحٌ بأنّ النشرَ لا يدعمها.
 *  ② `unknown` يمنع أيضاً — **fail-closed**، والصمتُ ليس موافقة.
 *  ③ **إلّا بدليلِ دفترٍ مقيس** — دورةٌ نفّذ فيها هذا النموذجُ أداةً فعلاً.
 *  ④ ودليلُ الدفترِ **لا ينقض `false`** — الإعلانُ الصريحُ أقوى من ملاحظة.
 *  ⑤ `reasoning = true` **ليست** عدمَ صلاحيّةٍ بذاتها.
 *  ⑥ **السلسلةُ تُصفّى لا البابُ وحدَه** — وإلّا وصل الاحتياطُ إلى غيرِ مُلائم.
 *  ⑦ **والسببُ يُقال** — «ينقصه `tools`» لا «لا غرضَ بسلسلةٍ جاهزة».
 */
class AiPurposeSuitabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        AiProfiles::seed();
    }

    // ── أدواتُ البناء ─────────────────────────────────────────────────

    private function provider(string $suffix = 'a'): AiProvider
    {
        return AiProvider::create([
            'catalog_key'      => 'openai',
            'label'            => 'مزوّدٌ وهميّ ' . $suffix,
            'enabled'          => true,
            'credential_name'  => 'hub-fake-' . $suffix . '-' . substr(sha1($suffix . microtime()), 0, 8),
            'credential_state' => 'configured',
        ]);
    }

    /** `null` في `$caps` تعني **مجهولةً** لا منفيّة */
    private function model(AiProvider $p, string $name, array $caps): AiModel
    {
        $facts = [];
        foreach ($caps as $k => $v) {
            $facts[$k] = ['v' => $v, 'src' => $v === null ? 'unknown' : 'litellm'];
        }

        return AiModel::create([
            'provider_id'        => $p->id,
            'litellm_model_name' => $name,
            'upstream_model'     => 'fake/' . $name,
            'display_name'       => $name,
            'enabled'            => true,
            'health'             => 'UNKNOWN',
            'capabilities'       => $facts,
            'limits'             => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params'             => [],
            'pricing'            => [
                'input_per_1k'  => ['v' => 0.001, 'src' => 'litellm'],
                'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                'currency'      => 'USD',
                'unit'          => 'per_1k_tokens',
            ],
        ]);
    }

    /** دورةٌ في الدفترِ نفّذ فيها النموذجُ أداةً — **الدليلُ المقيس** */
    private function toolTurn(AiModel $m): AiUsageEvent
    {
        return AiUsageEvent::create([
            'request_id'     => (string) \Illuminate\Support\Str::uuid(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'attempt'        => 1,
            'relation'       => 'initial',
            'model_id'       => $m->id,
            'provider_id'    => $m->provider_id,
            'feature'        => AiPurposes::ASK,
            'status'         => 'ok',
            'tool_requested' => 'hub_count',
            'started_at'     => now(),
            'settled_at'     => now(),
        ]);
    }

    private function profile(string $key): AiProfile
    {
        return AiProfile::query()->where('key', $key)->firstOrFail();
    }

    /**
     * **بابُ المساعدِ مفتوحٌ بكلِّ شرطٍ عدا السلسلة** — فيبقى وحدَه ما يُقاس.
     *
     * ولولا ذلك لَقالت `whyNot()` «لا صلاحيّة» في كلِّ حال، فيمرّ الاختبارُ
     * على رسالةٍ ليست موضوعَه — وهو صنفُ الخضرةِ الكاذبةِ الذي يُخفي العيب.
     */
    private function opensTheDoor(): void
    {
        \App\Support\Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        \App\Support\Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        \App\Support\Settings::put('ai.enabled', '1', 'test');
        \App\Support\Settings::put('ai.probe_ok', '1', 'test');
        \App\Support\Settings::put('ai.probe_fp', \App\Support\AiGateway::fingerprint(), 'test');
        \App\Support\Settings::put('ai.generation_ok', '1', 'test');
        \App\Support\Settings::put('ai.generation_fp', \App\Support\AiGateway::fingerprint(), 'test');
        \App\Support\FeatureRegistry::flush();

        $modules = array_keys((array) config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = \App\Models\Role::create([
            'name'   => 'سائلُ الملاءمة ' . \Illuminate\Support\Str::random(5),
            'scope'  => 'all',
            'flags'  => [AskPolicy::FLAG => 1],
            'matrix' => $matrix,
        ]);

        $this->actingAs(\App\Models\User::create([
            'name' => 'سائل', 'email' => 'fit-' . \Illuminate\Support\Str::random(6) . '@ask.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [],
        ]));
    }

    // ═══ ① · ② المنفيُّ يمنع، والمجهولُ يمنع مثلَه ═══

    public function test_قدرةُ_الأدواتِ_المنفيّةُ_تمنع_المساعدَ_بسببٍ_يُقال(): void
    {
        $m = $this->model($this->provider('deny'), 'no-tools',
            ['chat' => true, 'tools' => false]);

        $s = AiPurposes::suitability($m, AiPurposes::ASK);

        $this->assertFalse($s['ok'], '**نموذجٌ لا يُصدر طلبَ أداةٍ مرّ إلى مسارٍ كلُّه أدوات**');
        $this->assertSame(AiPurposes::UNFIT, $s['state']);
        $this->assertSame(['tools'], $s['missing'], 'السببُ لم يُسمِّ القدرةَ الناقصةَ بعينِها');
        $this->assertStringContainsString('tools', (string) $s['why']);
    }

    /**
     * **والمجهولُ يمنع كما يمنع المنفيّ** — وهذا لبُّ `Tri` في موضعِه.
     *
     * الخطرُ ليس أن يُرفَض نموذجٌ صالح، بل أن **يُقبَل** بلا دليلٍ فيُبنى
     * مسارُ المساعدِ كلُّه على قدرةٍ لم تُعلَن ولم تُلاحَظ.
     */
    public function test_قدرةُ_الأدواتِ_المجهولةُ_تمنع_ولا_تُرقّى_بالصمت(): void
    {
        $m = $this->model($this->provider('unk'), 'tools-unknown',
            ['chat' => true, 'tools' => null]);

        $s = AiPurposes::suitability($m, AiPurposes::ASK);

        $this->assertFalse($s['ok'], '**«لا نعرف» رُقّيت إلى «مدعومة» بالصمت**');
        $this->assertSame(AiPurposes::UNFIT, $s['state']);
        $this->assertSame(['tools'], $s['missing']);
    }

    /** وغيابُ المفتاحِ أصلاً = مجهولٌ لا مسموح */
    public function test_غيابُ_ذكرِ_القدرةِ_رأساً_يُعامَل_مجهولاً(): void
    {
        $m = $this->model($this->provider('abs'), 'no-mention', ['chat' => true]);

        $this->assertFalse(AiPurposes::suitability($m, AiPurposes::ASK)['ok']);
    }

    /** و`chat` مشروطةٌ أيضاً — بديهيّةٌ تُكتَب لأنّ ما لا يُكتَب لا يُفحَص */
    public function test_غيابُ_المحادثةِ_يمنع_ولو_كانت_الأدواتُ_مُعلَنة(): void
    {
        $m = $this->model($this->provider('nochat'), 'tools-only',
            ['chat' => false, 'tools' => true]);

        $s = AiPurposes::suitability($m, AiPurposes::ASK);

        $this->assertFalse($s['ok']);
        $this->assertSame(['chat'], $s['missing']);
    }

    // ═══ ③ · ④ دليلُ الدفترِ يعلو الصمتَ ولا يعلو النفيَ ═══

    /**
     * **دورةٌ ناجحةٌ نفّذ فيها النموذجُ أداةً تُثبِتها عملاً.**
     *
     * وهذا هو الفرقُ بين حارسٍ وبين قفلٍ يُطفئ ميزةً قائمة: بوّابةٌ لم تُعلن
     * `supports_function_calling` **لا تعني** نموذجاً لا يدعمها — وإن كان
     * دفترُنا يحمل طلبَ أداةٍ صدر عنه فعلاً، فالملاحظةُ أقوى من الإعلانِ
     * الغائب. وهي القاعدةُ نفسُها التي تجعل فاحصَ المستوى E يكتب `verified`.
     */
    public function test_دورةُ_أداةٍ_مسجّلةٌ_تُثبِت_القدرةَ_المجهولةَ_عملاً(): void
    {
        $m = $this->model($this->provider('obs'), 'proven-by-work',
            ['chat' => true, 'tools' => null]);

        $this->assertFalse(AiPurposes::suitability($m, AiPurposes::ASK)['ok'],
            'الحالةُ الأولى يجب أن تكون ممنوعةً وإلّا فالاختبارُ لا يقيس شيئاً');

        $this->toolTurn($m);

        $s = AiPurposes::suitability($m->fresh(), AiPurposes::ASK);
        $this->assertTrue($s['ok'], '**دليلُ الدفترِ المقيسُ أُهدر**');
        $this->assertSame(AiPurposes::OBSERVED, $s['state'],
            'الحالةُ لم تُفرّق المُعلَنَ من المُثبَتِ عملاً — والفرقُ هو ما يُقرأ في الشاشة');
    }

    /** **والملاحظةُ لا تنقض نفياً صريحاً** — الإعلانُ بأنّه لا يدعمها يُحتَرم */
    public function test_دليلُ_الدفترِ_لا_ينقض_نفياً_صريحاً(): void
    {
        $m = $this->model($this->provider('deny2'), 'declared-false',
            ['chat' => true, 'tools' => false]);

        $this->toolTurn($m);

        $this->assertFalse(AiPurposes::suitability($m->fresh(), AiPurposes::ASK)['ok'],
            '**ملاحظةٌ نقضت إعلاناً صريحاً بالمنع**');
    }

    /** وصفٌّ بلا اسمِ أداةٍ ليس دليلاً — `tool_requested = null` لا يُثبِت شيئاً */
    public function test_دورةٌ_بلا_طلبِ_أداةٍ_ليست_دليلاً(): void
    {
        $m = $this->model($this->provider('empty'), 'no-evidence',
            ['chat' => true, 'tools' => null]);

        AiUsageEvent::create([
            'request_id'     => (string) \Illuminate\Support\Str::uuid(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'attempt'        => 1,
            'relation'       => 'initial',
            'model_id'       => $m->id,
            'provider_id'    => $m->provider_id,
            'feature'        => AiPurposes::ASK,
            'status'         => 'ok',
            'tool_requested' => null,
            'started_at'     => now(),
            'settled_at'     => now(),
        ]);

        $this->assertFalse(AiPurposes::suitability($m->fresh(), AiPurposes::ASK)['ok']);
    }

    /** **ودليلُ نموذجٍ ليس دليلَ غيرِه** — الإثباتُ يخصّ صفّاً بعينِه */
    public function test_دليلُ_نموذجٍ_لا_يمتدّ_إلى_نموذجٍ_آخر(): void
    {
        $p       = $this->provider('sib');
        $proven  = $this->model($p, 'sibling-proven', ['chat' => true, 'tools' => null]);
        $other   = $this->model($p, 'sibling-silent', ['chat' => true, 'tools' => null]);

        $this->toolTurn($proven);

        $this->assertTrue(AiPurposes::suitability($proven->fresh(), AiPurposes::ASK)['ok']);
        $this->assertFalse(AiPurposes::suitability($other->fresh(), AiPurposes::ASK)['ok'],
            '**دليلُ نموذجٍ امتدّ إلى جارِه على المزوّدِ نفسِه**');
    }

    // ═══ ⑤ التفكيرُ ليس عدمَ صلاحيّة ═══

    /**
     * **`reasoning = true` وحدَها لا تُخرِج نموذجاً من المساعد.**
     *
     * والأمرُ صريحٌ في القرار: نموذجٌ يفكّر ويُصدر طلباتِ أدواتٍ **مُلائمٌ
     * تماماً**. وما أطفأ الشاشةَ في الإنتاج لم يكن التفكيرَ بل **سقفَ مخرَجٍ
     * يُستهلَك تفكيراً قبل أن يصل طلبُ الأداة** — وذاك حارسُ سقفٍ لا حارسُ
     * ملاءمة، ومكانُه `AskModelAdvisory` لا هنا.
     */
    public function test_التفكيرُ_وحدَه_لا_يجعل_النموذجَ_غيرَ_مُلائم(): void
    {
        $m = $this->model($this->provider('rsn'), 'thinks-and-calls',
            ['chat' => true, 'tools' => true, 'reasoning' => true]);

        $s = AiPurposes::suitability($m, AiPurposes::ASK);

        $this->assertTrue($s['ok'], '**نموذجٌ مفكّرٌ ويُصدر أدواتٍ حُجب لأنّه يفكّر**');
        $this->assertSame(AiPurposes::FIT, $s['state']);
    }

    /** وميزةٌ لم تُعلن حاجةً تمرّ — فلا يُقفَل سطحٌ لم يُطلَب منه شيء */
    public function test_ميزةٌ_بلا_حاجةٍ_مُعلَنةٍ_لا_تمنع_أحداً(): void
    {
        $m = $this->model($this->provider('free'), 'anything', ['chat' => true]);

        $this->assertSame([], AiPurposes::needs('feature-with-no-needs'));
        $this->assertTrue(AiPurposes::suitability($m, 'feature-with-no-needs')['ok']);
    }

    // ═══ ⑥ السلسلةُ تُصفّى لا البابُ وحدَه ═══

    public function test_السلسلةُ_تُصفّى_بالملاءمةِ_ويبقى_السلوكُ_القديمُ_بلا_ميزة(): void
    {
        $g   = $this->profile('general');
        $p   = $this->provider('ch');
        $bad = $this->model($p, 'chat-only', ['chat' => true]);
        $ok  = $this->model($p, 'chat-and-tools', ['chat' => true, 'tools' => true]);

        AiProfiles::attach($g, $bad);
        AiProfiles::attach($g, $ok);
        $g = $g->fresh();

        $this->assertCount(2, AiProfiles::chain($g),
            'السلسلةُ بلا ميزةٍ يجب أن تبقى كما كانت حرفاً — الإضافةُ لا الكسر');

        $ask = AiProfiles::chain($g, AiPurposes::ASK);
        $this->assertCount(1, $ask, '**السلسلةُ لم تُصفَّ بالملاءمة**');
        $this->assertSame('chat-and-tools', (string) $ask->first()->litellm_model_name);
    }

    /**
     * **والاحتياطُ لا يصل إلى غيرِ مُلائم.**
     *
     * وهذا هو الفرقُ بين فحصٍ عند البابِ وحارسٍ حقيقيّ: `AskPolicy::profile()`
     * يفحص، لكنّ `AiRouteRun` يمسك السلسلةَ كلَّها ويقفز فيها عند كلِّ إخفاق.
     * فلو بُنيت الرحلةُ على سلسلةٍ غيرِ مُصفّاةٍ لصار **الاحتياطُ بابَ التفافٍ
     * على الملاءمة** — يمرّ الأوّلُ بالفحصِ ثمّ يُسلَّم السؤالُ إلى الثاني.
     */
    public function test_رحلةُ_التوجيهِ_لا_تحتاط_إلى_نموذجٍ_غيرِ_مُلائم(): void
    {
        $g   = $this->fitThenUnfit();
        $run = AiRouteRun::for($g, ['feature' => AiPurposes::ASK]);

        $this->assertSame('primary', (string) $run->model()->litellm_model_name);

        // `404` ⟵ `model_gone`: احتياطٌ فوريٌّ بلا إعادةٍ — فالقفزةُ وحدَها تُقاس
        $d = $run->fail(['code' => 404]);

        $this->assertSame('stop', $d['action'], '**الاحتياطُ قفز إلى نموذجٍ لا يُصدر أدوات**');
        $this->assertTrue($run->closed());
        $this->assertStringContainsString('لا نموذجَ احتياطيَّ صالحٌ', (string) $run->why());
    }

    /**
     * **والسلوكُ القديمُ يبقى حرفاً لمن لم يطلب ميزة** — الإضافةُ لا الكسر.
     *
     * فالسلسلةُ نفسُها، والإخفاقُ نفسُه، بلا `feature` ⟵ احتياطٌ كما كان.
     */
    public function test_رحلةٌ_بلا_ميزةٍ_تحتاط_كما_كانت_تماماً(): void
    {
        $run = AiRouteRun::for($this->fitThenUnfit());

        $this->assertSame('fallback', $run->fail(['code' => 404])['action']);
        $this->assertSame('backup-unfit', (string) $run->model()?->litellm_model_name,
            '**عقدٌ قائمٌ كُسر — رحلةٌ بلا ميزةٍ تغيّر سلوكُها**');
    }

    /** أساسيٌّ مُلائمٌ يليه احتياطيٌّ لا يُصدر أدوات — على مزوّدين مختلفين */
    private function fitThenUnfit(): AiProfile
    {
        $g = $this->profile('general');

        AiProfiles::attach($g, $this->model($this->provider('r1'), 'primary',
            ['chat' => true, 'tools' => true]));
        AiProfiles::attach($g, $this->model($this->provider('r2'), 'backup-unfit',
            ['chat' => true]));

        return $g->fresh();
    }

    // ═══ ⑦ السببُ يُقال ═══

    public function test_المُستبعَدون_يُسمّون_القدرةَ_الناقصةَ_لا_سبباً_عامّاً(): void
    {
        $g = $this->profile('general');
        AiProfiles::attach($g, $this->model($this->provider('x1'), 'unfit-one', ['chat' => true]));
        $g = $g->fresh();

        $this->assertSame([], AiProfiles::excluded($g),
            'بلا ميزةٍ لا استبعادَ — فالنموذجُ صالحٌ للغرضِ العامّ');

        $out = AiProfiles::excluded($g, AiPurposes::ASK);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('tools', (string) $out[0]['why']);
        $this->assertStringContainsString('ينقص النموذجَ', (string) $out[0]['why']);
    }

    /**
     * **و«لا غرضَ بسلسلةٍ جاهزة» تشخيصٌ كاذبٌ حين تكون السلسلةُ مملوءة.**
     *
     * المديرُ يقرأ الرسالةَ فيذهب يربط نموذجاً بغرضٍ **وهو مربوطٌ أصلاً** —
     * ويعود بعد نصفِ ساعةٍ إلى العطلِ نفسِه. والصوابُ أن تُسمَّى القدرةُ.
     */
    public function test_رسالةُ_التعذّرِ_تفرّق_السلسلةَ_الفارغةَ_من_غيرِ_المُلائمة(): void
    {
        $this->opensTheDoor();

        $key = AskPolicy::profileKey();
        $g   = $this->profile($key);

        $empty = (string) AskPolicy::whyNot();
        $this->assertStringContainsString('لا غرضَ', $empty,
            'سلسلةٌ فارغةٌ فعلاً يجب أن تُقال كما هي');

        AiProfiles::attach($g, $this->model($this->provider('w1'), 'chat-no-tools', ['chat' => true]));

        $unfit = (string) AskPolicy::whyNot();
        $this->assertStringContainsString('لا تُلائم', $unfit,
            '**سلسلةٌ مملوءةٌ شُخّصت «فارغةً» — والمديرُ يُرسَل يربط ما هو مربوط**');
        $this->assertStringContainsString('tools', $unfit);
    }

    /** ونموذجٌ مُلائمٌ يفتح البابَ فعلاً — وإلّا فالحارسُ قفلٌ لا حارس */
    public function test_نموذجٌ_مُلائمٌ_يفتح_بابَ_المساعدِ(): void
    {
        $this->opensTheDoor();

        $g = $this->profile(AskPolicy::profileKey());
        AiProfiles::attach($g, $this->model($this->provider('w2'), 'fit-model',
            ['chat' => true, 'tools' => true]));

        $this->assertNotNull(AskPolicy::profile(), '**نموذجٌ مُلائمٌ حُجب**');
        $this->assertNull(AskPolicy::whyNot());
    }

    // ═══ الثابتُ المعماريّ — لا اسمَ مزوّدٍ ولا نموذجٍ في طبقةِ الملاءمة ═══

    /**
     * **الحاجاتُ بمفرداتِ `AiModelFacts` لا بأسماءِ نماذج.**
     *
     * ولو كُتب اسمُ نموذجٍ أو مزوّدٍ هنا لصار كلُّ نموذجٍ جديدٍ سطراً يُضاف —
     * وهو الدَّينُ الذي أُغلق في المرحلة ٢ بسجلٍّ ديناميّ.
     */
    public function test_طبقةُ_الملاءمةِ_بلا_اسمِ_مزوّدٍ_ولا_نموذج(): void
    {
        $src = (string) file_get_contents(app_path('Support/AiPurposes.php'));

        foreach (['openai', 'anthropic', 'gpt-', 'claude-', 'gemini', 'azure', 'mistral'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $src,
                '**اسمٌ مُثبَّتٌ في طبقةِ الملاءمة: ' . $needle . '**');
        }

        foreach (AiPurposes::needs(AiPurposes::ASK) as $cap) {
            $this->assertContains($cap, \App\Support\AiModelFacts::CAPABILITIES,
                'حاجةٌ خارجَ مفرداتِ القدراتِ المعروفة: ' . $cap);
        }
    }

    /** ووسمُ الشاشةِ موجودٌ لكلِّ حالةٍ — فلا حالةٌ تُعرَض بمفتاحِها الخام */
    public function test_لكلِّ_حالةِ_ملاءمةٍ_وسمٌ_عربيٌّ_للشاشة(): void
    {
        foreach ([AiPurposes::FIT, AiPurposes::OBSERVED, AiPurposes::UNFIT] as $state) {
            $this->assertArrayHasKey($state, AiPurposes::TAG);
            $this->assertNotSame('', trim((string) AiPurposes::TAG[$state]));
        }
    }
}

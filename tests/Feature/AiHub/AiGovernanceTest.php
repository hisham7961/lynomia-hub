<?php

namespace Tests\Feature\AiHub;

use App\Models\AiBudget;
use App\Models\AiModel;
use App\Models\AiPolicyRule;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Support\Ai\Governance\AiBudgets;
use App\Support\Ai\Governance\AiCost;
use App\Support\Ai\Governance\AiGovernance;
use App\Support\Ai\Governance\AiLedger;
use App\Support\Ai\Governance\AiPolicy;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **الحوكمةُ والمحاسبة** (المرحلة ٤ · P4-W1/W2/W3/W4/W5/W9).
 *
 * ── **الثابتُ الذي تحرسه هذه الحزمةُ قبل كلِّ شيء** ──
 *
 * ```
 * الوصولُ الفعليّ = تخويلُ Hub  ∩  سياسةُ الذكاء
 * ```
 *
 * وله وجهان يُختبَران كلاهما: **سياسةٌ تمنع تمنع** ولو كان بابُ Hub مفتوحاً،
 * **وسياسةٌ تسمح لا تمنح** لمن لا تخويلَ له — ولو كُتب له صفُّ «اسمح» صراحةً.
 *
 * ── **ولا نداءَ مزوّدٍ واحدٌ في هذه الحزمة** ──
 *
 * المحاكاةُ **تُسقط الاختبارَ** إن طُلب `/chat/completions` أو
 * `/health/test_connection`. فكلُّ رقمٍ هنا من لقطةٍ مكتوبةٍ لا من فاتورة.
 */
class AiGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-GOV4a1c8e2b55dd9911ff';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            if (str_contains($req->url(), '/chat/completions')
                || str_contains($req->url(), '/health/test_connection')) {
                $this->fail('**اتّصالٌ مدفوعٌ من مسارِ حوكمة**: ' . $req->url());
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    // ── أدواتٌ للتهيئة ─────────────────────────────────────────────────

    private function provider(): AiProvider
    {
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    /** نموذجٌ بسعرٍ معلومٍ ومصدرٍ مُثبَت — فالتقديرُ يُقاس ولا يُخمَّن */
    private function model(AiProvider $p, string $alias, bool $priced = true, bool $on = true): AiModel
    {
        return AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => $alias,
            'upstream_model' => 'fam/' . $alias, 'display_name' => $alias,
            'enabled' => $on, 'health' => 'OK',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [],
            'pricing' => $priced
                ? ['input_per_1k'  => ['v' => 0.001, 'src' => 'litellm'],
                   'output_per_1k' => ['v' => 0.002, 'src' => 'litellm']]
                : [],
        ]);
    }

    private function ctx(mixed $user = null, string $purpose = 'general'): array
    {
        $c = AiGovernance::context($user ?? $this->owner, $purpose, 'ask');
        $c['hub_allowed'] = true;

        return $c;
    }

    private function budget(array $attrs = []): AiBudget
    {
        return AiBudget::create(array_merge([
            'key' => 'b-' . \Illuminate\Support\Str::random(6), 'label' => 'ميزانيّةُ اختبار',
            'scope_type' => 'global', 'scope_id' => null, 'period' => 'monthly',
            'limit_micro' => 1_000_000, 'enforce' => true, 'enabled' => true,
        ], $attrs));
    }

    // ═══ ① التقاطع: صلاحيّةُ Hub ∩ سياسةُ الذكاء ═══

    /**
     * **سياسةٌ تسمح لا تمنح ما لا يملكه صاحبُه.**
     *
     * وهذا الوجهُ أخطرُ من أخيه: صفٌّ في جدولٍ يُدار من شاشةٍ **لا يجوز أن
     * يصير بابَ دخول**. والحارسُ في الترتيبِ لا في التوثيق: `evaluate()` تردّ
     * قبل أن تقرأ صفّاً واحداً.
     */
    public function test_السياسةُ_لا_تمنح_تخويلاً_لا_يملكه_المستخدم(): void
    {
        AiPolicyRule::create(['key' => 'open-all', 'label' => 'افتح كلَّ شيء',
            'effect' => AiPolicy::ALLOW, 'scope_type' => 'global',
            'allow_generation' => true, 'enabled' => true]);

        $v = AiPolicy::evaluate(['hub_allowed' => false, 'user' => $this->viewer]);

        $this->assertFalse($v['allowed']);
        $this->assertSame(AskFailures::UNAUTHORIZED, $v['code'],
            '**صفُّ «اسمح» منح تخويلاً** — والسياسةُ طبقةُ تضييقٍ لا بابُ دخول');
    }

    /** وسياسةٌ تمنع تمنع ولو كان بابُ Hub مفتوحاً */
    public function test_السياسةُ_تمنع_ولو_كان_التخويلُ_قائماً(): void
    {
        AiPolicyRule::create(['key' => 'no-ask', 'label' => 'أوقِف المساعد',
            'effect' => AiPolicy::DENY, 'scope_type' => 'global',
            'purpose' => 'general', 'enabled' => true, 'notes' => 'موقوفٌ حتّى المراجعة']);

        $v = AiPolicy::evaluate($this->ctx());

        $this->assertFalse($v['allowed']);
        $this->assertSame(AskFailures::POLICY_DENIED, $v['code']);
        $this->assertStringContainsString('المراجعة', (string) $v['why'],
            'سببُ الصفِّ كما كتبه المديرُ هو ما يُقال');
    }

    /** ونظامٌ بلا صفٍّ واحدٍ يعمل كما كان — **توافقٌ خلفيٌّ مُثبَتٌ لا موعود** */
    public function test_بلا_صفٍّ_واحدٍ_لا_تضييقَ_البتّة(): void
    {
        $v = AiPolicy::evaluate($this->ctx());

        $this->assertTrue($v['allowed']);
        $this->assertSame([], $v['matched']);
        $this->assertTrue($v['tools']);
    }

    // ═══ ② المنعُ بالأصلِ يُفعَّل بُعداً بُعداً ═══

    public function test_تسميةُ_نموذجٍ_في_«اسمح»_تجعل_البُعدَ_قائمةَ_سماح(): void
    {
        $p  = $this->provider();
        $ok = $this->model($p, 'hub-ok');
        $no = $this->model($p, 'hub-no');

        AiPolicyRule::create(['key' => 'only-ok', 'label' => 'النموذجُ المعتمَد',
            'effect' => AiPolicy::ALLOW, 'scope_type' => 'global',
            'model_id' => (string) $ok->id, 'enabled' => true]);

        $this->assertTrue(AiPolicy::allows($this->ctx() + ['model_id' => (string) $ok->id]));
        $this->assertFalse(AiPolicy::allows($this->ctx() + ['model_id' => (string) $no->id]),
            '**بُعدُ النماذجِ صار قائمةَ سماح** — وما ليس فيها ممنوع');
    }

    /** وبُعدٌ لم يُسمَّ لا يُضيَّق — فالتضييقُ لا يتسرّب من بُعدٍ إلى آخر */
    public function test_تسميةُ_نموذجٍ_لا_تُضيّق_بُعدَ_الأغراض(): void
    {
        $p  = $this->provider();
        $ok = $this->model($p, 'hub-ok');

        AiPolicyRule::create(['key' => 'only-ok2', 'label' => 'النموذجُ المعتمَد',
            'effect' => AiPolicy::ALLOW, 'scope_type' => 'global',
            'model_id' => (string) $ok->id, 'enabled' => true]);

        $this->assertTrue(AiPolicy::allows(
            $this->ctx(null, 'reasoning') + ['model_id' => (string) $ok->id]));
    }

    public function test_المنعُ_يغلب_السماحَ_مهما_كانت_الأولويّة(): void
    {
        AiPolicyRule::create(['key' => 'a', 'label' => 'اسمح', 'effect' => AiPolicy::ALLOW,
            'scope_type' => 'global', 'priority' => 1, 'enabled' => true]);
        AiPolicyRule::create(['key' => 'd', 'label' => 'امنع', 'effect' => AiPolicy::DENY,
            'scope_type' => 'global', 'priority' => 999, 'enabled' => true]);

        $this->assertFalse(AiPolicy::allows($this->ctx()));
    }

    public function test_صفٌّ_معطَّلٌ_لا_أثرَ_له(): void
    {
        AiPolicyRule::create(['key' => 'off', 'label' => 'امنع', 'effect' => AiPolicy::DENY,
            'scope_type' => 'global', 'enabled' => false]);

        $this->assertTrue(AiPolicy::allows($this->ctx()));
    }

    public function test_نطاقُ_المستخدمِ_لا_يمسُّ_غيرَه(): void
    {
        AiPolicyRule::create(['key' => 'no-emp', 'label' => 'امنع الموظّفة',
            'effect' => AiPolicy::DENY, 'scope_type' => 'user',
            'scope_id' => (string) $this->employee->id, 'enabled' => true]);

        $this->assertFalse(AiPolicy::allows($this->ctx($this->employee)));
        $this->assertTrue(AiPolicy::allows($this->ctx($this->owner)));
    }

    /** **وسقفُ المخرَجِ الأشدُّ يفوز** — والتضييقُ اتّجاهٌ واحد */
    public function test_سقفانِ_في_السياسةِ_يُنتجان_الأشدّ(): void
    {
        AiPolicyRule::create(['key' => 'c1', 'label' => 'سقفٌ فضفاض', 'effect' => AiPolicy::ALLOW,
            'scope_type' => 'global', 'max_output_tokens' => 900, 'enabled' => true]);
        AiPolicyRule::create(['key' => 'c2', 'label' => 'سقفٌ ضيّق', 'effect' => AiPolicy::ALLOW,
            'scope_type' => 'global', 'max_output_tokens' => 200, 'enabled' => true]);

        $this->assertSame(200, AiPolicy::evaluate($this->ctx())['limits']['max_output_tokens']);
    }

    public function test_منعُ_الأدواتِ_يُعلَن_ولا_يمنع_التوليد(): void
    {
        AiPolicyRule::create(['key' => 'no-tools', 'label' => 'بلا أدوات', 'effect' => AiPolicy::ALLOW,
            'scope_type' => 'global', 'allow_tools' => false, 'enabled' => true]);

        $v = AiPolicy::evaluate($this->ctx());

        $this->assertTrue($v['allowed']);
        $this->assertFalse($v['tools']);
    }

    // ═══ ③ منعُ التوليدِ بالأصلِ للنماذجِ غيرِ الصالحة ═══

    public function test_نموذجٌ_معطَّلٌ_لا_يُنادى_ولو_لم_تُكتَب_سياسة(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-off', true, false);

        $r = AiGovernance::admitModel($this->ctx(), $m);

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::MODEL_UNAVAILABLE, $r['code']);
    }

    public function test_نموذجٌ_مُزالٌ_من_المنبعِ_لا_يُنادى(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-gone');
        $m->forceFill(['health' => 'UNAVAILABLE'])->save();

        $this->assertFalse(AiGovernance::admitModel($this->ctx(), $m->fresh())['ok']);
    }

    // ═══ ④ الميزانيّة: حجزٌ ثمّ التزامٌ أو إفراج ═══

    public function test_الحجزُ_يُنقِص_المتاحَ_قبل_وقوعِ_النداء(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);

        $r = AiBudgets::reserve($this->ctx(), 400_000);

        $this->assertTrue($r['ok']);
        $s = AiBudgets::status($b->fresh());
        $this->assertSame(400_000, $s['reserved_micro']);
        $this->assertSame(0, $s['spent_micro'], 'الحجزُ ليس إنفاقاً');
        $this->assertSame(600_000, $s['available_micro']);
    }

    public function test_الالتزامُ_يُحوّل_الحجزَ_إلى_إنفاقٍ_فعليّ(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $r = AiBudgets::reserve($this->ctx(), 400_000);

        AiBudgets::commit($r['holds'], 250_000, 1200);

        $s = AiBudgets::status($b->fresh());
        $this->assertSame(0, $s['reserved_micro']);
        $this->assertSame(250_000, $s['spent_micro'], 'الفعليُّ يحلُّ محلَّ المقدَّرِ لا يُضاف إليه');
        $this->assertSame(1200, $s['tokens']);
        $this->assertSame(1, $s['requests']);
    }

    public function test_الإفراجُ_يردُّ_الحجزَ_وعدَّ_الطلبِ_معاً(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $r = AiBudgets::reserve($this->ctx(), 400_000);

        AiBudgets::releaseAll($r['holds']);

        $s = AiBudgets::status($b->fresh());
        $this->assertSame(0, $s['reserved_micro']);
        $this->assertSame(0, $s['spent_micro']);
        $this->assertSame(0, $s['requests'], 'نداءٌ لم يقع لا يُحسَب في الحصّة');
    }

    /** **والفاشلُ يلتزم لأنّه وقع** — والفشلُ ليس مجّانيّاً عند المزوّد */
    public function test_المحاولةُ_الفاشلةُ_تُحسَب_في_الحصّةِ_لا_تُفرَج(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $r = AiBudgets::reserve($this->ctx(), 400_000);

        AiBudgets::commit($r['holds'], null, 0);   // فشلٌ بلا كلفةٍ مُبلَّغة

        $s = AiBudgets::status($b->fresh());
        $this->assertSame(1, $s['requests']);
        $this->assertSame(1, $s['unknown_cost_events'],
            '**المجهولُ يُعَدّ ولا يُطوى في الصفر**');
        $this->assertSame(0, $s['spent_micro']);
    }

    public function test_تجاوزُ_سقفِ_المالِ_يُرَدّ_بتصنيفِ_ميزانيّة(): void
    {
        $this->budget(['limit_micro' => 100_000]);

        $r = AiBudgets::reserve($this->ctx(), 150_000);

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::BUDGET_EXCEEDED, $r['code']);
        $this->assertSame([], $r['holds']);
    }

    public function test_تجاوزُ_عددِ_الطلباتِ_يُرَدّ_بتصنيفِ_حصّة(): void
    {
        $this->budget(['limit_micro' => null, 'limit_requests' => 1]);

        $this->assertTrue(AiBudgets::reserve($this->ctx(), 1000)['ok']);

        $r = AiBudgets::reserve($this->ctx(), 1000);
        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::QUOTA_EXCEEDED, $r['code'],
            'سقفُ العددِ حصّةٌ لا ميزانيّةُ مال — والفرقُ يُقال للمستخدم');
    }

    /** **والمجهولُ لا يمرّ تحت سقفِ مالٍ مفروض** — قاعدةُ `Tri` ① في موضعِ المال */
    public function test_كلفةٌ_لا_تُقدَّر_لا_تمرّ_تحت_سقفٍ_مفروض(): void
    {
        $this->budget(['limit_micro' => 1_000_000]);

        $r = AiBudgets::reserve($this->ctx(), null);

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::BUDGET_EXCEEDED, $r['code']);
        $this->assertStringContainsString('لا تُقدَّر', (string) $r['why']);
    }

    /** وبلا سقفِ مالٍ لا شيءَ يُقاس — فيمرّ المجهولُ ويُعَدّ */
    public function test_كلفةٌ_لا_تُقدَّر_تمرّ_حيث_لا_سقفَ_مال(): void
    {
        $this->budget(['limit_micro' => null, 'limit_requests' => 10]);

        $this->assertTrue(AiBudgets::reserve($this->ctx(), null)['ok']);
    }

    /** **وميزانيّةٌ تراقب ولا تمنع** — مسارُ الهجرةِ الآمن */
    public function test_ميزانيّةٌ_بلا_فرضٍ_تقيس_ولا_تمنع(): void
    {
        $b = $this->budget(['limit_micro' => 10, 'enforce' => false]);

        $r = AiBudgets::reserve($this->ctx(), 900_000);

        $this->assertTrue($r['ok'], 'المراقبةُ لا تمنع');
        $this->assertSame(900_000, AiBudgets::status($b->fresh())['reserved_micro']);
    }

    /** **وميزانيّتان: رفضُ الثانيةِ يُفرج عن الأولى** فلا يبقى مالٌ محجوزٌ لطلبٍ لن يقع */
    public function test_رفضُ_ميزانيّةٍ_ثانيةٍ_يُفرج_عن_الأولى(): void
    {
        $wide   = $this->budget(['key' => 'wide', 'limit_micro' => 9_000_000]);
        $narrow = $this->budget(['key' => 'narrow', 'scope_type' => 'purpose',
            'scope_id' => 'general', 'limit_micro' => 10]);

        $r = AiBudgets::reserve($this->ctx(), 500_000);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, AiBudgets::status($wide->fresh())['reserved_micro'],
            '**حجزٌ يتيمٌ يخنق ميزانيّةً سليمةً بلا إنفاقٍ واحد**');
        $this->assertSame(0, AiBudgets::status($wide->fresh())['requests']);
        $this->assertSame(0, AiBudgets::status($narrow->fresh())['reserved_micro']);
    }

    // ═══ ⑤ الكلفة: أربعُ درجاتٍ ومجهولٌ ليس صفراً ═══

    public function test_الكلفةُ_المُبلَّغةُ_تسبق_الحساب(): void
    {
        $s = AiCost::settle(['cost' => 0.5, 'prompt' => 1000, 'completion' => 1000],
            ['input_per_1k' => ['v' => 99.0, 'src' => 'litellm']]);

        $this->assertSame(AiCost::REPORTED, $s['source']);
        $this->assertSame(500_000, $s['micro'], 'المُبلَّغةُ تُسجَّل كما وصلت ولا تُصحَّح');
    }

    public function test_بلا_كلفةٍ_مُبلَّغةٍ_تُحسَب_من_رموزٍ_حقيقيّة(): void
    {
        $s = AiCost::settle(['prompt' => 1000, 'completion' => 500],
            ['input_per_1k'  => ['v' => 0.001, 'src' => 'litellm'],
             'output_per_1k' => ['v' => 0.002, 'src' => 'litellm']]);

        $this->assertSame(AiCost::CALCULATED, $s['source']);
        $this->assertSame(2000, $s['micro']);   // 0.001 + 0.001 = 0.002 → 2000 ميكرو
    }

    public function test_بلا_سعرٍ_تبقى_مجهولةً_ولا_تصير_صفراً(): void
    {
        $s = AiCost::settle(['prompt' => 1000, 'completion' => 500], []);

        $this->assertSame(AiCost::UNKNOWN, $s['source']);
        $this->assertNull($s['micro'], '**صفرٌ هنا كذبٌ على الفاتورة**');
    }

    public function test_سعرٌ_مجهولُ_المصدرِ_لا_يُقاس_عليه(): void
    {
        $s = AiCost::settle(['prompt' => 1000],
            ['input_per_1k' => ['v' => 0.9, 'src' => 'unknown']]);

        $this->assertSame(AiCost::UNKNOWN, $s['source']);
        $this->assertNull($s['micro']);
    }

    public function test_الدرجةُ_الأضعفُ_تغلب_عند_الدمج(): void
    {
        $this->assertSame(AiCost::ESTIMATED,
            AiCost::weakest(AiCost::REPORTED, AiCost::ESTIMATED, AiCost::CALCULATED));
        $this->assertSame(AiCost::UNKNOWN, AiCost::weakest(AiCost::REPORTED, 'مخترَع'));
    }

    // ═══ ⑥ السجلّ: صفٌّ لكلِّ محاولةٍ وعلاقةٌ تُقرَأ ═══

    public function test_الإعادةُ_والاحتياطُ_صفّانِ_بطلبٍ_منطقيٍّ_واحد(): void
    {
        $p  = $this->provider();
        $m1 = $this->model($p, 'hub-1');
        $m2 = $this->model($p, 'hub-2');
        $c  = $this->ctx();

        $a = AiGovernance::admit($c, $m1, 1000, 100, ['attempt' => 1, 'relation' => 'initial']);
        AiGovernance::settleFailed($a['event'], $a['holds'],
            AskFailures::PROVIDER_FAILURE, 'transient', 503, 40);

        $b = AiGovernance::admit($c, $m2, 1000, 100,
            ['attempt' => 2, 'relation' => 'fallback', 'parent_id' => (string) $a['event']->id]);
        AiGovernance::settleOk($b['event'], $b['holds'],
            ['prompt' => 100, 'completion' => 50], (array) $m2->pricing, 60);

        $sum = AiLedger::summarize((string) $c['request_id']);

        $this->assertSame(2, $sum['attempts'], '**محاولتانِ أُنفقتا فتُريان محاولتين**');
        $this->assertSame(1, $sum['ok']);
        $this->assertSame(1, $sum['failed']);
        $this->assertSame((string) $a['event']->id, (string) $b['event']->fresh()->parent_id,
            'علاقةُ القفزةِ بما قبلها تُقرَأ لا تُخمَّن');
        $this->assertSame('fallback', (string) $b['event']->fresh()->relation);
    }

    /** **ومجموعٌ فيه مجهولٌ لا يُقال «مُبلَّغ»** ولا يُطوى المجهولُ في الصفر */
    public function test_مجموعُ_الطلبِ_يُعلن_مجهولَه_ولا_يبتلعه(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-x', false);   // بلا سعر
        $c = $this->ctx();

        $a = AiGovernance::admit($c, $m, null, 100);
        AiGovernance::settleOk($a['event'], $a['holds'], ['prompt' => 10, 'completion' => 5], [], 20);

        $sum = AiLedger::summarize((string) $c['request_id']);

        $this->assertNull($sum['cost_micro']);
        $this->assertSame(AiCost::UNKNOWN, $sum['cost_source']);
        $this->assertSame(1, $sum['unknown']);
    }

    public function test_السجلُّ_لا_يحمل_نصَّ_سؤالٍ_ولا_جواب(): void
    {
        $cols = array_keys(DB::getSchemaBuilder()->getColumnListing('ai_usage_events') === []
            ? [] : array_flip(DB::getSchemaBuilder()->getColumnListing('ai_usage_events')));

        foreach (['question', 'prompt', 'answer', 'response', 'body', 'content', 'messages'] as $bad) {
            $this->assertNotContains($bad, $cols,
                "**عمودُ نصٍّ في سجلِّ الحوكمة** — وهو يُقرَأ بصلاحيّةٍ غيرِ صلاحيّةِ السائل: {$bad}");
        }
    }

    // ═══ ⑦ انتهاءُ الحجزِ المعلّق ═══

    public function test_حجزٌ_معلّقٌ_فوق_عمرِه_يُفرَج_عنه(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $p = $this->provider();
        $m = $this->model($p, 'hub-hang');

        $a = AiGovernance::admit($this->ctx(), $m, 300_000, 100);
        $this->assertSame(300_000, AiBudgets::status($b->fresh())['reserved_micro']);

        // **المسارُ مات بين الحجزِ والالتزام** — ولولا المِقصِّ لَخُنقت الميزانيّةُ أبداً
        $a['event']->forceFill(['started_at' => now()->subHours(3)])->save();

        $this->assertSame(1, AiLedger::expireStale());
        $this->assertSame(0, AiBudgets::status($b->fresh())['reserved_micro']);
        $this->assertSame('expired', (string) $a['event']->fresh()->status);
    }

    // ═══ ⑧ الاستئجارُ والتزوير ═══

    /** **شركةٌ لا يملكها صاحبُ الطلبِ لا تُنسَب إليه عمليّتُه** */
    public function test_معرّفُ_شركةٍ_مخترَعٌ_لا_يُصدَّق(): void
    {
        $this->employee->forceFill([
            'companies' => ['company-mine'], 'company_id' => 'company-theirs'])->save();

        $this->assertSame('company-mine',
            AiGovernance::companyOf($this->employee->fresh()),
            '**عمودُ الشركةِ لا يتجاوز قائمةَ المسموحِ له**');
    }

    public function test_شركتانِ_بلا_ترجيحٍ_لا_تُنسَب_إحداهما_بالقرعة(): void
    {
        $this->employee->forceFill(['companies' => ['a', 'b'], 'company_id' => null])->save();

        $this->assertNull(AiGovernance::companyOf($this->employee->fresh()),
            'نسبةٌ مخترَعةٌ أسوأُ من لا نسبة');
    }

    /** **ولا مسارَ HTTP يكتب استهلاكاً** — فاختلاقُ كلفةٍ لا سطحَ له */
    public function test_لا_مسارَ_يقبل_استهلاكاً_من_العميل(): void
    {
        $names = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($r) => (string) $r->getName())
            ->filter(fn ($n) => $n !== '' && str_contains($n, 'usage'))
            ->values()->all();

        foreach ($names as $n) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($n);
            $this->assertSame(['GET', 'HEAD'], array_values(array_diff($route->methods(), ['HEAD'])) === ['GET']
                ? ['GET', 'HEAD'] : $route->methods(),
                "مسارُ استهلاكٍ يقبل كتابةً: {$n}");
        }
    }

    // ═══ ⑨ الأثر ═══

    public function test_منعُ_الحوكمةِ_يُسجَّل_في_الأثرِ_بلا_سرّ(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-audited');

        AiPolicyRule::create(['key' => 'stop', 'label' => 'إيقاف', 'effect' => AiPolicy::DENY,
            'scope_type' => 'global', 'enabled' => true]);

        $r = AiGovernance::admit($this->ctx(), $m, 1000, 100);
        $this->assertFalse($r['ok']);

        $rows = DB::table('audits')->where('module', AiUsageEvent::MODULE)->get();
        $this->assertGreaterThan(0, $rows->count(), '**منعٌ بلا أثرٍ شكوى لا دليل**');

        $blob = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::SECRET, (string) $blob);
    }

    /** وتعديلُ سياسةٍ قرارُ إنسانٍ فيُدقَّق — بخلافِ عدّادِ الفترة */
    public function test_السياسةُ_والميزانيّةُ_مُدقَّقتان_والعدّادُ_لا(): void
    {
        AiPolicyRule::create(['key' => 'audited', 'label' => 'صفٌّ مُدقَّق',
            'effect' => AiPolicy::ALLOW, 'scope_type' => 'global', 'enabled' => true]);
        $this->budget();

        $this->assertGreaterThan(0,
            DB::table('audits')->where('module', AiPolicyRule::MODULE)->count());
        $this->assertGreaterThan(0,
            DB::table('audits')->where('module', AiBudget::MODULE)->count());
        $this->assertSame(0,
            DB::table('audits')->where('module', \App\Models\AiBudgetPeriod::MODULE)->count(),
            'أثرٌ لكلِّ حجزٍ يُغرِق السجلَّ الذي بُني ليُقرَأ');
    }

    // ═══ ⑩ القبولُ الكامل: الترتيبُ لا يُعاد ترتيبُه ═══

    /** **سياسةٌ تمنع ⇒ لا حجزَ مالٍ ولا صفَّ سجلّ** */
    public function test_المنعُ_بالسياسةِ_لا_يحجز_مالاً_ولا_يفتح_صفّاً(): void
    {
        $b = $this->budget();
        $p = $this->provider();
        $m = $this->model($p, 'hub-denied');

        AiPolicyRule::create(['key' => 'nope', 'label' => 'امنع', 'effect' => AiPolicy::DENY,
            'scope_type' => 'global', 'enabled' => true]);

        $r = AiGovernance::admit($this->ctx(), $m, 500_000, 100);

        $this->assertFalse($r['ok']);
        $this->assertNull($r['event']);
        $this->assertSame(0, AiBudgetsStatusHelper::reserved($b),
            '**حُجز مالٌ لعمليّةٍ ممنوعة** — والترتيبُ هو ما يمنع ذلك');
        $this->assertSame(0, AiUsageEvent::query()->count());
    }

    public function test_القبولُ_يفتح_صفّاً_محجوزاً_ثمّ_يُغلَق_بنجاح(): void
    {
        $b = $this->budget();
        $p = $this->provider();
        $m = $this->model($p, 'hub-good');

        $r = AiGovernance::admit($this->ctx(), $m, 300_000, 100);

        $this->assertTrue($r['ok']);
        $this->assertSame('reserved', (string) $r['event']->status);

        AiGovernance::settleOk($r['event'], $r['holds'],
            ['prompt' => 100, 'completion' => 50, 'cost' => 0.25], (array) $m->pricing, 77);

        $e = $r['event']->fresh();
        $this->assertSame('ok', (string) $e->status);
        $this->assertSame(250_000, (int) $e->cost_micro);
        $this->assertSame(AiCost::REPORTED, (string) $e->cost_source);
        $this->assertSame(77, (int) $e->latency_ms);
        $this->assertSame(250_000, AiBudgets::status($b->fresh())['spent_micro']);
    }
}

/** قارئٌ صغيرٌ للحجز — يُبقي التأكيدَ مقروءاً في الاختبارِ أعلاه */
final class AiBudgetsStatusHelper
{
    public static function reserved(AiBudget $b): int
    {
        return (int) AiBudgets::status($b->fresh())['reserved_micro'];
    }
}

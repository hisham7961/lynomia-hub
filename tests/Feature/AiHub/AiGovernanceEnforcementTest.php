<?php

namespace Tests\Feature\AiHub;

use App\Models\AiBudget;
use App\Models\AiBudgetPeriod;
use App\Models\AiModel;
use App\Models\AiPolicyRule;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\AiChat;
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
 * **فرضُ الحوكمةِ لا إعلانُها** — تدقيقُ ما قبل المرحلة ٥.
 *
 * ── **ولمَ صنفٌ مستقلّ؟** ──
 *
 * لأنّ كلَّ ما فيه **عقدٌ كُتب في الوثيقةِ ولم يُفرَض في الشيفرة**. وصنفُ
 * الشاشاتِ القائمُ (`AiGovernanceScreenTest`) يبدأ كلَّ كتابةٍ بجلسةٍ
 * **مُصعَّدةٍ طازجة** — فهو يثبت أنّ المُصعَّدَ يمرّ، **ولا يثبت أنّ غيرَ
 * المُصعَّدِ يُردّ**. وهذا بالضبطِ ما نجا منه العيبُ الأوّلُ هنا.
 */
class AiGovernanceEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $asker;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ التدقيق']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① التصعيدُ على كتاباتِ الحوكمة — «الكتابةُ تلزمها الإدارةُ **والتصعيدُ**»
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **جلسةٌ باردةٌ لا تكتب سياسة.**
     *
     * والضررُ ملموس: صفُّ «اسمح» يُنشأ ليلاً ويُزال صباحاً، وهو التهديدُ
     * المُسمّى في نموذجِ تهديدِ المرحلة ٤ («رفعُ سقفٍ ليلاً وإعادتُه صباحاً»)
     * ومُعلَنٌ أنّ ما يمنعه `hub_require_stepup`.
     */
    public function test_إنشاءُ_سياسةٍ_بجلسةٍ_غيرِ_مُصعَّدةٍ_يُردّ_ولا_يكتب(): void
    {
        $this->actingAs($this->owner)->post(route('ai.policies.store'), [
            'key' => 'night-allow', 'label' => 'سماحٌ ليليّ',
            'effect' => 'allow', 'scope_type' => 'global',
        ])->assertRedirect();

        $this->assertSame(0, AiPolicyRule::query()->count(),
            'كُتبت سياسةُ سماحٍ بجلسةٍ لم تُثبِت هويّتَها من جديد');
    }

    public function test_تعطيلُ_سياسةٍ_بجلسةٍ_غيرِ_مُصعَّدةٍ_يُردّ(): void
    {
        $rule = AiPolicyRule::create(['key' => 'deny-all', 'label' => 'منعٌ شامل',
            'effect' => 'deny', 'scope_type' => 'global', 'priority' => 10, 'enabled' => true]);

        $this->actingAs($this->owner)
            ->post(route('ai.policies.toggle', $rule))->assertRedirect();

        $this->assertTrue((bool) $rule->fresh()->enabled,
            'أُطفئ حارسُ منعٍ بجلسةٍ لم تُثبِت هويّتَها من جديد');
    }

    public function test_إزالةُ_سياسةٍ_بجلسةٍ_غيرِ_مُصعَّدةٍ_يُردّ(): void
    {
        $rule = AiPolicyRule::create(['key' => 'deny-2', 'label' => 'منعٌ ثانٍ',
            'effect' => 'deny', 'scope_type' => 'global', 'priority' => 10, 'enabled' => true]);

        $this->actingAs($this->owner)
            ->delete(route('ai.policies.destroy', $rule))->assertRedirect();

        $this->assertNotNull(AiPolicyRule::query()->find($rule->id),
            'حُذف حارسُ منعٍ بجلسةٍ لم تُثبِت هويّتَها من جديد');
    }

    public function test_إنشاءُ_ميزانيّةٍ_بجلسةٍ_غيرِ_مُصعَّدةٍ_يُردّ_ولا_يكتب(): void
    {
        $this->actingAs($this->owner)->post(route('ai.budgets.store'), [
            'key' => 'wide', 'label' => 'سقفٌ واسع', 'scope_type' => 'global',
            'period' => 'monthly', 'limit_amount' => 9999, 'enforce' => 1,
        ])->assertRedirect();

        $this->assertSame(0, AiBudget::query()->count(),
            'كُتبت ميزانيّةٌ بجلسةٍ لم تُثبِت هويّتَها من جديد');
    }

    public function test_تعطيلُ_ميزانيّةٍ_مفروضةٍ_بجلسةٍ_غيرِ_مُصعَّدةٍ_يُردّ(): void
    {
        $b = $this->budget(['key' => 'cap', 'limit_requests' => 3]);

        $this->actingAs($this->owner)
            ->post(route('ai.budgets.toggle', $b))->assertRedirect();

        $this->assertTrue((bool) $b->fresh()->enabled,
            'أُطفئ سقفُ إنفاقٍ بجلسةٍ لم تُثبِت هويّتَها من جديد');
    }

    public function test_إزالةُ_ميزانيّةٍ_بجلسةٍ_غيرِ_مُصعَّدةٍ_يُردّ(): void
    {
        $b = $this->budget(['key' => 'cap-2', 'limit_requests' => 3]);

        $this->actingAs($this->owner)
            ->delete(route('ai.budgets.destroy', $b))->assertRedirect();

        $this->assertNotNull(AiBudget::query()->find($b->id),
            'حُذف سقفُ إنفاقٍ بجلسةٍ لم تُثبِت هويّتَها من جديد');
    }

    /** **والمُصعَّدُ يمرّ** — فالإصلاحُ حارسٌ لا سدٌّ في وجهِ المدير */
    public function test_المُصعَّدُ_يكتب_السياسةَ_والميزانيّةَ_كما_كان(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('ai.policies.store'), [
                'key' => 'ok-rule', 'label' => 'قاعدةٌ سليمة',
                'effect' => 'deny', 'scope_type' => 'global',
            ]);

        $this->assertSame(1, AiPolicyRule::query()->count());

        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('ai.budgets.store'), [
                'key' => 'ok-budget', 'label' => 'سقفٌ سليم', 'scope_type' => 'global',
                'period' => 'monthly', 'limit_amount' => 10, 'enforce' => 1,
            ]);

        $this->assertSame(1, AiBudget::query()->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② طلبٌ لم يغادر الخادمَ لا يُحاسَب — «الإفراجُ: لم يقع نداءٌ أصلاً»
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **عقدُ طبقةِ النقل: أغادر الطلبُ الخادمَ أم لا؟**
     *
     * ولا يكفي `cause = bad_request` للتفريق: ردُّ ٤٠٠ من البوّابةِ سببُه
     * `bad_request` أيضاً — **وقد وقع وأُنفق**. فالتفريقُ يُصرَّح به في
     * الطبقةِ التي تعرفه وحدَها.
     */
    public function test_رفضُ_ما_قبلَ_النداءِ_يُعلَن_أنّه_لم_يُرسَل(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        Settings::put('ai.enabled', '0', 'test');

        Http::fake(['*' => fn () => $this->fail('نداءٌ خرج رغم إطفاءِ البوّابة')]);

        $r = AiChat::complete(['model' => 'x', 'messages' => []], 64);

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['sent'], 'رفضٌ محلّيٌّ قبل فتحِ أيِّ مقبسٍ أُعلن «مُرسَلاً»');
    }

    public function test_وجهةٌ_يرفضها_حارسُ_الصادرِ_تُعلَن_أنّها_لم_تُرسَل(): void
    {
        Settings::put('ai.gateway_url', 'http://10.0.0.5:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        Settings::put('ai.enabled', '1', 'test');

        Http::fake(['*' => fn () => $this->fail('نداءٌ خرج إلى وجهةٍ مرفوضة')]);

        $r = AiChat::complete(['model' => 'x', 'messages' => []], 64);

        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::GATEWAY_FAILURE, $r['failure']);
        $this->assertFalse($r['sent']);
    }

    /** **وردُّ البوّابةِ مهما كان رمزُه نداءٌ وقع** — فلا يُفرَج عنه */
    public function test_ردٌّ_من_البوّابةِ_يُعلَن_مُرسَلاً_ولو_كان_رمزُه_٤٠٠(): void
    {
        $this->gatewayReady();
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $r = AiChat::complete(['model' => 'x', 'messages' => []], 64);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['sent'], 'ردٌّ وصل من البوّابةِ أُعلن «لم يُرسَل»');
    }

    /**
     * **وانقطاعُ النقلِ يُعَدُّ مُرسَلاً قصداً** — فالمهلةُ تعني أنّ الطلبَ
     * ربّما وصل وعُولج، **والإفراجُ عنه يكذب**. وهو نصُّ العقدِ المُجمَّد §٥.
     */
    public function test_انقطاعُ_النقلِ_يُعَدُّ_مُرسَلاً_لا_مُفرَجاً_عنه(): void
    {
        $this->gatewayReady();
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $r = AiChat::complete(['model' => 'x', 'messages' => []], 64);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['sent']);
    }

    /**
     * **والحصّةُ لا تُستنزَف بطلبٍ لم يغادر الخادم.**
     *
     * والسيناريو الحقيقيُّ بسيط: عنوانُ البوّابةِ يتغيّر إلى وجهةٍ يرفضها
     * حارسُ الصادر. فكلُّ سؤالٍ يُرَدّ محلّيّاً **بلا فلسٍ واحد**، ومع ذلك
     * يُحسَب طلباً على الحصّةِ ويُعَدّ حدثاً «مجهولَ الكلفة». وميزانيّةٌ
     * بحدِّ ثلاثةِ طلباتٍ تُستنزَف بثلاثةِ أخطاءِ تهيئة — ثمّ يُصَدّ السائلُ
     * الرابعُ بـ`QUOTA_EXCEEDED` عن عطلٍ ليس عطلَه.
     */
    public function test_طلبٌ_رُفض_قبل_مغادرةِ_الخادمِ_يُفرَج_عن_حجزِه_ولا_يُعَدّ(): void
    {
        $this->askReadyButUnreachable();

        $b = $this->budget(['key' => 'quota', 'limit_requests' => 3]);

        $r = AskPipeline::ask('كم مشروعاً لديّ؟', $this->asker, new LiteLlmAskGenerator());

        $this->assertFalse($r['ok'], 'وجهةٌ مرفوضةٌ أعطت جواباً!');

        $period = AiBudgetPeriod::query()->where('budget_id', $b->id)->first();
        $this->assertNotNull($period, 'لم يُفتَح صفُّ فترةٍ أصلاً — فالحجزُ لم يقع');

        $this->assertSame(0, (int) $period->requests,
            'طلبٌ لم يغادر الخادمَ استُنزف به عدّادُ الحصّة');
        $this->assertSame(0, (int) $period->reserved_micro,
            'بقي مالٌ محجوزاً لطلبٍ لم يقع');
        $this->assertSame(0, (int) $period->spent_micro,
            'سُجِّل إنفاقٌ لنداءٍ لم يحدث');
        $this->assertSame(0, (int) $period->unknown_cost_events,
            'عُدَّ حدثاً «مجهولَ الكلفة» وكلفتُه صفرٌ مُثبَتة');
    }

    /** **وصفُّ السجلِّ يقول «أُفرج عنه» لا «فشل»** — فالتصنيفُ يُقرأ لاحقاً */
    public function test_صفُّ_السجلِّ_لطلبٍ_لم_يُرسَل_يُوسَم_مُفرَجاً_عنه(): void
    {
        $this->askReadyButUnreachable();
        $this->budget(['key' => 'quota-2', 'limit_requests' => 3]);

        AskPipeline::ask('كم مشروعاً لديّ؟', $this->asker, new LiteLlmAskGenerator());

        $rows = AiUsageEvent::query()->orderBy('id')->get();
        $this->assertNotEmpty($rows, 'لا صفَّ سجلٍّ البتّة لمحاولةٍ حُجز لها');

        foreach ($rows as $row) {
            $this->assertSame('released', (string) $row->status,
                'صفُّ سجلٍّ لطلبٍ لم يغادر الخادمَ وُسم «' . $row->status . '»');
            $this->assertNull($row->cost_micro, 'كلفةٌ لنداءٍ لم يقع');
        }
    }

    // ── أدواتُ التهيئة ────────────────────────────────────────────────

    private function budget(array $over = []): AiBudget
    {
        return AiBudget::create(array_merge([
            'key' => 'b-' . Str::random(6), 'label' => 'سقفُ اختبار',
            'scope_type' => 'global', 'scope_id' => null, 'period' => 'monthly',
            'limit_micro' => null, 'limit_requests' => null, 'limit_tokens' => null,
            'currency' => 'USD', 'enforce' => true, 'enabled' => true,
        ], $over));
    }

    private function gatewayReady(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        Settings::put('ai.enabled', '1', 'test');
    }

    /**
     * **جاهزيّةٌ تامّةٌ على الورق، ووجهةٌ يرفضها حارسُ الصادرِ في الواقع.**
     *
     * وهي الحالُ التي تجعل `AskPolicy::ready` تمرّ (البصمةُ مطابقةٌ والأعلامُ
     * مرفوعة) ثمّ يُرَدّ النداءُ في `AiChat` قبل فتحِ أيِّ مقبس.
     */
    private function askReadyButUnreachable(): void
    {
        Settings::put('ai.gateway_url', 'http://10.0.0.5:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) {
            Settings::put($k, '1', 'test');
        }
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');

        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدُ التدقيق', 'enabled' => true,
            'credential_name' => 'hub-' . substr(sha1('audit' . microtime(true)), 0, 12),
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

        AiProfiles::attach(
            AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);

        $modules = array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(
            fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلُ التدقيق', 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        $this->asker = User::create(['name' => 'سائل', 'email' => 'audit@ask.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$this->alpha->id]]);

        Http::fake(['*' => fn ($req) => $this->fail('نداءٌ خرج إلى وجهةٍ مرفوضة: ' . $req->url())]);
    }
}

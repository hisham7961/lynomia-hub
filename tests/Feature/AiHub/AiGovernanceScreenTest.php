<?php

namespace Tests\Feature\AiHub;

use App\Models\AiBudget;
use App\Models\AiPolicyRule;
use App\Models\AiUsageEvent;
use App\Support\Ai\Governance\AiCost;
use App\Support\Ai\Governance\AiPolicy;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **شاشاتُ الحوكمةِ وأبوابُها** (المرحلة ٤ · P4-W7/W9).
 *
 * ── **والواجهةُ ليست طبقةَ الفرض** ──
 *
 * الحارسُ في الخادمِ قبل أن تُبنى صفحةٌ واحدة. فما يُختبَر هنا **أنّ نداءَ
 * المسارِ مباشرةً يصطدم بالحارسِ نفسِه** — لا أنّ زرّاً مخفيٌّ في قالب.
 */
class AiGovernanceScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => fn ($req) => str_contains($req->url(), '/chat/completions')
            ? $this->fail('اتّصالٌ مدفوعٌ من شاشة: ' . $req->url())
            : Http::response([], 200)]);
    }

    private function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    // ═══ ① الأبواب ═══

    public function test_قسما_الحوكمةِ_مُعلَنانِ_في_شريطِ_الأقسام(): void
    {
        $keys = array_column(\App\Support\Ai\Center\AiAccess::sections($this->owner), 'key');

        $this->assertContains('policies', $keys);
        $this->assertContains('budgets', $keys);
    }

    /** **كلُّ قسمٍ يُعرَض يُفتَح** — وقسمٌ يُعرَض ولا يُفتَح عيبٌ يُكشَف آليّاً */
    public function test_كلُّ_قسمٍ_مُعلَنٍ_للمالكِ_يُفتَح(): void
    {
        foreach (\App\Support\Ai\Center\AiAccess::sections($this->owner) as $s) {
            if (! $s['ok']) continue;

            $this->actingAs($this->owner)->get(route($s['route']))
                ->assertSuccessful();
        }
    }

    public function test_الموظّفةُ_لا_ترى_السياساتِ_ولا_الميزانيّات(): void
    {
        $this->actingAs($this->employee)->get(route('ai.policies.index'))->assertForbidden();
        $this->actingAs($this->employee)->get(route('ai.budgets.index'))->assertForbidden();
    }

    /** **والكتابةُ تلزمها الإدارةُ والتصعيدُ معاً** */
    public function test_كتابةُ_السياسةِ_تلزمها_الإدارة(): void
    {
        $this->actingAs($this->employee)->post(route('ai.policies.store'), [
            'key' => 'x', 'label' => 'س', 'effect' => 'deny', 'scope_type' => 'global',
        ])->assertForbidden();

        $this->assertSame(0, AiPolicyRule::query()->count());
    }

    public function test_كتابةُ_الميزانيّةِ_تلزمها_الإدارة(): void
    {
        $this->actingAs($this->employee)->post(route('ai.budgets.store'), [
            'key' => 'x', 'label' => 'م', 'scope_type' => 'global', 'period' => 'monthly',
            'limit_amount' => 1,
        ])->assertForbidden();

        $this->assertSame(0, AiBudget::query()->count());
    }

    // ═══ ② الكتابةُ الصحيحة ═══

    public function test_المالكُ_يضيف_سياسةً_من_الشاشة(): void
    {
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.policies.store'), [
                'key' => 'no-gen', 'label' => 'أوقِف التوليد', 'effect' => 'allow',
                'scope_type' => 'global', 'allow_generation' => '0', 'priority' => 10,
                'notes' => 'موقوفٌ حتّى المراجعة',
            ])->assertRedirect(route('ai.policies.index'));

        $r = AiPolicyRule::query()->where('key', 'no-gen')->first();
        $this->assertNotNull($r);
        $this->assertFalse((bool) $r->allow_generation);
        $this->assertTrue((bool) $r->enabled);
    }

    /** **نطاقٌ غيرُ عامٍّ بلا معرّفٍ يُرَدّ** — سياسةٌ تبدو مفروضةً وهي معطَّلة */
    public function test_نطاقٌ_غيرُ_عامٍّ_بلا_معرّفٍ_يُرَدّ(): void
    {
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.policies.store'), [
                'key' => 'orphan', 'label' => 'يتيم', 'effect' => 'deny',
                'scope_type' => 'company', 'scope_id' => '',
            ])->assertSessionHasErrors('scope_id');

        $this->assertSame(0, AiPolicyRule::query()->count());
    }

    /** **وميزانيّةٌ بلا سقفٍ واحدٍ لا تمنع شيئاً** — فتبدو حارساً وهي ليست كذلك */
    public function test_ميزانيّةٌ_بلا_سقفٍ_تُرَدّ(): void
    {
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.budgets.store'), [
                'key' => 'empty', 'label' => 'بلا سقف',
                'scope_type' => 'global', 'period' => 'monthly',
            ])->assertSessionHasErrors('limit_amount');

        $this->assertSame(0, AiBudget::query()->count());
    }

    /** **والمالُ يُحوَّل إلى ميكرو عند الحدّ** — فلا كسرٌ عائمٌ يبلغ العدّاد */
    public function test_سقفُ_المالِ_يُخزَّن_عدداً_صحيحاً_بالميكرو(): void
    {
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.budgets.store'), [
                'key' => 'm1', 'label' => 'شهريّة', 'scope_type' => 'global',
                'period' => 'monthly', 'limit_amount' => '12.3456', 'enforce' => '1',
            ])->assertRedirect(route('ai.budgets.index'));

        $b = AiBudget::query()->where('key', 'm1')->first();
        $this->assertSame(12_345_600, (int) $b->limit_micro);
        $this->assertIsInt($b->limit_micro);
        $this->assertSame(AiCost::CURRENCY, (string) $b->currency);
    }

    public function test_مفتاحٌ_مكرَّرٌ_يُرَدّ(): void
    {
        AiPolicyRule::create(['key' => 'dup', 'label' => 'أوّل', 'effect' => 'allow',
            'scope_type' => 'global', 'enabled' => true]);

        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.policies.store'), [
                'key' => 'dup', 'label' => 'ثانٍ', 'effect' => 'deny', 'scope_type' => 'global',
            ])->assertSessionHasErrors('key');

        $this->assertSame(1, AiPolicyRule::query()->count());
    }

    // ═══ ③ الشاشةُ تعرض ما بُني ═══

    public function test_شاشةُ_السياساتِ_تعرض_الصفوفَ_وسببَها(): void
    {
        AiPolicyRule::create(['key' => 'shown', 'label' => 'صفٌّ معروض',
            'effect' => AiPolicy::DENY, 'scope_type' => 'global', 'enabled' => true,
            'notes' => 'سببٌ مكتوبٌ يُقرَأ']);

        $this->actingAs($this->owner)->get(route('ai.policies.index'))
            ->assertSuccessful()
            ->assertSee('صفٌّ معروض')
            ->assertSee('shown')
            ->assertSee('سببٌ مكتوبٌ يُقرَأ');
    }

    public function test_شاشةُ_الميزانيّاتِ_تعرض_الحالَ_والمتبقّي(): void
    {
        AiBudget::create(['key' => 'seen', 'label' => 'ميزانيّةٌ معروضة',
            'scope_type' => 'global', 'period' => 'monthly',
            'limit_micro' => 2_000_000, 'enforce' => true, 'enabled' => true]);

        $this->actingAs($this->owner)->get(route('ai.budgets.index'))
            ->assertSuccessful()
            ->assertSee('ميزانيّةٌ معروضة')
            ->assertSee('2.0000');
    }

    /** **والمجهولُ يُعرَض «—» لا «٠٫٠٠»** — فصفرٌ هنا كذبٌ على الفاتورة */
    public function test_شاشةُ_الاستهلاكِ_تعرض_المجهولَ_شرطةً_لا_صفراً(): void
    {
        $html = $this->actingAs($this->owner)->get(route('ai.usage'))
            ->assertSuccessful()->getContent();

        $this->assertStringContainsString('لا قياسَ بعد', (string) $html,
            'بلا محاولةٍ واحدةٍ لا يُقال «صفرُ إنفاق»');
        $this->assertStringContainsString('المجهولُ ليس صفراً', (string) $html);
    }

    public function test_شاشةُ_الاستهلاكِ_تعرض_درجاتِ_المصدرِ_الأربع(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('ai.usage'))
            ->assertSuccessful()->getContent();

        foreach (AiCost::SOURCES as $src) {
            $this->assertStringContainsString($src, $html, "درجةُ المصدر {$src} غائبةٌ عن الشاشة");
        }
    }

    // ═══ ④ عزلُ الشركاتِ في أرقامِ المال ═══

    /**
     * **من له عزلُ شركاتٍ لا يرى مجموعاً عالميّاً.**
     *
     * وكشفُ مجموعِ غيرِه ليس تسريبَ صفٍّ بل **تسريبَ حجمِ نشاط** — وهو ما
     * يُبنى عليه استنتاجٌ تجاريٌّ كامل.
     */
    public function test_المعزولُ_بشركةٍ_لا_يرى_إنفاقَ_غيرِه(): void
    {
        // راقبٌ مُعزَلٌ بشركةٍ واحدة — ويملك بابَ التكاليف
        $role = \App\Models\Role::create(['name' => 'محاسبٌ معزول', 'scope' => 'all',
            'flags' => ['aiView' => 1, 'monitor' => 1], 'matrix' => []]);
        $u = \App\Models\User::create(['name' => 'محاسبة', 'email' => 'acc@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => ['co-mine']]);

        foreach ([['co-mine', 700_000], ['co-theirs', 4_000_000]] as [$co, $cost]) {
            AiUsageEvent::create([
                'request_id' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $co, 'status' => 'ok', 'model_name' => 'hub-' . $co,
                'cost_micro' => $cost, 'cost_source' => AiCost::REPORTED,
                'total_tokens' => 100, 'started_at' => now(), 'settled_at' => now(),
            ]);
        }

        $summary = $this->actingAs($u)->get(route('ai.usage'))->assertSuccessful();

        $html = (string) $summary->getContent();
        $this->assertStringContainsString('0.7000', $html, 'لم يرَ إنفاقَ شركتِه');
        $this->assertStringNotContainsString('4.7000', $html,
            '**رأى المجموعَ العالميَّ** — وهو حجمُ نشاطِ غيرِه');
        $this->assertStringNotContainsString('hub-co-theirs', $html);
    }

    // ═══ ⑤ الأثر ═══

    public function test_كتابةُ_السياسةِ_تدخل_الأثر(): void
    {
        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.policies.store'), [
                'key' => 'audited-ui', 'label' => 'مُدقَّقة', 'effect' => 'deny',
                'scope_type' => 'global',
            ])->assertRedirect();

        $this->assertGreaterThan(0,
            DB::table('audits')->where('module', AiPolicyRule::MODULE)->count());
    }

    public function test_تعطيلُ_الميزانيّةِ_وإزالتُها_يدخلان_الأثر(): void
    {
        $b = AiBudget::create(['key' => 'audit-b', 'label' => 'ميزانيّة',
            'scope_type' => 'global', 'period' => 'monthly',
            'limit_micro' => 1000, 'enforce' => true, 'enabled' => true]);

        $this->actingAs($this->owner)->stepped()
            ->post(route('ai.budgets.toggle', $b))->assertRedirect();
        $this->assertFalse((bool) $b->fresh()->enabled);

        $this->actingAs($this->owner)->stepped()
            ->delete(route('ai.budgets.destroy', $b))->assertRedirect(route('ai.budgets.index'));
        $this->assertSoftDeleted('ai_budgets', ['id' => $b->id]);

        $this->assertGreaterThan(1,
            DB::table('audits')->where('module', AiBudget::MODULE)->count());
    }

    // ═══ ⑥ ولا سرَّ في أيِّ شاشة ═══

    public function test_لا_سرَّ_ولا_مفتاحَ_في_شاشاتِ_الحوكمة(): void
    {
        foreach (['ai.policies.index', 'ai.budgets.index', 'ai.usage'] as $route) {
            $html = (string) $this->actingAs($this->owner)->get(route($route))
                ->assertSuccessful()->getContent();

            $this->assertStringNotContainsString('sk-admin-test-key-000111222333', $html,
                "مفتاحُ الإدارةِ ظهر في {$route}");
        }
    }
}

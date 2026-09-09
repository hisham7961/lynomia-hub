<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointDevice;
use App\Models\EndpointMdmConnection;
use App\Models\EndpointPolicy;
use App\Models\Role;
use App\Models\User;
use App\Models\VaultSecret;
use App\Support\Mdm\MdmActionResult;
use App\Support\Mdm\MdmProvider;
use App\Support\Mdm\NullMdmProvider;
use App\Support\MdmService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **التصحيح §8/§9 — USB observe-vs-enforce + طبقةُ تكامل MDM.**
 *
 * القاعدةُ الصلبة (C15): **لا حجبَ منفذٍ يُزعَم أبداً.** الافتراضُ «رصدٌ فقط»،
 * والمزوّدان الحقيقيّان (Intune/Jamf) جسرُهما الحيُّ مؤجَّلٌ فيبقيان رصداً فقط —
 * حتى لو طلبت السياسةُ الفرضَ. و«blocked» ليست حالةً في النموذج أصلاً. والسرُّ
 * يُخزَّن في الخزنة مشفَّراً، ولا يُكتب خاماً في صفّ الوصلة، ولا يُعرَض في الحالة.
 */
class EndpointMdmUsbEnforcementTest extends TestCase
{
    /** حسابُ عميلٍ صلب — نمطُ WorkOsEndpointProtocolTest::clientUser */
    protected function clientUser(): User
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all', 'flags' => [], 'matrix' => $full]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    /** سياسةٌ تطلب الفرضَ فعلاً (بإقرار MDM في النموذج) */
    protected function enforcingPolicy(?string $companyId = null): EndpointPolicy
    {
        $p = new EndpointPolicy(['name' => 'حجبٌ كامل', 'usb_mode' => 'block_all', 'enforce' => true, 'company_id' => $companyId]);
        $p->acknowledgeMdmRequirement()->save();

        return $p;
    }

    /** مزوّدٌ مزيّفٌ **قادرٌ على الفرض** — لاختبار مسار enforce بلا وصلٍ حيّ */
    protected function enforceCapableProvider(): MdmProvider
    {
        return new class implements MdmProvider {
            public function name(): string { return 'intune'; }
            public function isConfigured(): bool { return true; }
            public function canEnforce(): bool { return true; }
            public function applyUsbPolicy(EndpointDevice $device, string $usbMode): MdmActionResult
            {
                return MdmActionResult::synced('اختبار: نيّةٌ سُلِّمت');
            }
            public function health(): array { return ['status' => 'ok', 'detail' => 'اختبار']; }
        };
    }

    /* ───────── ① الصفريُّ رصدٌ فقط — لا حجبَ يُزعَم ───────── */

    public function test_the_default_provider_is_observe_only_and_never_claims_blocked(): void
    {
        $this->seedCore();
        $p = new NullMdmProvider();

        $this->assertFalse($p->isConfigured());
        $this->assertFalse($p->canEnforce());
        $res = $p->applyUsbPolicy(new EndpointDevice(['device_uuid' => 'x', 'os' => 'windows']), 'block_all');
        $this->assertSame(MdmActionResult::OBSERVE_ONLY, $res->status);
        $this->assertFalse($res->delivered());

        // «blocked» ليست حالةً في النموذج أصلاً — لا سبيلَ لادّعائها
        $this->assertNotContains('blocked', MdmActionResult::STATUSES);
    }

    /* ───────── ② enforce=true بلا مزوّدٍ قادرٍ = رصدٌ فقط (جوهرُ الصدق) ───────── */

    public function test_enforcing_policy_without_a_capable_provider_stays_observe_only(): void
    {
        $this->seedCore();
        $policy = $this->enforcingPolicy();

        // بلا وصلةٍ أصلاً: رصدٌ فقط رغم enforce=true
        $this->assertSame(MdmService::MODE_OBSERVE_ONLY,
            MdmService::effectiveUsbMode($policy, null));

        // وحتى بوصلةِ Intune مُفعَّلةٍ بمستأجرٍ وسرّ (الجسرُ الحيُّ مؤجَّل ⇒ canEnforce=false):
        // يبقى رصداً فقط — لا يُزعَم فرضٌ
        $vs = VaultSecret::create(['title' => 'سرّ', 'kind' => 'مفتاح', 'secret_cipher' => 'TENANT-SECRET']);
        $conn = EndpointMdmConnection::create(['provider' => 'intune', 'external_tenant' => 'contoso',
            'secret_id' => $vs->id, 'enabled' => true]);
        $this->assertSame(MdmService::MODE_OBSERVE_ONLY,
            MdmService::effectiveUsbMode($policy, $conn));
    }

    /* ───────── ③ مزوّدٌ قادرٌ (محقونٌ) + سياسةٌ enforce ⇒ enforce، synced لا blocked ───────── */

    public function test_a_capable_injected_provider_enables_enforce_and_returns_synced_never_blocked(): void
    {
        $this->seedCore();
        app()->instance(MdmProvider::class, $this->enforceCapableProvider());

        $policy = $this->enforcingPolicy();
        $conn = EndpointMdmConnection::create(['provider' => 'intune', 'enabled' => true]);

        $this->assertSame(MdmService::MODE_ENFORCE, MdmService::effectiveUsbMode($policy, $conn));

        $device = EndpointDevice::create(['device_uuid' => (string) Str::uuid(), 'os' => 'windows',
            'status' => 'active', 'policy_id' => $policy->id]);
        $res = MdmService::applyUsbPolicy($device, $policy);
        $this->assertSame(MdmActionResult::SYNCED, $res->status);
        $this->assertNotSame('blocked', $res->status);   // لا حجبَ يُزعَم أبداً
    }

    /* ───────── ④ الوصلةُ الخاصّة تسبق العامّة ───────── */

    public function test_company_connection_takes_precedence_over_global(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'ش']);
        $global = EndpointMdmConnection::create(['provider' => 'jamf', 'company_id' => null, 'enabled' => true]);
        $specific = EndpointMdmConnection::create(['provider' => 'intune', 'company_id' => $company->id, 'enabled' => true]);

        $this->assertSame($specific->id, MdmService::connectionFor((string) $company->id)?->id);
        $this->assertSame($global->id, MdmService::connectionFor(null)?->id);
    }

    /* ───────── ⑤ الويب: للمالك وحدَه، والعميلُ ٤٠٤ ───────── */

    public function test_mdm_admin_is_owner_only_and_client_gets_404(): void
    {
        $this->seedCore();

        $this->get(route('endpoints.mdm'))->assertRedirect();                    // ضيف
        $this->actingAs($this->employee)->get(route('endpoints.mdm'))->assertForbidden();
        $this->actingAs($this->clientUser())->get(route('endpoints.mdm'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('endpoints.mdm'))->assertOk()->assertSee('رصدٌ فقط');
    }

    /* ───────── ⑥ الإنشاء: السرُّ في الخزنة مشفَّراً — لا خامٌ في الوصلة ولا في الحالة ───────── */

    public function test_store_keeps_the_secret_in_the_vault_and_never_raw_on_the_connection(): void
    {
        $this->seedCore();

        // بلا تصعيد: يُحوَّل ولا صفَّ
        $this->actingAs($this->owner)
            ->post(route('endpoints.mdm.store'), ['provider' => 'intune', 'external_tenant' => 'contoso', 'secret' => 'TOP-SECRET-XYZ'])
            ->assertStatus(302);
        $this->assertSame(0, EndpointMdmConnection::count(), 'أُنشئت وصلةٌ بلا تصعيد');

        // مكتملٌ: الوصلةُ تُنشأ، والسرُّ يُخزَّن في الخزنة مشفَّراً، والوصلةُ تحمل المرجعَ فقط
        $this->actingAs($this->owner)->withStepup()
            ->post(route('endpoints.mdm.store'), ['provider' => 'intune', 'external_tenant' => 'contoso',
                'secret' => 'TOP-SECRET-XYZ', 'enabled' => '1'])
            ->assertRedirect();

        $conn = EndpointMdmConnection::firstOrFail();
        $this->assertSame('intune', $conn->provider);
        $this->assertNotNull($conn->secret_id, 'السرُّ لم يُخزَّن في الخزنة');

        // **لا سرَّ خامٌ في صفّ الوصلة** — أيُّ عمودٍ نصّيّ لا يحمل القيمة الخام
        $row = \Illuminate\Support\Facades\DB::table('endpoint_mdm_connections')->where('id', $conn->id)->first();
        foreach ((array) $row as $v) {
            if (is_string($v)) $this->assertStringNotContainsString('TOP-SECRET-XYZ', $v, 'سرٌّ خامٌ في صفّ الوصلة');
        }

        // والخزنةُ تحفظه مشفَّراً (لا نصّاً صريحاً في العمود الخام)
        $vsRaw = \Illuminate\Support\Facades\DB::table('vault_secrets')->where('id', $conn->secret_id)->value('secret_cipher');
        $this->assertStringNotContainsString('TOP-SECRET-XYZ', (string) $vsRaw, 'السرُّ غيرُ مشفَّرٍ في الخزنة');

        // وحالةُ الإدارة **بلا سرّ** — حضورٌ لا قيمة
        $status = MdmService::status($conn);
        $this->assertTrue($status['has_secret']);
        $this->assertArrayNotHasKey('secret', $status);
        $this->assertStringNotContainsString('TOP-SECRET-XYZ', json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /* ───────── ⑦ المزامنةُ صادقة: بلا مزوّدٍ قادرٍ لا «synced» ───────── */

    public function test_sync_is_honest_and_never_claims_synced_without_a_capable_provider(): void
    {
        $this->seedCore();
        $conn = EndpointMdmConnection::create(['provider' => 'jamf', 'external_tenant' => 'org', 'enabled' => true]);

        $res = MdmService::sync($conn);
        // اعتماداتٌ ناقصة (بلا سرّ) ⇒ not-configured؛ ومع سرٍّ لكن جسرٌ مؤجَّل ⇒ observe-only
        $this->assertContains($res->status, [MdmActionResult::NOT_CONFIGURED, MdmActionResult::OBSERVE_ONLY]);
        $this->assertNotSame(MdmActionResult::SYNCED, $res->status);
        $this->assertSame($res->status, $conn->fresh()->last_sync_status);
    }

    /* ───────── ⑧ صفحةُ الجهاز: enforce=true تُعرَض «رصدٌ فقط» صادقةً ───────── */

    public function test_device_page_shows_observe_only_even_when_policy_requests_enforce(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'ش']);
        $policy = $this->enforcingPolicy($company->id);
        $device = EndpointDevice::create(['device_uuid' => (string) Str::uuid(), 'company_id' => $company->id,
            'os' => 'windows', 'status' => 'active', 'hostname' => 'LT-MDM', 'policy_id' => $policy->id]);

        $this->actingAs($this->owner)->get(route('endpoints.show', $device->id))
            ->assertOk()
            ->assertSee('رصدٌ فقط')                       // الوضعُ الفعليُّ الصادق
            ->assertSee('لا مزوّدَ MDM قادرٌ');            // التصريحُ بأنّ الفرضَ المطلوبَ لم يتحقّق
    }
}

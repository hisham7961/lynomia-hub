<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointDevice;
use App\Models\EndpointRelease;
use App\Models\EnrollmentToken;
use App\Support\Endpoint;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **تصحيحُ أنظمةِ التشغيل المدعومة** (§1) — المصدرُ الواحد `App\Support\Endpoint`:
 * المدعومُ رسميّاً Windows/macOS فقط. تسجيلُ Linux **الجديد** يُرَدّ خادميّاً،
 * والصفوفُ القديمة تُصان وتُصنَّف «غير مدعومة». القائمةُ لا تتكرّر ولا تتباعد.
 */
class EndpointOsSupportTest extends TestCase
{
    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);
        $d = openssl_pkey_get_details($pk);

        return [$priv, $d['key']];
    }

    use \Tests\Concerns\EnrollsEndpoints;

    protected function mintFor(Company $c): string
    {
        $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), ['assetId' => $this->eligibleAsset($c)->id])->assertSessionHas('enroll_token');

        return (string) session('enroll_token');
    }

    protected function enrollAs(string $token, string $os)
    {
        [, $pub] = $this->keypair();

        return $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $token, 'device_uuid' => (string) Str::uuid(), 'hostname' => 'LT-OS-TEST',
            'os' => $os, 'agent_version' => '1.0.0', 'public_key' => $pub,
        ]);
    }

    /** المصدرُ الواحد: التسجيل/الإصدارات/الواجهة تقرأ Endpoint::SUPPORTED نفسَها */
    public function test_supported_os_list_is_single_sourced(): void
    {
        $this->assertSame(['windows', 'macos'], Endpoint::SUPPORTED);
        $this->assertSame(Endpoint::SUPPORTED, EndpointRelease::OSES, 'إصداراتُ الوكيل انحرفت عن المصدر الواحد');
        $osField = collect(config('hub.modules.endpoints.fields'))->firstWhere('key', 'os');
        $this->assertSame(Endpoint::SUPPORTED, $osField['options'] ?? null, 'خياراتُ الواجهة انحرفت عن المصدر الواحد');
        // عمودُ التخزين يبقى يسع القديم (linux) كي لا تُحذَف الصفوفُ التاريخيّة
        $this->assertContains('linux', EndpointDevice::OSES);
    }

    public function test_windows_enrollment_is_accepted(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $this->enrollAs($this->mintFor($c), 'windows')->assertCreated();
        $this->assertDatabaseHas('endpoint_devices', ['company_id' => $c->id, 'os' => 'windows']);
    }

    public function test_macos_enrollment_is_accepted(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $this->enrollAs($this->mintFor($c), 'macos')->assertCreated();
        $this->assertDatabaseHas('endpoint_devices', ['company_id' => $c->id, 'os' => 'macos']);
    }

    /** §1 — تسجيلُ Linux الجديد يُرَدّ ٤٢٢ صراحةً (لا يُعامَل مدعوماً صامتاً) */
    public function test_new_linux_enrollment_is_rejected(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $token = $this->mintFor($c);
        $this->enrollAs($token, 'linux')->assertStatus(422);
        // الرمزُ لم يُستهلك على رفضٍ بالتحقّق — يبقى صالحاً لجهازٍ مدعوم
        $this->assertDatabaseMissing('endpoint_devices', ['company_id' => $c->id, 'os' => 'linux']);
        $this->assertNull(EnrollmentToken::query()->whereNotNull('consumed_at')->first());
    }

    /** §17 — صفٌّ قديمٌ (linux) يبقى مقروءاً غيرَ محذوف، ويُصنَّف غيرَ مدعوم */
    public function test_legacy_linux_row_is_preserved_and_classified(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $legacy = EndpointDevice::create([
            'device_uuid' => (string) Str::uuid(), 'company_id' => $c->id,
            'hostname' => 'OLD-LINUX-01', 'os' => 'linux', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('endpoint_devices', ['id' => $legacy->id, 'os' => 'linux']);
        $this->assertFalse(Endpoint::isSupported('linux'));
        $this->assertTrue(Endpoint::isLegacy('linux'));
        $this->assertTrue(Endpoint::isSupported('windows'));
    }

    /** الواجهة تُظهر وسمَ «قديم — غير مدعوم» للجهاز القديم */
    public function test_endpoint_centre_flags_legacy_device(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $legacy = EndpointDevice::create([
            'device_uuid' => (string) Str::uuid(), 'company_id' => $c->id,
            'hostname' => 'OLD-LINUX-02', 'os' => 'linux', 'status' => 'active',
        ]);
        $this->actingAs($this->owner)->get(route('endpoints.show', $legacy->id))->assertOk()
            ->assertSee('غيرُ مدعوم');
    }
}

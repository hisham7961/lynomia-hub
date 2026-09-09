<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EndpointDevice;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\EnrollsEndpoints;
use Tests\TestCase;

/**
 * **التصحيح §15 — النقطةُ الطرفيّة في ملفّ الأصل/الموظف/المحطّة (360).**
 *
 * الجهازُ مربوطٌ بأصلٍ/حاملٍ/محطّةٍ منذ §2 — فيظهر في ملفّ كلٍّ منها بلا سجلٍّ
 * ثانٍ. **داخليٌّ حصراً:** العميلُ لا يراه، والقارئُ يمرّ بـ`hub_can('endpoints','v')`
 * وعزلِ الشركة، والهويّةُ التقنيّة خلف field-mode. صدقٌ: الغيابُ يُقال، ولا تسريبَ
 * لجهازٍ غيرِ مرتبط.
 */
class EndpointRelation360Test extends TestCase
{
    use EnrollsEndpoints;

    private function deviceFor(array $attrs): EndpointDevice
    {
        return EndpointDevice::create(array_merge([
            'device_uuid' => (string) Str::uuid(), 'os' => 'windows', 'status' => 'active',
            'pubkey_fp' => 'fp-' . Str::random(20),
        ], $attrs));
    }

    /** مستخدمٌ بكل الوحدات لكن **بلا** رؤيةِ endpoints — لاختبار حجب البطاقة بالصلاحية */
    private function noEndpointCapUser(): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $matrix['endpoints'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];   // لا رؤيةَ للنقاط الطرفية
        $role = Role::create(['name' => 'بلا نقاط ' . Str::random(4), 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'بلا نقاط', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function clientUser(): User
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'عميل ' . Str::random(4), 'scope' => 'all', 'flags' => [], 'matrix' => $full]);

        return User::create(['name' => 'عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    public function test_asset_360_shows_its_linked_device_and_never_an_unlinked_one(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'ش']);
        $asset = $this->eligibleAsset($company);
        $other = $this->eligibleAsset($company);

        $this->deviceFor(['asset_id' => $asset->id, 'company_id' => $company->id, 'hostname' => 'LT-360-ASSET']);
        $this->deviceFor(['asset_id' => $other->id, 'company_id' => $company->id, 'hostname' => 'LT-OTHER-DEV']);

        $res = $this->actingAs($this->owner)->get(route('m.show', ['assets', $asset->id]))->assertOk();
        $res->assertSee('النقاط الطرفيّة المرتبطة');
        $res->assertSee('LT-360-ASSET');
        $res->assertDontSee('LT-OTHER-DEV');   // لا تسريبَ لجهازِ أصلٍ آخر
    }

    public function test_station_and_employee_360_show_their_linked_devices(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'ش']);

        $station = Station::create(['name' => 'محطّةٌ', 'company_id' => $company->id]);
        $this->deviceFor(['station_id' => $station->id, 'company_id' => $company->id, 'hostname' => 'LT-360-STATION']);
        $this->actingAs($this->owner)->get(route('m.show', ['stations', $station->id]))
            ->assertOk()->assertSee('LT-360-STATION');

        $emp = Employee::create(['name' => 'موظفٌ', 'company_id' => $company->id, 'user_id' => (string) Str::uuid()]);
        $this->deviceFor(['employee_id' => $emp->user_id, 'company_id' => $company->id, 'hostname' => 'LT-360-HR']);
        $this->actingAs($this->owner)->get(route('m.show', ['hr', $emp->id]))
            ->assertOk()->assertSee('LT-360-HR');
    }

    public function test_a_user_without_endpoints_capability_does_not_see_the_card(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'ش']);
        $asset = $this->eligibleAsset($company);
        $this->deviceFor(['asset_id' => $asset->id, 'company_id' => $company->id, 'hostname' => 'LT-CAPTEST']);

        $res = $this->actingAs($this->noEndpointCapUser())->get(route('m.show', ['assets', $asset->id]));
        // يرى الأصلَ لكن **لا بطاقةَ نقاطٍ طرفية** (لا رؤيةَ لـendpoints)
        if ($res->status() === 200) {
            $res->assertDontSee('النقاط الطرفيّة المرتبطة');
            $res->assertDontSee('LT-CAPTEST');
        } else {
            $this->assertTrue(true);   // حُجب الوصولُ للوحدة أصلاً — لا تسريبَ بأي حال
        }
    }

    public function test_a_client_account_never_sees_the_endpoint_card(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'ش']);
        $asset = $this->eligibleAsset($company);
        $this->deviceFor(['asset_id' => $asset->id, 'company_id' => $company->id, 'hostname' => 'LT-CLIENTTEST']);

        $res = $this->actingAs($this->clientUser())->get(route('m.show', ['assets', $asset->id]));
        // العميلُ لا يبلغ الوحدةَ الداخليّة (٤٠٤/تحويل)، وبأيّ حالٍ لا يرى الجهاز
        $this->assertStringNotContainsString('LT-CLIENTTEST', (string) $res->getContent());
        $this->assertStringNotContainsString('النقاط الطرفيّة المرتبطة', (string) $res->getContent());
    }
}

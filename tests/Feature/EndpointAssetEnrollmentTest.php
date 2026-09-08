<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointDevice;
use App\Models\EnrollmentToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\EnrollsEndpoints;
use Tests\TestCase;

/**
 * **التصحيح §2 — التسجيلُ مربوطٌ بأصلٍ مملوكٍ للشركة، خادميّاً.**
 *
 * الجهازُ لا يختار شركتَه ولا أصلَه ولا حاملَه: الأصلُ المؤهّلُ يُختار عند السكّ
 * (بتصعيدٍ، مالك/مراقب)، فتُشتقّ منه الشركةُ والحاملُ والمحطّة. غيرُ المؤهّل
 * (شخصيّ/BYOD، منتهٍ، مفقود، عبرَ شركة) يُرَدّ fail-closed. المكرَّرُ النشطُ يُمنَع.
 */
class EndpointAssetEnrollmentTest extends TestCase
{
    use EnrollsEndpoints;

    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);

        return [$priv, openssl_pkey_get_details($pk)['key']];
    }

    /** سكٌّ (مالك + تصعيد) لأصلٍ — يعيد الاستجابة */
    protected function mint(string $assetId, array $over = [])
    {
        return $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), array_merge(['assetId' => $assetId], $over));
    }

    /** تسجيلٌ بحمولةٍ صالحة — يعيد الاستجابة */
    protected function enroll(string $token, array $over = [])
    {
        [, $pub] = $this->keypair();

        return $this->postJson('/api/v1/endpoint/enroll', array_merge([
            'token' => $token, 'device_uuid' => (string) Str::uuid(), 'hostname' => 'LT-ASSET',
            'os' => 'windows', 'agent_version' => '1.0.0', 'public_key' => $pub,
        ], $over));
    }

    /* ═══════════ المسارُ الصحيح ═══════════ */

    public function test_company_owned_asset_binds_authoritative_company_asset_holder_station(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $stationId = (string) Str::uuid();
        $asset = $this->eligibleAsset($c, $this->employee->id, ['station_id' => $stationId]);

        $this->mint($asset->id)->assertSessionHas('enroll_token');
        $this->enroll((string) session('enroll_token'))->assertCreated();

        $device = EndpointDevice::firstOrFail();
        $this->assertSame($c->id, $device->company_id, 'الشركةُ ليست من الأصل');
        $this->assertSame($asset->id, $device->asset_id, 'الأصلُ لم يُربَط');
        $this->assertSame($this->employee->id, $device->employee_id, 'الحاملُ ليس حاملَ الأصل');
        $this->assertSame($stationId, $device->station_id, 'المحطّةُ ليست محطّةَ الأصل');
    }

    /* ═══════════ الرفضُ fail-closed ═══════════ */

    public function test_personal_byod_asset_is_rejected(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $byod = $this->eligibleAsset($c, null, ['owner_scope' => \App\Models\Asset::OWNER_BYOD]);

        $this->mint($byod->id)->assertSessionHas('err')->assertSessionMissing('enroll_token');
        $this->assertSame(0, EnrollmentToken::count(), 'رمزٌ سُكّ لأصلٍ شخصيّ (BYOD)');
    }

    public function test_asset_from_another_company_is_not_found_for_scoped_admin(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        // مراقبٌ معزولٌ على (ب) لا يرى أصلَ (أ) — ٤٠٤ لا تسريب
        $role = Role::create(['name' => 'مراقب ب', 'scope' => 'own', 'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);
        $bUser = User::create(['name' => 'مراقب', 'email' => 'monb@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'companies' => [$b->id], 'password_changed_at' => now()]);

        $this->actingAs($bUser)->withStepup()
            ->post(route('enroll.mint'), ['assetId' => $this->eligibleAsset($a)->id])->assertNotFound();
        $this->assertSame(0, EnrollmentToken::count());
    }

    public function test_ineligible_status_assets_are_rejected(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        // منتهية/مفقودة/تالفة — كلُّها fail-closed (§2)
        foreach (['مستبعد', 'مباع', 'مُعاد للمورد', 'مفقود', 'تالف'] as $status) {
            $asset = $this->eligibleAsset($c, null, ['status' => $status]);
            $this->mint($asset->id)->assertSessionHas('err');
        }
        $this->assertSame(0, EnrollmentToken::count(), 'رمزٌ سُكّ لأصلٍ غيرِ مؤهّلِ الحالة');
    }

    public function test_employee_confirmation_mismatch_is_rejected(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $asset = $this->eligibleAsset($c, $this->employee->id);   // حاملُه الموظّف

        $this->mint($asset->id, ['employeeId' => (string) Str::uuid()])   // تأكيدٌ لا يطابق
            ->assertSessionHas('err')->assertSessionMissing('enroll_token');
        $this->assertSame(0, EnrollmentToken::count());
    }

    public function test_station_confirmation_mismatch_is_rejected(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $asset = $this->eligibleAsset($c, null, ['station_id' => (string) Str::uuid()]);

        $this->mint($asset->id, ['stationId' => (string) Str::uuid()])
            ->assertSessionHas('err')->assertSessionMissing('enroll_token');
        $this->assertSame(0, EnrollmentToken::count());
    }

    /* ═══════════ التكرارُ ورمزُ الاستخدام ═══════════ */

    public function test_duplicate_active_enrollment_is_prevented(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $asset = $this->eligibleAsset($c);

        // جهازٌ أوّلٌ نشط
        $this->mint($asset->id)->assertSessionHas('enroll_token');
        $this->enroll((string) session('enroll_token'))->assertCreated();

        // سكٌّ ثانٍ للأصلِ نفسِه بينما له جهازٌ نشط — يُرَدّ
        $this->mint($asset->id)->assertSessionHas('err')->assertSessionMissing('enroll_token');
        $this->assertSame(1, EndpointDevice::where('asset_id', $asset->id)->count());
    }

    public function test_consumed_token_cannot_enroll_twice(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $this->mint($this->eligibleAsset($c)->id)->assertSessionHas('enroll_token');
        $token = (string) session('enroll_token');

        $this->enroll($token)->assertCreated();
        $this->enroll($token)->assertStatus(409);   // استُهلك
        $this->assertSame(1, EndpointDevice::count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $this->mint($this->eligibleAsset($c)->id)->assertSessionHas('enroll_token');
        $token = (string) session('enroll_token');
        EnrollmentToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->enroll($token)->assertStatus(401);
        $this->assertSame(0, EndpointDevice::count());
    }

    /* ═══════════ الاستبدالُ الآمن (يحفظ التاريخ) ═══════════ */

    public function test_replacement_after_retire_preserves_history(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $asset = $this->eligibleAsset($c);

        $this->mint($asset->id);
        $this->enroll((string) session('enroll_token'))->assertCreated();
        $old = EndpointDevice::firstOrFail();
        $old->update(['status' => 'retired']);   // تقاعُدُ الجهاز القديم (لا حذف)

        // بعد التقاعُد: أصلٌ بلا جهازٍ نشط ⇒ يُقبَل بديلٌ جديد
        $this->mint($asset->id)->assertSessionHas('enroll_token');
        $this->enroll((string) session('enroll_token'))->assertCreated();

        // الجهازان موجودان (التاريخُ محفوظ)، والنشطُ واحد
        $this->assertSame(2, EndpointDevice::where('asset_id', $asset->id)->count());
        $this->assertSame(1, EndpointDevice::where('asset_id', $asset->id)->where('status', 'active')->count());
    }
}

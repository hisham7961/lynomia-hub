<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointCommand;
use App\Models\EndpointDevice;
use App\Models\EndpointEvent;
use App\Support\Custody;
use Illuminate\Support\Str;
use Tests\Concerns\EnrollsEndpoints;
use Tests\TestCase;

/**
 * **التصحيح §3 — اتساقُ دورةِ حياةِ الجهاز مع حالةِ الأصل.**
 *
 * الأصلُ يبلغ حالةً **نهائيّة** (مستبعد/مباع/مُعاد) ⇒ يُعلَّق جهازُه (إدارةٌ تنتهي،
 * أوامرُ تُلغى) بتدقيقٍ. المفتوحةُ (مفقود/تالف) تبقيه نشطاً وتُنبّه (كي يُقفَل/يُعزَل).
 * كلُّه عبر `Custody::transition` القائمة — لا حذفَ، ولا إعادةَ كتابةِ أحداث.
 */
class EndpointLifecycleTest extends TestCase
{
    use EnrollsEndpoints;

    private function deviceOn(\App\Models\Asset $asset, string $status = 'active'): EndpointDevice
    {
        return EndpointDevice::create([
            'device_uuid' => (string) Str::uuid(), 'company_id' => $asset->company_id,
            'asset_id' => $asset->id, 'hostname' => 'LT-LIFE', 'os' => 'windows', 'status' => $status,
        ]);
    }

    public function test_terminal_asset_status_suspends_active_device_and_audits(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        foreach (['مستبعد', 'مباع', 'مُعاد للمورد'] as $terminal) {
            $asset = $this->eligibleAsset(Company::create(['name_ar' => 'ش']));
            $device = $this->deviceOn($asset);

            Custody::transition($asset, $terminal, now()->toDateString());

            $this->assertSame('suspended', $device->fresh()->status, "لم يُعلَّق الجهازُ على {$terminal}");
        }
        $this->assertDatabaseHas('audits', ['action' => 'تعليقُ إدارةِ نقطةٍ طرفية — حالةُ الأصلِ النهائيّة']);
    }

    public function test_open_risky_status_lost_keeps_device_active_and_flags_attention(): void
    {
        $this->seedCore();
        $asset = $this->eligibleAsset(Company::create(['name_ar' => 'ش']));
        $device = $this->deviceOn($asset);

        $this->actingAs($this->owner);
        Custody::transition($asset, 'مفقود', now()->toDateString());

        $this->assertSame('active', $device->fresh()->status, 'المفقودُ عُلِّق — يجب أن يبقى نشطاً للقفل/العزل');
        // المركزُ يُنبّه على حالة الأصل
        $this->get(route('endpoints.show', $device->id))->assertOk()->assertSee('الأصلُ المرتبط');
    }

    public function test_suspended_device_rejects_new_commands(): void
    {
        $this->seedCore();
        $asset = $this->eligibleAsset(Company::create(['name_ar' => 'ش']));
        $device = $this->deviceOn($asset);
        $this->actingAs($this->owner);
        Custody::transition($asset, 'مستبعد', now()->toDateString());   // ⇒ suspended

        $this->postJson(route('endpoints.command', $device->id), ['type' => 'refresh_posture'])
            ->assertStatus(409);
        $this->assertSame(0, EndpointCommand::count(), 'أمرٌ خُزِّن لجهازٍ معلَّق');
    }

    public function test_lost_active_device_still_accepts_a_command(): void
    {
        $this->seedCore();
        $asset = $this->eligibleAsset(Company::create(['name_ar' => 'ش']));
        $device = $this->deviceOn($asset);
        $this->actingAs($this->owner);
        Custody::transition($asset, 'مفقود', now()->toDateString());   // يبقى نشطاً

        $this->postJson(route('endpoints.command', $device->id), ['type' => 'refresh_posture'])
            ->assertSuccessful();
        $this->assertSame(1, EndpointCommand::count());
    }

    public function test_transition_does_not_rewrite_historical_events(): void
    {
        $this->seedCore();
        $asset = $this->eligibleAsset(Company::create(['name_ar' => 'ش']));
        $device = $this->deviceOn($asset);
        EndpointEvent::create(['device_id' => $device->id, 'company_id' => $asset->company_id,
            'kind' => 'usb', 'severity' => 'info', 'summary' => 'حدثٌ تاريخيّ', 'nonce' => Str::random(12)]);

        $this->actingAs($this->owner);
        Custody::transition($asset, 'مستبعد', now()->toDateString());

        $this->assertSame(1, EndpointEvent::count(), 'حدثٌ تاريخيٌّ حُذف/أُعيد كتابتُه');
        $this->assertDatabaseHas('endpoint_events', ['device_id' => $device->id, 'summary' => 'حدثٌ تاريخيّ']);
    }

    public function test_asset_without_device_transition_is_noop(): void
    {
        $this->seedCore();
        $asset = $this->eligibleAsset(Company::create(['name_ar' => 'ش']));   // بلا جهاز
        $this->actingAs($this->owner);

        Custody::transition($asset, 'مستبعد', now()->toDateString());   // لا خطأ
        $this->assertSame(0, EndpointDevice::count());
    }
}

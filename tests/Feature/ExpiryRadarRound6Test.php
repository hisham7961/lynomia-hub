<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Domain;
use App\Models\Event;
use App\Models\Subscription;
use Tests\TestCase;

/**
 * جولة تدقيق ٦ — إحياء رادار الانتهاءات:
 *  1) راية 'expiry' المعلنة في السجل كانت لا يقرؤها أي سطر — الصيانة القادمة
 *     ومواعيد متابعة العملاء ومراجعات الحوادث كلها خارج الرادار رغم وسمها.
 *  2) حقول «تنبيه قبل (يوم)» كانت تُعرض ولا تُقرأ — النافذة 30 يوماً ثابتة
 *     للجميع، فعتبة «90» المكتوبة في واجهة الاشتراكات مستحيلة بنيوياً.
 *  3) الراية false تُخرج ما طابق الأنماط لفظاً لا معنى («تاريخ النهاية» لمعرض).
 */
class ExpiryRadarRound6Test extends TestCase
{
    protected function radarKeys(): array
    {
        return collect(hub_expiry(true, $this->owner))
            ->map(fn ($e) => $e['module'] . ':' . $e['name'])->all();
    }

    public function test_declared_expiry_flag_brings_maintenance_into_radar(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $asset = Asset::create(['name' => 'خادم الإنتاج']);
        AssetMaintenance::create(['asset_id' => $asset->id, 'title' => 'صيانة دورية مجدولة',
            'date' => now()->toDateString(), 'next' => now()->addDays(10)->toDateString()]);

        $this->assertContains('assetlog:صيانة دورية مجدولة', $this->radarKeys(),
            'الصيانة القادمة موسومة expiry=true في السجل — يجب أن تدخل الرادار');
    }

    public function test_negative_expiry_flag_silences_event_end_date(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Event::create(['title' => 'معرض جيتكس', 'date_end' => now()->addDays(5)->toDateString()]);

        $this->assertNotContains('events:معرض جيتكس', $this->radarKeys(),
            'نهاية معرضٍ ليست انتهاء رخصة — الراية false تُخرجها من الرادار');
    }

    public function test_domain_alert_threshold_extends_its_radar_window(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Domain::create(['name' => 'wide-alert.com', 'expiry' => now()->addDays(45)->toDateString(), 'alert' => 60]);
        Domain::create(['name' => 'default-window.com', 'expiry' => now()->addDays(45)->toDateString()]);

        $keys = $this->radarKeys();
        $this->assertContains('domains:wide-alert.com', $keys,
            'دومين عتبته 60 يوماً وينتهي بعد 45 يجب أن ينبّه الآن');
        $this->assertNotContains('domains:default-window.com', $keys,
            'بلا عتبة تبقى النافذة الافتراضية 30 يوماً');
    }

    public function test_subscription_alerts_list_is_honored(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Subscription::create(['service' => 'اشتراك مبكر التنبيه', 'renew' => now()->addDays(80)->toDateString(),
            'alerts' => '90,60,30']);
        Subscription::create(['service' => 'اشتراك عادي', 'renew' => now()->addDays(80)->toDateString()]);

        $keys = $this->radarKeys();
        $this->assertContains('subs:اشتراك مبكر التنبيه', $keys,
            'قائمة «90,60,30» تعني التنبيه قبل 90 يوماً — كانت حبراً على واجهة');
        $this->assertNotContains('subs:اشتراك عادي', $keys);
    }
}

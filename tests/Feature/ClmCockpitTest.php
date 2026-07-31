<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\ContractSigner;
use App\Models\SignRequest;
use Tests\TestCase;

/** CLM م8 (v2.124): قمرة /legal + أتمتة الانتهاء والتجديد + قسم العقود في التقرير */
class ClmCockpitTest extends TestCase
{
    /** القمرة بأرقام حقيقية: عالقة بتذكير، موافقات، التزامات، خط تجديد، عملات */
    public function test_cockpit_shows_real_sections(): void
    {
        $this->seedCore();
        Contract::create(['title' => 'ساري بقيمة', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 9000, 'currency' => 'د.ك', 'owner_id' => $this->employee->id]);
        ContractObligation::create(['title' => 'التزام مستحق', 'due' => now()->addDays(5)->toDateString(), 'status' => 'قائم']);

        // طلب عالق أسبوعاً
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'وثيقة عالقة', 'free_body' => 'نص', 'pass' => 'Pass1234',
        ]);
        SignRequest::where('title', 'وثيقة عالقة')->firstOrFail()
            ->forceFill(['sent_at' => now()->subDays(9)])->saveQuietly();

        $this->actingAs($this->owner)->get('/legal')->assertOk()
            ->assertSee('قيمة الساري بالعملة')->assertSee('9,000')
            ->assertSee('عالقة بلا توقيع')->assertSee('وثيقة عالقة')->assertSee('تذكير')
            ->assertSee('التزام مستحق')->assertSee('خط التجديد')
            ->assertSee('الساري بالمسؤول')->assertSee('موظفة');
    }

    /** بلا بيانات: رسائل صادقة لا أرقام مزعومة */
    public function test_cockpit_empty_states_are_honest(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/legal')->assertOk()
            ->assertSee('لا توجد بيانات مقارنة')
            ->assertSee('لا وثائق عالقة')
            ->assertSee('لا تجديدات جارية');
    }

    /** الانتهاء التلقائي معطل افتراضياً — وبتفعيله ينقلب الساري المتجاوز ويطلق contract.expired */
    public function test_auto_expire_gated_behind_setting(): void
    {
        $this->seedCore();
        $captured = [];
        \App\Support\HubEvents::listen(function ($event) use (&$captured) { $captured[] = $event; });
        $c = Contract::create(['title' => 'متجاوز نهايته', 'type' => 'عقد عميل', 'status' => 'ساري',
            'date_end' => now()->subDays(3)->toDateString()]);

        // الافتراضي: لا انقلاب — القرار للمنشأة
        $this->artisan('hub:automation')->assertSuccessful();
        $this->assertSame('ساري', $c->fresh()->status);

        $this->hubSetting('contracts.auto_expire', '1');
        $this->artisan('hub:automation')->assertSuccessful();
        $this->assertSame('منتهي', $c->fresh()->status);
        $this->assertContains('contract.expired', $captured);
    }

    /** مسودة التجديد المبكرة: تجديد تلقائي + notice → مسودة قبل النهاية، idempotent */
    public function test_auto_renewal_draft_within_notice_window(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'يتجدد تلقائياً', 'type' => 'عقد عميل', 'status' => 'ساري',
            'renewal' => 'تلقائي', 'notice' => 30, 'date_end' => now()->addDays(10)->toDateString()]);
        // خارج النافذة: إشعار ٥ أيام ونهايته بعد ١٠ — لا مسودة
        $far = Contract::create(['title' => 'بعيد النهاية', 'type' => 'عقد عميل', 'status' => 'ساري',
            'renewal' => 'تلقائي', 'notice' => 5, 'date_end' => now()->addDays(60)->toDateString()]);

        $this->artisan('hub:automation')->assertSuccessful();
        $this->assertSame(1, Contract::where('parent_id', $c->id)->where('kind', 'تجديد')->count());
        $this->assertSame(0, Contract::where('parent_id', $far->id)->count());
        $this->assertSame('قيد التجديد', $c->fresh()->status);

        // تشغيل ثانٍ: لا تكرار
        $this->artisan('hub:automation')->assertSuccessful();
        $this->assertSame(1, Contract::where('parent_id', $c->id)->where('kind', 'تجديد')->count());
    }

    /** تفعيل قاعدة تنبيه مبذورة بنقرة — ولا تفعيل آلياً ولا لغير المحرر */
    public function test_enable_dormant_alert_rule(): void
    {
        $this->seedCore();
        $this->artisan('hub:alerts-starter')->assertSuccessful();
        $rule = AlertRule::where('mod', 'contracts')->where('status', '!=', 'مفعّلة')->firstOrFail();

        $this->actingAs($this->owner)->get('/legal')->assertSee('قواعد تنبيه جاهزة معطلة');
        $this->actingAs($this->viewer)->post("/legal/rules/{$rule->id}/enable")->assertForbidden();
        $this->assertNotSame('مفعّلة', $rule->fresh()->status);

        $this->actingAs($this->owner)->post("/legal/rules/{$rule->id}/enable")->assertRedirect();
        $this->assertSame('مفعّلة', $rule->fresh()->status);
    }

    /** قسم العقود في التقرير التنفيذي — يظهر بأرقام حقيقية ويغيب عند الصفر */
    public function test_digest_contracts_section(): void
    {
        $this->seedCore();
        $this->artisan('hub:digest --dry')->assertSuccessful()
            ->expectsOutputToContain('تقريرك التنفيذي');

        Contract::create(['title' => 'سارٍ للتقرير', 'type' => 'عقد عميل', 'status' => 'ساري']);
        $this->artisan('hub:digest --dry')->expectsOutputToContain('📜 العقود: 1 سارٍ');
    }
}

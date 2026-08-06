<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PricingPlan;
use App\Models\Quote;
use App\Models\Service;
use Tests\TestCase;

/**
 * المرحلة ٥ — الإيراد الحقيقي: كان `hub_service_costs` يقيس هامشاً **نظرياً**
 * من السعر المُعلن في بطاقة الخدمة لا من عقدٍ مُوقَّع، وMRR غير قابل للقياس
 * أصلاً في نظامٍ يبيع اشتراكات. مرجع الخدمة على العقود يفتح القياس.
 */
class MrrRound6Test extends TestCase
{
    public function test_mrr_normalizes_contract_value_by_cycle(): void
    {
        $this->seedCore();
        $monthly = Service::create(['name' => 'استضافة مُدارة', 'cycle' => 'شهري', 'price' => 50]);
        $yearly = Service::create(['name' => 'ترخيص سنوي', 'cycle' => 'سنوي', 'price' => 1200]);

        Contract::create(['title' => 'عقد شهري', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 100, 'service_id' => $monthly->id]);
        Contract::create(['title' => 'عقد سنوي', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 1200, 'service_id' => $yearly->id]);
        // منتهٍ وعقد مورد: خارج الحساب
        Contract::create(['title' => 'عقد منتهٍ', 'type' => 'عقد عميل', 'status' => 'منتهي',
            'value' => 9000, 'service_id' => $monthly->id]);
        Contract::create(['title' => 'عقد مورد', 'type' => 'عقد مورد', 'status' => 'ساري', 'value' => 5000]);

        $this->actingAs($this->owner);
        $m = hub_mrr(true);
        $this->assertEquals(200.0, $m['mrr'], 'الشهري ١٠٠ + السنوي ١٢٠٠÷١٢ = ٢٠٠');
        $this->assertEquals(2400.0, $m['arr']);
        $this->assertSame(2, $m['contracts'], 'العقود السارية للعملاء وحدها');
    }

    public function test_plan_cycle_wins_and_one_time_stays_out(): void
    {
        $this->seedCore();
        $svc = Service::create(['name' => 'خدمة التطوير', 'cycle' => 'سنوي', 'price' => 100]);
        $plan = PricingPlan::create(['name' => 'باقة ربعية', 'service_id' => $svc->id,
            'cycle' => 'ربع سنوي', 'price' => 300]);
        Contract::create(['title' => 'عقد بباقة', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 300, 'plan_id' => $plan->id]);

        $once = Service::create(['name' => 'تنفيذ لمرة واحدة', 'cycle' => 'مرة واحدة', 'price' => 5000]);
        Contract::create(['title' => 'عقد لمرة', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 5000, 'service_id' => $once->id]);

        $this->actingAs($this->owner);
        $m = hub_mrr(true);
        $this->assertEquals(100.0, $m['mrr'], 'دورة الباقة (ربعية) تتقدم على دورة خدمتها: ٣٠٠÷٣');
        $this->assertEquals(5000.0, $m['oneTime'], '«مرة واحدة» لا تدخل التكرار');
    }

    public function test_revenue_is_attributed_per_service_and_unmapped_counted(): void
    {
        $this->seedCore();
        $a = Service::create(['name' => 'خدمة ألف', 'cycle' => 'شهري', 'price' => 10]);
        Contract::create(['title' => 'ع١', 'type' => 'عقد عميل', 'status' => 'ساري', 'value' => 300, 'service_id' => $a->id]);
        Contract::create(['title' => 'ع٢', 'type' => 'عقد عميل', 'status' => 'ساري', 'value' => 100, 'service_id' => $a->id]);
        Contract::create(['title' => 'ع٣ بلا خدمة', 'type' => 'عقد عميل', 'status' => 'ساري', 'value' => 1200]);

        $this->actingAs($this->owner);
        $m = hub_mrr(true);
        $top = $m['byService'][0];
        $this->assertSame('خدمة ألف', $top['name'], 'الأعلى إيراداً أولاً');
        $this->assertEquals(400.0, $top['mrr']);
        $this->assertSame(2, $top['contracts']);
        $this->assertSame(1, $m['unmapped'], 'العقود بلا خدمة تُعدّ صراحةً لا تُخفى');
    }

    public function test_service_costs_page_shows_mrr_and_quote_carries_service(): void
    {
        $this->seedCore();
        $svc = Service::create(['name' => 'خدمة المسار', 'cycle' => 'شهري', 'price' => 80]);
        Contract::create(['title' => 'عقد الصفحة', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 250, 'service_id' => $svc->id]);

        $this->actingAs($this->owner)->get('/service-costs')
            ->assertOk()->assertSee('MRR')->assertSee('خدمة المسار');

        // تحويل العرض ينقل الخدمة إلى العقد والفاتورة — فيُقاس MRR من مصدره
        $client = Client::create(['name' => 'عميل الخدمة', 'stage' => 'عميل حالي']);
        $q = Quote::create(['doc_no' => 'Q-SVC-1', 'title' => 'عرض بخدمة', 'status' => 'مقبول',
            'client_id' => $client->id, 'service_id' => $svc->id, 'total' => 250, 'currency' => 'د.ك']);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'contract']);
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'invoice']);

        $this->assertSame($svc->id, Contract::where('title', 'LIKE', '%Q-SVC-1%')->first()->service_id);
        $this->assertSame($svc->id, \App\Models\FinDocument::where('doc_no', 'INV-Q-SVC-1')->first()->service_id);
    }
}

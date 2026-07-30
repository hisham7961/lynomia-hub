<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceItem;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Quote;
use Tests\TestCase;

class JourneyComplianceTest extends TestCase
{
    public function test_journey_assembles_referenced_records_and_partner_matched_invoices(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل الرحلة', 'stage' => 'تفاوض', 'vip' => true]);
        Quote::create(['doc_no' => 'Q-9', 'title' => 'عرض', 'client_id' => $c->id, 'status' => 'مقبول', 'total' => 500]);
        Contract::create(['title' => 'عقد الرحلة', 'type' => 'خدمات', 'client_id' => $c->id, 'status' => 'سارٍ']);
        FinDocument::create(['doc_no' => 'INV-9', 'kind' => 'فاتورة', 'partner' => 'عميل الرحلة',
            'total' => 300, 'paid' => 100, 'date' => now()->subDay(), 'state' => 'جزئي']);
        FinDocument::create(['doc_no' => 'INV-X', 'kind' => 'فاتورة', 'partner' => 'عميل آخر',
            'total' => 900, 'paid' => 0, 'date' => now(), 'state' => 'مستحق']);

        $r = $this->actingAs($this->owner)->get('/journey/' . $c->id)->assertOk();
        $r->assertSee('عميل VIP')->assertSee('Q-9')->assertSee('عقد الرحلة')
            ->assertSee('INV-9')->assertSee('سُجّل العميل');
        $r->assertDontSee('INV-X');                        // فاتورة طرف آخر لا تتسلل

        $fin = $r->original->getData()['fin'];
        $this->assertSame(300.0, $fin['total']);
        $this->assertSame(100.0, $fin['paid']);
    }

    public function test_journey_gated_by_clients_visibility(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'محجوب']);

        $role = $this->viewer->role;
        $m = $role->matrix; $m['clients'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->viewer)->get('/journey/' . $c->id)->assertForbidden();
        // ومن لا يرى المالية لا يرى فواتير الرحلة
        $m['clients'] = ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0];
        $m['fin'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);
        FinDocument::create(['doc_no' => 'INV-H', 'kind' => 'فاتورة', 'partner' => 'محجوب',
            'total' => 50, 'date' => now(), 'state' => 'مستحق']);

        $this->actingAs($this->viewer->fresh())->get('/journey/' . $c->id)
            ->assertOk()->assertDontSee('INV-H');
    }

    public function test_compliance_module_works_and_feeds_expiry_radar(): void
    {
        $this->seedCore();

        // عبر المحرك العام: إنشاء من النموذج
        $this->actingAs($this->owner)->post('/m/compliance', [
            'title' => 'تجديد الترخيص التجاري', 'kind' => 'ترخيص تجاري',
            'authority' => 'وزارة التجارة', 'due' => now()->addDays(10)->toDateString(),
            'status' => 'مطلوب إجراء',
        ])->assertRedirect();

        $this->assertSame(1, ComplianceItem::count());

        // يظهر في رادار الانتهاءات تلقائياً (حقل due مكتشَف)
        $items = collect(hub_expiry(true));
        $this->assertTrue($items->contains(fn ($i) => $i['module'] === 'compliance'
            && str_contains($i['name'], 'الترخيص التجاري')),
            'استحقاق الامتثال يجب أن يدخل رادار الانتهاءات');

        // والحالة المنتهية تُسكِت التنبيه؟ «معفى» ليست من الحالات المغلقة العامة —
        // لكن الملتزم بلا استحقاق قريب لا ينبّه أصلاً؛ يكفي هنا دخول الرادار
        $this->actingAs($this->owner)->get('/m/compliance')->assertOk()->assertSee('الترخيص التجاري');
    }

    public function test_vip_flag_saves_and_shows_in_list(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/m/clients', ['name' => 'كبير الصيادين', 'vip' => 1])
            ->assertRedirect();
        $this->assertTrue((bool) Client::firstWhere('name', 'كبير الصيادين')->vip);
        $this->actingAs($this->owner)->get('/m/clients?q=')->assertOk()->assertSee('عميل VIP');
    }
}

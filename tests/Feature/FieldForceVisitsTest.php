<?php

namespace Tests\Feature;

use App\Models\Cycle;
use App\Models\Facility;
use App\Models\Hcp;
use App\Models\Territory;
use App\Models\Visit;
use Tests\TestCase;

/**
 * المرحلة ب من العمليات الميدانية — الدورات والزيارات المهيكلة.
 */
class FieldForceVisitsTest extends TestCase
{
    public function test_cycles_and_visits_work_end_to_end(): void
    {
        $this->seedCore();

        foreach (['cycles', 'visits'] as $m) {
            $this->actingAs($this->owner)->get('/m/' . $m)->assertOk();
            $this->actingAs($this->owner)->get('/m/' . $m . '/create')->assertOk();
        }

        $terr = Territory::create(['name' => 'الأحمدي', 'kind' => 'محافظة']);
        $this->actingAs($this->owner)->post('/m/cycles', [
            'name' => 'حملة القلب Q3', 'type' => 'حملة منتج', 'territoryId' => $terr->id,
            'targetVisits' => 4, 'status' => 'نشط',
        ])->assertSessionHasNoErrors();
        $cycle = Cycle::where('name', 'حملة القلب Q3')->first();
        $this->assertNotNull($cycle);

        $hcp = Hcp::create(['name' => 'د. خالد', 'specialty' => 'قلب', 'territory_id' => $terr->id, 'status' => 'نشط']);

        $this->actingAs($this->owner)->post('/m/visits', [
            'hcpId' => $hcp->id, 'cycleId' => $cycle->id, 'kind' => 'مخططة',
            'status' => 'مخطط', 'plannedDate' => now()->toDateString(),
            'objective' => 'عرض بيانات الدواء الجديد',
        ])->assertSessionHasNoErrors();
        $visit = Visit::where('cycle_id', $cycle->id)->first();
        $this->assertNotNull($visit);
        // الاسمُ يُولَّد من الطبيب والتاريخ، والمنطقةُ تُورَث من الطبيب
        $this->assertStringContainsString('د. خالد', $visit->name);
        $this->assertSame($terr->id, $visit->territory_id);

        // صفحة الزيارة ببطاقتها المخصصة
        $this->actingAs($this->owner)->get('/m/visits/' . $visit->id)->assertOk()
            ->assertSee('بطاقة الزيارة')->assertSee('د. خالد');
    }

    public function test_cycle_coverage_is_measured_from_completed_visits_not_a_field(): void
    {
        $this->seedCore();
        $cycle = Cycle::create(['name' => 'دورة', 'status' => 'نشط', 'target_visits' => 4]);
        $hcp = Hcp::create(['name' => 'د. سارة', 'status' => 'نشط']);

        // ثلاثُ زيارات: اثنتان تمّتا، واحدةٌ مخطّطة → التغطية ٢ من ٤ = ٥٠٪
        Visit::create(['hcp_id' => $hcp->id, 'cycle_id' => $cycle->id, 'status' => 'تمت']);
        Visit::create(['hcp_id' => $hcp->id, 'cycle_id' => $cycle->id, 'status' => 'تمت']);
        Visit::create(['hcp_id' => $hcp->id, 'cycle_id' => $cycle->id, 'status' => 'مخطط']);

        $cov = $cycle->fresh()->coverage();
        $this->assertSame(2, $cov['done']);
        $this->assertSame(4, $cov['target']);
        $this->assertSame(50, $cov['pct']);
    }

    public function test_facility_client_is_inherited_so_isolation_follows_the_visit(): void
    {
        $this->seedCore();
        $client = \App\Models\Client::create(['name' => 'مستشفى عميل']);
        $fac = Facility::create(['name' => 'مركز العميل', 'client_id' => $client->id]);
        $hcp = Hcp::create(['name' => 'د. نور', 'status' => 'نشط']);

        $v = Visit::create(['hcp_id' => $hcp->id, 'facility_id' => $fac->id, 'status' => 'مخطط']);
        $this->assertSame($client->id, $v->client_id, 'الزيارةُ لم ترث عميلَ المنشأة — يفوتها العزل');
    }

    public function test_visit_completion_emits_a_semantic_event(): void
    {
        $this->seedCore();
        // كتلةُ الأحداث الدلالية معرّفةٌ للزيارات — يُطلق بها المحرك والتنبيهات
        $events = config('hub.events.visits');
        $this->assertNotEmpty($events);
        $emits = collect($events)->pluck('emit')->all();
        $this->assertContains('visit.completed', $emits);
        $this->assertContains('visit.missed', $emits);
    }

    public function test_field_modules_are_in_nav_and_rules(): void
    {
        // على السكة العامة كاملةً: تنقّلٌ وقواعدُ تنبيه
        $navItems = collect(config('hub_nav'))->firstWhere('g', 'العمليات الميدانية')['items'];
        $this->assertContains('cycles', $navItems);
        $this->assertContains('visits', $navItems);

        $ruleMods = collect(hub_mod('rules')['fields'])->firstWhere('key', 'mod')['options'];
        $this->assertContains('visits', $ruleMods);
        $this->assertContains('cycles', $ruleMods);
    }
}

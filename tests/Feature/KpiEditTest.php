<?php

namespace Tests\Feature;

use App\Models\KpiDef;
use App\Models\Project;
use Tests\TestCase;

/**
 * الباني كان **يبني ولا يُصلح**: مؤشرٌ أخطأتَ رقمَ هدفه أو اخترتَ له الوحدة
 * الخطأ لا سبيل إلى تعديله — يُحذف ويُعاد بناؤه من الذاكرة، وبطاقتُه لا تقول
 * مِمَّ حُسبت أصلاً فلا تُراجَع ولا يُدرى ما يُحذف. والموسميّ لا خيار له غير
 * البقاء يشغل لوحة الجميع أو الفناء.
 *
 * الآن: تعديلٌ في المكان، وإيقافٌ يُخفي ولا يُتلف، وترتيبٌ، ومعادلةٌ مكتوبة
 * بالعربية على كل بطاقة — وكلُّ ذلك في التدقيق.
 */
class KpiEditTest extends TestCase
{
    protected function kpi(array $attrs = []): KpiDef
    {
        return KpiDef::create(array_merge([
            'name' => 'مشاريع جارية', 'unit' => 'مشروع', 'target' => 5, 'good' => 'up',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'projects', 'col' => null, 'st' => 'قيد التنفيذ'],
                          'combine' => 'none'],
            'sort' => 0,
        ], $attrs));
    }

    protected function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'مشاريع جارية', 'unit' => 'مشروع', 'target' => '9', 'good' => 'up',
            'a_agg' => 'count', 'a_module' => 'projects', 'a_col' => '', 'a_st' => 'قيد التنفيذ',
            'combine' => 'none',
        ], $over);
    }

    public function test_an_existing_kpi_can_be_edited_in_place(): void
    {
        $this->seedCore();
        $k = $this->kpi();

        // شاشة التعديل هي الباني نفسه، محمّلاً بالمؤشر
        $this->actingAs($this->owner)->get("/kpis?edit={$k->id}")->assertOk()
            ->assertSee('تعديل «مشاريع جارية»');

        $this->actingAs($this->owner)
            ->put("/kpis/{$k->id}", $this->payload(['name' => 'مشاريع متوقفة', 'target' => '2',
                'good' => 'down', 'a_st' => 'متوقف']))
            ->assertRedirect(route('kpis.index'));

        $k->refresh();
        $this->assertSame('مشاريع متوقفة', $k->name);
        $this->assertSame(2.0, $k->target);
        $this->assertSame('down', $k->good);
        $this->assertSame('متوقف', $k->formula['a']['st'], 'المعادلة لم تُحدَّث — التعديل مسّ العنوان دون الحساب');
        $this->assertSame(1, KpiDef::count(), 'التعديل أنشأ مؤشراً ثانياً بدل تحديث الأول');
    }

    /** التعديل يبقي المعرّف — فلا تنكسر الروابط ولا الترتيب */
    public function test_editing_keeps_the_identity_and_order(): void
    {
        $this->seedCore();
        $k = $this->kpi(['sort' => 7]);

        $this->actingAs($this->owner)->put("/kpis/{$k->id}", $this->payload(['name' => 'اسمٌ آخر']));

        $k->refresh();
        $this->assertSame(7, $k->sort);
    }

    /**
     * الإيقاف يُخفي ولا يُتلف: يختفي من اللوحات ويبقى في الباني بمعادلته.
     */
    public function test_pausing_hides_from_boards_but_keeps_the_definition(): void
    {
        $this->seedCore();
        Project::create(['name' => 'أ', 'status' => 'قيد التنفيذ']);
        $k = $this->kpi();

        $this->assertCount(1, hub_kpis($this->owner));

        $this->actingAs($this->owner)->post("/kpis/{$k->id}/toggle")->assertRedirect();

        $this->assertFalse((bool) $k->fresh()->active);
        $this->assertCount(0, hub_kpis($this->owner), 'الموقوف ما زال يظهر في اللوحات');
        $this->assertCount(1, hub_kpis($this->owner, true), 'الموقوف اختفى من الباني نفسه — هذا حذفٌ لا إيقاف');
        $this->assertNotNull(KpiDef::find($k->id), 'الإيقاف أتلف التعريف');

        // ويعود
        $this->actingAs($this->owner)->post("/kpis/{$k->id}/toggle");
        $this->assertCount(1, hub_kpis($this->owner));
    }

    public function test_reordering_swaps_neighbours(): void
    {
        $this->seedCore();
        $a = $this->kpi(['name' => 'أول', 'sort' => 0]);
        $b = $this->kpi(['name' => 'ثانٍ', 'sort' => 0]);   // ترتيبٌ متساوٍ — الحالة التي كانت تُبطل التبديل

        $this->actingAs($this->owner)->post("/kpis/{$b->id}/move", ['dir' => 'up'])->assertRedirect();

        $names = collect(hub_kpis($this->owner, true))->pluck('name')->all();
        $this->assertSame(['ثانٍ', 'أول'], $names);
    }

    /** ولا يخرج الترتيب عن حدوده */
    public function test_moving_the_first_one_up_is_a_no_op(): void
    {
        $this->seedCore();
        $a = $this->kpi(['name' => 'أول', 'sort' => 0]);
        $this->kpi(['name' => 'ثانٍ', 'sort' => 1]);

        $this->actingAs($this->owner)->post("/kpis/{$a->id}/move", ['dir' => 'up'])->assertRedirect();

        $this->assertSame(['أول', 'ثانٍ'], collect(hub_kpis($this->owner, true))->pluck('name')->all());
    }

    /** المعادلة تُقرأ بالعربية — البطاقة تقول مِمَّ حُسبت */
    public function test_the_formula_is_readable_on_the_card(): void
    {
        $this->seedCore();
        $this->kpi(['formula' => [
            'a' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'paid', 'st' => ''],
            'b' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'total', 'st' => ''],
            'combine' => 'ratio_pct']]);

        $txt = hub_kpi_explain([
            'a' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'paid', 'st' => ''],
            'b' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'total', 'st' => ''],
            'combine' => 'ratio_pct']);
        $this->assertStringContainsString('÷', $txt);
        $this->assertStringContainsString('١٠٠', $txt);

        $this->actingAs($this->owner)->get('/kpis')->assertOk()->assertSee('🧮');
    }

    /** كل تغييرٍ في تعريف مؤشر يترك أثراً — التعريف نفسه رقمٌ يُدار به */
    public function test_every_change_is_audited(): void
    {
        $this->seedCore();
        $k = $this->kpi();

        $this->actingAs($this->owner)->put("/kpis/{$k->id}", $this->payload(['name' => 'اسمٌ جديد']));
        $this->actingAs($this->owner)->post("/kpis/{$k->id}/toggle");
        $this->actingAs($this->owner)->delete("/kpis/{$k->id}");

        foreach (['تعديل مؤشر KPI', 'إيقاف مؤشر KPI', 'حذف مؤشر KPI'] as $action) {
            $this->assertTrue(
                \Illuminate\Support\Facades\DB::table('audits')->where('action', $action)->exists(),
                "تغييرٌ في تعريف مؤشر بلا أثر تدقيق: {$action}"
            );
        }
    }

    /** الباني كله خلف صلاحية المتابعة — التعديل والإيقاف والترتيب مثل الإضافة */
    public function test_the_new_actions_are_behind_the_same_gate(): void
    {
        $this->seedCore();
        $k = $this->kpi();

        $this->actingAs($this->viewer)->put("/kpis/{$k->id}", $this->payload())->assertForbidden();
        $this->actingAs($this->viewer)->post("/kpis/{$k->id}/toggle")->assertForbidden();
        $this->actingAs($this->viewer)->post("/kpis/{$k->id}/move", ['dir' => 'up'])->assertForbidden();

        $this->assertSame('مشاريع جارية', $k->fresh()->name);
        $this->assertTrue((bool) $k->fresh()->active);
    }

    /** والتعديل لا يُهرَّب عبره مقياسٌ على وحدةٍ لا يراها المعدِّل */
    public function test_editing_cannot_point_a_metric_at_an_invisible_module(): void
    {
        $this->seedCore();
        $k = $this->kpi();
        $role = $this->owner->role;
        $role->update(['is_owner' => false, 'flags' => ['monitor' => 1],
            'matrix' => ['projects' => ['v' => 1], 'fin' => ['v' => 0]]]);

        $this->actingAs($this->owner->fresh())
            ->put("/kpis/{$k->id}", $this->payload(['a_module' => 'fin', 'a_agg' => 'sum', 'a_col' => 'total']))
            ->assertStatus(422);

        $this->assertSame('projects', $k->fresh()->formula['a']['module']);
    }
}

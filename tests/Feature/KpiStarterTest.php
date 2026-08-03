<?php

namespace Tests\Feature;

use App\Models\KpiDef;
use Tests\TestCase;

/**
 * باني المؤشرات كان يطلب منك تركيب كل مؤشرٍ من الصفر: وحدةٌ وتجميعٌ وعمودٌ
 * وحالةٌ ودمج — فتبقى الشاشة فارغةً لأن البداية من العدم مُكلفة. هنا مكتبةٌ
 * جاهزة **محسوبةٌ من بياناتك** بالصيغة نفسها التي يبنيها الباني.
 */
class KpiStarterTest extends TestCase
{
    public function test_it_seeds_a_usable_library(): void
    {
        $this->seedCore();
        $this->artisan('hub:kpis-starter')->assertSuccessful();

        $this->assertGreaterThanOrEqual(20, KpiDef::count(), 'مكتبةٌ تغطي جوانب النظام');

        foreach (KpiDef::all() as $k) {
            $f = (array) $k->formula;
            $this->assertArrayHasKey('a', $f, "المؤشر «{$k->name}» بلا مقياس أول");
            $this->assertNotNull(hub_mod($f['a']['module']), "وحدة غير معروفة في «{$k->name}»");

            // العدّاد البسيط لا يجوز أن يعود null — وهو دليل صحة الصيغة.
            // (النِّسَب والمتوسطات تعود null على قاعدةٍ فارغة عمداً: «لا بيانات»
            //  أصدق من صفرٍ مضلِّل — وهو سلوك المحرّك المقصود.)
            if (($f['combine'] ?? 'none') === 'none' && ($f['a']['agg'] ?? '') === 'count') {
                $this->assertNotNull(hub_kpi_value($f), "المؤشر «{$k->name}» لا يُحسب — صيغةٌ ميتة");
            }
        }
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        $this->seedCore();
        $this->artisan('hub:kpis-starter')->assertSuccessful();
        $n = KpiDef::count();
        $this->artisan('hub:kpis-starter')->assertSuccessful();
        $this->assertSame($n, KpiDef::count(), 'المطابقة بالاسم فلا تكرار');
    }

    public function test_all_flag_adds_the_wider_library(): void
    {
        $this->seedCore();
        $this->artisan('hub:kpis-starter')->assertSuccessful();
        $core = KpiDef::count();
        $this->artisan('hub:kpis-starter --all')->assertSuccessful();
        $this->assertGreaterThan($core, KpiDef::count());
    }

    public function test_seeded_kpis_render_on_the_screen(): void
    {
        $this->seedCore();
        $this->artisan('hub:kpis-starter')->assertSuccessful();

        $this->actingAs($this->owner)->get('/kpis')->assertOk()
            ->assertSee('نسبة التحصيل')->assertSee('التذاكر المفتوحة');
    }

    public function test_ops_button_generates_them_without_a_terminal(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/admin/ops/starters')->assertRedirect();
        $this->assertGreaterThan(0, KpiDef::count(), 'زر عدّة الانطلاق يولّد المؤشرات أيضاً');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Flow;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * قسم المسارات بعد التوسعة: قائمةٌ مسطّحة بـ٨٩ مساراً غير صالحة للاستعمال —
 * التجميع بمجموعات التنقل، ولوحة تغطية تجيب «ماذا ينقصني؟»، وبحث وفلترة،
 * وتفعيل جماعي لأن مراجعة ٨٩ مساراً واحداً واحداً غير عملية.
 */
class FlowSectionTest extends TestCase
{
    public function test_flows_are_grouped_with_coverage_panel(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');

        $html = $this->actingAs($this->owner)->get('/admin/flows')->assertOk()->getContent();

        $this->assertStringContainsString('تغطية النظام', $html);
        // مجموعات التنقل نفسها تُستعمل للتجميع — تصنيفٌ يعرفه المستخدم أصلاً
        foreach (['العمل', 'المالية والمشتريات', 'الموارد البشرية'] as $group) {
            $this->assertStringContainsString($group, $html, "المجموعة «{$group}» غائبة عن التجميع");
        }
        $this->assertStringContainsString('مفعّل من', $html, 'عدّاد المفعّل من الإجمالي');
    }

    public function test_search_and_status_filters_narrow_the_list(): void
    {
        $this->seedCore();
        Flow::create(['name' => 'مسار الخيمة المفعّل', 'module' => 'tasks', 'event' => 'created',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'س']], 'enabled' => true]);
        Flow::create(['name' => 'مسار السحابة المعطّل', 'module' => 'tasks', 'event' => 'created',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'س']], 'enabled' => false]);

        // بحث بالاسم
        $this->actingAs($this->owner)->get('/admin/flows?q=الخيمة')
            ->assertOk()->assertSee('مسار الخيمة المفعّل')->assertDontSee('مسار السحابة المعطّل');

        // فلترة بالحالة
        $this->actingAs($this->owner)->get('/admin/flows?only=off')
            ->assertOk()->assertSee('مسار السحابة المعطّل')->assertDontSee('مسار الخيمة المفعّل');

        // بحثٌ بلا نتائج يقول ذلك صراحةً
        $this->actingAs($this->owner)->get('/admin/flows?q=لاشيءمطلقاً')
            ->assertOk()->assertSee('لا مسار يطابق التصفية');
    }

    public function test_bulk_toggle_flips_a_whole_group_only(): void
    {
        $this->seedCore();
        $work = Flow::create(['name' => 'مسار عمل', 'module' => 'tasks', 'event' => 'created',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'س']], 'enabled' => false]);
        $fin = Flow::create(['name' => 'مسار مالي', 'module' => 'fin', 'event' => 'created',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'س']], 'enabled' => false]);

        $this->actingAs($this->owner)->post('/admin/flows/bulk', ['g' => 'العمل', 'do' => 'on'])
            ->assertRedirect();

        $this->assertTrue((bool) $work->fresh()->enabled, 'مسارات المجموعة المختارة تُفعَّل');
        $this->assertFalse((bool) $fin->fresh()->enabled, 'ولا تُمَس مجموعةٌ أخرى');

        // والتعطيل الجماعي يعكسها
        $this->actingAs($this->owner)->post('/admin/flows/bulk', ['g' => 'العمل', 'do' => 'off']);
        $this->assertFalse((bool) $work->fresh()->enabled);
    }

    public function test_bulk_is_owner_only_and_validates_group(): void
    {
        $this->seedCore();
        Flow::create(['name' => 'مسار', 'module' => 'tasks', 'event' => 'created',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'س']], 'enabled' => false]);

        $this->actingAs($this->employee)->post('/admin/flows/bulk', ['g' => 'العمل', 'do' => 'on'])
            ->assertForbidden();
        $this->actingAs($this->owner)->post('/admin/flows/bulk', ['g' => 'مجموعة وهمية', 'do' => 'on'])
            ->assertStatus(422);
    }
}

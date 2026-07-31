<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\Task;
use Tests\TestCase;

/**
 * المرحلة ٣ — دورة حياة العهدة والإهلاك:
 *  1) الأصول تُربط بطلبات التوقيع (قالب «إقرار استلام عهدة» كان جاهزاً
 *     والوحدة محرومة من الربط).
 *  2) بطاقة الإهلاك بالقسط الثابت وتكلفة الملكية — كانت price/buyDate/life
 *     حقولاً بلا مستهلك.
 *  3) مزامنة الأصل مع صيانته، ومهمة استرداد العهدة عند انتهاء الخدمة.
 */
class AssetCustodyRound6Test extends TestCase
{
    public function test_assets_are_esign_linkable(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'لابتوب العهدة', 'status' => 'قيد الاستخدام']);

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'إقرار استلام عهدة اللابتوب', 'free_body' => 'أقرّ باستلام العهدة.',
            'pass' => 'p1234', 'link_module' => 'assets', 'link_id' => $asset->id,
        ]);

        $this->assertTrue(\App\Models\SignRequest::where('link_module', 'assets')
            ->where('link_id', $asset->id)->exists(), 'الأصل صار جهةً قابلة للربط بطلب توقيع');
    }

    public function test_asset_page_shows_straight_line_depreciation(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'خادم محاسبي', 'price' => 1200, 'life' => 5,
            'buy_date' => now()->subMonths(12)->toDateString(), 'status' => 'قيد الاستخدام']);

        // 1200 على 60 شهراً = 20 شهرياً؛ بعد 12 شهراً القيمة الدفترية 960
        $this->actingAs($this->owner)->get('/m/assets/' . $asset->id)
            ->assertOk()->assertSee('الإهلاك وتكلفة الملكية')->assertSee('960.00');
    }

    public function test_maintenance_log_syncs_asset_state_and_maint_date(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'طابعة الفرع', 'status' => 'قيد الاستخدام']);

        // بدء إصلاح يضع الأصل في «صيانة»
        $this->actingAs($this->owner)->post('/m/assetlog', [
            'assetId' => $asset->id, 'title' => 'إصلاح رأس الطباعة',
            'date' => now()->toDateString(), 'status' => 'قيد التنفيذ',
        ]);
        $this->assertSame('صيانة', $asset->fresh()->status);

        // الإكمال يختم «آخر صيانة» ويعيده للاستخدام
        $log = \App\Models\AssetMaintenance::where('title', 'إصلاح رأس الطباعة')->firstOrFail();
        $this->actingAs($this->owner)->put('/m/assetlog/' . $log->id, [
            'assetId' => $asset->id, 'title' => 'إصلاح رأس الطباعة',
            'date' => now()->toDateString(), 'status' => 'مكتملة',
        ]);
        $asset->refresh();
        $this->assertSame('قيد الاستخدام', $asset->status);
        $this->assertSame(now()->toDateString(), substr((string) $asset->maint, 0, 10),
            '«آخر صيانة» كان حقلاً يدوياً لا يشتق من السجل');
    }

    public function test_offboarding_creates_custody_recovery_task_once(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'مغادر بعهدة', 'status' => 'نشط', 'user_id' => $this->employee->id]);
        Asset::create(['name' => 'جوال شركة', 'holder_id' => $this->employee->id, 'status' => 'قيد الاستخدام']);
        Asset::create(['name' => 'لابتوب شركة', 'holder_id' => $this->employee->id, 'status' => 'قيد الاستخدام']);

        $this->actingAs($this->owner)->put('/m/hr/' . $emp->id, [
            'name' => 'مغادر بعهدة', 'status' => 'منتهية خدمته', 'userId' => $this->employee->id,
        ]);

        $task = Task::where('title', 'استرداد عهدة: مغادر بعهدة')->first();
        $this->assertNotNull($task, 'المغادرة بعهدة تولّد مهمة استرداد');
        $this->assertStringContainsString('جوال شركة', (string) $task->description);
        $this->assertStringContainsString('لابتوب شركة', (string) $task->description);

        // حفظ ثانٍ لا يكرر المهمة
        $this->actingAs($this->owner)->put('/m/hr/' . $emp->id, [
            'name' => 'مغادر بعهدة', 'status' => 'منتهية خدمته', 'userId' => $this->employee->id,
        ]);
        $this->assertSame(1, Task::where('title', 'استرداد عهدة: مغادر بعهدة')->count());
    }
}

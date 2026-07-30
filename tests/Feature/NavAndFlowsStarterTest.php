<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إعادة تنظيم الواجهة: قسم «النظام» انتقل من الجانبي لقائمة الترس في البار العلوي،
 * والجانبي صار يتذكّر حاله. وعدّة الانطلاق: مسارات عمل جاهزة تُنشأ مرة ولا تُكرّر.
 */
class NavAndFlowsStarterTest extends TestCase
{
    /** المالك يجد روابط الإدارة في قائمة الترس، والجانبي بلا قسم «النظام» */
    public function test_admin_links_moved_to_topbar_gear(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('gearmenu', $html);
        foreach (['الإعدادات', 'مسارات العمل', 'مركز الأمان', 'سجل التدقيق', 'التخصيص'] as $l) {
            $this->assertStringContainsString($l, $html);
        }
        // الجانبي تخفف: لا قسم «النظام» فيه بعد اليوم
        $this->assertStringNotContainsString('<div class="navsection">النظام</div>', $html);
        // مجموعات الجانبي تحمل مفاتيح التذكّر
        $this->assertStringContainsString('data-nav=', $html);
    }

    /** الموظف (غير المالك) لا يرى روابط لا تخصه في قائمة الترس */
    public function test_gear_menu_respects_permissions(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->employee)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('مركز الأمان', $html);
        $this->assertStringNotContainsString('href="' . route('settings.edit') . '"', $html);
    }

    /** عدّة الانطلاق تُنشئ المسارات مرة واحدة — النداء الثاني لا يكرّر */
    public function test_flows_starter_is_idempotent(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');
        $first = Flow::count();
        $this->assertGreaterThanOrEqual(15, $first, 'عدّة الانطلاق أنشأت أقل من المتوقع');

        Artisan::call('hub:flows-starter');
        $this->assertSame($first, Flow::count(), 'النداء الثاني كرّر مسارات');
    }

    /** مسار انطلاقي يعمل فعلاً: مهمة عاجلة جديدة → إشعار للمالكين */
    public function test_starter_flow_actually_fires(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');

        $p = \App\Models\Project::create(['name' => 'مشروع الحريق']);
        $this->actingAs($this->employee)
            ->post('/m/tasks', ['title' => 'إطفاء حريق فوري', 'projectId' => $p->id,
                                'priority' => 'عاجلة', 'status' => 'جديدة'])
            ->assertRedirect();

        $this->assertTrue(
            \App\Models\HubNotification::where('kind', 'flow')
                ->where('text', 'LIKE', '%مهمة عاجلة%')->exists(),
            'المسار الانطلاقي لم يُطلق إشعاره');
    }

    /** كل مسار انطلاقي سليم البنية: وحدته موجودة، وشرطه (إن وُجد) مفتاح حقل حقيقي */
    public function test_starter_flows_reference_real_modules_and_fields(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');

        foreach (Flow::all() as $f) {
            $def = config("hub.modules.{$f->module}");
            $this->assertNotNull($def, "مسار «{$f->name}» يشير لوحدة معدومة: {$f->module}");

            if ($f->cond_field) {
                $this->assertNotNull(collect($def['fields'])->firstWhere('key', $f->cond_field),
                    "مسار «{$f->name}» يشترط على حقل معدوم: {$f->cond_field}");
            }
            if ($f->event === 'status' && $f->status_to) {
                $statusField = collect($def['fields'])->firstWhere('col', $def['status'] ?? 'status');
                $this->assertContains($f->status_to, array_values($statusField['options'] ?? []),
                    "مسار «{$f->name}» يستهدف حالة معدومة: {$f->status_to}");
            }
        }
    }

    /** --full يولّد ثلاث شركات وتتوزع البيانات عليها ويستدعي عدّة الانطلاق */
    public function test_full_demo_seeds_three_companies_and_starter_flows(): void
    {
        $this->seedCore();
        Artisan::call('hub:demo', ['--full' => true]);

        $this->assertSame(3, DB::table('companies')->where('meta->demo', 1)->count());
        $spread = DB::table('clients')->where('meta->demo', 1)
            ->distinct()->pluck('company_id')->filter()->count();
        $this->assertGreaterThanOrEqual(3, $spread, 'العملاء لم يتوزعوا على الشركات الثلاث');
        $this->assertGreaterThanOrEqual(15, Flow::count(), '--full لم يستدعِ عدّة الانطلاق');

        // التصفير يمسح الشركات التجريبية ويُبقي مسارات الانطلاق (ليست تجريبية)
        $flows = Flow::count();
        Artisan::call('hub:demo', ['--purge' => true]);
        $this->assertSame(0, DB::table('companies')->where('meta->demo', 1)->count());
        $this->assertSame($flows, Flow::count(), 'التصفير مسح مسارات الانطلاق الدائمة');
    }
}

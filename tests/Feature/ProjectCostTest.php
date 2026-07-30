<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** محرك التكلفة الفعلية والربحية — كل رقم يُتحقق منه يدوياً */
class ProjectCostTest extends TestCase
{
    /**
     * تثبيت الساعة عند منتصف النهار.
     *
     * التكلفة الدورية تُضرب في `months = round(days/30, 2)`، و`days` تُقاس من منتصف ليل
     * `start_date` إلى **اللحظة الراهنة بساعتها**. فمشروعٌ بدأ قبل ٦٠ يوماً يعطي 60.5 يوماً
     * ظهراً (‏= 2.02 شهر) لكن 60.75 عند السادسة مساءً (‏= 2.03) — فكان الاختبار يخضرّ
     * صباحاً ويحمرّ مساءً بلا تغيير في الكود. التثبيت عند الظهر يجعل الفارق 60.5 دائماً.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(12));
    }

    /** مشروع بأرقام معلومة سلفاً حتى تكون كل نتيجة قابلة للحساب باليد */
    protected function seedProject(): Project
    {
        $p = Project::create([
            'name' => 'مشروع الحساب', 'status' => 'قيد التنفيذ', 'currency' => 'د.ك',
            'start_date' => now()->subDays(60)->toDateString(),
            'launch_exp' => now()->subDays(10)->toDateString(),
            'budget' => 5000,
        ]);

        // راتب ١٧٦٠ ÷ (٢٢ يوماً × ٨ ساعات) = ١٠ للساعة بالضبط
        Employee::create(['name' => 'منفّذ', 'user_id' => $this->employee->id,
            'salary' => 1760, 'allow' => 0, 'status' => 'على رأس العمل']);
        Cache::forget('cost:rates');

        Task::create(['title' => 'مهمة', 'project_id' => $p->id,
            'assignee_id' => $this->employee->id, 'act_h' => 100, 'status' => 'منجزة']);

        DB::table('servers')->insert(['id' => (string) Str::uuid(), 'name' => 'srv',
            'project_id' => $p->id, 'cost' => 120, 'cycle' => 'سنوي',
            'created_at' => now(), 'updated_at' => now()]);

        DB::table('subscriptions')->insert(['id' => (string) Str::uuid(), 'service' => 'أداة',
            'project_id' => $p->id, 'amount' => 30, 'cycle' => 'شهري',
            'renew' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        FinDocument::create(['doc_no' => 'INV-1', 'kind' => 'فاتورة', 'project_id' => $p->id,
            'total' => 4000, 'paid' => 2500, 'date' => now()->toDateString()]);
        FinDocument::create(['doc_no' => 'EXP-1', 'kind' => 'مصروف', 'project_id' => $p->id,
            'total' => 300, 'date' => now()->toDateString()]);

        return $p;
    }

    public function test_hourly_rate_is_salary_over_working_hours(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'موظف', 'user_id' => $this->employee->id,
            'salary' => 1760, 'allow' => 0, 'status' => 'على رأس العمل']);
        Cache::forget('cost:rates');

        $r = hub_hourly_rates();
        $this->assertSame(10.0, (float) $r['rates'][$this->employee->id]);   // ١٧٦٠ ÷ ١٧٦
    }

    public function test_every_cost_bucket_and_the_margin(): void
    {
        $this->seedCore();
        $p = $this->seedProject();
        $pl = hub_project_pl($p->id, true);

        $this->assertSame(1000.0, $pl['cost']['hours']);        // ١٠٠ ساعة × ١٠
        $this->assertSame(20.2, $pl['cost']['servers']);        // ١٢٠ سنوياً ÷ ١٢ × ٢.٠٢ شهر
        $this->assertSame(60.6, $pl['cost']['tools']);          // ٣٠ شهرياً × ٢.٠٢
        $this->assertSame(300.0, $pl['cost']['external']);      // مصروف واحد
        $this->assertSame(1380.8, $pl['cost']['total']);

        $this->assertSame(4000.0, $pl['revenue']['invoiced']);
        $this->assertSame(2500.0, $pl['revenue']['collected']);
        $this->assertSame(1500.0, $pl['revenue']['uncollected']);

        $this->assertSame(2619.2, $pl['profit']);               // ٤٠٠٠ − ١٣٨٠.٨
        $this->assertSame(65.5, $pl['margin']);                 // ٢٦١٩.٢ ÷ ٤٠٠٠
    }

    public function test_delay_cost_uses_burn_rate(): void
    {
        $this->seedCore();
        $p = $this->seedProject();
        $pl = hub_project_pl($p->id, true);

        $this->assertSame(10, $pl['delay']['days']);            // تأخر ١٠ أيام عن الإطلاق المتوقع
        $expected = round(10 * ($pl['cost']['total'] / $pl['days']), 2);
        $this->assertEqualsWithDelta($expected, $pl['delay']['cost'], 0.05);
    }

    public function test_budget_variance_and_no_delay_when_on_time(): void
    {
        $this->seedCore();
        $p = $this->seedProject();
        $p->forceFill(['launch_exp' => now()->addDays(30)->toDateString()])->save();

        $pl = hub_project_pl($p->id, true);
        $this->assertSame(0, $pl['delay']['days']);
        $this->assertSame(0.0, $pl['delay']['cost']);
        $this->assertLessThan(0, $pl['over']);                  // ما زال تحت الميزانية
    }

    public function test_page_renders_and_is_gated(): void
    {
        $this->seedCore();
        $p = $this->seedProject();

        $this->actingAs($this->owner)->get('/costs')->assertOk()->assertSee('هامش الربح');
        $this->actingAs($this->owner)->get('/costs?p=' . $p->id)->assertOk()->assertSee('توزيع التكلفة');
        $this->actingAs($this->viewer)->get('/costs')->assertForbidden();
    }

    public function test_logging_hours_busts_the_cached_calculation(): void
    {
        $this->seedCore();
        $p = $this->seedProject();
        $before = hub_project_pl($p->id)['cost']['hours'];

        $this->actingAs($this->owner)->post('/m/tasks', [
            'title' => 'مهمة إضافية', 'projectId' => $p->id,
            'assigneeId' => $this->employee->id, 'actH' => 10, 'status' => 'منجزة',
        ])->assertRedirect();

        // لولا نسف الكاش لبقي الرقم قديماً خمس دقائق
        $this->assertSame($before + 100.0, hub_project_pl($p->id)['cost']['hours']);
    }
}

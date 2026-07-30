<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CapacityImpactTest extends TestCase
{
    public function test_available_hours_subtract_weekends_and_approved_leave(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'موظف قدرة', 'user_id' => $this->employee->id,
            'status' => 'على رأس العمل']);

        // أسبوعان كاملان: ١٠ أيام عمل (الجمعة والسبت عطلة)
        $from = '2026-06-01';   // اثنين
        $to   = '2026-06-14';   // أحد
        $c = hub_capacity($from, $to);
        $this->assertSame(10, $c['workDays']);
        $row = collect($c['rows'])->firstWhere('id', $e->id);
        $this->assertSame(80, $row['available']);       // ١٠ × ٨

        // إجازة معتمدة يومي عمل تنقص المتاح
        DB::table('leave_requests')->insert(['id' => (string) Str::uuid(), 'emp_id' => $e->id,
            'type' => 'سنوية', 'status' => 'معتمدة', 'date_from' => '2026-06-01', 'date_to' => '2026-06-02',
            'created_at' => now(), 'updated_at' => now()]);

        $c2 = hub_capacity($from, $to);
        $row2 = collect($c2['rows'])->firstWhere('id', $e->id);
        $this->assertSame(2, $row2['leaveDays']);
        $this->assertSame(64, $row2['available']);      // ٨ أيام × ٨
    }

    public function test_load_and_bottleneck_detection(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'مثقل', 'user_id' => $this->employee->id, 'status' => 'على رأس العمل']);
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        // ١٠٠ ساعة مقدَّرة على فترة متاحها ٨٠ → حمل ١٢٥٪
        Task::create(['title' => 'مهمة ثقيلة', 'project_id' => $p->id,
            'assignee_id' => $this->employee->id, 'est_h' => 100,
            'due' => '2026-06-10', 'status' => 'قيد التنفيذ']);

        $c = hub_capacity('2026-06-01', '2026-06-14');
        $row = collect($c['rows'])->firstWhere('id', $e->id);
        $this->assertSame(100.0, $row['booked']);
        $this->assertSame(125, $row['load']);
        $this->assertSame(1, $c['totals']['over']);
        $this->assertSame(1, $row['projects']);
    }

    public function test_completed_tasks_do_not_consume_future_capacity(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'منجز', 'user_id' => $this->employee->id, 'status' => 'على رأس العمل']);
        Task::create(['title' => 'مهمة منجزة', 'assignee_id' => $this->employee->id,
            'est_h' => 100, 'due' => '2026-06-10', 'status' => 'منجزة']);

        $row = collect(hub_capacity('2026-06-01', '2026-06-14')['rows'])->first();
        $this->assertSame(0.0, $row['booked']);
        $this->assertSame(0, $row['load']);
    }

    public function test_employee_without_a_linked_account_is_flagged(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'بلا حساب', 'status' => 'على رأس العمل']);
        $row = collect(hub_capacity()['rows'])->firstWhere('name', 'بلا حساب');
        $this->assertFalse($row['linked']);
        $this->assertSame(1, hub_capacity()['totals']['unlinked']);
    }

    public function test_impact_map_merges_one_element_and_keeps_the_strictest_recovery(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع الدفع', 'status' => 'قيد التنفيذ']);
        $a = \App\Models\Application::create(['name' => 'تطبيق المتجر', 'status' => 'منشور']);

        foreach ([['API خارجي', 'حرجة', 8, $p->id, null, 'توقف الشراء', null],
                  ['خدمة سحابية', 'عالية', 2, null, $a->id, 'لا تجديد', 'تحويل يدوي']] as
                 [$type, $crit, $rto, $pid, $aid, $impact, $fb]) {
            DB::table('dependencies')->insert(['id' => (string) Str::uuid(), 'title' => 'اعتمادية',
                'src_type' => 'مشروع', 'project_id' => $pid, 'app_id' => $aid,
                'dep_type' => $type, 'dep_name' => 'بوابة KNET', 'criticality' => $crit,
                'impact' => $impact, 'fallback' => $fb, 'rto_hours' => $rto, 'status' => 'نشطة',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $r = $this->actingAs($this->owner)->get('/impact')->assertOk();
        $nodes = $r->original->getData()['nodes'];

        $this->assertCount(1, $nodes, 'العنصر الواحد يجب أن يظهر بطاقةً واحدة مهما اختلف تصنيفه');
        $n = $nodes->first();
        $this->assertSame('حرجة', $n['critLabel']);              // الأشد يفوز
        $this->assertSame(2, $n['rto']);                          // أقصر تعافٍ مقبول يفوز
        $this->assertEqualsCanonicalizing(['API خارجي', 'خدمة سحابية'], $n['types']);
        $this->assertEqualsCanonicalizing(['مشروع الدفع', 'تطبيق المتجر'], $n['dependents']);
        $r->assertSee('نقطة فشل مفردة');
    }

    public function test_pages_are_gated(): void
    {
        $this->seedCore();
        foreach (['/capacity', '/impact'] as $p) {
            $this->actingAs($this->viewer)->get($p)->assertForbidden();
            $this->actingAs($this->owner)->get($p)->assertOk();
        }
    }

    /** حارس أعمدة: استعلامات الصفحات المكتوبة يدوياً تعتمد أعمدة بعينها */
    public function test_hand_written_pages_reference_real_columns(): void
    {
        $pairs = [
            ['leave_requests', ['emp_id', 'date_from', 'date_to', 'status']],
            ['attendance', ['emp_id', 'date', 'hours']],
            ['tasks', ['assignee_id', 'est_h', 'act_h', 'due', 'status', 'project_id']],
            ['employees', ['user_id', 'salary', 'allow', 'status', 'dept']],
            ['fin_documents', ['kind', 'total', 'paid', 'due', 'project_id']],
            ['dependencies', ['dep_type', 'dep_name', 'criticality', 'impact', 'fallback', 'rto_hours']],
        ];
        $missing = [];
        foreach ($pairs as [$table, $cols]) {
            foreach ($cols as $c) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $c)) $missing[] = "$table.$c";
            }
        }
        // sqlite يبتلع العمود المجهول كنص حرفي بصمت، وMySQL ينهار — فالفحص صريح هنا
        $this->assertSame([], $missing, 'أعمدة تعتمدها الصفحات وغير موجودة: ' . implode(', ', $missing));
    }
}

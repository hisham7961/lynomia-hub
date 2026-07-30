<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\KpiDef;
use App\Models\Project;
use Tests\TestCase;

class KpiBuilderTest extends TestCase
{
    public function test_ratio_percentage_metric_computes_from_data(): void
    {
        $this->seedCore();
        FinDocument::create(['doc_no' => 'A', 'kind' => 'فاتورة', 'total' => 1000, 'paid' => 600, 'state' => 'جزئي']);
        FinDocument::create(['doc_no' => 'B', 'kind' => 'فاتورة', 'total' => 1000, 'paid' => 300, 'state' => 'جزئي']);

        $formula = [
            'a' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'paid', 'st' => ''],
            'b' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'total', 'st' => ''],
            'combine' => 'ratio_pct',
        ];
        // ٩٠٠ ÷ ٢٠٠٠ × ١٠٠ = ٤٥
        $this->assertSame(45.0, hub_kpi_value($formula, $this->owner));
    }

    public function test_count_with_status_filter(): void
    {
        $this->seedCore();
        Project::create(['name' => 'أ', 'status' => 'قيد التنفيذ']);
        Project::create(['name' => 'ب', 'status' => 'قيد التنفيذ']);
        Project::create(['name' => 'ج', 'status' => 'مكتمل']);

        $v = hub_kpi_value(['a' => ['agg' => 'count', 'module' => 'projects', 'st' => 'قيد التنفيذ'],
            'combine' => 'none'], $this->owner);
        $this->assertSame(2.0, $v);
    }

    public function test_safety_non_numeric_column_and_unknown_module_return_null(): void
    {
        $this->seedCore();
        $this->assertNull(hub_kpi_metric(['agg' => 'sum', 'module' => 'projects', 'col' => 'name'], $this->owner));
        $this->assertNull(hub_kpi_metric(['agg' => 'count', 'module' => 'nope'], $this->owner));
    }

    public function test_metric_respects_module_view_permission(): void
    {
        $this->seedCore();
        FinDocument::create(['doc_no' => 'C', 'kind' => 'فاتورة', 'total' => 500, 'paid' => 500, 'state' => 'مدفوع']);

        // دور بلا رؤية للمالية: المقياس يعيد null لا رقماً مسرَّباً
        $role = $this->viewer->role;
        $m = $role->matrix; $m['fin'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->assertNull(hub_kpi_metric(['agg' => 'sum', 'module' => 'fin', 'col' => 'total'], $this->viewer->fresh()));
    }

    public function test_divide_by_zero_yields_null_not_error(): void
    {
        $this->seedCore();
        $v = hub_kpi_value([
            'a' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'paid', 'st' => ''],
            'b' => ['agg' => 'count', 'module' => 'fin', 'st' => 'حالة لا وجود لها'],
            'combine' => 'ratio_pct',
        ], $this->owner);
        $this->assertNull($v);
    }

    /** الفلتر يعمل حتى حين يختلف مفتاح الحالة عن عمودها (files: docStatus/doc_status) */
    public function test_status_filter_uses_physical_column_for_documents(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\DB::table('documents')->insert([
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'عقد فعال', 'doc_status' => 'فعال',
             'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'عقد منتهٍ', 'doc_status' => 'منتهي',
             'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'آخر منتهٍ', 'doc_status' => 'منتهي',
             'created_at' => now(), 'updated_at' => now()],
        ]);

        // «عدد الوثائق المنتهية» يجب أن يساوي ٢ لا ٣ — الفلتر كان يُسقَط بصمت
        $v = hub_kpi_value(['a' => ['agg' => 'count', 'module' => 'files', 'st' => 'منتهي'],
            'combine' => 'none'], $this->owner);
        $this->assertSame(2.0, $v);
    }

    public function test_null_second_metric_yields_null_not_raw_first(): void
    {
        $this->seedCore();
        FinDocument::create(['doc_no' => 'Z', 'kind' => 'فاتورة', 'total' => 100, 'paid' => 50, 'state' => 'جزئي']);

        // المقياس الثاني فوق عمود غير رقمي → القيمة كلها null لا مجموع المدفوع خاماً
        $v = hub_kpi_value([
            'a' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'paid'],
            'b' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'partner'],
            'combine' => 'ratio_pct',
        ], $this->owner);
        $this->assertNull($v);
    }

    public function test_average_over_zero_rows_is_null(): void
    {
        $this->seedCore();
        $v = hub_kpi_metric(['agg' => 'avg', 'module' => 'fin', 'col' => 'total', 'st' => 'حالة لا وجود لها'], $this->owner);
        $this->assertNull($v, 'متوسط بلا صفوف لا بيانات — لا صفر');
    }

    public function test_build_store_and_gate(): void
    {
        $this->seedCore();

        $this->actingAs($this->viewer)->get('/kpis')->assertForbidden();

        $this->actingAs($this->owner)->post('/kpis', [
            'name' => 'مشاريع نشطة', 'good' => 'up',
            'a_agg' => 'count', 'a_module' => 'projects', 'a_st' => 'قيد التنفيذ',
            'combine' => 'none',
        ])->assertRedirect();

        $this->assertSame(1, KpiDef::count());
        $this->actingAs($this->owner)->get('/kpis')->assertOk()->assertSee('مشاريع نشطة');

        // ملفّق يشير لوحدة غير مرئية للدور يُرفض بـ422
        $role = $this->owner->role;   // المالك يرى كل شيء، فنجرّب موظف monitor
        $this->employee->role->update(['flags' => ['monitor' => 1]]);
        $m = $this->employee->role->matrix; $m['vault'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $this->employee->role->update(['matrix' => $m]);
        $this->actingAs($this->employee->fresh())->post('/kpis', [
            'name' => 'تسريب', 'good' => 'up', 'a_agg' => 'count', 'a_module' => 'vault', 'combine' => 'none',
        ])->assertStatus(422);
    }
}

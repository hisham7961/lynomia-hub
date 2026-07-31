<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Company;
use App\Models\FinDocument;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة ٢ — إحياء الميزانيات: الوحدة كانت سجلَّ نوايا — كل أبعاد المقارنة
 * جاهزة (شركة، مشروع، مركز تكلفة، بند مطابق لبنود المالية حرفياً، فترة)
 * ولا استعلامَ واحد يقابلها بالمصروف الفعلي، وحد التنبيه alert_pct ميت.
 */
class BudgetActualRound6Test extends TestCase
{
    protected function expense(string $cat, float $total, array $extra = []): FinDocument
    {
        return FinDocument::create(array_merge([
            'doc_no' => 'EX-' . strtoupper(substr(uniqid(), -6)), 'kind' => 'مصروف',
            'date' => now()->toDateString(), 'total' => $total, 'state' => 'مدفوعة', 'cat' => $cat,
        ], $extra));
    }

    public function test_budget_actual_sums_only_matching_expenses(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة الميزانية', 'status' => 'نشطة']);

        $this->expense('تسويق وإعلانات', 300, ['company_id' => $co->id]);
        $this->expense('تسويق وإعلانات', 200, ['company_id' => $co->id]);
        $this->expense('تطوير', 999, ['company_id' => $co->id]);                    // بند آخر
        $this->expense('تسويق وإعلانات', 500);                                       // شركة أخرى (بلا شركة)
        $this->expense('تسويق وإعلانات', 400, ['company_id' => $co->id, 'state' => 'ملغاة']); // ميتة
        FinDocument::create(['doc_no' => 'IN-1', 'kind' => 'فاتورة مبيعات', 'date' => now()->toDateString(),
            'total' => 5000, 'state' => 'مدفوعة', 'cat' => 'تسويق وإعلانات', 'company_id' => $co->id]); // دخل لا مصروف

        $budget = Budget::create(['name' => 'ميزانية التسويق', 'scope' => 'شركة', 'company_id' => $co->id,
            'cat' => 'تسويق وإعلانات', 'amount' => 1000, 'status' => 'نشطة']);

        $ba = hub_budget_actual($budget);
        $this->assertSame(500.0, $ba['spent'], 'يُجمع المصروف المطابق فقط: لا بنود أخرى ولا ملغاة ولا دخل');
        $this->assertSame(50, $ba['pct']);
        $this->assertSame(500.0, $ba['remain']);
    }

    public function test_budget_page_shows_actual_card(): void
    {
        $this->seedCore();
        $budget = Budget::create(['name' => 'ميزانية عامة', 'scope' => 'عام', 'cat' => 'الكل', 'amount' => 2000, 'status' => 'نشطة']);
        $this->expense('تطوير', 800);

        $this->actingAs($this->owner)->get('/m/budgets/' . $budget->id)
            ->assertOk()->assertSee('الميزانية مقابل الفعلي')->assertSee('40٪');
    }

    public function test_alert_pct_finally_notifies_when_threshold_reached(): void
    {
        $this->seedCore();
        Budget::create(['name' => 'ميزانية منذرة', 'scope' => 'عام', 'cat' => 'تطوير', 'amount' => 1000,
            'alert_pct' => 50, 'status' => 'نشطة']);
        Budget::create(['name' => 'ميزانية مرتاحة', 'scope' => 'عام', 'cat' => 'تصميم', 'amount' => 1000,
            'alert_pct' => 90, 'status' => 'نشطة']);
        $this->expense('تطوير', 600);
        $this->expense('تصميم', 100);

        $this->artisan('hub:automation')->assertExitCode(0);

        $texts = DB::table('notifications_hub')->pluck('text')->implode(' | ');
        $this->assertStringContainsString('ميزانية منذرة', $texts, 'حد التنبيه بُلغ — كان alert_pct حقلاً ميتاً');
        $this->assertStringContainsString('60٪', $texts);
        $this->assertStringNotContainsString('ميزانية مرتاحة', $texts, 'تحت الحد لا إزعاج');
    }

    public function test_fake_project_budget_rule_is_upgraded(): void
    {
        $this->seedCore();
        // منشأة قائمة عندها القاعدة الوهمية القديمة
        DB::table('alert_rules')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => '📉 مشروع تجاوز ميزانيته', 'mod' => 'projects', 'field' => 'cost',
            'op' => 'أكبر من', 'val' => '0', 'chan' => 'داخل النظام', 'every' => 7,
            'status' => 'متوقفة', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('hub:alerts-starter')->assertExitCode(0);

        $rule = DB::table('alert_rules')->where('name', '📉 مشروع تجاوز ميزانيته')->first();
        $this->assertSame('أكبر من عمود', $rule->op, 'القاعدة الوهمية (أكبر من صفر) تُرقّى لمقارنة الميزانية الحقيقية');
        $this->assertSame('budget', $rule->val);
    }
}
